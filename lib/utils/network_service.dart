// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:http/http.dart' as http;

import 'globals.dart';

class NetworkService {
  static String? _activeBaseUrl;

  static String? get activeBaseUrl => _activeBaseUrl;

  static void setActiveBaseUrl(String? url) {
    if (url == null || url.trim().isEmpty) {
      _activeBaseUrl = null;
      return;
    }
    final trimmed = url.trim();
    _activeBaseUrl = trimmed.endsWith('/') ? trimmed : '$trimmed/';
  }

  static List<String> baseUrls() {
    final Set<String> urls = {};

    void addCandidate(String raw) {
      final trimmed = raw.trim();
      if (trimmed.isEmpty) return;
      final normalized = trimmed.endsWith('/') ? trimmed : '$trimmed/';
      final Uri? parsed = Uri.tryParse(trimmed);
      if (parsed != null && parsed.host.isNotEmpty) {
        // اگر غلطی سے صرف mamfss.com ہو تو اسے درست کر کے madrasa.mamfss.com پر لے جائیں
        if (parsed.host == 'mamfss.com' || parsed.host == 'www.mamfss.com') {
          urls.add('https://madrasa.mamfss.com/');
          return;
        }
      }
      urls.add(normalized);
      if (parsed != null && parsed.host.isNotEmpty) {
        if (parsed.host.startsWith('www.')) {
          final noWww = parsed.replace(host: parsed.host.replaceFirst('www.', '')).toString();
          urls.add(noWww.endsWith('/') ? noWww : '$noWww/');
        } else {
          final withWww = parsed.replace(host: 'www.${parsed.host}').toString();
          urls.add(withWww.endsWith('/') ? withWww : '$withWww/');
        }
      }
    }

    // 1. سب سے پہلے تصدیق شدہ فعال سرور (Active Base URL)
    if (_activeBaseUrl != null && _activeBaseUrl!.isNotEmpty) {
      addCandidate(_activeBaseUrl!);
    }

    // 2. کلاؤڈ لائیو سرورز (سینٹرل لائیو ڈومین سرِ فہرست)
    addCandidate('https://madrasa.mamfss.com/');
    addCandidate('https://madrasa.mindcraftsai.com/');

    // 3. کسٹم انوائرنمنٹ یو آر ایل
    if (apiUrl.isNotEmpty) {
      addCandidate(apiUrl);
    }

    return urls.toList();
  }

  static bool _isValidApiResponse(http.Response res) {
    // 1. اگر اسٹیٹس 200 ہے تو چیک کریں کہ یہ سرور کا کوئی ایچ ٹی ایم ایل ایرر تو نہیں
    if (res.statusCode == 200) {
      final body = res.body.trim();
      if (body.startsWith('<!DOCTYPE') || body.startsWith('<html') || body.startsWith('<HTML')) {
        return false;
      }
      return true;
    }

    // 2. اگر اسٹیٹس 400 یا 422 وغیرہ ہو مگر باڈی ویلڈ JSON ہے (جیسے لاگ ان فیلیر)
    if (res.statusCode >= 400 && res.statusCode < 500) {
      try {
        final body = res.body.trim();
        if (body.startsWith('{') && body.endsWith('}')) {
          final decoded = jsonDecode(body);
          if (decoded is Map && (decoded.containsKey('status') || decoded.containsKey('message'))) {
            return true;
          }
        }
      } catch (_) {}
    }

    // ایچ ٹی ایم ایل 401، 403، 404 یا 500 سرور ایرر کی صورت میں اگلے سرور پر فال بیک کریں
    return false;
  }

  static Map<String, String> authHeaders({bool json = false}) {
    final token = currentUserToken.value;
    if (apiSecretKey.isEmpty && (token == null || token.isEmpty)) {
      throw StateError('Login required.');
    }
    final tCode = currentTenantCode.value;
    final tId = currentTenantId.value;

    return {
      if (json) 'Content-Type': 'application/json',
      'X-CLIENT-PLATFORM': !kIsWeb && Platform.isWindows ? 'windows' : 'mobile',
      if (apiSecretKey.isNotEmpty) 'Authorization': 'Bearer $apiSecretKey',
      if (apiSecretKey.isNotEmpty) 'X-API-KEY': apiSecretKey,
      if (token != null && token.isNotEmpty) 'X-USER-TOKEN': token,
      if (token != null && token.isNotEmpty && apiSecretKey.isEmpty)
        'Authorization': 'Bearer $token',
      if (tCode != null && tCode.isNotEmpty) 'X-TENANT-CODE': tCode,
      if (tId != null) 'X-TENANT-ID': tId.toString(),
    };
  }

  static Map<String, String> publicHeaders({bool json = false}) {
    final tCode = currentTenantCode.value;
    final tId = currentTenantId.value;
    return {
      if (json) 'Content-Type': 'application/json',
      'X-CLIENT-PLATFORM': !kIsWeb && Platform.isWindows ? 'windows' : 'mobile',
      if (apiSecretKey.isNotEmpty) 'X-API-KEY': apiSecretKey,
      if (tCode != null && tCode.isNotEmpty) 'X-TENANT-CODE': tCode,
      if (tId != null) 'X-TENANT-ID': tId.toString(),
    };
  }

