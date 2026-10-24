// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';

import '../screens/academic_entry_dashboard.dart';
import '../screens/admin_admissions.dart';
import '../screens/alumni_register.dart';
import '../screens/apply_leave.dart';
import '../screens/attendance.dart';
import '../screens/change_password.dart';
import '../screens/classes.dart';
import '../screens/complaints.dart';
import '../screens/dashboard.dart';
import '../screens/delete_account.dart';
import '../screens/examination.dart';
import '../screens/manage_complaints.dart';
import '../screens/home.dart';
import '../screens/manage_users.dart';
import '../screens/notices.dart';
import '../screens/pay_fee.dart';
import '../screens/reports_attendance.dart';
import '../screens/reports_finance.dart';
import '../screens/requests.dart';
import '../screens/settings.dart';
import '../screens/student_report.dart';
import '../screens/students.dart';
import '../screens/teachers.dart';
import '../screens/library_screen.dart';
import '../screens/inventory_screen.dart';
import '../screens/hostel_screen.dart';
import '../screens/acc_chart.dart';
import '../screens/acc_vouchers.dart';
import '../screens/acc_invoices.dart';
import '../screens/acc_payroll.dart';
import '../screens/loans_management.dart';
import '../screens/masjid_assets.dart';
import '../screens/in_kind_donations.dart';
import '../screens/annual_report.dart';
import '../screens/recruitment_pipeline.dart';
import '../screens/simple_finance.dart';
import '../screens/academic_view_dashboard.dart';
import '../screens/subscription_status.dart';
import '../screens/finance.dart';
import '../screens/masjid_finance_dashboard.dart';
import '../screens/acc_ledger.dart';
import '../screens/acc_trial_balance.dart';
import '../screens/acc_income_statement.dart';
import '../screens/acc_balance_sheet.dart';
import '../screens/departments.dart';
import '../screens/maktab_students.dart';
import '../screens/maktab_teachers.dart';
import '../screens/maktab_classes.dart';
import '../screens/maktab_attendance.dart';
import '../screens/maktab_reports_attendance.dart';
import '../screens/maktab_academic_entry_dashboard.dart';
import '../screens/maktab_academic_report.dart';
import '../screens/maktab_exams.dart';
import '../screens/masjid_itikaf.dart';
import '../screens/smart_notifications.dart';
import '../screens/course_setup.dart';
import '../screens/student_course_enrollment.dart';
import '../screens/subject_results_entry.dart';
import '../screens/student_promotion.dart';
import '../screens/academic_reports_view.dart';
import '../utils/system_type_global.dart';
import '../utils/globals.dart';
import '../utils/session.dart';
import 'app_brand_logo.dart';
import 'sync_health_modal.dart';

