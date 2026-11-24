<?php

use App\Http\Controllers\SkillApiController;
use App\Http\Middleware\AuthenticateAiToken;
use App\Mcp\Servers\FortValeServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Fort Vale for people's own AI apps. The token (from the AI page, /ai)
// decides who the AI acts as. Every skill comes from App\Ai\Skills.

// MCP apps (Claude Desktop/Code, Cursor, LM Studio, Open WebUI, Jan…).
Mcp::web('/mcp/fortvale', FortValeServer::class)->middleware([AuthenticateAiToken::class, 'throttle:ai-mcp']);

// Everything else: OpenAI-style tools, OpenAPI (e.g. ChatGPT actions) and plain HTTP.
Route::prefix('api/ai')->middleware([AuthenticateAiToken::class, 'throttle:ai-mcp'])->controller(SkillApiController::class)->group(function () {
    Route::get('/skills', 'index')->name('ai.api.skills');
    Route::get('/openapi.json', 'openapi')->name('ai.api.openapi');
    Route::post('/skills/{name}', 'run')->where('name', '[A-Za-z0-9_.-]+')->name('ai.api.run');
    Route::get('/recipes/{name}', 'recipe')->where('name', '[A-Za-z0-9_.-]+')->name('ai.api.recipe');
});
