import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/system_type_global.dart';

extension DbOperationsExt on DBHelper {
  // --- INVENTORY METHODS ---
  Future<List<Map<String, dynamic>>> getInventoryItems(
      {String? category, String? search, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND (system_type = 'Masjid' OR system_type = 'Shared')";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type = 'Shared' OR system_type IS NULL OR system_type = '')";
    }

    if (category != null &&
        category.isNotEmpty &&
        category.toLowerCase() != 'all') {
      where += " AND category = ?";
      args.add(category);
    }

    if (search != null && search.trim().isNotEmpty) {
      where += " AND (name LIKE ? OR item_code LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q]);
    }

    return await db.query('inventory_items',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'name ASC');
  }

  Future<int> insertInventoryItem(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('inventory_items', data);
    await logActivity('shop', 'نئی شاپ آئٹم شامل کی گئی: ${data['name']}');
    return id;
  }

  Future<int> updateInventoryItem(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('inventory_items', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteInventoryItem(int id) async {
    Database db = await database;
    return await db.update('inventory_items', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getInventorySales(
      {String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('inventory_sales',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC',
        limit: 100);
  }

  Future<bool> recordInventorySale(
    int itemId,
    int quantity, {
    int? studentId,
    String? customerName,
    String paymentMode = 'Cash',
    String? remarks,
    String? systemType,
  }) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    final itemRows =
        await db.query('inventory_items', where: 'id = ?', whereArgs: [itemId]);
    if (itemRows.isEmpty) return false;
    final item = itemRows.first;

    final currentStock = (item['current_stock'] as num?)?.toInt() ?? 0;
    if (currentStock < quantity) return false;

    final unitPrice = (item['unit_price'] as num?)?.toDouble() ?? 0.0;
    final totalAmount = unitPrice * quantity;
    final saleNo =
        'SALE-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}';
    final today = DateTime.now().toIso8601String().substring(0, 10);

    // 1. Insert Sale Record
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

    // 2. Reduce stock
    await db.update(
        'inventory_items',
        {
          'current_stock': currentStock - quantity,
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [itemId]);

    // 3. Auto post to simple finance if Cash
    try {
      if (paymentMode == 'Cash' && totalAmount > 0) {
        await db.insert('finance', {
          'date': today,
          'type': 'Income',
          'amount': totalAmount,
          'description':
              'شاپ سیل: ${item['name']} x $quantity (بل: $saleNo)',
          'system_type': sys,
          'is_deleted': 0,
          'is_synced': 0,
        });
      }
    } catch (_) {}

    await logActivity('shop',
        'سکول شاپ سے ${item['name']} کی فروخت x $quantity (مبلغ: $totalAmount روپے)');
    return true;
  }

  // --- HOSTEL METHODS ---
  Future<List<Map<String, dynamic>>> getHostelRooms(
      {String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('hostel_rooms',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'room_number ASC');
  }

  Future<int> insertHostelRoom(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('hostel_rooms', data);
  }

  Future<int> updateHostelRoom(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('hostel_rooms', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteHostelRoom(int id) async {
    Database db = await database;
    return await db.update('hostel_rooms', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getHostelAllocations(
      {String? systemType, String? status}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(a.is_deleted = 0 OR a.is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND a.system_type = 'Masjid'";
    } else {
      where +=
          " AND (a.system_type = 'Madrasa' OR a.system_type IS NULL OR a.system_type = '')";
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND a.status = ?";
      args.add(status);
    }

    final query = '''
      SELECT a.*, r.room_number, r.room_name, r.monthly_fee
      FROM hostel_allocations a
      LEFT JOIN hostel_rooms r ON a.room_id = r.id
      WHERE $where
      ORDER BY a.id DESC
    ''';
    return await db.rawQuery(query, args.isNotEmpty ? args : null);
  }

  Future<bool> allocateHostelBed(
    int roomId,
    int studentId, {
    String? studentName,
    String? bedNumber,
    String? allocationDate,
    String? remarks,
    String? systemType,
  }) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    String sName = studentName ?? '';
    if (sName.isEmpty) {
      final sRows = await db.query('students',
          columns: ['name'], where: 'id = ?', whereArgs: [studentId]);
      if (sRows.isNotEmpty) {
        sName = sRows.first['name']?.toString() ?? '';
      }
    }

    final allocMap = <String, dynamic>{
      'room_id': roomId,
      'student_id': studentId,
      'student_name': sName,
      'bed_number': bedNumber ?? 'Bed-1',
      'allocation_date':
          allocationDate ?? DateTime.now().toIso8601String().substring(0, 10),
      'status': 'Occupied',
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };

    await db.insert('hostel_allocations', allocMap);
    await db.update('students', {'residence_status': 'مقیم'},
        where: 'id = ?', whereArgs: [studentId]);
    await logActivity('hostel', 'طالب علم $sName کو ہاسٹل الاٹ کیا گیا');
    return true;
  }

  Future<bool> vacateHostelBed(int allocationId) async {
    Database db = await database;
    final aRows = await db.query('hostel_allocations',
        where: 'id = ?', whereArgs: [allocationId]);
    if (aRows.isEmpty) return false;
    final stdId = aRows.first['student_id'];

    await db.update(
        'hostel_allocations',
        {
          'status': 'Vacated',
          'vacating_date': DateTime.now().toIso8601String().substring(0, 10),
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [allocationId]);

    if (stdId != null) {
      await db.update('students', {'residence_status': 'مقامی'},
          where: 'id = ?', whereArgs: [stdId]);
    }
    return true;
  }

  // --- RECRUITMENT METHODS ---
  Future<List<Map<String, dynamic>>> getJobPostings(
      {String? systemType, String? status}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND status = ?";
      args.add(status);
    }

    return await db.query('job_postings',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC');
  }

  Future<int> insertJobPosting(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('job_postings', data);
  }

  Future<int> updateJobPosting(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('job_postings', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteJobPosting(int id) async {
    Database db = await database;
    return await db.update('job_postings', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getJobApplicants(
      {int? jobId, String? status, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(a.is_deleted = 0 OR a.is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND a.system_type = 'Masjid'";
    } else {
      where +=
          " AND (a.system_type = 'Madrasa' OR a.system_type IS NULL OR a.system_type = '')";
    }

    if (jobId != null) {
      where += " AND a.job_id = ?";
      args.add(jobId);
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND a.status = ?";
      args.add(status);
    }

    final query = '''
      SELECT a.*, j.title as job_title, j.department
      FROM job_applicants a
      LEFT JOIN job_postings j ON a.job_id = j.id
      WHERE $where
      ORDER BY a.id DESC
    ''';
    return await db.rawQuery(query, args.isNotEmpty ? args : null);
  }

  Future<int> insertJobApplicant(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('job_applicants', data);
  }

  Future<int> updateJobApplicant(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('job_applicants', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<bool> hireApplicantToTeacher(int applicantId) async {
    Database db = await database;
    final rows = await db
        .query('job_applicants', where: 'id = ?', whereArgs: [applicantId]);
    if (rows.isEmpty) return false;
    final app = rows.first;

    final sysType = app['system_type']?.toString() ??
        SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sysType == 'Masjid';
    final targetTable = isMasjid ? 'maktab_teachers' : 'teachers';

    final tId = await db.insert(targetTable, {
      'name': app['applicant_name'],
      'father_name': app['father_name'] ?? '',
      'phone': app['phone'] ?? '',
      'qualification': app['qualification'] ?? '',
      'status': 'Active',
      'is_deleted': 0,
      'is_synced': 0,
    });

    await db.update(
        'job_applicants',
        {
          'status': 'Selected',
          'converted_teacher_id': tId,
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [applicantId]);

    await logActivity('recruitment',
        'امیدوار ${app['applicant_name']} کو اساتذہ لسٹ میں شامل کیا گیا');
    return true;
  }

  // --- CMS & ANNOUNCEMENTS METHODS ---
  Future<List<Map<String, dynamic>>> getAnnouncements(
      {String? systemType, bool? isPublic}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (isPublic != null) {
      where += " AND is_public = ?";
      args.add(isPublic ? 1 : 0);
    }

    return await db.query('announcements',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'publish_date DESC, id DESC');
  }

  Future<int> insertAnnouncement(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('announcements', data);
  }

  Future<int> updateAnnouncement(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('announcements', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteAnnouncement(int id) async {
    Database db = await database;
    return await db.update('announcements', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getWebPages({String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('web_pages',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'sort_order ASC, id DESC');
  }

  Future<int> saveWebPage(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;

    final slug = data['page_slug']?.toString() ?? '';
    final existing =
        await db.query('web_pages', where: 'page_slug = ?', whereArgs: [slug]);
    if (existing.isNotEmpty) {
      return await db
          .update('web_pages', data, where: 'page_slug = ?', whereArgs: [slug]);
    } else {
      data['created_at'] = DateTime.now().toIso8601String();
      return await db.insert('web_pages', data);
    }
  }
}
