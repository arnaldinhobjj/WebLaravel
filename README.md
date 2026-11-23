# Fort Vale

A Laravel 13 app that recreates the Fort Vale home screen sketch (a window with a sidebar that can be hidden, a title bar with settings and account menus, and **Favorites**, **Actions** and **Mentions**) plus a ticketing system at `/ticket`.

## Features

- **Favorites** – module tiles (Quality, Nuclear, Dispatch, CCTV, Health…). Click the star to unfavorite; use the **Add** tile to favorite other modules.
- **Actions** – YAML actions, GitHub Actions style (see [Actions (YAML)](#actions-yaml)). Run-button actions marked `show_on_home` appear in the home screen's Actions row.
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
- **Actions page** (`/{username}/actions`) – shared actions from the project, your own YAML actions (write them in the browser, starting from a template), and recent runs with each step's result.
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

You need:

- **PHP 8.3–8.5** with the `oci8`, `pdo_mysql` and `pdo_sqlite` extensions, plus **Composer**.
- **Oracle Instant Client** (Basic + SDK), which `oci8` is built against.
- **Docker** to run MySQL and Oracle: Docker Desktop, or [Colima](https://github.com/abiosoft/colima) on macOS.

The steps below install all of this on macOS. Other systems are covered under [Installing `oci8` elsewhere](#installing-oci8-elsewhere).

### 1. Install PHP, Docker and the Oracle client (macOS, Apple Silicon)

1. Install PHP 8.4, the Docker tools and Colima with Homebrew:

   ```bash
   brew install php@8.4 colima docker docker-compose docker-credential-helper
   ```

   - **PHP:** `php@8.4` is installed next to any other PHP you have. Run this project with its full path, `/opt/homebrew/opt/php@8.4/bin/php`. The PHP from Laravel's `php.new` / Herd Lite installer can't load extensions, so it can't run this app.
   - **`docker-credential-helper`:** needed if `~/.docker/config.json` contains `"credsStore": "osxkeychain"`; otherwise image downloads fail.

2. Start Colima. Oracle needs about 2 GB of memory, so give the VM 6 GB:

   ```bash
   colima start --cpu 4 --memory 6 --disk 40
   ```

3. Install Oracle Instant Client:
   - Download **Basic** and **SDK** for *macOS ARM64* from [Oracle Instant Client](https://www.oracle.com/database/technologies/instant-client/macos-arm64-downloads.html).
   - Open both disk images and copy their contents into one folder, for example `~/Oracle/instantclient_23_3` (the SDK's `sdk` folder goes inside it).

4. Build the `oci8` extension, entering `instantclient,` followed by that folder's full path when asked:

   ```bash
   /opt/homebrew/opt/php@8.4/bin/pecl install oci8
   ```

   Check it with `/opt/homebrew/opt/php@8.4/bin/php -m | grep oci8`.

### 2. Start the databases

From the project folder:

```bash
docker-compose up -d
```

- Starts **MySQL 8.4** on port 3306 and **Oracle Free 23ai** on port 1521 (service `FREEPDB1`).
- Oracle takes a minute or two the first time; `docker-compose ps` shows both as `healthy` when they're ready.
- Also creates the test databases: `fortvale_test` in MySQL and the `FORTVALE_TEST` schema in Oracle. Tests never touch your development data.
- `docker compose up -d` (with a space) works too, if your Docker has the Compose plugin.

### 3. Install and initialize the app

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

- On macOS with Homebrew PHP, use the full path wherever these commands say `php`, and run Composer through it too:
  `/opt/homebrew/opt/php@8.4/bin/php "$(command -v composer)" install`.
- `.env.example` already matches the credentials in `compose.yaml`.
- `migrate --seed` creates the tables in **both** databases and loads the sample data: 6 people and 3 personal actions in MySQL, 8 tickets with their history in Oracle.

Then open http://127.0.0.1:8000.

## Working with it

### Starting and stopping

```bash
colima start                 # macOS only, after a restart
docker-compose up -d         # MySQL and Oracle
php artisan serve            # the app, at http://127.0.0.1:8000
php artisan schedule:work    # only needed for actions with `on: schedule`
```

- Stop the app with `Ctrl+C`.
- Stop the databases with `docker-compose stop`. Your data is kept in Docker volumes.
- `docker-compose down -v` deletes the databases completely; run `php artisan migrate --seed` afterwards to recreate them.

### Using the app

- There's no login. You act as a team member, **Alex Morgan** to start with. Switch person from the avatar menu (top right); replies, changes and personal pages follow the selected person.
- Personal pages live at `/{username}/settings` and `/{username}/actions` (for example `/alex.morgan/settings`).
- Tickets are at `/ticket`; new tickets are numbered from 38000.

### Resetting the data

```bash
php artisan migrate:fresh --seed
```

- Wipes and refills **both** databases with the sample data.
- In production it asks for confirmation first and leaves everything untouched if you say no.

### Choosing which database holds what

- Set the two connections in `.env`: `DB_CONNECTION` for people and app data, and `TICKETS_DB_CONNECTION` for tickets.
- Any connection in `config/database.php` works, for example `TICKETS_DB_CONNECTION=mysql` to keep everything in MySQL.
- After changing them, run `php artisan migrate:fresh --seed`. Data isn't moved between databases.

### Looking at the databases directly

```bash
docker-compose exec mysql mysql -ufortvale -pfortvale fortvale
docker-compose exec oracle sqlplus fortvale/fortvale@//localhost/FREEPDB1
```

From PHP, use `php artisan tinker` with `DB::connection('mysql')` or `DB::connection('oracle')`.

### Code changes

- Run the formatter after editing PHP files: `vendor/bin/pint --dirty`.
- Styles and scripts are plain files in `public/css` and `public/js`; there's no build step.

### Troubleshooting

| Problem | Fix |
| --- | --- |
| `composer install` stops on `ext-oci8` | The PHP running Composer has no `oci8`. Run Composer with the PHP that has it (see step 3). |
| `ORA-12514` / `ORA-12541` when migrating | Oracle is still starting. Wait until `docker-compose ps` shows it as `healthy`. |
| `error getting credentials … docker-credential-osxkeychain` | `brew install docker-credential-helper` |
| `Cannot connect to the Docker daemon` | Start Colima (`colima start`) or Docker Desktop. |
| `docker: unknown command: docker compose` | Use `docker-compose` instead. |

### Installing `oci8` elsewhere

- **macOS with Intel or another Homebrew PHP:** same as above; download the *macOS x86* Instant Client on Intel Macs and use that PHP's `pecl`.
- **Ubuntu/Debian:**
  - Download *Basic* and *SDK* from [Oracle Instant Client](https://www.oracle.com/database/technologies/instant-client/downloads.html) and unzip them to `/opt/oracle`.
  - Run `sudo apt install php-dev libaio1t64`.
  - Run `echo /opt/oracle/instantclient_* | sudo tee /etc/ld.so.conf.d/oracle.conf && sudo ldconfig`.
  - Run `pecl install oci8` and enter `instantclient,/opt/oracle/instantclient_<version>`.
- **Windows:**
  - Unzip Instant Client and add its folder to `PATH`.
  - Enable `extension=oci8_19` (or the build matching your PHP) in `php.ini`.

Without the extension, `composer install` stops on the `ext-oci8` requirement. To try the app without Oracle, install with `--ignore-platform-req=ext-oci8` and use the SQLite setup below.

### Without database servers (SQLite)

Point both groups at SQLite in `.env`:

```dotenv
DB_CONNECTION=sqlite
TICKETS_DB_CONNECTION=sqlite
```

Then:

1. Remove the `DB_DATABASE` line, or set it to the full path of a `.sqlite` file.
2. Run `touch database/database.sqlite`.
3. Run `php artisan migrate --seed`.

### Upgrading an existing install

If you used the app before Oracle support, add the `DB_*` and `TICKETS_DB_CONNECTION` settings from `.env.example` to your `.env`, then run `php artisan migrate`.

- To stay on a single SQLite database, set `TICKETS_DB_CONNECTION=sqlite`. Your data stays where it is.
- A small migration renames the `from`/`to` columns of `ticket_activities`, which are reserved words in Oracle.
- Moving existing tickets into Oracle isn't automatic; start fresh with `php artisan migrate:fresh --seed`.

## Actions (YAML)

Actions work like GitHub Actions: each one is a YAML document with a `name`, what starts it (`on:`) and the `steps` it runs.

- **Shared actions** are files in the [`actions/`](actions/) folder, one `.yml` per action, like `.github/workflows`. Everyone can run them.
  - Edit them in the repository, or in the browser: click the action, change the YAML and save. Saving checks the YAML first, writes the file, and refuses to overwrite a file someone else changed since you opened it.
  - Changes made in the browser show up as changed files in git; commit them like any other change.
- **Personal actions** are written in the browser on `/{username}/actions` and stored per person. The editor starts from a template and lists every mistake with where it is (for example `steps[1].with.url: …`).
- **Click an action** (on the actions page or the home screen) to open its YAML; the play button runs it.
- **Every run is recorded** with its trigger, who started it, and each step's result. Recent runs are listed on the actions page.

### Example

```yaml
name: Report a camera fault
description: Start a CCTV ticket and tell the team
on:
  run:                          # a Run button
    show_on_home: true          # also on the home screen
    inputs:
      camera:
        description: Camera number
        required: true
steps:
  - id: create
    uses: ticket/create
    with:
      subject: "Camera ${{ inputs.camera }} offline"
      category: cctv
      priority: high
      assignee: sam.okafor
  - uses: http/request
    with:
      url: https://hooks.example.com/your-webhook
      body:
        text: "New CCTV ticket #${{ steps.create.ticket }}"
  - uses: message/show
    with:
      text: "Ticket #${{ steps.create.ticket }} created."
```

### Triggers (`on:`)

| Trigger | What starts it | Settings |
| --- | --- | --- |
| `run` | The Run button on the actions page or home screen | `show_on_home: true/false`; `inputs:` with `description`, `required`, `default` per input, asked for in a small form before running |
| `ticket` | Something happening to a ticket | `types:` any of `created`, `status_changed`, `assigned`, `replied`; optional `if:` on `category`, `priority`, `status`, `assignee` (one value or a list) |
| `schedule` | A time, checked every minute | a list of `- cron: "0 9 * * 1"` (minute hour day month weekday, app timezone). Needs `php artisan schedule:work` running |

- Ticket-event actions run right after the page that caused the event has been sent, so pages stay fast.
- **Personal** ticket-event actions only run for tickets their owner opened or is assigned to. **Shared** ones run for every matching ticket.
- Changes made by an action don't trigger other actions, so actions can't loop.
- Scheduled personal actions run as their owner. Scheduled shared actions have no person, so ticket steps need `as: <username>`.

### Steps (`uses:`)

| Step | Does | `with:` |
| --- | --- | --- |
| `ticket/create` | Opens a ticket. Output: `ticket` (its number) | `subject` (required), `description`, `category`, `priority`, `assignee` (username), `as` (username to open it as) |
| `ticket/update` | Changes a ticket | `ticket` (number; defaults to the triggering ticket), `status`, `priority`, `category`, `assignee` (username or `none`), `as` |
| `ticket/reply` | Adds a reply | `ticket`, `body` (required), `as` |
| `http/request` | Calls a web address, e.g. a Slack or Teams webhook. Outputs: `status`, `body` | `url` (required), `method` (GET, POST, PUT, PATCH, DELETE; POST when there's a body), `headers`, `body` (text, or a map sent as JSON), `timeout` (1–10 s) |
| `message/show` | The message shown after a Run-button run | `text` (required) |
| `page/open` | Sends you to a Fort Vale page (`/ticket?…`) or a website | `url` (required) |
| `ticket/open-form` | Opens the new-ticket form filled in | `subject`, `description`, `category`, `priority` |

- Steps run in order. The first failing step stops the run, and the run log shows why.
- `page/open` and `ticket/open-form` must be the last step, and only work in actions with `on: run`. They are skipped when the action was started by a ticket event or schedule.
- Give a step an `id` to use its outputs later: `${{ steps.<id>.ticket }}`.
- Steps act as the person who started the run (Run button, or whoever caused the ticket event), unless they say `as: <username>`.

### Values (`${{ … }}`)

| Value | Is |
| --- | --- |
| `${{ inputs.<name> }}` | What was typed for a Run-button input |
| `${{ ticket.id }}`, `.subject`, `.status`, `.priority`, `.category` | The ticket that triggered the action (`on: ticket` only) |
| `${{ actor.name }}`, `${{ actor.username }}` | The person who started the run |
| `${{ steps.<id>.<output> }}` | An earlier step's output |
| `${{ secrets.<NAME> }}` | `FORTVALE_SECRET_<NAME>` from `.env`. **Shared actions only**; masked as `***` in run logs |

Values are plain text substitution; nothing in an action is ever run as code. There are no shell commands.

### Safety of web requests

- `http/request` only calls public internet addresses over http(s).
- It refuses `localhost`, this server, the local network and cloud metadata addresses (the host name is looked up first).
- It doesn't follow redirects, and gives up after at most 10 seconds.
- For development against a local service you can set `FORTVALE_ACTIONS_ALLOW_PRIVATE_HOSTS=true` in `.env`; keep it off in production.
- **Shared actions can read secrets, and anyone using the app can edit shared actions in the browser** (there's no login). Someone could therefore add a step that sends a secret to their own address. Only put secrets in `.env` on installs where everyone with access is trusted.

## Tests

The suite uses both databases, like the app. Start them with `docker-compose up -d`, then run:

```bash
php artisan test
```

- Tests use the separate `fortvale_test` database and `FORTVALE_TEST` schema, so your development data is safe.
- Connection settings for tests are in `phpunit.xml`. Values set in your shell environment win, so you can run against other setups. For example, everything in SQLite:

  ```bash
  DB_CONNECTION=sqlite DB_DATABASE=:memory: TICKETS_DB_CONNECTION=sqlite php artisan test
  ```

- Run one file or test with `php artisan test tests/Feature/TicketTest.php` or `--filter=test_name`.
- GitHub Actions runs the suite on PHP 8.3, 8.4 and 8.5 against real MySQL 8.4 and Oracle Free 23ai services, plus once on SQLite.

## Where things are

| Path | What |
| --- | --- |
| `routes/web.php` | Routes |
| `app/Http/Controllers/DashboardController.php` | Home screen, user switching, reset |
| `app/Http/Controllers/TicketController.php` | Ticket list, create, view, update, reply |
| `app/Http/Controllers/SettingsController.php` | Settings page |
| `actions/` | Shared actions (YAML) |
| `app/Services/Workflows/` | The actions engine: YAML parser, steps, runner, ticket-event and schedule triggers |
| `app/Http/Controllers/ActionController.php`, `SharedActionController.php`, `ActionRunController.php` | Actions page, YAML editors (personal and shared), Run button |
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
