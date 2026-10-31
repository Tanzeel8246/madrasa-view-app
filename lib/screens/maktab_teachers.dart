// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'dart:convert';
import 'dart:io';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../utils/sync_ui_helper.dart';

class MaktabTeachersPage extends StatefulWidget {
  const MaktabTeachersPage({super.key});

  @override
  _MaktabTeachersPageState createState() => _MaktabTeachersPageState();
}

class _MaktabTeachersPageState extends State<MaktabTeachersPage>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  List<Map<String, dynamic>> _allTeachers = [];
  List<Map<String, dynamic>> _foundTeachers = [];
  List<Map<String, dynamic>> _allStaff = [];
  List<Map<String, dynamic>> _foundStaff = [];

  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  // ---- عملہ کی اقسام ----
  static const List<Map<String, String>> staffTypes = [
    {'value': 'باورچی', 'icon': '🍳'},
    {'value': 'خادم', 'icon': '🧹'},
    {'value': 'کیشیئر', 'icon': '💰'},
    {'value': 'ناظم', 'icon': '📋'},
    {'value': 'چوکیدار', 'icon': '🔒'},
    {'value': 'ڈرائیور', 'icon': '🚗'},
    {'value': 'دفتری', 'icon': '🗂️'},
    {'value': 'صفائی کارکن', 'icon': '🧽'},
    {'value': 'دیگر', 'icon': '👤'},
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _refreshAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _refreshAll() async {
    final teachers = await DBHelper().getMaktabTeachers();
    final staff = await DBHelper().getMaktabStaff();
    if (!mounted) return;
    setState(() {
      _allTeachers = teachers;
      _foundTeachers = teachers;
      _allStaff = staff;
      _foundStaff = staff;
    });
  }

  void _runFilterTeachers(String keyword) {
    setState(() {
      _foundTeachers = keyword.isEmpty
          ? _allTeachers
          : _allTeachers
              .where((t) =>
                  t['name'].toString().toLowerCase().contains(keyword.toLowerCase()) ||
                  (t['phone'] ?? '').toString().contains(keyword))
              .toList();
    });
  }

  void _runFilterStaff(String keyword) {
    setState(() {
      _foundStaff = keyword.isEmpty
          ? _allStaff
          : _allStaff
              .where((s) =>
                  s['name'].toString().toLowerCase().contains(keyword.toLowerCase()) ||
                  (s['staff_type'] ?? '').toString().contains(keyword))
              .toList();
    });
  }

  Future<void> _deleteRecord(int id) async {
    await DBHelper().updateMaktabTeacher(id, {'is_deleted': 1, 'is_synced': 0});
    _refreshAll();
  }

  Set<String> _splitTeacherValues(dynamic value) {
    return (value?.toString() ?? '')
        .split(',')
        .map((item) => item.trim())
        .where((item) => item.isNotEmpty)
        .toSet();
  }


  String _normalizePeriodKey(String key) {
    const legacyHifz = {
      'hifz_sabaq': 'hifz_morning',
      'hifz_sabqi': 'hifz_after_breakfast',
      'hifz_manzil': 'hifz_noon',
      'hifz_review': 'hifz_evening',
    };
    return legacyHifz[key] ?? key;
  }

  Map<String, Set<String>> _decodeAssignedPeriods(
      dynamic value, Set<String> selectedClasses) {
    final raw = value?.toString().trim() ?? '';
    final result = <String, Set<String>>{};
    if (raw.isEmpty) return result;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        decoded.forEach((key, val) {
          if (val is List) {
            result[key.toString()] =
                val.map((e) => _normalizePeriodKey(e.toString())).toSet();
          } else if (val != null) {
            result[key.toString()] = val
                .toString()
                .split(',')
                .map((e) => _normalizePeriodKey(e.trim()))
                .where((e) => e.isNotEmpty)
                .toSet();
          }
        });
        return result;
      }
    } catch (_) {}
    final legacy = _splitTeacherValues(raw);
    for (final className in selectedClasses) {
      result[className] = {...legacy};
    }
    return result;
  }

  String _encodeAssignedPeriods(Map<String, Set<String>> map) {
    final clean = <String, List<String>>{};
    map.forEach((className, periods) {
      if (periods.isNotEmpty) {
        final sorted = periods.toList()..sort();
        clean[className] = sorted;
      }
    });
    return jsonEncode(clean);
  }

  bool _classTextContains(Map<String, dynamic> cls, List<String> needles) {
    final text = [cls['name'], cls['class_type'], cls['section']]
        .where((v) => v != null)
        .join(' ')
        .toLowerCase();
    return needles.any((needle) => text.contains(needle.toLowerCase()));
  }

  bool _isHifzClass(Map<String, dynamic> cls) =>
      _classTextContains(cls, ['\u062d\u0641\u0638', 'hifz']);

  bool _isNaziraClass(Map<String, dynamic> cls) =>
      _classTextContains(cls, ['\u0646\u0627\u0638\u0631\u06c1', 'nazira']);

  List<Map<String, String>> _periodOptionsForClass(Map<String, dynamic> cls) {
    if (_isNaziraClass(cls)) {
      return const [
        {'value': '1', 'labelUr': 'صبح کا سیشن (Morning Shift)', 'labelEn': 'Morning Shift'},
        {'value': '2', 'labelUr': 'شام کا سیشن (Evening Shift)', 'labelEn': 'Evening Shift'},
      ];
    }
    if (_isHifzClass(cls)) {
      return const [
        {'value': '1', 'labelUr': 'صبح کا سیشن (Morning Shift)', 'labelEn': 'Morning Shift'},
        {'value': '2', 'labelUr': 'دوپہر کا سیشن (Noon Shift)', 'labelEn': 'Noon Shift'},
        {'value': '3', 'labelUr': 'عصر / شام کا سیشن (Evening Shift)', 'labelEn': 'Evening Shift'},
        {'value': '4', 'labelUr': 'مغرب / عشاء کا سیشن (Night Shift)', 'labelEn': 'Night Shift'},
      ];
    }
    return const [
      {'value': '1', 'labelUr': 'صبح کا سیشن (Morning Shift)', 'labelEn': 'Morning Shift'},
      {'value': '2', 'labelUr': 'دوپہر کا سیشن (Noon Shift)', 'labelEn': 'Noon Shift'},
      {'value': '3', 'labelUr': 'عصر / شام کا سیشن (Evening Shift)', 'labelEn': 'Evening Shift'},
      {'value': '4', 'labelUr': 'مغرب / عشاء کا سیشن (Night Shift)', 'labelEn': 'Night Shift'},
    ];
  }

  String _periodLabel(String value, bool isUrdu) {
    final all = <Map<String, String>>[
      {'value': '1', 'labelUr': 'صبح کا سیشن', 'labelEn': 'Morning Shift'},
      {'value': '2', 'labelUr': 'دوپہر کا سیشن', 'labelEn': 'Noon Shift'},
      {'value': '3', 'labelUr': 'عصر / شام کا سیشن', 'labelEn': 'Evening Shift'},
      {'value': '4', 'labelUr': 'مغرب / عشاء کا سیشن', 'labelEn': 'Night Shift'},
      {'value': 'hifz_morning', 'labelUr': 'صبح کا سیشن', 'labelEn': 'Morning'},
      {'value': 'hifz_after_breakfast', 'labelUr': 'دوپہر کا سیشن', 'labelEn': 'After Breakfast'},
      {'value': 'hifz_noon', 'labelUr': 'عصر / شام کا سیشن', 'labelEn': 'Noon'},
      {'value': 'hifz_evening', 'labelUr': 'مغرب / عشاء کا سیشن', 'labelEn': 'Evening'},
      {'value': 'nazira_morning', 'labelUr': 'صبح کا سیشن', 'labelEn': 'Morning'},
      {'value': 'nazira_evening', 'labelUr': 'شام کا سیشن', 'labelEn': 'Evening'},
    ];
    final key = _normalizePeriodKey(value);
    final found = all.where((p) => p['value'] == key);
    if (found.isEmpty) return value;
    return found.first[isUrdu ? 'labelUr' : 'labelEn'] ?? value;
  }

  String _formatAssignedPeriods(dynamic value, {bool? isUrdu}) {
    final urdu = isUrdu ?? isUrduGlobal.value;
    final raw = value?.toString().trim() ?? '';
    if (raw.isEmpty) return '';
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        final parts = <String>[];
        decoded.forEach((className, vals) {
          final labels = vals is List
              ? vals.map((v) => _periodLabel(v.toString(), urdu)).join(', ')
              : vals.toString();
          if (labels.trim().isNotEmpty) parts.add('$className: $labels');
        });
        return parts.join(' | ');
      }
    } catch (_) {}
    return raw
        .split(',')
        .map((v) => _periodLabel(v.trim(), urdu))
        .where((v) => v.isNotEmpty)
        .join(', ');
  }

  // ══════════════════════════════════════════════
  // استاد شامل / ایڈٹ کرنے کا ڈائلاگ
  // ══════════════════════════════════════════════
  Future<void> _showAddTeacherDialog({Map<String, dynamic>? teacher}) async {
    final classes = await DBHelper().getMaktabClassesForUser();
    final selectedClasses = _splitTeacherValues(teacher?['assigned_classes']);
    final selectedPeriodsByClass =
        _decodeAssignedPeriods(teacher?['assigned_periods'], selectedClasses);
    final nameCtrl = TextEditingController(text: teacher?['name']?.toString() ?? '');
    final fatherCtrl = TextEditingController(text: teacher?['father_name']?.toString() ?? '');
    final phoneCtrl = TextEditingController(text: teacher?['phone']?.toString() ?? '');
    final cnicCtrl = TextEditingController(text: teacher?['cnic']?.toString() ?? '');
    final qualCtrl = TextEditingController(text: teacher?['qualification']?.toString() ?? '');
    final salaryCtrl = TextEditingController(text: teacher?['salary']?.toString() ?? '');
    final houseRentCtrl = TextEditingController(text: teacher?['house_rent']?.toString() ?? '');
    final medicalCtrl = TextEditingController(text: teacher?['medical_allowance']?.toString() ?? '');
    final travelCtrl = TextEditingController(text: teacher?['travel_allowance']?.toString() ?? '');
    final deductionCtrl = TextEditingController(text: teacher?['daily_deduction_rate']?.toString() ?? '');
    final dateCtrl = TextEditingController(text: teacher?['joining_date']?.toString() ?? '');
    final addressCtrl = TextEditingController(text: teacher?['address']?.toString() ?? '');
    final respCtrl = TextEditingController(text: teacher?['responsibilities']?.toString() ?? '');
    String selectedStatus = teacher?['status']?.toString() ?? 'Active';
    String? localPhotoPath = teacher?['photo']?.toString();
    if (localPhotoPath != null && localPhotoPath.isEmpty) localPhotoPath = null;

    showDialog(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setStateDialog) {
          Future<void> pickImage() async {
            final picker = ImagePicker();
            final pickedFile = await picker.pickImage(source: ImageSource.gallery, imageQuality: 50);
            if (pickedFile != null) {
              setStateDialog(() => localPhotoPath = pickedFile.path);
            }
          }

          ImageProvider? avatarImage;
          if (localPhotoPath != null && localPhotoPath!.isNotEmpty) {
            if (localPhotoPath!.startsWith('http') || localPhotoPath!.startsWith('uploads/')) {
              final url = localPhotoPath!.startsWith('http')
                  ? localPhotoPath!
                  : '${apiUrl.endsWith('/') ? apiUrl : '$apiUrl/'}$localPhotoPath';
              avatarImage = NetworkImage(url);
            } else if (File(localPhotoPath!).existsSync()) {
              avatarImage = FileImage(File(localPhotoPath!));
            }
          }

          return AlertDialog(
            title: Text(teacher == null
                ? (isUrduGlobal.value ? "نیا استاد شامل کریں" : "Add New Teacher")
                : (isUrduGlobal.value ? "استاد ایڈٹ کریں" : "Edit Teacher")),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // تصویر
                  Center(
                    child: GestureDetector(
                      onTap: pickImage,
                      child: CircleAvatar(
                        radius: 40,
                        backgroundColor: Colors.grey.shade300,
                        backgroundImage: avatarImage,
                        child: avatarImage == null
                            ? const Icon(Icons.camera_alt, size: 40, color: Colors.white)
                            : null,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  _field(nameCtrl, isUrduGlobal.value ? "نام استاد *" : "Teacher Name *"),
                  _field(fatherCtrl, isUrduGlobal.value ? "ولدیت" : "Father's Name"),
                  Row(children: [
                    Expanded(child: _field(phoneCtrl, isUrduGlobal.value ? "موبائل" : "Mobile", keyboard: TextInputType.phone)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(cnicCtrl, "CNIC", keyboard: TextInputType.number)),
                  ]),
                  Row(children: [
                    Expanded(child: _field(qualCtrl, isUrduGlobal.value ? "تعلیم" : "Qualification")),
                    const SizedBox(width: 8),
                    Expanded(child: _field(salaryCtrl, isUrduGlobal.value ? "بنیادی تنخواہ" : "Basic Salary", keyboard: TextInputType.number)),
                  ]),
                  Row(children: [
                    Expanded(child: _field(houseRentCtrl, isUrduGlobal.value ? "ہاؤس رینٹ" : "House Rent", keyboard: TextInputType.number)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(medicalCtrl, isUrduGlobal.value ? "میڈیکل" : "Medical Allow.", keyboard: TextInputType.number)),
                  ]),
                  Row(children: [
                    Expanded(child: _field(travelCtrl, isUrduGlobal.value ? "سفری الاؤنس" : "Travel Allow.", keyboard: TextInputType.number)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(deductionCtrl, isUrduGlobal.value ? "یومیہ کٹوتی" : "Daily Deduct.", keyboard: TextInputType.number)),
                  ]),
                  // تقرری کی تاریخ
                  TextField(
                    controller: dateCtrl,
                    readOnly: true,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تقرری کی تاریخ" : "Joining Date",
                      suffixIcon: const Icon(Icons.calendar_today),
                    ),
                    onTap: () async {
                      DateTime? picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now(),
                        firstDate: DateTime(2000),
                        lastDate: DateTime(2100),
                      );
                      if (picked != null) {
                        setStateDialog(() {
                          dateCtrl.text = "${picked.year}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}";
                        });
                      }
                    },
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: selectedStatus,
                    decoration: InputDecoration(labelText: isUrduGlobal.value ? "سٹیٹس" : "Status"),
                    items: [
                      DropdownMenuItem(value: "Active", child: Text(isUrduGlobal.value ? "Active (موجود)" : "Active")),
                      DropdownMenuItem(value: "Inactive", child: Text(isUrduGlobal.value ? "Inactive (فارغ)" : "Inactive")),
                    ],
                    onChanged: (v) => setStateDialog(() => selectedStatus = v!),
                  ),
                  const SizedBox(height: 8),
                  _field(addressCtrl, isUrduGlobal.value ? "مکمل پتہ" : "Full Address", maxLines: 2),
                  const SizedBox(height: 8),
                  // ذمہ داریاں
                  TextField(
                    controller: respCtrl,
                    maxLines: 3,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "ذمہ داریاں / فرائض" : "Responsibilities / Duties",
                      hintText: isUrduGlobal.value
                          ? "مثلاً: درس نظامی کلاس، صبح کی شفٹ نگران، جمعہ بیان..."
                          : "e.g. Morning class supervisor, Friday lecture...",
                      border: const OutlineInputBorder(),
                      alignLabelWithHint: true,
                    ),
                  ),
                  const SizedBox(height: 16),
                  // کلاس الاٹمنٹ
                  Align(
                    alignment: Alignment.centerRight,
                    child: Text(
                      isUrduGlobal.value ? "کلاسز الاٹ کریں" : "Assign Classes",
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                  const SizedBox(height: 8),
                  if (classes.isEmpty)
                    Text(isUrduGlobal.value ? "ابھی کوئی کلاس موجود نہیں" : "No classes available",
                        style: TextStyle(color: Colors.grey.shade600))
                  else
                    Align(
                      alignment: Alignment.centerRight,
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 6,
                        children: classes.map((cls) {
                          final className = cls['name']?.toString() ?? '';
                          final selected = selectedClasses.contains(className);
                          return FilterChip(
                            label: Text(className),
                            selected: selected,
                            onSelected: (v) {
                               setStateDialog(() {
                                 if (v) { selectedClasses.add(className); }
                                 else { selectedClasses.remove(className); }
                               });
                             },
                          );
                        }).toList(),
                      ),
                    ),
                  const SizedBox(height: 16),
                  if (selectedClasses.isNotEmpty) ...[
                    Align(
                      alignment: Alignment.centerRight,
                      child: Text(
                        isUrduGlobal.value ? "پیریڈز الاٹ کریں" : "Assign Periods",
                        style: const TextStyle(fontWeight: FontWeight.bold),
                      ),
                    ),
                    const SizedBox(height: 8),
                    ...classes.where((cls) => selectedClasses.contains(cls['name']?.toString() ?? '')).map((cls) {
                      final className = cls['name']?.toString() ?? '';
                      final options = _periodOptionsForClass(cls);
                      final selectedForClass = selectedPeriodsByClass[className] ?? <String>{};
                      selectedPeriodsByClass[className] = selectedForClass;
                      return Container(
                        width: double.infinity,
                        margin: const EdgeInsets.only(bottom: 10),
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.grey.shade300),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(className, style: const TextStyle(fontWeight: FontWeight.bold)),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 8,
                              runSpacing: 6,
                              children: options.map((period) {
                                final value = period['value']!;
                                final selected = selectedForClass.contains(value);
                                return FilterChip(
                                  label: Text(isUrduGlobal.value ? period['labelUr']! : period['labelEn']!),
                                  selected: selected,
                                  onSelected: (isSelected) {
                                     setStateDialog(() {
                                       if (isSelected) { selectedForClass.add(value); }
                                       else { selectedForClass.remove(value); }
                                     });
                                   },
                                );
                              }).toList(),
                            ),
                          ],
                        ),
                      );
                    }),
                  ],
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrduGlobal.value ? "کینسل" : "Cancel"),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF284D3D)),
                onPressed: () async {
                  if (nameCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(isUrduGlobal.value ? "نام لکھنا ضروری ہے!" : "Name is required!")),
                    );
                    return;
                  }
                  final data = {
                    'name': nameCtrl.text.trim(),
                    'father_name': fatherCtrl.text.trim(),
                    'phone': phoneCtrl.text.trim(),
                    'cnic': cnicCtrl.text.trim(),
                    'qualification': qualCtrl.text.trim(),
                    'salary': salaryCtrl.text.trim(),
                    'house_rent': houseRentCtrl.text.trim(),
                    'medical_allowance': medicalCtrl.text.trim(),
                    'travel_allowance': travelCtrl.text.trim(),
                    'daily_deduction_rate': deductionCtrl.text.trim(),
                    'joining_date': dateCtrl.text.trim(),
                    'status': selectedStatus,
                    'address': addressCtrl.text.trim(),
                    'photo': localPhotoPath ?? '',
                    'assigned_classes': selectedClasses.join(','),
                    'assigned_periods': _encodeAssignedPeriods(selectedPeriodsByClass),
                    'staff_type': 'Teacher',
                    'responsibilities': respCtrl.text.trim(),
                    'is_synced': 0,
                  };
                  final nav = Navigator.of(context);
                  final messenger = ScaffoldMessenger.of(context);
                  if (teacher == null) {
                    final isSuper = currentUserRole.value == 'superadmin' || 
                        (currentUserRole.value == 'admin' && currentUserId.value == 1);
                    final maxTch = currentTenantMaxTeachers.value;
                    final activeCount = _allTeachers.where((t) => (t['is_deleted'] == 0 || t['is_deleted'] == null) && (t['status'] == 'Active' || t['status'] == null || t['status'] == '')).length;
                    if (!isSuper && activeCount >= maxTch) {
                      showDialog(
                        context: context,
                        builder: (ctx) => AlertDialog(
                          title: Row(
                            children: [
                              const Icon(Icons.lock, color: Colors.red),
                              const SizedBox(width: 8),
                              Text(isUrduGlobal.value ? 'اساتذہ کی حد مکمل' : 'Teacher Limit Reached'),
                            ],
                          ),
                          content: Text(
                            isUrduGlobal.value
                                ? 'آپ کے موجودہ پلان (${currentTenantPlanName.value}) میں اساتذہ کی زیادہ سے زیادہ حد ($maxTch) مکمل ہو چکی ہے۔\nمزید اساتذہ کے اندراج کے لیے براہ کرم اپنا سبسکرپشن پلان اپ گریڈ کریں۔'
                                : 'You have reached the maximum teacher limit ($maxTch) for your plan (${currentTenantPlanName.value}). Please upgrade your plan to add more teachers.',
                          ),
                          actions: [
                            ElevatedButton(
                              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF284D3D)),
                              onPressed: () => Navigator.pop(ctx),
                              child: Text(isUrduGlobal.value ? 'سمجھ گیا' : 'OK', style: const TextStyle(color: Colors.white)),
                            ),
                          ],
                        ),
                      );
                      return;
                    }
                    await DBHelper().insertMaktabTeacher(data);
                  } else {
                    await DBHelper().updateMaktabTeacher(teacher['id'], data);
                  }
                  nav.pop();
                  _refreshAll();
                  SyncService.runTwoWaySync().catchError((_) {});
                  messenger.showSnackBar(SnackBar(
                    content: Text(isUrduGlobal.value ? "استاد کا ریکارڈ محفوظ ہو گیا!" : "Teacher saved!"),
                    backgroundColor: const Color(0xFF284D3D),
                  ));
                },
                child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save",
                    style: const TextStyle(color: Colors.white)),
              ),
            ],
          );
        },
      ),
    );
  }

  // ══════════════════════════════════════════════
  // دیگر عملہ شامل / ایڈٹ کرنے کا ڈائلاگ
  // ══════════════════════════════════════════════
  Future<void> _showAddStaffDialog({Map<String, dynamic>? staff}) async {
    final nameCtrl = TextEditingController(text: staff?['name']?.toString() ?? '');
    final fatherCtrl = TextEditingController(text: staff?['father_name']?.toString() ?? '');
    final phoneCtrl = TextEditingController(text: staff?['phone']?.toString() ?? '');
    final cnicCtrl = TextEditingController(text: staff?['cnic']?.toString() ?? '');
    final salaryCtrl = TextEditingController(text: staff?['salary']?.toString() ?? '');
    final dateCtrl = TextEditingController(text: staff?['joining_date']?.toString() ?? '');
    final addressCtrl = TextEditingController(text: staff?['address']?.toString() ?? '');
    final respCtrl = TextEditingController(text: staff?['responsibilities']?.toString() ?? '');
    String selectedType = staff?['staff_type']?.toString() ?? staffTypes.first['value']!;
    String selectedStatus = staff?['status']?.toString() ?? 'Active';
    String? localPhotoPath = staff?['photo']?.toString();
    if (localPhotoPath != null && localPhotoPath.isEmpty) localPhotoPath = null;

    showDialog(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setStateDialog) {
          Future<void> pickImage() async {
            final picker = ImagePicker();
            final pickedFile = await picker.pickImage(source: ImageSource.gallery, imageQuality: 50);
            if (pickedFile != null) setStateDialog(() => localPhotoPath = pickedFile.path);
          }

          ImageProvider? avatarImage;
          if (localPhotoPath != null && localPhotoPath!.isNotEmpty) {
            if (localPhotoPath!.startsWith('http') || localPhotoPath!.startsWith('uploads/')) {
              final url = localPhotoPath!.startsWith('http')
                  ? localPhotoPath!
                  : '${apiUrl.endsWith('/') ? apiUrl : '$apiUrl/'}$localPhotoPath';
              avatarImage = NetworkImage(url);
            } else if (File(localPhotoPath!).existsSync()) {
              avatarImage = FileImage(File(localPhotoPath!));
            }
          }

          return AlertDialog(
            title: Text(staff == null
                ? (isUrduGlobal.value ? "نیا عملہ شامل کریں" : "Add Staff Member")
                : (isUrduGlobal.value ? "عملہ ایڈٹ کریں" : "Edit Staff Member")),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // تصویر
                  Center(
                    child: GestureDetector(
                      onTap: pickImage,
                      child: CircleAvatar(
                        radius: 40,
                        backgroundColor: Colors.orange.shade200,
                        backgroundImage: avatarImage,
                        child: avatarImage == null
                            ? const Icon(Icons.camera_alt, size: 40, color: Colors.white)
                            : null,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  // عہدہ (ڈراپ ڈاؤن)
                  DropdownButtonFormField<String>(
                    value: staffTypes.any((s) => s['value'] == selectedType) ? selectedType : staffTypes.first['value'],
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "عہدہ / قسم" : "Designation",
                      prefixIcon: const Icon(Icons.work_outline),
                    ),
                    items: staffTypes.map((s) => DropdownMenuItem(
                      value: s['value'],
                      child: Text("${s['icon']}  ${s['value']}"),
                    )).toList(),
                    onChanged: (v) => setStateDialog(() => selectedType = v!),
                  ),
                  const SizedBox(height: 8),
                  _field(nameCtrl, isUrduGlobal.value ? "نام *" : "Name *"),
                  _field(fatherCtrl, isUrduGlobal.value ? "ولدیت" : "Father's Name"),
                  Row(children: [
                    Expanded(child: _field(phoneCtrl, isUrduGlobal.value ? "موبائل" : "Mobile", keyboard: TextInputType.phone)),
                    const SizedBox(width: 8),
                    Expanded(child: _field(cnicCtrl, "CNIC", keyboard: TextInputType.number)),
                  ]),
                  _field(salaryCtrl, isUrduGlobal.value ? "تنخواہ (ماہانہ)" : "Monthly Salary", keyboard: TextInputType.number),
                  // تقرری کی تاریخ
                  TextField(
                    controller: dateCtrl,
                    readOnly: true,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تقرری کی تاریخ" : "Joining Date",
                      suffixIcon: const Icon(Icons.calendar_today),
                    ),
                    onTap: () async {
                      DateTime? picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now(),
                        firstDate: DateTime(2000),
                        lastDate: DateTime(2100),
                      );
                      if (picked != null) {
                        setStateDialog(() {
                          dateCtrl.text = "${picked.year}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}";
                        });
                      }
                    },
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: selectedStatus,
                    decoration: InputDecoration(labelText: isUrduGlobal.value ? "سٹیٹس" : "Status"),
                    items: [
                      DropdownMenuItem(value: "Active", child: Text(isUrduGlobal.value ? "Active (موجود)" : "Active")),
                      DropdownMenuItem(value: "Inactive", child: Text(isUrduGlobal.value ? "Inactive (فارغ)" : "Inactive")),
                    ],
                    onChanged: (v) => setStateDialog(() => selectedStatus = v!),
                  ),
                  const SizedBox(height: 8),
                  _field(addressCtrl, isUrduGlobal.value ? "مکمل پتہ" : "Full Address", maxLines: 2),
                  const SizedBox(height: 8),
                  // ذمہ داریاں — یہ عملے کے لیے سب سے اہم فیلڈ ہے
                  Container(
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.orange.shade300, width: 1.5),
                      borderRadius: BorderRadius.circular(8),
                      color: Colors.orange.shade50,
                    ),
                    padding: const EdgeInsets.all(4),
                    child: TextField(
                      controller: respCtrl,
                      maxLines: 4,
                      decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "ذمہ داریاں / فرائض منصبی ⭐" : "Responsibilities / Duties ⭐",
                        hintText: isUrduGlobal.value
                            ? "مثلاً:\n• صبح و شام کا کھانا پکانا\n• برتن صاف کرنا\n• طلباء کے لیے ناشتہ"
                            : "e.g.:\n• Morning and evening cooking\n• Cleaning utensils\n• Preparing student breakfast",
                        border: InputBorder.none,
                        alignLabelWithHint: true,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrduGlobal.value ? "کینسل" : "Cancel"),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: Colors.orange.shade700),
                onPressed: () async {
                  if (nameCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(isUrduGlobal.value ? "نام لکھنا ضروری ہے!" : "Name is required!")),
                    );
                    return;
                  }
                  final data = {
                    'name': nameCtrl.text.trim(),
                    'father_name': fatherCtrl.text.trim(),
                    'phone': phoneCtrl.text.trim(),
                    'cnic': cnicCtrl.text.trim(),
                    'salary': salaryCtrl.text.trim(),
                    'joining_date': dateCtrl.text.trim(),
                    'status': selectedStatus,
                    'address': addressCtrl.text.trim(),
                    'photo': localPhotoPath ?? '',
                    'staff_type': selectedType,
                    'responsibilities': respCtrl.text.trim(),
                    'is_synced': 0,
                  };
                  final nav = Navigator.of(context);
                  final messenger = ScaffoldMessenger.of(context);
                  if (staff == null) {
                    await DBHelper().insertMaktabTeacher(data);
                  } else {
                    await DBHelper().updateMaktabTeacher(staff['id'], data);
                  }
                  nav.pop();
                  _refreshAll();
                  SyncService.runTwoWaySync().catchError((_) {});
                  messenger.showSnackBar(SnackBar(
                    content: Text(isUrduGlobal.value ? "عملہ کا ریکارڈ محفوظ ہو گیا!" : "Staff record saved!"),
                    backgroundColor: Colors.orange.shade700,
                  ));
                },
                child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save",
                    style: const TextStyle(color: Colors.white)),
              ),
            ],
          );
        },
      ),
    );
  }

  /// FAB دبانے پر انتخاب dialog
  void _showAddChoiceSheet() {
    final isUrdu = isUrduGlobal.value;
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 40, height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              isUrdu ? "کیا شامل کرنا ہے؟" : "What would you like to add?",
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 20),
            Row(
              children: [
                // استاد
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      Navigator.pop(context);
                      _showAddTeacherDialog();
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 20),
                      decoration: BoxDecoration(
                        color: const Color(0xFF284D3D).withOpacity(0.08),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: const Color(0xFF284D3D).withOpacity(0.3)),
                      ),
                      child: Column(children: [
                        const Text("📖", style: TextStyle(fontSize: 36)),
                        const SizedBox(height: 8),
                        Text(
                          isUrdu ? "استاد" : "Teacher",
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Color(0xFF284D3D)),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          isUrdu ? "کلاس و پیریڈ الاٹمنٹ سمیت" : "With class & period assignment",
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                          textAlign: TextAlign.center,
                        ),
                      ]),
                    ),
                  ),
                ),
                const SizedBox(width: 16),
                // دیگر عملہ
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      Navigator.pop(context);
                      _showAddStaffDialog();
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 20),
                      decoration: BoxDecoration(
                        color: Colors.orange.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Colors.orange.withOpacity(0.4)),
                      ),
                      child: Column(children: [
                        const Text("👷", style: TextStyle(fontSize: 36)),
                        const SizedBox(height: 8),
                        Text(
                          isUrdu ? "دیگر عملہ" : "Other Staff",
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.orange.shade800),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          isUrdu ? "باورچی، خادم، کیشیئر وغیرہ" : "Cook, Servant, Cashier etc.",
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                          textAlign: TextAlign.center,
                        ),
                      ]),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
          ],
        ),
      ),
    );
  }

  // ──────────────────────────────────
  Widget _field(TextEditingController ctrl, String label, {TextInputType? keyboard, int maxLines = 1}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextField(
        controller: ctrl,
        keyboardType: keyboard,
        maxLines: maxLines,
        decoration: InputDecoration(labelText: label),
      ),
    );
  }

  // ──────────────────────────────────
  Widget _buildTeacherCard(Map<String, dynamic> t, bool isUrdu) {
    final teacherName = t['name']?.toString() ?? '?';
    final firstLetter = teacherName.isNotEmpty ? teacherName[0] : '?';
    final phone = t['phone']?.toString() ?? '-';
    final assignedClasses = t['assigned_classes']?.toString() ?? '';
    final assignedPeriods = _formatAssignedPeriods(t['assigned_periods'], isUrdu: isUrdu);
    final responsibilities = t['responsibilities']?.toString() ?? '';
    final status = t['status']?.toString() ?? 'Active';
    final Color statusColor = status == 'Active' ? const Color(0xFF284D3D) : Colors.red;

    Widget leadingWidget = _buildAvatar(t, firstLetter, Colors.green.shade100, Colors.green.shade800);

    return Card(
      key: ValueKey(t["id"]),
      color: Colors.white,
      elevation: 2,
      margin: const EdgeInsets.symmetric(vertical: 5),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: ExpansionTile(
        leading: leadingWidget,
        title: Text(teacherName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text("${isUrdu ? 'موبائل' : 'Mobile'}: $phone", style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            if (assignedClasses.isNotEmpty)
              Text("${isUrdu ? 'کلاسز' : 'Classes'}: $assignedClasses", style: TextStyle(color: Colors.green.shade700, fontSize: 11)),
          ],
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: statusColor.withOpacity(0.1),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                status == 'Active' ? (isUrdu ? 'موجود' : 'Active') : (isUrdu ? 'فارغ' : 'Inactive'),
                style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 11),
              ),
            ),
            IconButton(icon: const Icon(Icons.edit, color: Colors.blue, size: 18), onPressed: () => _showAddTeacherDialog(teacher: t)),
            IconButton(icon: const Icon(Icons.delete, color: Colors.red, size: 18), onPressed: () => _deleteRecord(t['id'])),
          ],
        ),
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (assignedPeriods.isNotEmpty)
                  _infoRow(Icons.schedule, isUrdu ? 'پیریڈز' : 'Periods', assignedPeriods, Colors.indigo),
                if (responsibilities.isNotEmpty)
                  _infoRow(Icons.task_alt, isUrdu ? 'ذمہ داریاں' : 'Responsibilities', responsibilities, Colors.teal),
                _infoRow(Icons.calendar_today, isUrdu ? 'تقرری' : 'Joined', t['joining_date']?.toString() ?? '-', Colors.grey),
                _infoRow(Icons.payments, isUrdu ? 'وظیفہ' : 'Salary', "Rs ${t['salary'] ?? '-'}", Colors.green.shade700),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStaffCard(Map<String, dynamic> s, bool isUrdu) {
    final name = s['name']?.toString() ?? '?';
    final staffType = s['staff_type']?.toString() ?? 'دیگر';
    final phone = s['phone']?.toString() ?? '-';
    final responsibilities = s['responsibilities']?.toString() ?? '';
    final status = s['status']?.toString() ?? 'Active';
    final Color statusColor = status == 'Active' ? Colors.orange.shade700 : Colors.red;

    // عہدے کا آئیکن
    final typeIcon = staffTypes.firstWhere(
      (st) => st['value'] == staffType,
      orElse: () => {'value': '', 'icon': '👤'},
    )['icon']!;

    Widget leadingWidget = _buildAvatar(s, typeIcon, Colors.orange.shade100, Colors.orange.shade800, isEmoji: true);

    return Card(
      key: ValueKey("staff_${s["id"]}"),
      color: Colors.white,
      elevation: 2,
      margin: const EdgeInsets.symmetric(vertical: 5),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: Colors.orange.shade100, width: 1),
      ),
      child: ExpansionTile(
        leading: leadingWidget,
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
        subtitle: Row(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.orange.shade50,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: Colors.orange.shade200),
              ),
              child: Text("$typeIcon  $staffType",
                  style: TextStyle(color: Colors.orange.shade800, fontWeight: FontWeight.bold, fontSize: 12)),
            ),
          ],
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: statusColor.withOpacity(0.1),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                status == 'Active' ? (isUrdu ? 'موجود' : 'Active') : (isUrdu ? 'فارغ' : 'Inactive'),
                style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 11),
              ),
            ),
            IconButton(icon: const Icon(Icons.edit, color: Colors.blue, size: 18), onPressed: () => _showAddStaffDialog(staff: s)),
            IconButton(icon: const Icon(Icons.delete, color: Colors.red, size: 18), onPressed: () => _deleteRecord(s['id'])),
          ],
        ),
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _infoRow(Icons.phone, isUrdu ? 'موبائل' : 'Mobile', phone, Colors.blue),
                if (responsibilities.isNotEmpty)
                  _infoRow(Icons.task_alt, isUrdu ? 'ذمہ داریاں' : 'Responsibilities', responsibilities, Colors.teal),
                _infoRow(Icons.calendar_today, isUrdu ? 'تقرری' : 'Joined', s['joining_date']?.toString() ?? '-', Colors.grey),
                _infoRow(Icons.payments, isUrdu ? 'تنخواہ' : 'Salary', "Rs ${s['salary'] ?? '-'}", Colors.orange.shade700),
                if ((s['address']?.toString() ?? '').isNotEmpty)
                  _infoRow(Icons.location_on, isUrdu ? 'پتہ' : 'Address', s['address']?.toString() ?? '', Colors.red),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _infoRow(IconData icon, String label, String value, Color color) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 6),
          Text("$label: ", style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 12)),
          Expanded(child: Text(value, style: const TextStyle(fontSize: 12))),
        ],
      ),
    );
  }

  Widget _buildAvatar(Map<String, dynamic> t, String fallback, Color bg, Color fg, {bool isEmoji = false}) {
    final photo = t['photo']?.toString() ?? '';
    if (photo.isNotEmpty) {
      ImageProvider? imgProvider;
      if (photo.startsWith('http') || photo.startsWith('uploads/')) {
        final url = photo.startsWith('http') ? photo : '${apiUrl.endsWith('/') ? apiUrl : '$apiUrl/'}$photo';
        imgProvider = NetworkImage(url);
      } else if (File(photo).existsSync()) {
        imgProvider = FileImage(File(photo));
      }
      if (imgProvider != null) {
        return CircleAvatar(backgroundImage: imgProvider);
      }
    }
    return CircleAvatar(
      backgroundColor: bg,
      child: isEmoji
          ? Text(fallback, style: const TextStyle(fontSize: 20))
          : Text(fallback, style: TextStyle(color: fg, fontWeight: FontWeight.bold)),
    );
  }

  // ══════════════════════════════════════════════
  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
          child: Scaffold(
            drawer: const AppDrawer(),
            appBar: AppBar(
              leading: Builder(
                builder: (context) => IconButton(
                  icon: const Icon(Icons.menu, color: Colors.white),
                  onPressed: () => Scaffold.of(context).openDrawer(),
                ),
              ),
              title: Text(isUrdu ? "اساتذہ و عملہ ریکارڈ" : "Teachers & Staff Record"),
              backgroundColor: const Color(0xFF284D3D),
              foregroundColor: Colors.white,
              actions: [
                IconButton(
                  icon: const Icon(Icons.sync, color: Colors.white),
                  tooltip: isUrdu ? 'سنک کریں' : 'Sync',
                  onPressed: () async {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrdu ? "ڈیٹا سنک ہو رہا ہے..." : "Syncing...")));
                    try {
                      await SyncUIHelper.performManualSync(context);
                      if (mounted) {
                        _refreshAll();
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu ? "سنک مکمل!" : "Sync complete!"),
                            backgroundColor: Colors.green));
                      }
                    } catch (e) {
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu ? "سنک میں خرابی آئی" : "Sync failed"),
                            backgroundColor: Colors.red));
                      }
                    }
                  },
                ),
              ],
              bottom: TabBar(
                controller: _tabController,
                indicatorColor: const Color(0xFFD4AC0D),
                labelColor: Colors.white,
                unselectedLabelColor: Colors.white60,
                tabs: [
                  Tab(
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      const Text("📖  "),
                      Text(isUrdu ? "اساتذہ" : "Teachers"),
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(color: const Color(0xFFD4AC0D), borderRadius: BorderRadius.circular(10)),
                        child: Text("${_allTeachers.length}", style: const TextStyle(color: Colors.black, fontSize: 11, fontWeight: FontWeight.bold)),
                      ),
                    ]),
                  ),
                  Tab(
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      const Text("👷  "),
                      Text(isUrdu ? "دیگر عملہ" : "Other Staff"),
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(color: Colors.orange.shade400, borderRadius: BorderRadius.circular(10)),
                        child: Text("${_allStaff.length}", style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                      ),
                    ]),
                  ),
                ],
              ),
            ),
            floatingActionButton: FloatingActionButton.extended(
              backgroundColor: const Color(0xFF284D3D),
              onPressed: _showAddChoiceSheet,
              icon: const Icon(Icons.add, color: Colors.white),
              label: Text(isUrdu ? "نیا شامل کریں" : "Add New", style: const TextStyle(color: Colors.white)),
            ),
            body: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1100),
                child: TabBarView(
                  controller: _tabController,
              children: [
                // ── Tab 1: اساتذہ ──
                Padding(
                  padding: const EdgeInsets.all(10),
                  child: Column(children: [
                    TextField(
                      onChanged: _runFilterTeachers,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'تلاش (نام یا موبائل)' : 'Search (Name or Phone)',
                        suffixIcon: const Icon(Icons.search),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                        filled: true,
                        fillColor: Colors.grey.shade100,
                      ),
                    ),
                    const SizedBox(height: 10),
                    Expanded(
                      child: _foundTeachers.isNotEmpty
                          ? ListView.builder(
                              itemCount: _foundTeachers.length,
                              itemBuilder: (context, i) => _buildTeacherCard(_foundTeachers[i], isUrdu),
                            )
                          : Center(child: Text(
                              isUrdu ? 'کوئی استاد نہیں ملا، نیا شامل کریں (+)' : 'No teachers found. Add new (+)',
                              style: const TextStyle(fontSize: 16))),
                    ),
                  ]),
                ),
                // ── Tab 2: دیگر عملہ ──
                Padding(
                  padding: const EdgeInsets.all(10),
                  child: Column(children: [
                    TextField(
                      onChanged: _runFilterStaff,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'تلاش (نام یا عہدہ)' : 'Search (Name or Designation)',
                        suffixIcon: const Icon(Icons.search),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                        filled: true,
                        fillColor: Colors.orange.shade50,
                      ),
                    ),
                    const SizedBox(height: 10),
                    Expanded(
                      child: _foundStaff.isNotEmpty
                          ? ListView.builder(
                              itemCount: _foundStaff.length,
                              itemBuilder: (context, i) => _buildStaffCard(_foundStaff[i], isUrdu),
                            )
                          : Center(child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const Text("👷", style: TextStyle(fontSize: 50)),
                                const SizedBox(height: 12),
                                Text(
                                  isUrdu ? 'کوئی عملہ نہیں ملا\nنیا شامل کریں (+)' : 'No staff found\nAdd new (+)',
                                  style: const TextStyle(fontSize: 16),
                                  textAlign: TextAlign.center,
                                ),
                              ],
                            )),
                    ),
                  ]),
                ),
              ],
            ),
          ),
        ),
      ),
    );
      },
    );
  }
}
