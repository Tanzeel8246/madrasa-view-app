import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

class FinancePdfExportService {
  static Future<pw.Document> generatePdfDocument({
    required String title,
    required String institutionName,
    required String periodStr,
    required List<Map<String, dynamic>> records,
    required double totalIncome,
    required double totalExpense,
    required bool isUrdu,
    bool isLandscape = true,
  }) async {
    final pdf = pw.Document();

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

    final netBalance = totalIncome - totalExpense;

    pdf.addPage(
      pw.MultiPage(
        pageFormat:
            isLandscape ? PdfPageFormat.a4.landscape : PdfPageFormat.a4,
        margin: const pw.EdgeInsets.all(20),
        textDirection: pw.TextDirection.rtl,
        theme: pw.ThemeData.withFont(base: font, bold: font),
        header: (pw.Context ctx) {
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
                title,
                style:
                    pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold),
              ),
              pw.SizedBox(height: 2),
              pw.Text(
                periodStr,
                style: const pw.TextStyle(
                    fontSize: 10, color: PdfColors.grey700),
              ),
              pw.SizedBox(height: 8),
              pw.Divider(thickness: 1.2, color: PdfColor.fromHex('#284D3D')),
              pw.SizedBox(height: 8),
            ],
          );
        },
        footer: (pw.Context ctx) {
          return pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text(
                'صفحہ ${ctx.pageNumber} از ${ctx.pagesCount}',
                style:
                    const pw.TextStyle(fontSize: 9, color: PdfColors.grey600),
              ),
              pw.Text(
                'تاریخ و وقت رپورٹ: ${intl.DateFormat('dd-MM-yyyy hh:mm a').format(DateTime.now())}',
                style:
                    const pw.TextStyle(fontSize: 9, color: PdfColors.grey600),
              ),
            ],
          );
        },
        build: (pw.Context ctx) {
          return [
            if (isLandscape)
              // لینڈ اسکیپ: تمام 6 کالمز
              pw.Table(
                border:
                    pw.TableBorder.all(color: PdfColors.grey400, width: 0.8),
                columnWidths: {
                  0: const pw.FlexColumnWidth(0.8), // #
                  1: const pw.FlexColumnWidth(1.5), // Date
                  2: const pw.FlexColumnWidth(2.2), // Head
                  3: const pw.FlexColumnWidth(3.0), // Name
                  4: const pw.FlexColumnWidth(3.5), // Description
                  5: const pw.FlexColumnWidth(2.0), // Amount
                },
                children: [
                  pw.TableRow(
                    decoration:
                        pw.BoxDecoration(color: PdfColor.fromHex('#284D3D')),
                    children: [
                      _th('#'),
                      _th('تاریخ'),
                      _th('ہیڈ (مد)'),
                      _th('دینے والا / لینے والا (نام)'),
                      _th('تفصیل'),
                      _th('رقم (روپے)'),
                    ],
                  ),
                  ...List.generate(records.length, (index) {
                    final r = records[index];
                    final typeStr =
                        r['type']?.toString().toLowerCase() ?? '';
                    final isIncome = typeStr.contains('income') ||
                        r['type']?.toString() == 'آمدن';
                    final amt =
                        double.tryParse(r['amount']?.toString() ?? '0') ?? 0.0;
                    final donorName = (r['donor_name'] ??
                            r['spender_name'] ??
                            r['name'] ??
                            '')
                        .toString()
                        .trim();
                    final desc = (r['description'] ?? '-').toString().trim();
                    final head =
                        (r['head_name'] ?? r['head'] ?? '-').toString().trim();
                    final date = (r['date'] ?? '-').toString().trim();
                    final rowColor = index % 2 == 0
                        ? PdfColors.white
                        : PdfColor.fromHex('#F9FBF9');

                    return pw.TableRow(
                      decoration: pw.BoxDecoration(color: rowColor),
                      children: [
                        _td('${index + 1}', align: pw.TextAlign.center),
                        _td(date, align: pw.TextAlign.center),
                        _td(head),
                        _td(donorName.isNotEmpty ? donorName : '-'),
                        _td(desc.isNotEmpty ? desc : '-'),
                        _td(
                          '${isIncome ? '+' : '-'} ${intl.NumberFormat('#,##0').format(amt)}',
                          align: pw.TextAlign.right,
                          color: isIncome
                              ? PdfColor.fromHex('#0F5132')
                              : PdfColor.fromHex('#842029'),
                          bold: true,
                        ),
                      ],
                    );
                  }),
                ],
              )
            else
              // پورٹریٹ: صرف 4 کالمز (نمبر شمار، تاریخ، نام، رقم)
              pw.Table(
                border:
                    pw.TableBorder.all(color: PdfColors.grey400, width: 0.8),
                columnWidths: {
                  0: const pw.FlexColumnWidth(0.8), // #
                  1: const pw.FlexColumnWidth(2.0), // Date
                  2: const pw.FlexColumnWidth(4.5), // Name
                  3: const pw.FlexColumnWidth(2.5), // Amount
                },
                children: [
                  pw.TableRow(
                    decoration:
                        pw.BoxDecoration(color: PdfColor.fromHex('#284D3D')),
                    children: [
                      _th('#'),
                      _th('تاریخ'),
                      _th('دینے والا / لینے والا (نام)'),
                      _th('رقم (روپے)'),
                    ],
                  ),
                  ...List.generate(records.length, (index) {
                    final r = records[index];
                    final typeStr =
                        r['type']?.toString().toLowerCase() ?? '';
                    final isIncome = typeStr.contains('income') ||
                        r['type']?.toString() == 'آمدن';
                    final amt =
                        double.tryParse(r['amount']?.toString() ?? '0') ?? 0.0;
                    final donorName = (r['donor_name'] ??
                            r['spender_name'] ??
                            r['name'] ??
                            '')
                        .toString()
                        .trim();
                    final date = (r['date'] ?? '-').toString().trim();
                    final rowColor = index % 2 == 0
                        ? PdfColors.white
                        : PdfColor.fromHex('#F9FBF9');

                    return pw.TableRow(
                      decoration: pw.BoxDecoration(color: rowColor),
                      children: [
                        _td('${index + 1}', align: pw.TextAlign.center),
                        _td(date, align: pw.TextAlign.center),
                        _td(donorName.isNotEmpty ? donorName : '-'),
                        _td(
                          '${isIncome ? '+' : '-'} ${intl.NumberFormat('#,##0').format(amt)}',
                          align: pw.TextAlign.right,
                          color: isIncome
                              ? PdfColor.fromHex('#0F5132')
                              : PdfColor.fromHex('#842029'),
                          bold: true,
                        ),
                      ],
                    );
                  }),
                ],
              ),
            pw.SizedBox(height: 16),
            // Grand summary box
            pw.Container(
              padding: const pw.EdgeInsets.all(10),
              decoration: pw.BoxDecoration(
                border:
                    pw.TableBorder.all(color: PdfColors.grey700, width: 1),
                color: PdfColor.fromHex('#F1EFE7'),
              ),
              child: pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceAround,
                children: [
                  pw.Text(
                      'کل آمدن: Rs. ${intl.NumberFormat('#,##0').format(totalIncome)}',
                      style: pw.TextStyle(
                          color: PdfColor.fromHex('#0F5132'),
                          fontWeight: pw.FontWeight.bold,
                          fontSize: 11)),
                  pw.Text(
                      'کل اخراجات: Rs. ${intl.NumberFormat('#,##0').format(totalExpense)}',
                      style: pw.TextStyle(
                          color: PdfColor.fromHex('#842029'),
                          fontWeight: pw.FontWeight.bold,
                          fontSize: 11)),
                  pw.Text(
                    netBalance >= 0
                        ? 'بچت / بیلنس: Rs. ${intl.NumberFormat('#,##0').format(netBalance)}'
                        : 'خسارہ: Rs. ${intl.NumberFormat('#,##0').format(netBalance.abs())}',
                    style: pw.TextStyle(
                      color: netBalance >= 0
                          ? PdfColor.fromHex('#0F5132')
                          : PdfColor.fromHex('#842029'),
                      fontWeight: pw.FontWeight.bold,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            pw.SizedBox(height: 30),
            // Signatures row
            pw.Row(
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: [
                pw.SizedBox(
                  width: 150,
                  child: pw.Column(
                    children: [
                      pw.Divider(thickness: 1, color: PdfColors.black),
                      pw.Text('دستخط اکاؤنٹنٹ',
                          style: const pw.TextStyle(fontSize: 10)),
                    ],
                  ),
                ),
                pw.SizedBox(
                  width: 150,
                  child: pw.Column(
                    children: [
                      pw.Divider(thickness: 1, color: PdfColors.black),
                      pw.Text('دستخط مہتمم / ناظم',
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

    return pdf;
  }

  static Future<void> exportLandscapePdf({
    required String title,
    required String institutionName,
    required String periodStr,
    required List<Map<String, dynamic>> records,
    required double totalIncome,
    required double totalExpense,
    required bool isUrdu,
    bool isLandscape = true,
  }) async {
    final pdf = await generatePdfDocument(
      title: title,
      institutionName: institutionName,
      periodStr: periodStr,
      records: records,
      totalIncome: totalIncome,
      totalExpense: totalExpense,
      isUrdu: isUrdu,
      isLandscape: isLandscape,
    );

    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name: isLandscape
          ? 'Financial_Report_Landscape.pdf'
          : 'Financial_Report_Portrait.pdf',
    );
  }

  static pw.Widget _th(String text) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 6, horizontal: 4),
      child: pw.Text(
        text,
        style: pw.TextStyle(
            color: PdfColors.white,
            fontWeight: pw.FontWeight.bold,
            fontSize: 9.5),
        textAlign: pw.TextAlign.center,
      ),
    );
  }

  static pw.Widget _td(String text,
      {pw.TextAlign align = pw.TextAlign.left,
      PdfColor? color,
      bool bold = false}) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 5, horizontal: 4),
      child: pw.Text(
        text,
        style: pw.TextStyle(
          fontSize: 8.5,
          color: color ?? PdfColors.black,
          fontWeight: bold ? pw.FontWeight.bold : pw.FontWeight.normal,
        ),
        textAlign: align,
      ),
    );
  }
}
