import 'package:flutter/foundation.dart';
import 'package:intl/intl.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../utils/performance_helper.dart';
import '../utils/globals.dart';

enum AlertCategory {
  academic, // تعلیمی و حفظ
  attendance, // حاضری
  finance, // مالیات
  admin, // انتظامیہ
}

enum AlertSeverity {
  critical, // 🔴 شدید / مسلسل ناغہ
  warning, // 🟡 انتباہ / روزانہ ناغہ
  info, // 🟢 اطلاع / تصدیق
}

class SmartAlert {
  final String id;
  final String title;
  final String description;
  final AlertCategory category;
  final AlertSeverity severity;
  final String date;
  final String? studentName;
  final int? studentId;
  final String? studentRoll;
  final String? className;
  final String? contactNumber;
  final String? actionType; // 'hifz', 'attendance', 'fee', 'leave', 'complaint'
  final dynamic actionPayload;
  bool isRead;

  SmartAlert({
    required this.id,
    required this.title,
    required this.description,
    required this.category,
    required this.severity,
    required this.date,
    this.studentName,
    this.studentId,
    this.studentRoll,
    this.className,
    this.contactNumber,
    this.actionType,
    this.actionPayload,
    this.isRead = false,
  });
}

class SmartAlertService {
  static final SmartAlertService _instance = SmartAlertService._internal();
  factory SmartAlertService() => _instance;
  SmartAlertService._internal();

  /// Fetch all smart alerts across all categories, sorted by severity
  Future<List<SmartAlert>> getAllAlerts() async {
    final List<SmartAlert> alerts = [];
    try {
      final academic = await getAcademicAlerts();
      final attendance = await getAttendanceAlerts();
      final finance = await getFinanceAlerts();
      final admin = await getAdminAlerts();

      alerts.addAll(academic);
      alerts.addAll(attendance);
      alerts.addAll(finance);
      alerts.addAll(admin);

      // Sort: Critical first, then Warning, then Info
      alerts.sort((a, b) {
        final orderA = a.severity == AlertSeverity.critical ? 0 : (a.severity == AlertSeverity.warning ? 1 : 2);
        final orderB = b.severity == AlertSeverity.critical ? 0 : (b.severity == AlertSeverity.warning ? 1 : 2);
        return orderA.compareTo(orderB);
      });

      // Apply read status
      final userId = currentUserId.value ?? 1;
      final readAlertIds = await DBHelper().getReadSmartAlerts(userId);
      for (var alert in alerts) {
        if (readAlertIds.contains(alert.id)) {
          alert.isRead = true;
        }
      }
    } catch (e) {
      debugPrint("Error fetching smart alerts: $e");
    }
    return alerts;
  }

