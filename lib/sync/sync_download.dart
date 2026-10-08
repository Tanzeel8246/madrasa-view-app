import '../db_helper.dart';
import '../utils/network_service.dart';
import '../utils/system_type_global.dart';
import 'sync_engine.dart';
import 'sync_upload.dart';

class SyncDownloader {
  /// Download sync with optional delta timestamp and forceFull support
  static Future<Map<String, int>> syncDown(DBHelper db,
      {bool forceFull = false}) async {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final effectiveForce = forceFull;
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

    final pullBody = SyncUploader.decodeJsonMap(pullResponse.body);
    if (pullBody['status'] == 'success' && pullBody['data'] != null) {
      final rawData = Map<String, dynamic>.from(pullBody['data']);
      await db.saveServerData(rawData);

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
}
