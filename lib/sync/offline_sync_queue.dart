import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import '../utils/network_service.dart';

/// Persistent Offline Sync Queue
/// انٹرنیٹ نہ ہونے پر ہر عمل اس قطار میں محفوظ ہوگا اور ایپ ری سٹارٹ پر ضائع نہیں ہوگا
class OfflineSyncQueue {
  /// Ensures the offline_sync_queue table exists
  static Future<void> ensureQueueTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS offline_sync_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT NOT NULL,
        entity_id INTEGER,
        action TEXT NOT NULL,
        client_uuid TEXT NOT NULL UNIQUE,
        tenant_id INTEGER NOT NULL,
        payload_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
      )
    ''');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON offline_sync_queue(status, tenant_id)');
    await db.execute('CREATE INDEX IF NOT EXISTS idx_sync_queue_uuid ON offline_sync_queue(client_uuid)');
  }

  /// قطار میں نیا اندراج محفوظ کریں
  static Future<int> enqueue({
    required Database db,
    required String entityType,
    required int? entityId,
    required String action,
    required String clientUuid,
    required int tenantId,
    required Map<String, dynamic> payload,
  }) async {
    await ensureQueueTable(db);
    final now = DateTime.now().toIso8601String();
    return await db.insert(
      'offline_sync_queue',
      {
        'entity_type': entityType,
        'entity_id': entityId,
        'action': action,
        'client_uuid': clientUuid,
        'tenant_id': tenantId,
        'payload_json': jsonEncode(payload),
        'status': 'pending',
        'attempts': 0,
        'created_at': now,
        'updated_at': now,
      },
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  /// تمام زیر التواء اندراجات کا شمار حاصل کریں
  static Future<int> getPendingCount(Database db, int tenantId) async {
    await ensureQueueTable(db);
    final res = await db.rawQuery(
      "SELECT COUNT(*) as count FROM offline_sync_queue WHERE status = 'pending' AND tenant_id = ?",
      [tenantId],
    );
    return (res.first['count'] as num?)?.toInt() ?? 0;
  }

  /// زیر التواء اندراجات حاصل کریں (FIFO آرڈر میں)
  static Future<List<Map<String, dynamic>>> getPendingItems(Database db, int tenantId, {int limit = 50}) async {
    await ensureQueueTable(db);
    return await db.query(
      'offline_sync_queue',
      where: "status = 'pending' AND tenant_id = ?",
      whereArgs: [tenantId],
      orderBy: 'id ASC',
      limit: limit,
    );
  }

  /// اندراج کو کامیاب مارک کریں
  static Future<void> markCompleted(Database db, int id) async {
    await db.update(
      'offline_sync_queue',
      {
        'status': 'synced',
        'updated_at': DateTime.now().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  /// اندراج کو فیل مارک کریں
  static Future<void> markFailed(Database db, int id, String error) async {
    await db.rawUpdate(
      '''
      UPDATE offline_sync_queue 
      SET status = 'failed', 
          attempts = attempts + 1, 
          last_error = ?, 
          updated_at = ? 
      WHERE id = ?
      ''',
      [error, DateTime.now().toIso8601String(), id],
    );
  }

  /// زیر التواء آف لائن قطار کو سرور پر اپلوڈ اور کلیئر کرنے کا میتھڈ
  static Future<int> processPendingQueue({
    required Database db,
    required int tenantId,
  }) async {
    await ensureQueueTable(db);
    final items = await getPendingItems(db, tenantId, limit: 100);
    if (items.isEmpty) return 0;

    int syncedCount = 0;
    for (final item in items) {
      final id = item['id'] as int;
      final entityType = item['entity_type'] as String;
      final action = item['action'] as String;
      final payloadJson = item['payload_json'] as String;

      try {
        final payload = jsonDecode(payloadJson) as Map<String, dynamic>;
        final res = await NetworkService.postWithFallbackAction(
          action,
          {
            'entity_type': entityType,
            'client_uuid': item['client_uuid'],
            'data': payload,
            'tenant_id': tenantId,
          },
          timeout: const Duration(seconds: 30),
        );

        if (res.statusCode == 200) {
          final resBody = jsonDecode(res.body);
          if (resBody is Map && (resBody['status'] == 'success' || resBody['status'] == 'partial_success')) {
            await markCompleted(db, id);
            syncedCount++;
            continue;
          }
        }
        await markFailed(db, id, 'Server HTTP ${res.statusCode}');
      } catch (e) {
        await markFailed(db, id, e.toString());
      }
    }
    return syncedCount;
  }
}
