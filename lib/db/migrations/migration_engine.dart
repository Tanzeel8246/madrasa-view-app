import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';

class DatabaseMigration {
  final int version;
  final String description;
  final Future<void> Function(Database db) up;

  const DatabaseMigration({
    required this.version,
    required this.description,
    required this.up,
  });
}

class MigrationEngine {
  /// Ensures the schema_migrations table exists for tracking applied migrations
  static Future<void> ensureMigrationsTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS schema_migrations (
        version INTEGER PRIMARY KEY,
        description TEXT NOT NULL,
        applied_at TEXT NOT NULL
      )
    ''');
  }

  /// Returns a set of all migration versions that have already been executed
  static Future<Set<int>> getAppliedMigrationVersions(Database db) async {
    await ensureMigrationsTable(db);
    final rows = await db.query('schema_migrations', columns: ['version']);
    return rows
        .map((r) => int.tryParse(r['version']?.toString() ?? '') ?? 0)
        .where((v) => v > 0)
        .toSet();
  }

  /// Runs all registered migrations in strict sequential order
  static Future<void> runMigrations(
    Database db, {
    required List<DatabaseMigration> migrations,
  }) async {
    await ensureMigrationsTable(db);
    final appliedVersions = await getAppliedMigrationVersions(db);

    // Sort migrations by version ascending
    final sortedMigrations = List<DatabaseMigration>.from(migrations)
      ..sort((a, b) => a.version.compareTo(b.version));

    for (final migration in sortedMigrations) {
      if (!appliedVersions.contains(migration.version)) {
        debugPrint(
            '[MigrationEngine] Applying migration v${migration.version}: ${migration.description}');
        try {
          await migration.up(db);
          await db.insert(
            'schema_migrations',
            {
              'version': migration.version,
              'description': migration.description,
              'applied_at': DateTime.now().toIso8601String(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
          debugPrint(
              '[MigrationEngine] Successfully applied v${migration.version}');
        } catch (e) {
          debugPrint(
              '[MigrationEngine Error] Migration v${migration.version} failed: $e');
          rethrow;
        }
      }
    }
  }
}
