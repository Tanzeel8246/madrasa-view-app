import 'package:flutter/material.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import '../utils/globals.dart';

class MadrasaSetupWizardScreen extends StatefulWidget {
  const MadrasaSetupWizardScreen({super.key});

  @override
  State<MadrasaSetupWizardScreen> createState() =>
      _MadrasaSetupWizardScreenState();
}

class _MadrasaSetupWizardScreenState extends State<MadrasaSetupWizardScreen> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  int _currentStep = 0;
  bool _isSaving = false;

  // Step 1: معلومات ادارہ
  final _nameController = TextEditingController(text: 'جامعہ اشرف المدارس');
  final _taglineController =
      TextEditingController(text: 'مرکز علوم دینیہ و عصریہ');
  final _phoneController = TextEditingController(text: '03001234567');
  final _addressController = TextEditingController(text: 'مین جی ٹی روڈ');

  // Step 2: تعلیمی سال و کیلنڈر
  final _academicYearController =
      TextEditingController(text: '${DateTime.now().year}-${DateTime.now().year + 1}');
  final _hijriYearController = TextEditingController(text: '1447 ھ');
  String _selectedWeekend = 'جمعہ (Friday)';

  // Step 3: ابتدائی شعبہ جات و کلاسز
  final List<Map<String, dynamic>> _quickClasses = [
    {'name': 'حفظ القرآن الکریم', 'dept': 'شعبہ حفظ', 'selected': true},
    {'name': 'ناظرہ قرآن و تجوید', 'dept': 'شعبہ ناظرہ', 'selected': true},
    {'name': 'نورانی قاعدہ', 'dept': 'شعبہ ابتدائیہ', 'selected': true},
    {'name': 'درس نظامی - سال اول', 'dept': 'شعبہ کتب', 'selected': false},
  ];

  // Step 4: پہلا استاد و طالب علم
  final _teacherNameController = TextEditingController(text: 'قاری عبد الرحمن');
  final _teacherPhoneController = TextEditingController(text: '03120000000');
  final _studentNameController = TextEditingController(text: 'محمد احمد');
  final _studentFatherController = TextEditingController(text: 'محمد علی');

  @override
  void dispose() {
    _nameController.dispose();
    _taglineController.dispose();
    _phoneController.dispose();
    _addressController.dispose();
    _academicYearController.dispose();
    _hijriYearController.dispose();
    _teacherNameController.dispose();
    _teacherPhoneController.dispose();
    _studentNameController.dispose();
    _studentFatherController.dispose();
    super.dispose();
  }

  Future<void> _completeWizard() async {
    setState(() => _isSaving = true);
    final db = DBHelper();
    final database = await db.database;

    try {
      // 1. سیٹنگز محفوظ کریں
      await database.insert(
        'settings',
        {
          'id': 1,
          'madrasa_name': _nameController.text.trim(),
          'tagline': _taglineController.text.trim(),
          'phone': _phoneController.text.trim(),
          'address': _addressController.text.trim(),
          'hijri_year': _hijriYearController.text.trim(),
          'is_synced': 0,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

      // 2. منتخب کلاسز شامل کریں
      for (final item in _quickClasses) {
        if (item['selected'] == true) {
          final existing = await database.query(
            'classes',
            where: 'name = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [item['name']],
          );
          if (existing.isEmpty) {
            await database.insert('classes', {
              'name': item['name'],
              'department': item['dept'],
              'system_type': 'Madrasa',
              'is_synced': 0,
              'created_at': DateTime.now().toIso8601String(),
            });
          }
        }
      }

      // 3. پہلا استاد اگر درج ہو
      if (_teacherNameController.text.trim().isNotEmpty) {
        final existingTeacher = await database.query(
          'teachers',
          where: 'name = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: [_teacherNameController.text.trim()],
        );
        if (existingTeacher.isEmpty) {
          await database.insert('teachers', {
            'name': _teacherNameController.text.trim(),
            'phone': _teacherPhoneController.text.trim(),
            'role': 'استاد',
            'system_type': 'Madrasa',
            'is_synced': 0,
            'joining_date': DateTime.now().toIso8601String().split('T')[0],
          });
        }
      }

      // 4. پہلا طالب علم اگر درج ہو
      if (_studentNameController.text.trim().isNotEmpty) {
        final selectedClass = _quickClasses.firstWhere(
          (c) => c['selected'] == true,
          orElse: () => {'name': 'حفظ القرآن الکریم'},
        )['name'];

        final existingStudent = await database.query(
          'students',
          where: 'name = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: [_studentNameController.text.trim()],
        );
        if (existingStudent.isEmpty) {
          await database.insert('students', {
            'name': _studentNameController.text.trim(),
            'father_name': _studentFatherController.text.trim(),
            'class': selectedClass,
            'admission_date': DateTime.now().toIso8601String().split('T')[0],
            'system_type': 'Madrasa',
            'is_synced': 0,
          });
        }
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('✅ نیا مدرسہ سیٹ اپ کامیابی سے مکمل ہو گیا!'),
          backgroundColor: Colors.green,
        ),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('خرابی: $e'),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          title: Text(
            isUrdu
                ? 'ابتدائی سیٹ اپ وزرڈ (Madrasa Setup)'
                : 'Initial Setup Wizard',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
          ),
          elevation: 2,
        ),
        body: _isSaving
            ? const Center(child: CircularProgressIndicator())
            : Theme(
                data: Theme.of(context).copyWith(
                  colorScheme: ColorScheme.light(primary: primaryGreen),
                ),
                child: Stepper(
                  type: StepperType.horizontal,
                  currentStep: _currentStep,
                  onStepTapped: (step) => setState(() => _currentStep = step),
                  onStepContinue: () {
                    if (_currentStep < 3) {
                      setState(() => _currentStep += 1);
                    } else {
                      _completeWizard();
                    }
                  },
                  onStepCancel: () {
                    if (_currentStep > 0) {
                      setState(() => _currentStep -= 1);
                    } else {
                      Navigator.pop(context);
                    }
                  },
                  controlsBuilder: (context, details) {
                    return Padding(
                      padding: const EdgeInsets.only(top: 20.0),
                      child: Row(
                        children: [
                          Expanded(
                            child: ElevatedButton(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: primaryGreen,
                                foregroundColor: Colors.white,
                                padding:
                                    const EdgeInsets.symmetric(vertical: 12),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(8),
                                ),
                              ),
                              onPressed: details.onStepContinue,
                              child: Text(
                                _currentStep == 3
                                    ? (isUrdu ? 'سیٹ اپ مکمل کریں' : 'Finish Setup')
                                    : (isUrdu ? 'اگلا مرحلہ' : 'Next Step'),
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold, fontSize: 15),
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          OutlinedButton(
                            style: OutlinedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(
                                  vertical: 12, horizontal: 16),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(8),
                              ),
                            ),
                            onPressed: details.onStepCancel,
                            child: Text(
                              _currentStep == 0
                                  ? (isUrdu ? 'منسوخ' : 'Cancel')
                                  : (isUrdu ? 'پچھلا' : 'Back'),
                              style: TextStyle(color: primaryGreen),
                            ),
                          ),
                        ],
                      ),
                    );
                  },
                  steps: [
                    // Step 1
                    Step(
                      title: Text(isUrdu ? 'ادارہ' : 'Jamia'),
                      isActive: _currentStep >= 0,
                      state: _currentStep > 0
                          ? StepState.complete
                          : StepState.indexed,
                      content: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isUrdu
                                ? 'مرحلہ 1: ادارہ / مدرسہ کی بنیادی معلومات درج کریں'
                                : 'Step 1: Enter basic details of your Madrasa',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _nameController,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'مدرسہ کا مکمل نام'
                                  : 'Madrasa Full Name',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _taglineController,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'سلوگن / ذیلی عنوان'
                                  : 'Tagline / Subtitle',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _phoneController,
                            keyboardType: TextInputType.phone,
                            decoration: InputDecoration(
                              labelText:
                                  isUrdu ? 'مرکزی رابطہ نمبر' : 'Contact Phone',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _addressController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'پتہ / شہر' : 'Address / City',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Step 2
                    Step(
                      title: Text(isUrdu ? 'سال' : 'Year'),
                      isActive: _currentStep >= 1,
                      state: _currentStep > 1
                          ? StepState.complete
                          : StepState.indexed,
                      content: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isUrdu
                                ? 'مرحلہ 2: سالانہ تعلیمی سال اور ہجری سال سیٹ کریں'
                                : 'Step 2: Set Academic & Hijri Calendar',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _academicYearController,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'تعلیمی سال (عیسوی)'
                                  : 'Academic Year',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _hijriYearController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'ہجری سال' : 'Hijri Year',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          DropdownButtonFormField<String>(
                            value: _selectedWeekend,
                            items: [
                              'جمعہ (Friday)',
                              'اتوار (Sunday)',
                              'جمعرات و جمعہ (Thursday & Friday)'
                            ]
                                .map((w) => DropdownMenuItem(
                                      value: w,
                                      child: Text(w),
                                    ))
                                .toList(),
                            onChanged: (v) {
                              if (v != null) {
                                setState(() => _selectedWeekend = v);
                              }
                            },
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'ہفتہ وار تعطیل'
                                  : 'Weekly Holiday',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Step 3
                    Step(
                      title: Text(isUrdu ? 'کلاسز' : 'Classes'),
                      isActive: _currentStep >= 2,
                      state: _currentStep > 2
                          ? StepState.complete
                          : StepState.indexed,
                      content: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isUrdu
                                ? 'مرحلہ 3: ابتدائی کلاسز و شعبہ جات کا انتخاب کریں'
                                : 'Step 3: Select initial classes & departments',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                          const SizedBox(height: 8),
                          ...List.generate(_quickClasses.length, (idx) {
                            final item = _quickClasses[idx];
                            return CheckboxListTile(
                              value: item['selected'] as bool,
                              title: Text(item['name'] as String,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold)),
                              subtitle: Text(item['dept'] as String),
                              activeColor: primaryGreen,
                              onChanged: (val) {
                                setState(() {
                                  item['selected'] = val ?? false;
                                });
                              },
                            );
                          }),
                        ],
                      ),
                    ),

                    // Step 4
                    Step(
                      title: Text(isUrdu ? 'اندراج' : 'Entry'),
                      isActive: _currentStep >= 3,
                      state: StepState.indexed,
                      content: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isUrdu
                                ? 'مرحلہ 4: پہلے استاد اور طالب علم کا اندراج'
                                : 'Step 4: Add first teacher and student',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            isUrdu ? 'استاد کی تفصیلات:' : 'Teacher Details:',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          const SizedBox(height: 6),
                          TextField(
                            controller: _teacherNameController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'استاد کا نام' : 'Teacher Name',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 8),
                          TextField(
                            controller: _teacherPhoneController,
                            keyboardType: TextInputType.phone,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'استاد کا فون' : 'Teacher Phone',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 16),
                          Text(
                            isUrdu ? 'پہلے طالب علم کی تفصیلات:' : 'First Student Details:',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          const SizedBox(height: 6),
                          TextField(
                            controller: _studentNameController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'طالب علم کا نام' : 'Student Name',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 8),
                          TextField(
                            controller: _studentFatherController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'والد کا نام' : 'Father Name',
                              border: const OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
