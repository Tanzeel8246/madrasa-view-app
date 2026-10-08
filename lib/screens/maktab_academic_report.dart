// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../utils/sync_ui_helper.dart';
import '../utils/performance_helper.dart';
import 'dashboard.dart';

class MaktabAcademicReportPage extends StatefulWidget {
  final String? className;
  const MaktabAcademicReportPage({super.key, this.className});

  @override
  _MaktabAcademicReportPageState createState() => _MaktabAcademicReportPageState();
}

class _MaktabAcademicReportPageState extends State<MaktabAcademicReportPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  final _dateController = TextEditingController();

  List<Map<String, dynamic>> _allStudents = [];
  List<String> _classes = [];
  String? _selectedClass;
  List<String> _availableSubjects = [];

  // Data structures for 6 subjects per student
  final Map<int, List<TextEditingController>> _bookControllers = {};
  final Map<int, List<TextEditingController>> _lessonControllers = {};
  final Map<int, List<String?>> _selectedGrades = {};
  final Map<int, List<int?>> _existingRecordIds = {};

  final List<String> grades = ["بہترین", "بہتر", "رعایتی پاس", "کچا", "معمولی", "کمزور"];
  
  final List<String> _defaultSubjects = [
    "مضمون 1", "مضمون 2", "مضمون 3", "مضمون 4", "مضمون 5", "مضمون 6"
  ];

  @override
  void initState() {
    super.initState();
    _selectedClass = widget.className;
    _dateController.text = _formatDate(DateTime.now());

    _loadData();
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

  void _loadData() async {
    final db = DBHelper();
    final data = await db.getMaktabStudentsForUser(includeInactive: true);
    final classesData = await db.getMaktabClassesForUser();
    setState(() {
      _classes = classesData.map((c) => c['name'].toString()).toList();
      _allStudents = data.where((s) {
        final cName = _normalizeClassName(
            s['class_name']?.toString().isNotEmpty == true
                ? s['class_name']
                : s['class']);
        final isHifz = cName.contains('حفظ') ||
            cName.contains('حسنصوت') ||
            cName.contains('گردان') ||
            cName.contains('ناظرہ') ||
            cName.contains('قاعدہ');
        return _isActiveStudent(s) && !isHifz;
      }).toList();

      for (var student in _allStudents) {
        int sId = student['id'];
        _bookControllers[sId] = List.generate(6, (i) => TextEditingController());
        _lessonControllers[sId] = List.generate(6, (i) => TextEditingController());
        _selectedGrades[sId] = List.generate(6, (i) => null);
        _existingRecordIds[sId] = List.generate(6, (i) => null);
      }
    });

    if (_selectedClass != null) {
      _loadSubjectsForClass(_selectedClass!);
      await _checkExistingRecords();
    }
  }

  List<List<String>> _availableSubjectBooks = [];

  Future<void> _loadSubjectsForClass(String className) async {
    final db = DBHelper();
    final cMap = await db.getClassByName(className);
    List<String> loadedSubs = [];
    List<List<String>> loadedBooks = [];
    
    if (cMap != null) {
      int classId = cMap['id'];
      final periods = await db.getClassPeriods(classId);
      
      if (periods.isNotEmpty) {
        for (var p in periods) {
          String sub = p['books']?.toString() ?? '';
          if (sub.trim().isEmpty) sub = 'مضمون ${p['period_no']}';
          loadedSubs.add(sub);
          
          String booksStr = p['books_list']?.toString() ?? '';
          List<String> books = booksStr.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
          loadedBooks.add(books);
        }
      } else {
        if (cMap['subjects_list'] != null && cMap['subjects_list'].toString().trim().isNotEmpty) {
          var parts = cMap['subjects_list'].toString().split(',');
          for (var p in parts) {
            if (p.trim().isNotEmpty) {
              var subParts = p.split(':');
              loadedSubs.add(subParts[0].trim());
              loadedBooks.add([]);
            }
          }
        }
      }
    }
    
    setState(() {
      _availableSubjects = loadedSubs;
      _availableSubjectBooks = loadedBooks;
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
    final existing = await db.query('maktab_academic_reports',
        where: 'class_name = ? AND (date = ? OR date = ?) AND is_deleted = 0',
        whereArgs: [_selectedClass, d, normalizedD]);

    // Clear current fields for all students
    for (var s in _allStudents) {
      int sId = s['id'];
      for (int i = 0; i < 6; i++) {
        _bookControllers[sId]?[i].clear();
        _lessonControllers[sId]?[i].clear();
        _selectedGrades[sId]?[i] = null;
        _existingRecordIds[sId]?[i] = null;
      }
    }

    if (existing.isNotEmpty) {
      Map<int, List<Map<String, dynamic>>> groupedRecords = {};
      for (var r in existing) {
        int sId = int.tryParse(r['student_id']?.toString() ?? '') ?? -1;
        if (sId != -1) {
          groupedRecords.putIfAbsent(sId, () => []).add(r);
        }
      }

      for (var entry in groupedRecords.entries) {
        int sId = entry.key;
        if (_bookControllers.containsKey(sId)) {
          var records = entry.value;
          // Match by subject name
          for (var r in records) {
             String rSubj = r['subject']?.toString() ?? '';
             int idx = _getSubjectIndex(rSubj);
             if (idx != -1 && idx < 6) {
               _existingRecordIds[sId]?[idx] = r['id'] as int;
               _bookControllers[sId]?[idx].text = r['remarks']?.toString() ?? '';
               _lessonControllers[sId]?[idx].text = r['lesson_details']?.toString() ?? '';
               _selectedGrades[sId]?[idx] = r['grade']?.toString();
             }
          }
        }
      }
    }
    setState(() {});
  }
  
  int _getSubjectIndex(String subj) {
    if (_availableSubjects.isEmpty) {
       for(int i=0; i<_defaultSubjects.length; i++) {
           if (_defaultSubjects[i] == subj) return i;
       }
    } else {
       for(int i=0; i<_availableSubjects.length; i++) {
           if (_availableSubjects[i] == subj) return i;
       }
    }
    return -1;
  }
  
  String _getSubjectName(int index) {
      if (_availableSubjects.isNotEmpty && index < _availableSubjects.length) {
          return _availableSubjects[index];
      }
      if (index < _defaultSubjects.length) return _defaultSubjects[index];
      return "مضمون ${index+1}";
  }

  Future<void> _showAddBookDialog(int sId, int index) async {
    TextEditingController newBookCtrl = TextEditingController();
    await showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('نئی کتاب کا نام'),
          content: TextField(
            controller: newBookCtrl,
            decoration: const InputDecoration(hintText: 'کتاب کا نام لکھیں'),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('کینسل'),
            ),
            ElevatedButton(
              onPressed: () async {
                String newBook = newBookCtrl.text.trim();
                if (newBook.isNotEmpty) {
                  setState(() {
                    if (_availableSubjectBooks.length > index) {
                      _availableSubjectBooks[index].add(newBook);
                    }
                    _bookControllers[sId]?[index].text = newBook;
                  });
                  if (_selectedClass != null) {
                    final cMap = await DBHelper().getClassByName(_selectedClass!);
                    if (cMap != null) {
                      await DBHelper().addClassPeriodBook(cMap['id'], index + 1, newBook);
                      await SyncService.pushNewClassPeriodBook(cMap['id'], index + 1, newBook);
                    }
                  }
                }
                Navigator.pop(context);
              },
              child: const Text('محفوظ کریں'),
            ),
          ],
        );
      },
    );
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
      
      for (int i = 0; i < 6; i++) {
         String sub = _getSubjectName(i);
         String book = _bookControllers[sId]?[i].text.trim() ?? '';
         String gr = _selectedGrades[sId]?[i] ?? '';
         String les = _lessonControllers[sId]?[i].text.trim() ?? '';
         int? existingId = _existingRecordIds[sId]?[i];
         
         if (book.isNotEmpty || les.isNotEmpty || gr.isNotEmpty || existingId != null) {
            Map<String, dynamic> data = {
              'date': d,
              'student_id': sId.toString(),
              'class_name': _selectedClass,
              'subject': sub,
              'lesson_details': les,
              'grade': gr.isNotEmpty ? gr : null,
              'remarks': book,
            };

            if (existingId != null) {
              data['is_synced'] = 0;
              await db.update('maktab_academic_reports', data,
                  where: 'id = ?', whereArgs: [existingId]);
              savedCount++;
            } else if (book.isNotEmpty || les.isNotEmpty || gr.isNotEmpty) {
              data['is_synced'] = 0;
              await db.insert('maktab_academic_reports', data);
              savedCount++;
            }
         }
      }
    }

    if (savedCount == 0) {
      _showError(isUrduGlobal.value
          ? 'کم از کم ایک طالب علم کا ریکارڈ درج کریں!'
          : 'Enter record for at least one student!');
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrduGlobal.value
            ? 'تمام رپورٹس محفوظ ہو گئیں!'
            : 'All reports saved!'),
        backgroundColor: Colors.green));

    SyncService.runTwoWaySync().catchError((_) {});
    
    _checkExistingRecords();
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
                        await SyncUIHelper.performManualSync(context);
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سنک مکمل ہو گیا!"
                                  : "Sync complete!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سنک میں خرابی آئی"
                                  : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
                  ),
                ],
              ),
              body: Column(
                children: [
                  _buildHeaderFilters(isUrdu),
                  Expanded(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(8.0),
                      child: Column(
                        children: [
                          if (_selectedClass != null) ..._buildStudentCards(isUrdu),
                        ],
                      ),
                    ),
                  ),
                  _buildFooterActions(isUrdu),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildHeaderFilters(bool isUrdu) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.all(12),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _selectedClass,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'کلاس منتخب کریں' : 'Select Class',
                    border: const OutlineInputBorder(),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                  ),
                  items: _classes.map((String c) {
                    return DropdownMenuItem<String>(value: c, child: Text(c));
                  }).toList(),
                  onChanged: (v) {
                    setState(() {
                       _selectedClass = v;
                       if (v != null) _loadSubjectsForClass(v);
                    });
                    _checkExistingRecords();
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  controller: _dateController,
                  readOnly: true,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'تاریخ' : 'Date',
                    prefixIcon: const Icon(Icons.calendar_today),
                    border: const OutlineInputBorder(),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                  ),
                  onTap: () async {
                    DateTime? picked = await showDatePicker(
                      context: context,
                      initialDate: DateTime.now(),
                      firstDate: DateTime(2000),
                      lastDate: DateTime.now(),
                    );
                    if (picked != null) {
                      setState(() {
                        _dateController.text = _formatDate(picked);
                      });
                      _checkExistingRecords();
                    }
                  },
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildSubjectInputBlock(int sId, int index, bool isUrdu) {
    if (_availableSubjects.isNotEmpty && index >= _availableSubjects.length) return const SizedBox();
    
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: Colors.grey.shade50,
        border: Border.all(color: Colors.grey.shade300),
        borderRadius: BorderRadius.circular(6)
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _getSubjectName(index),
            style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF284D3D), fontSize: 13),
          ),
          const SizedBox(height: 5),
          Row(
            children: [
              Expanded(
                flex: 3,
                child: Builder(builder: (context) {
                  List<String> books = _availableSubjectBooks.length > index ? List.from(_availableSubjectBooks[index]) : [];
                  books.add('+ نئی کتاب شامل کریں');
                  String? val = _bookControllers[sId]?[index].text;
                  if (val != null && val.isNotEmpty && !books.contains(val)) books.insert(0, val);
                  if (val == null || val.isEmpty) val = null;
                  
                  return DropdownButtonFormField<String>(
                    value: books.contains(val) ? val : null,
                    isExpanded: true,
                    iconSize: 16,
                    decoration: InputDecoration(
                      hintText: isUrdu ? 'کتاب' : 'Book',
                      border: const OutlineInputBorder(),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 5, vertical: 8),
                      isDense: true,
                    ),
                    style: const TextStyle(fontSize: 11, color: Colors.black),
                    items: books.map((b) => DropdownMenuItem(
                      value: b == '+ نئی کتاب شامل کریں' ? 'ADD_NEW' : b, 
                      child: Text(b, overflow: TextOverflow.ellipsis)
                    )).toList(),
                    onChanged: (v) {
                      if (v == 'ADD_NEW') {
                        _showAddBookDialog(sId, index);
                      } else {
                        setState(() {
                          _bookControllers[sId]?[index].text = v ?? '';
                        });
                      }
                    },
                  );
                }),
              ),
              const SizedBox(width: 5),
              Expanded(
                flex: 3,
                child: TextField(
                  controller: _lessonControllers[sId]?[index],
                  decoration: InputDecoration(
                    hintText: isUrdu ? 'مقدار' : 'Quantity',
                    border: const OutlineInputBorder(),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    isDense: true,
                  ),
                  style: const TextStyle(fontSize: 12),
                ),
              ),
              const SizedBox(width: 5),
              Expanded(
                flex: 3,
                child: DropdownButtonFormField<String>(
                  value: _selectedGrades[sId]?[index],
                  isExpanded: true,
                  decoration: const InputDecoration(
                    border: OutlineInputBorder(),
                    contentPadding: EdgeInsets.symmetric(horizontal: 5, vertical: 0),
                    isDense: true,
                  ),
                  style: const TextStyle(fontSize: 11, color: Colors.black),
                  hint: Text(isUrdu ? 'کیفیت' : 'Grade', style: const TextStyle(fontSize: 11)),
                  items: grades.map((g) {
                    return DropdownMenuItem<String>(value: g, child: Text(g, style: const TextStyle(fontSize: 11)));
                  }).toList(),
                  onChanged: (v) {
                    setState(() => _selectedGrades[sId]?[index] = v);
                  },
                ),
              ),
            ],
          )
        ],
      )
    );
  }

  List<Widget> _buildStudentCards(bool isUrdu) {
    final filtered = _allStudents.where(_studentBelongsToSelectedClass).toList();
    if (filtered.isEmpty) {
      return [
        Center(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Text(
                isUrdu ? 'اس کلاس میں کوئی طالب علم نہیں' : 'No students found',
                style: const TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
          ),
        )
      ];
    }

    return filtered.map((student) {
      int sId = student['id'];
      String sName = student['name'];
      String rollNo = student['admission_number']?.toString() ??
          student['roll_number']?.toString() ??
          '';

      return Card(
        margin: const EdgeInsets.only(bottom: 15),
        elevation: 3,
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
                    child: Text(
                      "$sName ($rollNo)",
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: primaryGreen),
                    ),
                  ),
                  if (_existingRecordIds[sId]?.any((id) => id != null) == true)
                    const Icon(Icons.check_circle, color: Colors.green, size: 18)
                ],
              ),
              const Divider(thickness: 1),
              _buildSubjectInputBlock(sId, 0, isUrdu),
              _buildSubjectInputBlock(sId, 1, isUrdu),
              _buildSubjectInputBlock(sId, 2, isUrdu),
              _buildSubjectInputBlock(sId, 3, isUrdu),
              _buildSubjectInputBlock(sId, 4, isUrdu),
              _buildSubjectInputBlock(sId, 5, isUrdu),
            ],
          ),
        ),
      );
    }).toList();
  }

  Widget _buildFooterActions(bool isUrdu) {
    return Container(
      padding: const EdgeInsets.all(12),
      color: Colors.white,
      child: Row(
        children: [
          Expanded(
            child: ElevatedButton.icon(
              onPressed: _saveAll,
              style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen,
                  padding: const EdgeInsets.symmetric(vertical: 15)),
              icon: const Icon(Icons.save, color: Colors.white),
              label: Text(
                isUrdu ? 'تمام رپورٹس محفوظ کریں' : 'Save All Reports',
                style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 16),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
