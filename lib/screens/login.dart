// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:url_launcher/url_launcher.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../utils/session.dart';
import '../utils/notification_service.dart';
import '../widgets/neo_box.dart';
import '../widgets/app_brand_logo.dart';
import 'dashboard.dart';
import 'home.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  final _tenantCodeController = TextEditingController(text: 'MAIN01');
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  bool _isObscure = true;
  String _errorMessage = '';

  @override
  void initState() {
    super.initState();
    _loadSavedTenantCode();
  }

  Future<void> _loadSavedTenantCode() async {
    try {
      final sess = await SessionManager.getSession();
      if (sess != null &&
          sess['tenantCode'] != null &&
          sess['tenantCode'].toString().isNotEmpty) {
        if (mounted) {
          setState(() {
            _tenantCodeController.text = sess['tenantCode'].toString();
          });
        }
      }
    } catch (_) {}
  }

  @override
  void dispose() {
    _tenantCodeController.dispose();
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Map<String, dynamic> _decodeResponseBody(String body) {
    if (body.trim().isEmpty) return <String, dynamic>{};
    try {
      final jsonStart = body.indexOf('{');
      final jsonEnd = body.lastIndexOf('}');
      final cleanBody = (jsonStart >= 0 && jsonEnd > jsonStart)
          ? body.substring(jsonStart, jsonEnd + 1)
          : body;
      final decoded = jsonDecode(cleanBody);
      return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  String _networkErrorText(Object? error) {
    if (error is FormatException) {
      return isUrduGlobal.value
          ? 'سرور کا جواب درست نہیں۔ براہ کرم انتظامیہ سے رابطہ کریں۔'
          : 'Server response is invalid. Please contact support.';
    }
    return isUrduGlobal.value
        ? 'سرور سے رابطہ نہیں ہو سکا۔ انٹرنیٹ یا API URL چیک کریں۔'
        : 'Could not reach the server. Check internet or API URL.';
  }

  String _invalidLoginText() {
    return isUrduGlobal.value
        ? 'غلط یوزر نیم یا پاس ورڈ۔'
        : 'Invalid username or password.';
  }

  Future<void> _login() async {
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });

    String cleanInputCode(String input) {
      if (input.isEmpty) return '';
      var s = input.trim();
      // غیر مرئی یونیکوڈ حروف (LRM, RLM, ALM, ZWSP, BOM وغیرہ) کو ہٹائیں
      s = s.replaceAll(RegExp(r'[\u200B-\u200F\u202A-\u202E\u061C\uFEFF\s]'), '');
      // یونیکوڈ ڈیشز کو معیاری ASCII ڈیش میں تبدیل کریں
      s = s.replaceAll(RegExp(r'[\u2010-\u2015\u2212]'), '-');
      // اردو اور عربی ہندسوں کو انگریزی ہندسوں میں تبدیل کریں
      const eastern = '۰۱۲۳۴۵۶۷۸۹';
      const arabic = '٠١٢٣٤٥٦٧٨٩';
      const western = '0123456789';
      for (int i = 0; i < 10; i++) {
        s = s.replaceAll(eastern[i], western[i]).replaceAll(arabic[i], western[i]);
      }
      return s.toUpperCase();
    }

    final rawUsername = _usernameController.text.trim();
    final username = rawUsername.replaceAll(RegExp(r'[\u200B-\u200F\u202A-\u202E\u061C\uFEFF]'), '');
    final password = _passwordController.text.trim();
    final tenantCode = isMultiTenantEnabled.value
        ? cleanInputCode(_tenantCodeController.text)
        : '';
    String serverMessage = '';
    bool hasNetworkException = false;
    Object? caughtError;

    // 0. Unified Fast Login (Single roundtrip for all roles)
    try {
      final loginPayload = <String, dynamic>{
        'username': username,
        'password': password,
        'client_platform': !kIsWeb && Platform.isWindows ? 'windows' : 'mobile',
      };
      if (tenantCode.isNotEmpty) {
        loginPayload['tenant_code'] = tenantCode;
      }
      final uniRes = await NetworkService.publicPostWithFallbackAction(
        'login',
        loginPayload,
      );
      final uniBody = _decodeResponseBody(uniRes.body);
      if (uniRes.statusCode == 200 && uniBody['status'] == 'success') {
        final role = uniBody['role']?.toString() ?? 'admin';
        final newUserId = int.tryParse((uniBody['user_id'] ??
                uniBody['admin_id'] ??
                uniBody['student_id'] ??
                uniBody['parent_id'] ??
                uniBody['teacher_id'])
            ?.toString() ??
            '1') ?? 1;

        // اگر پہلے سے لاگ ان یوزر مختلف تھا تو سنک ٹائم اسٹیمپ اور فنانس کیشے ری سیٹ کریں تاکہ فل سنک ہو
        try {
          final prevSession = await SessionManager.getSession();
          final prevUserId = prevSession?['userId'];
          if (prevUserId != null && prevUserId != newUserId) {
            const storage = FlutterSecureStorage();
            await storage.delete(key: 'last_sync_time');
            final db = await DBHelper().database;
            await db.delete('finance');
            await db.delete('masjid_finance');
          }
        } catch (_) {}

        currentUserRole.value = role;
        currentUserId.value = newUserId;
        currentUserName.value =
            uniBody['name']?.toString().isNotEmpty == true
                ? uniBody['name'].toString()
                : username;
        currentUserToken.value = uniBody['token']?.toString();

        final tCode = uniBody['tenant_code']?.toString() ?? (tenantCode.isNotEmpty ? tenantCode : 'MAIN01');
        final tId = int.tryParse(uniBody['tenant_id']?.toString() ?? '1') ?? 1;
        final tName = uniBody['tenant_name']?.toString() ?? '';
        final tMasjidName = uniBody['masjid_name']?.toString() ?? '';

        currentTenantCode.value = tCode;
        currentTenantId.value = tId;
        currentTenantName.value = tName;
        if (tMasjidName.isNotEmpty) {
          currentTenantMasjidName.value = tMasjidName;
        }

        final rawMods = uniBody['allowed_modules'] as List<dynamic>?;
        final allowedMods = rawMods?.map((e) => e.toString()).toList() ?? [];
        currentTenantAllowedModules.value = allowedMods;

        final perms = uniBody['permissions'] as List<dynamic>?;
        currentUserPermissions.value =
            perms?.map((e) => e.toString()).toList() ?? [];

        List<String> assignedClasses = [];
        if (role != 'admin' && role != 'parent' && role != 'student') {
          final rawAssigned = uniBody['assigned_classes'] as List<dynamic>?;
          if (rawAssigned != null && rawAssigned.isNotEmpty) {
            assignedClasses = rawAssigned
                .map((e) => e.toString().trim())
                .where((n) => n.isNotEmpty)
                .toList();
          }
          if (assignedClasses.isEmpty) {
            try {
              final classList = await DBHelper().getClasses();
              assignedClasses = classList
                  .map((c) => c['name']?.toString() ?? '')
                  .where((n) => n.isNotEmpty)
                  .toList();
            } catch (_) {}
          }
        }
        currentUserAssignedClasses.value = assignedClasses;

        List<int>? studentIds;
        if (role == 'parent') {
          final rawStdIds = uniBody['student_ids'] as List<dynamic>?;
          studentIds = rawStdIds
              ?.map((e) => int.tryParse(e.toString()) ?? 0)
              .where((id) => id > 0)
              .toList();
        }

        final isTrial = uniBody['is_trial'] == true ||
            uniBody['is_trial'] == 1 ||
            uniBody['is_trial']?.toString().toLowerCase() == 'true';
        final trialDaysLeft =
            int.tryParse(uniBody['trial_days_left']?.toString() ?? '0') ?? 0;
        final planName =
            uniBody['plan_name']?.toString() ?? 'بنیادی پیکج (Basic)';
        final planCode = uniBody['plan_code']?.toString() ?? 'basic';
        final subEndsAt = uniBody['subscription_ends_at']?.toString() ?? '';
        final subStatus = uniBody['subscription_status']?.toString() ??
            (isTrial ? 'trial' : 'active');
        final tSystemType = uniBody['tenant_system_type']?.toString() ??
            (uniBody['system_type']?.toString() ?? 'Both');
        final maxStudents = int.tryParse(uniBody['max_students']?.toString() ?? '20') ?? 20;
        final maxClasses = int.tryParse(uniBody['max_classes']?.toString() ?? '1') ?? 1;
        final maxTeachers = int.tryParse(uniBody['max_teachers']?.toString() ?? '2') ?? 2;

        currentTenantIsTrial.value = isTrial;
        currentTenantTrialDaysLeft.value = trialDaysLeft;
        currentTenantPlanName.value = planName;
        currentTenantPlanCode.value = planCode;
        currentTenantSubscriptionEndsAt.value = subEndsAt;
        currentTenantSubscriptionStatus.value = subStatus;
        currentTenantSystemType.value = tSystemType;
        currentTenantMaxStudents.value = maxStudents;
        currentTenantMaxClasses.value = maxClasses;
        currentTenantMaxTeachers.value = maxTeachers;

        await SessionManager.saveSession(
          currentUserRole.value!,
          currentUserId.value ?? 1,
          permissions: currentUserPermissions.value,
          token: currentUserToken.value,
          name: currentUserName.value,
          assignedClasses: assignedClasses,
          studentIds: studentIds,
          tenantCode: tCode,
          tenantId: tId,
          tenantName: tName,
          masjidName: tMasjidName,
          allowedModules: allowedMods,
          isTrial: isTrial,
          trialDaysLeft: trialDaysLeft,
          planName: planName,
          planCode: planCode,
          subscriptionEndsAt: subEndsAt,
          subscriptionStatus: subStatus,
          systemType: tSystemType,
          maxStudents: maxStudents,
          maxClasses: maxClasses,
          maxTeachers: maxTeachers,
        );

        NotificationService.syncTokenWithServer(
          currentUserId.value ?? 1,
          currentUserRole.value!,
        );

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const DashboardPage()),
        );
        return;
      } else if (uniBody['message'] != null &&
          uniBody['message'].toString().isNotEmpty) {
        serverMessage = uniBody['message'].toString();
      }
    } catch (e) {
      debugPrint("Unified login attempt error, falling back: $e");
    }

    if (serverMessage.isNotEmpty) {
      if (!mounted) return;
      setState(() {
        _errorMessage = serverMessage;
        _isLoading = false;
      });
      return;
    }

    // 1. Admin / Teacher / Nazim Login
    try {
      final res = await NetworkService.publicPostWithFallbackAction(
        'login_admin',
        {'username': username, 'password': password},
      );
      final body = _decodeResponseBody(res.body);
      if (res.statusCode != 200) {
        throw Exception("Server returned ${res.statusCode}");
      }
      if (res.statusCode == 200 && body['status'] == 'success') {
        final role = body['role']?.toString() ?? 'admin';
        currentUserRole.value = role;
        currentUserId.value = int.tryParse(body['admin_id']?.toString() ?? '1');
        currentUserName.value = username;
        currentUserToken.value = body['token']?.toString();
        final perms = body['permissions'] as List<dynamic>?;
        currentUserPermissions.value =
            perms?.map((e) => e.toString()).toList() ?? [];

        List<String> assignedClasses = [];
        if (body['assigned_classes'] is List) {
          assignedClasses = (body['assigned_classes'] as List)
              .map((e) => e.toString())
              .where((n) => n.isNotEmpty)
              .toList();
        } else if (role == 'admin' || role == 'superadmin') {
          try {
            final classList = await DBHelper().getClasses();
            assignedClasses = classList
                .map((c) => c['name']?.toString() ?? '')
                .where((n) => n.isNotEmpty)
                .toList();
          } catch (_) {}
        }
        currentUserAssignedClasses.value = assignedClasses;

        final rawMods = body['allowed_modules'] as List<dynamic>?;
        final allowedMods = rawMods?.map((e) => e.toString()).toList() ?? [];
        currentTenantAllowedModules.value = allowedMods;

        await SessionManager.saveSession(
          currentUserRole.value!,
          currentUserId.value ?? 1,
          permissions: currentUserPermissions.value,
          token: currentUserToken.value,
          name: currentUserName.value,
          assignedClasses: assignedClasses,
          allowedModules: allowedMods,
        );

        NotificationService.syncTokenWithServer(
          currentUserId.value ?? 1,
          currentUserRole.value ?? 'admin',
        );

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const DashboardPage()),
        );
        return;
      } else if (body['message'] != null && body['message'].toString().isNotEmpty) {
        serverMessage = body['message'].toString();
      }
    } catch (e) {
      hasNetworkException = true;
      caughtError = e;
      debugPrint("Admin login error: $e");
    }

    // 2. Student Login
    try {
      final studentRes = await NetworkService.publicPostWithFallbackAction(
        'login_student',
        {'username': username, 'password': password},
      );
      final body = _decodeResponseBody(studentRes.body);
      if (studentRes.statusCode != 200) {
        throw Exception("Server returned ${studentRes.statusCode}");
      }
      if (studentRes.statusCode == 200 && body['status'] == 'success') {
        currentUserRole.value = 'student';
        currentUserId.value =
            int.tryParse(body['student_id']?.toString() ?? '1');
        currentUserName.value = username;
        currentUserToken.value = body['token']?.toString();

        await SessionManager.saveSession(
          'student',
          currentUserId.value!,
          token: currentUserToken.value,
          name: currentUserName.value,
        );

        NotificationService.syncTokenWithServer(
          currentUserId.value!,
          'student',
        );

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const DashboardPage()),
        );
        return;
      } else if (serverMessage.isEmpty && body['message'] != null && body['message'].toString().isNotEmpty) {
        serverMessage = body['message'].toString();
      }
    } catch (e) {
      hasNetworkException = true;
      caughtError = e;
      debugPrint("Student login error: $e");
    }

    // 3. Parent Login
    try {
      final parentRes = await NetworkService.publicPostWithFallbackAction(
        'login_parent',
        {'phone': username, 'password': password},
      );
      final parentBody = _decodeResponseBody(parentRes.body);
      if (parentRes.statusCode != 200) {
        throw Exception("Server returned ${parentRes.statusCode}");
      }
      if (parentRes.statusCode == 200 && parentBody['status'] == 'success') {
        currentUserRole.value = 'parent';
        currentUserId.value = int.tryParse(parentBody['parent_id']?.toString() ?? '1');
        currentUserName.value = username;
        currentUserToken.value = parentBody['token']?.toString();

        final rawStdIds = parentBody['student_ids'] as List<dynamic>?;
        final studentIds = rawStdIds
                ?.map((e) => int.tryParse(e.toString()) ?? 0)
                .where((id) => id > 0)
                .toList() ??
            [];

        await SessionManager.saveSession(
          'parent',
          currentUserId.value!,
          token: currentUserToken.value,
          name: currentUserName.value,
          studentIds: studentIds,
        );

        NotificationService.syncTokenWithServer(
          currentUserId.value!,
          'parent',
        );

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const DashboardPage()),
        );
        return;
      } else if (serverMessage.isEmpty && parentBody['message'] != null && parentBody['message'].toString().isNotEmpty) {
        serverMessage = parentBody['message'].toString();
      }
    } catch (e) {
      hasNetworkException = true;
      caughtError = e;
      debugPrint("Parent login error: $e");
    }

    if (!mounted) return;
    setState(() {
      if (serverMessage.isNotEmpty) {
        _errorMessage = serverMessage;
      } else if (hasNetworkException && caughtError != null) {
        _errorMessage = _networkErrorText(caughtError);
      } else {
        _errorMessage = _invalidLoginText();
      }
      _isLoading = false;
    });
  }

  void _showServerConfigDialog() {
    final isUrdu = isUrduGlobal.value;
    final currentBase = NetworkService.activeBaseUrl ?? 'https://madrasa.mamfss.com/';
    final urlController = TextEditingController(text: currentBase);
    bool isTesting = false;
    String? testResult;
    bool? testSuccess;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      constraints: const BoxConstraints(maxWidth: 550),
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (dialogCtx, setModalState) {
            return Directionality(
              textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
              child: Padding(
                padding: EdgeInsets.only(
                  left: 20,
                  right: 20,
                  top: 20,
                  bottom: MediaQuery.of(dialogCtx).viewInsets.bottom + 20,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: Container(
                        width: 44,
                        height: 4,
                        margin: const EdgeInsets.only(bottom: 16),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade300,
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                    ),
                    Row(
                      children: [
                        Icon(Icons.dns, color: primaryGreen, size: 24),
                        const SizedBox(width: 8),
                        Text(
                          isUrdu ? 'سرور نیٹ ورک سیٹنگز' : 'Server Network Settings',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: primaryGreen,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      isUrdu
                          ? 'لائیو کلاؤڈ سرور منتخب کریں یا رابطہ چیک کریں:'
                          : 'Select live cloud server or test connection:',
                      style: const TextStyle(fontSize: 13, color: Colors.black54),
                    ),
                    const SizedBox(height: 14),

                    // سرور 1 (MAMFSS Live Cloud - پرائمری)
                    InkWell(
                      onTap: () {
                        setModalState(() {
                          urlController.text = 'https://madrasa.mamfss.com/';
                          testResult = null;
                        });
                      },
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        margin: const EdgeInsets.only(bottom: 8),
                        decoration: BoxDecoration(
                          color: urlController.text.contains('mamfss.com')
                              ? Colors.green.shade50
                              : Colors.grey.shade50,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(
                            color: urlController.text.contains('mamfss.com')
                                ? primaryGreen
                                : Colors.grey.shade300,
                            width: urlController.text.contains('mamfss.com') ? 1.5 : 1,
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              urlController.text.contains('mamfss.com')
                                  ? Icons.radio_button_checked
                                  : Icons.radio_button_off,
                              color: primaryGreen,
                              size: 20,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    isUrdu ? 'کلاؤڈ سرور 1 (MAMFSS Live - پرائمری)' : 'Cloud Server 1 (MAMFSS Live - Primary)',
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                  ),
                                  const Text(
                                    'https://madrasa.mamfss.com/',
                                    style: TextStyle(fontSize: 12, color: Colors.black54),
                                    textDirection: TextDirection.ltr,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    // سرور 2 (Mindcrafts AI - بیک اپ)
                    InkWell(
                      onTap: () {
                        setModalState(() {
                          urlController.text = 'https://madrasa.mindcraftsai.com/';
                          testResult = null;
                        });
                      },
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: urlController.text.contains('mindcraftsai.com')
                              ? Colors.green.shade50
                              : Colors.grey.shade50,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(
                            color: urlController.text.contains('mindcraftsai.com')
                                ? primaryGreen
                                : Colors.grey.shade300,
                            width: urlController.text.contains('mindcraftsai.com') ? 1.5 : 1,
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              urlController.text.contains('mindcraftsai.com')
                                  ? Icons.radio_button_checked
                                  : Icons.radio_button_off,
                              color: primaryGreen,
                              size: 20,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    isUrdu ? 'کلاؤڈ سرور 2 (Mindcrafts AI - ثانوی)' : 'Cloud Server 2 (Mindcrafts AI - Secondary)',
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                  ),
                                  const Text(
                                    'https://madrasa.mindcraftsai.com/',
                                    style: TextStyle(fontSize: 12, color: Colors.black54),
                                    textDirection: TextDirection.ltr,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    // Custom URL
                    TextField(
                      controller: urlController,
                      textDirection: TextDirection.ltr,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'سرور API URL' : 'Server API URL',
                        prefixIcon: const Icon(Icons.link),
                        border: const OutlineInputBorder(),
                        isDense: true,
                      ),
                      onChanged: (_) {
                        setModalState(() {
                          testResult = null;
                        });
                      },
                    ),

                    if (testResult != null) ...[
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: testSuccess == true ? Colors.green.shade50 : Colors.red.shade50,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(
                            color: testSuccess == true ? Colors.green : Colors.red,
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              testSuccess == true ? Icons.check_circle : Icons.error,
                              color: testSuccess == true ? Colors.green : Colors.red,
                              size: 20,
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                testResult!,
                                style: TextStyle(
                                  color: testSuccess == true ? Colors.green.shade900 : Colors.red.shade900,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],

                    const Divider(height: 24),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(
                        isUrdu
                            ? 'ملٹی ٹیننٹ سپورٹ (SaaS / ادارہ کوڈ)'
                            : 'Multi-Tenant Support (SaaS / Institute Code)',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      subtitle: Text(
                        isUrdu
                            ? 'صرف MAMFSS سرور پر ضرورت پڑنے پر فعال کریں۔ مائنڈ کرافٹس اے آئی سرور پر سنگل مدرسہ کے لیے اسے بند رکھیں۔'
                            : 'Enable for MAMFSS multi-tenant. Keep disabled for single madrasa on Mindcrafts AI.',
                        style: const TextStyle(fontSize: 11, color: Colors.black54),
                      ),
                      value: isMultiTenantEnabled.value,
                      activeColor: primaryGreen,
                      onChanged: (val) {
                        setState(() {
                          isMultiTenantEnabled.value = val;
                        });
                        setModalState(() {});
                      },
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            icon: isTesting
                                ? const SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : const Icon(Icons.network_check, size: 18),
                            label: Text(isUrdu ? 'ٹیسٹ کنکشن' : 'Test'),
                            onPressed: isTesting
                                ? null
                                : () async {
                                    setModalState(() {
                                      isTesting = true;
                                      testResult = null;
                                    });
                                    final testUrl = urlController.text.trim();
                                    try {
                                      NetworkService.setActiveBaseUrl(testUrl);
                                      final res = await NetworkService.publicGetWithFallbackAction(
                                        'get_stats',
                                        timeout: const Duration(seconds: 8),
                                      );
                                      final body = _decodeResponseBody(res.body);
                                      if (res.statusCode == 200 && body['status'] == 'success') {
                                        setModalState(() {
                                          testSuccess = true;
                                          testResult = isUrdu
                                              ? '🟢 سرور آن لائن ہے اور جواب دے رہا ہے!'
                                              : '🟢 Server is online and responding!';
                                          isTesting = false;
                                        });
                                      } else {
                                        setModalState(() {
                                          testSuccess = false;
                                          testResult = isUrdu
                                              ? '🔴 سرور نے غیر متوقع جواب دیا (Status: ${res.statusCode})'
                                              : '🔴 Unexpected server response (${res.statusCode})';
                                          isTesting = false;
                                        });
                                      }
                                    } catch (e) {
                                      setModalState(() {
                                        testSuccess = false;
                                        testResult = isUrdu
                                            ? '🔴 سرور سے رابطہ نہیں ہو سکا۔'
                                            : '🔴 Could not connect to server.';
                                        isTesting = false;
                                      });
                                    }
                                  },
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                            icon: const Icon(Icons.save, size: 18, color: Colors.white),
                            label: Text(
                              isUrdu ? 'محفوظ کریں' : 'Save',
                              style: const TextStyle(color: Colors.white),
                            ),
                            onPressed: () async {
                              final selected = urlController.text.trim();
                              if (selected.isNotEmpty) {
                                NetworkService.setActiveBaseUrl(selected);
                                const storage = FlutterSecureStorage();
                                await storage.write(key: 'custom_server_url', value: selected);
                              }
                              Navigator.pop(dialogCtx);
                              if (mounted) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(
                                    content: Text(
                                      isUrdu
                                          ? 'سرور ترتیبات کامیابی سے محفوظ ہو گئیں۔'
                                          : 'Server settings saved successfully.',
                                    ),
                                    backgroundColor: primaryGreen,
                                  ),
                                );
                              }
                            },
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  void _showSuperAdminOptions() {
    final isUrdu = isUrduGlobal.value;
    showModalBottomSheet(
      context: context,
      constraints: const BoxConstraints(maxWidth: 550),
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return Directionality(
          textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 22),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 44,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade300,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.shield, color: Color(0xFFD97706), size: 26),
                    const SizedBox(width: 8),
                    Text(
                      isUrdu ? 'سپر ایڈمن پینل لاگ ان (SaaS)' : 'Super Admin Portal (SaaS)',
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF92400E),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  isUrdu
                      ? 'تمام اداروں کے ماسٹر کنٹرول اور مرکزی ترتیبات کے اختیارات:'
                      : 'Master administration across all institutions:',
                  style: const TextStyle(fontSize: 13, color: Colors.black54),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 18),
                ListTile(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(color: Colors.amber.shade300),
                  ),
                  tileColor: const Color(0xFFFFFBEB),
                  leading: CircleAvatar(
                    backgroundColor: const Color(0xFFFEF3C7),
                    child: const Icon(Icons.smartphone, color: Color(0xFFD97706)),
                  ),
                  title: Text(
                    isUrdu ? 'موبائل ایپ میں بطور سپر ایڈمن لاگ ان کریں' : 'Login into Mobile App as Super Admin',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  subtitle: Text(
                    isUrdu
                        ? 'فارم میں مرکزی ادارہ کوڈ (MAIN01) اور یوزر درج کرے گا'
                        : 'Pre-fills MAIN01 tenant code & superadmin username',
                    style: const TextStyle(fontSize: 12),
                  ),
                  trailing: const Icon(Icons.arrow_forward_ios, size: 16, color: Color(0xFFD97706)),
                  onTap: () {
                    Navigator.pop(ctx);
                    setState(() {
                      _tenantCodeController.text = 'MAIN01';
                      _usernameController.text = 'superadmin';
                      _errorMessage = '';
                    });
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        backgroundColor: const Color(0xFF92400E),
                        content: Text(
                          isUrdu
                              ? 'سپر ایڈمن کی تفصیلات درج ہو گئیں۔ برائے مہربانی اپنا پاس ورڈ درج کر کے "لاگ ان کریں" پر کلک کریں۔'
                              : 'Super Admin credentials pre-filled. Please enter your password and tap Login.',
                        ),
                        duration: const Duration(seconds: 4),
                      ),
                    );
                  },
                ),
                const SizedBox(height: 12),
                ListTile(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(color: Colors.blue.shade200),
                  ),
                  tileColor: const Color(0xFFEFF6FF),
                  leading: CircleAvatar(
                    backgroundColor: const Color(0xFFDBEAFE),
                    child: const Icon(Icons.language, color: Color(0xFF2563EB)),
                  ),
                  title: Text(
                    isUrdu ? 'آن لائن سپر ایڈمن ویب پورٹل کھولیں' : 'Open Super Admin Web Portal',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  subtitle: Text(
                    isUrdu
                        ? 'براؤزر میں نئے ادارے، بلنگ اور ڈیٹا بیس مینجمنٹ دیکھیں'
                        : 'Open SaaS Master Panel in your mobile browser',
                    style: const TextStyle(fontSize: 12),
                  ),
                  trailing: const Icon(Icons.open_in_new, size: 16, color: Color(0xFF2563EB)),
                  onTap: () async {
                    Navigator.pop(ctx);
                    final uri = Uri.parse('https://madrasa.mamfss.com/super_admin/');
                    try {
                      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
                      if (!ok && context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(isUrdu ? 'براؤزر نہیں کھل سکا' : 'Could not launch browser'),
                          ),
                        );
                      }
                    } catch (e) {
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(isUrdu ? 'براؤزر نہیں کھل سکا: $e' : 'Error: $e'),
                          ),
                        );
                      }
                    }
                  },
                ),
                const SizedBox(height: 10),
              ],
            ),
          ),
        );
      },
    );
  }


  Future<void> _resetPassword() async {
    final phoneController =
        TextEditingController(text: _usernameController.text.trim());
    final otpController = TextEditingController();
    final newPassController = TextEditingController();
    int currentStep = 1; // 1: request, 2: verify
    bool isProcessing = false;
    String statusMsg = '';
    bool isSuccess = false;

    await showDialog(
      context: context,
      barrierDismissible: false,
      builder: (dialogCtx) {
        return StatefulBuilder(
          builder: (ctx, setDialogState) {
            final isUrdu = isUrduGlobal.value;

            Future<void> handleRequestOtp() async {
              final phone = phoneController.text.trim();
              if (phone.isEmpty) {
                setDialogState(() {
                  statusMsg = isUrdu
                      ? 'براہ کرم فون یا یوزر نیم درج کریں'
                      : 'Please enter phone or username';
                  isSuccess = false;
                });
                return;
              }

              setDialogState(() {
                isProcessing = true;
                statusMsg = '';
              });

              try {
                final res = await NetworkService.publicPostWithFallbackAction(
                  'request_otp',
                  {'phone': phone},
                );
                final body = _decodeResponseBody(res.body);
                if (res.statusCode == 200 && body['status'] == 'success') {
                  setDialogState(() {
                    currentStep = 2;
                    isProcessing = false;
                    statusMsg = body['message']?.toString() ??
                        (isUrdu
                            ? 'OTP کوڈ بھیج دیا گیا ہے'
                            : 'OTP sent');
                    isSuccess = true;
                  });
                } else {
                  setDialogState(() {
                    isProcessing = false;
                    statusMsg = body['message']?.toString() ??
                        (isUrdu ? 'درخواست میں خرابی' : 'Request failed');
                    isSuccess = false;
                  });
                }
              } catch (e) {
                setDialogState(() {
                  isProcessing = false;
                  statusMsg =
                      isUrdu ? 'سرور سے رابطہ نہ ہو سکا' : 'Network error';
                  isSuccess = false;
                });
              }
            }

            Future<void> handleVerifyAndReset() async {
              final phone = phoneController.text.trim();
              final otp = otpController.text.trim();
              final newPass = newPassController.text.trim();

              if (otp.isEmpty || newPass.isEmpty) {
                setDialogState(() {
                  statusMsg = isUrdu
                      ? 'OTP اور نیا پاس ورڈ درج کریں'
                      : 'Enter OTP and new password';
                  isSuccess = false;
                });
                return;
              }

              setDialogState(() {
                isProcessing = true;
                statusMsg = '';
              });

              try {
                final res = await NetworkService.publicPostWithFallbackAction(
                  'verify_otp_and_reset',
                  {
                    'phone': phone,
                    'otp_code': otp,
                    'new_password': newPass,
                  },
                );
                final body = _decodeResponseBody(res.body);
                if (res.statusCode == 200 && body['status'] == 'success') {
                  setDialogState(() {
                    isProcessing = false;
                    statusMsg = body['message']?.toString() ??
                        (isUrdu
                            ? 'پاس ورڈ کامیابی سے تبدیل ہو گیا!'
                            : 'Password reset successfully!');
                    isSuccess = true;
                    currentStep = 3; // Done
                  });
                } else {
                  setDialogState(() {
                    isProcessing = false;
                    statusMsg = body['message']?.toString() ??
                        (isUrdu ? 'OTP غلط ہے' : 'Invalid OTP');
                    isSuccess = false;
                  });
                }
              } catch (e) {
                setDialogState(() {
                  isProcessing = false;
                  statusMsg =
                      isUrdu ? 'سرور سے رابطہ نہ ہو سکا' : 'Network error';
                  isSuccess = false;
                });
              }
            }

            return Directionality(
              textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
              child: AlertDialog(
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16)),
                title: Row(
                  children: [
                    Icon(Icons.lock_reset, color: primaryGreen),
                    const SizedBox(width: 8),
                    Text(
                      isUrdu
                          ? 'پاس ورڈ کی بحالی (OTP)'
                          : 'Reset Password (OTP)',
                      style: TextStyle(
                          color: primaryGreen,
                          fontWeight: FontWeight.bold,
                          fontSize: 18),
                    ),
                  ],
                ),
                content: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (statusMsg.isNotEmpty)
                        Container(
                          padding: const EdgeInsets.all(8),
                          margin: const EdgeInsets.only(bottom: 12),
                          decoration: BoxDecoration(
                            color: isSuccess
                                ? Colors.green.withValues(alpha: 0.1)
                                : Colors.red.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(
                                color: isSuccess ? Colors.green : Colors.red),
                          ),
                          child: Text(
                            statusMsg,
                            style: TextStyle(
                                color: isSuccess
                                    ? Colors.green.shade800
                                    : Colors.red,
                                fontSize: 13),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      if (currentStep == 1) ...[
                        Text(
                          isUrdu
                              ? 'اپنا رجسٹرڈ موبائل نمبر، یوزر نیم یا داخلہ نمبر درج کریں:'
                              : 'Enter your registered phone number, username or admission number:',
                          style: const TextStyle(
                              fontSize: 13, color: Colors.black87),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: phoneController,
                          keyboardType: TextInputType.phone,
                          decoration: InputDecoration(
                            labelText: isUrdu
                                ? 'موبائل نمبر / یوزر نیم'
                                : 'Phone / Username',
                            prefixIcon: const Icon(Icons.phone_android),
                            border: const OutlineInputBorder(),
                          ),
                        ),
                      ] else if (currentStep == 2) ...[
                        Text(
                          isUrdu
                              ? 'موصولہ 6 ہندسوں کا OTP کوڈ اور نیا پاس ورڈ درج کریں:'
                              : 'Enter the 6-digit OTP code received and your new password:',
                          style: const TextStyle(
                              fontSize: 13, color: Colors.black87),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: otpController,
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(
                            labelText:
                                isUrdu ? '6 ہندسوں کا OTP' : '6-digit OTP',
                            prefixIcon: const Icon(Icons.pin),
                            border: const OutlineInputBorder(),
                          ),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: newPassController,
                          obscureText: true,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'نیا پاس ورڈ' : 'New Password',
                            prefixIcon: const Icon(Icons.lock_outline),
                            border: const OutlineInputBorder(),
                          ),
                        ),
                      ] else ...[
                        Icon(Icons.check_circle, color: primaryGreen, size: 54),
                        const SizedBox(height: 10),
                        Text(
                          isUrdu
                              ? 'آپ کا پاس ورڈ کامیابی سے اپ ڈیٹ ہو چکا ہے۔ اب آپ نئے پاس ورڈ کے ساتھ لاگ ان کر سکتے ہیں۔'
                              : 'Your password has been successfully updated. You can now login with your new password.',
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 14),
                        ),
                      ],
                    ],
                  ),
                ),
                actions: [
                  if (currentStep == 3)
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(
                          backgroundColor: primaryGreen),
                      onPressed: () => Navigator.pop(dialogCtx),
                      child: Text(
                          isUrdu ? 'لاگ ان صفحہ پر جائیں' : 'Go to Login',
                          style: const TextStyle(color: Colors.white)),
                    )
                  else ...[
                    TextButton(
                      onPressed:
                          isProcessing ? null : () => Navigator.pop(dialogCtx),
                      child: Text(isUrdu ? 'منسوخ' : 'Cancel'),
                    ),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(
                          backgroundColor: primaryGreen),
                      onPressed: isProcessing
                          ? null
                          : (currentStep == 1
                              ? handleRequestOtp
                              : handleVerifyAndReset),
                      child: isProcessing
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                  color: Colors.white, strokeWidth: 2))
                          : Text(
                              currentStep == 1
                                  ? (isUrdu
                                      ? 'OTP کوڈ حاصل کریں'
                                      : 'Get OTP')
                                  : (isUrdu
                                      ? 'پاس ورڈ تبدیل کریں'
                                      : 'Change Password'),
                              style: const TextStyle(color: Colors.white),
                            ),
                    ),
                  ],
                ],
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
          child: Scaffold(
            backgroundColor: bgColor,
            body: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 30),
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 480),
                    child: NeoBox(
                      padding: 30,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              IconButton(
                                icon: const Icon(Icons.cloud_sync_outlined, size: 20),
                                tooltip: isUrdu ? 'سرور ترتیبات' : 'Server Settings',
                                color: Colors.black45,
                                onPressed: _showServerConfigDialog,
                              ),
                              IconButton(
                                icon: const Icon(Icons.shield_outlined, size: 20),
                                tooltip: isUrdu ? 'سپر ایڈمن پینل (SaaS)' : 'Super Admin (SaaS)',
                                color: const Color(0xFFD97706),
                                onPressed: _showSuperAdminOptions,
                              ),
                            ],
                          ),
                          const SizedBox(height: 5),
                          Container(
                            width: 88,
                            height: 88,
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: primaryGreen.withValues(alpha: 0.18),
                                  blurRadius: 16,
                                  offset: const Offset(0, 6),
                                ),
                              ],
                            ),
                            child: const Center(
                              child: AppBrandLogo(size: 72),
                            ),
                          ),
                          const SizedBox(height: 15),
                          ValueListenableBuilder<String?>(
                            valueListenable: currentTenantName,
                            builder: (ctx, tName, _) {
                              final title = (tName != null && tName.isNotEmpty)
                                  ? tName
                                  : (isUrdu ? 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)' : 'MC-MMS (Mindcrafts Madrasa Management System)');
                              return Text(
                                title,
                                style: TextStyle(
                                  fontSize: 22,
                                  color: primaryGreen,
                                  fontWeight: FontWeight.bold,
                                ),
                                textAlign: TextAlign.center,
                              );
                            },
                          ),
                          const SizedBox(height: 3),
                          Text(
                            isUrdu
                                ? 'جامع نظامِ انتظام و تعلیم (Management ERP)'
                                : 'Integrated Management System (ERP)',
                            style: TextStyle(
                              fontSize: 12,
                              color: primaryGreen.withValues(alpha: 0.8),
                              fontWeight: FontWeight.w600,
                            ),
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 6),
                          Text(
                            isUrdu
                                ? 'اپنے اکاؤنٹ میں داخل ہوں'
                                : 'Login to your account',
                            style: const TextStyle(color: Colors.black54),
                          ),
                          const SizedBox(height: 20),
                          if (_errorMessage.isNotEmpty)
                            Container(
                              width: double.infinity,
                              padding: const EdgeInsets.all(10),
                              margin: const EdgeInsets.only(bottom: 15),
                              decoration: BoxDecoration(
                                color: Colors.red.withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: Colors.red),
                              ),
                              child: Text(
                                _errorMessage,
                                style: const TextStyle(color: Colors.red),
                              ),
                            ),
                          if (isMultiTenantEnabled.value) ...[
                            Directionality(
                              textDirection: TextDirection.ltr,
                              child: TextField(
                                controller: _tenantCodeController,
                                textDirection: TextDirection.ltr,
                                textAlign: TextAlign.start,
                                decoration: InputDecoration(
                                  labelText: isUrdu
                                      ? 'ادارہ کوڈ (Institute Code)'
                                      : 'Institute Code',
                                  hintText: 'M-26-001 / MAIN01',
                                  helperText: isUrdu
                                      ? 'مرکزی ادارہ کوڈ MAIN01 ہے'
                                      : 'Default Code: MAIN01',
                                  prefixIcon: const Icon(Icons.domain),
                                  suffixIcon: IconButton(
                                    icon: const Icon(Icons.clear, size: 18),
                                    onPressed: () {
                                      _tenantCodeController.clear();
                                    },
                                  ),
                                  border: const OutlineInputBorder(),
                                ),
                              ),
                            ),
                            const SizedBox(height: 15),
                          ],
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: TextField(
                              controller: _usernameController,
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.start,
                              decoration: InputDecoration(
                                labelText: isUrdu
                                    ? 'یوزر نیم / داخلہ نمبر'
                                    : 'Username / admission no',
                                prefixIcon: const Icon(Icons.person),
                                border: const OutlineInputBorder(),
                              ),
                            ),
                          ),
                          const SizedBox(height: 15),
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: TextField(
                              controller: _passwordController,
                              obscureText: _isObscure,
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.start,
                              decoration: InputDecoration(
                                labelText: isUrdu ? 'پاس ورڈ' : 'Password',
                                prefixIcon: const Icon(Icons.lock),
                                suffixIcon: IconButton(
                                  icon: Icon(
                                    _isObscure
                                        ? Icons.visibility_off
                                        : Icons.visibility,
                                  ),
                                  onPressed: () {
                                    setState(() {
                                      _isObscure = !_isObscure;
                                    });
                                  },
                                ),
                                border: const OutlineInputBorder(),
                              ),
                            ),
                          ),
                      Align(
                        alignment: isUrdu
                            ? Alignment.centerLeft
                            : Alignment.centerRight,
                        child: TextButton(
                          onPressed: _resetPassword,
                          child: Text(
                            isUrdu ? 'پاس ورڈ بھول گئے؟' : 'Forgot Password?',
                            style: TextStyle(
                              color: primaryGreen,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 10),
                      SizedBox(
                        width: double.infinity,
                        height: 50,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                          ),
                          onPressed: _isLoading ? null : _login,
                          child: _isLoading
                              ? const CircularProgressIndicator(
                                  color: Colors.white,
                                )
                              : Text(
                                  isUrdu ? 'لاگ ان کریں' : 'Login',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.bold,
                                    fontSize: 18,
                                  ),
                                ),
                        ),
                      ),
                      const SizedBox(height: 15),
                      TextButton(
                        onPressed: () {
                          Navigator.pushReplacement(
                            context,
                            MaterialPageRoute(
                              builder: (_) => const HomePage(),
                            ),
                          );
                        },
                        child: Text(
                          isUrdu ? 'ہوم پر واپس جائیں' : 'Back to Home',
                          style: const TextStyle(color: Colors.grey),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  },
);
  }
}
