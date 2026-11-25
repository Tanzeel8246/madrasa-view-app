import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('Dashboard Metrics & Real-Time Query Parity Tests', () {
    late Database db;

    setUp(() async {
      db = await databaseFactory.openDatabase(inMemoryDatabasePath);
      // Create minimal tables
      await db.execute('''
        CREATE TABLE students (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT,
          status TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0
        )
      ''');

      await db.execute('''
        CREATE TABLE teachers (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT,
          status TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0
        )
      ''');

      await db.execute('''
        CREATE TABLE classes (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0
        )
      ''');

      // Seed students:
      // 1. NULL status (Active imported)
      await db.insert('students', {'name': 'Hamza Ali', 'status': null, 'system_type': 'Madrasa'});
      // 2. Empty string status (Active)
      await db.insert('students', {'name': 'Zoheb Hassan', 'status': '', 'system_type': 'Madrasa'});
      // 3. Urdu active status
      await db.insert('students', {'name': 'Zain ul Abideen', 'status': 'موجود', 'system_type': 'Madrasa'});
      // 4. 'Active' status
      await db.insert('students', {'name': 'Alyan Ahmed', 'status': 'Active', 'system_type': 'Madrasa'});
      // 5. 'present' status
      await db.insert('students', {'name': 'Abdul Moez', 'status': 'present', 'system_type': 'Madrasa'});
      // 6. Alumni (Should be excluded from active count)
      await db.insert('students', {'name': 'Usman Farooq', 'status': 'Alumni', 'system_type': 'Madrasa'});
      // 7. Urdu Alumni (Should be excluded)
      await db.insert('students', {'name': 'Bilal Tariq', 'status': 'فارغ التحصیل', 'system_type': 'Madrasa'});
      // 8. Soft-deleted student (Should be excluded)
      await db.insert('students', {'name': 'Deleted Student', 'status': 'Active', 'system_type': 'Madrasa', 'is_deleted': 1});

      // Seed teachers:
      await db.insert('teachers', {'name': 'Qari Saleem', 'status': null, 'system_type': 'Madrasa'});
      await db.insert('teachers', {'name': 'Maulana Aqib', 'status': 'Active', 'system_type': 'Madrasa'});
      await db.insert('teachers', {'name': 'Left Teacher', 'status': 'resigned', 'system_type': 'Madrasa'});

      // Seed classes:
      await db.insert('classes', {'name': 'Hifz', 'system_type': 'Madrasa'});
      await db.insert('classes', {'name': 'Nazira', 'system_type': 'Madrasa'});
    });

    tearDown(() async {
      await db.close();
    });

    test('Active students count includes NULL, empty, and Urdu status without dropping rows', () async {
      const activeStudentFilter = "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'موجود', 'حاضر', 'زیر تعلیم') OR LOWER(TRIM(status)) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped', 'struck off', 'inactive', 'چھوڑ دیا', 'ترک تعلیم'))";
      const sysCond = "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      final res = await db.rawQuery(
        "SELECT COUNT(*) as count FROM students WHERE $activeStudentFilter AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond"
      );
      final count = (res.first['count'] as num?)?.toInt() ?? 0;

      // Expect exactly 5 active students (Hamza, Zoheb, Zain, Alyan, Abdul Moez)
      // Usman (Alumni), Bilal (فارغ التحصیل), and Deleted are excluded
      expect(count, equals(5));
    });

    test('Alumni count includes English and Urdu alumni statuses', () async {
      const sysCond = "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      final res = await db.rawQuery(
        "SELECT COUNT(*) as count FROM students WHERE (LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل') OR status LIKE '%فارغ%' OR status LIKE '%alumni%') AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond"
      );
      final count = (res.first['count'] as num?)?.toInt() ?? 0;
      expect(count, equals(2));
    });

    test('Active teachers count includes active and excludes resigned', () async {
      const activeTeacherFilter = "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', 'موجود') OR LOWER(TRIM(status)) NOT IN ('inactive', 'left', 'خارج', 'معطل', 'مستعفی', 'resigned', 'terminated'))";
      const sysCond = "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      final res = await db.rawQuery(
        "SELECT COUNT(*) as count FROM teachers WHERE $activeTeacherFilter AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond"
      );
      final count = (res.first['count'] as num?)?.toInt() ?? 0;
      expect(count, equals(2));
    });

    test('Classes count matches active non-deleted classes', () async {
      const sysCond = "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      final res = await db.rawQuery(
        "SELECT COUNT(*) as count FROM classes WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond"
      );
      final count = (res.first['count'] as num?)?.toInt() ?? 0;
      expect(count, equals(2));
    });
  });
}
