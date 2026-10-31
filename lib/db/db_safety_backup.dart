import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import '../db_helper.dart';

/// Database Safety & Pre-Migration Backup Engine
/// ہر اسکیما تبدیلی سے قبل ڈیٹا بیس کا بیک اپ اور نقل پر آزمائشی بحالی (Dry-run restore) یقینی بناتی ہے
class DbSafetyBackup {
  /// ڈیٹا بیس فائل کا موجودہ پاتھ حاصل کریں
  static Future<String> getDatabasePath() async {
    DBHelper.ensureFfiInitialized();
    final dbDir = await getDatabasesPath();
    return join(dbDir, 'madrasa.db');
  }

  /// بیک اپ فولڈر کا راستہ
  static Future<Directory> getBackupDirectory() async {
    final dbDir = await getDatabasesPath();
    final backupDir = Directory(join(dbDir, 'schema_backups'));
    if (!await backupDir.exists()) {
      await backupDir.create(recursive: true);
    }
    return backupDir;
  }

  /// اسکیما تبدیلی سے قبل خودکار محفوظ بیک اپ اور آزمائشی بحالی کا ٹیسٹ
  /// Returns the created backup file path on success
  static Future<String> createBackupAndValidateDryRun({required String reason}) async {
    final dbPath = await getDatabasePath();
    final dbFile = File(dbPath);

    if (!await dbFile.exists()) {
      debugPrint('[DbSafetyBackup] Database file does not exist yet at: $dbPath. Skipping pre-migration backup.');
      return '';
    }

    final backupDir = await getBackupDirectory();
    final timestamp = DateTime.now().toIso8601String().replaceAll(':', '-').replaceAll('.', '_');
    final cleanReason = reason.replaceAll(RegExp(r'[^a-zA-Z0-9_-]'), '_');
    final backupPath = join(backupDir.path, 'madrasa_backup_${timestamp}_$cleanReason.db');
    final dryRunPath = join(backupDir.path, 'madrasa_dryrun_test_$timestamp.db');

    // 1. اصل ڈیٹا بیس کا محفوظ اسنیپ شاٹ بیک اپ لیں
    await dbFile.copy(backupPath);
    debugPrint('🛡️ [DbSafetyBackup] Pre-migration backup created: $backupPath');

    // 2. نقل پر بحالی کی باقاعدہ آزمائش (Dry-run Restore Validation)
    try {
      final dryRunFile = await File(backupPath).copy(dryRunPath);
      if (!await dryRunFile.exists() || await dryRunFile.length() == 0) {
        throw Exception('Dry-run restore failed: copied backup file is empty or missing.');
      }

      // SQLite FFI کے ذریعے نقل شدہ فائل کو اوپن اور جانچیں
      DBHelper.ensureFfiInitialized();
      final testDb = await databaseFactoryFfi.openDatabase(dryRunPath);
      final checkRes = await testDb.rawQuery('PRAGMA integrity_check;');
      if (checkRes.isEmpty || checkRes.first.values.first != 'ok') {
        await testDb.close();
        throw Exception('Dry-run database integrity check failed: $checkRes');
      }
      await testDb.close();

      // آزمائشی فائل کی صفائی
      if (await dryRunFile.exists()) {
        await dryRunFile.delete();
      }
      debugPrint('✅ [DbSafetyBackup] Dry-run restore & integrity verified successfully.');
      return backupPath;
    } catch (e) {
      debugPrint('❌ [DbSafetyBackup] Dry-run restore verification FAILED: $e');
      rethrow;
    }
  }

  /// کسی بھی خرابی پر بیک اپ سے بحالی
  static Future<void> restoreFromBackup(String backupPath) async {
    final bFile = File(backupPath);
    if (!await bFile.exists()) {
      throw Exception('Backup file not found: $backupPath');
    }
    final dbPath = await getDatabasePath();
    final dbFile = File(dbPath);
    await bFile.copy(dbFile.path);
    debugPrint('♻️ [DbSafetyBackup] Restored database from backup: $backupPath');
  }
}
