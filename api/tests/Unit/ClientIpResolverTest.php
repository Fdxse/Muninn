<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Http\ClientIpResolver;
use Muninn\Api\Http\Request;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase
{
    public function testForwardedHeaderIsIgnoredFromUntrustedPeer(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $spoofingRequest = new Request('GET', '/', ['X-Forwarded-For' => '1.2.3.4'], [], '', '198.51.100.7');

        self::assertSame('198.51.100.7', $resolver->resolve($spoofingRequest));
    }

    public function testRightmostUntrustedAddressIsUsedBehindTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        // The client can prepend anything; only the entry our proxy appended is trustworthy.
        $proxiedRequest = new Request('GET', '/', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.7'], [], '', '10.0.0.1');

        self::assertSame('198.51.100.7', $resolver->resolve($proxiedRequest));
    }
}
