# Roadmap — Homi

| | |
|---|---|
| Version | 1.1 · 11 November 2026 (mockup v3: Nuxt UI, dashboards, Z-report, shift safeguards) |
| Related | [PRD](PRD.md) · [TRD](TRD.md) · [DATABASE_SCHEMA](DATABASE_SCHEMA.md) · [SYSTEM_DESIGN](SYSTEM_DESIGN.md) · [APP_FLOW](APP_FLOW.md) · [DESIGN_SYSTEM](DESIGN_SYSTEM.md) |

Build one phase at a time, in order. A phase is done only when every box is ticked, its "Done when" line is true, and the full check in [TRD §11](TRD.md) passes. Screen numbers are mockup numbers (1–63 plus 2b, 4b, 4c, 31b, 37b, 37c, 38b). Feature IDs (F1–F18) are from [PRD §5](PRD.md).

| Phase | Name | Features | Screens |
|---|---|---|---|
| 0 | Setup | — | — |
| 1 | Design system and components | — | Building blocks |
| 2 | Data layer | all | — |
| 3 | Core logic with tests | F2, F4–F7, F15 | — |
| 4 | Front desk core flow | F1, F3–F7, F15, F16, F17 (62) | 1–18, 50–53, 62 |
| 5 | Shift cash, close and handover | F2 | 21–26, 54 |
| 6 | POS, stock and housekeeping | F8, F9 | 19, 20, 32–35 |
| 7 | Kiosk and reservations | F10, F11 | 27–31b, 57, 58 |
| 8 | Reports | F12, F15 (43) | 36–38, 37b, 37c, 38b, 40–43 |
| 9 | Admin, owner, dashboards and stats | F13, F14, F17, F16 (49) | 39, 44–49, 55, 56, 60, 61, 63 |
| 10 | Polish, printing and release | F18, F16 | 59, then all |

---

## Phase 0 — Setup

- [x] Create the Laravel 13 project in this folder (keep `docs/`, `CLAUDE.md`, `START_HERE.md`)
- [x] Install Inertia 3 + Vue 3 + Vite 8, Tailwind 4, Ziggy, qrcode
- [x] Install and configure Nuxt UI v4 (`@nuxt/ui` ^4) for Vue + Vite + Inertia: Vite plugin, CSS import, `UApp` at the root of every layout; Lucide icons come with it
- [x] Install `@vueuse/core` and `date-fns`
- [x] Install `barryvdh/laravel-dompdf`; a test renders a one-line Blade view to a PDF response
- [x] Install Pest 4, Pint, Larastan (level 6), Vitest 5 + Vue Test Utils
- [x] `.env.example` for MySQL `hotelv4` and `hotelv4_test`; `phpunit.xml` pointing tests at `hotelv4_test`
- [x] Queue, cache and session on the database driver
- [x] `config/hotel.php` with the constants in TRD §3.9
- [x] `App\Support\Clock` bound in the container; `App\Support\Money` with its Eloquent cast
- [x] `composer run dev` script (serve + queue + vite)
- [x] Arch tests: no `now()` in `app/`, controllers do not use `DB`, no `dd`/`dump`
- [x] No chart, table or date-picker library in `package.json` (TRD §2)
- [x] `git init`, `.gitignore`, first commit

**Done when:** a blank Inertia page renders at `/` with one Nuxt UI button on it, `php artisan test` and `npm run test` run green with the arch tests, and the full check passes.

## Phase 1 — Design system and components

- [x] Theme Nuxt UI with the Homi tokens: Tailwind theme with every token in DESIGN_SYSTEM §2–4, Nuxt UI primary and neutral colours mapped to them; self-hosted fonts per ASSETS
- [x] Helpers: `formatMoney`, `formatDateTime`, `formatDuration` (DESIGN_SYSTEM §7)
- [x] Nuxt UI components first (TRD §12): `UButton`, `UInput`, `UFormField`, `UModal`, `USkeleton`, `UDropdownMenu`, toasts through `UApp`; set their sizes, radius and states once in the theme, not per page
- [x] Our own components, only where Nuxt UI has none, each with all variants and states: MoneyInput, OptionTiles, StepBar, StatusLabel (small soft label), RoomTable, MoneyRow, TotalRow, StatCard, PinPad, ConfirmDialog, EmptyState (side sheets use Nuxt UI USlideover), Slip
- [x] Tables are plain `<table>` with the `.dtable` style (no table library)
- [x] Chart kit in `Components/Charts`: `VBars`, `HBars`, `Shares`, `HeatGrid`, `Spark` — theme tokens only, value on hover, plain empty state when there is no data
- [x] Layouts: FrontDeskLayout, BackOfficeLayout, AdminLayout, OwnerLayout, KioskLayout, PhoneLayout (static nav for now)
- [x] Print stylesheets: 80 mm slip and A4 report
- [x] Download the six chosen photographs (ASSETS) at the listed sizes, plus the copy named for each room type into `public/images/photos/`; self-host IBM Plex Sans, IBM Plex Mono and Newsreader
- [x] Component gallery at `/dev/components` (local only) showing every Nuxt UI component we use, every component of our own and every chart, in every state
- [x] Vitest tests for MoneyInput, PinPad, OptionTiles, RoomTable, ConfirmDialog, and one per chart (renders values, renders the empty state)

