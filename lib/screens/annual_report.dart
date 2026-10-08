import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class AnnualReportPage extends StatefulWidget {
  const AnnualReportPage({super.key});

  @override
  State<AnnualReportPage> createState() => _AnnualReportPageState();
}

class _AnnualReportPageState extends State<AnnualReportPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  DateTime _startDate = DateTime(DateTime.now().year, 1, 1);
  DateTime _endDate = DateTime(DateTime.now().year, 12, 31);

  bool _isLoading = true;

  int _totalStudents = 0;
  int _totalTeachers = 0;
  double _avgAttendance = 0.0;
  double _totalIncome = 0.0;
  double _totalExpense = 0.0;
  double _netSavings = 0.0;
  int _totalHifzRecords = 0;
  int _totalQaidaRecords = 0;

  @override
  void initState() {
    super.initState();
    _loadAnnualData();
  }

  Future<void> _loadAnnualData() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final database = await db.database;
      final startStr = intl.DateFormat('yyyy-MM-dd').format(_startDate);
      final endStr = intl.DateFormat('yyyy-MM-dd').format(_endDate);

      // 1. Students & Teachers
      final students = await db.getStudents();
      final teachers = await db.getTeachers();
      _totalStudents = students.where((s) => (s['is_deleted'] ?? 0) == 0).length;
      _totalTeachers = teachers.length;

      // 2. Attendance
      final attRows = await database.rawQuery(
        "SELECT status_1, status FROM attendance WHERE date BETWEEN ? AND ?",
        [startStr, endStr],
      );
      if (attRows.isNotEmpty) {
        int present = 0;
        for (var r in attRows) {
          final st = (r['status_1'] ?? r['status'] ?? '').toString().toUpperCase();
          if (st == 'P' || st == 'PRESENT' || st == 'حاضر') present++;
        }
        _avgAttendance = (present / attRows.length) * 100;
      } else {
        _avgAttendance = 0.0;
      }

      // 3. Finance
      final financeRows = await database.rawQuery(
        "SELECT type, amount FROM finance WHERE date BETWEEN ? AND ?",
        [startStr, endStr],
      );
      double inc = 0.0;
      double exp = 0.0;
      for (var f in financeRows) {
        final amt = double.tryParse(f['amount']?.toString() ?? '0') ?? 0.0;
        final type = (f['type'] ?? '').toString().toLowerCase();
        if (type == 'income' || type == 'آمدن') {
          inc += amt;
        } else if (type == 'expense' || type == 'خرچ' || type == 'اخراجات') {
          exp += amt;
        }
      }

      // Add fee submissions to income if not in finance
      final feeRows = await database.rawQuery(
        "SELECT amount, status FROM fee_submissions WHERE date BETWEEN ? AND ?",
        [startStr, endStr],
      );
      for (var f in feeRows) {
        final st = (f['status'] ?? '').toString().toLowerCase();
        if (st == 'approved' || st == 'paid' || st == 'ادا شدہ') {
          inc += double.tryParse(f['amount']?.toString() ?? '0') ?? 0.0;
        }
      }

      _totalIncome = inc;
      _totalExpense = exp;
      _netSavings = inc - exp;

      // 4. Hifz & Qaida
      final hifzRows = await database.rawQuery(
        "SELECT COUNT(*) as cnt FROM daily_hifz WHERE date BETWEEN ? AND ?",
        [startStr, endStr],
      );
      _totalHifzRecords = Sqflite.firstIntValue(hifzRows) ?? 0;

      final qaidaRows = await database.rawQuery(
        "SELECT COUNT(*) as cnt FROM qaida_progress WHERE date BETWEEN ? AND ?",
        [startStr, endStr],
      );
      _totalQaidaRecords = Sqflite.firstIntValue(qaidaRows) ?? 0;
    } catch (e) {
      debugPrint("Annual Report Load Error: $e");
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _selectDateRange() async {
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(2035),
      initialDateRange: DateTimeRange(start: _startDate, end: _endDate),
    );
    if (picked != null) {
      setState(() {
        _startDate = picked.start;
        _endDate = picked.end;
      });
      _loadAnnualData();
    }
  }

  Future<void> _generateAndPrintPdf() async {
    final pdf = pw.Document();
    final startStr = intl.DateFormat('yyyy-MM-dd').format(_startDate);
    final endStr = intl.DateFormat('yyyy-MM-dd').format(_endDate);

    pdf.addPage(
      pw.Page(
        pageFormat: PdfPageFormat.a4,
        build: (pw.Context context) {
          return pw.Container(
            padding: const pw.EdgeInsets.all(20),
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Header(
                  level: 0,
                  child: pw.Row(
                    mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                    children: [
                      pw.Text('Madrasa Ashraf-ul-Madaris Farooka',
                          style: pw.TextStyle(
                              fontSize: 18, fontWeight: pw.FontWeight.bold)),
                      pw.Text('Annual Report',
                          style: pw.TextStyle(
                              fontSize: 16, color: PdfColors.green800)),
                    ],
                  ),
                ),
                pw.SizedBox(height: 10),
                pw.Text('Period: $startStr to $endStr',
                    style: const pw.TextStyle(fontSize: 12, color: PdfColors.grey700)),
                pw.Divider(),
                pw.SizedBox(height: 15),
                pw.Table(
                  border: pw.TableBorder.all(color: PdfColors.grey400),
                  children: [
                    pw.TableRow(
                      decoration: const pw.BoxDecoration(color: PdfColors.grey200),
                      children: [
                        pw.Padding(
                            padding: const pw.EdgeInsets.all(6),
                            child: pw.Text('Metric',
                                style: pw.TextStyle(fontWeight: pw.FontWeight.bold))),
                        pw.Padding(
                            padding: const pw.EdgeInsets.all(6),
                            child: pw.Text('Value',
                                style: pw.TextStyle(fontWeight: pw.FontWeight.bold))),
                      ],
                    ),
                    _buildPdfRow('Total Active Students', '$_totalStudents'),
                    _buildPdfRow('Total Teachers', '$_totalTeachers'),
                    _buildPdfRow('Average Attendance', '${_avgAttendance.toStringAsFixed(1)}%'),
                    _buildPdfRow('Total Revenue / Income', 'Rs. ${_totalIncome.toStringAsFixed(0)}'),
                    _buildPdfRow('Total Expenditure', 'Rs. ${_totalExpense.toStringAsFixed(0)}'),
                    _buildPdfRow(_netSavings >= 0 ? 'Net Savings / Balance' : 'Deficit / Loss', 'Rs. ${_netSavings.abs().toStringAsFixed(0)}'),
                    _buildPdfRow('Hifz Activity Records', '$_totalHifzRecords entries'),
                    _buildPdfRow('Qaida / Nazira Records', '$_totalQaidaRecords entries'),
                  ],
                ),
                pw.SizedBox(height: 40),
                pw.Row(
                  mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                  children: [
                    pw.Text('Principal Signature: __________________'),
                    pw.Text('Date: ${intl.DateFormat('yyyy-MM-dd').format(DateTime.now())}'),
                  ],
                )
              ],
            ),
          );
        },
      ),
    );

    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name: 'Annual_Report_${intl.DateFormat('yyyy').format(_startDate)}.pdf',
    );
  }

  pw.TableRow _buildPdfRow(String key, String value) {
    return pw.TableRow(
      children: [
        pw.Padding(padding: const pw.EdgeInsets.all(6), child: pw.Text(key)),
        pw.Padding(
            padding: const pw.EdgeInsets.all(6),
            child: pw.Text(value, style: pw.TextStyle(fontWeight: pw.FontWeight.bold))),
      ],
    );
  }

  void _shareAnnualSummaryWhatsApp() {
    final startStr = intl.DateFormat('yyyy-MM-dd').format(_startDate);
    final endStr = intl.DateFormat('yyyy-MM-dd').format(_endDate);

    final buffer = StringBuffer();
    buffer.writeln('📋 *سالانہ رپورٹ - مدرسہ اشرف المدارس فروکہ*');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('📅 *دورانیہ:* $startStr تا $endStr');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('👥 *کل طلبہ:* $_totalStudents');
    buffer.writeln('👨‍🏫 *کل اساتذہ:* $_totalTeachers');
    buffer.writeln('📊 *اوسط حاضری:* ${_avgAttendance.toStringAsFixed(1)}%');
    buffer.writeln('💰 *کل آمدن:* ${_totalIncome.toStringAsFixed(0)} روپے');
    buffer.writeln('💸 *کل اخراجات:* ${_totalExpense.toStringAsFixed(0)} روپے');
    buffer.writeln('💵 *خالص بچت:* ${_netSavings.toStringAsFixed(0)} روپے');
    buffer.writeln('📖 *حفظ اندراجات:* $_totalHifzRecords');
    buffer.writeln('📚 *قاعدہ/ناظرہ اندراجات:* $_totalQaidaRecords');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('_اشرف المدارس مینجمنٹ سسٹم_');

    Share.share(buffer.toString(), subject: 'سالانہ رپورٹ');
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final startStr = intl.DateFormat('dd MMM yyyy').format(_startDate);
    final endStr = intl.DateFormat('dd MMM yyyy').format(_endDate);

    return Scaffold(
      backgroundColor: bgColor,
      drawer: const AppDrawer(),
      appBar: AppBar(
        title: Text(
          isUrdu ? 'سالانہ جامع رپورٹ' : 'Annual Comprehensive Report',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.share),
            tooltip: isUrdu ? 'WhatsApp شیئر' : 'Share WhatsApp',
            onPressed: _shareAnnualSummaryWhatsApp,
          ),
          IconButton(
            icon: const Icon(Icons.picture_as_pdf),
            tooltip: isUrdu ? 'PDF پرنٹ' : 'Print PDF',
            onPressed: _generateAndPrintPdf,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  // Date Filter Selector Card
                  Card(
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12)),
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(isUrdu ? 'منتخب دورانیہ:' : 'Selected Period:',
                                  style: const TextStyle(
                                      fontSize: 12, color: Colors.grey)),
                              Text('$startStr - $endStr',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold, fontSize: 14)),
                            ],
                          ),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                              foregroundColor: Colors.white,
                            ),
                            icon: const Icon(Icons.calendar_month, size: 16),
                            label: Text(isUrdu ? 'تاریخ بدلیں' : 'Change Dates'),
                            onPressed: _selectDateRange,
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Metrics Grid
                  Row(
                    children: [
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'کل طلبہ' : 'Students',
                          '$_totalStudents',
                          Icons.people,
                          Colors.blue,
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'کل اساتذہ' : 'Teachers',
                          '$_totalTeachers',
                          Icons.school,
                          Colors.indigo,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'اوسط حاضری' : 'Avg Attendance',
                          '${_avgAttendance.toStringAsFixed(1)}%',
                          Icons.how_to_reg,
                          Colors.teal,
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu
                              ? (_netSavings >= 0 ? 'خالص بچت' : 'خالص خسارہ')
                              : (_netSavings >= 0 ? 'Net Savings' : 'Deficit'),
                          'Rs. ${_netSavings.abs().toStringAsFixed(0)}',
                          _netSavings >= 0 ? Icons.savings : Icons.trending_down,
                          _netSavings >= 0 ? Colors.green : Colors.red,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'کل آمدن' : 'Total Income',
                          '${_totalIncome.toStringAsFixed(0)} Rs',
                          Icons.arrow_downward,
                          Colors.green.shade700,
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'کل اخراجات' : 'Total Expense',
                          '${_totalExpense.toStringAsFixed(0)} Rs',
                          Icons.arrow_upward,
                          Colors.red.shade700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'حفظ ریکارڈز' : 'Hifz Entries',
                          '$_totalHifzRecords',
                          Icons.menu_book,
                          Colors.purple,
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _buildMetricCard(
                          isUrdu ? 'قاعدہ ریکارڈز' : 'Qaida Entries',
                          '$_totalQaidaRecords',
                          Icons.auto_stories,
                          Colors.orange.shade800,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),

                  // Actions
                  SizedBox(
                    width: double.infinity,
                    height: 48,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: primaryGreen,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10)),
                      ),
                      icon: const Icon(Icons.print),
                      label: Text(
                        isUrdu ? 'رپورٹ پرنٹ یا PDF بنائیں' : 'Print or Save PDF',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      onPressed: _generateAndPrintPdf,
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _buildMetricCard(
      String title, String value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Icon(icon, color: color, size: 24),
              Text(value,
                  style: TextStyle(
                      fontSize: 16, fontWeight: FontWeight.bold, color: color)),
            ],
          ),
          const SizedBox(height: 8),
          Text(title,
              style: const TextStyle(fontSize: 12, color: Colors.black54)),
        ],
      ),
    );
  }
}
