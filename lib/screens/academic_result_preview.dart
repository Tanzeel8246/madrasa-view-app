// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';

class AcademicResultPreviewPage extends StatefulWidget {
  final int examId;
  final int courseId;
  final int yearNo;
  final Map<String, dynamic> student;

  const AcademicResultPreviewPage({
    super.key,
    required this.examId,
    required this.courseId,
    required this.yearNo,
    required this.student,
  });

  @override
  State<AcademicResultPreviewPage> createState() => _AcademicResultPreviewPageState();
}

class _AcademicResultPreviewPageState extends State<AcademicResultPreviewPage> {
  final Color primaryGreen = const Color(0xFF1B4332);
  final Color bgColor = const Color(0xFFF0F2F5);

  List<Map<String, dynamic>> _results = [];
  Map<String, dynamic>? _examInfo;
  Map<String, dynamic>? _courseInfo;
  bool _isLoading = true;
  bool _hideHeaderForLetterhead = false;
  double _topMarginMm = 0.0;

  final Map<int, String> _yearNames = {
    1: 'سال اول (درجہ اولیٰ)',
    2: 'سال دوم (درجہ ثانیہ)',
    3: 'سال سوم (درجہ ثالثہ)',
    4: 'سال چہارم (درجہ رابعہ)',
    5: 'سال پنجم (درجہ خامسہ)',
    6: 'سال ششم (درجہ سادسہ)',
    7: 'سال ہفتم (درجہ عالیہ)',
    8: 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)',
  };

  @override
  void initState() {
    super.initState();
    _loadCardData();
  }

  Future<void> _loadCardData() async {
    setState(() => _isLoading = true);
    final db = DBHelper();

    // 1. Results for this student
    final res = await db.getExamSubjectResults(
      examId: widget.examId,
      courseId: widget.courseId,
      yearNo: widget.yearNo,
      studentId: widget.student['student_id'] as int? ?? widget.student['id'] as int,
    );

    // 2. Exam info
    final exams = await db.getExams();
    final exam = exams.firstWhere(
      (e) => e['id'] == widget.examId,
      orElse: () => {'exam_name': 'امتحان'},
    );

    // 3. Course info
    final courses = await db.getCourses();
    final course = courses.firstWhere(
      (c) => c['id'] == widget.courseId,
      orElse: () => {'name': 'درس نظامی'},
    );

    setState(() {
      _results = res;
      _examInfo = exam;
      _courseInfo = course;
      _isLoading = false;
    });
  }

  String _calculateGrade(double obtained, double total, bool isAbsent) {
    if (isAbsent) return 'غیر حاضر';
    if (total <= 0) return '—';
    final pct = (obtained / total) * 100;
    if (pct >= 80) return 'ممتاز';
    if (pct >= 70) return 'جید جدا';
    if (pct >= 60) return 'جید';
    if (pct >= 40) return 'مقبول';
    return 'راسب';
  }

  Color _getGradeColor(String grade) {
    switch (grade) {
      case 'ممتاز':
        return Colors.green.shade800;
      case 'جید جدا':
        return Colors.blue.shade800;
      case 'جید':
        return Colors.teal.shade800;
      case 'مقبول':
        return Colors.amber.shade900;
      default:
        return Colors.red.shade800;
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    double totalMax = 0.0;
    double totalObt = 0.0;
    bool allAbsent = true;
    bool anyFail = false;

    for (var r in _results) {
      final maxM = (r['max_marks'] as num?)?.toDouble() ?? 100.0;
      final obt = (r['marks_obtained'] as num?)?.toDouble() ?? 0.0;
      final passM = (r['subject_pass_marks'] as num?)?.toDouble() ?? 40.0;
      final isAbs = (r['is_absent'] ?? 0) == 1;

      totalMax += maxM;
      if (!isAbs) {
        allAbsent = false;
        totalObt += obt;
        if (obt < passM) anyFail = true;
      }
    }

    final overallPct = (totalMax > 0) ? ((totalObt / totalMax) * 100) : 0.0;
    final finalGrade = _results.isNotEmpty ? _calculateGrade(totalObt, totalMax, allAbsent) : '—';
    final nextYearTitle = (widget.yearNo < 8)
        ? (_yearNames[widget.yearNo + 1] ?? 'سال ${widget.yearNo + 1}')
        : 'فراغت / تکمیل کورس';

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          title: Text(
            isUrdu ? 'نتیجہ نامہ پریویو' : 'Result Card Preview',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    // Margin & Letterhead Adjustment Controls Box
                    Card(
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      color: Colors.white,
                      elevation: 2,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        child: Column(
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(
                                  isUrdu ? 'لیٹر پیڈ پرنٹنگ ایڈجسٹمنٹ' : 'Letterhead Adjustment',
                                  style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
                                ),
                                Switch(
                                  value: _hideHeaderForLetterhead,
                                  activeColor: primaryGreen,
                                  onChanged: (val) => setState(() => _hideHeaderForLetterhead = val),
                                ),
                              ],
                            ),
                            if (_hideHeaderForLetterhead) ...[
                              const Divider(height: 14),
                              Row(
                                children: [
                                  Text(isUrdu ? 'ٹاپ اسپیس (مارجن): ' : 'Top Space: '),
                                  Text('${_topMarginMm.toInt()} mm', style: const TextStyle(fontWeight: FontWeight.bold)),
                                  Expanded(
                                    child: Slider(
                                      value: _topMarginMm,
                                      min: 0,
                                      max: 80,
                                      activeColor: primaryGreen,
                                      onChanged: (val) => setState(() => _topMarginMm = val),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),

                    // Paper Preview Card
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: [
                          BoxShadow(color: Colors.black.withOpacity(0.08), blurRadius: 15, offset: const Offset(0, 4)),
                        ],
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          // Top Space Spacer for Letterhead
                          if (_hideHeaderForLetterhead && _topMarginMm > 0)
                            SizedBox(height: _topMarginMm * 2.5),

                          // Inner Double Border Container
                          Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              border: Border.all(color: primaryGreen, width: 2),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Column(
                              children: [
                                // Header (Visible unless pre-printed letterhead toggle is on)
                                if (!_hideHeaderForLetterhead) ...[
                                  Text(
                                    'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ',
                                    style: TextStyle(fontSize: 13, color: primaryGreen),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'جامعہ / مدرسہ اشرف المدارس',
                                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: primaryGreen),
                                  ),
                                  Text(
                                    'شعبہ درس نظامی و عصری علوم • امتحانی نتیجہ نامہ',
                                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                  ),
                                  const SizedBox(height: 8),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                                    decoration: BoxDecoration(
                                      color: primaryGreen,
                                      borderRadius: BorderRadius.circular(14),
                                    ),
                                    child: Text(
                                      _examInfo?['exam_name'] ?? 'امتحان',
                                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                                    ),
                                  ),
                                  const Divider(height: 20, thickness: 1.5),
                                ],

                                // Student Particulars
                                Container(
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(
                                    color: Colors.grey.shade50,
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(color: Colors.grey.shade200),
                                  ),
                                  child: Column(
                                    children: [
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text('نام طالب علم: ${widget.student['student_name'] ?? widget.student['name']}',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                                          Text('رول نمبر: ${widget.student['roll_number'] ?? '—'}',
                                              style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen)),
                                        ],
                                      ),
                                      const SizedBox(height: 4),
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text('ولدیت: ${widget.student['father_name'] ?? '—'}',
                                              style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                                          Text('کورس: ${_courseInfo?['name'] ?? 'درس نظامی'}',
                                              style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                                        ],
                                      ),
                                      const SizedBox(height: 4),
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text('سال / درجہ: ${_yearNames[widget.yearNo] ?? "سال ${widget.yearNo}"}',
                                              style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen, fontSize: 12)),
                                          Text('سیشن: ${widget.student['academic_session'] ?? '1446-1447ھ'}',
                                              style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: 14),

                                // Marks Table
                                Table(
                                  border: TableBorder.all(color: Colors.grey.shade300),
                                  columnWidths: const {
                                    0: FlexColumnWidth(4),
                                    1: FlexColumnWidth(2),
                                    2: FlexColumnWidth(2),
                                    3: FlexColumnWidth(2),
                                  },
                                  children: [
                                    TableRow(
                                      decoration: BoxDecoration(color: Colors.grey.shade200),
                                      children: const [
                                        Padding(padding: EdgeInsets.all(6), child: Text('مضمون و کتاب', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12), textAlign: TextAlign.center)),
                                        Padding(padding: EdgeInsets.all(6), child: Text('کل نمبر', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12), textAlign: TextAlign.center)),
                                        Padding(padding: EdgeInsets.all(6), child: Text('حاصل کردہ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12), textAlign: TextAlign.center)),
                                        Padding(padding: EdgeInsets.all(6), child: Text('گریڈ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12), textAlign: TextAlign.center)),
                                      ],
                                    ),
                                    ..._results.map((r) {
                                      final isAbs = (r['is_absent'] ?? 0) == 1;
                                      final obt = (r['marks_obtained'] as num?)?.toDouble() ?? 0.0;
                                      final maxM = (r['max_marks'] as num?)?.toDouble() ?? 100.0;
                                      final grade = isAbs ? 'غیر حاضر' : _calculateGrade(obt, maxM, false);

                                      return TableRow(
                                        children: [
                                          Padding(
                                            padding: const EdgeInsets.all(6),
                                            child: Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Text(r['subject_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                                                if (r['book_name'] != null && r['book_name'].toString().isNotEmpty)
                                                  Text(r['book_name'], style: TextStyle(fontSize: 10, color: Colors.grey.shade600)),
                                              ],
                                            ),
                                          ),
                                          Padding(padding: const EdgeInsets.all(6), child: Text('$maxM', textAlign: TextAlign.center, style: const TextStyle(fontSize: 12))),
                                          Padding(padding: const EdgeInsets.all(6), child: Text(isAbs ? 'غ/ح' : '$obt', textAlign: TextAlign.center, style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: isAbs ? Colors.red : Colors.black))),
                                          Padding(padding: const EdgeInsets.all(6), child: Text(grade, textAlign: TextAlign.center, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: _getGradeColor(grade)))),
                                        ],
                                      );
                                    }).toList(),
                                  ],
                                ),
                                const SizedBox(height: 14),

                                // Summary Box
                                Container(
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(
                                    color: Colors.grey.shade50,
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(color: primaryGreen),
                                  ),
                                  child: Column(
                                    children: [
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                                        children: [
                                          Text('کل: $totalMax', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                          Text('حاصل: $totalObt', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: primaryGreen)),
                                          Text('فیصد: ${overallPct.toStringAsFixed(1)}%', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                          Text('گریڈ: $finalGrade', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: _getGradeColor(finalGrade))),
                                        ],
                                      ),
                                      const Divider(height: 12),
                                      Text(
                                        (!anyFail && !allAbsent && finalGrade != 'راسب')
                                            ? '✅ کامیاب — اگلے تعلیمی سال ($nextYearTitle) میں ترقی دی گئی۔'
                                            : '⚠️ ناکام / مشروط — اسی سال میں دوبارہ جائزہ لیا جائے گا۔',
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 12,
                                          color: (!anyFail && !allAbsent && finalGrade != 'راسب') ? Colors.green.shade800 : Colors.red.shade800,
                                        ),
                                        textAlign: TextAlign.center,
                                      ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: 24),

                                // Signatures
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: const [
                                    Text('دستخط ممتحن', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                    Text('دستخط ناظم تعلیمات', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                    Text('مہر و دستخط مہتمم', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
