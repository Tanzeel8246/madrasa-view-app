// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:intl/intl.dart';

class PerformanceCalculator {
  /// Calculates date boundaries for various report periods.
  static Map<String, DateTime> getPeriodBoundaries(String period, int weekStartDay) {
    DateTime now = DateTime.now();
    DateTime todayEnd = DateTime(now.year, now.month, now.day, 23, 59, 59);
    DateTime start;

    switch (period) {
      case 'all':
        // تمام ریکارڈز
        start = DateTime(2000, 1, 1);
        todayEnd = DateTime(now.year + 5, 12, 31, 23, 59, 59);
        break;
      case 'this_month':
        // رواں مہینہ
        start = DateTime(now.year, now.month, 1);
        break;
      case 'weekly':
        // آخری 7 دن
        start = now.subtract(const Duration(days: 6));
        break;
      case 'monthly':
        // آخری 30 دن
        start = now.subtract(const Duration(days: 29));
        break;
      case 'quarterly':
        // آخری 90 دن
        start = now.subtract(const Duration(days: 89));
        break;
      case 'semi_annually':
        // آخری 180 دن
        start = now.subtract(const Duration(days: 179));
        break;
      case 'annually':
        // پچھلے سال کی آج کی تاریخ سے آج تک (آخری 365 دن)
        start = now.subtract(const Duration(days: 364));
        break;
      case 'current_week':
      default:
        // رواں ہفتہ (ویک سٹارٹ ڈے سے آج تک)
        int currentDay = now.weekday % 7;
        int daysSinceStart = (currentDay - weekStartDay) % 7;
        if (daysSinceStart < 0) daysSinceStart += 7;
        start = now.subtract(Duration(days: daysSinceStart));
        break;
    }

    return {
      'start': DateTime(start.year, start.month, start.day),
      'end': todayEnd,
    };
  }

  /// Calculates the boundaries of the "current week" based on the [weekStartDay].
  /// 0 = Sunday, 6 = Saturday.
  static Map<String, DateTime> getWeekBoundaries(int weekStartDay) {
    return getPeriodBoundaries('current_week', weekStartDay);
  }

  static double getGradePoints(String? grade, double maxPoints) {
    if (grade == null) return 0;
    String g = grade.trim().toLowerCase();

    if (g == 'بہترین') return maxPoints;
    if (g == 'بہتر') return maxPoints * 0.8;
    if (g == 'رعایتی پاس') return maxPoints * 0.6;
    if (g == 'کچا') return maxPoints * 0.4;

    // ممتاز، بہترین، اعلیٰ، بہت اچھے (Excellent)
    if (g == 'excellent' ||
        g == 'ممتاز' ||
        g == 'بہترین' ||
        g == 'اعلیٰ' ||
        g == 'بہت اچھے') {
      return maxPoints;
    }
    // بہتر، اچھا، ٹھیک (Good)
    if (g == 'good' || g == 'بہتر' || g == 'اچھا' || g == 'ٹھیک') {
      return maxPoints * 0.8;
    }
    // معمولی، مناسب، درمیانہ (Average)
    if (g == 'average' || g == 'معمولی' || g == 'مناسب' || g == 'درمیانہ') {
      return maxPoints * 0.6;
    }
    // کمزور، توجہ طلب، بہت کمزور (Weak)
    if (g == 'weak' || g == 'کمزور' || g == 'توجہ طلب' || g == 'بہت کمزور') {
      return maxPoints * 0.4;
    }

    return 0;
  }

