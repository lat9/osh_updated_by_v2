<?php

/**
 * Updates the order status history in the database. If customer notification is
 * requested and email details are specified, the customer will be sent a
 * notification email with the specified details.
 *
 * The order status history will not be updated if the specified comment is
 * empty (or null) and the order is already the specified order status.
 *
 * Notification emails will not be sent to customers if the email subject or
 * email text are not specified (or empty). Admin email notifications are not
 * sent directly by this method.
 *
 * If an error occurs while performing the update a message will be written to
 * the configured error log (usually the Zen Cart debug logs).
 *
 * Two (read only) notifiers are called by this method:
 *   NOTIFY_UPDATE_ORDER_STATUS indicates the order's status has been updated.
 *   NOTIFY_UPDATE_ORDER_STATUS_HISTORY indicates the order status history has
 *     been updated and the specified order status history exists in the database.
 *   NOTIFY_BEFORE_SEND_EXTRA_ORDER_STATUS_ADMIN_EMAILS is sent before checking
 *     to see if extra admin emails should be sent. Observers can use this to
 *     modify the extra order status emails sent when the order status history
 *     is updated.
 *
 * @param int $orders_id the associated order for the order status history.
 * @param string $comment a comment to add to the order status history.
 * @param int $orders_status_id the order status of the order status history.
 *   By default the current order status of the associated order is used.
 *
 * @param int $notify -1 to hide the order status history from the customer,
 *   0 to allow the customer to see the order status history, and 1 to allow
 *   the customer to see the order status history and notify the customer via
 *   email. By default this is set to -1 (hidden).
 *
 * @param string $email_subject the subject to when sending an email. By default
 *   this is left empty.
 *
 * @param string $email_text the text portion of the email. By default this is
 *   left empty.
 *
 * @param array|string $email_html the html portion of the email. By default
 *   this is left empty.
 *
 * @param string $updated_by the name of the module or admin updating the order
 *   status history. When not specified the first item found in the following
 *   list will be used: the name and id of the current admin, '' if triggered
 *   by a customer, or '--'.
 *
 * @return boolean|int false if an error occurs while updating the order status
 *   history, otherwise the id of the order status history corresponding to the
 *   update.
 */
