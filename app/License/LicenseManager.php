<?php
/**
 * WHMCS Batch Delete Clients - ELMS License Manager
 *
 * Developed by Host Nibo
 * Website: https://hostnibo.com
 * Support: https://hostnibo.com/contact
 * License Server: https://lic.hostnibo.com
 */

namespace BatchDeleteClients\License;

use WHMCS\Database\Capsule;

class LicenseManager
{
    // 1. ELMS License Server URL
    public const DEFAULT_SERVER_URL  = 'https://lic.hostnibo.com';

    // 2. ELMS Product Key
    public const DEFAULT_PRODUCT_KEY = 'BATCH-DELETE-CLIENTS';

    // 3. Cache validity in seconds (15 Minutes)
    public const CACHE_TTL_SECONDS   = 900;

    private static ?self $instance = null;
    private string $serverUrl;
    private string $productKey;
    private string $cacheDir;

    public function __construct()
    {
        $this->serverUrl  = self::DEFAULT_SERVER_URL;
        $this->productKey = self::DEFAULT_PRODUCT_KEY;
        $this->cacheDir   = dirname(__DIR__, 2) . '/storage/license';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function isLicensed(?bool $forceRemote = null): bool
    {
        if ($forceRemote === null) {
            $isAdmin = defined('ADMINAREA') && ADMINAREA;
            $forceRemote = $isAdmin;
        }
        return self::getInstance()->checkLicenseValid($forceRemote);
    }

    public function getLicenseKey(): string
    {
        try {
            $key = Capsule::table('tbladdonmodules')
                ->where('module', 'batch_delete_clients')
                ->where('setting', 'license_key')
                ->value('value');
            return trim((string)$key);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function saveLicenseKey(string $key): void
    {
        $key = trim($key);
        try {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => 'batch_delete_clients', 'setting' => 'license_key'],
                ['value' => $key]
            );
        } catch (\Throwable $e) {
            // Silence DB save errors
        }
    }

    public function getDomain(): string
    {
        $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $domain = preg_replace('/:\d+$/', '', (string)$domain);
        return strtolower(trim($domain)) ?: 'localhost';
    }

    public function getIp(): string
    {
        $ip = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            $ip = gethostbyname(gethostname()) ?: '127.0.0.1';
        }
        return trim($ip);
    }

    public function checkLicenseValid(bool $forceRemote = true): bool
    {
        $key = $this->getLicenseKey();
        if (empty($key)) {
            return false;
        }

        if ($forceRemote) {
            $res = $this->verify(true);
            return !empty($res['status']);
        }

        $cached = $this->readCache($key, $this->getDomain());
        if ($cached !== null) {
            return !empty($cached['status']);
        }

        $res = $this->verify(true);
        return !empty($res['status']);
    }

    public function verify(bool $forceRemote = true): array
    {
        $key = $this->getLicenseKey();
        $domain = $this->getDomain();
        $ip = $this->getIp();

        if (empty($key)) {
            return [
                'status'  => false,
                'message' => 'No license key entered.',
                'data'    => []
            ];
        }

        if (!$forceRemote) {
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $res = $this->post('/api/license/verify', [
                'license_key' => $key,
                'domain'      => $domain,
                'ip'          => $ip,
                'product'     => $this->productKey,
            ]);

            if (!empty($res['status'])) {
                $this->writeCache($key, $domain, $ip, $res);
            } else {
                $this->clearCache($key, $domain);
            }

            return $res;
        } catch (\Throwable $e) {
            $cached = $this->readCache($key, $domain);
            if ($cached !== null) {
                return $cached;
            }
            return [
                'status'  => false,
                'message' => $e->getMessage(),
                'data'    => []
            ];
        }
    }

