import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';

/// گلوبل اومنی سرچ ڈائیلاگ
/// تمام ماڈیولز (طلبہ، اساتذہ، فنانس، کتب خانہ، شاپ) کی 360 ڈگری فوری تلاش
class OmniSearchDialog extends StatefulWidget {
  const OmniSearchDialog({super.key});

  static void show(BuildContext context) {
    showDialog(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => const OmniSearchDialog(),
    );
  }

  @override
  State<OmniSearchDialog> createState() => _OmniSearchDialogState();
}

class _OmniSearchDialogState extends State<OmniSearchDialog> {
  final TextEditingController _searchController = TextEditingController();
  final DBHelper _db = DBHelper();

  bool _isLoading = false;
  List<Map<String, dynamic>> _studentResults = [];
  List<Map<String, dynamic>> _teacherResults = [];
  List<Map<String, dynamic>> _financeResults = [];
  List<Map<String, dynamic>> _bookResults = [];
  List<Map<String, dynamic>> _inventoryResults = [];

  String _currentFilter = 'all'; // 'all', 'students', 'finance', 'teachers', 'library'

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _performSearch(String query) async {
    final q = query.trim();
    if (q.isEmpty) {
      setState(() {
        _studentResults.clear();
        _teacherResults.clear();
        _financeResults.clear();
        _bookResults.clear();
        _inventoryResults.clear();
        _isLoading = false;
      });
      return;
    }

    setState(() => _isLoading = true);

    try {
      final sysType = SystemTypeGlobal.currentSystemType.value;

      // 1. Students Search
      final students = await _db.students.searchStudents(q, systemType: sysType, limit: 10);

      // 2. Teachers Search
      final allTeachers = await _db.admin.getTeachers(systemType: sysType);
      final qLower = q.toLowerCase();
      final teachers = allTeachers.where((t) {
        final name = (t['name'] ?? '').toString().toLowerCase();
        final phone = (t['phone'] ?? '').toString();
        return name.contains(qLower) || phone.contains(qLower);
      }).take(5).toList();

      // 3. Finance Search (Receipt, Donor, Head)
      final allFinance = await _db.financeRepo.getFinanceEntries(systemType: sysType, limit: 100);
      final finance = allFinance.where((f) {
        final donor = (f['donor_name'] ?? '').toString().toLowerCase();
        final desc = (f['description'] ?? '').toString().toLowerCase();
        final receipt = (f['manual_receipt_no'] ?? f['receipt_no'] ?? '').toString().toLowerCase();
        return donor.contains(qLower) || desc.contains(qLower) || receipt.contains(qLower);
      }).take(5).toList();

      // 4. Books Search
      final books = await _db.library.getBooks(systemType: sysType, search: q);

      // 5. Inventory Items Search
      final items = await _db.inventory.getInventoryItems(systemType: sysType, search: q);

      if (mounted) {
        setState(() {
          _studentResults = students;
          _teacherResults = teachers;
          _financeResults = finance;
          _bookResults = books.take(5).toList();
          _inventoryResults = items.take(5).toList();
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
      child: Container(
        constraints: const BoxConstraints(maxWidth: 600, maxHeight: 750),
        child: Column(
          children: [
            // Search Input Header
            Container(
              padding: const EdgeInsets.all(16),
              decoration: const BoxDecoration(
                color: Color(0xFF1B5E20),
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
              ),
              child: Column(
                children: [
                  Row(
                    children: [
                      const Icon(Icons.manage_search_rounded, color: Colors.white, size: 28),
                      const SizedBox(width: 8),
                      const Expanded(
                        child: Text(
                          'جامع اومنی سرچ (360° تلاش)',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            fontFamily: 'JameelNooriNastaleeq',
                          ),
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close, color: Colors.white70),
                        onPressed: () => Navigator.pop(context),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _searchController,
                    autofocus: true,
                    style: const TextStyle(color: Colors.black87),
                    decoration: InputDecoration(
                      filled: true,
                      fillColor: Colors.white,
                      hintText: 'طالب علم کا نام، رول نمبر، رسید نمبر، کتاب، استاد تلاش کریں...',
                      hintStyle: const TextStyle(fontSize: 13, color: Colors.grey),
                      prefixIcon: const Icon(Icons.search, color: Color(0xFF1B5E20)),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, color: Colors.grey),
                              onPressed: () {
                                _searchController.clear();
                                _performSearch('');
                              },
                            )
                          : null,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                        borderSide: BorderSide.none,
                      ),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    ),
                    onChanged: _performSearch,
                  ),
                ],
              ),
            ),

            // Filter Chips
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(
                children: [
                  _filterChip('all', 'تمام نتائج', Icons.all_inclusive),
                  _filterChip('students', 'طلبہ (${_studentResults.length})', Icons.school),
                  _filterChip('teachers', 'اساتذہ (${_teacherResults.length})', Icons.person),
                  _filterChip('finance', 'مالیات (${_financeResults.length})', Icons.attach_money),
                  _filterChip('library', 'کتب خانہ (${_bookResults.length})', Icons.menu_book),
                ],
              ),
            ),

            const Divider(height: 1),

            // Search Results Body
            Expanded(
              child: _isLoading
                  ? const Center(
                      child: CircularProgressIndicator(color: Color(0xFF1B5E20)),
                    )
                  : _searchController.text.trim().isEmpty
                      ? _buildEmptyPrompt()
                      : _buildResultsList(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _filterChip(String filterKey, String label, IconData icon) {
    final isSelected = _currentFilter == filterKey;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: FilterChip(
        avatar: Icon(icon, size: 16, color: isSelected ? Colors.white : const Color(0xFF1B5E20)),
        label: Text(label),
        selected: isSelected,
        selectedColor: const Color(0xFF1B5E20),
        labelStyle: TextStyle(
          color: isSelected ? Colors.white : Colors.black87,
          fontSize: 12,
          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
        ),
        onSelected: (_) => setState(() => _currentFilter = filterKey),
      ),
    );
  }

  Widget _buildEmptyPrompt() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.search_rounded, size: 64, color: Colors.grey.withOpacity(0.4)),
          const SizedBox(height: 12),
          const Text(
            'سرچ کرنے کے لیے کچھ ٹائپ کریں',
            style: TextStyle(
              fontSize: 16,
              color: Colors.grey,
              fontFamily: 'JameelNooriNastaleeq',
            ),
          ),
          const SizedBox(height: 4),
          const Text(
            'مثال: طالب علم کا نام، رول نمبر، داخلہ نمبر یا فون نمبر',
            style: TextStyle(fontSize: 12, color: Colors.grey),
          ),
        ],
      ),
    );
  }

  Widget _buildResultsList() {
    final hasAny = _studentResults.isNotEmpty ||
        _teacherResults.isNotEmpty ||
        _financeResults.isNotEmpty ||
        _bookResults.isNotEmpty ||
        _inventoryResults.isNotEmpty;

    if (!hasAny) {
      return const Center(
        child: Text(
          'کوئی نتیجہ نہیں ملا!',
          style: TextStyle(color: Colors.grey, fontSize: 16, fontFamily: 'JameelNooriNastaleeq'),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        if ((_currentFilter == 'all' || _currentFilter == 'students') &&
            _studentResults.isNotEmpty) ...[
          _sectionHeader('طلبہ کے نتائج', Icons.school, Colors.blue),
          ..._studentResults.map(_buildStudentTile),
        ],
        if ((_currentFilter == 'all' || _currentFilter == 'teachers') &&
            _teacherResults.isNotEmpty) ...[
          _sectionHeader('اساتذہ و عملہ', Icons.person, Colors.teal),
          ..._teacherResults.map(_buildTeacherTile),
        ],
        if ((_currentFilter == 'all' || _currentFilter == 'finance') &&
            _financeResults.isNotEmpty) ...[
          _sectionHeader('مالیات و رسیدات', Icons.receipt_long, Colors.green),
          ..._financeResults.map(_buildFinanceTile),
        ],
        if ((_currentFilter == 'all' || _currentFilter == 'library') &&
            _bookResults.isNotEmpty) ...[
          _sectionHeader('کتب خانہ', Icons.menu_book, Colors.deepPurple),
          ..._bookResults.map(_buildBookTile),
        ],
      ],
    );
  }

  Widget _sectionHeader(String title, IconData icon, Color color) {
    return Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 6),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 6),
          Text(
            title,
            style: TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 14,
              color: color,
              fontFamily: 'JameelNooriNastaleeq',
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStudentTile(Map<String, dynamic> s) {
    final sId = s['id'] as int;
    final name = s['name'] ?? '';
    final father = s['father_name'] ?? '';
    final roll = s['roll_number'] ?? s['admission_number'] ?? '';
    final cName = s['class_name'] ?? s['class'] ?? '';

    return Card(
      elevation: 0.5,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: Colors.blue.withOpacity(0.15),
          child: const Icon(Icons.school, color: Colors.blue, size: 20),
        ),
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text(
          'والد: $father | کلاس: $cName | رول: $roll',
          style: const TextStyle(fontSize: 12, color: Colors.black54),
        ),
        trailing: const Icon(Icons.chevron_left, color: Colors.grey),
        onTap: () => _openStudent360(sId),
      ),
    );
  }

