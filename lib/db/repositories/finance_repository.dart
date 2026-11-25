import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';

class FinanceRepository {
  final DBHelper _dbHelper;
  FinanceRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  Future<List<Map<String, dynamic>>> getFinanceEntries({
    String? type,
    String? fromDate,
    String? toDate,
    String? systemType,
    int? limit,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "f.system_type = 'Masjid'"
        : "(f.system_type = 'Madrasa' OR f.system_type IS NULL OR f.system_type = '')";

    final conditions = <String>[
      "(f.is_deleted = 0 OR f.is_deleted IS NULL)",
      sysCond,
    ];
    final args = <dynamic>[];

    if (type != null && type.isNotEmpty) {
      conditions.add("LOWER(f.type) = ?");
      args.add(type.toLowerCase());
    }
    if (fromDate != null && fromDate.isNotEmpty) {
      conditions.add("f.date >= ?");
      args.add(fromDate);
    }
    if (toDate != null && toDate.isNotEmpty) {
      conditions.add("f.date <= ?");
      args.add(toDate);
    }

    final whereClause = conditions.join(" AND ");
    final limitClause = (limit != null && limit > 0) ? "LIMIT $limit" : "";

    return await db.rawQuery('''
      SELECT f.*, h.name as head_name
      FROM finance f
      LEFT JOIN heads h ON f.head_id = h.id
      WHERE $whereClause
      ORDER BY f.date DESC, f.id DESC
      $limitClause
    ''', args);
  }

  Future<int> insertFinanceEntry(Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final id = await db.insert('finance', values);

    DataRefreshBus.notify(DataDomain.finance, action: 'insert', entityId: id);
    return id;
  }

  Future<int> updateFinanceEntry(int id, Map<String, dynamic> data) async {
    final db = await _db;
    final values = Map<String, dynamic>.from(data);
    values['is_synced'] = 0;
    final count =
        await db.update('finance', values, where: 'id = ?', whereArgs: [id]);

    DataRefreshBus.notify(DataDomain.finance, action: 'update', entityId: id);
    return count;
  }

  Future<int> deleteFinanceEntry(int id) async {
    final db = await _db;
    final count = await db.update(
      'finance',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.finance, action: 'delete', entityId: id);
    return count;
  }

  Future<Map<String, double>> getMonthlySummary(
    String monthYear, {
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? 'Madrasa';
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final rows = await db.rawQuery('''
      SELECT LOWER(type) as ftype, SUM(amount) as total
      FROM finance
      WHERE date LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond
      GROUP BY LOWER(type)
    ''', ['$monthYear%']);

    double income = 0.0;
    double expense = 0.0;

    for (final r in rows) {
      final t = (r['ftype'] ?? '').toString();
      final amt = (r['total'] as num?)?.toDouble() ?? 0.0;
      if (t == 'income') {
        income += amt;
      } else if (t == 'expense') {
        expense += amt;
      }
    }

    return {
      'income': income,
      'expense': expense,
      'net': income - expense,
    };
  }

  Future<List<Map<String, dynamic>>> getHeads({String? type}) async {
    final db = await _db;
    if (type != null && type.isNotEmpty) {
      return await db.query(
        'heads',
        where: "LOWER(type) = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
        whereArgs: [type.toLowerCase()],
        orderBy: 'name ASC',
      );
    }
    return await db.query(
      'heads',
      where: "(is_deleted = 0 OR is_deleted IS NULL)",
      orderBy: 'name ASC',
    );
  }
}
