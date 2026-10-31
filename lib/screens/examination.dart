// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import '../db_helper.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';
import 'exam_results_entry.dart';
import 'package:intl/intl.dart' as intl;
import 'dart:ui' as ui;

class ExaminationPage extends StatefulWidget {
  const ExaminationPage({super.key});

  @override
  State<ExaminationPage> createState() => _ExaminationPageState();
}

class _ExaminationPageState extends State<ExaminationPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  final _nameController = TextEditingController();
  final _dateController = TextEditingController(
      text: intl.DateFormat('yyyy-MM-dd').format(DateTime.now()));
  final _totalMarksController = TextEditingController(text: '100');
  final _passingMarksController = TextEditingController(text: '33');

  List<Map<String, dynamic>> _exams = [];

  @override
  void initState() {
    super.initState();
    _loadExams();
  }

  void _loadExams() async {
    final data = await DBHelper().getExams();
    setState(() {
      _exams = data;
    });
  }

  void _addExam() async {
    if (_nameController.text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(isUrduGlobal.value
                ? "Ø§Ù…ØªØ­Ø§Ù† Ú©Ø§ Ù†Ø§Ù… Ø¯Ø±Ø¬ Ú©Ø±ÛŒÚº"
                : "Please enter Exam Name")),
      );
      return;
    }

    await DBHelper().insertExam({
      'exam_name': _nameController.text,
      'exam_date': _dateController.text,
      'total_marks': double.tryParse(_totalMarksController.text) ?? 100,
      'passing_marks': double.tryParse(_passingMarksController.text) ?? 33,
    });

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
          content: Text(isUrduGlobal.value
              ? "Ù†ÛŒØ§ Ø§Ù…ØªØ­Ø§Ù† Ø´Ø§Ù…Ù„ Ú©Ø± Ø¯ÛŒØ§ Ú¯ÛŒØ§!"
              : "New Exam Added!")),
    );

    _nameController.clear();
    _totalMarksController.text = '100';
    _passingMarksController.text = '33';
    _loadExams();
    SyncService.runTwoWaySync().catchError((_) {});
  }

  @override
  void dispose() {
    _nameController.dispose();
    _dateController.dispose();
    _totalMarksController.dispose();
    _passingMarksController.dispose();
    super.dispose();
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
              appBar: AppBar(
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Syncing data...'))
              );
              // Call your sync logic here
              // ApiSync.syncData(); 
            },
          )
        ],
                title: Text(isUrdu ? "Ø§Ù…ØªØ­Ø§Ù†Ø§Øª Ú©Ø§Ø±ÛŒÚ©Ø§Ø±Úˆ" : "Exams Record",
                    style: TextStyle(
                        color: primaryGreen, fontWeight: FontWeight.bold)),
                backgroundColor: bgColor,
                elevation: 0,
                iconTheme: IconThemeData(color: primaryGreen),
                centerTitle: true,
              ),
              drawer: const AppDrawer(),
              body: Padding(
                padding: const EdgeInsets.all(15.0),
                child: Column(
                  children: [
                    NeoBox(
                      padding: 15,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              Icon(Icons.add_circle, color: primaryGreen),
                              const SizedBox(width: 10),
                              Text(
                                isUrdu
                                    ? "Ù†ÛŒØ§ Ø§Ù…ØªØ­Ø§Ù† Ø´Ø§Ù…Ù„ Ú©Ø±ÛŒÚº"
                                    : "Add New Exam",
                                style: TextStyle(
                                    fontSize: 18,
                                    color: textDark,
                                    fontWeight: FontWeight.bold),
                              )
                            ],
                          ),
                          const SizedBox(height: 15),
                          TextField(
                            controller: _nameController,
                            decoration: InputDecoration(
                              labelText: isUrdu
                                  ? "Ø§Ù…ØªØ­Ø§Ù† Ú©Ø§ Ù†Ø§Ù… (Ù…Ø«Ù„Ø§Ù‹: Ø³Ø§Ù„Ø§Ù†Û Ø§Ù…ØªØ­Ø§Ù† 2024)"
                                  : "Exam Name",
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _dateController,
                            readOnly: true,
                            decoration: InputDecoration(
                              labelText: isUrdu ? "ØªØ§Ø±ÛŒØ®" : "Date",
                              border: const OutlineInputBorder(),
                              suffixIcon: const Icon(Icons.calendar_today),
                            ),
                            onTap: () async {
                              final pickedDate = await showDatePicker(
                                context: context,
                                initialDate: DateTime.now(),
                                firstDate: DateTime(2000),
                                lastDate: DateTime(2100),
                              );
                              if (pickedDate != null) {
                                _dateController.text =
                                    intl.DateFormat('yyyy-MM-dd')
                                        .format(pickedDate);
                              }
                            },
                          ),
                          const SizedBox(height: 10),
                          Row(
                            children: [
                              Expanded(
                                child: TextField(
                                  controller: _totalMarksController,
                                  keyboardType: TextInputType.number,
                                  decoration: InputDecoration(
                                    labelText:
                                        isUrdu ? "Ú©Ù„ Ù†Ù…Ø¨Ø±" : "Total Marks",
                                    border: const OutlineInputBorder(),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: TextField(
                                  controller: _passingMarksController,
                                  keyboardType: TextInputType.number,
                                  decoration: InputDecoration(
                                    labelText: isUrdu
                                        ? "Ù¾Ø§Ø³Ù†Ú¯ Ù…Ø§Ø±Ú©Ø³"
                                        : "Passing Marks",
                                    border: const OutlineInputBorder(),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 15),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                            ),
                            onPressed: _addExam,
                            icon: const Icon(Icons.save, color: Colors.white),
                            label: Text(
                              isUrdu ? "Ù…Ø­ÙÙˆØ¸ Ú©Ø±ÛŒÚº" : "Save Exam",
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),
                    Expanded(
                      child: NeoBox(
                        padding: 10,
                        child: _exams.isEmpty
                            ? Center(
                                child: Text(isUrdu
                                    ? "Ú©ÙˆØ¦ÛŒ Ø§Ù…ØªØ­Ø§Ù† Ù…ÙˆØ¬ÙˆØ¯ Ù†ÛÛŒÚº"
                                    : "No exams found"))
                            : ListView.builder(
                                itemCount: _exams.length,
                                itemBuilder: (context, index) {
                                  final exam = _exams[index];
                                  return Card(
                                    color: Colors.white,
                                    margin: const EdgeInsets.only(bottom: 10),
                                    child: ListTile(
                                      leading: CircleAvatar(
                                        backgroundColor: primaryGreen,
                                        child: Text("${exam['id']}",
                                            style: const TextStyle(
                                                color: Colors.white)),
                                      ),
                                      title: Text(
                                        exam['exam_name'],
                                        style: TextStyle(
                                            fontWeight: FontWeight.bold,
                                            color: primaryGreen),
                                      ),
                                      subtitle: Text(
                                        "${exam['exam_date']}\n${isUrdu ? 'Ú©Ù„ Ù†Ù…Ø¨Ø±' : 'Total'}: ${exam['total_marks'] ?? 100} | ${isUrdu ? 'Ù¾Ø§Ø³' : 'Pass'}: ${exam['passing_marks'] ?? 33}",
                                      ),
                                      trailing: Icon(Icons.arrow_forward_ios,
                                          size: 16, color: primaryGreen),
                                      onTap: () {
                                        Navigator.push(
                                            context,
                                            MaterialPageRoute(
                                              builder: (c) =>
                                                  ExamResultsEntryPage(
                                                      exam: exam),
                                            ));
                                      },
                                    ),
                                  );
                                },
                              ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
