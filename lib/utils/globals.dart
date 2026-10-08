// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';

// پوری ایپ میں زبان کنٹرول کرنے والا ویری ایبل
ValueNotifier<bool> isUrduGlobal = ValueNotifier(true);

// ڈارک موڈ کنٹرول ویری ایبل
ValueNotifier<bool> isDarkMode = ValueNotifier(false);

// یوزر لاگ ان سٹیٹ
ValueNotifier<String?> currentUserRole =
    ValueNotifier(null); // 'admin', 'nazim', 'teacher', 'student' or 'parent'
ValueNotifier<int?> currentUserId = ValueNotifier(null);
ValueNotifier<String?> currentUserName = ValueNotifier(null);
ValueNotifier<List<String>> currentUserPermissions = ValueNotifier([]);
ValueNotifier<String?> currentUserToken = ValueNotifier(null);
// Teacher کی allowed class names (UI filtering کے لیے) — admin/nazim کے لیے خالی رہتا ہے
ValueNotifier<List<String>> currentUserAssignedClasses = ValueNotifier([]);

// ملٹی ٹیننٹ SaaS متغیرات
// ملٹی ٹیننٹ (SaaS) موڈ سوئچ — لائیو سینٹرل سسٹم کے لیے فعال (true)
ValueNotifier<bool> isMultiTenantEnabled = ValueNotifier(true);
ValueNotifier<String?> currentTenantCode = ValueNotifier(null);
ValueNotifier<int?> currentTenantId = ValueNotifier(null);
ValueNotifier<String?> currentTenantName = ValueNotifier(null);
ValueNotifier<String?> currentTenantLogoPath = ValueNotifier(null);
ValueNotifier<String?> currentTenantMasjidName = ValueNotifier(null);
ValueNotifier<String?> currentTenantMasjidLogoPath = ValueNotifier(null);
// سوپر ایڈمن کی طرف سے الاٹ شدہ ماڈیولز کی فہرست
ValueNotifier<List<String>> currentTenantAllowedModules = ValueNotifier([]);

// SaaS سبسکرپشن و ٹرائل گلوبل متغیرات
ValueNotifier<bool> currentTenantIsTrial = ValueNotifier(false);
ValueNotifier<int> currentTenantTrialDaysLeft = ValueNotifier(0);
ValueNotifier<String> currentTenantPlanName = ValueNotifier('بنیادی پیکج (Basic)');
ValueNotifier<String> currentTenantPlanCode = ValueNotifier('basic');
ValueNotifier<String> currentTenantSubscriptionEndsAt = ValueNotifier('');
ValueNotifier<String> currentTenantSubscriptionStatus = ValueNotifier('active');

/// ماڈیول چیکر: اگر سوپر ایڈمن نے ماڈیول بند کر رکھا ہو تو false واپس کرتا ہے
bool isTenantModuleAllowed(String moduleKey) {
  // اگر ملٹی ٹیننٹ موڈ ہی غیر فعال ہو تو تمام ماڈیولز مکمل کھلے ہیں
  if (!isMultiTenantEnabled.value) {
    return true;
  }
  // اگر سوپر ایڈمن خود ہو تو تمام اختیارات کھلے ہیں
  if (currentUserRole.value == 'admin' && currentUserId.value == 1) {
    return true;
  }
  // اگر لسٹ ابھی خالی ہو (مثلاً پرانا کیش یا بغیر پابندی کے)، تو ڈیفالٹ میں کھلا رکھیں
  if (currentTenantAllowedModules.value.isEmpty) {
    return true;
  }
  return currentTenantAllowedModules.value.contains(moduleKey);
}

// Configure these with --dart-define for release builds.
const String apiUrl = String.fromEnvironment(
  'MADRASA_API_URL',
  defaultValue: 'https://madrasa.mamfss.com/',
);
const String apiSecretKey = String.fromEnvironment('MADRASA_API_SECRET',
    defaultValue: '');

/// فارغ التحصیل طالب علم کی کیٹیگری حل کرنے والا مرکزی گلوبل فنکشن
String resolveAlumniCategory(Map<String, dynamic> s) {
  final cat = (s['alumni_category'] ?? '').toString().toLowerCase();
  final reason = (s['leaving_reason'] ?? '').toString().toLowerCase();
  final status = (s['status'] ?? '').toString().toLowerCase();

  if (cat == 'expelled' || cat.contains('اخراج') || cat.contains('خارج')) {
    return 'expelled';
  }
  if (cat == 'dropped' || cat.contains('چھوڑ') || cat.contains('ترک') || cat.contains('شوقیہ')) {
    return 'dropped';
  }
  if (reason.contains('خارج') || reason.contains('اخراج') || status.contains('خارج') || status == 'struck off' || status == 'expelled') {
    return 'expelled';
  }
  if (reason.contains('چھوڑ') || reason.contains('شوقیہ') || reason.contains('ترک') || status == 'left' || status == 'dropped') {
    return 'dropped';
  }
  return 'completed';
}
