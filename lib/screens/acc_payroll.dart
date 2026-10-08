// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
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
      if (t == null) continue;
      final tName = t['name'] ?? 'Staff';
      enriched.add({
        ...p,
        'teacher_name': tName,
      });
    }

    setState(() {
      _payrolls = enriched;
      _teachers = teachers;
      _isLoading = false;
    });

    // Reconcile paid stipends to ensure they are recorded in Simple Finance
    _reconcilePaidPayrolls(enriched);
  }

  Future<void> _reconcilePaidPayrolls(List<Map<String, dynamic>> payrolls) async {
    try {
      final db = DBHelper();
      final sys = SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sys == 'Masjid';
      final table = isMasjid ? 'masjid_finance' : 'finance';
      final dbInst = await db.database;

      for (var p in payrolls) {
        if (p['status'] != 'Paid') continue;
        final tName = (p['teacher_name'] ?? 'Staff').toString();
        final mYear = (p['month_year'] ?? '').toString();
        final netSalary = double.tryParse(p['net_salary']?.toString() ?? '0') ?? 0.0;
        if (netSalary <= 0) continue;

        final payDate = (p['payment_date']?.toString().isNotEmpty ?? false)
            ? p['payment_date'].toString()
            : DateTime.now().toIso8601String().substring(0, 10);
        final payMethod = (p['payment_method']?.toString().isNotEmpty ?? false)
            ? p['payment_method'].toString()
            : 'Cash';
        final payAccId = int.tryParse(p['payment_account_id']?.toString() ?? '');

        // Check if already present in finance
        final descPattern = "%ماہ $mYear: $tName%";
        final existing = await dbInst.rawQuery(
          "SELECT id FROM $table WHERE (is_deleted = 0 OR is_deleted IS NULL) AND type = 'Expense' AND description LIKE ?",
          [descPattern],
        );

        if (existing.isEmpty) {
          final financeEntry = {
            'user_id': currentUserId.value ?? 1,
            'user_name': currentUserName.value ?? currentUserRole.value ?? 'Admin',
            'type': 'Expense',
            'head_id': 0,
            'head_name': isUrduGlobal.value ? 'وظائف عملہ' : 'Staff Stipends',
            'account_id': payAccId,
            'payment_method': payMethod,
            'amount': netSalary,
            'date': payDate,
            'donor_name': tName,
            'donor_address': '',
            'spender_name': tName,
            'manual_receipt_no': '',
            'description':
                "مد: وظائف عملہ | وظیفہ/تنخواہ ادا کی گئی برائے ماہ $mYear: $tName ($payMethod)",
            'system_type': sys,
            'is_deleted': 0,
            'is_synced': 0,
          };
          if (isMasjid) {
            await db.insertMasjidFinance(financeEntry);
          } else {
            await db.insertFinance(financeEntry);
          }
        }
      }
    } catch (e) {
      debugPrint("Error in _reconcilePaidPayrolls: $e");
    }
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
    for (var p in _payrolls) {
      final tid = p['teacher_id']?.toString() ?? '';
      final tname = p['teacher_name']?.toString() ?? 'Staff';
      if (tid.isNotEmpty && tname.isNotEmpty && tname != 'Staff') {
        map[tid] = tname;
      }
    }
    for (var t in _teachers) {
      final tid = t['id']?.toString() ?? '';
      final tname = t['name']?.toString() ?? '';
      if (tid.isNotEmpty && tname.isNotEmpty) {
        map[tid] = tname;
      }
    }
    final list = map.entries.map((e) => {'id': e.key, 'name': e.value}).toList();
    list.sort((a, b) => (a['name'] ?? '').compareTo(b['name'] ?? ''));
    return list;
  }

  Future<void> _generateBulkPayroll() async {
    DateTime targetDate = DateTime.now();
    final monthController = TextEditingController(
        text:
            "${targetDate.year}-${targetDate.month.toString().padLeft(2, '0')}");

    final result = await showDialog<bool>(
        context: context,
        builder: (context) {
          return StatefulBuilder(
            builder: (context, setDlgState) {
              return AlertDialog(
                title: Row(
                  children: [
                    Icon(Icons.auto_awesome, color: primaryGreen),
                    const SizedBox(width: 8),
                    Text(isUrduGlobal.value ? 'ماہانہ وظائف تیار کریں' : 'Generate Staff Stipends'),
                  ],
                ),
                content: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isUrduGlobal.value 
                          ? 'جس مہینے کے وظائف تیار کرنا چاہتے ہیں وہ منتخب کریں:' 
                          : 'Select target month for staff stipends:',
                      style: const TextStyle(fontSize: 13, color: Colors.grey),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: monthController,
                      decoration: InputDecoration(
                        labelText:
                            isUrduGlobal.value ? 'مہینہ (YYYY-MM)' : 'Month (YYYY-MM)',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.calendar_month),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () {
                              final prev = DateTime(targetDate.year, targetDate.month - 1, 1);
                              targetDate = prev;
                              monthController.text = "${prev.year}-${prev.month.toString().padLeft(2, '0')}";
                              setDlgState(() {});
                            },
                            child: Text(isUrduGlobal.value ? 'پچھلا مہینہ' : 'Prev Month', style: const TextStyle(fontSize: 11)),
                          ),
                        ),
                        const SizedBox(width: 4),
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () {
                              final curr = DateTime.now();
                              targetDate = curr;
                              monthController.text = "${curr.year}-${curr.month.toString().padLeft(2, '0')}";
                              setDlgState(() {});
                            },
                            child: Text(isUrduGlobal.value ? 'رواں مہینہ' : 'Current', style: const TextStyle(fontSize: 11)),
                          ),
                        ),
                        const SizedBox(width: 4),
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () {
                              final next = DateTime(targetDate.year, targetDate.month + 1, 1);
                              targetDate = next;
                              monthController.text = "${next.year}-${next.month.toString().padLeft(2, '0')}";
                              setDlgState(() {});
                            },
                            child: Text(isUrduGlobal.value ? 'اگلا مہینہ' : 'Next Month', style: const TextStyle(fontSize: 11)),
                          ),
                        ),
                      ],
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
                    child: Text(isUrduGlobal.value ? 'وظائف تیار کریں' : 'Generate'),
                  ),
                ],
              );
            },
          );
        });

    if (result == true) {
      final month = monthController.text.trim();
      if (month.isNotEmpty) {
        final db = DBHelper();
        final sys = SystemTypeGlobal.currentSystemType.value;
        final isMasjid = sys == 'Masjid';
        final teachers = isMasjid ? await db.getMaktabTeachers() : await db.getTeachers();
        int createdCount = 0;
        for (var t in teachers) {
          if (t['is_deleted'] == 1) continue;

          final st = (t['status'] ?? 'Active').toString().trim().toLowerCase();
          // Exclude explicitly resigned, left, expelled, terminated, inactive
          if (st == 'inactive' ||
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
              st.contains('معطل')) {
            continue;
          }

          final tId = t['id'];
          final salaryStr = t['salary']?.toString() ?? '0';
          final basicSalary = double.tryParse(salaryStr) ?? 0.0;
          final allowances = (double.tryParse(t['house_rent']?.toString() ?? '0') ?? 0.0) +
              (double.tryParse(t['medical_allowance']?.toString() ?? '0') ?? 0.0) +
              (double.tryParse(t['travel_allowance']?.toString() ?? '0') ?? 0.0);

          final existing = _payrolls
              .where((i) => i['teacher_id']?.toString() == tId?.toString() && i['month_year'] == month)
              .toList();
          if (existing.isEmpty) {
            await db.insertAccPayroll({
              'teacher_id': tId,
              'month_year': month,
              'basic_salary': basicSalary,
              'allowances': allowances,
              'deductions': 0.0,
              'net_salary': basicSalary + allowances,
              'status': 'Unpaid',
              'system_type': sys,
              'created_by': currentUserId.value ?? 1,
            });
            createdCount++;
          }
        }
        await _loadPayrolls();
        setState(() => _selectedMonth = month);
        SyncService.runTwoWaySync().catchError((_) {});
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(isUrduGlobal.value
                  ? '$createdCount اساتذہ کے وظائف تیار ہو گئے برائے ($month)'
                  : 'Stipends generated for $createdCount staff ($month)')));
        }
      }
    }
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
                            const Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text("مدرسہ اشرف المدارس",
                                      style: TextStyle(
                                          fontSize: 18,
                                          fontWeight: FontWeight.bold,
                                          fontFamily: 'JameelNooriNastaleeq')),
                                  Text("فاروقہ، ضلع سرگودھا",
                                      style: TextStyle(
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
      final matchMonth = _selectedMonth == null || _selectedMonth!.isEmpty || p['month_year'] == _selectedMonth;
      final matchTeacher = _selectedTeacherId == null || _selectedTeacherId!.isEmpty || p['teacher_id']?.toString() == _selectedTeacherId;
      return matchMonth && matchTeacher;
    }).toList();

    double totalNet = 0;
    double totalPaid = 0;
    double totalUnpaid = 0;
    int paidCount = 0;
    int unpaidCount = 0;

    for (var p in filteredPayrolls) {
      double net = double.tryParse(p['net_salary']?.toString() ?? '0') ?? 0;
      totalNet += net;
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

                // Month Summary Stats Card
                Container(
                  margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.grey.shade300),
                    boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 2)],
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _statColumn(
                        isUrduGlobal.value ? 'کل اساتذہ' : 'Staff',
                        '${filteredPayrolls.length}',
                        Colors.blue.shade800,
                      ),
                      _statColumn(
                        isUrduGlobal.value ? 'کل تنخواہ' : 'Total Net',
                        'Rs. ${totalNet.toInt()}',
                        Colors.black87,
                      ),
                      _statColumn(
                        isUrduGlobal.value ? 'ادا شدہ ($paidCount)' : 'Paid ($paidCount)',
                        'Rs. ${totalPaid.toInt()}',
                        Colors.green.shade700,
                      ),
                      _statColumn(
                        isUrduGlobal.value ? 'بقایا ($unpaidCount)' : 'Unpaid ($unpaidCount)',
                        'Rs. ${totalUnpaid.toInt()}',
                        Colors.red.shade700,
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
