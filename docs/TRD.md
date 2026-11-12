# Technical Requirements — Homi

| | |
|---|---|
| Version | 1.1 · 11 November 2026 (Nuxt UI, dashboards, Z-report, shift safeguards) |
| Status | Approved; matches mockup v3 |
| Wins on conflict | **Yes**, for technical rules. Table and column names: [DATABASE_SCHEMA](DATABASE_SCHEMA.md) wins. |
| Related | [PRD](PRD.md) · [SYSTEM_DESIGN](SYSTEM_DESIGN.md) · [APP_FLOW](APP_FLOW.md) · [DESIGN_SYSTEM](DESIGN_SYSTEM.md) · [ROADMAP](ROADMAP.md) |

## 1. Platform and toolchain

| Item | Requirement |
|---|---|
| PHP | 8.3 |
| Framework | Laravel 13 |
| Frontend | Inertia.js 3 + Vue 3 (`<script setup>`, Composition API), Vite 8 |
| Styling | Tailwind CSS 4 and **Nuxt UI v4**, themed with the tokens in [DESIGN_SYSTEM](DESIGN_SYSTEM.md). The reference for the level of finish is the owner's Euphoria system (`v2-pos` branch). |
| Database | MySQL 8.0, InnoDB, `utf8mb4_unicode_ci`. Tests run on MySQL too (generated columns and CHECK constraints must be exercised), never SQLite. |
| Queue / scheduler | `database` queue driver; Laravel scheduler via one cron entry |
| Cache / session | `database` driver for both (no Redis needed for ₱0 hosting) |
| Server | One Linux VPS or shared host with SSH, PHP 8.3, MySQL 8, HTTPS. One installation serves one owner and all their branches. |
| Browsers | Latest Chrome and Edge on desktop (front desk, back office, admin, owner); Chrome on Android for housekeepers; Chrome in kiosk mode for the kiosk |
| Screen sizes | Desktop ≥ 1280×720. Kiosk portrait 1080×1920. Phone ≥ 360 px wide. |
| Timezone | Server and database in UTC. `APP_TIMEZONE=UTC`; display timezone `Asia/Manila` from `config('hotel.display_timezone')`. |
| Language | English UI. Currency ₱, format `₱1,234.00`. |

## 2. Allowed dependencies

| Package | Purpose |
|---|---|
| `laravel/framework` ^13 | Backend |
| `inertiajs/inertia-laravel` ^3, `@inertiajs/vue3` ^3 | Server-driven Vue pages |
| `vue` ^3.5 | UI |
| `tailwindcss` ^4, `@tailwindcss/vite` | Styling |
| `tightenco/ziggy` ^2 | Route names in Vue |
| `@nuxt/ui` ^4 | Component library: buttons, forms, modals, toasts, skeletons, dropdowns, colour mode. Lucide icons come with it. |
| `@iconify-json/lucide` | The Lucide icon set bundled locally, so no icon is fetched at runtime |
| `@vueuse/core`, `date-fns` | Colour mode, timers, date helpers |
| `barryvdh/laravel-dompdf` | PDF export of the Z-report and other reports |
| `qrcode` (npm) | QR on kiosk hold screen (30) |
| IBM Plex Sans, IBM Plex Mono and Newsreader font files (one-time source: `@fontsource/ibm-plex-sans`, `@fontsource/ibm-plex-mono`, `@fontsource/newsreader`) | The woff2 files are copied into `resources/fonts/` and committed (ASSETS §1); the packages are not kept in `package.json` |
| `pestphp/pest` ^4, `pestphp/pest-plugin-laravel` ^4 | Tests |
| `laravel/pint` | Code style |
| `larastan/larastan` ^3.15 | Static analysis, level 6 |
| `vitest` ^5, `@vue/test-utils` | Component tests for shared components |
| `@vitejs/plugin-vue`, `laravel-vite-plugin`, `concurrently`, `typescript` ^6, `happy-dom` (dev only) | Build and test tooling: Vue SFCs in Vite, Laravel's Vite integration, `composer run dev`, `<script setup lang="ts">` type checking, the DOM Vitest runs in. TypeScript stays on 6: Vue's SFC compiler cannot use TypeScript 7 to resolve imported prop types. |

**Not allowed:** any chart, table or date-picker library (charts are our own small components in `Components/Charts`), Livewire, Jetstream, Fortify, Filament, WireUI, Pusher or any websocket service, `spatie/laravel-permission`, `spatie/laravel-db-snapshots`, Debugbar in production, any paid API or SaaS, any package that edits ledger rows. Adding a dependency needs a line in this table first.

## 3. Architecture requirements

