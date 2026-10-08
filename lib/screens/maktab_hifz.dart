// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'package:flutter/material.dart';

import '../widgets/madrasa_card.dart';

import '../widgets/madrasa_inputs.dart';

import 'dart:ui' as ui;

import '../db_helper.dart';

import '../utils/globals.dart';

import 'package:intl/intl.dart' as intl;

import '../widgets/app_drawer.dart';

import '../utils/sync_service.dart';

import '../utils/sync_ui_helper.dart';

import 'dashboard.dart';



class MaktabHifzPage extends StatefulWidget {

  final String? className;

  const MaktabHifzPage({super.key, this.className});



  @override

  _MaktabHifzPageState createState() => _MaktabHifzPageState();

}



class _MaktabHifzPageState extends State<MaktabHifzPage> {

  final Color bgColor = const Color(0xFFF1EFE7);

  final Color primaryGreen = const Color(0xFF284D3D);



  final _dateController = TextEditingController();

  final _studentSearchController = TextEditingController();

  String? selectedStudent;

  List<Map<String, dynamic>> maktabstudents = [];

  int? _existingRecordId;



  // --- 1.  (Sabaq) ---

  String? sabaqPara, sabaqGrade;

  final sabaqLinesController = TextEditingController();



  // --- 2.  (Sabqi)   ---

  double _targetSabqiQty = 0.0;

  List<Map<String, dynamic>> sabqiDetails = [];

  String? selectedSabqiParaToAdd;

  double? selectedSabqiAmountToAdd;

  bool isSabqiJaiza = false;

  String? sabqiGrade;

  final sabqiListenerController = TextEditingController();



  // --- 3.  (Manzil)   ---

  double _targetManzilQty = 0.0;

  List<Map<String, dynamic>> manzilDetails = [];

  String? selectedManzilParaToAdd;

  double? selectedManzilAmountToAdd;

  bool isManzilJaiza = false;

  String? manzilGrade;

  final manzilListenerController = TextEditingController();



  bool sabqiListenerJaiza = false;

  bool manzilListenerJaiza = false;



  String? sabaqTime;

  String? sabqiTime;

  String? manzilTime;



  final List<String> paraNumbers = [

    ' ',

    ' ',

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

    sabqiListenerController.dispose();

    manzilListenerController.dispose();

    super.dispose();

  }



  String _formatDate(DateTime date) {

    return "${date.day}/${date.month}/${date.year}";

  }



  void _loadStudents() async {

    final data = await DBHelper().getMaktabStudentsForUser();

    setState(() {

      maktabstudents = data.where((s) {

        bool isActive = s['status'] == 'Active' &&

            (s['is_deleted'] == 0 || s['is_deleted'] == null);

        String cName =

            (s['class_name']?.toString() ?? s['class']?.toString() ?? '')

                .toLowerCase();

        bool isHifz = cName.contains('حفظ') ||
            cName.contains('hifz');

        return isActive && isHifz;

      }).toList();

    });

  }



  String getUrduQty(double qty) {

    if (qty == 0.0) return " ";

    if (qty == 0.25) return " ";

    if (qty == 0.50) return " ";

    if (qty == 0.51) return " ";

    if (qty == 0.52) return " ";

    if (qty == 0.75) return " ";

    if (qty == 1.00) return " ";

    if (qty == 1.25) return " ";

    if (qty == 1.50) return " ";

    if (qty == 1.75) return "  ";

    if (qty == 2.00) return " ";

    if (qty == 2.50) return " ";

    if (qty == 3.00) return " ";

    return "$qty ";

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

    if (g == 'excellent') return '';

    if (g == 'good') return ''; // Fallback mapping based on map_grade logic

    if (g == 'average') return ' ';

    if (g == 'weak') return '';

    return grade;

  }



