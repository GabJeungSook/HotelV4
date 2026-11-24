# Product Requirements — Homi

| | |
|---|---|
| Product | Homi — hotel management system for small Philippine hotels, inns and pension houses |
| Owner / developer | Gab |
| Version | 1.1 |
| Date | 11 November 2026 |
| Status | Approved with mockup v3 (design level, dashboards, Z-report and shift safeguards added the same day) |
| Wins on conflict | Product scope and priorities. Table and column names: [DATABASE_SCHEMA](DATABASE_SCHEMA.md). Technical rules and formulas: [TRD](TRD.md). |
| Related | [Mockup](mockup/hotel-mockup.html) · [APP_FLOW](APP_FLOW.md) · [TRD](TRD.md) · [DATABASE_SCHEMA](DATABASE_SCHEMA.md) · [SYSTEM_DESIGN](SYSTEM_DESIGN.md) · [DESIGN_SYSTEM](DESIGN_SYSTEM.md) · [ROADMAP](ROADMAP.md) · [ASSETS](ASSETS.md) · [START_HERE](../START_HERE.md) |

Screen numbers in this document are the mockup numbers (1–63 plus 2b, 4b, 4c, 31b, 37a, 37b, 37c, 38b: 71 frames). Feature IDs (F1–F18) and roadmap phases (0–10) are the same in every document.

## 1. Summary

Homi is a web application that runs the daily work of a small hotel: signing staff in, running a cash shift, selling rooms by the hour or by the night, charging food and extras to a room, checking guests out, cleaning rooms, and reporting sales to the owner. It is a from-scratch rebuild of HotelV2, which failed in production because its money, shift and room records could disagree with each other.

The rebuild is built on three rules that every screen follows:

1. **Money is written once.** Every charge and every payment is a new row in an append-only ledger. Nothing is edited or deleted; a mistake is fixed by a reversing row with a reason and an approver.
2. **A shift is a real record.** Every peso points to the shift session that was open when it was recorded. Signing out never closes a shift.
3. **A room can have only one open stay.** The database enforces it, so a room cannot be double-booked or shown free while a guest is checked in.

One installation serves one owner with one or more branches. Front desk, back office, admin and owner use a desktop browser; guests use an optional portrait kiosk; housekeepers use their own phones. The product has 18 features, 63 screens and 8 screen variants (71 frames), all approved in the mockup on 11 November 2026.

The reference for the level of design is the owner's Euphoria system. Homi takes from it the component library (Nuxt UI v4, themed with the Homi tokens), a dashboard as each role's home, a Z-report with a Z number and a running grand total, and its cashier-shift safeguards. Homi keeps what is specific to a hotel: one shared drawer per shift, a cash count at every shift change, and a handover accepted by the incoming front desk's PIN, because a hotel runs 24 hours and holds guest deposits in the drawer.

## 2. Problem and goal

### 2.1 What went wrong in HotelV2

HotelV2 (Laravel 9 + Livewire 2) was written for one hourly-rate hotel. It failed for five reasons.

| # | Problem in HotelV2 | What it caused | Rule in the rebuild |
|---|---|---|---|
| 1 | No single source of truth for money. Amounts were stored in several places and rows were edited in place. | Totals that could not be traced or re-added. | Two append-only ledgers: `folio_lines` (sales) and `payments` (cash). Balances are always calculated, never stored. |
| 2 | A shift was not a real record. Transactions were attributed by clock time, logging out closed a shift, and closing wrote a fake ₱1.00 ending cash. | Cash that belonged to no shift, or to the wrong one. | `shift_sessions` is a real row. Every ledger row carries its `shift_session_id`, set on the server. |
| 3 | Each report computed its own numbers: four definitions of "shift", four date bases, and "forwarded guests" recalculated differently per report. | Shift reports that did not add up to the daily report. | One `ReportService`. A closed shift's totals are frozen. Forwarded guests are saved once at close. |
| 4 | Room status and stay status were separate facts. | Ghost check-ins and double bookings; a "fix all" tool that checked out 21 staying guests in April 2026. | One open stay per room, enforced by a unique index. Room status changes only through one service. No bulk-fix tools. |
| 5 | Business logic lived in 2,000-line screen components, with three different extension calculators and almost no tests. | Different prices for the same extension; silent ₱0 charges. | One Action class per business action, one `PricingService`, and a named test for every rule ([TRD](TRD.md) §10). |

### 2.2 Goal

Deliver a hotel system whose numbers always agree: the sum of a day's shift reports equals the daily report, the drawer count is checked against one formula, and the room board cannot contradict the stays. HotelV2's working rules are kept, but as the default settings of a branch (screen 46) instead of fixed code, so the same product fits an hourly inn, an overnight pension house, or a hotel that sells both.

### 2.3 Who it is for

Small Philippine hotels, inns and pension houses with 20 to 300 rooms per branch, one owner, and one or more branches. Old HotelV2 transactions are not migrated; each branch starts with clean data.

## 3. Personas

| Persona | Role value | Device | What they need |
|---|---|---|---|
| **Owner** — owns the hotel company and all branches | `owner` | Desktop | Land on one dashboard that shows today's sales, occupancy, who is on duty and what needs attention in every branch (60), and a summary for a week or month (39). Add a branch in minutes by copying an existing one (56). Open any branch as its admin (55). Trust that reports cannot be altered after a shift closes. |
| **Branch admin** — sets up and maintains one branch | `admin` | Desktop | Land on the branch dashboard (61). Enter rooms, rates, settings, users and products (44–48). Reset passwords and PINs. See what looks wrong without a dangerous fix-all button (49). Read the branch's reports (36–43) and stats (63), and close the day (38b). |
| **Supervisor** — senior staff on duty | `supervisor` | Desktop | Land on Today (62). Approve transfers, voids, cancels, variances and special closes with their own PIN (14). Unlock a computer whose PIN entry is locked. Do front desk work when needed. Read reports, the branch dashboard (61) and the attention list (49). |
| **Front desk** — sells rooms and holds the drawer | `frontdesk` | Desktop, shared with one partner | Start on a page that says what needs doing in the next half hour (62). Check a guest in on one screen with one Save (6). See every room's state at a glance (4). Take payments and add charges quickly (10, 11). Close the shift in a guided way and hand the drawer over without arguments (22–25). |
| **Back office** — checks the numbers | `backoffice` | Desktop | Land on the branch dashboard (61). Read shift, daily, Z, occupancy, housekeeping, inventory and audit reports for the branch (36–43). Sign the numbered Z-report of each shift (37b), export it to PDF or CSV, and close the day (38b). Click any figure to see the ledger rows behind it. Reprint an old report and get the same numbers. |
| **Housekeeper** — cleans rooms | `housekeeper` | Own Android phone | One list of rooms to clean, oldest first (32). One big button to start and finish (33). A fair record of late cleans, counted once per clean (34). |
| **Hotel guest at the kiosk** | none (uses the `kiosk` device account) | Portrait touchscreen, 1080×1920 | Pick a room type and hours, see the exact price, give a name, and get a code to pay at the front desk (27–30). Never be sold a room that is already taken. |

## 4. Goals and success metrics

| # | Goal | Metric | Target | How it is measured |
|---|---|---|---|---|
| G1 | Reports agree | Sum of shift reports equals the daily report | Every business day, difference ₱0.00 | `ReconcileBusinessDay` at 09:00; result line on screens 36 and 38 |
| G2 | No double bookings | Rooms with two open stays | 0, ever | Unique index `stays_one_active_per_room`; invariant 2 in [TRD](TRD.md) §8 |
| G3 | No ghost check-ins | Rooms `occupied` without exactly one `checked_in` stay | 0 every night | `CheckInvariants` at 09:05; screen 49 |
| G4 | Every peso belongs to a shift | Ledger rows without a `shift_session_id` | 0 (column is `NOT NULL`) | Schema constraint; Feature tests |
| G5 | Fast shift close | Time from "Close shift" to the slip (22 → 26) with a receiver present | Under 5 minutes | Timed walk-through on staging with the demo shift (23 forwarded guests) |
| G6 | Fast check-in | Time from clicking "Check in" on a vacant room to the printed slip (6 → 8) | Under 60 seconds | Timed walk-through on staging |
| G7 | Stable reports | A closed shift's report reprinted later | Identical figures, every time | Reports test "close freezes totals" ([TRD](TRD.md) §10) |
| G8 | Honest cash count | Shift closes where the expected amount, or a figure it can be worked out from, was visible to the front desk before the count | 0 | No front desk page receives `expected_cash` or collected amounts for an open shift; arch test and Feature test ([TRD](TRD.md) §4.11, §4.12) |
| G9 | No silent ₱0 | Stays checked in or extended with a missing rate | 0 | `RateNotFound`; Pricing tests |
| G10 | Roll-forwards balance | Guests, deposits and unpaid roll-forwards on every closed shift | Balance on 100% of shifts | Invariant 6; printed on screen 37 |
| G11 | Responsive board | Room board first load with 300 rooms | ≤ 800 ms server time, ≤ 6 queries | Pest test ([TRD](TRD.md) §7) |
| G12 | No running cost | Paid services, packages or APIs | ₱0.00 | Dependency list in [TRD](TRD.md) §2 |
| G13 | Fresh dashboards | Age of a figure on screens 60 and 61 | Summaries rebuilt every minute; the page refreshes itself every 30 seconds | `RebuildSummaries` schedule; polling test ([TRD](TRD.md) §4.11) |
| G14 | Dashboards agree with the ledger | Difference between the summary tables and the ledgers for today and yesterday | ₱0.00; no summary more than 10 minutes behind | `CheckSummaries` every 10 minutes |
| G15 | Unbroken Z numbers | Gaps or repeats in the shift Z numbers and day Z numbers of a branch | 0 | Unique keys on `shift_sessions` and `day_closings`; concurrency test ([TRD](TRD.md) §10) |
| G16 | Fast dashboards | Dashboard first response and each card | ≤ 300 ms each, ≤ 3 queries per card | Pest test ([TRD](TRD.md) §7) |

