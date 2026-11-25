import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../models/app_user.dart';

/// Holds the device's token and signed-in account, and persists the token in the
/// platform keystore so the app stays signed in across restarts. A ChangeNotifier
/// so the widget tree rebuilds on sign-in/out (no extra state package).
class AuthStore extends ChangeNotifier {
  AuthStore({ApiClient? api, FlutterSecureStorage? storage})
      : _api = api ?? ApiClient(),
        _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'homi_token';

  final ApiClient _api;
  final FlutterSecureStorage _storage;

  String? _token;
  AppUser? _user;
  bool _loading = true;

  String? get token => _token;
  AppUser? get user => _user;
  bool get loading => _loading;
  bool get signedIn => _token != null && _user != null;

  /// On launch: restore a saved token and confirm it with /api/me.
  Future<void> restore() async {
    _loading = true;
    notifyListeners();
    try {
      final saved = await _storage.read(key: _tokenKey);
      if (saved != null) {
        final body = await _api.get('/api/me', token: saved);
        _token = saved;
        _user = AppUser.fromJson(body['user'] as Map<String, dynamic>);
      }
    } on ApiException {
      await _clear(); // token gone or revoked
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> login(String username, String password, String deviceName) async {
    final body = await _api.post('/api/auth/login', body: {
      'username': username,
      'password': password,
      'device_name': deviceName,
    });
    _token = body['token'] as String;
    _user = AppUser.fromJson(body['user'] as Map<String, dynamic>);
    await _storage.write(key: _tokenKey, value: _token);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      if (_token != null) {
        await _api.post('/api/auth/logout', token: _token);
      }
    } on ApiException {
      // Revoke locally regardless of the network result.
    } finally {
      await _clear();
    }
  }

  Future<void> _clear() async {
    _token = null;
    _user = null;
    await _storage.delete(key: _tokenKey);
    notifyListeners();
  }
}
