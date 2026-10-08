// ignore_for_file: use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';

class SanadPreviewScreen extends StatefulWidget {
  final Map<String, dynamic> student;

  const SanadPreviewScreen({super.key, required this.student});

  @override
  State<SanadPreviewScreen> createState() => _SanadPreviewScreenState();
}

class _SanadPreviewScreenState extends State<SanadPreviewScreen> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color goldColor = const Color(0xFFD4AF37);
  final Color bgColor = const Color(0xFFF1EFE7);

  bool _isLoading = true;
  bool _isPrinting = false;
  bool _isCustomMode = false;

  Map<String, dynamic> _settings = {};
  Map<String, dynamic> _layout = {};
  String? _selectedFieldKey;
  bool _isSavingLayout = false;

  // Editable Student & Sanad Details Controllers
  late TextEditingController _studentNameCtrl;
  late TextEditingController _fatherNameCtrl;
  late TextEditingController _admissionNoCtrl;
  late TextEditingController _sanadTitleCtrl;
  late TextEditingController _deptCtrl;
  late TextEditingController _gradDateCtrl;
  late TextEditingController _wifaqMarksCtrl;
  late TextEditingController _gradeCtrl;
  late TextEditingController _sanadSerialCtrl;

  Uint8List? _bgImageBytes;
  Uint8List? _stampImageBytes;
  Uint8List? _logoImageBytes;

  @override
  void initState() {
    super.initState();
    _initControllers();
    _loadData();
  }

  void _initControllers() {
    final s = widget.student;
    final stdId = s['id']?.toString() ?? '0';

    _studentNameCtrl = TextEditingController(text: s['name']?.toString() ?? '');
    _fatherNameCtrl =
        TextEditingController(text: s['father_name']?.toString() ?? '');
    _admissionNoCtrl = TextEditingController(
        text: s['admission_number']?.toString().isNotEmpty == true
            ? s['admission_number'].toString()
            : 'STD-$stdId');
    _sanadTitleCtrl =
        TextEditingController(text: 'سند الفراغ فی حفظ القرآن الکریم');
    _deptCtrl = TextEditingController(
        text: s['class']?.toString().isNotEmpty == true
            ? s['class'].toString()
            : 'شعبہ حفظ القرآن الکریم');
    _gradDateCtrl = TextEditingController(
        text: s['graduation_date']?.toString().isNotEmpty == true
            ? s['graduation_date'].toString()
            : DateTime.now().toString().split(' ')[0]);
    _wifaqMarksCtrl =
        TextEditingController(text: s['wifaq_marks']?.toString() ?? '');
    _gradeCtrl = TextEditingController(text: 'ممتاز');
    _sanadSerialCtrl =
        TextEditingController(text: 'SANAD-1447-${stdId.padLeft(4, '0')}');
  }

  @override
  void dispose() {
    _studentNameCtrl.dispose();
    _fatherNameCtrl.dispose();
    _admissionNoCtrl.dispose();
    _sanadTitleCtrl.dispose();
    _deptCtrl.dispose();
    _gradDateCtrl.dispose();
    _wifaqMarksCtrl.dispose();
    _gradeCtrl.dispose();
    _sanadSerialCtrl.dispose();
    super.dispose();
  }

  Map<String, dynamic> _getDefaultLayout() {
    return {
      'serial_no': {
        'label': 'سیریل نمبر',
        'x': 15.0,
        'y': 28.0,
        'size': 16.0,
        'color': '#284D3D',
        'align': 'right',
        'visible': true
      },
      'admission_no': {
        'label': 'داخلہ نمبر',
        'x': 82.0,
        'y': 28.0,
        'size': 16.0,
        'color': '#284D3D',
        'align': 'left',
        'visible': true
      },
      'sanad_title': {
        'label': 'سند کا عنوان',
        'x': 50.0,
        'y': 35.0,
        'size': 28.0,
        'color': '#284D3D',
        'align': 'center',
        'visible': true
      },
      'student_name': {
        'label': 'طالب علم کا نام',
        'x': 58.0,
        'y': 45.0,
        'size': 26.0,
        'color': '#111111',
        'align': 'center',
        'visible': true
      },
      'father_name': {
        'label': 'ولدیت',
        'x': 32.0,
        'y': 45.0,
        'size': 24.0,
        'color': '#111111',
        'align': 'center',
        'visible': true
      },
      'department': {
        'label': 'شعبہ / کلاس',
        'x': 50.0,
        'y': 53.0,
        'size': 25.0,
        'color': '#16a085',
        'align': 'center',
        'visible': true
      },
      'grad_date': {
        'label': 'تاریخ فراغت',
        'x': 50.0,
        'y': 61.0,
        'size': 20.0,
        'color': '#284D3D',
        'align': 'center',
        'visible': true
      },
      'wifaq_info': {
        'label': 'وفاق نمبرات / تفصیل',
        'x': 50.0,
        'y': 68.0,
        'size': 19.0,
        'color': '#333333',
        'align': 'center',
        'visible': true
      },
      'sig_nazim': {
        'label': 'دستخط ناظم',
        'x': 18.0,
        'y': 84.0,
        'size': 16.0,
        'color': '#284D3D',
        'align': 'center',
        'visible': true
      },
      'stamp': {
        'label': 'مہر / اسٹیمپ',
        'x': 50.0,
        'y': 83.0,
        'size': 90.0,
        'color': '#000000',
        'align': 'center',
        'visible': true
      },
      'sig_mohtamim': {
        'label': 'دستخط مہتمم',
        'x': 78.0,
        'y': 84.0,
        'size': 16.0,
        'color': '#284D3D',
        'align': 'center',
        'visible': true
      },
      'qr_code': {
        'label': 'کیو آر کوڈ',
        'x': 90.0,
        'y': 83.0,
        'size': 75.0,
        'color': '#000000',
        'align': 'center',
        'visible': true
      },
    };
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      _settings = await DBHelper().getSettings();
      final hijri =
          _settings['current_hijri_year']?.toString().isNotEmpty == true
              ? _settings['current_hijri_year'].toString()
              : '1447';
      final stdId = widget.student['id']?.toString() ?? '0';
      _sanadSerialCtrl.text = "SANAD-$hijri-${stdId.padLeft(4, '0')}";

      // Check academic course enrollment (Darse Nizami / Asri)
      try {
        final parsedSid = int.tryParse(stdId);
        if (parsedSid != null) {
          final enrollments = await DBHelper().getCourseEnrollments();
          if (enrollments.isNotEmpty) {
            final en = enrollments.first;
            final cName = en['course_name']?.toString() ?? 'درس نظامی';
            final cYear = en['current_year'] as int? ?? 1;
            final yearMap = {
              1: 'سال اول (درجہ اولیٰ)',
              2: 'سال دوم (درجہ ثانیہ)',
              3: 'سال سوم (درجہ ثالثہ)',
              4: 'سال چہارم (درجہ رابعہ)',
              5: 'سال پنجم (درجہ خامسہ)',
              6: 'سال ششم (درجہ سادسہ)',
              7: 'سال ہفتم (درجہ عالیہ)',
              8: 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)',
            };
            final yrStr = yearMap[cYear] ?? 'سال $cYear';
            if (cYear >= 8) {
              _sanadTitleCtrl.text =
                  'سند الفراغ و شہادۃ العالمیہ فی العلوم الاسلامیہ والعربیہ';
            } else {
              _sanadTitleCtrl.text = 'سند کامیابی تعلیمی سال - $cName ($yrStr)';
            }
            _deptCtrl.text = '$cName - $yrStr';
          }
        }
      } catch (_) {}

      // Parse Layout
      _layout = _getDefaultLayout();
      if (_settings['sanad_layout_json'] != null &&
          _settings['sanad_layout_json'].toString().trim().isNotEmpty) {
        try {
          final decoded = jsonDecode(_settings['sanad_layout_json'].toString());
          if (decoded is Map<String, dynamic>) {
            decoded.forEach((k, v) {
              if (v is Map) {
                final def = _layout[k] ??
                    {'label': k, 'visible': true, 'is_custom': true};
                _layout[k] = {...def, ...Map<String, dynamic>.from(v)};
              }
            });
          }
        } catch (_) {}
      }

      // Check if custom bg exists
      final sanadBgPath = _settings['sanad_bg']?.toString();
      if (sanadBgPath != null && sanadBgPath.isNotEmpty) {
        _isCustomMode = true;
        _bgImageBytes = await _loadImageBytes(sanadBgPath);
      } else {
        _isCustomMode = false;
      }

      // Load stamp & logo
      final stampPath = _settings['signature_stamp_path']?.toString();
      if (stampPath != null && stampPath.isNotEmpty) {
        _stampImageBytes = await _loadImageBytes(stampPath);
      }

      final logoPath = _settings['logo_path']?.toString();
      if (logoPath != null && logoPath.isNotEmpty) {
        _logoImageBytes = await _loadImageBytes(logoPath);
      }
    } catch (_) {}
    if (mounted) setState(() => _isLoading = false);
  }

  Future<Uint8List?> _loadImageBytes(String path) async {
    try {
      if (path.isEmpty) return null;
      if (path.startsWith('http://') || path.startsWith('https://')) {
        final res = await http.get(Uri.parse(path));
        if (res.statusCode == 200 && res.bodyBytes.isNotEmpty) {
          return res.bodyBytes;
        }
      }
      // 1. Check local file
      final f = File(path);
      if (await f.exists()) {
        return await f.readAsBytes();
      }
      // 2. Check in app directory
      try {
        final appDir = await getApplicationDocumentsDirectory();
        final localInApp = File('${appDir.path}/$path');
        if (await localInApp.exists()) {
          return await localInApp.readAsBytes();
        }
      } catch (_) {}
      // 3. Network fetch with fallback
      String baseUrl = apiUrl;
      if (baseUrl.endsWith('api.php')) {
        baseUrl = baseUrl.substring(0, baseUrl.length - 7);
      }
      if (!baseUrl.endsWith('/')) baseUrl += '/';
      final cleanPath = path.startsWith('/') ? path.substring(1) : path;
      final fullUrl = '$baseUrl$cleanPath';
      final res = await http.get(Uri.parse(fullUrl));
      if (res.statusCode == 200 && res.bodyBytes.isNotEmpty) {
        return res.bodyBytes;
      }
    } catch (_) {}
    return null;
  }

  Future<void> _pickCustomBackground() async {
    try {
      final picker = ImagePicker();
      final picked =
          await picker.pickImage(source: ImageSource.gallery, imageQuality: 90);
      if (picked != null) {
        final bytes = await File(picked.path).readAsBytes();
        setState(() {
          _bgImageBytes = bytes;
          _isCustomMode = true;
        });
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('کسٹم بیک گراؤنڈ کامیابی سے سیٹ ہو گیا!'),
          backgroundColor: Colors.green,
        ));
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('تصویر لوڈ کرنے میں خرابی: $e'),
        backgroundColor: Colors.red,
      ));
    }
  }

  void _openEditDialog() {
    final isUrdu = isUrduGlobal.value;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Directionality(
        textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
        child: Container(
          height: MediaQuery.of(context).size.height * 0.85,
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 20,
            bottom: MediaQuery.of(context).viewInsets.bottom + 20,
          ),
          child: Column(
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    isUrdu ? 'ترمیم مندرجاتِ سند' : 'Edit Sanad Details',
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: primaryGreen,
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              const Divider(),
              Expanded(
                child: ListView(
                  children: [
                    _buildEditField(_sanadTitleCtrl,
                        isUrdu ? 'سند کا عنوان' : 'Sanad Title'),
                    const SizedBox(height: 10),
                    _buildEditField(_studentNameCtrl,
                        isUrdu ? 'طالب علم کا نام' : 'Student Name'),
                    const SizedBox(height: 10),
                    _buildEditField(
                        _fatherNameCtrl, isUrdu ? 'ولدیت' : 'Father Name'),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                            child: _buildEditField(_admissionNoCtrl,
                                isUrdu ? 'داخلہ نمبر' : 'Admission No')),
                        const SizedBox(width: 10),
                        Expanded(
                            child: _buildEditField(_sanadSerialCtrl,
                                isUrdu ? 'سیریل نمبر' : 'Serial No')),
                      ],
                    ),
                    const SizedBox(height: 10),
                    _buildEditField(_deptCtrl,
                        isUrdu ? 'شعبہ / کلاس' : 'Department / Class'),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                            child: _buildEditField(_gradDateCtrl,
                                isUrdu ? 'تاریخ فراغت' : 'Graduation Date')),
                        const SizedBox(width: 10),
                        Expanded(
                            child: _buildEditField(_wifaqMarksCtrl,
                                isUrdu ? 'وفاق نمبرات' : 'Wifaq Marks')),
                      ],
                    ),
                    const SizedBox(height: 10),
                    _buildEditField(
                        _gradeCtrl, isUrdu ? 'درجہ / گریڈ' : 'Grade'),
                  ],
                ),
              ),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: primaryGreen,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                  onPressed: () {
                    setState(() {});
                    Navigator.pop(ctx);
                  },
                  icon: const Icon(Icons.check),
                  label: Text(isUrdu
                      ? 'محفوظ کریں اور پرویو دیکھیں'
                      : 'Save & Preview'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildEditField(TextEditingController ctrl, String label) {
    return TextFormField(
      controller: ctrl,
      style: const TextStyle(fontFamily: 'NotoNaskhArabic', fontSize: 15),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: TextStyle(
            color: primaryGreen, fontSize: 13, fontFamily: 'NotoNaskhArabic'),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      ),
    );
  }

  Future<void> _saveLayout() async {
    if (_isSavingLayout) return;
    setState(() => _isSavingLayout = true);
    try {
      final jsonStr = jsonEncode(_layout);
      // 1. Save locally to SQLite
      final db = await DBHelper().database;
      await db.update('settings', {'sanad_layout_json': jsonStr},
          where: 'id = 1');

      // 2. Sync to Server if available
      try {
        await NetworkService.postWithFallbackAction(
          'update_settings',
          {'sanad_layout_json': jsonStr},
        );
      } catch (_) {}

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('لے آؤٹ کوآرڈینیٹس کامیابی سے محفوظ ہو گئے!'),
            backgroundColor: Colors.green,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('محفوظ کرنے میں خرابی: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isSavingLayout = false);
    }
  }

  void _resetLayout() {
    final isUrdu = isUrduGlobal.value;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrdu ? 'اصل حالت پر بحال کریں؟' : 'Reset Layout?'),
        content: Text(isUrdu
            ? 'کیا آپ تمام فیلڈز کے کوآرڈینیٹس اور سائز کو اصل پوزیشن پر بحال کرنا چاہتے ہیں؟'
            : 'Do you want to reset all field positions and sizes to default?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(isUrdu ? 'منسوخ' : 'Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
                backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () {
              Navigator.pop(ctx);
              setState(() {
                _layout = _getDefaultLayout();
                _selectedFieldKey = null;
              });
              _saveLayout();
            },
            child: Text(isUrdu ? 'بحال کریں' : 'Reset'),
          ),
        ],
      ),
    );
  }

  void _addCustomField() {
    final count = _layout.keys.where((k) => k.startsWith('custom_')).length + 1;
    final newKey = 'custom_${DateTime.now().millisecondsSinceEpoch}';
    setState(() {
      _layout[newKey] = {
        'label': 'اضافی فیلڈ $count',
        'text': 'عبارت درج کریں',
        'x': 50.0,
        'y': 50.0,
        'size': 22.0,
        'color': '#111111',
        'align': 'center',
        'visible': true,
        'is_custom': true,
      };
      _selectedFieldKey = newKey;
    });
    _openFieldConfigSheet(newKey);
  }

  void _openFieldConfigSheet(String fKey) {
    final fCfg = _layout[fKey];
    if (fCfg == null) return;
    final isUrdu = isUrduGlobal.value;
    final isCustomField = fCfg['is_custom'] == true;
    final isGraphic = fKey == 'stamp' || fKey == 'qr_code';
    final label = fCfg['label']?.toString() ?? fKey;

    final textCtrl = TextEditingController(text: _getFieldText(fKey));

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          final posX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
          final posY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;
          final size = (fCfg['size'] as num?)?.toDouble() ?? 24.0;
          final curColorHex = fCfg['color']?.toString() ?? '#111111';
          final curAlign = fCfg['align']?.toString() ?? 'center';
          final isVis = fCfg['visible'] != false;

          final colors = [
            '#111111',
            '#1B4332',
            '#284D3D',
            '#B89230',
            '#D4AF37',
            '#0284C7',
            '#059669',
            '#E11D48',
            '#FFFFFF'
          ];

          return Directionality(
            textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
            child: Container(
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
              ),
              padding: EdgeInsets.only(
                left: 18,
                right: 18,
                top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Header
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Icon(Icons.tune, color: primaryGreen, size: 20),
                            const SizedBox(width: 8),
                            Text(
                              label,
                              style: TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  color: primaryGreen),
                            ),
                          ],
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.pop(ctx),
                        ),
                      ],
                    ),
                    const Divider(height: 12),

                    // Text Input (Arabic Font)
                    if (!isGraphic) ...[
                      Text(
                        isUrdu
                            ? 'متن / مواد (عربی خط):'
                            : 'Field Text (Arabic Font):',
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(height: 6),
                      TextFormField(
                        controller: textCtrl,
                        style: const TextStyle(
                            fontFamily: 'NotoNaskhArabic', fontSize: 16),
                        decoration: InputDecoration(
                          hintText: 'متن درج کریں...',
                          hintStyle:
                              const TextStyle(fontFamily: 'NotoNaskhArabic'),
                          border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8)),
                          contentPadding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 10),
                        ),
                        onChanged: (val) {
                          setState(() {
                            fCfg['text'] = val;
                          });
                        },
                      ),
                      const SizedBox(height: 12),
                    ],

                    // Size Slider
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          isUrdu ? 'سائز / فونٹ:' : 'Size / Font:',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        Text('${size.toInt()} px',
                            style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: primaryGreen)),
                      ],
                    ),
                    Slider(
                      value: size.clamp(10.0, 120.0),
                      min: 10,
                      max: 120,
                      divisions: 110,
                      activeColor: primaryGreen,
                      onChanged: (val) {
                        setSheetState(() => fCfg['size'] = val);
                        setState(() => fCfg['size'] = val);
                      },
                    ),

                    // Position X & Y Steppers
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                isUrdu
                                    ? 'افقی پوزیشن (X): ${posX.toStringAsFixed(1)}%'
                                    : 'X: ${posX.toStringAsFixed(1)}%',
                                style: const TextStyle(
                                    fontSize: 12, fontWeight: FontWeight.bold),
                              ),
                              Slider(
                                value: posX.clamp(0.0, 100.0),
                                min: 0,
                                max: 100,
                                activeColor: primaryGreen,
                                onChanged: (val) {
                                  setSheetState(() => fCfg['x'] =
                                      double.parse(val.toStringAsFixed(1)));
                                  setState(() => fCfg['x'] =
                                      double.parse(val.toStringAsFixed(1)));
                                },
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                isUrdu
                                    ? 'عمودی پوزیشن (Y): ${posY.toStringAsFixed(1)}%'
                                    : 'Y: ${posY.toStringAsFixed(1)}%',
                                style: const TextStyle(
                                    fontSize: 12, fontWeight: FontWeight.bold),
                              ),
                              Slider(
                                value: posY.clamp(0.0, 100.0),
                                min: 0,
                                max: 100,
                                activeColor: primaryGreen,
                                onChanged: (val) {
                                  setSheetState(() => fCfg['y'] =
                                      double.parse(val.toStringAsFixed(1)));
                                  setState(() => fCfg['y'] =
                                      double.parse(val.toStringAsFixed(1)));
                                },
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),

                    // Align & Color if not graphic
                    if (!isGraphic) ...[
                      const SizedBox(height: 6),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            isUrdu ? 'الائنمنٹ:' : 'Alignment:',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          Row(
                            children: [
                              _buildAlignBtn('right', Icons.format_align_right,
                                  curAlign == 'right', () {
                                setSheetState(() => fCfg['align'] = 'right');
                                setState(() => fCfg['align'] = 'right');
                              }),
                              const SizedBox(width: 6),
                              _buildAlignBtn(
                                  'center',
                                  Icons.format_align_center,
                                  curAlign == 'center', () {
                                setSheetState(() => fCfg['align'] = 'center');
                                setState(() => fCfg['align'] = 'center');
                              }),
                              const SizedBox(width: 6),
                              _buildAlignBtn('left', Icons.format_align_left,
                                  curAlign == 'left', () {
                                setSheetState(() => fCfg['align'] = 'left');
                                setState(() => fCfg['align'] = 'left');
                              }),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 10),

                      // Color palette
                      Text(
                        isUrdu ? 'رنگ منتخب کریں:' : 'Select Color:',
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 8,
                        children: colors.map((cHex) {
                          final c = _parseColor(cHex);
                          final isSel =
                              curColorHex.toUpperCase() == cHex.toUpperCase();
                          return GestureDetector(
                            onTap: () {
                              setSheetState(() => fCfg['color'] = cHex);
                              setState(() => fCfg['color'] = cHex);
                            },
                            child: Container(
                              width: 28,
                              height: 28,
                              decoration: BoxDecoration(
                                color: c,
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color: isSel
                                      ? Colors.orange
                                      : Colors.grey.shade400,
                                  width: isSel ? 2.5 : 1,
                                ),
                                boxShadow: isSel
                                    ? [
                                        BoxShadow(
                                            color:
                                                Colors.orange.withOpacity(0.5),
                                            blurRadius: 4)
                                      ]
                                    : null,
                              ),
                              child: isSel
                                  ? const Icon(Icons.check,
                                      size: 14, color: Colors.orange)
                                  : null,
                            ),
                          );
                        }).toList(),
                      ),
                      const SizedBox(height: 14),
                    ],

                    // Visibility & Actions
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor:
                                  isVis ? Colors.orange.shade800 : Colors.green,
                              side: BorderSide(
                                  color: isVis ? Colors.orange : Colors.green),
                            ),
                            onPressed: () {
                              setSheetState(() => fCfg['visible'] = !isVis);
                              setState(() => fCfg['visible'] = !isVis);
                            },
                            icon: Icon(
                                isVis ? Icons.visibility_off : Icons.visibility,
                                size: 16),
                            label: Text(isVis
                                ? (isUrdu ? 'ہائیڈ کریں' : 'Hide')
                                : (isUrdu ? 'ظاہر کریں' : 'Show')),
                          ),
                        ),
                        if (isCustomField) ...[
                          const SizedBox(width: 8),
                          IconButton(
                            style: IconButton.styleFrom(
                                backgroundColor: Colors.red.shade50),
                            icon: const Icon(Icons.delete, color: Colors.red),
                            onPressed: () {
                              setState(() {
                                _layout.remove(fKey);
                                _selectedFieldKey = null;
                              });
                              Navigator.pop(ctx);
                              _saveLayout();
                            },
                          ),
                        ],
                        const SizedBox(width: 8),
                        Expanded(
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                              foregroundColor: Colors.white,
                            ),
                            onPressed: () {
                              Navigator.pop(ctx);
                              _saveLayout();
                            },
                            icon: const Icon(Icons.check, size: 16),
                            label: Text(isUrdu ? 'محفوظ کریں' : 'Save'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildAlignBtn(
      String align, IconData icon, bool active, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: active ? primaryGreen : Colors.grey.shade100,
          borderRadius: BorderRadius.circular(6),
          border:
              Border.all(color: active ? primaryGreen : Colors.grey.shade300),
        ),
        child:
            Icon(icon, size: 16, color: active ? Colors.white : Colors.black87),
      ),
    );
  }

  String _getFieldText(String key) {
    final customCfg = _layout[key];
    if (customCfg != null &&
        customCfg['text'] != null &&
        customCfg['text'].toString().trim().isNotEmpty) {
      return customCfg['text'].toString();
    }
    switch (key) {
      case 'serial_no':
        return _sanadSerialCtrl.text;
      case 'admission_no':
        return _admissionNoCtrl.text;
      case 'sanad_title':
        return _sanadTitleCtrl.text;
      case 'student_name':
        return _studentNameCtrl.text;
      case 'father_name':
        return _fatherNameCtrl.text;
      case 'department':
        return _deptCtrl.text;
      case 'grad_date':
        return 'بتاریخ: ${_gradDateCtrl.text}ء';
      case 'wifaq_info':
        return _wifaqMarksCtrl.text.isNotEmpty
            ? 'امتحان وفاق: ${_wifaqMarksCtrl.text} نمبرات (درجہ ${_gradeCtrl.text})'
            : 'تعلیمی کارکردگی و اخلاق: تسلی بخش';
      case 'sig_nazim':
        return 'دستخط ناظمِ تعلیمات';
      case 'sig_mohtamim':
        return 'دستخط مہتمم / پرنسپل';
      default:
        return customCfg?['label'] ?? '';
    }
  }

  Color _parseColor(String? hex) {
    if (hex == null || hex.isEmpty) return const Color(0xFF111111);
    try {
      final clean = hex.replaceAll('#', '');
      return Color(int.parse('FF$clean', radix: 16));
    } catch (_) {
      return const Color(0xFF111111);
    }
  }

  TextAlign _parseAlign(String? align) {
    switch (align) {
      case 'right':
        return TextAlign.right;
      case 'left':
        return TextAlign.left;
      default:
        return TextAlign.center;
    }
  }

  Future<void> _printOrExportPdf() async {
    if (_isPrinting) return;
    setState(() => _isPrinting = true);

    try {
      // 1. Load Fonts with Zero-Defect Guarantee for Urdu Glyphs
      final urduFontData =
          await rootBundle.load('assets/fonts/NotoNaskhArabic-Regular.ttf');
      final urduFont = pw.Font.ttf(urduFontData);

      final robotoFontData =
          await rootBundle.load('assets/fonts/Roboto-Regular.ttf');
      final robotoFont = pw.Font.ttf(robotoFontData);

      final pdfTheme = pw.ThemeData.withFont(
        base: urduFont,
        fontFallback: [robotoFont],
      );

      final doc = pw.Document(theme: pdfTheme);
      final isUrdu = isUrduGlobal.value;

      final madrasaName =
          _settings['madrasa_name']?.toString().isNotEmpty == true
              ? _settings['madrasa_name'].toString()
              : (isUrdu ? 'جامعہ اشرف المدارس' : 'Madrasa Management System');
      final tagline = _settings['tagline']?.toString().isNotEmpty == true
          ? _settings['tagline'].toString()
          : (isUrdu ? 'العلم نور - تعلیم و تربیت کا گہوارہ' : '');

      final qrData =
          'SANAD:${widget.student['id']}:${_admissionNoCtrl.text}:${_sanadSerialCtrl.text}';

      if (_isCustomMode && _bgImageBytes != null) {
        // ==================== PDF MODE 1: CUSTOM BACKGROUND ====================
        final bgImage = pw.MemoryImage(_bgImageBytes!);
        pw.MemoryImage? stampImg =
            _stampImageBytes != null ? pw.MemoryImage(_stampImageBytes!) : null;

        doc.addPage(
          pw.Page(
            pageFormat: PdfPageFormat.a4.landscape,
            margin: pw.EdgeInsets.zero,
            build: (pw.Context context) {
              final pageWidth = PdfPageFormat.a4.landscape.width;
              final pageHeight = PdfPageFormat.a4.landscape.height;

              List<pw.Widget> stackChildren = [
                pw.Positioned.fill(
                  child: pw.Image(bgImage, fit: pw.BoxFit.fill),
                ),
              ];

              _layout.forEach((fKey, fCfg) {
                final isVisible = fCfg['visible'] != false;
                if (!isVisible) return;

                final posX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
                final posY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;
                final size = (fCfg['size'] as num?)?.toDouble() ?? 24.0;
                final colorHex = fCfg['color']?.toString() ?? '#111111';
                final pdfColor = PdfColor.fromHex(colorHex.replaceAll('#', ''));
                final align = fCfg['align']?.toString() ?? 'center';

                // Right percent to points: 0% right means at pageWidth, 100% right means at 0
                final rightPt = (posX / 100.0) * pageWidth;
                final topPt = (posY / 100.0) * pageHeight;

                if (fKey == 'stamp') {
                  if (stampImg != null) {
                    stackChildren.add(
                      pw.Positioned(
                        right: rightPt - (size / 2),
                        top: topPt - (size / 2),
                        child: pw.Image(stampImg, width: size, height: size),
                      ),
                    );
                  }
                } else if (fKey == 'qr_code') {
                  stackChildren.add(
                    pw.Positioned(
                      right: rightPt - (size / 2),
                      top: topPt - (size / 2),
                      child: pw.Container(
                        width: size,
                        height: size,
                        color: PdfColors.white,
                        padding: const pw.EdgeInsets.all(2),
                        child: pw.BarcodeWidget(
                          barcode: pw.Barcode.qrCode(),
                          data: qrData,
                          width: size,
                          height: size,
                        ),
                      ),
                    ),
                  );
                } else {
                  final text = _getFieldText(fKey);
                  pw.TextAlign pdfAlign = pw.TextAlign.center;
                  if (align == 'right') pdfAlign = pw.TextAlign.right;
                  if (align == 'left') pdfAlign = pw.TextAlign.left;

                  stackChildren.add(
                    pw.Positioned(
                      right: rightPt - 150,
                      top: topPt,
                      child: pw.SizedBox(
                        width: 300,
                        child: pw.Text(
                          text,
                          textAlign: pdfAlign,
                          textDirection: pw.TextDirection.rtl,
                          style: pw.TextStyle(
                            fontSize: size * 0.75, // Pt scaling
                            color: pdfColor,
                          ),
                        ),
                      ),
                    ),
                  );
                }
              });

              return pw.Stack(children: stackChildren);
            },
          ),
        );
      } else {
        // ==================== PDF MODE 2: ROYAL ISLAMIC FRAME ====================
        pw.MemoryImage? logoImg =
            _logoImageBytes != null ? pw.MemoryImage(_logoImageBytes!) : null;
        pw.MemoryImage? stampImg =
            _stampImageBytes != null ? pw.MemoryImage(_stampImageBytes!) : null;

        doc.addPage(
          pw.Page(
            pageFormat: PdfPageFormat.a4.landscape,
            margin: const pw.EdgeInsets.all(24),
            build: (pw.Context context) {
              return pw.Container(
                decoration: pw.BoxDecoration(
                  border: pw.Border.all(
                      color: PdfColor.fromHex('284D3D'), width: 7),
                  borderRadius: pw.BorderRadius.circular(8),
                ),
                padding: const pw.EdgeInsets.all(16),
                child: pw.Column(
                  mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                  children: [
                    // Header
                    pw.Column(
                      children: [
                        pw.Text(
                          'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ',
                          style: pw.TextStyle(
                              fontSize: 18,
                              color: PdfColor.fromHex('284D3D'),
                              fontWeight: pw.FontWeight.bold),
                          textDirection: pw.TextDirection.rtl,
                        ),
                        pw.SizedBox(height: 2),
                        pw.Text(
                          'يَرْفَعِ اللَّهُ الَّذِينَ آمَنُوا مِنكُمْ وَالَّذِينَ أُوتُوا الْعِلْمَ دَرَجَاتٍ',
                          style: pw.TextStyle(
                              fontSize: 12, color: PdfColor.fromHex('D4AF37')),
                          textDirection: pw.TextDirection.rtl,
                        ),
                        pw.SizedBox(height: 4),
                        pw.Row(
                          mainAxisAlignment: pw.MainAxisAlignment.center,
                          children: [
                            if (logoImg != null) ...[
                              pw.Image(logoImg, width: 45, height: 45),
                              pw.SizedBox(width: 12),
                            ],
                            pw.Column(
                              children: [
                                pw.Text(
                                  madrasaName,
                                  style: pw.TextStyle(
                                      fontSize: 22,
                                      fontWeight: pw.FontWeight.bold,
                                      color: PdfColor.fromHex('284D3D')),
                                  textDirection: pw.TextDirection.rtl,
                                ),
                                if (tagline.isNotEmpty)
                                  pw.Text(
                                    tagline,
                                    style: const pw.TextStyle(
                                        fontSize: 11, color: PdfColors.grey700),
                                    textDirection: pw.TextDirection.rtl,
                                  ),
                              ],
                            ),
                          ],
                        ),
                        pw.SizedBox(height: 6),
                        // Badge
                        pw.Container(
                          padding: const pw.EdgeInsets.symmetric(
                              horizontal: 24, vertical: 4),
                          decoration: pw.BoxDecoration(
                            color: PdfColor.fromHex('284D3D'),
                            borderRadius: pw.BorderRadius.circular(15),
                            border: pw.Border.all(
                                color: PdfColor.fromHex('D4AF37'), width: 1.5),
                          ),
                          child: pw.Text(
                            _sanadTitleCtrl.text,
                            style: pw.TextStyle(
                                fontSize: 16,
                                color: PdfColor.fromHex('D4AF37'),
                                fontWeight: pw.FontWeight.bold),
                            textDirection: pw.TextDirection.rtl,
                          ),
                        ),
                      ],
                    ),

                    // Meta Strip
                    pw.Padding(
                      padding: const pw.EdgeInsets.symmetric(horizontal: 10),
                      child: pw.Row(
                        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                        children: [
                          pw.Text('سیریل نمبر: ${_sanadSerialCtrl.text}',
                              style: const pw.TextStyle(
                                  fontSize: 10, color: PdfColors.grey800),
                              textDirection: pw.TextDirection.rtl),
                          pw.Text('داخلہ نمبر: ${_admissionNoCtrl.text}',
                              style: const pw.TextStyle(
                                  fontSize: 10, color: PdfColors.grey800),
                              textDirection: pw.TextDirection.rtl),
                        ],
                      ),
                    ),

                    // Body Prose
                    pw.Padding(
                      padding: const pw.EdgeInsets.symmetric(horizontal: 16),
                      child: pw.Text(
                        'تصدیق کی جاتی ہے کہ محترم ${_studentNameCtrl.text} ابنِ محترم ${_fatherNameCtrl.text} نے اس مبارک ادارے سے ${_deptCtrl.text} کا نصابِ تعلیم باقاعدہ و کامیابی کے ساتھ بتاریخ ${_gradDateCtrl.text}ء مکمل کر کے فراغت حاصل کی۔ ادارہ ان کی درازیِ عمر، حسنِ عمل اور قرآنی خدمات کے لیے دعا گو ہے۔',
                        textAlign: pw.TextAlign.center,
                        textDirection: pw.TextDirection.rtl,
                        style: const pw.TextStyle(fontSize: 15, lineSpacing: 5),
                      ),
                    ),

                    if (_wifaqMarksCtrl.text.isNotEmpty)
                      pw.Container(
                        padding: const pw.EdgeInsets.symmetric(
                            horizontal: 14, vertical: 3),
                        decoration: pw.BoxDecoration(
                          color: PdfColor.fromHex('F9F9F6'),
                          border: pw.Border.all(
                              color: PdfColor.fromHex('D4AF37'), width: 0.8),
                          borderRadius: pw.BorderRadius.circular(6),
                        ),
                        child: pw.Text(
                          'امتحان وفاق المدارس: ${_wifaqMarksCtrl.text} نمبرات (درجہ: ${_gradeCtrl.text})',
                          style: pw.TextStyle(
                              fontSize: 11,
                              color: PdfColor.fromHex('284D3D'),
                              fontWeight: pw.FontWeight.bold),
                          textDirection: pw.TextDirection.rtl,
                        ),
                      ),

                    // Footer
                    pw.Padding(
                      padding: const pw.EdgeInsets.symmetric(horizontal: 20),
                      child: pw.Row(
                        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                        crossAxisAlignment: pw.CrossAxisAlignment.end,
                        children: [
                          pw.Column(
                            children: [
                              pw.Container(
                                  width: 100,
                                  height: 1,
                                  color: PdfColor.fromHex('284D3D')),
                              pw.SizedBox(height: 3),
                              pw.Text('دستخط ناظمِ تعلیمات',
                                  style: const pw.TextStyle(fontSize: 10),
                                  textDirection: pw.TextDirection.rtl),
                            ],
                          ),
                          if (stampImg != null)
                            pw.Image(stampImg, width: 55, height: 55)
                          else
                            pw.Container(
                              width: 50,
                              height: 50,
                              decoration: pw.BoxDecoration(
                                shape: pw.BoxShape.circle,
                                border: pw.Border.all(
                                    color: PdfColor.fromHex('D4AF37'),
                                    width: 1),
                              ),
                              child: pw.Center(
                                child: pw.Text('مہر ادارہ',
                                    style: const pw.TextStyle(fontSize: 8),
                                    textDirection: pw.TextDirection.rtl),
                              ),
                            ),
                          pw.Container(
                            width: 50,
                            height: 50,
                            color: PdfColors.white,
                            padding: const pw.EdgeInsets.all(2),
                            child: pw.BarcodeWidget(
                              barcode: pw.Barcode.qrCode(),
                              data: qrData,
                              width: 46,
                              height: 46,
                            ),
                          ),
                          pw.Column(
                            children: [
                              pw.Container(
                                  width: 100,
                                  height: 1,
                                  color: PdfColor.fromHex('284D3D')),
                              pw.SizedBox(height: 3),
                              pw.Text('دستخط مہتمم / پرنسپل',
                                  style: const pw.TextStyle(fontSize: 10),
                                  textDirection: pw.TextDirection.rtl),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        );
      }

      await Printing.layoutPdf(
        onLayout: (format) async => doc.save(),
        name: 'sanad_${_admissionNoCtrl.text}.pdf',
      );
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text('خرابی پرنٹنگ: $e'), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _isPrinting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final cat = resolveAlumniCategory(widget.student);

    if (cat != 'completed') {
      return Directionality(
        textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
        child: Scaffold(
          backgroundColor: bgColor,
          appBar: AppBar(
            backgroundColor: primaryGreen,
            foregroundColor: Colors.white,
            title: Text(isUrdu ? 'غیر مجاز اجراء' : 'Unauthorized Sanad'),
          ),
          body: Center(
            child: Padding(
              padding: const EdgeInsets.all(24.0),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.block, color: Colors.red, size: 64),
                  const SizedBox(height: 16),
                  Text(
                    isUrdu
                        ? 'سند کا اجراء صرف اور صرف "تکمیلِ تعلیم" کے حامل طلبہ کے لیے ممکن ہے!\nترکِ تعلیم یا اخراج والے طلبہ کو سند جاری نہیں کی جا سکتی۔'
                        : 'Sanad can only be issued to students with "Completed Education" status!\nDropped or expelled students cannot be issued a Sanad.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 24),
                  ElevatedButton(
                    onPressed: () => Navigator.pop(context),
                    style:
                        ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                    child: Text(
                      isUrdu ? 'واپس جائیں' : 'Go Back',
                      style: const TextStyle(color: Colors.white),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          title: Text(isUrdu ? 'سند الفراغ - لائیو پرویو' : 'Sanad Preview'),
          actions: [
            IconButton(
              icon: const Icon(Icons.edit_note, size: 28),
              tooltip: isUrdu ? 'معلومات میں ترمیم کریں' : 'Edit Details',
              onPressed: _openEditDialog,
            ),
            IconButton(
              icon: _isPrinting
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2),
                    )
                  : const Icon(Icons.print),
              tooltip: isUrdu ? 'پرنٹ / پی ڈی ایف' : 'Print / PDF',
              onPressed: _isPrinting ? null : _printOrExportPdf,
            ),
          ],
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  // Top Mode Selector Bar
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    color: Colors.white,
                    child: Row(
                      children: [
                        Expanded(
                          child: ChoiceChip(
                            label: Text(
                              isUrdu
                                  ? '👑 شاہکار اسلامی ڈیزائن'
                                  : 'Royal Islamic Frame',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: !_isCustomMode
                                    ? Colors.white
                                    : primaryGreen,
                              ),
                            ),
                            selected: !_isCustomMode,
                            selectedColor: primaryGreen,
                            onSelected: (val) {
                              if (val) setState(() => _isCustomMode = false);
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: ChoiceChip(
                            label: Text(
                              isUrdu
                                  ? '🖼️ کسٹم بیک گراؤنڈ سند'
                                  : 'Custom Background',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color:
                                    _isCustomMode ? Colors.white : primaryGreen,
                              ),
                            ),
                            selected: _isCustomMode,
                            selectedColor: primaryGreen,
                            onSelected: (val) {
                              if (val) {
                                if (_bgImageBytes == null) {
                                  _pickCustomBackground();
                                } else {
                                  setState(() => _isCustomMode = true);
                                }
                              }
                            },
                          ),
                        ),
                      ],
                    ),
                  ),

                  // Actions Row
                  Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                    child: Column(
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(
                              _isCustomMode
                                  ? (isUrdu
                                      ? 'موڈ: کسٹم بیک گراؤنڈ (ڈریگ فعال)'
                                      : 'Mode: Custom BG (Drag Active)')
                                  : (isUrdu
                                      ? 'موڈ: شاہکار رائل فریم'
                                      : 'Mode: Royal Islamic Frame'),
                              style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.bold,
                                  color: primaryGreen),
                            ),
                            SingleChildScrollView(
                              scrollDirection: Axis.horizontal,
                              child: Row(
                                children: [
                                  if (_isCustomMode) ...[
                                    TextButton.icon(
                                      style: TextButton.styleFrom(
                                          padding: const EdgeInsets.symmetric(
                                              horizontal: 4)),
                                      icon: const Icon(Icons.add_circle_outline,
                                          size: 16),
                                      label: Text(
                                          isUrdu ? '+ نئی فیلڈ' : '+ Field',
                                          style: const TextStyle(fontSize: 12)),
                                      onPressed: _addCustomField,
                                    ),
                                    TextButton.icon(
                                      style: TextButton.styleFrom(
                                          padding: const EdgeInsets.symmetric(
                                              horizontal: 4)),
                                      icon: const Icon(Icons.image, size: 16),
                                      label: Text(isUrdu ? 'بیک گراؤنڈ' : 'BG',
                                          style: const TextStyle(fontSize: 12)),
                                      onPressed: _pickCustomBackground,
                                    ),
                                    ElevatedButton.icon(
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: Colors.green.shade700,
                                        foregroundColor: Colors.white,
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 8, vertical: 4),
                                        textStyle: const TextStyle(
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold),
                                      ),
                                      onPressed:
                                          _isSavingLayout ? null : _saveLayout,
                                      icon: _isSavingLayout
                                          ? const SizedBox(
                                              width: 12,
                                              height: 12,
                                              child: CircularProgressIndicator(
                                                  color: Colors.white,
                                                  strokeWidth: 2))
                                          : const Icon(Icons.save, size: 13),
                                      label:
                                          Text(isUrdu ? 'محفوظ کریں' : 'Save'),
                                    ),
                                    const SizedBox(width: 4),
                                  ],
                                  ElevatedButton.icon(
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: primaryGreen,
                                      foregroundColor: Colors.white,
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 4),
                                      textStyle: const TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.bold),
                                    ),
                                    onPressed: _openEditDialog,
                                    icon: const Icon(Icons.edit, size: 13),
                                    label: Text(isUrdu ? 'مندرجات' : 'Details'),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        if (_isCustomMode) ...[
                          _buildFieldSelectorChips(isUrdu),
                          _buildPrecisionNudgeBar(isUrdu),
                        ],
                      ],
                    ),
                  ),

                  // Sanad Visual Preview Container (A4 Landscape aspect ratio 297/210 = 1.414)
                  Expanded(
                    child: Center(
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.all(12),
                        child: AspectRatio(
                          aspectRatio: 297 / 210,
                          child: Container(
                            decoration: BoxDecoration(
                              color: Colors.white,
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.18),
                                  blurRadius: 15,
                                  offset: const Offset(0, 5),
                                ),
                              ],
                            ),
                            child: _isCustomMode
                                ? _buildCustomBackgroundPreview()
                                : _buildIslamicFramePreview(),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _buildIslamicFramePreview() {
    final isUrdu = isUrduGlobal.value;
    final madrasaName = _settings['madrasa_name']?.toString().isNotEmpty == true
        ? _settings['madrasa_name'].toString()
        : (isUrdu ? 'جامعہ اشرف المدارس' : 'Madrasa Management System');
    final tagline = _settings['tagline']?.toString().isNotEmpty == true
        ? _settings['tagline'].toString()
        : (isUrdu ? 'العلم نور - تعلیم و تربیت کا گہوارہ' : '');

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: primaryGreen, width: 7),
      ),
      padding: const EdgeInsets.all(12),
      child: Container(
        decoration: BoxDecoration(
          border: Border.all(color: goldColor, width: 2),
        ),
        padding: const EdgeInsets.all(12),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            // Header
            Column(
              children: [
                Text(
                  'بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ',
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: primaryGreen,
                    fontFamily: 'NotoNaskhArabic',
                  ),
                ),
                Text(
                  'يَرْفَعِ اللَّهُ الَّذِينَ آمَنُوا مِنكُمْ وَالَّذِينَ أُوتُوا الْعِلْمَ دَرَجَاتٍ',
                  style: TextStyle(
                    fontSize: 11,
                    color: goldColor,
                    fontFamily: 'NotoNaskhArabic',
                  ),
                ),
                const SizedBox(height: 2),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (_logoImageBytes != null) ...[
                      Image.memory(_logoImageBytes!,
                          width: 35, height: 35, fit: BoxFit.contain),
                      const SizedBox(width: 8),
                    ],
                    Column(
                      children: [
                        Text(
                          madrasaName,
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: primaryGreen,
                            fontFamily: 'NotoNaskhArabic',
                          ),
                        ),
                        if (tagline.isNotEmpty)
                          Text(
                            tagline,
                            style: const TextStyle(
                                fontSize: 10, color: Colors.grey),
                          ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 20, vertical: 3),
                  decoration: BoxDecoration(
                    color: primaryGreen,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: goldColor, width: 1.5),
                  ),
                  child: Text(
                    _sanadTitleCtrl.text,
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      color: goldColor,
                      fontFamily: 'NotoNaskhArabic',
                    ),
                  ),
                ),
              ],
            ),

            // Meta
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('سیریل: ${_sanadSerialCtrl.text}',
                      style: const TextStyle(fontSize: 9, color: Colors.grey)),
                  Text('داخلہ نمبر: ${_admissionNoCtrl.text}',
                      style: const TextStyle(fontSize: 9, color: Colors.grey)),
                ],
              ),
            ),

            // Body Prose
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Text(
                'تصدیق کی جاتی ہے کہ محترم ${_studentNameCtrl.text} ابنِ محترم ${_fatherNameCtrl.text} نے اس مبارک ادارے سے ${_deptCtrl.text} کا نصابِ تعلیم باقاعدہ و کامیابی کے ساتھ بتاریخ ${_gradDateCtrl.text}ء مکمل کر کے فراغت حاصل کی۔ ادارہ ان کی درازیِ عمر، حسنِ عمل اور قرآنی خدمات کے لیے دعا گو ہے۔',
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 12,
                  height: 1.8,
                  fontFamily: 'NotoNaskhArabic',
                  color: Colors.black87,
                ),
              ),
            ),

            if (_wifaqMarksCtrl.text.isNotEmpty)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                decoration: BoxDecoration(
                  color: const Color(0xFFF9F9F6),
                  border: Border.all(color: goldColor, width: 0.8),
                  borderRadius: BorderRadius.circular(5),
                ),
                child: Text(
                  'امتحان وفاق المدارس: ${_wifaqMarksCtrl.text} نمبرات (درجہ: ${_gradeCtrl.text})',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: primaryGreen,
                    fontFamily: 'NotoNaskhArabic',
                  ),
                ),
              ),

            // Footer Signatures
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Column(
                    children: [
                      Container(width: 80, height: 1, color: primaryGreen),
                      const SizedBox(height: 2),
                      const Text('دستخط ناظمِ تعلیمات',
                          style: TextStyle(fontSize: 9)),
                    ],
                  ),
                  if (_stampImageBytes != null)
                    Image.memory(_stampImageBytes!,
                        width: 40, height: 40, fit: BoxFit.contain)
                  else
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: goldColor, width: 1),
                      ),
                      child: const Center(
                        child: Text('مہر', style: TextStyle(fontSize: 8)),
                      ),
                    ),
                  Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(
                      border: Border.all(color: goldColor, width: 1),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: const Icon(Icons.qr_code,
                        size: 30, color: Colors.black87),
                  ),
                  Column(
                    children: [
                      Container(width: 80, height: 1, color: primaryGreen),
                      const SizedBox(height: 2),
                      const Text('دستخط مہتمم / پرنسپل',
                          style: TextStyle(fontSize: 9)),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildFieldSelectorChips(bool isUrdu) {
    final fields =
        _layout.entries.where((e) => e.value['visible'] != false).toList();
    return Container(
      height: 38,
      margin: const EdgeInsets.only(top: 4, bottom: 2),
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        itemCount: fields.length,
        itemBuilder: (context, idx) {
          final entry = fields[idx];
          final fKey = entry.key;
          final fCfg = entry.value;
          final isSelected = (_selectedFieldKey == fKey);
          final label = (fCfg['label'] ?? fKey).toString();

          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3),
            child: ChoiceChip(
              visualDensity: VisualDensity.compact,
              label: Text(
                label,
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                  color: isSelected ? Colors.white : primaryGreen,
                ),
              ),
              selected: isSelected,
              selectedColor: Colors.orange.shade800,
              backgroundColor: Colors.white,
              side: BorderSide(
                color:
                    isSelected ? Colors.orange.shade800 : Colors.grey.shade300,
                width: isSelected ? 1.5 : 1,
              ),
              onSelected: (val) {
                setState(() {
                  _selectedFieldKey = val ? fKey : null;
                });
              },
            ),
          );
        },
      ),
    );
  }

  Widget _buildPrecisionNudgeBar(bool isUrdu) {
    if (_selectedFieldKey == null || !_layout.containsKey(_selectedFieldKey)) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: Colors.orange.shade50,
          borderRadius: BorderRadius.circular(6),
        ),
        child: Row(
          children: [
            Icon(Icons.touch_app, size: 14, color: Colors.orange.shade800),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                isUrdu
                    ? '💡 کسی فیلڈ پر کلک کریں یا اوپر سے فیلڈ منتخب کر کے نَج بٹن استعمال کریں۔'
                    : '💡 Tap any field or select from above to adjust with precision buttons.',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade800),
              ),
            ),
            InkWell(
              onTap: _resetLayout,
              child: Text(
                isUrdu ? 'اصل ری سیٹ' : 'Reset',
                style: const TextStyle(
                    fontSize: 11,
                    color: Colors.red,
                    fontWeight: FontWeight.bold),
              ),
            ),
          ],
        ),
      );
    }

    final fCfg = _layout[_selectedFieldKey]!;
    final label = (fCfg['label'] ?? _selectedFieldKey!).toString();
    final posX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
    final posY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.orange.shade50,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: Colors.orange.shade300),
      ),
      child: Wrap(
        spacing: 4,
        runSpacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        alignment: WrapAlignment.spaceBetween,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: Colors.orange.shade800,
              borderRadius: BorderRadius.circular(4),
            ),
            child: Text(
              '$label (X: ${posX.toStringAsFixed(1)}%, Y: ${posY.toStringAsFixed(1)}%)',
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 11,
                  fontWeight: FontWeight.bold),
            ),
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              _buildNudgeBtn(Icons.arrow_forward, 'دائیں (+0.5%)',
                  () => _nudge(dx: -0.5, dy: 0)),
              _buildNudgeBtn(Icons.arrow_back, 'بائیں (-0.5%)',
                  () => _nudge(dx: 0.5, dy: 0)),
              _buildNudgeBtn(Icons.arrow_upward, 'اوپر (-0.5%)',
                  () => _nudge(dx: 0, dy: -0.5)),
              _buildNudgeBtn(Icons.arrow_downward, 'نیچے (+0.5%)',
                  () => _nudge(dx: 0, dy: 0.5)),
              const SizedBox(width: 4),
              _buildNudgeBtn(Icons.text_decrease, 'سائز کم (A-)',
                  () => _adjustFontSize(-2)),
              _buildNudgeBtn(Icons.text_increase, 'سائز زیادہ (A+)',
                  () => _adjustFontSize(2)),
              const SizedBox(width: 4),
              IconButton(
                visualDensity: VisualDensity.compact,
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 26, minHeight: 26),
                icon: const Icon(Icons.tune, color: Colors.blue, size: 18),
                tooltip: isUrdu ? 'رنگ، فانٹ اور ٹیکسٹ ترتیب' : 'Properties',
                onPressed: () => _openFieldConfigSheet(_selectedFieldKey!),
              ),
              IconButton(
                visualDensity: VisualDensity.compact,
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 26, minHeight: 26),
                icon: const Icon(Icons.close, color: Colors.grey, size: 18),
                tooltip: isUrdu ? 'انتخاب ختم کریں' : 'Deselect',
                onPressed: () => setState(() => _selectedFieldKey = null),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildNudgeBtn(IconData icon, String tooltip, VoidCallback onTap) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 1.5),
      child: IconButton(
        visualDensity: VisualDensity.compact,
        padding: const EdgeInsets.all(2),
        constraints: const BoxConstraints(minWidth: 28, minHeight: 28),
        icon: Icon(icon, size: 16, color: primaryGreen),
        tooltip: tooltip,
        style: IconButton.styleFrom(
          backgroundColor: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(4),
            side: BorderSide(color: Colors.grey.shade400, width: 0.8),
          ),
        ),
        onPressed: onTap,
      ),
    );
  }

  void _nudge({required double dx, required double dy}) {
    if (_selectedFieldKey == null || !_layout.containsKey(_selectedFieldKey))
      return;
    final fCfg = _layout[_selectedFieldKey]!;
    setState(() {
      final curX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
      final curY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;
      double newX = (curX + dx).clamp(0.0, 100.0);
      double newY = (curY + dy).clamp(0.0, 100.0);
      fCfg['x'] = double.parse(newX.toStringAsFixed(1));
      fCfg['y'] = double.parse(newY.toStringAsFixed(1));
    });
  }

  void _adjustFontSize(double delta) {
    if (_selectedFieldKey == null || !_layout.containsKey(_selectedFieldKey))
      return;
    final fCfg = _layout[_selectedFieldKey]!;
    setState(() {
      final curSize = (fCfg['size'] as num?)?.toDouble() ?? 24.0;
      double newSize = (curSize + delta).clamp(10.0, 120.0);
      fCfg['size'] = double.parse(newSize.toStringAsFixed(1));
    });
  }

  Widget _buildCustomBackgroundPreview() {
    if (_bgImageBytes == null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.image_not_supported,
                size: 45, color: Colors.grey.shade400),
            const SizedBox(height: 8),
            const Text(
              'کسٹم بیک گراؤنڈ تصویر موجود نہیں ہے',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
            ),
            const SizedBox(height: 8),
            ElevatedButton.icon(
              onPressed: _pickCustomBackground,
              icon: const Icon(Icons.add_photo_alternate, size: 16),
              label: const Text('گیلری سے بیک گراؤنڈ منتخب کریں'),
              style: ElevatedButton.styleFrom(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
              ),
            ),
          ],
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final w = constraints.maxWidth;
        final h = constraints.maxHeight;

        List<Widget> children = [
          Positioned.fill(
            child: Image.memory(_bgImageBytes!, fit: BoxFit.fill),
          ),
        ];

        // Gather all visible field keys
        final visibleKeys =
            _layout.keys.where((k) => _layout[k]['visible'] != false).toList();

        // 🌟 CRITICAL FIX: Sort so that _selectedFieldKey is rendered LAST (at top of z-index)!
        // This guarantees that the selected field catches all touch/mouse events cleanly.
        visibleKeys.sort((a, b) {
          if (a == _selectedFieldKey) return 1;
          if (b == _selectedFieldKey) return -1;
          return 0;
        });

        for (final fKey in visibleKeys) {
          final fCfg = _layout[fKey]!;
          final posX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
          final posY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;
          final size = (fCfg['size'] as num?)?.toDouble() ?? 24.0;
          final color = _parseColor(fCfg['color']?.toString());
          final align = _parseAlign(fCfg['align']?.toString());
          final isSelected = (_selectedFieldKey == fKey);

          // Relative preview scaling (standard screen preview is approx ~0.45 of print points)
          final previewScale = w / 842.0; // 842 is A4 width in pt
          final fieldSize = (size * previewScale).clamp(8.0, 48.0);

          final rightPx = (posX / 100.0) * w;
          final topPx = (posY / 100.0) * h;

          Widget fieldContent;

          if (fKey == 'stamp') {
            if (_stampImageBytes != null) {
              fieldContent = Image.memory(_stampImageBytes!,
                  width: fieldSize, height: fieldSize, fit: BoxFit.contain);
            } else {
              fieldContent = Container(
                width: fieldSize,
                height: fieldSize,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: goldColor, width: 1),
                ),
                child: Center(
                    child: Text('مہر',
                        style: TextStyle(
                            fontSize: (fieldSize * 0.25).clamp(7.0, 14.0)))),
              );
            }
          } else if (fKey == 'qr_code') {
            fieldContent = Container(
              width: fieldSize,
              height: fieldSize,
              color: Colors.white,
              child: const Icon(Icons.qr_code, size: 24),
            );
          } else {
            final text = _getFieldText(fKey);
            fieldContent = IntrinsicWidth(
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: w * 0.7, minWidth: 20),
                child: Text(
                  text,
                  textAlign: align,
                  style: TextStyle(
                    fontSize: fieldSize,
                    color: color,
                    fontFamily: 'NotoNaskhArabic',
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            );
          }

          final isGraphic = fKey == 'stamp' || fKey == 'qr_code';
          final posRight =
              isGraphic ? (rightPx - (fieldSize / 2)) : (rightPx - 40);
          final posTop = isGraphic ? (topPx - (fieldSize / 2)) : topPx;

          children.add(
            Positioned(
              right: posRight,
              top: posTop,
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onPanStart: (_) {
                  setState(() {
                    _selectedFieldKey = fKey;
                  });
                },
                onPanUpdate: (details) {
                  // Only allow movement if this is the currently active/selected field!
                  if (_selectedFieldKey != fKey) return;
                  final dxPercent = (details.delta.dx / w) * 100.0;
                  final dyPercent = (details.delta.dy / h) * 100.0;
                  setState(() {
                    final curX = (fCfg['x'] as num?)?.toDouble() ?? 50.0;
                    final curY = (fCfg['y'] as num?)?.toDouble() ?? 50.0;
                    double newX = (curX - dxPercent).clamp(0.0, 100.0);
                    double newY = (curY + dyPercent).clamp(0.0, 100.0);
                    fCfg['x'] = double.parse(newX.toStringAsFixed(1));
                    fCfg['y'] = double.parse(newY.toStringAsFixed(1));
                  });
                },
                onTap: () {
                  setState(() {
                    _selectedFieldKey = fKey;
                  });
                },
                onDoubleTap: () {
                  setState(() {
                    _selectedFieldKey = fKey;
                  });
                  _openFieldConfigSheet(fKey);
                },
                child: Container(
                  decoration: BoxDecoration(
                    border: isSelected
                        ? Border.all(color: Colors.orange.shade800, width: 2.0)
                        : Border.all(color: Colors.transparent, width: 1.0),
                    borderRadius: BorderRadius.circular(4),
                    color: isSelected ? Colors.orange.withOpacity(0.15) : null,
                  ),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (isSelected)
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 4, vertical: 1),
                          margin: const EdgeInsets.only(bottom: 2),
                          decoration: BoxDecoration(
                            color: Colors.orange.shade800,
                            borderRadius: BorderRadius.circular(3),
                          ),
                          child: Text(
                            '${fCfg['label'] ?? fKey} (${posX.toStringAsFixed(0)}%, ${posY.toStringAsFixed(0)}%)',
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 8,
                                fontWeight: FontWeight.bold),
                          ),
                        ),
                      fieldContent,
                    ],
                  ),
                ),
              ),
            ),
          );
        }

        return Stack(children: children);
      },
    );
  }
}
