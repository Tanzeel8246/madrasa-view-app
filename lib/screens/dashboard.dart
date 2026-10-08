// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;

import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../utils/globals.dart';
import '../utils/session.dart';
import '../utils/sync_service.dart';
import '../utils/sync_ui_helper.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import '../widgets/blinking_trial_beacon.dart';
import 'subscription_status.dart';
import 'academic_entry_dashboard.dart';
import 'apply_leave.dart';
import 'attendance.dart';
import 'classes.dart';
import 'complaints.dart';
import 'exams.dart';
import 'manage_users.dart';
import 'notices.dart';
import 'pay_fee.dart';
import 'requests.dart';
import 'settings.dart';
import 'student_report.dart';
import 'students.dart';
import 'teachers.dart';
import 'masjid_itikaf.dart';
import 'smart_notifications.dart';
import 'teacher_leaves.dart';
import 'maktab_attendance.dart';
import 'maktab_students.dart';
import 'maktab_teachers.dart';
import 'maktab_classes.dart';
import 'maktab_reports_attendance.dart';
import 'maktab_academic_entry_dashboard.dart';
import 'maktab_academic_report.dart';
import 'maktab_exams.dart';
import 'teacher_attendance_report.dart';
import 'maktab_teacher_attendance_report.dart';
import 'library_screen.dart';
import 'inventory_screen.dart';
import 'hostel_screen.dart';
import 'recruitment_pipeline.dart';
import 'academic_view_dashboard.dart';
import 'finance.dart';
import 'masjid_finance_dashboard.dart';
import '../services/smart_alert_service.dart';


class DashboardPage extends StatefulWidget {
  final bool autoSync;
  const DashboardPage({super.key, this.autoSync = false});

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardCard {
  const _DashboardCard({
    required this.permission,
    required this.urTitle,
    required this.enTitle,
    required this.icon,
    required this.page,
    this.value = '',
  });

  final String permission;
  final String urTitle;
  final String enTitle;
  final IconData icon;
  final Widget page;
  final String value;
}

class _DashboardPageState extends State<DashboardPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  String _userName = '';
  String _userRoleDisplay = '';
  String _institutionName = '';
  int studentsCount = 0;
  int teachersCount = 0;
  int todayAttendanceCount = 0;
  String todayAttendanceSummary = '';
  double monthlyAttendancePercent = 0.0;
  int alumniCount = 0;
  int noticesCount = 0;
  int classesCount = 0;
  double monthlyExpenses = 0.0;
  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  int _smartAlertsCount = 0;

  // Phase 1 Widgets State
  int _totalClassesCount = 0;
  int _markedClassesCount = 0;
  List<String> _remainingClassNames = [];
  int _teacherPresentCount = 0;
  int _teacherLeaveCount = 0;
  int _teacherAbsentCount = 0;
  List<Map<String, dynamic>> _topStudents = [];
  List<Map<String, dynamic>> _recentActivities = [];

  @override
  void initState() {
    super.initState();
    if (!isTenantModuleAllowed('masjid_wing') && SystemTypeGlobal.currentSystemType.value == 'Masjid') {
      SystemTypeGlobal.currentSystemType.value = 'Madrasa';
    }
    SystemTypeGlobal.currentSystemType.addListener(_loadRealData);
    _loadRealData();
    _checkInitialAutoSync();
  }

  @override
  void dispose() {
    SystemTypeGlobal.currentSystemType.removeListener(_loadRealData);
    super.dispose();
  }

