import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../sync/sync_engine.dart';
import '../sync/sync_conflict_handler.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';

class SyncHealthModal extends StatefulWidget {
  const SyncHealthModal({super.key});

  static Future<void> show(BuildContext context) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => const SyncHealthModal(),
    );
  }

  @override
  State<SyncHealthModal> createState() => _SyncHealthModalState();
}

class _SyncHealthModalState extends State<SyncHealthModal> {
  bool _isLoading = true;
  bool _isManualSyncing = false;
  String? _lastSyncTime;
  int _pendingChangesCount = 0;
  int _conflictsCount = 0;
  String? _statusMessage;

  @override
  void initState() {
    super.initState();
    _loadSyncHealth();
  }

  Future<void> _loadSyncHealth() async {
    setState(() => _isLoading = true);
    try {
      final db = DBHelper();
      final lastTime = await SyncEngine.getLastSyncTime();
      final conflicts = await SyncConflictHandler.getUnresolvedConflicts(db);
      
      // Count unsynced items in primary tables
      int unsynced = 0;
      final localDb = await db.database;
      final tables = ['students', 'attendance', 'finance', 'notices', 'complaints'];
      for (final tbl in tables) {
        try {
          final res = await localDb.rawQuery('SELECT COUNT(*) as cnt FROM $tbl WHERE is_synced = 0');
          if (res.isNotEmpty) {
            unsynced += (res.first['cnt'] as num?)?.toInt() ?? 0;
          }
        } catch (_) {}
      }

      setState(() {
        _lastSyncTime = lastTime != null
            ? "${lastTime.hour.toString().padLeft(2, '0')}:${lastTime.minute.toString().padLeft(2, '0')} (${lastTime.day}/${lastTime.month}/${lastTime.year})"
            : "ابھی تک سنک نہیں ہوا";
        _pendingChangesCount = unsynced;
        _conflictsCount = conflicts.length;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
        _statusMessage = "معلومات حاصل کرنے میں خرابی: $e";
      });
    }
  }

  Future<void> _triggerImmediateSync() async {
    setState(() {
      _isManualSyncing = true;
      _statusMessage = null;
    });

    try {
      final db = DBHelper();
      await SyncEngine.syncSingleTable('students');
      await SyncEngine.syncSingleTable('attendance');
      await SyncEngine.syncSingleTable('finance');
      await SyncEngine.saveLastSyncTime();
      await _loadSyncHealth();
      setState(() {
        _statusMessage = "✅ سنکنگ کامیابی سے مکمل ہو گئی!";
      });
    } catch (e) {
      setState(() {
        _statusMessage = "❌ سنکنگ میں رکاوٹ: سرور سے رابطہ چیک کریں۔";
      });
    } finally {
      setState(() => _isManualSyncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    final primaryColor = isMasjid ? const Color(0xFF1B2A4A) : const Color(0xFF284D3D);

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: const BoxDecoration(
          color: Color(0xFFFBF9F4),
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.cloud_sync, color: primaryColor, size: 28),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    isUrdu ? "سنک ہیلتھ انسپکٹر و کلاؤڈ مانیٹر" : "Sync Health & Cloud Monitor",
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: primaryColor,
                    ),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
            const Divider(),
            if (_isLoading)
              const Center(child: Padding(padding: EdgeInsets.all(20), child: CircularProgressIndicator()))
            else ...[
              _buildMetricCard(
                icon: Icons.history,
                title: isUrdu ? "آخری کامیاب سنک" : "Last Successful Sync",
                value: _lastSyncTime ?? "نامعلوم",
                color: Colors.blueGrey,
              ),
              const SizedBox(height: 8),
              _buildMetricCard(
                icon: Icons.upload_file,
                title: isUrdu ? "زیر التواء آف لائن تبدیلیاں" : "Pending Offline Records",
                value: "$_pendingChangesCount ریکارڈز",
                color: _pendingChangesCount > 0 ? Colors.orange.shade800 : Colors.green,
              ),
              const SizedBox(height: 8),
              _buildMetricCard(
                icon: Icons.warning_amber_rounded,
                title: isUrdu ? "ڈیٹا کنفلکٹس (تنازعات)" : "Data Conflicts",
                value: _conflictsCount == 0 ? (isUrdu ? "کوئی نہیں (محفوظ)" : "None (Safe)") : "$_conflictsCount تنازعات",
                color: _conflictsCount == 0 ? Colors.green : Colors.red,
              ),
              if (_statusMessage != null) ...[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: _statusMessage!.startsWith('✅') ? Colors.green.shade50 : Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: _statusMessage!.startsWith('✅') ? Colors.green : Colors.red,
                    ),
                  ),
                  child: Text(
                    _statusMessage!,
                    style: TextStyle(
                      color: _statusMessage!.startsWith('✅') ? Colors.green.shade900 : Colors.red.shade900,
                      fontWeight: FontWeight.bold,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _isManualSyncing ? null : _triggerImmediateSync,
                icon: _isManualSyncing
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.sync, color: Colors.white),
                label: Text(
                  _isManualSyncing
                      ? (isUrdu ? "سنک ہو رہا ہے..." : "Syncing...")
                      : (isUrdu ? "ابھی فوری سنک کریں" : "Sync Now"),
                  style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: primaryColor,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
            const SizedBox(height: 10),
          ],
        ),
      ),
    );
  }

  Widget _buildMetricCard({
    required IconData icon,
    required String title,
    required String value,
    required Color color,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.black.withValues(alpha: 0.08)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 18,
            backgroundColor: color.withValues(alpha: 0.12),
            child: Icon(icon, color: color, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              title,
              style: const TextStyle(fontSize: 13, color: Colors.black87),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.bold,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}
