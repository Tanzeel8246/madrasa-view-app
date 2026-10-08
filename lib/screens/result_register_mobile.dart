// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/ai_service.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'dart:io';
import 'package:path_provider/path_provider.dart';

class ResultRegisterMobilePage extends StatefulWidget {
  final Map<String, dynamic> exam;
  const ResultRegisterMobilePage({super.key, required this.exam});

  @override
  State<ResultRegisterMobilePage> createState() =>
      _ResultRegisterMobilePageState();
}

class _ResultRegisterMobilePageState extends State<ResultRegisterMobilePage> {
  final Color primary = const Color(0xFF284D3D);
  final Color gold = const Color(0xFFFFD700);
  final ScreenshotController screenshotController = ScreenshotController();

  final TextStyle urdu = const TextStyle(
    fontFamily: 'JameelNooriNastaleeq',
    fontSize: 12,
    height: 1.6,
  );

  List<Map<String, dynamic>> results = [];
  bool isLoading = true;
  bool _isPrinting = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      setState(() => isLoading = true);
      final db = DBHelper();
      final List<Map<String, dynamic>> rawData =
          await db.getCollectiveResults(widget.exam['id']);

      List<Map<String, dynamic>> data = List.from(rawData);
      data.sort((a, b) {
        double valA =
            double.tryParse((a['marks_obtained'] ?? 0).toString()) ?? 0.0;
        double valB =
            double.tryParse((b['marks_obtained'] ?? 0).toString()) ?? 0.0;
        return valB.compareTo(valA);
      });

