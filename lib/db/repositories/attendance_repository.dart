import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';

class AttendanceRepository {
  final DBHelper _dbHelper;
  AttendanceRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  Future<List<Map<String, dynamic>>> getAttendanceByDate(
    String date, {
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "s.system_type = 'Masjid'"
        : "(s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')";

    return await db.rawQuery('''
      SELECT a.*, s.name, s.father_name, s.class_name, s.roll_number
      FROM attendance a
      JOIN students s ON a.student_id = s.id
      WHERE a.date = ? AND (a.is_deleted = 0 OR a.is_deleted IS NULL) AND $sysCond
      ORDER BY s.roll_number ASC, s.name ASC
    ''', [date]);
  }

  Future<Map<String, int>> getAttendanceStats(
    String date, {
    String? systemType,
  }) async {
    final rows = await getAttendanceByDate(date, systemType: systemType);
    int present = 0, absent = 0, leave = 0, lateCount = 0;

    for (final r in rows) {
      for (int i = 1; i <= 4; i++) {
        final status = (r['status_$i'] ?? '').toString().toUpperCase().trim();
        final lateMin = (r['late_$i'] as num?)?.toInt() ?? 0;
        if (status.isEmpty) continue;

        if (lateMin > 0 || status == 'LATE') {
          lateCount++;
        } else if (status == 'P' || status == 'PRESENT') {
          present++;
        } else if (status == 'A' || status == 'ABSENT') {
          absent++;
        } else if (status == 'L' || status == 'LEAVE' || status == 'R') {
          leave++;
        }
      }
    }

    return {
      'present': present,
      'absent': absent,
      'leave': leave,
      'late': lateCount,
      'total': present + absent + leave + lateCount,
    };
  }

  Future<int> markStudentAttendance(Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final id = await db.insert(
      'attendance',
      values,
      conflictAlgorithm: ConflictAlgorithm.replace,
    );

    DataRefreshBus.notify(DataDomain.attendance,
        action: 'mark_student', entityId: values['student_id']);
    return id;
  }

  Future<List<Map<String, dynamic>>> getTeacherAttendance(
    String date, {
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "t.system_type = 'Masjid'"
        : "(t.system_type = 'Madrasa' OR t.system_type IS NULL OR t.system_type = '')";

    return await db.rawQuery('''
      SELECT at.*, t.name as teacher_name, t.phone
      FROM attendance_teachers at
      JOIN teachers t ON at.teacher_id = t.id
      WHERE at.date = ? AND (at.is_deleted = 0 OR at.is_deleted IS NULL) AND $sysCond
    ''', [date]);
  }

  Future<int> markTeacherAttendance(Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final id = await db.insert(
      'attendance_teachers',
      values,
      conflictAlgorithm: ConflictAlgorithm.replace,
    );

    DataRefreshBus.notify(DataDomain.attendance,
        action: 'mark_teacher', entityId: values['teacher_id']);
    return id;
  }
}
