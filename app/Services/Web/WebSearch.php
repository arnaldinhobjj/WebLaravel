<?php

namespace App\Services\Web;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Searches the web for AI skills, with the provider chosen in .env:
 * DuckDuckGo (default, no key), Brave Search, Tavily, or your own SearXNG.
 * Every provider gives back the same shape: title, url and snippet.
 */
class WebSearch
{
    public const PROVIDERS = ['duckduckgo', 'brave', 'tavily', 'searxng'];

    private const USER_AGENT = 'Mozilla/5.0 (compatible; FortVale/1.0)';

    public function provider(): string
    {
        $provider = (string) config('fortvale.web_search.provider', 'duckduckgo');

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'duckduckgo';
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     *
     * @throws RuntimeException when the provider can't be reached or isn't set up
     */
    public function search(string $query, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));

        try {
            $results = match ($this->provider()) {
                'brave' => $this->brave($query, $limit),
                'tavily' => $this->tavily($query, $limit),
                'searxng' => $this->searxng($query),
                default => $this->duckDuckGo($query),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException("Couldn't reach the search service ({$this->provider()}): {$exception->getMessage()}");
        }

        return array_slice(array_values(array_filter($results, fn (array $result): bool => str_starts_with($result['url'], 'http'))), 0, $limit);
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function duckDuckGo(string $query): array
    {
        $response = Http::asForm()->withHeaders(['User-Agent' => self::USER_AGENT])->timeout(10)
            ->post('https://html.duckduckgo.com/html/', ['q' => $query]);
        if ($response->failed()) {
            throw new RuntimeException("DuckDuckGo answered {$response->status()}.");
        }

        $page = new \DOMDocument;
        @$page->loadHTML('<?xml encoding="utf-8"?>'.$response->body());
        $xpath = new \DOMXPath($page);

        $results = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " result ")]') as $node) {
            $link = $xpath->query('.//a[contains(@class, "result__a")]', $node)->item(0);
            if (! $link instanceof \DOMElement) {
                continue;
            }
            $href = $link->getAttribute('href');
            // DuckDuckGo links go through a redirect: the real address is in "uddg".
            parse_str((string) parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href, PHP_URL_QUERY), $params);
            $results[] = [
                'title' => trim($link->textContent),
                'url' => (string) ($params['uddg'] ?? $href),
                'snippet' => trim((string) $xpath->query('.//*[contains(@class, "result__snippet")]', $node)->item(0)?->textContent),
            ];
        }

        return $results;
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function brave(string $query, int $limit): array
    {
        $key = (string) config('fortvale.web_search.brave_key');
        if ($key === '') {
            throw new RuntimeException('Brave Search needs BRAVE_SEARCH_API_KEY in .env.');
        }

        $response = Http::withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])->timeout(10)
            ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $query, 'count' => $limit]);
        if ($response->failed()) {
            throw new RuntimeException("Brave Search answered {$response->status()}.");
        }

        return array_map(fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'snippet' => strip_tags((string) ($item['description'] ?? '')),
        ], (array) $response->json('web.results', []));
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function tavily(string $query, int $limit): array
    {
        $key = (string) config('fortvale.web_search.tavily_key');
        if ($key === '') {
            throw new RuntimeException('Tavily needs TAVILY_API_KEY in .env.');
        }

        $response = Http::timeout(15)->post('https://api.tavily.com/search', ['api_key' => $key, 'query' => $query, 'max_results' => $limit]);
        if ($response->failed()) {
            throw new RuntimeException("Tavily answered {$response->status()}.");
        }

        return array_map(fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'snippet' => (string) ($item['content'] ?? ''),
        ], (array) $response->json('results', []));
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function searxng(string $query): array
    {
        $url = rtrim((string) config('fortvale.web_search.searxng_url'), '/');
        if ($url === '') {
            throw new RuntimeException('SearXNG needs SEARXNG_URL in .env.');
        }

        $response = Http::timeout(10)->get("{$url}/search", ['q' => $query, 'format' => 'json']);
        if ($response->failed()) {
            throw new RuntimeException("SearXNG answered {$response->status()}.");
        }

        return array_map(fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'snippet' => (string) ($item['content'] ?? ''),
        ], (array) $response->json('results', []));
    }
}
