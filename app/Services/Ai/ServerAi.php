<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use App\Services\Workflows\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to a person's AI from the server, for work that runs in the
 * background (the `ai/report` action step). The /ai chat itself still talks
 * to the AI from the browser.
 *
 * Uses the same neutral chat shape as public/js/ai-chat.js:
 *   ['role' => 'user'|'assistant'|'tool', 'content' => string,
 *    'calls' => [['id', 'name', 'input']], 'call_id' => …, 'name' => …]
 *
 * The server only calls public internet addresses, plus the hosts listed in
 * fortvale.ai.server_hosts (by default the local Ollama and LM Studio ports),
 * so a person's AI address can't be used to reach other machines or services.
 */
class ServerAi
{
    public const TIMEOUT = 300;

    public function __construct(private HostResolver $resolver) {}

    /**
     * Why the server may not call this AI, or null when it may.
     */
    public function refusal(AiConnection $connection): ?string
    {
        if (! $connection->model) {
            return "{$connection->name} has no model chosen. Pick one under “Your AIs” on the AI page.";
        }

        $parts = parse_url($connection->base_url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return "{$connection->name} doesn't have a web address Fort Vale can call.";
        }

        if ($this->isAllowedPrivateHost($host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80))) {
            return null;
        }

        return $this->resolver->publicAddress($host) === null
            ? "Fort Vale's server may not call {$host}. Ask an administrator to add it to FORTVALE_AI_SERVER_HOSTS."
            : null;
    }

    /**
     * One turn: the AI's text and any skills it asked to use.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     * @return array{text: string, calls: list<array{id: string, name: string, input: array<string, mixed>}>}
     *
     * @throws RuntimeException When the AI can't be reached or answers with an error.
     */
    public function turn(AiConnection $connection, string $system, array $history, array $tools): array
    {
        if (($refusal = $this->refusal($connection)) !== null) {
            throw new RuntimeException($refusal);
        }

        return $connection->provider === 'anthropic'
            ? $this->anthropic($connection, $system, $history, $tools)
            : $this->openai($connection, $system, $history, $tools);
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     * @return array{text: string, calls: list<array{id: string, name: string, input: array<string, mixed>}>}
     */
    private function openai(AiConnection $connection, string $system, array $history, array $tools): array
    {
        $messages = $system !== '' ? [['role' => 'system', 'content' => $system]] : [];
        foreach ($history as $message) {
            $messages[] = match (true) {
                $message['role'] === 'user' => ['role' => 'user', 'content' => $message['content']],
                $message['role'] === 'assistant' => array_filter([
                    'role' => 'assistant',
                    'content' => $message['content'] !== '' ? $message['content'] : null,
                    'tool_calls' => array_map(fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => ['name' => $call['name'], 'arguments' => json_encode((object) $call['input'])],
                    ], $message['calls'] ?? []) ?: null,
                ], fn (mixed $value): bool => $value !== null),
                isset($message['call_id']) => ['role' => 'tool', 'tool_call_id' => $message['call_id'], 'content' => $message['content']],
                default => ['role' => 'user', 'content' => $this->asText($message)],
            };
        }

        $body = ['model' => $connection->model, 'messages' => $messages];
        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $tool): array => [
                'type' => 'function',
                'function' => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['schema']],
            ], $tools);
        }

        $headers = $connection->api_key ? ['Authorization' => 'Bearer '.$connection->api_key] : [];
        $answer = $this->post($connection, '/chat/completions', $headers, $body);
        $message = $answer['choices'][0]['message'] ?? [];

        return [
            'text' => (string) ($message['content'] ?? ''),
            'calls' => array_values(array_map(fn (array $call, int $index): array => [
                'id' => (string) ($call['id'] ?? 'call_'.$index),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => $this->input($call['function']['arguments'] ?? null),
            ], $message['tool_calls'] ?? [], array_keys($message['tool_calls'] ?? []))),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{name: string, description: string, schema: array<string, mixed>}>  $tools
     * @return array{text: string, calls: list<array{id: string, name: string, input: array<string, mixed>}>}
     */
    private function anthropic(AiConnection $connection, string $system, array $history, array $tools): array
    {
        $messages = [];
        $add = function (string $role, array $block) use (&$messages): void {
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                $messages[$last]['content'][] = $block;
            } else {
                $messages[] = ['role' => $role, 'content' => [$block]];
            }
        };

        foreach ($history as $message) {
            if ($message['role'] === 'user') {
                $add('user', ['type' => 'text', 'text' => $message['content']]);
            } elseif ($message['role'] === 'assistant') {
                if ($message['content'] !== '') {
                    $add('assistant', ['type' => 'text', 'text' => $message['content']]);
                }
                foreach ($message['calls'] ?? [] as $call) {
                    $add('assistant', ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => (object) $call['input']]);
                }
            } elseif (isset($message['call_id'])) {
                $add('user', ['type' => 'tool_result', 'tool_use_id' => $message['call_id'], 'content' => $message['content']]);
            } else {
                $add('user', ['type' => 'text', 'text' => $this->asText($message)]);
            }
        }

        $body = ['model' => $connection->model, 'max_tokens' => 4096, 'messages' => $messages];
        if ($system !== '') {
            $body['system'] = $system;
        }
        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $tool): array => ['name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['schema']], $tools);
        }

        $answer = $this->post($connection, '/messages', ['x-api-key' => (string) $connection->api_key, 'anthropic-version' => '2023-06-01'], $body);

        $text = '';
        $calls = [];
        foreach ($answer['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => (array) ($block['input'] ?? [])];
            }
        }

        return ['text' => $text, 'calls' => $calls];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(AiConnection $connection, string $path, array $headers, array $body): array
    {
        $url = rtrim($connection->base_url, '/').$path;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        // Connect to the address that was checked, so DNS can't change in between
        // (a host that looked public in refusal() may not be public any more).
        $allowedPrivate = $this->isAllowedPrivateHost(strtolower(trim($host, '[]')), $port);
        $address = $allowedPrivate ? null : $this->resolver->publicAddress($host);
        if (! $allowedPrivate && $address === null) {
            throw new RuntimeException("Fort Vale's server may not call {$host}.");
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting()
                ->withOptions($address !== null ? HostResolver::pinnedTo($url, $address) : [])
                ->post($url, $body);
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn't reach {$connection->name} at {$connection->base_url}. Is it running?");
        }

        if (! $response->successful()) {
            throw new AiProviderError("{$connection->name} answered {$response->status()}: ".mb_substr($response->body(), 0, 300), $response->status(), $response->body());
        }

        return (array) $response->json();
    }

    private function isAllowedPrivateHost(string $host, int $port): bool
    {
        $allowed = array_map('strtolower', (array) config('fortvale.ai.server_hosts', []));

        return in_array("{$host}:{$port}", $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function asText(array $message): string
    {
        return "(I used the Fort Vale skill {$message['name']}. Result: {$message['content']})";
    }

    /**
     * @return array<string, mixed>
     */
    private function input(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        $decoded = json_decode((string) $arguments, true);

        return is_array($decoded) ? $decoded : [];
    }
}
