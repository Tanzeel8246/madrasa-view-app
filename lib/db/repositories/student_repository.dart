import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';

class StudentRepository {
  final DBHelper _dbHelper;
  StudentRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  Future<List<Map<String, dynamic>>> getStudents({
    String? systemType,
    bool activeOnly = true,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    final activeCond = activeOnly
        ? "AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'موجود', 'حاضر', 'زیر تعلیم') OR LOWER(TRIM(COALESCE(status, ''))) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped', 'struck off', 'inactive', 'چھوڑ دیا', 'ترک تعلیم'))"
        : "";

    return await db.query(
      'students',
      where: "(is_deleted = 0 OR is_deleted IS NULL) $activeCond AND $sysCond",
      orderBy: 'name ASC',
    );
  }

  Future<Map<String, dynamic>?> getStudentById(int id) async {
    final db = await _db;
    final res = await db.query(
      'students',
      where: 'id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
      whereArgs: [id],
      limit: 1,
    );
    return res.isNotEmpty ? res.first : null;
  }

  Future<List<Map<String, dynamic>>> getStudentsByClass(
    String className, {
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    return await db.query(
      'students',
      where:
          "class_name = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL) AND $sysCond",
      whereArgs: [className],
      orderBy: 'roll_number ASC, name ASC',
    );
  }

  /// فوری ملٹی فیلڈ سرچ (نام، رول نمبر، داخلہ نمبر، والد کا نام، موبائل)
  Future<List<Map<String, dynamic>>> searchStudents(
    String query, {
    String? systemType,
    int limit = 30,
  }) async {
    final db = await _db;
    final trimmed = query.trim();
    if (trimmed.isEmpty) return [];

    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final q = '%$trimmed%';
    return await db.query(
      'students',
      where:
          "(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond AND "
          "(name LIKE ? OR roll_number LIKE ? OR admission_number LIKE ? OR father_name LIKE ? OR contact_number LIKE ?)",
      whereArgs: [q, q, q, q, q],
      orderBy: 'name ASC',
      limit: limit,
    );
  }

  /// طالب علم کی 360 ڈگری جامع ہسٹری و پروفائل
  Future<Map<String, dynamic>> getStudent360Profile(int studentId) async {
    final db = await _db;
    final student = await getStudentById(studentId);
    if (student == null) return {};

    // 1. Attendance Summary
    final attRows = await db.rawQuery('''
      SELECT status_1, status_2, status_3, status_4
      FROM attendance
      WHERE student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
      ORDER BY date DESC
      LIMIT 100
    ''', [studentId]);

    int present = 0, absent = 0, leave = 0;
    for (final r in attRows) {
      for (int i = 1; i <= 4; i++) {
        final st = (r['status_$i'] ?? '').toString().toUpperCase();
        if (st == 'P' || st == 'PRESENT') present++;
        if (st == 'A' || st == 'ABSENT') absent++;
        if (st == 'L' || st == 'LEAVE') leave++;
      }
    }

    // 2. Latest Exam Results
    final examRows = await db.rawQuery('''
      SELECT er.*, e.exam_name, e.exam_date
      FROM exam_results er
      LEFT JOIN exams e ON er.exam_id = e.id
      WHERE er.student_id = ? AND (er.is_deleted = 0 OR er.is_deleted IS NULL)
      ORDER BY er.id DESC
      LIMIT 5
    ''', [studentId]);

    // 3. Fee Receipts & Dues
    final feeRows = await db.rawQuery('''
      SELECT * FROM fee_submissions
      WHERE student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
      ORDER BY submission_date DESC, id DESC
      LIMIT 10
    ''', [studentId]);

    // 4. Hostel Allocation
    final hostelRows = await db.rawQuery('''
      SELECT a.*, r.room_name
      FROM hostel_allocations a
      LEFT JOIN hostel_rooms r ON a.room_id = r.id
      WHERE a.student_id = ? AND a.status = 'Allocated' AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
      LIMIT 1
    ''', [studentId]);

    // 5. Library Books Issued
    final libRows = await db.rawQuery('''
      SELECT i.*, b.title as book_title
      FROM lib_issues i
      LEFT JOIN lib_books b ON i.book_id = b.id
      WHERE i.student_id = ? AND i.status = 'Issued' AND (i.is_deleted = 0 OR i.is_deleted IS NULL)
    ''', [studentId]);

    return {
      'student': student,
      'attendance_summary': {
        'present': present,
        'absent': absent,
        'leave': leave,
        'total_sessions': present + absent + leave,
      },
      'exam_results': examRows,
      'fee_history': feeRows,
      'hostel': hostelRows.isNotEmpty ? hostelRows.first : null,
      'issued_books': libRows,
    };
  }

  Future<int> insertStudent(Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final id = await db.insert('students', values);

    DataRefreshBus.notify(DataDomain.students, action: 'insert', entityId: id);
    return id;
  }

  Future<int> updateStudent(int id, Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final count = await db.update(
      'students',
      values,
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.students, action: 'update', entityId: id);
    return count;
  }

  Future<int> deleteStudent(int id) async {
    final db = await _db;
    final count = await db.update(
      'students',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.students, action: 'delete', entityId: id);
    return count;
  }

  Future<List<Map<String, dynamic>>> getAlumni({String? systemType}) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    return await db.query(
      'students',
      where: "status = 'Alumni' AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
      orderBy: 'id DESC',
    );
  }
}
