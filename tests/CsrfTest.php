<?php

declare(strict_types=1);

namespace SslCave\Tests;

use Csrf;
use SslCave\Tests\Support\DatabaseTestCase;

final class CsrfTest extends DatabaseTestCase
{
    public function testTokenCreatesAndReusesSessionValue(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
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
