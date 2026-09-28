<?php

declare(strict_types=1);

namespace SslCave\Tests;

use CpanelAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CpanelAdapterTest extends TestCase
{
    public function testDeleteSslTreatsMissingCertificateAsAlreadyClean(): void
    {
        $raw = <<<'TXT'
[2026-09-28 01:51:03 -0400] warn [uapi] Cpanel::Wrap::send_cpwrapd_request adminbin Cpanel/ssl/DEL: exit 5: namespace=[Cpanel] module=[ssl] function=[DEL]: raw_response=[{"statusmsg":"adminbin Cpanel/ssl/DEL: exit 5","mode":"full","version":"2.4","status":1,"timeout":0,"error":1,"exit_code":1280,"data":"No SSL certificate secures the website “php-ecommerce.mnfprofile.com”.\n","action":"run"}]
[2026-09-28 01:51:03 -0400] warn [uapi] Cpanel::Wrap::send_cpwrapd_request error: namespace=[Cpanel] module=[ssl] function=[DEL]: statusmsg=[adminbin Cpanel/ssl/DEL: exit 5]
{"apiversion":3,"module":"SSL","func":"delete_ssl","result":{"warnings":null,"data":null,"errors":["Failed to remove SSL host for “php-ecommerce.mnfprofile.com”: No SSL certificate secures the website “php-ecommerce.mnfprofile.com”.\n"],"metadata":{},"status":0,"messages":["OK"]}}
TXT;

        $message = $this->interpretDeleteSsl('php-ecommerce.mnfprofile.com', $raw);

        $this->assertSame(
            'No SSL host installed for php-ecommerce.mnfprofile.com (already clean).',
            $message
        );
    }

    public function testDeleteSslReadsResultAfterWarnLines(): void
    {
        $raw = <<<'TXT'
[2026-09-28 01:51:03 -0400] warn [uapi] raw_response=[{"status":1,"error":1,"data":"blocked"}]
{"apiversion":3,"module":"SSL","func":"delete_ssl","result":{"warnings":null,"data":null,"errors":["Certificate is locked"],"metadata":{},"status":0,"messages":null}}
TXT;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uapi SSL delete_ssl rejected: Certificate is locked');
        $this->interpretDeleteSsl('example.com', $raw);
    }

    public function testDeleteSslReturnsSuccessMessageFromJsonLine(): void
    {
        $raw = <<<'TXT'
[2026-09-28 01:51:03 -0400] warn [uapi] raw_response=[{"status":1}]
{"apiversion":3,"module":"SSL","func":"delete_ssl","result":{"warnings":null,"data":null,"errors":null,"metadata":{},"status":1,"messages":["The SSL host was successfully removed."]}}
TXT;

        $message = $this->interpretDeleteSsl('example.com', $raw);

        $this->assertSame('The SSL host was successfully removed.', $message);
    }

    private function interpretDeleteSsl(string $domain, string $raw): string
    {
        $method = new ReflectionMethod(CpanelAdapter::class, 'interpretDeleteSsl');
        $result = $method->invoke(new CpanelAdapter(), $domain, $raw);
        $this->assertIsString($result);

        return $result;
    }
}
