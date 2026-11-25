import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';
import '../../utils/data_refresh_bus.dart';
import '../../utils/system_type_global.dart';

class LibraryRepository {
  final DBHelper _dbHelper;
  LibraryRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  /// کتب خانہ کی کتب حاصل کریں
  Future<List<Map<String, dynamic>>> getBooks({
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
      where += " AND (title LIKE ? OR author LIKE ? OR book_code LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q, q]);
    }

    return await db.query(
      'lib_books',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      orderBy: 'title ASC',
    );
  }

  /// نئی کتاب شامل کریں
  Future<int> insertBook(Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('lib_books', data);

    DataRefreshBus.notify(DataDomain.library, action: 'insert_book', entityId: id);
    return id;
  }

  /// کتاب کا ریکارڈ اپ ڈیٹ کریں
  Future<int> updateBook(int id, Map<String, dynamic> row) async {
    final db = await _db;
    final data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    final count = await db.update('lib_books', data, where: 'id = ?', whereArgs: [id]);

    DataRefreshBus.notify(DataDomain.library, action: 'update_book', entityId: id);
    return count;
  }

  /// کتاب ڈیلیٹ کریں
  Future<int> deleteBook(int id) async {
    final db = await _db;
    final count = await db.update(
      'lib_books',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );

    DataRefreshBus.notify(DataDomain.library, action: 'delete_book', entityId: id);
    return count;
  }

  /// جاری شدہ کتب کی لسٹ بمع طالب علم کی تفصیلات
  Future<List<Map<String, dynamic>>> getIssuedBooks({
    String? status,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String sysCond = (sys == 'Masjid')
        ? "i.system_type = 'Masjid'"
        : "(i.system_type = 'Madrasa' OR i.system_type IS NULL OR i.system_type = '')";

    String statusCond = '';
    if (status != null && status.isNotEmpty) {
      statusCond = "AND i.status = '$status'";
    }

    return await db.rawQuery('''
      SELECT i.*, b.title as book_title, b.book_code, b.author,
             s.name as student_name, s.roll_number, s.admission_number, s.class_name
      FROM lib_issues i
      LEFT JOIN lib_books b ON i.book_id = b.id
      LEFT JOIN students s ON i.student_id = s.id
      WHERE (i.is_deleted = 0 OR i.is_deleted IS NULL)
        AND $sysCond
        $statusCond
      ORDER BY i.id DESC
    ''');
  }

  /// کتاب جاری (Issue) کریں
  Future<bool> issueBook({
    required int bookId,
    required int studentId,
    required String issueDate,
    String? returnDueDate,
    String? remarks,
    String? systemType,
  }) async {
    final db = await _db;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    // Check availability
    final bookRows = await db.query('lib_books', where: 'id = ?', whereArgs: [bookId]);
    if (bookRows.isEmpty) return false;
    final copies = (bookRows.first['available_copies'] as num?)?.toInt() ?? 0;
    if (copies <= 0) return false;

    final issueMap = {
      'book_id': bookId,
      'student_id': studentId,
      'issue_date': issueDate,
      'due_date': returnDueDate ?? '',
      'status': 'Issued',
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };
    final issueId = await db.insert('lib_issues', issueMap);

    await db.rawUpdate(
      'UPDATE lib_books SET available_copies = MAX(0, available_copies - 1), is_synced = 0 WHERE id = ?',
      [bookId],
    );

    DataRefreshBus.notify(DataDomain.library, action: 'issue_book', entityId: issueId);
    return true;
  }

  /// کتاب واپس (Return) جمع کریں
  Future<bool> returnBook({
    required int issueId,
    required String returnDate,
    String? remarks,
    double fine = 0.0,
  }) async {
    final db = await _db;
    final issueRows = await db.query('lib_issues', where: 'id = ?', whereArgs: [issueId]);
    if (issueRows.isEmpty) return false;
    final bookId = issueRows.first['book_id'] as int?;

    await db.update(
      'lib_issues',
      {
        'return_date': returnDate,
        'status': 'Returned',
        'fine_amount': fine,
        'remarks': remarks ?? '',
        'is_synced': 0,
        'updated_at': DateTime.now().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [issueId],
    );

    if (bookId != null) {
      await db.rawUpdate(
        'UPDATE lib_books SET available_copies = available_copies + 1, is_synced = 0 WHERE id = ?',
        [bookId],
      );
    }

    DataRefreshBus.notify(DataDomain.library, action: 'return_book', entityId: issueId);
    return true;
  }
}
