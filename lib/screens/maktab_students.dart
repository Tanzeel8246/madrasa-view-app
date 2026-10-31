import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:io';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../utils/sync_service.dart';
import 'dashboard.dart';
import 'sanad_preview_screen.dart';
import 'student_report.dart';
import 'package:printing/printing.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:flutter/services.dart';
import 'package:screenshot/screenshot.dart';
import 'package:image_picker/image_picker.dart';

class MaktabStudentsPage extends StatefulWidget {
  final String? initialClassFilter;
  const MaktabStudentsPage({super.key, this.initialClassFilter});

  @override
  _MaktabStudentsPageState createState() => _MaktabStudentsPageState();
}

class _MaktabStudentsPageState extends State<MaktabStudentsPage> {
  final ScreenshotController screenshotController = ScreenshotController();
  final TextEditingController _searchController = TextEditingController();
  List<Map<String, dynamic>> _activeMaktabStudents = [];
  List<Map<String, dynamic>> _alumniMaktabStudents = [];

  List<Map<String, dynamic>> _foundActive = [];
  List<Map<String, dynamic>> _foundAlumni = [];
  List<Map<String, dynamic>> _classes = [];
  String? _selectedClass;
  String? _selectedResidenceStatus;
  String _selectedAlumniCategory = 'all';

