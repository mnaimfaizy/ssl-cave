<?php

declare(strict_types=1);

namespace SslCave\Tests;

use Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testTokenCreatesAndReusesSessionValue(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
        $this->assertTrue(Csrf::validate($first));
    }

    public function testValidateAcceptsMatchingToken(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
    }

    public function testValidateRejectsMismatchNullAndEmpty(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate('definitely-not-the-token'));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }
}
