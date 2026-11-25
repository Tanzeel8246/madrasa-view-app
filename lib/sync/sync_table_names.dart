import 'package:flutter/material.dart';

class SyncTableNames {
  static String getUrduTableName(String key) {
    switch (key.toLowerCase()) {
      case 'students':
        return 'طلبہ ریکارڈ';
      case 'teachers':
        return 'اساتذہ ریکارڈ';
      case 'classes':
        return 'کلاسز';
      case 'departments':
        return 'شعبہ جات';
      case 'notices':
        return 'اعلانات / نوٹس بورڈ';
      case 'attendance':
      case 'attendance_entries':
      case 'attendance_history':
        return 'طلبہ حاضری';
      case 'attendance_teachers':
        return 'اساتذہ حاضری';
      case 'teacher_period_attendance':
        return 'پیریڈ وار حاضری اساتذہ';
      case 'teacher_leaves':
        return 'اساتذہ رخصت';
      case 'hifz_record':
      case 'hifz_entries':
      case 'hifz_history':
        return 'روزانہ سبق و منزل (حفظ)';
      case 'nazira_record':
      case 'nazira_history':
        return 'ناظرہ قرآن ریکارڈ';
      case 'qaida_records':
        return 'نورانی قاعدہ ریکارڈ';
      case 'husn_saut_record':
        return 'حسن صوت تعلیمی ریکارڈ';
      case 'academic_reports':
        return 'تعلیمی رپورٹس';
      case 'finance':
      case 'finance_entries':
      case 'finance_history':
        return 'مدرسہ فنانس (آمدن و خرچ)';
      case 'masjid_finance':
      case 'masjid_finance_entries':
      case 'masjid_finance_history':
        return 'مسجد فنانس (آمدن و خرچ)';
      case 'heads':
        return 'مالیاتی مدات (Heads)';
      case 'fee_submissions':
        return 'فیس جمع ریکارڈ';
      case 'exams':
        return 'امتحانات';
      case 'exam_results':
        return 'امتحانی نتائج';
      case 'complaints':
        return 'طالب علم شکایات';
      case 'parent_complaints':
        return 'والدین شکایات';
      case 'leave_applications':
        return 'درخواست برائے رخصت';
      case 'acc_chart_of_accounts':
        return 'چارٹ آف اکاؤنٹس';
      case 'acc_vouchers':
        return 'اکاؤنٹنگ واؤچرز';
      case 'acc_ledger_entries':
        return 'لیجر اندراجات';
      case 'acc_payroll':
        return 'وظائف عملہ (پے رول)';
      case 'acc_invoices':
        return 'فیس انوائسز';
      case 'in_kind_donations':
        return 'اشیاء عطیات';
      case 'masjid_assets':
      case 'assets':
        return 'اثاثہ جات';
      case 'staff_loans':
        return 'قرضہ جات ریکارڈ';
      case 'loan_transactions':
        return 'قرض ٹرانزیکشنز';
      case 'maktab_students':
        return 'مکتب طلبہ';
      case 'maktab_teachers':
        return 'مکتب اساتذہ';
      case 'maktab_classes':
        return 'مکتب کلاسز';
      case 'maktab_exams':
        return 'مکتب امتحانات';
      case 'maktab_attendance':
        return 'مکتب حاضری';
      case 'maktab_nazira_record':
        return 'مکتب ناظرہ ریکارڈ';
      case 'maktab_hifz_record':
        return 'مکتب حفظ ریکارڈ';
      case 'maktab_qaida_records':
        return 'مکتب قاعدہ ریکارڈ';
      case 'maktab_academic_reports':
        return 'مکتب تعلیمی رپورٹس';
      case 'maktab_exam_results':
        return 'مکتب امتحانی نتائج';
      case 'admission_inquiries':
        return 'مراحلِ داخلہ درخواستیں';
      case 'lib_books':
        return 'لائبریری کتب کیٹلاگ';
      case 'lib_issues':
        return 'لائبریری اجراء و واپسی';
      case 'inventory_items':
        return 'سکول شاپ انونٹری';
      case 'inventory_sales':
        return 'شاپ سیلز و رسیدات';
      case 'hostel_rooms':
        return 'ہاسٹل کمرہ جات';
      case 'hostel_allocations':
        return 'ہاسٹل الاٹمنٹس';
      case 'job_postings':
        return 'ملازمتوں کے اشتہارات';
      case 'job_applicants':
        return 'امیدوارانِ ملازمت';
      case 'web_pages':
        return 'ویب سائٹ پیجز';
      case 'announcements':
        return 'خبریں و اعلانات';
      case 'masjid_itikaf':
        return 'مسجد اعتکاف';
      case 'course_structure':
        return 'کورسز (درس نظامی و عصری)';
      case 'course_subjects':
        return 'کورس کے مضامین و کتب';
      case 'student_course_enrollment':
        return 'طلبہ داخلہ / انرولمنٹ';
      case 'exam_subject_results':
        return 'مضامین وار امتحانی نتائج';
      case 'student_year_promotion':
        return 'سالانہ ترقی و نتیجہ ریکارڈ';
      case 'settings':
        return 'سسٹم ترتیبات';
      default:
        return key;
    }
  }

