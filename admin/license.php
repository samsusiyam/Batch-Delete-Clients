<?php
/**
 * WHMCS Batch Delete Clients - Admin License Management View
 *
 * Developed by Host Nibo
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use BatchDeleteClients\License\LicenseManager;

$licenseManager = LicenseManager::getInstance();
$successMsg     = '';
$errorMsg       = '';

// Handle License Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['activate_license'])) {
        $inputKey = trim($_POST['license_key'] ?? '');
        $res = $licenseManager->activate($inputKey);
        if (!empty($res['status'])) {
            $successMsg = $res['message'] ?? 'License activated successfully!';
        } else {
            $errorMsg = $res['message'] ?? 'Activation failed. Please verify your license key.';
        }
    } elseif (isset($_POST['reverify_license'])) {
        $res = $licenseManager->verify(true);
        if (!empty($res['status'])) {
            $successMsg = 'License verified! Your license is active and valid.';
        } else {
            $errorMsg = 'License verification failed: ' . ($res['message'] ?? 'Invalid license status');
        }
    }
}

$details = $licenseManager->getDetails(true);
$status  = strtolower($details['status']);

// Resolve logo
$logoFile = dirname(__DIR__) . '/logo.png';
if (file_exists($logoFile)) {
    $logoData = base64_encode(file_get_contents($logoFile));
    $logoSrc = 'data:image/png;base64,' . $logoData;
} else {
    $logoSrc = '../modules/addons/batch_delete_clients/logo.png';
}

$moduleUrl = 'addonmodules.php?module=batch_delete_clients';
?>

<!-- Scoped License Styles -->
<style>
    .hn-lic-container {
        max-width: 960px;
        margin: 20px auto 40px auto;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        color: #1e293b;
    }
    .hn-lic-container * {
        box-sizing: border-box;
    }
    .hn-lic-header {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 22px 26px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }
    .hn-lic-header-left {
        display: flex;
        align-items: center;
        gap: 18px;
    }
    .hn-lic-logo {
        max-height: 48px;
        width: auto;
        object-fit: contain;
    }
    .hn-lic-title h2 {
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 4px 0;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .hn-lic-title p {
        margin: 0;
        color: #64748b;
        font-size: 13.5px;
    }
    .hn-lic-status-card {
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    .hn-lic-active-bg {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
    }
    .hn-lic-inactive-bg {
        background: #fef2f2;
        border: 1px solid #fecaca;
    }
    .hn-lic-status-info h3 {
        margin: 0 0 6px 0;
        font-size: 18px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .hn-lic-meta {
        font-size: 13px;
        color: #475569;
        margin: 0;
        line-height: 1.6;
    }
    .hn-lic-meta strong {
        color: #0f172a;
    }
    .hn-lic-card {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04);
        padding: 26px;
        margin-bottom: 24px;
    }
    .hn-lic-card h3 {
        font-size: 16px;
        font-weight: 700;
        margin: 0 0 16px 0;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .hn-form-group {
        margin-bottom: 18px;
    }
    .hn-form-group label {
        display: block;
        font-size: 13.5px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 8px;
    }
    .hn-lic-input {
        width: 100%;
        padding: 11px 16px;
        font-size: 14px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        outline: none;
        transition: all 0.2s ease;
        color: #0f172a;
        font-family: monospace;
        letter-spacing: 0.5px;
    }
    .hn-lic-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .hn-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
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
    }
    .hn-btn-outline {
        background: #ffffff;
        color: #334155 !important;
        border-color: #cbd5e1;
    }
    .hn-btn-outline:hover {
        background: #f8fafc;
        border-color: #94a3b8;
    }
    .hn-alert-msg {
        padding: 14px 18px;
        border-radius: 8px;
        font-size: 13.5px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
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
    .hn-info-box {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 18px 22px;
        font-size: 13px;
        color: #64748b;
        line-height: 1.6;
    }
</style>

<div class="hn-lic-container">
    <!-- Header -->
    <div class="hn-lic-header">
        <div class="hn-lic-header-left">
            <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Host Nibo Logo" class="hn-lic-logo">
            <div class="hn-lic-title">
                <h2><i class="fa fa-shield"></i> Module License Management</h2>
                <p>Host Nibo ELMS Licensing Protection & Activation</p>
            </div>
        </div>
        <div>
            <?php if ($details['is_licensed']): ?>
                <a href="<?php echo htmlspecialchars($moduleUrl); ?>" class="hn-btn hn-btn-primary">
                    <i class="fa fa-arrow-left"></i> Back to Dashboard
                </a>
            <?php else: ?>
                <a href="https://hostnibo.com/contact" target="_blank" class="hn-btn hn-btn-outline">
                    <i class="fa fa-life-ring"></i> Get License Support
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($successMsg)): ?>
        <div class="hn-alert-msg hn-alert-success">
            <i class="fa fa-check-circle" style="font-size: 16px;"></i>
            <div><?php echo htmlspecialchars($successMsg); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="hn-alert-msg hn-alert-danger">
            <i class="fa fa-times-circle" style="font-size: 16px;"></i>
            <div><?php echo htmlspecialchars($errorMsg); ?></div>
        </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div class="hn-lic-status-card <?php echo $status === 'active' ? 'hn-lic-active-bg' : 'hn-lic-inactive-bg'; ?>">
        <div class="hn-lic-status-info">
            <h3 style="color: <?php echo $status === 'active' ? '#166534' : '#991b1b'; ?>;">
                <?php if ($status === 'active'): ?>
                    <i class="fa fa-check-circle"></i> License Status: ACTIVE
                <?php elseif ($status === 'expired'): ?>
                    <i class="fa fa-calendar-times-o"></i> License Status: EXPIRED
                <?php elseif ($status === 'suspended'): ?>
                    <i class="fa fa-ban"></i> License Status: SUSPENDED
                <?php elseif ($status === 'domain_mismatch'): ?>
                    <i class="fa fa-globe"></i> License Status: DOMAIN MISMATCH
                <?php elseif ($status === 'product_mismatch'): ?>
                    <i class="fa fa-cubes"></i> License Status: PRODUCT MISMATCH
                <?php else: ?>
                    <i class="fa fa-exclamation-triangle"></i> License Status: <?php echo strtoupper(str_replace('_', ' ', $status)); ?>
                <?php endif; ?>
            </h3>
            <?php if ($status === 'expired'): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 6px 12px; border-radius: 6px; font-size: 12.5px; margin: 4px 0 8px 0; font-weight: 600;">
                    <i class="fa fa-clock-o"></i> Your license subscription has expired. Please renew your license to continue using this module.
                </div>
            <?php elseif ($status === 'suspended'): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 6px 12px; border-radius: 6px; font-size: 12.5px; margin: 4px 0 8px 0; font-weight: 600;">
                    <i class="fa fa-ban"></i> This license has been suspended. Please contact Host Nibo Support.
                </div>
            <?php endif; ?>
            <p class="hn-lic-meta">
                Product: <strong><?php echo htmlspecialchars($details['product_name']); ?></strong> (<code><?php echo htmlspecialchars($details['product_key']); ?></code>)<br>
                Domain: <strong><?php echo htmlspecialchars($details['domain']); ?></strong> &bull;
                Server IP: <strong><?php echo htmlspecialchars($details['ip']); ?></strong><br>
                Expiry Date: <strong><?php echo htmlspecialchars($details['expiry_date']); ?></strong> &bull;
                Key: <code><?php echo htmlspecialchars($details['masked_key']); ?></code>
            </p>
        </div>
        <form method="POST" style="margin: 0;">
            <button type="submit" name="reverify_license" value="1" class="hn-btn hn-btn-outline">
                <i class="fa fa-refresh"></i> Re-verify License
            </button>
        </form>
    </div>

    <!-- Activation Form Card -->
    <div class="hn-lic-card">
        <h3><i class="fa fa-key"></i> <?php echo $details['is_licensed'] ? 'Change / Update License Key' : 'Enter License Key to Activate'; ?></h3>
        <form method="POST">
            <div class="hn-form-group">
                <label for="license_key">Product License Key:</label>
                <input type="text" id="license_key" name="license_key" class="hn-lic-input" placeholder="Enter your license key..." value="<?php echo htmlspecialchars($details['license_key']); ?>" required autocomplete="off">
                <span style="font-size: 12px; color: #64748b; margin-top: 6px; display: block;">
                    Enter the license key provided upon purchase or assigned in your Host Nibo Client Portal.
                </span>
            </div>
            <button type="submit" name="activate_license" value="1" class="hn-btn hn-btn-primary">
                <i class="fa fa-lock"></i> Activate Product License
            </button>
        </form>
    </div>

    <!-- Instructions / Support Box -->
    <div class="hn-info-box">
        <h4 style="margin: 0 0 6px 0; color: #334155; font-size: 13.5px; font-weight: 700;">
            <i class="fa fa-info-circle"></i> Need Help with Licensing?
        </h4>
        <p style="margin: 0 0 8px 0;">
            Each license is uniquely bound to your domain (<strong><?php echo htmlspecialchars($details['domain']); ?></strong>) and server IP (<strong><?php echo htmlspecialchars($details['ip']); ?></strong>).
        </p>
        <p style="margin: 0;">
            If you need to reissue your license for a domain change or server migration, please contact our support team at <a href="https://hostnibo.com/contact" target="_blank" style="color: #2563eb; text-decoration: underline; font-weight: 600;">Host Nibo Support</a> or visit <a href="https://hostnibo.com" target="_blank" style="color: #2563eb; text-decoration: underline; font-weight: 600;">hostnibo.com</a>.
        </p>
    </div>
</div>
