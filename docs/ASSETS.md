# Assets — Homi

| | |
|---|---|
| Version | 1.2 · 11 November 2026 |
| Status | Approved with mockup v4 |
| Scope | Every file that is not code: fonts, icons, logo, favicon, photographs, print and PDF headers, backgrounds |
| Related | [DESIGN_SYSTEM](DESIGN_SYSTEM.md) · [SYSTEM_DESIGN](SYSTEM_DESIGN.md) · [TRD](TRD.md) · [mockup](mockup/hotel-mockup.html) |

The app needs few assets. Apart from the Homi logo and six real photographs on the three front-door pages (sign-in 1, welcome 59, kiosk welcome 27), everything visual is a font, a Lucide icon, CSS, or text from the database. There are no drawn illustrations, no AI-generated images, no sounds and no video. The product is **Homi**, with its existing logo and brand blue `#009ef5` (mockup decision D7). The component library is Nuxt UI v4; it brings no image assets of its own.

## 1. Asset list

### 1.1 Fonts

Two families from one designer, both under the SIL Open Font License 1.1, self-hosted. The mockup loads them from Google Fonts for review only; the app never calls an outside font server. The woff2 files are copied once from the free `@fontsource/geist` (Geist) and `@fontsource/geist-mono` npm packages into `resources/fonts/` and committed, so the packages do not stay in `package.json`. Geist comes from `@fontsource/geist`, the Google Fonts build, because the older `@fontsource/geist-sans` (v1.0.1) has no latin-ext subset and no peso sign (checked on 16 Nov 2026 in Phase 11); its files keep the `geist-sans-` names below. `@font-face` rules with `font-display: swap` and a `unicode-range` per subset live in `resources/css/app.css`. Mockup v6 (16 Nov 2026) replaced IBM Plex Sans, IBM Plex Mono and Newsreader: their files are deleted from `resources/fonts/` together with their licence texts. The public pages now use Geist too, set larger and tighter, so the whole product reads as one hand.

| Path in repo | What it is | Status | Fallback until supplied | Source and licence |
|---|---|---|---|---|
| `resources/fonts/geist-sans-latin-400-normal.woff2` | Geist Regular — body text, inputs, table cells | Shipped (Phase 11) | `system-ui, sans-serif` | `@fontsource/geist` 5.x · SIL OFL 1.1 |
| `resources/fonts/geist-sans-latin-500-normal.woff2` | Geist Medium — buttons, field labels, status labels, table headers, sidebar links | Shipped (Phase 11) | as above | as above |
| `resources/fonts/geist-sans-latin-600-normal.woff2` | Geist SemiBold — page titles, numbers, KPI figures, room numbers, bold text, public headlines | Shipped (Phase 11) | as above | as above |
| `resources/fonts/geist-sans-latin-ext-400-normal.woff2`, `-500-`, `-600-` (three files) | The latin-ext subset of the same three weights. It carries the peso sign ₱ (U+20B1). | Shipped (Phase 11) | as above | as above |
| `resources/fonts/geist-mono-latin-500-normal.woff2` | Geist Mono Medium — slips, printed receipts, stay codes | Shipped (Phase 11) | `ui-monospace, monospace` | `@fontsource/geist-mono` 5.x · SIL OFL 1.1 |
| `resources/fonts/geist-mono-latin-600-normal.woff2` | Geist Mono SemiBold — slip header and totals, the stay code on the kiosk hold screen | Shipped (Phase 11) | as above | as above |
| `resources/fonts/OFL-Geist.txt` | Licence text for Geist and Geist Mono | Shipped (Phase 11) | — | Copied from both packages |

Notes:

