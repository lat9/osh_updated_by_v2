<?php
// -----
// Part of the "Orders Status History - Updated By (v2)" plugin.
// Copyright 2013-2016, Vinos de Frutas Tropicales (http://vinosdefrutastropicales.com)
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// -----
// Add the updated_by column to the orders_status_history table, if it doesn't already exist.
//
if (is_object ($sniffer) && $sniffer->field_exists (TABLE_ORDERS_STATUS_HISTORY, 'updated_by') === false) {
    $db->Execute("ALTER TABLE " . TABLE_ORDERS_STATUS_HISTORY . " ADD updated_by varchar(45) NOT NULL default ''");
}