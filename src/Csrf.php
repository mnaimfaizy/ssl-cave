<?php

declare(strict_types=1);

final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        $session = $_SESSION[self::KEY] ?? '';
        return is_string($token)
            && is_string($session)
            && $session !== ''
            && hash_equals($session, $token);
    }

    public static function requireValid(): void
    {
        $token = $_POST['_csrf'] ?? null;
        if (!self::validate(is_string($token) ? $token : null)) {
            http_response_code(403);
            echo 'Invalid CSRF token.';
            exit;
        }
    }
}
