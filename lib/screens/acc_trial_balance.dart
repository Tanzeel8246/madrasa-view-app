import 'package:flutter/material.dart';
import '../utils/sync_service.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/system_type_global.dart';

class AccTrialBalancePage extends StatefulWidget {
  const AccTrialBalancePage({super.key});

  @override
  State<AccTrialBalancePage> createState() => _AccTrialBalancePageState();
}

class _AccTrialBalancePageState extends State<AccTrialBalancePage> {
  DateTime _asOf = DateTime.now();
  List<Map<String, dynamic>> _rows = [];
  double _grossDebit = 0.0;
  double _grossCredit = 0.0;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String _date(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    setState(() => _loading = true);
    final asOfText = _date(_asOf);
    final rows = await DBHelper().getTrialBalance(asOfText);

    final db = await DBHelper().database;
    final sys = SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "v.system_type = 'Masjid'"
        : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
    final grossRows = await db.rawQuery('''
      SELECT COALESCE(SUM(e.debit), 0) AS total_dr,
             COALESCE(SUM(e.credit), 0) AS total_cr
      FROM acc_ledger_entries e
      JOIN acc_vouchers v ON e.voucher_id = v.id
      WHERE v.status IN ('Approved', 'Reversed')
        AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        AND $sysCond
        AND v.date <= ?
    ''', [asOfText]);

    final gDr = double.tryParse(grossRows.first['total_dr']?.toString() ?? '0') ?? 0.0;
    final gCr = double.tryParse(grossRows.first['total_cr']?.toString() ?? '0') ?? 0.0;

    if (!mounted) return;
    setState(() {
      _rows = rows.map((r) => {
        'code': r['code']?.toString() ?? '',
        'name': r['name']?.toString() ?? '',
        'type': r['type']?.toString() ?? '',
        'debit': (r['debit_balance'] as num?)?.toDouble() ?? 0.0,
        'credit': (r['credit_balance'] as num?)?.toDouble() ?? 0.0,
      }).toList();
      _grossDebit = gDr;
      _grossCredit = gCr;
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

  Color _getTypeColor(String type) {
    switch (type.toLowerCase()) {
      case 'asset':
        return Colors.blue.shade700;
      case 'liability':
        return Colors.orange.shade800;
      case 'equity':
        return Colors.purple.shade700;
      case 'revenue':
      case 'income':
        return const Color(0xFF1B5E20);
      case 'expense':
        return const Color(0xFFB71C1C);
      default:
        return Colors.grey.shade700;
    }
  }

  String _formatCurrency(double val) {
    return val.toStringAsFixed(2).replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]},',
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final totalNetDebit = _rows.fold<double>(
        0, (sum, row) => sum + ((row['debit'] as double?) ?? 0));
    final totalNetCredit = _rows.fold<double>(
        0, (sum, row) => sum + ((row['credit'] as double?) ?? 0));
    final diff = (totalNetDebit - totalNetCredit).abs();

    return Scaffold(
      appBar: AppBar(
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            onPressed: () async {
              ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Syncing data...')));
              try {
                await SyncService.runTwoWaySync();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Sync complete')));
                  await _load();
                }
              } catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Sync failed')));
                }
              }
            },
          )
        ],
        title: Text(isUrdu ? 'میزانِ پڑتال (Trial Balance)' : 'Trial Balance'),
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
                  elevation: 2,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                  child: ListTile(
                    leading: const Icon(Icons.calendar_today,
                        color: Color(0xFF284D3D)),
                    title: Text(
                      isUrdu ? 'بتاریخ (As of Date)' : 'As of Date',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                    subtitle: Text(_date(_asOf)),
                    trailing: IconButton(
                      icon: const Icon(Icons.edit_calendar,
                          color: Color(0xFF284D3D)),
                      onPressed: _pickDate,
                    ),
                  ),
                ),
                const SizedBox(height: 8),

                // Authoritative General Ledger Anchor Card
                Card(
                  elevation: 2,
                  color: const Color(0xFFF1F8F5),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                      side: const BorderSide(color: Color(0xFF284D3D), width: 1.5)),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        Text(
                          isUrdu
                              ? 'جنرل لیجر اینکر میزان (General Ledger Anchor)'
                              : 'General Ledger Anchor',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                              color: Color(0xFF284D3D)),
                        ),
                        const SizedBox(height: 8),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: [
                            Column(
                              children: [
                                Text(
                                  isUrdu ? 'کل ڈیبٹ (Dr)' : 'Total Debit',
                                  style: TextStyle(
                                      color: Colors.grey.shade700, fontSize: 13),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Rs ${_formatCurrency(_grossDebit)}',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                      color: Color(0xFF1B5E20)),
                                ),
                              ],
                            ),
                            Container(
                                height: 35,
                                width: 1,
                                color: Colors.grey.shade400),
                            Column(
                              children: [
                                Text(
                                  isUrdu ? 'کل کریڈٹ (Cr)' : 'Total Credit',
                                  style: TextStyle(
                                      color: Colors.grey.shade700, fontSize: 13),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Rs ${_formatCurrency(_grossCredit)}',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                      color: Color(0xFFB71C1C)),
                                ),
                              ],
                            ),
                          ],
                        ),
                        const Divider(height: 20),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              (_grossDebit - _grossCredit).abs() < 0.01
                                  ? Icons.check_circle
                                  : Icons.error,
                              color: (_grossDebit - _grossCredit).abs() < 0.01
                                  ? Colors.green
                                  : Colors.red,
                              size: 18,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              (_grossDebit - _grossCredit).abs() < 0.01
                                  ? (isUrdu
                                      ? 'اینکر 100% متوازن ہے (صفر فرق)'
                                      : 'Anchor 100% Balanced (Zero Difference)')
                                  : (isUrdu
                                      ? 'فرق: Rs ${_formatCurrency((_grossDebit - _grossCredit).abs())}'
                                      : 'Diff: Rs ${_formatCurrency((_grossDebit - _grossCredit).abs())}'),
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: (_grossDebit - _grossCredit).abs() < 0.01
                                    ? Colors.green.shade800
                                    : Colors.red.shade800,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 8),

                // Net Trial Balance Summary
                Card(
                  elevation: 2,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        Column(
                          children: [
                            Text(isUrdu ? 'نیٹ ڈیبٹ بیلنس' : 'Net Debit',
                                style: const TextStyle(fontSize: 12, color: Colors.grey)),
                            const SizedBox(height: 2),
                            Text('Rs ${_formatCurrency(totalNetDebit)}',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                    color: Color(0xFF1B5E20))),
                          ],
                        ),
                        Column(
                          children: [
                            Text(isUrdu ? 'نیٹ کریڈٹ بیلنس' : 'Net Credit',
                                style: const TextStyle(fontSize: 12, color: Colors.grey)),
                            const SizedBox(height: 2),
                            Text('Rs ${_formatCurrency(totalNetCredit)}',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                    color: Color(0xFFB71C1C))),
                          ],
                        ),
                        Column(
                          children: [
                            Text(isUrdu ? 'فرق' : 'Difference',
                                style: const TextStyle(fontSize: 12, color: Colors.grey)),
                            const SizedBox(height: 2),
                            Text(diff < 0.01 ? '0.00' : _formatCurrency(diff),
                                style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                    color: diff < 0.01
                                        ? Colors.green
                                        : Colors.red)),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),

                // Account rows
                Text(
                  isUrdu ? 'کھاتہ وار میزان فہرست' : 'Accounts List',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 16),
                ),
                const SizedBox(height: 6),
                ..._rows.map((row) {
                  final code = row['code']?.toString() ?? '';
                  final name = row['name']?.toString() ?? '';
                  final type = row['type']?.toString() ?? '';
                  final dr = (row['debit'] as double?) ?? 0.0;
                  final cr = (row['credit'] as double?) ?? 0.0;

                  return Card(
                    elevation: 1,
                    margin: const EdgeInsets.symmetric(vertical: 4),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 10),
                      child: Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: _getTypeColor(type).withOpacity(0.12),
                              borderRadius: BorderRadius.circular(4),
                              border: Border.all(
                                  color: _getTypeColor(type).withOpacity(0.5)),
                            ),
                            child: Text(
                              code.isNotEmpty ? code : '-',
                              style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                  color: _getTypeColor(type)),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  name,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w600,
                                      fontSize: 14),
                                ),
                                Text(
                                  type,
                                  style: TextStyle(
                                      fontSize: 11,
                                      color: _getTypeColor(type)),
                                ),
                              ],
                            ),
                          ),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              if (dr > 0)
                                Text(
                                  'Dr: ${_formatCurrency(dr)}',
                                  style: const TextStyle(
                                      color: Color(0xFF1B5E20),
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13),
                                ),
                              if (cr > 0)
                                Text(
                                  'Cr: ${_formatCurrency(cr)}',
                                  style: const TextStyle(
                                      color: Color(0xFFB71C1C),
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13),
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              ],
            ),
    );
  }
}
