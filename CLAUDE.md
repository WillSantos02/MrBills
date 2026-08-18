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
mount. `core` itself runs under **Laravel Octane (Swoole)** in production instead of `php artisan serve`
(`command: 'php artisan octane:start --server=swoole --host=0.0.0.0 --port=80'` — `php8.5-swoole` is already
installed in the Sail runtime image, no extra binary needed); dev keeps `artisan serve` via `composer dev`
unchanged, this override only applies with the prod overlay loaded. Also mounts
`docker/traefik/dynamic.prod/headers.yml` (HSTS/frame-deny/nosniff/referrer-policy) as the `secure-headers`
Traefik middleware on the `core` router — the dev equivalent (`dynamic.dev/`) doesn't set these, so don't
expect them when testing locally against `mrbills.localhost`.

**Environment**: never reuse the dev `.env` in production (it has `APP_DEBUG=true` and weak secrets).
Copy `.env.production.example` to `.env` on the server, fill in every `CHANGE_ME` placeholder (domain,
`APP_KEY` via `php artisan key:generate`, DB/RabbitMQ passwords, `RESEND_KEY` — email is real in
production via Resend, `composer require`d as `resend/resend-php`; `MAIL_MAILER=log` like dev would silently
break e-mail verification and password reset), and set `ADMIN_EMAIL` (used by `queue:alert-failed` below).
Requires `APP_DOMAIN` set to the real public domain and `ACME_EMAIL` set in `.env`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

**Backup**: `docker/postgres/backup.sh` (`pg_dump` + gzip, 14-day retention, written to
`docker/postgres/backups/` — a host bind mount, not a Docker volume, so it survives `docker compose down -v`)
is meant to run daily via host crontab, e.g. `0 3 * * * cd /path/to/project && docker/postgres/backup.sh`.
Local-only for now (no off-site copy — see `Roadmap.md` for that as tracked tech debt). Restore:
`gunzip -c backups/mrbills-<timestamp>.sql.gz | docker compose exec -T pgsql psql -U "$DB_USERNAME" -d "$DB_DATABASE"`.

**Health/monitoring**: `core` has a Docker `healthcheck:` (`curl -f http://localhost/up`, the health route
Laravel registers via `health: '/up'` in `bootstrap/app.php`) so Compose/Traefik notice if it stops
responding. The scheduled `queue:alert-failed` command (`app/Console/Commands/AlertFailedJobs.php`, daily at
09:00) e-mails `ADMIN_EMAIL` when new rows land in `failed_jobs` — dedup is a cache key
(`failed_jobs:last_alerted_id`, the highest id already alerted), same "stamp something so we don't
re-notify" idea as `Bill::last_due_soon_notified_at`. No APM/error tracker (Sentry etc.) yet — also tracked
as tech debt in `Roadmap.md`.

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

**Family data sharing**: `Bill`/`Income`/`Category`/`IncomeCategory` are still stamped with the `user_id` of
whoever created them, but every read/edit/delete query in the `⚡list-*`/`⚡create-*` components filters by
`whereIn('user_id', auth()->user()->familyGroupUserIds())` instead of a single `user_id`.
`User::familyGroupUserIds()` (`app/Models/User.php`) resolves the whole family circle (owner + members,
including the caller) from `family_owner_id`, and works the same whether called on the owner or a member.
Category/income-category name uniqueness is enforced **per family**, not per creator
(`Rule::unique(...)->where(fn ($q) => $q->whereIn('user_id', ...))` in `⚡create-category.blade.php` and the
two `updateCategory` methods) — there's no DB-level constraint for this (the family group is dynamic, keyed
off `family_owner_id`, not a fixed column per row), just the validation layer. The `category_id`/
`income_category_id` a bill/income points to is validated the same way
(`Rule::exists(...)->where(fn ($q) => $q->whereIn('user_id', ...))` in the 4 create/edit components) — a
plain `exists:categories,id` would let a bill attach to *any* category in the database regardless of family,
which silently leaks that bill's value into a stranger's category totals via `scopeWithTotals()`'s
`withSum`.

**Deleting an account you don't fully own — ownership transfer**: a user can always delete their own
account, but a family **owner with active members** can't just self-destruct and cascade-delete data the
family still depends on. `⚡delete-user-modal.blade.php` branches into 4 states based on
`auth()->user()->familyMembers()->count()`, `sentOwnershipTransferRequest`, and
`family_transfer_declined_at`: no members → normal deletion; owner with members and no pending
request/decline → pick a member to transfer to; pending request → wait/cancel; declined → offer a
non-destructive path. `OwnershipTransferRequest` (`from_user_id`/`to_user_id`, `unique(from_user_id)`) is a
family-invite-style "pending-only" table — accept/reject in `⚡notification-center.blade.php` always deletes
the row, no status column. On **accept**, inside one `DB::transaction()`: same-named categories/income
categories on both sides are merged (bills/incomes repointed, the duplicate deleted) *before* bulk
`update()`-ing every `Bill`/`Income` `user_id` from the old owner to the new one — otherwise the mass update
would collide with `unique(user_id, name)`; the family tree is then re-rooted (`family_owner_id`) so the old
owner becomes a regular member, which is what actually unblocks their own deletion afterward (state 1 above,
since they no longer own anyone). On **reject**, nothing about the data moves — only
`family_transfer_declined_at` gets stamped, which is what lets the modal offer a **soft-delete** instead
(`User` uses `SoftDeletes`; a soft-deleted row never triggers the `onDelete('cascade')` on
`bills`/`categories`/`incomes`/`income_categories`, and the Fortify auth guard already excludes soft-deleted
users automatically via Eloquent's global scope, so the account just stops being able to log in).
`familyGroupUserIds()` uses `withTrashed()` specifically so a soft-deleted former owner's data stays visible
to the family that's still using it. That preserved data isn't permanent, though: `deleteUser()` also checks
`trashedFamilyOwner()`/`isLastActiveFamilyMember()` — if the person deleting their account is the last
active member of a family whose (soft-deleted) former owner was never really transferred, their departure
`forceDelete()`s that former owner too, cascading the real, final deletion at that point.

**Test coverage**: `Bill`, `Income`, `Category`, `IncomeCategory` each have a factory
(`database/factories/`) and Feature tests (`tests/Feature/{Bill,Income,Category,IncomeCategory}Test.php`,
`tests/Feature/Livewire/ListBillsFilterTest.php`) covering weekend rollover, derived "Vencido" status,
`createRecurrent`/`siblings()`, and `scopeWithTotals()`; `tests/Feature/SendBillDueSoonNotificationsTest.php`
and `tests/Feature/Livewire/NotificationCenterTest.php` cover the notification window/dedup rules and the
bell's actions; `tests/Feature/Livewire/FamilyDataSharingTest.php` covers cross-member visibility/edit and the
family-scoped `category_id`/name-uniqueness validation above; `tests/Feature/Livewire/OwnershipTransferTest.php`
covers the accept/reject/soft-delete/dissolution flow; `tests/Feature/AlertFailedJobsTest.php` covers the
`failed_jobs` alert dedup — all using `RefreshDatabase` against the real Postgres Sail service, no
sqlite/mocking.
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
