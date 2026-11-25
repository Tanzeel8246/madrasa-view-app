// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../db_helper.dart';
import '../utils/data_refresh_bus.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import 'offline_sync_queue.dart';
import 'sync_config.dart';
import 'sync_download.dart';
import 'sync_models.dart';
import 'sync_permissions.dart';
import 'sync_upload.dart';

class SyncEngine {
  static final ValueNotifier<SyncProgress?> progress = ValueNotifier(null);
  static SyncResult? lastResult;
  static bool _isSyncing = false;

  /// Check if a sync is currently in progress
  static bool get isSyncing => _isSyncing;

  /// نان بلاکنگ پس پردہ فوری سنک (Optimistic Background Sync)
  /// کلک کے فوراً بعد بغیر UI فریز کیے متعلقہ ٹیبل کا نیا ڈیٹا سرور پر بھیجتا ہے
  static void triggerBackgroundTableSync(String table) {
    Future.microtask(() async {
      try {
        final db = DBHelper();
        final uploadErrors = <String>[];
        if (SyncPermissions.canUploadTable(table)) {
          await SyncUploader.uploadSingleTable(
            db: db,
            table: table,
            uploadErrors: uploadErrors,
          );
        }
      } catch (e) {
        debugPrint("Background sync for $table error: $e");
      }
    });
  }

  /// سنگل ٹیبل کو براہ راست اور تیز رفتاری سے سنک کرنے کا فنکشن
  static Future<int> syncSingleTable(String table) async {
    try {
      final db = DBHelper();
      final uploadErrors = <String>[];
      if (!SyncPermissions.canUploadTable(table)) return 0;
      return await SyncUploader.uploadSingleTable(
        db: db,
        table: table,
        uploadErrors: uploadErrors,
      );
    } catch (e) {
      debugPrint("Sync single table error ($table): $e");
      return 0;
    }
  }

  /// Save last sync timestamp in secure storage + SQLite fallback
  static Future<void> saveLastSyncTime([String? serverTime]) async {
    try {
      final val = (serverTime != null && serverTime.trim().isNotEmpty)
          ? serverTime.trim()
          : ((SyncDownloader.lastServerTime != null &&
                  SyncDownloader.lastServerTime!.trim().isNotEmpty)
              ? SyncDownloader.lastServerTime!.trim()
              : DateTime.now().toIso8601String());

      const storage = FlutterSecureStorage();
      await storage.write(
        key: 'last_sync_time',
        value: val,
      );

      try {
        final db = await DBHelper().database;
        await db.execute(
            'CREATE TABLE IF NOT EXISTS app_metadata (key TEXT PRIMARY KEY, value TEXT)');
        await db.rawInsert(
            'INSERT OR REPLACE INTO app_metadata (key, value) VALUES (?, ?)',
            ['last_sync_time', val]);
      } catch (_) {}
    } catch (e) {
      debugPrint("Error saving last sync time: $e");
    }
  }

  /// Get last sync timestamp from secure storage with SQLite fallback
  static Future<DateTime?> getLastSyncTime() async {
    try {
      const storage = FlutterSecureStorage();
      final str = await storage.read(key: 'last_sync_time');
      if (str != null && str.trim().isNotEmpty) {
        final dt = DateTime.tryParse(str.trim());
        if (dt != null) return dt;
      }
    } catch (e) {
      debugPrint("Error reading last sync time: $e");
    }

    try {
      final db = await DBHelper().database;
      final rows = await db.rawQuery(
          "SELECT value FROM app_metadata WHERE key = 'last_sync_time' LIMIT 1");
      if (rows.isNotEmpty && rows.first['value'] != null) {
        final str = rows.first['value'].toString().trim();
        if (str.isNotEmpty) {
          return DateTime.tryParse(str);
        }
      }
    } catch (_) {}

    return null;
  }

  /// Main Two-Way Sync Runner with backward compatibility
  static Future<void> runTwoWaySync({bool forceFull = false}) async {
    await runTwoWaySyncWithResult(forceFull: forceFull);
  }

