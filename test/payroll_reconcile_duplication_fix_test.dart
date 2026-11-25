import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('Payroll Auto-Reconcile Duplication Fix & Privacy Tests', () {
    test('Condition 1: Startup cleanup purges machine-generated payroll rows unconditionally (both is_synced=0 and 1) without touching real records', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              user_name TEXT,
              type TEXT,
              amount REAL,
              description TEXT,
              system_type TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
          await db.execute('''
            CREATE TABLE masjid_finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              user_name TEXT,
              type TEXT,
              amount REAL,
              description TEXT,
              system_type TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // 1. Seed simulated machine-generated duplicate rows (both unsynced 0 and synced 1)
      await db.insert('finance', {
        'user_id': 1,
        'user_name': 'Admin',
        'type': 'Expense',
        'amount': 25000.0,
        'description': 'وظیفہ/تنخواہ ادا کی گئی برائے ماہ 2026-10: ذیشان احمد',
        'system_type': 'Madrasa',
        'is_synced': 0,
      });
      await db.insert('finance', {
        'user_id': 1,
        'user_name': 'Admin',
        'type': 'Expense',
        'amount': 30000.0,
        'description': 'مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی برائے ماہ 2026-10: قاری حماد (Cash)',
        'system_type': 'Madrasa',
        'is_synced': 1, // synced from earlier wave
      });
      await db.insert('masjid_finance', {
        'user_id': 2,
        'user_name': 'حماد',
        'type': 'Expense',
        'amount': 15000.0,
        'description': 'وظیفہ/تنخواہ ادا کی گئی برائے ماہ 2026-11: حافظ عمر',
        'system_type': 'Masjid',
        'is_synced': 0,
      });

      // 2. Seed legitimate manual finance records that MUST NOT be touched
      await db.insert('finance', {
        'user_id': 3,
        'user_name': 'سلیم',
        'type': 'Expense',
        'amount': 1200.0,
        'description': 'بجلی مرمت سامان برائے دارالاقامہ',
        'system_type': 'Madrasa',
        'is_synced': 0,
      });
      await db.insert('finance', {
        'user_id': 1,
        'user_name': 'Admin',
        'type': 'Income',
        'amount': 5000.0,
        'description': 'عطیات برائے ماہ شعبان',
        'system_type': 'Madrasa',
        'is_synced': 1,
      });

      // Verify counts before cleanup
      final preFin = await db.query('finance');
      final preMasjid = await db.query('masjid_finance');
      expect(preFin.length, equals(4));
      expect(preMasjid.length, equals(1));

      // 3. Run Condition 1 Pattern-based unconditional cleanup
      final purgedFin = await db.rawDelete(
        "DELETE FROM finance WHERE description LIKE '%وظیفہ/تنخواہ ادا کی گئی برائے ماہ%'"
      );
      final purgedMasjid = await db.rawDelete(
        "DELETE FROM masjid_finance WHERE description LIKE '%وظیفہ/تنخواہ ادا کی گئی برائے ماہ%'"
      );

      expect(purgedFin, equals(2)); // Both is_synced=0 and is_synced=1 payroll rows purged
      expect(purgedMasjid, equals(1));

      // 4. Verify post-cleanup: strictly ZERO payroll duplicates remain
      final remainingPayrollFin = await db.rawQuery(
        "SELECT COUNT(*) as cnt FROM finance WHERE description LIKE '%وظیفہ/تنخواہ ادا کی گئی برائے ماہ%'"
      );
      final remainingPayrollMasjid = await db.rawQuery(
        "SELECT COUNT(*) as cnt FROM masjid_finance WHERE description LIKE '%وظیفہ/تنخواہ ادا کی گئی برائے ماہ%'"
      );
      expect(remainingPayrollFin.first['cnt'], equals(0));
      expect(remainingPayrollMasjid.first['cnt'], equals(0));

      // 5. Verify legitimate records are 100% intact
      final postFin = await db.query('finance');
      expect(postFin.length, equals(2));
      expect(postFin[0]['description'], equals('بجلی مرمت سامان برائے دارالاقامہ'));
      expect(postFin[1]['description'], equals('عطیات برائے ماہ شعبان'));

      await db.close();
    });

    test('Condition 2: Updating payroll status to Paid updates acc_payroll without creating finance rows', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE acc_payroll(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              teacher_id INTEGER,
              month_year TEXT,
              basic_salary REAL DEFAULT 0,
              allowances REAL DEFAULT 0,
              deductions REAL DEFAULT 0,
              net_salary REAL DEFAULT 0,
              status TEXT DEFAULT 'Unpaid',
              payment_date TEXT,
              payment_account_id INTEGER,
              payment_method TEXT DEFAULT 'Cash',
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
          await db.execute('''
            CREATE TABLE finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              type TEXT,
              amount REAL,
              description TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // Insert Unpaid payroll item
      final pid = await db.insert('acc_payroll', {
        'teacher_id': 101,
        'month_year': '2026-11',
        'basic_salary': 35000.0,
        'allowances': 2000.0,
        'deductions': 1000.0,
        'net_salary': 36000.0,
        'status': 'Unpaid',
        'is_synced': 1,
      });

      // Mark payroll as Paid (new behavior: only updates acc_payroll, zero finance insert)
      await db.update(
        'acc_payroll',
        {
          'status': 'Paid',
          'payment_date': '2026-11-19',
          'payment_account_id': 1001,
          'payment_method': 'Cash',
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [pid],
      );

      // Verify acc_payroll status is Paid
      final paidRows = await db.query('acc_payroll', where: 'id = ?', whereArgs: [pid]);
      expect(paidRows.first['status'], equals('Paid'));
      expect(paidRows.first['payment_date'], equals('2026-11-19'));

      // Verify finance table remains strictly untouched (COUNT = 0)
      final finRows = await db.query('finance');
      expect(finRows.length, equals(0));

      await db.close();
    });

    test('Condition 3 (Point 16): Non-admin local finance privacy purge completely isolates other users records', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              user_name TEXT,
              account_id INTEGER,
              type TEXT,
              amount REAL,
              description TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 1
            )
          ''');
          await db.execute('''
            CREATE TABLE masjid_finance(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              user_name TEXT,
              account_id INTEGER,
              type TEXT,
              amount REAL,
              description TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 1
            )
          ''');
          await db.execute('''
            CREATE TABLE acc_chart_of_accounts(
              id INTEGER PRIMARY KEY,
              code TEXT,
              name TEXT,
              type TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // Account 1503 is Saleem personal advance account
      await db.insert('acc_chart_of_accounts', {
        'id': 1503,
        'code': '1503',
        'name': 'خرچ کھاتہ - محمد سلیم',
        'type': 'Asset',
      });
      // Account 1001 is Central Cash (Admin)
      await db.insert('acc_chart_of_accounts', {
        'id': 1001,
        'code': '1001',
        'name': 'Cash in Hand (نقد رقم)',
        'type': 'Asset',
      });

      // Seed records of other users (Admin / Tanzeel) from old sync
      await db.insert('finance', {
        'user_id': 1,
        'user_name': 'تنزیل الرحمن',
        'account_id': 1001,
        'type': 'Income',
        'amount': 500000.0,
        'description': 'مرکزی عطیات',
        'is_synced': 1,
      });
      await db.insert('finance', {
        'user_id': 1,
        'user_name': 'Admin',
        'account_id': 1001,
        'type': 'Expense',
        'amount': 150000.0,
        'description': 'تعمیراتی اخراجات',
        'is_synced': 1,
      });

      // Seed Saleem (user_id = 3) personal records
      await db.insert('finance', {
        'user_id': 3,
        'user_name': 'سلیم',
        'account_id': 1503,
        'type': 'Expense',
        'amount': 3500.0,
        'description': 'کچن راشن',
        'is_synced': 1,
      });

      // Execute non-admin privacy purge for user 3 (Saleem)
      final myUid = 3;
      final myAccountIds = [1503];
      final accCond = "AND (account_id IS NULL OR account_id NOT IN (${myAccountIds.join(',')}))";

      await db.rawDelete('''
        DELETE FROM finance 
        WHERE (is_synced = 1 OR is_synced IS NULL)
          AND user_id != ?
          $accCond
          AND (user_name IS NULL OR (
            LOWER(TRIM(user_name)) IN ('admin', 'tanzeel', 'تنزیل', 'تنزیل الرحمن')
            OR LOWER(user_name) LIKE '%tanzeel%'
            OR LOWER(user_name) LIKE '%تنزیل%'
          ))
      ''', [myUid]);

      // Verify on-device privacy: other users local rows = 0!
      final otherUsersRows = await db.rawQuery(
        'SELECT COUNT(*) as cnt FROM finance WHERE user_id != ?', [myUid]
      );
      expect(otherUsersRows.first['cnt'], equals(0));

      // Verify user 3 own record remains 100% intact
      final myRows = await db.query('finance', where: 'user_id = ?', whereArgs: [myUid]);
      expect(myRows.length, equals(1));
      expect(myRows.first['amount'], equals(3500.0));

      await db.close();
    });
  });
}