  static Uri buildActionUri(String baseUrl, String action) {
    String fullUrl = baseUrl;
    if (!fullUrl.endsWith('/')) fullUrl += '/';
    if (!fullUrl.contains('api.php')) fullUrl += 'api.php';
    final token = currentUserToken.value;
    final tCode = currentTenantCode.value;
    String actionWithToken = action;
    if (token != null &&
        token.isNotEmpty &&
        !action.contains('user_token=')) {
      actionWithToken += '&user_token=${Uri.encodeComponent(token)}';
    }
    if (tCode != null &&
        tCode.isNotEmpty &&
        !action.contains('tenant_code=')) {
      actionWithToken += '&tenant_code=${Uri.encodeComponent(tCode)}';
    }
    return Uri.parse('$fullUrl?action=$actionWithToken');
  }

  static Uri buildPathUri(String baseUrl, String path) {
    String fullUrl = baseUrl;
    if (!fullUrl.endsWith('/')) fullUrl += '/';
    return Uri.parse('$fullUrl$path');
  }

  static Duration _defaultTimeoutForAction(String action) {
    final act = action.toLowerCase();
    if (act.startsWith('sync_down') ||
        act.startsWith('sync_up') ||
        act.contains('sync')) {
      return const Duration(seconds: 60);
    }
    return const Duration(seconds: 20);
  }

  static Future<http.Response> getWithFallbackAction(
    String action, {
    Duration? timeout,
  }) async {
    final effectiveTimeout = timeout ?? _defaultTimeoutForAction(action);
    Object? lastError;
    for (final baseUrl in baseUrls()) {
      try {
        final res = await http
            .get(
              buildActionUri(baseUrl, action),
              headers: authHeaders(),
            )
            .timeout(effectiveTimeout);
        if (!_isValidApiResponse(res)) {
          lastError = Exception('Endpoint returned ${res.statusCode} at $baseUrl');
          continue;
        }
        _activeBaseUrl = baseUrl;
        return res;
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError ?? Exception('No working endpoint');
  }

  static Future<http.Response> publicGetWithFallbackAction(
    String action, {
    Duration? timeout,
  }) async {
    final effectiveTimeout = timeout ?? _defaultTimeoutForAction(action);
    Object? lastError;
    for (final baseUrl in baseUrls()) {
      try {
        final res = await http
            .get(
              buildActionUri(baseUrl, action),
              headers: publicHeaders(),
            )
            .timeout(effectiveTimeout);
        if (!_isValidApiResponse(res)) {
          lastError = Exception('Endpoint returned ${res.statusCode} at $baseUrl');
          continue;
        }
        _activeBaseUrl = baseUrl;
        return res;
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError ?? Exception('No working endpoint');
  }

  static Future<http.Response> postWithFallbackAction(
    String action,
    Map<String, dynamic> payload, {
    Duration? timeout,
  }) async {
    final effectiveTimeout = timeout ?? _defaultTimeoutForAction(action);
    final token = currentUserToken.value;
    final augmentedPayload = Map<String, dynamic>.from(payload);
    if (token != null &&
        token.isNotEmpty &&
        !augmentedPayload.containsKey('user_token')) {
      augmentedPayload['user_token'] = token;
    }
    Object? lastError;
    for (final baseUrl in baseUrls()) {
      try {
        final res = await http
            .post(
              buildActionUri(baseUrl, action),
              headers: authHeaders(json: true),
              body: jsonEncode(augmentedPayload),
            )
            .timeout(effectiveTimeout);
        if (!_isValidApiResponse(res)) {
          lastError = Exception('Endpoint returned ${res.statusCode} at $baseUrl');
          continue;
        }
        _activeBaseUrl = baseUrl;
        return res;
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError ?? Exception('No working endpoint');
  }

  static Future<http.Response> publicPostWithFallbackAction(
    String action,
    Map<String, dynamic> payload, {
    Duration? timeout,
  }) async {
    final effectiveTimeout = timeout ?? _defaultTimeoutForAction(action);
    Object? lastError;
    for (final baseUrl in baseUrls()) {
      try {
        final res = await http
            .post(
              buildActionUri(baseUrl, action),
              headers: publicHeaders(json: true),
              body: jsonEncode(payload),
            )
            .timeout(effectiveTimeout);
        if (!_isValidApiResponse(res)) {
          lastError = Exception('Endpoint returned ${res.statusCode} at $baseUrl');
          continue;
        }
        _activeBaseUrl = baseUrl;
        return res;
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError ?? Exception('No working endpoint');
  }

  static Future<http.Response> postToPathWithFallback(
    String path,
    Map<String, dynamic> payload, {
    Duration timeout = const Duration(seconds: 30),
  }) async {
    final token = currentUserToken.value;
    final augmentedPayload = Map<String, dynamic>.from(payload);
    if (token != null &&
        token.isNotEmpty &&
        !augmentedPayload.containsKey('user_token')) {
      augmentedPayload['user_token'] = token;
    }
    Object? lastError;
    for (final baseUrl in baseUrls()) {
      try {
        final res = await http
            .post(
              buildPathUri(baseUrl, path),
              headers: authHeaders(json: true),
              body: jsonEncode(augmentedPayload),
            )
            .timeout(timeout);
        if (!_isValidApiResponse(res)) {
          lastError = Exception('Endpoint returned ${res.statusCode} at $baseUrl');
          continue;
        }
        _activeBaseUrl = baseUrl;
        return res;
      } catch (e) {
        lastError = e;
      }
    }
    throw lastError ?? Exception('No working endpoint');
  }
}
