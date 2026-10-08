// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key});

  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _sales = [];
  List<Map<String, dynamic>> _students = [];

  String _selectedCategory = 'All';
  String _searchQuery = '';

  final List<String> _categories = [
    'All',
    'یونیفارم',
    'کتب و کاپیاں',
    'اسٹیشنری',
    'بیج و ٹوپیاں',
    'عام',
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
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

    final items = await _dbHelper.getInventoryItems(
      category: _selectedCategory != 'All' ? _selectedCategory : null,
      search: _searchQuery.isNotEmpty ? _searchQuery : null,
      systemType: sys,
    );

    final sales = await _dbHelper.getInventorySales(systemType: sys);

    final db = await _dbHelper.database;
    final stdTable = isMasjid ? 'maktab_students' : 'students';
    List<Map<String, dynamic>> stds = [];
    try {
      stds = await db.query(
        stdTable,
        columns: ['id', 'name', 'class'],
        where: "status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: 'name ASC',
      );
    } catch (_) {}

    setState(() {
      _items = items;
      _sales = sales;
      _students = stds;
      _isLoading = false;
    });
  }

  int get _lowStockCount {
    return _items.where((item) {
      final stock = (item['current_stock'] as num?)?.toInt() ?? 0;
      final alert = (item['alert_threshold'] as num?)?.toInt() ?? 5;
      return stock <= alert;
    }).length;
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final title = sys == 'Masjid' ? 'مکتب شاپ و سامان' : 'سکول شاپ / انونٹری';

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: Text(
            title,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          bottom: TabBar(
            controller: _tabController,
            indicatorColor: Colors.amber,
            indicatorWeight: 3,
            labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
            tabs: [
              Tab(
                icon: const Icon(Icons.inventory_2),
                text: 'اسٹاک و اشیاء (${_items.length})',
              ),
              Tab(
                icon: const Icon(Icons.receipt_long),
                text: 'سیلز و رسیدات (${_sales.length})',
              ),
            ],
          ),
        ),
        drawer: const AppDrawer(),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(
                controller: _tabController,
                children: [
                  _buildItemsTab(),
                  _buildSalesTab(),
                ],
              ),
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          onPressed: () {
            if (_tabController.index == 0) {
              _showItemFormDialog();
            } else {
              _showQuickSaleDialog();
            }
          },
          icon: Icon(_tabController.index == 0 ? Icons.add : Icons.point_of_sale),
          label: Text(
            _tabController.index == 0 ? 'نیا سامان درج کریں' : 'نئی فروخت (POS)',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }

  Widget _buildItemsTab() {
    return Column(
      children: [
        if (_lowStockCount > 0)
          Container(
            color: Colors.red.shade50,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: [
                const Icon(Icons.warning_amber_rounded, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'توجہ: $_lowStockCount اشیاء کا اسٹاک خطرے کی حد تک کم ہو چکا ہے!',
                    style: const TextStyle(
                      color: Colors.red,
                      fontWeight: FontWeight.bold,
                      fontSize: 13,
                    ),
                  ),
                ),
              ],
            ),
          ),
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  decoration: InputDecoration(
                    hintText: 'آئٹم کا نام یا کوڈ تلاش کریں...',
                    prefixIcon: const Icon(Icons.search),
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 12, vertical: 10),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  onChanged: (val) {
                    _searchQuery = val;
                    _loadData();
                  },
                ),
              ),
            ],
          ),
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Row(
            children: _categories.map((cat) {
              final isSel = _selectedCategory == cat;
              return Padding(
                padding: const EdgeInsets.only(left: 6),
                child: ChoiceChip(
                  label: Text(cat == 'All' ? 'تمام کیٹیگریز' : cat),
                  selected: isSel,
                  selectedColor: primaryGreen.withOpacity(0.2),
                  labelStyle: TextStyle(
                    color: isSel ? primaryGreen : Colors.black87,
                    fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                  ),
                  onSelected: (sel) {
                    if (sel) {
                      setState(() => _selectedCategory = cat);
                      _loadData();
                    }
                  },
                ),
              );
            }).toList(),
          ),
        ),
        const Divider(),
        Expanded(
          child: _items.isEmpty
              ? const Center(
                  child: Text('کوئی سامان موجود نہیں ہے۔ نیا آئٹم شامل کریں۔'),
                )
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 4),
                  itemCount: _items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (ctx, idx) {
                    final item = _items[idx];
                    final stock = (item['current_stock'] as num?)?.toInt() ?? 0;
                    final alert = (item['alert_threshold'] as num?)?.toInt() ?? 5;
                    final isLow = stock <= alert;
                    final price = (item['unit_price'] as num?)?.toDouble() ?? 0.0;
                    final unit = item['unit']?.toString() ?? 'عدد';

                    return Card(
                      elevation: 1.5,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                        side: BorderSide(
                          color: isLow ? Colors.red.shade300 : Colors.grey.shade200,
                        ),
                      ),
                      child: ListTile(
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 14, vertical: 6),
                        leading: CircleAvatar(
                          backgroundColor: isLow ? Colors.red.shade100 : Colors.teal.shade50,
                          child: Icon(
                            Icons.shopping_bag_outlined,
                            color: isLow ? Colors.red : primaryGreen,
                          ),
                        ),
                        title: Row(
                          children: [
                            Expanded(
                              child: Text(
                                item['name']?.toString() ?? '',
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 15,
                                ),
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: isLow ? Colors.red : Colors.green.shade700,
                                borderRadius: BorderRadius.circular(20),
                              ),
                              child: Text(
                                '$stock $unit',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                          ],
                        ),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const SizedBox(height: 4),
                            Row(
                              children: [
                                Text(
                                  'قیمت: Rs $price',
                                  style: TextStyle(
                                    color: Colors.grey.shade800,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(width: 12),
                                if (item['category'] != null)
                                  Container(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 6, vertical: 1),
                                    decoration: BoxDecoration(
                                      color: Colors.grey.shade200,
                                      borderRadius: BorderRadius.circular(4),
                                    ),
                                    child: Text(
                                      item['category'].toString(),
                                      style: TextStyle(
                                        fontSize: 11,
                                        color: Colors.grey.shade700,
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                          ],
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            IconButton(
                              icon: const Icon(Icons.point_of_sale, color: Colors.blue),
                              tooltip: 'فروخت کریں',
                              onPressed: stock > 0
                                  ? () => _showQuickSaleDialog(preSelectedItem: item)
                                  : null,
                            ),
                            PopupMenuButton<String>(
                              onSelected: (val) {
                                if (val == 'edit') {
                                  _showItemFormDialog(item: item);
                                } else if (val == 'delete') {
                                  _confirmDeleteItem(item['id'] as int);
                                }
                              },
                              itemBuilder: (ctx) => [
                                const PopupMenuItem(
                                  value: 'edit',
                                  child: Row(
                                    children: [
                                      Icon(Icons.edit, size: 18),
                                      SizedBox(width: 8),
                                      Text('ترمیم کریں'),
                                    ],
                                  ),
                                ),
                                const PopupMenuItem(
                                  value: 'delete',
                                  child: Row(
                                    children: [
                                      Icon(Icons.delete, color: Colors.red, size: 18),
                                      SizedBox(width: 8),
                                      Text('حذف کریں', style: TextStyle(color: Colors.red)),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _buildSalesTab() {
    if (_sales.isEmpty) {
      return const Center(
        child: Text('کوئی سیلز ریکارڈ موجود نہیں ہے۔'),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 10),
      itemCount: _sales.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (ctx, idx) {
        final s = _sales[idx];
        final amount = (s['total_amount'] as num?)?.toDouble() ?? 0.0;
        final qty = s['quantity'] ?? 1;

        return Card(
          elevation: 1.5,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(
                  backgroundColor: Colors.teal.shade50,
                  child: const Icon(Icons.receipt, color: Color(0xFF284D3D)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            s['item_name']?.toString() ?? 'آئٹم',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                            ),
                          ),
                          Text(
                            'Rs $amount',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF284D3D),
                              fontSize: 15,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'خریدار: ${s['customer_name']?.toString().isNotEmpty == true ? s['customer_name'] : 'عام خریدار'}  |  تعداد: $qty',
                        style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                      ),
                      const SizedBox(height: 2),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            'تاریخ: ${s['date'] ?? ''}',
                            style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(
                              color: Colors.blue.shade50,
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: Text(
                              'رسید: ${s['sale_no'] ?? ''}',
                              style: TextStyle(color: Colors.blue.shade800, fontSize: 11),
                            ),
                          ),
                        ],
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
  }

  void _showItemFormDialog({Map<String, dynamic>? item}) {
    final isEdit = item != null;
    final nameCtrl = TextEditingController(text: item?['name']?.toString() ?? '');
    final codeCtrl = TextEditingController(text: item?['item_code']?.toString() ?? '');
    final salePriceCtrl = TextEditingController(
        text: item?['unit_price'] != null ? item!['unit_price'].toString() : '');
    final costPriceCtrl = TextEditingController(
        text: item?['purchase_price'] != null ? item!['purchase_price'].toString() : '');
    final stockCtrl = TextEditingController(
        text: item?['current_stock'] != null ? item!['current_stock'].toString() : '0');
    final alertCtrl = TextEditingController(
        text: item?['alert_threshold'] != null ? item!['alert_threshold'].toString() : '5');
    String selCategory = item?['category']?.toString() ?? 'عام';
    String selUnit = item?['unit']?.toString() ?? 'عدد';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: Text(isEdit ? 'ترمیم سامان' : 'نیا سامان شامل کریں'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(labelText: 'سامان کا نام *'),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: codeCtrl,
                          decoration: const InputDecoration(labelText: 'آئٹم کوڈ / بارکوڈ'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          value: _categories.contains(selCategory) && selCategory != 'All'
                              ? selCategory
                              : 'عام',
                          decoration: const InputDecoration(labelText: 'کیٹیگری'),
                          items: _categories
                              .where((c) => c != 'All')
                              .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                              .toList(),
                          onChanged: (v) => setDlgState(() => selCategory = v ?? 'عام'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: salePriceCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'قیمت فروخت (روپے) *'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: costPriceCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'قیمت خرید'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: stockCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'موجودہ اسٹاک *'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: alertCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'الرٹ حد (Low Stock)'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: ['عدد', 'سیٹ', 'جوڑی', 'پیکٹ'].contains(selUnit) ? selUnit : 'عدد',
                    decoration: const InputDecoration(labelText: 'اکائی (Unit)'),
                    items: ['عدد', 'سیٹ', 'جوڑی', 'پیکٹ']
                        .map((u) => DropdownMenuItem(value: u, child: Text(u)))
                        .toList(),
                    onChanged: (v) => setDlgState(() => selUnit = v ?? 'عدد'),
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
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (nameCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('برائے مہربانی سامان کا نام درج کریں')),
                    );
                    return;
                  }

                  final data = {
                    'name': nameCtrl.text.trim(),
                    'item_code': codeCtrl.text.trim(),
                    'category': selCategory,
                    'unit_price': double.tryParse(salePriceCtrl.text.trim()) ?? 0.0,
                    'purchase_price': double.tryParse(costPriceCtrl.text.trim()) ?? 0.0,
                    'current_stock': int.tryParse(stockCtrl.text.trim()) ?? 0,
                    'alert_threshold': int.tryParse(alertCtrl.text.trim()) ?? 5,
                    'unit': selUnit,
                    'system_type': SystemTypeGlobal.currentSystemType.value,
                  };

                  if (isEdit) {
                    await _dbHelper.updateInventoryItem(item['id'] as int, data);
                  } else {
                    await _dbHelper.insertInventoryItem(data);
                  }

                  Navigator.pop(ctx);
                  _loadData();
                },
                child: Text(isEdit ? 'محفوظ کریں' : 'شامل کریں',
                    style: const TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showQuickSaleDialog({Map<String, dynamic>? preSelectedItem}) {
    Map<String, dynamic>? selectedItem = preSelectedItem;
    if (selectedItem == null && _items.isNotEmpty) {
      selectedItem = _items.firstWhere(
        (it) => ((it['current_stock'] as num?)?.toInt() ?? 0) > 0,
        orElse: () => _items.first,
      );
    }

    final qtyCtrl = TextEditingController(text: '1');
    final customerCtrl = TextEditingController();
    Map<String, dynamic>? selectedStudent;
    String paymentMode = 'Cash';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) {
          final stock = (selectedItem?['current_stock'] as num?)?.toInt() ?? 0;
          final unitPrice = (selectedItem?['unit_price'] as num?)?.toDouble() ?? 0.0;
          final qty = int.tryParse(qtyCtrl.text) ?? 1;
          final total = unitPrice * qty;

          return Directionality(
            textDirection: TextDirection.rtl,
            child: AlertDialog(
              title: const Row(
                children: [
                  Icon(Icons.point_of_sale, color: Color(0xFF284D3D)),
                  SizedBox(width: 8),
                  Text('نئی شاپ فروخت (POS)'),
                ],
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    DropdownButtonFormField<Map<String, dynamic>>(
                      value: selectedItem,
                      decoration: const InputDecoration(labelText: 'سامان منتخب کریں *'),
                      items: _items.map((it) {
                        final s = (it['current_stock'] as num?)?.toInt() ?? 0;
                        return DropdownMenuItem(
                          value: it,
                          child: Text('${it['name']} (اسٹاک: $s)'),
                        );
                      }).toList(),
                      onChanged: (val) {
                        setDlgState(() => selectedItem = val);
                      },
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: qtyCtrl,
                            keyboardType: TextInputType.number,
                            decoration: InputDecoration(
                              labelText: 'تعداد (موجود: $stock)',
                              helperText: 'دستیاب اسٹاک کے اندر درج کریں',
                            ),
                            onChanged: (_) => setDlgState(() {}),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: Colors.teal.shade50,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('کل رقم', style: TextStyle(fontSize: 11)),
                                Text('Rs $total',
                                    style: const TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 16,
                                        color: Color(0xFF284D3D))),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<Map<String, dynamic>>(
                      value: selectedStudent,
                      decoration: const InputDecoration(
                        labelText: 'طالب علم منتخب کریں (اختیاری)',
                        isDense: true,
                      ),
                      items: [
                        const DropdownMenuItem<Map<String, dynamic>>(
                          value: null,
                          child: Text('عام کسٹمر (طالب علم نہیں)'),
                        ),
                        ..._students.map((s) => DropdownMenuItem(
                              value: s,
                              child: Text('${s['name']} (${s['class'] ?? ''})'),
                            )),
                      ],
                      onChanged: (val) {
                        setDlgState(() {
                          selectedStudent = val;
                          if (val != null) {
                            customerCtrl.text = val['name'].toString();
                          }
                        });
                      },
                    ),
                    if (selectedStudent == null) ...[
                      const SizedBox(height: 8),
                      TextField(
                        controller: customerCtrl,
                        decoration: const InputDecoration(
                          labelText: 'خریدار کا نام',
                          hintText: 'عام کسٹمر',
                          isDense: true,
                        ),
                      ),
                    ],
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: paymentMode,
                      decoration: const InputDecoration(
                        labelText: 'ادائیگی طریقہ',
                        isDense: true,
                      ),
                      items: const [
                        DropdownMenuItem(value: 'Cash', child: Text('نقد (Cash)')),
                        DropdownMenuItem(value: 'Online', child: Text('آن لائن بینک / ایزی پیسہ')),
                      ],
                      onChanged: (val) => setDlgState(() => paymentMode = val ?? 'Cash'),
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: Colors.amber.shade50,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: Colors.amber.shade200),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.auto_awesome, color: Colors.amber, size: 18),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'ڈبل انٹری: کیش اکاؤنٹ ڈیبٹ اور شاپ سیلز کریڈٹ واؤچر خودکار بن جائے گا۔',
                              style: TextStyle(fontSize: 11, color: Colors.brown),
                            ),
                          ),
                        ],
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
                  style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                  onPressed: () async {
                    if (selectedItem == null) return;
                    final buyQty = int.tryParse(qtyCtrl.text) ?? 1;
                    if (buyQty <= 0) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('درست تعداد درج کریں')),
                      );
                      return;
                    }

                    if (buyQty > stock) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('اسٹاک میں اتنی اشیاء موجود نہیں ہیں!')),
                      );
                      return;
                    }

                    final success = await _dbHelper.recordInventorySale(
                      selectedItem!['id'] as int,
                      buyQty,
                      studentId: selectedStudent?['id'] as int?,
                      customerName: customerCtrl.text.trim().isNotEmpty
                          ? customerCtrl.text.trim()
                          : (selectedStudent?['name']?.toString() ?? 'عام خریدار'),
                      paymentMode: paymentMode,
                      systemType: SystemTypeGlobal.currentSystemType.value,
                    );

                    Navigator.pop(ctx);
                    if (success) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('سیل کامیابی سے محفوظ اور واؤچر جاری ہو گیا!')),
                      );
                      _loadData();
                    } else {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('فروخت ریکارڈ کرنے میں خرابی واقع ہوئی')),
                      );
                    }
                  },
                  child: const Text('فروخت مکمل کریں', style: TextStyle(color: Colors.white)),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _confirmDeleteItem(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('حذف کی تصدیق'),
          content: const Text('کیا آپ واقعی یہ آئٹم حذف کرنا چاہتے ہیں؟'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('نہیں')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ہاں، حذف کریں', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );

    if (ok == true) {
      await _dbHelper.deleteInventoryItem(id);
      _loadData();
    }
  }
}
