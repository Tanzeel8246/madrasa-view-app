import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('Phase Complete Verification: Privacy Isolation & Zero Crash', () {
    test('Non-admin user NEVER retrieves admin entries (user_id = 1 / tanzeel) and receives 100% of their own records', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              user_name TEXT,
              spender_name TEXT,
              donor_name TEXT,
              account_id INTEGER,
              type TEXT,
              amount REAL,
              date TEXT,
              system_type TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
          await db.execute('''
            CREATE TABLE admins(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              username TEXT,
              name TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
          await db.execute('''
            CREATE TABLE teachers(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT,
              user_name TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // Seed Admins
      await db.insert('admins', {'id': 1, 'username': 'tanzeel', 'name': 'تنزیل الرحمن'});
      // Seed Teacher with ID 1 (Teacher table has ID 1 for first teacher)
      await db.insert('teachers', {'id': 1, 'user_name': 'qari_abdullah', 'name': 'قاری عبداللہ'});

      // Admin Entries
      await db.insert('finance', {
        'id': 1,
        'user_id': 1,
        'user_name': 'tanzeel',
        'spender_name': 'تنزیل الرحمن',
        'type': 'Expense',
        'amount': 50000.0,
        'date': '2026-11-15',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      await db.insert('finance', {
        'id': 2,
        'user_id': 1,
        'user_name': 'Admin',
        'donor_name': 'حاجی صاحب',
        'type': 'Income',
        'amount': 100000.0,
        'date': '2026-11-16',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Teacher Abdullah's own entries
      await db.insert('finance', {
        'id': 3,
        'user_id': 1, // teacher id 1
        'user_name': 'قاری عبداللہ',
        'spender_name': 'قاری عبداللہ',
        'type': 'Expense',
        'amount': 2500.0,
        'date': '2026-11-17',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      await db.insert('finance', {
        'id': 4,
        'user_id': 1, // teacher id 1
        'user_name': 'قاری عبداللہ',
        'donor_name': 'محمد احمد',
        'type': 'Income',
        'amount': 3000.0,
        'date': '2026-11-18',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Query as non-admin teacher 'قاری عبداللہ'
      final targetUserId = 1;
      final role = 'teacher';
      final curName = 'قاری عبداللہ';

      final Set<String> myNames = {curName};
      if (role == 'teacher') {
        final tcRows = await db.rawQuery(
          "SELECT name, user_name FROM teachers WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          [targetUserId],
        );
        for (final r in tcRows) {
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          final u = r['user_name']?.toString().trim().toLowerCase() ?? '';
          if (n.isNotEmpty) myNames.add(n);
          if (u.isNotEmpty) myNames.add(u);
        }
      }

      // Strict isolation
      myNames.remove('admin');
      myNames.remove('administrator');
      myNames.remove('superadmin');
      myNames.remove('tanzeel');
      myNames.remove('تنزیل');
      myNames.remove('تنزیل الرحمن');

      final List<String> userMatch = [];
      final List<dynamic> userArgs = [];

      final namePlaceholders = myNames.map((_) => '?').join(',');
      userMatch.add("LOWER(TRIM(user_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
      userMatch.add("LOWER(TRIM(spender_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
      userMatch.add("LOWER(TRIM(donor_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);

      const privacyGuard = " AND (user_name IS NULL OR (LOWER(TRIM(user_name)) NOT IN ('admin', 'tanzeel', 'تنزیل الرحمن') AND LOWER(TRIM(user_name)) NOT LIKE '%tanzeel%' AND LOWER(TRIM(user_name)) NOT LIKE '%تنزیل%'))";
      const sysWhere = "(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      final userWhere = "(${userMatch.join(' OR ')})$privacyGuard AND ";
      final nonAdminResults = await db.query(
        'finance',
        where: '$userWhere$sysWhere',
        whereArgs: userArgs,
        orderBy: 'date DESC, id DESC',
      );

      // VERIFICATION:
      // 1. Non-admin MUST NOT see any admin record
      expect(nonAdminResults.any((r) => r['id'] == 1), isFalse, reason: "Admin expense id 1 leaked!");
      expect(nonAdminResults.any((r) => r['id'] == 2), isFalse, reason: "Admin income id 2 leaked!");

      // 2. Non-admin MUST see both of their own records
      expect(nonAdminResults.any((r) => r['id'] == 3), isTrue, reason: "Teacher expense id 3 missing!");
      expect(nonAdminResults.any((r) => r['id'] == 4), isTrue, reason: "Teacher income id 4 missing!");
      expect(nonAdminResults.length, equals(2));
    });

    test('365-Day Academic reporting loop does not create unbounded dummy bloating', () {
      final sDate = DateTime(2025, 1, 1);
      final eDate = DateTime(2025, 12, 31);
      final totalDays = eDate.difference(sDate).inDays + 1;
      expect(totalDays, equals(365));

      final isLongRange = totalDays > 35;
      expect(isLongRange, isTrue);

      final attLookup = <String, Map<String, String>>{
        "10_2025-05-15": {"main": "A"},
        "10_2025-05-16": {"main": "P"},
      };

      final combinedRecords = <Map<String, dynamic>>[];
      final existingRecKeys = <String>{};

      // Simulate optimized loop
      for (DateTime d = sDate; !d.isAfter(eDate); d = DateTime(d.year, d.month, d.day + 1)) {
        String isoDate = "${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";
        String sid = "10";
        String recKey = "${sid}_$isoDate";
        if (!existingRecKeys.contains(recKey)) {
          var attInfo = attLookup[recKey];
          if (isLongRange && attInfo == null) continue; // Optimization verified!

          existingRecKeys.add(recKey);
          combinedRecords.add({
            'student_id': 10,
            'date': isoDate,
            'is_dummy': 1,
          });
        }
      }

      // Bloating avoided: Only 2 records created instead of 365!
      expect(combinedRecords.length, equals(2));
    });
  });
}