  static IconData getTableIcon(String key) {
    switch (key.toLowerCase()) {
      case 'course_structure':
      case 'course_subjects':
        return Icons.auto_stories;
      case 'student_course_enrollment':
      case 'student_year_promotion':
        return Icons.how_to_reg;
      case 'students':
      case 'maktab_students':
        return Icons.school;
      case 'teachers':
      case 'maktab_teachers':
        return Icons.person_outline;
      case 'classes':
      case 'maktab_classes':
        return Icons.class_;
      case 'attendance':
      case 'attendance_entries':
      case 'attendance_history':
      case 'attendance_teachers':
      case 'teacher_period_attendance':
      case 'maktab_attendance':
        return Icons.event_available;
      case 'hifz_record':
      case 'hifz_entries':
      case 'hifz_history':
      case 'nazira_record':
      case 'nazira_history':
      case 'qaida_records':
      case 'academic_reports':
      case 'maktab_nazira_record':
      case 'maktab_hifz_record':
      case 'maktab_qaida_records':
      case 'maktab_academic_reports':
        return Icons.menu_book;
      case 'finance':
      case 'finance_entries':
      case 'finance_history':
      case 'masjid_finance':
      case 'masjid_finance_entries':
      case 'masjid_finance_history':
      case 'fee_submissions':
      case 'acc_vouchers':
      case 'acc_ledger_entries':
      case 'acc_payroll':
      case 'acc_invoices':
      case 'staff_loans':
      case 'inventory_sales':
        return Icons.account_balance_wallet;
      case 'inventory_items':
        return Icons.shopping_bag;
      case 'hostel_rooms':
      case 'hostel_allocations':
        return Icons.hotel;
      case 'job_postings':
      case 'job_applicants':
        return Icons.badge;
      case 'web_pages':
        return Icons.language;
      case 'announcements':
      case 'notices':
        return Icons.campaign;
      case 'exams':
      case 'exam_results':
      case 'maktab_exams':
      case 'maktab_exam_results':
        return Icons.assignment;
      case 'complaints':
      case 'parent_complaints':
        return Icons.report_problem;
      case 'leave_applications':
        return Icons.event_busy;
      case 'departments':
      case 'acc_chart_of_accounts':
        return Icons.account_tree;
      case 'in_kind_donations':
      case 'masjid_assets':
      case 'assets':
        return Icons.inventory_2;
      case 'loan_transactions':
        return Icons.swap_horiz;
      case 'admission_inquiries':
        return Icons.stream;
      case 'lib_books':
      case 'lib_issues':
        return Icons.menu_book;
      case 'settings':
        return Icons.settings;
      default:
        return Icons.table_chart;
    }
  }
}
