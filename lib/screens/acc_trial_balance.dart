import 'package:flutter/material.dart';
import '../utils/sync_service.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class AccTrialBalancePage extends StatefulWidget {
  const AccTrialBalancePage({super.key});

  @override
  State<AccTrialBalancePage> createState() => _AccTrialBalancePageState();
}

class _AccTrialBalancePageState extends State<AccTrialBalancePage> {
  DateTime _asOf = DateTime.now();
  List<Map<String, dynamic>> _rows = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String _date(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    final db = await DBHelper().database;
    final asOfText = _date(_asOf);
    const query = '''
      SELECT a.id, a.code, a.name, a.type,
             SUM(e.debit) as total_debit,
             SUM(e.credit) as total_credit
      FROM acc_chart_of_accounts a
      JOIN acc_ledger_entries e ON a.id = e.account_id
      JOIN acc_vouchers v ON e.voucher_id = v.id
      WHERE v.status IN ('Approved', 'Reversed')
        AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
        AND v.date <= ?
      GROUP BY a.id, a.code, a.name, a.type
      ORDER BY a.code ASC
    ''';
    final accounts = await db.rawQuery(query, [asOfText]);
    final grouped = <String, Map<String, dynamic>>{};
    for (final acc in accounts) {
      final code = acc['code']?.toString() ?? '';
      final name = acc['name']?.toString() ?? '';
      final title = '$code - $name';
      final dr = double.tryParse(acc['total_debit']?.toString() ?? '0') ?? 0.0;
      final cr = double.tryParse(acc['total_credit']?.toString() ?? '0') ?? 0.0;
      if (dr > 0 || cr > 0) {
        grouped[title] = {
          'name': title,
          'debit': dr,
          'credit': cr,
        };
      }
    }
    setState(() {
      _rows = grouped.values.toList()
        ..sort((a, b) => a['name'].toString().compareTo(b['name'].toString()));
      _loading = false;
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _asOf,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      _asOf = picked;
      _loading = true;
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final totalDebit = _rows.fold<double>(
        0, (sum, row) => sum + ((row['debit'] as double?) ?? 0));
    final totalCredit = _rows.fold<double>(
        0, (sum, row) => sum + ((row['credit'] as double?) ?? 0));

    return Scaffold(
      appBar: AppBar(
          actions: [
            IconButton(
              icon: const Icon(Icons.sync, color: Colors.white),
              onPressed: () async {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Syncing data...')));
                try {
                  await SyncService.runTwoWaySync();
                  if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Sync complete')));
                } catch (e) {
                  if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Sync failed: ')));
                }
              },
            )
          ],
        title: Text(isUrdu ? 'میزان' : 'Trial Balance'),
        backgroundColor: const Color(0xFF284D3D),
        foregroundColor: Colors.white,
      ),
      drawer: const AppDrawer(),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.calendar_today),
                    title: Text(isUrdu ? 'کس تاریخ تک' : 'As of Date'),
                    subtitle: Text(_date(_asOf)),
                    trailing: IconButton(
                      icon: const Icon(Icons.edit_calendar),
                      onPressed: _pickDate,
                    ),
                  ),
                ),
                Card(
                  child: ListTile(
                    title: Text(isUrdu ? 'کل ڈیبٹ' : 'Total Debit'),
                    trailing: Text('Rs ${totalDebit.toStringAsFixed(0)}'),
                  ),
                ),
                Card(
                  child: ListTile(
                    title: Text(isUrdu ? 'کل کریڈٹ' : 'Total Credit'),
                    trailing: Text('Rs ${totalCredit.toStringAsFixed(0)}'),
                  ),
                ),
                const SizedBox(height: 8),
                ..._rows.map((row) => Card(
                      child: ListTile(
                        title: Text(row['name'].toString()),
                        subtitle: Text(
                            'Dr: ${((row['debit'] as double?) ?? 0).toStringAsFixed(0)} | Cr: ${((row['credit'] as double?) ?? 0).toStringAsFixed(0)}'),
                      ),
                    )),
              ],
            ),
    );
  }
}