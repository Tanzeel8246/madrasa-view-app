// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously

import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import 'package:intl/intl.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/teacher_period_utils.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';

class TeacherAttendancePage extends StatefulWidget {
  const TeacherAttendancePage({super.key});

  @override
  _TeacherAttendancePageState createState() => _TeacherAttendancePageState();
}

class _TeacherAttendancePageState extends State<TeacherAttendancePage> {
  DateTime _selectedDate = DateTime.now();
  List<TeacherPeriodAssignment> _assignments = [];
  int? _selectedTeacherId;
  final Map<String, String> _attendanceStatus = {};
  final Map<String, bool> _isLate = {};
  final Map<String, TextEditingController> _lateMinControllers = {};
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    for (final c in _lateMinControllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  String _assignmentKey(TeacherPeriodAssignment a) =>
      '${a.teacherId}|${a.className}|${a.periodNo}';

  String _normalizeStatus(dynamic value) {
    final raw = value?.toString().trim() ?? '';
    final code = raw.toUpperCase();
    if (raw == '\u062d\u0627\u0636\u0631' || code == 'P') return 'P';
    if (raw ==
            '\u0631\u062e\u0635\u062a \u0627\u062a\u0641\u0627\u0642\u06cc' ||
        code == 'LA') {
      return 'LA';
    }
    if (raw == '\u0628\u06a9\u0627\u0631 \u0645\u062f\u0631\u0633\u06c1' ||
        code == 'OD') {
      return 'OD';
    }
    if (raw == '\u0631\u062e\u0635\u062a' || code == 'L') {
      return 'L';
    }
    return raw.isEmpty ? 'P' : raw;
  }

  List<MapEntry<int, String>> get _teachers {
    final map = <int, String>{};
    for (final assignment in _assignments) {
      map[assignment.teacherId] = assignment.teacherName;
    }
    return map.entries.toList()..sort((a, b) => a.value.compareTo(b.value));
  }

  List<TeacherPeriodAssignment> get _visibleAssignments {
    if (_selectedTeacherId == null) return const [];
    return _assignments
        .where((assignment) => assignment.teacherId == _selectedTeacherId)
        .toList();
  }

  Future<void> _loadData() async {
    final db = DBHelper();
    final allTeachers = await db.getTeachers();
    final classes = await db.getClassesForUser();
    final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);

    final teachers = allTeachers.where((t) {
      final joinDate = t['joining_date']?.toString().trim();
      if (joinDate != null && joinDate.isNotEmpty && joinDate != 'null') {
        final cleanJoin = joinDate.length >= 10 ? joinDate.substring(0, 10) : joinDate;
        if (cleanJoin.compareTo(dateStr) > 0) {
          return false; // استاذ کی تقرری اس تاریخ کے بعد ہوئی تھی
        }
      }
      final status = (t['status'] ?? '').toString().trim().toLowerCase();
      final statusDate = (t['status_change_date'] ?? t['resignation_date'])?.toString().trim();
      
      bool isActiveStatus = status == 'active' || status == 'حاضر' || status == 'فعال' || status.isEmpty;
      
      if (!isActiveStatus) {
        if (statusDate == null || statusDate.isEmpty || statusDate == 'null') {
           return false; // Exclude unconditionally if no status date is provided and they are inactive.
        } else {
           final cleanStatus = statusDate.length >= 10 ? statusDate.substring(0, 10) : statusDate;
           if (cleanStatus.compareTo(dateStr) <= 0) {
             return false;
           }
        }
      }
      return true;
    }).toList();

    final existingRows = await db.getTeacherPeriodAttendanceByDate(dateStr);
    final assignments = buildTeacherPeriodAssignments(
      teachers: teachers,
      classes: classes,
    );

    for (final c in _lateMinControllers.values) {
      c.dispose();
    }
    _attendanceStatus.clear();
    _isLate.clear();
    _lateMinControllers.clear();

    for (final assignment in assignments) {
      final key = _assignmentKey(assignment);
      Map<String, dynamic>? existing;
      for (final row in existingRows) {
        final teacherId =
            int.tryParse(row['teacher_id']?.toString() ?? '') ?? 0;
        final periodNo = int.tryParse(row['period_no']?.toString() ?? '') ?? 0;
        final className = row['class_name']?.toString() ?? '';
        if (teacherId == assignment.teacherId &&
            periodNo == assignment.periodNo &&
            className == assignment.className) {
          existing = row;
          break;
        }
      }
      final late =
          int.tryParse(existing?['late_minutes']?.toString() ?? '0') ?? 0;
      _attendanceStatus[key] = _normalizeStatus(existing?['status']);
      _isLate[key] = late > 0;
      _lateMinControllers[key] =
          TextEditingController(text: late > 0 ? late.toString() : '');
    }

    if (!mounted) return;
    setState(() {
      _assignments = assignments;
      if (_selectedTeacherId != null &&
          !assignments.any((a) => a.teacherId == _selectedTeacherId)) {
        _selectedTeacherId = null;
      }
    });
  }

