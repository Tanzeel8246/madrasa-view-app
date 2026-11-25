// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../db_helper.dart';
import '../utils/globals.dart';

class AcademicResultCardView extends StatefulWidget {
  final Map<String, dynamic>? exam;
  final Map<String, dynamic>? course;
  final int yearNo;
  final List<Map<String, dynamic>> students;
  final List<Map<String, dynamic>> subjects;
  final Map<String, dynamic>? initialStudent;

  const AcademicResultCardView({
    super.key,
    required this.exam,
    required this.course,
    required this.yearNo,
    required this.students,
    required this.subjects,
    this.initialStudent,
  });

  @override
  _AcademicResultCardViewState createState() => _AcademicResultCardViewState();
}

class _AcademicResultCardViewState extends State<AcademicResultCardView> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color accentBlue = const Color(0xFF1E3C72);
  final Color bgColor = const Color(0xFF525659);

  final DBHelper _db = DBHelper();

  // Print & Margin Adjustment State
  bool _isPreprintedMode = false;
  double _topMarginMm = 15.0;
  double _bottomMarginMm = 15.0;
  double _sidesMarginMm = 15.0;
  double _fontScale = 1.0;

  int _selectedStudentIndex = 0;
  bool _isPrinting = false;
  bool _isLoading = true;

  // Student results map: studentId -> { subjectId -> Map }
  final Map<int, Map<int, Map<String, dynamic>>> _resultsMap = {};

  @override
  void initState() {
    super.initState();
    if (widget.initialStudent != null) {
      final idx = widget.students.indexWhere((s) => s['student_id'] == widget.initialStudent!['student_id']);
      if (idx != -1) _selectedStudentIndex = idx;
    }
    _loadResultsData();
  }

  Future<void> _loadResultsData() async {
    setState(() => _isLoading = true);

    final examId = widget.exam?['id'] as int?;
    final courseId = widget.course?['id'] as int?;

    if (examId != null && courseId != null) {
      final data = await _db.getExamSubjectResults(
        examId: examId,
        courseId: courseId,
        yearNo: widget.yearNo,
      );

      for (var r in data) {
        final sId = r['student_id'] as int;
        final subId = r['subject_id'] as int;
        _resultsMap.putIfAbsent(sId, () => {})[subId] = r;
      }
    }

    if (!mounted) return;
    setState(() => _isLoading = false);
  }

  String _getDarjahTitle(int yr, String cType) {
    final dNames = {
      1: 'سال اول (اولیٰ)',
      2: 'سال دوم (ثانیہ)',
      3: 'سال سوم (ثالثہ)',
      4: 'سال چہارم (رابعہ)',
      5: 'سال پنجم (خامسہ)',
      6: 'سال ششم (سادسہ)',
      7: 'سال ہفتم (سابعہ)',
      8: 'سال ہشتم (دورہ حدیث)',
    };
    final asriNames = {
      1: 'ہفتم (7th)',
      2: 'ہشتم (8th)',
      3: 'نہم (9th)',
      4: 'دہم (10th)',
    };
    if (cType == 'Asri' || cType == 'School') {
      return asriNames[yr] ?? 'سال $yr';
    }
    return dNames[yr] ?? 'سال $yr';
  }

  double _getStudentTotalObtained(int studentId) {
    double sum = 0.0;
    final stdResults = _resultsMap[studentId] ?? {};
    for (var sb in widget.subjects) {
      final subId = sb['id'] as int;
      final r = stdResults[subId];
      if (r != null && r['is_absent'] != 1) {
        sum += (r['marks_obtained'] as num?)?.toDouble() ?? 0.0;
      }
    }
    return sum;
  }

  double _getTotalMaxMarks() {
    double sum = 0.0;
    for (var sb in widget.subjects) {
      sum += (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
    }
    return sum;
  }

  String _getOverallGrade(int studentId) {
    final stdResults = _resultsMap[studentId] ?? {};
    bool allAbsent = true;
    bool hasFail = false;

    for (var sb in widget.subjects) {
      final subId = sb['id'] as int;
      final r = stdResults[subId];
      final passM = (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;
      final isOpt = sb['is_optional'] == 1;

      if (r != null && r['is_absent'] == 1) {
        if (!isOpt) hasFail = true;
      } else if (r != null) {
        allAbsent = false;
        final obt = (r['marks_obtained'] as num?)?.toDouble() ?? 0.0;
        if (!isOpt && obt < passM) hasFail = true;
      } else {
        if (!isOpt) hasFail = true;
      }
    }

    if (allAbsent && widget.subjects.isNotEmpty) return 'غیر حاضر';

    final totalMax = _getTotalMaxMarks();
    final obt = _getStudentTotalObtained(studentId);
    final perc = totalMax > 0 ? (obt / totalMax) * 100 : 0;

    if (hasFail || perc < 40) return 'راسب';
    if (perc >= 80) return 'ممتاز';
    if (perc >= 70) return 'جید جدا';
    if (perc >= 50) return 'جید';
    return 'مقبول';
  }

  Color _getGradeColor(String grade) {
    switch (grade) {
      case 'ممتاز':
        return Colors.amber.shade800;
      case 'جید جدا':
        return Colors.blue.shade700;
      case 'جید':
        return Colors.teal.shade700;
      case 'مقبول':
        return Colors.green.shade700;
      default:
        return Colors.red.shade700;
    }
  }

  Future<void> _generateAndPrintPdf({bool singleStudentOnly = false}) async {
    setState(() => _isPrinting = true);
    try {
      final doc = pw.Document();
      final font = await PdfGoogleFonts.amiriBold();
      final regularFont = await PdfGoogleFonts.amiriRegular();

      final listToPrint = singleStudentOnly
          ? [widget.students[_selectedStudentIndex]]
          : widget.students;

      final totalMax = _getTotalMaxMarks();
      final courseName = widget.course?['name']?.toString() ?? 'درس نظامی';
      final courseType = widget.course?['course_type']?.toString() ?? 'Darse_Nizami';
      final darjahTitle = _getDarjahTitle(widget.yearNo, courseType);
      final examName = widget.exam?['exam_name']?.toString() ?? 'سالانہ امتحان';

      for (var std in listToPrint) {
        final sId = std['student_id'] as int;
        final sName = std['student_name']?.toString() ?? '';
        final fName = std['father_name']?.toString() ?? '-';
        final rollNo = std['course_roll'] ?? std['admission_number'] ?? '-';
        final admNo = std['admission_number']?.toString() ?? '-';

        final totalObt = _getStudentTotalObtained(sId);
        final perc = totalMax > 0 ? (totalObt / totalMax) * 100 : 0;
        final overallGrade = _getOverallGrade(sId);

        doc.addPage(
          pw.Page(
            pageFormat: PdfPageFormat.a4,
            margin: pw.EdgeInsets.only(
              top: _topMarginMm * PdfPageFormat.mm,
              bottom: _bottomMarginMm * PdfPageFormat.mm,
              left: _sidesMarginMm * PdfPageFormat.mm,
              right: _sidesMarginMm * PdfPageFormat.mm,
            ),
            build: (pw.Context ctx) {
              return pw.Directionality(
                textDirection: pw.TextDirection.rtl,
                child: pw.Container(
                  decoration: _isPreprintedMode
                      ? null
                      : pw.BoxDecoration(
                          border: pw.Border.all(color: PdfColor.fromHex('284D3D'), width: 3),
                        ),
                  padding: pw.EdgeInsets.all(_isPreprintedMode ? 0 : 14),
                  child: pw.Column(
                    crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                    children: [
                      // Header (only in Plain Paper mode)
                      if (!_isPreprintedMode) ...[
                        pw.Center(
                          child: pw.Text(
                            (currentTenantName.value ?? '').trim().isNotEmpty ? currentTenantName.value!.trim() : 'مدرسہ مینجمنٹ سسٹم',
                            style: pw.TextStyle(font: font, fontSize: 22 * _fontScale, color: PdfColor.fromHex('284D3D')),
                          ),
                        ),
                        pw.Center(
                          child: pw.Text(
                            'كشف الدرجات (Detailed Marks Certificate)',
                            style: pw.TextStyle(font: font, fontSize: 13 * _fontScale, color: PdfColor.fromHex('1E3C72')),
                          ),
                        ),
                        pw.Center(
                          child: pw.Text(
                            '$examName ($courseName - $darjahTitle)',
                            style: pw.TextStyle(font: regularFont, fontSize: 11 * _fontScale),
                          ),
                        ),
                        pw.SizedBox(height: 8),
                        pw.Divider(thickness: 1.5, color: PdfColor.fromHex('284D3D')),
                        pw.SizedBox(height: 8),
                      ] else ...[
                        // Pre-printed spacer
                        pw.SizedBox(height: 10),
                      ],

                      // Student Info Box
                      pw.Container(
                        decoration: pw.BoxDecoration(
                          border: pw.Border.all(color: PdfColor.fromHex('284D3D'), width: 1),
                          color: PdfColor.fromHex('F9F9F9'),
                        ),
                        padding: const pw.EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        child: pw.Row(
                          mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                          children: [
                            pw.Column(
                              crossAxisAlignment: pw.CrossAxisAlignment.start,
                              children: [
                                pw.Text('نام طالب علم: $sName', style: pw.TextStyle(font: font, fontSize: 11 * _fontScale)),
                                pw.Text('ولدیت: $fName', style: pw.TextStyle(font: regularFont, fontSize: 10 * _fontScale)),
                              ],
                            ),
                            pw.Column(
                              crossAxisAlignment: pw.CrossAxisAlignment.start,
                              children: [
                                pw.Text('رول نمبر: $rollNo', style: pw.TextStyle(font: font, fontSize: 11 * _fontScale)),
                                pw.Text('داخلہ نمبر: $admNo', style: pw.TextStyle(font: regularFont, fontSize: 10 * _fontScale)),
                              ],
                            ),
                            pw.Column(
                              crossAxisAlignment: pw.CrossAxisAlignment.start,
                              children: [
                                pw.Text('کورس: $courseName', style: pw.TextStyle(font: regularFont, fontSize: 10 * _fontScale)),
                                pw.Text('درجہ: $darjahTitle', style: pw.TextStyle(font: font, fontSize: 10 * _fontScale)),
                              ],
                            ),
                          ],
                        ),
                      ),
                      pw.SizedBox(height: 10),

                      // Subjects Table
                      pw.Table(
                        border: pw.TableBorder.all(color: PdfColor.fromHex('284D3D'), width: 0.8),
                        children: [
                          // Header Row
                          pw.TableRow(
                            decoration: pw.BoxDecoration(color: PdfColor.fromHex('284D3D')),
                            children: [
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Center(child: pw.Text('#', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale))),
                              ),
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Text('مضمون و کتاب', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale)),
                              ),
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Center(child: pw.Text('کل نمبر', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale))),
                              ),
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Center(child: pw.Text('پاسنگ', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale))),
                              ),
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Center(child: pw.Text('حاصل کردہ', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale))),
                              ),
                              pw.Padding(
                                padding: const pw.EdgeInsets.all(5),
                                child: pw.Center(child: pw.Text('درجہ', style: pw.TextStyle(font: font, color: PdfColors.white, fontSize: 10 * _fontScale))),
                              ),
                            ],
                          ),
                          // Subject Rows
                          ...widget.subjects.asMap().entries.map((entry) {
                            final idx = entry.key + 1;
                            final sb = entry.value;
                            final subId = sb['id'] as int;
                            final subName = sb['subject_name']?.toString() ?? '';
                            final bookName = sb['book_name']?.toString() ?? '';
                            final maxM = (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
                            final passM = (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;

                            final r = _resultsMap[sId]?[subId];
                            final isAbs = r?['is_absent'] == 1;
                            final obt = isAbs ? 0.0 : ((r?['marks_obtained'] as num?)?.toDouble() ?? 0.0);
                            final gr = isAbs ? 'غ/ح' : (r?['grade']?.toString() ?? 'راسب');

                            return pw.TableRow(
                              children: [
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Center(child: pw.Text('$idx', style: pw.TextStyle(font: regularFont, fontSize: 9 * _fontScale))),
                                ),
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Text(
                                    bookName.isNotEmpty ? '$subName ($bookName)' : subName,
                                    style: pw.TextStyle(font: regularFont, fontSize: 9.5 * _fontScale),
                                  ),
                                ),
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Center(child: pw.Text('${maxM.toInt()}', style: pw.TextStyle(font: regularFont, fontSize: 9.5 * _fontScale))),
                                ),
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Center(child: pw.Text('${passM.toInt()}', style: pw.TextStyle(font: regularFont, fontSize: 9.5 * _fontScale))),
                                ),
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Center(
                                    child: pw.Text(
                                      isAbs ? 'غیر حاضر' : (obt % 1 == 0 ? obt.toInt().toString() : obt.toStringAsFixed(1)),
                                      style: pw.TextStyle(font: font, fontSize: 9.5 * _fontScale, color: (obt < passM || isAbs) ? PdfColors.red : PdfColors.black),
                                    ),
                                  ),
                                ),
                                pw.Padding(
                                  padding: const pw.EdgeInsets.all(4),
                                  child: pw.Center(child: pw.Text(gr, style: pw.TextStyle(font: font, fontSize: 9.5 * _fontScale))),
                                ),
                              ],
                            );
                          }),
                        ],
                      ),
                      pw.SizedBox(height: 10),

                      // Totals & Islamic Grade Box
                      pw.Container(
                        decoration: pw.BoxDecoration(
                          border: pw.Border.all(color: PdfColor.fromHex('284D3D'), width: 1.2),
                          color: PdfColor.fromHex('F7F7F7'),
                        ),
                        padding: const pw.EdgeInsets.all(8),
                        child: pw.Row(
                          mainAxisAlignment: pw.MainAxisAlignment.spaceAround,
                          children: [
                            pw.Text('کل نمبر: ${totalMax.toInt()}', style: pw.TextStyle(font: font, fontSize: 11 * _fontScale)),
                            pw.Text('حاصل کردہ: ${totalObt.toStringAsFixed(1).replaceAll('.0', '')}', style: pw.TextStyle(font: font, fontSize: 11 * _fontScale, color: PdfColor.fromHex('284D3D'))),
                            pw.Text('فیصد: ${perc.toStringAsFixed(1)}%', style: pw.TextStyle(font: font, fontSize: 11 * _fontScale)),
                            pw.Text('درجہ: $overallGrade', style: pw.TextStyle(font: font, fontSize: 13 * _fontScale, color: PdfColor.fromHex('1E3C72'))),
                          ],
                        ),
                      ),
                      pw.Spacer(),

                      // Signatures Footer
                      pw.Padding(
                        padding: const pw.EdgeInsets.symmetric(horizontal: 10),
                        child: pw.Row(
                          mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                          children: [
                            pw.Column(
                              children: [
                                pw.Container(width: 100, height: 1, color: PdfColor.fromHex('284D3D')),
                                pw.SizedBox(height: 3),
                                pw.Text('دستخط نگرانِ امتحانات', style: pw.TextStyle(font: font, fontSize: 9.5 * _fontScale)),
                              ],
                            ),
                            pw.Column(
                              children: [
                                pw.Container(width: 100, height: 1, color: PdfColor.fromHex('284D3D')),
                                pw.SizedBox(height: 3),
                                pw.Text('دستخط مہتمم / صدر مدرس', style: pw.TextStyle(font: font, fontSize: 9.5 * _fontScale)),
                              ],
                            ),
                            pw.Column(
                              children: [
                                pw.Container(width: 100, height: 1, color: PdfColor.fromHex('284D3D')),
                                pw.SizedBox(height: 3),
                                pw.Text('دستخط سرپرست / والد', style: pw.TextStyle(font: font, fontSize: 9.5 * _fontScale)),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        );
      }

      await Printing.layoutPdf(
        onLayout: (format) async => doc.save(),
        name: 'academic_result_card_${widget.yearNo}.pdf',
      );
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('پرنٹنگ میں خرابی: $e'), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _isPrinting = false);
    }
  }

  void _showMarginSettingsModal() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(
        builder: (context, setModalState) {
          return Directionality(
            textDirection: ui.TextDirection.rtl,
            child: Padding(
              padding: const EdgeInsets.all(20.0),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Row(
                          children: [
                            Icon(Icons.tune, color: Color(0xFF1E3C72)),
                            SizedBox(width: 8),
                            Text('مارجنز و لے آؤٹ ایڈجسٹمنٹ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                          ],
                        ),
                        IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                      ],
                    ),
                    const Divider(),

                    // Preprinted Switch
                    SwitchListTile(
                      title: const Text('پری پرنٹڈ لیٹر پیڈ / سند موڈ', style: TextStyle(fontWeight: FontWeight.bold)),
                      subtitle: const Text('ہیڈر، ادارہ کا نام اور فریم غائب کریں تاکہ پہلے سے چھپے پیڈ پر پرنٹ ہو سکے'),
                      value: _isPreprintedMode,
                      onChanged: (val) {
                        setState(() => _isPreprintedMode = val);
                        setModalState(() {});
                      },
                    ),

                    // Top Margin
                    Text('اوپر کا مارجن (Top Margin): ${_topMarginMm.toInt()} ملی میٹر', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    Slider(
                      min: 0,
                      max: 120,
                      value: _topMarginMm,
                      divisions: 24,
                      label: '${_topMarginMm.toInt()} mm',
                      onChanged: (val) {
                        setState(() => _topMarginMm = val);
                        setModalState(() {});
                      },
                    ),

                    // Bottom Margin
                    Text('نیچے کا مارجن (Bottom Margin): ${_bottomMarginMm.toInt()} ملی میٹر', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    Slider(
                      min: 0,
                      max: 80,
                      value: _bottomMarginMm,
                      divisions: 16,
                      label: '${_bottomMarginMm.toInt()} mm',
                      onChanged: (val) {
                        setState(() => _bottomMarginMm = val);
                        setModalState(() {});
                      },
                    ),

                    // Side Margins
                    Text('دائیں اور بائیں سائیڈز مارجن: ${_sidesMarginMm.toInt()} ملی میٹر', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    Slider(
                      min: 0,
                      max: 40,
                      value: _sidesMarginMm,
                      divisions: 8,
                      label: '${_sidesMarginMm.toInt()} mm',
                      onChanged: (val) {
                        setState(() => _sidesMarginMm = val);
                        setModalState(() {});
                      },
                    ),

                    // Font Scale
                    Text('فونٹ کا سائز (Scale): ${(_fontScale * 100).toInt()}%', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    Slider(
                      min: 0.8,
                      max: 1.2,
                      value: _fontScale,
                      divisions: 8,
                      label: '${(_fontScale * 100).toInt()}%',
                      onChanged: (val) {
                        setState(() => _fontScale = val);
                        setModalState(() {});
                      },
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          title: Text(
            isUrdu ? 'نتیجہ کارڈ (کشف الدرجات DMC)' : 'Detailed Marks Certificate',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17, color: Colors.white),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          elevation: 2,
          actions: [
            IconButton(
              icon: const Icon(Icons.tune, color: Colors.white),
              tooltip: isUrdu ? 'مارجنز ایڈجسٹ کریں' : 'Margins',
              onPressed: _showMarginSettingsModal,
            ),
            IconButton(
              icon: const Icon(Icons.print, color: Colors.white),
              tooltip: isUrdu ? 'موجودہ کارڈ پرنٹ کریں' : 'Print Card',
              onPressed: () => _generateAndPrintPdf(singleStudentOnly: true),
            ),
            IconButton(
              icon: const Icon(Icons.print_outlined, color: Colors.white),
              tooltip: isUrdu ? 'تمام طلبہ کے کارڈز پرنٹ کریں' : 'Print All',
              onPressed: () => _generateAndPrintPdf(singleStudentOnly: false),
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator(color: Colors.white))
            : widget.students.isEmpty
                ? const Center(child: Text('کوئی طالب علم موجود نہیں ہے', style: TextStyle(color: Colors.white)))
                : Column(
                    children: [
                      // Student Selector Slider / Chips
                      Container(
                        color: Colors.white,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        child: Row(
                          children: [
                            Text(isUrdu ? 'طالب علم: ' : 'Student: ', style: const TextStyle(fontWeight: FontWeight.bold)),
                            const SizedBox(width: 8),
                            Expanded(
                              child: SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                child: Row(
                                  children: widget.students.asMap().entries.map((entry) {
                                    final idx = entry.key;
                                    final std = entry.value;
                                    final isSelected = idx == _selectedStudentIndex;
                                    return Padding(
                                      padding: const EdgeInsets.only(left: 6),
                                      child: ChoiceChip(
                                        label: Text(std['student_name']?.toString() ?? ''),
                                        selected: isSelected,
                                        selectedColor: primaryGreen,
                                        labelStyle: TextStyle(
                                          color: isSelected ? Colors.white : Colors.black87,
                                          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                                          fontSize: 12,
                                        ),
                                        onSelected: (selected) {
                                          if (selected) setState(() => _selectedStudentIndex = idx);
                                        },
                                      ),
                                    );
                                  }).toList(),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),

                      // Interactive Live Preview Card (On-Screen Representation)
                      Expanded(
                        child: Center(
                          child: SingleChildScrollView(
                            padding: const EdgeInsets.all(16),
                            child: _buildInteractivePreviewCard(),
                          ),
                        ),
                      ),
                    ],
                  ),
        bottomNavigationBar: Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: SafeArea(
            child: Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    onPressed: _isPrinting ? null : () => _generateAndPrintPdf(singleStudentOnly: true),
                    icon: const Icon(Icons.print),
                    label: Text(
                      isUrdu ? 'یہ کارڈ پرنٹ کریں' : 'Print This Card',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: accentBlue,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    onPressed: _isPrinting ? null : () => _generateAndPrintPdf(singleStudentOnly: false),
                    icon: const Icon(Icons.all_inclusive),
                    label: Text(
                      isUrdu ? 'تمام کارڈز پرنٹ کریں' : 'Print All Cards',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildInteractivePreviewCard() {
    final std = widget.students[_selectedStudentIndex];
    final sId = std['student_id'] as int;
    final sName = std['student_name']?.toString() ?? '';
    final fName = std['father_name']?.toString() ?? '-';
    final rollNo = std['course_roll'] ?? std['admission_number'] ?? '-';
    final admNo = std['admission_number']?.toString() ?? '-';

    final courseName = widget.course?['name']?.toString() ?? 'درس نظامی';
    final courseType = widget.course?['course_type']?.toString() ?? 'Darse_Nizami';
    final darjahTitle = _getDarjahTitle(widget.yearNo, courseType);
    final examName = widget.exam?['exam_name']?.toString() ?? 'سالانہ امتحان';

    final totalMax = _getTotalMaxMarks();
    final totalObt = _getStudentTotalObtained(sId);
    final perc = totalMax > 0 ? (totalObt / totalMax) * 100 : 0;
    final overallGrade = _getOverallGrade(sId);
    final gradeColor = _getGradeColor(overallGrade);

    return Container(
      width: 500,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 15, offset: Offset(0, 5))],
      ),
      padding: EdgeInsets.only(
        top: _topMarginMm * 1.5,
        bottom: _bottomMarginMm * 1.5,
        left: _sidesMarginMm * 1.5,
        right: _sidesMarginMm * 1.5,
      ),
      child: Container(
        decoration: _isPreprintedMode
            ? null
            : BoxDecoration(
                border: Border.all(color: primaryGreen, width: 3),
              ),
        padding: EdgeInsets.all(_isPreprintedMode ? 4 : 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!_isPreprintedMode) ...[
              Center(
                child: Text(
                  (currentTenantName.value ?? '').trim().isNotEmpty ? currentTenantName.value!.trim() : 'مدرسہ مینجمنٹ سسٹم',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20 * _fontScale, color: primaryGreen),
                ),
              ),
              Center(
                child: Text(
                  'كشف الدرجات (Detailed Marks Certificate)',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13 * _fontScale, color: accentBlue),
                ),
              ),
              Center(
                child: Text(
                  '$examName ($courseName - $darjahTitle)',
                  style: TextStyle(fontSize: 11 * _fontScale, color: Colors.grey.shade700),
                ),
              ),
              const SizedBox(height: 8),
              Divider(thickness: 1.5, color: primaryGreen),
              const SizedBox(height: 8),
            ] else ...[
              Container(
                padding: const EdgeInsets.all(6),
                color: Colors.amber.shade50,
                child: const Text(
                  'پری پرنٹڈ لیٹر پیڈ موڈ فعال ہے (ہیڈر اور بارڈرز پرنٹ میں مخفی رہیں گے)',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 10, color: Colors.brown, fontWeight: FontWeight.bold),
                ),
              ),
              const SizedBox(height: 8),
            ],

            // Student Info Banner
            Container(
              decoration: BoxDecoration(
                color: Colors.grey.shade50,
                border: Border.all(color: primaryGreen, width: 1),
                borderRadius: BorderRadius.circular(6),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('نام: $sName', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12 * _fontScale)),
                      Text('ولدیت: $fName', style: TextStyle(fontSize: 11 * _fontScale, color: Colors.grey.shade700)),
                    ],
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('رول نمبر: $rollNo', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12 * _fontScale)),
                      Text('داخلہ نمبر: $admNo', style: TextStyle(fontSize: 11 * _fontScale, color: Colors.grey.shade700)),
                    ],
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('کورس: $courseName', style: TextStyle(fontSize: 11 * _fontScale, color: Colors.grey.shade700)),
                      Text('درجہ: $darjahTitle', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11 * _fontScale)),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),

            // Subjects Table
            Table(
              border: TableBorder.all(color: primaryGreen, width: 1),
              columnWidths: const {
                0: FixedColumnWidth(28),
                1: FlexColumnWidth(3),
                2: FixedColumnWidth(55),
                3: FixedColumnWidth(55),
                4: FixedColumnWidth(65),
                5: FixedColumnWidth(60),
              },
              children: [
                TableRow(
                  decoration: BoxDecoration(color: primaryGreen),
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Center(child: Text('#', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale))),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Text('مضمون / کتاب', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale)),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Center(child: Text('کل نمبر', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale))),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Center(child: Text('پاسنگ', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale))),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Center(child: Text('حاصل کردہ', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale))),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(5),
                      child: Center(child: Text('درجہ', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11 * _fontScale))),
                    ),
                  ],
                ),
                ...widget.subjects.asMap().entries.map((entry) {
                  final idx = entry.key + 1;
                  final sb = entry.value;
                  final subId = sb['id'] as int;
                  final subName = sb['subject_name']?.toString() ?? '';
                  final bookName = sb['book_name']?.toString() ?? '';
                  final maxM = (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
                  final passM = (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;

                  final r = _resultsMap[sId]?[subId];
                  final isAbs = r?['is_absent'] == 1;
                  final obt = isAbs ? 0.0 : ((r?['marks_obtained'] as num?)?.toDouble() ?? 0.0);
                  final gr = isAbs ? 'غ/ح' : (r?['grade']?.toString() ?? 'راسب');

                  return TableRow(
                    children: [
                      Padding(padding: const EdgeInsets.all(4), child: Center(child: Text('$idx', style: TextStyle(fontSize: 10 * _fontScale)))),
                      Padding(
                        padding: const EdgeInsets.all(4),
                        child: Text(
                          bookName.isNotEmpty ? '$subName ($bookName)' : subName,
                          style: TextStyle(fontSize: 10 * _fontScale, fontWeight: FontWeight.w600),
                        ),
                      ),
                      Padding(padding: const EdgeInsets.all(4), child: Center(child: Text('${maxM.toInt()}', style: TextStyle(fontSize: 10 * _fontScale)))),
                      Padding(padding: const EdgeInsets.all(4), child: Center(child: Text('${passM.toInt()}', style: TextStyle(fontSize: 10 * _fontScale)))),
                      Padding(
                        padding: const EdgeInsets.all(4),
                        child: Center(
                          child: Text(
                            isAbs ? 'غ/ح' : (obt % 1 == 0 ? obt.toInt().toString() : obt.toStringAsFixed(1)),
                            style: TextStyle(
                              fontSize: 10 * _fontScale,
                              fontWeight: FontWeight.bold,
                              color: (obt < passM || isAbs) ? Colors.red : primaryGreen,
                            ),
                          ),
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.all(4),
                        child: Center(
                          child: Text(
                            gr,
                            style: TextStyle(fontSize: 10 * _fontScale, fontWeight: FontWeight.bold, color: _getGradeColor(gr)),
                          ),
                        ),
                      ),
                    ],
                  );
                }),
              ],
            ),
            const SizedBox(height: 10),

            // Summary Numbers Box
            Container(
              decoration: BoxDecoration(
                color: Colors.grey.shade50,
                border: Border.all(color: primaryGreen, width: 1.2),
                borderRadius: BorderRadius.circular(6),
              ),
              padding: const EdgeInsets.all(8),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  Text('کل نمبر: ${totalMax.toInt()}', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11 * _fontScale)),
                  Text(
                    'حاصل کردہ: ${totalObt.toStringAsFixed(1).replaceAll('.0', '')}',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11 * _fontScale, color: primaryGreen),
                  ),
                  Text('فیصد: ${perc.toStringAsFixed(1)}%', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 11 * _fontScale)),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: gradeColor.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: gradeColor),
                    ),
                    child: Text(
                      overallGrade,
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12 * _fontScale, color: gradeColor),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 25),

            // Signatures
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  children: [
                    Container(width: 90, height: 1, color: primaryGreen),
                    const SizedBox(height: 3),
                    Text('نگرانِ امتحانات', style: TextStyle(fontSize: 9.5 * _fontScale, fontWeight: FontWeight.bold)),
                  ],
                ),
                Column(
                  children: [
                    Container(width: 90, height: 1, color: primaryGreen),
                    const SizedBox(height: 3),
                    Text('صدر مدرس / مہتمم', style: TextStyle(fontSize: 9.5 * _fontScale, fontWeight: FontWeight.bold)),
                  ],
                ),
                Column(
                  children: [
                    Container(width: 90, height: 1, color: primaryGreen),
                    const SizedBox(height: 3),
                    Text('سرپرست / والد', style: TextStyle(fontSize: 9.5 * _fontScale, fontWeight: FontWeight.bold)),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
