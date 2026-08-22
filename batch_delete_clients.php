<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function batch_delete_clients_config() {
    return [
        'name' => 'Batch Delete Clients',
        'description' => 'A module to batch delete clients in WHMCS.',
        'version' => '1.1',
        'author' => 'DigiDome.BiZ',
        'fields' => []
    ];
}

function batch_delete_clients_activate() {
    return [
        'status' => 'success',
        'description' => 'Batch Delete Clients module activated successfully.'
    ];
}

function batch_delete_clients_deactivate() {
    return [
        'status' => 'success',
        'description' => 'Batch Delete Clients module deactivated successfully.'
    ];
}

function batch_delete_clients_output($vars) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_ids'])) {
        include_once 'client_delete.php';
        batch_delete_clients_delete();
    }

    $filterZeroServices = isset($_GET['filter']) && $_GET['filter'] == 'zero_services';
    $filterAffiliatesOnly = isset($_GET['filter']) && $_GET['filter'] == 'affiliates_only';
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $baseQuery = Capsule::table('tblclients')
        ->leftJoin('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
        ->select('tblclients.id', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email', Capsule::raw('COUNT(tblhosting.id) as services_count'))
        ->groupBy('tblclients.id')
        ->orderBy('tblclients.datecreated', 'desc');

    if ($filterZeroServices) {
        $baseQuery->havingRaw('COUNT(tblhosting.id) = 0');
    } elseif ($filterAffiliatesOnly) {
        $baseQuery = Capsule::table('tblclients')
            ->leftJoin('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
            ->leftJoin('tbldomains', 'tblclients.id', '=', 'tbldomains.userid')
            ->join('tblaffiliates', 'tblclients.id', '=', 'tblaffiliates.clientid')
            ->select(
                'tblclients.id',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.email',
                Capsule::raw('COUNT(DISTINCT tblhosting.id) as services_count'),
                Capsule::raw('COUNT(DISTINCT tbldomains.id) as domains_count')
            )
            ->groupBy('tblclients.id')
            ->havingRaw('services_count = 0 AND domains_count = 0')
            ->orderBy('tblclients.datecreated', 'desc');
    }

    if ($filterAffiliatesOnly) {
    $clientsQuery = Capsule::table('tblclients')
        ->leftJoin('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
        ->leftJoin('tbldomains', 'tblclients.id', '=', 'tbldomains.userid')
        ->join('tblaffiliates', 'tblclients.id', '=', 'tblaffiliates.clientid')
        ->select(
            'tblclients.id',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            Capsule::raw('COUNT(DISTINCT tblhosting.id) as services_count'),
            Capsule::raw('COUNT(DISTINCT tbldomains.id) as domains_count')
        )
        ->groupBy('tblclients.id')
        ->havingRaw('COUNT(DISTINCT tblhosting.id) = 0 AND COUNT(DISTINCT tbldomains.id) = 0')
        ->orderBy('tblclients.datecreated', 'desc');

    $totalClients = $clientsQuery->get()->count(); // count manually from result
    $clients = $clientsQuery->skip($offset)->take($perPage)->get();
} else {
    $totalClients = $baseQuery->count();
    $clients = $baseQuery->skip($offset)->take($perPage)->get();
}

    echo '
    <style>
        .batch-delete-clients-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 1em;
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
        }
        .batch-delete-clients-table th, .batch-delete-clients-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .batch-delete-clients-table th {
            background-color: #4CAF50;
            color: white;
        }
        .batch-delete-clients-form {
            margin: 20px 0;
        }
        .batch-delete-clients-form input[type="submit"] {
            background-color: #f44336;
            color: white;
            border: none;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 1em;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 5px;
        }
        .batch-delete-clients-form label {
            font-weight: bold;
        }
        .filter-button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 1em;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 5px;
        }
        .pagination {
            margin: 20px 0;
            text-align: center;
        }
        .pagination a {
            color: #4CAF50;
            float: none;
            padding: 8px 16px;
            text-decoration: none;
            transition: background-color .3s;
            border: 1px solid #ddd;
            margin: 0 4px;
            display: inline-block;
        }
        .pagination a:hover {
            background-color: #ddd;
        }
    </style>';

    echo '<h2>Batch Delete Clients</h2>';
    echo '<form method="get" action="addonmodules.php" class="batch-delete-clients-form">';
    echo '<input type="hidden" name="module" value="batch_delete_clients">';
    echo '<button type="submit" name="filter" value="zero_services" class="filter-button">Filter Clients with 0 Services</button>
    <button type="submit" name="filter" value="affiliates_only" class="filter-button">Filter Affiliate Clients w/o Services or Domains</button>';
    echo '</form>';

    echo '<form method="post" action="addonmodules.php?module=batch_delete_clients" class="batch-delete-clients-form" onsubmit="return confirmDeletion()">';
    echo '<table class="batch-delete-clients-table">';
    echo '<tr><th><input type="checkbox" id="select_all"></th><th>Client ID</th><th>Name</th><th>Email</th><th>Services Count</th></tr>';

    foreach ($clients as $client) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="client_ids[]" value="' . $client->id . '" class="client-checkbox"></td>';
        echo '<td>' . $client->id . '</td>';
        echo '<td>' . $client->firstname . ' ' . $client->lastname . '</td>';
        echo '<td>' . $client->email . '</td>';
        echo '<td>' . $client->services_count . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '<input type="submit" value="Delete Selected Clients">';
    echo '</form>';

    // Pagination
    $totalPages = ceil($totalClients / $perPage);
    if ($totalPages > 1) {
        echo '<div class="pagination">';
        for ($i = 1; $i <= $totalPages; $i++) {
            echo '<a href="addonmodules.php?module=batch_delete_clients&page=' . $i . ($filterZeroServices ? '&filter=zero_services' : '') . '">' . $i . '</a> ';
        }
        echo '</div>';
    }

    echo '
    <script>
        document.getElementById("select_all").onclick = function() {
            var checkboxes = document.querySelectorAll(".client-checkbox");
            for (var checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        }

        function confirmDeletion() {
            var checkboxes = document.querySelectorAll(".client-checkbox:checked");
            var count = checkboxes.length;
            return confirm("Are you sure you want to delete " + count + " clients?");
        }
    </script>';
}
?>
