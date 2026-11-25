import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../utils/session.dart';
import '../utils/system_type_global.dart';
import 'sync_engine.dart';
import 'sync_upload.dart';

class SyncDownloader {
  /// Server-stamped timestamp from latest successful sync
  static String? lastServerTime;

  /// Download sync with optional delta timestamp and forceFull support
  static Future<Map<String, int>> syncDown(DBHelper db,
      {bool forceFull = false}) async {
    final sys = SystemTypeGlobal.currentSystemType.value;
    bool effectiveForce = forceFull;
    if (!effectiveForce) {
      try {
        final localDb = await db.database;
        final countRes = await localDb.rawQuery(
            'SELECT COUNT(*) as cnt FROM students WHERE is_deleted = 0 OR is_deleted IS NULL');
        final cnt =
            countRes.isNotEmpty ? (countRes.first['cnt'] as int? ?? 0) : 0;
        if (cnt == 0) {
          effectiveForce = true;
        }
      } catch (_) {}
    }

    final lastSync =
        effectiveForce ? null : await SyncEngine.getLastSyncTime();
    final since = (lastSync != null && !effectiveForce)
        ? lastSync.subtract(const Duration(minutes: 5)).toIso8601String()
        : '';

    String action = 'sync_down&system_type=${Uri.encodeComponent(sys)}';
    if (effectiveForce) {
      action += '&full_sync=1';
    } else if (since.isNotEmpty) {
      action += '&since=${Uri.encodeComponent(since)}';
    }

    final pullResponse = await NetworkService.getWithFallbackAction(
      action,
      timeout: const Duration(seconds: 60),
    );

    if (pullResponse.statusCode != 200) {
      throw Exception('Sync down failed: ${pullResponse.statusCode}');
    }

    lastServerTime = null;
    final pullBody = SyncUploader.decodeJsonMap(pullResponse.body);
    if (pullBody['server_time'] != null &&
        pullBody['server_time'].toString().trim().isNotEmpty) {
      lastServerTime = pullBody['server_time'].toString().trim();
    } else if (pullResponse.headers['date'] != null) {
      try {
        final headerDate = HttpDate.parse(pullResponse.headers['date']!);
        lastServerTime = headerDate.toIso8601String();
      } catch (_) {}
    }

    if (pullBody['status'] == 'success' && pullBody['data'] != null) {
      final rawData = Map<String, dynamic>.from(pullBody['data']);

      // Live permissions reconciliation from server (preserve all SaaS session fields)
      if (rawData['current_user_permissions'] is List) {
        try {
          final freshPerms = (rawData['current_user_permissions'] as List)
              .map((e) => e.toString())
              .toList();
          currentUserPermissions.value = freshPerms;
          final sess = await SessionManager.getSession();
          if (sess != null) {
            await SessionManager.saveSession(
              sess['role']?.toString() ?? 'nazim',
              int.tryParse(sess['userId']?.toString() ?? '1') ?? 1,
              permissions: freshPerms,
              token: sess['token']?.toString(),
              name: sess['name']?.toString(),
              assignedClasses: (sess['assignedClasses'] as List?)
                  ?.map((e) => e.toString())
                  .toList(),
              studentIds: (sess['studentIds'] as List?)
                  ?.map((e) => int.tryParse(e.toString()) ?? 0)
                  .where((id) => id > 0)
                  .toList(),
              tenantCode: sess['tenantCode']?.toString(),
              tenantId: sess['tenantId'] is int
                  ? sess['tenantId']
                  : int.tryParse(sess['tenantId']?.toString() ?? ''),
              tenantName: sess['tenantName']?.toString(),
              masjidName: sess['masjidName']?.toString(),
              allowedModules: (sess['allowedModules'] as List?)
                  ?.map((e) => e.toString())
                  .toList(),
              isTrial: sess['isTrial'] == true,
              trialDaysLeft: int.tryParse(sess['trialDaysLeft']?.toString() ?? '0'),
              planName: sess['planName']?.toString(),
              planCode: sess['planCode']?.toString(),
              subscriptionEndsAt: sess['subscriptionEndsAt']?.toString(),
              subscriptionStatus: sess['subscriptionStatus']?.toString(),
              systemType: sess['systemType']?.toString(),
              maxStudents: int.tryParse(sess['maxStudents']?.toString() ?? '20'),
              maxClasses: int.tryParse(sess['maxClasses']?.toString() ?? '1'),
              maxTeachers: int.tryParse(sess['maxTeachers']?.toString() ?? '2'),
            );
          }
        } catch (_) {}
      }

      await db.saveServerData(rawData);

      // Privacy safeguard: non-admin devices do not retain synced finance rows owned by other users
      final role = (currentUserRole.value ?? '').toLowerCase();
      final isAdmin = role == 'admin' ||
          role == 'superadmin' ||
          role == 'super_admin' ||
          role == 'administrator';
      if (!isAdmin) {
        final localDb = await db.database;
        await _purgeOtherUsersLocalFinance(localDb);
      }

      if (effectiveForce) {
        final localDb = await db.database;
        await _runIdGuardOnFullSync(localDb, rawData);
      }

      final counts = <String, int>{};
      rawData.forEach((key, val) {
        if (val is List && val.isNotEmpty) {
          counts[key] = val.length;
        }
      });
      return counts;
    }

    throw Exception((pullBody['message'] ?? 'Sync down failed').toString());
  }

