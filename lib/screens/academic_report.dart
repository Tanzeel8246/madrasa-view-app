// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../utils/performance_helper.dart';
import 'dashboard.dart';

class AcademicReportPage extends StatefulWidget {
  final String? className;
  const AcademicReportPage({super.key, this.className});

  @override
  _AcademicReportPageState createState() => _AcademicReportPageState();
}

class _AcademicReportPageState extends State<AcademicReportPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  final _dateController = TextEditingController();
  final _subjectController = TextEditingController();

  List<Map<String, dynamic>> _allStudents = [];
  List<String> _classes = [];
  String? _selectedClass;

  final Map<int, TextEditingController> _lessonDetailsControllers = {};
  final Map<int, String?> _selectedGrades = {};
  final Map<int, TextEditingController> _remarksControllers = {};
  final Map<int, int> _existingRecordIds = {};

  final FocusNode _subjectFocus = FocusNode();

  final List<String> grades = [
    "بہترین",
    "بہتر",
    "رعایتی پاس",
    "کچا",
    "سبق نہیں سنایا"
  ];

  @override
  void initState() {
    super.initState();
    _selectedClass = widget.className;
    _dateController.text = _formatDate(DateTime.now());

    _subjectFocus.addListener(() {
      if (!_subjectFocus.hasFocus) {
        _checkExistingRecords();
      }
    });

    _loadData();
  }

  @override
  void dispose() {
    _subjectFocus.dispose();
    super.dispose();
  }

  String _formatDate(DateTime date) {
    return "${date.day}/${date.month}/${date.year}";
  }

  String _normalizeClassName(dynamic value) {
    return (value?.toString() ?? '')
        .trim()
        .replaceAll('ٰ', '')
        .replaceAll('ى', 'ی')
        .replaceAll('ي', 'ی')
        .replaceAll('ك', 'ک')
        .replaceAll(RegExp(r'\s+'), '')
        .toLowerCase();
  }

  bool _isActiveStudent(Map<String, dynamic> student) {
    return PerformanceCalculator.isStudentActive(student);
  }

  bool _studentBelongsToSelectedClass(Map<String, dynamic> student) {
    if (_selectedClass == null) return false;
    final selected = _normalizeClassName(_selectedClass);
    final className = _normalizeClassName(student['class_name']);
    final legacyClass = _normalizeClassName(student['class']);
    return className == selected || legacyClass == selected;
  }

  List<String> _suggestedSubjects = [];

  void _loadData() async {
    final data = await DBHelper().getStudentsForUser(includeInactive: true);
    final classesData = await DBHelper().getClassesForUser();
    setState(() {
      _classes = classesData.map((c) => c['name'].toString()).toList();
      _allStudents = data.where((s) {
        final cName = _normalizeClassName(
            s['class_name']?.toString().isNotEmpty == true
                ? s['class_name']
                : s['class']);
        final isHifz = cName.contains('حفظ') ||
            cName.contains('حسنصوت') ||
            cName.contains('گردان');
        return _isActiveStudent(s) && !isHifz;
      }).toList();

      for (var student in _allStudents) {
        int sId = student['id'];
        _lessonDetailsControllers[sId] = TextEditingController();
        _selectedGrades[sId] = null;
        _remarksControllers[sId] = TextEditingController();
      }
    });

    if (_selectedClass != null) {
      await _loadClassSubjects(_selectedClass!);
      await _checkExistingRecords();
    }
  }

  Future<void> _loadClassSubjects(String className) async {
    final db = await DBHelper().database;
    final cRows = await db.query('classes', where: 'name = ?', whereArgs: [className], limit: 1);
    final subs = <String>{};
    if (cRows.isNotEmpty) {
      final raw = cRows.first['subjects_list']?.toString() ?? '';
      if (raw.isNotEmpty) {
        for (final item in raw.split(RegExp(r'[,،]'))) {
          final clean = item.split(':').first.trim();
          if (clean.isNotEmpty) subs.add(clean);
        }
      }
      final cType = cRows.first['class_type']?.toString() ?? '';
      // Also look up course subjects if linked
      final courses = await DBHelper().getCourses();
      for (final crs in courses) {
        if (crs['name'] == cType || crs['name'] == className) {
          final cSubs = await DBHelper().getCourseSubjects(crs['id'] as int);
          for (final cs in cSubs) {
            final sName = cs['subject_name']?.toString().trim() ?? '';
            if (sName.isNotEmpty) subs.add(sName);
          }
        }
      }
    }
    setState(() {
      _suggestedSubjects = subs.toList();
      if (_suggestedSubjects.isNotEmpty && _subjectController.text.trim().isEmpty) {
        _subjectController.text = _suggestedSubjects.first;
      }
    });
  }

  String _normalizeDate(String dateStr) {
    if (dateStr.contains('-')) return dateStr;
    try {
      final parts = dateStr.split('/');
      if (parts.length == 3) {
        String d = parts[0].padLeft(2, '0');
        String m = parts[1].padLeft(2, '0');
        String y = parts[2];
        return "$y-$m-$d";
      }
    } catch (_) {}
    return dateStr;
  }

  Future<void> _checkExistingRecords() async {
    if (_selectedClass == null) return;
    String d = _dateController.text;
    if (d.isEmpty) return;
    String normalizedD = _normalizeDate(d);

    final db = await DBHelper().database;
    String subjectQuery = '';
    List<dynamic> args = [_selectedClass, d, normalizedD];
    if (_subjectController.text.trim().isNotEmpty) {
      subjectQuery = ' AND subject = ?';
      args.add(_subjectController.text.trim());
    }

    final existing = await db.query('academic_reports',
        where:
            'class_name = ? AND (date = ? OR date = ?) AND is_deleted = 0$subjectQuery',
        whereArgs: args);

    // Clear current fields for all students
    for (var s in _allStudents) {
      int sId = s['id'];
      _lessonDetailsControllers[sId]?.clear();
      _selectedGrades[sId] = null;
      _remarksControllers[sId]?.clear();
    }
    _existingRecordIds.clear();

    if (subjectQuery.isEmpty) {
      _subjectController.clear();
    }

    if (existing.isNotEmpty) {
      _subjectController.text = existing.first['subject']?.toString() ?? '';
      for (var r in existing) {
        int sId = int.tryParse(r['student_id']?.toString() ?? '') ?? -1;
        if (sId != -1 && _lessonDetailsControllers.containsKey(sId)) {
          _existingRecordIds[sId] = r['id'] as int;
          _lessonDetailsControllers[sId]?.text =
              r['lesson_details']?.toString() ?? '';
          _selectedGrades[sId] = r['grade']?.toString();
          _remarksControllers[sId]?.text = r['remarks']?.toString() ?? '';
        }
      }
    }
    setState(() {});
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg, style: const TextStyle(fontWeight: FontWeight.bold)),
      backgroundColor: Colors.red.shade800,
      duration: const Duration(seconds: 4),
    ));
  }

  Future<void> _saveAll() async {
    if (_selectedClass == null) {
      _showError(isUrduGlobal.value ? 'کلاس منتخب کریں!' : 'Select Class!');
      return;
    }
    if (_subjectController.text.trim().isEmpty) {
      _showError(isUrduGlobal.value ? 'مضمون درج کریں!' : 'Enter subject!');
      return;
    }

    final filteredStudents =
        _allStudents.where(_studentBelongsToSelectedClass).toList();

    if (filteredStudents.isEmpty) {
      _showError(
          isUrduGlobal.value ? 'طالب علم موجود نہیں!' : 'No students found!');
      return;
    }

    final db = await DBHelper().database;
    String d = _dateController.text;

    int savedCount = 0;

    for (var student in filteredStudents) {
      int sId = student['id'];
      if (_selectedGrades[sId] != null) {
        Map<String, dynamic> data = {
          'date': d,
          'student_id': sId.toString(),
          'class_name': _selectedClass,
          'subject': _subjectController.text,
          'lesson_details': _lessonDetailsControllers[sId]?.text ?? '',
          'grade': _selectedGrades[sId],
          'remarks': _remarksControllers[sId]?.text ?? '',
        };

        if (_existingRecordIds.containsKey(sId)) {
          data['is_synced'] = 0;
          await db.update('academic_reports', data,
              where: 'id = ?', whereArgs: [_existingRecordIds[sId]]);
        } else {
          data['is_synced'] = 0;
          await db.insert('academic_reports', data);
        }
        savedCount++;
      }
    }

    if (savedCount == 0) {
      _showError(isUrduGlobal.value
          ? 'کم از کم ایک طالب علم کا گریڈ منتخب کریں!'
          : 'Select grade for at least one student!');
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrduGlobal.value
            ? 'تمام رپورٹس محفوظ ہو گئیں!'
            : 'All reports saved!'),
        backgroundColor: Colors.green));

    try {
      await SyncService.runTwoWaySync();
    } catch (_) {}
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
              drawer: const AppDrawer(),
              appBar: AppBar(
                leading: Builder(
                  builder: (context) => IconButton(
                    icon: const Icon(Icons.menu, color: Colors.white),
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  ),
                ),
                title: Text(
                    isUrdu ? 'جنرل اکیڈمک رپورٹ' : 'Academic Report Entry',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrdu
                              ? "ڈیٹا سنک ہو رہا ہے..."
                              : "Syncing data...")));
                      try {
                        await SyncService.runTwoWaySync();
                        if (mounted) {
                          _loadData();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سنک مکمل ہو گیا!"
                                  : "Sync complete!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(
                                  isUrdu ? "سنک میں خرابی آئی" : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
                  ),
                ],
              ),
              endDrawer: const AppDrawer(),
              body: Column(
                children: [
                  _buildHeaderFilters(isUrdu),
                  Expanded(
                    child: _buildStudentsList(isUrdu),
                  ),
                ],
              ),
              bottomNavigationBar: Padding(
                padding: const EdgeInsets.all(12.0),
                child: SizedBox(
                  width: double.infinity,
                  height: 50,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10)),
                    ),
                    onPressed: _saveAll,
                    icon: const Icon(Icons.save, color: Colors.white),
                    label: Text(
                      isUrdu ? "محفوظ کریں" : "Save All",
                      style: const TextStyle(
                          fontSize: 18,
                          color: Colors.white,
                          fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildHeaderFilters(bool isUrdu) {
    return Card(
      margin: const EdgeInsets.all(12.0),
      elevation: 3,
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          children: [
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _dateController,
                    readOnly: true,
                    decoration: InputDecoration(
                        labelText: isUrdu ? 'تاریخ' : 'Date',
                        suffixIcon: const Icon(Icons.calendar_today, size: 20),
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 10, vertical: 15)),
                    onTap: () async {
                      DateTime? picked = await showDatePicker(
                          context: context,
                          initialDate: DateTime.now(),
                          firstDate: DateTime(2000),
                          lastDate: DateTime(2100));
                      if (picked != null) {
                        setState(
                            () => _dateController.text = _formatDate(picked));
                        await _checkExistingRecords();
                      }
                    },
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    isExpanded: true,
                    value: _selectedClass,
                    dropdownColor: Colors.white,
                    style: const TextStyle(color: Colors.black87, fontSize: 14),
                    decoration: InputDecoration(
                      labelText: isUrdu ? 'کلاس' : 'Class',
                      border: const OutlineInputBorder(),
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 15),
                    ),
                    items: [
                      DropdownMenuItem<String>(
                        value: null,
                        child: Text(isUrdu ? "منتخب کریں" : "Select Class",
                            style: const TextStyle(fontSize: 13, color: Colors.black87)),
                      ),
                      ..._classes.map((c) => DropdownMenuItem<String>(
                          value: c,
                          child:
                              Text(c, style: const TextStyle(fontSize: 13, color: Colors.black87)))),
                    ],
                    onChanged: (val) async {
                      setState(() => _selectedClass = val);
                      if (val != null) {
                        await _loadClassSubjects(val);
                      }
                      await _checkExistingRecords();
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (_suggestedSubjects.isNotEmpty) ...[
              Align(
                alignment: isUrdu ? Alignment.centerRight : Alignment.centerLeft,
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      Text(
                        isUrdu ? 'مضامین: ' : 'Subjects: ',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: primaryGreen),
                      ),
                      ..._suggestedSubjects.map((sub) {
                        final isSel = _subjectController.text.trim() == sub.trim();
                        return Padding(
                          padding: const EdgeInsets.only(left: 4, right: 4),
                          child: ActionChip(
                            label: Text(sub, style: TextStyle(fontSize: 12, color: isSel ? Colors.white : primaryGreen)),
                            backgroundColor: isSel ? primaryGreen : primaryGreen.withOpacity(0.08),
                            onPressed: () async {
                              setState(() {
                                _subjectController.text = sub;
                              });
                              await _checkExistingRecords();
                            },
                          ),
                        );
                      }),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],
            TextField(
              controller: _subjectController,
              focusNode: _subjectFocus,
              decoration: InputDecoration(
                  labelText: isUrdu ? "مضمون" : "Subject",
                  hintText: isUrdu ? "مضمون کا نام منتخب کریں یا لکھیں" : "Enter subject name",
                  border: const OutlineInputBorder(),
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 15)),
              textInputAction: TextInputAction.done,
              onSubmitted: (_) {
                _subjectFocus.unfocus();
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildStudentsList(bool isUrdu) {
    if (_selectedClass == null) {
      return Center(
        child: Text(
            isUrdu ? "پہلے کلاس منتخب کریں" : "Please select a class first",
            style: const TextStyle(fontSize: 16, color: Colors.grey)),
      );
    }

    final filteredStudents =
        _allStudents.where(_studentBelongsToSelectedClass).toList();

    if (filteredStudents.isEmpty) {
      return Center(
        child: Text(
            isUrdu
                ? "اس کلاس میں کوئی طالب علم نہیں"
                : "No students in this class",
            style: const TextStyle(fontSize: 16, color: Colors.grey)),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 12.0, vertical: 6.0),
      itemCount: filteredStudents.length,
      itemBuilder: (context, index) {
        var student = filteredStudents[index];
        int sId = student['id'];
        String sName = student['name'] ?? 'نامعلوم';
        String fName = student['father_name'] ?? 'نامعلوم';
        String sRoll = student['roll_number']?.toString() ?? '-';

        return Card(
          elevation: 2,
          margin: const EdgeInsets.only(bottom: 12.0),
          child: Padding(
            padding: const EdgeInsets.all(12.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text("$sName - $fName",
                          style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 16,
                              color: primaryGreen)),
                    ),
                    Text(sRoll,
                        style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 14,
                            color: primaryGreen)),
                  ],
                ),
                const Divider(),
                const SizedBox(height: 8),
                TextField(
                  controller: _lessonDetailsControllers[sId],
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: isUrdu ? "سبق کی تفصیل" : "Lesson Details",
                    border: const OutlineInputBorder(),
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 10),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      flex: 1,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 10, vertical: 2),
                        decoration: BoxDecoration(
                            border: Border.all(color: Colors.grey),
                            borderRadius: BorderRadius.circular(5)),
                        child: DropdownButtonHideUnderline(
                          child: DropdownButton<String>(
                            isExpanded: true,
                            value: _selectedGrades[sId],
                            dropdownColor: Colors.white,
                            style: const TextStyle(color: Colors.black87, fontSize: 13),
                            hint: Text(isUrdu ? "گریڈ" : "Grade",
                                style: const TextStyle(fontSize: 13, color: Colors.black54)),
                            items: [
                              DropdownMenuItem<String>(
                                value: null,
                                child: Text(isUrdu ? "خالی" : "Clear",
                                    style: const TextStyle(color: Colors.grey)),
                              ),
                              ...grades.map((g) => DropdownMenuItem(
                                  value: g,
                                  child: Text(g,
                                      style: const TextStyle(fontSize: 13, color: Colors.black87))))
                            ],
                            onChanged: (val) {
                              setState(() => _selectedGrades[sId] = val);
                            },
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 2,
                      child: TextField(
                        controller: _remarksControllers[sId],
                        decoration: InputDecoration(
                          labelText: isUrdu ? "ریمارکس (اختیاری)" : "Remarks",
                          border: const OutlineInputBorder(),
                          contentPadding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 14),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
