import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../db_helper.dart';
import 'globals.dart';

class SessionManager {
  static const _storage = FlutterSecureStorage();
  static const _sessionKey = 'madrasa_session';

  static Future<void> saveSession(
    String role,
    int userId, {
    List<String>? permissions,
    String? token,
    String? name,
    List<String>? assignedClasses,
    List<int>? studentIds,
    String? tenantCode,
    int? tenantId,
    String? tenantName,
    String? masjidName,
    List<String>? allowedModules,
    bool? isTrial,
    int? trialDaysLeft,
    String? planName,
    String? planCode,
    String? subscriptionEndsAt,
    String? subscriptionStatus,
  }) async {
    final data = {
      'role': role,
      'userId': userId,
      'permissions': permissions ?? [],
      'token': token,
      'name': name,
      'assignedClasses': assignedClasses ?? [],
      'studentIds': studentIds ?? [],
      'tenantCode': tenantCode,
      'tenantId': tenantId,
      'tenantName': tenantName,
      'masjidName': masjidName,
      'allowedModules': allowedModules ?? [],
      'isTrial': isTrial ?? false,
      'trialDaysLeft': trialDaysLeft ?? 0,
      'planName': planName ?? 'بنیادی پیکج (Basic)',
      'planCode': planCode ?? 'basic',
      'subscriptionEndsAt': subscriptionEndsAt ?? '',
      'subscriptionStatus': subscriptionStatus ?? 'active',
      'timestamp': DateTime.now().millisecondsSinceEpoch,
    };
    await _storage.write(key: _sessionKey, value: jsonEncode(data));
  }

  static Future<List<int>> getParentStudentIds() async {
    final sess = await getSession();
    if (sess == null) return [];
    final list = sess['studentIds'];
    if (list is List) {
      return list.map((e) => int.tryParse(e.toString()) ?? 0).where((id) => id > 0).toList();
    }
    return [];
  }

  static Future<Map<String, dynamic>?> getSession() async {
    try {
      final str = await _storage.read(key: _sessionKey);
      if (str == null || str.isEmpty) return null;
      final data = jsonDecode(str);
      if (data is! Map<String, dynamic>) return null;

      final timestamp = data['timestamp'];
      if (timestamp != null) {
        final sessionTime =
            DateTime.fromMillisecondsSinceEpoch(timestamp as int);
        final difference = DateTime.now().difference(sessionTime);
        if (difference.inDays >= 90) {
          await clearSession();
          return null;
        }
      }

      return data;
    } catch (e) {
      return null;
    }
  }

  static Future<void> clearSession() async {
    try {
      await _storage.delete(key: _sessionKey);
      await _storage.delete(key: 'last_sync_time');
    } catch (_) {}

    // لوکل گلوبل اسٹیٹس ری سیٹ کریں
    currentUserRole.value = null;
    currentUserId.value = null;
    currentUserName.value = null;
    currentUserPermissions.value = [];
    currentUserToken.value = null;
    currentUserAssignedClasses.value = [];
    currentTenantCode.value = null;
    currentTenantId.value = null;
    currentTenantName.value = null;
    currentTenantMasjidName.value = null;
    currentTenantAllowedModules.value = [];
    currentTenantIsTrial.value = false;
    currentTenantTrialDaysLeft.value = 0;
    currentTenantPlanName.value = 'بنیادی پیکج (Basic)';
    currentTenantPlanCode.value = 'basic';
    currentTenantSubscriptionEndsAt.value = '';
    currentTenantSubscriptionStatus.value = 'active';

    // ملٹی ٹیننٹ ڈیٹا آئسولیشن: لاگ آؤٹ پر لوکل ڈیٹا بیس مکمل ری سیٹ
    try {
      await DBHelper().clearAndResetDatabase();
    } catch (_) {}
  }
}