**Done when:** the gallery matches the mockup's "Building blocks" section and the cards and charts of screens 60–63 side by side, including disabled, loading, error and empty states, at desktop, kiosk and phone widths.

## Phase 2 — Data layer

- [x] All 31 migrations in the order of DATABASE_SCHEMA §6, with foreign keys, CHECK constraints and generated columns
- [x] The version 1.1 tables (migrations 000026–000031, DATABASE_SCHEMA §4.16): `day_closings`, `cash_count_drafts`, `reasons`, `pin_attempts`, `pin_locks`, `print_logs`, and the summary tables `summary_days`, `daily_summaries`, `hourly_sales_summaries`, `daily_payment_summaries`, `daily_report_lines`
- [x] The version 1.1 columns: `branches` Z counters, `shift_stale_block_minutes`, VAT and welcome settings (§4.1); `shift_sessions.z_no` and the grand totals (§4.7); `needs_note` / `needs_manager` on the reason lists (§4.6); `cash_movements.reason_id` and `idempotency_key` (§4.9)
- [x] Enums in `app/Enums` for every ENUM column
- [x] Models with `$fillable`, casts, relationships (DATABASE_SCHEMA §6), `BelongsToBranch`, `AppendOnly` (including `pin_attempts` and `print_logs`); models for the new tables
- [x] `CurrentBranch` service and global scope; arch test that operational models use the trait
- [x] Factories for every model
- [x] Seeders in DATABASE_SCHEMA §5, including `DemoShiftSeeder`
- [x] Tests: each unique/generated-column rule rejects a violating insert (second active stay in a room, second open shift, second in-progress task per housekeeper, repeated `z_no` in a branch, second day closing for a date, second open PIN lock for a computer); append-only models refuse update and delete; CHECK constraints reject bad rows

**Done when:** `php artisan migrate:fresh --seed` builds "Hotel Dalisay GenSan" with 46 rooms and the demo shift, all 31 migrations run, and the constraint tests pass on MySQL.

## Phase 3 — Core logic with tests

No screens in this phase. Everything is driven by tests.

- [ ] `PricingService` (TRD §4.2–4.4) with every pricing test in TRD §10
- [ ] `CashMath` (TRD §4.5) and `RoomRotation` (TRD §4.8) with unit tests
- [ ] `CurrentShift`, `RoomStatusService` (transitions in APP_FLOW §5.2, with log rows)
- [ ] `Auth/VerifyPin`, `Auth/ApproveByPin` (approver ≠ requester, role check, lockout)
- [ ] PIN attempts and locks with the approvals (TRD §4.12): every try writes `pin_attempts` for the computer; ten wrong tries in a row write `pin_locks`; a locked computer refuses every PIN; a supervisor or admin unlocks it with their own password (`approvals.action = 'unlock_pin'`)
- [ ] Shift actions needed by the rest: `OpenShift`, `JoinShift`
- [ ] Stay actions: `CheckInGuest`, `AddCharges`, `TakePayment`, `ExtendStay`, `TransferRoom`, `VoidLine`, `CancelStay`, `CheckOutGuest` (DATABASE_SCHEMA §8.1–8.9); every money row records `created_by` as well as the shift
- [ ] `StayQuery` (folio, balance due, deposit held, in-house)
- [ ] Domain exceptions in TRD §8 with user messages
- [ ] Every "Stays" test in TRD §10, including the two-simultaneous-check-ins test and idempotency
- [ ] Invariants helper (TRD §8, items 1–5) run after every Feature test

**Done when:** a scripted test can open a shift, check in, add charges, pay partly, extend, transfer, void a line and check out, and afterwards the balance is 0, deposits held are 0, the room is `uncleaned`, and all invariants hold; ten wrong PINs lock the test computer and a supervisor unlocks it.

## Phase 4 — Front desk core flow

