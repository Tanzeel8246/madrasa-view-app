import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

class FinanceSyncSaver {
  static Future<void> save(Database db, Batch batch, Map<String, dynamic> data) async {
    try {
      final finCols = await db.rawQuery("PRAGMA table_info(finance)");
      final finColNames = finCols.map((c) => c['name']?.toString()).toSet();
      if (!finColNames.contains('account_id')) {
        await db.execute("ALTER TABLE finance ADD COLUMN account_id INTEGER");
      }
      if (!finColNames.contains('payment_method')) {
        await db.execute("ALTER TABLE finance ADD COLUMN payment_method TEXT DEFAULT 'Cash'");
      }
      if (!finColNames.contains('spender_name')) {
        await db.execute("ALTER TABLE finance ADD COLUMN spender_name TEXT");
      }

      final mFinCols = await db.rawQuery("PRAGMA table_info(masjid_finance)");
      final mFinColNames = mFinCols.map((c) => c['name']?.toString()).toSet();
      if (!mFinColNames.contains('account_id')) {
        await db.execute("ALTER TABLE masjid_finance ADD COLUMN account_id INTEGER");
      }
      if (!mFinColNames.contains('payment_method')) {
        await db.execute("ALTER TABLE masjid_finance ADD COLUMN payment_method TEXT DEFAULT 'Cash'");
      }
      if (!mFinColNames.contains('spender_name')) {
        await db.execute("ALTER TABLE masjid_finance ADD COLUMN spender_name TEXT");
      }
    } catch (e) {
      debugPrint("Finance column verification error: $e");
    }

    // 1. Finance History (Madrasa)
    if (data['finance_history'] != null) {
      try {
        for (var item in data['finance_history']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final isDel = int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0;
          if (serverId > 0) {
            batch.delete('finance',
                where: 'server_id = ? AND id != ? AND (is_synced = 1 OR is_synced IS NULL)',
                whereArgs: [serverId, serverId]);
            final rawSys = item['system_type']?.toString().trim() ?? '';
            final sysType = (rawSys.isNotEmpty && rawSys.toLowerCase() == 'masjid') ? 'Masjid' : 'Madrasa';
            final rawType = item['type']?.toString().trim().toLowerCase() ?? '';
            final normType = (rawType == 'income' || rawType == 'آمدن') ? 'Income' : 'Expense';
            batch.insert(
              'finance',
              {
                'id': serverId,
                'server_id': serverId,
                'date': item['date']?.toString() ?? '',
                'type': normType,
                'amount': (item['amount'] != null)
                    ? double.tryParse(item['amount'].toString()) ?? 0.0
                    : 0.0,
                'description': item['description']?.toString() ?? '',
                'head_id': item['head_id'],
                'head_name': item['head_name']?.toString() ?? '',
                'manual_receipt_no':
                    item['manual_receipt_no']?.toString() ?? '',
                'donor_name': item['donor_name']?.toString() ?? '',
                'donor_address': item['donor_address']?.toString() ?? '',
                'spender_name': item['spender_name']?.toString() ?? '',
                'account_id': item['account_id'] != null ? int.tryParse(item['account_id'].toString()) : null,
                'payment_method': item['payment_method']?.toString() ?? 'Cash',
                'computer_receipt_no': item['computer_receipt_no'],
                'receipt_no': item['receipt_no']?.toString() ??
                    item['computer_receipt_no']?.toString(),
                'user_id':
                    int.tryParse(item['user_id']?.toString() ?? '1') ?? 1,
                'user_name': item['user_name']?.toString() ?? 'Admin',
                'system_type': sysType,
                'is_deleted': isDel,
                'is_synced': 1,
              },
              conflictAlgorithm: ConflictAlgorithm.replace,
            );
          }
        }
      } catch (e) {
        debugPrint("Sync Error (finance): $e");
      }
    }

    // 2. Masjid Finance History (Masjid)
    final masjidFinanceList = data['masjid_finance_history'] ?? data['masjid_finance_entries'];
    if (masjidFinanceList != null) {
      try {
        for (var item in masjidFinanceList) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final isDel = int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0;
          if (serverId > 0) {
            batch.delete('masjid_finance',
                where: 'server_id = ? AND id != ? AND (is_synced = 1 OR is_synced IS NULL)',
                whereArgs: [serverId, serverId]);
            final rawMType = item['type']?.toString().trim().toLowerCase() ?? '';
            final normMType = (rawMType == 'expense' || rawMType == 'خرچ') ? 'Expense' : 'Income';
            batch.insert(
              'masjid_finance',
              {
                'id': serverId,
                'server_id': serverId,
                'date': item['date']?.toString() ?? '',
                'type': normMType,
                'amount': (item['amount'] != null)
                    ? double.tryParse(item['amount'].toString()) ?? 0.0
                    : 0.0,
                'description': item['description']?.toString() ?? '',
                'head_id': item['head_id'],
                'head_name': item['head_name']?.toString() ?? '',
                'manual_receipt_no':
                    item['manual_receipt_no']?.toString() ?? '',
                'donor_name': item['donor_name']?.toString() ?? '',
                'donor_address': item['donor_address']?.toString() ?? '',
                'spender_name': item['spender_name']?.toString() ?? '',
                'account_id': item['account_id'] != null ? int.tryParse(item['account_id'].toString()) : null,
                'payment_method': item['payment_method']?.toString() ?? 'Cash',
                'computer_receipt_no': item['computer_receipt_no'],
                'receipt_no': item['receipt_no']?.toString() ??
                    (item['computer_receipt_no'] != null
                        ? 'M-${item['computer_receipt_no']}'
                        : null),
                'user_id':
                    int.tryParse(item['user_id']?.toString() ?? '1') ?? 1,
                'user_name': item['user_name']?.toString() ?? 'Admin',
                'system_type': 'Masjid',
                'is_deleted': isDel,
                'is_synced': 1,
              },
              conflictAlgorithm: ConflictAlgorithm.replace,
            );
          }
        }
      } catch (e) {
        debugPrint("Sync Error (masjid_finance): $e");
      }
    }

