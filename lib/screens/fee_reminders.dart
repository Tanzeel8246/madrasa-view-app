// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' as intl;
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class FeeRemindersPage extends StatefulWidget {
  const FeeRemindersPage({super.key});

  @override
  State<FeeRemindersPage> createState() => _FeeRemindersPageState();
}

class _FeeRemindersPageState extends State<FeeRemindersPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _defaulters = [];
  List<Map<String, dynamic>> _filteredDefaulters = [];
  bool _isLoading = true;
  String _searchQuery = '';
  String _selectedClass = 'All';
  List<String> _classes = ['All'];

  @override
  void initState() {
    super.initState();
    _loadFeeDefaulters();
  }

  Future<void> _loadFeeDefaulters() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final database = await db.database;
      final currentMonthStr = intl.DateFormat('yyyy-MM').format(DateTime.now());

      final students = await db.getStudents();
      final activeStudents = students.where((s) => (s['is_deleted'] ?? 0) == 0).toList();

      final classSet = <String>{'All'};
      for (var s in activeStudents) {
        final c = (s['class'] ?? '').toString().trim();
        if (c.isNotEmpty) classSet.add(c);
      }
      _classes = classSet.toList();

      List<Map<String, dynamic>> list = [];

      for (var s in activeStudents) {
        final sId = s['id'];
        final monthlyFee = double.tryParse(s['monthly_fee']?.toString() ?? '1000') ?? 1000.0;

        // Check if student has paid fee this month
        final feePaid = await database.rawQuery(
          "SELECT amount, status FROM fee_submissions WHERE student_id = ? AND date LIKE ? AND (LOWER(status) = 'approved' OR LOWER(status) = 'paid')",
          [sId, '$currentMonthStr%'],
        );

        if (feePaid.isEmpty) {
          list.add({
            'id': sId,
            'name': s['name'] ?? 'طالب علم',
            'father_name': s['father_name'] ?? '',
            'class': s['class'] ?? '',
            'phone': s['contact_number'] ?? s['phone'] ?? '',
            'due_amount': monthlyFee,
            'month': intl.DateFormat('MMMM yyyy').format(DateTime.now()),
          });
        }
      }

      _defaulters = list;
      _applyFilter();
    } catch (e) {
      debugPrint("Load Defaulters Error: $e");
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _applyFilter() {
    setState(() {
      _filteredDefaulters = _defaulters.where((item) {
        final matchClass = _selectedClass == 'All' || item['class'] == _selectedClass;
        final name = (item['name'] ?? '').toString().toLowerCase();
        final father = (item['father_name'] ?? '').toString().toLowerCase();
        final matchQuery = _searchQuery.isEmpty ||
            name.contains(_searchQuery.toLowerCase()) ||
            father.contains(_searchQuery.toLowerCase());
        return matchClass && matchQuery;
      }).toList();
    });
  }

  void _sendIndividualReminder(Map<String, dynamic> item) async {
    final name = item['name'];
    final father = item['father_name'];
    final month = item['month'];
    final amount = (item['due_amount'] as double).toStringAsFixed(0);
    String rawPhone = (item['phone'] ?? '').toString().replaceAll(RegExp(r'[^0-9]'), '');

    if (rawPhone.startsWith('0')) {
      rawPhone = '92${rawPhone.substring(1)}';
    }

    final message = '''
محترم والدین: جناب $father!
السلام علیکم ورحمۃ اللہ وبرکاتہ!
اطلاع دی جاتی ہے کہ طالب علم *$name* کی بابت ماہ *$month* کے تعلیمی اخراجات / ماہانہ فیس مبلغ *$amount روپے* واجب الادا ہیں۔
براہ کرم بر وقت جمع کروا کر رسید حاصل فرمائیں۔
شکریہ!
_ادارہ اشرف المدارس فروکہ_
'''.trim();

    if (rawPhone.length >= 10) {
      final uri = Uri.parse('https://wa.me/$rawPhone?text=${Uri.encodeComponent(message)}');
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
        return;
      }
    }

    // Fallback to general share
    Share.share(message, subject: 'فیس یاددہانی');
  }

  void _sendBulkRemindersWhatsApp() {
    if (_filteredDefaulters.isEmpty) return;

    final buffer = StringBuffer();
    buffer.writeln('📋 *فہرست واجب الادا فیس جات - مدرسہ اشرف المدارس فروکہ*');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('📅 *ماہ:* ${intl.DateFormat('MMMM yyyy').format(DateTime.now())}');
    buffer.writeln('👥 *کل طلبہ:* ${_filteredDefaulters.length}');
    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');

    for (int i = 0; i < _filteredDefaulters.length; i++) {
      final s = _filteredDefaulters[i];
      final amt = (s['due_amount'] as double).toStringAsFixed(0);
      buffer.writeln('${i + 1}. ${s['name']} ولد ${s['father_name']} (${s['class']}) - $amt روپے');
    }

    buffer.writeln('━━━━━━━━━━━━━━━━━━━━');
    buffer.writeln('_اشرف المدارس مینجمنٹ سسٹم_');

    Share.share(buffer.toString(), subject: 'بقایاجات فیس لسٹ');
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Scaffold(
      backgroundColor: bgColor,
      drawer: const AppDrawer(),
      appBar: AppBar(
        title: Text(
          isUrdu ? 'فیس یاددہانی و بقایاجات' : 'Fee Reminders & Dues',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.share),
            tooltip: isUrdu ? 'کل لسٹ WhatsApp کریں' : 'Share List',
            onPressed: _sendBulkRemindersWhatsApp,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: isUrdu ? 'تازہ کریں' : 'Refresh',
            onPressed: _loadFeeDefaulters,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Filter and Search Header
                Container(
                  color: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: TextField(
                              decoration: InputDecoration(
                                hintText: isUrdu ? 'نام یا ولدیت سے تلاش کریں...' : 'Search by name/father...',
                                prefixIcon: const Icon(Icons.search, size: 20),
                                isDense: true,
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                              ),
                              onChanged: (val) {
                                _searchQuery = val;
                                _applyFilter();
                              },
                            ),
                          ),
                          const SizedBox(width: 8),
                          DropdownButton<String>(
                            value: _selectedClass,
                            items: _classes.map((c) => DropdownMenuItem(value: c, child: Text(c))).toList(),
                            onChanged: (val) {
                              if (val != null) {
                                _selectedClass = val;
                                _applyFilter();
                              }
                            },
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            isUrdu
                                ? 'واجب الادا طلبہ: ${_filteredDefaulters.length}'
                                : 'Pending Students: ${_filteredDefaulters.length}',
                            style: TextStyle(
                              color: primaryGreen,
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                            ),
                          ),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: Colors.green.shade700,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                            ),
                            icon: const Icon(Icons.message, size: 14),
                            label: Text(
                              isUrdu ? 'تمام لسٹ WhatsApp' : 'Bulk WhatsApp',
                              style: const TextStyle(fontSize: 12),
                            ),
                            onPressed: _sendBulkRemindersWhatsApp,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                // Defaulters List
                Expanded(
                  child: _filteredDefaulters.isEmpty
                      ? Center(
                          child: Text(
                            isUrdu ? 'کوئی واجب الادا فیس باقی نہیں ہے! ماشاء اللہ' : 'No pending fee dues!',
                            style: const TextStyle(color: Colors.grey, fontSize: 16),
                          ),
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.all(12),
                          itemCount: _filteredDefaulters.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 8),
                          itemBuilder: (context, index) {
                            final item = _filteredDefaulters[index];
                            final amount = (item['due_amount'] as double).toStringAsFixed(0);

                            return Card(
                              elevation: 2,
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              child: ListTile(
                                leading: CircleAvatar(
                                  backgroundColor: Colors.red.shade50,
                                  child: const Icon(Icons.person, color: Colors.red),
                                ),
                                title: Text(
                                  '${item['name']} ولد ${item['father_name']}',
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                ),
                                subtitle: Text(
                                  'درجہ: ${item['class']} | فون: ${item['phone'].isEmpty ? 'دستیاب نہیں' : item['phone']}\nواجب الادا: $amount روپے (${item['month']})',
                                  style: const TextStyle(fontSize: 12),
                                ),
                                isThreeLine: true,
                                trailing: ElevatedButton.icon(
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xFF25D366),
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                  ),
                                  icon: const Icon(Icons.send, size: 14),
                                  label: Text(
                                    isUrdu ? 'یاددہانی' : 'Remind',
                                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                  ),
                                  onPressed: () => _sendIndividualReminder(item),
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
}
