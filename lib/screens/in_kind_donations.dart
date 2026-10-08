// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../widgets/madrasa_card.dart';
import '../widgets/madrasa_inputs.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:path_provider/path_provider.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../utils/system_type_global.dart';
import '../utils/urdu_number_words.dart';
import '../utils/hijri_helper.dart';
import '../widgets/app_drawer.dart';

class InKindDonationsScreen extends StatefulWidget {
  const InKindDonationsScreen({super.key});

  @override
  State<InKindDonationsScreen> createState() => _InKindDonationsScreenState();
}

class _InKindDonationsScreenState extends State<InKindDonationsScreen> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final ScreenshotController _screenshotController = ScreenshotController();

  final _dateController = TextEditingController();
  final _donorNameController = TextEditingController();
  final _donorPhoneController = TextEditingController();
  final _donorAddressController = TextEditingController();
  final _itemNameController = TextEditingController();
  final _quantityController = TextEditingController();
  final _valueController = TextEditingController();
  final _descController = TextEditingController();

  List<Map<String, dynamic>> _records = [];
  bool _isSaving = false;
  int? _editId;

  @override
  void initState() {
    super.initState();
    _dateController.text = _formatDate(DateTime.now());
    SystemTypeGlobal.currentSystemType.addListener(_loadRecords);
    _loadRecords();
  }

  @override
  void dispose() {
    SystemTypeGlobal.currentSystemType.removeListener(_loadRecords);
    _dateController.dispose();
    _donorNameController.dispose();
    _donorPhoneController.dispose();
    _donorAddressController.dispose();
    _itemNameController.dispose();
    _quantityController.dispose();
    _valueController.dispose();
    _descController.dispose();
    super.dispose();
  }

  String _formatDate(DateTime date) {
    return '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
  }

  Future<void> _loadRecords() async {
    final db = DBHelper();
    final data = await db.getInKindDonations();
    if (!mounted) return;
    setState(() {
      _records = data;
    });

    if (data.isEmpty) {
      SyncService.runTwoWaySync().then((_) async {
        if (!mounted) return;
        final refreshed = await DBHelper().getInKindDonations();
        if (mounted && refreshed.isNotEmpty) {
          setState(() {
            _records = refreshed;
          });
        }
      }).catchError((_) {});
    }
  }

  Future<void> _saveRecord() async {
    if (_dateController.text.isEmpty ||
        _donorNameController.text.isEmpty ||
        _itemNameController.text.isEmpty ||
        _quantityController.text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(isUrduGlobal.value ? "تاریخ، نام، شے اور مقدار ضروری ہیں" : "Date, Name, Item & Qty are required")),
      );
      return;
    }
    
    setState(() => _isSaving = true);
    final db = DBHelper();
    final currentSys = SystemTypeGlobal.currentSystemType.value;
    
    // Get next receipt number
    int nextReceiptNo = 1;
    try {
      final dbInstance = await db.database;
      int minNo = 100000;
      int maxNo = 200000;
      final currentMaxRes = await dbInstance.rawQuery("SELECT MAX(computer_receipt_no) as max_no FROM in_kind_donations WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND (is_deleted = 0 OR is_deleted IS NULL)", [minNo, maxNo]);
      int currentMax = currentMaxRes.first['max_no'] != null ? currentMaxRes.first['max_no'] as int : minNo;
      nextReceiptNo = currentMax + 1;
    } catch (_) {}

    try {
      Map<String, dynamic>? newlySavedRecord;
      if (_editId != null) {
        await db.updateInKindDonation(_editId!, {
          'date': _dateController.text,
          'donor_name': _donorNameController.text,
          'donor_phone': _donorPhoneController.text,
          'donor_address': _donorAddressController.text,
          'item_name': _itemNameController.text,
          'quantity': _quantityController.text,
          'estimated_value': _valueController.text.isNotEmpty ? double.tryParse(_valueController.text) : null,
          'description': _descController.text,
          'system_type': currentSys,
        });
        _editId = null;
      } else {
        final row = {
          'date': _dateController.text,
          'donor_name': _donorNameController.text,
          'donor_phone': _donorPhoneController.text,
          'donor_address': _donorAddressController.text,
          'item_name': _itemNameController.text,
          'quantity': _quantityController.text,
          'estimated_value': _valueController.text.isNotEmpty ? double.tryParse(_valueController.text) : null,
          'description': _descController.text,
          'computer_receipt_no': nextReceiptNo,
          'system_type': currentSys,
          'user_id': currentUserId.value ?? 1,
          'user_name': currentUserName.value ?? 'Admin',
        };
        final insertedId = await db.insertInKindDonation(row);
        newlySavedRecord = Map<String, dynamic>.from(row);
        newlySavedRecord['id'] = insertedId;
      }
      
      _donorNameController.clear();
      _donorPhoneController.clear();
      _donorAddressController.clear();
      _itemNameController.clear();
      _quantityController.clear();
      _valueController.clear();
      _descController.clear();
      
      await _loadRecords();
      if (mounted) {
        if (newlySavedRecord != null) {
          _showReceiptDialog(newlySavedRecord);
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(isUrduGlobal.value ? "محفوظ ہو گیا" : "Saved successfully"), backgroundColor: Colors.green),
          );
        }
      }
    } catch (e) {
      debugPrint("Save error: $e");
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text("Error: $e"), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _deleteRecord(int id) async {
    final db = DBHelper();
    await db.deleteInKindDonation(id);
    _loadRecords();
  }

  void _showAddModal() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: bgColor,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) {
        return Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom, left: 16, right: 16, top: 20),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(isUrduGlobal.value ? 'نیا عطیہ (اشیاء)' : 'New In-Kind Donation', style: TextStyle(color: primaryGreen, fontSize: 18, fontWeight: FontWeight.bold)),
                const SizedBox(height: 15),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'تاریخ' : 'Date'), controller: _dateController, readOnly: true, onTap: () async {
                  final d = await showDatePicker(context: context, initialDate: DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime(2100));
                  if (d != null) _dateController.text = _formatDate(d);
                }),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'عطیہ دہندہ کا نام' : 'Donor Name'), controller: _donorNameController),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'فون نمبر (اختیاری)' : 'Phone (Optional)'), controller: _donorPhoneController, keyboardType: TextInputType.phone),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'پتہ (اختیاری)' : 'Address (Optional)'), controller: _donorAddressController),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'شے (مثلاً: چاول، کپڑے، کتابیں)' : 'Item (e.g. Clothes, Food)'), controller: _itemNameController),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'مقدار / تعداد' : 'Quantity'), controller: _quantityController),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'تخمینی مالیت (اختیاری)' : 'Estimated Value (Optional)'), controller: _valueController, keyboardType: TextInputType.number),
                const SizedBox(height: 10),
                MadrasaTextField(decoration: InputDecoration(labelText: isUrduGlobal.value ? 'تفصیل' : 'Description'), controller: _descController),
                const SizedBox(height: 20),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 15)),
                    onPressed: () {
                      Navigator.pop(ctx);
                      _saveRecord();
                    },
                    child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                ),
                const SizedBox(height: 20),
              ],
            ),
          ),
        );
      }
    );
  }

  void _editRecord(Map<String, dynamic> r) {
    setState(() {
      _editId = r['id'];
      _dateController.text = r['date'] ?? '';
      _donorNameController.text = r['donor_name'] ?? '';
      _donorPhoneController.text = r['donor_phone'] ?? '';
      _donorAddressController.text = r['donor_address'] ?? '';
      _itemNameController.text = r['item_name'] ?? '';
      _quantityController.text = r['quantity']?.toString() ?? '';
      _valueController.text = r['estimated_value']?.toString() ?? '';
      _descController.text = r['description'] ?? '';
    });
    _showAddModal();
  }

  void _showReceiptDialog(Map<String, dynamic> r) {
    final isMasjid = (SystemTypeGlobal.currentSystemType.value == 'Masjid') || (r['system_type'] == 'Masjid');
    final rawReceiptNo = r['computer_receipt_no']?.toString() ?? r['id']?.toString() ?? '';
    final String displayReceiptNo = isMasjid ? 'M-$rawReceiptNo' : rawReceiptNo;
    final String donorName = (r['donor_name']?.toString() ?? '').trim();
    final String dateStr = (r['date']?.toString() ?? '').trim();
    final String address = (r['donor_address']?.toString() ?? '').trim();
    final String phone = (r['donor_phone']?.toString() ?? '').trim();
    final String fullAddress = [
      if (address.isNotEmpty) address,
      if (phone.isNotEmpty) 'فون: $phone',
    ].join(' | ');

    final String itemName = (r['item_name']?.toString() ?? '').trim();
    final String quantity = (r['quantity']?.toString() ?? '').trim();
    final double? estVal = double.tryParse(r['estimated_value']?.toString() ?? '');

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext dialogContext) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            return Dialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
              backgroundColor: Colors.white,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Screenshot(
                      controller: _screenshotController,
                      child: Container(
                        width: double.infinity,
                        color: Colors.white,
                        child: LayoutBuilder(
                          builder: (context, constraints) {
                            final w = constraints.maxWidth;
                            final receiptTextStyle = TextStyle(
                              fontSize: w * 0.034,
                              fontWeight: FontWeight.w700,
                              color: Colors.black,
                              height: 1.25,
                              decoration: TextDecoration.underline,
                              decorationStyle: TextDecorationStyle.dotted,
                              decorationColor: Colors.black45,
                            );

                            final isLongName = donorName.length > 20;
                            final nameHeight = isLongName ? 1.4 : 1.25;
                            final addressTop = isLongName ? w * 0.388 : w * 0.375;

                            return AspectRatio(
                              aspectRatio: 1.6,
                              child: Stack(
                                children: [
                                  Positioned.fill(
                                    child: Image.asset(
                                      isMasjid ? 'assets/masjid_receipt_bg.webp' : 'assets/receipt_bg.webp',
                                      fit: BoxFit.fill,
                                      errorBuilder: (_, __, ___) => Image.asset(
                                        'assets/receipt_bg.webp',
                                        fit: BoxFit.fill,
                                      ),
                                    ),
                                  ),
                                  // رسید نمبر
                                  Positioned(
                                    top: w * 0.203,
                                    left: w * 0.12,
                                    child: Text(
                                      displayReceiptNo,
                                      style: receiptTextStyle.copyWith(
                                        fontSize: w * 0.036,
                                        height: 1.0,
                                        decoration: TextDecoration.none,
                                      ),
                                    ),
                                  ),
                                  // عطیہ دہندہ نام
                                  Positioned(
                                    top: w * 0.288,
                                    right: w * 0.06,
                                    left: w * 0.42,
                                    child: Text(
                                      'محترم جناب: $donorName',
                                      textDirection: ui.TextDirection.rtl,
                                      textAlign: TextAlign.right,
                                      style: receiptTextStyle.copyWith(height: nameHeight),
                                    ),
                                  ),
                                  // تاریخ و ہجری تاریخ
                                  Positioned(
                                    top: w * 0.297,
                                    left: w * 0.08,
                                    child: Text(
                                      'تاریخ: $dateStr ${HijriHelper.toUrduHijri(dateStr).isNotEmpty ? '(${HijriHelper.toUrduHijri(dateStr)})' : ''}',
                                      textDirection: ui.TextDirection.rtl,
                                      style: receiptTextStyle.copyWith(
                                        height: 1.0,
                                        fontSize: w * 0.027,
                                        decoration: TextDecoration.none,
                                      ),
                                    ),
                                  ),
                                  // پتہ و فون
                                  Positioned(
                                    top: addressTop,
                                    right: w * 0.06,
                                    left: w * 0.35,
                                    child: Text(
                                      'پتہ: ${fullAddress.isEmpty ? '-' : fullAddress}',
                                      textDirection: ui.TextDirection.rtl,
                                      textAlign: TextAlign.right,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: receiptTextStyle,
                                    ),
                                  ),
                                  // مد / شے و مقدار
                                  Positioned(
                                    top: w * 0.458,
                                    right: w * 0.06,
                                    left: w * 0.38,
                                    child: Text(
                                      'مد: عطیات (اشیاء) - $itemName ($quantity)',
                                      textDirection: ui.TextDirection.rtl,
                                      textAlign: TextAlign.right,
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: receiptTextStyle,
                                    ),
                                  ),
                                  // تخمینی مالیت اور اردو الفاظ
                                  Positioned(
                                    top: w * 0.458,
                                    left: w * 0.145,
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Text(
                                          (estVal != null && estVal > 0)
                                              ? 'Rs: ${NumberFormat('#,##0').format(estVal)}/-'
                                              : 'تعداد: $quantity',
                                          textDirection: ui.TextDirection.ltr,
                                          style: receiptTextStyle.copyWith(
                                            height: 1.0,
                                            decoration: TextDecoration.none,
                                          ),
                                        ),
                                        if (estVal != null && estVal > 0) ...[
                                          SizedBox(height: w * 0.004),
                                          Text(
                                            '(${UrduNumberWords.convert(estVal)})',
                                            textDirection: ui.TextDirection.rtl,
                                            style: receiptTextStyle.copyWith(
                                              fontSize: w * 0.024,
                                              fontWeight: FontWeight.w600,
                                              color: Colors.black87,
                                              height: 1.1,
                                              decoration: TextDecoration.none,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  // دستخط وصول کنندہ
                                  Positioned(
                                    bottom: w * 0.038,
                                    right: w * 0.06,
                                    left: w * 0.30,
                                    child: Row(
                                      textDirection: ui.TextDirection.rtl,
                                      crossAxisAlignment: CrossAxisAlignment.center,
                                      children: [
                                        Text(
                                          'دستخط وصول کنندہ: ________________',
                                          textDirection: ui.TextDirection.rtl,
                                          style: receiptTextStyle.copyWith(
                                            fontSize: w * 0.027,
                                            color: Colors.black87,
                                            decoration: TextDecoration.none,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            );
                          },
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          Expanded(
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: primaryGreen,
                                foregroundColor: Colors.white,
                              ),
                              onPressed: () async {
                                try {
                                  final image = await _screenshotController.capture();
                                  if (image == null) return;
                                  final directory = await getApplicationDocumentsDirectory();
                                  final imagePath = await File(
                                    '${directory.path}/in_kind_receipt_${DateTime.now().millisecondsSinceEpoch}.png',
                                  ).create();
                                  await imagePath.writeAsBytes(image);
                                  await Share.shareXFiles(
                                    [XFile(imagePath.path)],
                                    text: isUrduGlobal.value ? 'رسید عطیات (اشیاء) - جامعہ اشرف المدارس' : 'In-Kind Donation Receipt',
                                  );
                                } catch (e) {
                                  debugPrint("Share error: $e");
                                }
                              },
                              icon: const Icon(Icons.share, size: 18),
                              label: Text(isUrduGlobal.value ? 'شیئر کریں' : 'Share'),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: ElevatedButton(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.grey.shade700,
                                foregroundColor: Colors.white,
                              ),
                              onPressed: () => Navigator.pop(dialogContext),
                              child: Text(isUrduGlobal.value ? 'بند کریں' : 'Close'),
                            ),
                          ),
                        ],
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
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin = role == 'admin' || role == 'superadmin' || role == 'super_admin' || role == 'administrator';
    final hasDonationPerm = isAdmin || currentUserPermissions.value.contains('manage_in_kind_donations') || currentUserPermissions.value.contains('manage_masjid_in_kind_donations');

    if (!hasDonationPerm) {
      return Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          title: Text(isUrdu ? 'عطیات (اشیاء)' : 'In-Kind Donations', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
          backgroundColor: primaryGreen,
          iconTheme: const IconThemeData(color: Colors.white),
        ),
        drawer: const AppDrawer(),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.lock_outline, size: 64, color: Colors.grey.shade400),
              const SizedBox(height: 16),
              Text(
                isUrdu ? 'آپ کے پاس اشیاء و عطیات تک رسائی کی اجازت نہیں ہے۔' : 'You do not have permission to access In-Kind Donations.',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black54),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      backgroundColor: bgColor,
      appBar: AppBar(
        title: Text(isUrduGlobal.value ? 'عطیات (اشیاء)' : 'In-Kind Donations', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
        backgroundColor: primaryGreen,
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            tooltip: isUrduGlobal.value ? 'ڈیٹا سنک کریں' : 'Sync Data',
            onPressed: () async {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text(isUrduGlobal.value ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...'),
              ));
              try {
                await SyncService.runTwoWaySync();
                await _loadRecords();
                if (!mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(isUrduGlobal.value ? 'سنک مکمل ہو گیا!' : 'Sync complete!'),
                  backgroundColor: Colors.green,
                ));
              } catch (_) {
                if (!mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(isUrduGlobal.value ? 'سنک میں خرابی آئی' : 'Sync failed'),
                  backgroundColor: Colors.red,
                ));
              }
            },
          ),
        ],
      ),
      drawer: const AppDrawer(),
      body: _isSaving
          ? const Center(child: CircularProgressIndicator())
          : ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: _records.length,
              itemBuilder: (ctx, i) {
                final r = _records[i];
                return MadrasaCard(
                  child: Padding(
                    padding: const EdgeInsets.all(12.0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(r['donor_name'] ?? '', style: TextStyle(color: primaryGreen, fontWeight: FontWeight.bold, fontSize: 16)),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(4)),
                              child: Text('رسید: ${r['computer_receipt_no']}', style: const TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text('شے: ${r['item_name']} - ${r['quantity']}'),
                        const SizedBox(height: 8),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(r['date'] ?? '', style: const TextStyle(color: Colors.grey)),
                            Row(
                              children: [
                                IconButton(
                                  icon: const Icon(Icons.edit, color: Colors.orange),
                                  onPressed: () => _editRecord(r),
                                ),
                                IconButton(
                                  icon: const Icon(Icons.receipt_long, color: Colors.blue),
                                  tooltip: isUrduGlobal.value ? 'رسید دیکھیں / شیئر کریں' : 'Receipt',
                                  onPressed: () => _showReceiptDialog(r),
                                ),
                                IconButton(
                                  icon: const Icon(Icons.delete, color: Colors.red),
                                  onPressed: () async {
                                    final conf = await showDialog<bool>(
                                      context: context,
                                      builder: (c) => AlertDialog(
                                        title: const Text('تصدیق'),
                                        content: const Text('کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟'),
                                        actions: [
                                          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('نہیں')),
                                          TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('ہاں', style: TextStyle(color: Colors.red))),
                                        ],
                                      )
                                    );
                                    if (conf == true) _deleteRecord(r['id']);
                                  },
                                ),
                              ],
                            ),
                          ],
                        )
                      ],
                    ),
                  )
                );
              },
            ),
      floatingActionButton: FloatingActionButton(
        backgroundColor: primaryGreen,
        onPressed: () {
          setState(() {
            _editId = null;
            _dateController.text = _formatDate(DateTime.now());
            _donorNameController.clear();
            _donorPhoneController.clear();
            _itemNameController.clear();
            _quantityController.clear();
            _valueController.clear();
            _descController.clear();
          });
          _showAddModal();
        },
        child: const Icon(Icons.add, color: Colors.white),
      ),
    );
  }
}