  /// Main Two-Way Sync Runner returning detailed SyncResult
  static Future<SyncResult> runTwoWaySyncWithResult(
      {bool forceFull = false}) async {
    if (_isSyncing) {
      debugPrint("SyncEngine: Sync already in progress, skipping concurrent duplicate run.");
      return lastResult ??
          SyncResult(
            uploadedPerTable: {},
            downloadedPerTable: {},
            errors: [],
            duration: Duration.zero,
            timestamp: DateTime.now(),
          );
    }
    _isSyncing = true;
    try {
      final startTime = DateTime.now();
      final db = DBHelper();
      final uploadErrors = <String>[];
      final uploadedPerTable = <String, int>{};
      final downloadedPerTable = <String, int>{};

      final uploadableTables =
          SyncConfig.tables.where((t) => SyncPermissions.canUploadTable(t)).toList();
      final totalSteps = uploadableTables.length + 1; // +1 for sync_down

      int completedSteps = 0;

      // 0. Offline Sync Queue Drain Phase
      try {
        final sqliteDb = await db.database;
        final tid = currentTenantId.value ?? 1;
        final drained = await OfflineSyncQueue.processPendingQueue(
          db: sqliteDb,
          tenantId: tid,
        );
        if (drained > 0) {
          uploadedPerTable['offline_queue'] = drained;
        }
      } catch (e) {
        debugPrint('Offline queue drain error: $e');
      }

      // 1. Upload Phase
      await SyncUploader.uploadUnsyncedSettings(
        db: db,
        uploadErrors: uploadErrors,
      );
      for (final table in uploadableTables) {
      progress.value = SyncProgress(
        currentTable: table,
        completed: completedSteps,
        total: totalSteps,
        errors: uploadErrors,
      );

      final tableUploaded = await SyncUploader.uploadSingleTable(
        db: db,
        table: table,
        uploadErrors: uploadErrors,
      );

      if (tableUploaded > 0) {
        uploadedPerTable[table] = tableUploaded;
      }
      completedSteps++;
    }

    // 2. Download Phase
    progress.value = SyncProgress(
      currentTable: 'sync_down',
      completed: completedSteps,
      total: totalSteps,
      errors: uploadErrors,
    );

    var downloadSucceeded = false;
    try {
      final downloadedCounts =
          await SyncDownloader.syncDown(db, forceFull: forceFull);
      downloadedPerTable.addAll(downloadedCounts);
      downloadSucceeded = true;
    } catch (e) {
      final message = 'Sync down failed: $e';
      debugPrint(message);
      uploadErrors.add(message);
    }

    completedSteps++;

    // 3. Accounting Backfill & Finalization
    try {
      await db.ensureSimpleFinanceAccountingBackfill();
    } catch (e) {
      debugPrint('Error in accounting backfill after sync: $e');
    }

    // SYNC-SAFETY: only advance the delta-sync watermark when the download
    // actually succeeded. Previously the timestamp was saved even after a
    // failed download, so server changes from before the failed attempt
    // fell outside the next delta window (lastSync - 5 min) and were never
    // downloaded until a manual full sync.
    if (downloadSucceeded) {
      await saveLastSyncTime(SyncDownloader.lastServerTime);
    }

    progress.value = SyncProgress(
      currentTable: 'done',
      completed: totalSteps,
      total: totalSteps,
      errors: uploadErrors,
    );

    final duration = DateTime.now().difference(startTime);

    final result = SyncResult(
      uploadedPerTable: uploadedPerTable,
      downloadedPerTable: downloadedPerTable,
      errors: uploadErrors,
      duration: duration,
      timestamp: DateTime.now(),
    );

    lastResult = result;

    if (downloadSucceeded || uploadedPerTable.isNotEmpty) {
      DataRefreshBus.notifyAll('sync_completed');
    }

    if (uploadErrors.isNotEmpty) {
      throw Exception(uploadErrors.join('\n'));
    }

    return result;
    } finally {
      _isSyncing = false;
    }
  }

  /// Push new class period book using authenticated NetworkService
  static Future<void> pushNewClassPeriodBook(
      int classId, int periodNo, String newBook) async {
    final Map<String, dynamic> data = {
      'class_id': classId,
      'period_no': periodNo,
      'new_book': newBook,
    };
    try {
      final response = await NetworkService.postWithFallbackAction(
        'push_class_period_book',
        data,
      );
      if (response.statusCode != 200) {
        debugPrint("Failed to push new book: ${response.body}");
      }
    } catch (e) {
      debugPrint("Error pushing new book: $e");
    }
  }
}

/// Alias for backward compatibility
typedef SyncService = SyncEngine;
