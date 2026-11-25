/// App-wide configuration.
///
/// The API base URL. Override at build/run time with:
///   flutter run --dart-define=HOMI_API_BASE=http://192.168.1.10:8000
/// On a real device, point this at the server's LAN IP (not 127.0.0.1, which is
/// the phone itself). The default suits the Android emulator reaching a host dev
/// server at :8000 (10.0.2.2 is the emulator's alias for the host machine).
class Config {
  const Config._();

  static const String apiBase = String.fromEnvironment(
    'HOMI_API_BASE',
    defaultValue: 'http://10.0.2.2:8000',
  );
}
