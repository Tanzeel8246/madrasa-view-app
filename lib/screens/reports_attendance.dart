// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import 'package:path_provider/path_provider.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/ai_service.dart';
import '../utils/teacher_period_utils.dart';
import '../widgets/app_drawer.dart';
import 'dashboard.dart';
import 'student_report.dart';

class ReportsAttendancePage extends StatefulWidget {
  const ReportsAttendancePage({super.key});

  @override
  _ReportsAttendancePageState createState() => _ReportsAttendancePageState();
}

class _ReportsAttendancePageState extends State<ReportsAttendancePage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final ScreenshotController screenshotController = ScreenshotController();

  List<Map<String, dynamic>> _allStudents = [];
  List<Map<String, dynamic>> _filteredStudents = [];
  List<Map<String, dynamic>> _classes = [];

  bool isLoading = true;
  String? displayMonth;
  String? displayYear;
  String? selectedClassName;
  bool isMonthMode = true;
  late DateTime rangeFrom;
  late DateTime rangeTo;
  int hijriOffset = 0;

  // Student -> Date -> Map of Shifts ('1','2','3','4') -> Status ('P','A','L')
  Map<String, Map<String, Map<String, String>>> attData = {};
  // Class -> Teacher ID -> Date -> Period -> Status
  Map<String, Map<String, Map<String, Map<String, String>>>> teacherAttData =
      {};
  Map<String, String> teacherNameById = {};
  Map<String, Map<String, dynamic>> teacherInfoById = {};
  Map<String, String> teacherLastAttDate = {};
  Map<String, Set<String>> assignedTeacherIdsByClass = {};

  int grandTotalSlots = 0;
  int grandTotalP = 0;
  int grandTotalLate = 0;
  int grandTotalA = 0;
  int grandTotalL = 0;
  String? selectedStudentId;
  final Map<String, DateTime> _selectedSummaryDateByClass = {};

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    displayMonth = now.month.toString().padLeft(2, '0');
    displayYear = now.year.toString();
    rangeFrom = DateTime(now.year, now.month, 1);
    rangeTo = now;
    _loadData();
  }

  DateTime get _reportStartDate {
    if (isMonthMode) {
      return DateTime(int.parse(displayYear!), int.parse(displayMonth!), 1);
    }
    return rangeFrom;
  }

  DateTime get _reportEndDate {
    if (isMonthMode) {
      final y = int.parse(displayYear!);
      final m = int.parse(displayMonth!);
      return DateTime(y, m + 1, 0);
    }
    return rangeTo.isBefore(rangeFrom) ? rangeFrom : rangeTo;
  }

  List<DateTime> get _reportDates {
    final dates = <DateTime>[];
    var cursor = _reportStartDate;
    while (!cursor.isAfter(_reportEndDate)) {
      dates.add(cursor);
      cursor = cursor.add(const Duration(days: 1));
    }
    return dates;
  }

  String _dateKey(DateTime date) => intl.DateFormat('yyyy-MM-dd').format(date);

  String? _cleanDateStr(dynamic val) {
    if (val == null) return null;
    final s = val.toString().trim();
    if (s.isEmpty || s == 'null') return null;
    if (s.length >= 10 && RegExp(r'^\d{4}-\d{2}-\d{2}').hasMatch(s)) {
      return s.substring(0, 10);
    }
    final parsed = DateTime.tryParse(s);
    if (parsed != null) {
      return intl.DateFormat('yyyy-MM-dd').format(parsed);
    }
    return null;
  }

  String get _reportTitle {
    if (isMonthMode) {
      final m = int.parse(displayMonth!);
      return "${getGregorianMonthUrdu(m)} $displayYear";
    }
    return "${_dateKey(_reportStartDate)} تا ${_dateKey(_reportEndDate)}";
  }

  Future<void> _loadData() async {
    setState(() => isLoading = true);
    final db = DBHelper();

    // Load Classes
    _classes = await db.getClassesForUser();

    final settings = await db.getSettings();
    hijriOffset =
        int.tryParse(settings['hijri_offset']?.toString() ?? '0') ?? 0;

    // Load Students
    final allStudents = await db.getStudentsForUser(includeInactive: true);
    _allStudents = allStudents.where((s) {
      final isDeleted = s['is_deleted'] == 1 || s['is_deleted'] == '1';
      if (isDeleted) return false;
      final st = (s['status'] ?? '').toString().trim().toLowerCase();
      final gradDate = s['graduation_date']?.toString().trim();
      return st == 'active' ||
          st == '' ||
          st == 'present' ||
          st == 'alumni' ||
          st == 'graduated' ||
          st == 'left' ||
          (gradDate != null && gradDate.isNotEmpty);
    }).toList();

    final allAttendance = await db.getAttendance();
    final allTeachers = await db.getTeachers();
    teacherInfoById = {
      for (final t in allTeachers) t['id'].toString(): t
    };
    teacherNameById = {
      for (final t in allTeachers)
        t['id'].toString(): t['name']?.toString() ?? ''
    };

    try {
      final dbSqlite = await db.database;
      final lastAttRows = await dbSqlite.rawQuery(
        "SELECT teacher_id, MAX(date) as last_date FROM teacher_period_attendance WHERE (is_deleted = 0 OR is_deleted IS NULL) GROUP BY teacher_id"
      );
      teacherLastAttDate = {
        for (final r in lastAttRows)
          if (r['teacher_id'] != null && r['last_date'] != null)
            r['teacher_id'].toString(): r['last_date'].toString()
      };
    } catch (_) {}
    assignedTeacherIdsByClass = {};
    final assignments = buildTeacherPeriodAssignments(
      teachers: allTeachers,
      classes: _classes,
    );
    for (final assignment in assignments) {
      assignedTeacherIdsByClass
          .putIfAbsent(assignment.className, () => <String>{})
          .add(assignment.teacherId.toString());
    }

    attData = {};
    teacherAttData = {};
    grandTotalSlots = 0;
    grandTotalP = 0;
    grandTotalLate = 0;
    grandTotalA = 0;
    grandTotalL = 0;

    for (var a in allAttendance) {
      String dateStr = a['date'].toString();
      DateTime? d = _safeParseDate(dateStr);
      if (d == null) continue;

      if (!d.isBefore(_reportStartDate) && !d.isAfter(_reportEndDate)) {
        String studentId = a['student_id'].toString().trim();
        String dayKey = _dateKey(d);

        String shift = _normalizeShift(a['shift']?.toString());
        String status = _normalizeStatus(a['status']?.toString());
        int delay = int.tryParse(a['delay_minutes']?.toString() ?? '0') ?? 0;

        if (!attData.containsKey(studentId)) attData[studentId] = {};
        if (!attData[studentId]!.containsKey(dayKey)) {
          attData[studentId]![dayKey] = {};
        }

        String finalStatus = (status == 'P' && delay > 0) ? 'Late' : status;
        attData[studentId]![dayKey]![shift] = finalStatus;

        if (status.isNotEmpty && status != 'N/A') {
          grandTotalSlots++;
          if (status == 'P') {
            if (delay > 0) {
              grandTotalLate++;
            } else {
              grandTotalP++;
            }
          }
          if (status == 'A') grandTotalA++;
          if (status == 'L') grandTotalL++;
        }
      }
    }

    for (final date in _reportDates) {
      final dayKey = _dateKey(date);
      final teacherRows = await db.getTeacherPeriodAttendanceByDate(dayKey);
      for (final row in teacherRows) {
        final className = (row['class_name'] ?? '').toString().trim();
        if (className.isEmpty) continue;
        final teacherId = row['teacher_id']?.toString() ?? '';
        if (teacherId.isEmpty) continue;
        final period = (row['period_no'] ?? '1').toString();
        final late = int.tryParse(row['late_minutes']?.toString() ?? '0') ?? 0;
        var status = _normalizeStatus(row['status']?.toString());
        final raw = (row['status'] ?? '').toString().trim().toUpperCase();
        if (raw == 'OD') status = 'P';
        if (status == 'P' && late > 0) status = 'Late';
        if (status.isEmpty) status = 'P';
        teacherAttData
            .putIfAbsent(className, () => {})
            .putIfAbsent(teacherId, () => {})
            .putIfAbsent(dayKey, () => {})[period] = status;
      }
    }

    _applyFilters();
    setState(() => isLoading = false);
  }

  Map<String, dynamic> _getHijriDateMap(String dateStr, int offset) {
    try {
      DateTime refDate = DateTime(2023, 7, 19); // 1 Muharram 1445
      DateTime targetDate = DateTime.parse(dateStr);

      int days = targetDate.difference(refDate).inDays;
      if (days < 0) return {'d': 1, 'm': 1, 'y': 1445}; // Fallback for older

      days += offset;

      int hYear = 1445;
      int hMonth = 1;

      while (true) {
        int daysInMonth = (hMonth % 2 != 0) ? 30 : 29;
        if (days < daysInMonth) {
          break;
        }
        days -= daysInMonth;
        hMonth++;
        if (hMonth > 12) {
          hMonth = 1;
          hYear++;
        }
      }
      return {'d': days + 1, 'm': hMonth, 'y': hYear};
    } catch (_) {
      return {'d': 1, 'm': 1, 'y': 1445};
    }
  }

  void _applyFilters() {
    if (selectedClassName == null || selectedClassName == "All") {
      _filteredStudents = _allStudents;
    } else {
      _filteredStudents = _allStudents.where((s) {
        return (s['class_name'] ?? '').toString().trim() == selectedClassName;
      }).toList();
    }
    if (selectedStudentId != null &&
        !_filteredStudents
            .any((s) => s['id'].toString() == selectedStudentId)) {
      selectedStudentId = null;
    }
  }

  List<Map<String, dynamic>> get _visibleStudents {
    if (selectedStudentId == null) return _filteredStudents;
    return _filteredStudents
        .where((s) => s['id'].toString() == selectedStudentId)
        .toList();
  }

  DateTime? _safeParseDate(String dateStr) {
    try {
      if (dateStr.contains('/')) {
        return intl.DateFormat('d/M/yyyy').parse(dateStr);
      }
      return DateTime.parse(dateStr);
    } catch (_) {}
    return null;
  }

  Future<void> _pickRangeDate(bool isFrom) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: isFrom ? rangeFrom : rangeTo,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      if (isFrom) {
        rangeFrom = picked;
        if (rangeTo.isBefore(rangeFrom)) rangeTo = rangeFrom;
      } else {
        rangeTo = picked;
        if (rangeTo.isBefore(rangeFrom)) rangeFrom = rangeTo;
      }
    });
    await _loadData();
  }

  String _normalizeShift(String? shift) {
    final s =
        (shift ?? '').toLowerCase().replaceAll(' ', '').replaceAll('_', '');
    if (s.contains('morning') || s == '1') return '1';
    if (s.contains('after') || s == '2') return '2';
    if (s.contains('noon') || s == '3') return '3';
    if (s.contains('evening') || s == '4') return '4';
    if (s == 'lesson1') return '1';
    if (s == 'lesson2') return '2';
    if (s == 'lesson3') return '3';
    if (s == 'lesson4') return '4';
    if (s == 'lesson5' || s == '5') return '5';
    if (s == 'lesson6' || s == '6') return '6';
    if (s == 'study' || s == '7') return '7';
    if (s == 'revision' || s == '8') return '8';
    return '1';
  }

  String _normalizeStatus(String? status) {
    String s = (status ?? '').toLowerCase();
    if (s.contains('present') || s.contains('حاضر') || s == 'p') return 'P';
    if (s.contains('absent') ||
        s.contains('غیرحاضر') ||
        s.contains('غیر حاضر') ||
        s == 'a') {
      return 'A';
    }
    if (s.contains('leave') ||
        s.contains('رخصت') ||
        s.contains('چھٹی') ||
        s == 'l') {
      return 'L';
    }
    return '';
  }

  Map<String, dynamic>? _classRowForName(String className) {
    for (final row in _classes) {
      if ((row['name'] ?? '').toString() == className) return row;
    }
    return null;
  }

  bool _classTextContains(String className, List<String> parts) {
    final row = _classRowForName(className);
    final text = [className, row?['class_type'], row?['section']]
        .whereType<Object>()
        .join(' ')
        .toLowerCase();
    return parts.any((part) => text.contains(part.toLowerCase()));
  }

  List<String> _periodKeysForClass(String className) {
    if (_classTextContains(className, const [
      'ناظرہ',
      'nazira',
      'Ù†Ø§Ø¸Ø±Û',
    ])) {
      return const ['1', '2'];
    }
    if (_classTextContains(className, const [
      'حفظ',
      'گردان',
      'حسن صوت',
      'hifz',
      'gardan',
      'husn',
      'Ø­ÙØ¸',
      'Ú¯Ø±Ø¯Ø§Ù†',
      'Ø­Ø³Ù†',
    ])) {
      return const ['1', '2', '3', '4'];
    }
    return const ['1', '2', '3', '4', '5', '6', '7', '8'];
  }

  int _gridColumnsForPeriods(int count) =>
      count <= 2 ? 2 : (count <= 4 ? 2 : 4);

  Widget _buildPeriodDotGrid(Map<String, String>? periods, List<String> keys) {
    return SizedBox(
      width: keys.length > 4 ? 20 : 14,
      height: keys.length > 4 ? 20 : 14,
      child: GridView.count(
        crossAxisCount: _gridColumnsForPeriods(keys.length),
        padding: EdgeInsets.zero,
        physics: const NeverScrollableScrollPhysics(),
        children: keys.map((key) => _buildDot(periods?[key])).toList(),
      ),
    );
  }

  pw.Widget _buildPdfPeriodDotGrid(
      Map<String, String>? periods, List<String> keys) {
    return pw.SizedBox(
      width: keys.length > 4 ? 15 : 11,
      height: keys.length > 4 ? 15 : 11,
      child: pw.GridView(
        crossAxisCount: _gridColumnsForPeriods(keys.length),
        childAspectRatio: 1,
        children: keys.map((key) => _attDot(periods?[key] ?? '')).toList(),
      ),
    );
  }

  Map<String, int> getHijriDateLoop(DateTime targetDate, int offset) {
    DateTime refDate = DateTime(2023, 7, 19);
    if (refDate.isAfter(targetDate)) {
      return {'d': 1, 'm': 1, 'y': 1445};
    }
    int days = targetDate.difference(refDate).inDays;
    days += offset;

    int hYear = 1445;
    int hMonth = 1;

    while (days > 0) {
      int daysInMonth = (hMonth % 2 == 1) ? 30 : 29;
      if (hMonth == 12 && ((11 * hYear + 14) % 30 < 11)) {
        daysInMonth = 30;
      }

      if (days >= daysInMonth) {
        days -= daysInMonth;
        hMonth++;
        if (hMonth > 12) {
          hMonth = 1;
          hYear++;
        }
      } else {
        break;
      }
    }
    return {'d': days + 1, 'm': hMonth, 'y': hYear};
  }

  String getHijriMonthName(int m) {
    const months = [
      "",
      "محرم الحرام",
      "صفر المظفر",
      "ربیع الاول",
      "ربیع الثانی",
      "جمادی الاول",
      "جمادی الثانی",
      "رجب المرجب",
      "شعبان المعظم",
      "رمضان المبارک",
      "شوال المکرم",
      "ذیقعدہ",
      "ذی الحجہ"
    ];
    if (m >= 1 && m <= 12) return months[m];
    return "";
  }

  String getGregorianMonthUrdu(int m) {
    const list = [
      "",
      "جنوری",
      "فروری",
      "مارچ",
      "اپریل",
      "مئی",
      "جون",
      "جولائی",
      "اگست",
      "ستمبر",
      "اکتوبر",
      "نومبر",
      "دسمبر"
    ];
    if (m >= 1 && m <= 12) return list[m];
    return "";
  }

  Future<void> _shareReport() async {
    try {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(isUrduGlobal.value
              ? "رپورٹ تیار ہو رہی ہے..."
              : "Preparing report...")));

      final Uint8List? imageBytes = await screenshotController.capture(
          delay: const Duration(milliseconds: 10));

      if (imageBytes != null) {
        final directory = await getApplicationDocumentsDirectory();
        final file = File('${directory.path}/attendance_report.png');
        await file.writeAsBytes(imageBytes);

        await Share.shareXFiles([XFile(file.path)], text: 'Attendance Report');
      }
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text("Error: $e")));
    }
  }

  Widget _buildDot(String? status) {
    Color c = Colors.grey.shade200;
    if (status == 'P') {
      c = Colors.green;
    } else if (status == 'Late') {
      c = Colors.orange;
    } else if (status == 'A') {
      c = Colors.red;
    } else if (status == 'L') {
      c = Colors.amber;
    }

    return Container(
      margin: const EdgeInsets.all(0.5),
      decoration: BoxDecoration(
        color: c,
        shape: BoxShape.circle,
      ),
    );
  }

  Widget _cellCustom(Widget child, double width, {Color? bgColor}) {
    return Container(
      width: width,
      constraints: const BoxConstraints(minHeight: 28),
      padding: const EdgeInsets.symmetric(horizontal: 1, vertical: 2),
      decoration: BoxDecoration(
        color: bgColor ?? Colors.white,
        border: Border.all(color: Colors.black87, width: 0.5),
      ),
      alignment: Alignment.center,
      child: child,
    );
  }

  Widget _cellText(String text, double width,
      {Color? bgColor,
      bool bold = false,
      double fontSize = 11,
      Color? textColor}) {
    return _cellCustom(
        Text(text,
            textAlign: TextAlign.center,
            softWrap: false,
            overflow: TextOverflow.visible,
            style: TextStyle(
                fontWeight: bold ? FontWeight.bold : FontWeight.normal,
                fontSize: fontSize,
                color: textColor ?? Colors.black87)),
        width,
        bgColor: bgColor);
  }

  String _aiInsight = "";
  bool _isAILoading = false;

  Future<void> _fetchAIInsight() async {
    if (grandTotalSlots == 0) return;
    setState(() => _isAILoading = true);

    double percP = (grandTotalP / grandTotalSlots) * 100;

    final prompt =
        "You are an Attendance Auditor for a Madrasa. Monthly Attendance Stats: Total Slots: $grandTotalSlots, Present: $grandTotalP (${percP.toStringAsFixed(1)}%), Absent: $grandTotalA, Leave: $grandTotalL. Provide a concise 2-line audit/advice in URDU for the ADMIN. Highlight any attendance issues or praise consistency. Just Urdu text.";

    try {
      final insight = await AIService.generateInsight(prompt);
      if (!mounted) return;
      setState(() {
        _aiInsight = insight;
      });
    } catch (_) {
      if (mounted) {
        setState(() {
          _aiInsight = isUrduGlobal.value
              ? "Unable to reach AI service"
              : "Unable to reach AI service";
        });
      }
    }
    if (mounted) {
      setState(() => _isAILoading = false);
    }
  }

  List<pw.Widget> _buildTeacherPdfRows(
    String className,
    List<DateTime> reportDates,
    pw.Font ttf,
  ) {
    final classRows = teacherAttData[className];
    final rawTeacherIds = <String>{
      ...(assignedTeacherIdsByClass[className] ?? <String>{}),
      ...(classRows?.keys ?? <String>{}),
    };
    final teacherIds = rawTeacherIds.where((tid) => teacherNameById.containsKey(tid)).toList();
    if (teacherIds.isEmpty) return const [];

    final reportYm = reportDates.isNotEmpty
        ? reportDates.first.toIso8601String().substring(0, 7)
        : '';

    teacherIds.sort((a, b) {
      final tA = teacherInfoById[a] ?? {};
      final tB = teacherInfoById[b] ?? {};
      final stA = (tA['status'] ?? 'Active').toString().trim().toLowerCase();
      final stB = (tB['status'] ?? 'Active').toString().trim().toLowerCase();
      final statusDateA = _cleanDateStr(tA['status_change_date']) ?? _cleanDateStr(tA['resignation_date']);
      final statusDateB = _cleanDateStr(tB['status_change_date']) ?? _cleanDateStr(tB['resignation_date']);
      final statusAYm = statusDateA != null && statusDateA.length >= 7 ? statusDateA.substring(0, 7) : '';
      final statusBYm = statusDateB != null && statusDateB.length >= 7 ? statusDateB.substring(0, 7) : '';

      final inactA = stA != 'active' && stA != 'حاضر' && stA != 'فعال' && stA.isNotEmpty && (statusAYm.isEmpty || reportYm.compareTo(statusAYm) >= 0);
      final inactB = stB != 'active' && stB != 'حاضر' && stB != 'فعال' && stB.isNotEmpty && (statusBYm.isEmpty || reportYm.compareTo(statusBYm) >= 0);
      if (!inactA && inactB) return -1;
      if (inactA && !inactB) return 1;
      return (teacherNameById[a] ?? '').compareTo(teacherNameById[b] ?? '');
    });

    final rows = <pw.Widget>[];

    for (final teacherId in teacherIds) {
      final tInfo = teacherInfoById[teacherId] ?? {};
      final tStatus = (tInfo['status'] ?? 'Active').toString().trim();
      final isInactive = tStatus.toLowerCase() != 'active' &&
          tStatus != 'حاضر' &&
          tStatus != 'فعال' &&
          tStatus.isNotEmpty;

      final joinDate = _cleanDateStr(tInfo['joining_date']);
      final joinYm = joinDate != null && joinDate.length >= 7 ? joinDate.substring(0, 7) : null;

      String? statusDate = _cleanDateStr(tInfo['status_change_date']) ?? _cleanDateStr(tInfo['resignation_date']);
      if (statusDate == null && isInactive) {
        final lastAtt = teacherLastAttDate[teacherId];
        if (lastAtt != null && lastAtt.isNotEmpty) {
          statusDate = _cleanDateStr(lastAtt);
        }
      }
      final statusYm = statusDate != null && statusDate.length >= 7 ? statusDate.substring(0, 7) : null;

      // کیا استاذ اس رپورٹ کے مہینے میں غیر فعال تھا؟
      final isInactiveForReport = isInactive && (statusYm == null || reportYm.isEmpty || reportYm.compareTo(statusYm) >= 0);

      bool hasAttInMonth = false;
      for (final date in reportDates) {
        final dayKey = _dateKey(date);
        final periods = classRows?[teacherId]?[dayKey];
        if (periods != null && periods.values.any((v) => v.isNotEmpty)) {
          hasAttInMonth = true;
          break;
        }
      }

      if (joinYm != null && reportYm.isNotEmpty && reportYm.compareTo(joinYm) < 0) {
        continue;
      }

      if (isInactive && statusYm != null && reportYm.isNotEmpty && reportYm.compareTo(statusYm) > 0 && !hasAttInMonth) {
        continue;
      }

      final teacherName = teacherNameById[teacherId]?.trim().isNotEmpty == true
          ? teacherNameById[teacherId]!
          : 'استاد $teacherId';

      String statusLabel = "غیر فعال";
      PdfColor statusColor = PdfColors.red800;
      PdfColor statusBg = PdfColors.red50;

      if (tStatus.contains('Terminated') || tStatus.contains('برطرف') || tStatus.contains('فارغ')) {
        statusLabel = "برطرف / فارغ";
        statusColor = PdfColors.red800;
        statusBg = PdfColors.red50;
      } else if (tStatus.contains('On Leave') || tStatus.contains('رخصت')) {
        statusLabel = "رخصت پر";
        statusColor = PdfColors.orange800;
        statusBg = PdfColors.orange50;
      } else if (tStatus.contains('مستعفی') || tStatus.contains('استعفی') || tStatus.toLowerCase().contains('resigned')) {
        statusLabel = "مستعفی";
        statusColor = PdfColors.grey700;
        statusBg = PdfColors.grey100;
      }
      final statusReason = (tInfo['status_reason'] ?? '').toString().trim();
      if (statusReason.isNotEmpty) {
        statusLabel += " ($statusReason)";
      }

      final showFullStatusBanner = (isInactiveForReport && !hasAttInMonth) ||
          (isInactive && statusYm != null && reportYm.isNotEmpty && reportYm.compareTo(statusYm) > 0);

      int present = 0, absent = 0, leave = 0;
      final periodKeys = _periodKeysForClass(className);
      final dayCells = <pw.Widget>[];

      if (showFullStatusBanner) {
        final totalWidth = reportDates.length * 17.0;
        final displayLabel = statusDate != null ? "$statusLabel (مورخہ: $statusDate)" : statusLabel;
        dayCells.add(
          pw.Container(
            width: totalWidth,
            height: 28,
            decoration: pw.BoxDecoration(
              color: statusBg,
              border: pw.Border.all(color: statusColor, width: 0.5),
            ),
            alignment: pw.Alignment.center,
            child: pw.Text(
              displayLabel,
              style: pw.TextStyle(
                font: ttf,
                fontSize: 6.5,
                fontWeight: pw.FontWeight.bold,
                color: statusColor,
              ),
            ),
          ),
        );
      } else {
        for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
          final date = reportDates[dIdx];
          final isFri = date.weekday == DateTime.friday;
          final dateStr = _dateKey(date);

          if (isFri) {
            dayCells.add(
              pw.Container(
                width: 17,
                height: 28,
                decoration: const pw.BoxDecoration(
                  color: PdfColors.red50,
                  border: pw.Border(
                    left: pw.BorderSide(color: PdfColors.red200, width: 0.4),
                    right: pw.BorderSide(color: PdfColors.red200, width: 0.4),
                  ),
                ),
                alignment: pw.Alignment.center,
                child: pw.Text("جمعہ",
                  style: pw.TextStyle(
                    font: ttf,
                    fontSize: 6.5,
                    fontWeight: pw.FontWeight.bold,
                    color: PdfColors.red800,
                  ),
                ),
              ),
            );
            continue;
          }

          if (joinDate != null && dateStr.compareTo(joinDate) < 0) {
            dayCells.add(
              pw.Container(
                width: 17,
                height: 28,
                decoration: pw.BoxDecoration(
                  border: pw.Border.all(color: PdfColors.grey400, width: 0.3),
                  color: PdfColors.grey100,
                ),
                alignment: pw.Alignment.center,
                child: pw.Text("-", style: pw.TextStyle(font: ttf, fontSize: 6.5, color: PdfColors.grey600)),
              ),
            );
            continue;
          }

          if (isInactiveForReport && statusDate != null && dateStr.compareTo(statusDate) >= 0) {
            final remCount = reportDates.length - dIdx;
            final remWidth = remCount * 17.0;
            final displayLabel = "$statusLabel (مورخہ: $statusDate)";
            dayCells.add(
              pw.Container(
                width: remWidth,
                height: 28,
                decoration: pw.BoxDecoration(
                  color: statusBg,
                  border: pw.Border.all(color: statusColor, width: 0.5),
                ),
                alignment: pw.Alignment.center,
                child: pw.Text(
                  displayLabel,
                  style: pw.TextStyle(
                    font: ttf,
                    fontSize: 6.0,
                    fontWeight: pw.FontWeight.bold,
                    color: statusColor,
                  ),
                ),
              ),
            );
            break;
          }

          final dayKey = dateStr;
          final periods = classRows?[teacherId]?[dayKey];
          if (periods != null && periods.isNotEmpty) {
            for (final st in periods.values) {
              if (st == 'P' || st == 'Late') {
                present++;
              } else if (st == 'A') {
                absent++;
              } else if (st == 'L') {
                leave++;
              }
            }
            dayCells.add(pw.Container(
              width: 17,
              height: 28,
              decoration: pw.BoxDecoration(
                  color: PdfColors.green50,
                  border: pw.Border.all(color: PdfColors.grey400, width: 0.3)),
              alignment: pw.Alignment.center,
              child: _buildPdfPeriodDotGrid(periods, periodKeys),
            ));
          } else {
            dayCells.add(pw.Container(
                width: 17,
                height: 28,
                decoration: pw.BoxDecoration(
                    color: PdfColors.green50,
                    border:
                        pw.Border.all(color: PdfColors.grey300, width: 0.3))));
          }
        }
      }

      final teacherStyle = pw.TextStyle(
          font: ttf,
          fontSize: 8,
          color: PdfColor.fromHex('#284D3D'),
          fontWeight: pw.FontWeight.bold);

      rows.add(pw.Row(children: [
        _attCellR('استاد', 18,
            ttf: ttf, bgColor: PdfColors.green50, bold: true),
        _attCellR(teacherName, 88,
            ttf: ttf,
            bgColor: PdfColors.green50,
            bold: true,
            color: PdfColor.fromHex('#284D3D')),
        _attCellR('استاد', 68,
            ttf: ttf,
            bgColor: PdfColors.green50,
            color: PdfColor.fromHex('#284D3D')),
        ...dayCells,
        _attCell((present > 0 || !isInactiveForReport) ? present.toString() : '-', 26,
            style: teacherStyle, bgColor: PdfColors.green50),
        _attCell((absent > 0 || !isInactiveForReport) ? absent.toString() : '-', 26,
            style: teacherStyle.copyWith(color: PdfColors.red),
            bgColor: PdfColors.green50),
        _attCell((leave > 0 || !isInactiveForReport) ? leave.toString() : '-', 26,
            style: teacherStyle.copyWith(color: PdfColors.amber),
            bgColor: PdfColors.green50),
      ]));
    }

    return rows;

  }

  Future<void> _printAttendancePdf() async {
    try {
      final fontData =
          await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final fallbackData =
          await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
          
      final ttf = pw.Font.ttf(fontData);
      final fallbackTtf = pw.Font.ttf(fallbackData);
      
      final boldStyle = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf], fontWeight: pw.FontWeight.bold);

      final reportDates = _reportDates;
      String titleGreg = _reportTitle;

      final pdf = pw.Document();
      pdf.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4.landscape,
          textDirection: pw.TextDirection.rtl,
          margin: const pw.EdgeInsets.all(12),
          theme: pw.ThemeData.withFont(
            base: ttf,
            fontFallback: [fallbackTtf],
          ),
          header: (ctx) => pw.Column(children: [
            pw.Container(
              padding: const pw.EdgeInsets.symmetric(vertical: 4),
              decoration: const pw.BoxDecoration(
                  border: pw.Border(
                      bottom:
                          pw.BorderSide(color: PdfColors.black, width: 1.5))),
              child: pw.Row(
                  mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                  children: [
                    pw.Text(
                        "\u0645\u062f\u0631\u0633\u06c1 \u0627\u0634\u0631\u0641 \u0627\u0644\u0645\u062f\u0627\u0631\u0633 \u2014 \u0641\u0627\u0631\u0648\u0642\u06c1\u060c \u0633\u0631\u06af\u0648\u062f\u06be\u0627",
                        style: boldStyle.copyWith(fontSize: 11)),
                    pw.Text(titleGreg,
                        style: boldStyle.copyWith(
                            fontSize: 12, color: PdfColors.green800)),
                    pw.Text(
                        "\u0631\u062c\u0633\u0679\u0631 \u062d\u0627\u0636\u0631\u06cc",
                        style: boldStyle.copyWith(fontSize: 14)),
                  ]),
            ),
            pw.SizedBox(height: 3),
            pw.Row(mainAxisAlignment: pw.MainAxisAlignment.center, children: [
              _attPdfLegend(PdfColors.green, "\u062d\u0627\u0636\u0631", ttf),
              pw.SizedBox(width: 10),
              _attPdfLegend(
                  PdfColors.orange, "\u062a\u0627\u062e\u06cc\u0631", ttf),
              pw.SizedBox(width: 10),
              _attPdfLegend(PdfColors.red,
                  "\u063a\u06cc\u0631 \u062d\u0627\u0636\u0631", ttf),
              pw.SizedBox(width: 10),
              _attPdfLegend(PdfColors.amber, "\u0631\u062e\u0635\u062a", ttf),
            ]),
            pw.SizedBox(height: 3),
          ]),
          build: (pw.Context context) {
            List<pw.Widget> allRows = [];

            // Grouping logic for PDF
            Map<String, List<Map<String, dynamic>>> grouped = {};
            for (var s in _visibleStudents) {
              String c = (s['class_name'] ?? 'General').toString();
              if (!grouped.containsKey(c)) grouped[c] = [];
              grouped[c]!.add(s);
            }

            for (var entry in grouped.entries) {
              String className = entry.key;
              List<Map<String, dynamic>> students = List<Map<String, dynamic>>.from(entry.value);
              final reportYm = reportDates.isNotEmpty
                  ? reportDates.first.toIso8601String().substring(0, 7)
                  : '';

              students.sort((a, b) {
                final aGradDateStr = a['graduation_date']?.toString().trim() ?? '';
                final bGradDateStr = b['graduation_date']?.toString().trim() ?? '';
                final aGradYm = aGradDateStr.length >= 7 ? aGradDateStr.substring(0, 7) : '';
                final bGradYm = bGradDateStr.length >= 7 ? bGradDateStr.substring(0, 7) : '';

                final aStatus = (a['status'] ?? '').toString().trim().toLowerCase();
                final bStatus = (b['status'] ?? '').toString().trim().toLowerCase();

                final aInactiveInThisMonth = (aStatus == 'alumni' || aStatus == 'graduated' || aStatus == 'left' || aStatus.contains('خارج') || aGradDateStr.isNotEmpty)
                    && (aGradYm.isEmpty || reportYm.compareTo(aGradYm) >= 0);
                final bInactiveInThisMonth = (bStatus == 'alumni' || bStatus == 'graduated' || bStatus == 'left' || bStatus.contains('خارج') || bGradDateStr.isNotEmpty)
                    && (bGradYm.isEmpty || reportYm.compareTo(bGradYm) >= 0);

                if (!aInactiveInThisMonth && bInactiveInThisMonth) return -1;
                if (aInactiveInThisMonth && !bInactiveInThisMonth) return 1;

                final admA = int.tryParse(a['admission_number']?.toString() ?? a['roll_number']?.toString() ?? '0') ?? 0;
                final admB = int.tryParse(b['admission_number']?.toString() ?? b['roll_number']?.toString() ?? '0') ?? 0;
                return admA.compareTo(admB);
              });

              students = students.where((s) {
                final admDateStr = _cleanDateStr(s['admission_date']);
                final admYm = (admDateStr != null && admDateStr.length >= 7) ? admDateStr.substring(0, 7) : null;
                if (admYm != null && reportYm.isNotEmpty && reportYm.compareTo(admYm) < 0) {
                  return false;
                }

                final sStatus = (s['status'] ?? 'Active').toString().trim();
                final gradDateStr = s['graduation_date']?.toString().trim() ?? '';
                final isInactive = sStatus.toLowerCase() == 'alumni' ||
                    sStatus.toLowerCase() == 'graduated' ||
                    sStatus.toLowerCase() == 'left' ||
                    sStatus.contains('خارج') ||
                    gradDateStr.isNotEmpty;

                if (!isInactive) return true;

                final sid = s['id'].toString();
                bool hasAtt = false;
                for (final date in reportDates) {
                  final dk = _dateKey(date);
                  final sm = attData[sid]?[dk];
                  if (sm != null && sm.values.any((v) => v.isNotEmpty)) {
                    hasAtt = true;
                    break;
                  }
                }
                if (hasAtt) return true;

                if (gradDateStr.isNotEmpty) {
                  final gradYm = gradDateStr.length >= 7 ? gradDateStr.substring(0, 7) : gradDateStr;
                  if (reportYm.isNotEmpty && reportYm.compareTo(gradYm) > 0) {
                    return false;
                  }
                  return true;
                }
                return false;
              }).toList();


              // Class Header
              allRows.add(pw.Container(
                width: double.infinity,
                padding:
                    const pw.EdgeInsets.symmetric(vertical: 4, horizontal: 8),
                color: PdfColors.grey300,
                child: pw.Text("کلاس: $className",
                    style: pw.TextStyle(
                        font: ttf,
                        fontWeight: pw.FontWeight.bold,
                        fontSize: 10)),
              ));

              // Table Header
              List<pw.Widget> hdr = [
                _attCell("#", 18, style: boldStyle.copyWith(fontSize: 7)),
                _attCellR(
                    isUrduGlobal.value
                        ? "\u0646\u0627\u0645 \u0637\u0627\u0644\u0628 \u0639\u0644\u0645"
                        : "Student Name",
                    88,
                    ttf: ttf,
                    style: boldStyle.copyWith(fontSize: 7)),
                _attCellR(
                    isUrduGlobal.value
                        ? "\u0648\u0644\u062f\u06cc\u062a"
                        : "Father Name",
                    68,
                    ttf: ttf,
                    style: boldStyle.copyWith(fontSize: 7)),
                ...reportDates.map((d) {
                  final isFri = d.weekday == DateTime.friday;
                  return pw.Container(
                    width: 17,
                    height: 18,
                    decoration: pw.BoxDecoration(
                        color: isFri ? PdfColors.red50 : PdfColors.grey200,
                        border: pw.Border.all(
                            color: PdfColors.grey400, width: 0.3)),
                    alignment: pw.Alignment.center,
                    child: pw.Text(d.day.toString(),
                        style: pw.TextStyle(
                            font: ttf,
                            fontSize: 7,
                            fontWeight: pw.FontWeight.bold,
                            color: isFri ? PdfColors.red800 : PdfColors.black)),
                  );
                }),
                _attCell(
                    isUrduGlobal.value ? "\u062d\u0627\u0636\u0631" : "P", 26,
                    style: boldStyle.copyWith(
                        font: ttf, fontSize: 7, color: PdfColors.green800)),
                _attCell(
                    isUrduGlobal.value ? "\u063a\u06cc\u0631" : "A", 26,
                    style: boldStyle.copyWith(
                        font: ttf, fontSize: 7, color: PdfColors.red)),
                _attCell(
                    isUrduGlobal.value ? "\u0631\u062e\u0635\u062a" : "L", 26,
                    style: boldStyle.copyWith(
                        font: ttf,
                        fontSize: 7,
                        color: PdfColors.amber,
                        fontWeight: pw.FontWeight.bold)),
              ];
              allRows.add(pw.Row(children: hdr));

              // Teacher rows
              allRows.addAll(_buildTeacherPdfRows(className, reportDates, ttf));

              // Student rows
              final periodKeys = _periodKeysForClass(className);
              int sr = 1;
              for (var s in students) {
                String sid = s['id'].toString();
                int mp = 0, ma = 0, ml = 0;

                final sStatus = (s['status'] ?? 'Active').toString().trim();
                final gradDateStr = s['graduation_date']?.toString().trim() ?? '';
                final gradYm = gradDateStr.length >= 7 ? gradDateStr.substring(0, 7) : '';

                // کیا طالب علم اس رپورٹ کے مہینے میں ان ایکٹو تھا؟
                final isInactiveForThisReport = (sStatus.toLowerCase() == 'alumni' ||
                    sStatus.toLowerCase() == 'graduated' ||
                    sStatus.toLowerCase() == 'left' ||
                    sStatus.contains('خارج') ||
                    gradDateStr.isNotEmpty) && (gradYm.isEmpty || reportYm.compareTo(gradYm) >= 0);

                final leavingReason = (s['leaving_reason'] ?? '').toString().trim();
                final alumniCat = (s['alumni_category'] ?? '').toString().trim();

                String statusLabel = "تعلیم مکمل ہو گئی";
                PdfColor statusBorderColor = PdfColors.green800;
                PdfColor statusBgColor = PdfColors.green50;
                PdfColor statusTextColor = PdfColors.green900;

                if (leavingReason.contains('خارج') || sStatus.contains('خارج') || alumniCat.contains('خارج')) {
                  statusLabel = "خارج کر دیا گیا";
                  statusBorderColor = PdfColors.red800;
                  statusBgColor = PdfColors.red50;
                  statusTextColor = PdfColors.red900;
                } else if (leavingReason.contains('چھوڑ') || leavingReason.contains('شوقیہ') || sStatus.toLowerCase().contains('left')) {
                  statusLabel = "چھوڑ گیا";
                  statusBorderColor = PdfColors.orange800;
                  statusBgColor = PdfColors.orange50;
                  statusTextColor = PdfColors.orange900;
                } else if (alumniCat.isNotEmpty) {
                  statusLabel = alumniCat;
                } else if (leavingReason.isNotEmpty) {
                  statusLabel = leavingReason;
                }

                final admDateStr = _cleanDateStr(s['admission_date']);
                List<pw.Widget> dcs = [];
                for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
                  final date = reportDates[dIdx];
                  final isFri = date.weekday == DateTime.friday;
                  String dayKey = _dateKey(date);

                  if (isFri) {
                    dcs.add(pw.Container(
                      width: 17,
                      height: 28,
                      decoration: const pw.BoxDecoration(
                        color: PdfColors.red50,
                        border: pw.Border(
                          left: pw.BorderSide(color: PdfColors.red200, width: 0.4),
                          right: pw.BorderSide(color: PdfColors.red200, width: 0.4),
                        ),
                      ),
                      alignment: pw.Alignment.center,
                      child: pw.Text("جمعہ",
                        style: pw.TextStyle(
                          font: ttf,
                          fontSize: 6.5,
                          fontWeight: pw.FontWeight.bold,
                          color: PdfColors.red800,
                        ),
                      ),
                    ));
                    continue;
                  }

                  if (admDateStr != null && dayKey.compareTo(admDateStr) < 0) {
                    dcs.add(pw.Container(
                      width: 17,
                      height: 28,
                      decoration: pw.BoxDecoration(
                        border: pw.Border.all(color: PdfColors.grey400, width: 0.3),
                        color: PdfColors.grey100,
                      ),
                      alignment: pw.Alignment.center,
                      child: pw.Text("-", style: pw.TextStyle(font: ttf, fontSize: 6.5, color: PdfColors.grey600)),
                    ));
                    continue;
                  }

                  if (isInactiveForThisReport && gradDateStr.isNotEmpty && dayKey.compareTo(gradDateStr) >= 0) {
                    final remainingDays = reportDates.length - dIdx;
                    final width = remainingDays * 17.0;
                    final gradDisplay = gradDateStr.isNotEmpty ? " ($gradDateStr)" : "";
                    dcs.add(pw.Container(
                      width: width,
                      height: 28,
                      decoration: pw.BoxDecoration(
                        color: statusBgColor,
                        border: pw.Border.all(color: statusBorderColor, width: 0.5),
                      ),
                      alignment: pw.Alignment.center,
                      child: pw.Text(statusLabel + gradDisplay,
                          style: pw.TextStyle(font: ttf, fontSize: 6.5, color: statusTextColor, fontWeight: pw.FontWeight.bold)),
                    ));
                    break;
                  }

                  Map<String, String>? shiftsMap = attData[sid]?[dayKey];
                  PdfColor bg =
                      sr % 2 == 0 ? PdfColors.grey100 : PdfColors.white;
                  if (shiftsMap != null) {
                    for (final key in periodKeys) {
                      final st = shiftsMap[key] ?? '';
                      if (st == 'P' || st == 'Late') {
                        mp++;
                      } else if (st == 'A') {
                        ma++;
                      } else if (st == 'L') {
                        ml++;
                      }
                    }
                    dcs.add(pw.Container(
                      width: 17,
                      height: 28,
                      decoration: pw.BoxDecoration(
                          color: bg,
                          border: pw.Border.all(
                              color: PdfColors.grey400, width: 0.3)),
                      alignment: pw.Alignment.center,
                      child: _buildPdfPeriodDotGrid(shiftsMap, periodKeys),
                    ));
                  } else {
                    dcs.add(pw.Container(
                        width: 17,
                        height: 28,
                        decoration: pw.BoxDecoration(
                            color: bg,
                            border: pw.Border.all(
                                color: PdfColors.grey300, width: 0.3))));
                  }
                }
                PdfColor rowBg =
                    sr % 2 == 0 ? PdfColors.grey100 : PdfColors.white;
                allRows.add(pw.Row(children: [
                  _attCell(sr.toString(), 18, bgColor: rowBg),
                  _attCellR(s['name']?.toString() ?? '', 88,
                      ttf: ttf, bgColor: rowBg, bold: true),
                  _attCellR(s['father_name']?.toString() ?? '', 68,
                      ttf: ttf, bgColor: rowBg, color: PdfColors.grey700),
                  ...dcs,
                  _attCell((mp > 0 || !isInactiveForThisReport) ? mp.toString() : '-', 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.green800,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                  _attCell((ma > 0 || !isInactiveForThisReport) ? ma.toString() : '-', 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.red,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                  _attCell((ml > 0 || !isInactiveForThisReport) ? ml.toString() : '-', 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.amber,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                ]));
                sr++;
              }
              // Class Period Summary in PDF
              allRows.addAll(_buildPdfClassPeriodSummaryPanel(students, className, reportDates, attData, ttf));
              allRows.add(pw.SizedBox(height: 10));
            }
            return allRows;
          },
        ),
      );

      await Printing.layoutPdf(
        onLayout: (PdfPageFormat fmt) async => pdf.save(),
        name: 'Attendance_Register.pdf',
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text("Print Error: $e")));
      }
    }
  }

  pw.Widget _attCell(String text, double w,
          {pw.TextStyle? style, PdfColor? bgColor}) =>
      pw.Container(
          width: w,
          height: 28,
          alignment: pw.Alignment.center,
          decoration: pw.BoxDecoration(
              color: bgColor ?? PdfColors.white,
              border: pw.Border.all(color: PdfColors.grey600, width: 0.4)),
          child: pw.Text(text,
              textAlign: pw.TextAlign.center,
              style: style ?? const pw.TextStyle(fontSize: 8)));

  pw.Widget _attCellR(String text, double w,
          {required pw.Font ttf,
          PdfColor? bgColor,
          bool bold = false,
          PdfColor? color,
          pw.TextStyle? style}) =>
      pw.Container(
          width: w,
          height: 28,
          padding: const pw.EdgeInsets.symmetric(horizontal: 3),
          alignment: pw.Alignment.centerRight,
          decoration: pw.BoxDecoration(
              color: bgColor ?? PdfColors.white,
              border: pw.Border.all(color: PdfColors.grey600, width: 0.4)),
          child: pw.Text(text,
              maxLines: 1,
              style: style ??
                  pw.TextStyle(
                      font: ttf,
                      fontSize: 8,
                      fontWeight: bold ? pw.FontWeight.bold : pw.FontWeight.normal,
                      color: color ?? PdfColors.black)));

  List<pw.Widget> _buildPdfClassPeriodSummaryPanel(
    List<Map<String, dynamic>> students,
    String className,
    List<DateTime> reportDates,
    Map<String, Map<String, Map<String, String>>> attData,
    pw.Font ttf,
  ) {
    if (reportDates.isEmpty) return [];

    final selectedDate = _selectedSummaryDateByClass[className] ?? reportDates.last;
    final periodKeys = _periodKeysForClass(className);
    final dk = _dateKey(selectedDate);

    final Map<String, Map<String, int>> periodStats = {};
    for (final pKey in periodKeys) {
      periodStats[pKey] = {'p': 0, 'late': 0, 'a': 0, 'l': 0};
    }

    for (final s in students) {
      final sid = s['id'].toString();
      final sm = attData[sid]?[dk];
      if (sm != null) {
        for (final pKey in periodKeys) {
          final st = sm[pKey] ?? '';
          if (st == 'P') {
            periodStats[pKey]!['p'] = (periodStats[pKey]!['p'] ?? 0) + 1;
          } else if (st == 'Late') {
            periodStats[pKey]!['late'] = (periodStats[pKey]!['late'] ?? 0) + 1;
          } else if (st == 'A') {
            periodStats[pKey]!['a'] = (periodStats[pKey]!['a'] ?? 0) + 1;
          } else if (st == 'L') {
            periodStats[pKey]!['l'] = (periodStats[pKey]!['l'] ?? 0) + 1;
          }
        }
      }
    }

    final dateLabel = intl.DateFormat('MMM-yy-dd').format(selectedDate);

    return [
      pw.SizedBox(height: 6),
      pw.Container(
        padding: const pw.EdgeInsets.all(6),
        decoration: pw.BoxDecoration(
          color: PdfColors.white,
          border: pw.Border.all(color: PdfColors.grey600, width: 0.5),
        ),
        child: pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.stretch,
          children: [
            pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: [
                pw.Container(
                  padding: const pw.EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: pw.BoxDecoration(
                    border: pw.Border.all(color: PdfColors.grey400, width: 0.5),
                  ),
                  child: pw.Text(dateLabel, style: pw.TextStyle(font: ttf, fontSize: 8, fontWeight: pw.FontWeight.bold)),
                ),
                pw.Text("پیریڈ وائز حاضری سمری", style: pw.TextStyle(font: ttf, fontSize: 9, fontWeight: pw.FontWeight.bold)),
              ],
            ),
            pw.SizedBox(height: 4),
            pw.Divider(thickness: 0.5, color: PdfColors.grey400),
            pw.SizedBox(height: 4),
            pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceAround,
              children: periodKeys.asMap().entries.map((entry) {
                final idx = entry.key;
                final pKey = entry.value;
                final pTitle = "سبق ${idx + 1}";
                final stats = periodStats[pKey] ?? {'p': 0, 'late': 0, 'a': 0, 'l': 0};

                return pw.Container(
                  padding: const pw.EdgeInsets.all(4),
                  decoration: pw.BoxDecoration(
                    border: pw.Border.all(color: PdfColors.grey400, width: 0.4),
                  ),
                  child: pw.Column(
                    children: [
                      pw.Text(pTitle, style: pw.TextStyle(font: ttf, fontSize: 7.5, fontWeight: pw.FontWeight.bold)),
                      pw.SizedBox(height: 2),
                      pw.Text("ح:${stats['p']} ت:${stats['late']} غ:${stats['a']} ر:${stats['l']}",
                          style: pw.TextStyle(font: ttf, fontSize: 6.5, fontWeight: pw.FontWeight.bold, color: PdfColors.grey800)),
                    ],
                  ),
                );
              }).toList(),
            ),
          ],
        ),
      ),
    ];
  }

  pw.Widget _attDot(String status) {
    PdfColor c = PdfColors.grey200;
    if (status == 'P') {
      c = PdfColors.green;
    } else if (status == 'Late') {
      c = PdfColors.orange;
    } else if (status == 'A') {
      c = PdfColors.red;
    } else if (status == 'L') {
      c = PdfColors.amber;
    }
    return pw.Container(
        margin: const pw.EdgeInsets.all(0.5),
        decoration: pw.BoxDecoration(color: c, shape: pw.BoxShape.circle));
  }

  pw.Widget _attPdfLegend(PdfColor color, String label, pw.Font ttf) =>
      pw.Row(children: [
        pw.Container(
            width: 7,
            height: 7,
            decoration:
                pw.BoxDecoration(color: color, shape: pw.BoxShape.circle)),
        pw.SizedBox(width: 3),
        pw.Text(label,
            style: pw.TextStyle(font: ttf, fontSize: 7.5, color: color)),
      ]);

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        } else {
          Navigator.pushReplacement(
              context, MaterialPageRoute(builder: (c) => const DashboardPage()));
        }
      },
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          title: Text(isUrduGlobal.value ? "حاضری رپورٹ" : "Attendance Report"),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
          actions: [
            IconButton(
                icon: const Icon(Icons.print), onPressed: _printAttendancePdf),
            IconButton(icon: const Icon(Icons.share), onPressed: _shareReport),
            IconButton(
                icon: const Icon(Icons.psychology), onPressed: _fetchAIInsight),
          ],
        ),
        body: Column(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              color: Colors.white,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: SegmentedButton<bool>(
                      segments: [
                        ButtonSegment(
                            value: true,
                            label: Text(isUrduGlobal.value ? "ماہانہ" : "Monthly"),
                            icon: const Icon(Icons.calendar_month)),
                        ButtonSegment(
                            value: false,
                            label: Text(isUrduGlobal.value ? "تاریخ کے لحاظ سے" : "By Date"),
                            icon: const Icon(Icons.date_range)),
                      ],
                      selected: {isMonthMode},
                      onSelectionChanged: (value) {
                        setState(() => isMonthMode = value.first);
                        _loadData();
                      },
                    ),
                  ),
                  Row(
                    children: [
                      if (isMonthMode) ...[
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            isExpanded: true,
                            value: displayMonth,
                            decoration: InputDecoration(
                                labelText: isUrduGlobal.value ? "مہینہ منتخب کریں" : "Select Month",
                                border: const OutlineInputBorder(),
                                contentPadding:
                                    const EdgeInsets.symmetric(horizontal: 10)),
                            items: List.generate(12, (index) {
                              int m = index + 1;
                              return DropdownMenuItem(
                                  value: m.toString().padLeft(2, '0'),
                                  child: Text(getGregorianMonthUrdu(m)));
                            }),
                            onChanged: (v) {
                              setState(() => displayMonth = v);
                              _loadData();
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            isExpanded: true,
                            value: displayYear,
                            decoration: InputDecoration(
                                labelText: isUrduGlobal.value ? "سال منتخب کریں" : "Select Year",
                                border: const OutlineInputBorder(),
                                contentPadding:
                                    const EdgeInsets.symmetric(horizontal: 10)),
                            items: List.generate(5, (index) {
                              int y = DateTime.now().year - 2 + index;
                              return DropdownMenuItem(
                                  value: y.toString(),
                                  child: Text(y.toString()));
                            }),
                            onChanged: (v) {
                              setState(() => displayYear = v);
                              _loadData();
                            },
                          ),
                        ),
                      ] else ...[
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: () => _pickRangeDate(true),
                            icon: const Icon(Icons.today),
                            label: Text("سے: ${_dateKey(rangeFrom)}"),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: () => _pickRangeDate(false),
                            icon: const Icon(Icons.event),
                            label: Text("تک: ${_dateKey(rangeTo)}"),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          isExpanded: true,
                          value: selectedClassName,
                          decoration: const InputDecoration(
                              labelText: "کلاس منتخب کریں",
                              border: OutlineInputBorder(),
                              contentPadding:
                                  EdgeInsets.symmetric(horizontal: 10)),
                          items: [
                            const DropdownMenuItem(
                                value: "All", child: Text("تمام کلاسز")),
                            ..._classes.map((c) => DropdownMenuItem(
                                value: c['name'], child: Text(c['name'] ?? '')))
                          ],
                          onChanged: (v) {
                            setState(() => selectedClassName = v);
                            _applyFilters();
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          isExpanded: true,
                          value: selectedStudentId,
                          decoration: const InputDecoration(
                              labelText: "طالب علم (اختیاری)",
                              border: OutlineInputBorder(),
                              contentPadding:
                                  EdgeInsets.symmetric(horizontal: 10)),
                          items: [
                            const DropdownMenuItem(
                                value: null, child: Text("تمام طلبہ")),
                            ..._filteredStudents.map((s) => DropdownMenuItem(
                                value: s['id'].toString(),
                                child: Text(
                                    "${s['name']} - ${s['father_name'] ?? ''}")))
                          ],
                          onChanged: (v) {
                            setState(() => selectedStudentId = v);
                            _applyFilters();
                          },
                        ),
                      ),
                    ],
                  ),
                  if (selectedStudentId != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: ElevatedButton.icon(
                        onPressed: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                                builder: (c) => StudentReportPage(
                                    forceStudentId: selectedStudentId))),
                        icon: const Icon(Icons.person),
                        label: const Text("انفرادی رپورٹ دیکھیں"),
                        style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white),
                      ),
                    ),
                ],
              ),
            ),
            Expanded(
              child: isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : SingleChildScrollView(
                      child: Column(
                        children: [
                          _buildReportBody(),
                          if (_aiInsight.isNotEmpty || _isAILoading)
                            Padding(
                              padding: const EdgeInsets.all(12.0),
                              child: Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                    color: primaryGreen,
                                    borderRadius: BorderRadius.circular(10)),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(children: [
                                      const Icon(Icons.auto_awesome,
                                          color: Colors.white, size: 20),
                                      const SizedBox(width: 8),
                                      Text(
                                          isUrduGlobal.value
                                              ? "اے آئی حاضری تبصرہ"
                                              : "AI Attendance Insight",
                                          style: const TextStyle(
                                              color: Colors.white,
                                              fontWeight: FontWeight.bold))
                                    ]),
                                    const Divider(color: Colors.white24),
                                    _isAILoading
                                        ? const LinearProgressIndicator(
                                            color: Colors.white24)
                                        : Text(_aiInsight,
                                            style: const TextStyle(
                                                color: Colors.white,
                                                fontSize: 13,
                                                fontStyle: FontStyle.italic)),
                                  ],
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildTeacherReportRows(
      String className, List<DateTime> reportDates) {
    final classRows = teacherAttData[className];
    final rawTeacherIds = <String>{
      ...(assignedTeacherIdsByClass[className] ?? <String>{}),
      ...(classRows?.keys ?? <String>{}),
    };
    final teacherIds = rawTeacherIds.where((tid) => teacherNameById.containsKey(tid)).toList();
    if (teacherIds.isEmpty) return const [];

    final reportYm = reportDates.isNotEmpty
        ? reportDates.first.toIso8601String().substring(0, 7)
        : '';

    teacherIds.sort((a, b) {
      final tA = teacherInfoById[a] ?? {};
      final tB = teacherInfoById[b] ?? {};
      final stA = (tA['status'] ?? 'Active').toString().trim().toLowerCase();
      final stB = (tB['status'] ?? 'Active').toString().trim().toLowerCase();
      final statusDateA = _cleanDateStr(tA['status_change_date']) ?? _cleanDateStr(tA['resignation_date']);
      final statusDateB = _cleanDateStr(tB['status_change_date']) ?? _cleanDateStr(tB['resignation_date']);
      final statusAYm = statusDateA != null && statusDateA.length >= 7 ? statusDateA.substring(0, 7) : '';
      final statusBYm = statusDateB != null && statusDateB.length >= 7 ? statusDateB.substring(0, 7) : '';

      final inactA = stA != 'active' && stA != 'حاضر' && stA != 'فعال' && stA.isNotEmpty && (statusAYm.isEmpty || reportYm.compareTo(statusAYm) >= 0);
      final inactB = stB != 'active' && stB != 'حاضر' && stB != 'فعال' && stB.isNotEmpty && (statusBYm.isEmpty || reportYm.compareTo(statusBYm) >= 0);
      if (!inactA && inactB) return -1;
      if (inactA && !inactB) return 1;
      return (teacherNameById[a] ?? '').compareTo(teacherNameById[b] ?? '');
    });

    final widgets = <Widget>[];

    for (final teacherId in teacherIds) {
      final tInfo = teacherInfoById[teacherId] ?? {};
      final tStatus = (tInfo['status'] ?? 'Active').toString().trim();
      final isInactive = tStatus.toLowerCase() != 'active' &&
          tStatus != 'حاضر' &&
          tStatus != 'فعال' &&
          tStatus.isNotEmpty;

      final joinDate = _cleanDateStr(tInfo['joining_date']);
      final joinYm = joinDate != null && joinDate.length >= 7 ? joinDate.substring(0, 7) : null;

      String? statusDate = _cleanDateStr(tInfo['status_change_date']) ?? _cleanDateStr(tInfo['resignation_date']);
      if (statusDate == null && isInactive) {
        final lastAtt = teacherLastAttDate[teacherId];
        if (lastAtt != null && lastAtt.isNotEmpty) {
          statusDate = _cleanDateStr(lastAtt);
        }
      }
      final statusYm = statusDate != null && statusDate.length >= 7 ? statusDate.substring(0, 7) : null;

      // کیا استاذ اس رپورٹ کے مہینے میں غیر فعال تھا؟
      final isInactiveForReport = isInactive && (statusYm == null || reportYm.isEmpty || reportYm.compareTo(statusYm) >= 0);

      bool hasAttInMonth = false;
      for (final date in reportDates) {
        final dayKey = _dateKey(date);
        final periods = classRows?[teacherId]?[dayKey];
        if (periods != null && periods.values.any((v) => v.isNotEmpty)) {
          hasAttInMonth = true;
          break;
        }
      }

      if (joinYm != null && reportYm.isNotEmpty && reportYm.compareTo(joinYm) < 0) {
        continue;
      }

      if (isInactive && statusYm != null && reportYm.isNotEmpty && reportYm.compareTo(statusYm) > 0 && !hasAttInMonth) {
        continue;
      }

      final teacherName = teacherNameById[teacherId]?.trim().isNotEmpty == true
          ? teacherNameById[teacherId]!
          : 'استاد $teacherId';

      String statusLabel = "غیر فعال";
      Color statusColor = const Color(0xFFDC2626);
      Color statusBg = const Color(0xFFFDE8E8);

      if (tStatus.contains('Terminated') || tStatus.contains('برطرف') || tStatus.contains('فارغ')) {
        statusLabel = "برطرف / فارغ";
        statusColor = const Color(0xFFDC2626);
        statusBg = const Color(0xFFFDE8E8);
      } else if (tStatus.contains('On Leave') || tStatus.contains('رخصت')) {
        statusLabel = "رخصت پر";
        statusColor = const Color(0xFFFD7E14);
        statusBg = const Color(0xFFFFF3CD);
      } else if (tStatus.contains('مستعفی') || tStatus.contains('استعفی') || tStatus.toLowerCase().contains('resigned')) {
        statusLabel = "مستعفی";
        statusColor = const Color(0xFF6C757D);
        statusBg = const Color(0xFFF8F9FA);
      }
      final statusReason = (tInfo['status_reason'] ?? '').toString().trim();
      if (statusReason.isNotEmpty) {
        statusLabel += " ($statusReason)";
      }

      final showFullStatusBanner = (isInactiveForReport && !hasAttInMonth) ||
          (isInactive && statusYm != null && reportYm.isNotEmpty && reportYm.compareTo(statusYm) > 0);

      int monthP = 0, monthA = 0, monthL = 0;
      final periodKeys = _periodKeysForClass(className);
      final dayCells = <Widget>[];

      if (showFullStatusBanner) {
        final totalWidth = reportDates.length * 25.0;
        final displayLabel = statusDate != null ? "$statusLabel (مورخہ: $statusDate)" : statusLabel;
        dayCells.add(
          Container(
            width: totalWidth,
            height: 28,
            decoration: BoxDecoration(
              color: statusBg,
              border: Border.all(color: statusColor.withOpacity(0.4)),
            ),
            alignment: Alignment.center,
            child: Text(
              displayLabel,
              style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.bold,
                color: statusColor,
              ),
            ),
          ),
        );
      } else {
        for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
          final date = reportDates[dIdx];
          final isFri = date.weekday == DateTime.friday;
          final dateStr = _dateKey(date);

          if (isFri) {
            dayCells.add(
              Container(
                width: 25.0,
                height: 28.0,
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF1F2),
                  border: Border(
                    left: BorderSide(color: Colors.red.shade300, width: 0.8),
                    right: BorderSide(color: Colors.red.shade300, width: 0.8),
                  ),
                ),
                alignment: Alignment.center,
                child: Text(
                  "جمعہ",
                  style: TextStyle(
                    fontSize: 8.5,
                    fontWeight: FontWeight.bold,
                    color: Colors.red.shade700,
                  ),
                ),
              ),
            );
            continue;
          }

          if (joinDate != null && dateStr.compareTo(joinDate) < 0) {
            dayCells.add(
              Container(
                width: 25.0,
                height: 28,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.grey.shade300),
                  color: Colors.grey.shade100,
                ),
                alignment: Alignment.center,
                child: Text("-", style: TextStyle(fontSize: 9, color: Colors.grey.shade600)),
              ),
            );
            continue;
          }

          if (isInactiveForReport && statusDate != null && dateStr.compareTo(statusDate) >= 0) {
            final remCount = reportDates.length - dIdx;
            final remWidth = remCount * 25.0;
            final displayLabel = "$statusLabel (مورخہ: $statusDate)";
            dayCells.add(
              Container(
                width: remWidth,
                height: 28,
                decoration: BoxDecoration(
                  color: statusBg,
                  border: Border.all(color: statusColor.withOpacity(0.4)),
                ),
                alignment: Alignment.center,
                child: Text(
                  displayLabel,
                  style: TextStyle(
                    fontSize: 9,
                    fontWeight: FontWeight.bold,
                    color: statusColor,
                  ),
                ),
              ),
            );
            break;
          }

          final dayKey = dateStr;
          final periods = classRows?[teacherId]?[dayKey];
          if (periods != null && periods.isNotEmpty) {
            for (final st in periods.values) {
              if (st == 'P' || st == 'Late') {
                monthP++;
              } else if (st == 'A') {
                monthA++;
              } else if (st == 'L') {
                monthL++;
              }
            }
            dayCells
                .add(_cellCustom(_buildPeriodDotGrid(periods, periodKeys), 25));
          } else {
            dayCells.add(_cellText('', 25));
          }
        }
      }

      final totalSlots = monthP + monthA + monthL;
      String status = "-";
      Color currentStatusColor = primaryGreen;
      if (isInactiveForReport && (totalSlots == 0 || showFullStatusBanner)) {
        status = statusLabel;
        currentStatusColor = statusColor;
      } else if (totalSlots > 0) {
        status = '${((monthP / totalSlots) * 100).toStringAsFixed(0)}% حاضر';
      }

      widgets.add(Row(children: [
        _cellText('استاد', 30, bold: true, textColor: primaryGreen),
        _cellText('', 60),
        _cellText(teacherName, 150, bold: true, textColor: primaryGreen),
        _cellText('استاد', 90, textColor: primaryGreen),
        ...dayCells,
        _cellText((monthP > 0 || !isInactiveForReport) ? monthP.toString() : '-', 35, textColor: Colors.green),
        _cellText((monthA > 0 || !isInactiveForReport) ? monthA.toString() : '-', 35, textColor: Colors.red),
        _cellText((monthL > 0 || !isInactiveForReport) ? monthL.toString() : '-', 35, textColor: Colors.amber.shade800),
        _cellText(status, 60, bold: true, textColor: currentStatusColor, fontSize: (isInactiveForReport && (totalSlots == 0 || showFullStatusBanner)) ? 9 : 11),
      ]));
    }

    return widgets;
  }


  Widget _buildReportBody() {
    final reportDates = _reportDates;
    String titleGreg = _reportTitle;

    // Grouping for UI
    Map<String, List<Map<String, dynamic>>> grouped = {};
    for (var s in _visibleStudents) {
      String c = (s['class_name'] ?? 'General').toString();
      if (!grouped.containsKey(c)) grouped[c] = [];
      grouped[c]!.add(s);
    }

    return Screenshot(
      controller: screenshotController,
      child: Container(
        color: Colors.white,
        padding: const EdgeInsets.all(12),
        margin: const EdgeInsets.all(8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text("مدرسہ اشرف المدارس — فاروقہ، سرگودھا",
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
            Text("رجسٹر حاضری — $titleGreg",
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 14,
                    color: Colors.green,
                    fontWeight: FontWeight.bold)),
            const SizedBox(height: 10),
            Directionality(
              textDirection: TextDirection.rtl,
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (var entry in grouped.entries) ...() {
                      final reportYm = reportDates.isNotEmpty
                          ? reportDates.first.toIso8601String().substring(0, 7)
                          : '';

                      final sortedList = List<Map<String, dynamic>>.from(entry.value);
                      sortedList.sort((a, b) {
                        final aGradDateStr = a['graduation_date']?.toString().trim() ?? '';
                        final bGradDateStr = b['graduation_date']?.toString().trim() ?? '';
                        final aGradYm = aGradDateStr.length >= 7 ? aGradDateStr.substring(0, 7) : '';
                        final bGradYm = bGradDateStr.length >= 7 ? bGradDateStr.substring(0, 7) : '';

                        final aStatus = (a['status'] ?? '').toString().trim().toLowerCase();
                        final bStatus = (b['status'] ?? '').toString().trim().toLowerCase();

                        final aInactiveInThisMonth = (aStatus == 'alumni' || aStatus == 'graduated' || aStatus == 'left' || aStatus.contains('خارج') || aGradDateStr.isNotEmpty)
                            && (aGradYm.isEmpty || reportYm.compareTo(aGradYm) >= 0);
                        final bInactiveInThisMonth = (bStatus == 'alumni' || bStatus == 'graduated' || bStatus == 'left' || bStatus.contains('خارج') || bGradDateStr.isNotEmpty)
                            && (bGradYm.isEmpty || reportYm.compareTo(bGradYm) >= 0);

                        if (!aInactiveInThisMonth && bInactiveInThisMonth) return -1;
                        if (aInactiveInThisMonth && !bInactiveInThisMonth) return 1;

                        final admA = int.tryParse(a['admission_number']?.toString() ?? a['roll_number']?.toString() ?? '0') ?? 0;
                        final admB = int.tryParse(b['admission_number']?.toString() ?? b['roll_number']?.toString() ?? '0') ?? 0;
                        return admA.compareTo(admB);
                      });

                      final filteredClassStudents = sortedList.where((s) {
                        final admDateStr = _cleanDateStr(s['admission_date']);
                        final admYm = (admDateStr != null && admDateStr.length >= 7) ? admDateStr.substring(0, 7) : null;
                        if (admYm != null && reportYm.isNotEmpty && reportYm.compareTo(admYm) < 0) {
                          return false;
                        }

                        final sStatus = (s['status'] ?? 'Active').toString().trim();
                        final gradDateStr = s['graduation_date']?.toString().trim() ?? '';
                        final isInactive = sStatus.toLowerCase() == 'alumni' ||
                            sStatus.toLowerCase() == 'graduated' ||
                            sStatus.toLowerCase() == 'left' ||
                            sStatus.contains('خارج') ||
                            gradDateStr.isNotEmpty;

                        if (!isInactive) return true;

                        final sid = s['id'].toString();
                        bool hasAtt = false;
                        for (final date in reportDates) {
                          final dk = _dateKey(date);
                          final sm = attData[sid]?[dk];
                          if (sm != null && sm.values.any((v) => v.isNotEmpty)) {
                            hasAtt = true;
                            break;
                          }
                        }
                        if (hasAtt) return true;

                        if (gradDateStr.isNotEmpty) {
                          final gradYm = gradDateStr.length >= 7 ? gradDateStr.substring(0, 7) : gradDateStr;
                          if (reportYm.isNotEmpty && reportYm.compareTo(gradYm) > 0) {
                            return false;
                          }
                          return true;
                        }
                        return false;
                      }).toList();


                      return [
                        // Class Header
                        Container(
                          width: 1000,
                          padding: const EdgeInsets.symmetric(
                              vertical: 4, horizontal: 8),
                          color: Colors.grey.shade200,
                          child: Text("کلاس: ${entry.key}",
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 13)),
                        ),
                        // Table Header
                        IntrinsicHeight(
                          child: Row(
                            children: [
                              _cellText("#", 30,
                                  bgColor: Colors.grey.shade200, bold: true),
                              _cellText("رول نمبر", 60,
                                  bgColor: Colors.grey.shade200, bold: true),
                              _cellText("نام طالب علم", 150,
                                  bgColor: Colors.grey.shade200, bold: true),
                              _cellText("ولدیت", 90,
                                  bgColor: Colors.grey.shade100, bold: true),
                              ...reportDates.map((date) {
                                final isFri = date.weekday == DateTime.friday;
                                String dateStr =
                                    date.toIso8601String().split('T')[0];
                                Map<String, dynamic> hInfo =
                                    _getHijriDateMap(dateStr, hijriOffset);
                                return Container(
                                  width: 25,
                                  decoration: BoxDecoration(
                                      border: Border.all(
                                          color: isFri
                                              ? Colors.red.shade300
                                              : Colors.grey.shade400,
                                          width: 0.5),
                                      color: isFri
                                          ? const Color(0xFFFFE4E6)
                                          : Colors.grey.shade100),
                                  child: Column(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Text(
                                          isFri ? "جمعہ" : "${date.day}",
                                          style: TextStyle(
                                              fontSize: isFri ? 8.5 : 11,
                                              fontWeight: FontWeight.bold,
                                              color: isFri
                                                  ? Colors.red.shade800
                                                  : Colors.black)),
                                      Text("${hInfo['d']}",
                                          style: TextStyle(
                                              fontSize: 8,
                                              color: isFri
                                                  ? Colors.red.shade700
                                                  : Colors.green)),
                                    ],
                                  ),
                                );
                              }),
                              _cellText("حاضر", 35,
                                  bgColor: Colors.grey.shade100, bold: true),
                              _cellText("غیر", 35,
                                  bgColor: Colors.grey.shade100, bold: true),
                              _cellText("رخصت", 35,
                                  bgColor: Colors.grey.shade100, bold: true),
                              _cellText("کیفیت", 60,
                                  bgColor: Colors.grey.shade100, bold: true),
                            ],
                          ),
                        ),
                        // Teacher rows
                        ..._buildTeacherReportRows(entry.key, reportDates),
                        // Student rows
                        ...filteredClassStudents.asMap().entries.map((studentEntry) {
                          int idx = studentEntry.key;
                          var s = studentEntry.value;
                          String sid = s['id'].toString();
                          int monthP = 0, monthA = 0, monthL = 0;
                          final periodKeys = _periodKeysForClass(entry.key);

                          final sStatus = (s['status'] ?? 'Active').toString().trim();
                          final gradDateStr = s['graduation_date']?.toString().trim() ?? '';
                          final gradYm = gradDateStr.length >= 7 ? gradDateStr.substring(0, 7) : '';

                          // کیا طالب علم اس رپورٹ کے مہینے میں ان ایکٹو تھا؟
                          final isInactiveForThisReport = (sStatus.toLowerCase() == 'alumni' ||
                              sStatus.toLowerCase() == 'graduated' ||
                              sStatus.toLowerCase() == 'left' ||
                              sStatus.contains('خارج') ||
                              gradDateStr.isNotEmpty) && (gradYm.isEmpty || reportYm.compareTo(gradYm) >= 0);

                          final leavingReason = (s['leaving_reason'] ?? '').toString().trim();
                          final alumniCat = (s['alumni_category'] ?? '').toString().trim();

                          String statusLabel = "تعلیم مکمل ہو گئی";
                          Color statusColor = const Color(0xFF198754);
                          Color statusBg = const Color(0xFFE8F5E9);

                          if (leavingReason.contains('خارج') || sStatus.contains('خارج') || alumniCat.contains('خارج')) {
                            statusLabel = "خارج کر دیا گیا";
                            statusColor = const Color(0xFFDC2626);
                            statusBg = const Color(0xFFFDE8E8);
                          } else if (leavingReason.contains('چھوڑ') || leavingReason.contains('شوقیہ') || sStatus.toLowerCase().contains('left')) {
                            statusLabel = "چھوڑ گیا";
                            statusColor = const Color(0xFFFD7E14);
                            statusBg = const Color(0xFFFFF3CD);
                          } else if (alumniCat.isNotEmpty) {
                            statusLabel = alumniCat;
                          } else if (leavingReason.isNotEmpty) {
                            statusLabel = leavingReason;
                          }

                          final admDateStr = _cleanDateStr(s['admission_date']);
                          List<Widget> dayCells = [];
                          for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
                            final date = reportDates[dIdx];
                            final isFri = date.weekday == DateTime.friday;
                            final dk = _dateKey(date);

                            if (isFri) {
                              dayCells.add(
                                Container(
                                  width: 25.0,
                                  height: 28.0,
                                  decoration: BoxDecoration(
                                    color: const Color(0xFFFFF1F2),
                                    border: Border(
                                      left: BorderSide(color: Colors.red.shade300, width: 0.8),
                                      right: BorderSide(color: Colors.red.shade300, width: 0.8),
                                      top: idx == 0 ? BorderSide(color: Colors.red.shade300, width: 0.8) : BorderSide.none,
                                      bottom: idx == filteredClassStudents.length - 1 ? BorderSide(color: Colors.red.shade300, width: 0.8) : BorderSide.none,
                                    ),
                                  ),
                                  alignment: Alignment.center,
                                  child: RotatedBox(
                                    quarterTurns: 3,
                                    child: Text(
                                      "جمعہ",
                                      style: TextStyle(
                                        fontSize: 8.5,
                                        fontWeight: FontWeight.bold,
                                        color: Colors.red.shade700,
                                      ),
                                    ),
                                  ),
                                ),
                              );
                              continue;
                            }

                            if (admDateStr != null && dk.compareTo(admDateStr) < 0) {
                              dayCells.add(
                                Container(
                                  width: 25.0,
                                  height: 28,
                                  decoration: BoxDecoration(
                                    border: Border.all(color: Colors.grey.shade300),
                                    color: Colors.grey.shade100,
                                  ),
                                  alignment: Alignment.center,
                                  child: Text("-", style: TextStyle(fontSize: 9, color: Colors.grey.shade600)),
                                ),
                              );
                              continue;
                            }

                            if (isInactiveForThisReport && gradDateStr.isNotEmpty && dk.compareTo(gradDateStr) >= 0) {
                              dayCells.add(
                                Container(
                                  width: 25.0,
                                  height: 28,
                                  decoration: BoxDecoration(
                                    color: statusBg,
                                    border: Border.all(color: statusColor.withOpacity(0.4)),
                                  ),
                                  alignment: Alignment.center,
                                  child: Text(
                                    "-",
                                    style: TextStyle(
                                      fontSize: 10,
                                      fontWeight: FontWeight.bold,
                                      color: statusColor,
                                    ),
                                  ),
                                ),
                              );
                              continue;
                            }

                            Map<String, String>? sm = attData[sid]?[dk];
                            if (sm != null) {
                              for (final key in periodKeys) {
                                final st = sm[key] ?? '';
                                if (st == 'P' || st == 'Late') {
                                  monthP++;
                                } else if (st == 'A') {
                                  monthA++;
                                } else if (st == 'L') {
                                  monthL++;
                                }
                              }
                              dayCells.add(_cellCustom(
                                  _buildPeriodDotGrid(sm, periodKeys), 25));
                            } else {
                              dayCells.add(_cellText("", 25));
                            }
                          }

                          return Row(children: [
                            _cellText((idx + 1).toString(), 30),
                            _cellText(
                                s['roll_number']?.toString() ??
                                    s['admission_number']?.toString() ??
                                    '',
                                60),
                            _cellText(s['name'] ?? '', 150, bold: true),
                            _cellText(s['father_name'] ?? '', 90),
                            ...dayCells,
                            _cellText((monthP > 0 || !isInactiveForThisReport) ? monthP.toString() : '-', 35),
                            _cellText((monthA > 0 || !isInactiveForThisReport) ? monthA.toString() : '-', 35),
                            _cellText((monthL > 0 || !isInactiveForThisReport) ? monthL.toString() : '-', 35),
                            Builder(builder: (context) {
                              int totalSlots = monthP + monthA + monthL;
                              if (isInactiveForThisReport && totalSlots == 0) {
                                return _cellText(statusLabel, 60,
                                    bold: true,
                                    textColor: statusColor,
                                    fontSize: 9);
                              }
                              String status = "-";
                              if (totalSlots > 0) {
                                double perc = (monthP / totalSlots) * 100;
                                if (perc >= 100) {
                                  status = "مکمل حاضر";
                                } else {
                                  status = "${perc.toStringAsFixed(0)}% حاضر";
                                }
                              }
                              return _cellText(status, 60,
                                  bold: true,
                                  textColor: status == "مکمل حاضر"
                                      ? Colors.green
                                      : Colors.blue);
                            }),
                          ]);

                        }),
                        // Period-wise Attendance Summary Panel below class table
                        _buildClassPeriodSummaryPanel(filteredClassStudents, entry.key, reportDates, attData),
                        const SizedBox(height: 15),
                      ];
                    }(),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildClassPeriodSummaryPanel(
    List<Map<String, dynamic>> students,
    String className,
    List<DateTime> reportDates,
    Map<String, Map<String, Map<String, String>>> attData,
  ) {
    if (reportDates.isEmpty) return const SizedBox.shrink();

    DateTime? latestWithAtt;
    for (final d in reportDates.reversed) {
      final dk = _dateKey(d);
      bool hasAny = false;
      for (final s in students) {
        final sid = s['id'].toString();
        final sm = attData[sid]?[dk];
        if (sm != null && sm.values.any((v) => v.isNotEmpty)) {
          hasAny = true;
          break;
        }
      }
      if (hasAny) {
        latestWithAtt = d;
        break;
      }
    }

    DateTime selectedDate = _selectedSummaryDateByClass[className] ?? (latestWithAtt ?? reportDates.last);
    if (!reportDates.contains(selectedDate)) {
      selectedDate = latestWithAtt ?? reportDates.last;
    }

    final periodKeys = _periodKeysForClass(className);
    final dk = _dateKey(selectedDate);

    final Map<String, Map<String, int>> periodStats = {};
    for (final pKey in periodKeys) {
      periodStats[pKey] = {'p': 0, 'late': 0, 'a': 0, 'l': 0};
    }

    for (final s in students) {
      final sid = s['id'].toString();
      final sm = attData[sid]?[dk];
      if (sm != null) {
        for (final pKey in periodKeys) {
          final st = sm[pKey] ?? '';
          if (st == 'P') {
            periodStats[pKey]!['p'] = (periodStats[pKey]!['p'] ?? 0) + 1;
          } else if (st == 'Late') {
            periodStats[pKey]!['late'] = (periodStats[pKey]!['late'] ?? 0) + 1;
          } else if (st == 'A') {
            periodStats[pKey]!['a'] = (periodStats[pKey]!['a'] ?? 0) + 1;
          } else if (st == 'L') {
            periodStats[pKey]!['l'] = (periodStats[pKey]!['l'] ?? 0) + 1;
          }
        }
      }
    }

    return Container(
      width: 1000,
      margin: const EdgeInsets.only(top: 12, bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: const Color(0xFFCBD5E1)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  border: Border.all(color: Colors.grey.shade400),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<DateTime>(
                    value: selectedDate,
                    isDense: true,
                    icon: const Icon(Icons.keyboard_arrow_down, size: 20, color: Colors.black87),
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.black87),
                    items: reportDates.reversed.map((d) {
                      final label = intl.DateFormat('MMM-yy-dd').format(d);
                      return DropdownMenuItem<DateTime>(
                        value: d,
                        child: Text(label, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (DateTime? newDate) {
                      if (newDate != null) {
                        setState(() {
                          _selectedSummaryDateByClass[className] = newDate;
                        });
                      }
                    },
                  ),
                ),
              ),
              Text(
                isUrduGlobal.value ? "پیریڈ وائز حاضری سمری" : "Period-wise Attendance Summary",
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 16,
                  color: Colors.black87,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          const Divider(height: 1, color: Color(0xFFE2E8F0)),
          const SizedBox(height: 10),
          Directionality(
            textDirection: TextDirection.rtl,
            child: Row(
              children: periodKeys.asMap().entries.map((entry) {
                final idx = entry.key;
                final pKey = entry.value;
                final pTitle = "سبق ${idx + 1}";
                final stats = periodStats[pKey] ?? {'p': 0, 'late': 0, 'a': 0, 'l': 0};

                return Expanded(
                  child: Container(
                    margin: const EdgeInsets.symmetric(horizontal: 3),
                    padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(color: const Color(0xFFCBD5E1)),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          pTitle,
                          style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 13,
                            color: Colors.black87,
                          ),
                        ),
                        const SizedBox(height: 4),
                        const Divider(height: 1, color: Color(0xFFE2E8F0)),
                        const SizedBox(height: 6),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: [
                            InkWell(
                              onTap: () {
                                final matching = students.where((s) {
                                  final sid = s['id'].toString();
                                  final sm = attData[sid]?[dk];
                                  return sm != null && sm[pKey] == 'P';
                                }).toList();
                                _showPeriodStudentsDialog(
                                  className: className,
                                  periodTitle: pTitle,
                                  categoryLabel: "حاضر طلبہ",
                                  themeColor: const Color(0xFF0D9488),
                                  selectedDate: selectedDate,
                                  matchingStudents: matching,
                                );
                              },
                              child: Text(
                                "ح: ${stats['p']}",
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                  color: Color(0xFF0D9488),
                                  decoration: TextDecoration.underline,
                                ),
                              ),
                            ),
                            InkWell(
                              onTap: () {
                                final matching = students.where((s) {
                                  final sid = s['id'].toString();
                                  final sm = attData[sid]?[dk];
                                  return sm != null && sm[pKey] == 'Late';
                                }).toList();
                                _showPeriodStudentsDialog(
                                  className: className,
                                  periodTitle: pTitle,
                                  categoryLabel: "تاخیر والے طلبہ",
                                  themeColor: const Color(0xFFEA580C),
                                  selectedDate: selectedDate,
                                  matchingStudents: matching,
                                );
                              },
                              child: Text(
                                "ت: ${stats['late']}",
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                  color: Color(0xFFEA580C),
                                  decoration: TextDecoration.underline,
                                ),
                              ),
                            ),
                            InkWell(
                              onTap: () {
                                final matching = students.where((s) {
                                  final sid = s['id'].toString();
                                  final sm = attData[sid]?[dk];
                                  return sm != null && sm[pKey] == 'A';
                                }).toList();
                                _showPeriodStudentsDialog(
                                  className: className,
                                  periodTitle: pTitle,
                                  categoryLabel: "غیر حاضر طلبہ",
                                  themeColor: const Color(0xFFDC2626),
                                  selectedDate: selectedDate,
                                  matchingStudents: matching,
                                );
                              },
                              child: Text(
                                "غ: ${stats['a']}",
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                  color: Color(0xFFDC2626),
                                  decoration: TextDecoration.underline,
                                ),
                              ),
                            ),
                            InkWell(
                              onTap: () {
                                final matching = students.where((s) {
                                  final sid = s['id'].toString();
                                  final sm = attData[sid]?[dk];
                                  return sm != null && sm[pKey] == 'L';
                                }).toList();
                                _showPeriodStudentsDialog(
                                  className: className,
                                  periodTitle: pTitle,
                                  categoryLabel: "رخصت والے طلبہ",
                                  themeColor: const Color(0xFFCA8A04),
                                  selectedDate: selectedDate,
                                  matchingStudents: matching,
                                );
                              },
                              child: Text(
                                "ر: ${stats['l']}",
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                  color: Color(0xFFCA8A04),
                                  decoration: TextDecoration.underline,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }

  void _showPeriodStudentsDialog({
    required String className,
    required String periodTitle,
    required String categoryLabel,
    required Color themeColor,
    required DateTime selectedDate,
    required List<Map<String, dynamic>> matchingStudents,
  }) {
    showDialog(
      context: context,
      builder: (ctx) {
        final dateStr = intl.DateFormat('dd-MMM-yyyy').format(selectedDate);
        return Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            titlePadding: EdgeInsets.zero,
            title: Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                color: themeColor,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.people, color: Colors.white, size: 22),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      "فہرست $categoryLabel ($periodTitle)",
                      style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
            ),
            content: SizedBox(
              width: 500,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: Colors.grey.shade300),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text("کلاس: $className", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                        Text("تاریخ: $dateStr", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.blueGrey)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (matchingStudents.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 20),
                      child: Center(
                        child: Text("اس کیٹیگری میں کوئی طالب علم نہیں ہے", style: TextStyle(fontWeight: FontWeight.bold, color: Colors.grey)),
                      ),
                    )
                  else
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxHeight: 350),
                      child: SingleChildScrollView(
                        child: Table(
                          border: TableBorder.all(color: Colors.grey.shade300),
                          columnWidths: const {
                            0: FixedColumnWidth(35),
                            1: FixedColumnWidth(70),
                            2: FlexColumnWidth(2),
                            3: FlexColumnWidth(2),
                            4: FixedColumnWidth(80),
                          },
                          children: [
                            TableRow(
                              decoration: BoxDecoration(color: Colors.grey.shade200),
                              children: const [
                                Padding(padding: EdgeInsets.all(6), child: Text("#", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                                Padding(padding: EdgeInsets.all(6), child: Text("رول نمبر", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                                Padding(padding: EdgeInsets.all(6), child: Text("نام طالب علم", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                                Padding(padding: EdgeInsets.all(6), child: Text("ولدیت", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                                Padding(padding: EdgeInsets.all(6), child: Text("کیفیت", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                              ],
                            ),
                            ...matchingStudents.asMap().entries.map((e) {
                              final idx = e.key;
                              final st = e.value;
                              return TableRow(
                                children: [
                                  Padding(padding: const EdgeInsets.all(6), child: Text("${idx + 1}", textAlign: TextAlign.center, style: const TextStyle(fontSize: 11))),
                                  Padding(padding: const EdgeInsets.all(6), child: Text("${st['roll_number'] ?? st['admission_number'] ?? '-'}", textAlign: TextAlign.center, style: const TextStyle(fontSize: 11))),
                                  Padding(padding: const EdgeInsets.all(6), child: Text("${st['name'] ?? '-'}", textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
                                  Padding(padding: const EdgeInsets.all(6), child: Text("${st['father_name'] ?? '-'}", textAlign: TextAlign.right, style: const TextStyle(fontSize: 11, color: Colors.black54))),
                                  Padding(
                                    padding: const EdgeInsets.all(6),
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                                      decoration: BoxDecoration(color: themeColor.withOpacity(0.15), borderRadius: BorderRadius.circular(4)),
                                      child: Text(categoryLabel, textAlign: TextAlign.center, style: TextStyle(color: themeColor, fontWeight: FontWeight.bold, fontSize: 10)),
                                    ),
                                  ),
                                ],
                              );
                            }),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text("بند کریں", style: TextStyle(fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        );
      },
    );
  }

}
