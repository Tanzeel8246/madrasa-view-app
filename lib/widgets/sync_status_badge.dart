import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../sync/offline_sync_queue.dart';
import '../sync/sync_config.dart';
import '../sync/sync_engine.dart';
import '../utils/globals.dart';

/// واضح اور متحرک سنک انڈیکیٹر و بیج (Synced / Pending count / Failed)
class SyncStatusBadge extends StatefulWidget {
  final VoidCallback? onTap;
  final Color? color;

  const SyncStatusBadge({super.key, this.onTap, this.color});

  @override
  State<SyncStatusBadge> createState() => _SyncStatusBadgeState();
}

class _SyncStatusBadgeState extends State<SyncStatusBadge> {
  int _pendingCount = 0;
  bool _isSyncing = false;

  @override
  void initState() {
    super.initState();
    _checkPendingCount();
    SyncEngine.progress.addListener(_onSyncProgress);
  }

  @override
  void dispose() {
    SyncEngine.progress.removeListener(_onSyncProgress);
    super.dispose();
  }

  void _onSyncProgress() {
    if (mounted) {
      setState(() {
        _isSyncing = SyncEngine.isSyncing;
      });
      if (!_isSyncing) {
        _checkPendingCount();
      }
    }
  }

  Future<void> _checkPendingCount() async {
    try {
      final db = await DBHelper().database;
      final tid = currentTenantId.value ?? 1;
      final queueCount = await OfflineSyncQueue.getPendingCount(db, tid);

      int unsyncedRecords = 0;
      final dbHelper = DBHelper();
      for (final table in SyncConfig.tables) {
        try {
          final unsynced = await dbHelper.getUnsyncedData(table);
          unsyncedRecords += unsynced.length;
        } catch (_) {}
      }

      if (mounted) {
        setState(() {
          _pendingCount = queueCount + unsyncedRecords;
        });
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final textColor = widget.color ?? Colors.white;

    if (_isSyncing) {
      return SizedBox(
        width: 38,
        height: 38,
        child: Center(
          child: SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              valueColor: AlwaysStoppedAnimation<Color>(textColor),
            ),
          ),
        ),
      );
    }

    return InkWell(
      onTap: () {
        widget.onTap?.call();
        _checkPendingCount();
      },
      borderRadius: BorderRadius.circular(20),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                Icon(
                  _pendingCount > 0 ? Icons.cloud_upload : Icons.cloud_done,
                  color: _pendingCount > 0 ? Colors.amberAccent : textColor,
                  size: 22,
                ),
                if (_pendingCount > 0)
                  Positioned(
                    right: -6,
                    top: -4,
                    child: Container(
                      padding: const EdgeInsets.all(3),
                      decoration: const BoxDecoration(
                        color: Colors.redAccent,
                        shape: BoxShape.circle,
                      ),
                      constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                      child: Text(
                        _pendingCount > 99 ? '99+' : '$_pendingCount',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 9,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
