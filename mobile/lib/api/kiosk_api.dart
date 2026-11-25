import 'api_client.dart';

/// Lobby kiosk endpoints. HoldRoom is the only write and is never sent a price.
class KioskApi {
  KioskApi(this._api, this._token);

  final ApiClient _api;
  final String _token;

  Future<Map<String, dynamic>> welcome() => _api.get('/api/kiosk/welcome', token: _token);

  Future<Map<String, dynamic>> rooms() => _api.get('/api/kiosk/rooms', token: _token);

  Future<Map<String, dynamic>> offer({required int roomType, required String kind, required int quantity}) {
    return _api.get('/api/kiosk/offer', token: _token, query: {
      'room_type': roomType,
      'kind': kind,
      'quantity': quantity,
    });
  }

  Future<Map<String, dynamic>> hold({
    required int roomTypeId,
    required int hours,
    required String guestName,
    required String idempotencyKey,
    String? guestMobile,
  }) {
    return _api.post('/api/kiosk/hold', token: _token, body: {
      'room_type_id': roomTypeId,
      'hours': hours,
      'guest_name': guestName,
      'idempotency_key': idempotencyKey,
      if (guestMobile != null && guestMobile.isNotEmpty) 'guest_mobile': guestMobile,
    });
  }

  Future<Map<String, dynamic>> held(String code) => _api.get('/api/kiosk/held/$code', token: _token);
}
