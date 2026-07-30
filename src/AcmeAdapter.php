<?php

declare(strict_types=1);

/**
 * Reads managed certificate state from acme.sh (list + domain confs).
 * Never exposes private keys.
 */
final class AcmeAdapter
{
    /** @return list<array{domain:string,expiry:?string,webroot:?string,deploy_hook:bool,san:list<string>}> */
    public function listCertificates(): array
    {
        $bin = (string) config_get('paths.acme_bin');
        $home = (string) config_get('paths.acme_home');

        if ($bin === '' || !is_file($bin)) {
            throw new RuntimeException('acme.sh binary not found at configured path.');
        }

        $cmd = escapeshellarg($bin)
            . ' --list --listraw --home ' . escapeshellarg($home)
            . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('acme.sh --list failed: ' . implode("\n", $output));
        }

        $certs = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Main_Domain')) {
                continue;
            }

            // listraw: Main_Domain|KeyLength|SAN|CA|Created|Renew
            // Renew is next-renew-by time, NOT cert expiry — do not use it for drift.
            $parts = explode('|', $line);
            if (count($parts) < 6) {
                // Fallback whitespace columns
                $parts = preg_split('/\s+/', $line) ?: [];
                if (count($parts) < 6) {
                    continue;
                }
                $domain = $parts[0];
                $sanRaw = $parts[2];
            } else {
                $domain = $parts[0];
                $sanRaw = $parts[2];
            }

            if ($domain === '') {
                continue;
            }

            $san = array_values(array_filter(array_map('trim', explode(',', (string) $sanRaw))));
            $meta = $this->readDomainConf($domain);

            $certs[] = [
                'domain' => strtolower($domain),
                'expiry' => $this->certExpiryFromDir($meta['dir']),
                'webroot' => $meta['webroot'] ?? null,
                'deploy_hook' => $meta['deploy_hook'],
                'san' => $san,
            ];
        }

        return $certs;
    }

    /** @return array{webroot:?string,deploy_hook:bool,dir:?string} */
    public function readDomainConf(string $domain): array
    {
        $home = (string) config_get('paths.acme_home');
        $path = $home . '/' . $domain . '/' . $domain . '.conf';
        $dir = $home . '/' . $domain;
        $result = [
            'webroot' => null,
            'deploy_hook' => false,
            'dir' => null,
        ];

        if (!is_readable($path)) {
            // Also try _ecc suffix common for ECC certs
            $pathEcc = $home . '/' . $domain . '_ecc/' . $domain . '.conf';
            if (is_readable($pathEcc)) {
                $path = $pathEcc;
                $dir = $home . '/' . $domain . '_ecc';
            } else {
                return $result;
            }
        }

        $result['dir'] = $dir;
        $contents = (string) file_get_contents($path);
        if (preg_match("/Le_Webroot=['\"]?([^'\"\\n]+)/", $contents, $m)) {
            $result['webroot'] = $m[1];
        }
        if (preg_match("/Le_DeployHook=['\"]?([^'\"\\n]+)/", $contents, $m)) {
            $hooks = strtolower($m[1]);
            $result['deploy_hook'] = str_contains($hooks, 'cpanel_uapi');
        }

        return $result;
    }

    /** Certificate Not After (YYYY-MM-DD) from fullchain.cer — comparable to cPanel expiry. */
    private function certExpiryFromDir(?string $dir): ?string
    {
        if ($dir === null || $dir === '') {
            return null;
        }

        $fullchain = $dir . '/fullchain.cer';
        if (!is_readable($fullchain)) {
            return null;
        }

        if (extension_loaded('openssl')) {
            $raw = @file_get_contents($fullchain);
            if (is_string($raw) && $raw !== '') {
                $parsed = @openssl_x509_parse($raw);
                if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
                    return gmdate('Y-m-d', (int) $parsed['validTo_time_t']);
                }
            }
        }

        $cmd = 'openssl x509 -enddate -noout -in ' . escapeshellarg($fullchain) . ' 2>/dev/null';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0 || $output === []) {
            return null;
        }
        // notAfter=Oct 15 12:00:00 2026 GMT
        $line = trim($output[0]);
        if (preg_match('/^notAfter=(.+)$/i', $line, $m)) {
            return $this->normalizeDate($m[1]);
        }

        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        // Already YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d', $ts);
    }

    /**
     * Stop renewals for a domain (RSA and/or ECC). Does not delete files on disk.
     *
     * @return array{stdout:string,stderr:string,removed:bool,soft_miss:bool}
     */
    public function removeFromList(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $this->assertSafeDomain($domain);

        $bin = (string) config_get('paths.acme_bin');
        $home = (string) config_get('paths.acme_home');
        if ($bin === '' || !is_file($bin)) {
            throw new RuntimeException('acme.sh binary not found at configured path.');
        }

        $stdout = '';
        $stderr = '';
        $removed = false;
        $softMiss = false;

        foreach ([false, true] as $ecc) {
            $cmd = escapeshellarg($bin)
                . ' --remove -d ' . escapeshellarg($domain)
                . ($ecc ? ' --ecc' : '')
                . ' --home ' . escapeshellarg($home)
                . ' 2>&1';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);
            $text = implode("\n", $output);
            $stdout .= '$ ' . $cmd . "\n" . $text . "\n";
            if ($code === 0) {
                $removed = true;
                continue;
            }
            // Domain absent from renewal list is fine when cleaning typos / leftovers.
            if ($this->isRemoveNotListed($text)) {
                $softMiss = true;
                continue;
            }
            $stderr .= $text . "\n";
            throw new RuntimeException('acme.sh --remove failed for ' . $domain . ($ecc ? ' (ecc)' : '') . ': ' . $text);
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'removed' => $removed,
            'soft_miss' => $softMiss && !$removed,
        ];
    }

    /**
     * Delete leftover cert/conf directories under acme_home for domain and domain_ecc.
     * Paths are constrained to the configured acme home.
     *
     * @return list<string> relative dir names removed
     */
    public function purgeDomainFiles(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $this->assertSafeDomain($domain);

        $home = (string) config_get('paths.acme_home');
        if ($home === '' || !is_dir($home)) {
            throw new RuntimeException('acme.sh home not found at configured path.');
        }
        $homeReal = realpath($home);
        if ($homeReal === false) {
            throw new RuntimeException('Unable to resolve acme.sh home path.');
        }

        $removed = [];
        foreach ([$domain, $domain . '_ecc'] as $dirName) {
            $candidate = $homeReal . DIRECTORY_SEPARATOR . $dirName;
            if (!is_dir($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }
            // Must stay strictly inside acme home (never delete home itself).
            $prefix = $homeReal . DIRECTORY_SEPARATOR;
            if (!str_starts_with($real, $prefix) || $real === $homeReal) {
                throw new RuntimeException('Refusing to purge path outside acme home: ' . $candidate);
            }
            $this->removeTree($real);
            $removed[] = $dirName;
        }

        return $removed;
    }

    private function isRemoveNotListed(string $output): bool
    {
        $lower = strtolower($output);
        return str_contains($lower, 'is not in the list')
            || str_contains($lower, 'is not a issued domain')
            || str_contains($lower, 'is not an issued domain')
            || str_contains($lower, 'no domain specified')
            || str_contains($lower, 'cannot find domain');
    }

    private function assertSafeDomain(string $domain): void
    {
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }
        if (str_contains($domain, '..') || str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            throw new InvalidArgumentException('Invalid domain.');
        }
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            throw new RuntimeException('Unable to read directory: ' . $dir);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                if (!@unlink($path)) {
                    throw new RuntimeException('Unable to delete file: ' . $path);
                }
            }
        }
        if (!@rmdir($dir)) {
            throw new RuntimeException('Unable to delete directory: ' . $dir);
        }
    }
}
