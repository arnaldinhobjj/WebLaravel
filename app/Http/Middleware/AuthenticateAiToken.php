<?php

namespace App\Http\Middleware;

use App\Models\AiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an AI use Fort Vale with a person's token (`Authorization: Bearer fv_…`).
 * The AI then acts as that person, with their permissions. The person must
 * still be allowed to connect an AI; revoked or unknown tokens get 401.
 */
class AuthenticateAiToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? AiToken::with('user')->where('token_hash', hash('sha256', $plain))->first() : null;

        if ($token === null || $token->user === null || ! $token->user->hasPermission('ai.connect')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'A valid Fort Vale AI token is needed. Create one on the AI page.'],
            ], 401)->header('WWW-Authenticate', 'Bearer realm="Fort Vale"');
        }

        Auth::setUser($token->user);
        $request->attributes->set('ai_token_id', $token->id);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