## 5. Scope

### 5.1 In scope for version 1.0

| ID | Feature | Priority | Mockup screens | Roadmap phase |
|---|---|---|---|---|
| F1 | Accounts, roles and PINs | Must | 1, 3, 47 | 3 (PIN lockout logic), 4 (photo sign-in, role homes, PIN switch), 9 (user admin) |
| F2 | Shifts, cash and handover, with the shift safeguards | Must | 2, 2b, 21, 22, 23, 24, 25, 26 (safeguards also show on 3 and 54) | 4 (open, join), 5 (cash, safeguards, close, handover, Z number) |
| F3 | Room board | Must | 4, 4b, 4c | 4 |
| F4 | Check-in | Must | 6, 8 | 4 |
| F5 | Folio, charges and payments | Must | 9, 10, 11, 15 | 4 |
| F6 | Extend and transfer | Must | 12, 13 | 4 |
| F7 | Check-out | Must | 16, 17, 18 | 4 |
| F8 | POS and stock | Must | 19, 20 | 6 |
| F9 | Housekeeping | Must | 32, 33, 34, 35 | 6 |
| F10 | Kiosk | Should | 5, 7, 27, 28, 29, 30, 31, 31b | 7 |
| F11 | Reservations | Should | 57, 58 | 7 |
| F12 | Reports | Must | 36, 37, 37b, 37c, 38, 38b, 40, 41, 42, 43 | 8 |
| F13 | Branch setup (admin) | Must | 44, 45, 46, 47, 48 | 9 |
| F14 | Owner and branches | Must | 39, 55, 56 | 9 |
| F15 | Approvals and audit trail | Must | 14, 43 | 4 (PIN approval), 8 (audit report) |
| F16 | Problems and resilience | Must | 49, 50, 51, 52, 53, 54 | 4 (50–53), 5 (54), 9 (49), 10 (final pass) |
| F17 | Dashboards and stats | Must | 60, 61, 62, 63 | 4 (62), 9 (60, 61, 63) |
| F18 | Welcome page | Should | 59 | 10 |

The pricing, cash, shift and report rules behind these screens are built and tested first, in phase 3, before any screen uses them. Screens 47 and 43 are each listed under two features because they serve both; the screen inventory in [APP_FLOW](APP_FLOW.md) §7 gives each screen one home feature. The Z-report, the day close and the exports belong to F12; the shift safeguards to F2; the scene sign-in to F1.

F10 and F11 are "Should" because they are optional modules switched on per branch (46); a branch can operate without them. F18 is "Should" because no staff work depends on the public page. Every other feature is needed for a branch to open, sell, close a shift and report; F17 is "Must" because its screens are the home of every desktop role.

### 5.2 Out of scope (not planned)

| Item | Reason (mockup Decisions table) |
|---|---|
| Automatic overtime charge | D5. Kept as in V2: the room's row shows Overdue after the expected check-out and the front desk extends by hand (12). |
| Migrating past V2 transactions | The old data has the errors this rebuild exists to remove. The V2 database stays as a read-only archive. |
| Bulk "fix all" tools; editing or deleting saved money rows | Corrections are reversing entries with a reason and an approver. |
| Kiosk check-out | Removed. Check-out happens only at the front desk (16–18). |
| Processing GCash or card payments | They are recorded by reference number only ([TRD](TRD.md) §6). |
| Printer drivers, ESC/POS, push notifications, websockets | Browser print and polling only ([TRD](TRD.md) §6). |
| Storing guest IDs or photos | Only name, optional mobile and optional plate number are kept ([TRD](TRD.md) §9). |
| A chart, table or date-picker library | Charts are small components of our own; tables are plain HTML tables ([TRD](TRD.md) §2). |
| The cash model of the owner's Euphoria system (remit everything, one close per day) | Not used. A hotel runs 24 hours and holds guest deposits in the drawer, so Homi keeps one shared drawer per shift, a count at every shift change and the PIN handover ([TRD](TRD.md) §4.12). |
| Availability or booking on the welcome page | The public page shows room types and prices only (59). Online booking stays on the Later list. |

### 5.3 Later (room is left for it, not built in version 1)

| Item | What already exists for it |
|---|---|
| Several unrelated hotel owners on one server | Version 1 is one hotel company with many branches per installation. |
| Peso penalty for late cleaning | The housekeeping report (41) already lists late rooms; a penalty column is added once the rule is known (OD3). |
| Kitchen and pub screens | `products.outlet` already holds `frontdesk`, `kitchen`, `pub`; version 1 uses `frontdesk` only. |
| Online booking and a payment gateway | Reservations and payment methods are designed so these can be added. |
| Supervisor approving from their own phone | Version 1 uses the PIN on the spot (14). |
| Working offline | Version 1 is online-only (50). |

## 6. Functional requirements

Each requirement is testable and cites its screen. Formulas are in [TRD](TRD.md) §4; transaction steps are in [DATABASE_SCHEMA](DATABASE_SCHEMA.md) §8; step-by-step flows are in [APP_FLOW](APP_FLOW.md) §4.

### F1 Accounts, roles and PINs

| ID | Requirement | Screens |
|---|---|---|
| FR1.1 | Every staff member signs in with their own username and password. There are no shared accounts. Usernames are unique across the installation. | 1, 47 |
| FR1.2 | After sign-in each role is sent to its home: `frontdesk` to Today (62), or to Open shift (2, or 2b to join) when they are not a member of an open shift; `supervisor` to Today (62); `admin` and `backoffice` to the branch dashboard (61); `owner` to the owner dashboard (60); `housekeeper` to the queue (32); `kiosk` to the kiosk welcome screen (27). | 1, 60, 61, 62 |
| FR1.3 | Five wrong passwords in a row lock the account for 15 minutes. | 1 |
| FR1.4 | Passwords have at least 8 characters. There is no self-service reset; the sign-in screen tells the user to ask the branch admin, who resets it on screen 47. | 1, 47 |
| FR1.5 | `frontdesk`, `supervisor` and `admin` users have a personal 4-digit PIN. The user sets it at first sign-in. An admin can reset it but can never see it. | 47 |
| FR1.6 | On a shared computer a front desk user switches to their partner by tapping the name in the top bar and typing the partner's PIN. Only members of the open shift are listed. The sheet shows the tries left; three wrong PINs fall back to the full sign-in (1). | 3 |
| FR1.7 | There are seven roles: `owner`, `admin`, `supervisor`, `frontdesk`, `backoffice`, `housekeeper`, `kiosk`. Each can open only the screens given to it in [TRD](TRD.md) §4.1. | all |
| FR1.8 | A `frontdesk` user must be a member of the open shift to open any front desk route except `/shift/open`. | 2, 2b |
| FR1.9 | A session lasts 12 hours. Signing out, closing the browser or a session timeout never closes a shift, frees a room or changes any cash figure. | 1 |
| FR1.10 | The admin manages the users of their own branch: add a user with a role (admin, supervisor, front desk, back office, housekeeper, kiosk device), reset a password or PIN, assign floors to a housekeeper, and deactivate a user. A user is never deleted, so past work keeps the name. | 47 |
| FR1.11 | A user of one branch cannot read or write any data of another branch. Only an `owner` can move between branches. | 51, 55 |
| FR1.12 | The sign-in is a split screen: a real photograph on the left with the greeting, and a plain white sign-in panel on the right with the line "Use your own account. Never share it." The photo and the greeting follow the server's time of day (morning, afternoon, evening). The photo drifts slowly and the text fades in once on load; all motion stops under the system's reduced-motion setting. | 1 |
| FR1.13 | On a computer that has signed in before, the photo side shows four figures about the remembered branch — rooms available, guests in house, guests due out, arrivals today — and whether a shift is open and since when. On a new computer it shows the greeting only. The facts are counts: no money and no guest names appear before sign-in. | 1 |
| FR1.14 | Every PIN try (switch user, approval, handover) is logged per computer, right or wrong. Ten wrong tries in a row lock PIN entry on that computer until a supervisor or admin unlocks it with their own password. The unlock is recorded with who did it. | 3, 14, 25 |

### F2 Shifts, cash and handover

