<?php

declare(strict_types=1);

/**
 * Live SSL status via local cPanel UAPI CLI.
 */
final class CpanelAdapter
{
    /** @return list<array{domain:string,expiry:?string}> */
    public function installedHosts(): array
    {
        $raw = $this->runUapi('SSL installed_hosts');
        $json = $this->decodeUapiJson($raw, 'SSL installed_hosts');

        $data = $json['result']['data'] ?? $json['data'] ?? null;
        if (!is_array($data)) {
            // Some uapi wrappers nest differently
            if (isset($json['result']) && is_array($json['result']) && array_is_list($json['result'])) {
                $data = $json['result'];
            } else {
                $data = [];
            }
        }

        $hosts = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $domain = strtolower((string) ($row['servername'] ?? $row['domain'] ?? $row['host'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $expiry = null;
            foreach (['not_after', 'expiredate', 'certificate.not_after', 'cert_valid_not_after'] as $key) {
                if (str_contains($key, '.')) {
                    [$a, $b] = explode('.', $key, 2);
                    $candidate = $row[$a][$b] ?? null;
                } else {
                    $candidate = $row[$key] ?? null;
                }
                $expiry = $this->normalizeExpiry($candidate);
                if ($expiry !== null) {
                    break;
                }
            }

            // certificate object often present
            if ($expiry === null && isset($row['certificate']) && is_array($row['certificate'])) {
                $expiry = $this->normalizeExpiry(
                    $row['certificate']['not_after']
                    ?? $row['certificate']['expiredate']
                    ?? null
                );
            }

            $hosts[] = [
                'domain' => $domain,
                'expiry' => $expiry,
            ];
        }

        return $hosts;
    }

    /**
     * End SSL coverage for a domain (uapi SSL delete_ssl).
     * Does not delete the cert blob from SSL storage (use delete_cert for that).
     * Soft-succeeds when the domain already has no installed SSL (cleanup-friendly).
     */
    public function deleteSsl(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $raw = $this->runUapi('SSL delete_ssl domain=' . $domain);

        if ($this->isDeleteSslAlreadyGone($raw)) {
            return 'No SSL host installed for ' . $domain . ' (already clean).';
        }

        $json = $this->decodeUapiJson($raw, 'SSL delete_ssl');
        $result = is_array($json['result'] ?? null) ? $json['result'] : $json;
        $status = (int) ($result['status'] ?? 0);
        $errors = $result['errors'] ?? null;
        $messages = $result['messages'] ?? null;
        $errText = is_array($errors)
            ? implode('; ', array_map('strval', $errors))
            : (is_string($errors) ? $errors : '');

        if ($status !== 1) {
            if ($this->isDeleteSslAlreadyGone($errText !== '' ? $errText : $raw)) {
                return 'No SSL host installed for ' . $domain . ' (already clean).';
            }
            throw new RuntimeException(
                'uapi SSL delete_ssl rejected: ' . ($errText !== '' ? $errText : $this->snippet($raw))
            );
        }

        if (is_array($messages) && $messages !== []) {
            return implode(' ', array_map('strval', $messages));
        }

        return 'The SSL host was successfully removed.';
    }

    /** Run uapi CLI; merges stderr so callers can inspect full output. */
    private function runUapi(string $args): string
    {
        $uapi = (string) config_get('paths.uapi_bin', 'uapi');
        $cmd = escapeshellarg($uapi) . ' --output=json ' . $args . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = trim(implode("\n", $output));

        if ($code !== 0 && !$this->looksLikeJson($raw) && !$this->isDeleteSslAlreadyGone($raw)) {
            throw new RuntimeException('uapi ' . $args . ' failed (exit ' . $code . '): ' . $this->snippet($raw));
        }

        return $raw;
    }

    /**
     * Parse uapi JSON, tolerating leading/trailing banner/warn lines from 2>&1.
     *
     * @return array<string,mixed>
     */
    private function decodeUapiJson(string $raw, string $context): array
    {
        if ($raw === '') {
            throw new RuntimeException('Empty output from uapi ' . $context . '.');
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        // uapi often prints notices on stderr; with 2>&1 that contaminates pure JSON.
        $extracted = $this->extractJsonObject($raw);
        if ($extracted !== null) {
            $json = json_decode($extracted, true);
            if (is_array($json)) {
                return $json;
            }
        }

        throw new RuntimeException(
            'Invalid JSON from uapi ' . $context . ': ' . $this->snippet($raw)
        );
    }

    private function extractJsonObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($raw, $start, $end - $start + 1);
    }

    private function looksLikeJson(string $raw): bool
    {
        return str_contains($raw, '{') && str_contains($raw, '}');
    }

    private function isDeleteSslAlreadyGone(string $text): bool
    {
        $lower = strtolower($text);
        return str_contains($lower, 'does not have an ssl')
            || str_contains($lower, 'does not have ssl')
            || str_contains($lower, 'no ssl host')
            || str_contains($lower, 'ssl is not installed')
            || str_contains($lower, 'not currently installed')
            || str_contains($lower, 'could not find')
            || str_contains($lower, 'no such domain')
            || str_contains($lower, 'domain does not exist')
            || str_contains($lower, 'is not installed on this');
    }

    private function snippet(string $raw, int $max = 500): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '(empty)';
        }
        if (strlen($raw) <= $max) {
            return $raw;
        }
        return substr($raw, 0, $max - 1) . '…';
    }

    private function normalizeExpiry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;
            // milliseconds vs seconds
            if ($n > 9999999999) {
                $n = (int) floor($n / 1000);
            }
            return gmdate('Y-m-d', $n);
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d', $ts);
    }
}
