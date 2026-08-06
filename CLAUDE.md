# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Mr. Bills — a personal finance manager focused on bill (contas a pagar) tracking, with automatic due-date
recalculation for weekends and recurring/installment billing. `projeto_mrbills.md` (Portuguese) is the
historical scope doc and phase log for phases 1–7 (already shipped: bills/categories/wallet CRUD + dashboard).
`Roadmap.md` (also Portuguese) is the **single source of truth for current status and what's next** — planned
features (credit cards/invoices, shared "family" accounts, notification center) and completed
technical-debt work are tracked there; check it before designing new work or answering "what's next".

Stack: PHP 8.5 / Laravel 13, Livewire v4 (functional/Volt-style single-file components), Tailwind CSS v4,
Flux UI, PostgreSQL 18 via Laravel Sail (Docker), Traefik as reverse proxy/TLS termination, RabbitMQ as the
real queue backend (`QUEUE_CONNECTION=rabbitmq`). Local `.env` runs against the `pgsql` Sail service
(`DB_CONNECTION=pgsql`), not sqlite, despite `.env.example` defaulting to sqlite. The Sail app container is
named `core` (`APP_SERVICE=core` in `.env`), not the Sail default `laravel.test` — `vendor/bin/sail` reads
`APP_SERVICE` so all `sail ...` subcommands target it transparently.

## Commands

Run via Sail if the containers are up, otherwise plain `php artisan` / `composer` on the host.

```bash
composer lint          # pint --parallel (auto-fix code style)
composer lint:check    # pint --parallel --test (CI check, no changes)
composer types:check   # phpstan analyse (level 7, see phpstan.neon)
composer test          # config:clear + lint:check + types:check + php artisan test
php artisan test                                   # run full test suite
php artisan test --filter=TestClassName::test_name # run a single test
php artisan test tests/Feature/DashboardTest.php    # run a single file
```

Frontend assets: `npm run dev` (Vite dev server) / `npm run build`. `composer dev` runs `php artisan dev`
(concurrently serves app, queue listener against RabbitMQ, the Laravel scheduler loop, and Vite — the
scheduler process is registered by `AppServiceProvider::boot()` via `DevCommands::artisan('schedule:work',
...)`, so scheduled commands like `notifications:send-bill-due-soon` actually run locally without a separate
manual step or cron).

CI (`.github/workflows/`) runs `composer lint` (Pint) and, per PHP version 8.3/8.4/8.5, `composer types:check`
+ `php artisan test`.

### Local environment (Traefik + HTTPS)

The `core` container no longer publishes port 80 directly — Traefik fronts it and terminates TLS. One-time
setup per dev machine:

```bash
sudo apt-get install -y mkcert   # or another mkcert install method
mkcert -install                  # trust mkcert's local CA (repeat on Windows too if browsing from WSL2)
docker/traefik/generate-dev-certs.sh   # generates docker/traefik/certs/mrbills.localhost*.pem
sail up -d
```

App is then reachable at `https://mrbills.localhost` (`APP_DOMAIN` in `.env`; `*.localhost` resolves to
`127.0.0.1` without editing `/etc/hosts`). `docker compose` auto-loads `compose.override.yaml` alongside
`compose.yaml` for `sail`/plain dev use — it adds the Vite/Postgres host port mappings and mounts the mkcert
certs into Traefik, none of which apply in production.

### Production deploy

