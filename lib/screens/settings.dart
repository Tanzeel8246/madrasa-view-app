import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/session.dart';
import '../utils/network_service.dart';
import 'package:url_launcher/url_launcher.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import 'dashboard.dart';
import 'madrasa_setup_wizard.dart';

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key});

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  final _madrasaNameController = TextEditingController();
  final _masjidNameController = TextEditingController();
  final _taglineController = TextEditingController();
  final _phoneController = TextEditingController();
  final _addressController = TextEditingController();
  final _hijriYearController = TextEditingController();
  final _geminiKeyController = TextEditingController();
  final _groqKeyController = TextEditingController();
  final _whatsappKeyController = TextEditingController();
  final _smsKeyController = TextEditingController();

  String? _logoPath;
  String? _masjidLogoPath;
  String? _signatureStampPath;
  String? _receiptBgPath;
  String? _idCardBgPath;
  String? _sanadBgPath;

  File? _newLogoFile;
  File? _newMasjidLogoFile;
  File? _newStampFile;
  File? _newReceiptBgFile;
  File? _newIdCardBgFile;
  File? _newSanadBgFile;

  final ImagePicker _picker = ImagePicker();

  String _aiProvider = 'gemini';
  int _weekStartDay = 6;
  int _autoNotifyDay = 4;
  String _autoNotifyTime = '18:00';

  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadSettings();
  }

  Future<void> _loadSettings() async {
    try {
      // 1. Try fetching from server via sync_down
      try {
        final res = await NetworkService.getWithFallbackAction('sync_down')
            .timeout(const Duration(seconds: 10));
        if (res.statusCode == 200) {
          final body = jsonDecode(res.body);
          if (body['data']?['settings'] != null) {
            final s = body['data']['settings'];
            await DBHelper().saveServerData(body['data']);
            if (mounted) {
              setState(() {
                _madrasaNameController.text = s['madrasa_name']?.toString() ?? '';
                _masjidNameController.text = s['masjid_name']?.toString() ?? 'مسجد اشرف المدارس';
                _taglineController.text = s['tagline']?.toString() ?? '';
                _phoneController.text = s['phone']?.toString() ?? '';
                _addressController.text = s['address']?.toString() ?? '';
                _hijriYearController.text =
                    s['current_hijri_year']?.toString() ?? '1447';
                _geminiKeyController.text = s['gemini_api_key']?.toString() ?? '';
                _groqKeyController.text = s['groq_api_key']?.toString() ?? '';
                _whatsappKeyController.text =
                    s['whatsapp_api_key']?.toString() ?? '';
                _smsKeyController.text = s['sms_api_key']?.toString() ?? '';
                _aiProvider = s['ai_provider']?.toString().isNotEmpty == true
                    ? s['ai_provider'].toString()
                    : 'gemini';
                _weekStartDay =
                    int.tryParse(s['week_start_day']?.toString() ?? '6') ?? 6;
                _autoNotifyDay =
                    int.tryParse(s['auto_notify_day']?.toString() ?? '4') ?? 4;
                _autoNotifyTime =
                    s['auto_notify_time']?.toString().isNotEmpty == true
                        ? s['auto_notify_time'].toString()
                        : '18:00';
                _logoPath = s['logo_path']?.toString();
                _masjidLogoPath = s['masjid_logo_path']?.toString();
                _signatureStampPath = s['signature_stamp_path']?.toString();
                _receiptBgPath = s['receipt_bg']?.toString();
                _idCardBgPath = s['id_card_bg']?.toString();
                _sanadBgPath = s['sanad_bg']?.toString();
                _isLoading = false;

                // Keep live branding notifiers in sync
                if (_logoPath != null && _logoPath!.isNotEmpty) currentTenantLogoPath.value = _logoPath;
                if (s['masjid_name'] != null && s['masjid_name'].toString().trim().isNotEmpty) {
                  currentTenantMasjidName.value = s['masjid_name'].toString().trim();
                }
                if (_masjidLogoPath != null && _masjidLogoPath!.isNotEmpty) currentTenantMasjidLogoPath.value = _masjidLogoPath;
              });
            }
            return;
          }
        }
      } catch (_) {}

      // 2. Fallback to local SQLite settings
      final s = await DBHelper().getSettings();
      if (mounted) {
        setState(() {
          _madrasaNameController.text = s['madrasa_name']?.toString() ?? '';
          _masjidNameController.text = s['masjid_name']?.toString() ?? 'مسجد اشرف المدارس';
          _taglineController.text = s['tagline']?.toString() ?? '';
          _phoneController.text = s['phone']?.toString() ?? '';
          _addressController.text = s['address']?.toString() ?? '';
          _hijriYearController.text =
              s['current_hijri_year']?.toString() ?? '1447';
          _geminiKeyController.text = s['gemini_api_key']?.toString() ?? '';
          _groqKeyController.text = s['groq_api_key']?.toString() ?? '';
          _whatsappKeyController.text = s['whatsapp_api_key']?.toString() ?? '';
          _smsKeyController.text = s['sms_api_key']?.toString() ?? '';
          _aiProvider = s['ai_provider']?.toString().isNotEmpty == true
              ? s['ai_provider'].toString()
              : 'gemini';
          _weekStartDay =
              int.tryParse(s['week_start_day']?.toString() ?? '6') ?? 6;
          _autoNotifyDay =
              int.tryParse(s['auto_notify_day']?.toString() ?? '4') ?? 4;
          _autoNotifyTime = s['auto_notify_time']?.toString().isNotEmpty == true
              ? s['auto_notify_time'].toString()
              : '18:00';
          _logoPath = s['logo_path']?.toString();
          _masjidLogoPath = s['masjid_logo_path']?.toString();
          _signatureStampPath = s['signature_stamp_path']?.toString();
          _receiptBgPath = s['receipt_bg']?.toString();
          _idCardBgPath = s['id_card_bg']?.toString();
          _sanadBgPath = s['sanad_bg']?.toString();
          _isLoading = false;

          // Keep live branding notifiers in sync
          if (_logoPath != null && _logoPath!.isNotEmpty) currentTenantLogoPath.value = _logoPath;
          if (s['masjid_name'] != null && s['masjid_name'].toString().trim().isNotEmpty) {
            currentTenantMasjidName.value = s['masjid_name'].toString().trim();
          }
          if (_masjidLogoPath != null && _masjidLogoPath!.isNotEmpty) currentTenantMasjidLogoPath.value = _masjidLogoPath;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _pickImage(String type) async {
    try {
      final picked = await _picker.pickImage(
        source: ImageSource.gallery,
        imageQuality: 85,
      );
      if (picked != null) {
        setState(() {
          if (type == 'logo') _newLogoFile = File(picked.path);
          if (type == 'masjid_logo') _newMasjidLogoFile = File(picked.path);
          if (type == 'stamp') _newStampFile = File(picked.path);
          if (type == 'receipt_bg') _newReceiptBgFile = File(picked.path);
          if (type == 'id_card_bg') _newIdCardBgFile = File(picked.path);
          if (type == 'sanad_bg') _newSanadBgFile = File(picked.path);
        });
      }
    } catch (e) {
      _showError('تصویر منتخب کرنے میں خرابی: $e');
    }
  }

  Future<void> _updateSettings() async {
    setState(() => _isLoading = true);

    final payload = <String, dynamic>{
      'madrasa_name': _madrasaNameController.text,
      'masjid_name': _masjidNameController.text,
      'tagline': _taglineController.text,
      'phone': _phoneController.text,
      'address': _addressController.text,
      'current_hijri_year': _hijriYearController.text,
      'week_start_day': _weekStartDay,
      'ai_provider': _aiProvider,
      'auto_notify_day': _autoNotifyDay,
      'auto_notify_time': _autoNotifyTime,
    };

    if (_geminiKeyController.text.trim().isNotEmpty) {
      payload['gemini_api_key'] = _geminiKeyController.text.trim();
    }
    if (_groqKeyController.text.trim().isNotEmpty) {
      payload['groq_api_key'] = _groqKeyController.text.trim();
    }
    if (_whatsappKeyController.text.trim().isNotEmpty) {
      payload['whatsapp_api_key'] = _whatsappKeyController.text.trim();
    }
    if (_smsKeyController.text.trim().isNotEmpty) {
      payload['sms_api_key'] = _smsKeyController.text.trim();
    }

    // Attach base64 images if newly picked
    if (_newLogoFile != null) {
      final bytes = await _newLogoFile!.readAsBytes();
      payload['logo_base64'] = base64Encode(bytes);
    }
    if (_newMasjidLogoFile != null) {
      final bytes = await _newMasjidLogoFile!.readAsBytes();
      payload['masjid_logo_base64'] = base64Encode(bytes);
    }
    if (_newStampFile != null) {
      final bytes = await _newStampFile!.readAsBytes();
      payload['signature_stamp_base64'] = base64Encode(bytes);
    }
    if (_newReceiptBgFile != null) {
      final bytes = await _newReceiptBgFile!.readAsBytes();
      payload['receipt_bg_base64'] = base64Encode(bytes);
    }
    if (_newIdCardBgFile != null) {
      final bytes = await _newIdCardBgFile!.readAsBytes();
      payload['id_card_bg_base64'] = base64Encode(bytes);
    }
    if (_newSanadBgFile != null) {
      final bytes = await _newSanadBgFile!.readAsBytes();
      payload['sanad_bg_base64'] = base64Encode(bytes);
    }

    try {
      final res = await NetworkService.postWithFallbackAction(
          'update_settings', payload);
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 'success') {
          final serverSettings = data['settings'];
          
          final localMap = <String, dynamic>{
            'madrasa_name': _madrasaNameController.text,
            'masjid_name': _masjidNameController.text,
            'tagline': _taglineController.text,
            'phone': _phoneController.text,
            'address': _addressController.text,
            'current_hijri_year': _hijriYearController.text,
            'week_start_day': _weekStartDay,
            'ai_provider': _aiProvider,
            'auto_notify_day': _autoNotifyDay,
            'auto_notify_time': _autoNotifyTime,
          };

          if (serverSettings != null) {
            if (serverSettings['logo_path'] != null) {
              localMap['logo_path'] = serverSettings['logo_path'];
              _logoPath = serverSettings['logo_path'];
            }
            if (serverSettings['masjid_logo_path'] != null) {
              localMap['masjid_logo_path'] = serverSettings['masjid_logo_path'];
              _masjidLogoPath = serverSettings['masjid_logo_path'];
            }
            if (serverSettings['signature_stamp_path'] != null) {
              localMap['signature_stamp_path'] = serverSettings['signature_stamp_path'];
              _signatureStampPath = serverSettings['signature_stamp_path'];
            }
            if (serverSettings['receipt_bg'] != null) {
              localMap['receipt_bg'] = serverSettings['receipt_bg'];
              _receiptBgPath = serverSettings['receipt_bg'];
            }
            if (serverSettings['id_card_bg'] != null) {
              localMap['id_card_bg'] = serverSettings['id_card_bg'];
              _idCardBgPath = serverSettings['id_card_bg'];
            }
            if (serverSettings['sanad_bg'] != null) {
              localMap['sanad_bg'] = serverSettings['sanad_bg'];
              _sanadBgPath = serverSettings['sanad_bg'];
            }
          }

          final db = await DBHelper().database;
          await db.update('settings', localMap, where: 'id = 1');

          // Update currentTenantName global so Drawer and UI update immediately
          final newName = _madrasaNameController.text.trim();
          if (newName.isNotEmpty) {
            currentTenantName.value = newName;
            // Also update stored session with new tenant name
            try {
              final sess = await SessionManager.getSession();
              if (sess != null) {
                await SessionManager.saveSession(
                  sess['role']?.toString() ?? '',
                  int.tryParse(sess['userId']?.toString() ?? '0') ?? 0,
                  permissions: (sess['permissions'] as List?)?.map((e) => e.toString()).toList(),
                  token: sess['token']?.toString(),
                  name: sess['name']?.toString(),
                  assignedClasses: (sess['assignedClasses'] as List?)?.map((e) => e.toString()).toList(),
                  studentIds: (sess['studentIds'] as List?)?.map((e) => int.tryParse(e.toString()) ?? 0).toList(),
                  tenantCode: sess['tenantCode']?.toString(),
                  tenantId: int.tryParse(sess['tenantId']?.toString() ?? '0'),
                  tenantName: newName,
                );
              }
            } catch (_) {}
          }

          final newMasjidName = _masjidNameController.text.trim();
          if (newMasjidName.isNotEmpty) {
            currentTenantMasjidName.value = newMasjidName;
          }
          if (_logoPath != null && _logoPath!.isNotEmpty) {
            currentTenantLogoPath.value = _logoPath;
          }
          if (_masjidLogoPath != null && _masjidLogoPath!.isNotEmpty) {
            currentTenantMasjidLogoPath.value = _masjidLogoPath;
          }

          setState(() {
            _newLogoFile = null;
            _newMasjidLogoFile = null;
            _newStampFile = null;
            _newReceiptBgFile = null;
            _newIdCardBgFile = null;
            _newSanadBgFile = null;
          });

          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
                content: Text(isUrduGlobal.value
                    ? "سیٹنگز اور کسٹم برانڈنگ کامیابی سے اپڈیٹ ہو گئیں!"
                    : "Settings & branding updated successfully!"),
                backgroundColor: Colors.green),
          );
        } else {
          _showError(data['message'] ?? 'Error updating settings');
        }
      } else {
        _showError('Server Error: ${res.statusCode}');
      }
    } catch (e) {
      try {
        final localMap = <String, dynamic>{
          'madrasa_name': _madrasaNameController.text,
          'masjid_name': _masjidNameController.text,
          'tagline': _taglineController.text,
          'phone': _phoneController.text,
          'address': _addressController.text,
          'current_hijri_year': _hijriYearController.text,
          'week_start_day': _weekStartDay,
          'ai_provider': _aiProvider,
          'auto_notify_day': _autoNotifyDay,
          'auto_notify_time': _autoNotifyTime,
        };
        final db = await DBHelper().database;
        await db.update('settings', localMap, where: 'id = 1');
      } catch (_) {}
      _showError('کنکشن خرابی: سیٹنگز لوکل محفوظ ہو گئیں مگر سرور سے رابطہ نہیں ہو سکا۔');
    }
    setState(() => _isLoading = false);
  }

  void _showError(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: Colors.red),
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
            Navigator.pushReplacement(context,
                MaterialPageRoute(builder: (c) => const DashboardPage()));
          },
          child: Directionality(
            textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
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
                title: Text(isUrdu ? "سافٹ ویئر سیٹنگ و برانڈنگ" : "Settings & Branding",
                    style: const TextStyle(
                        color: Colors.white, fontWeight: FontWeight.bold)),
                backgroundColor: primaryGreen,
                elevation: 0,
                iconTheme: const IconThemeData(color: Colors.white),
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
                        await _loadSettings();
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "سیٹنگز ری فریش ہو گئیں!"
                                  : "Settings refreshed!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "ری فریش میں خرابی آئی"
                                  : "Refresh failed"),
                              backgroundColor: Colors.red));
                        }
                      }
                    },
                  ),
                ],
              ),
              body: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : Padding(
                      padding: const EdgeInsets.all(15.0),
                      child: ListView(
                        children: [
                          // 1. Language & Theme Card
                          NeoBox(
                            padding: 20,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Row(
                                  children: [
                                    Icon(Icons.language, color: primaryGreen),
                                    const SizedBox(width: 10),
                                    Text(
                                      isUrdu ? "زبان و ظاہری حالت" : "Language & Theme",
                                      style: TextStyle(
                                          fontSize: 18,
                                          color: textDark,
                                          fontWeight: FontWeight.bold),
                                    )
                                  ],
                                ),
                                const SizedBox(width: 10),
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  title: const Text("اردو",
                                      style: TextStyle(
                                          fontWeight: FontWeight.bold)),
                                  trailing: Switch(
                                    value: isUrdu,
                                    activeThumbColor: primaryGreen,
                                    onChanged: (val) {
                                      isUrduGlobal.value = val;
                                    },
                                  ),
                                ),
                                const Divider(),
                                ValueListenableBuilder<bool>(
                                  valueListenable: isDarkMode,
                                  builder: (context, isDark, _) {
                                    return ListTile(
                                      contentPadding: EdgeInsets.zero,
                                      leading: Icon(
                                          isDark
                                              ? Icons.dark_mode
                                              : Icons.light_mode,
                                          color: primaryGreen),
                                      title: Text(
                                          isUrdu
                                              ? "ڈارک موڈ (Dark Mode)"
                                              : "Dark Mode",
                                          style: const TextStyle(
                                              fontWeight: FontWeight.bold)),
                                      trailing: Switch(
                                        value: isDark,
                                        activeThumbColor: primaryGreen,
                                        onChanged: (val) async {
                                          isDarkMode.value = val;
                                          try {
                                            const storage =
                                                FlutterSecureStorage();
                                            await storage.write(
                                                key: 'is_dark_mode',
                                                value: val.toString());
                                          } catch (_) {}
                                        },
                                      ),
                                    );
                                  },
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),

                          // 2. Madrasa Profile & Info
                          NeoBox(
                            padding: 20,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Row(
                                  children: [
                                    Icon(Icons.account_balance, color: primaryGreen),
                                    const SizedBox(width: 10),
                                    Text(
                                      isUrdu
                                          ? "مدرسہ معلومات (Madrasa Profile)"
                                          : "Madrasa Profile",
                                      style: TextStyle(
                                          fontSize: 18,
                                          color: textDark,
                                          fontWeight: FontWeight.bold),
                                    )
                                  ],
                                ),
                                const SizedBox(height: 15),
                                _buildTextField(_madrasaNameController,
                                    isUrdu ? "مدرسہ کا آفیشل نام" : "Madrasa Official Name"),
                                const SizedBox(height: 10),
                                _buildTextField(_masjidNameController,
                                    isUrdu ? "مسجد کا آفیشل نام (Masjid Name)" : "Masjid Official Name"),
                                const SizedBox(height: 10),
                                _buildTextField(_taglineController,
                                    isUrdu ? "سلوگن / ذیلی عنوان (Tagline)" : "Tagline"),
                                const SizedBox(height: 10),
                                _buildTextField(_phoneController,
                                    isUrdu ? "رابطہ فون نمبر" : "Phone Number"),
                                const SizedBox(height: 10),
                                _buildTextField(_addressController,
                                    isUrdu ? "مدرسہ کا مکمل پتہ" : "Address",
                                    maxLines: 2),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _hijriYearController,
                                    isUrdu
                                        ? "موجودہ ہجری سال (جیسے 1447)"
                                        : "Current Hijri Year"),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),

                          // 3. Custom Branding & Documents Uploads (NEW)
                          NeoBox(
                            padding: 20,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Row(
                                  children: [
                                    Icon(Icons.brush, color: primaryGreen),
                                    const SizedBox(width: 10),
                                    Text(
                                      isUrdu
                                          ? "کسٹم برانڈنگ و دستاویزات"
                                          : "Custom Branding & Documents",
                                      style: TextStyle(
                                          fontSize: 18,
                                          color: textDark,
                                          fontWeight: FontWeight.bold),
                                    )
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  isUrdu
                                      ? "موبائل گیلری سے لوگو، مہر اور رسید/سند کے بیک گراؤنڈز اپلوڈ کریں۔"
                                      : "Upload custom logo, stamp and document templates from gallery.",
                                  style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                ),
                                const SizedBox(height: 15),

                                // 1. Official Logo
                                _buildBrandingItem(
                                  title: isUrdu ? "مدرسہ کا آفیشل لوگو" : "Official Logo",
                                  subtitle: isUrdu ? "رسید، کارڈ اور سند پر نمایاں ہوگا" : "Displayed on receipts, cards and sanad",
                                  newFile: _newLogoFile,
                                  serverPath: _logoPath,
                                  onPick: () => _pickImage('logo'),
                                  isUrdu: isUrdu,
                                ),

                                // 1b. Official Masjid Logo
                                _buildBrandingItem(
                                  title: isUrdu ? "مسجد کا آفیشل لوگو" : "Masjid Official Logo",
                                  subtitle: isUrdu ? "مسجد موڈ رسیدوں اور رپورٹس پر نمایاں ہوگا" : "Displayed on masjid receipts and reports",
                                  newFile: _newMasjidLogoFile,
                                  serverPath: _masjidLogoPath,
                                  onPick: () => _pickImage('masjid_logo'),
                                  isUrdu: isUrdu,
                                ),

                                // 2. Official Stamp / Signature
                                _buildBrandingItem(
                                  title: isUrdu ? "پرنسپل / مہتمم کے دستخط و مہر" : "Stamp / Signature",
                                  subtitle: isUrdu ? "رسید اور سند پر خودکار شامل ہوگی" : "Auto-applied on receipts and certificates",
                                  newFile: _newStampFile,
                                  serverPath: _signatureStampPath,
                                  onPick: () => _pickImage('stamp'),
                                  isUrdu: isUrdu,
                                ),

                                // 3. Madrasa Receipt Background
                                _buildBrandingItem(
                                  title: isUrdu ? "مدرسہ رسید کسٹم بیک گراؤنڈ" : "Receipt Custom Background",
                                  subtitle: isUrdu ? "چھپی ہوئی رسید کا اسکین شدہ پس منظر" : "Pre-printed receipt watermark/template",
                                  newFile: _newReceiptBgFile,
                                  serverPath: _receiptBgPath,
                                  onPick: () => _pickImage('receipt_bg'),
                                  isUrdu: isUrdu,
                                ),

                                // 4. Student ID Card Background
                                _buildBrandingItem(
                                  title: isUrdu ? "سٹوڈنٹ کارڈ کسٹم بیک گراؤنڈ" : "ID Card Background",
                                  subtitle: isUrdu ? "سٹینڈرڈ کارڈ سائز (CR80)" : "Standard card dimensions (CR80)",
                                  newFile: _newIdCardBgFile,
                                  serverPath: _idCardBgPath,
                                  onPick: () => _pickImage('id_card_bg'),
                                  isUrdu: isUrdu,
                                ),

                                // 5. Official Sanad Background
                                _buildBrandingItem(
                                  title: isUrdu ? "آفیشل سند کا کسٹم بیک گراؤنڈ" : "Sanad Custom Background",
                                  subtitle: isUrdu ? "فارغین کی خالی سند کا فریم (A4 لینڈ اسکیپ)" : "Certificate frame (A4 landscape)",
                                  newFile: _newSanadBgFile,
                                  serverPath: _sanadBgPath,
                                  onPick: () => _pickImage('sanad_bg'),
                                  isUrdu: isUrdu,
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),

                          // 4. API Keys & Automation
                          NeoBox(
                            padding: 20,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Row(
                                  children: [
                                    Icon(Icons.api, color: primaryGreen),
                                    const SizedBox(width: 10),
                                    Text(
                                      isUrdu
                                          ? "اے پی آئی و آٹومیشن سیٹنگز"
                                          : "API & Automation Settings",
                                      style: TextStyle(
                                          fontSize: 18,
                                          color: textDark,
                                          fontWeight: FontWeight.bold),
                                    )
                                  ],
                                ),
                                const SizedBox(height: 15),
                                DropdownButtonFormField<String>(
                                  value: _aiProvider,
                                  decoration: const InputDecoration(
                                      labelText: 'AI Provider',
                                      border: OutlineInputBorder()),
                                  items: const [
                                    DropdownMenuItem(
                                        value: 'gemini', child: Text('Google Gemini')),
                                    DropdownMenuItem(
                                        value: 'groq',
                                        child: Text('Groq (Llama)')),
                                  ],
                                  onChanged: (v) =>
                                      setState(() => _aiProvider = v!),
                                ),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _geminiKeyController, "Gemini API Keys",
                                    maxLines: 2),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _groqKeyController, "Groq API Keys",
                                    maxLines: 2),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _whatsappKeyController, "WhatsApp API Key",
                                    obscure: true),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _smsKeyController, "SMS API Key",
                                    obscure: true),
                              ],
                            ),
                          ),
                          const SizedBox(height: 25),

                          // Submit Button
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: primaryGreen,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(10)),
                              elevation: 4,
                            ),
                            onPressed: _updateSettings,
                            icon: const Icon(Icons.save, size: 20),
                            label: Text(
                              isUrdu ? "تمام سیٹنگز و برانڈنگ محفوظ کریں" : "Save All Settings & Branding",
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 16),
                            ),
                          ),
                          const SizedBox(height: 16),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(
                                    padding: const EdgeInsets.symmetric(vertical: 13),
                                    side: BorderSide(color: primaryGreen),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                  ),
                                  onPressed: () {
                                    Navigator.push(
                                      context,
                                      MaterialPageRoute(
                                        builder: (_) => const MadrasaSetupWizardScreen(),
                                      ),
                                    ).then((_) => _loadSettings());
                                  },
                                  icon: Icon(Icons.auto_fix_high, color: primaryGreen, size: 18),
                                  label: Text(
                                    isUrdu ? "سیٹ اپ وزرڈ" : "Setup Wizard",
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: primaryGreen,
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(
                                    padding: const EdgeInsets.symmetric(vertical: 13),
                                    side: const BorderSide(color: Colors.blueGrey),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                  ),
                                  onPressed: () async {
                                    String base = apiUrl.trim();
                                    if (!base.endsWith('/')) base += '/';
                                    final uri = Uri.parse('${base}privacy.php');
                                    if (await canLaunchUrl(uri)) {
                                      await launchUrl(uri, mode: LaunchMode.externalApplication);
                                    }
                                  },
                                  icon: const Icon(Icons.privacy_tip_outlined, color: Colors.blueGrey, size: 18),
                                  label: Text(
                                    isUrdu ? "پرائیویسی پالیسی" : "Privacy Policy",
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: Colors.blueGrey,
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 40),
                        ],
                      ),
                    ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildBrandingItem({
    required String title,
    required String subtitle,
    required File? newFile,
    required String? serverPath,
    required VoidCallback onPick,
    required bool isUrdu,
  }) {
    String? fullServerUrl;
    if (serverPath != null && serverPath.isNotEmpty) {
      String base = apiUrl.trim();
      if (!base.endsWith('/')) base += '/';
      fullServerUrl = base + serverPath.replaceFirst(RegExp(r'^/+'), '');
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Row(
        children: [
          Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(
              color: Colors.grey.shade100,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: Colors.grey.shade300),
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(7),
              child: newFile != null
                  ? Image.file(newFile, fit: BoxFit.cover)
                  : (fullServerUrl != null
                      ? Image.network(
                          fullServerUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) =>
                              const Icon(Icons.image, color: Colors.grey),
                        )
                      : const Icon(Icons.image, color: Colors.grey)),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14),
                ),
                const SizedBox(height: 2),
                Text(
                  newFile != null
                      ? (isUrdu
                          ? 'نئی فائل منتخب ہے (محفوظ کریں)'
                          : 'New file selected (Save now)')
                      : subtitle,
                  style: TextStyle(
                    fontSize: 11,
                    color: newFile != null
                        ? Colors.green.shade700
                        : Colors.grey.shade600,
                    fontWeight:
                        newFile != null ? FontWeight.bold : FontWeight.normal,
                  ),
                ),
              ],
            ),
          ),
          ElevatedButton.icon(
            onPressed: onPick,
            icon: const Icon(Icons.upload_file, size: 16),
            label: Text(newFile != null
                ? (isUrdu ? "تبدیل" : "Change")
                : (isUrdu ? "اپلوڈ" : "Upload")),
            style: ElevatedButton.styleFrom(
              backgroundColor: primaryGreen,
              foregroundColor: Colors.white,
              padding:
                  const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              textStyle: const TextStyle(
                  fontSize: 12, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTextField(TextEditingController controller, String label,
      {bool obscure = false, int maxLines = 1}) {
    return TextField(
      controller: controller,
      obscureText: obscure && maxLines == 1,
      maxLines: maxLines,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 10, vertical: 15),
      ),
    );
  }
}