class AppDrawer extends StatelessWidget {
  const AppDrawer({super.key});

  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);

  bool hasPerm(String perm) {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    if (role == 'admin' || role == 'superadmin') return true;
    if (currentUserPermissions.value.contains('all')) return true;
    if (role == 'nazim') {
      const nazimPerms = {
        'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
        'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
        'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
        'manage_students', 'manage_teachers', 'manage_classes',
        'manage_finance_entry', 'manage_finance_report', 'manage_finance',
        'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
      };
      if (nazimPerms.contains(perm)) return true;
    }
    if (currentUserPermissions.value.contains(perm)) return true;
    if (currentUserPermissions.value.contains('manage_masjid_$perm')) return true;
    final unprefixed = perm.replaceFirst('manage_masjid_', 'manage_');
    if (currentUserPermissions.value.contains(unprefixed)) return true;
    return false;
  }

  @override
  Widget build(BuildContext context) {
    return Drawer(
      backgroundColor: bgColor,
      child: ValueListenableBuilder<bool>(
        valueListenable: isUrduGlobal,
        builder: (context, isUrdu, child) {
          return ValueListenableBuilder<String>(
            valueListenable: SystemTypeGlobal.currentSystemType,
            builder: (context, sysType, _) {
              final isMasjid = sysType == 'Masjid';
              return Directionality(
                textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
                child: ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    DrawerHeader(
                      decoration: BoxDecoration(
                        color: bgColor,
                        border: Border(
                          bottom: BorderSide(
                            color: primaryGreen.withValues(alpha: 0.2),
                          ),
                        ),
                      ),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const AppBrandLogo(size: 46),
                          const SizedBox(height: 6),
                          ValueListenableBuilder<String?>(
                            valueListenable: currentTenantName,
                            builder: (context, tName, _) {
                              final defaultName = isUrdu
                                  ? (isMasjid ? 'MC-MMS (جامع مسجد)' : 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)')
                                  : (isMasjid ? 'MC-MMS (Masjid)' : 'MC-MMS (Mindcrafts Madrasa Management System)');
                              final title = (tName != null && tName.trim().isNotEmpty) ? tName.trim() : defaultName;
                              return Text(
                                title,
                                style: TextStyle(
                                  color: primaryGreen,
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                ),
                                textAlign: TextAlign.center,
                              );
                            },
                          ),
                          Container(
                            margin: const EdgeInsets.only(top: 8),
                                decoration: BoxDecoration(
                                  color: primaryGreen.withValues(alpha: 0.12),
                                  borderRadius: BorderRadius.circular(20),
                                  border: Border.all(color: primaryGreen.withValues(alpha: 0.3)),
                                ),
                                padding: const EdgeInsets.all(2),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    GestureDetector(
                                      onTap: () {
                                        SystemTypeGlobal.currentSystemType.value = 'Madrasa';
                                      },
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
                                        decoration: BoxDecoration(
                                          color: !isMasjid ? primaryGreen : Colors.transparent,
                                          borderRadius: BorderRadius.circular(16),
                                        ),
                                        child: Row(
                                          children: [
                                            Icon(Icons.school, size: 14, color: !isMasjid ? Colors.white : primaryGreen),
                                            const SizedBox(width: 4),
                                            Text(
                                              isUrdu ? 'مدرسہ' : 'Madrasa',
                                              style: TextStyle(
                                                fontSize: 12,
                                                fontWeight: FontWeight.bold,
                                                color: !isMasjid ? Colors.white : primaryGreen,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                    GestureDetector(
                                      onTap: () {
                                        SystemTypeGlobal.currentSystemType.value = 'Masjid';
                                      },
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
                                        decoration: BoxDecoration(
                                          color: isMasjid ? primaryGreen : Colors.transparent,
                                          borderRadius: BorderRadius.circular(16),
                                        ),
                                        child: Row(
                                          children: [
                                            Icon(Icons.mosque, size: 14, color: isMasjid ? Colors.white : primaryGreen),
                                            const SizedBox(width: 4),
                                            Text(
                                              isUrdu ? 'مسجد' : 'Masjid',
                                              style: TextStyle(
                                                fontSize: 12,
                                                fontWeight: FontWeight.bold,
                                                color: isMasjid ? Colors.white : primaryGreen,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                          const SizedBox(height: 8),
                          InkWell(
                            onTap: () {
                              Navigator.pop(context);
                              SyncHealthModal.show(context);
                            },
                            borderRadius: BorderRadius.circular(12),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                              decoration: BoxDecoration(
                                color: (isMasjid ? const Color(0xFF1B2A4A) : primaryGreen).withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: (isMasjid ? const Color(0xFF1B2A4A) : primaryGreen).withValues(alpha: 0.25)),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(Icons.cloud_sync, size: 14, color: isMasjid ? const Color(0xFF1B2A4A) : primaryGreen),
                                  const SizedBox(width: 5),
                                  Text(
                                    isUrdu ? 'کلاؤڈ سنک مانیٹر' : 'Cloud Sync Monitor',
                                    style: TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                      color: isMasjid ? const Color(0xFF1B2A4A) : primaryGreen,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'ہوم' : 'Home',
                      Icons.home,
                      const HomePage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'ڈیش بورڈ' : 'Dashboard',
                      Icons.dashboard,
                      const DashboardPage(),
                    ),
                    if (isMasjid) ...[
                      if (['admin', 'nazim', 'teacher'].contains(currentUserRole.value)) ...[
                        if (hasPerm('manage_requests')) ...[
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'درخواستیں و شکایات' : 'Requests & Complaints',
                            Icons.notifications_active,
                            const RequestsPage(),
                          ),
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'شکایات ریکارڈ' : 'Complaints Record',
                            Icons.report_problem,
                            const ManageComplaintsPage(),
                          ),
                        ],
                        if (hasPerm('manage_notices'))
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'نوٹس بورڈ' : 'Notice Board',
                            Icons.campaign,
                            const NoticesPage(),
                          ),
                        if (hasPerm('manage_students'))
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب طلباء' : 'Maktab Students',
                            Icons.school,
                            const MaktabStudentsPage(),
                          ),
                        if (hasPerm('manage_classes'))
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب کلاسز' : 'Maktab Classes',
                            Icons.class_,
                            const MaktabClassesPage(),
                          ),
                        if (hasPerm('manage_teachers'))
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب اساتذہ' : 'Maktab Teachers',
                            Icons.person_outline,
                            const MaktabTeachersPage(),
                          ),
                        if (hasPerm('manage_attendance')) ...[
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب حاضری رجسٹر' : 'Maktab Attendance',
                            Icons.fact_check,
                            const MaktabAttendancePage(),
                          ),
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب حاضری رپورٹ' : 'Maktab Attendance Report',
                            Icons.receipt_long,
                            const ReportsMaktabAttendancePage(),
                          ),
                        ],
                        if (hasPerm('manage_academics') || hasPerm('manage_academic_entry') || hasPerm('manage_academic_view') || hasPerm('manage_academic_dashboard') || currentUserRole.value == 'teacher') ...[
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry',
                            Icons.edit_document,
                            const MaktabAcademicEntryDashboardPage(),
                          ),
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'تعلیمی رپورٹ (دیکھیں)' : 'Academic Reports',
                            Icons.analytics,
                            const MaktabAcademicReportPage(),
                          ),
                        ],
                        if (hasPerm('manage_finance') || hasPerm('manage_masjid_finance') || currentUserRole.value == 'admin')
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مسجد فائنانس و اکاؤنٹس' : 'Masjid Finance & Accounts',
                            Icons.account_balance_wallet,
                            const MasjidFinanceDashboardPage(),
                          ),
                        if (hasPerm('manage_exams'))
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'مکتب امتحانات' : 'Maktab Exams',
                            Icons.assignment,
                            const MaktabExamsPage(),
                          ),
                        if (hasPerm('manage_itikaf') || hasPerm('manage_masjid_itikaf') || currentUserRole.value == 'admin')
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'اعتکاف مینیجمنٹ' : 'Itikaf Management',
                            Icons.mosque,
                            const MasjidItikafPage(),
                          ),
                        _buildDrawerItem(
                          context,
                          isUrdu ? 'اسمارٹ الرٹس و تنبیہات' : 'Smart Alerts',
                          Icons.notifications_active,
                          const SmartNotificationsPage(),
                        ),
                        if (hasPerm('manage_library') || currentUserRole.value == 'admin')
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'کتب خانہ / لائبریری' : 'Library',
                            Icons.local_library,
                            const LibraryScreen(),
                          ),
                        if (currentUserRole.value == 'admin') ...[
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'یوزرز کا انتظام' : 'Manage Users',
                            Icons.manage_accounts,
                            const ManageUsersPage(),
                          ),
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'سافٹ ویئر سیٹنگ' : 'Settings',
                            Icons.settings,
                            const SettingsPage(),
                          ),
                          _buildDrawerItem(
                            context,
                            isUrdu ? 'سبسکرپشن کیفیت و فیس' : 'Subscription & Status',
                            Icons.workspace_premium,
                            const SubscriptionStatusPage(),
                            iconColor: Colors.amber.shade800,
                          ),
                        ],
                      ],
                    ] else ...[
                      if (['admin', 'nazim', 'teacher'].contains(currentUserRole.value)) ...[
                  if (hasPerm('manage_admissions'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'داخلہ درخواستیں' : 'Admission Applications',
                      Icons.assignment_ind,
                      const AdminAdmissionsPage(),
                    ),
                  if (hasPerm('manage_requests')) ...[
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'درخواستیں و شکایات' : 'Requests & Complaints',
                      Icons.notifications_active,
                      const RequestsPage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'شکایات ریکارڈ' : 'Complaints Record',
                      Icons.report_problem,
                      const ManageComplaintsPage(),
                    ),
                  ],
                  if (hasPerm('manage_notices'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'نوٹس بورڈ' : 'Notice Board',
                      Icons.campaign,
                      const NoticesPage(),
                    ),
                  if (hasPerm('manage_academics') || hasPerm('manage_academic_entry') || hasPerm('manage_academic_view') || hasPerm('manage_academic_dashboard') || currentUserRole.value == 'teacher') ...[
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'تعلیمی کارکردگی (اندراج)' : 'Academic Entry',
                      Icons.edit_document,
                      const AcademicEntryDashboardPage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'تعلیمی کارکردگی (رپورٹس / جائزہ)' : 'Academic Reports',
                      Icons.analytics,
                      const AcademicViewDashboardPage(),
                    ),
                  ],
                  // درس نظامی و عصری علوم (جامع تعلیمی نظام)
                  if (hasPerm('manage_academics') || hasPerm('manage_academic_entry') || currentUserRole.value == 'admin')
                    _buildDarseNizamiSubMenu(context, isUrdu),
                  if (hasPerm('manage_students')) ...[
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'طلباء کا ریکارڈ' : 'Students',
                      Icons.school,
                      const StudentsPage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'فارغ التحصیل طلباء' : 'Alumni Register',
                      Icons.how_to_reg,
                      const AlumniRegisterPage(),
                    ),
                  ],
                  if (hasPerm('manage_classes'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'کلاسز' : 'Classes',
                      Icons.class_,
                      const ClassesPage(),
                    ),
                  if (hasPerm('manage_teachers'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'اساتذہ کا ریکارڈ' : 'Teachers',
                      Icons.person_outline,
                      const TeachersPage(),
                    ),
                  if (hasPerm('manage_attendance')) ...[
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'حاضری رجسٹر' : 'Attendance',
                      Icons.fact_check,
                      const AttendancePage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'حاضری رپورٹ' : 'Attendance Report',
                      Icons.receipt_long,
                      const ReportsAttendancePage(),
                    ),
                  ],
                  // انتظامات (سب مینیو: کتب خانہ، شاپ و انوینٹری، دارالاقامہ، بھرتی و تقرری)
                  if (hasPerm('manage_library') ||
                      hasPerm('manage_inventory') ||
                      hasPerm('manage_hostel') ||
                      hasPerm('manage_recruitment') ||
                      currentUserRole.value == 'admin')
                    _buildManagementSubMenu(context, isUrdu),

                  // مالیات و اکاؤنٹس (سب مینیو / جامع سیکشن)
                  if (hasPerm('manage_finance') || hasPerm('manage_erp') || hasPerm('manage_masjid_finance') || currentUserRole.value == 'admin')
                    _buildFinanceSubMenu(context, isUrdu),
                  if (hasPerm('manage_requests'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'شکایت اندراج' : 'Complaint Entry',
                      Icons.warning,
                      const ComplaintsPage(),
                    ),
                  if (hasPerm('manage_exams'))
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'امتحانات' : 'Exams',
                      Icons.assignment,
                      const ExaminationPage(),
                    ),
                  if (currentUserRole.value == 'admin') ...[
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'یوزرز کا انتظام' : 'Manage Users',
                      Icons.manage_accounts,
                      const ManageUsersPage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'سافٹ ویئر سیٹنگ' : 'Settings',
                      Icons.settings,
                      const SettingsPage(),
                    ),
                    _buildDrawerItem(
                      context,
                      isUrdu ? 'سبسکرپشن کیفیت و فیس' : 'Subscription & Status',
                      Icons.workspace_premium,
                      const SubscriptionStatusPage(),
                      iconColor: Colors.amber.shade800,
                    ),
                  ],
                ],
              ],
                if (currentUserRole.value == 'student') ...[
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'میری تعلیمی رپورٹ' : 'My Report',
                    Icons.school,
                    const StudentReportPage(),
                  ),
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'مدرسہ کے ساتھ تعاون' : 'Cooperate with Madrasa',
                    Icons.handshake,
                    const PayFeePage(),
                  ),
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'چھٹی کی درخواست' : 'Apply Leave',
                    Icons.mark_email_read,
                    const ApplyLeavePage(),
                  ),
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'اصلاحی شکایت درج کریں' : 'Register Complaint',
                    Icons.sentiment_dissatisfied,
                    const ComplaintsPage(),
                  ),
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'پاس ورڈ تبدیل کریں' : 'Change Password',
                    Icons.vpn_key,
                    const ChangePasswordPage(),
                  ),
                  _buildDrawerItem(
                    context,
                    isUrdu ? 'اکاؤنٹ حذف کریں' : 'Delete Account',
                    Icons.delete_forever,
                    const DeleteAccountPage(),
                    iconColor: Colors.red,
                  ),
                ],
                ListTile(
                  leading: const Icon(Icons.logout, color: Colors.red),
                  title: Text(
                    isUrdu ? 'لاگ آؤٹ' : 'Logout',
                    style: const TextStyle(
                      color: Colors.red,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  onTap: () async {
                    currentUserRole.value = null;
                    currentUserId.value = null;
                    currentUserToken.value = null;
                    currentUserPermissions.value = [];
                    await SessionManager.clearSession();
                    if (context.mounted) {
                      Navigator.pushReplacement(
                        context,
                        MaterialPageRoute(builder: (c) => const HomePage()),
                      );
                    }
                  },
                ),
                const Divider(),
                ListTile(
                  leading: Icon(Icons.g_translate, color: primaryGreen),
                  title: Text(
                    isUrdu ? 'English میں تبدیل کریں' : 'Switch to اردو',
                    style: TextStyle(
                      color: textDark,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  onTap: () {
                    isUrduGlobal.value = !isUrduGlobal.value;
                    Navigator.pop(context);
                  },
                ),
                const SizedBox(height: 20),
              ],
            ),
          );
        },
      );
    },
  ),
);
}

  Widget _buildDrawerItem(
    BuildContext context,
    String title,
    IconData icon,
    Widget page, {
    Color? iconColor,
  }) {
    return ListTile(
      dense: true,
      leading: Icon(icon, color: iconColor ?? primaryGreen, size: 22),
      title: Text(
        title,
        style: TextStyle(
          color: textDark,
          fontWeight: FontWeight.w500,
          fontSize: 14,
        ),
      ),
      onTap: () {
        Navigator.pop(context);
        if (page.runtimeType == DashboardPage) {
          Navigator.pushAndRemoveUntil(
            context,
            MaterialPageRoute(builder: (c) => page),
            (route) => false,
          );
        } else {
          Navigator.push(
            context,
            MaterialPageRoute(builder: (c) => page),
          );
        }
      },
    );
  }

  Widget _buildSubDrawerItem(
    BuildContext context,
    String title,
    IconData icon,
    Widget page, {
    Color? iconColor,
  }) {
    return ListTile(
      dense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 28),
      leading: Icon(icon, color: iconColor ?? primaryGreen.withValues(alpha: 0.8), size: 19),
      title: Text(
        title,
        style: TextStyle(
          color: textDark,
          fontWeight: FontWeight.w400,
          fontSize: 13,
        ),
      ),
      onTap: () {
        Navigator.pop(context);
        if (page.runtimeType == DashboardPage) {
          Navigator.pushAndRemoveUntil(
            context,
            MaterialPageRoute(builder: (c) => page),
            (route) => false,
          );
        } else {
          Navigator.push(
            context,
            MaterialPageRoute(builder: (c) => page),
          );
        }
      },
    );
  }

  Widget _buildManagementSubMenu(BuildContext context, bool isUrdu) {
    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        leading: Icon(Icons.business_center, color: primaryGreen, size: 22),
        title: Text(
          isUrdu ? 'انتظامات' : 'Management',
          style: TextStyle(
            color: textDark,
            fontWeight: FontWeight.w500,
            fontSize: 14,
          ),
        ),
        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 0),
        childrenPadding: EdgeInsets.zero,
        children: [
          if (hasPerm('manage_library') || currentUserRole.value == 'admin')
            _buildSubDrawerItem(
              context,
              isUrdu ? 'کتب خانہ' : 'Library',
              Icons.local_library,
              const LibraryScreen(),
            ),
          if (hasPerm('manage_inventory') || currentUserRole.value == 'admin')
            _buildSubDrawerItem(
              context,
              isUrdu ? 'شاپ و انوینٹری' : 'Shop & Inventory',
              Icons.store,
              const InventoryScreen(),
            ),
          if (hasPerm('manage_hostel') || currentUserRole.value == 'admin')
            _buildSubDrawerItem(
              context,
              isUrdu ? 'دارالاقامہ' : 'Hostel',
              Icons.hotel,
              const HostelScreen(),
            ),
          if (hasPerm('manage_recruitment') || currentUserRole.value == 'admin')
            _buildSubDrawerItem(
              context,
              isUrdu ? 'بھرتی و تقرری' : 'Recruitment & HR',
              Icons.work_outline,
              const RecruitmentPipelineScreen(),
            ),
        ],
      ),
    );
  }

  Widget _buildDarseNizamiSubMenu(BuildContext context, bool isUrdu) {
    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        leading: Icon(Icons.school, color: primaryGreen, size: 22),
        title: Text(
          isUrdu ? 'درس نظامی و عصری علوم' : 'Darse Nizami & Academics',
          style: TextStyle(
            color: textDark,
            fontWeight: FontWeight.w500,
            fontSize: 14,
          ),
        ),
        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 0),
        childrenPadding: EdgeInsets.zero,
        children: [
          _buildSubDrawerItem(
            context,
            isUrdu ? 'کورس و مضامین سیٹ اپ' : 'Course & Subjects Setup',
            Icons.auto_stories,
            const CourseSetupPage(),
          ),
          _buildSubDrawerItem(
            context,
            isUrdu ? 'طلبہ کورس داخلہ' : 'Student Course Enrollment',
            Icons.how_to_reg,
            const StudentCourseEnrollmentPage(),
          ),
          _buildSubDrawerItem(
            context,
            isUrdu ? 'مضامین وار رزلٹ انٹری' : 'Subject Marks Entry',
            Icons.edit_note,
            const SubjectResultsEntryPage(),
          ),
          _buildSubDrawerItem(
            context,
            isUrdu ? 'سالانہ ترقی (پروموشن)' : 'Annual Promotion',
            Icons.upgrade,
            const StudentPromotionPage(),
          ),
          _buildSubDrawerItem(
            context,
            isUrdu ? 'تعلیمی رپورٹس و رینکنگ' : 'Academic Reports & Rankings',
            Icons.leaderboard,
            const AcademicReportsViewPage(),
          ),
        ],
      ),
    );
  }

  Widget _buildFinanceSubMenu(BuildContext context, bool isUrdu) {
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        leading: Icon(Icons.account_balance, color: primaryGreen, size: 22),
        title: Text(
          isUrdu ? (isMasjid ? 'مسجد فائنانس' : 'مالیات و اکاؤنٹس') : (isMasjid ? 'Masjid Finance' : 'Finance & Accounts'),
          style: TextStyle(
            color: textDark,
            fontWeight: FontWeight.w500,
            fontSize: 14,
          ),
        ),
        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 0),
        childrenPadding: EdgeInsets.zero,
        children: isMasjid
            ? [
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'مسجد فائنانس ڈیش بورڈ' : 'Masjid Finance Dashboard',
                  Icons.dashboard,
                  const MasjidFinanceDashboardPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'آمدن و خرچ' : 'Income & Expense',
                  Icons.account_balance_wallet,
                  const SimpleFinancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'مالیاتی رپورٹ' : 'Finance Report',
                  Icons.bar_chart,
                  const ReportsFinancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'اشیاء و عطیات' : 'In-Kind Donations',
                  Icons.card_giftcard,
                  const InKindDonationsScreen(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'اثاثہ جات' : 'Assets',
                  Icons.inventory_2,
                  const MasjidAssetsPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'وظائف عملہ' : 'Staff Stipends',
                  Icons.payments,
                  const AccPayrollPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'ایڈوانس و قرض' : 'Loans & Advance',
                  Icons.request_quote,
                  const LoansManagementPage(),
                ),
              ]
            : [
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'مالیات و اکاؤنٹس ڈیش بورڈ' : 'Finance & Accounts Dashboard',
                  Icons.dashboard,
                  const FinancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'آمدن و خرچ (سادہ)' : 'Income & Expense',
                  Icons.account_balance_wallet,
                  const SimpleFinancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'مالیاتی تفصیلی رپورٹ' : 'Finance Report',
                  Icons.bar_chart,
                  const ReportsFinancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'سالانہ جامع رپورٹ' : 'Annual Report',
                  Icons.insert_chart,
                  const AnnualReportPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'ایڈوانس و قرض حسنہ' : 'Staff Loans & Advance',
                  Icons.request_quote,
                  const LoansManagementPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'اشیاء و عطیات' : 'In-Kind Donations',
                  Icons.card_giftcard,
                  const InKindDonationsScreen(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'اثاثہ جات مینیجمنٹ' : 'Assets Management',
                  Icons.inventory_2,
                  const MasjidAssetsPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'وظائف عملہ و پے رول' : 'Staff Stipends & Payroll',
                  Icons.payments,
                  const AccPayrollPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'فیس انوائسز و بلنگ' : 'Fee Invoices',
                  Icons.receipt_long,
                  const AccInvoicesPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'چارٹ آف اکاؤنٹس' : 'Chart of Accounts',
                  Icons.account_tree,
                  const AccChartPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'اکاؤنٹنگ واؤچرز' : 'Accounting Vouchers',
                  Icons.receipt,
                  const AccVouchersPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'جنرل لیجر (کھاتہ بہی)' : 'General Ledger',
                  Icons.menu_book,
                  const AccLedgerPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'میزان پڑتال (Trial Balance)' : 'Trial Balance',
                  Icons.balance,
                  const AccTrialBalancePage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'گوشوارہ آمدن و اخراجات' : 'Income Statement',
                  Icons.pie_chart,
                  const AccIncomeStatementPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'بیلنس شیٹ' : 'Balance Sheet',
                  Icons.account_balance,
                  const BalanceSheetPage(),
                ),
                _buildSubDrawerItem(
                  context,
                  isUrdu ? 'شعبہ جات و برانچز' : 'Departments',
                  Icons.business,
                  const DepartmentsPage(),
                ),
              ],
      ),
    );
  }
}
