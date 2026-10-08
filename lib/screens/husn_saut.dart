import 'package:madrasa_app/utils/sync_service.dart';
// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../widgets/madrasa_card.dart';
import '../widgets/madrasa_inputs.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_ui_helper.dart';
import '../widgets/app_drawer.dart';
import 'dashboard.dart';

class HusnSautPage extends StatefulWidget {
  final String? className;
  const HusnSautPage({super.key, this.className});

  @override
  _HusnSautPageState createState() => _HusnSautPageState();
}

class _HusnSautPageState extends State<HusnSautPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryColor = const Color(0xFF6A1B9A);

  final _dateController = TextEditingController();
  final _studentSearchController = TextEditingController();
  String? selectedStudent;
  List<Map<String, dynamic>> students = [];
  int? _existingRecordId;
  int mashqPeriods = 2;

  String? manzilSabaq;
  String? manzilListener;
  String? manzilGrade;

  List<String?> mashqSabaqs = [null, null, null, null];
  List<String?> mashqTimes = [null, null, null, null];

  String? tilawatQty;
  String? tilawatParahs;

  final List<String> paraNumbers = [
    'نورانی قاعدہ',
    'نماز حنفی',
    '1',
    '2',
    '3',
    '4',
    '5',
    '6',
    '7',
    '8',
    '9',
    '10',
    '11',
    '12',
    '13',
    '14',
    '15',
    '16',
    '17',
    '18',
    '19',
    '20',
    '21',
    '22',
    '23',
    '24',
    '25',
    '26',
    '27',
    '28',
    '29',
    '30',
  ];
  final List<String> grades = ["پختہ", "کچا", "ناقص", "رعایتی پاس"];
  final List<String> qtyOptions = [
    "پاؤ",
    "آدھا",
    "تین پاؤ",
    "مکمل",
    "ڈیڑھ",
    "دو"
  ];
  final List<String> statusOptions = ["ممتاز", "بہتر", "معمولی", "کمزور"];

  @override
  void initState() {
    super.initState();
    _dateController.text = _formatDate(DateTime.now());
    _loadStudentsAndClassConfig();
  }

  @override
  void dispose() {
    _dateController.dispose();
    _studentSearchController.dispose();
    super.dispose();
  }

  String _formatDate(DateTime date) =>
      "${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}";

  void _loadStudentsAndClassConfig() async {
    final data = await DBHelper().getStudentsForUser();
    if (widget.className != null) {
      try {
        final classData = await DBHelper().getClassByName(widget.className!);
        if (classData != null) {
          final p =
              int.tryParse(classData['mashq_periods']?.toString() ?? '2') ?? 2;
          if (mounted) setState(() => mashqPeriods = p.clamp(1, 4));
        }
      } catch (_) {}
    }
    if (mounted) {
      setState(() {
        students = data.where((s) {
          bool isActive = s['status'] == 'Active' &&
              (s['is_deleted'] == 0 || s['is_deleted'] == null);
          String cName =
              (s['class_name']?.toString() ?? s['class']?.toString() ?? '')
                  .toLowerCase();
          if (widget.className != null) {
            return isActive && cName == widget.className!.toLowerCase();
          }
          return isActive && cName.contains('حسن صوت');
        }).toList();
      });
    }
  }

  Future<void> _checkExistingRecord() async {
    _existingRecordId = null;
    if (selectedStudent == null) return;
    final db = await DBHelper().database;
    final existing = await db.query('husn_saut_record',
        where: 'student_id = ? AND date = ?',
        whereArgs: [selectedStudent, _dateController.text]);
    if (existing.isNotEmpty) {
      final r = existing.last;
      _existingRecordId = r['id'] as int?;
      setState(() {
        manzilSabaq = r['manzil_sabaq']?.toString();
        manzilListener = r['manzil_listener']?.toString();
        manzilGrade = r['manzil_grade']?.toString();
        for (int i = 0; i < 4; i++) {
          mashqSabaqs[i] = r['mashq_${i + 1}_sabaq']?.toString();
          mashqTimes[i] = r['mashq_${i + 1}_time']?.toString();
        }
        tilawatQty = r['tilawat_qty']?.toString();
        tilawatParahs = r['tilawat_parahs']?.toString();
      });
    } else {
      setState(() {
        manzilSabaq = null;
        manzilListener = null;
        manzilGrade = null;
        mashqSabaqs = [null, null, null, null];
        mashqTimes = [null, null, null, null];
        tilawatQty = null;
        tilawatParahs = null;
      });
    }
  }

  Future<void> _saveRecord() async {
    if (selectedStudent == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('طالب علم منتخب کریں!'), backgroundColor: Colors.red));
      return;
    }
    try {
      final Map<String, dynamic> data = {
        'date': _dateController.text,
        'student_id': selectedStudent,
        'manzil_sabaq': manzilSabaq ?? '',
        'manzil_listener': manzilListener ?? '',
        'manzil_grade': manzilGrade ?? '',
        'mashq_1_sabaq': mashqSabaqs[0] ?? '',
        'mashq_1_time': mashqTimes[0] ?? '',
        'mashq_2_sabaq': mashqSabaqs[1] ?? '',
        'mashq_2_time': mashqTimes[1] ?? '',
        'mashq_3_sabaq': mashqSabaqs[2] ?? '',
        'mashq_3_time': mashqTimes[2] ?? '',
        'mashq_4_sabaq': mashqSabaqs[3] ?? '',
        'mashq_4_time': mashqTimes[3] ?? '',
        'tilawat_qty': tilawatQty ?? '',
        'tilawat_parahs': tilawatParahs ?? '',
        'is_deleted': 0,
        'is_synced': 0,
      };
      if (_existingRecordId != null) {
        final db = await DBHelper().database;
        await db.update('husn_saut_record', data,
            where: 'id = ?', whereArgs: [_existingRecordId]);
      } else {
        await DBHelper().insertHusnSaut(data);
        await _checkExistingRecord();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('خرابی: $e'), backgroundColor: Colors.red));
      }
      return;
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('حسن صوت ریکارڈ محفوظ / اپڈیٹ ہو گیا!'),
        backgroundColor: Colors.green));
    SyncService.runTwoWaySync().catchError((_) {});
    if (!mounted) return;
    setState(() {
      manzilSabaq = null;
      manzilListener = null;
      manzilGrade = null;
      mashqSabaqs = [null, null, null, null];
      mashqTimes = [null, null, null, null];
      tilawatQty = null;
      tilawatParahs = null;
    });
  }

  String _studentLabel(Map<String, dynamic> s) {
    return "${s['name'] ?? 'نامعلوم'} - ولد: ${s['father_name'] ?? '-'}";
  }

  String _selectedStudentLabel(bool isUrdu) {
    if (selectedStudent == null) {
      return isUrdu ? "طالب علم تلاش کریں" : "Search student";
    }
    final sel = students.where((s) => s['id']?.toString() == selectedStudent);
    if (sel.isEmpty) return isUrdu ? "طالب علم تلاش کریں" : "Search student";
    return _studentLabel(sel.first);
  }

  Future<void> _showStudentSearch(bool isUrdu) async {
    _studentSearchController.clear();
    var filtered = List<Map<String, dynamic>>.from(students);
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setSheetState) => SafeArea(
          child: Padding(
            padding: EdgeInsets.only(
                left: 16,
                right: 16,
                top: 16,
                bottom: MediaQuery.of(context).viewInsets.bottom + 16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              MadrasaTextField(
                controller: _studentSearchController,
                autofocus: true,
                decoration: InputDecoration(
                    labelText: isUrdu ? 'نام، ولدیت یا داخلہ نمبر' : 'Search',
                    prefixIcon: const Icon(Icons.search),
                    border: const OutlineInputBorder()),
                onChanged: (value) {
                  final q = value.trim().toLowerCase();
                  setSheetState(() {
                    filtered = students.where((s) {
                      final h = [s['name'], s['father_name'], s['roll_number']]
                          .map((v) => v?.toString().toLowerCase() ?? '')
                          .join(' ');
                      return h.contains(q);
                    }).toList();
                  });
                },
              ),
              const SizedBox(height: 12),
              SizedBox(
                height: 360,
                child: filtered.isEmpty
                    ? Center(
                        child: Text(isUrdu ? 'کوئی نتیجہ نہیں' : 'No results'))
                    : ListView.separated(
                        itemCount: filtered.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final s = filtered[index];
                          return ListTile(
                            leading: const Icon(Icons.person),
                            title: Text(_studentLabel(s)),
                            subtitle: Text(s['class_name']?.toString() ?? ''),
                            onTap: () =>
                                Navigator.pop(context, s['id']?.toString()),
                          );
                        }),
              ),
            ]),
          ),
        ),
      ),
    );
    if (picked == null) return;
    setState(() => selectedStudent = picked);
    await _checkExistingRecord();
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
                title: Text(
                    isUrdu
                        ? 'حسن صوت - روزانہ رپورٹ'
                        : 'Husn-e-Saut Daily Entry',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                backgroundColor: primaryColor,
                foregroundColor: Colors.white,
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(
                              isUrdu ? "سنک ہو رہا ہے..." : "Syncing...")));
                      try {
                        await SyncUIHelper.performManualSync(context);
                        if (mounted) {
                          _loadStudentsAndClassConfig();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content:
                                  Text(isUrdu ? "سنک مکمل!" : "Sync complete!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (_) {}
                    },
                  ),
                ],
              ),
              body: Padding(
                padding: const EdgeInsets.all(12.0),
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      MadrasaCard(
                        elevation: 3,
                        child: Padding(
                          padding: const EdgeInsets.all(12.0),
                          child: Row(children: [
                            Expanded(
                              child: MadrasaTextField(
                                controller: _dateController,
                                readOnly: true,
                                decoration: InputDecoration(
                                    labelText: isUrdu ? 'تاریخ' : 'Date',
                                    suffixIcon: const Icon(Icons.calendar_today,
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
                                  child: Row(children: [
                                    const Icon(Icons.search, size: 20),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Text(
                                        students.isEmpty
                                            ? (isUrdu
                                                ? "کوئی طالب علم نہیں"
                                                : "No students")
                                            : _selectedStudentLabel(isUrdu),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 13),
                                      ),
                                    ),
                                    const Icon(Icons.arrow_drop_down),
                                  ]),
                                ),
                              ),
                            ),
                          ]),
                        ),
                      ),
                      const SizedBox(height: 15),
                      _buildSectionCard(
                        isUrdu ? "منزل سنانا" : "Manzil Recitation",
                        primaryColor,
                        [
                          Row(children: [
                            Expanded(
                                child: _buildDropdown(
                                    isUrdu ? "پارہ" : "Parah",
                                    paraNumbers,
                                    manzilSabaq,
                                    (v) => setState(() => manzilSabaq = v))),
                            const SizedBox(width: 10),
                            Expanded(
                                child: TextFormField(
                                    initialValue: manzilListener,
                                    onChanged: (v) =>
                                        setState(() => manzilListener = v),
                                    decoration: InputDecoration(
                                      labelText:
                                          isUrdu ? "سننے والا" : "Listener",
                                      border: const OutlineInputBorder(),
                                      isDense: true,
                                    ))),
                            const SizedBox(width: 10),
                            Expanded(
                                child: _buildDropdown(
                                    isUrdu ? "کیفیت" : "Grade",
                                    statusOptions,
                                    manzilGrade,
                                    (v) => setState(() => manzilGrade = v))),
                          ]),
                        ],
                      ),
                      const SizedBox(height: 15),
                      ...List.generate(
                          mashqPeriods,
                          (i) => Column(children: [
                                _buildSectionCard(
                                  isUrdu ? "مشق ${i + 1}" : "Mashq ${i + 1}",
                                  Colors.teal.shade700,
                                  [
                                    Row(children: [
                                      Expanded(
                                          child: TextFormField(
                                              initialValue: mashqSabaqs[i],
                                              onChanged: (v) => setState(
                                                  () => mashqSabaqs[i] = v),
                                              decoration: InputDecoration(
                                                labelText:
                                                    isUrdu ? "آیات" : "Aayaat",
                                                border:
                                                    const OutlineInputBorder(),
                                                isDense: true,
                                              ))),
                                      const SizedBox(width: 10),
                                      Expanded(
                                          child: _buildTimeInput(
                                              isUrdu ? "وقت" : "Time",
                                              mashqTimes[i],
                                              (t) => setState(
                                                  () => mashqTimes[i] = t))),
                                    ]),
                                  ],
                                ),
                                const SizedBox(height: 10),
                              ])),
                      _buildSectionCard(
                        isUrdu ? "تلاوت" : "Tilawat",
                        Colors.green.shade700,
                        [
                          Row(children: [
                            Expanded(
                                child: _buildDropdown(
                                    isUrdu ? "مقدار" : "Qty",
                                    [
                                      "1 پارہ",
                                      "2 پارے",
                                      "3 پارے",
                                      "4 پارے",
                                      "5 پارے"
                                    ],
                                    tilawatQty,
                                    (v) => setState(() => tilawatQty = v))),
                            const SizedBox(width: 10),
                            Expanded(
                                child: TextFormField(
                                    initialValue: tilawatParahs,
                                    onChanged: (v) =>
                                        setState(() => tilawatParahs = v),
                                    decoration: InputDecoration(
                                      labelText: isUrdu
                                          ? "پارہ نمبرز"
                                          : "Parah Numbers",
                                      hintText:
                                          isUrdu ? "مثلاً 1, 2" : "e.g. 1, 2",
                                      border: const OutlineInputBorder(),
                                      isDense: true,
                                    ))),
                          ]),
                        ],
                      ),
                      const SizedBox(height: 15),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(
                            backgroundColor: primaryColor,
                            padding: const EdgeInsets.symmetric(vertical: 15)),
                        onPressed: _saveRecord,
                        child: Text(
                            isUrdu ? 'محفوظ / اپڈیٹ کریں' : 'Save / Update',
                            style: const TextStyle(
                                fontSize: 18, color: Colors.white)),
                      ),
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

  Widget _buildSectionCard(String title, Color color, List<Widget> children) {
    return MadrasaCard(
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
              style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                  fontSize: 16)),
        ),
        Padding(
            padding: const EdgeInsets.all(12.0),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children)),
      ]),
    );
  }

  Widget _buildDropdown(String label, List<String> items, String? value,
      Function(String?) onChanged) {
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
              border: Border.all(color: Colors.grey.shade400),
              borderRadius: BorderRadius.circular(5)),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              isExpanded: true,
              value: value,
              hint: const Text("--"),
              icon: const Icon(Icons.keyboard_arrow_down),
              items: items
                  .map((i) => DropdownMenuItem<String>(
                      value: i,
                      child: Text(i, style: const TextStyle(fontSize: 13))))
                  .toList(),
              onChanged: onChanged,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildTimeInput(
      String label, String? time, Function(String) onChanged) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
        const SizedBox(height: 5),
        Autocomplete<String>(
          initialValue: TextEditingValue(text: time ?? ''),
          optionsBuilder: (TextEditingValue textEditingValue) {
            final options = [
              '10 منٹ',
              '20 منٹ',
              '30 منٹ',
              '40 منٹ',
              '50 منٹ',
              '1 گھنٹہ'
            ];
            if (textEditingValue.text.isEmpty) {
              return options;
            }
            return options.where((String option) {
              return option.contains(textEditingValue.text);
            });
          },
          onSelected: (String selection) {
            onChanged(selection);
          },
          fieldViewBuilder:
              (context, controller, focusNode, onEditingComplete) {
            return TextFormField(
              controller: controller,
              focusNode: focusNode,
              onEditingComplete: onEditingComplete,
              onChanged: onChanged,
              decoration: InputDecoration(
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(4)),
                isDense: true,
                hintText: "وقت (منٹ)",
              ),
              style: const TextStyle(fontSize: 13),
            );
          },
        ),
      ],
    );
  }
}
