import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:intl/intl.dart' as intl;
import '../db_helper.dart';
import '../widgets/app_drawer.dart';

class BalanceSheetPage extends StatefulWidget {
  const BalanceSheetPage({super.key});

  @override
  _BalanceSheetPageState createState() => _BalanceSheetPageState();
}

class _BalanceSheetPageState extends State<BalanceSheetPage> {
  final dbHelper = DBHelper();
  bool _isLoading = true;

  String _asOfDate = intl.DateFormat('yyyy-MM-dd').format(DateTime.now());
  final _asOfDateController = TextEditingController();

  List<Map<String, dynamic>> _assets = [];
  List<Map<String, dynamic>> _liabilities = [];
  List<Map<String, dynamic>> _equity = [];

  double _totalAssets = 0;
  double _totalLiabilities = 0;
  double _totalEquity = 0;
  double _retainedEarnings = 0;

  @override
  void initState() {
    super.initState();
    _asOfDateController.text = intl.DateFormat('dd-MMM-yyyy').format(DateTime.parse(_asOfDate));
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final db = await dbHelper.database;
      
      // Load accounts with balances up to asOfDate
      const query = '''
        SELECT a.id, a.code, a.name, a.type,
               SUM(CASE WHEN v.id IS NOT NULL THEN e.debit ELSE 0 END) as total_debit, 
               SUM(CASE WHEN v.id IS NOT NULL THEN e.credit ELSE 0 END) as total_credit
        FROM acc_chart_of_accounts a
        LEFT JOIN acc_ledger_entries e ON a.id = e.account_id
        LEFT JOIN acc_vouchers v ON e.voucher_id = v.id AND v.status = 'Approved' AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND v.date <= ?
        WHERE a.is_deleted = 0
        GROUP BY a.id, a.code, a.name, a.type
      ''';
      
      final accounts = await db.rawQuery(query, [_asOfDate]);

      _assets = [];
      _liabilities = [];
      _equity = [];
      _totalAssets = 0;
      _totalLiabilities = 0;
      _totalEquity = 0;
      _retainedEarnings = 0;

      for (var acc in accounts) {
        double dr = double.tryParse(acc['total_debit']?.toString() ?? '0') ?? 0;
        double cr = double.tryParse(acc['total_credit']?.toString() ?? '0') ?? 0;
        
        String type = acc['type']?.toString() ?? '';
        
        if (type == 'Revenue') {
          _retainedEarnings += (cr - dr);
        } else if (type == 'Expense') {
          _retainedEarnings -= (dr - cr);
        }
        
        if (type == 'Asset') {
          double bal = dr - cr;
          if (bal != 0) {
            _assets.add({...acc, 'balance': bal});
            _totalAssets += bal;
          }
        } else if (type == 'Liability') {
          double bal = cr - dr;
          if (bal != 0) {
            _liabilities.add({...acc, 'balance': bal});
            _totalLiabilities += bal;
          }
        } else if (type == 'Equity') {
          double bal = cr - dr;
          if (bal != 0) {
            _equity.add({...acc, 'balance': bal});
            _totalEquity += bal;
          }
        }
      }

      if (_retainedEarnings != 0) {
        _equity.add({
          'code': '3999', 
          'name': 'Retained Earnings (خالص منافع/نقصان)', 
          'balance': _retainedEarnings
        });
        _totalEquity += _retainedEarnings;
      }

      _assets.sort((a, b) => (a['code']?.toString() ?? '').compareTo(b['code']?.toString() ?? ''));
      _liabilities.sort((a, b) => (a['code']?.toString() ?? '').compareTo(b['code']?.toString() ?? ''));
      _equity.sort((a, b) => (a['code']?.toString() ?? '').compareTo(b['code']?.toString() ?? ''));

    } catch (e) {
      debugPrint("Error loading Balance Sheet: \$e");
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _selectDate(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime.parse(_asOfDate),
      firstDate: DateTime(2000),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked != null) {
      setState(() {
        _asOfDate = intl.DateFormat('yyyy-MM-dd').format(picked);
        _asOfDateController.text = intl.DateFormat('dd-MMM-yyyy').format(picked);
      });
      _loadData();
    }
  }

