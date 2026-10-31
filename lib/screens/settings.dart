import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/session.dart';
import '../utils/network_service.dart';
import '../utils/system_type_global.dart';
import '../utils/sync_service.dart';
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
                _madrasaNameController.text =
                    s['madrasa_name']?.toString() ?? '';
                _masjidNameController.text =
                    s['masjid_name']?.toString() ?? 'Ù…Ø³Ø¬Ø¯ Ø§Ø´Ø±Ù Ø§Ù„Ù…Ø¯Ø§Ø±Ø³';
                _taglineController.text = s['tagline']?.toString() ?? '';
                _phoneController.text = s['phone']?.toString() ?? '';
                _addressController.text = s['address']?.toString() ?? '';
                _hijriYearController.text =
                    s['current_hijri_year']?.toString() ?? '1447';
                _geminiKeyController.text =
                    s['gemini_api_key']?.toString() ?? '';
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
                if (_logoPath != null && _logoPath!.isNotEmpty)
                  currentTenantLogoPath.value = _logoPath;
                if (s['masjid_name'] != null &&
                    s['masjid_name'].toString().trim().isNotEmpty) {
                  currentTenantMasjidName.value =
                      s['masjid_name'].toString().trim();
                }
                if (_masjidLogoPath != null && _masjidLogoPath!.isNotEmpty)
                  currentTenantMasjidLogoPath.value = _masjidLogoPath;
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
          _masjidNameController.text =
              s['masjid_name']?.toString() ?? 'Ù…Ø³Ø¬Ø¯ Ø§Ø´Ø±Ù Ø§Ù„Ù…Ø¯Ø§Ø±Ø³';
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
          if (_logoPath != null && _logoPath!.isNotEmpty)
            currentTenantLogoPath.value = _logoPath;
          if (s['masjid_name'] != null &&
              s['masjid_name'].toString().trim().isNotEmpty) {
            currentTenantMasjidName.value = s['masjid_name'].toString().trim();
          }
          if (_masjidLogoPath != null && _masjidLogoPath!.isNotEmpty)
            currentTenantMasjidLogoPath.value = _masjidLogoPath;
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
      _showError('ØªØµÙˆÛŒØ± Ù…Ù†ØªØ®Ø¨ Ú©Ø±Ù†Û’ Ù…ÛŒÚº Ø®Ø±Ø§Ø¨ÛŒ: $e');
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
              localMap['signature_stamp_path'] =
                  serverSettings['signature_stamp_path'];
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

          localMap['is_synced'] = 1;
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
                  permissions: (sess['permissions'] as List?)
                      ?.map((e) => e.toString())
                      .toList(),
                  token: sess['token']?.toString(),
                  name: sess['name']?.toString(),
                  assignedClasses: (sess['assignedClasses'] as List?)
                      ?.map((e) => e.toString())
                      .toList(),
                  studentIds: (sess['studentIds'] as List?)
                      ?.map((e) => int.tryParse(e.toString()) ?? 0)
                      .toList(),
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
                    ? "Ø³ÛŒÙ¹Ù†Ú¯Ø² Ø§ÙˆØ± Ú©Ø³Ù¹Ù… Ø¨Ø±Ø§Ù†ÚˆÙ†Ú¯ Ú©Ø§Ù…ÛŒØ§Ø¨ÛŒ Ø³Û’ Ø§Ù¾ÚˆÛŒÙ¹ ÛÙˆ Ú¯Ø¦ÛŒÚº!"
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
          'is_synced': 0,
        };
        final db = await DBHelper().database;
        await db.update('settings', localMap, where: 'id = 1');
        SyncService.runTwoWaySync().catchError((_) {});
      } catch (_) {}
      _showError(
          'Ú©Ù†Ú©Ø´Ù† Ø®Ø±Ø§Ø¨ÛŒ: Ø³ÛŒÙ¹Ù†Ú¯Ø² Ù„ÙˆÚ©Ù„ Ù…Ø­ÙÙˆØ¸ ÛÙˆ Ú¯Ø¦ÛŒÚº Ù…Ú¯Ø± Ø³Ø±ÙˆØ± Ø³Û’ Ø±Ø§Ø¨Ø·Û Ù†ÛÛŒÚº ÛÙˆ Ø³Ú©Ø§Û”');
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
                title: Text(
                    isUrdu
                        ? "Ø³Ø§ÙÙ¹ ÙˆÛŒØ¦Ø± Ø³ÛŒÙ¹Ù†Ú¯ Ùˆ Ø¨Ø±Ø§Ù†ÚˆÙ†Ú¯"
                        : "Settings & Branding",
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
                              ? "ÚˆÛŒÙ¹Ø§ Ø³Ù†Ú© ÛÙˆ Ø±ÛØ§ ÛÛ’..."
                              : "Syncing data...")));
                      try {
                        await _loadSettings();
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "Ø³ÛŒÙ¹Ù†Ú¯Ø² Ø±ÛŒ ÙØ±ÛŒØ´ ÛÙˆ Ú¯Ø¦ÛŒÚº!"
                                  : "Settings refreshed!"),
                              backgroundColor: Colors.green));
                        }
                      } catch (e) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrdu
                                  ? "Ø±ÛŒ ÙØ±ÛŒØ´ Ù…ÛŒÚº Ø®Ø±Ø§Ø¨ÛŒ Ø¢Ø¦ÛŒ"
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
                                      isUrdu
                                          ? "Ø²Ø¨Ø§Ù† Ùˆ Ø¸Ø§ÛØ±ÛŒ Ø­Ø§Ù„Øª"
                                          : "Language & Theme",
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
                                  title: const Text("Ø§Ø±Ø¯Ùˆ",
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
                                              ? "ÚˆØ§Ø±Ú© Ù…ÙˆÚˆ (Dark Mode)"
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

                          // 1b. Active System Mode Switcher (Madrasa vs Masjid)
                          ValueListenableBuilder<String>(
                            valueListenable: SystemTypeGlobal.currentSystemType,
                            builder: (context, activeSys, _) {
                              final isMasjid = activeSys == 'Masjid';
                              return NeoBox(
                                padding: 20,
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.stretch,
                                  children: [
                                    Row(
                                      children: [
                                        Icon(
                                            isMasjid
                                                ? Icons.mosque
                                                : Icons.school,
                                            color: primaryGreen),
                                        const SizedBox(width: 10),
                                        Text(
                                          isUrdu
                                              ? "ÙØ¹Ø§Ù„ Ø³Ø³Ù¹Ù… Ù…ÙˆÚˆ (Active Mode)"
                                              : "Active System Mode",
                                          style: TextStyle(
                                            fontSize: 18,
                                            color: textDark,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Text(
                                      isUrdu
                                          ? "Ù…Ø¯Ø±Ø³Û ÛŒØ§ Ù…Ø³Ø¬Ø¯ Ù…ÙˆÚˆ Ù…Ù†ØªØ®Ø¨ Ú©Ø±ÛŒÚºØŒ Ø§ÛŒÙ¾ Ú©Ø§ ÚˆÛŒÙ¹Ø§ Ø§ÙˆØ± Ø§Ù†Ù¹Ø±ÙÛŒØ³ Ø®ÙˆØ¯Ú©Ø§Ø± ØªØ¨Ø¯ÛŒÙ„ ÛÙˆ Ø¬Ø§Ø¦Û’ Ú¯Ø§:"
                                          : "Select Madrasa or Masjid mode, the app interface and data will adapt automatically:",
                                      style: TextStyle(
                                          fontSize: 12,
                                          color: Colors.grey.shade700),
                                    ),
                                    const SizedBox(height: 14),
                                    Row(
                                      children: [
                                        Expanded(
                                          child: InkWell(
                                            onTap: () {
                                              SystemTypeGlobal.currentSystemType
                                                  .value = 'Madrasa';
                                            },
                                            borderRadius:
                                                BorderRadius.circular(10),
                                            child: Container(
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                      vertical: 12,
                                                      horizontal: 8),
                                              decoration: BoxDecoration(
                                                color: !isMasjid
                                                    ? primaryGreen
                                                    : Colors.grey.shade100,
                                                borderRadius:
                                                    BorderRadius.circular(10),
                                                border: Border.all(
                                                  color: !isMasjid
                                                      ? primaryGreen
                                                      : Colors.grey.shade300,
                                                  width: 1.5,
                                                ),
                                              ),
                                              child: Row(
                                                mainAxisAlignment:
                                                    MainAxisAlignment.center,
                                                children: [
                                                  Icon(Icons.school,
                                                      size: 20,
                                                      color: !isMasjid
                                                          ? Colors.white
                                                          : Colors.black87),
                                                  const SizedBox(width: 8),
                                                  Text(
                                                    isUrdu
                                                        ? 'Ù…Ø¯Ø±Ø³Û Ù…ÙˆÚˆ'
                                                        : 'Madrasa Mode',
                                                    style: TextStyle(
                                                      fontWeight:
                                                          FontWeight.bold,
                                                      fontSize: 14,
                                                      color: !isMasjid
                                                          ? Colors.white
                                                          : Colors.black87,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: InkWell(
                                            onTap: () {
                                              SystemTypeGlobal.currentSystemType
                                                  .value = 'Masjid';
                                            },
                                            borderRadius:
                                                BorderRadius.circular(10),
                                            child: Container(
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                      vertical: 12,
                                                      horizontal: 8),
                                              decoration: BoxDecoration(
                                                color: isMasjid
                                                    ? primaryGreen
                                                    : Colors.grey.shade100,
                                                borderRadius:
                                                    BorderRadius.circular(10),
                                                border: Border.all(
                                                  color: isMasjid
                                                      ? primaryGreen
                                                      : Colors.grey.shade300,
                                                  width: 1.5,
                                                ),
                                              ),
                                              child: Row(
                                                mainAxisAlignment:
                                                    MainAxisAlignment.center,
                                                children: [
                                                  Icon(Icons.mosque,
                                                      size: 20,
                                                      color: isMasjid
                                                          ? Colors.white
                                                          : Colors.black87),
                                                  const SizedBox(width: 8),
                                                  Text(
                                                    isUrdu
                                                        ? 'Ù…Ø³Ø¬Ø¯ Ù…ÙˆÚˆ'
                                                        : 'Masjid Mode',
                                                    style: TextStyle(
                                                      fontWeight:
                                                          FontWeight.bold,
                                                      fontSize: 14,
                                                      color: isMasjid
                                                          ? Colors.white
                                                          : Colors.black87,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                              );
                            },
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
                                    Icon(Icons.account_balance,
                                        color: primaryGreen),
                                    const SizedBox(width: 10),
                                    Text(
                                      isUrdu
                                          ? "Ù…Ø¯Ø±Ø³Û Ù…Ø¹Ù„ÙˆÙ…Ø§Øª (Madrasa Profile)"
                                          : "Madrasa Profile",
                                      style: TextStyle(
                                          fontSize: 18,
                                          color: textDark,
                                          fontWeight: FontWeight.bold),
                                    )
                                  ],
                                ),
                                const SizedBox(height: 15),
                                _buildTextField(
                                    _madrasaNameController,
                                    isUrdu
                                        ? "Ù…Ø¯Ø±Ø³Û Ú©Ø§ Ø¢ÙÛŒØ´Ù„ Ù†Ø§Ù…"
                                        : "Madrasa Official Name"),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _masjidNameController,
                                    isUrdu
                                        ? "Ù…Ø³Ø¬Ø¯ Ú©Ø§ Ø¢ÙÛŒØ´Ù„ Ù†Ø§Ù… (Masjid Name)"
                                        : "Masjid Official Name"),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _taglineController,
                                    isUrdu
                                        ? "Ø³Ù„ÙˆÚ¯Ù† / Ø°ÛŒÙ„ÛŒ Ø¹Ù†ÙˆØ§Ù† (Tagline)"
                                        : "Tagline"),
                                const SizedBox(height: 10),
                                _buildTextField(_phoneController,
                                    isUrdu ? "Ø±Ø§Ø¨Ø·Û ÙÙˆÙ† Ù†Ù…Ø¨Ø±" : "Phone Number"),
                                const SizedBox(height: 10),
                                _buildTextField(_addressController,
                                    isUrdu ? "Ù…Ø¯Ø±Ø³Û Ú©Ø§ Ù…Ú©Ù…Ù„ Ù¾ØªÛ" : "Address",
                                    maxLines: 2),
                                const SizedBox(height: 10),
                                _buildTextField(
                                    _hijriYearController,
                                    isUrdu
                                        ? "Ù…ÙˆØ¬ÙˆØ¯Û ÛØ¬Ø±ÛŒ Ø³Ø§Ù„ (Ø¬ÛŒØ³Û’ 1447)"
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
                                          ? "Ú©Ø³Ù¹Ù… Ø¨Ø±Ø§Ù†ÚˆÙ†Ú¯ Ùˆ Ø¯Ø³ØªØ§ÙˆÛŒØ²Ø§Øª"
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
                                      ? "Ù…ÙˆØ¨Ø§Ø¦Ù„ Ú¯ÛŒÙ„Ø±ÛŒ Ø³Û’ Ù„ÙˆÚ¯ÙˆØŒ Ù…ÛØ± Ø§ÙˆØ± Ø±Ø³ÛŒØ¯/Ø³Ù†Ø¯ Ú©Û’ Ø¨ÛŒÚ© Ú¯Ø±Ø§Ø¤Ù†ÚˆØ² Ø§Ù¾Ù„ÙˆÚˆ Ú©Ø±ÛŒÚºÛ”"
                                      : "Upload custom logo, stamp and document templates from gallery.",
                                  style: TextStyle(
                                      fontSize: 12,
                                      color: Colors.grey.shade700),
                                ),
                                const SizedBox(height: 15),

                                // 1. Official Logo
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ù…Ø¯Ø±Ø³Û Ú©Ø§ Ø¢ÙÛŒØ´Ù„ Ù„ÙˆÚ¯Ùˆ"
                                      : "Official Logo",
                                  subtitle: isUrdu
                                      ? "Ø±Ø³ÛŒØ¯ØŒ Ú©Ø§Ø±Úˆ Ø§ÙˆØ± Ø³Ù†Ø¯ Ù¾Ø± Ù†Ù…Ø§ÛŒØ§Úº ÛÙˆÚ¯Ø§"
                                      : "Displayed on receipts, cards and sanad",
                                  newFile: _newLogoFile,
                                  serverPath: _logoPath,
                                  onPick: () => _pickImage('logo'),
                                  isUrdu: isUrdu,
                                ),

                                // 1b. Official Masjid Logo
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ù…Ø³Ø¬Ø¯ Ú©Ø§ Ø¢ÙÛŒØ´Ù„ Ù„ÙˆÚ¯Ùˆ"
                                      : "Masjid Official Logo",
                                  subtitle: isUrdu
                                      ? "Ù…Ø³Ø¬Ø¯ Ù…ÙˆÚˆ Ø±Ø³ÛŒØ¯ÙˆÚº Ø§ÙˆØ± Ø±Ù¾ÙˆØ±Ù¹Ø³ Ù¾Ø± Ù†Ù…Ø§ÛŒØ§Úº ÛÙˆÚ¯Ø§"
                                      : "Displayed on masjid receipts and reports",
                                  newFile: _newMasjidLogoFile,
                                  serverPath: _masjidLogoPath,
                                  onPick: () => _pickImage('masjid_logo'),
                                  isUrdu: isUrdu,
                                ),

                                // 2. Official Stamp / Signature
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ù¾Ø±Ù†Ø³Ù¾Ù„ / Ù…ÛØªÙ…Ù… Ú©Û’ Ø¯Ø³ØªØ®Ø· Ùˆ Ù…ÛØ±"
                                      : "Stamp / Signature",
                                  subtitle: isUrdu
                                      ? "Ø±Ø³ÛŒØ¯ Ø§ÙˆØ± Ø³Ù†Ø¯ Ù¾Ø± Ø®ÙˆØ¯Ú©Ø§Ø± Ø´Ø§Ù…Ù„ ÛÙˆÚ¯ÛŒ"
                                      : "Auto-applied on receipts and certificates",
                                  newFile: _newStampFile,
                                  serverPath: _signatureStampPath,
                                  onPick: () => _pickImage('stamp'),
                                  isUrdu: isUrdu,
                                ),

                                // 3. Madrasa Receipt Background
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ù…Ø¯Ø±Ø³Û Ø±Ø³ÛŒØ¯ Ú©Ø³Ù¹Ù… Ø¨ÛŒÚ© Ú¯Ø±Ø§Ø¤Ù†Úˆ"
                                      : "Receipt Custom Background",
                                  subtitle: isUrdu
                                      ? "Ú†Ú¾Ù¾ÛŒ ÛÙˆØ¦ÛŒ Ø±Ø³ÛŒØ¯ Ú©Ø§ Ø§Ø³Ú©ÛŒÙ† Ø´Ø¯Û Ù¾Ø³ Ù…Ù†Ø¸Ø±"
                                      : "Pre-printed receipt watermark/template",
                                  newFile: _newReceiptBgFile,
                                  serverPath: _receiptBgPath,
                                  onPick: () => _pickImage('receipt_bg'),
                                  isUrdu: isUrdu,
                                ),

                                // 4. Student ID Card Background
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ø³Ù¹ÙˆÚˆÙ†Ù¹ Ú©Ø§Ø±Úˆ Ú©Ø³Ù¹Ù… Ø¨ÛŒÚ© Ú¯Ø±Ø§Ø¤Ù†Úˆ"
                                      : "ID Card Background",
                                  subtitle: isUrdu
                                      ? "Ø³Ù¹ÛŒÙ†ÚˆØ±Úˆ Ú©Ø§Ø±Úˆ Ø³Ø§Ø¦Ø² (CR80)"
                                      : "Standard card dimensions (CR80)",
                                  newFile: _newIdCardBgFile,
                                  serverPath: _idCardBgPath,
                                  onPick: () => _pickImage('id_card_bg'),
                                  isUrdu: isUrdu,
                                ),

                                // 5. Official Sanad Background
                                _buildBrandingItem(
                                  title: isUrdu
                                      ? "Ø¢ÙÛŒØ´Ù„ Ø³Ù†Ø¯ Ú©Ø§ Ú©Ø³Ù¹Ù… Ø¨ÛŒÚ© Ú¯Ø±Ø§Ø¤Ù†Úˆ"
                                      : "Sanad Custom Background",
                                  subtitle: isUrdu
                                      ? "ÙØ§Ø±ØºÛŒÙ† Ú©ÛŒ Ø®Ø§Ù„ÛŒ Ø³Ù†Ø¯ Ú©Ø§ ÙØ±ÛŒÙ… (A4 Ù„ÛŒÙ†Úˆ Ø§Ø³Ú©ÛŒÙ¾)"
                                      : "Certificate frame (A4 landscape)",
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
                                          ? "Ø§Û’ Ù¾ÛŒ Ø¢Ø¦ÛŒ Ùˆ Ø¢Ù¹ÙˆÙ…ÛŒØ´Ù† Ø³ÛŒÙ¹Ù†Ú¯Ø²"
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
                                        value: 'gemini',
                                        child: Text('Google Gemini')),
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
                              isUrdu
                                  ? "ØªÙ…Ø§Ù… Ø³ÛŒÙ¹Ù†Ú¯Ø² Ùˆ Ø¨Ø±Ø§Ù†ÚˆÙ†Ú¯ Ù…Ø­ÙÙˆØ¸ Ú©Ø±ÛŒÚº"
                                  : "Save All Settings & Branding",
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 16),
                            ),
                          ),
                          const SizedBox(height: 16),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 13),
                                    side: BorderSide(color: primaryGreen),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                  ),
                                  onPressed: () {
                                    Navigator.push(
                                      context,
                                      MaterialPageRoute(
                                        builder: (_) =>
                                            const MadrasaSetupWizardScreen(),
                                      ),
                                    ).then((_) => _loadSettings());
                                  },
                                  icon: Icon(Icons.auto_fix_high,
                                      color: primaryGreen, size: 18),
                                  label: Text(
                                    isUrdu ? "Ø³ÛŒÙ¹ Ø§Ù¾ ÙˆØ²Ø±Úˆ" : "Setup Wizard",
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
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 13),
                                    side: const BorderSide(
                                        color: Colors.blueGrey),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                  ),
                                  onPressed: () async {
                                    String base = apiUrl.trim();
                                    if (!base.endsWith('/')) base += '/';
                                    final uri = Uri.parse('${base}privacy.php');
                                    if (await canLaunchUrl(uri)) {
                                      await launchUrl(uri,
                                          mode: LaunchMode.externalApplication);
                                    }
                                  },
                                  icon: const Icon(Icons.privacy_tip_outlined,
                                      color: Colors.blueGrey, size: 18),
                                  label: Text(
                                    isUrdu
                                        ? "Ù¾Ø±Ø§Ø¦ÛŒÙˆÛŒØ³ÛŒ Ù¾Ø§Ù„ÛŒØ³ÛŒ"
                                        : "Privacy Policy",
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
      if (serverPath.startsWith('http')) {
        fullServerUrl = serverPath;
      } else {
        String base = (NetworkService.activeBaseUrl ?? apiUrl).trim();
        if (!base.endsWith('/')) base += '/';
        fullServerUrl = base + serverPath.replaceFirst(RegExp(r'^/+'), '');
      }
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
                          ? 'Ù†Ø¦ÛŒ ÙØ§Ø¦Ù„ Ù…Ù†ØªØ®Ø¨ ÛÛ’ (Ù…Ø­ÙÙˆØ¸ Ú©Ø±ÛŒÚº)'
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
                ? (isUrdu ? "ØªØ¨Ø¯ÛŒÙ„" : "Change")
                : (isUrdu ? "Ø§Ù¾Ù„ÙˆÚˆ" : "Upload")),
            style: ElevatedButton.styleFrom(
              backgroundColor: primaryGreen,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              textStyle:
                  const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
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