    public function activate(string $newKey): array
    {
        $newKey = trim($newKey);
        if ($newKey !== '') {
            $this->saveLicenseKey($newKey);
        }

        $domain = $this->getDomain();
        $ip = $this->getIp();

        try {
            $res = $this->post('/api/license/activate', [
                'license_key'     => $newKey,
                'domain'          => $domain,
                'ip'              => $ip,
                'product'         => $this->productKey,
                'server_hostname' => gethostname() ?: 'unknown',
            ]);

            if (!empty($res['status']) || ($res['message'] ?? '') === 'Already activated') {
                $this->writeCache($newKey, $domain, $ip, $res);
                return [
                    'status'  => true,
                    'message' => 'License activated successfully!'
                ];
            }

            $this->clearCache($newKey, $domain);
            return $res;
        } catch (\Throwable $e) {
            return [
                'status'  => false,
                'message' => 'Activation failed: ' . $e->getMessage()
            ];
        }
    }

    public function getDetails(bool $refreshLive = false): array
    {
        $key = $this->getLicenseKey();
        $details = [
            'status'       => 'unlicensed',
            'expiry'       => 'Lifetime',
            'product_name' => 'Batch Delete Clients',
            'product_key'  => $this->productKey,
        ];

        if (!empty($key)) {
            $check = $refreshLive ? $this->verify(true) : ($this->readCache($key, $this->getDomain()) ?? $this->verify(true));
            if (!empty($check['status'])) {
                $details['status']       = 'active';
                $details['expiry']       = $check['data']['expiry'] ?? ($check['data']['expires_at'] ?? 'Lifetime');
                $details['product_name'] = $check['data']['product_name'] ?? ($check['data']['product'] ?? 'Batch Delete Clients');
                $details['product_key']  = $check['data']['product_key'] ?? $this->productKey;
            } else {
                $msg = strtolower($check['message'] ?? '');
                if (strpos($msg, 'suspend') !== false) {
                    $details['status'] = 'suspended';
                } elseif (strpos($msg, 'terminate') !== false) {
                    $details['status'] = 'terminated';
                } elseif (strpos($msg, 'domain') !== false) {
                    $details['status'] = 'domain_mismatch';
                } elseif (strpos($msg, 'expired') !== false) {
                    $details['status'] = 'expired';
                } elseif (strpos($msg, 'mismatch') !== false || strpos($msg, 'product') !== false) {
                    $details['status'] = 'product_mismatch';
                } else {
                    $details['status'] = 'invalid';
                }
            }
        }

        $masked = !empty($key) && strlen($key) >= 8 
            ? substr($key, 0, 4) . '-****-****-' . substr($key, -4) 
            : (!empty($key) ? '****' : 'None');

        return [
            'license_key'  => $key,
            'masked_key'   => $masked,
            'status'       => $details['status'],
            'is_licensed'  => ($details['status'] === 'active'),
            'expiry_date'  => $details['expiry'],
            'domain'       => $this->getDomain(),
            'ip'           => $this->getIp(),
            'product_name' => $details['product_name'] ?? 'Batch Delete Clients',
            'product_key'  => $details['product_key'] ?? $this->productKey,
            'server_url'   => $this->serverUrl,
        ];
    }

    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload);
        $ch = curl_init($this->serverUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
        ]);
        $resp = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [
                'status'  => false,
                'message' => 'Connection to license server failed: ' . $curlError
            ];
        }

        $decoded = json_decode((string)$resp, true);
        return is_array($decoded) ? $decoded : [
            'status'  => false,
            'message' => 'Invalid response from license server'
        ];
    }

    private function getCacheFile(string $key, string $domain): string
    {
        return $this->cacheDir . '/lic_' . substr(hash('sha256', $key . '|' . $this->productKey . '|' . $domain), 0, 32) . '.json';
    }

    private function writeCache(string $key, string $domain, string $ip, array $payload): void
    {
        @file_put_contents(
            $this->getCacheFile($key, $domain),
            json_encode([
                'ts'      => time(),
                'domain'  => $domain,
                'payload' => $payload
            ])
        );
    }

    private function readCache(string $key, string $domain): ?array
    {
        $file = $this->getCacheFile($key, $domain);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(@file_get_contents($file) ?: '', true);
        if (!is_array($data) || (time() - (int)($data['ts'] ?? 0)) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        if (strtolower($data['domain'] ?? '') !== strtolower($domain)) {
            return null;
        }
        return $data['payload'] ?? null;
    }

    private function clearCache(string $key, string $domain): void
    {
        @unlink($this->getCacheFile($key, $domain));
    }
}
