// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'sanad_preview_screen.dart';
import 'students.dart';

class AlumniRegisterPage extends StatefulWidget {
  const AlumniRegisterPage({super.key});

  @override
  State<AlumniRegisterPage> createState() => _AlumniRegisterPageState();
}

class _AlumniRegisterPageState extends State<AlumniRegisterPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  List<Map<String, dynamic>> _alumni = [];
  bool _isLoading = true;
  bool _isPrinting = false;
  String _selectedCategory = 'all'; // all, completed, dropped, expelled

  int _countAll = 0;
  int _countCompleted = 0;
  int _countDropped = 0;
  int _countExpelled = 0;

  @override
  void initState() {
    super.initState();
    _loadAlumni();
  }

  static String resolveCategory(Map<String, dynamic> s) {
    final cat = (s['alumni_category'] ?? '').toString().trim().toLowerCase();
    final reason = (s['leaving_reason'] ?? '').toString().trim();
    final status = (s['status'] ?? '').toString().trim().toLowerCase();

    // 1. Direct match on alumni_category
    if (cat == 'expelled' || cat.contains('خارج') || cat.contains('اخراج')) {
      return 'expelled';
    }
    if (cat == 'dropped' || cat.contains('چھوڑ') || cat.contains('ترک') || cat.contains('شوقیہ')) {
      return 'dropped';
    }

    // 2. Reason or status indicators
    if (reason.contains('خارج') || reason.contains('اخراج') || status.contains('خارج') || status == 'struck off' || status == 'expelled') {
      return 'expelled';
    }
    if (reason.contains('چھوڑ') || reason.contains('شوقیہ') || reason.contains('ترک') || status == 'left' || status == 'dropped') {
      return 'dropped';
    }

    return 'completed';
  }

  Future<void> _loadAlumni() async {
    final db = DBHelper();
    final all = await db.getPagedStudents(limit: 2000, offset: 0, status: 'Alumni');
    
    int cAll = all.length;
    int cCompleted = 0;
    int cDropped = 0;
    int cExpelled = 0;

    for (var s in all) {
      final c = resolveCategory(s);
      if (c == 'dropped') {
        cDropped++;
      } else if (c == 'expelled') {
        cExpelled++;
      } else {
        cCompleted++;
      }
    }

    List<Map<String, dynamic>> filtered = all;
    if (_selectedCategory != 'all') {
      filtered = all.where((s) {
        return resolveCategory(s) == _selectedCategory;
      }).toList();
    }

    if (!mounted) return;
    setState(() {
      _alumni = filtered;
      _countAll = cAll;
      _countCompleted = cCompleted;
      _countDropped = cDropped;
      _countExpelled = cExpelled;
      _isLoading = false;
    });
  }

  String _admissionNo(Map<String, dynamic> student) {
    return (student['admission_number'] ??
            student['roll_number'] ??
            student['serial_no'] ??
            '')
        .toString();
  }

  Future<void> _syncData() async {
    final isUrdu = isUrduGlobal.value;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(isUrdu ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...'),
    ));
    try {
      await SyncService.runTwoWaySync();
      await _loadAlumni();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu ? 'سنک مکمل ہو گیا!' : 'Sync complete!'),
        backgroundColor: Colors.green,
      ));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu ? 'سنک میں خرابی آئی' : 'Sync failed'),
        backgroundColor: Colors.red,
      ));
    }
  }

  Future<void> _restoreStudent(Map<String, dynamic> student) async {
    final isUrdu = isUrduGlobal.value;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrdu ? 'بحالی کی تصدیق' : 'Confirm Restore'),
        content: Text(isUrdu
            ? 'کیا اس طالب علم کو دوبارہ فعال طلباء میں شامل کرنا ہے؟'
            : 'Restore this student to active students?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(isUrdu ? 'نہیں' : 'No'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
            onPressed: () => Navigator.pop(context, true),
            icon: const Icon(Icons.restore, color: Colors.white),
            label: Text(isUrdu ? 'بحال کریں' : 'Restore',
                style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final sId = int.tryParse(student['id']?.toString() ?? '') ?? 0;
    if (sId <= 0) return;

    await DBHelper().updateStudent(sId, {
      'status': 'Active',
      'graduation_date': '',
      'duration': '',
      'leaving_reason': '',
      'alumni_category': '',
      'wifaq_exam': 0,
      'wifaq_marks': '',
      'mehrab_recitation': '',
    });
    await _loadAlumni();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(isUrdu ? 'طالب علم بحال کر دیا گیا' : 'Student restored'),
      backgroundColor: Colors.green,
    ));
  }

  Future<void> _changeCategory(Map<String, dynamic> student) async {
    final isUrdu = isUrduGlobal.value;
    String currentCat = resolveCategory(student);
    final reasonController = TextEditingController(
        text: student['leaving_reason']?.toString() ?? '');

    final updated = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: Text(isUrdu
              ? 'طالب علم کی نوعیت / کیٹیگری تبدیل کریں'
              : 'Change Alumni Category'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  "${student['name']}",
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 16,
                      color: primaryGreen),
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  value: ['completed', 'dropped', 'expelled'].contains(currentCat)
                      ? currentCat
                      : 'completed',
                  decoration: InputDecoration(
                    labelText: isUrdu ? "نوعیت / کیٹیگری" : "Category",
                    border: const OutlineInputBorder(),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: "completed",
                      child: Text(isUrdu
                          ? "🎓 تعلیم مکمل کرنے والے (تکمیلِ تعلیم)"
                          : "Completed"),
                    ),
                    DropdownMenuItem(
                      value: "dropped",
                      child: Text(isUrdu
                          ? "⏸️ درمیان میں چھوڑنے والے (ترکِ تعلیم)"
                          : "Dropped Out"),
                    ),
                    DropdownMenuItem(
                      value: "expelled",
                      child: Text(isUrdu
                          ? "❌ مدرسہ سے خارج کردہ (اخراج)"
                          : "Expelled"),
                    ),
                  ],
                  onChanged: (val) {
                    if (val != null) setDlgState(() => currentCat = val);
                  },
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: reasonController,
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: isUrdu ? "وجہ / تفصیلات" : "Reason / Details",
                    border: const OutlineInputBorder(),
                    hintText: isUrdu
                        ? "مثلاً: شوقیہ چھوڑ گیا یا نافرمانی پر اخراج"
                        : "Reason...",
                  ),
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
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final sId = int.tryParse(student['id']?.toString() ?? '') ?? 0;
                if (sId > 0) {
                  await DBHelper().updateStudent(sId, {
                    'alumni_category': currentCat,
                    'leaving_reason': reasonController.text.trim(),
                    'is_synced': 0,
                  });
                }
                Navigator.pop(ctx, true);
              },
              child: Text(isUrdu ? 'محفوظ کریں' : 'Save'),
            ),
          ],
        ),
      ),
    );

    if (updated == true) {
      await _loadAlumni();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu
            ? 'نوعیت کامیابی سے تبدیل ہو گئی'
            : 'Category updated successfully'),
        backgroundColor: Colors.green,
      ));
    }
  }

  Future<void> _printRegister() async {
    if (_isPrinting) return;
    setState(() => _isPrinting = true);
    try {
      final isUrdu = isUrduGlobal.value;
      final doc = pw.Document();

      String catTitle = isUrdu ? 'تمام کیٹیگریز' : 'All Categories';
      if (_selectedCategory == 'completed') {
        catTitle = isUrdu ? 'تکمیلِ تعلیم' : 'Completed';
      } else if (_selectedCategory == 'dropped') {
        catTitle = isUrdu ? 'ترکِ تعلیم' : 'Dropped';
      } else if (_selectedCategory == 'expelled') {
        catTitle = isUrdu ? 'خارج کردہ' : 'Expelled';
      }

      doc.addPage(
        pw.MultiPage(
          pageFormat: PdfPageFormat.a4.landscape,
          margin: const pw.EdgeInsets.all(20),
          textDirection: isUrdu ? pw.TextDirection.rtl : pw.TextDirection.ltr,
          build: (_) => [
            pw.Center(
              child: pw.Column(children: [
                pw.Text(
                  '${isUrdu ? 'رجسٹر فارغ التحصیل طلباء' : 'Alumni Register'} ($catTitle)',
                  style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold),
                ),
                pw.SizedBox(height: 4),
                pw.Text('${isUrdu ? 'کل تعداد' : 'Total'}: ${_alumni.length}'),
              ]),
            ),
            pw.SizedBox(height: 12),
            pw.Table.fromTextArray(
              border: pw.TableBorder.all(color: PdfColors.grey400, width: 0.5),
              headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10),
              cellStyle: const pw.TextStyle(fontSize: 8.5),
              cellAlignment:
                  isUrdu ? pw.Alignment.centerRight : pw.Alignment.centerLeft,
              headers: [
                '#',
                isUrdu ? 'داخلہ نمبر' : 'Adm No',
                isUrdu ? 'نام طالب علم' : 'Student Name',
                isUrdu ? 'ولدیت' : 'Father Name',
                isUrdu ? 'کیٹیگری' : 'Category',
                isUrdu ? 'داخلہ' : 'Admission',
                isUrdu ? 'فراغت/اخراج' : 'Graduation',
                isUrdu ? 'دورانیہ' : 'Duration',
                isUrdu ? 'امتحان وفاق' : 'Wifaq',
                isUrdu ? 'محراب' : 'Mehrab',
                isUrdu ? 'وجہ' : 'Reason',
              ],
              data: List.generate(_alumni.length, (index) {
                final s = _alumni[index];
                final cat = resolveCategory(s);
                String catText = isUrdu ? 'تکمیلِ تعلیم' : 'Completed';
                if (cat == 'dropped') catText = isUrdu ? 'ترکِ تعلیم' : 'Dropped';
                if (cat == 'expelled') catText = isUrdu ? 'خارج کردہ' : 'Expelled';

                String wifaqText = '-';
                if (s['wifaq_exam'] == 1 || s['wifaq_exam'] == '1') {
                  wifaqText = isUrdu
                      ? "ہاں (${s['wifaq_marks'] ?? '-'})"
                      : "Yes (${s['wifaq_marks'] ?? '-'})";
                }

                return [
                  '${index + 1}',
                  _admissionNo(s),
                  s['name']?.toString() ?? '',
                  s['father_name']?.toString() ?? '',
                  catText,
                  s['admission_date']?.toString() ?? '',
                  s['graduation_date']?.toString() ?? '',
                  s['duration']?.toString() ?? '',
                  wifaqText,
                  s['mehrab_recitation']?.toString() ?? '-',
                  s['leaving_reason']?.toString() ?? '',
                ];
              }),
            ),
          ],
        ),
      );
      await Printing.layoutPdf(
        onLayout: (_) async => doc.save(),
        name: 'alumni_register.pdf',
      );
    } finally {
      if (mounted) setState(() => _isPrinting = false);
    }
  }

  Future<void> _issueSanad(Map<String, dynamic> student, bool isUrdu) async {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => SanadPreviewScreen(student: student),
      ),
    );
  }

  Widget _buildCategoryTabs(bool isUrdu) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      child: Row(
        children: [
          _categoryChip(
            id: 'all',
            label: isUrdu ? 'تمام فارغین' : 'All',
            count: _countAll,
            icon: Icons.people,
            color: Colors.blueGrey,
          ),
          const SizedBox(width: 8),
          _categoryChip(
            id: 'completed',
            label: isUrdu ? '🎓 تکمیلِ تعلیم' : '🎓 Completed',
            count: _countCompleted,
            icon: Icons.school,
            color: Colors.green,
          ),
          const SizedBox(width: 8),
          _categoryChip(
            id: 'dropped',
            label: isUrdu ? '⏸️ ترکِ تعلیم' : '⏸️ Dropped Out',
            count: _countDropped,
            icon: Icons.pause_circle_outline,
            color: Colors.orange,
          ),
          const SizedBox(width: 8),
          _categoryChip(
            id: 'expelled',
            label: isUrdu ? '❌ خارج کردہ' : '❌ Expelled',
            count: _countExpelled,
            icon: Icons.cancel_outlined,
            color: Colors.red,
          ),
        ],
      ),
    );
  }

  Widget _categoryChip({
    required String id,
    required String label,
    required int count,
    required IconData icon,
    required MaterialColor color,
  }) {
    final isSelected = _selectedCategory == id;
    return InkWell(
      onTap: () {
        setState(() {
          _selectedCategory = id;
          _loadAlumni();
        });
      },
      borderRadius: BorderRadius.circular(20),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: isSelected ? color.shade700 : Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: isSelected ? color.shade700 : color.shade200,
            width: 1.5,
          ),
          boxShadow: isSelected
              ? [
                  BoxShadow(
                    color: color.withValues(alpha: 0.3),
                    blurRadius: 4,
                    offset: const Offset(0, 2),
                  )
                ]
              : null,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: 16,
              color: isSelected ? Colors.white : color.shade700,
            ),
            const SizedBox(width: 6),
            Text(
              label,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                color: isSelected ? Colors.white : Colors.black87,
              ),
            ),
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
              decoration: BoxDecoration(
                color: isSelected
                    ? Colors.white.withValues(alpha: 0.25)
                    : color.shade50,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                '$count',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.bold,
                  color: isSelected ? Colors.white : color.shade800,
                ),
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
              backgroundColor: const Color(0xFFF1EFE7),
              appBar: AppBar(
                leading: Builder(
                  builder: (context) => IconButton(
                    icon: const Icon(Icons.menu, color: Colors.white),
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  ),
                ),
                title: Text(isUrdu ? 'فارغ التحصیل طلباء' : 'Alumni Register'),
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                actions: [
                  Center(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      child: Chip(
                        label: Text('${_alumni.length}'),
                        backgroundColor: Colors.white,
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: isUrdu ? 'پرنٹ' : 'Print',
                    onPressed:
                        _isPrinting || _alumni.isEmpty ? null : _printRegister,
                    icon: _isPrinting
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.print, color: Colors.white),
                  ),
                  IconButton(
                    tooltip: isUrdu ? 'سنک' : 'Sync',
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: _syncData,
                  ),
                ],
              ),
              drawer: const AppDrawer(),
              body: Column(
                children: [
                  _buildCategoryTabs(isUrdu),
                  Expanded(
                    child: _isLoading
                        ? const Center(child: CircularProgressIndicator())
                        : _alumni.isEmpty
                            ? Center(
                                child: Text(isUrdu
                                    ? 'اس کیٹیگری میں کوئی ریکارڈ موجود نہیں ہے'
                                    : 'No records found in this category'),
                              )
                            : ListView.builder(
                                padding: const EdgeInsets.all(10),
                                itemCount: _alumni.length,
                                itemBuilder: (context, index) {
                                  final student = _alumni[index];
                                  return _alumniCard(student, index, isUrdu);
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

  Widget _buildCategoryBadge(String cat, bool isUrdu) {
    Color bg;
    Color fg;
    String text;
    IconData icon;

    if (cat == 'dropped') {
      bg = Colors.orange.shade50;
      fg = Colors.orange.shade800;
      text = isUrdu ? 'ترکِ تعلیم' : 'Dropped Out';
      icon = Icons.pause_circle_outline;
    } else if (cat == 'expelled') {
      bg = Colors.red.shade50;
      fg = Colors.red.shade800;
      text = isUrdu ? 'خارج کردہ' : 'Expelled';
      icon = Icons.cancel_outlined;
    } else {
      bg = Colors.green.shade50;
      fg = Colors.green.shade800;
      text = isUrdu ? 'تکمیلِ تعلیم' : 'Completed';
      icon = Icons.school;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: fg.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: fg),
          const SizedBox(width: 4),
          Text(
            text,
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.bold,
              color: fg,
            ),
          ),
        ],
      ),
    );
  }

  Widget _alumniCard(Map<String, dynamic> student, int index, bool isUrdu) {
    final cat = resolveCategory(student);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: NeoBox(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: primaryGreen.withValues(alpha: 0.12),
                  child: Text('${index + 1}',
                      style: TextStyle(
                          color: primaryGreen, fontWeight: FontWeight.bold)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(student['name']?.toString() ?? '',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold, fontSize: 16)),
                          ),
                          _buildCategoryBadge(cat, isUrdu),
                        ],
                      ),
                      Text(
                          '${isUrdu ? 'ولدیت' : 'Father'}: ${student['father_name'] ?? ''}',
                          style: const TextStyle(
                              fontSize: 12, color: Colors.grey)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Chip(
                  label: Text(_admissionNo(student)),
                  backgroundColor: Colors.blue.withValues(alpha: 0.08),
                ),
              ],
            ),
            const Divider(height: 20),
            _detailRow(
                Icons.calendar_today,
                isUrdu ? 'تاریخ داخلہ' : 'Admission',
                student['admission_date'] ?? 'N/A'),
            _detailRow(
                Icons.event_available,
                isUrdu ? 'تاریخ فراغت/اخراج' : 'Graduation / Leaving',
                student['graduation_date'] ?? 'N/A'),
            _detailRow(Icons.timer, isUrdu ? 'تعلیمی دورانیہ' : 'Duration',
                student['duration'] ?? 'N/A'),
            if (student['wifaq_exam'] == 1 || student['wifaq_exam'] == '1')
              _detailRow(
                Icons.grade,
                isUrdu ? 'امتحان وفاق' : 'Wifaq Exam',
                isUrdu
                    ? "ہاں (نمبرات: ${student['wifaq_marks']?.toString().isNotEmpty == true ? student['wifaq_marks'] : 'درج نہیں'})"
                    : "Yes (Marks: ${student['wifaq_marks']?.toString().isNotEmpty == true ? student['wifaq_marks'] : 'N/A'})",
              ),
            if (student['mehrab_recitation']?.toString().isNotEmpty == true)
              _detailRow(
                Icons.mosque,
                isUrdu ? 'محراب (تراویح میں قرآن)' : 'Mehrab Recitation',
                student['mehrab_recitation'].toString(),
              ),
            _detailRow(
                Icons.info_outline,
                isUrdu ? 'وجہ فراغت / اخراج' : 'Reason',
                student['leaving_reason'] ??
                    (isUrdu ? 'درج نہیں' : 'Not entered')),
            _detailRow(Icons.location_on, isUrdu ? 'پتہ' : 'Address',
                student['address'] ?? 'N/A'),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _changeCategory(student),
                    icon: const Icon(Icons.edit_note, size: 18),
                    label: Text(isUrdu ? 'نوعیت تبدیل' : 'Category'),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.orange.shade800),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => const StudentsPage(),
                      ),
                    ),
                    icon: const Icon(Icons.visibility, size: 18),
                    label: Text(isUrdu ? 'طلباء ریکارڈ' : 'Students'),
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _restoreStudent(student),
                    icon: const Icon(Icons.restore, size: 18),
                    label: Text(isUrdu ? 'بحال کریں' : 'Restore'),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.green.shade700),
                  ),
                ),
              ],
            ),
            if (cat == 'completed') ...[
              const SizedBox(height: 8),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: () => _issueSanad(student, isUrdu),
                  icon: const Icon(Icons.workspace_premium, size: 18),
                  label: Text(isUrdu ? 'سند الفراغ جاری و پرنٹ کریں' : 'Issue / Print Sanad'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF284D3D),
                    foregroundColor: const Color(0xFFD4AF37),
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _detailRow(IconData icon, String label, dynamic value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 5),
      child: Row(
        children: [
          Icon(icon, size: 14, color: primaryGreen),
          const SizedBox(width: 10),
          Text('$label:',
              style:
                  const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(width: 10),
          Expanded(
              child: Text((value ?? '').toString(),
                  style: const TextStyle(fontSize: 13))),
        ],
      ),
    );
  }
}
