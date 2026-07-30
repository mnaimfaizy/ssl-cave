<?php

declare(strict_types=1);

namespace SslCave\Tests;

use InvalidArgumentException;
use JobQueue;
use RuntimeException;
use SslCave\Tests\Support\DatabaseTestCase;

final class JobQueueTest extends DatabaseTestCase
{
    public function testEnqueueCreatesPendingJobAndReturnsId(): void
    {
        $this->insertDomain('queue.example.com');
        $queue = new JobQueue();

        $id = $queue->enqueue('queue.example.com', 'renew');

        $this->assertGreaterThan(0, $id);
        $job = $queue->find($id);
        $this->assertNotNull($job);
        $this->assertSame('queue.example.com', $job['domain']);
        $this->assertSame('renew', $job['action']);
        $this->assertSame('pending', $job['status']);

        $domain = $this->findDomain('queue.example.com');
        $this->assertNotNull($domain);
        $this->assertSame('job-pending', $domain['status']);
    }

    public function testEnqueueRejectsActionOutsideAllowlist(): void
    {
        $queue = new JobQueue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Action not allowlisted');
        $queue->enqueue('queue.example.com', 'explode');
    }

    public function testEnqueueRejectsInvalidDomain(): void
    {
        $queue = new JobQueue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid domain.');
        $queue->enqueue('not a domain!', 'renew');
    }

    public function testEnqueueRejectsDuplicatePendingJob(): void
    {
        $this->insertDomain('dup.example.com');
        $queue = new JobQueue();
        $queue->enqueue('dup.example.com', 'renew');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already pending or running');
        $queue->enqueue('dup.example.com', 'renew');
    }

    public function testEnqueueRemoveRequiresAtLeastOneCleanupTarget(): void
    {
        $queue = new JobQueue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cleanup requires at least one');
        $queue->enqueue('cleanup.example.com', 'remove', []);
    }

    public function testCancelMarksPendingJobFailed(): void
    {
        $this->insertDomain('cancel.example.com');
        $queue = new JobQueue();
        $id = $queue->enqueue('cancel.example.com', 'deploy');

        $result = $queue->cancel($id, 'Stopped by test.');

        $this->assertSame($id, $result['id']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(130, $result['exit_code']);
        $this->assertSame('cancel.example.com', $result['domain']);
        $this->assertSame('deploy', $result['action']);

        $job = $queue->find($id);
        $this->assertNotNull($job);
        $this->assertSame('failed', $job['status']);
        $this->assertSame(130, (int) $job['exit_code']);
        $this->assertStringContainsString('Stopped by test.', (string) $job['stderr']);

        $domain = $this->findDomain('cancel.example.com');
        $this->assertNotNull($domain);
        $this->assertSame('job-failed', $domain['status']);
    }

    public function testCancelRejectsMissingAndTerminalJobs(): void
    {
        $queue = new JobQueue();

        try {
            $queue->cancel(999999);
            $this->fail('Expected RuntimeException for missing job');
        } catch (RuntimeException $e) {
            $this->assertSame('Job not found.', $e->getMessage());
        }

        $this->insertDomain('done.example.com');
        $id = $queue->enqueue('done.example.com', 'deploy');
        $queue->cancel($id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only pending or running jobs can be cancelled.');
        $queue->cancel($id);
    }

    public function testPendingCountTracksActiveJobs(): void
    {
        $this->insertDomain('count.example.com');
        $queue = new JobQueue();

        $this->assertSame(0, $queue->pendingCount());
        $id = $queue->enqueue('count.example.com', 'renew');
        $this->assertSame(1, $queue->pendingCount());
        $queue->cancel($id);
        $this->assertSame(0, $queue->pendingCount());
    }
}
