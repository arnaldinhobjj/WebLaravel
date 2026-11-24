<?php

namespace App\Mcp\Tools;

use App\Models\AiTokenCall;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * A Fort Vale tool for someone's AI. It acts as the token's owner with their
 * permissions, is only offered when they have them, and every call is logged
 * on the token so the person can see what their AI did.
 */
abstract class FortValeTool extends Tool
{
    /**
     * Every permission the person needs for this tool to be offered.
     *
     * @return list<string>
     */
    abstract protected function permissions(): array;

    abstract protected function perform(User $me, Request $request): Response;

    public function shouldRegister(): bool
    {
        $me = auth()->user();

        return $me instanceof User && collect($this->permissions())->every(fn (string $permission): bool => $me->hasPermission($permission));
    }

    public function handle(Request $request): Response
    {
        $me = auth()->user();
        if (! $me instanceof User || ! $this->shouldRegister()) {
            return $this->logged(Response::error('You are not allowed to use this tool.'), 'not allowed');
        }

        try {
            $response = $this->perform($me, $request);
        } catch (ValidationException $invalid) {
            $response = Response::error(implode(' ', $invalid->validator->errors()->all()));
        } catch (Throwable $failure) {
            report($failure);
            $response = Response::error('Something went wrong in Fort Vale while doing that.');
        }

        return $this->logged($response, $response->isError() ? 'error' : 'ok');
    }

    private function logged(Response $response, string $outcome): Response
    {
        $tokenId = request()->attributes->get('ai_token_id');
        if ($tokenId !== null) {
            AiTokenCall::create([
                'token_id' => $tokenId,
                'tool' => $this->name(),
                'ok' => ! $response->isError(),
                'summary' => Str::limit($outcome === 'ok' ? 'Done' : (string) $response->content(), 290),
            ]);
        }

        return $response;
    }
}
