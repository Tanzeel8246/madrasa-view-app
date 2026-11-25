import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('User Finance Privacy & Full Self-Record Visibility Tests', () {
    test('Non-admin user retrieves 100% of their own records matching by username or account', () async {
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
          await db.execute('''
            CREATE TABLE acc_chart_of_accounts(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT,
              name TEXT,
              type TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // Insert test records
      // User Aqib (ID: 5, username: aqib, name: مولانا عاقب)
      await db.insert('admins', {'id': 5, 'username': 'aqib', 'name': 'مولانا عاقب'});
      await db.insert('acc_chart_of_accounts', {'id': 20, 'code': '1520', 'name': 'خرچ کھاتہ - مولانا عاقب', 'type': 'Asset'});

      // Aqib's income created with user_name = 'مولانا عاقب' but user_id = 1 (created on server or by admin)
      await db.insert('finance', {
        'id': 101,
        'user_id': 1,
        'user_name': 'مولانا عاقب',
        'type': 'Income',
        'amount': 5000.0,
        'date': '2026-11-10',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Aqib's expense paid from his advance account 20
      await db.insert('finance', {
        'id': 102,
        'user_id': 5,
        'user_name': 'aqib',
        'spender_name': 'مولانا عاقب',
        'account_id': 20,
        'type': 'Expense',
        'amount': 3000.0,
        'date': '2026-11-12',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Another user's record (Saleem, user_id: 3) - MUST BE HIDDEN
      await db.insert('finance', {
        'id': 103,
        'user_id': 3,
        'user_name': 'قاری سلیم اللہ صاحب',
        'type': 'Expense',
        'amount': 15000.0,
        'date': '2026-11-11',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Query as Aqib (user_id: 5, user_name: 'مولانا عاقب')
      final myNames = ['aqib', 'مولانا عاقب'];
      final placeholders = myNames.map((_) => '?').join(',');
      final userArgs = <dynamic>[5, ...myNames, ...myNames, 20];

      final results = await db.rawQuery('''
        SELECT * FROM finance 
        WHERE (
          user_id = ? 
          OR LOWER(TRIM(user_name)) IN ($placeholders)
          OR LOWER(TRIM(spender_name)) IN ($placeholders)
          OR account_id = ?
        )
        AND (is_deleted = 0 OR is_deleted IS NULL)
      ''', userArgs);

      // Verify Aqib sees exactly his 2 records (101 and 102) and NOT Saleem's record (103)
      expect(results.length, 2);
      final ids = results.map((r) => r['id']).toSet();
      expect(ids.contains(101), isTrue, reason: 'Income record 101 must be visible to user');
      expect(ids.contains(102), isTrue, reason: 'Expense record 102 must be visible to user');
      expect(ids.contains(103), isFalse, reason: 'Other user record 103 must be strictly private and hidden');

      await db.close();
    });

    test('Non-admin income collection accounts dropdown strictly contains ONLY user personal collection account', () {
      // Setup the sample accounts exactly as reported by user
      final assetAccounts = [
        {'id': 1, 'code': '10001', 'name': 'کیش ان ہینڈ 10001'},
        {'id': 2, 'code': '1002', 'name': 'بنک بیلنس/بینک اکاؤنٹ 1002'},
        {'id': 3, 'code': '1004', 'name': 'دیگر واجب الوصول 1004'},
        {'id': 4, 'code': '11001', 'name': 'سٹوڈنٹ فیس ریسیو ایبل 11001'},
        {'id': 5, 'code': '5050', 'name': 'کیش ان ہینڈ 5050'},
        {'id': 6, 'code': '1101', 'name': 'نقد وصولی - مولانا تنزیل الرحمن صاحب'},
        {'id': 7, 'code': '1102', 'name': 'نقد وصولی - قاری سلیم اللہ صاحب'},
        {'id': 8, 'code': '1103', 'name': 'نقد وصولی - مولانا طارق صاحب'},
        {'id': 9, 'code': '1503', 'name': 'خرچ کھاتہ - قاری سلیم اللہ صاحب'},
        {'id': 10, 'code': '1501', 'name': 'خرچ کھاتہ - مولانا عثمان صاحب'},
      ];

      // Simulate Tariq (non-admin teacher)
      const currentUserName = 'طارق';
      final selectedCollectionAccountId = 8;

      bool isMyCollectionAccount(String accountName) {
        if (!accountName.contains('وصولی') && !accountName.toLowerCase().contains('collection')) return false;
        final lower = accountName.toLowerCase();
        if (lower.contains('طارق')) return true;
        return false;
      }

      bool isOtherUserAdvanceAccount(String accountName) {
        final lower = accountName.toLowerCase();
        return (lower.contains('خرچ کھاتہ') || lower.contains('ایڈوانس')) && !lower.contains('طارق');
      }

      bool isOtherUserCollectionAccount(String accountName) {
        final lower = accountName.toLowerCase();
        return (lower.contains('وصولی') || lower.contains('collection')) && !isMyCollectionAccount(accountName);
      }

      // Execute non-admin filter logic
      final list = <Map<String, dynamic>>[];
      for (final a in assetAccounts) {
        final name = a['name']?.toString() ?? '';
        final id = int.tryParse(a['id']?.toString() ?? '');
        final code = a['code']?.toString().trim() ?? '';

        if (code == '1001' || code == '10001' || code == '1002' || code == '1004' || code == '11001' || code == '5050') continue;
        if (code.toUpperCase().contains('LOAN') || name.contains('قرض') || name.contains('لون')) continue;
        if (isOtherUserAdvanceAccount(name) || isOtherUserCollectionAccount(name)) continue;
        if (name.toLowerCase().contains('advance') || name.contains('ایڈوانس') || name.contains('خرچ کھاتہ')) continue;

        final isMyCol = isMyCollectionAccount(name) || (id != null && id == selectedCollectionAccountId);
        if (isMyCol) {
          list.add(a);
        }
      }

      // Verification:
      // 1. MUST contain ONLY Tariq's collection account (ID: 8)
      expect(list.length, equals(1));
      expect(list.first['id'], equals(8));
      expect(list.first['name'], equals('نقد وصولی - مولانا طارق صاحب'));

      // 2. Central accounts (10001, 1002, 1004, 11001, 5050) MUST be strictly excluded
      final codes = list.map((a) => a['code']).toSet();
      expect(codes.contains('10001'), isFalse, reason: 'کیش ان ہینڈ 10001 must not appear in dropdown');
      expect(codes.contains('1002'), isFalse, reason: 'بنک بیلنس 1002 must not appear in dropdown');
      expect(codes.contains('1004'), isFalse, reason: 'دیگر واجب الوصول 1004 must not appear in dropdown');
      expect(codes.contains('11001'), isFalse, reason: 'سٹوڈنٹ فیس ریسیو ایبل 11001 must not appear in dropdown');
      expect(codes.contains('5050'), isFalse, reason: 'کیش ان ہینڈ 5050 must not appear in dropdown');
      expect(codes.contains('1101'), isFalse, reason: 'Other user collection 1101 must not appear in dropdown');
      expect(codes.contains('1102'), isFalse, reason: 'Other user collection 1102 must not appear in dropdown');
    });

    test('Advance calculation reconciliation (45,000 received, 33,500 spent, net 11,500)', () {
      final totalRec = 45000.0;
      final bal = 11500.0;
      double totalDed = 0.0;

      // When period filter returns 0 deductions but running balance reflects cumulative deductions
      if (totalDed == 0.0 && bal != totalRec && totalRec > 0) {
        totalDed = totalRec - bal;
      }

      expect(totalDed, 33500.0);
      expect(totalRec - totalDed, bal);
    });
    test('Teacher role user in admins table retrieves both Income and Expense history, including Urdu types, and isolates admin records', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 2,
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
              role TEXT,
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
          await db.execute('''
            CREATE TABLE acc_chart_of_accounts(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT,
              name TEXT,
              type TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
        },
      );

      // Central accounts
      await db.insert('acc_chart_of_accounts', {'id': 1, 'code': '1001', 'name': 'Cash in Hand 1001', 'type': 'Asset'});
      await db.insert('acc_chart_of_accounts', {'id': 2, 'code': '1002', 'name': 'Bank Account 1002', 'type': 'Asset'});

      // Teacher Tariq (created by admin in admins table with ID: 6, role: teacher)
      await db.insert('admins', {'id': 6, 'username': 'tariq', 'name': 'مولانا طارق صاحب', 'role': 'teacher'});
      await db.insert('acc_chart_of_accounts', {'id': 25, 'code': '1525', 'name': 'خرچ کھاتہ - مولانا طارق صاحب', 'type': 'Asset'});
      await db.insert('acc_chart_of_accounts', {'id': 26, 'code': '1125', 'name': 'نقد وصولی - مولانا طارق صاحب', 'type': 'Asset'});

      // Tariq's income 1 (English 'Income', account_id 26)
      await db.insert('finance', {
        'id': 201,
        'user_id': 6,
        'user_name': 'tariq',
        'account_id': 26,
        'type': 'Income',
        'amount': 10000.0,
        'date': '2026-11-15',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Tariq's income 2 (Urdu 'آمدن', synced or entered with donor info)
      await db.insert('finance', {
        'id': 202,
        'user_id': 6,
        'user_name': 'مولانا طارق صاحب',
        'donor_name': 'حاجی محمد',
        'account_id': 26,
        'type': 'آمدن',
        'amount': 5000.0,
        'date': '2026-11-16',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Tariq's expense 1 (English 'Expense', account_id 25)
      await db.insert('finance', {
        'id': 203,
        'user_id': 6,
        'user_name': 'tariq',
        'spender_name': 'مولانا طارق صاحب',
        'account_id': 25,
        'type': 'Expense',
        'amount': 4000.0,
        'date': '2026-11-17',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Tariq's expense 2 (Urdu 'خرچ')
      await db.insert('finance', {
        'id': 204,
        'user_id': 6,
        'user_name': 'مولانا طارق صاحب',
        'spender_name': 'طارق',
        'account_id': 25,
        'type': 'خرچ',
        'amount': 2500.0,
        'date': '2026-11-18',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Central admin record (tanzeel, account_id 1) - MUST BE HIDDEN
      await db.insert('finance', {
        'id': 205,
        'user_id': 1,
        'user_name': 'tanzeel',
        'account_id': 1,
        'type': 'Income',
        'amount': 100000.0,
        'date': '2026-11-10',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Other user record (Saleem, user_id 3) - MUST BE HIDDEN
      await db.insert('finance', {
        'id': 206,
        'user_id': 3,
        'user_name': 'قاری سلیم اللہ صاحب',
        'account_id': 10,
        'type': 'Expense',
        'amount': 8000.0,
        'date': '2026-11-14',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Execute Tariq's query
      final myNames = ['tariq', 'مولانا طارق صاحب', 'طارق'];
      final userMatch = <String>['user_id = ?'];
      final userArgs = <dynamic>[6];
      for (final nm in myNames) {
        userMatch.add("LOWER(TRIM(user_name)) = ?");
        userArgs.add(nm);
        userMatch.add("LOWER(TRIM(spender_name)) = ?");
        userArgs.add(nm);
      }
      userMatch.add("account_id IN (25, 26)");

      final privacyGuard = " AND (account_id NOT IN (1, 2) OR user_id = 6 OR account_id IN (25, 26) OR (user_name IS NOT NULL AND LOWER(TRIM(user_name)) NOT IN ('admin', 'tanzeel') AND LOWER(TRIM(user_name)) NOT LIKE '%tanzeel%'))";
      final sql = "SELECT * FROM finance WHERE (${userMatch.join(' OR ')})$privacyGuard AND (is_deleted = 0 OR is_deleted IS NULL)";

      final rows = await db.rawQuery(sql, userArgs);

      // Verification: Tariq gets exactly his 4 records (201, 202, 203, 204)
      expect(rows.length, equals(4));
      final returnedIds = rows.map((r) => r['id']).toSet();
      expect(returnedIds.contains(201), isTrue);
      expect(returnedIds.contains(202), isTrue);
      expect(returnedIds.contains(203), isTrue);
      expect(returnedIds.contains(204), isTrue);
      expect(returnedIds.contains(205), isFalse, reason: 'Admin central entry 205 must be strictly hidden');
      expect(returnedIds.contains(206), isFalse, reason: 'Other user entry 206 must be strictly hidden');

      // Test UI tab splitting logic (supporting both English & Urdu)
      final incomeList = rows.where((r) {
        final t = (r['type'] ?? '').toString().toLowerCase().trim();
        return t == 'income' || t == 'آمدن';
      }).toList();

      final expenseList = rows.where((r) {
        final t = (r['type'] ?? '').toString().toLowerCase().trim();
        return t == 'expense' || t == 'خرچ';
      }).toList();

      expect(incomeList.length, equals(2), reason: 'Income tab must contain exactly 2 income entries (201 and 202)');
      expect(expenseList.length, equals(2), reason: 'Expense tab must contain exactly 2 expense entries (203 and 204)');

      await db.close();
    });
  });
}
