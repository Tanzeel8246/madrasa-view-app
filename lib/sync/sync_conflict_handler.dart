import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';

class SyncConflictHandler {
  /// Ensures the sync_conflicts table exists in local database
  static Future<void> ensureConflictTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS sync_conflicts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        table_name TEXT NOT NULL,
        record_id TEXT,
        local_data TEXT,
        server_data TEXT,
        conflict_time TEXT NOT NULL,
        is_resolved INTEGER DEFAULT 0
      )
    ''');
  }

  /// Logs a conflict when both local and remote records have divergent un-synced updates
  static Future<void> logConflict({
    required DBHelper dbHelper,
    required String tableName,
    required String recordId,
    required Map<String, dynamic> localData,
    required Map<String, dynamic> serverData,
  }) async {
    try {
      final db = await dbHelper.database;
      await ensureConflictTable(db);
      await db.insert('sync_conflicts', {
        'table_name': tableName,
        'record_id': recordId,
        'local_data': jsonEncode(localData),
        'server_data': jsonEncode(serverData),
        'conflict_time': DateTime.now().toIso8601String(),
        'is_resolved': 0,
      });
      debugPrint('[SyncConflictHandler] Logged conflict for $tableName (ID: $recordId)');
    } catch (e) {
      debugPrint('[SyncConflictHandler] Failed to log conflict: $e');
    }
  }

  /// Fetches all unresolved conflicts for user review
  static Future<List<Map<String, dynamic>>> getUnresolvedConflicts(DBHelper dbHelper) async {
    try {
      final db = await dbHelper.database;
      await ensureConflictTable(db);
      return await db.query(
        'sync_conflicts',
        where: 'is_resolved = 0',
        orderBy: 'id DESC',
      );
    } catch (e) {
      return [];
    }
  }
}
