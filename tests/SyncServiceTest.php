<?php

declare(strict_types=1);

namespace SslCave\Tests;

use SslCave\Tests\Support\DatabaseTestCase;
use SyncService;

final class SyncServiceTest extends DatabaseTestCase
{
    public function testComputeStatusReturnsCpanelOnlyWhenAbsentFromAcme(): void
    {
        $status = (new SyncService())->computeStatus([
            'domain' => 'only-cpanel.example.com',
            'cpanel_expiry' => '2027-01-01',
            'acme_expiry' => null,
            'deploy_hook' => 0,
            'in_cpanel' => 1,
            'in_acme' => 0,
        ]);

        $this->assertSame('cpanel-only', $status);
    }

    public function testComputeStatusReturnsAcmeOnlyWhenAbsentFromCpanel(): void
    {
        $status = (new SyncService())->computeStatus([
            'domain' => 'only-acme.example.com',
            'cpanel_expiry' => null,
            'acme_expiry' => '2027-01-01',
            'deploy_hook' => 1,
            'in_cpanel' => 0,
            'in_acme' => 1,
        ]);

        $this->assertSame('acme-only', $status);
    }

    public function testComputeStatusReturnsDriftWhenExpiriesDiffer(): void
    {
        $status = (new SyncService())->computeStatus([
            'domain' => 'drift.example.com',
            'cpanel_expiry' => '2027-01-01',
            'acme_expiry' => '2027-02-01',
            'deploy_hook' => 1,
            'in_cpanel' => 1,
            'in_acme' => 1,
        ]);

        $this->assertSame('drift', $status);
    }

    public function testComputeStatusReturnsDriftWhenDeployHookMissing(): void
    {
        $status = (new SyncService())->computeStatus([
            'domain' => 'no-hook.example.com',
            'cpanel_expiry' => '2027-01-01',
            'acme_expiry' => '2027-01-01',
            'deploy_hook' => 0,
            'in_cpanel' => 1,
            'in_acme' => 1,
        ]);

        $this->assertSame('drift', $status);
    }

    public function testComputeStatusReturnsExpiringWithinThirtyDays(): void
    {
        $expiry = gmdate('Y-m-d', time() + (14 * 86400));
        $status = (new SyncService())->computeStatus([
            'domain' => 'soon.example.com',
            'cpanel_expiry' => $expiry,
            'acme_expiry' => $expiry,
            'deploy_hook' => 1,
            'in_cpanel' => 1,
            'in_acme' => 1,
        ]);

        $this->assertSame('expiring', $status);
    }

    public function testComputeStatusReturnsOkWhenHealthy(): void
    {
        $expiry = gmdate('Y-m-d', time() + (90 * 86400));
        $status = (new SyncService())->computeStatus([
            'domain' => 'healthy.example.com',
            'cpanel_expiry' => $expiry,
            'acme_expiry' => $expiry,
            'deploy_hook' => 1,
            'in_cpanel' => 1,
            'in_acme' => 1,
        ]);

        $this->assertSame('ok', $status);
    }

    public function testSyncAgainstFixturesMergesInventoryAndReturnsShape(): void
    {
        if (!$this->fixturesRunnable()) {
            $this->markTestSkipped('Fixture acme/uapi stubs require a POSIX shell (CI/Linux or Docker).');
        }

        $result = (new SyncService())->sync();

        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['domains']);
        $this->assertSame(0, $result['pruned']);
        $this->assertArrayHasKey('domains', $result);
        $this->assertArrayHasKey('pruned', $result);
        $this->assertArrayHasKey('errors', $result);

        $fixture = $this->findDomain('fixture.example.com');
        $example = $this->findDomain('example.com');
        $typo = $this->findDomain('typo.example.com');

        $this->assertNotNull($fixture);
        $this->assertSame('1', (string) $fixture['in_cpanel']);
        $this->assertSame('1', (string) $fixture['in_acme']);
        $this->assertContains($fixture['status'], ['ok', 'expiring']);

        $this->assertNotNull($example);
        $this->assertSame('1', (string) $example['in_cpanel']);
        $this->assertSame('1', (string) $example['in_acme']);
        $this->assertContains($example['status'], ['ok', 'expiring']);

        $this->assertNotNull($typo);
        $this->assertSame('0', (string) $typo['in_cpanel']);
        $this->assertSame('1', (string) $typo['in_acme']);
        $this->assertSame('acme-only', $typo['status']);
    }

    public function testSyncPrunesMissingDomainsOnlyWhenBothSourcesSucceed(): void
    {
        if (!$this->fixturesRunnable()) {
            $this->markTestSkipped('Fixture acme/uapi stubs require a POSIX shell (CI/Linux or Docker).');
        }

        $this->insertDomain('gone.example.com', 'ok');

        $result = (new SyncService())->sync();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['pruned']);
        $this->assertNull($this->findDomain('gone.example.com'));
    }

    public function testSyncSkipsPruneWhenAcmeSourceFails(): void
    {
        $this->insertDomain('keep-on-acme-fail.example.com', 'ok');

        $result = $this->withConfig([
            'paths' => [
                'acme_bin' => dirname(__DIR__) . '/docker/fixtures/bin/missing-acme.sh',
            ],
        ], static fn () => (new SyncService())->sync());

        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('acme:', $result['errors'][0]);
        $this->assertSame(0, $result['pruned']);
        $this->assertNotNull($this->findDomain('keep-on-acme-fail.example.com'));
    }

    public function testSyncSkipsPruneWhenCpanelSourceFails(): void
    {
        $this->insertDomain('keep-on-cpanel-fail.example.com', 'ok');

        $result = $this->withConfig([
            'paths' => [
                'uapi_bin' => dirname(__DIR__) . '/docker/fixtures/bin/missing-uapi',
            ],
        ], static fn () => (new SyncService())->sync());

        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('cpanel:', $result['errors'][0]);
        $this->assertSame(0, $result['pruned']);
        $this->assertNotNull($this->findDomain('keep-on-cpanel-fail.example.com'));
    }

    public function testSyncKeepsDomainWithPendingJobDuringPrune(): void
    {
        if (!$this->fixturesRunnable()) {
            $this->markTestSkipped('Fixture acme/uapi stubs require a POSIX shell (CI/Linux or Docker).');
        }

        $this->insertDomain('pending-job.example.com', 'ok');
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo()->prepare(
            "INSERT INTO jobs (domain, action, status, created_at) VALUES (?, 'renew', 'pending', ?)"
        );
        $stmt->execute(['pending-job.example.com', $now]);

        $result = (new SyncService())->sync();

        $this->assertSame(0, $result['pruned']);
        $this->assertNotNull($this->findDomain('pending-job.example.com'));
    }

    private function fixturesRunnable(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $acme = (string) ($GLOBALS['sslcave_config']['paths']['acme_bin'] ?? '');
        $uapi = (string) ($GLOBALS['sslcave_config']['paths']['uapi_bin'] ?? '');

        return is_file($acme) && is_file($uapi);
    }
}
