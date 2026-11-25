import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../db_helper.dart';
import '../utils/network_service.dart';
import 'sync_config.dart';
import 'sync_image_processor.dart';
import 'sync_permissions.dart';

class SyncUploader {
  static Map<String, dynamic> decodeJsonMap(String body) {
    final jsonStart = body.indexOf('{');
    final jsonEnd = body.lastIndexOf('}');
    final cleanBody = (jsonStart >= 0 && jsonEnd > jsonStart)
        ? body.substring(jsonStart, jsonEnd + 1)
        : body;
    final decoded = jsonDecode(cleanBody);
    return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
  }

  static Future<http.Response> postWithRetry(
    String action,
    Map<String, dynamic> payload, {
    int maxRetries = 2,
  }) async {
    http.Response? lastResponse;
    for (int attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        final response = await NetworkService.postWithFallbackAction(
          action,
          payload,
          timeout: const Duration(seconds: 45),
        );
        lastResponse = response;
        if (response.statusCode == 200) return response;
        if (response.statusCode == 401 || response.statusCode == 403) {
          return response;
        }
        if (attempt < maxRetries) {
          await Future.delayed(Duration(seconds: attempt + 1));
        }
      } catch (e) {
        if (attempt == maxRetries) rethrow;
        await Future.delayed(Duration(seconds: attempt + 1));
      }
    }
    if (lastResponse != null) return lastResponse;
    throw Exception('Upload request failed after $maxRetries retries');
  }

  static Future<int> uploadSingleTable({
    required DBHelper db,
    required String table,
    required List<String> uploadErrors,
  }) async {
    if (!SyncPermissions.canUploadTable(table)) return 0;

    final unsynced = await db.getUnsyncedData(table);
    if (unsynced.isEmpty) return 0;

    // SYNC-SAFETY (F-01): before the first network attempt, make sure every
    // row that can carry a client_uuid has one PERSISTED locally. Retries
    // then reuse the same uuid and the server's (tenant_id, client_uuid)
    // deduplication can actually work — a lost response can no longer turn
    // into a duplicate record on retry.
    for (final row in unsynced) {
      try {
        await db.ensureClientUuidPersisted(table, row);
      } catch (_) {}
    }

    final apiKey = SyncConfig.apiKeyMap[table] ?? table;
    const chunkSize = 50;
    int tableUploaded = 0;

    for (var i = 0; i < unsynced.length; i += chunkSize) {
      final end =
          (i + chunkSize < unsynced.length) ? i + chunkSize : unsynced.length;
      var chunk = unsynced.sublist(i, end);

      // Convert file paths to base64 for image columns
      chunk = await SyncImageProcessor.processImagesForSync(chunk, table);
      chunk = chunk.map((row) {
        final copy = Map<String, dynamic>.from(row);
        if (table == 'students') {
          copy.remove('password');
        }
        // client_uuid was already ensured AND persisted above (F-01). Do
        // NOT invent a fresh one here: a uuid that changes between attempts
        // defeats the server's deduplication and can create duplicates.
        // Tables without a client_uuid column simply send none — the
        // server matches those rows by server_id / natural keys instead.
        return copy;
      }).toList();

      try {
        final response = await postWithRetry(
          'sync_up',
          {apiKey: chunk},
        );

        if (response.statusCode != 200) {
          throw Exception('HTTP ${response.statusCode}: ${response.body}');
        }

        final body = decodeJsonMap(response.body);
        if (body['status'] != 'success' &&
            body['status'] != 'partial_success') {
          throw Exception(body['message']?.toString() ?? 'Sync up failed');
        }

        final syncedIds = body['synced_ids']?[apiKey];
        final serverIds = body['server_ids']?[apiKey];
        if (table == 'finance' &&
            (syncedIds is! List || syncedIds.length < chunk.length)) {
          final errors =
              body['errors']?.toString() ?? 'Unknown finance sync error';
          throw Exception(errors);
        }

        if (syncedIds is List) {
          for (final id in syncedIds) {
            final localId = int.tryParse(id.toString()) ?? 0;
            if (localId == 0) continue;
            if (table == 'finance' || table == 'masjid_finance') {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markFinanceAsSynced(localId, serverId,
                  isMasjid: table == 'masjid_finance');
            } else if (table == 'teachers') {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markTeacherAsSynced(localId, serverId);
            } else if (table == 'students') {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markStudentAsSynced(localId, serverId, isMaktab: false);
            } else if (table == 'maktab_students') {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markStudentAsSynced(localId, serverId, isMaktab: true);
            } else if (table == 'teacher_leaves') {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markTeacherLeaveAsSynced(localId, serverId);
            } else {
              int? serverId;
              if (serverIds is Map && serverIds[id.toString()] != null) {
                serverId = int.tryParse(serverIds[id.toString()].toString());
              }
              await db.markRecordAsSynced(table, localId, serverId);
            }
            tableUploaded++;
          }
        }
      } catch (e) {
        final message = 'Sync up failed for $table: $e';
        debugPrint(message);
        uploadErrors.add(message);
      }
    }

    return tableUploaded;
  }

  static Future<void> uploadUnsyncedSettings({
    required DBHelper db,
    required List<String> uploadErrors,
  }) async {
    try {
      final database = await db.database;
      final rows = await database.query(
        'settings',
        where: 'id = 1 AND is_synced = 0',
      );
      if (rows.isEmpty) return;

      final s = rows.first;
      final payload = <String, dynamic>{
        'madrasa_name': s['madrasa_name'] ?? '',
        'masjid_name': s['masjid_name'] ?? '',
        'tagline': s['tagline'] ?? '',
        'phone': s['phone'] ?? '',
        'address': s['address'] ?? '',
        'current_hijri_year': s['current_hijri_year'] ?? '',
        'week_start_day': s['week_start_day'] ?? 6,
        'ai_provider': s['ai_provider'] ?? 'deepseek',
        'auto_notify_day': s['auto_notify_day'] ?? 'Saturday',
        'auto_notify_time': s['auto_notify_time'] ?? '10:00 AM',
      };

      Future<void> attachFile(String col, String key) async {
        final path = s[col]?.toString();
        if (path != null &&
            path.isNotEmpty &&
            !path.startsWith('http') &&
            !path.startsWith('uploads/')) {
          final file = File(path);
          if (file.existsSync()) {
            final bytes = await file.readAsBytes();
            payload[key] = base64Encode(bytes);
          }
        }
      }

      await attachFile('logo_path', 'logo_base64');
      await attachFile('masjid_logo_path', 'masjid_logo_base64');
      await attachFile('signature_stamp_path', 'signature_stamp_base64');
      await attachFile('receipt_bg', 'receipt_bg_base64');
      await attachFile('masjid_receipt_bg', 'masjid_receipt_bg_base64');
      await attachFile('id_card_bg', 'id_card_bg_base64');
      await attachFile('sanad_bg', 'sanad_bg_base64');

      final res = await postWithRetry('update_settings', payload);
      if (res.statusCode == 200) {
        final body = decodeJsonMap(res.body);
        if (body['status'] == 'success') {
          final serverSettings = body['settings'];
          final updateMap = <String, dynamic>{'is_synced': 1};
          if (serverSettings is Map) {
            for (final key in [
              'logo_path',
              'masjid_logo_path',
              'signature_stamp_path',
              'receipt_bg',
              'masjid_receipt_bg',
              'id_card_bg',
              'sanad_bg',
            ]) {
              if (serverSettings[key] != null) {
                updateMap[key] = serverSettings[key];
              }
            }
          }
          await database.update('settings', updateMap, where: 'id = 1');
          debugPrint('Successfully synced offline settings to server.');
        }
      }
    } catch (e) {
      final msg = 'Sync up failed for settings: $e';
      debugPrint(msg);
      uploadErrors.add(msg);
    }
  }
}
