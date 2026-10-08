@echo off
echo ============================================================
echo   Madrasa App - Logical Git Commits Execution (Task 4)
echo ============================================================

echo [1/8] Committing Group B: ERP Modules...
git add lib/screens/admissions_pipeline.dart lib/screens/hostel_screen.dart lib/screens/library_screen.dart lib/screens/inventory_screen.dart lib/screens/recruitment_pipeline.dart lib/screens/teacher_leaves.dart lib/screens/loans_management.dart lib/screens/madrasa_setup_wizard.dart lib/screens/onboarding_screen.dart lib/screens/smart_notifications.dart lib/screens/qr_attendance.dart lib/screens/annual_report.dart lib/screens/husn_saut.dart lib/screens/in_kind_donations.dart
git commit -m "feat: add 14 new ERP modules (hostel, library, inventory, admissions, etc)"

echo [2/8] Committing Group C: Maktab and Masjid Modules...
git add lib/screens/maktab_*.dart lib/screens/masjid_*.dart
git commit -m "feat: complete maktab and masjid module screens"

echo [3/8] Committing Group D: Unit Tests...
git add test/
git commit -m "test: add 6 unit test files covering sync, auth, finance, and app state"

echo [4/8] Committing Group E: Web Panel New Modules...
git add "Madrasa web app/admissions_pipeline.php" "Madrasa web app/hostel.php" "Madrasa web app/library.php" "Madrasa web app/inventory.php" "Madrasa web app/recruitment.php" "Madrasa web app/teacher_leaves.php" "Madrasa web app/qr_attendance.php" "Madrasa web app/setup_wizard.php" "Madrasa web app/smart_notifications.php" "Madrasa web app/maktab_*.php" "Madrasa web app/masjid_*.php" "Madrasa web app/api/"
git commit -m "feat: add web panel pages for all new modules + modular API controllers"

echo [5/8] Committing Group F: Security and Documentation...
git add docs/ lib/services/ lib/utils/hijri_helper.dart lib/utils/notification_service.dart lib/utils/system_type_global.dart
git commit -m "feat: add smart alert service, notification service, hijri helper, docs"

echo [6/8] Committing Group G: Core Dart Files and Task 3 Fix...
git add lib/db_helper.dart lib/main.dart lib/utils/globals.dart lib/utils/network_service.dart lib/utils/session.dart lib/utils/teacher_period_utils.dart lib/widgets/app_drawer.dart lib/screens/dashboard.dart lib/screens/login.dart lib/screens/students.dart lib/screens/teachers.dart lib/screens/attendance.dart lib/screens/simple_finance.dart lib/screens/settings.dart pubspec.yaml pubspec.lock .gitignore
git commit -m "fix: parent dashboard filter, unified login, sync auth bug fixes, hifz period mapping + version bump"

echo [7/8] Committing Group H: Web Core Files and Security Archive (Tasks 1 & 2)...
git add "Madrasa web app/api.php" "Madrasa web app/index.php" "Madrasa web app/header.php" "Madrasa web app/login.php" "Madrasa web app/students.php" "Madrasa web app/teachers.php" "Madrasa web app/simple_finance.php" "Madrasa web app/settings.php" "Madrasa web app/hifz.php" "Madrasa web app/nazira.php" "Madrasa web app/attendance.php" "Madrasa web app/dashboard.php" "Madrasa web app/theme_styles.php" "Madrasa web app/.htaccess" "Madrasa web app/.env" "Madrasa web app/archive/" "Madrasa web app/logs/" "Madrasa web app/debug_log.txt" "Madrasa web app/header_log.txt" "Madrasa web app/check_*" "Madrasa web app/clean_*" "Madrasa web app/create_*" "Madrasa web app/db_debug*" "Madrasa web app/debug_*" "Madrasa web app/diag_*" "Madrasa web app/finance_dumper*" "Madrasa web app/fix_*" "Madrasa web app/rescue_*" "Madrasa web app/run_*" "Madrasa web app/schema_inspect*" "Madrasa web app/show_tables*" "Madrasa web app/test*"
git commit -m "feat: major web panel upgrades, security triple-shield, logs lockdown, and dev scripts archive"

echo [8/8] Committing Group I: Android / Native Configuration...
git add android/app/src/main/AndroidManifest.xml assets/
git commit -m "config: update AndroidManifest permissions and asset settings"

echo ============================================================
echo   All Commits Completed Successfully!
echo ============================================================
pause
