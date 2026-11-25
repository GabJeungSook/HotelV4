# Homi mobile (Flutter)

Native apps for the two device roles — the **lobby kiosk** (tablet) and the **roomboy / housekeeping** (phone) — over the Homi JSON API (`routes/api.php`, Sanctum tokens). Phase 2 of the mobile plan; Phase 1 (the API) lives in the main Laravel app.

## Decisions made so far (temporary — confirm or change)

These were chosen to keep moving while unattended; all are easy to revise:

1. **One app, role-based.** A single Flutter codebase; after sign-in the returned role (`kiosk` or `housekeeper`) decides which UI shows (`lib/main.dart`). Rationale: one codebase to maintain, shared auth/networking. Splitting into two apps later is mostly moving `screens/kiosk` vs `screens/housekeeping` into separate entrypoints.
2. **Lives in `/mobile`** inside this repo (monorepo), versioned with the API it depends on.
3. **v1 is online-only.** No offline cache and no push notifications yet (Phase 3). The queue refreshes on pull-to-refresh and on return from a cleaning.
4. **Deps:** `dio` (HTTP + bearer interceptor), `flutter_secure_storage` (token kept in the platform keystore). No state-management package — a `ChangeNotifier` (`AuthStore`) drives sign-in/out.

## Status

- Networking + auth + roomboy flow (queue → start → finish, ready/problem) are **implemented**.
- Kiosk is **implemented end to end**: welcome → pick room & hours → offer (price breakdown + name/mobile) → hold → held (code + QR + countdown). Long-stay (day) bookings are the one remaining kiosk addition; hourly is done.
- **Verification:** `flutter analyze` is clean (Flutter 3.50 / Dart 3.14). Not yet run on a device — needs platform folders + an Android/iOS SDK (see below).

## Run it

```bash
cd mobile
flutter pub get
flutter analyze          # static check (no device needed)

# Point at your dev server. On the Android emulator, 10.0.2.2 = the host machine.
# On a real device, use the server's LAN IP (e.g. http://192.168.1.10:8000).
flutter run --dart-define=HOMI_API_BASE=http://10.0.2.2:8000
```

Sign in with a **kiosk** or **housekeeper** account (e.g. the seeded `kiosk-gensan-1` / `dodong.lumayag`, password `password` on a dev build). Other roles are refused by the API.

> First `flutter run` on a new machine also needs a target: Android (Android Studio + an emulator or a USB device) or iOS (Xcode, macOS only). `flutter doctor` lists what's missing.

## Layout

```
lib/
  config.dart                 API base URL (--dart-define HOMI_API_BASE), brand colour
  models/app_user.dart        the signed-in account
  api/
    api_client.dart           dio wrapper + ApiException (maps the server's JSON errors)
    auth_api … (in auth_store) login / logout / me
    housekeeping_api.dart     queue, history, task, start, finish
    kiosk_api.dart            welcome, rooms, offer, hold, held
  state/auth_store.dart       token (secure storage) + current user, ChangeNotifier
  screens/
    login_screen.dart
    housekeeping/queue_screen.dart, cleaning_screen.dart
    kiosk/welcome_screen.dart
```

## Next steps

- Kiosk long-stay (per-day) bookings alongside the hourly flow.
- Offline queue for housekeeping (spotty room wifi) and push notifications for new cleanings (Phase 3).
- Platform scaffolding (`flutter create . --platforms=android,ios` here) and app icons/splash.
- CI: `flutter analyze` + `flutter test` on the `mobile/` path.
