import 'package:flutter/material.dart';

import '../db_helper.dart';
import 'academic_result_card_view.dart';
import 'subject_results_entry.dart';

class CourseSetupScreen extends StatefulWidget {
  const CourseSetupScreen({super.key});

  @override
  State<CourseSetupScreen> createState() => _CourseSetupScreenState();
}

class _CourseSetupScreenState extends State<CourseSetupScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _db = DBHelper();
  List<Map<String, dynamic>> _courses = [];
  final Map<int, List<Map<String, dynamic>>> _subjectsMap = {};
  List<Map<String, dynamic>> _allStudents = [];
  final Map<int, List<Map<String, dynamic>>> _enrollmentsMap = {};
  int? _selectedCourseId;
  bool _isLoading = true;
  TabController? _tabController;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    _tabController?.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final courses = await _db.getCourseStructures();
    final students = await _db.students.getStudents(activeOnly: false);

    int? activeId = _selectedCourseId;
    if (courses.isNotEmpty) {
      if (activeId == null || !courses.any((c) => c['id'] == activeId)) {
        activeId = courses.first['id'] as int;
      }
    } else {
      activeId = null;
    }

    _selectedCourseId = activeId;
    _courses = courses;
    _allStudents = students;

    if (activeId != null) {
      await _loadCourseDetails(activeId);
    }

    if (!mounted) return;
    setState(() => _isLoading = false);
  }

  Future<void> _loadCourseDetails(int courseId) async {
    final subs = await _db.getCourseSubjects(courseId);
    final enrollments =
        await _db.getStudentCourseEnrollments(courseId: courseId);

    // Group subjects by year
    final Map<int, List<Map<String, dynamic>>> grouped = {};
    for (var s in subs) {
      int y = int.tryParse(s['year_no']?.toString() ?? '1') ?? 1;
      grouped.putIfAbsent(y, () => []).add(s);
    }

    if (!mounted) return;
    setState(() {
      _subjectsMap[courseId] = subs;
      _enrollmentsMap[courseId] = enrollments;
    });
  }

  String _getYearLabel(int yearNo, String courseType) {
    const Map<int, String> dNames = {
      1: 'سال اول (اولیٰ)',
      2: 'سال دوم (ثانیہ)',
      3: 'سال سوم (ثالثہ)',
      4: 'سال چہارم (رابعہ)',
      5: 'سال پنجم (خامسہ)',
      6: 'سال ششم (سادسہ)',
      7: 'سال ہفتم (سابعہ / موقوف علیہ)',
      8: 'سال ہشتم (دورہ حدیث شریف)',
      9: 'تخصص فی الفقہ سال اول',
      10: 'تخصص فی الفقہ سال دوم',
    };
    const Map<int, String> aNames = {
      1: 'کلاس ہفتم (7th)',
      2: 'کلاس ہشتم (8th)',
      3: 'کلاس نہم (9th)',
      4: 'کلاس دہم (10th)',
      5: 'گیارہویں (11th)',
      6: 'بارہویں (12th)',
    };
    if (courseType == 'Asri' || courseType == 'School') {
      return aNames[yearNo] ?? 'کلاس $yearNo';
    }
    return dNames[yearNo] ?? 'سال $yearNo';
  }

  void _showAddEditCourseDialog([Map<String, dynamic>? course]) {
    final isEdit = course != null;
    final nameCtrl =
        TextEditingController(text: course?['name']?.toString() ?? '');
    final descCtrl =
        TextEditingController(text: course?['description']?.toString() ?? '');
    String courseType = course?['course_type']?.toString() ?? 'Darse_Nizami';
    String gender = course?['gender']?.toString() ?? 'بنین';
    int totalYears =
        int.tryParse(course?['total_years']?.toString() ?? '8') ?? 8;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: Row(
              children: [
                Icon(isEdit ? Icons.edit : Icons.add_circle,
                    color: Theme.of(context).primaryColor),
                const SizedBox(width: 8),
                Text(isEdit ? 'ترمیم کورس' : 'نیا تعلیمی کورس بنائیں',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16)),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(
                      labelText: 'کورس کا نام *',
                      hintText: 'مثلاً: درس نظامی بنین، یا بنات کورس',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          value: courseType,
                          decoration: const InputDecoration(
                              labelText: 'نوعیت', border: OutlineInputBorder()),
                          items: const [
                            DropdownMenuItem(
                                value: 'Darse_Nizami',
                                child: Text('درس نظامی')),
                            DropdownMenuItem(
                                value: 'Asri', child: Text('عصری علوم')),
                            DropdownMenuItem(
                                value: 'School', child: Text('سکول')),
                            DropdownMenuItem(
                                value: 'Other', child: Text('دیگر')),
                          ],
                          onChanged: (val) {
                            if (val != null) {
                              setDialogState(() => courseType = val);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          value: gender,
                          decoration: const InputDecoration(
                              labelText: 'صنف', border: OutlineInputBorder()),
                          items: const [
                            DropdownMenuItem(
                                value: 'بنین', child: Text('بنین (طلباء)')),
                            DropdownMenuItem(
                                value: 'بنات', child: Text('بنات (طالبات)')),
                            DropdownMenuItem(
                                value: 'مشترک', child: Text('مشترک')),
                          ],
                          onChanged: (val) {
                            if (val != null) setDialogState(() => gender = val);
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      const Text('کل تعلیمی سال: ',
                          style: TextStyle(fontWeight: FontWeight.bold)),
                      const Spacer(),
                      IconButton(
                        icon: const Icon(Icons.remove_circle_outline),
                        onPressed: totalYears > 1
                            ? () => setDialogState(() => totalYears--)
                            : null,
                      ),
                      Text('$totalYears سال',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 16)),
                      IconButton(
                        icon: const Icon(Icons.add_circle_outline),
                        onPressed: totalYears < 12
                            ? () => setDialogState(() => totalYears++)
                            : null,
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: descCtrl,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'تفصیل / ہدایات (اختیاری)',
                      border: OutlineInputBorder(),
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
                onPressed: () async {
                  final name = nameCtrl.text.trim();
                  if (name.isEmpty) return;
                  if (isEdit) {
                    await _db.updateCourseStructure(course['id'] as int, {
                      'name': name,
                      'course_type': courseType,
                      'total_years': totalYears,
                      'gender': gender,
                      'description': descCtrl.text.trim(),
                    });
                  } else {
                    await _db.insertCourseStructure({
                      'name': name,
                      'course_type': courseType,
                      'total_years': totalYears,
                      'gender': gender,
                      'description': descCtrl.text.trim(),
                    });
                  }
                  if (ctx.mounted) Navigator.pop(ctx);
                  _loadData();
                },
                child: Text(isEdit ? 'تبدیل کریں' : 'محفوظ کریں'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showAddSubjectDialog(int courseId, int yearNo, String yearLabel,
      [Map<String, dynamic>? subject]) {
    final isEdit = subject != null;
    final nameCtrl =
        TextEditingController(text: subject?['subject_name']?.toString() ?? '');
    final bookCtrl =
        TextEditingController(text: subject?['book_name']?.toString() ?? '');
    final maxCtrl =
        TextEditingController(text: subject?['max_marks']?.toString() ?? '100');
    final passCtrl =
        TextEditingController(text: subject?['pass_marks']?.toString() ?? '40');
    final orderCtrl = TextEditingController(
        text: subject?['subject_order']?.toString() ?? '1');
    bool isOptional = (subject?['is_optional'] == 1);

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: Row(
              children: [
                const Icon(Icons.menu_book, color: Colors.indigo),
                const SizedBox(width: 8),
                Text(isEdit ? 'ترمیم مضمون' : 'نیا مضمون ($yearLabel)',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 15)),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(
                      labelText: 'مضمون کا نام *',
                      hintText: 'مثلاً: نحو، فقہ، ریاضی',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: bookCtrl,
                    decoration: const InputDecoration(
                      labelText: 'کتاب کا نام (اختیاری)',
                      hintText: 'مثلاً: ہدایۃ النحو، قدوری',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: maxCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                              labelText: 'کل نمبر',
                              border: OutlineInputBorder()),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: passCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                              labelText: 'پاسنگ', border: OutlineInputBorder()),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: orderCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                              labelText: 'ترتیب', border: OutlineInputBorder()),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: CheckboxListTile(
                          contentPadding: EdgeInsets.zero,
                          title: const Text('اختیاری مضمون',
                              style: TextStyle(fontSize: 13)),
                          value: isOptional,
                          onChanged: (val) =>
                              setDialogState(() => isOptional = val ?? false),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('منسوخ')),
              ElevatedButton(
                onPressed: () async {
                  final sName = nameCtrl.text.trim();
                  if (sName.isEmpty) return;
                  final maxM = double.tryParse(maxCtrl.text.trim()) ?? 100.0;
                  final passM = double.tryParse(passCtrl.text.trim()) ?? 40.0;
                  final ord = int.tryParse(orderCtrl.text.trim()) ?? 1;

                  if (isEdit) {
                    await _db.updateCourseSubject(subject['id'] as int, {
                      'year_no': yearNo,
                      'year_name': yearLabel,
                      'subject_name': sName,
                      'book_name': bookCtrl.text.trim(),
                      'max_marks': maxM,
                      'pass_marks': passM,
                      'subject_order': ord,
                      'is_optional': isOptional ? 1 : 0,
                    });
                  } else {
                    await _db.insertCourseSubject({
                      'course_id': courseId,
                      'year_no': yearNo,
                      'year_name': yearLabel,
                      'subject_name': sName,
                      'book_name': bookCtrl.text.trim(),
                      'max_marks': maxM,
                      'pass_marks': passM,
                      'subject_order': ord,
                      'is_optional': isOptional ? 1 : 0,
                    });
                  }
                  if (ctx.mounted) Navigator.pop(ctx);
                  await _loadCourseDetails(courseId);
                },
                child: Text(isEdit ? 'تبدیل کریں' : 'محفوظ کریں'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showSeedTemplateDialog() {
    String selectedPreset = 'banin_8';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(
              children: [
                Icon(Icons.auto_awesome, color: Colors.amber),
                SizedBox(width: 8),
                Text('ریڈی میڈ نصاب شامل کریں',
                    style:
                        TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                    'تیار شدہ سانچہ منتخب کریں تاکہ تمام سالوں کے مضامین خودکار طور پر درج ہو جائیں:',
                    style: TextStyle(fontSize: 13, color: Colors.black87)),
                const SizedBox(height: 12),
                RadioListTile<String>(
                  title: const Text('درس نظامی (8 سالہ بنین کورس)',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text(
                      'اولیٰ تا دورہ حدیث مع 40 سے زائد کتب و مضامین',
                      style: TextStyle(fontSize: 12)),
                  value: 'banin_8',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
                RadioListTile<String>(
                  title: const Text('درس نظامی (6 سالہ بنات کورس)',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text('عامہ تا عالمیہ للبنات',
                      style: TextStyle(fontSize: 12)),
                  value: 'banat_6',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
                RadioListTile<String>(
                  title: const Text('درس نظامی سال اول: اولیٰ (روایتی نصاب)',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text(
                      'نحو میر، صرف میر، تسہیل الادب، زاد الطالبین، نور الایضاح',
                      style: TextStyle(fontSize: 12)),
                  value: 'ula_standard',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
                RadioListTile<String>(
                  title: const Text('درس نظامی سال اول: اولیٰ عربی',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text(
                      'دروس اللغۃ العربیۃ، القراءۃ الراشدۃ، النحو الواضح، الصرف الواضح، الفقہ المیسر',
                      style: TextStyle(fontSize: 12)),
                  value: 'ula_arabic',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
                RadioListTile<String>(
                  title: const Text(
                      'درس نظامی سال اول: اولیٰ مع میٹرک (2 سالہ: اولیٰ نہم و اولیٰ دہم)',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text(
                      'اولیٰ نہم (نصف دینی + 9th عصری) اور اولیٰ دہم (باقی دینی + 10th عصری)',
                      style: TextStyle(fontSize: 12)),
                  value: 'ula_matric',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
                RadioListTile<String>(
                  title: const Text('عصری علوم (مڈل تا میٹرک 4 سالہ)',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text('ہفتم تا دہم مضامین',
                      style: TextStyle(fontSize: 12)),
                  value: 'asri_middle_matric',
                  groupValue: selectedPreset,
                  onChanged: (val) =>
                      setDialogState(() => selectedPreset = val!),
                ),
              ],
            ),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('منسوخ')),
              ElevatedButton.icon(
                icon: const Icon(Icons.download_done),
                label: const Text('ابھی تیار کریں'),
                onPressed: () async {
                  Navigator.pop(ctx);
                  await _seedPreset(selectedPreset);
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _seedPreset(String preset) async {
    setState(() => _isLoading = true);
    if (preset == 'banin_8') {
      final cId = await _db.insertCourseStructure({
        'name': 'درس نظامی (8 سالہ بنین کورس)',
        'course_type': 'Darse_Nizami',
        'total_years': 8,
        'gender': 'بنین',
        'description': 'عامہ تا دورہ حدیث (وفاق المدارس کے نصاب کے مطابق)',
      });

      final standardYears = {
        1: {
          'name': 'اولیٰ (عامہ سال اول)',
          'subjects': [
            ['نحو میر', 'نحو میر'],
            ['صرف میر', 'صرف میر'],
            ['تسہیل الادب', 'تسہیل الادب'],
            ['زاد الطالبین', 'زاد الطالبین'],
            ['فقہ حنفی', 'نور الایضاح']
          ]
        },
        2: {
          'name': 'ثانیہ (عامہ سال دوم)',
          'subjects': [
            ['ہدایۃ النحو', 'ہدایۃ النحو'],
            ['علم الصیغہ', 'علم الصیغہ'],
            ['قدوری', 'مختصر القدوری'],
            ['نفحة الیمن', 'نفحة الیمن'],
            ['قصص النبیین', 'قصص النبیین']
          ]
        },
        3: {
          'name': 'ثالثہ (خاصہ سال اول)',
          'subjects': [
            ['کافیہ', 'الکافیہ'],
            ['اصول الشاشی', 'اصول الشاشی'],
            ['قدوری (مکمل)', 'مختصر القدوری'],
            ['مقامات حریری', 'مقامات حریری']
          ]
        },
        4: {
          'name': 'رابعہ (خاصہ سال دوم)',
          'subjects': [
            ['شرح جامی', 'شرح جامی'],
            ['کنز الدقائق', 'کنز الدقائق'],
            ['نور الانوار', 'نور الانوار'],
            ['شرح التہذیب', 'شرح التہذیب']
          ]
        },
        5: {
          'name': 'خامسہ (عالیہ سال اول)',
          'subjects': [
            ['ہدایہ اول', 'الہدایۃ جلد 1'],
            ['حسامی', 'المنتخب فی اصول المذہب'],
            ['دیوان الحماسہ', 'دیوان الحماسہ'],
            ['مختصر المعانی', 'مختصر المعانی']
          ]
        },
        6: {
          'name': 'سادسہ (عالیہ سال دوم)',
          'subjects': [
            ['ہدایہ ثالث', 'الہدایۃ جلد 3'],
            ['توضیح و تلویح', 'التوضیح والتلویح'],
            ['جلالین', 'تفسیر الجلالین'],
            ['مسلم الثبوت', 'مسلم الثبوت']
          ]
        },
        7: {
          'name': 'سابعہ (عالمیہ سال اول)',
          'subjects': [
            ['مشکوٰۃ المصابیح', 'مشکوٰۃ شریف جلد 1 و 2'],
            ['ہدایہ رابع', 'الہدایۃ جلد 4'],
            ['شرح العقائد', 'شرح العقائد'],
            ['بیضاوی', 'تفسیر البیضاوی']
          ]
        },
        8: {
          'name': 'دورہ حدیث شریف',
          'subjects': [
            ['صحیح البخاری', 'بخاری شریف'],
            ['صحیح مسلم', 'مسلم شریف'],
            ['جامع الترمذی', 'ترمذی شریف'],
            ['سنن ابی داؤد', 'ابو داؤد شریف'],
            ['سنن النسائی', 'نسائی شریف'],
            ['سنن ابن ماجہ', 'ابن ماجہ شریف'],
            ['موطا امام مالک', 'موطا'],
            ['شرح معانی الآثار', 'طحاوی شریف']
          ]
        },
      };

      for (var entry in standardYears.entries) {
        int yNum = entry.key;
        String yName = entry.value['name'] as String;
        var subs = entry.value['subjects'] as List<List<String>>;
        int ord = 1;
        for (var sb in subs) {
          await _db.insertCourseSubject({
            'course_id': cId,
            'year_no': yNum,
            'year_name': yName,
            'subject_name': sb[0],
            'book_name': sb[1],
            'max_marks': 100.0,
            'pass_marks': 40.0,
            'subject_order': ord++,
          });
        }
      }
    } else if (preset == 'asri_middle_matric') {
      final cId = await _db.insertCourseStructure({
        'name': 'عصری علوم (مڈل تا میٹرک)',
        'course_type': 'Asri',
        'total_years': 4,
        'gender': 'مشترک',
        'description': 'ہفتم، ہشتم، نہم، دہم',
      });
      final asriYears = {
        1: {
          'name': 'ہفتم (7th)',
          'subjects': [
            'انگریزی',
            'ریاضی',
            'اردو',
            'سائنس',
            'اسلامیات',
            'مطالعہ پاکستان'
          ]
        },
        2: {
          'name': 'ہشتم (8th)',
          'subjects': [
            'انگریزی',
            'ریاضی',
            'اردو',
            'سائنس',
            'اسلامیات',
            'مطالعہ پاکستان'
          ]
        },
        3: {
          'name': 'نہم (9th)',
          'subjects': [
            'انگریزی',
            'ریاضی',
            'اردو',
            'فزکس',
            'کیمسٹری',
            'کمپیوٹر / بائیو',
            'اسلامیات'
          ]
        },
        4: {
          'name': 'دہم (10th)',
          'subjects': [
            'انگریزی',
            'ریاضی',
            'اردو',
            'فزکس',
            'کیمسٹری',
            'کمپیوٹر / بائیو',
            'مطالعہ پاکستان'
          ]
        },
      };
      for (var entry in asriYears.entries) {
        int yNum = entry.key;
        String yName = entry.value['name'] as String;
        var subs = entry.value['subjects'] as List<String>;
        int ord = 1;
        for (var sb in subs) {
          await _db.insertCourseSubject({
            'course_id': cId,
            'year_no': yNum,
            'year_name': yName,
            'subject_name': sb,
            'max_marks': 100.0,
            'pass_marks': 33.0,
            'subject_order': ord++,
          });
        }
      }
    } else if (preset == 'ula_standard') {
      final cId = await _db.insertCourseStructure({
        'name': 'درس نظامی سال اول (اولیٰ روایتی)',
        'course_type': 'Darse_Nizami',
        'total_years': 1,
        'gender': 'بنین',
        'description': 'اولیٰ عامہ سال اول کا معیاری روایتی نصاب',
      });
      final ulaSubs = [
        'نحو میر',
        'صرف میر',
        'تسہیل الادب',
        'زاد الطالبین',
        'نور الایضاح'
      ];
      int uOrd = 1;
      for (var sb in ulaSubs) {
        await _db.insertCourseSubject({
          'course_id': cId,
          'year_no': 1,
          'year_name': 'اولیٰ (عامہ سال اول)',
          'subject_name': sb,
          'book_name': sb,
          'max_marks': 100.0,
          'pass_marks': 40.0,
          'subject_order': uOrd++,
        });
      }
    } else if (preset == 'ula_arabic') {
      final cId = await _db.insertCourseStructure({
        'name': 'درس نظامی سال اول (اولیٰ عربی)',
        'course_type': 'Darse_Nizami',
        'total_years': 1,
        'gender': 'مشترک',
        'description': 'عربی کتب و عربی میڈیم تیاری کا نصاب',
      });
      final arabicSubs = [
        'دروس اللغۃ العربیۃ',
        'القراءۃ الراشدۃ',
        'النحو الواضح',
        'الصرف الواضح',
        'الفقہ المیسر',
        'زاد الطالبین',
      ];
      int aOrd = 1;
      for (var sb in arabicSubs) {
        await _db.insertCourseSubject({
          'course_id': cId,
          'year_no': 1,
          'year_name': 'اولیٰ عربی',
          'subject_name': sb,
          'book_name': sb,
          'max_marks': 100.0,
          'pass_marks': 40.0,
          'subject_order': aOrd++,
        });
      }
    } else if (preset == 'ula_matric') {
      final cId = await _db.insertCourseStructure({
        'name': 'درس نظامی مع میٹرک (اولیٰ نہم و دہم)',
        'course_type': 'Darse_Nizami',
        'total_years': 2,
        'gender': 'مشترک',
        'description':
            'اولیٰ نہم (نصف دینی + 9th عصری) اور اولیٰ دہم (باقی دینی + 10th عصری)',
      });
      final matricYears = {
        1: {
          'name': 'اولیٰ نہم (مع میٹرک 9th)',
          'subjects': [
            'نحو میر (حصہ اول)',
            'صرف میر (حصہ اول)',
            'تسہیل الادب (حصہ اول)',
            'زاد الطالبین (حصہ اول)',
            'انگریزی (9th)',
            'اردو (9th)',
            'ریاضی (9th)',
            'مطالعہ پاکستان (9th)',
            'جنرل سائنس (9th)',
            'اسلامیات لازمی (9th)',
          ]
        },
        2: {
          'name': 'اولیٰ دہم (مع میٹرک 10th)',
          'subjects': [
            'نحو میر (مکمل)',
            'علم الصیغہ',
            'نور الایضاح (فقہ)',
            'زاد الطالبین (مکمل)',
            'انگریزی (10th)',
            'اردو (10th)',
            'ریاضی (10th)',
            'مطالعہ پاکستان (10th)',
            'جنرل سائنس (10th)',
          ]
        },
      };
      for (var entry in matricYears.entries) {
        int yNum = entry.key;
        String yName = entry.value['name'] as String;
        var subs = entry.value['subjects'] as List<String>;
        int mOrd = 1;
        for (var sb in subs) {
          await _db.insertCourseSubject({
            'course_id': cId,
            'year_no': yNum,
            'year_name': yName,
            'subject_name': sb,
            'book_name': sb,
            'max_marks': 100.0,
            'pass_marks': 33.0,
            'subject_order': mOrd++,
          });
        }
      }
    } else {
      await _db.insertCourseStructure({
        'name': 'درس نظامی (6 سالہ بنات کورس)',
        'course_type': 'Darse_Nizami',
        'total_years': 6,
        'gender': 'بنات',
        'description': 'عامہ تا دورہ حدیث للبنات',
      });
    }

    await _loadData();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('ریڈی میڈ نصاب کامیابی سے تیار ہو گیا!'),
            backgroundColor: Colors.green),
      );
    }
  }

  void _showEnrollStudentDialog(
      int courseId, int totalYears, String courseType) {
    int? selectedStdId;
    int selectedYear = 1;
    final rollCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(
              children: [
                Icon(Icons.person_add, color: Colors.teal),
                SizedBox(width: 8),
                Text('طالب علم کا داخلہ / انرولمنٹ',
                    style:
                        TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  DropdownButtonFormField<int>(
                    value: selectedStdId,
                    decoration: const InputDecoration(
                        labelText: 'طالب علم منتخب کریں *',
                        border: OutlineInputBorder()),
                    items: _allStudents.map((s) {
                      final sId = s['id'] as int;
                      final sName = s['name']?.toString() ?? '';
                      final fName = s['father_name']?.toString() ?? '';
                      final roll =
                          s['admission_number'] ?? s['roll_number'] ?? '';
                      return DropdownMenuItem<int>(
                        value: sId,
                        child: Text('$sName ولد $fName ($roll)',
                            style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) =>
                        setDialogState(() => selectedStdId = val),
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    value: selectedYear,
                    decoration: const InputDecoration(
                        labelText: 'داخلہ سال / درجہ',
                        border: OutlineInputBorder()),
                    items: List.generate(totalYears, (i) => i + 1).map((y) {
                      return DropdownMenuItem<int>(
                        value: y,
                        child: Text(_getYearLabel(y, courseType)),
                      );
                    }).toList(),
                    onChanged: (val) {
                      if (val != null) setDialogState(() => selectedYear = val);
                    },
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: rollCtrl,
                    decoration: const InputDecoration(
                        labelText: 'رول نمبر (اختیاری)',
                        border: OutlineInputBorder()),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('منسوخ')),
              ElevatedButton(
                onPressed: () async {
                  if (selectedStdId == null) return;
                  await _db.saveStudentCourseEnrollment({
                    'student_id': selectedStdId,
                    'course_id': courseId,
                    'current_year': selectedYear,
                    'roll_number': rollCtrl.text.trim(),
                    'enrollment_date':
                        DateTime.now().toIso8601String().substring(0, 10),
                    'status': 'Active',
                  });
                  if (ctx.mounted) Navigator.pop(ctx);
                  await _loadCourseDetails(courseId);
                },
                child: const Text('داخلہ محفوظ کریں'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final activeCourse = _courses.firstWhere(
      (c) => c['id'] == _selectedCourseId,
      orElse: () => {},
    );

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('کورسز و مضامین (درس نظامی و عصری)',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17)),
          backgroundColor: const Color(0xFF1E3C72),
          foregroundColor: Colors.white,
          elevation: 2,
          actions: [
            IconButton(
              icon: const Icon(Icons.assignment_turned_in_outlined),
              tooltip: 'امتحانی نتائج اندراج',
              onPressed: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (context) => SubjectResultsEntryPage(
                        initialCourseId: _selectedCourseId),
                  ),
                );
              },
            ),
            IconButton(
              icon: const Icon(Icons.auto_awesome),
              tooltip: 'ریڈی میڈ نصاب',
              onPressed: _showSeedTemplateDialog,
            ),
            IconButton(
              icon: const Icon(Icons.add_circle_outline),
              tooltip: 'نیا کورس بنائیں',
              onPressed: () => _showAddEditCourseDialog(),
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : _courses.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24.0),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.school_outlined,
                              size: 70, color: Colors.grey.shade400),
                          const SizedBox(height: 16),
                          const Text('کوئی کورس موجود نہیں ہے!',
                              style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 18,
                                  color: Colors.black87)),
                          const SizedBox(height: 8),
                          const Text(
                              'درس نظامی، عصری علوم یا دیگر کلاسز کے لیے نیا کورس بنائیں یا ریڈی میڈ نصاب شامل کریں۔',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: Colors.grey)),
                          const SizedBox(height: 20),
                          ElevatedButton.icon(
                            icon: const Icon(Icons.auto_awesome),
                            label: const Text('ریڈی میڈ نصاب امپورٹ کریں'),
                            style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF1E3C72),
                                foregroundColor: Colors.white),
                            onPressed: _showSeedTemplateDialog,
                          ),
                        ],
                      ),
                    ),
                  )
                : Column(
                    children: [
                      // Course Selector Dropdown / Chips Bar
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 16, vertical: 10),
                        color: Colors.grey.shade100,
                        child: Row(
                          children: [
                            const Text('منتخب کورس: ',
                                style: TextStyle(fontWeight: FontWeight.bold)),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Container(
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 12),
                                decoration: BoxDecoration(
                                  color: Colors.white,
                                  borderRadius: BorderRadius.circular(8),
                                  border:
                                      Border.all(color: Colors.grey.shade300),
                                ),
                                child: DropdownButtonHideUnderline(
                                  child: DropdownButton<int>(
                                    value: _selectedCourseId,
                                    isExpanded: true,
                                    items: _courses.map((c) {
                                      return DropdownMenuItem<int>(
                                        value: c['id'] as int,
                                        child: Text(
                                          "${c['name']} (${c['gender']} - ${c['total_years']} سال)",
                                          style: const TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 13),
                                        ),
                                      );
                                    }).toList(),
                                    onChanged: (val) {
                                      if (val != null) {
                                        setState(() => _selectedCourseId = val);
                                        _loadCourseDetails(val);
                                      }
                                    },
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            IconButton(
                              icon: const Icon(Icons.edit, color: Colors.blue),
                              tooltip: 'ترمیم کورس',
                              onPressed: () =>
                                  _showAddEditCourseDialog(activeCourse),
                            ),
                            IconButton(
                              icon: const Icon(Icons.delete_outline,
                                  color: Colors.red),
                              tooltip: 'حذف کورس',
                              onPressed: () async {
                                final confirm = await showDialog<bool>(
                                  context: context,
                                  builder: (ctx) => AlertDialog(
                                    title: const Text('کورس حذف کریں؟'),
                                    content: const Text(
                                        'کیا آپ واقعی یہ کورس حذف کرنا چاہتے ہیں؟'),
                                    actions: [
                                      TextButton(
                                          onPressed: () =>
                                              Navigator.pop(ctx, false),
                                          child: const Text('منسوخ')),
                                      TextButton(
                                          onPressed: () =>
                                              Navigator.pop(ctx, true),
                                          child: const Text('حذف کریں',
                                              style: TextStyle(
                                                  color: Colors.red))),
                                    ],
                                  ),
                                );
                                if (confirm == true &&
                                    _selectedCourseId != null) {
                                  await _db.deleteCourseStructure(
                                      _selectedCourseId!);
                                  _loadData();
                                }
                              },
                            ),
                          ],
                        ),
                      ),

                      // Tabs for Subjects vs Enrolled Students
                      Expanded(
                        child: DefaultTabController(
                          length: 2,
                          child: Column(
                            children: [
                              Container(
                                color: Colors.white,
                                child: TabBar(
                                  labelColor: const Color(0xFF1E3C72),
                                  unselectedLabelColor: Colors.grey,
                                  indicatorColor: const Color(0xFF1E3C72),
                                  indicatorWeight: 3,
                                  labelStyle: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14),
                                  tabs: [
                                    Tab(
                                      text:
                                          "مضامین و کتب (${_subjectsMap[_selectedCourseId]?.length ?? 0})",
                                      icon:
                                          const Icon(Icons.menu_book, size: 20),
                                    ),
                                    Tab(
                                      text:
                                          "داخل شدہ طلبہ (${_enrollmentsMap[_selectedCourseId]?.length ?? 0})",
                                      icon: const Icon(Icons.how_to_reg,
                                          size: 20),
                                    ),
                                  ],
                                ),
                              ),
                              Expanded(
                                child: TabBarView(
                                  children: [
                                    _buildSubjectsTabView(activeCourse),
                                    _buildEnrollmentsTabView(activeCourse),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
      ),
    );
  }

  Widget _buildSubjectsTabView(Map<String, dynamic> course) {
    if (course.isEmpty) return const SizedBox.shrink();
    final cId = course['id'] as int;
    final totalYears =
        int.tryParse(course['total_years']?.toString() ?? '8') ?? 8;
    final cType = course['course_type']?.toString() ?? 'Darse_Nizami';
    final allSubs = _subjectsMap[cId] ?? [];

    final Map<int, List<Map<String, dynamic>>> grouped = {};
    for (var s in allSubs) {
      int y = int.tryParse(s['year_no']?.toString() ?? '1') ?? 1;
      grouped.putIfAbsent(y, () => []).add(s);
    }

    return ListView.builder(
      padding: const EdgeInsets.all(12),
      itemCount: totalYears,
      itemBuilder: (context, index) {
        final yearNo = index + 1;
        final yearLabel = _getYearLabel(yearNo, cType);
        final yearSubs = grouped[yearNo] ?? [];

        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 1.5,
          child: Theme(
            data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
            child: ExpansionTile(
              initiallyExpanded: yearNo == 1,
              leading: CircleAvatar(
                backgroundColor: const Color(0xFF1E3C72).withOpacity(0.12),
                child: Text('$yearNo',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, color: Color(0xFF1E3C72))),
              ),
              title: Text(yearLabel,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
              subtitle: Text('${yearSubs.length} مضامین',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    icon:
                        const Icon(Icons.add_circle, color: Color(0xFF1E3C72)),
                    tooltip: 'مضمون شامل کریں',
                    onPressed: () =>
                        _showAddSubjectDialog(cId, yearNo, yearLabel),
                  ),
                  const Icon(Icons.expand_more),
                ],
              ),
              children: [
                if (yearSubs.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 16),
                    child: Text('اس سال میں ابھی کوئی مضمون درج نہیں ہے۔',
                        style: TextStyle(color: Colors.grey, fontSize: 12)),
                  )
                else
                  ListView.separated(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    itemCount: yearSubs.length,
                    separatorBuilder: (_, __) =>
                        Divider(height: 1, color: Colors.grey.shade200),
                    itemBuilder: (context, sIdx) {
                      final sub = yearSubs[sIdx];
                      final isOpt = (sub['is_optional'] == 1);
                      return ListTile(
                        dense: true,
                        leading: CircleAvatar(
                          radius: 12,
                          backgroundColor: Colors.grey.shade200,
                          child: Text('${sub['subject_order'] ?? (sIdx + 1)}',
                              style: const TextStyle(
                                  fontSize: 11, color: Colors.black87)),
                        ),
                        title: Text(sub['subject_name']?.toString() ?? '',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13)),
                        subtitle: Text(
                          "کتاب: ${sub['book_name']?.toString().isNotEmpty == true ? sub['book_name'] : '-'} | کل: ${sub['max_marks']} | پاسنگ: ${sub['pass_marks']}",
                          style: TextStyle(
                              fontSize: 11, color: Colors.grey.shade700),
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (isOpt)
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                    color: Colors.amber.shade100,
                                    borderRadius: BorderRadius.circular(4)),
                                child: const Text('اختیاری',
                                    style: TextStyle(
                                        fontSize: 10, color: Colors.brown)),
                              ),
                            IconButton(
                              icon: const Icon(Icons.delete_outline,
                                  color: Colors.red, size: 18),
                              onPressed: () async {
                                final sId = sub['id'] as int;
                                await _db.deleteCourseSubject(sId);
                                await _loadCourseDetails(cId);
                              },
                            ),
                          ],
                        ),
                      );
                    },
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildEnrollmentsTabView(Map<String, dynamic> course) {
    if (course.isEmpty) return const SizedBox.shrink();
    final cId = course['id'] as int;
    final totalYears =
        int.tryParse(course['total_years']?.toString() ?? '8') ?? 8;
    final cType = course['course_type']?.toString() ?? 'Darse_Nizami';
    final enrollments = _enrollmentsMap[cId] ?? [];

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12.0),
          child: Row(
            children: [
              Text('کل داخل شدہ طلبہ: ${enrollments.length}',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
              const Spacer(),
              ElevatedButton.icon(
                icon: const Icon(Icons.person_add, size: 18),
                label: const Text('طالب علم کا داخلہ'),
                style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.teal,
                    foregroundColor: Colors.white),
                onPressed: () =>
                    _showEnrollStudentDialog(cId, totalYears, cType),
              ),
            ],
          ),
        ),
        Expanded(
          child: enrollments.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.person_off_outlined,
                          size: 50, color: Colors.grey.shade400),
                      const SizedBox(height: 12),
                      const Text(
                          'اس کورس میں فی الوقت کوئی طالب علم داخل نہیں ہے۔',
                          style: TextStyle(color: Colors.grey)),
                    ],
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  itemCount: enrollments.length,
                  itemBuilder: (context, index) {
                    final en = enrollments[index];
                    final sId = en['student_id'];
                    final std = _allStudents.firstWhere((s) => s['id'] == sId,
                        orElse: () => {});
                    final sName = std['name']?.toString() ?? 'طالب علم #$sId';
                    final fName = std['father_name']?.toString() ?? '';
                    final curYear =
                        int.tryParse(en['current_year']?.toString() ?? '1') ??
                            1;

                    return Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10)),
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: Colors.teal.shade50,
                          child: const Icon(Icons.school, color: Colors.teal),
                        ),
                        title: Text(sName,
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14)),
                        subtitle: Text(
                            "ولدیت: $fName | رول نمبر: ${en['roll_number'] ?? '-'}",
                            style: const TextStyle(fontSize: 12)),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Chip(
                              label: Text(_getYearLabel(curYear, cType),
                                  style: const TextStyle(
                                      fontSize: 11, color: Colors.white)),
                              backgroundColor: const Color(0xFF1E3C72),
                              padding: EdgeInsets.zero,
                            ),
                            const SizedBox(width: 4),
                            IconButton(
                              icon: const Icon(Icons.print_outlined,
                                  size: 18, color: Colors.teal),
                              tooltip: 'نتیجہ کارڈ (DMC)',
                              onPressed: () async {
                                final subs = await _db.getCourseSubjects(cId,
                                    yearNo: curYear);
                                final stdDetails = await _db
                                    .getEnrolledStudentsWithDetails(cId,
                                        yearNo: curYear);
                                final exams = await _db.getExams();
                                if (!mounted) return;
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (context) =>
                                        AcademicResultCardView(
                                      exam:
                                          exams.isNotEmpty ? exams.first : null,
                                      course: course,
                                      yearNo: curYear,
                                      students: stdDetails,
                                      subjects: subs,
                                      initialStudent: stdDetails.firstWhere(
                                        (s) => s['student_id'] == sId,
                                        orElse: () => {
                                          'student_id': sId,
                                          'student_name': sName,
                                          'father_name': fName
                                        },
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }
}

typedef CourseSetupPage = CourseSetupScreen;
