import 'dart:io';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import 'globals.dart';

/// سنگل ٹیپ لوکل ڈیٹا بیس بیک اپ و شیئرنگ سروس
/// واٹس ایپ، گوگل ڈرائیو، یا لوکل اسٹوریج پر محفوظ کرنے کی صلاحیت
class DatabaseBackupService {
  /// ڈیٹا بیس فائل کی تفاصیل اور شماریات معلوم کریں
  static Future<Map<String, dynamic>> getDatabaseInfo() async {
    try {
      final dbPath = p.join(await getDatabasesPath(), 'madrasa.db');
      final file = File(dbPath);
      final exists = await file.exists();
      int sizeBytes = 0;
      if (exists) {
        sizeBytes = await file.length();
      }

      final db = await DBHelper().database;
      final stdCountRes = await db.rawQuery(
          "SELECT COUNT(*) as cnt FROM students WHERE is_deleted = 0 OR is_deleted IS NULL");
      final finCountRes = await db.rawQuery(
          "SELECT COUNT(*) as cnt FROM finance WHERE is_deleted = 0 OR is_deleted IS NULL");
      final attCountRes = await db.rawQuery(
          "SELECT COUNT(*) as cnt FROM attendance WHERE is_deleted = 0 OR is_deleted IS NULL");

      final stdCount = Sqflite.firstIntValue(stdCountRes) ?? 0;
      final finCount = Sqflite.firstIntValue(finCountRes) ?? 0;
      final attCount = Sqflite.firstIntValue(attCountRes) ?? 0;

      final sizeMB = (sizeBytes / (1024 * 1024)).toStringAsFixed(2);

      return {
        'exists': exists,
        'path': dbPath,
        'size_bytes': sizeBytes,
        'size_mb': sizeMB,
        'student_count': stdCount,
        'finance_count': finCount,
        'attendance_count': attCount,
        'last_modified': exists ? file.lastModifiedSync() : null,
      };
    } catch (e) {
      debugPrint("Error fetching DB info: $e");
      return {'exists': false, 'error': e.toString()};
    }
  }

  /// سنگل ٹیپ بیک اپ تیار کر کے واٹس ایپ/گوگل ڈرائیو پر شیئر کریں
  static Future<bool> createAndShareBackup(BuildContext context) async {
    try {
      // 1. Show Progress Indicator
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Row(
            children: [
              SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                ),
              ),
              SizedBox(width: 12),
              Text(
                'ڈیٹا بیس کا محفوظ بیک اپ تیار ہو رہا ہے...',
                style: TextStyle(fontFamily: 'JameelNooriNastaleeq', fontSize: 16),
              ),
            ],
          ),
          duration: Duration(seconds: 2),
          backgroundColor: Color(0xFF1B5E20),
        ),
      );

      final dbPath = p.join(await getDatabasesPath(), 'madrasa.db');
      final originalFile = File(dbPath);

      if (!await originalFile.exists()) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('ڈیٹا بیس فائل موجود نہیں ہے!'),
              backgroundColor: Colors.red,
            ),
          );
        }
        return false;
      }

      // 2. Safe Flush / Checkpoint to WAL if active
      try {
        final db = await DBHelper().database;
        await db.rawQuery('PRAGMA wal_checkpoint(FULL);');
      } catch (_) {}

      // 3. Create timestamped backup file in cache
      final tempDir = await getTemporaryDirectory();
      final now = DateTime.now();
      final dateStr = DateFormat('yyyy_MM_dd_HHmm').format(now);
      final backupFileName = 'madrasa_backup_$dateStr.db';
      final backupFilePath = p.join(tempDir.path, backupFileName);

      await originalFile.copy(backupFilePath);
      final backupFile = File(backupFilePath);

      if (!await backupFile.exists()) {
        throw Exception("بیک اپ فائل تیار نہ ہو سکی");
      }

      final dateFormatted = DateFormat('dd MMMM yyyy, hh:mm a').format(now);
      final shareText = '''
📦 *${(currentTenantName.value ?? '').trim().isNotEmpty ? currentTenantName.value!.trim() : 'مدرسہ مینجمنٹ سسٹم'} - لوکل ڈیٹا بیس بیک اپ*
📅 تاریخ: $dateFormatted
📁 فائل: $backupFileName
🔒 یہ بیک اپ فائل محفوظ رکھیں تاکہ کسی بھی وقت بحال (Restore) کی جا سکے۔
''';

      // 4. Trigger Native Share Sheet (WhatsApp, Google Drive, Bluetooth, etc.)
      await Share.shareXFiles(
        [XFile(backupFilePath, mimeType: 'application/octet-stream', name: backupFileName)],
        text: shareText,
        subject: 'Madrasa Database Backup - $dateStr',
      );

      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'بیک اپ فائل کامیابی سے تیار کر لی گئی: $backupFileName',
              style: const TextStyle(fontFamily: 'JameelNooriNastaleeq', fontSize: 15),
            ),
            backgroundColor: const Color(0xFF2E7D32),
            duration: const Duration(seconds: 4),
          ),
        );
      }

      return true;
    } catch (e) {
      debugPrint("Backup sharing error: $e");
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('خرابی: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
      return false;
    }
  }

  /// بیک اپ کنفرمیشن و تفاصیل کا الرٹ ڈائیلاگ
  static void showBackupDialog(BuildContext context) async {
    final info = await getDatabaseInfo();
    if (!context.mounted) return;

    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.backup_rounded, color: Color(0xFF1B5E20), size: 28),
              SizedBox(width: 8),
              Text(
                'ڈیٹا بیس بیک اپ و شیئرنگ',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 18,
                  fontFamily: 'JameelNooriNastaleeq',
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'آپ ایک کلک میں تمام طلبہ، حاضری، مالیات، امتحانات اور کتب خانہ کا بیک اپ بنا کر اپنے واٹس ایپ یا گوگل ڈرائیو پر محفوظ کر سکتے ہیں۔',
                style: TextStyle(fontSize: 14, height: 1.4),
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.green.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.green.withOpacity(0.3)),
                ),
                child: Column(
                  children: [
                    _infoRow('ڈیٹا بیس سائز:', '${info['size_mb'] ?? '0'} MB'),
                    const Divider(height: 12),
                    _infoRow('کل طلبہ ریکارڈز:', '${info['student_count'] ?? 0}'),
                    const Divider(height: 12),
                    _infoRow('مالیاتی اندراجات:', '${info['finance_count'] ?? 0}'),
                    const Divider(height: 12),
                    _infoRow('حاضری ریکارڈز:', '${info['attendance_count'] ?? 0}'),
                  ],
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('منسوخ', style: TextStyle(color: Colors.grey)),
            ),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF1B5E20),
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              onPressed: () {
                Navigator.pop(ctx);
                createAndShareBackup(context);
              },
              icon: const Icon(Icons.share_rounded, size: 18),
              label: const Text(
                'بیک اپ شیئر کریں (WhatsApp/Drive)',
                style: TextStyle(fontFamily: 'JameelNooriNastaleeq', fontSize: 15),
              ),
            ),
          ],
        );
      },
    );
  }

  static Widget _infoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF1B5E20))),
      ],
    );
  }
}
