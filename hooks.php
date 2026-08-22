<?php

add_hook('AdminAreaPage', 1, function($vars) {
    if ($vars['filename'] == 'addonmodules' && isset($_GET['module']) && $_GET['module'] == 'batch_delete_clients') {
        include_once 'client_delete.php';
    }
});
?>