`compose.prod.yaml` is an explicit overlay (not auto-loaded) for a real VPS deploy: `restart: unless-stopped`
on all services, and switches the `core` Traefik router to the `letsencrypt` certificate resolver (HTTP-01
challenge, defined in `docker/traefik/traefik.yml`) instead of the local mkcert certificate. It also adds two
always-on services that dev gets for free via `composer dev`'s process list: `worker` (`php artisan
queue:work rabbitmq ...`) and `scheduler` (`php artisan schedule:work`) — both share `core`'s image/code
mount. Requires `APP_DOMAIN` set to the real public domain and `ACME_EMAIL` set in `.env`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

## Architecture

**Livewire functional components use a `⚡` filename prefix**, e.g.
`resources/views/components/⚡list-bills.blade.php`. Each file is a single-file component: a
`new class extends Component { ... }` block (props, `mount()`, `with()` for view data, action methods) followed
by `?>` and the Blade template in the same file. This is the primary place business-adjacent UI logic lives —
read the whole file (PHP class + template) together, not just the model, to understand a feature.

Pages under `resources/views/*.blade.php` (`contas.blade.php`, `categorias.blade.php`, `carteira.blade.php`,
`dashboard.blade.php`) are thin wrappers that compose one or more `⚡` components inside the
`x-layouts::app` layout. Routes (`routes/web.php`) map directly to these page views via `Route::view(...)`
under the `auth`+`verified` middleware group — there are no dedicated controllers for the app's core features.

**Domain model**: `Bill` and `Income` are the two transactional entities, each scoped to `user_id` and each
belonging to its own category (`Category` / `IncomeCategory` respectively — separate tables, not shared).
Both support recurring/installment entries via a shared pattern:
- `is_recurrent`, `total_installments`, `current_installments`, `recurrence_group_id` (UUID) columns.
- A static `createRecurrent(array $data)` factory that pre-generates *all* installments up front (one row per
  month, via `addMonthsNoOverflow`), rather than generating them lazily over time.
- A `siblings()` relation (`hasMany` keyed on `recurrence_group_id`) to fetch the rest of the group — used
  when deleting "this and future" installments (see `⚡list-bills.blade.php`'s `deleteThisAndFuture`).
- A `getDisplayDescriptionAttribute()` accessor that appends ` - {current}/{total}` when recurrent.

**Bill-specific business rule**: `Bill::boot()` hooks `static::saving` to compute `actual_due_date` from
`due_date`, rolling weekend due dates forward to the following Monday (Carbon `next(Carbon::MONDAY)`). All
querying/filtering/sorting for "when is this due" uses `actual_due_date`, not `due_date` — `due_date` is the
original/nominal date, `actual_due_date` is the business-day-adjusted one actually used for status and
period logic.

**Status is partly virtual**: `BillStatus` enum (`Pendente=1, Pago=2, Vencido=3, Renegociado=4`) is the
persisted `status` column, but `Vencido` (overdue) is *never stored* — it's derived. A bill is only
"Vencido" if its persisted status is still `Pendente` and `actual_due_date` is in the past.
`Bill::getEffectiveStatusAttribute()` computes this for display; any filtering by "Vencido" status in a
component (see `⚡list-bills.blade.php::applyStatusFilter`) must reconstruct the same condition
(`status = Pendente AND actual_due_date < today`) rather than querying `status = Vencido` directly.

**Category totals**: `Category`/`IncomeCategory` expose a `scopeWithTotals()` query scope using `withSum` to
attach `total_geral` and `total_mes_atual` aggregates without N+1 queries — reuse this instead of summing in
PHP when a listing needs per-category totals.

**Notification center**: uses Laravel's built-in notifications (`User` has `Notifiable`; `notifications`
table via `php artisan notifications:table`), not a custom model — the bell/badge UI
(`⚡notification-center.blade.php`, included twice in `layouts/app/sidebar.blade.php` for desktop/mobile,
same duplication pattern as the user menu there) reads `auth()->user()->unreadNotifications()`.
`App\Notifications\BillDueSoonNotification` implements `ShouldQueue`, so sending one actually round-trips
through RabbitMQ. The scheduled command `App\Console\Commands\SendBillDueSoonNotifications`
(`notifications:send-bill-due-soon`, registered daily at 08:00 in `bootstrap/app.php`) notifies `Pendente`
bills with `actual_due_date` 0–3 days out. Dedup is **not** done by querying the `notifications` table —
since the notification is queued, that row may not exist yet by the time the command finishes. Instead
`Bill::last_due_soon_notified_at` (a plain date column) is stamped synchronously right after dispatch;
"Lembrar depois" only marks the notification read (leaves the bill/column untouched), so the next day's run
naturally re-notifies since the column is stale — that's the entire "remind me tomorrow" mechanism, no
separate snooze state.

**Test coverage**: `Bill`, `Income`, `Category`, `IncomeCategory` each have a factory
(`database/factories/`) and Feature tests (`tests/Feature/{Bill,Income,Category,IncomeCategory}Test.php`,
`tests/Feature/Livewire/ListBillsFilterTest.php`) covering weekend rollover, derived "Vencido" status,
`createRecurrent`/`siblings()`, and `scopeWithTotals()`; `tests/Feature/SendBillDueSoonNotificationsTest.php`
and `tests/Feature/Livewire/NotificationCenterTest.php` cover the notification window/dedup rules and the
bell's actions — all using `RefreshDatabase` against the real Postgres Sail service, no sqlite/mocking.
`phpunit.xml` forces `QUEUE_CONNECTION=sync`, so `ShouldQueue` notifications still execute inline during
tests (no RabbitMQ dependency in CI). `composer types:check` (PHPStan level 7) is currently clean (0 errors);
keep it that way by annotating new Eloquent relations/scopes with generics the way the existing models do
(`BelongsTo<Related, $this>`, `HasMany<Related, $this>`, `Builder<Model>`).

## Notable non-obvious files

- `chisel.php` / `chisel-paths.php`: leftover installer scaffolding from the Laravel/Livewire starter kit used
  to bootstrap this project (chooses between Single-File vs Multi-File Livewire component variants at
  `composer create-project` time). Not part of the app's runtime; safe to ignore during feature work.
- `phpstan.neon`: level 7, includes Larastan + Carbon extensions, scoped to `app/`, `bootstrap/app.php`,
  `config/`, `database/`, `routes/`.
- `docker/traefik/`: Traefik config. `traefik.yml` is the static config (entrypoints, providers, the
  `letsencrypt` certificate resolver skeleton — email is injected via CLI flag in `compose.prod.yaml`, not
  hardcoded here since Traefik's static config file doesn't expand env vars). `dynamic.dev/tls.yml` sets the
  mkcert-generated cert as the default local TLS certificate and is only mounted in dev
  (`compose.override.yaml`). `certs/` holds the machine-generated mkcert `.pem` files (gitignored — regenerate
  with `generate-dev-certs.sh`, don't commit them). Pin the Traefik image to `v3.6+` — earlier v3.x releases
  hardcode the Docker API client at v1.24 and get rejected (`400`, silently — the Docker provider never
  discovers `core`, so every request 404s) by Docker Engine 29+, which requires API v1.40+
  ([traefik/traefik#12253](https://github.com/traefik/traefik/issues/12253)).

**`bootstrap/app.php` trusts all proxies** (`$middleware->trustProxies(at: '*', ...)`) so Laravel reads the
`X-Forwarded-Proto`/`-Host`/`-Port`/`-For` headers Traefik sets — without this, asset/URL generation silently
falls back to `http://` even though the app is only ever reached over HTTPS. Safe to trust `*` here because
Traefik is the sole entrypoint into the `sail` Docker network; nothing else is reachable from outside it.

- `config/queue.php`'s `rabbitmq` connection (added on top of Laravel's stock file, package
  `vladimir-yuldashev/laravel-queue-rabbitmq`) is not in Laravel's own driver list — it's registered by that
  package's auto-discovered service provider. The RabbitMQ container has no published ports in
  `compose.yaml`/`compose.prod.yaml` (only reachable on the internal `sail` network); the AMQP port and the
  management UI (`http://localhost:15672`) are dev-only forwards in `compose.override.yaml`.
