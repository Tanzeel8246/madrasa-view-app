import 'package:sqflite/sqflite.dart';
import 'migration_engine.dart';

class AppMigrations {
  static final List<DatabaseMigration> all = [
    DatabaseMigration(
      version: 38,
      description: 'Add residence_status column to students table',
      up: (Database db) async {
        try {
          await db.execute(
              "ALTER TABLE students ADD COLUMN residence_status TEXT DEFAULT ''");
        } catch (_) {}
      },
    ),
    DatabaseMigration(
      version: 42,
      description: 'Create smart_notification_reads table',
      up: (Database db) async {
        try {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS smart_notification_reads(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              alert_id TEXT NOT NULL,
              user_id INTEGER NOT NULL,
              created_at TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0,
              UNIQUE(alert_id, user_id)
            )
          ''');
        } catch (_) {}
      },
    ),
    DatabaseMigration(
      version: 43,
      description: 'Create sync_conflicts audit table',
      up: (Database db) async {
        try {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS sync_conflicts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              table_name TEXT NOT NULL,
              record_id TEXT,
              local_data TEXT,
              server_data TEXT,
              conflict_time TEXT NOT NULL,
              is_resolved INTEGER DEFAULT 0
            )
          ''');
        } catch (_) {}
      },
    ),
  ];
}
