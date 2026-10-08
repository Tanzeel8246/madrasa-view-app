// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../utils/performance_helper.dart';
import '../utils/system_type_global.dart';
import 'dashboard.dart';

class ReportsFinancePage extends StatefulWidget {
  const ReportsFinancePage({super.key});

  @override
  _ReportsFinancePageState createState() => _ReportsFinancePageState();
}

class _ReportsFinancePageState extends State<ReportsFinancePage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF1E3A2F);

  List<Map<String, dynamic>> _financeRecords = [];
  Map<int, Map<String, dynamic>> _userGroups = {}; // For Admin grouped view
  Set<int> _expandedUsers = {}; // Track expanded user accordions

  bool isLoading = true;
  String? fromDate;
  String? toDate;

  final _fromDateController = TextEditingController();
  final _toDateController = TextEditingController();
  String selectedPeriod = 'all';

  double totalIncome = 0;
  double totalExpense = 0;

  int? _selectedUserId;
  Map<int, String> _availableUsers = {};

  @override
  void initState() {
    super.initState();
    SystemTypeGlobal.currentSystemType.addListener(_loadFinance);
    _loadFinance();
  }

  @override
  void dispose() {
    SystemTypeGlobal.currentSystemType.removeListener(_loadFinance);
    _fromDateController.dispose();
    _toDateController.dispose();
    super.dispose();
  }

  Future<void> _updateDatesForPeriod(String period) async {
    if (period == 'custom') return;
    if (period == 'all') {
      setState(() {
        fromDate = null;
        toDate = null;
        _fromDateController.clear();
        _toDateController.clear();
      });
      _loadFinance();
      return;
    }
    final db = DBHelper();
    final settings = await db.getSettings();
    int weekStartDay = int.tryParse(settings['week_start_day']?.toString() ?? '6') ?? 6;
    final boundaries = PerformanceCalculator.getPeriodBoundaries(period, weekStartDay);
    setState(() {
      fromDate = "${boundaries['start']!.day}/${boundaries['start']!.month}/${boundaries['start']!.year}";
      toDate = "${boundaries['end']!.day}/${boundaries['end']!.month}/${boundaries['end']!.year}";
      _fromDateController.text = fromDate!;
      _toDateController.text = toDate!;
    });
    _loadFinance();
  }

  Future<void> _loadFinance() async {
    setState(() => isLoading = true);
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdminOrNazim = role == 'admin' || role == 'superadmin' || role == 'nazim';

    // Strict system_type isolation: getMasjidFinance for Masjid, getFinance for Madrasa
    final allFinance = isMasjid
        ? await DBHelper().getMasjidFinance(
            userId: isAdminOrNazim ? null : currentUserId.value,
            userRole: currentUserRole.value,
          )
        : await DBHelper().getFinance(
            userId: isAdminOrNazim ? null : currentUserId.value,
            userRole: currentUserRole.value,
          );

    final Map<int, String> userOptions = {};
    for (var f in allFinance) {
      final uid = (f['user_id'] as int?) ?? 0;
      final uname = (f['user_name'] as String?)?.trim() ?? '';
      if (uid > 0 && uname.isNotEmpty) {
        userOptions[uid] = uname;
      }
    }

    List<Map<String, dynamic>> filtered = [];
    Map<int, Map<String, dynamic>> grouped = {};
    totalIncome = 0;
    totalExpense = 0;

    for (var f in allFinance) {
      int uid = (f['user_id'] as int?) ?? 0;
      if (_selectedUserId != null && uid != _selectedUserId) {
        continue;
      }

      if (fromDate != null || toDate != null) {
        DateTime? fDate;
        try {
          fDate = intl.DateFormat('d/M/yyyy').parse(f['date'].toString());
        } catch (_) {
          try {
            fDate = DateTime.parse(f['date'].toString());
          } catch (_) {}
        }
        if (fDate != null) {
          DateTime fDateClean = DateTime(fDate.year, fDate.month, fDate.day);
          if (fromDate != null) {
            try {
              DateTime sDate = intl.DateFormat('d/M/yyyy').parse(fromDate!);
              DateTime sDateStart = DateTime(sDate.year, sDate.month, sDate.day);
              if (fDateClean.isBefore(sDateStart)) continue;
            } catch (_) {}
          }
          if (toDate != null) {
            try {
              DateTime eDate = intl.DateFormat('d/M/yyyy').parse(toDate!);
              DateTime eDateEnd = DateTime(eDate.year, eDate.month, eDate.day);
              if (fDateClean.isAfter(eDateEnd)) continue;
            } catch (_) {}
          }
        }
      }
      filtered.add(f);
      double amt = f['amount'] is double
          ? f['amount']
          : double.tryParse(f['amount'].toString()) ?? 0.0;
      String typeLower = f['type']?.toString().toLowerCase() ?? '';

      if (typeLower == 'income') totalIncome += amt;
      if (typeLower == 'expense') totalExpense += amt;

      if (isAdminOrNazim) {
        String uname = (f['user_name'] as String?) ?? 'نامعلوم';
        if (!grouped.containsKey(uid)) {
          grouped[uid] = {
            'name': uname,
            'income': <Map<String, dynamic>>[],
            'expense': <Map<String, dynamic>>[]
          };
        }
        if (typeLower == 'income') {
          (grouped[uid]!['income'] as List).add(f);
        } else {
          (grouped[uid]!['expense'] as List).add(f);
        }
      }
    }

    setState(() {
      _availableUsers = userOptions;
      _financeRecords = filtered;
      _userGroups = grouped;
      if (_expandedUsers.isEmpty && grouped.isNotEmpty) {
        _expandedUsers = {grouped.keys.first};
      }
      isLoading = false;
    });
  }

  Future<void> _selectDate(BuildContext context, bool isFrom) async {
    DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      String formatted = "${picked.day}/${picked.month}/${picked.year}";
      setState(() {
        selectedPeriod = 'custom';
        if (isFrom) {
          fromDate = formatted;
          _fromDateController.text = formatted;
        } else {
          toDate = formatted;
          _toDateController.text = formatted;
        }
      });
      _loadFinance();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        } else {
          Navigator.pushReplacement(
              context, MaterialPageRoute(builder: (c) => const DashboardPage()));
        }
      },
      child: Builder(builder: (context) {
        final theme = Theme.of(context);
        final isDark = theme.brightness == Brightness.dark;
        final effectiveBg = theme.scaffoldBackgroundColor;
        final effectiveAppBarBg = isDark ? theme.colorScheme.surface : primaryGreen;
        final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';

        return Scaffold(
          backgroundColor: effectiveBg,
          drawer: const AppDrawer(),
          appBar: AppBar(
            leading: Builder(
              builder: (context) {
                if (Navigator.canPop(context)) {
                  return IconButton(
                    icon: const Icon(Icons.arrow_back, color: Colors.white),
                    onPressed: () => Navigator.pop(context),
                    tooltip: isUrduGlobal.value ? "واپس" : "Back",
                  );
                }
                return IconButton(
                  icon: const Icon(Icons.menu, color: Colors.white),
                  onPressed: () => Scaffold.of(context).openDrawer(),
                );
              },
            ),
            title: Text(isUrduGlobal.value
                ? (isMasjid ? "فنانس رپورٹ (مسجد)" : "مالیاتی تفصیلی رپورٹ")
                : (isMasjid ? "Masjid Finance Report" : "Detailed Finance Report")),
            backgroundColor: effectiveAppBarBg,
            foregroundColor: Colors.white,
            centerTitle: true,
          actions: [
            IconButton(
              icon: const Icon(Icons.sync, color: Colors.white),
              onPressed: () async {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text(isUrduGlobal.value
                        ? "ڈیٹا سنک ہو رہا ہے..."
                        : "Syncing data...")));
                try {
                  await SyncService.runTwoWaySync();
                  if (mounted) {
                    _loadFinance();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value
                            ? "سنک مکمل ہو گیا!"
                            : "Sync complete!"),
                        backgroundColor: Colors.green));
                  }
                } catch (e) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrduGlobal.value
                            ? "سنک میں خرابی آئی"
                            : "Sync failed"),
                        backgroundColor: Colors.red));
                  }
                }
              },
            ),
          ],
        ),
        body: Column(
          children: [
            // Responsive Filter Section
            Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final isWide = constraints.maxWidth >= 900;
                  final hasUserFilter = (currentUserRole.value == 'admin' ||
                          currentUserRole.value == 'superadmin' ||
                          currentUserRole.value == 'nazim') &&
                      _availableUsers.isNotEmpty;

                  final userDropdown = hasUserFilter
                      ? DropdownButtonFormField<int?>(
                          value: _selectedUserId,
                          isExpanded: true,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "صارف / کیشیئر فلٹر" : "User / Cashier Filter",
                            border: const OutlineInputBorder(),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          ),
                          items: [
                            DropdownMenuItem<int?>(
                              value: null,
                              child: Text(isUrduGlobal.value ? "تمام صارفین (All Users)" : "All Users", overflow: TextOverflow.ellipsis),
                            ),
                            ..._availableUsers.entries.map(
                              (e) => DropdownMenuItem<int?>(
                                value: e.key,
                                child: Text(e.value, overflow: TextOverflow.ellipsis),
                              ),
                            ),
                          ],
                          onChanged: (v) {
                            setState(() => _selectedUserId = v);
                            _loadFinance();
                          },
                        )
                      : null;

                  final periodDropdown = DropdownButtonFormField<String>(
                    value: selectedPeriod,
                    isExpanded: true,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "دورانیہ (Period)" : "Period",
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    items: [
                      DropdownMenuItem(
                          value: 'all',
                          child: Text(isUrduGlobal.value ? "تمام تاریخیں / تمام ریکارڈز (All)" : "All Dates (All Records)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'this_month',
                          child: Text(isUrduGlobal.value ? "رواں مہینہ (This Month)" : "This Month", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'current_week',
                          child: Text(isUrduGlobal.value ? "رواں ہفتہ (Current Week)" : "Current Week", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'weekly',
                          child: Text(isUrduGlobal.value ? "ہفتہ وار (آخری 7 دن)" : "Weekly (Last 7 Days)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'monthly',
                          child: Text(isUrduGlobal.value ? "ماہانہ (آخری 30 دن)" : "Monthly (Last 30 Days)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'quarterly',
                          child: Text(isUrduGlobal.value ? "سہ ماہی (آخری 90 دن)" : "Quarterly (Last 90 Days)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'semi_annually',
                          child: Text(isUrduGlobal.value ? "شش ماہی (آخری 180 دن)" : "Semi-Annually (180 Days)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'annually',
                          child: Text(isUrduGlobal.value ? "سالانہ (آخری 365 دن)" : "Annually (365 Days)", overflow: TextOverflow.ellipsis)),
                      DropdownMenuItem(
                          value: 'custom',
                          child: Text(isUrduGlobal.value ? "کسٹم ڈیٹس (Custom)" : "Custom Dates", overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setState(() => selectedPeriod = v);
                        _updateDatesForPeriod(v);
                      }
                    },
                  );

                  final fromDateField = TextField(
                    controller: _fromDateController,
                    readOnly: true,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "ابتدائی تاریخ" : "From Date",
                        prefixIcon: const Icon(Icons.calendar_today, size: 16),
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    onTap: () => _selectDate(context, true),
                  );

                  final toDateField = TextField(
                    controller: _toDateController,
                    readOnly: true,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "آخری تاریخ" : "To Date",
                        prefixIcon: const Icon(Icons.calendar_today, size: 16),
                        border: const OutlineInputBorder(),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    onTap: () => _selectDate(context, false),
                  );

                  final clearBtn = IconButton(
                    icon: const Icon(Icons.clear, color: Colors.red),
                    tooltip: isUrduGlobal.value ? "فلٹر ختم کریں" : "Clear Filter",
                    onPressed: () {
                      setState(() {
                        selectedPeriod = 'all';
                        fromDate = null;
                        toDate = null;
                        _fromDateController.clear();
                        _toDateController.clear();
                      });
                      _loadFinance();
                    },
                  );

                  if (isWide) {
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        if (userDropdown != null) ...[
                          Expanded(flex: 3, child: userDropdown),
                          const SizedBox(width: 8),
                        ],
                        Expanded(flex: 3, child: periodDropdown),
                        const SizedBox(width: 8),
                        Expanded(flex: 2, child: fromDateField),
                        const SizedBox(width: 8),
                        Expanded(flex: 2, child: toDateField),
                        const SizedBox(width: 4),
                        clearBtn,
                      ],
                    );
                  } else {
                    return Column(
                      children: [
                        if (userDropdown != null) ...[
                          userDropdown,
                          const SizedBox(height: 8),
                        ],
                        periodDropdown,
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            Expanded(child: fromDateField),
                            const SizedBox(width: 8),
                            Expanded(child: toDateField),
                            clearBtn,
                          ],
                        ),
                      ],
                    );
                  }
                },
              ),
            ),

            // Summary Dashboard
            Padding(
              padding: const EdgeInsets.all(12.0),
              child: Row(
                children: [
                  Expanded(
                    child: Card(
                      color: Colors.green.shade50,
                      child: Padding(
                        padding: const EdgeInsets.all(10.0),
                        child: Column(
                          children: [
                            Text(
                                isUrduGlobal.value ? "کل آمدن" : "Total Income",
                                style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 12,
                                    color: Colors.green.shade800)),
                            const SizedBox(height: 5),
                            Text("Rs. ${totalIncome.toStringAsFixed(0)}",
                                style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.green.shade900)),
                          ],
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: Card(
                      color: Colors.red.shade50,
                      child: Padding(
                        padding: const EdgeInsets.all(10.0),
                        child: Column(
                          children: [
                            Text(
                                isUrduGlobal.value ? "کل خرچ" : "Total Expense",
                                style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 12,
                                    color: Colors.red.shade800)),
                            const SizedBox(height: 5),
                            Text("Rs. ${totalExpense.toStringAsFixed(0)}",
                                style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.red.shade900)),
                          ],
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: Card(
                      color: (totalIncome - totalExpense) >= 0
                          ? Colors.blue.shade50
                          : Colors.orange.shade50,
                      child: Padding(
                        padding: const EdgeInsets.all(10.0),
                        child: Column(
                          children: [
                            Text(
                                isUrduGlobal.value
                                    ? ((totalIncome - totalExpense) >= 0
                                        ? "کل بچت"
                                        : "کل خسارہ")
                                    : ((totalIncome - totalExpense) >= 0
                                        ? "Net Savings"
                                        : "Deficit"),
                                style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 12,
                                    color: (totalIncome - totalExpense) >= 0
                                        ? Colors.blue.shade800
                                        : Colors.red.shade800)),
                            const SizedBox(height: 5),
                            Text(
                                "Rs. ${(totalIncome - totalExpense).abs().toStringAsFixed(0)}",
                                style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    color: (totalIncome - totalExpense) >= 0
                                        ? Colors.blue.shade900
                                        : Colors.red.shade900)),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),

            // List
            Expanded(
              child: isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _financeRecords.isEmpty
                      ? Center(
                          child: Text(isUrduGlobal.value
                              ? "کوئی ریکارڈ نہیں ملا"
                              : "No records found"))
                      : (currentUserRole.value == 'admin' ||
                              currentUserRole.value == 'nazim')
                          ? _buildAdminGroupedList()
                          : _buildFlatList(),
            ),
          ],
        ),
      );
    }),
  );
  }

  Widget _buildFlatList() {
    return ListView.builder(
      itemCount: _financeRecords.length,
      itemBuilder: (context, index) {
        final f = _financeRecords[index];
        bool isInc = (f['type']?.toString().toLowerCase() == 'income');
        return _transactionCard(f, isInc);
      },
    );
  }

  Widget _buildAdminGroupedList() {
    return ListView(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      children: _userGroups.entries.map((entry) {
        final uid = entry.key;
        final ug = entry.value;
        final uname = ug['name'] as String;
        final incomeList = (ug['income'] as List).cast<Map<String, dynamic>>();
        final expenseList =
            (ug['expense'] as List).cast<Map<String, dynamic>>();

        double uInc = incomeList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
        double uExp = expenseList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
        double uBal = uInc - uExp;
        final isExpanded = _expandedUsers.contains(uid);

        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          elevation: 3,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              InkWell(
                onTap: () {
                  setState(() {
                    if (isExpanded) {
                      _expandedUsers.remove(uid);
                    } else {
                      _expandedUsers.add(uid);
                    }
                  });
                },
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  color: primaryGreen,
                  child: Row(
                    children: [
                      const Icon(Icons.person, color: Colors.white70, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          uname,
                          style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 15),
                        ),
                      ),
                      _adminBadge(isUrduGlobal.value ? 'آمدن' : 'In', uInc,
                          Colors.greenAccent.shade700),
                      const SizedBox(width: 6),
                      _adminBadge(isUrduGlobal.value ? 'خرچ' : 'Exp', uExp,
                          Colors.redAccent),
                      const SizedBox(width: 6),
                      _adminBadge(
                          isUrduGlobal.value
                              ? (uBal >= 0 ? 'بچت' : 'خسارہ')
                              : (uBal >= 0 ? 'Savings' : 'Deficit'),
                          uBal.abs(),
                          uBal >= 0
                              ? Colors.lightBlueAccent
                              : Colors.redAccent),
                      const SizedBox(width: 6),
                      Icon(isExpanded ? Icons.expand_less : Icons.expand_more,
                          color: Colors.white70),
                    ],
                  ),
                ),
              ),
              if (isExpanded) ...[
                if (incomeList.isNotEmpty) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(8),
                    color: Colors.green.shade50,
                    child: Text(isUrduGlobal.value ? 'آمدن' : 'Income',
                        style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: Colors.green.shade800)),
                  ),
                  ...incomeList.map((r) => _transactionCard(r, true)),
                ],
                if (expenseList.isNotEmpty) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(8),
                    color: Colors.red.shade50,
                    child: Text(isUrduGlobal.value ? 'خرچ' : 'Expense',
                        style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: Colors.red.shade800)),
                  ),
                  ...expenseList.map((r) => _transactionCard(r, false)),
                ],
              ],
            ],
          ),
        );
      }).toList(),
    );
  }

  Widget _adminBadge(String label, double amount, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.15),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withOpacity(0.6)),
      ),
      child: Column(
        children: [
          Text(label,
              style: TextStyle(
                  color: color, fontSize: 8, fontWeight: FontWeight.bold)),
          Text(amount.toStringAsFixed(0),
              style: TextStyle(
                  color: color, fontSize: 10, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }

  String _compactFinanceText(dynamic value) {
    return value?.toString().trim() ?? '';
  }

  String _valueAfterLabel(String text, List<String> labels) {
    for (final label in labels) {
      final index = text.indexOf(label);
      if (index == -1) continue;
      var value = text.substring(index + label.length).trim();
      if (value.startsWith(':') || value.startsWith('：')) {
        value = value.substring(1).trim();
      }
      final slashIndex = value.indexOf('/');
      if (slashIndex != -1) value = value.substring(0, slashIndex).trim();
      return value;
    }
    return '';
  }

  String _incomeName(Map<String, dynamic> f) {
    final donorName = _compactFinanceText(f['donor_name']);
    if (donorName.isNotEmpty) return donorName;
    final description = _compactFinanceText(f['description']);
    return _valueAfterLabel(description, const ['نام', 'Name']);
  }

  String _incomeHead(Map<String, dynamic> f) {
    final description = _compactFinanceText(f['description']);
    final head = _valueAfterLabel(description, const ['مد', 'Head']);
    if (head.isNotEmpty) return head;
    final slashIndex = description.indexOf('/');
    return slashIndex == -1
        ? description
        : description.substring(0, slashIndex).trim();
  }

  Widget _transactionCard(Map<String, dynamic> f, bool isInc) {
    final amountText = "Rs. ${f['amount']}";
    final dateText = _compactFinanceText(f['date']);
    final descriptionText = _compactFinanceText(f['description']);
    final receiverText = _compactFinanceText(f['user_name']);
    final incomeName = _incomeName(f);

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      elevation: 1,
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: isInc ? Colors.green.shade100 : Colors.red.shade100,
          child: Icon(
            isInc ? Icons.arrow_downward : Icons.arrow_upward,
            color: isInc ? Colors.green.shade800 : Colors.red.shade800,
          ),
        ),
        title: isInc
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    incomeName.isEmpty ? '-' : incomeName,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: Colors.green.shade900,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    amountText,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: Colors.green.shade900,
                    ),
                  ),
                ],
              )
            : Text(amountText,
                style: TextStyle(
                    fontWeight: FontWeight.bold, color: Colors.red.shade900)),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 4),
            Text(dateText,
                style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
            const SizedBox(height: 2),
            if (isInc) ...[
              Text(_incomeHead(f), style: const TextStyle(fontSize: 13)),
              if (receiverText.isNotEmpty)
                Text(receiverText, style: const TextStyle(fontSize: 13)),
            ] else
              Text(descriptionText, style: const TextStyle(fontSize: 13)),
          ],
        ),
        isThreeLine: true,
      ),
    );
  }
}
