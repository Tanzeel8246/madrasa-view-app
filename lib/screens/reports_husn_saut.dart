// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import 'dart:ui' as ui;
import '../widgets/madrasa_card.dart';
import '../widgets/madrasa_inputs.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_ui_helper.dart';
import 'dashboard.dart';
import 'student_report.dart';

class ReportsHusnSautPage extends StatefulWidget {
  final String? className;
  const ReportsHusnSautPage({super.key, this.className});

  @override
  _ReportsHusnSautPageState createState() => _ReportsHusnSautPageState();
}

class _ReportsHusnSautPageState extends State<ReportsHusnSautPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryColor = const Color(0xFF6A1B9A);

  List<Map<String, dynamic>> _records = [];
  List<Map<String, dynamic>> _students = [];
  bool isLoading = true;

  String? fromDate;
  String? toDate;
  String? selectedStudentId;

  final _fromDateController = TextEditingController();
  final _toDateController = TextEditingController();

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    fromDate = intl.DateFormat('dd/MM/yyyy')
        .format(now.subtract(const Duration(days: 7)));
    toDate = intl.DateFormat('dd/MM/yyyy').format(now);
    _fromDateController.text = fromDate!;
    _toDateController.text = toDate!;
    _loadData();
  }

  DateTime _parseRobust(String dateStr) {
    if (dateStr.contains('-')) {
      try {
        return DateTime.parse(dateStr);
      } catch (_) {
        return DateTime.now();
      }
    }
    try {
      return intl.DateFormat('d/M/yyyy').parse(dateStr);
    } catch (_) {
      return DateTime.now();
    }
  }

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

  bool _isActiveStudent(Map<String, dynamic> s) {
    final value = s['is_active'] ?? s['active'];
    if (value is bool) return value;
    if (value is num) return value != 0;

    final status = value?.toString().trim().toLowerCase() ??
        s['status']?.toString().trim().toLowerCase();
    return status != 'inactive' && status != '0' && status != 'false';
  }

  Future<void> _loadData() async {
    setState(() => isLoading = true);
    try {
      final db = DBHelper();
      final allStudents = await db.getStudentsForUser(includeInactive: true);
      List<Map<String, dynamic>> extraMaktabStudents = [];
      try {
        extraMaktabStudents = await db.getMaktabStudentsForUser(includeInactive: true);
      } catch (_) {}
      final combinedStudents = [...allStudents, ...extraMaktabStudents];

      final validStudents = <Map<String, dynamic>>[];
      final Set<String> seenIds = {};

      for (var s in combinedStudents) {
        if (!_isActiveStudent(s)) continue;

        String cName = (s['class_name']?.toString() ?? s['class']?.toString() ?? '').toLowerCase();
        String normCName = _normalizeClassName(s['class_name'] ?? s['class']);

        bool isTarget = false;
        if (widget.className != null && widget.className!.trim().isNotEmpty) {
          String normTarget = _normalizeClassName(widget.className);
          isTarget = (normCName == normTarget ||
              cName == widget.className!.toLowerCase().trim() ||
              (s['class_name']?.toString().trim() == widget.className?.trim()) ||
              (s['class']?.toString().trim() == widget.className?.trim()));
        } else {
          isTarget = cName.contains('حسن') || cName.contains('صوت');
        }

        final idStr = s['id']?.toString();
        if (isTarget && idStr != null && !seenIds.contains(idStr)) {
          seenIds.add(idStr);
          validStudents.add(s);
        }
      }
      _students = validStudents;
      if (selectedStudentId != null && !seenIds.contains(selectedStudentId)) {
        selectedStudentId = null;
      }

      List<Map<String, dynamic>> allRecords = [];
      try {
        allRecords = await db.getHusnSautRecords();
      } catch (e) {
        debugPrint("Error fetching husn records in reports: $e");
      }
      List<Map<String, dynamic>> filtered = [];

      DateTime sDate = _parseRobust(fromDate!);
      DateTime eDate = _parseRobust(toDate!);

      for (var h in allRecords) {
        if (selectedStudentId != null &&
            h['student_id'].toString() != selectedStudentId) {
          continue;
        }
        if (selectedStudentId == null) {
          bool isStudentValid = _students
              .any((s) => s['id'].toString() == h['student_id'].toString());
          if (!isStudentValid) continue;
        }

        try {
          DateTime fDate = _parseRobust(h['date']?.toString() ?? '');
          if (fDate.isBefore(sDate.subtract(const Duration(days: 1))) ||
              fDate.isAfter(eDate.add(const Duration(days: 1)))) {
            continue;
          }
        } catch (_) {
          continue;
        }

        filtered.add(Map<String, dynamic>.from(h));
      }

      // If any active student has no record, add a placeholder record so they appear in the class list
      final existingStudentIds = filtered.map((r) => r['student_id']?.toString() ?? '').toSet();
      for (var s in _students) {
        String sid = s['id'].toString();
        if (selectedStudentId != null && sid != selectedStudentId) continue;
        if (!existingStudentIds.contains(sid)) {
          filtered.add({
            'student_id': s['id'],
            'date': toDate ?? intl.DateFormat('dd/MM/yyyy').format(DateTime.now()),
            'manzil_sabaq': '-',
            'manzil_listener': '-',
            'manzil_grade': '-',
            'mashq_1_sabaq': '-',
            'mashq_1_time': '-',
            'mashq_2_sabaq': '-',
            'mashq_2_time': '-',
            'mashq_3_sabaq': '-',
            'mashq_3_time': '-',
            'mashq_4_sabaq': '-',
            'mashq_4_time': '-',
            'tilawat_qty': '-',
            'tilawat_parahs': '-',
            'is_dummy': 1,
          });
        }
      }

      filtered.sort((a, b) {
        DateTime dA = _parseRobust(a['date'] ?? '');
        DateTime dB = _parseRobust(b['date'] ?? '');
        return dB.compareTo(dA);
      });

      _records = filtered;
    } catch (e) {
      debugPrint("Error in _loadData (Husn): $e");
    }
    if (mounted) setState(() => isLoading = false);
  }

  String _getStudentName(String id) {
    final s = _students.firstWhere((s) => s['id'].toString() == id,
        orElse: () => <String, dynamic>{});
    return s['name']?.toString() ?? 'نامعلوم';
  }

  Future<void> _pickDate(bool isFrom) async {
    DateTime initial = _parseRobust(isFrom ? fromDate! : toDate!);
    DateTime? picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked != null) {
      setState(() {
        String formatted = intl.DateFormat('dd/MM/yyyy').format(picked);
        if (isFrom) {
          fromDate = formatted;
          _fromDateController.text = formatted;
        } else {
          toDate = formatted;
          _toDateController.text = formatted;
        }
      });
      _loadData();
    }
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
            Navigator.pushReplacement(
                context, MaterialPageRoute(builder: (c) => DashboardPage()));
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
                      onPressed: () => Scaffold.of(context).openDrawer()),
                ),
                title: Text(isUrdu ? 'حسن صوت رپورٹ' : 'Husn-e-Saut Report',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                backgroundColor: primaryColor,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(
                              isUrdu ? "سنک ہو رہا ہے..." : "Syncing...")));
                      await SyncUIHelper.performManualSync(context);
                      _loadData();
                    },
                  ),
                ],
              ),
              body: Column(
                children: [
                  Container(
                    padding: const EdgeInsets.all(12),
                    color: Colors.white,
                    child: Column(
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: MadrasaTextField(
                                controller: _fromDateController,
                                readOnly: true,
                                decoration: InputDecoration(
                                    labelText: isUrdu ? 'سے (Date)' : 'From',
                                    border: const OutlineInputBorder(),
                                    suffixIcon: const Icon(Icons.calendar_today,
                                        size: 20)),
                                onTap: () => _pickDate(true),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: MadrasaTextField(
                                controller: _toDateController,
                                readOnly: true,
                                decoration: InputDecoration(
                                    labelText: isUrdu ? 'تک (Date)' : 'To',
                                    border: const OutlineInputBorder(),
                                    suffixIcon: const Icon(Icons.calendar_today,
                                        size: 20)),
                                onTap: () => _pickDate(false),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        DropdownButtonFormField<String>(
                          value: _students.any((s) => s['id'].toString() == selectedStudentId)
                              ? selectedStudentId
                              : null,
                          decoration: InputDecoration(
                            labelText: isUrdu
                                ? 'طالب علم منتخب کریں'
                                : 'Select Student',
                            border: const OutlineInputBorder(),
                          ),
                          items: [
                            DropdownMenuItem(
                                value: null,
                                child: Text(
                                    isUrdu ? '-- تمام طلباء (ڈیفالٹ) --' : '-- All Students (Default) --')),
                            ..._students.map((s) => DropdownMenuItem(
                                value: s['id'].toString(),
                                child: Text("${s['admission_number'] ?? s['roll_number'] ?? ''} - ${s['name']} (${s['father_name'] ?? ''})"))),
                          ],
                          onChanged: (v) {
                            setState(() => selectedStudentId = v);
                            _loadData();
                          },
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: isLoading
                        ? const Center(child: CircularProgressIndicator())
                        : selectedStudentId != null
                            ? StudentReportPage(
                                key: ValueKey(selectedStudentId),
                                forceStudentId: selectedStudentId,
                                targetClassType: 'Husn-e-Saut',
                                forceClassName: widget.className ?? 'حسن صوت',
                                isEmbedded: true,
                              )
                            : _records.isEmpty
                                ? Center(
                                    child: Text(isUrdu
                                        ? 'اس کلاس میں کوئی فعال طلبہ موجود نہیں ہیں'
                                        : 'No active students found in this class'))
                                : ListView.builder(
                                padding: const EdgeInsets.all(12),
                                itemCount: _records.length,
                                itemBuilder: (context, index) {
                                  final r = _records[index];
                                  final stuName = _getStudentName(
                                      r['student_id']?.toString() ?? '');
                                  return MadrasaCard(
                                    margin: const EdgeInsets.only(bottom: 10),
                                    child: Padding(
                                      padding: const EdgeInsets.all(12.0),
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            mainAxisAlignment:
                                                MainAxisAlignment.spaceBetween,
                                            children: [
                                              Expanded(
                                                  child: Text(stuName,
                                                      style: const TextStyle(
                                                          fontWeight:
                                                              FontWeight.bold,
                                                          fontSize: 16))),
                                              Text(r['date'] ?? '',
                                                  style: TextStyle(
                                                      color:
                                                          Colors.grey.shade600,
                                                      fontSize: 12)),
                                            ],
                                          ),
                                          const Divider(),
                                          if (r['manzil_sabaq'] != null &&
                                              r['manzil_sabaq']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "منزل:" : "Manzil:",
                                                "${r['manzil_sabaq']} (${r['manzil_listener'] ?? '-'}) - ${r['manzil_grade'] ?? '-'}"),
                                          if (r['mashq_1_sabaq'] != null &&
                                              r['mashq_1_sabaq']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "مشق 1:" : "Mashq 1:",
                                                "${r['mashq_1_sabaq']} (${r['mashq_1_time'] ?? '-'})"),
                                          if (r['mashq_2_sabaq'] != null &&
                                              r['mashq_2_sabaq']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "مشق 2:" : "Mashq 2:",
                                                "${r['mashq_2_sabaq']} (${r['mashq_2_time'] ?? '-'})"),
                                          if (r['mashq_3_sabaq'] != null &&
                                              r['mashq_3_sabaq']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "مشق 3:" : "Mashq 3:",
                                                "${r['mashq_3_sabaq']} (${r['mashq_3_time'] ?? '-'})"),
                                          if (r['mashq_4_sabaq'] != null &&
                                              r['mashq_4_sabaq']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "مشق 4:" : "Mashq 4:",
                                                "${r['mashq_4_sabaq']} (${r['mashq_4_time'] ?? '-'})"),
                                          if (r['tilawat_qty'] != null &&
                                              r['tilawat_qty']
                                                  .toString()
                                                  .isNotEmpty)
                                            _buildRow(
                                                isUrdu ? "تلاوت:" : "Tilawat:",
                                                "${r['tilawat_qty']} (${r['tilawat_parahs'] ?? '-'})"),
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
          ),
        );
      },
    );
  }

  Widget _buildRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
              width: 60,
              child: Text(label,
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12))),
          Expanded(
              child: Text(value,
                  style: const TextStyle(
                      fontWeight: FontWeight.w500, fontSize: 13))),
        ],
      ),
    );
  }
}