| ID | Requirement | Screens |
|---|---|---|
| FR2.1 | A branch has at most one open shift at a time. A second attempt to open one is refused by the database and shown as "shift already open". | 2 |
| FR2.2 | Open shift proposes the shift period and the business date from the last closed shift, not from the clock, and saves them once on the shift record. The user may change the proposed period only when no shift has ever been closed or with a supervisor PIN. The business-day rule is in [TRD](TRD.md) §4.6. | 2 |
| FR2.3 | The opening cash shown is the amount the previous shift counted at close. The user types what they counted. If it differs, a note is required and the difference is recorded against the handover. | 2 |
| FR2.4 | A second front desk joins the open shift with their own account. They share one drawer and one cash count. The number of front desks per shift is a branch setting (default 2); one more is refused with "This shift already has two front desks". | 2b, 46 |
| FR2.5 | Every charge, payment, cash movement, POS order and stock movement is stamped on the server with the open shift, whoever is signed in, and with the person signed on at that computer at that moment (after a PIN switch, that person). No shift value comes from the browser, the clock or the user record. "By front desk" on the Z-report (37b) is read from these stamps. | 11, 19, 21, 37b |
| FR2.6 | During the shift the front desk records three kinds of cash movement: expense (needs a category), remittance, and shortage payment (names the earlier short shift being repaid). Each needs an amount above ₱0.00 and a note. | 21 |
| FR2.7 | A wrong cash movement is voided with a reason and a supervisor PIN, while its shift is still open. It stays visible, struck through, and counts as ₱0.00. The correct entry is typed again. | 21, 14 |
| FR2.8 | The count is blind on the server, not just on the screen. The expected drawer total is never shown on screen 21, and until the count is saved no response to the outgoing team contains the expected cash or any figure it can be worked out from. | 21, 22, 23, 62 |
| FR2.9 | Close step 1 lists what the next team inherits: kiosk holds waiting, overdue guests, occupied rooms, folios with unpaid lines, and rooms uncleaned or being cleaned. Items to fix are amber and do not block closing; they are written into the handover notes. From this step the shift is `closing` and new payments, charges and cash movements by the outgoing team are refused. | 22 |
| FR2.10 | Close step 2 is a blind count by denomination: pieces of ₱1,000, ₱500, ₱200, ₱100, ₱50 and ₱20, plus one coins total. The server adds up the pieces itself and ignores any total sent by the browser. The counter and a witness are recorded. The count is saved once and cannot be edited. | 23 |
| FR2.11 | Close step 3 shows the expected cash line by line (opening cash, collected from guests, deposits taken, deposits returned, expenses, remittance) next to the count, using the single formula in [TRD](TRD.md) §4.5. Only cash is counted; GCash and card are listed separately. A variance other than ₱0.00 needs a written explanation. | 24 |
| FR2.12 | Closing freezes the shift's totals and the list of forwarded guests as its permanent record. They are never updated. A correction made later is posted to the shift that is open at that time. | 24, 37 |
| FR2.13 | Close step 4: the incoming front desk sees the cash, forwarded guests, key deposits held and unpaid balances they are receiving, types their own count, and accepts with their PIN. The receiver cannot be the person who counted. Accepting opens the next shift in the same save with the received cash as its opening cash, so there is never a gap or an overlap. A different count is recorded as a difference with a note. | 25 |
| FR2.14 | If nobody is there to receive, "Close without handover" needs a supervisor PIN. The next person uses Open shift (2), which links itself to that handover. | 25, 14, 2 |
| FR2.15 | After the handover the shift report slip is shown with the shift's Z number and can be printed on 80 mm thermal paper. Reprinting it at any later date gives the same numbers; every print is logged and a second print is marked as a reprint. The outgoing team is then signed out. | 26 |
| FR2.16 | A short shift's shortage is an amount owed by that shift. Staff repay it later as a shortage payment, which adds to the drawer of the shift in which it is paid. | 24, 21 |
| FR2.17 | The app never closes a shift by itself and never moves a late transaction to another shift. A shift still open 30 minutes past its end time (branch setting) shows an amber reminder bar; after 2 hours it also appears on the attention list; after 4 hours (branch setting, default 240 minutes) it refuses new payments and charges until it is closed. | 54, 49 |
| FR2.18 | An admin or supervisor can force-close a shift left open from the attention list. It asks for a cash count and a PIN and marks the shift as force-closed. A force-closed shift gets a Z number like any other. | 49, 14 |
| FR2.19 | Close step 1 also shows a check list of the papers that should be in the drawer, as counts only: expense receipts, remittance slips, GCash and card slips, cancelled stays and voids, key deposits taken and returned. No peso amount appears anywhere before the count. | 22 |
| FR2.20 | The pieces typed in the count are saved as a draft after each change and restored after a reload or a power cut. The draft is deleted when the count is confirmed. | 23 |
| FR2.21 | The person who counted can never approve their own variance: the receiver in step 4, or a supervisor, must be someone else. | 24, 25, 14 |
| FR2.22 | When one member starts the close, the other member sees who is closing the shift; their next save is refused and their unsaved form stays on screen. | 22 |
| FR2.23 | A double tap on a cash movement saves one entry, not two. | 21 |
| FR2.24 | An expense or remittance larger than the cash expected in the drawer at that moment is refused. The message does not state the expected amount. | 21 |
| FR2.25 | Reasons for voids, cancels, variances, voided cash entries, wastage and cancelled reservations, and the transfer reasons and expense categories, come from lists the admin edits. Each reason can require a note, a supervisor PIN, or both; the form enforces it. | 13, 15, 20, 21, 24, 58 |
| FR2.26 | Closing a shift gives it the next Z number of its branch (shown as `Z-0629`) and a running grand total: the total before the shift plus the shift's net sales. Both are written in the same save as the close and never change. | 25, 26, 37b |
| FR2.27 | Homi keeps one shared drawer per shift, a cash count at every shift change, and the handover accepted by the incoming front desk's PIN. | 2b, 23, 25 |

### F3 Room board

| ID | Requirement | Screens |
|---|---|---|
| FR3.1 | The board is a table with one row per active room, grouped by floor: room, type, status, guest, due out, time, balance and an action. Tabs above it filter by status and show counts; a sort switches between room number and "Needs attention first". | 4 |
| FR3.2 | Each row's status comes from the room status plus its open stay. Rows are never tinted; only the status label has colour, and the time turns ochre when due within the due-soon window (default 30 minutes) and brick when past the expected check-out. | 4 |
| FR3.3 | A Vacant row shows "Check in", which starts a walk-in check-in (6); clicking an Occupied, Due out or Overdue row opens the folio (9); a Held row opens the confirm screen (7). To clean, Cleaning and Blocked rows cannot be sold. | 4 |
| FR3.3a | The status label reads Vacant (`available`), Occupied, Due out, Overdue, Held (kiosk hold), To clean (`uncleaned`), Cleaning or Blocked (`maintenance`). Other screens and reports keep the status names. | 4 |
| FR3.4 | The top of the board counts rooms per state (available, occupied, due soon, overdue, uncleaned, cleaning, kiosk hold) and offers a floor filter and a "Walk-in check-in" button. | 4 |
| FR3.5 | Search matches room numbers and the names of guests currently checked in. With no match the board says so and offers one "Clear search" button. | 4c |
| FR3.6 | The board refreshes itself every 15 seconds and updates rows in place without flashing or moving the row under the cursor. Grey skeleton rows show on the first load only. | 4, 4b |
| FR3.7 | A room reserved for a specific room shows "Reserved · <arrival time>" in its guest column on the arrival day. | 4, 57 |

### F4 Check-in

