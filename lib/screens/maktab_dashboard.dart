import 'package:flutter/material.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import 'maktab_students.dart';
import 'maktab_teachers.dart';
import 'maktab_classes.dart';
import 'maktab_attendance.dart';
import 'maktab_academic_entry_dashboard.dart';
import 'maktab_reports_attendance.dart';
import 'maktab_teacher_attendance.dart';
import 'maktab_teacher_attendance_report.dart';

class MaktabDashboardPage extends StatelessWidget {
  const MaktabDashboardPage({super.key});

  bool _hasPerm(String perm) {
    if (currentUserRole.value == 'admin' || currentUserRole.value == 'superadmin') return true;
    if (currentUserPermissions.value.contains('all')) return true;
    
    bool checkPerm(String p) {
      if (SystemTypeGlobal.currentSystemType.value == 'Masjid') {
        if (!p.startsWith('manage_masjid_')) {
          if (p == 'add_complaint') {
            p = 'manage_masjid_add_complaint';
          } else {
            p = p.replaceFirst('manage_', 'manage_masjid_');
          }
        }
      }
      if (currentUserRole.value == 'nazim') {
        const nazimBase = {
          'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
          'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
          'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
          'manage_students', 'manage_teachers', 'manage_classes',
          'manage_finance_entry', 'manage_finance_report',
          'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
          'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
          'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
          'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
          'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes',
          'manage_masjid_finance_entry', 'manage_masjid_finance_report',
          'manage_masjid_requests', 'manage_masjid_notices', 'manage_masjid_admissions', 'manage_masjid_complaints', 'manage_masjid_add_complaint'
        };
        if (nazimBase.contains(p)) return true;
      }
      return currentUserPermissions.value.contains(p);
    }

    if (perm.contains('|')) {
      return perm.split('|').any((p) => checkPerm(p));
    }
    return checkPerm(perm);
  }

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;
    const primaryGreen = Color(0xFF284D3D);

    final allCards = [
      {'perm': 'manage_classes', 'ur': 'کلاسز (حفظ/ناظرہ)', 'en': 'Classes', 'icon': Icons.class_, 'page': const MaktabClassesPage()},
      {'perm': 'manage_students', 'ur': 'طلباء', 'en': 'Students', 'icon': Icons.people, 'page': const MaktabStudentsPage()},
      {'perm': 'manage_teachers', 'ur': 'اساتذہ', 'en': 'Teachers', 'icon': Icons.person_pin, 'page': const MaktabTeachersPage()},
      {'perm': 'manage_attendance', 'ur': 'طلباء حاضری', 'en': 'Student Attendance', 'icon': Icons.check_circle, 'page': const MaktabAttendancePage()},
      {'perm': 'manage_teacher_attendance', 'ur': 'اساتذہ حاضری', 'en': 'Teacher Attendance', 'icon': Icons.assignment_ind, 'page': const TeacherMaktabAttendancePage()},
      {'perm': 'manage_academic_dashboard|manage_hifz|manage_hifz_daily', 'ur': 'تعلیمی کارکردگی', 'en': 'Academic Performance', 'icon': Icons.edit_document, 'page': const MaktabAcademicEntryDashboardPage()},
      {'perm': 'manage_attendance', 'ur': 'حاضری رپورٹ', 'en': 'Attendance Report', 'icon': Icons.bar_chart, 'page': const ReportsMaktabAttendancePage()},
      {'perm': 'manage_teacher_attendance', 'ur': 'اساتذہ حاضری رپورٹ', 'en': 'Teacher Attendance Report', 'icon': Icons.bar_chart_outlined, 'page': const MaktabTeacherAttendanceReportPage()},
    ];

    final visibleCards = allCards.where((c) => _hasPerm(c['perm'] as String)).toList();

    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      appBar: AppBar(
        title: Text(isUrdu ? 'مکتب (حفظ و ناظرہ)' : 'Maktab (Hifz & Nazira)'),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      body: Directionality(
        textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
        child: GridView.builder(
          padding: const EdgeInsets.all(16),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            crossAxisSpacing: 16,
            mainAxisSpacing: 16,
          ),
          itemCount: visibleCards.length,
          itemBuilder: (context, index) {
            final card = visibleCards[index];
            return _buildCard(
              context,
              card['ur'] as String,
              card['en'] as String,
              card['icon'] as IconData,
              card['page'] as Widget,
            );
          },
        ),
      ),
    );
  }

  Widget _buildCard(BuildContext context, String urTitle, String enTitle, IconData icon, Widget page) {
    bool isUrdu = isUrduGlobal.value;
    String title = isUrdu ? urTitle : enTitle;
    return GestureDetector(
      onTap: () {
        Navigator.push(context, MaterialPageRoute(builder: (_) => page));
      },
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFF284D3D).withOpacity(0.3), width: 1.5),
          boxShadow: [
            BoxShadow(
              color: const Color(0xFF284D3D).withOpacity(0.1),
              blurRadius: 10,
              offset: const Offset(0, 4),
            )
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFF284D3D).withOpacity(0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 36, color: const Color(0xFF284D3D)),
            ),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: Color(0xFF284D3D),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
