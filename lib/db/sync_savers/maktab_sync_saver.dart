import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

class MaktabSyncSaver {
  static Future<void> save(Database db, Batch batch, Map<String, dynamic> data, [List<List<int>>? maktabStudentIdRemaps]) async {
    // 1. Maktab Students
    if (data['maktab_students'] != null) {
      try {
        final mStdTableInfo = await db.rawQuery("PRAGMA table_info(maktab_students)");
        final validCols = mStdTableInfo.map((r) => r['name'] as String).toSet();

        for (var item in data['maktab_students']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          String roll =
              (item['roll_number'] ?? item['admission_number'])?.toString().trim() ??
                  '';

          var existing = <Map<String, dynamic>>[];
          if (serverId > 0) {
            existing = await db.query('maktab_students',
                where: 'id = ?', whereArgs: [serverId], limit: 1);
          }
          if (existing.isEmpty && roll.isNotEmpty) {
            existing = await db.query('maktab_students',
                where: 'roll_number = ? OR admission_number = ?',
                whereArgs: [roll, roll],
                limit: 1);
          }

          if (existing.isNotEmpty) {
            final existingId =
                int.tryParse(existing.first['id']?.toString() ?? '') ?? 0;
            if (serverId > 0 && existingId > 0 && existingId != serverId) {
              maktabStudentIdRemaps?.add([existingId, serverId]);
            }
          }

          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'name': item['name']?.toString() ?? '',
            'father_name': item['father_name']?.toString() ?? '',
            'roll_number': roll,
            'admission_number': roll,
            'class':
                (item['class'] ?? item['class_name'])?.toString() ?? '',
            'class_name':
                (item['class_name'] ?? item['class'])?.toString() ?? '',
            'contact_number':
                (item['contact_number'] ?? item['mobile_no'])?.toString() ?? '',
            'address': item['address']?.toString() ?? '',
            'password': '',
            'status': (item['status']?.toString().isNotEmpty == true) ? item['status'].toString() : 'Active',
            'admission_date': item['admission_date']?.toString() ?? '',
            'graduation_date': item['graduation_date']?.toString() ?? '',
            'duration': item['duration']?.toString() ?? '',
            'photo': item['photo']?.toString() ?? '',
            'leaving_reason': item['leaving_reason']?.toString() ?? '',
            'serial_no':
                int.tryParse(item['serial_no']?.toString() ?? '0') ?? 0,
            'hijri_year':
                int.tryParse(item['hijri_year']?.toString() ?? '1447') ?? 1447,
            'system_type': 'Masjid',
            'residence_status': item['residence_status']?.toString() ?? '',
            'alumni_category': item['alumni_category']?.toString() ?? 'completed',
            'wifaq_exam': int.tryParse(item['wifaq_exam']?.toString() ?? '0') ?? 0,
            'wifaq_marks': item['wifaq_marks']?.toString() ?? '',
            'mehrab_recitation': item['mehrab_recitation']?.toString() ?? '',
            'hifz_section':
                (item['hifz_section']?.toString().isNotEmpty == true) ? item['hifz_section'].toString() : 'regular',
            'is_deleted':
                int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };

          row.removeWhere((k, v) => !validCols.contains(k));

          batch.insert('maktab_students', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_students): $e");
      }
    }

    // 2. Maktab Teachers
    if (data['maktab_teachers'] != null) {
      try {
        for (var item in data['maktab_teachers']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'name': item['name']?.toString() ?? '',
            'father_name': item['father_name']?.toString() ?? '',
            'phone': item['phone']?.toString() ?? '',
            'cnic': item['cnic']?.toString() ?? '',
            'qualification': item['qualification']?.toString() ?? '',
            'salary': item['salary']?.toString() ?? '',
            'joining_date': item['joining_date']?.toString() ?? '',
            'status': item['status']?.toString() ?? '',
            'address': item['address']?.toString() ?? '',
            'photo': item['photo']?.toString() ?? '',
            'assigned_classes': item['assigned_classes']?.toString() ?? '',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_teachers', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
          batch.insert('teachers', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_teachers): $e");
      }
    }

    // 3. Maktab Classes
    if (data['maktab_classes'] != null) {
      try {
        for (var item in data['maktab_classes']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'name': item['name']?.toString() ?? '',
            'section': item['section']?.toString() ?? 'A',
            'class_type': item['class_type']?.toString() ?? 'Maktab',
            'status': item['status']?.toString() ?? 'Active',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_classes', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
          batch.insert('classes', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_classes): $e");
      }
    }

    // 4. Maktab Exams
    if (data['maktab_exams'] != null) {
      try {
        for (var item in data['maktab_exams']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'exam_name': item['exam_name']?.toString() ?? '',
            'exam_date': item['exam_date']?.toString() ?? '',
            'total_marks': double.tryParse(item['total_marks']?.toString() ?? '100') ?? 100.0,
            'passing_marks': double.tryParse(item['passing_marks']?.toString() ?? '33') ?? 33.0,
            'status': item['status']?.toString() ?? 'Announced',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_exams', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_exams): $e");
      }
    }

    // 5. Maktab Attendance
    if (data['maktab_attendance'] != null) {
      try {
        for (var item in data['maktab_attendance']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final studentId = int.tryParse(item['student_id']?.toString() ?? '0') ?? 0;
          final date = item['date']?.toString() ?? '';
          if (studentId > 0 && date.isNotEmpty) {
            batch.delete('maktab_attendance',
                where: 'student_id = ? AND date = ? AND (id IS NULL OR id != ?)',
                whereArgs: [studentId, date, serverId]);
          }
          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'student_id': studentId,
            'date': date,
            'status': item['status']?.toString() ?? '',
            'remarks': item['remarks']?.toString() ?? '',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_attendance', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_attendance): $e");
      }
    }

    // 6. Maktab Nazira Record
    if (data['maktab_nazira_record'] != null) {
      try {
        for (var item in data['maktab_nazira_record']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final studentId = int.tryParse(item['student_id']?.toString() ?? '') ?? 0;
          final date = item['date']?.toString() ?? '';
          if (studentId > 0 && date.isNotEmpty) {
            batch.delete('maktab_nazira_record',
                where: 'student_id = ? AND date = ? AND (id IS NULL OR id != ?)',
                whereArgs: [studentId, date, serverId]);
          }
          Map<String, dynamic> row = {
            if (serverId > 0) 'id': serverId,
            'student_id': studentId,
            'class_name': item['class_name']?.toString() ?? '',
            'teacher_id': int.tryParse(item['teacher_id']?.toString() ?? ''),
            'date': date,
            'morning_sabaq': item['morning_sabaq']?.toString() ?? '',
            'morning_qty': item['morning_qty']?.toString() ?? '',
            'morning_time': item['morning_time']?.toString() ?? '',
            'morning_status': item['morning_status']?.toString() ?? '',
            'evening_sabaq': item['evening_sabaq']?.toString() ?? '',
            'evening_qty': item['evening_qty']?.toString() ?? '',
            'evening_time': item['evening_time']?.toString() ?? '',
            'evening_status': item['evening_status']?.toString() ?? '',
            'sabaq_p': item['sabaq_p']?.toString() ?? '',
            'sabaq_r': item['sabaq_r']?.toString() ?? '',
            'sabaq_s': item['sabaq_s']?.toString() ?? '',
            'sabaq_time': item['sabaq_time']?.toString() ?? '',
            'sabqi_p': item['sabqi_p']?.toString() ?? '',
            'sabqi_r': item['sabqi_r']?.toString() ?? '',
            'sabqi_s': item['sabqi_s']?.toString() ?? '',
            'sabqi_listener': item['sabqi_listener']?.toString() ?? '',
            'sabqi_time': item['sabqi_time']?.toString() ?? '',
            'manzil_p': item['manzil_p']?.toString() ?? '',
            'manzil_r': item['manzil_r']?.toString() ?? '',
            'manzil_s': item['manzil_s']?.toString() ?? '',
            'manzil_listener': item['manzil_listener']?.toString() ?? '',
            'manzil_time': item['manzil_time']?.toString() ?? '',
            'status': item['status']?.toString() ?? 'Active',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_nazira_record', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_nazira_record): $e");
      }
    }

    // 7. Maktab Hifz Record
    if (data['maktab_hifz_record'] != null) {
      try {
        for (var item in data['maktab_hifz_record']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final studentId = item['student_id']?.toString() ?? '';
          final date = item['date']?.toString() ?? '';
          if (serverId > 0) {
            batch.delete('maktab_hifz_record',
                where: 'server_id = ? AND id != ?',
                whereArgs: [serverId, serverId]);
          }
          if (studentId.isNotEmpty && date.isNotEmpty) {
            batch.delete('maktab_hifz_record',
                where: 'student_id = ? AND date = ? AND (id IS NULL OR id != ?)',
                whereArgs: [studentId, date, serverId]);
          }
          Map<String, dynamic> row = {
            if (serverId > 0) 'id': serverId,
            'server_id': serverId > 0 ? serverId : null,
            'student_id': studentId,
            'class_name': item['class_name']?.toString() ?? '',
            'teacher_id': int.tryParse(item['teacher_id']?.toString() ?? ''),
            'date': date,
            'sabaq_para': item['sabaq_para']?.toString() ?? '',
            'sabaq_lines': item['sabaq_lines']?.toString() ?? '',
            'sabaq_grade': item['sabaq_grade']?.toString() ?? '',
            'sabaq_time': item['sabaq_time']?.toString() ?? '',
            'sabqi_para': item['sabqi_para']?.toString() ?? '',
            'sabqi_qty': item['sabqi_qty']?.toString() ?? '',
            'sabqi_grade': item['sabqi_grade']?.toString() ?? '',
            'sabqi_listener': item['sabqi_listener']?.toString() ?? '',
            'sabqi_time': item['sabqi_time']?.toString() ?? '',
            'sabqi_review_details': item['sabqi_review_details']?.toString() ?? '',
            'manzil_total_qty': item['manzil_total_qty']?.toString() ?? '',
            'manzil_details': item['manzil_details']?.toString() ?? '',
            'manzil_grade': item['manzil_grade']?.toString() ?? '',
            'manzil_listener': item['manzil_listener']?.toString() ?? '',
            'manzil_time': item['manzil_time']?.toString() ?? '',
            'sabaq_p': item['sabaq_p']?.toString() ?? '',
            'sabaq_l': item['sabaq_l']?.toString() ?? '',
            'sabaq_s': item['sabaq_s']?.toString() ?? '',
            'sabqi_p': item['sabqi_p']?.toString() ?? '',
            'sabqi_amt': item['sabqi_amt']?.toString() ?? '',
            'sabqi_s': item['sabqi_s']?.toString() ?? '',
            'manzil_p': item['manzil_p']?.toString() ?? '',
            'manzil_s': item['manzil_s']?.toString() ?? '',
            'status': item['status']?.toString() ?? 'Active',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_hifz_record', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_hifz_record): $e");
      }
    }

    // 8. Maktab Qaida Records
    if (data['maktab_qaida_records'] != null) {
      try {
        for (var item in data['maktab_qaida_records']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final studentId = int.tryParse(item['student_id']?.toString() ?? '') ?? 0;
          final date = item['date']?.toString() ?? '';
          if (studentId > 0 && date.isNotEmpty) {
            batch.delete('maktab_qaida_records',
                where: 'student_id = ? AND date = ? AND (id IS NULL OR id != ?)',
                whereArgs: [studentId, date, serverId]);
          }
          Map<String, dynamic> row = {
            if (serverId > 0) 'id': serverId,
            'student_id': studentId,
            'class_name': item['class_name']?.toString() ?? '',
            'teacher_id': int.tryParse(item['teacher_id']?.toString() ?? ''),
            'date': date,
            'lesson_type': item['lesson_type']?.toString() ?? 'نورانی قاعدہ',
            'hearing_time': item['hearing_time']?.toString() ?? '',
            'sabaq_1': item['sabaq_1']?.toString() ?? '',
            'qty_1': item['qty_1']?.toString() ?? '',
            'grade_1': item['grade_1']?.toString() ?? '',
            'sabaq_2': item['sabaq_2']?.toString() ?? '',
            'qty_2': item['qty_2']?.toString() ?? '',
            'grade_2': item['grade_2']?.toString() ?? '',
            'sabaq_3': item['sabaq_3']?.toString() ?? '',
            'qty_3': item['qty_3']?.toString() ?? '',
            'grade_3': item['grade_3']?.toString() ?? '',
            'sabaq_4': item['sabaq_4']?.toString() ?? '',
            'qty_4': item['qty_4']?.toString() ?? '',
            'grade_4': item['grade_4']?.toString() ?? '',
            'sabaq_book': item['sabaq_book']?.toString() ?? '',
            'sabaq_page': item['sabaq_page']?.toString() ?? '',
            'sabaq_status': item['sabaq_status']?.toString() ?? '',
            'sabaq_time': item['sabaq_time']?.toString() ?? '',
            'status': item['status']?.toString() ?? 'Active',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_qaida_records', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_qaida_records): $e");
      }
    }

    // 9. Maktab Academic Reports
    if (data['maktab_academic_reports'] != null) {
      try {
        for (var item in data['maktab_academic_reports']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            if (serverId > 0) 'id': serverId,
            'student_id': int.tryParse(item['student_id']?.toString() ?? '') ?? 0,
            'class_name': item['class_name']?.toString() ?? '',
            'date': item['date']?.toString() ?? '',
            'month': item['month']?.toString() ?? '',
            'year': item['year']?.toString() ?? '',
            'type': item['type']?.toString() ?? '',
            'subject': item['subject']?.toString() ?? '',
            'lesson_details': item['lesson_details']?.toString() ?? '',
            'grade': item['grade']?.toString() ?? '',
            'attendance_days': int.tryParse(item['attendance_days']?.toString() ?? ''),
            'total_working_days': int.tryParse(item['total_working_days']?.toString() ?? ''),
            'sabaq_grade': item['sabaq_grade']?.toString() ?? '',
            'sabqi_grade': item['sabqi_grade']?.toString() ?? '',
            'manzil_grade': item['manzil_grade']?.toString() ?? '',
            'remarks': item['remarks']?.toString() ?? '',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_academic_reports', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_academic_reports): $e");
      }
    }

    // 10. Maktab Exam Results
    if (data['maktab_exam_results'] != null) {
      try {
        for (var item in data['maktab_exam_results']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            if (serverId > 0) 'id': serverId,
            'exam_id': int.tryParse(item['exam_id']?.toString() ?? '') ?? 0,
            'student_id': int.tryParse(item['student_id']?.toString() ?? '') ?? 0,
            'class_name': item['class_name']?.toString() ?? '',
            'obtained_marks': double.tryParse(item['obtained_marks']?.toString() ?? '0') ?? 0.0,
            'total_marks': double.tryParse(item['total_marks']?.toString() ?? '100') ?? 100.0,
            'percentage': double.tryParse(item['percentage']?.toString() ?? '0') ?? 0.0,
            'grade': item['grade']?.toString() ?? '',
            'status': item['status']?.toString() ?? 'Pass',
            'remarks': item['remarks']?.toString() ?? '',
            'system_type': 'Masjid',
            'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          batch.insert('maktab_exam_results', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (maktab_exam_results): $e");
      }
    }
  }
}
