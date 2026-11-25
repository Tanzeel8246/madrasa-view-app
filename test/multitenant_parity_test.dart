import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:madrasa_app/db_helper.dart';
import 'package:madrasa_app/utils/data_refresh_bus.dart';
import 'package:madrasa_app/utils/globals.dart';
import 'package:madrasa_app/utils/system_type_global.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  group('Multi-Tenant Parity & Core Invariants', () {
    test('DataRefreshBus broadcasts domain & global events cleanly', () async {
      final events = <DataRefreshEvent>[];
      final sub = DataRefreshBus.stream.listen(events.add);

      DataRefreshBus.notify(DataDomain.students, action: 'insert', entityId: 42);
      DataRefreshBus.notifyAll('sync_completed');
      await Future<void>.delayed(const Duration(milliseconds: 20));
      await sub.cancel();

      expect(events.length, equals(2));
      expect(events[0].domain, equals(DataDomain.students));
      expect(events[0].entityId, equals(42));
      expect(events[1].domain, equals(DataDomain.all));
      expect(events[1].metadata?['reason'], equals('sync_completed'));
    });

    test('SQLite DBHelper supports ST course APIs + Approved/Reversed GL system isolation', () async {
      final dbHelper = DBHelper();
      await dbHelper.clearAndResetDatabase();
      final db = await dbHelper.database;

      // Verify course_structure & course_subjects compatibility columns exist
      final courseCols = (await db.rawQuery('PRAGMA table_info(course_structure)'))
          .map((r) => r['name'].toString())
          .toSet();
      expect(courseCols.contains('system_type'), isTrue);

      final subjCols = (await db.rawQuery('PRAGMA table_info(course_subjects)'))
          .map((r) => r['name'].toString())
          .toSet();
      expect(subjCols.contains('year_name'), isTrue);
      expect(subjCols.contains('year_title'), isTrue);

      final enrollCols = (await db.rawQuery('PRAGMA table_info(student_course_enrollment)'))
          .map((r) => r['name'].toString())
          .toSet();
      expect(enrollCols.contains('roll_number'), isTrue);

      // Verify Madrasa vs Masjid isolation and Approved+Reversed voucher inclusion
      final accRevId = await db.insert('acc_chart_of_accounts', {
        'code': '4901',
        'name': 'ٹیسٹ آمدنی کھاتہ',
        'type': 'Revenue',
        'is_deleted': 0,
      });
      final accCashId = await db.insert('acc_chart_of_accounts', {
        'code': '1091',
        'name': 'ٹیسٹ کیش کھاتہ',
        'type': 'Asset',
        'is_deleted': 0,
      });

      final vMadrasa = await db.insert('acc_vouchers', {
        'voucher_no': 'TST-M1',
        'date': '2026-05-01',
        'type': 'Receipt',
        'status': 'Approved',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vMadrasa,
        'account_id': accCashId,
        'debit': 5000.0,
        'credit': 0.0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vMadrasa,
        'account_id': accRevId,
        'debit': 0.0,
        'credit': 5000.0,
      });

      final vReversed = await db.insert('acc_vouchers', {
        'voucher_no': 'TST-M2',
        'date': '2026-05-02',
        'type': 'Journal',
        'status': 'Reversed',
        'system_type': 'Madrasa',
        'is_deleted': 0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vReversed,
        'account_id': accCashId,
        'debit': 0.0,
        'credit': 1000.0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vReversed,
        'account_id': accRevId,
        'debit': 1000.0,
        'credit': 0.0,
      });

      final vMasjid = await db.insert('acc_vouchers', {
        'voucher_no': 'TST-J1',
        'date': '2026-05-03',
        'type': 'Receipt',
        'status': 'Approved',
        'system_type': 'Masjid',
        'is_deleted': 0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vMasjid,
        'account_id': accCashId,
        'debit': 3000.0,
        'credit': 0.0,
      });
      await db.insert('acc_ledger_entries', {
        'voucher_id': vMasjid,
        'account_id': accRevId,
        'debit': 0.0,
        'credit': 3000.0,
      });

      SystemTypeGlobal.currentSystemType.value = 'Madrasa';
      final madrasaStmt = await dbHelper.getIncomeStatement(
        fromDate: '2026-05-01',
        toDate: '2026-05-31',
        systemType: 'Madrasa',
      );
      expect(madrasaStmt['total_revenue'], equals(4000.0));

      final masjidStmt = await dbHelper.getIncomeStatement(
        fromDate: '2026-05-01',
        toDate: '2026-05-31',
        systemType: 'Masjid',
      );
      expect(masjidStmt['total_revenue'], equals(3000.0));

      // Cleanup test records so local dev db isn't polluted if shared
      await dbHelper.clearAndResetDatabase();
    });

    test('Dynamic tenant globals initialize cleanly without single-institute lock', () {
      expect(isMultiTenantEnabled.value, isTrue);
    });
  });
}
