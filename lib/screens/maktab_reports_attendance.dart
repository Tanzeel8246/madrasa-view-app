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
import 'maktab_student_report.dart';

class ReportsMaktabAttendancePage extends StatefulWidget {
  const ReportsMaktabAttendancePage({super.key});

  @override
  _ReportsMaktabAttendancePageState createState() => _ReportsMaktabAttendancePageState();
}

class _ReportsMaktabAttendancePageState extends State<ReportsMaktabAttendancePage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final ScreenshotController screenshotController = ScreenshotController();

  List<Map<String, dynamic>> _allStudents = [];
  List<Map<String, dynamic>> _filteredStudents = [];
  List<Map<String, dynamic>> _classes = [];

  bool isLoading = true;
  String? displayMonth;
  String? displayYear;
  String? selectedClassName = "All";

  int hijriOffset = 0;

  // Student -> Date -> Map of Shifts ('1','2','3','4') -> Status ('P','A','L')
  Map<String, Map<String, Map<String, String>>> attData = {};
  // Class -> Teacher ID -> Date -> Period -> Status
  Map<String, Map<String, Map<String, Map<String, String>>>> teacherAttData =
      {};
  Map<String, String> teacherNameById = {};
  Map<String, Map<String, dynamic>> teacherInfoById = {};
  Map<String, Set<String>> assignedTeacherIdsByClass = {};

  int grandTotalSlots = 0;
  int grandTotalP = 0;
  int grandTotalLate = 0;
  int grandTotalA = 0;
  int grandTotalL = 0;
  String? selectedStudentId;
  Map<String, String> selectedSummaryDateByClass = {};

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    displayMonth = now.month.toString().padLeft(2, '0');
    displayYear = now.year.toString();

    _loadData();
  }

  DateTime get _reportStartDate {
    return DateTime(int.parse(displayYear!), int.parse(displayMonth!), 1);
  }

  DateTime get _reportEndDate {
    final y = int.parse(displayYear!);
    final m = int.parse(displayMonth!);
    return DateTime(y, m + 1, 0);
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

  String get _reportTitle {
    final m = int.parse(displayMonth!);
    return "${getGregorianMonthUrdu(m)} $displayYear";
  }

  Future<void> _loadData() async {
    setState(() => isLoading = true);
    final db = DBHelper();

    // Load Classes
    _classes = await db.getMaktabClassesForUser();

    final settings = await db.getSettings();
    hijriOffset =
        int.tryParse(settings['hijri_offset']?.toString() ?? '0') ?? 0;

    // Load Students
    final allStudents = await db.getMaktabStudentsForUser(includeInactive: true);
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

    final allAttendance = await db.getMaktabAttendance();
    final allTeachers = await db.getMaktabTeachers();
    teacherNameById = {
      for (final t in allTeachers)
        t['id'].toString(): t['name']?.toString() ?? ''
    };
    teacherInfoById = {
      for (final t in allTeachers) t['id'].toString(): t
    };
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
    final reportYm = _reportDates.isNotEmpty ? _dateKey(_reportDates.first).substring(0, 7) : '';
    _filteredStudents = _allStudents.where((s) {
      if (selectedClassName != null && selectedClassName != "All") {
        if ((s['class_name'] ?? '').toString().trim() != selectedClassName) return false;
      }
      final admDate = s['admission_date']?.toString().trim();
      if (admDate != null && admDate.isNotEmpty && admDate != 'null') {
        final cleanAdm = admDate.length >= 7 ? admDate.substring(0, 7) : admDate;
        if (reportYm.isNotEmpty && cleanAdm.compareTo(reportYm) > 0) {
          return false;
        }
      }
      return true;
    }).toList();
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

  String? _cleanDateStr(dynamic val) {
    if (val == null) return null;
    final s = val.toString().trim();
    if (s.isEmpty || s == 'null') return null;
    return s.length >= 10 ? s.substring(0, 10) : s;
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

  String _periodName(String className, String pKey) {
    return teacherPeriodLabel(pKey, isUrduGlobal.value);
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
    if (status == null) return '';
    String s = status.trim().toLowerCase();
    if (s == 'a' ||
        s.contains('absent') ||
        s.contains('غیر حاضر') ||
        s.contains('غیرحاضر') ||
        s.contains('غائب')) {
      return 'A';
    }
    if (s == 'l' ||
        s.contains('leave') ||
        s.contains('رخصت') ||
        s.contains('چھٹی')) {
      return 'L';
    }
    if (s == 'p' ||
        s.contains('present') ||
        s.contains('late') ||
        s.contains('حاضر')) {
      return 'P';
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
      '',
      'nazira',
      'ناظر',
    ])) {
      return const ['1', '2'];
    }
    if (_classTextContains(className, const [
      '',
      '',
      ' ',
      'hifz',
      'gardan',
      'husn',
      'حظ',
      'گردان',
      'حسن',
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
      " ",
      " ",
      " ",
      " ",
      " ",
      " ",
      " ",
      " ",
      " ",
      " ",
      "",
      " "
    ];
    if (m >= 1 && m <= 12) return months[m];
    return "";
  }

  String getGregorianMonthUrdu(int m) {
    const list = [
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      "",
      ""
    ];
    if (m >= 1 && m <= 12) return list[m];
    return "";
  }

  Future<void> _shareReport() async {
    try {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(isUrduGlobal.value
              ? "    ..."
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
    final teacherIds = <String>{
      ...(assignedTeacherIdsByClass[className] ?? <String>{}),
      ...(classRows?.keys ?? <String>{}),
    };
    final reportYm = reportDates.isNotEmpty ? _dateKey(reportDates.first).substring(0, 7) : '';
    final validTeacherIds = teacherIds.where((teacherId) {
      final tInfo = teacherInfoById[teacherId];
      if (tInfo != null) {
        final joinDate = _cleanDateStr(tInfo['joining_date']);
        final joinYm = joinDate != null && joinDate.length >= 7 ? joinDate.substring(0, 7) : null;
        if (joinYm != null && reportYm.isNotEmpty && reportYm.compareTo(joinYm) < 0) {
          return false;
        }
      }
      return true;
    }).toList();
    if (validTeacherIds.isEmpty) return const [];

    return validTeacherIds.map((teacherId) {
      final teacherName = teacherNameById[teacherId]?.trim().isNotEmpty == true
          ? teacherNameById[teacherId]!
          : ' $teacherId';
      int present = 0, absent = 0, leave = 0;
      final periodKeys = _periodKeysForClass(className);
      final dayCells = <pw.Widget>[];

      for (final date in reportDates) {
        final isFri = date.weekday == DateTime.friday;
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

        final dayKey = _dateKey(date);
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

      final teacherStyle = pw.TextStyle(
          font: ttf,
          fontSize: 8,
          color: PdfColor.fromHex('#284D3D'),
          fontWeight: pw.FontWeight.bold);
      return pw.Row(children: [
        _attCellR('', 18,
            ttf: ttf, bgColor: PdfColors.green50, bold: true),
        _attCellR(teacherName, 88,
            ttf: ttf,
            bgColor: PdfColors.green50,
            bold: true,
            color: PdfColor.fromHex('#284D3D')),
        _attCellR('', 68,
            ttf: ttf,
            bgColor: PdfColors.green50,
            color: PdfColor.fromHex('#284D3D')),
        ...dayCells,
        _attCell(present.toString(), 26,
            style: teacherStyle, bgColor: PdfColors.green50),
        _attCell(absent.toString(), 26,
            style: teacherStyle.copyWith(color: PdfColors.red),
            bgColor: PdfColors.green50),
        _attCell(leave.toString(), 26,
            style: teacherStyle.copyWith(color: PdfColors.amber),
            bgColor: PdfColors.green50),
      ]);
    }).toList();
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
              List<Map<String, dynamic>> students = entry.value;

              // Class Header
              allRows.add(pw.Container(
                width: double.infinity,
                padding:
                    const pw.EdgeInsets.symmetric(vertical: 4, horizontal: 8),
                color: PdfColors.grey300,
                child: pw.Text(": $className",
                    style: pw.TextStyle(
                        font: ttf,
                        fontWeight: pw.FontWeight.bold,
                        fontSize: 10)),
              ));

              // Table Header
              List<pw.Widget> hdr = [
                _attCell("#", 18, style: boldStyle.copyWith(fontSize: 7)),
                _attCellR(
                    "\u0646\u0627\u0645 \u0637\u0627\u0644\u0628 \u0639\u0644\u0645",
                    88,
                    ttf: ttf,
                    bold: true),
                _attCellR("\u0648\u0644\u062f\u06cc\u062a", 68,
                    ttf: ttf, bold: true),
              ];
              for (final date in reportDates) {
                final isFri = date.weekday == DateTime.friday;
                Map<String, int> hd = getHijriDateLoop(date, hijriOffset);
                hdr.add(pw.Container(
                  width: 17,
                  height: 30,
                  decoration: pw.BoxDecoration(
                      color: isFri ? PdfColors.red50 : PdfColor.fromHex('#284D3D'),
                      border:
                          pw.Border.all(color: isFri ? PdfColors.red200 : PdfColors.white, width: 0.2)),
                  alignment: pw.Alignment.center,
                  child: pw.Column(
                      mainAxisAlignment: pw.MainAxisAlignment.center,
                      children: [
                        pw.Text(isFri ? "جمعہ" : date.day.toString(),
                            style: pw.TextStyle(
                                font: ttf,
                                fontSize: isFri ? 6 : 6.5,
                                color: isFri ? PdfColors.red800 : PdfColors.white,
                                fontWeight: pw.FontWeight.bold)),
                        pw.Text(hd['d'].toString(),
                            style: pw.TextStyle(
                                font: ttf,
                                fontSize: 5.5,
                                color: isFri ? PdfColors.red600 : PdfColors.greenAccent200)),
                      ]),
                ));
              }
              hdr.addAll([
                _attCell("\u062d\u0627\u0636\u0631", 26,
                    style: pw.TextStyle(
                        font: ttf,
                        fontSize: 7,
                        color: PdfColors.green800,
                        fontWeight: pw.FontWeight.bold)),
                _attCell("\u063a.\u062d", 26,
                    style: pw.TextStyle(
                        font: ttf,
                        fontSize: 7,
                        color: PdfColors.red,
                        fontWeight: pw.FontWeight.bold)),
                _attCell("\u0631\u062e\u0635\u062a", 26,
                    style: pw.TextStyle(
                        font: ttf,
                        fontSize: 7,
                        color: PdfColors.amber,
                        fontWeight: pw.FontWeight.bold)),
              ]);
              allRows.add(pw.Row(children: hdr));

              // Teacher rows
              allRows.addAll(_buildTeacherPdfRows(className, reportDates, ttf));

              // Student rows
              final periodKeys = _periodKeysForClass(className);
              int sr = 1;
              for (var s in students) {
                String sid = s['id'].toString();
                int mp = 0, ma = 0, ml = 0;
                List<pw.Widget> dcs = [];
                for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
                  final date = reportDates[dIdx];
                  final isFri = date.weekday == DateTime.friday;
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
                  String dayKey = _dateKey(date);
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
                  _attCell(mp.toString(), 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.green800,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                  _attCell(ma.toString(), 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.red,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                  _attCell(ml.toString(), 26,
                      style: pw.TextStyle(
                          font: ttf,
                          fontSize: 8,
                          color: PdfColors.amber,
                          fontWeight: pw.FontWeight.bold),
                      bgColor: rowBg),
                ]));
                sr++;
              }
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
          PdfColor? color}) =>
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
              style: pw.TextStyle(
                  font: ttf,
                  fontSize: 8,
                  fontWeight: bold ? pw.FontWeight.bold : pw.FontWeight.normal,
                  color: color ?? PdfColors.black)));

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
        Navigator.pushReplacement(
            context, MaterialPageRoute(builder: (c) => const DashboardPage()));
      },
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          title: Text(isUrduGlobal.value ? " " : "Attendance Report"),
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
                  Row(
                    children: [
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            isExpanded: true,
                            value: displayMonth,
                            decoration: const InputDecoration(
                                labelText: " / Month",
                                border: OutlineInputBorder(),
                                contentPadding:
                                    EdgeInsets.symmetric(horizontal: 10)),
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
                            decoration: const InputDecoration(
                                labelText: " / Year",
                                border: OutlineInputBorder(),
                                contentPadding:
                                    EdgeInsets.symmetric(horizontal: 10)),
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
                              labelText: "  ",
                              border: OutlineInputBorder(),
                              contentPadding:
                                  EdgeInsets.symmetric(horizontal: 10)),
                          items: [
                            const DropdownMenuItem(
                                value: "All", child: Text(" ")),
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
                              labelText: "  ()",
                              border: OutlineInputBorder(),
                              contentPadding:
                                  EdgeInsets.symmetric(horizontal: 10)),
                          items: [
                            const DropdownMenuItem(
                                value: null, child: Text(" ")),
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
                                builder: (c) => MaktabStudentReportPage(
                                    forceStudentId: selectedStudentId))),
                        icon: const Icon(Icons.person),
                        label: const Text("  "),
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
                                              ? "   "
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
    final teacherIds = <String>{
      ...(assignedTeacherIdsByClass[className] ?? <String>{}),
      ...(classRows?.keys ?? <String>{}),
    };
    final reportYm = reportDates.isNotEmpty ? _dateKey(reportDates.first).substring(0, 7) : '';
    final validTeacherIds = teacherIds.where((teacherId) {
      final tInfo = teacherInfoById[teacherId];
      if (tInfo != null) {
        final joinDate = _cleanDateStr(tInfo['joining_date']);
        final joinYm = joinDate != null && joinDate.length >= 7 ? joinDate.substring(0, 7) : null;
        if (joinYm != null && reportYm.isNotEmpty && reportYm.compareTo(joinYm) < 0) {
          return false;
        }
      }
      return true;
    }).toList();
    if (validTeacherIds.isEmpty) return const [];

    return validTeacherIds.map((teacherId) {
      final teacherName = teacherNameById[teacherId]?.trim().isNotEmpty == true
          ? teacherNameById[teacherId]!
          : ' $teacherId';
      int monthP = 0, monthA = 0, monthL = 0;
      final periodKeys = _periodKeysForClass(className);
      final dayCells = <Widget>[];

      for (final date in reportDates) {
        final isFri = date.weekday == DateTime.friday;
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

        final dayKey = _dateKey(date);
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

      final totalSlots = monthP + monthA + monthL;
      final status = totalSlots == 0
          ? '-'
          : '${((monthP / totalSlots) * 100).toStringAsFixed(0)}% ';
      return Row(children: [
        _cellText('', 30, bold: true, textColor: primaryGreen),
        _cellText('', 60),
        _cellText(teacherName, 150, bold: true, textColor: primaryGreen),
        _cellText('', 90, textColor: primaryGreen),
        ...dayCells,
        _cellText(monthP.toString(), 35, textColor: Colors.green),
        _cellText(monthA.toString(), 35, textColor: Colors.red),
        _cellText(monthL.toString(), 35, textColor: Colors.amber.shade800),
        _cellText(status, 60, bold: true, textColor: primaryGreen),
      ]);
    }).toList();
  }


  Widget _buildClassSummary(String className, List<DateTime> reportDates, List<Map<String, dynamic>> students) {
    if (reportDates.isEmpty) return const SizedBox();
    
    final datesReverse = reportDates.reversed.toList();
    final todayStr = _dateKey(DateTime.now());
    final validDateKeys = datesReverse.map((d) => _dateKey(d)).toSet();

    return StatefulBuilder(
      builder: (context, setState) {
        String? storedDate = selectedSummaryDateByClass[className];
        String selectedDateStr = (storedDate != null && validDateKeys.contains(storedDate))
            ? storedDate
            : (reportDates.any((d) => _dateKey(d) == todayStr) ? todayStr : _dateKey(datesReverse.first));
        List<String> periodKeys = [];
        for (var s in students) {
          final sid = s['id'].toString();
          final periods = attData[sid]?[selectedDateStr];
          if (periods != null) {
            for (var k in periods.keys) {
              if (!periodKeys.contains(k)) periodKeys.add(k);
            }
          }
        }
        periodKeys.sort();

        Map<String, Map<String, int>> periodTotals = {
          for (var p in periodKeys) p: {'P': 0, 'Late': 0, 'A': 0, 'L': 0}
        };

        int dayTotalP = 0;
        int dayTotalLate = 0;
//           int dayTotalA = 0;
//           int dayTotalL = 0;

        for (var s in students) {
          final sid = s['id'].toString();
          final periods = attData[sid]?[selectedDateStr];
          if (periods != null) {
            for (var p in periodKeys) {
              final st = periods[p] ?? '';
              if (st == 'P') {
                periodTotals[p]!['P'] = (periodTotals[p]!['P'] ?? 0) + 1;
                dayTotalP++;
              } else if (st == 'Late') {
                periodTotals[p]!['Late'] = (periodTotals[p]!['Late'] ?? 0) + 1;
                dayTotalLate++;
              } else if (st == 'A') {
                periodTotals[p]!['A'] = (periodTotals[p]!['A'] ?? 0) + 1;
//                 dayTotalA++;
              } else if (st == 'L') {
                periodTotals[p]!['L'] = (periodTotals[p]!['L'] ?? 0) + 1;
//                 dayTotalL++;
              }
            }
          }
        }

        return Card(
          elevation: 2,
          margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.summarize, size: 20, color: Color(0xFF284D3D)),
                      const SizedBox(width: 8),
                      Text("   ($className)", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF284D3D))),
                    ],
                  ),
                  GestureDetector(
                    onTap: () async {
                      DateTime initDate = DateTime.tryParse(selectedDateStr) ?? DateTime.now();
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: initDate,
                        firstDate: DateTime(2020),
                        lastDate: DateTime.now(),
                        builder: (context, child) {
                          return Theme(
                            data: Theme.of(context).copyWith(
                              colorScheme: const ColorScheme.light(
                                primary: Color(0xFF284D3D), // Header background color
                                onPrimary: Colors.white, // Header text color
                                onSurface: Colors.black, // Body text color
                              ),
                            ),
                            child: child!,
                          );
                        },
                      );
                      if (picked != null) {
                        setState(() {
                          selectedSummaryDateByClass[className] = _dateKey(picked);
                        });
                      }
                    },
                    child: Container(
                      height: 35,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        border: Border.all(color: Colors.grey.shade400),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.calendar_today, size: 16, color: Color(0xFF284D3D)),
                          const SizedBox(width: 8),
                          Text(selectedDateStr, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              const SizedBox(height: 12),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  // Overall Day Summary Chip
                  Builder(
                    builder: (context) {
                      int totalPossible = students.length * periodKeys.length;
                      int totalPresent = dayTotalP + dayTotalLate;
                      double percentage = totalPossible > 0 ? (totalPresent / totalPossible) * 100 : 0;
                      
                      return Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: Colors.grey.shade200),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Text("  :  ", style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                            Text(": ${percentage.toStringAsFixed(1)}% ", style: const TextStyle(fontSize: 13, color: Colors.green, fontWeight: FontWeight.bold)),
                            Text("( : ${students.length})", style: const TextStyle(fontSize: 13, color: Colors.blue, fontWeight: FontWeight.bold)),
                          ],
                        ),
                      );
                    }
                  ),
                  ...periodKeys.map((p) {
                    final totals = periodTotals[p]!;
                    String pName = _periodName(className, p);
                    return Container(
                      width: 140,
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        border: Border.all(color: Colors.grey.shade300),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Column(
                        children: [
                          Text(pName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.blue)),
                          const Divider(height: 10),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [
                              Text(": ${totals['P']}", style: const TextStyle(fontSize: 11, color: Colors.green, fontWeight: FontWeight.bold)),
                              Text(": ${totals['Late']}", style: const TextStyle(fontSize: 11, color: Colors.orange, fontWeight: FontWeight.bold)),
                              Text(": ${totals['A']}", style: const TextStyle(fontSize: 11, color: Colors.red, fontWeight: FontWeight.bold)),
                              Text(": ${totals['L']}", style: const TextStyle(fontSize: 11, color: Colors.amber, fontWeight: FontWeight.bold)),
                            ],
                          ),
                        ],
                      ),
                    );
                  }),
                ],
              ),
            ],
          ),
        );
      }
    );
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
            Text("     ",
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
            Text("   $titleGreg",
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
                    for (var entry in grouped.entries) ...[
                      // Class Header
                      Container(
                        width: 1000,
                        padding: const EdgeInsets.symmetric(
                            vertical: 4, horizontal: 8),
                        color: Colors.grey.shade200,
                        child: Text(": ${entry.key}",
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13)),
                      ),
                      // Table Header
                      IntrinsicHeight(
                        child: Row(
                          children: [
                            _cellText("#", 30,
                                bgColor: Colors.grey.shade200, bold: true),
                            _cellText(" ", 60,
                                bgColor: Colors.grey.shade200, bold: true),
                            _cellText("  ", 150,
                                bgColor: Colors.grey.shade200, bold: true),
                            _cellText("", 90,
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
                                    Text(isFri ? "جمعہ" : "${date.day}",
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
                            _cellText("", 35,
                                bgColor: Colors.grey.shade100, bold: true),
                            _cellText("", 35,
                                bgColor: Colors.grey.shade100, bold: true),
                            _cellText("", 35,
                                bgColor: Colors.grey.shade100, bold: true),
                            _cellText("", 60,
                                bgColor: Colors.grey.shade100, bold: true),
                          ],
                        ),
                      ),
                      // Teacher rows
                      ..._buildTeacherReportRows(entry.key, reportDates),
                      // Student rows
                      ...entry.value.asMap().entries.map((studentEntry) {
                        int idx = studentEntry.key;
                        var s = studentEntry.value;
                        String sid = s['id'].toString();
                        int monthP = 0, monthA = 0, monthL = 0;
                        final periodKeys = _periodKeysForClass(entry.key);
                        List<Widget> dayCells = [];
                        for (int dIdx = 0; dIdx < reportDates.length; dIdx++) {
                          final date = reportDates[dIdx];
                          final isFri = date.weekday == DateTime.friday;
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
                                    bottom: idx == entry.value.length - 1 ? BorderSide(color: Colors.red.shade300, width: 0.8) : BorderSide.none,
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
                          String dk = _dateKey(date);
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
                          _cellText(monthP.toString(), 35),
                          _cellText(monthA.toString(), 35),
                          _cellText(monthL.toString(), 35),
                          Builder(builder: (context) {
                            int totalSlots = monthP + monthA + monthL;
                            String status = "-";
                            if (totalSlots > 0) {
                              double perc = (monthP / totalSlots) * 100;
                              if (perc >= 100) {
                                status = " ";
                              } else {
                                status = "${perc.toStringAsFixed(0)}% ";
                              }
                            }
                            return _cellText(status, 60,
                                bold: true,
                                textColor: status == " "
                                    ? Colors.green
                                    : Colors.blue);
                          }),
                        ]);
                      }),
                      const SizedBox(height: 15),
                      _buildClassSummary(entry.key, reportDates, entry.value),
                    ]
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