- [ ] Sign in (1) as the split screen: time-of-day photograph and greeting, white sign-in panel, fade-in and photo drift with reduced-motion support, lockout, sign out
- [ ] Sign-in facts for the remembered branch: four counts and the open-shift line on a computer that has signed in before; greeting only on a new computer; no money and no guest names
- [ ] Role-based redirect to the role homes in APP_FLOW §1.1; a home built in a later phase (60, 61) gets a plain placeholder page until then
- [ ] Role middleware and policies (TRD §4.1); no-permission page (51)
- [ ] Navigation built on the server: the menu items sent to each layout come from the role, the branch's module flags and the open branch, never from a list in Vue
- [ ] Open shift (2) and join shift (2b); front desk routes require shift membership
- [ ] Switch user by PIN (3) with the tries-left count and the locked-computer state; first-sign-in "set your PIN" step
- [ ] Top bar with branch, shift chip, user, Close shift button (button wired in phase 5)
- [ ] Front desk "Today" (62): arriving, due out, overdue, unpaid folios, kiosk holds, leaving-soon and arriving lists, rooms by status; counts and names only; arch test that its props carry no money
- [ ] Room board (4) with `BoardQuery`, 15 s polling, search, floor filter, loading (4b) and empty (4c) states
- [ ] Walk-in check-in (6) with stay options, discount, payment method, change or keep as deposit, idempotency key
- [ ] Checked-in slip (8) with 80 mm print
- [ ] Guest folio (9); add charge sheet (10); take payment (11)
- [ ] Extend (12); transfer (13); supervisor approval sheet (14); cancel stay and void line (15)
- [ ] Check-out wizard (16, 17, 18)
- [ ] Problem states: connection bar (50), missing rate (52), room just taken (53)
- [ ] Feature tests for each controller: happy path, validation, permission
- [ ] Manual pass: each screen compared with its mockup frame

**Done when:** a front desk user signs in, lands on Today (62), and can run a full guest stay to check-out in the browser using only screens 1–18 and 62; two browsers racing for one room produce screen 53 for the loser; no front desk page receives a sales or cash figure; and nothing in the flow needs the database to be edited by hand.

## Phase 5 — Shift cash, close and handover

- [ ] Shift cash screen (21): expense, remittance, shortage payment; void with reason and approval
- [ ] `RecordCashMovement`, `VoidCashMovement`; idempotency key on the form (a double tap saves one row); cash-out cap (an expense or remittance above the cash expected in the drawer is refused)
- [ ] Reason lists on the shift forms: `reasons` and `expense_categories` with `needs_note` and `needs_manager` enforced by the form
- [ ] Close step 1 check (22) with `StayQuery::inHouse` and the pending list; papers check list as counts only, no peso amounts; shift goes to `closing`
- [ ] Second member refused while closing: their next write gets `ShiftClosing`, their form stays on screen, and they see who is closing
- [ ] Close step 2 blind count by denomination (23): no response to the outgoing team carries `expected_cash` or a figure it can be derived from before `cash_counts` is saved; the server adds up the pieces and ignores any total sent
- [ ] Count draft: pieces saved to `cash_count_drafts` after each change (debounced 1 s), restored on reload, deleted at confirm
- [ ] Close step 3 result (24): expected cash line by line, variance, required explanation; the person who counted cannot approve their own variance (`approve_variance`)
- [ ] Close step 4 handover (25): the receiver cannot be the person who counted; `CloseShift` freezes `totals` and writes `shift_forwarded_stays`; `HandOverShift` opens the next shift in the same transaction; "amount is different" path; close without handover with approval
- [ ] Z number and grand total at close: `next_z_number` taken with the branch row locked, `z_no`, `grand_total_before`, `grand_total_after` written in the closing transaction; the same for a force-closed shift
- [ ] Open shift (2) picks up the opening cash from the last handover
- [ ] Shift report slip (26) with its Z number and print; every print writes `print_logs`, a second print is a reprint
- [ ] Shift running late bar (54) from `shift_overdue_warn_minutes`; a shift `shift_stale_block_minutes` past its end refuses new payments and charges until it is closed
- [ ] `ReportService::computeShiftTotals` with the version 1.1 keys of `totals` (DATABASE_SCHEMA §4.7) and the three roll-forwards
- [ ] Every "Shifts" and "Shift safeguards" test in TRD §10; invariants 6 and 8

**Done when:** two consecutive shifts can be run end to end in the browser and get consecutive Z numbers with a chained grand total; the second shift's forwarded-in list equals the first shift's forwarded-out list; a charge made in shift A and paid in shift B shows as a sale in A and cash in B; a reload in the middle of the count restores the pieces; the counter is refused as receiver; logging out or letting the session expire mid-shift changes nothing about the shift.

## Phase 6 — POS, stock and housekeeping

