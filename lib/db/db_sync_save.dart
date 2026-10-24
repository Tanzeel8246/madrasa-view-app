import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../db_helper.dart';
import 'sync_savers/core_sync_saver.dart';
import 'sync_savers/students_sync_saver.dart';
import 'sync_savers/maktab_sync_saver.dart';
import 'sync_savers/finance_sync_saver.dart';
import 'sync_savers/attendance_sync_saver.dart';

extension DbSyncSave on DBHelper {
  Future<void> saveServerData(Map<String, dynamic> data) async {
    final Database db = await database;
    final Batch batch = db.batch();
    final List<List<int>> teacherIdRemaps = <List<int>>[];
    final List<List<int>> studentIdRemaps = <List<int>>[];
    final List<List<int>> maktabStudentIdRemaps = <List<int>>[];

    // 1. Students & Academics Domain
    await StudentsSyncSaver.save(db, batch, data, studentIdRemaps);

    // 2. Maktab & Masjid Education Domain
    await MaktabSyncSaver.save(db, batch, data, maktabStudentIdRemaps);

    // 3. Attendance & Staff Domain
    await AttendanceSyncSaver.save(db, batch, data, teacherIdRemaps);

    // 4. Finance & Accounting Domain
    await FinanceSyncSaver.save(db, batch, data);

    // 5. Core & Metadata Domain
    await CoreSyncSaver.save(db, batch, data);

    // Commit all batch operations with results inspection
    final List<Object?> results =
        await batch.commit(continueOnError: true, noResult: false);

    // Inspect individual batch execution results and log any DatabaseException
    for (final Object? res in results) {
      if (res is DatabaseException) {
        debugPrint('⚠️ [DbSyncSaver] Batch operation failed: $res');
      }
    }

    // Remap any teacher IDs if changed during server reconciliation
    for (final remap in teacherIdRemaps) {
      await markTeacherAsSynced(remap[0], remap[1]);
    }

    // Remap any Madrasa student IDs if changed during server reconciliation
    for (final remap in studentIdRemaps) {
      await markStudentAsSynced(remap[0], remap[1], isMaktab: false);
    }

    // Remap any Maktab student IDs if changed during server reconciliation
    for (final remap in maktabStudentIdRemaps) {
      await markStudentAsSynced(remap[0], remap[1], isMaktab: true);
    }
  }
}
