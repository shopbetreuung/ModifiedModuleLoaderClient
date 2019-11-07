<?php
use FirstWeb\MultiOrder\Classes\Controller;

require ('includes/application_top.php');

if (!defined('MODULE_FW_MULTI_ORDER_STATUS') || MODULE_FW_MULTI_ORDER_STATUS != 'true') {
    return;
}

require_once DIR_FS_DOCUMENT_ROOT . '/vendor-no-composer/firstweb/MultiOrder/autoload.php';
require_once (DIR_FS_INC . 'get_order_total.inc.php');
require_once (DIR_FS_INC . 'xtc_format_price_order.inc.php');
require_once (DIR_FS_INC . 'xtc_get_attributes_model.inc.php');
require DIR_WS_CLASSES . 'order.php';

// restore_error_handler();
// restore_exception_handler();
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL ^ E_NOTICE);

$controller = new Controller();
$controller->invoke();
