import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

class FilteredHifzReportPage extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<Map<String, dynamic>> filteredRecords;
  final List<Map<String, dynamic>> allStudents;
  final Map<String, List<String>>? naaghahDates;

  const FilteredHifzReportPage({
    super.key,
    required this.title,
    required this.subtitle,
    required this.filteredRecords,
    required this.allStudents,
    this.naaghahDates,
  });

  String _getStudentName(String studentId) {
    final cleanId = studentId.trim();
    var student = allStudents.firstWhere(
      (s) => (s['id'] ?? '').toString().trim() == cleanId,
      orElse: () => <String, dynamic>{},
    );
    if (student.isEmpty) {
      for (final r in filteredRecords) {
        if ((r['student_id'] ?? '').toString().trim() == cleanId) {
          final sName = (r['student_name'] ?? r['name'])?.toString().trim();
          if (sName != null && sName.isNotEmpty) return sName;
        }
      }
      return 'نامعلوم';
    }
    return (student['name'] ?? 'نامعلوم').toString().trim();
  }

  String _getFatherName(String studentId) {
    final cleanId = studentId.trim();
    var student = allStudents.firstWhere(
      (s) => (s['id'] ?? '').toString().trim() == cleanId,
      orElse: () => <String, dynamic>{},
    );
    return (student['father_name'] ?? '').toString().trim();
  }
  
  String _getRollNo(String studentId, [Map<String, dynamic>? record]) {
    final cleanId = studentId.trim();

    // 1. Check passed record
    if (record != null) {
      for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
        final val = (record[key] ?? '').toString().trim();
        if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
          return val;
        }
      }
    }

    // 2. Search in allStudents
    var student = allStudents.firstWhere(
      (s) => (s['id'] ?? '').toString().trim() == cleanId,
      orElse: () => <String, dynamic>{},
    );
    if (student.isNotEmpty) {
      for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
        final val = (student[key] ?? '').toString().trim();
        if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
          return val;
        }
      }
    }

    // 3. Search in filteredRecords
    for (final r in filteredRecords) {
      if ((r['student_id'] ?? '').toString().trim() == cleanId) {
        for (final key in ['roll_number', 'roll_no', 'admission_number', 'admission_no', 'gr_number', 'serial_no']) {
          final val = (r[key] ?? '').toString().trim();
          if (val.isNotEmpty && val != 'null' && val != '0' && val != '-') {
            return val;
          }
        }
      }
    }

    return '-';
  }

  String translateStatusClean(String? status) {
    if (status == null || status.isEmpty || status == 'null') return '-';
    if (status == 'Excellent') return '';
    if (status == 'Good') return '';
    if (status == 'Average') return '';
    if (status == 'Weak') return '';
      if (status == 'Absent') return 'غیر حاضر';
      if (status == 'Leave') return 'رخصت';
    return status;
  }

  String translateQtyClean(String? qty) {
    if (qty == null || qty.isEmpty || qty == 'null') return '-';
    return qty
        .replaceAll('Lines', '')
        .replaceAll('Pages', '')
        .replaceAll('Ruku', '')
        .replaceAll('Rubah', '')
        .replaceAll('Nisf', '')
        .replaceAll('Suls', '')
        .replaceAll('Full Para', ' ')
        .trim();
  }

  String cleanUrduString(String? text) {
    if (text == null || text == 'null' || text.trim().isEmpty) return '-';
    return text
        .replaceAll('(3/4)', '')
        .replaceAll('(1/2)', '')
        .replaceAll('(1/4)', '')
        .replaceAll('(1)', '')
        .replaceAll('((', '(')
        .replaceAll('))', ')')
        .replaceAll(RegExp(r'\s+'), ' ')
        .replaceAllMapped(RegExp(r'(\d|[\u0600-\u06FF])\('), (match) => "${match.group(1)} (")
        .trim();
  }

  Future<void> _printReport(BuildContext context) async {
    try {
      final fontData = await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final fallbackData = await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
      final ttf = pw.Font.ttf(fontData);
      final fallbackTtf = pw.Font.ttf(fallbackData);
      final boldStyle = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf], fontWeight: pw.FontWeight.bold);
      final regularStyle = pw.TextStyle(font: ttf, fontFallback: [fallbackTtf]);

      final pdf = pw.Document();

      // Collect unique students for naaghah table
      final Set<String> studentIds = {};
      for (var r in filteredRecords) {
        final sid = r['student_id']?.toString() ?? '';
        if (sid.isNotEmpty) studentIds.add(sid);
      }
      if (naaghahDates != null) {
        studentIds.addAll(naaghahDates!.keys);
      }

      final studentsList = studentIds.map((sid) {
        final dates = naaghahDates?[sid] ?? [];
        return {
          'sid': sid,
          'name': _getStudentName(sid),
          'father': _getFatherName(sid),
          'roll': _getRollNo(sid),
          'dates': dates,
          'count': dates.isNotEmpty ? dates.length : (filteredRecords.where((r) => r['student_id'].toString() == sid).length),
        };
      }).toList();

      pdf.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4,
          textDirection: pw.TextDirection.rtl,
          margin: const pw.EdgeInsets.all(20),
          theme: pw.ThemeData.withFont(base: ttf, fontFallback: [fallbackTtf]),
          header: (ctx) => pw.Column(
            children: [
              pw.Center(
                child: pw.Text("جامعہ اشرف المدارس — فاروقہ، سرگودھا", style: boldStyle.copyWith(fontSize: 16, color: PdfColors.green800)),
              ),
              pw.SizedBox(height: 4),
              pw.Center(
                child: pw.Text(title, style: boldStyle.copyWith(fontSize: 14, color: PdfColors.red800)),
              ),
              pw.SizedBox(height: 2),
              pw.Center(
                child: pw.Text("$subtitle | کل طلبہ: ${studentsList.length}", style: regularStyle.copyWith(fontSize: 10, color: PdfColors.grey700)),
              ),
              pw.SizedBox(height: 8),
              pw.Divider(thickness: 1.5, color: PdfColors.green800),
              pw.SizedBox(height: 8),
            ],
          ),
          build: (ctx) => [
            pw.Table(
              border: pw.TableBorder.all(color: PdfColors.grey400, width: 0.5),
              columnWidths: {
                0: const pw.FixedColumnWidth(25),
                1: const pw.FixedColumnWidth(45),
                2: const pw.FlexColumnWidth(2.5),
                3: const pw.FlexColumnWidth(2),
                4: const pw.FixedColumnWidth(40),
                5: const pw.FlexColumnWidth(3),
              },
              children: [
                pw.TableRow(
                  decoration: const pw.BoxDecoration(color: PdfColors.grey200),
                  children: [
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("#", textAlign: pw.TextAlign.center, style: boldStyle.copyWith(fontSize: 9))),
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("رول نمبر", textAlign: pw.TextAlign.center, style: boldStyle.copyWith(fontSize: 9))),
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("نام طالب علم", textAlign: pw.TextAlign.right, style: boldStyle.copyWith(fontSize: 9))),
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("ولدیت", textAlign: pw.TextAlign.right, style: boldStyle.copyWith(fontSize: 9))),
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("کل ناغے", textAlign: pw.TextAlign.center, style: boldStyle.copyWith(fontSize: 9, color: PdfColors.red800))),
                    pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("ناغہ کی تاریخیں", textAlign: pw.TextAlign.right, style: boldStyle.copyWith(fontSize: 9))),
                  ],
                ),
                ...studentsList.asMap().entries.map((entry) {
                  int idx = entry.key;
                  var st = entry.value;
                  final datesList = (st['dates'] as List<String>);
                  final datesStr = datesList.isNotEmpty ? datesList.join(', ') : '-';
                  return pw.TableRow(
                    decoration: pw.BoxDecoration(color: idx % 2 == 1 ? PdfColors.grey100 : PdfColors.white),
                    children: [
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("${idx + 1}", textAlign: pw.TextAlign.center, style: regularStyle.copyWith(fontSize: 8))),
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("${st['roll']}", textAlign: pw.TextAlign.center, style: regularStyle.copyWith(fontSize: 8))),
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("${st['name']}", textAlign: pw.TextAlign.right, style: boldStyle.copyWith(fontSize: 8))),
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text("${st['father']}", textAlign: pw.TextAlign.right, style: regularStyle.copyWith(fontSize: 8))),
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text((st['count'] as int) == 0 ? "0 (بلاناغہ)" : "${st['count']}", textAlign: pw.TextAlign.center, style: boldStyle.copyWith(fontSize: 8, color: (st['count'] as int) == 0 ? PdfColors.green800 : PdfColors.red800))),
                      pw.Padding(padding: const pw.EdgeInsets.all(5), child: pw.Text((st['count'] as int) == 0 ? "ماشاءاللہ باقاعدہ و پابند طالب علم" : datesStr, textAlign: pw.TextAlign.right, style: regularStyle.copyWith(fontSize: 7.5, color: (st['count'] as int) == 0 ? PdfColors.green800 : PdfColors.black))),
                    ],
                  );
                }),
              ],
            ),
          ],
        ),
      );

      await Printing.layoutPdf(
        onLayout: (format) async => pdf.save(),
        name: '${title}_Report.pdf',
      );
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('پرنٹنگ میں مسئلہ پیش آیا: $e'), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    // Unique students list if naaghahDates provided
    final Set<String> uniqueStudentIds = {};
    for (var r in filteredRecords) {
      final sid = r['student_id']?.toString() ?? '';
      if (sid.isNotEmpty) uniqueStudentIds.add(sid);
    }
    if (naaghahDates != null) {
      uniqueStudentIds.addAll(naaghahDates!.keys);
    }
    final int totalCount = naaghahDates != null ? uniqueStudentIds.length : filteredRecords.length;

    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      appBar: AppBar(
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: const Color(0xFF284D3D),
        actions: [
          IconButton(
            icon: const Icon(Icons.print, color: Colors.white),
            tooltip: 'رپورٹ پرنٹ کریں',
            onPressed: () => _printReport(context),
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            color: Colors.white,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(subtitle, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.blueAccent)),
                    const SizedBox(height: 3),
                    Text("کل طلبہ: $totalCount", style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.black87, fontSize: 14)),
                  ],
                ),
                ElevatedButton.icon(
                  onPressed: () => _printReport(context),
                  icon: const Icon(Icons.print, size: 16, color: Colors.white),
                  label: const Text("پرنٹ رپورٹ", style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)),
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.red.shade700, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6)),
                ),
              ],
            ),
          ),
          Expanded(
            child: (naaghahDates != null)
                ? _buildNaaghahStudentListView(uniqueStudentIds.toList())
                : _buildStandardRecordsListView(),
          ),
        ],
      ),
    );
  }

  Widget _buildNaaghahStudentListView(List<String> studentIds) {
    if (studentIds.isEmpty) {
      return const Center(child: Text("اس زمرے میں کوئی طالب علم موجود نہیں ہے", style: TextStyle(color: Colors.grey, fontSize: 16, fontWeight: FontWeight.bold)));
    }

    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: studentIds.length,
      itemBuilder: (context, index) {
        final sid = studentIds[index];
        final sName = _getStudentName(sid);
        final fName = _getFatherName(sid);
        final rNo = _getRollNo(sid);
        final rollSuffix = (rNo.isNotEmpty && rNo != '-') ? " (رول: $rNo)" : "";
        final displayName = fName.isNotEmpty ? "$sName ولد $fName" : sName;
        final dates = naaghahDates?[sid] ?? [];

        return Card(
          elevation: 2,
          margin: const EdgeInsets.only(bottom: 10),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
            side: BorderSide(color: dates.isEmpty ? Colors.green.shade300 : Colors.red.shade200, width: 1),
          ),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        "${index + 1}. $displayName$rollSuffix",
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF284D3D)),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: dates.isEmpty ? Colors.green.shade50 : Colors.red.shade50,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: dates.isEmpty ? Colors.green.shade300 : Colors.red.shade300),
                      ),
                      child: Text(
                        dates.isEmpty ? "ماشاءاللہ بلاناغہ" : "${dates.length} ناغے",
                        style: TextStyle(fontWeight: FontWeight.bold, color: dates.isEmpty ? Colors.green.shade800 : Colors.red.shade800, fontSize: 12),
                      ),
                    ),
                  ],
                ),
                if (dates.isNotEmpty) ...[
                  const Divider(height: 16),
                  const Text("ناغہ کرنے کی تاریخیں:", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.black87)),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: dates.map((d) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: Colors.red.shade100.withOpacity(0.5),
                        border: Border.all(color: Colors.red.shade400, width: 0.8),
                        borderRadius: BorderRadius.circular(5),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.calendar_today, size: 10, color: Colors.red.shade800),
                          const SizedBox(width: 4),
                          Text(d, style: TextStyle(fontSize: 11, color: Colors.red.shade900, fontWeight: FontWeight.bold)),
                        ],
                      ),
                    )).toList(),
                  ),
                ] else ...[
                  const Divider(height: 16),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: Colors.green.shade50,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: Colors.green.shade200),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.star, size: 14, color: Colors.amber),
                        SizedBox(width: 6),
                        Text("ماشاءاللہ باقاعدہ و پابند طالب علم (کوئی ناغہ نہیں)", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.green)),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildStandardRecordsListView() {
    if (filteredRecords.isEmpty) {
      return const Center(child: Text("کوئی ریکارڈ نہیں ملا", style: TextStyle(color: Colors.red, fontSize: 16, fontWeight: FontWeight.bold)));
    }

    return ListView.builder(
      padding: const EdgeInsets.all(8),
      itemCount: filteredRecords.length,
      itemBuilder: (context, index) {
        final h = filteredRecords[index];
        final sid = h['student_id']?.toString() ?? '';
        final sName = _getStudentName(sid);
        final fName = _getFatherName(sid);
        final rNo = _getRollNo(sid, h);
        final rollSuffix = (rNo.isNotEmpty && rNo != '-') ? " (رول: $rNo)" : "";
        final displayName = fName.isNotEmpty ? "$sName ولد $fName" : sName;

        return Card(
          elevation: 2,
          margin: const EdgeInsets.only(bottom: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text("$displayName$rollSuffix", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF284D3D))),
                    ),
                    Text(h['date'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.grey, fontSize: 12)),
                  ],
                ),
                const Divider(),
                _buildRow("سبق:", "${cleanUrduString(h['sabaq_para'])} - ${h['sabaq_lines'] ?? '-'}", translateStatusClean(h['sabaq_grade'])),
                const SizedBox(height: 5),
                _buildRow("سبقی:", "${cleanUrduString(h['sabqi_para'])} - ${translateQtyClean(h['sabqi_qty'])}", translateStatusClean(h['sabqi_grade'])),
                const SizedBox(height: 5),
                _buildRow("منزل:", "${cleanUrduString(h['manzil_details'])} - ${translateQtyClean(h['manzil_total_qty'])}", translateStatusClean(h['manzil_grade'])),
                if (h['daily_complaint'] != null && h['daily_complaint'].toString().isNotEmpty && h['daily_complaint'] != '-') ...[
                  const SizedBox(height: 5),
                  _buildRow("شکایت:", "${h['daily_complaint']}", "شکایت"),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildRow(String label, String detail, String status) {
    Color statusColor = Colors.black87;
    if (status == 'بہترین' || status == 'Excellent') statusColor = Colors.green;
    if (status == 'بہتر' || status == 'Good') statusColor = Colors.blue;
    if (status == 'مناسب' || status == 'Average') statusColor = Colors.orange;
    if (status == 'کمزور' || status == 'Weak' || status == 'ناغہ' || status == 'غائب' || status == 'Absent' || status == 'شکایت' || status == '-') statusColor = Colors.red;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 50, child: Text(label, style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.black54))),
        Expanded(child: Text(detail, style: const TextStyle(fontWeight: FontWeight.w500))),
        Text(status, style: TextStyle(fontWeight: FontWeight.bold, color: statusColor)),
      ],
    );
  }
}