  Widget _buildTeacherTile(Map<String, dynamic> t) {
    final name = t['name'] ?? '';
    final phone = t['phone'] ?? '';
    final designation = t['designation'] ?? t['role'] ?? 'استاد';

    return Card(
      elevation: 0.5,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: Colors.teal.withOpacity(0.15),
          child: const Icon(Icons.person, color: Colors.teal, size: 20),
        ),
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text('$designation | فون: $phone', style: const TextStyle(fontSize: 12, color: Colors.black54)),
      ),
    );
  }

  Widget _buildFinanceTile(Map<String, dynamic> f) {
    final type = f['type'] ?? '';
    final amount = f['amount'] ?? 0;
    final head = f['head_name'] ?? '';
    final donor = f['donor_name'] ?? f['description'] ?? '';
    final date = f['date'] ?? '';
    final isIncome = type.toString().toLowerCase() == 'income';

    return Card(
      elevation: 0.5,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: (isIncome ? Colors.green : Colors.red).withOpacity(0.15),
          child: Icon(
            isIncome ? Icons.arrow_downward : Icons.arrow_upward,
            color: isIncome ? Colors.green : Colors.red,
            size: 20,
          ),
        ),
        title: Text(
          'Rs. $amount ($head)',
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 14,
            color: isIncome ? Colors.green.shade800 : Colors.red.shade800,
          ),
        ),
        subtitle: Text('$donor | تاریخ: $date', style: const TextStyle(fontSize: 12, color: Colors.black54)),
      ),
    );
  }

  Widget _buildBookTile(Map<String, dynamic> b) {
    final title = b['title'] ?? '';
    final author = b['author'] ?? '';
    final code = b['book_code'] ?? '';

    return Card(
      elevation: 0.5,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: Colors.deepPurple.withOpacity(0.15),
          child: const Icon(Icons.menu_book, color: Colors.deepPurple, size: 20),
        ),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text('مصنف: $author | کوڈ: $code', style: const TextStyle(fontSize: 12, color: Colors.black54)),
      ),
    );
  }

  /// 360 ڈگری اسٹوڈنٹ ہسٹری اور پروفائل کارڈ ڈسپلے کریں
  void _openStudent360(int studentId) async {
    final profile = await _db.students.getStudent360Profile(studentId);
    if (!mounted || profile.isEmpty) return;

    final student = profile['student'] as Map<String, dynamic>;
    final att = profile['attendance_summary'] as Map<String, dynamic>;
    final hostel = profile['hostel'] as Map<String, dynamic>?;
    final books = profile['issued_books'] as List<dynamic>? ?? [];

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return DraggableScrollableSheet(
          expand: false,
          initialChildSize: 0.8,
          maxChildSize: 0.95,
          minChildSize: 0.5,
          builder: (_, controller) {
            return ListView(
              controller: controller,
              padding: const EdgeInsets.all(20),
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: Colors.grey.shade300,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                // Header
                Row(
                  children: [
                    CircleAvatar(
                      radius: 30,
                      backgroundColor: const Color(0xFF1B5E20).withOpacity(0.15),
                      child: const Icon(Icons.person, size: 36, color: Color(0xFF1B5E20)),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            student['name'] ?? '',
                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                          ),
                          Text(
                            'والد: ${student['father_name'] ?? ''} | کلاس: ${student['class_name'] ?? student['class'] ?? ''}',
                            style: const TextStyle(color: Colors.black54, fontSize: 13),
                          ),
                          Text(
                            'رول نمبر: ${student['roll_number'] ?? ''} | داخلہ: ${student['admission_number'] ?? ''}',
                            style: const TextStyle(color: Color(0xFF1B5E20), fontWeight: FontWeight.bold, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),

                // Attendance Snapshot Card
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.blue.withOpacity(0.08),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.blue.withOpacity(0.2)),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _statItem('حاضر', '${att['present'] ?? 0}', Colors.green),
                      _statItem('غیر حاضر', '${att['absent'] ?? 0}', Colors.red),
                      _statItem('رخصت', '${att['leave'] ?? 0}', Colors.orange),
                      _statItem('کل سیشنز', '${att['total_sessions'] ?? 0}', Colors.blue),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // Hostel & Library Info
                if (hostel != null) ...[
                  ListTile(
                    leading: const Icon(Icons.hotel, color: Colors.purple),
                    title: Text('دارالاقامہ: ${hostel['room_name'] ?? ''}'),
                    subtitle: Text('بیڈ نمبر: ${hostel['bed_number'] ?? 'متعین نہیں'}'),
                  ),
                ],
                if (books.isNotEmpty) ...[
                  ListTile(
                    leading: const Icon(Icons.menu_book, color: Colors.deepPurple),
                    title: Text('جاری شدہ کتب: ${books.length}'),
                    subtitle: Text(books.map((b) => b['book_title']).join(', ')),
                  ),
                ],

                const SizedBox(height: 16),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF1B5E20),
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  onPressed: () => Navigator.pop(ctx),
                  icon: const Icon(Icons.check),
                  label: const Text('مکمل'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Widget _statItem(String label, String value, Color color) {
    return Column(
      children: [
        Text(value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: color)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(fontSize: 11, color: Colors.black54)),
      ],
    );
  }
}
