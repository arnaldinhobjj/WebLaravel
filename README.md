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
- **Sign in** with email and password. Replies and changes are recorded under the person who is signed in.
- **Profile menu** (the avatar) – your tickets, tickets you opened, new ticket, *My actions*, *Settings* (including your password), *Tags and permissions* (if you may manage them), show/hide sidebar, and *Sign out*.
- **Tags page** (`/tags`) – permissions, departments and people. See [People, departments and tags](#people-departments-and-tags).
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

- Starts **MySQL 8.4** on port 3306, **Oracle Free 23ai** on port 1521 (service `FREEPDB1`), **Mailpit**, which catches every email the app sends (read them at http://localhost:8025), and **SeaweedFS**, an S3-compatible store for files (S3 on port 8333, a file browser at http://localhost:8888). A one-off `seaweedfs-bucket` container creates the `fortvale` bucket.
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
docker-compose up -d         # MySQL, Oracle, Mailpit and SeaweedFS
php artisan serve            # the app, at http://127.0.0.1:8000
php artisan schedule:work    # only needed for actions with `on: schedule`
```

- Stop the app with `Ctrl+C`.
- Stop the databases with `docker-compose stop`. Your data is kept in Docker volumes.
- `docker-compose down -v` deletes the databases completely; run `php artisan migrate --seed` afterwards to recreate them.

### Using the app

- Sign in at http://127.0.0.1:8000/login. Every seeded person has the password `password`; **alex.morgan@fortvale.test** is the Administrator. Change it under *Settings* → *Password*.
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

## People, departments and tags

Who may do what is set per page on the **Tags** page (`/tags`).

- **Tags hold permissions, page by page.** The tag form is a grid: pages down the side, **View / Create / Edit / Delete** across, and page-specific extras at the end. A page only offers the abilities it really has.

  | Page | View | Create | Edit | Delete | Extras |
  | --- | --- | --- | --- | --- | --- |
  | Tickets | list and open tickets | new ticket | status, priority, category | — | Reply, Assign people, See everyone's tickets |
  | My actions | actions page | new action | edit YAML | delete | Run them |
  | Shared actions | read the YAML | — | save the YAML | — | Run them |
  | Tags / Departments | their section | new | edit | delete | — |
  | People | People section | add person | edit, set password | — | — |

  Ticking anything on a page also ticks View. Without *See everyone's tickets*, people only see tickets they opened or are assigned to; other tickets answer "not found".
- **Everyone** is a built-in tag that every person has. Edit it to set what everyone may do. It can't be deleted or renamed. Out of the box it allows: tickets (view, create, reply), their own actions, viewing and running shared actions, files, and AI chat and web search. Changing a ticket's status, priority or category and reassigning it are for the Helpdesk tag and administrators. Without any tag, a person can still sign in, see the home screen and change their own settings.
- **Departments** carry tags: everyone in a department gets its tags. **People** can also get extra tags of their own. The People list shows what each person can do in the end.
- **You can't lock yourself out.** Any change that would leave nobody able to edit both tags and people is refused.
- **Adding someone:** *Add person*, with their email and a first password. They can change it under *Settings*.
- **Search** with the box at the top: it filters tags, departments and people as you type.
- **Upgrading an older install:** `php artisan migrate` converts the old permissions (for example "Manage tags and people" becomes every ability on Tags, Departments and People) and creates the Everyone tag.

### Adding a page or functionality

1. Add it to `PAGES` in `app/Support/PagePermissions.php`: its label, which of view/create/edit/delete it has, and any extras.
2. Protect its routes with `->middleware('can:page.ability')` (for example `can:reports.view`), and wrap its buttons in `@can('page.ability')`.

The tag grid and the Tags page pick it up by themselves.

Seed data: departments Operations (tag *Action editors*), Facilities and IT (tag *Helpdesk*: see, change and assign every ticket); Alex Morgan also has *Administrator* (everything).

## Files

**Workspace Files** (`/workspace-files`, in the sidebar) shows files like the macOS Finder:

- **Icons:** folders show as folder icons with their name underneath. Click one to open it; folders inside folders work at any depth. Files show as document icons labelled with their type (PDF, XLSX…), and images as small thumbnails. Click a file to preview it.
- **Places:** a sidebar (Workspace Files, Library, My files, and the trash, which keeps the same look and has *Restore* under each item), a back button and the folder path.
- **New:** *New folder* and *Upload files…* inside a folder; at the top, *New folder in Library* or *in My files*. You can also drop files anywhere in a folder to upload them.
- **List view** opens the same folder with renaming, moving, versions and access settings. *Icon view* on the Files pages comes back.
- **Access:** the same rules as everywhere: you only see the areas and folders you may open.

Files lives in the sidebar and has three areas:

- **Library** (`/files`): shared folders.
- **My files** (`/my-files`): private to each person.
- **Trash:** deleted items stay for 30 days.

What you can do:

- **Upload:** drop files on the page or choose them. A file with the same name as one already in the folder becomes a **new version**. *Version history* lists every version; any old version can be downloaded or restored (restoring adds it as the newest, so nothing is lost).
- **Preview:** images (PNG, JPEG, GIF, WebP), PDFs and text files open in the page. Everything else, including SVG and HTML, downloads instead of being shown, since those can carry scripts.
- **Organise:** rename, move between folders, new folders, and **search** by name across everything you may see.

**Who sees what:**

- **Personal areas:** only their owner can open them, administrators included.
- **Library:**
  - *Files (library)* in the tag grid allows View / Create (upload, new folder) / Edit (rename, move, new versions) / Delete, plus *Manage folder access* and *Empty trash*.
  - **Each top-level folder** can be limited with *Who has access*: tick tags and departments, and what each may do. Subfolders follow. A folder with nothing ticked is open to everyone with the Files permission.
  - Both have to allow it.
  - Folders and files you may not see answer "not found".
- **Defaults:** the Everyone tag may view, upload and edit in the library, and use My files; the Administrator tag also deletes, manages access, empties the trash and changes file settings.

**Limits** (*File settings*, `/files/settings`):

- **Largest file:** 25 MB by default. PHP's own `upload_max_filesize` must be at least as big; on this Mac it is set to 100 MB.
- **Blocked extensions.** Programs are refused whatever their name, because the type is read from the content.
- **Quotas:** a limit for My files per person and a total limit. Versions and the trash count towards them.

**Storage.** File contents live in an S3 bucket, one object per version under a random name. Names, folders and versions are in MySQL, so renaming or moving never touches S3. Downloads go through Fort Vale, which checks access first; the bucket itself refuses anyone without the keys. Locally that's SeaweedFS; for AWS S3 or another S3-compatible service, set in `.env`:

```dotenv
FILES_ENDPOINT=            # empty for AWS; e.g. https://<account>.r2.cloudflarestorage.com for others
FILES_ACCESS_KEY_ID=…
FILES_SECRET_ACCESS_KEY=…
FILES_REGION=eu-west-1
FILES_BUCKET=fortvale
```

**Attachments.** Files can also be attached to pages instead of living in a folder:

- **Tickets:** attach files when opening a ticket or replying (they show on the ticket and under the reply), or with the *Attachments* box on the ticket. Whoever can see the ticket can open them. Adding with the box needs *Tickets: Reply*. A file can be removed by whoever added it, or by people with *Tickets: Edit*.
- **Email templates and shared actions** have a *Files for this …* box. Seeing them needs view permission on that page; adding or removing needs edit.
- **Ticket emails** say how many files came with a new ticket or reply. The files themselves aren't attached, to keep emails small.
- **Limits:** the size and type limits apply, and attachments count towards the total quota (not anyone's My files).
- **Adding attachments to another page:**
  1. Add a type to `app/Support/Attachables.php`: how to find the record, who may view and add, and its URL.
  2. Put `<x-attachments type="…" :id="…" />` on the page.

**Files in email and actions.**

- **Email page:** *Attach from Files* shows your 30 most recently changed files and searches all files you may open by name; ticked files stay ticked while you search. You can also upload new ones (sent, not stored). Up to 10 MB in total. Starting from a template also sends the template's files.
- **Email templates:** files in a template's *Files for this template* box go with every email that uses it: the Email page, the template's test send, actions, and the built-in ticket emails.
- **Actions:** `email/send` takes `attachments:` (paths such as `Library/Quality/Checklist.pdf` or `My files/notes.txt`), and `file/save` writes text into a folder. Both use the access of:
  - **personal actions:** their owner;
  - **shared actions:** whoever runs them, or `as:`;
  - **scheduled shared actions without `as:`:** only library folders without access rules.

**Trash:** `php artisan files:empty-trash` runs daily from the scheduler (`php artisan schedule:work`) and deletes items older than 30 days, including their stored objects.

## AI

The **AI** page (`/ai`, in the sidebar) connects AIs to Fort Vale, both ways. Everything is per person: your AIs, your chats, your tokens and your skill switches. Nobody else can see your chats, administrators included.

### Chat with any AI

- **Your AIs:** add as many AIs as you like under *Your AIs*, local or cloud. There are ready-made presets for Ollama, LM Studio, OpenAI, Anthropic Claude, Google Gemini, OpenRouter and Groq.
  - Each has an address, a model (*Test connection* lists them) and an optional API key, stored encrypted and only sent to your own browser.
  - Pick which AI to talk to above the message box.
- **Your browser talks to the AI directly**, so answers stream in. Cloud AIs allow this already; local ones must allow this page (CORS):
  - **Ollama:** `OLLAMA_ORIGINS=http://127.0.0.1:8000 ollama serve`;
  - **LM Studio:** Developer tab → *Enable CORS*.
- **Chats are saved** as they happen, including any skills used, and listed on the left.
- **Instructions for your AIs** (a system prompt) are sent at the start of every chat.

### Skills

Skills are what any AI can do in Fort Vale. Each is written once (`app/Ai/Skills/`) and offered to every kind of AI. A skill always acts **as you**, within your permissions, and never more.

- **30 abilities:**
  - tickets: search, read, my queue, history, stale, open, reply, change, assign;
  - people and departments;
  - files: search, recent, list a folder, read, details/versions, create a folder, save a text file, trash;
  - email: templates, sent, send;
  - actions: list, explain, recent runs, check YAML, run;
  - today's summary;
  - **web: search the internet, and read a web page as text.**
- **10 recipes**, ready-made instructions an AI follows: summarise a ticket, draft a reply, triage my tickets, my day, weekly ticket report, find a document, write an action, email an update about a ticket, handover notes, what can I do, research a topic on the web.
- **In the chat:**
  - **The AI uses skills by itself**, when the model supports tool calling (most current ones; small models choose poorly, so use something like `qwen3:30b`, which this Mac uses, or a cloud AI). Skills that change something (open, reply, send, run, save, delete…) ask you first.
  - **You can call them yourself:** type `/` in the message box. A menu appears and narrows as you keep typing (`/tic` shows the ticket skills); ↑ ↓ choose, Enter or Tab picks, Esc closes. An ability runs at once and the AI sees the result; a recipe is sent to your AI.
- **The Skills page** (`/ai/skills`) lists everything, with *Try it* forms:
  - **Switch any skill off for your own AIs.** People with *AI: Switch skills off for everyone* can switch one off for everybody.
  - **Switches apply everywhere:** chat, MCP and the API.

### Let other AI apps use Fort Vale

Create a token under *Let your AI use Fort Vale* (it needs *AI: Connect my AI to Fort Vale*). The token acts as you.

- **MCP apps** (Claude Desktop, Claude Code, Cursor, LM Studio, Open WebUI, Jan…):
  - server `/mcp/fortvale` with header `Authorization: Bearer <token>`;
  - every ability is a tool (e.g. `tickets_search`) marked read-only or changing, and every recipe is a prompt.
- **Anything else**, using the same token:
  - **OpenAI-style tool calling, custom GPT actions, your own agents:**
    - `GET /api/ai/skills`: tools in OpenAI format, plus the recipes;
    - `GET /api/ai/openapi.json`: an OpenAPI 3.1 spec;
    - `POST /api/ai/skills/{name}`: JSON in, `{ok, summary, data}` out;
    - `GET /api/ai/recipes/{name}?…`: a recipe's instructions.
  - **Limits:** 60 calls a minute per token, every call is logged on the token, and *Revoke* stops it.

**Web search** (`web.search`) uses DuckDuckGo out of the box, with no key. For other search services, set `WEB_SEARCH_PROVIDER` in `.env`:
- `brave` with `BRAVE_SEARCH_API_KEY`;
- `tavily` with `TAVILY_API_KEY`;
- `searxng` with `SEARXNG_URL`, your own instance.

`web.read` only reads public internet addresses, after every redirect too, so it can't reach your own network. Both need *AI: Search and read the web*; everyone has it by default.

**Adding a skill:**
1. Write a class in `app/Ai/Skills/Abilities/` (name, description, JSON Schema inputs, permissions, `run()`), or a recipe in `app/Ai/Skills/Recipes/`.
2. List it in `SkillRegistry`.

It then appears in the chat, on the Skills page, over MCP and in the API.

**Permissions:** *AI: Chat with my AI* (Everyone), *Connect my AI to Fort Vale* and *Switch skills off for everyone* (Administrator).

## Email

Fort Vale sends email three ways, all through one sender (`app/Services/Email/Mailer.php`):

- **Ticket emails**, automatically:
  - **New ticket:** the category's extra addresses (below).
  - **Assigned:** the new assignee.
  - **Reply** and **status change:** the requester and the assignee.

  Nobody is emailed about their own change, or about a ticket they can't see. Each person picks which ticket emails they get under *Settings* → *Email me when…*.
- **The Email page** (`/email`): pick people and/or type addresses, optionally start from a template, and send. Each recipient gets their own copy. Your sent emails are listed below the form.
- **Actions**, with the `email/send` step (see [Steps](#steps-uses)).

Defaults:

- **Email templates** (`/email/templates`): four built-in templates are the ticket emails. Edit their wording, but you can't delete them. Add your own for the Email page and actions. *Send a test to me* sends one with example values. Templates use the same `${{ … }}` placeholders as actions, plus `recipient.name` and `reply.body`.
- **Email settings** (`/email/settings`): the sender name and address, and per ticket category the addresses that also get the "new ticket" email. The sender falls back to `MAIL_FROM_NAME` / `MAIL_FROM_ADDRESS` in `.env`.

Permissions (on the Tags page): *Email* (view, Send emails), *Email templates* (view, create, edit, delete), and *Email settings* (view, edit). The Everyone tag doesn't include them; the Administrator tag has them all.

**Locally**, everything lands in Mailpit at http://localhost:8025 and nothing leaves your machine. **For real email**, point `.env` at your mail server:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="fortvale@yourcompany.com"
```

A failed send is written to `storage/logs/laravel.log`. It never breaks the page; an action's step is marked failed.

## Actions (YAML)

**Help writing actions:**
- `php artisan actions:validate` checks every file in `actions/`, or the files you name; add `--personal` to check a personal action. It lists every mistake.
- If you use Claude Code, the project includes an **action-builder** agent (`.claude/agents/action-builder.md`). Ask it for an action in plain language, e.g. "make an action that emails IT when a CCTV ticket is created". It writes the file in `actions/` and validates it.

Actions work like GitHub Actions: each one is a YAML document with a `name`, what starts it (`on:`) and the `steps` it runs.

- **Shared actions** are files in the [`actions/`](actions/) folder, one `.yml` per action, like `.github/workflows`. Everyone can run them.
  - Edit them in the repository, or in the browser if one of your tags allows *Shared actions: Edit*: click the action, change the YAML and save. Saving checks the YAML first, writes the file, and refuses to overwrite a file someone else changed since you opened it.
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
| `email/send` | Sends an email, one copy per recipient. Outputs: `sent`, `to` | `to` (required: usernames and/or addresses, up to 20), and either `template` (an email template's name) or `subject` + `body`. `${{ recipient.name }}` is filled in per person. `attachments`: up to 10 file paths (`Library/…` or `My files/…`), 10 MB in total. Personal actions need their owner to have *Email: Send emails* |
| `file/save` | Saves text as a file; the same name gets a new version. Outputs: `file` (its path), `version` | `folder` (required, `Library/…` or `My files/…`), `name` (required), `content` (required, up to 1 MB), `as` |
| `http/request` | Calls a web address, e.g. a Slack or Teams webhook. Outputs: `status`, `body` | `url` (required), `method` (GET, POST, PUT, PATCH, DELETE; POST when there's a body), `headers`, `body` (text, or a map sent as JSON), `timeout` (1–10 s) |
| `message/show` | The message shown after a Run-button run | `text` (required) |
| `page/open` | Sends you to a Fort Vale page (`/ticket?…`) or a website | `url` (required) |
| `ticket/open-form` | Opens the new-ticket form filled in | `subject`, `description`, `category`, `priority` |

- Steps run in order. The first failing step stops the run, and the run log shows why.
- `page/open` and `ticket/open-form` must be the last step, and only work in actions with `on: run`. They are skipped when the action was started by a ticket event or schedule.
- Give a step an `id` to use its outputs later: `${{ steps.<id>.ticket }}`.
- **Personal actions always act as their owner**, within the owner's permissions: they can only touch tickets the owner can see, and changing, assigning or replying needs the owner's matching ticket permissions. `as:` isn't allowed in personal actions.
- **Shared actions** act as the person who started the run (Run button, or whoever caused the ticket event), unless they say `as: <username>`. They may only use the *My files* of the person running them.

### Values (`${{ … }}`)

| Value | Is |
| --- | --- |
| `${{ inputs.<name> }}` | What was typed for a Run-button input |
| `${{ ticket.id }}`, `.subject`, `.status`, `.priority`, `.category`, `.url`, `.requester`, `.assignee` | The ticket that triggered the action (`on: ticket` only); requester and assignee are usernames |
| `${{ recipient.name }}`, `${{ recipient.email }}` | The person receiving the email (`email/send` only) |
| `${{ actor.name }}`, `${{ actor.username }}` | The person who started the run |
| `${{ steps.<id>.<output> }}` | An earlier step's output |
| `${{ secrets.<NAME> }}` | `FORTVALE_SECRET_<NAME>` from `.env`. **Shared actions only**; masked as `***` in run logs |

Values are plain text substitution; nothing in an action is ever run as code. There are no shell commands.

### Safety of web requests

- `http/request` only calls public internet addresses over http(s).
- It refuses `localhost`, this server, the local network and cloud metadata addresses (the host name is looked up first).
- It doesn't follow redirects, and gives up after at most 10 seconds.
- For development against a local service you can set `FORTVALE_ACTIONS_ALLOW_PRIVATE_HOSTS=true` in `.env`; keep it off in production.
- **Shared actions can read secrets**, so anyone allowed to edit shared actions could add a step that sends a secret elsewhere. Give *Shared actions: Edit* only to people you'd trust with the secrets in `.env`.

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
| `app/Http/Controllers/DashboardController.php` | Home screen, reset |
| `app/Http/Controllers/LoginController.php` | Sign in and out |
| `app/Http/Controllers/TagController.php`, `DepartmentController.php`, `PersonController.php`, `resources/views/tags` | Tags page: tags, departments, people |
| `resources/views/files/workspace.blade.php`, `workspace-trash.blade.php`, `resources/views/files/partials/` (folder and file icons, Finder sidebar) | The Finder-style Workspace Files pages |
| `app/Ai/Skills/` (abilities, recipes, `SkillRegistry`), `app/Mcp/`, `app/Http/Controllers/SkillApiController.php`, `routes/ai.php` | AI skills, and the MCP and HTTP adapters that offer them to any AI |
| `app/Http/Controllers/AiController.php`, `AiSkillController.php`, `resources/views/ai`, `public/js/ai-chat.js` | The AI page: connections, saved chats, skills in the chat, the Skills page |
| `app/Services/Files/`, `app/Support/Attachables.php`, `resources/views/components/attachments.blade.php`, `app/Http/Controllers/FileController.php`, `AttachmentController.php`, `FileSettingsController.php`, `resources/views/files`, `app/Models/Folder.php`, `StoredFile.php`, `FileVersion.php`, `FolderAccess.php` | Files: storage, access rules, limits, trash and the file pages |
| `app/Services/Email/Mailer.php`, `app/Listeners/SendTicketEmails.php`, `app/Http/Controllers/Email*Controller.php`, `resources/views/emails` | Sending email, ticket emails, the Email, templates and settings pages, the email layout |
| `app/Support/PagePermissions.php`, `app/Models/Tag.php`, `app/Models/Department.php`, `app/Support/AccessGuard.php` | Pages and their permissions, tags, departments, and the check that someone can always manage access |
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