| ID | Requirement | Screens |
|---|---|---|
| FR4.1 | Walk-in check-in is one screen with one Save. Saving writes the stay, the room charge, the discount line, the payment, the deposit and the room status together, or nothing at all. | 6 |
| FR4.2 | The price comes from the rate table and cannot be typed. Picking a stay option shows the price and the expected check-out immediately. | 6 |
| FR4.3 | Stay options are hourly blocks (default 6, 12, 18, 24 hours), overnight for N nights (check-out at the branch's fixed check-out time), and long stay for 1 to 31 days at the 24-hour rate per day. Only the options the branch has switched on, and that have a rate, are offered. | 6, 45, 46 |
| FR4.4 | The guest's full name is required (at least 3 characters). Mobile number (11 digits) and plate number are optional. | 6 |
| FR4.5 | A discount from the branch list (fixed amount or percentage) can be applied. It reduces the room amount only, never the deposit and never below ₱0.00, and is refused on a rate it is not allowed on. It is written as its own ledger line. | 6, 45 |
| FR4.6 | If the branch takes a deposit, it is added automatically, shown as its own row, and kept separate from room sales. | 6 |
| FR4.7 | The payment method is one the branch accepts. Cash received must cover the total. Extra cash is either given as change or kept as a guest deposit, as the guest chooses. GCash and card need a reference number. | 6 |
| FR4.8 | A missing rate greys out that option and explains it; the app never checks a guest in at ₱0.00. | 6, 52 |
| FR4.9 | If another user or a kiosk guest took the room a moment earlier, Save is refused with "Room was just taken", nothing is saved, and "Pick another" reopens check-in with the guest's details and the next free room of the same type. | 53 |
| FR4.10 | A repeated Save of the same form (double click, retry) returns the first stay and creates nothing new. | 6 |
| FR4.11 | After a successful check-in the app shows the change to give and prints a check-in slip (stay code, room, guest, in and out times, amounts paid, front desk name). The slip can be printed again. | 8 |
| FR4.12 | Each stay gets a unique code made of the branch prefix, the two-digit year and a six-digit running number, for example `DGS-26-004126`. | 8 |

### F5 Folio, charges and payments

| ID | Requirement | Screens |
|---|---|---|
| FR5.1 | One folio screen per stay lists every charge and deposit with time, item, who added it, amount and status (Paid, Unpaid, Held, Reversed), and shows charges, paid, balance due and deposit held. | 9 |
| FR5.2 | Balance due, line status and deposit held are always calculated from the ledger rows and never stored on the stay. | 9 |
| FR5.3 | Add charge is a side panel with three tabs — food and drinks, amenities, damages — fed by the one product list. An out-of-stock item cannot be added. | 10, 48 |
| FR5.4 | "Add to folio" leaves the new lines unpaid. "Add and take payment now" continues to Take payment in the same save. | 10 |
| FR5.5 | Adding a charge for a stock-tracked product reduces its stock in the same save; stock can never go below zero. | 10, 20 |
| FR5.6 | One payment can settle several lines. A payment smaller than the balance is allowed: it pays the oldest lines first and leaves the rest unpaid. | 11 |
| FR5.7 | The guest-deposit payment option is enabled only when the stay has guest deposit money, and cannot exceed it. The key deposit can never be used to pay before check-out. | 11 |
| FR5.8 | GCash and card payments need a reference number and are reported outside the drawer count. | 11, 24 |
| FR5.9 | Cash given above the balance is returned as change or, when the guest asks, kept as a guest deposit. | 11 |
| FR5.10 | Voiding a line adds a reversing line stamped with the open shift, refunds what was paid on it, and puts the stock back. It needs a reason from the branch's list (with a note when the reason requires one) and a supervisor PIN. A line can be reversed only once. | 15, 14 |
| FR5.11 | Cancelling a stay (for a check-in made by mistake) reverses every line, refunds every payment and deposit from the drawer of the open shift, restores stock, sets the stay to cancelled and returns the room to Available without a cleaning task. It needs a reason from the branch's list (with a note when the reason requires one) and a supervisor PIN. | 15, 14 |
| FR5.12 | The price at the moment of sale is saved on the line. Later price or rate changes never alter existing lines. | 9, 45, 48 |

### F6 Extend and transfer

| ID | Requirement | Screens |
|---|---|---|
| FR6.1 | Extend offers only hour options whose every needed rate exists, shows the price before saving, explains how it was worked out, and shows the new expected check-out. | 12 |
| FR6.2 | Extension pricing follows the branch's rule — `cycle` (default, 24-hour cycle), `per_hour` or `block` — exactly as in [TRD](TRD.md) §4.3. Example on screen 12: a Deluxe stay with 12 of 24 cycle hours used pays ₱250.00 for 6 more hours, and ₱400.00 + ₱650.00 = ₱1,050.00 for 24 more hours. | 12, 46 |
| FR6.3 | The front desk chooses "Extend and take payment" or "Extend, pay later"; the second leaves an unpaid extension line. | 12 |
| FR6.4 | If no extension option has a price, the screen shows the missing-rate message and saves nothing. | 12, 52 |
| FR6.5 | Transfer offers only rooms that are available, not held and not reserved for the period. | 13 |
| FR6.6 | A transfer needs a reason from the admin's list and the choice of what the old room becomes: Uncleaned (joins the housekeeping queue) or Maintenance. | 13 |
| FR6.7 | The original room charge is never edited. The transfer adds one line for the price difference ([TRD](TRD.md) §4.4): an upgrade is collected, a downgrade is credited to the guest deposit, the same price adds nothing. Discounts are not re-applied. The expected check-out does not change. | 13 |
| FR6.8 | Saving a transfer needs a supervisor PIN. | 13, 14 |

### F7 Check-out

| ID | Requirement | Screens |
|---|---|---|
| FR7.1 | Check-out is a three-step guide opened from the folio: items returned, settle, done. The guest does not use the kiosk. | 16–18 |
| FR7.2 | Step 1: the front desk answers whether the room key and the TV remote were returned. Each "No" adds a damage line at the branch's lost-item amount (default ₱100.00 each). Other damages can be added from the product list. Next is disabled until both are answered. | 16 |
| FR7.3 | Step 2: any unpaid balance is collected first with the same payment panel as screen 11. | 17 |
| FR7.4 | Step 2: the key deposit is settled — the amount held minus charges for missing items is returned in cash on the open shift, or, when the guest left without claiming it, recorded as forfeited income of that shift. Any guest deposit is settled the same way. | 17 |
| FR7.5 | "Check out guest" is disabled until the balance is ₱0.00 and the deposit box is answered. After check-out the stay has balance ₱0.00 and deposit held ₱0.00. | 17 |
| FR7.6 | Step 3: the stay is closed with the actual check-out time, stored separately from the expected time. In the same save the room becomes Uncleaned and a cleaning task is created with the branch's deadline (default 4 hours). | 18 |
| FR7.7 | Pressing Check out twice does nothing the second time. | 18 |
| FR7.8 | A receipt can be printed from the done screen on 80 mm thermal paper. | 18 |

### F8 POS and stock

| ID | Requirement | Screens |
|---|---|---|
| FR8.1 | The POS sells from the branch's one product list, filtered by category, showing price and stock left. Out-of-stock items cannot be added. | 19 |
| FR8.2 | A "Walk-in · cash" sale must be paid in full and goes straight to the open shift's cash. | 19 |
| FR8.3 | A "Charge to room" sale needs a room with a checked-in stay and adds unpaid lines to that folio, the same as Add charge. | 19, 9 |
| FR8.4 | Both kinds of sale reduce the same stock in the same save. | 19, 20 |
| FR8.5 | A sale can be voided with a supervisor PIN within the same shift; the void puts the stock back. | 19, 14 |
| FR8.6 | A repeated submit of the same sale returns the first result. | 19 |
| FR8.7 | The stock screen shows, per product for the open shift: opening, in, sold, wastage and on hand, where on hand = opening + in − sold − wastage. Each number opens the movements behind it. Low and out-of-stock products are flagged. | 20 |
| FR8.8 | "Receive stock" records a delivery. Wastage needs a note. Stock on hand can never go below zero. | 20 |
| FR8.9 | The POS and stock module can be switched off per branch; its screens then disappear from the menus. | 46 |
| FR8.10 | A product can be a **meal** made from a recipe of other stock items (whole-unit quantities, flat). Selling it deducts each ingredient from its own stock; a void puts each back. A meal keeps no stock of its own, and its availability is how many it can still be made from its ingredients. | 19, 48 |
| FR8.11 | A stock item can be a **supply** that is not sold on its own (`sellable` off, no price), used only inside a meal's recipe; it is counted and received on the stock screen but never shown on the counter. An ingredient may also be sold directly (e.g. extra egg). | 19, 20, 48 |

### F9 Housekeeping

| ID | Requirement | Screens |
|---|---|---|
| FR9.1 | Every check-out and every transfer-out to Uncleaned creates exactly one cleaning task, tied to the stay that left the room, with a deadline of the branch's cleaning deadline (default 4 hours) from that moment. | 18, 13, 32 |
| FR9.2 | A housekeeper sees the queued rooms on the floors assigned to them, oldest first. A room waiting at least the urgent time (default 2 hours) is marked urgent. | 32 |
| FR9.3 | Only the first N rooms in the queue can be started, where N is the number of housekeepers of those floors seen online in the last 2 minutes (at least 1). | 32 |
| FR9.4 | "Start cleaning" sets the room to Cleaning (violet on the board). A housekeeper can have only one room in progress. | 32, 33 |
| FR9.5 | The cleaning screen shows a timer against the branch's target (default 15 minutes) and a short checklist. | 33 |
| FR9.6 | "Room is ready" finishes the task and sets the room to Available in one save. It is refused if the room has an active stay. | 33 |
| FR9.7 | "Report a problem" finishes the task with a note and sends the room to Maintenance instead of Available. | 33 |
| FR9.8 | A clean is late when it finished after its deadline. Each clean is counted once. | 34, 41 |
| FR9.9 | "My cleans" shows the housekeeper's own cleans for the day with time taken, average and late count. | 34 |
| FR9.10 | The queue checks for new rooms every 20 seconds and shows an empty state when there is nothing to clean. | 35 |
| FR9.11 | An admin or supervisor can reset a cleaning that never finished from the attention list. | 49 |

### F10 Kiosk

| ID | Requirement | Screens |
|---|---|---|
| FR10.1 | The kiosk is an optional module per branch. It runs in a full-screen browser signed in once as a `kiosk` device account, which can open only `/kiosk/*`. | 27, 46 |
| FR10.2 | The welcome screen shows the branch's house rules and the number of rooms available now. Tapping anywhere starts. | 27 |
| FR10.3 | The kiosk offers one room per room type, chosen by the rotation rule in [TRD](TRD.md) §4.8 (never used first, then longest since last check-out). A type with no free room shows "Full". Rooms not enabled for the kiosk or reserved for the period are never offered. | 28 |
| FR10.4 | The guest picks a type and the hours and sees the price. All prices are computed on the server; the kiosk never sends a price. | 28 |
| FR10.5 | The guest's name is required (at least 3 letters). A mobile number is optional but must have all 11 digits if typed. A kiosk-enabled discount appears only on the rates it is allowed on. The summary shows exactly what the front desk will collect, including the deposit. "Hold my room" stays disabled until the form is valid. | 29 |
| FR10.6 | "Hold my room" creates a held stay with a code and an expiry (default 10 minutes, branch setting) in one save. If the room was taken a second earlier the save fails cleanly. A kiosk can only create held stays. | 30, 53 |
| FR10.7 | The held screen shows the code, a QR of the code, the amount to pay and a countdown that uses the server's expiry time. It returns to the welcome screen after 30 seconds or on Done. Any kiosk screen left idle for 60 seconds resets to the welcome screen. | 30 |
| FR10.8 | The front desk sees holds in a sheet on the board with time left and amount to collect, and can "Take payment" (7) or "Release room" (after a confirmation). | 5 |
| FR10.9 | Confirming a hold fixes room, hours and discount to what the guest chose; the front desk only takes the payment. The stay's hours start when the front desk presses Check in, not when the guest tapped the kiosk. | 7 |
| FR10.10 | An expired hold is closed by the server within one minute and the room returns to sale. If it expires while the confirm screen is open, Save is refused with "This hold has expired". | 5, 7 |
| FR10.11 | When no room is free in any type the kiosk shows "All rooms are taken", checks again every 20 seconds, and returns to the welcome screen when one opens. | 31 |
| FR10.12 | When the kiosk loses its connection it stops taking check-ins, shows the offline screen, and reconnects by itself. | 31b |

### F11 Reservations

| ID | Requirement | Screens |
|---|---|---|
| FR11.1 | Reservations is an optional module per branch: a list and a form for the front desk. There is no online booking. | 57, 58, 46 |
| FR11.2 | A reservation needs guest name, arrival date and time, stay option and room type. A specific room is optional. | 58 |
| FR11.3 | Before saving, the app checks that a room is free for the whole period and quotes the price from the rate table, showing the total at check-in including the deposit. | 58 |
| FR11.4 | Money paid ahead is recorded on the open shift with its method (and reference number for GCash or card) and held as the guest's deposit on the reserved stay. It is applied to the bill at check-in. | 58, 37 |
| FR11.5 | A reservation for a specific room keeps that room off the kiosk and off walk-in sale for the period. A reservation for "any" room of a type reduces the number of rooms of that type the branch may sell. | 57, 28 |
| FR11.6 | The list shows reservations by arrival day in Today, Upcoming and Past tabs, with status (Arriving, Confirmed, No-show), paid-ahead amount, and rooms free per type on the selected day. | 57 |
| FR11.7 | "Check in" opens the normal check-in (6) with guest, room and stay filled in and the paid-ahead amount taken off the total. | 57, 6 |
| FR11.8 | A guest who has not arrived by the release time after arrival (default 2 hours, branch setting) is marked no-show within 5 minutes and the room is released. Money paid ahead is kept unless a supervisor refunds it. | 57 |
| FR11.9 | Cancelling a reservation, or refunding a no-show, needs a reason and a supervisor PIN. | 58, 14 |

### F12 Reports

| ID | Requirement | Screens |
|---|---|---|
| FR12.1 | The report hub offers seven reports: shift reports, daily sales, Z-report, occupancy and guests, housekeeping, inventory, audit trail. The Z-report has two papers (37b, 37c) and daily sales leads to Close the day (38b). The hub shows the result of last night's check. | 36 |
| FR12.2 | Every report reads the same ledger through one report service. For a closed shift it reads the frozen totals and never recalculates them. | 36–43 |
| FR12.3 | Shift report: sales charged by category with counts; cash (opening, collected, deposits taken and returned, expenses, remittance, expected, counted, short or over with the note); GCash and card outside the drawer; and three roll-forward boxes — guests, key deposits, unpaid balances — each of which must add up ([TRD](TRD.md) §4.5). | 37 |
| FR12.4 | Sales (what was charged in the shift) and cash (what was collected in the shift) are shown separately. A charge made in one shift and paid in the next is a sale of the first and cash of the second. | 37 |
| FR12.5 | "Forwarded in" is read from the list saved when the previous shift closed. It is never recalculated. | 37, 37c |
| FR12.6 | Every figure on the shift report can be clicked to list the ledger rows behind it. | 37 |
| FR12.7 | Z-report — rooms by floor (the second tab of the Z-report): sales by floor and category (Room, Extend, Transfer difference, Foods, Drinks, Miscellaneous) with a "No room" column for walk-in POS, a "Deposits held at close" row, then a room chart with one row per stay per room. Forwarded guests are red and marked FWD and show only what was charged this shift; EXT marks an extension made this shift. Its total equals net sales on the first tab (37b) and "Sales charged" on the shift report (37). Further tabs hold the cleaning chart, housekeeping log, stocks and expenses. | 37c |
| FR12.8 | Deposit figures come from the real deposit rows, never from a count multiplied by a fixed amount. | 37, 37b, 37c |
| FR12.9 | Daily sales shows a business day as its shifts side by side with their sum, for a day, week or month. A day is all shifts with that business date; a month is the sum of its days. Key deposits are not sales. | 38 |
| FR12.10 | A nightly job at 09:00 recomputes the previous business day from the ledger and compares it with the closed shifts. A match shows a green line; a mismatch shows a red line listing the rows that differ. | 36, 38 |
| FR12.11 | Occupancy and guests: in house at start + checked in − checked out = in house at end, counted from actual check-in and check-out times, with check-ins, extensions, transfers and overstays per room type. Each number opens the stays behind it. | 40 |
| FR12.12 | Housekeeping report: cleans, average time and late count per housekeeper for a day or a shift, and a list of late rooms. It counts late rooms only; no peso penalty. | 41 |
| FR12.13 | Inventory report: opening, in, sold, wastage, closing and sales value per product per day. Closing = opening + in − sold − wastage, and one day's closing is the next day's opening. | 42 |
| FR12.14 | Reports can be printed on A4. Every print and every export of a slip or report is logged with who, when and in what format; a second print of the same document is marked as a reprint. | 8, 18, 26, 37–43 |
| FR12.15 | `backoffice`, `supervisor` and `admin` see reports for their own branch. The owner sees every report with a branch picker. | 36, 39 |
| FR12.16 | Z-report — sales and cash is the shift's signed paper. Its header carries the branch, the period and business date, the Z number, the open and close times, and when and by whom it was printed. | 37b |
| FR12.17 | One "Sales and cash" column walks from sales to the drawer in this order: gross sales; less each discount by name with its count; **net sales**; less unpaid forwarded out; plus paid for earlier shifts; **collected**; less each non-cash method; less paid from deposit; **cash collected**; plus deposits taken; less deposits returned; less expenses; less remittance; plus shortage payments (when not zero); **cash from this shift**; plus opening cash; **expected in the drawer**; counted; short or over with its note. Every bold line equals the arithmetic of the lines above it. | 37b |
| FR12.18 | "By front desk" lists each member with their check-ins, cash taken and cash given back. | 37b |
| FR12.19 | The exceptions list has a fixed set of rows, printed even at zero: cancelled stays, voided lines, cash refunds, room transfers, forfeited deposits, voided cash entries, slip reprints, and "force closed" when it applies. | 37b |
| FR12.20 | "Documents" shows the first and last stay code and POS order number of the shift, with counts. | 37b |
| FR12.21 | "Running grand total" shows the total before the shift, the shift's net sales and the total after. It chains from shift to shift. A void made later lowers the grand total of the shift it was made in, never an earlier one. | 37b |
| FR12.22 | The signature block pre-prints the names of who closed and who received over their lines, and leaves a line for back office with name and date. | 37b |
| FR12.23 | While a shift is open the same page is a preview: built live from the ledger, marked "Preview. Do not sign.", with no Z number. A preview cannot be exported. Only users who may read reports can open it; the front desk cannot. | 37b |
| FR12.24 | The Z-report and the day Z export to PDF (A4) and CSV (columns Section, Line, Count, Amount). Other reports export to CSV. An export needs the reports permission. The screen, the slip, the PDF and the CSV show the same figures because all four are drawn from one set of lines; no format works out a figure of its own. | 37b, 38b, 37–43 |
| FR12.25 | Close the day: back office, the admin or the owner closes a business day. The day gets its own number (shown as `D-0314`), lists its shifts with their Z numbers, net sales and short or over, and carries the grand total forward. The day paper is the sum of its shift papers and prints in the layout of 37b. Short and over are added separately and never netted. | 38b |
| FR12.26 | Closing a day is refused while a shift of that date is open, when an earlier day is not yet closed, or when the nightly check found a mismatch for that date. A day is closed once. | 38b |
| FR12.27 | VAT is a branch setting, off by default. When it is on, the Z-report and the day Z add a VAT block (VATable sales, VAT, VAT-exempt sales) and the branch's TIN and registered name; prices are treated as VAT-inclusive, and senior citizen and PWD discounted sales are reported as exempt. When it is off, no VAT figure is computed or shown. | 37b, 38b, 46 |
| FR12.28 | Closed-shift and closed-day reports never read the dashboard summary tables. They read the frozen totals of the shift, the forwarded-guests list and the day closing. | 37, 37b, 37c, 38, 38b |

### F13 Branch setup (admin)

| ID | Requirement | Screens |
|---|---|---|
| FR13.1 | Rooms: add and edit number (letters allowed, such as 3A; unique within the branch), floor, type, area and whether the kiosk may offer it. Floors and room types are edited from tabs on the same page. | 44 |
| FR13.2 | The admin can move a room only between Available and Maintenance. A room with a guest in it cannot be changed. | 44 |
| FR13.3 | Rates: a price table by room type and stay option, showing only the stay options the branch has switched on. A blank cell means the option is not offered at check-in, extend and the kiosk. | 45 |
| FR13.3a | When a branch switches on Floor & room rates (Settings), the rate table can also set a flat rate for a whole floor (every room on it, whatever the type) and a rate for a single room. A room rate wins over its floor rate, which wins over the room-type rate; a blank override falls back to the type rate. Overrides apply to the stay price (hourly, overnight, long stay); extension rates stay per branch. Off by default; pricing is by room type until it is switched on. | 45, 46 |
| FR13.4 | Extension rates per block of hours (seeded: 3, 6, 12, 24) apply to all room types. | 45 |
| FR13.5 | Discounts are a fixed amount or a whole percentage, can be limited to certain rates, and can be offered on the kiosk. | 45 |
| FR13.6 | A rate or price change affects new check-ins and sales only and is logged in the audit trail. | 45, 48, 43 |
| FR13.7 | Branch settings cover: stay options (hourly, overnight with check-in and check-out times, long stay with maximum days); extension rule and cycle hours; due-soon warning; deposit on or off, its name and amount; lost key and lost remote amounts; payment methods (at least one); shifts (two or three, any hours); front desks per shift; the late-shift warning and the time after which a late shift stops taking payments; modules (kiosk and hold minutes, reservations and release time, POS and stock, housekeeping with deadline, target and urgent times); transfer reasons, expense categories and the other reason lists, each reason with its "needs a note" and "needs a supervisor" switches; VAT (on or off, rate, TIN, registered name); whether the branch appears on the welcome page; house rules; slip header and address. | 46 |
| FR13.8 | Every setting has a working default, so no front desk screen is blocked by a missing value. Nothing is hard-coded in actions, reports or pages. | 46 |
| FR13.9 | Switching a module off removes its screens from the menus. | 46 |
| FR13.10 | Products: one list for everything charged besides the room — food and drinks, amenities, damages — each with name, category, price, whether stock is tracked, low-stock level and active flag. Price changes are logged. | 48 |

### F14 Owner and branches

| ID | Requirement | Screens |
|---|---|---|
| FR14.1 | The branches list shows, per branch: rooms, staff, the open shift (or "No open shift"), today's sales so far and status (Setting up, Active, Inactive). | 55 |
| FR14.2 | "Open" switches the whole app to that branch with full admin rights and its reports, and lands on that branch's dashboard (61). A chip in the top bar shows which branch is open. The choice is kept on the server. | 55, 61 |
| FR14.3 | A branch can be deactivated but never deleted; its history stays readable by the owner. | 55 |
| FR14.4 | Adding a branch needs a name (unique), an address (printed on slips), a three-character stay-code prefix (unique) and its first admin (full name and username). The form does not save while a field is wrong. | 56 |
| FR14.5 | The owner can copy from an existing branch: room types, rates and extension rates; branch settings; products and prices with stock at 0; transfer reasons and expense categories. Rooms and floors are entered fresh. Guests, stays, money and staff are never copied. | 56 |
| FR14.6 | A new branch is "Setting up" until it has at least one room. | 55, 56 |
| FR14.7 | The owner summary shows sales, check-ins, occupancy and short/over for all branches or one, for a week or a month, with sales per day and a row per branch that opens that branch's daily sales. Its totals equal the sum of the branches' daily reports. | 39 |

### F15 Approvals and audit trail

| ID | Requirement | Screens |
|---|---|---|
| FR15.1 | These actions need an approver to type their own PIN on the spot: transfer, void a line, cancel a stay, void a cash movement, void a POS sale, force-close a shift, close without handover, cancel a reservation, refund a no-show, change the proposed shift period, approve a variance, and any entry whose reason is marked "needs a supervisor". | 14 |
| FR15.2 | The approver must be a `supervisor` or `admin` of the branch, or an `owner`, and must be a different person from the requester. | 14 |
| FR15.3 | A wrong PIN shows "PIN not recognised" and nothing is saved. Five wrong PINs lock approvals by that approver for 15 minutes. | 14 |
| FR15.4 | Each approval is saved with the requester, the approver, the action, the reason and a one-line summary. | 14, 43 |
| FR15.5 | The audit trail lists every void, cancelled stay, transfer, shift close, forced close and settings change with time, details, who did it, who approved it and the reason. It can be filtered by type and by staff member. | 43 |
| FR15.6 | Nothing in the app is deleted, so the audit trail is complete by design. | 43 |

### F16 Problems and resilience

| ID | Requirement | Screens |
|---|---|---|
| FR16.1 | The app is online-only. When the server cannot be reached, a red bar appears on every screen, the board is dimmed, Save buttons are disabled, and the app retries every 5 seconds. | 50 |
| FR16.2 | A signed-in user who opens a page their role cannot use sees a page that names their account and offers the way back to their home screen. | 51 |
| FR16.3 | A missing price greys out the option and explains what to do, in check-in, extend and transfer. | 52 |
| FR16.4 | Two people taking the same room at the same moment: exactly one succeeds; the other sees "Room was just taken" and nothing is saved. | 53 |
| FR16.5 | A shift past its end time shows a reminder bar and keeps all its transactions. Four hours past its end it stops taking new payments and charges until it is closed (FR2.17). | 54 |
| FR16.6 | The attention list shows overdue stays, shifts open too long, cleanings that never finished, and any failed nightly invariant. Nothing is fixed automatically; each button opens the normal screen for that task and records who acted. There is no bulk-fix button. The same rows, built once, feed the "Needs attention" panel of the dashboards (FR17.9). | 49, 60, 61 |
| FR16.7 | Every save either completes fully or leaves nothing behind. An unexpected error shows "Something went wrong. Nothing was saved." with a request id. | all |
| FR16.8 | Eight invariants ([TRD](TRD.md) §8) are checked nightly at 09:05 and after every Feature test. A failure is logged and listed on screen 49; it is never auto-fixed. | 49 |

### F17 Dashboards and stats

| ID | Requirement | Screens |
|---|---|---|
| FR17.1 | Owner dashboard: the owner's home, for all branches or one. A period switch (Today, Yesterday, This week, This month, This year) sets every card. Cards: sales, rooms occupied now, check-ins and check-outs, short and over for the last 7 days. Below: sales for the last 7 days with the busiest day marked, "Branches now" (each branch's open shift, who is on duty, rooms occupied, sales so far), how guests paid, and "Needs attention". | 60 |
| FR17.2 | Branch dashboard: home of the admin and back office, and what the owner sees after opening a branch. Cards: sales, occupancy now, guests in and out today with the number forwarded, unpaid balances. Below: rooms by status in one bar, sales by hour with the busiest hour marked, the open shift with each front desk's check-ins and collections so far, housekeeping today, and "Needs attention". | 61 |
| FR17.3 | Front desk "Today": home of the front desk and the supervisor. Tiles for arriving today, due out in 30 minutes, overdue, unpaid folios and kiosk holds; lists of who is leaving soon and who is arriving; rooms available, uncleaned and being cleaned; buttons to the room board and walk-in check-in. Every tile and row opens the board (4), the folio (9) or the reservation (57). It shows counts and names only. | 62 |
| FR17.4 | Stats: a date range with quick choices sets the whole page. The grain follows the range: by hour up to 2 days, by day up to 62 days, by month beyond. Cards give totals; below are check-ins by weekday and hour as a heat grid, the stay options guests chose, sales by room type, top products, payment methods, occupancy by day and a front desk table. | 63 |
| FR17.5 | Dashboards and Stats read only summary tables that are rebuilt from the ledgers every minute, plus live state read directly and bounded to now (the open shift and its members, rooms by status, in-house stays, kiosk holds). They never add up the ledgers during a request. Today (62) is all live state. | 60–63 |
| FR17.6 | Every dashboard figure loads on its own with a skeleton in its place, so the page appears at once. The figures refresh every 30 seconds without reloading the page; the refresh is skipped while the tab is hidden or a request is still running, and a failure shows "Could not refresh. Retry". Refreshes do not keep a session alive. | 60, 61 |
| FR17.7 | Each card states the period it covers. The change figure compares with the same-length period just before; for "today" it compares with yesterday up to the same time of day, never part of a day with a whole day. With no earlier data there is no change figure. | 60, 61, 63 |
| FR17.8 | Short and over are added separately and never netted against each other. | 60, 38b |
| FR17.9 | "Needs attention" lists only things someone should act on. Each row has a severity, one sentence and the screen that fixes it. The dashboards show the top rows and screen 49 shows all of them, from the same list. Empty state: "All good." | 60, 61, 49 |
| FR17.10 | A front desk user never receives collected amounts or expected cash for an open shift on any page. The server leaves them out; hiding them in the page is not enough. The open-shift block of the branch dashboard is for managers only. | 61, 62 |
| FR17.11 | Charts are small components of our own that use the design tokens. Hovering shows the value; a chart with no data shows a plain empty state. | 60, 61, 63 |
| FR17.12 | A check every 10 minutes compares the summary tables with the ledgers for today and yesterday and logs an error when they differ or when a summary is more than 10 minutes behind. The summary tables hold nothing that cannot be rebuilt. | 60–63 |
| FR17.13 | The owner sees the owner dashboard, and the branch dashboard and Stats for any branch with the branch picker. `admin`, `backoffice` and `supervisor` see the branch dashboard and Stats of their own branch. `frontdesk` and `supervisor` see Today. | 60–63 |

