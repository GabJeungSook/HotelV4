import 'package:dio/dio.dart';

import '../config.dart';

/// A refusal from the API, already turned into a message the UI can show.
/// Mirrors the server's JSON error shape { message, code, ... } and the status
/// map in DomainErrorResponse::json (422 refusal, 409 conflict, 401/403 auth).
class ApiException implements Exception {
  ApiException(this.message, {this.status, this.code, this.errors});

  final String message;
  final int? status;
  final String? code;
  final Map<String, dynamic>? errors;

  bool get isUnauthorized => status == 401;
  bool get isForbidden => status == 403;
  bool get isConflict => status == 409;

  @override
  String toString() => message;
}

/// Thin Dio wrapper: base URL, JSON headers, a bearer token supplied per call,
/// and uniform error mapping so screens only ever deal with ApiException.
class ApiClient {
  ApiClient({Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: Config.apiBase,
              connectTimeout: const Duration(seconds: 10),
              receiveTimeout: const Duration(seconds: 15),
              headers: {'Accept': 'application/json'},
              // We handle non-2xx ourselves so we can read the JSON body.
              validateStatus: (code) => code != null && code < 500,
            ));

  final Dio _dio;

  Future<Map<String, dynamic>> get(String path, {String? token, Map<String, dynamic>? query}) {
    return _send(() => _dio.get(path, queryParameters: query, options: _opts(token)));
  }

  Future<Map<String, dynamic>> post(String path, {String? token, Map<String, dynamic>? body}) {
    return _send(() => _dio.post(path, data: body, options: _opts(token)));
  }

  Options _opts(String? token) => Options(
        headers: token == null ? null : {'Authorization': 'Bearer $token'},
      );

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() run) async {
    final Response<dynamic> res;
    try {
      res = await run();
    } on DioException catch (e) {
      throw ApiException(
        'Cannot reach the server. Check the connection and try again.',
        status: e.response?.statusCode,
      );
    }

    final data = res.data;
    final body = data is Map<String, dynamic> ? data : <String, dynamic>{};
    final status = res.statusCode ?? 0;

    if (status >= 200 && status < 300) {
      return body;
    }

    throw ApiException(
      (body['message'] as String?) ?? 'Something went wrong (HTTP $status).',
      status: status,
      code: body['code'] as String?,
      errors: body['errors'] as Map<String, dynamic>?,
    );
  }
}
