// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class SalaryStatusRecord {
  final int teacherId;
  final String teacherName;
  final String fatherName;
  final String phone;
  final double monthlySalary;
  final String joiningDate;
  final String? resignationDate;
  final String status;
  final int monthsOfService;
  final double totalDue; // کل واجب
  final double totalPaid; // ادا شدہ
  final double totalUnpaid; // باقی (جنریٹ شدہ غیر ادا شدہ)
  final double totalArrears; // بقایا جات (غیر جنریٹ شدہ مہینے)
  final double totalAdvance; // ایڈوانس
  final double netBalance; // خالص باقی

  const SalaryStatusRecord({
    required this.teacherId,
    required this.teacherName,
    required this.fatherName,
    required this.phone,
    required this.monthlySalary,
    required this.joiningDate,
    this.resignationDate,
    required this.status,
    required this.monthsOfService,
    required this.totalDue,
    required this.totalPaid,
    required this.totalUnpaid,
    required this.totalArrears,
    required this.totalAdvance,
    required this.netBalance,
  });
}

class SalaryStatusPage extends StatefulWidget {
  const SalaryStatusPage({super.key});

  @override
  State<SalaryStatusPage> createState() => _SalaryStatusPageState();
}

class _SalaryStatusPageState extends State<SalaryStatusPage>
    with SingleTickerProviderStateMixin {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  late TabController _tabController;
  bool _isLoading = true;
  String _selectedMonthYear = '';
  List<SalaryStatusRecord> _records = [];
  List<SalaryStatusRecord> _filteredRecords = [];
  String _searchQuery = '';

  // انفرادی جائزہ کے لیے اسٹیٹ
  SalaryStatusRecord? _selectedTeacherRecord;
  List<Map<String, dynamic>> _teacherMonthGrid = [];

  // مجموعی اعداد (Grand Totals)
  double _grandDue = 0.0;
  double _grandPaid = 0.0;
  double _grandUnpaid = 0.0;
  double _grandArrears = 0.0;
  double _grandAdvance = 0.0;
  double _grandNet = 0.0;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    final now = DateTime.now();
    _selectedMonthYear =
        "${now.year}-${now.month.toString().padLeft(2, '0')}";
    _loadSalaryStatus();
    SystemTypeGlobal.currentSystemType.addListener(_onSystemTypeChanged);
  }

  void _onSystemTypeChanged() {
    if (mounted) {
      _loadSalaryStatus();
    }
  }

  @override
  void dispose() {
    _tabController.dispose();
    SystemTypeGlobal.currentSystemType.removeListener(_onSystemTypeChanged);
    super.dispose();
  }

  Future<void> _loadSalaryStatus() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final sys = SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sys == 'Masjid';

      List<Map<String, dynamic>> teachers = [];
      if (isMasjid) {
        teachers = await db.getMaktabTeachers();
      } else {
        teachers = await db.getTeachers();
      }

      final allPayrolls = await db.getAccPayroll();
      final cutoffDate = _parseMonthYear(_selectedMonthYear);

      List<SalaryStatusRecord> records = [];
      double sumDue = 0.0;
      double sumPaid = 0.0;
      double sumUnpaid = 0.0;
      double sumArrears = 0.0;
      double sumAdvance = 0.0;
      double sumNet = 0.0;

      for (var t in teachers) {
        if (t['is_deleted'] == 1) continue;
        final tid = int.tryParse(t['id']?.toString() ?? '') ?? 0;
        if (tid == 0) continue;

        final tName = (t['name'] ?? 'Staff').toString();
        final fName = (t['father_name'] ?? '').toString();
        final phone = (t['phone'] ?? '').toString();
        final salary = double.tryParse(t['salary']?.toString() ?? '0') ?? 0.0;
        final joiningStr = (t['joining_date'] ?? '').toString().trim();
        final resignationStr = (t['resignation_date'] ?? '').toString().trim();
        final status = (t['status'] ?? 'Active').toString();

        DateTime startDate = DateTime(2026, 1, 1);
        if (joiningStr.isNotEmpty) {
          final pDate = DateTime.tryParse(joiningStr);
          if (pDate != null) startDate = pDate;
        }

        DateTime endDate = cutoffDate;
        if (resignationStr.isNotEmpty) {
          final rDate = DateTime.tryParse(resignationStr);
          if (rDate != null && rDate.isBefore(cutoffDate)) {
            endDate = rDate;
          }
        }

        // سروس ونڈو کے فعال مہینوں کی تعداد
        int monthsCount = 0;
        if (!endDate.isBefore(startDate)) {
          monthsCount = ((endDate.year - startDate.year) * 12) +
              (endDate.month - startDate.month) +
              1;
          if (monthsCount < 0) monthsCount = 0;
        }

        // اس استاد کے تمام پے رول ریکارڈز حاصل کریں
        final teacherPayrolls = allPayrolls.where((p) {
          final pTid = int.tryParse(p['teacher_id']?.toString() ?? '') ?? 0;
          final pSys = p['system_type']?.toString();
          if (isMasjid && pSys != 'Masjid') return false;
          if (!isMasjid && pSys == 'Masjid') return false;
          final isDel = int.tryParse(p['is_deleted']?.toString() ?? '0') ?? 0;
          return pTid == tid && isDel == 0;
        }).toList();

        // ادا شدہ اور باقی تنخواہوں کی جمع بندی
        double paidAmt = 0.0;
        double unpaidAmt = 0.0;
        double advanceAmt = 0.0;
        Set<String> generatedMonths = {};

        for (var p in teacherPayrolls) {
          final mStr = (p['month_year'] ?? '').toString().trim();
          final net = double.tryParse(p['net_salary']?.toString() ?? '0') ?? 0.0;
          final st = (p['status'] ?? '').toString().trim().toLowerCase();

          if (mStr.isNotEmpty) {
            generatedMonths.add(mStr);
            final pMonthDate = _parseMonthYear(mStr);
            if (pMonthDate.isAfter(cutoffDate)) {
              if (st == 'paid') {
                advanceAmt += net;
              }
              continue;
            }
          }

          if (st == 'paid') {
            paidAmt += net;
          } else if (st == 'unpaid') {
            unpaidAmt += net;
          }
        }

        // کل واجب = مہینے × رجسٹرڈ تنخواہ
        final double dueAmt = monthsCount * salary;

        // بقایا جات = ایسے مہینے جن کا پے رول سرے سے جنریٹ ہی نہیں ہوا
        int missingMonths = 0;
        DateTime mPointer = DateTime(startDate.year, startDate.month, 1);
        final mEnd = DateTime(endDate.year, endDate.month, 1);
        while (!mPointer.isAfter(mEnd)) {
          final mKey =
              "${mPointer.year}-${mPointer.month.toString().padLeft(2, '0')}";
          if (!generatedMonths.contains(mKey)) {
            missingMonths++;
          }
          mPointer = DateTime(mPointer.year, mPointer.month + 1, 1);
        }
        final double arrearsAmt = missingMonths * salary;

        // خالص باقی = کل واجب − ادا شدہ − ایڈوانس
        final double netBal = dueAmt - paidAmt - advanceAmt;

        final rec = SalaryStatusRecord(
          teacherId: tid,
          teacherName: tName,
          fatherName: fName,
          phone: phone,
          monthlySalary: salary,
          joiningDate: joiningStr,
          resignationDate: resignationStr.isNotEmpty ? resignationStr : null,
          status: status,
          monthsOfService: monthsCount,
          totalDue: dueAmt,
          totalPaid: paidAmt,
          totalUnpaid: unpaidAmt,
          totalArrears: arrearsAmt,
          totalAdvance: advanceAmt,
          netBalance: netBal,
        );

        records.add(rec);
        sumDue += dueAmt;
        sumPaid += paidAmt;
        sumUnpaid += unpaidAmt;
        sumArrears += arrearsAmt;
        sumAdvance += advanceAmt;
        sumNet += netBal;
      }

      records.sort((a, b) => a.teacherId.compareTo(b.teacherId));

      setState(() {
        _records = records;
        _filteredRecords = _filterRecords(records, _searchQuery);
        _grandDue = sumDue;
        _grandPaid = sumPaid;
        _grandUnpaid = sumUnpaid;
        _grandArrears = sumArrears;
        _grandAdvance = sumAdvance;
        _grandNet = sumNet;
        _isLoading = false;
      });

      if (_selectedTeacherRecord != null) {
        _selectTeacherForDetail(_selectedTeacherRecord!);
      } else if (records.isNotEmpty) {
        _selectTeacherForDetail(records.first);
      }
    } catch (e) {
      debugPrint("Load Salary Status Error: $e");
      if (mounted) setState(() => _isLoading = false);
    }
  }

  DateTime _parseMonthYear(String my) {
    try {
      final parts = my.split('-');
      if (parts.length >= 2) {
        final y = int.parse(parts[0]);
        final m = int.parse(parts[1]);
        return DateTime(y, m, 1);
      }
    } catch (_) {}
    return DateTime.now();
  }

  List<SalaryStatusRecord> _filterRecords(
      List<SalaryStatusRecord> list, String q) {
    if (q.trim().isEmpty) return list;
    final lower = q.trim().toLowerCase();
    return list.where((r) {
      return r.teacherName.toLowerCase().contains(lower) ||
          r.fatherName.toLowerCase().contains(lower) ||
          r.teacherId.toString().contains(lower);
    }).toList();
  }

  Future<void> _selectTeacherForDetail(SalaryStatusRecord rec) async {
    _selectedTeacherRecord = rec;
    final db = DBHelper();
    final allPayrolls = await db.getAccPayroll();
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    final teacherPayrolls = allPayrolls.where((p) {
      final pTid = int.tryParse(p['teacher_id']?.toString() ?? '') ?? 0;
      final pSys = p['system_type']?.toString();
      if (isMasjid && pSys != 'Masjid') return false;
      if (!isMasjid && pSys == 'Masjid') return false;
      final isDel = int.tryParse(p['is_deleted']?.toString() ?? '0') ?? 0;
      return pTid == rec.teacherId && isDel == 0;
    }).toList();

    final payrollMap = <String, Map<String, dynamic>>{};
    for (var p in teacherPayrolls) {
      final my = (p['month_year'] ?? '').toString().trim();
      if (my.isNotEmpty) payrollMap[my] = p;
    }

    DateTime startDate = DateTime(2026, 1, 1);
    if (rec.joiningDate.isNotEmpty) {
      final pDate = DateTime.tryParse(rec.joiningDate);
      if (pDate != null) startDate = pDate;
    }

    final cutoff = _parseMonthYear(_selectedMonthYear);
    DateTime endDate = cutoff;
    if (rec.resignationDate != null && rec.resignationDate!.isNotEmpty) {
      final rDate = DateTime.tryParse(rec.resignationDate!);
      if (rDate != null && rDate.isBefore(cutoff)) {
        endDate = rDate;
      }
    }

    // Include up to selected cutoff month and any future advance months
    DateTime latestMonth = endDate;
    for (var my in payrollMap.keys) {
      final d = _parseMonthYear(my);
      if (d.isAfter(latestMonth)) latestMonth = d;
    }

    List<Map<String, dynamic>> grid = [];
    DateTime cur = DateTime(startDate.year, startDate.month, 1);
    final finish = DateTime(latestMonth.year, latestMonth.month, 1);

    while (!cur.isAfter(finish)) {
      final mKey = "${cur.year}-${cur.month.toString().padLeft(2, '0')}";
      final pRow = payrollMap[mKey];
      final isFuture = cur.isAfter(cutoff);

      String statusLabel = 'غیر جنریٹ شدہ بقایا';
      Color statusColor = Colors.red.shade700;
      double amt = rec.monthlySalary;
      String payDate = '-';
      String payMethod = '-';

      if (pRow != null) {
        amt = double.tryParse(pRow['net_salary']?.toString() ?? '0') ??
            rec.monthlySalary;
        final st = (pRow['status'] ?? '').toString().toLowerCase();
        payDate = (pRow['payment_date'] ?? '-').toString();
        payMethod = (pRow['payment_method'] ?? 'Cash').toString();

        if (st == 'paid') {
          if (isFuture) {
            statusLabel = 'ایڈوانس ادا شدہ';
            statusColor = Colors.blue.shade700;
          } else {
            statusLabel = 'ادا شدہ';
            statusColor = Colors.green.shade700;
          }
        } else {
          statusLabel = 'باقی (غیر ادا شدہ)';
          statusColor = Colors.orange.shade800;
        }
      } else if (isFuture) {
        cur = DateTime(cur.year, cur.month + 1, 1);
        continue;
      }

      grid.add({
        'month_year': mKey,
        'status': statusLabel,
        'color': statusColor,
        'amount': amt,
        'payment_date': payDate,
        'payment_method': payMethod,
      });

      cur = DateTime(cur.year, cur.month + 1, 1);
    }

    setState(() {
      _teacherMonthGrid = grid.reversed.toList();
    });
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
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          iconTheme: const IconThemeData(color: Colors.white),
          actionsIconTheme: const IconThemeData(color: Colors.white),
          title: Text(
            isUrdu ? 'تنخواہ و واجبات اسٹیٹس' : 'Staff Salary Status',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
          ),
          bottom: TabBar(
            controller: _tabController,
            indicatorColor: Colors.amber,
            indicatorWeight: 3,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            tabs: [
              Tab(
                icon: const Icon(Icons.table_chart, size: 20),
                text: isUrdu ? 'مشترکہ جدول' : 'Combined Overview',
              ),
              Tab(
                icon: const Icon(Icons.person_search, size: 20),
                text: isUrdu ? 'ماہ بہ ماہ جائزہ' : 'Individual Grid',
              ),
            ],
          ),
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh, color: Colors.white),
              tooltip: isUrdu ? 'ریفریش کریں' : 'Refresh',
              onPressed: _loadSalaryStatus,
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  _buildTopFilterBar(isUrdu),
                  Expanded(
                    child: TabBarView(
                      controller: _tabController,
                      children: [
                        _buildCombinedOverviewTab(isUrdu),
                        _buildIndividualGridTab(isUrdu),
                      ],
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _buildTopFilterBar(bool isUrdu) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: TextField(
              decoration: InputDecoration(
                hintText: isUrdu ? 'استاد کے نام سے تلاش کریں...' : 'Search staff...',
                prefixIcon: const Icon(Icons.search, size: 20),
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
              onChanged: (val) {
                setState(() {
                  _searchQuery = val;
                  _filteredRecords = _filterRecords(_records, val);
                });
              },
            ),
          ),
          const SizedBox(width: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: primaryGreen.withOpacity(0.08),
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: primaryGreen.withOpacity(0.3)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.calendar_month, size: 16, color: primaryGreen),
                const SizedBox(width: 6),
                Text(
                  '$_selectedMonthYear تا',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                    color: primaryGreen,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildCombinedOverviewTab(bool isUrdu) {
    return Column(
      children: [
        // Grand Total Benchmark Summary Cards
        Container(
          padding: const EdgeInsets.all(12),
          color: primaryGreen,
          child: Row(
            children: [
              _buildSummaryPill('کل واجب', _grandDue, Colors.white, Colors.white12),
              const SizedBox(width: 8),
              _buildSummaryPill('ادا شدہ', _grandPaid, Colors.greenAccent, Colors.white12),
              const SizedBox(width: 8),
              _buildSummaryPill('ایڈوانس', _grandAdvance, Colors.cyanAccent, Colors.white12),
              const SizedBox(width: 8),
              _buildSummaryPill(
                'خالص باقی',
                _grandNet,
                _grandNet >= 0 ? Colors.amberAccent : Colors.orangeAccent,
                Colors.white12,
              ),
            ],
          ),
        ),

        // Staff Records Table
        Expanded(
          child: _filteredRecords.isEmpty
              ? Center(
                  child: Text(
                    isUrdu ? 'کوئی ریکارڈ موجود نہیں' : 'No records found',
                    style: const TextStyle(fontSize: 14, color: Colors.grey),
                  ),
                )
              : SingleChildScrollView(
                  scrollDirection: Axis.vertical,
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: DataTable(
                      headingRowColor: WidgetStateProperty.all(
                        Colors.grey.shade200,
                      ),
                      columnSpacing: 18,
                      columns: [
                        DataColumn(label: Text(isUrdu ? '#' : '#', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'نام استاد / عملہ' : 'Staff Name', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'ماہانہ تنخواہ' : 'Monthly Salary', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'مدتِ خدمت' : 'Months', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'کل واجب' : 'Total Due', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'ادا شدہ' : 'Paid', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'باقی (پے رول)' : 'Unpaid', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'بقایا جات' : 'Arrears', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'ایڈوانس' : 'Advance', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'خالص باقی' : 'Net Balance', style: const TextStyle(fontWeight: FontWeight.bold))),
                        DataColumn(label: Text(isUrdu ? 'ایکشن' : 'Action', style: const TextStyle(fontWeight: FontWeight.bold))),
                      ],
                      rows: _filteredRecords.map((r) {
                        return DataRow(
                          cells: [
                            DataCell(Text('${r.teacherId}')),
                            DataCell(
                              Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Text(r.teacherName, style: const TextStyle(fontWeight: FontWeight.bold)),
                                  if (r.fatherName.isNotEmpty)
                                    Text('ولد ${r.fatherName}', style: const TextStyle(fontSize: 10, color: Colors.grey)),
                                ],
                              ),
                            ),
                            DataCell(Text(intl.NumberFormat('#,##0').format(r.monthlySalary))),
                            DataCell(Text('${r.monthsOfService} ماہ')),
                            DataCell(Text(intl.NumberFormat('#,##0').format(r.totalDue), style: const TextStyle(fontWeight: FontWeight.bold))),
                            DataCell(Text(intl.NumberFormat('#,##0').format(r.totalPaid), style: TextStyle(color: Colors.green.shade800, fontWeight: FontWeight.bold))),
                            DataCell(Text(intl.NumberFormat('#,##0').format(r.totalUnpaid))),
                            DataCell(Text(intl.NumberFormat('#,##0').format(r.totalArrears))),
                            DataCell(Text(
                              intl.NumberFormat('#,##0').format(r.totalAdvance),
                              style: TextStyle(color: Colors.blue.shade800, fontWeight: FontWeight.bold),
                            )),
                            DataCell(Text(
                              intl.NumberFormat('#,##0').format(r.netBalance),
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: r.netBalance < 0 ? Colors.purple.shade800 : (r.netBalance > 0 ? Colors.red.shade800 : Colors.black),
                              ),
                            )),
                            DataCell(
                              IconButton(
                                icon: const Icon(Icons.remove_red_eye, size: 18, color: Color(0xFF284D3D)),
                                tooltip: isUrdu ? 'ماہ بہ ماہ جائزہ' : 'View Grid',
                                onPressed: () {
                                  _selectTeacherForDetail(r);
                                  _tabController.animateTo(1);
                                },
                              ),
                            ),
                          ],
                        );
                      }).toList(),
                    ),
                  ),
                ),
        ),
      ],
    );
  }

  Widget _buildSummaryPill(String title, double amount, Color textColor, Color bgColor) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
        decoration: BoxDecoration(
          color: bgColor,
          borderRadius: BorderRadius.circular(6),
        ),
        child: Column(
          children: [
            Text(
              title,
              style: TextStyle(color: textColor.withOpacity(0.85), fontSize: 11, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 2),
            Text(
              intl.NumberFormat('#,##0').format(amount),
              style: TextStyle(color: textColor, fontSize: 13, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildIndividualGridTab(bool isUrdu) {
    if (_selectedTeacherRecord == null) {
      return Center(
        child: Text(
          isUrdu ? 'براہ کرم کسی استاد کا انتخاب کریں' : 'Please select a staff member',
        ),
      );
    }

    final rec = _selectedTeacherRecord!;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Teacher Selector & Profile Card
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            elevation: 2,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  Row(
                    children: [
                      CircleAvatar(
                        backgroundColor: primaryGreen,
                        child: const Icon(Icons.person, color: Colors.white),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: DropdownButton<int>(
                          isExpanded: true,
                          value: rec.teacherId,
                          underline: const SizedBox(),
                          items: _records.map((r) {
                            return DropdownMenuItem<int>(
                              value: r.teacherId,
                              child: Text('${r.teacherName} (ID: ${r.teacherId})'),
                            );
                          }).toList(),
                          onChanged: (newId) {
                            if (newId != null) {
                              final target = _records.firstWhere((r) => r.teacherId == newId);
                              _selectTeacherForDetail(target);
                            }
                          },
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 24),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _buildProfileItem('ماہانہ تنخواہ', 'Rs. ${intl.NumberFormat('#,##0').format(rec.monthlySalary)}'),
                      _buildProfileItem('تاریخ شمولیت', rec.joiningDate.isNotEmpty ? rec.joiningDate : '-'),
                      _buildProfileItem('کل ادا شدہ', 'Rs. ${intl.NumberFormat('#,##0').format(rec.totalPaid)}'),
                      _buildProfileItem('خالص باقی', 'Rs. ${intl.NumberFormat('#,##0').format(rec.netBalance)}'),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          Text(
            isUrdu ? 'ماہ بہ ماہ پے رول اور ادائیگی گرڈ:' : 'Month-by-Month Payroll Grid:',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
          ),
          const SizedBox(height: 10),

          // Month-by-Month Grid
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            elevation: 1,
            child: Table(
              border: TableBorder.all(color: Colors.grey.shade300, width: 0.8),
              columnWidths: const {
                0: FlexColumnWidth(1.8),
                1: FlexColumnWidth(2.2),
                2: FlexColumnWidth(1.8),
                3: FlexColumnWidth(1.8),
                4: FlexColumnWidth(1.6),
              },
              children: [
                TableRow(
                  decoration: BoxDecoration(color: Colors.grey.shade200),
                  children: [
                    _tableCell('ماہ و سال', isHeader: true),
                    _tableCell('حیثیت / اسٹیٹس', isHeader: true),
                    _tableCell('رقم (روپے)', isHeader: true),
                    _tableCell('تاریخ ادائیگی', isHeader: true),
                    _tableCell('طریقہ', isHeader: true),
                  ],
                ),
                ..._teacherMonthGrid.map((row) {
                  final Color c = row['color'] as Color;
                  return TableRow(
                    children: [
                      _tableCell(row['month_year']?.toString() ?? '-'),
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
                        child: Container(
                          padding: const EdgeInsets.symmetric(vertical: 3, horizontal: 8),
                          decoration: BoxDecoration(
                            color: c.withOpacity(0.12),
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: c.withOpacity(0.5), width: 0.8),
                          ),
                          child: Text(
                            row['status']?.toString() ?? '-',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: c, fontWeight: FontWeight.bold, fontSize: 11),
                          ),
                        ),
                      ),
                      _tableCell(intl.NumberFormat('#,##0').format(row['amount'])),
                      _tableCell(row['payment_date']?.toString() ?? '-'),
                      _tableCell(row['payment_method']?.toString() ?? '-'),
                    ],
                  );
                }),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProfileItem(String label, String value) {
    return Column(
      children: [
        Text(label, style: const TextStyle(fontSize: 11, color: Colors.grey)),
        const SizedBox(height: 2),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
      ],
    );
  }

  Widget _tableCell(String text, {bool isHeader = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(
          fontWeight: isHeader ? FontWeight.bold : FontWeight.normal,
          fontSize: isHeader ? 12 : 11,
        ),
      ),
    );
  }
}
