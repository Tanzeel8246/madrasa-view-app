import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import 'package:intl/intl.dart' hide TextDirection;

class StaffLoansPage extends StatefulWidget {
  const StaffLoansPage({super.key});

  @override
  State<StaffLoansPage> createState() => _StaffLoansPageState();
}

class _StaffLoansPageState extends State<StaffLoansPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  List<Map<String, dynamic>> _loans = [];
  List<Map<String, dynamic>> _teachers = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    
    // Create staff_loans table manually if it wasn't created yet during development
    try {
      final dbInst = await db.database;
      await dbInst.execute('''
        CREATE TABLE IF NOT EXISTS staff_loans(
          id INTEGER PRIMARY KEY,
          teacher_id INTEGER,
          loan_amount REAL DEFAULT 0,
          installment_amount REAL DEFAULT 0,
          remaining_amount REAL DEFAULT 0,
          status TEXT DEFAULT 'Active',
          date_issued TEXT,
          description TEXT,
          is_synced INTEGER DEFAULT 0,
          is_deleted INTEGER DEFAULT 0,
          system_type TEXT DEFAULT 'Madrasa'
        )
      ''');
    } catch (_) {}

    final dbInst = await db.database;
    final sys = SystemTypeGlobal.currentSystemType.value;
    final loanRows = await dbInst.query('staff_loans', where: '(is_deleted = 0 OR is_deleted IS NULL) AND system_type = ?', whereArgs: [sys], orderBy: 'id DESC');
    final teacherRows = await db.getTeachers();
    
    _teachers = teacherRows;
    
    List<Map<String, dynamic>> enriched = [];
    for (var l in loanRows) {
      var map = Map<String, dynamic>.from(l);
      final tId = int.tryParse(map['teacher_id']?.toString() ?? '');
      final t = teacherRows.firstWhere((element) => element['id'] == tId, orElse: () => {});
      map['staff_name'] = t['name'] ?? 'Unknown';
      enriched.add(map);
    }
    
    setState(() {
      _loans = enriched;
      _isLoading = false;
    });
  }

  Future<void> _issueLoan() async {
    int? selectedTeacher;
    final amtCtrl = TextEditingController();
    final instCtrl = TextEditingController();
    final descCtrl = TextEditingController();
    
    await showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setStateSB) {
            return AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              title: const Text('نیا قرض جاری کریں', textAlign: TextAlign.right, style: TextStyle(fontWeight: FontWeight.bold)),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    DropdownButtonFormField<int>(
                      decoration: const InputDecoration(labelText: 'سٹاف کا نام', border: OutlineInputBorder()),
                      items: _teachers.map((t) => DropdownMenuItem<int>(
                        value: t['id'] as int,
                        child: Text(t['name'] ?? ''),
                      )).toList(),
                      onChanged: (val) => setStateSB(() => selectedTeacher = val),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: amtCtrl,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(labelText: 'کل قرض (Rs)', border: OutlineInputBorder()),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: instCtrl,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(labelText: 'ماہانہ کٹوتی (Rs)', border: OutlineInputBorder()),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: descCtrl,
                      decoration: const InputDecoration(labelText: 'تفصیل', border: OutlineInputBorder()),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('منسوخ کریں', style: TextStyle(color: Colors.grey)),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                  onPressed: () async {
                    if (selectedTeacher == null || amtCtrl.text.isEmpty || instCtrl.text.isEmpty) return;
                    
                    final amt = double.tryParse(amtCtrl.text) ?? 0.0;
                    final inst = double.tryParse(instCtrl.text) ?? 0.0;
                    
                    final date = DateFormat('yyyy-MM-dd').format(DateTime.now());
                    
                    final dbInst = await DBHelper().database;
                    
                    // Get Loan Head ID
                    final sys = SystemTypeGlobal.currentSystemType.value;
                    final h = await dbInst.query('acc_chart_of_accounts', where: "code = 'LOAN-001' AND system_type = ?", whereArgs: [sys]);
                    int? headId;
                    if (h.isNotEmpty) headId = h.first['id'] as int;

                    // Insert loan
                    await dbInst.insert('staff_loans', {
                      'teacher_id': selectedTeacher,
                      'loan_amount': amt,
                      'installment_amount': inst,
                      'remaining_amount': amt,
                      'date_issued': date,
                      'description': descCtrl.text,
                      'system_type': SystemTypeGlobal.currentSystemType.value,
                    });

                    // Add to Finance (Loan Asset)
                    await dbInst.insert('finance', {
                      'type': 'Expense',
                      'amount': amt,
                      'date': date,
                      'head_id': headId,
                      'donor_name': _teachers.firstWhere((t) => t['id'] == selectedTeacher)['name'],
                      'description': 'Staff Loans & Advances: ${descCtrl.text}',
                      'is_synced': 0,
                      'system_type': sys,
                    });
                    
                    if (mounted) Navigator.pop(context);
                    _loadData();
                  },
                  child: const Text('محفوظ کریں', style: TextStyle(color: Colors.white)),
                ),
              ],
            );
          }
        );
      }
    );
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: ui.TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          backgroundColor: primaryGreen,
          title: const Text('عملہ کے قرض اور ایڈوانس', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          iconTheme: const IconThemeData(color: Colors.white),
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : _loans.isEmpty
                ? const Center(child: Text('کوئی ریکارڈ نہیں ملا'))
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: _loans.length,
                    itemBuilder: (ctx, i) {
                      final l = _loans[i];
                      final isPaid = l['status'] == 'Paid';
                      
                      return Card(
                        elevation: 3,
                        margin: const EdgeInsets.only(bottom: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        child: ListTile(
                          contentPadding: const EdgeInsets.all(16),
                          title: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(l['staff_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: isPaid ? Colors.grey.shade300 : Colors.green.shade100,
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                child: Text(isPaid ? 'ادا شدہ' : 'فعال', style: TextStyle(
                                  color: isPaid ? Colors.black54 : Colors.green.shade800,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                )),
                              )
                            ],
                          ),
                          subtitle: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const SizedBox(height: 8),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Text('کل قرض: Rs.${l['loan_amount']}', style: const TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                                  Text('بقیہ: Rs.${l['remaining_amount']}', style: const TextStyle(color: Colors.blue, fontWeight: FontWeight.bold)),
                                ],
                              ),
                              const SizedBox(height: 4),
                              Text('ماہانہ کٹوتی قسط: Rs.${l['installment_amount']}', style: const TextStyle(color: Colors.orange, fontWeight: FontWeight.bold)),
                              const SizedBox(height: 4),
                              Text('تاریخ: ${l['date_issued']}'),
                              if ((l['description'] ?? '').isNotEmpty)
                                Text('تفصیل: ${l['description']}', style: const TextStyle(color: Colors.grey)),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: _issueLoan,
          backgroundColor: primaryGreen,
          icon: const Icon(Icons.add, color: Colors.white),
          label: const Text('نیا قرض', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
    );
  }
}
