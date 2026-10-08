// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class CourseSetupPage extends StatefulWidget {
  const CourseSetupPage({super.key});

  @override
  State<CourseSetupPage> createState() => _CourseSetupPageState();
}

class _CourseSetupPageState extends State<CourseSetupPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color secondaryGreen = const Color(0xFF3B6E57);
  final Color bgColor = const Color(0xFFF8F9FA);

  List<Map<String, dynamic>> _courses = [];
  Map<String, dynamic>? _selectedCourse;
  int _selectedYearNo = 1;
  List<Map<String, dynamic>> _subjects = [];
  bool _isLoading = true;

  final Map<int, String> _darseNizamiYearNames = {
    1: 'سال اول (درجہ اولیٰ)',
    2: 'سال دوم (درجہ ثانیہ)',
    3: 'سال سوم (درجہ ثالثہ)',
    4: 'سال چہارم (درجہ رابعہ)',
    5: 'سال پنجم (درجہ خامسہ)',
    6: 'سال ششم (درجہ سادسہ)',
    7: 'سال ہفتم (درجہ عالیہ)',
    8: 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)',
    9: 'سال نہم (تخصص فی الفقہ)',
    10: 'سال دہم (تخصص فی الحدیث)',
  };

  @override
  void initState() {
    super.initState();
    _loadCourses();
  }

  Future<void> _loadCourses() async {
    setState(() => _isLoading = true);
    final courses = await DBHelper().getCourses();
    setState(() {
      _courses = courses;
      if (_courses.isNotEmpty && _selectedCourse == null) {
        _selectedCourse = _courses.first;
      }
      _isLoading = false;
    });
    if (_selectedCourse != null) {
      await _loadSubjects();
    }
  }

  Future<void> _loadSubjects() async {
    if (_selectedCourse == null) return;
    final subs = await DBHelper().getCourseSubjects(
      _selectedCourse!['id'] as int,
      yearNo: _selectedYearNo,
    );
    setState(() {
      _subjects = subs;
    });
  }

  void _showAddEditCourseDialog({Map<String, dynamic>? course}) {
    final isEdit = course != null;
    final nameCtrl = TextEditingController(text: course?['name'] ?? '');
    String courseType = course?['course_type'] ?? 'Darse_Nizami';
    int totalYears = course?['total_years'] ?? 8;
    String gender = course?['gender'] ?? 'بنین';
    final descCtrl = TextEditingController(text: course?['description'] ?? '');

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          title: Text(
            isEdit ? 'کورس میں ترمیم کریں' : 'نیا تعلیمی کورس شامل کریں',
            style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
            textAlign: TextAlign.center,
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameCtrl,
                  textAlign: TextAlign.right,
                  decoration: const InputDecoration(
                    labelText: 'کورس کا نام (لازمی)',
                    hintText: 'مثلاً درس نظامی بنین، بنات یا عصری',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: courseType,
                  decoration: const InputDecoration(
                    labelText: 'کورس کی نوعیت',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'Darse_Nizami', child: Text('درس نظامی')),
                    DropdownMenuItem(value: 'Asri', child: Text('عصری علوم')),
                    DropdownMenuItem(value: 'Hifz', child: Text('حفظ و تجوید')),
                    DropdownMenuItem(value: 'Other', child: Text('دیگر کورس')),
                  ],
                  onChanged: (val) => setDlgState(() => courseType = val ?? 'Darse_Nizami'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  value: totalYears,
                  decoration: const InputDecoration(
                    labelText: 'کل درجات / سال',
                    border: OutlineInputBorder(),
                  ),
                  items: List.generate(16, (i) => i + 1)
                      .map((y) => DropdownMenuItem(value: y, child: Text('$y سالہ کورس')))
                      .toList(),
                  onChanged: (val) => setDlgState(() => totalYears = val ?? 8),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: gender,
                  decoration: const InputDecoration(
                    labelText: 'صنف (Gender)',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'بنین', child: Text('بنین (طلبہ)')),
                    DropdownMenuItem(value: 'بنات', child: Text('بنات (طالبات)')),
                    DropdownMenuItem(value: 'مشترک', child: Text('مشترک')),
                  ],
                  onChanged: (val) => setDlgState(() => gender = val ?? 'بنین'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: descCtrl,
                  textAlign: TextAlign.right,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'مختصر تفصیل (اختیاری)',
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
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () async {
                final name = nameCtrl.text.trim();
                if (name.isEmpty) return;

                if (isEdit) {
                  await DBHelper().updateCourse(course['id'] as int, {
                    'name': name,
                    'course_type': courseType,
                    'total_years': totalYears,
                    'gender': gender,
                    'description': descCtrl.text.trim(),
                  });
                } else {
                  await DBHelper().insertCourse({
                    'name': name,
                    'course_type': courseType,
                    'total_years': totalYears,
                    'gender': gender,
                    'description': descCtrl.text.trim(),
                    'is_active': 1,
                  });
                }
                Navigator.pop(ctx);
                _loadCourses();
              },
              child: Text(isEdit ? 'اپڈیٹ کریں' : 'محفوظ کریں'),
            ),
          ],
        ),
      ),
    );
  }

  void _showAddEditSubjectDialog({Map<String, dynamic>? subject}) {
    final isEdit = subject != null;
    final yearTitleCtrl = TextEditingController(
      text: subject?['year_title'] ?? (_darseNizamiYearNames[_selectedYearNo] ?? 'سال $_selectedYearNo'),
    );
    final nameCtrl = TextEditingController(text: subject?['subject_name'] ?? '');
    final bookCtrl = TextEditingController(text: subject?['book_name'] ?? '');
    final maxMarksCtrl = TextEditingController(text: (subject?['max_marks'] ?? 100).toString());
    final passMarksCtrl = TextEditingController(text: (subject?['pass_marks'] ?? 40).toString());
    final orderCtrl = TextEditingController(
      text: (subject?['subject_order'] ?? (_subjects.length + 1)).toString(),
    );
    bool isOptional = (subject?['is_optional'] ?? 0) == 1;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          title: Text(
            isEdit ? 'مضمون میں ترمیم کریں' : 'نیا مضمون شامل کریں',
            style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
            textAlign: TextAlign.center,
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: yearTitleCtrl,
                  textAlign: TextAlign.right,
                  decoration: const InputDecoration(
                    labelText: 'درجہ / سال کا عنوان',
                    hintText: 'مثلاً درجہ اولیٰ',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: nameCtrl,
                  textAlign: TextAlign.right,
                  decoration: const InputDecoration(
                    labelText: 'مضمون کا نام (لازمی)',
                    hintText: 'مثلاً صرف، نحو، فقہ، ترجمہ، ریاضی',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: bookCtrl,
                  textAlign: TextAlign.right,
                  decoration: const InputDecoration(
                    labelText: 'کتاب کا نام (اختیاری)',
                    hintText: 'مثلاً علم الصیغہ، ہدایۃ النحو',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: maxMarksCtrl,
                        keyboardType: TextInputType.number,
                        textAlign: TextAlign.center,
                        decoration: const InputDecoration(
                          labelText: 'کل نمبر',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextField(
                        controller: passMarksCtrl,
                        keyboardType: TextInputType.number,
                        textAlign: TextAlign.center,
                        decoration: const InputDecoration(
                          labelText: 'پاسنگ نمبر',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: orderCtrl,
                        keyboardType: TextInputType.number,
                        textAlign: TextAlign.center,
                        decoration: const InputDecoration(
                          labelText: 'ترتیب نمبر',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: CheckboxListTile(
                        title: const Text('اختیاری', style: TextStyle(fontSize: 13)),
                        value: isOptional,
                        contentPadding: EdgeInsets.zero,
                        onChanged: (val) => setDlgState(() => isOptional = val ?? false),
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
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () async {
                final name = nameCtrl.text.trim();
                if (name.isEmpty) return;

                final data = {
                  'course_id': _selectedCourse!['id'],
                  'year_no': _selectedYearNo,
                  'year_title': yearTitleCtrl.text.trim(),
                  'subject_name': name,
                  'book_name': bookCtrl.text.trim(),
                  'max_marks': double.tryParse(maxMarksCtrl.text) ?? 100.0,
                  'pass_marks': double.tryParse(passMarksCtrl.text) ?? 40.0,
                  'subject_order': int.tryParse(orderCtrl.text) ?? 0,
                  'is_optional': isOptional ? 1 : 0,
                };

                if (isEdit) {
                  await DBHelper().updateCourseSubject(subject['id'] as int, data);
                } else {
                  await DBHelper().insertCourseSubject(data);
                }
                Navigator.pop(ctx);
                _loadSubjects();
              },
              child: Text(isEdit ? 'اپڈیٹ کریں' : 'شامل کریں'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          title: Text(
            isUrdu ? 'کورس و نصاب مینیجر' : 'Course & Syllabus Manager',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              icon: const Icon(Icons.add_circle_outline),
              tooltip: isUrdu ? 'نیا کورس' : 'Add Course',
              onPressed: () => _showAddEditCourseDialog(),
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : _courses.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.school_outlined, size: 70, color: Colors.grey.shade400),
                        const SizedBox(height: 16),
                        Text(
                          isUrdu ? 'کوئی تعلیمی کورس موجود نہیں ہے' : 'No courses found',
                          style: TextStyle(fontSize: 18, color: Colors.grey.shade700, fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 12),
                        ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                          ),
                          onPressed: () => _showAddEditCourseDialog(),
                          icon: const Icon(Icons.add),
                          label: Text(isUrdu ? 'پہلا کورس بنائیں' : 'Create First Course'),
                        ),
                      ],
                    ),
                  )
                : Column(
                    children: [
                      // Top Horizontal Course Selector
                      Container(
                        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
                        color: Colors.white,
                        child: Row(
                          children: [
                            Expanded(
                              child: SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                child: Row(
                                  children: _courses.map((c) {
                                    final isSelected = (_selectedCourse?['id'] == c['id']);
                                    return Padding(
                                      padding: const EdgeInsets.only(left: 8.0),
                                      child: ChoiceChip(
                                        label: Text(
                                          '${c['name']} (${c['gender'] ?? 'بنین'})',
                                          style: TextStyle(
                                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                                            color: isSelected ? Colors.white : Colors.black87,
                                          ),
                                        ),
                                        selected: isSelected,
                                        selectedColor: primaryGreen,
                                        backgroundColor: Colors.grey.shade100,
                                        onSelected: (val) {
                                          if (val) {
                                            setState(() {
                                              _selectedCourse = c;
                                              _selectedYearNo = 1;
                                            });
                                            _loadSubjects();
                                          }
                                        },
                                      ),
                                    );
                                  }).toList(),
                                ),
                              ),
                            ),
                            IconButton(
                              icon: const Icon(Icons.edit, color: Colors.blue),
                              tooltip: 'ترمیم کورس',
                              onPressed: () => _showAddEditCourseDialog(course: _selectedCourse),
                            ),
                          ],
                        ),
                      ),

                      // Years Pills Bar
                      if (_selectedCourse != null)
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade200,
                            border: Border(bottom: BorderSide(color: Colors.grey.shade300)),
                          ),
                          child: SingleChildScrollView(
                            scrollDirection: Axis.horizontal,
                            child: Row(
                              children: List.generate(
                                (_selectedCourse!['total_years'] as int? ?? 8),
                                (index) {
                                  final y = index + 1;
                                  final isYearActive = (_selectedYearNo == y);
                                  final title = _darseNizamiYearNames[y] ?? 'سال $y';
                                  return Padding(
                                    padding: const EdgeInsets.only(left: 6.0),
                                    child: InkWell(
                                      onTap: () {
                                        setState(() => _selectedYearNo = y);
                                        _loadSubjects();
                                      },
                                      borderRadius: BorderRadius.circular(20),
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                                        decoration: BoxDecoration(
                                          color: isYearActive ? secondaryGreen : Colors.white,
                                          borderRadius: BorderRadius.circular(20),
                                          boxShadow: isYearActive
                                              ? [BoxShadow(color: secondaryGreen.withOpacity(0.3), blurRadius: 4, offset: const Offset(0, 2))]
                                              : null,
                                        ),
                                        child: Text(
                                          title,
                                          style: TextStyle(
                                            color: isYearActive ? Colors.white : Colors.black87,
                                            fontWeight: isYearActive ? FontWeight.bold : FontWeight.normal,
                                            fontSize: 13,
                                          ),
                                        ),
                                      ),
                                    ),
                                  );
                                },
                              ),
                            ),
                          ),
                        ),

                      // Active Year Header & Add Subject Button
                      Padding(
                        padding: const EdgeInsets.all(12.0),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  _darseNizamiYearNames[_selectedYearNo] ?? 'سال $_selectedYearNo',
                                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: primaryGreen),
                                ),
                                Text(
                                  'کل مضامین: ${_subjects.length}',
                                  style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                ),
                              ],
                            ),
                            ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: primaryGreen,
                                foregroundColor: Colors.white,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                              ),
                              onPressed: () => _showAddEditSubjectDialog(),
                              icon: const Icon(Icons.add, size: 18),
                              label: const Text('نیا مضمون', style: TextStyle(fontWeight: FontWeight.bold)),
                            ),
                          ],
                        ),
                      ),

                      // Subjects List
                      Expanded(
                        child: _subjects.isEmpty
                            ? Center(
                                child: Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    Icon(Icons.menu_book, size: 50, color: Colors.grey.shade400),
                                    const SizedBox(height: 8),
                                    Text(
                                      'اس سال میں کوئی مضمون شامل نہیں ہے',
                                      style: TextStyle(color: Colors.grey.shade600),
                                    ),
                                    const SizedBox(height: 8),
                                    TextButton.icon(
                                      onPressed: () => _showAddEditSubjectDialog(),
                                      icon: const Icon(Icons.add),
                                      label: const Text('پہلا مضمون شامل کریں'),
                                    ),
                                  ],
                                ),
                              )
                            : ListView.builder(
                                padding: const EdgeInsets.symmetric(horizontal: 12),
                                itemCount: _subjects.length,
                                itemBuilder: (ctx, i) {
                                  final s = _subjects[i];
                                  final isOpt = (s['is_optional'] ?? 0) == 1;
                                  return Card(
                                    margin: const EdgeInsets.only(bottom: 8),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                                    elevation: 1,
                                    child: ListTile(
                                      leading: CircleAvatar(
                                        backgroundColor: primaryGreen.withOpacity(0.1),
                                        foregroundColor: primaryGreen,
                                        child: Text('${s['subject_order'] ?? (i + 1)}'),
                                      ),
                                      title: Text(
                                        s['subject_name'] ?? '',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                                      ),
                                      subtitle: Text(
                                        'کتاب: ${s['book_name']?.isNotEmpty == true ? s['book_name'] : '—'} • کل: ${s['max_marks']} • پاس: ${s['pass_marks']}',
                                        style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                      ),
                                      trailing: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                            decoration: BoxDecoration(
                                              color: isOpt ? Colors.amber.shade100 : Colors.green.shade100,
                                              borderRadius: BorderRadius.circular(8),
                                            ),
                                            child: Text(
                                              isOpt ? 'اختیاری' : 'لازمی',
                                              style: TextStyle(
                                                fontSize: 11,
                                                fontWeight: FontWeight.bold,
                                                color: isOpt ? Colors.amber.shade900 : Colors.green.shade900,
                                              ),
                                            ),
                                          ),
                                          IconButton(
                                            icon: const Icon(Icons.edit, size: 20, color: Colors.blue),
                                            onPressed: () => _showAddEditSubjectDialog(subject: s),
                                          ),
                                          IconButton(
                                            icon: const Icon(Icons.delete_outline, size: 20, color: Colors.red),
                                            onPressed: () async {
                                              final confirm = await showDialog<bool>(
                                                context: context,
                                                builder: (c) => AlertDialog(
                                                  title: const Text('تصدیق'),
                                                  content: const Text('کیا آپ واقعی یہ مضمون حذف کرنا چاہتے ہیں؟'),
                                                  actions: [
                                                    TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('منسوخ')),
                                                    TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('حذف کریں', style: TextStyle(color: Colors.red))),
                                                  ],
                                                ),
                                              );
                                              if (confirm == true) {
                                                await DBHelper().deleteCourseSubject(s['id'] as int);
                                                _loadSubjects();
                                              }
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
                  ),
      ),
    );
  }
}
