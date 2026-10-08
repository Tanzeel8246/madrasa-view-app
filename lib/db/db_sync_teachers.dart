import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';

extension DbSyncTeachers on DBHelper {
  List<String> _decodeIdListText(dynamic value) {
    final raw = value?.toString().trim() ?? '';
    if (raw.isEmpty) return [];
    Iterable<dynamic> items;
    try {
      final decoded = jsonDecode(raw);
      items = decoded is List ? decoded : raw.split(',');
    } catch (_) {
      items = raw.split(',');
    }
    return items
        .map((item) => item.toString().trim())
        .where((item) => item.isNotEmpty)
        .toList();
  }

  String _encodeIdListText(List<String> ids) {
    final clean = <int>[];
    for (final id in ids) {
      final parsed = int.tryParse(id);
      if (parsed != null && parsed > 0 && !clean.contains(parsed)) {
        clean.add(parsed);
      }
    }
    return jsonEncode(clean);
  }

  Future<void> _updateTeacherReferenceIfTableExists(
    Transaction txn,
    String table,
    int localId,
    int serverId, {
    bool markUnsynced = true,
  }) async {
    final tableExists = await txn.rawQuery(
      "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
      [table],
    );
    if (tableExists.isEmpty) return;

    final values = <String, dynamic>{'teacher_id': serverId};
    if (markUnsynced) {
      final cols = await txn.rawQuery("PRAGMA table_info($table)");
      if (cols.any((col) => col['name'] == 'is_synced')) {
        values['is_synced'] = 0;
      }
    }
    await txn
        .update(table, values, where: 'teacher_id = ?', whereArgs: [localId]);
  }

  Future<void> markTeacherAsSynced(int localId, int? serverId) async {
    Database db = await database;
    if (serverId == null || serverId <= 0 || serverId == localId) {
      await db.update('teachers', {'is_synced': 1},
          where: 'id = ?', whereArgs: [localId]);
      return;
    }

    await db.transaction((txn) async {
      final classRows = await txn.query(
        'classes',
        columns: ['id', 'main_teacher_ids'],
        where: "COALESCE(main_teacher_ids, '') <> ''",
      );
      for (final row in classRows) {
        final ids = _decodeIdListText(row['main_teacher_ids']);
        var changed = false;
        final replaced = ids.map((id) {
          if (id == localId.toString()) {
            changed = true;
            return serverId.toString();
          }
          return id;
        }).toList();
        if (!changed) continue;
        await txn.update(
          'classes',
          {
            'main_teacher_ids': _encodeIdListText(replaced),
            'is_synced': 0,
          },
          where: 'id = ?',
          whereArgs: [row['id']],
        );
      }

      await _updateTeacherReferenceIfTableExists(
          txn, 'attendance_teachers', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'teacher_period_attendance', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'acc_payroll', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'teacher_leaves', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'staff_loans', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'maktab_nazira_record', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'maktab_hifz_record', localId, serverId);
      await _updateTeacherReferenceIfTableExists(
          txn, 'maktab_qaida_records', localId, serverId);

      final source = await txn.query('teachers',
          where: 'id = ?', whereArgs: [localId], limit: 1);
      final target = await txn.query('teachers',
          where: 'id = ?', whereArgs: [serverId], limit: 1);
      if (source.isEmpty) {
        await txn.update('teachers', {'is_synced': 1},
            where: 'id = ?', whereArgs: [serverId]);
        return;
      }

      if (target.isNotEmpty) {
        final merged = Map<String, dynamic>.from(source.first)
          ..remove('id')
          ..['is_synced'] = 1;
        await txn
            .update('teachers', merged, where: 'id = ?', whereArgs: [serverId]);
        await txn.delete('teachers', where: 'id = ?', whereArgs: [localId]);
      } else {
        await txn.update(
          'teachers',
          {'id': serverId, 'is_synced': 1},
          where: 'id = ?',
          whereArgs: [localId],
        );
      }
    });
  }

  Future<void> markTeacherLeaveAsSynced(int localId, int? serverId) async {
    Database db = await database;
    if (serverId != null && serverId > 0 && serverId != localId) {
      await db.update(
        'teacher_leaves',
        {'is_synced': 1, 'server_id': serverId},
        where: 'id = ?',
        whereArgs: [localId],
      );
    } else {
      await db.update(
        'teacher_leaves',
        {'is_synced': 1},
        where: 'id = ?',
        whereArgs: [localId],
      );
    }
  }
}
