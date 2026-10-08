import 'package:flutter/material.dart';
import '../widgets/madrasa_card.dart';
import '../widgets/madrasa_inputs.dart';
import 'package:intl/intl.dart';
import '../db_helper.dart';

class QaidaEntryPage extends StatefulWidget {
  final String selectedClass;
  const QaidaEntryPage({super.key, required this.selectedClass});
  @override
  _QaidaEntryPageState createState() => _QaidaEntryPageState();
}

class _QaidaEntryPageState extends State<QaidaEntryPage> {
  final dbHelper = DBHelper();
  String _selectedDate = DateFormat('yyyy-MM-dd').format(DateTime.now());
  List<Map<String, dynamic>> maktabStudents = [];
  Map<String, Map<String, dynamic>> _records = {};
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final db = await dbHelper.database;
      final allStudents = await db.query('maktab_students', where: "class_name = ? AND status IN ('Active', 'Present', 'حاضر') AND (is_deleted = 0 OR is_deleted IS NULL)", whereArgs: [widget.selectedClass]);
      final allRecords = await db.query('maktab_qaida_records', where: "date = ? AND (is_deleted = 0 OR is_deleted IS NULL)", whereArgs: [_selectedDate]);
      
      Map<String, Map<String, dynamic>> recMap = {};
      for (var r in allRecords) {
        recMap[r['student_id'].toString()] = r;
      }
      if (mounted) {
        setState(() {
          maktabStudents = allStudents;
          _records = recMap;
        });
      }
    } catch (e) {
      debugPrint("Error loading Qaida records: $e");
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  Future<void> _selectDate(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: DateTime.parse(_selectedDate),
      firstDate: DateTime(2000),
      lastDate: DateTime(2101),
    );
    if (picked != null) {
      setState(() {
        _selectedDate = DateFormat('yyyy-MM-dd').format(picked);
      });
      _loadData();
    }
  }

  void _openEntryModal(Map<String, dynamic> student, Map<String, dynamic>? record) {
    String lessonType = record?['lesson_type']?.toString() ?? 'نورانی قاعدہ';
    List<String?> sabaqs = List.generate(4, (i) {
      var val = record?['sabaq_${i+1}']?.toString();
      return (val != null && val.isNotEmpty) ? val : null;
    });
    List<TextEditingController> qtyControllers = List.generate(4, (i) => TextEditingController(text: record?['qty_${i+1}']?.toString() ?? ''));
    List<String?> grades = List.generate(4, (i) {
      var val = record?['grade_${i+1}']?.toString();
      return (val != null && val.isNotEmpty) ? val : null;
    });
    
    TimeOfDay? selectedTime;
    if (record != null && record['hearing_time'] != null && record['hearing_time'].toString().isNotEmpty) {
      try {
        final parts = record['hearing_time'].toString().split(':');
        selectedTime = TimeOfDay(hour: int.parse(parts[0]), minute: int.parse(parts[1]));
      } catch (_) {}
    }

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setStateDialog) {
          List<String> getSabaqOptions() {
            if (lessonType == 'نماز حنفی') {
              return List.generate(80, (index) => 'صفحہ نمبر ${index + 1}');
            } else {
              return List.generate(17, (index) => 'تختی نمبر ${index + 1}');
            }
          }

          var sabaqOptions = getSabaqOptions();
          
          return AlertDialog(
            title: Text('${student['name']} - $_selectedDate'),
            content: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  DropdownButtonFormField<String>(
                    value: lessonType,
                    decoration: const InputDecoration(labelText: 'شعبہ منتخب کریں'),
                    items: const [
                      DropdownMenuItem(value: 'نورانی قاعدہ', child: Text('نورانی قاعدہ')),
                      DropdownMenuItem(value: 'نماز حنفی', child: Text('نماز حنفی')),
                    ],
                    onChanged: (val) {
                      setStateDialog(() {
                        lessonType = val!;
                        var newOptions = getSabaqOptions();
                        for (int i = 0; i < 4; i++) {
                          if (sabaqs[i] != null && !newOptions.contains(sabaqs[i])) {
                            sabaqs[i] = null;
                          }
                        }
                      });
                    },
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () async {
                            final TimeOfDay? picked = await showTimePicker(
                              context: context,
                              initialTime: selectedTime ?? TimeOfDay.now(),
                            );
                            if (picked != null) {
                              setStateDialog(() => selectedTime = picked);
                            }
                          },
                          icon: const Icon(Icons.access_time),
                          label: Text(selectedTime == null
                              ? 'وقت منتخب کریں'
                              : selectedTime!.format(context)),
                        ),
                      ),
                      if (selectedTime != null)
                        IconButton(
                          icon: const Icon(Icons.clear),
                          onPressed: () => setStateDialog(() => selectedTime = null),
                        )
                    ],
                  ),
                  const SizedBox(height: 10),
                  ...List.generate(4, (i) => MadrasaCard(
                    margin: const EdgeInsets.symmetric(vertical: 5),
                    child: Padding(
                      padding: const EdgeInsets.all(8.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('سبق ${i+1}', style: const TextStyle(fontWeight: FontWeight.bold)),
                          DropdownButtonFormField<String>(
                            value: sabaqs[i],
                            isExpanded: true,
                            decoration: const InputDecoration(labelText: 'تفصیل (تختی/صفحہ)'),
                            items: sabaqOptions.map((opt) => DropdownMenuItem(value: opt, child: Text(opt))).toList(),
                            onChanged: (val) => setStateDialog(() => sabaqs[i] = val),
                          ),
                          MadrasaTextField(
                            controller: qtyControllers[i],
                            decoration: const InputDecoration(labelText: 'مقدار (Quantity)'),
                          ),
                          DropdownButtonFormField<String>(
                            value: grades[i],
                            decoration: const InputDecoration(labelText: 'کیفیت / درجہ'),
                            items: const [
                              DropdownMenuItem(value: 'بہت اچھا', child: Text('بہت اچھا (Excellent)')),
                              DropdownMenuItem(value: 'اچھا', child: Text('اچھا (Good)')),
                              DropdownMenuItem(value: 'درمیانہ', child: Text('درمیانہ (Average)')),
                              DropdownMenuItem(value: 'کمزور', child: Text('کمزور (Weak)')),
                              DropdownMenuItem(value: 'ناغہ', child: Text('ناغہ (Absent)')),
                            ],
                            onChanged: (val) => setStateDialog(() => grades[i] = val),
                          ),
                        ],
                      ),
                    ),
                  )),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('بند کریں')),
              ElevatedButton(
                onPressed: () async {
                  final db = await dbHelper.database;
                  String hearingTimeStr = '';
                  if (selectedTime != null) {
                    hearingTimeStr = '${selectedTime!.hour.toString().padLeft(2, '0')}:${selectedTime!.minute.toString().padLeft(2, '0')}:00';
                  }
                  Map<String, dynamic> data = {
                    'student_id': student['id'],
                    'date': _selectedDate,
                    'lesson_type': lessonType,
                    'hearing_time': hearingTimeStr,
                    'sabaq_1': sabaqs[0] ?? '', 'qty_1': qtyControllers[0].text, 'grade_1': grades[0] ?? '',
                    'sabaq_2': sabaqs[1] ?? '', 'qty_2': qtyControllers[1].text, 'grade_2': grades[1] ?? '',
                    'sabaq_3': sabaqs[2] ?? '', 'qty_3': qtyControllers[2].text, 'grade_3': grades[2] ?? '',
                    'sabaq_4': sabaqs[3] ?? '', 'qty_4': qtyControllers[3].text, 'grade_4': grades[3] ?? '',
                    'is_synced': 0,
                  };
                  if (record != null) {
                    await db.update('maktab_qaida_records', data, where: 'id = ?', whereArgs: [record['id']]);
                  } else {
                    await db.insert('maktab_qaida_records', data);
                  }
                  Navigator.pop(ctx);
                  _loadData();
                },
                child: const Text('محفوظ کریں'),
              ),
            ],
          );
        }
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('قاعدہ / نماز حنفی اندراج')),
      body: _isLoading ? const Center(child: CircularProgressIndicator()) : Column(
        children: [
          ListTile(
            title: Text('تاریخ: $_selectedDate', style: const TextStyle(fontWeight: FontWeight.bold)),
            subtitle: Text('کلاس: ${widget.selectedClass}'),
            trailing: IconButton(icon: const Icon(Icons.calendar_today), onPressed: () => _selectDate(context)),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: maktabStudents.length,
              itemBuilder: (context, index) {
                final s = maktabStudents[index];
                final r = _records[s['id'].toString()];
                return MadrasaCard(
                  color: r != null ? Colors.green.shade50 : Colors.red.shade50,
                  child: ListTile(
                    title: Text(s['name'] ?? ''),
                    subtitle: Text(r != null ? '${r['lesson_type']} اندراج موجود ہے' : 'اندراج نہیں ہوا'),
                    trailing: ElevatedButton(
                      onPressed: () => _openEntryModal(s, r),
                      child: const Text('اندراج'),
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
