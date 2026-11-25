import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';
import '../../utils/system_type_global.dart';

class InventoryRepository {
  final DBHelper _dbHelper;
  InventoryRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  /// شاپ / انوینٹری کی اشیاء حاصل کریں
  Future<List<Map<String, dynamic>>> getInventoryItems({
    String? systemType,
    String? category,
    String? search,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where += " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (category != null && category.trim().isNotEmpty) {
      where += " AND category = ?";
      args.add(category.trim());
    }

    if (search != null && search.trim().isNotEmpty) {
      where += " AND (name LIKE ? OR item_code LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q]);
    }

    return await db.query(
      'inventory_items',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      orderBy: 'name ASC',
    );
  }

  /// کم اسٹاک والی اشیاء الرٹ کے لیے حاصل کریں
  Future<List<Map<String, dynamic>>> getLowStockItems({
    int threshold = 5,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    return await db.query(
      'inventory_items',
      where: "(is_deleted = 0 OR is_deleted IS NULL) AND current_stock <= ? AND $sysCond",
      whereArgs: [threshold],
      orderBy: 'current_stock ASC',
    );
  }

  /// نئی انوینٹری آئٹم داخل کریں
  Future<int> insertInventoryItem(Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('inventory_items', data);

    DataRefreshBus.notify(DataDomain.inventory, action: 'insert', entityId: id);
    return id;
  }

  /// انوینٹری آئٹم اپ ڈیٹ کریں
  Future<int> updateInventoryItem(int id, Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    final count = await db.update(
      'inventory_items',
      data,
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.inventory, action: 'update', entityId: id);
    return count;
  }

  /// انوینٹری آئٹم ڈیلیٹ کریں
  Future<int> deleteInventoryItem(int id) async {
    final db = await _db;
    final count = await db.update(
      'inventory_items',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.inventory, action: 'delete', entityId: id);
    return count;
  }

  /// فروخت کی ہسٹری حاصل کریں
  Future<List<Map<String, dynamic>>> getInventorySales({
    String? systemType,
    int limit = 100,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where += " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query(
      'inventory_sales',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      orderBy: 'id DESC',
      limit: limit,
    );
  }

  /// اشیاء کی فروخت کا اندراج کریں اور اسٹاک خودکار کم کریں
  Future<bool> recordInventorySale(
    int itemId,
    int quantity, {
    int? studentId,
    String? customerName,
    String paymentMode = 'Cash',
    String? remarks,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    final itemRows = await db.query('inventory_items', where: 'id = ?', whereArgs: [itemId]);
    if (itemRows.isEmpty) return false;
    final item = itemRows.first;

    final currentStock = (item['current_stock'] as num?)?.toInt() ?? 0;
    if (currentStock < quantity) return false;

    final unitPrice = (item['unit_price'] as num?)?.toDouble() ?? 0.0;
    final totalAmount = unitPrice * quantity;
    final saleNo = 'SALE-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}';
    final today = DateTime.now().toIso8601String().substring(0, 10);

    final saleMap = <String, dynamic>{
      'sale_no': saleNo,
      'item_id': itemId,
      'item_name': item['name']?.toString() ?? '',
      'student_id': studentId,
      'customer_name': customerName ?? '',
      'quantity': quantity,
      'unit_price': unitPrice,
      'total_amount': totalAmount,
      'payment_mode': paymentMode,
      'date': today,
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };
    await db.insert('inventory_sales', saleMap);

    await db.rawUpdate(
      'UPDATE inventory_items SET current_stock = MAX(0, current_stock - ?), updated_at = ? WHERE id = ?',
      [quantity, DateTime.now().toIso8601String(), itemId],
    );

    DataRefreshBus.notify(DataDomain.inventory, action: 'sale', entityId: itemId);
    DataRefreshBus.notify(DataDomain.finance, action: 'revenue', entityId: itemId);
    return true;
  }
}
