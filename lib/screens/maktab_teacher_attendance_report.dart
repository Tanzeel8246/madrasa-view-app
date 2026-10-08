// ignore_for_file: use_build_context_synchronously

import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/teacher_period_utils.dart';
import '../widgets/app_drawer.dart';

class MaktabTeacherAttendanceReportPage extends StatefulWidget {
  const MaktabTeacherAttendanceReportPage({super.key});

  @override
  State<MaktabTeacherAttendanceReportPage> createState() =>
      _MaktabTeacherAttendanceReportPageState();
}

class _TeacherPeriodRef {
  const _TeacherPeriodRef(this.classId, this.className, this.periodNo);
  final int? classId;
  final String className;
  final int periodNo;
}

class _TeacherAttendanceRow {
  _TeacherAttendanceRow({
    required this.teacherId,
    required this.teacherName,
    required this.classId,
    required this.className,
    required this.periods,
  });

  final int teacherId;
  final String teacherName;
  final int? classId;
  final String className;
  final List<_TeacherPeriodRef> periods;
}

class _MaktabTeacherAttendanceReportPageState
    extends State<MaktabTeacherAttendanceReportPage> {
  final Color primary = const Color(0xFF284D3D);
  final DateFormat _fmt = DateFormat('yyyy-MM-dd');

  bool _isLoading = true;
  bool _isMonthMode = true;
  bool _isConsolidated = false;
  bool _isPrinting = false;
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();
  int? _teacherId;

  List<Map<String, dynamic>> _teachers = [];
  // List<Map<String, dynamic>> _classes = [];
  List<Map<String, dynamic>> _records = [];
  List<TeacherPeriodAssignment> _assignments = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  DateTime get _startDate =>
      _isMonthMode ? DateTime(_month.year, _month.month, 1) : _from;

  DateTime get _endDate => _isMonthMode
      ? DateTime(_month.year, _month.month + 1, 0)
      : (_to.isBefore(_from) ? _from : _to);

  List<DateTime> get _days {
    final days = <DateTime>[];
    var cursor = _startDate;
    while (!cursor.isAfter(_endDate)) {
      days.add(cursor);
      cursor = cursor.add(const Duration(days: 1));
    }
    return days;
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final teachers = await db.getMaktabTeachers();
      final classes = await db.getMaktabClassesForUser();
      final activeTeachers = teachers.where((t) {
        final st = (t['status'] ?? '').toString().trim().toLowerCase();
        final isDel = int.tryParse(t['is_deleted']?.toString() ?? '0') ?? 0;
        return isDel == 0 &&
            (st.isEmpty || st == 'active' || st == 'present' || st == 'حاضر');
      }).toList()
        ..sort((a, b) => (a['name'] ?? '')
            .toString()
            .compareTo((b['name'] ?? '').toString()));

      final records = <Map<String, dynamic>>[];
      for (final day in _days) {
        records.addAll(
            await db.getTeacherPeriodAttendanceByDate(_fmt.format(day)));
      }

      final assignments = buildTeacherPeriodAssignments(
        teachers: activeTeachers,
        classes: classes,
      );

      if (!mounted) return;
      setState(() {
        _teachers = activeTeachers;
        // _classes = classes;
        _records = records;
        _assignments = assignments;
      });
    } catch (e) {
      debugPrint('Error loading teacher attendance report: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }



  List<_TeacherAttendanceRow> get _rows {
    final grouped = <String, _TeacherAttendanceRow>{};
    
    if (_isConsolidated) {
      for (final a in _assignments) {
        if (_teacherId != null && a.teacherId != _teacherId) continue;
        final key = '${a.teacherId}';
        
        final periods = [_TeacherPeriodRef(a.classId, a.className, a.periodNo)];
            
        if (grouped.containsKey(key)) {
          grouped[key]!.periods.addAll(periods);
        } else {
          grouped[key] = _TeacherAttendanceRow(
            teacherId: a.teacherId,
            teacherName: a.teacherName,
            classId: null,
            className: 'مجموعی حاضری',
            periods: periods,
          );
        }
      }
    } else {
      for (final a in _assignments) {
        if (_teacherId != null && a.teacherId != _teacherId) continue;
        final key = '${a.teacherId}|${a.periodNo}';
        
        final periods = [_TeacherPeriodRef(a.classId, a.className, a.periodNo)];

        grouped.putIfAbsent(
          key,
          () => _TeacherAttendanceRow(
            teacherId: a.teacherId,
            teacherName: a.teacherName,
            classId: null,
            className: 'پیریڈ ${a.periodNo}',
            periods: periods,
          ),
        );
      }
    }
    
    final rows = grouped.values.toList()
      ..sort((a, b) {
        final c = a.className.compareTo(b.className);
        if (c != 0) return c;
        return a.teacherName.compareTo(b.teacherName);
      });
    return rows;
  }

  Map<String, dynamic>? _recordFor(
    _TeacherAttendanceRow row,
    DateTime date,
    _TeacherPeriodRef ref,
  ) {
    final dateStr = _fmt.format(date);
    for (final r in _records) {
      final teacherId = int.tryParse(r['teacher_id']?.toString() ?? '') ?? 0;
      final classId = int.tryParse(r['class_id']?.toString() ?? '');
      final className = r['class_name']?.toString() ?? '';
      final pNo = int.tryParse(r['period_no']?.toString() ?? '') ?? 0;
      final rDate = r['date']?.toString() ?? '';
      
      final sameClass = ref.classId != null
          ? classId == ref.classId || className == ref.className
          : className == ref.className;
          
      if (teacherId == row.teacherId &&
          sameClass &&
          rDate == dateStr &&
          pNo == ref.periodNo) {
        return r;
      }
    }
    return null;
  }

  String _statusFor(Map<String, dynamic>? record) {
    if (record == null) return '';
    final raw = (record['status'] ?? '').toString().trim();
    final up = raw.toUpperCase();
    final late = int.tryParse(record['late_minutes']?.toString() ?? '0') ?? 0;
    
    if (up == 'OD' || raw == 'بکار مدرسہ') return 'OD';
    if (up == 'LA' || up == 'L' && raw == 'رخصت اتفاقی' || raw == 'رخصت اتفاقی') return 'LA';
    if (up == 'L' || raw == 'رخصت') return 'L';
    if (up == 'T' || late > 0) return 'T';
    if (up == 'P' || raw == 'حاضر') return 'P';
    return up;
  }

  int _lateMinutes(Map<String, dynamic>? record) =>
      int.tryParse(record?['late_minutes']?.toString() ?? '0') ?? 0;

  Map<String, num> _countsForRow(_TeacherAttendanceRow row) {
    final counts = <String, num>{'P': 0, 'T': 0, 'L': 0, 'lateMinutes': 0};
    final N = row.periods.isEmpty ? 1 : row.periods.length;
    final fraction = 1.0 / N;

    for (final day in _days) {
      if (day.weekday == DateTime.friday) {
        counts['P'] = (counts['P'] ?? 0) + 1.0;
        continue;
      }
      for (final period in row.periods) {
        final record = _recordFor(row, day, period);
        final status = _statusFor(record);
        
        if (status == 'P' || status == 'OD' || status == 'T') {
          counts['P'] = (counts['P'] ?? 0) + fraction;
          if (status == 'T') {
            counts['T'] = (counts['T'] ?? 0) + 1;
            counts['lateMinutes'] = (counts['lateMinutes'] ?? 0) + _lateMinutes(record);
          }
        } else if (status == 'LA' || status == 'L') {
          counts['L'] = (counts['L'] ?? 0) + fraction;
        }
      }
    }
    return counts;
  }

  Map<String, num> get _totalCounts {
    final total = <String, num>{'P': 0, 'T': 0, 'L': 0, 'lateMinutes': 0};
    for (final row in _rows) {
      final c = _countsForRow(row);
      for (final key in total.keys) {
        total[key] = (total[key] ?? 0) + (c[key] ?? 0);
      }
    }
    return total;
  }

  // num get _totalMarkedSlots {
    // final c = _totalCounts;
    // return (c['P'] ?? 0) + (c['L'] ?? 0);
  // }


  String _formatNum(num n) {
    final rounded = (n * 100).roundToDouble() / 100.0;
    if (rounded == rounded.roundToDouble()) return rounded.toInt().toString();
    return rounded.toStringAsFixed(1);
  }

  Future<void> _pickMonth() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _month,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
      helpText: 'مہینہ منتخب کریں',
    );
    if (picked != null) {
      _month = DateTime(picked.year, picked.month);
      await _load();
    }
  }

  Future<void> _pickRangeDate(bool isFrom) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: isFrom ? _from : _to,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked != null) {
      if (isFrom) {
        _from = picked;
        if (_to.isBefore(_from)) _to = _from;
      } else {
        _to = picked;
        if (_to.isBefore(_from)) _from = _to;
      }
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    return Directionality(
      textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
      child: Scaffold(
        drawer: const AppDrawer(),
        backgroundColor: const Color(0xFFF1EFE7),
        appBar: AppBar(
          title:
              Text(isUrdu ? 'اساتذہ حاضری رپورٹ' : 'Teacher Attendance Report'),
          backgroundColor: primary,
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              tooltip: isUrdu ? 'پرنٹ' : 'Print',
              onPressed: _isPrinting ? null : _printReport,
              icon: _isPrinting
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Icon(Icons.print),
            ),
            IconButton(
              tooltip: isUrdu ? 'ری فریش' : 'Refresh',
              onPressed: _load,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  _filters(isUrdu),
                  _summary(isUrdu),
                  Expanded(child: _reportTable(isUrdu)),
                ],
              ),
      ),
    );
  }

  Widget _filters(bool isUrdu) {
    return Container(
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
      ),
      child: Wrap(
        spacing: 10,
        runSpacing: 10,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(
                value: false,
                label: Text(isUrdu ? 'کلاس وائز' : 'Class-wise'),
              ),
              ButtonSegment(
                value: true,
                label: Text(isUrdu ? 'مجموعی حاضری' : 'Consolidated'),
              ),
            ],
            selected: {_isConsolidated},
            onSelectionChanged: (v) {
              setState(() => _isConsolidated = v.first);
            },
          ),
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(
                value: true,
                label: Text(isUrdu ? 'ماہانہ' : 'Monthly'),
                icon: const Icon(Icons.calendar_month),
              ),
              ButtonSegment(
                value: false,
                label: Text(isUrdu ? 'رینج' : 'Range'),
                icon: const Icon(Icons.date_range),
              ),
            ],
            selected: {_isMonthMode},
            onSelectionChanged: (v) async {
              _isMonthMode = v.first;
              await _load();
            },
          ),
          if (_isMonthMode)
            OutlinedButton.icon(
              onPressed: _pickMonth,
              icon: const Icon(Icons.calendar_today),
              label: Text(DateFormat('MMMM yyyy').format(_month)),
            )
          else ...[
            OutlinedButton.icon(
              onPressed: () => _pickRangeDate(true),
              icon: const Icon(Icons.today),
              label: Text('${isUrdu ? 'سے' : 'From'}: ${_fmt.format(_from)}'),
            ),
            OutlinedButton.icon(
              onPressed: () => _pickRangeDate(false),
              icon: const Icon(Icons.event),
              label: Text('${isUrdu ? 'تک' : 'To'}: ${_fmt.format(_to)}'),
            ),
          ],
          DropdownButton<int?>(
            value: _teacherId,
            hint: Text(isUrdu ? 'تمام اساتذہ' : 'All Teachers'),
            items: [
              DropdownMenuItem<int?>(
                value: null,
                child: Text(isUrdu ? 'تمام اساتذہ' : 'All Teachers'),
              ),
              ..._teachers.map(
                (t) => DropdownMenuItem<int?>(
                  value: int.tryParse(t['id']?.toString() ?? ''),
                  child: Text(t['name']?.toString() ?? '-'),
                ),
              ),
            ],
            onChanged: (v) async {
              setState(() => _teacherId = v);
              await _load();
            },
          ),
        ],
      ),
    );
  }

  Widget _summary(bool isUrdu) {
    final c = _totalCounts;
    double totalSlots = ((c['P'] ?? 0) + (c['T'] ?? 0) + (c['A'] ?? 0) + (c['L'] ?? 0)).toDouble();
    if (totalSlots == 0) totalSlots = 1;
    
    double percP = (((c['P'] ?? 0) / totalSlots) * 100).toDouble();
    double percT = (((c['T'] ?? 0) / totalSlots) * 100).toDouble();
    double percA = (((c['A'] ?? 0) / totalSlots) * 100).toDouble();
    double percL = (((c['L'] ?? 0) / totalSlots) * 100).toDouble();

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          _summaryItem(isUrdu ? 'حاضری کی شرح' : 'Present %', percP, Colors.green, true),
          _summaryItem(isUrdu ? 'تاخیر کی شرح' : 'Late %', percT, Colors.deepOrange, true),
          _summaryItem(isUrdu ? 'غیر حاضری کی شرح' : 'Absent %', percA, Colors.red, true),
          _summaryItem(isUrdu ? 'رخصت کی شرح' : 'Leave %', percL, Colors.orange, true),
        ],
      ),
    );
  }

  Widget _summaryItem(String label, num value, Color color, [bool isPercent = false]) {
    String valStr = isPercent ? '${value.toStringAsFixed(1)}%' : _formatNum(value);
    return Expanded(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(valStr,
              style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 16)),
          Text(label,
              style: const TextStyle(fontSize: 10),
              textAlign: TextAlign.center),
        ],
      ),
    );
  }

  Widget _reportTable(bool isUrdu) {
    final rows = _rows;
    if (rows.isEmpty) {
      return Center(
          child: Text(isUrdu
              ? 'کوئی استاد/کلاس تفویض نہیں'
              : 'No teacher assignments found'));
    }
    return Container(
      margin: const EdgeInsets.all(12),
      decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(8)),
      child: Scrollbar(
        thumbVisibility: true,
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: SingleChildScrollView(
            child: DataTable(
              headingRowColor:
                  WidgetStateProperty.all(primary.withOpacity(0.08)),
              columnSpacing: 10,
              dataRowMinHeight: 34,
              dataRowMaxHeight: 42,
              columns: [
                const DataColumn(label: Text('#')),
                DataColumn(label: Text(isUrdu ? 'کلاس' : 'Class')),
                DataColumn(label: Text(isUrdu ? 'استاد' : 'Teacher')),
                ..._days.map(
                    (d) => DataColumn(label: Text(DateFormat('d').format(d)))),
                DataColumn(label: Text(isUrdu ? 'حاضر (دن)' : 'P(D)')),
                DataColumn(label: Text(isUrdu ? 'تاخیر (پ)' : 'T(P)')),
                DataColumn(label: Text(isUrdu ? 'رخصت (دن)' : 'L(D)')),
                DataColumn(label: Text(isUrdu ? 'تاخیر منٹ' : 'Late Min')),
              ],
              rows: List.generate(rows.length, (index) {
                final row = rows[index];
                final counts = _countsForRow(row);
                return DataRow(cells: [
                  DataCell(Text('${index + 1}')),
                  DataCell(Text(row.className,
                      style: const TextStyle(fontWeight: FontWeight.bold))),
                  DataCell(SizedBox(
                    width: 150,
                    child: Text(
                      row.teacherName,
                      maxLines: 1,
                      overflow: TextOverflow.visible,
                      softWrap: false,
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  )),
                  ..._days.map((d) => DataCell(_periodDots(row, d))),
                  DataCell(Text(_formatNum(counts['P'] ?? 0))),
                  DataCell(Text(_formatNum(counts['T'] ?? 0))),
                  DataCell(Text(_formatNum(counts['L'] ?? 0))),
                  DataCell(Text(_formatNum(counts['lateMinutes'] ?? 0))),
                ]);
              }),
            ),
          ),
        ),
      ),
    );
  }

  Widget _periodDots(_TeacherAttendanceRow row, DateTime day) {
    if (day.weekday == DateTime.friday) {
      return const SizedBox(
        width: 16,
        child: OverflowBox(
          maxHeight: 0,
          minHeight: 0,
          child: RotatedBox(
            quarterTurns: 3,
            child: Text("جمعہ", style: TextStyle(fontSize: 7, color: Colors.green, fontWeight: FontWeight.bold)),
          ),
        ),
      );
    }
    final statuses =
        row.periods.map((p) => _statusFor(_recordFor(row, day, p))).toList();
    if (statuses.every((s) => s.isEmpty)) {
      return const SizedBox(width: 24, height: 16);
    }
    
    final width = row.periods.length > 8 ? 28.0 : (row.periods.length > 4 ? 22.0 : 16.0);
    return SizedBox(
      width: width,
      child: Wrap(
        spacing: 1,
        runSpacing: 1,
        alignment: WrapAlignment.center,
        children: statuses.map(_dot).toList(),
      ),
    );
  }

  Widget _dot(String status) {
    final color = switch (status) {
      'P' => Colors.green,
      'OD' => Colors.teal,
      'LA' => Colors.blue,
      'L' => Colors.orange,
      'T' => Colors.deepOrange,
      _ => Colors.grey.shade300,
    };
    return Container(
      width: 6,
      height: 6,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
  }

  Future<void> _printReport() async {
    setState(() => _isPrinting = true);
    try {
      final rows = _rows;
      await Printing.layoutPdf(
        onLayout: (PdfPageFormat format) async {
          final doc = pw.Document();
          doc.addPage(
            pw.MultiPage(
              pageFormat: PdfPageFormat.a4.landscape,
              textDirection: pw.TextDirection.rtl,
              build: (context) => [
                pw.Center(
                  child: pw.Text('اساتذہ حاضری رپورٹ',
                      style: pw.TextStyle(
                          fontSize: 18, fontWeight: pw.FontWeight.bold)),
                ),
                pw.SizedBox(height: 8),
                pw.TableHelper.fromTextArray(
                  headers: [
                    '#',
                    'کلاس',
                    'استاد',
                    ..._days.map((d) => DateFormat('d').format(d)),
                    'حاضر (دن)',
                    'تاخیر (پ)',
                    'رخصت (دن)',
                    'تاخیر منٹ'
                  ],
                  data: List.generate(rows.length, (i) {
                    final row = rows[i];
                    final counts = _countsForRow(row);
                    return [
                      '${i + 1}',
                      row.className,
                      row.teacherName,
                      ..._days.map((d) => row.periods
                          .map((p) => _statusFor(_recordFor(row, d, p)).isEmpty
                              ? '-'
                              : _statusFor(_recordFor(row, d, p)))
                          .join('')),
                      _formatNum(counts['P'] ?? 0),
                      _formatNum(counts['T'] ?? 0),
                      _formatNum(counts['L'] ?? 0),
                      _formatNum(counts['lateMinutes'] ?? 0),
                    ];
                  }),
                  cellStyle: const pw.TextStyle(fontSize: 6),
                  headerStyle:
                      pw.TextStyle(fontSize: 7, fontWeight: pw.FontWeight.bold),
                ),
              ],
            ),
          );
          return doc.save();
        },
      );
    } finally {
      if (mounted) setState(() => _isPrinting = false);
    }
  }
}

