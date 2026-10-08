import 'dart:io';
import 'package:flutter/foundation.dart' show debugPrint, kIsWeb;
import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'db/repositories/repositories.dart';
import 'utils/globals.dart';
import 'utils/system_type_global.dart';

import 'db/migrations/migration_engine.dart';
import 'db/migrations/app_migrations.dart';

export 'db/db_sync_read.dart';
export 'db/db_sync_teachers.dart';
export 'db/db_sync_students.dart';
export 'db/db_sync_save.dart';
export 'db/repositories/repositories.dart';
export 'db/migrations/migration_engine.dart';
export 'db/migrations/app_migrations.dart';
export 'db/extensions/db_academics_ext.dart';
export 'db/extensions/db_operations_ext.dart';

class DBHelper {
  static Database? _database;
  static bool _phase34Created = false;
  static bool _academicSystemCreated = false;

  /// ڈیسک ٹاپ (ونڈوز/لینکس/میک) پر SQLite FFI کی خودکار انیشلائزیشن
  static void ensureFfiInitialized() {
    if (!kIsWeb && (Platform.isWindows || Platform.isLinux || Platform.isMacOS)) {
      sqfliteFfiInit();
      databaseFactory = databaseFactoryFfi;
    }
  }

  // Modular Repositories Gateway
  StudentRepository get students => StudentRepository(this);
  AttendanceRepository get attendance => AttendanceRepository(this);
  FinanceRepository get financeRepo => FinanceRepository(this);
  AcademicRepository get academics => AcademicRepository(this);
  MasjidRepository get masjid => MasjidRepository(this);
  SettingsRepository get settingsRepo => SettingsRepository(this);

  Future<Database> get database async {
    if (_database != null) {
      if (!_phase34Created) {
        await _createPhase34Tables(_database!);
        _phase34Created = true;
      }
      if (!_academicSystemCreated) {
        await _createAcademicSystemTables(_database!);
        _academicSystemCreated = true;
      }
      return _database!;
    }
    _database = await initDB();
    if (!_phase34Created) {
      await _createPhase34Tables(_database!);
      _phase34Created = true;
    }
    if (!_academicSystemCreated) {
      await _createAcademicSystemTables(_database!);
      _academicSystemCreated = true;
    }
    return _database!;
  }

  /// ملٹی ٹیننٹ لاگ آؤٹ پر لوکل ڈیٹا بیس مکمل صاف اور ری سیٹ کریں
  Future<void> clearAndResetDatabase() async {
    try {
      ensureFfiInitialized();
      if (_database != null) {
        await _database!.close();
        _database = null;
      }
      _phase34Created = false;
      String path = join(await getDatabasesPath(), 'madrasa.db');
      await deleteDatabase(path);
      debugPrint("Local SQLite database completely reset on logout.");
    } catch (e) {
      debugPrint("Error resetting local database: $e");
    }
  }

  /// تمام ہائی ٹریفک ٹیبلز پر کمپاؤنڈ انڈیکسز کی خودکار تنصیب تاکہ فل ٹیبل اسکین ختم ہو اور اسپیڈ 10 گنا تیز ہو
  static Future<void> _ensureIndexes(Database db) async {
    final indexQueries = [
      // Students
      "CREATE INDEX IF NOT EXISTS idx_students_status_class ON students(status, class, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_students_tenant ON students(tenant_id, is_deleted)",
      "CREATE INDEX IF NOT EXISTS idx_students_roll ON students(roll_number, admission_number)",
      "CREATE INDEX IF NOT EXISTS idx_students_sync ON students(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_students_residence ON students(residence_status)",

      // Maktab Students
      "CREATE INDEX IF NOT EXISTS idx_maktab_std_status_class ON maktab_students(status, class, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_std_tenant ON maktab_students(tenant_id, is_deleted)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_std_roll ON maktab_students(roll_number, admission_number)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_std_sync ON maktab_students(is_synced)",

      // Attendance
      "CREATE INDEX IF NOT EXISTS idx_att_date_student ON attendance(date, student_id, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_att_student_date ON attendance(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_att_sync ON attendance(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_att_tenant ON attendance(tenant_id)",

      // Maktab Attendance
      "CREATE INDEX IF NOT EXISTS idx_maktab_att_date_student ON maktab_attendance(date, student_id, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_att_student_date ON maktab_attendance(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_att_sync ON maktab_attendance(is_synced)",

      // Hifz & Nazira Records
      "CREATE INDEX IF NOT EXISTS idx_hifz_student_date ON hifz_record(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_hifz_date ON hifz_record(date, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_hifz_sync ON hifz_record(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_hifz_std_date ON maktab_hifz_record(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_hifz_sync ON maktab_hifz_record(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_nazira_student_date ON nazira_record(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_nazira_sync ON nazira_record(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_nazira_std_date ON maktab_nazira_record(student_id, date)",

      // Finance & Masjid Finance
      "CREATE INDEX IF NOT EXISTS idx_finance_date_system ON finance(date, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_finance_head ON finance(head_id)",
      "CREATE INDEX IF NOT EXISTS idx_finance_sync ON finance(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_masjid_fin_date ON masjid_finance(date, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_masjid_fin_sync ON masjid_finance(is_synced)",

      // Teachers & Staff Attendance
      "CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers(status, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_teachers_status ON maktab_teachers(status, system_type)",
      "CREATE INDEX IF NOT EXISTS idx_teachers_sync ON teachers(is_synced)",
      "CREATE INDEX IF NOT EXISTS idx_att_teachers_date ON attendance_teachers(date, teacher_id)",
      "CREATE INDEX IF NOT EXISTS idx_teacher_period_att ON teacher_period_attendance(date, teacher_id, period_no)",

      // Classes & Academics
      "CREATE INDEX IF NOT EXISTS idx_classes_system ON classes(system_type, is_deleted)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_classes_system ON maktab_classes(system_type, is_deleted)",
      "CREATE INDEX IF NOT EXISTS idx_acad_rep_std_date ON academic_reports(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_maktab_acad_rep_std_date ON maktab_academic_reports(student_id, date)",
      "CREATE INDEX IF NOT EXISTS idx_exam_results_exam_std ON exam_results(exam_id, student_id)",

      // Hostel & Library & Inventory
      "CREATE INDEX IF NOT EXISTS idx_hostel_alloc_std ON hostel_allocations(student_id, status)",
      "CREATE INDEX IF NOT EXISTS idx_lib_issues_std ON lib_issues(student_id, status)",
      "CREATE INDEX IF NOT EXISTS idx_inv_sales_date ON inventory_sales(date)",
    ];

    for (final q in indexQueries) {
      try {
        await db.execute(q);
      } catch (_) {}
    }
  }

