<?php

use WHMCS\Database\Capsule;

function batch_delete_clients_delete() {
    if (isset($_POST['client_ids'])) {
        $clientIds = $_POST['client_ids'];
        foreach ($clientIds as $clientId) {
            $clientId = intval($clientId);
            if ($clientId) {
                try {
                    Capsule::table('tblclients')->where('id', $clientId)->delete();
                    echo "Client ID {$clientId} deleted successfully.<br>";
                } catch (Exception $e) {
                    echo "Error deleting Client ID {$clientId}: " . $e->getMessage() . "<br>";
                }
            }
        }
    }
}
?>
