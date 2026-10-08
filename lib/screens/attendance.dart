// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'package:flutter/material.dart';

import '../utils/globals.dart';

import '../widgets/app_drawer.dart';

import '../widgets/neo_box.dart';

import '../db_helper.dart';

import '../utils/sync_service.dart';

import '../utils/system_type_global.dart';

import '../utils/teacher_period_utils.dart';

import 'dashboard.dart';

import 'package:intl/intl.dart' as intl;

import 'dart:ui' as ui;
import 'package:share_plus/share_plus.dart';



class AttendancePage extends StatefulWidget {
  final String? initialClass;
  const AttendancePage({super.key, this.initialClass});

  @override
  _AttendancePageState createState() => _AttendancePageState();
}



class _AttendancePageState extends State<AttendancePage>

    with SingleTickerProviderStateMixin {

  final Color bgColor = const Color(0xFFF1EFE7);

  final Color primaryGreen = const Color(0xFF284D3D);

  final Color textDark = const Color(0xFF2D2D2D);



  final _dateController = TextEditingController();

  List<Map<String, dynamic>> _students = [];

  List<String> _classes = [];

  List<Map<String, dynamic>> _classRows = [];

  List<Map<String, dynamic>> _teachers = [];

  List<TeacherPeriodAssignment> _teacherAssignments = [];

  String? _selectedClass;



  late TabController _tabController;

  List<_AttendancePeriod> _periods = const [];



  final Map<String, Map<int, String>> _statusMap = {};

  final Map<String, Map<int, String>> _delayMap = {};

  final Map<String, Map<int, bool>> _isLateMap = {};

  final Map<String, String> _teacherStatusMap = {};

  final Map<String, bool> _teacherLateMap = {};

  final Map<String, TextEditingController> _teacherLateControllers = {};

  bool _isSaving = false;



  final List<String> statusOptions = [

    'Present',

    'Absent',

    'Leave'

  ];



  bool _hasPerm(String perm) {
    if (currentUserRole.value == 'admin' || currentUserRole.value == 'superadmin') return true;
    if (currentUserPermissions.value.contains('all')) return true;
    if (SystemTypeGlobal.currentSystemType.value == 'Masjid') {
      if (!perm.startsWith('manage_masjid_')) {
        if (perm == 'add_complaint') {
          perm = 'manage_masjid_add_complaint';
        } else {
          perm = perm.replaceFirst('manage_', 'manage_masjid_');
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
      if (nazimBase.contains(perm)) return true;
    }
    return currentUserPermissions.value.contains(perm);
  }

  bool get _canManageTeacherAttendance =>
      currentUserRole.value == 'admin' ||
      _hasPerm('manage_attendance') ||
      _hasPerm('manage_teacher_attendance');



  @override

  void initState() {

    super.initState();

    _selectedClass = widget.initialClass;
    _periods = _buildPeriodsForClass(_selectedClass);

    _tabController = TabController(length: _periods.length, vsync: this);

    _tabController.addListener(() {

      if (!_tabController.indexIsChanging) _reloadTeacherAssignments();

    });

    _dateController.text = _formatDate(DateTime.now());

    _loadStudents();

  }



  String _formatDate(DateTime date) {

    return intl.DateFormat('yyyy-MM-dd').format(date);

  }



  void _loadStudents() async {

    final data = await DBHelper().getStudentsForUser();

    final classesData = await DBHelper().getClassesForUser();

    final teachersData = await DBHelper().getTeachers();

    final teacherPeriodRows =

        await DBHelper().getTeacherPeriodAttendanceByDate(_dateController.text);

    final attendanceRows =

        await DBHelper().getAttendanceByDate(_dateController.text);

    setState(() {
      _classRows = classesData;
      _classes = classesData.map((c) => c['name'].toString()).toList();
      if (_selectedClass == null && _classes.isNotEmpty) {
        _selectedClass = _classes.first;
      } else if (_selectedClass != null && !_classes.contains(_selectedClass)) {
        final matched = _classes.firstWhere(
          (c) => c.trim().toLowerCase() == _selectedClass!.trim().toLowerCase(),
          orElse: () => _classes.isNotEmpty ? _classes.first : _selectedClass!,
        );
        _selectedClass = matched;
      }
      _rebuildTabsForClass(_selectedClass);
      final curDateStrForTeacher = _dateController.text.trim();
      _teachers = teachersData.where((t) {
        final status = (t['status'] ?? '').toString().trim().toLowerCase();
        final statusDate = (t['status_change_date'] ?? t['resignation_date'])?.toString().trim();
        
        bool isActiveStatus = status == 'active' || status == 'حاضر' || status == 'فعال' || status.isEmpty;
        
        if (!isActiveStatus) {
          if (statusDate == null || statusDate.isEmpty || statusDate == 'null') {
             return false;
          } else {
             final cleanStatus = statusDate.length >= 10 ? statusDate.substring(0, 10) : statusDate;
             if (cleanStatus.compareTo(curDateStrForTeacher) <= 0) {
               return false;
             }
          }
        }
        return true;
      }).toList();

      final curDateStr = _dateController.text.trim();
      _students = data.where((s) {
        final st = (s['status'] ?? '').toString().trim().toLowerCase();
        final isDel = int.tryParse(s['is_deleted']?.toString() ?? '0') ?? 0;
        final isActive = (st == 'active' || st == 'present' || st == '') && isDel == 0;
        if (!isActive) return false;

        final admDate = s['admission_date']?.toString().trim();
        if (admDate != null && admDate.isNotEmpty && admDate != 'null') {
          final cleanAdm = admDate.length >= 10 ? admDate.substring(0, 10) : admDate;
          if (curDateStr.isNotEmpty && cleanAdm.compareTo(curDateStr) > 0) {
            return false;
          }
        }
        return true;
      }).toList();

      for (var shift in _allPeriodKeys) {

        _statusMap[shift] = {};

        _delayMap[shift] = {};

        _isLateMap[shift] = {};

        for (var student in data) {

          _statusMap[shift]![student['id']] = 'Present';

          _delayMap[shift]![student['id']] = '';

          _isLateMap[shift]![student['id']] = false;

        }

      }

      _refreshTeacherAssignmentsFromCache(teacherPeriodRows);

      _refreshStudentAttendanceFromCache(attendanceRows);

    });

  }



  
  String _translateStatus(String s, bool isUrdu) {
    if (!isUrdu) return s;
    if (s == 'Present') return 'حاضر';
    if (s == 'Absent') return 'غیر حاضر';
    if (s == 'Leave') return 'رخصت';
    return s;
  }
  String _normalizeStudentStatus(dynamic value) {
    if (value == null) return 'Present';
    final raw = value.toString().trim();
    final code = raw.toUpperCase();
    final lower = raw.toLowerCase();

    if (code == 'A' ||
        lower.contains('absent') ||
        raw.contains('غیر حاضر') ||
        raw.contains('غیرحاضر') ||
        raw.contains('غائب')) {
      return 'Absent';
    }

    if (code == 'L' ||
        lower.contains('leave') ||
        raw.contains('رخصت') ||
        raw.contains('چھٹی')) {
      return 'Leave';
    }

    if (code == 'P' ||
        lower.contains('present') ||
        raw.contains('حاضر')) {
      return 'Present';
    }

    return raw.isEmpty ? 'Present' : raw;
  }



  String _normalizeStudentShift(dynamic value) {

    final raw = value?.toString().trim() ?? '';

    final lower = raw.toLowerCase().replaceAll(' ', '').replaceAll('_', '');

    const byNumber = {

      '1': 'Morning',

      '2': 'AfterBreakfast',

      '3': 'Noon',

      '4': 'Evening',

      '5': 'Lesson5',

      '6': 'Lesson6',

      '7': 'Study',

      '8': 'Revision',

    };

    if (byNumber.containsKey(lower)) return byNumber[lower]!;

    if (lower == 'afterbreakfast') return 'AfterBreakfast';

    if (lower == 'morning') return 'Morning';

    if (lower == 'noon') return 'Noon';

    if (lower == 'evening') return 'Evening';

    if (lower == 'lesson1') return 'Lesson1';

    if (lower == 'lesson2') return 'Lesson2';

    if (lower == 'lesson3') return 'Lesson3';

    if (lower == 'lesson4') return 'Lesson4';

    if (lower == 'lesson5') return 'Lesson5';

    if (lower == 'lesson6') return 'Lesson6';

    if (lower == 'study') return 'Study';

    if (lower == 'revision') return 'Revision';

    return raw;

  }



  void _refreshStudentAttendanceFromCache(List<Map<String, dynamic>> rows) {

    for (final row in rows) {

      final shift = _normalizeStudentShift(row['shift']);

      final studentId = int.tryParse(row['student_id']?.toString() ?? '');

      if (studentId == null || !_statusMap.containsKey(shift)) continue;

      final late = int.tryParse(row['delay_minutes']?.toString() ?? '0') ?? 0;

      _statusMap[shift]![studentId] = _normalizeStudentStatus(row['status']);

      _isLateMap[shift]![studentId] = late > 0;

      _delayMap[shift]![studentId] = late > 0 ? late.toString() : '';

    }

  }



  Future<void> _reloadStudentAttendance() async {

    final rows = await DBHelper().getAttendanceByDate(_dateController.text);

    if (!mounted) return;

    setState(() => _refreshStudentAttendanceFromCache(rows));

  }



  int _currentPeriodNo() => _periods[_tabController.index].periodNo;



  List<String> get _allPeriodKeys => const [

        'Morning',

        'AfterBreakfast',

        'Noon',

        'Evening',

        'Lesson1',

        'Lesson2',

        'Lesson3',

        'Lesson4',

        'Lesson5',

        'Lesson6',

        'Study',

        'Revision',

      ];



  Map<String, dynamic>? get _selectedClassRow {

    if (_selectedClass == null) return null;

    for (final row in _classRows) {

      if (row['name']?.toString() == _selectedClass) return row;

    }

    return null;

  }



  bool _classTextHas(Map<String, dynamic>? row, List<String> needles, [String? fallbackName]) {
    final text = [
      row?['name'],
      row?['class_type'],
      row?['section'],
      fallbackName,
      _selectedClass,
    ].whereType<Object>().join(' ').toLowerCase();

    return needles
        .where((needle) => needle.trim().isNotEmpty)
        .any((needle) => text.contains(needle.trim().toLowerCase()));
  }

  bool _checkIsNazira(Map<String, dynamic>? row, [String? className]) {
    return _classTextHas(row, [
      'ناظرہ',
      'ناظر',
      'nazira',
    ], className);
  }

  bool _checkIsHifz(Map<String, dynamic>? row, [String? className]) {
    return _classTextHas(row, [
      'حفظ',
      'گردان',
      'حسن صوت',
      'حسن',
      'hifz',
      'gardan',
      'husn',
    ], className);
  }

  List<_AttendancePeriod> _buildPeriodsForClass(String? className) {
    Map<String, dynamic>? targetRow;
    if (className != null) {
      for (final r in _classRows) {
        if (r['name']?.toString() == className) {
          targetRow = r;
          break;
        }
      }
    }
    targetRow ??= _selectedClassRow;

    final isNazira = _checkIsNazira(targetRow, className);
    final isHifz = _checkIsHifz(targetRow, className);

    // 1. Nazira Class -> 2 Periods
    if (isNazira) {
      return const [
        _AttendancePeriod(1, 'Morning', 'صبح کی شفٹ', 'Morning Shift'),
        _AttendancePeriod(2, 'Evening', 'شام کی شفٹ', 'Evening Shift'),
      ];
    }

    // 2. Hifz Class -> 4 Periods
    if (isHifz || (className == null && _classes.isEmpty)) {
      return const [
        _AttendancePeriod(1, 'Morning', 'صبح', 'Morning'),
        _AttendancePeriod(2, 'AfterBreakfast', 'بعد ناشتہ', 'After Breakfast'),
        _AttendancePeriod(3, 'Noon', 'دوپہر', 'Noon'),
        _AttendancePeriod(4, 'Evening', 'شام', 'Evening'),
      ];
    }

    // 3. Academic / Regular Classes -> 8 Periods
    return const [
      _AttendancePeriod(1, 'Lesson1', 'گھنٹہ 1', 'Lesson 1'),
      _AttendancePeriod(2, 'Lesson2', 'گھنٹہ 2', 'Lesson 2'),
      _AttendancePeriod(3, 'Lesson3', 'گھنٹہ 3', 'Lesson 3'),
      _AttendancePeriod(4, 'Lesson4', 'گھنٹہ 4', 'Lesson 4'),
      _AttendancePeriod(5, 'Lesson5', 'گھنٹہ 5', 'Lesson 5'),
      _AttendancePeriod(6, 'Lesson6', 'گھنٹہ 6', 'Lesson 6'),
      _AttendancePeriod(7, 'Study', 'مطالعہ', 'Study'),
      _AttendancePeriod(8, 'Revision', 'تکرار', 'Revision'),
    ];
  }



  void _rebuildTabsForClass(String? className) {

    final nextPeriods = _buildPeriodsForClass(className);

    final oldIndex = _tabController.index;

    _tabController.dispose();

    _periods = nextPeriods;

    _tabController = TabController(

      length: _periods.length,

      vsync: this,

      initialIndex: oldIndex.clamp(0, _periods.length - 1),

    );

    _tabController.addListener(() {

      if (!_tabController.indexIsChanging) _reloadTeacherAssignments();

    });

  }



  String _teacherKey(TeacherPeriodAssignment a) =>

      [a.teacherId, a.className, a.periodNo].join('|');



  String _normalizeTeacherStatus(dynamic value) {

    final raw = value?.toString().trim() ?? '';

    final code = raw.toUpperCase();

    if (raw == '\u062d\u0627\u0636\u0631' || code == 'P') return 'P';

    if (raw == '\u063a\u06cc\u0631 \u062d\u0627\u0636\u0631' || code == 'A') {

      return 'A';

    }

    if (raw ==

            '\u0631\u062e\u0635\u062a \u0627\u062a\u0641\u0627\u0642\u06cc' ||

        code == 'LA') {

      return 'LA';

    }

    if (raw == '\u0628\u06a9\u0627\u0631 \u0645\u062f\u0631\u0633\u06c1' ||

        code == 'OD') {

      return 'OD';

    }

    if (raw == '\u0631\u062e\u0635\u062a' || code == 'L') {

      return 'L';

    }

    return raw.isEmpty ? 'P' : raw;

  }



  void _refreshTeacherAssignmentsFromCache(

      List<Map<String, dynamic>> teacherPeriodRows) {

    for (final controller in _teacherLateControllers.values) {

      controller.dispose();

    }

    _teacherStatusMap.clear();

    _teacherLateMap.clear();

    _teacherLateControllers.clear();

    if (!_canManageTeacherAttendance) {

      _teacherAssignments = [];

      return;

    }

    _teacherAssignments = buildTeacherPeriodAssignments(

      teachers: _teachers,

      classes: _classRows,

      className: _selectedClass,

      periodNo: _currentPeriodNo(),

    );

    for (final assignment in _teacherAssignments) {

      final key = _teacherKey(assignment);

      Map<String, dynamic>? existing;

      for (final row in teacherPeriodRows) {

        final rowTeacherId =

            int.tryParse(row['teacher_id']?.toString() ?? '') ?? 0;

        final rowPeriodNo =

            int.tryParse(row['period_no']?.toString() ?? '') ?? 0;

        final rowClassName = row['class_name']?.toString() ?? '';

        if (rowTeacherId == assignment.teacherId &&

            rowPeriodNo == assignment.periodNo &&

            rowClassName == assignment.className) {

          existing = row;

          break;

        }

      }

      final late =

          int.tryParse(existing?['late_minutes']?.toString() ?? '0') ?? 0;

      _teacherStatusMap[key] = _normalizeTeacherStatus(existing?['status']);

      _teacherLateMap[key] = late > 0;

      _teacherLateControllers[key] =

          TextEditingController(text: late > 0 ? late.toString() : '');

    }

  }



  Future<void> _reloadTeacherAssignments() async {

    final rows =

        await DBHelper().getTeacherPeriodAttendanceByDate(_dateController.text);

    if (!mounted) return;

    setState(() => _refreshTeacherAssignmentsFromCache(rows));

  }



  Future<void> _saveTeacherAttendanceForCurrentPeriod() async {

    if (!_canManageTeacherAttendance) return;

    for (final assignment in _teacherAssignments) {

      final key = _teacherKey(assignment);

      final status = _teacherStatusMap[key] ?? 'P';

      final late = status == 'P' && (_teacherLateMap[key] ?? false)

          ? (_teacherLateControllers[key]?.text.trim() ?? '0')

          : '0';

      await DBHelper().insertTeacherPeriodAttendance({

        'teacher_id': assignment.teacherId,

        'class_id': assignment.classId,

        'class_name': assignment.className,

        'date': _dateController.text,

        'period_no': assignment.periodNo,

        'status': status,

        'late_minutes': int.tryParse(late.isEmpty ? '0' : late) ?? 0,

        'remarks': '',

      });

    }

  }



  Future<void> _saveAttendance() async {

    if (_isSaving) return;



    if (_students.isEmpty) {

      ScaffoldMessenger.of(context).showSnackBar(

        SnackBar(

            content: Text(isUrduGlobal.value

                ? '    !'

                : 'No students found!')),

      );

      return;

    }



    String currentShift = _periods[_tabController.index].key;



    final filteredStudents = _selectedClass == null

        ? _students

        : _students

            .where((s) =>

                (s['class_name'] ?? s['class'])?.toString() == _selectedClass)

            .toList();



    if (filteredStudents.isEmpty && _teacherAssignments.isEmpty) {

      ScaffoldMessenger.of(context).showSnackBar(

        SnackBar(

            content: Text(isUrduGlobal.value

                ? '           '

                : 'No attendance records to save for the selected class.')),

      );

      return;

    }



    setState(() => _isSaving = true);



    try {

      await _saveTeacherAttendanceForCurrentPeriod();



      for (var student in filteredStudents) {

        int sId = student['id'];

        final status = _statusMap[currentShift]?[sId] ?? 'Present';

        final isPresent = status == 'Present';

        final lateMins = (isPresent && _isLateMap[currentShift]?[sId] == true)

            ? (_delayMap[currentShift]?[sId] ?? '').trim()

            : '0';



        await DBHelper().insertAttendance({

          'date': _dateController.text,

          'shift': currentShift,

          'student_id': sId.toString(),

          'status': status,

          'delay_minutes': lateMins.isEmpty ? '0' : lateMins,

        });

      }



      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(

        SnackBar(

          content: Text(isUrduGlobal.value

              ? '      !'

              : 'Attendance saved!'),

          backgroundColor: primaryGreen,

        ),

      );
      SyncService.triggerBackgroundTableSync('attendance');
      SyncService.triggerBackgroundTableSync('attendance_teachers');

    } catch (e, stackTrace) {
      debugPrint('Error saving attendance: $e\nStack: $stackTrace');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? '❌ حاضری محفوظ نہیں ہو سکی۔ دوبارہ کوشش کریں۔'
              : '❌ Attendance could not be saved. Please try again.'),
          backgroundColor: Colors.red.shade700,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  void _shareAttendanceSummaryWhatsApp() {
    final isUrdu = isUrduGlobal.value;
    if (_students.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu
            ? 'شیئر کرنے کے لیے کوئی ریکارڈ موجود نہیں ہے'
            : 'No attendance records available to share'),
        backgroundColor: Colors.orange,
      ));
      return;
    }

    final dateStr = _dateController.text.trim();
    final className = _selectedClass ?? (isUrdu ? 'تمام کلاسز' : 'All Classes');
    final activeTabIdx = _tabController.index;
    final currentPeriod = (activeTabIdx >= 0 && activeTabIdx < _periods.length)
        ? _periods[activeTabIdx]
        : (_periods.isNotEmpty ? _periods.first : null);
    final periodLabel = currentPeriod != null
        ? (isUrdu ? currentPeriod.labelUr : currentPeriod.labelEn)
        : '';
    final currentShift = currentPeriod?.key ?? 'morning';

    int total = _students.length;
    int present = 0;
    int absent = 0;
    int leave = 0;
    List<String> absentNames = [];
    List<String> leaveNames = [];

    for (var student in _students) {
      final sId = student['id'];
      final name = student['name']?.toString() ?? '';
      final status =
          (_statusMap[currentShift]?[sId] ?? 'Present').toLowerCase();

      if (status == 'present' || status == 'حاضر') {
        present++;
      } else if (status == 'absent' || status == 'غیر حاضر') {
        absent++;
        if (name.isNotEmpty) absentNames.add(name);
      } else if (status == 'leave' || status == 'رخصت') {
        leave++;
        if (name.isNotEmpty) leaveNames.add(name);
      } else {
        present++;
      }
    }

    final buffer = StringBuffer();
    buffer.writeln('📋 *مدرسہ اشرف المدارس - روزانہ حاضری رپورٹ*');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('📅 *تاریخ:* $dateStr');
    if (className.isNotEmpty) {
      buffer.writeln('🏫 *جماعت:* $className');
    }
    if (periodLabel.isNotEmpty) {
      buffer.writeln('⏰ *پیریڈ:* $periodLabel');
    }
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('👥 *کل طلبہ:* $total');
    buffer.writeln('✅ *حاضر:* $present');
    buffer.writeln('❌ *غیر حاضر:* $absent');
    buffer.writeln('📝 *رخصت:* $leave');

    if (absentNames.isNotEmpty) {
      buffer.writeln('\n❌ *غیر حاضر طلبہ:*');
      for (int i = 0; i < absentNames.length; i++) {
        buffer.writeln('${i + 1}. ${absentNames[i]}');
      }
    }

    if (leaveNames.isNotEmpty) {
      buffer.writeln('\n📝 *رخصت پر طلبہ:*');
      for (int i = 0; i < leaveNames.length; i++) {
        buffer.writeln('${i + 1}. ${leaveNames[i]}');
      }
    }

    buffer.writeln('\n_اشرف المدارس مینجمنٹ سسٹم_');

    Share.share(buffer.toString(), subject: 'حاضری رپورٹ - $dateStr');
  }



  @override

  void dispose() {

    for (final controller in _teacherLateControllers.values) {

      controller.dispose();

    }

    _tabController.dispose();

    super.dispose();

  }



  @override

  Widget build(BuildContext context) {

    return ValueListenableBuilder<bool>(

      valueListenable: isUrduGlobal,

      builder: (context, isUrdu, child) {

        return PopScope(

          canPop: false,

          onPopInvokedWithResult: (bool didPop, dynamic result) {
            if (didPop) return;
            if (Navigator.of(context).canPop()) {
              Navigator.of(context).pop();
            } else {
              Navigator.pushReplacement(context,
                  MaterialPageRoute(builder: (c) => const DashboardPage()));
            }
          },

          child: Directionality(

            textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,

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

                title: Text(
                  isUrdu ? "حاضری رجسٹر" : "Attendance Register",
                  style: const TextStyle(
                      color: Colors.white, fontWeight: FontWeight.bold),
                ),
                backgroundColor: primaryGreen,
                elevation: 0,
                iconTheme: const IconThemeData(color: Colors.white),
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.share, color: Colors.white),
                    tooltip: isUrdu ? 'WhatsApp حاضری رپورٹ' : 'WhatsApp Attendance Report',
                    onPressed: _shareAttendanceSummaryWhatsApp,
                  ),
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    tooltip: isUrdu ? 'سنک' : 'Sync',
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrdu
                              ? "ڈیٹا سنک ہو رہا ہے..."
                              : "Syncing data...")));
                      try {
                        await SyncService.runTwoWaySync();
                        if (mounted) {
                          _loadStudents();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سنک مکمل ہو گیا!"
                                  : "Sync complete!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e, stackTrace) {
                        debugPrint(
                            'Error syncing attendance: $e\nStack: $stackTrace');
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(
                                  isUrdu ? "سنک میں خرابی آئی" : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
                  ),
                ],
              ),

              body: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1200),
                  child: Column(
                    children: [
                      Padding(

                    padding: const EdgeInsets.symmetric(

                        horizontal: 15.0, vertical: 10),

                    child: Row(

                      children: [

                        Expanded(

                          child: NeoBox(

                            padding: 5,

                            child: TextField(

                              controller: _dateController,

                              readOnly: true,

                              style: TextStyle(

                                  color: textDark,

                                  fontWeight: FontWeight.bold,

                                  fontSize: 13),

                              decoration: InputDecoration(

                                labelText: isUrdu ? 'تاریخ:' : 'Date:',

                                labelStyle: TextStyle(

                                    color: primaryGreen, fontSize: 12),

                                suffixIcon: Icon(Icons.calendar_today,

                                    color: primaryGreen, size: 18),

                                border: InputBorder.none,

                                contentPadding: const EdgeInsets.symmetric(

                                    horizontal: 10, vertical: 5),

                              ),

                              onTap: () async {

                                DateTime? pickedDate = await showDatePicker(

                                  context: context,

                                  initialDate: DateTime.now(),

                                  firstDate: DateTime(2000),

                                  lastDate: DateTime(2100),

                                );

                                if (pickedDate != null) {

                                  _dateController.text =

                                      intl.DateFormat('yyyy-MM-dd')

                                          .format(pickedDate);

                                  _loadStudents();

                                }

                              },

                            ),

                          ),

                        ),

                        const SizedBox(width: 10),

                        Expanded(

                          child: NeoBox(

                            padding: 5,

                            child: DropdownButtonFormField<String>(

                              value: _selectedClass,

                              decoration: InputDecoration(

                                labelText: isUrdu

                                    ? 'کلاس منتخب کریں:'

                                    : 'Select Class:',

                                labelStyle: TextStyle(

                                    color: primaryGreen, fontSize: 12),

                                border: InputBorder.none,

                                contentPadding: const EdgeInsets.symmetric(

                                    horizontal: 10, vertical: 5),

                              ),

                              items: [

                                DropdownMenuItem<String>(

                                    value: null,

                                    child: Text(

                                        isUrdu ? "تمام کلاسز" : "All Classes",

                                        style: const TextStyle(fontSize: 13))),

                                ..._classes.map((c) => DropdownMenuItem<String>(

                                    value: c,

                                    child: Text(c,

                                        style: const TextStyle(fontSize: 13)))),

                              ],

                              onChanged: (val) async {

                                setState(() {

                                  _selectedClass = val;

                                  _rebuildTabsForClass(val);

                                });

                                await _reloadTeacherAssignments();

                                await _reloadStudentAttendance();

                              },

                            ),

                          ),

                        ),

                      ],

                    ),
                  ),
                  // Prominent Period Selector Header (Visible on attendance register)
                  Container(
                    width: double.infinity,
                    margin: const EdgeInsets.fromLTRB(15, 0, 15, 10),
                    padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 4),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.grey.shade300),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withOpacity(0.04),
                          blurRadius: 4,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: TabBar(
                      controller: _tabController,
                      isScrollable: true,
                      labelColor: Colors.white,
                      unselectedLabelColor: primaryGreen,
                      indicator: BoxDecoration(
                        color: primaryGreen,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                      unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                      tabs: _periods
                          .map((period) => Tab(
                                height: 36,
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 10),
                                  child: Text(isUrdu ? period.labelUr : period.labelEn),
                                ),
                              ))
                          .toList(),
                    ),
                  ),
                  Expanded(
                    child: TabBarView(

                      controller: _tabController,

                      children: _periods

                          .map(

                              (period) => _buildStudentList(period.key, isUrdu))

                          .toList(),

                    ),

                  ),

                  Padding(

                    padding: const EdgeInsets.all(15.0),

                    child: SizedBox(

                      width: double.infinity,

                      height: 50,

                      child: ElevatedButton.icon(

                        style: ElevatedButton.styleFrom(

                          backgroundColor: primaryGreen,

                          shape: RoundedRectangleBorder(

                              borderRadius: BorderRadius.circular(10)),

                        ),

                        onPressed: _isSaving ? null : _saveAttendance,

                        icon: _isSaving

                            ? const SizedBox(

                                width: 20,

                                height: 20,

                                child: CircularProgressIndicator(

                                  strokeWidth: 2,

                                  color: Colors.white,

                                ),

                              )

                            : const Icon(Icons.save, color: Colors.white),

                        label: Text(

                          _isSaving

                              ? (isUrdu ? "محفوظ ہو رہا ہے..." : "Saving...")

                              : (isUrdu ? "محفوظ کریں" : "Save"),

                          style: const TextStyle(

                              fontSize: 18,

                              color: Colors.white,

                              fontWeight: FontWeight.bold),

                        ),

                      ),

                    ),

                  ),

                ],

              ),
            ),
          ),

            ),

          ),

        );

      },

    );

  }



  Widget _buildStudentList(String shift, bool isUrdu) {

    if (_students.isEmpty) {

      return Center(

        child: Text(

          isUrdu ? "کوئی طالب علم نہیں ملا" : "No students found",

          style: TextStyle(color: Colors.grey.shade600, fontSize: 16),

        ),

      );

    }



    final filteredStudents = _selectedClass == null

        ? _students

        : _students

            .where((s) =>

                (s['class_name'] ?? s['class'])?.toString() == _selectedClass)

            .toList();



    if (filteredStudents.isEmpty) {

      return ListView(

        padding: const EdgeInsets.symmetric(horizontal: 15),

        children: [

          _buildTeacherAttendanceSection(isUrdu),

          NeoBox(

            padding: 18,

            child: Row(

              children: [

                Icon(Icons.info_outline, color: Colors.orange.shade700),

                const SizedBox(width: 10),

                Expanded(

                  child: Text(

                    isUrdu

                        ? '                 '

                        : 'No students found in the selected class. Sync or check student class assignment.',

                    style: TextStyle(

                      color: Colors.orange.shade800,

                      fontWeight: FontWeight.bold,

                    ),

                  ),

                ),

              ],

            ),

          ),

        ],

      );

    }



    return ListView.builder(

      padding: const EdgeInsets.symmetric(horizontal: 15),

      itemCount: filteredStudents.length + 1,

      itemBuilder: (context, index) {

        if (index == 0) return _buildTeacherAttendanceSection(isUrdu);

        var student = filteredStudents[index - 1];

        int sId = student['id'];

        String sName = student['name'];

        String sRoll = student['roll_number'] ?? '-';



        return Padding(

          padding: const EdgeInsets.only(bottom: 12.0),

          child: NeoBox(

            padding: 10,

            child: Column(

              children: [

                Row(

                  mainAxisAlignment: MainAxisAlignment.spaceBetween,

                  children: [

                    Expanded(

                      child: Column(

                        crossAxisAlignment: CrossAxisAlignment.start,

                        children: [

                          Text(

                            sName,

                            style: TextStyle(

                                color: textDark,

                                fontSize: 16,

                                fontWeight: FontWeight.bold),

                          ),

                          Text(

                            ': ${student['father_name'] ?? '-'}',

                            style: const TextStyle(

                                color: Colors.grey, fontSize: 12),

                          ),

                        ],

                      ),

                    ),

                    Text(

                      sRoll,

                      style: TextStyle(

                          color: primaryGreen,

                          fontSize: 16,

                          fontWeight: FontWeight.bold),

                    ),

                  ],

                ),

                const Divider(),

                Row(

                  children: [

                    Expanded(

                      child: Container(

                        height: 45,

                        padding: const EdgeInsets.symmetric(horizontal: 10),

                        decoration: BoxDecoration(

                          color: _statusMap[shift]![sId] == 'Present'

                              ? Colors.green.shade700

                              : _statusMap[shift]![sId] == 'Absent'

                                  ? Colors.red.shade700

                                  : _statusMap[shift]![sId] == 'Leave'

                                      ? Colors.orange.shade700

                                      : Colors.grey.shade600,

                          borderRadius: BorderRadius.circular(5),

                        ),

                        child: DropdownButtonHideUnderline(

                          child: DropdownButton<String>(

                            isExpanded: true,

                            dropdownColor: Colors.white,

                            icon: const Icon(Icons.arrow_drop_down,

                                color: Colors.white),

                            value: _statusMap[shift]![sId],

                            selectedItemBuilder: (BuildContext context) {

                              return statusOptions.map((String value) {

                                return Center(

                                  child: Text(

                                    _translateStatus(value, isUrduGlobal.value),

                                    style: const TextStyle(

                                        color: Colors.white,

                                        fontSize: 13,

                                        fontWeight: FontWeight.bold),

                                  ),

                                );

                              }).toList();

                            },

                            items: statusOptions.map((String item) {

                              return DropdownMenuItem<String>(

                                value: item,

                                child: Text(_translateStatus(item, isUrduGlobal.value),

                                    style: const TextStyle(

                                        color: Colors.black,

                                        fontSize: 13,

                                        fontWeight: FontWeight.bold)),

                              );

                            }).toList(),

                            onChanged: (String? newValue) {

                              setState(() {

                                _statusMap[shift]![sId] = newValue!;

                                if (newValue != 'Present') {

                                  _isLateMap[shift]![sId] = false;

                                  _delayMap[shift]![sId] = '';

                                }

                              });

                            },

                          ),

                        ),

                      ),

                    ),

                  ],

                ),

                if (_statusMap[shift]![sId] == 'Present') ...[

                  const SizedBox(height: 10),

                  Row(

                    children: [

                      Expanded(

                        child: DropdownButtonFormField<String>(

                          value:

                              (_isLateMap[shift]![sId] ?? false) ? 'late' : '0',

                          decoration: InputDecoration(

                            labelText: isUrdu ? 'تاخیر:' : 'Delay',

                            labelStyle: const TextStyle(fontSize: 13),

                            border: const OutlineInputBorder(),

                            contentPadding: const EdgeInsets.symmetric(

                                horizontal: 10, vertical: 8),

                          ),

                          items: [

                            DropdownMenuItem(

                                value: '0',

                                child: Text(isUrdu ? 'بلا تاخیر' : 'On Time',

                                    style: const TextStyle(fontSize: 13))),

                            DropdownMenuItem(

                                value: 'late',

                                child: Text(isUrdu ? 'تاخیر' : 'Late',

                                    style: const TextStyle(fontSize: 13))),

                          ],

                          onChanged: (val) {

                            setState(() {

                              _isLateMap[shift]![sId] = val == 'late';

                              if (val != 'late') {

                                _delayMap[shift]![sId] = '';

                              }

                            });

                          },

                        ),

                      ),

                      if (_isLateMap[shift]![sId] ?? false) ...[

                        const SizedBox(width: 10),

                        SizedBox(

                          width: 100,

                          child: TextFormField(

                            initialValue: _delayMap[shift]![sId],

                            keyboardType: TextInputType.number,

                            textAlign: TextAlign.center,

                            decoration: InputDecoration(

                              labelText: isUrdu ? 'منٹ' : 'Min',

                              labelStyle: const TextStyle(fontSize: 12),

                              border: const OutlineInputBorder(),

                              contentPadding: const EdgeInsets.symmetric(

                                  horizontal: 8, vertical: 8),

                            ),

                            style: const TextStyle(fontSize: 14),

                            onChanged: (val) {

                              _delayMap[shift]![sId] = val;

                            },

                          ),

                        ),

                      ],

                    ],

                  ),

                ],

              ],

            ),

          ),

        );

      },

    );

  }



  Widget _buildTeacherAttendanceSection(bool isUrdu) {

    if (!_canManageTeacherAttendance) {

      return const SizedBox.shrink();

    }

    if (_teacherAssignments.isEmpty) {

      return Padding(

        padding: const EdgeInsets.only(bottom: 12.0),

        child: NeoBox(

          padding: 12,

          child: Row(

            children: [

              Icon(Icons.info_outline, color: Colors.orange.shade700),

              const SizedBox(width: 8),

              Expanded(

                child: Text(

                  isUrdu

                      ? 'اس کلاس/پیریڈ کے لیے کوئی استاذ متعین نہیں'

                      : 'No teacher assigned for this class/period',

                  style: TextStyle(

                    color: Colors.orange.shade800,

                    fontWeight: FontWeight.bold,

                  ),

                ),

              ),

            ],

          ),

        ),

      );

    }

    return Column(

      children: _teacherAssignments.map((assignment) {

        final key = _teacherKey(assignment);

        final currentStatus = _teacherStatusMap[key] ?? 'P';

        final isPresent = currentStatus == 'P';

        final isLate = _teacherLateMap[key] ?? false;

        return Padding(

          padding: const EdgeInsets.only(bottom: 12.0),

          child: NeoBox(

            padding: 10,

            child: Column(

              crossAxisAlignment: CrossAxisAlignment.start,

              children: [

                Row(

                  children: [

                    Icon(Icons.person_pin, color: primaryGreen),

                    const SizedBox(width: 8),

                    Expanded(

                      child: Column(

                        crossAxisAlignment: CrossAxisAlignment.start,

                        children: [

                          Text(

                            assignment.teacherName,

                            style: TextStyle(

                              color: textDark,

                              fontSize: 16,

                              fontWeight: FontWeight.bold,

                            ),

                          ),

                          Text(

                            '${assignment.className} - ${isUrdu ? assignment.periodLabelUr : assignment.periodLabelEn}',

                            style: const TextStyle(

                                color: Colors.grey, fontSize: 12),

                          ),

                        ],

                      ),

                    ),

                  ],

                ),

                const Divider(),

                Wrap(

                  spacing: 8.0,

                  runSpacing: 8.0,

                  children: [

                    _buildTeacherStatusChip(

                        key, 'P', isUrdu ? 'حاضر' : 'Present', Colors.green),

                    _buildTeacherStatusChip(

                        key, 'L', isUrdu ? 'رخصت' : 'Leave', Colors.orange),

                    _buildTeacherStatusChip(key, 'LA',

                        isUrdu ? 'رخصت اتفاقی' : 'Casual Leave', Colors.blue),

                    _buildTeacherStatusChip(key, 'OD',

                        isUrdu ? 'بکار جامعہ' : 'Official Duty', Colors.purple),

                  ],

                ),

                if (isPresent) ...[

                  const SizedBox(height: 10),

                  Row(

                    children: [

                      Expanded(

                        child: DropdownButtonFormField<String>(

                          value: isLate ? 'late' : '0',

                          decoration: InputDecoration(

                            labelText: isUrdu ? 'تاخیر:' : 'Delay',

                            border: const OutlineInputBorder(),

                            contentPadding: const EdgeInsets.symmetric(

                                horizontal: 10, vertical: 8),

                          ),

                          items: [

                            DropdownMenuItem(

                                value: '0',

                                child: Text(isUrdu ? 'بلا تاخیر' : 'On Time')),

                            DropdownMenuItem(

                                value: 'late',

                                child: Text(isUrdu ? 'تاخیر' : 'Late')),

                          ],

                          onChanged: (val) {

                            setState(() {

                              _teacherLateMap[key] = val == 'late';

                              if (val != 'late') {

                                _teacherLateControllers[key]?.clear();

                              }

                            });

                          },

                        ),

                      ),

                      if (isLate) ...[

                        const SizedBox(width: 8),

                        SizedBox(

                          width: 90,

                          child: TextFormField(

                            controller: _teacherLateControllers[key],

                            keyboardType: TextInputType.number,

                            textAlign: TextAlign.center,

                            decoration: InputDecoration(

                              labelText: isUrdu ? 'منٹ' : 'Min',

                              border: const OutlineInputBorder(),

                              contentPadding: const EdgeInsets.symmetric(

                                  horizontal: 8, vertical: 8),

                            ),

                          ),

                        ),

                      ],

                    ],

                  ),

                ],

              ],

            ),

          ),

        );

      }).toList(),

    );

  }



  Widget _buildTeacherStatusChip(

      String key, String value, String label, Color color) {

    final selected = _teacherStatusMap[key] == value;

    final chipWidth =

        ((MediaQuery.of(context).size.width - 76) / 2).clamp(120.0, 180.0);

    return SizedBox(

      width: chipWidth.toDouble(),

      child: InkWell(

        onTap: () {

          setState(() {

            _teacherStatusMap[key] = value;

            if (value != 'P') {

              _teacherLateMap[key] = false;

              _teacherLateControllers[key]?.clear();

            }

          });

        },

        child: Container(

          padding: const EdgeInsets.symmetric(vertical: 9),

          decoration: BoxDecoration(

            color: selected ? color : Colors.transparent,

            border: Border.all(color: color),

            borderRadius: BorderRadius.circular(8),

          ),

          child: Text(

            label,

            textAlign: TextAlign.center,

            style: TextStyle(

              color: selected ? Colors.white : color,

              fontWeight: FontWeight.bold,

            ),

          ),

        ),

      ),

    );

  }

}



class _AttendancePeriod {

  const _AttendancePeriod(this.periodNo, this.key, this.labelUr, this.labelEn);



  final int periodNo;

  final String key;

  final String labelUr;

  final String labelEn;

}

