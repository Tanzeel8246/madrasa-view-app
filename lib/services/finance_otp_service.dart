import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../utils/network_service.dart';

class FinanceOtpService {
  static String get cleanApiUrl {
    var url = apiUrl.trim();
    if (!url.endsWith('/')) {
      url += '/';
    }
    return url;
  }

  /// غیر ایڈمن صارف کے لیے ایڈمن کو 24 گھنٹے کا OTP بھیجنے کی درخواست
  static Future<Map<String, dynamic>> requestEditOtp({
    required int financeId,
    String? systemType,
  }) async {
    try {
      final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
      final uri = Uri.parse('${cleanApiUrl}api.php?action=request_finance_edit_otp');
      final body = {
        'finance_id': financeId,
        'user_id': currentUserId.value ?? 0,
        'user_name': currentUserName.value ?? 'User',
        'tenant_id': currentTenantId.value ?? 0,
        'system_type': sys,
      };

      Map<String, String> headers = {'Content-Type': 'application/json'};
      try {
        headers = NetworkService.publicHeaders(json: true);
        final token = currentUserToken.value;
        if (token != null && token.isNotEmpty) {
          headers['Authorization'] = 'Bearer $token';
          headers['X-USER-TOKEN'] = token;
        }
      } catch (_) {}

      final response = await http.post(
        uri,
        headers: headers,
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return {
          'success': data['status'] == 'success',
          'message': data['message'] ?? '',
          'otp_code': data['otp_code']?.toString() ?? '',
          'expires_at': data['expires_at'] ?? '',
        };
      }
      return {
        'success': false,
        'message': 'سرور کی خرابی: کوڈ ${response.statusCode}',
      };
    } catch (e) {
      debugPrint('Error requesting finance edit OTP: $e');
      return {
        'success': false,
        'message': 'رابطہ میں خرابی: $e',
      };
    }
  }

  /// غیر ایڈمن صارف کی طرف سے ایڈمن سے حاصل کردہ 6 ہندسوں کے OTP کی تصدیق
  static Future<Map<String, dynamic>> verifyEditOtp({
    required int financeId,
    required String otpCode,
  }) async {
    try {
      final uri = Uri.parse('${cleanApiUrl}api.php?action=verify_finance_edit_otp');
      final body = {
        'finance_id': financeId,
        'otp_code': otpCode.trim(),
        'user_id': currentUserId.value ?? 0,
        'tenant_id': currentTenantId.value ?? 0,
      };

      Map<String, String> headers = {'Content-Type': 'application/json'};
      try {
        headers = NetworkService.publicHeaders(json: true);
        final token = currentUserToken.value;
        if (token != null && token.isNotEmpty) {
          headers['Authorization'] = 'Bearer $token';
          headers['X-USER-TOKEN'] = token;
        }
      } catch (_) {}

      final response = await http.post(
        uri,
        headers: headers,
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return {
          'success': data['status'] == 'success',
          'message': data['message'] ?? '',
        };
      }
      return {
        'success': false,
        'message': 'سرور کی خرابی: کوڈ ${response.statusCode}',
      };
    } catch (e) {
      debugPrint('Error verifying finance edit OTP: $e');
      return {
        'success': false,
        'message': 'رابطہ میں خرابی: $e',
      };
    }
  }

  /// چیک کریں کہ کیا اس انٹری کے لیے 24 گھنٹے کا فعال OTP تصدیق شدہ ہے یا صارف ایڈمن ہے
  static Future<bool> checkEditPermission({
    required int financeId,
  }) async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    if (role == 'admin' || role == 'superadmin' || role == 'super_admin' || role == 'administrator') {
      return true;
    }

    try {
      final uri = Uri.parse('${cleanApiUrl}api.php?action=check_finance_edit_permission');
      final body = {
        'finance_id': financeId,
        'user_id': currentUserId.value ?? 0,
        'tenant_id': currentTenantId.value ?? 0,
      };

      Map<String, String> headers = {'Content-Type': 'application/json'};
      try {
        headers = NetworkService.publicHeaders(json: true);
        final token = currentUserToken.value;
        if (token != null && token.isNotEmpty) {
          headers['Authorization'] = 'Bearer $token';
          headers['X-USER-TOKEN'] = token;
        }
      } catch (_) {}

      final response = await http.post(
        uri,
        headers: headers,
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['has_permission'] == true;
      }
    } catch (e) {
      debugPrint('Error checking finance edit permission: $e');
    }
    return false;
  }

  /// ایڈمن کے لیے زیر التواء تمام OTP درخواستیں مع تفصیلات اور 6 ہندسوں کا کوڈ
  static Future<List<Map<String, dynamic>>> getAdminPendingOtps({
    String? systemType,
  }) async {
    try {
      final tid = currentTenantId.value ?? 0;
      final uid = currentUserId.value ?? 0;
      final uri = Uri.parse('${cleanApiUrl}api.php?action=get_admin_pending_otps&tenant_id=$tid&user_id=$uid');

      Map<String, String> headers = {};
      try {
        headers = NetworkService.publicHeaders();
        final token = currentUserToken.value;
        if (token != null && token.isNotEmpty) {
          headers['Authorization'] = 'Bearer $token';
          headers['X-USER-TOKEN'] = token;
        }
      } catch (_) {}

      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success' && data['otps'] is List) {
          return List<Map<String, dynamic>>.from(data['otps']);
        }
      }
    } catch (e) {
      debugPrint('Error fetching admin pending OTPs: $e');
    }
    return [];
  }

  /// خرچ کے بل یا رسید کی تصویر براہ راست سرور پر اپلوڈ کرنا
  static Future<String?> uploadBillFile({
    required int financeId,
    required File file,
  }) async {
    try {
      final uri = Uri.parse('${cleanApiUrl}api.php?action=upload_finance_bill');
      final request = http.MultipartRequest('POST', uri);
      request.fields['finance_id'] = financeId.toString();
      request.fields['tenant_id'] = (currentTenantId.value ?? 0).toString();
      request.files.add(await http.MultipartFile.fromPath('bill_file', file.path));

      final streamedResponse = await request.send().timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success') {
          return data['bill_url']?.toString() ?? data['bill_image']?.toString();
        }
      }
    } catch (e) {
      debugPrint('Error uploading bill file: $e');
    }
    return null;
  }
}
