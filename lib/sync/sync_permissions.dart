import '../utils/globals.dart';
import 'sync_config.dart';

class SyncPermissions {
  static bool canUploadTable(String table) {
    final role = currentUserRole.value?.toLowerCase();
    if (role == 'admin' ||
        role == 'nazim' ||
        role == 'superadmin' ||
        role == 'super_admin' ||
        role == 'administrator') {
      return true;
    }

    if (role == 'student' || role == 'parent') {
      return table == 'parent_complaints' ||
          table == 'complaints' ||
          table == 'leave_applications' ||
          table == 'fee_submissions';
    }

    final permissions = currentUserPermissions.value;
    if (table == 'settings') {
      return permissions.contains('manage_settings');
    }

    if (table == 'complaints' &&
        (permissions.contains('manage_students') ||
            permissions.contains('manage_requests') ||
            permissions.contains('manage_complaints'))) {
      return true;
    }

    if ((table == 'course_structure' ||
            table == 'course_subjects' ||
            table == 'student_course_enrollment' ||
            table == 'student_year_promotion') &&
        (permissions.contains('manage_academics') ||
            permissions.contains('manage_classes'))) {
      return true;
    }

    if (table == 'exam_subject_results' &&
        (permissions.contains('manage_exams') ||
            permissions.contains('manage_academics'))) {
      return true;
    }

    if ((table == 'attendance_teachers' ||
            table == 'teacher_period_attendance') &&
        (permissions.contains('manage_attendance') ||
            permissions.contains('manage_teacher_attendance'))) {
      return true;
    }

    if ((table == 'academic_reports' ||
            table == 'maktab_academic_reports') &&
        (permissions.contains('manage_academics') ||
            permissions.contains('manage_hifz'))) {
      return true;
    }

    if (table == 'acc_payroll' &&
        (permissions.contains('manage_finance') ||
            permissions.contains('manage_erp') ||
            permissions.contains('manage_stipends') ||
            permissions.contains('manage_masjid_stipends'))) {
      return true;
    }

    if ((table == 'acc_vouchers' ||
            table == 'acc_ledger_entries' ||
            table == 'acc_chart_of_accounts') &&
        (permissions.contains('manage_finance') ||
            permissions.contains('manage_erp') ||
            permissions.contains('manage_masjid_finance'))) {
      return true;
    }

    if ((table == 'lib_books' || table == 'lib_issues') &&
        (permissions.contains('manage_library') ||
            permissions.contains('manage_masjid_library') ||
            permissions.contains('manage_students'))) {
      return true;
    }

    if ((table == 'inventory_items' || table == 'inventory_sales') &&
        (permissions.contains('manage_inventory') ||
            permissions.contains('manage_masjid_inventory') ||
            permissions.contains('manage_finance'))) {
      return true;
    }

    if ((table == 'hostel_rooms' || table == 'hostel_allocations') &&
        (permissions.contains('manage_hostel') ||
            permissions.contains('manage_students'))) {
      return true;
    }

    if ((table == 'job_postings' || table == 'job_applicants') &&
        (permissions.contains('manage_recruitment') ||
            permissions.contains('manage_teachers'))) {
      return true;
    }

    final permission = SyncConfig.uploadPermissionMap[table];
    return permission != null && permissions.contains(permission);
  }
}
