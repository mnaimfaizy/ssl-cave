<?php

declare(strict_types=1);

namespace SslCave\Tests\Support;

use Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

abstract class DatabaseTestCase extends TestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabaseConnection();
        $this->ensureSchema();
        $this->truncateTables();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    protected function pdo(): PDO
    {
        return Database::pdo();
    }

    protected function resetDatabaseConnection(): void
    {
        $ref = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $schema = file_get_contents(dirname(__DIR__, 2) . '/schema.sql');
        if ($schema === false) {
            self::fail('Unable to read schema.sql');
        }

        $this->pdo()->exec($schema);
        self::$schemaReady = true;
    }

    protected function truncateTables(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['alert_sent', 'jobs', 'domains', 'login_attempts', 'settings'] as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string,mixed> $overrides */
    protected function withConfig(array $overrides, callable $callback): mixed
    {
        $original = $GLOBALS['sslcave_config'];
        $GLOBALS['sslcave_config'] = array_replace_recursive($original, $overrides);
        try {
            return $callback();
        } finally {
            $GLOBALS['sslcave_config'] = $original;
            $this->resetDatabaseConnection();
        }
    }

    protected function insertDomain(string $domain, string $status = 'ok'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo()->prepare(
            'INSERT INTO domains
                (domain, status, in_cpanel, in_acme, created_at, updated_at)
             VALUES (?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([$domain, $status, $now, $now]);
    }

    /** @return array<string,mixed>|null */
    protected function findDomain(string $domain): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM domains WHERE domain = ? LIMIT 1');
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
