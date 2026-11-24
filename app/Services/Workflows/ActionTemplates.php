<?php

namespace App\Services\Workflows;

/**
 * Starter YAML offered when someone creates a new personal action.
 */
class ActionTemplates
{
    /** @var array<string, array{label: string, yaml: string}> */
    public const TEMPLATES = [
        'page' => [
            'label' => 'Open a page',
            'yaml' => <<<'YAML'
                name: My open tickets
                description: Open tickets assigned to me
                on:
                  run:
                    show_on_home: true
                steps:
                  - uses: page/open
                    with:
                      url: /ticket?assignee=me
                YAML,
        ],
        'ticket-form' => [
            'label' => 'Start a ticket',
            'yaml' => <<<'YAML'
                name: Report a broken camera
                on:
                  run:
                    show_on_home: true
                    inputs:
                      camera:
                        description: Camera number
                        required: true
                steps:
                  - uses: ticket/open-form
                    with:
                      subject: "Camera ${{ inputs.camera }} is broken"
                      category: cctv
                      priority: high
                YAML,
        ],
        'ticket-event' => [
            'label' => 'React to ticket events',
            'yaml' => <<<'YAML'
                name: Acknowledge urgent tickets
                description: Reply to my urgent tickets as soon as they are opened
                on:
                  ticket:
                    types: [created]
                    if:
                      priority: urgent
                steps:
                  - uses: ticket/reply
                    with:
                      body: "Thanks ${{ actor.name }}, I'm on it (ticket #${{ ticket.id }})."
                YAML,
        ],
        'webhook' => [
            'label' => 'Call a webhook',
            'yaml' => <<<'YAML'
                name: Tell my team chat
                on:
                  ticket:
                    types: [status_changed]
                    if:
                      status: resolved
                steps:
                  - uses: http/request
                    with:
                      url: https://hooks.example.com/your-webhook
                      method: POST
                      body:
                        text: "Ticket #${{ ticket.id }} \"${{ ticket.subject }}\" was resolved."
                YAML,
        ],
        'schedule' => [
            'label' => 'Run on a schedule',
            'yaml' => <<<'YAML'
                name: Weekly printer check
                on:
                  schedule:
                    - cron: "0 9 * * 1"   # Mondays at 09:00
                steps:
                  - uses: ticket/create
                    with:
                      subject: Weekly printer check
                      category: it
                      description: Check paper, toner and jams on every floor.
                YAML,
        ],
    ];
}
