import 'package:sqflite/sqflite.dart';
import '../../db_helper.dart';

class SettingsRepository {
  final DBHelper _dbHelper;
  SettingsRepository([DBHelper? dbHelper]) : _dbHelper = dbHelper ?? DBHelper();

  Future<Database> get _db async => await _dbHelper.database;

  Future<Map<String, dynamic>> getSettings() async {
    return await _dbHelper.getSettings();
  }

  Future<int> saveSettings(Map<String, dynamic> settingsData) async {
    final db = await _db;
    return await db.update('settings', settingsData, where: 'id = 1');
  }

  Future<String> getHijriYear() async {
    final s = await getSettings();
    return s['current_hijri_year']?.toString() ?? '1447';
  }
}