      setState(() {
        results = data;
        isLoading = false;
      });
    } catch (e) {
      debugPrint("Load Error: $e");
      setState(() => isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Theme(
      data: ThemeData(fontFamily: 'JameelNooriNastaleeq'),
      child: Directionality(
        textDirection: ui.TextDirection.rtl,
        child: Scaffold(
          backgroundColor: const Color(0xFFF1EFE7),
          appBar: AppBar(
            backgroundColor: primary,
            foregroundColor: Colors.white,
            title: Text(widget.exam['exam_name'] ?? "رزلٹ رجسٹر",
                style: const TextStyle(fontWeight: FontWeight.bold)),
            actions: [
              IconButton(
                icon: const Icon(Icons.print, color: Colors.white),
                onPressed: _printPdf,
                tooltip: "پرنٹ کریں",
              ),
              IconButton(
                icon: const Icon(Icons.refresh, color: Colors.white),
                onPressed: _load,
                tooltip: "ریفریش کریں",
              ),
            ],
          ),
          body: isLoading
              ? const Center(child: CircularProgressIndicator())
              : results.isEmpty
                  ? _buildEmptyState()
                  : SingleChildScrollView(
                      child: Column(
                        children: [
                          _stats(),
                          _top3(),
                          _perfectBanner(),
                          _gradeSections(),
                        ],
                      ),
                    ),
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.info_outline, size: 60, color: Colors.grey.shade400),
          const SizedBox(height: 16),
          Text("کوئی رزلٹ نہیں ملا",
              style: urdu.copyWith(fontSize: 18, color: Colors.grey)),
          const SizedBox(height: 15),
          ElevatedButton(
            onPressed: _load,
            style: ElevatedButton.styleFrom(backgroundColor: primary),
            child: const Text("دوبارہ کوشش کریں",
                style: TextStyle(color: Colors.white)),
          )
        ],
      ),
    );
  }

  // ================= STATS =================
  Widget _stats() {
    int total = results.length;
    int pass = results
        .where((e) => e['status'] == 'Pass' || e['status'] == 'Pass (Grace)')
        .length;
    int fail = total - pass;
    double perc = total > 0 ? (pass / total) * 100 : 0;

    return Padding(
      padding: const EdgeInsets.all(8),
      child: Row(
        children: [
          _statCard("کل طلباء", total, Colors.blue),
          _statCard("کامیاب", pass, Colors.green),
          _statCard("ناکام", fail, Colors.red),
          _statCard("شرح فیصد", "${perc.toStringAsFixed(1)}%", Colors.teal),
        ],
      ),
    );
  }

  Widget _statCard(String t, dynamic v, Color c) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.all(4),
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border(bottom: BorderSide(color: c, width: 3)),
          boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 4)],
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          children: [
            Text("$v",
                style: TextStyle(
                    fontWeight: FontWeight.bold, color: c, fontSize: 18)),
            Text(t, style: urdu.copyWith(color: c, fontSize: 12)),
          ],
        ),
      ),
    );
  }

  // ================= TOP 3 =================
  Widget _top3() {
    if (results.isEmpty) return const SizedBox();
    Map<double, List<Map<String, dynamic>>> groupedByMarks = {};
    for (var r in results) {
      if (r['status'] != 'Pass') continue;
      double m = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
      if (m == 0) continue;
      groupedByMarks.putIfAbsent(m, () => []);
      groupedByMarks[m]!.add(r);
    }
    List<double> sortedMarks = groupedByMarks.keys.toList()
      ..sort((a, b) => b.compareTo(a));
    List<Color> colors = [
      const Color(0xFFFFD700),
      const Color(0xFFC0C0C0),
      const Color(0xFFCD7F32)
    ];
    List<String> titles = ["اول پوزیشن", "دوم پوزیشن", "سوم پوزیشن"];
    int count = sortedMarks.length < 3 ? sortedMarks.length : 3;

    return Padding(
      padding: const EdgeInsets.all(8.0),
      child: Column(
        children: List.generate(count, (i) {
          double score = sortedMarks[i];
          List students = groupedByMarks[score]!;
          String names = students.map((s) => s['student_name']).join("، ");

          return Container(
            margin: const EdgeInsets.symmetric(vertical: 4),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: colors[i], width: 2),
              borderRadius: BorderRadius.circular(10),
              boxShadow: [
                BoxShadow(color: colors[i].withOpacity(0.1), blurRadius: 4)
              ],
            ),
            child: Row(
              children: [
                Icon(Icons.emoji_events, color: colors[i], size: 36),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(titles[i],
                          style: urdu.copyWith(
                              fontWeight: FontWeight.bold,
                              color: colors[i],
                              fontSize: 16)),
                      Text(
                        names,
                        style: urdu.copyWith(
                            fontSize: 13, fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
                Text("${score.toStringAsFixed(0)} نمبر",
                    style: urdu.copyWith(fontSize: 12, color: Colors.grey)),
              ],
            ),
          );
        }),
      ),
    );
  }

  // ================= PERFECT BANNER (ستارہ امتیاز) =================
  Widget _perfectBanner() {
    final perfect = results.where((r) {
      double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
      double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 0;
      return tot > 0 && obt >= tot;
    }).toList();
    if (perfect.isEmpty) return const SizedBox();
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: gold, width: 2),
        borderRadius: BorderRadius.circular(12),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
      ),
      child: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 16),
            decoration: BoxDecoration(
              color: primary,
              borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(10), topRight: Radius.circular(10)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.star, color: Colors.amber, size: 22),
                const SizedBox(width: 8),
                Text(
                  "ستارہ امتیاز — پورے نمبر حاصل کرنے والے طلباء",
                  style: urdu.copyWith(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: Colors.white),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              children: perfect.map((s) {
                double obt =
                    double.tryParse((s['marks_obtained'] ?? 0).toString()) ?? 0;
                double tot =
                    double.tryParse((s['total_marks'] ?? 100).toString()) ??
                        100;
                return Container(
                  margin: const EdgeInsets.symmetric(vertical: 6),
                  padding:
                      const EdgeInsets.symmetric(vertical: 10, horizontal: 16),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    border: Border.all(color: Colors.amber, width: 1.5),
                    borderRadius: BorderRadius.circular(30),
                    boxShadow: [
                      BoxShadow(
                          color: Colors.amber.withOpacity(0.15),
                          blurRadius: 4,
                          offset: const Offset(0, 2))
                    ],
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.star, color: Colors.amber, size: 20),
                          const SizedBox(width: 10),
                          Text(s['student_name'] ?? '',
                              style: urdu.copyWith(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 14,
                                  color: Colors.black87)),
                        ],
                      ),
                      Text(
                          "${obt.toStringAsFixed(0)} / ${tot.toStringAsFixed(0)}",
                          style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                              color: Colors.brown)),
                    ],
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }

  // ================= GRADE SECTIONS =================
  Widget _gradeSections() {
    Map<String, List<Map<String, dynamic>>> grouped = {};
    const gradeOrder = ['A+', 'A', 'B', 'C', 'D', 'E', 'F'];
    for (var r in results) {
      String g = r['grade'] ?? 'F';
      grouped.putIfAbsent(g, () => []);
      grouped[g]!.add(r);
    }
    List<String> sortedGrades = grouped.keys.toList()
      ..sort((a, b) {
        int idxA = gradeOrder.indexOf(a);
        int idxB = gradeOrder.indexOf(b);
        return (idxA == -1 ? 99 : idxA).compareTo(idxB == -1 ? 99 : idxB);
      });
    return Column(
        children: sortedGrades
            .map((g) => Column(children: [
                  _gradeHeader(g, grouped[g]!.length),
                  _table(grouped[g]!),
                  const SizedBox(height: 20)
                ]))
            .toList());
  }

  Widget _gradeHeader(String g, int count) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(
          color: primary.withOpacity(0.05),
          border: Border(right: BorderSide(color: primary, width: 5))),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text("گریڈ $g",
            style: urdu.copyWith(
                fontWeight: FontWeight.bold, fontSize: 15, color: primary)),
        Text("$count طلباء", style: urdu.copyWith(fontSize: 13, color: primary))
      ]),
    );
  }

  Widget _table(List<Map<String, dynamic>> data) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Padding(
        padding: const EdgeInsets.all(8.0),
        child: Container(
          decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
              boxShadow: const [
                BoxShadow(color: Colors.black12, blurRadius: 4)
              ]),
          child: DataTable(
            columnSpacing: 15,
            headingRowHeight: 45,
            dataRowHeight: 55,
            headingRowColor: MaterialStateProperty.all(primary),
            columns: [
              _hCol("شمار"),
              _hCol("نام طالب علم"),
              _hCol("ولدیت"),
              _hCol("مقدار خواندگی"),
              _hCol("پختگی"),
              _hCol("تجوید"),
              _hCol("لہجہ"),
              _hCol("مسائل"),
              _hCol("حاصل"),
              _hCol("کل"),
              _hCol("اوسط"),
              _hCol("گریڈ"),
              _hCol("کیفیت"),
              _hCol("کارڈ"),
            ],
            rows: List.generate(data.length, (i) => _row(i + 1, data[i])),
          ),
        ),
      ),
    );
  }

  DataColumn _hCol(String t) => DataColumn(
      label: Text(t,
          style: urdu.copyWith(
              color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)));

  DataRow _row(int i, Map<String, dynamic> r) {
    double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
    double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;
    double perc = tot > 0 ? (obt / tot) * 100 : 0;
    return DataRow(cells: [
      DataCell(Text("$i", style: const TextStyle(fontSize: 11))),
      DataCell(Text(r['student_name'] ?? '',
          style: urdu.copyWith(fontWeight: FontWeight.bold, fontSize: 12))),
      DataCell(Text(r['father_name'] ?? '',
          style: urdu.copyWith(fontSize: 11, color: Colors.grey.shade700))),
      DataCell(Text(r['miqdar_khwandgi'] ?? '-',
          style: urdu.copyWith(fontSize: 11))),
      DataCell(
          Text("${r['pukhtagi'] ?? 0}", style: const TextStyle(fontSize: 11))),
      DataCell(
          Text("${r['tajweed'] ?? 0}", style: const TextStyle(fontSize: 11))),
      DataCell(
          Text("${r['lahja'] ?? 0}", style: const TextStyle(fontSize: 11))),
      DataCell(
          Text("${r['masail'] ?? 0}", style: const TextStyle(fontSize: 11))),
      DataCell(Text(obt.toStringAsFixed(0),
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12))),
      DataCell(Text(tot.toStringAsFixed(0),
          style: const TextStyle(fontSize: 11, color: Colors.grey))),
      DataCell(Text("${perc.toStringAsFixed(1)}%",
          style: TextStyle(
              color: perc < 40 ? Colors.red : Colors.blue.shade800,
              fontSize: 11,
              fontWeight: FontWeight.bold))),
      DataCell(Text(r['grade'] ?? '-',
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11))),
      DataCell(Text(r['status'] ?? '-', style: urdu.copyWith(fontSize: 11))),
      DataCell(IconButton(
          icon: const Icon(Icons.visibility, size: 22, color: Colors.blue),
          onPressed: () => _viewResultCard(r))),
    ]);
  }

  // ================= VIEW & SHARE RESULT CARD =================
  void _viewResultCard(Map<String, dynamic> r) async {
    String aiRemarks = r['remarks'] ?? "";

    if (aiRemarks.isEmpty) {
      showDialog(
          context: context,
          barrierDismissible: false,
          builder: (c) => const Center(child: CircularProgressIndicator()));
      double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
      double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;
      String prompt =
          "Generate a concise, encouraging 2-line performance remark in URDU for a student named ${r['student_name']}. Exam: ${widget.exam['exam_name']}, Marks: $obt/$tot, Grade: ${r['grade']}. Focus on praise if good, or encouragement if poor. Just Urdu text.";

      try {
        aiRemarks = await AIService.generateInsight(prompt);
      } catch (_) {
        aiRemarks =
            "ماشاء اللہ! طالب علم کی تعلیمی کارکردگی اطمینان بخش ہے۔ مزید محنت اور توجہ سے بہتر نتائج حاصل کیے جا سکتے ہیں۔";
      }
      Navigator.pop(context);
    }

    showDialog(
      context: context,
      builder: (context) => Theme(
        data: ThemeData(fontFamily: 'JameelNooriNastaleeq'),
        child: Dialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          backgroundColor: const Color(0xFFF0F2F5),
          child: Container(
            width: 420,
            padding: const EdgeInsets.all(12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Top Blue Print Button matching Screenshot #2
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: () => _printResultCardPdf(r),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF007BFF),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      elevation: 2,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8)),
                    ),
                    child: Text("پرنٹ رزلٹ کارڈ (Print Card)",
                        style: urdu.copyWith(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Colors.white)),
                  ),
                ),
                const SizedBox(height: 12),
                Expanded(
                  child: SingleChildScrollView(
                    scrollDirection: Axis.vertical,
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.topCenter,
                      child: _buildResultCardContent(r, aiRemarks),
                    ),
                  ),
                ),
                const Divider(height: 20, thickness: 1),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    ElevatedButton.icon(
                        onPressed: () => _shareCard(r, false),
                        icon: const Icon(Icons.save),
                        label: const Text("محفوظ کریں")),
                    ElevatedButton.icon(
                        onPressed: () => _shareCard(r, true),
                        icon: const Icon(Icons.share),
                        label: const Text("شیئر کریں"),
                        style: ElevatedButton.styleFrom(
                            backgroundColor: primary,
                            foregroundColor: Colors.white)),
                  ],
                ),
                TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text("بند کریں",
                        style: TextStyle(fontWeight: FontWeight.bold))),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildResultCardContent(Map<String, dynamic> r, String aiRemarks) {
    double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
    double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;

    return Directionality(
      textDirection: ui.TextDirection.rtl,
      child: Screenshot(
        controller: screenshotController,
        child: Container(
          width: 550, // Fixed width to prevent squishing
          decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: primary, width: 6),
            boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 8)],
          ),
          padding: const EdgeInsets.all(4),
          child: Container(
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: primary, width: 1.5),
            ),
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                // Header section
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Image.asset('assets/logo.png',
                        height: 55,
                        width: 55,
                        errorBuilder: (_, __, ___) =>
                            Icon(Icons.school, color: primary, size: 55)),
                    Expanded(
                      child: Column(
                        children: [
                          Text("مدرسہ اشرف المدارس",
                              style: urdu.copyWith(
                                  fontSize: 26,
                                  fontWeight: FontWeight.bold,
                                  color: primary)),
                          const SizedBox(height: 2),
                          Text("فاروقہ، ضلع سرگودھا | فون: 0300-1234567",
                              style: urdu.copyWith(
                                  fontSize: 11,
                                  color: primary,
                                  fontWeight: FontWeight.bold)),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                // Pill Badge
                Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 24, vertical: 6),
                    decoration: BoxDecoration(
                        color: primary,
                        borderRadius: BorderRadius.circular(25)),
                    child: Text("رزلٹ کارڈ (Result Card)",
                        style: urdu.copyWith(
                            color: gold,
                            fontSize: 15,
                            fontWeight: FontWeight.bold))),
                const SizedBox(height: 8),
                Text(
                    "${widget.exam['exam_name'] ?? ''} (${DateTime.now().toString().split(' ')[0]})",
                    style: urdu.copyWith(
                        fontSize: 14,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87)),
                const SizedBox(height: 8),
                // Double Divider
                Divider(color: primary, thickness: 2, height: 4),
                Divider(color: primary, thickness: 0.8, height: 4),

                // Student Info Box
                Container(
                  margin: const EdgeInsets.symmetric(vertical: 14),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    border: Border.all(color: Colors.grey.shade400, width: 1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _cardInfoRow("نام طالب علم:", r['student_name']),
                            _cardInfoRow("ولدیت:", r['father_name']),
                            _cardInfoRow(
                                "داخلہ نمبر:",
                                r['roll_number']?.toString() ??
                                    r['student_id']?.toString() ??
                                    ""),
                            _cardInfoRow(
                                "کلاس / درجہ:", r['class_name'] ?? "حفظ"),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      _buildPhotoBox(r['photo']),
                    ],
                  ),
                ),

                // Subjects Table
                Table(
                  border: TableBorder.all(color: primary, width: 1),
                  columnWidths: const {
                    0: FlexColumnWidth(2.5),
                    1: FlexColumnWidth(1),
                    2: FlexColumnWidth(1),
                  },
                  children: [
                    TableRow(
                      decoration: BoxDecoration(color: primary),
                      children: [
                        _tableHCell("تفصیل (Subjects / Details)"),
                        _tableHCell("کل نمبر"),
                        _tableHCell("حاصل کردہ"),
                      ],
                    ),
                    _tableDataRow(
                        "پختگی (Memorization / Pukhtagi)", r['pukhtagi'], 40),
                    _tableDataRow(
                        "تلفظ و تجوید (Tajweed / Talafuz)", r['tajweed'], 20),
                    _tableDataRow(
                        "خوبصورتی و لہجہ (Accent / Lahja)", r['lahja'], 20),
                    _tableDataRow(
                        "نماز و مسائل (Namaz / Masail)", r['masail'], 20),
                    TableRow(
                      decoration: const BoxDecoration(color: Color(0xFFEAEFEA)),
                      children: [
                        _tableCell("مجموعی نمبر (Total Marks)", bold: true),
                        _tableCell(tot.toStringAsFixed(0), bold: true),
                        _tableCell(obt.toStringAsFixed(0), bold: true),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Grade & Remarks Section
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: "اساتذہ کے ریمارکس / تاثرات:",
                          labelStyle: urdu.copyWith(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: primary),
                          border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide: BorderSide(color: primary, width: 1)),
                          enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide: BorderSide(color: primary, width: 1)),
                          contentPadding: const EdgeInsets.all(12),
                        ),
                        child: Text(aiRemarks,
                            style: urdu.copyWith(
                                fontSize: 13,
                                color: Colors.black87,
                                height: 1.5),
                            textAlign: TextAlign.right),
                      ),
                    ),
                    const SizedBox(width: 10),
                    SizedBox(
                      width: 110,
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: "گریڈ (Grade)",
                          labelStyle: urdu.copyWith(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: primary),
                          border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide: BorderSide(color: primary, width: 1)),
                          enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide: BorderSide(color: primary, width: 1)),
                          contentPadding: const EdgeInsets.symmetric(
                              vertical: 10, horizontal: 8),
                        ),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(r['grade'] ?? "-",
                                style: TextStyle(
                                    fontSize: 28,
                                    fontWeight: FontWeight.bold,
                                    color: primary,
                                    fontFamily: 'sans-serif')),
                            const SizedBox(height: 2),
                            Text(r['status'] == 'Pass' ? 'کامیاب' : 'فیل',
                                style: urdu.copyWith(
                                    fontSize: 14,
                                    color: r['status'] == 'Pass'
                                        ? Colors.green
                                        : Colors.red,
                                    fontWeight: FontWeight.bold)),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 35),

                // Signatures Section
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _signLine("دستخط کلاس انچارج"),
                    _signLine("دستخط سرپرست / والد"),
                    _signLine("مہر و دستخط مہتمم"),
                  ],
                ),
                const SizedBox(height: 20),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                        "جاری کردہ: ${DateTime.now().toString().split(' ')[0]} | آئی ڈی: #${r['student_id'] ?? r['id'] ?? 264}",
                        style:
                            const TextStyle(fontSize: 10, color: Colors.grey)),
                    const SizedBox(),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildPhotoBox(String? photoPath) {
    Widget placeholder = const Center(
        child: Text("No\nPhoto",
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 12,
                color: Colors.grey,
                fontWeight: FontWeight.bold)));

    Widget imageWidget = placeholder;
    if (photoPath != null && photoPath.isNotEmpty) {
      if (photoPath.startsWith('http')) {
        imageWidget = Image.network(photoPath,
            fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder);
      } else if (File(photoPath).existsSync()) {
        imageWidget = Image.file(File(photoPath), fit: BoxFit.cover);
      } else {
        String baseUrl = apiUrl;
        if (baseUrl.endsWith('api.php')) baseUrl = baseUrl.substring(0, baseUrl.length - 7);
        if (!baseUrl.endsWith('/')) baseUrl += '/';
        
        String fullUrl = baseUrl;
        if (photoPath.startsWith('uploads/')) {
          fullUrl += photoPath;
        } else if (!photoPath.contains('/')) {
          fullUrl += "uploads/students/$photoPath";
        } else {
          fullUrl += photoPath;
        }
        imageWidget = Image.network(fullUrl,
            fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder);
      }
    }

    return Container(
        width: 90,
        height: 110,
        decoration: BoxDecoration(
            color: Colors.grey.shade100,
            border: Border.all(color: Colors.grey.shade400, width: 1.5),
            borderRadius: BorderRadius.circular(8)),
        child: ClipRRect(
            borderRadius: BorderRadius.circular(6), child: imageWidget));
  }

  Widget _cardInfoRow(String label, String? value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            SizedBox(
              width: 90,
              child: Text(label,
                  style: urdu.copyWith(
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                      color: Colors.black87)),
            ),
            Expanded(
              child: Text(value ?? "",
                  style: urdu.copyWith(
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                      color: Colors.black)),
            ),
          ],
        ),
      );

  Widget _tableHCell(String t) => Container(
      padding: const EdgeInsets.all(8),
      alignment: Alignment.center,
      child: Text(t,
          style: urdu.copyWith(
              color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)));
  Widget _tableCell(String t, {bool bold = false}) => Container(
      padding: const EdgeInsets.all(8),
      alignment: Alignment.center,
      child: Text(t,
          style: urdu.copyWith(
              fontSize: 12,
              fontWeight: bold ? FontWeight.bold : FontWeight.normal,
              color: bold ? Colors.black : Colors.black87)));
  TableRow _tableDataRow(String label, dynamic obt, dynamic tot) => TableRow(
        children: [
          _tableCell(label, bold: true),
          _tableCell(tot?.toString() ?? "0"),
          _tableCell(obt?.toString() ?? "0"),
        ],
      );

  Widget _signLine(String t) => Column(children: [
        Container(width: 90, height: 1.5, color: Colors.black87),
        const SizedBox(height: 6),
        Text(t,
            style: urdu.copyWith(
                fontSize: 11,
                fontWeight: FontWeight.bold,
                color: Colors.black87))
      ]);

  Future<void> _shareCard(Map<String, dynamic> r, bool share) async {
    try {
      final image = await screenshotController.capture();
      if (image == null) return;
      final directory = await getApplicationDocumentsDirectory();
      final path = '${directory.path}/ResultCard_${r['student_name']}.png';
      final file = File(path);
      await file.writeAsBytes(image);
      if (share) {
        await Share.shareXFiles([XFile(path)],
            text: "${r['student_name']} کا رزلٹ کارڈ");
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text("رزلٹ کارڈ محفوظ کر لیا گیا ہے")));
      }
    } catch (e) {
      debugPrint("Share error: $e");
    }
  }

  Future<void> _printResultCardPdf(Map<String, dynamic> r) async {
    try {
      final imageBytes = await screenshotController.capture();
      if (imageBytes == null) return;

      final pdf = pw.Document();
      final image = pw.MemoryImage(imageBytes);

      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat.a4,
          build: (context) {
            return pw.Center(
              child: pw.Image(image, fit: pw.BoxFit.contain),
            );
          },
        ),
      );

      await Printing.layoutPdf(
        onLayout: (PdfPageFormat format) async => pdf.save(),
        name: "ResultCard_${r['student_name']}.pdf",
      );
    } catch (e) {
      debugPrint("Print Card Error: $e");
    }
  }

  // ================= PDF LANDSCAPE REGISTER =================
  Future<void> _printPdf() async {
    setState(() => _isPrinting = true);
    try {
      final pdf = pw.Document();

      int passed = 0;
      int failed = 0;
      double totalMarksSum = 0;
      double obtainedMarksSum = 0;
      for (var r in results) {
        if (r['status'] == 'Pass' || r['status'] == 'Grace Pass') {
          passed++;
        } else {
          failed++;
        }
        double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;
        double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
        totalMarksSum += tot;
        obtainedMarksSum += obt;
      }
      final total = results.length;
      final avgMarks = total > 0 ? (obtainedMarksSum / total) : 0.0;
      final avgTotal = total > 0 ? (totalMarksSum / total) : 100.0;

      // Group by marks for proper Top 3
      Map<double, List<Map<String, dynamic>>> groupedByMarks = {};
      for (var r in results) {
        if (r['status'] != 'Pass') continue;
        double m = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
        if (m == 0) continue;
        groupedByMarks.putIfAbsent(m, () => []);
        groupedByMarks[m]!.add(r);
      }
      List<double> sortedMarks = groupedByMarks.keys.toList()
        ..sort((a, b) => b.compareTo(a));
      
      // top3 will store Map with {score, students_list}
      List<Map<String, dynamic>> top3 = [];
      int count = sortedMarks.length < 3 ? sortedMarks.length : 3;
      for (int i = 0; i < count; i++) {
        double score = sortedMarks[i];
        top3.add({
          'score': score,
          'students': groupedByMarks[score],
        });
      }

      const int rowsPerPageFirst = 15;
      const int rowsPerPageNormal = 22;
      
      List<List<Map<String, dynamic>>> chunks = [];
      int currentIndex = 0;
      
      if (results.isNotEmpty) {
        int firstChunkSize = results.length > rowsPerPageFirst ? rowsPerPageFirst : results.length;
        chunks.add(results.sublist(0, firstChunkSize));
        currentIndex = firstChunkSize;
      }
      
      while (currentIndex < results.length) {
        int nextChunkSize = (results.length - currentIndex) > rowsPerPageNormal ? rowsPerPageNormal : (results.length - currentIndex);
        chunks.add(results.sublist(currentIndex, currentIndex + nextChunkSize));
        currentIndex += nextChunkSize;
      }

      if (chunks.isEmpty) {
        setState(() => _isPrinting = false);
        return;
      }

      for (int i = 0; i < chunks.length; i++) {
        final chunk = chunks[i];
        final pageNum = i + 1;
        
        final pageWidget = _buildPdfPageWidget(
          chunk: chunk, 
          pageNum: pageNum, 
          totalPages: chunks.length,
          total: total, passed: passed, failed: failed, 
          avgMarks: avgMarks, avgTotal: avgTotal, top3: top3,
          isUrdu: true,
        );

        final imageBytes = await screenshotController.captureFromWidget(
          pageWidget,
          delay: const Duration(milliseconds: 200),
          targetSize: const Size(1122, 793), // A4 Landscape size
          pixelRatio: 3.0, // High-Resolution rendering for Print Quality
        );

        pdf.addPage(
          pw.Page(
            pageFormat: PdfPageFormat.a4.landscape,
            margin: pw.EdgeInsets.zero,
            build: (pw.Context context) {
              return pw.FullPage(
                ignoreMargins: true,
                child: pw.Image(pw.MemoryImage(imageBytes), fit: pw.BoxFit.fill),
              );
            },
          )
        );
      }

      await Printing.layoutPdf(
        onLayout: (format) async => pdf.save(),
        name: 'Result_Register_${widget.exam['exam_name']}.pdf',
      );
    } catch (e) {
      debugPrint("Print Error: $e");
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text("Print failed: $e")));
      }
    } finally {
      if (mounted) setState(() => _isPrinting = false);
    }
  }

  Widget _buildPdfPageWidget({
    required List<Map<String, dynamic>> chunk,
    required int pageNum,
    required int totalPages,
    required int total, required int passed, required int failed,
    required double avgMarks, required double avgTotal,
    required List<Map<String, dynamic>> top3,
    required bool isUrdu,
  }) {
    const primary = Color(0xFF284D3D);
    const gold = Color(0xFFD4AC0D);
    const urduFont = TextStyle(fontFamily: 'JameelNooriNastaleeq', color: Colors.black);

    return Directionality(
      textDirection: ui.TextDirection.rtl,
      child: MediaQuery(
        data: const MediaQueryData(size: Size(1122, 793)),
        child: SizedBox(
          width: 1122,
          height: 793,
          child: Material(
            color: Colors.white,
            child: Container(
            padding: const EdgeInsets.all(24),
            child: Column(
              children: [
                // Header
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(color: primary, borderRadius: BorderRadius.circular(8)),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Image.asset('assets/logo.png', height: 45, width: 45, errorBuilder: (_,__,___)=>Icon(Icons.school, color: Colors.white, size: 45)),
                      Column(
                        children: [
                          Text("مدرسہ اشرف المدارس — فاروقہ، سرگودھا", style: urduFont.copyWith(fontSize: 20, color: Colors.white, fontWeight: FontWeight.bold)),
                          Text("اجتماعی رزلٹ رجسٹر — ${widget.exam['exam_name'] ?? ''}", style: urduFont.copyWith(fontSize: 14, color: gold)),
                        ],
                      ),
                      Text("صفحہ $pageNum / $totalPages", style: urduFont.copyWith(color: Colors.white, fontSize: 14)),
                    ],
                  ),
                ),
                const SizedBox(height: 12),

                // Summary & Top 3 (Only on Page 1)
                if (pageNum == 1) ...[
                  Container(
                    decoration: BoxDecoration(
                      border: Border.all(color: primary, width: 1.5),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        _pdfSummaryItem("کل طلباء", "$total", primary),
                        _pdfSummaryItem("کامیاب", "$passed", Colors.green.shade700),
                        _pdfSummaryItem("ناکام", "$failed", Colors.red.shade700),
                        _pdfSummaryItem("اوسط نمبر", "${avgMarks.toStringAsFixed(1)} / ${avgTotal.toStringAsFixed(0)}", primary),
                        _pdfSummaryItem("پاسنگ %", "${total > 0 ? (passed / total * 100).toStringAsFixed(1) : 0}%", Colors.blue.shade700),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  if (top3.isNotEmpty)
                    Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFF8E1),
                        border: Border.all(color: gold, width: 1.5),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text("پوزیشن ہولڈرز:", style: urduFont.copyWith(fontSize: 14, color: Colors.orange.shade900, fontWeight: FontWeight.bold)),
                          const SizedBox(width: 16),
                          ...List.generate(top3.length, (i) {
                            final posGroup = top3[i];
                            final score = posGroup['score'];
                            final students = posGroup['students'] as List;
                            final names = students.map((s) => s['student_name']).join("، ");
                            return Container(
                              margin: const EdgeInsets.symmetric(horizontal: 4),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                              decoration: BoxDecoration(
                                color: i == 0 ? gold : (i == 1 ? Colors.grey.shade400 : const Color(0xFFCD7F32)),
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: Text(
                                "${i+1}. $names (${score.toStringAsFixed(0)} نمبر)",
                                style: urduFont.copyWith(fontSize: 12, color: Colors.white, fontWeight: FontWeight.bold)
                              ),
                            );
                          })
                        ],
                      ),
                    ),
                  const SizedBox(height: 12),
                ],

                // Table
                Expanded(
                  child: Table(
                    border: TableBorder.all(color: primary, width: 1),
                    columnWidths: const {
                      0: FlexColumnWidth(0.5),
                      1: FlexColumnWidth(2),
                      2: FlexColumnWidth(1),
                      3: FlexColumnWidth(1),
                      4: FlexColumnWidth(1),
                      5: FlexColumnWidth(1),
                      6: FlexColumnWidth(1),
                      7: FlexColumnWidth(1),
                      8: FlexColumnWidth(1),
                      9: FlexColumnWidth(1),
                      10: FlexColumnWidth(1),
                      11: FlexColumnWidth(1),
                    },
                    children: [
                      // Table Header
                      TableRow(
                        decoration: BoxDecoration(color: primary),
                        children: [
                          _pdfTHeader("#"),
                          _pdfTHeader(isUrdu ? "طالب علم مع ولدیت" : "Student"),
                          _pdfTHeader(isUrdu ? "پختگی" : "Pukhtagi"),
                          _pdfTHeader(isUrdu ? "تجوید" : "Tajweed"),
                          _pdfTHeader(isUrdu ? "لہجہ" : "Lahja"),
                          _pdfTHeader(isUrdu ? "مسائل" : "Masail"),
                          _pdfTHeader(isUrdu ? "حاصل" : "Obtained"),
                          _pdfTHeader(isUrdu ? "رعایتی" : "Grace"),
                          _pdfTHeader(isUrdu ? "کل" : "Total"),
                          _pdfTHeader("%"),
                          _pdfTHeader(isUrdu ? "گریڈ" : "Grade"),
                          _pdfTHeader(isUrdu ? "کیفیت" : "Status"),
                        ],
                      ),
                      // Table Rows
                      ...chunk.map((r) {
                        int index = results.indexOf(r) + 1;
                        double obt = double.tryParse((r['marks_obtained'] ?? 0).toString()) ?? 0;
                        double tot = double.tryParse((r['total_marks'] ?? 100).toString()) ?? 100;
                        double perc = tot > 0 ? (obt / tot) * 100 : 0;
                        String statusStr = r['status'] == 'Pass' ? 'پاس' : (r['status'] == 'Grace Pass' ? 'رعایتی' : 'فیل');
                        Color rowColor = r['status'] == 'Fail' ? Colors.red.shade50 : Colors.white;

                        String fn = (r['father_name'] ?? '').toString().trim();
                        String studentWithFather = fn.isNotEmpty ? "${r['student_name'] ?? ''} بن $fn" : (r['student_name'] ?? '');
                        return TableRow(
                          decoration: BoxDecoration(color: rowColor),
                          children: [
                            _pdfTCell("$index"),
                            _pdfTCell(studentWithFather, align: Alignment.centerRight),
                            _pdfTCell(r['pukhtagi']?.toString() ?? "-"),
                            _pdfTCell(r['tajweed']?.toString() ?? "-"),
                            _pdfTCell(r['lahja']?.toString() ?? "-"),
                            _pdfTCell(r['masail']?.toString() ?? "-"),
                            _pdfTCell(obt.toStringAsFixed(0), bold: true),
                            _pdfTCell(r['grace_marks']?.toString() ?? "-"),
                            _pdfTCell(tot.toStringAsFixed(0)),
                            _pdfTCell("${perc.toStringAsFixed(1)}%"),
                            _pdfTCell(r['grade'] ?? "-", bold: true),
                            _pdfTCell(statusStr),
                          ],
                        );
                      })
                    ]
                  )
                )
              ]
            )
          )
        )
      )
    ));
  }

  Widget _pdfSummaryItem(String label, String value, Color color) {
    return Column(
      children: [
        Text(value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: color)),
        Text(label, style: const TextStyle(fontFamily: 'JameelNooriNastaleeq', fontSize: 12, fontWeight: FontWeight.bold)),
      ]
    );
  }

  Widget _pdfTHeader(String text) {
    return Container(
      padding: const EdgeInsets.all(6),
      alignment: Alignment.center,
      child: Text(text, style: const TextStyle(fontFamily: 'JameelNooriNastaleeq', color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
    );
  }

  Widget _pdfTCell(String text, {Alignment align = Alignment.center, bool bold = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      alignment: align,
      child: Text(text, style: TextStyle(fontFamily: 'JameelNooriNastaleeq', color: Colors.black87, fontSize: 11, fontWeight: bold ? FontWeight.bold : FontWeight.normal)),
    );
  }

}