1. **Layers.** `Controller → FormRequest → Action → Models/Services`. Controllers contain no business logic and no queries beyond calling a Query class. Vue pages contain no pricing, totals or permission logic; they display what the server sends.
2. **Actions.** One class per business action in `app/Actions`, one public `handle()` method, one `DB::transaction()` inside. An action never calls another action's `handle()` outside its own transaction; shared steps live in services.
3. **Pure services.** `PricingService`, `CashMath`, `ReportService::compute*` and `RoomRotation` take values in and return values out. They read no globals, call no `now()`, and are unit-tested without a database where possible.
4. **Injected clock.** Time comes from `App\Support\Clock` (wrapping `CarbonImmutable`), bound in the container. `now()` and `Carbon::now()` are banned in `app/` (enforced by a Pest arch test). Tests freeze the clock.
5. **Current shift.** `CurrentShift::for($branchId)` is the only way to find the open shift. Every action that writes a `folio_lines`, `payments`, `cash_movements`, `pos_orders` or `stock_movements` row gets the shift from it, inside the transaction, with `lockForUpdate()`. No shift value ever comes from the browser, the clock or the user row.
6. **Branch scope.** `BelongsToBranch` trait adds a global scope from `CurrentBranch` (the user's `branch_id`, or the branch an owner has opened, kept in the session). A Pest arch test asserts every operational model uses the trait.
7. **Enums.** Every status and kind is a PHP backed enum in `app/Enums` matching the MySQL ENUM. No magic strings or numeric IDs in code.
8. **Money.** `App\Support\Money` value object over integer centavos; Eloquent cast on every `*_amount`/`*_cash` column. Arithmetic only through `Money`. Floats are banned for money (arch test on `float` casts in models).
9. **Config constants.** Product-wide constants live in `config/hotel.php` (display timezone, PIN length 4, login lock 5 tries / 15 minutes, polling: board 15 s, kiosk holds 5 s, housekeeping 20 s, kiosk availability 20 s, heartbeat 5 s). Per-branch values live on `branches` and are read through the `Branch` model. Nothing is hard-coded in actions, reports or Vue.
10. **Append-only models.** Models listed as append-only in the schema use the `AppendOnly` trait, which throws on `update`/`delete`.
11. **Idempotency.** Check-in, kiosk hold, POS sale and payment forms carry a UUID `idempotency_key` generated when the form opens. The server returns the first result for a repeated key.

## 4. Business rules that must be implemented exactly

### 4.1 Roles and permissions

| Capability | owner | admin | supervisor | frontdesk | backoffice | housekeeper | kiosk |
|---|---|---|---|---|---|---|---|
| Manage branches, owner accounts, owner summary, owner dashboard (55, 56, 39, 60) | ✔ | | | | | | |
| Branch dashboard and stats (61, 63) | ✔ any branch | ✔ own | ✔ own | | ✔ own | | |
| Close the day (38b) | ✔ | ✔ own | | | ✔ own | | |
| Today (62) | | | ✔ | ✔ | | | |
| Welcome page (59) | public |||||||
| Branch setup (44–48) | ✔ any branch | ✔ own | | | | | |
| Needs attention (49) | ✔ | ✔ | ✔ | | | | |
| Reports (36–38, 37b, 37c, 40–43) | ✔ any branch | ✔ own | ✔ own | | ✔ own | | |
| Front desk work (2–26, 57, 58) | | | ✔ | ✔ | | | |
| Approve by PIN (14) | | ✔ | ✔ | | | | |
| Housekeeping (32–35) | | | | | | ✔ | |
| Kiosk (27–31b) | | | | | | | ✔ |

Implemented as Laravel policies and a `role` middleware. A front desk user must be a member of the open shift to reach any front desk route except `/shift/open`.

### 4.2 Stay pricing (`PricingService::quoteStay`)

| Stay kind | Room amount | Expected check-out |
|---|---|---|
| `hourly`, H hours | `rates(type, hourly, H).amount` | check-in + H hours |
| `overnight`, N nights | `rates(type, overnight).amount × N` | the N-th next occurrence of `overnight_checkout_time` after check-in |
| `long_stay`, D days (1 to `long_stay_max_days`) | `rates(type, hourly, 24).amount × D` | check-in + D × 24 hours |

- Discount: `fixed` subtracts `value` centavos; `percent` subtracts `round_half_up(room_amount × value / 100)`. A discount applies to the room amount only, never to the deposit, and never below ₱0. A discount limited to rates (`all_rates = 0`) is refused on other rates.
- Missing rate → `RateNotFound`. Never ₱0.
- Total to collect at check-in = room amount − discount + `deposit_amount` (if `deposit_enabled`).

### 4.3 Extension pricing (`PricingService::quoteExtension`)

Let `C = extension_cycle_hours`, `U = stays.cycle_hours_used`, `H` = hours requested.

| `extension_rule` | Amount | New `cycle_hours_used` |
|---|---|---|
| `cycle` and `U + H ≤ C` | `extension_rates(H).amount` | `U + H` |
| `cycle` and `U = C` | `rates(type, hourly, H).amount` (regular rate) | `H mod C` |
| `cycle` and `U < C < U + H` | `extension_rates(C − U).amount + rates(type, hourly, U + H − C).amount` | `U + H − C` |
| `per_hour` | `extension_per_hour_amount × H` | unchanged |
| `block` | `extension_rates(H).amount` | unchanged |

- At check-in `cycle_hours_used` = the hours bought (12 for a 12-hour stay; 0 for overnight and long stay, which extend with the `block` rule regardless of the setting).
- Only hour options whose every needed rate exists are offered. If none exist the Extend screen shows the missing-rate message (52).
- Worked example (mockup 12): Deluxe, 12 hours used of 24. +6 h → ₱250 (extension rate), used becomes 18. +24 h crosses the cycle → ₱400 (extension rate for the 12 hours left in the cycle) + ₱650 (regular 12-hour rate for the overflow) = ₱1,050, used becomes 12.

### 4.4 Transfer pricing (`PricingService::quoteTransfer`)

Difference = price of the same stay option in the new room type at today's rates − the stay's current `room` line amount (plus earlier transfer differences). Discounts are not re-applied. Positive → collect; negative → guest deposit credit; zero → nothing. The expected check-out does not change.

### 4.5 Cash maths (`CashMath`)

```
expected_cash = opening_cash
              + Σ payments.amount            where method = 'cash' and shift = this
              − Σ cash_movements.amount      where kind in (expense, remittance) and not voided
              + Σ cash_movements.amount      where kind = shortage_payment and not voided
variance      = counted_cash − expected_cash          (negative = short)
```

Roll-forwards printed on every shift report (37) and asserted in tests:

```
guests:   forwarded_in + checked_in − checked_out            = forwarded_out
deposits: held_open + taken − returned − forfeited − applied = held_close
unpaid:   forwarded_in + charged − collected                 = forwarded_out
```

`collected` in the unpaid roll-forward is the sum of payment allocations made in the shift (all methods, including `deposit_applied`).

### 4.6 Business day

A business day is all shift sessions of a branch with the same `business_date`. `business_date` is the local date on which the shift's defined start time falls: a PM shift opened at 7:54 PM or at 8:20 PM on 11 Nov, or even at 12:10 AM on 12 Nov after a late handover, is the PM shift of 11 Nov because it is the next period after the AM shift of 11 Nov. `OpenShift` proposes the period following the last closed shift; the user may change it only when no shift has ever been closed or with a supervisor PIN.

### 4.7 Housekeeping queue

Order by `queued_at`. Urgent when waiting ≥ `cleaning_urgent_minutes`. Startable tasks = the first N in the queue for the user's floors, N = housekeepers of those floors with `last_seen_at` in the last 2 minutes (minimum 1). One in-progress task per housekeeper.

### 4.8 Kiosk room rotation (`RoomRotation`)

Among rooms of the type that are `available`, `is_kiosk_enabled`, with no active stay and no reservation arriving within the requested period: never-used first (`last_checkout_at IS NULL`), then oldest `last_checkout_at`, then natural sort of `number`.

### 4.9 Rules for cases the mockup does not draw

These were settled during the cross-check of the documents. They are requirements like any other.

| Case | Rule |
|---|---|
| Supervisor at the front desk | A supervisor's home is Today (62). A supervisor may do front desk work on the open shift without joining it and does not count toward `max_frontdesks_per_shift`. Only `frontdesk` users must be shift members. |
| Housekeeping module off | On check-out and transfer-out the room goes straight to `available` and no `cleaning_tasks` row is created. |
| Lost key and lost remote | Priced from `branches.lost_key_amount` and `lost_remote_amount`. They are not products. Other damages come from products of kind `damage`. |
| Discount lines | A `discount` line is never paid on its own. The payment for the room is allocated `+room amount` to the room line and `−discount` to the discount line, so both count as paid. |
| "Not yet" during close | Before the cash count is saved (22), the shift returns to `open`. After the count is saved the close cannot be abandoned; the wizard reopens at the step it reached. |
| Changing the proposed shift period | Needs a supervisor PIN; `approvals.action = 'change_shift_period'`. |
| Reset a stuck cleaning (49) | Action `ResetCleaning`: the stuck task becomes `reset`, a new `queued` task is created for the same room and stay with the same `queued_at` and `due_at`, and the room goes `cleaning → uncleaned`. |
| Force close (49) | Action `ForceCloseShift`: a supervisor or admin counts the drawer using the count form of screen 23, then the shift is frozen as in DATABASE_SCHEMA §8.10 step 5 with `is_force_closed = 1`. |
| Release a kiosk hold (5, 7) | Action `ReleaseKioskHold`: stay `held → cancelled` after a ConfirmDialog. No approval needed; no money is involved. |
| Reservation cancel and no-show money | Actions `CancelReservation` and `RefundNoShow`, each with reason and approval. Money paid ahead is refunded (`deposit_refund`) or kept (`forfeited_deposit` line + `deposit_applied`). A `no_show` reservation whose money has not been settled can still be checked in if a room is free. |
| Create branch (56) | Copies `shift_definitions` with the settings, and `discounts` with `discount_rate` together with the rates. The first admin gets a temporary password shown once after saving and must change it and set a PIN at first sign-in. |
| First owner account | Created with `php artisan homi:create-owner` on the server. Owners manage other owner accounts from the owner menu. |
| Inactive branch | The owner can set an `inactive` branch back to `active`. |
| "Needs attention" thresholds (49) | In `config/hotel.php`: stay overdue by 60 minutes; shift open 120 minutes past its end time; cleaning in progress for 60 minutes. |

Screens built from shared components without their own mockup frame (listed in APP_FLOW §8): set your PIN at first sign-in; change temporary password; admin reset of password and PIN (on 47); owner accounts list and form; receive stock and record wastage forms (on 20); list of shifts for reports (`/reports/shifts`); reservation detail with cancel and no-show settlement (on 57); force-close count (on 49).

### 4.10 Z numbers, grand total and the day close

- Every closed shift gets the next `z_no` of its branch and a chained grand total, in the closing transaction (DATABASE_SCHEMA §8.10). Shown as `Z-0629`. A force-closed shift gets one too.
- `grand_total_after = grand_total_before + net sales of the shift`. Net sales already include reversals posted in that shift, so a void made later lowers the grand total of the shift it was made in, never an earlier one.
- The Z-report (37b) is built by `ReportService::zReport()` as **one array of sections and lines** that feeds the screen, the 80 mm slip, the PDF and the CSV. No format computes a figure of its own.
- The "Sales and cash" column is, in this order: gross sales; each discount by name; **net sales**; unpaid forwarded out; paid for earlier shifts; **collected**; each non-cash method; paid from deposit; **cash collected**; deposits taken; deposits returned; expenses; remittance; shortage payments (when not zero); **cash from this shift**; opening cash; **expected**; counted; short or over. Each bold line must equal the arithmetic of the lines above it; a unit test asserts this on the demo shift.
- The exceptions list has a fixed set of rows, printed even at zero: cancelled stays, voided lines, cash refunds, room transfers, forfeited deposits, voided cash entries, slip reprints, and "force closed" when it applies.
- For an open shift the same page is a preview: built live from the ledger, watermarked "Preview. Do not sign.", with no Z number. It cannot be exported (`PreviewNotExportable`). The preview shows expected cash, so it is open to admin, back office and owner, and to a supervisor only if they have recorded no money rows on that shift; front desk users cannot open it.
- Day close (38b): rules in DATABASE_SCHEMA §8.10b. A day's paper is the sum of its shifts' frozen totals.
- VAT: only when `branches.vat_enabled`. A sale is exempt when its discount has `discounts.is_vat_exempt = 1` (seeded on for Senior citizen and PWD). The day Z sums its shifts' VAT figures. Prices are VAT-inclusive; `vatable = net_sales / (1 + rate)`, `vat = net_sales − vatable`, computed per line and summed, with senior and PWD discounted sales reported as exempt. Until a branch turns it on, no VAT code path runs.
- Every print and export writes a `print_logs` row; a second print of the same document is marked `is_reprint`.

### 4.11 Dashboards, stats and summaries

- Dashboards (60, 61) and Stats (63) read **only** the summary tables (DATABASE_SCHEMA §4.16), plus "live state" read directly and bounded to now: the open shift and its members, each member's check-ins and collections on that open shift (one grouped query on `payments` and `stays` by shift id, sent to admin, back office, supervisor and owner only), rooms by status, in-house stays, kiosk holds. They never aggregate `folio_lines` or `payments` in a request. Today (62) is all live state and shows no money.
- `RebuildSummaries` runs every minute: for each dirty branch-day it recomputes the day from the ledgers and replaces that day's rows in one transaction. Besides ledger inserts, a day is marked dirty when a stay is checked in or out, a cleaning task is finished, or a shift is closed or force-closed, so counts that are not ledger rows are also refreshed. `rebuilt_at` is the start time of the run, and "dirty" is `dirty_at >= rebuilt_at`, so a row written during a rebuild is picked up by the next one.
- `CheckSummaries` runs every 10 minutes for today and yesterday and logs an error if `Σ payments ≠ Σ daily_payment_summaries`, `Σ folio_lines ≠ daily_summaries.net_sales_amount`, or a summary is more than 10 minutes older than the newest ledger row.
- Every dashboard figure is an Inertia deferred prop with a skeleton. The page reloads its figures every 30 seconds with a partial reload, skips the reload while the tab is hidden or a request is in flight, and shows "Could not refresh. Retry" on failure. Polls do not count as activity for the session lifetime.
- Sign-in facts (screen 1): a successful sign-in sets a one-year cookie `homi_branch` with the branch id. When it is present the sign-in page shows four counts for that branch and nothing else: rooms available, guests in house, due out within `due_soon_minutes`, and reservations arriving today. No names, no money.
- Photographs (1, 27, 59): served from `public/images/photos/` as JPEG at 2000 px and 1200 px with `srcset`, lazy-loaded below the fold, behind a flat scrim; never hotlinked in production. Sign-in picks the morning, afternoon or evening photo from the part of the day.
- Motion on 1, 27 and 59 is CSS only (photo drift, hero cross-fade every 7 s, one fade-up on load) and is switched off under `prefers-reduced-motion`.
- Welcome page (59): lists branches with `welcome_enabled = 1` and `status = active`; headline and sub-line come from `config/hotel.php` (`welcome.headline`, `welcome.subline`); prices are the lowest hourly and overnight rate per room type.
- Each card states its period. The change figure compares with the same-length period immediately before; for "today" it compares with yesterday **up to the same time of day**, never a part day against a full day. No previous data → no change figure (not "+100%").
- Short and over are summed separately and never netted.
- "Needs attention" rows are built by one class (`AttentionList`), each with a severity, a sentence and a route; the dashboards show the top rows and screen 49 shows all. Empty state: "All good."
- Stats grain follows the range: up to 2 days by hour, up to 62 days by day, longer by month.
- Front desk users never receive collected amounts or expected cash for an open shift in any Inertia prop: the server omits them (not just the template). An arch test asserts front desk pages carry no `expected_cash`.
- Charts are small Vue components of our own (`VBars`, `HBars`, `Shares`, `HeatGrid`, `Spark`) using theme tokens; hover shows the value; a chart with no data shows a plain empty state.

### 4.12 Shift safeguards

| Rule | Detail |
|---|---|
| Who did it | Every `folio_lines`, `payments`, `cash_movements`, `pos_orders` and `stock_movements` row records `created_by` = the person signed on at that computer (after a PIN switch, that person), as well as the shift. "By front desk" on 37b comes from these. |
| Papers check list (22) | Counts only, no peso amounts: expense receipts, remittance slips, non-cash slips, cancelled stays and voids, key deposits taken and returned. |
| Blind count | Until `cash_counts` is saved, no response to the outgoing team contains `expected_cash` or any figure it can be derived from. |
| Count draft | The pieces being typed are saved to `cash_count_drafts` after each change (debounced 1 s, `PUT /shift/close/draft`) and restored on reload. The draft row is deleted in the transaction that saves `cash_counts` (step 2). The server computes the total from the pieces and ignores any total sent. |
| Separation of duties | The person who counted cannot be the receiver in step 4 and cannot approve their own variance. At a normal handover the receiver's PIN acknowledges the variance and no approval row is written. When there is no receiver (close without handover, force close) and the variance is not 0, a supervisor or admin other than the counter signs it off with `approvals.action = 'approve_variance'`. |
| Closing with a colleague signed on | When one member starts the close, the other member's next write is refused with `ShiftClosing` and their unsaved form is kept on screen. |
| Double taps | Cash movement, payment, check-in, hold and POS forms carry an idempotency key. |
| Cash-out cap | An expense or remittance greater than the cash expected in the drawer at that moment is refused (`NotEnoughCash`). The message says only "There is not that much cash in the drawer" and never states an amount, so it does not reveal the expected cash. |
| Reasons | Reason lists (`reasons`, `transfer_reasons`, `expense_categories`) carry `needs_note` and `needs_manager`; the form enforces both. A picked reason is stored on the row it explains (DATABASE_SCHEMA §4.16, "Where a reason is stored"). When `needs_manager` is set and the action does not already need an approval, a supervisor PIN is asked with `approvals.action = 'reason_requires_manager'`. |
| PIN lockout | Every PIN try is logged per computer (`pin_attempts`). Ten wrong tries in a row lock PIN entry on that computer (`pin_locks`, error `PinLocked`) until a supervisor or admin unlocks it by signing in with their own username and password on the unlock form (`POST /pin/unlock`); the unlock is recorded on `pin_locks.unlocked_by` and in `audit_logs`, not in `approvals`. Three wrong tries on switch-user still fall back to the full sign-in. |
| Stale shift | A shift more than `shift_stale_block_minutes` (default 240) past its defined end refuses new payments and charges (`ShiftStale`) until it is closed. The warning bar (54) appears from `shift_overdue_warn_minutes`. |
| Kept from Homi's own design | One shared drawer per shift, the count at every shift change, and the incoming front desk accepting the drawer by PIN. Euphoria's remit-everything, one-close-per-day model is not used: a hotel runs 24 hours and holds guest deposits in the drawer. |

## 5. Data requirements

| Topic | Requirement |
|---|---|
| Transactions | One per action (see DATABASE_SCHEMA §8). Isolation level default (REPEATABLE READ). Lock order: shift → rooms by id → stay → products by id → branch counter last (DATABASE_SCHEMA §8). |
| Deadlocks | Actions are wrapped in `DB::transaction($fn, attempts: 3)`. |
| Uniqueness errors | `stays_one_active_per_room` → `RoomTaken`; `shift_sessions_one_open` → `ShiftAlreadyOpen`; idempotency keys → return the existing result. Caught by error code 1062 and constraint name. |
| IDs | Auto-increment. Public codes (`stays.code`) from the per-branch counter. |
| Dates | Stored UTC, converted at the edge by a single `Clock::toLocal()` helper and a Vue `formatDateTime()` helper. Business dates are `DATE` in local time and computed once. |
| Migrations | As listed in DATABASE_SCHEMA §6. `migrate:fresh --seed` must work at every commit. |
| Backups | Nightly `mysqldump` to a second disk or free cloud storage by cron; 14 daily copies kept. Documented in START_HERE. |

## 6. Platform specifics

| Area | Requirement |
|---|---|
| Exports | Z-report and day Z: PDF (dompdf, A4, from a Blade view that renders the same builder array) and CSV (streamed, UTF-8 BOM, columns Section / Line / Count / Amount). Other reports and the tables on Stats (63): CSV. Routes: `GET /reports/shifts/{id}/z.pdf`, `/reports/shifts/{id}/z.csv`, `/reports/daily/{date}/z.pdf`, `/reports/daily/{date}/z.csv`, and `?export=csv` on the other report and stats routes. Exports need the reports permission and are logged. |
| Printing | Browser print (`window.print()`) with a print stylesheet sized for 80 mm thermal paper (72 mm printable width, 11 pt monospace). Slips: check-in (8), receipt (18), shift report (26). No printer drivers or ESC/POS in version 1. A4 print stylesheet for reports 37–43. |
| Kiosk | A normal web page in Chrome `--kiosk` mode, signed in as a `kiosk` user with a 30-day remember token. No on-screen browser chrome; idle reset to screen 27 after 60 s. Touch targets ≥ 56 px. |
| Phone (housekeeping) | Same web app, `PhoneLayout`. No install, no push notifications; the queue polls every 20 s. |
| Live updates | Polling with Inertia partial reloads: board 15 s, kiosk holds 5 s, housekeeping 20 s, kiosk availability 20 s. No websockets. |
| Offline | Not supported. A global connection bar (50) appears when a heartbeat request fails; Save buttons are disabled while it shows. |
| Payments | Cash, GCash and card are **recorded**, not processed. GCash/card need a reference number. No gateway. |
| Scheduled jobs | `RebuildSummaries` every minute · `CheckSummaries` every 10 minutes · `ExpireKioskHolds` every minute · `ReleaseNoShows` every 5 minutes · `ReconcileBusinessDay` daily 09:00 Asia/Manila · `CheckInvariants` daily 09:05. |

## 7. Performance budgets

| Budget | Target | How it is measured |
|---|---|---|
| Room board first load (300 rooms) | ≤ 800 ms server time, ≤ 6 queries | Pest test with query counter on a 300-room seed |
| Room board refresh | ≤ 300 ms, partial reload only | Same test, `X-Inertia-Partial-Data` |
| Any action (check-in, payment, check-out) | ≤ 500 ms server time | Pest timing assertion on seeded data |
| Freezing a shift at handover (close step 4) with 300 in-house stays | ≤ 2 s | Pest test |
| Shift report and Z-report for a closed shift | ≤ 500 ms (reads frozen `totals` and snapshot) | Pest test |
| Daily and owner summary, 1 year of data | ≤ 1.5 s | Pest test on a generated year |
| JS bundle (front desk entry) | ≤ 350 kB gzip (includes Nuxt UI) | `vite build` report |
| Dashboard (60, 61) first response | ≤ 300 ms; each deferred card ≤ 300 ms, ≤ 3 queries | Pest test with query counter |
| `RebuildSummaries` for one busy branch-day | ≤ 2 s | Pest test on a generated day |
| Stats (63) for one year | ≤ 1 s | Pest test on a generated year |
| No N+1 | `Model::preventLazyLoading()` on in non-production | Always on in tests |

## 8. Reliability and error handling

| Failure | Handling |
|---|---|
| Validation error | 422 with field errors shown under the fields (FormRequest). |
| Domain error (`RoomTaken`, `RoomNotAvailable`, `HoldExpired`, `RateNotFound`, `InsufficientStock`, `NoOpenShift`, `ShiftAlreadyOpen`, `ShiftClosing`, `ShiftStale`, `NotEnoughCash`, `BalanceNotZero`, `NotAvailable`, `PinInvalid`, `PinLocked`, `DayNotClosable`, `PreviewNotExportable`) | Subclasses of `DomainException` with a user message; rendered as a dialog or inline alert. Transaction rolled back; nothing half-saved. |
| Unexpected exception | Rolled back, logged with user, branch, route and request id; user sees "Something went wrong. Nothing was saved." with the request id. |
| Session expired | Redirect to sign-in. The shift stays open; nothing is closed or freed. |
| Lost connection | Connection bar (50); retries every 5 s. |
| Invariant broken | `CheckInvariants` writes `audit_logs` rows (`invariant.failed`) and the item appears on screen 49. It never auto-fixes. |

Invariants checked nightly (and after every Feature test via a helper):

1. At most one open shift per branch.
2. Every room with status `occupied` has exactly one `checked_in` stay pointing at it, and every `checked_in` stay's room is `occupied`.
3. No `checked_in` stay without a `room` folio line.
4. Every checked-out stay has balance 0 and deposit held 0.
5. `products.stock_on_hand` equals the last `stock_movements.balance_after`.
6. For every closed shift: recomputed totals equal stored `totals`; the three roll-forwards balance.
7. For every business day: sum of shift totals equals the day recomputed from the ledger.
8. Shift N's forwarded-out rows equal shift N+1's forwarded-in rows (same set of stays, same amounts).

## 9. Security and privacy

- Passwords: bcrypt, minimum 8 characters. PINs: 4 digits, bcrypt, never shown; 3 wrong PINs on switch-user fall back to full sign-in; 5 wrong PINs on an approval lock approvals for that approver for 15 minutes. This per-person lock and the per-computer lock of §4.12 (10 wrong tries in a row on one computer) both apply; whichever is reached first takes effect.
- Login throttling: 5 failures → locked 15 minutes (`users.locked_until`).
- Sessions: database driver, 12-hour lifetime, `secure` and `http_only` cookies, CSRF on every form.
- Authorisation: policies on every controller method; branch scope on every query; owner branch switch stored server-side.
- Kiosk: a hold writes no money, so it does not need an open shift (confirming it at the front desk does). The kiosk role can only reach `/kiosk/*`; all prices are computed on the server; a kiosk can only create `held` stays.
- Approvals: the approver must be a different user from the requester, with role supervisor or admin of that branch (or owner).
- Guest data kept: name, optional mobile, optional plate number. No IDs or photos. Mobile numbers are shown only on the folio and reservation screens.
- Logs never contain passwords, PINs or full mobile numbers.
- HTTPS required in production; HSTS on.

## 10. Testing requirements

| Level | Tool | Scope |
|---|---|---|
| Unit | Pest | `PricingService`, `CashMath`, `Money`, `RoomRotation`, enums, `ReportService::compute*` |
| Feature | Pest + MySQL | Every Action: happy path, each domain error, permission denied, idempotency, concurrency where listed |
| Reconciliation | Pest | Invariants 1–8 after scripted multi-shift scenarios |
| Architecture | Pest arch | No `now()` in `app/`; controllers do not use `DB`; models use `BelongsToBranch`; no float money casts |
| Component | Vitest | Shared components: MoneyInput, PinPad, OptionTiles, RoomTable, ConfirmDialog |
| Manual | Checklist in ROADMAP per phase | Compare each built screen with its mockup number |

Minimum test cases (each is one named test):

**Pricing** — hourly 6/12/18/24 per type · overnight 1 and 3 nights with check-in before and after midnight · long stay 1 and 31 days, 32 refused · fixed discount, percent discount with rounding, discount on a non-eligible rate refused · missing rate throws · extension: within cycle, exactly at cycle end, crossing the cycle, `per_hour`, `block`, missing extension rate throws · transfer upgrade, downgrade, same price.

**Stays** — check-in writes stay, room line, payment, key deposit and room status in one transaction · check-in with deposit disabled · excess kept as guest deposit · two simultaneous check-ins to one room: exactly one succeeds · repeated idempotency key returns the same stay · kiosk hold expires · confirm after expiry refused · add charge reduces stock; out-of-stock refused · partial payment pays oldest line first · pay from guest deposit; key deposit refused · void line restores stock and refunds · cancel stay reverses everything and frees the room · check-out blocked with balance · lost key taken from deposit · unclaimed deposit forfeited · double check-out is a no-op · transfer leaves the original room line untouched.

**Shifts** — open first shift · second open refused by the database · join as second member; third refused · logout and session expiry leave the shift open · payment stamped with the open shift regardless of who is signed in · expected cash formula with every movement kind · voided expense excluded · variance requires a note · close freezes totals; later void posts to the new shift and the old totals are unchanged · handover opens the next shift atomically with the received cash · close without handover needs approval · forwarded-out of shift N equals forwarded-in of shift N+1 · charge in shift A paid in shift B: sale in A, cash in B, unpaid roll-forward balances in both · a shift that runs 40 minutes late keeps all its transactions.

**Reports** — shift report equals ledger · Z-report gross equals shift sales; floor columns sum to total; "No room" equals walk-in POS · day = sum of its shifts · owner summary = sum of branches · occupancy: start + in − out = end · stock: opening + in − sold − wastage = closing, and closing = next opening · reconcile job flags a tampered row.

**Z-report and day close** — Z numbers are sequential per branch with no gaps or repeats under concurrent closes · grand total chains across shifts · every bold line of the sales-and-cash column equals its arithmetic · exceptions list has all fixed rows at zero · preview has no Z number and cannot be exported · a later void changes the later shift's paper and grand total, not the earlier one · day close refused while a shift is open, out of order, or after a reconcile mismatch · PDF and CSV carry the same figures as the screen · every print is logged and reprints are marked.

**Dashboards and summaries** — rebuild equals the ledgers for a scripted day · a row written during a rebuild is picked up next run · `CheckSummaries` flags a tampered summary · same-time comparison for today · no change figure without previous data · short and over not netted · front desk props never contain expected cash or collected amounts · owner sees all branches, admin only their own · query budget per card.

**Shift safeguards** — receiver cannot be the counter · count draft survives reload and is deleted at confirm · server total ignores a forged total · second member refused while closing · double-tapped expense saves once · expense above expected cash refused · reason needing a note or manager is enforced · ten wrong PINs lock the computer and a supervisor unlocks it · stale shift refuses payments.

**Housekeeping** — check-out creates one task with the right deadline · only first N startable · second start by same user refused · finish sets room available · finish refused when room has an active stay · late flag · problem → maintenance.

**Kiosk and reservations** — rotation order · kiosk cannot send a price · hold blocks the room for walk-in · reservation blocks overlapping dates · no-show release · money paid ahead applied at check-in.

**Branches and permissions** — a branch admin cannot read another branch · owner can open any branch · each role is refused on every other role's routes · create branch copies rates, settings, products and lists, not rooms or users.

## 11. Build and release

| Step | Command / rule |
|---|---|
| Install | `composer install && npm ci` |
| Dev | `composer run dev` (serve + queue + vite) |
| Before "done" | `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && php artisan test && npm run test && npm run build` — all green |
| Deploy | `git pull` → `composer install --no-dev -o` → `npm ci && npm run build` → `php artisan migrate --force` → `php artisan optimize` → restart queue worker |
| Environments | `local`, `staging` (copy of production settings, demo data), `production` |
| Versioning | `v1.0.0` at first deployment; tag every deploy |
| Rollback | Redeploy the previous tag. Migrations are forward-only; a bad migration is fixed by a new one. |

## 12. Coding conventions

- PSR-12 via Pint (Laravel preset). `declare(strict_types=1)` in every PHP file. Typed properties and return types everywhere.
- Naming: Actions are verb-first (`CheckInGuest`), Queries end in `Query`, Form Requests end in `Request`, enums are singular nouns.
- Controllers are resourceful where possible; one invokable controller per odd action.
- Vue: one page per route under `resources/js/Pages`, shared parts under `Components`, composables under `Composables`. Props typed with `defineProps<{}>()`. No business maths in components; amounts arrive as integer centavos and are formatted by `formatMoney()`.
- Use Nuxt UI components first (`UButton`, `UInput`, `UFormField`, `UModal`, `USkeleton`, `UDropdownMenu`, toasts through `UApp`); write a component of our own only for what Nuxt UI does not have (RoomTable, MoneyInput, PinPad, StatCard, charts, Slip). Tables are plain `<table>` with the `.dtable` style, as in Euphoria.
- Only design tokens for colour, radius and spacing; no hex values in pages.
- Every screen's root component has a comment with its mockup number, e.g. `<!-- Mockup 12 -->`.
- Commits: imperative subject, reference the roadmap task, e.g. `Phase 3: ExtendStay action and tests`.
- No `dd()`, `dump()`, `console.log` or commented-out code in commits (Pint and an arch test).
