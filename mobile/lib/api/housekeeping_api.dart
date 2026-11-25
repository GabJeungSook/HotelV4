import 'api_client.dart';

/// Roomboy endpoints. Returns the raw decoded maps/lists from the API; screens
/// read the same keys the web housekeeping pages use (tasks, current, …).
class HousekeepingApi {
  HousekeepingApi(this._api, this._token);

  final ApiClient _api;
  final String _token;

  Future<Map<String, dynamic>> queue() => _api.get('/api/housekeeping/queue', token: _token);

  Future<Map<String, dynamic>> history() => _api.get('/api/housekeeping/history', token: _token);

  Future<Map<String, dynamic>> task(int id) => _api.get('/api/housekeeping/tasks/$id', token: _token);

  Future<Map<String, dynamic>> start(int id) => _api.post('/api/housekeeping/tasks/$id/start', token: _token);

  Future<Map<String, dynamic>> finish(int id, {required bool ready, String? problemNote}) {
    return _api.post('/api/housekeeping/tasks/$id/finish', token: _token, body: {
      'result': ready ? 'ready' : 'problem',
      if (!ready && problemNote != null) 'problem_note': problemNote,
    });
  }
}
