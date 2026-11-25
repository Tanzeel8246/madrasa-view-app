import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';
import '../../utils/system_type_global.dart';

class HostelRepository {
  final DBHelper _dbHelper;
  HostelRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  /// دارالاقامہ (ہاسٹل) کے کمرے حاصل کریں
  Future<List<Map<String, dynamic>>> getHostelRooms({String? systemType}) async {
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
      'hostel_rooms',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      orderBy: 'room_name ASC',
    );
  }

  /// نیا ہاسٹل کمرہ شامل کریں
  Future<int> insertHostelRoom(Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('hostel_rooms', data);

    DataRefreshBus.notify(DataDomain.hostel, action: 'insert_room', entityId: id);
    return id;
  }

  /// ہاسٹل کمرہ اپ ڈیٹ کریں
  Future<int> updateHostelRoom(int id, Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    final count = await db.update('hostel_rooms', data, where: 'id = ?', whereArgs: [id]);

    DataRefreshBus.notify(DataDomain.hostel, action: 'update_room', entityId: id);
    return count;
  }

  /// ہاسٹل کمرہ ڈیلیٹ کریں
  Future<int> deleteHostelRoom(int id) async {
    final db = await _db;
    final count = await db.update(
      'hostel_rooms',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.hostel, action: 'delete_room', entityId: id);
    return count;
  }

  /// رہائش الاٹمنٹ کی لسٹ بمع طلبہ تفصیلات
  Future<List<Map<String, dynamic>>> getHostelAllocations({
    String? status,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String sysCond = (sys == 'Masjid')
        ? "a.system_type = 'Masjid'"
        : "(a.system_type = 'Madrasa' OR a.system_type IS NULL OR a.system_type = '')";

    String statusCond = '';
    if (status != null && status.isNotEmpty) {
      statusCond = "AND a.status = '$status'";
    }

    return await db.rawQuery('''
      SELECT a.*, r.room_name, r.capacity,
             s.name as student_name, s.roll_number, s.admission_number, s.class_name
      FROM hostel_allocations a
      LEFT JOIN hostel_rooms r ON a.room_id = r.id
      LEFT JOIN students s ON a.student_id = s.id
      WHERE (a.is_deleted = 0 OR a.is_deleted IS NULL)
        AND $sysCond
        $statusCond
      ORDER BY a.id DESC
    ''');
  }

  /// رہائش الاٹ کریں
  Future<int> allocateHostelBed({
    required int studentId,
    required int roomId,
    String? bedNumber,
    required String allocationDate,
    String? remarks,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    final allocMap = {
      'student_id': studentId,
      'room_id': roomId,
      'bed_number': bedNumber ?? '',
      'allocation_date': allocationDate,
      'status': 'Allocated',
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };
    final allocId = await db.insert('hostel_allocations', allocMap);

    DataRefreshBus.notify(DataDomain.hostel, action: 'allocate_bed', entityId: allocId);
    return allocId;
  }

  /// رہائش ختم (Vacate) کریں
  Future<bool> vacateHostelBed({
    required int allocationId,
    required String vacateDate,
  }) async {
    final db = await _db;
    await db.update(
      'hostel_allocations',
      {
        'vacate_date': vacateDate,
        'status': 'Vacated',
        'is_synced': 0,
        'updated_at': DateTime.now().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [allocationId],
    );

    DataRefreshBus.notify(DataDomain.hostel, action: 'vacate_bed', entityId: allocationId);
    return true;
  }
}
