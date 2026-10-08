// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';

class LoansManagementPage extends StatefulWidget {
  const LoansManagementPage({super.key});

  @override
  _LoansManagementPageState createState() => _LoansManagementPageState();
}

class _LoansManagementPageState extends State<LoansManagementPage> with SingleTickerProviderStateMixin {
  final DBHelper _db = DBHelper();
  late TabController _tabController;

  List<Map<String, dynamic>> _loansGivenList = [];
  List<Map<String, dynamic>> _loansTakenList = [];
  List<Map<String, dynamic>> _recoveriesList = [];
  List<Map<String, dynamic>> _repaymentsList = [];
  List<Map<String, dynamic>> _teachersList = [];
  List<Map<String, dynamic>> _paymentAccounts = [];

  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
    _tabController.addListener(() {
      if (mounted) setState(() {});
    });
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final dbInst = await _db.database;
      final sys = SystemTypeGlobal.currentSystemType.value;

      // Ensure staff_loans table exists
      try {
        await dbInst.execute('''
          CREATE TABLE IF NOT EXISTS staff_loans(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER,
            borrower_name TEXT,
            phone TEXT,
            loan_direction TEXT DEFAULT 'Given',
            loan_amount REAL DEFAULT 0,
            installment_amount REAL DEFAULT 0,
            repaid_amount REAL DEFAULT 0,
            remaining_amount REAL DEFAULT 0,
            date_issued TEXT,
            due_date TEXT,
            description TEXT,
            status TEXT DEFAULT 'Active',
            system_type TEXT DEFAULT 'Madrasa',
            account_id INTEGER,
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');
      } catch (_) {}

      // Ensure loan_transactions table exists
      try {
        await dbInst.execute('''
          CREATE TABLE IF NOT EXISTS loan_transactions(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            loan_id INTEGER,
            type TEXT NOT NULL,
            borrower_lender_name TEXT,
            amount REAL DEFAULT 0,
            date TEXT NOT NULL,
            payment_method TEXT DEFAULT 'Cash',
            account_id INTEGER,
            remarks TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            created_by INTEGER DEFAULT 1,
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');
      } catch (_) {}

      // Ensure columns exist
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN loan_direction TEXT DEFAULT 'Given'"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN borrower_name TEXT"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN repaid_amount REAL DEFAULT 0"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN due_date TEXT"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN phone TEXT"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN remaining_amount REAL DEFAULT 0"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN system_type TEXT DEFAULT 'Madrasa'"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE staff_loans ADD COLUMN account_id INTEGER"); } catch (_) {}
      try { await dbInst.execute("ALTER TABLE loan_transactions ADD COLUMN account_id INTEGER"); } catch (_) {}

      // Load Payment Asset Accounts
      final paymentAccounts = await _db.getPaymentAssetAccounts();

      // Load Teachers
      final teachers = await dbInst.query(
        'teachers',
        where: "(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = ? OR system_type IS NULL OR system_type = '')",
        whereArgs: [sys],
        orderBy: 'name ASC',
      );

      // Load Loans Given
      final given = await dbInst.rawQuery('''
        SELECT l.*, t.name as teacher_name, t.phone as teacher_phone
        FROM staff_loans l
        LEFT JOIN teachers t ON l.teacher_id = t.id
        WHERE (l.is_deleted = 0 OR l.is_deleted IS NULL) 
          AND (l.loan_direction = 'Given' OR l.loan_direction IS NULL OR l.loan_direction = '')
          AND (l.system_type = ? OR l.system_type IS NULL OR l.system_type = '')
        ORDER BY l.id DESC
      ''', [sys]);

      // Load Loans Taken
      final taken = await dbInst.rawQuery('''
        SELECT * FROM staff_loans 
        WHERE (is_deleted = 0 OR is_deleted IS NULL) 
          AND loan_direction = 'Taken'
          AND (system_type = ? OR system_type IS NULL OR system_type = '')
        ORDER BY id DESC
      ''', [sys]);

      // Load Loan Recoveries (قرض وصولی)
      final recoveries = await dbInst.rawQuery('''
        SELECT t.*, l.loan_amount, l.remaining_amount as current_remaining
        FROM loan_transactions t
        LEFT JOIN staff_loans l ON t.loan_id = l.id
        WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL)
          AND t.type = 'Recovery'
          AND (t.system_type = ? OR t.system_type IS NULL OR t.system_type = '')
        ORDER BY t.date DESC, t.id DESC
      ''', [sys]);

      // Load Loan Repayments (قرض واپسی / ادائیگی)
      final repayments = await dbInst.rawQuery('''
        SELECT t.*, l.loan_amount, l.remaining_amount as current_remaining
        FROM loan_transactions t
        LEFT JOIN staff_loans l ON t.loan_id = l.id
        WHERE (t.is_deleted = 0 OR t.is_deleted IS NULL)
          AND t.type = 'Repayment'
          AND (t.system_type = ? OR t.system_type IS NULL OR t.system_type = '')
        ORDER BY t.date DESC, t.id DESC
      ''', [sys]);

