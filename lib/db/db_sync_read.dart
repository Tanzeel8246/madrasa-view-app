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
