// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../utils/sync_ui_helper.dart';
import '../utils/teacher_period_utils.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'maktab_students.dart';
import 'class_periods_config.dart';

class MaktabClassesPage extends StatefulWidget {
  const MaktabClassesPage({super.key});

  @override
  _MaktabClassesPageState createState() => _MaktabClassesPageState();
}

class _MaktabClassesPageState extends State<MaktabClassesPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  final List<String> _classTypes = const [
    'Hifz/Gardan/Husn',
    'Husn-e-Saut',
    'Nazira',
    'Qaida',
    'Tajweed',
    'Asri Taleem',
    'Darse Nizami',
  ];

  String _translateClassType(String type) {
    switch (type) {
      case 'Hifz/Gardan/Husn': return 'حفظ و گردان';
      case 'Husn-e-Saut': return 'حسنِ صوت';
      case 'Nazira': return 'ناظرہ';
      case 'Qaida': return 'نورانی قاعدہ';
      case 'Tajweed': return 'تجوید';
      case 'Asri Taleem': return 'عصری تعلیم';
      case 'Darse Nizami': return 'درسِ نظامی';
      default: return type;
    }
  }

  List<Map<String, dynamic>> _classes = [];
  List<Map<String, dynamic>> _teachers = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    var classes = await DBHelper().getMaktabClasses();
    var teachers = await DBHelper().getMaktabTeachers();
    if (!mounted) return;
    setState(() {
      _classes = classes;
      _teachers = teachers;
      _isLoading = false;
    });

    if (classes.isEmpty) {
      SyncService.runTwoWaySync().then((_) async {
        if (!mounted) return;
        final refreshedClasses = await DBHelper().getMaktabClasses();
        final refreshedTeachers = await DBHelper().getMaktabTeachers();
        if (mounted && refreshedClasses.isNotEmpty) {
          setState(() {
            _classes = refreshedClasses;
            _teachers = refreshedTeachers;
          });
        }
      }).catchError((_) {});
    }
  }

  Future<void> _syncData(bool isUrdu) async {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(isUrdu ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...'),
    ));
    try {
      await SyncUIHelper.performManualSync(context);
      await _loadData();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu ? 'سنک مکمل ہو گیا!' : 'Sync complete!'),
        backgroundColor: Colors.green,
      ));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu ? 'سنک میں خرابی آئی' : 'Sync failed'),
        backgroundColor: Colors.red,
      ));
    }
  }

  List<String> _decodeTeacherIds(dynamic raw) {
    if (raw == null) return [];
    final text = raw.toString().trim();
    if (text.isEmpty) return [];
    try {
      final decoded = jsonDecode(text);
      if (decoded is List) return decoded.map((e) => e.toString()).toList();
    } catch (_) {}
    return text
        .split(',')
        .map((e) => e.trim())
        .where((e) => e.isNotEmpty)
        .toList();
  }

  String _teacherNames(dynamic raw) {
    final ids = _decodeTeacherIds(raw).toSet();
    if (ids.isEmpty) return '';
    final names = _teachers
        .where((t) => ids.contains(t['id']?.toString()))
        .map((t) => t['name']?.toString() ?? '')
        .where((name) => name.isNotEmpty)
        .toList();
    return names.join('، ');
  }

  List<Map<String, String>> _getPeriodDefinitionsForClass(
      String classType, String className, bool isUrdu) {
    final clean = '${classType.toLowerCase()} ${className.toLowerCase()}';
    if (clean.contains('nazira') || clean.contains('ناظرہ') || clean.contains('qaida') || clean.contains('قاعدہ')) {
      return [
        {'period_no': '1', 'label': isUrdu ? 'پیریڈ 1: صبح کا سیشن' : 'Period 1: Morning Shift'},
        {'period_no': '2', 'label': isUrdu ? 'پیریڈ 2: شام کا سیشن' : 'Period 2: Evening Shift'},
      ];
    }
    if (clean.contains('husn') || clean.contains('حسن') || clean.contains('صوت')) {
      return [
        {'period_no': '1', 'label': isUrdu ? 'پیریڈ 1: صبح کا سیشن' : 'Period 1: Morning Shift'},
        {'period_no': '2', 'label': isUrdu ? 'پیریڈ 2: دوپہر کا سیشن' : 'Period 2: Noon Shift'},
        {'period_no': '3', 'label': isUrdu ? 'پیریڈ 3: عصر / شام کا سیشن' : 'Period 3: Evening Shift'},
        {'period_no': '4', 'label': isUrdu ? 'پیریڈ 4: مغرب / عشاء کا سیشن' : 'Period 4: Night Shift'},
      ];
    }
    if (clean.contains('hifz') || clean.contains('حفظ') || clean.contains('gardan') || clean.contains('گردان')) {
      return [
        {'period_no': '1', 'label': isUrdu ? 'پیریڈ 1: صبح کا سیشن (سبق)' : 'Period 1: Morning (Sabaq)'},
        {'period_no': '2', 'label': isUrdu ? 'پیریڈ 2: ناشتہ / دوپہر (سبقی)' : 'Period 2: Noon (Sabqi)'},
        {'period_no': '3', 'label': isUrdu ? 'پیریڈ 3: عصر / شام (منزل)' : 'Period 3: Evening (Manzil)'},
        {'period_no': '4', 'label': isUrdu ? 'پیریڈ 4: مغرب / عشاء (تکرار)' : 'Period 4: Night (Takrar)'},
      ];
    }
    return [
      {'period_no': '1', 'label': isUrdu ? 'پہلا پیریڈ (Period 1)' : 'Period 1'},
      {'period_no': '2', 'label': isUrdu ? 'دوسرا پیریڈ (Period 2)' : 'Period 2'},
      {'period_no': '3', 'label': isUrdu ? 'تیسرا پیریڈ (Period 3)' : 'Period 3'},
      {'period_no': '4', 'label': isUrdu ? 'چوتھا پیریڈ (Period 4)' : 'Period 4'},
      {'period_no': '5', 'label': isUrdu ? 'پانچواں پیریڈ (Period 5)' : 'Period 5'},
      {'period_no': '6', 'label': isUrdu ? 'چھٹا پیریڈ (Period 6)' : 'Period 6'},
      {'period_no': '7', 'label': isUrdu ? 'ساتواں پیریڈ (مطالعہ)' : 'Period 7 (Study)'},
      {'period_no': '8', 'label': isUrdu ? 'آٹھواں پیریڈ (تکرار)' : 'Period 8 (Review)'},
    ];
  }

  Future<void> _showClassDialog(bool isUrdu,
      [Map<String, dynamic>? klass]) async {
    final nameController =
        TextEditingController(text: klass?['name']?.toString() ?? '');
    final sectionController =
        TextEditingController(text: klass?['section']?.toString() ?? '');
    final subjectsController =
        TextEditingController(text: klass?['subjects_list']?.toString() ?? '');
    String classType = klass?['class_type']?.toString() ?? 'Nazira';
    if (!_classTypes.contains(classType)) classType = 'Nazira';
    String status = klass?['status']?.toString() ?? 'Active';
    final selectedTeacherIds =
        _decodeTeacherIds(klass?['main_teacher_ids']).toSet();

    final initialClassName = klass?['name']?.toString() ?? '';
    final Map<int, Set<String>> periodTeacherIds = {};
    for (int p = 1; p <= 8; p++) {
      periodTeacherIds[p] = <String>{};
    }
    if (initialClassName.isNotEmpty) {
      for (final t in _teachers) {
        final tId = t['id']?.toString() ?? '';
        final ap = decodeTeacherAssignedPeriods(t['assigned_periods'], {initialClassName});
        final periods = ap[initialClassName] ?? {};
        for (final pStr in periods) {
          final pNo = periodNoFromKey(pStr);
          if (pNo >= 1 && pNo <= 8) {
            periodTeacherIds[pNo]?.add(tId);
          }
        }
      }
    }

    StateSetter? dialogSetState;
    void nameListener() {
      if (dialogSetState != null) {
        dialogSetState!(() {});
      }
    }
    nameController.addListener(nameListener);

    await showDialog(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) {
          dialogSetState = setDialogState;
          return AlertDialog(
            title: Text(klass == null
                ? (isUrdu ? 'نئی کلاس شامل کریں' : 'Add New Class')
                : (isUrdu ? 'کلاس میں ترمیم' : 'Edit Class')),
            content: SizedBox(
              width: 520,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextField(
                      controller: nameController,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'کلاس کا نام' : 'Class Name',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: sectionController,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'سیکشن' : 'Section',
                        hintText: isUrdu ? 'مثلاً الف، ب' : 'e.g. A, B',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: classType,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'کلاس قسم' : 'Class Type',
                        border: const OutlineInputBorder(),
                      ),
                      items: _classTypes
                          .map((type) => DropdownMenuItem(
                              value: type,
                              child: Text(isUrdu
                                  ? _translateClassType(type)
                                  : type)))
                          .toList(),
                      onChanged: (value) =>
                          setDialogState(() => classType = value ?? classType),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: status,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'حالت' : 'Status',
                        border: const OutlineInputBorder(),
                      ),
                      items: [
                        DropdownMenuItem(
                            value: 'Active',
                            child: Text(isUrdu ? 'فعال' : 'Active')),
                        DropdownMenuItem(
                            value: 'Inactive',
                            child: Text(isUrdu ? 'غیر فعال' : 'Inactive')),
                      ],
                      onChanged: (value) =>
                          setDialogState(() => status = value ?? 'Active'),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: subjectsController,
                      maxLines: 2,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'مضامین/نصاب' : 'Subjects',
                        hintText:
                            isUrdu ? 'مثلاً قرآن:100، ریاضی:50' : 'Quran:100',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 14),
                    Align(
                      alignment:
                          isUrdu ? Alignment.centerRight : Alignment.centerLeft,
                      child: Text(
                        isUrdu ? 'پیریڈز اور اساتذہ کا تقرر' : 'Periods & Teacher Assignments',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                      ),
                    ),
                    const SizedBox(height: 6),
                    Builder(builder: (context) {
                      final currentPeriods = _getPeriodDefinitionsForClass(
                          classType, nameController.text, isUrdu);
                      return Column(
                        children: currentPeriods.map((pDef) {
                          final pNo = int.parse(pDef['period_no']!);
                          final pLabel = pDef['label']!;
                          final assigned = periodTeacherIds[pNo] ?? <String>{};
                          return Container(
                            key: ValueKey('period_container_$pNo'),
                            margin: const EdgeInsets.only(bottom: 8),
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: Colors.grey.shade50,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: Colors.grey.shade300),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(pLabel,
                                    style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        color: primaryGreen,
                                        fontSize: 13)),
                                const SizedBox(height: 4),
                                if (_teachers.isEmpty)
                                  Text(
                                    isUrdu ? 'کوئی استاد موجود نہیں' : 'No teachers found',
                                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                  )
                                else
                                  Wrap(
                                    spacing: 6,
                                    runSpacing: 4,
                                    children: _teachers.map((teacher) {
                                      final tId = teacher['id']?.toString() ?? '';
                                      final tName = teacher['name']?.toString() ?? '';
                                      final isSelected = assigned.contains(tId);
                                      return FilterChip(
                                        key: ValueKey('period_${pNo}_teacher_$tId'),
                                        label: Text(tName, style: const TextStyle(fontSize: 12)),
                                        selected: isSelected,
                                        selectedColor: primaryGreen.withOpacity(0.2),
                                        checkmarkColor: primaryGreen,
                                        onSelected: (val) {
                                          setDialogState(() {
                                            periodTeacherIds.putIfAbsent(pNo, () => <String>{});
                                            if (val) {
                                              periodTeacherIds[pNo]!.add(tId);
                                              selectedTeacherIds.add(tId);
                                            } else {
                                              periodTeacherIds[pNo]!.remove(tId);
                                            }
                                          });
                                        },
                                      );
                                    }).toList(),
                                  ),
                              ],
                            ),
                          );
                        }).toList(),
                      );
                    }),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrdu ? 'منسوخ' : 'Cancel'),
              ),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                icon: const Icon(Icons.save, color: Colors.white),
                label: Text(
                  isUrdu ? 'محفوظ کریں' : 'Save',
                  style: const TextStyle(color: Colors.white),
                ),
                onPressed: () async {
                  final name = nameController.text.trim();
                  if (name.isEmpty) return;

                  final currentPeriods = _getPeriodDefinitionsForClass(
                      classType, name, isUrdu);
                  final allAssignedTeacherIds = <String>{...selectedTeacherIds};
                  for (final pDef in currentPeriods) {
                    final pNo = int.parse(pDef['period_no']!);
                    if (periodTeacherIds.containsKey(pNo)) {
                      allAssignedTeacherIds.addAll(periodTeacherIds[pNo]!);
                    }
                  }

                  final data = {
                    'name': name,
                    'section': sectionController.text.trim(),
                    'class_type': classType,
                    'status': status,
                    'subjects_list': subjectsController.text.trim(),
                    'main_teacher_ids': jsonEncode(allAssignedTeacherIds.toList()),
                    'is_synced': 0,
                  };

                  if (klass == null) {
                    final isSuper = currentUserRole.value == 'superadmin' || 
                        (currentUserRole.value == 'admin' && currentUserId.value == 1);
                    final maxCls = currentTenantMaxClasses.value;
                    final activeCount = _classes.where((c) => (c['status'] == 'Active' || c['status'] == null || c['status'] == '') && (c['is_deleted'] == 0 || c['is_deleted'] == null)).length;
                    if (!isSuper && activeCount >= maxCls) {
                      showDialog(
                        context: context,
                        builder: (ctx) => AlertDialog(
                          title: Row(
                            children: [
                              const Icon(Icons.lock, color: Colors.red),
                              const SizedBox(width: 8),
                              Text(isUrdu ? 'کلاسز کی حد مکمل' : 'Class Limit Reached'),
                            ],
                          ),
                          content: Text(
                            isUrdu
                                ? 'آپ کے موجودہ پلان (${currentTenantPlanName.value}) میں کلاسز کی زیادہ سے زیادہ حد ($maxCls) مکمل ہو چکی ہے۔\nمزید کلاسز کے اندراج کے لیے براہ کرم اپنا سبسکرپشن پلان اپ گریڈ کریں۔'
                                : 'You have reached the maximum class limit ($maxCls) for your plan (${currentTenantPlanName.value}). Please upgrade your plan to add more classes.',
                          ),
                          actions: [
                            ElevatedButton(
                              style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                              onPressed: () => Navigator.pop(ctx),
                              child: Text(isUrdu ? 'سمجھ گیا' : 'OK', style: const TextStyle(color: Colors.white)),
                            ),
                          ],
                        ),
                      );
                      return;
                    }
                    await DBHelper().insertMaktabClass(data);
                  } else {
                    await DBHelper().updateMaktabClass(klass['id'] as int, data);
                  }

                  // Sync each teacher's assigned_classes and assigned_periods in SQLite
                  for (final teacher in _teachers) {
                    final tId = teacher['id'];
                    final tIdStr = tId?.toString() ?? '';
                    if (tId == null || tIdStr.isEmpty) continue;

                    var currentAssignedClasses = (teacher['assigned_classes']?.toString() ?? '')
                        .split(',')
                        .map((e) => e.trim())
                        .where((e) => e.isNotEmpty)
                        .toSet();

                    var currentAssignedPeriods = decodeTeacherAssignedPeriods(
                        teacher['assigned_periods'], currentAssignedClasses);

                    if (initialClassName.isNotEmpty && initialClassName != name) {
                      currentAssignedClasses.remove(initialClassName);
                      currentAssignedPeriods.remove(initialClassName);
                    }

                    final teacherPeriodsForThisClass = <String>[];
                    for (final pDef in currentPeriods) {
                      final pNo = int.parse(pDef['period_no']!);
                      if (periodTeacherIds[pNo]?.contains(tIdStr) == true) {
                        teacherPeriodsForThisClass.add(pNo.toString());
                      }
                    }

                    if (teacherPeriodsForThisClass.isNotEmpty) {
                      currentAssignedClasses.add(name);
                      currentAssignedPeriods[name] = teacherPeriodsForThisClass.toSet();
                    } else {
                      currentAssignedClasses.remove(name);
                      currentAssignedPeriods.remove(name);
                    }

                    final updatedPeriodsMap = <String, List<String>>{};
                    currentAssignedPeriods.forEach((cName, pSet) {
                      if (pSet.isNotEmpty) updatedPeriodsMap[cName] = pSet.toList()..sort();
                    });

                    await DBHelper().updateMaktabTeacher(tId as int, {
                      'assigned_classes': currentAssignedClasses.join(','),
                      'assigned_periods': jsonEncode(updatedPeriodsMap),
                      'is_synced': 0,
                    });
                  }

                  SyncService.runTwoWaySync().catchError((_) {});
                  if (!mounted) return;
                  Navigator.pop(context);
                  await _loadData();
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text(isUrdu
                        ? 'کلاس محفوظ کر دی گئی'
                        : 'Class saved successfully'),
                    backgroundColor: Colors.green,
                  ));
                },
              ),
            ],
          );
        },
      ),
    );
    nameController.removeListener(nameListener);
  }

  Future<void> _deleteMaktabClass(Map<String, dynamic> klass, bool isUrdu) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrdu ? 'حذف کی تصدیق' : 'Confirm Delete'),
        content: Text(isUrdu
            ? 'کیا آپ واقعی یہ کلاس حذف کرنا چاہتے ہیں؟'
            : 'Do you really want to delete this class?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(isUrdu ? 'نہیں' : 'No'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(context, true),
            icon: const Icon(Icons.delete, color: Colors.white),
            label: Text(isUrdu ? 'حذف کریں' : 'Delete',
                style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    await DBHelper().deleteMaktabClass(klass['id'] as int);
    await _loadData();
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
                title: Text(isUrdu ? 'کلاسز مینجمنٹ' : 'Manage Classes',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, color: Colors.white)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    tooltip: isUrdu ? 'سنک' : 'Sync',
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () => _syncData(isUrdu),
                  ),
                ],
              ),
              body: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _classes.isEmpty
                      ? _buildEmptyState(isUrdu)
                      : Center(
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 1100),
                            child: ListView.builder(
                              padding: const EdgeInsets.all(15),
                              itemCount: _classes.length,
                              itemBuilder: (context, index) {
                                final klass = _classes[index];
                                return _buildClassCard(klass, isUrdu);
                              },
                            ),
                          ),
                        ),
              floatingActionButton: FloatingActionButton(
                backgroundColor: primaryGreen,
                onPressed: () => _showClassDialog(isUrdu),
                child: const Icon(Icons.add, color: Colors.white),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildClassCard(Map<String, dynamic> klass, bool isUrdu) {
    final section = klass['section']?.toString() ?? '';
    final teacherNames = _teacherNames(klass['main_teacher_ids']);
    final subjects = klass['subjects_list']?.toString() ?? '';
    final status = klass['status']?.toString() ?? 'Active';
    final active = status == 'Active';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      child: NeoBox(
        padding: 15,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                      color: primaryGreen.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(10)),
                  child: Icon(Icons.class_, color: primaryGreen),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: InkWell(
                    onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => MaktabStudentsPage(
                                initialClassFilter: klass['name']))),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${klass['name'] ?? ''}${section.isNotEmpty ? ' ($section)' : ''}',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 18),
                        ),
                        const SizedBox(height: 4),
                        Text(isUrdu ? _translateClassType(klass['class_type']?.toString() ?? 'Darse Nizami') : (klass['class_type']?.toString() ?? 'Darse Nizami')),
                      ],
                    ),
                  ),
                ),
                Chip(
                  label: Text(active ? (isUrdu ? 'فعال' : 'Active') : (isUrdu ? 'غیر فعال' : 'Inactive')),
                  backgroundColor:
                      active ? Colors.green.shade50 : Colors.grey.shade200,
                ),
              ],
            ),
            if (teacherNames.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('${isUrdu ? 'اساتذہ' : 'Teachers'}: $teacherNames'),
            ],
            if (subjects.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('${isUrdu ? 'مضامین' : 'Subjects'}: $subjects'),
            ],
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _showClassDialog(isUrdu, klass),
                    icon: const Icon(Icons.edit, size: 18),
                    label: Text(isUrdu ? 'ترمیم' : 'Edit'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _deleteMaktabClass(klass, isUrdu),
                    icon: const Icon(Icons.delete, size: 18),
                    label: Text(isUrdu ? 'حذف' : 'Delete'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: Colors.red,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (klass['name'] != null && 
                !klass['name'].toString().contains('حفظ') && 
                !klass['name'].toString().contains('ناظرہ') && 
                !klass['name'].toString().contains('قاعدہ') &&
                !(klass['class_type']?.toString().toLowerCase().contains('hifz') ?? false) &&
                !(klass['class_type']?.toString().toLowerCase().contains('nazira') ?? false) &&
                !(klass['class_type']?.toString().toLowerCase().contains('qaida') ?? false))
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () {
                        int classId = int.tryParse(klass['id']?.toString() ?? '0') ?? 0;
                        if (classId > 0) {
                          Navigator.push(context, MaterialPageRoute(builder: (c) => ClassPeriodsConfigPage(classId: classId, className: klass['name'] ?? '')));
                        }
                      },
                      icon: const Icon(Icons.list_alt, size: 18),
                      label: Text(isUrdu ? 'پیریڈز کنفیگر' : 'Config Periods'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.indigo,
                      ),
                    ),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState(bool isUrdu) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.class_outlined, size: 80, color: Colors.grey.shade300),
          const SizedBox(height: 20),
          Text(isUrdu ? 'کوئی کلاس نہیں ملی' : 'No classes found',
              style: TextStyle(color: Colors.grey.shade500, fontSize: 18)),
          const SizedBox(height: 10),
          Text(
              isUrdu
                  ? 'نئی کلاس شامل کریں یا ڈیٹا سنک کریں'
                  : 'Add a class or sync data',
              style: TextStyle(color: Colors.grey.shade400, fontSize: 14)),
        ],
      ),
    );
  }
}
