import '../../db_helper.dart';

extension DbAcademicsExt on DBHelper {
  // --- ACADEMIC METHODS: COURSES ---
  Future<List<Map<String, dynamic>>> getCourses({String? courseType, int? isActive}) async {
    final db = await database;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [];
    if (courseType != null && courseType.isNotEmpty) {
      where += ' AND course_type = ?';
      args.add(courseType);
    }
    if (isActive != null) {
      where += ' AND is_active = ?';
      args.add(isActive);
    }
    return await db.query('course_structure',
        where: where, whereArgs: args.isNotEmpty ? args : null, orderBy: 'id ASC');
  }

  Future<int> insertCourse(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();
    return await db.insert('course_structure', data);
  }

  Future<int> updateCourse(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['updated_at'] = DateTime.now().toIso8601String();
    return await db.update('course_structure', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteCourse(int id) async {
    final db = await database;
    return await db.update('course_structure', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  // --- ACADEMIC METHODS: SUBJECTS ---
  Future<List<Map<String, dynamic>>> getCourseSubjects(int courseId, {int? yearNo}) async {
    final db = await database;
    String where = 'course_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [courseId];
    if (yearNo != null) {
      where += ' AND year_no = ?';
      args.add(yearNo);
    }
    return await db.query('course_subjects',
        where: where, whereArgs: args, orderBy: 'year_no ASC, subject_order ASC, id ASC');
  }

  Future<int> insertCourseSubject(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();
    return await db.insert('course_subjects', data);
  }

  Future<int> updateCourseSubject(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.update('course_subjects', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteCourseSubject(int id) async {
    final db = await database;
    return await db.update('course_subjects', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  // --- ACADEMIC METHODS: ENROLLMENT ---
  Future<Map<String, dynamic>?> getStudentCourseEnrollment(int studentId) async {
    final db = await database;
    final rows = await db.rawQuery('''
      SELECT e.*, c.name as course_name, c.course_type, c.total_years
      FROM student_course_enrollment e
      LEFT JOIN course_structure c ON c.id = e.course_id
      WHERE e.student_id = ? AND (e.is_deleted = 0 OR e.is_deleted IS NULL)
      ORDER BY e.id DESC
      LIMIT 1
    ''', [studentId]);
    return rows.isNotEmpty ? rows.first : null;
  }

  Future<int> enrollStudentInCourse(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();
    return await db.insert('student_course_enrollment', data);
  }

  Future<List<Map<String, dynamic>>> getEnrolledStudentsForCourse({
    required int courseId,
    int? currentYear,
    String? status,
  }) async {
    final db = await database;
    String where = 'e.course_id = ? AND (e.is_deleted = 0 OR e.is_deleted IS NULL)';
    final List<dynamic> args = [courseId];
    if (currentYear != null && currentYear > 0) {
      where += ' AND e.current_year = ?';
      args.add(currentYear);
    }
    if (status != null && status.isNotEmpty) {
      where += ' AND e.status = ?';
      args.add(status);
    }
    return await db.rawQuery('''
      SELECT e.*, s.name as student_name, s.father_name, s.roll_number, s.contact_number
      FROM student_course_enrollment e
      JOIN students s ON s.id = e.student_id
      WHERE $where
      ORDER BY s.roll_number ASC, s.name ASC
    ''', args);
  }

  // --- ACADEMIC METHODS: RESULTS ---
  Future<List<Map<String, dynamic>>> getExamSubjectResults({
    int? examId,
    int? courseId,
    int? yearNo,
    int? studentId,
  }) async {
    final db = await database;
    String where = '(r.is_deleted = 0 OR r.is_deleted IS NULL)';
    final List<dynamic> args = [];
    if (examId != null && examId > 0) {
      where += ' AND r.exam_id = ?';
      args.add(examId);
    }
    if (courseId != null && courseId > 0) {
      where += ' AND r.course_id = ?';
      args.add(courseId);
    }
    if (yearNo != null && yearNo > 0) {
      where += ' AND r.year_no = ?';
      args.add(yearNo);
    }
    if (studentId != null && studentId > 0) {
      where += ' AND r.student_id = ?';
      args.add(studentId);
    }

    return await db.rawQuery('''
      SELECT r.*, s.name as student_name, s.father_name, s.roll_number,
             sub.max_marks as subject_max_marks, sub.pass_marks as subject_pass_marks,
             sub.book_name
      FROM exam_subject_results r
      JOIN students s ON s.id = r.student_id
      LEFT JOIN course_subjects sub ON sub.id = r.subject_id
      WHERE $where
      ORDER BY s.roll_number ASC, s.name ASC, r.subject_id ASC
    ''', args);
  }

  Future<int> saveExamSubjectResult(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();

    final examId = data['exam_id'];
    final studentId = data['student_id'];
    final subjectId = data['subject_id'];

    if (examId != null && studentId != null && subjectId != null) {
      final existing = await db.query(
        'exam_subject_results',
        where: 'exam_id = ? AND student_id = ? AND subject_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
        whereArgs: [examId, studentId, subjectId],
        limit: 1,
      );
      if (existing.isNotEmpty) {
        return await db.update(
          'exam_subject_results',
          data,
          where: 'id = ?',
          whereArgs: [existing.first['id']],
        );
      }
    }
    return await db.insert('exam_subject_results', data);
  }

  Future<void> saveBulkExamSubjectResults(List<Map<String, dynamic>> results) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final row in results) {
        final data = Map<String, dynamic>.from(row);
        data['is_deleted'] = 0;
        data['is_synced'] = 0;
        data['created_at'] ??= DateTime.now().toIso8601String();

        final examId = data['exam_id'];
        final studentId = data['student_id'];
        final subjectId = data['subject_id'];

        if (examId != null && studentId != null && subjectId != null) {
          final existing = await txn.query(
            'exam_subject_results',
            where: 'exam_id = ? AND student_id = ? AND subject_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [examId, studentId, subjectId],
            limit: 1,
          );
          if (existing.isNotEmpty) {
            await txn.update(
              'exam_subject_results',
              data,
              where: 'id = ?',
              whereArgs: [existing.first['id']],
            );
            continue;
          }
        }
        await txn.insert('exam_subject_results', data);
      }
    });
  }

  // --- ACADEMIC METHODS: PROMOTION ---
  Future<int> promoteStudent(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();

    final promoId = await db.insert('student_year_promotion', data);

    // If status is 'Promoted', also update the student's current enrollment
    if (data['status'] == 'Promoted' && data['student_id'] != null && data['to_year'] != null) {
      await db.update(
        'student_course_enrollment',
        {
          'current_year': data['to_year'],
          'academic_session': data['academic_session'],
          'is_synced': 0,
        },
        where: 'student_id = ? AND course_id = ? AND status = ?',
        whereArgs: [data['student_id'], data['course_id'], 'active'],
      );
    }
    return promoId;
  }

  Future<List<Map<String, dynamic>>> getStudentPromotions(int studentId) async {
    final db = await database;
    return await db.rawQuery('''
      SELECT p.*, c.name as course_name
      FROM student_year_promotion p
      LEFT JOIN course_structure c ON c.id = p.course_id
      WHERE p.student_id = ? AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      ORDER BY p.id DESC
    ''', [studentId]);
  }
}
