import 'package:flutter_test/flutter_test.dart';

void main() {
  group('Finance Calculations and Quotas', () {
    test('calculates net total correctly from entries', () {
      final entries = [
        {'type': 'Income', 'amount': 5000.0},
        {'type': 'Income', 'amount': 1500.0},
        {'type': 'Expense', 'amount': 2000.0},
        {'type': 'Expense', 'amount': 500.0},
      ];

      double income = 0;
      double expense = 0;

      for (var e in entries) {
        final t = (e['type'] as String).toLowerCase();
        final amt = e['amount'] as double;
        if (t == 'income') {
          income += amt;
        } else if (t == 'expense') {
          expense += amt;
        }
      }

      expect(income, 6500.0);
      expect(expense, 2500.0);
      expect(income - expense, 4000.0);
    });

    test('receipt quota per user boundary validation', () {
      int calculateNextReceipt(int userId, int? currentMax) {
        final minNo = userId * 100000;
        final maxNo = (userId + 1) * 100000;
        final next = (currentMax != null && currentMax > minNo)
            ? currentMax + 1
            : minNo + 1;
        if (next >= maxNo) {
          throw Exception('Receipt quota exhausted');
        }
        return next;
      }

      expect(calculateNextReceipt(1, null), 100001);
      expect(calculateNextReceipt(1, 100005), 100006);
      expect(calculateNextReceipt(2, 200050), 200051);
      expect(() => calculateNextReceipt(1, 199999), throwsException);
    });

    test('advance transfer, expense deduction, and positive/negative balance calculation', () {
      double calculateAdvanceBalance({
        required double totalTransferredIn,
        required List<Map<String, dynamic>> expenses,
        required int advanceAccountId,
      }) {
        double deductedFromAdvance = 0.0;
        for (var exp in expenses) {
          if (exp['account_id'] == advanceAccountId) {
            deductedFromAdvance += (exp['amount'] as num).toDouble();
          }
        }
        return totalTransferredIn - deductedFromAdvance;
      }

      const advAccountId = 1501;
      const transferredIn = 10000.0;
      final expenses = [
        {'account_id': advAccountId, 'amount': 2500.0}, // Paid from advance
        {'account_id': advAccountId, 'amount': 1500.0}, // Paid from advance
        {'account_id': 1001, 'amount': 5000.0},         // Paid from general cash (not advance)
      ];

      // Total deducted from advance must be 4000, leaving +6000
      final bal1 = calculateAdvanceBalance(
        totalTransferredIn: transferredIn,
        expenses: expenses,
        advanceAccountId: advAccountId,
      );
      expect(bal1, 6000.0);

      // Overspending test: User spends 7000 more from advance
      expenses.add({'account_id': advAccountId, 'amount': 7000.0});
      final bal2 = calculateAdvanceBalance(
        totalTransferredIn: transferredIn,
        expenses: expenses,
        advanceAccountId: advAccountId,
      );
      // 10000 - 11000 = -1000 (negative balance: institution owes user)
      expect(bal2, -1000.0);
    });
  });
}
