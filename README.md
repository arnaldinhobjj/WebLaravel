# Fort Vale

A Laravel 13 app that recreates the Fort Vale home screen sketch (a window with a sidebar that can be hidden, a title bar with settings and account menus, and **Favorites**, **Actions** and **Mentions**) plus a ticketing system at `/ticket`.

## Features

- **Favorites** – module tiles (Quality, Nuclear, Dispatch, CCTV, Health…). Click the star to unfavorite; use the **Add** tile to favorite other modules.
- **Actions** – run an action with the play button; the last run time is shown.
- **Mentions** – mark as done (✓), dismiss (✕), or use the ⋮ menu to expand the message or copy the ticket number. The bell badge shows the open mention count.
- **Search** – `Ctrl/⌘ + K` filters favorites, actions and mentions as you type.
- **Tickets** (`/ticket`) – a real ticketing system stored in the database:
  - list with status tabs (Active, Open, In progress, Waiting, Resolved, Closed, All), filters for priority, category and assignee, sorting, search (including `#37941`) and pagination;
  - create tickets with a subject, category, priority, assignee and description;
  - a ticket page with a timeline of replies and changes, a reply box that can also change the status, and controls for status, priority, assignee and category, plus *Assign to me* and *Mark resolved / Reopen*.
  - Mention ticket numbers on the home screen link to their tickets, and **Request printer support** opens a prefilled new ticket.
- **Settings menu** – light, dark or system theme, show/hide sidebar, keyboard shortcuts, reset the home screen.
- **Profile menu** – your tickets, tickets you opened, new ticket, and *Switch user*. There's no login: you act as one of the team members, and replies and changes are recorded under that person.
- The sidebar toggle (or the `[` key) hides the sidebar completely; the choice is remembered. Other shortcuts: `Ctrl/⌘ + K` search, `N` new ticket.

Tickets, people and their history are stored in the database (SQLite by default) and seeded with sample data. Home screen state (favorites, action runs, handled mentions) is kept in the session; that sample data lives in `app/Services/Workspace.php`. No Node build step is needed — styles and scripts are plain files in `public/css` and `public/js`.

## Getting started

Requires PHP 8.3+ and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Open http://localhost:8000.

## Tests

```bash
php artisan test
```

## Where things are

| Path | What |
| --- | --- |
| `routes/web.php` | Routes |
| `app/Http/Controllers/DashboardController.php` | Home screen, user switching, reset |
| `app/Http/Controllers/TicketController.php` | Ticket list, create, view, update, reply |
| `app/Models/Ticket.php`, `app/Models/TicketActivity.php`, `app/Enums` | Ticket data, change log, statuses and priorities |
| `database/seeders/DatabaseSeeder.php` | Sample people and tickets |
| `resources/views/tickets` | Ticket pages |
| `app/Services/Workspace.php` | Sample data and session-backed state |
| `resources/views/dashboard.blade.php` | Home screen |
| `resources/views/layouts`, `resources/views/partials` | Window, sidebar, title bar and menus |
| `resources/views/components/icon.blade.php` | Inline SVG icons |
| `public/css/fortvale.css`, `public/js/fortvale.js` | Styles and behaviour |
