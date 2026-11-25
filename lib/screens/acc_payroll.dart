// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import '../utils/sync_service.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:image_picker/image_picker.dart';
import 'dart:io';
import 'package:path_provider/path_provider.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class AccPayrollPage extends StatefulWidget {
  const AccPayrollPage({super.key});

  @override
  State<AccPayrollPage> createState() => _AccPayrollPageState();
}

class _AccPayrollPageState extends State<AccPayrollPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  List<Map<String, dynamic>> _payrolls = [];
  List<Map<String, dynamic>> _teachers = [];
  bool _isLoading = true;
  String? _selectedMonth;
  String? _selectedTeacherId;

  @override
  void initState() {
    super.initState();
    _loadPayrolls();
  }

  Future<void> _loadPayrolls() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final allPayrolls = await db.getAccPayroll();
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    List<Map<String, dynamic>> teachers = [];
    if (isMasjid) {
      teachers = await db.getMaktabTeachers();
    } else {
      teachers = await db.getTeachers();
    }

    final teacherMap = <String, Map<String, dynamic>>{};
    for (var t in teachers) {
      final tid = t['id']?.toString() ?? '';
      if (tid.isNotEmpty) teacherMap[tid] = t;
    }

    List<Map<String, dynamic>> enriched = [];
    for (var p in allPayrolls) {
      final pSys = p['system_type']?.toString();
      if (isMasjid && pSys != 'Masjid') continue;
      if (!isMasjid && pSys == 'Masjid') continue;
      final tid = p['teacher_id']?.toString() ?? '';
      final t = teacherMap[tid];
      final isOrphan = t == null;
      final tName = t != null
          ? (t['name']?.toString() ?? 'Staff')
          : (isUrduGlobal.value ? 'حذف شدہ استاد (ID #$tid)' : 'Deleted Staff (ID #$tid)');
      enriched.add({
        ...p,
        'teacher_name': tName,
        'is_orphan': isOrphan,
      });
    }

    setState(() {
      _payrolls = enriched;
      _teachers = teachers;
      _isLoading = false;
    });
  }

  List<String> _getAvailableMonths() {
    final months = <String>{};
    for (var p in _payrolls) {
      final m = p['month_year']?.toString();
      if (m != null && m.isNotEmpty) {
        months.add(m);
      }
    }
    final nowM = "${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}";
    months.add(nowM);
    final sorted = months.toList()..sort((a, b) => b.compareTo(a));
    return sorted;
  }

  String _formatUrduMonthYear(String? my) {
    if (my == null || my.trim().isEmpty) return '';
    final parts = my.trim().split('-');
    if (parts.length < 2) return my;
    final year = parts[0];
    final month = int.tryParse(parts[1]) ?? 0;
    const urduMonths = {
      1: 'جنوری',
      2: 'فروری',
      3: 'مارچ',
      4: 'اپریل',
      5: 'مئی',
      6: 'جون',
      7: 'جولائی',
      8: 'اگست',
      9: 'ستمبر',
      10: 'اکتوبر',
      11: 'نومبر',
      12: 'دسمبر',
    };
    final mName = urduMonths[month] ?? parts[1];
    return '$mName $year';
  }

  List<Map<String, String>> _getAvailableTeachers() {
    final Map<String, String> map = {};
    for (var t in _teachers) {
      final tid = t['id']?.toString() ?? '';
      final tname = t['name']?.toString() ?? '';
      if (tid.isNotEmpty && tname.isNotEmpty) {
        map[tid] = tname;
      }
    }
    final list = map.entries.map((e) => {'id': e.key, 'name': e.value}).toList();
    list.sort((a, b) => (a['name'] ?? '').compareTo(b['name'] ?? ''));

    final orphanCount = _payrolls.where((p) => p['is_orphan'] == true).length;
    if (orphanCount > 0) {
      list.add({
        'id': '__orphans__',
        'name': isUrduGlobal.value
            ? 'یتیم ریکارڈ — حذف شدہ اساتذہ ($orphanCount قطاریں)'
            : 'Orphan Records ($orphanCount rows)',
      });
    }
    return list;
  }

  Future<void> _generateBulkPayroll() async {
    await _showSelectivePayrollModal();
  }

  Future<void> _showSelectivePayrollModal() async {
    DateTime targetDate = DateTime.now();
    final monthController = TextEditingController(
        text:
            "${targetDate.year}-${targetDate.month.toString().padLeft(2, '0')}");

    final db = DBHelper();
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';
    final allTeachers =
        isMasjid ? await db.getMaktabTeachers() : await db.getTeachers();

    final Map<int, bool> selectedStaff = {};

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final month = monthController.text.trim();
            final pStart = "$month-01";
            final pEnd = "$month-31";

            final eligibleList = <Map<String, dynamic>>[];
            final alreadyGeneratedMap = <int, bool>{};

            for (var t in allTeachers) {
              if (t['is_deleted'] == 1) continue;
              if (t['payroll_excluded'] == 1) continue;

              final st =
                  (t['status'] ?? 'Active').toString().trim().toLowerCase();
              final isTerminal = st == 'inactive' ||
                  st.contains('غیر فعال') ||
                  st == 'terminated' ||
                  st.contains('برطرف') ||
                  st.contains('فارغ') ||
                  st == 'resigned' ||
                  st.contains('مستعفی') ||
                  st.contains('استعفی') ||
                  st.contains('علیحدہ') ||
                  st.contains('چھوڑ') ||
                  st == 'expelled' ||
                  st.contains('خارج') ||
                  st.contains('اخراج') ||
                  st.contains('معطل');

              final jDate = (t['joining_date'] ?? '').toString().trim();
              final rDate = (t['resignation_date'] ?? '').toString().trim();

              if (jDate.isNotEmpty && jDate.compareTo(pEnd) > 0) continue;
              if (rDate.isNotEmpty && rDate.compareTo(pStart) < 0) continue;
              if (isTerminal && rDate.isEmpty) continue;

              final tid = int.tryParse(t['id']?.toString() ?? '') ?? 0;
              if (tid == 0) continue;

              final hasExisting = _payrolls.any((p) {
                final pTid =
                    int.tryParse(p['teacher_id']?.toString() ?? '') ?? 0;
                final pMonth = (p['month_year'] ?? '').toString().trim();
                final isDel =
                    int.tryParse(p['is_deleted']?.toString() ?? '0') ?? 0;
                return pTid == tid && pMonth == month && isDel == 0;
              });

              if (hasExisting) {
                alreadyGeneratedMap[tid] = true;
              } else if (!selectedStaff.containsKey(tid)) {
                selectedStaff[tid] = true;
              }

              eligibleList.add(t);
            }

            int selectedCount = 0;
            double selectedTotalSalary = 0.0;
            for (var t in eligibleList) {
              final tid = int.tryParse(t['id']?.toString() ?? '') ?? 0;
              if (alreadyGeneratedMap[tid] != true &&
                  selectedStaff[tid] == true) {
                selectedCount++;
                selectedTotalSalary +=
                    double.tryParse(t['salary']?.toString() ?? '0') ?? 0.0;
              }
            }

            return Container(
              height: MediaQuery.of(context).size.height * 0.88,
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
              ),
              child: Column(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 16, vertical: 14),
                    decoration: BoxDecoration(
                      color: primaryGreen,
                      borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(20)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.auto_awesome, color: Colors.white),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            isUrduGlobal.value
                                ? 'ماہانہ وظائف کی تیاری (انتخاب اساتذہ)'
                                : 'Generate Staff Stipends',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close, color: Colors.white),
                          onPressed: () => Navigator.pop(context),
                        ),
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 16, vertical: 10),
                    child: Row(
                      children: [
                        Expanded(
                          flex: 3,
                          child: TextField(
                            controller: monthController,
                            decoration: InputDecoration(
                              labelText: isUrduGlobal.value
                                  ? 'ماہ و سال (YYYY-MM)'
                                  : 'Month (YYYY-MM)',
                              prefixIcon: const Icon(Icons.calendar_month),
                              border: const OutlineInputBorder(),
                              isDense: true,
                            ),
                            onChanged: (_) => setModalState(() {}),
                          ),
                        ),
                        const SizedBox(width: 8),
                        OutlinedButton(
                          onPressed: () {
                            final prev = DateTime(
                                targetDate.year, targetDate.month - 1, 1);
                            targetDate = prev;
                            monthController.text =
                                "${prev.year}-${prev.month.toString().padLeft(2, '0')}";
                            setModalState(() {});
                          },
                          child: Text(isUrduGlobal.value ? 'پچھلا' : 'Prev'),
                        ),
                        const SizedBox(width: 4),
                        OutlinedButton(
                          onPressed: () {
                            final now = DateTime.now();
                            targetDate = now;
                            monthController.text =
                                "${now.year}-${now.month.toString().padLeft(2, '0')}";
                            setModalState(() {});
                          },
                          child:
                              Text(isUrduGlobal.value ? 'رواں' : 'Current'),
                        ),
                      ],
                    ),
                  ),
                  Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                    child: Row(
                      children: [
                        ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.teal.shade700,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 6),
                          ),
                          icon: const Icon(Icons.select_all, size: 16),
                          label: Text(
                              isUrduGlobal.value ? 'سب منتخب' : 'Select All'),
                          onPressed: () {
                            setModalState(() {
                              for (var t in eligibleList) {
                                final tid =
                                    int.tryParse(t['id']?.toString() ?? '') ??
                                        0;
                                if (alreadyGeneratedMap[tid] != true) {
                                  selectedStaff[tid] = true;
                                }
                              }
                            });
                          },
                        ),
                        const SizedBox(width: 8),
                        OutlinedButton.icon(
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 6),
                          ),
                          icon: const Icon(Icons.deselect, size: 16),
                          label: Text(
                              isUrduGlobal.value ? 'سب خالی' : 'Clear All'),
                          onPressed: () {
                            setModalState(() {
                              for (var t in eligibleList) {
                                final tid =
                                    int.tryParse(t['id']?.toString() ?? '') ??
                                        0;
                                selectedStaff[tid] = false;
                              }
                            });
                          },
                        ),
                        const Spacer(),
                        Text(
                          isUrduGlobal.value
                              ? 'اہل عملہ: ${eligibleList.length}'
                              : 'Eligible: ${eligibleList.length}',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                              color: Colors.grey),
                        ),
                      ],
                    ),
                  ),
                  const Divider(),
                  Expanded(
                    child: ListView.builder(
                      itemCount: eligibleList.length,
                      itemBuilder: (context, idx) {
                        final t = eligibleList[idx];
                        final tid =
                            int.tryParse(t['id']?.toString() ?? '') ?? 0;
                        final tName = (t['name'] ?? 'Staff').toString();
                        final fName = (t['father_name'] ?? '').toString();
                        final salary =
                            double.tryParse(t['salary']?.toString() ?? '0') ??
                                0.0;
                        final isAlready = alreadyGeneratedMap[tid] == true;
                        final isChecked =
                            isAlready || (selectedStaff[tid] == true);

                        return Container(
                          decoration: BoxDecoration(
                            border: Border(
                                bottom:
                                    BorderSide(color: Colors.grey.shade200)),
                            color:
                                isAlready ? Colors.grey.shade50 : Colors.white,
                          ),
                          child: CheckboxListTile(
                            value: isChecked,
                            enabled: !isAlready,
                            activeColor: isAlready ? Colors.grey : primaryGreen,
                            title: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        tName,
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 14,
                                          color: isAlready
                                              ? Colors.grey.shade700
                                              : Colors.black,
                                        ),
                                      ),
                                      if (fName.isNotEmpty)
                                        Text('ولد $fName',
                                            style: const TextStyle(
                                                fontSize: 11,
                                                color: Colors.grey)),
                                    ],
                                  ),
                                ),
                                if (isAlready)
                                  Container(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 8, vertical: 3),
                                    decoration: BoxDecoration(
                                      color: Colors.amber.shade100,
                                      borderRadius: BorderRadius.circular(4),
                                      border: Border.all(
                                          color: Colors.amber.shade700,
                                          width: 0.8),
                                    ),
                                    child: Text(
                                      isUrduGlobal.value
                                          ? 'اس مہینے کا پے رول بن چکا ہے'
                                          : 'Already Generated',
                                      style: TextStyle(
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        color: Colors.amber.shade900,
                                      ),
                                    ),
                                  )
                                else
                                  Text(
                                    'Rs. ${intl.NumberFormat('#,##0').format(salary)}',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13,
                                      color: Colors.green.shade800,
                                    ),
                                  ),
                              ],
                            ),
                            onChanged: isAlready
                                ? null
                                : (bool? val) {
                                    setModalState(() {
                                      selectedStaff[tid] = val ?? false;
                                    });
                                  },
                          ),
                        );
                      },
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      boxShadow: [
                        BoxShadow(
                            color: Colors.black.withOpacity(0.08),
                            blurRadius: 8,
                            offset: const Offset(0, -2))
                      ],
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                isUrduGlobal.value
                                    ? 'منتخب اساتذہ: $selectedCount'
                                    : 'Selected: $selectedCount',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              Text(
                                'مجموعہ: Rs. ${intl.NumberFormat('#,##0').format(selectedTotalSalary)}',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: Colors.green.shade800,
                                    fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                        ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                                horizontal: 20, vertical: 12),
                          ),
                          icon: const Icon(Icons.bolt, size: 18),
                          label: Text(
                            isUrduGlobal.value
                                ? 'پے رول جنریٹ کریں'
                                : 'Generate Selected',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                          onPressed: selectedCount == 0
                              ? null
                              : () async {
                                  Navigator.pop(context);
                                  await _executeSelectiveGeneration(
                                    month: month,
                                    eligibleList: eligibleList,
                                    selectedStaff: selectedStaff,
                                    alreadyGeneratedMap: alreadyGeneratedMap,
                                  );
                                },
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  Future<void> _executeSelectiveGeneration({
    required String month,
    required List<Map<String, dynamic>> eligibleList,
    required Map<int, bool> selectedStaff,
    required Map<int, bool> alreadyGeneratedMap,
  }) async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final sys = SystemTypeGlobal.currentSystemType.value;
    final database = await db.database;

    final pStart = "$month-01";
    final pEnd = "$month-31";

    final generatedList = <Map<String, dynamic>>[];
    final skippedAlreadyList = <Map<String, dynamic>>[];
    final unselectedList = <Map<String, dynamic>>[];

    for (var t in eligibleList) {
      final tid = int.tryParse(t['id']?.toString() ?? '') ?? 0;
      final tName = (t['name'] ?? 'Staff').toString();
      final fullSalary = double.tryParse(t['salary']?.toString() ?? '0') ?? 0.0;
      final houseRent = double.tryParse(t['house_rent']?.toString() ?? '0') ?? 0.0;
      final medical = double.tryParse(t['medical_allowance']?.toString() ?? '0') ?? 0.0;
      final travel = double.tryParse(t['travel_allowance']?.toString() ?? '0') ?? 0.0;
      final fullAllowances = houseRent + medical + travel;

      if (alreadyGeneratedMap[tid] == true) {
        skippedAlreadyList.add({'name': tName, 'salary': fullSalary});
        continue;
      }

      if (selectedStaff[tid] != true) {
        unselectedList.add({'name': tName, 'salary': fullSalary});
        continue;
      }

      final jDate = (t['joining_date'] ?? '').toString().trim();
      final rDate = (t['resignation_date'] ?? '').toString().trim();

      double salary = fullSalary;
      double allowances = fullAllowances;

      final bool isMidJoin = jDate.isNotEmpty && jDate.compareTo(pStart) > 0;
      final bool isMidResign = rDate.isNotEmpty && rDate.compareTo(pEnd) < 0;

      if (isMidJoin || isMidResign) {
        try {
          final effStart =
              isMidJoin ? DateTime.parse(jDate) : DateTime.parse(pStart);
          final effEnd =
              isMidResign ? DateTime.parse(rDate) : DateTime.parse(pEnd);
          int days = effEnd.difference(effStart).inDays + 1;
          if (days < 0) days = 0;
          if (days > 30) days = 30;

          salary = double.parse(((fullSalary / 30) * days).toStringAsFixed(2));
          allowances =
              double.parse(((fullAllowances / 30) * days).toStringAsFixed(2));
        } catch (_) {}
      }

      int absentDays = 0;
      try {
        final absRes = await database.rawQuery(
          "SELECT COUNT(*) as count FROM attendance_teachers WHERE teacher_id = ? AND date LIKE ? AND (status = 'A' OR status = 'غیر حاضر')",
          [tid, '$month%'],
        );
        absentDays = (absRes.first['count'] as num?)?.toInt() ?? 0;
      } catch (_) {}

      double deductionRate =
          double.tryParse(t['daily_deduction_rate']?.toString() ?? '0') ?? 0.0;
      if (deductionRate == 0 && fullSalary > 0) {
        deductionRate = double.parse((fullSalary / 30).toStringAsFixed(2));
      }
      final double deductions = absentDays * deductionRate;
      final double netSalary =
          (salary + allowances - deductions).clamp(0.0, double.infinity);

      await db.insertAccPayroll({
        'teacher_id': tid,
        'month_year': month,
        'basic_salary': salary,
        'allowances': allowances,
        'deductions': deductions,
        'net_salary': netSalary,
        'status': 'Unpaid',
        'system_type': sys,
        'created_by': currentUserId.value ?? 1,
      });

      generatedList.add({
        'name': tName,
        'salary': salary,
        'net': netSalary,
      });
    }

    await _loadPayrolls();
    setState(() => _selectedMonth = month);
    SyncService.runTwoWaySync().catchError((_) {});

    if (mounted) {
      _showGenerationResultDialog(
        month: month,
        generatedList: generatedList,
        skippedAlreadyList: skippedAlreadyList,
        unselectedList: unselectedList,
      );
    }
  }

  void _showGenerationResultDialog({
    required String month,
    required List<Map<String, dynamic>> generatedList,
    required List<Map<String, dynamic>> skippedAlreadyList,
    required List<Map<String, dynamic>> unselectedList,
  }) {
    showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              const Icon(Icons.check_circle, color: Colors.green, size: 24),
              const SizedBox(width: 8),
              Text(
                isUrduGlobal.value
                    ? 'پے رول جنریشن کا خلاصہ'
                    : 'Payroll Generation Summary',
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'ماہ: $month',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, color: Colors.grey),
                ),
                const SizedBox(height: 12),
                _buildResultSection(
                  title: 'کامیابی سے تیار شدہ (${generatedList.length})',
                  color: Colors.green.shade800,
                  bgColor: Colors.green.shade50,
                  items: generatedList,
                ),
                const SizedBox(height: 10),
                if (skippedAlreadyList.isNotEmpty) ...[
                  _buildResultSection(
                    title:
                        'پہلے سے موجود / نظر انداز (${skippedAlreadyList.length})',
                    color: Colors.amber.shade900,
                    bgColor: Colors.amber.shade50,
                    items: skippedAlreadyList,
                  ),
                  const SizedBox(height: 10),
                ],
                if (unselectedList.isNotEmpty)
                  _buildResultSection(
                    title: 'غیر منتخب (${unselectedList.length})',
                    color: Colors.grey.shade700,
                    bgColor: Colors.grey.shade100,
                    items: unselectedList,
                  ),
              ],
            ),
          ),
          actions: [
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
              onPressed: () => Navigator.pop(context),
              child: Text(isUrduGlobal.value ? 'ٹھیک ہے' : 'OK'),
            ),
          ],
        );
      },
    );
  }

  Widget _buildResultSection({
    required String title,
    required Color color,
    required Color bgColor,
    required List<Map<String, dynamic>> items,
  }) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withOpacity(0.4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title,
              style: TextStyle(
                  fontWeight: FontWeight.bold, color: color, fontSize: 13)),
          const SizedBox(height: 6),
          ...items.map((it) {
            final net = it['net'] != null
                ? ' (Rs. ${intl.NumberFormat('#,##0').format(it['net'])})'
                : '';
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: [
                  Icon(Icons.arrow_right, size: 16, color: color),
                  Text('${it['name']}$net',
                      style: TextStyle(fontSize: 12, color: color)),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  Future<void> _editAllowances(Map<String, dynamic> p) async {
    final allowController =
        TextEditingController(text: p['allowances'].toString());
    final deductController =
        TextEditingController(text: p['deductions'].toString());

    final result = await showDialog<bool>(
        context: context,
        builder: (context) {
          return AlertDialog(
            title: Text(isUrduGlobal.value
                ? 'ترمیم کریں'
                : 'Edit Allowances/Deductions'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: allowController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? 'الاؤنسز / اضافی رقم'
                        : 'Allowances / Bonus',
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: deductController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value ? 'کٹوتی' : 'Deductions',
                    border: const OutlineInputBorder(),
                  ),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: Text(isUrduGlobal.value ? 'منسوخ کریں' : 'Cancel'),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                    backgroundColor: primaryGreen,
                    foregroundColor: Colors.white),
                onPressed: () => Navigator.pop(context, true),
                child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save'),
              ),
            ],
          );
        });

    if (result == true) {
      final allow = double.tryParse(allowController.text) ?? 0.0;
      final deduct = double.tryParse(deductController.text) ?? 0.0;
      final basic = double.tryParse(p['basic_salary'].toString()) ?? 0.0;
      final net = basic + allow - deduct;

      await DBHelper().updateAccPayroll(p['id'], {
        'allowances': allow,
        'deductions': deduct,
        'net_salary': net,
      });
      await _loadPayrolls();
      SyncService.runTwoWaySync().catchError((_) {});
    }
  }

  Future<void> _markPaid(dynamic payrollOrId) async {
    Map<String, dynamic> payrollItem = {};
    if (payrollOrId is Map<String, dynamic>) {
      payrollItem = payrollOrId;
    } else {
      final pid = int.tryParse(payrollOrId?.toString() ?? '');
      payrollItem = _payrolls.firstWhere(
        (x) => int.tryParse(x['id']?.toString() ?? '') == pid,
        orElse: () => {},
      );
    }
    if (payrollItem.isEmpty) return;
    final id = int.tryParse(payrollItem['id']?.toString() ?? '');
    if (id == null) return;

    final tName = payrollItem['teacher_name'] ?? 'Staff';
    final mYear = payrollItem['month_year'] ?? '';
    final netSalary =
        double.tryParse(payrollItem['net_salary']?.toString() ?? '0') ?? 0.0;

    List<Map<String, dynamic>> assetAccounts = [];
    try {
      final allAccounts = await DBHelper().getAccChartAccounts();
      assetAccounts = allAccounts
          .where((a) =>
              (a['type'] ?? '').toString().toLowerCase() == 'asset' &&
              (a['is_deleted'] ?? 0) == 0)
          .toList();
    } catch (_) {}

    int? selectedAccountId;
    if (assetAccounts.isNotEmpty) {
      selectedAccountId = int.tryParse(assetAccounts.first['id']?.toString() ?? '');
    }
    String selectedMethod = 'Cash';
    final isUrdu = isUrduGlobal.value;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDlgState) {
            return AlertDialog(
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(15)),
              title: Row(
                children: [
                  const Icon(Icons.payment, color: Colors.green),
                  const SizedBox(width: 8),
                  Text(isUrdu ? 'وظیفہ / تنخواہ کی ادائیگی' : 'Pay Salary / Stipend',
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                ],
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.green.shade50,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: Colors.green.shade200),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '$tName (${_formatUrduMonthYear(mYear)})',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 15),
                          ),
                          const SizedBox(height: 5),
                          Text(
                            '${isUrdu ? "قابل ادا رقم" : "Payable Amount"}: Rs. ${netSalary.toStringAsFixed(0)}',
                            style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: Colors.green.shade900,
                                fontSize: 16),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      isUrdu
                          ? 'ادائیگی کا کھاتہ (کہاں سے ادا ہوئی؟)'
                          : 'Payment Source Account',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                    const SizedBox(height: 6),
                    if (assetAccounts.isNotEmpty)
                      DropdownButtonFormField<int>(
                        value: selectedAccountId,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          border: OutlineInputBorder(),
                          contentPadding:
                              EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                        ),
                        items: assetAccounts.map((a) {
                          final accId = int.tryParse(a['id']?.toString() ?? '');
                          return DropdownMenuItem<int>(
                            value: accId,
                            child: Text(
                              "${a['code'] ?? ''} - ${a['name'] ?? ''}",
                              overflow: TextOverflow.ellipsis,
                            ),
                          );
                        }).toList(),
                        onChanged: (val) =>
                            setDlgState(() => selectedAccountId = val),
                      )
                    else
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                            color: Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(6)),
                        child: Text(
                          isUrdu
                              ? 'کیش ان ہینڈ (1001 Cash in Hand)'
                              : 'Cash in Hand (1001)',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                    const SizedBox(height: 14),
                    Text(
                      isUrdu ? 'طریقہ ادائیگی' : 'Payment Method',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                    const SizedBox(height: 6),
                    DropdownButtonFormField<String>(
                      value: selectedMethod,
                      decoration: const InputDecoration(
                        border: OutlineInputBorder(),
                        contentPadding:
                            EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      ),
                      items: [
                        DropdownMenuItem(
                            value: 'Cash',
                            child: Text(
                                isUrdu ? 'نقد رقم (Cash)' : 'Cash')),
                        DropdownMenuItem(
                            value: 'Bank Transfer',
                            child: Text(isUrdu
                                ? 'آن لائن بینک ٹرانسفر (Online Transfer)'
                                : 'Bank Transfer')),
                        DropdownMenuItem(
                            value: 'Cheque',
                            child: Text(
                                isUrdu ? 'چیک (Cheque)' : 'Cheque')),
                      ],
                      onChanged: (val) {
                        if (val != null) {
                          setDlgState(() => selectedMethod = val);
                        }
                      },
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: Text(isUrdu ? 'کینسل' : 'Cancel'),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.green,
                      foregroundColor: Colors.white),
                  onPressed: () => Navigator.pop(ctx, true),
                  child: Text(isUrdu ? 'ادائیگی مکمل کریں' : 'Confirm Payment'),
                ),
              ],
            );
          },
        );
      },
    );

    if (confirmed != true) return;

    try {
      final date =
          "${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}-${DateTime.now().day.toString().padLeft(2, '0')}";

      await DBHelper().updateAccPayroll(id, {
        'status': 'Paid',
        'payment_date': date,
        'payment_account_id': selectedAccountId,
        'payment_method': selectedMethod,
      });

      // Auto-record into Finance Expense (Madrasa vs Masjid)
      final sys = payrollItem['system_type']?.toString().isNotEmpty == true
          ? payrollItem['system_type'].toString()
          : SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sys == 'Masjid';

      final financeEntry = {
        'user_id': currentUserId.value ?? 1,
        'user_name': currentUserName.value ?? currentUserRole.value ?? 'Admin',
        'type': 'Expense',
        'head_id': 0,
        'head_name': isUrduGlobal.value ? 'وظائف عملہ' : 'Staff Stipends',
        'account_id': selectedAccountId,
        'payment_method': selectedMethod,
        'amount': netSalary,
        'date': date,
        'donor_name': tName,
        'donor_address': '',
        'spender_name': tName,
        'manual_receipt_no': '',
        'description':
            "مد: وظائف عملہ | تفصیل: وظیفہ/تنخواہ ادا کی گئی برائے ماہ $mYear: $tName ($selectedMethod)",
        'system_type': sys,
        'is_deleted': 0,
        'is_synced': 0,
      };

      try {
        if (isMasjid) {
          await DBHelper().insertMasjidFinance(financeEntry);
        } else {
          await DBHelper().insertFinance(financeEntry);
        }
      } catch (e) {
        debugPrint("Error auto-inserting finance expense for payroll: $e");
      }

      await _loadPayrolls();
      SyncService.runTwoWaySync().catchError((_) {});

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(isUrdu
                ? 'وظیفہ/تنخواہ کی ادائیگی کامیابی سے درج ہو گئی۔'
                : 'Stipend payment recorded successfully.'),
            backgroundColor: Colors.green,
          ),
        );
      }
    } catch (e) {
      debugPrint("Error marking payroll paid: $e");
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(isUrdu
                ? 'ادائیگی درج کرنے میں خرابی پیش آئی: $e'
                : 'Error recording payment: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  Future<void> _markUnpaid(dynamic payrollOrId) async {
    Map<String, dynamic> payrollItem = {};
    if (payrollOrId is Map<String, dynamic>) {
      payrollItem = payrollOrId;
    } else {
      final pid = int.tryParse(payrollOrId?.toString() ?? '');
      payrollItem = _payrolls.firstWhere(
        (x) => int.tryParse(x['id']?.toString() ?? '') == pid,
        orElse: () => {},
      );
    }
    final id = int.tryParse(payrollItem['id']?.toString() ?? '');
    if (id == null) return;

    await DBHelper()
        .updateAccPayroll(id, {'status': 'Unpaid', 'payment_date': null});

    // Soft-delete corresponding finance entry if present
    try {
      final tName = (payrollItem['teacher_name'] ?? '').toString();
      final mYear = (payrollItem['month_year'] ?? '').toString();
      final sys = payrollItem['system_type']?.toString().isNotEmpty == true
          ? payrollItem['system_type'].toString()
          : SystemTypeGlobal.currentSystemType.value;
      final table = sys == 'Masjid' ? 'masjid_finance' : 'finance';
      final dbInst = await DBHelper().database;
      await dbInst.rawUpdate(
        "UPDATE $table SET is_deleted = 1, is_synced = 0 WHERE type = 'Expense' AND description LIKE ?",
        ["%ماہ $mYear: $tName%"],
      );
    } catch (e) {
      debugPrint("Error soft-deleting finance entry on unpay: $e");
    }

    await _loadPayrolls();
    SyncService.runTwoWaySync().catchError((_) {});
  }

  Future<void> _deletePayroll(dynamic payrollOrId) async {
    Map<String, dynamic> payrollItem = {};
    if (payrollOrId is Map<String, dynamic>) {
      payrollItem = payrollOrId;
    } else {
      final pid = int.tryParse(payrollOrId?.toString() ?? '');
      payrollItem = _payrolls.firstWhere(
        (x) => int.tryParse(x['id']?.toString() ?? '') == pid,
        orElse: () => {},
      );
    }
    final id = int.tryParse(payrollItem['id']?.toString() ?? '');
    if (id == null) return;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'حذف کی تصدیق' : 'Confirm Delete'),
        content: Text(isUrduGlobal.value 
            ? 'کیا آپ واقعی اس پے رول ریکارڈ کو حذف کرنا چاہتے ہیں؟' 
            : 'Are you sure you want to delete this payroll record?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(isUrduGlobal.value ? 'نہیں' : 'No'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isUrduGlobal.value ? 'ہاں، حذف کریں' : 'Yes, Delete'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await DBHelper().deleteAccPayroll(id);

      // Soft-delete corresponding finance entry if present
      try {
        final tName = (payrollItem['teacher_name'] ?? '').toString();
        final mYear = (payrollItem['month_year'] ?? '').toString();
        final sys = payrollItem['system_type']?.toString().isNotEmpty == true
            ? payrollItem['system_type'].toString()
            : SystemTypeGlobal.currentSystemType.value;
        final table = sys == 'Masjid' ? 'masjid_finance' : 'finance';
        final dbInst = await DBHelper().database;
        await dbInst.rawUpdate(
          "UPDATE $table SET is_deleted = 1, is_synced = 0 WHERE type = 'Expense' AND description LIKE ?",
          ["%ماہ $mYear: $tName%"],
        );
      } catch (e) {
        debugPrint("Error soft-deleting finance entry on payroll delete: $e");
      }

      await _loadPayrolls();
      SyncService.runTwoWaySync().catchError((_) {});
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(isUrduGlobal.value ? 'ریکارڈ حذف کر دیا گیا' : 'Record deleted')));
      }
    }
  }

  void _showReceipt(BuildContext context, Map<String, dynamic> p) {
    final ScreenshotController screenshotController = ScreenshotController();
    String? signaturePath;

    showDialog(
      context: context,
      builder: (c) {
        return StatefulBuilder(
          builder: (context, setStateDialog) {
            return AlertDialog(
              contentPadding: const EdgeInsets.all(0),
              title: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(isUrduGlobal.value ? 'پے سلپ' : 'Pay Slip'),
                  TextButton.icon(
                    icon: const Icon(Icons.draw, size: 16),
                    label: Text(isUrduGlobal.value ? 'دستخط' : 'Signature'),
                    onPressed: () async {
                      final picker = ImagePicker();
                      final picked = await picker.pickImage(
                          source: ImageSource.gallery, imageQuality: 70);
                      if (picked != null) {
                        setStateDialog(() => signaturePath = picked.path);
                      }
                    },
                  ),
                ],
              ),
              content: SingleChildScrollView(
                child: Screenshot(
                  controller: screenshotController,
                  child: Container(
                    width: 380,
                    padding: const EdgeInsets.all(20),
                    color: Colors.white,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Logo + Header
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Image.asset('assets/logo.png',
                                width: 50,
                                height: 50,
                                errorBuilder: (_, __, ___) => const Icon(
                                    Icons.school,
                                    size: 50,
                                    color: Color(0xFF284D3D))),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                      (currentTenantName.value ?? '').trim().isNotEmpty
                                          ? currentTenantName.value!.trim()
                                          : (isUrduGlobal.value
                                              ? 'مدرسہ مینجمنٹ سسٹم'
                                              : 'Madrasa Management System'),
                                      style: const TextStyle(
                                          fontSize: 18,
                                          fontWeight: FontWeight.bold,
                                          fontFamily: 'JameelNooriNastaleeq')),
                                  Text(
                                      SystemTypeGlobal.currentSystemType.value == 'Masjid'
                                          ? 'شعبہ مسجد و مکتب'
                                          : 'پے رول و وظائف نظام',
                                      style: const TextStyle(
                                          fontSize: 11,
                                          color: Colors.grey,
                                          fontFamily: 'JameelNooriNastaleeq')),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          color: const Color(0xFF284D3D),
                          child: const Text("پے سلپ (Pay Slip)",
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  fontFamily: 'JameelNooriNastaleeq')),
                        ),
                        const SizedBox(height: 8),
                        _receiptRow("استاد کا نام", "${p['teacher_name']}"),
                        _receiptRow("مہینہ", _formatUrduMonthYear(p['month_year']?.toString())),
                        const Divider(),
                        _receiptRow("بنیادی تنخواہ", "Rs ${p['basic_salary']}"),
                        _receiptRow(
                            "الاؤنسز / اضافی", "+ Rs ${p['allowances']}"),
                        _receiptRow("کٹوتیاں", "- Rs ${p['deductions']}"),
                        const Divider(thickness: 2),
                        _receiptRow("خالص تنخواہ", "Rs ${p['net_salary']}",
                            bold: true),
                        _receiptRow(
                            "سٹیٹس",
                            p['status'] == 'Paid'
                                ? 'ادا شدہ ✓'
                                : 'غیر ادا شدہ'),
                        if (p['payment_date'] != null &&
                            p['payment_date'].toString().isNotEmpty)
                          _receiptRow(
                              "ادائیگی کی تاریخ", p['payment_date'].toString()),
                        const SizedBox(height: 16),
                        const Divider(thickness: 1),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text("دستخط / مہر:",
                                style: TextStyle(
                                    fontSize: 12,
                                    fontFamily: 'JameelNooriNastaleeq')),
                            if (signaturePath != null &&
                                File(signaturePath!).existsSync())
                              Image.file(File(signaturePath!),
                                  height: 50, width: 100, fit: BoxFit.contain)
                            else
                              const Text("_____________________",
                                  style: TextStyle(fontSize: 12)),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                            "تاریخ اجراء: ${DateTime.now().toString().split(' ')[0]}",
                            style: const TextStyle(
                                fontSize: 10, color: Colors.grey)),
                      ],
                    ),
                  ),
                ),
              ),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(c),
                    child: const Text("بند کریں")),
                ElevatedButton.icon(
                  icon: const Icon(Icons.share),
                  label: Text(isUrduGlobal.value ? "شیئر کریں" : "Share"),
                  style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      foregroundColor: Colors.white),
                  onPressed: () async {
                    final image = await screenshotController.capture();
                    if (image != null) {
                      final directory =
                          await getApplicationDocumentsDirectory();
                      final imagePath =
                          await File('${directory.path}/payslip_${p['id']}.png')
                              .create();
                      await imagePath.writeAsBytes(image);
                      await Share.shareXFiles([XFile(imagePath.path)],
                          text: 'پے سلپ - ${p['teacher_name']}');
                    }
                  },
                ),
              ],
            );
          },
        );
      },
    );
  }

  Widget _receiptRow(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: const TextStyle(
                  fontSize: 13, fontFamily: 'JameelNooriNastaleeq')),
          Text(value,
              style: TextStyle(
                fontSize: 13,
                fontWeight: bold ? FontWeight.bold : FontWeight.normal,
                fontFamily: 'JameelNooriNastaleeq',
              )),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final availableMonths = _getAvailableMonths();
    final availableTeachers = _getAvailableTeachers();
    final filteredPayrolls = _payrolls.where((p) {
      final matchMonth = _selectedMonth == null ||
          _selectedMonth!.isEmpty ||
          p['month_year'] == _selectedMonth;
      final matchTeacher = _selectedTeacherId == null ||
          _selectedTeacherId!.isEmpty
          ? true
          : (_selectedTeacherId == '__orphans__'
              ? p['is_orphan'] == true
              : p['teacher_id']?.toString() == _selectedTeacherId);
      return matchMonth && matchTeacher;
    }).toList();

    double totalNet = 0;
    double totalPaid = 0;
    double totalUnpaid = 0;
    int paidCount = 0;
    int unpaidCount = 0;
    int regCount = 0;
    double regNet = 0;
    int orphanCount = 0;
    double orphanNet = 0;

    for (var p in filteredPayrolls) {
      double net = double.tryParse(p['net_salary']?.toString() ?? '0') ?? 0;
      totalNet += net;
      if (p['is_orphan'] == true) {
        orphanCount++;
        orphanNet += net;
      } else {
        regCount++;
        regNet += net;
      }
      if (p['status'] == 'Paid') {
        totalPaid += net;
        paidCount++;
      } else {
        totalUnpaid += net;
        unpaidCount++;
      }
    }

    return Scaffold(
      appBar: AppBar(
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            tooltip: 'Sync Data',
            onPressed: () async {
              ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Syncing data...')));
              try {
                await SyncService.runTwoWaySync();
                await _loadPayrolls();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Sync complete')));
                }
              } catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context)
                      .showSnackBar(const SnackBar(content: Text('Sync failed')));
                }
              }
            },
          )
        ],
        title: Text(isUrduGlobal.value ? 'وظائف عملہ' : 'Staff Stipends'),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      drawer: const AppDrawer(),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Top Action & Filter Bar (Month & Staff)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  color: Colors.grey.shade100,
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: primaryGreen,
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 10),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                              icon: const Icon(Icons.auto_awesome, size: 16),
                              label: Text(
                                isUrduGlobal.value
                                    ? 'نیا پے رول بنائیں'
                                    : 'Generate Payroll',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              onPressed: _generateBulkPayroll,
                            ),
                          ),
                          if (_selectedMonth != null || _selectedTeacherId != null) ...[
                            const SizedBox(width: 8),
                            OutlinedButton.icon(
                              style: OutlinedButton.styleFrom(
                                foregroundColor: Colors.red.shade700,
                                side: BorderSide(color: Colors.red.shade300),
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                              icon: const Icon(Icons.clear_all, size: 16),
                              label: Text(isUrduGlobal.value ? 'ری سیٹ' : 'Reset'),
                              onPressed: () {
                                setState(() {
                                  _selectedMonth = null;
                                  _selectedTeacherId = null;
                                });
                              },
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          // Month Filter Dropdown
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8),
                              decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: Colors.grey.shade400),
                              ),
                              child: DropdownButtonHideUnderline(
                                child: DropdownButton<String?>(
                                  isExpanded: true,
                                  value: _selectedMonth,
                                  hint: Text(
                                    isUrduGlobal.value ? 'تمام مہینے' : 'All Months',
                                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                  ),
                                  items: [
                                    DropdownMenuItem<String?>(
                                      value: null,
                                      child: Text(
                                        isUrduGlobal.value ? 'تمام مہینے' : 'All Months',
                                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                      ),
                                    ),
                                    ...availableMonths.map((m) => DropdownMenuItem<String?>(
                                          value: m,
                                          child: Text(
                                            _formatUrduMonthYear(m),
                                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                          ),
                                        )),
                                  ],
                                  onChanged: (val) {
                                    setState(() => _selectedMonth = val);
                                  },
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          // Staff / Teacher Filter Dropdown
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8),
                              decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: Colors.grey.shade400),
                              ),
                              child: DropdownButtonHideUnderline(
                                child: DropdownButton<String?>(
                                  isExpanded: true,
                                  value: _selectedTeacherId,
                                  hint: Text(
                                    isUrduGlobal.value ? 'تمام اساتذہ و عملہ' : 'All Staff',
                                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                  ),
                                  items: [
                                    DropdownMenuItem<String?>(
                                      value: null,
                                      child: Text(
                                        isUrduGlobal.value ? 'تمام اساتذہ و عملہ' : 'All Staff',
                                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                      ),
                                    ),
                                    ...availableTeachers.map((t) => DropdownMenuItem<String?>(
                                          value: t['id'],
                                          child: Text(
                                            t['name'] ?? '',
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                          ),
                                        )),
                                  ],
                                  onChanged: (val) {
                                    setState(() => _selectedTeacherId = val);
                                  },
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                // Month Summary Stats Card (Registered vs Orphan vs Total)
                Container(
                  margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.grey.shade300),
                    boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 2)],
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          _statColumn(
                            isUrduGlobal.value ? 'مندرج عملہ ($regCount)' : 'Reg ($regCount)',
                            'Rs. ${intl.NumberFormat('#,##0').format(regNet)}',
                            Colors.teal.shade800,
                          ),
                          _statColumn(
                            isUrduGlobal.value ? 'یتیم ریکارڈ ($orphanCount)' : 'Orphan ($orphanCount)',
                            'Rs. ${intl.NumberFormat('#,##0').format(orphanNet)}',
                            Colors.amber.shade900,
                          ),
                          _statColumn(
                            isUrduGlobal.value ? 'کل میزان (${filteredPayrolls.length})' : 'Total (${filteredPayrolls.length})',
                            'Rs. ${intl.NumberFormat('#,##0').format(totalNet)}',
                            Colors.blue.shade900,
                          ),
                        ],
                      ),
                      const Divider(height: 16),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          _statColumn(
                            isUrduGlobal.value ? 'ادا شدہ ($paidCount)' : 'Paid ($paidCount)',
                            'Rs. ${intl.NumberFormat('#,##0').format(totalPaid)}',
                            Colors.green.shade700,
                          ),
                          _statColumn(
                            isUrduGlobal.value ? 'بقایا ($unpaidCount)' : 'Unpaid ($unpaidCount)',
                            'Rs. ${intl.NumberFormat('#,##0').format(totalUnpaid)}',
                            Colors.red.shade700,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                Expanded(
                  child: filteredPayrolls.isEmpty
                      ? Center(
                          child: Text(
                            isUrduGlobal.value
                                ? 'اس مہینے کا کوئی پے رول ریکارڈ موجود نہیں'
                                : 'No payroll records found for selected month',
                            style: const TextStyle(color: Colors.grey, fontWeight: FontWeight.bold),
                          ),
                        )
                      : ListView.builder(
                          itemCount: filteredPayrolls.length,
                          itemBuilder: (context, index) {
                            final p = filteredPayrolls[index];
                            final isPaid = p['status'] == 'Paid';
                            return Card(
                              margin: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 4),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(8),
                                side: BorderSide(
                                  color: isPaid ? Colors.green.shade200 : Colors.orange.shade200,
                                ),
                              ),
                              child: ListTile(
                                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                title: Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        "${p['teacher_name']} (${_formatUrduMonthYear(p['month_year']?.toString())})",
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold, fontSize: 14),
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: isPaid ? Colors.green : Colors.orange.shade800,
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: Text(
                                        isPaid
                                            ? (isUrduGlobal.value ? 'ادا شدہ' : 'Paid')
                                            : (isUrduGlobal.value ? 'غیر ادا' : 'Unpaid'),
                                        style: const TextStyle(
                                            color: Colors.white,
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold),
                                      ),
                                    ),
                                  ],
                                ),
                                subtitle: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const SizedBox(height: 4),
                                    Text(
                                      "${isUrduGlobal.value ? 'بنیادی' : 'Basic'}: Rs. ${p['basic_salary']} | "
                                      "+${p['allowances']} -${p['deductions']} = Rs. ${p['net_salary']}",
                                      style: TextStyle(fontSize: 12, color: Colors.grey.shade800),
                                    ),
                                    if (isPaid && p['payment_date'] != null && p['payment_date'].toString().isNotEmpty)
                                      Text(
                                        "${isUrduGlobal.value ? 'تاریخ ادائیگی' : 'Paid on'}: ${p['payment_date']}",
                                        style: const TextStyle(fontSize: 10, color: Colors.green),
                                      ),
                                  ],
                                ),
                                trailing: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    if (!isPaid) ...[
                                      IconButton(
                                        icon: const Icon(Icons.edit, color: Colors.blue, size: 20),
                                        tooltip: isUrduGlobal.value ? 'ترمیم الاؤنس/کٹوتی' : 'Edit',
                                        onPressed: () => _editAllowances(p),
                                      ),
                                      IconButton(
                                        icon: const Icon(Icons.check_circle, color: Colors.green, size: 22),
                                        tooltip: isUrduGlobal.value ? 'ادائیگی درج کریں' : 'Mark Paid',
                                        onPressed: () => _markPaid(p),
                                      ),
                                      IconButton(
                                        icon: const Icon(Icons.delete_outline, color: Colors.red, size: 20),
                                        tooltip: isUrduGlobal.value ? 'حذف کریں' : 'Delete',
                                        onPressed: () => _deletePayroll(p),
                                      ),
                                    ] else ...[
                                      IconButton(
                                        icon: const Icon(Icons.undo, color: Colors.orange, size: 20),
                                        tooltip: isUrduGlobal.value ? 'غیر ادا شدہ کریں' : 'Mark Unpaid',
                                        onPressed: () => _markUnpaid(p),
                                      ),
                                    ],
                                    IconButton(
                                      icon: const Icon(Icons.receipt_long, color: Colors.indigo, size: 22),
                                      tooltip: isUrduGlobal.value ? 'سلپ دیکھیں' : 'View Slip',
                                      onPressed: () => _showReceipt(context, p),
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
    );
  }

  Widget _statColumn(String label, String value, Color color) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(value, style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: color)),
        const SizedBox(height: 2),
        Text(label, style: TextStyle(fontSize: 10, color: Colors.grey.shade700, fontWeight: FontWeight.w600)),
      ],
    );
  }
}
