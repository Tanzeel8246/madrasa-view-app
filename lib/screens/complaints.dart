// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';
import 'complaints_history.dart';
import 'dart:ui' as ui;

class ComplaintsPage extends StatefulWidget {
  const ComplaintsPage({super.key});

  @override
  State<ComplaintsPage> createState() => _ComplaintsPageState();
}

class _ComplaintsPageState extends State<ComplaintsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);
  final _complaintController = TextEditingController();
  final _dateController = TextEditingController(
      text: DateTime.now().toIso8601String().split('T')[0]);
  String? _selectedCategory;
  final List<String> _categories = [
    'تعلیمی (Educational)',
    'اخلاقی / تربیتی (Behavioral)',
    'نظم و ضبط (Discipline)',
    'فیس / مالیات (Financial)',
    'دیگر (Other)',
    'نیا شامل کریں (Add New)'
  ];
  final _customCategoryController = TextEditingController();
  List<Map<String, dynamic>> _recentComplaints = [];
  List<Map<String, dynamic>> _students = [];
  List<int> _selectedStudentIds = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadStudents();
    _loadHistory();
  }

  Future<void> _loadStudents() async {
    final data = await DBHelper().getStudentsForUser();
    setState(() {
      _students = data
          .where((s) =>
              s['status'] == 'Active' &&
              (s['is_deleted'] == 0 || s['is_deleted'] == null))
          .toList();
      if (currentUserRole.value == 'student' && currentUserId.value != null) {
        _selectedStudentIds = [currentUserId.value!];
      }
    });
  }

  Future<void> _loadHistory() async {
    final db = DBHelper();
    final data = await db.database;
    final categoryRows = await data.query('parent_complaints',
        columns: ['category'], distinct: true);
    final complaints = await DBHelper().getComplaints();
    final students = await DBHelper().getStudentsForUser();
    final allowedStudentIds = students.map((s) => s['id']).toSet();
    final recent = <Map<String, dynamic>>[];
    for (final c in complaints) {
      if (c['is_deleted'] == 1) continue;
      if (!allowedStudentIds.contains(c['student_id'])) continue;
      final student = students.firstWhere(
        (s) => s['id'] == c['student_id'],
        orElse: () => <String, dynamic>{},
      );
      final father = (student['father_name'] ?? student['father'] ?? '').toString().trim();
      final name = student['name'] ?? '-';
      final fullName = father.isNotEmpty ? "$name ولد $father" : name;
      recent.add({
        ...c,
        'student_name': fullName,
        'admission_number': student['admission_number'] ?? '',
      });
      if (recent.length >= 50) break;
    }

    setState(() {
      _recentComplaints = recent;
      _isLoading = false;

      for (var r in categoryRows) {
        final cat = r['category']?.toString();
        if (cat != null && cat.isNotEmpty && !_categories.contains(cat)) {
          _categories.insert(_categories.length - 1, cat);
        }
      }
    });
  }

  Future<void> _deleteRecentComplaint(Map<String, dynamic> complaint) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'تصدیق' : 'Confirm'),
        content: Text(isUrduGlobal.value
            ? 'کیا آپ یہ شکایت حذف کرنا چاہتے ہیں؟'
            : 'Delete this complaint?'),
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

    if (confirmed == true) {
      await DBHelper().deleteComplaint((complaint['id'] as num).toInt());
      await _loadHistory();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'شکایت حذف کر دی گئی ہے'
              : 'Complaint deleted'),
          backgroundColor: Colors.green,
        ),
      );
    }
  }

  @override
  void dispose() {
    _complaintController.dispose();
    _dateController.dispose();
    _customCategoryController.dispose();
    super.dispose();
  }

  Future<void> _runSync() async {
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text("Syncing...")));
    try {
      await SyncService.runTwoWaySync();
      _loadHistory();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text("Synced successfully"), backgroundColor: Colors.green));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text("Sync failed: $e"), backgroundColor: Colors.red));
    }
  }

  Future<void> _submitComplaint() async {
    if (_complaintController.text.trim().isEmpty ||
        _selectedCategory == null ||
        _selectedStudentIds.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(isUrduGlobal.value
                ? "طالب علم، نوعیت اور تفصیل لکھنا ضروری ہے"
                : "Student, Category and Description are required")),
      );
      return;
    }

    String finalCategory = _selectedCategory!;
    if (_selectedCategory == 'نیا شامل کریں (Add New)') {
      if (_customCategoryController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(isUrduGlobal.value
                  ? "نئی کیٹیگری کا نام لکھیں"
                  : "Enter new category name")),
        );
        return;
      }
      finalCategory = _customCategoryController.text.trim();
    }

    try {
      for (int studentId in _selectedStudentIds) {
        if (currentUserRole.value != 'student') {
          // Admin/Teacher -> Direct to official complaints
          await DBHelper().insertComplaint({
            'student_id': studentId,
            'complaint':
                "[${currentUserRole.value == 'admin' ? 'ایڈمن' : 'استاد'} کی طرف سے]: $finalCategory - ${_complaintController.text.trim()}",
            'date': _dateController.text,
            'is_synced': 0,
          });
        } else {
          // Student/Parent -> To parent_complaints for approval
          await DBHelper().insertParentComplaint({
            'student_id': studentId,
            'date': _dateController.text,
            'category': finalCategory,
            'description': _complaintController.text.trim(),
            'status': 'Pending',
            'is_synced': 0,
          });
        }
      }

      _complaintController.clear();
      setState(() => _selectedCategory = null);
      _loadHistory();
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text("Error: $e")));
      return;
    }

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          isUrduGlobal.value
              ? 'شکایت کامیابی سے درج کر دی گئی ہے۔'
              : 'Complaint submitted successfully.',
        ),
        backgroundColor: Colors.green,
      ),
    );
  }

  Future<void> _showMultiSelectDialog() async {
    final List<int> initialSelected = List.from(_selectedStudentIds);
    await showDialog(
      context: context,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            return AlertDialog(
              title: Text(
                  isUrduGlobal.value ? 'طلبہ منتخب کریں' : 'Select Students'),
              content: SizedBox(
                width: double.maxFinite,
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: _students.length,
                  itemBuilder: (context, index) {
                    final student = _students[index];
                    final isSelected = initialSelected.contains(student['id']);
                    final father = (student['father_name'] ?? student['father'] ?? '').toString().trim();
                    final displayName = father.isNotEmpty
                        ? "${student['name']} ولد $father"
                        : "${student['name']}";
                    return CheckboxListTile(
                      title: Text(displayName),
                      value: isSelected,
                      onChanged: (val) {
                        setDialogState(() {
                          if (val == true) {
                            initialSelected.add(student['id']);
                          } else {
                            initialSelected.remove(student['id']);
                          }
                        });
                      },
                    );
                  },
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: Text(isUrduGlobal.value ? 'منسوخ' : 'Cancel'),
                ),
                ElevatedButton(
                  onPressed: () {
                    setState(() {
                      _selectedStudentIds = initialSelected;
                    });
                    _loadHistory();
                    Navigator.pop(context);
                  },
                  child: Text(isUrduGlobal.value ? 'منتخب کریں' : 'Select'),
                ),
              ],
            );
          },
        );
      },
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
                  isUrdu ? 'تجاویز و شکایات' : 'Suggestions & Complaints',
                  style: TextStyle(
                      color: primaryGreen, fontWeight: FontWeight.bold),
                ),
                backgroundColor: bgColor,
                elevation: 0,
                iconTheme: IconThemeData(color: primaryGreen),
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: _runSync,
                  ),
                ],
              ),
              body: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 1100),
                        child: SingleChildScrollView(
                          padding: const EdgeInsets.all(15),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Icon(Icons.feedback,
                              size: 60, color: Colors.orange),
                          const SizedBox(height: 20),
                          if (currentUserRole.value != 'student')
                            ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.white,
                                foregroundColor: primaryGreen,
                                side: BorderSide(color: primaryGreen),
                                padding:
                                    const EdgeInsets.symmetric(vertical: 15),
                              ),
                              icon: const Icon(Icons.group_add),
                              label: Text(
                                _selectedStudentIds.isEmpty
                                    ? (isUrdu
                                        ? 'طلبہ منتخب کریں'
                                        : 'Select Students')
                                    : (isUrdu
                                        ? '${_selectedStudentIds.length} طلبہ منتخب ہیں'
                                        : '${_selectedStudentIds.length} Students Selected'),
                              ),
                              onPressed: _showMultiSelectDialog,
                            ),
                          if (currentUserRole.value != 'student')
                            const SizedBox(height: 15),
                          TextField(
                            controller: _dateController,
                            readOnly: true,
                            onTap: () async {
                              final DateTime? picked = await showDatePicker(
                                context: context,
                                initialDate:
                                    DateTime.tryParse(_dateController.text) ??
                                        DateTime.now(),
                                firstDate: DateTime.now()
                                    .subtract(const Duration(days: 30)),
                                lastDate: DateTime.now(),
                              );
                              if (picked != null) {
                                setState(() {
                                  _dateController.text =
                                      picked.toIso8601String().split('T')[0];
                                });
                              }
                            },
                            decoration: InputDecoration(
                              labelText: isUrdu ? 'تاریخ وقوعہ (Date)' : 'Date',
                              border: const OutlineInputBorder(),
                              prefixIcon: const Icon(Icons.calendar_today),
                            ),
                          ),
                          const SizedBox(height: 15),
                          DropdownButtonFormField<String>(
                            value: _selectedCategory,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'شکایت کی نوعیت (Category)'
                                  : 'Category',
                              border: const OutlineInputBorder(),
                              prefixIcon: const Icon(Icons.category),
                            ),
                            items: _categories.map((c) {
                              return DropdownMenuItem<String>(
                                value: c,
                                child: Text(c),
                              );
                            }).toList(),
                            onChanged: (val) {
                              setState(() {
                                _selectedCategory = val;
                              });
                            },
                          ),
                          if (_selectedCategory ==
                              'نیا شامل کریں (Add New)') ...[
                            const SizedBox(height: 15),
                            TextField(
                              controller: _customCategoryController,
                              decoration: InputDecoration(
                                labelText: isUrduGlobal.value
                                    ? 'نئی کیٹیگری کا نام لکھیں'
                                    : 'Enter New Category',
                                border: const OutlineInputBorder(),
                              ),
                            ),
                          ],
                          const SizedBox(height: 15),
                          TextField(
                            controller: _complaintController,
                            maxLines: 4,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? 'تفصیل (Description)'
                                  : 'Description',
                              border: const OutlineInputBorder(),
                              alignLabelWithHint: true,
                            ),
                          ),
                          const SizedBox(height: 20),
                          ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                              padding: const EdgeInsets.all(15),
                            ),
                            onPressed: _submitComplaint,
                            child: Text(
                              isUrdu ? 'شکایت درج کریں' : 'Submit Complaint',
                              style: const TextStyle(
                                  fontSize: 18, color: Colors.white),
                            ),
                          ),
                          const SizedBox(height: 20),
                          OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              padding: const EdgeInsets.all(15),
                              side: BorderSide(color: primaryGreen, width: 2),
                            ),
                            onPressed: () {
                              Navigator.push(
                                context,
                                MaterialPageRoute(
                                  builder: (c) => ComplaintsHistoryPage(
                                      studentId: (_selectedStudentIds.isNotEmpty
                                              ? _selectedStudentIds.first
                                              : null) ??
                                          (currentUserRole.value == 'student'
                                              ? currentUserId.value
                                              : null)),
                                ),
                              );
                            },
                            icon: Icon(Icons.history, color: primaryGreen),
                            label: Text(
                              isUrdu ? 'سابقہ ہسٹری دیکھیں' : 'View History',
                              style: TextStyle(
                                  fontSize: 18,
                                  color: primaryGreen,
                                  fontWeight: FontWeight.bold),
                            ),
                          ),
                          const SizedBox(height: 20),
                          _recentComplaintsCard(isUrdu),
                        ],
                      ),
                    ),
                  ),
                ),
            ),
          ),
        );
      },
    );
  }

  Widget _recentComplaintsCard(bool isUrdu) {
    if (currentUserRole.value == 'student') return const SizedBox.shrink();

    return Card(
      elevation: 3,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.history, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isUrdu
                        ? 'حالیہ ریکارڈ (آخری 50)'
                        : 'Recent Records (Last 50)',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ),
              ],
            ),
            const Divider(),
            if (_recentComplaints.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Center(
                  child: Text(isUrdu
                      ? 'کوئی شکایت / نوٹ موجود نہیں'
                      : 'No complaint or note found'),
                ),
              )
            else
              ..._recentComplaints.map((complaint) {
                final parts = _splitComplaintText(
                    complaint['complaint']?.toString() ?? '');
                return Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    border:
                        Border.all(color: Colors.red.withValues(alpha: .25)),
                    borderRadius: BorderRadius.circular(8),
                    color: Colors.red.withValues(alpha: .04),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              '${complaint['student_name'] ?? '-'}'
                              '${(complaint['admission_number'] ?? '').toString().isNotEmpty ? ' (${complaint['admission_number']})' : ''}',
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: Colors.blue),
                            ),
                          ),
                          Text(
                            complaint['date']?.toString() ?? '',
                            style: TextStyle(
                                color: Colors.grey.shade700, fontSize: 12),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        parts.$1,
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, color: Colors.orange),
                      ),
                      const SizedBox(height: 4),
                      Text(parts.$2),
                      const SizedBox(height: 8),
                      Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: TextButton.icon(
                          style: TextButton.styleFrom(
                              foregroundColor: Colors.red,
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 8)),
                          onPressed: () => _deleteRecentComplaint(complaint),
                          icon: const Icon(Icons.delete_outline, size: 18),
                          label: Text(isUrdu ? 'حذف' : 'Delete'),
                        ),
                      ),
                    ],
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }

  (String, String) _splitComplaintText(String raw) {
    final match = RegExp(r'^\[(.*?)\]\s*(.*)$', dotAll: true).firstMatch(raw);
    if (match == null) return ('General', raw);
    return (match.group(1) ?? 'General', match.group(2) ?? '');
  }
}