      if (mounted) {
        setState(() {
          _teachersList = teachers;
          _loansGivenList = given;
          _loansTakenList = taken;
          _recoveriesList = recoveries;
          _repaymentsList = repayments;
          _paymentAccounts = paymentAccounts;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
      debugPrint("Error loading loans data: $e");
    }
  }

  // --- 1. Dialog: Issue Loan Given ---
  void _showIssueGivenDialog() {
    final formKey = GlobalKey<FormState>();
    int? selectedTeacherId;
    int? selectedAccountId = _paymentAccounts.firstWhere(
      (a) => a['code']?.toString() == '1001',
      orElse: () => _paymentAccounts.isNotEmpty ? _paymentAccounts.first : {},
    )['id'] as int?;

    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final amountCtrl = TextEditingController();
    final instCtrl = TextEditingController();
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    final dueDateCtrl = TextEditingController();
    final descCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: Row(
            children: [
              const Icon(Icons.arrow_upward, color: Colors.blue),
              const SizedBox(width: 8),
              Text(isUrduGlobal.value ? "نیا قرض / ایڈوانس جاری کریں" : "Issue Loan (Given)", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          content: SingleChildScrollView(
            child: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "استاد / ملازم (اختیاری)" : "Staff Member (Optional)",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedTeacherId,
                    items: [
                      DropdownMenuItem<int?>(
                        value: null,
                        child: Text(isUrduGlobal.value ? "-- دیگر / بیرونی فرد --" : "-- Other / External Person --"),
                      ),
                      ..._teachersList.map((t) => DropdownMenuItem<int?>(
                            value: t['id'] as int,
                            child: Text(t['name'] ?? ''),
                          )),
                    ],
                    onChanged: (val) {
                      setDlgState(() {
                        selectedTeacherId = val;
                        if (val != null) {
                          final match = _teachersList.firstWhere((t) => t['id'] == val, orElse: () => {});
                          if (match.isNotEmpty) {
                            nameCtrl.text = match['name'] ?? '';
                            phoneCtrl.text = match['phone'] ?? '';
                          }
                        }
                      });
                    },
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: nameCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "وصول کنندہ کا نام *" : "Borrower Name *",
                      border: const OutlineInputBorder(),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? (isUrduGlobal.value ? "نام درج کریں" : "Name required") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: phoneCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "فون نمبر" : "Phone Number",
                      border: const OutlineInputBorder(),
                    ),
                    keyboardType: TextInputType.phone,
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: amountCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "قرض کی رقم *" : "Loan Amount *",
                            border: const OutlineInputBorder(),
                          ),
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          validator: (v) {
                            final val = double.tryParse(v ?? '');
                            if (val == null || val <= 0) return isUrduGlobal.value ? "درست رقم لکھیں" : "Valid amount required";
                            return null;
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextFormField(
                          controller: instCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "ماہانہ قسط" : "Monthly Inst.",
                            border: const OutlineInputBorder(),
                          ),
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "ادائیگی کھاتہ (رقم کس کھاتے سے ادا کی گئی؟) *" : "Payment Account *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedAccountId,
                    items: _paymentAccounts.map((a) {
                      final name = a['name']?.toString() ?? '';
                      final code = a['code']?.toString() ?? '';
                      return DropdownMenuItem<int?>(
                        value: a['id'] as int?,
                        child: Text("$name ($code)", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) => setDlgState(() => selectedAccountId = val),
                    validator: (v) => v == null ? (isUrduGlobal.value ? "کھاتہ منتخب کریں" : "Select account") : null,
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: dateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "تاریخ اجرا" : "Date Issued",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.calendar_today, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextFormField(
                          controller: dueDateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "واپسی تاریخ" : "Due Date",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.event, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now().add(const Duration(days: 30)),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dueDateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: descCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تفصیل / مقصد" : "Purpose / Description",
                      border: const OutlineInputBorder(),
                    ),
                    maxLines: 2,
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.blue, foregroundColor: Colors.white),
              onPressed: () async {
                if (!formKey.currentState!.validate()) return;
                final lAmt = double.tryParse(amountCtrl.text.trim()) ?? 0;
                final instAmt = double.tryParse(instCtrl.text.trim()) ?? 0;
                final dIss = dateCtrl.text.trim();
                final dDue = dueDateCtrl.text.trim();
                final sys = SystemTypeGlobal.currentSystemType.value;

                final dbInst = await _db.database;
                await dbInst.insert('staff_loans', {
                  'teacher_id': selectedTeacherId,
                  'borrower_name': nameCtrl.text.trim(),
                  'phone': phoneCtrl.text.trim(),
                  'loan_direction': 'Given',
                  'loan_amount': lAmt,
                  'installment_amount': instAmt,
                  'repaid_amount': 0.0,
                  'remaining_amount': lAmt,
                  'account_id': selectedAccountId,
                  'date_issued': dIss,
                  'due_date': dDue.isNotEmpty ? dDue : null,
                  'description': descCtrl.text.trim(),
                  'status': 'Active',
                  'system_type': sys,
                  'is_deleted': 0,
                  'is_synced': 0,
                });

                // Post Expense in Finance
                try {
                  final finData = {
                    'user_id': currentUserId.value ?? 1,
                    'user_name': currentUserRole.value ?? 'Admin',
                    'type': 'Expense',
                    'head_id': 0,
                    'account_id': selectedAccountId,
                    'amount': lAmt,
                    'date': dIss,
                    'donor_name': nameCtrl.text.trim(),
                    'description': isUrduGlobal.value ? "قرض/پیشگی رقم جاری کی گئی: ${nameCtrl.text.trim()} - ${descCtrl.text.trim()}" : "Loan Issued: ${nameCtrl.text.trim()}",
                    'is_deleted': 0,
                    'is_synced': 0,
                  };
                  if (sys == 'Masjid') {
                    await _db.insertMasjidFinance(finData);
                  } else {
                    await _db.insertFinance(finData);
                  }
                } catch (e) { debugPrint("Finance expense error: $e"); }

                Navigator.pop(ctx);
                _loadData();
              },
              child: Text(isUrduGlobal.value ? "قرض جاری کریں" : "Issue Loan"),
            ),
          ],
        ),
      ),
    );
  }

  // --- 2. Dialog: Record Loan Taken ---
  void _showRecordTakenDialog() {
    final formKey = GlobalKey<FormState>();
    int? selectedAccountId = _paymentAccounts.firstWhere(
      (a) => a['code']?.toString() == '1001',
      orElse: () => _paymentAccounts.isNotEmpty ? _paymentAccounts.first : {},
    )['id'] as int?;

    final lenderCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final amountCtrl = TextEditingController();
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    final dueDateCtrl = TextEditingController();
    final descCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: Row(
            children: [
              const Icon(Icons.arrow_downward, color: Colors.orange),
              const SizedBox(width: 8),
              Text(isUrduGlobal.value ? "ادارہ کا لیا گیا قرض درج کریں" : "Record Loan Taken", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          content: SingleChildScrollView(
            child: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextFormField(
                    controller: lenderCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "قرض دہندہ کا نام *" : "Lender Name *",
                      border: const OutlineInputBorder(),
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? (isUrduGlobal.value ? "نام درج کریں" : "Name required") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: phoneCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "فون نمبر" : "Phone Number",
                      border: const OutlineInputBorder(),
                    ),
                    keyboardType: TextInputType.phone,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: amountCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "لی گئی رقم (روپے) *" : "Amount Taken *",
                      border: const OutlineInputBorder(),
                    ),
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    validator: (v) {
                      final val = double.tryParse(v ?? '');
                      if (val == null || val <= 0) return isUrduGlobal.value ? "درست رقم لکھیں" : "Valid amount required";
                      return null;
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "وصولی کھاتہ (رقم کس کھاتے میں جمع ہوئی؟) *" : "Deposit Account *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedAccountId,
                    items: _paymentAccounts.map((a) {
                      final name = a['name']?.toString() ?? '';
                      final code = a['code']?.toString() ?? '';
                      return DropdownMenuItem<int?>(
                        value: a['id'] as int?,
                        child: Text("$name ($code)", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) => setDlgState(() => selectedAccountId = val),
                    validator: (v) => v == null ? (isUrduGlobal.value ? "کھاتہ منتخب کریں" : "Select account") : null,
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: dateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "تاریخ وصولی" : "Date Taken",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.calendar_today, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextFormField(
                          controller: dueDateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "واپسی تاریخ" : "Due Date",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.event, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now().add(const Duration(days: 90)),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dueDateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: descCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تفصیل / شرائط" : "Purpose / Notes",
                      border: const OutlineInputBorder(),
                    ),
                    maxLines: 2,
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.orange.shade800, foregroundColor: Colors.white),
              onPressed: () async {
                if (!formKey.currentState!.validate()) return;
                final lAmt = double.tryParse(amountCtrl.text.trim()) ?? 0;
                final dIss = dateCtrl.text.trim();
                final dDue = dueDateCtrl.text.trim();
                final sys = SystemTypeGlobal.currentSystemType.value;

                final dbInst = await _db.database;
                await dbInst.insert('staff_loans', {
                  'teacher_id': null,
                  'borrower_name': lenderCtrl.text.trim(),
                  'phone': phoneCtrl.text.trim(),
                  'loan_direction': 'Taken',
                  'loan_amount': lAmt,
                  'installment_amount': 0.0,
                  'repaid_amount': 0.0,
                  'remaining_amount': lAmt,
                  'account_id': selectedAccountId,
                  'date_issued': dIss,
                  'due_date': dDue.isNotEmpty ? dDue : null,
                  'description': descCtrl.text.trim(),
                  'status': 'Active',
                  'system_type': sys,
                  'is_deleted': 0,
                  'is_synced': 0,
                });

                // Post Income in Finance
                try {
                  final finData = {
                    'user_id': currentUserId.value ?? 1,
                    'user_name': currentUserRole.value ?? 'Admin',
                    'type': 'Income',
                    'head_id': 0,
                    'account_id': selectedAccountId,
                    'amount': lAmt,
                    'date': dIss,
                    'donor_name': lenderCtrl.text.trim(),
                    'description': isUrduGlobal.value ? "ادارہ نے قرض حسنہ حاصل کیا: ${lenderCtrl.text.trim()} - ${descCtrl.text.trim()}" : "Loan Taken: ${lenderCtrl.text.trim()}",
                    'is_deleted': 0,
                    'is_synced': 0,
                  };
                  if (sys == 'Masjid') {
                    await _db.insertMasjidFinance(finData);
                  } else {
                    await _db.insertFinance(finData);
                  }
                } catch (e) { debugPrint("Finance income error: $e"); }

                Navigator.pop(ctx);
                _loadData();
              },
              child: Text(isUrduGlobal.value ? "محفوظ کریں" : "Save"),
            ),
          ],
        ),
      ),
    );
  }

  // --- 3. Dialog: Receive Repayment for Given Loan (قرض وصولی) ---
  void _showReceiveRepaymentDialog([Map<String, dynamic>? preselectedLoan]) {
    final formKey = GlobalKey<FormState>();
    int? selectedLoanId = preselectedLoan?['id'];
    double maxRem = ((preselectedLoan?['remaining_amount'] ?? 0) as num).toDouble();
    double instAmt = ((preselectedLoan?['installment_amount'] ?? 0) as num).toDouble();
    double defaultAmt = (instAmt > 0 && instAmt <= maxRem) ? instAmt : maxRem;

    int? selectedAccountId = _paymentAccounts.firstWhere(
      (a) => a['code']?.toString() == '1001',
      orElse: () => _paymentAccounts.isNotEmpty ? _paymentAccounts.first : {},
    )['id'] as int?;

    final amountCtrl = TextEditingController(text: defaultAmt > 0 ? defaultAmt.toStringAsFixed(0) : '');
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    String paymentMethod = 'Cash';
    final remarksCtrl = TextEditingController();

    final activeGiven = _loansGivenList.where((l) => ((l['remaining_amount'] ?? 0) as num).toDouble() > 0).toList();

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: Row(
            children: [
              const Icon(Icons.volunteer_activism, color: Colors.green),
              const SizedBox(width: 8),
              Text(isUrduGlobal.value ? "قرض واپسی وصولی درج کریں" : "Record Loan Recovery", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          content: SingleChildScrollView(
            child: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "قرض دار منتخب کریں *" : "Select Borrower *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedLoanId,
                    items: activeGiven.map((l) {
                      final name = l['teacher_name'] ?? (l['borrower_name'] ?? 'Borrower');
                      final rem = ((l['remaining_amount'] ?? 0) as num).toDouble();
                      return DropdownMenuItem<int?>(
                        value: l['id'] as int,
                        child: Text("$name (بقایا: Rs. ${rem.toStringAsFixed(0)})", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) {
                      setDlgState(() {
                        selectedLoanId = val;
                        final match = activeGiven.firstWhere((l) => l['id'] == val, orElse: () => {});
                        if (match.isNotEmpty) {
                          maxRem = ((match['remaining_amount'] ?? 0) as num).toDouble();
                          final inst = ((match['installment_amount'] ?? 0) as num).toDouble();
                          final def = (inst > 0 && inst <= maxRem) ? inst : maxRem;
                          amountCtrl.text = def.toStringAsFixed(0);
                        }
                      });
                    },
                    validator: (v) => v == null ? (isUrduGlobal.value ? "قرض دار منتخب کریں" : "Select borrower") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: amountCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "وصول شدہ رقم (روپے) *" : "Amount Received *",
                      border: const OutlineInputBorder(),
                    ),
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    validator: (v) {
                      final val = double.tryParse(v ?? '');
                      if (val == null || val <= 0) return isUrduGlobal.value ? "درست رقم لکھیں" : "Valid amount required";
                      if (val > maxRem && maxRem > 0) return isUrduGlobal.value ? "رقم بقایا سے زیادہ نہیں ہو سکتی" : "Cannot exceed remaining balance";
                      return null;
                    },
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: dateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "تاریخ وصولی" : "Date",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.calendar_today, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "طریقہ ادائیگی" : "Method",
                            border: const OutlineInputBorder(),
                          ),
                          value: paymentMethod,
                          items: const [
                            DropdownMenuItem(value: 'Cash', child: Text("کیش")),
                            DropdownMenuItem(value: 'Bank', child: Text("بینک")),
                            DropdownMenuItem(value: 'Cheque', child: Text("چیک")),
                            DropdownMenuItem(value: 'Salary Deduction', child: Text("تنخواہ کٹوتی")),
                          ],
                          onChanged: (v) => setDlgState(() => paymentMethod = v ?? 'Cash'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "وصولی کھاتہ (رقم کس کھاتے میں موصول ہوئی؟) *" : "Deposit Account *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedAccountId,
                    items: _paymentAccounts.map((a) {
                      final name = a['name']?.toString() ?? '';
                      final code = a['code']?.toString() ?? '';
                      return DropdownMenuItem<int?>(
                        value: a['id'] as int?,
                        child: Text("$name ($code)", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) => setDlgState(() => selectedAccountId = val),
                    validator: (v) => v == null ? (isUrduGlobal.value ? "کھاتہ منتخب کریں" : "Select account") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: remarksCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تفصیل / نوٹ" : "Remarks / Notes",
                      border: const OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(8)),
                    child: Text(
                      isUrduGlobal.value ? "یہ وصولی قرض بیلنس کم کرے گی اور خودکار فنانس آمدن (Income) میں درج ہو گی۔" : "This recovery will update balance and post as Income in Finance.",
                      style: TextStyle(fontSize: 11, color: Colors.green.shade800),
                    ),
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white),
              onPressed: () async {
                if (!formKey.currentState!.validate()) return;
                final rAmt = double.tryParse(amountCtrl.text.trim()) ?? 0;
                final dRec = dateCtrl.text.trim();
                final sys = SystemTypeGlobal.currentSystemType.value;

                final dbInst = await _db.database;
                final loanMatch = _loansGivenList.firstWhere((l) => l['id'] == selectedLoanId, orElse: () => {});
                final curRepaid = ((loanMatch['repaid_amount'] ?? 0) as num).toDouble();
                final curRem = ((loanMatch['remaining_amount'] ?? 0) as num).toDouble();
                final bName = loanMatch['teacher_name'] ?? (loanMatch['borrower_name'] ?? 'Borrower');

                final newRepaid = curRepaid + rAmt;
                final newRem = (curRem - rAmt) < 0 ? 0.0 : (curRem - rAmt);
                final newStatus = newRem <= 0 ? 'Paid' : 'Active';

                // 1. Update staff_loans
                await dbInst.update(
                  'staff_loans',
                  {'repaid_amount': newRepaid, 'remaining_amount': newRem, 'status': newStatus, 'is_synced': 0},
                  where: 'id = ?',
                  whereArgs: [selectedLoanId],
                );

                // 2. Insert into loan_transactions
                await dbInst.insert('loan_transactions', {
                  'loan_id': selectedLoanId,
                  'type': 'Recovery',
                  'borrower_lender_name': bName,
                  'amount': rAmt,
                  'date': dRec,
                  'payment_method': paymentMethod,
                  'account_id': selectedAccountId,
                  'remarks': remarksCtrl.text.trim(),
                  'system_type': sys,
                  'created_by': currentUserId.value ?? 1,
                  'is_deleted': 0,
                  'is_synced': 0,
                });

                // 3. Post Income in Finance
                try {
                  final finData = {
                    'user_id': currentUserId.value ?? 1,
                    'user_name': currentUserRole.value ?? 'Admin',
                    'type': 'Income',
                    'head_id': 0,
                    'account_id': selectedAccountId,
                    'amount': rAmt,
                    'date': dRec,
                    'donor_name': bName,
                    'description': isUrduGlobal.value ? "قرض قسط واپسی وصول ہوئی: $bName - ${remarksCtrl.text.trim()} ($paymentMethod)" : "Loan Recovery: $bName",
                    'is_deleted': 0,
                    'is_synced': 0,
                  };
                  if (sys == 'Masjid') {
                    await _db.insertMasjidFinance(finData);
                  } else {
                    await _db.insertFinance(finData);
                  }
                } catch (e) { debugPrint("Finance income error: $e"); }

                Navigator.pop(ctx);
                _loadData();
              },
              child: Text(isUrduGlobal.value ? "وصولی محفوظ کریں" : "Save Recovery"),
            ),
          ],
        ),
      ),
    );
  }

  // --- 4. Dialog: Repay Taken Loan (قرض واپسی / ادائیگی) ---
  void _showRepayTakenDialog([Map<String, dynamic>? preselectedLoan]) {
    final formKey = GlobalKey<FormState>();
    int? selectedLoanId = preselectedLoan?['id'];
    double maxRem = ((preselectedLoan?['remaining_amount'] ?? 0) as num).toDouble();

    int? selectedAccountId = _paymentAccounts.firstWhere(
      (a) => a['code']?.toString() == '1001',
      orElse: () => _paymentAccounts.isNotEmpty ? _paymentAccounts.first : {},
    )['id'] as int?;

    final amountCtrl = TextEditingController(text: maxRem > 0 ? maxRem.toStringAsFixed(0) : '');
    final dateCtrl = TextEditingController(text: DateFormat('yyyy-MM-dd').format(DateTime.now()));
    String paymentMethod = 'Cash';
    final remarksCtrl = TextEditingController();

    final activeTaken = _loansTakenList.where((l) => ((l['remaining_amount'] ?? 0) as num).toDouble() > 0).toList();

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: Row(
            children: [
              const Icon(Icons.payment, color: Colors.indigo),
              const SizedBox(width: 8),
              Text(isUrduGlobal.value ? "قرض حسنہ واپسی ادائیگی درج کریں" : "Repay Debt (Madrasa Payment)", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          content: SingleChildScrollView(
            child: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "قرض دہندہ منتخب کریں *" : "Select Lender *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedLoanId,
                    items: activeTaken.map((l) {
                      final name = l['borrower_name'] ?? 'Lender';
                      final rem = ((l['remaining_amount'] ?? 0) as num).toDouble();
                      return DropdownMenuItem<int?>(
                        value: l['id'] as int,
                        child: Text("$name (واجب الادا: Rs. ${rem.toStringAsFixed(0)})", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) {
                      setDlgState(() {
                        selectedLoanId = val;
                        final match = activeTaken.firstWhere((l) => l['id'] == val, orElse: () => {});
                        if (match.isNotEmpty) {
                          maxRem = ((match['remaining_amount'] ?? 0) as num).toDouble();
                          amountCtrl.text = maxRem.toStringAsFixed(0);
                        }
                      });
                    },
                    validator: (v) => v == null ? (isUrduGlobal.value ? "قرض دہندہ منتخب کریں" : "Select lender") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: amountCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "ادا کی جانے والی رقم (روپے) *" : "Amount to Repay *",
                      border: const OutlineInputBorder(),
                    ),
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    validator: (v) {
                      final val = double.tryParse(v ?? '');
                      if (val == null || val <= 0) return isUrduGlobal.value ? "درست رقم لکھیں" : "Valid amount required";
                      if (val > maxRem && maxRem > 0) return isUrduGlobal.value ? "رقم واجب الادا سے زیادہ نہیں ہو سکتی" : "Cannot exceed outstanding debt";
                      return null;
                    },
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: dateCtrl,
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "تاریخ ادائیگی" : "Payment Date",
                            border: const OutlineInputBorder(),
                            suffixIcon: const Icon(Icons.calendar_today, size: 18),
                          ),
                          readOnly: true,
                          onTap: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2035),
                            );
                            if (picked != null) {
                              dateCtrl.text = DateFormat('yyyy-MM-dd').format(picked);
                            }
                          },
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          decoration: InputDecoration(
                            labelText: isUrduGlobal.value ? "طریقہ ادائیگی" : "Method",
                            border: const OutlineInputBorder(),
                          ),
                          value: paymentMethod,
                          items: const [
                            DropdownMenuItem(value: 'Cash', child: Text("کیش")),
                            DropdownMenuItem(value: 'Bank', child: Text("بینک")),
                            DropdownMenuItem(value: 'Cheque', child: Text("چیک")),
                          ],
                          onChanged: (v) => setDlgState(() => paymentMethod = v ?? 'Cash'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int?>(
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "ادائیگی کھاتہ (رقم کس کھاتے سے ادا کی گئی؟) *" : "Payment Account *",
                      border: const OutlineInputBorder(),
                    ),
                    value: selectedAccountId,
                    items: _paymentAccounts.map((a) {
                      final name = a['name']?.toString() ?? '';
                      final code = a['code']?.toString() ?? '';
                      return DropdownMenuItem<int?>(
                        value: a['id'] as int?,
                        child: Text("$name ($code)", style: const TextStyle(fontSize: 13)),
                      );
                    }).toList(),
                    onChanged: (val) => setDlgState(() => selectedAccountId = val),
                    validator: (v) => v == null ? (isUrduGlobal.value ? "کھاتہ منتخب کریں" : "Select account") : null,
                  ),
                  const SizedBox(height: 10),
                  TextFormField(
                    controller: remarksCtrl,
                    decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "تفصیل / نوٹ" : "Remarks / Notes",
                      border: const OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(color: Colors.blue.shade50, borderRadius: BorderRadius.circular(8)),
                    child: Text(
                      isUrduGlobal.value ? "یہ ادائیگی قرض بیلنس کم کرے گی اور خودکار فنانس خرچ (Expense) میں درج ہو گی۔" : "This payment will reduce debt and post as Expense in Finance.",
                      style: TextStyle(fontSize: 11, color: Colors.blue.shade800),
                    ),
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(isUrduGlobal.value ? "منسوخ" : "Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.indigo, foregroundColor: Colors.white),
              onPressed: () async {
                if (!formKey.currentState!.validate()) return;
                final rAmt = double.tryParse(amountCtrl.text.trim()) ?? 0;
                final dPay = dateCtrl.text.trim();
                final sys = SystemTypeGlobal.currentSystemType.value;

                final dbInst = await _db.database;
                final loanMatch = _loansTakenList.firstWhere((l) => l['id'] == selectedLoanId, orElse: () => {});
                final curRepaid = ((loanMatch['repaid_amount'] ?? 0) as num).toDouble();
                final curRem = ((loanMatch['remaining_amount'] ?? 0) as num).toDouble();
                final lName = loanMatch['borrower_name'] ?? 'Lender';

                final newRepaid = curRepaid + rAmt;
                final newRem = (curRem - rAmt) < 0 ? 0.0 : (curRem - rAmt);
                final newStatus = newRem <= 0 ? 'Paid' : 'Active';

                // 1. Update staff_loans
                await dbInst.update(
                  'staff_loans',
                  {'repaid_amount': newRepaid, 'remaining_amount': newRem, 'status': newStatus, 'is_synced': 0},
                  where: 'id = ?',
                  whereArgs: [selectedLoanId],
                );

                // 2. Insert into loan_transactions
                await dbInst.insert('loan_transactions', {
                  'loan_id': selectedLoanId,
                  'type': 'Repayment',
                  'borrower_lender_name': lName,
                  'amount': rAmt,
                  'date': dPay,
                  'payment_method': paymentMethod,
                  'account_id': selectedAccountId,
                  'remarks': remarksCtrl.text.trim(),
                  'system_type': sys,
                  'created_by': currentUserId.value ?? 1,
                  'is_deleted': 0,
                  'is_synced': 0,
                });

                // 3. Post Expense in Finance
                try {
                  final finData = {
                    'user_id': currentUserId.value ?? 1,
                    'user_name': currentUserRole.value ?? 'Admin',
                    'type': 'Expense',
                    'head_id': 0,
                    'account_id': selectedAccountId,
                    'amount': rAmt,
                    'date': dPay,
                    'donor_name': lName,
                    'description': isUrduGlobal.value ? "ادارہ کی طرف سے قرض واپس ادا کیا گیا: $lName - ${remarksCtrl.text.trim()} ($paymentMethod)" : "Debt Repaid: $lName",
                    'is_deleted': 0,
                    'is_synced': 0,
                  };
                  if (sys == 'Masjid') {
                    await _db.insertMasjidFinance(finData);
                  } else {
                    await _db.insertFinance(finData);
                  }
                } catch (e) { debugPrint("Finance expense error: $e"); }

                Navigator.pop(ctx);
                _loadData();
              },
              child: Text(isUrduGlobal.value ? "ادائیگی محفوظ کریں" : "Save Repayment"),
            ),
          ],
        ),
      ),
    );
  }

  // --- Delete Loan ---
  Future<void> _deleteLoan(int id) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? "حذف کریں" : "Delete"),
        content: Text(isUrduGlobal.value ? "کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟" : "Delete this loan record?"),
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
    await dbInst.update('staff_loans', {'is_deleted': 1, 'is_synced': 0}, where: 'id = ?', whereArgs: [id]);
    _loadData();
  }

  // --- Delete Transaction Record ---
  Future<void> _deleteTransaction(Map<String, dynamic> tx) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? "حذف کریں" : "Delete"),
        content: Text(isUrduGlobal.value ? "کیا آپ واقعی یہ ٹرانزیکشن حذف کرنا چاہتے ہیں؟ اس سے قرض کا بقایا بیلنس دوبارہ درست ہو جائے گا۔" : "Delete this transaction? Loan balance will be adjusted."),
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
    await dbInst.update('loan_transactions', {'is_deleted': 1, 'is_synced': 0}, where: 'id = ?', whereArgs: [tx['id']]);

