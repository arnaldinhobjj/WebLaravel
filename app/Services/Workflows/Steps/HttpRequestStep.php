<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\Expressions;
use App\Services\Workflows\HostResolver;
use App\Services\Workflows\Links;
use App\Services\Workflows\RunContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * `http/request`: calls a web address (e.g. a Slack or Teams webhook).
 * Outputs `status` and `body` (first 2 KB).
 *
 * Only public http(s) addresses are allowed, so actions can't reach this
 * server, the local network or cloud metadata services. Redirects are not
 * followed and the timeout is at most 10 seconds.
 */
class HttpRequestStep extends BaseStep
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private HostResolver $resolver) {}

    protected function keys(): array
    {
        return ['url' => true, 'method' => false, 'headers' => false, 'body' => false, 'timeout' => false];
    }

    protected function checks(array $with, string $path): array
    {
        $errors = [];
        $url = $with['url'] ?? null;

        if (is_string($url) && ! Expressions::hasPlaceholder($url) && ! Links::isWebAddress($url)) {
            $errors[] = "{$path}.url: must be a web address starting with https:// or http://.";
        }

        if (isset($with['method']) && ! in_array(strtoupper((string) $with['method']), self::METHODS, true)) {
            $errors[] = "{$path}.method: must be one of ".implode(', ', self::METHODS).'.';
        }

        if (isset($with['headers']) && (! is_array($with['headers']) || array_is_list($with['headers']))) {
            $errors[] = "{$path}.headers: must be a list of `Name: value` pairs.";
        }

        if (isset($with['timeout']) && (! is_int($with['timeout']) || $with['timeout'] < 1 || $with['timeout'] > 10)) {
            $errors[] = "{$path}.timeout: must be a whole number of seconds from 1 to 10.";
        }

        return $errors;
    }

    public function run(array $with, RunContext $context): array
    {
        $url = (string) $with['url'];

        if (! Links::isWebAddress($url)) {
            throw new StepFailed("\"{$url}\" isn't an http(s) web address.");
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $address = config('fortvale.actions.allow_private_hosts') ? null : $this->resolver->publicAddress($host);
        if (! config('fortvale.actions.allow_private_hosts') && $address === null) {
            throw new StepFailed("{$host} is not a public internet address, so actions may not call it.");
        }

        $body = $with['body'] ?? null;
        $method = strtoupper((string) ($with['method'] ?? ($body !== null ? 'POST' : 'GET')));
        $headers = array_map('strval', $with['headers'] ?? []);
        $contentType = collect($headers)->first(fn (string $value, string $name): bool => strtolower($name) === 'content-type') ?? 'text/plain';

        $request = Http::withHeaders($headers)
            ->timeout((int) ($with['timeout'] ?? 10))
            ->withoutRedirecting()
            // Connect to the address that was checked, so DNS can't change in between.
            ->withOptions($address !== null ? HostResolver::pinnedTo($url, $address) : []);

        try {
            $response = match (true) {
                is_array($body) => $request->send($method, $url, ['json' => $body]),
                $body !== null => $request->withBody((string) $body, $contentType)->send($method, $url),
                default => $request->send($method, $url),
            };
        } catch (ConnectionException $exception) {
            throw new StepFailed("Couldn't reach {$host}: {$exception->getMessage()}");
        }

        $output = ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)];

        if ($response->failed() || $response->redirect()) {
            throw new StepFailed("{$host} answered {$response->status()}.");
        }

        return $output;
    }
}
