# Design System — Homi

| | |
|---|---|
| Version | 2.0 · 16 November 2026 |
| Status | Approved with mockup **v6** (operations look after the owner's Dribbble reference: labelled sidebar with branch switcher, page header with icon tile, filter row, people in tables, timelines; Geist; redesigned sign-in and welcome). Section 0 lists what v6 changed; where an older section still says otherwise, section 0 and the mockup win. Structure from v3 is unchanged: Nuxt UI, layouts, dashboards, Z paper. |
| Component library | **Nuxt UI v4** on Tailwind CSS 4, themed once with the Homi tokens. Our own components only for what Nuxt UI does not have. |
| Theme | Light by default; dark through the Nuxt UI colour mode (section 2.4). Sign-in, welcome, kiosk and print are light only. |
| Source of truth | The `<style>` block of the [mockup](mockup/hotel-mockup.html) — the blocks that start `/* ---------- v6: operations look` and `/* ---------- v6 public pages` override every earlier block wherever they set a value — plus screens 1, 4, 37a, 59, 60, 61, 62 and the "Building blocks" section. If this file and the mockup disagree on a look, stop and ask. Technical rules: [TRD](TRD.md). |
| Where the tokens live in code | `resources/css/app.css` (the `--hm-*` layer, the `--ui-*` mapping, `@theme` blocks, font faces, keyframes) and `vite.config.ts` (Nuxt UI colours and component overrides) |
| Related | [ASSETS](ASSETS.md) · [SYSTEM_DESIGN](SYSTEM_DESIGN.md) · [APP_FLOW](APP_FLOW.md) · [TRD](TRD.md) |

Homi is used by front desk staff for a whole 12-hour shift, by managers who want one calm page of numbers, by guests for one minute on a kiosk, and by housekeepers with one hand on a phone. The look is professional, clean and easy on the eyes: cool neutral greys around the Homi blue, white cards with a thin border and a hairline shadow, one blue for the one main action, colour kept for status, people shown with initials avatars, and real photographs on the three pages people see first (sign-in, welcome page, kiosk welcome). Nothing is decorated for its own sake.

## 0. What mockup v6 changed (16 Nov 2026) — read first

The owner asked for a less generic look, using a Dribbble hotel-software shot as the reference for layout (not for colours or content), and a more professional sign-in and welcome page with animation. The structure, flows, rules and screen numbers are unchanged. The design was checked against the impeccable design skill (`.claude/skills/impeccable`): its craft floor and its list of overused AI-default fonts (IBM Plex and Newsreader are on it).

| Area | Before (v4/v5) | Now (v6) |
|---|---|---|
| Font | IBM Plex Sans, IBM Plex Mono, Newsreader for public headings | **Geist** for everything (400, 500, 600) with tabular figures; **Geist Mono** for slips and stay codes only. Public headlines are Geist 600 with tight tracking (−0.035 to −0.04em). No serif anywhere. ([ASSETS §1.1](ASSETS.md#11-fonts)) |
| Neutrals | Warm greys (`#f5f4f1` page, `#e6e3dd` lines) | Cool greys that sit with the blue: page `#f7f8fa`, line `#e4e7ec`, strong line `#d0d5dd`, row line `#eef0f3`, ink `#0f1728`, text `#344054`, muted `#667085`, faint `#98a2b3`, hover `#f2f4f7` (section 2.1) |
| Cards | Flat, no shadow, 10 px radius | White, 1 px `line`, **12 px radius**, hairline shadow `0 1px 2px rgba(16,24,40,.05)` (`--hm-shadow-xs`). Still no gradients, no glass. |
| Sidebar | 156–248 px, warm fill, short labels | `SidebarNav` 232 px, white. Logo + collapse button; **branch switcher card** (initials tile, branch name, "46 rooms · GenSan", up-down chevron; owner sees "All branches"); uppercase group labels (11 px, `faint`); links 13 px/500 with 17 px icons; the active link has a `hover` fill and a 1 px `line` border; a parent with children (Reports) folds open with an indented guide line; at the bottom Notifications (red count) and Help, then the **user card** (avatar, name, role, menu). |
| Top bar | Page title on the left | `TopBar` 56 px: **breadcrumbs** (home icon / group / parent / page, the last one in `primary`), search box with "Ctrl K", the shift label and Close shift (front desk), a bell button. |
| Page header | None (title in the top bar) | `PageHeader` under the top bar: a 44 px **ink icon tile** with the nav icon in white, the page title (21 px/600, may carry a status label), one quiet sub-line (period, refresh, or branch), and the page's actions on the right. Every desktop page has one. |
| Lists | Table straight in a card | Dribbble list pattern: `FilterBar` (segmented List/Calendar where it applies, dropdown chips, a date-range chip, search on the right) above a flush table card; header row on `#f9fafb`; row menu "···"; `Pager` with Previous / numbers / Next. |
| People | Plain names | `PersonPill`: a 22 px initials avatar in one of seven soft colours (picked from the name, stable) inside a white pill with a 1 px border. Used for guests, front desks and staff in every table, and stacked avatars where several people share a row. |
| Status label | Square soft label, no border | **Pill** (radius 99, 22 px high, 11.5 px/500) with a 1 px border in a lighter tint of the same hue (`--hm-*-line` tokens). Sentence case, no dot. |
| Dashboards (60, 61) | Four equal KPIs, small charts | Left column: three `StatCard`s (value, trend arrow, comparison line with the change on the right), a stacked-bar chart with legend and filter chips, then a two-card row (sales overview with channel bars; recent check-ins with people). Right column (384 px): **ShiftCalendar** on 61 (days across, AM/PM down, a block per front desk, green dot for on duty now, dashed block for a shift not opened yet) and Needs attention. Owner (60): sales by branch, branches table with people, attention and how guests paid. |
| Today (62) | Lists only | **StaysTimeline**: rooms down, hours across (8 hours from 10 AM in the mockup; the app shows now −2 h to now +6 h), a blue now-line with the time, each stay a block from check-in to expected check-out coloured by state (occupied blue, due out amber, overdue red, to clean clay, cleaning violet, kiosk hold dashed blue), vacant rows offer "Check in". Leaving soon and Arriving on the right. Still counts and names only, never money. |
| Shift reports | — | New list screen **37a** (`/reports/shifts`): the list pattern above plus a Calendar tab that shows the same shifts as `ShiftCalendar` blocks. |
| StepBar | Separate pills | One bordered strip; the current step has a `primary-50` fill, `primary-ink` text and a 2 px `primary` underline; done steps show `ok` text. |
| Phone header (32–35) | Solid blue or violet bar | White header with a 1 px line; "Cleaning" adds a 3 px violet top edge. No thick coloured left borders anywhere (craft floor). |
| Sign-in (1) | Photo left, serif greeting | Framed photo panel (16 px radius, 12 px inset) that cross-fades three photos with a slow drift; top: white logo and a live "AM shift open since 7:58 AM" pill with a soft pulse; bottom: "Good morning." (Geist 46/600) and a white figures card. Right: logo, "Sign in to Homi", fields with icons (44 px), a 46 px blue button, help line, and a footer with the connection state. Load sequence: greeting, card and each field rise 14 px and fade in, 80 ms apart, once. |
| Welcome (59) | Photo hero, serif, numbered steps | Hero 520 px: cross-fading photos with drift and a progress bar per photo, location pill, headline revealed **word by word** (blur and rise, 60 ms apart), sub-line and two buttons rise in. A white **facts strip** overlaps the hero bottom (open 24 h, lowest price, how to pay, key deposit). Room cards (photo 190 px, three amenity tags, price "₱350 / 6 hours" and overnight) lift 4 px and zoom the photo on hover. "How a stay works": three icon steps on a line that **draws itself** once. "Find us": lobby photo beside address, hours and payment. No eyebrow labels above headings, no 01/02/03 numbers. |
| Motion | Fade-up only | Public pages: the sequences above, easing `cubic-bezier(.16,1,.3,1)`, all of it off under `prefers-reduced-motion`. In the app: 150–250 ms hover, focus and sheet transitions only; the live pulse dot is the one looping element. |

The components named above were built in Phase 11 and are described in section 5.2 ("Mockup v6 components"); all of them are in the gallery at `/dev/components#v6`.

## 1. Principles

1. **Calm and quiet.** The page is cool grey `#f7f8fa`; everything that holds content is a white card with a 1 px `line` border, a 12 px radius and only a hairline shadow. Borders do the work, not shadows. There are **no gradients** anywhere in the product (the skeleton shimmer is the one exception) and no glass or blur effects. Colour appears only where it means something: the one primary button, links, focus, a selected choice, and a status. Never a table straight on the grey page, never a card inside a card.
2. **One main action per screen.** Each screen has exactly one solid primary button ("Check in and print slip", "Save payment", "Close the day"). Every other button is white with a 1 px border, or plain text. A wizard shows its steps in a `StepBar` and one Next button.
3. **A status is a coloured dot + coloured text, never a pill (v7).** One word or a short phrase in sentence case ("Overdue", "Closed", "9 min"): a small solid dot in the tone's colour followed by the label in the same tone's text colour — no fill, no border, no rounded container. The same status has the same tone on every screen. Colour is never the only signal: the word is always there. The dot drops at the large (`lg`) size, where big tone-coloured text stands on its own.
4. **Real photographs only.** The sign-in (1), the welcome page (59) and the kiosk welcome (27) carry real photographs of rooms and a lobby — never drawn illustrations, never AI-generated images, never a CGI render. Every photo sits under a flat dark scrim so white text stays readable. Inside the app there are no photos.
5. **Every figure states its period.** A card says "Sales today", "Short / over · 7 days", "Rooms occupied now". A change figure names what it compares with ("▲ 8% vs yesterday by this time"). No previous data means no change figure.
6. **Numbers are the sans font with tabular figures.** Money, counts, percentages, room numbers, timers and KPI figures use Geist at weight 600 with `tabular-nums`, right-aligned in tables. Geist Mono is only for thermal slips, printed receipts and stay codes.
7. **Short and over are never netted.** Wherever both can occur over a period they are summed and shown separately.
8. **Show the sum, not just the total.** Bills, cash results and the Z-report show the lines that add up to each total, so staff can check the arithmetic by eye.
9. **Destructive or sensitive actions need a reason, and often a PIN.** Void, cancel, release and force close open a `ConfirmDialog` that says what happens to the room and the money, ask for a reason from the reason list (with a note or a manager when the reason requires it), and show a lock when a supervisor PIN follows.
10. **Never leave the user guessing.** Every list has an empty state with one way forward, every first load has a skeleton shaped like the content, every disabled option says why, and every error says what happened and what to do next.
11. **Motion belongs to the front door.** Photo cross-fade and drift, the word-by-word headline, the staggered rise of the sign-in form and the self-drawing steps line are used only on the sign-in, the welcome page and the kiosk welcome, and all of it stops under reduced motion. Inside the app, motion is limited to hover, focus, the sheet slide and the skeleton.

## 2. Colour tokens

Pages and components never contain a hex value ([TRD §12](TRD.md#12-coding-conventions)). Colours come from one token layer, `--hm-*`, declared in `resources/css/app.css`. Nuxt UI's own variables (`--ui-*`) point at that layer, so every Nuxt UI component picks the Homi look up without per-page classes.

### 2.1 The Homi token layer

Light values are exactly the mockup's v6 block (and the earlier blocks where v4 sets nothing). "Utility" is the Tailwind class stem a page may use; where Nuxt UI already has a semantic utility for the same value, that one is used.

**Surfaces and text**

| Token | Mockup | Light value | Utility | Use |
|---|---|---|---|---|
| `--hm-ink` | `--ink` | `#0f1728` | `text-highlighted` | Titles, numbers, bold text, the total-row rule, the active sidebar link |
| `--hm-text` | `--text` | `#344054` | `text-default` | Body text, field labels, sidebar links, guest name on the room board |
| `--hm-muted` | `--mut` | `#667085` | `text-muted` | Helper text, table headers, KPI labels, sub-lines, time column on the room board, step numbers on the welcome page |
| `--hm-line` | `--line` | `#e4e7ec` | `border-default` | Every card, stat card, room table card and room-type card border; sidebar edge; top bar; dividers |
| `--hm-line-strong` | `--line2` | `#d0d5dd` | `border-accented` | Inputs, selects, secondary buttons, option tiles and segmented options at rest |
| `--hm-row` | literal | `#eef0f3` | `border-muted` | Line between table rows and attention rows |
| `--hm-bg` | `--bg` | `#f7f8fa` | `bg-muted` | Page background behind the cards; the "How a stay works" band on 59 |
| `--hm-card` | `--card` | `#ffffff` | `bg-default` | Cards, top bar, inputs, modals, slide-overs, the room table, the sign-in panel |
| `--hm-sidebar` | literal | `#ffffff` | `bg-sidebar` | Sidebar background |
| `--hm-nav-active` | literal | `#f2f4f7` | `bg-nav-active` | Active sidebar link fill; avatar fill |
| `--hm-hover` | — | `#f2f4f7` | `bg-elevated` | Hover fill of white buttons, table rows and sidebar links; skeleton blocks |
| `--hm-blocked` | literal | `#f7f6f3` | `bg-blocked` | Spare neutral fill (Blocked rooms are shown as muted text on the board) |
| `--hm-track` | literal | `#f2f4f7` | `bg-track` | Track of the segmented period switch |
| `--hm-step` | literal | `#ffffff` | `bg-step` | `StepBar` pill of a step not reached yet |

Cards, stat cards, inputs and buttons carry `--hm-shadow-xs` · `0 1px 2px rgba(16, 24, 40, 0.05)`; nothing else has a shadow except modals, the sign-in figures card and the welcome facts strip.

**Brand and selection**

| Token | Mockup | Light value | Utility | Use |
|---|---|---|---|---|
| `--hm-brand` | `--brand` | `#009ef5` | `brand` | The logo blue. **Logo only.** Never a button fill, a background or text: white on it is 2.9 : 1. |
| `--hm-primary` | `--pri` | `#0077c2` | `primary` | The one solid button, links, focus border, current step, the active sidebar icon, the peak bar in charts, selected room tile outline |
| `--hm-primary-ink` | literal | `#005f9e` | `primary-ink` | Text on `primary-50` and on `select`: the Held label, the branch label in the top bar, the selected option's text |
| `--hm-primary-50` | `--pri50` | `#eaf4fb` | `primary-50` | Held label fill, filled PIN cell, selected kiosk type card |
| `--hm-select` | literal | `#eaf4fb` | `bg-select` | Fill of a selected option tile or segmented option |
| `--hm-select-line` | literal | `#6fb5e3` | `border-select` | Border of a selected option tile or segmented option |
| `--hm-focus` | literal | `#eaf4fb` | — | The 4 px focus halo around an input |
| `--hm-accent` | `--acc` | `#b7791f` | `accent` | Fourth hue of the shares chart |
| `--hm-accent-50` | `--acc50` | `#fbf1dc` | `accent-50` | Promo card fill on the kiosk (29) |

**Status** (one text colour and one soft fill per tone; the alert note uses the same pair)

| Tone | Text token · value | Soft fill token · value | Edge token · value |
|---|---|---|---|
| ok | `--hm-ok` · `#067647` | `--hm-ok-50` · `#ecfdf3` | `--hm-ok-line` · `#abefc6` |
| warn | `--hm-warn` · `#b54708` | `--hm-warn-50` · `#fffaeb` | `--hm-warn-line` · `#fedf89` |
| bad | `--hm-bad` · `#b42318` | `--hm-bad-50` · `#fef3f2` | `--hm-bad-line` · `#fecdca` |
| info | `--hm-info` · `#3538cd` | `--hm-info-50` · `#eef4ff` | `--hm-info-line` · `#c7d7fe` |
| primary | `--hm-primary-ink` · `#005f9e` | `--hm-primary-50` · `#eaf4fb` | `--hm-primary-line` · `#cfe6f6`; `--hm-held-line` · `#6fb5e3` (dashed outline of a held option) |
| violet | `--hm-violet` · `#5925dc` | `--hm-violet-50` · `#f4f3ff` | `--hm-violet-line` · `#d9d6fe` |
| clay | `--hm-clay` · `#93370d` | `--hm-clay-50` · `#fef6ee` | `--hm-clay-line` · `#f9dbaf` |
| neutral | `--hm-grey` · `#475467` | `--hm-grey-50` · `#f2f4f7` | `--hm-grey-line` · `#e4e7ec` |

Utilities follow the token name without the prefix: `text-ok`, `bg-ok-50`, `border-warn-line`, and so on. Phase 11 added `--hm-faint` (`#98a2b3`, `text-faint`: group labels, breadcrumbs, chart axes), `--hm-thead` (`#f9fafb`, `bg-thead`: the header row of a flush table), `--hm-group-row` (`#fcfcfd`: floor rows), `--hm-tile` / `--hm-on-tile` (the ink icon tile of the page header), `--hm-live` (`#12b76a`, the pulse dot on 1), the seven avatar pairs `--hm-av-1…7` and `--hm-av-1…7-ink`, and the chart series `--hm-bar-1` `#3b93cf`, `--hm-bar-2` `#a9d3ef`, `--hm-bar-3` (= line) and `--hm-bar-now` `#005f9e`. The same text colours fill the room-status shares bar and the chart series (section 5.2, Charts), where they are large shapes, not text.

**Supporting**

| Token | Light value | Use |
|---|---|---|
| `--hm-control-dash` | `#cfd5df` | Border of empty PIN cells |
| `--hm-disabled` / `--hm-disabled-ink` | `#d7dce4` / `#8b93a2` | Disabled solid button fill and text; disabled input text |
| `--hm-void` | `#9aa3b2` | Struck-through voided rows |
| `--hm-empty-icon` | `#b3bac6` | Empty-state icon |
| `--hm-bar-soft` | `#a9d3ef` | Chart bars that are not the peak |
| `--hm-promo-line` | `#eccf8f` | Border of the kiosk promo card |
| `--hm-slip-line` | `#b9c0cc` | Dashed border of the on-screen slip preview |
| `--hm-overlay` | `rgba(18, 24, 34, 0.5)` | Dim layer behind modals |
| `--hm-shadow-modal` | `0 14px 34px rgba(29, 35, 41, 0.18)` | Modals and dialogs — the only soft shadow on a surface |
| `--hm-radius` | `6px` | Base control radius (section 4.2) |

**Photo layer** (sign-in 1, welcome 59, kiosk welcome 27; light only, not themed)

| Token | Value | Use |
|---|---|---|
| `--hm-photo-fallback` | `#3a3f45` | Flat warm colour block behind every photo. It shows while a photo loads and whenever one fails, so white text is still readable. |
| `--hm-scrim` | `rgba(15, 23, 40, 0.42)` (sign-in panel 0.34) | Flat dark layer over the sign-in and welcome photos. Never a gradient. |
| `--hm-scrim-kiosk` | `rgba(20, 22, 25, 0.52)` | The same layer on the kiosk welcome, stronger because the text sits over the middle of the photo and is read from further away |
| `--hm-on-photo-soft` | `rgba(255, 255, 255, 0.85)` | Eyebrow lines, figure labels, the free-room line on the kiosk |
| `--hm-on-photo-body` | `rgba(255, 255, 255, 0.92)` | The one sentence under a headline |
| `--hm-on-photo-line` | `rgba(255, 255, 255, 0.40)` | Rule above the sign-in figures; outline-button border is `rgba(255,255,255,.7)` |
| `--hm-on-photo-divider` | `rgba(255, 255, 255, 0.28)` | Thin rules between the sign-in figures |
| `--hm-slide-off` | `rgba(255, 255, 255, 0.45)` | Slide indicator that is not showing; the current one is white |

### 2.2 How the tokens map onto Nuxt UI

`resources/css/app.css`, in this order:

```css
@import "tailwindcss";
@import "@nuxt/ui";

/* 1. The primary scale Nuxt UI needs (a full 50–950 scale), built around #0077c2.
      The logo blue #009ef5 is not part of the scale: it is the logo's colour only. */
@theme static {
  --color-homi-50:  #eaf4fb;   /* = --hm-primary-50 */
  --color-homi-100: #cfe6f6;   /* = --hm-primary-line */
  --color-homi-200: #a9d3ef;   /* = --hm-bar-2 */
  --color-homi-300: #6fb5e3;   /* = --hm-select-line */
  --color-homi-400: #3b93cf;   /* = --hm-bar-1 */
  --color-homi-500: #1784c8;
  --color-homi-600: #0077c2;   /* = --hm-primary */
  --color-homi-700: #005f9e;   /* = --hm-primary-ink */
  --color-homi-800: #0d4b73;
  --color-homi-900: #0f3a57;
  --color-homi-950: #0b2638;
  --font-sans:  "Geist", system-ui, sans-serif;
  --font-mono:  "Geist Mono", ui-monospace, monospace;
}

/* 2. The Homi layer (light). Dark values: section 2.4. */
:root, .hm-light { --hm-ink: #0f1728; --hm-text: #344054; /* …every token of 2.1 */ }

/* 3. Nuxt UI's variables point at the Homi layer. Declared on the same selectors
      as the layer, so a light-only subtree (.hm-light) resolves them again. */
:root, .dark, .hm-light {
  --ui-primary: var(--hm-primary);
  --ui-success: var(--hm-ok);
  --ui-warning: var(--hm-warn);
  --ui-error: var(--hm-bad);
  --ui-info: var(--hm-info);
  --ui-bg: var(--hm-card);
  --ui-bg-muted: var(--hm-bg);
  --ui-bg-elevated: var(--hm-hover);
  --ui-bg-accented: var(--hm-line);
  --ui-border: var(--hm-line);
  --ui-border-muted: var(--hm-row);
  --ui-border-accented: var(--hm-line-strong);
  --ui-text: var(--hm-text);
  --ui-text-muted: var(--hm-muted);
  --ui-text-toned: var(--hm-text);
  --ui-text-dimmed: var(--hm-disabled-ink);
  --ui-text-highlighted: var(--hm-ink);
  --ui-radius: calc(var(--hm-radius) / 1.5); /* 4px: Nuxt UI v4 makes rounded-md 1.5 × this = 6px */
}

/* 4. Homi-only utilities for what Nuxt UI has no semantic name for. */
@theme inline {
  --color-brand: var(--hm-brand);
  --color-primary-ink: var(--hm-primary-ink);
  --color-ok: var(--hm-ok);          --color-ok-50: var(--hm-ok-50);
  /* …warn, bad, info, violet, clay, grey, accent, the -line edges, sidebar, nav-active,
     blocked, select, select-line, track, step, row in the same pattern */
}
```

| Nuxt UI variable | Homi token | Light value | What it drives |
|---|---|---|---|
| `--ui-primary` | `--hm-primary` | `#0077c2` | Solid button, links, focus border, checked switch and checkbox |
| `--ui-bg` | `--hm-card` | `#ffffff` | `bg-default`: cards, inputs, modals, dropdowns |
| `--ui-bg-muted` | `--hm-bg` | `#f7f8fa` | `bg-muted`: the page behind the cards |
| `--ui-bg-elevated` | `--hm-hover` | `#f2f4f7` | `bg-elevated`: hover fills, skeleton blocks |
| `--ui-border` | `--hm-line` | `#e4e7ec` | Card borders, dividers |
| `--ui-border-accented` | `--hm-line-strong` | `#d0d5dd` | Rings of white buttons, inputs, selects |
| `--ui-border-muted` | `--hm-row` | `#eef0f3` | Table row lines |
| `--ui-text` | `--hm-text` | `#344054` | `text-default` |
| `--ui-text-muted` | `--hm-muted` | `#667085` | `text-muted` |
| `--ui-text-highlighted` | `--hm-ink` | `#0f1728` | `text-highlighted`: titles and numbers |
| `--ui-success` / `--ui-warning` / `--ui-error` / `--ui-info` | `--hm-ok` / `--hm-warn` / `--hm-bad` / `--hm-info` | `#067647` / `#b54708` / `#b42318` / `#3538cd` | Toast and alert colours, the red confirm button |
| `--ui-radius` | `--hm-radius` ÷ 1.5 | `4px` | Nuxt UI v4 derives `rounded-sm/md/lg/xl` as 1×/1.5×/2×/3× this. Buttons, inputs and selects add `rounded-control` (8 px, v6) in `nuxt-ui.config.ts`; cards are `rounded-card` (12 px); modals `rounded-xl` (12 px) |

`vite.config.ts` (the Nuxt UI Vite plugin; full list of overrides in section 5.1):

```ts
ui({
  router: 'inertia',                       // ULink and UButton `to` use Inertia visits
  ui: {
    colors: { primary: 'homi', neutral: 'neutral' },
    icons: { loading: 'i-lucide-loader-circle' },
    button: { /* one solid primary, others white with a 1 px border — section 5.1 */ },
    modal: { /* 12 px radius, the one soft shadow — section 5.1 */ },
  },
})
```

Rules for pages: use the semantic utilities (`bg-default`, `bg-muted`, `text-muted`, `text-highlighted`, `border-default`, `text-primary`, `bg-primary-50`, `text-ok`, `bg-ok-50`); never a palette class such as `bg-blue-600` or `text-neutral-500`; never a `dark:` variant; never `shadow-*` on a card; never `bg-gradient-*`, `backdrop-blur-*` or `backdrop-filter`. Dark mode is the token layer and nothing else.

### 2.3 Status tones and the room-status mapping

One mapping for the whole app. `StatusLabel` takes the tone; the word comes from the server with the enum value.

| Tone | Text on fill | Used for (examples from the mockup) |
|---|---|---|
| `neutral` | `grey` on `grey-50` | Closed, Blocked, Deactivated, role names, expired hold, "No open shift" in the front desk top bar, "12 m waiting" |
| `ok` | `ok` on `ok-50` | Paid, Vacant, Active, OK, Confirmed, Open, open shift ("AM shift · Wed 11 Nov", "AM · 7:58 AM"), Closed (day close list 38b) |
| `warn` | `warn` on `warn-50` | Unpaid, Due out, "9 min", Low, "1 to fix", hold time left ("6:42 left"), "No open shift" on the owner dashboard (60), shift closing or late, EXT, Void (43) |
| `bad` | `bad` on `bad-50` | Overdue, "42 min over", Out of stock, Late, Full, No-show, Cancel, Urgent, FWD |
| `info` | `info` on `info-50` | Occupied, Checked in, Arriving, Note, Remittance, Transfer |
| `primary` | `primary-ink` on `primary-50` | Held, Kiosk hold, the branch name in the top bar, the room offered on the kiosk |
| `violet` | `violet` on `violet-50` | Cleaning |
| `clay` | `clay` on `clay-50` | To clean |

**Room board rows.** The board is a table with one row per room; its status column shows one of eight labels. Due out, Overdue and Held are not values of `rooms.status`; the board derives them from the room's stay ([DATABASE_SCHEMA §4.4](DATABASE_SCHEMA.md#44-floors-room_types-rooms-room_status_logs)). `RoomRow` receives the display status key from the server (`BoardQuery`); the page never works it out.

| Label | Key | From `rooms.status` + stay | Guest · Due out | Time column | Label tone | Row action |
|---|---|---|---|---|---|---|
| Vacant | `vacant` | `available`, no active stay | "—" · "—" | Blank | `ok` | "Check in" link → check-in (6) |
| Occupied | `occupied` | `occupied`, `checked_in` stay with more than `due_soon_minutes` (30) left | Guest · clock time | "3h 10m left", `muted` | `info` | Row opens the folio (9) |
| Due out | `due_out` | `occupied`, `due_soon_minutes` or less left | Guest · clock time | "22m left" in `warn`, 600 | `warn` | Folio (9) |
| Overdue | `overdue` | `occupied`, past `expected_checkout_at` | Guest · clock time | "42m over" in `bad`, 600 | `bad` | Folio (9) |
| Held | `held` | `available` with a `held` stay (kiosk hold) | "—" · "—" | "hold 6:42", `muted` | `primary` | Confirm (7) |
| To clean | `to_clean` | `uncleaned` | "—" · "—" | "waiting 48m", `muted` | `clay` | None |
| Cleaning | `cleaning` | `cleaning` | "—" · "—" | "cleaning 11m", `muted` | `violet` | None |
| Blocked | `blocked` | `maintenance` | "—" · "—" | Blank; the whole row in `muted` | `neutral` | None |

| Modifier | From | Look |
|---|---|---|
| Reserved today | A `reserved` stay arriving today on a Vacant room | The guest column reads "Reserved · 2:00 PM" in `muted`; the label stays Vacant. The room is kept off the kiosk. |
| Selected (transfer 13) | The picked room in the transfer picker, a small room table with a radio column | Row fill `#f2f7fb`, radio filled with `primary` |

Rows are **never tinted** by status; only the label has colour.

The board header counts use the same words and tones, in this order: Vacant `ok`, Occupied `info`, Due out `warn`, Overdue `bad`, To clean `clay`, Cleaning `violet`, Held `primary`, Blocked `neutral` ("Vacant 14", "Occupied 23", …). The "Rooms now" shares bar (61, 62) uses six of them in this order: Vacant, Occupied, To clean, Cleaning, Held, Blocked (Due out and Overdue are counted inside Occupied there).

### 2.4 Dark mode

| Topic | Rule |
|---|---|
| Switch | The user menu in the top bar has "Appearance": **Light · Dark · System**. It is Nuxt UI's colour mode (`useColorMode()` from `@vueuse/core`), which sets the `dark` class on `<html>` and remembers the choice in `localStorage`. Default: Light. |
| How it works | `.dark` re-declares the `--hm-*` tokens below. The `--ui-*` mapping and every component follow. No page has a `dark:` class. |
| Light-only surfaces | Sign-in (1), welcome (59), every kiosk screen (27–31b), and everything printed or exported (slips, A4, PDF). Their root element carries the class `hm-light`, which re-declares the light values, so they look the same whatever the user chose. The photo-layer tokens have no dark value. `PhoneLayout` follows the user's choice. |
| Character | The v6 cool neutrals turned down: near-black blue-grey page, slightly lighter cards, thin cool borders, the hairline shadow darkened. Status meaning is unchanged: the dot and text keep the tone's colour on the darker ground. |

| Token | Light | Dark |
|---|---|---|
| `--hm-ink` | `#0f1728` | `#f5f6f8` |
| `--hm-text` | `#344054` | `#cdd1d8` |
| `--hm-muted` | `#667085` | `#959cab` |
| `--hm-faint` | `#98a2b3` | `#8a92a4` |
| `--hm-line` | `#e4e7ec` | `#262c38` |
| `--hm-line-strong` | `#d0d5dd` | `#363e4d` |
| `--hm-row` | `#eef0f3` | `#1f2430` |
| `--hm-bg` | `#f7f8fa` | `#0c111b` |
| `--hm-card` / `--hm-sidebar` | `#ffffff` | `#141924` |
| `--hm-nav-active` / `--hm-hover` | `#f2f4f7` | `#1e2431` / `#1b212d` |
| `--hm-thead` / `--hm-group-row` | `#f9fafb` / `#fcfcfd` | `#171c27` / `#161b26` |
| `--hm-tile` / `--hm-on-tile` | `#0f1728` / `#ffffff` | `#f5f6f8` / `#0c111b` |
| `--hm-brand` | `#009ef5` | `#009ef5` (logo only)` |
| `--hm-primary` | `#0077c2` | `#5aa9e0` (solid button text becomes `#0c111b`)` |
| `--hm-primary-ink` | `#005f9e` | `#8cc4ec` |
| `--hm-primary-50` / `-line` | `#eaf4fb` / `#cfe6f6` | `#10263a` / `#1d4566` |
| `--hm-select` / `--hm-select-line` | `#eaf4fb` / `#6fb5e3` | `#10263a` / `#2f6390` |
| `--hm-focus` | `#eaf4fb` | `#143049` |
| `--hm-ok` / `-50` / `-line` | `#067647` / `#ecfdf3` / `#abefc6` | `#6ad39b` / `#0f2a1e` / `#1c4a34` |
| `--hm-warn` / `-50` / `-line` | `#b54708` / `#fffaeb` / `#fedf89` | `#f5b54a` / `#2c2110` / `#574117` |
| `--hm-bad` / `-50` / `-line` | `#b42318` / `#fef3f2` / `#fecdca` | `#f4837a` / `#321719` / `#5a2a2b` |
| `--hm-info` / `-50` / `-line` | `#3538cd` / `#eef4ff` / `#c7d7fe` | `#a4b9fc` / `#181f40` / `#2d3a75` |
| `--hm-violet` / `-50` / `-line` | `#5925dc` / `#f4f3ff` / `#d9d6fe` | `#bcb2fd` / `#211c3c` / `#3d3370` |
| `--hm-clay` / `-50` / `-line` | `#93370d` / `#fef6ee` / `#f9dbaf` | `#f2ac78` / `#2c1c12` / `#573821` |
| `--hm-grey` / `-50` / `-line` | `#475467` / `#f2f4f7` / `#e4e7ec` | `#aeb4c0` / `#1e2431` / `#2c3340` |
| `--hm-av-1…7` (fill / ink)` | `soft tints, e.g. `#dbeafe` / `#1e40af` | `deep tints, e.g. `#1c2c4f` / `#a8c4fb` |
| `--hm-bar-1` / `-2` / `-3` / `-now` | `#3b93cf` / `#a9d3ef` / `#e4e7ec` / `#005f9e` | `#3f8fc8` / `#24496a` / `#2c3340` / `#8cc4ec` |
| `--hm-disabled` / `-ink` | `#e4e7ec` / `#98a2b3` | `#222834` / `#8a92a4` |
| `--hm-overlay` | `rgba(15,23,40,.5)` | `rgba(0,0,0,.6)` |
| `--hm-shadow-xs` | `0 1px 2px rgba(16,24,40,.05)` | `0 1px 2px rgba(0,0,0,.3)` |
| `--hm-shadow-modal` | `0 14px 34px rgba(15,23,40,.18)` | `0 14px 34px rgba(0,0,0,.5)` |

The dark values were derived from the v6 light layer in Phase 11: a cool near-black page and slightly lighter cards (the blue-grey of the v6 neutrals, not the old warm greys), thin cool borders, and each status hue lifted so its text reads on its tinted fill (every pair is above 4.5 : 1). `resources/css/app.css` holds the full list.

In dark mode the solid buttons whose fill becomes light — primary, the red confirm button and the green "Room is ready" button — use dark text `#0c111b` (`--hm-on-primary`).

## 3. Typography

| Family | Token | Stack | Weights shipped | Use |
|---|---|---|---|---|
| Geist | `font-sans` | `"Geist", system-ui, sans-serif` | 400, 500, 600 | All interface text, the public headlines, and **all numbers** (money, counts, percentages, room numbers, timers, countdowns, PIN digits, Z numbers) with `font-variant-numeric: tabular-nums`; the body text, buttons and figures of the public pages |
| Geist Mono | `font-mono` | `"Geist Mono", ui-monospace, monospace` | 500, 600 | The whole of every 80 mm slip and its on-screen preview, printed receipts, and stay codes (`DGS-26-004126`) wherever they are shown. Nothing else. |

Self-hosted woff2; files and licences in [ASSETS §1.1](ASSETS.md#11-fonts). Bold in running text (`<b>`, `<strong>`) is weight 600, in `ink`. There is no weight 700 or 800 anywhere; titles are 600. There is no serif face.

The helper class `.num` (`font-sans`, 600, `tabular-nums`, letter-spacing −0.01em, `ink`) is applied by `MoneyRow`, `StatCard`, `RoomRow` and `.dtable td.r`; pages do not restyle numbers by hand.

Mockup v6 draws each desktop screen in a 1200 px frame at app size (body 13 px); the table below still lists the v4 frame sizes in its second column, and section 0 gives the v6 sizes for the shell, page header, stat cards and status pills. The real app targets 1280 px and wider; the sizes below are the ones to build.

### 3.1 Desktop scale (front desk, back office, admin, owner)

| Style | Mockup frame | App size / line height | Weight | Family · colour | Notes |
|---|---|---|---|---|---|
| Base (body) | 12.5 px | 14 px / 20 px | 400 | sans · `text` | Set on `body` |
| Page title (`h1` in the top bar) | 14 px | 19 px / 24 px | 600 | sans · `ink` | Letter-spacing −0.01em. One per page, no wrap. |
| In-page title (wizards, forms: "Close AM shift · Wed 11 Nov") | 15.5 px | 18 px / 26 px | 600 | sans · `ink` | Letter-spacing −0.005em |
| Card title | 12.5 px | 15 px / 22 px | 600 | sans · `ink` | "Sales · last 7 days", "Needs attention" |
| Section label inside a card | 11.5 px | 13 px / 18 px | 600 | sans · `ink` | Sentence case, not uppercase |
| Sidebar group heading | 9.5 px | 11 px / 16 px | 600 | sans · `muted` | Uppercase, letter-spacing 0.07em |
| Sidebar link | 11.5 px | 14 px / 20 px | 400 (active 500) | sans · `text` (active `ink`) | |
| Small / helper | 11 px | 12 px / 16 px | 400 | sans · `muted` | |
| Field label | 10.5 px | 13 px / 18 px | 500 | sans · `text` | |
| Field error | 10.5 px | 12 px / 16 px | 600 | sans · `bad` | |
| Input text | 12 px | 14 px / 20 px | 400 (600 in `MoneyInput`) | sans | |
| Button | 11.5 px | 14 px / 20 px | 500 | sans | |
| Button, main action of a form | 13.5 px | 16 px / 24 px | 500 | sans | |
| Status label | 10.5 px | 12 px / 18 px | 500 | sans · the tone's text colour on its fill | Padding 1 × 7 px (app 2 × 8 px), radius 4 px, sentence case |
| Table header | 11 px | 13 px / 18 px | 500 | sans · `muted` | Not uppercase, no letter-spacing, no wrap |
| Table cell | 11.5 px | 14 px / 20 px | 400 (numbers 600) | sans | |
| Table footer (totals) | 11.5 px | 14 px / 20 px | 600 | sans · `ink` | 1.5 px `ink` rule above |
| Money row | 12 px | 14 px / 20 px | 400 label, 600 amount | sans | |
| Total row | 14 px | 16 px / 24 px | 600 label, 600 amount | sans · `ink` | 1.5 px `ink` rule above |
| KPI label | 11 px | 13 px / 18 px | 400 | sans · `muted` | Sentence case |
| KPI number | 19 px | 26 px / 32 px | 600 | sans · `ink` (`bad` for a real problem) | Letter-spacing −0.01em, tabular |
| KPI sub-line | 10.5 px | 12 px / 16 px | 400 | sans · `muted` (`ok` up, `bad` down) | |
| Big number (`.big`) | 20 px | 24 px / 32 px | 600 | sans · `ink` | |
| Segmented switch | 11 px | 13 px / 18 px | 500 (active 600) | sans · `muted` (active `ink`) | |
| Room board room number | 12.5 px | 14 px / 20 px | 600 | sans · `ink` | Tabular |
| Room board cells | 11 px | 13 px / 20 px | 400 | sans · `text` | One line, ellipsis |
| Room board time | 11 px | 13 px / 20 px | 400 (600 when Due out or Overdue) | sans · `muted` (`warn` / `bad`) | Tabular, no wrap |
| PIN cell digit | 18 px | 22 px | 600 | sans, tabular | |
| PIN pad key | 14 px | 18 px | 600 | sans, tabular | |
| Z paper body | 11.5 px | 13 px / 20 px | 400 label, 500 amount, 600 on an "equals" line | sans | |
| Z paper section heading | 10.5 px | 11 px / 16 px | 600 | sans · `muted` | Uppercase, letter-spacing 0.06em — the only uppercase headings besides the sidebar groups and the eyebrow lines on the public pages |
| Z number on the paper | 17 px | 19 px / 24 px | 600 | sans · `ink` | `Z-0629`, letter-spacing −0.02em |
| On-screen slip preview | 10.5 px / 1.5 | 12 px / 18 px | 500, 600 for totals | mono | Print size in section 6.10 |

### 3.2 Public scale (sign-in 1, welcome 59)

v6: every heading here is Geist 600 with tight tracking; there are no eyebrow labels above headings and no 01/02/03 numbers (section 0). Rows below that mention them are superseded.

| Style | Mockup frame | App size / line height | Weight | Family · colour | Notes |
|---|---|---|---|---|---|
| Eyebrow line ("Hotel Dalisay GenSan · Wednesday, 11 November", "General Santos City · Open 24 hours", "Rooms") | 10 px | 12 px / 16 px | 500 | sans · `on-photo-soft` (on a photo) or `muted` (on white) | Uppercase, letter-spacing 0.12em |
| Sign-in greeting ("Good morning.") | 42 px / 1.0 | 64 px / 64 px | 600 | sans, tracking −0.035em · white | Letter-spacing −0.01em |
| Sentence under the greeting | 12.5 px | 16 px / 24 px | 400 | sans · `on-photo-body` | One sentence, max width 480 px |
| Sign-in figure | 20 px / 1.1 | 28 px / 32 px | 500 | sans · white | Tabular |
| Sign-in figure label | 10.5 px | 13 px / 18 px | 400 | sans · `on-photo-soft` | "rooms free", "in house", "due out soon", "arriving today" |
| Panel heading "Sign in" | 26 px / 1.1 | 34 px / 38 px | 600 | sans, tracking −0.035em · `ink` | |
| Panel line ("Use your own account. Never share it.") | 12.5 px | 14 px / 20 px | 400 | sans · `muted` | |
| Panel fields and button | 12.5 px, 36 px high | 15 px, 44 px high | 400 / 500 | sans | `UInput size="xl"`, `UButton size="xl" block` |
| "Forgot your password? Ask your branch admin." | 11 px | 13 px / 18 px | 400 | sans · `muted` | Bottom of the panel |
| Welcome nav links | 12 px | 15 px / 20 px | 400 | sans · white at 90% | |
| Welcome headline | 40 px / 1.02 | 60 px / 62 px | 600 | sans, tracking −0.035em · white | Letter-spacing −0.01em, max width 640 px |
| Welcome sub-line | 13 px | 17 px / 26 px | 400 | sans · `on-photo-body` | Max width 520 px |
| Section heading ("Three ways to stay") | 24 px | 32 px / 38 px | 600 | sans, tracking −0.035em · `ink` | Under its eyebrow |
| Room-type card name | 13 px | 17 px / 24 px | 600 | sans · `ink` | |
| Room-type card line | 11 px | 14 px / 20 px | 400 | sans · `muted` | |
| Room-type price | 17 px | 22 px / 28 px | 500 | sans · `ink` | Tabular; "6 hours" after it and "Overnight ₱750" on the right in 13 px `muted` |
| Step number ("01") | 22 px | 28 px / 32 px | 600 | sans, tracking −0.035em · `muted` | |
| Step title / text | 13 px / 11.5 px | 17 px / 24 px · 15 px / 22 px | 600 / 400 | sans · `ink` / `muted` | |
| Footer | 11 px | 13 px / 18 px | 400 | sans · `muted` | |

### 3.3 Kiosk scale (1080 × 1920 portrait)

The kiosk frame is 340 px wide for a 1080 px screen. Text is sized for reading at arm's length, not scaled 3×.

| Style | Mockup frame | App size / line height | Weight | Family |
|---|---|---|---|---|
| Welcome title ("Welcome to Hotel Dalisay") | 30 px / 1.05 | 80 px / 84 px | 400 | **serif**, white |
| Welcome sentence | 13 px | 26 px / 36 px | 400 | sans, `on-photo-body` |
| House rules panel: title / rules | 11.5 px | 22 px / 30 px · 20 px / 30 px | 600 / 400 | sans, `ink` / `text` on white |
| "Tap to start" | 15 px | 28 px | 500 | sans, `ink` on white |
| Free-room line | 11 px | 20 px / 28 px | 400 | sans, `on-photo-soft` |
| Screen title ("Choose a room type") | 16–18 px | 40 px / 48 px | 600 | sans |
| Body | 12.5 px | 18 px / 28 px on forms and rules; 24 px / 34 px for the one-sentence instruction under a title | 400 | sans |
| Card title (room type) | 14 px | 28 px / 36 px | 600 | sans |
| Option tile label / price | 11.5 px / 11 px | 24 px / 22 px | 500 / 500 | sans |
| Input text | 14 px | 28 px | 400 | sans |
| Button | 13.5–16 px | 24 px | 500 | sans |
| Stay code on the hold screen | 20 px | 48 px | 600 | mono |
| Countdown | 14 px | 28 px | 600 | sans, tabular |
| Small print | 11 px | 18 px / 26 px | 400 | sans |

### 3.4 Phone scale (housekeeping, 360 px and wider)

| Style | Mockup frame | App size / line height | Weight |
|---|---|---|---|
| Header title | 15 px | 18 px / 24 px | 600 |
| Header subtitle | 11 px | 13 px / 18 px | 400 |
| Body | 12.5 px | 16 px / 24 px | 400 |
| Room number on a task card | 16 px | 20 px | 600 sans, tabular |
| Small / helper | 11 px | 13 px / 18 px | 400 |
| Status label | 10.5 px | 13 px | 500 |
| Timer (33) | 44 px | 56 px | 600 sans, tabular |
| Button | 11.5 px | 16 px | 500 |

All phone text is Geist. The desktop app shell also works on a phone-width screen (section 6.1); it keeps the desktop scale with 16 px side padding.

## 4. Spacing, radius, elevation, motion

### 4.1 Spacing

A 4 px grid on Tailwind's default spacing scale (`1` = 4 px).

| Step | px | Use |
|---|---|---|
| `1` | 4 | Label to input; gap inside a status label |
| `2` | 8 | Gap between buttons in a row, between status labels |
| `3` | 12 | Gap between form fields; horizontal padding inside inputs |
| `4` | 16 | Gap between cards in a grid; KPI card padding (16 × 18); page side padding under 768 px |
| `5` | 20 | Card padding; modal padding; Z paper padding is 24 × 28 |
| `6` | 24 | Page padding on desktop; gap between page sections |
| `8` | 32 | Kiosk page padding |
| `12` | 48 | Padding of an empty state (top and bottom); sign-in panel padding |

| Fixed size | Value |
|---|---|
| Sidebar | 248 px; collapsed rail 64 px; drawer under 768 px is `min(280px, 85vw)` |
| Top bar height | 56 px (mockup 48 px) |
| Dashboard aside | 340 px, sticky 16 px under the top bar |
| KPI row | 4 equal columns, 16 px gap (5 on Today 62); 2 columns under 768 px |
| Room board | One table in one card, full content width; floor group rows; columns as in section 5 (RoomTable and RoomRow) |
| Table cell padding | Header 10 × 14 px, cell 11 × 14 px; `dense` 6 × 10 px |
| Modal width | 440 px (PIN dialogs 360 px) |
| Slide-over width | 420 px |
| Sign-in (1) | Photo `minmax(0, 1.3fr)` (about 57%) · panel `minmax(0, 1fr)`; the form inside the panel is at most 360 px wide |
| Welcome hero (59) | `min(100svh, 760px)` high, at least 560 px |
| Room-type card photo (59) | 4 : 3 on desktop (mockup 96 px high in the small frame) |
| Content max width | None on the board, dashboards and tables; 1100 px on forms and the welcome sections; 880 px for the Z paper |

**Rhythm rules** (checked on every screen of mockup v4 after the spacing pass of 11 Nov):

| Rule | Value |
|---|---|
| Line height | 1.5 for all app text; 1.3 for page titles; 1.1 for KPI figures and serif headings |
| Page padding | 24 px desktop (mockup 20 × 22 px in the small frame) |
| Title to content | 16 px under the page title; 20 px between page sections; 8 px from a section heading to its content |
| Cards | 16–20 px inside; 16 px between cards; 12 px between KPI cards |
| Forms | 14 px between fields; 5 px from label to input; inputs 36 px (mockup 34), main buttons 44 px (mockup 42) |
| Tables | 10–11 px vertical cell padding, never under 8 px; no cell text wraps except names and descriptions, which wrap to two lines at most |
| One idea per row | A row or card never holds more than one primary number; secondary facts go on a muted second line, not a new column |
| Density limit | No more than 4 KPI cards per row on desktop (5 only on Today 62, with one-word labels), no more than 5 columns in a table that sits in half the page, and no option group that wraps to a second line in a side sheet |
| Overflow check | Every screen is tested at 1280 px wide: nothing may run past its card or the window, and no button text may wrap |

### 4.2 Radius

| Token | Value | Use |
|---|---|---|
| `rounded-[3px]` | 3 px | Checkboxes |
| `rounded-[4px]` (class `rounded-label`) | 4 px | `StatusLabel` |
| `rounded-md` (`--ui-radius`) | 6 px | Buttons, inputs, selects, option tiles, sidebar links, segmented switch segments |
| `rounded-[7px]` | 7 px | The large main-action button (`xl`) |
| `rounded-lg` | 8 px | The segmented switch track; PIN cells and keys |
| `rounded-[10px]` (class `rounded-card`) | 10 px | Cards, stat cards, the Z paper, inline alerts, room-type cards, the kiosk house-rules panel |
| `rounded-xl` | 12 px | Modals and dialogs |
| `rounded-full` | 999 px | Step pills, avatar, the live dot, attention marks, progress and chart tracks |

The sign-in panel and the photo halves have no radius: they fill the screen.

### 4.3 Elevation

| Level | Value | Use |
|---|---|---|
| Card | **No shadow**; 1 px `line` border | Cards, stat cards, the room table card, the Z paper, room-type cards, the sidebar edge, the top bar |
| Secondary button | 1 px inset `line-strong` ring, no shadow | White (secondary) buttons |
| Segment | `0 1px 2px rgba(0,0,0,.08)` | The active segment of `PeriodSegments` (a hairline lift inside the track, not a card shadow) |
| Focus | Inputs: `primary` border + 3 px `focus` halo (error: `bad` border + 3 px `bad-50` halo). Buttons, links, table rows: 2 px `primary` ring, 2 px offset. | Focused control |
| Slide-over | 1 px `line` left border + `-14px 0 40px rgba(0,0,0,.2)` | `USlideover` (a sheet that floats over the page) |
| Modal | `--hm-shadow-modal` `0 14px 34px rgba(29,35,41,.18)` over `overlay` | `UModal`, `ConfirmDialog`, PIN dialogs |

No surface uses a gradient, `backdrop-filter` or blur. The sidebar and top bar are flat with a 1 px `line` edge. Z-order, low to high: page · sidebar and top bar · `OverdueBar` · slide-over · overlay and modal · toasts · `ConnectionBar`.

### 4.4 Motion inside the app

| What | Duration | Easing | Detail |
|---|---|---|---|
| Hover and press on buttons, links, rows | 120 ms | `ease-out` | Background and border colour only; no movement |
| Focus ring | 120 ms | `ease-out` | Fades in |
| Modal open / close | 160 ms / 120 ms | `cubic-bezier(0.2, 0, 0, 1)` | Overlay fades; modal fades and scales from 0.97 |
| Slide-over open / close | 200 ms / 160 ms | `cubic-bezier(0.2, 0, 0, 1)` | Slides in from the right |
| Sidebar collapse to rail, drawer open | 160 ms | `ease-out` | Width (rail) or transform (drawer) |
| Toast | 160 ms in, 120 ms out; stays 4 s (errors 8 s) | `ease-out` | Bottom right on desktop, top on a phone |
| `ConnectionBar`, `OverdueBar` | 160 ms | `ease-out` | Slide down; page content moves down, nothing is covered |
| Skeleton | Nuxt UI pulse, 2 s | — | Shapes match the content that will appear. The mockup draws a light shimmer; that shimmer is the only gradient allowed in the product. |
| Deferred card arriving | 120 ms | `ease-out` | Skeleton cross-fades to content; the card does not change size |
| Chart bars | 0 ms on a 30 s refresh; 200 ms height on first paint | `ease-out` | Bars never bounce or re-sort on refresh |
| Button spinner | 0.7 s per turn, linear | — | `i-lucide-loader-circle`, 16 px |
| Board and dashboard refresh | 0 ms | — | Values change in place; no fade, no flash, no reorder, scroll kept |
| Countdowns and timers | Tick once per second | — | Tabular figures keep the width fixed |

Nothing in the app moves on its own except skeletons, spinners and timers. The live dot is a static `ok` dot; it does not blink. No photo drift, no fade-up and no cross-fade inside the app.

### 4.5 Motion on the public pages, sign-in and kiosk welcome

Used on sign-in (1), welcome (59) and kiosk welcome (27) only. CSS only, no animation library ([TRD](TRD.md), front-end rules for photographs and motion); only `transform` and `opacity` are animated.

| What | Where | Spec |
|---|---|---|
| Photo drift | Every photo layer on 1, 27 and 59 (each hero slide too) | Keyframes `hm-drift`: from `scale(1.03)` to `scale(1.13) translate(-1.5%, -1%)`; 24 s, `ease-in-out`, infinite, `alternate`. The photo layer is `overflow: hidden` inside its half or hero, so the edges never show. |
| Hero cross-fade | Welcome hero (59): three photos stacked | Keyframes `hm-xfade` on a 21 s infinite cycle: opacity 0 at 0%, 1 from 5% to 33%, 0 from 38% to 100%. Photo 2 starts 7 s later, photo 3 14 s later, so each photo shows for 7 s and fades over about 1 s. The drift runs at the same time. The slide indicators follow the photo that is showing. |
| Fade-up on load | Text and cards on 1, 27 and 59 | Keyframes `hm-up`: from `opacity: 0; translateY(12px)` to `opacity: 1; transform: none`; 0.8 s, `cubic-bezier(.2, .7, .2, 1)`, `both`, once. Staggered with delays 0, 0.12 s, 0.26 s, 0.40 s, 0.54 s in reading order (eyebrow, headline, sentence, figures or buttons, then cards). On the kiosk it runs again each time the welcome screen returns after idle. |
| Pause | Welcome hero | The cross-fade and drift pause while the pointer is over the hero or keyboard focus is inside it (`animation-play-state: paused`). |
| Reduced motion | All three pages | Under `prefers-reduced-motion: reduce` every one of these animations is `none`: the first photo is shown still, the other slides stay hidden, text appears at once. |

```css
@keyframes hm-drift { from { transform: scale(1.03) } to { transform: scale(1.13) translate(-1.5%, -1%) } }
@keyframes hm-up    { from { opacity: 0; transform: translateY(12px) } to { opacity: 1; transform: none } }
@keyframes hm-xfade { 0% { opacity: 0 } 5%, 33% { opacity: 1 } 38%, 100% { opacity: 0 } }
@media (prefers-reduced-motion: reduce) {
  .hm-drift, .hm-up, .hm-slides > * { animation: none !important }
  .hm-slides > :first-child { opacity: 1 }
}
```

## 5. Components

Nuxt UI first ([TRD §12](TRD.md#12-coding-conventions)). A component of our own exists only where Nuxt UI has nothing (the room table, a money field, a PIN pad, a stat card, a chart, a slip, the photo hero) or where a rule must be enforced in one place (the soft status label, the one-table style, the confirm wording). Every component and every state below appears in the gallery at `/dev/components` (local only). The old hand-built `AppButton`, `AppInput`, `AppAlert`, `Modal`, `SideSheet` and `Skeleton` are replaced by the Nuxt UI components in 5.1; `StatusDot` (version 1.1) and `StatusChip` (version 1.0) are replaced by `StatusLabel`; the version 1.1 sign-in and welcome background components are replaced by `PhotoHero`, `SignInPanel`, `RoomTypeCard` and `KioskWelcome`.

### 5.1 Nuxt UI components we use

| Component | Used for | How |
|---|---|---|
| `UApp` | Wraps the whole Inertia app once, in `app.ts` | Provides the toaster, tooltips and overlays (`useOverlay`). Toaster position `bottom-right` (phone: `top-center`), 4 s. |
| `useToast()` | The result of a save | Fed by the flash-to-toast contract ([SYSTEM_DESIGN §4.3](SYSTEM_DESIGN.md#43-inertia)): `success` → green tick, `error` → red, `info` → neutral. One sentence, amounts included ("Payment saved. Change ₱85.00"). A toast never carries the only copy of an error that blocks the user; that is an inline `UAlert` or a dialog. |
| `UButton` | Every button | Table below. Labels are verbs that say what happens: "Check in and print slip", not "Submit". |
| `UFormField` + `UInput` | Text, password, tel, search fields | `UFormField` gives `label`, `hint`, `help`, `error` (server errors from the `errors` prop land here by `name`). "(optional)" is part of the label. `UInput size="lg"` (36 px) on desktop, `xl` (44 px) on the sign-in panel, `xl` + `class="kiosk"` on the kiosk (72 px), `xl` (48 px) on the phone. Leading icon through `icon="i-lucide-search"`. The password field has a "Show" text button at its right end. Validation on blur and on submit, not on every keystroke. |
| `UTextarea` | Notes and reasons | Same field wrapper; 3 rows; Enter adds a line |
| `USelect` | A choice from a list that can grow: reason, expense category, incoming front desk, approver, branch picker | `:items="[{ label, value }]"`, `value-key="value"`. A fixed small set is `OptionTiles` or `PeriodSegments` instead. |
| `USwitch` | On/off settings (46): modules, deposit, VAT | Label on the left, switch on the right, one line of help under the label |
| `UCheckbox` | Check-out returns (16), cleaning checklist (33), multi-pick filters | Square 3 px corner (override) so it never reads as a radio |
| `UModal` | Take payment (11), PIN dialogs (3, 14), and the base of `ConfirmDialog` and `ConflictDialog` | `title`, `description`, `:dismissible="!saving"`; footer slot right-aligned: text button (the safe choice) then the action. Radius 12 px, padding 20 px, width 440 px, the modal shadow. Under 640 px a modal is full screen with the buttons pinned at the bottom. |
| `USlideover` | Side sheets: kiosk holds (5), add charge (10), "view all forwarded guests" (25), ledger lines behind a report figure (37) | `side="right"`, width 420 px, `:overlay="false"` so the page stays readable; header = title + close; `#footer` holds the `TotalRow` and at most two buttons. Esc and × close it; closing with unsaved input asks first. |
| `UDropdownMenu` | `UserMenu`, `ExportMenu`, row menus ("⋯") | Items with `icon`, `label`, `onSelect`; destructive items last, separated, red on hover only |
| `UAlert` | Inline notes inside a page or card: a rule, a warning, a blocking error, a passed check | `variant="soft"`, `color` = `success` / `warning` / `error` / `info`, icon by colour (`i-lucide-check`, `i-lucide-triangle-alert`, `i-lucide-clock`), text in the tone's colour on its `-50` fill, no border, radius 10 px. The missing-rate message (52) is a `UAlert color="error"`. |
| `UBadge` | **Only** where a count sits beside a label: sidebar "Attention 3", a tab with a count, "+ 20 more" | `variant="subtle"`, `color="neutral"`, no ring, weight 400. Never for a status; statuses are `StatusLabel`. |
| `USkeleton` | First load of a page and every deferred dashboard card | `class="h-… w-…"` in the shape of the content. A polling refresh never shows skeletons. |
| `UTooltip` | Icon-only buttons, the collapsed sidebar rail, truncated names, the reason a control is disabled | Text only, 400 ms delay; never the only place a rule is written |
| `UAvatar` | Top bar user, "on duty" names | `:alt="name"` renders the initials (two letters), 28 px, `nav-active` fill, `text` colour, weight 500. No photos. |
| `UCalendar` | Date and date-range pickers on reports (36–43), Stats (63), reservations (58) | Inside a `UPopover` opened from a secondary button that shows the formatted range ("‹ 1 – 10 Nov 2026 ›"); `range` on Stats; week starts Monday; no third-party date picker ([TRD §2](TRD.md#2-allowed-dependencies)) |
| `UPopover` | Host of `UCalendar` and of the chart tooltip | — |
| `USeparator` | The rule above "Sign out" in the sidebar; between groups in a form card | 1 px `line` |

`UButton` — the one button system:

| Role | Props | Look |
|---|---|---|
| Primary (one per screen) | `color="primary" variant="solid"` | `primary` fill, white text, weight 500, no shadow |
| Secondary (every other button) | `color="neutral" variant="outline"` | White, `ink` text, 1 px inset `line-strong` ring, **no shadow**; hover `bg-elevated` |
| Text | `color="primary" variant="link"` (or `color="neutral" variant="ghost"` for "Cancel", "Back", "Not yet") | No fill, no ring |
| Destructive confirm (inside a `ConfirmDialog` only) | `color="error" variant="solid"` | `bad` fill, white text |
| Destructive at rest (row action, "Void", "Release") | `color="error" variant="outline"` | Looks like a secondary button with `muted` text; turns red (`bad` text on `bad-50`) on hover only |
| On a photo (welcome 59, kiosk 27) | `class="on-photo"` / `class="on-photo-outline"` | White fill with `ink` text ("See rooms and rates", "Tap to start"), or transparent with a 1 px white-70% border and white text ("Staff sign in", "How a stay works"). Only on the photo layer. |
| Needs approval | any of the above + `icon="i-lucide-lock"` | The lock says a supervisor PIN follows |
| Loading | `loading` (or `loading-auto` on a submit) | Spinner before the label, label in the progressive form ("Saving…"), width does not change, clicks blocked |
| Disabled | `disabled` | Solid: `--hm-disabled` fill, `--hm-disabled-ink` text. Outline: `muted` text at 60%. A tooltip or helper line says why. Every submit button is disabled while `ConnectionBar` shows. |

| Size | Height | Text | Where |
|---|---|---|---|
| `sm` | 28 px | 12 px | Buttons inside table rows and inside the top bar on a phone |
| `md` (default) | 32 px | 14 px | Top bar actions, filters, dialog footers |
| `lg` | 36 px | 14 px | Form buttons on desktop; inputs use the same height |
| `xl` + `block` | 40 px (44 px on the sign-in panel), radius 7 px, 16 px text | 16 px | The main action at the bottom of a summary card ("Check in and print slip", "Confirm count"), "Sign in" |
| `xl` + `class="phone"` | 48 px (56 px for "Room is ready") | 16 px | `PhoneLayout` |
| `xl` + `class="kiosk"` | 72 px | 24 px | `KioskLayout` |

Theme overrides in `vite.config.ts` (`ui.ui`):

| Component | Override | Why |
|---|---|---|
| `button` | `slots.base: 'cursor-pointer font-medium shadow-none'`; compound `neutral` + `outline`/`subtle` → `bg-default text-highlighted ring ring-inset ring-accented hover:bg-elevated`; compound `error` + `outline` → `bg-default text-muted ring ring-inset ring-accented hover:bg-(--hm-bad-50) hover:text-error`; compound `primary` + `solid` + disabled → `disabled:bg-(--hm-disabled) disabled:text-(--hm-disabled-ink) disabled:opacity-100`; size `xl` → `rounded-[7px]` | One solid primary, the rest white with a 1 px border, nothing lifted |
| `modal` | `slots.content: 'rounded-xl shadow-(--hm-shadow-modal) ring-0'`; under 640 px: full screen, body scrolls, footer pinned | 12 px radius, the one soft shadow; usable on a phone |
| `slideover` | `slots.content: 'border-l border-default shadow-[-14px_0_40px_rgba(0,0,0,.2)]'` | The mockup's drawer |
| `badge` | `slots.base: 'font-normal'`; `subtle` → `ring-0` | Quiet counts |
| `checkbox` | `slots.base: 'rounded-[3px]'` | Square = "pick several" |
| `input`, `select`, `textarea` | default `size: 'lg'`, `variant: 'outline'`; rest → `ring-accented`; focus → `ring-1 ring-primary` plus `shadow-[0_0_0_3px_var(--hm-focus)]`; error → `ring-error` + `shadow-[0_0_0_3px_var(--hm-bad-50)]` | The focused and error fields of the mockup |
| `alert` | `slots.root: 'rounded-[10px] ring-0'`; soft variants use the `-50` fill and the tone's text colour | The mockup's note |
| `avatar` | `slots.root: 'bg-(--hm-nav-active)'`, `slots.fallback: 'text-default font-medium'` | Initials avatar |
| `icons` | `loading: 'i-lucide-loader-circle'` | One spinner |

Icons are Lucide through Nuxt UI's Iconify integration, written `i-lucide-<name>` and bundled locally ([ASSETS §1.2](ASSETS.md#12-icons)). Sizes: 16 px in buttons and status rows, 18 px in the sidebar, 40 px in an empty state (96 px on the kiosk).

### 5.2 Our own components

All live in `resources/js/Components/` (charts in `Components/Charts/`). Each takes typed props, emits events, and knows nothing about routes. Only the shell pieces, `ConnectionBar`, `OverdueBar`, `PinSwitch` and `ApprovalPin` read `usePage()`.

#### App shell pieces

Used by the four desktop layouts (section 6.1).

**SidebarNav**

| Prop | Values |
|---|---|
| `groups` | `{ heading: string, items: NavItem[] }[]`, built on the server from the shared `nav` prop ([SYSTEM_DESIGN §4.3](SYSTEM_DESIGN.md#43-inertia)); `NavItem = { key, label, route, icon, subtitle?, count? }` |
| `current` | The `key` of the active item |
| `collapsed` | Icon rail (64 px). `v-model:collapsed`; remembered in `localStorage` |
| `drawer` | Under 768 px: off-canvas, opened by the menu button in `TopBar` |

| Part | Look |
|---|---|
| Frame | `sidebar` fill (`#fbfaf8`), 1 px `line` right edge, full height, scrolls on its own |
| Logo | Blue Homi logo, 112 × 34 px, top left; in the rail the "H with house" mark, 32 × 32 px |
| Group heading | 11 px uppercase `muted`, weight 600, letter-spacing 0.07em; hidden in the rail |
| Link | 18 px icon in `muted` + label, 14 px / 400, `text`, 36 px high, radius 6 px. Hover: `bg-elevated`. |
| Active link | `nav-active` fill (`#ecebe6`), `ink` text at weight 500, the icon in `primary`; `aria-current="page"` |
| Count | `UBadge` after the label ("Attention 3"); in the rail a 6 px `bad` dot on the icon |
| Footer | `USeparator`, then "Sign out" (`i-lucide-x`) |
| Rail | Icons only, each with a `UTooltip` of its label |
| Focus | 2 px `primary` ring inside the link |

**TopBar**

| Prop / slot | Values |
|---|---|
| `title` | The page title, rendered as the page's `<h1>` (19 px / 600). Defaults to the active nav item's label; a page overrides it with `<Head>` title or `PageHeader title`. |
| `subtitle` | Optional `muted` line after the title on wide screens (the nav item's `subtitle`) |
| `#actions` target | An element with id `page-actions` that `PageHeader` teleports into |
| Right side | Status labels (branch, shift — section 6.1), then `UserMenu` |

56 px high, white, 1 px `line` below, 24 px side padding, 12 px gaps. Under 768 px it shows a menu button (`i-lucide-menu`), the title, and the avatar only; status labels move into the drawer header.

**PageHeader**

| Prop / slot | Values |
|---|---|
| default slot | The page's actions, in this order: quiet buttons, `ExportMenu`, then the one solid primary ("Add room", "New reservation", "Close the day") |
| `title` | When set, draws an in-page title row instead of teleporting (wizards, pages outside the app shell) |

By default it renders nothing in the page: it teleports its slot into the top bar beside the title, so the title and the actions share one row and the page starts with content.

**UserMenu**

| Prop | Values |
|---|---|
| `user` | `{ name, role }` from `auth.user` and `auth.role` |
| `canSwitch` | Front desk with other members on the open shift → shows "Switch user" (opens `PinSwitch`) |

`UAvatar` initials + the short name ("Jessa Mae D.") as the trigger of a `UDropdownMenu`: role label (not clickable) · "Switch user" (front desk) · Appearance: Light / Dark / System (`i-lucide-sun`, `i-lucide-moon`, `i-lucide-monitor`). Sign out stays in the sidebar footer, as in the mockup.

#### Mockup v6 components (Phase 11)

| Component | Props and slots | Look |
|---|---|---|
| `PageHeader` | default slot: the page's actions; `#status`: a status pill after the title; `sub`: the sub-line; `title`: draws an in-page title row instead (wizard steps) | The shell (`AppShell`) draws the header itself under the top bar: 44 px ink tile (`bg-tile`) with the nav icon in white, `<h1>` 21 px/600 −0.018em, one 12.5 px `muted` sub-line (the page's `sub`, else the layout's `subtitle`, else the nav item's subtitle, else the branch), actions on the right. `PageHeader` teleports into it. |
| `FilterBar` | `v-model:view` + `views` (List / Calendar), `v-model:search` (debounced 300 ms), `searchPlaceholder`, `searchable`, `loading`; default slot for the chips | The row above a list card: segmented switch, chips, a spacer, a 220 px search box (32 px) at the right end |
| `FilterSelect` | `v-model`, `items`, `label`, `icon` | A `USelect` styled as a 32 px chip: 1 px `line` border, 12.5 px/500, quiet chevron |
| `DateRangeChip` | `from`, `to`, `placeholder`; emits `change` | Calendar icon, "5–12 Nov 2026", chevron; a popover with two native date fields, Clear and Apply |
| `PersonPill` | `name`, `extra` ("+1") | 22 px initials avatar in one of seven soft colours picked from the name (stable), inside a white 28 px pill with a 1 px border. Every table that lists people uses it. `PersonAvatar` (`name`, `size`, `ring`) and `PersonStack` (`names`) are its parts. In print the pill is the plain name. |
| `Pager` | `page`, `perPage`, `total`; emits `update:page` | Previous (outline, sm) · page numbers with "…" (current in `primary-50`/`primary-ink`) · Next, on a 1 px line at the bottom of the table card; hidden on one page |
| `StackedBars` (Charts) | `labels`, `series` (`{ name, values, color? }`), `current`, `max`, `money`, `unit`, `titles`, `height` (190), `loading`, `emptyText`, `caption`, `legend` | One column per day, series stacked bottom-up 2 px apart in `bar-1`, `bar-2`, `bar-3`, the current day's main segment in `bar-now` and its label in ink; five grid lines, scale on the left in `faint`; tooltip on hover/focus; a hidden table carries the values |
| `ShiftCalendar` | `days` (`{ date, weekday, day, today }`), `bands` (`{ label, rows: Block[][] }`), `emptyText` | Days across (today in a `primary` chip), a dashed-rule label per shift period, blocks spanning a person's consecutive days (`ok` while open with the static `live` dot, `primary` when closed, `bad` force-closed), dashed white block for days not opened yet. Built by `App\Queries\ShiftCalendarQuery`; used on 61 and the Calendar tab of 37a. |
| `StaysTimeline` | `floors` (`{ label, rooms }`), `now`, `hours` (8), `before` (2) | Rooms down, hours across from now −2 h, a 2 px `primary` now-line with the time; a block per room from check-in to expected check-out (`primary` occupied, `warn` due out, `bad` overdue, `clay` to clean, `violet` cleaning, dashed `held-line` kiosk hold), "Vacant · Check in" or "Blocked". Names and times only. Built by `TodayQuery::timeline()`. |
| `SalesOverview` | `overview`, `title`, `showRange`; `#control` slot | Big net-sales figure, Rooms and Extras side by side, one 6 px bar per payment method with its share (61 Sales overview, 60 How guests paid). Managers only. |

`StatusLabel` is now a pill (22 px, radius 99, 11.5 px/500, 1 px border in the tone's `-line`). `StepBar` is one bordered strip. `StatCard` takes `change` (shown on the right of the sub-line in `ok`/`bad`) and draws a trend arrow beside the value; `StatCards` lays out 3, 4 or 5 columns by count. `SidebarNav` has the branch card, folding parents (`NavItem.children`), Notifications, Help (a dialog with the keys that work everywhere) and the user card whose menu holds Switch user, Appearance and Sign out; `TopBar` has breadcrumbs, the room search (front desk; Ctrl K) and the bell. The shared `branchCard` prop feeds the branch card.

#### StatCard / StatCards

| Prop (`StatCard`) | Values |
|---|---|
| `label` | Says the period: "Sales today", "Short / over · 7 days" |
| `value` | Preformatted string or number: "₱9,420", "32 of 74", "14 / 10", "9 PM" |
| `sub` | One sub-line: "23 of 46 rooms", "▲ 8% vs yesterday by this time" |
| `trend` | `up` · `down` · none — colours the sub-line `ok` / `bad` and adds ▲ / ▼. Omitted when there is no previous data. |
| `tone` | `default` · `bad` (a real problem: a short, an overdue count above 0) · `primary` (the page's headline figure) |
| `to` | Makes the whole card a link to the list behind the number |

| Prop (`StatCards`) | Values | Default |
|---|---|---|
| `items` | `StatCard[]` | `[]` |
| `loading` | Shows `count` skeleton cards of the same size | `false` |
| `count` | Number of placeholders | `4` |

White card, 1 px `line` border, radius 10 px, no shadow, padding 16 × 18 px: label (13 px `muted`), number (sans 26 px / 600, −0.01em, tabular; `bad` when `tone` is `bad`), sub-line (12 px). The number is never mono. The grid is 4 equal columns (5 when five items), 2 under 768 px. Money on a KPI card is whole pesos (section 7.1). Hover on a linked card: `bg-elevated`.

#### PeriodSegments

| Prop | Values |
|---|---|
| `modelValue` | The selected `value` |
| `items` | `{ value, label, disabled? }[]` — a small fixed set: "Today · Yesterday · This week · This month · This year", "7 days · This month · Last month", "Day · Week · Month" |

One track (`--hm-track`, radius 8 px, 2 px padding); segments are quiet `muted` text; the active one is a white pill (radius 6 px, `ink`, weight 600, `0 1px 2px rgba(0,0,0,.08)`). Radio-group semantics; arrow keys move. It sets every card on the page and writes the URL query. A set that is fed by data or can grow (branches, statuses) is a `USelect`, never a row of segments.

#### StatusLabel

| Prop | Values | Default |
|---|---|---|
| `tone` | `neutral` · `ok` · `warn` · `bad` · `info` · `primary` · `violet` · `clay` (section 2.3) | `neutral` |
| `label` | The word or short phrase, sentence case (also the default slot) | required |
| `size` | `md` (12 px text, 2 × 8 px padding) · `lg` (28 px text, 6 × 14 px padding, radius 6 px: kiosk countdown) | `md` |

An inline-flex box: the word in the tone's text colour at weight 500 on the tone's soft fill, radius 4 px, no border, no dot, no icon, no wrap. Always contains a word. In dense tables two labels sit side by side with 8 px between them ("FWD", "EXT"). The board header counts are `StatusLabel`s with the count after the word ("Vacant 14").

The "on duty now" marker (60, 61) is not a status label: it is a static 6 px `ok` dot before a person's name (class `live`), with the name in the normal text colour.

#### DataTable, ListCard, Pager

Tables are plain `<table>` elements with the `.dtable` style, as in Euphoria ([TRD §12](TRD.md#12-coding-conventions)). `DataTable` is the convenience wrapper for the common case; a page may also write the `<table>` itself inside a `.dtable` element.

| Prop (`DataTable`) | Values |
|---|---|
| `columns` | `{ key, label, align?: 'left' \| 'right' \| 'center', code?: boolean, nowrap?: boolean }[]` — `code` sets a stay-code column in mono |
| `rows` | Array of row objects |
| `footer` | Optional totals row |
| `dense` | 12 px text, 6 × 10 px cells: room chart (37c), handover list (25) |
| `rowClass` | Function returning `void` (struck through, `--hm-void`) or `forwarded` (`bad` text, FWD rows on 37c) |
| `empty` | Props for the `EmptyState` shown when `rows` is empty |
| `loading` | Shows 5 skeleton rows |
| Slots | `#cell-{key}` for status labels and row buttons |

| `.dtable` part | Look |
|---|---|
| Header | 13 px, weight 500, `muted`, not uppercase, no wrap, 1 px `line` below, transparent background |
| Rows | 14 px, 1 px `row` line between rows, none after the last; hover `bg-elevated` when the row is a link |
| Numbers | `td.r`: right-aligned, weight 600, tabular, `ink`. `td.code`: Geist Mono, for a stay code only. Room numbers are sans 600 like any number. |
| Footer | Weight 600, 1.5 px `ink` rule above |
| Group row | `.grp`: weight 600, `ink`, 18 px top padding |
| First column | No wrap |

No zebra stripes. No column sorting in version 1.

| Slot (`ListCard`) | Content |
|---|---|
| `#filters` | Left to right: date or range button, `USelect` filters, `PeriodSegments` |
| `#search` | A `UInput` with `i-lucide-search`, 208 px, always at the right end |
| default | The table |
| `#footer` | One `Pager` |
| `loading` prop | A small spinner beside the search while the server answers |

One white card holding the filter row, the table and the pager. The filter row is left out when neither `#filters` nor `#search` is given. Under 768 px the filters fold behind a "Filters" button and the table scrolls sideways inside the card.

| Prop (`Pager`) | Values |
|---|---|
| `page`, `perPage`, `total` | From the server's paginator |
| emits `update:page` | The page reloads with the new query |

"1–25 of 312" in `muted` on the left; previous / next secondary buttons (`i-lucide-chevron-left`, `i-lucide-chevron-right`) on the right. Hidden when everything fits on one page. One pager per list, at the bottom only.

#### EmptyState

| Prop | Values |
|---|---|
| `icon` | `i-lucide-*`, 40 px, `--hm-empty-icon` (kiosk 96 px; `tone` may colour it: `ok` for done, `bad` for offline) |
| `title` | One bold sentence: "No room or guest matches "manalo"", "All good." |
| `text` | One line saying why or what happens next |
| `actionLabel` | Optional single secondary button ("Clear search", "Back to room board", "Check again") |

Centred, `muted` text, 48 px vertical padding. Also the frame for full-page states: no permission (51), check-out done (18), all rooms taken (31), kiosk offline (31b), all rooms clean (35). A chart with no data shows the compact form: title only, 24 px padding.

#### AttentionList

| Prop | Values |
|---|---|
| `rows` | `{ severity: 'high' \| 'normal', title, detail?, route }[]` — built by the server's `AttentionList` class ([TRD §4.11](TRD.md#411-dashboards-stats-and-summaries)) |
| `limit` | Rows shown on a dashboard card; screen 49 passes none | `5` |
| `moreRoute` | "View all" link to screen 49 when rows were cut |
| `loading` | Three skeleton rows |

A card titled "Needs attention". Each row is a link: a 7 px round severity mark (`bad` for `high`, `warn` for `normal`; the sentence carries the meaning), the sentence in weight 600 `ink`, a `muted` detail line; 1 px `row` line between rows. Empty: "All good." with `i-lucide-check` in `ok`. Rows only ever name something a person should act on; each opens the screen that fixes it.

#### RoomTable and RoomRow

The room board (4) and the board behind sheets (3, 5, 50, 54). A plain table inside one card with no padding; one `RoomRow` per room; a group row per floor ("Floor 1 · 16 rooms", `sidebar` fill, 10.5 px `muted`).

| Column | Content | Look |
|---|---|---|
| Room | `205`, `3A` | 12.5 px / 600 `ink`, tabular |
| Type | Standard, Deluxe, Suite | `text` |
| Status | `StatusLabel` from the display key (section 2.3) | The only coloured part of the row |
| Guest | "R. Manalastas", "Reserved · 2:00 PM", or "—" | `text`; ellipsis |
| Due out | Clock time of `expected_checkout_at`: "9:12 PM", or "—" | `text`, tabular |
| Time | "9h 28m left", "22m left", "42m over", "hold 6:42", "waiting 48m", "cleaning 11m", or blank | `muted`; `warn` 600 when Due out, `bad` 600 when Overdue |
| Balance | Unpaid balance when there is one: "₱115.00" | Right-aligned, tabular |
| Action | "Check in" link on Vacant rows; "›" on rows that open something; blank otherwise | Right-aligned |

| Prop (`RoomTable`) | Values |
|---|---|
| `rooms` | Rows from `BoardQuery`, already grouped by floor |
| `filter` | `all` · `vacant` · `occupied` (includes Due out and Overdue) · `due_out` · `overdue` · `housekeeping` (To clean and Cleaning) · `held` |
| `sort` | `number` (default) · `attention` (Overdue, then Due out, then Held, then the rest by number) |
| `limit` | Rows per floor before a "N more rooms on Floor 2" row (compact views only) |

Above the table: status tabs with counts (11.5 px `muted`; the current tab in `ink` with a 2 px `ink` underline), then search, floor select, sort select and the "Walk-in check-in" primary button.

| State | Look |
|---|---|
| Row padding | 6 × 7 px in the mockup frame; 10 × 12 px in the app; 1 px `#efede8` line between rows |
| Hover (clickable row) | Fill `#faf9f7`; pointer cursor |
| Focus | 2 px `primary` ring inside the row |
| Blocked | All cells `muted` |
| Loading (4b) | Ten skeleton bars in the card, first load only |
| Refresh | Rows update in place every 15 s; the row under the pointer never moves while it is hovered |

Emits `select` with the room. Each row has an accessible name built from its cells: "Room 106, Standard, Due out, L. Ramos, due 12:06 PM, 22 minutes left".

#### MoneyInput

| Prop | Values | Default |
|---|---|---|
| `modelValue` | Integer centavos (`100000` = ₱1,000.00) | `0` |
| `id`, `error` | From the wrapping `UFormField`, so the label focuses the field and the error ring paints the field itself | — |
| `min`, `max` | Centavos | `0`, none |
| `due` | Centavos owed; shows an "Exact" text button that fills the field | none |
| `autofocus`, `disabled`, `placeholder` | | `false`, `false`, `0.00` |
| `size` | `lg` (36 px) · `xl` (40 px, the cash-received field) | `lg` |

| Behaviour | Rule |
|---|---|
| Element | A text input with `inputmode="decimal"`, never `type="number"` (no spinner arrows, no scroll-wheel changes, no "e") |
| Display | "₱" prefix, sans 600, tabular; thousands separators and two decimals applied on blur: `₱ 1,000.00` |
| Typing | Digits and one decimal point only; at most two decimals; no negatives; leading zeros dropped |
| Emits | Integer centavos, never a float. No arithmetic on money happens in Vue beyond showing the change the server will also compute. |
| States | Normal, focused, error, disabled — the same four as `UInput` |

#### MoneyRow / TotalRow

| Prop | `MoneyRow` | `TotalRow` |
|---|---|---|
| `label` | Left text | Left text ("Total", "Balance due", "Expected") |
| `amount` | Integer centavos, formatted by the component | Same |
| `tone` | `default` · `ok` (change, zero balance) · `bad` (balance due, short) · `primary` | Same |
| `sign` | `auto` (shows `−` for negatives) · `none` (report columns without ₱) | Same |
| `text` | Use instead of `amount` for non-money values ("Wed 9:12 PM", "1 of 2") | — |

`MoneyRow`: label left in `text`, value right in sans 600 tabular `ink`, 4 px vertical padding. `TotalRow`: 1.5 px `ink` rule above, 16 px, weight 700.

#### OptionTiles

A single-choice group of tiles for a small fixed set that carries a second line: stay length with price, payment method, floor filter, Yes/No, report tabs.

| Prop | Values | Default |
|---|---|---|
| `modelValue` | The selected option's `value` | — |
| `options` | `{ value, label, sub?, disabled?, reason? }[]` — `sub` is the second line (price, "ref. no.") | required |
| `columns` | `auto` (wrap) or a number (kiosk uses 2) | `auto` |
| `size` | `md` · `kiosk` (min-height 96 px) | `md` |

| State | Look |
|---|---|
| Normal | White, 1 px `line-strong` border, radius 6 px, label 500 in `text`, `sub` in `muted` (500, tabular) |
| Selected | `select-line` border (`#9fc3de`), `select` fill (`#eef4f9`), label 600 and `sub` in `primary-ink` (`#0b5f96`) |
| Unavailable | 45% opacity, not focusable, `sub` shows the reason ("no rate", "₱0 available") |
| Add tile | Dashed `line-strong` border, "+ Add" (admin rates 45) |

Keyboard: arrow keys move, Space selects (radio-group semantics).

#### StepBar

| Prop | Values |
|---|---|
| `steps` | `string[]`, e.g. `['Check', 'Count cash', 'Result', 'Handover']` |
| `current` | 1-based index |

Equal-width pills (radius 999 px), numbered in the label ("1 Check"). Done: `ok-50` fill, `ok` text. Current: `primary` fill, white text, weight 600. Next: `step` fill (`#eeedea`), `text` colour (the mockup draws it in `muted`, which is 4.2 : 1 on that fill; `text` keeps it at 8 : 1 or better). Steps are not clickable; Back and Next buttons move between them.

#### PinPad

| Prop | Values | Default |
|---|---|---|
| `length` | From `config('hotel')` PIN length | `4` |
| `error` | Message under the cells ("PIN not recognised") | none |
| `triesLeft` | Shown as "3 tries left" | none |
| `locked` | PIN entry is locked on this computer: cells and keys disabled, message "PIN entry is locked on this computer. Ask a supervisor to unlock it." | `false` |
| `disabled` | While verifying | `false` |
| `showKeys` | On-screen keys; desktop also accepts the keyboard | `true` |

| Part | Look |
|---|---|
| Cells | 44 × 52 px, 1.5 px `--hm-control-dash` border, radius 8 px; filled: `primary` border, `primary-50` fill, shows "•" |
| Keys | 3 × 4 grid, 1–9, blank, 0, ⌫; each 56 px high (kiosk and phone 64 px), `grey-50` fill, sans 600 tabular |
| Error | Cells get the `bad` border; no shake; digits are cleared |

Emits `complete` with the digits when the last cell is filled. Digits are never shown, stored or logged.

#### PinSwitch

| Prop | Values |
|---|---|
| `members` | Members of the open shift other than the current user |
| `open` | `v-model:open` |
| `locked` | From the server when this computer has an open `pin_locks` row |

A 360 px `UModal` titled "Switch user": pick a member (skipped when there is only one), then a `PinPad`. Shows the name being signed in and "3 tries left". Text button "Cancel". Three wrong PINs go to the full sign-in (1); ten wrong tries in a row on this computer lock PIN entry until a supervisor or admin unlocks it with their own password ([TRD §4.12](TRD.md#412-shift-safeguards)). Opened from `UserMenu`.

#### ApprovalPin

| Prop | Values |
|---|---|
| `summary` | One line of what is being approved: "Transfer 108 → 212 · collect ₱150.00 · "Aircon not cooling"" |
| `approvers` | Supervisors and admins of the branch (and owners), never the requester; for a variance, never the person who counted |
| `error` | "PIN not recognised" |
| `locked` | As `PinSwitch` |
| `open` | `v-model:open` |

A 360 px `UModal` titled "Supervisor approval" with `i-lucide-lock`: summary line, approver `USelect`, `PinPad`, then "Cancel" (text) and "Approve" (primary, disabled until 4 digits). Nothing is saved until the PIN is accepted.

#### ConfirmDialog

Promise-based, opened through the composable: `const confirm = useConfirmDialog(); if (await confirm({ … })) { … }`. It mounts through Nuxt UI's `useOverlay()`, so no page keeps an `open` flag for it.

| Option | Values | Default |
|---|---|---|
| `title` | A question naming the thing: "Release Room 210?" | required |
| `description` | One or two sentences saying what happens to the room, the money and the stock, amounts in bold | none |
| `confirmLabel` | The action as a verb: "Release room", "Cancel stay" — never "OK" or "Yes" | required |
| `cancelLabel` | The safe choice as a verb: "Keep hold", "Keep stay" | required |
| `confirmColor` | `primary` · `error` (title in `bad`, confirm button red solid) | `primary` |
| `reasons` | `{ id, name, needs_note, needs_manager }[]` from the `reasons` table for this kind; shows a required `USelect` | none |
| `needsApproval` | Lock icon on the confirm button; `ApprovalPin` opens next. Forced on when the picked reason has `needs_manager`. | `false` |
| `loggedAs` | "Recorded as Jessa Mae D." under the buttons | current user |

Resolves `false` on cancel or Esc, or `{ reasonId, note }` on confirm. A note field appears and is required when the picked reason has `needs_note`. The safe button is on the left as a text button, the action on the right. Focus starts on the safe button (on the reason field when there is one).

#### ConflictDialog

| Prop | Values |
|---|---|
| `title` | "Room 205 was just taken" |
| `message` | Who took it, and that nothing was saved and no payment was recorded |
| `primaryLabel` | "Pick another Deluxe room" |
| `secondaryLabel` | "Back to board" |

A `UModal` that is not dismissible by overlay click. The primary action keeps the guest's details and reopens check-in with the next free room of the type (53).

#### ConnectionBar

No props; reads `useConnection()`. Mounted once in every layout.

| State | Look |
|---|---|
| Online | Not rendered |
| Offline | Full-width bar above everything: `bad` fill, white 600 text, `i-lucide-wifi-off`: "No connection to the server. Do not take payments until this bar disappears. Trying again in 5 s…". Page content drops to 50% opacity and every submit button is disabled. |
| Back online | Bar disappears; the page reloads its props once |

The kiosk shows the full-page offline state (31b) instead of the bar.

#### OverdueBar

| Prop | Values |
|---|---|
| `periodName` | "AM" |
| `endedAt` | The shift's defined end time |
| `blocked` | The shift is past `shift_stale_block_minutes`: payments and charges are refused |

Rendered by `FrontDeskLayout` when the shared `shift` prop says the shift is past its end by `shift_overdue_warn_minutes`. `warn-50` fill, `warn` 600 text, `i-lucide-triangle-alert`: "The AM shift should have ended at 8:00 PM. You can keep working, and everything still goes to the AM shift." When `blocked`: `bad-50` fill, `bad` text: "The AM shift is 4 hours past its end. Payments and charges are stopped until it is closed." A small secondary button "Close shift" sits on the right. It cannot be dismissed.

#### Slip (80 mm)

| Prop | Values |
|---|---|
| `header` | `branches.slip_header`, printed in capitals |
| `title` | "CHECK-IN SLIP", "RECEIPT", "SHIFT REPORT · AM · 11 NOV 2026" |
| `number` | Optional document number line, e.g. `Z-0629` on the shift report slip |
| `sections` | Arrays of `{ label, value, bold? }` rows, separated by dashed rules |
| `footer` | Lines under the last rule ("Front desk: Jessa Mae D.") |
| `reprint` | Prints "REPRINT" under the title when the server marks the print as a reprint |
| `preview` | Renders the on-screen preview with the dashed `--hm-slip-line` border |

One component for the thermal slips (8, 18, 26) and the 80 mm form of the Z-report. Geist Mono only. Labels left, amounts right-aligned in the same line; amounts have no ₱ sign and use an ASCII hyphen for negatives. Always light. Print layout: section 6.10.

#### ZPaper

The Z-report paper (37b), the day paper (38b) and their previews. It renders the one builder array from `ReportService::zReport()`; the PDF Blade view and the CSV render the same array, so no format computes a figure of its own ([TRD §4.10](TRD.md#410-z-numbers-grand-total-and-the-day-close)).

| Prop | Values |
|---|---|
| `paper` | `{ header: { branch, title, number, opened, closed, printed }, sections: Section[], signatures: { name?, role }[] }` |
| `Section` | `{ key, title, layout: 'waterfall' \| 'table' \| 'lines', columns?: string[], lines: Line[], note?: string }` |
| `Line` | `{ label, count?: number, amount?: number \| null, cells?: (string \| number)[], style: 'plain' \| 'indent' \| 'equals' }` |
| `preview` | The shift is still open: no Z number, a diagonal watermark "Preview. Do not sign." in `muted` at 12%, export and print disabled |

| Part | Look |
|---|---|
| Sheet | White, 1 px `line` border, no shadow, radius 10 px, padding 24 × 28 px, max width 880 px, 13 px text. Always light, also on screen in dark mode. |
| Header | Left: blue Homi logo (112 × 34 px), branch name (600), "Z-READ · AM shift · Wed 11 Nov 2026" in `muted`. Right: the Z number in sans 19 px / 600, then "Opened … · Closed …" and "Printed … by …" in `muted`. |
| Body | Two columns, 24 px gap: the "Sales and cash" waterfall on the left; "By front desk", "Exceptions", "Documents" and "Running grand total" on the right. One column under 768 px and on 80 mm. |
| Section heading | 11 px uppercase `muted`, weight 600, letter-spacing 0.06em, 12 px above |
| `plain` line | Label left, amount right (500, tabular, `ink`), 2 px vertical padding |
| `indent` line | Indented 12 px, label in `muted` with the sign word and count: "Less Promo ₱50 · 4", "Plus deposits taken · 27"; negative amounts with `−` |
| `equals` line | 1 px `ink` rule above, weight 700: Net sales, Collected, Cash collected, Cash from this shift, Expected in the drawer, After this shift |
| `table` section | A dense `.dtable` ("By front desk": Name · In · Cash · Back; "Exceptions": name · count · amount, fixed rows printed even at zero, "—" where an amount does not apply) |
| Short / over | "Short" with a `−` amount in `bad`, or "Over" in `ink`; the variance note in quotes, `muted`, under it |
| Signature block | Three equal columns, each a 1 px `ink` rule with the pre-printed name above the role in `muted`: "Closed by", "Received by", "Back office · name and date" (blank name) |
| VAT block | A fourth right-hand section (VATable sales, VAT, VAT-exempt) and the TIN in the header, only when the array carries it |

The tabs above the paper (Sales and cash · Rooms by floor · Cleaning chart · Housekeeping log · Stocks · Expenses) are `OptionTiles`; "Rooms by floor" (37c) is two `DataTable`s, not a `ZPaper`.

#### ExportMenu

| Prop | Values | Default |
|---|---|---|
| `print` | Shows "Print" | `true` |
| `pdf` | Shows "PDF" (a file made on the server) | `false` |
| `csv` | Shows "CSV" | `false` |
| `disabled` | A preview cannot be exported; tooltip says "Close the shift first" | `false` |
| `loading` | While the server builds a file | `false` |
| emits | `print`, `pdf`, `csv` | |

One secondary button "Export" with `i-lucide-chevron-down`, opening a `UDropdownMenu`: Print (`i-lucide-printer`), PDF (`i-lucide-file-text`), CSV (`i-lucide-sheet`). The page records the print (`print_logs`) before calling `window.print()`. Sits in `PageHeader`, before the primary button.

#### Charts

Small SVG components of our own in `Components/Charts/`; no chart library ([TRD §2](TRD.md#2-allowed-dependencies)). One kit, used by the dashboards (60, 61), Today (62), Stats (63) and the owner summary (39).

| Component | Props | Draws |
|---|---|---|
| `VBars` | `labels: string[]`, `series: { name, values: number[], color? }[]`, `money?: boolean`, `unit?: string`, `wide?: boolean`, `titles?: string[]` (tooltip titles) | Vertical bars over time. One series: all bars `--hm-bar-soft`, the peak in `primary` and named in the card header ("Busiest: Sun ₱71,300"). Two series: side by side with a legend. Four light grid lines, axis labels in `muted` 11 px. |
| `HBars` | `rows: { name, value, text, total? }[]`, `color?` | Ranked horizontal bars: name left (truncated at 22 characters), 7 px bar on a `grey-50` track, the value text right in 600 tabular. `total` draws the track to that length. |
| `Shares` | `rows: { name, value, color? }[]`, `money?: boolean` | Shares of one whole: one 10 px stacked bar with 2 px gaps and a legend under it (square 7 px swatch, name, value or percent). Up to five parts; a sixth and more fold into "Other". |
| `HeatGrid` | `hours: string[]`, `grid: Record<'Mon' … 'Sun', number[]>` | Weekdays down, hours across, one hue (`primary`), opacity `0.04` for zero and `0.12 + 0.88 × value / max` otherwise; caption "8 AM to 10 PM · darker is busier". |
| `Spark` | `values: number[]`, `tone?: 'default' \| 'ok' \| 'bad'` | A 64 × 20 px line with no axes, for a table row or a stat card sub-line |
| `ChartTip` | — (reads the shared `tip` state) | The one hover tooltip: title + value, positioned at the pointer |

Shared helpers in `Components/Charts/chart.ts`: `tip`, `showTip(event, title, text)`, `hideTip()`, `num(n)`, `peso(centavos)` (whole pesos), `pesoShort(centavos)` (`₱71.3k` on axes), `niceTop(max)`, `barPath(x, y, w, h, r = 4)`, `hbarPath(x, y, w, h, r = 4)`.

| Rule | Detail |
|---|---|
| Colours | Series order `primary`, `info`, `ok`, `accent`, `violet`; "Other" is `grey`. Room-status shares use the status colours (section 2.3). Tokens only, so dark mode follows. |
| Values | Hover (and keyboard focus) shows the exact value in `ChartTip`; every chart also has a text equivalent — the legend, the value column, or a visually hidden table. |
| Empty | No data → the compact `EmptyState` ("No sales in this period"), never an empty axis |
| Loading | A `USkeleton` the size of the chart |
| Refresh | Values change in place; no animation on a 30 s refresh |

#### PhotoHero

The photo layer of sign-in (1), welcome (59) and kiosk welcome (27). Light only. One component, so the scrim, the fallback and the motion rules live in one place.

| Prop / slot | Values | Default |
|---|---|---|
| `photos` | `{ src, srcset }[]` — one photo, or three for the welcome slideshow; files in `public/images/photos/` ([ASSETS §1.5](ASSETS.md#15-photographs)) | required |
| `scrim` | `default` (`--hm-scrim`, 40%) · `kiosk` (`--hm-scrim-kiosk`, 52%) | `default` |
| `drift` | Slow drift and zoom on the photo (section 4.5) | `true` |
| `interval` | Seconds each photo shows when there are several | `7` |
| `indicators` | Thin slide indicators bottom left (18 × 2 px lines, 6 px apart; `slide-off` and white) | `true` when more than one photo |
| default slot | The content, above the scrim | — |

Layers, back to front: the `photo-fallback` colour block · the photo or photos (`background-size: cover`, centred, `aria-hidden`, empty `alt`) · the flat scrim · the content. With several photos they are stacked and cross-faded on the 21 s cycle of section 4.5, and the hero pauses while hovered or focused. The fallback block stays visible behind a photo that is still loading or fails, so white text is readable in every case. Under reduced motion it shows the first photo still and nothing else moves. The first photo is loaded eagerly with `fetchpriority="high"`; the others lazily.

#### SignInPanel

The right half of the sign-in (1): a plain white panel, deliberately not a card.

| Prop / slot | Values |
|---|---|
| `heading` | "Sign in" |
| `line` | "Use your own account. Never share it." |
| default slot | The form: username, password with "Show", the full-width primary button |
| `#footer` | "Forgot your password? Ask your branch admin." |

| Part | Look |
|---|---|
| Panel | `card` fill, full height of the screen, padding 48 × 56 px, no border, no radius, no shadow |
| Logo | Blue Homi logo top left of the panel, 112 × 34 px |
| Form block | Centred vertically (`margin: auto 0`), at most 360 px wide: serif "Sign in" (34 px / 400, `ink`), the line in `muted`, 24 px gap, then the two `UFormField`s (`UInput size="xl"`, 16 px apart) and `UButton size="xl" block` "Sign in" |
| Footer line | 13 px `muted`, pinned to the bottom of the panel |
| Errors | Wrong password or a locked account: a `UAlert color="error"` above the fields, one sentence ("Wrong username or password. 3 tries left before a 15-minute lock.") |

No stub, no chip, no dashed tear line, no notches, no pill button — section 10.

#### RoomTypeCard

One room type on the welcome page (59).

| Prop | Values |
|---|---|
| `name` | `room_types.name`, "Deluxe" |
| `line` | One line from `room_types.description`, "Queen bed, hot shower, work desk." |
| `photo` | The room type's photo ([ASSETS §1.5](ASSETS.md#15-photographs)); none → the `photo-fallback` block |
| `price` | Lowest price for the shortest stay length, whole pesos, with its length: "₱450" + "6 hours" |
| `overnight` | Overnight price when the type has one: "Overnight ₱950" |

A white card, 1 px `line` border, radius 10 px, `overflow: hidden`, no shadow. The photo fills the top (4 : 3, `object-fit: cover`, empty `alt`); under it, 16 × 18 px padding: name (17 px / 600), the line (14 px `muted`), then one row with the price (22 px / 500, `ink`) and its length in `muted` on the left and the overnight price in `muted` on the right. Not a link in version 1 (no booking). Prices are read live from `rates`.

#### KioskWelcome

The resting screen of the kiosk (27), inside `KioskLayout`.

| Prop | Values |
|---|---|
| `branchName` | "Hotel Dalisay" in "Welcome to Hotel Dalisay" |
| `rules` | `branches.house_rules`, one rule per line |
| `freeRooms` | Count for "12 rooms available now" |
| `photo` | The lobby photo |

A full-screen `PhotoHero` with `scrim="kiosk"` and drift. Top: white logo (300 × 91 px). Then the serif welcome title (80 px), one sentence ("Choose your room here, then pay at the front desk."), and the house rules in a **white** panel (radius 10 px, 28 × 32 px padding, `ink` title, `text` rules as a numbered list). At the bottom: one white button "Tap to start" (72 px, `ink` text, full width) and the free-room line under it. Tapping anywhere starts. The fade-up runs each time the screen returns from idle.

#### KioskLayout pieces

| Component | Props | Look |
|---|---|---|
| `KioskStepBar` | As `StepBar` | The same pills at 48 px high, 20 px text |
| `KioskTypeCard` | `name`, `description`, `room` (label of the offered room), `full`, `selected` | Full-width card, 28 px title, `muted` description; the offered room as a `primary` `StatusLabel` on the right; white with a 1 px `line` border, radius 10 px; selected: `select-line` border + `select` fill; full: 50% opacity with a `bad` label "Full" |
| `KioskFooter` | slots `back`, default | Buttons pinned to the bottom: "Back" (secondary, 72 px) left, the primary (72 px) filling the rest |
| `KioskHoldCard` | `code`, `amount`, `expiresAt` | Centred: amount to pay, QR 400 × 400 px, stay code in 48 px mono, countdown as `StatusLabel size="lg" tone="warn"` ("6:42 left", sans tabular) |
| `useKioskIdle` | `(seconds, onIdle)` | Returns to the welcome screen after 60 s without a touch (30 s on the hold screen) |

#### PhoneLayout pieces

| Component | Props | Look |
|---|---|---|
| `PhoneHeader` | `title`, `subtitle`, `tone: 'primary' \| 'violet'` | Flat coloured band (no gradient), 16 px padding, white text: title 18 px / 600 and one subtitle line. `primary` on the queue (32, 35) and history (34); `violet` while cleaning (33). Two links only (queue, my cleans). |
| `TaskCard` | `room`, `waiting` (label), `tone`, `detail`, `startable`, `urgent` | Card (1 px `line` border, radius 10 px, no shadow): room number in sans 20 px / 600, a `StatusLabel` on the right, one helper line, one full-width 48 px button. Urgent: 4 px `bad` left border. Not startable: 55% opacity with a disabled "Next in line" button. |
| `CleanTimer` | `startedAt`, `targetMinutes` | Timer in 56 px sans 600 tabular, target line, progress bar (`violet` on `grey-50`, 999 px radius) |

## 6. Layout patterns

### 6.1 App shell (desktop and down)

Used by `FrontDeskLayout`, `BackOfficeLayout`, `AdminLayout` and `OwnerLayout`; each is a thin wrapper that passes the server-built `nav` to the same shell. Designed for 1280 × 720 and wider, and usable down to a phone.

| Part | Spec |
|---|---|
| Sidebar | `SidebarNav`: 232 px, white, 1 px `line` right edge. Logo and collapse button, branch switcher card, groups with uppercase labels, links with icons (parents fold open), then Notifications, Help and the user card with Sign out in its menu (section 0). |
| Collapsed | A toggle in the sidebar header (`i-lucide-panel-left`) collapses it to a 64 px icon rail with tooltips. The choice is remembered in `localStorage`; on a first visit under 1100 px wide it starts collapsed. |
| Under 768 px | The sidebar is an off-canvas drawer (`min(280px, 85vw)`), opened by the menu button in the top bar, closed by a tap outside, Esc, or choosing a link. |
| Top bar | `TopBar`: 56 px, white, 1 px `line` below. Left: breadcrumbs. Right: search (Ctrl K), status labels (shift), Close shift for the front desk, the bell. |
| Page header | `PageHeader`: ink icon tile, `<h1>` title with an optional status label, one sub-line, and the page's teleported actions on the right. |
| Content | `bg-muted` page, 28 px side padding (16 px under 768 px), 20 px below the page header. |
| Bars | `ConnectionBar` above everything; `OverdueBar` between the top bar and the content. |

| Role | Sidebar groups → links (icon) | Top bar shows, after the title |
|---|---|---|
| frontdesk, supervisor | **Front desk** → Today (`clock`) · Room board (`bed`) · Reservations (`list`) · POS (`shopping-cart`) · Stock (`package`). **Shift** → Shift cash (`banknote`). Links for switched-off modules are left out by the server. | Branch (`primary` label) · shift label: `ok` "AM shift · Wed 11 Nov", `neutral` "No open shift", `warn` "AM shift · closing", `warn` "AM shift · 40 min over" · "Close shift" (secondary button; hidden when no shift is open or while closing) · avatar + "Jessa Mae D." |
| backoffice | **Overview** → Dashboard (`chart-column`). **Reports** → Reports (`list`) · Shift reports (`banknote`) · Z-report (`list`) · Daily sales (`chart-column`) · Occupancy (`bed`) · Housekeeping (`brush`) · Inventory (`package`) · Audit trail (`lock`). **Analytics** → Stats (`chart-column`). | Branch · avatar + name |
| admin | **Overview** → Dashboard (`chart-column`) · Attention (`triangle-alert`, with a count). **Setup** → Rooms (`bed`) · Rates (`banknote`) · Products (`package`) · Users (`user`) · Settings (`settings`). **Reports** → Reports (`list`) · Stats (`chart-column`). | Branch · avatar + name |
| owner | **Overview** → Dashboard (`chart-column`) · Branches (`list`) · Owner summary (`chart-column`). **Reports** → Reports (`list`) · Stats (`chart-column`). **People** → Owner accounts (`user`). After opening a branch: the admin groups for that branch. | Branch: "All branches" or the opened branch's name · avatar + name |

The navigation is one array built on the server and filtered by role and module, so a new page appears in the sidebar without a change to a layout. Front desk users never see sales or cash figures anywhere in the shell.

### 6.2 Dashboard grid

Owner dashboard (60), branch dashboard (61), Today (62), Stats (63).

| Part | Spec |
|---|---|
| Control row | `PeriodSegments` on the left (Stats: the date-range button, then the segments); on the right "Updated 11:44 AM · refreshes every 30 s" in `muted` 12 px, or "Could not refresh. Retry" with a text button; Stats adds `ExportMenu` |
| KPI row | `StatCards`: 4 cards (5 on Today), 16 px gap |
| Body | Two columns: main `minmax(0, 1fr)` and a 340 px aside, 16 px gap, `align-items: start`. The aside is sticky 16 px under the top bar. Under 1100 px the aside drops under the main column. |
| Main column | Cards stacked with 16 px between them: a chart card (title left, "Busiest: …" right), a table card, a shares or bars card |
| Aside | `AttentionList` first, then one small facts card ("Housekeeping today") |
| Today (62) | KPI row of counts, then two equal cards ("Leaving soon", "Arriving" + rooms legend + "Open room board" primary and "Walk-in check-in" secondary button), and the line "Counts only. Sales and drawer amounts are never shown to the front desk during a shift." No aside, no money. |
| Stats (63) | KPI row, then two equal columns of chart cards (heat grid left; shares and bars right), then full-width cards for top products, payment methods, occupancy by day and the front desk table |
| Loading | The frame (control row, card outlines) renders at once; every figure is a deferred prop with a skeleton of the same size. |
| Refresh | Every 30 s, values change in place. Paused while the tab is hidden. |

### 6.3 Two-column form + summary

Check-in (6), confirm hold (7), extend (12), transfer (13), open shift (2), new reservation (58), add branch (56), close the day (38b: shifts table left, day totals right).

| Part | Spec |
|---|---|
| Left card | What the user chooses and types: `OptionTiles`, fields, in the order they are asked |
| Right card | "To collect": `MoneyRow` lines, a `TotalRow`, payment method tiles, `MoneyInput` for cash received, change, then the one main-action button (`xl`, `block`) |
| Columns | Two equal columns, 16 px gap; the folio (9) uses main + a 340 px summary. One column under 900 px, summary last. |
| Behaviour | The right card updates as soon as an option changes, from prices the server already sent. The main button is disabled until the form is valid and cash covers the total. |

### 6.4 Wizard with StepBar

Check-out (16–18), close shift (22–25), kiosk (28–30).

| Part | Spec |
|---|---|
| Header | In-page title ("Close AM shift · Wed 11 Nov"), then the `StepBar` |
| Body | One card or a two-column pair per step. Close step 1 (22): "Passed on to the next shift" (status label + sentence per row) beside "Have these with you before you count" (label + count per row, no peso amounts). |
| Footer | Right-aligned: a text button back ("Back", "Not yet", "Back to folio") and one primary button named for the next step ("Next: settle", "Next: count cash") |
| Rules | One decision per step. Next is disabled until the step is complete. The last step is a result (`EmptyState` with a tick, or the slip), not a form. |

### 6.5 List + slide-over

Any admin list (44, 47, 48), reservations (57), audit trail (43); board + kiosk holds (5); folio + add charge (10).

| Part | Spec |
|---|---|
| List | A `ListCard` (filters, table, pager), or the board; it stays visible and readable on the left |
| Sheet | `USlideover`, 420 px, for a short task that does not need the whole screen |
| Sheet footer | `TotalRow` and at most two buttons: the primary and one secondary alternative ("Add to folio" / "Add and take payment now") |

Tasks that are a single decision use a `UModal` instead (take payment 11, approvals 14, cancel 15).

### 6.6 The Z paper

Z-report (37b, 37c), the day paper (38b).

| Part | Spec |
|---|---|
| Control row | Tabs as `OptionTiles` on the left; `ExportMenu` and a "Print" secondary button on the right (in `PageHeader` on wide screens) |
| Sheet | One `ZPaper`, centred, max width 880 px, on the grey page |
| Rooms by floor (37c) | A card with the floor × category table (whole pesos, a "No room" column, a "Gross total" footer and a "Deposits held at close" row), then one dense room-chart card per floor with FWD rows in `bad` |
| Preview | For an open shift: the watermark, no Z number, `ExportMenu` and Print disabled |
| Print | The sheet prints without its border and radius, with the signature block kept on the same page as the last section |

### 6.7 Photo pages: sign-in and welcome

Sign-in (1) and welcome (59). No app shell. Light only (`hm-light`). Both are built from `PhotoHero` (section 5.2); the photographs are listed in [ASSETS §1.5](ASSETS.md#15-photographs).

**Sign-in (1)** — a split screen, deliberately unlike the owner's Euphoria sign-in card (section 10).

| Part | Spec |
|---|---|
| Grid | Two columns filling the viewport (`min-height: 100dvh`): photo `minmax(0, 1.3fr)` (about 57%) on the left, `SignInPanel` `minmax(0, 1fr)` on the right. No gap, no card, no outer padding. |
| Photo | `PhotoHero` with one photo chosen by the server's part of the day from `Clock` in Asia/Manila: morning 5:00–11:59 (`signin-morning`), afternoon 12:00–17:59 (`signin-afternoon`), evening 18:00–4:59 (`signin-evening`). Default scrim, drift on. |
| Caption | Bottom left of the photo, 48 px from the left, right and bottom edges, in reading order with the fade-up stagger: eyebrow ("Hotel Dalisay GenSan · Wednesday, 11 November"), serif greeting ("Good morning." · "Good afternoon." · "Good evening."), one sentence ("The AM shift has been open since 7:58 AM."), then four figures in a row. |
| Figures | Above them a 1 px `on-photo-line` rule with 16 px space below; four equal columns separated by 1 px `on-photo-divider` rules, each a number (28 px / 500) over its label: "14 rooms free", "23 in house", "3 due out soon", "2 arriving today". Counts only, never sales, cash or names. On a computer that has not signed in before (no remembered branch), the eyebrow shows the date only, and the sentence and figures are left out. |
| Panel | `SignInPanel`: logo top left, the form block centred vertically, "Forgot your password? Ask your branch admin." at the bottom |
| Under 900 px | One column: the photo becomes a band 240 px high above the panel showing the eyebrow and the greeting only; the figures are hidden; the panel follows with 24 px padding. |
| Focus on load | The username field |

**Welcome (59)** — the public front page.

| Part | Spec |
|---|---|
| Hero | `PhotoHero` with three photos (`lobby`, `signin-afternoon`, `signin-evening`), cross-fading every 7 s, default scrim, `min(100svh, 760px)` high. |
| Nav | On the photo, 24 px from the top, 48 px side padding: white logo (120 × 36 px) left; then, pushed right, the links "Rooms", "How a stay works", "Find us" (anchors on this page) and one outline button on the photo "Staff sign in" (opens 1; "Dashboard" for signed-in staff). |
| Hero copy | Max width 640 px, 48 px from the left, vertically in the upper middle: eyebrow ("General Santos City · Open 24 hours"), serif headline ("Rest well, by the hour or the night."), one sub-line, then a white button "See rooms and rates" (scrolls to Rooms) and an outline button "How a stay works". With more than one branch: a branch `USelect` under the sub-line. |
| Slide indicators | Bottom left, three 18 × 2 px lines, 6 px apart; the current one white, the others `slide-off`. Decorative (`aria-hidden`). |
| Rooms section | White page, 64 px vertical padding, content max width 1100 px. Header row: eyebrow "Rooms" and the serif heading "Three ways to stay" on the left; on the right one `muted` line from the branch's deposit setting ("A ₱200 key deposit is added at check-in and returned when you leave."). Then one `RoomTypeCard` per active room type in three columns, 24 px gap (two columns under 900 px, one under 600 px). |
| How a stay works | Full-width band on the `bg` page colour with a 1 px `line` rule above and below; eyebrow "How a stay works"; three columns: serif step number in `muted` ("01", "02", "03"), step title, one line ("Choose", "Pay at the desk", "Extend or leave"). |
| Footer | White, 24 px padding: small blue logo (56 × 17 px), "General Santos City · Open 24 hours", the photo credit line ("Photos: Unsplash", opening a small credits list — [ASSETS §1.5](ASSETS.md#15-photographs)), and "© 2026 Hotel Dalisay" at the right. 13 px `muted`. |
| Content rules | Prices only, read live from `rates`; no availability, no booking. A branch with `welcome_enabled` off is left out. |

### 6.8 Kiosk portrait (`KioskLayout`, 1080 × 1920)

| Part | Spec |
|---|---|
| Chrome | None: no sidebar, no top bar, no browser UI, no sign-out. White page, 32 px padding. Light only. |
| Welcome (27) | `KioskWelcome` (section 5.2): the lobby photograph full screen under the kiosk scrim (`rgba(20,22,25,.52)`, flat), slow drift, white logo near the top, the serif "Welcome to Hotel Dalisay", one sentence, the house rules in a white panel (radius 10 px), and one white button "Tap to start" (`ink` text) pinned to the bottom with the free-room count under it. Tapping anywhere starts. No gradient. |
| Steps (28–30) | `KioskStepBar` at the top ("1 Room · 2 Your name · 3 Pay at desk"), content in the upper two thirds, `KioskFooter` pinned to the bottom |
| Choices | `KioskTypeCard`s; hours as `OptionTiles size="kiosk"` in 2 columns |
| Hold screen (30) | `KioskHoldCard`, one "Done" button |
| Full-page states | `EmptyState` centred: no rooms (31), offline (31b) |
| Idle | Back to the welcome screen after 60 s without a touch (30 s on the hold screen) |
| Touch | Every target 56 px or taller; primary buttons 72 px |

### 6.9 Phone (`PhoneLayout`, 360 px and wider)

| Part | Spec |
|---|---|
| Header | `PhoneHeader` |
| Content | 12 px padding, one column of `TaskCard`s |
| Cleaning (33) | `CleanTimer`, checklist card (`UCheckbox`), one 56 px `ok` button "Room is ready", and a text button for reporting a problem |
| Navigation | Two destinations only (queue, my cleans) as links in the header; no sidebar, no bottom tab bar |
| Touch | Every target 48 px or taller |

### 6.10 Print and export layouts

Printing is `window.print()` with print styles in `app.css`; no printer drivers ([TRD §6](TRD.md#6-platform-specifics)). In print, the sidebar, top bar, bars, toasts and buttons are hidden, the page background is white, and the light tokens are forced. Every print and export is recorded before it starts.

| Layout | Used by | Page | Type | Content rules |
|---|---|---|---|---|
| 80 mm thermal | Check-in slip (8), receipt (18), shift report (26), the slip form of the Z-report — the `Slip` component | `@page { size: 80mm auto; margin: 0 }`, content 72 mm wide, centred | Geist Mono 11 pt, line height 1.4; about 30 characters per line; weight 600 for the header and totals | Black on white only, no colours, no logo, no images. Header: `slip_header` in capitals, then the slip title and its number, centred. Dashed rules between sections. Label left, amount right on one line. A reprint says "REPRINT". Ends with 3 blank lines for the tear-off. |
| A4 portrait (browser) | Reports 37–43, 37b, 38b | `@page { size: A4; margin: 12mm }` | Geist 10 pt body, 9 pt tables, tabular figures; stay codes in Geist Mono; no serif | Header on the first page: blue Homi logo (30 mm), branch name and address, report title and period, printed date and by whom. Tables keep their header row on every page; rows do not split. Status labels print as plain words without their fill. Card borders print as thin grey rules. Rooms by floor (37c) prints in landscape. |
| A4 PDF (dompdf) | Z-report (37b) and day Z (38b) — `resources/views/reports/zreport.blade.php` | A4 portrait, 12 mm margins | DejaVu Sans (bundled with dompdf, has the ₱ sign) 9.5 pt; numbers right-aligned | The same sections and lines as `ZPaper`, in the same order, from the same builder array. Plain CSS only (tables, borders); no flex, no grid, no web fonts. Logo embedded as PNG when GD is available, otherwise the product name as text ([ASSETS §1.4](ASSETS.md#14-printing-and-export)). |
| CSV | Z-report, day Z, other reports | UTF-8 with BOM, streamed | — | Z-report columns: Section, Line, Count, Amount (pesos with two decimals, no ₱, no separators). |

## 7. Formatting rules

All formatting is done by `formatMoney()` and `formatDateTime()` (composables `useMoney`, `useDateTime`); pages never format by hand. Times are stored in UTC and always shown in `Asia/Manila`.

### 7.1 Money

| Case | Format | Example |
|---|---|---|
| Standard amount | `₱` + thousands separators + two decimals, sans 600, tabular | `₱1,234.00` |
| Negative amount | Minus sign (U+2212) before the peso sign | `−₱50.00` |
| Zero | Written out, never blank | `₱0.00` |
| Table column of amounts | No peso sign, right-aligned, two decimals; the header or the footer carries the ₱ | `650.00`, `−250.00` |
| No value / does not apply | Em dash | `—` |
| Money input | Peso sign, space, amount | `₱ 1,000.00` |
| Short / over | The word plus the amount; short is `bad`. Over a period, two figures, never one netted figure. | `Short by ₱50.00` · "Short ₱180 · Over ₱0" |
| Thermal slip | No peso sign, two decimals, ASCII hyphen for negatives | `650.00`, `-340.00` |
| CSV | No peso sign, no separators, two decimals, ASCII hyphen | `18160.00` |

Whole-peso exceptions — the only places that drop the centavos:

| Place | Format | Example |
|---|---|---|
| Option tiles, product cards, kiosk price tiles, welcome rate cards (59) | `₱` + whole pesos when the centavos are zero | `₱450`, `₱1,000/day` |
| KPI / stat cards on dashboards and Stats (60, 61, 63) and the owner summary (39) | `₱` + whole pesos, rounded half up | `₱9,420`, `−₱180` |
| Chart value labels, legends and axes | Whole pesos; axes may shorten thousands | `₱71,300`, `168,300`, `₱71.3k` |
| Rooms-by-floor table and room chart (37c) | Whole pesos, no ₱, to fit the grid | `14,350` |
| Owner summary branch table (39) | Whole pesos, no ₱ | `268,940` |

Everything else — folios, payments, the Z paper, shift and daily reports, tables on dashboards ("Branches now", the open-shift table), toasts and dialogs — keeps two decimals. Amounts travel as integer centavos and are divided by 100 only inside `formatMoney()`.

### 7.2 Dates and times

| Case | Format | Example |
|---|---|---|
| Time today, in tables and lists | `h:mm AM/PM` | `9:12 AM` |
| Time on another day within a week | Weekday + time | `Wed 9:12 PM`, `Thu 3:12 AM` |
| Time further away | Day, month + time | `10 Nov 11:20 PM` |
| Date | `D MMM YYYY` | `11 Nov 2026` |
| Business date, page titles, Z paper | Weekday, date | `Wed, 11 Nov 2026` |
| Shift label, short date | Weekday day month | `Wed 11 Nov` |
| Date range | En dash, month once | `4–10 Nov 2026`, `1 – 10 Nov 2026` in the range button |
| Noon | `12:00 NN` | `Fri 13 Nov, 12:00 NN` |
| Hour on a chart axis | Short | `8a`, `12n`, `2p`; "9 AM", "9 PM" in text |
| "Updated" line on dashboards | Time only | `Updated 11:44 AM` |
| Slip timestamps | Zero-padded hour | `11 Nov 2026 09:12 AM` |
| Shift hours | Start – end | `8:00 AM – 8:00 PM` |
| Eyebrow line on the sign-in photo | Branch · full weekday, day and month (shown in capitals by the eyebrow style) | `Hotel Dalisay GenSan · Wednesday, 11 November` |

No seconds, no 24-hour times, no numeric-only dates (`11/11/2026` is never shown), no relative words other than "Today", "Yesterday" and "now".

### 7.3 Durations and countdowns

| Case | Format | Example |
|---|---|---|
| Duration in text and status words | Hours and minutes with spaces | `9 h 28 m`, `48 m`, `2 h 10 m` |
| Minutes left or over in a list (62) | Number + "min" | `9 min`, `42 min over` |
| Time left on the room board | Compact + "left" | `3h 10m left`, `22m left` |
| Overdue on the room board | Compact + "over" | `42m over` |
| Hold countdown | `m:ss`, sans tabular | `6:42`, `9:58` |
| Cleaning timer | `mm:ss`, sans tabular | `06:12` |
| Minutes in reports | Number + "min" | `17 min` |
| Stay length | Hours, nights or days | `12 hrs`, `2 nights`, `3 days` |

### 7.4 Identifiers and other values

| Value | Format | Example |
|---|---|---|
| Room number | Sans 600, tabular, exactly as stored | `205`, `3A` |
| Stay code | Geist Mono, prefix-year-number (6 digits) | `DGS-26-004126` |
| Shift Z number | Sans 600, tabular, `Z-` + `shift_sessions.z_no` padded to 4 digits (wider when larger); mono only on a slip | `Z-0629` |
| Day Z number | Sans 600, tabular, `D-` + `day_closings.day_z_no` padded to 4 digits | `D-0314` |
| POS order number | Sans, tabular, `P-` + the per-branch number padded to 6 digits; mono only on a slip | `P-008821` |
| Document range on the Z paper | First "to" last, the prefix once | `Stays DGS-26-004112 to 004138` |
| Room with type | Number · type | `210 · Deluxe` |
| Floor | Label from `floors.label` | `2F` |
| Guest name | As typed on the folio; initial + surname on the room board and dense tables | `Rico Manalastas`, `R. Manalastas` |
| Staff name | Full name in headings and on signature lines; first names + initial in the top bar, tables and slips; two initials in the avatar | `Jessa Mae Dela Cruz`, `Jessa Mae D.`, `JM` |
| Mobile number | 11 digits grouped 4-3-4; shown only on the folio and reservation screens | `0917 555 0142` |
| Quantity | `× n` after the item | `Cup noodles × 2` |
| Count of a limit | `n of m`; `n / m` in a narrow table cell | `32 of 74`, `23 / 46` |
| Two counts on one card | Slash with spaces | `14 / 10` (in / out) |
| Percentage | Whole number | `71%` |
| Change against the previous period | Arrow, whole percent, and what it compares with; left out when there is no previous data | `▲ 8% vs yesterday by this time`, `▲ 5% vs 1–10 Oct` |
| Transfer | Arrow between rooms | `108 → 212` |
| List separator | Middle dot with spaces | `Deluxe · 2F` |

## 8. Accessibility

Target: WCAG 2.1 AA for text and controls.

### 8.1 Contrast

Ratios computed with the WCAG 2.1 relative-luminance formula for the light token pairs in use. 4.5 : 1 is the bar for normal text, 3 : 1 for large text and for graphics that carry meaning.

**Soft status labels** (text on its own fill — every label must reach 4.5 : 1, and does):

| Label | Text | Fill | Ratio | Same text on white `card` |
|---|---|---|---|---|
| ok (Paid, Vacant, Open) | `#2f6b47` | `#e8f1eb` | 5.5 : 1 | 6.3 : 1 |
| warn (Unpaid, Due out) | `#8a5a12` | `#f6eedd` | 5.1 : 1 | 5.9 : 1 |
| bad (Overdue, Short) | `#9b3328` | `#f7e8e5` | 6.1 : 1 | 7.3 : 1 |
| info (Occupied) | `#3b5373` | `#ecf0f5` | 6.9 : 1 | 7.9 : 1 |
| violet (Cleaning) | `#5a4f80` | `#efedf5` | 6.3 : 1 | 7.3 : 1 |
| clay (To clean) | `#7a5236` | `#f3ece5` | 5.8 : 1 | 6.8 : 1 |
| neutral (Closed, Blocked) | `#5f646b` | `#eeeeeb` | 5.1 : 1 | 6.0 : 1 |
| primary (Held, branch) | `#0b5f96` | `#eaf2f8` | 6.0 : 1 | 6.8 : 1 |

The same pairs are the inline alert notes (`UAlert` soft), so alerts pass at the same ratios. The lowest pair is warn at 5.1 : 1; a new tone added later must reach 4.5 : 1 on its own fill before it is used.

**Text and controls**

| Text | On | Ratio | Result |
|---|---|---|---|
| `ink` `#1d2329` | `card` / `bg` / `nav-active` | 15.9 / 14.4 / 13.3 : 1 | Pass |
| `text` `#3d434a` | `card` / `bg` / `sidebar` | 10.0 / 9.1 / 9.6 : 1 | Pass |
| `text` | `step` `#eeedea` | 8.5 : 1 | Pass — so the "next" step pill uses `text` |
| `muted` `#6b7178` | `card` / `sidebar` / `hover` / `blocked` | 4.9 / 4.7 / 4.6 / 4.6 : 1 | Pass (time column, Blocked rows, helper text) |
| `muted` | `bg` `#f5f4f1` | 4.48 : 1 | Just below 4.5 : 1 — see the rule below |
| `muted` | `step` `#eeedea` | 4.2 : 1 | Below — not used there |
| Board time in `warn` / `bad` (600) | `card` | 5.9 / 7.3 : 1 | Pass |
| white | `primary` `#0077c2` | 4.75 : 1 | Pass (primary button, current step) |
| `primary` | `card` | 4.75 : 1 | Pass (links) |
| `primary` | `primary-50` | 4.2 : 1 | Below — so text on `primary-50` is `primary-ink` (6.0 : 1), never `primary` |
| `primary-ink` `#0b5f96` | `select` `#eef4f9` | 6.1 : 1 | Pass (selected option tile and segment) |
| white | `bad` `#9b3328` | 7.3 : 1 | Pass (destructive confirm) |
| white | `ok` `#2f6b47` | 6.3 : 1 | Pass ("Room is ready" — now passes at any size) |
| `ink` | white (secondary and on-photo buttons) | 15.9 : 1 | Pass |
| white | `brand` `#009ef5` | 2.9 : 1 | **Fail** — `brand` is never a background for text or a button fill |

**Graphics** (WCAG 1.4.11, 3 : 1)

| Graphic | On | Ratio | Result |
|---|---|---|---|
| Shares bar parts: `ok`, `info`, `clay`, `violet`, `primary`, `grey` | `card` | 6.3, 7.9, 6.8, 7.3, 4.75, 6.0 : 1 | Pass |
| Peak bar `primary` | `card` | 4.75 : 1 | Pass |
| Other bars `bar-soft` `#d6e2eb` | `card` | 1.3 : 1 | Decorative by design; every bar's value is in the tooltip and the text equivalent, and the peak is named in the card header |
| Input and secondary-button border `line-strong` `#d9d6cf` | `card` | 1.45 : 1 | Below 3 : 1, as in most calm UI kits; accepted because every input has a visible label above it and every button has a text label. The focused border (`primary`, 4.75 : 1) passes. |

**Text on photographs.** White text on the photos relies on the flat scrim. With the 40% scrim, white text reaches 4.5 : 1 wherever the photo under it is no lighter than about `#b5b5b5` (mid-light grey), and 3 : 1 — enough for the 60–64 px serif headings — wherever it is no lighter than `#e8e8e8`. With the kiosk's 52% scrim the limits are `#dfdfdf` (4.5 : 1) and pure white (3 : 1). The chosen photos are darker than these limits where the text sits (sign-in: bottom left; welcome: left half; kiosk: middle and top). The fallback block `#5b554d` under the scrim gives 10.8 : 1 (12.4 : 1 on the kiosk). Phase 1 checks each photo with a contrast sample of the text area at 1280 × 720 and 1080 × 1920; a replacement photo that is brighter under the text gets a different crop (`background-position`) or the kiosk scrim value, never a gradient.

Rule for `muted`: it is for text **on a card or panel**. A note placed directly on the grey page (the line under a table, "Counts only…", "Updated 11:44 AM") uses `text`, or sits inside the card it describes.

Dark mode pairs: `ink` on `card` 14.8 : 1, `text` 11.6 : 1, `muted` 6.4 : 1 (6.9 : 1 on the page, 5.4 : 1 on `nav-active` and `step`); `primary` on `card` 6.6 : 1; dark text on the `primary` button 7.2 : 1; soft labels on their dark fills 6.6 : 1 (bad) to 7.9 : 1 (primary), all above 4.5 : 1.

### 8.2 Focus

| Element | Focus style |
|---|---|
| Inputs, selects, textareas | 1 px `primary` border + 3 px `focus` halo `#e2edf6` (error: `bad` border + 3 px `bad-50` halo) |
| Buttons, links, tiles, option tiles, segments, sidebar links, linked stat cards and table rows | 2 px `primary` ring with 2 px offset, shown for keyboard focus (`:focus-visible`) |
| On a photograph (welcome nav and buttons, kiosk "Tap to start") | 2 px white ring with 2 px offset |
| On the sign-in panel | As inputs and buttons above (the panel is white) |
| Modals and slide-overs | Focus moves in on open, is trapped, and returns to the opener on close (Nuxt UI / Reka behaviour; do not disable) |
| Charts | Each bar, segment and cell is focusable in reading order and shows `ChartTip` |

Focus is never removed without a replacement.

### 8.3 Target sizes

| Device | Minimum target | Applies to |
|---|---|---|
| Desktop | 32 × 32 px; the main action of a form 40 px | Buttons (`md` and up), inputs (36 px), option tiles, sidebar links (36 px), segments, PIN keys (56 px). Row buttons are 28 px high with at least 8 px between them. |
| Kiosk | 56 × 56 px; primary buttons 72 px high | Everything touchable; 16 px or more between targets |
| Phone | 48 × 48 px; main button 56 px high | Everything touchable, including the app shell's drawer links under 768 px |

### 8.4 Keyboard on the front desk

| Key | Does |
|---|---|
| Tab / Shift+Tab | Moves through fields in reading order: left card, then right card, then the main button |
| Enter | Submits the form the focus is in (the page's one primary action). In a textarea it adds a line. |
| Esc | Closes the top-most modal, slide-over or menu; never submits; asks first if there is unsaved input |
| Arrow keys | Move within `OptionTiles`, `PeriodSegments`, menus and the calendar; move between room tiles on the board |
| Space | Selects the focused option tile or tile; toggles a checkbox or switch |
| Digits, Backspace | Type into `PinPad` when a PIN dialog is open |

On opening a form, focus is on the first field the user must fill; on the payment modal it is on "Cash received". A destructive dialog never has its destructive button focused by default.

### 8.5 Other rules

| Topic | Rule |
|---|---|
| Colour | Never the only signal: every status has a word; errors have text and an icon; a chart has a legend or value column |
| Labels | Every input has a visible `UFormField` label; placeholders are examples, not labels |
| Errors | Field errors are linked by `UFormField` (`aria-describedby`); a blocking `UAlert` uses `role="alert"`; toasts are announced politely and never hold the only copy of an error |
| Icons | Decorative icons are `aria-hidden`; icon-only buttons (close, ⌫, sidebar toggle, pager arrows) have an `aria-label` and a tooltip |
| Tables | Real `<table>` markup with `<th scope>`; numbers are text, not images |
| Navigation | The sidebar is a `<nav>` with a label; the active link has `aria-current="page"`; the top bar title is the page's only `<h1>` |
| Dialogs | `role="dialog"`, `aria-modal`, labelled by their title |
| Live updates | Board and dashboard refreshes do not move focus or announce every change; countdowns are not live regions; "Could not refresh" is `role="status"` |
| Photographs | Decorative: `aria-hidden` layers or empty `alt`; nothing is said only by a photo. The greeting (1), the headline (59) and the kiosk title (27) are each the page's `<h1>`. |
| Moving content | The hero cross-fade and the photo drift are decorative and stop under `prefers-reduced-motion: reduce`; the welcome hero also pauses on hover and while focus is inside it. Nothing flashes. |
| Zoom | Pages work at 200% browser zoom without horizontal scrolling of forms (tables may scroll inside their card) |
| Language | `lang="en"`; plain words, no codes shown to staff except stay codes, Z numbers, order numbers and request ids |

## 9. Motion and feel checklist

Run through this for every screen before calling it done, next to its mockup frame.

- [ ] The page is white cards with a 1 px `line` border on the warm grey page: no card shadow, no card inside a card, no table straight on the grey.
- [ ] Nothing on the page uses a gradient, a blur or a glass effect (the skeleton shimmer is the only exception). Colour appears only on the primary button, links, focus, a selected choice and statuses.
- [ ] The screen has exactly one solid primary button, and its label is a verb that says what will be saved. Every other button is white with a 1 px border and no shadow, or text.
- [ ] Every status is a `StatusLabel` — a small soft label in sentence case, no dot — with the tone from section 2.3. No `UBadge` used as a status, no bare coloured text as a status.
- [ ] The room board is a table: one row per room, grouped by floor; rows are never tinted; only the status label has colour; the time is ochre when due out and brick when overdue
- [ ] Every figure names its period; a change figure names what it compares with and is absent when there is no previous data; short and over are shown separately.
- [ ] Numbers, room numbers, timers and KPI figures are Geist, weight 600, tabular, formatted by `formatMoney()`; Geist Mono appears only on slips, printed receipts and stay codes; Newsreader appears only on the display headings of 1, 59 and 27. Centavos are dropped only where section 7.1 allows.
- [ ] The first load shows skeletons shaped like the content; each deferred card fills in without changing size; a 15 s or 30 s refresh changes values in place with no flash, no reorder and no lost scroll position.
- [ ] The primary button shows its spinner within 100 ms of a click, cannot be clicked twice, and keeps its width.
- [ ] After a save the user sees the result: a toast with the amount, the slip, or the updated page — never a blank wait. A blocking error is inline or a dialog, not only a toast.
- [ ] An empty list shows an `EmptyState` with one way forward; an empty chart says so; a disabled option says why.
- [ ] Destructive actions open a `ConfirmDialog` whose button is worded as the action, that names the room and the money affected, asks for a reason, and focuses the safe button.
- [ ] Modals and slide-overs open in 200 ms or less, trap focus, close on Esc, and return focus to where the user was.
- [ ] Enter submits and Tab order follows reading order; the whole flow works without a mouse on the front desk.
- [ ] The sidebar collapses to the rail and remembers it; under 768 px it is a drawer and the page has no horizontal scroll.
- [ ] The page looks right in Light and in Dark (except the light-only surfaces), using tokens only.
- [ ] Touch targets meet 32 px desktop, 56 px kiosk, 48 px phone.
- [ ] Inside the app nothing moves on its own except skeletons, spinners and timers. On 1, 27 and 59 the photo drifts, the hero cross-fades every 7 s and the text fades up once, with the timings of section 4.5.
- [ ] With "reduce motion" on, nothing slides, drifts, fades or pulses, the first photo is shown still, and the screen still works.
- [ ] On 1, 27 and 59 every photo is a real photograph from [ASSETS §1.5](ASSETS.md#15-photographs) under the flat scrim; white text stays readable over the photo and over the `photo-fallback` block when the photo is missing.
- [ ] With the server stopped, the `ConnectionBar` appears, Save buttons are disabled, dashboards say "Could not refresh. Retry", and everything recovers by itself when the server returns.
- [ ] No hex colour, palette class, `dark:` variant, `shadow-*` on a card, gradient class, pixel font size or one-off button style appears in the page; it is built only from section 5.
- [ ] A front desk page shows no sales, collected or expected-cash figure while the shift is open.
- [ ] Printed and exported output (if any) matches section 6.10: no sidebar, no buttons, correct width, the same figures as the screen.
- [ ] The page's root element has its `<!-- Mockup N -->` comment and looks like frame N at 1280 × 720 (or 1080 × 1920, or 360 px wide).

## 10. What we deliberately did not copy from Euphoria

Euphoria is the reference for the level of finish and for conventions (the token layer over Nuxt UI, the one button system, the plain table style, KPI cards, the page header that teleports into the top bar, the promise-based confirm, the chart kit). These parts stay behind:

| Not copied | Why | Homi instead |
|---|---|---|
| The magenta (fuchsia) palette and Euphoria's secondary colour | It is Euphoria's brand | Warm neutral greys, the Homi primary `#0077c2` for actions and the logo blue `#009ef5` for the logo only; muted status hues (occupied rooms in slate blue) |
| The park scene: rides, park wording, its fonts for public pages | Homi is a hotel | Real photographs of rooms and a lobby under a flat scrim; Geist everywhere and Newsreader for the few public headings |
| The ticket-style sign-in card: a coloured stub, a chip, a dashed tear line with notches, a card floating over a scene | It is Euphoria's own sign-in, and the owner asked for Homi not to look like it | A split screen: a full-height photograph with the greeting and four figures on the left, a plain white panel with the form on the right — no card, no stub, no tear line (section 6.7) |
| Whole pesos in some money columns and two decimals in others, decided page by page | Staff could not tell whether centavos were dropped or absent | Two decimals everywhere, with the short fixed list of whole-peso places in section 7.1 |
| Two pagers on one list (above and below the table) | Duplicate controls drift apart | One `Pager`, at the bottom of the `ListCard` |
| Charts drawn twice — div bars on the dashboard and SVG charts on the stats page | Two looks, two sets of bugs | One chart kit in `Components/Charts/` used by every page |
| Mixed status styles — soft pills on some pages, bare coloured dots on others | The same status looked different from page to page | One `StatusLabel` everywhere; `UBadge` only for counts |
| The `[.admin-soft_&]` scoping of every override | Euphoria has a second, counter-style look to protect; Homi has one look | Overrides apply app-wide; kiosk and phone sizes are size classes, not a second theme |
| Note-denomination buttons on the money field | Removed in Euphoria too; a drawer is counted in the hand | `MoneyInput` is a typed field; denominations appear only on the blind count (23) |
| Remit-everything, one close per day | A hotel runs 24 hours and holds guest deposits in the drawer ([TRD §4.12](TRD.md#412-shift-safeguards)) | One shared drawer per shift, a count at every shift change, handover by PIN — and the screens that go with it (22–25) |