  Future<void> _checkExistingRecord() async {

    _existingRecordId = null;

    if (selectedStudent == null || selectedStudent == 'empty') return;

    String d = _dateController.text;

    if (d.isEmpty) return;

    String normalizedD = _normalizeDate(d);



    final db = await DBHelper().database;

    final existing = await db.query('maktab_hifz_record',

        where: 'student_id = ? AND (date = ? OR date = ?)',

        whereArgs: [selectedStudent, d, normalizedD]);



    if (existing.isNotEmpty) {

      final r = existing.last;

      _existingRecordId = r['id'] as int?;

      setState(() {

        sabaqPara = r['sabaq_para']?.toString().isEmpty == true

            ? null

            : r['sabaq_para']?.toString();

        sabaqLinesController.text = r['sabaq_lines']?.toString() ?? '';

        sabaqGrade = _mapGradeToUrdu(

            r['sabaq_grade']?.toString().isEmpty == true

                ? null

                : r['sabaq_grade']?.toString());



        String sqty = r['sabqi_qty']?.toString() ?? '';

        if (sqty.contains(" ")) {

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

        if (slistener.contains(" ()")) {

          sabqiListenerJaiza = true;

          sabqiListenerController.text = slistener.replaceAll(" ()", "");

        } else {

          sabqiListenerJaiza = false;

          sabqiListenerController.text = slistener;

        }



        String mqty = r['manzil_total_qty']?.toString() ?? '';

        if (mqty.contains(" ")) {

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

            r['manzil_listener']?.toString().contains('()') ?? false;



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

        if (mlistener.contains(" ()")) {

          manzilListenerJaiza = true;

          manzilListenerController.text = mlistener.replaceAll(" ()", "");

        } else {

          manzilListenerJaiza = false;

          manzilListenerController.text = mlistener;

        }

      });

    } else {

      setState(() {

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



  void _addSabqiPara() {

    if (selectedSabqiParaToAdd == null || selectedSabqiAmountToAdd == null) {

      return;

    }

    if (!isSabqiJaiza &&

        (_currentSabqiQty + _getRealQty(selectedSabqiAmountToAdd!)) >

            _targetSabqiQty) {

      _showError("        !");

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

      _showError("        !");

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

    return "$name - : $father";

  }



  String _selectedStudentLabel(bool isUrdu) {

    if (selectedStudent == null || selectedStudent == "empty") {

      return isUrdu ? "   " : "Search student";

    }

    final selected =

        maktabstudents.where((s) => s['id']?.toString() == selectedStudent);

    if (selected.isEmpty) {

      return isUrdu ? "   " : "Search student";

    }

    return _studentLabel(selected.first);

  }



  Future<void> _showStudentSearch(bool isUrdu) async {

    _studentSearchController.clear();

    var filtered = List<Map<String, dynamic>>.from(maktabstudents);

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

                    MadrasaTextField(

                      controller: _studentSearchController,

                      autofocus: true,

                      decoration: InputDecoration(

                        labelText: isUrdu

                            ? '      '

                            : 'Search by name, father or roll no',

                        prefixIcon: const Icon(Icons.search),

                        border: const OutlineInputBorder(),

                      ),

                      onChanged: (value) {

                        final q = value.trim().toLowerCase();

                        setSheetState(() {

                          filtered = maktabstudents.where((s) {

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

                                  ? '    '

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

    // Auto-add pending Sabqi para if selected in dropdown but user forgot to click Add
    if (selectedSabqiParaToAdd != null && selectedSabqiAmountToAdd != null) {
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
    if (selectedManzilParaToAdd != null && selectedManzilAmountToAdd != null) {
      if (isManzilJaiza || (_currentManzilQty + _getRealQty(selectedManzilAmountToAdd!)) <= _targetManzilQty) {
        manzilDetails.add({
          'para': selectedManzilParaToAdd,
          'amountVal': selectedManzilAmountToAdd,
        });
        selectedManzilParaToAdd = null;
        selectedManzilAmountToAdd = null;
      }
    }

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

          ? '          '

          : 'Add at least one Sabqi review para.');

      return;

    }



    if (!isManzilJaiza &&

        _targetManzilQty > 0 &&

        _currentManzilQty < _targetManzilQty) {

      _showError(isUrduGlobal.value

          ? '    !    '

          : 'Manzil entry incomplete!');

      return;

    }



    if (isManzilJaiza && manzilDetails.isEmpty) {

      _showError(isUrduGlobal.value

          ? '          '

          : 'Add at least one Manzil review para.');

      return;

    }



    String sabqiDetailsStr = sabqiDetails

        .map((d) => "${d['para']} (${getUrduQty(d['amountVal'])})")

        .join(' ');

    String finalSabqiQty = isSabqiJaiza

        ? "  (${sabqiDetails.length} )"

        : getUrduQty(_targetSabqiQty);



    String manzilDetailsStr = manzilDetails

        .map((d) => "${d['para']} (${getUrduQty(d['amountVal'])})")

        .join(' ');

    String finalManzilQty = isManzilJaiza

        ? "  (${manzilDetails.length} )"

        : getUrduQty(_targetManzilQty);



    try {

      final db = await DBHelper().database;

      

      Map<String, dynamic> data = {

        'date': _dateController.text,

        'student_id': selectedStudent,

        'sabaq_para': sabaqPara,

        'sabaq_lines': sabaqLinesController.text,

        'sabaq_grade': sabaqGrade,

        'sabqi_para': sabqiDetailsStr,

        'sabqi_qty': (_targetSabqiQty > 0 || isSabqiJaiza) ? finalSabqiQty : '',

        'sabqi_grade': sabqiGrade,

        'sabqi_listener': sabqiListenerController.text +

            (sabqiListenerJaiza ? " ()" : ""),

        'manzil_total_qty':

            (_targetManzilQty > 0 || isManzilJaiza) ? finalManzilQty : '',

        'manzil_details': manzilDetailsStr,

        'manzil_grade': manzilGrade,

        'manzil_listener': manzilListenerController.text +

            (manzilListenerJaiza ? " ()" : ""),

        'sabaq_time': sabaqTime ?? '',

        'sabqi_time': sabqiTime ?? '',

        'manzil_time': manzilTime ?? '',

      };



      data['class_name'] = widget.className ?? '';
      if (_existingRecordId != null) {
        data['is_synced'] = 0;
        await db.update('maktab_hifz_record', data, where: 'id = ?', whereArgs: [_existingRecordId]);
      } else {
        data['is_synced'] = 0;
        data['is_deleted'] = 0;
        data['system_type'] = 'Masjid';
        await db.insert('maktab_hifz_record', data);
        await _checkExistingRecord(); // Update the existing record id
      }

    } catch (e) {

      if (mounted) {

        _showError(isUrduGlobal.value

            ? '    : $e'

            : 'Error saving record: $e');

      }

      return;

    }



    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(

        content: Text(isUrduGlobal.value

            ? '    /   !'

            : 'Record saved / updated!'),

        backgroundColor: Colors.green));



    SyncService.runTwoWaySync().catchError((_) {});



    if (!mounted) return;

    // Removed `selectedStudent = null` so the form stays populated and flexible.

    setState(() {

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



    for (var s in maktabstudents) {

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

                title: Text(isUrdu ? '   ' : 'Hifz Daily Entry',

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

                              ? "    ..."

                              : "Syncing data...")));

                      try {

                        await SyncUIHelper.performManualSync(context);

                        if (mounted) {

                          _loadStudents();

                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(

                              content: Text(isUrdu

                                  ? "   !"

                                  : "Sync complete!"),

                              backgroundColor: Colors.green));

                        }

                      } catch (e) {

                        if (mounted) {

                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(

                              content: Text(

                                  isUrdu ? "   " : "Sync failed"),

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

                      MadrasaCard(

                        elevation: 3,

                        child: Padding(

                          padding: const EdgeInsets.all(12.0),

                          child: Row(

                            children: [

                              Expanded(

                                flex: 1,

                                child: MadrasaTextField(

                                  controller: _dateController,

                                  readOnly: true,

                                  decoration: InputDecoration(

                                      labelText: isUrdu ? '' : 'Date',

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

                                  onTap: maktabstudents.isEmpty

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

                                            maktabstudents.isEmpty

                                                ? (isUrdu

                                                    ? "    "

                                                    : "No maktabstudents found")

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

                          isUrdu ? "" : "Sabaq", primaryGreen, [

                        Row(

                          children: [

                            Expanded(

                              child: _buildDropdown(

                                  isUrdu ? " " : "Para No",

                                  paraNumbers,

                                  sabaqPara,

                                  (val) => setState(() => sabaqPara = val)),

                            ),

                            const SizedBox(width: 10),

                            Expanded(

                              child: MadrasaTextField(

                                controller: sabaqLinesController,

                                keyboardType: TextInputType.number,

                                decoration: InputDecoration(

                                    labelText: isUrdu ? "" : "Lines",

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

                                  isUrdu ? "" : "Grade",

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

                      ]),

                      const SizedBox(height: 15),

                      _buildSectionCard(

                          " ( )", Colors.blue.shade700, [

                        Row(

                          crossAxisAlignment: CrossAxisAlignment.center,

                          children: [

                            Expanded(

                                flex: 2,

                                child: _buildDoubleDropdown(

                                    isUrdu ? " " : "Target Qty",

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

                                title: Text(" ",

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

                                            "",

                                            paraNumbers,

                                            selectedSabqiParaToAdd,

                                            (val) => setState(() =>

                                                selectedSabqiParaToAdd = val))),

                                    const SizedBox(width: 10),

                                    Expanded(

                                        child: _buildDoubleDropdown(

                                            "",

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

                                    label: Text(isUrdu ? " " : "Add",

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

                                  .map((e) => MadrasaCard(

                                      elevation: 0,

                                      color: Colors.blue.shade50,

                                      child: ListTile(

                                          dense: true,

                                          title:

                                              Text(" ${e.value['para']}"),

                                          subtitle: Text(

                                              getUrduQty(e.value['amountVal'])),

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

                                    "",

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

                                  child: MadrasaTextField(

                                      controller: sabqiListenerController,

                                      decoration: InputDecoration(

                                          labelText:

                                              isUrdu ? " " : "Listener",

                                          border: const OutlineInputBorder()))),

                              Expanded(

                                  flex: 3,

                                  child: Row(

                                    mainAxisAlignment: MainAxisAlignment.end,

                                    children: [

                                      const Expanded(

                                          child: Text("",

                                              style: TextStyle(

                                                  fontSize: 11,

                                                  fontWeight: FontWeight.bold),

                                              textAlign: TextAlign.right,

                                              overflow: TextOverflow.ellipsis)),

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

                      ]),

                      const SizedBox(height: 15),

                      _buildSectionCard(

                          "",

                          Colors.amber.shade700,

                          [

                            Row(

                              crossAxisAlignment: CrossAxisAlignment.center,

                              children: [

                                Expanded(

                                    flex: 2,

                                    child: _buildDoubleDropdown(

                                        isUrdu ? " " : "Target Qty",

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

                                        title: Text(" ",

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

                                                "",

                                                paraNumbers,

                                                selectedManzilParaToAdd,

                                                (val) => setState(() =>

                                                    selectedManzilParaToAdd =

                                                        val))),

                                        const SizedBox(width: 10),

                                        Expanded(

                                            child: _buildDoubleDropdown(

                                                "",

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

                                            isUrdu ? " " : "Add",

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

                                      .map((e) => MadrasaCard(

                                          elevation: 0,

                                          color: Colors.amber.shade50,

                                          child: ListTile(

                                              dense: true,

                                              title: Text(

                                                  " ${e.value['para']}"),

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

                                        "",

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

                                      child: MadrasaTextField(

                                          controller: manzilListenerController,

                                          decoration: InputDecoration(

                                              labelText: isUrdu

                                                  ? " "

                                                  : "Listener",

                                              border:

                                                  const OutlineInputBorder()))),

                                  Expanded(

                                      flex: 3,

                                      child: Row(

                                        mainAxisAlignment:

                                            MainAxisAlignment.end,

                                        children: [

                                          const Expanded(

                                              child: Text("",

                                                  style: TextStyle(

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

                                                  activeColor: Colors

                                                      .amber.shade900,

                                                  onChanged: (val) => setState(

                                                      () =>

                                                          manzilListenerJaiza =

                                                              val!))),

                                        ],

                                      ))

                                ]

                              ]),

                          ],

                          titleColor: Colors.white),

                      const SizedBox(height: 20),

                      ElevatedButton(

                          style: ElevatedButton.styleFrom(

                              backgroundColor: primaryGreen,

                              padding:

                                  const EdgeInsets.symmetric(vertical: 15)),

                          onPressed: _saveRecord,

                          child: Text(

                              isUrdu ? ' /  ' : 'Save / Update',

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

                  hint: Text("--", style: TextStyle(color: textColor)),

                  icon: Icon(Icons.keyboard_arrow_down, color: textColor),

                  items: items

                      .map((i) => DropdownMenuItem<String>(

                          value: i,

                          child: Text(i,

                              style:

                                  TextStyle(color: textColor, fontSize: 13))))

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

                  hint: Text("--", style: TextStyle(color: textColor)),

                  icon: Icon(Icons.keyboard_arrow_down, color: textColor),

                  items: items

                      .map((i) => DropdownMenuItem<double>(

                          value: i,

                          child: Text(getUrduQty(i),

                              style: TextStyle(

                                  color: textColor,

                                  fontSize: 13,

                                  fontWeight: FontWeight.bold))))

                      .toList(),

                  onChanged: onChanged)),

        ),

      ],

    );

  }

}

