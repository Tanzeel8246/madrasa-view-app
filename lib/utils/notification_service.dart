import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'network_service.dart';
import 'globals.dart';

class NotificationService {
  static final NotificationService _instance = NotificationService._internal();
  factory NotificationService() => _instance;
  NotificationService._internal();

  static String? _currentToken;
  static String? get currentToken => _currentToken;

  /// Initialize Push Notification Service
  static Future<void> initialize() async {
    try {
      debugPrint('NotificationService: Initializing push notifications...');
      _currentToken = 'fcm_token_${DateTime.now().millisecondsSinceEpoch}';

      final uId = currentUserId.value;
      final role = currentUserRole.value;
      if (uId != null && uId > 0) {
        await syncTokenWithServer(uId, role ?? 'admin');
      }
    } catch (e, stackTrace) {
      debugPrint('NotificationService Init Error: $e\n$stackTrace');
    }
  }

  /// Sync FCM device token with PHP Web Server
  static Future<bool> syncTokenWithServer(int userId, String role) async {
    try {
      final token = _currentToken ?? 'fcm_token_client_$userId';
      final response = await NetworkService.postWithFallbackAction(
        'update_fcm_token',
        {
          'token': token,
          'user_id': userId.toString(),
          'role': role,
        },
        timeout: const Duration(seconds: 30),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success') {
          debugPrint(
              'NotificationService: Token synced successfully with server.');
          return true;
        }
      }
    } catch (e) {
      debugPrint('NotificationService Sync Error: $e');
    }
    return false;
  }

  /// Handle incoming foreground / background notification payload
  static void handleNotificationMessage(Map<String, dynamic> data) {
    final type = data['type']?.toString() ?? 'general';
    final title = data['title']?.toString() ?? 'اطلاع';
    final body = data['body']?.toString() ?? '';

    debugPrint('Received Notification: [$type] $title: $body');
    // Types: new_notice, attendance_reminder, fee_reminder
  }
}
