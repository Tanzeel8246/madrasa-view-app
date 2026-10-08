// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import 'package:intl/intl.dart' as intl;

class SubjectResultsEntryPage extends StatefulWidget {
  final Map<String, dynamic>? initialExam;
  const SubjectResultsEntryPage({super.key, this.initialExam});

  @override
  State<SubjectResultsEntryPage> createState() => _SubjectResultsEntryPageState();
}

class _SubjectResultsEntryPageState extends State<SubjectResultsEntryPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color secondaryGreen = const Color(0xFF3B6E57);
  final Color bgColor = const Color(0xFFF8F9FA);

  List<Map<String, dynamic>> _exams = [];
  List<Map<String, dynamic>> _courses = [];
  List<Map<String, dynamic>> _subjects = [];
  List<Map<String, dynamic>> _students = [];

  int? _selectedExamId;
  int? _selectedCourseId;
  int _selectedYearNo = 1;

  // [student_id][subject_id] => controller
  final Map<int, Map<int, TextEditingController>> _markControllers = {};
  // [student_id][subject_id] => bool isAbsent
  final Map<int, Map<int, bool>> _absentMap = {};

  bool _isLoading = true;
  bool _isSaving = false;

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
    _loadInitialData();
  }

  @override
  void dispose() {
    for (var m in _markControllers.values) {
      for (var c in m.values) {
        c.dispose();
      }
    }
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final exams = await db.getExams();
    final courses = await db.getCourses(isActive: 1);

    setState(() {
      _exams = exams;
      _courses = courses;

      if (widget.initialExam != null) {
        _selectedExamId = widget.initialExam!['id'] as int?;
      } else if (_exams.isNotEmpty) {
        _selectedExamId = _exams.first['id'] as int?;
      }

      if (_courses.isNotEmpty) {
        _selectedCourseId = _courses.first['id'] as int?;
      }
    });

    await _loadGridData();
  }

  Future<void> _loadGridData() async {
    if (_selectedCourseId == null) {
      setState(() => _isLoading = false);
      return;
    }

    setState(() => _isLoading = true);
    final db = DBHelper();

    // 1. Fetch Subjects for selected course and year
    final subs = await db.getCourseSubjects(_selectedCourseId!, yearNo: _selectedYearNo);

    // 2. Fetch Students enrolled in this course and year
    final enrollments = await db.getCourseEnrollments(
      courseId: _selectedCourseId,
      yearNo: _selectedYearNo,
      status: 'active',
    );

    // 3. Fetch Existing Marks
    Map<int, Map<int, Map<String, dynamic>>> existingMarks = {};
    if (_selectedExamId != null) {
      final results = await db.getExamSubjectResults(
        examId: _selectedExamId!,
        courseId: _selectedCourseId,
        yearNo: _selectedYearNo,
      );
      for (var r in results) {
        final sid = r['student_id'] as int;
        final subId = r['subject_id'] as int;
        existingMarks.putIfAbsent(sid, () => {})[subId] = r;
      }
    }

    // Initialize Controllers & Absent Map
    _markControllers.clear();
    _absentMap.clear();

    for (var st in enrollments) {
      final sid = st['student_id'] as int;
      _markControllers[sid] = {};
      _absentMap[sid] = {};

      for (var sub in subs) {
        final subId = sub['id'] as int;
        final res = existingMarks[sid]?[subId];
        final isAbs = (res?['is_absent'] ?? 0) == 1;
        final obt = res?['marks_obtained']?.toString() ?? '';

        _absentMap[sid]![subId] = isAbs;
        _markControllers[sid]![subId] = TextEditingController(
          text: isAbs ? '' : (obt.isNotEmpty && obt != '0.0' ? obt : (obt == '0.0' && res != null ? '0' : '')),
        );
      }
    }

    setState(() {
      _subjects = subs;
      _students = enrollments;
      _isLoading = false;
    });
  }

  void _showQuickExamDialog() {
    final nameCtrl = TextEditingController();
    final dateCtrl = TextEditingController(
      text: intl.DateFormat('yyyy-MM-dd').format(DateTime.now()),
    );

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text(
          'نیا امتحان تشکیل دیں',
          style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
          textAlign: TextAlign.center,
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: nameCtrl,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                labelText: 'امتحان کا نام',
                hintText: 'مثلاً: سالانہ، ششماہی، دو ماہی یا ماہانہ ٹیسٹ',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: dateCtrl,
              textAlign: TextAlign.center,
              decoration: const InputDecoration(
                labelText: 'تاریخ امتحان',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: primaryGreen,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            onPressed: () async {
              final name = nameCtrl.text.trim();
              if (name.isEmpty) return;
              final newId = await DBHelper().insertExam({
                'exam_name': name,
                'exam_date': dateCtrl.text.trim(),
                'total_marks': 100,
                'passing_marks': 40,
                'system_type': 'Madrasa',
              });
              Navigator.pop(ctx);
              final exams = await DBHelper().getExams();
              setState(() {
                _exams = exams;
                _selectedExamId = newId;
              });
              _loadGridData();
            },
            child: const Text('تشکیل دیں'),
          ),
        ],
      ),
    );
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
        return Colors.green.shade700;
      case 'جید جدا':
        return Colors.blue.shade700;
      case 'جید':
        return Colors.teal.shade700;
      case 'مقبول':
        return Colors.amber.shade800;
      case 'غیر حاضر':
        return Colors.grey.shade600;
      default:
        return Colors.red.shade700;
    }
  }

  Future<void> _saveAllResults() async {
    if (_selectedExamId == null || _selectedCourseId == null) return;
    setState(() => _isSaving = true);

    final List<Map<String, dynamic>> resultsList = [];
    final db = DBHelper();

    for (var st in _students) {
      final sid = st['student_id'] as int;

      for (var sub in _subjects) {
        final subId = sub['id'] as int;
        final isAbs = _absentMap[sid]?[subId] ?? false;
        final ctrl = _markControllers[sid]?[subId];
        final maxM = (sub['max_marks'] as num?)?.toDouble() ?? 100.0;
        final obt = isAbs ? 0.0 : (double.tryParse(ctrl?.text.trim() ?? '') ?? 0.0);
        final grade = _calculateGrade(obt, maxM, isAbs);

        resultsList.add({
          'tenant_id': 1,
          'exam_id': _selectedExamId,
          'student_id': sid,
          'course_id': _selectedCourseId,
          'year_no': _selectedYearNo,
          'subject_id': subId,
          'subject_name': sub['subject_name'] ?? '',
          'marks_obtained': obt,
          'max_marks': maxM,
          'is_absent': isAbs ? 1 : 0,
          'grade': grade,
        });
      }
    }

    await db.saveBulkExamSubjectResults(resultsList);
    setState(() => _isSaving = false);

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('✅ تمام مضامین کے نتائج کامیابی سے محفوظ ہو گئے!'),
        backgroundColor: Colors.green,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    double totalMaxMarks = 0.0;
    for (var s in _subjects) {
      totalMaxMarks += (s['max_marks'] as num?)?.toDouble() ?? 100.0;
    }

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          title: Text(
            isUrdu ? 'مضامین وار رزلٹ انٹری' : 'Subject Results Entry',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              icon: const Icon(Icons.add_circle_outline),
              tooltip: isUrdu ? 'نیا امتحان' : 'New Exam',
              onPressed: _showQuickExamDialog,
            ),
          ],
        ),
        body: Column(
          children: [
            // Top Selectors
            Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedExamId,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'امتحان منتخب کریں' : 'Select Exam',
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          items: _exams.map((ex) {
                            return DropdownMenuItem<int>(
                              value: ex['id'] as int,
                              child: Text('${ex['exam_name']}', overflow: TextOverflow.ellipsis),
                            );
                          }).toList(),
                          onChanged: (val) {
                            setState(() => _selectedExamId = val);
                            _loadGridData();
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedCourseId,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'کورس' : 'Course',
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          items: _courses.map((c) {
                            return DropdownMenuItem<int>(
                              value: c['id'] as int,
                              child: Text('${c['name']}', overflow: TextOverflow.ellipsis),
                            );
                          }).toList(),
                          onChanged: (val) {
                            setState(() => _selectedCourseId = val);
                            _loadGridData();
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  // Years Pills
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: List.generate(8, (i) {
                        final y = i + 1;
                        final isSel = (_selectedYearNo == y);
                        return Padding(
                          padding: const EdgeInsets.only(left: 6.0),
                          child: ChoiceChip(
                            label: Text(_yearNames[y] ?? 'سال $y'),
                            selected: isSel,
                            selectedColor: primaryGreen,
                            labelStyle: TextStyle(color: isSel ? Colors.white : Colors.black87),
                            onSelected: (val) {
                              if (val) {
                                setState(() => _selectedYearNo = y);
                                _loadGridData();
                              }
                            },
                          ),
                        );
                      }),
                    ),
                  ),
                ],
              ),
            ),

            // Content Area
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _subjects.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.menu_book, size: 50, color: Colors.grey.shade400),
                              const SizedBox(height: 10),
                              Text(
                                isUrdu ? 'اس سال میں تاحال کوئی مضمون شامل نہیں ہے' : 'No subjects for this year',
                                style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        )
                      : _students.isEmpty
                          ? Center(
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(Icons.person_off_outlined, size: 50, color: Colors.grey.shade400),
                                  const SizedBox(height: 10),
                                  Text(
                                    isUrdu ? 'اس سال میں کوئی طالب علم داخل نہیں ہے' : 'No enrolled students',
                                    style: TextStyle(color: Colors.grey.shade700, fontWeight: FontWeight.bold),
                                  ),
                                ],
                              ),
                            )
                          : ListView.builder(
                              padding: const EdgeInsets.all(12),
                              itemCount: _students.length,
                              itemBuilder: (ctx, i) {
                                final st = _students[i];
                                final sid = st['student_id'] as int;

                                double studentObt = 0.0;
                                bool anyEntered = false;
                                bool allAbsent = true;

                                for (var sub in _subjects) {
                                  final subId = sub['id'] as int;
                                  final isAbs = _absentMap[sid]?[subId] ?? false;
                                  final val = _markControllers[sid]?[subId]?.text.trim() ?? '';
                                  if (isAbs) {
                                    anyEntered = true;
                                  } else if (val.isNotEmpty) {
                                    anyEntered = true;
                                    allAbsent = false;
                                    studentObt += double.tryParse(val) ?? 0.0;
                                  }
                                }

                                final grade = anyEntered
                                    ? _calculateGrade(studentObt, totalMaxMarks, allAbsent)
                                    : '—';

                                return Card(
                                  margin: const EdgeInsets.only(bottom: 12),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                  elevation: 2,
                                  child: Padding(
                                    padding: const EdgeInsets.all(14),
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        // Student Header
                                        Row(
                                          children: [
                                            CircleAvatar(
                                              backgroundColor: primaryGreen.withOpacity(0.1),
                                              foregroundColor: primaryGreen,
                                              child: Text('${st['roll_number'] ?? (i + 1)}'),
                                            ),
                                            const SizedBox(width: 10),
                                            Expanded(
                                              child: Column(
                                                crossAxisAlignment: CrossAxisAlignment.start,
                                                children: [
                                                  Text(
                                                    st['student_name'] ?? '',
                                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                                  ),
                                                  Text(
                                                    'ولد: ${st['father_name'] ?? '—'}',
                                                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                                  ),
                                                ],
                                              ),
                                            ),
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                              decoration: BoxDecoration(
                                                color: _getGradeColor(grade).withOpacity(0.15),
                                                borderRadius: BorderRadius.circular(12),
                                              ),
                                              child: Text(
                                                grade,
                                                style: TextStyle(
                                                  fontWeight: FontWeight.bold,
                                                  color: _getGradeColor(grade),
                                                  fontSize: 13,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                        const Divider(height: 20),

                                        // Subjects Marks Input Grid
                                        ..._subjects.map((sub) {
                                          final subId = sub['id'] as int;
                                          final ctrl = _markControllers[sid]?[subId];
                                          final isAbs = _absentMap[sid]?[subId] ?? false;
                                          final maxM = (sub['max_marks'] as num?)?.toDouble() ?? 100.0;

                                          return Padding(
                                            padding: const EdgeInsets.symmetric(vertical: 4),
                                            child: Row(
                                              children: [
                                                Expanded(
                                                  flex: 4,
                                                  child: Column(
                                                    crossAxisAlignment: CrossAxisAlignment.start,
                                                    children: [
                                                      Text(
                                                        sub['subject_name'] ?? '',
                                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                                      ),
                                                      Text(
                                                        'کتاب: ${sub['book_name']?.isNotEmpty == true ? sub['book_name'] : '—'} (کل: $maxM)',
                                                        style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                                                      ),
                                                    ],
                                                  ),
                                                ),
                                                const SizedBox(width: 8),
                                                // Absent Checkbox
                                                InkWell(
                                                  onTap: () {
                                                    setState(() {
                                                      final next = !isAbs;
                                                      _absentMap[sid]![subId] = next;
                                                      if (next) {
                                                        ctrl?.clear();
                                                      }
                                                    });
                                                  },
                                                  child: Container(
                                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                                                    decoration: BoxDecoration(
                                                      color: isAbs ? Colors.red.shade100 : Colors.grey.shade100,
                                                      borderRadius: BorderRadius.circular(8),
                                                    ),
                                                    child: Text(
                                                      'غ/ح',
                                                      style: TextStyle(
                                                        fontSize: 11,
                                                        fontWeight: FontWeight.bold,
                                                        color: isAbs ? Colors.red.shade900 : Colors.grey.shade600,
                                                      ),
                                                    ),
                                                  ),
                                                ),
                                                const SizedBox(width: 8),
                                                // Marks Input
                                                SizedBox(
                                                  width: 75,
                                                  height: 38,
                                                  child: TextField(
                                                    controller: ctrl,
                                                    enabled: !isAbs,
                                                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                                    textAlign: TextAlign.center,
                                                    onChanged: (_) => setState(() {}),
                                                    decoration: InputDecoration(
                                                      hintText: '0',
                                                      contentPadding: EdgeInsets.zero,
                                                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                                                      filled: isAbs,
                                                      fillColor: Colors.grey.shade100,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                            ),
                                          );
                                        }).toList(),

                                        const SizedBox(height: 8),
                                        // Total Summary Bar for Student
                                        Container(
                                          padding: const EdgeInsets.all(8),
                                          decoration: BoxDecoration(
                                            color: Colors.grey.shade100,
                                            borderRadius: BorderRadius.circular(10),
                                          ),
                                          child: Row(
                                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                            children: [
                                              Text(
                                                'مجموعی حاصل کردہ: ${anyEntered ? studentObt.toStringAsFixed(1) : '—'} / $totalMaxMarks',
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                              ),
                                              Text(
                                                anyEntered && totalMaxMarks > 0
                                                    ? '${((studentObt / totalMaxMarks) * 100).toStringAsFixed(1)}%'
                                                    : '—',
                                                style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
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
            ),

            // Bottom Save Button
            if (_students.isNotEmpty && _subjects.isNotEmpty)
              Container(
                padding: const EdgeInsets.all(12),
                color: Colors.white,
                child: SizedBox(
                  width: double.infinity,
                  height: 48,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: _isSaving ? null : _saveAllResults,
                    icon: _isSaving
                        ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Icon(Icons.save),
                    label: Text(
                      _isSaving ? 'محفوظ کیا جا رہا ہے...' : 'تمام نتائج محفوظ کریں',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
