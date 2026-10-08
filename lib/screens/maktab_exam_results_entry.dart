// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import 'dart:ui' as ui;
import 'dart:convert';
import '../widgets/app_drawer.dart';

import '../utils/sync_ui_helper.dart';
import 'dashboard.dart';
import 'result_register_mobile.dart';

class MaktabExamResultsEntryPage extends StatefulWidget {
  final Map<String, dynamic> exam;
  const MaktabExamResultsEntryPage({super.key, required this.exam});

  @override
  State<MaktabExamResultsEntryPage> createState() => _MaktabExamResultsEntryPageState();
}

class _MaktabExamResultsEntryPageState extends State<MaktabExamResultsEntryPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _maktabStudents = [];
  List<Map<String, dynamic>> _maktabClasses = [];
  Map<int, Map<String, dynamic>> _results = {};
  bool _isLoading = true;
  bool _gradeView = false;
  Map<String, dynamic>? _selectedClassData;
  bool _isHifzClass = false;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    final db = DBHelper();
    final maktabClasses = await db.getMaktabClassesForUser();
    final maktabStudents = await db.getMaktabStudentsForUser();
    final existingResults = await db.getExamResults(widget.exam['id']);

    Map<int, Map<String, dynamic>> resultsMap = {};
    for (var res in existingResults) {
      resultsMap[res['student_id']] = {
        'marks': res['marks_obtained'].toString(),
        'total': res['total_marks'].toString(),
        'status': res['status'] ?? 'Pass',
        'pukhtagi': res['pukhtagi']?.toString() ?? '0',
        'tajweed': res['tajweed']?.toString() ?? '0',
        'lahja': res['lahja']?.toString() ?? '0',
        'masail': res['masail']?.toString() ?? '0',
        'miqdar': res['miqdar_khwandgi']?.toString() ?? '',
        'p1': res['p1']?.toString() ?? '0',
        'p2': res['p2']?.toString() ?? '0',
        'p3': res['p3']?.toString() ?? '0',
        'p4': res['p4']?.toString() ?? '0',
        'p5': res['p5']?.toString() ?? '0',
        'p6': res['p6']?.toString() ?? '0',
        'grade': res['grade'] ?? '',
        'remarks': res['remarks'] ?? '',
        'subjects_marks': res['subjects_marks']?.toString() ?? '',
      };
    }

    setState(() {
      _maktabClasses = maktabClasses;
      _maktabStudents = maktabStudents;
      _results = resultsMap;
      _isLoading = false;
    });
  }

  void _updateClassFormat(Map<String, dynamic>? classData) {
    if (classData == null) return;
    setState(() {
      _selectedClassData = classData;
      _isHifzClass = (classData['class_type'] == 'Hifz' ||
          classData['name'].toString().contains('حفظ') ||
          classData['name'].toString().contains('گردان') ||
          classData['name'].toString().contains('حسن صوت') ||
          classData['name'].toString().contains('ناظرہ'));
    });
  }

  Future<void> _saveResult(int studentId, String marks, String total,
      String status, Map<String, dynamic> data) async {
    await DBHelper().insertExamResult({
      'exam_id': widget.exam['id'],
      'student_id': studentId,
      'subject': _isHifzClass ? 'Quran' : 'Final',
      'marks_obtained': double.tryParse(marks) ?? 0,
      'total_marks': double.tryParse(total) ?? (_isHifzClass ? 100 : 600),
      'status': status,
      'pukhtagi': int.tryParse(data['pukhtagi'] ?? '0') ?? 0,
      'tajweed': int.tryParse(data['tajweed'] ?? '0') ?? 0,
      'lahja': int.tryParse(data['lahja'] ?? '0') ?? 0,
      'masail': int.tryParse(data['masail'] ?? '0') ?? 0,
      'miqdar_khwandgi': data['miqdar'] ?? '',
      'p1': int.tryParse(data['p1'] ?? '0') ?? 0,
      'p2': int.tryParse(data['p2'] ?? '0') ?? 0,
      'p3': int.tryParse(data['p3'] ?? '0') ?? 0,
      'p4': int.tryParse(data['p4'] ?? '0') ?? 0,
      'p5': int.tryParse(data['p5'] ?? '0') ?? 0,
      'p6': int.tryParse(data['p6'] ?? '0') ?? 0,
      'subjects_marks': data['subjects_marks'] ?? '',
      'grade': data['grade'] ?? '',
      'remarks': data['remarks'] ?? '',
      'is_synced': 0,
    });
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return PopScope(
          canPop: false,
          onPopInvokedWithResult: (didPop, result) {
            if (didPop) return;
            if (Navigator.of(context).canPop()) {
              Navigator.of(context).pop();
            } else {
              Navigator.pushReplacement(context,
                  MaterialPageRoute(builder: (c) => const DashboardPage()));
            }
          },
          child: Directionality(
            textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
            child: Scaffold(
              backgroundColor: bgColor,
              appBar: AppBar(
                title: Text(isUrdu ? "رزلٹ انٹری" : "Result Entry",
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.list_alt, color: Colors.white),
                    tooltip: isUrdu ? "رجسٹر دیکھیں" : "View Register",
                    onPressed: () {
                      Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (c) =>
                                  ResultRegisterMobilePage(exam: widget.exam)));
                    },
                  ),
                  IconButton(
                    icon: Icon(_gradeView ? Icons.list : Icons.layers,
                        color: Colors.white),
                    onPressed: () => setState(() => _gradeView = !_gradeView),
                  ),
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () async {
                      setState(() => _isLoading = true);
                      await SyncUIHelper.performManualSync(context);
                      _loadData();
                    },
                  ),
                ],
              ),
              drawer: const AppDrawer(),
              body: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _buildMainContent(isUrdu),
            ),
          ),
        );
      },
    );
  }

  Widget _buildMainContent(bool isUrdu) {
    final filteredStudents = _selectedClassData == null
        ? _maktabStudents
        : _maktabStudents
            .where((s) => s['class_name'] == _selectedClassData!['name'])
            .toList();

    List<Map<String, dynamic>> dynSubjects = [];
    if (_selectedClassData != null && _selectedClassData!['subjects_list'] != null && _selectedClassData!['subjects_list'].toString().trim().isNotEmpty) {
      final parts = _selectedClassData!['subjects_list'].toString().split(',');
      for (var p in parts) {
        final sp = p.split(':');
        if (sp.isNotEmpty && sp[0].trim().isNotEmpty) {
          dynSubjects.add({
            'name': sp[0].trim(),
            'max': sp.length > 1 ? (double.tryParse(sp[1].trim()) ?? 100) : 100,
          });
        }
      }
    }

    return Column(
      children: [
        Container(
          padding: const EdgeInsets.all(10),
          color: Colors.white,
          child: DropdownButtonFormField<String>(
            value: _selectedClassData?['name'],
            decoration: InputDecoration(
              isDense: true,
              labelText: isUrdu ? "کلاس منتخب کریں" : "Select Class",
              border: const OutlineInputBorder(),
            ),
            items:
                _maktabClasses.map((c) => c['name'].toString()).toSet().map((name) {
              return DropdownMenuItem<String>(value: name, child: Text(name));
            }).toList(),
            onChanged: (val) {
              final selected = _maktabClasses.firstWhere(
                (c) => c['name'] == val,
                orElse: () => _maktabClasses.isNotEmpty ? _maktabClasses.first : {},
              );
              if (selected.isNotEmpty) _updateClassFormat(selected);
            },
          ),
        ),
        if (_gradeView)
          Expanded(child: _buildGradeView(isUrdu, filteredStudents))
        else
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildTableHeader(isUrdu, dynSubjects),
                    ...List.generate(filteredStudents.length, (index) {
                      final student = filteredStudents[index];
                      final res = _results[student['id']] ??
                          {
                            'marks': '',
                            'total': _isHifzClass ? '100' : '600',
                            'status': 'Pass',
                            'pukhtagi': '0',
                            'tajweed': '0',
                            'lahja': '0',
                            'masail': '0',
                            'miqdar': '',
                            'p1': '0',
                            'p2': '0',
                            'p3': '0',
                            'p4': '0',
                            'p5': '0',
                            'p6': '0',
                            'grade': '',
                            'remarks': ''
                          };
                      return _buildTableRow(index + 1, student, dynSubjects, res, isUrdu);
                    }),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildTableHeader(bool isUrdu, List<Map<String, dynamic>> dynSubjects) {
    return Container(
      color: primaryGreen,
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          _buildHeaderCell(isUrdu ? "شمار" : "Sr", 40),
          _buildHeaderCell(isUrdu ? "طالب علم" : "Student", 130),
          if (_isHifzClass) ...[
            _buildHeaderCell(isUrdu ? "مقدارِ خواندگی" : "Miqdar", 220),
            _buildHeaderCell(isUrdu ? "پختگی" : "Pukht", 55),
            _buildHeaderCell(isUrdu ? "تلفظ" : "Talafuz", 55),
            _buildHeaderCell(isUrdu ? "لہجہ" : "Lahja", 55),
            _buildHeaderCell(isUrdu ? "نماز" : "Namaz", 55),
          ] else if (dynSubjects.isNotEmpty) ...[
            for (var sub in dynSubjects) _buildHeaderCell(sub['name'], 60),
          ] else ...[
            _buildHeaderCell(_selectedClassData?['p1_name'] ?? "P1", 60),
            _buildHeaderCell(_selectedClassData?['p2_name'] ?? "P2", 60),
            _buildHeaderCell(_selectedClassData?['p3_name'] ?? "P3", 60),
            _buildHeaderCell(_selectedClassData?['p4_name'] ?? "P4", 60),
            _buildHeaderCell(_selectedClassData?['p5_name'] ?? "P5", 60),
            _buildHeaderCell(_selectedClassData?['p6_name'] ?? "P6", 60),
          ],
          _buildHeaderCell(isUrdu ? "حاصل" : "Obt", 60),
          _buildHeaderCell(isUrdu ? "گریڈ" : "Grd", 50),
          _buildHeaderCell(isUrdu ? "کیفیت" : "Stat", 80),
          _buildHeaderCell(isUrdu ? "ایکشن" : "Act", 60),
        ],
      ),
    );
  }

  Widget _buildHeaderCell(String text, double width) {
    return SizedBox(
        width: width,
        child: Text(text,
            textAlign: TextAlign.center,
            style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
                fontSize: 10)));
  }

  Widget _buildTableRow(int sr, Map<String, dynamic> student, List<Map<String, dynamic>> dynSubjects,
      Map<String, dynamic> result, bool isUrdu) {
    final pukhtagi = TextEditingController(text: result['pukhtagi']);
    final tajweed = TextEditingController(text: result['tajweed']);
    final lahja = TextEditingController(text: result['lahja']);
    final masail = TextEditingController(text: result['masail']);
    final miqdar = TextEditingController(text: result['miqdar']);
    final p1 = TextEditingController(text: result['p1']);
    final p2 = TextEditingController(text: result['p2']);
    final p3 = TextEditingController(text: result['p3']);
    final p4 = TextEditingController(text: result['p4']);
    final p5 = TextEditingController(text: result['p5']);
    final p6 = TextEditingController(text: result['p6']);
    final obt = TextEditingController(text: result['marks']);

    Map<String, dynamic> parsedMarks = {};
    if (result['subjects_marks'] != null && result['subjects_marks'].toString().isNotEmpty) {
      try {
        parsedMarks = jsonDecode(result['subjects_marks'].toString());
      } catch (_) {}
    }

    Map<String, TextEditingController> dynCtrls = {};
    for (var sub in dynSubjects) {
      dynCtrls[sub['name']] = TextEditingController(text: parsedMarks[sub['name']]?.toString() ?? '0');
    }

    void updateObt() {
      double total = 0;
      if (_isHifzClass) {
        total = (double.tryParse(pukhtagi.text) ?? 0) +
            (double.tryParse(tajweed.text) ?? 0) +
            (double.tryParse(lahja.text) ?? 0) +
            (double.tryParse(masail.text) ?? 0);
      } else if (dynSubjects.isNotEmpty) {
        for (var sub in dynSubjects) {
          total += double.tryParse(dynCtrls[sub['name']]!.text) ?? 0;
        }
      } else {
        total = (double.tryParse(p1.text) ?? 0) +
            (double.tryParse(p2.text) ?? 0) +
            (double.tryParse(p3.text) ?? 0) +
            (double.tryParse(p4.text) ?? 0) +
            (double.tryParse(p5.text) ?? 0) +
            (double.tryParse(p6.text) ?? 0);
      }
      obt.text = total.toStringAsFixed(0);
    }

    return Container(
      decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: Colors.grey.shade300))),
      child: Row(
        children: [
          _buildDataCell(sr.toString(), 40),
          Container(
            width: 130,
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(student['name'] ?? '',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 11)),
                Text(student['father_name'] ?? '',
                    style: TextStyle(fontSize: 9, color: Colors.grey.shade600)),
              ],
            ),
          ),
          if (_isHifzClass) ...[
            _buildInputCell(miqdar, 220, hint: 'پارہ نمبر 3 سے 11...'),
            _buildInputCell(pukhtagi, 55, onChanged: updateObt),
            _buildInputCell(tajweed, 55, onChanged: updateObt),
            _buildInputCell(lahja, 55, onChanged: updateObt),
            _buildInputCell(masail, 55, onChanged: updateObt),
          ] else if (dynSubjects.isNotEmpty) ...[
            for (var sub in dynSubjects) _buildInputCell(dynCtrls[sub['name']]!, 60, onChanged: updateObt),
          ] else ...[
            _buildInputCell(p1, 60, onChanged: updateObt),
            _buildInputCell(p2, 60, onChanged: updateObt),
            _buildInputCell(p3, 60, onChanged: updateObt),
            _buildInputCell(p4, 60, onChanged: updateObt),
            _buildInputCell(p5, 60, onChanged: updateObt),
            _buildInputCell(p6, 60, onChanged: updateObt),
          ],
          _buildDataCell(obt.text, 60, bold: true),
          _buildDataCell(
              _calculateGrade(
                  double.tryParse(obt.text) ?? 0, _isHifzClass ? 100 : 600),
              50),
          SizedBox(
            width: 80,
            child: DropdownButton<String>(
              isExpanded: true,
              value: (result['status'] == 'Pass' ||
                      result['status'] == 'Fail' ||
                      result['status'] == 'Grace Pass' ||
                      result['status'] == 'Absent')
                  ? result['status']
                  : 'Pass',
              style: const TextStyle(fontSize: 10, color: Colors.black),
              underline: const SizedBox(),
              items: [
                DropdownMenuItem(
                    value: 'Pass', child: Text(isUrdu ? "پاس" : "Pass")),
                DropdownMenuItem(
                    value: 'Grace Pass',
                    child: Text(isUrdu ? "رعایتی" : "Grace")),
                DropdownMenuItem(
                    value: 'Fail', child: Text(isUrdu ? "فیل" : "Fail")),
                DropdownMenuItem(
                    value: 'Absent',
                    child: Text(isUrdu ? "غیر حاضر" : "Absent")),
              ],
              onChanged: (val) {
                if (val == null) return;
                setState(() {
                  _results[student['id']]!['status'] = val;
                  if (val == 'Absent') {
                    pukhtagi.text = '0';
                    tajweed.text = '0';
                    lahja.text = '0';
                    masail.text = '0';
                    p1.text = '0';
                    p2.text = '0';
                    p3.text = '0';
                    p4.text = '0';
                    p5.text = '0';
                    p6.text = '0';
                    obt.text = '0';
                    _results[student['id']]!['grade'] = 'Absent';
                  }
                });
              },
            ),
          ),
          IconButton(
            icon: const Icon(Icons.save, color: Colors.green, size: 20),
            onPressed: () async {
              String status = result['status'] ?? 'Pass';
              if (status != 'Absent') {
                updateObt();
              } else {
                obt.text = '0';
              }

              double obtVal = double.tryParse(obt.text) ?? 0;
              double totalVal = _isHifzClass ? 100 : 600;
              if (!_isHifzClass && dynSubjects.isNotEmpty) {
                totalVal = 0;
                for (var sub in dynSubjects) {
                  totalVal += sub['max'] ?? 100;
                }
              }

              String dynMarksJson = '';
              if (!_isHifzClass && dynSubjects.isNotEmpty) {
                Map<String, dynamic> m = {};
                for (var sub in dynSubjects) {
                  m[sub['name']] = dynCtrls[sub['name']]!.text;
                }
                dynMarksJson = jsonEncode(m);
              }

              String grade = (status == 'Absent')
                  ? 'Absent'
                  : _calculateGrade(obtVal, totalVal);

              await _saveResult(
                  student['id'], obt.text, totalVal.toString(), status, {
                'pukhtagi': pukhtagi.text,
                'tajweed': tajweed.text,
                'lahja': lahja.text,
                'masail': masail.text,
                'miqdar': miqdar.text,
                'p1': p1.text,
                'p2': p2.text,
                'p3': p3.text,
                'p4': p4.text,
                'p5': p5.text,
                'p6': p6.text,
                'subjects_marks': dynMarksJson,
                'grade': grade,
                'remarks': ''
              });
              _loadData();
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text("محفوظ ہو گیا")));
              }
            },
          ),
        ],
      ),
    );
  }

  Widget _buildDataCell(String text, double width, {bool bold = false}) {
    return SizedBox(
        width: width,
        child: Text(text,
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 10,
                fontWeight: bold ? FontWeight.bold : FontWeight.normal)));
  }

  Widget _buildInputCell(TextEditingController ctrl, double width,
      {String? hint, VoidCallback? onChanged}) {
    return Container(
      width: width,
      padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 4),
      child: TextField(
        controller: ctrl,
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 10),
        decoration: InputDecoration(
            isDense: true,
            contentPadding: const EdgeInsets.all(6),
            border: const OutlineInputBorder(),
            hintText: hint),
        onChanged: (_) => onChanged?.call(),
      ),
    );
  }

  String _calculateGrade(double obt, double total) {
    if (total == 0) return 'F';
    double perc = (obt / total) * 100;
    if (perc >= 90) return 'A+';
    if (perc >= 80) return 'A';
    if (perc >= 70) return 'B';
    if (perc >= 60) return 'C';
    if (perc >= 50) return 'D';
    if (perc >= 40) return 'E';
    return 'F';
  }

  Widget _buildGradeView(bool isUrdu, List<Map<String, dynamic>> filtered) {
    final gradeOrder = ['A+', 'A', 'B', 'C', 'D', 'E', 'F'];
    return ListView(
      children: gradeOrder.map((g) {
        final maktabStudentsInGrade = filtered
            .where((s) => (_results[s['id']]?['grade'] ?? 'F') == g)
            .toList();
        if (maktabStudentsInGrade.isEmpty) return const SizedBox();
        return Card(
          margin: const EdgeInsets.all(8),
          child: Column(
            children: [
              ListTile(
                  title: Text(isUrdu ? "گریڈ $g" : "Grade $g",
                      style: const TextStyle(fontWeight: FontWeight.bold))),
              ...maktabStudentsInGrade.map((s) => ListTile(
                  dense: true,
                  title: Text(s['name']),
                  trailing: Text(_results[s['id']]?['marks'] ?? '0'))),
            ],
          ),
        );
      }).toList(),
    );
  }
}