  static bool isStudentInactive(Map<String, dynamic>? s, [String? checkDate]) {
    if (s == null) return false;
    final isDel = int.tryParse(s['is_deleted']?.toString() ?? '0') ?? 0;
    if (isDel == 1) return true;

    String status = (s['status'] ?? '').toString().toLowerCase().trim();
    String cat = (s['alumni_category'] ?? '').toString().toLowerCase().trim();
    String reason = (s['leaving_reason'] ?? '').toString().toLowerCase().trim();

    const inactiveStatuses = [
      'alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive',
      'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'
    ];
    if (inactiveStatuses.contains(status)) return true;
    if (status.contains('خارج') || status.contains('فارغ') || status.contains('چھوڑ') || status.contains('ترک')) return true;

    const inactiveCats = ['completed', 'left', 'dropped', 'expelled'];
    if (inactiveCats.contains(cat)) return true;
    if (cat.contains('خارج') || cat.contains('چھوڑ') || cat.contains('ترک')) return true;

    if (reason.contains('خارج') || reason.contains('چھوڑ') || reason.contains('ترک') || reason.contains('اخراج')) return true;

    if (checkDate != null && checkDate.isNotEmpty) {
      String normCheck = normalizeDate(checkDate);
      String gDate = normalizeDate((s['graduation_date'] ?? '').toString());
      if (gDate.isNotEmpty && gDate != '1970-01-01' && gDate != '0000-00-00' && normCheck.compareTo(gDate) >= 0) {
        return true;
      }
    }
    return false;
  }

  static bool isStudentActive(Map<String, dynamic>? s, [String? checkDate]) {
    if (s == null) return false;
    final isDel = int.tryParse(s['is_deleted']?.toString() ?? '0') ?? 0;
    if (isDel != 0) return false;
    return !isStudentInactive(s, checkDate);
  }

  static DateTime? _parseDate(String? dateStr) {
    if (dateStr == null || dateStr.isEmpty) return null;
    try {
      DateTime dt = parseDateRobust(dateStr);
      if (dt.year > 1970) return dt;
    } catch (_) {}
    return null;
  }

  static Map<String, dynamic> calculateWeeklyPerformance({
    required List<Map<String, dynamic>> hifzRecords,
    List<Map<String, dynamic>>? qaidaRecords,
    required List<Map<String, dynamic>> attendanceRecords,
    required int weekStartDay,
  }) {
    var boundaries = getWeekBoundaries(weekStartDay);
    DateTime start = boundaries['start']!;
    DateTime end = boundaries['end']!;

    // Filter records for this week
    var weeklyHifz = hifzRecords.where((r) {
      DateTime? d = _parseDate(r['date']);
      return d != null &&
          d.isAfter(start.subtract(const Duration(seconds: 1))) &&
          d.isBefore(end.add(const Duration(seconds: 1)));
    }).toList();

    var weeklyAttendance = attendanceRecords.where((r) {
      DateTime? d = _parseDate(r['date']);
      return d != null &&
          d.isAfter(start.subtract(const Duration(seconds: 1))) &&
          d.isBefore(end.add(const Duration(seconds: 1)));
    }).toList();

    // 1. Attendance Calculation
    int present = 0;
    int lateCount = 0;
    int totalDaysMeasured = weeklyAttendance.length;
    for (var a in weeklyAttendance) {
      if (a['status'] == 'Present' || a['status'] == 'P') {
        present++;
        int delay = int.tryParse(a['delay_minutes']?.toString() ?? '0') ?? 0;
        if (delay > 0) lateCount++;
      }
    }
    double attendancePerc =
        totalDaysMeasured > 0 ? (present / totalDaysMeasured) * 100 : 0;

    var weeklyQaida = (qaidaRecords ?? []).where((r) {
      DateTime? d = _parseDate(r['date']);
      return d != null &&
          d.isAfter(start.subtract(const Duration(seconds: 1))) &&
          d.isBefore(end.add(const Duration(seconds: 1)));
    }).toList();

    // 2. Academic Calculation
    // Logic: Avg(Sabaq Points) + Avg(Sabqi Points) + Avg(Manzil Points)
    double totalSabaq = 0;
    double totalSabqi = 0;
    double totalManzil = 0;
    int hifzCount = weeklyHifz.length;
    int qaidaCount = weeklyQaida.length;

    double academicScore = 0;
    if (qaidaCount > 0) {
      double totalQaidaPoints = 0;
      for (var q in weeklyQaida) {
        totalQaidaPoints += getGradePoints(q['grade_1'], 25);
        totalQaidaPoints += getGradePoints(q['grade_2'], 25);
        totalQaidaPoints += getGradePoints(q['grade_3'], 25);
        totalQaidaPoints += getGradePoints(q['grade_4'], 25);
      }
      academicScore = totalQaidaPoints / qaidaCount;
      hifzCount = qaidaCount; // Use hifzCount as generic record count for UI
    } else if (hifzCount > 0) {
      for (var h in weeklyHifz) {
        totalSabaq += getGradePoints(h['sabaq_grade'], 30);
        totalSabqi += getGradePoints(h['sabqi_grade'], 30);
        totalManzil += getGradePoints(h['manzil_grade'], 40);
      }
      academicScore = (totalSabaq + totalSabqi + totalManzil) / hifzCount;
    }

    return {
      'attendancePerc': attendancePerc.round(),
      'academicScore': academicScore.round(),
      'presentDays': present,
      'totalDays': totalDaysMeasured,
      'lateCount': lateCount,
      'hifzCount': hifzCount,
      'startDate': DateFormat('dd MMM').format(start),
      'endDate': DateFormat('dd MMM').format(end),
    };
  }

