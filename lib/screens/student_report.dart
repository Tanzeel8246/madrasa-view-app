// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use, prefer_interpolation_to_compose_strings, curly_braces_in_flow_control_structures
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../utils/performance_helper.dart';
import '../utils/hifz_calculator.dart';
import 'package:intl/intl.dart' as intl;
import 'dart:ui' as ui;
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:url_launcher/url_launcher.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/ai_service.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';

class StudentReportPage extends StatefulWidget {
  final String? forceStudentId;
  final bool isEmbedded;
  final String? targetClassType;
  final String? forceClassName;
  const StudentReportPage({
    super.key,
    this.forceStudentId,
    this.isEmbedded = false,
    this.targetClassType,
    this.forceClassName,
  });

  @override
  State<StudentReportPage> createState() => _StudentReportPageState();
}

class _StudentReportPageState extends State<StudentReportPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _hifzRecords = [];
  List<Map<String, dynamic>> _qaidaRecords = [];
  List<Map<String, dynamic>> _husnSautRecords = [];
  List<Map<String, dynamic>> _attendanceRecords = [];
  List<Map<String, dynamic>> _complaints = [];
  List<Map<String, dynamic>> _examResults = [];
  List<Map<String, dynamic>> _academicReports = [];
  Map<String, dynamic> _stats = {};
  Map<String, dynamic>? _student;

  DateTime? _filterStartDate = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime? _filterEndDate = DateTime(DateTime.now().year, DateTime.now().month + 1, 0, 23, 59, 59);
  String _selectedPeriodFilter = 'current_month';

  List<Map<String, dynamic>> get _filteredHifzRecords {
    if (_filterStartDate == null || _filterEndDate == null) return _hifzRecords;
    return _hifzRecords.where((r) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year < 2000) return true;
      return !dt.isBefore(_filterStartDate!) && !dt.isAfter(_filterEndDate!);
    }).toList();
  }

  List<Map<String, dynamic>> get _filteredQaidaRecords {
    if (_filterStartDate == null || _filterEndDate == null) return _qaidaRecords;
    return _qaidaRecords.where((r) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year < 2000) return true;
      return !dt.isBefore(_filterStartDate!) && !dt.isAfter(_filterEndDate!);
    }).toList();
  }

  List<Map<String, dynamic>> get _filteredHusnSautRecords {
    if (_filterStartDate == null || _filterEndDate == null) return _husnSautRecords;
    return _husnSautRecords.where((r) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year < 2000) return true;
      return !dt.isBefore(_filterStartDate!) && !dt.isAfter(_filterEndDate!);
    }).toList();
  }

  List<Map<String, dynamic>> get _filteredAttendanceRecords {
    if (_filterStartDate == null || _filterEndDate == null) return _attendanceRecords;
    return _attendanceRecords.where((r) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year < 2000) return true;
      return !dt.isBefore(_filterStartDate!) && !dt.isAfter(_filterEndDate!);
    }).toList();
  }

  List<Map<String, dynamic>> get _filteredAcademicReports {
    if (_filterStartDate == null || _filterEndDate == null) return _academicReports;
    return _academicReports.where((r) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year < 2000) return true;
      return !dt.isBefore(_filterStartDate!) && !dt.isAfter(_filterEndDate!);
    }).toList();
  }

  bool get _isHusnSautStudent {
    if (widget.targetClassType != null) {
      final t = widget.targetClassType!.toLowerCase();
      if (t.contains('husn') || t.contains('صوت')) return true;
      if (t == 'hifz' || t == 'qaida' || t == 'nazira' || t == 'academic') return false;
    }
    final cls = (_student?['class_name'] ?? _student?['class'] ?? widget.forceClassName ?? '').toString().toLowerCase();
    return cls.contains('حسن') || cls.contains('صوت') || _husnSautRecords.any((r) => r['is_dummy'] != 1);
  }

  bool get _isHifzStudent {
    if (_isHusnSautStudent) return false;
    if (widget.targetClassType != null) {
      final t = widget.targetClassType!.toLowerCase();
      if (t.contains('hifz') || t.contains('حفظ')) return true;
      if (t.contains('husn') || t == 'qaida' || t == 'nazira' || t == 'academic') return false;
    }
    final cls = (_student?['class_name'] ?? _student?['class'] ?? widget.forceClassName ?? '').toString();
    return cls.contains('حفظ') || _hifzRecords.any((r) => r['is_dummy'] != 1);
  }

  bool get _isQaidaStudent {
    if (_isHusnSautStudent || _isHifzStudent) return false;
    if (widget.targetClassType != null) {
      final t = widget.targetClassType!.toLowerCase();
      if (t.contains('qaida') || t.contains('nazira') || t.contains('قاعدہ') || t.contains('ناظرہ')) return true;
      if (t.contains('husn') || t == 'hifz' || t == 'academic') return false;
    }
    final cls = (_student?['class_name'] ?? _student?['class'] ?? widget.forceClassName ?? '').toString();
    return cls.contains('قاعدہ') || cls.contains('ناظرہ') || _qaidaRecords.any((r) => r['is_dummy'] != 1);
  }

  bool _isLoading = true;
  String _aiAdvice = '';
  bool _isAiLoading = false;
  final ScreenshotController _screenshotController = ScreenshotController();

  @override
  void initState() {
    super.initState();
    _loadReportData();
  }

  void _loadReportData() async {
    final db = DBHelper();
    final targetIdStr =
        widget.forceStudentId ?? currentUserId.value?.toString() ?? '0';
    final int targetId = int.tryParse(targetIdStr) ?? 0;

    final allHifz = await db.getHifzRecords();
    final allQaida = await db.getQaidaRecords();
    List<Map<String, dynamic>> allHusn = [];
    try {
      allHusn = await db.getHusnSautRecords();
    } catch (_) {}
    final database = await db.database;
    final allAtt = await database.query('attendance');
    List<Map<String, dynamic>> extraAtt = [];
    try {
      extraAtt = await database.query('maktab_attendance');
    } catch (_) {}
    final mergedAtt = [...allAtt, ...extraAtt];

    final allComplaints = await db.getComplaints();
    final studentExams = await db.getStudentResults(targetId);
    final allAcademic = await db.getAcademicReports();
    List<Map<String, dynamic>> extraAcademic = [];
    try {
      extraAcademic = await database.query('maktab_academic_reports');
    } catch (_) {}
    final mergedAcademic = [...allAcademic, ...extraAcademic];

    final allStudents = await db.getStudentsForUser();
    List<Map<String, dynamic>> extraMaktabStudents = [];
    try {
      extraMaktabStudents = await db.getMaktabStudentsForUser();
    } catch (_) {}
    final combinedStudents = [...allStudents, ...extraMaktabStudents];
    final settings = await db.getSettings();

    final matched =
        combinedStudents.where((s) => s['id'].toString() == targetIdStr).toList();
    final studentObj = matched.isNotEmpty ? matched.first : null;
    final singleStudentList = studentObj != null ? [studentObj] : <Map<String, dynamic>>[];

    var studentAtt = mergedAtt
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    var studentHifz = allHifz
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    var studentQaida = allQaida
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    var studentHusn = allHusn
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    var studentAcademic = mergedAcademic
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    final cls = (studentObj?['class_name'] ?? studentObj?['class'] ?? widget.forceClassName ?? '').toString().toLowerCase();
    final isTargetHusn = widget.targetClassType != null &&
        (widget.targetClassType!.toLowerCase().contains('husn') || widget.targetClassType!.toLowerCase().contains('صوت'));
    final isHusn = isTargetHusn || cls.contains('حسن') || cls.contains('صوت') || studentHusn.isNotEmpty;
    final isHifz = !isHusn && (cls.contains('حفظ') || studentHifz.isNotEmpty);
    final isQaida = !isHusn && !isHifz && (cls.contains('قاعدہ') || cls.contains('ناظرہ') || studentQaida.isNotEmpty);

    if (isHifz) {
      studentHifz = PerformanceCalculator.injectMissingAttendanceRecords(
          studentHifz, studentAtt, singleStudentList, 'hifz');
    }
    if (isQaida) {
      studentQaida = PerformanceCalculator.injectMissingAttendanceRecords(
          studentQaida, studentAtt, singleStudentList, 'qaida');
    }
    if (!isHifz && !isQaida && !isHusn && studentAcademic.isNotEmpty) {
      studentAcademic = PerformanceCalculator.injectMissingAttendanceRecords(
          studentAcademic, studentAtt, singleStudentList, 'academic');
    }

    studentHifz = studentHifz
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();
    studentQaida = studentQaida
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();
    studentHusn = studentHusn
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();
    studentAcademic = studentAcademic
        .where((r) => r['student_id'].toString() == targetIdStr)
        .toList();

    studentHifz.sort((a, b) {
      DateTime dA = PerformanceCalculator.parseDateRobust(a['date']?.toString() ?? '');
      DateTime dB = PerformanceCalculator.parseDateRobust(b['date']?.toString() ?? '');
      return dB.compareTo(dA);
    });
    studentQaida.sort((a, b) {
      DateTime dA = PerformanceCalculator.parseDateRobust(a['date']?.toString() ?? '');
      DateTime dB = PerformanceCalculator.parseDateRobust(b['date']?.toString() ?? '');
      return dB.compareTo(dA);
    });
    studentHusn.sort((a, b) {
      DateTime dA = PerformanceCalculator.parseDateRobust(a['date']?.toString() ?? '');
      DateTime dB = PerformanceCalculator.parseDateRobust(b['date']?.toString() ?? '');
      return dB.compareTo(dA);
    });
    studentAtt.sort((a, b) {
      DateTime dA = PerformanceCalculator.parseDateRobust(a['date']?.toString() ?? '');
      DateTime dB = PerformanceCalculator.parseDateRobust(b['date']?.toString() ?? '');
      return dB.compareTo(dA);
    });
    studentAcademic.sort((a, b) {
      DateTime dA = PerformanceCalculator.parseDateRobust(a['date']?.toString() ?? '');
      DateTime dB = PerformanceCalculator.parseDateRobust(b['date']?.toString() ?? '');
      return dB.compareTo(dA);
    });

    DateTime latest = DateTime.now();
    for (var r in [...studentHifz, ...studentQaida, ...studentHusn, ...studentAtt]) {
      final dt = PerformanceCalculator.parseDateRobust(r['date']?.toString() ?? '');
      if (dt.year > 2000 && dt.isAfter(latest)) {
        latest = dt;
      }
    }
    _filterStartDate ??= DateTime(latest.year, latest.month, 1);
    _filterEndDate ??= DateTime(latest.year, latest.month + 1, 0, 23, 59, 59);

    setState(() {
      _hifzRecords = studentHifz;
      _qaidaRecords = studentQaida;
      _husnSautRecords = studentHusn;
      _attendanceRecords = studentAtt;
      _complaints = allComplaints
          .where((r) => r['student_id'].toString() == targetIdStr)
          .toList();
      _examResults = studentExams;
      _academicReports = studentAcademic;
      _student = studentObj;

      int weekStart = settings['week_start_day'] ?? 6;
      _stats = PerformanceCalculator.calculateWeeklyPerformance(
        hifzRecords: _hifzRecords,
        qaidaRecords: _qaidaRecords,
        attendanceRecords: _attendanceRecords,
        weekStartDay: weekStart,
      );

      _isLoading = false;
    });

    _fetchAIAdvice();
  }

  Future<void> _fetchAIAdvice() async {
    if (_stats['hifzCount'] == 0 && _attendanceRecords.isEmpty) return;
    setState(() => _isAiLoading = true);
    try {
      int complaintsCount = _complaints.length;
      String complaintContext = complaintsCount > 0
          ? 'There are $complaintsCount complaints. Focus on Tarbiyat.'
          : 'Behavior is good.';
      final prompt =
          'Student: ${_student?['name']}. Attendance: ${_stats['attendancePerc']}%, Academic Score: ${_stats['academicScore']}/100. Context: $complaintContext. Give 2 short lines of reformative advice in URDU for parent. Only Urdu text.';
      final insight = await AIService.generateInsight(prompt);
      if (mounted) setState(() => _aiAdvice = insight);
    } catch (_) {
      if (mounted) setState(() => _aiAdvice = 'مشورہ فی الحال دستیاب نہیں ہے۔');
    } finally {
      if (mounted) setState(() => _isAiLoading = false);
    }
  }

  Future<void> _runSync() async {
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('سنک ہو رہا ہے...')));
    try {
      await SyncService.runTwoWaySync();
      _loadReportData();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('ڈیٹا اپڈیٹ ہو گیا'), backgroundColor: Colors.green));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('سنک میں خرابی: $e'), backgroundColor: Colors.red));
    }
  }

  Future<void> _sharePdf() async {
    try {
      final bool isUrdu = isUrduGlobal.value;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(isUrdu
              ? 'پی ڈی ایف تیار ہو رہی ہے...'
              : 'Generating PDF...')));

      // Build the report as a headless widget to solve Urdu text breaking and scrolling capture issues
      final Widget reportWidget = MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          fontFamily: 'JameelNooriNastaleeq',
          textTheme: const TextTheme(
            bodyMedium: TextStyle(color: Colors.black),
            bodyLarge: TextStyle(color: Colors.black),
            titleMedium: TextStyle(color: Colors.black),
          ),
        ),
        home: Directionality(
          textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
          child: MediaQuery(
            data: const MediaQueryData(size: Size(800, 2000)),
            child: Material(
              color: const Color(0xFFF4F6F8),
              child: Container(
                width: 800,
                padding: const EdgeInsets.all(20),
                color: const Color(0xFFF4F6F8),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _buildProfileCard(isUrdu),
                    const SizedBox(height: 12),
                    _buildSummaryCardsSection(isUrdu),
                    const SizedBox(height: 12),
                    if (_isHifzStudent) ...[
                      _buildHifzCompletionEstimateCard(isUrdu),
                      const SizedBox(height: 12),
                    ],
                    _buildPerformanceSection(isUrdu),
                    const SizedBox(height: 12),
                    _buildAdviceCard(isUrdu),
                    const SizedBox(height: 12),
                    if (_isHusnSautStudent) ...[
                      _buildHusnSautTable(isUrdu),
                    ] else if (_isHifzStudent) ...[
                      _buildHifzTable(isUrdu),
                    ] else if (_isQaidaStudent) ...[
                      _buildQaidaTable(isUrdu),
                    ] else ...[
                      _buildAcademicRegister(isUrdu),
                    ],
                    const SizedBox(height: 12),
                    _buildAttendanceHistoryTable(isUrdu),
                    const SizedBox(height: 15),
                    _buildDailyLog(isUrdu),
                    const SizedBox(height: 12),
                    _buildComplaintsCard(isUrdu),
                    const SizedBox(height: 12),
                    _buildExamsCard(isUrdu),
                  ],
                ),
              ),
            ),
          ),
        ),
      );

      final Uint8List imageBytes = await _screenshotController.captureFromWidget(
        reportWidget,
        delay: const Duration(milliseconds: 500),
        context: context,
      );

      final pdf = pw.Document();
      final pdfImage = pw.MemoryImage(imageBytes);

      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat.a4,
          margin: const pw.EdgeInsets.all(10),
          build: (pw.Context context) {
            return pw.Center(
              child: pw.Image(pdfImage, fit: pw.BoxFit.contain),
            );
          },
        ),
      );

      final dir = await getTemporaryDirectory();
      final file =
          File('${dir.path}/student_report_${_student?['id'] ?? 'doc'}.pdf');
      await file.writeAsBytes(await pdf.save());

      await Share.shareXFiles(
        [XFile(file.path)],
        text: isUrdu
            ? 'طالب علم کی تعلیمی رپورٹ'
            : 'Student Academic Report',
      );
    } catch (e) {
      debugPrint("PDF Generate Error: $e");
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Error generating PDF: $e')));
      }
    }
  }

  String _formatDateUrdu(String? dateStr) {
    if (dateStr == null || dateStr.isEmpty) return '';
    try {
      final dt = DateTime.parse(dateStr);
      final monthsUr = [
        '',
        'جنوری',
        'فروری',
        'مارچ',
        'اپریل',
        'مئی',
        'جون',
        'جولائی',
        'اگست',
        'ستمبر',
        'اکتوبر',
        'نومبر',
        'دسمبر'
      ];
      return '${dt.day} ${monthsUr[dt.month]}';
    } catch (_) {
      return dateStr;
    }
  }

  Future<void> _shareReportSummary() async {
    final studentName = _student?['name']?.toString() ?? 'طالب علم';
    final fatherName = _student?['father_name']?.toString() ?? '';
    final studentFullName =
        fatherName.isNotEmpty ? '$studentName ولد $fatherName' : studentName;
    final className =
        (_student?['class_name'] ?? _student?['class'] ?? '').toString();

    // 1. Calculate Date Range
    String fromDateStr = '';
    String toDateStr = '';
    if (_filterStartDate != null && _filterEndDate != null) {
      fromDateStr = _formatDateUrdu(
          intl.DateFormat('yyyy-MM-dd').format(_filterStartDate!));
      toDateStr = _formatDateUrdu(
          intl.DateFormat('yyyy-MM-dd').format(_filterEndDate!));
    } else {
      List<DateTime> allDates = [];
      for (var r in _attendanceRecords) {
        final d = DateTime.tryParse(r['date']?.toString() ?? '');
        if (d != null) allDates.add(d);
      }
      for (var r in _hifzRecords) {
        final d = DateTime.tryParse(r['date']?.toString() ?? '');
        if (d != null) allDates.add(d);
      }
      if (allDates.isNotEmpty) {
        allDates.sort();
        fromDateStr = _formatDateUrdu(
            intl.DateFormat('yyyy-MM-dd').format(allDates.first));
        toDateStr = _formatDateUrdu(
            intl.DateFormat('yyyy-MM-dd').format(allDates.last));
      } else {
        final now = DateTime.now();
        final monthStart = DateTime(now.year, now.month, 1);
        fromDateStr = _formatDateUrdu(
            intl.DateFormat('yyyy-MM-dd').format(monthStart));
        toDateStr = _formatDateUrdu(
            intl.DateFormat('yyyy-MM-dd').format(now));
      }
    }

    final hRecords = _filteredHifzRecords;
    final aRecords = _filteredAttendanceRecords;

    // 2. Attendance %
    final Set<String> presentDates = {};
    final Set<String> absentDates = {};
    final Set<String> leaveDates = {};
    for (var a in aRecords) {
      final date = PerformanceCalculator.normalizeDate(a['date']?.toString() ?? '');
      if (date.isEmpty || date == '1970-01-01') continue;
      final st = (a['status'] ?? '').toString().trim().toUpperCase();
      if (st == 'ABSENT' || st == 'A' || st.contains('غیر حاضر') || st.contains('غائب')) {
        absentDates.add(date);
      } else if (st == 'LEAVE' || st == 'L' || st.contains('رخصت')) {
        leaveDates.add(date);
      } else if (st == 'PRESENT' || st == 'P' || st.contains('حاضر')) {
        presentDates.add(date);
      }
    }
    for (var h in hRecords) {
      final date = PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '');
      final attSt = (h['att_status'] ?? '').toString().trim();
      if (attSt == 'غیر حاضر') {
        absentDates.add(date);
      } else if (attSt == 'رخصت') {
        leaveDates.add(date);
      } else if (date.isNotEmpty && !absentDates.contains(date)) {
        presentDates.add(date);
      }
    }
    for (var d in absentDates) {
      presentDates.remove(d);
    }
    for (var d in leaveDates) {
      presentDates.remove(d);
    }
    int totalAttDays = presentDates.length + absentDates.length + leaveDates.length;
    final attendancePerc = totalAttDays > 0
        ? (presentDates.length / totalAttDays * 100).toStringAsFixed(0)
        : (_stats['attendancePerc']?.toString() ?? '0');

    // 3. Naghas & Summary Calculation
    int sabaqNagha = 0;
    int sabqiNagha = 0;
    int manzilNagha = 0;
    int totalSabaqLines = 0;
    int sabqiDaysRecited = 0;
    double manzilParasRecited = 0.0;

    for (var r in hRecords) {
      final attStatus = (r['att_status'] ?? '').toString().trim();
      if (attStatus == 'غیر حاضر' || attStatus == 'Absent' || attStatus == 'A' ||
          attStatus == 'رخصت' || attStatus == 'Leave' || attStatus == 'L') {
        continue;
      }

      final sp = (r['sabaq_p'] ?? r['sabaq_grade'] ?? '').toString().trim();
      final sq = (r['sabqi_p'] ?? r['sabqi_grade'] ?? '').toString().trim();
      final mp = (r['manzil_p'] ?? r['manzil_grade'] ?? '').toString().trim();
      final sLines = int.tryParse((r['sabaq_lines'] ?? '0').toString().trim()) ?? 0;
      final sPara = (r['sabaq_para'] ?? '').toString().trim();
      final sqPara = (r['sabqi_para'] ?? '').toString().trim();
      final sqQty = (r['sabqi_qty'] ?? '').toString().trim();
      final mDetails = (r['manzil_details'] ?? r['manzil_parah'] ?? '').toString().trim();
      final mQty = (r['manzil_total_qty'] ?? r['manzil_amount'] ?? '').toString().trim();

      if (sp == 'ناغہ' || sp == 'Absent' || sp == 'کمزور' || sp == 'نہیں سنایا' || (sLines == 0 && sPara.isEmpty)) {
        sabaqNagha++;
      } else {
        totalSabaqLines += sLines;
      }

      if (sq == 'ناغہ' || sq == 'Absent' || sq == 'کمزور' || sq == 'نہیں سنائی' || (sqPara.isEmpty && sqQty.isEmpty)) {
        sabqiNagha++;
      } else {
        sabqiDaysRecited++;
      }

      if (mp == 'ناغہ' || mp == 'Absent' || mp == 'کمزور' || mp == 'نہیں سنائی' || (mDetails.isEmpty && mQty.isEmpty)) {
        manzilNagha++;
      } else {
        double paras = _parseManzilAmountToParas(mQty);
        if (paras == 0.0 && mDetails.isNotEmpty) paras = 1.0;
        manzilParasRecited += paras;
      }
    }

    // If student is in Qaida, also check qaida naghas
    final qRecords = _filteredQaidaRecords;
    if (hRecords.isEmpty && qRecords.isNotEmpty) {
      for (var r in qRecords) {
        final attStatus = (r['att_status'] ?? '').toString().trim();
        if (attStatus == 'غیر حاضر' || attStatus == 'Absent' || attStatus == 'A' ||
            attStatus == 'رخصت' || attStatus == 'Leave' || attStatus == 'L') {
          continue;
        }
        final st =
            (r['sabaq_status'] ?? r['grade_1'] ?? '').toString().trim();
        if (st == 'ناغہ' || st == 'Absent' || st == 'کمزور') {
          sabaqNagha++;
        } else {
          totalSabaqLines += 1;
        }
      }
    }

    // 4. Complaints count
    final complaintsCount = _complaints.length;

    // 5. Fee status check
    String feeStatus = '';
    try {
      final db = await DBHelper().database;
      final targetId = _student?['id'];
      if (targetId != null) {
        final feeRows = await db.query(
          'fee_submissions',
          where: 'student_id = ?',
          whereArgs: [targetId],
          orderBy: 'id DESC',
          limit: 1,
        );
        if (feeRows.isNotEmpty) {
          final lastFee = feeRows.first;
          final status = (lastFee['status'] ?? 'Pending').toString();
          final amount = lastFee['amount']?.toString() ?? '0';
          if (status.toLowerCase() == 'approved' ||
              status.toLowerCase() == 'paid' ||
              status == 'ادا شدہ') {
            feeStatus = 'ادا شدہ ($amount روپے)';
          } else {
            feeStatus = 'بقایا ($amount روپے)';
          }
        }
      }
    } catch (_) {}

    // 6. Build the formatted Urdu WhatsApp message
    final buffer = StringBuffer();
    buffer.writeln('📋 *مجموعی تعلیمی و حاضری رپورٹ*');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('👤 *برائے :* $studentFullName');
    if (className.isNotEmpty) {
      buffer.writeln('🏫 *جماعت :* $className');
    }
    buffer.writeln('📅 *از :* $fromDateStr *تا :* $toDateStr');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('📊 *حاضری :* $attendancePerc فیصد');
    final bool isManzilGardan = (_student?['hifz_section'] == 'manzil_gardan');
    if (isManzilGardan) {
      buffer.writeln('🏷️ *سیکشن :* منزل/گردان (پارٹ ٹائم)');
      buffer.writeln('📖 *سبق و سبقی :* استثناء (پارٹ ٹائم)');
      buffer.writeln('🕋 *منزل :* ${manzilParasRecited.toStringAsFixed(1)} پارے سنائے | $manzilNagha ناغے');
    } else {
      buffer.writeln('📖 *سبق :* $totalSabaqLines لائنیں سنائیں | $sabaqNagha ناغے');
      if (_hifzRecords.isNotEmpty) {
        buffer.writeln('📚 *سبقی :* $sabqiDaysRecited دن سنائی | $sabqiNagha ناغے');
        buffer.writeln('🕋 *منزل :* ${manzilParasRecited.toStringAsFixed(1)} پارے سنائے | $manzilNagha ناغے');
      }
    }
    buffer.writeln('⚠️ *شکایات :* $complaintsCount');
    if (feeStatus.isNotEmpty) {
      buffer.writeln('💰 *فیس سٹیٹس :* $feeStatus');
    }
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('_منجانب: ادارہ اشرف المدارس فروکہ_');

    await Share.share(buffer.toString(), subject: 'مجموعی رپورٹ - $studentName');
  }

  Future<void> _sendSmsReport() async {
    final studentName = _student?['name']?.toString() ?? 'نامعلوم';
    final studentFullName = _student?['full_name']?.toString() ?? studentName;
    final className = (_student?['class_name'] ?? _student?['class'] ?? widget.forceClassName ?? '').toString();
    
    String fromDateStr = _filterStartDate != null ? intl.DateFormat('yyyy-MM-dd').format(_filterStartDate!) : '-';
    String toDateStr = _filterEndDate != null ? intl.DateFormat('yyyy-MM-dd').format(_filterEndDate!) : '-';
    
    // Fallbacks
    final hRecords = _filteredHifzRecords;
    final aRecords = _filteredAttendanceRecords;

    final Set<String> presentDates = {};
    final Set<String> absentDates = {};
    final Set<String> leaveDates = {};
    for (var a in aRecords) {
      final date = PerformanceCalculator.normalizeDate(a['date']?.toString() ?? '');
      if (date.isEmpty || date == '1970-01-01') continue;
      final st = (a['status'] ?? '').toString().trim().toUpperCase();
      if (st == 'ABSENT' || st == 'A' || st.contains('غیر حاضر') || st.contains('غائب')) {
        absentDates.add(date);
      } else if (st == 'LEAVE' || st == 'L' || st.contains('رخصت')) {
        leaveDates.add(date);
      } else if (st == 'PRESENT' || st == 'P' || st.contains('حاضر')) {
        presentDates.add(date);
      }
    }
    for (var h in hRecords) {
      final date = PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '');
      final attSt = (h['att_status'] ?? '').toString().trim();
      if (attSt == 'غیر حاضر') absentDates.add(date);
      else if (attSt == 'رخصت') leaveDates.add(date);
      else if (date.isNotEmpty && !absentDates.contains(date)) presentDates.add(date);
    }
    for (var d in absentDates) presentDates.remove(d);
    for (var d in leaveDates) presentDates.remove(d);
    
    int totalAttDays = presentDates.length + absentDates.length + leaveDates.length;
    final attendancePerc = totalAttDays > 0
        ? (presentDates.length / totalAttDays * 100).toStringAsFixed(0)
        : (_stats['attendancePerc']?.toString() ?? '0');

    int sabaqNagha = 0;
    int sabqiNagha = 0;
    int manzilNagha = 0;
    int totalSabaqLines = 0;
    int sabqiDaysRecited = 0;
    double manzilParasRecited = 0.0;

    for (var r in hRecords) {
      final attStatus = (r['att_status'] ?? '').toString().trim();
      if (attStatus == 'غیر حاضر' || attStatus == 'Absent' || attStatus == 'A' ||
          attStatus == 'رخصت' || attStatus == 'Leave' || attStatus == 'L') continue;
      final sp = (r['sabaq_p'] ?? r['sabaq_grade'] ?? '').toString().trim();
      final sq = (r['sabqi_p'] ?? r['sabqi_grade'] ?? '').toString().trim();
      final mp = (r['manzil_p'] ?? r['manzil_grade'] ?? '').toString().trim();
      final sLines = int.tryParse((r['sabaq_lines'] ?? '0').toString().trim()) ?? 0;
      final sPara = (r['sabaq_para'] ?? '').toString().trim();
      final sqPara = (r['sabqi_para'] ?? '').toString().trim();
      final sqQty = (r['sabqi_qty'] ?? '').toString().trim();
      final mDetails = (r['manzil_details'] ?? r['manzil_parah'] ?? '').toString().trim();
      final mQty = (r['manzil_total_qty'] ?? r['manzil_amount'] ?? '').toString().trim();

      if (sp == 'ناغہ' || sp == 'Absent' || sp == 'کمزور' || sp == 'نہیں سنایا' || (sLines == 0 && sPara.isEmpty)) sabaqNagha++;
      else totalSabaqLines += sLines;

      if (sq == 'ناغہ' || sq == 'Absent' || sq == 'کمزور' || sq == 'نہیں سنائی' || (sqPara.isEmpty && sqQty.isEmpty)) sabqiNagha++;
      else sabqiDaysRecited++;

      if (mp == 'ناغہ' || mp == 'Absent' || mp == 'کمزور' || mp == 'نہیں سنائی' || (mDetails.isEmpty && mQty.isEmpty)) manzilNagha++;
      else {
        double paras = double.tryParse(mQty) ?? (mDetails.isNotEmpty ? 1.0 : 0.0);
        manzilParasRecited += paras;
      }
    }

    final buffer = StringBuffer();
    final classSuffix = className.isNotEmpty ? ' ($className)' : '';
    buffer.writeln('مدرسہ اشرف المدارس: $studentFullName$classSuffix کی رپورٹ ($fromDateStr تا $toDateStr)۔');
    buffer.writeln('حاضری: $attendancePerc%۔');
    if (_hifzRecords.isNotEmpty) {
      buffer.writeln('سبق لائنیں: $totalSabaqLines (ناغے: $sabaqNagha)۔ سبقی دن: $sabqiDaysRecited (ناغے: $sabqiNagha)۔ منزل پارے: ${manzilParasRecited.toStringAsFixed(1)} (ناغے: $manzilNagha)۔');
    }
    buffer.writeln('شکایات: ${_complaints.length}۔');

    String msg = buffer.toString();
    String? parentPhone = _student?['parent_phone']?.toString();
    
    if (parentPhone == null || parentPhone.isEmpty) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('والدین کا موبائل نمبر درج نہیں ہے۔')));
      return;
    }
    
    final uri = Uri.parse('sms:$parentPhone?body=${Uri.encodeComponent(msg)}');
    try {
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri);
      } else {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('SMS ایپ نہیں کھولی جا سکی۔')));
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: $e')));
    }
  }

  Color _gradeColor(String? g) {
    final s = (g ?? '').trim();
    if (s == 'Excellent' || s == 'A+' || s == 'ممتاز' || s == 'بہترین') {
      return Colors.green;
    }
    if (s == 'Good' || s == 'A' || s == 'B' || s == 'بہتر' || s == 'اچھا') {
      return Colors.blue;
    }
    if (s == 'Average' ||
        s == 'C' ||
        s == 'D' ||
        s == 'معمولی' ||
        s == 'مناسب') {
      return Colors.orange;
    }
    if (s == 'Weak' ||
        s == 'E' ||
        s == 'F' ||
        s == 'کمزور' ||
        s == 'توجہ طلب') {
      return Colors.red;
    }
    return Colors.grey;
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    if (widget.isEmbedded) {
      if (_isLoading) {
        return const Center(
          child: Padding(
            padding: EdgeInsets.all(32.0),
            child: CircularProgressIndicator(),
          ),
        );
      }
      return SingleChildScrollView(
        padding: const EdgeInsets.all(12),
        child: Screenshot(
          controller: _screenshotController,
          child: Container(
            color: bgColor,
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.only(bottom: 8.0),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      ElevatedButton.icon(
                        icon: const Icon(Icons.share, size: 16),
                        label: Text(
                          isUrdu ? 'WhatsApp رپورٹ' : 'Share WhatsApp',
                          style: const TextStyle(fontSize: 12),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF25D366),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 6),
                        ),
                        onPressed: _shareReportSummary,
                      ),
                      const SizedBox(width: 8),
                      ElevatedButton.icon(
                        icon: const Icon(Icons.picture_as_pdf, size: 16),
                        label: Text(
                          isUrdu ? 'PDF' : 'PDF',
                          style: const TextStyle(fontSize: 12),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: primaryGreen,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 6),
                        ),
                        onPressed: _sharePdf,
                      ),
                      const SizedBox(width: 8),
                      ElevatedButton.icon(
                        icon: const Icon(Icons.sms, size: 16),
                        label: Text(
                          isUrdu ? 'SMS' : 'SMS',
                          style: const TextStyle(fontSize: 12),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.blueAccent,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 6),
                        ),
                        onPressed: _sendSmsReport,
                      ),
                    ],
                  ),
                ),
                _buildProfileCard(isUrdu),
                const SizedBox(height: 12),
                _buildDateFilterBar(isUrdu),
                const SizedBox(height: 12),
                _buildSummaryCardsSection(isUrdu),
                const SizedBox(height: 12),
                _buildPerformanceSection(isUrdu),
                const SizedBox(height: 12),
                _buildAdviceCard(isUrdu),
                const SizedBox(height: 12),
                if (_isHusnSautStudent) ...[
                  _buildHusnSautTable(isUrdu),
                ] else if (_isHifzStudent) ...[
                  _buildHifzTable(isUrdu),
                ] else if (_isQaidaStudent) ...[
                  _buildQaidaTable(isUrdu),
                ] else ...[
                  _buildAcademicRegister(isUrdu),
                ],
                _buildAttendanceHistoryTable(isUrdu),
                const SizedBox(height: 15),
                _buildDailyLog(isUrdu),
                const SizedBox(height: 12),
                _buildComplaintsCard(isUrdu),
                const SizedBox(height: 12),
                _buildExamsCard(isUrdu),
              ],
            ),
          ),
        ),
      );
    }

    return Directionality(
      textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
      child: PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, result) {
          if (didPop) return;
          if (Navigator.of(context).canPop()) {
            Navigator.of(context).pop();
          } else {
            Navigator.pushReplacement(context,
                MaterialPageRoute(builder: (c) => const DashboardPage()));
          }
        },
        child: Scaffold(
          backgroundColor: bgColor,
          drawer: const AppDrawer(),
          appBar: AppBar(
            leading: Navigator.canPop(context)
                ? IconButton(
                    icon: const Icon(Icons.arrow_back),
                    onPressed: () => Navigator.pop(context),
                  )
                : Builder(
                    builder: (context) => IconButton(
                      icon: const Icon(Icons.menu),
                      onPressed: () => Scaffold.of(context).openDrawer(),
                    ),
                  ),
            title: Text(isUrdu ? 'میری تعلیمی رپورٹ' : 'My Academic Report'),
            backgroundColor: primaryGreen,
            foregroundColor: Colors.white,
            actions: [
              IconButton(
                  tooltip: isUrdu ? 'WhatsApp رپورٹ' : 'Share WhatsApp Summary',
                  icon: const Icon(Icons.share),
                  onPressed: _shareReportSummary),
              IconButton(
                  tooltip: isUrdu ? 'PDF شیئر کریں' : 'Share PDF',
                  icon: const Icon(Icons.picture_as_pdf),
                  onPressed: _sharePdf),
              IconButton(icon: const Icon(Icons.sms), onPressed: _sendSmsReport),
              IconButton(icon: const Icon(Icons.sync), onPressed: _runSync)
            ],
          ),
          body: _isLoading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(12),
                  child: Screenshot(
                    controller: _screenshotController,
                    child: Container(
                      color: bgColor,
                      child: Column(
                        children: [
                          _buildProfileCard(isUrdu),
                          const SizedBox(height: 12),
                          _buildDateFilterBar(isUrdu), // Period / Month Filter
                          const SizedBox(height: 12),
                          _buildSummaryCardsSection(isUrdu), // Sabaq, Sabqi, Manzil & Attendance Summary
                          const SizedBox(height: 12),
                          if (_isHifzStudent) ...[
                            _buildHifzCompletionEstimateCard(isUrdu),
                            const SizedBox(height: 12),
                          ],
                          _buildPerformanceSection(isUrdu), // Graph + Gauges
                          const SizedBox(height: 12),
                          _buildAdviceCard(isUrdu), // Advice
                          const SizedBox(height: 12),
                          if (_isHusnSautStudent) ...[
                            _buildHusnSautTable(isUrdu),
                          ] else if (_isHifzStudent) ...[
                            _buildHifzTable(isUrdu),
                          ] else if (_isQaidaStudent) ...[
                            _buildQaidaTable(isUrdu),
                          ] else ...[
                            _buildAcademicRegister(isUrdu),
                          ],
                          const SizedBox(height: 12),
                          _buildDailyLog(isUrdu),
                          const SizedBox(height: 12),
                          _buildComplaintsCard(isUrdu),
                          const SizedBox(height: 12),
                          _buildExamsCard(isUrdu),
                        ],
                      ),
                    ),
                  ),
                ),
        ),
      ),
    );
  }

  Widget _buildProfileCard(bool isUrdu) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(15),
        boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 5)],
      ),
      child: Column(
        children: [
          CircleAvatar(
              radius: 30,
              backgroundColor: primaryGreen,
              child: const Icon(Icons.person, color: Colors.white, size: 35)),
          const SizedBox(height: 10),
          Text(_student?['name'] ?? 'Student Name',
              style:
                  const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          Text(
              '${isUrdu ? 'ولد' : 'S/O'}: ${_student?['father_name'] ?? '-'} | ${isUrdu ? 'درجہ' : 'Class'}: ${_student?['class_name'] ?? _student?['class'] ?? '-'}',
              style: const TextStyle(color: Colors.grey)),
          if (_student?['hifz_section'] == 'manzil_gardan') ...[
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
              decoration: BoxDecoration(
                color: Colors.amber.shade100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.amber.shade700),
              ),
              child: Text(
                isUrdu ? "⭐ سیکشن B: منزل/گردان (پارٹ ٹائم)" : "⭐ Section B: Manzil/Gardan (Part-time)",
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.bold,
                  color: Colors.amber.shade900,
                ),
              ),
            ),
          ],
          const SizedBox(height: 10),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF25D366),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
            ),
            icon: const Icon(Icons.share, size: 18),
            label: Text(
              isUrdu ? 'مختصر رپورٹ WhatsApp کریں' : 'Share WhatsApp Report',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
            ),
            onPressed: _shareReportSummary,
          ),
        ],
      ),
    );
  }

  Widget _buildHifzCompletionEstimateCard(bool isUrdu) {
    final result = HifzCompletionCalculator.calculate(
      student: _student,
      hifzRecords: _hifzRecords,
    );

    Widget buildPill(String label, String value) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: const Color(0x59000000),
          border: Border.all(color: const Color(0x40FFFFFF)),
          borderRadius: BorderRadius.circular(20),
        ),
        child: RichText(
          text: TextSpan(
            children: [
              TextSpan(text: '$label: ', style: const TextStyle(color: Color(0xFF6EE7B7), fontSize: 10.5, fontWeight: FontWeight.normal, fontFamily: 'JameelNooriNastaleeq')),
              TextSpan(text: value, style: const TextStyle(color: Color(0xFFFDE047), fontSize: 11, fontWeight: FontWeight.bold, fontFamily: 'JameelNooriNastaleeq')),
            ],
          ),
        ),
      );
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFF0F3D2E),
        borderRadius: BorderRadius.circular(12),
        border: const Border(
          top: BorderSide(color: Color(0xFF1E6B52)),
          left: BorderSide(color: Color(0xFF1E6B52)),
          bottom: BorderSide(color: Color(0xFF1E6B52)),
          right: BorderSide(color: Color(0xFFEAB308), width: 5),
        ),
        boxShadow: const [
          BoxShadow(color: Colors.black26, blurRadius: 6, offset: Offset(0, 3)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  const Icon(Icons.calendar_month, color: Color(0xFFFDE047), size: 19),
                  const SizedBox(width: 6),
                  Text(
                    isUrdu ? 'تخمینہ تکمیلِ حفظ' : 'Hifz Completion Estimate',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.bold,
                      fontSize: 14.5,
                      fontFamily: 'JameelNooriNastaleeq',
                    ),
                  ),
                ],
              ),
              buildPill(isUrdu ? "رفتار" : "Pace", '${result.avgDailyLines} ${isUrdu ? "لائنیں" : "lines"}'),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              buildPill(isUrdu ? "نسخہ" : "Edition", result.edition),
              buildPill(isUrdu ? "ترتیب" : "Order", result.sequenceTitle),
              buildPill(isUrdu ? "مکمل" : "Done", '${result.completedParas}/30 ${isUrdu ? "پارے" : "paras"}'),
            ],
          ),
          const Divider(color: Color(0x33FFFFFF), height: 16),

          if (result.isCompleted) ...[
            Row(
              children: [
                const Icon(Icons.stars, color: Color(0xFFFDE047), size: 22),
                const SizedBox(width: 8),
                Text(
                  isUrdu ? 'مبارک ہو! حفظِ قرآن مکمل ہو چکا ہے' : 'Hifz Completed!',
                  style: const TextStyle(color: Color(0xFFFDE047), fontWeight: FontWeight.bold, fontSize: 14, fontFamily: 'JameelNooriNastaleeq'),
                ),
              ],
            ),
          ] else ...[
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Text(
                  isUrdu ? 'متوقع تاریخ فراغت: ' : 'Expected Date: ',
                  style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 12, fontWeight: FontWeight.w600, fontFamily: 'JameelNooriNastaleeq'),
                ),
                Text(
                  result.expectedDateFormatted,
                  style: const TextStyle(
                    color: Color(0xFFFDE047),
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    fontFamily: 'JameelNooriNastaleeq',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 5),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: RichText(
                    text: TextSpan(
                      children: [
                        TextSpan(text: isUrdu ? 'متوقع دورانیہ: ' : 'Duration: ', style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 11.5, fontFamily: 'JameelNooriNastaleeq')),
                        TextSpan(text: result.durationText, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11.5, fontFamily: 'JameelNooriNastaleeq')),
                        const TextSpan(text: ' • ', style: TextStyle(color: Color(0xFF6EE7B7))),
                        TextSpan(text: isUrdu ? 'خالص ایام: ' : 'Net days: ', style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 11.5, fontFamily: 'JameelNooriNastaleeq')),
                        TextSpan(text: '${result.netEducationalDays} ${isUrdu ? "دن" : "d"}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11.5, fontFamily: 'JameelNooriNastaleeq')),
                      ],
                    ),
                  ),
                ),
                RichText(
                  text: TextSpan(
                    children: [
                      TextSpan(text: '${result.progressPerc}% ', style: const TextStyle(color: Color(0xFFFDE047), fontWeight: FontWeight.bold, fontSize: 12)),
                      TextSpan(text: '(${result.completedLines}/${result.totalLines})', style: const TextStyle(color: Colors.white, fontSize: 10.5)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            ClipRRect(
              borderRadius: BorderRadius.circular(5),
              child: LinearProgressIndicator(
                value: (result.progressPerc / 100.0).clamp(0.0, 1.0),
                minHeight: 8,
                backgroundColor: const Color(0x33FFFFFF),
                valueColor: const AlwaysStoppedAnimation<Color>(Color(0xFFFACC15)),
              ),
            ),
          ],
        ],
      ),
    );
  }

  double _parseManzilAmountToParas(String? amountStr) {
    if (amountStr == null || amountStr.isEmpty || amountStr == '-' || amountStr == 'ناغہ') return 0.0;
    final s = amountStr.trim();
    const map = {
      'Quarter': 0.25, 'پاؤ': 0.25,
      'Half': 0.5, 'آدھا': 0.5,
      'Three Quarter': 0.75, 'تین پاؤ': 0.75,
      'Full': 1.0, 'مکمل': 1.0,
      '1 Parah': 1.0, 'ایک پارہ': 1.0, '1': 1.0,
      '1.25 Parah': 1.25, 'سوا پارہ': 1.25,
      '1.5 Parah': 1.5, 'ڈیڑھ': 1.5, 'ڈیڑھ پارہ': 1.5, '1.5': 1.5,
      '2 Parah': 2.0, 'دو پارے': 2.0, 'دو': 2.0, '2': 2.0,
      '2.5 Parah': 2.5, 'ڈھائی': 2.5, 'ڈھائی پارے': 2.5, '2.5': 2.5,
      '3 Parah': 3.0, 'تین': 3.0, 'تین پارے': 3.0, '3': 3.0
    };
    if (map.containsKey(s)) return map[s]!;
    final match = RegExp(r'^([0-9\.]+)').firstMatch(s);
    if (match != null) {
      return double.tryParse(match.group(1)!) ?? 0.0;
    }
    return 0.0;
  }

  Widget _buildDateFilterBar(bool isUrdu) {
    String dateRangeLabel;
    if (_filterStartDate == null || _filterEndDate == null) {
      dateRangeLabel = isUrdu ? 'تمام ریکارڈز' : 'All Records';
    } else {
      final fromStr = intl.DateFormat('d MMM yyyy').format(_filterStartDate!);
      final toStr = intl.DateFormat('d MMM yyyy').format(_filterEndDate!);
      dateRangeLabel = isUrdu ? '$fromStr تا $toStr' : '$fromStr to $toStr';
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(15),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4, offset: Offset(0, 1))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Icon(Icons.calendar_month, color: primaryGreen, size: 20),
                  const SizedBox(width: 6),
                  Text(
                    isUrdu ? 'رپورٹ کا دورانیہ' : 'Report Period',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: primaryGreen),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: primaryGreen.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: primaryGreen.withOpacity(0.2)),
                ),
                child: Text(
                  dateRangeLabel,
                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: primaryGreen),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _buildFilterChip(
                  label: isUrdu ? 'یہ مہینہ' : 'This Month',
                  selected: _selectedPeriodFilter == 'current_month',
                  onTap: () {
                    final now = DateTime.now();
                    setState(() {
                      _selectedPeriodFilter = 'current_month';
                      _filterStartDate = DateTime(now.year, now.month, 1);
                      _filterEndDate = DateTime(now.year, now.month + 1, 0, 23, 59, 59);
                    });
                  },
                ),
                const SizedBox(width: 6),
                _buildFilterChip(
                  label: isUrdu ? 'گزشتہ مہینہ' : 'Last Month',
                  selected: _selectedPeriodFilter == 'last_month',
                  onTap: () {
                    final now = DateTime.now();
                    setState(() {
                      _selectedPeriodFilter = 'last_month';
                      _filterStartDate = DateTime(now.year, now.month - 1, 1);
                      _filterEndDate = DateTime(now.year, now.month, 0, 23, 59, 59);
                    });
                  },
                ),
                const SizedBox(width: 6),
                _buildFilterChip(
                  label: isUrdu ? 'تمام ریکارڈز' : 'All Time',
                  selected: _selectedPeriodFilter == 'all',
                  onTap: () {
                    setState(() {
                      _selectedPeriodFilter = 'all';
                      _filterStartDate = null;
                      _filterEndDate = null;
                    });
                  },
                ),
                const SizedBox(width: 6),
                _buildFilterChip(
                  label: isUrdu ? 'مخصوص تاریخ 📅' : 'Custom 📅',
                  selected: _selectedPeriodFilter == 'custom',
                  onTap: () async {
                    final now = DateTime.now();
                    final initialRange = DateTimeRange(
                      start: _filterStartDate ?? DateTime(now.year, now.month, 1),
                      end: _filterEndDate ?? now,
                    );
                    final picked = await showDateRangePicker(
                      context: context,
                      initialDateRange: initialRange,
                      firstDate: DateTime(2020),
                      lastDate: DateTime(now.year + 2),
                      builder: (context, child) {
                        return Theme(
                          data: Theme.of(context).copyWith(
                            colorScheme: ColorScheme.light(
                              primary: primaryGreen,
                              onPrimary: Colors.white,
                              onSurface: Colors.black87,
                            ),
                          ),
                          child: child!,
                        );
                      },
                    );
                    if (picked != null) {
                      setState(() {
                        _selectedPeriodFilter = 'custom';
                        _filterStartDate = DateTime(picked.start.year, picked.start.month, picked.start.day, 0, 0, 0);
                        _filterEndDate = DateTime(picked.end.year, picked.end.month, picked.end.day, 23, 59, 59);
                      });
                    }
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip({required String label, required bool selected, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: selected ? primaryGreen : Colors.grey.shade100,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: selected ? primaryGreen : Colors.grey.shade300),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: selected ? FontWeight.bold : FontWeight.normal,
            color: selected ? Colors.white : Colors.black87,
          ),
        ),
      ),
    );
  }

  Widget _buildHusnSautSummaryCards(bool isUrdu) {
    int manzilRecited = 0;
    int manzilNaghas = 0;
    int totalMashq = 0;
    int tilawatDays = 0;

    final husnRecords = _filteredHusnSautRecords;
    for (var r in husnRecords) {
      if (r['is_dummy'] == 1) continue;
      final mSabaq = (r['manzil_sabaq'] ?? '').toString().trim();
      final mGrade = (r['manzil_grade'] ?? '').toString().trim();
      if (mGrade == 'ناغہ' || mGrade == 'Absent' || mGrade == 'نہیں سنائی' || mSabaq == '-' || mSabaq.isEmpty) {
        if (mGrade == 'ناغہ' || mGrade == 'Absent') manzilNaghas++;
      } else {
        manzilRecited++;
      }

      for (int i = 1; i <= 4; i++) {
        final m = (r['mashq_${i}_sabaq'] ?? '').toString().trim();
        if (m.isNotEmpty && m != '-') totalMashq++;
      }

      final tQty = (r['tilawat_qty'] ?? '').toString().trim();
      if (tQty.isNotEmpty && tQty != '-') tilawatDays++;
    }

    final aRecords = _filteredAttendanceRecords;
    final Set<String> presentDates = {};
    final Set<String> absentDates = {};
    for (var a in aRecords) {
      final date = PerformanceCalculator.normalizeDate(a['date']?.toString() ?? '');
      if (date.isEmpty || date == '1970-01-01') continue;
      final st = (a['status'] ?? '').toString().trim().toUpperCase();
      if (st == 'ABSENT' || st == 'A' || st.contains('غیر حاضر')) {
        absentDates.add(date);
      } else if (st == 'PRESENT' || st == 'P' || st.contains('حاضر')) {
        presentDates.add(date);
      }
    }
    for (var r in husnRecords) {
      final date = PerformanceCalculator.normalizeDate(r['date']?.toString() ?? '');
      if (date.isNotEmpty && !absentDates.contains(date) && r['is_dummy'] != 1) {
        presentDates.add(date);
      }
    }
    final presentDays = presentDates.length;
    final absentDays = absentDates.length;

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'منزل سمری' : 'Manzil Summary',
                icon: Icons.auto_stories,
                primaryColor: const Color(0xFF6A1B9A),
                stat1Label: isUrdu ? 'سنائی گئی منزل' : 'Recited Days',
                stat1Value: isUrdu ? '$manzilRecited دن' : '$manzilRecited d',
                stat2Label: isUrdu ? 'ناغے' : 'Naghas',
                stat2Value: manzilNaghas.toString(),
                isStat2Alert: manzilNaghas > 0,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'مشق سمری' : 'Mashq Summary',
                icon: Icons.record_voice_over,
                primaryColor: Colors.deepPurple,
                stat1Label: isUrdu ? 'کل مشقیں' : 'Total Sessions',
                stat1Value: isUrdu ? '$totalMashq مشق' : '$totalMashq',
                stat2Label: isUrdu ? 'معیار' : 'Quality',
                stat2Value: isUrdu ? 'جاری' : 'Active',
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'تلاوت سمری' : 'Tilawat Summary',
                icon: Icons.menu_book,
                primaryColor: Colors.teal.shade800,
                stat1Label: isUrdu ? 'ایام تلاوت' : 'Tilawat Days',
                stat1Value: isUrdu ? '$tilawatDays دن' : '$tilawatDays d',
                stat2Label: isUrdu ? 'کیفیت' : 'Status',
                stat2Value: isUrdu ? 'حسب شیڈول' : 'On Schedule',
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'حاضری سمری' : 'Attendance',
                icon: Icons.how_to_reg,
                primaryColor: Colors.teal.shade700,
                stat1Label: isUrdu ? 'کل حاضری' : 'Presents',
                stat1Value: isUrdu ? '$presentDays دن' : '$presentDays d',
                stat2Label: isUrdu ? 'غیر حاضری' : 'Absents',
                stat2Value: isUrdu ? '$absentDays دن' : '$absentDays d',
                isStat2Alert: absentDays > 0,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildSummaryCardsSection(bool isUrdu) {
    if (_isHusnSautStudent) {
      return _buildHusnSautSummaryCards(isUrdu);
    }
    final bool isManzilGardan = (_student?['hifz_section'] == 'manzil_gardan');
    int totalSabaqLines = 0;
    int sabaqNaghas = 0;
    int sabqiDaysRecited = 0;
    int sabqiNaghas = 0;
    double manzilParasRecited = 0.0;
    int manzilNaghas = 0;

    final hRecords = _filteredHifzRecords;
    final aRecords = _filteredAttendanceRecords;

    for (var r in hRecords) {
      final attStatus = (r['att_status'] ?? '').toString().trim();
      // If student was Absent or Leave on this day, it's counted in attendance, NOT as a lesson nagha!
      if (attStatus == 'غیر حاضر' || attStatus == 'Absent' || attStatus == 'A' ||
          attStatus == 'رخصت' || attStatus == 'Leave' || attStatus == 'L') {
        continue;
      }

      final sGrade = (r['sabaq_p'] ?? r['sabaq_grade'] ?? '').toString().trim();
      final sLines = int.tryParse((r['sabaq_lines'] ?? '0').toString().trim()) ?? 0;
      final sPara = (r['sabaq_para'] ?? '').toString().trim();
      bool isSabaqMissed = (sGrade == 'ناغہ' || sGrade == 'Absent' || sGrade == 'نہیں سنایا' || (sLines == 0 && sPara.isEmpty));
      if (isSabaqMissed) {
        if (!isManzilGardan) sabaqNaghas++;
      } else {
        totalSabaqLines += sLines;
      }

      final sqGrade = (r['sabqi_p'] ?? r['sabqi_grade'] ?? '').toString().trim();
      final sqPara = (r['sabqi_para'] ?? '').toString().trim();
      final sqQty = (r['sabqi_qty'] ?? '').toString().trim();
      bool isSabqiMissed = (sqGrade == 'ناغہ' || sqGrade == 'Absent' || sqGrade == 'نہیں سنائی' || (sqPara.isEmpty && sqQty.isEmpty));
      if (isSabqiMissed) {
        if (!isManzilGardan) sabqiNaghas++;
      } else {
        sabqiDaysRecited++;
      }

      final mGrade = (r['manzil_p'] ?? r['manzil_grade'] ?? '').toString().trim();
      final mDetails = (r['manzil_details'] ?? r['manzil_parah'] ?? '').toString().trim();
      final mQty = (r['manzil_total_qty'] ?? r['manzil_amount'] ?? '').toString().trim();
      bool isManzilMissed = (mGrade == 'ناغہ' || mGrade == 'Absent' || mGrade == 'نہیں سنائی' || (mDetails.isEmpty && mQty.isEmpty));
      if (isManzilMissed) {
        manzilNaghas++;
      } else {
        double paras = _parseManzilAmountToParas(mQty);
        if (paras == 0.0 && mDetails.isNotEmpty) paras = 1.0;
        manzilParasRecited += paras;
      }
    }

    final Set<String> presentDates = {};
    final Set<String> absentDates = {};
    final Set<String> leaveDates = {};

    for (var a in aRecords) {
      final date = PerformanceCalculator.normalizeDate(a['date']?.toString() ?? '');
      if (date.isEmpty || date == '1970-01-01') continue;
      final st = (a['status'] ?? '').toString().trim().toUpperCase();
      if (st == 'ABSENT' || st == 'A' || st.contains('غیر حاضر') || st.contains('غائب')) {
        absentDates.add(date);
      } else if (st == 'LEAVE' || st == 'L' || st.contains('رخصت')) {
        leaveDates.add(date);
      } else if (st == 'PRESENT' || st == 'P' || st.contains('حاضر')) {
        presentDates.add(date);
      }
    }

    for (var h in hRecords) {
      final date = PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '');
      final attSt = (h['att_status'] ?? '').toString().trim();
      if (attSt == 'غیر حاضر') {
        absentDates.add(date);
      } else if (attSt == 'رخصت') {
        leaveDates.add(date);
      } else if (date.isNotEmpty && !absentDates.contains(date)) {
        presentDates.add(date);
      }
    }

    for (var d in absentDates) {
      presentDates.remove(d);
    }
    for (var d in leaveDates) {
      presentDates.remove(d);
    }

    int presentDays = presentDates.length;
    int absentDays = absentDates.length;

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'سبق کی سمری' : 'Sabaq Summary',
                icon: Icons.menu_book,
                primaryColor: Colors.green.shade700,
                stat1Label: isUrdu ? 'کل لائنیں' : 'Total Lines',
                stat1Value: totalSabaqLines.toString(),
                stat2Label: isManzilGardan ? (isUrdu ? 'حیثیت' : 'Status') : (isUrdu ? 'ناغے' : 'Naghas'),
                stat2Value: isManzilGardan ? (isUrdu ? 'استثناء' : 'Exempt') : sabaqNaghas.toString(),
                isStat2Alert: !isManzilGardan && sabaqNaghas > 0,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'سبقی کی سمری' : 'Sabqi Summary',
                icon: Icons.book,
                primaryColor: Colors.blue.shade700,
                stat1Label: isUrdu ? 'سنائے گئے ایام' : 'Days Recited',
                stat1Value: isUrdu ? '$sabqiDaysRecited دن' : '$sabqiDaysRecited d',
                stat2Label: isManzilGardan ? (isUrdu ? 'حیثیت' : 'Status') : (isUrdu ? 'ناغے' : 'Naghas'),
                stat2Value: isManzilGardan ? (isUrdu ? 'استثناء' : 'Exempt') : sabqiNaghas.toString(),
                isStat2Alert: !isManzilGardan && sabqiNaghas > 0,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'منزل کی سمری' : 'Manzil Summary',
                icon: Icons.auto_stories,
                primaryColor: Colors.amber.shade800,
                stat1Label: isUrdu ? 'کل پارے' : 'Total Paras',
                stat1Value: isUrdu ? '${manzilParasRecited.toStringAsFixed(1)} پارے' : '${manzilParasRecited.toStringAsFixed(1)} p',
                stat2Label: isUrdu ? 'ناغے' : 'Naghas',
                stat2Value: manzilNaghas.toString(),
                isStat2Alert: manzilNaghas > 0,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _buildMetricCard(
                title: isUrdu ? 'حاضری سمری' : 'Attendance',
                icon: Icons.how_to_reg,
                primaryColor: Colors.teal.shade700,
                stat1Label: isUrdu ? 'کل حاضری' : 'Presents',
                stat1Value: isUrdu ? '$presentDays دن' : '$presentDays d',
                stat2Label: isUrdu ? 'غیر حاضری' : 'Absents',
                stat2Value: isUrdu ? '$absentDays دن' : '$absentDays d',
                isStat2Alert: absentDays > 0,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildMetricCard({
    required String title,
    required IconData icon,
    required Color primaryColor,
    required String stat1Label,
    required String stat1Value,
    required String stat2Label,
    required String stat2Value,
    bool isStat2Alert = false,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border(top: BorderSide(color: primaryColor, width: 3.5)),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4, offset: Offset(0, 2))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 16, color: primaryColor),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: primaryColor),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(stat1Value, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.black87)),
                  Text(stat1Label, style: TextStyle(fontSize: 9.5, color: Colors.grey.shade600)),
                ],
              ),
              Container(width: 1, height: 26, color: Colors.grey.shade200),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    stat2Value,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.bold,
                      color: isStat2Alert ? Colors.red.shade700 : Colors.green.shade700,
                    ),
                  ),
                  Text(stat2Label, style: TextStyle(fontSize: 9.5, color: Colors.grey.shade600)),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildPerformanceSection(bool isUrdu) {
    final aRecords = _filteredAttendanceRecords;
    final hRecords = _filteredHifzRecords;

    final Set<String> presentDates = {};
    final Set<String> absentDates = {};
    final Set<String> leaveDates = {};

    for (var a in aRecords) {
      final date = PerformanceCalculator.normalizeDate(a['date']?.toString() ?? '');
      if (date.isEmpty || date == '1970-01-01') continue;
      final st = (a['status'] ?? '').toString().trim().toUpperCase();
      if (st == 'ABSENT' || st == 'A' || st.contains('غیر حاضر') || st.contains('غائب')) {
        absentDates.add(date);
      } else if (st == 'LEAVE' || st == 'L' || st.contains('رخصت')) {
        leaveDates.add(date);
      } else if (st == 'PRESENT' || st == 'P' || st.contains('حاضر')) {
        presentDates.add(date);
      }
    }

    for (var h in hRecords) {
      final date = PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '');
      final attSt = (h['att_status'] ?? '').toString().trim();
      if (attSt == 'غیر حاضر') {
        absentDates.add(date);
      } else if (attSt == 'رخصت') {
        leaveDates.add(date);
      } else if (date.isNotEmpty && !absentDates.contains(date)) {
        presentDates.add(date);
      }
    }

    for (var d in absentDates) {
      presentDates.remove(d);
    }
    for (var d in leaveDates) {
      presentDates.remove(d);
    }

    int totalAttDays = presentDates.length + absentDates.length + leaveDates.length;
    double att = totalAttDays > 0 ? (presentDates.length / totalAttDays * 100) : (_stats['attendancePerc'] as num? ?? 0).toDouble();

    final bool isManzilGardan = (_student?['hifz_section'] == 'manzil_gardan');
    double totalPoints = 0;
    int count = 0;
    for (var h in hRecords) {
      final attSt = (h['att_status'] ?? '').toString();
      if (attSt == 'غیر حاضر' || attSt == 'رخصت') continue;
      count++;
      if (isManzilGardan) {
        totalPoints += PerformanceCalculator.getGradePoints(h['manzil_grade'], 100);
      } else {
        totalPoints += PerformanceCalculator.getGradePoints(h['sabaq_grade'], 30);
        totalPoints += PerformanceCalculator.getGradePoints(h['sabqi_grade'], 30);
        totalPoints += PerformanceCalculator.getGradePoints(h['manzil_grade'], 40);
      }
    }
    double acad = count > 0 ? (totalPoints / count) : (_stats['academicScore'] as num? ?? 0).toDouble();

    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Text(isUrdu ? '📊 کارکردگی اور گراف' : '📊 Performance & Graphs',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          ),
          const Divider(),
          const SizedBox(height: 5),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildGauge(isUrdu ? 'حاضری' : 'Attendance', att, Colors.blue),
              _buildGauge(isUrdu ? 'تعلیمی' : 'Academic', acad, Colors.green),
            ],
          ),
          const SizedBox(height: 20),
          Text(isUrdu ? '📈 حفظ / سبق اسکور ٹرینڈ (آخری 7 ایام)' : '📈 Sabaq Score Trend (Last 7 Days)',
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.black87)),
          const SizedBox(height: 10),
          _buildSimpleBarChart(),
          const SizedBox(height: 20),
          Text(isUrdu ? '📅 ماہانہ حاضری فیصد ٹرینڈ' : '📅 Monthly Attendance % Trend',
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.black87)),
          const SizedBox(height: 10),
          SizedBox(height: 85, child: _buildMonthlyAttendanceChart(isUrdu)),
          const SizedBox(height: 20),
          Text(isUrdu ? '📝 آخری 5 امتحانی نتائج (%)' : '📝 Recent Exam Results (%)',
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.black87)),
          const SizedBox(height: 10),
          SizedBox(height: 85, child: _buildExamResultsBarChart(isUrdu)),
        ],
      ),
    );
  }

  Widget _buildMonthlyAttendanceChart(bool isUrdu) {
    if (_attendanceRecords.isEmpty) {
      return Center(
        child: Text(isUrdu ? 'حاضری کا ڈیٹا دستیاب نہیں' : 'No attendance data',
            style: const TextStyle(fontSize: 11, color: Colors.grey)),
      );
    }

    final Map<String, List<Map<String, dynamic>>> monthlyGroups = {};
    for (var r in _attendanceRecords) {
      final dateStr = r['date']?.toString() ?? '';
      if (dateStr.length >= 7) {
        final ym = dateStr.substring(0, 7);
        monthlyGroups.putIfAbsent(ym, () => []).add(r);
      }
    }

    final sortedKeys = monthlyGroups.keys.toList()..sort();
    final recentKeys = sortedKeys.reversed.take(5).toList().reversed.toList();

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: recentKeys.map((ym) {
        final records = monthlyGroups[ym]!;
        int presentCount = 0;
        for (var rec in records) {
          final s1 =
              (rec['status_1'] ?? rec['status'] ?? '').toString().toUpperCase();
          if (s1 == 'P' || s1 == 'PRESENT' || s1 == 'حاضر') presentCount++;
        }
        final double perc = records.isNotEmpty
            ? ((presentCount / records.length) * 100).clamp(0, 100)
            : 0;
        String monthName = ym;
        try {
          monthName =
              intl.DateFormat('MMM').format(DateTime.parse('$ym-01'));
        } catch (_) {}

        return Column(
          mainAxisAlignment: MainAxisAlignment.end,
          children: [
            Text('${perc.toInt()}%',
                style: const TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: Colors.blue)),
            const SizedBox(height: 4),
            Container(
              width: 26,
              height: (perc * 0.55).clamp(6, 55),
              decoration: BoxDecoration(
                color: perc >= 80
                    ? Colors.blue.shade600
                    : (perc >= 60 ? Colors.blue.shade300 : Colors.orange),
                borderRadius:
                    const BorderRadius.vertical(top: Radius.circular(4)),
              ),
            ),
            const SizedBox(height: 4),
            Text(monthName,
                style: const TextStyle(fontSize: 10, color: Colors.black87)),
          ],
        );
      }).toList(),
    );
  }

  Widget _buildExamResultsBarChart(bool isUrdu) {
    if (_examResults.isEmpty) {
      return Center(
        child: Text(isUrdu ? 'امتحانی نتائج دستیاب نہیں' : 'No exam results',
            style: const TextStyle(fontSize: 11, color: Colors.grey)),
      );
    }

    final recentExams = _examResults.take(5).toList();

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: recentExams.map((ex) {
        final total =
            double.tryParse(ex['total_marks']?.toString() ?? '100') ?? 100;
        final obt =
            double.tryParse(ex['obtained_marks']?.toString() ?? '0') ?? 0;
        final perc =
            total > 0 ? ((obt / total) * 100).clamp(0, 100) : 0.0;
        final name =
            (ex['exam_name'] ?? ex['subject'] ?? 'Exam').toString();
        final shortName = name.length > 6 ? name.substring(0, 6) : name;

        Color barColor = perc >= 80
            ? Colors.green
            : (perc >= 60
                ? Colors.blue
                : (perc >= 40 ? Colors.orange : Colors.red));

        return Column(
          mainAxisAlignment: MainAxisAlignment.end,
          children: [
            Text('${perc.toInt()}%',
                style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: barColor)),
            const SizedBox(height: 4),
            Container(
              width: 26,
              height: (perc * 0.55).clamp(6, 55),
              decoration: BoxDecoration(
                color: barColor,
                borderRadius:
                    const BorderRadius.vertical(top: Radius.circular(4)),
              ),
            ),
            const SizedBox(height: 4),
            Text(shortName,
                style: const TextStyle(fontSize: 9, color: Colors.black87)),
          ],
        );
      }).toList(),
    );
  }

  Widget _buildGauge(String label, double value, Color color) {
    return Column(
      children: [
        Stack(
          alignment: Alignment.center,
          children: [
            SizedBox(
              width: 70,
              height: 70,
              child: CircularProgressIndicator(
                value: value / 100,
                strokeWidth: 8,
                backgroundColor: color.withOpacity(0.1),
                valueColor: AlwaysStoppedAnimation<Color>(color),
              ),
            ),
            Text('${value.toInt()}%',
                style: TextStyle(fontWeight: FontWeight.bold, color: color)),
          ],
        ),
        const SizedBox(height: 5),
        Text(label, style: const TextStyle(fontSize: 12)),
      ],
    );
  }

  Widget _buildSimpleBarChart() {
    final bool isManzilGardan = (_student?['hifz_section'] == 'manzil_gardan');
    List<double> scores = _hifzRecords.reversed
        .take(7)
        .map((r) {
          if (isManzilGardan) {
            return PerformanceCalculator.getGradePoints(r['manzil_grade'], 100);
          }
          double s =
              (PerformanceCalculator.getGradePoints(r['sabaq_grade'], 30) +
                  PerformanceCalculator.getGradePoints(r['sabqi_grade'], 30) +
                  PerformanceCalculator.getGradePoints(r['manzil_grade'], 40));
          return s;
        })
        .toList()
        .reversed
        .toList();

    if (scores.isEmpty) {
      return const Center(
        child: Text('ڈیٹا دستیاب نہیں',
            style: TextStyle(fontSize: 10, color: Colors.grey)),
      );
    }

    return SizedBox(
      height: 80,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: scores.map((s) {
          return Column(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              Text('${s.toInt()}',
                  style: TextStyle(
                      fontSize: 9,
                      fontWeight: FontWeight.bold,
                      color: primaryGreen)),
              const SizedBox(height: 2),
              Container(
                width: 25,
                height: (s * 0.55).clamp(5, 55),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                      colors: [primaryGreen.withOpacity(0.5), primaryGreen],
                      begin: Alignment.bottomCenter,
                      end: Alignment.topCenter),
                  borderRadius:
                      const BorderRadius.vertical(top: Radius.circular(5)),
                ),
              ),
            ],
          );
        }).toList(),
      ),
    );
  }

  Widget _buildAdviceCard(bool isUrdu) {
    if (_aiAdvice.isEmpty && !_isAiLoading) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [primaryGreen, Colors.green.shade900]),
        borderRadius: BorderRadius.circular(15),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.lightbulb, color: Colors.white, size: 18),
            const SizedBox(width: 8),
            Text(isUrdu ? 'مشورہ' : 'Advice',
                style: const TextStyle(
                    color: Colors.white, fontWeight: FontWeight.bold))
          ]),
          const Divider(color: Colors.white24),
          _isAiLoading
              ? const Center(
                  child: CircularProgressIndicator(color: Colors.white))
              : Text(_aiAdvice,
                  style: const TextStyle(color: Colors.white, height: 1.8)),
        ],
      ),
    );
  }

  Widget _buildAcademicRegister(bool isUrdu) {
    final realReports = _filteredAcademicReports.where((r) => r['is_dummy'] != 1).toList();
    if (realReports.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.menu_book, color: Color(0xFF284D3D), size: 20),
              const SizedBox(width: 8),
              Text(isUrdu ? 'تفصیلی تعلیمی رپورٹ' : 'Detailed Academic Report',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ],
          ),
          const Divider(),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columnSpacing: 14,
              headingRowColor:
                  WidgetStateProperty.all(primaryGreen.withOpacity(0.05)),
              columns: [
                DataColumn(label: Text(isUrdu ? 'تاریخ' : 'Date')),
                DataColumn(label: Text(isUrdu ? 'کلاس' : 'Class')),
                DataColumn(label: Text(isUrdu ? 'مضمون' : 'Subject')),
                DataColumn(
                    label: Text(isUrdu ? 'سبق کی تفصیل' : 'Lesson Details')),
                DataColumn(label: Text(isUrdu ? 'کیفیت' : 'Grade')),
                DataColumn(label: Text(isUrdu ? 'ریمارکس' : 'Remarks')),
              ],
              rows: realReports.map((r) {
                final dateText = (r['date'] ?? '').toString();
                final classText =
                    (r['class_name'] ?? r['class'] ?? '-').toString();
                final subject = (r['subject'] ?? '-').toString();
                final details = (r['lesson_details'] ?? '-').toString();
                final grade = (r['grade'] ?? '-').toString();
                final remarks = (r['remarks'] ?? '-').toString();
                return DataRow(cells: [
                  DataCell(Text(dateText)),
                  DataCell(Text(classText)),
                  DataCell(SizedBox(
                    width: 120,
                    child: Text(subject,
                        softWrap: true,
                        style: const TextStyle(fontWeight: FontWeight.bold)),
                  )),
                  DataCell(SizedBox(
                    width: 240,
                    child: Text(details,
                        softWrap: true,
                        style: const TextStyle(fontSize: 11, height: 1.45)),
                  )),
                  DataCell(Text(grade,
                      style: TextStyle(
                          color: _gradeColor(grade),
                          fontWeight: FontWeight.bold))),
                  DataCell(SizedBox(
                    width: 190,
                    child: Text(remarks,
                        softWrap: true,
                        style: const TextStyle(fontSize: 11, height: 1.45)),
                  )),
                ]);
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAttendanceHistoryTable(bool isUrdu) {
    final attList = _filteredAttendanceRecords.where((r) => r['is_dummy'] != 1).toList();
    if (attList.isEmpty) return const SizedBox.shrink();
    
    // Group attendance by date
    Map<String, Map<String, dynamic>> grouped = {};
    for (var a in attList) {
      final date = a['date']?.toString() ?? '';
      if (date.isEmpty) continue;
      if (!grouped.containsKey(date)) {
        grouped[date] = {
          'status_1': '-', 'delay_1': '-',
          'status_2': '-', 'delay_2': '-',
          'status_3': '-', 'delay_3': '-',
          'status_4': '-', 'delay_4': '-',
        };
      }
      if (a.containsKey('status_1')) grouped[date]!['status_1'] = a['status_1'];
      if (a.containsKey('delay_1')) grouped[date]!['delay_1'] = a['delay_1'];
      if (a.containsKey('status_2')) grouped[date]!['status_2'] = a['status_2'];
      if (a.containsKey('delay_2')) grouped[date]!['delay_2'] = a['delay_2'];
      if (a.containsKey('status_3')) grouped[date]!['status_3'] = a['status_3'];
      if (a.containsKey('delay_3')) grouped[date]!['delay_3'] = a['delay_3'];
      if (a.containsKey('status_4')) grouped[date]!['status_4'] = a['status_4'];
      if (a.containsKey('delay_4')) grouped[date]!['delay_4'] = a['delay_4'];
      
      // Fallback
      if (a.containsKey('status') && !a.containsKey('status_1')) {
         grouped[date]!['status_1'] = a['status'];
      }
      if (a.containsKey('delay_time') && !a.containsKey('delay_1')) {
         grouped[date]!['delay_1'] = a['delay_time'];
      }
    }

    final sortedDates = grouped.keys.toList()..sort((a, b) => b.compareTo(a));

    Widget _attCell(String status, String delay) {
      String st = status.toString().toUpperCase();
      String mark = '-';
      Color c = Colors.black;
      if (st.startsWith('P') || st.contains('حاضر')) { mark = 'ح'; c = Colors.green; }
      else if (st.startsWith('A') || st.contains('غیر')) { mark = 'غ'; c = Colors.red; }
      else if (st.startsWith('L') || st.contains('رخصت')) { mark = 'ر'; c = Colors.orange; }
      else if (st.startsWith('T') || st.contains('تاخیر')) { mark = 'ت'; c = Colors.blue; }

      String dText = (delay == '-' || delay.isEmpty || delay == 'null' || delay == '0') ? '' : ' \n$delay';
      
      return Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        alignment: Alignment.center,
        child: RichText(
          textAlign: TextAlign.center,
          text: TextSpan(
            text: mark,
            style: TextStyle(color: c, fontWeight: FontWeight.bold, fontFamily: 'JameelNooriNastaleeq', fontSize: 16),
            children: [
              if (dText.isNotEmpty) TextSpan(text: dText, style: const TextStyle(color: Colors.grey, fontSize: 10, fontFamily: 'Roboto')),
            ]
          )
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(15),
      margin: const EdgeInsets.only(bottom: 15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.date_range, color: Color(0xFF284D3D), size: 20),
              const SizedBox(width: 8),
              Text(isUrdu ? 'حاضری کی تفصیل' : 'Attendance History',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ],
          ),
          const Divider(),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Table(
              border: TableBorder.all(color: Colors.grey.shade300),
              columnWidths: const {
                0: FixedColumnWidth(90),
                1: FixedColumnWidth(60),
                2: FixedColumnWidth(60),
                3: FixedColumnWidth(60),
                4: FixedColumnWidth(60),
              },
              children: [
                TableRow(
                  decoration: BoxDecoration(color: primaryGreen.withOpacity(0.05)),
                  children: [
                    _tableHCell(isUrdu ? "تاریخ" : "Date"),
                    _tableHCell(isUrdu ? "فجر" : "Fajr"),
                    _tableHCell(isUrdu ? "ظہر" : "Dhuhr"),
                    _tableHCell(isUrdu ? "عصر" : "Asr"),
                    _tableHCell(isUrdu ? "عشاء" : "Isha"),
                  ]
                ),
                ...sortedDates.map((d) {
                  final rec = grouped[d]!;
                  return TableRow(
                    children: [
                      Container(padding: const EdgeInsets.all(8), alignment: Alignment.center, child: Text(d, style: const TextStyle(fontSize: 12))),
                      _attCell(rec['status_1'].toString(), rec['delay_1'].toString()),
                      _attCell(rec['status_2'].toString(), rec['delay_2'].toString()),
                      _attCell(rec['status_3'].toString(), rec['delay_3'].toString()),
                      _attCell(rec['status_4'].toString(), rec['delay_4'].toString()),
                    ]
                  );
                }).toList(),
              ],
            ),
          )
        ],
      ),
    );
  }

  Widget _buildDailyLog(bool isUrdu) {
    Set<String> allDates = {};
    for (var r in _hifzRecords) {
      if (r['date'] != null) allDates.add(r['date']);
    }
    for (var r in _academicReports) {
      if (r['date'] != null) allDates.add(r['date']);
    }
    for (var c in _complaints) {
      if (c['date'] != null) allDates.add(c['date']);
    }

    if (allDates.isEmpty) return const SizedBox.shrink();

    List<String> sortedDates = allDates.toList();
    // Sort descending
    sortedDates.sort((a, b) {
      DateTime? da, db;
      try {
        da = intl.DateFormat('d/M/yyyy').parse(a);
      } catch (_) {
        try {
          da = DateTime.parse(a);
        } catch (_) {}
      }
      try {
        db = intl.DateFormat('d/M/yyyy').parse(b);
      } catch (_) {
        try {
          db = DateTime.parse(b);
        } catch (_) {}
      }
      if (da != null && db != null) return db.compareTo(da);
      return b.compareTo(a);
    });

    List<DataRow> rows = [];
    for (var d in sortedDates) {
      var hifz = _hifzRecords.where((r) => r['date'] == d).toList();
      var acad = _academicReports.where((r) => r['date'] == d).toList();
      var comps = _complaints.where((c) => c['date'] == d).toList();

      String activity = '';
      if (hifz.isNotEmpty) {
        activity += (isUrdu ? "حفظ: " : "Hifz: ") +
            (hifz.first['sabaq_para'] ?? '') +
            " | " +
            (hifz.first['manzil_details'] ?? '');
      }
      if (acad.isNotEmpty) {
        if (activity.isNotEmpty) activity += "\n";
        activity += (isUrdu ? "اکیڈمک: " : "Acad: ") +
            (acad.first['subject'] ?? '') +
            " - " +
            (acad.first['grade'] ?? '');
      }
      if (activity.isEmpty) activity = '-';

      String compStr = comps.isNotEmpty
          ? comps.map((c) => c['complaint'] ?? '').join('\n')
          : '-';

      rows.add(DataRow(cells: [
        DataCell(Text(d)),
        DataCell(Text(activity)),
        DataCell(Text(compStr,
            style: TextStyle(
                color: comps.isNotEmpty ? Colors.red : Colors.black))),
      ]));
    }

    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
              isUrdu
                  ? 'یومیہ کارکردگی و شکایات'
                  : 'Daily Performance & Complaints',
              style: const TextStyle(fontWeight: FontWeight.bold)),
          const Divider(),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columnSpacing: 15,
              headingRowColor:
                  WidgetStateProperty.all(primaryGreen.withOpacity(0.05)),
              columns: [
                DataColumn(label: Text(isUrdu ? 'تاریخ' : 'Date')),
                DataColumn(label: Text(isUrdu ? 'کارکردگی' : 'Activity')),
                DataColumn(label: Text(isUrdu ? 'شکایات' : 'Complaints')),
              ],
              rows: rows.take(20).toList(),
            ),
          )
        ],
      ),
    );
  }

  Widget _buildComplaintsCard(bool isUrdu) {
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.report_problem, color: Colors.red),
            const SizedBox(width: 8),
            Text(isUrdu ? 'شکایات' : 'Complaints',
                style: const TextStyle(
                    fontWeight: FontWeight.bold, color: Colors.red))
          ]),
          const Divider(),
          if (_complaints.isEmpty)
            Text(isUrdu ? 'کوئی شکایت نہیں' : 'No complaints'),
          ..._complaints.map((c) => ListTile(
                dense: true,
                title: Text(c['complaint'] ?? '',
                    style: const TextStyle(fontSize: 13)),
                subtitle:
                    Text(c['date'] ?? '', style: const TextStyle(fontSize: 10)),
                leading: const Icon(Icons.arrow_right, color: Colors.red),
              )),
        ],
      ),
    );
  }

  Widget _buildExamsCard(bool isUrdu) {
    if (_examResults.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.assignment, color: Colors.blue),
            const SizedBox(width: 8),
            Text(isUrdu ? 'امتحانی نتائج' : 'Exam Results',
                style: const TextStyle(
                    fontWeight: FontWeight.bold, color: Colors.blue))
          ]),
          const Divider(),
          ..._examResults.map((r) {
            double marks =
                double.tryParse(r['marks_obtained']?.toString() ?? '0') ?? 0;
            double total =
                double.tryParse(r['total_marks']?.toString() ?? '100') ?? 100;
            return ListTile(
              title: Text(r['exam_name'] ?? 'Exam',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
              subtitle: Text('${isUrdu ? 'نمبر' : 'Marks'}: $marks/$total'),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                        color: _gradeColor(r['grade']).withOpacity(0.1),
                        borderRadius: BorderRadius.circular(10)),
                    child: Text(r['grade'] ?? '-',
                        style: TextStyle(
                            color: _gradeColor(r['grade']),
                            fontWeight: FontWeight.bold)),
                  ),
                  const SizedBox(width: 8),
                  IconButton(
                    icon: Icon(Icons.remove_red_eye, color: primaryGreen),
                    onPressed: () {
                      Map<String, dynamic> rWithStudent = Map.from(r);
                      rWithStudent['student_name'] = _student?['name'];
                      rWithStudent['father_name'] = _student?['father_name'];
                      rWithStudent['class_name'] = _student?['class_name'];
                      rWithStudent['photo'] = _student?['photo'];
                      rWithStudent['roll_number'] = _student?['roll_number'];
                      rWithStudent['student_id'] = _student?['id'];
                      _viewResultCard(rWithStudent);
                    },
                    tooltip: isUrdu ? 'رزلٹ کارڈ دیکھیں' : 'View Result Card',
                  )
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  String cleanUrduString(String? text) {
    if (text == null) return '-';
    return text
        .replaceAll('(3/4)', '')
        .replaceAll('(1/2)', '')
        .replaceAll('(1/4)', '')
        .replaceAll('(1)', '')
        .replaceAll('((', '(')
        .replaceAll('))', ')')
        .replaceAll('پارہ ', '')
        .trim();
  }

  String translateStatusClean(String? status) {
    if (status == null) return '-';
    if (status == 'Excellent') return 'ممتاز';
    if (status == 'Good') return 'بہتر';
    if (status == 'Average') return 'معمولی';
    if (status == 'Weak') return 'کمزور';
    return status;
  }

  String translateQtyClean(String? qty) {
    if (qty == null) return '-';
    if (qty == 'Full') return 'مکمل';
    if (qty == 'First Half') return 'نصف اول';
    if (qty == 'Second Half') return 'نصف آخر';
    if (qty == 'Quarter') return 'پاؤ';
    return qty;
  }

  Color getStatusColor(String? status) {
    if (status == null) return Colors.black;
    if (status == 'Excellent' || status == 'ممتاز' || status == 'بہترین') return Colors.green.shade700;
    if (status == 'Good' || status == 'بہتر') return Colors.blue.shade700;
    if (status == 'Average' || status == 'معمولی') return Colors.orange.shade700;
    if (status == 'Weak' || status == 'کمزور') return Colors.red.shade700;
    if (status == 'Absent' || status == 'ناغہ') return Colors.red.shade900;
    return Colors.black;
  }

  Widget _cell(String text, double width,
      {bool isHeader = false,
      Color? bgColor,
      Color? textColor,
      bool bold = false,
      double fontSize = 11,
      Widget? child}) {
    return Container(
      width: width,
      decoration: BoxDecoration(
        color: bgColor ?? (isHeader ? Colors.grey.shade200 : Colors.white),
        border: Border.all(color: Colors.grey.shade300, width: 0.5),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      alignment: Alignment.center,
      child: child ??
          Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: isHeader ? 12 : fontSize,
              fontWeight: (isHeader || bold) ? FontWeight.bold : FontWeight.normal,
              color: textColor ?? (isHeader ? Colors.black87 : Colors.black87),
              fontFamily: isHeader ? 'JameelNooriNastaleeq' : null,
            ),
          ),
    );
  }

  Widget _gradeWithTimeCell(String grade, String? time, double width, {Color? textColor}) {
    if (time == null || time.isEmpty || time == '-' || time == 'null') {
      return _cell(grade, width, textColor: textColor);
    }
    String formattedTime = time;
    try {
      final parts = time.split(':');
      if (parts.length >= 2) {
        int h = int.parse(parts[0]);
        int m = int.parse(parts[1]);
        String ampm = h >= 12 ? "PM" : "AM";
        int h12 = h > 12 ? h - 12 : (h == 0 ? 12 : h);
        formattedTime = "${h12.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')} $ampm";
      }
    } catch (_) {}

    return _cell(
      "",
      width,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(grade, style: TextStyle(color: textColor ?? Colors.black, fontSize: 11)),
          const SizedBox(height: 2),
          Text(formattedTime, style: const TextStyle(color: Colors.black54, fontSize: 8), textDirection: TextDirection.ltr),
        ],
      ),
    );
  }

  Widget _buildTableHeader(bool isUrdu) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(isUrdu ? "تاریخ" : "Date", 80, isHeader: true),
          Column(
            children: [
              _cell(isUrdu ? "سبق (نیا)" : "Sabaq (New)", 175,
                  isHeader: true,
                  bgColor: Colors.green.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell("پارہ", 60, isHeader: true),
                  _cell("لائنیں", 60, isHeader: true),
                  _cell("کیفیت", 55, isHeader: true),
                ],
              )
            ],
          ),
          Column(
            children: [
              _cell(
                  isUrdu ? "سبقی (پچھلا سبق)" : "Sabqi (Previous)",
                  305,
                  isHeader: true,
                  bgColor: Colors.blue.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell("تفصیل", 100, isHeader: true),
                  _cell("مقدار", 60, isHeader: true),
                  _cell("کیفیت", 55, isHeader: true),
                  _cell("سننے والا", 90, isHeader: true),
                ],
              )
            ],
          ),
          Column(
            children: [
              _cell(isUrdu ? "منزل (پختہ)" : "Manzil", 355,
                  isHeader: true,
                  bgColor: Colors.orange.withOpacity(0.8),
                  textColor: Colors.black),
              Row(
                children: [
                  _cell("تفصیل", 150, isHeader: true),
                  _cell("مقدار", 60, isHeader: true),
                  _cell("کیفیت", 55, isHeader: true),
                  _cell("سننے والا", 90, isHeader: true),
                ],
              )
            ],
          ),
          _cell(isUrdu ? "شکایات" : "Complaints", 150, isHeader: true, bgColor: Colors.red.withOpacity(0.8), textColor: Colors.white),
        ],
      ),
    );
  }

  Widget _buildDataRow(Map<String, dynamic> h) {
    String attStatus = (h['att_status'] ?? '').toString().trim();
    String sabaqStatus = (h['sabaq_status'] ?? '').toString().trim();
    bool isAbsent = attStatus == 'غیر حاضر' || sabaqStatus == 'غیر حاضر' || attStatus.toLowerCase() == 'absent' || sabaqStatus.toLowerCase() == 'absent';
    bool isLeave = attStatus == 'رخصت' || sabaqStatus == 'رخصت' || attStatus.toLowerCase() == 'leave' || sabaqStatus.toLowerCase() == 'leave';
    final bool isManzilGardan = (_student?['hifz_section'] == 'manzil_gardan');
    bool isNaagha = !isManzilGardan && (h['is_dummy'] == 1 && (sabaqStatus == 'ناغہ' || (attStatus == 'حاضر' && (h['sabaq_para'] == null || h['sabaq_para'].toString().isEmpty || h['sabaq_para'] == '-'))));

    if (isAbsent) {
      return Container(
        decoration: BoxDecoration(
          color: Colors.red.withOpacity(0.08),
          border: Border(bottom: BorderSide(color: Colors.grey.shade300, width: 0.5)),
        ),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _cell(h['date'] ?? '-', 80, bold: true, fontSize: 10),
              Container(
                width: 985,
                padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                alignment: Alignment.center,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: const [
                    Icon(Icons.cancel, color: Colors.red, size: 16),
                    SizedBox(width: 8),
                    Text(
                      '❌ غیر حاضر (Absent)',
                      style: TextStyle(
                        color: Colors.red,
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    if (isLeave) {
      return Container(
        decoration: BoxDecoration(
          color: Colors.amber.withOpacity(0.08),
          border: Border(bottom: BorderSide(color: Colors.grey.shade300, width: 0.5)),
        ),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _cell(h['date'] ?? '-', 80, bold: true, fontSize: 10),
              Container(
                width: 985,
                padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                alignment: Alignment.center,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.info_outline, color: Colors.amber.shade800, size: 16),
                    const SizedBox(width: 8),
                    Text(
                      '⚠️ رخصت (On Leave)',
                      style: TextStyle(
                        color: Colors.amber.shade900,
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    if (isNaagha) {
      return Container(
        decoration: BoxDecoration(
          color: Colors.grey.shade50,
          border: Border(bottom: BorderSide(color: Colors.grey.shade300, width: 0.5)),
        ),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _cell(h['date'] ?? '-', 80, bold: true, fontSize: 10),
              Container(
                width: 985,
                padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                alignment: Alignment.center,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.warning_amber_rounded, color: Colors.orange.shade700, size: 16),
                    const SizedBox(width: 8),
                    Text(
                      'ناغہ (سبق نہیں سنایا)',
                      style: TextStyle(
                        color: Colors.orange.shade800,
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(h['date'] ?? '-', 80, bold: true, fontSize: 10),
          _cell(cleanUrduString(h['sabaq_para']), 60),
          _cell(h['sabaq_lines'] ?? '-', 60),
          _gradeWithTimeCell(translateStatusClean(h['sabaq_grade']), h['sabaq_time']?.toString(), 55, textColor: getStatusColor(h['sabaq_grade'])),
          _cell(cleanUrduString(h['sabqi_para']), 100),
          _cell(translateQtyClean(h['sabqi_qty']), 60),
          _gradeWithTimeCell(translateStatusClean(h['sabqi_grade']), h['sabqi_time']?.toString(), 55, textColor: getStatusColor(h['sabqi_grade'])),
          _cell(
              (h['sabqi_listener']?.toString().isEmpty ?? true)
                  ? '-'
                  : h['sabqi_listener'],
              90,
              fontSize: 9),
          _cell(cleanUrduString(h['manzil_details']), 150),
          _cell(translateQtyClean(h['manzil_total_qty']), 60),
          _gradeWithTimeCell(translateStatusClean(h['manzil_grade']), h['manzil_time']?.toString(), 55, textColor: getStatusColor(h['manzil_grade'])),
          _cell(
              (h['manzil_listener']?.toString().isEmpty ?? true)
                  ? '-'
                  : h['manzil_listener'],
              90,
              fontSize: 9),
          _cell(h['daily_complaint'] ?? '-', 150, textColor: Colors.red),
        ],
      ),
    );
  }

    Widget _buildQaidaTable(bool isUrdu) {
    if (_qaidaRecords.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(15),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 10,
              offset: const Offset(0, 5))
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.menu_book, color: Color(0xFF284D3D), size: 20),
              const SizedBox(width: 8),
              Text(isUrdu ? 'قاعدہ / نماز حنفی تفصیلی رپورٹ' : 'Detailed Qaida Report',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ],
          ),
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Table(
              border: TableBorder.all(color: Colors.grey.shade300),
              columnWidths: const {
                0: FixedColumnWidth(90),
                1: FixedColumnWidth(100),
                2: FixedColumnWidth(100),
                3: FixedColumnWidth(100),
                4: FixedColumnWidth(100),
                5: FixedColumnWidth(100),
              },
              children: [
                TableRow(
                  decoration: BoxDecoration(color: Colors.grey.shade100),
                  children: [
                    _qaidaCell(isUrdu ? 'تاریخ' : 'Date', true),
                    _qaidaCell(isUrdu ? 'شعبہ' : 'Type', true),
                    _qaidaCell(isUrdu ? 'سبق 1' : 'Sabaq 1', true),
                    _qaidaCell(isUrdu ? 'سبق 2' : 'Sabaq 2', true),
                    _qaidaCell(isUrdu ? 'سبق 3' : 'Sabaq 3', true),
                    _qaidaCell(isUrdu ? 'سبق 4' : 'Sabaq 4', true),
                  ],
                ),
                ..._filteredQaidaRecords.map((r) {
                  String attStatus = (r['att_status'] ?? '').toString().trim();
                  bool isAbsent = attStatus == 'غیر حاضر' || (r['grade_1'] == 'غیر حاضر' && (r['sabaq_1']?.toString().isEmpty ?? true));
                  bool isLeave = attStatus == 'رخصت' || (r['grade_1'] == 'رخصت' && (r['sabaq_1']?.toString().isEmpty ?? true));

                  if (isAbsent) {
                    return TableRow(
                      decoration: BoxDecoration(color: Colors.red.withOpacity(0.08)),
                      children: [
                        _qaidaCell(r['date'] ?? '-', false),
                        _qaidaCell('غیر حاضر', false),
                        _qaidaCell('-', false),
                        _qaidaCell('-', false),
                        _qaidaCell('-', false),
                        _qaidaCell('❌ غیر حاضر (Absent)', false),
                      ],
                    );
                  }
                  if (isLeave) {
                    return TableRow(
                      decoration: BoxDecoration(color: Colors.amber.withOpacity(0.08)),
                      children: [
                        _qaidaCell(r['date'] ?? '-', false),
                        _qaidaCell('رخصت', false),
                        _qaidaCell('-', false),
                        _qaidaCell('-', false),
                        _qaidaCell('-', false),
                        _qaidaCell('⚠️ رخصت (On Leave)', false),
                      ],
                    );
                  }
                  return TableRow(
                    children: [
                      _qaidaCell(r['date'] ?? '-', false),
                      _qaidaCell(r['lesson_type'] ?? '-', false),
                      _qaidaCell("${r['sabaq_1'] ?? '-'} (${r['grade_1'] ?? '-'})", false),
                      _qaidaCell("${r['sabaq_2'] ?? '-'} (${r['grade_2'] ?? '-'})", false),
                      _qaidaCell("${r['sabaq_3'] ?? '-'} (${r['grade_3'] ?? '-'})", false),
                      _qaidaCell("${r['sabaq_4'] ?? '-'} (${r['grade_4'] ?? '-'})", false),
                    ],
                  );
                }),
              ],
            ),
          )
        ],
      ),
    );
  }

  Widget _qaidaCell(String text, bool isHeader) {
    return Padding(
      padding: const EdgeInsets.all(8.0),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(
            fontWeight: isHeader ? FontWeight.bold : FontWeight.normal,
            fontSize: 12),
      ),
    );
  }

  Widget _buildHusnSautTable(bool isUrdu) {
    final records = _filteredHusnSautRecords;
    if (records.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.record_voice_over, color: Color(0xFF6A1B9A), size: 20),
              const SizedBox(width: 8),
              Text(isUrdu ? 'حسن صوت تعلیمی تفصیلات' : 'Husn-e-Saut Academic Details',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ],
          ),
          const Divider(),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Container(
              decoration: BoxDecoration(
                border: Border.all(color: Colors.grey.shade300, width: 1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: DataTable(
                headingRowColor: WidgetStateProperty.all(const Color(0xFF6A1B9A).withOpacity(0.08)),
                columns: [
                  DataColumn(label: Text(isUrdu ? 'تاریخ' : 'Date', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'منزل' : 'Manzil', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'مشق 1' : 'Mashq 1', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'مشق 2' : 'Mashq 2', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'مشق 3' : 'Mashq 3', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'مشق 4' : 'Mashq 4', style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataColumn(label: Text(isUrdu ? 'تلاوت' : 'Tilawat', style: const TextStyle(fontWeight: FontWeight.bold))),
                ],
                rows: records.map((r) {
                  final date = r['date']?.toString() ?? '-';
                  final manzil = "${r['manzil_sabaq'] ?? '-'} (${r['manzil_grade'] ?? '-'})";
                  final m1 = "${r['mashq_1_sabaq'] ?? '-'} ${r['mashq_1_time'] != null && r['mashq_1_time'].toString().isNotEmpty ? '(${r['mashq_1_time']})' : ''}";
                  final m2 = "${r['mashq_2_sabaq'] ?? '-'} ${r['mashq_2_time'] != null && r['mashq_2_time'].toString().isNotEmpty ? '(${r['mashq_2_time']})' : ''}";
                  final m3 = "${r['mashq_3_sabaq'] ?? '-'} ${r['mashq_3_time'] != null && r['mashq_3_time'].toString().isNotEmpty ? '(${r['mashq_3_time']})' : ''}";
                  final m4 = "${r['mashq_4_sabaq'] ?? '-'} ${r['mashq_4_time'] != null && r['mashq_4_time'].toString().isNotEmpty ? '(${r['mashq_4_time']})' : ''}";
                  final tilawat = "${r['tilawat_qty'] ?? '-'} (${r['tilawat_parahs'] ?? '-'})";
                  return DataRow(cells: [
                    DataCell(Text(date)),
                    DataCell(Text(manzil)),
                    DataCell(Text(m1)),
                    DataCell(Text(m2)),
                    DataCell(Text(m3)),
                    DataCell(Text(m4)),
                    DataCell(Text(tilawat)),
                  ]);
                }).toList(),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildHifzTable(bool isUrdu) {
    final records = _filteredHifzRecords;
    if (records.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(15)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.menu_book, color: Color(0xFF284D3D), size: 20),
              const SizedBox(width: 8),
              Text(isUrdu ? 'حفظ / ناظرہ تفصیلات' : 'Detailed Hifz Report',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ],
          ),
          const Divider(),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Container(
              decoration: BoxDecoration(
                border: Border.all(color: Colors.grey.shade300, width: 1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                children: [
                  _buildTableHeader(isUrdu),
                  ...records.map((r) => _buildDataRow(r)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  final Color _primary = const Color(0xFF284D3D);
  final Color _gold = const Color(0xFFD4AC0D);
  final TextStyle _urdu = const TextStyle(fontFamily: 'JameelNooriNastaleeq');

  void _viewResultCard(Map<String, dynamic> r) async {
    String aiRemarks = r['remarks'] ?? "";

    if (aiRemarks.isEmpty) {
      showDialog(
          context: context,
          barrierDismissible: false,
          builder: (c) => const Center(child: CircularProgressIndicator()));
      double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
      double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;
      String prompt =
          "Generate a concise, encouraging 2-line performance remark in URDU for a student named ${r['student_name']}. Exam: ${r['exam_name']}, Marks: $obt/$tot, Grade: ${r['grade']}. Focus on praise if good, or encouragement if poor. Just Urdu text.";

      try {
        aiRemarks = await AIService.generateInsight(prompt);
      } catch (_) {
        aiRemarks =
            "ماشاء اللہ! طالب علم کی تعلیمی کارکردگی اطمینان بخش ہے۔ مزید محنت اور توجہ سے بہتر نتائج حاصل کیے جا سکتے ہیں۔";
      }
      if (mounted) Navigator.pop(context);
    }

    if (!mounted) return;
    showDialog(
      context: context,
      builder: (context) => Theme(
        data: ThemeData(fontFamily: 'JameelNooriNastaleeq'),
        child: Dialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          backgroundColor: const Color(0xFFF0F2F5),
          child: Container(
            width: 420,
            padding: const EdgeInsets.all(12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Expanded(
                  child: SingleChildScrollView(
                    scrollDirection: Axis.vertical,
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.topCenter,
                      child: _buildResultCardContent(r, aiRemarks),
                    ),
                  ),
                ),
                const Divider(height: 20, thickness: 1),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    ElevatedButton.icon(
                        onPressed: () => _shareCard(r, false),
                        icon: const Icon(Icons.save),
                        label: const Text("محفوظ کریں")),
                    ElevatedButton.icon(
                        onPressed: () => _shareCard(r, true),
                        icon: const Icon(Icons.share),
                        label: const Text("شیئر کریں"),
                        style: ElevatedButton.styleFrom(
                            backgroundColor: _primary,
                            foregroundColor: Colors.white)),
                  ],
                ),
                TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text("بند کریں",
                        style: TextStyle(fontWeight: FontWeight.bold))),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildResultCardContent(Map<String, dynamic> r, String aiRemarks) {
    double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
    double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;

    return Directionality(
      textDirection: ui.TextDirection.rtl,
      child: Screenshot(
        controller: _screenshotController,
        child: Container(
          width: 550, // Fixed width to prevent squishing
          decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: _primary, width: 6),
            boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 8)],
          ),
          padding: const EdgeInsets.all(4),
          child: Container(
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: _primary, width: 1.5),
            ),
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                // Header section
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Image.asset('assets/logo.png',
                        height: 55,
                        width: 55,
                        errorBuilder: (_, __, ___) =>
                            Icon(Icons.school, color: _primary, size: 55)),
                    Expanded(
                      child: Column(
                        children: [
                          Text("مدرسہ اشرف المدارس",
                              style: _urdu.copyWith(
                                  fontSize: 26,
                                  fontWeight: FontWeight.bold,
                                  color: _primary)),
                          const SizedBox(height: 2),
                          Text("فاروقہ، ضلع سرگودھا | فون: 0300-1234567",
                              style: _urdu.copyWith(
                                  fontSize: 11,
                                  color: _primary,
                                  fontWeight: FontWeight.bold)),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 24, vertical: 6),
                    decoration: BoxDecoration(
                        color: _primary,
                        borderRadius: BorderRadius.circular(25)),
                    child: Text("رزلٹ کارڈ (Result Card)",
                        style: _urdu.copyWith(
                            color: _gold,
                            fontSize: 15,
                            fontWeight: FontWeight.bold))),
                const SizedBox(height: 8),
                Text(
                    "${r['exam_name'] ?? ''} (${DateTime.now().toString().split(' ')[0]})",
                    style: _urdu.copyWith(
                        fontSize: 14,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87)),
                const SizedBox(height: 8),
                Divider(color: _primary, thickness: 2, height: 4),
                Divider(color: _primary, thickness: 0.8, height: 4),

                // Student Info Box
                Container(
                  margin: const EdgeInsets.symmetric(vertical: 14),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    border: Border.all(color: Colors.grey.shade400, width: 1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _cardInfoRow("نام طالب علم:", r['student_name']),
                            _cardInfoRow("ولدیت:", r['father_name']),
                            _cardInfoRow(
                                "داخلہ نمبر:",
                                r['roll_number']?.toString() ??
                                    r['student_id']?.toString() ??
                                    ""),
                            _cardInfoRow(
                                "کلاس / درجہ:", r['class_name'] ?? "حفظ"),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      _buildPhotoBox(r['photo']?.toString()),
                    ],
                  ),
                ),

                // Subjects Table
                Table(
                  border: TableBorder.all(color: _primary, width: 1),
                  columnWidths: const {
                    0: FlexColumnWidth(2.5),
                    1: FlexColumnWidth(1),
                    2: FlexColumnWidth(1),
                  },
                  children: [
                    TableRow(
                      decoration: BoxDecoration(color: _primary),
                      children: [
                        _tableHCell("تفصیل (Subjects / Details)"),
                        _tableHCell("کل نمبر"),
                        _tableHCell("حاصل کردہ"),
                      ],
                    ),
                    _tableDataRow(
                        "پختگی (Memorization / Pukhtagi)", r['pukhtagi'], 40),
                    _tableDataRow(
                        "تلفظ و تجوید (Tajweed / Talafuz)", r['tajweed'], 20),
                    _tableDataRow(
                        "خوبصورتی و لہجہ (Accent / Lahja)", r['lahja'], 20),
                    _tableDataRow(
                        "نماز و مسائل (Namaz / Masail)", r['masail'], 20),
                    TableRow(
                      decoration: const BoxDecoration(color: Color(0xFFEAEFEA)),
                      children: [
                        _tableCell("مجموعی نمبر (Total Marks)", bold: true),
                        _tableCell(tot.toStringAsFixed(0), bold: true),
                        _tableCell(obt.toStringAsFixed(0), bold: true),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Grade & Remarks Section
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: "اساتذہ کے ریمارکس / تاثرات:",
                          labelStyle: _urdu.copyWith(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: _primary),
                          border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide:
                                  BorderSide(color: _primary, width: 1)),
                          enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide:
                                  BorderSide(color: _primary, width: 1)),
                          contentPadding: const EdgeInsets.all(12),
                        ),
                        child: Text(aiRemarks,
                            style: _urdu.copyWith(
                                fontSize: 13,
                                color: Colors.black87,
                                height: 1.5),
                            textAlign: TextAlign.right),
                      ),
                    ),
                    const SizedBox(width: 10),
                    SizedBox(
                      width: 110,
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: "گریڈ (Grade)",
                          labelStyle: _urdu.copyWith(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: _primary),
                          border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide:
                                  BorderSide(color: _primary, width: 1)),
                          enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide:
                                  BorderSide(color: _primary, width: 1)),
                          contentPadding: const EdgeInsets.symmetric(
                              vertical: 10, horizontal: 8),
                        ),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(r['grade'] ?? "-",
                                style: TextStyle(
                                    fontSize: 28,
                                    fontWeight: FontWeight.bold,
                                    color: _primary,
                                    fontFamily: 'sans-serif')),
                            const SizedBox(height: 2),
                            Text(r['status'] == 'Pass' ? 'کامیاب' : 'فیل',
                                style: _urdu.copyWith(
                                    fontSize: 14,
                                    color: r['status'] == 'Pass'
                                        ? Colors.green
                                        : Colors.red,
                                    fontWeight: FontWeight.bold)),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 35),

                // Signatures Section
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _signLine("دستخط کلاس انچارج"),
                    _signLine("دستخط سرپرست / والد"),
                    _signLine("مہر و دستخط مہتمم"),
                  ],
                ),
                const SizedBox(height: 20),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                        "جاری کردہ: ${DateTime.now().toString().split(' ')[0]} | آئی ڈی: #${r['student_id'] ?? r['id'] ?? ''}",
                        style:
                            const TextStyle(fontSize: 10, color: Colors.grey)),
                    const SizedBox(),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildPhotoBox(String? photoPath) {
    Widget placeholder = const Center(
        child: Text("No\nPhoto",
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 12,
                color: Colors.grey,
                fontWeight: FontWeight.bold)));

    Widget imageWidget = placeholder;
    if (photoPath != null && photoPath.isNotEmpty) {
      if (photoPath.startsWith('http')) {
        imageWidget = Image.network(photoPath,
            fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder);
      } else if (File(photoPath).existsSync()) {
        imageWidget = Image.file(File(photoPath), fit: BoxFit.cover);
      } else {
        String baseUrl = apiUrl;
        if (baseUrl.endsWith('api.php'))
          baseUrl = baseUrl.substring(0, baseUrl.length - 7);
        if (!baseUrl.endsWith('/')) baseUrl += '/';

        String fullUrl = baseUrl;
        if (photoPath.startsWith('uploads/')) {
          fullUrl += photoPath;
        } else if (!photoPath.contains('/')) {
          fullUrl += "uploads/students/$photoPath";
        } else {
          fullUrl += photoPath;
        }
        imageWidget = Image.network(fullUrl,
            fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder);
      }
    }

    return Container(
        width: 90,
        height: 110,
        decoration: BoxDecoration(
            color: Colors.grey.shade100,
            border: Border.all(color: Colors.grey.shade400, width: 1.5),
            borderRadius: BorderRadius.circular(8)),
        child: ClipRRect(
            borderRadius: BorderRadius.circular(6), child: imageWidget));
  }

  Widget _cardInfoRow(String label, String? value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            SizedBox(
              width: 90,
              child: Text(label,
                  style: _urdu.copyWith(
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                      color: Colors.black87)),
            ),
            Expanded(
              child: Text(value ?? "",
                  style: _urdu.copyWith(
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                      color: Colors.black)),
            ),
          ],
        ),
      );

  Widget _tableHCell(String t) => Container(
      padding: const EdgeInsets.all(8),
      alignment: Alignment.center,
      child: Text(t,
          style: _urdu.copyWith(
              color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)));
  Widget _tableCell(String t, {bool bold = false}) => Container(
      padding: const EdgeInsets.all(8),
      alignment: Alignment.center,
      child: Text(t,
          style: _urdu.copyWith(
              fontSize: 12,
              fontWeight: bold ? FontWeight.bold : FontWeight.normal,
              color: bold ? Colors.black : Colors.black87)));
  TableRow _tableDataRow(String label, dynamic obt, dynamic tot) => TableRow(
        children: [
          _tableCell(label, bold: true),
          _tableCell(tot?.toString() ?? "0"),
          _tableCell(obt?.toString() ?? "0"),
        ],
      );

  Widget _signLine(String t) => Column(children: [
        Container(width: 90, height: 1.5, color: Colors.black87),
        const SizedBox(height: 6),
        Text(t,
            style: _urdu.copyWith(
                fontSize: 11,
                fontWeight: FontWeight.bold,
                color: Colors.black87))
      ]);

  Future<void> _shareCard(Map<String, dynamic> r, bool share) async {
    try {
      final imageBytes = await _screenshotController.capture(
          delay: const Duration(milliseconds: 100));
      if (imageBytes == null) return;
      final directory = await getTemporaryDirectory();
      final imagePath = '${directory.path}/result_card_${r['student_id']}.png';
      final imageFile = File(imagePath);
      await imageFile.writeAsBytes(imageBytes);

      if (share) {
        await Share.shareXFiles([XFile(imagePath)],
            text: 'Result Card: ${r['student_name']}');
      } else {
        if (mounted)
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text('Image saved to $imagePath')));
      }
    } catch (e) {
      debugPrint("Error capturing screenshot: $e");
    }
  }
}

