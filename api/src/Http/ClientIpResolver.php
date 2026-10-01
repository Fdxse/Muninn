<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * Determines the real client IP address for rate limiting and audit logging.
 *
 * X-Forwarded-For is attacker-controlled unless it was written by a proxy we trust,
 * so it is only read when the direct peer (REMOTE_ADDR) is a configured trusted proxy.
 * We then walk the list from the right and return the first address that is not
 * itself a trusted proxy.
 */
final class ClientIpResolver
{
    /** @param list<string> $trustedProxyAddresses */
    public function __construct(private readonly array $trustedProxyAddresses)
    {
    }

    public function resolve(Request $request): string
    {
        $directPeerAddress = $request->remoteAddress();
        if (!in_array($directPeerAddress, $this->trustedProxyAddresses, true)) {
            return $directPeerAddress;
        }

        $forwardedForHeader = $request->header('X-Forwarded-For');
        if ($forwardedForHeader === null || trim($forwardedForHeader) === '') {
            return $directPeerAddress;
        }

        $forwardedAddresses = array_reverse(array_map('trim', explode(',', $forwardedForHeader)));
        foreach ($forwardedAddresses as $forwardedAddress) {
            if (filter_var($forwardedAddress, FILTER_VALIDATE_IP) === false) {
                // A malformed entry means we cannot trust anything further left.
                break;
            }
            if (!in_array($forwardedAddress, $this->trustedProxyAddresses, true)) {
                return $forwardedAddress;
            }
        }

        return $directPeerAddress;
    }
}
