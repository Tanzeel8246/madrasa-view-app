// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../utils/system_type_global.dart';
import 'dart:ui' as ui;

import '../widgets/neo_box.dart';

class MasjidAssetsPage extends StatefulWidget {
  const MasjidAssetsPage({super.key});
  @override
  _MasjidAssetsPageState createState() => _MasjidAssetsPageState();
}

class _MasjidAssetsPageState extends State<MasjidAssetsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color dangerColor = Colors.red.shade700;

  List<Map<String, dynamic>> _data = [];
  bool _isLoading = true;

  // Form Controllers
  final _nameCtrl = TextEditingController();
  final _categoryCtrl = TextEditingController();
  final _quantityCtrl = TextEditingController();
  final _donorNameCtrl = TextEditingController();
  final _donorFatherCtrl = TextEditingController();
  final _donorAddressCtrl = TextEditingController();
  final _donorPhoneCtrl = TextEditingController();
  
  String _condition = 'نیا (New)';
  String _assetType = 'Purchased';
  int? _editingId;

  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _resetForm();
    _loadData();
  }

  Future<void> _loadData() async {
    try {
      final db = await DBHelper().database;
      final currentSys = SystemTypeGlobal.currentSystemType.value;
      final whereClause = (currentSys == 'Masjid')
          ? "system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL)"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND (is_deleted = 0 OR is_deleted IS NULL)";
      final data = await db.query('masjid_assets', where: whereClause, orderBy: 'id DESC');
      if (!mounted) return;
      setState(() {
        _data = data;
        _isLoading = false;
      });

      if (data.isEmpty) {
        SyncService.runTwoWaySync().then((_) async {
          if (!mounted) return;
          final refreshed = await db.query('masjid_assets', where: whereClause, orderBy: 'id DESC');
          if (mounted && refreshed.isNotEmpty) {
            setState(() {
              _data = refreshed;
            });
          }
        }).catchError((_) {});
      }
    } catch (e) {
      debugPrint("Error loading masjid_assets: $e");
      if (!mounted) return;
      setState(() {
        _isLoading = false;
      });
    }
  }

  void _resetForm() {
    _editingId = null;
    _nameCtrl.clear();
    _categoryCtrl.clear();
    _quantityCtrl.text = '1';
    _donorNameCtrl.clear();
    _donorFatherCtrl.clear();
    _donorAddressCtrl.clear();
    _donorPhoneCtrl.clear();
    _condition = 'نیا (New)';
    _assetType = 'Purchased';
    setState(() {});
  }

  Future<void> _deleteItem(int id) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'تائید' : 'Confirm'),
        content: Text(isUrduGlobal.value ? 'کیا آپ یہ اثاثہ ڈیلیٹ کرنا چاہتے ہیں؟' : 'Do you want to delete this asset?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(isUrduGlobal.value ? 'نہیں' : 'No')),
          ElevatedButton(onPressed: () => Navigator.pop(context, true), child: Text(isUrduGlobal.value ? 'ہاں' : 'Yes')),
        ],
      ),
    );
    if (confirmed != true) return;
    
    final db = await DBHelper().database;
    await db.update('masjid_assets', {'is_deleted': 1, 'is_synced': 0}, where: 'id = ?', whereArgs: [id]);
    _loadData();
  }

  void _editItem(Map<String, dynamic> existing) {
    _scrollController.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
    setState(() {
      _editingId = existing['id'];
      _nameCtrl.text = existing['name']?.toString() ?? '';
      _categoryCtrl.text = existing['category']?.toString() ?? '';
      _quantityCtrl.text = existing['quantity']?.toString() ?? '1';
      _donorNameCtrl.text = existing['donor_name']?.toString() ?? '';
      _donorFatherCtrl.text = existing['donor_father']?.toString() ?? '';
      _donorAddressCtrl.text = existing['donor_address']?.toString() ?? '';
      _donorPhoneCtrl.text = existing['donor_phone']?.toString() ?? '';

      String cond = existing['condition']?.toString() ?? existing['condition_status']?.toString() ?? 'نیا (New)';
      if (!['نیا (New)', 'استعمال شدہ (Used - Good)', 'مرمت طلب (Needs Repair)', 'ناکارہ (Damaged/Broken)'].contains(cond)) {
        cond = 'نیا (New)';
      }
      _condition = cond;
      _assetType = existing['asset_type']?.toString() ?? 'Purchased';
    });
  }

  Future<void> _saveAsset() async {
    if (_nameCtrl.text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? 'نام درج کریں' : 'Enter asset name')));
      return;
    }
    
    final db = await DBHelper().database;
    final data = {
      'name': _nameCtrl.text,
      'category': _categoryCtrl.text,
      'quantity': int.tryParse(_quantityCtrl.text) ?? 1,
      'condition': _condition,
      'asset_type': _assetType,
      'donor_name': _assetType == 'Waqf' ? _donorNameCtrl.text : '',
      'donor_father': _assetType == 'Waqf' ? _donorFatherCtrl.text : '',
      'donor_address': _assetType == 'Waqf' ? _donorAddressCtrl.text : '',
      'donor_phone': _assetType == 'Waqf' ? _donorPhoneCtrl.text : '',
      'system_type': 'Masjid',
      'is_synced': 0
    };
    
    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS masjid_assets(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT,
          category TEXT,
          quantity INTEGER,
          condition TEXT,
          condition_status TEXT,
          asset_type TEXT,
          donor_name TEXT,
          donor_father TEXT,
          donor_address TEXT,
          donor_phone TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
      try {
        await db.execute('ALTER TABLE masjid_assets ADD COLUMN condition_status TEXT');
      } catch (_) {}
      try {
        await db.execute("ALTER TABLE masjid_assets ADD COLUMN system_type TEXT DEFAULT 'Masjid'");
      } catch (_) {}
      data['condition_status'] = _condition;
      data['system_type'] = SystemTypeGlobal.currentSystemType.value;

      if (_editingId != null) {
        await db.update('masjid_assets', data, where: 'id = ?', whereArgs: [_editingId]);
      } else {
        await db.insert('masjid_assets', data);
      }
      
      _resetForm();
      _loadData();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? 'محفوظ ہو گیا' : 'Saved Successfully')));
    } catch (e) {
      debugPrint("Save Asset Error: $e");
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? 'محفوظ کرنے میں خطا: $e' : 'Save Error: $e')));
    }
  }
  
  Widget _typeBtn(String label, String value) {
    bool selected = (_assetType == value);
    return InkWell(
      onTap: () {
        setState(() {
          _assetType = value;
        });
      },
      child: Container(
        height: 45,
        decoration: BoxDecoration(
          color: selected ? primaryGreen : Colors.transparent,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: primaryGreen, width: 2),
        ),
        alignment: Alignment.center,
        child: Text(
          label,
          style: TextStyle(
            color: selected ? Colors.white : primaryGreen,
            fontWeight: FontWeight.bold,
            fontSize: 14,
          ),
        ),
      ),
    );
  }

  Widget _buildInputs(bool isUrdu) {
    return NeoBox(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(flex: 2, child: TextField(controller: _nameCtrl, decoration: InputDecoration(labelText: isUrdu?'اثاثہ کا نام':'Asset Name', border: const OutlineInputBorder()))),
              const SizedBox(width: 10),
              Expanded(flex: 1, child: TextField(controller: _quantityCtrl, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: isUrdu?'تعداد':'Qty', border: const OutlineInputBorder()))),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: TextField(controller: _categoryCtrl, decoration: InputDecoration(labelText: isUrdu?'کیٹیگری':'Category', border: const OutlineInputBorder()))),
              const SizedBox(width: 10),
              Expanded(
                child: DropdownButtonFormField<String>(
                  decoration: InputDecoration(labelText: isUrdu ? 'حالت' : 'Condition', border: const OutlineInputBorder()),
                  value: _condition,
                  items: ['نیا (New)', 'استعمال شدہ (Used - Good)', 'مرمت طلب (Needs Repair)', 'ناکارہ (Damaged/Broken)']
                      .map((c) => DropdownMenuItem(value: c, child: Text(c, style: const TextStyle(fontSize: 12)))).toList(),
                  onChanged: (v) => setState(() => _condition = v!),
                ),
              ),
            ],
          ),
          const SizedBox(height: 15),
          Row(
            children: [
              Expanded(child: _typeBtn(isUrdu ? 'خریدا گیا (Purchased)' : 'Purchased', 'Purchased')),
              const SizedBox(width: 10),
              Expanded(child: _typeBtn(isUrdu ? 'وقف (Waqf)' : 'Waqf', 'Waqf')),
            ],
          ),
          
          if (_assetType == 'Waqf') ...[
            const SizedBox(height: 15),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: Colors.green.withOpacity(0.05), borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.green.shade200)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(isUrdu ? 'وقف کنندہ کی تفصیلات' : 'Waqf Donor Details', style: TextStyle(fontWeight: FontWeight.bold, color: primaryGreen)),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(child: TextField(controller: _donorNameCtrl, decoration: InputDecoration(labelText: isUrdu?'نام':'Donor Name', border: const OutlineInputBorder()))),
                      const SizedBox(width: 10),
                      Expanded(child: TextField(controller: _donorFatherCtrl, decoration: InputDecoration(labelText: isUrdu?'ولدیت':'Father Name', border: const OutlineInputBorder()))),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(child: TextField(controller: _donorPhoneCtrl, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: isUrdu?'فون نمبر':'Phone Number', border: const OutlineInputBorder()))),
                      const SizedBox(width: 10),
                      Expanded(child: TextField(controller: _donorAddressCtrl, decoration: InputDecoration(labelText: isUrdu?'پتہ':'Address', border: const OutlineInputBorder()))),
                    ],
                  ),
                ],
              ),
            )
          ]
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    final pageTitle = isMasjid 
        ? (isUrdu ? 'مسجد کے اثاثہ جات' : 'Masjid Assets')
        : (isUrdu ? 'اثاثہ جات (مدرسہ)' : 'Madrasa Assets');

    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin = role == 'admin' || role == 'superadmin' || role == 'super_admin' || role == 'administrator';
    final hasAssetPerm = isAdmin || currentUserPermissions.value.contains('manage_assets') || currentUserPermissions.value.contains('manage_masjid_assets');

    if (!hasAssetPerm) {
      return Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          title: Text(pageTitle),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
        ),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.lock_outline, size: 64, color: Colors.grey.shade400),
              const SizedBox(height: 16),
              Text(
                isUrdu ? 'آپ کے پاس اثاثہ جات تک رسائی کی اجازت نہیں ہے۔' : 'You do not have permission to access Assets.',
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
        title: Text(pageTitle),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            tooltip: isUrdu ? 'ڈیٹا سنک کریں' : 'Sync Data',
            onPressed: () async {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text(isUrdu ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...'),
              ));
              try {
                await SyncService.runTwoWaySync();
                await _loadData();
                if (!mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(isUrdu ? 'سنک مکمل ہو گیا!' : 'Sync complete!'),
                  backgroundColor: Colors.green,
                ));
              } catch (_) {
                if (!mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(isUrdu ? 'سنک میں خرابی آئی' : 'Sync failed'),
                  backgroundColor: Colors.red,
                ));
              }
            },
          ),
        ],
      ),
      body: Directionality(
        textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
        child: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                controller: _scrollController,
                padding: const EdgeInsets.all(15),
                child: Column(
                  children: [
                    _buildInputs(isUrdu),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: SizedBox(
                            height: 50,
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: primaryGreen,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                              onPressed: _saveAsset,
                              icon: const Icon(Icons.save, color: Colors.white),
                              label: Text(
                                _editingId != null ? (isUrdu ? 'اپ ڈیٹ کریں' : 'Update') : (isUrdu ? 'محفوظ کریں' : 'Save'),
                                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                              ),
                            ),
                          ),
                        ),
                        if (_editingId != null) ...[
                          const SizedBox(width: 10),
                          OutlinedButton(
                            onPressed: _resetForm,
                            style: OutlinedButton.styleFrom(side: const BorderSide(color: Colors.red)),
                            child: Text(isUrdu ? 'منسوخ' : 'Cancel', style: const TextStyle(color: Colors.red)),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 24),
                    
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
                      child: Row(
                        children: [
                          const Icon(Icons.inventory, color: Colors.black54),
                          const SizedBox(width: 8),
                          Text(isUrdu ? 'اثاثہ جات کی فہرست' : 'Assets List', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
                        ],
                      ),
                    ),
                    const SizedBox(height: 10),
                    
                    ListView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: _data.length,
                      itemBuilder: (ctx, i) {
                        final item = _data[i];
                        String cond = item['condition']?.toString() ?? item['condition_status']?.toString() ?? '';
                        String type = item['asset_type']?.toString() ?? 'Purchased';
                        bool isWaqf = type == 'Waqf';
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: NeoBox(
                            child: ListTile(
                              leading: CircleAvatar(
                                backgroundColor: isWaqf ? Colors.green.shade100 : Colors.blue.shade100,
                                child: Icon(isWaqf ? Icons.volunteer_activism : Icons.shopping_cart, 
                                  color: isWaqf ? primaryGreen : Colors.blue.shade800),
                              ),
                              title: Text(item['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text("تعداد: ${item['quantity']} | حالت: $cond"),
                                  if (isWaqf && item['donor_name'] != null && item['donor_name'].toString().isNotEmpty)
                                    Text("عطیہ: ${item['donor_name']} ${item['donor_father'] != null && item['donor_father'].toString().isNotEmpty ? 'بن ${item['donor_father']}' : ''}"),
                                ],
                              ),
                              trailing: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: isWaqf ? Colors.green : Colors.blue,
                                      borderRadius: BorderRadius.circular(4)
                                    ),
                                    child: Text(
                                      isWaqf ? (isUrdu ? 'وقف' : 'Waqf') : (isUrdu ? 'خریدا گیا' : 'Purchased'),
                                      style: const TextStyle(color: Colors.white, fontSize: 10)
                                    ),
                                  ),
                                ],
                              ),
                              onTap: () => _editItem(item),
                              onLongPress: () {
                                showModalBottomSheet(context: context, builder: (ctx) => Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    ListTile(
                                      leading: const Icon(Icons.edit, color: Colors.blue), title: Text(isUrdu?'ترمیم':'Edit'), 
                                      onTap: () { Navigator.pop(ctx); _editItem(item); }
                                    ),
                                    ListTile(
                                      leading: const Icon(Icons.delete, color: Colors.red), title: Text(isUrdu?'حذف کریں':'Delete'), 
                                      onTap: () { Navigator.pop(ctx); _deleteItem(item['id']); }
                                    ),
                                  ],
                                ));
                              },
                            ),
                          ),
                        );
                      },
                    ),
                    if (_data.isEmpty)
                       Padding(
                         padding: const EdgeInsets.all(32),
                         child: Text(isUrdu ? 'کوئی اثاثہ نہیں ملا' : 'No assets found'),
                       ),
                  ],
                ),
              ),
      ),
    );
  }
}