function zen_update_order_status_history($orders_id, $comment, $orders_status_id = null, $notify = -1, $email_subject = null, $email_text = null, $email_html = null, $updated_by = null) {
  global $db, $zco_notifier;

  // Verify the order exists
  $sql =
    'SELECT `orders_id`, `orders_status`, `customers_name`, ' .
      '`customers_email_address` FROM `' . TABLE_ORDERS . '` ' .
    'WHERE `orders_id`=\':orders_id:\'';
  $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');

  $order = $db->Execute($sql);
  if($order->EOF) {
    // Return false to indicate failure
    $e = new Exception();
    error_log(sprintf(
      ORDER_STATUS_HISTORY_MISSING_ORDER,
      $orders_id,
      $e->getTraceAsString()
    ));
    unset($e);
    return false;
  }

  // Verify the order status
  if($orders_status_id === null || $orders_status_id == -1) {
    // None specified (or no change specified)
    $orders_status_id = (int)$order->fields['orders_status'];
  }
  else {
    // Status specified, verify
    $sql =
      'SELECT `orders_status_id` FROM `' . TABLE_ORDERS_STATUS . '` ' .
      'WHERE `language_id`=\':language_id:\' ' .
      'AND `orders_status_id`=\':status_id:\'';
    $sql = $db->bindVars($sql, ':language_id:', $_SESSION['languages_id'], 'integer');
    $sql = $db->bindVars($sql, ':status_id:', $orders_status_id, 'integer');

    $check = $db->Execute($sql);
    if($check->EOF) {
      // Return false to indicate failure
      $e = new Exception();
      error_log(sprintf(
        ORDER_STATUS_HISTORY_MISSING_ORDER_STATUS,
        $orders_status_id,
        $_SESSION['languages_id'],
        $e->getTraceAsString()
      ));
      unset($e);
      return false;
    }
  }

  // Update the order status and last modified timestamp on the order
  $sql_data_array = array(
    'orders_status' => $orders_status_id,
    'last_modified' => 'now()'
  );
  $sql = '`orders_id`=\':orders_id:\'';
  $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');
  zen_db_perform(TABLE_ORDERS, $sql_data_array, 'update', $sql);

  // Notify any observers letting them know the order status was updated
  if($orders_status_id != $order->fields['orders_status']) {
    $zco_notifier->notify(
      'NOTIFY_UPDATE_ORDER_STATUS',
      array(
        'orders_id' => $orders_id,
        'prev_orders_status_id' => $order->fields['orders_status'],
        'next_orders_status_id' => $orders_status_id,
        'updated_by' => $updated_by
      )
    );
  }
  // If the order status did not change and no comment was entered
  else if(zen_not_null($comment)) {
    $sql =
      'SELECT `orders_status_history_id` ' .
      'FROM `' . TABLE_ORDERS_STATUS_HISTORY . '` ' .
      'WHERE `orders_id`=\':orders_id:\' ' .
      'AND `orders_status_id`=\':orders_status_id:\' ' .
      'ORDER BY `date_added` DESC LIMIT 1';
    $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');
    $sql = $db->bindVars($sql, ':orders_status_id:', $orders_status_id, 'integer');
    $check = $db->Execute($sql);
    if(!$check->EOF) {
      // We found the matching order status history, Return the id.
      return (int)$check->fields['orders_status_history_id'];
    }

    // No matching Order Status History entry was found for the order and status
    // Continue with processing to create a new Order Status History
  }

  // If not specified, generate the updated_by field
  if($updated_by === null) {
    $updated_by = '';
    // Called by an administrative user
    if(array_key_exists('admin_id', $_SESSION)) {
      $sql =
        'SELECT `admin_name` FROM `' . TABLE_ADMIN . '` ' .
        'WHERE `admin_id`=\':admin_id:\' LIMIT 1';
      $sql = $db->bindVars($sql, ':admin_id:', $_SESSION['admin_id'], 'integer');
      $check = $db->Execute($sql);
      if(!$check->EOF) {
        $updated_by = $check->fields['admin_name'] . ' [' . (int)$_SESSION['admin_id'] . ']';
      }
    }
    // Not called by an administrative user and no customer is present
    else if(!array_key_exists('customers_id', $_SESSION)) {
      $updated_by = ORDER_STATUS_HISTORY_UNKNOWN_MODULE;
    }
  }
  unset($check, $sql);

  // Verify the customer notification.
  // TODO: Move to static variables on a class in the future
  switch($notify) {
    case 1:
      // Change the notification to "show only" if the email subject and text
      // do not exist. No email will be sent, but the change will be visible to
      // the customer.
      if(!zen_not_null($email_subject) || !zen_not_null($email_text)) {
        $notify = 0;
      }
    case 0:
      break;
    default:
      // Default to no notification / hidden from customer
      $notify = -1;
  }

  // Save the updated order status history
  $sql_data_array = array(
    'orders_id' => $orders_id,
    'orders_status_id' => $orders_status_id,
    'updated_by' => $updated_by,
    'date_added' => 'now()',
    'customer_notified' => $notify,
    'comments' => zen_not_null($comments) ? $comments : 'null'
  );
  zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
  $orders_status_history_id = zen_db_insert_id();

  // Verify entry was written to the database (return false to indicate error)
  if($orders_status_history_id == 0) {
    $e = new Exception();
    error_log(sprintf(
      ORDER_STATUS_HISTORY_NOT_UPDATED,
      $sql_data_array,
      $e->getTraceAsString()
    ));
    unset($e);
    return false;
  }

  // Notify any observers the order status history was updated
  $sql_data_array['orders_status_history_id'] = $orders_status_history_id;
  $zco_notifier->notify(
    'NOTIFY_UPDATE_ORDER_STATUS_HISTORY',
    $sql_data_array
  );

  // Send the customer notifiation via email
  if($notify == 1) {
    zen_mail(
      $order->fields['customers_name'],
      $order->fields['customers_email_address'],
      $email_subject, $email_text,
      STORE_NAME, EMAIL_FROM,
      $email_html, 'order_status'
    );
  }

  // Notify any observers we will be sending admin "extra order status" emails.
  // Observers are allowed to modify various aspects of the emails.
  $send_extra = (SEND_EXTRA_ORDERS_STATUS_ADMIN_EMAILS_TO_STATUS == '1');
  $send_extra_to = SEND_EXTRA_ORDERS_STATUS_ADMIN_EMAILS_TO;
  $zco_notifier->notify(
    'NOTIFY_BEFORE_SEND_EXTRA_ORDER_STATUS_ADMIN_EMAILS',
    $sql_data_array,
    $email_subject,
    $email_text,
    $email_html,
    $send_extra,
    $send_extra_to
  );
  unset($sql_data_array);

  //send extra emails
  if($send_extra && zen_not_null($send_extra_to)) {
    zen_mail(
      '', $send_extra_to, $email_subject, $email_text,
      STORE_NAME, EMAIL_FROM, $email_html, 'order_status_extra'
    );
  }

  return $orders_status_history_id;
}

