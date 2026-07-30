<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function consume_flash(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function days_until(?string $date): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    try {
        $target = new DateTimeImmutable($date . ' 23:59:59', new DateTimeZone('UTC'));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        return (int) $today->diff($target)->format('%r%a');
    } catch (Throwable) {
        return null;
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'ok' => 'OK',
        'expiring' => 'Expiring',
        'drift' => 'Drift',
        'cpanel-only' => 'cPanel only',
        'acme-only' => 'acme only',
        'job-pending' => 'Job pending',
        'job-running' => 'Job running',
        'job-failed' => 'Job failed',
        default => ucfirst($status),
    };
}

function job_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'running' => 'Running',
        'success' => 'Success',
        'failed' => 'Failed',
        default => ucfirst($status),
    };
}

/** acme.sh --renew exits non-zero when the cert is not due yet. */
function job_is_renew_skip(?string $stdout): bool
{
    if ($stdout === null || $stdout === '') {
        return false;
    }
    return str_contains($stdout, 'Skipping. Next renewal time')
        || str_contains($stdout, "Add '--force' to force renewal");
}

/** Short human line from job stdout for lists. */
function job_summary_line(?string $stdout, ?string $stderr = null): string
{
    $text = trim((string) $stdout);
    if (job_is_renew_skip($text)) {
        if (preg_match('/Skipping\\. Next renewal time is:\\s*(\\S+)/', $text, $m)) {
            return 'Renew skipped — next due ' . $m[1];
        }
        return 'Renew skipped — not due yet';
    }
    $lines = preg_split('/\\R/', $text) ?: [];
    $pick = '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if ($line === '' || str_starts_with($line, '$ ')) {
            continue;
        }
        $pick = $line;
        break;
    }
    if ($pick === '') {
        $err = trim((string) $stderr);
        if ($err !== '') {
            $errLines = preg_split('/\\R/', $err) ?: [];
            $pick = trim((string) ($errLines[0] ?? ''));
        }
    }
    if ($pick === '') {
        return '—';
    }
    if (strlen($pick) > 120) {
        return substr($pick, 0, 117) . '…';
    }
    return $pick;
}

function config_get(string $key, mixed $default = null): mixed
{
    $parts = explode('.', $key);
    $value = $GLOBALS['sslcave_config'] ?? [];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}