  /// ID Guard strictly on Full Sync: reconciles stale synced local records against server snapshot
  static Future<void> _runIdGuardOnFullSync(
      Database db, Map<String, dynamic> rawData) async {
    try {
      if (rawData.containsKey('acc_vouchers') &&
          rawData['acc_vouchers'] is List) {
        final serverIds = (rawData['acc_vouchers'] as List)
            .map((item) => int.tryParse(item['id']?.toString() ?? ''))
            .whereType<int>()
            .toSet();
        if (serverIds.isNotEmpty) {
          final localRows = await db.rawQuery(
              'SELECT id FROM acc_vouchers WHERE (is_synced = 1 OR is_synced IS NULL)');
          final staleIds = localRows
              .map((r) => r['id'] as int)
              .where((id) => !serverIds.contains(id))
              .toList();
          for (var i = 0; i < staleIds.length; i += 500) {
            final end =
                (i + 500 < staleIds.length) ? i + 500 : staleIds.length;
            final chunk = staleIds.sublist(i, end);
            final q = List.filled(chunk.length, '?').join(',');
            await db.rawDelete(
                'DELETE FROM acc_ledger_entries WHERE voucher_id IN ($q)',
                chunk);
            await db.rawDelete(
                'DELETE FROM acc_vouchers WHERE id IN ($q)', chunk);
          }
        }
      }

      if (rawData.containsKey('acc_payroll') &&
          rawData['acc_payroll'] is List) {
        final serverIds = (rawData['acc_payroll'] as List)
            .map((item) => int.tryParse(item['id']?.toString() ?? ''))
            .whereType<int>()
            .toSet();
        if (serverIds.isNotEmpty) {
          final localRows = await db.rawQuery(
              'SELECT id FROM acc_payroll WHERE (is_synced = 1 OR is_synced IS NULL)');
          final staleIds = localRows
              .map((r) => r['id'] as int)
              .where((id) => !serverIds.contains(id))
              .toList();
          for (var i = 0; i < staleIds.length; i += 500) {
            final end =
                (i + 500 < staleIds.length) ? i + 500 : staleIds.length;
            final chunk = staleIds.sublist(i, end);
            final q = List.filled(chunk.length, '?').join(',');
            await db.rawDelete(
                'DELETE FROM acc_payroll WHERE id IN ($q)', chunk);
          }
        }
      }

      if (rawData.containsKey('finance_history') &&
          rawData['finance_history'] is List) {
        final serverIds = (rawData['finance_history'] as List)
            .map((item) => int.tryParse(item['id']?.toString() ?? ''))
            .whereType<int>()
            .toSet();
        if (serverIds.isNotEmpty) {
          final localRows = await db.rawQuery(
              'SELECT id FROM finance WHERE (is_synced = 1 OR is_synced IS NULL)');
          final staleIds = localRows
              .map((r) => r['id'] as int)
              .where((id) => !serverIds.contains(id))
              .toList();
          for (var i = 0; i < staleIds.length; i += 500) {
            final end =
                (i + 500 < staleIds.length) ? i + 500 : staleIds.length;
            final chunk = staleIds.sublist(i, end);
            final q = List.filled(chunk.length, '?').join(',');
            await db.rawDelete(
                'DELETE FROM finance WHERE id IN ($q)', chunk);
          }
        }
      }
    } catch (e) {
      debugPrint('ID Guard Full Sync error: $e');
    }
  }

  /// Dynamically resolves current user's personal Asset accounts and purges synced rows from other users
  static Future<void> _purgeOtherUsersLocalFinance(Database localDb) async {
    try {
      final myUid = currentUserId.value;
      if (myUid == null || myUid <= 1) return;

      final myName = (currentUserName.value ?? '').trim().toLowerCase();
      final List<String> myNames = [if (myName.isNotEmpty) myName];

      try {
        final uRows = await localDb.rawQuery(
            'SELECT username, name FROM admins WHERE id = ?', [myUid]);
        for (final r in uRows) {
          final u = r['username']?.toString().trim().toLowerCase() ?? '';
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          if (u.isNotEmpty && !myNames.contains(u)) myNames.add(u);
          if (n.isNotEmpty && !myNames.contains(n)) myNames.add(n);
        }
      } catch (_) {}

      final Set<int> myAccountIds = {};
      try {
        final patterns = myNames
            .where((n) => n.length >= 3)
            .map((n) => '%$n%')
            .toList();
        if (patterns.isNotEmpty) {
          final whereAcc = patterns.map((_) => 'name LIKE ?').join(' OR ');
          final accs = await localDb.rawQuery(
            "SELECT id, code FROM acc_chart_of_accounts WHERE type = 'Asset' AND ($whereAcc) AND (is_deleted = 0 OR is_deleted IS NULL)",
            patterns,
          );
          for (final a in accs) {
            final c = (a['code']?.toString() ?? '').trim();
            final aid = int.tryParse(a['id']?.toString() ?? '') ?? 0;
            if (c != '1001' && c != '1002' && aid > 0) {
              myAccountIds.add(aid);
            }
          }
        }
      } catch (_) {}

      final accCond = myAccountIds.isNotEmpty
          ? "AND (account_id IS NULL OR account_id NOT IN (${myAccountIds.join(',')}))"
          : '';

      await localDb.rawDelete('''
        DELETE FROM finance
        WHERE (is_synced = 1 OR is_synced IS NULL)
          AND user_id IS NOT NULL
          AND user_id != ?
          $accCond
      ''', [myUid]);

      await localDb.rawDelete('''
        DELETE FROM masjid_finance
        WHERE (is_synced = 1 OR is_synced IS NULL)
          AND user_id IS NOT NULL
          AND user_id != ?
          $accCond
      ''', [myUid]);
    } catch (e) {
      debugPrint('Error purging other users local finance: $e');
    }
  }
}