  Future<void> _printPdf() async {
    try {
      final fontData = await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final fallbackData = await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
      final ttf = pw.Font.ttf(fontData);
      final fallbackTtf = pw.Font.ttf(fallbackData);
      final bold = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf], fontWeight: pw.FontWeight.bold);
      final normal = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf]);

      final pdf = pw.Document();

      final totalLiabEq = _totalLiabilities + _totalEquity;

      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat.a4,
          textDirection: pw.TextDirection.rtl,
          theme: pw.ThemeData.withFont(
            base: ttf,
            fontFallback: [fallbackTtf],
          ),
          build: (context) {
            return pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Center(child: pw.Text('مدرسہ اشرف المدارس', style: bold.copyWith(fontSize: 24))),
                pw.Center(child: pw.Text('بیلنس شیٹ (Balance Sheet)', style: bold.copyWith(fontSize: 18))),
                pw.Center(child: pw.Text('As of: \${_asOfDateController.text}', style: normal.copyWith(fontSize: 12))),
                pw.SizedBox(height: 20),
                
                pw.Row(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    // Liabilities & Equity
                    pw.Expanded(
                      child: pw.Column(
                        crossAxisAlignment: pw.CrossAxisAlignment.start,
                        children: [
                          pw.Text('واجبات (Liabilities)', style: bold.copyWith(fontSize: 16)),
                          pw.Divider(),
                          ..._liabilities.map((l) => pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text("\${l['code']} - \${l['name']}", style: normal),
                              pw.Text(intl.NumberFormat('#,##0.00').format(l['balance']), style: normal),
                            ]
                          )),
                          if (_liabilities.isEmpty) pw.Text('کوئی واجبات نہیں (No liabilities)', style: normal),
                          pw.Divider(),
                          pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text('کل واجبات:', style: bold),
                              pw.Text(intl.NumberFormat('#,##0.00').format(_totalLiabilities), style: bold),
                            ]
                          ),
                          pw.SizedBox(height: 20),
                          pw.Text('سرمایہ (Equity)', style: bold.copyWith(fontSize: 16)),
                          pw.Divider(),
                          ..._equity.map((e) => pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text("\${e['code']} - \${e['name']}", style: normal),
                              pw.Text(intl.NumberFormat('#,##0.00').format(e['balance']), style: normal),
                            ]
                          )),
                          if (_equity.isEmpty) pw.Text('کوئی سرمایہ نہیں (No equity)', style: normal),
                          pw.Divider(),
                          pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text('کل سرمایہ:', style: bold),
                              pw.Text(intl.NumberFormat('#,##0.00').format(_totalEquity), style: bold),
                            ]
                          ),
                          pw.SizedBox(height: 20),
                          pw.Divider(thickness: 2),
                          pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text('کل واجبات و سرمایہ:', style: bold.copyWith(fontSize: 14)),
                              pw.Text(intl.NumberFormat('#,##0.00').format(totalLiabEq), style: bold.copyWith(fontSize: 14)),
                            ]
                          ),
                        ]
                      ),
                    ),
                    pw.SizedBox(width: 20),
                    // Assets
                    pw.Expanded(
                      child: pw.Column(
                        crossAxisAlignment: pw.CrossAxisAlignment.start,
                        children: [
                          pw.Text('اثاثہ جات (Assets)', style: bold.copyWith(fontSize: 16)),
                          pw.Divider(),
                          ..._assets.map((a) => pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text("\${a['code']} - \${a['name']}", style: normal),
                              pw.Text(intl.NumberFormat('#,##0.00').format(a['balance']), style: normal),
                            ]
                          )),
                          if (_assets.isEmpty) pw.Text('کوئی اثاثہ نہیں (No assets)', style: normal),
                          pw.Divider(thickness: 2),
                          pw.Row(
                            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                            children: [
                              pw.Text('کل اثاثہ جات:', style: bold.copyWith(fontSize: 14)),
                              pw.Text(intl.NumberFormat('#,##0.00').format(_totalAssets), style: bold.copyWith(fontSize: 14)),
                            ]
                          ),
                        ]
                      )
                    ),
                  ],
                ),
              ],
            );
          },
        ),
      );

      await Printing.layoutPdf(onLayout: (_) async => pdf.save());
    } catch (e) {
      debugPrint("Print error: \$e");
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Print failed: \$e')));
      }
    }
  }

  Widget _buildSectionHeader(String title, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        border: Border(left: BorderSide(color: color, width: 4)),
      ),
      child: Row(
        children: [
          Icon(icon, color: color, size: 20),
          const SizedBox(width: 8),
          Text(title, style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: color, fontFamily: 'Jameel Noori Nastaleeq')),
        ],
      ),
    );
  }

  Widget _buildAccountRow(Map<String, dynamic> acc, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Row(
              children: [
                Text(acc['code']?.toString() ?? '', style: const TextStyle(color: Colors.grey, fontSize: 13)),
                const SizedBox(width: 8),
                Expanded(child: Text(acc['name']?.toString() ?? '', style: const TextStyle(fontSize: 15, fontFamily: 'Jameel Noori Nastaleeq'))),
              ],
            ),
          ),
          Text(intl.NumberFormat('#,##0.00').format(acc['balance'] ?? 0), style: TextStyle(fontWeight: FontWeight.bold, color: color, fontSize: 15)),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final totalLiabEq = _totalLiabilities + _totalEquity;
    final isBalanced = (_totalAssets - totalLiabEq).abs() < 0.01;

    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      appBar: AppBar(
        title: const Text('بیلنس شیٹ (Balance Sheet)', style: TextStyle(fontFamily: 'Jameel Noori Nastaleeq')),
        backgroundColor: const Color(0xFF284D3D),
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.print),
            onPressed: _printPdf,
          ),
        ],
      ),
      drawer: const AppDrawer(),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Filter Card
                Card(
                  margin: const EdgeInsets.all(12),
                  child: Padding(
                    padding: const EdgeInsets.all(12.0),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _asOfDateController,
                            readOnly: true,
                            decoration: const InputDecoration(
                              labelText: 'کس تاریخ تک (As of Date)',
                              border: OutlineInputBorder(),
                              prefixIcon: Icon(Icons.calendar_today),
                              contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                              labelStyle: TextStyle(fontFamily: 'Jameel Noori Nastaleeq'),
                            ),
                            onTap: () => _selectDate(context),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                // Balance Sheet Content
                Expanded(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    child: Column(
                      children: [
                        // ASSETS
                        Card(
                          elevation: 2,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _buildSectionHeader('اثاثہ جات (Assets)', Colors.green.shade700, Icons.money),
                              const Divider(height: 1),
                              ..._assets.map((a) => _buildAccountRow(a, Colors.green.shade700)),
                              if (_assets.isEmpty)
                                const Padding(
                                  padding: EdgeInsets.all(16.0),
                                  child: Center(child: Text('کوئی اثاثہ نہیں (No assets)', style: TextStyle(color: Colors.grey))),
                                ),
                              const Divider(thickness: 2),
                              Padding(
                                padding: const EdgeInsets.all(12.0),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    const Text('کل اثاثہ جات:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, fontFamily: 'Jameel Noori Nastaleeq')),
                                    Text(intl.NumberFormat('#,##0.00').format(_totalAssets), style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.green.shade700)),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 16),

                        // LIABILITIES
                        Card(
                          elevation: 2,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _buildSectionHeader('واجبات (Liabilities)', Colors.red.shade700, Icons.handshake),
                              const Divider(height: 1),
                              ..._liabilities.map((l) => _buildAccountRow(l, Colors.red.shade700)),
                              if (_liabilities.isEmpty)
                                const Padding(
                                  padding: EdgeInsets.all(16.0),
                                  child: Center(child: Text('کوئی واجبات نہیں (No liabilities)', style: TextStyle(color: Colors.grey))),
                                ),
                              const Divider(thickness: 1),
                              Padding(
                                padding: const EdgeInsets.all(12.0),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    const Text('کل واجبات:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, fontFamily: 'Jameel Noori Nastaleeq')),
                                    Text(intl.NumberFormat('#,##0.00').format(_totalLiabilities), style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.red.shade700)),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 16),

                        // EQUITY
                        Card(
                          elevation: 2,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _buildSectionHeader('سرمایہ (Equity)', Colors.blue.shade700, Icons.show_chart),
                              const Divider(height: 1),
                              ..._equity.map((e) => _buildAccountRow(e, Colors.blue.shade700)),
                              if (_equity.isEmpty)
                                const Padding(
                                  padding: EdgeInsets.all(16.0),
                                  child: Center(child: Text('کوئی سرمایہ نہیں (No equity)', style: TextStyle(color: Colors.grey))),
                                ),
                              const Divider(thickness: 1),
                              Padding(
                                padding: const EdgeInsets.all(12.0),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    const Text('کل سرمایہ:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, fontFamily: 'Jameel Noori Nastaleeq')),
                                    Text(intl.NumberFormat('#,##0.00').format(_totalEquity), style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.blue.shade700)),
                                  ],
                                ),
                              ),
                              const Divider(thickness: 2),
                              Padding(
                                padding: const EdgeInsets.all(12.0),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    const Text('کل واجبات و سرمایہ:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, fontFamily: 'Jameel Noori Nastaleeq')),
                                    Text(
                                      intl.NumberFormat('#,##0.00').format(totalLiabEq), 
                                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: isBalanced ? Colors.green.shade700 : Colors.red.shade700)
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                        
                        const SizedBox(height: 20),
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: isBalanced ? Colors.green.shade100 : Colors.red.shade100,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: isBalanced ? Colors.green : Colors.red),
                          ),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(isBalanced ? Icons.check_circle : Icons.warning, color: isBalanced ? Colors.green : Colors.red),
                              const SizedBox(width: 8),
                              Text(
                                isBalanced 
                                  ? 'بیلنس شیٹ درست ہے! (Balanced)' 
                                  : 'بیلنس شیٹ برابر نہیں ہے! (Out of balance by ${intl.NumberFormat('#,##0.00').format((_totalAssets - totalLiabEq).abs())})',
                                style: TextStyle(fontWeight: FontWeight.bold, color: isBalanced ? Colors.green.shade800 : Colors.red.shade800, fontFamily: 'Jameel Noori Nastaleeq', fontSize: 16),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 30),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}