- Numbers on screen — money, counts, room numbers, timers, KPI figures — are Geist with tabular figures, not the mono ([DESIGN_SYSTEM §3](DESIGN_SYSTEM.md#3-typography)). Geist Mono is only for slips, printed receipts and stay codes.
- Peso sign check. Geist has ₱ in its latin-ext subset (checked in the mockup on 16 Nov 2026: the glyph renders in Geist, not the fallback), which is why the three latin-ext files are shipped. The component gallery (`/dev/components`) checks that `₱1,234.00` renders in Geist at 400, 500 and 600 in Chrome and Edge. Geist Mono ships latin only: slips print amounts without ₱ ([DESIGN_SYSTEM §7.1](DESIGN_SYSTEM.md#71-money)).
- PDF exports do not use these files: dompdf cannot read woff2. The PDF view uses DejaVu Sans, which ships inside dompdf and has the peso sign (section 1.4).

### 1.2 Icons

One icon set: Lucide, through Nuxt UI's Iconify integration. An icon is written as a class name, `i-lucide-<name>`, on `UIcon` or on the `icon` prop of a Nuxt UI component. The icon data comes from the local dev dependency `@iconify-json/lucide` and is bundled at build time; nothing is fetched from the Iconify API or any CDN at runtime. No separate Lucide package is installed, no icon files are stored in the repo and no icon font is used. Sizes are in [DESIGN_SYSTEM §5.1](DESIGN_SYSTEM.md#51-nuxt-ui-components-we-use).

| Icon (`i-lucide-…`) | Mockup symbol | Used for |
|---|---|---|
| `bed` | `i-bed` | Room board and Occupancy menu items, Rooms (admin), empty board (4c), kiosk no rooms (31), and the logo fallback when the image fails to load |
| `banknote` | `i-cash` | Shift cash, Shift reports and Rates menu items, blind count note (23) |
| `shopping-cart` | `i-cart` | POS menu item |
| `clock` | `i-clock` | Today menu item, info alerts about time (2, 7, 12) |
| `user` | `i-user` | Users and Owner accounts menu items |
| `check` | `i-check` | Success alerts and toasts, check-out done (18), "All good." |
| `x` | `i-x` | Close a slide-over or modal, Sign out |
| `triangle-alert` | `i-warn` | Warning and error alerts, Attention menu item, overdue bar (54) |
| `wifi-off` | `i-wifi` | Connection bar (50), kiosk offline (31b) |
| `lock` | `i-lock` | "Needs approval" buttons, approval dialog (14), no permission (51), Audit trail menu item, PIN entry locked |
| `brush` | `i-broom` | Housekeeping menu item, empty queue (35) |
| `chart-column` | `i-chart` | Dashboard, Stats, Daily sales and Owner summary menu items |
| `settings` | `i-gear` | Settings menu item |
| `key` | `i-key` | Room key row at check-out (16) |
| `arrow-left-right` | `i-swap` | Transfer |
| `plus` | `i-plus` | Add buttons, walk-in check-in |
| `printer` | `i-print` | Print and Print again, the Print item of the export menu |
| `package` | `i-box` | Stock, Inventory and Products menu items |
| `list` | `i-list` | Reservations, Branches, Reports and Z-report menu items, generic empty state |
| `search` | — | Search fields (board, list cards) |
| `chevron-down` | "▾" | Export menu, selects |
| `chevron-left`, `chevron-right` | "‹ ›" | Pager, date-range button |
| `file-text` | — | PDF item of the export menu |
| `sheet` | — | CSV item of the export menu |
| `loader-circle` | spinner | Loading state of every button |
| `refresh-cw` | — | "Could not refresh. Retry" on dashboards |
| `panel-left` | — | Collapse the sidebar to its icon rail |
| `menu` | — | Open the sidebar drawer under 768 px |
| `sun`, `moon`, `monitor` | — | Appearance: Light, Dark, System in the user menu |

| Path in repo | What it is | Status | Fallback until supplied | Source and licence |
|---|---|---|---|---|
| `node_modules/@iconify-json/lucide` (not committed; a dev dependency) | The Lucide icon data. Only the icons named above end up in the built CSS. | Have (bundled by Nuxt UI `icon.clientBundle.scan`, nuxt-ui.config.ts) | — | Lucide · ISC |

A new icon may be used only from Lucide and is added to this table first. Lucide renames icons from time to time and keeps the old names as aliases; if the installed set has renamed one of the names above, use the current name and keep the row here unchanged in meaning. The up and down marks on a stat card ("▲ 8%") are text characters, not icons.

### 1.3 Logo, favicon and app icons

| Path in repo | What it is | Status | Fallback until supplied | Source and licence |
|---|---|---|---|---|
| `resources/images/brand/homi-logo.png` | Homi logo for light backgrounds: blue wordmark "HOMI" with a house in the H, transparent background. Master file, about 2048 × 619 px. Used in the sidebar, on the sign-in panel, in the welcome footer, on the Z paper and on A4 report headers. | Have | If the image fails to load: the `i-lucide-bed` icon in white on a `primary` rounded square (radius 8 px), followed by the text "Homi" | The owner's own artwork, copied from the old project · owned by the client |
| `resources/images/brand/homi-logo-white.png` | White version for photographs and dark backgrounds: the welcome hero (59), the kiosk welcome (27), the sidebar in dark mode. Master file, same size. | Have | The same fallback with the colours inverted (`primary` icon on a white square) and white text | as above |
| `resources/images/brand/homi-logo-400.png` and `homi-logo-800.png` | Web exports of the blue logo: 400 px wide (about 121 px tall) and its 2x, served with `srcset` | Have (phase 1, 400 × 121 and 800 × 242 px) | The master file, scaled by the browser | Exported from the master |
| `resources/images/brand/homi-logo-white-400.png` and `homi-logo-white-800.png` | Web exports of the white logo, same sizes | Have (phase 1, 400 × 121 and 800 × 242 px) | The white master file | Exported from the master |
| — (config) | Product name in page titles, the browser tab and the fallback text | Have | `config('hotel.name')`, value `Homi` | Own text |
| `public/favicon.svg` | Browser tab icon and the mark shown in the collapsed sidebar rail: the "H with house" part of the logo, white on a blue rounded square, 32 × 32 viewBox | Have (phase 1: the H-with-house mark redrawn by hand as a 32 × 32 SVG on `#0077c2`) | A `primary` (`#0077c2`) rounded square with a bold white "H", written by hand as a short SVG | Derived from the owner's logo; the fallback is our own shape |
| `public/favicon.ico` | 32 × 32 fallback for browsers that ignore SVG favicons | Have (phase 1, 32 × 32 PNG inside an ICO) | `favicon.svg` | as above |
| `public/apple-touch-icon.png` | 180 × 180 icon used when a housekeeper adds the page to a phone home screen; blue fills the whole square | Have (phase 1, 180 × 180) | The phone uses a page screenshot | as above |

Logo display sizes. The logo's ratio is about 3.3 : 1. Never stretch it, recolour it, put the blue logo on a photograph or a blue background, or put any logo on a busy part of a photo.

| Place | File | Displayed size |
|---|---|---|
| Sidebar, top left | blue (white in dark mode) | 112 × 34 px; the favicon mark at 32 × 32 px in the collapsed rail |
| Sign-in panel (1), top left of the white panel | blue | 112 × 34 px |
| Welcome page (59), hero nav, top left | white | 120 × 36 px |
| Welcome page (59), footer | blue | 56 × 17 px |
| Kiosk welcome (27) | white | 300 × 91 px on the 1080 px wide screen |
| Z paper header on screen (37b, 38b) | blue | 112 × 34 px |
| A4 report header, printed and PDF (37–43, 37b, 38b) | blue | 30 mm wide |
| 80 mm slips (8, 18, 26) | none | Text header only |

There is no web app manifest, no service worker and no install prompt: the app is a normal web page on every device ([TRD §6](TRD.md#6-platform-specifics)).

### 1.4 Printing and export

| Path in repo | What it is | Status | Fallback until supplied | Source and licence |
|---|---|---|---|---|
| — (database) | Slip header on the check-in slip (8), receipt (18) and shift report (26) | Have | Text only: `branches.slip_header` in capitals (seed: "HOTEL DALISAY GENSAN"), then the slip title. No image, no logo on thermal paper. | Typed by the owner or admin (46, 56) |
| — (database) | Address line on slips, A4 report headers and the Z paper | Have | `branches.address` | Typed by the owner or admin |
| — (database) | TIN and registered name on the Z paper, only for a VAT-registered branch | Have | Left out when `branches.vat_enabled` is off | `branches.tin`, `branches.registered_name` (46) |
| — (CSS) | 80 mm and A4 print layouts | Have (built with the `Slip` and `ZPaper` components and print styles in `resources/css/app.css`) | — | Own code |
| `resources/views/reports/zreport.blade.php` | The PDF twin of the Z paper, rendered by dompdf from the same builder array as the screen | Not yet (roadmap phase for reports) | — | Own code |
| `resources/images/brand/homi-logo-400.png` (read from disk by the Blade view) | Logo in the PDF header | Have (phase 1, 400 × 121 and 800 × 242 px) | **The product name as bold text** (`config('hotel.name')`) | as section 1.3 |
| — (inside `dompdf/dompdf`) | DejaVu Sans, the PDF text font | Have with the dependency | — | DejaVu Fonts licence (free, Bitstream Vera derivative) |

PDF logo note. dompdf embeds a PNG only when the PHP **GD** extension is loaded (it needs GD to handle the PNG's transparency). The Blade view checks `extension_loaded('gd')` and that the logo file exists; when either is false it prints the product name as text in the logo's place, so a PDF never fails or shows a broken image on a shared host without GD. The view reads the file from the local path (not a URL), and dompdf's remote loading stays off. START_HERE lists GD among the server's PHP extensions. Printed output never contains a photograph.

### 1.5 Photographs

Real photographs on three screens: the left half of the sign-in (1), the hero and room-type cards of the welcome page (59), and the kiosk welcome (27). They are never drawn illustrations, AI-generated images or CGI renders. All six were picked on Unsplash; each is a real photograph (the photo page shows the camera data), chosen over CGI renders that appeared in the same searches. The drawn building and time-of-day sky of the earlier version are gone.

**Status: downloaded on 11 November 2026** into `public/images/photos/` (11 files, 3.0 MB in total) with a `CREDITS.md` beside them. The mockup now reads these local files; production never hotlinks ([TRD](TRD.md), photographs rule).

| Path in repo | Unsplash image id | Photo page | Photographer | Used on | Status |
|---|---|---|---|---|---|
| `public/images/photos/signin-morning.jpg` | `photo-1759328257280-28bdb3050be3` | https://unsplash.com/photos/NIbUk7ATSuw | Hiram Huang | Sign-in (1), morning (5:00–11:59) | Have |
| `public/images/photos/signin-afternoon.jpg` | `photo-1765547090903-348b711f0eee` | https://unsplash.com/photos/CMHgRFDANH8 | Marcel Strauß | Sign-in (1), afternoon (12:00–17:59); welcome hero slide 2 | Have |
| `public/images/photos/signin-evening.jpg` | `photo-1618773928121-c32242e63f39` | https://unsplash.com/photos/Yrxr3bsPdS0 | Vojtech Bruzek | Sign-in (1), evening (18:00–4:59); welcome hero slide 3; the Deluxe card on 59 | Have |
| `public/images/photos/lobby.jpg` | `photo-1621293954908-907159247fc8` | https://unsplash.com/photos/zSG-kd-L6vw | Frames For Your Heart | Welcome hero slide 1; kiosk welcome (27) | Have |
| `public/images/photos/room-standard.jpg` | `photo-1631049307264-da0ec9d70304` | https://unsplash.com/photos/oxeCZrodz78 | Point3D Commercial Imaging Ltd. | The Standard card on 59 | Have |
| `public/images/photos/room-suite.jpg` | `photo-1611892440504-42a792e24d32` | https://unsplash.com/photos/T5pL6ciEn-I | visualsofdana | The Suite card on 59 | Have |

| Topic | Rule |
|---|---|
| Export sizes | Hero and sign-in photos (`signin-*`, `lobby`): 2000 px wide (the afternoon photo 1920 px), JPEG quality 75 (afternoon 40, morning 65, to stay small; actual sizes 349–602 KB), plus a 1200 px version named with `-1200` (`lobby-1200.jpg`), served together with `srcset`. Room-type cards: 1200 px wide, 200 KB or less. Colour profile sRGB; camera data (EXIF) stripped. |
| Room-type photos | One photo per room type. In version 1 the welcome page takes it from `public/images/photos/room-{slug}.jpg`, where the slug is the room type's name in lower case with dashes (`room-standard.jpg`, `room-deluxe.jpg`, `room-suite.jpg`). `room-deluxe.jpg` is the 1200 px export of the evening photo, so it can be replaced on its own later. A room type without a file shows the fallback block. Uploading a room-type photo from the admin screens is planned for later and is not in version 1. |
| Fallback | Every photo sits on a flat `#5b554d` colour block (`--hm-photo-fallback`) under the same scrim. While a photo loads, or if it is missing or fails, the block shows and white text stays readable (10.8 : 1 with the 40% scrim). No broken-image icon ever shows. |
| Scrim | A flat dark layer over every photo: `rgba(22,24,27,.40)` on 1 and 59, `rgba(20,22,25,.52)` on the kiosk. Never a gradient ([DESIGN_SYSTEM §2.1](DESIGN_SYSTEM.md#21-the-homi-token-layer)). |
| Alt text | Decorative: empty `alt` or a CSS background with `aria-hidden`. |
| Hotel's own photos | When the hotel supplies its own photos, each replaces one of these one for one under the same file name and size; the Unsplash credit for that photo is removed (section 2). |
| Not allowed | Photos with identifiable guests or staff, photos of another named hotel, AI-generated images, CGI renders, drawn illustrations. |

Attribution. The Unsplash License does not require credit, but credit is given as a courtesy. The welcome page footer (59) shows "Photos: Unsplash"; it opens a small credits list in a `UPopover`, one line per photo in use: "Photo by Hiram Huang on Unsplash", each linking to the photo page above. The list is built from one config array (`config/hotel.php` → `photo_credits`, keyed by file name), so a replaced photo drops out of the list by removing its entry. The sign-in and the kiosk carry no credit line.

### 1.6 Backgrounds, QR and empty states

| Path in repo | What it is | Status | Fallback until supplied | Source and licence |
|---|---|---|---|---|
| — (CSS) | Page background of the app | Have | The `bg` token, `#f5f4f1` (dark: `#161513`) | Own CSS |
| — (CSS) | Kiosk welcome background (27) | Have | The lobby photograph under the kiosk scrim (section 1.5); while it loads or if it fails, the flat `#5b554d` block under the same scrim. No gradient. | Photo: Unsplash License; CSS: own |
| — (generated) | QR code on the kiosk hold screen (30), encoding the stay code, e.g. `DGS-26-004127` | Have | Drawn in the browser by the `qrcode` npm package as black on white; the stay code is always printed in mono under it, so a QR that fails to draw does not block the guest | `qrcode` · MIT |
| — (icons) | Empty-state pictures (4c, 31, 31b, 35, 51, empty lists, empty charts) | Have | A single Lucide icon at 40 px desktop / 96 px kiosk in the empty-icon grey; no illustrations | Lucide · ISC |
| — (component) | Loading placeholders (4b, every dashboard card) | Have | Nuxt UI `USkeleton` | Nuxt UI · MIT |
| — (SVG drawn in code) | Charts on the dashboards and Stats (60–63, 39) | Have | Our own components in `Components/Charts`; no chart library, no chart images | Own code |
| — (database) | Kiosk house rules (27) | Have | `branches.house_rules` text | Typed by the admin (46) |

### 1.7 Not used

| Kind | Decision |
|---|---|
| Photos inside the app | None. Photographs appear only on 1, 59 and 27 (section 1.5). Room types inside the app are described by `room_types.description` text. |
| Drawn illustrations, scene art, CGI renders | None. The drawn building and sky of the earlier version are removed. |
| Staff profile pictures | None. Avatars are initials on a tint. |
| Sounds | None. No beeps on payment, hold or error. |
| AI-generated images | None. There is no `IMAGE_PROMPTS.md`. |
| Guest ID scans or photos | Never stored ([TRD §9](TRD.md#9-security-and-privacy)). |
| Web fonts from a CDN, icons fetched at runtime, icon fonts, emoji as icons, hotlinked photos | Not allowed in the app. |
| A chart library or chart images | Not allowed ([TRD §2](TRD.md#2-allowed-dependencies)). |
| Euphoria's artwork, park scene, sign-in card, palette or fonts | Not used. Only its structure and conventions were taken ([DESIGN_SYSTEM §10](DESIGN_SYSTEM.md#10-what-we-deliberately-did-not-copy-from-euphoria)). |

## 2. How to change the brand later

The brand is one name, two logo files, the photographs and one set of tokens (decision D7). Changing it touches these places and nothing else:

| What changes | Change | File |
|---|---|---|
| Product name | Set `name` | `config/hotel.php` (`'name' => 'Homi'`) |
| Brand colours | Replace the values of `--hm-brand`, `--hm-primary`, `--hm-primary-ink`, `--hm-primary-50`, `--hm-select`, `--hm-select-line`, `--hm-focus` and `--hm-held-line` (light and dark), and rebuild the eleven-step `--color-homi-*` scale around the new primary: step 600 is the primary, 700 the primary ink, 50 the tint; the logo colour stays outside the scale. Keep the rules behind them: `brand` is for the logo only; `primary` must hold white text at 4.5 : 1 or better; `primary-ink` must reach 4.5 : 1 on `primary-50` and `select`. Keep the status colours unless the new brand colour is close to one of them. Re-check contrast as in [DESIGN_SYSTEM §8](DESIGN_SYSTEM.md#8-accessibility). | `resources/css/app.css` (the `--hm-*` layer and the `@theme static` scale), then the token tables in [DESIGN_SYSTEM §2](DESIGN_SYSTEM.md#2-colour-tokens) and the v4 block of the mockup. `vite.config.ts` needs no change: it names the scale (`primary: 'homi'`), not a colour. |
| Logo | Replace the two master files, keeping the file names, a transparent background, and one version for light and one for photographs and dark backgrounds; re-export the 400 px and 800 px files. If the new logo's ratio is not about 3.3 : 1, adjust the display sizes in section 1.3. | `resources/images/brand/` |
| Favicon and rail mark | Regenerate the three icon files from the new mark | `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png` |
| Photographs | Replace a file with another real photograph under the **same file name**, at the sizes in section 1.5 (2000 px and `-1200` for hero and sign-in, 1200 px for room cards). Check the text area against the scrim as in [DESIGN_SYSTEM §8.1](DESIGN_SYSTEM.md#81-contrast). Update or remove its entry in `photo_credits`. A new room type needs `room-{slug}.jpg`. | `public/images/photos/`, `config/hotel.php` |
| Typeface (only if one is supplied with a web licence) | Add its woff2 files and licence text, change `--font-sans`. Geist Mono stays for slips and stay codes. Re-run the peso-sign check of section 1.1. | `resources/fonts/`, `resources/css/app.css`, [DESIGN_SYSTEM §3](DESIGN_SYSTEM.md#3-typography) |
| PDF header | Nothing to edit: the Blade view reads the same logo file and the same config name | `resources/views/reports/zreport.blade.php` |
| Slip header per branch | No code change: the owner or admin edits it | Screen 46 (`branches.slip_header`, `branches.address`) |
| Welcome page and kiosk wording | The welcome page shows the branch name and its rates; "Welcome to …" on the kiosk uses the branch name; house rules are edited on screen 46 | No code change |

After a change: open `/dev/components` and screens 1 (morning, afternoon and evening), 59, 4, 60, 27, 8 (print preview) and 37b (screen, print preview and PDF), in Light and in Dark, with and without reduced motion, and compare with the mockup; update this file's status columns and section 3. Slips stay text-only, because thermal printers print images slowly and poorly.

## 3. Licences summary

| Asset | Licence | What it requires | How we comply |
|---|---|---|---|
| Geist and Geist Mono (Vercel) | SIL Open Font License 1.1 | Keep the licence with the font files; do not sell the fonts by themselves; a modified version may not use the reserved name | `resources/fonts/OFL-Geist.txt` is committed next to the files |
| `@fontsource/ibm-plex-sans`, `@fontsource/ibm-plex-mono`, `@fontsource/newsreader` (packaging) | Font files under OFL 1.1 | Same as the fonts | Used only as the download source; not kept in `package.json` |
| The six photographs (section 1.5) | Unsplash License | Free to use, also commercially, and to modify, without permission or attribution. Not allowed: selling unaltered copies, and using the photos to build a similar or competing photo service. | Used as page photographs inside the product only, cropped and compressed; never sold or offered for download as photos; credit given in the welcome footer as a courtesy. Downloaded only after the owner agrees. |
| Nuxt UI v4 (`@nuxt/ui`) | MIT | Keep the copyright notice in copies of the software | The notice stays in `node_modules` and in the built bundle's licence comments |
| Lucide icons (`@iconify-json/lucide`) | ISC | Keep the copyright notice | Same |
| `@vueuse/core`, `date-fns` | MIT | Keep the copyright notice | Same |
| `qrcode` (npm) | MIT | Keep the copyright notice | Same |
| dompdf (`barryvdh/laravel-dompdf` is MIT; the `dompdf/dompdf` library under it is LGPL 2.1) | LGPL 2.1 for the library | Use it as an unmodified library and keep its licence; if we ever change dompdf's own source, publish those changes | Installed by Composer and never edited; its licence file stays in `vendor/` |
| DejaVu Sans (inside dompdf, PDF text) | DejaVu Fonts licence (Bitstream Vera terms) | Keep the licence with the font; do not sell the font by itself | Shipped untouched inside `vendor/dompdf` |
| Homi logo (blue and white) and the favicon derived from it | Owned by the client (the owner's own artwork) | Nothing; not to be reused outside this product | Kept in `resources/images/brand/` |
| The hotel's own photographs, when supplied | Owned by the client | The owner confirms each photo is his to use before it is committed | They replace the Unsplash files one for one in `public/images/photos/` |
| Logo fallback (bed icon on a square) | Built from a Lucide icon (ISC) | Same as Lucide | Shown only if a logo image fails to load |
| Sample names, hotel and prices ("Hotel Dalisay GenSan") | Made up for this project | — | Not real people or businesses |

Total paid licences: ₱0.