  /// 1. Academic & Hifz Alerts (روزانہ سبق، سبقی، منزل ناغہ اور مسلسل ناغہ - پچھلے 7 دن)
  Future<List<SmartAlert>> getAcademicAlerts() async {
    final List<SmartAlert> alerts = [];
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final today = DateFormat('yyyy-MM-dd').format(DateTime.now());
      final sevenDaysAgo = DateTime.now().subtract(const Duration(days: 7));

      final stdTable = isMasjid ? 'maktab_students' : 'students';
      final hifzTable = isMasjid ? 'maktab_hifz_record' : 'hifz_record';
      final qaidaTable = isMasjid ? 'maktab_qaida_records' : 'qaida_records';

      // Fetch active students
      final students = await db.query(
        stdTable,
        columns: ['id', 'name', 'roll_number', 'admission_number', 'class', 'class_name', 'contact_number'],
        where: "(status = 'Active' OR status IS NULL OR status = '') AND (is_deleted = 0 OR is_deleted IS NULL)",
      );

      for (final s in students) {
        final stdId = int.tryParse(s['id']?.toString() ?? '') ?? 0;
        if (stdId <= 0) continue;

        final stdName = s['name']?.toString() ?? 'طالب علم';
        final roll = (s['roll_number'] ?? s['admission_number'])?.toString() ?? '';
        final cls = (s['class'] ?? s['class_name'])?.toString() ?? '';
        final phone = s['contact_number']?.toString() ?? '';

        // Query last 4 Hifz records for this student
        final records = await db.query(
          hifzTable,
          where: "student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          whereArgs: [stdId.toString()],
          orderBy: "date DESC",
          limit: 4,
        );

        if (records.isNotEmpty) {
          final latest = records.first;
          final latestDate = latest['date']?.toString() ?? '';
          final parsedDate = DateTime.tryParse(latestDate);
          final isRecent = parsedDate != null && !parsedDate.isBefore(sevenDaysAgo);

          // Check Recent Defaults (پچھلے 7 دن کا ناغہ)
          if (isRecent) {
            final sGrade = latest['sabaq_grade']?.toString().trim() ?? '';
            final sbGrade = latest['sabqi_grade']?.toString().trim() ?? '';
            final mGrade = latest['manzil_grade']?.toString().trim() ?? '';
            final sPara = latest['sabaq_para']?.toString().trim() ?? '';

            final isSabaqMissed = sGrade == 'نہیں سنایا' || sGrade == 'ناغہ' || sGrade == 'غیر حاضر' || sPara == 'نہیں سنایا';
            if (isSabaqMissed) {
              alerts.add(SmartAlert(
                id: 'daily_sabaq_${stdId}_$latestDate',
                title: latestDate == today ? 'آج کا سبق ناغہ' : 'سبق کا ناغہ ($latestDate)',
                description: '$stdName ($cls) نے ${latestDate == today ? "آج" : latestDate} کا نیا سبق نہیں سنایا۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.warning,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }

            final isSabqiMissed = sbGrade == 'نہیں سنائی' || sbGrade == 'ناغہ' || sbGrade == 'غیر حاضر';
            if (isSabqiMissed) {
              alerts.add(SmartAlert(
                id: 'daily_sabqi_${stdId}_$latestDate',
                title: latestDate == today ? 'آج کی سبقی ناغہ' : 'سبقی کا ناغہ ($latestDate)',
                description: '$stdName ($cls) نے ${latestDate == today ? "آج" : latestDate} سبقی (گزشتہ اسباق) نہیں سنائی۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.warning,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }

            final isManzilMissed = mGrade == 'نہیں سنائی' || mGrade == 'ناغہ' || mGrade == 'غیر حاضر';
            if (isManzilMissed) {
              alerts.add(SmartAlert(
                id: 'daily_manzil_${stdId}_$latestDate',
                title: latestDate == today ? 'آج کی منزل ناغہ' : 'منزل کا ناغہ ($latestDate)',
                description: '$stdName ($cls) نے ${latestDate == today ? "آج" : latestDate} منزل نہیں سنائی۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.warning,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }
          }

          // Check Consecutive Defaults (لگاتار 3 دن کا ناغہ - Critical 🚨)
          if (records.length >= 3) {
            final recentThree = records.take(3).toList();

            bool threeDaysSabaqMissed = recentThree.every((r) {
              final g = r['sabaq_grade']?.toString().trim() ?? '';
              final p = r['sabaq_para']?.toString().trim() ?? '';
              return g == 'نہیں سنایا' || g == 'ناغہ' || g == 'غیر حاضر' || p == 'نہیں سنایا' ||
                  (r['sabaq_lines']?.toString().trim().isEmpty == true && p.isEmpty);
            });

            if (threeDaysSabaqMissed) {
              alerts.add(SmartAlert(
                id: 'chronic_sabaq_$stdId',
                title: 'مسلسل 3 دن سبق کا ناغہ! 🚨',
                description: '$stdName ($cls) نے پچھلے 3 اندراجات میں مسلسل سبق نہیں سنایا۔ فوری استاد سے استفسار فرمائیں۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.critical,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }

            bool threeDaysSabqiMissed = recentThree.every((r) {
              final g = r['sabqi_grade']?.toString().trim() ?? '';
              return g == 'نہیں سنائی' || g == 'ناغہ' || g == 'غیر حاضر';
            });

            if (threeDaysSabqiMissed) {
              alerts.add(SmartAlert(
                id: 'chronic_sabqi_$stdId',
                title: 'مسلسل 3 دن سبقی کا ناغہ! ⚠️',
                description: '$stdName ($cls) نے پچھلے 3 اندراجات میں مسلسل سبقی نہیں سنائی۔ پچھلے اسباق کچے ہونے کا اندیشہ ہے۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.critical,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }

            bool threeDaysManzilMissed = recentThree.every((r) {
              final g = r['manzil_grade']?.toString().trim() ?? '';
              return g == 'نہیں سنائی' || g == 'ناغہ' || g == 'غیر حاضر';
            });

            if (threeDaysManzilMissed) {
              alerts.add(SmartAlert(
                id: 'chronic_manzil_$stdId',
                title: 'مسلسل 3 دن منزل کا ناغہ! ⚠️',
                description: '$stdName ($cls) نے پچھلے 3 اندراجات میں منزل نہیں سنائی۔ حفظ کچا ہونے کا خدشہ ہے۔',
                category: AlertCategory.academic,
                severity: AlertSeverity.critical,
                date: latestDate,
                studentName: stdName,
                studentId: stdId,
                studentRoll: roll,
                className: cls,
                contactNumber: phone,
                actionType: 'hifz',
              ));
            }
          }
        }

        // Also check Qaida records for Qaida students
        try {
          final qRecords = await db.query(
            qaidaTable,
            where: "student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
            whereArgs: [stdId.toString()],
            orderBy: "date DESC",
            limit: 2,
          );
          if (qRecords.isNotEmpty) {
            final qLatest = qRecords.first;
            final qDate = qLatest['date']?.toString() ?? '';
            final qParsed = DateTime.tryParse(qDate);
            if (qParsed != null && !qParsed.isBefore(sevenDaysAgo)) {
              final g1 = qLatest['grade_1']?.toString().trim() ?? '';
              if (g1 == 'ناغہ' || g1 == 'نہیں سنایا' || g1 == 'غیر حاضر') {
                alerts.add(SmartAlert(
                  id: 'daily_qaida_${stdId}_$qDate',
                  title: qDate == today ? 'آج قاعدہ سبق کا ناغہ' : 'قاعدہ سبق کا ناغہ ($qDate)',
                  description: '$stdName ($cls) نے تاریخ $qDate کو قاعدہ کا سبق نہیں سنایا۔',
                  category: AlertCategory.academic,
                  severity: AlertSeverity.warning,
                  date: qDate,
                  studentName: stdName,
                  studentId: stdId,
                  studentRoll: roll,
                  className: cls,
                  contactNumber: phone,
                  actionType: 'hifz',
                ));
              }
            }
          }
        } catch (_) {}
      }
    } catch (e) {
      debugPrint("Error in getAcademicAlerts: $e");
    }
    return alerts;
  }

  /// 2. Attendance & Staff Alerts (پچھلے 7 دن کی غیر حاضری اور مسلسل غیر حاضری)
  Future<List<SmartAlert>> getAttendanceAlerts() async {
    final List<SmartAlert> alerts = [];
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final today = DateFormat('yyyy-MM-dd').format(DateTime.now());
      final sevenDaysAgo = DateTime.now().subtract(const Duration(days: 7));

      final attTable = isMasjid ? 'maktab_attendance' : 'attendance';
      final stdTable = isMasjid ? 'maktab_students' : 'students';

      // Recent Student Absentees (Last 7 days)
      final recentRows = await db.query(
        attTable,
        where: "(status = 'Absent' OR status = 'A' OR status LIKE '%غیر حاضر%' OR status LIKE '%غائب%') AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "date DESC, id DESC",
        limit: 200,
      );

      final Set<String> seenStudentDate = {};
      final Map<String, Set<String>> studentAbsentDates = {};

      for (final row in recentRows) {
        final stdId = row['student_id']?.toString() ?? '';
        final rawDate = row['date']?.toString() ?? today;
        if (stdId.isEmpty) continue;

        final dt = PerformanceCalculator.parseDateRobust(rawDate);
        if (dt.year > 2000 && dt.isBefore(sevenDaysAgo)) {
          continue; // Outside 7-day window
        }

        final normDate = PerformanceCalculator.normalizeDate(rawDate);
        studentAbsentDates.putIfAbsent(stdId, () => {}).add(normDate);

        final uniqueKey = '${stdId}_$normDate';
        if (seenStudentDate.contains(uniqueKey)) continue;
        seenStudentDate.add(uniqueKey);

        final stdInfo = await db.query(
          stdTable,
          columns: ['id', 'name', 'roll_number', 'admission_number', 'class', 'class_name', 'contact_number'],
          where: "id = ?",
          whereArgs: [stdId],
          limit: 1,
        );

        if (stdInfo.isNotEmpty) {
          final s = stdInfo.first;
          final name = s['name']?.toString() ?? 'طالب علم';
          final cls = (s['class'] ?? s['class_name'])?.toString() ?? '';
          final phone = s['contact_number']?.toString() ?? '';

          alerts.add(SmartAlert(
            id: 'absent_${stdId}_$normDate',
            title: normDate == today ? 'آج کی غیر حاضری' : 'غیر حاضری ($normDate)',
            description: '$name ($cls) تاریخ $normDate کو مدرسہ سے غیر حاضر رہا۔',
            category: AlertCategory.attendance,
            severity: AlertSeverity.warning,
            date: normDate,
            studentName: name,
            studentId: int.tryParse(stdId),
            studentRoll: (s['roll_number'] ?? s['admission_number'])?.toString(),
            className: cls,
            contactNumber: phone,
            actionType: 'attendance',
          ));
        }
      }

      // Check Consecutive 3 Days Absenteeism (لگاتار 3 دن غیر حاضری - Critical 🚨)
      for (final entry in studentAbsentDates.entries) {
        if (entry.value.length >= 3) {
          final stdId = entry.key;
          final stdInfo = await db.query(
            stdTable,
            columns: ['id', 'name', 'class', 'class_name', 'contact_number'],
            where: "id = ?",
            whereArgs: [stdId],
            limit: 1,
          );
          if (stdInfo.isNotEmpty) {
            final s = stdInfo.first;
            final name = s['name']?.toString() ?? 'طالب علم';
            final cls = (s['class'] ?? s['class_name'])?.toString() ?? '';
            final phone = s['contact_number']?.toString() ?? '';

            alerts.add(SmartAlert(
              id: 'chronic_absent_$stdId',
              title: 'مسلسل 3 دن سے غیر حاضر! 🚨',
              description: '$name ($cls) گزشتہ ہفتے میں 3 یا زائد دن غیر حاضر رہا ہے۔ سرپرست سے فوری رابطہ فرمائیں۔',
              category: AlertCategory.attendance,
              severity: AlertSeverity.critical,
              date: today,
              studentName: name,
              studentId: int.tryParse(stdId),
              className: cls,
              contactNumber: phone,
              actionType: 'attendance',
            ));
          }
        }
      }

      // Teacher Attendance (پچھلے 7 دن)
      try {
        final teacherRows = await db.query(
          'attendance_teachers',
          where: "(status = 'Absent' OR status = 'A' OR status LIKE '%غیر حاضر%') AND (is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: "date DESC, id DESC",
          limit: 30,
        );

        for (final tr in teacherRows) {
          final tId = tr['teacher_id']?.toString() ?? '';
          final tDate = tr['date']?.toString() ?? today;
          final dt = PerformanceCalculator.parseDateRobust(tDate);
          if (dt.year > 2000 && dt.isBefore(sevenDaysAgo)) continue;

          final tInfo = await db.query('teachers', columns: ['name', 'phone'], where: 'id = ?', whereArgs: [tId], limit: 1);
          final tName = tInfo.isNotEmpty ? tInfo.first['name']?.toString() ?? 'استاد' : 'استاد';
          alerts.add(SmartAlert(
            id: 'teacher_absent_${tId}_$tDate',
            title: tDate == today ? 'استاد آج غیر حاضر' : 'استاد غیر حاضری ($tDate)',
            description: '$tName تاریخ $tDate کو جامعہ سے غیر حاضر رہے۔',
            category: AlertCategory.attendance,
            severity: AlertSeverity.warning,
            date: tDate,
            actionType: 'teacher_attendance',
          ));
        }
      } catch (_) {}
    } catch (e) {
      debugPrint("Error in getAttendanceAlerts: $e");
    }
    return alerts;
  }

  /// 3. Finance Alerts (فیس منظوری اور بقایا جات)
  Future<List<SmartAlert>> getFinanceAlerts() async {
    final List<SmartAlert> alerts = [];
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final stdTable = isMasjid ? 'maktab_students' : 'students';
      final today = DateFormat('yyyy-MM-dd').format(DateTime.now());

      // 1. Pending Fee Verifications
      try {
        final pendingFees = await db.query(
          'fee_submissions',
          where: "status = 'Pending'",
          orderBy: 'id DESC',
          limit: 15,
        );

        for (final f in pendingFees) {
          final stdId = f['student_id']?.toString() ?? '';
          final amount = f['amount']?.toString() ?? '0';
          final subDate = f['submission_date']?.toString() ?? '';

          final stdInfo = await db.query(stdTable, columns: ['name', 'class', 'class_name'], where: 'id = ?', whereArgs: [stdId], limit: 1);
          final name = stdInfo.isNotEmpty ? stdInfo.first['name']?.toString() ?? 'طالب علم' : 'طالب علم';
          final cls = stdInfo.isNotEmpty ? (stdInfo.first['class'] ?? stdInfo.first['class_name'] ?? '').toString() : '';

          alerts.add(SmartAlert(
            id: 'fee_pending_${f['id']}',
            title: 'آن لائن فیس تصدیق کی منتظر',
            description: '$name ($cls) کی فیس مبلغ Rs. $amount آن لائن جمع ہوئی ہے، برائے مہربانی تصدیق فرمائیں۔',
            category: AlertCategory.finance,
            severity: AlertSeverity.info,
            date: subDate,
            studentName: name,
            studentId: int.tryParse(stdId),
            className: cls,
            actionType: 'fee',
            actionPayload: f['id'],
          ));
        }
      } catch (e) {
        debugPrint("Error fetching pending fees: $e");
      }

      // 2. Fee Defaulters for current month
      final currentMonth = DateFormat('yyyy-MM').format(DateTime.now());
      try {
        final feeDefaulters = await db.rawQuery('''
          SELECT s.id, s.name, s.class, s.class_name, s.contact_number, s.monthly_fee
          FROM $stdTable s
          WHERE (s.status = 'Active' OR s.status IS NULL OR s.status = '')
            AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
            AND CAST(s.monthly_fee AS REAL) > 0
            AND s.id NOT IN (
              SELECT student_id FROM fee_submissions
              WHERE submission_date LIKE '$currentMonth%'
                AND (status = 'Approved' OR status = 'Paid' OR status = 'ادا شدہ' OR status IS NULL)
            )
          LIMIT 25
        ''');

        for (final fd in feeDefaulters) {
          final stdId = fd['id']?.toString() ?? '';
          final name = fd['name']?.toString() ?? 'طالب علم';
          final cls = (fd['class'] ?? fd['class_name'])?.toString() ?? '';
          final phone = fd['contact_number']?.toString() ?? '';
          final fee = fd['monthly_fee']?.toString() ?? '0';

          alerts.add(SmartAlert(
            id: 'defaulter_${stdId}_$currentMonth',
            title: 'ماہانہ فیس بقایا جات',
            description: '$name ($cls) کی ماہ $currentMonth کی فیس مبلغ Rs. $fee تاحال جمع نہیں ہوئی۔',
            category: AlertCategory.finance,
            severity: AlertSeverity.warning,
            date: today,
            studentName: name,
            studentId: int.tryParse(stdId),
            className: cls,
            contactNumber: phone,
            actionType: 'fee',
          ));
        }
      } catch (e) {
        debugPrint("Error fetching fee defaulters: $e");
      }
    } catch (e) {
      debugPrint("Error in getFinanceAlerts: $e");
    }
    return alerts;
  }

  /// 4. Admin Alerts (رخصت اور شکایات)
  Future<List<SmartAlert>> getAdminAlerts() async {
    final List<SmartAlert> alerts = [];
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final stdTable = isMasjid ? 'maktab_students' : 'students';

      // Pending Leaves
      try {
        final pendingLeaves = await db.query(
          'leave_applications',
          where: "status = 'Pending'",
          orderBy: 'id DESC',
          limit: 10,
        );

        for (final l in pendingLeaves) {
          final stdId = l['student_id']?.toString() ?? '';
          final reason = l['reason']?.toString() ?? 'ضروری کام';
          final fromDate = l['date_from']?.toString() ?? '';

          final stdInfo = await db.query(stdTable, columns: ['name', 'class', 'class_name'], where: 'id = ?', whereArgs: [stdId], limit: 1);
          final name = stdInfo.isNotEmpty ? stdInfo.first['name']?.toString() ?? 'طالب علم' : 'طالب علم';
          final cls = stdInfo.isNotEmpty ? (stdInfo.first['class'] ?? stdInfo.first['class_name'] ?? '').toString() : '';

          alerts.add(SmartAlert(
            id: 'leave_pending_${l['id']}',
            title: 'درخواست برائے رخصت زیر التواء',
            description: '$name ($cls) کی رخصت کی درخواست ($reason) منظوری کی منتظر ہے۔',
            category: AlertCategory.admin,
            severity: AlertSeverity.warning,
            date: fromDate,
            studentName: name,
            studentId: int.tryParse(stdId),
            className: cls,
            actionType: 'leave',
            actionPayload: l['id'],
          ));
        }
      } catch (e) {
        debugPrint("Error fetching pending leaves: $e");
      }

      // Pending Complaints
      try {
        final pendingComplaints = await db.query(
          'parent_complaints',
          where: "status = 'Pending'",
          orderBy: 'id DESC',
          limit: 10,
        );

        for (final c in pendingComplaints) {
          final stdId = c['student_id']?.toString() ?? '';
          final desc = c['description']?.toString() ?? '';
          final cDate = c['date']?.toString() ?? '';

          final stdInfo = await db.query(stdTable, columns: ['name', 'class', 'class_name'], where: 'id = ?', whereArgs: [stdId], limit: 1);
          final name = stdInfo.isNotEmpty ? stdInfo.first['name']?.toString() ?? 'طالب علم' : 'طالب علم';
          final cls = stdInfo.isNotEmpty ? (stdInfo.first['class'] ?? stdInfo.first['class_name'] ?? '').toString() : '';

          alerts.add(SmartAlert(
            id: 'complaint_pending_${c['id']}',
            title: 'والدین کی نئی شکایت! ⚠️',
            description: '$name ($cls) کے والد/سرپرست کی طرف سے شکایت: "$desc"',
            category: AlertCategory.admin,
            severity: AlertSeverity.critical,
            date: cDate,
            studentName: name,
            studentId: int.tryParse(stdId),
            className: cls,
            actionType: 'complaint',
            actionPayload: c['id'],
          ));
        }
      } catch (e) {
        debugPrint("Error fetching pending complaints: $e");
      }
    } catch (e) {
      debugPrint("Error in getAdminAlerts: $e");
    }
    return alerts;
  }
}
