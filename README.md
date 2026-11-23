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
- **Favorites and Actions carousels** – page back and forth with the arrow buttons (or the arrow keys when the row has focus).
- **Profile menu** (the avatar) – your tickets, tickets you opened, new ticket, *My actions*, *Settings*, show/hide sidebar, and *Switch user*. There's no login: you act as one of the team members, and replies and changes are recorded under that person.
- **Settings** (`/{username}/settings`) – name, username, email, job title, light/dark/system theme (saved per person), reset the home screen, and the keyboard shortcuts.
- **My actions** (`/{username}/actions`) – create, edit, run and delete your own actions. An action either opens a link (a Fort Vale page such as `/ticket?priority=urgent`, or a website) or starts a ticket from a template. Actions marked *Show on the home screen* appear first in the home screen's Actions carousel.
- Personal pages only open for the person you are acting as; someone else's `/{username}/…` page returns 403.
- The sidebar toggle (or the `[` key) hides the sidebar completely; the choice is remembered. Other shortcuts: `Ctrl/⌘ + K` search, `N` new ticket.

Home screen state (favorites, action runs, handled mentions) is kept in the session; that sample data lives in `app/Services/Workspace.php`. No Node build step is needed — styles and scripts are plain files in `public/css` and `public/js`.

## Databases: MySQL and Oracle at the same time

Fort Vale uses two databases at once, both through Laravel's PDO-based connections:

| Data | Tables | Connection | Setting |
| --- | --- | --- | --- |
| People and app | `users`, `actions`, `sessions`, `cache`, `jobs`, … | MySQL | `DB_CONNECTION` |
| Tickets | `tickets`, `ticket_activities` | Oracle | `TICKETS_DB_CONNECTION` |

- Each group can point at any connection in `config/database.php` (`mysql`, `oracle`, `sqlite`, `pgsql`, …). The mapping lives in `config/fortvale.php`.
- Pages combine both: a ticket comes from Oracle and its requester, assignee and reply authors from MySQL.
- You can also query either database directly: `DB::connection('mysql')`, `DB::connection('oracle')`, or `->getPdo()` for the raw PDO object.
- Links between tickets and people can't be foreign keys when they live on different servers. They become indexed columns, and the app validates them (an assignee must exist in the people database). When both groups share one connection, real foreign keys are created.
- Oracle support comes from [`yajra/laravel-oci8`](https://github.com/yajra/laravel-oci8). It needs PHP's **`oci8` extension** and **Oracle Instant Client**.
- New tickets are numbered from 38000 on MySQL and Oracle, after the seeded sample tickets.

## Getting started

Requires PHP 8.3+, Composer, Docker, and the PHP `oci8` extension (see below).

1. Start MySQL and Oracle (Oracle takes a minute or two the first time):

   ```bash
   docker compose up -d
   ```

2. Install, configure and migrate. `migrate --seed` creates the tables in **both** databases:

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve
   ```

3. Open http://localhost:8000.

`docker compose` also creates the test databases: `fortvale_test` in MySQL and the `FORTVALE_TEST` schema in Oracle. Tests never touch your development data.

### Installing the `oci8` extension

- **macOS:**
  - Install Instant Client: `brew tap InstantClientTap/instantclient && brew install instantclient-basic instantclient-sdk`.
  - Then `pecl install oci8` and enter `instantclient,$(brew --prefix)/lib` when asked.
- **Ubuntu/Debian:**
  - Download *Basic* and *SDK* from [Oracle Instant Client](https://www.oracle.com/database/technologies/instant-client/downloads.html) and unzip them to `/opt/oracle`.
  - Run `sudo apt install php-dev libaio1t64`.
  - Run `echo /opt/oracle/instantclient_* | sudo tee /etc/ld.so.conf.d/oracle.conf && sudo ldconfig`.
  - Run `pecl install oci8` and enter `instantclient,/opt/oracle/instantclient_<version>`.
- **Windows:**
  - Unzip Instant Client and add its folder to `PATH`.
  - Enable `extension=oci8_19` (or the build matching your PHP) in `php.ini`.

Check with `php -m | grep oci8`. Without the extension, `composer install` stops on the `ext-oci8` requirement. To try the app without Oracle, install with `--ignore-platform-req=ext-oci8` and use the SQLite setup below.

### Without database servers (SQLite)

Point both groups at SQLite in `.env`:

```dotenv
DB_CONNECTION=sqlite
TICKETS_DB_CONNECTION=sqlite
```

Then remove the `DB_DATABASE` line (or set it to the full path of a `.sqlite` file), run `touch database/database.sqlite`, and run `php artisan migrate --seed`.

### Upgrading an existing install

If you used the app before Oracle support, add the `DB_*` and `TICKETS_DB_CONNECTION` settings from `.env.example` to your `.env`, then run `php artisan migrate`.

- To stay on a single SQLite database, set `TICKETS_DB_CONNECTION=sqlite`. Your data stays where it is.
- A small migration renames the `from`/`to` columns of `ticket_activities`, which are reserved words in Oracle.
- Moving existing tickets into Oracle isn't automatic; start fresh with `php artisan migrate:fresh --seed`, which wipes and refills both databases.

## Tests

The suite uses both databases, like the app. Start them with `docker compose up -d`, then run:

```bash
php artisan test
```

- Connection settings for tests are in `phpunit.xml`. Values set in your shell environment win, so you can run against other setups. For example, everything in SQLite:

  ```bash
  DB_CONNECTION=sqlite DB_DATABASE=:memory: TICKETS_DB_CONNECTION=sqlite php artisan test
  ```

- GitHub Actions runs the suite on PHP 8.3, 8.4 and 8.5 against real MySQL 8.4 and Oracle Free 23ai services, plus once on SQLite.

## Where things are

| Path | What |
| --- | --- |
| `routes/web.php` | Routes |
| `app/Http/Controllers/DashboardController.php` | Home screen, user switching, reset |
| `app/Http/Controllers/TicketController.php` | Ticket list, create, view, update, reply |
| `app/Http/Controllers/SettingsController.php`, `ActionController.php`, `ActionRunController.php` | Settings page and personal actions |
| `app/Http/Middleware/EnsureCurrentUser.php` | Keeps `/{username}/…` pages to their owner |
| `app/Models/Ticket.php`, `app/Models/TicketActivity.php`, `app/Models/Action.php`, `app/Enums` | Tickets, change log, personal actions, statuses and priorities |
| `config/fortvale.php`, `config/database.php` | Which database each data group uses; the MySQL and Oracle connections |
| `compose.yaml`, `docker/` | Local MySQL and Oracle, plus their test database/schema |
| `database/seeders/DatabaseSeeder.php` | Sample people and tickets |
| `resources/views/tickets`, `resources/views/settings`, `resources/views/actions` | Ticket, settings and actions pages |
| `app/Services/Workspace.php` | Sample data and session-backed state |
| `resources/views/dashboard.blade.php` | Home screen |
| `resources/views/layouts`, `resources/views/partials` | Window, sidebar, title bar and menus |
| `resources/views/components/icon.blade.php` | Inline SVG icons |
| `public/css/fortvale.css`, `public/js/fortvale.js` | Styles and behaviour |
