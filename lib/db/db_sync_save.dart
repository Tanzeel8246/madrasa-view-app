import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import '../sync/sync_config.dart';
import '../sync/sync_conflict_handler.dart';
import 'sync_savers/core_sync_saver.dart';
import 'sync_savers/students_sync_saver.dart';
import 'sync_savers/maktab_sync_saver.dart';
import 'sync_savers/finance_sync_saver.dart';
import 'sync_savers/attendance_sync_saver.dart';

extension DbSyncSave on DBHelper {
  /// SYNC-SAFETY (F-04): shield local rows that still carry un-uploaded
  /// changes from being silently overwritten by downloaded server data.
  ///
  /// The per-domain savers insert server rows with ConflictAlgorithm.replace
  /// keyed on the server id. If the same id exists locally with
  /// is_synced = 0 (an offline edit whose upload failed, or that was made
  /// after this sync's upload phase), the replace would destroy the local
  /// change without a trace. For every such collision this guard:
  ///   1. logs a reviewable conflict via SyncConflictHandler, and
  ///   2. drops the server copy from THIS download round — the local
  ///      pending version wins for now; once it uploads successfully, the
  ///      server copy becomes authoritative and downloads cleanly.
  /// Server deletions (is_deleted = 1) are still applied, but the conflict
  /// is logged as well. Anything unrecognised is left exactly as before.
  Future<void> _shieldPendingLocalRows(
      Database db, Map<String, dynamic> data) async {
    // Map incoming payload keys back to their local table names.
    final keyToTable = <String, String>{};
    SyncConfig.apiKeyMap.forEach((table, apiKey) {
      keyToTable[apiKey] = table;
    });
    for (final t in SyncConfig.tables) {
      keyToTable.putIfAbsent(t, () => t);
    }

    final tableReady = <String, bool>{};
    Future<bool> canShield(String table) async {
      if (tableReady.containsKey(table)) return tableReady[table]!;
      var ok = false;
      try {
        final exists = await db.rawQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (exists.isNotEmpty) {
          final cols = await db.rawQuery("PRAGMA table_info($table)");
          ok = cols.any((c) => c['name'] == 'is_synced');
        }
      } catch (_) {
        ok = false;
      }
      tableReady[table] = ok;
      return ok;
    }

    for (final key in data.keys.toList()) {
      final value = data[key];
      final table = keyToTable[key];
      if (table == null) continue;

      if (value is List) {
        if (!await canShield(table)) continue;
        final kept = <dynamic>[];
        for (final item in value) {
          var shielded = false;
          if (item is Map) {
            final serverId = int.tryParse(item['id']?.toString() ?? '');
            if (serverId != null) {
              try {
                final local = await db.query(table,
                    where: 'id = ?', whereArgs: [serverId], limit: 1);
                if (local.isNotEmpty &&
                    (int.tryParse(
                                local.first['is_synced']?.toString() ?? '1') ??
                            1) ==
                        0) {
                  final localRow =
                      Map<String, dynamic>.from(local.first);
                  await SyncConflictHandler.logConflict(
                    dbHelper: this,
                    tableName: table,
                    recordId: serverId.toString(),
                    localData: localRow,
                    serverData: Map<String, dynamic>.from(item),
                  );
                  try {
                    if (await syncTableHasColumn(table, 'conflict_flag')) {
                      await db.update(table, {'conflict_flag': 1},
                          where: 'id = ?', whereArgs: [serverId]);
                    }
                  } catch (_) {}
                  final isTombstone =
                      (int.tryParse(item['is_deleted']?.toString() ?? '0') ??
                              0) ==
                          1;
                  shielded = !isTombstone;
                }
              } catch (e) {
                debugPrint('Sync shield error ($table): $e');
              }
            }
          }
          if (!shielded) kept.add(item);
        }
        data[key] = kept;
      } else if (key == 'settings' && value is Map) {
        // Settings are saved wholesale by the core saver; protect a local
        // settings row whose changes have not been uploaded yet.
        try {
          if (await syncTableHasColumn('settings', 'is_synced')) {
            final local = await db.query('settings',
                where: 'COALESCE(is_synced, 0) = 0', limit: 1);
            if (local.isNotEmpty) {
              await SyncConflictHandler.logConflict(
                dbHelper: this,
                tableName: 'settings',
                recordId: local.first['id']?.toString() ?? '1',
                localData: Map<String, dynamic>.from(local.first),
                serverData: Map<String, dynamic>.from(value),
              );
              data.remove('settings');
            }
          }
        } catch (e) {
          debugPrint('Sync shield error (settings): $e');
        }
      }
    }
  }

  Future<void> saveServerData(Map<String, dynamic> data) async {
    final Database db = await database;

    // SYNC-SAFETY (F-04): never let a download silently destroy local
    // changes that have not been uploaded yet.
    try {
      await _shieldPendingLocalRows(db, data);
    } catch (e) {
      debugPrint('Sync shield failed (continuing with download): $e');
    }
    final Batch batch = db.batch();
    final List<List<int>> teacherIdRemaps = <List<int>>[];
    final List<List<int>> studentIdRemaps = <List<int>>[];
    final List<List<int>> maktabStudentIdRemaps = <List<int>>[];

    // 1. Students & Academics Domain
    await StudentsSyncSaver.save(db, batch, data, studentIdRemaps);

    // 2. Maktab & Masjid Education Domain
    await MaktabSyncSaver.save(db, batch, data, maktabStudentIdRemaps);

    // 3. Attendance & Staff Domain
    await AttendanceSyncSaver.save(db, batch, data, teacherIdRemaps);

    // 4. Finance & Accounting Domain
    await FinanceSyncSaver.save(db, batch, data);

    // 5. Core & Metadata Domain
    await CoreSyncSaver.save(db, batch, data);

    // Commit all batch operations with results inspection
    final List<Object?> results =
        await batch.commit(continueOnError: true, noResult: false);

    // Inspect individual batch execution results and log any DatabaseException
    for (final Object? res in results) {
      if (res is DatabaseException) {
        debugPrint('⚠️ [DbSyncSaver] Batch operation failed: $res');
      }
    }

    // Remap any teacher IDs if changed during server reconciliation
    for (final remap in teacherIdRemaps) {
      await markTeacherAsSynced(remap[0], remap[1]);
    }

    // Remap any Madrasa student IDs if changed during server reconciliation
    for (final remap in studentIdRemaps) {
      await markStudentAsSynced(remap[0], remap[1], isMaktab: false);
    }

    // Remap any Maktab student IDs if changed during server reconciliation
    for (final remap in maktabStudentIdRemaps) {
      await markStudentAsSynced(remap[0], remap[1], isMaktab: true);
    }
  }
}
