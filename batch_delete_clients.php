<?php
/**
 * WHMCS Batch Delete Clients Addon Module
 *
 * Developed by Host Nibo
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 * Description: Bulk delete inactive clients, zero-service accounts, and orphaned affiliates.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/app/License/LicenseManager.php';

use WHMCS\Database\Capsule;
use BatchDeleteClients\License\LicenseManager;

/**
 * Module configuration parameters
 */
function batch_delete_clients_config() {
    return [
        'name'        => 'Batch Delete Clients',
        'description' => 'A modern and powerful WHMCS addon module to easily search, filter, and batch delete inactive clients, zero-service accounts, or orphaned affiliates. Protected with Host Nibo ELMS Licensing. Designed & Developed by <a href="https://hostnibo.com" target="_blank" style="color: #2563eb; font-weight: 600; text-decoration: none;">Host Nibo</a>. For support, visit <a href="https://hostnibo.com/contact" target="_blank" style="color: #2563eb; text-decoration: none;">Host Nibo Support</a>.',
        'version'     => '2.0.0',
        'author'      => '<a href="https://hostnibo.com" target="_blank" style="color: #2563eb; font-weight: 600; text-decoration: none;">Host Nibo</a>',
        'language'    => 'english',
        'fields'      => [
            'license_key' => [
                'FriendlyName' => 'License Key',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Enter your Host Nibo ELMS license key (e.g. BDC-XXXX-XXXX-XXXX-XXXX)',
                'Default'      => ''
            ]
        ]
    ];
}

/**
 * Module activation
 */
function batch_delete_clients_activate() {
    return [
        'status'      => 'success',
        'description' => 'Batch Delete Clients module by Host Nibo has been successfully activated.'
    ];
}

/**
 * Module deactivation
 */
function batch_delete_clients_deactivate() {
    return [
        'status'      => 'success',
        'description' => 'Batch Delete Clients module has been deactivated.'
    ];
}

/**
 * Admin area module output
 */
