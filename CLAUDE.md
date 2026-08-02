# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Mr. Bills — a personal finance manager focused on bill (contas a pagar) tracking, with automatic due-date
recalculation for weekends and recurring/installment billing. See `projeto_mrbills.md` for the (Portuguese)
project scope doc and development-phase log; keep it updated as new phases land.

Stack: PHP 8.5 / Laravel 13, Livewire v4 (functional/Volt-style single-file components), Tailwind CSS v4,
Flux UI, PostgreSQL 18 via Laravel Sail (Docker). Local `.env` runs against the `pgsql` Sail service
(`DB_CONNECTION=pgsql`), not sqlite, despite `.env.example` defaulting to sqlite.

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
(concurrently serves app, queue listener, and Vite).

CI (`.github/workflows/`) runs `composer lint` (Pint) and, per PHP version 8.3/8.4/8.5, `composer types:check`
+ `php artisan test`.

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

## Notable non-obvious files

- `chisel.php` / `chisel-paths.php`: leftover installer scaffolding from the Laravel/Livewire starter kit used
  to bootstrap this project (chooses between Single-File vs Multi-File Livewire component variants at
  `composer create-project` time). Not part of the app's runtime; safe to ignore during feature work.
- `phpstan.neon`: level 7, includes Larastan + Carbon extensions, scoped to `app/`, `bootstrap/app.php`,
  `config/`, `database/`, `routes/`.
