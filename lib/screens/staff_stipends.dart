// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';

class StaffStipendsPage extends StatefulWidget {
  const StaffStipendsPage({super.key});

  @override
  _StaffStipendsPageState createState() => _StaffStipendsPageState();
}

class _StaffStipendsPageState extends State<StaffStipendsPage> {
  final DBHelper _db = DBHelper();
  List<Map<String, dynamic>> _payrollList = [];
  List<Map<String, dynamic>> _teachersList = [];
  List<String> _monthsList = [];

  bool _isLoading = true;
  String? _selectedTeacherId; // null = all teachers
  String? _selectedMonth; // null = all months
  String _selectedStatus = 'all'; // 'all', 'Paid', 'Unpaid'

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final dbInst = await _db.database;
      final sys = SystemTypeGlobal.currentSystemType.value;

      // Ensure acc_payroll exists with all columns
      try {
        await dbInst.execute('''
          CREATE TABLE IF NOT EXISTS acc_payroll(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER,
            month_year TEXT,
            basic_salary REAL DEFAULT 0,
            allowances REAL DEFAULT 0,
            deductions REAL DEFAULT 0,
            net_salary REAL DEFAULT 0,
            status TEXT DEFAULT 'Unpaid',
            payment_date TEXT,
            created_by INTEGER DEFAULT 1,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');
      } catch (_) {}

      try {
        await dbInst.execute("ALTER TABLE acc_payroll ADD COLUMN system_type TEXT DEFAULT 'Madrasa'");
      } catch (_) {}
      try {
        await dbInst.execute("ALTER TABLE acc_payroll ADD COLUMN created_by INTEGER DEFAULT 1");
      } catch (_) {}

      // Load Teachers robustly
      final allTeachers = await _db.getTeachers();
      List<Map<String, dynamic>> teachers = allTeachers.where((t) {
        final st = (t['status'] ?? '').toString().trim().toLowerCase();
        return st != 'inactive' && st != 'terminated' && st != 'فارغ' && st != 'برطرف';
      }).toList();
      if (teachers.isEmpty) {
        teachers = allTeachers;
      }
      if (teachers.isEmpty) {
        teachers = await dbInst.query(
          'teachers',
          where: "(is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: 'name ASC',
        );
      }

      // Load Distinct Months
      final monthsQuery = await dbInst.rawQuery(
        "SELECT DISTINCT month_year FROM acc_payroll WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = ? OR system_type IS NULL OR system_type = '') ORDER BY month_year DESC",
        [sys],
      );
      var months = monthsQuery.map((m) => m['month_year']?.toString() ?? '').where((m) => m.isNotEmpty).toList();
      if (months.isEmpty) {
        final fallbackMonthsQuery = await dbInst.rawQuery(
          "SELECT DISTINCT month_year FROM acc_payroll WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY month_year DESC",
        );
        months = fallbackMonthsQuery.map((m) => m['month_year']?.toString() ?? '').where((m) => m.isNotEmpty).toList();
      }

      // Base Payroll Query
      List<String> whereClauses = [
        "(p.is_deleted = 0 OR p.is_deleted IS NULL)",
        "(p.system_type = ? OR p.system_type IS NULL OR p.system_type = '')"
      ];
      List<dynamic> whereArgs = [sys];

      if (_selectedTeacherId != null && _selectedTeacherId!.isNotEmpty) {
        whereClauses.add("p.teacher_id = ?");
        whereArgs.add(int.tryParse(_selectedTeacherId!) ?? 0);
      }
      if (_selectedMonth != null && _selectedMonth!.isNotEmpty) {
        whereClauses.add("p.month_year = ?");
        whereArgs.add(_selectedMonth);
      }
      if (_selectedStatus != 'all') {
        whereClauses.add("p.status = ?");
        whereArgs.add(_selectedStatus);
      }

      final sql = '''
        SELECT p.*, t.name as teacher_name, t.designation as teacher_desig, t.phone as teacher_phone
        FROM acc_payroll p
        LEFT JOIN teachers t ON p.teacher_id = t.id
        WHERE ${whereClauses.join(' AND ')}
        ORDER BY p.month_year DESC, p.id DESC
      ''';

      var payrolls = await dbInst.rawQuery(sql, whereArgs);
      if (payrolls.isEmpty && _selectedTeacherId == null && _selectedMonth == null && _selectedStatus == 'all') {
        // Fallback to fetch all non-deleted payrolls
        const fallbackSql = '''
          SELECT p.*, t.name as teacher_name, t.designation as teacher_desig, t.phone as teacher_phone
          FROM acc_payroll p
          LEFT JOIN teachers t ON p.teacher_id = t.id
          WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL)
          ORDER BY p.month_year DESC, p.id DESC
        ''';
        payrolls = await dbInst.rawQuery(fallbackSql);
      }

      // Enrich teacher names in memory in case left join missed due to type or filter
      final teacherMap = <String, Map<String, dynamic>>{};
      for (var t in allTeachers) {
        final tid = t['id']?.toString() ?? '';
        if (tid.isNotEmpty) teacherMap[tid] = t;
      }
      final allDbTeachers = await dbInst.query('teachers', where: '(is_deleted = 0 OR is_deleted IS NULL)');
      for (var t in allDbTeachers) {
        final tid = t['id']?.toString() ?? '';
        if (tid.isNotEmpty && !teacherMap.containsKey(tid)) teacherMap[tid] = t;
      }

      final enrichedPayrolls = <Map<String, dynamic>>[];
      for (var row in payrolls) {
        var p = Map<String, dynamic>.from(row);
        final tid = p['teacher_id']?.toString() ?? '';
        final currentTName = p['teacher_name']?.toString().trim() ?? '';
        if ((currentTName.isEmpty || currentTName == 'Staff' || currentTName == 'Unknown') && teacherMap.containsKey(tid)) {
          p['teacher_name'] = teacherMap[tid]!['name'] ?? 'Staff';
          p['teacher_desig'] = teacherMap[tid]!['designation'] ?? '';
          p['teacher_phone'] = teacherMap[tid]!['phone'] ?? '';
        }
        enrichedPayrolls.add(p);
      }

      if (mounted) {
        setState(() {
          _teachersList = teachers;
          _monthsList = months;
          _payrollList = enrichedPayrolls;
        });
      }
    } catch (e) {
      debugPrint("Error loading payroll data: $e");
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  // --- Payroll Generation ---
  Future<void> _generatePayrollForMonth(String monthYear) async {
    final dbInst = await _db.database;
    final sys = SystemTypeGlobal.currentSystemType.value;

    final allTeachers = await _db.getTeachers();
    List<Map<String, dynamic>> teachers = allTeachers.where((t) {
      final st = (t['status'] ?? '').toString().trim().toLowerCase();
      return st != 'inactive' && st != 'terminated' && st != 'فارغ' && st != 'برطرف';
    }).toList();
    if (teachers.isEmpty) {
      teachers = allTeachers;
    }
    if (teachers.isEmpty) {
      teachers = await dbInst.query(
        'teachers',
        where: "(is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: 'name ASC',
      );
    }

    int count = 0;
    for (var t in teachers) {
      int tId = int.tryParse(t['id']?.toString() ?? '') ?? 0;
      if (tId <= 0) continue;

      final existing = await dbInst.query(
        'acc_payroll',
        where: "teacher_id = ? AND month_year = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
        whereArgs: [tId, monthYear],
      );

      if (existing.isEmpty) {
        double basic = double.tryParse((t['basic_salary'] ?? t['salary'] ?? 0).toString()) ?? 0.0;
        double houseRent = double.tryParse((t['house_rent'] ?? 0).toString()) ?? 0.0;
        double med = double.tryParse((t['medical_allowance'] ?? 0).toString()) ?? 0.0;
        double travel = double.tryParse((t['travel_allowance'] ?? 0).toString()) ?? 0.0;
        double allowances = houseRent + med + travel;

        double dailyRate = double.tryParse((t['daily_deduction_rate'] ?? 0).toString()) ?? 0.0;
        final attList = await dbInst.rawQuery(
          "SELECT COUNT(*) as cnt FROM attendance_teachers WHERE teacher_id = ? AND date LIKE ? AND status = 'A'",
          [tId, '$monthYear%'],
        );
        int absences = attList.isNotEmpty ? (attList.first['cnt'] as int? ?? 0) : 0;
        double deductions = absences * dailyRate;

        double net = basic + allowances - deductions;
        if (net < 0) net = 0;

        await dbInst.insert('acc_payroll', {
          'teacher_id': tId,
          'month_year': monthYear,
          'basic_salary': basic,
          'allowances': allowances,
          'deductions': deductions,
          'net_salary': net,
          'status': 'Unpaid',
          'created_by': currentUserId.value ?? 1,
          'system_type': sys,
          'is_deleted': 0,
          'is_synced': 0,
        });
        count++;
      }
    }

    await _loadData();

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          isUrduGlobal.value
              ? "ماہ $monthYear کے لیے $count اساتذہ کا وظیفہ پے رول تیار کر دیا گیا!"
              : "Payroll generated for $monthYear ($count records created)!",
        ),
        backgroundColor: Colors.green,
      ),
    );
  }

  void _showGenerateDialog() {
    DateTime targetDate = DateTime.now();
    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDlgState) {
            String formattedMonth = DateFormat('yyyy-MM').format(targetDate);
            String displayMonth = DateFormat('MMMM yyyy').format(targetDate);

            return AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              title: Row(
                children: [
                  const Icon(Icons.calculate, color: Colors.teal),
                  const SizedBox(width: 8),
                  Text(isUrduGlobal.value ? "ماہانہ پے رول جنریشن" : "Generate Payroll", style: const TextStyle(fontWeight: FontWeight.bold)),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    isUrduGlobal.value ? "منتخب کردہ مہینہ:" : "Selected Month:",
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    decoration: BoxDecoration(
                      color: Colors.teal.shade50,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.teal.shade200),
                    ),
                    child: Text(
                      displayMonth,
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.teal),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      OutlinedButton.icon(
                        icon: const Icon(Icons.chevron_left, size: 18),
                        label: Text(isUrduGlobal.value ? "سابقہ" : "Prev"),
                        onPressed: () {
                          setDlgState(() {
                            targetDate = DateTime(targetDate.year, targetDate.month - 1, 1);
                          });
                        },
                      ),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(backgroundColor: Colors.teal, foregroundColor: Colors.white),
                        onPressed: () {
                          setDlgState(() {
                            targetDate = DateTime.now();
                          });
                        },
                        child: Text(isUrduGlobal.value ? "رواں" : "Current"),
                      ),
                      OutlinedButton.icon(
                        icon: const Icon(Icons.chevron_right, size: 18),
                        label: Text(isUrduGlobal.value ? "آئندہ" : "Next"),
                        onPressed: () {
                          setDlgState(() {
                            targetDate = DateTime(targetDate.year, targetDate.month + 1, 1);
                          });
                        },
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Text(
                    isUrduGlobal.value
                        ? "یہ عمل تمام فعال اساتذہ کے لیے بنیادی تنخواہ اور حاضری کی بنیاد پر پے رول تیار کرے گا۔"
                        : "This will calculate base salary and attendance deductions for all active staff.",
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel"),
                ),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.teal, foregroundColor: Colors.white),
                  icon: const Icon(Icons.check),
                  label: Text(isUrduGlobal.value ? "پے رول بنائیں" : "Generate"),
                  onPressed: () {
                    Navigator.pop(ctx);
                    _generatePayrollForMonth(formattedMonth);
                  },
                ),
              ],
            );
          },
        );
      },
    );
  }

  void _showAddSingleStipendDialog() {
    if (_teachersList.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value ? "کوئی استاد موجود نہیں ہے" : "No teachers found"),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    String? selectedTId = _teachersList.first['id']?.toString();
    DateTime targetDate = DateTime.now();
    final basicCtrl = TextEditingController();
    final allowCtrl = TextEditingController(text: '0');
    final dedCtrl = TextEditingController(text: '0');
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    String status = 'Unpaid';

    void updateSalaryForTeacher(String? tId) {
      if (tId == null) return;
      final t = _teachersList.firstWhere((x) => x['id']?.toString() == tId, orElse: () => {});
      if (t.isNotEmpty) {
        double b = ((t['basic_salary'] ?? t['salary'] ?? 0) as num).toDouble();
        double houseRent = ((t['house_rent'] ?? 0) as num).toDouble();
        double med = ((t['medical_allowance'] ?? 0) as num).toDouble();
        double travel = ((t['travel_allowance'] ?? 0) as num).toDouble();
        basicCtrl.text = b.toStringAsFixed(0);
        allowCtrl.text = (houseRent + med + travel).toStringAsFixed(0);
      }
    }

    updateSalaryForTeacher(selectedTId);

    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDlgState) {
            String formattedMonth = DateFormat('yyyy-MM').format(targetDate);
            double basic = double.tryParse(basicCtrl.text) ?? 0.0;
            double allow = double.tryParse(allowCtrl.text) ?? 0.0;
            double ded = double.tryParse(dedCtrl.text) ?? 0.0;
            double net = basic + allow - ded;
            if (net < 0) net = 0;

            return AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              title: Row(
                children: [
                  const Icon(Icons.person_add_alt_1, color: Colors.teal),
                  const SizedBox(width: 8),
                  Text(isUrduGlobal.value ? "انفرادی وظیفہ اندراج" : "Add Individual Stipend", style: const TextStyle(fontWeight: FontWeight.bold)),
                ],
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    DropdownButtonFormField<String>(
                      value: selectedTId,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "استاد منتخب کریں" : "Select Teacher",
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      items: _teachersList.map((t) => DropdownMenuItem<String>(
                        value: t['id']?.toString(),
                        child: Text(t['name'] ?? ''),
                      )).toList(),
                      onChanged: (v) {
                        setDlgState(() {
                          selectedTId = v;
                          updateSalaryForTeacher(v);
                        });
                      },
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: Text("${isUrduGlobal.value ? 'مہینہ:' : 'Month:'} $formattedMonth", style: const TextStyle(fontWeight: FontWeight.bold)),
                        ),
                        IconButton(
                          icon: const Icon(Icons.calendar_month, color: Colors.teal),
                          onPressed: () async {
                            final pd = await showDatePicker(
                              context: ctx,
                              initialDate: targetDate,
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2030),
                            );
                            if (pd != null) {
                              setDlgState(() {
                                targetDate = pd;
                              });
                            }
                          },
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      controller: basicCtrl,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "بنیادی تنخواہ / وظیفہ" : "Basic Salary",
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      onChanged: (_) => setDlgState(() {}),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: allowCtrl,
                            keyboardType: TextInputType.number,
                            decoration: InputDecoration(
                              labelText: isUrduGlobal.value ? "الاؤنسز (+)" : "Allowances",
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                            ),
                            onChanged: (_) => setDlgState(() {}),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: TextField(
                            controller: dedCtrl,
                            keyboardType: TextInputType.number,
                            decoration: InputDecoration(
                              labelText: isUrduGlobal.value ? "کٹوتیاں (-)" : "Deductions",
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                            ),
                            onChanged: (_) => setDlgState(() {}),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: status,
                      decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "سٹیٹس" : "Status",
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      items: [
                        DropdownMenuItem(value: 'Unpaid', child: Text(isUrduGlobal.value ? "غیر ادا شدہ (Unpaid)" : "Unpaid")),
                        DropdownMenuItem(value: 'Paid', child: Text(isUrduGlobal.value ? "ادا شدہ (Paid)" : "Paid")),
                      ],
                      onChanged: (v) => setDlgState(() => status = v ?? 'Unpaid'),
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(color: Colors.teal.shade50, borderRadius: BorderRadius.circular(8)),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(isUrduGlobal.value ? "خالص ادائیگی:" : "Net Payable:", style: const TextStyle(fontWeight: FontWeight.bold)),
                          Text("Rs. ${net.toStringAsFixed(0)}", style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.teal, fontSize: 18)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.teal, foregroundColor: Colors.white),
                  onPressed: () async {
                    if (selectedTId == null) return;
                    Navigator.pop(ctx);
                    final dbInst = await _db.database;
                    final sys = SystemTypeGlobal.currentSystemType.value;
                    int tId = int.tryParse(selectedTId!) ?? 0;
                    
                    final existing = await dbInst.query(
                      'acc_payroll',
                      where: "teacher_id = ? AND month_year = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
                      whereArgs: [tId, formattedMonth],
                    );

                    if (existing.isNotEmpty) {
                      await dbInst.update(
                        'acc_payroll',
                        {
                          'basic_salary': basic,
                          'allowances': allow,
                          'deductions': ded,
                          'net_salary': net,
                          'status': status,
                          'payment_date': status == 'Paid' ? dateCtrl.text : null,
                          'is_synced': 0,
                        },
                        where: 'id = ?',
                        whereArgs: [existing.first['id']],
                      );
                    } else {
                      await dbInst.insert('acc_payroll', {
                        'teacher_id': tId,
                        'month_year': formattedMonth,
                        'basic_salary': basic,
                        'allowances': allow,
                        'deductions': ded,
                        'net_salary': net,
                        'status': status,
                        'payment_date': status == 'Paid' ? dateCtrl.text : null,
                        'created_by': currentUserId.value ?? 1,
                        'system_type': sys,
                        'is_deleted': 0,
                        'is_synced': 0,
                      });
                    }

                    if (status == 'Paid') {
                      try {
                        String tName = 'Staff';
                        final tRow = _teachersList.firstWhere((t) => t['id'].toString() == selectedTId, orElse: () => {});
                        if (tRow.isNotEmpty && tRow['name'] != null) {
                          tName = tRow['name'].toString();
                        }
                        final uName = currentUserName.value ?? 'Admin';
                        final finData = {
                          'user_id': currentUserId.value ?? 1,
                          'user_name': uName,
                          'type': 'Expense',
                          'head_id': 0,
                          'head_name': isUrduGlobal.value ? 'اساتذہ وظائف (Salaries)' : 'Staff Salaries',
                          'amount': net,
                          'date': dateCtrl.text,
                          'donor_name': tName,
                          'spender_name': tName,
                          'description': isUrduGlobal.value
                              ? "وظیفہ/تنخواہ ادا کی گئی برائے ماہ $formattedMonth: $tName"
                              : "Staff Salary Paid for $formattedMonth: $tName",
                          'system_type': sys,
                          'is_deleted': 0,
                          'is_synced': 0,
                        };
                        await _db.insertFinance(finData);
                        if (sys == 'Masjid') {
                          try {
                            await dbInst.insert('masjid_finance', finData);
                          } catch (_) {}
                        }
                      } catch (_) {}
                    }

                    _loadData();
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(isUrduGlobal.value ? "وظیفہ کامیابی سے محفوظ ہو گیا!" : "Stipend saved successfully!"),
                        backgroundColor: Colors.green,
                      ),
                    );
                  },
                  child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save"),
                ),
              ],
            );
          },
        );
      },
    );
  }

  // --- Mark Paid with Finance Entry ---
  Future<void> _markPaid(Map<String, dynamic> item) async {
    final dbInst = await _db.database;
    final sys = SystemTypeGlobal.currentSystemType.value;
    int tId = item['teacher_id'] ?? 0;

    // Fetch active loan
    final loanRows = await dbInst.query(
      'staff_loans',
      where: 'teacher_id = ? AND status = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = ? OR system_type IS NULL OR system_type = "")',
      whereArgs: [tId, 'Active', sys],
      orderBy: 'id ASC',
      limit: 1,
    );

    double maxLoan = 0.0;
    double defLoan = 0.0;
    int? activeLoanId;
    if (loanRows.isNotEmpty) {
      final l = loanRows.first;
      maxLoan = ((l['remaining_amount'] ?? 0) as num).toDouble();
      defLoan = ((l['installment_amount'] ?? 0) as num).toDouble();
      activeLoanId = l['id'] as int?;
      if (defLoan > maxLoan) defLoan = maxLoan;
    }

    double basic = ((item['basic_salary'] ?? 0) as num).toDouble();
    
    final allowCtrl = TextEditingController(text: item['allowances']?.toString() ?? '0');
    final dedCtrl = TextEditingController(text: item['deductions']?.toString() ?? '0');
    final loanCtrl = TextEditingController(text: defLoan.toString());
    
    DateTime selectedDate = DateTime.now();
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(selectedDate));

    await showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setStateSB) {
            double allow = double.tryParse(allowCtrl.text) ?? 0.0;
            double ded = double.tryParse(dedCtrl.text) ?? 0.0;
            double loanDed = double.tryParse(loanCtrl.text) ?? 0.0;
            
            double net = basic + allow - ded - loanDed;
            if (net < 0) net = 0;

            void recalc() {
              setStateSB(() {});
            }

            return AlertDialog(
              title: Text(isUrduGlobal.value ? "ادائیگی کی تفصیل اور کٹوتیاں" : "Confirm Payment"),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text("${item['teacher_name']} (${item['month_year']})", style: const TextStyle(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 10),
                    TextField(
                      controller: dateCtrl,
                      readOnly: true,
                      decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "تاریخ ادائیگی" : "Payment Date",
                        suffixIcon: const Icon(Icons.calendar_today),
                      ),
                      onTap: () async {
                        final pd = await showDatePicker(
                          context: ctx,
                          initialDate: selectedDate,
                          firstDate: DateTime(2020),
                          lastDate: DateTime(2030),
                        );
                        if (pd != null) {
                          selectedDate = pd;
                          dateCtrl.text = DateFormat('yyyy-MM-dd').format(pd);
                          recalc();
                        }
                      },
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(child: TextField(
                          controller: allowCtrl,
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: isUrduGlobal.value ? "الاؤنسز (+)" : "Allowances"),
                          onChanged: (_) => recalc(),
                        )),
                        const SizedBox(width: 10),
                        Expanded(child: TextField(
                          controller: dedCtrl,
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: isUrduGlobal.value ? "کٹوتیاں (-)" : "Deductions"),
                          onChanged: (_) => recalc(),
                        )),
                      ],
                    ),
                    const SizedBox(height: 10),
                    if (maxLoan > 0) ...[
                      TextField(
                        controller: loanCtrl,
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(
                          labelText: isUrduGlobal.value ? "قرض قسط کٹوتی (-)" : "Loan Deduction",
                          helperText: isUrduGlobal.value ? "بقایا قرض: $maxLoan" : "Max remaining: $maxLoan",
                          helperStyle: const TextStyle(color: Colors.red),
                        ),
                        onChanged: (_) => recalc(),
                      ),
                      const SizedBox(height: 10),
                    ] else ...[
                      Text(isUrduGlobal.value ? "اساتذہ کا کوئی ایکٹو قرض نہیں ہے" : "No active loan found", style: const TextStyle(color: Colors.grey, fontSize: 12)),
                      const SizedBox(height: 10),
                    ],
                    const Divider(),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(isUrduGlobal.value ? "خالص ادائیگی:" : "Net Payable:", style: const TextStyle(fontWeight: FontWeight.bold)),
                        Text("Rs. ${net.toStringAsFixed(0)}", style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.blue, fontSize: 18)),
                      ],
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white),
                  onPressed: () async {
                    Navigator.pop(ctx);
                    
                    double finalAllow = double.tryParse(allowCtrl.text) ?? 0.0;
                    double finalDed = double.tryParse(dedCtrl.text) ?? 0.0;
                    double finalLoan = double.tryParse(loanCtrl.text) ?? 0.0;
                    double totalDed = finalDed + finalLoan;
                    
                    double finalNet = basic + finalAllow - totalDed;
                    if (finalNet < 0) finalNet = 0;

                    String tName = item['teacher_name'] ?? 'Staff';
                    String mYear = item['month_year'] ?? '';

                    await dbInst.update(
                      'acc_payroll',
                      {'allowances': finalAllow, 'deductions': totalDed, 'net_salary': finalNet, 'status': 'Paid', 'payment_date': dateCtrl.text, 'is_synced': 0},
                      where: 'id = ?',
                      whereArgs: [item['id']],
                    );

                    // Insert into Finance Expense
                    try {
                      final uName = currentUserName.value ?? 'Admin';
                      final finData = {
                        'user_id': currentUserId.value ?? 1,
                        'user_name': uName,
                        'type': 'Expense',
                        'head_id': 0,
                        'head_name': isUrduGlobal.value ? 'اساتذہ وظائف (Salaries)' : 'Staff Salaries',
                        'amount': finalNet,
                        'date': dateCtrl.text,
                        'donor_name': tName,
                        'spender_name': tName,
                        'description': isUrduGlobal.value
                            ? "وظیفہ/تنخواہ ادا کی گئی برائے ماہ $mYear: $tName"
                            : "Staff Salary Paid for $mYear: $tName",
                        'system_type': sys,
                        'is_deleted': 0,
                        'is_synced': 0,
                      };
                      await _db.insertFinance(finData);
                      if (sys == 'Masjid') {
                        try {
                          await dbInst.insert('masjid_finance', finData);
                        } catch (_) {}
                      }
                    } catch (_) {}

                    if (finalLoan > 0 && activeLoanId != null) {
                       // Record Loan Recovery
                       try {
                         final uName = currentUserName.value ?? 'Admin';
                         final loanData = {
                           'user_id': currentUserId.value ?? 1,
                           'user_name': uName,
                           'type': 'Income',
                           'head_id': 0,
                           'head_name': isUrduGlobal.value ? 'قرض حسنہ (اساتذہ)' : 'Staff Loans',
                           'amount': finalLoan,
                           'date': dateCtrl.text,
                           'donor_name': tName,
                           'spender_name': tName,
                           'description': isUrduGlobal.value
                               ? "قرض وصولی از تنخواہ کٹوتی برائے ماہ $mYear: $tName"
                               : "Loan Recovery from Salary for $mYear: $tName",
                           'system_type': sys,
                           'is_deleted': 0,
                           'is_synced': 0,
                         };
                         await _db.insertFinance(loanData);
                         if (sys == 'Masjid') {
                           try {
                             await dbInst.insert('masjid_finance', loanData);
                           } catch (_) {}
                         }
                       } catch (_) {}

                       // Update Loan
                       double rem = maxLoan - finalLoan;
                       if (rem < 0) rem = 0;
                       String st = (rem == 0) ? 'Paid' : 'Active';
                       await dbInst.rawUpdate(
                         "UPDATE staff_loans SET remaining_amount = ?, repaid_amount = COALESCE(repaid_amount,0) + ?, status = ?, is_synced = 0 WHERE id = ?",
                         [rem, finalLoan, st, activeLoanId]
                       );
                    }

                    _loadData();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? "ادائیگی محفوظ ہو گئی اور فنانس میں درج ہو گئی" : "Payment Saved and posted to Finance"), backgroundColor: Colors.green));
                  },
                  child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save Payment"),
                ),
              ],
            );
          }
        );
      }
    );
  }

  // --- Mark Unpaid ---
  Future<void> _markUnpaid(Map<String, dynamic> item) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? "منسوخ ادائیگی" : "Mark Unpaid"),
        content: Text(isUrduGlobal.value ? "کیا آپ اس وظیفہ کو غیر ادا شدہ کرنا چاہتے ہیں؟" : "Revert status to Unpaid?"),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(isUrduGlobal.value ? "نہیں" : "No")),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(isUrduGlobal.value ? "ہاں" : "Yes")),
        ],
      ),
    );

    if (confirm != true) return;

    final dbInst = await _db.database;
    final mYear = item['month_year'] ?? '';
    final tName = item['teacher_name'] ?? '';
    final descLike = "%ماہ $mYear: $tName%";

    await dbInst.update(
      'acc_payroll',
      {'status': 'Unpaid', 'payment_date': null, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [item['id']],
    );

    try {
      await dbInst.rawUpdate(
        "UPDATE finance SET is_deleted = 1, is_synced = 0 WHERE type = 'Expense' AND description LIKE ?",
        [descLike],
      );
      await dbInst.rawUpdate(
        "UPDATE masjid_finance SET is_deleted = 1, is_synced = 0 WHERE type = 'Expense' AND description LIKE ?",
        [descLike],
      );
    } catch (_) {}

    _loadData();
  }

  // --- Edit Allowances/Deductions ---
  void _editAllowances(Map<String, dynamic> item) {
    final allowCtrl = TextEditingController(text: item['allowances']?.toString() ?? '0');
    final dedCtrl = TextEditingController(text: item['deductions']?.toString() ?? '0');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(isUrduGlobal.value ? "الاؤنس و کٹوتی ترمیم" : "Edit Allowances & Deductions"),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text("${item['teacher_name']} (${item['month_year']})", style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: allowCtrl,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "الاؤنسز (اضافہ)" : "Allowances (+)",
                border: const OutlineInputBorder(),
                prefixIcon: const Icon(Icons.add_circle, color: Colors.green),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: dedCtrl,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: isUrduGlobal.value ? "کٹوتیاں (منفی)" : "Deductions (-)",
                border: const OutlineInputBorder(),
                prefixIcon: const Icon(Icons.remove_circle, color: Colors.red),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.teal, foregroundColor: Colors.white),
            onPressed: () async {
              double basic = ((item['basic_salary'] ?? 0) as num).toDouble();
              double allow = double.tryParse(allowCtrl.text) ?? 0;
              double ded = double.tryParse(dedCtrl.text) ?? 0;
              double net = basic + allow - ded;
              if (net < 0) net = 0;

              final dbInst = await _db.database;
              await dbInst.update(
                'acc_payroll',
                {'allowances': allow, 'deductions': ded, 'net_salary': net, 'is_synced': 0},
                where: 'id = ?',
                whereArgs: [item['id']],
              );

              Navigator.pop(ctx);
              _loadData();
            },
            child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save"),
          ),
        ],
      ),
    );
  }

  // --- Delete Unpaid Entry ---
  Future<void> _deletePayroll(Map<String, dynamic> item) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? "ریکارڈ حذف کریں" : "Delete Record"),
        content: Text(isUrduGlobal.value ? "کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟" : "Delete this payroll record?"),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isUrduGlobal.value ? "حذف کریں" : "Delete"),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final dbInst = await _db.database;
    await dbInst.update(
      'acc_payroll',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [item['id']],
    );
    _loadData();
  }

  // --- Show Slip Dialog ---
  void _showSlipDialog(Map<String, dynamic> item) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? "تنخواہ کی رسید" : "Salary Slip"),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text("${isUrduGlobal.value ? 'نام:' : 'Name:'} ${item['teacher_name']}", style: const TextStyle(fontWeight: FontWeight.bold)),
            Text("${isUrduGlobal.value ? 'مہینہ:' : 'Month:'} ${item['month_year']}"),
            const Divider(),
            Text("${isUrduGlobal.value ? 'بنیادی تنخواہ:' : 'Basic:'} ${item['basic_salary']}"),
            Text("${isUrduGlobal.value ? 'الاؤنسز:' : 'Allowances:'} ${item['allowances']}", style: const TextStyle(color: Colors.green)),
            Text("${isUrduGlobal.value ? 'کٹوتیاں:' : 'Deductions:'} ${item['deductions']}", style: const TextStyle(color: Colors.red)),
            const Divider(),
            Text("${isUrduGlobal.value ? 'خالص ادائیگی:' : 'Net Salary:'} ${item['net_salary']}", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "بند کریں" : "Close")),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;
    double totalPayable = 0;
    double totalPaid = 0;
    double totalUnpaid = 0;
    int paidCount = 0;
    int unpaidCount = 0;

    for (var p in _payrollList) {
      double net = ((p['net_salary'] ?? 0) as num).toDouble();
      totalPayable += net;
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
        title: Text(
          isUrdu ? "وظائف و تنخواہیں مینیجمنٹ" : "Staff Stipends Management",
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: Colors.teal,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.person_add_alt_1),
            tooltip: isUrdu ? "انفرادی وظیفہ اندراج" : "Add Individual Stipend",
            onPressed: _showAddSingleStipendDialog,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: isUrdu ? "ریفریش" : "Refresh",
            onPressed: _loadData,
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: Colors.teal,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: Text(isUrdu ? "نیا پے رول / وظیفہ" : "New Payroll / Stipend", style: const TextStyle(fontWeight: FontWeight.bold)),
        onPressed: () {
          showModalBottomSheet(
            context: context,
            shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
            builder: (ctx) {
              return SafeArea(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ListTile(
                      leading: const CircleAvatar(backgroundColor: Colors.teal, child: Icon(Icons.calculate, color: Colors.white)),
                      title: Text(isUrdu ? "ماہانہ پے رول جنریشن (تمام اساتذہ)" : "Monthly Batch Payroll (All Staff)", style: const TextStyle(fontWeight: FontWeight.bold)),
                      subtitle: Text(isUrdu ? "ایک کلک پر پورے مہینے کا پے رول خودکار تیار کریں" : "Auto-generate batch payroll for selected month"),
                      onTap: () {
                        Navigator.pop(ctx);
                        _showGenerateDialog();
                      },
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const CircleAvatar(backgroundColor: Colors.indigo, child: Icon(Icons.person_add, color: Colors.white)),
                      title: Text(isUrdu ? "انفرادی وظیفہ اندراج" : "Individual Stipend Entry", style: const TextStyle(fontWeight: FontWeight.bold)),
                      subtitle: Text(isUrdu ? "کسی ایک استاد کا وظیفہ اور کٹوتیاں درج کریں" : "Enter individual teacher stipend and deductions"),
                      onTap: () {
                        Navigator.pop(ctx);
                        _showAddSingleStipendDialog();
                      },
                    ),
                  ],
                ),
              );
            },
          );
        },
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Top Summary Cards
                Container(
                  padding: const EdgeInsets.all(12),
                  color: Colors.teal.shade50,
                  child: Row(
                    children: [
                      _summaryCard(isUrdu ? "کل واجب الادا" : "Payable", "Rs. ${totalPayable.toStringAsFixed(0)}", Colors.indigo, "${_payrollList.length} ${isUrdu ? 'ریکارڈز' : 'Entries'}"),
                      const SizedBox(width: 8),
                      _summaryCard(isUrdu ? "کل ادا شدہ" : "Paid", "Rs. ${totalPaid.toStringAsFixed(0)}", Colors.green, "$paidCount ${isUrdu ? 'افراد' : 'Staff'}"),
                      const SizedBox(width: 8),
                      _summaryCard(isUrdu ? "بقایا واجب الادا" : "Pending", "Rs. ${totalUnpaid.toStringAsFixed(0)}", Colors.red, "$unpaidCount ${isUrdu ? 'افراد' : 'Staff'}"),
                    ],
                  ),
                ),

                // Filters Row (Staff & Month)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  child: Row(
                    children: [
                      // Staff Filter
                      Expanded(
                        child: DropdownButtonFormField<String?>(
                          value: _selectedTeacherId,
                          isExpanded: true,
                          decoration: InputDecoration(
                            labelText: isUrdu ? "استاد / ملازم فلٹر" : "Staff Filter",
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                            prefixIcon: const Icon(Icons.person, size: 20),
                          ),
                          items: [
                            DropdownMenuItem<String?>(
                              value: null,
                              child: Text(isUrdu ? "-- تمام اساتذہ --" : "-- All Staff --"),
                            ),
                            ..._teachersList.map((t) => DropdownMenuItem<String?>(
                                  value: t['id']?.toString(),
                                  child: Text(t['name'] ?? ''),
                                )),
                          ],
                          onChanged: (v) {
                            setState(() => _selectedTeacherId = v);
                            _loadData();
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      // Month Filter
                      Expanded(
                        child: DropdownButtonFormField<String?>(
                          value: _selectedMonth,
                          isExpanded: true,
                          decoration: InputDecoration(
                            labelText: isUrdu ? "ماہانہ فلٹر" : "Month Filter",
                            contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                            prefixIcon: const Icon(Icons.calendar_month, size: 20),
                          ),
                          items: [
                            DropdownMenuItem<String?>(
                              value: null,
                              child: Text(isUrdu ? "-- تمام مہینے --" : "-- All Months --"),
                            ),
                            ..._monthsList.map((m) => DropdownMenuItem<String?>(
                                  value: m,
                                  child: Text(m),
                                )),
                          ],
                          onChanged: (v) {
                            setState(() => _selectedMonth = v);
                            _loadData();
                          },
                        ),
                      ),
                    ],
                  ),
                ),

                // Status Chips Row
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  child: Row(
                    children: [
                      ChoiceChip(
                        label: Text(isUrdu ? "تمام" : "All"),
                        selected: _selectedStatus == 'all',
                        onSelected: (s) {
                          if (s) {
                            setState(() => _selectedStatus = 'all');
                            _loadData();
                          }
                        },
                      ),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: Text(isUrdu ? "صرف ادا شدہ (Paid)" : "Paid"),
                        selected: _selectedStatus == 'Paid',
                        selectedColor: Colors.green.shade100,
                        onSelected: (s) {
                          if (s) {
                            setState(() => _selectedStatus = 'Paid');
                            _loadData();
                          }
                        },
                      ),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: Text(isUrdu ? "صرف واجب الادا (Unpaid)" : "Unpaid"),
                        selected: _selectedStatus == 'Unpaid',
                        selectedColor: Colors.red.shade100,
                        onSelected: (s) {
                          if (s) {
                            setState(() => _selectedStatus = 'Unpaid');
                            _loadData();
                          }
                        },
                      ),
                    ],
                  ),
                ),
                const Divider(height: 16),

                // Main Payroll List
                Expanded(
                  child: _payrollList.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.receipt_long, size: 64, color: Colors.grey.shade400),
                              const SizedBox(height: 8),
                              Text(
                                isUrdu
                                    ? "کوئی وظیفہ ریکارڈ موجود نہیں ہے۔\n'نیا پے رول بنائیں' پر کلک کریں۔"
                                    : "No stipend records found.\nClick 'Generate Payroll' to start.",
                                textAlign: TextAlign.center,
                                style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.only(left: 12, right: 12, bottom: 80),
                          itemCount: _payrollList.length,
                          itemBuilder: (ctx, idx) {
                            final item = _payrollList[idx];
                            bool isPaid = item['status'] == 'Paid';

                            return Card(
                              margin: const EdgeInsets.symmetric(vertical: 6),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              elevation: 2,
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Row 1: Name & Month Badge & Status
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Expanded(
                                          child: Text(
                                            item['teacher_name'] ?? 'Staff',
                                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                          decoration: BoxDecoration(
                                            color: Colors.grey.shade200,
                                            borderRadius: BorderRadius.circular(6),
                                          ),
                                          child: Text(
                                            item['month_year'] ?? '',
                                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                          decoration: BoxDecoration(
                                            color: isPaid ? Colors.green.shade100 : Colors.red.shade100,
                                            borderRadius: BorderRadius.circular(6),
                                          ),
                                          child: Text(
                                            isPaid ? (isUrdu ? "ادا شدہ" : "PAID") : (isUrdu ? "واجب الادا" : "UNPAID"),
                                            style: TextStyle(
                                              fontSize: 12,
                                              fontWeight: FontWeight.bold,
                                              color: isPaid ? Colors.green.shade800 : Colors.red.shade800,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),

                                    // Row 2: Salary breakdown
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          "${isUrdu ? 'بنیادی:' : 'Basic:'} Rs. ${(item['basic_salary'] ?? 0).toString()}",
                                          style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                        ),
                                        Text(
                                          "${isUrdu ? 'الاؤنسز:' : 'Allow:'} +Rs. ${(item['allowances'] ?? 0).toString()}",
                                          style: const TextStyle(fontSize: 12, color: Colors.green),
                                        ),
                                        Text(
                                          "${isUrdu ? 'کٹوتیاں:' : 'Deduct:'} -Rs. ${(item['deductions'] ?? 0).toString()}",
                                          style: const TextStyle(fontSize: 12, color: Colors.red),
                                        ),
                                        Text(
                                          "${isUrdu ? 'خالص:' : 'Net:'} Rs. ${(item['net_salary'] ?? 0).toString()}",
                                          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.teal),
                                        ),
                                      ],
                                    ),
                                    if (item['payment_date'] != null)
                                      Padding(
                                        padding: const EdgeInsets.only(top: 4),
                                        child: Text(
                                          "${isUrdu ? 'تاریخ ادائیگی:' : 'Paid on:'} ${item['payment_date']}",
                                          style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                                        ),
                                      ),
                                    const Divider(height: 14),

                                    // Row 3: Action Buttons
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.end,
                                      children: [
                                        if (!isPaid) ...[
                                          ElevatedButton.icon(
                                            style: ElevatedButton.styleFrom(
                                              backgroundColor: Colors.green,
                                              foregroundColor: Colors.white,
                                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                            ),
                                            icon: const Icon(Icons.check_circle, size: 16),
                                            label: Text(isUrdu ? "ادائیگی کریں" : "Pay"),
                                            onPressed: () => _markPaid(item),
                                          ),
                                          const SizedBox(width: 6),
                                          IconButton(
                                            icon: const Icon(Icons.edit, size: 20, color: Colors.blue),
                                            tooltip: isUrdu ? "ترمیم" : "Edit",
                                            onPressed: () => _editAllowances(item),
                                          ),
                                          IconButton(
                                            icon: const Icon(Icons.delete, size: 20, color: Colors.red),
                                            tooltip: isUrdu ? "حذف" : "Delete",
                                            onPressed: () => _deletePayroll(item),
                                          ),
                                        ] else ...[
                                          OutlinedButton.icon(
                                            style: OutlinedButton.styleFrom(
                                              foregroundColor: Colors.orange.shade800,
                                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                            ),
                                            icon: const Icon(Icons.undo, size: 16),
                                            label: Text(isUrdu ? "منسوخ ادائیگی" : "Unmark"),
                                            onPressed: () => _markUnpaid(item),
                                          ),
                                        ],
                                        const SizedBox(width: 6),
                                        IconButton(
                                          icon: const Icon(Icons.receipt, size: 20, color: Colors.teal),
                                          tooltip: isUrdu ? "رسید / سلپ" : "Slip",
                                          onPressed: () => _showSlipDialog(item),
                                        ),
                                      ],
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

  Widget _summaryCard(String title, String val, Color color, String sub) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(10),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 4, offset: const Offset(0, 2))],
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey.shade600)),
            const SizedBox(height: 2),
            Text(val, style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: color)),
            const SizedBox(height: 2),
            Text(sub, style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
          ],
        ),
      ),
    );
  }
}