  Future<void> _selectDate(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked != null && picked != _selectedDate) {
      setState(() => _selectedDate = picked);
      await _loadData();
    }
  }

  Future<void> _saveAttendance() async {
    if (_isSaving) return;

    final rowsToSave = _visibleAssignments;
    if (rowsToSave.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'استاد منتخب کریں'
              : 'Select a teacher first'),
          backgroundColor: Colors.red.shade700,
        ),
      );
      return;
    }

    setState(() => _isSaving = true);
    final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
    try {
      final db = DBHelper();
      for (final assignment in rowsToSave) {
        final key = _assignmentKey(assignment);
        final status = _attendanceStatus[key] ?? 'P';
        final lateText = status == 'P' && (_isLate[key] ?? false)
            ? (_lateMinControllers[key]?.text.trim() ?? '0')
            : '0';
        await db.insertTeacherPeriodAttendance({
          'teacher_id': assignment.teacherId,
          'class_id': assignment.classId,
          'class_name': assignment.className,
          'date': dateStr,
          'period_no': assignment.periodNo,
          'status': status,
          'late_minutes': int.tryParse(lateText.isEmpty ? '0' : lateText) ?? 0,
          'remarks': '',
        });
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'استاد کی پیریڈ وار حاضری محفوظ ہو گئی!'
              : 'Teacher period attendance saved!'),
          backgroundColor: Colors.green.shade700,
        ),
      );
      SyncService.runTwoWaySync().catchError((_) {});
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'اساتذہ حاضری محفوظ نہیں ہو سکی: $e'
              : 'Teacher attendance could not be saved: $e'),
          backgroundColor: Colors.red.shade700,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
          child: Scaffold(
            drawer: const AppDrawer(),
            appBar: AppBar(
              title: Text(isUrdu ? 'اساتذہ کی حاضری' : 'Teacher Attendance'),
              backgroundColor: Colors.green.shade700,
              foregroundColor: Colors.white,
              actions: [
                IconButton(
                  icon: const Icon(Icons.sync),
                  tooltip: isUrdu ? 'سنک کریں' : 'Sync',
                  onPressed: () async {
                    await SyncService.runTwoWaySync();
                    await _loadData();
                  },
                ),
              ],
            ),
            body: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    children: [
                      InkWell(
                        onTap: () => _selectDate(context),
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              vertical: 15, horizontal: 10),
                          decoration: BoxDecoration(
                            border: Border.all(color: Colors.grey),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                DateFormat('dd MMMM, yyyy')
                                    .format(_selectedDate),
                                style: const TextStyle(fontSize: 16),
                              ),
                              const Icon(Icons.calendar_today,
                                  color: Colors.green),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<int>(
                        value: _selectedTeacherId,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText:
                              isUrdu ? 'استاد منتخب کریں' : 'Select Teacher',
                          border: const OutlineInputBorder(),
                          contentPadding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 12),
                        ),
                        items: _teachers
                            .map((teacher) => DropdownMenuItem<int>(
                                  value: teacher.key,
                                  child: Text(teacher.value),
                                ))
                            .toList(),
                        onChanged: (value) {
                          setState(() => _selectedTeacherId = value);
                        },
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: _assignments.isEmpty
                      ? Center(
                          child: Text(isUrdu
                              ? 'کسی استاد کے لیے کلاس/پیریڈ الاٹ نہیں۔'
                              : 'No teacher class/period assignments found'),
                        )
                      : _selectedTeacherId == null
                          ? Center(
                              child: Text(isUrdu
                                  ? 'استاد منتخب کریں'
                                  : 'Select a teacher'),
                            )
                          : ListView.builder(
                              itemCount: _visibleAssignments.length,
                              itemBuilder: (context, index) {
                                final assignment = _visibleAssignments[index];
                                return _buildAttendanceCard(assignment, isUrdu);
                              },
                            ),
                ),
                Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: ElevatedButton.icon(
                      onPressed: _isSaving ? null : _saveAttendance,
                      icon: _isSaving
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Icon(Icons.save, color: Colors.white),
                      style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green.shade700),
                      label: Text(
                        _isSaving
                            ? (isUrdu ? 'محفوظ ہو رہا ہے...' : 'Saving...')
                            : (isUrdu ? 'حاضری محفوظ کریں' : 'Save Attendance'),
                        style:
                            const TextStyle(fontSize: 18, color: Colors.white),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildAttendanceCard(TeacherPeriodAssignment assignment, bool isUrdu) {
    final key = _assignmentKey(assignment);
    final currentStatus = _attendanceStatus[key] ?? 'P';
    final isPresent = currentStatus == 'P';
    final isLate = _isLate[key] ?? false;

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              assignment.teacherName,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 4),
            Text(
              '${assignment.className} - ${isUrdu ? assignment.periodLabelUr : assignment.periodLabelEn}',
              style: TextStyle(color: Colors.grey.shade700),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildStatusChip(
                    key, 'P', isUrdu ? 'حاضر' : 'Present', Colors.green),
                _buildStatusChip(
                    key, 'L', isUrdu ? 'رخصت' : 'Leave', Colors.orange),
                _buildStatusChip(key, 'LA',
                    isUrdu ? 'رخصت اتفاقی' : 'Casual Leave', Colors.blue),
                _buildStatusChip(
                    key, 'OD', isUrdu ? 'بکار مدرسہ' : 'On Duty', Colors.teal),
              ],
            ),
            if (isPresent) ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      value: isLate ? 'late' : '0',
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'تاخیر' : 'Delay',
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 10, vertical: 8),
                      ),
                      items: [
                        DropdownMenuItem(
                            value: '0',
                            child: Text(isUrdu ? 'بلا تاخیر' : 'On Time')),
                        DropdownMenuItem(
                            value: 'late',
                            child: Text(isUrdu ? 'تاخیر' : 'Late')),
                      ],
                      onChanged: (val) {
                        setState(() {
                          _isLate[key] = val == 'late';
                          if (val != 'late') _lateMinControllers[key]?.clear();
                        });
                      },
                    ),
                  ),
                  if (isLate) ...[
                    const SizedBox(width: 8),
                    SizedBox(
                      width: 90,
                      child: TextFormField(
                        controller: _lateMinControllers[key],
                        keyboardType: TextInputType.number,
                        textAlign: TextAlign.center,
                        decoration: InputDecoration(
                          labelText: isUrdu ? 'منٹ' : 'Min',
                          border: const OutlineInputBorder(),
                          contentPadding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 8),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildStatusChip(String key, String value, String label, Color color) {
    final isSelected = _attendanceStatus[key] == value;
    return InkWell(
      onTap: () {
        setState(() {
          _attendanceStatus[key] = value;
          if (value != 'P') {
            _isLate[key] = false;
            _lateMinControllers[key]?.clear();
          }
        });
      },
      child: Container(
        constraints: const BoxConstraints(minWidth: 92),
        padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 12),
        decoration: BoxDecoration(
          color: isSelected ? color : Colors.transparent,
          border: Border.all(color: color),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: isSelected ? Colors.white : color,
            fontWeight: FontWeight.bold,
          ),
        ),
      ),
    );
  }
}
