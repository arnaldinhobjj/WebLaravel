<?php

namespace App\Services\Web;

use App\Services\Workflows\HostResolver;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a public web page and turns it into plain text for an AI. Only
 * public internet addresses are allowed, after every redirect too, so it
 * can't be used to reach Fort Vale's own network.
 */
class WebPage
{
    public const MAX_TEXT = 40000;

    /** Bytes of a page read at most. */
    public const MAX_DOWNLOAD = 3_000_000;

    private const MAX_REDIRECTS = 3;

    public function __construct(private HostResolver $resolver) {}

    /**
     * @return array{url: string, title: string, text: string, truncated: bool}
     */
    public function read(string $url): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $address = $this->publicAddress($url);

            $tooLarge = false;
            try {
                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; FortVale/1.0)', 'Accept' => 'text/html,text/plain,application/json;q=0.9,*/*;q=0.1'])
                    ->timeout(10)->withoutRedirecting()
                    // Connect to the address that was checked, so DNS can't change in between.
                    ->withOptions(HostResolver::pinnedTo($url, $address) + [
                        // Stop downloading once the page is bigger than we'd read anyway.
                        'progress' => function ($total, int $downloaded) use (&$tooLarge): void {
                            if ($downloaded > self::MAX_DOWNLOAD) {
                                $tooLarge = true;
                                throw new RuntimeException('too large');
                            }
                        },
                    ])
                    ->get($url);
            } catch (ConnectionException|RequestException $exception) {
                // Guzzle wraps the callback's exception a few levels deep, so remember it here instead.
                if ($tooLarge) {
                    throw new RuntimeException("{$url} is larger than ".(self::MAX_DOWNLOAD / 1_000_000).' MB, too big to read.');
                }
                throw new RuntimeException("Couldn't reach {$url}: {$exception->getMessage()}");
            }

            if ($response->redirect() && $response->header('Location') !== '') {
                $url = $this->absolute($response->header('Location'), $url);

                continue;
            }
            if ($response->failed()) {
                throw new RuntimeException("{$url} answered {$response->status()}.");
            }

            $type = strtolower($response->header('Content-Type'));
            if ($type !== '' && ! preg_match('#^(text/|application/(json|xml|xhtml))#', $type)) {
                throw new RuntimeException("{$url} isn't a web page ({$type}), so it can't be read as text.");
            }

            return $this->toText($url, substr($response->body(), 0, self::MAX_DOWNLOAD), str_contains($type, 'html') || $type === '');
        }

        throw new RuntimeException('Too many redirects.');
    }

    private function publicAddress(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException("“{$url}” isn't an http(s) web address.");
        }

        return $this->resolver->publicAddress($host)
            ?? throw new RuntimeException("{$host} isn't a public internet address, so it can't be read.");
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.ltrim($location, '/');
    }

    /**
     * @return array{url: string, title: string, text: string, truncated: bool}
     */
    private function toText(string $url, string $body, bool $html): array
    {
        $title = '';
        if ($html) {
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $match)) {
                $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5));
            }
            $body = (string) preg_replace('#<(script|style|noscript|svg|nav|footer|header|form)\b[^>]*>.*?</\1>#is', ' ', $body);
            $body = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $body);
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5);
        }

        $text = trim((string) preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n\n"], mb_convert_encoding($body, 'UTF-8', 'UTF-8')));

        return [
            'url' => $url,
            'title' => $title,
            'text' => mb_substr($text, 0, self::MAX_TEXT),
            'truncated' => mb_strlen($text) > self::MAX_TEXT,
        ];
    }
}