    final loanId = tx['loan_id'];
    if (loanId != null) {
      final lRes = await dbInst.query('staff_loans', where: 'id = ?', whereArgs: [loanId]);
      if (lRes.isNotEmpty) {
        final loan = lRes.first;
        final curRepaid = ((loan['repaid_amount'] ?? 0) as num).toDouble();
        final curRem = ((loan['remaining_amount'] ?? 0) as num).toDouble();
        final txAmt = ((tx['amount'] ?? 0) as num).toDouble();

        final newRepaid = (curRepaid - txAmt) < 0 ? 0.0 : (curRepaid - txAmt);
        final newRem = curRem + txAmt;
        final newStatus = newRem <= 0 ? 'Paid' : 'Active';

        await dbInst.update(
          'staff_loans',
          {'repaid_amount': newRepaid, 'remaining_amount': newRem, 'status': newStatus, 'is_synced': 0},
          where: 'id = ?',
          whereArgs: [loanId],
        );
      }
    }

    _loadData();
  }

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;

    double totGivenRem = 0;
    for (var l in _loansGivenList) {
      totGivenRem += ((l['remaining_amount'] ?? 0) as num).toDouble();
    }

    double totTakenRem = 0;
    for (var l in _loansTakenList) {
      totTakenRem += ((l['remaining_amount'] ?? 0) as num).toDouble();
    }

    double totRecoveries = 0;
    for (var r in _recoveriesList) {
      totRecoveries += ((r['amount'] ?? 0) as num).toDouble();
    }

    double totRepayments = 0;
    for (var rp in _repaymentsList) {
      totRepayments += ((rp['amount'] ?? 0) as num).toDouble();
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          isUrdu ? "ایڈوانس و قرض مینیجمنٹ" : "Loans & Advances Management",
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: Colors.indigo,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadData,
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          isScrollable: true,
          tabs: [
            Tab(
              icon: const Icon(Icons.arrow_upward),
              text: isUrdu ? "1. دیے گئے قرض (${_loansGivenList.length})" : "1. Given (${_loansGivenList.length})",
            ),
            Tab(
              icon: const Icon(Icons.arrow_downward),
              text: isUrdu ? "2. لیے ہوئے قرض (${_loansTakenList.length})" : "2. Taken (${_loansTakenList.length})",
            ),
            Tab(
              icon: const Icon(Icons.volunteer_activism),
              text: isUrdu ? "3. قرض وصولی (${_recoveriesList.length})" : "3. Recoveries (${_recoveriesList.length})",
            ),
            Tab(
              icon: const Icon(Icons.payment),
              text: isUrdu ? "4. قرض واپسی (${_repaymentsList.length})" : "4. Repayments (${_repaymentsList.length})",
            ),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: _tabController.index == 0
            ? Colors.blue.shade700
            : _tabController.index == 1
                ? Colors.orange.shade800
                : _tabController.index == 2
                    ? Colors.green.shade700
                    : Colors.indigo,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: Text(
          _tabController.index == 0
              ? (isUrdu ? "نیا قرض جاری کریں" : "Issue Loan")
              : _tabController.index == 1
                  ? (isUrdu ? "نیا قرض درج کریں" : "Record Loan Taken")
                  : _tabController.index == 2
                      ? (isUrdu ? "نئی قرض وصولی درج کریں" : "New Recovery")
                      : (isUrdu ? "نئی قرض واپسی درج کریں" : "New Repayment"),
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        onPressed: () {
          if (_tabController.index == 0) {
            _showIssueGivenDialog();
          } else if (_tabController.index == 1) {
            _showRecordTakenDialog();
          } else if (_tabController.index == 2) {
            _showReceiveRepaymentDialog();
          } else {
            _showRepayTakenDialog();
          }
        },
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Top Summary Header
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  color: Colors.indigo.shade50,
                  child: Row(
                    children: [
                      _topMetric(isUrdu ? "واجب الوصول" : "Receivable", "Rs. ${totGivenRem.toStringAsFixed(0)}", Colors.blue.shade800),
                      const SizedBox(width: 6),
                      _topMetric(isUrdu ? "واجب الادا" : "Debt", "Rs. ${totTakenRem.toStringAsFixed(0)}", Colors.red.shade800),
                      const SizedBox(width: 6),
                      _topMetric(isUrdu ? "وصولیاں" : "Recoveries", "Rs. ${totRecoveries.toStringAsFixed(0)}", Colors.green.shade800),
                      const SizedBox(width: 6),
                      _topMetric(isUrdu ? "ادائیگی" : "Repaid", "Rs. ${totRepayments.toStringAsFixed(0)}", Colors.indigo.shade800),
                    ],
                  ),
                ),

                // Tab Views
                Expanded(
                  child: TabBarView(
                    controller: _tabController,
                    children: [
                      // Tab 1: Loans Given
                      _buildGivenList(isUrdu),
                      // Tab 2: Loans Taken
                      _buildTakenList(isUrdu),
                      // Tab 3: Loan Recoveries
                      _buildRecoveriesList(isUrdu),
                      // Tab 4: Loan Repayments
                      _buildRepaymentsList(isUrdu),
                    ],
                  ),
                ),
              ],
            ),
    );
  }

  // --- List 1: Loans Given ---
  Widget _buildGivenList(bool isUrdu) {
    if (_loansGivenList.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.request_quote, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text(
              isUrdu ? "کوئی جاری کردہ قرض موجود نہیں ہے۔" : "No loans given yet.",
              style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(left: 12, right: 12, top: 8, bottom: 80),
      itemCount: _loansGivenList.length,
      itemBuilder: (ctx, idx) {
        final item = _loansGivenList[idx];
        double rem = ((item['remaining_amount'] ?? 0) as num).toDouble();
        bool isSettled = rem <= 0 || item['status'] == 'Paid';

        return Card(
          margin: const EdgeInsets.symmetric(vertical: 6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 2,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        item['teacher_name'] ?? (item['borrower_name'] ?? 'Borrower'),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: isSettled ? Colors.green.shade100 : Colors.orange.shade100,
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        isSettled ? (isUrdu ? "ادا شدہ" : "PAID") : (isUrdu ? "فعال / بقایا" : "ACTIVE"),
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: isSettled ? Colors.green.shade800 : Colors.orange.shade900,
                        ),
                      ),
                    ),
                  ],
                ),
                if (item['phone'] != null && item['phone'].toString().isNotEmpty)
                  Text(item['phone'].toString(), style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                const SizedBox(height: 8),

                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text("${isUrdu ? 'اصل رقم:' : 'Amount:'} Rs. ${(item['loan_amount'] ?? 0).toString()}", style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                    Text("${isUrdu ? 'واپس وصول:' : 'Repaid:'} Rs. ${(item['repaid_amount'] ?? 0).toString()}", style: const TextStyle(fontSize: 12, color: Colors.green)),
                    Text("${isUrdu ? 'بقایا:' : 'Remaining:'} Rs. ${rem.toStringAsFixed(0)}", style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.red)),
                  ],
                ),
                if (item['date_issued'] != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text("${isUrdu ? 'تاریخ اجرا:' : 'Issued on:'} ${item['date_issued']}", style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                  ),
                const Divider(height: 14),

                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    if (!isSettled)
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                        icon: const Icon(Icons.add_task, size: 16),
                        label: Text(isUrdu ? "قسط وصول کریں" : "Receive"),
                        onPressed: () => _showReceiveRepaymentDialog(item),
                      ),
                    const SizedBox(width: 8),
                    IconButton(
                      icon: const Icon(Icons.delete, size: 20, color: Colors.red),
                      onPressed: () => _deleteLoan(item['id']),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // --- List 2: Loans Taken ---
  Widget _buildTakenList(bool isUrdu) {
    if (_loansTakenList.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.handshake, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text(
              isUrdu ? "ادارہ کے لیے ہوئے قرض کا کوئی ریکارڈ موجود نہیں ہے۔" : "No institutional loans taken yet.",
              style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(left: 12, right: 12, top: 8, bottom: 80),
      itemCount: _loansTakenList.length,
      itemBuilder: (ctx, idx) {
        final item = _loansTakenList[idx];
        double rem = ((item['remaining_amount'] ?? 0) as num).toDouble();
        bool isSettled = rem <= 0 || item['status'] == 'Paid';

        return Card(
          margin: const EdgeInsets.symmetric(vertical: 6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 2,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        item['borrower_name'] ?? 'Lender',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: isSettled ? Colors.green.shade100 : Colors.red.shade100,
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        isSettled ? (isUrdu ? "ادا شدہ" : "SETTLED") : (isUrdu ? "واجب الادا قرض" : "DEBT"),
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: isSettled ? Colors.green.shade800 : Colors.red.shade900,
                        ),
                      ),
                    ),
                  ],
                ),
                if (item['phone'] != null && item['phone'].toString().isNotEmpty)
                  Text(item['phone'].toString(), style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                const SizedBox(height: 8),

                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text("${isUrdu ? 'لی گئی رقم:' : 'Amount:'} Rs. ${(item['loan_amount'] ?? 0).toString()}", style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                    Text("${isUrdu ? 'واپس ادا:' : 'Repaid:'} Rs. ${(item['repaid_amount'] ?? 0).toString()}", style: const TextStyle(fontSize: 12, color: Colors.blue)),
                    Text("${isUrdu ? 'بقایا قرض:' : 'Remaining:'} Rs. ${rem.toStringAsFixed(0)}", style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.red)),
                  ],
                ),
                if (item['date_issued'] != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text("${isUrdu ? 'تاریخ موصولی:' : 'Taken on:'} ${item['date_issued']}", style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                  ),
                const Divider(height: 14),

                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    if (!isSettled)
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(backgroundColor: Colors.orange.shade800, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                        icon: const Icon(Icons.payment, size: 16),
                        label: Text(isUrdu ? "قرض واپس ادا کریں" : "Repay Debt"),
                        onPressed: () => _showRepayTakenDialog(item),
                      ),
                    const SizedBox(width: 8),
                    IconButton(
                      icon: const Icon(Icons.delete, size: 20, color: Colors.red),
                      onPressed: () => _deleteLoan(item['id']),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // --- List 3: Loan Recoveries (قرض وصولی) ---
  Widget _buildRecoveriesList(bool isUrdu) {
    if (_recoveriesList.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.volunteer_activism, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text(
              isUrdu ? "ابھی تک قرض وصولی کا کوئی ریکارڈ موجود نہیں ہے۔" : "No recovery transactions recorded yet.",
              style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(left: 12, right: 12, top: 8, bottom: 80),
      itemCount: _recoveriesList.length,
      itemBuilder: (ctx, idx) {
        final r = _recoveriesList[idx];
        final amt = ((r['amount'] ?? 0) as num).toDouble();

        return Card(
          margin: const EdgeInsets.symmetric(vertical: 6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 2,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        r['borrower_lender_name'] ?? 'Borrower',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    Text(
                      "Rs. ${amt.toStringAsFixed(0)}",
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.green),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(4)),
                      child: Text(r['payment_method'] ?? 'Cash', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                    ),
                    const SizedBox(width: 8),
                    Text("${isUrdu ? 'تاریخ:' : 'Date:'} ${r['date'] ?? ''}", style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                    const Spacer(),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(4)),
                      child: Text(isUrdu ? "فنانس: آمدن" : "Income Inflow", style: TextStyle(fontSize: 10, color: Colors.green.shade800, fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
                if (r['remarks'] != null && r['remarks'].toString().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text("${isUrdu ? 'نوٹ:' : 'Note:'} ${r['remarks']}", style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                ],
                const Divider(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    IconButton(
                      icon: const Icon(Icons.delete, size: 20, color: Colors.red),
                      onPressed: () => _deleteTransaction(r),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // --- List 4: Loan Repayments (قرض واپسی / ادائیگی) ---
  Widget _buildRepaymentsList(bool isUrdu) {
    if (_repaymentsList.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.payment, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text(
              isUrdu ? "ابھی تک قرض واپسی ادائیگی کا کوئی ریکارڈ موجود نہیں ہے۔" : "No repayment transactions recorded yet.",
              style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold),
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(left: 12, right: 12, top: 8, bottom: 80),
      itemCount: _repaymentsList.length,
      itemBuilder: (ctx, idx) {
        final rp = _repaymentsList[idx];
        final amt = ((rp['amount'] ?? 0) as num).toDouble();

        return Card(
          margin: const EdgeInsets.symmetric(vertical: 6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 2,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        rp['borrower_lender_name'] ?? 'Lender',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    Text(
                      "Rs. ${amt.toStringAsFixed(0)}",
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.indigo),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(4)),
                      child: Text(rp['payment_method'] ?? 'Cash', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                    ),
                    const SizedBox(width: 8),
                    Text("${isUrdu ? 'تاریخ:' : 'Date:'} ${rp['date'] ?? ''}", style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                    const Spacer(),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(4)),
                      child: Text(isUrdu ? "فنانس: خرچ" : "Expense Outflow", style: TextStyle(fontSize: 10, color: Colors.red.shade800, fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
                if (rp['remarks'] != null && rp['remarks'].toString().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text("${isUrdu ? 'نوٹ:' : 'Note:'} ${rp['remarks']}", style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                ],
                const Divider(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    IconButton(
                      icon: const Icon(Icons.delete, size: 20, color: Colors.red),
                      onPressed: () => _deleteTransaction(rp),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _topMetric(String label, String val, Color col) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 6),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: col.withOpacity(0.3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.grey.shade600)),
            const SizedBox(height: 2),
            Text(val, style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: col)),
          ],
        ),
      ),
    );
  }
}