  static DateTime parseDateRobust(String dateStr) {
    String s = dateStr.trim().split(' ')[0];
    if (s.isEmpty) return DateTime(1970);
    
    // Try YYYY-MM-DD or YYYY/MM/DD
    var m1 = RegExp(r'^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$').firstMatch(s);
    if (m1 != null) {
      try {
        return DateTime(int.parse(m1.group(1)!), int.parse(m1.group(2)!), int.parse(m1.group(3)!));
      } catch (_) {}
    }
    
    // Try DD-MM-YYYY or DD/MM/YYYY
    var m2 = RegExp(r'^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$').firstMatch(s);
    if (m2 != null) {
      try {
        return DateTime(int.parse(m2.group(3)!), int.parse(m2.group(2)!), int.parse(m2.group(1)!));
      } catch (_) {}
    }
    
    try {
      return DateTime.parse(s);
    } catch (_) {}
    
    return DateTime(1970);
  }

  static String normalizeDate(String dateStr) {
    DateTime dt = parseDateRobust(dateStr);
    if (dt.year == 1970) return dateStr.trim().split(' ')[0];
    return "${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}";
  }

  static List<Map<String, dynamic>> injectMissingAttendanceRecords(
      List<Map<String, dynamic>> records, 
      List<Map<String, dynamic>> allAttendance, 
      List<Map<String, dynamic>> students, 
      String type) {
    
    List<Map<String, dynamic>> result = List<Map<String, dynamic>>.from(records);
    
    // Fast lookup for students
    Map<String, Map<String, dynamic>> stdMap = {};
    for (var s in students) {
      String sid = s['id']?.toString() ?? '';
      if (sid.isNotEmpty) stdMap[sid] = s;
    }

    // Index existing records by student_id and normalized date
    Map<String, List<int>> recordIndicesByStudentDate = {};
    for (int i = 0; i < result.length; i++) {
      var r = result[i];
      String sid = r['student_id']?.toString() ?? '';
      String date = normalizeDate(r['date']?.toString() ?? '');
      if (sid.isNotEmpty && date.isNotEmpty) {
        recordIndicesByStudentDate.putIfAbsent("${sid}_$date", () => []).add(i);
      }
    }

    // Process attendance
    for (var att in allAttendance) {
      String sid = att['student_id']?.toString() ?? '';
      String dateStr = att['date']?.toString() ?? '';
      String normDate = normalizeDate(dateStr);
      if (sid.isEmpty || normDate.isEmpty || normDate == '1970-01-01') continue;
      
      // Only process if student belongs to the current class/context
      if (!stdMap.containsKey(sid)) continue;

      Map<String, dynamic> stdObj = stdMap[sid] ?? <String, dynamic>{};
      
      // Exclude students who are inactive on this date or outside enrollment window
      String sAdm = normalizeDate((stdObj['admission_date'] ?? '').toString());
      String sGrad = normalizeDate((stdObj['graduation_date'] ?? '').toString());

      if (sAdm.isNotEmpty && sAdm != '1970-01-01' && normDate.compareTo(sAdm) < 0) continue;
      if (sGrad.isNotEmpty && sGrad != '1970-01-01' && normDate.compareTo(sGrad) > 0) continue;
      if (isStudentInactive(stdObj, normDate)) continue;
      
      String rawStatus = (att['status'] ?? '').toString().toUpperCase();
      String stat1 = (att['status_1'] ?? '').toString().toUpperCase();
      String stat2 = (att['status_2'] ?? '').toString().toUpperCase();
      String stat3 = (att['status_3'] ?? '').toString().toUpperCase();
      String stat4 = (att['status_4'] ?? '').toString().toUpperCase();
      String stat5 = (att['status_5'] ?? '').toString().toUpperCase();
      String stat6 = (att['status_6'] ?? '').toString().toUpperCase();
      String stat7 = (att['status_7'] ?? '').toString().toUpperCase();
      String stat8 = (att['status_8'] ?? '').toString().toUpperCase();
      String combined = "$rawStatus $stat1 $stat2 $stat3 $stat4 $stat5 $stat6 $stat7 $stat8".trim();
      
      String resolveStatus(String s) {
        String raw = s.trim().isEmpty ? combined : s.trim();
        var tokens = raw.split(RegExp(r'\s+'));
        if (raw.contains('ABSENT') || raw.contains('غیر حاضر') || raw.contains('غير حاضر') || raw.contains('غیرحاضر') || raw.contains('غيرحاضر') || raw.contains('غائب') || tokens.contains('A') || tokens.contains('غ')) return 'غیر حاضر';
        if (raw.contains('LEAVE') || raw.contains('رخصت') || raw.contains('چھٹی') || tokens.contains('L') || tokens.contains('ر')) return 'رخصت';
        if (raw.contains('PRESENT') || raw.contains('حاضر') || tokens.contains('P') || tokens.contains('ح')) return 'حاضر';
        if (raw.isNotEmpty && raw != '-') return 'حاضر';
        return '';
      }

      String p1 = resolveStatus(stat1);
      String p2 = resolveStatus(stat2);
      String p3 = resolveStatus(stat3);
      String p4 = resolveStatus(stat4);
      String mainStat = resolveStatus(rawStatus);
      
      // If global day status is absent or leave, propagate
      if (mainStat.isEmpty) {
        if (p1.isNotEmpty) {
          mainStat = p1;
        } else if (p2.isNotEmpty) {
          mainStat = p2;
        } else if (p3.isNotEmpty) {
          mainStat = p3;
        } else if (p4.isNotEmpty) {
          mainStat = p4;
        } else if (resolveStatus(stat5).isNotEmpty) {
          mainStat = resolveStatus(stat5);
        } else if (resolveStatus(stat6).isNotEmpty) {
          mainStat = resolveStatus(stat6);
        } else if (resolveStatus(stat7).isNotEmpty) {
          mainStat = resolveStatus(stat7);
        } else if (resolveStatus(stat8).isNotEmpty) {
          mainStat = resolveStatus(stat8);
        }
      }

      if (p1.isEmpty) p1 = mainStat;
      if (p2.isEmpty) p2 = mainStat;
      if (p3.isEmpty) p3 = mainStat;
      if (p4.isEmpty) p4 = mainStat;

      String stdClass = stdObj['class_name']?.toString().isNotEmpty == true
          ? stdObj['class_name'].toString()
          : (stdObj['class']?.toString() ?? '');

      String key = "${sid}_$normDate";
      var existingIndices = recordIndicesByStudentDate[key];

      if (existingIndices == null || existingIndices.isEmpty) {
        // No record exists for this student and date
        int newIdx = result.length;
        if (mainStat == 'غیر حاضر') {
          if (type == 'hifz') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_status': 'غیر حاضر',
              'sabqi_status': 'غیر حاضر',
              'manzil_status': 'غیر حاضر',
              'sabaq_grade': 'غیر حاضر',
              'sabqi_grade': 'غیر حاضر',
              'manzil_grade': 'غیر حاضر',
              'sabaq_parah': 'غیر حاضر',
              'sabqi_parah': 'غیر حاضر',
              'manzil_parah': 'غیر حاضر',
              'sabaq_para': 'غیر حاضر',
              'sabaq_lines': '-',
              'sabqi_para': 'غیر حاضر',
              'sabqi_qty': '-',
              'manzil_details': 'غیر حاضر',
              'manzil_total_qty': '-',
              'att_status': 'غیر حاضر',
              'is_dummy': 1
            });
          } else if (type == 'nazira') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'morning_sabaq': 'غیر حاضر', 'morning_qty': '-', 'morning_status': 'غیر حاضر',
              'evening_sabaq': 'غیر حاضر', 'evening_qty': '-', 'evening_status': 'غیر حاضر',
              'sabaq_1': '', 'qty_1': '', 'grade_1': 'غیر حاضر',
              'sabaq_2': '', 'qty_2': '', 'grade_2': 'غیر حاضر',
              'lesson_type': 'ناظرہ',
              'att_status': 'غیر حاضر',
              'is_dummy': 1
            });
          } else if (type == 'academic') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'subject': '-',
              'grade': 'غیر حاضر',
              'lesson_details': 'غیر حاضر',
              'att_status': 'غیر حاضر',
              'is_dummy': 1
            });
          } else if (type == 'qaida') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_1': '', 'qty_1': '', 'grade_1': 'غیر حاضر',
              'sabaq_2': '', 'qty_2': '', 'grade_2': 'غیر حاضر',
              'sabaq_3': '', 'qty_3': '', 'grade_3': 'غیر حاضر',
              'sabaq_4': '', 'qty_4': '', 'grade_4': 'غیر حاضر',
              'lesson_type': 'نورانی قاعدہ',
              'att_status': 'غیر حاضر',
              'is_dummy': 1
            });
          }
        } else if (mainStat == 'رخصت') {
          if (type == 'hifz') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_status': 'رخصت',
              'sabqi_status': 'رخصت',
              'manzil_status': 'رخصت',
              'sabaq_grade': 'رخصت',
              'sabqi_grade': 'رخصت',
              'manzil_grade': 'رخصت',
              'sabaq_parah': 'رخصت',
              'sabqi_parah': 'رخصت',
              'manzil_parah': 'رخصت',
              'sabaq_para': 'رخصت',
              'sabaq_lines': '-',
              'sabqi_para': 'رخصت',
              'sabqi_qty': '-',
              'manzil_details': 'رخصت',
              'manzil_total_qty': '-',
              'att_status': 'رخصت',
              'is_dummy': 1
            });
          } else if (type == 'nazira') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'morning_sabaq': 'رخصت', 'morning_qty': '-', 'morning_status': 'رخصت',
              'evening_sabaq': 'رخصت', 'evening_qty': '-', 'evening_status': 'رخصت',
              'sabaq_1': '', 'qty_1': '', 'grade_1': 'رخصت',
              'sabaq_2': '', 'qty_2': '', 'grade_2': 'رخصت',
              'lesson_type': 'ناظرہ',
              'att_status': 'رخصت',
              'is_dummy': 1
            });
          } else if (type == 'academic') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'subject': '-',
              'grade': 'رخصت',
              'lesson_details': 'رخصت',
              'att_status': 'رخصت',
              'is_dummy': 1
            });
          } else if (type == 'qaida') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_1': '', 'qty_1': '', 'grade_1': 'رخصت',
              'sabaq_2': '', 'qty_2': '', 'grade_2': 'رخصت',
              'sabaq_3': '', 'qty_3': '', 'grade_3': 'رخصت',
              'sabaq_4': '', 'qty_4': '', 'grade_4': 'رخصت',
              'lesson_type': 'نورانی قاعدہ',
              'att_status': 'رخصت',
              'is_dummy': 1
            });
          }
        } else if (mainStat == 'حاضر') {
          // Present in attendance but no academic entry -> Naagha
          if (type == 'hifz') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_status': 'ناغہ',
              'sabqi_status': 'ناغہ',
              'manzil_status': 'ناغہ',
              'sabaq_grade': '-',
              'sabqi_grade': '-',
              'manzil_grade': '-',
              'sabaq_parah': '-',
              'sabqi_parah': '-',
              'manzil_parah': '-',
              'sabaq_para': '-',
              'sabaq_lines': '-',
              'sabqi_para': '-',
              'sabqi_qty': '-',
              'manzil_details': '-',
              'manzil_total_qty': '-',
              'att_status': 'حاضر',
              'is_dummy': 1
            });
          } else if (type == 'nazira') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'morning_sabaq': '-', 'morning_qty': '-', 'morning_status': 'ناغہ',
              'evening_sabaq': '-', 'evening_qty': '-', 'evening_status': 'ناغہ',
              'grade_1': 'ناغہ', 'grade_2': 'ناغہ',
              'lesson_type': 'ناظرہ',
              'att_status': 'حاضر',
              'is_dummy': 1
            });
          } else if (type == 'academic') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'subject': '-',
              'grade': 'ناغہ',
              'lesson_details': 'ناغہ',
              'att_status': 'حاضر',
              'is_dummy': 1
            });
          } else if (type == 'qaida') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': normDate,
              'class_name': stdClass,
              'class': stdClass,
              'grade_1': 'ناغہ', 'grade_2': 'ناغہ', 'grade_3': 'ناغہ', 'grade_4': 'ناغہ',
              'lesson_type': 'نورانی قاعدہ',
              'att_status': 'حاضر',
              'is_dummy': 1
            });
          }
        }
        recordIndicesByStudentDate[key] = [newIdx];
      } else {
        // Record exists: ONLY update empty/unrecited portions if student was Absent or Leave
        for (int idx in existingIndices) {
          if (idx >= 0 && idx < result.length) {
            var mod = Map<String, dynamic>.from(result[idx]);
            
            bool isRecited(dynamic val) {
              String s = (val ?? '').toString().trim();
              return s.isNotEmpty && s != '-' && s != 'ناغہ' && s != 'غیر حاضر' && s != 'غير حاضر' && s != 'Absent' && s != 'رخصت' && s != 'Leave';
            }

            if (type == 'academic') {
              bool recited = isRecited(mod['lesson_details']) || isRecited(mod['grade']);
              if (!recited) {
                if (mainStat == 'غیر حاضر') {
                  mod['grade'] = 'غیر حاضر';
                  mod['lesson_details'] = 'غیر حاضر';
                  mod['att_status'] = 'غیر حاضر';
                } else if (mainStat == 'رخصت') {
                  mod['grade'] = 'رخصت';
                  mod['lesson_details'] = 'رخصت';
                  mod['att_status'] = 'رخصت';
                }
              } else {
                mod['att_status'] = 'حاضر';
              }
            } else if (type == 'hifz') {
              // Sabaq
              bool sabaqRecited = isRecited(mod['sabaq_para']) || isRecited(mod['sabaq_parah']) || isRecited(mod['sabaq_lines']) || isRecited(mod['sabaq_grade']);
              if (!sabaqRecited) {
                if (p1 == 'غیر حاضر' || mainStat == 'غیر حاضر') {
                  mod['sabaq_status'] = 'غیر حاضر';
                  mod['sabaq_grade'] = 'غیر حاضر';
                  mod['sabaq_parah'] = 'غیر حاضر';
                  mod['sabaq_para'] = 'غیر حاضر';
                } else if (p1 == 'رخصت' || mainStat == 'رخصت') {
                  mod['sabaq_status'] = 'رخصت';
                  mod['sabaq_grade'] = 'رخصت';
                  mod['sabaq_parah'] = 'رخصت';
                  mod['sabaq_para'] = 'رخصت';
                }
              }
              // Sabqi
              bool sabqiRecited = isRecited(mod['sabqi_para']) || isRecited(mod['sabqi_parah']) || isRecited(mod['sabqi_amount']) || isRecited(mod['sabqi_qty']) || isRecited(mod['sabqi_grade']);
              if (!sabqiRecited) {
                if (p2 == 'غیر حاضر' || mainStat == 'غیر حاضر') {
                  mod['sabqi_status'] = 'غیر حاضر';
                  mod['sabqi_grade'] = 'غیر حاضر';
                  mod['sabqi_parah'] = 'غیر حاضر';
                  mod['sabqi_para'] = 'غیر حاضر';
                } else if (p2 == 'رخصت' || mainStat == 'رخصت') {
                  mod['sabqi_status'] = 'رخصت';
                  mod['sabqi_grade'] = 'رخصت';
                  mod['sabqi_parah'] = 'رخصت';
                  mod['sabqi_para'] = 'رخصت';
                }
              }
              // Manzil
              bool manzilRecited = isRecited(mod['manzil_parah']) || isRecited(mod['manzil_details']) || isRecited(mod['manzil_grade']) || isRecited(mod['manzil_total_qty']);
              if (!manzilRecited) {
                if (p3 == 'غیر حاضر' || mainStat == 'غیر حاضر') {
                  mod['manzil_status'] = 'غیر حاضر';
                  mod['manzil_grade'] = 'غیر حاضر';
                  mod['manzil_details'] = 'غیر حاضر';
                  mod['manzil_parah'] = 'غیر حاضر';
                } else if (p3 == 'رخصت' || mainStat == 'رخصت') {
                  mod['manzil_status'] = 'رخصت';
                  mod['manzil_grade'] = 'رخصت';
                  mod['manzil_details'] = 'رخصت';
                  mod['manzil_parah'] = 'رخصت';
                }
              }
              mod['att_status'] = (sabaqRecited || sabqiRecited || manzilRecited) ? 'حاضر' : mainStat;
            } else if (type == 'nazira') {
              bool mRecited = isRecited(mod['morning_sabaq']) || isRecited(mod['morning_qty']) || isRecited(mod['morning_status']);
              if (!mRecited) {
                if (p1 == 'غیر حاضر' || mainStat == 'غیر حاضر') {
                  mod['morning_status'] = 'غیر حاضر';
                  mod['morning_sabaq'] = 'غیر حاضر';
                } else if (p1 == 'رخصت' || mainStat == 'رخصت') {
                  mod['morning_status'] = 'رخصت';
                  mod['morning_sabaq'] = 'رخصت';
                }
              }
              bool eRecited = isRecited(mod['evening_sabaq']) || isRecited(mod['evening_qty']) || isRecited(mod['evening_status']);
              if (!eRecited) {
                if (p2 == 'غیر حاضر' || mainStat == 'غیر حاضر') {
                  mod['evening_status'] = 'غیر حاضر';
                  mod['evening_sabaq'] = 'غیر حاضر';
                } else if (p2 == 'رخصت' || mainStat == 'رخصت') {
                  mod['evening_status'] = 'رخصت';
                  mod['evening_sabaq'] = 'رخصت';
                }
              }
              mod['att_status'] = (mRecited || eRecited) ? 'حاضر' : mainStat;
            } else if (type == 'qaida') {
              for (int k = 1; k <= 4; k++) {
                bool kRecited = isRecited(mod['sabaq_$k']) || isRecited(mod['qty_$k']) || isRecited(mod['grade_$k']);
                if (!kRecited) {
                  if (mainStat == 'غیر حاضر') {
                    mod['grade_$k'] = 'غیر حاضر';
                  } else if (mainStat == 'رخصت') {
                    mod['grade_$k'] = 'رخصت';
                  }
                }
              }
            }
            result[idx] = mod;
          }
        }
      }
    }

    // Ensure all active students are present for all active recorded dates
    Set<String> distinctDates = {};
    for (var r in result) {
      String d = normalizeDate(r['date']?.toString() ?? '');
      if (d.isNotEmpty && d != '1970-01-01') distinctDates.add(d);
    }
    for (var d in distinctDates) {
      DateTime dt = parseDateRobust(d);
      bool isFriday = dt.weekday == DateTime.friday;
      for (var s in students) {
        String sid = s['id']?.toString() ?? '';
        if (sid.isEmpty) continue;
        String sAdm = normalizeDate((s['admission_date'] ?? '').toString());
        String sGrad = normalizeDate((s['graduation_date'] ?? '').toString());
        if (sAdm.isNotEmpty && sAdm != '1970-01-01' && d.compareTo(sAdm) < 0) continue;
        if (sGrad.isNotEmpty && sGrad != '1970-01-01' && d.compareTo(sGrad) > 0) continue;
        if (isStudentInactive(s, d)) continue;

        String key = "${sid}_$d";
        if (recordIndicesByStudentDate[key] == null || recordIndicesByStudentDate[key]!.isEmpty) {
          String stdClass = s['class_name']?.toString().isNotEmpty == true ? s['class_name'].toString() : (s['class']?.toString() ?? '');
          String missingStat = isFriday ? 'جمعہ' : '-';
          String missingAtt = isFriday ? 'جمعہ' : '-';
          int newIdx = result.length;
          if (type == 'academic') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': d,
              'class_name': stdClass,
              'class': stdClass,
              'subject': '-',
              'grade': missingStat,
              'lesson_details': missingStat,
              'att_status': missingAtt,
              'is_dummy': 1
            });
          } else if (type == 'hifz') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': d,
              'class_name': stdClass,
              'class': stdClass,
              'sabaq_status': missingStat,
              'sabqi_status': missingStat,
              'manzil_status': missingStat,
              'sabaq_grade': isFriday ? 'جمعہ' : '-',
              'sabqi_grade': isFriday ? 'جمعہ' : '-',
              'manzil_grade': isFriday ? 'جمعہ' : '-',
              'sabaq_parah': isFriday ? 'جمعہ' : '-',
              'sabaq_para': isFriday ? 'جمعہ' : '-',
              'sabaq_lines': '-',
              'sabqi_parah': isFriday ? 'جمعہ' : '-',
              'sabqi_para': isFriday ? 'جمعہ' : '-',
              'sabqi_qty': '-',
              'manzil_parah': isFriday ? 'جمعہ' : '-',
              'manzil_details': isFriday ? 'جمعہ' : '-',
              'manzil_total_qty': '-',
              'att_status': missingAtt,
              'is_dummy': 1
            });
          } else if (type == 'nazira') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': d,
              'class_name': stdClass,
              'class': stdClass,
              'morning_sabaq': isFriday ? 'جمعہ' : '-', 'morning_qty': '-', 'morning_status': missingStat,
              'evening_sabaq': isFriday ? 'جمعہ' : '-', 'evening_qty': '-', 'evening_status': missingStat,
              'grade_1': missingStat, 'grade_2': missingStat,
              'lesson_type': 'ناظرہ',
              'att_status': missingAtt,
              'is_dummy': 1
            });
          } else if (type == 'qaida') {
            result.add({
              'student_id': int.tryParse(sid) ?? sid,
              'date': d,
              'class_name': stdClass,
              'class': stdClass,
              'grade_1': missingStat, 'grade_2': missingStat, 'grade_3': missingStat, 'grade_4': missingStat,
              'lesson_type': 'نورانی قاعدہ',
              'att_status': missingAtt,
              'is_dummy': 1
            });
          }
          recordIndicesByStudentDate[key] = [newIdx];
        }
      }
    }
    
    return result;
  }
}
