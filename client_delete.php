<?php
/**
 * WHMCS Batch Delete Clients Module
 *
 * Developed by Host Nibo
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Handle batch deletion of selected clients
 *
 * @return array Array containing success client IDs and error messages
 */
function batch_delete_clients_delete() {
    $results = [
        'success' => [],
        'errors'  => []
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['client_ids'])) {
        $clientIds = is_array($_POST['client_ids']) ? $_POST['client_ids'] : [$_POST['client_ids']];

        foreach ($clientIds as $clientId) {
            $clientId = intval($clientId);
            if ($clientId <= 0) {
                continue;
            }

            try {
                $deleted = false;

                // 1. Try WHMCS Local API first for standard cascade deletion and hook triggering
                if (function_exists('localAPI')) {
                    $apiResult = localAPI('DeleteClient', [
                        'clientid'           => $clientId,
                        'deleteusers'        => true,
                        'deletetransactions' => true
                    ]);

                    if (isset($apiResult['result']) && strtolower($apiResult['result']) === 'success') {
                        $deleted = true;
                    }
                }

                // 2. Direct database cleanup if localAPI is not available or encounters issues
                if (!$deleted) {
                    Capsule::table('tblclients')->where('id', $clientId)->delete();
                    Capsule::table('tblhosting')->where('userid', $clientId)->delete();
                    Capsule::table('tbldomains')->where('userid', $clientId)->delete();
                    Capsule::table('tblaffiliates')->where('clientid', $clientId)->delete();
                    Capsule::table('tblinvoices')->where('userid', $clientId)->delete();
                    Capsule::table('tbltickets')->where('userid', $clientId)->delete();
                    Capsule::table('tblactivitylog')->where('userid', $clientId)->delete();
                    Capsule::table('tblnotes')->where('userid', $clientId)->delete();
                    Capsule::table('tblaccounts')->where('userid', $clientId)->delete();
                }

                // Log WHMCS activity
                if (function_exists('logActivity')) {
                    logActivity("Host Nibo Batch Delete Module: Permanently deleted Client ID #{$clientId}");
                }

                $results['success'][] = $clientId;
            } catch (\Exception $e) {
                $results['errors'][] = "Client #{$clientId}: " . $e->getMessage();
            }
        }
    }

    return $results;
}
