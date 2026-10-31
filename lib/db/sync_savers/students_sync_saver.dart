import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

class StudentsSyncSaver {
  static Future<void> save(Database db, Batch batch, Map<String, dynamic> data, [List<List<int>>? studentIdRemaps]) async {
    // 1. Students (Madrasa)
    if (data['students'] != null) {
      try {
        final stdTableInfo = await db.rawQuery("PRAGMA table_info(students)");
        final validCols = stdTableInfo.map((r) => r['name'] as String).toSet();

        for (var item in data['students']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          String roll =
              (item['roll_number'] ?? item['admission_number'])?.toString().trim() ??
                  '';

          var existing = <Map<String, dynamic>>[];
          if (serverId > 0) {
            existing = await db.query('students',
                where: 'id = ?', whereArgs: [serverId], limit: 1);
          }
          if (existing.isEmpty && roll.isNotEmpty) {
            existing = await db.query('students',
                where: 'roll_number = ? OR admission_number = ?',
                whereArgs: [roll, roll],
                limit: 1);
          }

          if (existing.isNotEmpty) {
            final existingId =
                int.tryParse(existing.first['id']?.toString() ?? '') ?? 0;
            if (serverId > 0 && existingId > 0 && existingId != serverId) {
              studentIdRemaps?.add([existingId, serverId]);
            }
          }

          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'name': item['name']?.toString() ?? '',
            'father_name': item['father_name']?.toString() ?? '',
            'roll_number': roll,
            'admission_number': roll,
            'class_name':
                (item['class_name'] ?? item['class'])?.toString() ?? '',
            'class':
                (item['class'] ?? item['class_name'])?.toString() ?? '',
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
            'system_type':
                (item['system_type']?.toString().isNotEmpty == true) ? item['system_type'].toString() : 'Madrasa',
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

          batch.insert('students', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (students): $e");
      }
    }

    // 2. Hifz History
    if (data['hifz_history'] != null) {
      try {
        for (var item in data['hifz_history']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final dateStr = (item['date']?.toString() ?? '').split(' ')[0];
          final stdIdStr = item['student_id']?.toString() ?? '';
          if (serverId > 0) {
            batch.delete('hifz_record',
                where: 'server_id = ? AND id != ?',
                whereArgs: [serverId, serverId]);
          }
          if (dateStr.isNotEmpty && stdIdStr.isNotEmpty) {
            batch.delete('hifz_record',
                where: 'date = ? AND student_id = ? AND (id IS NULL OR id != ?)',
                whereArgs: [dateStr, stdIdStr, serverId]);
          }
          batch.insert(
            'hifz_record',
            {
              'id': serverId > 0 ? serverId : null,
              'server_id': serverId > 0 ? serverId : null,
              'date': dateStr,
              'student_id': stdIdStr,
              'sabaq_para':
                  (item['sabaq_parah'] ?? item['sabaq_para'])?.toString() ?? '',
              'sabaq_lines': item['sabaq_lines']?.toString() ?? '',
              'sabaq_grade':
                  (item['sabaq_status'] ?? item['sabaq_grade'])?.toString() ?? '',
              'sabqi_para':
                  (item['sabqi_parah'] ?? item['sabqi_para'])?.toString() ?? '',
              'sabqi_qty':
                  (item['sabqi_amount'] ?? item['sabqi_qty'])?.toString() ?? '',
              'sabqi_grade':
                  (item['sabqi_status'] ?? item['sabqi_grade'])?.toString() ?? '',
              'sabqi_listener': item['sabqi_listener']?.toString() ?? '',
              'manzil_total_qty':
                  (item['manzil_amount'] ?? item['manzil_total_qty'])
                          ?.toString() ??
                      '',
              'manzil_details':
                  (item['manzil_parah'] ?? item['manzil_details'])?.toString() ??
                      '',
              'manzil_grade':
                  (item['manzil_status'] ?? item['manzil_grade'])?.toString() ??
                      '',
              'manzil_listener': item['manzil_listener']?.toString() ?? '',
              'sabaq_time': item['sabaq_time']?.toString() ?? '',
              'sabqi_time': item['sabqi_time']?.toString() ?? '',
              'manzil_time': item['manzil_time']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (hifz): $e");
      }
    }

    // 3. Nazira History
    if (data['nazira_history'] != null) {
      try {
        for (var item in data['nazira_history']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final dateStr = (item['date']?.toString() ?? '').split(' ')[0];
          final stdIdStr = item['student_id']?.toString() ?? '';
          if (dateStr.isNotEmpty && stdIdStr.isNotEmpty) {
            batch.delete('nazira_record',
                where: 'date = ? AND student_id = ? AND (id IS NULL OR id != ?)',
                whereArgs: [dateStr, stdIdStr, serverId]);
          }
          batch.insert(
            'nazira_record',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': stdIdStr,
              'date': dateStr,
              'morning_sabaq': item['morning_sabaq'],
              'morning_qty': item['morning_qty'] ?? '',
              'morning_time': item['morning_time'] ?? '',
              'morning_status': item['morning_status'],
              'evening_sabaq': item['evening_sabaq'],
              'evening_qty': item['evening_qty'] ?? '',
              'evening_time': item['evening_time'] ?? '',
              'evening_status': item['evening_status'],
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (nazira): $e");
      }
    }

    // 4. Exams
    if (data['exams'] != null) {
      try {
        for (var item in data['exams']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'exams',
            {
              'id': serverId > 0 ? serverId : null,
              'exam_name': item['exam_name']?.toString() ?? '',
              'exam_date': item['exam_date']?.toString() ?? '',
              'total_marks':
                  double.tryParse(item['total_marks']?.toString() ?? '100') ??
                      100,
              'passing_marks':
                  double.tryParse(item['passing_marks']?.toString() ?? '33') ??
                      33,
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (exams): $e");
      }
    }

    // 5. Complaints
    if (data['complaints'] != null) {
      try {
        for (var item in data['complaints']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'complaints',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id':
                  int.tryParse(item['student_id']?.toString() ?? '') ?? 0,
              'complaint': item['complaint']?.toString() ?? '',
              'date': item['date']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (complaints): $e");
      }
    }

    // 6. Parent Complaints
    if (data['parent_complaints'] != null) {
      try {
        for (var item in data['parent_complaints']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'parent_complaints',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': item['student_id'],
              'date': item['date'],
              'category': item['category'],
              'description': item['description'],
              'status': item['status'],
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (parent_complaints): $e");
      }
    }

    // 7. Leave Applications
    if (data['leave_applications'] != null) {
      try {
        for (var item in data['leave_applications']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'leave_applications',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': item['student_id'],
              'date_from': item['date_from'],
              'date_to': item['date_to'],
              'reason': item['reason'],
              'status': item['status'],
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (leave_applications): $e");
      }
    }

    // 8. Fee Submissions
    if (data['fee_submissions'] != null) {
      try {
        for (var item in data['fee_submissions']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'fee_submissions',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': item['student_id'],
              'amount': item['amount'],
              'submission_date': item['submission_date'],
              'screenshot': item['screenshot'] ?? '',
              'status': item['status'],
              'head_id': item['head_id'],
              'description': item['description'],
              'finance_id': item['finance_id'],
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (fee_submissions): $e");
      }
    }

    // 9. Exam Results
    if (data['exam_results'] != null) {
      try {
        for (var item in data['exam_results']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'exam_results',
            {
              'id': serverId > 0 ? serverId : null,
              'exam_id': item['exam_id'],
              'student_id': item['student_id'],
              'subject': item['subject']?.toString() ?? 'Quran',
              'marks_obtained': item['marks_obtained'],
              'total_marks': item['total_marks'],
              'grade': item['grade']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Pass',
              'remarks': item['remarks']?.toString() ?? '',
              'pukhtagi':
                  int.tryParse(item['pukhtagi']?.toString() ?? '0') ?? 0,
              'tajweed':
                  int.tryParse(item['tajweed']?.toString() ?? '0') ?? 0,
              'lahja': int.tryParse(item['lahja']?.toString() ?? '0') ?? 0,
              'masail': int.tryParse(item['masail']?.toString() ?? '0') ?? 0,
              'miqdar_khwandgi': item['miqdar_khwandgi']?.toString() ?? '',
              'p1': int.tryParse(item['p1']?.toString() ?? '0') ?? 0,
              'p2': int.tryParse(item['p2']?.toString() ?? '0') ?? 0,
              'p3': int.tryParse(item['p3']?.toString() ?? '0') ?? 0,
              'p4': int.tryParse(item['p4']?.toString() ?? '0') ?? 0,
              'p5': int.tryParse(item['p5']?.toString() ?? '0') ?? 0,
              'p6': int.tryParse(item['p6']?.toString() ?? '0') ?? 0,
              'subjects_marks': item['subjects_marks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (exam_results): $e");
      }
    }

    // 10. Academic Reports
    if (data['academic_reports'] != null) {
      try {
        for (var item in data['academic_reports']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'academic_reports',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': item['student_id'],
              'date': item['date'],
              'class_name': item['class_name'],
              'subject': item['subject'],
              'lesson_details': item['lesson_details'],
              'grade': item['grade'],
              'remarks': item['remarks'],
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (academic_reports): $e");
      }
    }

    // 11. Qaida Records
    if (data['qaida_records'] != null) {
      try {
        for (var item in data['qaida_records']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final dateStr = (item['date']?.toString() ?? '').split(' ')[0];
          final stdId = int.tryParse(item['student_id']?.toString() ?? '') ?? 0;
          if (dateStr.isNotEmpty && stdId > 0) {
            batch.delete('qaida_records',
                where: 'date = ? AND student_id = ? AND (id IS NULL OR id != ?)',
                whereArgs: [dateStr, stdId, serverId]);
          }
          batch.insert(
            'qaida_records',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': stdId,
              'date': dateStr,
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
              'lesson_type': item['lesson_type']?.toString() ?? 'نورانی قاعدہ',
              'hearing_time': item['hearing_time']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (qaida_records): $e");
      }
    }

    // 12. Husn-e-Saut Records
    if (data['husn_saut_record'] != null) {
      try {
        for (var item in data['husn_saut_record']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final dateStr = (item['date']?.toString() ?? '').split(' ')[0];
          final stdIdStr = item['student_id']?.toString() ?? '';
          if (serverId > 0) {
            batch.delete('husn_saut_record',
                where: 'server_id = ? AND id != ?',
                whereArgs: [serverId, serverId]);
          }
          if (dateStr.isNotEmpty && stdIdStr.isNotEmpty) {
            batch.delete('husn_saut_record',
                where: 'date = ? AND student_id = ? AND (id IS NULL OR id != ?)',
                whereArgs: [dateStr, stdIdStr, serverId]);
          }
          batch.insert(
            'husn_saut_record',
            {
              'id': serverId > 0 ? serverId : null,
              'server_id': serverId > 0 ? serverId : null,
              'student_id': stdIdStr,
              'date': dateStr,
              'manzil_sabaq': item['manzil_sabaq']?.toString() ?? '',
              'manzil_listener': item['manzil_listener']?.toString() ?? '',
              'manzil_grade': item['manzil_grade']?.toString() ?? '',
              'mashq_1_sabaq': item['mashq_1_sabaq']?.toString() ?? '',
              'mashq_1_time': item['mashq_1_time']?.toString() ?? '',
              'mashq_2_sabaq': item['mashq_2_sabaq']?.toString() ?? '',
              'mashq_2_time': item['mashq_2_time']?.toString() ?? '',
              'mashq_3_sabaq': item['mashq_3_sabaq']?.toString() ?? '',
              'mashq_3_time': item['mashq_3_time']?.toString() ?? '',
              'mashq_4_sabaq': item['mashq_4_sabaq']?.toString() ?? '',
              'mashq_4_time': item['mashq_4_time']?.toString() ?? '',
              'tilawat_qty': item['tilawat_qty']?.toString() ?? '',
              'tilawat_parahs': item['tilawat_parahs']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (husn_saut_record): $e");
      }
    }

    // 13. Course Structure
    if (data['course_structure'] != null) {
      try {
        for (var item in data['course_structure']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'course_structure',
            {
              'id': serverId > 0 ? serverId : null,
              'name': item['name']?.toString() ?? '',
              'course_type': item['course_type']?.toString() ?? 'Darse_Nizami',
              'total_years': int.tryParse(item['total_years']?.toString() ?? '8') ?? 8,
              'gender': item['gender']?.toString() ?? 'بنین',
              'description': item['description']?.toString() ?? '',
              'is_active': int.tryParse(item['is_active']?.toString() ?? '1') ?? 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (course_structure): $e");
      }
    }

    // 14. Course Subjects
    if (data['course_subjects'] != null) {
      try {
        for (var item in data['course_subjects']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'course_subjects',
            {
              'id': serverId > 0 ? serverId : null,
              'course_id': int.tryParse(item['course_id']?.toString() ?? '0') ?? 0,
              'year_no': int.tryParse(item['year_no']?.toString() ?? '1') ?? 1,
              'year_title': item['year_title']?.toString() ?? '',
              'subject_name': item['subject_name']?.toString() ?? '',
              'book_name': item['book_name']?.toString() ?? '',
              'max_marks': double.tryParse(item['max_marks']?.toString() ?? '100') ?? 100.0,
              'pass_marks': double.tryParse(item['pass_marks']?.toString() ?? '40') ?? 40.0,
              'subject_order': int.tryParse(item['subject_order']?.toString() ?? '0') ?? 0,
              'is_optional': int.tryParse(item['is_optional']?.toString() ?? '0') ?? 0,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (course_subjects): $e");
      }
    }

    // 15. Student Course Enrollment
    if (data['student_course_enrollment'] != null) {
      try {
        for (var item in data['student_course_enrollment']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'student_course_enrollment',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': int.tryParse(item['student_id']?.toString() ?? '0') ?? 0,
              'course_id': int.tryParse(item['course_id']?.toString() ?? '0') ?? 0,
              'current_year': int.tryParse(item['current_year']?.toString() ?? '1') ?? 1,
              'academic_session': item['academic_session']?.toString() ?? '',
              'enrollment_date': item['enrollment_date']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'active',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (student_course_enrollment): $e");
      }
    }

    // 16. Exam Subject Results
    if (data['exam_subject_results'] != null) {
      try {
        for (var item in data['exam_subject_results']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'exam_subject_results',
            {
              'id': serverId > 0 ? serverId : null,
              'exam_id': int.tryParse(item['exam_id']?.toString() ?? '0') ?? 0,
              'student_id': int.tryParse(item['student_id']?.toString() ?? '0') ?? 0,
              'course_id': int.tryParse(item['course_id']?.toString() ?? '0') ?? 0,
              'year_no': int.tryParse(item['year_no']?.toString() ?? '1') ?? 1,
              'subject_id': int.tryParse(item['subject_id']?.toString() ?? '0') ?? 0,
              'subject_name': item['subject_name']?.toString() ?? '',
              'marks_obtained': double.tryParse(item['marks_obtained']?.toString() ?? '0') ?? 0.0,
              'max_marks': double.tryParse(item['max_marks']?.toString() ?? '100') ?? 100.0,
              'is_absent': int.tryParse(item['is_absent']?.toString() ?? '0') ?? 0,
              'grade': item['grade']?.toString() ?? 'مقبول',
              'remarks': item['remarks']?.toString() ?? '',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (exam_subject_results): $e");
      }
    }

    // 17. Student Year Promotion
    if (data['student_year_promotion'] != null) {
      try {
        for (var item in data['student_year_promotion']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'student_year_promotion',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': int.tryParse(item['student_id']?.toString() ?? '0') ?? 0,
              'course_id': int.tryParse(item['course_id']?.toString() ?? '0') ?? 0,
              'from_year': int.tryParse(item['from_year']?.toString() ?? '1') ?? 1,
              'to_year': int.tryParse(item['to_year']?.toString() ?? '2') ?? 2,
              'academic_session': item['academic_session']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Promoted',
              'promoted_by': int.tryParse(item['promoted_by']?.toString() ?? ''),
              'remarks': item['remarks']?.toString() ?? '',
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (student_year_promotion): $e");
      }
    }
  }
}
