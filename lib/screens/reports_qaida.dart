import '../utils/performance_helper.dart';
// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../widgets/app_drawer.dart';
import 'dashboard.dart';
import 'student_report.dart';

class QaidaReportPage extends StatefulWidget {
  final String? className;
  const QaidaReportPage({super.key, this.className});

  @override
  _QaidaReportPageState createState() => _QaidaReportPageState();
}

class _QaidaReportPageState extends State<QaidaReportPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  final dbHelper = DBHelper();
  List<Map<String, dynamic>> _records = [];
  List<Map<String, dynamic>> _students = [];
  bool _isLoading = true;

  String? fromDate;
  String? toDate;
  String? selectedStudentId;
  String selectedPeriod = 'current_week';

  final _fromDateController = TextEditingController();
  final _toDateController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _updateDatesForPeriod(String period) async {
    if (period == 'custom') return;
    final settings = await dbHelper.getSettings();
    int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;
    final boundaries = PerformanceCalculator.getPeriodBoundaries(period, weekStartDay);
    setState(() {
      fromDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['start']!);
      toDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['end']!);
      _fromDateController.text = fromDate!;
      _toDateController.text = toDate!;
    });
    _loadData();
  }

  DateTime _parseRobust(String dateStr) {
    return PerformanceCalculator.parseDateRobust(dateStr);
  }

  String _normalizeClassName(dynamic value) {
    return (value?.toString() ?? '')
        .trim()
        .replaceAll('ٰ', '')
        .replaceAll('ى', 'ی')
        .replaceAll('ي', 'ی')
        .replaceAll('ك', 'ک')
        .replaceAll(RegExp(r'\s+'), '')
        .toLowerCase();
  }

  bool _isActiveStudent(Map<String, dynamic> s) {
    return PerformanceCalculator.isStudentActive(s);
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final db = await dbHelper.database;
      final settings = await dbHelper.getSettings();
      int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;

      // Load students
      final allStudents = await dbHelper.getStudentsForUser(includeInactive: true);
      List<Map<String, dynamic>> extraMaktabStudents = [];
      try {
        extraMaktabStudents = await dbHelper.getMaktabStudentsForUser(includeInactive: true);
      } catch (_) {}
      final combinedStudents = [...allStudents, ...extraMaktabStudents];

      final validStudents = <Map<String, dynamic>>[];
      final Set<String> seenIds = {};

      for (var s in combinedStudents) {
        if (!_isActiveStudent(s)) continue;

        String cName = (s['class_name']?.toString() ?? s['class']?.toString() ?? '').toLowerCase();
        String normCName = _normalizeClassName(s['class_name'] ?? s['class']);

        bool isTarget = true;
        if (widget.className != null && widget.className!.trim().isNotEmpty) {
          String normTarget = _normalizeClassName(widget.className);
          isTarget = (normCName == normTarget ||
              cName == widget.className!.toLowerCase().trim() ||
              (s['class_name']?.toString().trim() == widget.className?.trim()) ||
              (s['class']?.toString().trim() == widget.className?.trim()));
        }

        final idStr = s['id']?.toString();
        if (isTarget && idStr != null && !seenIds.contains(idStr)) {
          seenIds.add(idStr);
          validStudents.add(s);
        }
      }
      _students = validStudents;
      if (selectedStudentId != null && !seenIds.contains(selectedStudentId)) {
        selectedStudentId = null;
      }

      // Set default date range
      if (fromDate == null || toDate == null) {
        final boundaries = PerformanceCalculator.getPeriodBoundaries(selectedPeriod, weekStartDay);
        fromDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['start']!);
        toDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['end']!);
        _fromDateController.text = fromDate!;
        _toDateController.text = toDate!;
      }

      DateTime sDateRaw = _parseRobust(fromDate!);
      DateTime eDateRaw = _parseRobust(toDate!);
      DateTime sDate = DateTime(sDateRaw.year, sDateRaw.month, sDateRaw.day, 0, 0, 0);
      DateTime eDate = DateTime(eDateRaw.year, eDateRaw.month, eDateRaw.day, 23, 59, 59, 999);

      // Build student IDs filter
      List<dynamic> stdIds;
      if (selectedStudentId != null) {
        stdIds = [int.tryParse(selectedStudentId!) ?? selectedStudentId];
      } else {
        stdIds = _students.map((s) => s['id']).toList();
      }

      if (stdIds.isEmpty) {
        setState(() => _records = []);
        return;
      }

      final database = await DBHelper().database;
      final allAttRaw = await database.query('attendance');
      List<Map<String, dynamic>> extraAtt = [];
      try {
        extraAtt = await db.query('maktab_attendance');
      } catch (_) {}
      final allAttendance = [...allAttRaw, ...extraAtt];
      final qs = stdIds.map((e) => '?').join(',');
      final allRecords = await db.rawQuery(
        'SELECT q.*, s.name, s.roll_number, s.admission_number FROM qaida_records q '
        'JOIN students s ON q.student_id = s.id '
        'WHERE (q.is_deleted = 0 OR q.is_deleted IS NULL) AND q.student_id IN ($qs) '
        'ORDER BY q.date DESC, s.name ASC',
        [...stdIds],
      );

      // Filter by date range
      final filtered = allRecords.where((r) {
        try {
          DateTime fDate = _parseRobust(r['date']?.toString() ?? '');
          return !fDate.isBefore(sDate) && !fDate.isAfter(eDate);
        } catch (_) {
          return false;
        }
      }).toList();

      final studentIdSet = stdIds.map((e) => e.toString()).toSet();
      final relevantAttendance = allAttendance.where((a) {
        String sid = a['student_id']?.toString() ?? '';
        if (!studentIdSet.contains(sid)) return false;
        try {
          DateTime d = _parseRobust(a['date'] ?? '');
          return !d.isBefore(sDate) && !d.isAfter(eDate);
        } catch (_) {
          return true;
        }
      }).toList();

      var injectedFiltered = PerformanceCalculator.injectMissingAttendanceRecords(
          filtered, relevantAttendance, _students, 'qaida');

      // STRICT DE-DUPLICATION: Exactly 1 row per student per date
      Map<String, Map<String, dynamic>> uniqueCombined = {};
      for (var r in injectedFiltered) {
        String sid = r['student_id']?.toString() ?? '';
        String rNorm = PerformanceCalculator.normalizeDate(r['date']?.toString() ?? '');
        if (sid.isNotEmpty && rNorm.isNotEmpty) {
          String key = "${sid}_$rNorm";
          if (!uniqueCombined.containsKey(key)) {
            var copy = Map<String, dynamic>.from(r);
            copy['date'] = rNorm;
            uniqueCombined[key] = copy;
          } else {
            if (uniqueCombined[key]!['is_dummy'] == 1 && r['is_dummy'] != 1) {
              var copy = Map<String, dynamic>.from(r);
              copy['date'] = rNorm;
              uniqueCombined[key] = copy;
            }
          }
        }
      }

      List<Map<String, dynamic>> finalRecords = uniqueCombined.values.map((r) {
        var copy = Map<String, dynamic>.from(r);
        DateTime dt = _parseRobust(copy['date']?.toString() ?? '');
        if (dt.weekday == DateTime.friday) {
          copy['sabaq_1'] = 'جمعہ';
          copy['grade_1'] = 'جمعہ';
          copy['attendance'] = 'جمعہ';
        }
        return copy;
      }).toList();

      final existingStudentIds = finalRecords.map((r) => r['student_id']?.toString() ?? '').toSet();
      for (var s in _students) {
        String sid = s['id'].toString();
        if (selectedStudentId != null && sid != selectedStudentId) continue;
        if (!existingStudentIds.contains(sid)) {
          finalRecords.add({
            'student_id': s['id'],
            'name': s['name'] ?? '',
            'roll_number': s['roll_number'] ?? s['admission_number'] ?? '',
            'admission_number': s['admission_number'] ?? '',
            'date': toDate ?? intl.DateFormat('dd/MM/yyyy').format(DateTime.now()),
            'sabaq_1': '-',
            'qty_1': '-',
            'grade_1': '-',
            'sabaq_2': '-',
            'qty_2': '-',
            'grade_2': '-',
            'sabaq_3': '-',
            'qty_3': '-',
            'grade_3': '-',
            'sabaq_4': '-',
            'qty_4': '-',
            'grade_4': '-',
            'attendance': 'حاضر',
            'behavior': '-',
            'hearing_time': '-',
            'is_dummy': 1,
          });
        }
      }

      finalRecords.sort((a, b) {
        DateTime dA = _parseRobust(a['date'] ?? '');
        DateTime dB = _parseRobust(b['date'] ?? '');
        int dCmp = dB.compareTo(dA);
        if (dCmp != 0) return dCmp;
        return (a['name'] ?? '').toString().compareTo((b['name'] ?? '').toString());
      });

      setState(() => _records = finalRecords);
    } catch (e) {
      debugPrint("Error loading Qaida reports: $e");
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _selectDate(BuildContext context, bool isFrom) async {
    DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked != null) {
      String formatted = "${picked.day}/${picked.month}/${picked.year}";
      setState(() {
        if (isFrom) {
          fromDate = formatted;
          _fromDateController.text = formatted;
        } else {
          toDate = formatted;
          _toDateController.text = formatted;
        }
        selectedPeriod = 'custom';
      });
      _loadData();
    }
  }

  Widget _cell(String text, double width,
      {bool isHeader = false, Color? bgColor, Color? textColor, double? fontSize}) {
    return Container(
      width: width,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
      decoration: BoxDecoration(
        color: bgColor ?? (isHeader ? Colors.grey.shade200 : Colors.white),
        border: Border.all(color: Colors.black87, width: 0.5),
      ),
      alignment: Alignment.center,
      child: Text(
        text,
        textAlign: TextAlign.center,
        softWrap: true,
        style: TextStyle(
          fontWeight: isHeader ? FontWeight.bold : FontWeight.normal,
          fontSize: fontSize ?? (isHeader ? 12 : 11),
          color: textColor ?? Colors.black,
        ),
      ),
    );
  }

  Widget _buildTableHeader() {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell('#', 40, isHeader: true),
          _cell(isUrduGlobal.value ? 'رول نمبر' : 'Roll No', 70, isHeader: true),
          _cell(isUrduGlobal.value ? 'طالب علم' : 'Student', 160, isHeader: true),
          _cell(isUrduGlobal.value ? 'تاریخ' : 'Date', 80, isHeader: true),
          _cell(isUrduGlobal.value ? 'قسم' : 'Type', 80, isHeader: true),
          _cell(isUrduGlobal.value ? 'وقت' : 'Time', 70, isHeader: true),
          for (int i = 1; i <= 4; i++)
            Column(
              children: [
                _cell('سبق $i', 230, isHeader: true, bgColor: Colors.teal.withOpacity(0.7), textColor: Colors.white),
                Row(children: [
                  _cell(isUrduGlobal.value ? 'تختی/صفحہ' : 'Page', 100, isHeader: true),
                  _cell(isUrduGlobal.value ? 'مقدار' : 'Qty', 60, isHeader: true),
                  _cell(isUrduGlobal.value ? 'کیفیت' : 'Grade', 70, isHeader: true),
                ]),
              ],
            ),
          _cell(isUrduGlobal.value ? 'حاضری' : 'Att.', 70, isHeader: true),
          _cell(isUrduGlobal.value ? 'شکایات' : 'Remarks', 130, isHeader: true,
              bgColor: Colors.red.withOpacity(0.7), textColor: Colors.white),
        ],
      ),
    );
  }

  Color _gradeColor(String? grade) {
    if (grade == null) return Colors.black;
    final g = grade.toLowerCase();
    if (g == 'جمعہ') return Colors.indigo.shade700;
    if (g == 'excellent' || g == 'ممتاز') return Colors.green.shade700;
    if (g == 'good' || g == 'بہتر') return Colors.blue.shade700;
    if (g == 'average' || g == 'معمولی') return Colors.orange.shade700;
    if (g == 'weak' || g == 'کمزور') return Colors.red.shade700;
    if (g.contains('غیر حاضر') || g == 'absent') return Colors.red.shade900;
    if (g.contains('رخصت') || g == 'leave') return Colors.orange.shade800;
    if (g.contains('نہیں سنا')) return Colors.orange.shade900;
    if (g.contains('ناغہ')) return Colors.red.shade700;
    return Colors.black;
  }

  Widget _buildDataRow(Map<String, dynamic> r, int srNo) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(srNo.toString(), 40, bgColor: Colors.grey.shade100),
          _cell((r['roll_number'] ?? r['admission_number'] ?? '-').toString(), 70),
          _cell(r['name']?.toString() ?? '-', 160, textColor: Colors.black87),
          _cell(r['date']?.toString() ?? '-', 80, fontSize: 10),
          _cell(r['lesson_type']?.toString() ?? '-', 80),
          _cell(r['hearing_time'] != null && r['hearing_time'].toString().isNotEmpty
              ? r['hearing_time'].toString().substring(0, r['hearing_time'].toString().length > 5 ? 5 : r['hearing_time'].toString().length)
              : '-', 70),
          for (int i = 1; i <= 4; i++) ...[
            _cell(r['sabaq_$i']?.toString().isNotEmpty == true ? r['sabaq_$i'].toString() : '-', 100),
            _cell(r['qty_$i']?.toString().isNotEmpty == true ? r['qty_$i'].toString() : '-', 60),
            _cell(r['grade_$i']?.toString().isNotEmpty == true ? r['grade_$i'].toString() : '-', 70,
                textColor: _gradeColor(r['grade_$i']?.toString())),
          ],
          _cell(r['attendance']?.toString() ?? '-', 70),
          _cell(r['complaint']?.toString() ?? r['remarks']?.toString() ?? '-', 130,
              textColor: Colors.red.shade700),
        ],
      ),
    );
  }

  Future<void> _printQaidaPdf() async {
    try {
      final fontData = await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final fallbackData = await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
      final ttf = pw.Font.ttf(fontData);
      final fallbackTtf = pw.Font.ttf(fallbackData);
      final bold = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf], fontWeight: pw.FontWeight.bold);
      final normal = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf]);

      final pdf = pw.Document();

      final headers = ['#', 'رول نمبر', 'طالب علم', 'تاریخ', 'قسم', 'وقت',
        'سبق 1\nتختی', 'سبق 1\nمقدار', 'سبق 1\nکیفیت',
        'سبق 2\nتختی', 'سبق 2\nمقدار', 'سبق 2\nکیفیت',
        'سبق 3\nتختی', 'سبق 3\nمقدار', 'سبق 3\nکیفیت',
        'سبق 4\nتختی', 'سبق 4\nمقدار', 'سبق 4\nکیفیت',
        'حاضری', 'شکایات'];

      pdf.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4.landscape,
          textDirection: pw.TextDirection.rtl,
          theme: pw.ThemeData.withFont(
            base: ttf,
            fontFallback: [fallbackTtf],
          ),
          build: (context) => [
            pw.Text('قاعدہ / نماز حنفی رپورٹ', style: bold.copyWith(fontSize: 16)),
            pw.Text('کلاس: ${widget.className ?? "تمام"} | تاریخ: $fromDate تا $toDate', style: normal.copyWith(fontSize: 10)),
            pw.SizedBox(height: 8),
            pw.TableHelper.fromTextArray(
              headers: headers,
              data: _records.asMap().entries.map((e) {
                final r = e.value;
                return [
                  (e.key + 1).toString(),
                  r['roll_number']?.toString() ?? '-',
                  r['name']?.toString() ?? '-',
                  r['date']?.toString() ?? '-',
                  r['lesson_type']?.toString() ?? '-',
                  r['hearing_time']?.toString() ?? '-',
                  ...List.generate(4, (i) => [
                    r['sabaq_${i+1}']?.toString() ?? '-',
                    r['qty_${i+1}']?.toString() ?? '-',
                    r['grade_${i+1}']?.toString() ?? '-',
                  ]).expand((x) => x),
                  r['attendance']?.toString() ?? '-',
                  r['complaint']?.toString() ?? r['remarks']?.toString() ?? '-',
                ];
              }).toList(),
              headerStyle: bold.copyWith(fontSize: 7),
              cellStyle: normal.copyWith(fontSize: 7),
              headerDecoration: const pw.BoxDecoration(color: PdfColors.grey300),
              cellAlignment: pw.Alignment.center,
            ),
          ],
        ),
      );

      await Printing.layoutPdf(onLayout: (_) async => pdf.save());
    } catch (e) {
      debugPrint("Print Error: $e");
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isUrduGlobal.value ? "پرنٹ میں خرابی آئی: $e" : "Print error: $e"),
              backgroundColor: Colors.red),
        );
      }
    }
  }

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
          leading: Builder(
            builder: (context) => IconButton(
              icon: const Icon(Icons.menu, color: Colors.white),
              onPressed: () => Scaffold.of(context).openDrawer(),
            ),
          ),
          title: Text(
              isUrduGlobal.value ? "قاعدہ / نماز رپورٹ" : "Qaida / Namaz Report"),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
          actions: [
            IconButton(
              icon: const Icon(Icons.print, color: Colors.white),
              onPressed: _printQaidaPdf,
              tooltip: isUrduGlobal.value ? "پرنٹ PDF" : "Print PDF",
            ),
            IconButton(
              icon: const Icon(Icons.sync, color: Colors.white),
              onPressed: () async {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text(isUrduGlobal.value ? "ڈیٹا سنک ہو رہا ہے..." : "Syncing...")));
                try {
                  await SyncService.runTwoWaySync();
                  if (mounted) {
                    _loadData();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value ? "سنک مکمل!" : "Sync complete!"),
                        backgroundColor: Colors.green));
                  }
                } catch (e) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value ? "سنک میں خرابی" : "Sync failed"),
                        backgroundColor: Colors.red));
                  }
                }
              },
            ),
          ],
        ),
        body: Column(
          children: [
            // ─── Filter Card ───
            Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: Column(
                children: [
                  // Period Dropdown
                  DropdownButtonFormField<String>(
                    value: selectedPeriod,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "دورانیہ (Period)" : "Period",
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    items: [
                      DropdownMenuItem(value: 'current_week',
                          child: Text(isUrduGlobal.value ? "رواں ہفتہ (ڈیفالٹ)" : "Current Week (Default)")),
                      DropdownMenuItem(value: 'weekly',
                          child: Text(isUrduGlobal.value ? "ہفتہ وار (آخری 7 دن)" : "Weekly (Last 7 Days)")),
                      DropdownMenuItem(value: 'monthly',
                          child: Text(isUrduGlobal.value ? "ماہانہ (آخری 30 دن)" : "Monthly (Last 30 Days)")),
                      DropdownMenuItem(value: 'quarterly',
                          child: Text(isUrduGlobal.value ? "سہ ماہی (آخری 90 دن)" : "Quarterly (Last 90 Days)")),
                      DropdownMenuItem(value: 'semi_annually',
                          child: Text(isUrduGlobal.value ? "شش ماہی (آخری 180 دن)" : "Semi-Annually (180 Days)")),
                      DropdownMenuItem(value: 'annually',
                          child: Text(isUrduGlobal.value ? "سالانہ (آخری 365 دن)" : "Annually (365 Days)")),
                      DropdownMenuItem(value: 'custom',
                          child: Text(isUrduGlobal.value ? "کسٹم ڈیٹس" : "Custom Dates")),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setState(() => selectedPeriod = v);
                        _updateDatesForPeriod(v);
                      }
                    },
                  ),
                  const SizedBox(height: 10),
                  // Student Dropdown
                  DropdownButtonFormField<String>(
                    value: _students.any((s) => s['id'].toString() == selectedStudentId)
                        ? selectedStudentId
                        : null,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "طالب علم منتخب کریں" : "Select Student",
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    items: [
                      DropdownMenuItem(
                          value: null,
                          child: Text(isUrduGlobal.value ? "-- تمام طلباء (ڈیفالٹ) --" : "-- All Students (Default) --")),
                      ..._students.map((s) => DropdownMenuItem(
                          value: s['id'].toString(),
                          child: Text("${s['admission_number'] ?? s['roll_number'] ?? ''} - ${s['name']} (${s['father_name'] ?? ''})")))],
                    onChanged: (v) {
                      setState(() => selectedStudentId = v);
                      _loadData();
                    },
                  ),
                  const SizedBox(height: 10),
                  // Date Pickers
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _fromDateController,
                          readOnly: true,
                          decoration: InputDecoration(
                              labelText: isUrduGlobal.value ? "تاریخ سے" : "From Date",
                              prefixIcon: const Icon(Icons.calendar_today),
                              border: const OutlineInputBorder(),
                              contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0)),
                          onTap: () => _selectDate(context, true),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: TextField(
                          controller: _toDateController,
                          readOnly: true,
                          decoration: InputDecoration(
                              labelText: isUrduGlobal.value ? "تاریخ تک" : "To Date",
                              prefixIcon: const Icon(Icons.calendar_today),
                              border: const OutlineInputBorder(),
                              contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0)),
                          onTap: () => _selectDate(context, false),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            // ─── Table ───
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : selectedStudentId != null
                      ? StudentReportPage(
                          key: ValueKey(selectedStudentId),
                          forceStudentId: selectedStudentId,
                          isEmbedded: true,
                        )
                      : _records.isEmpty
                          ? Center(
                              child: Text(
                                isUrduGlobal.value
                                    ? "اس کلاس میں کوئی فعال طلبہ موجود نہیں ہیں"
                                    : "No active students found in this class",
                                style: const TextStyle(color: Colors.red, fontSize: 16),
                              ))
                          : SingleChildScrollView(
                          child: Padding(
                            padding: const EdgeInsets.all(8.0),
                            child: SingleChildScrollView(
                              scrollDirection: Axis.horizontal,
                              physics: const BouncingScrollPhysics(),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  _buildTableHeader(),
                                  ..._records.asMap().entries.map(
                                      (e) => _buildDataRow(e.value, e.key + 1)),
                                  const SizedBox(height: 20),
                                  Padding(
                                    padding: const EdgeInsets.all(8.0),
                                    child: Text(
                                      isUrduGlobal.value
                                          ? "مجموعی ریکارڈ: ${_records.length}"
                                          : "Total Records: ${_records.length}",
                                      style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          color: primaryGreen,
                                          fontSize: 13),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }
}


