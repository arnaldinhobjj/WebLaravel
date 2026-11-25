<?php

namespace App\Enums;

enum DashboardWidgetType: string
{
    case TicketSummary = 'ticket_summary';
    case TicketQueue = 'ticket_queue';
    case TicketsByStatus = 'tickets_by_status';
    case TicketsByPriority = 'tickets_by_priority';
    case TicketsByCategory = 'tickets_by_category';
    case ResolvedTrend = 'resolved_trend';
    case RecentActivity = 'recent_activity';
    case Table = 'table';

    public function label(): string
    {
        return match ($this) {
            self::TicketSummary => 'Ticket summary',
            self::TicketQueue => 'Ticket queue',
            self::TicketsByStatus => 'Tickets by status',
            self::TicketsByPriority => 'Open tickets by priority',
            self::TicketsByCategory => 'Open tickets by category',
            self::ResolvedTrend => 'Resolved per day',
            self::RecentActivity => 'Recent activity',
            self::Table => 'Table',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TicketSummary => 'Open, unassigned, urgent and recently resolved counts.',
            self::TicketQueue => 'The most pressing open tickets, highest priority first.',
            self::TicketsByStatus => 'How many tickets are in each status.',
            self::TicketsByPriority => 'Open tickets split by priority.',
            self::TicketsByCategory => 'Open tickets split by category.',
            self::ResolvedTrend => 'Tickets resolved or closed each day for the last 14 days.',
            self::RecentActivity => 'The latest replies and changes on tickets.',
            self::Table => 'Rows from a table you choose, with your own columns and filters.',
        };
    }

    /** Wide widgets take the full row unless resized; the rest sit two to a row. */
    public function isWide(): bool
    {
        return in_array($this, [self::TicketSummary, self::ResolvedTrend, self::Table], true);
    }

    /** Whether the widget shows tickets, and so needs the tickets.view permission and has a scope. */
    public function isAboutTickets(): bool
    {
        return $this !== self::Table;
    }
}
