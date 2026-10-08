import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_ui_helper.dart';

class ClassPeriodsConfigPage extends StatefulWidget {
  final int classId;
  final String className;

  const ClassPeriodsConfigPage({super.key, required this.classId, required this.className});

  @override
  State<ClassPeriodsConfigPage> createState() => _ClassPeriodsConfigPageState();
}

class _ClassPeriodsConfigPageState extends State<ClassPeriodsConfigPage> {
  bool isLoading = true;
//   List<Map<String, dynamic>> _periods = [];
  final List<TextEditingController> _subjectControllers = List.generate(6, (index) => TextEditingController());
  final List<TextEditingController> _booksControllers = List.generate(6, (index) => TextEditingController());
  
  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    try {
      final db = DBHelper();
      final periods = await db.getClassPeriods(widget.classId);
      
      for (int i = 0; i < 6; i++) {
        int periodNo = i + 1;
        var match = periods.where((p) => p['period_no'] == periodNo);
        if (match.isNotEmpty) {
          _subjectControllers[i].text = match.first['books']?.toString() ?? '';
          _booksControllers[i].text = match.first['books_list']?.toString() ?? '';
        } else {
          _subjectControllers[i].text = 'مضمون${i + 1}';
          _booksControllers[i].text = '';
        }
      }

      if (mounted) {
        setState(() {
//           _periods = periods;
          isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() { isLoading = false; });
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error loading periods: $e')));
      }
    }
  }

  Future<void> _saveConfig() async {
    setState(() => isLoading = true);
    final db = DBHelper();
    for (int i = 0; i < 6; i++) {
      await db.saveClassPeriodConfig(
        widget.classId, 
        i + 1, 
        _subjectControllers[i].text.trim(), 
        _booksControllers[i].text.trim()
      );
    }
    
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrduGlobal.value ? "محفوظ کر لیا گیا! ہم آہنگ کیا جا رہا ہے..." : "Saved! Syncing..."),
        backgroundColor: Colors.green,
      ));
    }
    
    try {
      await SyncUIHelper.performManualSync(context);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(isUrduGlobal.value ? "سنک مکمل ہو گیا!" : "Sync Complete!"),
          backgroundColor: Colors.green,
        ));
        Navigator.pop(context, true);
      }
    } catch(e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(isUrduGlobal.value ? "سنک نہیں ہو سکا۔" : "Sync Failed."),
          backgroundColor: Colors.orange,
        ));
        Navigator.pop(context, true);
      }
    }
  }

  @override
  void dispose() {
    for (var c in _subjectControllers) {
      c.dispose();
    }
    for (var c in _booksControllers) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(isUrdu ? "پیریڈز کنفیگر: ${widget.className}" : "Configure Periods: ${widget.className}"),
        backgroundColor: const Color(0xFF284D3D),
        foregroundColor: Colors.white,
      ),
      body: isLoading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                children: [
                  for (int i = 0; i < 6; i++)
                    Card(
                      elevation: 3,
                      margin: const EdgeInsets.only(bottom: 16),
                      child: Padding(
                        padding: const EdgeInsets.all(16.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              isUrdu ? "پیریڈ ${i + 1}" : "Period ${i + 1}",
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Color(0xFF284D3D)),
                            ),
                            const SizedBox(height: 10),
                            TextField(
                              controller: _subjectControllers[i],
                              decoration: InputDecoration(
                                labelText: isUrdu ? "سبجیکٹ / مضمون کا نام" : "Subject Name",
                                border: const OutlineInputBorder(),
                                prefixIcon: const Icon(Icons.subject),
                              ),
                            ),
                            const SizedBox(height: 10),
                            TextField(
                              controller: _booksControllers[i],
                              decoration: InputDecoration(
                                labelText: isUrdu ? "کتابوں کے نام (کوما سے الگ کریں)" : "Book Names (comma separated)",
                                border: const OutlineInputBorder(),
                                prefixIcon: const Icon(Icons.menu_book),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  const SizedBox(height: 20),
                  ElevatedButton(
                    onPressed: _saveConfig,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF284D3D),
                      foregroundColor: Colors.white,
                      minimumSize: const Size(double.infinity, 50),
                    ),
                    child: Text(
                      isUrdu ? "محفوظ کریں" : "Save Configuration",
                      style: const TextStyle(fontSize: 18),
                    ),
                  )
                ],
              ),
            ),
    );
  }
}
