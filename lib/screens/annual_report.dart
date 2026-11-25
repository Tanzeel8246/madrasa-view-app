import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
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
  List<Map<String, dynamic>> _monthlyBreakdown = [];

  @override
  void initState() {
    super.initState();
    _loadAnnualData();
  }

  String _institutionTitle() {
    final name = (currentTenantName.value ?? '').trim();
    if (name.isNotEmpty) return name;
    return isUrduGlobal.value ? 'مدرسہ مینجمنٹ سسٹم' : 'Madrasa Management System';
  }

  Future<void> _loadAnnualData() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final database = await db.database;
      final startStr = intl.DateFormat('yyyy-MM-dd').format(_startDate);
      final endStr = intl.DateFormat('yyyy-MM-dd').format(_endDate);
      final sys = SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sys == 'Masjid';
      final sysCond = isMasjid
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      // 1. Students & Teachers (real DB counts, system_type isolated)
      final stdRes = await database.rawQuery(
        "SELECT COUNT(*) as cnt FROM students WHERE (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped', 'struck off', 'inactive', 'چھوڑ دیا', 'ترک تعلیم')) AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
      );
      _totalStudents = (stdRes.first['cnt'] as num?)?.toInt() ?? 0;

      final tRes = await database.rawQuery(
        "SELECT COUNT(*) as cnt FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
      );
      _totalTeachers = (tRes.first['cnt'] as num?)?.toInt() ?? 0;

      // 2. Attendance
      final attRows = await database.rawQuery(
        "SELECT status_1, status FROM attendance WHERE (date BETWEEN ? AND ?) AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
        [startStr, endStr],
      );
      if (attRows.isNotEmpty) {
        int present = 0;
        for (var r in attRows) {
          final st = (r['status_1'] ?? r['status'] ?? '').toString().toUpperCase().trim();
          if (st == 'P' || st == 'PRESENT' || st == 'حاضر') present++;
        }
        _avgAttendance = (present / attRows.length) * 100;
      } else {
        _avgAttendance = 0.0;
      }

      // 3. Authoritative General Ledger Financials (Approved & Reversed vouchers)
      double inc = 0.0;
      double exp = 0.0;
      final Map<String, Map<String, double>> monthlyMap = {};
      try {
        await db.ensureSimpleFinanceAccountingBackfill();
      } catch (_) {}

      final vSysCond = isMasjid
          ? "v.system_type = 'Masjid'"
          : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";

      final glRows = await database.rawQuery('''
        SELECT
          substr(v.date, 1, 7) as ym,
          c.type as account_type,
          COALESCE(SUM(e.debit), 0) as total_debit,
          COALESCE(SUM(e.credit), 0) as total_credit
        FROM acc_ledger_entries e
        JOIN acc_chart_of_accounts c ON e.account_id = c.id
        JOIN acc_vouchers v ON e.voucher_id = v.id
        WHERE (v.date BETWEEN ? AND ?)
          AND v.status IN ('Approved', 'Reversed')
          AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
          AND $vSysCond
          AND c.type IN ('Revenue', 'Income', 'Expense')
        GROUP BY ym, c.type
      ''', [startStr, endStr]);

      for (var r in glRows) {
        final ym = (r['ym'] ?? '').toString();
        final type = (r['account_type'] ?? '').toString();
        final debit = (r['total_debit'] as num?)?.toDouble() ?? 0.0;
        final credit = (r['total_credit'] as num?)?.toDouble() ?? 0.0;

        if (ym.isNotEmpty) {
          monthlyMap.putIfAbsent(ym, () => {'income': 0.0, 'expense': 0.0});
        }

        if (type == 'Revenue' || type == 'Income') {
          final netIncome = credit - debit;
          inc += netIncome;
          if (ym.isNotEmpty) {
            monthlyMap[ym]!['income'] = (monthlyMap[ym]!['income'] ?? 0.0) + netIncome;
          }
        } else if (type == 'Expense') {
          final netExpense = debit - credit;
          exp += netExpense;
          if (ym.isNotEmpty) {
            monthlyMap[ym]!['expense'] = (monthlyMap[ym]!['expense'] ?? 0.0) + netExpense;
          }
        }
      }

      _totalIncome = inc;
      _totalExpense = exp;
      _netSavings = inc - exp;

      final sortedYm = monthlyMap.keys.toList()..sort();
      _monthlyBreakdown = sortedYm.map((ym) {
        return {
          'month': ym,
          'income': monthlyMap[ym]!['income'] ?? 0.0,
          'expense': monthlyMap[ym]!['expense'] ?? 0.0,
          'savings': (monthlyMap[ym]!['income'] ?? 0.0) - (monthlyMap[ym]!['expense'] ?? 0.0),
        };
      }).toList();

      // 4. Hifz & Qaida (real SQLite tables)
      try {
        final hifzRes = await database.rawQuery(
          "SELECT COUNT(*) as cnt FROM hifz_record WHERE (date BETWEEN ? AND ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [startStr, endStr],
        );
        _totalHifzRecords = Sqflite.firstIntValue(hifzRes) ?? 0;
      } catch (_) {
        _totalHifzRecords = 0;
      }

      try {
        final qaidaRes = await database.rawQuery(
          "SELECT COUNT(*) as cnt FROM qaida_records WHERE (date BETWEEN ? AND ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [startStr, endStr],
        );
        _totalQaidaRecords = Sqflite.firstIntValue(qaidaRes) ?? 0;
      } catch (_) {
        _totalQaidaRecords = 0;
      }
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
    final startStr = intl.DateFormat('dd-MM-yyyy').format(_startDate);
    final endStr = intl.DateFormat('dd-MM-yyyy').format(_endDate);

    pw.Font? font;
    try {
      final fontData =
          await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      font = pw.Font.ttf(fontData);
    } catch (_) {
      try {
        final fontData =
            await rootBundle.load('assets/fonts/JameelNooriNastaleeq.ttf');
        font = pw.Font.ttf(fontData);
      } catch (_) {
        font = pw.Font.helvetica();
      }
    }

    final institutionName = _institutionTitle();

    pdf.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.all(24),
        textDirection: pw.TextDirection.rtl,
        theme: pw.ThemeData.withFont(base: font, bold: font),
        header: (pw.Context context) {
          return pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.center,
            children: [
              pw.Text(
                institutionName,
                style: pw.TextStyle(
                  fontSize: 18,
                  fontWeight: pw.FontWeight.bold,
                  color: PdfColor.fromHex('#284D3D'),
                ),
              ),
              pw.SizedBox(height: 4),
              pw.Text(
                'سالانہ جامع کارکردگی رپورٹ',
                style: pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold),
              ),
              pw.SizedBox(height: 2),
              pw.Text(
                'دورانیہ: $startStr تا $endStr',
                style: const pw.TextStyle(fontSize: 10, color: PdfColors.grey700),
              ),
              pw.SizedBox(height: 8),
              pw.Divider(thickness: 1.2, color: PdfColor.fromHex('#284D3D')),
              pw.SizedBox(height: 10),
            ],
          );
        },
        footer: (pw.Context context) {
          return pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text(
                'صفحہ ${context.pageNumber} از ${context.pagesCount}',
                style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey600),
              ),
              pw.Text(
                'تاریخ و وقت رپورٹ: ${intl.DateFormat('dd-MM-yyyy hh:mm a').format(DateTime.now())}',
                style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey600),
              ),
            ],
          );
        },
        build: (pw.Context context) {
          return [
            pw.Text(
              'کلیدی اشاریے و خلاصہ:',
              style: pw.TextStyle(
                  fontSize: 12,
                  fontWeight: pw.FontWeight.bold,
                  color: PdfColor.fromHex('#284D3D')),
            ),
            pw.SizedBox(height: 6),
            pw.Table(
              border: pw.TableBorder.all(color: PdfColors.grey400, width: 0.8),
              children: [
                pw.TableRow(
                  decoration: pw.BoxDecoration(color: PdfColor.fromHex('#284D3D')),
                  children: [
                    _buildPdfUrduTh('شمار'),
                    _buildPdfUrduTh('عنوان / اشاریہ'),
                    _buildPdfUrduTh('تفصیل / مقدار'),
                  ],
                ),
                _buildPdfUrduRow('1', 'کل زیر تعلیم فعال طلبہ', '$_totalStudents طلبہ'),
                _buildPdfUrduRow('2', 'کل فعال اساتذہ و عملہ', '$_totalTeachers اساتذہ'),
                _buildPdfUrduRow('3', 'اوسط حاضری کی شرح', '${_avgAttendance.toStringAsFixed(1)}%'),
                _buildPdfUrduRow('4', 'کل مالیاتی آمدن', 'Rs. ${intl.NumberFormat('#,##0').format(_totalIncome)}'),
                _buildPdfUrduRow('5', 'کل مالیاتی اخراجات', 'Rs. ${intl.NumberFormat('#,##0').format(_totalExpense)}'),
                _buildPdfUrduRow(
                  '6',
                  _netSavings >= 0 ? 'خالص بچت / سرپلس' : 'خالص خسارہ / شارٹ فال',
                  'Rs. ${intl.NumberFormat('#,##0').format(_netSavings.abs())}',
                  color: _netSavings >= 0
                      ? PdfColor.fromHex('#0F5132')
                      : PdfColor.fromHex('#842029'),
                ),
                _buildPdfUrduRow('7', 'حفظ سرگرمی کے اندراجات', '$_totalHifzRecords ریکارڈز'),
                _buildPdfUrduRow('8', 'قاعدہ و ناظرہ کے اندراجات', '$_totalQaidaRecords ریکارڈز'),
              ],
            ),
            pw.SizedBox(height: 16),
            if (_monthlyBreakdown.isNotEmpty) ...[
              pw.Text(
                'ماہ بہ ماہ مالیاتی تجزیہ:',
                style: pw.TextStyle(
                    fontSize: 12,
                    fontWeight: pw.FontWeight.bold,
                    color: PdfColor.fromHex('#284D3D')),
              ),
              pw.SizedBox(height: 6),
              pw.Table(
                border: pw.TableBorder.all(color: PdfColors.grey400, width: 0.8),
                children: [
                  pw.TableRow(
                    decoration: const pw.BoxDecoration(color: PdfColors.grey300),
                    children: [
                      _buildPdfUrduTh('ماہ و سال', textColor: PdfColors.black),
                      _buildPdfUrduTh('آمدن (روپے)', textColor: PdfColors.black),
                      _buildPdfUrduTh('اخراجات (روپے)', textColor: PdfColors.black),
                      _buildPdfUrduTh('خالص بچت / خسارہ', textColor: PdfColors.black),
                    ],
                  ),
                  ..._monthlyBreakdown.map((m) {
                    final double mInc = (m['income'] as num?)?.toDouble() ?? 0.0;
                    final double mExp = (m['expense'] as num?)?.toDouble() ?? 0.0;
                    final double sav = (m['savings'] as num?)?.toDouble() ?? 0.0;
                    return pw.TableRow(
                      children: [
                        _buildPdfUrduTd(m['month']?.toString() ?? '',
                            align: pw.TextAlign.center),
                        _buildPdfUrduTd(intl.NumberFormat('#,##0').format(mInc),
                            align: pw.TextAlign.center),
                        _buildPdfUrduTd(intl.NumberFormat('#,##0').format(mExp),
                            align: pw.TextAlign.center),
                        _buildPdfUrduTd(
                          intl.NumberFormat('#,##0').format(sav),
                          align: pw.TextAlign.center,
                          color: sav >= 0
                              ? PdfColor.fromHex('#0F5132')
                              : PdfColor.fromHex('#842029'),
                          bold: true,
                        ),
                      ],
                    );
                  }),
                ],
              ),
              pw.SizedBox(height: 20),
            ],
            pw.SizedBox(height: 25),
            pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: [
                pw.SizedBox(
                  width: 150,
                  child: pw.Column(
                    children: [
                      pw.Divider(thickness: 1, color: PdfColors.black),
                      pw.Text('دستخط ناظمِ تعلیمات',
                          style: const pw.TextStyle(fontSize: 10)),
                    ],
                  ),
                ),
                pw.SizedBox(
                  width: 150,
                  child: pw.Column(
                    children: [
                      pw.Divider(thickness: 1, color: PdfColors.black),
                      pw.Text('دستخط مہتمم / صدر',
                          style: const pw.TextStyle(fontSize: 10)),
                    ],
                  ),
                ),
              ],
            ),
          ];
        },
      ),
    );

    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name: 'Annual_Report_${intl.DateFormat('yyyy').format(_startDate)}.pdf',
    );
  }

  pw.TableRow _buildPdfUrduRow(String sNo, String metric, String value,
      {PdfColor? color}) {
    return pw.TableRow(
      children: [
        _buildPdfUrduTd(sNo, align: pw.TextAlign.center),
        _buildPdfUrduTd(metric),
        _buildPdfUrduTd(value,
            align: pw.TextAlign.center, bold: true, color: color),
      ],
    );
  }

  pw.Widget _buildPdfUrduTh(String text, {PdfColor? textColor}) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 6, horizontal: 4),
      child: pw.Text(
        text,
        style: pw.TextStyle(
          color: textColor ?? PdfColors.white,
          fontWeight: pw.FontWeight.bold,
          fontSize: 9.5,
        ),
        textAlign: pw.TextAlign.center,
      ),
    );
  }

  pw.Widget _buildPdfUrduTd(String text,
      {pw.TextAlign align = pw.TextAlign.right,
      bool bold = false,
      PdfColor? color}) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 5, horizontal: 6),
      child: pw.Text(
        text,
        style: pw.TextStyle(
          fontSize: 9,
          fontWeight: bold ? pw.FontWeight.bold : pw.FontWeight.normal,
          color: color ?? PdfColors.black,
        ),
        textAlign: align,
      ),
    );
  }

  void _shareAnnualSummaryWhatsApp() {
    final startStr = intl.DateFormat('yyyy-MM-dd').format(_startDate);
    final endStr = intl.DateFormat('yyyy-MM-dd').format(_endDate);
    final instName = _institutionTitle();

    final buffer = StringBuffer();
    buffer.writeln('📋 *سالانہ رپورٹ - $instName*');
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
    buffer.writeln('_$instName مینجمنٹ سسٹم_');

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
                  const SizedBox(height: 16),

                  // Monthly Financial Breakdown Table
                  if (_monthlyBreakdown.isNotEmpty) ...[
                    Card(
                      elevation: 2,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              children: [
                                Icon(Icons.table_chart, color: primaryGreen, size: 20),
                                const SizedBox(width: 8),
                                Text(
                                  isUrdu ? 'ماہانہ مالیاتی گوشوارہ' : 'Monthly Financial Breakdown',
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 15,
                                    color: primaryGreen,
                                  ),
                                ),
                              ],
                            ),
                            const Divider(height: 16),
                            Table(
                              border: TableBorder.all(color: Colors.grey.shade300, width: 0.5),
                              columnWidths: const {
                                0: FlexColumnWidth(2),
                                1: FlexColumnWidth(2.5),
                                2: FlexColumnWidth(2.5),
                                3: FlexColumnWidth(2.5),
                              },
                              children: [
                                TableRow(
                                  decoration: BoxDecoration(color: Colors.grey.shade200),
                                  children: [
                                    Padding(
                                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                      child: Text(isUrdu ? 'مہینہ' : 'Month', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11)),
                                    ),
                                    Padding(
                                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                      child: Text(isUrdu ? 'آمدن (Rs)' : 'Income', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11, color: Colors.green.shade800)),
                                    ),
                                    Padding(
                                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                      child: Text(isUrdu ? 'خرچ (Rs)' : 'Expense', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11, color: Colors.red.shade800)),
                                    ),
                                    Padding(
                                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                      child: Text(isUrdu ? 'بچت (Rs)' : 'Savings', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11)),
                                    ),
                                  ],
                                ),
                                ..._monthlyBreakdown.map((m) {
                                  final double sav = (m['savings'] as num?)?.toDouble() ?? 0.0;
                                  return TableRow(
                                    children: [
                                      Padding(
                                        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                        child: Text(m['month']?.toString() ?? '', textAlign: TextAlign.center, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
                                      ),
                                      Padding(
                                        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                        child: Text((m['income'] as num?)?.toStringAsFixed(0) ?? '0', textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: Colors.green.shade700)),
                                      ),
                                      Padding(
                                        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                        child: Text((m['expense'] as num?)?.toStringAsFixed(0) ?? '0', textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: Colors.red.shade700)),
                                      ),
                                      Padding(
                                        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                                        child: Text(sav.toStringAsFixed(0), textAlign: TextAlign.center, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: sav >= 0 ? Colors.green.shade800 : Colors.red.shade800)),
                                      ),
                                    ],
                                  );
                                }),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],
                  const SizedBox(height: 4),

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