- [ ] POS (19): walk-in cash sale and charge to room; `CompleteSale`, `VoidSale`
- [ ] Stock (20): per-shift opening, in, sold, wastage, on hand; `ReceiveStock`, `RecordWastage`
- [ ] Reason lists: wastage reasons from `reasons` (kind `wastage`) with their note and manager flags
- [ ] Out-of-stock and low-stock states in POS and Add charge (10)
- [ ] Housekeeping queue (32) with startable rule and urgent flag; empty state (35)
- [ ] Cleaning in progress (33): `StartCleaning`, `FinishCleaning`, report a problem
- [ ] My cleans (34)
- [ ] `CleaningQuery`; `last_seen_at` heartbeat
- [ ] POS, stock and "Housekeeping" tests in TRD §10

**Done when:** a POS sale reduces stock and appears in the shift's cash; a check-out puts the room in a housekeeper's queue on their phone and "Room is ready" shows the room as Vacant on the board within one refresh.

## Phase 7 — Kiosk and reservations

- [ ] Kiosk device sign-in and `KioskLayout`; routes limited to `/kiosk/*`
- [ ] Welcome (27), room and hours (28) with `RoomRotation`, your name (29), room held (30) with QR and countdown
- [ ] No rooms (31) and offline (31b)
- [ ] `HoldRoom`; `ExpireKioskHolds` command every minute
- [ ] Kiosk holds sheet on the board (5); confirm hold (7) with `ConfirmKioskHold`
- [ ] Reservations list (57) and new reservation (58): `ReserveRoom`, availability by date overlap, money paid ahead
- [ ] Check in from a reservation (prefilled screen 6); "Reserved · <time>" in the board row
- [ ] `ReleaseNoShows` command; cancel reservation and refund no-show with approval
- [ ] Reason lists: cancel-reservation reasons from `reasons` (kind `cancel_reservation`) with their note and manager flags
- [ ] Modules respect `branches.kiosk_enabled` and `reservations_enabled` (menus and routes disappear when off)
- [ ] "Kiosk and reservations" tests in TRD §10

**Done when:** a guest can hold a room on the kiosk and the front desk can confirm it before it expires; an expired hold frees the room without leaving any record to clean up; a reservation blocks its room for its dates and its money paid ahead comes off the bill at check-in.

## Phase 8 — Reports

- [ ] `ReportQuery` and `ReportService` (shift, zReport, daily, summary, occupancy, housekeeping, inventory)
- [ ] Report hub (36)
- [ ] Shift report (37) with the three roll-forwards and non-cash line
- [ ] `ReportService::zReport()` returns one array of sections and lines; the screen, the 80 mm slip, the PDF and the CSV all render that array and compute nothing of their own (TRD §4.10)
- [ ] Z-report — sales and cash (37b): Z number, the sales-and-cash column from gross sales to short or over, by front desk, the fixed exceptions list (printed at zero), document number ranges, running grand total, signature block with names pre-printed
- [ ] Z-report — rooms by floor (37c, `?tab=rooms`): floor × category summary with the "No room" column, per-room chart with FWD and EXT; tabs for cleaning chart, housekeeping log, stocks, expenses
- [ ] Preview for an open shift: built live from the ledger, marked "Preview. Do not sign.", no Z number, export refused
- [ ] Exports: Z-report and day Z as PDF (dompdf, A4, Blade view over the builder array) and CSV (streamed, UTF-8 BOM, columns Section / Line / Count / Amount); other reports as CSV; exports need the reports permission
- [ ] Print log: every print and export writes `print_logs`; a second print of the same document is marked `is_reprint` and counted under "Slip reprints"
- [ ] Daily sales (38) for day, week, month
- [ ] Close the day (38b): `CloseDay` with its three refusals, day Z number, shift Z range, grand total carried forward, short and over summed separately; the day paper in the 37b layout
- [ ] Optional VAT block: only when `branches.vat_enabled`; VATable sales, VAT and VAT-exempt on 37b and the day Z, TIN and registered name in the header; with it off no VAT code path runs
- [ ] Occupancy and guests (40); housekeeping report (41); inventory report (42); audit trail (43)
- [ ] Drill-down from any figure to the ledger rows behind it
- [ ] A4 print styles for all reports
- [ ] `ReconcileBusinessDay` (daily 09:00) and `CheckInvariants` (09:05); result line on 36 and 38
- [ ] Every "Reports" and "Z-report and day close" test in TRD §10; invariant 7

