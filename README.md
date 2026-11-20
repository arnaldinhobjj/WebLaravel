# Fort Vale

A Laravel 13 app that recreates the Fort Vale home screen sketch: a desktop-style window with a collapsible sidebar, a title bar (notifications, settings, account), and three sections — **Favorites**, **Actions** and **Mentions**.

## Features

- **Favorites** – module tiles (Quality, Nuclear, Dispatch, CCTV, Health…). Click the star to unfavorite; use the **Add** tile to favorite other modules.
- **Actions** – run an action with the play button; the last run time is shown.
- **Mentions** – mark as done (✓), dismiss (✕), or use the ⋮ menu to expand the message or copy the ticket number. The bell badge shows the open mention count.
- **Search** – `Ctrl/⌘ + K` filters favorites, actions and mentions as you type.
- Sidebar toggle (remembered), responsive layout, light and dark themes.

State (favorites, action runs, handled mentions) is kept in the session; the sample data lives in `app/Services/Workspace.php`. No Node build step is needed — styles and scripts are plain files in `public/css` and `public/js`.

## Getting started

Requires PHP 8.3+ and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
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
| `app/Http/Controllers/DashboardController.php` | Page and form actions |
| `app/Services/Workspace.php` | Sample data and session-backed state |
| `resources/views/dashboard.blade.php` | Home screen |
| `resources/views/layouts`, `resources/views/partials` | Window, sidebar, title bar |
| `resources/views/components/icon.blade.php` | Inline SVG icons |
| `public/css/fortvale.css`, `public/js/fortvale.js` | Styles and behaviour |
