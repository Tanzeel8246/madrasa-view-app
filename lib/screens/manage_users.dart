import 'package:flutter/material.dart';
import 'dart:convert';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../widgets/app_drawer.dart';
import 'dashboard.dart';

class ManageUsersPage extends StatefulWidget {
  const ManageUsersPage({super.key});

  @override
  State<ManageUsersPage> createState() => _ManageUsersPageState();
}

class _ManageUsersPageState extends State<ManageUsersPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color cardColor = const Color(0xFFE8E5D9);

  List<dynamic> users = [];
  List<Map<String, dynamic>> allSystemClasses = [];
  bool isLoading = true;

  @override
  void initState() {
    super.initState();
    fetchData();
  }

  Future<void> fetchData() async {
    try {
      final classData = await DBHelper().getClasses();
      setState(() => allSystemClasses = classData);
    } catch (_) {}
    await fetchUsers();
  }

  Future<void> fetchUsers() async {
    try {
      final response = await NetworkService.getWithFallbackAction('get_users')
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['status'] == 'success') {
          if (mounted) {
            setState(() {
              users = data['users'];
              isLoading = false;
              final curName = (currentUserName.value ?? '').toLowerCase();
              if (currentUserId.value == 3 &&
                  (curName.contains('tanzeel') || curName.contains('تنزیل'))) {
                currentUserId.value = 1;
              }
            });
          }
        } else {
          throw Exception(data['message'] ?? 'Failed to fetch users');
        }
      } else {
        throw Exception('Server error: ${response.statusCode}');
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Error: $e')));
        setState(() => isLoading = false);
      }
    }
  }

  Future<void> deleteUser(String id) async {
    if (id == '1') {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(isUrduGlobal.value
                ? 'مرکزی ایڈمن اکاؤنٹ (تنزیل الرحمن) کو حذف نہیں کیا جا سکتا۔'
                : 'Main Admin account cannot be deleted.'),
          ),
        );
      }
      return;
    }

    bool? confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'یوزر حذف کریں' : 'Delete User'),
        content: Text(isUrduGlobal.value
            ? 'کیا آپ واقعی اس یوزر کو حذف کرنا چاہتے ہیں؟'
            : 'Are you sure you want to delete this user?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(isUrduGlobal.value ? 'نہیں' : 'No')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isUrduGlobal.value ? 'ہاں، حذف کریں' : 'Yes, Delete',
                style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    setState(() => isLoading = true);
    try {
      final response =
          await NetworkService.getWithFallbackAction('delete_user&id=$id');
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['status'] == 'success') {
          fetchUsers();
          if (mounted) {
            ScaffoldMessenger.of(context)
                .showSnackBar(SnackBar(content: Text(data['message'])));
          }
        } else {
          if (mounted) {
            ScaffoldMessenger.of(context)
                .showSnackBar(SnackBar(content: Text(data['message'])));
            setState(() => isLoading = false);
          }
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Error: $e')));
        setState(() => isLoading = false);
      }
    }
  }

  void openUserDialog({Map<String, dynamic>? user}) {
    final isUrdu = isUrduGlobal.value;
    final nameController = TextEditingController(text: user?['name'] ?? '');
    final usernameController =
        TextEditingController(text: user?['username'] ?? '');
    final passwordController = TextEditingController();
    String role = user?['role'] ?? 'teacher';

    // Parse permissions
    List<String> currentPerms = [];
    if (user != null && user['permissions'] != null) {
      try {
        final p = json.decode(user['permissions']);
        if (p is List) currentPerms = p.map((e) => e.toString()).toList();
      } catch (_) {}
    }

    // Parse allowed classes
    List<dynamic> currentAllowedClasses = [];
    if (user != null && user['allowed_classes'] != null) {
      try {
        final ac = json.decode(user['allowed_classes']);
        if (ac is List) currentAllowedClasses = ac;
      } catch (_) {}
    }

    final Map<String, String> madrasaPerms = {
      'manage_classes': isUrdu ? 'کلاسز کا انتظام' : 'Manage Classes',
      'manage_students': isUrdu ? 'طلباء کا ریکارڈ' : 'Manage Students',
      'manage_teachers': isUrdu ? 'اساتذہ کا ریکارڈ' : 'Manage Teachers',
      'manage_attendance': isUrdu ? 'طالب علموں کی حاضری' : 'Student Attendance',
      'manage_teacher_attendance': isUrdu ? 'اساتذہ کی حاضری' : 'Teacher Attendance',
      'manage_attendance_report': isUrdu ? 'حاضری تفصیلی رپورٹ' : 'Attendance Detailed Report',
      'manage_hifz': isUrdu ? 'حفظ (روزانہ اندراج و رپورٹ)' : 'Manage Hifz',
      'manage_hifz_daily': isUrdu ? 'حفظ (روزانہ اندراج)' : 'Hifz Daily Entry',
      'manage_hifz_report': isUrdu ? 'حفظ (تعلیمی رپورٹ)' : 'Hifz Report',
      'manage_academic_dashboard': isUrdu ? 'تعلیمی کارکردگی (اندراج ڈیش بورڈ)' : 'Academic Entry Dashboard',
      'manage_academic_entry': isUrdu ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry',
      'manage_academic_view': isUrdu ? 'تعلیمی کارکردگی (رپورٹ دیکھیں)' : 'Academic View',
      'manage_academics': isUrdu ? 'تعلیمی رپورٹ (جنرل)' : 'General Academics',
      'manage_exams': isUrdu ? 'امتحانات و نتائج' : 'Exams & Results',
      'manage_finance': isUrdu ? 'آمدن و خرچ (سادہ فنانس - تمام)' : 'Simple Finance (All)',
      'manage_finance_entry': isUrdu ? 'آمدن و خرچ اندراج' : 'Finance Entry',
      'manage_finance_report': isUrdu ? 'مالیاتی رپورٹ' : 'Finance Report',
      'manage_financial_reports': isUrdu ? 'مالیاتی تفصیلی گوشوارے و سالانہ رپورٹ' : 'Detailed Financial Reports',
      'manage_fee_defaulters': isUrdu ? 'فیس و واجبات / نادہندگان' : 'Fee Reminders & Defaulters',
      'manage_loans': isUrdu ? 'ایڈوانس و قرض مینیجمنٹ' : 'Loans & Advance',
      'manage_stipends': isUrdu ? 'وظائف مینیجمنٹ' : 'Staff Stipends',
      'manage_assets': isUrdu ? 'اثاثہ جات (مدرسہ)' : 'Assets (Madrasa)',
      'manage_in_kind_donations': isUrdu ? 'اشیاء و عطیات (مدرسہ)' : 'In-Kind Donations (Madrasa)',
      'manage_petty_cash': isUrdu ? 'پیٹی کیش رجسٹر' : 'Petty Cash Register',
      'manage_erp': isUrdu ? 'ای آر پی اکاؤنٹس (ERP Accounting)' : 'ERP Accounting',
      'manage_admissions': isUrdu ? 'داخلہ درخواستیں' : 'Admission Requests',
      'manage_notices': isUrdu ? 'نوٹس بورڈ / اعلانات' : 'Notice Board',
      'manage_requests': isUrdu ? 'درخواستیں و شکایات' : 'Requests & Complaints',
      'add_complaint': isUrdu ? 'شکایت اندراج' : 'Add Complaint',
      'manage_complaints': isUrdu ? 'شکایات ریکارڈ' : 'Manage Complaints',
      'manage_library': isUrdu ? 'لائبریری مینیجمنٹ' : 'Library Management',
      'manage_inventory': isUrdu ? 'سکول شاپ و انونٹری' : 'School Shop & Inventory',
      'manage_hostel': isUrdu ? 'ہاسٹل مینیجمنٹ' : 'Hostel Management',
      'manage_recruitment': isUrdu ? 'بھرتی و ملازمت پائپ لائن' : 'Jobs & Recruitment',
      'manage_website': isUrdu ? 'ویب سائٹ و سوشل CMS' : 'Website CMS',
      'manage_saas': isUrdu ? 'سپر ایڈمن پورٹل (SaaS)' : 'Super Admin Portal (SaaS)',
      'manage_users': isUrdu ? 'یوزرز کا انتظام' : 'User Management',
    };

    final Map<String, String> masjidPerms = {
      'manage_masjid_classes': isUrdu ? 'مسجد: کلاسز کا انتظام' : 'Masjid: Manage Classes',
      'manage_masjid_students': isUrdu ? 'مسجد: طلباء کا ریکارڈ' : 'Masjid: Manage Students',
      'manage_masjid_teachers': isUrdu ? 'مسجد: اساتذہ کا ریکارڈ' : 'Masjid: Manage Teachers',
      'manage_masjid_attendance': isUrdu ? 'مسجد: طالب علموں کی حاضری' : 'Masjid: Student Attendance',
      'manage_masjid_teacher_attendance': isUrdu ? 'مسجد: اساتذہ کی حاضری' : 'Masjid: Teacher Attendance',
      'manage_masjid_attendance_report': isUrdu ? 'مسجد: حاضری تفصیلی رپورٹ' : 'Masjid: Attendance Detailed Report',
      'manage_masjid_hifz': isUrdu ? 'مسجد: حفظ (روزانہ اندراج و رپورٹ)' : 'Masjid: Manage Hifz',
      'manage_masjid_hifz_daily': isUrdu ? 'مسجد: حفظ (روزانہ اندراج)' : 'Masjid: Hifz Daily Entry',
      'manage_masjid_hifz_report': isUrdu ? 'مسجد: حفظ (تعلیمی رپورٹ)' : 'Masjid: Hifz Report',
      'manage_masjid_academic_dashboard': isUrdu ? 'مسجد: تعلیمی کارکردگی (اندراج ڈیش بورڈ)' : 'Masjid: Academic Entry Dashboard',
      'manage_masjid_academic_entry': isUrdu ? 'مسجد: تعلیمی رپورٹ (اندراج)' : 'Masjid: Academic Entry',
      'manage_masjid_academic_view': isUrdu ? 'مسجد: تعلیمی کارکردگی (رپورٹ دیکھیں)' : 'Masjid: Academic View',
      'manage_masjid_academics': isUrdu ? 'مسجد: تعلیمی رپورٹ (جنرل)' : 'Masjid: General Academics',
      'manage_masjid_exams': isUrdu ? 'مسجد: امتحانات و نتائج' : 'Masjid: Exams & Results',
      'manage_masjid_finance': isUrdu ? 'مسجد: آمدن و خرچ (سادہ فنانس - تمام)' : 'Masjid: Simple Finance (All)',
      'manage_masjid_finance_entry': isUrdu ? 'مسجد: آمدن و خرچ اندراج' : 'Masjid: Finance Entry',
      'manage_masjid_finance_report': isUrdu ? 'مسجد: مالیاتی رپورٹ' : 'Masjid: Finance Report',
      'manage_masjid_financial_reports': isUrdu ? 'مسجد: مالیاتی تفصیلی گوشوارے' : 'Masjid: Detailed Financial Reports',
      'manage_masjid_fee_defaulters': isUrdu ? 'مسجد: فیس و واجبات' : 'Masjid: Fee Reminders & Defaulters',
      'manage_masjid_loans': isUrdu ? 'مسجد: ایڈوانس و قرض مینیجمنٹ' : 'Masjid: Loans & Advance',
      'manage_masjid_stipends': isUrdu ? 'مسجد: وظائف مینیجمنٹ' : 'Masjid: Staff Stipends',
      'manage_masjid_assets': isUrdu ? 'مسجد: اثاثہ جات' : 'Masjid: Assets',
      'manage_masjid_in_kind_donations': isUrdu ? 'مسجد: اشیاء و عطیات' : 'Masjid: In-Kind Donations',
      'manage_masjid_petty_cash': isUrdu ? 'مسجد: پیٹی کیش رجسٹر' : 'Masjid: Petty Cash Register',
      'manage_masjid_itikaf': isUrdu ? 'مسجد: اعتکاف' : 'Masjid: Itikaf',
    };

    final Map<String, String> allPerms = {...madrasaPerms, ...masjidPerms};

    showDialog(
      context: context,
      builder: (ctx) {
        final theme = Theme.of(ctx);
        final isDark = theme.brightness == Brightness.dark;
        final dBg = theme.dialogBackgroundColor;
        final cBg = theme.cardColor;
        final bColor = isDark ? Colors.grey.shade700 : Colors.grey.shade300;

        return StatefulBuilder(builder: (context, setStateDialog) {
          return AlertDialog(
            backgroundColor: dBg,
            title: Text(
                isUrdu
                    ? (user == null ? 'نیا یوزر شامل کریں' : 'یوزر اپڈیٹ کریں')
                    : (user == null ? 'Add New User' : 'Update User'),
                style: TextStyle(
                    color: primaryGreen, fontWeight: FontWeight.bold)),
            content: SizedBox(
              width: double.maxFinite,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    TextField(
                      controller: nameController,
                      decoration: InputDecoration(
                          labelText: isUrdu ? 'نام' : 'Name',
                          border: const OutlineInputBorder()),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: usernameController,
                      decoration: InputDecoration(
                          labelText: isUrdu ? 'یوزر نیم' : 'Username',
                          border: const OutlineInputBorder()),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: passwordController,
                      decoration: InputDecoration(
                          labelText: isUrdu ? 'پاسورڈ' : 'Password',
                          border: const OutlineInputBorder()),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: role,
                      decoration: InputDecoration(
                          labelText: isUrdu ? 'رول' : 'Role',
                          border: const OutlineInputBorder()),
                      items: [
                        DropdownMenuItem(
                            value: 'admin',
                            child: Text(isUrdu ? 'ایڈمن' : 'Admin')),
                        DropdownMenuItem(
                            value: 'nazim',
                            child: Text(isUrdu ? 'ناظم' : 'Nazim')),
                        DropdownMenuItem(
                            value: 'teacher',
                            child: Text(isUrdu ? 'استاذ' : 'Teacher')),
                      ],
                      onChanged: (val) {
                        setStateDialog(() => role = val!);
                      },
                    ),
                    const SizedBox(height: 15),

                    // SECTION 1: Madrasa Permissions
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.school, size: 18, color: Colors.green),
                            const SizedBox(width: 6),
                            Text(isUrdu ? 'مدرسہ پرمیشنز' : 'Madrasa Permissions',
                                style: TextStyle(
                                    fontWeight: FontWeight.bold, color: primaryGreen)),
                          ],
                        ),
                        TextButton(
                          onPressed: () {
                            setStateDialog(() {
                              final allMadrasaKeys = madrasaPerms.keys.toSet();
                              final currentMadrasa = currentPerms.where((p) => allMadrasaKeys.contains(p)).toSet();
                              if (currentMadrasa.length == allMadrasaKeys.length) {
                                currentPerms.removeWhere((p) => allMadrasaKeys.contains(p));
                              } else {
                                currentPerms.addAll(allMadrasaKeys);
                                currentPerms = currentPerms.toSet().toList();
                              }
                            });
                          },
                          child: Text(isUrdu ? 'سب منتخب / ختم' : 'Toggle All',
                              style: const TextStyle(fontSize: 12)),
                        ),
                      ],
                    ),
                    const Divider(),
                    Container(
                      constraints: const BoxConstraints(maxHeight: 180),
                      decoration: BoxDecoration(
                        color: cBg,
                        border: Border.all(color: bColor),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: ListView(
                        shrinkWrap: true,
                        children: madrasaPerms.entries.map((e) {
                          return CheckboxListTile(
                            dense: true,
                            title: Text(e.value, style: const TextStyle(fontSize: 13)),
                            value: role == 'admin'
                                ? true
                                : currentPerms.contains(e.key),
                            onChanged: role == 'admin'
                                ? null
                                : (bool? val) {
                                    setStateDialog(() {
                                      if (val == true) {
                                        currentPerms.add(e.key);
                                      } else {
                                        currentPerms.remove(e.key);
                                      }
                                    });
                                  },
                          );
                        }).toList(),
                      ),
                    ),
                    const SizedBox(height: 15),

                    // SECTION 2: Allowed Classes
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.class_, size: 18, color: Colors.blue),
                            const SizedBox(width: 6),
                            Text(isUrdu ? 'متعلقہ کلاسز' : 'Allowed Classes',
                                style: TextStyle(
                                    fontWeight: FontWeight.bold, color: primaryGreen)),
                          ],
                        ),
                        TextButton(
                          onPressed: () {
                            setStateDialog(() {
                              if (currentAllowedClasses.length == allSystemClasses.length) {
                                currentAllowedClasses.clear();
                              } else {
                                currentAllowedClasses = allSystemClasses
                                    .map((c) => c['id'])
                                    .where((id) => id != null)
                                    .toList();
                              }
                            });
                          },
                          child: Text(isUrdu ? 'سب منتخب / ختم' : 'Toggle All',
                              style: const TextStyle(fontSize: 12)),
                        ),
                      ],
                    ),
                    const Divider(),
                    Container(
                      constraints: const BoxConstraints(maxHeight: 160),
                      decoration: BoxDecoration(
                        color: cBg,
                        border: Border.all(color: bColor),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: ListView(
                        shrinkWrap: true,
                        children: allSystemClasses.map((cls) {
                          final clsId = cls['id'];
                          final clsName = cls['name'] ?? '';
                          final isChecked = currentAllowedClasses.contains(clsId) || currentAllowedClasses.contains(clsId.toString());
                          return CheckboxListTile(
                            dense: true,
                            title: Text(clsName, style: const TextStyle(fontSize: 13)),
                            value: role == 'admin' ? true : isChecked,
                            onChanged: role == 'admin'
                                ? null
                                : (bool? val) {
                                    setStateDialog(() {
                                      if (val == true) {
                                        if (!currentAllowedClasses.contains(clsId)) {
                                          currentAllowedClasses.add(clsId);
                                        }
                                      } else {
                                        currentAllowedClasses.remove(clsId);
                                        currentAllowedClasses.remove(clsId.toString());
                                      }
                                    });
                                  },
                          );
                        }).toList(),
                      ),
                    ),
                    const SizedBox(height: 15),

                    // SECTION 3: Masjid Permissions
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.mosque, size: 18, color: Colors.amber),
                            const SizedBox(width: 6),
                            Text(isUrdu ? 'مسجد پرمیشنز' : 'Masjid Permissions',
                                style: TextStyle(
                                    fontWeight: FontWeight.bold, color: primaryGreen)),
                          ],
                        ),
                        TextButton(
                          onPressed: () {
                            setStateDialog(() {
                              final allMasjidKeys = masjidPerms.keys.toSet();
                              final currentMasjid = currentPerms.where((p) => allMasjidKeys.contains(p)).toSet();
                              if (currentMasjid.length == allMasjidKeys.length) {
                                currentPerms.removeWhere((p) => allMasjidKeys.contains(p));
                              } else {
                                currentPerms.addAll(allMasjidKeys);
                                currentPerms = currentPerms.toSet().toList();
                              }
                            });
                          },
                          child: Text(isUrdu ? 'سب منتخب / ختم' : 'Toggle All',
                              style: const TextStyle(fontSize: 12)),
                        ),
                      ],
                    ),
                    const Divider(),
                    Container(
                      constraints: const BoxConstraints(maxHeight: 180),
                      decoration: BoxDecoration(
                        color: cBg,
                        border: Border.all(color: bColor),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: ListView(
                        shrinkWrap: true,
                        children: masjidPerms.entries.map((e) {
                          return CheckboxListTile(
                            dense: true,
                            title: Text(e.value, style: const TextStyle(fontSize: 13)),
                            value: role == 'admin'
                                ? true
                                : currentPerms.contains(e.key),
                            onChanged: role == 'admin'
                                ? null
                                : (bool? val) {
                                    setStateDialog(() {
                                      if (val == true) {
                                        currentPerms.add(e.key);
                                      } else {
                                        currentPerms.remove(e.key);
                                      }
                                    });
                                  },
                          );
                        }).toList(),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(isUrdu ? 'منسوخ کریں' : 'Cancel',
                    style: const TextStyle(color: Colors.red)),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (nameController.text.isEmpty ||
                      usernameController.text.isEmpty ||
                      (user == null && passwordController.text.isEmpty)) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(isUrdu
                            ? 'تمام خانے پر کریں'
                            : 'Fill all required fields')));
                    return;
                  }
                  Navigator.pop(ctx);
                  await saveUser(
                    id: user?['id']?.toString() ?? '',
                    name: nameController.text,
                    username: usernameController.text,
                    password: passwordController.text,
                    role: role,
                    permissions:
                        role == 'admin' ? allPerms.keys.toList() : currentPerms,
                    allowedClasses: role == 'admin'
                        ? allSystemClasses.map((c) => c['id']).toList()
                        : currentAllowedClasses,
                  );
                },
                child: Text(isUrdu ? 'محفوظ کریں' : 'Save',
                    style: const TextStyle(color: Colors.white)),
              ),
            ],
          );
        });
      },
    );
  }

  Future<void> saveUser(
      {required String id,
      required String name,
      required String username,
      required String password,
      required String role,
      required List<String> permissions,
      required List<dynamic> allowedClasses}) async {
    setState(() => isLoading = true);
    try {
      final response = await NetworkService.postWithFallbackAction(
        'save_user',
        {
          'id': id,
          'name': name,
          'username': username,
          'password': password,
          'role': role,
          'permissions': permissions,
          'allowed_classes': allowedClasses,
        },
      );
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(data['message'] ?? 'Success')));
        }
        fetchUsers();
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Failed to save user')));
          setState(() => isLoading = false);
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Error: $e')));
        setState(() => isLoading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return PopScope(
          canPop: false,
          onPopInvokedWithResult: (didPop, result) {
            if (didPop) return;
            Navigator.pushReplacement(
                context, MaterialPageRoute(builder: (c) => DashboardPage()));
          },
          child: Directionality(
            textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
            child: Scaffold(
              backgroundColor: bgColor,
              appBar: AppBar(
                backgroundColor: primaryGreen,
                foregroundColor: Colors.white,
                leading: Builder(
                  builder: (context) => IconButton(
                    icon: const Icon(Icons.menu, color: Colors.white),
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  ),
                ),
                title: Text(isUrdu ? 'یوزرز کا انتظام' : 'Manage Users',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: const Icon(Icons.sync, color: Colors.white),
                    onPressed: () {
                      setState(() => isLoading = true);
                      fetchUsers();
                    },
                  ),
                  IconButton(
                    icon: const Icon(Icons.add, color: Colors.white),
                    onPressed: () => openUserDialog(),
                  )
                ],
              ),
              drawer: const AppDrawer(),
              body: isLoading
                  ? Center(
                      child: CircularProgressIndicator(color: primaryGreen))
                  : users.isEmpty
                      ? Center(
                          child: Text(
                              isUrdu ? "کوئی یوزر نہیں ملا" : "No users found"))
                      : ListView.builder(
                          padding: const EdgeInsets.all(10),
                          itemCount: users.length,
                          itemBuilder: (context, index) {
                            final u = users[index];
                            return Card(
                              color: Colors.white,
                              elevation: 2,
                              margin: const EdgeInsets.symmetric(vertical: 5),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(10)),
                              child: ListTile(
                                leading: CircleAvatar(
                                  backgroundColor:
                                      primaryGreen.withOpacity(0.1),
                                  child:
                                      Icon(Icons.person, color: primaryGreen),
                                ),
                                title: Text(u['name'] ?? '',
                                    style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        color: primaryGreen)),
                                subtitle: Text(
                                    '@${u['username']} - Role: ${u['role']}',
                                    style: TextStyle(color: Colors.grey[700])),
                                trailing: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    IconButton(
                                      icon: const Icon(Icons.edit,
                                          color: Colors.blue),
                                      onPressed: () => openUserDialog(user: u),
                                    ),
                                    if (u['id'].toString() == '1')
                                      Container(
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 8, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: Colors.red.shade50,
                                          borderRadius:
                                              BorderRadius.circular(6),
                                          border: Border.all(
                                              color: Colors.red.shade200),
                                        ),
                                        child: Text(
                                          isUrduGlobal.value
                                              ? 'مین اکاؤنٹ'
                                              : 'Main Admin',
                                          style: TextStyle(
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                              color: Colors.red.shade800),
                                        ),
                                      )
                                    else if (u['id'].toString() !=
                                        currentUserId.value?.toString())
                                      IconButton(
                                        icon: const Icon(Icons.delete,
                                            color: Colors.red),
                                        onPressed: () =>
                                            deleteUser(u['id'].toString()),
                                      ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ),
        );
      },
    );
  }
}