**Done when:** for the seeded demo shift, screens 37, 37b and 37c show the same figures as the mockup (Z-0629, net sales ₱18,160.00, expected ₱13,195.00, short ₱50.00, grand total after ₱11,500,500.00) and the PDF and CSV carry the same figures as the screen; for a generated month of data, every day equals the sum of its shifts, every day can be closed in date order, and the reconcile job reports no mismatch; changing one ledger row by hand makes the job flag that day and `CloseDay` refuse it.

## Phase 9 — Admin, owner, dashboards and stats

- [ ] Rooms, floors and room types (44) with the status rules
- [ ] Rates, extension rates and discounts (45)
- [ ] Branch settings (46) including shift definitions, payment methods, modules, the stale-shift limit, the VAT block (on or off, rate, TIN, registered name) and the reason lists with their note and manager flags
- [ ] Users (47): roles, PIN reset, floor assignment, deactivate
- [ ] Products (48) with price log
- [ ] Summary tables: `RebuildSummaries` every minute (dirty branch-days recomputed from the ledgers, one transaction per day) and `CheckSummaries` every 10 minutes for today and yesterday; code in `app/Summaries`
- [ ] `AttentionList`: one class that builds every "Needs attention" row with a severity, a sentence and a route
- [ ] Needs attention (49) from `AttentionList`: overdue stays, shift open too long, cleaning stuck, failed invariants, a day not yet closed; force close with approval; `ResetCleaning`
- [ ] Owner dashboard (60) at `/owner`: period switch, cards, sales chart, "Branches now", how guests paid, top of `AttentionList`
- [ ] Branch dashboard (61) at `/dashboard`: cards, rooms now, sales by hour, the open-shift block for managers, housekeeping today, top of `AttentionList`
- [ ] Stats (63) at `/stats`: date range with quick choices, grain by range, cards with the same-length comparison, heat grid, shares, bars, front desk table
- [ ] Dashboard behaviour (TRD §4.11): every figure a deferred prop with a skeleton; partial reload every 30 seconds, skipped while the tab is hidden or a request is in flight; "Could not refresh. Retry"; same-time comparison; no change figure without previous data; short and over never netted
- [ ] Reports in the admin menu
- [ ] Owner: branches (55), add branch (56) with `CreateBranch` copy options, open a branch (lands on 61), owner accounts
- [ ] Owner summary (39) and branch picker on every report, dashboard and Stats
- [ ] `audit_logs` written for every settings, rate, price and user change
- [ ] "Branches and permissions" and "Dashboards and summaries" tests in TRD §10

**Done when:** an owner can create a second branch by copying the first, add rooms, and run a shift there; nothing from one branch is visible to the other branch's admin; switching hourly stays off in settings removes the hourly options from check-in, kiosk and rates; a payment taken at the front desk shows on the branch and owner dashboards within two minutes without a page reload; the dashboard figures for a scripted day equal the ledgers; and `CheckSummaries` logs an error when a summary row is changed by hand.

## Phase 10 — Polish, printing and release

- [ ] Welcome page (59) at `/`: logo, "Staff sign in" (or "Dashboard" when signed in), headline, room types with their lowest hourly and overnight prices from the rate table, three steps, branch picker with more than one branch; no availability and no booking
- [ ] Walk every screen against the mockup; fix spacing, wording and states
- [ ] Dark mode check: every page, chart and Nuxt UI component is readable in both colour modes; slips and reports always print light
- [ ] Keyboard pass on the front desk: Enter submits, Esc closes, focus order
- [ ] Print test on a real 80 mm thermal printer: slips 8, 18, 26; A4 test of the Z-report PDF (37b) and the day Z (38b)
- [ ] Performance budgets in TRD §7 measured and met, including the dashboard, `RebuildSummaries` and Stats budgets and the 350 kB bundle
- [ ] Empty database first-run: owner account command, first branch wizard path through 55/56/44/45
- [ ] Error pages and the "nothing was saved" message with request id
- [ ] Backup cron and restore test (START_HERE)
- [ ] Staging deployment with HTTPS; smoke test of the full journey on staging
- [ ] One-page quick guide per role (front desk, housekeeper, back office)
- [ ] Tag `v1.0.0`

**Done when:** a visitor sees the welcome page with the real prices and reaches the sign-in from it; a new person can follow the front desk quick guide to open a shift, check in three guests, sell a drink, check one out, close the shift and hand over, on staging, without help; the numbers on the shift report and the Z-report are right; and back office can close that day.

---

## Later (not in version 1)

- Kitchen and pub screens (products already have `outlet`)
- Online booking and a payment gateway
- Supervisor approving from their own phone
- Working offline
- Peso penalty for late cleaning
- Several unrelated hotel owners on one server
- Automatic overtime charge (decided against for now; see PRD §12)