function batch_delete_clients_output($vars) {
    $action = $_GET['action'] ?? '';

    // 🔒 ELMS License Gatekeeper: Lock access if unlicensed
    $isLicensed = LicenseManager::isLicensed(true);
    if (!$isLicensed && $action !== 'license') {
        require_once __DIR__ . '/admin/license.php';
        return;
    }

    if ($action === 'license') {
        require_once __DIR__ . '/admin/license.php';
        return;
    }

    $moduleLink = 'addonmodules.php?module=batch_delete_clients';

    // Handle Deletion
    $deleteResults = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['client_ids'])) {
        include_once __DIR__ . '/client_delete.php';
        $deleteResults = batch_delete_clients_delete();
    }

    // Parameters
    $filter  = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';
    $search  = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['limit']) ? max(10, min(500, intval($_GET['limit']))) : 20;
    $offset  = ($page - 1) * $perPage;

    // Fast Stat Counts
    try {
        $totalAllClients = Capsule::table('tblclients')->count();
        
        $totalZeroServices = Capsule::table('tblclients')
            ->leftJoin('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
            ->whereNull('tblhosting.id')
            ->count();

        $totalAffiliatesOnly = Capsule::table('tblaffiliates')
            ->leftJoin('tblhosting', 'tblaffiliates.clientid', '=', 'tblhosting.userid')
            ->leftJoin('tbldomains', 'tblaffiliates.clientid', '=', 'tbldomains.userid')
            ->whereNull('tblhosting.id')
            ->whereNull('tbldomains.id')
            ->count();
    } catch (\Exception $e) {
        $totalAllClients     = 0;
        $totalZeroServices   = 0;
        $totalAffiliatesOnly = 0;
    }

    // Query Builder for Clients
    try {
        $baseQuery = Capsule::table('tblclients')
            ->leftJoin('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
            ->leftJoin('tbldomains', 'tblclients.id', '=', 'tbldomains.userid')
            ->select(
                'tblclients.id',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.companyname',
                'tblclients.email',
                'tblclients.datecreated',
                'tblclients.status',
                Capsule::raw('COUNT(DISTINCT tblhosting.id) as services_count'),
                Capsule::raw('COUNT(DISTINCT tbldomains.id) as domains_count')
            )
            ->groupBy('tblclients.id');

        if ($filter === 'zero_services') {
            $baseQuery->havingRaw('COUNT(DISTINCT tblhosting.id) = 0');
        } elseif ($filter === 'affiliates_only') {
            $baseQuery->join('tblaffiliates', 'tblclients.id', '=', 'tblaffiliates.clientid')
                      ->havingRaw('COUNT(DISTINCT tblhosting.id) = 0 AND COUNT(DISTINCT tbldomains.id) = 0');
        }

        if (!empty($search)) {
            $baseQuery->where(function($q) use ($search) {
                $q->where('tblclients.firstname', 'LIKE', "%{$search}%")
                  ->orWhere('tblclients.lastname', 'LIKE', "%{$search}%")
                  ->orWhere('tblclients.email', 'LIKE', "%{$search}%")
                  ->orWhere('tblclients.companyname', 'LIKE', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('tblclients.id', '=', intval($search));
                }
            });
        }

        $baseQuery->orderBy('tblclients.id', 'desc');

        // Total count of filtered records
        $totalFiltered = $baseQuery->get()->count();
        $totalPages    = max(1, ceil($totalFiltered / $perPage));

        // Fetch page items
        $clients = $baseQuery->skip($offset)->take($perPage)->get();
    } catch (\Exception $e) {
        $clients       = [];
        $totalFiltered = 0;
        $totalPages    = 1;
        $dbError       = $e->getMessage();
    }

    // Logo resolution
    $logoFile = __DIR__ . '/logo.png';
    if (file_exists($logoFile)) {
        $logoData = base64_encode(file_get_contents($logoFile));
        $logoSrc = 'data:image/png;base64,' . $logoData;
    } else {
        $logoSrc = '../modules/addons/batch_delete_clients/logo.png';
    }

    // Build URL helper for pagination & filters
    $buildUrl = function($newParams = []) use ($moduleLink, $filter, $search, $perPage, $page) {
        $params = [
            'filter' => $filter,
            'search' => $search,
            'limit'  => $perPage,
            'page'   => $page
        ];
        foreach ($newParams as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        return $moduleLink . '&' . http_build_query($params);
    };
    ?>

    <!-- Module Scoped CSS Styles -->
    <style>
        .hn-bdc-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            margin-top: 15px;
            margin-bottom: 40px;
        }
        .hn-bdc-container * {
            box-sizing: border-box;
        }
        /* Header Card */
        .hn-header-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .hn-header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .hn-logo-img {
            max-height: 52px;
            width: auto;
            object-fit: contain;
            border-radius: 6px;
        }
        .hn-header-titles h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px 0;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hn-version-badge {
            font-size: 11px;
            font-weight: 600;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 0.5px;
        }
        .hn-lic-status-badge {
            font-size: 11px;
            font-weight: 600;
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            padding: 2px 8px;
            border-radius: 20px;
        }
        .hn-header-titles p {
            margin: 0;
            color: #64748b;
            font-size: 13.5px;
        }
        .hn-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hn-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none !important;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            line-height: 1.4;
        }
        .hn-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }
        .hn-btn-primary {
            background: #2563eb;
            color: #ffffff !important;
            border-color: #2563eb;
        }
        .hn-btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }
        .hn-btn-outline {
            background: #ffffff;
            color: #334155 !important;
            border-color: #cbd5e1;
        }
        .hn-btn-outline:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a !important;
        }
        .hn-btn-danger {
            background: #ef4444;
            color: #ffffff !important;
            border-color: #ef4444;
        }
        .hn-btn-danger:hover {
            background: #dc2626;
            border-color: #dc2626;
        }
        .hn-btn-danger:disabled {
            background: #fca5a5 !important;
            border-color: #fca5a5 !important;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        .hn-btn-sm {
            padding: 6px 12px;
            font-size: 12.5px;
            border-radius: 6px;
        }

        /* Stats Grid */
        .hn-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .hn-stat-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            text-decoration: none !important;
            color: inherit !important;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }
        .hn-stat-card:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.06);
        }
        .hn-stat-card.active-card {
            border-color: #3b82f6;
            background: #f8faff;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        .hn-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .hn-icon-blue { background: #eff6ff; color: #2563eb; }
        .hn-icon-amber { background: #fffbeb; color: #d97706; }
        .hn-icon-purple { background: #faf5ff; color: #9333ea; }
        .hn-icon-emerald { background: #ecfdf5; color: #059669; }
        .hn-stat-info {
            display: flex;
            flex-direction: column;
        }
        .hn-stat-num {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        .hn-stat-label {
            font-size: 12.5px;
            color: #64748b;
            font-weight: 500;
        }

        /* Alert Box */
        .hn-alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            font-size: 14px;
            line-height: 1.5;
            position: relative;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .hn-alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .hn-alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .hn-alert-icon {
            font-size: 18px;
            margin-top: 1px;
        }

        /* Filter & Search Bar */
        .hn-filter-bar {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .hn-filter-pills {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .hn-pill {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none !important;
            color: #475569 !important;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .hn-pill:hover {
            background: #e2e8f0;
            color: #0f172a !important;
        }
        .hn-pill.active {
            background: #2563eb;
            color: #ffffff !important;
            border-color: #2563eb;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
        }
        .hn-pill-badge {
            background: rgba(0,0,0,0.12);
            padding: 1px 7px;
            border-radius: 12px;
            font-size: 11.5px;
        }
        .hn-pill.active .hn-pill-badge {
            background: rgba(255,255,255,0.25);
            color: #ffffff;
        }
        .hn-search-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hn-search-input {
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            width: 240px;
            transition: all 0.2s ease;
            color: #1e293b;
        }
        .hn-search-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        /* Bulk Action Bar */
        .hn-bulk-bar {
            background: #ffffff;
            border-radius: 10px 10px 0 0;
            border: 1px solid #e2e8f0;
            border-bottom: none;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .hn-bulk-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .hn-bulk-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hn-selected-tag {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        /* Data Table */
        .hn-table-card {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
        }
        .hn-table-card.no-bulk {
            border-radius: 12px;
        }
        .hn-table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .hn-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }
        .hn-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .hn-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .hn-table tbody tr {
            transition: background 0.15s ease;
        }
        .hn-table tbody tr:hover {
            background: #f8fafc;
        }
        .hn-table tbody tr.row-selected {
            background: #eff6ff !important;
        }

        /* Custom Checkbox */
        .hn-checkbox {
            width: 17px;
            height: 17px;
            cursor: pointer;
            accent-color: #2563eb;
        }

        /* Badges */
        .hn-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }
        .hn-badge-zero {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }
        .hn-badge-has {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #d1fae5;
        }
        .hn-badge-info {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #dbeafe;
        }
        .hn-badge-gray {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .hn-badge-active {
            background: #ecfdf5;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .hn-badge-inactive {
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #ffe4e6;
        }
        .hn-badge-closed {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        /* Client Info */
        .hn-client-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .hn-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #4338ca;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13.5px;
            flex-shrink: 0;
        }
        .hn-client-name {
            font-weight: 600;
            color: #0f172a;
            text-decoration: none !important;
        }
        .hn-client-name:hover {
            color: #2563eb;
            text-decoration: underline !important;
        }
        .hn-client-email {
            font-size: 12px;
            color: #64748b;
            display: block;
        }

        /* Empty State */
        .hn-empty-state {
            padding: 48px 24px;
            text-align: center;
            color: #64748b;
        }
        .hn-empty-icon {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 12px;
        }
        .hn-empty-state h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 6px 0;
        }
        .hn-empty-state p {
            font-size: 13.5px;
            margin: 0;
        }

        /* Pagination */
        .hn-pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            padding: 16px 20px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
        }
        .hn-pagination-info {
            font-size: 13px;
            color: #64748b;
        }
        .hn-pagination-list {
            display: flex;
            align-items: center;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .hn-page-link {
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none !important;
            color: #334155 !important;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            transition: all 0.15s ease;
        }
        .hn-page-link:hover {
            background: #e2e8f0;
            color: #0f172a !important;
        }
        .hn-page-link.active {
            background: #2563eb;
            color: #ffffff !important;
            border-color: #2563eb;
        }
        .hn-page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Footer */
        .hn-footer {
            margin-top: 24px;
            padding: 18px 24px;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 13px;
            color: #64748b;
        }
        .hn-footer a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .hn-footer a:hover {
            text-decoration: underline;
        }

        /* Modal Overlay */
        .hn-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 999999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .hn-modal-overlay.show {
            display: flex;
            animation: fadeIn 0.2s ease;
        }
        .hn-modal {
            background: #ffffff;
            border-radius: 16px;
            max-width: 460px;
            width: 100%;
            padding: 28px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1);
            text-align: center;
        }
        .hn-modal-icon {
            width: 60px;
            height: 60px;
            background: #fef2f2;
            color: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin: 0 auto 18px auto;
            border: 4px solid #fee2e2;
        }
        .hn-modal h2 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 10px 0;
        }
        .hn-modal p {
            font-size: 14px;
            color: #475569;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }
        .hn-modal-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
    </style>

    <div class="hn-bdc-container">
        <!-- Top Header Card -->
        <div class="hn-header-card">
            <div class="hn-header-left">
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Host Nibo Logo" class="hn-logo-img">
                <div class="hn-header-titles">
                    <h1>
                        Batch Delete Clients
                        <span class="hn-version-badge">v2.0.0</span>
                        <span class="hn-lic-status-badge"><i class="fa fa-shield"></i> Licensed</span>
                    </h1>
                    <p>Clean up, filter, and batch delete inactive or zero-service client accounts in WHMCS.</p>
                </div>
            </div>
            <div class="hn-header-actions">
                <a href="<?php echo htmlspecialchars($moduleLink . '&action=license'); ?>" class="hn-btn hn-btn-outline" title="Manage License">
                    <i class="fa fa-key"></i> License
                </a>
                <a href="https://hostnibo.com" target="_blank" class="hn-btn hn-btn-outline">
                    <i class="fa fa-globe"></i> Visit Host Nibo
                </a>
                <a href="https://hostnibo.com/contact" target="_blank" class="hn-btn hn-btn-primary">
                    <i class="fa fa-life-ring"></i> Get Support
                </a>
            </div>
        </div>

        <!-- Deletion Feedback Alerts -->
        <?php if ($deleteResults !== null): ?>
            <?php if (!empty($deleteResults['success'])): ?>
                <div class="hn-alert hn-alert-success">
                    <i class="fa fa-check-circle hn-alert-icon"></i>
                    <div>
                        <strong>Success!</strong> Successfully deleted <?php echo count($deleteResults['success']); ?> client(s): 
                        <code>#<?php echo implode(', #', $deleteResults['success']); ?></code>.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($deleteResults['errors'])): ?>
                <div class="hn-alert hn-alert-danger">
                    <i class="fa fa-exclamation-triangle hn-alert-icon"></i>
                    <div>
                        <strong>Some errors occurred during deletion:</strong>
                        <ul style="margin: 6px 0 0 0; padding-left: 20px;">
                            <?php foreach ($deleteResults['errors'] as $errorMsg): ?>
                                <li><?php echo htmlspecialchars($errorMsg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($dbError)): ?>
            <div class="hn-alert hn-alert-danger">
                <i class="fa fa-exclamation-triangle hn-alert-icon"></i>
                <div>
                    <strong>Database Query Error:</strong> <?php echo htmlspecialchars($dbError); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Stats Overview Cards -->
        <div class="hn-stats-grid">
            <a href="<?php echo $buildUrl(['filter' => 'all', 'page' => 1]); ?>" class="hn-stat-card <?php echo ($filter === 'all') ? 'active-card' : ''; ?>">
                <div class="hn-stat-icon hn-icon-blue">
                    <i class="fa fa-users"></i>
                </div>
                <div class="hn-stat-info">
                    <span class="hn-stat-num"><?php echo number_format($totalAllClients); ?></span>
                    <span class="hn-stat-label">Total Clients</span>
                </div>
            </a>

            <a href="<?php echo $buildUrl(['filter' => 'zero_services', 'page' => 1]); ?>" class="hn-stat-card <?php echo ($filter === 'zero_services') ? 'active-card' : ''; ?>">
                <div class="hn-stat-icon hn-icon-amber">
                    <i class="fa fa-cube"></i>
                </div>
                <div class="hn-stat-info">
                    <span class="hn-stat-num"><?php echo number_format($totalZeroServices); ?></span>
                    <span class="hn-stat-label">0 Services Clients</span>
                </div>
            </a>

            <a href="<?php echo $buildUrl(['filter' => 'affiliates_only', 'page' => 1]); ?>" class="hn-stat-card <?php echo ($filter === 'affiliates_only') ? 'active-card' : ''; ?>">
                <div class="hn-stat-icon hn-icon-purple">
                    <i class="fa fa-user-times"></i>
                </div>
                <div class="hn-stat-info">
                    <span class="hn-stat-num"><?php echo number_format($totalAffiliatesOnly); ?></span>
                    <span class="hn-stat-label">Inactive Affiliates</span>
                </div>
            </a>

            <div class="hn-stat-card active-card">
                <div class="hn-stat-icon hn-icon-emerald">
                    <i class="fa fa-filter"></i>
                </div>
                <div class="hn-stat-info">
                    <span class="hn-stat-num"><?php echo number_format($totalFiltered); ?></span>
                    <span class="hn-stat-label">Current Matches</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="hn-filter-bar">
            <div class="hn-filter-pills">
                <a href="<?php echo $buildUrl(['filter' => 'all', 'page' => 1]); ?>" class="hn-pill <?php echo ($filter === 'all') ? 'active' : ''; ?>">
                    <i class="fa fa-list"></i> All Clients
                    <span class="hn-pill-badge"><?php echo $totalAllClients; ?></span>
                </a>
                <a href="<?php echo $buildUrl(['filter' => 'zero_services', 'page' => 1]); ?>" class="hn-pill <?php echo ($filter === 'zero_services') ? 'active' : ''; ?>">
                    <i class="fa fa-exclamation-circle"></i> 0 Services Only
                    <span class="hn-pill-badge"><?php echo $totalZeroServices; ?></span>
                </a>
                <a href="<?php echo $buildUrl(['filter' => 'affiliates_only', 'page' => 1]); ?>" class="hn-pill <?php echo ($filter === 'affiliates_only') ? 'active' : ''; ?>">
                    <i class="fa fa-handshake-o"></i> Inactive Affiliates (0 Svc & 0 Dom)
                    <span class="hn-pill-badge"><?php echo $totalAffiliatesOnly; ?></span>
                </a>
            </div>

            <form method="get" action="addonmodules.php" class="hn-search-form">
                <input type="hidden" name="module" value="batch_delete_clients">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by ID, Name, Email..." class="hn-search-input">
                <button type="submit" class="hn-btn hn-btn-primary hn-btn-sm">
                    <i class="fa fa-search"></i> Search
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo $buildUrl(['search' => '', 'page' => 1]); ?>" class="hn-btn hn-btn-outline hn-btn-sm" title="Clear Search">
                        <i class="fa fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Batch Delete Form -->
        <form method="post" action="<?php echo htmlspecialchars($buildUrl()); ?>" id="batchDeleteForm">
            <!-- Bulk Action Bar -->
            <div class="hn-bulk-bar">
                <div class="hn-bulk-left">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; font-size: 13.5px; margin: 0;">
                        <input type="checkbox" id="hnSelectAll" class="hn-checkbox">
                        <span>Select All On Page</span>
                    </label>
                    <span class="hn-selected-tag">
                        Selected: <span id="hnSelectedCount" style="color: #2563eb;">0</span> client(s)
                    </span>
                </div>
                <div class="hn-bulk-right">
                    <button type="button" class="hn-btn hn-btn-outline hn-btn-sm" id="btnSelectVisible">
                        <i class="fa fa-check-square-o"></i> Select All
                    </button>
                    <button type="button" class="hn-btn hn-btn-outline hn-btn-sm" id="btnDeselectVisible">
                        <i class="fa fa-square-o"></i> Deselect All
                    </button>
                    <button type="button" class="hn-btn hn-btn-danger" id="btnDeleteSelected" disabled onclick="openDeleteModal()">
                        <i class="fa fa-trash"></i> Delete Selected (<span id="btnSelectedCount">0</span>)
                    </button>
                </div>
            </div>

            <!-- Table Card -->
            <div class="hn-table-card">
                <div class="hn-table-responsive">
                    <table class="hn-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;">
                                    <input type="checkbox" id="hnTableHeadSelectAll" class="hn-checkbox">
                                </th>
                                <th style="width: 90px;">Client ID</th>
                                <th>Client Details</th>
                                <th style="width: 140px;">Services</th>
                                <th style="width: 140px;">Domains</th>
                                <th style="width: 150px;">Created Date</th>
                                <th style="width: 110px;">Status</th>
                                <th style="width: 130px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clients)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="hn-empty-state">
                                            <div class="hn-empty-icon"><i class="fa fa-folder-open-o"></i></div>
                                            <h3>No Clients Found</h3>
                                            <p>No clients matched your current filter criteria or search query.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clients as $client): ?>
                                    <?php
                                        $fullName = trim($client->firstname . ' ' . $client->lastname);
                                        if (empty($fullName)) {
                                            $fullName = 'Client #' . $client->id;
                                        }
                                        $initial = strtoupper(substr($client->firstname, 0, 1));
                                        if (empty($initial)) {
                                            $initial = 'C';
                                        }
                                        $clientStatus = strtolower($client->status ?? 'active');
                                        $statusClass = 'hn-badge-active';
                                        if ($clientStatus === 'inactive') {
                                            $statusClass = 'hn-badge-inactive';
                                        } elseif ($clientStatus === 'closed') {
                                            $statusClass = 'hn-badge-closed';
                                        }
                                        $createdDate = !empty($client->datecreated) ? date('M d, Y', strtotime($client->datecreated)) : 'N/A';
                                    ?>
                                    <tr id="row-<?php echo $client->id; ?>">
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="client_ids[]" value="<?php echo $client->id; ?>" class="hn-checkbox hn-client-check" onchange="updateSelectedCount()">
                                        </td>
                                        <td>
                                            <a href="clientssummary.php?userid=<?php echo $client->id; ?>" target="_blank" class="hn-badge hn-badge-info" title="View Client Summary in WHMCS">
                                                #<?php echo $client->id; ?> <i class="fa fa-external-link" style="font-size: 10px;"></i>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="hn-client-cell">
                                                <div class="hn-avatar"><?php echo $initial; ?></div>
                                                <div>
                                                    <a href="clientssummary.php?userid=<?php echo $client->id; ?>" target="_blank" class="hn-client-name">
                                                        <?php echo htmlspecialchars($fullName); ?>
                                                    </a>
                                                    <?php if (!empty($client->companyname)): ?>
                                                        <span style="font-size: 11.5px; color: #475569; display: block;"><?php echo htmlspecialchars($client->companyname); ?></span>
                                                    <?php endif; ?>
                                                    <span class="hn-client-email">
                                                        <i class="fa fa-envelope-o" style="font-size: 11px;"></i> <?php echo htmlspecialchars($client->email); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($client->services_count == 0): ?>
                                                <span class="hn-badge hn-badge-zero"><i class="fa fa-times"></i> 0 Services</span>
                                            <?php else: ?>
                                                <span class="hn-badge hn-badge-has"><i class="fa fa-check"></i> <?php echo $client->services_count; ?> Service(s)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($client->domains_count == 0): ?>
                                                <span class="hn-badge hn-badge-gray"><i class="fa fa-globe"></i> 0 Domains</span>
                                            <?php else: ?>
                                                <span class="hn-badge hn-badge-info"><i class="fa fa-globe"></i> <?php echo $client->domains_count; ?> Domain(s)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 12.5px; color: #475569;">
                                                <i class="fa fa-calendar-o"></i> <?php echo $createdDate; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="hn-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($clientStatus); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; gap: 6px;">
                                                <a href="clientssummary.php?userid=<?php echo $client->id; ?>" target="_blank" class="hn-btn hn-btn-outline hn-btn-sm" title="View Profile">
                                                    <i class="fa fa-eye"></i>
                                                </a>
                                                <button type="button" class="hn-btn hn-btn-danger hn-btn-sm" title="Delete this client" onclick="deleteSingleClient(<?php echo $client->id; ?>)">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination & Record Controls -->
                <?php if ($totalFiltered > 0): ?>
                    <div class="hn-pagination-wrapper">
                        <div class="hn-pagination-info">
                            Showing <strong><?php echo min($offset + 1, $totalFiltered); ?></strong> to <strong><?php echo min($offset + $perPage, $totalFiltered); ?></strong> of <strong><?php echo number_format($totalFiltered); ?></strong> client(s)
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <ul class="hn-pagination-list">
                                <!-- First & Prev -->
                                <li>
                                    <a href="<?php echo $buildUrl(['page' => 1]); ?>" class="hn-page-link <?php echo ($page <= 1) ? 'disabled' : ''; ?>" title="First Page">
                                        <i class="fa fa-angle-double-left"></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo $buildUrl(['page' => $page - 1]); ?>" class="hn-page-link <?php echo ($page <= 1) ? 'disabled' : ''; ?>" title="Previous Page">
                                        <i class="fa fa-angle-left"></i>
                                    </a>
                                </li>

                                <!-- Page Number Loop (Limited Range for clean display) -->
                                <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage   = min($totalPages, $page + 2);
                                    if ($startPage > 1) {
                                        echo '<li><span class="hn-page-link disabled">...</span></li>';
                                    }
                                    for ($i = $startPage; $i <= $endPage; $i++) {
                                        $activeClass = ($i === $page) ? 'active' : '';
                                        echo '<li><a href="' . htmlspecialchars($buildUrl(['page' => $i])) . '" class="hn-page-link ' . $activeClass . '">' . $i . '</a></li>';
                                    }
                                    if ($endPage < $totalPages) {
                                        echo '<li><span class="hn-page-link disabled">...</span></li>';
                                    }
                                ?>

                                <!-- Next & Last -->
                                <li>
                                    <a href="<?php echo $buildUrl(['page' => $page + 1]); ?>" class="hn-page-link <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>" title="Next Page">
                                        <i class="fa fa-angle-right"></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo $buildUrl(['page' => $totalPages]); ?>" class="hn-page-link <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>" title="Last Page">
                                        <i class="fa fa-angle-double-right"></i>
                                    </a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <!-- Modern Confirmation Modal -->
        <div class="hn-modal-overlay" id="hnDeleteModal">
            <div class="hn-modal">
                <div class="hn-modal-icon">
                    <i class="fa fa-trash"></i>
                </div>
                <h2>Confirm Permanent Deletion</h2>
                <p>
                    Are you sure you want to permanently delete <strong id="modalSelectedCount" style="color: #ef4444;">0</strong> selected client(s)?<br>
                    <span style="font-size: 12.5px; color: #dc2626; display: block; margin-top: 6px;">
                        <i class="fa fa-warning"></i> Warning: This action cannot be undone and will remove associated client data.
                    </span>
                </p>
                <div class="hn-modal-actions">
                    <button type="button" class="hn-btn hn-btn-outline" onclick="closeDeleteModal()">Cancel</button>
                    <button type="button" class="hn-btn hn-btn-danger" onclick="submitBatchDelete()">
                        <i class="fa fa-trash"></i> Yes, Delete Permanently
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer Card with Host Nibo Branding -->
        <div class="hn-footer">
            <div style="display: flex; align-items: center; gap: 8px;">
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Host Nibo" style="height: 22px; width: auto; vertical-align: middle;">
                <span>Batch Delete Clients Module &bull; Version 2.0.0</span>
            </div>
            <div>
                Developed with pride by <a href="https://hostnibo.com" target="_blank">Host Nibo</a> &bull;
                <a href="<?php echo htmlspecialchars($moduleLink . '&action=license'); ?>">License Info</a> &bull;
                <a href="https://hostnibo.com/contact" target="_blank">Contact Support</a>
            </div>
        </div>
    </div>

    <!-- Client-side Interactive Script -->
    <script>
        function updateSelectedCount() {
            var checkboxes = document.querySelectorAll('.hn-client-check');
            var checked = document.querySelectorAll('.hn-client-check:checked');
            var count = checked.length;

            document.getElementById('hnSelectedCount').innerText = count;
            document.getElementById('btnSelectedCount').innerText = count;

            var deleteBtn = document.getElementById('btnDeleteSelected');
            if (count > 0) {
                deleteBtn.removeAttribute('disabled');
            } else {
                deleteBtn.setAttribute('disabled', 'disabled');
            }

            // Sync master checkboxes
            var allChecked = checkboxes.length > 0 && count === checkboxes.length;
            var master1 = document.getElementById('hnSelectAll');
            var master2 = document.getElementById('hnTableHeadSelectAll');
            if (master1) master1.checked = allChecked;
            if (master2) master2.checked = allChecked;

            // Highlight selected rows
            checkboxes.forEach(function(cb) {
                var row = document.getElementById('row-' + cb.value);
                if (row) {
                    if (cb.checked) {
                        row.classList.add('row-selected');
                    } else {
                        row.classList.remove('row-selected');
                    }
                }
            });
        }

        // Master checkbox listeners
        function toggleAllCheckboxes(checked) {
            var checkboxes = document.querySelectorAll('.hn-client-check');
            checkboxes.forEach(function(cb) {
                cb.checked = checked;
            });
            updateSelectedCount();
        }

        var master1 = document.getElementById('hnSelectAll');
        if (master1) {
            master1.addEventListener('change', function() {
                toggleAllCheckboxes(this.checked);
            });
        }

        var master2 = document.getElementById('hnTableHeadSelectAll');
        if (master2) {
            master2.addEventListener('change', function() {
                toggleAllCheckboxes(this.checked);
            });
        }

        var btnSelectVisible = document.getElementById('btnSelectVisible');
        if (btnSelectVisible) {
            btnSelectVisible.addEventListener('click', function() {
                toggleAllCheckboxes(true);
            });
        }

        var btnDeselectVisible = document.getElementById('btnDeselectVisible');
        if (btnDeselectVisible) {
            btnDeselectVisible.addEventListener('click', function() {
                toggleAllCheckboxes(false);
            });
        }

        // Modal Controls
        function openDeleteModal() {
            var count = document.querySelectorAll('.hn-client-check:checked').length;
            if (count <= 0) return;
            document.getElementById('modalSelectedCount').innerText = count;
            document.getElementById('hnDeleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('hnDeleteModal').classList.remove('show');
        }

        function submitBatchDelete() {
            document.getElementById('batchDeleteForm').submit();
        }

        function deleteSingleClient(clientId) {
            toggleAllCheckboxes(false);
            var cb = document.querySelector('.hn-client-check[value="' + clientId + '"]');
            if (cb) {
                cb.checked = true;
                updateSelectedCount();
                openDeleteModal();
            }
        }

        // Close modal on click outside
        document.getElementById('hnDeleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
    </script>
    <?php
}
