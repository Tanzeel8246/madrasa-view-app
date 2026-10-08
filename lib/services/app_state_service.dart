import 'package:flutter/foundation.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../utils/session.dart';

/// مرکزی ایپ اسٹیٹ کنٹرولر (Central App State Controller)
/// زبان، ڈارک موڈ، سسٹم ٹائپ اور سیشن اسٹیٹس کو ایک منظم اور ری ایکٹو سروس میں یکجا کرتا ہے
class AppStateService extends ChangeNotifier {
  static final AppStateService _instance = AppStateService._internal();
  static AppStateService get instance => _instance;

  AppStateService._internal() {
    // دو طرفہ مطابقت برائے گلوبل ValueNotifiers
    isUrduGlobal.addListener(_onUrduChanged);
    isDarkMode.addListener(_onThemeChanged);
    SystemTypeGlobal.currentSystemType.addListener(_onSystemTypeChanged);
    currentUserRole.addListener(_onUserRoleChanged);
  }

  void _onUrduChanged() => notifyListeners();
  void _onThemeChanged() => notifyListeners();
  void _onSystemTypeChanged() => notifyListeners();
  void _onUserRoleChanged() => notifyListeners();

  // زبان (Language)
  bool get isUrdu => isUrduGlobal.value;
  void setUrdu(bool val) {
    if (isUrduGlobal.value != val) {
      isUrduGlobal.value = val;
      notifyListeners();
    }
  }

  void toggleLanguage() => setUrdu(!isUrdu);

  // تھیم (Dark Mode)
  bool get isDark => isDarkMode.value;
  void setDarkMode(bool val) {
    if (isDarkMode.value != val) {
      isDarkMode.value = val;
      notifyListeners();
    }
  }

  void toggleDarkMode() => setDarkMode(!isDark);

  // سسٹم ٹائپ (Madrasa vs Masjid)
  String get systemType => SystemTypeGlobal.currentSystemType.value;
  bool get isMadrasa => SystemTypeGlobal.currentSystemType.value == 'Madrasa';
  bool get isMasjid => SystemTypeGlobal.currentSystemType.value == 'Masjid';

  void setSystemType(String type) {
    if (SystemTypeGlobal.currentSystemType.value != type) {
      SystemTypeGlobal.currentSystemType.value = type;
      notifyListeners();
    }
  }

  // یوزر سیشن معلومات
  String? get role => currentUserRole.value;
  int? get userId => currentUserId.value;
  String? get userName => currentUserName.value;
  List<String> get permissions => currentUserPermissions.value;
  List<String> get assignedClasses => currentUserAssignedClasses.value;
  String? get token => currentUserToken.value;

  bool get isLoggedIn => currentUserRole.value != null && currentUserId.value != null;
  bool get isAdmin => currentUserRole.value == 'admin';
  bool get isTeacher => currentUserRole.value == 'teacher';
  bool get isParent => currentUserRole.value == 'parent';
  bool get isStudent => currentUserRole.value == 'student';

  /// مکمل لاگ ان اسٹیٹ کو سیٹ کریں
  void setUserSession({
    required String role,
    required int userId,
    String? name,
    String? token,
    List<String>? permissions,
    List<String>? assignedClasses,
  }) {
    currentUserRole.value = role;
    currentUserId.value = userId;
    currentUserName.value = name;
    currentUserToken.value = token;
    currentUserPermissions.value = permissions ?? [];
    currentUserAssignedClasses.value = assignedClasses ?? [];
    notifyListeners();
  }

  /// محفوظ لاگ آؤٹ اور سیشن کلیئرنس
  Future<void> logout() async {
    await SessionManager.clearSession();
    currentUserRole.value = null;
    currentUserId.value = null;
    currentUserName.value = null;
    currentUserToken.value = null;
    currentUserPermissions.value = [];
    currentUserAssignedClasses.value = [];
    notifyListeners();
  }
}
