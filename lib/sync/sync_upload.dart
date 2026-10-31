import 'dart:convert';
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
        // Ensure client_uuid is present for robust idempotency
        if (copy['client_uuid'] == null || copy['client_uuid'].toString().trim().isEmpty) {
          final rowId = copy['id'] ?? '0';
          copy['client_uuid'] = '${table}_${rowId}_${DateTime.now().millisecondsSinceEpoch}';
        }
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
}
