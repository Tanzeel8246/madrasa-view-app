import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:madrasa_app/utils/globals.dart';
import 'package:madrasa_app/utils/system_type_global.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  group('Masjid Permissions Isolation & Finance User Account Restoration Tests', () {
    test('1. Non-admin user receives their own income and expense entries via personal accounts, while admin central cash is blocked', () async {
      final db = await openDatabase(
        inMemoryDatabasePath,
        version: 1,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE acc_chart_of_accounts(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT,
              name TEXT,
              type TEXT,
              is_deleted INTEGER DEFAULT 0
            )
          ''');
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
        },
      );

      // Accounts
      await db.insert('acc_chart_of_accounts', {'id': 1, 'code': '1001', 'name': 'مرکزی کیش (Cash in Hand)', 'type': 'Asset'});
      await db.insert('acc_chart_of_accounts', {'id': 2, 'code': '1503', 'name': 'خرچ کھاتہ - قاری سلیم اللہ صاحب', 'type': 'Asset'});
      await db.insert('acc_chart_of_accounts', {'id': 3, 'code': '1102', 'name': 'نقد وصولی - قاری سلیم اللہ صاحب', 'type': 'Asset'});

      // Admin central entries
      await db.insert('finance', {
        'id': 101,
        'user_id': 1,
        'user_name': 'tanzeel',
        'spender_name': '',
        'donor_name': 'حاجی رشید',
        'account_id': 1, // Central Cash
        'type': 'Income',
        'amount': 50000.0,
        'date': '2026-11-16',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      await db.insert('finance', {
        'id': 102,
        'user_id': 1,
        'user_name': 'tanzeel',
        'spender_name': 'بل بجلی',
        'donor_name': '',
        'account_id': 1, // Central Cash
        'type': 'Expense',
        'amount': 15000.0,
        'date': '2026-11-16',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // User Saleem's personal entries (as created via Advance & Collection)
      await db.insert('finance', {
        'id': 201,
        'user_id': 3,
        'user_name': 'قاری سلیم اللہ صاحب',
        'spender_name': 'کریانہ مارکیٹ راشن',
        'donor_name': '',
        'account_id': 2, // Saleem's Advance Account
        'type': 'Expense',
        'amount': 2500.0,
        'date': '2026-11-17',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      await db.insert('finance', {
        'id': 202,
        'user_id': 1, // Notice: fee invoice synced from web has user_id = 1 on server!
        'user_name': 'Admin',
        'spender_name': '',
        'donor_name': 'طالب علم بلال',
        'account_id': 3, // Saleem's Collection Account
        'type': 'Income',
        'amount': 3000.0,
        'date': '2026-11-18',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });

      // Execute the exact query logic used in getFinance for Saleem
      const curName = 'قاری سلیم اللہ صاحب';
      const targetUserId = 3;
      const sysType = 'Madrasa';

      final Set<String> myNames = {curName.toLowerCase(), 'saleem', 'قاری سلیم اللہ'};
      final List<String> userMatch = [];
      final List<dynamic> userArgs = [];

      userMatch.add("user_id = ?");
      userArgs.add(targetUserId);

      final namePlaceholders = myNames.map((_) => '?').join(',');
      userMatch.add("LOWER(TRIM(user_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
      userMatch.add("LOWER(TRIM(spender_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
      userMatch.add("LOWER(TRIM(donor_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);

      // Resolve personal accounts
      final List<String> accPatterns = ['%سلیم%', '%saleem%'];
      final whereAcc = accPatterns.map((_) => "name LIKE ?").join(' OR ');
      final pAccs = await db.rawQuery(
        "SELECT id, code, name FROM acc_chart_of_accounts WHERE type = 'Asset' AND ($whereAcc) AND (is_deleted = 0 OR is_deleted IS NULL)",
        accPatterns,
      );
      final Set<int> myPersonalAccountIds = {};
      for (final pa in pAccs) {
        final pCode = (pa['code']?.toString() ?? '').trim();
        final pName = (pa['name']?.toString() ?? '').toLowerCase();
        final pId = int.tryParse(pa['id']?.toString() ?? '') ?? 0;
        if (pCode == '1001' || pCode == '1002') continue;
        if (pId > 0 && (pName.contains('خرچ') || pName.contains('ایڈوانس') || pName.contains('وصولی'))) {
          myPersonalAccountIds.add(pId);
        }
      }

      expect(myPersonalAccountIds.contains(2), isTrue);
      expect(myPersonalAccountIds.contains(3), isTrue);
      expect(myPersonalAccountIds.contains(1), isFalse); // Central cash never included

      final accPlaceholders = myPersonalAccountIds.map((_) => '?').join(',');
      userMatch.add("account_id IN ($accPlaceholders)");
      userArgs.addAll(myPersonalAccountIds);

      final privacyGuard = " AND (account_id IN (${myPersonalAccountIds.join(',')}) OR ((user_id IS NULL OR user_id != 1) AND (user_name IS NULL OR (LOWER(TRIM(user_name)) NOT IN ('admin', 'tanzeel', 'تنزیل الرحمن') AND LOWER(TRIM(user_name)) NOT LIKE '%tanzeel%' AND LOWER(TRIM(user_name)) NOT LIKE '%تنزیل%'))))";
      const sysWhere = '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = ? OR system_type IS NULL OR system_type = \'\' OR LOWER(system_type) = LOWER(?))';

      final userWhere = "(${userMatch.join(' OR ')})$privacyGuard AND ";
      final result = await db.query(
        'finance',
        where: '$userWhere$sysWhere',
        whereArgs: [...userArgs, sysType, sysType],
        orderBy: 'date DESC, id DESC',
      );

      // Verify Results:
      // Must contain Saleem's expense (id 201) and Saleem's income (id 202)
      final ids = result.map((r) => r['id']).toList();
      expect(ids, contains(201));
      expect(ids, contains(202));
      // Must NOT contain Admin's Central Cash entries (id 101, 102)
      expect(ids, isNot(contains(101)));
      expect(ids, isNot(contains(102)));

      final incomeRows = result.where((r) => r['type'] == 'Income').toList();
      final expenseRows = result.where((r) => r['type'] == 'Expense').toList();
      expect(incomeRows.length, 1);
      expect(expenseRows.length, 1);
      expect(incomeRows.first['amount'], 3000.0);
      expect(expenseRows.first['amount'], 2500.0);
    });

    test('2. User with zero Masjid permissions is strictly blocked from Masjid permissions in AppDrawer and Dashboard', () {
      // Simulate a user who has only Madrasa permissions
      currentUserRole.value = 'teacher';
      currentUserPermissions.value = [
        'manage_attendance',
        'manage_academics',
        'manage_finance',
        'manage_students',
        'manage_classes',
        'manage_requests',
        'manage_notices',
      ];

      // Replicate AppDrawer.hasPerm logic
      bool appDrawerHasPerm(String perm) {
        final role = currentUserRole.value?.toLowerCase() ?? '';
        if (role == 'superadmin') return true;
        if (currentUserPermissions.value.contains('all')) return true;
        if (role == 'admin' && (currentUserPermissions.value.isEmpty || currentUserPermissions.value.contains('all'))) return true;
        if (currentUserPermissions.value.contains(perm)) return true;

        if (perm.startsWith('manage_masjid_')) {
          return currentUserPermissions.value.contains(perm);
        }

        if (role == 'nazim') {
          const nazimPerms = {
            'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
            'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
            'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
            'manage_students', 'manage_teachers', 'manage_classes',
            'manage_finance_entry', 'manage_finance_report', 'manage_finance',
            'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
          };
          if (nazimPerms.contains(perm)) return true;
        }
        return false;
      }

      // Replicate Dashboard._hasPerm logic
      bool dashboardHasPerm(String permission) {
        if (currentUserRole.value == 'superadmin') return true;
        if (currentUserPermissions.value.contains('all')) return true;
        if (currentUserRole.value == 'admin' && (currentUserPermissions.value.isEmpty || currentUserPermissions.value.contains('all'))) return true;

        bool checkPerm(String p) {
          if (SystemTypeGlobal.currentSystemType.value == 'Masjid') {
            if (p == 'admin') {
              final r = currentUserRole.value?.toLowerCase() ?? '';
              return r == 'admin' || r == 'superadmin';
            }
            if (!p.startsWith('manage_masjid_')) {
              return false;
            }
            return currentUserPermissions.value.contains(p);
          }
          if (currentUserRole.value == 'nazim') {
            const nazimBase = {
              'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
              'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
              'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
              'manage_students', 'manage_teachers', 'manage_classes',
              'manage_finance_entry', 'manage_finance_report',
              'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
            };
            if (nazimBase.contains(p)) return true;
          }
          return currentUserPermissions.value.contains(p);
        }

        if (permission.contains('|')) {
          return permission.split('|').any((p) => checkPerm(p));
        }
        return checkPerm(permission);
      }

      // Check AppDrawer permissions in Masjid mode
      expect(appDrawerHasPerm('manage_masjid_attendance'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_academics'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_students'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_teachers'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_classes'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_requests'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_notices'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_finance'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_exams'), isFalse);
      expect(appDrawerHasPerm('manage_masjid_itikaf'), isFalse);

      // Check Dashboard Cards in Masjid mode
      SystemTypeGlobal.currentSystemType.value = 'Masjid';

      // 5 target cards mentioned by user:
      // 1. رجسٹر حاضری
      expect(dashboardHasPerm('manage_masjid_attendance'), isFalse);
      // 2. حاضری رپورٹ
      expect(dashboardHasPerm('manage_masjid_attendance|manage_masjid_attendance_report'), isFalse);
      // 3. اساتذہ حاضری رپورٹ
      expect(dashboardHasPerm('manage_masjid_attendance|manage_masjid_teacher_attendance'), isFalse);
      // 4. تعلیمی رپورٹ (اندراج)
      expect(dashboardHasPerm('manage_masjid_academics|manage_masjid_hifz|manage_masjid_academic_entry'), isFalse);
      // 5. تعلیمی رپورٹ (دیکھیں)
      expect(dashboardHasPerm('manage_masjid_academics|manage_masjid_hifz|manage_masjid_academic_view'), isFalse);

      // Additional cards mentioned by user:
      // درخواستیں و شکایات / شکایات ریکارڈ
      expect(dashboardHasPerm('manage_masjid_students|manage_requests|admin'), isFalse);
      // مکتب طلباء
      expect(dashboardHasPerm('manage_masjid_students'), isFalse);
      // مسجد فائنانس
      expect(dashboardHasPerm('manage_masjid_finance|manage_masjid_finance_entry'), isFalse);
      expect(dashboardHasPerm('manage_masjid_finance|admin'), isFalse);
      // اسمارٹ الرٹس و تنبیہات
      expect(dashboardHasPerm('manage_masjid_notices|manage_masjid_alerts|admin'), isFalse);
    });
  });
}
