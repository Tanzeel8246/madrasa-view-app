import 'package:flutter/material.dart';
import '../utils/sync_service.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class AccIncomeStatementPage extends StatefulWidget {
  const AccIncomeStatementPage({super.key});

  @override
  State<AccIncomeStatementPage> createState() => _AccIncomeStatementPageState();
}

class _AccIncomeStatementPageState extends State<AccIncomeStatementPage> {
  late DateTime _from;
  late DateTime _to;
  double _revenue = 0;
  double _expense = 0;
  List<Map<String, dynamic>> _revenues = [];
  List<Map<String, dynamic>> _expenses = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    // Default to full current year 2026 to immediately match canonical annual target
    final now = DateTime.now();
    _from = DateTime(now.year, 1, 1);
    _to = DateTime(now.year, 12, 31);
    _load();
  }

  String _date(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

  String _formatCurrency(double val) {
    return val.toStringAsFixed(2).replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]},',
    );
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final fromText = _date(_from);
    final toText = _date(_to);

    final res = await DBHelper().getIncomeStatement(
      fromDate: fromText,
      toDate: toText,
    );

    if (!mounted) return;
    setState(() {
      _revenue = (res['total_revenue'] as num?)?.toDouble() ?? 0.0;
      _expense = (res['total_expense'] as num?)?.toDouble() ?? 0.0;
      _revenues = ((res['revenues'] as List?) ?? []).cast<Map<String, dynamic>>();
      _expenses = ((res['expenses'] as List?) ?? []).cast<Map<String, dynamic>>();
      _loading = false;
    });
  }

  Future<void> _pickRange() async {
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
      initialDateRange: DateTimeRange(start: _from, end: _to),
    );
    if (picked == null) return;
    setState(() {
      _from = picked.start;
      _to = picked.end;
      _loading = true;
    });
    await _load();
  }

  void _setPreset(String preset) {
    final now = DateTime.now();
    setState(() {
      if (preset == 'month') {
        _from = DateTime(now.year, now.month, 1);
        _to = DateTime(now.year, now.month + 1, 0);
      } else if (preset == '2026') {
        final now = DateTime.now();
    _from = DateTime(now.year, 1, 1);
        _to = DateTime(now.year, 12, 31);
      } else if (preset == 'all') {
        _from = DateTime(2000, 1, 1);
        _to = DateTime(2099, 12, 31);
      }
      _loading = true;
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final net = _revenue - _expense;

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
        title: Text(isUrdu ? 'آمدن و اخراجات (Income Statement)' : 'Income Statement'),
        backgroundColor: const Color(0xFF284D3D),
        foregroundColor: Colors.white,
      ),
      drawer: const AppDrawer(),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Date Selector Card
                Card(
                  elevation: 2,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      children: [
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.date_range, color: Color(0xFF284D3D)),
                          title: Text(isUrdu ? 'مدت منتخب کریں' : 'Period',
                              style: const TextStyle(fontWeight: FontWeight.bold)),
                          subtitle: Text('${_date(_from)}  تا  ${_date(_to)}',
                              style: const TextStyle(fontSize: 13)),
                          trailing: IconButton(
                            icon: const Icon(Icons.edit_calendar, color: Color(0xFF284D3D)),
                            onPressed: _pickRange,
                          ),
                        ),
                        const Divider(height: 12),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                          children: [
                            OutlinedButton(
                              onPressed: () => _setPreset('month'),
                              style: OutlinedButton.styleFrom(
                                visualDensity: VisualDensity.compact,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                              ),
                              child: Text(isUrdu ? 'رواں ماہ' : 'This Month'),
                            ),
                            OutlinedButton(
                              onPressed: () => _setPreset('year'),
                              style: OutlinedButton.styleFrom(
                                visualDensity: VisualDensity.compact,
                                backgroundColor: const Color(0xFF284D3D).withOpacity(0.08),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                              ),
                              child: Text(isUrdu ? 'سال ${DateTime.now().year}' : 'Year ${DateTime.now().year}',
                                  style: const TextStyle(fontWeight: FontWeight.bold)),
                            ),
                            OutlinedButton(
                              onPressed: () => _setPreset('all'),
                              style: OutlinedButton.styleFrom(
                                visualDensity: VisualDensity.compact,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                              ),
                              child: Text(isUrdu ? 'کل مدت' : 'All Time'),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),

                // Financial Summary Cards
                Row(
                  children: [
                    Expanded(
                      child: Card(
                        elevation: 2,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(isUrdu ? 'کل آمدنی' : 'Total Revenue',
                                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
                              const SizedBox(height: 6),
                              Text('Rs ${_formatCurrency(_revenue)}',
                                  style: const TextStyle(
                                      color: Color(0xFF1B5E20),
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16)),
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Card(
                        elevation: 2,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(isUrdu ? 'کل اخراجات' : 'Total Expenses',
                                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
                              const SizedBox(height: 6),
                              Text('Rs ${_formatCurrency(_expense)}',
                                  style: const TextStyle(
                                      color: Color(0xFFB71C1C),
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),

                // Net Surplus / Deficit Banner
                Card(
                  elevation: 2,
                  color: net >= 0 ? const Color(0xFFE8F5E9) : const Color(0xFFFFEBEE),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(
                        color: net >= 0 ? const Color(0xFF2E7D32) : const Color(0xFFC62828),
                        width: 1.5),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Icon(
                              net >= 0 ? Icons.trending_up : Icons.trending_down,
                              color: net >= 0 ? const Color(0xFF2E7D32) : const Color(0xFFC62828),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              net >= 0
                                  ? (isUrdu ? 'خالص فاضل (بچت)' : 'Net Surplus')
                                  : (isUrdu ? 'خالص خسارہ (نقصان)' : 'Net Deficit'),
                              style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 15,
                                  color: net >= 0
                                      ? const Color(0xFF2E7D32)
                                      : const Color(0xFFC62828)),
                            ),
                          ],
                        ),
                        Text(
                          'Rs ${_formatCurrency(net.abs())}',
                          style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 18,
                              color: net >= 0
                                  ? const Color(0xFF2E7D32)
                                  : const Color(0xFFC62828)),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 16),

                // Revenue Breakdown Section
                Text(
                  isUrdu ? 'آمدنی کھاتہ جات (Revenue Accounts)' : 'Revenue Breakdown',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                if (_revenues.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(isUrdu ? 'اس مدت میں کوئی آمدنی نہیں' : 'No revenue records',
                        style: TextStyle(color: Colors.grey.shade600)),
                  ),
                ..._revenues.map((row) {
                  final code = row['code']?.toString() ?? '';
                  final name = row['name']?.toString() ?? '';
                  final bal = (row['balance'] as num?)?.toDouble() ?? 0.0;
                  return Card(
                    elevation: 1,
                    margin: const EdgeInsets.symmetric(vertical: 3),
                    child: ListTile(
                      dense: true,
                      leading: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: const Color(0xFF1B5E20).withOpacity(0.1),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(code,
                            style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF1B5E20))),
                      ),
                      title: Text(name, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                      trailing: Text('Rs ${_formatCurrency(bal)}',
                          style: const TextStyle(
                              color: Color(0xFF1B5E20),
                              fontWeight: FontWeight.bold,
                              fontSize: 13)),
                    ),
                  );
                }),
                const SizedBox(height: 16),

                // Expense Breakdown Section
                Text(
                  isUrdu ? 'اخراجات کھاتہ جات (Expense Accounts)' : 'Expense Breakdown',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                if (_expenses.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(isUrdu ? 'اس مدت میں کوئی اخراجات نہیں' : 'No expense records',
                        style: TextStyle(color: Colors.grey.shade600)),
                  ),
                ..._expenses.map((row) {
                  final code = row['code']?.toString() ?? '';
                  final name = row['name']?.toString() ?? '';
                  final bal = (row['balance'] as num?)?.toDouble() ?? 0.0;
                  return Card(
                    elevation: 1,
                    margin: const EdgeInsets.symmetric(vertical: 3),
                    child: ListTile(
                      dense: true,
                      leading: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: const Color(0xFFB71C1C).withOpacity(0.1),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(code,
                            style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFFB71C1C))),
                      ),
                      title: Text(name, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                      trailing: Text('Rs ${_formatCurrency(bal)}',
                          style: const TextStyle(
                              color: Color(0xFFB71C1C),
                              fontWeight: FontWeight.bold,
                              fontSize: 13)),
                    ),
                  );
                }),
              ],
            ),
    );
  }
}
