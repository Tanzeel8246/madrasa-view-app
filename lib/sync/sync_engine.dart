// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../db_helper.dart';
import '../utils/network_service.dart';
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

  /// Save last sync timestamp in secure storage
  static Future<void> saveLastSyncTime() async {
    try {
      const storage = FlutterSecureStorage();
      await storage.write(
        key: 'last_sync_time',
        value: DateTime.now().toIso8601String(),
      );
    } catch (e) {
      debugPrint("Error saving last sync time: $e");
    }
  }

  /// Get last sync timestamp from secure storage
  static Future<DateTime?> getLastSyncTime() async {
    try {
      const storage = FlutterSecureStorage();
      final str = await storage.read(key: 'last_sync_time');
      if (str == null || str.isEmpty) return null;
      return DateTime.tryParse(str);
    } catch (e) {
      debugPrint("Error reading last sync time: $e");
      return null;
    }
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

    // 1. Upload Phase
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

    try {
      final downloadedCounts =
          await SyncDownloader.syncDown(db, forceFull: forceFull);
      downloadedPerTable.addAll(downloadedCounts);
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

    await saveLastSyncTime();

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