### F18 Welcome page

| ID | Requirement | Screens |
|---|---|---|
| FR18.1 | The address `/` is a public page: the Homi logo, one "Staff sign in" link to the sign-in (1), a headline, the room types with their lowest hourly price and their overnight price, and three steps on how a stay works. | 59 |
| FR18.2 | Prices are read live from the rate table (45); the page stores none. A stay option the branch has switched off, or that has no rate, is not shown. | 59, 45 |
| FR18.3 | With more than one active branch a branch picker sits under the headline. A branch that is not active, or is switched off for the welcome page, is not listed. | 59, 46 |
| FR18.4 | A signed-in user sees "Dashboard" instead of "Staff sign in"; it opens their role home. | 59 |
| FR18.5 | The page shows no availability, takes no bookings and shows no staff, guest or sales data. | 59 |

## 7. Non-functional requirements

Detail lives in the TRD; this table states the targets the product is accepted against.

| Area | Requirement | Target | Source |
|---|---|---|---|
| Performance | Room board first load, 300 rooms | ≤ 800 ms server time, ≤ 6 queries | [TRD](TRD.md) §7 |
| Performance | Room board refresh | ≤ 300 ms, partial reload | [TRD](TRD.md) §7 |
| Performance | Any action (check-in, payment, check-out) | ≤ 500 ms server time | [TRD](TRD.md) §7 |
| Performance | Freezing a shift at handover with 300 in-house stays | ≤ 2 s | [TRD](TRD.md) §7 |
| Performance | Shift report and Z-report of a closed shift | ≤ 500 ms | [TRD](TRD.md) §7 |
| Performance | Daily and owner summary over 1 year of data | ≤ 1.5 s | [TRD](TRD.md) §7 |
| Performance | Front desk JavaScript bundle | ≤ 350 kB gzip (includes Nuxt UI) | [TRD](TRD.md) §7 |
| Performance | Dashboard (60, 61) first response; each deferred card | ≤ 300 ms each; ≤ 3 queries per card | [TRD](TRD.md) §7 |
| Performance | Rebuilding the summaries of one busy branch-day | ≤ 2 s | [TRD](TRD.md) §7 |
| Performance | Stats (63) over one year | ≤ 1 s | [TRD](TRD.md) §7 |
| Reliability | Every action is one database transaction; domain errors roll back fully | No half-saved data | [TRD](TRD.md) §3, §8 |
| Reliability | Nightly invariants 1–8 | All pass | [TRD](TRD.md) §8 |
| Reliability | Backups | Nightly dump, 14 daily copies kept | [TRD](TRD.md) §5 |
| Security | Passwords and PINs | bcrypt; password ≥ 8 characters; PIN 4 digits, never shown | [TRD](TRD.md) §9 |
| Security | Throttling | 5 failed sign-ins → 15-minute lock; 3 wrong switch PINs → full sign-in; 5 wrong approval PINs → 15-minute lock for that approver; 10 wrong PINs in a row on one computer → PIN entry locked there until a supervisor or admin unlocks it | [TRD](TRD.md) §9, §4.12 |
| Security | Sessions and transport | 12-hour session, CSRF on every form, HTTPS with HSTS in production | [TRD](TRD.md) §9 |
| Security | Authorisation | Policy on every controller method; branch scope on every query | [TRD](TRD.md) §9 |
| Privacy | Guest data kept | Name, optional mobile, optional plate number; mobile shown only on folio and reservation screens; never in logs | [TRD](TRD.md) §9 |
| Compatibility | Browsers | Latest Chrome and Edge (desktop), Chrome on Android (housekeeping), Chrome kiosk mode | [TRD](TRD.md) §1 |
| Compatibility | Screen sizes | Desktop ≥ 1280×720; kiosk 1080×1920 portrait; phone ≥ 360 px wide | [TRD](TRD.md) §1 |
| Usability | Kiosk touch targets | ≥ 56 px | [TRD](TRD.md) §6 |
| Localisation | Language, currency, time | English; ₱1,234.00; stored UTC, shown Asia/Manila | [TRD](TRD.md) §1 |
| Printing | Slips and reports | 80 mm thermal slips (8, 18, 26) and A4 reports (37–43) through browser print; every print logged | [TRD](TRD.md) §6, §4.10 |
| Exports | Z-report and day Z; other reports | PDF (A4) and CSV; CSV only for the other reports; every export logged | [TRD](TRD.md) §6 |
| Live updates | Polling, no websockets | Board 15 s; kiosk holds 5 s; housekeeping 20 s; kiosk availability 20 s; dashboards 30 s over summaries rebuilt every minute | [TRD](TRD.md) §6, §4.11 |
| Quality | Tests and checks before a phase is done | Pint, Larastan level 6, Pest on MySQL, Vitest and build all green | [TRD](TRD.md) §10, §11 |
| Cost | Paid services | ₱0.00 | [TRD](TRD.md) §2 |
| Design | Look and components | Nuxt UI v4 themed with the tokens in [DESIGN_SYSTEM](DESIGN_SYSTEM.md); our own components only where Nuxt UI has none; no chart library. Level of finish: the owner's Euphoria system. Both colour modes are checked in phase 10; slips and reports print light. | [TRD](TRD.md) §1, §2, §12 · [DESIGN_SYSTEM](DESIGN_SYSTEM.md) |

