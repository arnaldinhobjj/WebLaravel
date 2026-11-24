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
     * Ranges that aren't the public internet but that PHP's private/reserved
     * filter doesn't catch (or that wrap an IPv4 address inside IPv6).
     */
    private const NOT_PUBLIC = [
        '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '2001::/32',
    ];

    /**
     * Whether a host only points at public internet addresses (not this
     * machine, the local network, or cloud metadata services).
     */
    public function isPublic(string $host): bool
    {
        return $this->publicAddress($host) !== null;
    }

    /**
     * The address to connect to, if every address the host has is public.
     * Callers should connect to exactly this address (see pinnedTo()), so the
     * answer can't change between the check and the request.
     */
    public function publicAddress(string $host): ?string
    {
        $host = trim($host, '[]');

        if ($host === '' || strtolower($host) === 'localhost') {
            return null;
        }

        $addresses = $this->addresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * Guzzle options that make the request use the checked address for the host.
     *
     * @return array<string, mixed>
     */
    public static function pinnedTo(string $url, string $address): array
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $ip = str_contains($address, ':') ? "[{$address}]" : $address;

        return ['curl' => [CURLOPT_RESOLVE => [trim($parts['host'] ?? '', '[]').":{$port}:{$ip}"]]];
    }

    public static function isPublicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $binary = (string) inet_pton($address);

        // IPv6 that carries an IPv4 address: judge the IPv4 address inside.
        if (strlen($binary) === 16) {
            $embedded = match (true) {
                str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") => substr($binary, 12, 4),     // ::ffff:a.b.c.d
                str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($binary, 12, 4), // 64:ff9b::/96 NAT64
                str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),                           // 2002::/16 6to4
                default => null,
            };
            if ($embedded !== null) {
                return self::isPublicAddress((string) inet_ntop($embedded));
            }
            if (str_starts_with($binary, "\x00\x64\xff\x9b")) {
                return false; // other NAT64 forms
            }
        }

        foreach (self::NOT_PUBLIC as $range) {
            if (self::inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $start = (string) inet_pton($network);
        if (strlen($start) !== strlen($binary)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($binary, 0, $bytes) !== substr($start, 0, $bytes)) {
            return false;
        }

        return $rest === 0 || ((ord($binary[$bytes]) ^ ord($start[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }
}
