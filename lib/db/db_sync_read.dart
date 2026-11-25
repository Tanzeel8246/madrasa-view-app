import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';

extension DbSyncRead on DBHelper {
  Future<List<Map<String, dynamic>>> getUnsyncedData(String table) async {
    Database db = await database;
    try {
      var checkTable = await db.rawQuery(
          "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
      if (checkTable.isEmpty) return [];
      if (table == 'acc_ledger_entries') {
        return await db.rawQuery('''
          SELECT e.*, v.voucher_no, a.code AS account_code, a.name AS account_name
          FROM acc_ledger_entries e
          LEFT JOIN acc_vouchers v ON v.id = e.voucher_id
          LEFT JOIN acc_chart_of_accounts a ON a.id = e.account_id
          WHERE COALESCE(e.is_synced, 0) = 0
        ''');
      }
      if (table == 'settings') {
        final cols = await db.rawQuery("PRAGMA table_info(settings)");
        if (!cols.any((c) => c['name'] == 'is_synced')) return [];
        return await db.query('settings',
            where: 'COALESCE(is_synced, 0) = ?', whereArgs: [0]);
      }
      return await db
          .query(table, where: 'COALESCE(is_synced, 0) = ?', whereArgs: [0]);
    } catch (e) {
      debugPrint("GetUnsynced Error ($table): $e");
      return [];
    }
  }

  /// Whether [table] currently has a column named [column].
  Future<bool> syncTableHasColumn(String table, String column) async {
    try {
      final Database db = await database;
      final cols = await db.rawQuery("PRAGMA table_info($table)");
      return cols.any((c) => c['name'] == column);
    } catch (_) {
      return false;
    }
  }

  /// SYNC-SAFETY (F-01): guarantee that [row] carries a client_uuid that is
  /// PERSISTED in the local database before any upload attempt.
  ///
  /// The server deduplicates finance uploads on (tenant_id, client_uuid).
  /// Previously, when a row had no uuid, the uploader invented a fresh one
  /// from the current timestamp on EVERY attempt — so if the server saved
  /// the row but the response was lost, the retry arrived with a different
  /// uuid and the server inserted a duplicate. Generating the uuid once and
  /// writing it back to the local row makes every retry idempotent.
  ///
  /// Returns the uuid to send (also written back into [row]), or null when
  /// the table has no client_uuid column (the server does not deduplicate
  /// those tables by uuid, so none is invented for them).
  Future<String?> ensureClientUuidPersisted(
      String table, Map<String, dynamic> row) async {
    final existing = row['client_uuid']?.toString().trim() ?? '';
    if (existing.isNotEmpty) return existing;

    final id = int.tryParse(row['id']?.toString() ?? '');
    if (id == null) return null;
    if (!await syncTableHasColumn(table, 'client_uuid')) return null;

    var uuid =
        'app_${table}_${id}_${DateTime.now().microsecondsSinceEpoch}_${Random().nextInt(0x7fffffff)}';
    if (uuid.length > 100) uuid = uuid.substring(0, 100);
    try {
      final Database db = await database;
      final changed = await db.update(
        table,
        {'client_uuid': uuid},
        where: "id = ? AND (client_uuid IS NULL OR client_uuid = '')",
        whereArgs: [id],
      );
      if (changed == 0) {
        // Another path filled it in first — use the stored value.
        final stored = await db.query(table,
            columns: ['client_uuid'], where: 'id = ?', whereArgs: [id], limit: 1);
        final storedUuid = stored.isNotEmpty
            ? (stored.first['client_uuid']?.toString().trim() ?? '')
            : '';
        if (storedUuid.isNotEmpty) {
          row['client_uuid'] = storedUuid;
          return storedUuid;
        }
        return null;
      }
    } catch (e) {
      debugPrint("ensureClientUuidPersisted error ($table, $id): $e");
      return null;
    }
    row['client_uuid'] = uuid;
    return uuid;
  }

  Future<void> markAsSynced(String table, int id) async {
    Database db = await database;
    await db.update(table, {'is_synced': 1}, where: 'id = ?', whereArgs: [id]);
  }

  Future<void> markFinanceAsSynced(int id, int? serverId, {bool isMasjid = false}) async {
    Database db = await database;
    final tableName = isMasjid ? 'masjid_finance' : 'finance';
    if (serverId == null || serverId <= 0 || serverId == id) {
      final values = <String, dynamic>{'is_synced': 1};
      if (serverId != null && serverId > 0) {
        values['server_id'] = serverId;
      }
      await db.update(tableName, values, where: 'id = ?', whereArgs: [id]);
      return;
    }

    await db.transaction((txn) async {
      final current = await txn.query(tableName, where: 'id = ?', whereArgs: [id], limit: 1);
      if (current.isEmpty) return;

      final existingWithServerId = await txn.query(
        tableName,
        where: 'server_id = ? AND id != ?',
        whereArgs: [serverId, id],
        limit: 1,
      );

      if (existingWithServerId.isNotEmpty) {
        final dupId = existingWithServerId.first['id'];
        await txn.delete(tableName, where: 'id = ?', whereArgs: [dupId]);
      }

      final existingWithId = await txn.query(
        tableName,
        where: 'id = ?',
        whereArgs: [serverId],
        limit: 1,
      );

      if (existingWithId.isEmpty) {
        await txn.update(
          tableName,
          {'id': serverId, 'server_id': serverId, 'is_synced': 1},
          where: 'id = ?',
          whereArgs: [id],
        );
      } else {
        await txn.update(
          tableName,
          {'server_id': serverId, 'is_synced': 1},
          where: 'id = ?',
          whereArgs: [id],
        );
      }
    });
  }

  Future<void> markRecordAsSynced(String table, int id, int? serverId) async {
    Database db = await database;
    try {
      if (serverId == null || serverId <= 0 || serverId == id) {
        await db.update(table, {'is_synced': 1}, where: 'id = ?', whereArgs: [id]);
        return;
      }
      final cols = await db.rawQuery("PRAGMA table_info($table)");
      final hasServerId = cols.any((c) => c['name'] == 'server_id');
      if (!hasServerId) {
        await db.update(table, {'is_synced': 1}, where: 'id = ?', whereArgs: [id]);
        return;
      }

      await db.transaction((txn) async {
        final current = await txn.query(table, where: 'id = ?', whereArgs: [id], limit: 1);
        if (current.isEmpty) return;

        // Deduplication: if an entry from web sync down already has this server_id
        final existingWithServerId = await txn.query(
          table,
          where: 'server_id = ? AND id != ?',
          whereArgs: [serverId, id],
          limit: 1,
        );
        if (existingWithServerId.isNotEmpty) {
          final dupId = existingWithServerId.first['id'];
          await txn.delete(table, where: 'id = ?', whereArgs: [dupId]);
        }

        final existingWithId = await txn.query(
          table,
          where: 'id = ?',
          whereArgs: [serverId],
          limit: 1,
        );
        if (existingWithId.isEmpty) {
          await txn.update(
            table,
            {'id': serverId, 'server_id': serverId, 'is_synced': 1},
            where: 'id = ?',
            whereArgs: [id],
          );
        } else {
          await txn.update(
            table,
            {'server_id': serverId, 'is_synced': 1},
            where: 'id = ?',
            whereArgs: [id],
          );
        }
      });
    } catch (e) {
      debugPrint("markRecordAsSynced error ($table, $id): $e");
      try {
        await db.update(table, {'is_synced': 1}, where: 'id = ?', whereArgs: [id]);
      } catch (_) {}
    }
  }
}
