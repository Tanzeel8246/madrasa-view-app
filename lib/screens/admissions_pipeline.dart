import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class AdmissionsPipelineScreen extends StatefulWidget {
  const AdmissionsPipelineScreen({super.key});

  @override
  State<AdmissionsPipelineScreen> createState() =>
      _AdmissionsPipelineScreenState();
}

class _AdmissionsPipelineScreenState extends State<AdmissionsPipelineScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _allInquiries = [];
  String _searchQuery = '';
  List<String> _classesList = [];

  final List<Map<String, dynamic>> _stageDefs = [
    {
      'key': 'Pending',
      'label': 'موصولہ',
      'color': Color(0xFF0288D1),
      'icon': Icons.inbox
    },
    {
      'key': 'Interviewed',
      'label': 'انٹرویو',
      'color': Color(0xFFF57C00),
      'icon': Icons.record_voice_over
    },
    {
      'key': 'Accepted',
      'label': 'منظور شدہ',
      'color': Color(0xFF2E7D32),
      'icon': Icons.check_circle
    },
    {
      'key': 'Rejected',
      'label': 'مسترد',
      'color': Color(0xFFC62828),
      'icon': Icons.cancel
    },
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _stageDefs.length, vsync: this);
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

    // Load classes
    final db = await _dbHelper.database;
    final classTable = isMasjid ? 'maktab_classes' : 'classes';
    List<String> cls = [];
    try {
      final rows = await db.query(classTable,
          columns: ['name'],
          where: "status = 'Active' OR status = '' OR status IS NULL");
      cls = rows.map((r) => r['name']?.toString() ?? '').where((n) => n.isNotEmpty).toList();
    } catch (_) {}
    if (cls.isEmpty) {
      cls = isMasjid
          ? ['قاعدہ', 'ناظرہ', 'حفظ', 'ترجمہ']
          : ['حفظ', 'ناظرہ', 'تجوید', 'اولیٰ', 'ثانیہ', 'ثالثہ'];
    }

    final inq = await _dbHelper.getAdmissionInquiries(
      systemType: sys,
      search: _searchQuery.isNotEmpty ? _searchQuery : null,
    );

    setState(() {
      _classesList = cls;
      _allInquiries = inq;
      _isLoading = false;
    });
  }

  List<Map<String, dynamic>> _getItemsForStage(String stageKey) {
    return _allInquiries.where((item) {
      final st = item['status']?.toString() ?? 'Pending';
      return st == stageKey;
    }).toList();
  }

  Widget _buildPhotoAvatar(String? photo, {double radius = 24}) {
    if (photo == null || photo.trim().isEmpty) {
      return CircleAvatar(
        radius: radius,
        backgroundColor: Colors.grey.shade300,
        child: Icon(Icons.person, size: radius * 1.2, color: Colors.grey.shade600),
      );
    }
    final p = photo.trim();
    if (p.startsWith('http://') || p.startsWith('https://')) {
      return CircleAvatar(
        radius: radius,
        backgroundColor: Colors.grey.shade200,
        backgroundImage: NetworkImage(p),
      );
    }
    try {
      final file = File(p);
      if (file.existsSync()) {
        return CircleAvatar(
          radius: radius,
          backgroundColor: Colors.grey.shade200,
          backgroundImage: FileImage(file),
        );
      }
    } catch (_) {}

    String base = apiUrl;
    if (base.endsWith('api.php')) base = base.substring(0, base.length - 7);
    if (!base.endsWith('/')) base += '/';
    String cleanPath = p.startsWith('/') ? p.substring(1) : p;
    if (!cleanPath.startsWith('uploads/')) cleanPath = 'uploads/students/$cleanPath';
    return CircleAvatar(
      radius: radius,
      backgroundColor: Colors.grey.shade200,
      backgroundImage: NetworkImage('$base$cleanPath'),
    );
  }

  void _showAddInquiryDialog() {
    final nameCtrl = TextEditingController();
    final fNameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final addrCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    String selectedClass = _classesList.isNotEmpty ? _classesList.first : 'حفظ';
    String selectedQuranEdition = '16 سطری';
    String selectedResidence = 'residential_aided';
    String? photoPath;

    bool isQuranClass(String cls) {
      return cls.contains('حفظ') || cls.contains('ناظرہ') || cls.contains('گردان');
    }

    Future<void> pickImage(void Function(void Function()) setDlgState) async {
      try {
        final picker = ImagePicker();
        final picked = await picker.pickImage(
          source: ImageSource.gallery,
          imageQuality: 60,
          maxWidth: 800,
          maxHeight: 800,
        );
        if (picked != null) {
          setDlgState(() => photoPath = picked.path);
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('خرابی تصویر: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Icon(Icons.person_add, color: primaryGreen),
              const SizedBox(width: 8),
              const Text('نئی داخلہ درخواست',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Center(
                  child: GestureDetector(
                    onTap: () => pickImage(setDlgState),
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        _buildPhotoAvatar(photoPath, radius: 40),
                        if (photoPath == null)
                          Container(
                            padding: const EdgeInsets.all(6),
                            decoration: const BoxDecoration(
                              color: Colors.black45,
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.camera_alt, size: 22, color: Colors.white),
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'طالب علم کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: fNameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'والد کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'رابطہ نمبر / موبائل',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: selectedClass,
                  decoration: const InputDecoration(
                    labelText: 'مطلوبہ کلاس',
                    border: OutlineInputBorder(),
                  ),
                  items: _classesList
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => selectedClass = v);
                  },
                ),
                if (isQuranClass(selectedClass)) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: selectedQuranEdition,
                    decoration: const InputDecoration(
                      labelText: 'قرآن کریم نسخہ / لائنز',
                      prefixIcon: Icon(Icons.menu_book, color: Colors.green),
                      border: OutlineInputBorder(),
                    ),
                    items: const [
                      DropdownMenuItem(
                          value: '13 سطری',
                          child: Text('13 سطری قرآن کریم (13 Lines)')),
                      DropdownMenuItem(
                          value: '15 سطری',
                          child: Text('15 سطری قرآن کریم (15 Lines)')),
                      DropdownMenuItem(
                          value: '16 سطری',
                          child: Text('16 سطری قرآن کریم (16 Lines - عام فہم)')),
                      DropdownMenuItem(
                          value: '18 سطری',
                          child: Text('18 سطری قرآن کریم (18 Lines)')),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setDlgState(() => selectedQuranEdition = v);
                      }
                    },
                  ),
                ],
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: selectedResidence,
                  decoration: const InputDecoration(
                    labelText: 'رہائشی حیثیت',
                    prefixIcon: Icon(Icons.home_outlined),
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'residential_aided', child: Text("اقامتی، امدادی")),
                    DropdownMenuItem(value: 'non_residential_non_aided', child: Text("غیر اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'residential_non_aided', child: Text("اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'non_residential_aided', child: Text("غیر اقامتی، امدادی")),
                  ],
                  onChanged: (v) {
                    if (v != null) setDlgState(() => selectedResidence = v);
                  },
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: addrCtrl,
                  decoration: const InputDecoration(
                    labelText: 'پتہ / علاقہ',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: noteCtrl,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'ابتدائی نوٹس / ریمارکس',
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
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final sName = nameCtrl.text.trim();
                final fName = fNameCtrl.text.trim();
                if (sName.isEmpty || fName.isEmpty) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('نام اور ولدیت لازمی ہیں!')),
                  );
                  return;
                }
                Navigator.pop(ctx);
                await _dbHelper.insertAdmissionInquiry({
                  'student_name': sName,
                  'father_name': fName,
                  'phone': phoneCtrl.text.trim(),
                  'class_applied': selectedClass,
                  'quran_edition': isQuranClass(selectedClass) ? selectedQuranEdition : '16 سطری',
                  'residence_status': selectedResidence,
                  'photo': photoPath ?? '',
                  'address': addrCtrl.text.trim(),
                  'admin_notes': noteCtrl.text.trim(),
                  'status': 'Pending',
                  'system_type': SystemTypeGlobal.currentSystemType.value,
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

  void _showDirectEnrollDialog() {
    final nameCtrl = TextEditingController();
    final fNameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final admNoCtrl = TextEditingController();
    final addrCtrl = TextEditingController();
    String selectedClass = _classesList.isNotEmpty ? _classesList.first : 'حفظ';
    String residenceStatus = 'residential_aided';
    String selectedQuranEdition = '16 سطری';
    String? photoPath;

    bool isQuranClass(String cls) {
      return cls.contains('حفظ') || cls.contains('ناظرہ') || cls.contains('گردان');
    }

    Future<void> pickImage(void Function(void Function()) setDlgState) async {
      try {
        final picker = ImagePicker();
        final picked = await picker.pickImage(
          source: ImageSource.gallery,
          imageQuality: 60,
          maxWidth: 800,
          maxHeight: 800,
        );
        if (picked != null) {
          setDlgState(() => photoPath = picked.path);
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('خرابی تصویر: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Icon(Icons.person_add_alt_1, color: Colors.teal.shade700),
              const SizedBox(width: 8),
              const Text('ڈائریکٹ طالب علم داخلہ',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Center(
                  child: GestureDetector(
                    onTap: () => pickImage(setDlgState),
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        _buildPhotoAvatar(photoPath, radius: 40),
                        if (photoPath == null)
                          Container(
                            padding: const EdgeInsets.all(6),
                            decoration: const BoxDecoration(
                              color: Colors.black45,
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.camera_alt, size: 22, color: Colors.white),
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'طالب علم کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: fNameCtrl,
                  decoration: const InputDecoration(
                    labelText: 'والد کا نام *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'رابطہ نمبر / موبائل',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: selectedClass,
                  decoration: const InputDecoration(
                    labelText: 'کلاس *',
                    border: OutlineInputBorder(),
                  ),
                  items: _classesList
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => selectedClass = v);
                  },
                ),
                if (isQuranClass(selectedClass)) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: selectedQuranEdition,
                    decoration: const InputDecoration(
                      labelText: 'قرآن کریم نسخہ / لائنز',
                      prefixIcon: Icon(Icons.menu_book, color: Colors.green),
                      border: OutlineInputBorder(),
                    ),
                    items: const [
                      DropdownMenuItem(
                          value: '13 سطری',
                          child: Text('13 سطری قرآن کریم (13 Lines)')),
                      DropdownMenuItem(
                          value: '15 سطری',
                          child: Text('15 سطری قرآن کریم (15 Lines)')),
                      DropdownMenuItem(
                          value: '16 سطری',
                          child: Text('16 سطری قرآن کریم (16 Lines - عام فہم)')),
                      DropdownMenuItem(
                          value: '18 سطری',
                          child: Text('18 سطری قرآن کریم (18 Lines)')),
                    ],
                    onChanged: (v) {
                      if (v != null) {
                        setDlgState(() => selectedQuranEdition = v);
                      }
                    },
                  ),
                ],
                const SizedBox(height: 10),
                TextField(
                  controller: admNoCtrl,
                  decoration: const InputDecoration(
                    labelText: 'داخلہ نمبر (اختیاری)',
                    hintText: 'خالی چھوڑنے پر خودکار',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: residenceStatus,
                  decoration: const InputDecoration(
                    labelText: 'رہائشی حیثیت',
                    prefixIcon: Icon(Icons.home_outlined),
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'residential_aided', child: Text("اقامتی، امدادی")),
                    DropdownMenuItem(value: 'non_residential_non_aided', child: Text("غیر اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'residential_non_aided', child: Text("اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'non_residential_aided', child: Text("غیر اقامتی، امدادی")),
                  ],
                  onChanged: (v) {
                    if (v != null) setDlgState(() => residenceStatus = v);
                  },
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: addrCtrl,
                  decoration: const InputDecoration(
                    labelText: 'پتہ / علاقہ',
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
                backgroundColor: Colors.teal.shade700,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final sName = nameCtrl.text.trim();
                final fName = fNameCtrl.text.trim();
                if (sName.isEmpty || fName.isEmpty) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('نام اور ولدیت لازمی ہیں!')),
                  );
                  return;
                }
                Navigator.pop(ctx);
                await _dbHelper.directEnrollStudent({
                  'name': sName,
                  'father_name': fName,
                  'contact_number': phoneCtrl.text.trim(),
                  'class': selectedClass,
                  'admission_number': admNoCtrl.text.trim(),
                  'quran_edition': isQuranClass(selectedClass) ? selectedQuranEdition : '16 سطری',
                  'residence_status': residenceStatus,
                  'photo': photoPath ?? '',
                  'address': addrCtrl.text.trim(),
                  'system_type': SystemTypeGlobal.currentSystemType.value,
                });
                _loadData();
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text('طالب علم $sName باقاعدہ داخل کر لیا گیا!'),
                    backgroundColor: Colors.green,
                  ),
                );
              },
              child: const Text('داخلہ مکمل کریں'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _sendWhatsAppInterviewNotice(
      String sName, String fName, String phone, String dateStr, String timeStr) async {
    final rawPhone = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (rawPhone.isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('امیدوار کا فون نمبر موجود نہیں ہے!'),
            backgroundColor: Colors.orange,
          ),
        );
      }
      return;
    }
    final cleanPhone = rawPhone.startsWith('0') ? '92${rawPhone.substring(1)}' : rawPhone;
    final msg =
        "السلام علیکم ورحمۃ اللہ!\nمحترم $sName ولد $fName کی داخلہ درخواست برائے جامعہ اشرف المدارس پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔\n📅 تاریخ: $dateStr\n⏰ وقت: $timeStr\n📍 ادارہ: جامعہ اشرف المدارس، فروکہ\nبراہ کرم مقررہ تاریخ اور وقت پر اصل اسناد و شناختی دستاویزات کے ہمراہ تشریف لائیں۔ شکریہ!";
    final url = Uri.parse("https://wa.me/$cleanPhone?text=${Uri.encodeComponent(msg)}");
    try {
      await launchUrl(url, mode: LaunchMode.externalApplication);
    } catch (_) {
      await launchUrl(url);
    }
  }

  void _showInterviewDialog(Map<String, dynamic> item) {
    final int id = item['id'];
    final sName = item['student_name']?.toString() ?? '';
    final fName = item['father_name']?.toString() ?? '';
    final phone = item['phone']?.toString() ?? '';
    final notesCtrl =
        TextEditingController(text: item['interview_notes']?.toString() ?? '');
    String dateStr = item['interview_date']?.toString() ??
        DateTime.now().toIso8601String().substring(0, 10);
    String timeStr = '09:00 AM';
    String selectedStage = item['status']?.toString() ?? 'Interviewed';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Text('انٹرویو و ٹیسٹ شیڈول: $sName بن $fName',
              style: const TextStyle(fontFamily: 'JameelNoori', fontSize: 18)),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.calendar_today, color: Colors.blue),
                  title: const Text('انٹرویو تاریخ'),
                  subtitle: Text(dateStr),
                  trailing: TextButton(
                    child: const Text('تبدیل کریں'),
                    onPressed: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.tryParse(dateStr) ?? DateTime.now(),
                        firstDate: DateTime(2020),
                        lastDate: DateTime(2035),
                      );
                      if (picked != null) {
                        setDlgState(() =>
                            dateStr = picked.toIso8601String().substring(0, 10));
                      }
                    },
                  ),
                ),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.access_time, color: Colors.orange),
                  title: const Text('انٹرویو / ٹیسٹ وقت'),
                  subtitle: Text(timeStr),
                  trailing: TextButton(
                    child: const Text('تبدیل کریں'),
                    onPressed: () async {
                      final picked = await showTimePicker(
                        context: context,
                        initialTime: const TimeOfDay(hour: 9, minute: 0),
                      );
                      if (picked != null) {
                        setDlgState(() => timeStr = picked.format(context));
                      }
                    },
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: notesCtrl,
                  maxLines: 3,
                  decoration: const InputDecoration(
                    labelText: 'انٹرویو ریمارکس / تاثرات',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: selectedStage,
                  decoration: const InputDecoration(
                    labelText: 'مرحلہ منتخب کریں',
                    border: OutlineInputBorder(),
                  ),
                  items: _stageDefs
                      .map((s) => DropdownMenuItem<String>(
                            value: s['key'],
                            child: Text(s['label']),
                          ))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => selectedStage = v);
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
                Navigator.pop(ctx);
                await _dbHelper.updateAdmissionInquiry(id, {
                  'interview_date': dateStr,
                  'interview_notes': '$timeStr - ${notesCtrl.text.trim()}',
                  'status': selectedStage,
                });
                _loadData();
              },
              child: const Text('صرف محفوظ کریں'),
            ),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF25D366),
                foregroundColor: Colors.white,
              ),
              icon: const Icon(Icons.send, size: 16),
              label: const Text('واٹس ایپ نوٹس'),
              onPressed: () async {
                Navigator.pop(ctx);
                await _dbHelper.updateAdmissionInquiry(id, {
                  'interview_date': dateStr,
                  'interview_notes': '$timeStr - ${notesCtrl.text.trim()}',
                  'status': 'Interviewed',
                });
                _loadData();
                await _sendWhatsAppInterviewNotice(sName, fName, phone, dateStr, timeStr);
              },
            ),
          ],
        ),
      ),
    );
  }

  void _confirmReject(Map<String, dynamic> item) {
    final int id = item['id'];
    final sName = item['student_name']?.toString() ?? '';
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.warning, color: Colors.red),
            SizedBox(width: 8),
            Text('درخواست مسترد کریں؟', style: TextStyle(fontFamily: 'JameelNoori', fontSize: 18)),
          ],
        ),
        content: Text('کیا آپ واقعی $sName کی داخلہ درخواست مسترد کرنا چاہتے ہیں؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('منسوخ'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red,
              foregroundColor: Colors.white,
            ),
            onPressed: () async {
              Navigator.pop(ctx);
              await _dbHelper.updateAdmissionInquiry(id, {'status': 'Rejected'});
              _loadData();
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('$sName کی درخواست مسترد کر دی گئی'), backgroundColor: Colors.red),
              );
            },
            child: const Text('مسترد کریں'),
          ),
        ],
      ),
    );
  }

  void _showEnrollDialog(Map<String, dynamic> item) {
    final int id = item['id'];
    final sName = item['student_name']?.toString() ?? '';
    final fName = item['father_name']?.toString() ?? '';
    final appliedCls = item['class_applied']?.toString() ?? '';
    String selectedClass = _classesList.contains(appliedCls)
        ? appliedCls
        : (_classesList.isNotEmpty ? _classesList.first : 'حفظ');
    final admNoCtrl = TextEditingController();
    String residence = item['residence_status']?.toString() ?? 'residential_aided';
    if (!['residential_aided', 'non_residential_non_aided', 'residential_non_aided', 'non_residential_aided'].contains(residence)) {
      if (residence.contains('رہائشی')) {
        residence = 'residential_aided';
      } else {
        residence = 'non_residential_non_aided';
      }
    }
    String selectedQuran = item['quran_edition']?.toString() ?? '16 سطری';
    if (!['13 سطری', '15 سطری', '16 سطری', '18 سطری'].contains(selectedQuran)) {
      selectedQuran = '16 سطری';
    }

    bool isQuranClass(String cls) {
      return cls.contains('حفظ') || cls.contains('ناظرہ') || cls.contains('گردان');
    }

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              const Icon(Icons.school, color: Colors.green),
              const SizedBox(width: 8),
              const Text('باقاعدہ طالب علم بنائیں',
                  style: TextStyle(fontFamily: 'JameelNoori', fontSize: 18)),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.green.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.green.shade200),
                  ),
                  child: Text(
                    'طالب علم: $sName بن $fName\nیہ ریکارڈ مراحلِ داخلہ سے باقاعدہ طلبہ رجسٹر میں شامل ہو جائے گا۔',
                    style: TextStyle(
                        fontSize: 13, color: Colors.green.shade900, height: 1.4),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: selectedClass,
                  decoration: const InputDecoration(
                    labelText: 'مقررہ کلاس *',
                    border: OutlineInputBorder(),
                  ),
                  items: _classesList
                      .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) {
                    if (v != null) setDlgState(() => selectedClass = v);
                  },
                ),
                if (isQuranClass(selectedClass)) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: selectedQuran,
                    decoration: const InputDecoration(
                      labelText: 'قرآن کریم نسخہ / لائنز *',
                      prefixIcon: Icon(Icons.menu_book, color: Colors.green),
                      border: OutlineInputBorder(),
                    ),
                    items: const [
                      DropdownMenuItem(
                          value: '13 سطری',
                          child: Text('13 سطری قرآن کریم (13 Lines)')),
                      DropdownMenuItem(
                          value: '15 سطری',
                          child: Text('15 سطری قرآن کریم (15 Lines)')),
                      DropdownMenuItem(
                          value: '16 سطری',
                          child: Text('16 سطری قرآن کریم (16 Lines - عام فہم)')),
                      DropdownMenuItem(
                          value: '18 سطری',
                          child: Text('18 سطری قرآن کریم (18 Lines)')),
                    ],
                    onChanged: (v) {
                      if (v != null) setDlgState(() => selectedQuran = v);
                    },
                  ),
                ],
                const SizedBox(height: 10),
                TextField(
                  controller: admNoCtrl,
                  decoration: const InputDecoration(
                    labelText: 'داخلہ نمبر (خالی پر خودکار)',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: residence,
                  decoration: const InputDecoration(
                    labelText: 'رہائشی حیثیت',
                    prefixIcon: Icon(Icons.home_outlined),
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'residential_aided', child: Text("اقامتی، امدادی")),
                    DropdownMenuItem(value: 'non_residential_non_aided', child: Text("غیر اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'residential_non_aided', child: Text("اقامتی، غیر امدادی")),
                    DropdownMenuItem(value: 'non_residential_aided', child: Text("غیر اقامتی، امدادی")),
                  ],
                  onChanged: (v) {
                    if (v != null) setDlgState(() => residence = v);
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
                backgroundColor: Colors.green,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                Navigator.pop(ctx);
                await _dbHelper.enrollInquiryToStudent(
                  id,
                  selectedClass,
                  customAdmNo:
                      admNoCtrl.text.trim().isNotEmpty ? admNoCtrl.text.trim() : null,
                  residenceStatus: residence,
                  quranEdition: isQuranClass(selectedClass) ? selectedQuran : '16 سطری',
                );
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                      content: Text('$sName کو باقاعدہ داخل کر لیا گیا!')),
                );
                _loadData();
              },
              child: const Text('داخلہ مکمل کریں'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildInquiryCard(Map<String, dynamic> item) {
    final int id = item['id'];
    final sName = item['student_name']?.toString() ?? '';
    final fName = item['father_name']?.toString() ?? '';
    final phone = item['phone']?.toString() ?? '';
    final cls = item['class_applied']?.toString() ?? 'غیر متعین';
    final intDate = item['interview_date']?.toString() ?? '';
    final intNotes = item['interview_notes']?.toString() ?? '';
    final isConverted = item['converted_student_id'] != null;
    final status = item['status']?.toString() ?? 'Pending';
    final photo = item['photo']?.toString();

    return Card(
      elevation: 2,
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _buildPhotoAvatar(photo, radius: 24),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              sName,
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                          Chip(
                            label: Text(cls, style: const TextStyle(fontSize: 11)),
                            backgroundColor: Colors.grey.shade100,
                            padding: EdgeInsets.zero,
                            visualDensity: VisualDensity.compact,
                          ),
                        ],
                      ),
                      Text('ولدیت: $fName',
                          style: TextStyle(color: Colors.grey.shade700, fontSize: 13)),
                    ],
                  ),
                ),
              ],
            ),
            if (phone.isNotEmpty) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  const Icon(Icons.phone, size: 14, color: Colors.green),
                  const SizedBox(width: 4),
                  Text(phone,
                      style: const TextStyle(fontSize: 13, color: Colors.blue)),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.call, size: 18, color: Colors.green),
                    onPressed: () => launchUrl(Uri.parse('tel:$phone')),
                    tooltip: 'کال کریں',
                    constraints: const BoxConstraints(),
                    padding: const EdgeInsets.all(4),
                  ),
                ],
              ),
            ],
            if (intDate.isNotEmpty) ...[
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: Colors.orange.shade50,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: Colors.orange.shade200),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.calendar_today,
                            size: 12, color: Colors.orange),
                        const SizedBox(width: 4),
                        Text('انٹرویو: $intDate',
                            style: const TextStyle(
                                fontSize: 11, fontWeight: FontWeight.bold)),
                      ],
                    ),
                    if (intNotes.isNotEmpty)
                      Text(intNotes,
                          style: TextStyle(
                              fontSize: 11, color: Colors.grey.shade800)),
                  ],
                ),
              ),
            ],
            if (isConverted) ...[
              const SizedBox(height: 6),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.green.shade100,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.check_circle, size: 14, color: Colors.green),
                    SizedBox(width: 4),
                    Text('باقاعدہ داخلہ ہو چکا ہے',
                        style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.green)),
                  ],
                ),
              ),
            ],
            const Divider(height: 16),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              alignment: WrapAlignment.end,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                PopupMenuButton<String>(
                  icon: const Icon(Icons.swap_horiz, size: 20),
                  tooltip: 'مرحلہ تبدیل کریں',
                  onSelected: (newSt) async {
                    await _dbHelper.updateAdmissionInquiry(id, {'status': newSt});
                    _loadData();
                  },
                  itemBuilder: (ctx) => _stageDefs
                      .where((s) => s['key'] != status)
                      .map<PopupMenuEntry<String>>((s) => PopupMenuItem<String>(
                            value: s['key'].toString(),
                            child: Row(
                              children: [
                                Icon(s['icon'] as IconData,
                                    size: 16, color: s['color'] as Color),
                                const SizedBox(width: 8),
                                Text(s['label'].toString()),
                              ],
                            ),
                          ))
                      .toList(),
                ),
                // 1) انٹرویو مع واٹس ایپ
                OutlinedButton.icon(
                  icon: const Icon(Icons.calendar_month, size: 16, color: Colors.orange),
                  label: const Text('انٹرویو / ٹیسٹ', style: TextStyle(fontSize: 12, color: Colors.orange)),
                  style: OutlinedButton.styleFrom(
                    side: const BorderSide(color: Colors.orange),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  ),
                  onPressed: () => _showInterviewDialog(item),
                ),
                // 2) داخلہ منظور کریں
                if (!isConverted)
                  ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.green,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    ),
                    icon: const Icon(Icons.check_circle, size: 16),
                    label: const Text('منظور کریں', style: TextStyle(fontSize: 12)),
                    onPressed: () => _showEnrollDialog(item),
                  ),
                // 3) مسترد ایکشن
                if (status != 'Rejected' && !isConverted)
                  OutlinedButton.icon(
                    icon: const Icon(Icons.cancel, size: 16, color: Colors.red),
                    label: const Text('مسترد', style: TextStyle(fontSize: 12, color: Colors.red)),
                    style: OutlinedButton.styleFrom(
                      side: const BorderSide(color: Colors.red),
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    ),
                    onPressed: () => _confirmReject(item),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    return Scaffold(
      appBar: AppBar(
        title: Text(isMasjid ? 'مراحلِ داخلہ (مکتب)' : 'مراحلِ داخلہ',
            style: const TextStyle(fontFamily: 'JameelNoori', fontSize: 20)),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.person_add_alt_1),
            tooltip: 'ڈائریکٹ طالب علم داخلہ',
            onPressed: _showDirectEnrollDialog,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'تازہ کریں',
            onPressed: _loadData,
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          isScrollable: false,
          indicatorColor: Colors.amber,
          indicatorWeight: 3,
          labelColor: Colors.amber,
          unselectedLabelColor: Colors.white70,
          tabs: _stageDefs.map((s) {
            final count = _getItemsForStage(s['key']).length;
            return Tab(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(s['label'],
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.bold)),
                  const SizedBox(width: 4),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    decoration: BoxDecoration(
                      color: Colors.white24,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text('$count',
                        style: const TextStyle(fontSize: 10, color: Colors.white)),
                  ),
                ],
              ),
            );
          }).toList(),
        ),
      ),
      drawer: const AppDrawer(),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tabController,
              children: _stageDefs.map((s) {
                final items = _getItemsForStage(s['key']);
                if (items.isEmpty) {
                  return Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(s['icon'], size: 48, color: Colors.grey.shade400),
                        const SizedBox(height: 8),
                        Text('اس مرحلے میں کوئی ریکارڈ نہیں ہے',
                            style: TextStyle(color: Colors.grey.shade600)),
                      ],
                    ),
                  );
                }
                return RefreshIndicator(
                  onRefresh: _loadData,
                  child: ListView.builder(
                    padding: const EdgeInsets.only(top: 8, bottom: 80),
                    itemCount: items.length,
                    itemBuilder: (ctx, idx) => _buildInquiryCard(items[idx]),
                  ),
                );
              }).toList(),
            ),
      floatingActionButton: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          FloatingActionButton.extended(
            heroTag: 'direct_enroll_fab',
            backgroundColor: Colors.teal.shade700,
            foregroundColor: Colors.white,
            icon: const Icon(Icons.person_add_alt_1),
            label: const Text('ڈائریکٹ طالب علم شامل کریں'),
            onPressed: _showDirectEnrollDialog,
          ),
          const SizedBox(width: 10),
          FloatingActionButton.extended(
            heroTag: 'new_inquiry_fab',
            backgroundColor: primaryGreen,
            foregroundColor: Colors.white,
            icon: const Icon(Icons.add),
            label: const Text('نئی درخواست درج کریں'),
            onPressed: _showAddInquiryDialog,
          ),
        ],
      ),
    );
  }
}
