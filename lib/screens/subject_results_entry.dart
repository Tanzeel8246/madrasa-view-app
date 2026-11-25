// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../widgets/neo_box.dart';
import 'academic_result_card_view.dart';

class SubjectResultsEntryPage extends StatefulWidget {
  final Map<String, dynamic>? initialExam;
  final int? initialCourseId;
  final int? initialYearNo;

  const SubjectResultsEntryPage({
    super.key,
    this.initialExam,
    this.initialCourseId,
    this.initialYearNo,
  });

  @override
  _SubjectResultsEntryPageState createState() =>
      _SubjectResultsEntryPageState();
}

class _SubjectResultsEntryPageState extends State<SubjectResultsEntryPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color accentBlue = const Color(0xFF1E3C72);
  final Color bgColor = const Color(0xFFF1EFE7);

  final DBHelper _db = DBHelper();

  List<Map<String, dynamic>> _exams = [];
  List<Map<String, dynamic>> _courses = [];
  List<Map<String, dynamic>> _subjects = [];
  List<Map<String, dynamic>> _enrolledStudents = [];
  List<Map<String, dynamic>> _allActiveStudents = [];

  int? _selectedExamId;
  int? _selectedCourseId;
  int _selectedYearNo = 1;

  bool _isLoading = true;
  bool _isSaving = false;

  // Controllers: key = "${studentId}_${subjectId}"
  final Map<String, TextEditingController> _marksControllers = {};
  final Map<String, bool> _absentMap = {};
  final Map<int, TextEditingController> _remarksControllers = {};

  @override
  void initState() {
    super.initState();
    _selectedYearNo = widget.initialYearNo ?? 1;
    _loadInitialData();
  }

  @override
  void dispose() {
    for (var c in _marksControllers.values) {
      c.dispose();
    }
    for (var c in _remarksControllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);

    final exams = await _db.getExams();
    final courses = await _db.getCourseStructures();
    final allStd = await _db.getStudents();

    int? activeExamId = widget.initialExam?['id'] as int?;
    if (activeExamId == null && exams.isNotEmpty) {
      activeExamId = exams.first['id'] as int;
    }

    int? activeCourseId = widget.initialCourseId;
    if (activeCourseId == null && courses.isNotEmpty) {
      activeCourseId = courses.first['id'] as int;
    }

    _exams = exams;
    _courses = courses;
    _allActiveStudents = allStd;
    _selectedExamId = activeExamId;
    _selectedCourseId = activeCourseId;

    await _loadSubjectsAndStudents();

    if (!mounted) return;
    setState(() => _isLoading = false);
  }

  Future<void> _loadSubjectsAndStudents() async {
    // Clear old controllers
    for (var c in _marksControllers.values) {
      c.dispose();
    }
    for (var c in _remarksControllers.values) {
      c.dispose();
    }
    _marksControllers.clear();
    _absentMap.clear();
    _remarksControllers.clear();

    if (_selectedCourseId == null) {
      _subjects = [];
      _enrolledStudents = [];
      return;
    }

    // 1. Fetch subjects
    final subs = await _db.getCourseSubjects(_selectedCourseId!,
        yearNo: _selectedYearNo);

    // 2. Fetch enrolled students
    final stds = await _db.getEnrolledStudentsWithDetails(_selectedCourseId!,
        yearNo: _selectedYearNo);

    // 3. Fetch existing results
    Map<String, Map<String, dynamic>> existingMap = {};
    if (_selectedExamId != null) {
      final existing = await _db.getExamSubjectResults(
        examId: _selectedExamId,
        courseId: _selectedCourseId,
        yearNo: _selectedYearNo,
      );
      for (var r in existing) {
        final sId = r['student_id']?.toString() ?? '';
        final subId = r['subject_id']?.toString() ?? '';
        existingMap['${sId}_$subId'] = r;
      }
    }

    // Initialize controllers
    for (var st in stds) {
      final sId = st['student_id'] as int;
      String stdRemark = '';

      for (var sb in subs) {
        final subId = sb['id'] as int;
        final key = '${sId}_$subId';
        final saved = existingMap[key];

        double val = (saved?['marks_obtained'] as num?)?.toDouble() ?? 0.0;
        bool isAbs = (saved?['is_absent'] as int?) == 1;

        String textVal = '';
        if (saved != null) {
          textVal = (val % 1 == 0) ? val.toInt().toString() : val.toString();
        }

        final ctrl = TextEditingController(text: isAbs ? '0' : textVal);
        _marksControllers[key] = ctrl;
        _absentMap[key] = isAbs;

        if (saved != null && saved['remarks'] != null && stdRemark.isEmpty) {
          stdRemark = saved['remarks'].toString();
        }
      }

      _remarksControllers[sId] = TextEditingController(text: stdRemark);
    }

    if (!mounted) return;
    setState(() {
      _subjects = subs;
      _enrolledStudents = stds;
    });
  }

  String _getYearLabel(int yearNo, String courseType) {
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
    if (courseType == 'Asri' || courseType == 'School') {
      return asriNames[yearNo] ?? 'سال $yearNo';
    }
    return dNames[yearNo] ?? 'سال $yearNo';
  }

  double _getStudentObtained(int studentId) {
    double total = 0.0;
    for (var sb in _subjects) {
      final subId = sb['id'] as int;
      final key = '${studentId}_$subId';
      if (_absentMap[key] == true) continue;
      final text = _marksControllers[key]?.text.trim() ?? '';
      final val = double.tryParse(text) ?? 0.0;
      total += val;
    }
    return total;
  }

  double _getTotalMax() {
    double total = 0.0;
    for (var sb in _subjects) {
      final maxM = (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
      total += maxM;
    }
    return total;
  }

  double _getStudentPercentage(int studentId) {
    final totalMax = _getTotalMax();
    if (totalMax <= 0) return 0.0;
    final obt = _getStudentObtained(studentId);
    return (obt / totalMax) * 100.0;
  }

  String _getStudentGrade(int studentId) {
    bool allAbsent = true;
    bool hasAnyFail = false;

    for (var sb in _subjects) {
      final subId = sb['id'] as int;
      final key = '${studentId}_$subId';
      final isAbs = _absentMap[key] == true;
      final passM = (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;
      final isOpt = (sb['is_optional'] as int?) == 1;

      if (!isAbs) {
        allAbsent = false;
        final text = _marksControllers[key]?.text.trim() ?? '';
        final val = double.tryParse(text) ?? 0.0;
        if (!isOpt && val < passM) {
          hasAnyFail = true;
        }
      } else {
        if (!isOpt) hasAnyFail = true;
      }
    }

    if (allAbsent && _subjects.isNotEmpty) {
      return 'غیر حاضر';
    }

    final perc = _getStudentPercentage(studentId);
    if (hasAnyFail || perc < 40.0) {
      return 'راسب';
    } else if (perc >= 80.0) {
      return 'ممتاز';
    } else if (perc >= 70.0) {
      return 'جید جدا';
    } else if (perc >= 50.0) {
      return 'جید';
    } else {
      return 'مقبول';
    }
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
      case 'راسب':
      case 'غیر حاضر':
        return Colors.red.shade700;
      default:
        return Colors.grey.shade700;
    }
  }

  Future<void> _saveAllResults() async {
    if (_selectedExamId == null || _selectedCourseId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('برائے مہربانی امتحان اور کورس منتخب کریں')),
      );
      return;
    }

    setState(() => _isSaving = true);

    try {
      List<Map<String, dynamic>> recordsToSave = [];

      for (var st in _enrolledStudents) {
        final sId = st['student_id'] as int;
        final remarks = _remarksControllers[sId]?.text.trim() ?? '';

        for (var sb in _subjects) {
          final subId = sb['id'] as int;
          final subName = sb['subject_name']?.toString() ?? '';
          final maxM = (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
          final passM = (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;

          final key = '${sId}_$subId';
          final isAbs = _absentMap[key] == true;
          final text = _marksControllers[key]?.text.trim() ?? '';
          final obtained = isAbs ? 0.0 : (double.tryParse(text) ?? 0.0);

          // Subject grade
          String subGrade = 'راسب';
          if (isAbs) {
            subGrade = 'غیر حاضر';
          } else {
            final perc = maxM > 0 ? (obtained / maxM) * 100 : 0;
            if (perc >= 80) {
              subGrade = 'ممتاز';
            } else if (perc >= 70) {
              subGrade = 'جید جدا';
            } else if (perc >= 50) {
              subGrade = 'جید';
            } else if (perc >= passM) {
              subGrade = 'مقبول';
            } else {
              subGrade = 'راسب';
            }
          }

          recordsToSave.add({
            'exam_id': _selectedExamId,
            'student_id': sId,
            'course_id': _selectedCourseId,
            'year_no': _selectedYearNo,
            'subject_id': subId,
            'subject_name': subName,
            'marks_obtained': obtained,
            'max_marks': maxM,
            'is_absent': isAbs ? 1 : 0,
            'grade': subGrade,
            'remarks': remarks,
          });
        }
      }

      await _db.saveBatchExamSubjectResults(recordsToSave);

      // Trigger sync in background
      SyncService.runTwoWaySync().ignore();

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
              'تمام امتحانی نتائج (${recordsToSave.length} اندراجات) کامیابی سے محفوظ ہو گئے!'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('نتائج محفوظ کرنے میں خرابی: $e'),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  void _showQuickEnrollDialog() {
    List<int> selectedIds = [];
    String searchQuery = '';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) {
          final filtered = _allActiveStudents.where((s) {
            final name = (s['name'] ?? '').toString().toLowerCase();
            final roll = (s['admission_number'] ?? s['roll_number'] ?? '')
                .toString()
                .toLowerCase();
            final q = searchQuery.toLowerCase();
            return name.contains(q) || roll.contains(q);
          }).toList();

          return Directionality(
            textDirection: ui.TextDirection.rtl,
            child: AlertDialog(
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16)),
              title: const Row(
                children: [
                  Icon(Icons.person_add, color: Colors.teal),
                  SizedBox(width: 8),
                  Text('طلبہ کو اس درجے میں شامل کریں',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                ],
              ),
              content: SizedBox(
                width: 480,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextField(
                      decoration: const InputDecoration(
                        hintText: 'نام یا رول نمبر سے تلاش کریں...',
                        prefixIcon: Icon(Icons.search),
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                      onChanged: (val) =>
                          setDialogState(() => searchQuery = val),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        TextButton(
                          onPressed: () {
                            setDialogState(() {
                              selectedIds =
                                  filtered.map((s) => s['id'] as int).toList();
                            });
                          },
                          child: const Text('سب منتخب کریں'),
                        ),
                        TextButton(
                          onPressed: () {
                            setDialogState(() => selectedIds.clear());
                          },
                          child: const Text('انتخاب ختم کریں'),
                        ),
                      ],
                    ),
                    const Divider(height: 1),
                    SizedBox(
                      height: 280,
                      child: filtered.isEmpty
                          ? const Center(child: Text('کوئی طالب علم نہیں ملا'))
                          : ListView.builder(
                              shrinkWrap: true,
                              itemCount: filtered.length,
                              itemBuilder: (c, idx) {
                                final s = filtered[idx];
                                final sId = s['id'] as int;
                                final isSelected = selectedIds.contains(sId);
                                return CheckboxListTile(
                                  dense: true,
                                  value: isSelected,
                                  title: Text(s['name']?.toString() ?? '',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.bold)),
                                  subtitle: Text(
                                      'ولد: ${s['father_name'] ?? '-'} | داخلہ: ${s['admission_number'] ?? '-'}'),
                                  onChanged: (val) {
                                    setDialogState(() {
                                      if (val == true) {
                                        selectedIds.add(sId);
                                      } else {
                                        selectedIds.remove(sId);
                                      }
                                    });
                                  },
                                );
                              },
                            ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('منسوخ'),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      foregroundColor: Colors.white),
                  onPressed: selectedIds.isEmpty
                      ? null
                      : () async {
                          Navigator.pop(ctx);
                          for (var sId in selectedIds) {
                            await _db.saveStudentCourseEnrollment({
                              'student_id': sId,
                              'course_id': _selectedCourseId,
                              'current_year': _selectedYearNo,
                              'status': 'Active',
                            });
                          }
                          await _loadSubjectsAndStudents();
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(
                                    '${selectedIds.length} طلبہ کامیابی سے شامل ہو گئے!'),
                                backgroundColor: Colors.green,
                              ),
                            );
                          }
                        },
                  child: const Text('منتخب طلبہ انرول کریں'),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        Map<String, dynamic>? selectedCourse;
        for (var c in _courses) {
          if (c['id'] == _selectedCourseId) {
            selectedCourse = c;
            break;
          }
        }
        final totalYears = selectedCourse?['total_years'] as int? ?? 8;
        final courseType =
            selectedCourse?['course_type']?.toString() ?? 'Darse_Nizami';

        return Directionality(
          textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
          child: Scaffold(
            backgroundColor: bgColor,
            appBar: AppBar(
              title: Text(
                isUrdu
                    ? 'نتائج مضامین (درس نظامی و عصری علوم)'
                    : 'Academic Subject Results',
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 17,
                    color: Colors.white),
              ),
              backgroundColor: primaryGreen,
              foregroundColor: Colors.white,
              elevation: 2,
              actions: [
                IconButton(
                  icon: const Icon(Icons.print, color: Colors.white),
                  tooltip: isUrdu
                      ? 'تمام نتیجہ کارڈز (DMC) پرنٹ کریں'
                      : 'Print All Result Cards',
                  onPressed: (_enrolledStudents.isEmpty || _subjects.isEmpty)
                      ? null
                      : () {
                          Map<String, dynamic>? selectedCourse;
                          for (var c in _courses) {
                            if (c['id'] == _selectedCourseId) {
                              selectedCourse = c;
                              break;
                            }
                          }
                          Map<String, dynamic>? selectedExam;
                          for (var e in _exams) {
                            if (e['id'] == _selectedExamId) {
                              selectedExam = e;
                              break;
                            }
                          }
                          Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => AcademicResultCardView(
                                exam: selectedExam,
                                course: selectedCourse,
                                yearNo: _selectedYearNo,
                                students: _enrolledStudents,
                                subjects: _subjects,
                              ),
                            ),
                          );
                        },
                ),
                IconButton(
                  icon: const Icon(Icons.sync, color: Colors.white),
                  tooltip: isUrdu ? 'سنک کریں' : 'Sync',
                  onPressed: () async {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                          content:
                              Text(isUrdu ? 'سنک ہو رہا ہے...' : 'Syncing...')),
                    );
                    await SyncService.runTwoWaySync();
                    await _loadSubjectsAndStudents();
                  },
                ),
              ],
            ),
            body: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : Column(
                    children: [
                      // Filter Bar (Exam, Course, Year)
                      _buildFilterBar(isUrdu, totalYears, courseType),

                      // Content Body
                      Expanded(
                        child: _courses.isEmpty
                            ? _buildNoCoursesState(isUrdu)
                            : _subjects.isEmpty
                                ? _buildNoSubjectsState(isUrdu)
                                : _enrolledStudents.isEmpty
                                    ? _buildNoStudentsState(isUrdu)
                                    : _buildResultsTable(isUrdu),
                      ),
                    ],
                  ),
            bottomNavigationBar:
                (_subjects.isNotEmpty && _enrolledStudents.isNotEmpty)
                    ? _buildBottomAction(isUrdu)
                    : null,
          ),
        );
      },
    );
  }

  Widget _buildFilterBar(bool isUrdu, int totalYears, String courseType) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Column(
        children: [
          Row(
            children: [
              // Exam Selector
              Expanded(
                flex: 5,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.grey.shade300),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      value: _selectedExamId,
                      isExpanded: true,
                      hint: Text(isUrdu ? 'امتحان منتخب کریں' : 'Select Exam'),
                      items: _exams.map((e) {
                        return DropdownMenuItem<int>(
                          value: e['id'] as int,
                          child: Text(
                            e['exam_name']?.toString() ?? '',
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                        );
                      }).toList(),
                      onChanged: (val) {
                        if (val != null) {
                          setState(() => _selectedExamId = val);
                          _loadSubjectsAndStudents();
                        }
                      },
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),

              // Course Selector
              Expanded(
                flex: 5,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.grey.shade300),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      value: _selectedCourseId,
                      isExpanded: true,
                      hint: Text(isUrdu ? 'کورس منتخب کریں' : 'Select Course'),
                      items: _courses.map((c) {
                        return DropdownMenuItem<int>(
                          value: c['id'] as int,
                          child: Text(
                            "${c['name']} (${c['gender']})",
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                        );
                      }).toList(),
                      onChanged: (val) {
                        if (val != null) {
                          setState(() {
                            _selectedCourseId = val;
                            _selectedYearNo = 1;
                          });
                          _loadSubjectsAndStudents();
                        }
                      },
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Year Selector Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: List.generate(totalYears, (idx) {
                final y = idx + 1;
                final isSelected = _selectedYearNo == y;
                return Padding(
                  padding: const EdgeInsets.only(left: 6.0),
                  child: ChoiceChip(
                    label: Text(
                      _getYearLabel(y, courseType),
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight:
                            isSelected ? FontWeight.bold : FontWeight.normal,
                        color: isSelected ? Colors.white : Colors.black87,
                      ),
                    ),
                    selected: isSelected,
                    selectedColor: primaryGreen,
                    backgroundColor: Colors.grey.shade100,
                    onSelected: (selected) {
                      if (selected) {
                        setState(() => _selectedYearNo = y);
                        _loadSubjectsAndStudents();
                      }
                    },
                  ),
                );
              }),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildNoCoursesState(bool isUrdu) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.school_outlined, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text(
              isUrdu
                  ? 'کوئی تعلیمی کورس موجود نہیں ہے'
                  : 'No Academic Course Found',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 6),
            Text(
              isUrdu
                  ? 'پہلے کورسز و مضامین مینیجر سے کورس اور نصاب تشکیل دیں۔'
                  : 'Please setup courses and curriculum first.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.grey, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildNoSubjectsState(bool isUrdu) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.menu_book, size: 64, color: Colors.amber.shade700),
            const SizedBox(height: 12),
            Text(
              isUrdu
                  ? 'اس سال / درجہ میں کوئی مضمون شامل نہیں ہے'
                  : 'No Subjects Found for this Year',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 6),
            Text(
              isUrdu
                  ? 'کورس مینیجر میں جا کر اس سال کے لیے کتب و مضامین شامل کریں۔'
                  : 'Add subjects in Course Manager first.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.grey, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildNoStudentsState(bool isUrdu) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.people_outline, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text(
              isUrdu
                  ? 'اس درجے میں فی الحال کوئی طالب علم انرول نہیں ہے'
                  : 'No Students Enrolled in this Darjah',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 12),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen, foregroundColor: Colors.white),
              onPressed: _showQuickEnrollDialog,
              icon: const Icon(Icons.person_add),
              label: Text(isUrdu
                  ? 'اس درجے میں طلبہ انرول کریں'
                  : 'Enroll Students Now'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildResultsTable(bool isUrdu) {
    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: _enrolledStudents.length,
      itemBuilder: (context, idx) {
        final st = _enrolledStudents[idx];
        final sId = st['student_id'] as int;
        final sName = st['student_name']?.toString() ?? '';
        final fName = st['father_name']?.toString() ?? '';
        final roll =
            st['roll_number'] ?? st['admission_number'] ?? '${idx + 1}';

        final obt = _getStudentObtained(sId);
        final totalMax = _getTotalMax();
        final perc = _getStudentPercentage(sId);
        final grade = _getStudentGrade(sId);
        final gradeColor = _getGradeColor(grade);

        return Container(
          margin: const EdgeInsets.only(bottom: 12),
          child: NeoBox(
            padding: 14,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Student Header Row
                Row(
                  children: [
                    CircleAvatar(
                      radius: 16,
                      backgroundColor: primaryGreen.withValues(alpha: 0.1),
                      child: Text(
                        '${idx + 1}',
                        style: TextStyle(
                            color: primaryGreen,
                            fontWeight: FontWeight.bold,
                            fontSize: 12),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(sName,
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 15)),
                          Text(
                            '${isUrdu ? 'ولد' : 'S/o'}: $fName | ${isUrdu ? 'رول' : 'Roll'}: $roll',
                            style: const TextStyle(
                                color: Colors.grey, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                    // Live Computed Grade Badge
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: gradeColor.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                            color: gradeColor.withValues(alpha: 0.4)),
                      ),
                      child: Text(
                        grade,
                        style: TextStyle(
                            color: gradeColor,
                            fontWeight: FontWeight.bold,
                            fontSize: 13),
                      ),
                    ),
                    const SizedBox(width: 4),
                    IconButton(
                      icon: const Icon(Icons.print_outlined,
                          size: 20, color: Color(0xFF1E3C72)),
                      tooltip: isUrdu ? 'نتیجہ کارڈ (DMC) دیکھیں' : 'View DMC',
                      onPressed: () {
                        Map<String, dynamic>? selectedCourse;
                        for (var c in _courses) {
                          if (c['id'] == _selectedCourseId) {
                            selectedCourse = c;
                            break;
                          }
                        }
                        Map<String, dynamic>? selectedExam;
                        for (var e in _exams) {
                          if (e['id'] == _selectedExamId) {
                            selectedExam = e;
                            break;
                          }
                        }
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (context) => AcademicResultCardView(
                              exam: selectedExam,
                              course: selectedCourse,
                              yearNo: _selectedYearNo,
                              students: _enrolledStudents,
                              subjects: _subjects,
                              initialStudent: st,
                            ),
                          ),
                        );
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 8),

                // Summary Numbers
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      '${isUrdu ? 'حاصل کردہ' : 'Obtained'}: ${obt.toStringAsFixed(1).replaceAll('.0', '')} / ${totalMax.toStringAsFixed(0)}',
                      style: TextStyle(
                          fontWeight: FontWeight.bold,
                          color: primaryGreen,
                          fontSize: 13),
                    ),
                    Text(
                      '${isUrdu ? 'فیصد' : 'Percentage'}: ${perc.toStringAsFixed(1)}%',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                  ],
                ),
                const Divider(height: 18),

                // Subjects Grid
                GridView.builder(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    childAspectRatio: 2.2,
                    crossAxisSpacing: 8,
                    mainAxisSpacing: 8,
                  ),
                  itemCount: _subjects.length,
                  itemBuilder: (context, subIdx) {
                    final sb = _subjects[subIdx];
                    final subId = sb['id'] as int;
                    final subName = sb['subject_name']?.toString() ?? '';
                    final maxM = (sb['max_marks'] as num?)?.toDouble() ?? 100.0;
                    final passM =
                        (sb['pass_marks'] as num?)?.toDouble() ?? 40.0;
                    final key = '${sId}_$subId';

                    final isAbs = _absentMap[key] == true;
                    final ctrl = _marksControllers[key];

                    return Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.grey.shade200),
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  subName,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 12),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                Text(
                                  '${isUrdu ? 'کل' : 'Max'}: ${maxM.toInt()} | ${isUrdu ? 'پاس' : 'Pass'}: ${passM.toInt()}',
                                  style: const TextStyle(
                                      fontSize: 10, color: Colors.grey),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 6),
                          // Marks Input Field
                          SizedBox(
                            width: 50,
                            height: 38,
                            child: TextField(
                              controller: ctrl,
                              enabled: !isAbs,
                              keyboardType:
                                  const TextInputType.numberWithOptions(
                                      decimal: true),
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 13),
                              decoration: InputDecoration(
                                contentPadding: EdgeInsets.zero,
                                filled: true,
                                fillColor:
                                    isAbs ? Colors.grey.shade200 : Colors.white,
                                border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(6)),
                              ),
                              onChanged: (val) {
                                setState(() {});
                              },
                            ),
                          ),
                          // Absent Checkbox
                          IconButton(
                            padding: EdgeInsets.zero,
                            constraints: const BoxConstraints(
                                minWidth: 28, maxWidth: 28),
                            icon: Icon(
                              isAbs ? Icons.cancel : Icons.check_circle_outline,
                              size: 18,
                              color: isAbs ? Colors.red : Colors.grey.shade400,
                            ),
                            tooltip: isUrdu ? 'غیر حاضر' : 'Absent',
                            onPressed: () {
                              setState(() {
                                final nowAbs = !isAbs;
                                _absentMap[key] = nowAbs;
                                if (nowAbs) {
                                  ctrl?.text = '0';
                                }
                              });
                            },
                          ),
                        ],
                      ),
                    );
                  },
                ),
                const SizedBox(height: 8),

                // Remarks Field
                TextField(
                  controller: _remarksControllers[sId],
                  style: const TextStyle(fontSize: 12),
                  decoration: InputDecoration(
                    hintText: isUrdu
                        ? 'کیفیت / تاثرات (اختیاری)...'
                        : 'Remarks (optional)...',
                    isDense: true,
                    contentPadding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(6)),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildBottomAction(bool isUrdu) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.all(12),
      child: SafeArea(
        child: Row(
          children: [
            Expanded(
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: _isSaving ? null : _saveAllResults,
                icon: _isSaving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Icon(Icons.save),
                label: Text(
                  isUrdu ? 'تمام امتحانی نتائج محفوظ کریں' : 'Save All Results',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ),
            ),
            const SizedBox(width: 8),
            OutlinedButton(
              style: OutlinedButton.styleFrom(
                padding:
                    const EdgeInsets.symmetric(vertical: 14, horizontal: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: _showQuickEnrollDialog,
              child: const Icon(Icons.person_add),
            ),
          ],
        ),
      ),
    );
  }
}
