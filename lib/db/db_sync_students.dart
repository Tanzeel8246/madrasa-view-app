import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';

extension DbSyncStudents on DBHelper {
  Future<void> _updateStudentReferenceIfTableExists(
    Transaction txn,
    String table,
    int localId,
    int serverId,
  ) async {
    final tableExists = await txn.rawQuery(
      "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
      [table],
    );
    if (tableExists.isEmpty) return;

    final cols = await txn.rawQuery("PRAGMA table_info($table)");
    final hasCol = cols.any((col) => col['name'] == 'student_id');
    if (!hasCol) return;

    await txn.update(
      table,
      {'student_id': serverId.toString()},
      where: 'student_id = ? OR student_id = ?',
      whereArgs: [localId, localId.toString()],
    );
  }

  Future<void> markStudentAsSynced(
    int localId,
    int? serverId, {
    bool isMaktab = false,
  }) async {
    Database db = await database;
    final tableName = isMaktab ? 'maktab_students' : 'students';

    if (serverId == null || serverId <= 0 || serverId == localId) {
      await db.update(tableName, {'is_synced': 1},
          where: 'id = ?', whereArgs: [localId]);
      return;
    }

    await db.transaction((txn) async {
      final childTables = isMaktab
          ? [
              'maktab_attendance',
              'period_attendance',
              'maktab_nazira_record',
              'maktab_hifz_record',
              'maktab_qaida_records',
              'maktab_academic_reports',
              'maktab_exam_results',
            ]
          : [
              'attendance',
              'hifz_record',
              'nazira_record',
              'academic_reports',
              'qaida_records',
              'husn_saut_record',
              'fee_submissions',
              'exam_results',
              'complaints',
              'parent_complaints',
              'leave_applications',
              'student_parents',
            ];

      for (final table in childTables) {
        await _updateStudentReferenceIfTableExists(
            txn, table, localId, serverId);
      }

      final source = await txn.query(tableName,
          where: 'id = ?', whereArgs: [localId], limit: 1);
      final target = await txn.query(tableName,
          where: 'id = ?', whereArgs: [serverId], limit: 1);

      if (source.isEmpty) {
        await txn.update(tableName, {'is_synced': 1},
            where: 'id = ?', whereArgs: [serverId]);
        return;
      }

      if (target.isNotEmpty) {
        final merged = Map<String, dynamic>.from(source.first)
          ..remove('id')
          ..['is_synced'] = 1;
        await txn.update(tableName, merged,
            where: 'id = ?', whereArgs: [serverId]);
        await txn.delete(tableName, where: 'id = ?', whereArgs: [localId]);
      } else {
        await txn.update(
          tableName,
          {'id': serverId, 'is_synced': 1},
          where: 'id = ?',
          whereArgs: [localId],
        );
      }
    });
  }
}
