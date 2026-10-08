// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import '../utils/system_type_global.dart';
import 'dashboard.dart';

class RequestsPage extends StatefulWidget {
  const RequestsPage({super.key});

  @override
  State<RequestsPage> createState() => _RequestsPageState();
}

class _RequestsPageState extends State<RequestsPage>
    with SingleTickerProviderStateMixin {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  late TabController _tabController;
  List<Map<String, dynamic>> _fees = [];
  List<Map<String, dynamic>> _leaves = [];
  List<Map<String, dynamic>> _parentComplaints = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _loadData();
  }

  Future<void> _loadData() async {
    final db = DBHelper();
    final database = await db.database;

    final currentSys = SystemTypeGlobal.currentSystemType.value;
    final students = await db.getStudents(systemType: currentSys);

    // Join with student names
    Map<String, String> studentNames = {
      for (var s in students)
        (s['id']?.toString() ?? ''):
            "${s['name'] ?? ''} - ${s['father_name'] ?? ''}".trim()
    };
    final validStudentIds = studentNames.keys.toSet();

    final allFees = await database.query('fee_submissions',
        where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'id DESC');
    final allLeaves = await database.query('leave_applications',
        where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'id DESC');
    final allComplaints = await database.query('parent_complaints',
        where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'id DESC');

    final fees = allFees.where((f) => validStudentIds.contains(f['student_id']?.toString())).toList();
    final leaves = allLeaves.where((l) => validStudentIds.contains(l['student_id']?.toString())).toList();
    final complaints = allComplaints.where((c) => validStudentIds.contains(c['student_id']?.toString())).toList();

    setState(() {
      _fees = fees
          .map((f) => {
                ...f,
                'student_name':
                    studentNames[f['student_id'].toString()] ?? 'Unknown'
              })
          .toList();
      _leaves = leaves
          .map((l) => {
                ...l,
                'student_name':
                    studentNames[l['student_id'].toString()] ?? 'Unknown'
              })
          .toList();
      _parentComplaints = complaints
          .map((c) => {
                ...c,
                'student_name':
                    studentNames[c['student_id'].toString()] ?? 'Unknown'
              })
          .toList();
      _isLoading = false;
    });
  }

  Future<void> _addToReport(Map<String, dynamic> pc) async {
    final db = DBHelper();
    final database = await db.database;

    // 1. Update status in parent_complaints
    await database.update(
        'parent_complaints', {'status': 'Viewed', 'is_synced': 0},
        where: 'id = ?', whereArgs: [pc['id']]);

    // 2. Insert into official complaints table
    String msg = "[والدین کی طرف سے]: ${pc['category']} - ${pc['description']}";
    await database.insert('complaints', {
      'student_id': pc['student_id'],
      'date': pc['date'],
      'complaint': msg,
      'is_synced': 0
    });

    /*
    if (status == 'Approved' &&
        table == 'fee_submissions' &&
        extraData != null) {
      final existingFinanceId =
          int.tryParse(extraData['finance_id']?.toString() ?? '');
      if (existingFinanceId == null || existingFinanceId <= 0) {
        final lastRows =
            await database.rawQuery('SELECT last_insert_rowid() as id');
        final financeId = int.tryParse(lastRows.first['id']?.toString() ?? '');
        if (financeId != null && financeId > 0) {
          final receiptNo = await _nextComputerReceiptNo(database);
          final desc = extraData['description']?.toString().trim();
          await database.update(
            'finance',
            {
              'date': _today(),
              'description': (desc != null && desc.isNotEmpty)
                  ? '$desc (تعاؤن ID: $id)'
                  : 'Online Cooperation/Fee (ID: $id)',
              'head_id': extraData['head_id'],
              'manual_receipt_no': '',
              'donor_name': extraData['student_name'],
              'donor_address': '',
              'computer_receipt_no': receiptNo,
              'is_synced': 0,
            },
            where: 'id = ?',
            whereArgs: [financeId],
          );
          updateData['finance_id'] = financeId;
        }
      }
    }

    await database.update(table, updateData, where: 'id = ?', whereArgs: [id]);
    */

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
          content: Text(isUrduGlobal.value
              ? "رپورٹ میں شامل کر دیا گیا!"
              : "Added to student report!")),
    );
    _loadData();
  }

  Future<void> _markComplaintViewed(Map<String, dynamic> complaint) async {
    final database = await DBHelper().database;
    await database.update(
      'parent_complaints',
      {'status': 'Viewed', 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [complaint['id']],
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(isUrduGlobal.value
            ? 'شکایت دیکھی ہوئی مارک کر دی گئی'
            : 'Complaint marked as viewed'),
      ),
    );
    _loadData();
  }

  Future<void> _addLeaveToReport(Map<String, dynamic> leave) async {
    final database = await DBHelper().database;
    await database.update(
      'leave_applications',
      {'status': 'Approved', 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [leave['id']],
    );
    await database.insert('complaints', {
      'student_id': leave['student_id'],
      'date': leave['date_from'],
      'complaint':
          '[چھٹی کی درخواست]: ${leave['reason'] ?? ''} (${leave['date_from'] ?? ''} تا ${leave['date_to'] ?? ''})',
      'is_synced': 0,
    });
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(isUrduGlobal.value
            ? 'چھٹی منظور کر کے رپورٹ میں شامل کر دی گئی'
            : 'Leave approved and added to report'),
      ),
    );
    _loadData();
  }

  Future<void> _deleteRequestRow(String table, int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'تصدیق' : 'Confirm'),
        content: Text(isUrduGlobal.value
            ? 'کیا آپ واقعی یہ انٹری حذف کرنا چاہتے ہیں؟'
            : 'Delete this entry?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(isUrduGlobal.value ? 'نہیں' : 'No'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
                backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isUrduGlobal.value ? 'حذف کریں' : 'Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final database = await DBHelper().database;
    await database.update(
      table,
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );
    if (!mounted) return;
    _loadData();
  }

  Future<void> _updateStatus(String table, int id, String status,
      {Map<String, dynamic>? extraData}) async {
    final db = DBHelper();
    final database = await db.database;

    final updateData = <String, dynamic>{'status': status, 'is_synced': 0};

    if (status == 'Approved' &&
        table == 'fee_submissions' &&
        extraData != null) {
      // فنانس میں انٹری کریں
      await database.insert('finance', {
        'date': extraData['submission_date'],
        'type': 'Income',
        'amount': extraData['amount'],
        'description': "فیس وصولی: ${extraData['student_name']}",
        'is_synced': 0
      });
    }

    if (status == 'Approved' &&
        table == 'fee_submissions' &&
        extraData != null) {
      final existingFinanceId =
          int.tryParse(extraData['finance_id']?.toString() ?? '');
      if (existingFinanceId == null || existingFinanceId <= 0) {
        final lastRows =
            await database.rawQuery('SELECT last_insert_rowid() as id');
        final financeId = int.tryParse(lastRows.first['id']?.toString() ?? '');
        if (financeId != null && financeId > 0) {
          final receiptNo = await _nextComputerReceiptNo(database);
          final desc = extraData['description']?.toString().trim();
          await database.update(
            'finance',
            {
              'date': _today(),
              'description': (desc != null && desc.isNotEmpty)
                  ? '$desc (تعاؤن ID: $id)'
                  : 'Online Cooperation/Fee (ID: $id)',
              'head_id': extraData['head_id'],
              'manual_receipt_no': '',
              'donor_name': extraData['student_name'],
              'donor_address': '',
              'computer_receipt_no': receiptNo,
              'is_synced': 0,
            },
            where: 'id = ?',
            whereArgs: [financeId],
          );
          updateData['finance_id'] = financeId;
        }
      }
    }

    await database.update(table, updateData, where: 'id = ?', whereArgs: [id]);

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
          content: Text(isUrduGlobal.value
              ? "سٹیٹس اپڈیٹ کر دیا گیا!"
              : "Status updated!")),
    );
    _loadData();
  }

  String _today() {
    final now = DateTime.now();
    return '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
  }

  Future<int> _nextComputerReceiptNo(dynamic database) async {
    final userId = currentUserId.value ?? 1;
    final int minNo;
    final int maxLimit;
    if (userId == 1) {
      minNo = 300000;
      maxLimit = 400000;
    } else if (userId == 3) {
      minNo = 700000;
      maxLimit = 800000;
    } else {
      minNo = userId * 100000;
      maxLimit = (userId + 1) * 100000;
    }
    final rows = await database.rawQuery(
      'SELECT MAX(computer_receipt_no) as max_no FROM finance WHERE computer_receipt_no >= ? AND computer_receipt_no < ? AND (is_deleted = 0 OR is_deleted IS NULL)',
      [minNo, maxLimit],
    );
    final maxNo = int.tryParse(rows.first['max_no']?.toString() ?? '');
    return (maxNo == null || maxNo < minNo) ? minNo + 1 : maxNo + 1;
  }

  Future<void> _showFeeReceipt(Map<String, dynamic> fee) async {
    final financeId = int.tryParse(fee['finance_id']?.toString() ?? '');
    if (financeId == null || financeId <= 0) return;
    final database = await DBHelper().database;
    final rows = await database.query(
      'finance',
      where: 'id = ?',
      whereArgs: [financeId],
      limit: 1,
    );
    if (!mounted) return;
    if (rows.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('رسید کی فنانس انٹری موجود نہیں')),
      );
      return;
    }
    final r = rows.first;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('رسید'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('رسید نمبر: ${r['computer_receipt_no'] ?? '-'}'),
            Text('تاریخ: ${r['date'] ?? '-'}'),
            Text(
                'طالب علم/نام: ${r['donor_name'] ?? fee['student_name'] ?? '-'}'),
            Text('رقم: Rs ${r['amount'] ?? fee['amount'] ?? '-'}'),
            Text('تفصیل: ${r['description'] ?? '-'}'),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('بند کریں'),
          ),
        ],
      ),
    );
  }

  void _viewScreenshot(String? path) {
    if (path == null || path.isEmpty) return;

    // اگر پاتھ پہلے سے مکمل URL ہے تو ویسے رکھیں، ورنہ سرور سے جوڑیں
    // path مثال: "uploads/fees/1234_file.jpg" یا مکمل URL
    String imageUrl;
    if (path.startsWith('http://') || path.startsWith('https://')) {
      imageUrl = path;
    } else {
      // uploads/ سے شروع ہوتا ہے تو apiUrl کے ساتھ جوڑیں (بغیر اضافی slash کے)
      final base = apiUrl.endsWith('/') ? apiUrl : '$apiUrl/';
      final cleanPath = path.startsWith('/') ? path.substring(1) : path;
      imageUrl = '$base$cleanPath';
    }

    showDialog(
      context: context,
      builder: (context) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: const BoxDecoration(
                color: Color(0xFF284D3D),
                borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(15),
                    topRight: Radius.circular(15)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.image, color: Colors.white, size: 20),
                  const SizedBox(width: 8),
                  Text(
                    isUrduGlobal.value
                        ? "سکرین شاٹ / تصویر"
                        : "Screenshot / Image",
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 15),
                  ),
                  const Spacer(),
                  GestureDetector(
                    onTap: () => Navigator.pop(context),
                    child: const Icon(Icons.close,
                        color: Colors.white70, size: 20),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(10),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Image.network(
                  imageUrl,
                  fit: BoxFit.contain,
                  loadingBuilder: (context, child, loadingProgress) {
                    if (loadingProgress == null) return child;
                    return const Padding(
                      padding: EdgeInsets.all(40),
                      child:
                          CircularProgressIndicator(color: Color(0xFF284D3D)),
                    );
                  },
                  errorBuilder: (context, error, stackTrace) => const Padding(
                    padding: EdgeInsets.all(30),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.broken_image, size: 60, color: Colors.grey),
                        SizedBox(height: 8),
                        Text("تصویر لوڈ نہیں ہو سکی",
                            style: TextStyle(color: Colors.grey)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrduGlobal.value ? "بند کریں" : "Close"),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // base64 image کو موبائل پر سیدھے دکھائیں (سنک سے پہلے)
  void _viewBase64Image(String? base64Data) {
    if (base64Data == null || base64Data.isEmpty) return;
    String raw = base64Data;
    if (raw.contains(';base64,')) raw = raw.split(';base64,')[1];
    final bytes = base64Decode(raw);
    showDialog(
      context: context,
      builder: (context) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: const BoxDecoration(
                color: Color(0xFF284D3D),
                borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(15),
                    topRight: Radius.circular(15)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.image, color: Colors.white, size: 20),
                  const SizedBox(width: 8),
                  Text(
                      isUrduGlobal.value
                          ? "سکرین شاٹ / تصویر"
                          : "Screenshot / Image",
                      style: const TextStyle(
                          color: Colors.white, fontWeight: FontWeight.bold)),
                  const Spacer(),
                  GestureDetector(
                      onTap: () => Navigator.pop(context),
                      child: const Icon(Icons.close,
                          color: Colors.white70, size: 20)),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(10),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Image.memory(bytes, fit: BoxFit.contain),
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrduGlobal.value ? "بند کریں" : "Close"),
              ),
            ),
          ],
        ),
      ),
    );
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
            Navigator.pushReplacement(context,
                MaterialPageRoute(builder: (c) => const DashboardPage()));
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
                    isUrdu ? "درخواستیں و شکایات" : "Requests & Complaints",
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, color: Colors.white)),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
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
                          _loadData();
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
                bottom: TabBar(
                  controller: _tabController,
                  indicatorColor: Colors.white,
                  labelColor: Colors.white,
                  unselectedLabelColor: Colors.white70,
                  labelStyle: const TextStyle(fontWeight: FontWeight.bold),
                  tabs: [
                    Tab(text: isUrdu ? "فیس" : "Fees"),
                    Tab(text: isUrdu ? "چھٹی" : "Leaves"),
                    Tab(text: isUrdu ? "شکایات" : "Complaints"),
                  ],
                ),
              ),
              body: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 1100),
                        child: TabBarView(
                          controller: _tabController,
                          children: [
                            _buildFeeTab(isUrdu),
                            _buildLeaveTab(isUrdu),
                            _buildComplaintTab(isUrdu),
                          ],
                        ),
                      ),
                    ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildFeeTab(bool isUrdu) {
    if (_fees.isEmpty) return _buildEmptyState(isUrdu);
    return ListView.builder(
      padding: const EdgeInsets.all(10),
      itemCount: _fees.length,
      itemBuilder: (context, i) {
        final f = _fees[i];
        bool isPending = f['status'] == 'Pending';
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: Column(
            children: [
              ListTile(
                title: Text(f['student_name'] ?? '',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                subtitle: Text(
                    "${isUrdu ? 'رقم' : 'Amount'}: ${f['amount']} | ${isUrdu ? 'تاریخ' : 'Date'}: ${f['submission_date']}"),
                trailing: _buildStatusBadge(f['status'] ?? 'Pending', isUrdu),
              ),
              if (!isPending) _processedFeeActions(f, isUrdu),
              if (isPending)
                Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      if (f['screenshot'] != null &&
                          f['screenshot'].toString().isNotEmpty &&
                          !f['screenshot'].toString().startsWith('data:'))
                        TextButton.icon(
                          onPressed: () => _viewScreenshot(f['screenshot']),
                          icon: const Icon(Icons.image, size: 16),
                          label: Text(isUrdu ? "سکرین شاٹ" : "Screenshot"),
                        ),
                      if (f['screenshot'] != null &&
                          f['screenshot'].toString().startsWith('data:'))
                        TextButton.icon(
                          onPressed: () => _viewBase64Image(f['screenshot']),
                          icon: const Icon(Icons.image, size: 16),
                          label: Text(isUrdu ? "سکرین شاٹ" : "Screenshot"),
                        ),
                      const Spacer(),
                      TextButton(
                        onPressed: () => _updateStatus(
                            'fee_submissions', f['id'], 'Rejected'),
                        style:
                            TextButton.styleFrom(foregroundColor: Colors.red),
                        child: Text(isUrdu ? "مسترد" : "Reject"),
                      ),
                      ElevatedButton(
                        onPressed: () => _updateStatus(
                            'fee_submissions', f['id'], 'Approved',
                            extraData: f),
                        style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.green,
                            foregroundColor: Colors.white),
                        child: Text(isUrdu ? "منظور" : "Approve"),
                      ),
                    ],
                  ),
                ),
              Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton.icon(
                      onPressed: () => _deleteRequestRow(
                          'fee_submissions', (f['id'] as num).toInt()),
                      icon: const Icon(Icons.delete_outline, size: 16),
                      label: Text(isUrdu ? 'حذف' : 'Delete'),
                      style: TextButton.styleFrom(foregroundColor: Colors.red),
                    ),

                    /*
                    ElevatedButton.icon(
                      onPressed: () => _deleteRequestRow(
                          'fee_submissions', (f['id'] as num).toInt()),
                      icon: const Icon(Icons.add_chart, size: 16),
                      label: Text(isUrdu ? 'رپورٹ میں ڈالیں' : 'Add to Report'),
                      style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.black87,
                          foregroundColor: Colors.white),
                    ),
                    */
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _processedFeeActions(Map<String, dynamic> fee, bool isUrdu) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          if (int.tryParse(fee['finance_id']?.toString() ?? '') != null)
            TextButton.icon(
              onPressed: () => _showFeeReceipt(fee),
              icon: const Icon(Icons.receipt_long, size: 16),
              label: Text(isUrdu ? 'رسید' : 'Receipt'),
            )
          else if (fee['screenshot'] != null &&
              fee['screenshot'].toString().isNotEmpty &&
              !fee['screenshot'].toString().startsWith('data:'))
            TextButton.icon(
              onPressed: () => _viewScreenshot(fee['screenshot']),
              icon: const Icon(Icons.image, size: 16),
              label: Text(isUrdu ? 'ثبوت' : 'Proof'),
            )
          else if (fee['screenshot'] != null &&
              fee['screenshot'].toString().startsWith('data:'))
            TextButton.icon(
              onPressed: () => _viewBase64Image(fee['screenshot']),
              icon: const Icon(Icons.image, size: 16),
              label: Text(isUrdu ? 'ثبوت' : 'Proof'),
            )
          else
            Text(
              isUrdu ? 'ثبوت موجود نہیں' : 'No proof',
              style: TextStyle(color: Colors.grey.shade600),
            ),
        ],
      ),
    );
  }

  Widget _buildLeaveTab(bool isUrdu) {
    if (_leaves.isEmpty) return _buildEmptyState(isUrdu);
    return ListView.builder(
      padding: const EdgeInsets.all(10),
      itemCount: _leaves.length,
      itemBuilder: (context, i) {
        final l = _leaves[i];
        bool isPending = l['status'] == 'Pending';
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: Column(
            children: [
              ListTile(
                title: Text(l['student_name'] ?? '',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                subtitle: Text(
                    "${l['date_from']} to ${l['date_to']}\n${l['reason']}"),
                trailing: _buildStatusBadge(l['status'] ?? 'Pending', isUrdu),
                isThreeLine: true,
              ),
              _leaveExtraActions(l, isUrdu),
              if (isPending)
                Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      if (l['application_photo'] != null &&
                          l['application_photo'].toString().isNotEmpty &&
                          !l['application_photo']
                              .toString()
                              .startsWith('data:'))
                        TextButton.icon(
                          onPressed: () =>
                              _viewScreenshot(l['application_photo']),
                          icon: const Icon(Icons.image, size: 16),
                          label: Text(isUrdu ? "سکرین شاٹ" : "Screenshot"),
                        ),
                      if (l['application_photo'] != null &&
                          l['application_photo'].toString().startsWith('data:'))
                        TextButton.icon(
                          onPressed: () =>
                              _viewBase64Image(l['application_photo']),
                          icon: const Icon(Icons.image, size: 16),
                          label: Text(isUrdu ? "درخواست" : "Application"),
                        ),
                      const Spacer(),
                      TextButton(
                        onPressed: () => _updateStatus(
                            'leave_applications', l['id'], 'Rejected'),
                        style:
                            TextButton.styleFrom(foregroundColor: Colors.red),
                        child: Text(isUrdu ? "مسترد" : "Reject"),
                      ),
                      const SizedBox(width: 10),
                      ElevatedButton(
                        onPressed: () => _updateStatus(
                            'leave_applications', l['id'], 'Approved'),
                        style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.green,
                            foregroundColor: Colors.white),
                        child: Text(isUrdu ? "منظور" : "Approve"),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _leaveExtraActions(Map<String, dynamic> leave, bool isUrdu) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          TextButton.icon(
            onPressed: () => _deleteRequestRow(
                'leave_applications', (leave['id'] as num).toInt()),
            icon: const Icon(Icons.delete_outline, size: 16),
            label: Text(isUrdu ? 'حذف' : 'Delete'),
            style: TextButton.styleFrom(foregroundColor: Colors.red),
          ),
          const SizedBox(width: 8),
          ElevatedButton.icon(
            onPressed: () => _addLeaveToReport(leave),
            icon: const Icon(Icons.add_chart, size: 16),
            label: Text(isUrdu ? 'رپورٹ میں ڈالیں' : 'Add to Report'),
            style: ElevatedButton.styleFrom(
                backgroundColor: Colors.black87, foregroundColor: Colors.white),
          ),
        ],
      ),
    );
  }

  Widget _buildComplaintTab(bool isUrdu) {
    if (_parentComplaints.isEmpty) return _buildEmptyState(isUrdu);

    // Group complaints by category
    Map<String, List<Map<String, dynamic>>> grouped = {};
    for (var c in _parentComplaints) {
      String cat = c['category'] ?? (isUrdu ? 'دیگر' : 'Other');
      if (!grouped.containsKey(cat)) grouped[cat] = [];
      grouped[cat]!.add(c);
    }

    List<String> categories = grouped.keys.toList();

    return ListView.builder(
      padding: const EdgeInsets.all(10),
      itemCount: categories.length,
      itemBuilder: (context, i) {
        String cat = categories[i];
        List<Map<String, dynamic>> categoryComplaints = grouped[cat]!;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 5),
              child: Text(
                cat,
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: primaryGreen,
                ),
              ),
            ),
            ...categoryComplaints.map((c) {
              bool isPending = c['status'] == 'Pending';
              return Card(
                elevation: isPending ? 4 : 1,
                color: isPending ? Colors.red.shade50 : Colors.white,
                margin: const EdgeInsets.only(bottom: 15),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                              child: Text(c['student_name'] ?? '',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16))),
                          Text(c['date'] ?? '',
                              style: const TextStyle(
                                  fontSize: 12, color: Colors.grey)),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(c['description'] ?? '',
                          style: const TextStyle(fontSize: 14)),
                      const Divider(),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          _buildStatusBadge(c['status'] ?? 'Pending', isUrdu),
                          if (isPending)
                            Row(
                              children: [
                                if (c['evidence'] != null &&
                                    c['evidence'].toString().isNotEmpty)
                                  IconButton(
                                    onPressed: () =>
                                        _viewScreenshot(c['evidence']),
                                    icon: const Icon(Icons.image,
                                        color: Colors.blue),
                                    tooltip:
                                        isUrdu ? "سکرین شاٹ" : "Screenshot",
                                  ),
                                TextButton(
                                  onPressed: () => _markComplaintViewed(c),
                                  child: Text(isUrdu ? 'صرف دیکھیں' : 'Viewed'),
                                ),
                                ElevatedButton.icon(
                                  onPressed: () => _addToReport(c),
                                  icon: const Icon(Icons.add_chart, size: 16),
                                  label: Text(isUrdu
                                      ? "رپورٹ میں ڈالیں"
                                      : "Add to Report"),
                                  style: ElevatedButton.styleFrom(
                                      backgroundColor: Colors.red.shade700,
                                      foregroundColor: Colors.white),
                                ),
                                IconButton(
                                  onPressed: () => _deleteRequestRow(
                                      'parent_complaints',
                                      (c['id'] as num).toInt()),
                                  icon: const Icon(Icons.delete_outline,
                                      color: Colors.red),
                                  tooltip: isUrdu ? 'حذف' : 'Delete',
                                ),
                              ],
                            )
                          else
                            Row(
                              children: [
                                const Text("Processed",
                                    style: TextStyle(
                                        color: Colors.grey, fontSize: 12)),
                                IconButton(
                                  onPressed: () => _deleteRequestRow(
                                      'parent_complaints',
                                      (c['id'] as num).toInt()),
                                  icon: const Icon(Icons.delete_outline,
                                      color: Colors.red),
                                  tooltip: isUrdu ? 'حذف' : 'Delete',
                                ),
                              ],
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            }),
          ],
        );
      },
    );
  }

  Widget _buildStatusBadge(String status, bool isUrdu) {
    Color color = Colors.grey;
    if (status == 'Approved' || status == 'Viewed') color = Colors.green;
    if (status == 'Rejected') color = Colors.red;
    if (status == 'Pending') color = Colors.orange;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
          color: color.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color)),
      child: Text(status,
          style: TextStyle(
              color: color, fontSize: 11, fontWeight: FontWeight.bold)),
    );
  }

  Widget _buildEmptyState(bool isUrdu) {
    return Center(
        child: Text(isUrdu ? "کوئی ریکارڈ نہیں ملا" : "No records found."));
  }
}