// Backwards compatibility with the "Order Status History -- Updated By" plugin
if(defined('OSH_UPDATED_BY_VERSION')) {
  // -----
  // Inputs:
  // - $order_id ................ The order for which the status record is to be created
  // - $updated_by .............. If non-null, the specified value will be used for the like-named field.  Otherwise,
  //                              the value will be calculated based on some defaults.
  // - $orders_status ........... The orders_status value for the update.  If set to -1, no change in the status value was detected.
  // - $notify_customer ......... Identifies whether the history record is sent via email and visible to the customer via the "account_history_info" page:
  //                               0 ... No emails sent, customer can view on "account_history_info"
  //                               1 ... Email sent, customer can view on "account_history_info"
  //                              -1 ... No emails sent, comments and status-change hidden from customer view
  //                              -2 ... Email sent only to configured admins; status-change hidden from customer view
  // - $message ................. The comments associated with the history record, if non-blank.
  // - $email_include_message ... Identifies whether (true) or not (false) to include the status message ($message) in any email sent.
  // - $email_subject ........... If specified, overrides the default email subject line.
  // - $send_xtra_mail_to ....... If specified, overrides the "standard" database settings SEND_EXTRA_ORDERS_STATUS_ADMIN_EMAILS_TO_STATUS and
  //                              SEND_EXTRA_ORDERS_STATUS_ADMIN_EMAILS_TO.
  //
  // Returns:
  // - $osh_id ............ A value > 0 if the record has been written (the orders_status_history_id number)
  //                        -2 if no order record was found for the specified $orders_id
  //                        -1 if no status change was detected (i.e. no record written).
  //
  function zen_update_orders_history($orders_id, $message = null, $updated_by = null, $orders_status = -1, $notify_customer = -1, $email_include_message = true, $email_subject = '', $send_xtra_emails_to = '') {
    global $db, $osh_additional_comments, $zco_notifier, $osh_extra;

    // Verify the order exists
    $sql =
      'SELECT `orders_id`, `orders_status`, `customers_name`, ' .
        '`customers_email_address` FROM `' . TABLE_ORDERS . '` ' .
      'WHERE `orders_id`=\':orders_id:\'';
    $sql = $db->bindVars($sql, ':orders_id:', $orders_id, 'integer');
    $order = $db->Execute($sql);
    if($order->EOF) {
      return -2;
    }

    // Handle the email message according to the older OSH rules
    if ($email_include_message === true) {
      $osh_additional_comments = '';
      $zco_notifier->notify('ZEN_UPDATE_ORDERS_HISTORY_PRE_EMAIL', array( 'message' => $message ) );
      if ($osh_additional_comments != '') {
        if (zen_not_null($message)) {
          $message .= "\n\n";
        }
        $message .= $osh_additional_comments;
      }
      unset($osh_additional_comments);
    }

    // Only update the status if there is a change or message
    if (($orders_status != -1 && $order->fields['orders_status'] != $orders_status) || zen_not_null($message)) {
      if ($orders_status == -1) {
        $orders_status = $order->fields['orders_status'];
      }
      $zco_notifier->notify('ZEN_UPDATE_ORDERS_HISTORY_STATUS_VALUES', array ( /*-bof-a-v0.0.4*/ 'orders_id' => $orders_id, /*-eof-a-v0.0.4*/ 'new' => $orders_status, 'old' => $order->fields['orders_status'] ));  /*v1.0.0m*/

      // Default to notify customer
      $notify_customer = (isset($notify_customer) && ($notify_customer == 1 || $notify_customer == -1 || $notify_customer == -2)) ? $notify_customer : 0; /*v1.1.0c*/

      if (IS_ADMIN_FLAG === true && ($notify_customer == 1 || $notify_customer == -2)) {
        $status_name = $db->Execute("SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_id = " . (int)$orders_status . " AND language_id = " . (int)$_SESSION['languages_id']);
        $orders_status_name = ($status_name->EOF) ? 'N/A' : $status_name->fields['orders_status_name'];
        $email_comments = (zen_not_null($message) && $email_include_message === true) ? (OSH_EMAIL_TEXT_COMMENTS_UPDATE . $message . "\n\n") : '';

        if ($orders_status != $order->fields['orders_status']) {
          $status_text = OSH_EMAIL_TEXT_STATUS_UPDATED;
          $status_value_text = sprintf(OSH_EMAIL_TEXT_STATUS_CHANGE, zen_get_orders_status_name($order->fields['orders_status']), $orders_status_name);
        } else {
          $status_text = OSH_EMAIL_TEXT_STATUS_NO_CHANGE;
          $status_value_text = sprintf(OSH_EMAIL_TEXT_STATUS_LABEL, $orders_status_name );
        }

        $email_text =
          STORE_NAME . ' ' . OSH_EMAIL_TEXT_ORDER_NUMBER . ' ' . $orders_id . "\n\n" .
          OSH_EMAIL_TEXT_INVOICE_URL . ' ' . zen_catalog_href_link(FILENAME_CATALOG_ACCOUNT_HISTORY_INFO, 'order_id=' . $orders_id, 'SSL') . "\n\n" .
          OSH_EMAIL_TEXT_DATE_ORDERED . ' ' . zen_date_long($order->fields['date_purchased']) . "\n\n" .
          strip_tags($email_comments) .
          $status_text . $status_value_text .  /*v1.0.0c*/
          OSH_EMAIL_TEXT_STATUS_PLEASE_REPLY;

        $html_msg['EMAIL_CUSTOMERS_NAME']    = $order->fields['customers_name'];
        $html_msg['EMAIL_TEXT_ORDER_NUMBER'] = OSH_EMAIL_TEXT_ORDER_NUMBER . ' ' . $orders_id;
        $html_msg['EMAIL_TEXT_INVOICE_URL']  = '<a href="' . zen_catalog_href_link(FILENAME_CATALOG_ACCOUNT_HISTORY_INFO, 'order_id=' . $orders_id, 'SSL') .'">'.str_replace(':','', OSH_EMAIL_TEXT_INVOICE_URL).'</a>';
        $html_msg['EMAIL_TEXT_DATE_ORDERED'] = OSH_EMAIL_TEXT_DATE_ORDERED . ' ' . zen_date_long($order->fields['date_purchased']);
        $html_msg['EMAIL_TEXT_STATUS_COMMENTS'] = nl2br($email_comments);
        $html_msg['EMAIL_TEXT_STATUS_UPDATED'] = str_replace('\n','', $status_text);  /*v1.0.0c*/
        $html_msg['EMAIL_TEXT_STATUS_LABEL'] = str_replace('\n','', $status_value_text);  /*v1.0.0c*/
        $html_msg['EMAIL_TEXT_NEW_STATUS'] = $orders_status_name;
        $html_msg['EMAIL_TEXT_STATUS_PLEASE_REPLY'] = str_replace('\n','', OSH_EMAIL_TEXT_STATUS_PLEASE_REPLY);
        $html_msg['EMAIL_PAYPAL_TRANSID'] = '';

        if(!isset($osh_extra)) $osh_extra = array();
        if(zen_not_null($send_xtra_emails_to)) {
          $osh_extra[$orders_id] = $send_xtra_emails_to;
        }

        // Update the order status history and notify requested parties
        $retval = zen_update_order_status_history(
          $orders_id, $message, $orders_status, $notify_customer,
          zen_not_null($email_subject) ? $email_subject : (OSH_EMAIL_TEXT_SUBJECT . ' #' . $orders_id),
          $email_text, $html_msg, $updated_by
        );

        // return 0 or id of new order status history
        return ($retval === false? 0 : $retval);
      }
    }

    // sid not attempt to update the order status history
    return -1;
  }
}
