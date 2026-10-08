// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';

class NaziraPage extends StatefulWidget {
  final String? className;
  const NaziraPage({super.key, this.className});

  @override
  _NaziraPageState createState() => _NaziraPageState();
}

class _NaziraPageState extends State<NaziraPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  final _dateController = TextEditingController();
  final _studentSearchController = TextEditingController();
  String? selectedStudent;
  List<Map<String, dynamic>> students = [];
  int? _existingRecordId;


  String? morningSabaq;
  final _morningQtyController = TextEditingController();
  String? morningTime;
  String? morningStatus;
  
  String? eveningSabaq;
  final _eveningQtyController = TextEditingController();
  String? eveningTime;
  String? eveningStatus;

  final List<String> statusOptions = ["بہت اچھا", "اچھا", "درمیانہ", "کمزور", "ناغہ"];
  final List<String> paraNumbers = [
    'نورانی قاعدہ',
    'نماز حنفی',
    ...List.generate(30, (index) => 'پارہ ${index + 1}'),
  ];

  final List<String> grades = ["پختہ", "کچا", "ناقص", "رعایتی پاس"];
  final List<double> sabqiTargetOptions = [
    0.0,
    0.25,
    0.5,
    0.75,
    1.0,
    1.25,
    1.5
  ];
  final List<double> manzilTargetOptions = [
    0.0,
    0.25,
    0.5,
    0.75,
    1.0,
    1.25,
    1.5,
    1.75,
    2.0,
    2.5,
    3.0,
    4.0,
    5.0
  ];
  final List<double> amountOptions = [0.25, 0.5, 0.51, 0.52, 0.75, 1.0];

  @override
  void initState() {
    super.initState();
    _dateController.text = _formatDate(DateTime.now());
    _loadStudents();
  }

  @override
  void dispose() {
    _dateController.dispose();
    _studentSearchController.dispose();
    _morningQtyController.dispose();
    _eveningQtyController.dispose();
    super.dispose();
  }

  String _formatDate(DateTime date) {
    return "${date.day}/${date.month}/${date.year}";
  }

  void _loadStudents() async {
    final data = await DBHelper().getStudentsForUser();
    setState(() {
      students = data.where((s) {
        bool isActive = s['status'] == 'Active' &&
            (s['is_deleted'] == 0 || s['is_deleted'] == null);
        String cName =
            (s['class_name']?.toString() ?? s['class']?.toString() ?? '')
                .toLowerCase();
        bool isNazira = cName.contains('ناظرہ') ||
            cName.contains('حسن صوت') ||
            cName.contains('گردان');
        return isActive && isNazira;
      }).toList();
    });
  }

  String _normalizeDate(String dateStr) {
    if (dateStr.contains('-')) return dateStr;
    try {
      final parts = dateStr.split('/');
      if (parts.length == 3) {
        String d = parts[0].padLeft(2, '0');
        String m = parts[1].padLeft(2, '0');
        String y = parts[2];
        return "$y-$m-$d";
      }
    } catch (_) {}
    return dateStr;
  }

  Future<void> _checkExistingRecord() async {
    _existingRecordId = null;
    if (selectedStudent == null || selectedStudent == 'empty') return;
    String d = _dateController.text;
    if (d.isEmpty) return;
    String normalizedD = _normalizeDate(d);

    final db = await DBHelper().database;
    final existing = await db.query('nazira_record', 
        where: 'student_id = ? AND (date = ? OR date = ?)', 
        whereArgs: [selectedStudent, d, normalizedD]);
    
    if (existing.isNotEmpty) {
      final r = existing.last;
      _existingRecordId = r['id'] as int?;
      setState(() {
        morningSabaq = r['morning_sabaq']?.toString().isEmpty == true ? null : r['morning_sabaq']?.toString();
        _morningQtyController.text = r['morning_qty']?.toString() ?? '';
        morningTime = r['morning_time']?.toString().isEmpty == true ? null : r['morning_time']?.toString();
        morningStatus = r['morning_status']?.toString().isEmpty == true ? null : r['morning_status']?.toString();
        
        eveningSabaq = r['evening_sabaq']?.toString().isEmpty == true ? null : r['evening_sabaq']?.toString();
        _eveningQtyController.text = r['evening_qty']?.toString() ?? '';
        eveningTime = r['evening_time']?.toString().isEmpty == true ? null : r['evening_time']?.toString();
        eveningStatus = r['evening_status']?.toString().isEmpty == true ? null : r['evening_status']?.toString();
      });
    } else {
      final attRows = await db.query('attendance',
          where: 'student_id = ? AND (date = ? OR date = ?)',
          whereArgs: [selectedStudent, d, normalizedD]);
      String attStatus = '';
      if (attRows.isNotEmpty) {
        String s1 = (attRows.first['status_1'] ?? attRows.first['status'] ?? '').toString().toUpperCase();
        String s2 = (attRows.first['status_2'] ?? '').toString().toUpperCase();
        if (s1 == 'A' || s2 == 'A' || s1.contains('غائب')) {
          attStatus = 'A';
        } else if (s1 == 'L' || s2 == 'L' || s1.contains('رخصت')) {
          attStatus = 'L';
        }
      }

      if (attStatus == 'A') {
        setState(() {
          morningSabaq = 'غیر حاضر';
          morningStatus = 'غیر حاضر';
          eveningSabaq = 'غیر حاضر';
          eveningStatus = 'غیر حاضر';
        });
      } else if (attStatus == 'L') {
        setState(() {
          morningSabaq = 'رخصت';
          morningStatus = 'رخصت';
          eveningSabaq = 'رخصت';
          eveningStatus = 'رخصت';
        });
      } else {
        setState(() {
          morningSabaq = null;
          _morningQtyController.clear();
          morningTime = null;
          morningStatus = null;
          
          eveningSabaq = null;
          _eveningQtyController.clear();
          eveningTime = null;
          eveningStatus = null;
        });
      }
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg, style: const TextStyle(fontWeight: FontWeight.bold)),
      backgroundColor: Colors.red.shade800,
      duration: const Duration(seconds: 4),
    ));
  }

  String _studentLabel(Map<String, dynamic> student) {
    final name = student['name']?.toString() ?? 'نامعلوم';
    final father = student['father_name']?.toString() ?? '-';
    return "$name - ولد: $father";
  }

  String _selectedStudentLabel(bool isUrdu) {
    if (selectedStudent == null || selectedStudent == "empty") {
      return isUrdu ? "طالب علم تلاش کریں" : "Search student";
    }
    final selected = students.where((s) => s['id']?.toString() == selectedStudent);
    if (selected.isEmpty) return isUrdu ? "طالب علم تلاش کریں" : "Search student";
    return _studentLabel(selected.first);
  }

  Future<void> _showStudentSearch(bool isUrdu) async {
    _studentSearchController.clear();
    var filtered = List<Map<String, dynamic>>.from(students);
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setSheetState) {
            return SafeArea(
              child: Padding(
                padding: EdgeInsets.only(
                  left: 16,
                  right: 16,
                  top: 16,
                  bottom: MediaQuery.of(context).viewInsets.bottom + 16,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextField(
                      controller: _studentSearchController,
                      autofocus: true,
                      decoration: InputDecoration(
                        labelText: isUrdu ? 'نام، ولدیت یا داخلہ نمبر سے تلاش' : 'Search by name, father or roll no',
                        prefixIcon: const Icon(Icons.search),
                        border: const OutlineInputBorder(),
                      ),
                      onChanged: (value) {
                        final q = value.trim().toLowerCase();
                        setSheetState(() {
                          filtered = students.where((s) {
                            final haystack = [
                              s['name'],
                              s['father_name'],
                              s['roll_number'],
                              s['admission_number'],
                              s['class_name'],
                              s['class'],
                            ].map((v) => v?.toString().toLowerCase() ?? '').join(' ');
                            return haystack.contains(q);
                          }).toList();
                        });
                      },
                    ),
                    const SizedBox(height: 12),
                    SizedBox(
                      height: 360,
                      child: filtered.isEmpty
                          ? Center(child: Text(isUrdu ? 'کوئی طالب علم نہیں ملا' : 'No student found'))
                          : ListView.separated(
                              itemCount: filtered.length,
                              separatorBuilder: (_, __) => const Divider(height: 1),
                              itemBuilder: (context, index) {
                                final student = filtered[index];
                                return ListTile(
                                  leading: const Icon(Icons.person),
                                  title: Text(_studentLabel(student)),
                                  subtitle: Text(student['class_name']?.toString() ?? student['class']?.toString() ?? ''),
                                  onTap: () => Navigator.pop(context, student['id']?.toString()),
                                );
                              },
                            ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
    if (picked == null) return;
    setState(() => selectedStudent = picked);
    await _checkExistingRecord();
  }

  Future<void> _saveRecord() async {
    if (selectedStudent == null || selectedStudent == "empty") {
      _showError(isUrduGlobal.value ? 'طالب علم منتخب کریں!' : 'Select Student!');
      return;
    }

    try {
      final db = await DBHelper().database;
      Map<String, dynamic> data = {
        'date': _dateController.text,
        'student_id': selectedStudent,
        'morning_sabaq': morningSabaq ?? '',
        'morning_qty': _morningQtyController.text,
        'morning_time': morningTime ?? '',
        'morning_status': morningStatus ?? '',
        'evening_sabaq': eveningSabaq ?? '',
        'evening_qty': _eveningQtyController.text,
        'evening_time': eveningTime ?? '',
        'evening_status': eveningStatus ?? '',
      };

      if (_existingRecordId != null) {
        data['is_synced'] = 0;
        await db.update('nazira_record', data, where: 'id = ?', whereArgs: [_existingRecordId]);
      } else {
        await DBHelper().insertNazira(data);
        await _checkExistingRecord(); // Update the existing record id
      }
    } catch (e) {
      if (mounted) _showError(isUrduGlobal.value ? 'ریکارڈ محفوظ کرنے میں خرابی: $e' : 'Error saving record: $e');
      return;
    }

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? 'ناظرہ کا ریکارڈ محفوظ / اپڈیٹ ہو گیا!' : 'Record saved / updated!'), backgroundColor: Colors.green));

    try { await SyncService.runTwoWaySync(); } catch (_) {}

    if (!mounted) return;
    if (!mounted) return;
    // Removed `selectedStudent = null` so the form stays populated and flexible.
    setState(() {
      morningSabaq = null;
      _morningQtyController.clear();
      morningTime = null;
      morningStatus = null;
      eveningSabaq = null;
      _eveningQtyController.clear();
      eveningTime = null;
      eveningStatus = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final Set<String> seenIds = {};

    for (var s in students) {
      final idStr = s['id']?.toString();
      if (idStr != null && idStr.isNotEmpty && !seenIds.contains(idStr)) {
        seenIds.add(idStr);
      }
    }
    if (selectedStudent != null &&
        selectedStudent != "empty" &&
        !seenIds.contains(selectedStudent)) {
      selectedStudent = null;
    }

    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
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
                title: Text(isUrdu ? 'ناظرہ کی روزانہ رپورٹ' : 'Nazira Daily Entry',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
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
                          _loadStudents();
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
              ),
              endDrawer: const AppDrawer(),
              body: Padding(
                padding: const EdgeInsets.all(12.0),
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Card(
                        elevation: 3,
                        child: Padding(
                          padding: const EdgeInsets.all(12.0),
                          child: Row(
                            children: [
                              Expanded(
                                flex: 1,
                                child: TextField(
                                  controller: _dateController,
                                  readOnly: true,
                                  decoration: InputDecoration(
                                      labelText: isUrdu ? 'تاریخ' : 'Date',
                                      suffixIcon: const Icon(
                                          Icons.calendar_today,
                                          size: 20),
                                      border: const OutlineInputBorder()),
                                  onTap: () async {
                                    DateTime? picked = await showDatePicker(
                                        context: context,
                                        initialDate: DateTime.now(),
                                        firstDate: DateTime(2000),
                                        lastDate: DateTime(2100));
                                    if (picked != null) {
                                      setState(() => _dateController.text =
                                          _formatDate(picked));
                                      await _checkExistingRecord();
                                    }
                                  },
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                flex: 1,
                                child: InkWell(
                                  onTap: students.isEmpty
                                      ? null
                                      : () => _showStudentSearch(isUrdu),
                                  child: Container(
                                    height: 55,
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 10),
                                    decoration: BoxDecoration(
                                        border: Border.all(color: Colors.grey),
                                        borderRadius: BorderRadius.circular(5)),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.search, size: 20),
                                        const SizedBox(width: 8),
                                        Expanded(
                                          child: Text(
                                            students.isEmpty
                                                ? (isUrdu
                                                    ? "کوئی طالب علم موجود نہیں"
                                                    : "No students found")
                                                : _selectedStudentLabel(isUrdu),
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 13,
                                            ),
                                          ),
                                        ),
                                        const Icon(Icons.arrow_drop_down),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 15),
                      _buildSectionCard(
                          isUrdu ? "صبح کا سبق" : "Morning Sabaq", primaryGreen, [
                        Row(
                          children: [
                            Expanded(child: _buildDropdown(isUrdu ? "پارہ / تختی" : "Parah", paraNumbers, morningSabaq, (val) => setState(() => morningSabaq = val))),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(isUrdu ? "مقدار" : "Qty", style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                  const SizedBox(height: 5),
                                  SizedBox(height: 45, child: TextField(controller: _morningQtyController, decoration: InputDecoration(contentPadding: const EdgeInsets.symmetric(horizontal: 10), border: OutlineInputBorder(borderRadius: BorderRadius.circular(5))))),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(isUrdu ? "وقت" : "Time", style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                  const SizedBox(height: 5),
                                  _buildTimePicker(morningTime, (t) => setState(() => morningTime = t)),
                                ],
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(child: _buildDropdown(isUrdu ? "کیفیت" : "Status", statusOptions, morningStatus, (val) => setState(() => morningStatus = val))),
                          ],
                        ),
                      ]),
                      const SizedBox(height: 15),
                      _buildSectionCard(
                          isUrdu ? "شام کا سبق" : "Evening Sabaq", Colors.blue.shade700, [
                        Row(
                          children: [
                            Expanded(child: _buildDropdown(isUrdu ? "پارہ / تختی" : "Parah", paraNumbers, eveningSabaq, (val) => setState(() => eveningSabaq = val))),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(isUrdu ? "مقدار" : "Qty", style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                  const SizedBox(height: 5),
                                  SizedBox(height: 45, child: TextField(controller: _eveningQtyController, decoration: InputDecoration(contentPadding: const EdgeInsets.symmetric(horizontal: 10), border: OutlineInputBorder(borderRadius: BorderRadius.circular(5))))),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(isUrdu ? "وقت" : "Time", style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                  const SizedBox(height: 5),
                                  _buildTimePicker(eveningTime, (t) => setState(() => eveningTime = t)),
                                ],
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(child: _buildDropdown(isUrdu ? "کیفیت" : "Status", statusOptions, eveningStatus, (val) => setState(() => eveningStatus = val))),
                          ],
                        ),
                      ]),
                      const SizedBox(height: 20),
                      ElevatedButton(
                          style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                              padding:
                                  const EdgeInsets.symmetric(vertical: 15)),
                          onPressed: _saveRecord,
                          child: Text(isUrdu ? 'محفوظ / اپڈیٹ کریں' : 'Save / Update',
                              style: const TextStyle(
                                  fontSize: 18, color: Colors.white))),
                      const SizedBox(height: 20),
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

  Widget _buildSectionCard(String title, Color color, List<Widget> children,
      {Color titleColor = Colors.white}) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: BorderSide(color: Colors.grey.shade300)),
      child: Column(children: [
        Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 10),
            decoration: BoxDecoration(
                color: color,
                borderRadius:
                    const BorderRadius.vertical(top: Radius.circular(8))),
            child: Text(title,
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: titleColor,
                    fontWeight: FontWeight.bold,
                    fontSize: 18))),
        Padding(
            padding: const EdgeInsets.all(12.0),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children)),
      ]),
    );
  }

  Widget _buildDropdown(String label, List<String> items, String? value,
      Function(String?) onChanged,
      {Color textColor = Colors.black}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
        const SizedBox(height: 5),
        Container(
          height: 45,
          padding: const EdgeInsets.symmetric(horizontal: 10),
          decoration: BoxDecoration(
              border: Border.all(
                  color: textColor == Colors.red
                      ? Colors.red
                      : Colors.grey.shade400),
              borderRadius: BorderRadius.circular(5)),
          child: DropdownButtonHideUnderline(
              child: DropdownButton<String>(
                  isExpanded: true,
                  value: value,
                  dropdownColor: Colors.white,
                  style: const TextStyle(color: Colors.black87, fontSize: 13),
                  hint: Text("--", style: TextStyle(color: textColor)),
                  icon: Icon(Icons.keyboard_arrow_down, color: textColor),
                  items: items
                      .map((i) => DropdownMenuItem<String>(
                          value: i,
                          child: Text(i,
                              style:
                                  const TextStyle(color: Colors.black87, fontSize: 13))))
                      .toList(),
                  onChanged: onChanged)),
        ),
      ],
    );
  }

  Widget _buildTimePicker(String? time, Function(String) onPicked) {
    return InkWell(
      onTap: () async {
        TimeOfDay? picked = await showTimePicker(
            context: context, initialTime: TimeOfDay.now());
        if (picked != null) {
          if (mounted) {
            onPicked(picked.format(context));
          }
        }
      },
      child: Container(
        height: 45,
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(4),
            border: Border.all(color: Colors.grey.shade400)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.access_time, size: 16, color: Colors.black54),
            const SizedBox(width: 5),
            Text(time ?? '--:--',
                style: const TextStyle(fontSize: 13, color: Colors.black87)),
          ],
        ),
      ),
    );
  }
}
