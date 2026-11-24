<?php

use App\Http\Middleware\AuthenticateAiToken;
use App\Mcp\Servers\FortValeServer;
use Laravel\Mcp\Facades\Mcp;

// Fort Vale as an MCP server for people's own AI apps. The token decides who
// the AI acts as; see the AI page (/ai) to create one.
Mcp::web('/mcp/fortvale', FortValeServer::class)->middleware([AuthenticateAiToken::class, 'throttle:ai-mcp']);
