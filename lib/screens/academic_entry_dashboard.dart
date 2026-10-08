// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'nazira.dart';
import 'qaida.dart';
import 'hifz.dart';
import 'husn_saut.dart';
import 'academic_report.dart';
import 'reports_academic.dart';

class AcademicEntryDashboardPage extends StatefulWidget {
  const AcademicEntryDashboardPage({super.key});

  @override
  _AcademicEntryDashboardPageState createState() =>
      _AcademicEntryDashboardPageState();
}

class _AcademicEntryDashboardPageState
    extends State<AcademicEntryDashboardPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _classes = [];
  Map<String, int> _studentCountsByClass = {};
  bool _isLoading = true;

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
    final status = (student['status'] ?? '').toString().trim().toLowerCase();
    final isDeleted =
        int.tryParse(student['is_deleted']?.toString() ?? '0') ?? 0;
    return isDeleted == 0 &&
        (status == 'active' ||
            status == 'present' ||
            status == 'حاضر' ||
            status.isEmpty);
  }

  @override
  void initState() {
    super.initState();
    _loadClasses();
  }

  Future<void> _loadClasses() async {
    final db = DBHelper();
    final data = await db.getClassesForUser();
    final students = await db.getStudentsForUser();
    final counts = <String, int>{};
    for (final student in students) {
      if (!_isActiveStudent(student)) continue;
      final classKey = _normalizeClassName(
          student['class_name']?.toString().isNotEmpty == true
              ? student['class_name']
              : student['class']);
      if (classKey.isEmpty) continue;
      counts[classKey] = (counts[classKey] ?? 0) + 1;
    }
    setState(() {
      _classes = data;
      _studentCountsByClass = counts;
      _isLoading = false;
    });
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
                    isUrdu ? "تعلیمی کارکردگی (اندراج)" : "Academic Entry",
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, color: Colors.white)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.analytics, color: Colors.white),
                    tooltip: isUrdu ? "تعلیمی رپورٹس و جائزہ" : "Academic Reports",
                    onPressed: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => const ReportsAcademicPage()),
                      );
                    },
                  ),
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrdu
                              ? "ڈیٹا سنک ہو رہا ہے..."
                              : "Syncing data...")));
                      try {
                        await SyncService.runTwoWaySync();
                        if (mounted) {
                          _loadClasses();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سنک مکمل ہو گیا!"
                                  : "Sync complete!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(
                                  isUrdu ? "سنک میں خرابی آئی" : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
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
                            child: _buildClassesList(isUrdu),
                          ),
                        ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildClassesList(bool isUrdu) {
    List<Map<String, dynamic>> quranClasses = [];
    List<Map<String, dynamic>> otherClasses = [];

    for (var c in _classes) {
      String classType = c['class_type'] ?? '';
      if (classType == 'Hifz/Gardan/Husn' ||
          classType == 'Hifz/Nazira' ||
          classType == 'Nazira' ||
          classType == 'Qaida' ||
          classType == 'نورانی قاعدہ' ||
          c['name'].toString().contains('قاعدہ') ||
          classType == 'نماز حنفی' ||
          c['name'].toString().contains('نماز حنفی') ||
          classType == 'Tajweed') {
        quranClasses.add(c);
      } else {
        otherClasses.add(c);
      }
    }

    return ListView(
      padding: const EdgeInsets.all(15),
      children: [
        if (quranClasses.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Text(
              isUrdu ? "قرآن کریم" : "Quranic Education",
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: primaryGreen,
              ),
            ),
          ),
          ...quranClasses.map((c) => _buildClassCard(c, isUrdu)),
        ],
        if (otherClasses.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Text(
              isUrdu ? "دیگر تعلیمات" : "Other Education",
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: primaryGreen,
              ),
            ),
          ),
          ...otherClasses.map((c) => _buildClassCard(c, isUrdu)),
        ],
      ],
    );
  }

  Widget _buildClassCard(Map<String, dynamic> c, bool isUrdu) {
    final studentCount =
        _studentCountsByClass[_normalizeClassName(c['name'])] ?? 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        onTap: () {
          String classType = c['class_type'] ?? '';
          String className = c['name']?.toString() ?? '';
          if (classType == 'Qaida' || classType == 'نورانی قاعدہ' || className.contains('قاعدہ') || classType == 'نماز حنفی' || className.contains('نماز حنفی')) {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => QaidaEntryPage(selectedClass: c['name'])));
          } else if (classType == 'Nazira' || className.contains('ناظرہ')) {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => NaziraPage(className: c['name'])));
          } else if (className.contains('حسن') || className.contains('صوت') || className.toLowerCase().contains('husn') || className.toLowerCase().contains('saut') || classType == 'Husn Saut' || classType == 'Husn') {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => HusnSautPage(className: c['name'])));
          } else if (classType == 'Hifz/Gardan/Husn' ||
              classType == 'Hifz/Nazira' ||
              className.contains('حفظ')) {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => HifzPage(className: c['name'])));
          } else {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => AcademicReportPage(className: c['name'])));
          }
        },
        child: NeoBox(
          padding: 15,
          child: Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                    color: primaryGreen.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10)),
                child: Icon(Icons.class_, color: primaryGreen),
              ),
              const SizedBox(width: 15),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      c['name'] ?? '',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 18),
                    ),
                    if (c['class_type'] != null)
                      Text(
                        c['class_type'],
                        style: TextStyle(
                            color: Colors.grey.shade600, fontSize: 14),
                      ),
                  ],
                ),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(15),
                  border: Border.all(color: Colors.green.shade200),
                ),
                child: Text(
                  isUrdu ? "طلبہ: $studentCount" : "Students: $studentCount",
                  style: TextStyle(
                      color: Colors.green.shade800,
                      fontSize: 12,
                      fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
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
          Text(isUrdu ? "کوئی کلاس نہیں ملی" : "No classes found",
              style: TextStyle(color: Colors.grey.shade500, fontSize: 18)),
          const SizedBox(height: 10),
          Text(isUrdu ? "براہ کرم ڈیٹا سنک کریں" : "Please sync data",
              style: TextStyle(color: Colors.grey.shade400, fontSize: 14)),
        ],
      ),
    );
  }
}
