// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../db_helper.dart';
import '../utils/globals.dart';
import 'package:intl/intl.dart' as intl;
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';

class HifzPage extends StatefulWidget {
  final String? className;
  const HifzPage({super.key, this.className});

  @override
  _HifzPageState createState() => _HifzPageState();
}

class _HifzPageState extends State<HifzPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  final _dateController = TextEditingController();
  final _studentSearchController = TextEditingController();
  String? selectedStudent;
  List<Map<String, dynamic>> students = [];
  int? _existingRecordId;

  // --- 1. سبق (Sabaq) ---
  String? sabaqPara, sabaqGrade;
  final sabaqLinesController = TextEditingController();
  bool sabaqNotRecited = false;
  final sabaqNotRecitedReasonController = TextEditingController();

  // --- 2. سبقی (Sabqi) ---
  double _targetSabqiQty = 0.0;
  List<Map<String, dynamic>> sabqiDetails = [];
  String? selectedSabqiParaToAdd;
  double? selectedSabqiAmountToAdd;
  bool isSabqiJaiza = false;
  String? sabqiGrade;
  final sabqiListenerController = TextEditingController();
  bool sabqiNotRecited = false;
  final sabqiNotRecitedReasonController = TextEditingController();

  // --- 3. منزل (Manzil) ---
  double _targetManzilQty = 0.0;
  List<Map<String, dynamic>> manzilDetails = [];
  String? selectedManzilParaToAdd;
  double? selectedManzilAmountToAdd;
  bool isManzilJaiza = false;
  String? manzilGrade;
  final manzilListenerController = TextEditingController();
  bool manzilNotRecited = false;
  final manzilNotRecitedReasonController = TextEditingController();

  bool sabqiListenerJaiza = false;
  bool manzilListenerJaiza = false;

  String? sabaqTime;
  String? sabqiTime;
  String? manzilTime;

  final List<String> paraNumbers = [
    'نورانی قاعدہ',
    'نماز حنفی',
    ...List.generate(30, (index) => (index + 1).toString()),
  ];

  final List<String> grades = ["بہترین", "بہتر", "کمزور", "کچا"];
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
    sabaqLinesController.dispose();
    sabaqNotRecitedReasonController.dispose();
    sabqiListenerController.dispose();
    sabqiNotRecitedReasonController.dispose();
    manzilListenerController.dispose();
    manzilNotRecitedReasonController.dispose();
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
        bool isHifz = cName.contains('حفظ') ||
            cName.contains('تحفیظ') ||
            cName.contains('hifz');
        return isActive && isHifz;
      }).toList();
    });
  }

  String getUrduQty(double qty) {
    if (qty == 0.0) return "منتخب کریں";
    if (qty == 0.25) return "پاؤ";
    if (qty == 0.50) return "آدھا";
    if (qty == 0.51) return "اوپر کا آدھا";
    if (qty == 0.52) return "نیچے کا آدھا";
    if (qty == 0.75) return "پونا";
    if (qty == 1.00) return "ایک پارہ";
    if (qty == 1.25) return "سوا پارہ";
    if (qty == 1.50) return "ڈیڑھ پارہ";
    if (qty == 1.75) return "پونے دو پارے";
    if (qty == 2.00) return "دو پارے";
    if (qty == 2.50) return "ڈھائی پارے";
    if (qty == 3.00) return "تین پارے";
    return "$qty پارے";
  }

  double _getRealQty(double qty) {
    if (qty == 0.51 || qty == 0.52) return 0.5;
    return qty;
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
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
            color: Colors.grey.shade100,
            borderRadius: BorderRadius.circular(4),
            border: Border.all(color: Colors.grey.shade300)),
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

  double get _currentSabqiQty => sabqiDetails.fold(
      0.0, (sum, item) => sum + _getRealQty(item['amountVal'] as double));
  double get _currentManzilQty => manzilDetails.fold(
      0.0, (sum, item) => sum + _getRealQty(item['amountVal'] as double));

  double _parseUrduQty(String qtyStr) {
    final s = qtyStr.trim();
    if (s == "پاؤ" || s == "ایک پاؤ" || s == "0.25") return 0.25;
    if (s == "آدھا" || s == "نصف" || s == "آدھا پارہ" || s == "0.5" || s == "0.50") return 0.50;
    if (s == "اوپر کا آدھا" || s == "نصف اول" || s == "0.51") return 0.51;
    if (s == "نیچے کا آدھا" || s == "نصف آخر" || s == "نصف ثانی" || s == "0.52") return 0.52;
    if (s == "پونا" || s == "پونا پاؤ" || s == "تین پاؤ" || s == "پونا پارہ" || s == "0.75") return 0.75;
    if (s == "ایک پارہ" || s == "1" || s == "1.0" || s == "1.00" || s == "ایک") return 1.00;
    if (s == "سوا پارہ" || s == "سوا" || s == "1.25") return 1.25;
    if (s == "ڈیڑھ پارہ" || s == "ڈیڑھ" || s == "1.5" || s == "1.50") return 1.50;
    if (s == "پونے دو پارے" || s == "پونے دو" || s == "1.75") return 1.75;
    if (s == "دو پارے" || s == "2" || s == "2.0" || s == "2.00" || s == "دو") return 2.00;
    if (s == "ڈھائی پارے" || s == "ڈھائی" || s == "2.5" || s == "2.50") return 2.50;
    if (s == "تین پارے" || s == "3" || s == "3.0" || s == "3.00" || s == "تین") return 3.00;
    if (s.contains("پارے") || s.contains("پارہ")) {
      return double.tryParse(s.replaceAll("پارے", "").replaceAll("پارہ", "").trim()) ?? 0.0;
    }
    return double.tryParse(s) ?? 0.0;
  }

  void _parseDetailsString(String str, List<Map<String, dynamic>> targetList) {
    targetList.clear();
    final clean = str.trim();
    if (clean.isEmpty || clean == 'نہیں سنائی' || clean == 'نہیں سنایا' || clean == '-') return;
    final parts = clean.split(RegExp(r'[،,]'));
    for (var p in parts) {
      final trimmed = p.trim();
      if (trimmed.isEmpty) continue;
      final lastParen = trimmed.lastIndexOf('(');
      final closeParen = trimmed.lastIndexOf(')');
      if (lastParen != -1 && closeParen > lastParen) {
        final para = trimmed.substring(0, lastParen).trim().replaceAll('پارہ', '').trim();
        final qtyStr = trimmed.substring(lastParen + 1, closeParen).trim();
        targetList.add({'para': para, 'amountVal': _parseUrduQty(qtyStr)});
      } else {
        final para = trimmed.replaceAll('پارہ', '').trim();
        if (para.isNotEmpty) {
          targetList.add({'para': para, 'amountVal': 1.0});
        }
      }
    }
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

  String? _mapGradeToUrdu(String? grade) {
    if (grade == null || grade.isEmpty) return null;
    final g = grade.toLowerCase();
    if (g == 'excellent') return 'ممتاز';
    if (g == 'good') return 'جید جدا';
    if (g == 'average') return 'جید';
    if (g == 'weak') return 'راسب';
    return grade;
  }

  Future<void> _checkExistingRecord() async {
    _existingRecordId = null;
    if (selectedStudent == null || selectedStudent == 'empty') return;
    String d = _dateController.text;
    if (d.isEmpty) return;
    String normalizedD = _normalizeDate(d);

    final db = await DBHelper().database;
    final existing = await db.query('hifz_record',
        where: 'student_id = ? AND (date = ? OR date = ?)',
        whereArgs: [selectedStudent, d, normalizedD]);

    if (existing.isNotEmpty) {
      final r = existing.last;
      _existingRecordId = r['id'] as int?;
      setState(() {
        bool sNot = (r['sabaq_grade'] == 'نہیں سنایا' || r['sabaq_para'] == 'نہیں سنایا');
        sabaqNotRecited = sNot;
        sabaqNotRecitedReasonController.text = sNot ? (r['sabaq_lines']?.toString() ?? '') : '';

        sabaqPara = r['sabaq_para']?.toString().isEmpty == true
            ? null
            : r['sabaq_para']?.toString();
        sabaqLinesController.text = sNot ? '' : (r['sabaq_lines']?.toString() ?? '');
        sabaqGrade = _mapGradeToUrdu(
            r['sabaq_grade']?.toString().isEmpty == true
                ? null
                : r['sabaq_grade']?.toString());

        bool sqNot = (r['sabqi_grade'] == 'نہیں سنائی' || r['sabqi_para'] == 'نہیں سنائی');
        sabqiNotRecited = sqNot;
        sabqiNotRecitedReasonController.text = sqNot ? (r['sabqi_qty']?.toString() ?? '') : '';

        String sqty = r['sabqi_qty']?.toString() ?? '';
        if (sqty.contains("جائزہ")) {
          isSabqiJaiza = true;
          _targetSabqiQty = 0.0;
        } else {
          isSabqiJaiza = false;
          _targetSabqiQty = _parseUrduQty(sqty);
        }
        _parseDetailsString(r['sabqi_para']?.toString() ?? '', sabqiDetails);
        sabqiGrade = _mapGradeToUrdu(
            r['sabqi_grade']?.toString().isEmpty == true
                ? null
                : r['sabqi_grade']?.toString());

        String slistener = r['sabqi_listener']?.toString() ?? '';
        if (slistener.contains("(جائزہ)")) {
          sabqiListenerJaiza = true;
          sabqiListenerController.text = slistener.replaceAll("(جائزہ)", "").trim();
        } else {
          sabqiListenerJaiza = false;
          sabqiListenerController.text = slistener;
        }

        bool mNot = (r['manzil_grade'] == 'نہیں سنائی' || r['manzil_details'] == 'نہیں سنائی');
        manzilNotRecited = mNot;
        manzilNotRecitedReasonController.text = mNot ? (r['manzil_total_qty']?.toString() ?? '') : '';

        String mqty = r['manzil_total_qty']?.toString() ?? '';
        if (mqty.contains("جائزہ")) {
          isManzilJaiza = true;
          _targetManzilQty = 0.0;
        } else {
          isManzilJaiza = false;
          _targetManzilQty = _parseUrduQty(mqty);
        }
        _parseDetailsString(
            r['manzil_details']?.toString() ?? '', manzilDetails);
        manzilGrade = _mapGradeToUrdu(
            r['manzil_grade']?.toString().isEmpty == true
                ? null
                : r['manzil_grade']?.toString());

        manzilListenerJaiza =
            r['manzil_listener']?.toString().contains('(جائزہ)') ?? false;

        sabaqTime = r['sabaq_time']?.toString().isEmpty == true
            ? null
            : r['sabaq_time']?.toString();
        sabqiTime = r['sabqi_time']?.toString().isEmpty == true
            ? null
            : r['sabqi_time']?.toString();
        manzilTime = r['manzil_time']?.toString().isEmpty == true
            ? null
            : r['manzil_time']?.toString();

        String mlistener = r['manzil_listener']?.toString() ?? '';
        if (mlistener.contains("(جائزہ)")) {
          manzilListenerJaiza = true;
          manzilListenerController.text = mlistener.replaceAll("(جائزہ)", "").trim();
        } else {
          manzilListenerJaiza = false;
          manzilListenerController.text = mlistener;
        }
      });
    } else {
      final attRows = await db.query('attendance',
          where: 'student_id = ? AND (date = ? OR date = ?)',
          whereArgs: [selectedStudent, d, normalizedD]);
      String attStatus = '';
      if (attRows.isNotEmpty) {
        String s1 = (attRows.first['status_1'] ?? attRows.first['status'] ?? '').toString().toUpperCase();
        String s2 = (attRows.first['status_2'] ?? '').toString().toUpperCase();
        if (s1 == 'A' || s2 == 'A' || s1.contains('غیر حاضر')) {
          attStatus = 'A';
        } else if (s1 == 'L' || s2 == 'L' || s1.contains('رخصت')) {
          attStatus = 'L';
        }
      }

      if (attStatus == 'A') {
        setState(() {
          sabaqNotRecited = sabqiNotRecited = manzilNotRecited = false;
          sabaqNotRecitedReasonController.clear();
          sabqiNotRecitedReasonController.clear();
          manzilNotRecitedReasonController.clear();
          sabaqPara = 'غیر حاضر';
          sabaqGrade = 'غیر حاضر';
          sabqiGrade = 'غیر حاضر';
          manzilGrade = 'غیر حاضر';
          sabaqLinesController.text = 'غیر حاضر';
        });
      } else if (attStatus == 'L') {
        setState(() {
          sabaqNotRecited = sabqiNotRecited = manzilNotRecited = false;
          sabaqNotRecitedReasonController.clear();
          sabqiNotRecitedReasonController.clear();
          manzilNotRecitedReasonController.clear();
          sabaqPara = 'رخصت';
          sabaqGrade = 'رخصت';
          sabqiGrade = 'رخصت';
          manzilGrade = 'رخصت';
          sabaqLinesController.text = 'رخصت';
        });
      } else {
        setState(() {
          sabaqNotRecited = sabqiNotRecited = manzilNotRecited = false;
          sabaqNotRecitedReasonController.clear();
          sabqiNotRecitedReasonController.clear();
          manzilNotRecitedReasonController.clear();
          sabaqPara = null;
          sabaqLinesController.clear();
          sabaqGrade = null;
          isSabqiJaiza = false;
          _targetSabqiQty = 0.0;
          sabqiDetails.clear();
          sabqiGrade = null;
          sabqiListenerController.clear();
          sabqiListenerJaiza = false;
          isManzilJaiza = false;
          _targetManzilQty = 0.0;
          manzilDetails.clear();
          manzilGrade = null;
          manzilListenerController.clear();
          manzilListenerJaiza = false;
        });
      }
    }
  }

  void _addSabqiPara() {
    if (selectedSabqiParaToAdd == null || selectedSabqiAmountToAdd == null) {
      return;
    }
    if (!isSabqiJaiza &&
        (_currentSabqiQty + _getRealQty(selectedSabqiAmountToAdd!)) >
            _targetSabqiQty) {
      _showError("مقدار ہدف سے زیادہ ہو جائے گی!");
      return;
    }
    setState(() {
      sabqiDetails.add({
        'para': selectedSabqiParaToAdd,
        'amountVal': selectedSabqiAmountToAdd
      });
      selectedSabqiParaToAdd = null;
      selectedSabqiAmountToAdd = null;
    });
  }

  void _addManzilPara() {
    if (selectedManzilParaToAdd == null || selectedManzilAmountToAdd == null) {
      return;
    }
    if (!isManzilJaiza &&
        (_currentManzilQty + _getRealQty(selectedManzilAmountToAdd!)) >
            _targetManzilQty) {
      _showError("مقدار ہدف سے زیادہ ہو جائے گی!");
      return;
    }
    setState(() {
      manzilDetails.add({
        'para': selectedManzilParaToAdd,
        'amountVal': selectedManzilAmountToAdd
      });
      selectedManzilParaToAdd = null;
      selectedManzilAmountToAdd = null;
    });
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg, style: const TextStyle(fontWeight: FontWeight.bold)),
      backgroundColor: Colors.red.shade800,
      duration: const Duration(seconds: 4),
    ));
  }

  String _studentLabel(Map<String, dynamic> student) {
    final name = student['name']?.toString() ?? '';
    final father = student['father_name']?.toString() ?? '-';
    final isMG = student['hifz_section'] == 'manzil_gardan';
    return isMG ? "$name [منزل/گردان] - ولدیت: $father" : "$name - ولدیت: $father";
  }

  String _selectedStudentLabel(bool isUrdu) {
    if (selectedStudent == null || selectedStudent == "empty") {
      return isUrdu ? "طالب علم منتخب کریں" : "Search student";
    }
    final selected =
        students.where((s) => s['id']?.toString() == selectedStudent);
    if (selected.isEmpty) {
      return isUrdu ? "طالب علم منتخب کریں" : "Search student";
    }
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
                        labelText: isUrdu
                            ? 'نام، ولدیت یا رول نمبر سے تلاش کریں'
                            : 'Search by name, father or roll no',
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
                            ]
                                .map((v) => v?.toString().toLowerCase() ?? '')
                                .join(' ');
                            return haystack.contains(q);
                          }).toList();
                        });
                      },
                    ),
                    const SizedBox(height: 12),
                    SizedBox(
                      height: 360,
                      child: filtered.isEmpty
                          ? Center(
                              child: Text(isUrdu
                                  ? 'کوئی طالب علم نہیں ملا'
                                  : 'No student found'))
                          : ListView.separated(
                              itemCount: filtered.length,
                              separatorBuilder: (_, __) =>
                                  const Divider(height: 1),
                              itemBuilder: (context, index) {
                                final student = filtered[index];
                                return ListTile(
                                  leading: const Icon(Icons.person),
                                  title: Text(_studentLabel(student)),
                                  subtitle: Text(
                                      student['class_name']?.toString() ??
                                          student['class']?.toString() ??
                                          ''),
                                  onTap: () => Navigator.pop(
                                      context, student['id']?.toString()),
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
      _showError(
          isUrduGlobal.value ? 'طالب علم منتخب کریں!' : 'Select Student!');
      return;
    }

    if (!sabaqNotRecited && (sabaqPara == null || sabaqPara!.isEmpty) && (sabaqGrade != null && sabaqGrade!.isNotEmpty)) {
      _showError(isUrduGlobal.value ? 'پارہ منتخب کریں!' : 'Select Para!');
      return;
    }

    // Auto-add pending Sabqi para if selected in dropdown but user forgot to click Add
    if (!sabqiNotRecited && selectedSabqiParaToAdd != null && selectedSabqiAmountToAdd != null) {
      if (isSabqiJaiza || (_currentSabqiQty + _getRealQty(selectedSabqiAmountToAdd!)) <= _targetSabqiQty) {
        sabqiDetails.add({
          'para': selectedSabqiParaToAdd,
          'amountVal': selectedSabqiAmountToAdd,
        });
        selectedSabqiParaToAdd = null;
        selectedSabqiAmountToAdd = null;
      }
    }

    // Auto-add pending Manzil para if selected in dropdown but user forgot to click Add
    if (!manzilNotRecited && selectedManzilParaToAdd != null && selectedManzilAmountToAdd != null) {
      if (isManzilJaiza || (_currentManzilQty + _getRealQty(selectedManzilAmountToAdd!)) <= _targetManzilQty) {
        manzilDetails.add({
          'para': selectedManzilParaToAdd,
          'amountVal': selectedManzilAmountToAdd,
        });
        selectedManzilParaToAdd = null;
        selectedManzilAmountToAdd = null;
      }
    }

    if (!sabqiNotRecited) {
      if (!isSabqiJaiza &&
          _targetSabqiQty > 0 &&
          _currentSabqiQty < _targetSabqiQty) {
        _showError(isUrduGlobal.value
            ? 'سبقی کا اندراج نامکمل ہے!'
            : 'Sabqi entry incomplete!');
        return;
      }

      if (isSabqiJaiza && sabqiDetails.isEmpty) {
        _showError(isUrduGlobal.value
            ? 'کم از کم ایک سبقی جائزہ پارہ شامل کریں'
            : 'Add at least one Sabqi review para.');
        return;
      }
    }

    if (!manzilNotRecited) {
      if (!isManzilJaiza &&
          _targetManzilQty > 0 &&
          _currentManzilQty < _targetManzilQty) {
        _showError(isUrduGlobal.value
            ? 'منزل کا اندراج نامکمل ہے!'
            : 'Manzil entry incomplete!');
        return;
      }

      if (isManzilJaiza && manzilDetails.isEmpty) {
        _showError(isUrduGlobal.value
            ? 'کم از کم ایک منزل جائزہ پارہ شامل کریں'
            : 'Add at least one Manzil review para.');
        return;
      }
    }

    String sabqiDetailsStr = sabqiNotRecited
        ? 'نہیں سنائی'
        : sabqiDetails
            .map((d) => "${d['para']} (${getUrduQty(d['amountVal'])})")
            .join('، ');
    String finalSabqiQty = sabqiNotRecited
        ? (sabqiNotRecitedReasonController.text.trim().isNotEmpty
            ? sabqiNotRecitedReasonController.text.trim()
            : 'بغیر وجہ')
        : (isSabqiJaiza
            ? "جائزہ (${sabqiDetails.length} پارے)"
            : getUrduQty(_targetSabqiQty));

    String manzilDetailsStr = manzilNotRecited
        ? 'نہیں سنائی'
        : manzilDetails
            .map((d) => "${d['para']} (${getUrduQty(d['amountVal'])})")
            .join('، ');
    String finalManzilQty = manzilNotRecited
        ? (manzilNotRecitedReasonController.text.trim().isNotEmpty
            ? manzilNotRecitedReasonController.text.trim()
            : 'بغیر وجہ')
        : (isManzilJaiza
            ? "جائزہ (${manzilDetails.length} پارے)"
            : getUrduQty(_targetManzilQty));

    try {
      final db = await DBHelper().database;
      
      Map<String, dynamic> data = {
        'date': _dateController.text,
        'student_id': selectedStudent,
        'sabaq_para': sabaqNotRecited ? 'نہیں سنایا' : sabaqPara,
        'sabaq_lines': sabaqNotRecited
            ? (sabaqNotRecitedReasonController.text.trim().isNotEmpty
                ? sabaqNotRecitedReasonController.text.trim()
                : 'بغیر وجہ')
            : sabaqLinesController.text,
        'sabaq_grade': sabaqNotRecited ? 'نہیں سنایا' : sabaqGrade,
        'sabqi_para': sabqiDetailsStr,
        'sabqi_qty': sabqiNotRecited ? finalSabqiQty : ((_targetSabqiQty > 0 || isSabqiJaiza) ? finalSabqiQty : ''),
        'sabqi_grade': sabqiNotRecited ? 'نہیں سنائی' : sabqiGrade,
        'sabqi_listener': sabqiNotRecited ? '' : (sabqiListenerController.text +
            (sabqiListenerJaiza ? " (جائزہ)" : "")),
        'manzil_total_qty': manzilNotRecited ? finalManzilQty : ((_targetManzilQty > 0 || isManzilJaiza) ? finalManzilQty : ''),
        'manzil_details': manzilDetailsStr,
        'manzil_grade': manzilNotRecited ? 'نہیں سنائی' : manzilGrade,
        'manzil_listener': manzilNotRecited ? '' : (manzilListenerController.text +
            (manzilListenerJaiza ? " (جائزہ)" : "")),
        'sabaq_time': sabaqTime ?? '',
        'sabqi_time': sabqiTime ?? '',
        'manzil_time': manzilTime ?? '',
      };

      if (_existingRecordId != null) {
        data['is_synced'] = 0;
        await db.update('hifz_record', data, where: 'id = ?', whereArgs: [_existingRecordId]);
      } else {
        await DBHelper().insertHifz(data);
        await _checkExistingRecord(); // Update the existing record id
      }
    } catch (e) {
      if (mounted) {
        _showError(isUrduGlobal.value
            ? 'ریکارڈ محفوظ کرنے میں خرابی: $e'
            : 'Error saving record: $e');
      }
      return;
    }

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrduGlobal.value
            ? 'تعلیمی اندراج کامیابی سے محفوظ / اپڈیٹ ہو گیا!'
            : 'Record saved / updated!'),
        backgroundColor: Colors.green));

    try {
      await SyncService.runTwoWaySync();
    } catch (_) {}

    if (!mounted) return;
    setState(() {
      sabaqNotRecited = sabqiNotRecited = manzilNotRecited = false;
      sabaqNotRecitedReasonController.clear();
      sabqiNotRecitedReasonController.clear();
      manzilNotRecitedReasonController.clear();
      sabaqPara = sabaqGrade = sabqiGrade = manzilGrade = null;
      _targetSabqiQty = _targetManzilQty = 0.0;
      isSabqiJaiza = isManzilJaiza = false;
      sabqiListenerJaiza = manzilListenerJaiza = false;
      sabaqLinesController.clear();
      sabqiListenerController.clear();
      manzilListenerController.clear();
      sabqiDetails.clear();
      manzilDetails.clear();
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
                title: Text(isUrdu ? 'روزانہ تعلیمی اندراج (حفظ)' : 'Hifz Daily Entry',
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
                                  isUrdu ? "سنک ناکام رہا" : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
                  ),
                ],
              ),
              endDrawer: const AppDrawer(),
              body: LayoutBuilder(
                builder: (context, constraints) {
                  final bool isWide = constraints.maxWidth >= 900;
                  return Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 1200),
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.all(12.0),
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
                                                size: 16),
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
                                                          ? "طلبہ دستیاب نہیں"
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
                            if (selectedStudent != null &&
                                students.any((s) =>
                                    s['id']?.toString() == selectedStudent &&
                                    s['hifz_section'] == 'manzil_gardan')) ...[
                              const SizedBox(height: 12),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 14, vertical: 10),
                                decoration: BoxDecoration(
                                  color: Colors.amber.shade50,
                                  borderRadius: BorderRadius.circular(10),
                                  border: Border.all(
                                      color: Colors.amber.shade400, width: 1.2),
                                ),
                                child: Row(
                                  children: [
                                    Icon(Icons.info_outline,
                                        size: 22, color: Colors.amber.shade900),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Text(
                                        isUrdu
                                            ? "یہ طالب علم منزل/گردان (پارٹ ٹائم) سیکشن میں ہے۔ صرف منزل/گردان سنانا لازمی ہے، نیا سبق و سبقی اختیاری ہیں اور ناغہ شمار نہیں ہوں گے۔"
                                            : "This student is in Manzil/Gardan (Part-time) section. Only Manzil is mandatory; Sabaq & Sabqi are optional and won't count as naagha.",
                                        style: TextStyle(
                                          fontSize: 12.5,
                                          fontWeight: FontWeight.bold,
                                          color: Colors.amber.shade900,
                                          height: 1.3,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                            const SizedBox(height: 15),
                            if (isWide)
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Expanded(child: _buildSabaqSection(isUrdu)),
                                  const SizedBox(width: 12),
                                  Expanded(child: _buildSabqiSection(isUrdu)),
                                  const SizedBox(width: 12),
                                  Expanded(child: _buildManzilSection(isUrdu)),
                                ],
                              )
                            else ...[
                              _buildSabaqSection(isUrdu),
                              const SizedBox(height: 15),
                              _buildSabqiSection(isUrdu),
                              const SizedBox(height: 15),
                              _buildManzilSection(isUrdu),
                            ],
                            const SizedBox(height: 25),
                            Center(
                              child: SizedBox(
                                width: isWide ? 380 : double.infinity,
                                child: ElevatedButton(
                                    style: ElevatedButton.styleFrom(
                                        backgroundColor: primaryGreen,
                                        elevation: 3,
                                        shape: RoundedRectangleBorder(
                                            borderRadius: BorderRadius.circular(10)),
                                        padding: const EdgeInsets.symmetric(vertical: 15)),
                                    onPressed: _saveRecord,
                                    child: Text(
                                        isUrdu ? 'محفوظ کریں / اپڈیٹ' : 'Save / Update',
                                        style: const TextStyle(
                                            fontSize: 18,
                                            fontWeight: FontWeight.bold,
                                            color: Colors.white))),
                              ),
                            ),
                            const SizedBox(height: 20),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildSabaqSection(bool isUrdu) {
    return _buildSectionCard(
        isUrdu ? "سبق" : "Sabaq", primaryGreen, [
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(isUrdu ? "سبق نہیں سنایا" : "Sabaq Not Recited",
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                color: Colors.red.shade800)),
        value: sabaqNotRecited,
        activeColor: Colors.red.shade800,
        onChanged: (val) => setState(() => sabaqNotRecited = val ?? false),
        controlAffinity: ListTileControlAffinity.leading,
      ),
      if (sabaqNotRecited) ...[
        const SizedBox(height: 5),
        TextField(
          controller: sabaqNotRecitedReasonController,
          decoration: InputDecoration(
            labelText: isUrdu ? "نہ سنانے کی مختصر وجہ لکھیں" : "Reason for not reciting",
            hintText: isUrdu ? "مثلاً: یاد نہیں تھا، مصروفیت وغیرہ" : "e.g. not prepared",
            prefixIcon: const Icon(Icons.info_outline, color: Colors.red),
            border: const OutlineInputBorder(),
          ),
        ),
      ] else ...[
        Row(
          children: [
            Expanded(
              child: _buildDropdown(
                  isUrdu ? "پارہ نمبر" : "Para No",
                  paraNumbers,
                  sabaqPara,
                  (val) => setState(() => sabaqPara = val)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextField(
                controller: sabaqLinesController,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(
                    labelText: isUrdu ? "سطریں" : "Lines",
                    border: const OutlineInputBorder()),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _buildDropdown(
                  isUrdu ? "کیفیت" : "Grade",
                  grades,
                  sabaqGrade,
                  (val) => setState(() {
                        sabaqGrade = val;
                        sabaqTime ??= intl.DateFormat('hh:mm a')
                            .format(DateTime.now());
                      })),
            ),
            const SizedBox(width: 10),
            _buildTimePicker(sabaqTime,
                (t) => setState(() => sabaqTime = t)),
          ],
        ),
      ],
    ]);
  }

  Widget _buildSabqiSection(bool isUrdu) {
    return _buildSectionCard(
        isUrdu ? "سبقی (پچھلا سبق)" : "Sabqi", Colors.blue.shade700, [
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(isUrdu ? "سبقی نہیں سنائی" : "Sabqi Not Recited",
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                color: Colors.red.shade800)),
        value: sabqiNotRecited,
        activeColor: Colors.red.shade800,
        onChanged: (val) => setState(() => sabqiNotRecited = val ?? false),
        controlAffinity: ListTileControlAffinity.leading,
      ),
      if (sabqiNotRecited) ...[
        const SizedBox(height: 5),
        TextField(
          controller: sabqiNotRecitedReasonController,
          decoration: InputDecoration(
            labelText: isUrdu ? "نہ سنانے کی مختصر وجہ لکھیں" : "Reason for not reciting",
            hintText: isUrdu ? "مثلاً: یاد نہیں تھا، طبیعت ناساز وغیرہ" : "e.g. unwell",
            prefixIcon: const Icon(Icons.info_outline, color: Colors.red),
            border: const OutlineInputBorder(),
          ),
        ),
      ] else ...[
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Expanded(
                flex: 2,
                child: _buildDoubleDropdown(
                    isUrdu ? "ہدف سبقی" : "Target Qty",
                    sabqiTargetOptions,
                    _targetSabqiQty,
                    (val) => setState(() {
                          _targetSabqiQty = val!;
                          sabqiDetails.clear();
                        }),
                    textColor: Colors.red)),
            Expanded(
              flex: 1,
              child: CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(isUrdu ? "جائزہ" : "Jaiza",
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: Colors.blue.shade900)),
                value: isSabqiJaiza,
                activeColor: Colors.blue.shade900,
                onChanged: (val) =>
                    setState(() => isSabqiJaiza = val!),
                controlAffinity:
                    ListTileControlAffinity.leading,
              ),
            )
          ],
        ),
        if (_targetSabqiQty > 0 || isSabqiJaiza) ...[
          const SizedBox(height: 15),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
                color: Colors.blue.shade50,
                borderRadius: BorderRadius.circular(8)),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                        child: _buildDropdown(
                            isUrdu ? "پارہ نمبر" : "Para No",
                            paraNumbers,
                            selectedSabqiParaToAdd,
                            (val) => setState(() =>
                                selectedSabqiParaToAdd = val))),
                    const SizedBox(width: 10),
                    Expanded(
                        child: _buildDoubleDropdown(
                            isUrdu ? "مقدار" : "Amount",
                            amountOptions,
                            selectedSabqiAmountToAdd,
                            (val) => setState(() =>
                                selectedSabqiAmountToAdd =
                                    val))),
                  ],
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.blue.shade700),
                    onPressed: _addSabqiPara,
                    icon: const Icon(Icons.add,
                        color: Colors.white, size: 18),
                    label: Text(isUrdu ? "شامل کریں" : "Add",
                        style: const TextStyle(
                            color: Colors.white)),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          Column(
              children: sabqiDetails
                  .asMap()
                  .entries
                  .map((e) => Card(
                      elevation: 0,
                      color: Colors.blue.shade50,
                      child: ListTile(
                          dense: true,
                          title: Text(
                              "پارہ ${e.value['para']}"),
                          subtitle: Text(getUrduQty(
                              e.value['amountVal'])),
                          trailing: IconButton(
                              icon: const Icon(Icons.delete,
                                  color: Colors.red),
                              onPressed: () => setState(() =>
                                  sabqiDetails
                                      .removeAt(e.key))))))
                  .toList()),
          const SizedBox(height: 10),
        ],
        if (_targetSabqiQty > 0 || isSabqiJaiza)
          Row(children: [
            Expanded(
                flex: 3,
                child: _buildDropdown(
                    isUrdu ? "کیفیت" : "Grade",
                    grades,
                    sabqiGrade,
                    (val) => setState(() {
                          sabqiGrade = val;
                          sabqiTime ??=
                              intl.DateFormat('hh:mm a')
                                  .format(DateTime.now());
                        }))),
            const SizedBox(width: 8),
            _buildTimePicker(sabqiTime,
                (t) => setState(() => sabqiTime = t)),
            if (!isSabqiJaiza) ...[
              const SizedBox(width: 10),
              Expanded(
                  flex: 4,
                  child: TextField(
                      controller: sabqiListenerController,
                      decoration: InputDecoration(
                          labelText:
                              isUrdu ? "سامع کا نام" : "Listener",
                          border: const OutlineInputBorder()))),
              Expanded(
                  flex: 3,
                  child: Row(
                    mainAxisAlignment:
                        MainAxisAlignment.end,
                    children: [
                      Expanded(
                          child: Text(isUrdu ? "جائزہ" : "Jaiza",
                              style: const TextStyle(
                                  fontSize: 11,
                                  fontWeight:
                                      FontWeight.bold),
                              textAlign: TextAlign.right,
                              overflow:
                                  TextOverflow.ellipsis)),
                      SizedBox(
                          width: 24,
                          height: 24,
                          child: Checkbox(
                              value: sabqiListenerJaiza,
                              activeColor: Colors.blue.shade900,
                              onChanged: (val) => setState(() =>
                                  sabqiListenerJaiza = val!))),
                    ],
                  ))
            ]
          ]),
      ],
    ]);
  }

  Widget _buildManzilSection(bool isUrdu) {
    return _buildSectionCard(
        isUrdu ? "منزل" : "Manzil",
        Colors.amber.shade700,
        [
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(isUrdu ? "منزل نہیں سنائی" : "Manzil Not Recited",
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: Colors.red.shade800)),
            value: manzilNotRecited,
            activeColor: Colors.red.shade800,
            onChanged: (val) => setState(() => manzilNotRecited = val ?? false),
            controlAffinity: ListTileControlAffinity.leading,
          ),
          if (manzilNotRecited) ...[
            const SizedBox(height: 5),
            TextField(
              controller: manzilNotRecitedReasonController,
              decoration: InputDecoration(
                labelText: isUrdu ? "نہ سنانے کی مختصر وجہ لکھیں" : "Reason for not reciting",
                hintText: isUrdu ? "مثلاً: یاد نہیں تھا، وغیرہ" : "e.g. not prepared",
                prefixIcon: const Icon(Icons.info_outline, color: Colors.red),
                border: const OutlineInputBorder(),
              ),
            ),
          ] else ...[
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                    flex: 2,
                    child: _buildDoubleDropdown(
                        isUrdu ? "ہدف منزل" : "Target Qty",
                        manzilTargetOptions,
                        _targetManzilQty,
                        (val) => setState(() {
                              _targetManzilQty = val!;
                              manzilDetails.clear();
                            }),
                        textColor: Colors.red)),
                Expanded(
                    flex: 1,
                    child: CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(isUrdu ? "جائزہ" : "Jaiza",
                            style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: Colors.amber.shade900)),
                        value: isManzilJaiza,
                        activeColor: Colors.amber.shade900,
                        onChanged: (val) => setState(
                            () => isManzilJaiza = val!),
                        controlAffinity:
                            ListTileControlAffinity.leading))
              ],
            ),
            if (_targetManzilQty > 0 || isManzilJaiza) ...[
              const SizedBox(height: 15),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    borderRadius: BorderRadius.circular(8)),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                            child: _buildDropdown(
                                isUrdu ? "پارہ نمبر" : "Para No",
                                paraNumbers,
                                selectedManzilParaToAdd,
                                (val) => setState(() =>
                                    selectedManzilParaToAdd =
                                        val))),
                        const SizedBox(width: 10),
                        Expanded(
                            child: _buildDoubleDropdown(
                                isUrdu ? "مقدار" : "Amount",
                                amountOptions,
                                selectedManzilAmountToAdd,
                                (val) => setState(() =>
                                    selectedManzilAmountToAdd =
                                        val))),
                      ],
                    ),
                    const SizedBox(height: 10),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                            backgroundColor:
                                Colors.amber.shade800),
                        onPressed: _addManzilPara,
                        icon: const Icon(Icons.add,
                            color: Colors.white, size: 18),
                        label: Text(
                            isUrdu ? "شامل کریں" : "Add",
                            style: const TextStyle(
                                color: Colors.white)),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              Column(
                  children: manzilDetails
                      .asMap()
                      .entries
                      .map((e) => Card(
                          elevation: 0,
                          color: Colors.amber.shade50,
                          child: ListTile(
                              dense: true,
                              title: Text(
                                  "پارہ ${e.value['para']}"),
                              subtitle: Text(getUrduQty(
                                  e.value['amountVal'])),
                              trailing: IconButton(
                                  icon: const Icon(Icons.delete,
                                      color: Colors.red),
                                  onPressed: () => setState(
                                      () => manzilDetails
                                          .removeAt(e.key))))))
                      .toList()),
              const SizedBox(height: 10),
            ],
            if (_targetManzilQty > 0 || isManzilJaiza)
              Row(children: [
                Expanded(
                    flex: 3,
                    child: _buildDropdown(
                        isUrdu ? "کیفیت" : "Grade",
                        grades,
                        manzilGrade,
                        (val) => setState(() {
                              manzilGrade = val;
                              manzilTime ??=
                                  intl.DateFormat('hh:mm a')
                                      .format(DateTime.now());
                            }))),
                const SizedBox(width: 8),
                _buildTimePicker(manzilTime,
                    (t) => setState(() => manzilTime = t)),
                if (!isManzilJaiza) ...[
                  const SizedBox(width: 10),
                  Expanded(
                      flex: 4,
                      child: TextField(
                          controller: manzilListenerController,
                          decoration: InputDecoration(
                              labelText: isUrdu
                                  ? "سامع کا نام"
                                  : "Listener",
                              border:
                                  const OutlineInputBorder()))),
                  Expanded(
                      flex: 3,
                      child: Row(
                        mainAxisAlignment:
                            MainAxisAlignment.end,
                        children: [
                          Expanded(
                              child: Text(isUrdu ? "جائزہ" : "Jaiza",
                                  style: const TextStyle(
                                      fontSize: 11,
                                      fontWeight:
                                          FontWeight.bold),
                                  textAlign: TextAlign.right,
                                  overflow:
                                      TextOverflow.ellipsis)),
                          SizedBox(
                              width: 24,
                              height: 24,
                              child: Checkbox(
                                  value: manzilListenerJaiza,
                                  activeColor:
                                      Colors.amber.shade900,
                                  onChanged: (val) => setState(
                                      () =>
                                          manzilListenerJaiza =
                                              val!))),
                        ],
                      ))
                ]
              ]),
          ],
        ]);
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

  Widget _buildDoubleDropdown(String label, List<double> items, double? value,
      Function(double?) onChanged,
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
              child: DropdownButton<double>(
                  isExpanded: true,
                  value: value,
                  dropdownColor: Colors.white,
                  style: const TextStyle(color: Colors.black87, fontSize: 13),
                  hint: Text("--", style: TextStyle(color: textColor)),
                  icon: Icon(Icons.keyboard_arrow_down, color: textColor),
                  items: items
                      .map((i) => DropdownMenuItem<double>(
                          value: i,
                          child: Text(getUrduQty(i),
                              style: const TextStyle(
                                  color: Colors.black87,
                                  fontSize: 13,
                                  fontWeight: FontWeight.bold))))
                      .toList(),
                  onChanged: onChanged)),
        ),
      ],
    );
  }
}