  Future<Database> initDB() async {
    ensureFfiInitialized();
    String path = join(await getDatabasesPath(), 'madrasa.db');
    return await openDatabase(
      path,
      version: 47,
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 47) {
          await _createAcademicSystemTables(db);
        }
        if (oldVersion < 46) {
          await _ensureIndexes(db);
        }
        if (oldVersion < 45) {
          await _addColumnIfNotExists(
              db, 'students', 'hifz_section', "TEXT DEFAULT 'regular'");
          await _addColumnIfNotExists(
              db, 'maktab_students', 'hifz_section', "TEXT DEFAULT 'regular'");
        }
        if (oldVersion < 44) {
          await _addColumnIfNotExists(db, 'finance', 'bill_image', 'TEXT');
          await _addColumnIfNotExists(
              db, 'masjid_finance', 'bill_image', 'TEXT');
        }
        if (oldVersion < 43) {
          await _createPhase34Tables(db);
        }
        if (oldVersion < 41) {
          final alumniCols = {
            'alumni_category': "TEXT DEFAULT 'completed'",
            'wifaq_exam': "INTEGER DEFAULT 0",
            'wifaq_marks': "TEXT",
            'mehrab_recitation': "TEXT",
          };
          for (final entry in alumniCols.entries) {
            await _addColumnIfNotExists(db, 'students', entry.key, entry.value);
            await _addColumnIfNotExists(
                db, 'maktab_students', entry.key, entry.value);
          }
        }
        if (oldVersion < 2) {
          try {
            await db.execute("ALTER TABLE students ADD COLUMN password TEXT");
          } catch (_) {
            // Ignore if column already exists.
          }
        }
        if (oldVersion < 3) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS exams(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              exam_name TEXT,
              exam_date TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
            )
          ''');

          await db.execute('''
            CREATE TABLE IF NOT EXISTS notices(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              title TEXT,
              message TEXT,
              date TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
            )
          ''');

          await db.execute('''
            CREATE TABLE IF NOT EXISTS complaints(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER,
              complaint TEXT,
              date TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
            )
          ''');
        }

        if (oldVersion < 4) {
          try {
            await db.execute("ALTER TABLE finance ADD COLUMN head_id INTEGER");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE finance ADD COLUMN manual_receipt_no TEXT");
          } catch (_) {}
          try {
            await db
                .execute("ALTER TABLE finance ADD COLUMN server_id INTEGER");
          } catch (_) {}
          try {
            await db.execute("ALTER TABLE finance ADD COLUMN donor_name TEXT");
          } catch (_) {}
          try {
            await db
                .execute("ALTER TABLE finance ADD COLUMN donor_address TEXT");
          } catch (_) {}
        }
        if (oldVersion < 17) {
          try {
            await db.execute(
                "ALTER TABLE finance ADD COLUMN computer_receipt_no INTEGER");
          } catch (_) {}
        }
        if (oldVersion < 5) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS parent_complaints(
              id INTEGER PRIMARY KEY,
              student_id INTEGER,
              date TEXT,
              category TEXT,
              description TEXT,
              status TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 1
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS leave_applications(
              id INTEGER PRIMARY KEY,
              student_id INTEGER,
              date_from TEXT,
              date_to TEXT,
              reason TEXT,
              status TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 1
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS fee_submissions(
              id INTEGER PRIMARY KEY,
              student_id INTEGER,
              amount REAL,
              submission_date TEXT,
              screenshot TEXT,
              status TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 1
            )
          ''');
        }
        if (oldVersion < 6) {
          try {
            await db.execute(
                "ALTER TABLE students ADD COLUMN status TEXT DEFAULT 'Active'");
            await db
                .execute("ALTER TABLE students ADD COLUMN admission_date TEXT");
            await db.execute(
                "ALTER TABLE students ADD COLUMN graduation_date TEXT");
            await db.execute("ALTER TABLE students ADD COLUMN duration TEXT");
            await db.execute("ALTER TABLE students ADD COLUMN photo TEXT");
          } catch (_) {}
        }
        if (oldVersion < 7) {
          try {
            await db
                .execute("ALTER TABLE students ADD COLUMN leaving_reason TEXT");
            await db.execute(
                "ALTER TABLE students ADD COLUMN serial_no INTEGER DEFAULT 0");
            await db.execute(
                "ALTER TABLE students ADD COLUMN hijri_year INTEGER DEFAULT 1447");
          } catch (_) {}
        }
        if (oldVersion < 9) {
          try {
            await db.execute('''
              CREATE TABLE IF NOT EXISTS settings(
                id INTEGER PRIMARY KEY,
                madrasa_name TEXT,
                phone TEXT,
                current_hijri_year TEXT,
                week_start_day INTEGER DEFAULT 6,
                ai_provider TEXT,
                gemini_api_key TEXT,
                groq_api_key TEXT,
                whatsapp_api_key TEXT,
                sms_api_key TEXT,
                auto_notify_day INTEGER,
                auto_notify_time TEXT
              )
            ''');
          } catch (_) {}
          try {
            await db
                .execute('ALTER TABLE settings ADD COLUMN ai_provider TEXT');
          } catch (_) {}
          try {
            await db
                .execute('ALTER TABLE settings ADD COLUMN gemini_api_key TEXT');
          } catch (_) {}
          try {
            await db
                .execute('ALTER TABLE settings ADD COLUMN groq_api_key TEXT');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE settings ADD COLUMN whatsapp_api_key TEXT');
          } catch (_) {}
          try {
            await db
                .execute('ALTER TABLE settings ADD COLUMN sms_api_key TEXT');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE settings ADD COLUMN auto_notify_day INTEGER');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE settings ADD COLUMN auto_notify_time TEXT');
          } catch (_) {}
        }
        if (oldVersion < 10) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS classes(
              id INTEGER PRIMARY KEY,
              name TEXT,
              section TEXT,
              status TEXT DEFAULT 'Active',
              class_type TEXT DEFAULT 'Regular',
              main_teacher_ids TEXT,
              p1_name TEXT DEFAULT 'Period 1',
              p2_name TEXT DEFAULT 'Period 2',
              p3_name TEXT DEFAULT 'Period 3',
              p4_name TEXT DEFAULT 'Period 4',
              p5_name TEXT DEFAULT 'Period 5',
              p6_name TEXT DEFAULT 'Period 6',
              subjects_list TEXT,
              is_synced INTEGER DEFAULT 1,
              system_type TEXT DEFAULT 'Madrasa'
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS exam_results(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              exam_id INTEGER,
              student_id INTEGER,
              subject TEXT DEFAULT 'Quran',
              marks_obtained REAL,
              total_marks REAL,
              grade TEXT,
              status TEXT,
              late_minutes INTEGER DEFAULT 0,
              remarks TEXT,
              pukhtagi INTEGER DEFAULT 0,
              tajweed INTEGER DEFAULT 0,
              lahja INTEGER DEFAULT 0,
              masail INTEGER DEFAULT 0,
              miqdar_khwandgi TEXT DEFAULT '',
              p1 INTEGER DEFAULT 0,
              p2 INTEGER DEFAULT 0,
              p3 INTEGER DEFAULT 0,
              p4 INTEGER DEFAULT 0,
              p5 INTEGER DEFAULT 0,
              p6 INTEGER DEFAULT 0,
              subjects_marks TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 1
            )
          ''');
        }
        if (oldVersion < 12) {
          try {
            await db.execute(
                "ALTER TABLE leave_applications ADD COLUMN application_photo TEXT");
          } catch (_) {}
        }
        if (oldVersion < 13) {
          try {
            await db.execute(
                "ALTER TABLE fee_submissions ADD COLUMN head_id INTEGER");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE fee_submissions ADD COLUMN description TEXT");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE fee_submissions ADD COLUMN finance_id INTEGER");
          } catch (_) {}
        }
        if (oldVersion < 14) {
          try {
            await db.execute(
                "ALTER TABLE exam_results ADD COLUMN subject TEXT DEFAULT 'Quran'");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE exam_results ADD COLUMN pukhtagi INTEGER DEFAULT 0");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE exam_results ADD COLUMN tajweed INTEGER DEFAULT 0");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE exam_results ADD COLUMN lahja INTEGER DEFAULT 0");
          } catch (_) {}
        }
        if (oldVersion < 16) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS academic_reports(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER,
              date TEXT,
              class_name TEXT,
              subject TEXT,
              lesson_details TEXT,
              grade TEXT,
              remarks TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
        }

        if (oldVersion < 18) {
          final tables = [
            'students',
            'teachers',
            'finance',
            'hifz_record',
            'nazira_record',
            'attendance',
            'academic_reports',
            'fee_submissions',
            'leave_applications',
            'parent_complaints',
            'complaints',
            'notices',
            'exams',
            'exam_results'
          ];
          for (var table in tables) {
            try {
              await db.execute(
                  'ALTER TABLE $table ADD COLUMN is_deleted INTEGER DEFAULT 0');
            } catch (_) {}
          }
        }

        if (oldVersion < 19) {
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN masail INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN miqdar_khwandgi TEXT DEFAULT ""');
          } catch (_) {}
        }
        if (oldVersion < 20) {
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p1 INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p2 INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p3 INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p4 INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p5 INTEGER DEFAULT 0');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE exam_results ADD COLUMN p6 INTEGER DEFAULT 0');
          } catch (_) {}
        }
        if (oldVersion < 21) {
          try {
            await db.execute('ALTER TABLE classes ADD COLUMN section TEXT');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN main_teacher_ids TEXT');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN class_type TEXT DEFAULT "Regular"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p1_name TEXT DEFAULT "Period 1"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p2_name TEXT DEFAULT "Period 2"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p3_name TEXT DEFAULT "Period 3"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p4_name TEXT DEFAULT "Period 4"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p5_name TEXT DEFAULT "Period 5"');
          } catch (_) {}
          try {
            await db.execute(
                'ALTER TABLE classes ADD COLUMN p6_name TEXT DEFAULT "Period 6"');
          } catch (_) {}
        }

        if (oldVersion < 23) {
          final allTables = [
            'students',
            'teachers',
            'finance',
            'hifz_record',
            'nazira_record',
            'attendance',
            'academic_reports',
            'fee_submissions',
            'leave_applications',
            'parent_complaints',
            'complaints',
            'notices',
            'exams',
            'exam_results',
            'classes'
          ];
          for (var table in allTables) {
            try {
              await db.execute(
                  'ALTER TABLE $table ADD COLUMN is_deleted INTEGER DEFAULT 0');
            } catch (_) {}
          }
        }
        if (oldVersion < 24) {
          try {
            await db.execute(
                'ALTER TABLE finance ADD COLUMN user_id INTEGER DEFAULT 1');
          } catch (_) {}
          try {
            await db.execute('ALTER TABLE finance ADD COLUMN user_name TEXT');
          } catch (_) {}
        }

        if (oldVersion < 25) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS acc_payroll(
              id INTEGER PRIMARY KEY,
              teacher_id INTEGER,
              month_year TEXT,
              basic_salary REAL DEFAULT 0,
              allowances REAL DEFAULT 0,
              deductions REAL DEFAULT 0,
              net_salary REAL DEFAULT 0,
              status TEXT DEFAULT 'Unpaid',
              payment_date TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
          await db.execute('''
            CREATE TABLE IF NOT EXISTS acc_invoices(
              id INTEGER PRIMARY KEY,
              student_id INTEGER,
              invoice_no TEXT,
              month_year TEXT,
              amount REAL,
              status TEXT DEFAULT 'Unpaid',
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
        }

        if (oldVersion < 26) {
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN house_rent REAL DEFAULT 0");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN medical_allowance REAL DEFAULT 0");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN travel_allowance REAL DEFAULT 0");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN daily_deduction_rate REAL DEFAULT 0");
          } catch (_) {}
          await db.execute('''
            CREATE TABLE IF NOT EXISTS attendance_teachers(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              teacher_id INTEGER,
              date TEXT,
              status TEXT,
              late_minutes INTEGER DEFAULT 0,
              remarks TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
            )
          ''');
        }
        if (oldVersion < 28) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS nazira_record(
              id INTEGER PRIMARY KEY,
              student_id INTEGER,
              date TEXT,
              morning_sabaq TEXT,
              morning_status TEXT,
              evening_sabaq TEXT,
              evening_status TEXT,
              system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
            )
          ''');
        }
        await forceMigrate(db);

        if (oldVersion < 29) {
          try {
            await db
                .execute("ALTER TABLE hifz_record ADD COLUMN sabaq_time TEXT");
            await db
                .execute("ALTER TABLE hifz_record ADD COLUMN sabqi_time TEXT");
            await db
                .execute("ALTER TABLE hifz_record ADD COLUMN manzil_time TEXT");
          } catch (_) {}
        }
        if (oldVersion < 30) {
          try {
            await db.execute("ALTER TABLE teachers ADD COLUMN photo TEXT");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN assigned_classes TEXT");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN assigned_periods TEXT");
          } catch (_) {}
        }
        if (oldVersion < 31) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS departments(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT,
              description TEXT,
              status TEXT DEFAULT 'Active',
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0
            )
          ''');
        }
        if (oldVersion < 32) {
          try {
            await db.execute(
                "ALTER TABLE exams ADD COLUMN total_marks REAL DEFAULT 100");
          } catch (_) {}
          try {
            await db.execute(
                "ALTER TABLE exams ADD COLUMN passing_marks REAL DEFAULT 33");
          } catch (_) {}
        }
        if (oldVersion < 33) {
          await _createAccountingTables(db);
        }
        if (oldVersion < 34) {
          try {
            await db.execute(
                "ALTER TABLE teachers ADD COLUMN status TEXT DEFAULT 'Active'");
          } catch (_) {}
        }
        if (oldVersion < 35) {
          try {
            await db.execute(
                "ALTER TABLE acc_chart_of_accounts ADD COLUMN parent_id INTEGER");
          } catch (_) {}
        }
        if (oldVersion < 36) {
          // مسجد فنانس کی الگ ٹیبل بنائیں
          try {
            await db.execute('''
              CREATE TABLE IF NOT EXISTS masjid_finance(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                server_id INTEGER,
                date TEXT,
                type TEXT,
                amount REAL,
                description TEXT,
                head_id INTEGER,
                head_name TEXT,
                manual_receipt_no TEXT,
                computer_receipt_no INTEGER,
                donor_name TEXT,
                donor_address TEXT,
                spender_name TEXT,
                signature_image TEXT,
                receipt_no TEXT,
                user_id INTEGER DEFAULT 1,
                user_name TEXT,
                system_type TEXT DEFAULT 'Masjid',
                is_deleted INTEGER DEFAULT 0,
                is_synced INTEGER DEFAULT 0
              )
            ''');
          } catch (_) {}
          // finance ٹیبل میں system_type کالم شامل کریں
          try {
            await db.execute(
                "ALTER TABLE finance ADD COLUMN system_type TEXT DEFAULT 'Madrasa'");
            await db.execute(
                "UPDATE finance SET system_type = 'Madrasa' WHERE system_type IS NULL");
          } catch (_) {}
          // مسجد فنانس ہیڈز کی ٹیبل
          try {
            await db.execute('''
              CREATE TABLE IF NOT EXISTS masjid_acc_chart_of_accounts(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT,
                name TEXT,
                type TEXT,
                system_type TEXT DEFAULT 'Masjid',
                is_deleted INTEGER DEFAULT 0,
                is_synced INTEGER DEFAULT 0
              )
            ''');
          } catch (_) {}
        }
        if (oldVersion < 37) {
          try {
            await db.execute('''
                CREATE TABLE IF NOT EXISTS masjid_assets(
                  id INTEGER PRIMARY KEY,
                  name TEXT,
                  category TEXT,
                  quantity INTEGER,
                  condition TEXT,
                  condition_status TEXT,
                  asset_type TEXT,
                  donor_name TEXT,
                  donor_father TEXT,
                  donor_address TEXT,
                  donor_phone TEXT,
                  system_type TEXT DEFAULT 'Masjid',
                  is_synced INTEGER DEFAULT 0,
                  is_deleted INTEGER DEFAULT 0
                )
              ''');
          } catch (_) {}
        }

        if (oldVersion < 38) {
          try {
            await db.execute(
                "ALTER TABLE students ADD COLUMN residence_status TEXT DEFAULT ''");
          } catch (_) {}
        }
        if (oldVersion < 42) {
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
        }
      },
      onOpen: (db) async {
        await forceMigrate(db);
        await _ensureIndexes(db);
        await MigrationEngine.runMigrations(db, migrations: AppMigrations.all);
      },
      onCreate: (db, version) async {
        await forceMigrate(db);
        await _createAcademicSystemTables(db);
        await db.execute('''
          CREATE TABLE IF NOT EXISTS students(
            id INTEGER PRIMARY KEY,
            name TEXT,
            father_name TEXT,
            roll_number TEXT,
            class_name TEXT,
            contact_number TEXT,
            address TEXT,
            password TEXT,
            status TEXT DEFAULT 'Active',
            admission_date TEXT,
            graduation_date TEXT,
            duration TEXT,
            photo TEXT,
            leaving_reason TEXT,
            serial_no INTEGER DEFAULT 0,
            hijri_year INTEGER DEFAULT 1447,
            system_type TEXT DEFAULT 'Madrasa',
            residence_status TEXT DEFAULT '',
            alumni_category TEXT DEFAULT 'completed',
            wifaq_exam INTEGER DEFAULT 0,
            wifaq_marks TEXT,
            mehrab_recitation TEXT,
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS teachers(
            id INTEGER PRIMARY KEY,
            name TEXT,
            father_name TEXT,
            phone TEXT,
            cnic TEXT,
            qualification TEXT,
            salary TEXT,
            house_rent REAL DEFAULT 0,
            medical_allowance REAL DEFAULT 0,
            travel_allowance REAL DEFAULT 0,
            daily_deduction_rate REAL DEFAULT 0,
            joining_date TEXT,
            status TEXT,
            status_reason TEXT,
            status_change_date TEXT,
            address TEXT,
            photo TEXT,
            assigned_classes TEXT,
            assigned_periods TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS departments(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            description TEXT,
            status TEXT DEFAULT 'Active',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS academic_reports(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            date TEXT,
            class_name TEXT,
            subject TEXT,
            lesson_details TEXT,
            grade TEXT,
            remarks TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS qaida_records(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            date TEXT,
            sabaq_1 TEXT, qty_1 TEXT, grade_1 TEXT,
            sabaq_2 TEXT, qty_2 TEXT, grade_2 TEXT,
            sabaq_3 TEXT, qty_3 TEXT, grade_3 TEXT,
            sabaq_4 TEXT, qty_4 TEXT, grade_4 TEXT,
            lesson_type TEXT DEFAULT 'نورانی قاعدہ',
            hearing_time TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS hifz_record(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER,
            date TEXT,
            student_id TEXT,
            sabaq_para TEXT,
            sabaq_lines TEXT,
            sabaq_grade TEXT,
            sabqi_para TEXT,
            sabqi_qty TEXT,
            sabqi_grade TEXT,
            sabqi_listener TEXT,
            manzil_total_qty TEXT,
            manzil_details TEXT,
            manzil_grade TEXT,
            manzil_listener TEXT,
            sabaq_time TEXT,
            sabqi_time TEXT,
            manzil_time TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS nazira_record(
            id INTEGER PRIMARY KEY,
            student_id INTEGER,
            date TEXT,
            morning_sabaq TEXT,
            morning_qty TEXT,
            morning_time TEXT,
            morning_status TEXT,
            evening_sabaq TEXT,
            evening_qty TEXT,
            evening_time TEXT,
            evening_status TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS husn_saut_record(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER,
            student_id TEXT,
            date TEXT,
            manzil_sabaq TEXT,
            manzil_listener TEXT,
            manzil_grade TEXT,
            mashq_1_sabaq TEXT,
            mashq_1_time TEXT,
            mashq_2_sabaq TEXT,
            mashq_2_time TEXT,
            mashq_3_sabaq TEXT,
            mashq_3_time TEXT,
            mashq_4_sabaq TEXT,
            mashq_4_time TEXT,
            tilawat_qty TEXT,
            tilawat_parahs TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS attendance(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT,
            shift TEXT,
            student_id TEXT,
            status TEXT,
            delay_minutes TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS finance(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER,
            date TEXT,
            type TEXT,
            amount REAL,
            description TEXT,
            head_id INTEGER,
            head_name TEXT,
            manual_receipt_no TEXT,
            receipt_no TEXT,
            donor_name TEXT,
            donor_address TEXT,
            spender_name TEXT,
            signature_image TEXT,
            computer_receipt_no INTEGER,
            user_id INTEGER DEFAULT 1,
            user_name TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS heads(
            id INTEGER PRIMARY KEY,
            name TEXT,
            type TEXT,
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS exams(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exam_name TEXT,
            exam_date TEXT,
            total_marks REAL DEFAULT 100,
            passing_marks REAL DEFAULT 33,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS notices(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            message TEXT,
            date TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS complaints(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            complaint TEXT,
            date TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

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

        await db.execute('''
          CREATE TABLE IF NOT EXISTS parent_complaints(
            id INTEGER PRIMARY KEY,
            student_id INTEGER,
            date TEXT,
            category TEXT,
            description TEXT,
            status TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS leave_applications(
            id INTEGER PRIMARY KEY,
            student_id INTEGER,
            date_from TEXT,
            date_to TEXT,
            reason TEXT,
            status TEXT,
            application_photo TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS fee_submissions(
            id INTEGER PRIMARY KEY,
            student_id INTEGER,
            amount REAL,
            submission_date TEXT,
            screenshot TEXT,
            status TEXT,
            head_id INTEGER,
            description TEXT,
            finance_id INTEGER,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS settings(
            id INTEGER PRIMARY KEY,
            madrasa_name TEXT,
            phone TEXT,
            current_hijri_year TEXT,
            week_start_day INTEGER DEFAULT 6,
            ai_provider TEXT,
            gemini_api_key TEXT,
            groq_api_key TEXT,
            whatsapp_api_key TEXT,
            sms_api_key TEXT,
            auto_notify_day INTEGER,
            auto_notify_time TEXT
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS classes(
            id INTEGER PRIMARY KEY,
            name TEXT,
            section TEXT,
            status TEXT DEFAULT 'Active',
            class_type TEXT DEFAULT 'Regular',
            main_teacher_ids TEXT,
            p1_name TEXT DEFAULT 'Period 1',
            p2_name TEXT DEFAULT 'Period 2',
            p3_name TEXT DEFAULT 'Period 3',
            p4_name TEXT DEFAULT 'Period 4',
            p5_name TEXT DEFAULT 'Period 5',
            p6_name TEXT DEFAULT 'Period 6',
            subjects_list TEXT,
              is_deleted INTEGER DEFAULT 0,
              is_synced INTEGER DEFAULT 0,
              system_type TEXT DEFAULT 'Madrasa'
            )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS exam_results(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exam_id INTEGER,
            student_id INTEGER,
            subject TEXT DEFAULT 'Quran',
            marks_obtained REAL,
            total_marks REAL,
            grade TEXT,
            status TEXT,
            remarks TEXT,
            pukhtagi INTEGER DEFAULT 0,
            tajweed INTEGER DEFAULT 0,
            lahja INTEGER DEFAULT 0,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS acc_payroll(
            id INTEGER PRIMARY KEY,
            teacher_id INTEGER,
            month_year TEXT,
            basic_salary REAL DEFAULT 0,
            allowances REAL DEFAULT 0,
            deductions REAL DEFAULT 0,
            net_salary REAL DEFAULT 0,
            status TEXT DEFAULT 'Unpaid',
            payment_date TEXT,
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE IF NOT EXISTS acc_invoices(
            id INTEGER PRIMARY KEY,
            student_id INTEGER,
            invoice_no TEXT,
            month_year TEXT,
            amount REAL,
            status TEXT DEFAULT 'Unpaid',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');
      },
    );
  }

  Future<List<Map<String, dynamic>>> getClasses() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('classes',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        orderBy: 'id ASC');
  }

  /// Role-based class list:
  /// - admin / superadmin → تمام classes
  /// - non-admin with assigned classes → صرف الاٹ شدہ classes
  /// - non-admin without assigned classes but with class perms → تمام classes
  /// - teacher without assigned classes → teacher_id سے خودکار classes
  Future<List<Map<String, dynamic>>> getClassesForUser() async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sysType == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    final assigned = currentUserAssignedClasses.value;

    // صرف Superadmin / Admin کو غیر مشروط تمام کلاسز
    if (isAdmin) {
      return getClasses();
    }

    // اگر غیر ایڈمن کو مخصوص کلاسز الاٹ ہیں تو سختی سے صرف وہی کلاسز لوٹائیں
    if (assigned.isNotEmpty) {
      final db = await database;
      final placeholders = assigned.map((_) => '?').join(',');
      final result = await db.rawQuery(
        'SELECT * FROM classes WHERE name IN ($placeholders) AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC',
        assigned,
      );
      return result;
    }

    // اگر کوئی کلاس الاٹ نہیں لیکن ناظم یا پرمیشن ہے تو تمام کلاسز
    String permName =
        (sysType == 'Masjid') ? 'manage_masjid_classes' : 'manage_classes';
    if (role == 'nazim' ||
        currentUserPermissions.value.contains(permName) ||
        currentUserPermissions.value.contains('manage_classes') ||
        currentUserPermissions.value.contains('manage_masjid_classes') ||
        currentUserPermissions.value.contains('all')) {
      return getClasses();
    }

    // استاد کے لیے اگر الاٹ شدہ کلاسز خالی ہیں تو کلاسز ٹیبل سے تلاش کریں
    final db = await database;
    final uid = currentUserId.value;
    if (uid != null && uid > 0) {
      final autoClasses = await db.rawQuery(
        'SELECT * FROM classes WHERE (teacher_id = ? OR main_teacher_ids LIKE ? OR assistant_teacher_ids LIKE ?) AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC',
        [uid, '%$uid%', '%$uid%'],
      );
      if (autoClasses.isNotEmpty) {
        return autoClasses;
      }
    }
    return [];
  }

  /// Role & Assigned-Classes based complete student list:
  /// - admin / superadmin → تمام طلبہ
  /// - non-admin with assigned classes → صرف الاٹ شدہ کلاسز کے طلبہ
  /// - non-admin with empty assigned classes but student permissions → تمام طلبہ
  Future<List<Map<String, dynamic>>> getStudentsForUser(
      {bool includeInactive = false}) async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final assigned = currentUserAssignedClasses.value;
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sysType == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    final statusCond = includeInactive
        ? "1=1"
        : "(status IS NULL OR LOWER(TRIM(status)) IN ('active', '', 'present', 'حاضر'))";

    if (isAdmin) {
      return getStudents(includeInactive: includeInactive);
    }

    // اگر مخصوص کلاسز الاٹ ہیں تو سختی سے صرف ان کلاسز کے طلبہ
    if (assigned.isNotEmpty) {
      final db = await database;
      final placeholders = assigned.map((_) => '?').join(',');
      return await db.rawQuery(
        'SELECT * FROM students WHERE (class_name IN ($placeholders) OR class IN ($placeholders)) AND $sysCond AND $statusCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC',
        [...assigned, ...assigned],
      );
    }

    // اگر کوئی کلاس مخصوص نہیں لیکن ناظم یا فل اسٹوڈنٹ پرمیشن ہے تو تمام طلبہ
    final hasStudentPerm = role == 'nazim' ||
        currentUserPermissions.value.contains('manage_students') ||
        currentUserPermissions.value.contains('manage_masjid_students') ||
        currentUserPermissions.value.contains('all');
    if (hasStudentPerm) {
      return getStudents(includeInactive: includeInactive);
    }

    // اگر استاد ہے اور الاٹ لسٹ خالی ہے تو اس کی کلاسز معلوم کر کے طلبہ لائیں
    if (role == 'teacher') {
      final userClasses = await getClassesForUser();
      if (userClasses.isNotEmpty) {
        final cNames = userClasses
            .map((c) => c['name']?.toString() ?? '')
            .where((n) => n.isNotEmpty)
            .toList();
        if (cNames.isNotEmpty) {
          final db = await database;
          final placeholders = cNames.map((_) => '?').join(',');
          return await db.rawQuery(
            'SELECT * FROM students WHERE (class_name IN ($placeholders) OR class IN ($placeholders)) AND $sysCond AND $statusCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC',
            [...cNames, ...cNames],
          );
        }
      }
    }

    return [];
  }

  /// Role-based student list with pagination:
  /// - admin/superadmin → تمام students
  /// - non-admin with assigned classes → صرف assigned classes کے students
  /// - non-admin with empty assigned classes but student perm → تمام students
  Future<List<Map<String, dynamic>>> getPagedStudentsForUser({
    int limit = 30,
    int offset = 0,
    String? searchQuery,
    String? status,
    String? alumniCategory,
  }) async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final assigned = currentUserAssignedClasses.value;

    final hasFullStudentPerm = isAdmin ||
        role == 'nazim' ||
        currentUserPermissions.value.contains('manage_students') ||
        currentUserPermissions.value.contains('manage_masjid_students') ||
        currentUserPermissions.value.contains('all');

    // اگر ایڈمن ہو، یا فل پرمیشن کے ساتھ کوئی مخصوص کلاس الاٹ نہ ہو تو تمام طلبہ
    if (isAdmin || (hasFullStudentPerm && assigned.isEmpty)) {
      return getPagedStudents(
          limit: limit,
          offset: offset,
          searchQuery: searchQuery,
          status: status,
          alumniCategory: alumniCategory);
    }

    // استاذ کے لیے اگر الاٹ شدہ کلاسز خالی ہیں تو کلاسز ٹیبل سے تلاش کریں
    List<String> effectiveAssigned = List<String>.from(assigned);
    if (effectiveAssigned.isEmpty && role == 'teacher') {
      try {
        final userClasses = await getClassesForUser();
        effectiveAssigned = userClasses
            .map((c) => c['name']?.toString() ?? '')
            .where((n) => n.isNotEmpty)
            .toList();
      } catch (_) {}
    }

    // اگر اب بھی کلاسز خالی ہیں لیکن پرمیشن موجود ہے تو تمام طلبہ
    if (effectiveAssigned.isEmpty && hasFullStudentPerm) {
      return getPagedStudents(
          limit: limit,
          offset: offset,
          searchQuery: searchQuery,
          status: status,
          alumniCategory: alumniCategory);
    }

    // اگر کوئی کلاس الاٹ نہیں اور پرمیشن بھی نہیں تو خالی فہرست
    if (effectiveAssigned.isEmpty) {
      return [];
    }

    // Teacher: صرف assigned classes کے students
    Database db = await database;
    final sysCond = (sysType == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    // Verify if any assigned class belongs to current system_type
    final matchingClasses = await db.query('classes',
        where:
            "name IN (${effectiveAssigned.map((_) => '?').join(',')}) AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL)",
        whereArgs: effectiveAssigned);

    if (matchingClasses.isEmpty && hasFullStudentPerm) {
      return getPagedStudents(
          limit: limit,
          offset: offset,
          searchQuery: searchQuery,
          status: status,
          alumniCategory: alumniCategory);
    }
    if (matchingClasses.isEmpty) {
      return [];
    }

    final List<String> conditions = [];
    final List<dynamic> args = [];

    conditions.add('(is_deleted = 0 OR is_deleted IS NULL)');
    conditions.add(sysCond);

    // Class filter
    final placeholders = effectiveAssigned.map((_) => '?').join(',');
    conditions.add('class_name IN ($placeholders)');
    args.addAll(effectiveAssigned);

    if (status != null) {
      if (status == 'Active') {
        conditions.add(
            "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped'))");
      } else if (status == 'Alumni') {
        conditions.add(
            "(LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped'))");
      } else {
        conditions.add('LOWER(status) = ?');
        args.add(status.toLowerCase());
      }
    }

    if (alumniCategory != null &&
        alumniCategory.isNotEmpty &&
        alumniCategory != 'all') {
      if (alumniCategory == 'completed') {
        conditions.add(
            "(alumni_category = 'completed' OR alumni_category IS NULL OR alumni_category = '')");
      } else {
        conditions.add('alumni_category = ?');
        args.add(alumniCategory);
      }
    }

    if (searchQuery != null && searchQuery.isNotEmpty) {
      conditions
          .add('(name LIKE ? OR roll_number LIKE ? OR class_name LIKE ?)');
      final q = '%$searchQuery%';
      args.addAll([q, q, q]);
    }

    final where = conditions.join(' AND ');
    return await db.query(
      'students',
      where: where,
      whereArgs: args,
      limit: limit,
      offset: offset,
      orderBy: 'name ASC',
    );
  }

  Future<Map<String, int>> getStudentSummaryCounts({String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    String baseWhere = '(is_deleted = 0 OR is_deleted IS NULL)';
    if (isMasjid) {
      baseWhere += " AND (system_type = 'Masjid' OR is_maktab = 1)";
    } else {
      baseWhere +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    final activeCond =
        "$baseWhere AND (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped', 'inactive'))";
    final alumniCond =
        "$baseWhere AND (LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped', 'inactive'))";

    final activeCount = Sqflite.firstIntValue(await db.rawQuery(
          'SELECT COUNT(*) FROM students WHERE $activeCond',
        )) ??
        0;

    final alumniCount = Sqflite.firstIntValue(await db.rawQuery(
          'SELECT COUNT(*) FROM students WHERE $alumniCond',
        )) ??
        0;

    // اقامتی طلبہ (Residential):
    // Contains 'residential' or 'اقامتی' or 'رہائشی', BUT does NOT contain 'non' or 'غیر'
    final resCond = """$activeCond AND (
      (
        (LOWER(residence_status) LIKE '%residential%' AND LOWER(residence_status) NOT LIKE '%non%')
        OR ((residence_status LIKE '%اقامتی%' OR residence_status LIKE '%رہائشی%') AND residence_status NOT LIKE '%غیر%')
      )
    )""";
    final resCount = Sqflite.firstIntValue(await db.rawQuery(
          'SELECT COUNT(*) FROM students WHERE $resCond',
        )) ??
        0;

    final nonResCount =
        (activeCount >= resCount) ? (activeCount - resCount) : 0;

    return {
      'total_active': activeCount,
      'residential': resCount,
      'non_residential': nonResCount,
      'alumni': alumniCount,
    };
  }

  Future<int> insertClass(Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['status'] = data['status'] ?? 'Active';
    data['class_type'] = data['class_type'] ?? 'Darse Nizami';
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    return await db.insert('classes', data,
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<int> updateClass(int id, Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return db.update('classes', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteClass(int id) async {
    Database db = await database;
    return db.update('classes', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<int> insertStudent(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    final sysType =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    data['system_type'] = sysType;
    final sysCond = (sysType == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    final admNo = data['roll_number']?.toString().trim().toLowerCase() ?? '';
    if (admNo.isNotEmpty) {
      final existing = await db.query(
        'students',
        where:
            "LOWER(TRIM(roll_number)) = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
        whereArgs: [admNo],
        limit: 1,
      );
      if (existing.isNotEmpty) return -1;
    }
    return await db.insert('students', data);
  }

  Future<int> updateStudent(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.update('students', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteStudent(int id) async {
    Database db = await database;
    return await db.update(
        'students', {'status': 'Deleted', 'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<void> _createAccountingTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS acc_chart_of_accounts(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT,
        name TEXT,
        type TEXT,
        parent_id INTEGER,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS acc_vouchers(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_no TEXT,
        date TEXT,
        type TEXT,
        reference TEXT,
        description TEXT,
        department_id INTEGER,
        status TEXT DEFAULT 'Pending',
        created_by INTEGER,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0
      )
    ''');
    await db.execute('''
      CREATE TABLE IF NOT EXISTS acc_ledger_entries(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_id INTEGER,
        account_id INTEGER,
        debit REAL DEFAULT 0,
        credit REAL DEFAULT 0,
        department_id INTEGER,
        is_synced INTEGER DEFAULT 0
      )
    ''');
    await _addColumnIfNotExists(db, 'heads', 'is_deleted', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(db, 'heads', 'is_synced', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(
        db, 'acc_vouchers', 'system_type', "TEXT DEFAULT 'Madrasa'");
    await _addColumnIfNotExists(
        db, 'acc_chart_of_accounts', 'system_type', "TEXT DEFAULT 'Madrasa'");
  }

  Future<List<Map<String, dynamic>>> getStudents(
      {String? systemType, bool includeInactive = false}) async {
    Database db = await database;
    try {
      final sysType = systemType ?? SystemTypeGlobal.currentSystemType.value;
      final sysCond = sysType == 'Masjid'
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      final statusCond = includeInactive
          ? "1=1"
          : "(status IS NULL OR LOWER(TRIM(status)) IN ('active', '', 'present', 'حاضر'))";
      return await db.query('students',
          where:
              "$statusCond AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond",
          orderBy: 'name ASC');
    } catch (e) {
      // Fallback if column missing during mid-sync/upgrade
      debugPrint("getStudents error: $e");
      return await db.query('students', orderBy: 'name ASC');
    }
  }

  Future<List<Map<String, dynamic>>> getPagedStudents({
    int limit = 30,
    int offset = 0,
    String? searchQuery,
    String? status,
    String? alumniCategory,
  }) async {
    Database db = await database;
    final List<String> conditions = [];
    final List<dynamic> args = [];

    conditions.add("(is_deleted = 0 OR is_deleted IS NULL)");

    final sysType = SystemTypeGlobal.currentSystemType.value;
    if (sysType == 'Masjid') {
      conditions.add("system_type = 'Masjid'");
    } else {
      conditions.add(
          "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')");
    }

    if (status != null) {
      if (status == 'Active') {
        conditions.add(
            "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped'))");
      } else if (status == 'Alumni') {
        conditions.add(
            "(LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped') OR (graduation_date IS NOT NULL AND graduation_date != ''))");
      } else {
        conditions.add('LOWER(status) = ?');
        args.add(status.toLowerCase());
      }
    }

    if (alumniCategory != null &&
        alumniCategory.isNotEmpty &&
        alumniCategory != 'all') {
      if (alumniCategory == 'completed') {
        conditions.add(
            "(alumni_category = 'completed' OR alumni_category IS NULL OR alumni_category = '')");
      } else {
        conditions.add('alumni_category = ?');
        args.add(alumniCategory);
      }
    }

    if (searchQuery != null && searchQuery.isNotEmpty) {
      conditions
          .add('(name LIKE ? OR roll_number LIKE ? OR class_name LIKE ?)');
      final q = '%$searchQuery%';
      args.addAll([q, q, q]);
    }

    final where = conditions.isEmpty ? null : conditions.join(' AND ');

    return await db.query(
      'students',
      where: where,
      whereArgs: args.isNotEmpty ? args : null,
      limit: limit,
      offset: offset,
      orderBy: 'name ASC',
    );
  }

  Future<List<Map<String, dynamic>>> getQaidaRecords() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final db = await database;
    return await db.query('qaida_records', where: sysCond);
  }

  Future<List<Map<String, dynamic>>> getHifzRecords() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('hifz_record', where: sysCond);
  }

  Future<String> getNextStudentRollNumber(
      {int? hijriYear, String? systemType}) async {
    final db = await database;
    final sysType = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sysType == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    if (hijriYear == null) {
      final s = await db.query('settings', limit: 1);
      hijriYear = (s.isNotEmpty && s.first['current_hijri_year'] != null)
          ? (int.tryParse(s.first['current_hijri_year'].toString()) ?? 1447)
          : 1447;
    }
    final prefix = '$hijriYear-';
    final rows = await db.query(
      'students',
      columns: ['roll_number'],
      where:
          'roll_number LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
      whereArgs: ['$prefix%'],
    );

    var highestSerial = 0;
    for (final row in rows) {
      final rollNumber = row['roll_number']?.toString() ?? '';
      final serial = int.tryParse(rollNumber.split('-').last);
      if (serial != null && serial > highestSerial) {
        highestSerial = serial;
      }
    }

    return '$prefix${(highestSerial + 1).toString().padLeft(2, '0')}';
  }

  Future<int> insertHifz(Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('hifz_record', data);
  }

  Future<List<Map<String, dynamic>>> getNaziraRecords() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final db = await database;
    return await db.query('nazira_record', where: sysCond);
  }

  Future<int> insertNazira(Map<String, dynamic> row) async {
    final db = await database;
    Map<String, dynamic> data = Map.from(row);
    data['is_synced'] = 0;
    return await db.insert('nazira_record', data);
  }

  Future<List<Map<String, dynamic>>> getTeachers() async {
    Database db = await database;
    try {
      final sysType = SystemTypeGlobal.currentSystemType.value;
      final sysCond = sysType == 'Masjid'
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      return await db.query('teachers',
          where: "(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond");
    } catch (e) {
      debugPrint("getTeachers error: $e");
      return await db.query('teachers');
    }
  }

  Future<int> updateTeacher(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['status'] = data['status'] ?? 'Active';
    return await db.update('teachers', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> insertTeacher(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['status'] = data['status'] ?? 'Active';
    return await db.insert('teachers', data);
  }

  Future<void> _createTeacherAttendanceTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS attendance_teachers(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER,
        date TEXT,
        status TEXT,
        late_minutes INTEGER DEFAULT 0,
        remarks TEXT,
        system_type TEXT DEFAULT 'Madrasa',
            is_synced INTEGER DEFAULT 0
      )
    ''');
  }

  Future<int> insertTeacherAttendance(Map<String, dynamic> row) async {
    Database db = await database;
    await _createTeacherAttendanceTable(db);
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;

    final existing = await db.query(
      'attendance_teachers',
      where: 'date = ? AND teacher_id = ?',
      whereArgs: [data['date'], data['teacher_id']],
      limit: 1,
    );

    if (existing.isNotEmpty) {
      return await db.update(
        'attendance_teachers',
        data,
        where: 'id = ?',
        whereArgs: [existing.first['id']],
      );
    }
    return await db.insert('attendance_teachers', data);
  }

  Future<List<Map<String, dynamic>>> getTeacherAttendanceByDate(
      String date) async {
    Database db = await database;
    await _createTeacherAttendanceTable(db);
    return await db
        .query('attendance_teachers', where: 'date = ?', whereArgs: [date]);
  }

  Future<List<Map<String, dynamic>>> getTeacherAttendanceByRange(
      String startDate, String endDate) async {
    Database db = await database;
    await _createTeacherAttendanceTable(db);
    return await db.rawQuery('''
      SELECT at.*, t.name AS teacher_name, t.phone
      FROM attendance_teachers at
      JOIN teachers t ON at.teacher_id = t.id
      WHERE at.date BETWEEN ? AND ?
      ORDER BY at.date ASC, t.name ASC
    ''', [startDate, endDate]);
  }

  Future<int> insertTeacherPeriodAttendance(Map<String, dynamic> row) async {
    Database db = await database;
    await _createTeacherPeriodAttendanceTable(db);
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    final existing = await db.query(
      'teacher_period_attendance',
      where: 'date = ? AND teacher_id = ? AND class_name = ? AND period_no = ?',
      whereArgs: [
        data['date'],
        data['teacher_id'],
        data['class_name'] ?? '',
        data['period_no']
      ],
      limit: 1,
    );
    final result = existing.isNotEmpty
        ? await db.update('teacher_period_attendance', data,
            where: 'id = ?', whereArgs: [existing.first['id']])
        : await db.insert('teacher_period_attendance', data);
    await _syncTeacherDailyAttendanceSummary(
      db,
      data['teacher_id'],
      data['date']?.toString() ?? '',
    );
    return result;
  }

  Future<void> _syncTeacherDailyAttendanceSummary(
      Database db, dynamic teacherIdValue, String date) async {
    await _createTeacherAttendanceTable(db);
    final teacherId = int.tryParse(teacherIdValue?.toString() ?? '') ?? 0;
    if (teacherId == 0 || date.isEmpty) return;
    final rows = await db.query(
      'teacher_period_attendance',
      where:
          'teacher_id = ? AND date = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
      whereArgs: [teacherId, date],
    );
    if (rows.isEmpty) return;
    var status = 'A';
    var maxLate = 0;
    for (final row in rows) {
      final raw = row['status']?.toString() ?? 'P';
      final normalized = _normalizeTeacherAttendanceStatus(raw);
      if (normalized == 'P') {
        status = 'P';
      } else if (status != 'P' && normalized == 'L') {
        status = 'L';
      }
      final late = int.tryParse(row['late_minutes']?.toString() ?? '0') ?? 0;
      if (late > maxLate) maxLate = late;
    }
    await insertTeacherAttendance({
      'teacher_id': teacherId,
      'date': date,
      'status': status,
      'late_minutes': maxLate,
      'remarks': maxLate.toString(),
    });
  }

  String _normalizeTeacherAttendanceStatus(String status) {
    final clean = status.trim();
    final code = clean.toUpperCase();
    if (clean == '\u062d\u0627\u0636\u0631' || code == 'P') return 'P';
    if (clean == '\u063a\u06cc\u0631 \u062d\u0627\u0636\u0631' || code == 'A') {
      return 'A';
    }
    if (clean ==
            '\u0631\u062e\u0635\u062a \u0627\u062a\u0641\u0627\u0642\u06cc' ||
        code == 'LA') {
      return 'L';
    }
    if (clean == '\u0631\u062e\u0635\u062a' || code == 'L') {
      return 'L';
    }
    if (clean == '\u0628\u06a9\u0627\u0631 \u0645\u062f\u0631\u0633\u06c1' ||
        code == 'OD') {
      return 'P';
    }
    return clean.isEmpty ? 'P' : clean;
  }

  Future<List<Map<String, dynamic>>> getTeacherPeriodAttendanceByDate(
      String date) async {
    Database db = await database;
    await _createTeacherPeriodAttendanceTable(db);
    return db.query('teacher_period_attendance',
        where: 'date = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
        whereArgs: [date]);
  }

  Future<int> insertAttendance(Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;

    // Check for existing record to avoid duplicates
    final existing = await db.query(
      'attendance',
      where: 'date = ? AND student_id = ? AND shift = ?',
      whereArgs: [data['date'], data['student_id'], data['shift']],
      limit: 1,
    );

    if (existing.isNotEmpty) {
      return await db.update(
        'attendance',
        data,
        where: 'id = ?',
        whereArgs: [existing.first['id']],
      );
    } else {
      return await db.insert('attendance', data);
    }
  }

  Future<List<Map<String, dynamic>>> getAttendance() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('attendance', where: sysCond);
  }

  Future<List<Map<String, dynamic>>> getAttendanceByDate(String date) async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query(
      'attendance',
      where: '(date = ?) AND $sysCond',
      whereArgs: [date],
    );
  }

  Future<List<Map<String, dynamic>>> getFinance(
      {int? userId, String? userRole}) async {
    final rawSys = SystemTypeGlobal.currentSystemType.value;
    final sysType = (rawSys.isNotEmpty && rawSys.toLowerCase() == 'masjid')
        ? 'Masjid'
        : 'Madrasa';

    Database db = await database;
    final role = (userRole ?? currentUserRole.value)?.toLowerCase() ?? '';
    final targetUserId = userId ?? currentUserId.value;

    if (sysType == 'Masjid') {
      return getMasjidFinance(userId: userId, userRole: userRole);
    }

    final isAdmin = role == 'admin' ||
        role == 'superadmin' ||
        role == 'super_admin' ||
        role == 'administrator';
    const sysWhere =
        '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = ? OR system_type IS NULL OR system_type = \'\' OR LOWER(system_type) = LOWER(?))';

    if (isAdmin) {
      return await db.query(
        'finance',
        where: sysWhere,
        whereArgs: [sysType, sysType],
        orderBy: 'user_id ASC, date DESC, id DESC',
      );
    }

    final curName = (currentUserName.value ?? '').trim().toLowerCase();
    final List<String> userMatch = [];
    final List<dynamic> userArgs = [];

    final Set<String> myNames = {if (curName.isNotEmpty) curName};
    try {
      if (targetUserId != null) {
        final adRows = await db.rawQuery(
          "SELECT username, name FROM admins WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          [targetUserId],
        );
        for (final r in adRows) {
          final u = r['username']?.toString().trim().toLowerCase() ?? '';
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          if (u.isNotEmpty) myNames.add(u);
          if (n.isNotEmpty) myNames.add(n);
        }
        final tcRows = await db.rawQuery(
          "SELECT name, user_name FROM teachers WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          [targetUserId],
        );
        for (final r in tcRows) {
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          final u = r['user_name']?.toString().trim().toLowerCase() ?? '';
          if (n.isNotEmpty) myNames.add(n);
          if (u.isNotEmpty) myNames.add(u);
        }
      }
    } catch (_) {}

    if (curName.contains('سلیم') || curName.contains('saleem')) {
      myNames.add('saleem');
      myNames.add('قاری سلیم اللہ');
      myNames.add('قاری سلیم اللہ صاحب');
    } else if (curName.contains('تنزیل') || curName.contains('tanzeel')) {
      myNames.add('tanzeel');
      myNames.add('تنزیل الرحمن');
    }

    if (targetUserId != null) {
      // 1. Primary: Match all records explicitly assigned to this user's ID
      userMatch.add("user_id = ?");
      userArgs.add(targetUserId);

      // 2. Secondary fallback: Match unassigned records (user_id is 0 or null) matching user's names
      if (myNames.isNotEmpty) {
        final namePlaceholders = myNames.map((_) => '?').join(',');
        userMatch.add(
            "((user_id IS NULL OR user_id = 0) AND LOWER(TRIM(user_name)) IN ($namePlaceholders))");
        userArgs.addAll(myNames);
      }
    } else if (myNames.isNotEmpty) {
      final namePlaceholders = myNames.map((_) => '?').join(',');
      userMatch.add("LOWER(TRIM(user_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
    }

    final userWhere =
        userMatch.isNotEmpty ? "(${userMatch.join(' OR ')}) AND " : "";
    return await db.query(
      'finance',
      where: '$userWhere$sysWhere',
      whereArgs: [...userArgs, sysType, sysType],
      orderBy: 'date DESC, id DESC',
    );
  }

  // ═══════════════════════════════════════════════════════
  // مسجد فنانس CRUD فنکشنز (الگ masjid_finance ٹیبل)
  // ═══════════════════════════════════════════════════════

  Future<List<Map<String, dynamic>>> getMasjidFinance(
      {int? userId, String? userRole}) async {
    Database db = await database;
    final role = (userRole ?? currentUserRole.value)?.toLowerCase() ?? '';
    final targetUserId = userId ?? currentUserId.value;
    final isAdmin = role == 'admin' ||
        role == 'superadmin' ||
        role == 'super_admin' ||
        role == 'administrator';

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS masjid_finance(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          date TEXT, type TEXT, amount REAL, description TEXT,
          head_id INTEGER, head_name TEXT,
          manual_receipt_no TEXT, computer_receipt_no INTEGER,
          donor_name TEXT, donor_address TEXT,
          spender_name TEXT, signature_image TEXT, receipt_no TEXT,
          user_id INTEGER DEFAULT 1, user_name TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0, is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    if (isAdmin) {
      return await db.query(
        'masjid_finance',
        where: '(is_deleted = 0 OR is_deleted IS NULL)',
        orderBy: 'user_id ASC, date DESC, id DESC',
      );
    }

    final curName = (currentUserName.value ?? '').trim().toLowerCase();
    final List<String> userMatch = [];
    final List<dynamic> userArgs = [];

    final Set<String> myNames = {if (curName.isNotEmpty) curName};
    try {
      if (targetUserId != null) {
        final adRows = await db.rawQuery(
          "SELECT username, name FROM admins WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          [targetUserId],
        );
        for (final r in adRows) {
          final u = r['username']?.toString().trim().toLowerCase() ?? '';
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          if (u.isNotEmpty) myNames.add(u);
          if (n.isNotEmpty) myNames.add(n);
        }
        final tcRows = await db.rawQuery(
          "SELECT name, user_name FROM teachers WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
          [targetUserId],
        );
        for (final r in tcRows) {
          final n = r['name']?.toString().trim().toLowerCase() ?? '';
          final u = r['user_name']?.toString().trim().toLowerCase() ?? '';
          if (n.isNotEmpty) myNames.add(n);
          if (u.isNotEmpty) myNames.add(u);
        }
      }
    } catch (_) {}

    if (curName.contains('سلیم') || curName.contains('saleem')) {
      myNames.add('saleem');
      myNames.add('قاری سلیم اللہ');
      myNames.add('قاری سلیم اللہ صاحب');
    } else if (curName.contains('تنزیل') || curName.contains('tanzeel')) {
      myNames.add('tanzeel');
      myNames.add('تنزیل الرحمن');
    }

    if (targetUserId != null) {
      // 1. Primary: Match all records explicitly assigned to this user's ID
      userMatch.add("user_id = ?");
      userArgs.add(targetUserId);

      // 2. Secondary fallback: Match unassigned records (user_id is 0 or null) matching user's names
      if (myNames.isNotEmpty) {
        final namePlaceholders = myNames.map((_) => '?').join(',');
        userMatch.add(
            "((user_id IS NULL OR user_id = 0) AND LOWER(TRIM(user_name)) IN ($namePlaceholders))");
        userArgs.addAll(myNames);
      }
    } else if (myNames.isNotEmpty) {
      final namePlaceholders = myNames.map((_) => '?').join(',');
      userMatch.add("LOWER(TRIM(user_name)) IN ($namePlaceholders)");
      userArgs.addAll(myNames);
    }

    final userWhere =
        userMatch.isNotEmpty ? "(${userMatch.join(' OR ')}) AND " : "";
    return await db.query(
      'masjid_finance',
      where: '$userWhere(is_deleted = 0 OR is_deleted IS NULL)',
      whereArgs: userArgs,
      orderBy: 'date DESC, id DESC',
    );
  }

  Future<Map<int, Map<String, dynamic>>> getMasjidFinanceGroupedByUser() async {
    final advSummaries = await getAllUserAdvanceSummaries(systemType: 'Masjid');
    try {
      for (final s in advSummaries.values) {
        final aid = s['account_id'] as int;
        final uLabel = s['user_label'] as String;
        await autoReconcileAdvanceExpenses(aid, uLabel);
      }
    } catch (e) {
      debugPrint('Error auto-reconciling advance expenses (Masjid): $e');
    }

    // Build admin lookup for canonical Urdu names & IDs
    final Map<int, String> adminNamesById = {
      1: 'تنزیل الرحمن',
      2: 'قاری حماد اللہ ساجد صاحب',
      3: 'قاری سلیم اللہ صاحب',
      4: 'محمد عثمان معاویہ',
    };
    final Map<String, String> adminNamesByUsername = {
      'tanzeel': 'تنزیل الرحمن',
      'hammad': 'قاری حماد اللہ ساجد صاحب',
      'saleem': 'قاری سلیم اللہ صاحب',
      'usman': 'محمد عثمان معاویہ',
    };
    try {
      final Database db = await database;
      final adminList = await db.rawQuery(
          "SELECT id, username, name FROM admins WHERE (is_deleted = 0 OR is_deleted IS NULL)");
      for (final a in adminList) {
        final aid = int.tryParse(a['id']?.toString() ?? '') ?? 0;
        final aname = (a['name']?.toString() ?? '').trim();
        final auname = (a['username']?.toString() ?? '').trim().toLowerCase();
        if (aid > 0 && aname.isNotEmpty) adminNamesById[aid] = aname;
        if (auname.isNotEmpty && aname.isNotEmpty)
          adminNamesByUsername[auname] = aname;
      }
    } catch (_) {}

    int resolveCanonicalUid(int rawUid, String rawUname) {
      final lowerName = rawUname.toLowerCase();
      if (lowerName.contains('tanzeel') || lowerName.contains('تنزیل'))
        return 1;
      if (lowerName.contains('hammad') || lowerName.contains('حماد')) return 2;
      if (lowerName.contains('saleem') || lowerName.contains('سلیم')) return 3;
      if (lowerName.contains('usman') || lowerName.contains('عثمان')) return 4;
      if (rawUid == 7) return 3;
      return rawUid > 0 ? rawUid : 1;
    }

    String resolveCanonicalName(int uid, String rawUname) {
      final lower = rawUname.toLowerCase();
      if (lower.contains('tanzeel') || lower.contains('تنزیل') || uid == 1)
        return 'تنزیل الرحمن';
      if (lower.contains('hammad') || lower.contains('حماد') || uid == 2)
        return 'قاری حماد اللہ ساجد صاحب';
      if (lower.contains('saleem') ||
          lower.contains('سلیم') ||
          uid == 3 ||
          uid == 7) return 'قاری سلیم اللہ صاحب';
      if (lower.contains('usman') || lower.contains('عثمان') || uid == 4)
        return 'محمد عثمان معاویہ';
      if (adminNamesById.containsKey(uid)) return adminNamesById[uid]!;
      if (adminNamesByUsername.containsKey(lower))
        return adminNamesByUsername[lower]!;
      if (rawUname.isNotEmpty && !rawUname.contains('@') && rawUname != 'Admin')
        return rawUname;
      return uid > 0 ? 'یوزر $uid' : 'نامعلوم';
    }

    final records = await getMasjidFinance(userRole: 'admin');
    final Map<int, Map<String, dynamic>> grouped = {};
    for (var r in records) {
      final rawUid = int.tryParse(r['user_id']?.toString() ?? '') ?? 0;
      final rawUname = (r['user_name'] as String?)?.trim() ?? '';
      final uid = resolveCanonicalUid(rawUid, rawUname);
      final uname = resolveCanonicalName(uid, rawUname);
      if (!grouped.containsKey(uid)) {
        grouped[uid] = {
          'name': uname,
          'income': <Map<String, dynamic>>[],
          'expense': <Map<String, dynamic>>[],
          'advance_received': 0.0,
          'advance_deducted': 0.0,
          'advance_balance': 0.0,
          'has_advance': false,
          'advance_account_id': 0,
          'other_expenses': 0.0,
        };
      }
      final type = (r['type'] as String? ?? '').toLowerCase();
      if (type == 'income') {
        (grouped[uid]!['income'] as List).add(r);
      } else {
        (grouped[uid]!['expense'] as List).add(r);
      }
    }

    final matchedAdvAids = <int>{};
    for (final entry in grouped.entries) {
      final g = entry.value;
      final gName = (g['name'] as String).toLowerCase();
      final gExpenses = (g['expense'] as List).cast<Map<String, dynamic>>();
      final userAccountIds = gExpenses
          .map((e) => int.tryParse(e['account_id']?.toString() ?? '') ?? 0)
          .where((id) => id > 0)
          .toSet();

      for (final s in advSummaries.values) {
        final aid = s['account_id'] as int;
        final uLabel = (s['user_label'] as String).toLowerCase();
        final accName = (s['name'] as String? ?? '').toLowerCase();
        final accCode = (s['code']?.toString() ?? '').trim();

        // Strict isolation and canonical matching for key users
        final rawUid = entry.key;
        bool isMatched = false;
        if (rawUid == 1) {
          if (accCode.contains('1502') ||
              uLabel.contains('tanzeel') ||
              uLabel.contains('تنزیل') ||
              accName.contains('tanzeel') ||
              accName.contains('تنزیل')) {
            isMatched = true;
          } else {
            continue;
          }
        } else if (rawUid == 2) {
          if (uLabel.contains('hammad') ||
              uLabel.contains('حماد') ||
              accName.contains('hammad') ||
              accName.contains('حماد')) {
            isMatched = true;
          }
        } else if (rawUid == 3) {
          if (accCode.contains('1503') ||
              uLabel.contains('saleem') ||
              uLabel.contains('سلیم') ||
              accName.contains('saleem') ||
              accName.contains('سلیم')) {
            isMatched = true;
          } else {
            continue;
          }
        } else if (rawUid == 4) {
          if (uLabel.contains('usman') ||
              uLabel.contains('عثمان') ||
              accName.contains('usman') ||
              accName.contains('عثمان')) {
            isMatched = true;
          }
        } else {
          if (accCode == '1502' ||
              accCode == '1503' ||
              uLabel.contains('tanzeel') ||
              uLabel.contains('تنزیل') ||
              uLabel.contains('saleem') ||
              uLabel.contains('سلیم')) {
            continue;
          }
        }

        if (!isMatched) {
          isMatched = (gName == uLabel ||
              gName.contains(uLabel) ||
              uLabel.contains(gName));
          if (!isMatched) {
            final words =
                gName.split(RegExp(r'[\s\-_:]+')).where((w) => w.length >= 4);
            for (final w in words) {
              if (uLabel.contains(w) || accName.contains(w)) {
                isMatched = true;
                break;
              }
            }
          }
        }

        if (isMatched) {
          final curRec = (g['advance_received'] as num?)?.toDouble() ?? 0.0;
          final curDed = (g['advance_deducted'] as num?)?.toDouble() ?? 0.0;
          final rec = (s['total_received'] as num?)?.toDouble() ?? 0.0;
          final ded = (s['total_deducted'] as num?)?.toDouble() ?? 0.0;
          g['advance_received'] = curRec + rec;
          g['advance_deducted'] = curDed + ded;
          g['advance_balance'] = (g['advance_received'] as double) -
              (g['advance_deducted'] as double);
          g['has_advance'] = true;
          if ((g['advance_account_id'] as int? ?? 0) == 0) {
            g['advance_account_id'] = aid;
          }
          matchedAdvAids.add(aid);
        }
      }

      // Dual-layer in-memory calculation of advance expenses for this user
      double inMemAdvDed = 0.0;
      double totalUserExp = 0.0;
      for (final exp in gExpenses) {
        final amt = double.tryParse(exp['amount']?.toString() ?? '0') ?? 0.0;
        totalUserExp += amt;
        final payMeth = (exp['payment_method']?.toString() ?? '').toLowerCase();
        final expAid = int.tryParse(exp['account_id']?.toString() ?? '') ?? 0;
        final desc = (exp['description']?.toString() ?? '').toLowerCase();
        final hName = (exp['head_name']?.toString() ?? '').toLowerCase();
        final aName = (exp['account_name']?.toString() ?? '').toLowerCase();

        bool isAdv = false;
        if (payMeth == 'advance' || payMeth.contains('ایڈوانس')) {
          isAdv = true;
        } else if (userAccountIds.contains(expAid) &&
            matchedAdvAids.contains(expAid)) {
          isAdv = true;
        } else if (aName.contains('ایڈوانس') ||
            aName.contains('advance') ||
            desc.contains('ایڈوانس') ||
            desc.contains('advance') ||
            hName.contains('ایڈوانس')) {
          isAdv = true;
        } else if (g['has_advance'] == true &&
            ((g['advance_received'] as num?)?.toDouble() ?? 0.0) > 0 &&
            (expAid == 0 || expAid == (g['advance_account_id'] as int? ?? 0))) {
          isAdv = true;
        }

        if (isAdv) {
          inMemAdvDed += amt;
        }
      }

      if (g['has_advance'] == true) {
        final curDed = (g['advance_deducted'] as num?)?.toDouble() ?? 0.0;
        final effectiveDed = inMemAdvDed > curDed ? inMemAdvDed : curDed;
        final rec = (g['advance_received'] as num?)?.toDouble() ?? 0.0;
        g['advance_deducted'] = effectiveDed;
        g['advance_balance'] = rec - effectiveDed;
        final otherExp = totalUserExp - effectiveDed;
        g['other_expenses'] = otherExp > 0 ? otherExp : 0.0;
      } else {
        g['other_expenses'] = totalUserExp;
      }
    }

    // Ensure any advance user without finance records is also displayed,
    // BUT strictly prevent creating duplicate cards for existing canonical users!
    int syntheticUid = -100;
    for (final s in advSummaries.values) {
      final aid = s['account_id'] as int;
      final uLabel = (s['user_label'] as String).toLowerCase();
      final accName = (s['name'] as String? ?? '').toLowerCase();
      final accCode = (s['code']?.toString() ?? '').trim();

      bool belongsToCanonical = uLabel.contains('tanzeel') ||
          uLabel.contains('تنزیل') ||
          accName.contains('tanzeel') ||
          accName.contains('تنزیل') ||
          accCode.contains('1502') ||
          uLabel.contains('hammad') ||
          uLabel.contains('حماد') ||
          accName.contains('hammad') ||
          accName.contains('حماد') ||
          uLabel.contains('saleem') ||
          uLabel.contains('سلیم') ||
          accName.contains('saleem') ||
          accName.contains('سلیم') ||
          accCode.contains('1503') ||
          uLabel.contains('usman') ||
          uLabel.contains('عثمان') ||
          accName.contains('usman') ||
          accName.contains('عثمان');

      if (belongsToCanonical) {
        continue;
      }

      if (!matchedAdvAids.contains(aid) &&
          (s['total_received'] as double) > 0) {
        grouped[syntheticUid--] = {
          'name': s['user_label'],
          'income': <Map<String, dynamic>>[],
          'expense': <Map<String, dynamic>>[],
          'advance_received': s['total_received'],
          'advance_deducted': s['total_deducted'],
          'advance_balance': s['net_balance'],
          'has_advance': true,
          'advance_account_id': aid,
          'other_expenses': 0.0,
        };
      }
    }

    return grouped;
  }

  Future<int> insertMasjidFinance(Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['system_type'] = 'Masjid';
    try {
      return await db.insert('masjid_finance', data,
          conflictAlgorithm: ConflictAlgorithm.replace);
    } catch (_) {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS masjid_finance(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          date TEXT, type TEXT, amount REAL, description TEXT,
          head_id INTEGER, head_name TEXT,
          manual_receipt_no TEXT, computer_receipt_no INTEGER,
          donor_name TEXT, donor_address TEXT,
          spender_name TEXT, signature_image TEXT, receipt_no TEXT,
          user_id INTEGER DEFAULT 1, user_name TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0, is_synced INTEGER DEFAULT 0
        )
      ''');
      return await db.insert('masjid_finance', data,
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  Future<int> updateMasjidFinance(int id, Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['system_type'] = 'Masjid';
    return await db
        .update('masjid_finance', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMasjidFinance(int id) async {
    Database db = await database;
    return await db.update('masjid_finance',
        {'is_deleted': 1, 'computer_receipt_no': null, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<Map<int, Map<String, dynamic>>> getFinanceGroupedByUser() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    if (sysType == 'Masjid') {
      return getMasjidFinanceGroupedByUser();
    }
    final advSummaries = await getAllUserAdvanceSummaries();
    try {
      for (final s in advSummaries.values) {
        final aid = s['account_id'] as int;
        final uLabel = s['user_label'] as String;
        await autoReconcileAdvanceExpenses(aid, uLabel);
      }
    } catch (e) {
      debugPrint('Error auto-reconciling advance expenses: $e');
    }

    // Build admin lookup for canonical Urdu names & IDs
    final Map<int, String> adminNamesById = {
      1: 'تنزیل الرحمن',
      2: 'قاری حماد اللہ ساجد صاحب',
      3: 'قاری سلیم اللہ صاحب',
      4: 'محمد عثمان معاویہ',
    };
    final Map<String, String> adminNamesByUsername = {
      'tanzeel': 'تنزیل الرحمن',
      'hammad': 'قاری حماد اللہ ساجد صاحب',
      'saleem': 'قاری سلیم اللہ صاحب',
      'usman': 'محمد عثمان معاویہ',
    };
    try {
      final Database db = await database;
      final adminList = await db.rawQuery(
          "SELECT id, username, name FROM admins WHERE (is_deleted = 0 OR is_deleted IS NULL)");
      for (final a in adminList) {
        final aid = int.tryParse(a['id']?.toString() ?? '') ?? 0;
        final aname = (a['name']?.toString() ?? '').trim();
        final auname = (a['username']?.toString() ?? '').trim().toLowerCase();
        if (aid > 0 && aname.isNotEmpty) adminNamesById[aid] = aname;
        if (auname.isNotEmpty && aname.isNotEmpty)
          adminNamesByUsername[auname] = aname;
      }
    } catch (_) {}

    int resolveCanonicalUid(int rawUid, String rawUname) {
      final lowerName = rawUname.toLowerCase();
      if (lowerName.contains('tanzeel') || lowerName.contains('تنزیل'))
        return 1;
      if (lowerName.contains('hammad') || lowerName.contains('حماد')) return 2;
      if (lowerName.contains('saleem') || lowerName.contains('سلیم')) return 3;
      if (lowerName.contains('usman') || lowerName.contains('عثمان')) return 4;
      if (rawUid == 7) return 3;
      return rawUid > 0 ? rawUid : 1;
    }

    String resolveCanonicalName(int uid, String rawUname) {
      final lower = rawUname.toLowerCase();
      if (lower.contains('tanzeel') || lower.contains('تنزیل') || uid == 1)
        return 'تنزیل الرحمن';
      if (lower.contains('hammad') || lower.contains('حماد') || uid == 2)
        return 'قاری حماد اللہ ساجد صاحب';
      if (lower.contains('saleem') ||
          lower.contains('سلیم') ||
          uid == 3 ||
          uid == 7) return 'قاری سلیم اللہ صاحب';
      if (lower.contains('usman') || lower.contains('عثمان') || uid == 4)
        return 'محمد عثمان معاویہ';
      if (adminNamesById.containsKey(uid)) return adminNamesById[uid]!;
      if (adminNamesByUsername.containsKey(lower))
        return adminNamesByUsername[lower]!;
      if (rawUname.isNotEmpty && !rawUname.contains('@') && rawUname != 'Admin')
        return rawUname;
      return uid > 0 ? 'یوزر $uid' : 'نامعلوم';
    }

    final records = await getFinance(userRole: 'admin');
    final Map<int, Map<String, dynamic>> grouped = {};
    for (var r in records) {
      final rawUid = int.tryParse(r['user_id']?.toString() ?? '') ?? 0;
      final rawUname = (r['user_name'] as String?)?.trim() ?? '';
      final uid = resolveCanonicalUid(rawUid, rawUname);
      final uname = resolveCanonicalName(uid, rawUname);
      if (!grouped.containsKey(uid)) {
        grouped[uid] = {
          'name': uname,
          'income': <Map<String, dynamic>>[],
          'expense': <Map<String, dynamic>>[],
          'advance_received': 0.0,
          'advance_deducted': 0.0,
          'advance_balance': 0.0,
          'has_advance': false,
          'advance_account_id': 0,
          'other_expenses': 0.0,
        };
      }
      final type = (r['type'] as String? ?? '').toLowerCase();
      if (type == 'income') {
        (grouped[uid]!['income'] as List).add(r);
      } else {
        (grouped[uid]!['expense'] as List).add(r);
      }
    }

    // Attach advance data to each user group
    final matchedAdvAids = <int>{};
    for (final entry in grouped.entries) {
      final g = entry.value;
      final gName = (g['name'] as String).toLowerCase();
      final gExpenses = (g['expense'] as List).cast<Map<String, dynamic>>();
      final userAccountIds = gExpenses
          .map((e) => int.tryParse(e['account_id']?.toString() ?? '') ?? 0)
          .where((id) => id > 0)
          .toSet();

      for (final s in advSummaries.values) {
        final aid = s['account_id'] as int;
        final uLabel = (s['user_label'] as String).toLowerCase();
        final accName = (s['name'] as String? ?? '').toLowerCase();
        final accCode = (s['code']?.toString() ?? '').trim();

        // Strict isolation and canonical matching for key users
        final rawUid = entry.key;
        bool isMatched = false;
        if (rawUid == 1) {
          if (accCode.contains('1502') ||
              uLabel.contains('tanzeel') ||
              uLabel.contains('تنزیل') ||
              accName.contains('tanzeel') ||
              accName.contains('تنزیل')) {
            isMatched = true;
          } else {
            continue;
          }
        } else if (rawUid == 2) {
          if (uLabel.contains('hammad') ||
              uLabel.contains('حماد') ||
              accName.contains('hammad') ||
              accName.contains('حماد')) {
            isMatched = true;
          }
        } else if (rawUid == 3) {
          if (accCode.contains('1503') ||
              uLabel.contains('saleem') ||
              uLabel.contains('سلیم') ||
              accName.contains('saleem') ||
              accName.contains('سلیم')) {
            isMatched = true;
          } else {
            continue;
          }
        } else if (rawUid == 4) {
          if (uLabel.contains('usman') ||
              uLabel.contains('عثمان') ||
              accName.contains('usman') ||
              accName.contains('عثمان')) {
            isMatched = true;
          }
        } else {
          if (accCode == '1502' ||
              accCode == '1503' ||
              uLabel.contains('tanzeel') ||
              uLabel.contains('تنزیل') ||
              uLabel.contains('saleem') ||
              uLabel.contains('سلیم')) {
            continue;
          }
        }

        isMatched = (gName == uLabel ||
            gName.contains(uLabel) ||
            uLabel.contains(gName));
        if (!isMatched) {
          final words =
              gName.split(RegExp(r'[\s\-_:]+')).where((w) => w.length >= 4);
          for (final w in words) {
            if (uLabel.contains(w) || accName.contains(w)) {
              isMatched = true;
              break;
            }
          }
        }

        if (isMatched) {
          final curRec = (g['advance_received'] as num?)?.toDouble() ?? 0.0;
          final curDed = (g['advance_deducted'] as num?)?.toDouble() ?? 0.0;
          final rec = (s['total_received'] as num?)?.toDouble() ?? 0.0;
          final ded = (s['total_deducted'] as num?)?.toDouble() ?? 0.0;
          g['advance_received'] = curRec + rec;
          g['advance_deducted'] = curDed + ded;
          g['advance_balance'] = (g['advance_received'] as double) -
              (g['advance_deducted'] as double);
          g['has_advance'] = true;
          if ((g['advance_account_id'] as int? ?? 0) == 0) {
            g['advance_account_id'] = aid;
          }
          matchedAdvAids.add(aid);
        }
      }

      // Dual-layer in-memory calculation of advance expenses for this user
      double inMemAdvDed = 0.0;
      double totalUserExp = 0.0;
      for (final exp in gExpenses) {
        final amt = double.tryParse(exp['amount']?.toString() ?? '0') ?? 0.0;
        totalUserExp += amt;
        final payMeth = (exp['payment_method']?.toString() ?? '').toLowerCase();
        final expAid = int.tryParse(exp['account_id']?.toString() ?? '') ?? 0;
        final desc = (exp['description']?.toString() ?? '').toLowerCase();
        final hName = (exp['head_name']?.toString() ?? '').toLowerCase();
        final aName = (exp['account_name']?.toString() ?? '').toLowerCase();

        bool isAdv = false;
        if (payMeth == 'advance' || payMeth.contains('ایڈوانس')) {
          isAdv = true;
        } else if (userAccountIds.contains(expAid) &&
            matchedAdvAids.contains(expAid)) {
          isAdv = true;
        } else if (aName.contains('ایڈوانس') ||
            aName.contains('advance') ||
            desc.contains('ایڈوانس') ||
            desc.contains('advance') ||
            hName.contains('ایڈوانس')) {
          isAdv = true;
        } else if (g['has_advance'] == true &&
            ((g['advance_received'] as num?)?.toDouble() ?? 0.0) > 0 &&
            (expAid == 0 || expAid == (g['advance_account_id'] as int? ?? 0))) {
          isAdv = true;
        }

        if (isAdv) {
          inMemAdvDed += amt;
        }
      }

      if (g['has_advance'] == true) {
        final curDed = (g['advance_deducted'] as num?)?.toDouble() ?? 0.0;
        final effectiveDed = inMemAdvDed > curDed ? inMemAdvDed : curDed;
        final rec = (g['advance_received'] as num?)?.toDouble() ?? 0.0;
        g['advance_deducted'] = effectiveDed;
        g['advance_balance'] = rec - effectiveDed;
        final otherExp = totalUserExp - effectiveDed;
        g['other_expenses'] = otherExp > 0 ? otherExp : 0.0;
      } else {
        g['other_expenses'] = totalUserExp;
      }
    }

    // Ensure any advance user without finance records is also displayed,
    // BUT strictly prevent creating duplicate cards for existing canonical users!
    int syntheticUid = -100;
    for (final s in advSummaries.values) {
      final aid = s['account_id'] as int;
      final uLabel = (s['user_label'] as String).toLowerCase();
      final accName = (s['name'] as String? ?? '').toLowerCase();
      final accCode = (s['code']?.toString() ?? '').trim();

      bool belongsToCanonical = uLabel.contains('tanzeel') ||
          uLabel.contains('تنزیل') ||
          accName.contains('tanzeel') ||
          accName.contains('تنزیل') ||
          accCode.contains('1502') ||
          uLabel.contains('hammad') ||
          uLabel.contains('حماد') ||
          accName.contains('hammad') ||
          accName.contains('حماد') ||
          uLabel.contains('saleem') ||
          uLabel.contains('سلیم') ||
          accName.contains('saleem') ||
          accName.contains('سلیم') ||
          accCode.contains('1503') ||
          uLabel.contains('usman') ||
          uLabel.contains('عثمان') ||
          accName.contains('usman') ||
          accName.contains('عثمان');

      if (belongsToCanonical) {
        continue;
      }

      if (!matchedAdvAids.contains(aid) &&
          (s['total_received'] as double) > 0) {
        grouped[syntheticUid--] = {
          'name': s['user_label'],
          'income': <Map<String, dynamic>>[],
          'expense': <Map<String, dynamic>>[],
          'advance_received': s['total_received'],
          'advance_deducted': s['total_deducted'],
          'advance_balance': s['net_balance'],
          'has_advance': true,
          'advance_account_id': aid,
          'other_expenses': 0.0,
        };
      }
    }

    return grouped;
  }

  Future<int> insertFinance(Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['system_type'] =
        data['system_type'] ?? 'Madrasa'; // مدرسہ فنانس کے لیے ہمیشہ Madrasa
    final id = await db.insert('finance', data,
        conflictAlgorithm: ConflictAlgorithm.replace);
    try {
      await _postSimpleFinanceToAccounts(db, id);
    } catch (e) {
      debugPrint('Warning: Could not mirror finance entry to accounts: $e');
    }
    return id;
  }

  Future<int> updateFinance(int id, Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    final result =
        await db.update('finance', data, where: 'id = ?', whereArgs: [id]);
    try {
      await _postSimpleFinanceToAccounts(db, id);
    } catch (e) {
      debugPrint(
          'Warning: Could not mirror updated finance entry to accounts: $e');
    }
    return result;
  }

  Future<int> deleteFinance(int id) async {
    Database db = await database;
    final result = await db.update('finance',
        {'is_deleted': 1, 'computer_receipt_no': null, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
    try {
      await _deleteSimpleFinanceVoucher(db, id);
    } catch (e) {
      debugPrint('Warning: Could not delete voucher for finance $id: $e');
    }
    return result;
  }

  Future<void> _ensureDefaultAccountingAccounts(DatabaseExecutor db) async {
    await _ensureAccount(db, '1001', 'Cash in Hand', 'Asset');
    await _ensureAccount(db, '4002', 'Donation / Sadqa', 'Revenue');
    await _ensureAccount(db, '5003', 'Maintenance', 'Expense');
  }

  Future<int> _ensureAccount(
    DatabaseExecutor db,
    String code,
    String name,
    String type,
  ) async {
    final existing = await db.query(
      'acc_chart_of_accounts',
      columns: ['id'],
      where: 'code = ?',
      whereArgs: [code],
      limit: 1,
    );
    if (existing.isNotEmpty) {
      return int.tryParse(existing.first['id'].toString()) ?? 0;
    }
    return db.insert('acc_chart_of_accounts', {
      'code': code,
      'name': name,
      'type': type,
      'is_deleted': 0,
      'is_synced': 1,
    });
  }

  String _simpleFinanceReference(int financeId) => 'FIN-$financeId';

  String _simpleFinanceVoucherNo(Map<String, dynamic> finance, int financeId) {
    final type = (finance['type'] ?? '').toString().toLowerCase();
    final prefix = type == 'expense' ? 'SF-PV' : 'SF-RV';
    return '$prefix-${financeId.toString().padLeft(6, '0')}';
  }

  Future<void> _postSimpleFinanceToAccounts(
    Database db,
    int financeId,
  ) async {
    await _createAccountingTables(db);
    await _ensureDefaultAccountingAccounts(db);

    final rows = await db.query(
      'finance',
      where: 'id = ?',
      whereArgs: [financeId],
      limit: 1,
    );
    if (rows.isEmpty) return;
    final finance = rows.first;

    if ((int.tryParse(finance['is_deleted']?.toString() ?? '0') ?? 0) == 1) {
      await _deleteSimpleFinanceVoucher(db, financeId);
      return;
    }

    final amount = double.tryParse(finance['amount']?.toString() ?? '0') ?? 0;
    if (amount <= 0) {
      await _deleteSimpleFinanceVoucher(db, financeId);
      return;
    }

    final type = (finance['type'] ?? '').toString().toLowerCase();
    final isExpense = type == 'expense';
    final date = (finance['date'] ?? '').toString();
    final reference = _simpleFinanceReference(financeId);
    final voucherNo = _simpleFinanceVoucherNo(finance, financeId);
    final description = (finance['description'] ?? '').toString().trim();
    final createdBy = int.tryParse(finance['user_id']?.toString() ?? '') ?? 1;
    final cashId = await _ensureAccount(db, '1001', 'Cash in Hand', 'Asset');
    final assetAccountId =
        int.tryParse(finance['account_id']?.toString() ?? '') ?? cashId;
    final offsetId = isExpense
        ? await _ensureAccount(db, '5003', 'Maintenance', 'Expense')
        : await _ensureAccount(db, '4002', 'Donation / Sadqa', 'Revenue');

    await db.transaction((txn) async {
      final existing = await txn.query(
        'acc_vouchers',
        columns: ['id'],
        where: 'reference = ?',
        whereArgs: [reference],
        limit: 1,
      );

      int voucherId;
      final voucherData = {
        'voucher_no': voucherNo,
        'date': date,
        'type': isExpense ? 'Payment' : 'Receipt',
        'reference': reference,
        'description': description.isEmpty
            ? (isExpense ? 'Simple finance expense' : 'Simple finance income')
            : description,
        'status': 'Approved',
        'created_by': createdBy,
        'system_type': (finance['system_type'] ?? '').toString().isNotEmpty
            ? finance['system_type']
            : SystemTypeGlobal.currentSystemType.value,
        'is_deleted': 0,
        'is_synced': 1,
      };

      if (existing.isNotEmpty) {
        voucherId = int.tryParse(existing.first['id'].toString()) ?? 0;
        await txn.update(
          'acc_vouchers',
          voucherData,
          where: 'id = ?',
          whereArgs: [voucherId],
        );
        await txn.delete(
          'acc_ledger_entries',
          where: 'voucher_id = ?',
          whereArgs: [voucherId],
        );
      } else {
        voucherId = await txn.insert('acc_vouchers', voucherData);
      }

      final lines = isExpense
          ? [
              {'account_id': offsetId, 'debit': amount, 'credit': 0.0},
              {'account_id': assetAccountId, 'debit': 0.0, 'credit': amount},
            ]
          : [
              {'account_id': assetAccountId, 'debit': amount, 'credit': 0.0},
              {'account_id': offsetId, 'debit': 0.0, 'credit': amount},
            ];

      for (final line in lines) {
        await txn.insert('acc_ledger_entries', {
          'voucher_id': voucherId,
          'account_id': line['account_id'],
          'debit': line['debit'],
          'credit': line['credit'],
          'is_synced': 1,
        });
      }
    });
  }

  Future<void> _deleteSimpleFinanceVoucher(Database db, int financeId) async {
    await _createAccountingTables(db);
    await db.update(
      'acc_vouchers',
      {'is_deleted': 1, 'is_synced': 1},
      where: 'reference = ?',
      whereArgs: [_simpleFinanceReference(financeId)],
    );
  }

  Future<void> ensureSimpleFinanceAccountingBackfill({
    String? fromDate,
    String? toDate,
  }) async {
    final db = await database;
    await _backfillSimpleFinanceToAccounts(
      db,
      fromDate: fromDate,
      toDate: toDate,
    );
  }

  Future<void> _backfillSimpleFinanceToAccounts(
    Database db, {
    String? fromDate,
    String? toDate,
  }) async {
    await _createAccountingTables(db);

    // Sync deleted status of finance vouchers
    await db.rawUpdate('''
      UPDATE acc_vouchers
      SET is_deleted = 1
      WHERE reference LIKE 'FIN-%'
      AND CAST(SUBSTR(reference, 5) AS INTEGER) IN (
        SELECT id FROM finance WHERE is_deleted = 1
      )
    ''');

    // Clean orphan/unlinked FIN- vouchers whose finance record is deleted or missing
    await db.rawUpdate('''
      UPDATE acc_vouchers
      SET is_deleted = 1
      WHERE reference LIKE 'FIN-%'
      AND CAST(SUBSTR(reference, 5) AS INTEGER) NOT IN (
        SELECT id FROM finance WHERE (is_deleted = 0 OR is_deleted IS NULL)
      )
    ''');

    final where = <String>['(is_deleted = 0 OR is_deleted IS NULL)'];
    final args = <Object?>[];
    if (fromDate != null && fromDate.isNotEmpty) {
      where.add('date >= ?');
      args.add(fromDate);
    }
    if (toDate != null && toDate.isNotEmpty) {
      where.add('date <= ?');
      args.add(toDate);
    }
    final rows = await db.query(
      'finance',
      columns: ['id'],
      where: where.join(' AND '),
      whereArgs: args,
      orderBy: 'id ASC',
    );
    for (final row in rows) {
      final id = int.tryParse(row['id']?.toString() ?? '') ?? 0;
      if (id > 0) await _postSimpleFinanceToAccounts(db, id);
    }
  }

  Future<int> insertHead(Map<String, dynamic> row) async {
    Database db = await database;
    await _createAccountingTables(db);
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    final name = (data['name'] ?? '').toString().trim();
    final type = (data['type'] ?? 'Income').toString();
    final headId = await db.insert('heads', data,
        conflictAlgorithm: ConflictAlgorithm.replace);

    if (name.isNotEmpty) {
      try {
        final existingChart = await db.query('acc_chart_of_accounts',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existingChart.isEmpty) {
          final isInc = type.toLowerCase() == 'income' ||
              type.toLowerCase().contains('revenue');
          final chartType = isInc ? 'Revenue' : 'Expense';
          final code = '${isInc ? '4' : '5'}${1000 + headId}';
          await db.insert('acc_chart_of_accounts', {
            'code': code,
            'name': name,
            'type': chartType,
            'system_type': 'Madrasa',
            'is_deleted': 0,
            'is_synced': 0
          });
        }
      } catch (_) {}
    }
    return headId;
  }

  Future<int> updateHead(int id, Map<String, dynamic> row) async {
    Database db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.update('heads', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteHead(int id) async {
    Database db = await database;
    return await db.update('heads', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getHeads() async {
    Database db = await database;
    await _createAccountingTables(db);
    await _addColumnIfNotExists(db, 'heads', 'is_deleted', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(db, 'heads', 'is_synced', 'INTEGER DEFAULT 0');
    await syncLocalHeadsFromChartAndDepartments(db);

    // 1. Fetch from heads table
    final headsList = await db.query('heads',
        where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'name ASC');

    // 2. Fetch directly from acc_chart_of_accounts to guarantee immediate availability
    List<Map<String, dynamic>> chartList = [];
    try {
      chartList = await db.query('acc_chart_of_accounts',
          where: 'is_deleted = 0 OR is_deleted IS NULL', orderBy: 'name ASC');
    } catch (_) {}

    final Map<String, Map<String, dynamic>> merged = {};
    for (var h in headsList) {
      final n = (h['name'] ?? '').toString().trim();
      if (n.isNotEmpty) {
        merged[n.toLowerCase()] = Map<String, dynamic>.from(h);
      }
    }

    for (var ca in chartList) {
      final n = (ca['name'] ?? '').toString().trim();
      if (n.isEmpty) continue;
      final rawType = (ca['type'] ?? '').toString().toLowerCase();
      final isRev = rawType == 'income' || rawType.contains('revenue');
      final isExp = rawType == 'expense';
      if (!isRev && !isExp) continue; // Only revenue and expense!

      if (!merged.containsKey(n.toLowerCase())) {
        merged[n.toLowerCase()] = {
          'id': ca['id'],
          'name': n,
          'type': isRev ? 'Income' : 'Expense',
          'is_deleted': 0,
          'is_synced': 1,
        };
      }
    }

    final result = merged.values.toList();
    result.sort((a, b) =>
        (a['name'] ?? '').toString().compareTo((b['name'] ?? '').toString()));
    return result;
  }

  Future<void> syncLocalHeadsFromChartAndDepartments(Database db) async {
    try {
      // 1. Deduplicate acc_chart_of_accounts by normalized name
      final dupAccounts = await db.rawQuery('''
        SELECT LOWER(TRIM(name)) as norm_name, COUNT(*) as cnt, MIN(id) as keep_id
        FROM acc_chart_of_accounts
        WHERE (is_deleted = 0 OR is_deleted IS NULL) AND name IS NOT NULL AND TRIM(name) != ''
        GROUP BY LOWER(TRIM(name))
        HAVING cnt > 1
      ''');
      for (var dup in dupAccounts) {
        final norm = dup['norm_name'] as String;
        final keepId = dup['keep_id'] as int;
        await db.update(
          'acc_chart_of_accounts',
          {'is_deleted': 1, 'is_synced': 0},
          where: 'LOWER(TRIM(name)) = ? AND id != ?',
          whereArgs: [norm, keepId],
        );
      }

      // 2. Deduplicate heads by normalized name
      final dupHeads = await db.rawQuery('''
        SELECT LOWER(TRIM(name)) as norm_name, COUNT(*) as cnt, MIN(id) as keep_id
        FROM heads
        WHERE (is_deleted = 0 OR is_deleted IS NULL) AND name IS NOT NULL AND TRIM(name) != ''
        GROUP BY LOWER(TRIM(name))
        HAVING cnt > 1
      ''');
      for (var dup in dupHeads) {
        final norm = dup['norm_name'] as String;
        final keepId = dup['keep_id'] as int;
        await db.update(
          'heads',
          {'is_deleted': 1, 'is_synced': 0},
          where: 'LOWER(TRIM(name)) = ? AND id != ?',
          whereArgs: [norm, keepId],
        );
      }

      // 3. Sync acc_chart_of_accounts -> heads (Only Revenue and Expense accounts)
      final chartAccounts = await db.query('acc_chart_of_accounts',
          where: 'is_deleted = 0 OR is_deleted IS NULL');
      for (final ca in chartAccounts) {
        final name = (ca['name'] ?? '').toString().trim();
        if (name.isEmpty) continue;
        final rawType = (ca['type'] ?? '').toString().toLowerCase();
        final isRev = rawType == 'income' || rawType.contains('revenue');
        final isExp = rawType == 'expense';
        if (!isRev && !isExp) continue; // Skip Asset, Liability, Equity!
        final type = isRev ? 'Income' : 'Expense';
        final existing = await db.query('heads',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existing.isEmpty) {
          await db.insert('heads',
              {'name': name, 'type': type, 'is_deleted': 0, 'is_synced': 0});
        }
      }

      // 4. Sync heads -> acc_chart_of_accounts
      final allHeads = await db.query('heads',
          where: 'is_deleted = 0 OR is_deleted IS NULL');
      for (final h in allHeads) {
        final name = (h['name'] ?? '').toString().trim();
        if (name.isEmpty) continue;
        final rawType = (h['type'] ?? '').toString().toLowerCase();
        final isInc = (rawType == 'income' || rawType.contains('revenue'));
        final chartType = isInc ? 'Revenue' : 'Expense';
        final existing = await db.query('acc_chart_of_accounts',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existing.isEmpty) {
          final code = await getNextChartCode(chartType);
          await db.insert('acc_chart_of_accounts', {
            'code': code,
            'name': name,
            'type': chartType,
            'system_type': 'Madrasa',
            'is_deleted': 0,
            'is_synced': 0
          });
        }
      }

      // 5. Sync departments -> heads & acc_chart_of_accounts
      final depts = await db.query('departments');
      for (final d in depts) {
        final name = (d['name'] ?? '').toString().trim();
        if (name.isEmpty) continue;
        final existingH = await db.query('heads',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existingH.isEmpty) {
          await db.insert('heads', {
            'name': name,
            'type': 'Expense',
            'is_deleted': 0,
            'is_synced': 0
          });
        }
        final existingC = await db.query('acc_chart_of_accounts',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existingC.isEmpty) {
          final code = await getNextChartCode('Expense');
          await db.insert('acc_chart_of_accounts', {
            'code': code,
            'name': name,
            'type': 'Expense',
            'system_type': 'Madrasa',
            'is_deleted': 0,
            'is_synced': 0
          });
        }
      }
    } catch (e) {
      // Ignore
    }
  }

  Future<String> getNextChartCode(String type) async {
    Database db = await database;
    int prefix = 1;
    int start = 1001;
    final rawType = type.trim();
    if (rawType == 'Asset') {
      prefix = 1;
      start = 1001;
    } else if (rawType == 'Liability') {
      prefix = 2;
      start = 2001;
    } else if (rawType == 'Equity') {
      prefix = 3;
      start = 3001;
    } else if (rawType == 'Revenue' || rawType == 'Income') {
      prefix = 4;
      start = 4001;
    } else if (rawType == 'Expense') {
      prefix = 5;
      start = 5001;
    }

    final min = prefix * 1000;
    final max = (prefix * 1000) + 998;

    try {
      final res = await db.rawQuery('''
        SELECT code FROM acc_chart_of_accounts
        WHERE (is_deleted = 0 OR is_deleted IS NULL)
      ''');
      int maxCode = start - 1;
      for (final row in res) {
        final codeStr = (row['code'] ?? '').toString().trim();
        final codeNum = int.tryParse(codeStr);
        if (codeNum != null && codeNum >= min && codeNum <= max) {
          if (codeNum > maxCode) {
            maxCode = codeNum;
          }
        }
      }
      return (maxCode + 1).toString();
    } catch (_) {
      return start.toString();
    }
  }

  Future<void> resequenceChartOfAccounts() async {
    try {
      Database db = await database;
      final types = {
        'Asset': 1001,
        'Liability': 2001,
        'Equity': 3001,
        'Revenue': 4001,
        'Expense': 5001,
      };

      for (final entry in types.entries) {
        final type = entry.key;
        final startCode = entry.value;
        final accounts = await db.rawQuery('''
          SELECT id, code, name FROM acc_chart_of_accounts
          WHERE (is_deleted = 0 OR is_deleted IS NULL)
          AND (type = ? OR (type = 'Income' AND ? = 'Revenue'))
          AND code != '3999'
          ORDER BY id ASC
        ''', [type, type]);

        int curr = startCode;
        for (final acc in accounts) {
          final id = acc['id'] as int;
          await db.update(
              'acc_chart_of_accounts',
              {
                'code': curr.toString(),
                'is_synced': 0,
              },
              where: 'id = ?',
              whereArgs: [id]);
          curr++;
        }
      }
    } catch (_) {}
  }

  Future<List<Map<String, dynamic>>> getAccChartAccounts() async {
    Database db = await database;
    await _createAccountingTables(db);
    await _ensureDefaultAccountingAccounts(db);
    await syncLocalHeadsFromChartAndDepartments(db);
    final accounts = await db.rawQuery('''
      SELECT c.*, p.name as parent_name, p.code as parent_code
      FROM acc_chart_of_accounts c
      LEFT JOIN acc_chart_of_accounts p ON c.parent_id = p.id
      WHERE (c.is_deleted = 0 OR c.is_deleted IS NULL)
      ORDER BY c.type ASC, c.code ASC, c.name ASC
    ''');
    return accounts;
  }

  Future<int> insertAccChartAccount(Map<String, dynamic> row) async {
    Database db = await database;
    await _createAccountingTables(db);
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    if ((data['code'] ?? '').toString().trim().isEmpty) {
      data['code'] =
          await getNextChartCode((data['type'] ?? 'Expense').toString());
    }
    final chartId = await db.insert('acc_chart_of_accounts', data);
    final name = (data['name'] ?? '').toString().trim();
    final rawType = (data['type'] ?? '').toString().toLowerCase();
    if (name.isNotEmpty) {
      try {
        final existingHead = await db.query('heads',
            where:
                'LOWER(TRIM(name)) = LOWER(TRIM(?)) AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [name]);
        if (existingHead.isEmpty) {
          final type = (rawType == 'income' || rawType.contains('revenue'))
              ? 'Income'
              : 'Expense';
          await db.insert('heads', {
            'name': name,
            'type': type,
            'is_deleted': 0,
            'is_synced': 0,
          });
        }
      } catch (_) {}
    }
    return chartId;
  }

  Future<int> updateAccChartAccount(int id, Map<String, dynamic> row) async {
    Database db = await database;
    await _createAccountingTables(db);
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return db.update('acc_chart_of_accounts', data,
        where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteAccChartAccount(int id) async {
    Database db = await database;
    await _createAccountingTables(db);
    return db.update(
      'acc_chart_of_accounts',
      {'is_deleted': 1, 'is_synced': 0},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  Future<List<Map<String, dynamic>>> getAccVouchersWithTotals(
      {String? systemType}) async {
    Database db = await database;
    await _createAccountingTables(db);
    await _backfillSimpleFinanceToAccounts(db);
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "v.system_type = 'Masjid'"
        : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
    return db.rawQuery('''
      SELECT v.*,
        COALESCE(SUM(e.debit), 0) AS total_debit,
        COALESCE(SUM(e.credit), 0) AS total_credit
      FROM acc_vouchers v
      LEFT JOIN acc_ledger_entries e ON e.voucher_id = v.id
      WHERE (v.is_deleted = 0 OR v.is_deleted IS NULL)
        AND $sysCond
      GROUP BY v.id
      ORDER BY v.date DESC, v.id DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getAccVoucherEntries(int voucherId) async {
    Database db = await database;
    await _createAccountingTables(db);
    return db.rawQuery('''
      SELECT e.*, a.code, a.name AS account_name
      FROM acc_ledger_entries e
      LEFT JOIN acc_chart_of_accounts a ON a.id = e.account_id
      WHERE e.voucher_id = ?
      ORDER BY e.id ASC
    ''', [voucherId]);
  }

  Future<Map<String, dynamic>> getGeneralLedger({
    required int accountId,
    required String fromDate,
    required String toDate,
    String? systemType,
  }) async {
    Database db = await database;
    await _createAccountingTables(db);
    await _backfillSimpleFinanceToAccounts(db, toDate: toDate);
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "v.system_type = 'Masjid'"
        : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
    final accounts = await db.query(
      'acc_chart_of_accounts',
      where: 'id = ?',
      whereArgs: [accountId],
      limit: 1,
    );
    if (accounts.isEmpty) {
      return {
        'account': null,
        'opening_balance': 0.0,
        'entries': <Map<String, dynamic>>[],
        'total_debit': 0.0,
        'total_credit': 0.0,
        'closing_balance': 0.0,
      };
    }
    final account = accounts.first;
    final type = account['type']?.toString() ?? '';
    final isDebitNormal = type == 'Asset' || type == 'Expense';

    final openingRows = await db.rawQuery('''
      SELECT COALESCE(SUM(e.debit), 0) AS total_dr,
             COALESCE(SUM(e.credit), 0) AS total_cr
      FROM acc_ledger_entries e
      JOIN acc_vouchers v ON e.voucher_id = v.id
      WHERE e.account_id = ?
        AND v.date < ?
        AND v.status != 'Rejected'
        AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        AND $sysCond
    ''', [accountId, fromDate]);
    final openingDebit =
        double.tryParse(openingRows.first['total_dr']?.toString() ?? '0') ?? 0;
    final openingCredit =
        double.tryParse(openingRows.first['total_cr']?.toString() ?? '0') ?? 0;
    final opening = isDebitNormal
        ? (openingDebit - openingCredit)
        : (openingCredit - openingDebit);

    final rows = await db.rawQuery('''
      SELECT e.*, v.date, v.voucher_no, v.description AS voucher_description,
             v.type AS voucher_type, v.reference
      FROM acc_ledger_entries e
      JOIN acc_vouchers v ON e.voucher_id = v.id
      WHERE e.account_id = ?
        AND v.date BETWEEN ? AND ?
        AND v.status != 'Rejected'
        AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        AND $sysCond
      ORDER BY v.date ASC, v.id ASC, e.id ASC
    ''', [accountId, fromDate, toDate]);

    var running = opening;
    var totalDebit = 0.0;
    var totalCredit = 0.0;
    final entries = <Map<String, dynamic>>[];
    for (final row in rows) {
      final debit = double.tryParse(row['debit']?.toString() ?? '0') ?? 0;
      final credit = double.tryParse(row['credit']?.toString() ?? '0') ?? 0;
      totalDebit += debit;
      totalCredit += credit;
      running += isDebitNormal ? (debit - credit) : (credit - debit);
      entries.add({
        ...row,
        'running_balance': running,
        'balance_side': running >= 0
            ? (isDebitNormal ? 'Dr' : 'Cr')
            : (isDebitNormal ? 'Cr' : 'Dr'),
      });
    }

    return {
      'account': account,
      'opening_balance': opening,
      'opening_side': opening >= 0
          ? (isDebitNormal ? 'Dr' : 'Cr')
          : (isDebitNormal ? 'Cr' : 'Dr'),
      'entries': entries,
      'total_debit': totalDebit,
      'total_credit': totalCredit,
      'closing_balance': running,
      'closing_side': running >= 0
          ? (isDebitNormal ? 'Dr' : 'Cr')
          : (isDebitNormal ? 'Cr' : 'Dr'),
    };
  }

  Future<List<Map<String, dynamic>>> getTrialBalance(String asOfDate,
      {String? systemType}) async {
    Database db = await database;
    await _createAccountingTables(db);
    await _backfillSimpleFinanceToAccounts(db, toDate: asOfDate);
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "v.system_type = 'Masjid'"
        : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
    final rows = await db.rawQuery('''
      SELECT a.id, a.code, a.name, a.type,
             COALESCE(SUM(CASE WHEN (v.status IS NULL OR v.status != 'Rejected') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sysCond AND v.date <= ? THEN e.debit ELSE 0 END), 0) AS total_debit,
             COALESCE(SUM(CASE WHEN (v.status IS NULL OR v.status != 'Rejected') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sysCond AND v.date <= ? THEN e.credit ELSE 0 END), 0) AS total_credit
      FROM acc_chart_of_accounts a
      LEFT JOIN acc_ledger_entries e ON a.id = e.account_id
      LEFT JOIN acc_vouchers v ON e.voucher_id = v.id AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sysCond
      WHERE (a.is_deleted = 0 OR a.is_deleted IS NULL)
      GROUP BY a.id, a.code, a.name, a.type
      ORDER BY a.type ASC, a.code ASC
    ''', [asOfDate, asOfDate]);

    final result = <Map<String, dynamic>>[];
    for (final row in rows) {
      final debit = double.tryParse(row['total_debit']?.toString() ?? '0') ?? 0;
      final credit =
          double.tryParse(row['total_credit']?.toString() ?? '0') ?? 0;
      final type = row['type']?.toString() ?? '';
      final isDebitNormal = type == 'Asset' || type == 'Expense';
      final balance = isDebitNormal ? debit - credit : credit - debit;
      if (balance.abs() <= 0.01) continue;
      result.add({
        ...row,
        'debit_balance': balance > 0 && isDebitNormal
            ? balance
            : (balance < 0 && !isDebitNormal ? balance.abs() : 0.0),
        'credit_balance': balance > 0 && !isDebitNormal
            ? balance
            : (balance < 0 && isDebitNormal ? balance.abs() : 0.0),
      });
    }
    return result;
  }

  Future<Map<String, dynamic>> getIncomeStatement({
    required String fromDate,
    required String toDate,
    int? departmentId,
    String? systemType,
  }) async {
    Database db = await database;
    await _createAccountingTables(db);
    await _backfillSimpleFinanceToAccounts(
      db,
      fromDate: fromDate,
      toDate: toDate,
    );
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "v.system_type = 'Masjid'"
        : "(v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
    final params = <Object?>[fromDate, toDate];
    var deptWhere = '';
    if (departmentId != null) {
      deptWhere = ' AND e.department_id = ?';
      params.add(departmentId);
    }
    final rows = await db.rawQuery('''
      SELECT a.id, a.code, a.name, a.type,
             COALESCE(SUM(e.debit), 0) AS total_debit,
             COALESCE(SUM(e.credit), 0) AS total_credit
      FROM acc_chart_of_accounts a
      JOIN acc_ledger_entries e ON a.id = e.account_id
      JOIN acc_vouchers v ON e.voucher_id = v.id AND (v.status IS NULL OR v.status != 'Rejected') AND (v.is_deleted = 0 OR v.is_deleted IS NULL) AND $sysCond
      WHERE a.type IN ('Revenue', 'Expense')
        AND v.date BETWEEN ? AND ?
        $deptWhere
      GROUP BY a.id, a.code, a.name, a.type
      ORDER BY a.type DESC, a.code ASC
    ''', params);

    final revenues = <Map<String, dynamic>>[];
    final expenses = <Map<String, dynamic>>[];
    var totalRevenue = 0.0;
    var totalExpense = 0.0;
    for (final row in rows) {
      final debit = double.tryParse(row['total_debit']?.toString() ?? '0') ?? 0;
      final credit =
          double.tryParse(row['total_credit']?.toString() ?? '0') ?? 0;
      final type = row['type']?.toString() ?? '';
      if (type == 'Revenue') {
        final balance = credit - debit;
        if (balance.abs() > 0.01) {
          revenues.add({...row, 'balance': balance});
          totalRevenue += balance;
        }
      } else if (type == 'Expense') {
        final balance = debit - credit;
        if (balance.abs() > 0.01) {
          expenses.add({...row, 'balance': balance});
          totalExpense += balance;
        }
      }
    }
    return {
      'revenues': revenues,
      'expenses': expenses,
      'total_revenue': totalRevenue,
      'total_expense': totalExpense,
      'net_income': totalRevenue - totalExpense,
    };
  }

  Future<String> nextAccVoucherNo(String type) async {
    Database db = await database;
    await _createAccountingTables(db);
    final prefix =
        '${type.isEmpty ? 'J' : type.substring(0, 1).toUpperCase()}V-';
    final count = Sqflite.firstIntValue(await db.rawQuery(
          'SELECT COUNT(*) FROM acc_vouchers WHERE type = ?',
          [type],
        )) ??
        0;
    return '$prefix${(count + 1).toString().padLeft(5, '0')}';
  }

  Future<int> insertAccVoucherWithEntries({
    required Map<String, dynamic> voucher,
    required List<Map<String, dynamic>> entries,
  }) async {
    Database db = await database;
    await _createAccountingTables(db);
    final validLines = <Map<String, dynamic>>[];
    for (final entry in entries) {
      final debit = double.tryParse(entry['debit']?.toString() ?? '0') ?? 0;
      final credit = double.tryParse(entry['credit']?.toString() ?? '0') ?? 0;
      final accountId =
          int.tryParse(entry['account_id']?.toString() ?? '') ?? 0;
      if (accountId <= 0 && (debit > 0 || credit > 0)) {
        throw Exception('رقم والی ledger line میں اکاؤنٹ منتخب کرنا ضروری ہے۔');
      }
      if (debit > 0 && credit > 0) {
        throw Exception(
            'ایک ledger line میں debit اور credit دونوں نہیں ہو سکتے۔');
      }
      if (accountId > 0 && (debit > 0 || credit > 0)) {
        validLines.add(entry);
      }
    }
    if (validLines.length < 2) {
      throw Exception('کم از کم دو درست ledger lines ضروری ہیں۔');
    }
    final totalDebit = validLines.fold<double>(
      0,
      (sum, e) => sum + (double.tryParse(e['debit']?.toString() ?? '0') ?? 0),
    );
    final totalCredit = validLines.fold<double>(
      0,
      (sum, e) => sum + (double.tryParse(e['credit']?.toString() ?? '0') ?? 0),
    );
    if ((totalDebit - totalCredit).abs() > 0.01 || totalDebit <= 0) {
      throw Exception('Debit اور Credit برابر ہونا ضروری ہے۔');
    }

    final voucherType = voucher['type']?.toString() ?? 'Journal';
    final voucherNo = voucher['voucher_no']?.toString().isNotEmpty == true
        ? voucher['voucher_no'].toString()
        : await nextAccVoucherNo(voucherType);

    return db.transaction<int>((txn) async {
      final data = Map<String, dynamic>.from(voucher);
      data['voucher_no'] = voucherNo;
      data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
      data['is_synced'] = 0;
      final voucherId = await txn.insert('acc_vouchers', data);
      for (final entry in validLines) {
        final line = Map<String, dynamic>.from(entry);
        line['voucher_id'] = voucherId;
        line['department_id'] ??= data['department_id'];
        line['is_synced'] = 0;
        await txn.insert('acc_ledger_entries', line);
      }
      return voucherId;
    });
  }

  Future<int> updateAccVoucherWithEntries({
    required int voucherId,
    required Map<String, dynamic> voucher,
    required List<Map<String, dynamic>> entries,
  }) async {
    Database db = await database;
    await _createAccountingTables(db);
    final validLines = <Map<String, dynamic>>[];
    for (final entry in entries) {
      final debit = double.tryParse(entry['debit']?.toString() ?? '0') ?? 0;
      final credit = double.tryParse(entry['credit']?.toString() ?? '0') ?? 0;
      final accountId =
          int.tryParse(entry['account_id']?.toString() ?? '') ?? 0;
      if (accountId <= 0 && (debit > 0 || credit > 0)) {
        throw Exception('رقم والی ledger line میں اکاؤنٹ منتخب کرنا ضروری ہے۔');
      }
      if (debit > 0 && credit > 0) {
        throw Exception(
            'ایک ledger line میں debit اور credit دونوں نہیں ہو سکتے۔');
      }
      if (accountId > 0 && (debit > 0 || credit > 0)) {
        validLines.add(entry);
      }
    }
    if (validLines.length < 2) {
      throw Exception('کم از کم دو درست ledger lines ضروری ہیں۔');
    }
    final totalDebit = validLines.fold<double>(
      0,
      (sum, e) => sum + (double.tryParse(e['debit']?.toString() ?? '0') ?? 0),
    );
    final totalCredit = validLines.fold<double>(
      0,
      (sum, e) => sum + (double.tryParse(e['credit']?.toString() ?? '0') ?? 0),
    );
    if (totalDebit <= 0 || (totalDebit - totalCredit).abs() > 0.01) {
      throw Exception(
          'Debits اور Credits کا برابر ہونا ضروری ہے۔ (Dr: $totalDebit, Cr: $totalCredit)');
    }

    return await db.transaction((txn) async {
      var data = Map<String, dynamic>.from(voucher);
      data['is_synced'] = 0;

      await txn.update('acc_vouchers', data,
          where: 'id = ?', whereArgs: [voucherId]);
      await txn.delete('acc_ledger_entries',
          where: 'voucher_id = ?', whereArgs: [voucherId]);

      for (final entry in validLines) {
        var line = Map<String, dynamic>.from(entry);
        line['voucher_id'] = voucherId;
        line['department_id'] ??= data['department_id'];
        line['is_synced'] = 0;
        await txn.insert('acc_ledger_entries', line);
      }
      return voucherId;
    });
  }

  Future<Map<int, Map<String, dynamic>>> getAllUserAdvanceSummaries(
      {String? systemType}) async {
    final db = await database;
    await _createAccountingTables(db);

    // Ensure 4 canonical users' chart of accounts consolidation & data reconciliation in SQLite
    try {
      // 1. Tanzeel: code 1502 (merge 1632 and all Tanzeel duplicates into 1502)
      final tanRows = await db.query('acc_chart_of_accounts',
          where:
              "code = '1502' OR ((name LIKE '%تنزیل%' OR name LIKE '%tanzeel%' OR name LIKE '%Tanzeel%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))",
          orderBy: "code = '1502' DESC");
      int tanAid = tanRows.isNotEmpty
          ? (int.tryParse(tanRows.first['id']?.toString() ?? '') ?? 0)
          : 0;
      if (tanAid == 0) {
        tanAid = await db.insert('acc_chart_of_accounts', {
          'code': '1502',
          'name': 'خرچ کھاتہ - تنزیل الرحمن',
          'type': 'Asset',
          'is_active': 1,
          'is_deleted': 0,
        });
      } else {
        await db.update(
            'acc_chart_of_accounts',
            {
              'code': '1502',
              'name': 'خرچ کھاتہ - تنزیل الرحمن',
              'is_active': 1,
              'is_deleted': 0,
            },
            where: 'id = ?',
            whereArgs: [tanAid]);
      }
      final dupTanRows = await db.query('acc_chart_of_accounts',
          where:
              "id != ? AND (code = '1632' OR name LIKE '%Tanzeel%' OR name LIKE '%tanzeel%' OR name LIKE '%تنزیل%')",
          whereArgs: [tanAid]);
      for (final dup in dupTanRows) {
        final dupId = int.tryParse(dup['id']?.toString() ?? '');
        if (dupId != null && dupId > 0) {
          await db.rawUpdate(
              'UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?',
              [tanAid, dupId]);
          await db.rawUpdate(
              'UPDATE finance SET account_id = ? WHERE account_id = ?',
              [tanAid, dupId]);
          await db.rawUpdate(
              'UPDATE masjid_finance SET account_id = ? WHERE account_id = ?',
              [tanAid, dupId]);
          await db.update('acc_chart_of_accounts', {'is_deleted': 1},
              where: 'id = ?', whereArgs: [dupId]);
        }
      }

      // 2. Usman: code 1501 (merge 1635 into 1501)
      final usmRows = await db.query('acc_chart_of_accounts',
          where:
              "code = '1501' OR (name LIKE '%عثمان%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))");
      int usmAid = usmRows.isNotEmpty
          ? (int.tryParse(usmRows.first['id']?.toString() ?? '') ?? 0)
          : 0;
      if (usmAid == 0) {
        usmAid = await db.insert('acc_chart_of_accounts', {
          'code': '1501',
          'name': 'خرچ کھاتہ - محمد عثمان معاویہ',
          'type': 'Asset',
          'is_active': 1,
          'is_deleted': 0,
        });
      } else {
        await db.update(
            'acc_chart_of_accounts',
            {
              'code': '1501',
              'name': 'خرچ کھاتہ - محمد عثمان معاویہ',
              'is_active': 1,
              'is_deleted': 0,
            },
            where: 'id = ?',
            whereArgs: [usmAid]);
      }
      final dupUsmRows = await db.query('acc_chart_of_accounts',
          where: "id != ? AND code = '1635'", whereArgs: [usmAid]);
      for (final dup in dupUsmRows) {
        final dupId = int.tryParse(dup['id']?.toString() ?? '');
        if (dupId != null && dupId > 0) {
          await db.rawUpdate(
              'UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?',
              [usmAid, dupId]);
          await db.rawUpdate(
              'UPDATE finance SET account_id = ? WHERE account_id = ?',
              [usmAid, dupId]);
          await db.rawUpdate(
              'UPDATE masjid_finance SET account_id = ? WHERE account_id = ?',
              [usmAid, dupId]);
          await db.update('acc_chart_of_accounts', {'is_deleted': 1},
              where: 'id = ?', whereArgs: [dupId]);
        }
      }

      // 3. Saleem: code 1503
      final salRows = await db.query(
        'acc_chart_of_accounts',
        where:
            "code = '1503' OR (id != ? AND id != ? AND (name LIKE '%سلیم%' OR name LIKE '%Saleem%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))",
        whereArgs: [tanAid, usmAid],
        orderBy: "(code = '1503') DESC, id ASC",
      );
      int salAid = salRows.isNotEmpty
          ? (int.tryParse(salRows.first['id']?.toString() ?? '') ?? 0)
          : 0;
      if (salAid == 0) {
        salAid = await db.insert('acc_chart_of_accounts', {
          'code': '1503',
          'name': 'خرچ کھاتہ - قاری سلیم اللہ صاحب',
          'type': 'Asset',
          'is_active': 1,
          'is_deleted': 0,
        });
      } else {
        await db.update(
            'acc_chart_of_accounts',
            {
              'code': '1503',
              'name': 'خرچ کھاتہ - قاری سلیم اللہ صاحب',
              'is_active': 1,
              'is_deleted': 0,
            },
            where: 'id = ?',
            whereArgs: [salAid]);
      }
      final dupSalRows = await db.query(
        'acc_chart_of_accounts',
        where:
            "id != ? AND id != ? AND id != ? AND (name LIKE '%سلیم%' OR name LIKE '%Saleem%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%')",
        whereArgs: [salAid, tanAid, usmAid],
      );
      for (final dup in dupSalRows) {
        final dupId = int.tryParse(dup['id']?.toString() ?? '');
        if (dupId != null && dupId > 0) {
          await db.rawUpdate(
              'UPDATE acc_ledger_entries SET account_id = ? WHERE account_id = ?',
              [salAid, dupId]);
          await db.rawUpdate(
              'UPDATE finance SET account_id = ? WHERE account_id = ?',
              [salAid, dupId]);
          await db.rawUpdate(
              'UPDATE masjid_finance SET account_id = ? WHERE account_id = ?',
              [salAid, dupId]);
          await db.update('acc_chart_of_accounts', {'is_deleted': 1},
              where: 'id = ?', whereArgs: [dupId]);
        }
      }

      // 4. Hammad: code 1633
      final hamRows = await db.query('acc_chart_of_accounts',
          where:
              "code = '1633' OR (name LIKE '%حماد%' AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))");
      if (hamRows.isNotEmpty) {
        final hamAid = int.tryParse(hamRows.first['id']?.toString() ?? '') ?? 0;
        if (hamAid > 0) {
          await db.update(
              'acc_chart_of_accounts',
              {
                'code': '1633',
                'name': 'خرچ کھاتہ - حماد اللہ ساجد',
                'is_active': 1,
                'is_deleted': 0,
              },
              where: 'id = ?',
              whereArgs: [hamAid]);
        }
      }

      // Soft-delete test and redundant accounts
      await db.rawUpdate(
          "UPDATE acc_chart_of_accounts SET is_deleted = 1 WHERE code IN ('1504', '1505', '1632', '1635') OR name LIKE '%ٹیسٹ%'");
    } catch (_) {}

    final advAccounts = await db.query(
      'acc_chart_of_accounts',
      columns: ['id', 'code', 'name'],
      where:
          "type = 'Asset' AND (name LIKE 'خرچ کھاتہ%' OR name LIKE 'ایڈوانس%' OR name LIKE 'Advance%' OR name LIKE '%Advance%' OR name LIKE '%ایڈوانس%') AND code NOT IN ('1504', '1505', '1632', '1635') AND name NOT LIKE '%ٹیسٹ%' AND (is_deleted = 0 OR is_deleted IS NULL)",
      orderBy: 'name ASC',
    );

    final Map<int, Map<String, dynamic>> summaries = {};

    for (final acc in advAccounts) {
      final aid = int.tryParse(acc['id']?.toString() ?? '') ?? 0;
      if (aid <= 0) continue;
      final accName = acc['name']?.toString() ?? '';
      final userLabel = accName
          .replaceFirst(
              RegExp(r'^(ایڈوانس|Advance)\s*[-:_]?\s*', caseSensitive: false),
              '')
          .trim();

      // Collect all name variants (Urdu, English, usernames) and user IDs matching this userLabel
      final Set<String> nameVariants = {userLabel.toLowerCase()};
      final Set<int> matchedUserIds = {};
      try {
        final matchedAdmins = await db.rawQuery(
          "SELECT id, username, name FROM admins WHERE (LOWER(username) = LOWER(?) OR LOWER(name) = LOWER(?) OR LOWER(username) LIKE ? OR LOWER(name) LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [userLabel, userLabel, '%$userLabel%', '%$userLabel%'],
        );
        for (final ma in matchedAdmins) {
          final mId = int.tryParse(ma['id']?.toString() ?? '');
          if (mId != null) matchedUserIds.add(mId);
          if (ma['username'] != null &&
              ma['username'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['username'].toString().toLowerCase().trim());
          }
          if (ma['name'] != null && ma['name'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['name'].toString().toLowerCase().trim());
          }
        }
        final matchedTeachers = await db.rawQuery(
          "SELECT id, name FROM teachers WHERE (LOWER(name) = LOWER(?) OR LOWER(name) LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [userLabel, '%$userLabel%'],
        );
        for (final mt in matchedTeachers) {
          final mId = int.tryParse(mt['id']?.toString() ?? '');
          if (mId != null) matchedUserIds.add(mId);
          if (mt['name'] != null && mt['name'].toString().trim().isNotEmpty) {
            nameVariants.add(mt['name'].toString().toLowerCase().trim());
          }
        }
      } catch (_) {}

      // Dedicated hard mapping for known accounts
      if (userLabel.contains('سلیم') ||
          userLabel.toLowerCase().contains('saleem') ||
          acc['code'] == '1503') {
        matchedUserIds.add(3);
        nameVariants.addAll(['saleem', 'قاری سلیم اللہ صاحب', 'سلیم']);
      }
      if (userLabel.contains('تنزیل') ||
          userLabel.toLowerCase().contains('tanzeel') ||
          acc['code'] == '1502') {
        matchedUserIds.add(1);
        nameVariants.addAll(['tanzeel', 'تنزیل الرحمن', 'تنزیل']);
      }

      // 1. Inflow / Transferred in (Debits in Ledger)
      final drResult = await db.rawQuery('''
        SELECT COALESCE(SUM(e.debit), 0) as total_debit
        FROM acc_ledger_entries e
        JOIN acc_vouchers v ON e.voucher_id = v.id
        WHERE e.account_id = ? AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
      ''', [aid]);
      double drSum =
          double.tryParse(drResult.first['total_debit']?.toString() ?? '0') ??
              0.0;

      // Fallback: If ledger debit is 0, check if Contra vouchers transferred to this account directly
      if (drSum <= 0 && userLabel.isNotEmpty) {
        try {
          final vDebRes = await db.rawQuery('''
            SELECT COALESCE(SUM(e.debit), 0) as total_amt
            FROM acc_ledger_entries e
            JOIN acc_vouchers v ON e.voucher_id = v.id
            WHERE (v.reference LIKE 'TRANSFER-%' OR v.type = 'Contra')
              AND (v.description LIKE ? OR v.description LIKE ?)
              AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
          ''', ['%$userLabel%', '%$aid%']);
          if (vDebRes.isNotEmpty) {
            drSum = double.tryParse(
                    vDebRes.first['total_amt']?.toString() ?? '0') ??
                0.0;
          }
        } catch (_) {}
      }

      // 2. Direct expenses in finance table recorded against this advance account
      // STRICT ISOLATION:
      // An expense is deducted from THIS advance account if and only if:
      // (a) account_id = aid, OR
      // (b) (account_id IS NULL OR account_id = 0) AND payment_method is Advance AND user_name/user_id strictly matches this user
      final List<String> matchClauses = ['account_id = ?'];
      final List<dynamic> matchArgs = [aid];

      final cleanNames = nameVariants
          .where((n) =>
              n.isNotEmpty &&
              n != 'admin' &&
              n != 'administrator' &&
              n != 'superadmin')
          .toSet();
      if (cleanNames.isNotEmpty) {
        final namePlaceholders = cleanNames.map((_) => '?').join(',');
        matchClauses.add('''
          ((account_id IS NULL OR account_id = 0) 
           AND (LOWER(payment_method) = 'advance' OR payment_method LIKE '%ایڈوانس%' OR description LIKE '%ایڈوانس%' OR description LIKE '%Advance%')
           AND LOWER(TRIM(user_name)) IN ($namePlaceholders))
        ''');
        matchArgs.addAll(cleanNames);
      }
      if (matchedUserIds.isNotEmpty) {
        final uidPlaceholders = matchedUserIds.map((_) => '?').join(',');
        matchClauses.add('''
          ((account_id IS NULL OR account_id = 0) 
           AND (LOWER(payment_method) = 'advance' OR payment_method LIKE '%ایڈوانس%' OR description LIKE '%ایڈوانس%' OR description LIKE '%Advance%')
           AND user_id IN ($uidPlaceholders))
        ''');
        matchArgs.addAll(matchedUserIds);
      }

      final matchSql = matchClauses.join(' OR ');

      final expFinanceRes = await db.rawQuery('''
        SELECT COALESCE(SUM(amount), 0) as total_exp
        FROM finance
        WHERE (LOWER(type) = 'expense') 
          AND (is_deleted = 0 OR is_deleted IS NULL)
          AND (account_id = ? OR ($matchSql))
          AND (account_id IS NULL OR account_id = 0 OR account_id = ?)
      ''', [aid, ...matchArgs, aid]);
      final finExpSum = double.tryParse(
              expFinanceRes.first['total_exp']?.toString() ?? '0') ??
          0.0;

      double masjidExpSum = 0.0;
      try {
        final masjidExpRes = await db.rawQuery('''
          SELECT COALESCE(SUM(amount), 0) as total_exp
          FROM masjid_finance
          WHERE (LOWER(type) = 'expense') 
            AND (is_deleted = 0 OR is_deleted IS NULL)
            AND (account_id = ? OR ($matchSql))
            AND (account_id IS NULL OR account_id = 0 OR account_id = ?)
        ''', [aid, ...matchArgs, aid]);
        masjidExpSum = double.tryParse(
                masjidExpRes.first['total_exp']?.toString() ?? '0') ??
            0.0;
      } catch (_) {}

      final currentSys = systemType ?? SystemTypeGlobal.currentSystemType.value;
      final isMasjid = currentSys == 'Masjid';
      final totalDirectExpenses = isMasjid ? masjidExpSum : finExpSum;

      // 3. Non-finance vouchers out of Advance (e.g. Contra fund transfer returning advance to cash, or manual ERP vouchers)
      // Strictly exclude vouchers generated from Simple Finance (FIN-% and SF-%) to eliminate double counting!
      double vouchAdvExp = 0.0;
      try {
        final crResult = await db.rawQuery('''
          SELECT COALESCE(SUM(e.credit), 0) as total_credit
          FROM acc_ledger_entries e
          JOIN acc_vouchers v ON e.voucher_id = v.id
          WHERE e.account_id = ? 
            AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
            AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
            AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
            AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        ''', [aid]);
        vouchAdvExp = double.tryParse(
                crResult.first['total_credit']?.toString() ?? '0') ??
            0.0;
      } catch (_) {}

      final effectiveDeducted = totalDirectExpenses + vouchAdvExp;
      final netBalance = drSum - effectiveDeducted;

      summaries[aid] = {
        'account_id': aid,
        'code': acc['code']?.toString() ?? '',
        'name': accName,
        'user_label': userLabel,
        'user_id': matchedUserIds.isNotEmpty ? matchedUserIds.first : null,
        'user_ids': matchedUserIds.toList(),
        'total_received': drSum,
        'total_deducted': effectiveDeducted,
        'net_balance': netBalance,
      };
    }
    return summaries;
  }

  Future<void> autoReconcileAdvanceExpenses(int aid, String userLabel) async {
    try {
      final db = await database;
      final uLabel = userLabel.trim();
      if (uLabel.isEmpty || aid <= 0) return;

      final Set<String> nameVariants = {uLabel.toLowerCase()};
      try {
        final matchedAdmins = await db.rawQuery(
          "SELECT username, name FROM admins WHERE (LOWER(username) = LOWER(?) OR LOWER(name) = LOWER(?)) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [uLabel, uLabel],
        );
        for (final ma in matchedAdmins) {
          if (ma['username'] != null &&
              ma['username'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['username'].toString().toLowerCase().trim());
          }
          if (ma['name'] != null && ma['name'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['name'].toString().toLowerCase().trim());
          }
        }
      } catch (_) {}

      final cleanNames = nameVariants
          .where((n) =>
              n.isNotEmpty &&
              n != 'admin' &&
              n != 'administrator' &&
              n != 'superadmin')
          .toSet();
      if (cleanNames.isEmpty) return;

      final namePlaceholders = cleanNames.map((_) => '?').join(',');
      // STRICT: Only reconcile unlinked records (account_id IS NULL OR account_id = 0) that are EXPLICITLY marked as Advance!
      // NEVER touch cash records (account_id = 1001 or payment_method = 'Cash')!
      final whereClause = '''
        LOWER(type) = 'expense' 
        AND (account_id IS NULL OR account_id = 0) 
        AND (LOWER(payment_method) = 'advance' OR payment_method LIKE '%ایڈوانس%' OR description LIKE '%ایڈوانس%' OR description LIKE '%Advance%')
        AND (payment_method IS NULL OR LOWER(payment_method) != 'cash')
        AND (is_deleted = 0 OR is_deleted IS NULL) 
        AND LOWER(TRIM(user_name)) IN ($namePlaceholders)
      ''';

      final unlinkedFinance = await db.query('finance',
          columns: ['id'], where: whereClause, whereArgs: cleanNames.toList());
      for (final r in unlinkedFinance) {
        final fid = int.tryParse(r['id']?.toString() ?? '') ?? 0;
        if (fid > 0) {
          await db.update('finance',
              {'account_id': aid, 'payment_method': 'Advance', 'is_synced': 0},
              where: 'id = ?', whereArgs: [fid]);
          await _postSimpleFinanceToAccounts(db, fid);
        }
      }

      try {
        final unlinkedMasjid = await db.query('masjid_finance',
            columns: ['id'],
            where: whereClause,
            whereArgs: cleanNames.toList());
        for (final r in unlinkedMasjid) {
          final mid = int.tryParse(r['id']?.toString() ?? '') ?? 0;
          if (mid > 0) {
            await db.update(
                'masjid_finance',
                {
                  'account_id': aid,
                  'payment_method': 'Advance',
                  'is_synced': 0
                },
                where: 'id = ?',
                whereArgs: [mid]);
          }
        }
      } catch (_) {}
    } catch (_) {}
  }

  Future<double> getUserAdvanceBalance(String username,
      {String? role, int? accountId}) async {
    final summaries = await getAllUserAdvanceSummaries();
    if (accountId != null &&
        accountId > 0 &&
        summaries.containsKey(accountId)) {
      return (summaries[accountId]!['net_balance'] as num?)?.toDouble() ?? 0.0;
    }

    final trimmed = username.trim().toLowerCase();
    if (trimmed.isNotEmpty) {
      for (final s in summaries.values) {
        final uLabel = (s['user_label']?.toString() ?? '').trim().toLowerCase();
        final accName = (s['name']?.toString() ?? '').trim().toLowerCase();
        if (uLabel == trimmed ||
            uLabel.contains(trimmed) ||
            trimmed.contains(uLabel) ||
            accName.contains(trimmed)) {
          return (s['net_balance'] as num?)?.toDouble() ?? 0.0;
        }
      }
    }

    final rLower = (role ?? '').trim().toLowerCase();
    if (rLower == 'nazim') {
      for (final s in summaries.values) {
        final uLabel = (s['user_label']?.toString() ?? '').toLowerCase();
        final accName = (s['name']?.toString() ?? '').toLowerCase();
        if (uLabel.contains('ناظم') ||
            uLabel.contains('nazim') ||
            accName.contains('ناظم') ||
            accName.contains('nazim')) {
          return (s['net_balance'] as num?)?.toDouble() ?? 0.0;
        }
      }
    }

    if (summaries.isNotEmpty) {
      if (summaries.length == 1) {
        return (summaries.values.first['net_balance'] as num?)?.toDouble() ??
            0.0;
      }
      if (rLower == 'admin' || trimmed.contains('admin')) {
        return (summaries.values.first['net_balance'] as num?)?.toDouble() ??
            0.0;
      }
    }

    return 0.0;
  }

  Future<int> ensureUserAdvanceAccount(String username) async {
    final db = await database;
    await _createAccountingTables(db);
    final uTrim = username.trim();
    if (uTrim.isEmpty) return 0;
    final uLower = uTrim.toLowerCase();

    // Check if user is Saleem (ID 3 / saleem / سلیم)
    if (uLower.contains('saleem') || uTrim.contains('سلیم')) {
      final salRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1503' OR ((name LIKE '%سلیم%' OR name LIKE '%saleem%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1503') DESC, id ASC",
        limit: 1,
      );
      if (salRows.isNotEmpty) {
        return int.tryParse(salRows.first['id'].toString()) ?? 0;
      }
    }

    // Check if user is Tanzeel (ID 1 / tanzeel / تنزیل)
    if (uLower.contains('tanzeel') || uTrim.contains('تنزیل')) {
      final tanRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1502' OR ((name LIKE '%تنزیل%' OR name LIKE '%tanzeel%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1502') DESC, id ASC",
        limit: 1,
      );
      if (tanRows.isNotEmpty) {
        return int.tryParse(tanRows.first['id'].toString()) ?? 0;
      }
    }

    // Check if user is Usman (ID 4 / usman / عثمان)
    if (uLower.contains('usman') || uTrim.contains('عثمان')) {
      final usmRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1501' OR ((name LIKE '%عثمان%' OR name LIKE '%usman%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1501') DESC, id ASC",
        limit: 1,
      );
      if (usmRows.isNotEmpty) {
        return int.tryParse(usmRows.first['id'].toString()) ?? 0;
      }
    }

    // Check if user is Hammad (ID 2 / hammad / حماد)
    if (uLower.contains('hammad') || uTrim.contains('حماد')) {
      final hamRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1633' OR ((name LIKE '%حماد%' OR name LIKE '%hammad%') AND (name LIKE '%خرچ%' OR name LIKE '%ایڈوانس%' OR name LIKE '%Advance%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1633') DESC, id ASC",
        limit: 1,
      );
      if (hamRows.isNotEmpty) {
        return int.tryParse(hamRows.first['id'].toString()) ?? 0;
      }
    }

    final accName = 'خرچ کھاتہ - $uTrim';
    final existing = await db.query(
      'acc_chart_of_accounts',
      columns: ['id'],
      where:
          "type = 'Asset' AND (name = ? OR name LIKE ? OR name LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
      whereArgs: [accName, '%$uTrim%', '%$uTrim%'],
      limit: 1,
    );
    if (existing.isNotEmpty) {
      return int.tryParse(existing.first['id'].toString()) ?? 0;
    }

    final maxCodeRow = await db.rawQuery(
        "SELECT MAX(CAST(code AS INTEGER)) as max_code FROM acc_chart_of_accounts WHERE type = 'Asset'");
    int maxCode =
        int.tryParse(maxCodeRow.first['max_code']?.toString() ?? '0') ?? 0;
    int newCode = maxCode > 1500 ? maxCode + 1 : 1501;

    final id = await db.insert('acc_chart_of_accounts', {
      'code': newCode.toString(),
      'name': accName,
      'type': 'Asset',
      'is_deleted': 0,
      'is_synced': 0,
    });
    return id;
  }

  Future<int> ensureUserCollectionAccount(String username) async {
    final db = await database;
    await _createAccountingTables(db);
    final uTrim = username.trim();
    if (uTrim.isEmpty) return 0;
    final uLower = uTrim.toLowerCase();

    // Check if user is Saleem (ID 3 / saleem / سلیم)
    if (uLower.contains('saleem') || uTrim.contains('سلیم')) {
      final salRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1102' OR ((name LIKE '%سلیم%' OR name LIKE '%saleem%') AND (name LIKE '%وصولی%' OR name LIKE '%Collection%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1102') DESC, id ASC",
        limit: 1,
      );
      if (salRows.isNotEmpty) {
        return int.tryParse(salRows.first['id'].toString()) ?? 0;
      }
      return await db.insert('acc_chart_of_accounts', {
        'code': '1102',
        'name': 'نقد وصولی - قاری سلیم اللہ صاحب',
        'type': 'Asset',
        'is_deleted': 0,
        'is_synced': 0,
      });
    }

    // Check if user is Tanzeel (ID 1 / tanzeel / تنزیل)
    if (uLower.contains('tanzeel') || uTrim.contains('تنزیل')) {
      final tanRows = await db.query(
        'acc_chart_of_accounts',
        columns: ['id'],
        where:
            "type = 'Asset' AND (code = '1101' OR ((name LIKE '%تنزیل%' OR name LIKE '%tanzeel%') AND (name LIKE '%وصولی%' OR name LIKE '%Collection%'))) AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: "(code = '1101') DESC, id ASC",
        limit: 1,
      );
      if (tanRows.isNotEmpty) {
        return int.tryParse(tanRows.first['id'].toString()) ?? 0;
      }
      return await db.insert('acc_chart_of_accounts', {
        'code': '1101',
        'name': 'نقد وصولی - تنزیل الرحمن',
        'type': 'Asset',
        'is_deleted': 0,
        'is_synced': 0,
      });
    }

    final accName = 'نقد وصولی - $uTrim';
    final existing = await db.query(
      'acc_chart_of_accounts',
      columns: ['id'],
      where:
          "type = 'Asset' AND (name = ? OR name LIKE ? OR name LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
      whereArgs: [accName, '%$uTrim%', '%نقد وصولی - $uTrim%'],
      limit: 1,
    );
    if (existing.isNotEmpty) {
      return int.tryParse(existing.first['id'].toString()) ?? 0;
    }

    final maxCodeRow = await db.rawQuery(
        "SELECT MAX(CAST(code AS INTEGER)) as max_code FROM acc_chart_of_accounts WHERE type = 'Asset' AND code LIKE '11%'");
    int maxCode =
        int.tryParse(maxCodeRow.first['max_code']?.toString() ?? '0') ?? 0;
    int newCode = maxCode >= 1101 ? maxCode + 1 : 1103;

    final id = await db.insert('acc_chart_of_accounts', {
      'code': newCode.toString(),
      'name': accName,
      'type': 'Asset',
      'is_deleted': 0,
      'is_synced': 0,
    });
    return id;
  }

  Future<Map<int, Map<String, dynamic>>> getAllUserCollectionSummaries(
      {String? systemType}) async {
    final db = await database;
    await _createAccountingTables(db);
    await _ensureDefaultAccountingAccounts(db);

    final colAccounts = await db.query(
      'acc_chart_of_accounts',
      columns: ['id', 'code', 'name'],
      where:
          "type = 'Asset' AND (name LIKE 'نقد وصولی%' OR name LIKE 'وصولی%' OR name LIKE '%وصولی%' OR name LIKE 'Collection%' OR (CAST(code AS INTEGER) >= 1101 AND CAST(code AS INTEGER) <= 1199)) AND (is_deleted = 0 OR is_deleted IS NULL)",
      orderBy: 'name ASC',
    );

    final Map<int, Map<String, dynamic>> summaries = {};

    for (final acc in colAccounts) {
      final aid = int.tryParse(acc['id']?.toString() ?? '') ?? 0;
      if (aid <= 0) continue;
      final accName = acc['name']?.toString() ?? '';
      final userLabel = accName
          .replaceFirst(
              RegExp(r'^(نقد وصولی|وصولی|Collection)\s*[-:_]?\s*',
                  caseSensitive: false),
              '')
          .trim();

      final Set<String> nameVariants = {userLabel.toLowerCase()};
      final Set<int> matchedUserIds = {};
      try {
        final matchedAdmins = await db.rawQuery(
          "SELECT id, username, name FROM admins WHERE (LOWER(username) = LOWER(?) OR LOWER(name) = LOWER(?) OR LOWER(username) LIKE ? OR LOWER(name) LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [userLabel, userLabel, '%$userLabel%', '%$userLabel%'],
        );
        for (final ma in matchedAdmins) {
          final mId = int.tryParse(ma['id']?.toString() ?? '');
          if (mId != null) matchedUserIds.add(mId);
          if (ma['username'] != null &&
              ma['username'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['username'].toString().toLowerCase().trim());
          }
          if (ma['name'] != null && ma['name'].toString().trim().isNotEmpty) {
            nameVariants.add(ma['name'].toString().toLowerCase().trim());
          }
        }
        final matchedTeachers = await db.rawQuery(
          "SELECT id, name FROM teachers WHERE (LOWER(name) = LOWER(?) OR LOWER(name) LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
          [userLabel, '%$userLabel%'],
        );
        for (final mt in matchedTeachers) {
          final mId = int.tryParse(mt['id']?.toString() ?? '');
          if (mId != null) matchedUserIds.add(mId);
          if (mt['name'] != null && mt['name'].toString().trim().isNotEmpty) {
            nameVariants.add(mt['name'].toString().toLowerCase().trim());
          }
        }
      } catch (_) {}

      if (userLabel.contains('سلیم') ||
          userLabel.toLowerCase().contains('saleem') ||
          acc['code'] == '1102') {
        matchedUserIds.add(3);
        nameVariants.addAll(['saleem', 'قاری سلیم اللہ صاحب', 'سلیم']);
      }
      if (userLabel.contains('تنزیل') ||
          userLabel.toLowerCase().contains('tanzeel') ||
          acc['code'] == '1101') {
        matchedUserIds.add(1);
        nameVariants.addAll(['tanzeel', 'تنزیل الرحمن', 'تنزیل']);
      }

      // 1. Total Inflow / Collected (Income receipts recorded in finance where account_id = aid)
      final incRes = await db.rawQuery('''
        SELECT COALESCE(SUM(amount), 0) as total_inc
        FROM finance
        WHERE (LOWER(type) = 'income')
          AND (is_deleted = 0 OR is_deleted IS NULL)
          AND account_id = ?
      ''', [aid]);
      double incSum =
          double.tryParse(incRes.first['total_inc']?.toString() ?? '0') ?? 0.0;

      double masjidIncSum = 0.0;
      try {
        final masjidIncRes = await db.rawQuery('''
          SELECT COALESCE(SUM(amount), 0) as total_inc
          FROM masjid_finance
          WHERE (LOWER(type) = 'income')
            AND (is_deleted = 0 OR is_deleted IS NULL)
            AND account_id = ?
        ''', [aid]);
        masjidIncSum = double.tryParse(
                masjidIncRes.first['total_inc']?.toString() ?? '0') ??
            0.0;
      } catch (_) {}

      final currentSys = systemType ?? SystemTypeGlobal.currentSystemType.value;
      final isMasjid = currentSys == 'Masjid';
      final totalCollected = isMasjid ? masjidIncSum : incSum;

      // 2. Direct expenses recorded against this collection account (spending from collection in-hand)
      double spentFromCollection = 0.0;
      try {
        final expRes = await db.rawQuery('''
          SELECT COALESCE(SUM(amount), 0) as total_exp
          FROM finance
          WHERE (LOWER(type) = 'expense')
            AND (is_deleted = 0 OR is_deleted IS NULL)
            AND account_id = ?
        ''', [aid]);
        spentFromCollection =
            double.tryParse(expRes.first['total_exp']?.toString() ?? '0') ??
                0.0;
      } catch (_) {}

      double masjidSpentFromCollection = 0.0;
      try {
        final masjidExpRes = await db.rawQuery('''
          SELECT COALESCE(SUM(amount), 0) as total_exp
          FROM masjid_finance
          WHERE (LOWER(type) = 'expense')
            AND (is_deleted = 0 OR is_deleted IS NULL)
            AND account_id = ?
        ''', [aid]);
        masjidSpentFromCollection = double.tryParse(
                masjidExpRes.first['total_exp']?.toString() ?? '0') ??
            0.0;
      } catch (_) {}

      final totalSpent =
          isMasjid ? masjidSpentFromCollection : spentFromCollection;

      // 3. Outflow / Transferred out to Admin / Central Cash (Credits in ledger entries where voucher is Contra/TRANSFER)
      double transferredOut = 0.0;
      try {
        final crResult = await db.rawQuery('''
          SELECT COALESCE(SUM(e.credit), 0) as total_credit
          FROM acc_ledger_entries e
          JOIN acc_vouchers v ON e.voucher_id = v.id
          WHERE e.account_id = ?
            AND (v.type = 'Contra' OR v.reference LIKE 'TRANSFER-%')
            AND (v.reference NOT LIKE 'FIN-%' OR v.reference IS NULL)
            AND (v.voucher_no NOT LIKE 'SF-%' OR v.voucher_no IS NULL)
            AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        ''', [aid]);
        transferredOut = double.tryParse(
                crResult.first['total_credit']?.toString() ?? '0') ??
            0.0;
      } catch (_) {}

      final netInHand = totalCollected - (transferredOut + totalSpent);

      summaries[aid] = {
        'account_id': aid,
        'code': acc['code']?.toString() ?? '',
        'name': accName,
        'user_label': userLabel,
        'user_id': matchedUserIds.isNotEmpty ? matchedUserIds.first : null,
        'user_ids': matchedUserIds.toList(),
        'total_collected': totalCollected,
        'total_transferred': transferredOut,
        'total_spent': totalSpent,
        'net_in_hand': netInHand,
      };
    }
    return summaries;
  }

  Future<List<Map<String, dynamic>>> getPaymentAssetAccounts(
      {String? systemType}) async {
    final db = await database;
    await _createAccountingTables(db);
    await _ensureDefaultAccountingAccounts(db);
    String whereClause =
        "type = 'Asset' AND (is_deleted = 0 OR is_deleted IS NULL) AND (name NOT LIKE '%لون%' AND name NOT LIKE '%قرض%' AND LOWER(name) NOT LIKE '%loan%')";
    List<dynamic> whereArgs = [];
    if (systemType != null && systemType.isNotEmpty) {
      if (systemType == 'Masjid') {
        whereClause += " AND system_type = 'Masjid'";
      } else {
        whereClause +=
            " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      }
    }
    return await db.query(
      'acc_chart_of_accounts',
      where: whereClause,
      whereArgs: whereArgs,
      orderBy: 'code ASC',
    );
  }

  Future<List<Map<String, dynamic>>> getSystemAdmins() async {
    final db = await database;
    try {
      return await db.rawQuery(
          "SELECT id, username, name, role FROM admins WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC");
    } catch (_) {
      return [];
    }
  }

  Future<List<Map<String, dynamic>>> getFundTransfers({
    String? systemType,
    int? userId,
    String? userRole,
    String? username,
  }) async {
    final db = await database;
    await _createAccountingTables(db);
    String sysFilter = '';
    List<dynamic> args = [];
    if (systemType != null && systemType.isNotEmpty) {
      if (systemType == 'Masjid') {
        sysFilter = "AND v.system_type = 'Masjid'";
      } else {
        sysFilter =
            "AND (v.system_type = 'Madrasa' OR v.system_type IS NULL OR v.system_type = '')";
      }
    }

    String userFilter = '';
    final roleLower = (userRole ?? '').toLowerCase();
    final isAdmin = roleLower == 'admin' ||
        roleLower == 'superadmin' ||
        roleLower == 'super_admin' ||
        roleLower == 'administrator';
    if (!isAdmin &&
        (userId != null || (username != null && username.isNotEmpty))) {
      final Set<String> nameVariants = {};
      final Set<int> userAccountIds = {};
      if (username != null && username.trim().isNotEmpty) {
        nameVariants.add(username.trim().toLowerCase());
      }
      if (userId != null && userId > 0) {
        try {
          final uRows = await db.rawQuery(
            "SELECT name, username FROM admins WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)",
            [userId],
          );
          for (final r in uRows) {
            if (r['name'] != null && r['name'].toString().trim().isNotEmpty) {
              nameVariants.add(r['name'].toString().trim().toLowerCase());
            }
            if (r['username'] != null &&
                r['username'].toString().trim().isNotEmpty) {
              nameVariants.add(r['username'].toString().trim().toLowerCase());
            }
          }
        } catch (_) {}
      }

      // Find user's advance and collection account ID(s)
      for (final n in nameVariants) {
        try {
          final accRows = await db.rawQuery(
            "SELECT id FROM acc_chart_of_accounts WHERE type = 'Asset' AND (name LIKE 'ایڈوانس%' OR name LIKE 'Advance%' OR name LIKE '%Advance%' OR name LIKE '%ایڈوانس%' OR name LIKE 'نقد وصولی%' OR name LIKE 'وصولی%' OR name LIKE '%وصولی%') AND (LOWER(name) LIKE ? OR LOWER(name) LIKE ?) AND (is_deleted = 0 OR is_deleted IS NULL)",
            ['%$n%', '%$n%'],
          );
          for (final ar in accRows) {
            final aid = int.tryParse(ar['id']?.toString() ?? '');
            if (aid != null && aid > 0) userAccountIds.add(aid);
          }
        } catch (_) {}
      }
      if (userId == 3 ||
          nameVariants.any((n) => n.contains('saleem') || n.contains('سلیم'))) {
        try {
          final salAccs = await db.rawQuery(
            "SELECT id FROM acc_chart_of_accounts WHERE type = 'Asset' AND (code = '1503' OR code = '1102' OR name LIKE '%سلیم%' OR name LIKE '%saleem%') AND (is_deleted = 0 OR is_deleted IS NULL)",
          );
          for (final ar in salAccs) {
            final aid = int.tryParse(ar['id']?.toString() ?? '');
            if (aid != null && aid > 0) userAccountIds.add(aid);
          }
        } catch (_) {}
      }
      if (userId == 1 ||
          nameVariants
              .any((n) => n.contains('tanzeel') || n.contains('تنزیل'))) {
        try {
          final tanAccs = await db.rawQuery(
            "SELECT id FROM acc_chart_of_accounts WHERE type = 'Asset' AND (code = '1502' OR code = '1101' OR name LIKE '%تنزیل%' OR name LIKE '%tanzeel%') AND (is_deleted = 0 OR is_deleted IS NULL)",
          );
          for (final ar in tanAccs) {
            final aid = int.tryParse(ar['id']?.toString() ?? '');
            if (aid != null && aid > 0) userAccountIds.add(aid);
          }
        } catch (_) {}
      }

      final List<String> conds = [];
      if (userId != null && userId > 0) {
        conds.add("v.created_by = ?");
        args.add(userId);
      }
      for (final n in nameVariants) {
        conds.add("a1.name LIKE ?");
        args.add('%$n%');
        conds.add("a2.name LIKE ?");
        args.add('%$n%');
        conds.add("v.description LIKE ?");
        args.add('%$n%');
      }
      for (final aid in userAccountIds) {
        conds.add("i1.account_id = ?");
        args.add(aid);
        conds.add("i2.account_id = ?");
        args.add(aid);
      }
      if (conds.isNotEmpty) {
        userFilter = "AND (${conds.join(' OR ')})";
      }
    }

    return await db.rawQuery('''
      SELECT v.id, v.voucher_no, v.date, v.description, v.reference, v.created_by,
             MAX(COALESCE(i1.credit, i2.debit, 0)) as amount,
             COALESCE(GROUP_CONCAT(DISTINCT a1.name), '---') as from_account,
             COALESCE(GROUP_CONCAT(DISTINCT a2.name), '---') as to_account
      FROM acc_vouchers v
      LEFT JOIN acc_ledger_entries i1 ON v.id = i1.voucher_id AND i1.credit > 0
      LEFT JOIN acc_chart_of_accounts a1 ON i1.account_id = a1.id
      LEFT JOIN acc_ledger_entries i2 ON v.id = i2.voucher_id AND i2.debit > 0
      LEFT JOIN acc_chart_of_accounts a2 ON i2.account_id = a2.id
      WHERE (v.reference LIKE 'TRANSFER-%' OR v.type = 'Contra')
        AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
        $sysFilter
        $userFilter
      GROUP BY v.id
      ORDER BY v.date DESC, v.id DESC
      LIMIT 100
    ''', args);
  }

  Future<int> transferFund({
    required int fromAccountId,
    required int toAccountId,
    required double amount,
    required String date,
    String description = '',
    int userId = 1,
    String systemType = 'Madrasa',
  }) async {
    if (fromAccountId <= 0 ||
        toAccountId <= 0 ||
        fromAccountId == toAccountId ||
        amount <= 0) {
      throw ArgumentError(
          'Invalid transfer parameters: From and To accounts must be different valid accounts and amount must be positive.');
    }
    final db = await database;
    await _createAccountingTables(db);

    String fromName = 'Account #$fromAccountId';
    String toName = 'Account #$toAccountId';
    final fromRows = await db.query('acc_chart_of_accounts',
        columns: ['name'], where: 'id = ?', whereArgs: [fromAccountId]);
    if (fromRows.isNotEmpty)
      fromName = fromRows.first['name']?.toString() ?? fromName;
    final toRows = await db.query('acc_chart_of_accounts',
        columns: ['name'], where: 'id = ?', whereArgs: [toAccountId]);
    if (toRows.isNotEmpty) toName = toRows.first['name']?.toString() ?? toName;

    final voucherNo = await nextAccVoucherNo('Contra');
    final reference = 'TRANSFER-${DateTime.now().millisecondsSinceEpoch}';
    final desc = description.trim().isNotEmpty
        ? description.trim()
        : 'رقم منتقلی از $fromName تا $toName (Fund Transfer)';

    return await db.transaction<int>((txn) async {
      final voucherData = {
        'voucher_no': voucherNo,
        'date': date,
        'type': 'Contra',
        'reference': reference,
        'description': desc,
        'status': 'Approved',
        'created_by': userId,
        'system_type': systemType,
        'is_deleted': 0,
        'is_synced': 0,
      };
      final voucherId = await txn.insert('acc_vouchers', voucherData);

      // Debit receiving account (Asset increases)
      await txn.insert('acc_ledger_entries', {
        'voucher_id': voucherId,
        'account_id': toAccountId,
        'debit': amount,
        'credit': 0.0,
        'is_synced': 0,
      });

      // Credit sending account (Asset decreases)
      await txn.insert('acc_ledger_entries', {
        'voucher_id': voucherId,
        'account_id': fromAccountId,
        'debit': 0.0,
        'credit': amount,
        'is_synced': 0,
      });

      return voucherId;
    });
  }

  Future<void> deleteFundTransfer(int voucherId) async {
    final db = await database;
    await _createAccountingTables(db);
    await db.transaction((txn) async {
      await txn.update(
        'acc_vouchers',
        {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?',
        whereArgs: [voucherId],
      );
      await txn.update(
        'acc_ledger_entries',
        {'debit': 0.0, 'credit': 0.0, 'is_synced': 0},
        where: 'voucher_id = ?',
        whereArgs: [voucherId],
      );
    });
  }

  Future<void> updateFundTransfer({
    required int voucherId,
    required double amount,
    required String date,
    String description = '',
  }) async {
    final db = await database;
    await _createAccountingTables(db);
    await db.transaction((txn) async {
      await txn.update(
        'acc_vouchers',
        {
          'date': date,
          'description': description,
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [voucherId],
      );
      await txn.rawUpdate('''
        UPDATE acc_ledger_entries SET debit = ?, is_synced = 0 WHERE voucher_id = ? AND debit > 0
      ''', [amount, voucherId]);
      await txn.rawUpdate('''
        UPDATE acc_ledger_entries SET credit = ?, is_synced = 0 WHERE voucher_id = ? AND credit > 0
      ''', [amount, voucherId]);
    });
  }

  Future<int> insertTransaction(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    return await db.insert('finance', data);
  }

  Future<int> insertExam(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['total_marks'] = data['total_marks'] ?? 100;
    data['passing_marks'] = data['passing_marks'] ?? 33;
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    return await db.insert('exams', data,
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>> getNotices() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('notices',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        orderBy: 'date DESC');
  }

  Future<int> insertNotice(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    return await db.insert('notices', data);
  }

  Future<int> insertComplaint(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    return await db.insert('complaints', data);
  }

  Future<int> insertParentComplaint(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['system_type'] =
        data['system_type'] ?? SystemTypeGlobal.currentSystemType.value;
    return await db.insert('parent_complaints', data);
  }

  Future<int> insertLeaveApplication(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('leave_applications', data);
  }

  Future<int> insertFeeSubmission(Map<String, dynamic> row) async {
    // saveServerData has been completely modularized to lib/db/db_sync_save.dart (DbSyncSave extension)
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('fee_submissions', data);
  }

  Future<void> deleteNotice(int id) async {
    Database db = await database;
    await db.update('notices', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getComplaints() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('complaints', orderBy: 'id DESC', where: sysCond);
  }

  Future<int> insertAcademicReport(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('academic_reports', data);
  }

  Future<List<Map<String, dynamic>>> getAcademicReports() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('academic_reports',
        orderBy: 'date DESC', where: sysCond);
  }

  // Sync read & teacher remap methods have been modularized to:
  // - lib/db/db_sync_read.dart (DbSyncRead extension)
  // - lib/db/db_sync_teachers.dart (DbSyncTeachers extension)

  // Sync save methods have been modularized to:
  // - lib/db/db_sync_save.dart (DbSyncSave extension)

  Future<Map<String, dynamic>> getSettings() async {
    Database db = await database;

    await db.execute('''
      CREATE TABLE IF NOT EXISTS settings(
        id INTEGER PRIMARY KEY,
        madrasa_name TEXT,
        phone TEXT,
        current_hijri_year TEXT,
        week_start_day INTEGER DEFAULT 6,
        ai_provider TEXT DEFAULT 'gemini',
        gemini_api_key TEXT,
        groq_api_key TEXT,
        whatsapp_api_key TEXT,
        sms_api_key TEXT,
        auto_notify_day INTEGER DEFAULT 4,
        auto_notify_time TEXT DEFAULT '18:00'
      )
    ''');

    try {
      await db.execute('ALTER TABLE settings ADD COLUMN address TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN tagline TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN logo_path TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN receipt_bg TEXT');
    } catch (_) {}
    try {
      await db
          .execute('ALTER TABLE settings ADD COLUMN masjid_receipt_bg TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN id_card_bg TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN sanad_bg TEXT');
    } catch (_) {}
    try {
      await db
          .execute('ALTER TABLE settings ADD COLUMN signature_stamp_path TEXT');
    } catch (_) {}
    try {
      await db
          .execute('ALTER TABLE settings ADD COLUMN sanad_layout_json TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN masjid_name TEXT');
    } catch (_) {}
    try {
      await db.execute('ALTER TABLE settings ADD COLUMN masjid_logo_path TEXT');
    } catch (_) {}

    var res = await db.query('settings', where: 'id = 1');
    if (res.isNotEmpty) return res.first;

    await db.insert(
        'settings',
        {
          'id': 1,
          'madrasa_name':
              '\u0645\u062f\u0631\u0633\u06c1 \u0627\u0634\u0631\u0641 \u0627\u0644\u0645\u062f\u0627\u0631\u0633',
          'phone': '',
          'current_hijri_year': '1447',
          'week_start_day': 6,
          'ai_provider': 'gemini',
          'gemini_api_key': '',
          'groq_api_key': '',
          'whatsapp_api_key': '',
          'sms_api_key': '',
          'auto_notify_day': 4,
          'auto_notify_time': '18:00',
        },
        conflictAlgorithm: ConflictAlgorithm.ignore);

    res = await db.query('settings', where: 'id = 1');
    return res.isNotEmpty ? res.first : {};
  }

  Future<List<Map<String, dynamic>>> getCollectiveResults(int examId) async {
    final db = await database;
    return await db.rawQuery('''
      SELECT er.*, s.name as student_name, s.father_name, s.roll_number, s.class_name
      FROM exam_results er
      JOIN students s ON er.student_id = s.id
      WHERE er.exam_id = ?
    ''', [examId]);
  }

  Future<List<Map<String, dynamic>>> getExamResults(int examId) async {
    final db = await database;
    return await db
        .query('exam_results', where: 'exam_id = ?', whereArgs: [examId]);
  }

  Future<void> insertExamResult(Map<String, dynamic> row) async {
    final db = await database;
    final existing = await db.query('exam_results',
        where: 'exam_id = ? AND student_id = ?',
        whereArgs: [row['exam_id'], row['student_id']]);

    if (existing.isNotEmpty) {
      await db.update('exam_results', row,
          where: 'id = ?', whereArgs: [existing.first['id']]);
    } else {
      await db.insert('exam_results', row);
    }
  }

  Future<List<Map<String, dynamic>>> getExams() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final db = await database;
    return await db.rawQuery('''
      SELECT e.*,
        (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id) AS result_count,
        (SELECT COUNT(*) FROM students s
          WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL)
            AND LOWER(COALESCE(s.status, 'Active')) = 'active'
            AND ($sysCond)) AS total_students
      FROM exams e
      WHERE (e.is_deleted = 0 OR e.is_deleted IS NULL)
        AND ($sysCond)
      ORDER BY e.id DESC
    ''');
  }

  Future<List<Map<String, dynamic>>> getStudentResults(int studentId) async {
    final db = await database;
    return await db.rawQuery('''
      SELECT er.*, e.exam_name, e.exam_date
      FROM exam_results er
      JOIN exams e ON er.exam_id = e.id
      WHERE er.student_id = ?
      ORDER BY e.exam_date DESC
    ''', [studentId]);
  }

  Future<void> _addColumnIfNotExists(
      Database db, String table, String column, String type) async {
    try {
      final columns = await db.rawQuery("PRAGMA table_info($table)");
      bool exists = columns.any((col) => col['name'] == column);
      if (!exists) {
        await db.execute("ALTER TABLE $table ADD COLUMN $column $type");
      }
    } catch (_) {}
  }

  Future<void> _createTeacherPeriodAttendanceTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS teacher_period_attendance(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER,
        class_id INTEGER,
        class_name TEXT,
        date TEXT,
        period_no INTEGER,
        status TEXT,
        late_minutes INTEGER DEFAULT 0,
        remarks TEXT,
        system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0
      )
    ''');
    await _addColumnIfNotExists(
        db, 'teacher_period_attendance', 'class_name', 'TEXT');
    await _addColumnIfNotExists(
        db, 'teacher_period_attendance', 'is_deleted', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(
        db, 'teacher_period_attendance', 'is_synced', 'INTEGER DEFAULT 0');
  }

  Future<void> forceMigrate(Database db) async {
    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_students(
          id INTEGER PRIMARY KEY,
          name TEXT,
          father_name TEXT,
          roll_number TEXT,
          admission_number TEXT,
          class TEXT,
          class_name TEXT,
          contact_number TEXT,
          address TEXT,
          password TEXT,
          status TEXT DEFAULT 'Active',
          admission_date TEXT,
          graduation_date TEXT,
          duration TEXT,
          photo TEXT,
          leaving_reason TEXT,
          serial_no INTEGER DEFAULT 0,
          hijri_year INTEGER DEFAULT 1447,
          system_type TEXT DEFAULT 'Masjid',
          residence_status TEXT DEFAULT '',
          alumni_category TEXT DEFAULT 'completed',
          wifaq_exam INTEGER DEFAULT 0,
          wifaq_marks TEXT,
          mehrab_recitation TEXT,
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_teachers(
          id INTEGER PRIMARY KEY,
          name TEXT,
          father_name TEXT,
          phone TEXT,
          cnic TEXT,
          qualification TEXT,
          salary TEXT,
          joining_date TEXT,
          status TEXT,
          address TEXT,
          photo TEXT,
          assigned_classes TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_classes(
          id INTEGER PRIMARY KEY,
          name TEXT,
          section TEXT DEFAULT 'A',
          class_type TEXT DEFAULT 'Maktab',
          status TEXT DEFAULT 'Active',
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS class_periods(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          class_id INTEGER,
          period_no INTEGER,
          books TEXT,
          books_list TEXT,
          teacher_id INTEGER,
          teacher_ids TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_exams(
          id INTEGER PRIMARY KEY,
          exam_name TEXT,
          exam_date TEXT,
          total_marks REAL DEFAULT 100,
          passing_marks REAL DEFAULT 33,
          status TEXT DEFAULT 'Announced',
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_attendance(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          date TEXT,
          status TEXT,
          remarks TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS masjid_assets(
          id INTEGER PRIMARY KEY,
          name TEXT,
          category TEXT,
          quantity INTEGER,
          condition TEXT,
          condition_status TEXT,
          asset_type TEXT,
          donor_name TEXT,
          donor_father TEXT,
          donor_address TEXT,
          donor_phone TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_synced INTEGER DEFAULT 0,
          is_deleted INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_nazira_record(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          class_name TEXT,
          teacher_id INTEGER,
          date TEXT,
          morning_sabaq TEXT,
          morning_qty TEXT,
          morning_time TEXT,
          morning_status TEXT,
          evening_sabaq TEXT,
          evening_qty TEXT,
          evening_time TEXT,
          evening_status TEXT,
          sabaq_p TEXT,
          sabaq_r TEXT,
          sabaq_s TEXT,
          sabaq_time TEXT,
          sabqi_p TEXT,
          sabqi_r TEXT,
          sabqi_s TEXT,
          sabqi_listener TEXT,
          sabqi_time TEXT,
          manzil_p TEXT,
          manzil_r TEXT,
          manzil_s TEXT,
          manzil_listener TEXT,
          manzil_time TEXT,
          status TEXT DEFAULT 'Active',
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_hifz_record(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          student_id TEXT,
          class_name TEXT,
          teacher_id INTEGER,
          date TEXT,
          sabaq_para TEXT,
          sabaq_lines TEXT,
          sabaq_grade TEXT,
          sabaq_time TEXT,
          sabqi_para TEXT,
          sabqi_qty TEXT,
          sabqi_grade TEXT,
          sabqi_listener TEXT,
          sabqi_time TEXT,
          sabqi_review_details TEXT,
          manzil_total_qty TEXT,
          manzil_details TEXT,
          manzil_grade TEXT,
          manzil_listener TEXT,
          manzil_time TEXT,
          sabaq_p TEXT,
          sabaq_l TEXT,
          sabaq_s TEXT,
          sabqi_p TEXT,
          sabqi_amt TEXT,
          sabqi_s TEXT,
          manzil_p TEXT,
          manzil_s TEXT,
          status TEXT DEFAULT 'Active',
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_qaida_records(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          class_name TEXT,
          teacher_id INTEGER,
          date TEXT,
          lesson_type TEXT DEFAULT 'نورانی قاعدہ',
          hearing_time TEXT,
          sabaq_1 TEXT, qty_1 TEXT, grade_1 TEXT,
          sabaq_2 TEXT, qty_2 TEXT, grade_2 TEXT,
          sabaq_3 TEXT, qty_3 TEXT, grade_3 TEXT,
          sabaq_4 TEXT, qty_4 TEXT, grade_4 TEXT,
          sabaq_book TEXT,
          sabaq_page TEXT,
          sabaq_status TEXT,
          sabaq_time TEXT,
          status TEXT DEFAULT 'Active',
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_academic_reports(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          class_name TEXT,
          date TEXT,
          month TEXT,
          year TEXT,
          type TEXT,
          subject TEXT,
          lesson_details TEXT,
          grade TEXT,
          attendance_days INTEGER,
          total_working_days INTEGER,
          sabaq_grade TEXT,
          sabqi_grade TEXT,
          manzil_grade TEXT,
          remarks TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS maktab_exam_results(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          exam_id INTEGER,
          student_id INTEGER,
          class_name TEXT,
          obtained_marks REAL,
          total_marks REAL DEFAULT 100,
          percentage REAL,
          grade TEXT,
          status TEXT DEFAULT 'Pass',
          remarks TEXT,
          system_type TEXT DEFAULT 'Masjid',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await _createAccountingTables(db);
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS academic_reports(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          date TEXT,
          class_name TEXT,
          subject TEXT,
          lesson_details TEXT,
          grade TEXT,
          remarks TEXT,
          system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS qaida_records(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          student_id INTEGER,
          date TEXT,
          sabaq_1 TEXT, qty_1 TEXT, grade_1 TEXT,
          sabaq_2 TEXT, qty_2 TEXT, grade_2 TEXT,
          sabaq_3 TEXT, qty_3 TEXT, grade_3 TEXT,
          sabaq_4 TEXT, qty_4 TEXT, grade_4 TEXT,
          lesson_type TEXT DEFAULT 'نورانی قاعدہ',
          hearing_time TEXT,
          system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}
    await _addColumnIfNotExists(
        db, 'qaida_records', 'is_deleted', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(
        db, 'qaida_records', 'is_synced', 'INTEGER DEFAULT 0');

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS hifz_record(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          date TEXT,
          student_id TEXT,
          sabaq_para TEXT,
          sabaq_lines TEXT,
          sabaq_grade TEXT,
          sabqi_para TEXT,
          sabqi_qty TEXT,
          sabqi_grade TEXT,
          sabqi_listener TEXT,
          manzil_total_qty TEXT,
          manzil_details TEXT,
          manzil_grade TEXT,
          manzil_listener TEXT,
          sabaq_time TEXT,
          sabqi_time TEXT,
          manzil_time TEXT,
          system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}
    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS husn_saut_record(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          student_id TEXT,
          date TEXT,
          manzil_sabaq TEXT,
          manzil_listener TEXT,
          manzil_grade TEXT,
          mashq_1_sabaq TEXT,
          mashq_1_time TEXT,
          mashq_2_sabaq TEXT,
          mashq_2_time TEXT,
          mashq_3_sabaq TEXT,
          mashq_3_time TEXT,
          mashq_4_sabaq TEXT,
          mashq_4_time TEXT,
          tilawat_qty TEXT,
          tilawat_parahs TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}
    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS departments(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT,
          description TEXT,
          status TEXT DEFAULT 'Active',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    await _addColumnIfNotExists(db, 'finance', 'server_id', 'INTEGER');
    await _addColumnIfNotExists(db, 'finance', 'head_name', 'TEXT');
    await _addColumnIfNotExists(
        db, 'finance', 'system_type', "TEXT DEFAULT 'Madrasa'");
    await _addColumnIfNotExists(db, 'finance', 'receipt_no', 'TEXT');
    await _addColumnIfNotExists(db, 'finance', 'spender_name', 'TEXT');
    await _addColumnIfNotExists(db, 'finance', 'signature_image', 'TEXT');
    await _addColumnIfNotExists(db, 'finance', 'account_id', 'INTEGER');
    await _addColumnIfNotExists(
        db, 'finance', 'payment_method', "TEXT DEFAULT 'Cash'");
    await _addColumnIfNotExists(db, 'masjid_finance', 'account_id', 'INTEGER');
    await _addColumnIfNotExists(
        db, 'masjid_finance', 'payment_method', "TEXT DEFAULT 'Cash'");
    await _addColumnIfNotExists(db, 'heads', 'is_deleted', 'INTEGER DEFAULT 0');
    await _addColumnIfNotExists(db, 'heads', 'is_synced', 'INTEGER DEFAULT 0');

    await _addColumnIfNotExists(db, 'students', 'class', 'TEXT');
    await _addColumnIfNotExists(db, 'students', 'class_name', 'TEXT');
    await _addColumnIfNotExists(db, 'students', 'admission_number', 'TEXT');
    await _addColumnIfNotExists(db, 'students', 'roll_number', 'TEXT');
    await _addColumnIfNotExists(
        db, 'students', 'residence_status', "TEXT DEFAULT ''");
    await _addColumnIfNotExists(
        db, 'students', 'system_type', "TEXT DEFAULT 'Madrasa'");

    await _addColumnIfNotExists(
        db, 'classes', 'system_type', "TEXT DEFAULT 'Madrasa'");

    await _addColumnIfNotExists(db, 'nazira_record', 'morning_qty', 'TEXT');
    await _addColumnIfNotExists(db, 'nazira_record', 'morning_time', 'TEXT');
    await _addColumnIfNotExists(db, 'nazira_record', 'evening_qty', 'TEXT');
    await _addColumnIfNotExists(db, 'nazira_record', 'evening_time', 'TEXT');

    // Classes columns
    final clsCols = [
      'class_type',
      'section',
      'main_teacher_ids',
      'p1_name',
      'p2_name',
      'p3_name',
      'p4_name',
      'p5_name',
      'p6_name',
      'subjects_list'
    ];
    for (var col in clsCols) {
      String def = col == 'class_type'
          ? '"Regular"'
          : (col == 'section' ||
                  col == 'main_teacher_ids' ||
                  col == 'subjects_list'
              ? '""'
              : '"Period ${col.substring(1, 2)}"');
      await _addColumnIfNotExists(db, 'classes', col, 'TEXT DEFAULT $def');
    }
    // Exam Results columns
    await _addColumnIfNotExists(db, 'exams', 'total_marks', 'REAL DEFAULT 100');
    await _addColumnIfNotExists(
        db, 'exams', 'passing_marks', 'REAL DEFAULT 33');

    final examCols = [
      'masail',
      'miqdar_khwandgi',
      'p1',
      'p2',
      'p3',
      'p4',
      'p5',
      'p6',
      'subjects_marks'
    ];
    for (var col in examCols) {
      String type = (col == 'miqdar_khwandgi' || col == 'subjects_marks')
          ? 'TEXT DEFAULT ""'
          : 'INTEGER DEFAULT 0';
      await _addColumnIfNotExists(db, 'exam_results', col, type);
    }

    final teacherCols = {
      'house_rent': 'REAL DEFAULT 0',
      'medical_allowance': 'REAL DEFAULT 0',
      'travel_allowance': 'REAL DEFAULT 0',
      'daily_deduction_rate': 'REAL DEFAULT 0',
      'photo': 'TEXT',
      'assigned_classes': 'TEXT',
      'assigned_periods': 'TEXT',
      'status_reason': 'TEXT',
      'status_change_date': 'TEXT',
    };
    for (final entry in teacherCols.entries) {
      await _addColumnIfNotExists(db, 'teachers', entry.key, entry.value);
    }

    final sysTypeTables = [
      'students',
      'teachers',
      'classes',
      'attendance',
      'exams',
      'exam_results',
      'finance',
      'acc_invoices',
      'acc_vouchers',
      'acc_payroll',
      'departments',
      'complaints',
      'in_kind_donations',
      'masjid_assets',
      'academic_reports',
      'qaida_records',
      'hifz_record',
      'nazira_record',
      'husn_saut_record',
      'maktab_attendance',
      'maktab_exams',
      'maktab_nazira_record',
      'maktab_hifz_record',
      'maktab_qaida_records',
      'maktab_academic_reports',
      'maktab_exam_results',
      'staff_loans'
    ];

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS staff_loans(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          teacher_id INTEGER,
          borrower_name TEXT,
          phone TEXT,
          loan_direction TEXT DEFAULT 'Given',
          loan_amount REAL DEFAULT 0,
          installment_amount REAL DEFAULT 0,
          repaid_amount REAL DEFAULT 0,
          remaining_amount REAL DEFAULT 0,
          date_issued TEXT,
          due_date TEXT,
          description TEXT,
          status TEXT DEFAULT 'Active',
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS acc_payroll(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          teacher_id INTEGER,
          month_year TEXT,
          basic_salary REAL DEFAULT 0,
          allowances REAL DEFAULT 0,
          deductions REAL DEFAULT 0,
          net_salary REAL DEFAULT 0,
          status TEXT DEFAULT 'Unpaid',
          payment_date TEXT,
          created_by INTEGER DEFAULT 1,
          system_type TEXT DEFAULT 'Madrasa',
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    final loanCols = {
      'loan_direction': "TEXT DEFAULT 'Given'",
      'borrower_name': "TEXT",
      'phone': "TEXT",
      'loan_amount': "REAL DEFAULT 0",
      'installment_amount': "REAL DEFAULT 0",
      'repaid_amount': "REAL DEFAULT 0",
      'remaining_amount': "REAL DEFAULT 0",
      'date_issued': "TEXT",
      'due_date': "TEXT",
      'description': "TEXT",
      'status': "TEXT DEFAULT 'Active'",
      'system_type': "TEXT DEFAULT 'Madrasa'",
      'is_deleted': "INTEGER DEFAULT 0",
      'is_synced': "INTEGER DEFAULT 0",
    };
    for (final entry in loanCols.entries) {
      await _addColumnIfNotExists(db, 'staff_loans', entry.key, entry.value);
    }

    final payrollCols = {
      'created_by': "INTEGER DEFAULT 1",
      'system_type': "TEXT DEFAULT 'Madrasa'",
      'payment_account_id': "INTEGER",
      'payment_method': "TEXT DEFAULT 'Cash'",
      'is_deleted': "INTEGER DEFAULT 0",
      'is_synced': "INTEGER DEFAULT 0",
    };
    for (final entry in payrollCols.entries) {
      await _addColumnIfNotExists(db, 'acc_payroll', entry.key, entry.value);
    }

    final alumniCols = {
      'alumni_category': "TEXT DEFAULT 'completed'",
      'wifaq_exam': "INTEGER DEFAULT 0",
      'wifaq_marks': "TEXT",
      'mehrab_recitation': "TEXT",
    };
    for (final entry in alumniCols.entries) {
      await _addColumnIfNotExists(db, 'students', entry.key, entry.value);
      await _addColumnIfNotExists(
          db, 'maktab_students', entry.key, entry.value);
    }

    for (var tbl in sysTypeTables) {
      String defVal = (tbl == 'masjid_assets' || tbl == 'masjid_finance')
          ? 'Masjid'
          : 'Madrasa';
      await _addColumnIfNotExists(
          db, tbl, 'system_type', "TEXT DEFAULT '$defVal'");
      try {
        await db.execute(
            "UPDATE $tbl SET system_type = '$defVal' WHERE system_type IS NULL OR system_type = ''");
      } catch (_) {}
    }

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS teacher_leaves(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          teacher_id INTEGER,
          leave_type TEXT,
          start_date TEXT,
          end_date TEXT,
          reason TEXT,
          status TEXT DEFAULT 'Pending',
          approved_by TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          created_at TEXT,
          updated_at TEXT,
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
      await _addColumnIfNotExists(
          db, 'teacher_leaves', 'system_type', "TEXT DEFAULT 'Madrasa'");
    } catch (_) {}

    await _addColumnIfNotExists(db, 'finance', 'bill_image', 'TEXT');
    await _addColumnIfNotExists(db, 'masjid_finance', 'bill_image', 'TEXT');

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS activity_logs(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          user_id INTEGER,
          user_name TEXT,
          action_type TEXT,
          description TEXT,
          created_at TEXT
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS admission_inquiries(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          student_name TEXT,
          father_name TEXT,
          phone TEXT,
          class_applied TEXT,
          admission_number TEXT,
          quran_edition TEXT DEFAULT '16 سطری',
          residence_status TEXT DEFAULT '',
          admission_date TEXT,
          graduation_date TEXT,
          duration TEXT,
          photo TEXT,
          gender TEXT DEFAULT 'Male',
          address TEXT,
          status TEXT DEFAULT 'Pending',
          interview_date TEXT,
          interview_notes TEXT,
          admin_notes TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          converted_student_id INTEGER,
          created_at TEXT,
          updated_at TEXT,
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS lib_books(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          book_title TEXT,
          author TEXT,
          category TEXT DEFAULT 'عام',
          isbn TEXT,
          rack_number TEXT,
          total_copies INTEGER DEFAULT 1,
          available_copies INTEGER DEFAULT 1,
          system_type TEXT DEFAULT 'Madrasa',
          created_at TEXT,
          updated_at TEXT,
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS lib_issues(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          server_id INTEGER,
          book_id INTEGER,
          borrower_type TEXT DEFAULT 'student',
          borrower_id INTEGER,
          borrower_name TEXT,
          borrower_contact TEXT,
          issue_date TEXT,
          due_date TEXT,
          return_date TEXT,
          status TEXT DEFAULT 'Issued',
          fine_amount REAL DEFAULT 0,
          remarks TEXT,
          system_type TEXT DEFAULT 'Madrasa',
          created_at TEXT,
          updated_at TEXT,
          is_deleted INTEGER DEFAULT 0,
          is_synced INTEGER DEFAULT 0
        )
      ''');
    } catch (_) {}

    try {
      await db.execute(
          "ALTER TABLE students ADD COLUMN quran_edition TEXT DEFAULT '16 سطری'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE students ADD COLUMN hifz_sequence TEXT DEFAULT 'reverse'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE maktab_students ADD COLUMN quran_edition TEXT DEFAULT '16 سطری'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE maktab_students ADD COLUMN hifz_sequence TEXT DEFAULT 'reverse'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN quran_edition TEXT DEFAULT '16 سطری'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN hifz_sequence TEXT DEFAULT 'reverse'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE students ADD COLUMN hifz_section TEXT DEFAULT 'regular'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE maktab_students ADD COLUMN hifz_section TEXT DEFAULT 'regular'");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN admission_number TEXT");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN admission_date TEXT");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN graduation_date TEXT");
    } catch (_) {}
    try {
      await db
          .execute("ALTER TABLE admission_inquiries ADD COLUMN duration TEXT");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE admission_inquiries ADD COLUMN residence_status TEXT DEFAULT ''");
    } catch (_) {}
    try {
      await db.execute("ALTER TABLE admission_inquiries ADD COLUMN photo TEXT");
    } catch (_) {}

    // Auto-heal Hifz records that were marked as synced prematurely or missed on server
    try {
      await db.execute('''
        CREATE TABLE IF NOT EXISTS sync_healing(
          id TEXT PRIMARY KEY,
          healed_at TEXT
        )
      ''');
      final checkHealed = await db.query(
        'sync_healing',
        where: 'id = ?',
        whereArgs: ['hifz_sync_healed_v1'],
      );
      if (checkHealed.isEmpty) {
        await db.rawUpdate('UPDATE hifz_record SET is_synced = 0');
        await db.rawUpdate('UPDATE maktab_hifz_record SET is_synced = 0');
        await db.insert('sync_healing', {
          'id': 'hifz_sync_healed_v1',
          'healed_at': DateTime.now().toIso8601String(),
        });
      }
    } catch (_) {}
  }

  Future<List<Map<String, dynamic>>> getTeacherLeaves(
      {int? teacherId, String? status, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = (sys == 'Masjid')
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    String where = '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond';
    List<dynamic> args = [];
    if (teacherId != null && teacherId > 0) {
      where += ' AND teacher_id = ?';
      args.add(teacherId);
    }
    if (status != null && status.isNotEmpty) {
      where += ' AND status = ?';
      args.add(status);
    }
    return await db.query('teacher_leaves',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC');
  }

  Future<int> insertTeacherLeave(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    final id = await db.insert('teacher_leaves', data);
    if (data['status'] == 'Approved') {
      await _applyTeacherLeaveAttendance(id, data);
    }
    return id;
  }

  Future<int> updateTeacherLeaveStatus(int id, String newStatus,
      {String? approvedBy}) async {
    Database db = await database;
    final res = await db.update(
      'teacher_leaves',
      {
        'status': newStatus,
        if (approvedBy != null) 'approved_by': approvedBy,
        'updated_at': DateTime.now().toIso8601String(),
        'is_synced': 0,
      },
      where: 'id = ?',
      whereArgs: [id],
    );

    if (newStatus == 'Approved') {
      final rows =
          await db.query('teacher_leaves', where: 'id = ?', whereArgs: [id]);
      if (rows.isNotEmpty) {
        await _applyTeacherLeaveAttendance(id, rows.first);
      }
    }
    return res;
  }

  Future<void> _applyTeacherLeaveAttendance(
      int leaveId, Map<String, dynamic> leave) async {
    try {
      Database db = await database;
      final teacherId = leave['teacher_id'] as int?;
      final startDateStr = leave['start_date']?.toString() ?? '';
      final endDateStr = leave['end_date']?.toString() ?? startDateStr;
      final sysType = leave['system_type']?.toString() ?? 'Madrasa';
      if (teacherId == null || startDateStr.isEmpty) return;

      DateTime start = DateTime.tryParse(startDateStr) ?? DateTime.now();
      DateTime end = DateTime.tryParse(endDateStr) ?? start;
      if (end.isBefore(start)) end = start;

      DateTime cur = start;
      while (!cur.isAfter(end)) {
        final dateKey = cur.toIso8601String().substring(0, 10);

        if (sysType == 'Masjid') {
          // 1. Maktab Teacher Attendance
          try {
            final existingM = await db.query('maktab_teacher_attendance',
                where: 'teacher_id = ? AND date = ?',
                whereArgs: [teacherId, dateKey]);
            if (existingM.isNotEmpty) {
              await db.update(
                'maktab_teacher_attendance',
                {'status': 'L', 'is_synced': 0},
                where: 'id = ?',
                whereArgs: [existingM.first['id']],
              );
            } else {
              await db.insert('maktab_teacher_attendance', {
                'teacher_id': teacherId,
                'date': dateKey,
                'status': 'L',
                'delay_minutes': 0,
                'is_synced': 0,
                'is_deleted': 0,
              });
            }
          } catch (_) {}

          // 2. Also record in attendance_teachers with system_type = 'Masjid'
          final existing = await db.query('attendance_teachers',
              where: 'teacher_id = ? AND date = ?',
              whereArgs: [teacherId, dateKey]);
          if (existing.isNotEmpty) {
            await db.update(
              'attendance_teachers',
              {'status': 'L', 'remarks': 'منظور شدہ رخصت', 'is_synced': 0},
              where: 'id = ?',
              whereArgs: [existing.first['id']],
            );
          } else {
            await db.insert('attendance_teachers', {
              'teacher_id': teacherId,
              'date': dateKey,
              'status': 'L',
              'late_minutes': 0,
              'remarks': 'منظور شدہ رخصت',
              'system_type': 'Masjid',
              'is_synced': 0,
              'is_deleted': 0,
            });
          }
        } else {
          // 1. Madrasa Teacher Attendance
          final existing = await db.query('attendance_teachers',
              where: 'teacher_id = ? AND date = ?',
              whereArgs: [teacherId, dateKey]);
          if (existing.isNotEmpty) {
            await db.update(
              'attendance_teachers',
              {'status': 'L', 'remarks': 'منظور شدہ رخصت', 'is_synced': 0},
              where: 'id = ?',
              whereArgs: [existing.first['id']],
            );
          } else {
            await db.insert('attendance_teachers', {
              'teacher_id': teacherId,
              'date': dateKey,
              'status': 'L',
              'late_minutes': 0,
              'remarks': 'منظور شدہ رخصت',
              'system_type': 'Madrasa',
              'is_synced': 0,
              'is_deleted': 0,
            });
          }

          // 2. Madrasa Period Attendance
          await db.update(
            'teacher_period_attendance',
            {'status': 'L', 'remarks': 'منظور شدہ رخصت', 'is_synced': 0},
            where: 'teacher_id = ? AND date = ?',
            whereArgs: [teacherId, dateKey],
          );
        }

        cur = cur.add(const Duration(days: 1));
      }

      await logActivity('leave',
          'استاذ کی رخصت منظور کی گئی ($sysType - از $startDateStr تا $endDateStr)',
          userName: leave['approved_by']?.toString());
    } catch (e) {
      debugPrint("Error applying teacher leave attendance: $e");
    }
  }

  Future<int> deleteTeacherLeave(int id) async {
    Database db = await database;
    return await db.update('teacher_leaves', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getActivityLogs({int limit = 20}) async {
    Database db = await database;
    try {
      return await db.query('activity_logs', orderBy: 'id DESC', limit: limit);
    } catch (_) {
      return [];
    }
  }

  Future<void> logActivity(String actionType, String description,
      {int? userId, String? userName}) async {
    try {
      Database db = await database;
      await db.insert('activity_logs', {
        'user_id': userId,
        'user_name': userName ?? 'سسٹم',
        'action_type': actionType,
        'description': description,
        'created_at': DateTime.now().toIso8601String(),
      });
    } catch (_) {}
  }

  // ===================== PHASE 2: ADMISSIONS PIPELINE =====================
  Future<List<Map<String, dynamic>>> getAdmissionInquiries(
      {String? status, String? systemType, String? search}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND status = ?";
      args.add(status);
    }

    if (search != null && search.trim().isNotEmpty) {
      where +=
          " AND (student_name LIKE ? OR father_name LIKE ? OR phone LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q, q]);
    }

    return await db.query('admission_inquiries',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC');
  }

  Future<int> insertAdmissionInquiry(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['status'] ??= 'Pending';
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('admission_inquiries', data);
    await logActivity(
        'admissions', 'نئی داخلہ درخواست درج کی گئی: ${data['student_name']}');
    return id;
  }

  Future<int> enrollInquiryToStudent(int inquiryId, String admClass,
      {String? customAdmNo,
      String? residenceStatus,
      String? quranEdition,
      String? duration}) async {
    Database db = await database;
    final rows = await db
        .query('admission_inquiries', where: 'id = ?', whereArgs: [inquiryId]);
    if (rows.isEmpty) return 0;
    final inq = rows.first;

    final sysType = inq['system_type']?.toString() ??
        SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sysType == 'Masjid';
    final targetTable = isMasjid ? 'maktab_students' : 'students';

    final sName = inq['student_name']?.toString() ?? '';
    final fName = inq['father_name']?.toString() ?? '';
    final phone = inq['phone']?.toString() ?? '';
    final addr = inq['address']?.toString() ?? '';
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final qEdition =
        quranEdition ?? inq['quran_edition']?.toString() ?? '16 سطری';
    final resStatus =
        residenceStatus ?? inq['residence_status']?.toString() ?? 'مقامی';
    final admDuration = duration ?? inq['duration']?.toString() ?? '';
    final photo = inq['photo']?.toString() ?? '';

    // Admission Number Generation if not provided
    String admNo = customAdmNo ?? inq['admission_number']?.toString() ?? '';
    if (admNo.isEmpty) {
      final maxRes =
          await db.rawQuery("SELECT MAX(id) as mid FROM $targetTable");
      final nextId = ((maxRes.first['mid'] as int?) ?? 0) + 1;
      admNo = '1447-${nextId.toString().padLeft(2, '0')}';
    }

    final newStdMap = <String, dynamic>{
      'name': sName,
      'father_name': fName,
      'admission_number': admNo,
      'class': admClass,
      if (!isMasjid) 'class_name': admClass,
      'contact_number': phone,
      'address': addr,
      'status': 'Active',
      'admission_date': today,
      'residence_status': resStatus,
      'quran_edition': qEdition,
      'duration': admDuration,
      'photo': photo,
      'system_type': sysType,
      'is_deleted': 0,
      'is_synced': 0,
    };

    final newStdId = await db.insert(targetTable, newStdMap);

    await db.update(
        'admission_inquiries',
        {
          'status': 'Accepted',
          'converted_student_id': newStdId,
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [inquiryId]);

    await logActivity('admissions',
        'طالب علم باقاعدہ داخل کر لیا گیا: $sName ($targetTable - داخلہ نمبر: $admNo)');
    return newStdId;
  }

  Future<int> updateAdmissionInquiry(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('admission_inquiries', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> directEnrollStudent(Map<String, dynamic> data) async {
    Database db = await database;
    final sysType = data['system_type']?.toString() ??
        SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sysType == 'Masjid';
    final targetTable = isMasjid ? 'maktab_students' : 'students';
    final sName = data['name']?.toString() ?? '';
    final fName = data['father_name']?.toString() ?? '';
    final phone = data['contact_number']?.toString() ?? '';
    final addr = data['address']?.toString() ?? '';
    final admClass = data['class']?.toString() ?? '';
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final qEdition = data['quran_edition']?.toString() ?? '16 سطری';
    final resStatus = data['residence_status']?.toString() ?? 'مقامی';
    final photo = data['photo']?.toString() ?? '';

    String admNo = data['admission_number']?.toString() ?? '';
    if (admNo.isEmpty) {
      final maxRes =
          await db.rawQuery("SELECT MAX(id) as mid FROM $targetTable");
      final nextId = ((maxRes.first['mid'] as int?) ?? 0) + 1;
      admNo = '1447-${nextId.toString().padLeft(2, '0')}';
    }

    final newStdMap = <String, dynamic>{
      'name': sName,
      'father_name': fName,
      'admission_number': admNo,
      'class': admClass,
      if (!isMasjid) 'class_name': admClass,
      'contact_number': phone,
      'address': addr,
      'status': 'Active',
      'admission_date': today,
      'residence_status': resStatus,
      'quran_edition': qEdition,
      'photo': photo,
      'system_type': sysType,
      'is_deleted': 0,
      'is_synced': 0,
    };

    final newStdId = await db.insert(targetTable, newStdMap);

    await db.insert('admission_inquiries', {
      'student_name': sName,
      'father_name': fName,
      'phone': phone,
      'class_applied': admClass,
      'address': addr,
      'status': 'Accepted',
      'converted_student_id': newStdId,
      'system_type': sysType,
      'admission_number': admNo,
      'residence_status': resStatus,
      'quran_edition': qEdition,
      'photo': photo,
      'created_at': DateTime.now().toIso8601String(),
      'is_deleted': 0,
      'is_synced': 0,
    });

    await logActivity('admissions',
        'ڈائریکٹ طالب علم داخل کیا گیا: $sName ($targetTable - داخلہ نمبر: $admNo)');
    return newStdId;
  }

  Future<List<Map<String, dynamic>>> getLibraryBooks(
      {String? category, String? systemType, String? search}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND (system_type = 'Masjid' OR system_type = 'Shared')";
    } else if (sys == 'Shared') {
      where += " AND system_type = 'Shared'";
    } else if (sys == 'All') {
      // no system_type filter
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type = 'Shared' OR system_type IS NULL OR system_type = '')";
    }

    if (category != null &&
        category.isNotEmpty &&
        category.toLowerCase() != 'all') {
      where += " AND category = ?";
      args.add(category);
    }

    if (search != null && search.trim().isNotEmpty) {
      where +=
          " AND (book_title LIKE ? OR author LIKE ? OR rack_number LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q, q]);
    }

    return await db.query('lib_books',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC');
  }

  Future<int> insertLibraryBook(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['total_copies'] ??= 1;
    data['available_copies'] ??= data['total_copies'];
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('lib_books', data);
    await logActivity('library', 'نئی کتاب شامل کی گئی: ${data['book_title']}');
    return id;
  }

  Future<int> updateLibraryBook(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db.update('lib_books', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteLibraryBook(int id) async {
    Database db = await database;
    return await db.update('lib_books', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getLibraryIssues(
      {int? bookId, String? status, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(i.is_deleted = 0 OR i.is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND i.system_type = 'Masjid'";
    } else {
      where +=
          " AND (i.system_type = 'Madrasa' OR i.system_type IS NULL OR i.system_type = '')";
    }

    if (bookId != null) {
      where += " AND i.book_id = ?";
      args.add(bookId);
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND i.status = ?";
      args.add(status);
    }

    final query = '''
      SELECT i.*, b.book_title, b.author, b.rack_number
      FROM lib_issues i
      LEFT JOIN lib_books b ON i.book_id = b.id
      WHERE $where
      ORDER BY i.id DESC
    ''';

    return await db.rawQuery(query, args.isNotEmpty ? args : null);
  }

  Future<bool> issueLibraryBook(Map<String, dynamic> issueData) async {
    Database db = await database;
    final bookId = issueData['book_id'] as int;
    final bRows =
        await db.query('lib_books', where: 'id = ?', whereArgs: [bookId]);
    if (bRows.isEmpty) return false;
    final avail = (bRows.first['available_copies'] as int?) ?? 0;
    if (avail <= 0) return false;

    var data = Map<String, dynamic>.from(issueData);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['status'] = 'Issued';
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;

    await db.transaction((txn) async {
      await txn.insert('lib_issues', data);
      await txn.rawUpdate(
        'UPDATE lib_books SET available_copies = MAX(0, available_copies - 1), is_synced = 0 WHERE id = ?',
        [bookId],
      );
    });

    final bTitle = bRows.first['book_title']?.toString() ?? '';
    final bName = data['borrower_name']?.toString() ?? '';
    await logActivity('library', 'کتاب جاری کی گئی: $bTitle برائے $bName');
    return true;
  }

  Future<bool> returnLibraryBook(int issueId,
      {double fine = 0, String? remarks}) async {
    Database db = await database;
    final iRows =
        await db.query('lib_issues', where: 'id = ?', whereArgs: [issueId]);
    if (iRows.isEmpty) return false;
    final issue = iRows.first;
    final bookId = issue['book_id'] as int;
    final today = DateTime.now().toIso8601String().substring(0, 10);

    await db.transaction((txn) async {
      await txn.update(
          'lib_issues',
          {
            'status': 'Returned',
            'return_date': today,
            'fine_amount': fine,
            'remarks': remarks ?? issue['remarks'],
            'updated_at': DateTime.now().toIso8601String(),
            'is_synced': 0,
          },
          where: 'id = ?',
          whereArgs: [issueId]);

      await txn.rawUpdate(
        'UPDATE lib_books SET available_copies = MIN(total_copies, available_copies + 1), is_synced = 0 WHERE id = ?',
        [bookId],
      );
    });

    await logActivity('library', 'کتاب واپس وصول کر لی گئی (ایشو #$issueId)');
    return true;
  }

  Future<List<Map<String, dynamic>>> getAccPayroll() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('acc_payroll',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        orderBy: 'id DESC');
  }

  Future<int> insertAccPayroll(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('acc_payroll', data);
  }

  Future<int> updateAccPayroll(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    try {
      return await db
          .update('acc_payroll', data, where: 'id = ?', whereArgs: [id]);
    } catch (e) {
      try {
        await _addColumnIfNotExists(
            db, 'acc_payroll', 'payment_account_id', 'INTEGER');
        await _addColumnIfNotExists(
            db, 'acc_payroll', 'payment_method', "TEXT DEFAULT 'Cash'");
        return await db
            .update('acc_payroll', data, where: 'id = ?', whereArgs: [id]);
      } catch (_) {
        data.remove('payment_account_id');
        data.remove('payment_method');
        return await db
            .update('acc_payroll', data, where: 'id = ?', whereArgs: [id]);
      }
    }
  }

  Future<int> deleteAccPayroll(int id) async {
    Database db = await database;
    return await db.update('acc_payroll', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getAccInvoices() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    return await db.query('acc_invoices',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        orderBy: 'id DESC');
  }

  Future<List<Map<String, dynamic>>> getDepartments() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    final db = await database;
    return await db.query('departments', orderBy: 'name ASC', where: sysCond);
  }

  Future<int> insertDepartment(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['is_deleted'] = data['is_deleted'] ?? 0;
    data['status'] = data['status'] ?? 'Active';
    return db.insert('departments', data);
  }

  Future<int> updateDepartment(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return db.update('departments', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> inactiveDepartment(int id) async {
    return updateDepartment(id, {'status': 'Inactive'});
  }

  Future<int> insertAccInvoice(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('acc_invoices', data);
  }

  Future<int> updateAccInvoice(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db
        .update('acc_invoices', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteComplaint(int id) async {
    Database db = await database;
    return await db.update('complaints', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<int> updateComplaint(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db
        .update('complaints', data, where: 'id = ?', whereArgs: [id]);
  }

  // --- Maktab Helper Methods ---

  Future<List<Map<String, dynamic>>> getMaktabStudents(
      {bool includeInactive = false}) async {
    Database db = await database;
    try {
      final statusCond = includeInactive
          ? "1=1"
          : "(status IS NULL OR LOWER(TRIM(status)) IN ('active', '', 'present'))";
      final list1 = await db.query('maktab_students',
          where: "$statusCond AND (is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: 'name ASC');
      final list2 = await db.query('students',
          where:
              "$statusCond AND (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Masjid' OR is_maktab = 1)",
          orderBy: 'name ASC');
      final combined = [...list1, ...list2];
      final Map<String, Map<String, dynamic>> unique = {};
      for (var s in combined) {
        String key = (s['id'] ?? s['name'] ?? '').toString();
        unique[key] = s;
      }
      return unique.values.toList();
    } catch (e) {
      try {
        return await db.query('students',
            where:
                "(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Masjid' OR is_maktab = 1)",
            orderBy: 'name ASC');
      } catch (_) {
        return [];
      }
    }
  }

  Future<int> insertMaktabStudent(Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_students', row);
  }

  Future<int> updateMaktabStudent(int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db
        .update('maktab_students', row, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabStudent(int id) async {
    Database db = await database;
    return await db.update('maktab_students', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getMaktabTeachers() async {
    Database db = await database;
    try {
      final list1 = await db.query('maktab_teachers',
          where:
              "(status IS NULL OR LOWER(TRIM(status)) IN ('active', '', 'present')) AND (is_deleted = 0 OR is_deleted IS NULL)",
          orderBy: 'name ASC');
      final list2 = await db.query('teachers',
          where:
              "(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Masjid' OR is_maktab = 1)",
          orderBy: 'name ASC');
      final combined = [...list1, ...list2];
      final Map<String, Map<String, dynamic>> unique = {};
      for (var t in combined) {
        String key = (t['id'] ?? t['name'] ?? '').toString();
        unique[key] = t;
      }
      return unique.values.toList();
    } catch (e) {
      try {
        return await db.query('teachers',
            where:
                "(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = 'Masjid' OR is_maktab = 1)",
            orderBy: 'name ASC');
      } catch (_) {
        return [];
      }
    }
  }

  Future<int> insertMaktabTeacher(Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_teachers', row);
  }

  Future<int> updateMaktabTeacher(int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db
        .update('maktab_teachers', row, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabTeacher(int id) async {
    Database db = await database;
    return await db.update('maktab_teachers', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getMaktabClasses() async {
    Database db = await database;
    final res = await db.query('classes',
        where:
            "system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: 'name ASC');
    if (res.isNotEmpty) return res;
    return await db.query('maktab_classes',
        where: '(is_deleted = 0 OR is_deleted IS NULL)', orderBy: 'name ASC');
  }

  Future<List<Map<String, dynamic>>> getMaktabClassesForUser() async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final assigned = currentUserAssignedClasses.value;
    if (isAdmin) {
      return getMaktabClasses();
    }
    if (assigned.isNotEmpty) {
      final db = await database;
      final placeholders = assigned.map((_) => '?').join(',');
      return await db.rawQuery(
        "SELECT * FROM classes WHERE name IN ($placeholders) AND system_type = 'Masjid' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC",
        assigned,
      );
    }
    return getMaktabClasses();
  }

  Future<List<Map<String, dynamic>>> getMaktabStudentsForUser(
      {bool includeInactive = false}) async {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final assigned = currentUserAssignedClasses.value;
    final statusCond = includeInactive
        ? "1=1"
        : "(status IS NULL OR LOWER(TRIM(status)) IN ('active', '', 'present'))";
    if (isAdmin) {
      return getMaktabStudents(includeInactive: includeInactive);
    }
    if (assigned.isNotEmpty) {
      final db = await database;
      final placeholders = assigned.map((_) => '?').join(',');
      return await db.rawQuery(
        "SELECT * FROM students WHERE (class_name IN ($placeholders) OR class IN ($placeholders)) AND system_type = 'Masjid' AND $statusCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC",
        [...assigned, ...assigned],
      );
    }
    final hasStudentPerm = role == 'nazim' ||
        currentUserPermissions.value.contains('manage_masjid_students') ||
        currentUserPermissions.value.contains('all');
    if (hasStudentPerm) {
      return getMaktabStudents(includeInactive: includeInactive);
    }
    return [];
  }

  Future<List<Map<String, dynamic>>> getPagedMaktabStudentsForUser(
      {required int limit,
      required int offset,
      String? status,
      String? searchQuery,
      String? alumniCategory}) async {
    Database db = await database;
    String query =
        "SELECT * FROM students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid'";
    List<dynamic> args = [];

    final role = currentUserRole.value?.toLowerCase() ?? '';
    final isAdmin =
        role == 'admin' || role == 'superadmin' || role == 'administrator';
    final assigned = currentUserAssignedClasses.value;
    if (!isAdmin && assigned.isNotEmpty) {
      final placeholders = assigned.map((_) => '?').join(',');
      query +=
          ' AND (class_name IN ($placeholders) OR class IN ($placeholders))';
      args.addAll([...assigned, ...assigned]);
    }

    if (status != null && status.isNotEmpty) {
      if (status == 'Active') {
        query +=
            " AND (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر'))";
      } else if (status == 'Alumni') {
        query +=
            " AND LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped')";
      } else {
        query += ' AND LOWER(status) = ?';
        args.add(status.toLowerCase());
      }
    }

    if (alumniCategory != null &&
        alumniCategory.isNotEmpty &&
        alumniCategory != 'all') {
      if (alumniCategory == 'completed') {
        query +=
            " AND (alumni_category = 'completed' OR alumni_category IS NULL OR alumni_category = '')";
      } else {
        query += ' AND alumni_category = ?';
        args.add(alumniCategory);
      }
    }

    if (searchQuery != null && searchQuery.isNotEmpty) {
      query +=
          ' AND (name LIKE ? OR roll_number LIKE ? OR admission_number LIKE ? OR class LIKE ? OR class_name LIKE ?)';
      final q = '%$searchQuery%';
      args.addAll([q, q, q, q, q]);
    }

    query += ' ORDER BY id DESC LIMIT ? OFFSET ?';
    args.addAll([limit, offset]);

    final res = await db.rawQuery(query, args);
    if (res.isNotEmpty) return res;

    // Fallback to maktab_students table if legacy records exist
    String fallbackQuery =
        'SELECT * FROM maktab_students WHERE (is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> fbArgs = [];
    if (status != null && status.isNotEmpty) {
      if (status == 'Active') {
        fallbackQuery +=
            " AND (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر'))";
      } else if (status == 'Alumni') {
        fallbackQuery +=
            " AND LOWER(TRIM(status)) IN ('alumni', 'graduated', 'finished', 'فارغ', 'فارغ التحصیل', 'left', 'خارج', 'expelled', 'dropped')";
      } else {
        fallbackQuery += ' AND LOWER(status) = ?';
        fbArgs.add(status.toLowerCase());
      }
    }
    if (alumniCategory != null &&
        alumniCategory.isNotEmpty &&
        alumniCategory != 'all') {
      if (alumniCategory == 'completed') {
        fallbackQuery +=
            " AND (alumni_category = 'completed' OR alumni_category IS NULL OR alumni_category = '')";
      } else {
        fallbackQuery += ' AND alumni_category = ?';
        fbArgs.add(alumniCategory);
      }
    }
    if (searchQuery != null && searchQuery.isNotEmpty) {
      fallbackQuery +=
          ' AND (name LIKE ? OR roll_number LIKE ? OR admission_number LIKE ? OR class LIKE ? OR class_name LIKE ?)';
      final q = '%$searchQuery%';
      fbArgs.addAll([q, q, q, q, q]);
    }
    fallbackQuery += ' ORDER BY id DESC LIMIT ? OFFSET ?';
    fbArgs.addAll([limit, offset]);
    return await db.rawQuery(fallbackQuery, fbArgs);
  }

  Future<int> insertMaktabClass(Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_classes', row);
  }

  Future<int> updateMaktabClass(int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db
        .update('maktab_classes', row, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabClass(int id) async {
    Database db = await database;
    return await db.update('maktab_classes', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getMaktabAttendance() async {
    Database db = await database;
    try {
      final list1 = await db.query('maktab_attendance',
          where:
              '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = "Masjid" OR system_type IS NULL OR system_type = "")');
      final list2 = await db.query('attendance',
          where:
              '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = "Masjid" OR is_maktab = 1)');
      return [...list1, ...list2];
    } catch (_) {
      return await db.query('maktab_attendance',
          where: '(is_deleted = 0 OR is_deleted IS NULL)');
    }
  }

  Future<List<Map<String, dynamic>>> getMaktabAttendanceByDate(
      String date) async {
    Database db = await database;
    try {
      final list1 = await db.query('maktab_attendance',
          where:
              'date = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = "Masjid" OR system_type IS NULL OR system_type = "")',
          whereArgs: [date]);
      final list2 = await db.query('attendance',
          where:
              'date = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND (system_type = "Masjid" OR is_maktab = 1)',
          whereArgs: [date]);
      return [...list1, ...list2];
    } catch (_) {
      return await db.query('maktab_attendance',
          where: 'date = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: [date]);
    }
  }

  Future<int> insertMaktabAttendance(Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_attendance', row);
  }

  Future<int> updateMaktabAttendance(int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db
        .update('maktab_attendance', row, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabAttendance(int id) async {
    Database db = await database;
    return await db.update(
        'maktab_attendance', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<void> saveMaktabAttendance(int studentId, String date, String status,
      int delay, int userId, String userName) async {
    Database db = await database;
    final existing = await db.query('maktab_attendance',
        where: 'student_id = ? AND date = ?', whereArgs: [studentId, date]);
    if (existing.isNotEmpty) {
      await db.update(
          'maktab_attendance',
          {
            'status': status,
            'delay_minutes': delay,
            'user_id': userId,
            'user_name': userName,
            'is_synced': 0,
            'is_deleted': 0
          },
          where: 'id = ?',
          whereArgs: [existing.first['id']]);
    } else {
      await db.insert('maktab_attendance', {
        'student_id': studentId,
        'date': date,
        'status': status,
        'delay_minutes': delay,
        'user_id': userId,
        'user_name': userName,
        'is_synced': 0,
        'is_deleted': 0
      });
    }
  }

  Future<List<Map<String, dynamic>>> getMaktabAcademics() async {
    Database db = await database;
    return await db.query('maktab_academics',
        where:
            '(is_deleted = 0 OR is_deleted IS NULL) AND system_type = \'${SystemTypeGlobal.currentSystemType.value}\'',
        orderBy: 'date DESC');
  }

  Future<int> insertMaktabAcademic(Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_academics', row);
  }

  Future<int> updateMaktabAcademic(int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db
        .update('maktab_academics', row, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabAcademic(int id) async {
    Database db = await database;
    return await db.update(
        'maktab_academics', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getMaktabTeacherAttendance() async {
    Database db = await database;
    return await db.query('maktab_teacher_attendance',
        where:
            '(is_deleted = 0 OR is_deleted IS NULL) AND system_type = \'${SystemTypeGlobal.currentSystemType.value}\'');
  }

  Future<List<Map<String, dynamic>>> getMaktabTeacherPeriodAttendanceByDate(
      String date) async {
    Database db = await database;
    return await db.query('maktab_teacher_attendance',
        where:
            'date = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND system_type = \'${SystemTypeGlobal.currentSystemType.value}\'',
        whereArgs: [date]);
  }

  Future<int> insertMaktabTeacherPeriodAttendance(
      Map<String, dynamic> row) async {
    Database db = await database;
    return await db.insert('maktab_teacher_attendance', row);
  }

  Future<int> updateMaktabTeacherAttendance(
      int id, Map<String, dynamic> row) async {
    Database db = await database;
    return await db.update('maktab_teacher_attendance', row,
        where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteMaktabTeacherAttendance(int id) async {
    Database db = await database;
    return await db.update(
        'maktab_teacher_attendance', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<void> saveMaktabTeacherPeriodAttendance(int teacherId, String date,
      int period, String status, int delay, int userId, String userName) async {
    Database db = await database;
    final existing = await db.query('maktab_teacher_attendance',
        where: 'teacher_id = ? AND date = ?', whereArgs: [teacherId, date]);
    if (existing.isNotEmpty) {
      await db.update(
          'maktab_teacher_attendance',
          {
            'status': status,
            'delay_minutes': delay,
            'user_id': userId,
            'user_name': userName,
            'is_synced': 0,
            'is_deleted': 0
          },
          where: 'id = ?',
          whereArgs: [existing.first['id']]);
    } else {
      await db.insert('maktab_teacher_attendance', {
        'teacher_id': teacherId,
        'date': date,
        'status': status,
        'delay_minutes': delay,
        'user_id': userId,
        'user_name': userName,
        'is_synced': 0,
        'is_deleted': 0
      });
    }
  }

  Future<List<Map<String, dynamic>>> searchMaktabStudentsForUser(
      String queryStr,
      {String? status}) async {
    return getPagedMaktabStudentsForUser(
        limit: 100, offset: 0, status: status, searchQuery: queryStr);
  }

  Future<List<Map<String, dynamic>>> getPagedMaktabTeachersForUser(
      {required int limit, required int offset}) async {
    Database db = await database;
    return await db.query('maktab_teachers',
        where:
            '(is_deleted = 0 OR is_deleted IS NULL) AND system_type = \'${SystemTypeGlobal.currentSystemType.value}\'',
        limit: limit,
        offset: offset,
        orderBy: 'id DESC');
  }

  Future<List<Map<String, dynamic>>> searchMaktabTeachersForUser(
      String queryStr) async {
    Database db = await database;
    return await db.query('maktab_teachers',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND name LIKE ?',
        whereArgs: ['%$queryStr%'],
        orderBy: 'id DESC');
  }

  Future<List<Map<String, dynamic>>> getMaktabExamsForUser() async {
    Database db = await database;
    return await db.query('maktab_exams',
        where: '(is_deleted = 0 OR is_deleted IS NULL)', orderBy: 'id DESC');
  }

  Future<List<Map<String, dynamic>>> getStaff() async {
    final db = await database;
    return await db.query('teachers',
        where:
            'is_deleted = 0 AND system_type = \'${SystemTypeGlobal.currentSystemType.value}\' AND staff_type != \'Teacher\' AND staff_type IS NOT NULL AND staff_type != \'\' AND staff_type != \'استاد / قاری\'');
  }

  Future<List<Map<String, dynamic>>> getMaktabStaff() async {
    final db = await database;
    return await db.query('teachers',
        where:
            "(is_deleted = 0 OR is_deleted IS NULL) AND system_type = 'Masjid' AND staff_type != 'Teacher' AND staff_type IS NOT NULL AND staff_type != '' AND staff_type != 'استاد / قاری'");
  }

  Future<List<Map<String, dynamic>>> getInKindDonations(
      {String? systemType}) async {
    final effectiveSysType = systemType ?? SystemTypeGlobal.currentSystemType.value;
    final sysCond = effectiveSysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

    Database db = await database;
    await db.execute('''
      CREATE TABLE IF NOT EXISTS in_kind_donations(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER NULL,
        date TEXT,
        item_name TEXT,
        quantity INTEGER,
        donor_name TEXT,
        donor_phone TEXT,
        donor_address TEXT,
        description TEXT,
        estimated_value REAL,
        computer_receipt_no INTEGER,
        system_type TEXT DEFAULT 'Madrasa',
        is_synced INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,
        user_id INTEGER,
        user_name TEXT
      )
    ''');
    return await db.query('in_kind_donations',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        orderBy: 'date DESC, id DESC');
  }

  Future<int> insertInKindDonation(Map<String, dynamic> data) async {
    Database db = await database;
    await db.execute('''
      CREATE TABLE IF NOT EXISTS in_kind_donations(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER NULL,
        date TEXT,
        item_name TEXT,
        quantity INTEGER,
        donor_name TEXT,
        donor_phone TEXT,
        donor_address TEXT,
        description TEXT,
        estimated_value REAL,
        computer_receipt_no INTEGER,
        system_type TEXT DEFAULT 'Masjid',
        is_synced INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,
        user_id INTEGER,
        user_name TEXT
      )
    ''');
    data['is_synced'] = 0;
    return await db.insert('in_kind_donations', data);
  }

  Future<int> updateInKindDonation(int id, Map<String, dynamic> data) async {
    Database db = await database;
    data['is_synced'] = 0;
    return await db
        .update('in_kind_donations', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteInKindDonation(int id) async {
    Database db = await database;
    return await db.update(
        'in_kind_donations', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<Map<String, dynamic>?> getClassByName(String name) async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    Database db = await database;
    final res = await db.query('classes',
        where: 'name = ? AND $sysCond', whereArgs: [name], limit: 1);
    return res.isNotEmpty ? res.first : null;
  }

  Future<void> _createClassPeriodsTable(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS class_periods(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER,
        class_id INTEGER,
        period_no INTEGER,
        books TEXT,
        books_list TEXT,
        teacher_id INTEGER,
        teacher_ids TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0
      )
    ''');
  }

  Future<List<Map<String, dynamic>>> getClassPeriods(dynamic classId) async {
    final db = await database;
    await _createClassPeriodsTable(db);
    try {
      final res = await db.query(
        'class_periods',
        where: 'class_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
        whereArgs: [classId],
        orderBy: 'period_no ASC',
      );
      return res;
    } catch (e) {
      debugPrint('Error getting class periods: $e');
      return [];
    }
  }

  Future<void> saveClassPeriodConfig(
      dynamic cls, int p, String pName, String sName) async {
    final db = await database;
    await _createClassPeriodsTable(db);
    try {
      final existing = await db.query(
        'class_periods',
        where: 'class_id = ? AND period_no = ?',
        whereArgs: [cls, p],
      );
      if (existing.isNotEmpty) {
        await db.update(
          'class_periods',
          {'books': pName, 'books_list': sName, 'is_synced': 0},
          where: 'class_id = ? AND period_no = ?',
          whereArgs: [cls, p],
        );
      } else {
        await db.insert('class_periods', {
          'class_id': cls,
          'period_no': p,
          'books': pName,
          'books_list': sName,
          'is_synced': 0,
        });
      }
    } catch (e) {
      debugPrint('Error saving class period config: $e');
    }
  }

  Future<void> addClassPeriodBook(dynamic cls, int pd, String book) async {
    final db = await database;
    await _createClassPeriodsTable(db);
    try {
      final existing = await db.query(
        'class_periods',
        where: 'class_id = ? AND period_no = ?',
        whereArgs: [cls, pd],
      );
      if (existing.isNotEmpty) {
        String currentBooksList =
            existing.first['books_list']?.toString() ?? '';
        List<String> list = currentBooksList
            .split(',')
            .map((e) => e.trim())
            .where((e) => e.isNotEmpty)
            .toList();
        if (!list.contains(book.trim())) {
          list.add(book.trim());
          await db.update(
            'class_periods',
            {'books_list': list.join(','), 'is_synced': 0},
            where: 'class_id = ? AND period_no = ?',
            whereArgs: [cls, pd],
          );
        }
      } else {
        await db.insert('class_periods', {
          'class_id': cls,
          'period_no': pd,
          'books': 'مضمون $pd',
          'books_list': book.trim(),
          'is_synced': 0,
        });
      }
    } catch (e) {
      debugPrint('Error adding class period book: $e');
    }
  }

  Future<int> insertHusnSaut(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.insert('husn_saut_record', data);
  }

  Future<List<Map<String, dynamic>>> getHusnSautRecords() async {
    final sysType = SystemTypeGlobal.currentSystemType.value;
    final sysCond = sysType == 'Masjid'
        ? "system_type = 'Masjid'"
        : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    final db = await database;
    try {
      return await db.query(
        'husn_saut_record',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
      );
    } catch (_) {
      try {
        await db.execute('''
          CREATE TABLE IF NOT EXISTS husn_saut_record(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id INTEGER,
            student_id TEXT,
            date TEXT,
            manzil_sabaq TEXT,
            manzil_listener TEXT,
            manzil_grade TEXT,
            mashq_1_sabaq TEXT,
            mashq_1_time TEXT,
            mashq_2_sabaq TEXT,
            mashq_2_time TEXT,
            mashq_3_sabaq TEXT,
            mashq_3_time TEXT,
            mashq_4_sabaq TEXT,
            mashq_4_time TEXT,
            tilawat_qty TEXT,
            tilawat_parahs TEXT,
            system_type TEXT DEFAULT 'Madrasa',
            is_deleted INTEGER DEFAULT 0,
            is_synced INTEGER DEFAULT 0
          )
        ''');
        return await db.query(
          'husn_saut_record',
          where: '(is_deleted = 0 OR is_deleted IS NULL) AND $sysCond',
        );
      } catch (e) {
        debugPrint('Error getting husn saut records: $e');
        return [];
      }
    }
  }

  Future<void> toggleSmartAlertRead(
      String alertId, int userId, bool isRead) async {
    final db = await database;
    try {
      if (isRead) {
        await db.insert(
            'smart_notification_reads',
            {
              'alert_id': alertId,
              'user_id': userId,
              'created_at': DateTime.now().toIso8601String(),
              'is_deleted': 0,
              'is_synced': 0
            },
            conflictAlgorithm: ConflictAlgorithm.replace);
      } else {
        await db.update(
            'smart_notification_reads', {'is_deleted': 1, 'is_synced': 0},
            where: 'alert_id = ? AND user_id = ?',
            whereArgs: [alertId, userId]);
      }
    } catch (e) {
      debugPrint('Error toggling smart alert read: $e');
    }
  }

  Future<List<String>> getReadSmartAlerts(int userId) async {
    final db = await database;
    try {
      final res = await db.query('smart_notification_reads',
          columns: ['alert_id'],
          where: 'user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          whereArgs: [userId]);
      return res.map((row) => row['alert_id'].toString()).toList();
    } catch (e) {
      debugPrint('Error getting read smart alerts: $e');
      return [];
    }
  }

  // ==========================================
  // PHASE 3 & 4: TABLES & OPERATIONS METHODS
  // ==========================================

  static Future<void> _createPhase34Tables(Database db) async {
    // 1. Inventory Items
    await db.execute('''
      CREATE TABLE IF NOT EXISTS inventory_items(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_code TEXT,
        name TEXT,
        category TEXT DEFAULT 'عام',
        unit_price REAL DEFAULT 0.0,
        purchase_price REAL DEFAULT 0.0,
        current_stock INTEGER DEFAULT 0,
        alert_threshold INTEGER DEFAULT 5,
        unit TEXT DEFAULT 'عدد',
        description TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 2. Inventory Sales
    await db.execute('''
      CREATE TABLE IF NOT EXISTS inventory_sales(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_no TEXT,
        item_id INTEGER,
        item_name TEXT,
        student_id INTEGER,
        customer_name TEXT,
        quantity INTEGER DEFAULT 1,
        unit_price REAL DEFAULT 0.0,
        total_amount REAL DEFAULT 0.0,
        payment_mode TEXT DEFAULT 'Cash',
        voucher_id INTEGER,
        date TEXT,
        remarks TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        created_by INTEGER,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 3. Hostel Rooms
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hostel_rooms(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_number TEXT,
        room_name TEXT,
        capacity_beds INTEGER DEFAULT 4,
        monthly_fee REAL DEFAULT 0.0,
        status TEXT DEFAULT 'Active',
        remarks TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 4. Hostel Allocations
    await db.execute('''
      CREATE TABLE IF NOT EXISTS hostel_allocations(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        student_id INTEGER,
        student_name TEXT,
        bed_number TEXT,
        allocation_date TEXT,
        vacating_date TEXT,
        status TEXT DEFAULT 'Occupied',
        remarks TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 5. Job Postings
    await db.execute('''
      CREATE TABLE IF NOT EXISTS job_postings(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        department TEXT,
        job_type TEXT DEFAULT 'Full-time',
        requirements TEXT,
        salary_range TEXT,
        deadline TEXT,
        status TEXT DEFAULT 'Open',
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 6. Job Applicants
    await db.execute('''
      CREATE TABLE IF NOT EXISTS job_applicants(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER,
        applicant_name TEXT,
        father_name TEXT,
        phone TEXT,
        email TEXT,
        qualification TEXT,
        experience TEXT,
        resume_path TEXT,
        status TEXT DEFAULT 'Pending',
        interview_date TEXT,
        interview_notes TEXT,
        notes TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        converted_teacher_id INTEGER,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 7. Web Pages
    await db.execute('''
      CREATE TABLE IF NOT EXISTS web_pages(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_slug TEXT UNIQUE,
        title TEXT,
        content_html TEXT,
        meta_description TEXT,
        status TEXT DEFAULT 'Published',
        sort_order INTEGER DEFAULT 0,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // 8. Announcements
    await db.execute('''
      CREATE TABLE IF NOT EXISTS announcements(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        body TEXT,
        image_url TEXT,
        category TEXT DEFAULT 'خبر',
        is_public INTEGER DEFAULT 1,
        target_audience TEXT DEFAULT 'All',
        publish_date TEXT,
        system_type TEXT DEFAULT 'Madrasa',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    // Ensure finance & masjid_finance columns for account_id and payment_method
    try {
      await db.execute("ALTER TABLE finance ADD COLUMN account_id INTEGER");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE finance ADD COLUMN payment_method TEXT DEFAULT 'Cash'");
    } catch (_) {}
    try {
      await db
          .execute("ALTER TABLE masjid_finance ADD COLUMN account_id INTEGER");
    } catch (_) {}
    try {
      await db.execute(
          "ALTER TABLE masjid_finance ADD COLUMN payment_method TEXT DEFAULT 'Cash'");
    } catch (_) {}
  }

  // --- COMPREHENSIVE ACADEMIC SYSTEM (DARSE NIZAMI & ASRI) SCHEMA & METHODS ---
  static Future<void> _createAcademicSystemTables(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS course_structure(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER DEFAULT 1,
        name TEXT NOT NULL,
        course_type TEXT DEFAULT 'Darse_Nizami',
        total_years INTEGER DEFAULT 8,
        gender TEXT DEFAULT 'بنین',
        description TEXT,
        is_active INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
      )
    ''');

    await db.execute('''
      CREATE TABLE IF NOT EXISTS course_subjects(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER DEFAULT 1,
        course_id INTEGER NOT NULL,
        year_no INTEGER DEFAULT 1,
        year_title TEXT,
        subject_name TEXT NOT NULL,
        book_name TEXT,
        max_marks REAL DEFAULT 100.0,
        pass_marks REAL DEFAULT 40.0,
        subject_order INTEGER DEFAULT 0,
        is_optional INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT
      )
    ''');

    await db.execute('''
      CREATE TABLE IF NOT EXISTS student_course_enrollment(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER DEFAULT 1,
        student_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL,
        current_year INTEGER DEFAULT 1,
        academic_session TEXT,
        enrollment_date TEXT,
        status TEXT DEFAULT 'active',
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT
      )
    ''');

    await db.execute('''
      CREATE TABLE IF NOT EXISTS exam_subject_results(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER DEFAULT 1,
        exam_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        course_id INTEGER DEFAULT 0,
        year_no INTEGER DEFAULT 1,
        subject_id INTEGER DEFAULT 0,
        subject_name TEXT NOT NULL,
        marks_obtained REAL DEFAULT 0.0,
        max_marks REAL DEFAULT 100.0,
        is_absent INTEGER DEFAULT 0,
        grade TEXT DEFAULT 'مقبول',
        remarks TEXT,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT
      )
    ''');

    await db.execute('''
      CREATE TABLE IF NOT EXISTS student_year_promotion(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER DEFAULT 1,
        student_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL,
        from_year INTEGER NOT NULL,
        to_year INTEGER NOT NULL,
        academic_session TEXT,
        status TEXT DEFAULT 'Promoted',
        promoted_by INTEGER,
        remarks TEXT,
        is_deleted INTEGER DEFAULT 0,
        is_synced INTEGER DEFAULT 0,
        created_at TEXT
      )
    ''');

    final acadIndexes = [
      "CREATE INDEX IF NOT EXISTS idx_course_struct_type ON course_structure(course_type, is_active)",
      "CREATE INDEX IF NOT EXISTS idx_course_subj_course_yr ON course_subjects(course_id, year_no)",
      "CREATE INDEX IF NOT EXISTS idx_std_enroll_std_course ON student_course_enrollment(student_id, course_id)",
      "CREATE INDEX IF NOT EXISTS idx_exam_subj_res_exam_std ON exam_subject_results(exam_id, student_id)",
      "CREATE INDEX IF NOT EXISTS idx_exam_subj_res_course_yr ON exam_subject_results(course_id, year_no)",
      "CREATE INDEX IF NOT EXISTS idx_std_promo_std_course ON student_year_promotion(student_id, course_id)"
    ];
    for (final q in acadIndexes) {
      try {
        await db.execute(q);
      } catch (_) {}
    }
  }

  // --- ACADEMIC METHODS: COURSES ---
  Future<List<Map<String, dynamic>>> getCourses({String? courseType, int? isActive}) async {
    final db = await database;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [];
    if (courseType != null && courseType.isNotEmpty) {
      where += ' AND course_type = ?';
      args.add(courseType);
    }
    if (isActive != null) {
      where += ' AND is_active = ?';
      args.add(isActive);
    }
    return await db.query('course_structure',
        where: where, whereArgs: args.isNotEmpty ? args : null, orderBy: 'id ASC');
  }

  Future<int> insertCourse(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();
    return await db.insert('course_structure', data);
  }

  Future<int> updateCourse(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    data['updated_at'] = DateTime.now().toIso8601String();
    return await db.update('course_structure', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteCourse(int id) async {
    final db = await database;
    return await db.update('course_structure', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  // --- ACADEMIC METHODS: SUBJECTS ---
  Future<List<Map<String, dynamic>>> getCourseSubjects(int courseId, {int? yearNo}) async {
    final db = await database;
    String where = 'course_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)';
    final List<dynamic> args = [courseId];
    if (yearNo != null) {
      where += ' AND year_no = ?';
      args.add(yearNo);
    }
    return await db.query('course_subjects',
        where: where, whereArgs: args, orderBy: 'year_no ASC, subject_order ASC, id ASC');
  }

  Future<int> insertCourseSubject(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();
    return await db.insert('course_subjects', data);
  }

  Future<int> updateCourseSubject(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.update('course_subjects', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteCourseSubject(int id) async {
    final db = await database;
    return await db.update('course_subjects', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  // --- ACADEMIC METHODS: ENROLLMENT ---
  Future<Map<String, dynamic>?> getStudentCourseEnrollment(int studentId) async {
    final db = await database;
    final rows = await db.rawQuery('''
      SELECT e.*, c.name as course_name, c.course_type, c.total_years
      FROM student_course_enrollment e
      LEFT JOIN course_structure c ON c.id = e.course_id
      WHERE e.student_id = ? AND (e.is_deleted = 0 OR e.is_deleted IS NULL)
      ORDER BY e.id DESC LIMIT 1
    ''', [studentId]);
    if (rows.isNotEmpty) return rows.first;
    return null;
  }

  Future<List<Map<String, dynamic>>> getCourseEnrollments({
    int? courseId,
    int? yearNo,
    String? session,
    String? status,
  }) async {
    final db = await database;
    String where = '(e.is_deleted = 0 OR e.is_deleted IS NULL) AND (s.is_deleted = 0 OR s.is_deleted IS NULL)';
    final List<dynamic> args = [];
    if (courseId != null) {
      where += ' AND e.course_id = ?';
      args.add(courseId);
    }
    if (yearNo != null) {
      where += ' AND e.current_year = ?';
      args.add(yearNo);
    }
    if (session != null && session.isNotEmpty) {
      where += ' AND e.academic_session = ?';
      args.add(session);
    }
    if (status != null && status.isNotEmpty) {
      where += ' AND e.status = ?';
      args.add(status);
    }

    return await db.rawQuery('''
      SELECT e.*, s.name as student_name, s.father_name, s.roll_number, s.photo,
             c.name as course_name, c.total_years
      FROM student_course_enrollment e
      JOIN students s ON s.id = e.student_id
      LEFT JOIN course_structure c ON c.id = e.course_id
      WHERE $where
      ORDER BY s.roll_number ASC, s.name ASC
    ''', args.isNotEmpty ? args : null);
  }

  Future<int> enrollStudentInCourse(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();

    // Deactivate prior active enrollments for this student
    if (data['student_id'] != null) {
      await db.update(
        'student_course_enrollment',
        {'status': 'inactive', 'is_synced': 0},
        where: 'student_id = ? AND status = ?',
        whereArgs: [data['student_id'], 'active'],
      );
    }
    return await db.insert('student_course_enrollment', data);
  }

  Future<int> updateStudentEnrollment(int id, Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_synced'] = 0;
    return await db.update('student_course_enrollment', data, where: 'id = ?', whereArgs: [id]);
  }

  // --- ACADEMIC METHODS: RESULTS ---
  Future<List<Map<String, dynamic>>> getExamSubjectResults({
    int? examId,
    int? courseId,
    int? yearNo,
    int? studentId,
  }) async {
    final db = await database;
    String where = '(r.is_deleted = 0 OR r.is_deleted IS NULL)';
    final List<dynamic> args = [];
    if (examId != null && examId > 0) {
      where += ' AND r.exam_id = ?';
      args.add(examId);
    }
    if (courseId != null && courseId > 0) {
      where += ' AND r.course_id = ?';
      args.add(courseId);
    }
    if (yearNo != null && yearNo > 0) {
      where += ' AND r.year_no = ?';
      args.add(yearNo);
    }
    if (studentId != null && studentId > 0) {
      where += ' AND r.student_id = ?';
      args.add(studentId);
    }

    return await db.rawQuery('''
      SELECT r.*, s.name as student_name, s.father_name, s.roll_number,
             sub.max_marks as subject_max_marks, sub.pass_marks as subject_pass_marks,
             sub.book_name
      FROM exam_subject_results r
      JOIN students s ON s.id = r.student_id
      LEFT JOIN course_subjects sub ON sub.id = r.subject_id
      WHERE $where
      ORDER BY s.roll_number ASC, s.name ASC, r.subject_id ASC
    ''', args);
  }

  Future<int> saveExamSubjectResult(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();

    final examId = data['exam_id'];
    final studentId = data['student_id'];
    final subjectId = data['subject_id'];

    if (examId != null && studentId != null && subjectId != null) {
      final existing = await db.query(
        'exam_subject_results',
        where: 'exam_id = ? AND student_id = ? AND subject_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
        whereArgs: [examId, studentId, subjectId],
        limit: 1,
      );
      if (existing.isNotEmpty) {
        return await db.update(
          'exam_subject_results',
          data,
          where: 'id = ?',
          whereArgs: [existing.first['id']],
        );
      }
    }
    return await db.insert('exam_subject_results', data);
  }

  Future<void> saveBulkExamSubjectResults(List<Map<String, dynamic>> results) async {
    final db = await database;
    await db.transaction((txn) async {
      for (final row in results) {
        final data = Map<String, dynamic>.from(row);
        data['is_deleted'] = 0;
        data['is_synced'] = 0;
        data['created_at'] ??= DateTime.now().toIso8601String();

        final examId = data['exam_id'];
        final studentId = data['student_id'];
        final subjectId = data['subject_id'];

        if (examId != null && studentId != null && subjectId != null) {
          final existing = await txn.query(
            'exam_subject_results',
            where: 'exam_id = ? AND student_id = ? AND subject_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [examId, studentId, subjectId],
            limit: 1,
          );
          if (existing.isNotEmpty) {
            await txn.update(
              'exam_subject_results',
              data,
              where: 'id = ?',
              whereArgs: [existing.first['id']],
            );
            continue;
          }
        }
        await txn.insert('exam_subject_results', data);
      }
    });
  }

  // --- ACADEMIC METHODS: PROMOTION ---
  Future<int> promoteStudent(Map<String, dynamic> row) async {
    final db = await database;
    final data = Map<String, dynamic>.from(row);
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    data['created_at'] ??= DateTime.now().toIso8601String();

    final promoId = await db.insert('student_year_promotion', data);

    // If status is 'Promoted', also update the student's current enrollment
    if (data['status'] == 'Promoted' && data['student_id'] != null && data['to_year'] != null) {
      await db.update(
        'student_course_enrollment',
        {
          'current_year': data['to_year'],
          'academic_session': data['academic_session'],
          'is_synced': 0,
        },
        where: 'student_id = ? AND course_id = ? AND status = ?',
        whereArgs: [data['student_id'], data['course_id'], 'active'],
      );
    }
    return promoId;
  }

  Future<List<Map<String, dynamic>>> getStudentPromotions(int studentId) async {
    final db = await database;
    return await db.rawQuery('''
      SELECT p.*, c.name as course_name
      FROM student_year_promotion p
      LEFT JOIN course_structure c ON c.id = p.course_id
      WHERE p.student_id = ? AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      ORDER BY p.id DESC
    ''', [studentId]);
  }

  // --- INVENTORY METHODS ---
  Future<List<Map<String, dynamic>>> getInventoryItems(
      {String? category, String? search, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND (system_type = 'Masjid' OR system_type = 'Shared')";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type = 'Shared' OR system_type IS NULL OR system_type = '')";
    }

    if (category != null &&
        category.isNotEmpty &&
        category.toLowerCase() != 'all') {
      where += " AND category = ?";
      args.add(category);
    }

    if (search != null && search.trim().isNotEmpty) {
      where += " AND (name LIKE ? OR item_code LIKE ?)";
      final q = '%${search.trim()}%';
      args.addAll([q, q]);
    }

    return await db.query('inventory_items',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'name ASC');
  }

  Future<int> insertInventoryItem(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    final id = await db.insert('inventory_items', data);
    await logActivity('shop', 'نئی شاپ آئٹم شامل کی گئی: ${data['name']}');
    return id;
  }

  Future<int> updateInventoryItem(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('inventory_items', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteInventoryItem(int id) async {
    Database db = await database;
    return await db.update('inventory_items', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getInventorySales(
      {String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('inventory_sales',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC',
        limit: 100);
  }

  Future<bool> recordInventorySale(
    int itemId,
    int quantity, {
    int? studentId,
    String? customerName,
    String paymentMode = 'Cash',
    String? remarks,
    String? systemType,
  }) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    final itemRows =
        await db.query('inventory_items', where: 'id = ?', whereArgs: [itemId]);
    if (itemRows.isEmpty) return false;
    final item = itemRows.first;

    final currentStock = (item['current_stock'] as num?)?.toInt() ?? 0;
    if (currentStock < quantity) return false;

    final unitPrice = (item['unit_price'] as num?)?.toDouble() ?? 0.0;
    final totalAmount = unitPrice * quantity;
    final saleNo =
        'SALE-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}';
    final today = DateTime.now().toIso8601String().substring(0, 10);

    // 1. Insert Sale Record
    final saleMap = <String, dynamic>{
      'sale_no': saleNo,
      'item_id': itemId,
      'item_name': item['name']?.toString() ?? '',
      'student_id': studentId,
      'customer_name': customerName ?? '',
      'quantity': quantity,
      'unit_price': unitPrice,
      'total_amount': totalAmount,
      'payment_mode': paymentMode,
      'date': today,
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };
    await db.insert('inventory_sales', saleMap);

    // 2. Reduce Stock
    await db.rawUpdate(
      'UPDATE inventory_items SET current_stock = MAX(0, current_stock - ?), updated_at = ? WHERE id = ?',
      [quantity, DateTime.now().toIso8601String(), itemId],
    );

    // 3. Double-Entry Accounting Voucher Link (Debit Cash, Credit Shop Sales Revenue)
    try {
      final cashRows = await db.query('acc_chart_of_accounts',
          where: "code = '1001' AND (is_deleted = 0 OR is_deleted IS NULL)");
      final shopRows = await db.query('acc_chart_of_accounts',
          where:
              "(code = '4005' OR name LIKE '%شاپ%' OR name LIKE '%Shop%') AND (is_deleted = 0 OR is_deleted IS NULL)");

      int? cashId = cashRows.isNotEmpty ? (cashRows.first['id'] as int?) : null;
      int? shopId = shopRows.isNotEmpty ? (shopRows.first['id'] as int?) : null;

      if (shopId == null) {
        shopId = await db.insert('acc_chart_of_accounts', {
          'code': '4005',
          'name': 'آمدنی سکول شاپ / انونٹری',
          'type': 'Revenue',
          'is_active': 1,
          'is_deleted': 0,
          'is_synced': 0,
        });
      }

      if (cashId != null) {
        final vNo =
            'RV-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}';
        final vId = await db.insert('acc_vouchers', {
          'voucher_no': vNo,
          'date': today,
          'type': 'Receipt',
          'reference': saleNo,
          'description':
              'سکول شاپ سیل: ${item['name']} x $quantity ($customerName)',
          'status': 'Approved',
          'system_type': SystemTypeGlobal.currentSystemType.value,
          'is_deleted': 0,
          'is_synced': 0,
        });

        await db.insert('acc_ledger_entries', {
          'voucher_id': vId,
          'account_id': cashId,
          'debit': totalAmount,
          'credit': 0.0,
          'is_deleted': 0,
          'is_synced': 0,
        });

        await db.insert('acc_ledger_entries', {
          'voucher_id': vId,
          'account_id': shopId,
          'debit': 0.0,
          'credit': totalAmount,
          'is_deleted': 0,
          'is_synced': 0,
        });
      }
    } catch (_) {}

    await logActivity('shop',
        'سکول شاپ سے ${item['name']} کی فروخت x $quantity (مبلغ: $totalAmount روپے)');
    return true;
  }

  // --- HOSTEL METHODS ---
  Future<List<Map<String, dynamic>>> getHostelRooms(
      {String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('hostel_rooms',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'room_number ASC');
  }

  Future<int> insertHostelRoom(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('hostel_rooms', data);
  }

  Future<int> updateHostelRoom(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('hostel_rooms', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteHostelRoom(int id) async {
    Database db = await database;
    return await db.update('hostel_rooms', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getHostelAllocations(
      {String? systemType, String? status}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(a.is_deleted = 0 OR a.is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND a.system_type = 'Masjid'";
    } else {
      where +=
          " AND (a.system_type = 'Madrasa' OR a.system_type IS NULL OR a.system_type = '')";
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND a.status = ?";
      args.add(status);
    }

    final query = '''
      SELECT a.*, r.room_number, r.room_name, r.monthly_fee
      FROM hostel_allocations a
      LEFT JOIN hostel_rooms r ON a.room_id = r.id
      WHERE $where
      ORDER BY a.id DESC
    ''';
    return await db.rawQuery(query, args.isNotEmpty ? args : null);
  }

  Future<bool> allocateHostelBed(
    int roomId,
    int studentId, {
    String? studentName,
    String? bedNumber,
    String? allocationDate,
    String? remarks,
    String? systemType,
  }) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;

    String sName = studentName ?? '';
    if (sName.isEmpty) {
      final sRows = await db.query('students',
          columns: ['name'], where: 'id = ?', whereArgs: [studentId]);
      if (sRows.isNotEmpty) {
        sName = sRows.first['name']?.toString() ?? '';
      }
    }

    final allocMap = <String, dynamic>{
      'room_id': roomId,
      'student_id': studentId,
      'student_name': sName,
      'bed_number': bedNumber ?? 'Bed-1',
      'allocation_date':
          allocationDate ?? DateTime.now().toIso8601String().substring(0, 10),
      'status': 'Occupied',
      'remarks': remarks ?? '',
      'system_type': sys,
      'is_deleted': 0,
      'is_synced': 0,
      'created_at': DateTime.now().toIso8601String(),
    };

    await db.insert('hostel_allocations', allocMap);
    await db.update('students', {'residence_status': 'مقیم'},
        where: 'id = ?', whereArgs: [studentId]);
    await logActivity('hostel', 'طالب علم $sName کو ہاسٹل الاٹ کیا گیا');
    return true;
  }

  Future<bool> vacateHostelBed(int allocationId) async {
    Database db = await database;
    final aRows = await db.query('hostel_allocations',
        where: 'id = ?', whereArgs: [allocationId]);
    if (aRows.isEmpty) return false;
    final stdId = aRows.first['student_id'];

    await db.update(
        'hostel_allocations',
        {
          'status': 'Vacated',
          'vacating_date': DateTime.now().toIso8601String().substring(0, 10),
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [allocationId]);

    if (stdId != null) {
      await db.update('students', {'residence_status': 'مقامی'},
          where: 'id = ?', whereArgs: [stdId]);
    }
    return true;
  }

  // --- RECRUITMENT METHODS ---
  Future<List<Map<String, dynamic>>> getJobPostings(
      {String? systemType, String? status}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND status = ?";
      args.add(status);
    }

    return await db.query('job_postings',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'id DESC');
  }

  Future<int> insertJobPosting(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('job_postings', data);
  }

  Future<int> updateJobPosting(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('job_postings', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteJobPosting(int id) async {
    Database db = await database;
    return await db.update('job_postings', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getJobApplicants(
      {int? jobId, String? status, String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(a.is_deleted = 0 OR a.is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND a.system_type = 'Masjid'";
    } else {
      where +=
          " AND (a.system_type = 'Madrasa' OR a.system_type IS NULL OR a.system_type = '')";
    }

    if (jobId != null) {
      where += " AND a.job_id = ?";
      args.add(jobId);
    }
    if (status != null && status.isNotEmpty && status.toLowerCase() != 'all') {
      where += " AND a.status = ?";
      args.add(status);
    }

    final query = '''
      SELECT a.*, j.title as job_title, j.department
      FROM job_applicants a
      LEFT JOIN job_postings j ON a.job_id = j.id
      WHERE $where
      ORDER BY a.id DESC
    ''';
    return await db.rawQuery(query, args.isNotEmpty ? args : null);
  }

  Future<int> insertJobApplicant(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['status'] ??= 'Pending';
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('job_applicants', data);
  }

  Future<int> updateJobApplicant(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('job_applicants', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<bool> hireApplicantToTeacher(int applicantId) async {
    Database db = await database;
    final rows = await db
        .query('job_applicants', where: 'id = ?', whereArgs: [applicantId]);
    if (rows.isEmpty) return false;
    final app = rows.first;

    final sysType = app['system_type']?.toString() ??
        SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sysType == 'Masjid';
    final targetTable = isMasjid ? 'maktab_teachers' : 'teachers';

    final tId = await db.insert(targetTable, {
      'name': app['applicant_name'],
      'father_name': app['father_name'] ?? '',
      'phone': app['phone'] ?? '',
      'qualification': app['qualification'] ?? '',
      'status': 'Active',
      'is_deleted': 0,
      'is_synced': 0,
    });

    await db.update(
        'job_applicants',
        {
          'status': 'Selected',
          'converted_teacher_id': tId,
          'updated_at': DateTime.now().toIso8601String(),
          'is_synced': 0,
        },
        where: 'id = ?',
        whereArgs: [applicantId]);

    await logActivity('recruitment',
        'امیدوار ${app['applicant_name']} کو اساتذہ لسٹ میں شامل کیا گیا');
    return true;
  }

  // --- CMS & ANNOUNCEMENTS METHODS ---
  Future<List<Map<String, dynamic>>> getAnnouncements(
      {String? systemType, bool? isPublic}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    if (isPublic != null) {
      where += " AND is_public = ?";
      args.add(isPublic ? 1 : 0);
    }

    return await db.query('announcements',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'publish_date DESC, id DESC');
  }

  Future<int> insertAnnouncement(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['created_at'] ??= DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;
    return await db.insert('announcements', data);
  }

  Future<int> updateAnnouncement(int id, Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_synced'] = 0;
    return await db
        .update('announcements', data, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> deleteAnnouncement(int id) async {
    Database db = await database;
    return await db.update('announcements', {'is_deleted': 1, 'is_synced': 0},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<List<Map<String, dynamic>>> getWebPages({String? systemType}) async {
    Database db = await database;
    final sys = systemType ?? SystemTypeGlobal.currentSystemType.value;
    String where = '(is_deleted = 0 OR is_deleted IS NULL)';
    List<dynamic> args = [];

    if (sys == 'Masjid') {
      where += " AND system_type = 'Masjid'";
    } else {
      where +=
          " AND (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    }

    return await db.query('web_pages',
        where: where,
        whereArgs: args.isNotEmpty ? args : null,
        orderBy: 'sort_order ASC, id DESC');
  }

  Future<int> saveWebPage(Map<String, dynamic> row) async {
    Database db = await database;
    var data = Map<String, dynamic>.from(row);
    data['system_type'] ??= SystemTypeGlobal.currentSystemType.value;
    data['updated_at'] = DateTime.now().toIso8601String();
    data['is_deleted'] = 0;
    data['is_synced'] = 0;

    final slug = data['page_slug']?.toString() ?? '';
    final existing =
        await db.query('web_pages', where: 'page_slug = ?', whereArgs: [slug]);
    if (existing.isNotEmpty) {
      return await db
          .update('web_pages', data, where: 'page_slug = ?', whereArgs: [slug]);
    } else {
      data['created_at'] = DateTime.now().toIso8601String();
      return await db.insert('web_pages', data);
    }
  }
}