    // 2.1 Process Deleted Finance IDs from Server (Trash Synchronization)
    final deletedFinanceIds = data['deleted_finance_ids'];
    if (deletedFinanceIds != null && deletedFinanceIds is List) {
      try {
        for (var delId in deletedFinanceIds) {
          final idVal = int.tryParse(delId.toString());
          if (idVal != null && idVal > 0) {
            batch.update(
              'finance',
              {'is_deleted': 1, 'computer_receipt_no': null},
              where: 'id = ? OR server_id = ?',
              whereArgs: [idVal, idVal],
            );
            batch.update(
              'masjid_finance',
              {'is_deleted': 1, 'computer_receipt_no': null},
              where: 'id = ? OR server_id = ?',
              whereArgs: [idVal, idVal],
            );
          }
        }
      } catch (e) {
        debugPrint("Sync Error (deleted_finance_ids): $e");
      }
    }

    // 3. Heads
    if (data['heads'] != null) {
      try {
        for (var item in data['heads']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final isDel = int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0;
          batch.insert(
            'heads',
            {
              'id': serverId > 0 ? serverId : null,
              'name': item['name']?.toString() ?? '',
              'type': item['type']?.toString() ?? '',
              'is_deleted': isDel,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (heads): $e");
      }
    }

    // 4. Chart of Accounts
    if (data['acc_chart_of_accounts'] != null) {
      try {
        for (var item in data['acc_chart_of_accounts']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'acc_chart_of_accounts',
            {
              'id': serverId > 0 ? serverId : null,
              'code': item['code']?.toString() ?? '',
              'name': item['name']?.toString() ?? '',
              'type': item['type']?.toString() ?? '',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (acc_chart_of_accounts): $e");
      }
    }

    // 5. Accounting Vouchers
    if (data['acc_vouchers'] != null) {
      try {
        for (var item in data['acc_vouchers']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final voucherNo = item['voucher_no']?.toString() ?? '';
          if (voucherNo.isNotEmpty && serverId > 0) {
            batch.rawDelete(
              'DELETE FROM acc_ledger_entries WHERE voucher_id IN (SELECT id FROM acc_vouchers WHERE voucher_no = ? AND id != ?)',
              [voucherNo, serverId],
            );
            batch.delete(
              'acc_vouchers',
              where: 'voucher_no = ? AND id != ?',
              whereArgs: [voucherNo, serverId],
            );
          }
          batch.insert(
            'acc_vouchers',
            {
              'id': serverId > 0 ? serverId : null,
              'voucher_no': voucherNo,
              'date': item['date']?.toString() ?? '',
              'type': item['type']?.toString() ?? 'Journal',
              'reference': item['reference']?.toString() ?? '',
              'description': item['description']?.toString() ?? '',
              'department_id':
                  int.tryParse(item['department_id']?.toString() ?? ''),
              'status': item['status']?.toString() ?? 'Pending',
              'created_by': int.tryParse(item['created_by']?.toString() ?? ''),
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (acc_vouchers): $e");
      }
    }

    // 6. Ledger Entries
    if (data['acc_ledger_entries'] != null) {
      try {
        for (var item in data['acc_ledger_entries']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'acc_ledger_entries',
            {
              'id': serverId > 0 ? serverId : null,
              'voucher_id': int.tryParse(item['voucher_id']?.toString() ?? ''),
              'account_id': int.tryParse(item['account_id']?.toString() ?? ''),
              'debit': double.tryParse(item['debit']?.toString() ?? '0') ?? 0.0,
              'credit':
                  double.tryParse(item['credit']?.toString() ?? '0') ?? 0.0,
              'department_id':
                  int.tryParse(item['department_id']?.toString() ?? ''),
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (acc_ledger_entries): $e");
      }
    }

    // 7. Payroll
    if (data['acc_payroll'] != null) {
      try {
        for (var item in data['acc_payroll']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          final basic =
              double.tryParse(item['basic_salary']?.toString() ?? '0') ?? 0.0;
          final allowances =
              double.tryParse(item['allowances']?.toString() ?? '0') ?? 0.0;
          final deductions =
              double.tryParse(item['deductions']?.toString() ?? '0') ?? 0.0;
          final net = double.tryParse(item['net_salary']?.toString() ?? '') ??
              (basic + allowances - deductions);
          batch.insert(
            'acc_payroll',
            {
              'id': serverId > 0 ? serverId : null,
              'teacher_id': item['teacher_id'],
              'month_year': item['month_year']?.toString() ?? '',
              'basic_salary': basic,
              'allowances': allowances,
              'deductions': deductions,
              'net_salary': net,
              'status': item['status']?.toString() ?? 'Unpaid',
              'payment_date': item['payment_date']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (acc_payroll): $e");
      }
    }

    // 8. Invoices
    if (data['acc_invoices'] != null) {
      try {
        for (var item in data['acc_invoices']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'acc_invoices',
            {
              'id': serverId > 0 ? serverId : null,
              'student_id': item['student_id'],
              'invoice_no': item['invoice_no']?.toString() ?? '',
              'month_year': item['month_year']?.toString() ?? '',
              'amount':
                  double.tryParse(item['amount']?.toString() ?? '0') ?? 0.0,
              'status': item['status']?.toString() ?? 'Unpaid',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (acc_invoices): $e");
      }
    }

    // 9. In-Kind Donations
    if (data['in_kind_donations'] != null) {
      try {
        for (var item in data['in_kind_donations']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'in_kind_donations',
            {
              'id': serverId > 0 ? serverId : null,
              'date': item['date']?.toString() ?? '',
              'item_name': item['item_name']?.toString() ?? '',
              'quantity': int.tryParse(item['quantity']?.toString() ?? '1') ?? 1,
              'donor_name': item['donor_name']?.toString() ?? '',
              'donor_phone': item['donor_phone']?.toString() ?? '',
              'donor_address': item['donor_address']?.toString() ?? '',
              'description': item['description']?.toString() ?? '',
              'estimated_value': double.tryParse(item['estimated_value']?.toString() ?? '0') ?? 0.0,
              'computer_receipt_no': int.tryParse(item['computer_receipt_no']?.toString() ?? '0') ?? 0,
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (in_kind_donations): $e");
      }
    }

    // 10. Staff Loans
    if (data['staff_loans'] != null) {
      try {
        for (var item in data['staff_loans']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'staff_loans',
            {
              'id': serverId > 0 ? serverId : null,
              'teacher_id': item['teacher_id'],
              'borrower_name': item['borrower_name']?.toString() ?? '',
              'phone': item['phone']?.toString() ?? '',
              'loan_direction': item['loan_direction']?.toString() ?? 'Given',
              'loan_amount': double.tryParse(item['loan_amount']?.toString() ?? '0') ?? 0.0,
              'installment_amount': double.tryParse(item['installment_amount']?.toString() ?? '0') ?? 0.0,
              'repaid_amount': double.tryParse(item['repaid_amount']?.toString() ?? '0') ?? 0.0,
              'remaining_amount': double.tryParse(item['remaining_amount']?.toString() ?? '0') ?? 0.0,
              'date_issued': item['date_issued']?.toString() ?? '',
              'due_date': item['due_date']?.toString() ?? '',
              'description': item['description']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Active',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (staff_loans): $e");
      }
    }

    // 11. Loan Transactions
    if (data['loan_transactions'] != null) {
      try {
        for (var item in data['loan_transactions']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'loan_transactions',
            {
              'id': serverId > 0 ? serverId : null,
              'loan_id': item['loan_id'],
              'type': item['type']?.toString() ?? 'Recovery',
              'borrower_lender_name': item['borrower_lender_name']?.toString() ?? '',
              'amount': double.tryParse(item['amount']?.toString() ?? '0') ?? 0.0,
              'date': item['date']?.toString() ?? '',
              'payment_method': item['payment_method']?.toString() ?? 'Cash',
              'remarks': item['remarks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'created_by': int.tryParse(item['created_by']?.toString() ?? '1') ?? 1,
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (loan_transactions): $e");
      }
    }
  }
}
