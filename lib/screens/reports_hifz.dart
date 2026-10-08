// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'package:flutter/material.dart';
import '../utils/performance_helper.dart';


import 'package:intl/intl.dart' as intl;

import 'package:pdf/pdf.dart';

import 'package:pdf/widgets.dart' as pw;

import 'package:printing/printing.dart';

import '../db_helper.dart';

import '../utils/globals.dart';
import '../utils/system_type_global.dart';

import '../utils/ai_service.dart';

import '../widgets/app_drawer.dart';

import '../utils/sync_service.dart';

import '../widgets/neo_box.dart';


import 'package:screenshot/screenshot.dart';

import 'dashboard.dart';

import 'filtered_hifz_report.dart';
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

class ReportsHifzPage extends StatefulWidget {
  final String? className;
  const ReportsHifzPage({super.key, this.className});

  @override
  _ReportsHifzPageState createState() => _ReportsHifzPageState();
}

class _ReportsHifzPageState extends State<ReportsHifzPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  List<Map<String, dynamic>> _hifzRecords = [];
  List<Map<String, dynamic>> _students = [];
  Map<String, Map<String, dynamic>> _allStudentMap = {};
  List<String> _classes = [];
  String? _selectedClass;
  String _selectedHifzSection = 'all';
  List<Map<String, dynamic>> _complaints = [];
  bool isLoading = true;
  String _loadingStep = '';
  String? _errorMessage;

  List<DateChunk> _dateChunks = [];
  int _selectedChunkIndex = 0;
  List<Map<String, dynamic>> _cachedRawHifz = [];
  List<Map<String, dynamic>> _cachedRawAtt = [];

  final ScreenshotController screenshotController = ScreenshotController();
  String? fromDate;
  String? toDate;
  String? selectedStudentId;
  String selectedPeriod = 'current_week';

  Map<String, dynamic>? _weeklyStats;
  String _aiInsight = "";
  bool _isAILoading = false;

  final _fromDateController = TextEditingController();
  final _toDateController = TextEditingController();

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
    _selectedClass = widget.className;
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
          ? "طلبہ اور ریکارڈز کی معلومات حاصل کی جا رہی ہیں..."
          : "Fetching students and records...";
    });
    await Future.delayed(const Duration(milliseconds: 30));

    try {
      final db = DBHelper();
      final allClasses = await db.getClasses();
      List<Map<String, dynamic>> allMaktabClasses = [];
      try {
        allMaktabClasses = await db.getMaktabClasses();
      } catch (_) {}
      final cSet = <String>{};
      for (var c in [...allClasses, ...allMaktabClasses]) {
        final name = c['name']?.toString().trim() ?? '';
        if (name.isNotEmpty) cSet.add(name);
      }
      _classes = cSet.toList()..sort();

      final currentSys = SystemTypeGlobal.currentSystemType.value;
      final bool isMasjid = (currentSys == 'Masjid');
      final dbHelper = DBHelper();
      final databaseInstance = await dbHelper.database;

      final String sysCond = isMasjid
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      const String activeStatusCond =
          "(is_deleted = 0 OR is_deleted IS NULL) AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))";

      List<Map<String, dynamic>> rawAllStudents = [];
      if (isMasjid) {
        final stds = await databaseInstance.rawQuery(
          "SELECT * FROM students WHERE $activeStatusCond AND system_type = 'Masjid' ORDER BY roll_number ASC, name ASC",
        );
        final maktabStds = await databaseInstance.rawQuery(
          "SELECT * FROM maktab_students WHERE $activeStatusCond ORDER BY roll_number ASC, name ASC",
        );
        rawAllStudents = [...stds, ...maktabStds];
      } else {
        rawAllStudents = await databaseInstance.rawQuery(
          "SELECT * FROM students WHERE $activeStatusCond AND $sysCond ORDER BY roll_number ASC, name ASC",
        );
      }

      _allStudentMap = {
        for (var s in rawAllStudents) (s['id']?.toString() ?? ''): s,
      };

      final Set<String> seenIds = {};
      final List<Map<String, dynamic>> validStudents = [];

      for (var s in rawAllStudents) {
        String cName = (s['class_name']?.toString() ?? s['class']?.toString() ?? '').toLowerCase();
        String normCName = _normalizeClassName(s['class_name'] ?? s['class']);

        bool isClassMatch = true;
        if (_selectedClass != null && _selectedClass!.trim().isNotEmpty) {
          String reqClass = _selectedClass!.trim().toLowerCase();
          String normReqClass = _normalizeClassName(_selectedClass);
          isClassMatch = (normCName == normReqClass ||
              cName == reqClass ||
              (s['class_name']?.toString().trim() == _selectedClass?.trim()) ||
              (s['class']?.toString().trim() == _selectedClass?.trim()));
        }

        final idStr = s['id']?.toString();
        if (isClassMatch && idStr != null && !seenIds.contains(idStr)) {
          seenIds.add(idStr);
          validStudents.add(s);
        }
      }

      if (_selectedHifzSection != 'all') {
        _students = validStudents.where((s) {
          final sec = (s['hifz_section'] ?? 'regular').toString().trim();
          return (sec.isEmpty ? 'regular' : sec) == _selectedHifzSection;
        }).toList();
      } else {
        _students = validStudents;
      }
      final studentIds = _students.map((s) => s['id']?.toString()).toSet();
      if (selectedStudentId != null && !studentIds.contains(selectedStudentId)) {
        selectedStudentId = null;
      }

      final allComplaints = await db.getComplaints();
      _complaints = allComplaints;

      final settings = await db.getSettings();
      int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;

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

      if (eDate.isBefore(sDate)) {
        final tmp = sDate;
        sDate = eDate;
        eDate = tmp;
      }

      // 30-Day Chunk Generation (With Full Period Option for 100% Web Parity)
      List<DateChunk> chunks = [];
      final totalDays = eDate.difference(sDate).inDays + 1;
      final fullStartStr = intl.DateFormat('dd/MM/yyyy').format(sDate);
      final fullEndStr = intl.DateFormat('dd/MM/yyyy').format(eDate);

      if (totalDays <= 30) {
        chunks.add(DateChunk(
          pageIndex: 1,
          startDate: sDate,
          endDate: eDate,
          label: isUrduGlobal.value ? "مرحلہ 1 ($fullStartStr تا $fullEndStr)" : "Phase 1 ($fullStartStr to $fullEndStr)",
        ));
      } else {
        // Option 0: Full Period (Matches Web Panel 100%)
        chunks.add(DateChunk(
          pageIndex: 0,
          startDate: DateTime(sDate.year, sDate.month, sDate.day, 0, 0, 0),
          endDate: DateTime(eDate.year, eDate.month, eDate.day, 23, 59, 59, 999),
          label: isUrduGlobal.value ? "مکمل عرصہ ($fullStartStr تا $fullEndStr)" : "Full Period ($fullStartStr to $fullEndStr)",
        ));

        DateTime cur = sDate;
        int pIndex = 1;
        while (!cur.isAfter(eDate)) {
          DateTime chunkEnd = cur.add(const Duration(days: 29));
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
        _selectedChunkIndex = 0; // Default to full period or phase 1
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

      List<Map<String, dynamic>> allHifz = [];
      List<Map<String, dynamic>> attRows = [];
      List<Map<String, dynamic>> extraAtt = [];

      if (sidsList.isNotEmpty) {
        // Query in chunks of 500 to avoid SQLite variable limits while supporting all 50+ students
        for (var i = 0; i < sidsList.length; i += 500) {
          final end = (i + 500 < sidsList.length) ? i + 500 : sidsList.length;
          final subList = sidsList.sublist(i, end);
          final placeholders = List.filled(subList.length, '?').join(',');
          final partHifz = await database.query(
            'hifz_record',
            where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: subList,
          );
          allHifz.addAll(partHifz);
          final partAtt = await database.query(
            'attendance',
            where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: subList,
          );
          attRows.addAll(partAtt);
          try {
            final partMaktabAtt = await database.query(
              'maktab_attendance',
              where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
              whereArgs: subList,
            );
            extraAtt.addAll(partMaktabAtt);
          } catch (_) {}
        }
      } else {
        allHifz = await db.getHifzRecords();
        attRows = await database.query('attendance');
      }
      _cachedRawHifz = allHifz;
      _cachedRawAtt = [...attRows, ...extraAtt];

      await _processActiveChunk();
    } catch (e, stack) {
      debugPrint("Load Hifz Report Error: $e\n$stack");
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

      // Only hifz records within active 10-day chunk
      List<Map<String, dynamic>> filtered = [];
      for (var h in _cachedRawHifz) {
        if (selectedStudentId != null && h['student_id'].toString() != selectedStudentId) {
          continue;
        }
        if (selectedStudentId == null) {
          bool isStudentValid = studentIdSet.isEmpty || studentIdSet.contains(h['student_id']?.toString());
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
          hifzRecords: filtered,
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
          filtered, relevantAttendance, _students, 'hifz');
      
      filtered.sort((a, b) {
        DateTime dA = _parseRobust(a['date'] ?? '');
        DateTime dB = _parseRobust(b['date'] ?? '');
        int cmp = dB.compareTo(dA); // descending (newest first)
        if (cmp != 0) return cmp;
        return (a['student_id'] ?? 0).toString().compareTo((b['student_id'] ?? 0).toString());
      });

      _hifzRecords = filtered;

      // Ensure _students is populated with all active students who have records in this report chunk
      final existingSidSet = _students.map((s) => s['id']?.toString() ?? '').toSet();
      bool addedExtra = false;
      for (var h in filtered) {
        final sid = h['student_id']?.toString();
        if (sid != null && sid.isNotEmpty && !existingSidSet.contains(sid)) {
          existingSidSet.add(sid);
          final stdObj = _allStudentMap[sid] ?? {
            'id': sid,
            'name': h['student_name'] ?? _getStudentName(sid),
            'roll_number': h['roll_number'] ?? '',
            'admission_number': h['admission_number'] ?? '',
            'father_name': h['father_name'] ?? '',
            'class_name': h['class_name'] ?? _selectedClass ?? '',
          };
          _students.add(stdObj);
          _allStudentMap[sid] = stdObj;
          addedExtra = true;
        }
      }
      if (addedExtra) {
        _students.sort((a, b) => (a['name']?.toString() ?? '').compareTo(b['name']?.toString() ?? ''));
      }

      _errorMessage = null;
    } catch (e, stack) {
      debugPrint("Process Active Hifz Chunk Error: $e\n$stack");
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

  Widget _build30DayPaginationBar() {
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
                label: Text(isUrduGlobal.value ? "سابقہ 30 دن" : "Prev 30 Days"),
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
                    "${isUrduGlobal.value ? '30 روزہ مرحلہ' : '30-Day Phase'} ${_selectedChunkIndex + 1} / ${_dateChunks.length}\n${intl.DateFormat('dd/MM/yyyy').format(currentChunk.startDate)} تا ${intl.DateFormat('dd/MM/yyyy').format(currentChunk.endDate)}",
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
                label: Text(isUrduGlobal.value ? "اگلے 30 دن" : "Next 30 Days"),
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
                  : (isUrduGlobal.value ? "حفظ تعلیمی ریکارڈز کا جائزہ لیا جا رہا ہے..." : "Processing Hifz records..."),
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

      });

      _loadData();

    }

  }



  String _getStudentName(String sid) {
    final cleanSid = sid.trim();
    if (cleanSid.isEmpty) return "Unknown";
    var s = _students.firstWhere(
      (st) => (st['id'] ?? '').toString().trim() == cleanSid,
      orElse: () => _allStudentMap[cleanSid] ?? <String, dynamic>{},
    );
    if (s.isEmpty) {
      for (final entry in _allStudentMap.values) {
        if ((entry['id'] ?? '').toString().trim() == cleanSid) {
          s = entry;
          break;
        }
      }
    }
    final bool isManzil = (s['hifz_section'] == 'manzil_gardan');
    final String secBadge = isManzil ? " [منزل/گردان]" : "";
    if (s.isNotEmpty && s['name'] != null && s['name'].toString().isNotEmpty) {
      String fName = s['father_name']?.toString().trim() ?? '';
      return fName.isNotEmpty ? "${s['name']}$secBadge ($fName)" : "${s['name']}$secBadge";
    }
    return "ID: $cleanSid$secBadge";
  }

  String _getRollNo(String sid, [Map<String, dynamic>? hRecord]) {
    final cleanSid = sid.trim();
    if (cleanSid.isEmpty) return '-';

    // 1. Check record itself
    if (hRecord != null) {
      for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
        final val = (hRecord[key] ?? '').toString().trim();
        if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
          return val;
        }
      }
    }

    // 2. Search in _students
    var s = _students.firstWhere(
      (st) => (st['id'] ?? '').toString().trim() == cleanSid,
      orElse: () => <String, dynamic>{},
    );

    // 3. Search in _allStudentMap
    if (s.isEmpty) {
      s = _allStudentMap[cleanSid] ?? <String, dynamic>{};
    }
    if (s.isEmpty) {
      for (final entry in _allStudentMap.values) {
        if ((entry['id'] ?? '').toString().trim() == cleanSid) {
          s = entry;
          break;
        }
      }
    }

    // 4. Check roll number and admission number columns
    if (s.isNotEmpty) {
      for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
        final val = (s[key] ?? '').toString().trim();
        if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
          return val;
        }
      }
    }

    return '-';
  }



  String translateStatusClean(String? status) {
    if (status == null) return '-';
    if (status == 'جمعہ') return 'جمعہ';
    if (status == 'مشق') return 'مشق';
    if (status.contains('نہیں سنایا') || status.contains('نہیں سنائی')) return status;
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
    if (status == 'مشق') return Colors.teal.shade700;
    if (status.contains('استثناء') || status.contains('Exempt')) return Colors.teal.shade700;
    if (status.contains('نہیں سنایا') || status.contains('نہیں سنائی')) return Colors.orange.shade900;
    if (status == 'Excellent' || status == 'ممتاز') return Colors.green;
    if (status == 'Good' || status == 'بہتر') return Colors.blue;
    if (status == 'Weak' || status == 'کمزور') return Colors.red;
    if (status == 'Average' || status == 'معمولی') return Colors.orange;
    if (status.contains('غیر حاضر') || status == 'Absent') return Colors.red.shade900;
    if (status.contains('رخصت') || status == 'Leave') return Colors.orange.shade800;
    if (status.contains('ناغہ')) return Colors.red.shade700;
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
      'Three Quarter': 'پون',
      'Full': 'مکمل',
      '1.5 Parah': 'ڈیڑھ پارہ',
      '2 Parah': '2 پارے',
      '2.5 Parah': 'ڈھائی پارے',
      '3 Parah': '3 پارے'
    };
    return map[clean] ?? clean;
  }

  Widget _cell(String text, double width,
      {Color? bgColor,
      Color? textColor,
      bool isHeader = false,
      bool bold = false,
      double? fontSize,
      Widget? child}) {
    return Container(
      width: width,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
      decoration: BoxDecoration(
        color: bgColor ?? (isHeader ? Colors.grey.shade200 : Colors.white),
        border: Border.all(color: Colors.black87, width: 0.5),
      ),
      alignment: Alignment.center,
      child: child ?? Text(
        text,
        textAlign: TextAlign.center,
        softWrap: true,
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
          _cell(isUrduGlobal.value ? "نمبر شمار" : "Sr No", 45, isHeader: true),
          if (!isIndividual)
            _cell(isUrduGlobal.value ? "رول نمبر" : "Roll No", 65, isHeader: true),
          if (!isIndividual)
            _cell(isUrduGlobal.value ? "طالب علم" : "Student", 190,
                isHeader: true),
          _cell(isUrduGlobal.value ? "تاریخ" : "Date", 90, isHeader: true),
          Column(
            children: [
              _cell(isUrduGlobal.value ? "سبق (نیا)" : "Sabaq (New)", 185,
                  isHeader: true,
                  bgColor: Colors.green.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell(isUrduGlobal.value ? "پارہ" : "Para", 65, isHeader: true),
                  _cell(isUrduGlobal.value ? "لائنیں" : "Lines", 60, isHeader: true),
                  _cell(isUrduGlobal.value ? "کیفیت" : "Grade", 60, isHeader: true),
                ],
              )
            ],
          ),
          Column(
            children: [
              _cell(
                  isUrduGlobal.value ? "سبقی (حالیہ)" : "Sabqi (Previous)",
                  365,
                  isHeader: true,
                  bgColor: Colors.blue.withOpacity(0.8),
                  textColor: Colors.white),
              Row(
                children: [
                  _cell(isUrduGlobal.value ? "تفصیل" : "Details", 130, isHeader: true),
                  _cell(isUrduGlobal.value ? "مقدار" : "Qty", 75, isHeader: true),
                  _cell(isUrduGlobal.value ? "کیفیت" : "Grade", 60, isHeader: true),
                  _cell(isUrduGlobal.value ? "سننے والا" : "Listener", 100, isHeader: true),
                ],
              )
            ],
          ),
          Column(
            children: [
              _cell(isUrduGlobal.value ? "منزل (پختہ)" : "Manzil", 415,
                  isHeader: true,
                  bgColor: Colors.orange.withOpacity(0.8),
                  textColor: Colors.black),
              Row(
                children: [
                  _cell(isUrduGlobal.value ? "تفصیل" : "Details", 180, isHeader: true),
                  _cell(isUrduGlobal.value ? "مقدار" : "Qty", 75, isHeader: true),
                  _cell(isUrduGlobal.value ? "کیفیت" : "Grade", 60, isHeader: true),
                  _cell(isUrduGlobal.value ? "سننے والا" : "Listener", 100, isHeader: true),
                ],
              )
            ],
          ),
          _cell(isUrduGlobal.value ? "شکایات" : "Complaints", 160, isHeader: true, bgColor: Colors.red.withOpacity(0.8), textColor: Colors.white),
        ],
      ),
    );
  }



  String cleanUrduString(String? text) {
    if (text == null || text.trim().isEmpty || text == 'null') return '-';
    return text
        .replaceAll('(3/4)', '')
        .replaceAll('(1/2)', '')
        .replaceAll('(1/4)', '')
        .replaceAll('(1)', '')
        .replaceAll('((', '(')
        .replaceAll('))', ')')
        .replaceAll(RegExp(r'\s+'), ' ')
        .replaceAllMapped(RegExp(r'(\d|[\u0600-\u06FF])\('), (match) => "${match.group(1)} (")
        .trim();
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



  Widget _buildDataRow(Map<String, dynamic> h, int srNo) {

    bool isIndividual = selectedStudentId != null;

    final sid = h['student_id']?.toString() ?? '';
    final rollNo = _getRollNo(sid, h);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cell(srNo.toString(), 45, bold: true, bgColor: Colors.grey.shade100),
          if (!isIndividual)
            _cell(rollNo, 65),
          if (!isIndividual)
            _cell(_getStudentName(sid), 190, bold: true),

          _cell(h['date'] ?? '-', 90, bold: true, fontSize: 10),

          _cell(cleanUrduString(h['sabaq_para']), 65),

          _cell(h['sabaq_lines'] ?? '-', 60),

          _gradeWithTimeCell(translateStatusClean(h['sabaq_grade']), h['sabaq_time']?.toString(), 60, textColor: getStatusColor(h['sabaq_grade'])),

          _cell(cleanUrduString(h['sabqi_para']), 130),

          _cell(translateQtyClean(h['sabqi_qty']), 75),

          _gradeWithTimeCell(translateStatusClean(h['sabqi_grade']), h['sabqi_time']?.toString(), 60, textColor: getStatusColor(h['sabqi_grade'])),

          _cell(
              (h['sabqi_listener']?.toString().isEmpty ?? true)
                  ? '-'
                  : h['sabqi_listener'],
              100,
              fontSize: 10),

          _cell(cleanUrduString(h['manzil_details']), 180),

          _cell(translateQtyClean(h['manzil_total_qty']), 75),

          _gradeWithTimeCell(translateStatusClean(h['manzil_grade']), h['manzil_time']?.toString(), 60, textColor: getStatusColor(h['manzil_grade'])),

          _cell(
              (h['manzil_listener']?.toString().isEmpty ?? true)
                  ? '-'
                  : h['manzil_listener'],
              100,
              fontSize: 10),

          _cell(h['daily_complaint'] ?? '-', 160, textColor: Colors.red),



        ],

      ),

    );

  }





  List<Widget> _buildTableRows() {

    List<Widget> rows = [];

    final List<Map<String, dynamic>> combinedRecords = [];

    combinedRecords.addAll(_hifzRecords);

    // Build comprehensive list of all students participating in this report
    final Map<String, Map<String, dynamic>> reportStudentMap = {};
    for (var s in _students) {
      final idStr = s['id']?.toString();
      if (idStr != null && idStr.isNotEmpty) {
        reportStudentMap[idStr] = s;
      }
    }
    for (var r in combinedRecords) {
      final sid = r['student_id']?.toString();
      if (sid != null && sid.isNotEmpty && !reportStudentMap.containsKey(sid)) {
        reportStudentMap[sid] = _allStudentMap[sid] ?? {
          'id': sid,
          'name': r['student_name'] ?? _getStudentName(sid),
          'roll_number': r['roll_number'] ?? '',
          'admission_number': r['admission_number'] ?? '',
          'father_name': r['father_name'] ?? '',
          'class_name': r['class_name'] ?? _selectedClass ?? '',
        };
      }
    }
    final List<Map<String, dynamic>> effectiveStudents = reportStudentMap.values.toList();



    // Frequency maps for weekly/period summary at bottom
    Map<String, int> freqSabaq = {};
    Map<String, int> freqSabqi = {};
    Map<String, int> freqManzil = {};
    Map<String, List<String>> datesSabaqNaaghah = {};
    Map<String, List<String>> datesSabqiNaaghah = {};
    Map<String, List<String>> datesManzilNaaghah = {};

    int dailyMissedSabaq = 0;

    int dailyMissedSabqi = 0;

    int dailyMissedManzil = 0;

    int dailyAbsentCount = 0;

    int dailyLeaveCount = 0;

    int dailyComplaints = 0;

    String lastDateFormatted = "";



    // Daily per-period lists for detailed navigation
    List<String> dailyAbsentSids = [];
    List<String> dailyLeaveSids = [];
    List<String> dailySabaqSenderSids = [];
    List<String> dailySabaqNotRecitedSids = [];
    List<String> dailySabaqMissSids = [];
    List<String> dailySabaqAbsentSids = [];
    List<String> dailySabaqLeaveSids = [];
    List<String> dailySabqiSenderSids = [];
    List<String> dailySabqiNotRecitedSids = [];
    List<String> dailySabqiMissSids = [];
    List<String> dailySabqiAbsentSids = [];
    List<String> dailySabqiLeaveSids = [];
    List<String> dailyManzilSenderSids = [];
    List<String> dailyManzilNotRecitedSids = [];
    List<String> dailyManzilMissSids = [];
    List<String> dailyManzilAbsentSids = [];
    List<String> dailyManzilLeaveSids = [];
    List<String> dailyComplaintSids = [];



    // Build robust attendance lookup map connecting _cachedRawAtt
    final Map<String, Map<String, String>> attLookup = {};
    for (var a in _cachedRawAtt) {
      String sid = a['student_id']?.toString().trim() ?? '';
      String rawDt = a['date']?.toString().trim() ?? '';
      if (sid.isEmpty || rawDt.isEmpty) continue;
      String nDt = PerformanceCalculator.normalizeDate(rawDt);

      String rawSt = (a['status'] ?? '').toString().trim().toUpperCase();
      String s1 = (a['status_1'] ?? '').toString().trim().toUpperCase();
      String s2 = (a['status_2'] ?? '').toString().trim().toUpperCase();
      String s3 = (a['status_3'] ?? '').toString().trim().toUpperCase();
      String shift = (a['shift'] ?? '').toString().trim().toLowerCase();

      String resolveCode(String s) {
        if (s.contains('ABSENT') || s.contains('غیر حاضر') || s.contains('غير حاضر') || s.contains('غیرحاضر') || s.contains('غيرحاضر') || s.contains('غائب') || s == 'A' || s == 'غ') return 'A';
        if (s.contains('LEAVE') || s.contains('رخصت') || s.contains('چھٹی') || s == 'L' || s == 'ر') return 'L';
        if (s.contains('PRESENT') || s.contains('حاضر') || s == 'P' || s == 'ح') return 'P';
        if (s.isNotEmpty && s != '-') return 'P';
        return '';
      }

      String mainCode = resolveCode(rawSt);
      String c1 = resolveCode(s1);
      String c2 = resolveCode(s2);
      String c3 = resolveCode(s3);

      if (mainCode.isEmpty) {
        if (c1.isNotEmpty) mainCode = c1;
        else if (c2.isNotEmpty) mainCode = c2;
        else if (c3.isNotEmpty) mainCode = c3;
      }
      String sNorm = shift.replaceAll(' ', '').replaceAll('_', '');
      String pShift = '';
      if (sNorm.contains('morning') || sNorm == '1' || sNorm.contains('sabaq') || sNorm.contains('صبح') || sNorm == 'lesson1') {
        pShift = '1';
      } else if (sNorm.contains('after') || sNorm == '2' || sNorm.contains('sabqi') || sNorm.contains('اشراق') || sNorm == 'lesson2') {
        pShift = '2';
      } else if (sNorm.contains('noon') || sNorm == '3' || sNorm.contains('manzil') || sNorm.contains('ظہر') || sNorm == 'lesson3') {
        pShift = '3';
      } else if (sNorm.contains('evening') || sNorm == '4' || sNorm.contains('عصر') || sNorm.contains('مغرب') || sNorm == 'lesson4') {
        pShift = '4';
      }

      if (pShift == '1') {
        c1 = mainCode;
      } else if (pShift == '2') {
        c2 = mainCode;
      } else if (pShift == '3') {
        c3 = mainCode;
      } else if (pShift.isEmpty) {
        if (c1.isEmpty) c1 = mainCode;
        if (c2.isEmpty) c2 = mainCode;
        if (c3.isEmpty) c3 = mainCode;
      }

      void recordLookup(String k) {
        var existing = attLookup[k];
        if (existing == null) {
          attLookup[k] = {
            'main': (pShift == '1' || pShift.isEmpty) ? mainCode : '',
            'sabaq': c1,
            'sabqi': c2,
            'manzil': c3,
          };
        } else {
          if (c1.isNotEmpty) existing['sabaq'] = c1;
          if (c2.isNotEmpty) existing['sabqi'] = c2;
          if (c3.isNotEmpty) existing['manzil'] = c3;
          if (pShift == '1' || (pShift.isEmpty && mainCode.isNotEmpty) || (existing['main']?.isEmpty ?? true)) {
            if (c1.isNotEmpty) {
              existing['main'] = c1;
            } else if (mainCode.isNotEmpty) {
              existing['main'] = mainCode;
            }
          }
        }
      }

      recordLookup("${sid}_$rawDt");
      if (nDt.isNotEmpty && nDt != '1970-01-01') {
        recordLookup("${sid}_$nDt");
      }
      try {
        DateTime d = _parseRobust(rawDt);
        String isoDate = "${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";
        String dMyDate = "${d.day}/${d.month}/${d.year}";
        String ddMMyyyy = "${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}";
        String dd_MM_yyyy = "${d.day.toString().padLeft(2, '0')}-${d.month.toString().padLeft(2, '0')}-${d.year}";
        recordLookup("${sid}_$isoDate");
        recordLookup("${sid}_$dMyDate");
        recordLookup("${sid}_$ddMMyyyy");
        recordLookup("${sid}_$dd_MM_yyyy");
      } catch (_) {}
    }

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
      DateTime now = DateTime.now();
      DateTime todayEnd = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
      DateTime maxD = eDate.isAfter(todayEnd) ? todayEnd : eDate;
      final Set<String> existingRecKeys = {
        for (var r in combinedRecords)
          "${r['student_id']}_${PerformanceCalculator.normalizeDate(r['date']?.toString() ?? '')}"
      };
      for (DateTime d = sDate; !d.isAfter(maxD); d = DateTime(d.year, d.month, d.day + 1)) {
        String isoDate = intl.DateFormat('yyyy-MM-dd').format(d);
        for (var s in effectiveStudents) {
          if (selectedStudentId != null && s['id'].toString() != selectedStudentId) continue;
          String sid = s['id']?.toString() ?? '';
          if (sid.isEmpty) continue;
          String recKey = "${sid}_$isoDate";
          if (!existingRecKeys.contains(recKey)) {
             existingRecKeys.add(recKey);
             var attInfo = attLookup["${sid}_$isoDate"];
             String aStat = attInfo?['main'] ?? '';
             String as1 = attInfo?['sabaq'] ?? aStat;
             String as2 = attInfo?['sabqi'] ?? aStat;
             String as3 = attInfo?['manzil'] ?? aStat;

             String sabaqPara = as1 == 'A' ? 'غیر حاضر' : (as1 == 'L' ? 'رخصت' : '-');
             String sabaqGrade = as1 == 'A' ? 'Absent' : (as1 == 'L' ? 'Leave' : '-');
             String sabqiPara = as2 == 'A' ? 'غیر حاضر' : (as2 == 'L' ? 'رخصت' : '-');
             String sabqiGrade = as2 == 'A' ? 'Absent' : (as2 == 'L' ? 'Leave' : '-');
             String manzilDetails = as3 == 'A' ? 'غیر حاضر' : (as3 == 'L' ? 'رخصت' : '-');
             String manzilGrade = as3 == 'A' ? 'Absent' : (as3 == 'L' ? 'Leave' : '-');
             String attSt = (aStat == 'A' || as1 == 'A' || as2 == 'A' || as3 == 'A')
                 ? 'غیر حاضر'
                 : ((aStat == 'L' || as1 == 'L' || as2 == 'L' || as3 == 'L') ? 'رخصت' : (aStat == 'P' ? 'حاضر' : '-'));

             combinedRecords.add({
                'student_id': s['id'],
                'date': isoDate,
                'sabaq_para': sabaqPara, 'sabaq_lines': '-', 'sabaq_grade': sabaqGrade,
                'sabqi_para': sabqiPara, 'sabqi_qty': '-', 'sabqi_grade': sabqiGrade, 'sabqi_listener': '-',
                'manzil_details': manzilDetails, 'manzil_total_qty': '-', 'manzil_grade': manzilGrade, 'manzil_listener': '-',
                'att_status': attSt,
                'is_dummy': 1,
             });
          }
        }
      }
    }

    // STRICT DE-DUPLICATION: Ensure exactly 1 row per student per date
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
          final existing = uniqueCombined[key]!;
          if (existing['is_dummy'] == 1 && r['is_dummy'] != 1) {
            var copy = Map<String, dynamic>.from(r);
            copy['date'] = rNorm;
            uniqueCombined[key] = copy;
          } else if (existing['is_dummy'] == 1 && (existing['att_status'] == null || existing['att_status'] == '-') && r['att_status'] != null && r['att_status'] != '-') {
            var copy = Map<String, dynamic>.from(r);
            copy['date'] = rNorm;
            uniqueCombined[key] = copy;
          }
        }
      }
    }
    List<Map<String, dynamic>> sortedRecords = uniqueCombined.values.toList();

    // Sort combined records by date descending, then record presence, then student
    sortedRecords.sort((a, b) {
      DateTime dA = _parseRobust(a['date'] ?? '');
      DateTime dB = _parseRobust(b['date'] ?? '');
      int dCmp = dB.compareTo(dA);
      if (dCmp != 0) return dCmp;
      return (a['student_id'] ?? 0).toString().compareTo((b['student_id'] ?? 0).toString());
    });



    int srNo = 1;

    DateTime? currentDate;

    Set<String> processedDates = {};



    void addDailySummary() {

      if (lastDateFormatted.isNotEmpty && selectedStudentId == null) {

        final dSabaqMiss = dailyMissedSabaq;

        final dSabqiMiss = dailyMissedSabqi;

        final dManzilMiss = dailyMissedManzil;

        final dAbsent = dailyAbsentCount;

        final dLeave = dailyLeaveCount;

        final dComplaints = dailyComplaints;

        final ldFormatted = lastDateFormatted;



        // Capture all per-period lists

        final capAbsentSids = List<String>.from(dailyAbsentSids);

        final capLeaveSids = List<String>.from(dailyLeaveSids);

        final capSabaqSenders = List<String>.from(dailySabaqSenderSids);

        final capSabaqNotRecited = List<String>.from(dailySabaqNotRecitedSids);

        final capSabaqMiss = List<String>.from(dailySabaqMissSids);

        final capSabaqAbsent = List<String>.from(dailySabaqAbsentSids);

        final capSabaqLeave = List<String>.from(dailySabaqLeaveSids);

        final capSabqiSenders = List<String>.from(dailySabqiSenderSids);

        final capSabqiNotRecited = List<String>.from(dailySabqiNotRecitedSids);

        final capSabqiMiss = List<String>.from(dailySabqiMissSids);

        final capSabqiAbsent = List<String>.from(dailySabqiAbsentSids);

        final capSabqiLeave = List<String>.from(dailySabqiLeaveSids);

        final capManzilSenders = List<String>.from(dailyManzilSenderSids);

        final capManzilNotRecited = List<String>.from(dailyManzilNotRecitedSids);

        final capManzilMiss = List<String>.from(dailyManzilMissSids);

        final capManzilAbsent = List<String>.from(dailyManzilAbsentSids);

        final capManzilLeave = List<String>.from(dailyManzilLeaveSids);

        final capComplaintSids = List<String>.from(dailyComplaintSids);



        void navBySids(String title, List<String> sids) {
          final fRecs = <Map<String, dynamic>>[];
          final List<Map<String, dynamic>> matchedStudents = [];
          for (var sid in sids) {
            var st = _students.firstWhere(
              (s) => s['id']?.toString() == sid,
              orElse: () => _allStudentMap[sid] ?? <String, dynamic>{},
            );
            if (st.isNotEmpty && !matchedStudents.any((m) => m['id']?.toString() == sid)) {
              matchedStudents.add(st);
            }
          }

          for (var s in matchedStudents) {
            final sid = s['id'].toString();
            var h = combinedRecords.firstWhere(
              (r) => r['student_id'].toString() == sid &&
                  (() {
                    try {
                      final dt1 = _parseRobust(r['date']?.toString() ?? '');
                      final dt2 = _parseRobust(ldFormatted);
                      return dt1.year == dt2.year && dt1.month == dt2.month && dt1.day == dt2.day;
                    } catch (_) {
                      return false;
                    }
                  })(),
              orElse: () => {},
            );

            h = Map<String, dynamic>.from(h);
            if (h.isEmpty) {
              var aInfo = attLookup["${sid}_$ldFormatted"] ?? attLookup["${sid}_${PerformanceCalculator.normalizeDate(ldFormatted)}"];
              String aSt = aInfo?['main'] ?? '';
              String s1 = (aInfo?['sabaq'] != null && aInfo!['sabaq']!.isNotEmpty) ? aInfo['sabaq']! : aSt;
              String s2 = (aInfo?['sabqi'] != null && aInfo!['sabqi']!.isNotEmpty) ? aInfo['sabqi']! : aSt;
              String s3 = (aInfo?['manzil'] != null && aInfo!['manzil']!.isNotEmpty) ? aInfo['manzil']! : aSt;
              String sabaqPara = s1 == 'A' ? 'غیر حاضر' : (s1 == 'L' ? 'رخصت' : '-');
              String sabaqGrade = s1 == 'A' ? 'Absent' : (s1 == 'L' ? 'Leave' : '-');
              String sabqiPara = s2 == 'A' ? 'غیر حاضر' : (s2 == 'L' ? 'رخصت' : '-');
              String sabqiGrade = s2 == 'A' ? 'Absent' : (s2 == 'L' ? 'Leave' : '-');
              String manzilDetails = s3 == 'A' ? 'غیر حاضر' : (s3 == 'L' ? 'رخصت' : '-');
              String manzilGrade = s3 == 'A' ? 'Absent' : (s3 == 'L' ? 'Leave' : '-');
              String attSt = aSt == 'A' ? 'غیر حاضر' : (aSt == 'L' ? 'رخصت' : (aSt == 'P' ? 'حاضر' : '-'));

              h = {
                'student_id': s['id'],
                'date': ldFormatted,
                'sabaq_para': sabaqPara,
                'sabaq_lines': '-',
                'sabaq_grade': sabaqGrade,
                'sabqi_para': sabqiPara,
                'sabqi_qty': '-',
                'sabqi_grade': sabqiGrade,
                'manzil_details': manzilDetails,
                'manzil_total_qty': '-',
                'manzil_grade': manzilGrade,
                'att_status': attSt,
              };
            }

            var comps = _complaints.where((c) {
              String cSid = c['student_id']?.toString() ?? '';
              String cDate = PerformanceCalculator.normalizeDate(c['date']?.toString() ?? '');
              String hDate = PerformanceCalculator.normalizeDate(ldFormatted);
              return cSid == sid && cDate == hDate;
            }).toList();
            if (comps.isNotEmpty) {
              h['daily_complaint'] = comps.map((c) => c['complaint']?.toString() ?? '').where((c) => c.isNotEmpty).join(' | ');
            }

            fRecs.add(h);
          }

          Navigator.push(context, MaterialPageRoute(
              builder: (_) => FilteredHifzReportPage(
                  title: title,
                  subtitle: "${isUrduGlobal.value ? 'تاریخ' : 'Date'}: $ldFormatted",
                  filteredRecords: fRecs,
                  allStudents: matchedStudents.isNotEmpty ? matchedStudents : _students)));
        }



        Widget clickableColumn(String label, int count, List<String> sids, Color color) {
          return InkWell(
            onTap: count > 0 ? () => navBySids(label, sids) : null,
            borderRadius: BorderRadius.circular(6),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 3, horizontal: 2),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: count > 0 ? color.withOpacity(0.12) : Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: count > 0 ? color.withOpacity(0.4) : Colors.grey.shade300,
                        width: 0.8,
                      ),
                    ),
                    child: Text(
                      '$count',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: count > 0 ? color : Colors.grey,
                      ),
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    label,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.bold,
                      color: count > 0 ? color : Colors.grey.shade700,
                    ),
                  ),
                ],
              ),
            ),
          );
        }

        Widget clickableItem(String label, int count, List<String> sids, Color color) {
          return InkWell(
            onTap: count > 0 ? () => navBySids(label, sids) : null,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(label,
                    style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: count > 0 ? color : Colors.grey)),
                const SizedBox(width: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(
                    color: count > 0 ? color.withOpacity(0.12) : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text('$count',
                      style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                          color: count > 0 ? color : Colors.grey)),
                ),
              ],
            ),
          );
        }

        Widget lessonBox(
          String title,
          Color titleColor,
          int senders,
          int notRecited,
          int missed,
          int absent,
          int leave,
          List<String> senderSids,
          List<String> notRecitedSids,
          List<String> missSids,
          List<String> absentSids,
          List<String> leaveSids,
        ) {
          return Expanded(
            child: Container(
              margin: const EdgeInsets.symmetric(horizontal: 3),
              padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
              decoration: BoxDecoration(
                color: titleColor.withOpacity(0.06),
                border: Border.all(color: titleColor.withOpacity(0.5)),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Text(
                      title,
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                        color: titleColor,
                      ),
                    ),
                  ),
                  const Divider(height: 8),
                  Row(
                    textDirection: isUrduGlobal.value ? TextDirection.rtl : TextDirection.ltr,
                    children: [
                      Expanded(
                        child: clickableColumn(
                          'سنانے والے',
                          senders,
                          senderSids,
                          Colors.green.shade700,
                        ),
                      ),
                      Container(width: 0.5, height: 34, color: titleColor.withOpacity(0.25)),
                      Expanded(
                        child: clickableColumn(
                          'نہ سنانے والے',
                          notRecited,
                          notRecitedSids,
                          Colors.orange.shade900,
                        ),
                      ),
                      Container(width: 0.5, height: 34, color: titleColor.withOpacity(0.25)),
                      Expanded(
                        child: clickableColumn(
                          'ناغہ',
                          missed,
                          missSids,
                          Colors.red,
                        ),
                      ),
                      Container(width: 0.5, height: 34, color: titleColor.withOpacity(0.25)),
                      Expanded(
                        child: clickableColumn(
                          'غیر حاضر',
                          absent,
                          absentSids,
                          Colors.red.shade900,
                        ),
                      ),
                      Container(width: 0.5, height: 34, color: titleColor.withOpacity(0.25)),
                      Expanded(
                        child: clickableColumn(
                          'رخصت',
                          leave,
                          leaveSids,
                          Colors.orange.shade800,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        }

        rows.add(
          Container(
            width: selectedStudentId != null ? 1260 : 1515,
            margin: const EdgeInsets.only(bottom: 2),
            decoration: BoxDecoration(
              color: Colors.blueGrey.shade50,
              border: Border(
                top: BorderSide(color: Colors.blueGrey.shade300, width: 1.5),
                bottom: BorderSide(color: Colors.blueGrey.shade300, width: 1.5),
              ),
            ),
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                // Header row
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        clickableItem(isUrduGlobal.value ? 'تعداد طلبہ' : 'Total Students', effectiveStudents.length, effectiveStudents.map((s) => s['id']?.toString() ?? '').toList(), Colors.blue.shade800),
                        const SizedBox(width: 8),
                        clickableItem(isUrduGlobal.value ? 'غیر حاضر' : 'Absent', dAbsent, capAbsentSids, Colors.red.shade900),
                        const SizedBox(width: 8),
                        clickableItem(isUrduGlobal.value ? 'رخصت' : 'Leave', dLeave, capLeaveSids, Colors.orange.shade800),
                        const SizedBox(width: 8),
                        clickableItem(isUrduGlobal.value ? 'شکایات' : 'Complaints', dComplaints, capComplaintSids, Colors.red),
                      ],
                    ),
                    Text(
                      isUrduGlobal.value ? 'روزانہ سمری ($ldFormatted)' : 'Daily Summary ($ldFormatted)',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          color: Colors.black87,
                          fontSize: 13),
                    ),
                  ],
                ),

                const SizedBox(height: 8),

                // Three lesson boxes (RTL: Sabaq -> Sabqi -> Manzil)
                Row(
                  textDirection: isUrduGlobal.value ? TextDirection.rtl : TextDirection.ltr,
                  children: [
                    lessonBox(
                      isUrduGlobal.value ? 'سبق' : 'Sabaq',
                      Colors.green.shade700,
                      capSabaqSenders.length, capSabaqNotRecited.length, dSabaqMiss, capSabaqAbsent.length, capSabaqLeave.length,
                      capSabaqSenders, capSabaqNotRecited, capSabaqMiss, capSabaqAbsent, capSabaqLeave,
                    ),
                    lessonBox(
                      isUrduGlobal.value ? 'سبقی' : 'Sabqi',
                      Colors.blue.shade700,
                      capSabqiSenders.length, capSabqiNotRecited.length, dSabqiMiss, capSabqiAbsent.length, capSabqiLeave.length,
                      capSabqiSenders, capSabqiNotRecited, capSabqiMiss, capSabqiAbsent, capSabqiLeave,
                    ),
                    lessonBox(
                      isUrduGlobal.value ? 'منزل' : 'Manzil',
                      Colors.orange.shade800,
                      capManzilSenders.length, capManzilNotRecited.length, dManzilMiss, capManzilAbsent.length, capManzilLeave.length,
                      capManzilSenders, capManzilNotRecited, capManzilMiss, capManzilAbsent, capManzilLeave,
                    ),
                  ],
                ),

              ],

            ),

          ),

        );

      }

      dailyMissedSabaq = 0;

      dailyMissedSabqi = 0;

      dailyMissedManzil = 0;

      dailyAbsentCount = 0;

      dailyLeaveCount = 0;

      dailyComplaints = 0;
      dailyComplaintSids = [];
      dailyAbsentSids = [];

      dailyLeaveSids = [];

      dailySabaqSenderSids = [];

      dailySabaqNotRecitedSids = [];

      dailySabaqMissSids = [];

      dailySabaqAbsentSids = [];

      dailySabaqLeaveSids = [];

      dailySabqiSenderSids = [];

      dailySabqiNotRecitedSids = [];

      dailySabqiMissSids = [];

      dailySabqiAbsentSids = [];

      dailySabqiLeaveSids = [];

      dailyManzilSenderSids = [];

      dailyManzilNotRecitedSids = [];

      dailyManzilMissSids = [];

      dailyManzilAbsentSids = [];

      dailyManzilLeaveSids = [];

    }



    for (var origH in sortedRecords) {
      var h = Map<String, dynamic>.from(origH);
      DateTime dt = _parseRobust(h['date'] ?? '');
      
      if (currentDate == null || dt.year != currentDate.year || dt.month != currentDate.month || dt.day != currentDate.day) {
        if (currentDate != null) addDailySummary();
        currentDate = dt;
        lastDateFormatted = intl.DateFormat('dd-MM-yyyy').format(dt);
        processedDates.add(lastDateFormatted);
        srNo = 1; 
        
        rows.add(
          Container(
            width: selectedStudentId != null ? 1260 : 1515,
            color: Colors.grey.shade300,
            padding: const EdgeInsets.symmetric(vertical: 6),
            alignment: Alignment.center,
            child: Text(
              isUrduGlobal.value ? "تاریخ: $lastDateFormatted" : "Date: $lastDateFormatted",
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black87),
            ),
          )
        );
      }

      String sid = h['student_id']?.toString() ?? '';
      String rDate = h['date']?.toString().trim() ?? '';
      String nRDate = PerformanceCalculator.normalizeDate(rDate);
      var attInfo = attLookup["${sid}_$rDate"] ?? attLookup["${sid}_$nRDate"];
      String aStat = attInfo?['main'] ?? '';
      String as1 = attInfo?['sabaq'] ?? aStat;
      String as2 = attInfo?['sabqi'] ?? aStat;
      String as3 = attInfo?['manzil'] ?? aStat;

      String hAtt = (h['att_status'] ?? '').toString().trim();
      if (hAtt == 'غیر حاضر' || hAtt == 'غير حاضر' || hAtt == 'Absent') {
        if (aStat.isEmpty) aStat = 'A';
        if (as1.isEmpty) as1 = 'A';
        if (as2.isEmpty) as2 = 'A';
        if (as3.isEmpty) as3 = 'A';
      } else if (hAtt == 'رخصت' || hAtt == 'Leave') {
        if (aStat.isEmpty) aStat = 'L';
        if (as1.isEmpty) as1 = 'L';
        if (as2.isEmpty) as2 = 'L';
        if (as3.isEmpty) as3 = 'L';
      } else if (hAtt == 'حاضر' || hAtt == 'Present') {
        if (aStat.isEmpty) aStat = 'P';
        if (as1.isEmpty) as1 = 'P';
        if (as2.isEmpty) as2 = 'P';
        if (as3.isEmpty) as3 = 'P';
      }

      if (as1.isEmpty) as1 = h['attendance_morning']?.toString() ?? h['attendance_sabaq']?.toString() ?? '';
      if (as2.isEmpty) as2 = h['attendance_noon']?.toString() ?? h['attendance_sabqi']?.toString() ?? '';
      if (as3.isEmpty) as3 = h['attendance_evening']?.toString() ?? h['attendance_manzil']?.toString() ?? '';

      bool isFriday = dt.weekday == DateTime.friday;
      bool isThursday = dt.weekday == DateTime.thursday;

      bool checkPeriodHasRec(String p) {
        String rg = (h['${p}_grade'] ?? '').toString().trim();
        String rd = p == 'manzil'
            ? (h['manzil_details'] ?? h['manzil_para'] ?? '').toString().trim()
            : (h['${p}_para'] ?? '').toString().trim();
        return rg.isNotEmpty && rg != '-' && rg != 'ناغہ' && !rg.contains('غیر حاضر') && !rg.contains('Absent') && !rg.contains('رخصت') && !rg.contains('Leave') && !rg.contains('نہیں سنا') &&
               rd.isNotEmpty && rd != '-' && rd != 'ناغہ' && !rd.contains('غیر حاضر') && !rd.contains('Absent') && !rd.contains('رخصت') && !rd.contains('Leave') && !rd.contains('نہیں سنا');
      }
      bool hasRecitationInAnyPeriod = checkPeriodHasRec('sabaq') || checkPeriodHasRec('sabqi') || checkPeriodHasRec('manzil');

      final studentObj = _allStudentMap[sid] ?? _students.firstWhere((s) => s['id']?.toString() == sid, orElse: () => {});
      final bool isManzilGardan = (studentObj['hifz_section'] == 'manzil_gardan');

      void processPeriod(String aStat, String pPrefix) {
        if (isFriday) {
          h['${pPrefix}_grade'] = 'جمعہ';
          if (pPrefix == 'manzil') {
            h['${pPrefix}_details'] = 'جمعہ';
          } else {
            h['${pPrefix}_para'] = 'جمعہ';
          }
          return;
        }

        if (isThursday && pPrefix == 'manzil') {
          h['${pPrefix}_grade'] = 'مشق';
          h['${pPrefix}_details'] = 'مشق';
          return;
        }

        String rawGrade = (h['${pPrefix}_grade'] ?? '').toString().trim();
        String rawDetails = pPrefix == 'manzil'
            ? (h['manzil_details'] ?? h['manzil_para'] ?? '').toString().trim()
            : (h['${pPrefix}_para'] ?? '').toString().trim();

        bool isNotRecited = rawGrade.contains('نہیں سنا') ||
            rawGrade.contains('نہیں سنائی') ||
            rawDetails.contains('نہیں سنا') ||
            rawDetails.contains('نہیں سنائی');

        if (isNotRecited) {
          final hDate = h['date']?.toString() ?? '';
          if (pPrefix == 'sabaq') {
            if (!isManzilGardan) {
              if (!dailySabaqNotRecitedSids.contains(sid)) dailySabaqNotRecitedSids.add(sid);
              freqSabaq[sid] = (freqSabaq[sid] ?? 0) + 1;
              if (hDate.isNotEmpty) datesSabaqNaaghah.putIfAbsent(sid, () => []).add(hDate);
            }
          } else if (pPrefix == 'sabqi') {
            if (!isManzilGardan) {
              if (!dailySabqiNotRecitedSids.contains(sid)) dailySabqiNotRecitedSids.add(sid);
              freqSabqi[sid] = (freqSabqi[sid] ?? 0) + 1;
              if (hDate.isNotEmpty) datesSabqiNaaghah.putIfAbsent(sid, () => []).add(hDate);
            }
          } else if (pPrefix == 'manzil') {
            if (!dailyManzilNotRecitedSids.contains(sid)) dailyManzilNotRecitedSids.add(sid);
            freqManzil[sid] = (freqManzil[sid] ?? 0) + 1;
            if (hDate.isNotEmpty) datesManzilNaaghah.putIfAbsent(sid, () => []).add(hDate);
          }
          return;
        }

        bool hasRecitation = rawGrade.isNotEmpty &&
            rawGrade != '-' &&
            rawGrade != 'ناغہ' &&
            rawGrade != 'غیر حاضر' &&
            rawGrade != 'غير حاضر' &&
            rawGrade != 'Absent' &&
            rawGrade != 'رخصت' &&
            rawGrade != 'Leave' &&
            rawDetails.isNotEmpty &&
            rawDetails != '-';

        if (hasRecitation) {
          // Recited!
          if (pPrefix == 'sabaq' && !dailySabaqSenderSids.contains(sid)) dailySabaqSenderSids.add(sid);
          if (pPrefix == 'sabqi' && !dailySabqiSenderSids.contains(sid)) dailySabqiSenderSids.add(sid);
          if (pPrefix == 'manzil' && !dailyManzilSenderSids.contains(sid)) dailyManzilSenderSids.add(sid);
        } else if (aStat == 'A' || rawGrade == 'غیر حاضر' || rawGrade == 'غير حاضر' || rawGrade == 'Absent' || rawDetails == 'غیر حاضر' || rawDetails == 'Absent') {
          h['${pPrefix}_grade'] = 'Absent';
          if (pPrefix == 'manzil') {
            h['${pPrefix}_details'] = isUrduGlobal.value ? 'غیر حاضر' : 'Absent';
          } else {
            h['${pPrefix}_para'] = isUrduGlobal.value ? 'غیر حاضر' : 'Absent';
          }
          if (pPrefix == 'sabaq' && !dailySabaqAbsentSids.contains(sid)) dailySabaqAbsentSids.add(sid);
          if (pPrefix == 'sabqi' && !dailySabqiAbsentSids.contains(sid)) dailySabqiAbsentSids.add(sid);
          if (pPrefix == 'manzil' && !dailyManzilAbsentSids.contains(sid)) dailyManzilAbsentSids.add(sid);
        } else if (aStat == 'L' || rawGrade == 'رخصت' || rawGrade == 'Leave' || rawDetails == 'رخصت' || rawDetails == 'Leave') {
          h['${pPrefix}_grade'] = 'Leave';
          if (pPrefix == 'manzil') {
            h['${pPrefix}_details'] = isUrduGlobal.value ? 'رخصت' : 'Leave';
          } else {
            h['${pPrefix}_para'] = isUrduGlobal.value ? 'رخصت' : 'Leave';
          }
          if (pPrefix == 'sabaq' && !dailySabaqLeaveSids.contains(sid)) dailySabaqLeaveSids.add(sid);
          if (pPrefix == 'sabqi' && !dailySabqiLeaveSids.contains(sid)) dailySabqiLeaveSids.add(sid);
          if (pPrefix == 'manzil' && !dailyManzilLeaveSids.contains(sid)) dailyManzilLeaveSids.add(sid);
        } else {
          // Only count as naaghah if explicitly marked or student was present in class today
          bool isExplicitNaaghah = rawGrade.contains('ناغہ') || rawDetails.contains('ناغہ') || (h['${pPrefix}_status'] ?? '').toString().contains('ناغہ');
          bool isPresentToday = aStat == 'P' || aStat == 'Present' || aStat == 'حاضر' || 
                               as1 == 'P' || as2 == 'P' || as3 == 'P' || 
                               as1 == 'حاضر' || as2 == 'حاضر' || as3 == 'حاضر' ||
                               (h['att_status'] == 'حاضر' || h['att_status'] == 'Present') ||
                               hasRecitationInAnyPeriod;

          if (isExplicitNaaghah || isPresentToday) {
            final hDate = h['date']?.toString() ?? '';
            if (isManzilGardan && (pPrefix == 'sabaq' || pPrefix == 'sabqi')) {
              h['${pPrefix}_grade'] = isUrduGlobal.value ? 'استثناء (منزل)' : 'Exempt (Manzil)';
              if (pPrefix == 'sabaq') {
                if (!dailySabaqNotRecitedSids.contains(sid)) dailySabaqNotRecitedSids.add(sid);
              }
              if (pPrefix == 'sabqi') {
                if (!dailySabqiNotRecitedSids.contains(sid)) dailySabqiNotRecitedSids.add(sid);
              }
            } else {
              h['${pPrefix}_grade'] = '-';
              if (pPrefix == 'sabaq') {
                dailyMissedSabaq++;
                if (!dailySabaqMissSids.contains(sid)) dailySabaqMissSids.add(sid);
                freqSabaq[sid] = (freqSabaq[sid] ?? 0) + 1;
                if (hDate.isNotEmpty) datesSabaqNaaghah.putIfAbsent(sid, () => []).add(hDate);
              }
              if (pPrefix == 'sabqi') {
                dailyMissedSabqi++;
                if (!dailySabqiMissSids.contains(sid)) dailySabqiMissSids.add(sid);
                freqSabqi[sid] = (freqSabqi[sid] ?? 0) + 1;
                if (hDate.isNotEmpty) datesSabqiNaaghah.putIfAbsent(sid, () => []).add(hDate);
              }
              if (pPrefix == 'manzil') {
                dailyMissedManzil++;
                if (!dailyManzilMissSids.contains(sid)) dailyManzilMissSids.add(sid);
                freqManzil[sid] = (freqManzil[sid] ?? 0) + 1;
                if (hDate.isNotEmpty) datesManzilNaaghah.putIfAbsent(sid, () => []).add(hDate);
              }
            }
          } else {
            // Unrecorded / Dummy record -> do NOT count as naaghah!
            h['${pPrefix}_grade'] = '-';
          }
        }
      }



      processPeriod(as1, 'sabaq');

      processPeriod(as2, 'sabqi');

      processPeriod(as3, 'manzil');



      bool isOverallAbsent = false;
      bool isOverallLeave = false;

      if (!hasRecitationInAnyPeriod) {
        if (as1 == 'A' || (as1.isEmpty && aStat == 'A') || hAtt == 'غیر حاضر' || hAtt == 'غير حاضر' || hAtt == 'Absent') {
          isOverallAbsent = true;
        } else if (as1 == 'L' || (as1.isEmpty && aStat == 'L') || hAtt == 'رخصت' || hAtt == 'Leave') {
          isOverallLeave = true;
        }
      }

      if (isOverallAbsent && !dailyAbsentSids.contains(sid)) {
        dailyAbsentCount++;
        dailyAbsentSids.add(sid);
      } else if (isOverallLeave && !dailyLeaveSids.contains(sid)) {
        dailyLeaveCount++;
        dailyLeaveSids.add(sid);
      }



      var comps = _complaints.where((c) {
        String cSid = c['student_id']?.toString() ?? '';
        String cDate = PerformanceCalculator.normalizeDate(c['date']?.toString() ?? '');
        String hDate = PerformanceCalculator.normalizeDate(h['date']?.toString() ?? '');
        return cSid == sid && cDate == hDate;
      }).toList();
      if (comps.isNotEmpty) {
        dailyComplaints += comps.length;
        dailyComplaintSids.add(sid);
        h['daily_complaint'] = comps.map((c) => c['complaint']?.toString() ?? '').where((c) => c.isNotEmpty).join(' | ');
      }



      rows.add(_buildDataRow(h, srNo));

      srNo++;

    }

    

    if (currentDate != null) addDailySummary(); // Add for the last date



    if (rows.isEmpty) {

      bool isIndividual = selectedStudentId != null;

      double width = isIndividual ? 1260 : 1515;

      rows.add(Container(

        width: width,

        padding: const EdgeInsets.all(20),

        alignment: Alignment.center,

        decoration: BoxDecoration(border: Border.all(color: Colors.black87, width: 0.5)),

        child: Text(isUrduGlobal.value ? "   " : "No record found", style: const TextStyle(color: Colors.red)),

      ));

    }

    

    if (processedDates.isNotEmpty && selectedStudentId == null) {

        int s0=0, s1=0, s2=0, s3=0, sMore=0;

        int sq0=0, sq1=0, sq2=0, sq3=0, sqMore=0;

        int m0=0, m1=0, m2=0, m3=0, mMore=0;

        

        for (var s in effectiveStudents) {

            String sid = s['id'].toString();

            int sq = freqSabaq[sid] ?? 0;

            if (sq == 0) {
              s0++;
            } else if (sq == 1) {
              s1++;
            } else if (sq == 2) {
              s2++;
            } else if (sq == 3) {
              s3++;
            } else if (sq > 3) {
              sMore++;
            }

            int sqi = freqSabqi[sid] ?? 0;

            if (sqi == 0) {
              sq0++;
            } else if (sqi == 1) {
              sq1++;
            } else if (sqi == 2) {
              sq2++;
            } else if (sqi == 3) {
              sq3++;
            } else if (sqi > 3) {
              sqMore++;
            }

            int mz = freqManzil[sid] ?? 0;

            if (mz == 0) {
              m0++;
            } else if (mz == 1) {
              m1++;
            } else if (mz == 2) {
              m2++;
            } else if (mz == 3) {
              m3++;
            } else if (mz > 3) {
              mMore++;
            }

        }

        

        Widget clickableCell(String catName, int c, String type, dynamic countVal, {bool isSuccess = false}) {
            return GestureDetector(
                onTap: () {
                    final fRecs = <Map<String, dynamic>>[];
                    final targetDatesMap = <String, List<String>>{};

                    for(var s in effectiveStudents) {
                        String sid = s['id'].toString();
                        int freq = 0;
                        List<String> dList = [];
                        if(type == 'sabaq') { freq = freqSabaq[sid] ?? 0; dList = datesSabaqNaaghah[sid] ?? []; }
                        if(type == 'sabqi') { freq = freqSabqi[sid] ?? 0; dList = datesSabqiNaaghah[sid] ?? []; }
                        if(type == 'manzil') { freq = freqManzil[sid] ?? 0; dList = datesManzilNaaghah[sid] ?? []; }

                        bool match = false;
                        if (countVal == 'more' && freq > 3) {
                          match = true;
                        } else if (countVal != 'more' && freq == countVal) {
                          match = true;
                        }

                        if (match) {
                           targetDatesMap[sid] = dList;
                           var recs = combinedRecords.where((r) => r['student_id'].toString() == sid).toList();
                           if(recs.isEmpty) {
                              fRecs.add({'student_id': s['id'], 'date': 'N/A', 'sabaq_grade': '-', 'sabqi_grade': '-', 'manzil_grade': '-'});
                           } else {
                              fRecs.addAll(recs);
                           }
                        }
                    }

                    String fullTitle = "$catName ($c طلبہ)";
                    if (isSuccess) {
                      if (type == 'sabaq') fullTitle = "سبق بلاناغہ طلبہ (0 ناغہ) — $c طلبہ";
                      if (type == 'sabqi') fullTitle = "سبقی بلاناغہ طلبہ (0 ناغہ) — $c طلبہ";
                      if (type == 'manzil') fullTitle = "منزل بلاناغہ طلبہ (0 ناغہ) — $c طلبہ";
                    } else {
                      if (type == 'sabaq') fullTitle = "سبق کا ناغہ — $catName ($c طلبہ)";
                      if (type == 'sabqi') fullTitle = "سبقی کا ناغہ — $catName ($c طلبہ)";
                      if (type == 'manzil') fullTitle = "منزل کا ناغہ — $catName ($c طلبہ)";
                    }

                    Navigator.push(context, MaterialPageRoute(builder: (_) => FilteredHifzReportPage(
                      title: fullTitle,
                      subtitle: "مدت: $fromDate تا $toDate",
                      filteredRecords: fRecs,
                      allStudents: effectiveStudents,
                      naaghahDates: targetDatesMap,
                    )));
                },
                child: MouseRegion(cursor: SystemMouseCursors.click, child: Padding(padding: const EdgeInsets.all(8.0), child: Text("$c طلبہ", textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, decoration: TextDecoration.underline, color: isSuccess ? Colors.green.shade700 : Colors.red)))),
            );
        }



        rows.add(const SizedBox(height: 20));

        rows.add(

            Container(

                width: selectedStudentId != null ? 1260 : 1515,

                padding: const EdgeInsets.all(15),

                decoration: BoxDecoration(

                    color: Colors.white,

                    border: Border.all(color: Colors.blueAccent, width: 2),

                    borderRadius: BorderRadius.circular(8),

                ),

                child: Column(

                    children: [

                        Text(isUrduGlobal.value ? "پیریڈ وار ناغہ جات خلاصہ (Period Missed Lessons Summary)" : "Period Missed Lessons Summary", style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.blueAccent)),
                        const SizedBox(height: 10),
                        Table(
                            border: TableBorder.all(color: Colors.grey.shade400),
                            children: [
                                TableRow(
                                    decoration: BoxDecoration(color: Colors.grey.shade200),
                                    children: [
                                        _summaryCell(isUrduGlobal.value ? "ناغہ تفصیل" : "Missed Detail", isHeader: true),
                                        _summaryCell(isUrduGlobal.value ? "سبق" : "Sabaq", isHeader: true, textColor: Colors.green),
                                        _summaryCell(isUrduGlobal.value ? "سبقی" : "Sabqi", isHeader: true, textColor: Colors.blue),
                                        _summaryCell(isUrduGlobal.value ? "منزل" : "Manzil", isHeader: true, textColor: Colors.orange.shade800),
                                    ]
                                ),
                                TableRow(decoration: BoxDecoration(color: Colors.green.shade50), children: [ _summaryCell(isUrduGlobal.value ? "بغیر ناغہ (0 ناغہ)" : "No Missed (0)", textColor: Colors.green.shade700), clickableCell("0", s0, 'sabaq', 0, isSuccess: true), clickableCell("0", sq0, 'sabqi', 0, isSuccess: true), clickableCell("0", m0, 'manzil', 0, isSuccess: true) ]),
                                TableRow(children: [ _summaryCell(isUrduGlobal.value ? "1 ناغہ" : "1 Missed"), clickableCell("1", s1, 'sabaq', 1), clickableCell("1", sq1, 'sabqi', 1), clickableCell("1", m1, 'manzil', 1) ]),
                                TableRow(children: [ _summaryCell(isUrduGlobal.value ? "2 ناغے" : "2 Missed"), clickableCell("2", s2, 'sabaq', 2), clickableCell("2", sq2, 'sabqi', 2), clickableCell("2", m2, 'manzil', 2) ]),
                                TableRow(children: [ _summaryCell(isUrduGlobal.value ? "3 ناغے" : "3 Missed"), clickableCell("3", s3, 'sabaq', 3), clickableCell("3", sq3, 'sabqi', 3), clickableCell("3", m3, 'manzil', 3) ]),
                                TableRow(decoration: BoxDecoration(color: Colors.red.shade50), children: [ _summaryCell(isUrduGlobal.value ? "3 سے زائد ناغے" : "More than 3 Missed", textColor: Colors.red), clickableCell("3+", sMore, 'sabaq', 'more'), clickableCell("3+", sqMore, 'sabqi', 'more'), clickableCell("3+", mMore, 'manzil', 'more') ]),
                            ]
                        )

                    ]

                )

            )

        );

    }

    

    return rows;

  }

  

  Widget _summaryCell(String text, {bool isHeader = false, Color? textColor}) {

      return Padding(

          padding: const EdgeInsets.all(8.0),

          child: Text(text, textAlign: TextAlign.center, style: TextStyle(fontWeight: isHeader ? FontWeight.bold : FontWeight.normal, color: textColor ?? Colors.black87)),

      );

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

                    ? "   "

                    : "Weekly Smart Analysis",

                style:

                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),

            const Divider(),

            Row(

              mainAxisAlignment: MainAxisAlignment.spaceAround,

              children: [

                _buildGauge(isUrduGlobal.value ? "" : "Att.",

                    att.toDouble(), Colors.blue),

                _buildGauge(isUrduGlobal.value ? "" : "Acad.",

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

                                ? "   ( )"

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

  Widget _buildFilterBar() {
    return Container(
      padding: const EdgeInsets.all(12),
      color: Colors.white,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final isWide = constraints.maxWidth >= 900;

          final periodDropdown = DropdownButtonFormField<String>(
            value: selectedPeriod,
            isExpanded: true,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "دورانیہ (Period)" : "Period",
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            items: [
              DropdownMenuItem(
                  value: 'all',
                  child: Text(isUrduGlobal.value ? "تمام تاریخیں / تمام ریکارڈز (All)" : "All Dates (All Records)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'this_month',
                  child: Text(isUrduGlobal.value ? "رواں مہینہ (This Month)" : "This Month", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'current_week',
                  child: Text(isUrduGlobal.value ? "رواں ہفتہ (Current Week)" : "Current Week", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'weekly',
                  child: Text(isUrduGlobal.value ? "ہفتہ وار (آخری 7 دن)" : "Weekly (Last 7 Days)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'monthly',
                  child: Text(isUrduGlobal.value ? "ماہانہ (آخری 30 دن)" : "Monthly (Last 30 Days)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'quarterly',
                  child: Text(isUrduGlobal.value ? "سہ ماہی (آخری 90 دن)" : "Quarterly (Last 90 Days)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'semi_annually',
                  child: Text(isUrduGlobal.value ? "شش ماہی (آخری 180 دن)" : "Semi-Annually (180 Days)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'annually',
                  child: Text(isUrduGlobal.value ? "سالانہ (آخری 365 دن)" : "Annually (365 Days)", overflow: TextOverflow.ellipsis)),
              DropdownMenuItem(
                  value: 'custom',
                  child: Text(isUrduGlobal.value ? "کسٹم ڈیٹس (Custom)" : "Custom Dates", overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) {
              if (v != null) {
                setState(() => selectedPeriod = v);
                _updateDatesForPeriod(v);
              }
            },
          );

          final classDropdown = DropdownButtonFormField<String>(
            value: _classes.contains(_selectedClass) ? _selectedClass : null,
            isExpanded: true,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "کلاس منتخب کریں" : "Select Class",
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            items: [
              DropdownMenuItem(
                  value: null,
                  child: Text(isUrduGlobal.value
                      ? "-- تمام حفظ / ناظرہ کلاسز --"
                      : "-- All Hifz Classes --", overflow: TextOverflow.ellipsis)),
              ..._classes.map((c) => DropdownMenuItem(
                    value: c,
                    child: Text(c, overflow: TextOverflow.ellipsis),
                  )),
            ],
            onChanged: (v) {
              setState(() {
                _selectedClass = v;
                selectedStudentId = null;
                _selectedChunkIndex = -1;
              });
              _loadData();
            },
          );

          final sectionDropdown = DropdownButtonFormField<String>(
            value: _selectedHifzSection,
            isExpanded: true,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "حفظ سیکشن" : "Hifz Section",
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            items: [
              DropdownMenuItem(
                value: 'all',
                child: Text(isUrduGlobal.value ? "تمام سیکشنز" : "All Sections", overflow: TextOverflow.ellipsis),
              ),
              DropdownMenuItem(
                value: 'regular',
                child: Text(isUrduGlobal.value ? "سیکشن A: ریگولر حفظ" : "Section A: Regular Hifz", overflow: TextOverflow.ellipsis),
              ),
              DropdownMenuItem(
                value: 'manzil_gardan',
                child: Text(isUrduGlobal.value ? "سیکشن B: منزل/گردان (پارٹ ٹائم)" : "Section B: Manzil/Gardan", overflow: TextOverflow.ellipsis),
              ),
            ],
            onChanged: (v) {
              if (v != null) {
                setState(() {
                  _selectedHifzSection = v;
                  selectedStudentId = null;
                  _selectedChunkIndex = -1;
                });
                _loadData();
              }
            },
          );

          final studentDropdown = DropdownButtonFormField<String>(
            value: _students.any((s) => s['id'].toString() == selectedStudentId)
                ? selectedStudentId
                : null,
            isExpanded: true,
            menuMaxHeight: 350,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value
                    ? "طالب علم منتخب کریں"
                    : "Select Student",
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            items: [
              DropdownMenuItem(
                  value: null,
                  child: Text(isUrduGlobal.value
                      ? "-- تمام طلباء (ڈیفالٹ) --"
                      : "-- All Students (Default) --", overflow: TextOverflow.ellipsis)),
              ..._students.map((s) {
                final roll = (s['roll_number']?.toString().isNotEmpty == true)
                    ? s['roll_number'].toString()
                    : (s['admission_number']?.toString() ?? '');
                final name = s['name']?.toString() ?? '';
                final fName = s['father_name']?.toString().trim() ?? '';
                final bool isManzil = (s['hifz_section'] == 'manzil_gardan');
                final badge = isManzil ? " [منزل/گردان]" : "";
                final label = roll.isNotEmpty
                    ? "$roll - $name$badge ${fName.isNotEmpty ? (isUrduGlobal.value ? '(ولد: $fName)' : '(S/O $fName)') : ''}"
                    : "$name$badge ${fName.isNotEmpty ? (isUrduGlobal.value ? '(ولد: $fName)' : '(S/O $fName)') : ''}";
                return DropdownMenuItem<String>(
                  value: s['id'].toString(),
                  child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
                );
              })
            ],
            onChanged: (v) {
              setState(() => selectedStudentId = v);
              _loadData();
            },
          );

          final fromDateField = TextField(
            controller: _fromDateController,
            readOnly: true,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "ابتدائی تاریخ" : "From Date",
                prefixIcon: const Icon(Icons.calendar_today, size: 16),
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            onTap: () => _selectDate(context, true),
          );

          final toDateField = TextField(
            controller: _toDateController,
            readOnly: true,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "آخری تاریخ" : "To Date",
                prefixIcon: const Icon(Icons.calendar_today, size: 16),
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            onTap: () => _selectDate(context, false),
          );

          if (isWide) {
            return Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(flex: 2, child: periodDropdown),
                const SizedBox(width: 8),
                Expanded(flex: 2, child: classDropdown),
                const SizedBox(width: 8),
                Expanded(flex: 2, child: sectionDropdown),
                const SizedBox(width: 8),
                Expanded(flex: 3, child: studentDropdown),
                const SizedBox(width: 8),
                Expanded(flex: 2, child: fromDateField),
                const SizedBox(width: 8),
                Expanded(flex: 2, child: toDateField),
              ],
            );
          } else {
            return Column(
              children: [
                Row(
                  children: [
                    Expanded(child: periodDropdown),
                    const SizedBox(width: 8),
                    Expanded(child: classDropdown),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(child: sectionDropdown),
                    const SizedBox(width: 8),
                    Expanded(child: studentDropdown),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(child: fromDateField),
                    const SizedBox(width: 8),
                    Expanded(child: toDateField),
                  ],
                ),
              ],
            );
          }
        },
      ),
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
            builder: (context) {
              if (Navigator.canPop(context)) {
                return IconButton(
                  icon: const Icon(Icons.arrow_back, color: Colors.white),
                  onPressed: () => Navigator.pop(context),
                  tooltip: isUrduGlobal.value ? "واپس" : "Back",
                );
              }
              return IconButton(
                icon: const Icon(Icons.menu, color: Colors.white),
                onPressed: () => Scaffold.of(context).openDrawer(),
              );
            },
          ),
          title: Text(
              isUrduGlobal.value ? "حفظ تفصیلی رپورٹ" : "Hifz Detailed Report"),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
          actions: [
            IconButton(
              icon: const Icon(Icons.print, color: Colors.white),
              onPressed: _onPrintPressed,
              tooltip: isUrduGlobal.value ? "پرنٹ / پریویو" : "Print / Preview",
            ),

            IconButton(

              icon: const Icon(Icons.sync, color: Colors.white),

              onPressed: () async {

                ScaffoldMessenger.of(context).showSnackBar(SnackBar(

                    content: Text(isUrduGlobal.value

                        ? "    ..."

                        : "Syncing data...")));

                try {

                  await SyncService.runTwoWaySync();

                  if (mounted) {

                    _loadData();

                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(

                        content: Text(isUrduGlobal.value

                            ? "   !"

                            : "Sync complete!"),

                        backgroundColor: Colors.green));

                  }

                } catch (e) {

                  if (mounted) {

                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(

                        content: Text(isUrduGlobal.value

                            ? "   "

                            : "Sync failed"),

                        backgroundColor: Colors.red));

                  }

                }

              },

            ),

          ],

        ),

        body: NestedScrollView(
          headerSliverBuilder: (BuildContext context, bool innerBoxIsScrolled) {
            return [
              SliverToBoxAdapter(child: _buildFilterBar()),
              if (!isLoading && _errorMessage == null && selectedStudentId == null)
                SliverToBoxAdapter(child: _build30DayPaginationBar()),
              if (!isLoading && _errorMessage == null && selectedStudentId == null && _hifzRecords.isNotEmpty)
                SliverToBoxAdapter(child: _buildSmartPerformance()),
            ];
          },
          body: isLoading
              ? _buildLoadingWidget()
              : _errorMessage != null
                  ? _buildErrorWidget()
                  : selectedStudentId != null
                      ? StudentReportPage(
                          key: ValueKey(selectedStudentId),
                          forceStudentId: selectedStudentId,
                          isEmbedded: true,
                        )
                      : (_hifzRecords.isEmpty && _students.isEmpty)
                          ? Center(
                              child: Text(
                                isUrduGlobal.value
                                    ? "کوئی ریکارڈ موجود نہیں ہے۔"
                                    : "No records found.",
                                style: const TextStyle(fontSize: 16, color: Colors.grey),
                              ),
                            )
                          : Padding(
                              padding: const EdgeInsets.all(8.0),
                              child: LayoutBuilder(
                                builder: (context, constraints) {
                                  return SingleChildScrollView(
                                    scrollDirection: Axis.horizontal,
                                    physics: const BouncingScrollPhysics(),
                                    child: SizedBox(
                                      width: constraints.maxWidth > (selectedStudentId != null ? 1260.0 : 1515.0) ? constraints.maxWidth : (selectedStudentId != null ? 1260.0 : 1515.0),
                                      height: constraints.maxHeight,
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          // Sticky Table Header
                                          _buildTableHeader(),
                                          // Scrollable Data Rows
                                          Expanded(
                                            child: SingleChildScrollView(
                                              physics: const BouncingScrollPhysics(),
                                              child: Column(
                                                crossAxisAlignment: CrossAxisAlignment.start,
                                                children: _buildTableRows(),
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  );
                                },
                              ),
                            ),
        ),

      ),

    );

  }



  Future<void> _onPrintPressed() async {
    final bool isUrdu = isUrduGlobal.value;
    String? selectedOption = await showDialog<String>(
      context: context,
      builder: (BuildContext ctx) {
        return AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          title: Row(
            children: [
              Icon(Icons.print, color: primaryGreen),
              const SizedBox(width: 8),
              Text(
                isUrdu ? "پرنٹ کا دائرہ کار منتخب کریں" : "Select Print Scope",
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ListTile(
                  leading: const Icon(Icons.date_range, color: Colors.blue),
                  title: Text(isUrdu ? "موجودہ مرحلہ (30 دن)" : "Current Phase (30 Days)"),
                  subtitle: Text(
                    _dateChunks.isNotEmpty && _selectedChunkIndex >= 0 && _selectedChunkIndex < _dateChunks.length
                        ? _dateChunks[_selectedChunkIndex].label
                        : (isUrdu ? "موجودہ 30 روزہ ڈیٹا" : "Current 30-day view"),
                    style: const TextStyle(fontSize: 12),
                  ),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  tileColor: Colors.blue.withValues(alpha: 0.08),
                  onTap: () => Navigator.pop(ctx, 'current_phase'),
                ),
                const SizedBox(height: 8),
                ListTile(
                  leading: const Icon(Icons.all_inclusive, color: Colors.green),
                  title: Text(isUrdu ? "مکمل ریکارڈز (تمام تاریخیں / سالانہ)" : "Full Records (All Dates / Annual)"),
                  subtitle: Text(
                    isUrdu ? "منتخب شدہ دورانیے کے تمام ریکارڈز" : "All records in selected period",
                    style: const TextStyle(fontSize: 12),
                  ),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  tileColor: Colors.green.withValues(alpha: 0.08),
                  onTap: () => Navigator.pop(ctx, 'all_records'),
                ),
                const SizedBox(height: 8),
                ListTile(
                  leading: const Icon(Icons.calendar_today, color: Colors.orange),
                  title: Text(isUrdu ? "رواں سال (Current Year)" : "Current Year"),
                  subtitle: Text(
                    isUrdu ? "یکم جنوری تا آج (${DateTime.now().year})" : "Jan 1 to today (${DateTime.now().year})",
                    style: const TextStyle(fontSize: 12),
                  ),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  tileColor: Colors.orange.withValues(alpha: 0.08),
                  onTap: () => Navigator.pop(ctx, 'current_year'),
                ),
                const SizedBox(height: 8),
                ListTile(
                  leading: const Icon(Icons.tune, color: Colors.purple),
                  title: Text(isUrdu ? "کسٹم ڈیٹ (مخصوص تاریخیں)" : "Custom Date Range"),
                  subtitle: Text(
                    isUrdu ? "اپنی مرضی کی تاریخ سے تاریخ تک" : "Select your own date range",
                    style: const TextStyle(fontSize: 12),
                  ),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  tileColor: Colors.purple.withValues(alpha: 0.08),
                  onTap: () => Navigator.pop(ctx, 'custom_date'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(isUrdu ? "منسوخ" : "Cancel"),
            ),
          ],
        );
      },
    );

    if (selectedOption == 'custom_date') {
      // کسٹم ڈیٹ رینج پکر
      final DateTimeRange? picked = await showDateRangePicker(
        context: context,
        firstDate: DateTime(2020),
        lastDate: DateTime.now(),
        initialDateRange: DateTimeRange(
          start: DateTime.now().subtract(const Duration(days: 30)),
          end: DateTime.now(),
        ),
        helpText: isUrduGlobal.value ? "پرنٹ کا دورانیہ منتخب کریں" : "Select Print Date Range",
        saveText: isUrduGlobal.value ? "ٹھیک ہے" : "OK",
        builder: (context, child) => Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(primary: Color(0xFF284D3D)),
          ),
          child: child!,
        ),
      );
      if (picked != null && mounted) {
        final customFrom = intl.DateFormat('dd/MM/yyyy').format(picked.start);
        final customTo = intl.DateFormat('dd/MM/yyyy').format(picked.end);
        await _generateAndPreviewHifzPdf(printScope: 'custom_date', customFrom: customFrom, customTo: customTo);
      }
    } else if (selectedOption != null) {
      await _generateAndPreviewHifzPdf(printScope: selectedOption);
    }
  }

  Future<void> _generateAndPreviewHifzPdf({String printScope = 'current_phase', String? customFrom, String? customTo}) async {
    try {
      if (!mounted) return;
      setState(() => isLoading = true);

      List<Map<String, dynamic>> recordsToPrint;
      if (printScope == 'all_records' && _cachedRawHifz.isNotEmpty) {
        recordsToPrint = _cachedRawHifz;
      } else if (printScope == 'current_year') {
        final yearStr = DateTime.now().year.toString();
        recordsToPrint = (_cachedRawHifz.isNotEmpty ? _cachedRawHifz : _hifzRecords)
            .where((r) => (r['date']?.toString() ?? '').startsWith(yearStr))
            .toList();
      } else if (printScope == 'custom_date' && customFrom != null && customTo != null) {
        // تاریخ تبدیل کریں dd/MM/yyyy → DateTime
        DateTime? fDate, tDate;
        try {
          final parts1 = customFrom.split('/');
          fDate = DateTime(int.parse(parts1[2]), int.parse(parts1[1]), int.parse(parts1[0]));
          final parts2 = customTo.split('/');
          tDate = DateTime(int.parse(parts2[2]), int.parse(parts2[1]), int.parse(parts2[0]));
        } catch (_) {}
        if (fDate != null && tDate != null) {
          recordsToPrint = (_cachedRawHifz.isNotEmpty ? _cachedRawHifz : _hifzRecords)
              .where((r) {
                final d = r['date']?.toString() ?? '';
                if (d.isEmpty) return false;
                try {
                  final rd = DateTime.parse(d.contains('/') ? '${d.split('/')[2]}-${d.split('/')[1]}-${d.split('/')[0]}' : d);
                  return !rd.isBefore(fDate!) && !rd.isAfter(tDate!);
                } catch (_) { return false; }
              })
              .toList();
        } else {
          recordsToPrint = _hifzRecords;
        }
      } else {
        recordsToPrint = _hifzRecords;
      }

      if (recordsToPrint.isEmpty) {
        if (mounted) {
          setState(() => isLoading = false);
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(isUrduGlobal.value ? "پرنٹ کے لیے کوئی ریکارڈ موجود نہیں ہے۔" : "No records to print."),
              backgroundColor: Colors.orange,
            ),
          );
        }
        return;
      }

      List<List<Map<String, dynamic>>> chunks = [];
      List<Map<String, dynamic>> currentChunk = [];
      double currentHeight = 0;
      bool isFirstPage = true;

      for (var h in recordsToPrint) {
        double maxPageHeight = isFirstPage ? 520.0 : 640.0;
        String dtStr = h['date']?.toString() ?? '';
        String sid = h['student_id']?.toString() ?? '';
        var comps = _complaints.where((c) =>
                c['student_id'].toString() == sid &&
                (c['date'] == dtStr || dtStr.contains(c['date'] ?? '')))
            .toList();
        String dailyComp = '-';
        if (comps.isNotEmpty) {
          dailyComp = comps.map((c) {
            String raw = c['complaint'] ?? '';
            final match = RegExp(r'^\[.*?\]\s*(.*)$', dotAll: true).firstMatch(raw);
            return match != null ? match.group(1) : raw;
          }).join('\n');
        }

        int compLines = dailyComp.split('\n').length;
        int manzilLines = (h['manzil_details']?.toString() ?? '').split('\n').length;
        int maxLines = compLines > manzilLines ? compLines : manzilLines;
        double rowHeight = 40.0 + (maxLines > 1 ? (maxLines - 1) * 18.0 : 0);

        if (currentChunk.isNotEmpty && currentHeight + rowHeight > maxPageHeight) {
          chunks.add(List.from(currentChunk));
          currentChunk.clear();
          currentHeight = 0;
          isFirstPage = false;
        }

        currentChunk.add(h);
        currentHeight += rowHeight;
      }

      if (currentChunk.isNotEmpty) {
        chunks.add(currentChunk);
      }

      if (chunks.isEmpty) {
        if (mounted) setState(() => isLoading = false);
        return;
      }

      final pdf = pw.Document();
      int currentGlobalIndex = 0;
      for (int i = 0; i < chunks.length; i++) {
        final chunk = chunks[i];
        final pageNum = i + 1;
        final pageWidget = _buildPdfPageWidget(
          chunk: chunk,
          pageNum: pageNum,
          totalPages: chunks.length,
          startIndex: currentGlobalIndex,
        );
        currentGlobalIndex += chunk.length;

        final imageBytes = await screenshotController.captureFromWidget(
          pageWidget,
          delay: const Duration(milliseconds: 300),
          targetSize: const Size(1122, 793),
          pixelRatio: 2.0,
        );

        pdf.addPage(pw.Page(
          pageFormat: PdfPageFormat.a4.landscape,
          margin: pw.EdgeInsets.zero,
          build: (pw.Context context) {
            return pw.FullPage(
              ignoreMargins: true,
              child: pw.Image(pw.MemoryImage(imageBytes), fit: pw.BoxFit.fill),
            );
          },
        ));
      }

      final pdfBytes = await pdf.save();
      if (!mounted) return;
      setState(() => isLoading = false);

      await showDialog(
        context: context,
        builder: (ctx) => Dialog(
          insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Scaffold(
              appBar: AppBar(
                title: Text(isUrduGlobal.value ? "رپورٹ پریویو و پرنٹنگ" : "Report Preview & Print"),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                leading: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.pop(ctx),
                ),
              ),
              body: PdfPreview(
                build: (format) => pdfBytes,
                canChangeOrientation: false,
                canChangePageFormat: false,
                canDebug: false,
                pdfFileName: 'Hifz_Report.pdf',
              ),
            ),
          ),
        ),
      );
    } catch (e) {
      debugPrint("Print Error: $e");
      if (mounted) {
        setState(() => isLoading = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text("Print failed: $e")));
      }
    }
  }



  Widget _buildPdfPageWidget({

    required List<Map<String, dynamic>> chunk,

    required int pageNum,

    required int totalPages,

    required int startIndex,

  }) {

    const primary = Color(0xFF284D3D);

    const gold = Color(0xFFD4AC0D);

    const urduFont =

        TextStyle(fontFamily: 'JameelNooriNastaleeq', color: Colors.black);

    bool isIndiv = selectedStudentId != null;



    return Directionality(

      textDirection: TextDirection.rtl,

      child: MediaQuery(

        data: const MediaQueryData(size: Size(1122, 793)),

        child: Theme(

          data: ThemeData(fontFamily: 'JameelNooriNastaleeq'),

          child: DefaultTextStyle(

            style: const TextStyle(fontFamily: 'JameelNooriNastaleeq', color: Colors.black),

            child: SizedBox(

              width: 1122,

              height: 793,

              child: Material(

                color: Colors.white,

              child: Container(

                padding: const EdgeInsets.all(24),

                child: Column(

                  children: [

                    Container(

                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),

                      decoration: BoxDecoration(

                          color: primary, borderRadius: BorderRadius.circular(8)),

                      child: Row(

                        mainAxisAlignment: MainAxisAlignment.spaceBetween,

                        children: [

                          Image.asset('assets/logo.png',

                              height: 45, width: 45,

                              errorBuilder: (_, __, ___) => const Icon(

                                  Icons.school, color: Colors.white, size: 45)),

                          Column(

                            children: [

                              Text("     ",

                                  style: urduFont.copyWith(

                                      fontSize: 20, color: Colors.white, fontWeight: FontWeight.bold)),

                              Text("  ",

                                  style: urduFont.copyWith(fontSize: 14, color: gold)),

                              Text(": $fromDate  $toDate",

                                  style: urduFont.copyWith(fontSize: 12, color: Colors.white)),

                            ],

                          ),

                          Text(" $pageNum / $totalPages",

                              style: urduFont.copyWith(color: Colors.white, fontSize: 14)),

                        ],

                      ),

                    ),

                    const SizedBox(height: 12),

                    if (isIndiv && pageNum == 1)

                      Container(

                        width: double.infinity,

                        padding: const EdgeInsets.all(8),

                        decoration: BoxDecoration(

                            color: Colors.grey.shade200, borderRadius: BorderRadius.circular(8)),

                        child: Row(

                          mainAxisAlignment: MainAxisAlignment.center,

                          children: [

                            Text(" : ", style: urduFont.copyWith(fontSize: 16, fontWeight: FontWeight.bold)),

                            Text(_getStudentName(selectedStudentId!), style: urduFont.copyWith(fontSize: 16)),

                          ],

                        ),

                      ),

                    const SizedBox(height: 12),

                    Expanded(

                      child: FittedBox(

                        fit: BoxFit.scaleDown,

                        alignment: Alignment.topCenter,

                        child: SizedBox(

                          width: 1122 - 48,

                          child: Container(

                            decoration: BoxDecoration(border: Border.all(color: Colors.grey.shade600, width: 1.5)),

                            child: Column(

                          children: [

                            // Header

                            SizedBox(

                                height: 55,

                                child: Row(

                                crossAxisAlignment: CrossAxisAlignment.stretch,

                                children: [

                                  _nativeHeaderBlock("#", 40, bgColor: Colors.grey.shade300),

                                  if (!isIndiv) _nativeHeaderBlock("طالب علم", 110, bgColor: Colors.grey.shade300),

                                  _nativeHeaderBlock("تاریخ", 70, bgColor: Colors.grey.shade300),

                                  _nativeHeaderSection("سبق", 145, [

                                    _nativeSubCol("پارہ", 45),

                                    _nativeSubCol("لائنیں", 45),

                                    _nativeSubCol("کیفیت", 55),

                                  ], bgColor: Colors.green.shade200),

                                  _nativeHeaderSection("سبقی", 225, [

                                    _nativeSubCol("پارہ", 45),

                                    _nativeSubCol("مقدار", 55),

                                    _nativeSubCol("کیفیت", 60),

                                    _nativeSubCol("سننے والا", 65),

                                  ], bgColor: Colors.blue.shade200),

                                  _nativeHeaderSection("منزل", 260, [

                                    _nativeSubCol("تفصیل", 75),

                                    _nativeSubCol("مقدار", 60),

                                    _nativeSubCol("کیفیت", 60),

                                    _nativeSubCol("سننے والا", 65),

                                  ], bgColor: Colors.orange.shade200),

                                  _nativeHeaderBlock("شکایات / کیفیت", isIndiv ? 334 : 224, bgColor: Colors.red.shade200),

                                ],

                              ),

                            ),

                            // Data rows

                            ...chunk.asMap().entries.map((entry) {

                              int index = entry.key;

                              var h = entry.value;

                              int srNo = startIndex + index + 1;

                              String dtStr = h['date']?.toString() ?? '';

                              String sid = h['student_id']?.toString() ?? '';

                              var comps = _complaints.where((c) =>

                                      c['student_id'].toString() == sid &&

                                      (c['date'] == dtStr || dtStr.contains(c['date'] ?? '')))

                                  .toList();

                              String dailyComp = '-';

                              if (comps.isNotEmpty) {

                                dailyComp = comps.map((c) {

                                  String raw = c['complaint'] ?? '';

                                  final match = RegExp(r'^\[.*?\]\s*(.*)$', dotAll: true).firstMatch(raw);

                                  return match != null ? match.group(1) : raw;

                                }).join('\n');

                              }

                              return IntrinsicHeight(

                                child: Row(

                                  crossAxisAlignment: CrossAxisAlignment.stretch,

                                  children: [

                                    _nativeCell(srNo.toString(), 40),

                                    if (!isIndiv) _nativeCell(_getStudentName(sid), 110),

                                    _nativeCell(dtStr, 70),

                                    _nativeCell(cleanUrduString(h['sabaq_para']), 45),

                                    _nativeCell(h['sabaq_lines']?.toString() ?? '-', 45),

                                    _nativeCell(translateStatusClean(h['sabaq_grade']), 55, textColor: _getNativeStatusColor(h['sabaq_grade'])),

                                    

                                    _nativeCell(cleanUrduString(h['sabqi_para']), 45),

                                    _nativeCell(translateQtyClean(h['sabqi_qty']), 55),

                                    _nativeCell(translateStatusClean(h['sabqi_grade']), 60, textColor: _getNativeStatusColor(h['sabqi_grade'])),

                                    _nativeCell(h['sabqi_listener']?.toString() ?? '-', 65),

                                    

                                    _nativeCell(cleanUrduString(h['manzil_details']), 75),

                                    _nativeCell(translateQtyClean(h['manzil_total_qty']), 60),

                                    _nativeCell(translateStatusClean(h['manzil_grade']), 60, textColor: _getNativeStatusColor(h['manzil_grade'])),

                                    _nativeCell(h['manzil_listener']?.toString() ?? '-', 65),

                                    

                                    _nativeCell(cleanUrduString(dailyComp), isIndiv ? 334 : 224, alignLeft: false),

                                  ],

                                ),

                              );

                            }),

                          ],

                        ),

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

      ),

    ));

  }



  Widget _nativeHeaderBlock(String text, double w, {Color? bgColor}) {

    return Container(

      width: w > 0 ? w : null,

      decoration: BoxDecoration(

        color: bgColor,

        border: Border.all(color: Colors.grey.shade600, width: 0.5),

      ),

      alignment: Alignment.center,

      padding: const EdgeInsets.all(4),

      child: Text(text, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, fontFamily: 'JameelNooriNastaleeq'), textAlign: TextAlign.center),

    );

  }



  Widget _nativeHeaderSection(String title, double width, List<Widget> children, {Color? bgColor}) {

    return Container(

      width: width,

      decoration: BoxDecoration(

        border: Border.all(color: Colors.grey.shade600, width: 0.5),

      ),

      child: Column(

        crossAxisAlignment: CrossAxisAlignment.stretch,

        children: [

          Container(

            color: bgColor,

            padding: const EdgeInsets.symmetric(vertical: 4),

            alignment: Alignment.center,

            decoration: BoxDecoration(

                border: Border(bottom: BorderSide(color: Colors.grey.shade600, width: 0.5))),

            child: Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, fontFamily: 'JameelNooriNastaleeq')),

          ),

          Expanded(

            child: Row(

              crossAxisAlignment: CrossAxisAlignment.stretch,

              children: children,

            ),

          )

        ],

      ),

    );

  }



  Widget _nativeSubCol(String text, double w) {

    return Container(

      width: w,

      color: Colors.grey.shade200,

      alignment: Alignment.center,

      decoration: BoxDecoration(

        border: Border(left: BorderSide(color: Colors.grey.shade600, width: 0.5), right: BorderSide(color: Colors.grey.shade600, width: 0.5)),

      ),

      padding: const EdgeInsets.all(2),

      child: Text(text, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11, fontFamily: 'JameelNooriNastaleeq'), textAlign: TextAlign.center),

    );

  }



  Widget _nativeCell(String text, double w, {Color? textColor, bool alignLeft = false}) {

    return Container(

      width: w > 0 ? w : null,

      decoration: BoxDecoration(

        border: Border.all(color: Colors.grey.shade400, width: 0.5),

      ),

      alignment: alignLeft ? Alignment.centerLeft : Alignment.center,

      padding: const EdgeInsets.all(4),

      child: Text(text, style: TextStyle(fontSize: 11, color: textColor ?? Colors.black, fontFamily: 'JameelNooriNastaleeq'), textAlign: alignLeft ? TextAlign.left : TextAlign.center),

    );

  }



  Color _getNativeStatusColor(String? status) {

    if (status == 'Excellent') return Colors.green.shade800;

    if (status == 'Good') return Colors.blue.shade800;

    if (status == 'Weak') return Colors.red.shade800;

    if (status == 'Average') return Colors.orange.shade800;

    if (status == ' ') return Colors.red.shade800;

    if (status == '') return Colors.green.shade800;

    if (status == '') return Colors.blue.shade800;

    return Colors.black;

  }

}



