// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class StudentPromotionPage extends StatefulWidget {
  const StudentPromotionPage({super.key});

  @override
  State<StudentPromotionPage> createState() => _StudentPromotionPageState();
}

class _StudentPromotionPageState extends State<StudentPromotionPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color secondaryGreen = const Color(0xFF3B6E57);
  final Color bgColor = const Color(0xFFF8F9FA);

  List<Map<String, dynamic>> _courses = [];
  List<Map<String, dynamic>> _students = [];

  int? _selectedCourseId;
  int _selectedFromYear = 1;
  int _selectedToYear = 2;
  late TextEditingController _sessionCtrl;

  // [student_id] => action
  final Map<int, String> _studentActions = {};
  // [student_id] => remarks
  final Map<int, TextEditingController> _remarksControllers = {};

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
    final nextYear = DateTime.now().year + 1;
    _sessionCtrl = TextEditingController(text: '$nextYear-${nextYear + 1}');
    _loadInitialData();
  }

  @override
  void dispose() {
    _sessionCtrl.dispose();
    for (var c in _remarksControllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);
    final courses = await DBHelper().getCourses(isActive: 1);
    setState(() {
      _courses = courses;
      if (_courses.isNotEmpty && _selectedCourseId == null) {
        _selectedCourseId = _courses.first['id'] as int;
      }
    });
    await _loadStudents();
  }

  Future<void> _loadStudents() async {
    if (_selectedCourseId == null) {
      setState(() => _isLoading = false);
      return;
    }
    setState(() => _isLoading = true);
    final data = await DBHelper().getCourseEnrollments(
      courseId: _selectedCourseId,
      yearNo: _selectedFromYear,
      status: 'active',
    );

    _studentActions.clear();
    for (var c in _remarksControllers.values) {
      c.dispose();
    }
    _remarksControllers.clear();

    for (var s in data) {
      final sid = s['student_id'] as int;
      _studentActions[sid] = 'Promoted';
      _remarksControllers[sid] = TextEditingController();
    }

    setState(() {
      _students = data;
      _selectedToYear = (_selectedFromYear < 8) ? (_selectedFromYear + 1) : 8;
      _isLoading = false;
    });
  }

  void _setAllActions(String action) {
    setState(() {
      for (var s in _students) {
        final sid = s['student_id'] as int;
        _studentActions[sid] = action;
      }
    });
  }

  Future<void> _applyPromotions() async {
    if (_students.isEmpty || _selectedCourseId == null) return;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('تصدیق سالانہ ترقی'),
        content: Text(
          'کیا آپ واقعی ${_students.length} طلبہ کی سالانہ ترقی کا نفاذ کرنا چاہتے ہیں؟',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('منسوخ')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(c, true),
            child: const Text('ہاں، لاگو کریں'),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    setState(() => _isSaving = true);
    final db = DBHelper();
    int promotedCount = 0;

    for (var s in _students) {
      final sid = s['student_id'] as int;
      final action = _studentActions[sid] ?? 'Promoted';
      final remarks = _remarksControllers[sid]?.text.trim() ?? '';
      final effToYear = (action == 'Promoted') ? _selectedToYear : _selectedFromYear;

      await db.promoteStudent({
        'tenant_id': 1,
        'student_id': sid,
        'course_id': _selectedCourseId,
        'from_year': _selectedFromYear,
        'to_year': effToYear,
        'academic_session': _sessionCtrl.text.trim(),
        'status': action,
        'remarks': remarks,
      });
      promotedCount++;
    }

    setState(() => _isSaving = false);

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('✅ $promotedCount طلبہ کی سالانہ ترقی کامیابی سے نافذ ہو گئی!'),
        backgroundColor: Colors.green,
      ),
    );

    await _loadStudents();
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
            isUrdu ? 'سالانہ ترقی / پروموشن' : 'Annual Promotion',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
        ),
        body: Column(
          children: [
            // Filter Bar
            Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: Column(
                children: [
                  Row(
                    children: [
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
                            _loadStudents();
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedFromYear,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'موجودہ سال' : 'From Year',
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          items: List.generate(8, (i) => i + 1).map((y) {
                            return DropdownMenuItem<int>(
                              value: y,
                              child: Text(_yearNames[y] ?? 'سال $y', overflow: TextOverflow.ellipsis),
                            );
                          }).toList(),
                          onChanged: (val) {
                            setState(() => _selectedFromYear = val ?? 1);
                            _loadStudents();
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: DropdownButtonFormField<int>(
                          value: _selectedToYear,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'اگلا سال (Target)' : 'To Year',
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          items: List.generate(8, (i) => i + 1).map((y) {
                            return DropdownMenuItem<int>(
                              value: y,
                              child: Text(_yearNames[y] ?? 'سال $y', overflow: TextOverflow.ellipsis),
                            );
                          }).toList(),
                          onChanged: (val) => setState(() => _selectedToYear = val ?? 2),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: _sessionCtrl,
                          textAlign: TextAlign.center,
                          decoration: InputDecoration(
                            labelText: isUrdu ? 'نیا سیشن' : 'New Session',
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            // Bulk Actions Bar
            if (_students.isNotEmpty)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                color: Colors.grey.shade100,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'کل طلبہ: ${_students.length}',
                      style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen),
                    ),
                    Row(
                      children: [
                        OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            foregroundColor: Colors.green.shade800,
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          ),
                          onPressed: () => _setAllActions('Promoted'),
                          child: const Text('سب کو ترقی دیں', style: TextStyle(fontSize: 11)),
                        ),
                        const SizedBox(width: 6),
                        OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            foregroundColor: Colors.amber.shade900,
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          ),
                          onPressed: () => _setAllActions('Detained'),
                          child: const Text('سب کو روکیں', style: TextStyle(fontSize: 11)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

            // Students List
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _students.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.check_circle_outline, size: 50, color: Colors.grey.shade400),
                              const SizedBox(height: 10),
                              Text(
                                isUrdu ? 'اس سال میں کوئی متحرک طالب علم نہیں ہے' : 'No active students',
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
                            final currentAction = _studentActions[sid] ?? 'Promoted';

                            return Card(
                              margin: const EdgeInsets.only(bottom: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                              elevation: 1,
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Column(
                                  children: [
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
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                                              ),
                                              Text(
                                                'ولد: ${st['father_name'] ?? '—'}',
                                                style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                              ),
                                            ],
                                          ),
                                        ),
                                        DropdownButton<String>(
                                          value: currentAction,
                                          underline: const SizedBox(),
                                          items: const [
                                            DropdownMenuItem(value: 'Promoted', child: Text('ترقی یافتہ', style: TextStyle(color: Colors.green, fontWeight: FontWeight.bold))),
                                            DropdownMenuItem(value: 'Detained', child: Text('روکا گیا', style: TextStyle(color: Colors.amber, fontWeight: FontWeight.bold))),
                                            DropdownMenuItem(value: 'Graduated', child: Text('فارغ التحصیل', style: TextStyle(color: Colors.blue, fontWeight: FontWeight.bold))),
                                            DropdownMenuItem(value: 'Dropped', child: Text('خارج', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold))),
                                          ],
                                          onChanged: (val) {
                                            if (val != null) {
                                              setState(() => _studentActions[sid] = val);
                                            }
                                          },
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 6),
                                    TextField(
                                      controller: _remarksControllers[sid],
                                      textAlign: isUrdu ? TextAlign.right : TextAlign.left,
                                      style: const TextStyle(fontSize: 12),
                                      decoration: InputDecoration(
                                        hintText: isUrdu ? 'ملاحظات / کیفیت (اختیاری)...' : 'Remarks (optional)...',
                                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                                        isDense: true,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
            ),

            // Bottom Apply Button
            if (_students.isNotEmpty)
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
                    onPressed: _isSaving ? null : _applyPromotions,
                    icon: _isSaving
                        ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Icon(Icons.arrow_upward),
                    label: Text(
                      _isSaving ? 'پروموشن کا نفاذ ہو رہا ہے...' : 'سالانہ ترقی لاگو کریں',
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
