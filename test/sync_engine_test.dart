import 'package:flutter_test/flutter_test.dart';
import 'package:madrasa_app/sync/sync_models.dart';
import 'package:madrasa_app/sync/sync_table_names.dart';

void main() {
  group('Sync Engine Unit Tests', () {
    test('SyncProgress correctly computes percentage and completion state', () {
      const progressZero = SyncProgress(
        currentTable: 'students',
        completed: 0,
        total: 10,
      );
      expect(progressZero.percent, 0.0);
      expect(progressZero.isDone, false);

      const progressHalf = SyncProgress(
        currentTable: 'teachers',
        completed: 5,
        total: 10,
      );
      expect(progressHalf.percent, 0.5);
      expect(progressHalf.isDone, false);

      const progressComplete = SyncProgress(
        currentTable: 'classes',
        completed: 10,
        total: 10,
      );
      expect(progressComplete.percent, 1.0);
      expect(progressComplete.isDone, true);

      // Boundary: total <= 0
      const progressEmpty = SyncProgress(
        currentTable: 'empty',
        completed: 0,
        total: 0,
      );
      expect(progressEmpty.percent, 0.0);
      expect(progressEmpty.isDone, true);
    });

    test('SyncResult calculates totals and success status', () {
      final now = DateTime.now();
      final resultSuccess = SyncResult(
        uploadedPerTable: {'students': 5, 'teachers': 2},
        downloadedPerTable: {'attendance': 12, 'finance': 3},
        errors: const [],
        duration: const Duration(seconds: 4),
        timestamp: now,
      );

      expect(resultSuccess.totalUploaded, 7);
      expect(resultSuccess.totalDownloaded, 15);
      expect(resultSuccess.isSuccess, true);

      final resultWithError = SyncResult(
        uploadedPerTable: {'students': 1},
        downloadedPerTable: {},
        errors: const ['Network timeout on table finance'],
        duration: const Duration(seconds: 10),
        timestamp: now,
      );

      expect(resultWithError.totalUploaded, 1);
      expect(resultWithError.totalDownloaded, 0);
      expect(resultWithError.isSuccess, false);
      expect(resultWithError.errors.length, 1);
    });

    test('SyncTableNames resolves Urdu display labels accurately', () {
      expect(SyncTableNames.getUrduTableName('students'), 'طلبہ ریکارڈ');
      expect(SyncTableNames.getUrduTableName('teachers'), 'اساتذہ ریکارڈ');
      expect(SyncTableNames.getUrduTableName('attendance'), 'طلبہ حاضری');
      expect(SyncTableNames.getUrduTableName('finance'), 'مدرسہ فنانس (آمدن و خرچ)');
      expect(SyncTableNames.getUrduTableName('masjid_finance'), 'مسجد فنانس (آمدن و خرچ)');
      expect(SyncTableNames.getUrduTableName('unknown_custom_table'), 'unknown_custom_table');
    });

    test('SyncResult generateSummaryUrdu contains expected header and counts', () {
      final summary = SyncResult(
        uploadedPerTable: {'students': 4},
        downloadedPerTable: {'teachers': 1},
        errors: const [],
        duration: const Duration(seconds: 2),
        timestamp: DateTime.now(),
      ).generateSummaryUrdu();

      expect(summary.contains('سنک رپورٹ خلاصہ'), true);
      expect(summary.contains('طلبہ ریکارڈ: 4'), true);
      expect(summary.contains('اساتذہ ریکارڈ: 1'), true);
    });
  });
}
