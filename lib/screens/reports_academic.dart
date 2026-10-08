// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:screenshot/screenshot.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../utils/performance_helper.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
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

class ReportsAcademicPage extends StatefulWidget {
  final String? className;
  const ReportsAcademicPage({super.key, this.className});

  @override
  _ReportsAcademicPageState createState() => _ReportsAcademicPageState();
}

class _ReportsAcademicPageState extends State<ReportsAcademicPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  int _recitedCount = 0;
  int _notRecitedCount = 0;
  int _naaghaCount = 0;
  int _absentCount = 0;
  int _leaveCount = 0;
  List<Map<String, dynamic>> _recitedReports = [];
  List<Map<String, dynamic>> _notRecitedReports = [];
  List<Map<String, dynamic>> _naaghaReports = [];
  List<Map<String, dynamic>> _absentReports = [];
  List<Map<String, dynamic>> _leaveReports = [];
  Map<String, String> _attMap = {};

  int _naagha0Count = 0;
  int _naagha1Count = 0;
  int _naagha2Count = 0;
  int _naagha3Count = 0;
  int _naaghaMoreCount = 0;
  Map<String, int> _studentNaaghaCountMap = {};
  Map<String, List<String>> _studentNaaghaDatesMap = {};

  Map<String, List<Map<String, dynamic>>> _reportsGrouped = {};
  List<Map<String, dynamic>> _students = [];
  Map<String, Map<String, dynamic>> _allStudentMap = {};
  List<String> _classes = [];
  String? _selectedClass;
  bool isLoading = true;
  String _loadingStep = '';
  String? _errorMessage;
  String? fromDate;
  String? toDate;
  String? selectedStudentId;
  String selectedPeriod = 'current_week';

  List<DateChunk> _dateChunks = [];
  int _selectedChunkIndex = 0;
  List<Map<String, dynamic>> _cachedRawReports = [];
  List<Map<String, dynamic>> _cachedRawAtt = [];
  final ScreenshotController screenshotController = ScreenshotController();

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

  bool _matchesSelectedClass(Map<String, dynamic> r) {
    if (_selectedClass == null || _selectedClass!.isEmpty) return true;
    final normTarget = _normalizeClassName(_selectedClass);
    final normClass = _normalizeClassName(r['class_name'] ?? r['class']);
    if (normClass.isNotEmpty && normClass == normTarget) return true;

    final sid = r['student_id']?.toString() ?? '';
    final student = _allStudentMap[sid] ??
        _students.firstWhere(
          (s) => s['id']?.toString() == sid,
          orElse: () => <String, dynamic>{},
        );
    final normStudentClass =
        _normalizeClassName(student['class_name'] ?? student['class']);
    return normStudentClass.isNotEmpty && normStudentClass == normTarget;
  }

  @override
  void initState() {
    super.initState();
    _selectedClass = widget.className;
    _loadData();
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
      final databaseInstance = await db.database;
      final sysCond = isMasjid
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      const activeStatusCond =
          "(is_deleted = 0 OR is_deleted IS NULL) AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر'))";

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

      _students = validStudents;

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
        _selectedChunkIndex = 0; // Default to full period for 100% web parity
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

      List<Map<String, dynamic>> allReports = [];
      List<Map<String, dynamic>> extraMaktabReports = [];
      List<Map<String, dynamic>> allAttForInjection = [];
      List<Map<String, dynamic>> extraAtt = [];

      if (sidsList.isNotEmpty) {
        final placeholders = List.filled(sidsList.length, '?').join(',');
        allReports = await database.query(
          'academic_reports',
          where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: sidsList,
          orderBy: 'date DESC, id DESC',
        );
        try {
          extraMaktabReports = await database.query(
            'maktab_academic_reports',
            where: 'student_id IN ($placeholders) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: sidsList,
            orderBy: 'date DESC, id DESC',
          );
        } catch (_) {}

        allAttForInjection = await database.query(
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
        allReports = await database.query('academic_reports', where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'date DESC, id DESC', limit: 200);
        try {
          extraMaktabReports = await database.query('maktab_academic_reports', where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'date DESC, id DESC', limit: 200);
        } catch (_) {}
      }
      _cachedRawReports = [...allReports, ...extraMaktabReports];
      _cachedRawAtt = [...allAttForInjection, ...extraAtt];

      await _processActiveChunk();
    } catch (e, stack) {
      debugPrint("Load Academic Report Error: $e\n$stack");
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
      final relevantAtt = _cachedRawAtt.where((a) {
        String sid = a['student_id']?.toString() ?? '';
        if (!studentIdSet.contains(sid)) return false;
        try {
          DateTime d = _parseRobust(a['date'] ?? '');
          return !d.isBefore(sDate) && !d.isAfter(eDate);
        } catch (_) {
          return false;
        }
      }).toList();

      // Only reports within active 10-day chunk
      final chunkReports = _cachedRawReports.where((r) {
        String sid = r['student_id']?.toString() ?? '';
        if (selectedStudentId != null && sid != selectedStudentId) return false;
        if (!studentIdSet.contains(sid)) return false;
        if (!_matchesSelectedClass(r)) return false;
        try {
          DateTime fDate = _parseRobust(r['date']?.toString() ?? '');
          return !fDate.isBefore(sDate) && !fDate.isAfter(eDate);
        } catch (_) {
          return false;
        }
      }).toList();

      // Missing attendance injection scoped to 10-day chunk only
      final injectedReports = PerformanceCalculator.injectMissingAttendanceRecords(
        chunkReports,
        relevantAtt,
        _students,
        'academic',
      );

      Map<String, List<Map<String, dynamic>>> grouped = {};

      for (var r in injectedReports) {
        String sid = r['student_id'].toString();
        if (selectedStudentId != null && sid != selectedStudentId) continue;
        if (!_matchesSelectedClass(r)) continue;

        if (selectedStudentId == null) {
          bool isStudentValid = _students.any((s) => s['id'].toString() == sid);
          if (!isStudentValid) continue;
        }

        try {
          DateTime fDate = _parseRobust(r['date']?.toString() ?? '');
          if (fDate.isBefore(sDate) || fDate.isAfter(eDate)) continue;
        } catch (_) {
          continue;
        }
        grouped.putIfAbsent(sid, () => []).add(r);
      }

      final chunkEndStr = intl.DateFormat('dd/MM/yyyy').format(eDate);
      for (var s in _students) {
        String sid = s['id'].toString();
        if (selectedStudentId != null && sid != selectedStudentId) continue;
        if (!grouped.containsKey(sid) || grouped[sid]!.isEmpty) {
          grouped[sid] = [
            {
              'student_id': s['id'],
              'class_name': s['class_name'] ?? s['class'] ?? widget.className ?? '',
              'date': chunkEndStr,
              'subject': '-',
              'lesson_details': '-',
              'grade': '-',
              'remarks': '-',
              'is_dummy': 1,
            }
          ];
        }
      }

      _reportsGrouped = grouped;

      Map<String, String> attMap = {};
      for (var a in relevantAtt) {
        String rawDt = a['date']?.toString().trim() ?? '';
        String sid = a['student_id']?.toString().trim() ?? '';
        String st = [
          a['status'],
          a['status_1'],
          a['status_2'],
          a['status_3'],
          a['status_4'],
          a['status_5'],
          a['status_6'],
          a['status_7'],
          a['status_8']
        ].where((e) => e != null && e.toString().trim().isNotEmpty).join(' ').toUpperCase();

        var tokens = st.split(RegExp(r'\s+'));
        String val = 'P';
        if (st.contains('ABSENT') || st.contains('غائب') || st.contains('غیر حاضر') || st.contains('غير حاضر') || st.contains('غیرحاضر') || st.contains('غيرحاضر') || tokens.contains('A') || tokens.contains('غ')) {
          val = 'A';
        } else if (st.contains('LEAVE') || st.contains('رخصت') || st.contains('چھٹی') || tokens.contains('L') || tokens.contains('ر')) {
          val = 'L';
        }

        if (sid.isNotEmpty && rawDt.isNotEmpty) {
          void setKey(String k, String v) {
            String curr = attMap[k] ?? '';
            if (curr == 'A') return;
            if (curr == 'L' && v != 'A') return;
            attMap[k] = v;
          }

          setKey('${rawDt}_$sid', val);
          try {
            String nDate = PerformanceCalculator.normalizeDate(rawDt);
            if (nDate.isNotEmpty && nDate != '1970-01-01') {
              setKey('${nDate}_$sid', val);
            }
          } catch (_) {}
          try {
            DateTime d = _parseRobust(rawDt);
            String isoDate = "${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";
            String dMyDate = "${d.day}/${d.month}/${d.year}";
            String ddMMyyyy = "${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}";
            setKey('${isoDate}_$sid', val);
            setKey('${dMyDate}_$sid', val);
            setKey('${ddMMyyyy}_$sid', val);
          } catch (_) {}
        }
      }

      int recited = 0;
      int notRecited = 0;
      int naagha = 0;
      int absentCount = 0;
      int leaveCount = 0;
      List<Map<String, dynamic>> recitedList = [];
      List<Map<String, dynamic>> notRecitedList = [];
      List<Map<String, dynamic>> naaghaList = [];
      List<Map<String, dynamic>> absentList = [];
      List<Map<String, dynamic>> leaveList = [];

      Map<String, int> studentNaaghaMap = {};
      Map<String, List<String>> studentNaaghaDatesMap = {};

      for (var entry in grouped.entries) {
        String sid = entry.key;
        for (var r in entry.value) {
          String rDate = r['date']?.toString().trim() ?? '';
          String att = attMap['${rDate}_$sid'] ?? '';
          if (att.isEmpty) {
            try {
              String normR = PerformanceCalculator.normalizeDate(rDate);
              att = attMap['${normR}_$sid'] ?? '';
            } catch (_) {}
          }
          if (att.isEmpty) {
            try {
              DateTime parsed = _parseRobust(rDate);
              String isoDate = "${parsed.year.toString().padLeft(4, '0')}-${parsed.month.toString().padLeft(2, '0')}-${parsed.day.toString().padLeft(2, '0')}";
              String dMyDate = "${parsed.day}/${parsed.month}/${parsed.year}";
              String ddMMyyyy = "${parsed.day.toString().padLeft(2, '0')}/${parsed.month.toString().padLeft(2, '0')}/${parsed.year}";
              att = attMap['${isoDate}_$sid'] ?? attMap['${dMyDate}_$sid'] ?? attMap['${ddMMyyyy}_$sid'] ?? '';
            } catch (_) {}
          }

          String lessonDet = (r['lesson_details'] ?? '').toString().trim();
          String gradeDet = (r['grade'] ?? '').toString().trim();
          final reportWithSid = Map<String, dynamic>.from(r)..['student_id'] = sid;

          bool isAbsent = (att == 'A' || gradeDet.contains('غیر حاضر') || gradeDet.contains('غير حاضر') || lessonDet.contains('غیر حاضر') || lessonDet.contains('غير حاضر') || gradeDet.toLowerCase() == 'absent');
          bool isLeave = (att == 'L' || gradeDet.contains('رخصت') || lessonDet.contains('رخصت') || gradeDet.toLowerCase() == 'leave');

          // If it's a dummy record or unrecorded date placeholder with no attendance
          if (r['is_dummy'] == 1 && !isAbsent && !isLeave && att != 'P') {
            continue;
          }

          bool isExplicitNaagha = (gradeDet.contains('ناغہ') || lessonDet.contains('ناغہ') || gradeDet == 'Naagha' || gradeDet == 'سبق نہیں سنایا');
          bool isNotRecited = (att == 'P' && (lessonDet.isEmpty || lessonDet == '-' || isExplicitNaagha));

          if (isAbsent) {
            absentCount++;
            absentList.add(reportWithSid);
          } else if (isLeave) {
            leaveCount++;
            leaveList.add(reportWithSid);
          } else if (isNotRecited) {
            notRecited++;
            notRecitedList.add(reportWithSid);
            studentNaaghaMap[sid] = (studentNaaghaMap[sid] ?? 0) + 1;
            studentNaaghaDatesMap.putIfAbsent(sid, () => []).add(rDate);
          } else if (isExplicitNaagha) {
            naagha++;
            naaghaList.add(reportWithSid);
            studentNaaghaMap[sid] = (studentNaaghaMap[sid] ?? 0) + 1;
            studentNaaghaDatesMap.putIfAbsent(sid, () => []).add(rDate);
          } else if (att == 'P' && (gradeDet.isEmpty || gradeDet == '-' || lessonDet.isEmpty || lessonDet == '-')) {
            // Present but did not recite
            notRecited++;
            notRecitedList.add(reportWithSid);
            studentNaaghaMap[sid] = (studentNaaghaMap[sid] ?? 0) + 1;
            studentNaaghaDatesMap.putIfAbsent(sid, () => []).add(rDate);
          } else if (gradeDet.isEmpty && lessonDet.isEmpty && att.isEmpty) {
            // Unrecorded date without attendance
            continue;
          } else {
            recited++;
            recitedList.add(reportWithSid);
          }
        }
      }

      int naagha0 = 0, naagha1 = 0, naagha2 = 0, naagha3 = 0, naaghaMore = 0;
      for (var s in _students) {
        String sid = s['id'].toString();
        int cnt = studentNaaghaMap[sid] ?? 0;
        if (cnt == 0) {
          naagha0++;
        } else if (cnt == 1) {
          naagha1++;
        } else if (cnt == 2) {
          naagha2++;
        } else if (cnt == 3) {
          naagha3++;
        } else {
          naaghaMore++;
        }
      }

      _recitedCount = recited;
      _notRecitedCount = notRecited;
      _naaghaCount = naagha;
      _absentCount = absentCount;
      _leaveCount = leaveCount;
      _recitedReports = recitedList;
      _notRecitedReports = notRecitedList;
      _naaghaReports = naaghaList;
      _absentReports = absentList;
      _leaveReports = leaveList;

      _naagha0Count = naagha0;
      _naagha1Count = naagha1;
      _naagha2Count = naagha2;
      _naagha3Count = naagha3;
      _naaghaMoreCount = naaghaMore;
      _studentNaaghaCountMap = studentNaaghaMap;
      _studentNaaghaDatesMap = studentNaaghaDatesMap;
      _attMap = attMap;
      _errorMessage = null;
    } catch (e, stack) {
      debugPrint("Process Active Chunk Error: $e\n$stack");
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
        border: Border.all(color: primaryGreen.withValues(alpha: 0.3)),
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
                  child: Text(isUrduGlobal.value ? "-- تمام کلاسز --" : "-- All Classes --", overflow: TextOverflow.ellipsis)),
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

          final studentDropdown = DropdownButtonFormField<String>(
            value: _students.any((s) => s['id'].toString() == selectedStudentId)
                ? selectedStudentId
                : null,
            isExpanded: true,
            menuMaxHeight: 350,
            decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "طالب علم منتخب کریں" : "Select Student",
                border: const OutlineInputBorder(),
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
            items: [
              DropdownMenuItem(
                  value: null,
                  child: Text(isUrduGlobal.value ? "-- تمام طلباء (ڈیفالٹ) --" : "-- All Students (Default) --", overflow: TextOverflow.ellipsis)),
              ..._students.map((s) {
                final roll = (s['roll_number']?.toString().isNotEmpty == true)
                    ? s['roll_number'].toString()
                    : (s['admission_number']?.toString() ?? '');
                final name = s['name']?.toString() ?? '';
                final fName = s['father_name']?.toString().trim() ?? '';
                final label = roll.isNotEmpty
                    ? "$roll - $name ${fName.isNotEmpty ? (isUrduGlobal.value ? '(ولد: $fName)' : '(S/O $fName)') : ''}"
                    : "$name ${fName.isNotEmpty ? (isUrduGlobal.value ? '(ولد: $fName)' : '(S/O $fName)') : ''}";
                return DropdownMenuItem<String>(
                  value: s['id'].toString(),
                  child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
                );
              }),
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
                studentDropdown,
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
                  : (isUrduGlobal.value ? "تعلیمی ریکارڈز کا جائزہ لیا جا رہا ہے..." : "Processing records..."),
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
                  : "Please wait, data is loading in stages",
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
        margin: const EdgeInsets.all(20),
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: Colors.red.shade50,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.red.shade200),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, color: Colors.red, size: 40),
            const SizedBox(height: 12),
            Text(
              _errorMessage ?? (isUrduGlobal.value ? "ڈیٹا لوڈ کرنے میں خرابی پیش آئی" : "An error occurred"),
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.red.shade900, fontWeight: FontWeight.bold, fontSize: 14),
            ),
            const SizedBox(height: 16),
            ElevatedButton.icon(
              onPressed: _loadData,
              icon: const Icon(Icons.refresh, size: 18),
              label: Text(isUrduGlobal.value ? "دوبارہ کوشش کریں" : "Retry"),
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
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

  String _getStudentRollNo(String sid) {
    final cleanSid = sid.trim();
    if (cleanSid.isEmpty) return "-";
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
    if (s.isNotEmpty) {
      for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
        final val = (s[key] ?? '').toString().trim();
        if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
          return val;
        }
      }
    }
    return "-";
  }

  Color getStatusColor(String? status) {
    if (status == null) return Colors.black;
    if (status.contains('بہترین')) return Colors.green;
    if (status.contains('بہتر')) return Colors.blue;
    if (status.contains('رعایتی پاس')) return Colors.orange;
    if (status.contains('کچا')) return Colors.red;
    if (status.contains('ممتاز') ||
        status.contains('Excellent') ||
        status.contains('پختہ')) {
      return Colors.green;
    }
    if (status.contains('بہتر') || status.contains('Good')) return Colors.blue;
    if (status.contains('معمولی') ||
        status.contains('Average') ||
        status.contains('کچا')) {
      return Colors.orange;
    }
    if (status.contains('کمزور') ||
        status.contains('Weak') ||
        status.contains('ناقص')) {
      return Colors.red;
    }
    return Colors.black;
  }

  Widget _cell(String text, double width,
      {Color? bgColor,
      Color? textColor,
      bool isHeader = false,
      bool bold = false}) {
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
        maxLines: null,
        overflow: TextOverflow.clip,
        style: TextStyle(
          fontWeight: (bold || isHeader) ? FontWeight.bold : FontWeight.normal,
          fontSize: isHeader ? 12 : 11,
          color: textColor ?? Colors.black,
        ),
      ),
    );
  }

  // ignore: unused_element
  Widget _buildTableHeader() {
    return Row(
      children: [
        _cell(isUrduGlobal.value ? "تاریخ" : "Date", 80, isHeader: true),
        _cell(isUrduGlobal.value ? "کلاس" : "Class", 100, isHeader: true),
        _cell(isUrduGlobal.value ? "مضمون" : "Subject", 120, isHeader: true),
        _cell(isUrduGlobal.value ? "سبق کی تفصیل" : "Lesson Details", 250,
            isHeader: true),
        _cell(isUrduGlobal.value ? "کیفیت" : "Grade", 80, isHeader: true),
        _cell(isUrduGlobal.value ? "ریمارکس" : "Remarks", 200, isHeader: true),
      ],
    );
  }


  // ignore: unused_element
  Widget _buildDataRow(Map<String, dynamic> r) {
    return Row(
      children: [
        _cell(r['date'] ?? '-', 80, bold: true),
        _cell(r['class_name'] ?? '-', 100),
        _cell(r['subject'] ?? '-', 120),
        _cell(r['lesson_details'] ?? '-', 250),
        _cell(r['grade'] ?? '-', 80, textColor: getStatusColor(r['grade'])),
        _cell(r['remarks'] ?? '-', 200),
      ],
    );
  }

  Widget _buildSummaryBanner() {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: primaryGreen.withValues(alpha: 0.3)),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4, offset: Offset(0, 2))],
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceAround,
          children: [
            _summaryCard(
              isUrduGlobal.value ? "سنانے والے طلبہ" : "Recited", 
              _recitedCount.toString(), 
              Colors.green,
              onTap: () => _showCategoryModal(
                isUrduGlobal.value ? "سنانے والے طلبہ کی فہرست" : "Recited Students",
                _recitedReports,
                Colors.green,
              ),
            ),
            const SizedBox(width: 8),
            _summaryCard(
              isUrduGlobal.value ? "نہ سنانے والے طلبہ" : "Not Recited", 
              _notRecitedCount.toString(), 
              Colors.orange.shade900,
              onTap: () => _showCategoryModal(
                isUrduGlobal.value ? "نہ سنانے والے طلبہ کی فہرست" : "Not Recited Students",
                _notRecitedReports,
                Colors.orange.shade900,
              ),
            ),
            const SizedBox(width: 8),
            _summaryCard(
              isUrduGlobal.value ? "ناغہ کرنے والے طلبہ" : "Naagha", 
              _naaghaCount.toString(), 
              Colors.red,
              onTap: () => _showCategoryModal(
                isUrduGlobal.value ? "ناغہ کرنے والے طلبہ کی فہرست" : "Naagha Students",
                _naaghaReports,
                Colors.red,
              ),
            ),
            const SizedBox(width: 8),
            _summaryCard(
              isUrduGlobal.value ? "غیر حاضر طلبہ" : "Absent", 
              _absentCount.toString(), 
              Colors.red.shade900,
              onTap: () => _showCategoryModal(
                isUrduGlobal.value ? "غیر حاضر طلبہ کی فہرست" : "Absent Students",
                _absentReports,
                Colors.red.shade900,
              ),
            ),
            const SizedBox(width: 8),
            _summaryCard(
              isUrduGlobal.value ? "رخصت طلبہ" : "Leave", 
              _leaveCount.toString(), 
              Colors.orange.shade800,
              onTap: () => _showCategoryModal(
                isUrduGlobal.value ? "رخصت والے طلبہ کی فہرست" : "Leave Students",
                _leaveReports,
                Colors.orange.shade800,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryCard(String title, String count, Color color, {VoidCallback? onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        child: Column(
          children: [
            Text(count, style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: color)),
            const SizedBox(height: 2),
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(title, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.grey.shade800)),
                const SizedBox(width: 4),
                Icon(Icons.list_alt, size: 12, color: color.withValues(alpha: 0.8)),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showCategoryModal(String title, List<Map<String, dynamic>> reports, Color color) {
    final isDesktop = MediaQuery.of(context).size.width >= 700;
    Widget buildModalContent(BuildContext ctx) {
      return Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: color,
              borderRadius: isDesktop
                  ? const BorderRadius.vertical(top: Radius.circular(16))
                  : const BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    const Icon(Icons.list_alt, color: Colors.white, size: 20),
                    const SizedBox(width: 8),
                    Text(
                      "$title (${reports.length})",
                      style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
                Row(
                  children: [
                    if (reports.isNotEmpty)
                      IconButton(
                        icon: const Icon(Icons.print, color: Colors.white),
                        tooltip: isUrduGlobal.value ? "پرنٹ کریں" : "Print",
                        onPressed: () => _printCategoryList(title, reports, color),
                      ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white),
                      onPressed: () => Navigator.pop(ctx),
                    ),
                  ],
                ),
              ],
            ),
          ),
          Expanded(
            child: reports.isEmpty
                ? Center(
                    child: Text(
                      isUrduGlobal.value ? "کوئی ریکارڈ نہیں ملا" : "No records found",
                      style: const TextStyle(fontSize: 15, color: Colors.grey, fontWeight: FontWeight.bold),
                    ),
                  )
                : ListView.separated(
                    itemCount: reports.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, index) {
                      final r = reports[index];
                      final sid = r['student_id']?.toString() ?? '';
                      final std = _allStudentMap[sid] ??
                          _students.firstWhere(
                            (st) => st['id']?.toString() == sid,
                            orElse: () => <String, dynamic>{},
                          );
                      final stdName = std['name'] ?? r['student_name'] ?? _getStudentName(sid);
                      final rollNo = _getStudentRollNo(sid);
                      final rollSuffix = (rollNo.isNotEmpty && rollNo != '-') ? " (رول: $rollNo)" : "";
                      final fName = std['father_name'] ?? '';
                      final rDate = r['date'] ?? '-';
                      final subject = r['subject'] ?? '-';
                      final lesson = r['lesson_details'] ?? r['grade'] ?? '-';
                      final grade = r['grade'] ?? '-';

                      return ListTile(
                        leading: CircleAvatar(
                          backgroundColor: color.withOpacity(0.15),
                          child: Text('${index + 1}', style: TextStyle(color: color, fontWeight: FontWeight.bold)),
                        ),
                        title: Text(
                          "$stdName ${fName.isNotEmpty ? 'ولد $fName' : ''}$rollSuffix",
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                        subtitle: Text(
                          "تاریخ: $rDate | مضمون: $subject\nسبق: $lesson",
                          style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                        ),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: color.withOpacity(0.1),
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: color.withOpacity(0.5)),
                          ),
                          child: Text(
                            grade.toString().isNotEmpty ? grade.toString() : title.split(' ')[0],
                            style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 11),
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      );
    }

    if (isDesktop) {
      showDialog(
        context: context,
        builder: (ctx) => Dialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720, maxHeight: 620),
            child: buildModalContent(ctx),
          ),
        ),
      );
    } else {
      showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (ctx) {
          return Container(
            height: MediaQuery.of(context).size.height * 0.8,
            decoration: const BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: buildModalContent(ctx),
          );
        },
      );
    }
  }

  Future<void> _printCategoryList(String title, List<Map<String, dynamic>> reports, Color color) async {
    try {
      final pdf = pw.Document();
      pdf.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4,
          margin: const pw.EdgeInsets.all(24),
          build: (pw.Context ctx) => [
            pw.Header(
              level: 0,
              child: pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text(
                    title,
                    style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold),
                  ),
                  pw.Text(
                    "مدت: $fromDate تا $toDate",
                    style: const pw.TextStyle(fontSize: 12),
                  ),
                ],
              ),
            ),
            pw.SizedBox(height: 10),
            pw.TableHelper.fromTextArray(
              headers: ['#', 'رول نمبر', 'طالب علم مع ولدیت', 'تاریخ', 'مضمون', 'سبق / تفصیل', 'کیفیت'],
              data: reports.asMap().entries.map((e) {
                final idx = e.key + 1;
                final r = e.value;
                final sid = r['student_id']?.toString() ?? '';
                final std = _allStudentMap[sid] ?? _students.firstWhere((s) => s['id']?.toString() == sid, orElse: () => <String, dynamic>{});
                final name = std['name'] ?? r['student_name'] ?? _getStudentName(sid);
                final fName = std['father_name'] ?? '';
                final roll = std['roll_number'] ?? std['admission_number'] ?? _getStudentRollNo(sid);
                final fullStd = fName.isNotEmpty ? "$name ولد $fName" : name;
                return [
                  idx.toString(),
                  roll,
                  fullStd,
                  r['date']?.toString() ?? '-',
                  r['subject']?.toString() ?? '-',
                  r['lesson_details']?.toString() ?? '-',
                  r['grade']?.toString() ?? '-',
                ];
              }).toList(),
              headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10),
              cellStyle: const pw.TextStyle(fontSize: 9),
              cellAlignment: pw.Alignment.center,
              headerDecoration: const pw.BoxDecoration(color: PdfColors.grey300),
            ),
          ],
        ),
      );

      final pdfBytes = await pdf.save();
      await Printing.layoutPdf(
        onLayout: (PdfPageFormat format) async => pdfBytes,
        name: '${title.replaceAll(' ', '_')}.pdf',
      );
    } catch (e) {
      debugPrint("Print category error: $e");
    }
  }

  Widget _buildNaaghaSummaryWidget() {
    if (selectedStudentId != null) return const SizedBox.shrink();

    Widget clickableCell(String catName, int c, dynamic countVal, {bool isSuccess = false}) {
      return GestureDetector(
        onTap: () => _showAggregateNaaghaModal(catName, countVal, isSuccess: isSuccess),
        child: MouseRegion(
          cursor: SystemMouseCursors.click,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8.0, horizontal: 4.0),
            child: Text(
              "$c ${isUrduGlobal.value ? 'طلبہ' : 'Students'}",
              textAlign: TextAlign.center,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                decoration: TextDecoration.underline,
                color: isSuccess ? Colors.green.shade700 : (c > 0 ? Colors.red.shade700 : Colors.grey.shade700),
              ),
            ),
          ),
        ),
      );
    }

    return Container(
      width: 960,
      margin: const EdgeInsets.only(top: 25, bottom: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: primaryGreen, width: 2),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 6, offset: Offset(0, 3))],
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.assessment, color: primaryGreen, size: 22),
              const SizedBox(width: 8),
              Text(
                isUrduGlobal.value ? "پیریڈ وار مجموعی ناغہ جات خلاصہ (Aggregate Naagha Summary)" : "Period Missed Lessons Summary",
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: primaryGreen),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Table(
            border: TableBorder.all(color: Colors.grey.shade300),
            children: [
              TableRow(
                decoration: BoxDecoration(color: Colors.grey.shade200),
                children: [
                  _summaryCell(isUrduGlobal.value ? "ناغہ تفصیل" : "Missed Detail", isHeader: true),
                  _summaryCell(isUrduGlobal.value ? "طلبہ تعداد (کلک برائے فہرست)" : "Students Count", isHeader: true, textColor: primaryGreen),
                ],
              ),
              TableRow(
                decoration: BoxDecoration(color: Colors.green.shade50),
                children: [
                  _summaryCell(isUrduGlobal.value ? "بغیر ناغہ (0 ناغہ)" : "No Missed (0)", textColor: Colors.green.shade800),
                  clickableCell(isUrduGlobal.value ? "بلا ناغہ طلبہ (0 ناغہ)" : "0 Missed Students", _naagha0Count, 0, isSuccess: true),
                ],
              ),
              TableRow(
                children: [
                  _summaryCell(isUrduGlobal.value ? "1 ناغہ" : "1 Missed"),
                  clickableCell(isUrduGlobal.value ? "1 ناغہ والے طلبہ" : "1 Missed Students", _naagha1Count, 1),
                ],
              ),
              TableRow(
                children: [
                  _summaryCell(isUrduGlobal.value ? "2 ناغے" : "2 Missed"),
                  clickableCell(isUrduGlobal.value ? "2 ناغے والے طلبہ" : "2 Missed Students", _naagha2Count, 2),
                ],
              ),
              TableRow(
                children: [
                  _summaryCell(isUrduGlobal.value ? "3 ناغے" : "3 Missed"),
                  clickableCell(isUrduGlobal.value ? "3 ناغے والے طلبہ" : "3 Missed Students", _naagha3Count, 3),
                ],
              ),
              TableRow(
                decoration: BoxDecoration(color: Colors.red.shade50),
                children: [
                  _summaryCell(isUrduGlobal.value ? "3 سے زائد ناغے" : "More than 3 Missed", textColor: Colors.red.shade900),
                  clickableCell(isUrduGlobal.value ? "3 سے زائد ناغے والے طلبہ" : "3+ Missed Students", _naaghaMoreCount, 'more'),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _summaryCell(String text, {bool isHeader = false, Color? textColor}) {
    return Padding(
      padding: const EdgeInsets.all(8.0),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(
          fontWeight: isHeader ? FontWeight.bold : FontWeight.normal,
          fontSize: isHeader ? 12 : 11,
          color: textColor ?? Colors.black87,
        ),
      ),
    );
  }

  void _showAggregateNaaghaModal(String catTitle, dynamic countVal, {bool isSuccess = false}) {
    final matchingStudents = <Map<String, dynamic>>[];
    for (var s in _students) {
      final sid = s['id']?.toString() ?? '';
      final freq = _studentNaaghaCountMap[sid] ?? 0;
      bool match = false;
      if (countVal == 'more') {
        match = freq > 3;
      } else {
        match = freq == countVal;
      }
      if (match) {
        matchingStudents.add({
          'sid': sid,
          'name': s['name'] ?? '',
          'father_name': s['father_name'] ?? '',
          'roll_number': s['roll_number'] ?? s['admission_number'] ?? '-',
          'class_name': s['class_name'] ?? s['class'] ?? _selectedClass ?? '',
          'count': freq,
          'dates': _studentNaaghaDatesMap[sid] ?? [],
        });
      }
    }

    final isDesktop = MediaQuery.of(context).size.width >= 700;
    final themeColor = isSuccess ? Colors.green.shade800 : Colors.red.shade800;

    Widget buildModalContent(BuildContext ctx) {
      return Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: themeColor,
              borderRadius: isDesktop
                  ? const BorderRadius.vertical(top: Radius.circular(16))
                  : const BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  "$catTitle (${matchingStudents.length} ${isUrduGlobal.value ? 'طلبہ' : 'Students'})",
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                ),
                Row(
                  children: [
                    if (matchingStudents.isNotEmpty)
                      IconButton(
                        icon: const Icon(Icons.print, color: Colors.white),
                        tooltip: isUrduGlobal.value ? "پرنٹ / پریویو" : "Print / Preview",
                        onPressed: () => _printAggregateNaaghaList(catTitle, matchingStudents, isSuccess: isSuccess),
                      ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white),
                      onPressed: () => Navigator.pop(ctx),
                    ),
                  ],
                ),
              ],
            ),
          ),
          Expanded(
            child: matchingStudents.isEmpty
                ? Center(
                    child: Text(
                      isUrduGlobal.value ? "اس کیٹیگری میں کوئی طالب علم نہیں ہے" : "No students in this category",
                      style: const TextStyle(fontSize: 15, color: Colors.grey, fontWeight: FontWeight.bold),
                    ),
                  )
                : ListView.separated(
                    itemCount: matchingStudents.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, index) {
                      final st = matchingStudents[index];
                      final name = st['name'] ?? '';
                      final fName = st['father_name'] ?? '';
                      final roll = st['roll_number'] ?? '-';
                      final cls = st['class_name'] ?? '-';
                      final int c = st['count'] ?? 0;
                      final List<String> dList = (st['dates'] as List<dynamic>?)?.map((e) => e.toString()).toList() ?? [];

                      return ListTile(
                        leading: CircleAvatar(
                          backgroundColor: themeColor.withOpacity(0.12),
                          child: Text('${index + 1}', style: TextStyle(color: themeColor, fontWeight: FontWeight.bold)),
                        ),
                        title: Text(
                          "$name ${fName.isNotEmpty ? 'ولد $fName' : ''} (رول: $roll | کلاس: $cls)",
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                        subtitle: Text(
                          dList.isNotEmpty ? "ناغہ تاریخیں: ${dList.join(', ')}" : (isSuccess ? "مکمل حاضری و سبق سنایا گیا" : "کوئی تفصیل نہیں"),
                          style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                        ),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: themeColor.withOpacity(0.1),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: themeColor.withOpacity(0.5)),
                          ),
                          child: Text(
                            "$c ناغے",
                            style: TextStyle(color: themeColor, fontWeight: FontWeight.bold, fontSize: 12),
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      );
    }

    if (isDesktop) {
      showDialog(
        context: context,
        builder: (ctx) => Dialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720, maxHeight: 620),
            child: buildModalContent(ctx),
          ),
        ),
      );
    } else {
      showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (ctx) {
          return Container(
            height: MediaQuery.of(context).size.height * 0.8,
            decoration: const BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: buildModalContent(ctx),
          );
        },
      );
    }
  }

  Future<void> _printAggregateNaaghaList(String catTitle, List<Map<String, dynamic>> students, {bool isSuccess = false}) async {
    try {
      final pdf = pw.Document();
      pdf.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4,
          margin: const pw.EdgeInsets.all(24),
          build: (pw.Context ctx) => [
            pw.Header(
              level: 0,
              child: pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text(
                    catTitle,
                    style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold),
                  ),
                  pw.Text(
                    "مدت: $fromDate تا $toDate",
                    style: const pw.TextStyle(fontSize: 12),
                  ),
                ],
              ),
            ),
            pw.SizedBox(height: 10),
            pw.TableHelper.fromTextArray(
              headers: ['#', 'رول نمبر', 'طالب علم مع ولدیت', 'کلاس', 'ناغہ تعداد', 'ناغہ کی تاریخیں'],
              data: students.asMap().entries.map((e) {
                final idx = e.key + 1;
                final st = e.value;
                final name = st['name'] ?? '';
                final fName = st['father_name'] ?? '';
                final fullStd = fName.isNotEmpty ? "$name ولد $fName" : name;
                final List<String> dList = (st['dates'] as List<dynamic>?)?.map((x) => x.toString()).toList() ?? [];
                return [
                  idx.toString(),
                  st['roll_number']?.toString() ?? '-',
                  fullStd,
                  st['class_name']?.toString() ?? '-',
                  "${st['count']} ناغے",
                  dList.isNotEmpty ? dList.join(', ') : (isSuccess ? "بلا ناغہ (0)" : "-"),
                ];
              }).toList(),
              headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10),
              cellStyle: const pw.TextStyle(fontSize: 9),
              cellAlignment: pw.Alignment.center,
              headerDecoration: const pw.BoxDecoration(color: PdfColors.grey300),
            ),
          ],
        ),
      );

      final pdfBytes = await pdf.save();
      await Printing.layoutPdf(
        onLayout: (PdfPageFormat format) async => pdfBytes,
        name: '${catTitle.replaceAll(' ', '_')}.pdf',
      );
    } catch (e) {
      debugPrint("Print aggregate naagha error: $e");
    }
  }



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
              isUrduGlobal.value ? "جنرل اکیڈمک رپورٹس" : "Academic Reports"),
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
        body: NestedScrollView(
          headerSliverBuilder: (BuildContext context, bool innerBoxIsScrolled) {
            return [
              SliverToBoxAdapter(child: _buildFilterBar()),
              if (!isLoading && _errorMessage == null && selectedStudentId == null)
                SliverToBoxAdapter(child: _build30DayPaginationBar()),
              if (!isLoading && _errorMessage == null && selectedStudentId == null)
                SliverToBoxAdapter(child: _buildSummaryBanner()),
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
                      : _reportsGrouped.isEmpty
                          ? Center(
                              child: Text(
                                isUrduGlobal.value
                                    ? "کوئی ریکارڈ نہیں ملا"
                                    : "No records found",
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
                                      width: constraints.maxWidth > 960 ? constraints.maxWidth : 960,
                                      height: constraints.maxHeight,
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          // Sticky Table Header
                                          _buildUnifiedTableHeader(),
                                          // Scrollable Data Rows
                                          Expanded(
                                            child: SingleChildScrollView(
                                              physics: const BouncingScrollPhysics(),
                                              child: Column(
                                                crossAxisAlignment: CrossAxisAlignment.start,
                                                children: _buildUnifiedTableRows(),
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

  Widget _buildUnifiedTableHeader() {
    return Row(
      children: [
        _cell(isUrduGlobal.value ? "طالب علم" : "Student", 150, isHeader: true),
        _cell(isUrduGlobal.value ? "تاریخ" : "Date", 85, isHeader: true),
        _cell(isUrduGlobal.value ? "حاضری" : "Attendance", 70, isHeader: true),
        _cell(isUrduGlobal.value ? "کلاس" : "Class", 95, isHeader: true),
        _cell(isUrduGlobal.value ? "مضمون" : "Subject", 130, isHeader: true),
        _cell(isUrduGlobal.value ? "سبق کی تفصیل" : "Lesson Details", 230, isHeader: true),
        _cell(isUrduGlobal.value ? "کیفیت" : "Grade", 80, isHeader: true),
        _cell(isUrduGlobal.value ? "ریمارکس" : "Remarks", 120, isHeader: true),
      ],
    );
  }

  List<Widget> _buildUnifiedTableRows() {
    List<Widget> rows = [];
    _reportsGrouped.forEach((studentId, reports) {
      for (var r in reports) {
        rows.add(_buildUnifiedDataRow(r, studentId));
      }
    });
    if (_reportsGrouped.isNotEmpty && selectedStudentId == null) {
      rows.add(const SizedBox(height: 20));
      rows.add(_buildNaaghaSummaryWidget());
      rows.add(const SizedBox(height: 40));
    }
    return rows;
  }

  Widget _buildUnifiedDataRow(Map<String, dynamic> r, String sid) {
    String rDate = r['date']?.toString().trim() ?? '';
    String att = _attMap['${rDate}_$sid'] ?? '';
    if (att.isEmpty) {
      try {
        DateTime parsed = _parseRobust(rDate);
        String isoDate = "${parsed.year.toString().padLeft(4, '0')}-${parsed.month.toString().padLeft(2, '0')}-${parsed.day.toString().padLeft(2, '0')}";
        String dMyDate = "${parsed.day}/${parsed.month}/${parsed.year}";
        String ddMMyyyy = "${parsed.day.toString().padLeft(2, '0')}/${parsed.month.toString().padLeft(2, '0')}/${parsed.year}";
        att = _attMap['${isoDate}_$sid'] ?? _attMap['${dMyDate}_$sid'] ?? _attMap['${ddMMyyyy}_$sid'] ?? '';
      } catch (_) {}
    }

    String lessonDet = r['lesson_details']?.toString().trim() ?? '-';
    String gradeDet = r['grade']?.toString().trim() ?? '-';

    if (att.isEmpty) {
      if (gradeDet.contains('غیر حاضر') || lessonDet.contains('غیر حاضر') || gradeDet == 'Absent') {
        att = 'A';
      } else if (gradeDet.contains('رخصت') || lessonDet.contains('رخصت') || gradeDet == 'Leave') {
        att = 'L';
      } else if (r['is_dummy'] == 1 || (gradeDet == '-' && lessonDet == '-')) {
        att = '';
      } else {
        att = 'P';
      }
    }

    if (att == 'A') {
      if (lessonDet == '-' || lessonDet.isEmpty) {
        lessonDet = isUrduGlobal.value ? 'غیر حاضر' : 'Absent';
      }
      if (gradeDet == '-' || gradeDet.isEmpty) {
        gradeDet = isUrduGlobal.value ? 'غیر حاضر' : 'Absent';
      }
    } else if (att == 'L') {
      if (lessonDet == '-' || lessonDet.isEmpty) {
        lessonDet = isUrduGlobal.value ? 'رخصت' : 'Leave';
      }
      if (gradeDet == '-' || gradeDet.isEmpty) {
        gradeDet = isUrduGlobal.value ? 'رخصت' : 'Leave';
      }
    }

    String attStr;
    Color attColor;
    Color gradeColor;

    DateTime rowDt = _parseRobust(rDate);
    if (rowDt.weekday == DateTime.friday) {
      attStr = 'جمعہ';
      attColor = Colors.blue;
      gradeColor = Colors.blue;
    } else if (att == 'P') {
      attStr = isUrduGlobal.value ? 'حاضر' : 'P';
      attColor = Colors.green;
      gradeColor = getStatusColor(gradeDet);
    } else if (att == 'A') {
      attStr = isUrduGlobal.value ? 'غیر حاضر' : 'A';
      attColor = Colors.red;
      gradeColor = Colors.red;
    } else if (att == 'L') {
      attStr = isUrduGlobal.value ? 'رخصت' : 'L';
      attColor = Colors.orange;
      gradeColor = Colors.orange;
    } else {
      attStr = '-';
      attColor = Colors.grey;
      gradeColor = getStatusColor(gradeDet);
    }

    final stdName = _getStudentName(sid);
    final rollNo = _getStudentRollNo(sid);
    final studentDisplay = (rollNo != '-') ? "$rollNo - $stdName" : stdName;

    return Row(
      children: [
        _cell(studentDisplay, 150),
        _cell(rDate, 85),
        _cell(attStr, 70, textColor: attColor, bold: true),
        _cell(r['class_name']?.toString() ?? r['class']?.toString() ?? '-', 95),
        _cell(r['subject']?.toString() ?? '-', 130),
        _cell(lessonDet, 230),
        _cell(gradeDet, 80, textColor: gradeColor, bold: true),
        _cell(r['remarks']?.toString() ?? '-', 120),
      ],
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
          content: Column(
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
              const SizedBox(height: 10),
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
            ],
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

    if (selectedOption != null) {
      await _generateAndPreviewAcademicPdf(printScope: selectedOption);
    }
  }

  Future<void> _generateAndPreviewAcademicPdf({String printScope = 'current_phase'}) async {
    try {
      if (!mounted) return;
      setState(() => isLoading = true);

      List<Map<String, dynamic>> reportsToPrint = [];
      if (printScope == 'all_records' && _cachedRawReports.isNotEmpty) {
        reportsToPrint = _cachedRawReports.where((r) => _matchesSelectedClass(r)).toList();
      } else {
        _reportsGrouped.forEach((_, list) => reportsToPrint.addAll(list));
      }

      if (reportsToPrint.isEmpty) {
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

      final chunks = <List<Map<String, dynamic>>>[];
      for (var i = 0; i < reportsToPrint.length; i += 18) {
        chunks.add(reportsToPrint.sublist(i, (i + 18 > reportsToPrint.length) ? reportsToPrint.length : i + 18));
      }

      final pdf = pw.Document();
      int pageIndex = 1;

      for (var chunk in chunks) {
        final pageWidget = _buildAcademicPdfPageWidget(
          chunk: chunk,
          pageNum: pageIndex,
          totalPages: chunks.length,
        );
        pageIndex++;

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
                title: Text(isUrduGlobal.value ? "اکیڈمک رپورٹ پریویو و پرنٹنگ" : "Academic Report Preview & Print"),
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
                pdfFileName: 'Academic_Report.pdf',
              ),
            ),
          ),
        ),
      );
    } catch (e) {
      debugPrint("Academic Print Error: $e");
      if (mounted) {
        setState(() => isLoading = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text("Print failed: $e")));
      }
    }
  }

  Widget _buildAcademicPdfPageWidget({
    required List<Map<String, dynamic>> chunk,
    required int pageNum,
    required int totalPages,
  }) {
    return Container(
      width: 1122,
      height: 793,
      color: Colors.white,
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header
          Container(
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 16),
            decoration: BoxDecoration(
              color: primaryGreen,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  isUrduGlobal.value ? "جامعہ اشرف المدارس — جنرل اکیڈمک رپورٹ" : "Jamia Ashraf-ul-Madaris — Academic Report",
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                ),
                Text(
                  "${isUrduGlobal.value ? 'صفحہ' : 'Page'} $pageNum / $totalPages",
                  style: const TextStyle(color: Colors.white, fontSize: 13),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          // Subheader details
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                "${isUrduGlobal.value ? 'کلاس:' : 'Class:'} ${_selectedClass ?? (isUrduGlobal.value ? 'تمام کلاسز' : 'All Classes')}",
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: primaryGreen),
              ),
              Text(
                "${isUrduGlobal.value ? 'مدت:' : 'Period:'} $fromDate تا $toDate",
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // Table Header
          Container(
            color: Colors.grey.shade300,
            padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
            child: Row(
              children: [
                _pdfCell(isUrduGlobal.value ? "#" : "#", 35, bold: true),
                _pdfCell(isUrduGlobal.value ? "طالب علم" : "Student", 180, bold: true),
                _pdfCell(isUrduGlobal.value ? "تاریخ" : "Date", 90, bold: true),
                _pdfCell(isUrduGlobal.value ? "حاضری" : "Att", 65, bold: true),
                _pdfCell(isUrduGlobal.value ? "کلاس" : "Class", 110, bold: true),
                _pdfCell(isUrduGlobal.value ? "مضمون" : "Subject", 150, bold: true),
                _pdfCell(isUrduGlobal.value ? "سبق کی تفصیل" : "Lesson Details", 250, bold: true),
                _pdfCell(isUrduGlobal.value ? "کیفیت" : "Grade", 80, bold: true),
                _pdfCell(isUrduGlobal.value ? "ریمارکس" : "Remarks", 120, bold: true),
              ],
            ),
          ),
          const Divider(height: 1, thickness: 1, color: Colors.black87),
          // Rows
          Expanded(
            child: ListView.builder(
              physics: const NeverScrollableScrollPhysics(),
              itemCount: chunk.length,
              itemBuilder: (context, index) {
                final r = chunk[index];
                final sid = r['student_id']?.toString() ?? '';
                final stdName = _getStudentName(sid);
                final roll = _getStudentRollNo(sid);
                final dt = r['date']?.toString() ?? '-';
                final cls = r['class_name']?.toString() ?? r['class']?.toString() ?? '-';
                final sub = r['subject']?.toString() ?? '-';
                final lesson = r['lesson_details']?.toString() ?? '-';
                final grade = r['grade']?.toString() ?? '-';
                final remarks = r['remarks']?.toString() ?? '-';

                return Container(
                  decoration: BoxDecoration(
                    color: index.isEven ? Colors.grey.shade50 : Colors.white,
                    border: Border(bottom: BorderSide(color: Colors.grey.shade300, width: 0.5)),
                  ),
                  padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 4),
                  child: Row(
                    children: [
                      _pdfCell("${index + 1}", 35),
                      _pdfCell(roll != '-' ? "$roll - $stdName" : stdName, 180),
                      _pdfCell(dt, 90),
                      _pdfCell(grade.contains('غیر حاضر') ? 'A' : (grade.contains('رخصت') ? 'L' : 'P'), 65),
                      _pdfCell(cls, 110),
                      _pdfCell(sub, 150),
                      _pdfCell(lesson, 250),
                      _pdfCell(grade, 80),
                      _pdfCell(remarks, 120),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _pdfCell(String text, double width, {bool bold = false}) {
    return SizedBox(
      width: width,
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(
          fontSize: 10,
          fontWeight: bold ? FontWeight.bold : FontWeight.normal,
        ),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}
