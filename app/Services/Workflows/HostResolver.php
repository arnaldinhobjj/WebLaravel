<?php

namespace App\Services\Workflows;

/**
 * Looks up the IP addresses of a host name. A separate class so tests can
 * replace it instead of using real DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * Whether a host only points at public internet addresses (not this
     * machine, the local network, or cloud metadata services).
     */
    public function isPublic(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $addresses = $this->addresses($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
