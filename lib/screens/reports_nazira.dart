// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../utils/performance_helper.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/ai_service.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'student_report.dart';

class DateChunk {
  final int pageIndex;
  final DateTime startDate;
  final DateTime endDate;
  final String label;

  DateChunk({
    required this.pageIndex,
    required this.startDate,
    required this.endDate,
    required this.label,
  });
}

class ReportsNaziraPage extends StatefulWidget {
  final String? className;
  const ReportsNaziraPage({super.key, this.className});

  @override
  _ReportsNaziraPageState createState() => _ReportsNaziraPageState();
}

class _ReportsNaziraPageState extends State<ReportsNaziraPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  List<Map<String, dynamic>> _naziraRecords = [];
  List<Map<String, dynamic>> _students = [];
  List<Map<String, dynamic>> _complaints = [];
  bool isLoading = true;
  String _loadingStep = '';
  String? _errorMessage;

  List<DateChunk> _dateChunks = [];
  int _selectedChunkIndex = 0;
  List<Map<String, dynamic>> _cachedRawNazira = [];
  List<Map<String, dynamic>> _cachedRawAtt = [];

  String? fromDate;
  String? toDate;
  String? selectedStudentId;
  String selectedPeriod = 'current_week';

  Map<String, dynamic>? _weeklyStats;
  String _aiInsight = "";
  bool _isAILoading = false;

  final _fromDateController = TextEditingController();
  final _toDateController = TextEditingController();

  Map<String, Map<String, dynamic>> _allStudentMap = {};

  Future<void> _updateDatesForPeriod(String period) async {
    if (period == 'custom') return;
    final db = DBHelper();
    final settings = await db.getSettings();
    int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;
    final boundaries = PerformanceCalculator.getPeriodBoundaries(period, weekStartDay);
    setState(() {
      fromDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['start']!);
      toDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['end']!);
      _fromDateController.text = fromDate!;
      _toDateController.text = toDate!;
      _selectedChunkIndex = -1;
    });
    _loadData();
  }

  @override
  void initState() {
    super.initState();
    _loadData();
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

  Future<void> _loadData() async {
    setState(() {
      isLoading = true;
      _errorMessage = null;
      _loadingStep = isUrduGlobal.value
          ? "ڈیٹا لوڈ کیا جا رہا ہے..."
          : "Loading data...";
    });
    try {
      final db = DBHelper();
      final allStudents = await db.getStudentsForUser(includeInactive: true);
      List<Map<String, dynamic>> extraMaktabStudents = [];
      try {
        extraMaktabStudents = await db.getMaktabStudentsForUser(includeInactive: true);
      } catch (_) {}
      final combinedStudents = [...allStudents, ...extraMaktabStudents];

      _allStudentMap = {
        for (var s in combinedStudents) s['id']?.toString() ?? '': s
      };
      final Set<String> seenIds = {};
      final List<Map<String, dynamic>> validStudents = [];
      for (var s in combinedStudents) {
        bool isEligible = PerformanceCalculator.isStudentActive(s);

        String cName =
            (s['class_name']?.toString() ?? s['class']?.toString() ?? '')
                .toLowerCase();
        String normCName = _normalizeClassName(s['class_name'] ?? s['class']);
        
        bool isClassMatch = true;
        if (widget.className != null && widget.className!.trim().isNotEmpty) {
          String reqClass = widget.className!.trim().toLowerCase();
          String normReqClass = _normalizeClassName(widget.className);
          isClassMatch = (normCName == normReqClass ||
              cName == reqClass ||
              (s['class_name']?.toString().trim() == widget.className?.trim()) ||
              (s['class']?.toString().trim() == widget.className?.trim()));
        } else {
          isClassMatch = cName.contains('ناظرہ') ||
              cName.contains('حسن صوت') ||
              cName.contains('گردان');
        }

        final idStr = s['id']?.toString();
        if (isEligible && isClassMatch && idStr != null && !seenIds.contains(idStr)) {
          seenIds.add(idStr);
          validStudents.add(s);
        }
      }
      _students = validStudents;
      if (selectedStudentId != null && !seenIds.contains(selectedStudentId)) {
        selectedStudentId = null;
      }
      final allComplaints = await db.getComplaints();
      _complaints = allComplaints;

      final settings = await db.getSettings();
      int weekStartDay =
          int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;

      if (fromDate == null || toDate == null) {
        final boundaries =
            PerformanceCalculator.getPeriodBoundaries(selectedPeriod, weekStartDay);
        fromDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['start']!);
        toDate = intl.DateFormat('dd/MM/yyyy').format(boundaries['end']!);
        _fromDateController.text = fromDate!;
        _toDateController.text = toDate!;
      }

      DateTime sDateRaw = _parseRobust(fromDate!);
      DateTime eDateRaw = _parseRobust(toDate!);
      DateTime sDate = DateTime(sDateRaw.year, sDateRaw.month, sDateRaw.day, 0, 0, 0);
      DateTime eDate = DateTime(eDateRaw.year, eDateRaw.month, eDateRaw.day, 23, 59, 59, 999);

      if (eDate.isBefore(sDate)) {
        final tmp = sDate;
        sDate = eDate;
        eDate = tmp;
      }

      // 10-Day Chunk Generation
      List<DateChunk> chunks = [];
      final totalDays = eDate.difference(sDate).inDays + 1;
      if (totalDays <= 10) {
        final startStr = intl.DateFormat('dd/MM/yyyy').format(sDate);
        final endStr = intl.DateFormat('dd/MM/yyyy').format(eDate);
        chunks.add(DateChunk(
          pageIndex: 1,
          startDate: sDate,
          endDate: eDate,
          label: isUrduGlobal.value ? "مرحلہ 1 ($startStr تا $endStr)" : "Phase 1 ($startStr to $endStr)",
        ));
      } else {
        DateTime cur = sDate;
        int pIndex = 1;
        while (!cur.isAfter(eDate)) {
          DateTime chunkEnd = cur.add(const Duration(days: 9));
          if (chunkEnd.isAfter(eDate)) chunkEnd = eDate;
          final startStr = intl.DateFormat('dd/MM/yyyy').format(cur);
          final endStr = intl.DateFormat('dd/MM/yyyy').format(chunkEnd);
          chunks.add(DateChunk(
            pageIndex: pIndex,
            startDate: DateTime(cur.year, cur.month, cur.day, 0, 0, 0),
            endDate: DateTime(chunkEnd.year, chunkEnd.month, chunkEnd.day, 23, 59, 59, 999),
            label: isUrduGlobal.value ? "مرحلہ $pIndex ($startStr تا $endStr)" : "Phase $pIndex ($startStr to $endStr)",
          ));
          cur = chunkEnd.add(const Duration(days: 1));
          pIndex++;
        }
      }
      _dateChunks = chunks;
      if (_selectedChunkIndex >= _dateChunks.length || _selectedChunkIndex < 0) {
        _selectedChunkIndex = _dateChunks.length - 1; // Default to latest phase
      }

      if (mounted) {
        setState(() {
          _loadingStep = isUrduGlobal.value
              ? "ڈیٹا بیس سے ریکارڈز حاصل کیے جا رہے ہیں..."
              : "Loading records from database...";
        });
      }
      await Future.delayed(const Duration(milliseconds: 20));

      final database = await db.database;
      final studentIdSet = _students.map((s) => s['id']?.toString() ?? '').where((id) => id.isNotEmpty).toSet();
      final sidsList = studentIdSet.map((s) => int.tryParse(s) ?? 0).where((i) => i > 0).toList();

      List<Map<String, dynamic>> allNazira = [];
      List<Map<String, dynamic>> extraMaktabNazira = [];
      List<Map<String, dynamic>> attRows = [];
      List<Map<String, dynamic>> extraAtt = [];

      if (sidsList.isNotEmpty) {
        final placeholders = List.filled(sidsList.length, '?').join(',');
        allNazira = await database.query(
          'nazira_record',
          where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: sidsList,
        );
        try {
          extraMaktabNazira = await database.query(
            'maktab_nazira_record',
            where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: sidsList,
          );
        } catch (_) {}

        attRows = await database.query(
          'attendance',
          where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: sidsList,
        );
        try {
          extraAtt = await database.query(
            'maktab_attendance',
            where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: sidsList,
          );
        } catch (_) {}
      } else {
        allNazira = await db.getNaziraRecords();
        attRows = await database.query('attendance', limit: 200);
      }
      _cachedRawNazira = [...allNazira, ...extraMaktabNazira];
      _cachedRawAtt = [...attRows, ...extraAtt];

      await _processActiveChunk();
    } catch (e, stack) {
      debugPrint("Load Nazira Report Error: $e\n$stack");
      if (mounted) {
        setState(() {
          isLoading = false;
          _loadingStep = '';
          _errorMessage = isUrduGlobal.value
              ? "ڈیٹا لوڈ کرنے میں خرابی پیش آئی: ${e.toString()}"
              : "Error loading data: ${e.toString()}";
        });
      }
    }
  }

  Future<void> _processActiveChunk() async {
    if (_dateChunks.isEmpty) {
      if (mounted) setState(() => isLoading = false);
      return;
    }
    if (!mounted) return;
    setState(() {
      isLoading = true;
      _loadingStep = isUrduGlobal.value
          ? "مرحلہ ${_selectedChunkIndex + 1} از ${_dateChunks.length} کا تجزیہ جاری ہے..."
          : "Analyzing Phase ${_selectedChunkIndex + 1} of ${_dateChunks.length}...";
    });
    await Future.delayed(const Duration(milliseconds: 20));

    try {
      final activeChunk = _dateChunks[_selectedChunkIndex];
      final DateTime sDate = activeChunk.startDate;
      final DateTime eDate = activeChunk.endDate;
      final studentIdSet = _students.map((s) => s['id']?.toString() ?? '').toSet();

      // Only attendance within active 10-day chunk
      final relevantAttendance = _cachedRawAtt.where((a) {
        String sid = a['student_id']?.toString() ?? '';
        if (!studentIdSet.contains(sid)) return false;
        try {
          DateTime d = _parseRobust(a['date']?.toString() ?? '');
          return !d.isBefore(sDate) && !d.isAfter(eDate);
        } catch (_) {
          return true;
        }
      }).toList();

      // Only nazira records within active 10-day chunk
      List<Map<String, dynamic>> filtered = [];
      for (var h in _cachedRawNazira) {
        if (selectedStudentId != null &&
            h['student_id'].toString() != selectedStudentId) {
          continue;
        }
        if (selectedStudentId == null) {
          bool isStudentValid = studentIdSet.contains(h['student_id']?.toString());
          if (!isStudentValid) continue;
        }
        try {
          DateTime fDate = _parseRobust(h['date'] ?? '');
          if (fDate.isBefore(sDate) || fDate.isAfter(eDate)) continue;
        } catch (_) {
          continue;
        }
        filtered.add(h);
      }

      if (selectedStudentId != null) {
        final settings = await DBHelper().getSettings();
        int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;
        _weeklyStats = PerformanceCalculator.calculateWeeklyPerformance(
          hifzRecords: <Map<String, dynamic>>[],
          attendanceRecords: _cachedRawAtt
              .where((a) => a['student_id'].toString() == selectedStudentId)
              .toList(),
          weekStartDay: weekStartDay,
        );
        _fetchAIInsight();
      } else {
        _weeklyStats = null;
        _aiInsight = "";
      }

      filtered = PerformanceCalculator.injectMissingAttendanceRecords(
          filtered, relevantAttendance, _students, 'nazira');
      
      filtered.sort((a, b) {
        DateTime dA = _parseRobust(a['date'] ?? '');
        DateTime dB = _parseRobust(b['date'] ?? '');
        int cmp = dB.compareTo(dA); // descending (newest first)
        if (cmp != 0) return cmp;
        return (a['student_id'] ?? 0)
            .toString()
            .compareTo((b['student_id'] ?? 0).toString());
      });

      _naziraRecords = filtered;
      _errorMessage = null;
    } catch (e, stack) {
      debugPrint("Process Active Nazira Chunk Error: $e\n$stack");
      _errorMessage = isUrduGlobal.value
          ? "ریکارڈز کے تجزیے میں مسئلہ پیش آیا: ${e.toString()}"
          : "Failed to process records: ${e.toString()}";
    } finally {
      if (mounted) {
        setState(() {
          isLoading = false;
          _loadingStep = '';
        });
      }
    }
  }

  void _switchChunk(int newIndex) {
    if (newIndex < 0 || newIndex >= _dateChunks.length) return;
    setState(() {
      _selectedChunkIndex = newIndex;
    });
    _processActiveChunk();
  }

  Widget _build10DayPaginationBar() {
    if (_dateChunks.length <= 1) return const SizedBox.shrink();
    final currentChunk = _dateChunks[_selectedChunkIndex];
    final hasPrev = _selectedChunkIndex > 0;
    final hasNext = _selectedChunkIndex < _dateChunks.length - 1;

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: primaryGreen.withOpacity(0.3)),
        boxShadow: const [
          BoxShadow(color: Colors.black12, blurRadius: 4, offset: Offset(0, 2))
        ],
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              ElevatedButton.icon(
                onPressed: hasPrev ? () => _switchChunk(_selectedChunkIndex - 1) : null,
                icon: const Icon(Icons.arrow_back, size: 16),
                label: Text(isUrduGlobal.value ? "سابقہ 10 دن" : "Prev 10 Days"),
                style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  child: Text(
                    "${isUrduGlobal.value ? '10 روزہ مرحلہ' : '10-Day Phase'} ${_selectedChunkIndex + 1} / ${_dateChunks.length}\n${intl.DateFormat('dd/MM/yyyy').format(currentChunk.startDate)} تا ${intl.DateFormat('dd/MM/yyyy').format(currentChunk.endDate)}",
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: primaryGreen,
                    ),
                  ),
                ),
              ),
              ElevatedButton.icon(
                onPressed: hasNext ? () => _switchChunk(_selectedChunkIndex + 1) : null,
                icon: const Icon(Icons.arrow_forward, size: 16),
                label: Text(isUrduGlobal.value ? "اگلے 10 دن" : "Next 10 Days"),
                style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(
              color: const Color(0xFFF1EFE7),
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: Colors.grey.shade400),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<int>(
                value: _selectedChunkIndex,
                isExpanded: true,
                isDense: true,
                items: _dateChunks.asMap().entries.map((e) {
                  final c = e.value;
                  final sStr = intl.DateFormat('dd/MM').format(c.startDate);
                  final eStr = intl.DateFormat('dd/MM').format(c.endDate);
                  return DropdownMenuItem<int>(
                    value: e.key,
                    child: Text(
                      "${c.label} ($sStr - $eStr)",
                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                    ),
                  );
                }).toList(),
                onChanged: (val) {
                  if (val != null) _switchChunk(val);
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLoadingWidget() {
    return Center(
      child: Container(
        margin: const EdgeInsets.all(24),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(color: Colors.black12, blurRadius: 10, offset: Offset(0, 4))
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(color: primaryGreen, strokeWidth: 3.5),
            const SizedBox(height: 20),
            Text(
              _loadingStep.isNotEmpty
                  ? _loadingStep
                  : (isUrduGlobal.value ? "ناظرہ تعلیمی ریکارڈز کا جائزہ لیا جا رہا ہے..." : "Processing Nazira records..."),
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.bold,
                color: primaryGreen,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              isUrduGlobal.value
                  ? "براہ کرم انتظار فرمائیں، ڈیٹا مرحلہ وار لوڈ ہو رہا ہے"
                  : "Please wait, data is loading in 10-day stages",
              style: const TextStyle(fontSize: 12, color: Colors.grey),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildErrorWidget() {
    return Center(
      child: Container(
        margin: const EdgeInsets.all(24),
        padding: const EdgeInsets.all(24),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(color: Colors.black12, blurRadius: 10, offset: Offset(0, 4))
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, color: Colors.red, size: 48),
            const SizedBox(height: 16),
            Text(
              _errorMessage ?? (isUrduGlobal.value ? "ایک خرابی پیش آگئی" : "An error occurred"),
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 14, color: Colors.red, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 20),
            ElevatedButton.icon(
              onPressed: () => _loadData(),
              icon: const Icon(Icons.refresh),
              label: Text(isUrduGlobal.value ? "دوبارہ کوشش کریں" : "Retry"),
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
            ),
          ],
        ),
      ),
    );
  }

  DateTime _parseRobust(String dateStr) {
    return PerformanceCalculator.parseDateRobust(dateStr);
  }

  Future<void> _fetchAIInsight() async {
    if (_weeklyStats == null) return;
    setState(() => _isAILoading = true);

    final prompt =
        "You are a senior supervisor of a Hifz Madrasa. Analyze this student's stats for the week: Attendance: ${_weeklyStats!['attendancePerc']}%, Academic Score: ${_weeklyStats!['academicScore']}/100. Provide a concise 2-line strategic advice in URDU for the CLASS TEACHER on how to handle this student. Just Urdu text.";

    try {
      final insight = await AIService.generateInsight(prompt);
      if (!mounted) return;
      setState(() {
        _aiInsight = insight;
      });
    } catch (e) {
      debugPrint("AI Error: $e");
      if (mounted) {
        setState(() {
          _aiInsight = isUrduGlobal.value
              ? "Unable to reach AI service"
              : "Unable to reach AI service";
        });
      }
    } finally {
      if (mounted) {
        setState(() => _isAILoading = false);
      }
    }
  }

  Future<void> _selectDate(BuildContext context, bool isFrom) async {
    DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime.now(),
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
        _selectedChunkIndex = -1;
      });
      _loadData();
    }
  }

  String _getStudentName(String sid) {
    if (sid.isEmpty) return "Unknown";
    final s = _students.firstWhere(
      (st) => st['id'].toString() == sid,
      orElse: () => _allStudentMap[sid] ?? <String, dynamic>{},
    );
    if (s.isNotEmpty && s['name'] != null && s['name'].toString().isNotEmpty) {
      String fName = s['father_name']?.toString().trim() ?? '';
      return fName.isNotEmpty ? "${s['name']} ($fName)" : "${s['name']}";
    }
    return "ID: $sid";
  }

  String translateStatusClean(String? status) {
    if (status == null) return '-';
    if (status == 'جمعہ') return 'جمعہ';
    if (isUrduGlobal.value) {
      if (status == 'Excellent') return 'ممتاز';
      if (status == 'Good') return 'بہتر';
      if (status == 'Average') return 'معمولی';
      if (status == 'Weak') return 'کمزور';
      if (status == 'Absent') return 'غیر حاضر';
      if (status == 'Leave') return 'رخصت';
    } else {
      return status;
    }
    return status;
  }

  Color getStatusColor(String? status) {
    if (status == null) return Colors.black;
    if (status == 'جمعہ') return Colors.indigo.shade700;
    if (status == 'Excellent' || status == 'ممتاز') return Colors.green;
    if (status == 'Good' || status == 'بہتر') return Colors.blue;
    if (status == 'Weak' || status == 'کمزور') return Colors.red;
    if (status == 'Average' || status == 'معمولی') return Colors.orange;
    if (status == 'غير حاضر' || status == 'غیر حاضر' || status == 'Absent') return Colors.red.shade900;
    if (status == 'رخصت' || status == 'Leave') return Colors.orange.shade800;
    if (status == 'ناغہ') return Colors.red.shade700;
    return Colors.black;
  }

  String translateQtyClean(String? qty) {
    if (qty == null) return '-';
    // Remove redundant fraction labels if present
    String clean = qty
        .replaceAll('(3/4)', '')
        .replaceAll('(1/2)', '')
        .replaceAll('(1/4)', '')
        .replaceAll('(1)', '')
        .trim();

    if (!isUrduGlobal.value) return clean;
    final map = {
      'Quarter': 'پاؤ',
      'Half': 'آدھا',
      'Three Quarter': 'پونا پارہ',
      'Full': 'مکمل',
      '1.5 Parah': 'ڈیڑھ',
      '2 Parah': 'دو',
      '2.5 Parah': 'ڈھائی',
      '3 Parah': 'تین'
    };
    return map[clean] ?? clean;
  }

  Widget _cell(String text, double width,
      {Color? bgColor,
      Color? textColor,
      bool isHeader = false,
      bool bold = false,
      double? fontSize}) {
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
        softWrap: false,
        overflow: TextOverflow.visible,
        style: TextStyle(
          fontWeight: (bold || isHeader) ? FontWeight.bold : FontWeight.normal,
          fontSize: fontSize ?? (isHeader ? 12 : 11),
          color: textColor ?? Colors.black,
        ),
      ),
    );
  }

  Widget _buildTableHeader() {
    bool isIndividual = selectedStudentId != null;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(isUrduGlobal.value ? "نمبر شمار" : "Sr No", 40, isHeader: true),
          if (!isIndividual)
            _cell(isUrduGlobal.value ? "رول نمبر" : "Roll No", 60, isHeader: true),
          if (!isIndividual)
            _cell(isUrduGlobal.value ? "طالب علم" : "Student", 180, isHeader: true),
          _cell(isUrduGlobal.value ? "تاریخ" : "Date", 80, isHeader: true),
          Column(
            children: [
              _cell(isUrduGlobal.value ? "صبح کا سبق" : "Morning Sabaq", 330,
                  isHeader: true,
                  bgColor: Colors.green.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell("پارہ/تختی", 110, isHeader: true),
                  _cell("مقدار", 90, isHeader: true),
                  _cell("وقت", 60, isHeader: true),
                  _cell("کیفیت", 70, isHeader: true),
                ],
              )
            ],
          ),
          Column(
            children: [
              _cell(isUrduGlobal.value ? "شام کا سبق" : "Evening Sabaq", 330,
                  isHeader: true,
                  bgColor: Colors.blue.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell("پارہ/تختی", 110, isHeader: true),
                  _cell("مقدار", 90, isHeader: true),
                  _cell("وقت", 60, isHeader: true),
                  _cell("کیفیت", 70, isHeader: true),
                ],
              )
            ],
          ),
          _cell(isUrduGlobal.value ? "شکایات" : "Complaints", 150, isHeader: true, bgColor: Colors.red.withOpacity(0.8), textColor: Colors.white),
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

  Widget _buildDataRow(Map<String, dynamic> h, int srNo) {
    bool isIndividual = selectedStudentId != null;
    final sid = h['student_id']?.toString() ?? '';
    final sObj = _students.firstWhere(
      (st) => st['id'].toString() == sid,
      orElse: () => _allStudentMap[sid] ?? <String, dynamic>{},
    );
    final rollNo = sObj['roll_number']?.toString() ?? sObj['admission_number']?.toString() ?? '';

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(srNo.toString(), 40, bold: true, bgColor: Colors.grey.shade100),
          if (!isIndividual)
            _cell(rollNo, 60),
          if (!isIndividual)
            _cell(_getStudentName(sid), 180,
                textColor: Colors.black87, bgColor: Colors.white),
          _cell(h['date'] ?? '-', 80, bold: true, fontSize: 10),
          Row(
            children: [
              _cell(h['morning_sabaq'] ?? '-', 110),
              _cell(h['morning_qty'] ?? '-', 90),
              _cell(h['morning_time'] ?? '-', 60),
              _cell(translateStatusClean(h['morning_status']), 70,
                  textColor: getStatusColor(h['morning_status'])),
            ],
          ),
          Row(
            children: [
              _cell(h['evening_sabaq'] ?? '-', 110),
              _cell(h['evening_qty'] ?? '-', 90),
              _cell(h['evening_time'] ?? '-', 60),
              _cell(translateStatusClean(h['evening_status']), 70,
                  textColor: getStatusColor(h['evening_status'])),
            ],
          ),
          _cell(h['daily_complaint'] ?? '-', 150, textColor: Colors.red),
        ],
      ),
    );
  }

  List<Widget> _buildTableRows() {
    List<Widget> rows = [];
    final List<Map<String, dynamic>> combinedRecords = [];
    combinedRecords.addAll(_naziraRecords);

    // Identify standalone complaints within date range
    DateTime? sDate;
    DateTime? eDate;
    if (_dateChunks.isNotEmpty && _selectedChunkIndex >= 0 && _selectedChunkIndex < _dateChunks.length) {
      sDate = _dateChunks[_selectedChunkIndex].startDate;
      eDate = _dateChunks[_selectedChunkIndex].endDate;
    } else if (fromDate != null && toDate != null) {
      sDate = _parseRobust(fromDate!);
      eDate = _parseRobust(toDate!);
    }

    if (sDate != null && eDate != null) {
      final Set<String> existingRecKeys = {
        for (var h in combinedRecords)
          "${h['student_id']}_${PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '')}"
      };
      for (var c in _complaints) {
        String sid = c['student_id'].toString();
        if (selectedStudentId != null && sid != selectedStudentId) continue;
        if (selectedStudentId == null && !_students.any((s) => s['id'].toString() == sid)) continue;
        
        DateTime? cDate;
        try { cDate = _parseRobust(c['date'] ?? ''); } catch (_) {}
        if (cDate != null && (cDate.isBefore(sDate) || cDate.isAfter(eDate))) continue;
        
        String dt = PerformanceCalculator.normalizeDate(c['date'] ?? '');
        String key = "${sid}_$dt";
        if (!existingRecKeys.contains(key)) {
          existingRecKeys.add(key);
          combinedRecords.add({
            'student_id': sid,
            'date': dt,
            'morning_sabaq': '-', 'morning_status': '-',
            'evening_sabaq': '-', 'evening_status': '-',
            'is_dummy': 1,
          });
        }
      }
    }

    // Ensure all students in class appear even if no attendance or report exists
    final existingStudentIds = combinedRecords.map((h) => h['student_id']?.toString() ?? '').toSet();
    for (var s in _students) {
      String sid = s['id'].toString();
      if (selectedStudentId != null && sid != selectedStudentId) continue;
      if (!existingStudentIds.contains(sid)) {
        combinedRecords.add({
          'student_id': s['id'],
          'date': toDate ?? intl.DateFormat('dd/MM/yyyy').format(DateTime.now()),
          'morning_sabaq': '-', 'morning_status': '-',
          'evening_sabaq': '-', 'evening_status': '-',
          'is_dummy': 1,
        });
      }
    }

    // STRICT DE-DUPLICATION: Exactly 1 row per student per date
    Map<String, Map<String, dynamic>> uniqueCombined = {};
    for (var r in combinedRecords) {
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
    List<Map<String, dynamic>> sortedRecords = uniqueCombined.values.toList();

    // Sort combined records by student first, then date descending
    sortedRecords.sort((a, b) {
      int sCmp = (a['student_id'] ?? 0).toString().compareTo((b['student_id'] ?? 0).toString());
      if (sCmp != 0) return sCmp;

      DateTime dA = _parseRobust(a['date'] ?? '');
      DateTime dB = _parseRobust(b['date'] ?? '');
      return dB.compareTo(dA); // descending
    });

    int srNo = 1;
    for (var origH in sortedRecords) {
      var h = Map<String, dynamic>.from(origH);
      String dt = h['date']?.toString() ?? '';
      String sid = h['student_id']?.toString() ?? '';
      
      DateTime rowDate = _parseRobust(dt);
      if (rowDate.weekday == DateTime.friday) {
        h['morning_status'] = 'جمعہ';
        h['evening_status'] = 'جمعہ';
        h['morning_sabaq'] = 'جمعہ';
        h['evening_sabaq'] = 'جمعہ';
      }

      if (dt != '-') {
        var comps = _complaints.where((c) => c['student_id'].toString() == sid && (c['date'] == dt || dt.contains(c['date'] ?? ''))).toList();
        if (comps.isNotEmpty) {
          h['daily_complaint'] = comps.map((c) {
             String raw = c['complaint'] ?? '';
             final match = RegExp(r'^\[.*?\]\s*(.*)$', dotAll: true).firstMatch(raw);
             return match != null ? match.group(1) : raw;
          }).join('\n');
        } else {
          h['daily_complaint'] = '-';
        }
      } else {
        h['daily_complaint'] = '-';
      }
      rows.add(_buildDataRow(h, srNo));
      srNo++;
    }

    if (rows.isEmpty) {
      bool isIndividual = selectedStudentId != null;
      double width = isIndividual ? 830 : 950;
      rows.add(Container(
        width: width,
        padding: const EdgeInsets.all(20),
        alignment: Alignment.center,
        decoration: BoxDecoration(border: Border.all(color: Colors.black87, width: 0.5)),
        child: Text(isUrduGlobal.value ? "کوئی ریکارڈ نہیں ملا" : "No record found", style: const TextStyle(color: Colors.red)),
      ));
    }
    return rows;
  }
  Widget _buildSmartPerformance() {
    if (_weeklyStats == null) return const SizedBox();
    final academic = _weeklyStats!['academicScore'];
    final att = _weeklyStats!['attendancePerc'];

    return Padding(
      padding: const EdgeInsets.all(12.0),
      child: NeoBox(
        child: Column(
          children: [
            Text(
                isUrduGlobal.value
                    ? "ہفتہ وار اسمارٹ تجزیہ"
                    : "Weekly Smart Analysis",
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            const Divider(),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildGauge(isUrduGlobal.value ? "حاضری" : "Att.",
                    att.toDouble(), Colors.blue),
                _buildGauge(isUrduGlobal.value ? "تعلیمی" : "Acad.",
                    academic.toDouble(), Colors.green),
              ],
            ),
            if (_aiInsight.isNotEmpty || _isAILoading) ...[
              const SizedBox(height: 15),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                    color: primaryGreen,
                    borderRadius: BorderRadius.circular(10)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.psychology,
                            color: Colors.white, size: 20),
                        const SizedBox(width: 8),
                        Text(
                            isUrduGlobal.value
                                ? "اے آئی ریمارکس (برائے استاد)"
                                : "AI Remarks (for Teacher)",
                            style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 12)),
                      ],
                    ),
                    const Divider(color: Colors.white24),
                    _isAILoading
                        ? const Center(
                            child:
                                LinearProgressIndicator(color: Colors.white24))
                        : Text(_aiInsight,
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                                fontStyle: FontStyle.italic)),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildGauge(String label, double value, Color color) {
    return Column(
      children: [
        Stack(
          alignment: Alignment.center,
          children: [
            SizedBox(
              width: 60,
              height: 60,
              child: CircularProgressIndicator(
                  value: value / 100,
                  strokeWidth: 6,
                  backgroundColor: Colors.grey.shade200,
                  color: color),
            ),
            Text("${value.round()}%",
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
          ],
        ),
        const SizedBox(height: 5),
        Text(label, style: const TextStyle(fontSize: 10, color: Colors.grey)),
      ],
    );
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
              isUrduGlobal.value ? "ناظرہ رپورٹس" : "Nazira Detailed Report"),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
          actions: [
            IconButton(
              icon: const Icon(Icons.print, color: Colors.white),
              onPressed: _printNaziraPdf,
              tooltip: isUrduGlobal.value ? "پرنٹ PDF" : "Print PDF",
            ),
            IconButton(
              icon: const Icon(Icons.sync, color: Colors.white),
              onPressed: () async {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text(isUrduGlobal.value
                        ? "ڈیٹا سنک ہو رہا ہے..."
                        : "Syncing data...")));
                try {
                  await SyncService.runTwoWaySync();
                  if (mounted) {
                    _loadData();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value
                            ? "سنک مکمل ہو گیا!"
                            : "Sync complete!"),
                        backgroundColor: Colors.green));
                  }
                } catch (e) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value
                            ? "سنک میں خرابی آئی"
                            : "Sync failed"),
                        backgroundColor: Colors.red));
                  }
                }
              },
            ),
          ],
        ),
        body: Column(
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: Column(
                children: [
                  DropdownButtonFormField<String>(
                    value: selectedPeriod,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "دورانیہ (Period)" : "Period",
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    items: [
                      DropdownMenuItem(
                          value: 'all',
                          child: Text(isUrduGlobal.value ? "تمام تاریخیں / تمام ریکارڈز (All)" : "All Dates (All Records)")),
                      DropdownMenuItem(
                          value: 'this_month',
                          child: Text(isUrduGlobal.value ? "رواں مہینہ (This Month)" : "This Month")),
                      DropdownMenuItem(
                          value: 'current_week',
                          child: Text(isUrduGlobal.value ? "رواں ہفتہ (Current Week)" : "Current Week")),
                      DropdownMenuItem(
                          value: 'weekly',
                          child: Text(isUrduGlobal.value ? "ہفتہ وار (آخری 7 دن)" : "Weekly (Last 7 Days)")),
                      DropdownMenuItem(
                          value: 'monthly',
                          child: Text(isUrduGlobal.value ? "ماہانہ (آخری 30 دن)" : "Monthly (Last 30 Days)")),
                      DropdownMenuItem(
                          value: 'quarterly',
                          child: Text(isUrduGlobal.value ? "سہ ماہی (آخری 90 دن)" : "Quarterly (Last 90 Days)")),
                      DropdownMenuItem(
                          value: 'annual',
                          child: Text(isUrduGlobal.value ? "سالانہ (آخری 365 دن)" : "Annual (Last 365 Days)")),
                      DropdownMenuItem(
                          value: 'custom',
                          child: Text(isUrduGlobal.value ? "مخصوص تاریخیں (Custom)" : "Custom Date Range")),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setState(() => selectedPeriod = v);
                        _updateDatesForPeriod(v);
                      }
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: _students
                            .any((s) => s['id'].toString() == selectedStudentId)
                        ? selectedStudentId
                        : null,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value
                            ? "طالب علم منتخب کریں"
                            : "Select Student",
                        border: const OutlineInputBorder()),
                    items: [
                      DropdownMenuItem(
                          value: null,
                          child: Text(isUrduGlobal.value
                              ? "تمام طلباء"
                              : "All Students")),
                      ..._students.map((s) => DropdownMenuItem(
                          value: s['id'].toString(),
                          child:
                              Text("${s['name']} - ${s['father_name'] ?? ''}")))
                    ],
                    onChanged: (v) {
                      setState(() => selectedStudentId = v);
                      _loadData();
                    },
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _fromDateController,
                          readOnly: true,
                          decoration: InputDecoration(
                              labelText: isUrduGlobal.value
                                  ? "ابتدائی تاریخ"
                                  : "From Date",
                              prefixIcon: const Icon(Icons.calendar_today),
                              border: const OutlineInputBorder(),
                              contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 0)),
                          onTap: () => _selectDate(context, true),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: TextField(
                          controller: _toDateController,
                          readOnly: true,
                          decoration: InputDecoration(
                              labelText:
                                  isUrduGlobal.value ? "آخری تاریخ" : "To Date",
                              prefixIcon: const Icon(Icons.calendar_today),
                              border: const OutlineInputBorder(),
                              contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 0)),
                          onTap: () => _selectDate(context, false),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            if (!isLoading && _errorMessage == null && selectedStudentId == null)
              _build10DayPaginationBar(),
            Expanded(
              child: isLoading
                  ? _buildLoadingWidget()
                  : _errorMessage != null
                      ? _buildErrorWidget()
                      : selectedStudentId != null
                          ? StudentReportPage(
                              key: ValueKey(selectedStudentId),
                              forceStudentId: selectedStudentId,
                              isEmbedded: true,
                            )
                          : _naziraRecords.isEmpty
                              ? Center(
                                  child: Text(
                                    isUrduGlobal.value
                                        ? "کوئی ریکارڈ موجود نہیں ہے۔"
                                        : "No records found.",
                                    style: const TextStyle(fontSize: 16, color: Colors.grey),
                                  ),
                                )
                              : SingleChildScrollView(
                                  child: Column(
                                    children: [
                                      _buildSmartPerformance(),
                                      Padding(
                                        padding: const EdgeInsets.all(8.0),
                                        child: SingleChildScrollView(
                                          scrollDirection: Axis.horizontal,
                                          physics: const BouncingScrollPhysics(),
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              _buildTableHeader(),
                                              ..._buildTableRows(),
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

  Future<void> _printNaziraPdf() async {
    try {
      final fontData =
          await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final fallbackData =
          await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
      
      final ttf = pw.Font.ttf(fontData);
      final fallbackTtf = pw.Font.ttf(fallbackData);
      
      final bold = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf], fontWeight: pw.FontWeight.bold);
      final normal = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf]);

      final pdf = pw.Document();
      pdf.addPage(pw.MultiPage(
          pageFormat: PdfPageFormat.a4.landscape,
          textDirection: pw.TextDirection.rtl,
          margin: const pw.EdgeInsets.all(15),
          theme: pw.ThemeData.withFont(
            base: ttf,
            fontFallback: [fallbackTtf],
          ),
          header: (ctx) => pw.Column(children: [
                pw.Container(
                  padding: const pw.EdgeInsets.symmetric(vertical: 5),
                  decoration: const pw.BoxDecoration(
                      border: pw.Border(
                          bottom:
                              pw.BorderSide(color: PdfColors.black, width: 2))),
                  child: pw.Row(
                      mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                      children: [
                        pw.Text("مدرسہ اشرف المدارس — فاروقہ، سرگودھا",
                            style: bold.copyWith(fontSize: 12)),
                        pw.Text("ناظرہ رپورٹس",
                            style: bold.copyWith(fontSize: 14)),
                        pw.Text("مدت: $fromDate تا $toDate",
                            style: normal.copyWith(fontSize: 9)),
                      ]),
                ),
                pw.SizedBox(height: 5),
                if (selectedStudentId != null)
                  pw.Container(
                    width: double.infinity,
                    padding: const pw.EdgeInsets.all(5),
                    decoration:
                        const pw.BoxDecoration(color: PdfColors.grey200),
                    child: pw.Row(children: [
                      pw.Text("طالب علم: ", style: bold.copyWith(fontSize: 10)),
                      pw.Text(_getStudentName(selectedStudentId!),
                          style: normal.copyWith(fontSize: 10)),
                    ]),
                  ),
                pw.SizedBox(height: 5),
              ]),
          build: (pw.Context context) {
            bool isIndiv = selectedStudentId != null;
            // Header Row
            List<pw.Widget> hdr = [
              if (!isIndiv)
                _naziraPdfCell("طالب علم", 90,
                    style: bold, bgColor: PdfColors.grey300),
              _naziraPdfCell("تاریخ", 60,
                  style: bold, bgColor: PdfColors.grey300),
              // Morning
              pw.Column(children: [
                _naziraPdfCell("صبح کا سبق", 265,
                    style: bold, bgColor: PdfColors.green300),
                pw.Row(children: [
                  _naziraPdfCell("تفصیل", 215,
                      style: bold, bgColor: PdfColors.grey200),
                  _naziraPdfCell("کیفیت", 50,
                      style: bold, bgColor: PdfColors.grey200),
                ])
              ]),
              // Evening
              pw.Column(children: [
                _naziraPdfCell("شام کا سبق", 265,
                    style: bold, bgColor: PdfColors.blue300),
                pw.Row(children: [
                  _naziraPdfCell("تفصیل", 215,
                      style: bold, bgColor: PdfColors.grey200),
                  _naziraPdfCell("کیفیت", 50,
                      style: bold, bgColor: PdfColors.grey200),
                ])
              ]),
            ];

            List<pw.Widget> allRows = [
              pw.Row(
                  children: hdr,
                  crossAxisAlignment: pw.CrossAxisAlignment.start)
            ];

            for (var h in _naziraRecords) {
              PdfColor mCol = _getPdfStatusColor(h['morning_status']);
              PdfColor eCol = _getPdfStatusColor(h['evening_status']);

              allRows.add(pw.Row(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    if (!isIndiv)
                      _naziraPdfCell(
                          _getStudentName(h['student_id'].toString()), 90),
                    _naziraPdfCell(h['date'] ?? '-', 60),
                    // Morning
                    _naziraPdfCell(h['morning_sabaq'] ?? '-', 215),
                    _naziraPdfCell(translateStatusClean(h['morning_status']), 50,
                        textColor: mCol),
                    // Evening
                    _naziraPdfCell(h['evening_sabaq'] ?? '-', 215),
                    _naziraPdfCell(translateStatusClean(h['evening_status']), 50,
                        textColor: eCol),
                  ]));
            }

            return allRows;
          }));

      await Printing.layoutPdf(
        onLayout: (format) async => pdf.save(),
        name: 'Nazira_Report.pdf',
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text("Print Error: $e")));
      }
    }
  }

  pw.Widget _naziraPdfCell(String text, double w,
      {pw.TextStyle? style, PdfColor? bgColor, PdfColor? textColor}) {
    return pw.Container(
      width: w,
      padding: const pw.EdgeInsets.all(3),
      decoration: pw.BoxDecoration(
          color: bgColor,
          border: pw.Border.all(color: PdfColors.grey600, width: 0.5)),
      alignment: pw.Alignment.center,
      child: pw.Text(text,
          textAlign: pw.TextAlign.center,
          style: style?.copyWith(color: textColor) ??
              pw.TextStyle(fontSize: 8, color: textColor)),
    );
  }

  PdfColor _getPdfStatusColor(String? status) {
    if (status == 'Excellent') return PdfColors.green800;
    if (status == 'Good') return PdfColors.blue800;
    if (status == 'Weak') return PdfColors.red800;
    if (status == 'Average') return PdfColors.orange800;
    return PdfColors.black;
  }
}