## 8. Data and content requirements

### 8.1 What must exist before a branch can operate

| # | What | Who | Screen | Needed for |
|---|---|---|---|---|
| 1 | The branch: name, address, slip header, three-character code prefix | Owner | 56 | Everything; stay codes and slips |
| 2 | The first admin of the branch | Owner | 56 | Branch setup |
| 3 | Floors and room types | Admin | 44 | Rooms and rates |
| 4 | At least one room (the branch then becomes Active) | Admin | 44 | Room board, check-in |
| 5 | Stay options switched on, and a rate for every room type and stay option the branch sells | Admin | 46, 45 | Check-in. A missing rate removes that option. |
| 6 | Extension rates for the hour blocks the branch offers | Admin | 45 | Extend |
| 7 | Shift definitions (two or three) and front desks per shift | Admin | 46 | Open shift, business day |
| 8 | Deposit setting, lost-item amounts and payment methods (at least one) | Admin | 46 | Check-in and check-out totals |
| 9 | At least one front desk user and one supervisor or admin with a PIN set | Admin, then each user | 47 | Shifts and approvals (the approver must differ from the requester) |
| 10 | Transfer reasons and expense categories | Admin | 46 | Transfer, expenses |
| 11 | Products with prices and opening stock (when POS is on) | Admin, front desk | 48, 20 | Add charge, POS, damages at check-out |
| 12 | Housekeepers with assigned floors (when housekeeping is on) | Admin | 47 | Cleaning queue |
| 13 | A kiosk device user, kiosk-enabled rooms and house rules (when the kiosk is on) | Admin | 47, 44, 46 | Kiosk |
| 14 | Discounts, if the branch gives any | Admin | 45 | Check-in, kiosk |
| 15 | Reason lists, each reason with its note and supervisor switches | Admin | 46 | Voids, cancels, variances, wastage |
| 16 | VAT details (TIN, registered name, rate), only for a VAT-registered branch | Admin | 46 | The VAT block on the Z-report (37b, 38b). Off by default; nothing is blocked without it (OD4). |

