<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateTicket;
use App\Mcp\Tools\GetTicket;
use App\Mcp\Tools\ListActions;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\ReplyToTicket;
use App\Mcp\Tools\RunAction;
use App\Mcp\Tools\SearchFiles;
use App\Mcp\Tools\SearchTickets;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Fort Vale for someone's own AI. The token used to connect decides who the
 * AI acts as; it can do exactly what that person can, never more.
 */
#[Name('Fort Vale')]
#[Version('1.0.0')]
#[Instructions('Fort Vale is an operations workspace with tickets, files and actions. You act as the person whose token you use, with their permissions. Look things up before acting, and confirm with the person before creating tickets, replying, or running actions.')]
class FortValeServer extends Server
{
    protected array $tools = [
        SearchTickets::class,
        GetTicket::class,
        CreateTicket::class,
        ReplyToTicket::class,
        SearchFiles::class,
        ReadFile::class,
        ListActions::class,
        RunAction::class,
    ];
}
