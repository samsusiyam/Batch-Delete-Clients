<?php
/**
 * WHMCS Batch Delete Clients Module Hooks
 *
 * Developed by Host Nibo
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('AdminAreaPage', 1, function($vars) {
    if (isset($vars['filename']) && $vars['filename'] === 'addonmodules' && isset($_GET['module']) && $_GET['module'] === 'batch_delete_clients') {
        include_once __DIR__ . '/client_delete.php';
    }
});