Items 5 to 8, 10, 11 and room types can be copied from an existing branch when the owner creates the new one (56). Every setting on `branches` has a working default, listed in [DATABASE_SCHEMA](DATABASE_SCHEMA.md) §4.1.

### 8.2 Seed data

Development and staging are seeded with one made-up branch, "Hotel Dalisay GenSan" (prefix `DGS`): 46 rooms on 3 floors in 3 types, AM and PM shifts, a full rate table with the Suite 18-hour rate deliberately missing (for screen 52), 4 extension rates, 3 discounts, 23 products, 11 branch users and 1 owner. A local-only seeder recreates the AM shift of 11 November 2026 exactly as drawn on screens 37, 37b and 37c (Z number `Z-0629`), so the built reports can be compared with the mockup figure by figure. The full list is in [DATABASE_SCHEMA](DATABASE_SCHEMA.md) §5. All sample names and prices are made up. Production is not seeded with sample data.

### 8.3 Content

| Content | Where it comes from |
|---|---|
| Product name, logo, brand colours | Name "Homi" (one config value); the owner's HOMI wordmark in `resources/images/brand/` (`homi-logo.png`, `homi-logo-white.png`); brand blue `#009EF5`, primary UI blue `#0077C2`, as tokens in [DESIGN_SYSTEM](DESIGN_SYSTEM.md) |
| Slip header and address | Branch settings (46) |
| House rules on the kiosk | Branch settings (46) |
| Room type descriptions on the kiosk | Room types (44) |
| Room types and prices on the welcome page (59) | Room types (44) and the rate table (45), read live |
| Photographs on the sign-in (1), welcome page (59) and kiosk welcome (27) | Free stock photographs from Unsplash (listed in [ASSETS](ASSETS.md)) in version 1; the hotel's own photos replace them one for one when supplied (OD2) |
| TIN and registered name on the Z-report | Branch settings (46), only when VAT is on |
| Error and empty-state messages | As worded in the mockup captions and screens 50–54 |

## 9. Assumptions and dependencies

**Assumptions**

1. Each branch has a stable internet connection. The app is online-only (50).
2. Each front desk has one desktop computer with Chrome or Edge at 1280×720 or larger and an 80 mm thermal printer installed as a normal Windows printer.
3. Housekeepers have their own Android phones with Chrome and mobile data or Wi-Fi.
4. A branch that uses the kiosk supplies a portrait 1080×1920 touchscreen that can run Chrome in kiosk mode.
5. One installation serves one owner company. Unrelated owners get separate installations.
6. Staff can each remember a personal 4-digit PIN, and at least one supervisor or admin is reachable during every shift to approve.
7. Branches start with clean data. Guests in house on cut-over day are checked in again in the new system.
8. Cash, GCash and card are the only payment methods; GCash and card are confirmed by staff outside the app.
9. The first owner account of an installation is created at install time; the procedure is in [START_HERE](../START_HERE.md).
10. Nuxt UI v4 supplies the standard controls (buttons, forms, modals, toasts, skeletons, dropdowns, colour mode) and works with Inertia and Vue 3 outside Nuxt. Components of our own are written only for what it does not have: room tile, money input, PIN pad, stat card, charts and slips.
11. A figure on a dashboard may be up to about a minute old. Anything that must be exact — a folio, the shift report, the Z-report — is read from the ledger or the frozen totals, never from a summary.
12. Each front desk computer keeps its cookies, so it can be recognised for the sign-in facts (FR1.13) and the PIN lockout (FR1.14). A computer that clears them is treated as new.

**Dependencies**

| Dependency | Needed by | Note |
|---|---|---|
| A Linux VPS or shared host with SSH, PHP 8.3, MySQL 8.0, HTTPS and cron | Phase 10 (release); staging earlier | OD1. Must cost ₱0 in paid services beyond what the owner already has. |
| A domain name | Phase 10 | OD1 |
| Real branch names, rooms and rates | First customer go-live | OD2; entered through screens 55, 56, 44, 45 |
| The packages in [TRD](TRD.md) §2 | All phases | All free and open source |

## 10. Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R1 | Internet or server outage at a branch | Front desk cannot check in or take payments; the kiosk stops | Connection bar (50) and kiosk offline screen (31b) stop half-saved work. Nightly backups with 14 copies. Offline mode is on the Later list. |
| R2 | Staff resist the blind count and the 4-step close because V2 let them type the expected number | Slow closes, pressure to bypass | Close is guided and targeted at under 5 minutes (G5). The expected amount is never sent before the count (FR2.8). The variance note makes small differences routine, not a crisis. |
| R3 | PINs are shared, so approvals stop meaning anything | Voids and transfers without real control | PINs are personal, set by the user, never shown, and resettable only by an admin. Every approval names the approver in the audit trail (43). The approver cannot be the requester. |
| R4 | Nobody with approval rights is present | Transfers, voids and close-without-handover are blocked | Each branch needs at least one supervisor per shift (assumption 6). The owner can approve. Approval from a phone is on the Later list. |
| R5 | A shift is left open for a day or more | The business day stretches; reports wait | Reminder bar after 30 minutes (54), attention list after 2 hours (49), force close with a count and a PIN. Transactions always stay with the shift they were made in. |
| R6 | A report disagrees with the ledger after a code change | Loss of trust, the failure that killed V2 | Frozen totals, one report service, reconciliation tests, the nightly reconcile job and the eight invariants. |
| R7 | One developer, an 18-feature scope | Late delivery or shortcuts in the core | Phases are ordered so the money and shift core is built and tested first (phases 2–5). F10 and F11 are optional modules and can ship last without blocking a branch. |
| R8 | Thermal printing through the browser print dialog differs per printer | Clipped or mis-sized slips | One print stylesheet at 72 mm printable width; tested on the customer's printer in phase 10. Slips can always be reprinted. |
| R9 | Free or low-cost hosting is slow or limits cron and queues | Board refresh over budget; scheduled jobs late | Performance budgets are tested on seeded data; only the database driver is needed for queue, cache and sessions; one cron entry runs the scheduler. Hosting is chosen against these needs (OD1). |
| R10 | Cut-over from V2 with guests in house | Guests missing from the first shift's records | Clean-start rule (assumption 7): cut over at a shift change and check in each in-house guest again; V2 stays available read-only. |
| R11 | A branch switches the housekeeping module off | Rooms would stay Uncleaned with nobody to release them | Settled in [TRD](TRD.md) §4.9: with the module off, check-out and transfer-out set the room straight to Available and no cleaning task is created. Covered by a test in phase 6. |
| R12 | Kiosk hardware or the kiosk browser fails | Guests cannot self-select | The kiosk is optional; walk-in check-in (6) always works and never depends on it. |
| R13 | A rate is left blank by mistake | A stay option silently disappears from sale | The option is greyed out with an explanation (52), not hidden, and rate changes are in the audit trail (43). |
| R14 | The summary tables drift from the ledgers | A dashboard shows a wrong figure and trust is lost | `CheckSummaries` compares them every 10 minutes and logs any difference; the tables can be rebuilt from the ledgers at any time; signed papers (37, 37b, 37c, 38, 38b) never read them. |
| R15 | A Nuxt UI release changes components or theming | Screens break or look different after an upgrade | The version is pinned to ^4; theming lives in one place; domain components are our own; the gallery at `/dev/components` and the Vitest tests show a break before release. |
| R16 | The larger bundle slows the front desk computer | Slow first load | Budget raised to 350 kB gzip and measured on every build; charts are our own small components, not a library. |
| R17 | Staff feel the safeguards as obstacles (cash-out cap, receiver must differ from counter, late shift stops taking payments) | Pressure to share accounts or PINs | Each refusal says what to do next; a supervisor can approve or unlock on the spot; force close is on the attention list (49). The shared drawer and the PIN handover staff already know are kept. |
| R18 | A forgotten shift passes the 4-hour limit during a busy hour | Payments are refused until the shift is closed | The amber bar warns from 30 minutes (54) and the attention list from 2 hours (49); the limit is a branch setting. |
| R19 | The first customer turns out to be VAT-registered after go-live | Z-reports printed without VAT lines | The VAT block is built and tested in phase 8 and is one switch in settings (46); OD4 is asked before go-live. |

