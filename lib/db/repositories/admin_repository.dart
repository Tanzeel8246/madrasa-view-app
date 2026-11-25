import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';
import '../../utils/system_type_global.dart';

class AdminRepository {
  final DBHelper _dbHelper;
  AdminRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  /// اساتذہ کی فہرست حاصل کریں
  Future<List<Map<String, dynamic>>> getTeachers({
    String? systemType,
    bool activeOnly = true,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    String activeCond = activeOnly
        ? "AND (status = 'Active' OR status = '' OR status IS NULL)"
        : "";

    return await db.query(
      'teachers',
      where: "(is_deleted = 0 OR is_deleted IS NULL) $activeCond AND $sysCond",
      orderBy: 'name ASC',
    );
  }

  /// نیا استاد شامل کریں
  Future<int> insertTeacher(Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('teachers', data);

    DataRefreshBus.notify(DataDomain.teachers, action: 'insert_teacher', entityId: id);
    return id;
  }

  /// استاد کا ڈیٹا اپ ڈیٹ کریں
  Future<int> updateTeacher(int id, Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    final count = await db.update('teachers', data, where: 'id = ?', whereArgs: [id]);

    DataRefreshBus.notify(DataDomain.teachers, action: 'update_teacher', entityId: id);
    return count;
  }

  /// استاد ڈیلیٹ کریں
  Future<int> deleteTeacher(int id) async {
    final db = await _db;
    final count = await db.update(
      'teachers',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.teachers, action: 'delete_teacher', entityId: id);
    return count;
  }

  /// بھرتی کے امیدواران کی فہرست حاصل کریں
  Future<List<Map<String, dynamic>>> getJobApplicants({
    String? systemType,
    String? status,
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

    if (status != null && status.isNotEmpty) {
      where += " AND status = ?";
      args.add(status);
    }

    return await db.query(
      'job_applicants',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      orderBy: 'id DESC',
    );
  }

  /// ایکٹیوٹی لاگ ریکارڈ کریں
  Future<int> logActivity(String module, String description) async {
    try {
      final db = await _db;
      final map = {
        'module': module,
        'description': description,
        'created_at': DateTime.now().toIso8601String(),
        'system_type': SystemTypeGlobal.currentSystemType.value,
        'is_synced': 0,
      };
      return await db.insert('activity_logs', map);
    } catch (_) {
      return 0;
    }
  }

  /// ایکٹیوٹی لاگز حاصل کریں
  Future<List<Map<String, dynamic>>> getActivityLogs({int limit = 100}) async {
    try {
      final db = await _db;
      final sys = SystemTypeGlobal.currentSystemType.value;
      String sysCond = (sys == 'Masjid')
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

      return await db.query(
        'activity_logs',
        where: sysCond,
        orderBy: 'id DESC',
        limit: limit,
      );
    } catch (_) {
      return [];
    }
  }
}
