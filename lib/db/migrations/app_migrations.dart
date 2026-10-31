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
    DatabaseMigration(
      version: 48,
      description: 'Ensure offline_sync_queue and audit columns (client_uuid, sync_status)',
      up: (Database db) async {
        // 1. Ensure persistent offline queue table
        await db.execute('''
          CREATE TABLE IF NOT EXISTS offline_sync_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            action TEXT NOT NULL,
            client_uuid TEXT NOT NULL UNIQUE,
            tenant_id INTEGER NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
          )
        ''');
        await db.execute('CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON offline_sync_queue(status, tenant_id)');
        await db.execute('CREATE INDEX IF NOT EXISTS idx_sync_queue_uuid ON offline_sync_queue(client_uuid)');

        // 2. Add client_uuid, sync_status and conflict_flag to core business tables
        final coreTables = [
          'students',
          'attendance',
          'hifz_record',
          'nazira_record',
          'qaida_records',
          'finance',
          'masjid_finance',
          'maktab_students',
          'maktab_attendance',
          'classes',
          'teachers'
        ];

        for (final tbl in coreTables) {
          try {
            await db.execute("ALTER TABLE $tbl ADD COLUMN client_uuid TEXT");
          } catch (_) {}
          try {
            await db.execute("ALTER TABLE $tbl ADD COLUMN sync_status TEXT DEFAULT 'synced'");
          } catch (_) {}
          try {
            await db.execute("ALTER TABLE $tbl ADD COLUMN conflict_flag INTEGER DEFAULT 0");
          } catch (_) {}
        }
      },
    ),
  ];
}