## 11. Release plan

### 11.1 Phases

| Phase | Name | What is delivered | Features |
|---|---|---|---|
| 0 | Setup | Laravel 13 project, Nuxt UI v4, @vueuse/core, date-fns, dompdf, toolchain, CI commands, empty layouts | — |
| 1 | Design system and components | Nuxt UI themed with the Homi tokens, our own components, the chart kit, gallery at `/dev/components` | — |
| 2 | Data layer | All 31 migrations (including the version 1.1 tables, 000026–000031), models, enums, seeders | — |
| 3 | Core logic with tests | Pricing, cash maths, every Action, report service, invariants, PIN attempts and locks with the approvals, with the tests in [TRD](TRD.md) §10 | Logic of F1 (PINs), F2–F12, F15 |
| 4 | Front desk core flow | Photo sign-in with the remembered-branch figures, role homes, server-built navigation, Today (62), open and join shift, PIN switch, board, check-in, folio, charges, payments, extend, transfer, approvals, check-out, problem screens 50–53 | F1, F2 (open/join), F3–F7, F15 (14), F16, F17 (62) |
| 5 | Shift cash, close and handover | Cash movements, all shift safeguards, papers check list, count draft, 4-step close, handover, Z number and grand total at close, shift slip, late bar and the late-shift limit | F2, F16 (54) |
| 6 | POS, stock and housekeeping | POS, stock, cleaning queue on the phone, reason lists for wastage | F8, F9 |
| 7 | Kiosk and reservations | Kiosk screens, holds sheet, confirm hold, reservation list and form, expiry and no-show jobs, reason lists for cancelled reservations | F10, F11 |
| 8 | Reports | Hub, shift, Z-report (37b, 37c) from one set of lines, preview, PDF and CSV exports, print log, daily, Close the day (38b), optional VAT block, occupancy, housekeeping, inventory, audit; nightly reconcile | F12, F15 (43) |
| 9 | Admin, owner, dashboards and stats | Rooms, rates, settings, users, products, summary tables with their rebuild and check jobs, attention list, owner dashboard (60), branch dashboard (61), Stats (63), branches, add branch, owner summary | F13, F14, F17, F1 (47), F16 (49) |
| 10 | Polish, printing and release | Welcome page (59), dark mode check, print stylesheets on real printers, performance budgets, staging run, deployment, backups | F18, all |

Task lists per phase are in [ROADMAP](ROADMAP.md).

### 11.2 Version 1.0 release criteria

Version 1.0 is released when all of these are true:

1. All 71 mockup frames (63 screens + 8 variants) are built and each has been compared with its mockup number.
2. Every functional requirement in section 6 passes its test or manual check.
3. Every named test in [TRD](TRD.md) §10 exists and passes on MySQL 8.0.
4. The "before done" command in [TRD](TRD.md) §11 is green: Pint, Larastan level 6, Pest, Vitest, production build.
5. Every performance budget in [TRD](TRD.md) §7 is met on the seeded data.
6. On staging, a scripted run of at least 6 consecutive shifts over 3 business days — including a late close, a short shift, a shortage payment, a void after close, a close without handover, and a charge in one shift paid in the next — ends with `ReconcileBusinessDay` and `CheckInvariants` reporting no mismatch, six consecutive Z numbers, a grand total that chains, the three days closed in order (38b), and `CheckSummaries` reporting no difference between the dashboards and the ledgers.
7. Screens 37, 37b and 37c built from `DemoShiftSeeder` show the same figures as the mockup (Z-0629, gross sales ₱18,460.00, net sales ₱18,160.00, collected ₱18,045.00, expected ₱13,195.00, counted ₱13,145.00, short ₱50.00, grand total after ₱11,500,500.00), and the PDF and CSV exports of 37b carry the same figures.
8. Slips 8, 18 and 26 print correctly on an 80 mm thermal printer, and reports 37–43 on A4. Every print and export appears in the print log.
9. `migrate:fresh --seed` works; production is deployed over HTTPS with the scheduler cron, the queue worker and the nightly backup running; the deploy is tagged `v1.0.0`.
10. Paid services in use: ₱0.00.

## 12. Open decisions

### 12.1 Settled (mockup Decisions table, approved 11 November 2026)

| # | Decision | Outcome |
|---|---|---|
| D1 | Check-in slip on the thermal printer (8) | Approved. A slip prints after every check-in. |
| D2 | Count cash by denomination at close (23) | Approved. Staff type pieces per bill; the count is blind. |
| D3 | Nightly check that shifts, days and the ledger agree (38) | Approved. A job runs at 09:00 and flags any mismatch on the report hub. |
| D4 | "Needs attention" list instead of ghost-room tools (49) | Approved. One list, no bulk fix. |
| D5 | Automatic overtime charge | Not planned. The row shows Overdue and the front desk extends by hand (12). |
| D6 | Late-cleaning report (41) | Approved. Counts late rooms only. |
| D7 | Product name, logo and brand colours | Settled after the mockup: product name Homi, blue brand (`#009EF5`), existing HOMI logo. The name is one config value and the colours one token set. |
| D8 | Seed data | One made-up branch, "Hotel Dalisay GenSan", 46 rooms. Real branches are entered through screens 55 and 56. |
| D9 | Z-report room chart layout (37c; numbered 37b before mockup v3) | Approved. Same columns and order as the V2 BigBoss sheet, with red FWD rows. It is now the second tab of the Z-report. |
| D10 | Unclaimed deposits | Approved. Decided once at check-out as returned or forfeited (17); a forfeited deposit is income of that shift. |
| D11 | Reservation no-show (57) | Approved. Released 2 hours after arrival; money paid ahead is kept unless a supervisor refunds it. |

The unnumbered rows of the Decisions table are also settled and are reflected in sections 5 and 6: forwarded guests saved once; the owner role and branches; the general product with branch settings; own account and PIN per staff; one shared drawer per shift; kiosk check-out removed; clean data start; reservations as a list and a form; GCash and card by reference number.

### 12.2 Settled after approval (11 November 2026, mockup v3)

These four rows were added to the mockup Decisions table the same day, after the first approval. They are unnumbered there.

| Decision | Outcome |
|---|---|
| Design at the level of the owner's Euphoria system | Settled. Nuxt UI v4 is the component library, themed with the Homi tokens; a light side menu and server-built navigation. After a second review on 11 Nov the look became Homi's own: IBM Plex Sans (Newsreader for public-page headings), warm neutral greys, thin borders instead of shadows, no gradients, statuses as small soft labels, the room board as a plain table, one row per room, real photographs and quiet motion on the sign-in (1), welcome page (59) and kiosk (27). Charts are our own components; no chart library. This replaces the earlier rule of hand-built components and no UI kit. |
| Dashboards and stats (60–63) | Settled. An owner dashboard, a branch dashboard, a front desk "Today" page and a Stats page. They read summary tables rebuilt every minute, load card by card and refresh every 30 seconds. "Needs attention" is a panel on the dashboards and a full page at 49. The front desk never sees money for an open shift. |
| Z-report rebuilt (37b, 37c, 38b) | Settled. A Z number per shift and a running grand total; one sales-and-cash column; payment methods and discounts by name; a fixed exceptions list; by front desk; document numbers; signatures; a preview while the shift is open; PDF and CSV. A day-level close with its own number. VAT lines are a branch setting, off by default. The V2 room chart is kept as the second tab (37c). |
| Shift safeguards adopted (3, 21–25, 54) | Settled. Every money row records the person as well as the shift; a papers check list with no amounts; a count draft saved while typing; the counter cannot approve their own variance or receive the drawer; a double-tap guard and a cash-out cap; reason lists with note and supervisor flags; a PIN lockout per computer; a shift 4 hours past its end stops taking payments. Homi keeps one shared drawer per shift, the count at every shift change and the handover by PIN. |

### 12.3 Still open

| # | Item | Needed from | Until then |
|---|---|---|---|
| OD1 | Hosting provider and domain | Gab | Development and staging run locally; any host meeting [TRD](TRD.md) §1 works. |
| OD2 | Real branch names, room lists and rates for the first customer | First customer | Entered through screens 55, 56, 44 and 45 when known; the seeded sample branch is used for development. |
| OD3 | Peso penalty rule for late cleaning | Gab | On the Later list. The report (41) counts late rooms only. |
| OD4 | Whether the first customer is VAT-registered | First customer | Decides only whether the VAT block is switched on for that branch (46). It is off by default and blocks nothing; the block is built and tested either way. |

Nothing in phases 0–10 is blocked by OD1–OD4. OD1, OD2 and OD4 are needed only for the production go-live at the end of phase 10; OD3 is after version 1.0.