  Future<void> _checkInitialAutoSync() async {
    final lastSync = await SyncService.getLastSyncTime();
    // Automatically perform full sync on very first launch (when lastSync is null) or if autoSync flag is set
    if (widget.autoSync || lastSync == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && !_isSyncing) {
          _runLiveTwoWaySync();
        }
      });
    }
  }

  String _formatLastSyncTime(bool isUrdu) {
    if (_lastSyncTime == null) return isUrdu ? 'کبھی نہیں' : 'Never';
    final diff = DateTime.now().difference(_lastSyncTime!);
    if (diff.inMinutes < 1) return isUrdu ? 'ابھی ابھی' : 'Just now';
    if (diff.inMinutes < 60) {
      return isUrdu ? '${diff.inMinutes} منٹ پہلے' : '${diff.inMinutes}m ago';
    }
    if (diff.inHours < 24) {
      return isUrdu ? '${diff.inHours} گھنٹے پہلے' : '${diff.inHours}h ago';
    }
    return intl.DateFormat('dd-MMM hh:mm a').format(_lastSyncTime!);
  }

  bool _hasPerm(String permission) {
    const permToModuleMap = {
      'manage_classes': 'classes',
      'manage_students': 'students',
      'manage_teachers': 'teachers',
      'manage_attendance': 'attendance',
      'manage_hifz': 'hifz',
      'manage_academics': 'hifz',
      'manage_exams': 'exams',
      'manage_notices': 'notices',
      'manage_requests': 'requests',
      'add_complaint': 'requests',
      'manage_finance': 'simple_finance',
      'manage_masjid_finance': 'simple_finance',
      'manage_masjid_assets': 'assets_donations',
      'manage_assets': 'assets_donations',
      'manage_in_kind_donations': 'assets_donations',
      'manage_masjid_stipends': 'payroll',
      'manage_stipends': 'payroll',
      'manage_masjid_loans': 'loans',
      'manage_loans': 'loans',
      'manage_erp': 'erp_accounts',
      'manage_masjid_itikaf': 'masjid_wing',
      'manage_itikaf': 'masjid_wing',
    };

    bool isModuleAllowedForPerm(String p) {
      final mod = permToModuleMap[p];
      if (mod != null) {
        return isTenantModuleAllowed(mod);
      }
      return true;
    }

    if (permission.contains('|')) {
      final parts = permission.split('|');
      final anyModuleAllowed = parts.any((p) => isModuleAllowedForPerm(p));
      if (!anyModuleAllowed) return false;
    } else {
      if (!isModuleAllowedForPerm(permission)) return false;
    }

    if (currentUserRole.value == 'admin' || currentUserRole.value == 'superadmin') return true;
    if (currentUserPermissions.value.contains('all')) return true;
    
    bool checkPerm(String p) {
      if (SystemTypeGlobal.currentSystemType.value == 'Masjid') {
        if (!p.startsWith('manage_masjid_')) {
          if (p == 'add_complaint') {
            p = 'manage_masjid_add_complaint';
          } else {
            p = p.replaceFirst('manage_', 'manage_masjid_');
          }
        }
      }
      if (currentUserRole.value == 'nazim') {
        const nazimBase = {
          'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
          'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
          'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
          'manage_students', 'manage_teachers', 'manage_classes',
          'manage_finance_entry', 'manage_finance_report',
          'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
          'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
          'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
          'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
          'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes',
          'manage_masjid_finance_entry', 'manage_masjid_finance_report',
          'manage_masjid_requests', 'manage_masjid_notices', 'manage_masjid_admissions', 'manage_masjid_complaints', 'manage_masjid_add_complaint'
        };
        if (nazimBase.contains(p)) return true;
      }
      return currentUserPermissions.value.contains(p);
    }

    if (permission.contains('|')) {
      return permission.split('|').any((p) => checkPerm(p));
    }
    return checkPerm(permission);
  }

  bool get _isStaff =>
      ['admin', 'superadmin', 'nazim', 'teacher'].contains(currentUserRole.value);
  bool get _isParent => currentUserRole.value == 'parent';

  Future<void> _runLiveTwoWaySync() async {
    if (_isSyncing || !mounted) return;
    setState(() => _isSyncing = true);
    try {
      await SyncUIHelper.performManualSync(context);
      await _loadRealData();
    } finally {
      if (mounted) setState(() => _isSyncing = false);
    }
  }

  Future<void> _loadRealData() async {
    try {
      final db = DBHelper();
      final database = await db.database;

      final sysVal = SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sysVal == 'Masjid';
      final sysCond = isMasjid
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      // 0. Immediately resolve institution name for instant UI reactivity
      String institutionName = '';
      try {
        final sMap = await db.getSettings();
        if (isMasjid) {
          institutionName = (currentTenantMasjidName.value != null && currentTenantMasjidName.value!.trim().isNotEmpty)
              ? currentTenantMasjidName.value!.trim()
              : ((sMap['masjid_name'] != null && sMap['masjid_name'].toString().trim().isNotEmpty)
                  ? sMap['masjid_name'].toString().trim()
                  : (isUrduGlobal.value ? 'مسجد اشرف المدارس' : 'Masjid Ashraf-ul-Madaris'));
        } else {
          institutionName = (currentTenantName.value != null && currentTenantName.value!.trim().isNotEmpty)
              ? currentTenantName.value!.trim()
              : ((sMap['madrasa_name'] != null && sMap['madrasa_name'].toString().trim().isNotEmpty)
                  ? sMap['madrasa_name'].toString().trim()
                  : (isUrduGlobal.value ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris'));
        }
      } catch (_) {
        institutionName = isMasjid 
            ? (currentTenantMasjidName.value ?? (isUrduGlobal.value ? 'مسجد اشرف المدارس' : 'Masjid Ashraf-ul-Madaris'))
            : (currentTenantName.value ?? (isUrduGlobal.value ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris'));
      }

      if (mounted) {
        setState(() {
          _institutionName = institutionName;
        });
      }

      // 1. Get counts safely using SQL COUNT
      int activeStudentsCount = 0;
      try {
        final activeStudentsCountRes = isMasjid
            ? await database.rawQuery(
                "SELECT COUNT(*) as count FROM (SELECT id FROM students WHERE LOWER(TRIM(status)) IN ('active', 'present', '') AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' UNION SELECT id FROM maktab_students WHERE LOWER(TRIM(status)) IN ('active', 'present', '') AND (is_deleted = 0 OR is_deleted IS NULL))")
            : await database.rawQuery(
                "SELECT COUNT(*) as count FROM students WHERE LOWER(TRIM(status)) IN ('active', 'present', '') AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
        activeStudentsCount =
            (activeStudentsCountRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {
        try {
          final fallbackRes = await database.rawQuery(
              "SELECT COUNT(*) as count FROM students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
          activeStudentsCount =
              (fallbackRes.first['count'] as num?)?.toInt() ?? 0;
        } catch (_) {}
      }

      int activeAlumniCount = 0;
      try {
        final alumniCountRes = await database.rawQuery(
            "SELECT COUNT(*) as count FROM students WHERE LOWER(TRIM(status)) IN ('alumni', 'graduated') AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
        activeAlumniCount =
            (alumniCountRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {}

      int activeTeachersCount = 0;
      try {
        final activeTeachersCountRes = isMasjid
            ? await database.rawQuery(
                "SELECT COUNT(*) as count FROM (SELECT id FROM teachers WHERE (LOWER(status) IN ('active', '') OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' UNION SELECT id FROM maktab_teachers WHERE (LOWER(status) IN ('active', '') OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL))")
            : await database.rawQuery(
                "SELECT COUNT(*) as count FROM teachers WHERE (LOWER(status) IN ('active', '') OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
        activeTeachersCount =
            (activeTeachersCountRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {
        try {
          final fallbackRes = await database.rawQuery(
              "SELECT COUNT(*) as count FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
          activeTeachersCount =
              (fallbackRes.first['count'] as num?)?.toInt() ?? 0;
        } catch (_) {}
      }

      int activeNoticesCount = 0;
      try {
        final noticesCountRes = await database.rawQuery(
            "SELECT COUNT(*) as count FROM notices WHERE (is_deleted = 0 OR is_deleted IS NULL)");
        activeNoticesCount =
            (noticesCountRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {}

      int activeClassesCount = 0;
      try {
        final classesCountRes = isMasjid
            ? await database.rawQuery(
                "SELECT COUNT(*) as count FROM (SELECT id FROM classes WHERE (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' UNION SELECT id FROM maktab_classes WHERE (is_deleted = 0 OR is_deleted IS NULL))")
            : await database.rawQuery(
                "SELECT COUNT(*) as count FROM classes WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
        activeClassesCount =
            (classesCountRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {
        try {
          final fallbackRes = await database.rawQuery(
              "SELECT COUNT(*) as count FROM classes WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
          activeClassesCount =
              (fallbackRes.first['count'] as num?)?.toInt() ?? 0;
        } catch (_) {}
      }

      // 2. Query monthly expenses safely using SQL SUM
      final now = DateTime.now();
      final currentYearMonth =
          "${now.year}-${now.month.toString().padLeft(2, '0')}";
      double monthlyExp = 0.0;
      try {
        final monthlyExpensesRes = isMasjid
            ? await database.rawQuery(
                "SELECT SUM(amount) as sum FROM masjid_finance WHERE LOWER(TRIM(type)) = 'expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND date LIKE ?",
                ['$currentYearMonth%'])
            : await database.rawQuery(
                "SELECT SUM(amount) as sum FROM finance WHERE LOWER(TRIM(type)) = 'expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond AND date LIKE ?",
                ['$currentYearMonth%']);
        monthlyExp =
            (monthlyExpensesRes.first['sum'] as num?)?.toDouble() ?? 0.0;
      } catch (_) {
        try {
          final fallbackFinRes = await database.rawQuery(
              "SELECT SUM(amount) as sum FROM finance WHERE LOWER(TRIM(type)) = 'expense' AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond AND date LIKE ?",
              ['$currentYearMonth%']);
          monthlyExp =
              (fallbackFinRes.first['sum'] as num?)?.toDouble() ?? 0.0;
        } catch (_) {}
      }

    // 3. Query today's attendance directly from DB (distinct per active student to prevent period duplication)
    final todayStr = intl.DateFormat('yyyy-MM-dd').format(now);
    final todayStudentAttRows = isMasjid
        ? await database.rawQuery('''
            SELECT a.student_id, a.status, a.delay_minutes 
            FROM (
              SELECT student_id, status, delay_minutes, date, is_deleted 
              FROM attendance 
              WHERE (system_type = 'Masjid')
              UNION ALL 
              SELECT student_id, status, '' as delay_minutes, date, is_deleted 
              FROM maktab_attendance
            ) a
            JOIN (
              SELECT id FROM students WHERE system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) AND LOWER(TRIM(status)) IN ('active', 'present', '')
              UNION
              SELECT id FROM maktab_students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND LOWER(TRIM(status)) IN ('active', 'present', '')
            ) s ON a.student_id = s.id
            WHERE a.date = ? AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
          ''', [todayStr])
        : await database.rawQuery('''
            SELECT a.student_id, a.status, a.delay_minutes 
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date = ? 
              AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
              AND LOWER(TRIM(s.status)) IN ('active', 'present', '')
              AND (s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')
          ''', [todayStr]);

    final Map<String, List<Map<String, dynamic>>> studentAttMap = {};
    for (final r in todayStudentAttRows) {
      final sId = r['student_id']?.toString() ?? '';
      if (sId.isEmpty) continue;
      studentAttMap.putIfAbsent(sId, () => []).add(r);
    }

    var todayPresent = 0;
    var todayAbsent = 0;
    var todayLeave = 0;
    var todayLate = 0;

    for (final entries in studentAttMap.values) {
      bool hasAbsent = false;
      bool hasLeave = false;
      bool hasLate = false;
      bool hasPresent = false;

      for (final e in entries) {
        final s = (e['status'] ?? '').toString().toUpperCase().trim();
        final delayStr = (e['delay_minutes'] ?? '').toString();
        final delay = int.tryParse(delayStr) ?? 0;

        if (delay > 0 || s == 'LATE' || s.contains('تاخیر')) {
          hasLate = true;
        }
        if (s == 'A' || s.contains('ABSENT') || s.contains('غائب') || s.contains('غیر')) {
          hasAbsent = true;
        } else if (s == 'L' || s == 'LA' || s.contains('LEAVE') || s.contains('رخصت')) {
          hasLeave = true;
        } else if (s == 'P' || s.contains('PRESENT') || s.contains('حاضر')) {
          hasPresent = true;
        }
      }

      if (hasLeave) {
        todayLeave++;
      } else if (hasAbsent) {
        todayAbsent++;
      } else if (hasLate) {
        todayLate++;
      } else if (hasPresent) {
        todayPresent++;
      }
    }

    final todayTotal = todayPresent + todayAbsent + todayLeave + todayLate;

    String pct(int value) {
      if (todayTotal == 0) return '\u200E(0%)';
      final p = (value / todayTotal * 100).toStringAsFixed(0);
      return '\u200E($p%)';
    }

    final todaySummary = isUrduGlobal.value
        ? 'حاضر: $todayPresent ${pct(todayPresent)} | غیرحاضر: $todayAbsent ${pct(todayAbsent)}\nرخصت: $todayLeave ${pct(todayLeave)} | تاخیر: $todayLate ${pct(todayLate)}'
        : 'P: $todayPresent ${pct(todayPresent)} | A: $todayAbsent ${pct(todayAbsent)}\nL: $todayLeave ${pct(todayLeave)} | Late: $todayLate ${pct(todayLate)}';

    final monthlyAtts = isMasjid
        ? await database.rawQuery(
            "SELECT a.status FROM (SELECT status FROM attendance WHERE date LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' UNION ALL SELECT status FROM maktab_attendance WHERE date LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL)) a",
            ['$currentYearMonth%', '$currentYearMonth%'])
        : await database.rawQuery(
            "SELECT status FROM attendance WHERE date LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
            ['$currentYearMonth%']);
    
    var mTotal = 0;
    var mPresent = 0;
    for (final a in monthlyAtts) {
      final s = (a['status'] ?? '').toString().toUpperCase();
      if (s.isEmpty) continue;
      mTotal++;
      if (s == 'P' || s.contains('حاضر')) mPresent++;
    }
    
    final monthlyAttPct = mTotal > 0 ? (mPresent / mTotal) * 100 : 0.0;
    double monthlyPercent = monthlyAttPct;

    // 5. Query user name, role and institution
    String displayName = currentUserName.value?.trim() ?? '';
    if (displayName.isEmpty) {
      if (currentUserRole.value == 'student' && currentUserId.value != null) {
        final studentRes = await database.rawQuery(
            "SELECT name FROM students WHERE id = ? AND $sysCond LIMIT 1",
            [currentUserId.value.toString()]);
        if (studentRes.isNotEmpty) {
          displayName = studentRes.first['name']?.toString() ?? '';
        }
      } else if (currentUserRole.value == 'teacher' && currentUserId.value != null) {
        final teacherRes = await database.rawQuery(
            "SELECT name FROM teachers WHERE id = ? AND $sysCond LIMIT 1",
            [currentUserId.value.toString()]);
        if (teacherRes.isNotEmpty) {
          displayName = teacherRes.first['name']?.toString() ?? '';
        }
      }
    }
    if (displayName.isEmpty) {
      final r = (currentUserRole.value ?? 'admin').toLowerCase();
      if (r == 'admin' || r == 'superadmin') {
        displayName = isUrduGlobal.value ? 'ایڈمنسٹریٹر' : 'Administrator';
      } else if (r == 'parent') {
        displayName = isUrduGlobal.value ? 'محترم والدین' : 'Parent';
      } else if (r == 'teacher') {
        displayName = isUrduGlobal.value ? 'استاد محترم' : 'Teacher';
      } else if (r == 'student') {
        displayName = isUrduGlobal.value ? 'طالب علم' : 'Student';
      } else {
        displayName = isUrduGlobal.value ? 'صارف' : 'User';
      }
    }

    String roleLabel = (currentUserRole.value ?? 'admin').toLowerCase();
    String roleDisplay = roleLabel;
    if (isUrduGlobal.value) {
      switch (roleLabel) {
        case 'admin':
          roleDisplay = 'ایڈمن';
          break;
        case 'superadmin':
          roleDisplay = 'سپر ایڈمن';
          break;
        case 'nazim':
          roleDisplay = 'ناظم تعلیمات';
          break;
        case 'teacher':
          roleDisplay = 'استاد';
          break;
        case 'student':
          roleDisplay = 'طالب علم';
          break;
        case 'parent':
          roleDisplay = 'والدین';
          break;
        case 'accountant':
          roleDisplay = 'اکاؤنٹنٹ';
          break;
        case 'operator':
          roleDisplay = 'آپریٹر';
          break;
        default:
          roleDisplay = roleLabel;
      }
    } else {
      roleDisplay = roleLabel.toUpperCase();
    }

    if (!mounted) return;

    // For parents, load only their own children from DB
    List<Map<String, dynamic>> parentChildrenList = [];
    if (currentUserRole.value == 'parent') {
      final parentStdIds = await SessionManager.getParentStudentIds();
      final parentPhone = currentUserName.value ?? '';

      if (parentStdIds.isNotEmpty) {
        final placeholders = List.filled(parentStdIds.length, '?').join(',');
        parentChildrenList = await database.query(
          'students',
          where: 'id IN ($placeholders) AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: parentStdIds,
        );
      } else if (parentPhone.isNotEmpty) {
        parentChildrenList = await database.query(
          'students',
          where: '(guardian_phone = ? OR phone = ?) AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: [parentPhone, parentPhone],
        );
      }
    }

    final lastSync = await SyncService.getLastSyncTime();

    int smartAlertsCount = 0;
    try {
      final alerts = await SmartAlertService().getAllAlerts();
      smartAlertsCount = alerts.where((a) => !a.isRead).length;
    } catch (_) {}

    // Phase 1: Class Attendance Progress for Today
    int totalCls = 0;
    int markedCls = 0;
    List<String> remainingCls = [];
    try {
      List<Map<String, dynamic>> allClasses = [];
      if (isMasjid) {
        allClasses = await db.getMaktabClasses();
      } else {
        allClasses = await database.query('classes',
            where: '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = \'Madrasa\' OR system_type IS NULL OR system_type = \'\')',
            orderBy: 'name ASC');
      }
      totalCls = allClasses.length;

      final Set<String> markedNames = {};
      if (isMasjid) {
        final m1 = await database.rawQuery(
          "SELECT DISTINCT TRIM(class_name) as cname FROM maktab_attendance WHERE date = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND class_name IS NOT NULL AND class_name != ''",
          [todayStr],
        );
        final m2 = await database.rawQuery(
          "SELECT DISTINCT TRIM(s.class_name) as cname FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND s.class_name IS NOT NULL AND s.class_name != '' AND s.system_type = 'Masjid'",
          [todayStr],
        );
        for (var r in m1) {
          final n = (r['cname'] ?? '').toString().trim();
          if (n.isNotEmpty) markedNames.add(n);
        }
        for (var r in m2) {
          final n = (r['cname'] ?? '').toString().trim();
          if (n.isNotEmpty) markedNames.add(n);
        }
      } else {
        final markedRows = await database.rawQuery(
          "SELECT DISTINCT TRIM(s.class_name) as cname FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND s.class_name IS NOT NULL AND s.class_name != '' AND (s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')",
          [todayStr],
        );
        for (var r in markedRows) {
          final n = (r['cname'] ?? '').toString().trim();
          if (n.isNotEmpty) markedNames.add(n);
        }
      }

      for (var c in allClasses) {
        final cName = (c['name'] ?? '').toString().trim();
        if (markedNames.contains(cName)) {
          markedCls++;
        } else {
          remainingCls.add(cName);
        }
      }
    } catch (_) {}

    // Phase 1: Today's Teacher Attendance
    int tPres = 0;
    int tLeav = 0;
    int tAbs = 0;
    try {
      List<Map<String, dynamic>> tAttRows = [];
      if (isMasjid) {
        tAttRows = await database.rawQuery(
          "SELECT status, COUNT(*) as cnt FROM (SELECT status FROM maktab_teacher_attendance WHERE date = ? AND (is_deleted = 0 OR is_deleted IS NULL) UNION ALL SELECT at.status FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND (at.is_deleted = 0 OR at.is_deleted IS NULL) AND t.system_type = 'Masjid') GROUP BY status",
          [todayStr, todayStr],
        );
      } else {
        tAttRows = await database.rawQuery(
          "SELECT at.status, COUNT(*) as cnt FROM attendance_teachers at JOIN teachers t ON at.teacher_id = t.id WHERE at.date = ? AND (at.is_deleted = 0 OR at.is_deleted IS NULL) AND (t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '') GROUP BY at.status",
          [todayStr],
        );
      }
      for (var row in tAttRows) {
        final st = (row['status'] ?? '').toString().toUpperCase();
        final c = (row['cnt'] as num?)?.toInt() ?? 0;
        if (st == 'P' || st.contains('حاضر')) {
          tPres += c;
        } else if (st == 'L' || st == 'LA' || st.contains('رخصت')) {
          tLeav += c;
        } else if (st == 'A' || st.contains('غیر') || st.contains('غائب')) {
          tAbs += c;
        }
      }
    } catch (_) {}

    // Phase 1: Top Students
    List<Map<String, dynamic>> topStds = [];
    try {
      if (isMasjid) {
        topStds = await database.rawQuery('''
          SELECT s.id, s.name, s.father_name, s.class_name, ROUND(AVG(er.obtained_marks), 1) as avg_marks
          FROM exam_results er
          JOIN students s ON er.student_id = s.id
          WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) AND s.system_type = 'Masjid'
          GROUP BY s.id, s.name, s.father_name, s.class_name
          ORDER BY avg_marks DESC
          LIMIT 5
        ''');
        if (topStds.isEmpty) {
          topStds = await database.query('maktab_students',
              where: '(is_deleted = 0 OR is_deleted IS NULL) AND (status = \'Active\' OR status IS NULL OR status = \'\')',
              orderBy: 'id DESC',
              limit: 5);
        }
      } else {
        topStds = await database.rawQuery('''
          SELECT s.id, s.name, s.father_name, s.class_name, ROUND(AVG(er.obtained_marks), 1) as avg_marks
          FROM exam_results er
          JOIN students s ON er.student_id = s.id
          WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) AND (s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')
          GROUP BY s.id, s.name, s.father_name, s.class_name
          ORDER BY avg_marks DESC
          LIMIT 5
        ''');
        if (topStds.isEmpty) {
          topStds = await database.query('students',
              where: '(is_deleted = 0 OR is_deleted IS NULL) AND (status = \'Active\' OR status IS NULL OR status = \'\') AND (system_type = \'Madrasa\' OR system_type IS NULL OR system_type = \'\')',
              orderBy: 'id DESC',
              limit: 5);
        }
      }
    } catch (_) {}

    // Phase 1: Activity Logs
    List<Map<String, dynamic>> actLogs = [];
    try {
      actLogs = await db.getActivityLogs(limit: 6);
    } catch (_) {}

    setState(() {
      studentsCount = activeStudentsCount;
      teachersCount = activeTeachersCount;
      alumniCount = activeAlumniCount;
      noticesCount = activeNoticesCount;
      classesCount = activeClassesCount;
      todayAttendanceCount = todayTotal;
      todayAttendanceSummary = todaySummary;
      monthlyAttendancePercent = monthlyPercent;
      monthlyExpenses = monthlyExp;
      _userName = displayName;
      _userRoleDisplay = roleDisplay;
      _institutionName = institutionName;
      _parentChildren = parentChildrenList;
      _lastSyncTime = lastSync;
      _smartAlertsCount = smartAlertsCount;
      _totalClassesCount = totalCls;
      _markedClassesCount = markedCls;
      _remainingClassNames = remainingCls;
      _teacherPresentCount = tPres;
      _teacherLeaveCount = tLeav;
      _teacherAbsentCount = tAbs;
      _topStudents = topStds;
      _recentActivities = actLogs;
    });
    } catch (e, st) {
      debugPrint("_loadRealData Error: $e\n$st");
    }
  }

  List<Map<String, dynamic>> _parentChildren = [];
  List<_DashboardCard> _staffCards() {
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    List<_DashboardCard> cards;

    if (isMasjid) {
      cards = <_DashboardCard>[
        _DashboardCard(
          permission: 'manage_students',
          urTitle: 'مکتب طلباء',
          enTitle: 'Maktab Students',
          value: studentsCount.toString(),
          icon: Icons.school,
          page: const MaktabStudentsPage(),
        ),
        _DashboardCard(
          permission: 'manage_teachers',
          urTitle: 'مکتب اساتذہ',
          enTitle: 'Maktab Teachers',
          value: teachersCount.toString(),
          icon: Icons.people,
          page: const MaktabTeachersPage(),
        ),
        _DashboardCard(
          permission: 'manage_classes',
          urTitle: 'مکتب کلاسز',
          enTitle: 'Maktab Classes',
          value: classesCount.toString(),
          icon: Icons.class_,
          page: const MaktabClassesPage(),
        ),
        _DashboardCard(
          permission: 'manage_attendance',
          urTitle: 'رجسٹر حاضری',
          enTitle: 'Attendance Register',
          value: todayAttendanceCount > 0 ? todayAttendanceCount.toString() : '',
          icon: Icons.fact_check,
          page: const MaktabAttendancePage(),
        ),
        _DashboardCard(
          permission: 'manage_attendance',
          urTitle: 'حاضری رپورٹ',
          enTitle: 'Attendance Report',
          icon: Icons.receipt_long,
          page: const ReportsMaktabAttendancePage(),
        ),
        _DashboardCard(
          permission: 'manage_hifz|manage_academics',
          urTitle: 'تعلیمی رپورٹ (اندراج)',
          enTitle: 'Academic Entry',
          icon: Icons.edit_document,
          page: const MaktabAcademicEntryDashboardPage(),
        ),
        _DashboardCard(
          permission: 'manage_hifz|manage_academics',
          urTitle: 'تعلیمی رپورٹ (دیکھیں)',
          enTitle: 'Academic View',
          icon: Icons.analytics,
          page: const MaktabAcademicReportPage(),
        ),
        _DashboardCard(
          permission: 'manage_masjid_finance|manage_masjid_finance_entry|manage_masjid_finance_report|manage_finance',
          urTitle: 'مسجد فائنانس و اکاؤنٹس',
          enTitle: 'Masjid Finance & Accounts',
          value: monthlyExpenses.toInt().toString(),
          icon: Icons.account_balance_wallet,
          page: const MasjidFinanceDashboardPage(),
        ),
        _DashboardCard(
          permission: 'manage_masjid_exams|manage_exams',
          urTitle: 'امتحانات',
          enTitle: 'Exams',
          icon: Icons.assignment,
          page: const MaktabExamsPage(),
        ),
        _DashboardCard(
          permission: 'manage_masjid_itikaf|manage_itikaf',
          urTitle: 'اعتکاف',
          enTitle: 'Itikaf',
          icon: Icons.mosque,
          page: const MasjidItikafPage(),
        ),
        _DashboardCard(
          permission: 'manage_students|manage_requests|admin',
          urTitle: 'اسمارٹ الرٹس و تنبیہات',
          enTitle: 'Smart Alerts',
          value: _smartAlertsCount.toString(),
          icon: Icons.notifications_active,
          page: const SmartNotificationsPage(),
        ),
        _DashboardCard(
          permission: 'manage_masjid_library|manage_library|admin',
          urTitle: 'کتب خانہ / لائبریری',
          enTitle: 'Library',
          icon: Icons.local_library,
          page: const LibraryScreen(),
        ),
      ];
    } else {
      cards = <_DashboardCard>[
        _DashboardCard(
          permission: 'manage_students',
          urTitle: 'زیر تعلیم طلبہ',
          enTitle: 'Enrolled Students',
          value: studentsCount.toString(),
          icon: Icons.school,
          page: const StudentsPage(),
        ),
        _DashboardCard(
          permission: 'manage_teachers',
          urTitle: 'اساتذہ',
          enTitle: 'Teachers',
          value: teachersCount.toString(),
          icon: Icons.people,
          page: const TeachersPage(),
        ),
        _DashboardCard(
          permission: 'manage_classes',
          urTitle: 'کلاسز',
          enTitle: 'Classes',
          value: classesCount.toString(),
          icon: Icons.class_,
          page: const ClassesPage(),
        ),
        _DashboardCard(
          permission: 'manage_attendance',
          urTitle: 'آج کی حاضری',
          enTitle: "Today's Attendance",
          value: todayAttendanceCount > 0 ? todayAttendanceCount.toString() : '',
          icon: Icons.fact_check,
          page: const AttendancePage(),
        ),
        _DashboardCard(
          permission: 'manage_hifz|manage_academics',
          urTitle: 'تعلیمی کارکردگی (اندراج)',
          enTitle: 'Academic Performance (Entry)',
          icon: Icons.edit_document,
          page: const AcademicEntryDashboardPage(),
        ),
        _DashboardCard(
          permission: 'manage_hifz|manage_academics|manage_academic_view|manage_academic_dashboard',
          urTitle: 'تعلیمی کارکردگی (رپورٹ / جائزہ)',
          enTitle: 'Academic Performance (Reports)',
          icon: Icons.analytics,
          page: const AcademicViewDashboardPage(),
        ),
        _DashboardCard(
          permission: 'manage_finance|manage_finance_entry|manage_finance_report|manage_erp',
          urTitle: 'مالیات و اکاؤنٹس',
          enTitle: 'Finance & Accounts',
          value: monthlyExpenses.toInt().toString(),
          icon: Icons.account_balance_wallet,
          page: const FinancePage(),
        ),
        _DashboardCard(
          permission: 'manage_exams',
          urTitle: 'امتحانات',
          enTitle: 'Exams',
          icon: Icons.assignment,
          page: const ExamsPage(),
        ),
        _DashboardCard(
          permission: 'add_complaint',
          urTitle: 'شکایت اندراج',
          enTitle: 'Complaint Entry',
          icon: Icons.report_problem,
          page: const ComplaintsPage(),
        ),
        _DashboardCard(
          permission: 'manage_requests',
          urTitle: 'درخواستیں و شکایات',
          enTitle: 'Requests & Complaints',
          icon: Icons.notifications_active,
          page: const RequestsPage(),
        ),
        _DashboardCard(
          permission: 'manage_notices',
          urTitle: 'اعلانات',
          enTitle: 'Notices',
          value: noticesCount.toString(),
          icon: Icons.notifications,
          page: const NoticesPage(),
        ),
        _DashboardCard(
          permission: 'manage_teachers|manage_attendance',
          urTitle: 'اساتذہ رخصت',
          enTitle: 'Teacher Leaves',
          icon: Icons.event_busy,
          page: const TeacherLeavesScreen(),
        ),
        _DashboardCard(
          permission: 'manage_students|manage_requests|admin',
          urTitle: 'اسمارٹ الرٹس و تنبیہات',
          enTitle: 'Smart Alerts',
          value: _smartAlertsCount.toString(),
          icon: Icons.notifications_active,
          page: const SmartNotificationsPage(),
        ),
        _DashboardCard(
          permission: 'manage_library|admin',
          urTitle: 'کتب خانہ / لائبریری',
          enTitle: 'Library',
          icon: Icons.local_library,
          page: const LibraryScreen(),
        ),
        _DashboardCard(
          permission: 'manage_inventory|admin',
          urTitle: 'سکول شاپ و انونٹری',
          enTitle: 'Shop & Inventory',
          icon: Icons.store,
          page: const InventoryScreen(),
        ),
        _DashboardCard(
          permission: 'manage_hostel|admin',
          urTitle: 'ہاسٹل مینیجمنٹ',
          enTitle: 'Hostel',
          icon: Icons.hotel,
          page: const HostelScreen(),
        ),
        _DashboardCard(
          permission: 'manage_recruitment|admin',
          urTitle: 'بھرتی و تقرری (HR)',
          enTitle: 'Recruitment',
          icon: Icons.work_outline,
          page: const RecruitmentPipelineScreen(),
        ),
      ];
    }

    final visible = cards.where((card) => _hasPerm(card.permission)).toList();
    if (currentUserRole.value == 'admin') {
      visible.addAll([
        const _DashboardCard(
          permission: 'admin',
          urTitle: 'یوزرز کا انتظام',
          enTitle: 'Manage Users',
          icon: Icons.manage_accounts,
          page: ManageUsersPage(),
        ),
        const _DashboardCard(
          permission: 'admin',
          urTitle: 'سافٹ ویئر سیٹنگ',
          enTitle: 'Settings',
          icon: Icons.settings,
          page: SettingsPage(),
        ),
      ]);
    }
    return visible;
  }

  List<_DashboardCard> _studentCards() {
    return const [
      _DashboardCard(
        permission: 'student',
        urTitle: 'میری تعلیمی رپورٹ',
        enTitle: 'My Academic Report',
        icon: Icons.school,
        page: StudentReportPage(),
      ),
      _DashboardCard(
        permission: 'student',
        urTitle: 'مدرسہ کے ساتھ تعاون',
        enTitle: 'Cooperate with Madrasa',
        icon: Icons.payments,
        page: PayFeePage(),
      ),
      _DashboardCard(
        permission: 'student',
        urTitle: 'چھٹی کی درخواست',
        enTitle: 'Apply Leave',
        icon: Icons.mark_email_read,
        page: ApplyLeavePage(),
      ),
      _DashboardCard(
        permission: 'student',
        urTitle: 'اصلاحی شکایت',
        enTitle: 'Register Complaint',
        icon: Icons.sentiment_dissatisfied,
        page: ComplaintsPage(),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return ValueListenableBuilder<String>(
          valueListenable: SystemTypeGlobal.currentSystemType,
          builder: (context, currentSys, _) {
            final cards =
                _isStaff ? _staffCards() : (_isParent ? [] : _studentCards());

            return PopScope(
              canPop: false,
              onPopInvokedWithResult: (didPop, result) async {
                if (didPop) return;

                final shouldExit = await showDialog<bool>(
                  context: context,
                  builder: (ctx) => AlertDialog(
                    title: Text(isUrdu ? 'ایپ بند کریں؟' : 'Exit App?'),
                    content: Text(
                      isUrdu
                          ? 'کیا آپ واقعی ایپ بند کرنا چاہتے ہیں؟'
                          : 'Are you sure you want to exit?',
                    ),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.of(ctx).pop(false),
                        child: Text(isUrdu ? 'نہیں' : 'No'),
                      ),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.red,
                        ),
                        onPressed: () => Navigator.of(ctx).pop(true),
                        child: Text(
                          isUrdu ? 'ہاں، بند کریں' : 'Yes, Exit',
                          style: const TextStyle(color: Colors.white),
                        ),
                      ),
                    ],
                  ),
                );

                if (shouldExit == true) {
                  try {
                    await SyncService.runTwoWaySync();
                  } catch (_) {}
                  SystemNavigator.pop();
                }
              },
              child: Directionality(
                textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
                child: Scaffold(
                  backgroundColor: bgColor,
                  drawer: const AppDrawer(),
                  appBar: AppBar(
                    leading: Builder(
                      builder: (context) => IconButton(
                        icon: const Icon(Icons.menu, color: Colors.white),
                        onPressed: () => Scaffold.of(context).openDrawer(),
                      ),
                    ),
                    title: ValueListenableBuilder<String>(
                      valueListenable: SystemTypeGlobal.currentSystemType,
                      builder: (context, sysVal, _) {
                        final isMasjid = sysVal == 'Masjid';
                        return Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            ColorFiltered(
                              colorFilter: const ColorFilter.mode(
                                Colors.white,
                                BlendMode.srcIn,
                              ),
                              child: Image.asset('assets/logo.png', height: 28),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              isUrdu
                                  ? (isMasjid ? 'مسجد ڈیش بورڈ' : 'مدرسہ ڈیش بورڈ')
                                  : (isMasjid ? 'Masjid Dashboard' : 'Madrasa Dashboard'),
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 17,
                              ),
                            ),
                          ],
                        );
                      },
                    ),
                    backgroundColor: primaryGreen,
                    foregroundColor: Colors.white,
                    centerTitle: true,
                    actions: [
                      ValueListenableBuilder<String>(
                        valueListenable: SystemTypeGlobal.currentSystemType,
                        builder: (context, sysVal, _) {
                          final isMasjid = sysVal == 'Masjid';
                          return InkWell(
                            onTap: () {
                              SystemTypeGlobal.currentSystemType.value =
                                  isMasjid ? 'Madrasa' : 'Masjid';
                              _loadRealData();
                            },
                            borderRadius: BorderRadius.circular(20),
                            child: Container(
                              margin: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(0.2),
                                borderRadius: BorderRadius.circular(20),
                                border: Border.all(
                                  color: Colors.white.withOpacity(0.6),
                                  width: 1.2,
                                ),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(
                                    isMasjid ? Icons.mosque : Icons.school,
                                    size: 16,
                                    color: Colors.white,
                                  ),
                                  const SizedBox(width: 4),
                                  Text(
                                    isUrdu
                                        ? (isMasjid ? 'مسجد' : 'مدرسہ')
                                        : (isMasjid ? 'Masjid' : 'Madrasa'),
                                    style: const TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.white,
                                    ),
                                  ),
                                  const SizedBox(width: 4),
                                  const Icon(Icons.swap_horiz, size: 16, color: Colors.white70),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                      const BlinkingTrialBeacon(),
                      IconButton(
                        icon: Badge(
                          isLabelVisible: _smartAlertsCount > 0,
                          label: Text(
                            '$_smartAlertsCount',
                            style: const TextStyle(fontSize: 10, color: Colors.white),
                          ),
                          backgroundColor: Colors.red,
                          child: const Icon(Icons.notifications_active, color: Colors.white),
                        ),
                        tooltip: isUrdu ? 'اسمارٹ نوٹیفیکیشن و الرٹس' : 'Smart Notifications & Alerts',
                        onPressed: () async {
                          await Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const SmartNotificationsPage()),
                          );
                          _loadRealData();
                        },
                      ),
                      IconButton(
                        icon: _isSyncing
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(
                                  color: Colors.white,
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.sync, color: Colors.white),
                        onPressed: _isSyncing ? null : _runLiveTwoWaySync,
                      ),
                    ],
                  ),
                  body: Padding(
                    padding: const EdgeInsets.all(15),
                    child: Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 1100),
                        child: Column(
                          children: [
                            NeoBox(
                              padding: 15,
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    radius: 30,
                                    backgroundColor: Colors.white,
                                    backgroundImage: const AssetImage('assets/logo.png'),
                                  ),
                                  const SizedBox(width: 15),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: isUrdu
                                          ? CrossAxisAlignment.end
                                          : CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          mainAxisAlignment: isUrdu
                                              ? MainAxisAlignment.end
                                              : MainAxisAlignment.start,
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            Text(
                                              isUrdu ? 'خوش آمدید، ' : 'Welcome, ',
                                              style: TextStyle(
                                                fontSize: 13,
                                                color: Colors.grey.shade700,
                                              ),
                                            ),
                                            Flexible(
                                              child: Text(
                                                _userName.isNotEmpty
                                                    ? _userName
                                                    : (isUrdu ? 'ایڈمن' : 'Admin'),
                                                style: TextStyle(
                                                  fontSize: 16,
                                                  fontWeight: FontWeight.bold,
                                                  color: textDark,
                                                ),
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ),
                                            if (_userRoleDisplay.isNotEmpty) ...[
                                              const SizedBox(width: 6),
                                              Container(
                                                padding: const EdgeInsets.symmetric(
                                                  horizontal: 7,
                                                  vertical: 2,
                                                ),
                                                decoration: BoxDecoration(
                                                  color: primaryGreen.withOpacity(0.12),
                                                  borderRadius: BorderRadius.circular(6),
                                                  border: Border.all(
                                                    color: primaryGreen.withOpacity(0.3),
                                                  ),
                                                ),
                                                child: Text(
                                                  _userRoleDisplay,
                                                  style: TextStyle(
                                                    fontSize: 10,
                                                    fontWeight: FontWeight.bold,
                                                    color: primaryGreen,
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                        if (_institutionName.isNotEmpty) ...[
                                          const SizedBox(height: 2),
                                          Text(
                                            _institutionName,
                                            style: TextStyle(
                                              fontSize: 13,
                                              fontWeight: FontWeight.bold,
                                              color: primaryGreen,
                                            ),
                                          ),
                                        ],
                                        const SizedBox(height: 4),
                                        Row(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            Icon(
                                              Icons.access_time,
                                              size: 12,
                                              color: Colors.grey.shade600,
                                            ),
                                            const SizedBox(width: 4),
                                            Text(
                                              '${isUrdu ? "آخری سنک:" : "Last Sync:"} ${_formatLastSyncTime(isUrdu)}',
                                              style: TextStyle(
                                                fontSize: 11,
                                                color: Colors.grey.shade700,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 12),
                            ValueListenableBuilder<String>(
                              valueListenable: SystemTypeGlobal.currentSystemType,
                              builder: (context, currentSys, _) {
                                final isMasjid = currentSys == 'Masjid';
                                return Container(
                                  padding: const EdgeInsets.all(4),
                                  decoration: BoxDecoration(
                                    color: Colors.white,
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(color: primaryGreen.withValues(alpha: 0.25)),
                                    boxShadow: [
                                      BoxShadow(
                                        color: Colors.black.withOpacity(0.04),
                                        blurRadius: 6,
                                        offset: const Offset(0, 2),
                                      ),
                                    ],
                                  ),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: InkWell(
                                          onTap: () {
                                            if (isMasjid) {
                                              SystemTypeGlobal.currentSystemType.value = 'Madrasa';
                                            }
                                          },
                                          borderRadius: BorderRadius.circular(9),
                                          child: Container(
                                            padding: const EdgeInsets.symmetric(vertical: 9),
                                            decoration: BoxDecoration(
                                              color: !isMasjid ? primaryGreen : Colors.transparent,
                                              borderRadius: BorderRadius.circular(9),
                                            ),
                                            child: Row(
                                              mainAxisAlignment: MainAxisAlignment.center,
                                              children: [
                                                Icon(
                                                  Icons.school,
                                                  size: 18,
                                                  color: !isMasjid ? Colors.white : primaryGreen,
                                                ),
                                                const SizedBox(width: 8),
                                                Text(
                                                  isUrdu ? 'مدرسہ نظام (Madrasa)' : 'Madrasa Mode',
                                                  style: TextStyle(
                                                    fontSize: 13,
                                                    fontWeight: FontWeight.bold,
                                                    color: !isMasjid ? Colors.white : primaryGreen,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 6),
                                      Expanded(
                                        child: InkWell(
                                          onTap: () {
                                            if (!isMasjid) {
                                              SystemTypeGlobal.currentSystemType.value = 'Masjid';
                                            }
                                          },
                                          borderRadius: BorderRadius.circular(9),
                                          child: Container(
                                            padding: const EdgeInsets.symmetric(vertical: 9),
                                            decoration: BoxDecoration(
                                              color: isMasjid ? primaryGreen : Colors.transparent,
                                              borderRadius: BorderRadius.circular(9),
                                            ),
                                            child: Row(
                                              mainAxisAlignment: MainAxisAlignment.center,
                                              children: [
                                                Icon(
                                                  Icons.mosque,
                                                  size: 18,
                                                  color: isMasjid ? Colors.white : primaryGreen,
                                                ),
                                                const SizedBox(width: 8),
                                                Text(
                                                  isUrdu ? 'مسجد نظام (Masjid)' : 'Masjid Mode',
                                                  style: TextStyle(
                                                    fontSize: 13,
                                                    fontWeight: FontWeight.bold,
                                                    color: isMasjid ? Colors.white : primaryGreen,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                );
                              },
                            ),
                            const SizedBox(height: 14),
                            if (cards.isEmpty && !_isParent)
                              Expanded(
                                child: Center(
                                  child: Text(
                                    isUrdu
                                        ? 'آپ کے لیے کوئی ڈیش بورڈ کارڈ اسائن نہیں کیا گیا۔'
                                        : 'No dashboard cards have been assigned to you.',
                                    textAlign: TextAlign.center,
                                    style: TextStyle(
                                      color: textDark,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                              )
                            else
                              Expanded(
                                child: _isParent
                                    ? _buildParentChildrenList(isUrdu)
                                    : ListView(
                                        children: [
                                          if (_isStaff) ...[
                                            _buildTodaySituationCard(isUrdu),
                                            const SizedBox(height: 14),
                                          ],
                                          GridView.extent(
                                            shrinkWrap: true,
                                            physics: const NeverScrollableScrollPhysics(),
                                            maxCrossAxisExtent: 200,
                                            crossAxisSpacing: 12,
                                            mainAxisSpacing: 12,
                                            childAspectRatio: 1.3,
                                            children: cards
                                                .map((card) => _buildStatCard(card, isUrdu))
                                                .toList(),
                                          ),
                                          if (_isStaff) ...[
                                            const SizedBox(height: 16),
                                            _buildTopStudentsCard(isUrdu),
                                            const SizedBox(height: 16),
                                            _buildRecentActivitiesCard(isUrdu),
                                            const SizedBox(height: 16),
                                          ],
                                        ],
                                      ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildTodaySituationCard(bool isUrdu) => _buildTodaySituationCardContent(isUrdu);

  Widget _buildTodaySituationCardContent(bool isUrdu) {
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    final clsPct = _totalClassesCount > 0 ? (_markedClassesCount / _totalClassesCount) : 0.0;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: primaryGreen.withOpacity(0.2)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: primaryGreen.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Icon(Icons.assignment_turned_in, size: 20, color: primaryGreen),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    isUrdu ? 'آج کی صورتحال (Today\'s Situation)' : 'Today\'s Attendance Situation',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: textDark,
                    ),
                  ),
                ],
              ),
              InkWell(
                onTap: () async {
                  await Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => isMasjid ? const MaktabAttendancePage() : const AttendancePage(),
                    ),
                  );
                  _loadRealData();
                },
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: primaryGreen,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    isUrdu ? 'حاضری رجسٹر' : 'Attendance',
                    style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                isUrdu ? 'قرآنی و درسی کلاسز:' : 'Class Attendance:',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: _remainingClassNames.isEmpty ? Colors.green.shade50 : Colors.orange.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: _remainingClassNames.isEmpty ? Colors.green : Colors.orange),
                ),
                child: Text(
                  '$_markedClassesCount / $_totalClassesCount ${isUrdu ? "مکمل" : "Done"}',
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: _remainingClassNames.isEmpty ? Colors.green.shade800 : Colors.orange.shade900,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: clsPct.clamp(0.0, 1.0),
              minHeight: 8,
              backgroundColor: Colors.grey.shade200,
              color: primaryGreen,
            ),
          ),
          if (_remainingClassNames.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              isUrdu ? 'غیر درج شدہ کلاسز (ایکشن مطلوب):' : 'Remaining Classes (Action Needed):',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.red.shade700),
            ),
            const SizedBox(height: 4),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: _remainingClassNames.map((cName) {
                return InkWell(
                  onTap: () async {
                    await Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => isMasjid
                            ? MaktabAttendancePage(initialClass: cName)
                            : AttendancePage(initialClass: cName),
                      ),
                    );
                    _loadRealData();
                  },
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: Colors.red.shade50,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.red.shade200),
                    ),
                    child: Text(
                      cName,
                      style: TextStyle(fontSize: 11, color: Colors.red.shade800, fontWeight: FontWeight.w600),
                    ),
                  ),
                );
              }).toList(),
            ),
          ],
          const Divider(height: 18),
          Row(
            children: [
              Text(
                isUrdu ? 'اساتذہ حاضری:' : 'Teachers:',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
              ),
              const Spacer(),
              InkWell(
                onTap: () async {
                  await Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => isMasjid
                          ? const MaktabTeacherAttendanceReportPage()
                          : const TeacherAttendanceReportPage(),
                    ),
                  );
                  _loadRealData();
                },
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2, horizontal: 2),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _buildMiniChip(isUrdu ? 'حاضر' : 'Present', '$_teacherPresentCount', Colors.green),
                      const SizedBox(width: 6),
                      _buildMiniChip(isUrdu ? 'رخصت' : 'Leave', '$_teacherLeaveCount', Colors.orange),
                      const SizedBox(width: 6),
                      _buildMiniChip(isUrdu ? 'غیرحاضر' : 'Absent', '$_teacherAbsentCount', Colors.red),
                      const SizedBox(width: 4),
                      Icon(
                        isUrdu ? Icons.arrow_back_ios_new : Icons.arrow_forward_ios,
                        size: 11,
                        color: Colors.grey.shade600,
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildMiniChip(String label, String val, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: color.withOpacity(0.08),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Text(
        '$label: $val',
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: color),
      ),
    );
  }

  Widget _buildTopStudentsCard(bool isUrdu) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.amber.withOpacity(0.3)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.03),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Icon(Icons.military_tech, size: 20, color: Colors.amber.shade900),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    isUrdu ? 'نمایاں طلبہ (Top 5 Students)' : 'Top 5 Students',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: textDark),
                  ),
                ],
              ),
              InkWell(
                onTap: () {
                  final isM = SystemTypeGlobal.currentSystemType.value == 'Masjid';
                  Navigator.push(context, MaterialPageRoute(builder: (_) => isM ? const MaktabStudentsPage() : const StudentsPage()));
                },
                child: Text(
                  isUrdu ? 'تمام دیکھیں ←' : 'View All →',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: primaryGreen),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (_topStudents.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Center(
                child: Text(
                  isUrdu ? 'طلبہ کا ریکارڈ دستیاب نہیں ہے' : 'No student record available',
                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ),
            )
          else
            ...List.generate(_topStudents.length, (idx) {
              final std = _topStudents[idx];
              final name = std['name']?.toString() ?? 'طالب علم';
              final father = std['father_name']?.toString() ?? '';
              final cls = std['class_name']?.toString() ?? (std['class']?.toString() ?? '');
              final marks = std['avg_marks'];

              final rankColor = idx == 0
                  ? Colors.amber.shade700
                  : (idx == 1 ? Colors.blueGrey : (idx == 2 ? Colors.brown : primaryGreen));

              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 12,
                      backgroundColor: rankColor,
                      child: Text(
                        '${idx + 1}',
                        style: const TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.bold),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(name, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                          Text(
                            'ولد: ${father.isEmpty ? "-" : father} | کلاس: ${cls.isEmpty ? "-" : cls}',
                            style: const TextStyle(fontSize: 10, color: Colors.grey),
                          ),
                        ],
                      ),
                    ),
                    if (marks != null)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: Colors.green.shade50,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: Colors.green.shade300),
                        ),
                        child: Text(
                          '$marks نمبر',
                          style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.green.shade800),
                        ),
                      )
                    else
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: Colors.blue.shade50,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          isUrdu ? 'فعال' : 'Active',
                          style: TextStyle(fontSize: 10, color: Colors.blue.shade800),
                        ),
                      ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _buildRecentActivitiesCard(bool isUrdu) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.blue.withOpacity(0.2)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.03),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: Colors.blue.shade100,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(Icons.stream, size: 20, color: Colors.blue.shade800),
              ),
              const SizedBox(width: 8),
              Text(
                isUrdu ? 'حالیہ سرگرمیاں (Recent Activities)' : 'Recent Activities',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: textDark),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: Colors.blue.shade50,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  isUrdu ? 'لائیو لاگ' : 'Live Log',
                  style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.blue.shade700),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (_recentActivities.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Center(
                child: Text(
                  isUrdu ? 'فی الوقت کوئی نئی سرگرمی درج نہیں ہے' : 'No recent activities recorded',
                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ),
            )
          else
            ..._recentActivities.map((act) {
              final type = (act['action_type'] ?? '').toString().toLowerCase();
              final desc = act['description']?.toString() ?? '';
              final uName = act['user_name']?.toString() ?? 'سسٹم';
              final createdAt = act['created_at']?.toString() ?? '';

              IconData actIcon = Icons.circle;
              Color actColor = Colors.grey;
              if (type.contains('leave')) {
                actIcon = Icons.event_busy;
                actColor = Colors.orange;
              } else if (type.contains('att')) {
                actIcon = Icons.check_circle_outline;
                actColor = Colors.green;
              } else if (type.contains('fee') || type.contains('fin')) {
                actIcon = Icons.payments_outlined;
                actColor = Colors.teal;
              } else if (type.contains('admiss')) {
                actIcon = Icons.school_outlined;
                actColor = Colors.blue;
              }

              String timeDisplay = '';
              if (createdAt.isNotEmpty) {
                try {
                  final dt = DateTime.parse(createdAt);
                  timeDisplay = '${dt.day}/${dt.month} ${dt.hour}:${dt.minute.toString().padLeft(2, '0')}';
                } catch (_) {
                  timeDisplay = createdAt;
                }
              }

              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(actIcon, size: 16, color: actColor),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(uName, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                              Text(timeDisplay, style: const TextStyle(fontSize: 10, color: Colors.grey)),
                            ],
                          ),
                          Text(desc, style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _buildParentChildrenList(bool isUrdu) {
    if (_parentChildren.isEmpty) {
      return Center(
        child: Text(
          isUrdu ? 'کوئی طالب علم منسلک نہیں ہے۔' : 'No children linked.',
          style: const TextStyle(fontSize: 16, color: Colors.grey),
        ),
      );
    }
    return ListView.builder(
      itemCount: _parentChildren.length,
      itemBuilder: (context, index) {
        final child = _parentChildren[index];
        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
          elevation: 2,
          child: ListTile(
            contentPadding: const EdgeInsets.all(15),
            leading: CircleAvatar(
              backgroundColor: primaryGreen.withOpacity(0.1),
              child: Icon(Icons.person, color: primaryGreen),
            ),
            title: Text(
              child['name']?.toString() ?? '',
              style: const TextStyle(fontWeight: FontWeight.bold),
            ),
            subtitle:
                Text('${isUrdu ? "کلاس:" : "Class:"} ${child['class'] ?? "-"}'),
            trailing:
                Icon(Icons.arrow_forward_ios, size: 16, color: primaryGreen),
            onTap: () async {
              await Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (context) => StudentReportPage(
                      forceStudentId: child['id']?.toString()),
                ),
              );
              if (mounted) {
                _loadRealData();
              }
            },
          ),
        );
      },
    );
  }

  Widget _buildStatCard(_DashboardCard card, bool isUrdu) {
    return GestureDetector(
      onTap: () async {
        await Navigator.push(
          context,
          MaterialPageRoute(builder: (c) => card.page),
        );
        if (mounted) {
          _loadRealData();
        }
      },
      child: NeoBox(
        padding: 10.0,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(card.icon, color: primaryGreen, size: 30),
            const SizedBox(height: 8),
            Text(
              isUrdu ? card.urTitle : card.enTitle,
              style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
              ),
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            if (card.value.isNotEmpty) ...[
              const SizedBox(height: 6),
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    card.value,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: primaryGreen,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}


