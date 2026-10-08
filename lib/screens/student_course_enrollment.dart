// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class StudentCourseEnrollmentPage extends StatefulWidget {
  const StudentCourseEnrollmentPage({super.key});

  @override
  State<StudentCourseEnrollmentPage> createState() =>
      _StudentCourseEnrollmentPageState();
}

class _StudentCourseEnrollmentPageState
    extends State<StudentCourseEnrollmentPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color secondaryGreen = const Color(0xFF3B6E57);
  final Color bgColor = const Color(0xFFF8F9FA);

  List<Map<String, dynamic>> _courses = [];
  List<Map<String, dynamic>> _enrollments = [];
  List<Map<String, dynamic>> _allStudents = [];
  int? _selectedCourseId;
  int? _selectedYearNo;
  String _searchQuery = '';
  bool _isLoading = true;

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

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final courses = await db.getCourses(isActive: 1);
    final students = await db.getStudentsForUser();

    setState(() {
      _courses = courses;
      _allStudents = students;
      if (_courses.isNotEmpty && _selectedCourseId == null) {
        _selectedCourseId = _courses.first['id'] as int;
      }
    });
    await _loadEnrollments();
  }

  Future<void> _loadEnrollments() async {
    setState(() => _isLoading = true);
    final data = await DBHelper().getCourseEnrollments(
      courseId: _selectedCourseId,
      yearNo: _selectedYearNo,
    );
    setState(() {
      _enrollments = data;
      _isLoading = false;
    });
  }

  void _showAddEditEnrollmentDialog({Map<String, dynamic>? enrollment}) {
    final isEdit = enrollment != null;
    int? selectedStdId = enrollment?['student_id'] as int?;
    int? selectedCid = (enrollment?['course_id'] as int?) ?? _selectedCourseId;
    int selectedYear =
        (enrollment?['current_year'] as int?) ?? (_selectedYearNo ?? 1);
    final sessionCtrl = TextEditingController(
      text: enrollment?['academic_session'] ??
          '${DateTime.now().year}-${DateTime.now().year + 1}',
    );
    String status = enrollment?['status'] ?? 'active';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          title: Text(
            isEdit ? 'انرولمنٹ میں ترمیم' : 'طالب علم کا کورس میں داخلہ',
            style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
            textAlign: TextAlign.center,
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (!isEdit)
                  DropdownButtonFormField<int>(
                    value: selectedStdId,
                    decoration: const InputDecoration(
                      labelText: 'طالب علم منتخب کریں (لازمی)',
                      border: OutlineInputBorder(),
                    ),
                    items: _allStudents.map((st) {
                      return DropdownMenuItem<int>(
                        value: st['id'] as int,
                        child: Text(
                            '${st['name']} (رول: ${st['roll_number'] ?? '—'})'),
                      );
                    }).toList(),
                    onChanged: (val) => setDlgState(() => selectedStdId = val),
                  )
                else
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                        color: Colors.grey.shade100,
                        borderRadius: BorderRadius.circular(10)),
                    child: Text(
                      'طالب علم: ${enrollment['student_name']} (رول: ${enrollment['roll_number'] ?? '—'})',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  value: selectedCid,
                  decoration: const InputDecoration(
                    labelText: 'کورس منتخب کریں',
                    border: OutlineInputBorder(),
                  ),
                  items: _courses.map((c) {
                    return DropdownMenuItem<int>(
                      value: c['id'] as int,
                      child: Text('${c['name']} (${c['gender'] ?? 'بنین'})'),
                    );
                  }).toList(),
                  onChanged: (val) => setDlgState(() => selectedCid = val),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  value: selectedYear,
                  decoration: const InputDecoration(
                    labelText: 'سال / درجہ',
                    border: OutlineInputBorder(),
                  ),
                  items: List.generate(8, (i) => i + 1).map((y) {
                    return DropdownMenuItem<int>(
                      value: y,
                      child: Text(_yearNames[y] ?? 'سال $y'),
                    );
                  }).toList(),
                  onChanged: (val) =>
                      setDlgState(() => selectedYear = val ?? 1),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: sessionCtrl,
                  textAlign: TextAlign.center,
                  decoration: const InputDecoration(
                    labelText: 'تعلیمی سیشن',
                    hintText: 'مثلاً 2025-2026',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: status,
                  decoration: const InputDecoration(
                    labelText: 'کیفیت (Status)',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(
                        value: 'active', child: Text('فعال (Active)')),
                    DropdownMenuItem(
                        value: 'completed',
                        child: Text('فارغ التحصیل (Completed)')),
                    DropdownMenuItem(
                        value: 'detained', child: Text('روکا گیا (Detained)')),
                    DropdownMenuItem(
                        value: 'dropped', child: Text('خارج (Dropped)')),
                  ],
                  onChanged: (val) =>
                      setDlgState(() => status = val ?? 'active'),
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
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () async {
                if (selectedCid == null) return;
                final session = sessionCtrl.text.trim();

                if (isEdit) {
                  await DBHelper()
                      .updateStudentEnrollment(enrollment['id'] as int, {
                    'course_id': selectedCid,
                    'current_year': selectedYear,
                    'academic_session': session,
                    'status': status,
                  });
                } else {
                  if (selectedStdId == null) return;
                  await DBHelper().enrollStudentInCourse({
                    'student_id': selectedStdId,
                    'course_id': selectedCid,
                    'current_year': selectedYear,
                    'academic_session': session,
                    'enrollment_date':
                        DateTime.now().toIso8601String().substring(0, 10),
                    'status': status,
                  });
                }
                Navigator.pop(ctx);
                _loadEnrollments();
              },
              child: Text(isEdit ? 'اپڈیٹ کریں' : 'داخل کریں'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    final filteredEnrollments = _enrollments.where((e) {
      if (_searchQuery.isEmpty) return true;
      final name = (e['student_name'] ?? '').toString().toLowerCase();
      final roll = (e['roll_number'] ?? '').toString().toLowerCase();
      final father = (e['father_name'] ?? '').toString().toLowerCase();
      final q = _searchQuery.toLowerCase();
      return name.contains(q) || roll.contains(q) || father.contains(q);
    }).toList();

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        drawer: const AppDrawer(),
        appBar: AppBar(
          title: Text(
            isUrdu ? 'طلبہ کورس انرولمنٹ' : 'Student Course Enrollment',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              icon: const Icon(Icons.person_add_alt_1),
              tooltip: isUrdu ? 'نیا داخلہ' : 'Enroll Student',
              onPressed: () => _showAddEditEnrollmentDialog(),
            ),
          ],
        ),
        body: Column(
          children: [
            // Filter Bar
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              color: Colors.white,
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedCourseId,
                          decoration: InputDecoration(
                            labelText:
                                isUrdu ? 'کورس منتخب کریں' : 'Select Course',
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 8),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12)),
                          ),
                          items: [
                            DropdownMenuItem<int>(
                              value: null,
                              child:
                                  Text(isUrdu ? 'تمام کورسز' : 'All Courses'),
                            ),
                            ..._courses.map((c) => DropdownMenuItem<int>(
                                  value: c['id'] as int,
                                  child: Text('${c['name']}'),
                                )),
                          ],
                          onChanged: (val) {
                            setState(() => _selectedCourseId = val);
                            _loadEnrollments();
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedYearNo,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'سال / درجہ' : 'Year',
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 8),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12)),
                          ),
                          items: [
                            DropdownMenuItem<int>(
                              value: null,
                              child: Text(isUrdu ? 'تمام درجات' : 'All Years'),
                            ),
                            ...List.generate(8, (i) => i + 1)
                                .map((y) => DropdownMenuItem<int>(
                                      value: y,
                                      child: Text(_yearNames[y] ?? 'سال $y'),
                                    )),
                          ],
                          onChanged: (val) {
                            setState(() => _selectedYearNo = val);
                            _loadEnrollments();
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    onChanged: (val) => setState(() => _searchQuery = val),
                    textAlign: isUrdu ? TextAlign.right : TextAlign.left,
                    decoration: InputDecoration(
                      hintText: isUrdu
                          ? 'نام یا رول نمبر سے تلاش کریں...'
                          : 'Search by name or roll...',
                      prefixIcon: const Icon(Icons.search),
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 10),
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12)),
                      isDense: true,
                    ),
                  ),
                ],
              ),
            ),

            // Summary Header
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    isUrdu
                        ? 'داخلہ شدہ طلبہ کی فہرست'
                        : 'Enrolled Students List',
                    style: TextStyle(
                        fontWeight: FontWeight.bold,
                        color: primaryGreen,
                        fontSize: 15),
                  ),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                        color: primaryGreen.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(20)),
                    child: Text(
                      'تعداد: ${filteredEnrollments.length}',
                      style: TextStyle(
                          fontWeight: FontWeight.bold,
                          color: primaryGreen,
                          fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),

            // Students List
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : filteredEnrollments.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.person_off_outlined,
                                  size: 50, color: Colors.grey.shade400),
                              const SizedBox(height: 8),
                              Text(
                                isUrdu
                                    ? 'کوئی داخلہ ریکارڈ نہیں ملا'
                                    : 'No enrollments found',
                                style: TextStyle(color: Colors.grey.shade600),
                              ),
                              const SizedBox(height: 8),
                              ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: primaryGreen,
                                  foregroundColor: Colors.white,
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(12)),
                                ),
                                onPressed: () => _showAddEditEnrollmentDialog(),
                                icon: const Icon(Icons.add),
                                label: Text(isUrdu
                                    ? 'طالب علم داخل کریں'
                                    : 'Enroll Student'),
                              ),
                            ],
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 6),
                          itemCount: filteredEnrollments.length,
                          itemBuilder: (ctx, i) {
                            final e = filteredEnrollments[i];
                            final status = e['status'] ?? 'active';
                            final isAct = status == 'active';
                            final yr = e['current_year'] as int? ?? 1;

                            return Card(
                              margin: const EdgeInsets.only(bottom: 8),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14)),
                              elevation: 1,
                              child: ListTile(
                                leading: CircleAvatar(
                                  backgroundColor:
                                      primaryGreen.withOpacity(0.1),
                                  foregroundColor: primaryGreen,
                                  child: const Icon(Icons.person),
                                ),
                                title: Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        e['student_name'] ?? '',
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 15),
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: isAct
                                            ? Colors.green.shade100
                                            : Colors.amber.shade100,
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Text(
                                        isAct ? 'فعال' : status,
                                        style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.bold,
                                          color: isAct
                                              ? Colors.green.shade900
                                              : Colors.amber.shade900,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                subtitle: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const SizedBox(height: 2),
                                    Text(
                                      'رول: ${e['roll_number'] ?? '—'} • ولدیت: ${e['father_name'] ?? '—'}',
                                      style: TextStyle(
                                          fontSize: 12,
                                          color: Colors.grey.shade600),
                                    ),
                                    Text(
                                      'کورس: ${e['course_name'] ?? '—'} • ${_yearNames[yr] ?? "سال $yr"}',
                                      style: TextStyle(
                                          fontSize: 12,
                                          fontWeight: FontWeight.bold,
                                          color: secondaryGreen),
                                    ),
                                    if (e['academic_session'] != null)
                                      Text(
                                        'تعلیمی سیشن: ${e['academic_session']}',
                                        style: TextStyle(
                                            fontSize: 11,
                                            color: Colors.grey.shade500),
                                      ),
                                  ],
                                ),
                                trailing: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    IconButton(
                                      icon: const Icon(Icons.edit,
                                          size: 20, color: Colors.blue),
                                      onPressed: () =>
                                          _showAddEditEnrollmentDialog(
                                              enrollment: e),
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
