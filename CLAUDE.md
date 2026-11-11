# CLAUDE.md

Homi is a hotel management system for small Philippine hotels, inns and pension houses that sell rooms by the hour, overnight, or both. One installation serves one owner with one or more branches. Front desk staff work on a desktop browser, guests on an optional lobby kiosk, housekeepers on their phones, and back office, admin and owner on desktop. It replaces HotelV2, which failed in production; the rebuild rests on three rules: **money is written once to an append-only ledger, a shift is a real record that every peso points to, and a room can have only one open stay.**

## Docs index

| File | Read it when |
|---|---|
| [docs/mockup/hotel-mockup.html](docs/mockup/hotel-mockup.html) | Building or changing any screen. Screen numbers there (1–63 plus 2b, 4b, 4c, 31b, 37b, 37c, 38b) are used everywhere. |
| [docs/PRD.md](docs/PRD.md) | You need the what and why, a feature's requirements (FR numbers), or the open decisions (§12). |
| [docs/TRD.md](docs/TRD.md) | Before writing any code: stack, architecture rules, pricing and cash formulas, Z-report, dashboards, shift safeguards, tests required. **Wins on technical conflicts.** |
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Touching a migration, model, query or business transaction. **Wins on table and column names.** |
| [docs/SYSTEM_DESIGN.md](docs/SYSTEM_DESIGN.md) | Deciding where code goes: folder tree, layers, components, sequence diagrams. |
| [docs/APP_FLOW.md](docs/APP_FLOW.md) | Routes, role homes, navigation, step-by-step flows, state machines, edge cases, screen inventory. |
| [docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) | Colours, type, spacing, the Nuxt UI theme, every component of our own and its states. |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Finding the current phase and its checklist. |
| [docs/ASSETS.md](docs/ASSETS.md) | Fonts, icons, logo and their fallbacks. |
| [START_HERE.md](START_HERE.md) | Setup steps and the prompt to run for each phase (for Gab). |

## Stack

- PHP 8.3, Laravel 12, MySQL 8.0 (tests on MySQL too, never SQLite)
- Inertia 2 + Vue 3 (`<script setup>`), Vite 6, Tailwind CSS 4
- Nuxt UI v4 (component library, Lucide icons included), `@vueuse/core`, `date-fns`
- `barryvdh/laravel-dompdf` for PDF exports
- Pest 3, Pint, Larastan level 6, Vitest
- Database driver for queue, cache and sessions; polling for live updates

**Not allowed:** Livewire, Jetstream, Fortify, Filament, WireUI, websockets or Pusher, spatie/laravel-permission, any chart, table or date-picker library, any paid service, any new package not listed in TRD §2.

**Design reference:** the owner's Euphoria system, `v2-pos` branch, is the reference for the level of finish; copy structure and conventions, not its palette.

## Golden rules

1. **Build phase by phase.** Do only the current phase in docs/ROADMAP.md. Stop when its checklist and "Done when" line are met.
2. **Match the mockup.** Every screen is built from its numbered frame. Put `<!-- Mockup N -->` at the top of the page component. If the mockup and a doc disagree, stop and ask.
3. **Never edit or delete a money row.** `folio_lines`, `payments`, `payment_allocations`, `stock_movements`, `cash_counts` and `shift_forwarded_stays` are append-only. A correction is a new reversing row with a reason.
4. **Every money row carries the open shift and the person.** Get the shift from `CurrentShift` inside the transaction, never from the clock, the browser or the user row; `created_by` is whoever is signed on at that computer. Logging out never closes a shift.
5. **One business action = one Action class = one database transaction**, with locks in the order in DATABASE_SCHEMA §8. No business logic in controllers or Vue.
6. **Prices come from `PricingService` only.** A missing rate throws; nothing is ever charged ₱0 by default. Money is integer centavos through `Money`; no floats.
7. **Reports read the ledger through `ReportService` only.** A closed shift's figures come from its frozen `totals`, its Z number and `shift_forwarded_stays`; a closed day's from `day_closings`. Never recalculate a closed shift. Every format of a report (screen, slip, PDF, CSV) renders the same builder array.
8. **Dashboards and Stats read the summary tables only**, plus live state bounded to now. They never aggregate the ledgers in a request, and closed-shift reports never read the summary tables.
9. **The count stays blind.** Front desk props never carry expected cash or collected amounts for an open shift; the server leaves them out, not the template.
10. **Room status changes only through `RoomStatusService`.** The database enforces one active stay per room; turn the duplicate-key error into the "room just taken" dialog (53).
11. **Time comes from `Clock`.** No `now()` in `app/`. Store UTC, show Asia/Manila. Use `expected_checkout_at` and `checked_out_at` for what their names say.
12. **Nuxt UI components first, our own components only where Nuxt UI has none; tokens only.** No one-off buttons, no hex colours in pages, charts from `Components/Charts`.
13. **Everything is branch-scoped.** Operational models use `BelongsToBranch`. Per-branch values come from `branches`; nothing about a specific hotel is hard-coded.
14. **Tests first for rules.** Every Action and every formula has the tests listed in TRD §10. Before saying a phase is done, run the full check below and report the real result.

## Commands

```bash
composer install && npm ci          # install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed    # rebuild the database with sample data
composer run dev                    # serve + queue + vite
php artisan test                    # Pest (needs the hotelv4_test MySQL database)
npm run test                        # Vitest
./vendor/bin/pint --test            # code style check
./vendor/bin/phpstan analyse        # static analysis
npm run build                       # production assets

# full check before a phase is done
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && php artisan test && npm run test && npm run build
```

## Folder map

```
app/Actions/        one class per business action (Stays, Shifts, Pos, Housekeeping, Owner, Auth)
app/Services/       PricingService, CashMath, RoomRotation, RoomStatusService, ReportService, DashboardService, StatsService, CurrentShift, CurrentBranch
app/Summaries/      summary tables: RebuildSummaries, CheckSummaries and what they share
app/Queries/        read-side queries (BoardQuery, StayQuery, ShiftQuery, ReportQuery, …)
app/Enums/          every status and kind
app/Models/         Eloquent models, $fillable + casts, BelongsToBranch, AppendOnly
app/Support/        Clock, Money
app/Http/           thin controllers, form requests, role middleware, policies
resources/js/Pages/ one Vue page per route (Welcome, Auth, Dashboard, Stats, Shift, Board, Stays, Pos, Stock, Kiosk, Housekeeping, Reservations, Reports, Admin, Owner)
resources/js/Components/         our own components (RoomTable, MoneyInput, PinPad, StatCard, Slip, …)
resources/js/Components/Charts/  VBars, HBars, Shares, HeatGrid, Spark
database/migrations/      one per table, order in DATABASE_SCHEMA §6
tests/Unit, tests/Feature, tests/Reconciliation, tests/Arch
docs/               all documentation and the mockup
```

Full tree: docs/SYSTEM_DESIGN.md §3.

## Owner notes

- Owner and developer: Gab. Usual stack is Laravel and Flutter; this project is web only.
- The old system lives in `../HotelV2` and is **reference only**. Do not copy its code, schema or data. Its lessons are in docs/PRD.md §2.
- "Hotel Dalisay GenSan" and every person and price in the seeders are made up.
- The product is **Homi**. Logo files are in `resources/images/brand/` and the brand blue is `#009EF5` (see docs/DESIGN_SYSTEM.md §2). The name is one config value (`config/hotel.php` → `name`).
- Budget is ₱0 for paid services.

## Open decisions

Open decisions are in docs/PRD.md §12. None of them blocks phases 0–10. If a task seems to depend on one of them, or on anything the docs do not answer, **ask before building it**.
