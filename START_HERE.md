# Start here

For Gab. How to set up the project and what to tell Claude Code for each build phase.

## 1. What is in this folder

```
HotelV4/
├─ CLAUDE.md            Claude Code reads this every session
├─ START_HERE.md        this file
└─ docs/
   ├─ PRD.md  TRD.md  DATABASE_SCHEMA.md  SYSTEM_DESIGN.md
   ├─ APP_FLOW.md  DESIGN_SYSTEM.md  ROADMAP.md  ASSETS.md
   └─ mockup/hotel-mockup.html     the approved screens, v3: 63 screens + 7 variants (open in a browser)
```

There is no app code yet. Phase 0 creates the Laravel project **in this folder**, around these files.

## 2. One-time setup on your machine

1. Install PHP 8.3, Composer, Node 22 and MySQL 8.0 (Laragon or Herd covers all of it on Windows).
2. Create two empty databases:

```sql
CREATE DATABASE hotelv4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE hotelv4_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. Open a terminal in `C:\Users\User\Documents\GitHub\HotelV4` and start Claude Code there (or open the folder in the Claude desktop app's Code tab).
4. Keep `docs/mockup/hotel-mockup.html` open in a browser while you review each phase.

`../HotelV2` stays where it is as a reference. Do not point Claude at it to copy code.

## 3. The prompt for each phase

Run them in order, one per session. Start a fresh session for each phase so the context is clean. After each phase, look at the result, commit, then move on.

**Phase 0 — Setup**

```
Do Phase 0 of docs/ROADMAP.md. Read CLAUDE.md and docs/TRD.md §1–3 and §11 first. Create the Laravel 12 project in this folder without removing docs/, CLAUDE.md or START_HERE.md. Install and configure Nuxt UI v4 for Vue + Vite + Inertia, @vueuse/core, date-fns and barryvdh/laravel-dompdf exactly as listed in TRD §2; do not add any chart, table or date-picker library. Run the full check. Stop when the Phase 0 checklist and its "Done when" line are met, and tell me exactly what passed.
```

**Phase 1 — Design system and components**

```
Do Phase 1 of docs/ROADMAP.md. Read docs/DESIGN_SYSTEM.md, docs/ASSETS.md, docs/TRD.md §2, §4.11 (charts) and §12, and the "Building blocks" section and screens 60–63 of docs/mockup/hotel-mockup.html first. Theme Nuxt UI with the Homi tokens, use Nuxt UI components first, and build our own components only where Nuxt UI has none. Build the chart kit (VBars, HBars, Shares, HeatGrid, Spark) with no chart library, the layouts and the /dev/components gallery. Run the full check. Stop when the checklist is done and show me the gallery.
```

**Phase 2 — Data layer**

```
Do Phase 2 of docs/ROADMAP.md. Read docs/DATABASE_SCHEMA.md in full (the version 1.1 tables are in §4.16 and migrations 000026–000031 in §6) and docs/TRD.md §3 and §5 first. Write all 31 migrations, the enums, models, factories and seeders exactly as specified, including CHECK constraints and generated columns. Run migrate:fresh --seed and the tests on MySQL. Stop when the checklist is done.
```

**Phase 3 — Core logic with tests**

```
Do Phase 3 of docs/ROADMAP.md. Read docs/TRD.md §3, §4 (including the PIN lockout row of §4.12), §8 and §10, docs/DATABASE_SCHEMA.md §4.15, §4.16 (pin_attempts, pin_locks) and §8, and docs/APP_FLOW.md §5 first. No screens in this phase. Write the tests listed in TRD §10 for pricing and stays first, then the services and actions until they pass. Run the full check. Stop when the checklist is done and report the test count.
```

**Phase 4 — Front desk core flow**

```
Do Phase 4 of docs/ROADMAP.md. Read docs/APP_FLOW.md §1–4 and §6, docs/TRD.md §4.1 and §4.11 (Today shows no money), docs/DESIGN_SYSTEM.md §5–6, and mockup screens 1–18, 50–53 and 62 first. Build the photo sign-in (1) with the facts of the remembered branch, the role homes, the server-built navigation and Today (62). Build each screen from its mockup frame with Nuxt UI components first and our own components where Nuxt UI has none. Run the full check. Stop when the checklist is done, then walk me through a full stay in the browser, starting from the sign-in.
```

**Phase 5 — Shift cash, close and handover**

```
Do Phase 5 of docs/ROADMAP.md. Read docs/TRD.md §4.5–4.6, §4.10 (Z number and grand total), §4.12 (every shift safeguard) and the "Shifts" and "Shift safeguards" tests in §10, docs/DATABASE_SCHEMA.md §4.1, §4.6–4.9, §4.16 (cash_count_drafts, reasons, print_logs) and §8.10, docs/APP_FLOW.md §4.15–4.16, and mockup screens 3, 21–26 and 54 first. Keep one shared drawer per shift, the count at every shift change and the handover by the incoming front desk's PIN. Run the full check. Stop when the checklist is done and show me two consecutive shifts with the forwarded guests matching, consecutive Z numbers and a chained grand total.
```

**Phase 6 — POS, stock and housekeeping**

```
Do Phase 6 of docs/ROADMAP.md. Read docs/DATABASE_SCHEMA.md §4.13–4.14, §4.16 (reasons) and §8.3, §8.11, docs/TRD.md §4.7, and mockup screens 19, 20 and 32–35 first. Run the full check. Stop when the checklist is done.
```

**Phase 7 — Kiosk and reservations**

```
Do Phase 7 of docs/ROADMAP.md. Read docs/TRD.md §4.8 and §6, docs/DATABASE_SCHEMA.md §4.16 (reasons), §8.2 and §8.7, docs/APP_FLOW.md (kiosk and reservation flows), and mockup screens 5, 7, 27–31b, 57 and 58 first. Run the full check. Stop when the checklist is done.
```

**Phase 8 — Reports**

```
Do Phase 8 of docs/ROADMAP.md. Read docs/TRD.md §4.5, §4.6, §4.10, §6 (Exports, Printing), §8 and the "Reports" and "Z-report and day close" tests in §10, docs/DATABASE_SCHEMA.md §4.1 (VAT settings), §4.7, §4.8, §4.16 (day_closings, print_logs), §7 and §8.10b, docs/APP_FLOW.md §4.22–4.23, and mockup screens 36–43, 37b, 37c and 38b first. A closed shift must be read from its frozen totals and forwarded-stays snapshot, never recalculated. Build the Z-report (37b sales and cash, 37c rooms by floor) from one builder array that feeds the screen, the slip, the PDF and the CSV; add the preview for an open shift, the print log, Close the day (38b) and the VAT block, which stays off unless the branch switches it on. Run the full check. Stop when the checklist is done and show me screens 37, 37b and 37c for the demo shift next to the mockup, plus the PDF.
```

**Phase 9 — Admin, owner, dashboards and stats**

```
Do Phase 9 of docs/ROADMAP.md. Read docs/TRD.md §4.1, §4.9, §4.11, §6 (Scheduled jobs), §7, §9 and the "Dashboards and summaries" tests in §10, docs/DATABASE_SCHEMA.md §4.1–4.6 and §4.16 (summary tables), docs/APP_FLOW.md §4.24–4.25, and mockup screens 39, 44–49, 55, 56, 60, 61 and 63 first. Dashboards and Stats read the summary tables only; build RebuildSummaries, CheckSummaries and AttentionList before the pages. Run the full check. Stop when the checklist is done and show me a payment appearing on the branch and owner dashboards without a reload.
```

**Phase 10 — Polish, printing and release**

```
Do Phase 10 of docs/ROADMAP.md. Read docs/TRD.md §6, §7 and §11, docs/PRD.md §6 (F18) and mockup screen 59 first. Build the welcome page (59) at /. Then go through all 70 mockup frames against the built app, in light and dark mode, and list the differences before fixing them. Run the full check. Stop when the checklist is done.
```

## 4. Tips

- **One phase per session.** If a phase is long, say "continue Phase N" in a new session; the roadmap checkboxes show where it stopped.
- **Make Claude tick the boxes.** Ask it to update the checkboxes in docs/ROADMAP.md as it finishes each task, and commit after each phase.
- **If something is unclear, it should ask.** CLAUDE.md tells it to stop when the mockup and a doc disagree. Answer, then have it fix the doc as well as the code.
- **Changing the design.** Change the mockup and the docs first, then the code. TRD wins on technical rules and DATABASE_SCHEMA wins on names.
- **Level of finish.** The owner's Euphoria system (`v2-pos` branch) is the reference for how finished a screen should look. Its structure and conventions are copied; its palette is not.
- **Never accept "tests pass" without the output.** Ask for the test count and the last lines of the run.
- **Check the money by hand once per phase.** Run one stay and one shift yourself and compare the shift report with a calculator. From phase 8, add up the "Sales and cash" column of the Z-report (37b) the same way.

## 5. Backups (set up before going live)

Add a nightly cron on the server that runs `mysqldump` of the production database to a second disk or free cloud storage, keeps 14 daily copies, and try a restore into a scratch database once before launch. Phase 10 has this on its checklist.

## 6. Brand and real branches

- The brand is Homi: logos are in `resources/images/brand/`, the blue is `#009EF5`. To change it later see docs/ASSETS.md §2.
- Real branches, rooms and rates: enter them as the owner through screens 55, 56, 44 and 45. Do not edit the seeders for production data.
- VAT: the VAT block on the Z-report is off by default. Switch it on in branch settings (46) only for a VAT-registered branch (docs/PRD.md §12, OD4).
