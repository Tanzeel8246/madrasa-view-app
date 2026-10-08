// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class LibraryScreen extends StatefulWidget {
  const LibraryScreen({super.key});

  @override
  State<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends State<LibraryScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _books = [];
  List<Map<String, dynamic>> _issues = [];
  List<Map<String, dynamic>> _students = [];
  List<Map<String, dynamic>> _teachers = [];

  String _selectedCategory = 'All';
  String _selectedScope = 'Default'; // 'Default', 'Shared', 'Madrasa', 'Masjid', 'All'
  String _searchQuery = '';

  final List<String> _categories = [
    'All',
    'قرآن و تفاسیر',
    'کتبِ احادیث',
    'فقہ و اصول فقہ',
    'عربی زبان و ادب',
    'سیرت و تاریخ',
    'تجوید و قراءت',
    'نصابی و درسی کتب',
    'عام',
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    String? querySys = sys;
    if (_selectedScope == 'Shared') {
      querySys = 'Shared';
    } else if (_selectedScope == 'All') {
      querySys = 'All';
    } else if (_selectedScope == 'Madrasa') {
      querySys = 'Madrasa';
    } else if (_selectedScope == 'Masjid') {
      querySys = 'Masjid';
    } else {
      querySys = sys;
    }

    final books = await _dbHelper.getLibraryBooks(
      category: _selectedCategory != 'All' ? _selectedCategory : null,
      systemType: querySys,
      search: _searchQuery.isNotEmpty ? _searchQuery : null,
    );

    final issues = await _dbHelper.getLibraryIssues(systemType: sys);

    final db = await _dbHelper.database;
    final stdTable = isMasjid ? 'maktab_students' : 'students';
    final tchTable = isMasjid ? 'maktab_teachers' : 'teachers';

    List<Map<String, dynamic>> stds = [];
    List<Map<String, dynamic>> tchs = [];
    try {
      stds = await db.query(stdTable,
          columns: ['id', 'name', 'class', 'contact_number'],
          where: "status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: 'name ASC');
    } catch (_) {}
    try {
      tchs = await db.query(tchTable,
          columns: ['id', 'name', 'phone'],
          where: "(status = 'Active' OR status = '' OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: 'name ASC');
    } catch (_) {}

    setState(() {
      _books = books;
      _issues = issues;
      _students = stds;
      _teachers = tchs;
      _isLoading = false;
    });
  }

  void _showAddBookDialog() {
    final titleCtrl = TextEditingController();
    final authorCtrl = TextEditingController();
    final rackCtrl = TextEditingController();
    final copiesCtrl = TextEditingController(text: '1');
    final isbnCtrl = TextEditingController();
    String cat = 'عام';
    String bookSys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Icon(Icons.library_books, color: primaryGreen),
              const SizedBox(width: 8),
              const Text('نئی کتاب کا اندراج',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: titleCtrl,
                  decoration: const InputDecoration(
                    labelText: 'کتاب کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: authorCtrl,
                  decoration: const InputDecoration(
                    labelText: 'مصنف / مؤلف',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: cat,
                  decoration: const InputDecoration(
                    labelText: 'کیٹیگری',
                    border: OutlineInputBorder(),
                  ),
                  items: _categories
                      .where((c) => c != 'All')
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => cat = v);
                  },
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: rackCtrl,
                        decoration: const InputDecoration(
                          labelText: 'الماری / ریک نمبر',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: copiesCtrl,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                          labelText: 'کل کاپیاں',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: isbnCtrl,
                  decoration: const InputDecoration(
                    labelText: 'ISBN / بارکوڈ (اختیاری)',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: bookSys,
                  decoration: const InputDecoration(
                    labelText: 'کتاب کا دائرہ کار (Scope)',
                    border: OutlineInputBorder(),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: isMasjid ? 'Masjid' : 'Madrasa',
                      child: Text(isMasjid ? 'صرف مسجد / مکتب' : 'صرف مدرسہ'),
                    ),
                    const DropdownMenuItem(
                      value: 'Shared',
                      child: Text('مشترکہ (دونوں سسٹمز کے لیے میسر)'),
                    ),
                    DropdownMenuItem(
                      value: isMasjid ? 'Madrasa' : 'Masjid',
                      child: Text(isMasjid ? 'صرف مدرسہ' : 'صرف مسجد / مکتب'),
                    ),
                  ],
                  onChanged: (v) {
                    if (v != null) setDlgState(() => bookSys = v);
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final title = titleCtrl.text.trim();
                if (title.isEmpty) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('کتاب کا نام لازمی ہے!')),
                  );
                  return;
                }
                final copies = int.tryParse(copiesCtrl.text.trim()) ?? 1;
                Navigator.pop(ctx);
                await _dbHelper.insertLibraryBook({
                  'book_title': title,
                  'author': authorCtrl.text.trim(),
                  'category': cat,
                  'rack_number': rackCtrl.text.trim(),
                  'isbn': isbnCtrl.text.trim(),
                  'total_copies': copies,
                  'available_copies': copies,
                  'system_type': bookSys,
                });
                _loadData();
              },
              child: const Text('محفوظ کریں'),
            ),
          ],
        ),
      ),
    );
  }

  void _showEditBookDialog(Map<String, dynamic> book) {
    final int id = book['id'];
    final titleCtrl = TextEditingController(text: book['book_title']?.toString() ?? '');
    final authorCtrl = TextEditingController(text: book['author']?.toString() ?? '');
    final rackCtrl = TextEditingController(text: book['rack_number']?.toString() ?? '');
    final total = (book['total_copies'] as int?) ?? 1;
    final avail = (book['available_copies'] as int?) ?? 1;
    final copiesCtrl = TextEditingController(text: '$total');
    final isbnCtrl = TextEditingController(text: book['isbn']?.toString() ?? '');
    String cat = book['category']?.toString() ?? 'عام';
    if (!_categories.contains(cat)) cat = 'عام';
    String bookSys = book['system_type']?.toString() ?? 'Madrasa';
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Icon(Icons.edit, color: primaryGreen),
              const SizedBox(width: 8),
              const Text('کتاب میں ترمیم',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: titleCtrl,
                  decoration: const InputDecoration(
                    labelText: 'کتاب کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: authorCtrl,
                  decoration: const InputDecoration(
                    labelText: 'مصنف / مؤلف',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: cat,
                  decoration: const InputDecoration(
                    labelText: 'کیٹیگری',
                    border: OutlineInputBorder(),
                  ),
                  items: _categories
                      .where((c) => c != 'All')
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => cat = v);
                  },
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: rackCtrl,
                        decoration: const InputDecoration(
                          labelText: 'الماری / ریک نمبر',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: copiesCtrl,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                          labelText: 'کل کاپیاں',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: isbnCtrl,
                  decoration: const InputDecoration(
                    labelText: 'ISBN / بارکوڈ (اختیاری)',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: bookSys,
                  decoration: const InputDecoration(
                    labelText: 'کتاب کا دائرہ کار (Scope)',
                    border: OutlineInputBorder(),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: isMasjid ? 'Masjid' : 'Madrasa',
                      child: Text(isMasjid ? 'صرف مسجد / مکتب' : 'صرف مدرسہ'),
                    ),
                    const DropdownMenuItem(
                      value: 'Shared',
                      child: Text('مشترکہ (دونوں سسٹمز کے لیے میسر)'),
                    ),
                    DropdownMenuItem(
                      value: isMasjid ? 'Madrasa' : 'Masjid',
                      child: Text(isMasjid ? 'صرف مدرسہ' : 'صرف مسجد / مکتب'),
                    ),
                  ],
                  onChanged: (v) {
                    if (v != null) setDlgState(() => bookSys = v);
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final title = titleCtrl.text.trim();
                if (title.isEmpty) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('کتاب کا نام لازمی ہے!')),
                  );
                  return;
                }
                final newTotal = int.tryParse(copiesCtrl.text.trim()) ?? total;
                final diff = newTotal - total;
                final newAvail = (avail + diff).clamp(0, newTotal);

                Navigator.pop(ctx);
                await _dbHelper.updateLibraryBook(id, {
                  'book_title': title,
                  'author': authorCtrl.text.trim(),
                  'category': cat,
                  'rack_number': rackCtrl.text.trim(),
                  'isbn': isbnCtrl.text.trim(),
                  'total_copies': newTotal,
                  'available_copies': newAvail,
                  'system_type': bookSys,
                });
                _loadData();
              },
              child: const Text('تبدیلیاں محفوظ کریں'),
            ),
          ],
        ),
      ),
    );
  }

  void _confirmDeleteBook(int id) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('کتاب حذف کریں'),
        content: const Text('کیا آپ واقعی یہ کتاب لائبریری سے حذف کرنا چاہتے ہیں؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('منسوخ'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () async {
              Navigator.pop(ctx);
              await _dbHelper.deleteLibraryBook(id);
              _loadData();
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('کتاب حذف کر دی گئی!')),
              );
            },
            child: const Text('حذف کریں'),
          ),
        ],
      ),
    );
  }

  void _showIssueBookDialog(Map<String, dynamic> book) {
    final int bookId = book['id'];
    final bookTitle = book['book_title']?.toString() ?? '';
    final avail = (book['available_copies'] as int?) ?? 0;

    if (avail <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('اس کتاب کی کوئی کاپی دستیاب نہیں ہے!')),
      );
      return;
    }

    String borrowerType = 'student';
    int? selectedBorrowerId =
        _students.isNotEmpty ? (_students.first['id'] as int) : null;
    final remarksCtrl = TextEditingController();
    String dueDateStr = DateTime.now()
        .add(const Duration(days: 14))
        .toIso8601String()
        .substring(0, 10);

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              const Icon(Icons.assignment_turned_in, color: Colors.green),
              const SizedBox(width: 8),
              const Text('کتاب جاری کریں (Issue)',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 18)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.book, size: 20, color: Colors.teal),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          bookTitle,
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                      Text('دستیاب: $avail',
                          style: const TextStyle(
                              color: Colors.green, fontWeight: FontWeight.bold)),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    ChoiceChip(
                      label: const Text('طالب علم'),
                      selected: borrowerType == 'student',
                      onSelected: (v) {
                        setDlgState(() {
                          borrowerType = 'student';
                          selectedBorrowerId = _students.isNotEmpty
                              ? (_students.first['id'] as int)
                              : null;
                        });
                      },
                    ),
                    const SizedBox(width: 8),
                    ChoiceChip(
                      label: const Text('استاذ محترم'),
                      selected: borrowerType == 'teacher',
                      onSelected: (v) {
                        setDlgState(() {
                          borrowerType = 'teacher';
                          selectedBorrowerId = _teachers.isNotEmpty
                              ? (_teachers.first['id'] as int)
                              : null;
                        });
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (borrowerType == 'student')
                  DropdownButtonFormField<int>(
                    value: selectedBorrowerId,
                    decoration: const InputDecoration(
                      labelText: 'طالب علم منتخب کریں',
                      border: OutlineInputBorder(),
                    ),
                    items: _students
                        .map((s) => DropdownMenuItem<int>(
                              value: s['id'] as int,
                              child: Text(
                                  "${s['name']} (${s['class'] ?? 'کلاس'})"),
                            ))
                        .toList(),
                    onChanged: (v) =>
                        setDlgState(() => selectedBorrowerId = v),
                  )
                else
                  DropdownButtonFormField<int>(
                    value: selectedBorrowerId,
                    decoration: const InputDecoration(
                      labelText: 'استاذ محترم منتخب کریں',
                      border: OutlineInputBorder(),
                    ),
                    items: _teachers
                        .map((t) => DropdownMenuItem<int>(
                              value: t['id'] as int,
                              child: Text("${t['name']}"),
                            ))
                        .toList(),
                    onChanged: (v) =>
                        setDlgState(() => selectedBorrowerId = v),
                  ),
                const SizedBox(height: 12),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.event, color: Colors.blue),
                  title: const Text('مقررہ واپسی کی تاریخ'),
                  subtitle: Text(dueDateStr),
                  trailing: TextButton(
                    child: const Text('تبدیل کریں'),
                    onPressed: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now().add(const Duration(days: 14)),
                        firstDate: DateTime.now(),
                        lastDate: DateTime.now().add(const Duration(days: 90)),
                      );
                      if (picked != null) {
                        setDlgState(() => dueDateStr =
                            picked.toIso8601String().substring(0, 10));
                      }
                    },
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: remarksCtrl,
                  decoration: const InputDecoration(
                    labelText: 'اضافی ریمارکس',
                    border: OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.green,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                if (selectedBorrowerId == null) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('برائے مہربانی نام منتخب کریں!')),
                  );
                  return;
                }
                String bName = '';
                String bContact = '';
                if (borrowerType == 'student') {
                  final std = _students.firstWhere(
                      (s) => s['id'] == selectedBorrowerId,
                      orElse: () => {});
                  bName = std['name']?.toString() ?? '';
                  bContact = std['contact_number']?.toString() ?? '';
                } else {
                  final tch = _teachers.firstWhere(
                      (t) => t['id'] == selectedBorrowerId,
                      orElse: () => {});
                  bName = tch['name']?.toString() ?? '';
                  bContact = tch['phone']?.toString() ?? '';
                }

                Navigator.pop(ctx);
                final ok = await _dbHelper.issueLibraryBook({
                  'book_id': bookId,
                  'borrower_type': borrowerType,
                  'borrower_id': selectedBorrowerId,
                  'borrower_name': bName,
                  'borrower_contact': bContact,
                  'issue_date':
                      DateTime.now().toIso8601String().substring(0, 10),
                  'due_date': dueDateStr,
                  'remarks': remarksCtrl.text.trim(),
                  'system_type': SystemTypeGlobal.currentSystemType.value,
                });

                if (ok) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('کتاب کامیابی سے جاری ہو گئی!')),
                  );
                  _loadData();
                }
              },
              child: const Text('کتاب جاری کریں'),
            ),
          ],
        ),
      ),
    );
  }

  void _showReturnDialog(Map<String, dynamic> issue) {
    final int issueId = issue['id'];
    final bTitle = issue['book_title']?.toString() ?? 'کتاب';
    final bName = issue['borrower_name']?.toString() ?? '';
    final remarksCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('کتاب کی واپسی (Return)',
            style: TextStyle(fontFamily: 'JameelNoori', fontSize: 18)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('کتاب: $bTitle',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            Text('قرض دار: $bName', style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 12),
            TextField(
              controller: remarksCtrl,
              decoration: const InputDecoration(
                labelText: 'واپسی ریمارکس / کیفیت',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('منسوخ'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.green,
              foregroundColor: Colors.white,
            ),
            onPressed: () async {
              Navigator.pop(ctx);
              await _dbHelper.returnLibraryBook(
                issueId,
                remarks: remarksCtrl.text.trim(),
              );
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('کتاب واپس جمع کر لی گئی!')),
              );
              _loadData();
            },
            child: const Text('واپسی کنفرم کریں'),
          ),
        ],
      ),
    );
  }

  Widget _buildBooksList() {
    if (_books.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.menu_book, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text('کوئی کتاب موجود نہیں ہے',
                style: TextStyle(color: Colors.grey.shade600)),
          ],
        ),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.only(top: 8, bottom: 80),
      itemCount: _books.length,
      itemBuilder: (ctx, idx) {
        final b = _books[idx];
        final title = b['book_title']?.toString() ?? '';
        final author = b['author']?.toString() ?? 'نامعلوم';
        final category = b['category']?.toString() ?? 'عام';
        final rack = b['rack_number']?.toString() ?? '';
        final total = (b['total_copies'] as int?) ?? 1;
        final avail = (b['available_copies'] as int?) ?? 0;
        final isAvail = avail > 0;

        return Card(
          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: isAvail
                            ? Colors.teal.shade50
                            : Colors.red.shade50,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Icon(
                        Icons.book,
                        color: isAvail ? Colors.teal : Colors.red,
                        size: 24,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            title,
                            style: const TextStyle(
                                fontSize: 16, fontWeight: FontWeight.bold),
                          ),
                          Text('مصنف: $author',
                              style: TextStyle(
                                  color: Colors.grey.shade700, fontSize: 13)),
                          const SizedBox(height: 4),
                          Wrap(
                            spacing: 6,
                            children: [
                              Chip(
                                label: Text(category,
                                    style: const TextStyle(fontSize: 10)),
                                padding: EdgeInsets.zero,
                                visualDensity: VisualDensity.compact,
                              ),
                              if (rack.isNotEmpty)
                                Chip(
                                  avatar: const Icon(Icons.shelves, size: 12),
                                  label: Text(rack,
                                      style: const TextStyle(fontSize: 10)),
                                  padding: EdgeInsets.zero,
                                  visualDensity: VisualDensity.compact,
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    Column(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: isAvail
                                ? Colors.green.shade100
                                : Colors.red.shade100,
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            '$avail / $total',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: isAvail
                                  ? Colors.green.shade900
                                  : Colors.red.shade900,
                            ),
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          isAvail ? 'دستیاب' : 'جاری شدہ',
                          style: TextStyle(
                            fontSize: 10,
                            color: isAvail ? Colors.green : Colors.red,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
                const Divider(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        IconButton(
                          icon: const Icon(Icons.edit, size: 18),
                          tooltip: 'ترمیم کریں',
                          onPressed: () => _showEditBookDialog(b),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.red, size: 18),
                          tooltip: 'حذف کریں',
                          onPressed: () => _confirmDeleteBook(b['id'] as int),
                        ),
                      ],
                    ),
                    if (isAvail)
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 6),
                        ),
                        icon: const Icon(Icons.assignment_turned_in, size: 16),
                        label: const Text('جاری کریں',
                            style: TextStyle(fontSize: 12)),
                        onPressed: () => _showIssueBookDialog(b),
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

  Widget _buildIssuesList() {
    if (_issues.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.history, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            Text('کوئی کتاب جاری نہیں کی گئی ہے',
                style: TextStyle(color: Colors.grey.shade600)),
          ],
        ),
      );
    }

    final today = DateTime.now().toIso8601String().substring(0, 10);

    return ListView.builder(
      padding: const EdgeInsets.only(top: 8, bottom: 80),
      itemCount: _issues.length,
      itemBuilder: (ctx, idx) {
        final iss = _issues[idx];
        final bTitle = iss['book_title']?.toString() ?? 'کتاب';
        final bName = iss['borrower_name']?.toString() ?? '';
        final bType = iss['borrower_type']?.toString() ?? 'student';
        final issueDate = iss['issue_date']?.toString() ?? '';
        final dueDate = iss['due_date']?.toString() ?? '';
        final status = iss['status']?.toString() ?? 'Issued';
        final isIssued = status == 'Issued';
        final isOverdue = isIssued && dueDate.compareTo(today) < 0;

        return Card(
          margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          color: isOverdue ? Colors.red.shade50 : Colors.white,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
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
                        bTitle,
                        style: const TextStyle(
                            fontSize: 15, fontWeight: FontWeight.bold),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: isIssued
                            ? (isOverdue ? Colors.red : Colors.orange)
                            : Colors.green,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(
                        isIssued
                            ? (isOverdue ? 'تاخیر شدہ' : 'جاری شدہ')
                            : 'واپس شدہ',
                        style: const TextStyle(
                            fontSize: 11,
                            color: Colors.white,
                            fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Icon(bType == 'teacher' ? Icons.person_pin : Icons.school,
                        size: 14, color: Colors.blueGrey),
                    const SizedBox(width: 4),
                    Text(
                      '$bName (${bType == 'teacher' ? 'استاذ' : 'طالب علم'})',
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w500),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Text('تاریخِ اجراء: $issueDate',
                        style: const TextStyle(fontSize: 11, color: Colors.grey)),
                    const Spacer(),
                    Text('مقررہ واپسی: $dueDate',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                          color: isOverdue ? Colors.red : Colors.black87,
                        )),
                  ],
                ),
                if (isIssued) ...[
                  const Divider(height: 16),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.teal,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 6),
                      ),
                      icon: const Icon(Icons.undo, size: 16),
                      label: const Text('واپسی درج کریں',
                          style: TextStyle(fontSize: 12)),
                      onPressed: () => _showReturnDialog(iss),
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    return Scaffold(
      appBar: AppBar(
        title: Text(isMasjid ? 'لائبریری (مکتب / مسجد)' : 'لائبریری مینیجمنٹ',
            style: const TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.amber,
          indicatorWeight: 3,
          labelColor: Colors.amber,
          unselectedLabelColor: Colors.white70,
          tabs: [
            Tab(text: 'کتب کیٹلاگ (${_books.length})'),
            Tab(text: 'جاری شدہ رجسٹر (${_issues.length})'),
          ],
        ),
      ),
      drawer: const AppDrawer(),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // سرچ اور کیٹیگری فلٹر بار
                Container(
                  padding: const EdgeInsets.all(8),
                  color: Colors.white,
                  child: Column(
                    children: [
                      TextField(
                        decoration: InputDecoration(
                          hintText: 'کتاب، مصنف یا الماری تلاش کریں...',
                          prefixIcon: const Icon(Icons.search),
                          isDense: true,
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                          ),
                        ),
                        onChanged: (v) {
                          _searchQuery = v;
                          _loadData();
                        },
                      ),
                      const SizedBox(height: 6),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: _categories.map((cat) {
                            final isSel = _selectedCategory == cat;
                            return Padding(
                              padding: const EdgeInsets.only(left: 4),
                              child: ChoiceChip(
                                label: Text(cat,
                                    style: const TextStyle(fontSize: 11)),
                                selected: isSel,
                                selectedColor: primaryGreen.withOpacity(0.2),
                                onSelected: (sel) {
                                  if (sel) {
                                    setState(() => _selectedCategory = cat);
                                    _loadData();
                                  }
                                },
                              ),
                            );
                          }).toList(),
                        ),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: TabBarView(
                    controller: _tabController,
                    children: [
                      RefreshIndicator(
                          onRefresh: _loadData, child: _buildBooksList()),
                      RefreshIndicator(
                          onRefresh: _loadData, child: _buildIssuesList()),
                    ],
                  ),
                ),
              ],
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('نئی کتاب'),
        onPressed: _showAddBookDialog,
      ),
    );
  }
}
