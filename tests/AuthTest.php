<?php

declare(strict_types=1);

namespace SslCave\Tests;

use Auth;
use SslCave\Tests\Support\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    public function testAttemptSucceedsWithValidCredentialsAndSetsSessionUser(): void
    {
        $ok = Auth::attempt('admin', 'test-password');

        $this->assertTrue($ok);
        $this->assertTrue(Auth::check());
        $this->assertSame('admin', Auth::user());
    }

    public function testAttemptRejectsInvalidPassword(): void
    {
        $ok = Auth::attempt('admin', 'wrong-password');

        $this->assertFalse($ok);
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }

    public function testAttemptRejectsInvalidUsername(): void
    {
        $ok = Auth::attempt('other', 'test-password');

        $this->assertFalse($ok);
        $this->assertFalse(Auth::check());
    }

    public function testLockoutTriggersAfterMaxFailedAttempts(): void
    {
        $ip = '203.0.113.10';

        $this->assertFalse(Auth::isLockedOut($ip));

        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailedAttempt($ip);
        }

        $this->assertTrue(Auth::isLockedOut($ip));
    }

    public function testClearAttemptsRemovesLockout(): void
    {
        $ip = '203.0.113.11';
        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailedAttempt($ip);
        }
        $this->assertTrue(Auth::isLockedOut($ip));

        Auth::clearAttempts($ip);

        $this->assertFalse(Auth::isLockedOut($ip));
    }

    public function testLogoutClearsSessionUser(): void
    {
        Auth::attempt('admin', 'test-password');
        $this->assertTrue(Auth::check());

        Auth::logout();

        // logout destroys the session; restart for assertions in this process
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::user());
    }
}
