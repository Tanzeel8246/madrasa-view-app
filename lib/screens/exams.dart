// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'exam_results_entry.dart';
import 'result_register_mobile.dart';

class ExamsPage extends StatefulWidget {
  const ExamsPage({super.key});

  @override
  _ExamsPageState createState() => _ExamsPageState();
}

class _ExamsPageState extends State<ExamsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _exams = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadExams();
  }

  Future<void> _loadExams() async {
    final data = await DBHelper().getExams();
    if (!mounted) return;
    setState(() {
      _exams = data;
      _isLoading = false;
    });
  }

  Future<void> _syncData(bool isUrdu) async {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(isUrdu ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...'),
    ));
    try {
      await SyncService.runTwoWaySync();
      await _loadExams();
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

  Future<void> _showAddExamDialog(bool isUrdu) async {
    final nameController = TextEditingController();
    final dateController = TextEditingController(
      text: intl.DateFormat('yyyy-MM-dd').format(DateTime.now()),
    );
    final totalController = TextEditingController(text: '100');
    final passController = TextEditingController(text: '33');

    await showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrdu ? 'نیا امتحان شامل کریں' : 'Add New Exam'),
        content: SizedBox(
          width: 480,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameController,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'امتحان کا نام' : 'Exam Name',
                    hintText: isUrdu
                        ? 'مثلاً سالانہ امتحان 2026'
                        : 'Annual Exam 2026',
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: dateController,
                  readOnly: true,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'تاریخ' : 'Date',
                    border: const OutlineInputBorder(),
                    suffixIcon: const Icon(Icons.calendar_today),
                  ),
                  onTap: () async {
                    final picked = await showDatePicker(
                      context: context,
                      initialDate: DateTime.tryParse(dateController.text) ??
                          DateTime.now(),
                      firstDate: DateTime(2000),
                      lastDate: DateTime(2100),
                    );
                    if (picked != null) {
                      dateController.text =
                          intl.DateFormat('yyyy-MM-dd').format(picked);
                    }
                  },
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: totalController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'کل نمبر' : 'Total Marks',
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: passController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: isUrdu ? 'پاسنگ مارکس' : 'Passing Marks',
                    border: const OutlineInputBorder(),
                  ),
                ),
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
            icon: const Icon(Icons.add, color: Colors.white),
            label: Text(
              isUrdu ? 'شامل کریں' : 'Add',
              style: const TextStyle(color: Colors.white),
            ),
            onPressed: () async {
              final name = nameController.text.trim();
              if (name.isEmpty) return;
              await DBHelper().insertExam({
                'exam_name': name,
                'exam_date': dateController.text.trim(),
                'total_marks':
                    double.tryParse(totalController.text.trim()) ?? 100,
                'passing_marks':
                    double.tryParse(passController.text.trim()) ?? 33,
                'system_type': SystemTypeGlobal.currentSystemType.value,
              });
              if (!mounted) return;
              Navigator.pop(context);
              await _loadExams();
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text(isUrdu
                    ? 'نیا امتحان شامل کر دیا گیا'
                    : 'Exam added successfully'),
                backgroundColor: Colors.green,
              ));
            },
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin = role == 'admin' || role == 'superadmin' || role == 'super_admin' || role == 'administrator';
    final hasExamPerm = isAdmin || currentUserPermissions.value.contains('manage_exams') || currentUserPermissions.value.contains('manage_masjid_exams');

    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        if (!hasExamPerm) {
          return Directionality(
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
                title: Text(isUrdu ? 'امتحانات' : 'Exams',
                    style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
              ),
              body: Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.lock_outline, size: 64, color: Colors.grey.shade400),
                    const SizedBox(height: 16),
                    Text(
                      isUrdu ? 'آپ کے پاس امتحانات تک رسائی کی اجازت نہیں ہے۔' : 'You do not have permission to access Exams.',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black54),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ),
          );
        }

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
                title: Text(isUrdu ? 'امتحانات' : 'Exams',
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
                  : _exams.isEmpty
                      ? _buildEmptyState(isUrdu)
                      : Center(
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 1100),
                            child: ListView.builder(
                              padding: const EdgeInsets.all(15),
                              itemCount: _exams.length,
                              itemBuilder: (context, index) {
                                final exam = _exams[index];
                                return _buildExamCard(exam, isUrdu);
                              },
                            ),
                          ),
                        ),
              floatingActionButton: FloatingActionButton.extended(
                backgroundColor: primaryGreen,
                onPressed: () => _showAddExamDialog(isUrdu),
                icon: const Icon(Icons.add, color: Colors.white),
                label: Text(
                  isUrdu ? 'نیا امتحان' : 'New Exam',
                  style: const TextStyle(color: Colors.white),
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildExamCard(Map<String, dynamic> exam, bool isUrdu) {
    final resultCount =
        int.tryParse(exam['result_count']?.toString() ?? '0') ?? 0;
    final totalStudents =
        int.tryParse(exam['total_students']?.toString() ?? '0') ?? 0;
    final progress = totalStudents > 0 ? resultCount / totalStudents : 0.0;
    final progressPercent = (progress * 100).round();
    final complete = totalStudents > 0 && resultCount >= totalStudents;

    return Container(
      margin: const EdgeInsets.only(bottom: 15),
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
                  child: Icon(Icons.assignment, color: primaryGreen),
                ),
                const SizedBox(width: 15),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(exam['exam_name']?.toString() ?? '',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 18)),
                      const SizedBox(height: 4),
                      Text(
                        '${exam['exam_date'] ?? ''}\n${isUrdu ? 'کل نمبر' : 'Total'}: ${exam['total_marks'] ?? 100} | ${isUrdu ? 'پاس' : 'Pass'}: ${exam['passing_marks'] ?? 33}',
                        style:
                            const TextStyle(color: Colors.grey, fontSize: 13),
                      ),
                    ],
                  ),
                ),
                Chip(
                  label: Text(complete
                      ? (isUrdu ? 'مکمل' : 'Complete')
                      : (isUrdu ? 'جاری' : 'In Progress')),
                  backgroundColor:
                      complete ? Colors.green.shade50 : Colors.amber.shade50,
                ),
              ],
            ),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: progress.clamp(0.0, 1.0),
                minHeight: 7,
                backgroundColor: Colors.grey.shade200,
                valueColor: AlwaysStoppedAnimation<Color>(
                    complete ? Colors.green : Colors.amber.shade700),
              ),
            ),
            const SizedBox(height: 4),
            Text(
              '${isUrdu ? 'رزلٹ' : 'Results'}: $progressPercent% ($resultCount/$totalStudents)',
              style: const TextStyle(fontSize: 12, color: Colors.grey),
            ),
            const Divider(height: 28),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (context) =>
                                  ExamResultsEntryPage(exam: exam)));
                    },
                    icon: const Icon(Icons.edit, size: 18),
                    label: Text(isUrdu ? 'نمبر درج کریں' : 'Enter Marks'),
                    style:
                        OutlinedButton.styleFrom(foregroundColor: primaryGreen),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (context) =>
                                  ResultRegisterMobilePage(exam: exam)));
                    },
                    icon: const Icon(Icons.list_alt, size: 18),
                    label: Text(isUrdu ? 'رزلٹ رجسٹر' : 'Register'),
                    style:
                        OutlinedButton.styleFrom(foregroundColor: Colors.teal),
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
          Icon(Icons.event_busy, size: 80, color: Colors.grey.shade300),
          const SizedBox(height: 20),
          Text(isUrdu ? 'کوئی امتحان نہیں ملا' : 'No exams found',
              style: TextStyle(color: Colors.grey.shade500, fontSize: 18)),
          const SizedBox(height: 10),
          Text(
              isUrdu
                  ? 'نیا امتحان شامل کریں یا ڈیٹا سنک کریں'
                  : 'Add an exam or sync data',
              style: TextStyle(color: Colors.grey.shade400, fontSize: 14)),
        ],
      ),
    );
  }
}