  // پیجینیشن متغیرات (Pagination)
  static const int _pageSize = 100;
  int _activeOffset = 0;
  int _alumniOffset = 0;
  bool _hasMoreActive = true;
  bool _hasMoreAlumni = true;
  bool _isLoadingMore = false;
  final ScrollController _scrollController = ScrollController();
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    if (widget.initialClassFilter != null) {
      _searchQuery = widget.initialClassFilter!;
      _searchController.text = _searchQuery;
    }
    _refreshMaktabStudents();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      _loadMoreMaktabStudents();
    }
  }

  void _refreshMaktabStudents() async {
    setState(() {
      _activeMaktabStudents = [];
      _alumniMaktabStudents = [];
      _foundActive = [];
      _foundAlumni = [];
      _activeOffset = 0;
      _alumniOffset = 0;
      _hasMoreActive = true;
      _hasMoreAlumni = true;
    });

    // پہلے بیچ
    final activeData = await DBHelper().getPagedMaktabStudentsForUser(
        limit: _pageSize,
        offset: 0,
        status: 'Active',
        searchQuery: _searchQuery);
    final alumniData = await DBHelper().getPagedMaktabStudentsForUser(
        limit: _pageSize,
        offset: 0,
        status: 'Alumni',
        searchQuery: _searchQuery);

    if (!mounted) return;
    setState(() {
      _activeMaktabStudents = activeData;
      _alumniMaktabStudents = alumniData;
      _foundActive = activeData;
      _foundAlumni = alumniData;
      _activeOffset = activeData.length;
      _alumniOffset = alumniData.length;
      _hasMoreActive = activeData.length == _pageSize;
      _hasMoreAlumni = alumniData.length == _pageSize;
    });

    final classData = await DBHelper().getMaktabClassesForUser();
    if (!mounted) return;
    setState(() => _classes = classData);

    if (widget.initialClassFilter != null &&
        widget.initialClassFilter!.isNotEmpty) {
      _searchController.text = widget.initialClassFilter!;
      _searchQuery = widget.initialClassFilter!;
    }

    if (activeData.isEmpty && alumniData.isEmpty && _searchQuery.isEmpty) {
      SyncService.runTwoWaySync().then((_) {
        if (mounted) {
          _refreshMaktabStudents();
        }
      }).catchError((_) {});
    }
  }

  Future<void> _loadMoreMaktabStudents() async {
    if (_isLoadingMore || (!_hasMoreActive && !_hasMoreAlumni)) return;
    setState(() => _isLoadingMore = true);

    try {
      if (_hasMoreActive) {
        final more = await DBHelper().getPagedMaktabStudentsForUser(
            limit: _pageSize,
            offset: _activeOffset,
            status: 'Active',
            searchQuery: _searchQuery);
        setState(() {
          _activeMaktabStudents.addAll(more);
          _foundActive = _activeMaktabStudents;
          _activeOffset += (more as List).length;
          _hasMoreActive = more.length == _pageSize;
        });
      }

      if (_hasMoreAlumni) {
        final more = await DBHelper().getPagedMaktabStudentsForUser(
            limit: _pageSize,
            offset: _alumniOffset,
            status: 'Alumni',
            searchQuery: _searchQuery);
        setState(() {
          _alumniMaktabStudents.addAll(more);
          _foundAlumni = _alumniMaktabStudents;
          _alumniOffset += (more as List).length;
          _hasMoreAlumni = more.length == _pageSize;
        });
      }
    } finally {
      if (mounted) {
        setState(() => _isLoadingMore = false);
      }
    }
  }

  void _runFilter(String enteredKeyword) {
    _searchQuery = enteredKeyword.trim();
    _refreshMaktabStudents();
  }

  Future<void> _showAddMaktabStudentDialog() async {
    final nameController = TextEditingController();
    final fatherNameController = TextEditingController();
    final rollNoController = TextEditingController();
    _selectedClass = null;
    _selectedResidenceStatus = null;
    String addQuranEdition = '16 سطری';
    String addHifzSection = 'regular';
    final contactController = TextEditingController();
    final addressController = TextEditingController();
    final admissionDateController =
        TextEditingController(text: DateTime.now().toString().substring(0, 10));
    final graduationDateController = TextEditingController();
    final durationController = TextEditingController();

    // ویب کی طرح اگلا داخلہ نمبر خودکار تجویز کریں
    final nextRollNo = await DBHelper().getNextStudentRollNumber();
    rollNoController.text = nextRollNo;

    bool isSaving = false;
    String? photoPath;

    Future<void> pickImage(
        void Function(void Function()) setDialogState) async {
      final picker = ImagePicker();
      final picked = await picker.pickImage(
          source: ImageSource.gallery,
          imageQuality: 50,
          maxWidth: 800,
          maxHeight: 800);
      if (picked != null) {
        setDialogState(() => photoPath = picked.path);
      }
    }

    showDialog(
      context: context,
      builder: (_) => StatefulBuilder(builder: (context, setDialogState) {
        return AlertDialog(
          title: Row(children: [
            const Icon(Icons.person_add, color: Color(0xFF284D3D)),
            const SizedBox(width: 8),
            Text(isUrduGlobal.value
                ? "نیا اندراج (طالب علم)"
                : "New Student Entry"),
          ]),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                GestureDetector(
                  onTap: () => pickImage(setDialogState),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      _buildPhotoAvatar(photoPath, radius: 40),
                      if (photoPath == null)
                        const Icon(Icons.camera_alt,
                            size: 30, color: Colors.grey),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                // نام طالب علم *
                TextField(
                  controller: nameController,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? "نام طالب علم *"
                        : "Student Name *",
                    prefixIcon: const Icon(Icons.person),
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                // ولدیت *
                TextField(
                  controller: fatherNameController,
                  decoration: InputDecoration(
                    labelText:
                        isUrduGlobal.value ? "ولدیت *" : "Father's Name *",
                    prefixIcon: const Icon(Icons.person_outline),
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                // داخلہ نمبر اور کلاس (ویب کی طرح)
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: rollNoController,
                        decoration: InputDecoration(
                          labelText: isUrduGlobal.value
                              ? "داخلہ نمبر *"
                              : "Admission No *",
                          border: const OutlineInputBorder(),
                          hintText: "مثلاً 90001",
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: DropdownButtonFormField<String>(
                        value: _selectedClass,
                        decoration: InputDecoration(
                          labelText:
                              isUrduGlobal.value ? "کلاس / درجہ" : "Class",
                          border: const OutlineInputBorder(),
                        ),
                        items: _classes.map((c) {
                          return DropdownMenuItem<String>(
                            value: c['name'].toString(),
                            child: Text(c['name'].toString()),
                          );
                        }).toList(),
                        onChanged: (v) {
                          setDialogState(() {
                            _selectedClass = v;
                          });
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value:
                      addQuranEdition.isNotEmpty ? addQuranEdition : '16 سطری',
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? "قرآن کریم نسخہ / لائنز *"
                        : "Quran Edition *",
                    prefixIcon:
                        const Icon(Icons.menu_book, color: Colors.green),
                    border: const OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(
                        value: '13 سطری',
                        child: Text("13 سطری قرآن کریم (13 Lines)")),
                    DropdownMenuItem(
                        value: '15 سطری',
                        child: Text("15 سطری قرآن کریم (15 Lines)")),
                    DropdownMenuItem(
                        value: '16 سطری',
                        child: Text("16 سطری قرآن کریم (16 Lines - عام فہم)")),
                    DropdownMenuItem(
                        value: '18 سطری',
                        child: Text("18 سطری قرآن کریم (18 Lines)")),
                  ],
                  onChanged: (v) {
                    if (v != null) {
                      setDialogState(() => addQuranEdition = v);
                    }
                  },
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: addHifzSection,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? "حفظ سیکشن / زمرہ *"
                        : "Hifz Section *",
                    prefixIcon: const Icon(Icons.category, color: Colors.teal),
                    border: const OutlineInputBorder(),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: 'regular',
                      child: Text(isUrduGlobal.value
                          ? "سیکشن A: ریگولر حفظ (مکمل وقت)"
                          : "Section A: Regular Hifz (Full Time)"),
                    ),
                    DropdownMenuItem(
                      value: 'manzil_gardan',
                      child: Text(isUrduGlobal.value
                          ? "سیکشن B: منزل/گردان (پارٹ ٹائم/سکول)"
                          : "Section B: Manzil / Gardan (Part Time)"),
                    ),
                  ],
                  onChanged: (v) {
                    if (v != null) {
                      setDialogState(() => addHifzSection = v);
                    }
                  },
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: _selectedResidenceStatus,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? "رہائشی حیثیت"
                        : "Residential Status",
                    border: const OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(
                        value: 'residential_aided',
                        child: Text("اقامتی، امدادی")),
                    DropdownMenuItem(
                        value: 'non_residential_non_aided',
                        child: Text("غیر اقامتی، غیر امدادی")),
                    DropdownMenuItem(
                        value: 'residential_non_aided',
                        child: Text("اقامتی، غیر امدادی")),
                    DropdownMenuItem(
                        value: 'non_residential_aided',
                        child: Text("غیر اقامتی، امدادی")),
                  ],
                  onChanged: (v) {
                    setDialogState(() {
                      _selectedResidenceStatus = v;
                    });
                  },
                ),
                const SizedBox(height: 10),
                // رابطہ نمبر *
                TextField(
                  controller: contactController,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? "رابطہ نمبر *"
                        : "Contact Number *",
                    hintText: "0300xxxxxxx",
                    prefixIcon: const Icon(Icons.phone),
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                // مکمل پتہ
                TextField(
                  controller: addressController,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value ? "مکمل پتہ" : "Full Address",
                    prefixIcon: const Icon(Icons.home),
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                // تاریخ داخلہ
                TextField(
                  controller: admissionDateController,
                  readOnly: true,
                  decoration: InputDecoration(
                    labelText:
                        isUrduGlobal.value ? "تاریخ داخلہ" : "Admission Date",
                    prefixIcon: const Icon(Icons.calendar_today),
                    border: const OutlineInputBorder(),
                  ),
                  onTap: () async {
                    DateTime? picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now(),
                        firstDate: DateTime(2000),
                        lastDate: DateTime(2100));
                    if (picked != null) {
                      setDialogState(() => admissionDateController.text =
                          picked.toString().substring(0, 10));
                    }
                  },
                ),
                const SizedBox(height: 10),
                // تعلیمی دورانیہ
                TextField(
                  controller: durationController,
                  decoration: InputDecoration(
                    labelText:
                        isUrduGlobal.value ? "تعلیمی دورانیہ" : "Duration",
                    hintText:
                        isUrduGlobal.value ? "مثلاً 3 سال" : "e.g. 3 years",
                    prefixIcon: const Icon(Icons.timer),
                    border: const OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: isSaving ? null : () => Navigator.pop(context),
              child: Text(isUrduGlobal.value ? "کینسل" : "Cancel"),
            ),
            ElevatedButton.icon(
              icon: isSaving
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2))
                  : const Icon(Icons.save, color: Colors.white, size: 18),
              style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF284D3D)),
              onPressed: isSaving
                  ? null
                  : () async {
                      // Validation
                      if (nameController.text.trim().isEmpty) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "نام طالب علم لکھنا ضروری ہے!"
                              : "Student name is required!"),
                          backgroundColor: Colors.red,
                        ));
                        return;
                      }
                      if (fatherNameController.text.trim().isEmpty) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "ولدیت لکھنا ضروری ہے!"
                              : "Father's name is required!"),
                          backgroundColor: Colors.red,
                        ));
                        return;
                      }
                      if (contactController.text.trim().isEmpty) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "رابطہ نمبر لازمی ہے!"
                              : "Contact number is required!"),
                          backgroundColor: Colors.red,
                        ));
                        return;
                      }
                      if (rollNoController.text.trim().isEmpty) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "داخلہ نمبر لازمی ہے!"
                              : "Admission number is required!"),
                          backgroundColor: Colors.red,
                        ));
                        return;
                      }

                      final isSuper = currentUserRole.value == 'superadmin' || 
                          (currentUserRole.value == 'admin' && currentUserId.value == 1);
                      final maxStd = currentTenantMaxStudents.value;
                      if (!isSuper && _activeMaktabStudents.length >= maxStd) {
                        showDialog(
                          context: context,
                          builder: (ctx) => AlertDialog(
                            title: Row(
                              children: [
                                const Icon(Icons.lock, color: Colors.red),
                                const SizedBox(width: 8),
                                Text(isUrduGlobal.value ? 'طلبہ کی حد مکمل' : 'Student Limit Reached'),
                              ],
                            ),
                            content: Text(
                              isUrduGlobal.value
                                  ? 'آپ کے موجودہ پلان (${currentTenantPlanName.value}) میں طلبہ کی زیادہ سے زیادہ حد ($maxStd) مکمل ہو چکی ہے۔\nمزید طلبہ کے داخلے کے لیے براہ کرم اپنا سبسکرپشن پلان اپ گریڈ کریں۔'
                                  : 'You have reached the maximum student limit ($maxStd) for your plan (${currentTenantPlanName.value}). Please upgrade your plan to admit more students.',
                            ),
                            actions: [
                              ElevatedButton(
                                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF284D3D)),
                                onPressed: () => Navigator.pop(ctx),
                                child: Text(isUrduGlobal.value ? 'سمجھ گیا' : 'OK', style: const TextStyle(color: Colors.white)),
                              ),
                            ],
                          ),
                        );
                        return;
                      }

                      setDialogState(() => isSaving = true);

                      try {
                        // DB-level duplicate check
                        final result = await DBHelper().insertMaktabStudent({
                          'name': nameController.text.trim(),
                          'father_name': fatherNameController.text.trim(),
                          'roll_number': rollNoController.text.trim(),
                          'class_name': _selectedClass ?? '',
                          'residence_status': _selectedResidenceStatus ?? '',
                          'contact_number': contactController.text.trim(),
                          'address': addressController.text.trim(),
                          'status': 'Active',
                          'admission_date': admissionDateController.text,
                          'graduation_date': graduationDateController.text,
                          'duration': durationController.text.trim(),
                          'photo': photoPath ?? '',
                          'quran_edition': addQuranEdition.isNotEmpty
                              ? addQuranEdition
                              : '16 سطری',
                          'hifz_section': addHifzSection,
                        });

                        if (result == -1) {
                          setDialogState(() => isSaving = false);
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrduGlobal.value
                                ? "❌ یہ داخلہ نمبر پہلے سے موجود ہے! نیا نمبر ڈالیں۔"
                                : "❌ This admission number already exists! Enter a new one."),
                            backgroundColor: Colors.red,
                            duration: const Duration(seconds: 4),
                          ));
                          return;
                        }

                        if (mounted) {
                          Navigator.pop(context);
                          _refreshMaktabStudents();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrduGlobal.value
                                ? "✅ طالب علم کا ریکارڈ محفوظ ہو گیا!"
                                : "✅ Student saved successfully!"),
                            backgroundColor: Colors.green,
                          ));
                        }

                        SyncService.triggerBackgroundTableSync(
                            'maktab_students');
                      } catch (e, stackTrace) {
                        debugPrint(
                            'Error saving maktab student: $e\nStack: $stackTrace');
                        setDialogState(() => isSaving = false);
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrduGlobal.value
                                ? "❌ طالب علم کا ریکارڈ محفوظ نہیں ہو سکا۔ دوبارہ کوشش کریں۔"
                                : "❌ Failed to save student record. Please try again."),
                            backgroundColor: Colors.red,
                          ));
                        }
                      }
                    },
              label: Text(
                  isUrduGlobal.value
                      ? (isSaving ? "محفوظ ہو رہا ہے..." : "محفوظ کریں")
                      : (isSaving ? "Saving..." : "Save"),
                  style: const TextStyle(color: Colors.white)),
            ),
          ],
        );
      }),
    );
  }

  Future<void> _showBulkImportDialog() async {
    final importController = TextEditingController();
    bool isImporting = false;

    showDialog(
      context: context,
      builder: (_) => StatefulBuilder(builder: (context, setDialogState) {
        return AlertDialog(
          title: Text(isUrduGlobal.value
              ? 'طلباء bulk import'
              : 'Bulk Import Maktab Students'),
          content: SizedBox(
            width: 520,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  isUrduGlobal.value
                      ? 'ہر لائن میں: داخلہ نمبر، نام، ولدیت، رابطہ، کلاس'
                      : 'One row per line: admission no, name, father, contact, class',
                  style: const TextStyle(fontSize: 12),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: importController,
                  minLines: 8,
                  maxLines: 12,
                  textDirection: isUrduGlobal.value
                      ? TextDirection.rtl
                      : TextDirection.ltr,
                  decoration: InputDecoration(
                    hintText: isUrduGlobal.value
                        ? '1001, احمد, محمد, 03000000000, حفظ'
                        : '1001, Ahmad, Muhammad, 03000000000, Hifz',
                    border: const OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: isImporting ? null : () => Navigator.pop(context),
              child: Text(isUrduGlobal.value ? 'کینسل' : 'Cancel'),
            ),
            ElevatedButton.icon(
              icon: isImporting
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.upload_file),
              onPressed: isImporting
                  ? null
                  : () async {
                      setDialogState(() => isImporting = true);
                      final lines = importController.text
                          .split(RegExp(r'\r?\n'))
                          .map((line) => line.trim())
                          .where((line) => line.isNotEmpty)
                          .toList();
                      var imported = 0;
                      var skipped = 0;

                      for (final line in lines) {
                        final cols = line
                            .split(RegExp(r'[\t,]'))
                            .map((part) => part.trim())
                            .toList();
                        if (cols.length < 2) {
                          skipped++;
                          continue;
                        }
                        final name = cols.length > 1 ? cols[1] : '';
                        if (name.isEmpty || name == 'نام الطالب علم') {
                          skipped++;
                          continue;
                        }
                        var admissionNo = cols[0];
                        admissionNo = admissionNo.isEmpty
                            ? await DBHelper().getNextStudentRollNumber()
                            : admissionNo;
                        final result = await DBHelper().insertMaktabStudent({
                          'roll_number': admissionNo,
                          'name': name,
                          'father_name': cols.length > 2 ? cols[2] : '',
                          'contact_number': cols.length > 3 ? cols[3] : '',
                          'class_name': cols.length > 4 ? cols[4] : 'حفظ',
                          'status': 'Active',
                          'admission_date':
                              DateTime.now().toString().substring(0, 10),
                        });
                        if (result == -1) {
                          skipped++;
                        } else {
                          imported++;
                        }
                      }

                      if (!mounted) return;
                      Navigator.pop(context);
                      _refreshMaktabStudents();
                      SyncService.triggerBackgroundTableSync('maktab_students');
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(isUrduGlobal.value
                              ? '$imported طلباء شامل، $skipped چھوڑ دیے گئے'
                              : '$imported students imported, $skipped skipped'),
                          backgroundColor:
                              imported > 0 ? Colors.green : Colors.orange,
                        ),
                      );
                    },
              label: Text(isUrduGlobal.value ? 'امپورٹ' : 'Import'),
            ),
          ],
        );
      }),
    );
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
                Navigator.pushReplacement(context,
                    MaterialPageRoute(builder: (_) => const DashboardPage()));
              }
            },
            child: Directionality(
              textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
              child: Scaffold(
                backgroundColor: const Color(0xFFF1EFE7),
                appBar: AppBar(
                  leading: Builder(
                    builder: (context) => IconButton(
                      icon: const Icon(Icons.menu, color: Colors.white),
                      onPressed: () => Scaffold.of(context).openDrawer(),
                    ),
                  ),
                  title: Text(isUrdu
                      ? "مکتب طلباء کا ریکارڈ"
                      : "Maktab Students Record"),
                  backgroundColor: const Color(0xFF284D3D),
                  foregroundColor: Colors.white,
                  centerTitle: true,
                  actions: [
                    IconButton(
                      icon: const Icon(Icons.upload_file, color: Colors.white),
                      tooltip: isUrdu
                          ? 'طلباء امپورٹ کریں'
                          : 'Import Maktab Students',
                      onPressed: _showBulkImportDialog,
                    ),
                    IconButton(
                      icon: const Icon(Icons.sync, color: Colors.white),
                      tooltip: isUrdu ? 'سنک کریں' : 'Sync',
                      onPressed: () async {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu
                                ? "ڈیٹا سنک ہو رہا ہے..."
                                : "Syncing data...")));
                        try {
                          await SyncService.runTwoWaySync();
                          if (mounted) {
                            _refreshMaktabStudents();
                            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                content: Text(isUrdu
                                    ? "سنک مکمل ہو گیا!"
                                    : "Sync complete!"),
                                backgroundColor: Colors.green));
                          }
                        } catch (e, stackTrace) {
                          debugPrint(
                              'Error syncing maktab students: $e\nStack: $stackTrace');
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                content: Text(isUrdu
                                    ? "سنک میں خرابی آئی"
                                    : "Sync failed"),
                                backgroundColor: Colors.red));
                          }
                        }
                      },
                    ),
                  ],
                ),
                drawer: const AppDrawer(),
                floatingActionButton: FloatingActionButton(
                  backgroundColor: const Color(0xFF284D3D),
                  onPressed: _showAddMaktabStudentDialog,
                  child: const Icon(Icons.add, color: Colors.white),
                ),
                body: DefaultTabController(
                  length: 6,
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: Column(
                      children: [
                        TextField(
                          onChanged: (value) => _runFilter(value),
                          decoration: InputDecoration(
                            labelText: isUrdu
                                ? 'تلاش کریں (نام، رول نمبر یا کلاس)'
                                : 'Search (Name, Roll No or Class)',
                            suffixIcon: const Icon(Icons.search),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(10)),
                            filled: true,
                            fillColor: Colors.white,
                          ),
                        ),
                        const SizedBox(height: 10),
                        TabBar(
                          isScrollable: true,
                          labelColor: const Color(0xFF284D3D),
                          unselectedLabelColor: Colors.grey,
                          indicatorColor: const Color(0xFF284D3D),
                          tabs: [
                            Tab(text: isUrdu ? 'تمام طلباء' : 'All'),
                            Tab(
                                text: isUrdu
                                    ? 'اقامتی، امدادی'
                                    : 'Residential Aided'),
                            Tab(
                                text: isUrdu
                                    ? 'غیر اقامتی، غیر امدادی'
                                    : 'Non-Res Non-Aided'),
                            Tab(
                                text: isUrdu
                                    ? 'اقامتی، غیر امدادی'
                                    : 'Residential Non-Aided'),
                            Tab(
                                text: isUrdu
                                    ? 'غیر اقامتی، امدادی'
                                    : 'Non-Res Aided'),
                            Tab(text: isUrdu ? 'فارغ التحصیل' : 'Alumni'),
                          ],
                        ),
                        Expanded(
                          child: TabBarView(
                            children: [
                              _buildGroupedList(isUrdu, 'all_active'),
                              _buildGroupedList(isUrdu, 'residential_aided'),
                              _buildGroupedList(
                                  isUrdu, 'non_residential_non_aided'),
                              _buildGroupedList(
                                  isUrdu, 'residential_non_aided'),
                              _buildGroupedList(
                                  isUrdu, 'non_residential_aided'),
                              _buildGroupedList(isUrdu, 'alumni'),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            )); // PopScope کا بند
      },
    );
  }

  Widget _buildPhotoAvatar(String? photoPath, {double radius = 16}) {
    Widget fallback = CircleAvatar(
        radius: radius,
        backgroundColor: Colors.grey.shade300,
        child: Icon(Icons.person, size: radius * 1.25, color: Colors.white));
    if (photoPath == null || photoPath.isEmpty) {
      return fallback;
    }

    Widget img;
    if (photoPath.startsWith('http')) {
      img = Image.network(photoPath,
          fit: BoxFit.cover, errorBuilder: (_, __, ___) => fallback);
    } else if (File(photoPath).existsSync()) {
      img = Image.file(File(photoPath),
          fit: BoxFit.cover, errorBuilder: (_, __, ___) => fallback);
    } else {
      String baseUrl = apiUrl;
      if (baseUrl.endsWith('api.php')) {
        baseUrl = baseUrl.substring(0, baseUrl.length - 7);
      }
      if (!baseUrl.endsWith('/')) baseUrl += '/';

      String fullUrl = baseUrl;
      if (photoPath.startsWith('uploads/')) {
        fullUrl += photoPath;
      } else if (!photoPath.contains('/')) {
        fullUrl += "uploads/maktab_students/$photoPath";
      } else {
        fullUrl += photoPath;
      }
      img = Image.network(fullUrl,
          fit: BoxFit.cover, errorBuilder: (_, __, ___) => fallback);
    }

    return SizedBox(
      width: radius * 2,
      height: radius * 2,
      child: ClipOval(child: img),
    );
  }

  Widget _buildClassDataTable(
      List<Map<String, dynamic>> students, bool isUrdu) {
    int serialNo = 1;
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        headingRowColor:
            WidgetStateProperty.resolveWith((states) => Colors.grey.shade200),
        dataRowMinHeight: 40,
        dataRowMaxHeight: 55,
        columnSpacing: 20,
        columns: [
          DataColumn(
              label: Text(isUrdu ? 'نمبرشمار' : 'S.No',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'رول نمبر' : 'Roll No',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'تصویر' : 'Photo',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'نام طالب علم' : 'Name',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'ولدیت' : 'Father',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'رابطہ' : 'Contact',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
          DataColumn(
              label: Text(isUrdu ? 'ایکشن' : 'Action',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
        ],
        rows: students.map((s) {
          return DataRow(
            cells: [
              DataCell(Text((serialNo++).toString(),
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, color: Colors.grey))),
              DataCell(Text(
                  (s['roll_number'] ?? s['admission_number'])?.toString() ??
                      '-')),
              DataCell(_buildPhotoAvatar(s['photo']?.toString())),
              DataCell(
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(s['name']?.toString() ?? '-',
                        style: const TextStyle(fontWeight: FontWeight.bold)),
                    if (s['hifz_section'] == 'manzil_gardan') ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: Colors.amber.shade100,
                          borderRadius: BorderRadius.circular(4),
                          border: Border.all(
                              color: Colors.amber.shade700, width: 0.8),
                        ),
                        child: Text(
                          isUrdu ? 'منزل/گردان' : 'Manzil',
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                            color: Colors.amber.shade900,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              DataCell(Text(s['father_name']?.toString() ?? '-')),
              DataCell(Text(s['contact_number']?.toString() ?? '-')),
              DataCell(
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      icon: const Icon(Icons.visibility,
                          color: Colors.blue, size: 20),
                      onPressed: () => _showStudentDetails(s, isUrdu),
                    ),
                    if (currentUserRole.value == 'admin')
                      IconButton(
                        icon: const Icon(Icons.edit,
                            color: Colors.orange, size: 20),
                        onPressed: () => _showEditMaktabStudentDialog(s),
                      ),
                  ],
                ),
              ),
            ],
          );
        }).toList(),
      ),
    );
  }

  Widget _buildAlumniFilterBar(bool isUrdu) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            _buildAlumniCatChip(
                'all', isUrdu ? 'تمام سابقہ طلباء' : 'All Alumni'),
            const SizedBox(width: 8),
            _buildAlumniCatChip(
                'completed', isUrdu ? '🎓 تکمیلِ تعلیم' : 'Completed'),
            const SizedBox(width: 8),
            _buildAlumniCatChip(
                'dropped', isUrdu ? '⏸️ ترکِ تعلیم' : 'Dropped'),
            const SizedBox(width: 8),
            _buildAlumniCatChip('expelled', isUrdu ? '❌ اخراج' : 'Expelled'),
          ],
        ),
      ),
    );
  }

  Widget _buildAlumniCatChip(String catKey, String label) {
    final isSelected = _selectedAlumniCategory == catKey;
    return ChoiceChip(
      label: Text(label,
          style: TextStyle(
              color: isSelected ? Colors.white : Colors.black87,
              fontWeight: FontWeight.bold,
              fontSize: 12)),
      selected: isSelected,
      selectedColor: const Color(0xFF284D3D),
      backgroundColor: Colors.grey.shade200,
      onSelected: (bool selected) {
        if (selected) {
          setState(() {
            _selectedAlumniCategory = catKey;
          });
        }
      },
    );
  }

  Widget _buildGroupedList(bool isUrdu, String filterType) {
    List<Map<String, dynamic>> activeList = [];
    List<Map<String, dynamic>> alumniList = [];

    if (filterType == 'alumni') {
      if (_selectedAlumniCategory != 'all') {
        alumniList = _foundAlumni
            .where((s) => resolveAlumniCategory(s) == _selectedAlumniCategory)
            .toList();
      } else {
        alumniList = _foundAlumni;
      }
    } else if (filterType == 'all_active') {
      activeList = _foundActive;
    } else {
      activeList = _foundActive
          .where((s) => (s['residence_status']?.toString() ?? '') == filterType)
          .toList();
    }

    if (activeList.isEmpty && alumniList.isEmpty) {
      if (filterType == 'alumni') {
        return Column(
          children: [
            _buildAlumniFilterBar(isUrdu),
            Expanded(
              child: Center(
                child: Text(
                  isUrdu
                      ? 'اس کیٹیگری میں کوئی ریکارڈ نہیں ملا'
                      : 'No records in this category',
                  style: const TextStyle(fontSize: 16, color: Colors.grey),
                ),
              ),
            ),
          ],
        );
      }
      return Center(
        child: Text(
          isUrdu ? 'کوئی ریکارڈ نہیں ملا' : 'No record found',
          style: const TextStyle(fontSize: 16, color: Colors.grey),
        ),
      );
    }

    final List<String> predefinedOrder = [
      'حفظ',
      'گردان',
      'تجوید',
      'حسن صوت',
      'اولیٰ',
      'اولیٰ دہم',
      'اولیٰ نہم',
      'مڈل'
    ];

    // Group active students by class
    Map<String, List<Map<String, dynamic>>> classGroups = {};
    for (var student in activeList) {
      String cName =
          (student['class_name'] ?? student['class'])?.toString() ?? 'دیگر';
      if (cName.trim().isEmpty) {
        cName = 'دیگر';
      }
      classGroups.putIfAbsent(cName, () => []).add(student);
    }

    // Determine the sorted order of classes present in groups
    List<String> orderedClasses = [];
    for (String pc in predefinedOrder) {
      if (classGroups.containsKey(pc)) {
        orderedClasses.add(pc);
      }
    }
    // Add remaining classes not in predefined order
    for (var c in classGroups.keys) {
      if (!orderedClasses.contains(c)) {
        orderedClasses.add(c);
      }
    }

    List<Widget> listWidgets = [];

    // Add active classes sections
    for (String cName in orderedClasses) {
      listWidgets.add(
        Padding(
          padding: const EdgeInsets.only(top: 15, bottom: 5, left: 5, right: 5),
          child: Text(
            "${isUrdu ? 'کلاس' : 'Class'}: $cName",
            style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: Color(0xFF284D3D)),
          ),
        ),
      );
      listWidgets.add(
        Card(
          elevation: 2,
          margin: const EdgeInsets.symmetric(vertical: 5, horizontal: 0),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: _buildClassDataTable(classGroups[cName]!, isUrdu),
          ),
        ),
      );
    }

    // Add Alumni section at the end if not empty
    if (alumniList.isNotEmpty) {
      listWidgets.add(
        Padding(
          padding: const EdgeInsets.only(top: 25, bottom: 5, left: 5, right: 5),
          child: Text(
            isUrdu ? 'فارغ التحصیل' : 'Alumni',
            style: const TextStyle(
                fontSize: 18, fontWeight: FontWeight.bold, color: Colors.grey),
          ),
        ),
      );
      listWidgets.add(
        Card(
          elevation: 2,
          margin: const EdgeInsets.symmetric(vertical: 5, horizontal: 0),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: _buildClassDataTable(alumniList, isUrdu),
          ),
        ),
      );
    }

    if (filterType == 'alumni') {
      listWidgets.insert(0, _buildAlumniFilterBar(isUrdu));
    }

    return ListView(
      children: listWidgets,
    );
  }

  void _showPromoteDialog(Map<String, dynamic> student, bool isUrdu) {
    String? selectedClass;
    String selectedQuran = student['quran_edition']?.toString() ?? '16 سطری';
    if (!['13 سطری', '15 سطری', '16 سطری', '18 سطری'].contains(selectedQuran)) {
      selectedQuran = '16 سطری';
    }

    bool isQuranClass(String? cls) {
      if (cls == null) return false;
      return cls.contains('حفظ') ||
          cls.contains('ناظرہ') ||
          cls.contains('گردان');
    }

    showDialog(
        context: context,
        builder: (ctx) {
          return StatefulBuilder(builder: (context, setStateSB) {
            final showQuran = isQuranClass(selectedClass);
            return AlertDialog(
              title:
                  Text(isUrdu ? 'طالب علم کو پروموٹ کریں' : 'Promote Student'),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${student['name']} کو اگلی کلاس میں منتقل کریں'),
                  const SizedBox(height: 20),
                  DropdownButtonFormField<String>(
                    decoration: InputDecoration(
                        labelText: isUrdu
                            ? 'نئی کلاس منتخب کریں *'
                            : 'Select New Class *'),
                    value: selectedClass,
                    items: _classes
                        .map((c) => DropdownMenuItem(
                            value: c['name'].toString(),
                            child: Text(c['name'].toString())))
                        .toList(),
                    onChanged: (v) {
                      setStateSB(() {
                        selectedClass = v;
                      });
                    },
                  ),
                  if (showQuran) ...[
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      decoration: const InputDecoration(
                        labelText: 'قرآن کریم نسخہ / لائنز *',
                        prefixIcon: Icon(Icons.menu_book, color: Colors.green),
                        border: OutlineInputBorder(),
                      ),
                      value: selectedQuran,
                      items: const [
                        DropdownMenuItem(
                            value: '13 سطری',
                            child: Text('13 سطری قرآن کریم (13 Lines)')),
                        DropdownMenuItem(
                            value: '15 سطری',
                            child: Text('15 سطری قرآن کریم (15 Lines)')),
                        DropdownMenuItem(
                            value: '16 سطری',
                            child:
                                Text('16 سطری قرآن کریم (16 Lines - عام فہم)')),
                        DropdownMenuItem(
                            value: '18 سطری',
                            child: Text('18 سطری قرآن کریم (18 Lines)')),
                      ],
                      onChanged: (v) {
                        if (v != null) {
                          setStateSB(() {
                            selectedQuran = v;
                          });
                        }
                      },
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'حفظ، ناظرہ یا گردان میں پروموشن پر قرآنی نسخہ خودکار ریکارڈ میں شامل ہو جائے گا۔',
                      style:
                          TextStyle(fontSize: 11, color: Colors.green.shade800),
                    ),
                  ],
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: Text(isUrdu ? 'کینسل' : 'Cancel'),
                ),
                ElevatedButton(
                  onPressed: () async {
                    if (selectedClass != null) {
                      final updateMap = <String, dynamic>{
                        'class_name': selectedClass,
                        'class': selectedClass,
                      };
                      if (isQuranClass(selectedClass)) {
                        updateMap['quran_edition'] = selectedQuran;
                      }
                      await DBHelper()
                          .updateMaktabStudent(student['id'], updateMap);
                      if (context.mounted) {
                        Navigator.pop(ctx);
                        Navigator.pop(context); // Close details dialog
                        _refreshMaktabStudents();
                        try {
                          await SyncService.runTwoWaySync();
                        } catch (_) {}
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu
                                ? 'طالب علم پروموٹ ہو گیا'
                                : 'Student Promoted')));
                      }
                    }
                  },
                  child: Text(isUrdu ? 'پروموٹ کریں' : 'Promote'),
                )
              ],
            );
          });
        });
  }

  void _showStudentDetails(Map<String, dynamic> student, bool isUrdu) {
    showDialog(
        context: context,
        builder: (BuildContext dialogContext) {
          return AlertDialog(
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
            title: Row(
              children: [
                Icon(
                    student['status'] == 'Alumni'
                        ? Icons.history_edu
                        : Icons.person,
                    color: const Color(0xFF284D3D)),
                const SizedBox(width: 10),
                Expanded(
                    child: Text(student['name'] ?? '',
                        style: const TextStyle(fontWeight: FontWeight.bold))),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _detailTile(
                      Icons.family_restroom,
                      isUrdu ? 'ولدیت' : 'Father',
                      student['father_name'] ?? '-'),
                  _detailTile(Icons.phone, isUrdu ? 'رابطہ نمبر' : 'Contact',
                      student['contact_number'] ?? '-'),
                  _detailTile(Icons.home, isUrdu ? 'مکمل پتہ' : 'Address',
                      student['address'] ?? '-'),
                  if (student['status'] == 'Alumni') ...[
                    const Divider(),
                    _detailTile(
                        Icons.category,
                        isUrdu ? 'نوعیت / کیٹیگری' : 'Category',
                        resolveAlumniCategory(student) == 'dropped'
                            ? (isUrdu ? 'ترکِ تعلیم' : 'Dropped Out')
                            : (resolveAlumniCategory(student) == 'expelled'
                                ? (isUrdu ? 'خارج کردہ' : 'Expelled')
                                : (isUrdu ? 'تکمیلِ تعلیم' : 'Completed'))),
                    _detailTile(
                        Icons.calendar_today,
                        isUrdu
                            ? 'فراغت / اخراج کی تاریخ'
                            : 'Graduation / Leaving Date',
                        student['graduation_date'] ?? '-'),
                    _detailTile(
                        Icons.timer,
                        isUrdu ? 'تعلیمی دورانیہ' : 'Duration',
                        student['duration'] ?? '-'),
                    if (student['wifaq_exam'] == 1 ||
                        student['wifaq_exam'] == '1')
                      _detailTile(
                          Icons.grade,
                          isUrdu ? 'امتحان وفاق' : 'Wifaq Exam',
                          isUrdu
                              ? "ہاں (نمبرات: ${student['wifaq_marks']?.toString().isNotEmpty == true ? student['wifaq_marks'] : 'درج نہیں'})"
                              : "Yes (Marks: ${student['wifaq_marks']?.toString().isNotEmpty == true ? student['wifaq_marks'] : 'N/A'})"),
                    if (student['mehrab_recitation']?.toString().isNotEmpty ==
                        true)
                      _detailTile(
                          Icons.mosque,
                          isUrdu
                              ? 'محراب (تراویح میں قرآن)'
                              : 'Mehrab Recitation',
                          student['mehrab_recitation'].toString()),
                    _detailTile(
                        Icons.info_outline,
                        isUrdu ? 'وجہ فراغت / اخراج' : 'Reason',
                        student['leaving_reason'] ?? '-'),
                  ],
                ],
              ),
            ),
            actions: [
              ElevatedButton.icon(
                onPressed: () => _printIDCard(student, isUrdu),
                icon: const Icon(Icons.assignment_ind,
                    color: Colors.white, size: 18),
                label: Text(isUrdu ? "آئی ڈی کارڈ" : "ID Card",
                    style: const TextStyle(color: Colors.white)),
                style: ElevatedButton.styleFrom(backgroundColor: Colors.green),
              ),
              if (resolveAlumniCategory(student) == 'completed')
                ElevatedButton.icon(
                  onPressed: () => _issueSanad(student, isUrdu),
                  icon: const Icon(Icons.card_membership,
                      color: Colors.white, size: 18),
                  label: Text(isUrdu ? "سند جاری کریں" : "Issue Sanad",
                      style: const TextStyle(color: Colors.white)),
                  style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFFD4AC0D)),
                ),
              if (currentUserRole.value == 'admin') ...[
                IconButton(
                  icon: const Icon(Icons.edit, color: Colors.blue),
                  onPressed: () {
                    Navigator.of(dialogContext).pop();
                    _showEditMaktabStudentDialog(student);
                  },
                ),
                IconButton(
                  icon: const Icon(Icons.delete, color: Colors.red),
                  tooltip: isUrdu ? 'حذف کریں' : 'Delete',
                  onPressed: () async {
                    Navigator.of(dialogContext).pop();
                    final confirm = await showDialog<bool>(
                      context: context,
                      builder: (c) => AlertDialog(
                        title: Text(
                            isUrdu ? 'طالب علم حذف کریں' : 'Delete Student'),
                        content: Text(isUrdu
                            ? 'کیا آپ واقعی اس طالب علم کا ریکارڈ حذف کرنا چاہتے ہیں؟'
                            : 'Are you sure you want to delete this student?'),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.pop(c, false),
                            child: Text(isUrdu ? 'نہیں' : 'No'),
                          ),
                          TextButton(
                            onPressed: () => Navigator.pop(c, true),
                            child: Text(isUrdu ? 'ہاں' : 'Yes',
                                style: const TextStyle(color: Colors.red)),
                          ),
                        ],
                      ),
                    );
                    if (confirm == true) {
                      try {
                        await DBHelper().deleteMaktabStudent(student['id']);
                        await SyncService.runTwoWaySync();
                        if (mounted) {
                          _refreshMaktabStudents();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu
                                ? "✅ طالب علم حذف کر دیا گیا!"
                                : "✅ Student deleted!"),
                            backgroundColor: Colors.green,
                          ));
                        }
                      } catch (e, stackTrace) {
                        debugPrint(
                            'Error deleting maktab student: $e\nStack: $stackTrace');
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrdu
                                ? "❌ طالب علم کو حذف کرنے میں خرابی پیش آئی۔"
                                : "❌ Failed to delete student. Please try again."),
                            backgroundColor: Colors.red,
                          ));
                        }
                      }
                    }
                  },
                ),
                if ((student['status']?.toString().toLowerCase() ?? '') !=
                    'alumni')
                  IconButton(
                    icon: const Icon(Icons.upgrade, color: Colors.green),
                    tooltip: isUrdu ? 'پروموٹ کریں' : 'Promote',
                    onPressed: () {
                      Navigator.of(dialogContext).pop();
                      _showPromoteDialog(student, isUrdu);
                    },
                  ),
                IconButton(
                  icon: const Icon(Icons.school, color: Colors.orange),
                  onPressed: () {
                    Navigator.of(dialogContext).pop();
                    _showMigrateStudentDialog(student);
                  },
                ),
              ],
              ElevatedButton.icon(
                onPressed: () {
                  Navigator.of(dialogContext).pop();
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (context) => StudentReportPage(
                          forceStudentId: student['id']?.toString()),
                    ),
                  );
                },
                icon:
                    const Icon(Icons.analytics, color: Colors.white, size: 18),
                label: Text(isUrdu ? "مکمل رپورٹ دیکھیں" : "View Full Report",
                    style: const TextStyle(color: Colors.white)),
                style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF284D3D)),
              ),
              TextButton(
                  onPressed: () => Navigator.of(dialogContext).pop(),
                  child: Text(isUrdu ? "بند کریں" : "Close"))
            ],
          );
        });
  }

  Widget _detailTile(IconData icon, String title, String value) {
    return ListTile(
      leading: Icon(icon, color: Colors.grey, size: 20),
      title:
          Text(title, style: const TextStyle(fontSize: 12, color: Colors.grey)),
      subtitle: Text(value,
          style: const TextStyle(
              fontWeight: FontWeight.bold, fontSize: 14, color: Colors.black)),
      contentPadding: EdgeInsets.zero,
      dense: true,
    );
  }

  void _showEditMaktabStudentDialog(Map<String, dynamic> student) {
    final nameController =
        TextEditingController(text: student['name']?.toString() ?? '');
    final fatherNameController =
        TextEditingController(text: student['father_name']?.toString() ?? '');
    final rollNoController =
        TextEditingController(text: student['roll_number']?.toString() ?? '');
    String? localSelectedClass = student['class_name']?.toString();
    _selectedResidenceStatus = student['residence_status']?.toString();
    String selectedQuran = student['quran_edition']?.toString() ?? '16 سطری';
    if (!['13 سطری', '15 سطری', '16 سطری', '18 سطری'].contains(selectedQuran)) {
      selectedQuran = '16 سطری';
    }
    String selectedHifzSection =
        student['hifz_section']?.toString() ?? 'regular';
    if (!['regular', 'manzil_gardan'].contains(selectedHifzSection)) {
      selectedHifzSection = 'regular';
    }
    final contactController = TextEditingController(
        text: student['contact_number']?.toString() ?? '');
    final addressController =
        TextEditingController(text: student['address']?.toString() ?? '');

    // verify class exists
    if (localSelectedClass != null &&
        !_classes.any((c) => c['name'].toString() == localSelectedClass)) {
      localSelectedClass = null;
    }

    String? photoPath = student['photo']?.toString();
    if (photoPath != null && photoPath.isEmpty) {
      photoPath = null;
    }

    Future<void> pickImage(
        void Function(void Function()) setDialogState) async {
      final picker = ImagePicker();
      final picked = await picker.pickImage(
          source: ImageSource.gallery,
          imageQuality: 50,
          maxWidth: 800,
          maxHeight: 800);
      if (picked != null) {
        setDialogState(() => photoPath = picked.path);
      }
    }

    bool isSaving = false;

    showDialog(
      context: context,
      builder: (_) => StatefulBuilder(builder: (context, setDialogState) {
        return AlertDialog(
          title: Row(children: [
            const Icon(Icons.edit, color: Colors.blue),
            const SizedBox(width: 8),
            Text(isUrduGlobal.value ? "طالب علم ترمیم" : "Edit Student"),
          ]),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                GestureDetector(
                  onTap: () => pickImage(setDialogState),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      _buildPhotoAvatar(photoPath, radius: 40),
                      if (photoPath == null)
                        const Icon(Icons.camera_alt,
                            size: 30, color: Colors.grey),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                    controller: nameController,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "نام" : "Name",
                        border: const OutlineInputBorder())),
                const SizedBox(height: 10),
                TextField(
                    controller: fatherNameController,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "ولدیت" : "Father Name",
                        border: const OutlineInputBorder())),
                const SizedBox(height: 10),
                TextField(
                    controller: rollNoController,
                    decoration: InputDecoration(
                        labelText:
                            isUrduGlobal.value ? "داخلہ نمبر" : "Admission No",
                        border: const OutlineInputBorder())),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: localSelectedClass,
                  decoration: InputDecoration(
                      labelText: isUrduGlobal.value ? "کلاس" : "Class",
                      border: const OutlineInputBorder()),
                  items: _classes
                      .map((c) => DropdownMenuItem<String>(
                          value: c['name'].toString(),
                          child: Text(c['name'].toString())))
                      .toList(),
                  onChanged: (v) =>
                      setDialogState(() => localSelectedClass = v),
                ),
                if (localSelectedClass != null &&
                    (localSelectedClass!.contains('حفظ') ||
                        localSelectedClass!.contains('ناظرہ') ||
                        localSelectedClass!.contains('گردان'))) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: selectedQuran,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value
                            ? "قرآن کریم نسخہ / لائنز"
                            : "Quran Edition",
                        prefixIcon:
                            const Icon(Icons.menu_book, color: Colors.green),
                        border: const OutlineInputBorder()),
                    items: const [
                      DropdownMenuItem(
                          value: '13 سطری',
                          child: Text("13 سطری قرآن کریم (13 Lines)")),
                      DropdownMenuItem(
                          value: '15 سطری',
                          child: Text("15 سطری قرآن کریم (15 Lines)")),
                      DropdownMenuItem(
                          value: '16 سطری',
                          child:
                              Text("16 سطری قرآن کریم (16 Lines - عام فہم)")),
                      DropdownMenuItem(
                          value: '18 سطری',
                          child: Text("18 سطری قرآن کریم (18 Lines)")),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setDialogState(() => selectedQuran = v);
                      }
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: selectedHifzSection,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value
                            ? "حفظ سیکشن / زمرہ *"
                            : "Hifz Section *",
                        prefixIcon:
                            const Icon(Icons.category, color: Colors.teal),
                        border: const OutlineInputBorder()),
                    items: [
                      DropdownMenuItem(
                          value: 'regular',
                          child: Text(isUrduGlobal.value
                              ? "سیکشن A: ریگولر حفظ (مکمل وقت)"
                              : "Section A: Regular Hifz (Full Time)")),
                      DropdownMenuItem(
                          value: 'manzil_gardan',
                          child: Text(isUrduGlobal.value
                              ? "سیکشن B: منزل/گردان (پارٹ ٹائم/سکول)"
                              : "Section B: Manzil / Gardan (Part Time)")),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setDialogState(() => selectedHifzSection = v);
                      }
                    },
                  ),
                ],
                const SizedBox(height: 10),
                TextField(
                    controller: contactController,
                    decoration: InputDecoration(
                        labelText:
                            isUrduGlobal.value ? "رابطہ نمبر" : "Contact",
                        border: const OutlineInputBorder())),
                const SizedBox(height: 10),
                TextField(
                    controller: addressController,
                    decoration: InputDecoration(
                        labelText: isUrduGlobal.value ? "پتہ" : "Address",
                        border: const OutlineInputBorder())),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: isSaving ? null : () => Navigator.pop(context),
              child: Text(isUrduGlobal.value ? "کینسل" : "Cancel"),
            ),
            ElevatedButton(
              onPressed: isSaving
                  ? null
                  : () async {
                      if (nameController.text.trim().isEmpty) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "نام طالب علم لکھنا ضروری ہے!"
                              : "Student name is required!"),
                          backgroundColor: Colors.red,
                        ));
                        return;
                      }
                      setDialogState(() => isSaving = true);
                      try {
                        final updateMap = <String, dynamic>{
                          'name': nameController.text.trim(),
                          'father_name': fatherNameController.text.trim(),
                          'roll_number': rollNoController.text.trim(),
                          'class_name': localSelectedClass ?? '',
                          'residence_status': _selectedResidenceStatus ?? '',
                          'contact_number': contactController.text.trim(),
                          'address': addressController.text.trim(),
                          'photo': photoPath ?? '',
                        };
                        if (localSelectedClass != null &&
                            (localSelectedClass!.contains('حفظ') ||
                                localSelectedClass!.contains('ناظرہ') ||
                                localSelectedClass!.contains('گردان'))) {
                          updateMap['quran_edition'] = selectedQuran;
                          updateMap['hifz_section'] = selectedHifzSection;
                        }
                        await DBHelper()
                            .updateMaktabStudent(student['id'], updateMap);
                        if (context.mounted) {
                          Navigator.pop(context);
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrduGlobal.value
                                ? "✅ طالب علم کی معلومات کامیابی سے محفوظ ہو گئیں!"
                                : "✅ Student updated successfully!"),
                            backgroundColor: Colors.green,
                          ));
                        }
                        _refreshMaktabStudents();
                        SyncService.triggerBackgroundTableSync(
                            'maktab_students');
                      } catch (e) {
                        setDialogState(() => isSaving = false);
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(isUrduGlobal.value
                                ? "❌ خرابی: $e"
                                : "❌ Error: $e"),
                            backgroundColor: Colors.red,
                          ));
                        }
                      }
                    },
              style: ElevatedButton.styleFrom(
                backgroundColor: Theme.of(context).colorScheme.primary,
                foregroundColor: Colors.white,
              ),
              child: isSaving
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : Text(isUrduGlobal.value ? "محفوظ کریں" : "Save"),
            ),
          ],
        );
      }),
    );
  }

  void _showMigrateStudentDialog(Map<String, dynamic> student) {
    final isUrdu = isUrduGlobal.value;
    String selectedCategory =
        student['alumni_category']?.toString().isNotEmpty == true
            ? student['alumni_category'].toString()
            : 'completed';
    if (selectedCategory != 'completed' &&
        selectedCategory != 'dropped' &&
        selectedCategory != 'expelled') {
      selectedCategory = 'completed';
    }

    String admDateStr = student['admission_date']?.toString().isNotEmpty == true
        ? student['admission_date'].toString().substring(0, 10)
        : DateTime.now().toString().substring(0, 10);
    String gradDateStr = DateTime.now().toString().substring(0, 10);

    bool wifaqExam = student['wifaq_exam'] == 1 || student['wifaq_exam'] == '1';
    final wifaqMarksController =
        TextEditingController(text: student['wifaq_marks']?.toString() ?? '');
    String selectedMehrab =
        student['mehrab_recitation']?.toString().isNotEmpty == true
            ? student['mehrab_recitation'].toString()
            : 'ہاں';

    String defaultReasonFor(String cat) {
      if (cat == 'dropped') {
        return isUrdu
            ? 'بغیر تعلیم مکمل کیے (درمیان میں چھوڑا)'
            : 'Left without completing education';
      } else if (cat == 'expelled') {
        return isUrdu
            ? 'مدرسہ سے خارج کیا گیا (تادیبی کارروائی)'
            : 'Expelled from madrasa';
      }
      return isUrdu
          ? 'تعلیم مکمل ہونے کی وجہ سے'
          : 'Graduated / Completed education';
    }

    final reasonController = TextEditingController(
      text: student['leaving_reason']?.toString().isNotEmpty == true
          ? student['leaving_reason'].toString()
          : defaultReasonFor(selectedCategory),
    );

    String calcDurationText(String adm, String grad) {
      try {
        DateTime d1 = DateTime.parse(adm);
        DateTime d2 = DateTime.parse(grad);
        if (d2.isBefore(d1)) return isUrdu ? 'غلط تاریخ!' : 'Invalid Date';
        int days = d2.difference(d1).inDays;
        int years = days ~/ 365;
        int remDays = days % 365;
        int months = remDays ~/ 30;
        int finalDays = remDays % 30;
        String res = '';
        if (years > 0) res += '$years ${isUrdu ? 'سال' : 'Years'} ';
        if (months > 0) res += '$months ${isUrdu ? 'ماہ' : 'Months'} ';
        if (finalDays > 0) res += '$finalDays ${isUrdu ? 'دن' : 'Days'}';
        return res.trim().isEmpty ? (isUrdu ? '0 دن' : '0 Days') : res.trim();
      } catch (_) {
        return '';
      }
    }

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => StatefulBuilder(
        builder: (context, setDialogState) {
          final durationStr = calcDurationText(admDateStr, gradDateStr);

          return AlertDialog(
            title: Row(
              children: [
                const Icon(Icons.school, color: Colors.orange),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isUrdu
                        ? 'فارغ التحصیل / اخراج ریکارڈ'
                        : 'Graduation / Migration Record',
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            content: SizedBox(
              width: MediaQuery.of(context).size.width * 0.9,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade100,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.person, color: Color(0xFF284D3D)),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  student['name']?.toString() ?? '-',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 15),
                                ),
                                Text(
                                  "${isUrdu ? 'داخلہ نمبر' : 'Roll/Adm'}: ${student['admission_number'] ?? student['roll_number'] ?? '-'} | ${student['class_name'] ?? student['class'] ?? '-'}",
                                  style: const TextStyle(
                                      fontSize: 12, color: Colors.grey),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 14),

                    // کیٹیگری کا انتخاب
                    Text(
                      isUrdu ? "نوعیت / کیٹیگری:* " : "Category:*",
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                    const SizedBox(height: 6),
                    DropdownButtonFormField<String>(
                      value: selectedCategory,
                      decoration: const InputDecoration(
                        border: OutlineInputBorder(),
                        contentPadding:
                            EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      ),
                      items: [
                        DropdownMenuItem(
                          value: 'completed',
                          child: Row(
                            children: [
                              const Icon(Icons.check_circle,
                                  color: Colors.green, size: 18),
                              const SizedBox(width: 8),
                              Text(isUrdu
                                  ? '🎓 تکمیلِ تعلیم (تعلیم مکمل)'
                                  : '🎓 Completed Education'),
                            ],
                          ),
                        ),
                        DropdownMenuItem(
                          value: 'dropped',
                          child: Row(
                            children: [
                              const Icon(Icons.pause_circle,
                                  color: Colors.orange, size: 18),
                              const SizedBox(width: 8),
                              Text(isUrdu
                                  ? '⏸️ ترکِ تعلیم (درمیان میں چھوڑا)'
                                  : '⏸️ Dropped Out'),
                            ],
                          ),
                        ),
                        DropdownMenuItem(
                          value: 'expelled',
                          child: Row(
                            children: [
                              const Icon(Icons.cancel,
                                  color: Colors.red, size: 18),
                              const SizedBox(width: 8),
                              Text(isUrdu
                                  ? '❌ خارج کردہ (مدرسہ سے اخراج)'
                                  : '❌ Expelled'),
                            ],
                          ),
                        ),
                      ],
                      onChanged: (val) {
                        if (val != null) {
                          setDialogState(() {
                            selectedCategory = val;
                            reasonController.text = defaultReasonFor(val);
                          });
                        }
                      },
                    ),
                    const SizedBox(height: 12),

                    // تاریخیں
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(isUrdu ? 'تاریخ داخلہ' : 'Adm Date',
                                  style: const TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold)),
                              const SizedBox(height: 4),
                              InkWell(
                                onTap: () async {
                                  final d = await showDatePicker(
                                    context: context,
                                    initialDate:
                                        DateTime.tryParse(admDateStr) ??
                                            DateTime.now(),
                                    firstDate: DateTime(2000),
                                    lastDate: DateTime(2100),
                                  );
                                  if (d != null) {
                                    setDialogState(() => admDateStr =
                                        d.toString().substring(0, 10));
                                  }
                                },
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 8, vertical: 10),
                                  decoration: BoxDecoration(
                                    border:
                                        Border.all(color: Colors.grey.shade400),
                                    borderRadius: BorderRadius.circular(4),
                                  ),
                                  child: Row(
                                    mainAxisAlignment:
                                        MainAxisAlignment.spaceBetween,
                                    children: [
                                      Text(admDateStr,
                                          style: const TextStyle(fontSize: 12)),
                                      const Icon(Icons.calendar_today,
                                          size: 14, color: Colors.grey),
                                    ],
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(isUrdu ? 'تاریخ فراغت/اخراج' : 'Grad Date',
                                  style: const TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.green)),
                              const SizedBox(height: 4),
                              InkWell(
                                onTap: () async {
                                  final d = await showDatePicker(
                                    context: context,
                                    initialDate:
                                        DateTime.tryParse(gradDateStr) ??
                                            DateTime.now(),
                                    firstDate: DateTime(2000),
                                    lastDate: DateTime(2100),
                                  );
                                  if (d != null) {
                                    setDialogState(() => gradDateStr =
                                        d.toString().substring(0, 10));
                                  }
                                },
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 8, vertical: 10),
                                  decoration: BoxDecoration(
                                    border: Border.all(color: Colors.green),
                                    borderRadius: BorderRadius.circular(4),
                                  ),
                                  child: Row(
                                    mainAxisAlignment:
                                        MainAxisAlignment.spaceBetween,
                                    children: [
                                      Text(gradDateStr,
                                          style: const TextStyle(
                                              fontSize: 12,
                                              fontWeight: FontWeight.bold,
                                              color: Colors.green)),
                                      const Icon(Icons.calendar_today,
                                          size: 14, color: Colors.green),
                                    ],
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    // دورانیہ
                    if (durationStr.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                          children: [
                            Text(isUrdu ? "تعلیمی دورانیہ: " : "Duration: ",
                                style: const TextStyle(
                                    fontSize: 12, color: Colors.grey)),
                            Text(durationStr,
                                style: const TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.teal)),
                          ],
                        ),
                      ),
                    const SizedBox(height: 6),

                    // اگر تکمیلِ تعلیم ہو تو وفاق امتحان اور محراب کے فیلڈز
                    if (selectedCategory == 'completed') ...[
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF1F8E9),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.lightGreen.shade300),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            SwitchListTile(
                              contentPadding: EdgeInsets.zero,
                              dense: true,
                              title: Text(
                                isUrdu
                                    ? 'کیا وفاق کا امتحان دیا ہے؟'
                                    : 'Attended Wifaq Exam?',
                                style: const TextStyle(
                                    fontSize: 13, fontWeight: FontWeight.bold),
                              ),
                              value: wifaqExam,
                              activeColor: Colors.green,
                              onChanged: (val) =>
                                  setDialogState(() => wifaqExam = val),
                            ),
                            if (wifaqExam) ...[
                              TextField(
                                controller: wifaqMarksController,
                                decoration: InputDecoration(
                                  labelText: isUrdu
                                      ? 'وفاق میں حاصل کردہ نمبرات / پوزیشن'
                                      : 'Wifaq Marks / Position',
                                  labelStyle: const TextStyle(fontSize: 12),
                                  hintText: isUrdu
                                      ? 'مثال: 450 یا ممتاز'
                                      : 'e.g. 450 / Mumtaz',
                                  border: const OutlineInputBorder(),
                                  isDense: true,
                                  contentPadding: const EdgeInsets.symmetric(
                                      horizontal: 10, vertical: 8),
                                ),
                              ),
                              const SizedBox(height: 10),
                            ],
                            Text(
                              isUrdu
                                  ? 'محراب (تراویح میں قرآن سنایا؟):'
                                  : 'Mehrab (Taraweeh Quran):',
                              style: const TextStyle(
                                  fontSize: 12, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 4),
                            DropdownButtonFormField<String>(
                              value: selectedMehrab,
                              decoration: const InputDecoration(
                                border: OutlineInputBorder(),
                                isDense: true,
                                contentPadding: EdgeInsets.symmetric(
                                    horizontal: 10, vertical: 6),
                              ),
                              items: [
                                DropdownMenuItem(
                                    value: 'ہاں',
                                    child: Text(isUrdu ? 'ہاں' : 'Yes')),
                                DropdownMenuItem(
                                    value: 'نہیں',
                                    child: Text(isUrdu ? 'نہیں' : 'No')),
                                DropdownMenuItem(
                                    value: 'سنا ہے',
                                    child: Text(isUrdu
                                        ? 'سنا ہے'
                                        : 'Listened (Samia)')),
                              ],
                              onChanged: (v) {
                                if (v != null)
                                  setDialogState(() => selectedMehrab = v);
                              },
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 10),
                    ],

                    // وجہ
                    TextField(
                      controller: reasonController,
                      decoration: InputDecoration(
                        labelText: isUrdu
                            ? 'فراغت / اخراج کی وجہ / تفصیلات'
                            : 'Reason / Remarks',
                        border: const OutlineInputBorder(),
                        isDense: true,
                      ),
                      maxLines: 2,
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: Text(isUrdu ? 'کینسل' : 'Cancel'),
              ),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: selectedCategory == 'expelled'
                      ? Colors.red
                      : (selectedCategory == 'dropped'
                          ? Colors.orange
                          : const Color(0xFF284D3D)),
                ),
                onPressed: () async {
                  final sId =
                      int.tryParse(student['id']?.toString() ?? '') ?? 0;
                  if (sId <= 0) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text(isUrdu
                          ? 'خرابی: طالب علم کی درست شناختی نمبر نہیں ملا'
                          : 'Error: Invalid student ID'),
                      backgroundColor: Colors.red,
                    ));
                    return;
                  }

                  await DBHelper().updateMaktabStudent(sId, {
                    'status': 'Alumni',
                    'alumni_category': selectedCategory,
                    'admission_date': admDateStr,
                    'graduation_date': gradDateStr,
                    'duration': durationStr,
                    'leaving_reason': reasonController.text.trim(),
                    'wifaq_exam':
                        (selectedCategory == 'completed' && wifaqExam) ? 1 : 0,
                    'wifaq_marks':
                        (selectedCategory == 'completed' && wifaqExam)
                            ? wifaqMarksController.text.trim()
                            : '',
                    'mehrab_recitation':
                        selectedCategory == 'completed' ? selectedMehrab : '',
                  });

                  if (mounted) {
                    Navigator.pop(context);
                    _refreshMaktabStudents();
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text(isUrdu
                          ? '✅ طالب علم کا ریکارڈ کامیابی سے فارغ التحصیل / خارج میں محفوظ ہو گیا!'
                          : '✅ Student migration record saved successfully!'),
                      backgroundColor: Colors.green,
                    ));
                  }

                  SyncService.triggerBackgroundTableSync('maktab_students');
                },
                icon: const Icon(Icons.check, color: Colors.white, size: 18),
                label: Text(
                  isUrdu ? 'محفوظ کریں' : 'Save',
                  style: const TextStyle(
                      color: Colors.white, fontWeight: FontWeight.bold),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _issueSanad(Map<String, dynamic> student, bool isUrdu) async {
    if (resolveAlumniCategory(student) != 'completed') {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(isUrdu
            ? 'سند صرف تکمیلِ تعلیم کے حامل طلبہ کو جاری کی جا سکتی ہے!'
            : 'Sanad can only be issued to completed alumni!'),
        backgroundColor: Colors.red,
      ));
      return;
    }
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => SanadPreviewScreen(student: student),
      ),
    );
  }

  Future<void> _printIDCard(Map<String, dynamic> student, bool isUrdu) async {
    final ByteData logoData = await rootBundle.load('assets/logo.png');
    final Uint8List logoBytes = logoData.buffer.asUint8List();

    Uint8List? photoBytes;
    try {
      final photoPath = student['photo']?.toString() ?? '';
      if (photoPath.isNotEmpty) {
        if (photoPath.startsWith('http')) {
          final response = await http.get(Uri.parse(photoPath));
          if (response.statusCode == 200) photoBytes = response.bodyBytes;
        } else if (File(photoPath).existsSync()) {
          photoBytes = File(photoPath).readAsBytesSync();
        } else {
          String fullUrl = apiUrl;
          if (fullUrl.endsWith('api.php')) {
            fullUrl = fullUrl.substring(0, fullUrl.length - 7);
          }
          if (!fullUrl.endsWith('/')) fullUrl += '/';
          if (photoPath.startsWith('uploads/')) {
            fullUrl += photoPath;
          } else if (!photoPath.contains('/')) {
            fullUrl += "uploads/maktab_students/$photoPath";
          } else {
            fullUrl += photoPath;
          }
          final response = await http.get(Uri.parse(fullUrl));
          if (response.statusCode == 200) photoBytes = response.bodyBytes;
        }
      }
    } catch (_) {}

    Uint8List? sigBytes;
    try {
      final sMap = await DBHelper().getSettings();
      final stampPath = sMap['signature_stamp_path']?.toString() ?? '';
      if (stampPath.isNotEmpty) {
        if (stampPath.startsWith('http')) {
          final res = await http.get(Uri.parse(stampPath));
          if (res.statusCode == 200) sigBytes = res.bodyBytes;
        } else if (File(stampPath).existsSync()) {
          sigBytes = File(stampPath).readAsBytesSync();
        } else {
          String fullUrl = apiUrl;
          if (fullUrl.endsWith('api.php')) {
            fullUrl = fullUrl.substring(0, fullUrl.length - 7);
          }
          if (!fullUrl.endsWith('/')) fullUrl += '/';
          fullUrl += stampPath;
          final res = await http.get(Uri.parse(fullUrl));
          if (res.statusCode == 200) sigBytes = res.bodyBytes;
        }
      }
    } catch (_) {}

    final String stdName = (student['name'] ?? '').toString();
    final String fName = (student['father_name'] ?? '').toString();
    final String admNo = (student['roll_number'] ??
            student['admission_number'] ??
            student['roll_no'] ??
            '')
        .toString();
    final String clsName = (student['class_name'] ??
            student['class'] ??
            student['current_class'] ??
            '')
        .toString();
    final DateTime now = DateTime.now();
    final String issueDate =
        "${now.day.toString().padLeft(2, '0')}-${now.month.toString().padLeft(2, '0')}-${now.year}";
    final DateTime nextYear = DateTime(now.year + 1, now.month, now.day);
    final String expiryDate =
        "${nextYear.day.toString().padLeft(2, '0')}-${nextYear.month.toString().padLeft(2, '0')}-${nextYear.year}";

    // Standard CR-80 card dimensions in logical pixels: 337.5 x 212.5 (3.375" x 2.125")
    final Widget idCardWidget = Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Container(
        width: 337.5,
        height: 212.5,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: const Color(0xFF284D3D), width: 1.5),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          children: [
            // ===== ہیڈر: بڑا لوگو (40px) اور بڑا نام (22px) =====
            Container(
              height: 52,
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              color: const Color(0xFF284D3D),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Image.memory(logoBytes,
                      width: 40,
                      height: 40,
                      color: Colors.white,
                      colorBlendMode: BlendMode.srcIn),
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          isUrdu
                              ? "مکتب اشرف المدارس فروکہ"
                              : "Maktab Ashraf-ul-Madaris",
                          style: const TextStyle(
                            fontFamily: 'JameelNooriNastaleeq',
                            fontSize: 22,
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                          ),
                          textDirection:
                              isUrdu ? TextDirection.rtl : TextDirection.ltr,
                          textAlign: TextAlign.center,
                        ),
                        Text(
                          "شناختی کارڈ — ID Card",
                          style: const TextStyle(
                            fontFamily: 'JameelNooriNastaleeq',
                            fontSize: 9,
                            color: Color(0xFFD4AC0D),
                            fontWeight: FontWeight.bold,
                          ),
                          textDirection: TextDirection.rtl,
                        ),
                      ],
                    ),
                  ),
                  Image.memory(logoBytes,
                      width: 40,
                      height: 40,
                      color: Colors.white,
                      colorBlendMode: BlendMode.srcIn),
                ],
              ),
            ),
            const SizedBox(height: 3),
            // ===== مرکزی باڈی =====
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 7),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ===== تصویر و دستخط کالم =====
                    SizedBox(
                      width: 66,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.start,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          // تصویر
                          Container(
                            width: 62,
                            height: 72,
                            decoration: BoxDecoration(
                              border: Border.all(
                                  color: const Color(0xFF284D3D), width: 1.5),
                              borderRadius: BorderRadius.circular(4),
                              color: Colors.grey.shade100,
                            ),
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(2.5),
                              child: photoBytes != null
                                  ? Image.memory(photoBytes, fit: BoxFit.cover)
                                  : Center(
                                      child: Icon(Icons.person,
                                          size: 36,
                                          color: Colors.grey.shade400),
                                    ),
                            ),
                          ),
                          const SizedBox(height: 2),
                          // "دستخط جاری کنندہ" — فونٹ سائز 50٪ کم
                          Text(
                            isUrdu ? "دستخط جاری کنندہ" : "Issuer Signature",
                            style: const TextStyle(
                                fontFamily: 'JameelNooriNastaleeq',
                                fontSize: 4.8,
                                color: Colors.black87,
                                fontWeight: FontWeight.bold),
                            textAlign: TextAlign.center,
                            textDirection: TextDirection.rtl,
                          ),
                          Expanded(
                            child: Center(
                              child: sigBytes != null
                                  ? Image.memory(sigBytes,
                                      height: 16, fit: BoxFit.contain)
                                  : const SizedBox.shrink(),
                            ),
                          ),
                          // دستخط کی لکیر
                          Container(
                            width: 62,
                            height: 1,
                            color: Colors.black87,
                          ),
                          const SizedBox(height: 3),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    // ===== تفصیلات — 5 rows (واٹر مارک لوگو 22% اوپیسٹی کے ساتھ) =====
                    Expanded(
                      child: Directionality(
                        textDirection:
                            isUrdu ? TextDirection.rtl : TextDirection.ltr,
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            // واٹر مارک لوگو (22% اوپیسٹی)
                            Opacity(
                              opacity: 0.22,
                              child: Image.memory(
                                logoBytes,
                                width: 75,
                                height: 75,
                                fit: BoxFit.contain,
                              ),
                            ),
                            Column(
                              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _idRow(
                                    isUrdu ? "نام:" : "Name:", stdName, isUrdu),
                                _idRow(isUrdu ? "ولدیت:" : "Father:", fName,
                                    isUrdu),
                                _idRow(isUrdu ? "داخلہ نمبر:" : "Adm No:",
                                    admNo, isUrdu),
                                _idRow(isUrdu ? "کلاس:" : "Class:", clsName,
                                    isUrdu),
                                _datesRow(issueDate, expiryDate, isUrdu),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 2),
            // ===== فوٹر: 26px اونچائی، ٹیکسٹ سینٹرڈ =====
            Container(
              height: 26,
              color: const Color(0xFFD4AC0D),
              alignment: Alignment.center,
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: Transform.translate(
                offset: const Offset(0, -1.5),
                child: Text(
                  isUrdu
                      ? "تعلیم و تربیت کا گہوارہ"
                      : "Education & Training Center",
                  style: const TextStyle(
                    fontFamily: 'JameelNooriNastaleeq',
                    fontSize: 9.5,
                    color: Color(0xFF284D3D),
                    fontWeight: FontWeight.bold,
                  ),
                  textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
          ],
        ),
      ),
    );

    // Capture the widget with exact targetSize and high pixelRatio
    final Uint8List imageBytes = await screenshotController.captureFromWidget(
      Material(
        color: Colors.white,
        child: idCardWidget,
      ),
      targetSize: const Size(337.5, 212.5),
      pixelRatio: 3.0,
      delay: const Duration(milliseconds: 150),
      context: context,
    );

    // Create PDF with the captured image
    final pdf = pw.Document();
    pdf.addPage(
      pw.Page(
        pageFormat: const PdfPageFormat(
            3.375 * PdfPageFormat.inch, 2.125 * PdfPageFormat.inch,
            marginAll: 0),
        build: (pw.Context ctx) {
          return pw.FullPage(
            ignoreMargins: true,
            child: pw.Center(
              child:
                  pw.Image(pw.MemoryImage(imageBytes), fit: pw.BoxFit.contain),
            ),
          );
        },
      ),
    );

    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name:
          'ID_Card_${(student["id"] ?? "student").toString().replaceAll(RegExp(r'[^a-zA-Z0-9.-]'), '')}.pdf',
    );
  }

  Widget _idRow(String label, String value, bool isUrdu) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 0.5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 52,
            child: Text(
              label,
              style: const TextStyle(
                  fontFamily: 'JameelNooriNastaleeq',
                  fontSize: 9.5,
                  fontWeight: FontWeight.bold),
              softWrap: false,
              maxLines: 1,
            ),
          ),
          Expanded(
            child: Text(
              value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  fontFamily: 'JameelNooriNastaleeq', fontSize: 10),
            ),
          ),
        ],
      ),
    );
  }

  Widget _datesRow(String issueDate, String expiryDate, bool isUrdu) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 1),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: Color(0xFFDDDDDD), width: 0.5)),
      ),
      child: Row(
        children: [
          Text(
            isUrdu ? "اجراء: " : "Issue: ",
            style: const TextStyle(
                fontFamily: 'JameelNooriNastaleeq',
                fontSize: 7,
                fontWeight: FontWeight.bold,
                color: Color(0xFF444444)),
          ),
          Text(
            issueDate,
            style: const TextStyle(
                fontFamily: 'JameelNooriNastaleeq',
                fontSize: 7,
                fontWeight: FontWeight.bold,
                color: Color(0xFF284D3D)),
          ),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 3),
            child: Text("|", style: TextStyle(fontSize: 7, color: Colors.grey)),
          ),
          Text(
            isUrdu ? "تنسیخ: " : "Exp: ",
            style: const TextStyle(
                fontFamily: 'JameelNooriNastaleeq',
                fontSize: 7,
                fontWeight: FontWeight.bold,
                color: Color(0xFF444444)),
          ),
          Text(
            expiryDate,
            style: const TextStyle(
                fontFamily: 'JameelNooriNastaleeq',
                fontSize: 7,
                fontWeight: FontWeight.bold,
                color: Color(0xFFB91C1C)),
          ),
        ],
      ),
    );
  }
}
