import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  test('Acceptance Verification: Raw file copy extracts uncorrupted SQLite database with exact records', () async {
    // 1. عارضی پرائیویٹ ایپ ڈائرکٹری بنائیں
    final tempAppDir = Directory(p.join(Directory.systemTemp.path, 'madrasa_test_app_dir'));
    if (await tempAppDir.exists()) await tempAppDir.delete(recursive: true);
    await tempAppDir.create(recursive: true);

    final databasesDir = Directory(p.join(tempAppDir.path, 'databases'));
    await databasesDir.create(recursive: true);

    final dbPath = p.join(databasesDir.path, 'madrasa.db');

    // 2. ڈیٹا بیس بنائیں اور اس میں فرضی لوکل ریکارڈز درج کریں (حفظ، حاضری، فنانس)
    final db = await databaseFactory.openDatabase(dbPath);
    await db.execute('''
      CREATE TABLE hifz_record (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER,
        date TEXT,
        sabaq_para TEXT,
        sabaq_lines TEXT,
        sabqi_para TEXT,
        manzil_para TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER,
        date TEXT,
        status TEXT
      )
    ''');
    await db.execute('''
      CREATE TABLE finance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT,
        type TEXT,
        amount REAL,
        description TEXT
      )
    ''');

    // ریکارڈز داخل کریں
    await db.insert('hifz_record', {
      'student_id': 101,
      'date': '2026-11-15',
      'sabaq_para': 'پارہ 5',
      'sabaq_lines': '16 سطور',
      'sabqi_para': 'پارہ 4',
      'manzil_para': 'پارہ 1-3'
    });
    await db.insert('attendance', {
      'student_id': 101,
      'date': '2026-11-15',
      'status': 'Present'
    });
    await db.insert('finance', {
      'date': '2026-11-15',
      'type': 'Income',
      'amount': 5000.0,
      'description': 'عطیہ برائے تعلیم'
    });

    await db.close(); // بند کریں تاکہ فائل ڈسک پر فلیش ہو

    // 3. ریکوری کاپی کا ٹیسٹ چلائیں (جیسے ریکوری اسکرین کرتی ہے)
    final tempDownloadRecovery = Directory(p.join(Directory.systemTemp.path, 'MadrasaRecovery_Test'));
    if (await tempDownloadRecovery.exists()) await tempDownloadRecovery.delete(recursive: true);
    await tempDownloadRecovery.create(recursive: true);

    // اسکین اور بائٹ کاپی
    final entities = tempAppDir.listSync(recursive: true);
    int copiedCount = 0;
    for (final e in entities) {
      if (e is File && p.extension(e.path) == '.db') {
        final bytes = await e.readAsBytes();
        final dest = File(p.join(tempDownloadRecovery.path, p.basename(e.path)));
        await dest.writeAsBytes(bytes, flush: true);
        copiedCount++;
      }
    }

    expect(copiedCount, 1);

    // 4. اب ریکور شدہ فائل کو کھول کر جانچیں کہ کیا اس کے اندر وہی ریکارڈز صحیح سلامت موجود ہیں!
    final recoveredDbFile = File(p.join(tempDownloadRecovery.path, 'madrasa.db'));
    expect(await recoveredDbFile.exists(), true);

    final recoveredDb = await databaseFactory.openDatabase(recoveredDbFile.path);

    final hifzRows = await recoveredDb.query('hifz_record');
    expect(hifzRows.length, 1);
    expect(hifzRows.first['sabaq_para'], 'پارہ 5');
    expect(hifzRows.first['sabaq_lines'], '16 سطور');

    final attRows = await recoveredDb.query('attendance');
    expect(attRows.length, 1);
    expect(attRows.first['status'], 'Present');

    final finRows = await recoveredDb.query('finance');
    expect(finRows.length, 1);
    expect(finRows.first['amount'], 5000.0);
    expect(finRows.first['description'], 'عطیہ برائے تعلیم');

    await recoveredDb.close();

    // کلین اپ
    await tempAppDir.delete(recursive: true);
    await tempDownloadRecovery.delete(recursive: true);

    print('✅ [TEST PASSED] Raw database copy recovered exact records without any data loss or corruption!');
  });
}
