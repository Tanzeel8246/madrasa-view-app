// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';

import 'package:flutter/material.dart';

import 'package:sqflite/sqflite.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../widgets/neo_box.dart';
import 'admission_form.dart';
import 'dashboard.dart';
import 'login.dart';

class HomePage extends StatefulWidget {
  const HomePage({super.key});

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color accentGold = const Color(0xFFD4AC0D);

  List<Map<String, dynamic>> _notices = [];
  Map<String, String> _stats = {
    'total_students': '0',
    'total_teachers': '0',
    'total_hifz': '0',
    'total_depts': '0',
  };

  @override
  void initState() {
    super.initState();
    _loadNotices();
    _loadStats();
  }

  Future<void> _loadNotices() async {
    final data = await DBHelper().getNotices();
    if (!mounted) return;
    setState(() => _notices = data.take(5).toList());
  }

  Future<void> _loadStats() async {
    // 1. پہلے لوکل ڈیٹابیس سے حقیقی معلومات حاصل کریں
    try {
      final db = await DBHelper().database;
      final ts = Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM students WHERE status="Active"')) ?? 0;
      final tt = Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM teachers WHERE status="Active"')) ?? 0;
      final th = Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM students WHERE status="Active" AND (class LIKE "%حفظ%" OR class LIKE "%Hifz%")')) ?? 0;
      final td = Sqflite.firstIntValue(await db.rawQuery('SELECT COUNT(*) FROM classes')) ?? 0;
      
      if (!mounted) return;
      setState(() {
        _stats = {
          'total_students': ts.toString(),
          'total_teachers': tt.toString(),
          'total_hifz': th.toString(),
          'total_depts': td > 0 ? td.toString() : '5',
        };
      });
    } catch (e) {
      debugPrint("Local stats error: $e");
    }

    // 2. بیک گراؤنڈ میں لائیو سرور سے اپڈیٹ کریں
    try {
      final response = await NetworkService.publicGetWithFallbackAction('get_stats');
      if (response.statusCode != 200) return;
      final data = json.decode(response.body);
      if (data['status'] != 'success') return;
      if (!mounted) return;
      
      final netTs = data['data']['total_students']?.toString() ?? '0';
      if (netTs != '0') {
        setState(() {
          _stats = {
            'total_students': netTs,
            'total_teachers': data['data']['total_teachers']?.toString() ?? '0',
            'total_hifz': data['data']['total_hifz']?.toString() ?? '0',
            'total_depts': data['data']['total_depts']?.toString() ?? '0',
          };
        });
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
          child: Scaffold(
            backgroundColor: bgColor,
            appBar: AppBar(
              backgroundColor: primaryGreen,
              foregroundColor: Colors.white,
              title: ValueListenableBuilder<String?>(
                valueListenable: currentTenantName,
                builder: (context, tName, _) {
                  final titleText = (tName != null && tName.trim().isNotEmpty)
                      ? tName.trim()
                      : (isUrdu ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris');
                  return Text(
                    titleText,
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  );
                },
              ),
              centerTitle: true,
              actions: [
                IconButton(
                  tooltip: isUrdu ? 'English' : 'اردو',
                  icon: const Icon(Icons.language, color: Colors.white),
                  onPressed: () => isUrduGlobal.value = !isUrduGlobal.value,
                ),
              ],
            ),
            body: LayoutBuilder(
              builder: (context, constraints) {
                final isSmall = constraints.maxWidth < 400;
                final hPad = isSmall ? 10.0 : 15.0;
                return SingleChildScrollView(
                  physics: const BouncingScrollPhysics(),
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 1100),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _hero(context, isUrdu, hPad, isSmall),
                          Padding(
                            padding: EdgeInsets.symmetric(horizontal: hPad),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                if (_notices.isNotEmpty) _noticeBar(isUrdu),
                                _statsGrid(isUrdu, isSmall),
                                const SizedBox(height: 30),
                                _aboutSection(isUrdu),
                                const SizedBox(height: 30),
                                _sectionTitle(isUrdu
                                    ? 'نمایاں شعبہ جات'
                                    : 'Featured Departments'),
                                const SizedBox(height: 15),
                                _departmentList(isUrdu),
                                const SizedBox(height: 30),
                                _sectionTitle(
                                    isUrdu ? 'دیگر سہولیات' : 'Facilities'),
                                const SizedBox(height: 15),
                                _facilitiesGrid(isUrdu),
                                const SizedBox(height: 30),
                                _admissionProcess(context, isUrdu),
                                const SizedBox(height: 30),
                                _noticeBoard(isUrdu),
                                const SizedBox(height: 30),
                              ],
                            ),
                          ),
                          _footer(isUrdu),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        );
      },
    );
  }

  Widget _hero(BuildContext context, bool isUrdu, double hPad, bool isSmall) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: hPad,
        vertical: isSmall ? 28 : 40,
      ),
      margin: EdgeInsets.all(hPad),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [primaryGreen, const Color(0xFF143527)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.2),
            offset: const Offset(0, 8),
            blurRadius: 15,
          ),
        ],
      ),
      child: Column(
        children: [
          ColorFiltered(
            colorFilter: const ColorFilter.mode(
              Colors.white,
              BlendMode.srcIn,
            ),
            child: Image.asset('assets/logo.png', height: 80),
          ),
          const SizedBox(height: 12),
          Text(
            isUrdu ? 'تعمیر ملت کا عظیم قلعہ' : 'A Center for Quranic Learning',
            style: TextStyle(
              fontSize: isSmall ? 13 : 16,
              color: Colors.white.withValues(alpha: 0.85),
              fontWeight: FontWeight.bold,
            ),
            textAlign: TextAlign.center,
          ),
          ValueListenableBuilder<String?>(
            valueListenable: currentTenantName,
            builder: (context, tName, _) {
              final heroText = (tName != null && tName.trim().isNotEmpty)
                  ? tName.trim()
                  : (isUrdu ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris');
              return Text(
                heroText,
                style: TextStyle(
                  fontSize: isSmall ? 25 : 30,
                  color: accentGold,
                  fontWeight: FontWeight.bold,
                ),
                textAlign: TextAlign.center,
              );
            },
          ),
          const SizedBox(height: 8),
          Text(
            isUrdu
                ? 'قرآن، تجوید اور حسن صوت کے ذریعے اخلاقی و علمی آبیاری'
                : 'Quran, Tajweed, Qiraat, and balanced modern education',
            style: TextStyle(
              fontSize: isSmall ? 12 : 14,
              color: Colors.white,
              height: 1.5,
            ),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: accentGold,
                    foregroundColor: Colors.black,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(30),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => const AdmissionFormPage(),
                    ),
                  ),
                  icon: const Icon(Icons.school, size: 18),
                  label: Text(
                    isUrdu ? 'داخلہ شروع ہے' : 'Apply Now',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(30),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: () {
                    if (currentUserToken.value != null &&
                        currentUserToken.value!.isNotEmpty) {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => const DashboardPage()),
                      );
                    } else {
                      Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => const LoginPage()),
                      );
                    }
                  },
                  icon: const Icon(Icons.login, size: 18),
                  label: Text(isUrdu ? 'لاگ ان' : 'Login'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _noticeBar(bool isUrdu) {
    final notice = _notices.first;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      margin: const EdgeInsets.only(bottom: 20),
      decoration: BoxDecoration(
        color: Colors.red.shade50,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.red.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          const Icon(Icons.campaign, color: Colors.red, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '${notice['title']}: ${notice['message']}',
              style: const TextStyle(
                color: Colors.red,
                fontWeight: FontWeight.bold,
                fontSize: 11,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }

  Widget _statsGrid(bool isUrdu, bool isSmall) {
    return GridView.count(
      crossAxisCount: isSmall ? 2 : 4,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisSpacing: 8,
      mainAxisSpacing: 8,
      childAspectRatio: isSmall ? 1.4 : 1.25,
      children: [
        _statBox(
          Icons.groups,
          isUrdu ? 'کل طلبہ' : 'Students',
          _stats['total_students'] ?? '0',
        ),
        _statBox(
          Icons.person,
          isUrdu ? 'ماہر اساتذہ' : 'Teachers',
          _stats['total_teachers'] ?? '0',
        ),
        _statBox(
          Icons.menu_book,
          isUrdu ? 'حفاظ کرام' : 'Huffaz',
          _stats['total_hifz'] ?? '0',
        ),
        _statBox(
          Icons.apartment,
          isUrdu ? 'کل کلاسز' : 'Classes',
          _stats['total_depts'] ?? '0',
        ),
      ],
    );
  }

  Widget _aboutSection(bool isUrdu) {
    return Column(
      children: [
        _sectionTitle(isUrdu ? 'ادارے کا تعارف' : 'About Us'),
        const SizedBox(height: 15),
        ClipRRect(
          borderRadius: BorderRadius.circular(15),
          child: Image.network(
            'https://images.unsplash.com/photo-1590075865003-e48267af035c?q=80&w=1080&auto=format&fit=crop',
            height: 200,
            width: double.infinity,
            fit: BoxFit.cover,
            errorBuilder: (context, error, stackTrace) => _imageFallback(),
          ),
        ),
        const SizedBox(height: 15),
        NeoBox(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                isUrdu
                    ? 'مدرسہ اشرف المدارس قرآن، تجوید، حسن صوت اور دینی و اخلاقی تربیت کا ایک معتبر مرکز ہے، جہاں روایتی دینی تعلیم کو عصرِ حاضر کے تقاضوں سے ہم آہنگ کیا گیا ہے۔\n\n'
                      'ادارے نے اپنے تعلیمی و انتظامی نظام کو مکمل طور پر ڈیجیٹلائز کر دیا ہے تاکہ شفافیت اور سہولت کو یقینی بنایا جا سکے۔ اس ایپ میں دو اہم پینلز موجود ہیں:\n\n'
                      '۱۔ ایڈمن و ٹیچر پینل: جس کے ذریعے اساتذہ اور انتظامیہ طلباء کی حاضری، روزانہ کا سبق (حفظ و ناظرہ)، امتحانات اور فیس کا ریکارڈ باآسانی مرتب کرتے ہیں۔\n'
                      '۲۔ پیرنٹس (والدین) پینل: جس کے ذریعے والدین گھر بیٹھے اپنے بچوں کی کارکردگی، روزانہ کی حاضری، تعلیمی رپورٹس اور فیس کی تفصیلات دیکھ سکتے ہیں، نیز آن لائن چھٹی کی درخواست اور اساتذہ سے رابطہ بھی کر سکتے ہیں۔\n\n'
                      'اس ڈیجیٹل نظام کی بدولت مدرسہ اور والدین کے درمیان رابطہ مضبوط ہوا ہے اور بچوں کی تعلیمی و اخلاقی تربیت میں خاطر خواہ بہتری آئی ہے۔'
                    : 'Madrasa Ashraf-ul-Madaris is a prestigious center for Quranic learning and moral training, blending traditional education with modern technological advancements.\n\n'
                      'The institution has fully digitalized its management system to ensure transparency and efficiency. This application features two dedicated panels:\n\n'
                      '1. Admin & Teacher Panel: Empowers the administration to seamlessly manage daily attendance, academic progress (Hifz & Nazra), exams, and financial records.\n'
                      '2. Parents Panel: Allows parents to stay connected with their child\'s educational journey from home, offering real-time access to daily reports, attendance, fee statuses, and the ability to submit online leave requests.\n\n'
                      'This digital transformation bridges the gap between the Madrasa and parents, fostering a collaborative environment for the better upbringing and education of the students.',
                style: const TextStyle(fontSize: 13, height: 1.8),
                textAlign: TextAlign.justify,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _departmentList(bool isUrdu) {
    final departments = [
      (
        Icons.menu_book,
        isUrdu ? 'حفظ قرآن کریم' : 'Hifz-ul-Quran',
        isUrdu ? 'تجوید کے ساتھ پختہ حفظ' : 'Memorization with Tajweed'
      ),
      (
        Icons.auto_stories,
        isUrdu ? 'ناظرہ قرآن' : 'Nazra Quran',
        isUrdu ? 'نورانی قاعدہ اور تجوید کی بنیاد' : 'Foundations of recitation'
      ),
      (
        Icons.record_voice_over,
        isUrdu ? 'حسن صوت و قرات' : 'Qiraat & Voice Training',
        isUrdu ? 'مختلف لہجوں میں مہارت' : 'Training in recitation styles'
      ),
      (
        Icons.school,
        isUrdu ? 'درس نظامی' : 'Dars-e-Nizami',
        isUrdu
            ? 'وفاق المدارس کے نصاب کے مطابق'
            : 'Traditional Islamic curriculum'
      ),
      (
        Icons.laptop_mac,
        isUrdu ? 'عصری تعلیم' : 'Contemporary Education',
        isUrdu ? 'دینی و عصری تعلیم کا امتزاج' : 'Balanced modern learning'
      ),
    ];

    return Column(
      children: departments
          .map(
            (d) => Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: NeoBox(
                padding: 12,
                child: Row(
                  children: [
                    CircleAvatar(
                      backgroundColor: primaryGreen.withValues(alpha: 0.1),
                      child: Icon(d.$1, color: primaryGreen),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            d.$2,
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 14,
                            ),
                          ),
                          Text(
                            d.$3,
                            style: const TextStyle(
                              fontSize: 11,
                              color: Colors.black54,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          )
          .toList(),
    );
  }

  Widget _facilitiesGrid(bool isUrdu) {
    final facilities = [
      (Icons.computer, isUrdu ? 'کمپیوٹر لیب' : 'Computer Lab'),
      (Icons.local_library, isUrdu ? 'لائبریری' : 'Library'),
      (Icons.hotel, isUrdu ? 'رہائش' : 'Accommodation'),
      (Icons.security, isUrdu ? 'محفوظ نظام' : 'Safe Environment'),
    ];
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisSpacing: 10,
      mainAxisSpacing: 10,
      childAspectRatio: 1.45,
      children: facilities
          .map((f) => NeoBox(
                padding: 12,
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(f.$1, color: primaryGreen, size: 30),
                    const SizedBox(height: 8),
                    Text(
                      f.$2,
                      style: const TextStyle(fontWeight: FontWeight.bold),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ))
          .toList(),
    );
  }

  Widget _admissionProcess(BuildContext context, bool isUrdu) {
    return NeoBox(
      child: Row(
        children: [
          _processStep(
            Icons.assignment,
            isUrdu ? '۱۔ فارم' : '1. Form',
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const AdmissionFormPage()),
            ),
          ),
          const Icon(Icons.chevron_right, color: Colors.grey),
          _processStep(Icons.quiz, isUrdu ? '۲۔ ٹیسٹ' : '2. Test'),
          const Icon(Icons.chevron_right, color: Colors.grey),
          _processStep(Icons.verified, isUrdu ? '۳۔ منظوری' : '3. Approval'),
        ],
      ),
    );
  }

  Widget _noticeBoard(bool isUrdu) {
    return Column(
      children: [
        _sectionTitle(isUrdu ? 'نوٹس بورڈ' : 'Notice Board'),
        const SizedBox(height: 15),
        NeoBox(
          child: _notices.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(20),
                  child: Center(
                    child: Text(isUrdu ? 'کوئی نوٹس نہیں' : 'No notices'),
                  ),
                )
              : Column(
                  children: _notices
                      .map(
                        (notice) => ListTile(
                          leading: const Icon(Icons.circle,
                              color: Colors.red, size: 10),
                          title: Text(
                            notice['title']?.toString() ?? '',
                            style: const TextStyle(
                              color: Colors.red,
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                            ),
                          ),
                          subtitle: Text(
                            notice['message']?.toString() ?? '',
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      )
                      .toList(),
                ),
        ),
      ],
    );
  }

  Widget _footer(bool isUrdu) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: const BoxDecoration(
        color: Color(0xFF112B1E),
        borderRadius: BorderRadius.vertical(top: Radius.circular(30)),
      ),
      child: Column(
        children: [
          Row(
            children: [
              const Icon(Icons.mosque, color: Colors.white70, size: 40),
              const SizedBox(width: 15),
              Expanded(
                child: ValueListenableBuilder<String?>(
                  valueListenable: currentTenantName,
                  builder: (context, tName, _) {
                    final fName = (tName != null && tName.trim().isNotEmpty)
                        ? tName.trim()
                        : (isUrdu ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris');
                    return Text(
                      fName,
                      style: TextStyle(
                        color: accentGold,
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            isUrdu
                ? 'قرآن، تجوید اور حسن صوت کا مرکز'
                : 'A center for Quran, Tajweed, and Qiraat',
            style: const TextStyle(color: Colors.white70, fontSize: 12),
          ),
          const Divider(color: Colors.white24, height: 25),
          const Text(
            '0301-2454595',
            style: TextStyle(color: Colors.white, fontSize: 13),
            textDirection: TextDirection.ltr,
          ),
          const SizedBox(height: 18),
          ValueListenableBuilder<String?>(
            valueListenable: currentTenantName,
            builder: (context, tName, _) {
              final cName = (tName != null && tName.trim().isNotEmpty)
                  ? tName.trim()
                  : (isUrdu ? 'مدرسہ اشرف المدارس' : 'Madrasa Ashraf-ul-Madaris');
              return Text(
                isUrdu
                    ? 'کاپی رائٹ ${DateTime.now().year} $cName'
                    : 'Copyright ${DateTime.now().year} $cName',
                style: const TextStyle(color: Colors.white30, fontSize: 11),
              );
            },
          ),
        ],
      ),
    );
  }

  Widget _sectionTitle(String title) {
    return Center(
      child: Text(
        title,
        style: TextStyle(
          fontSize: 20,
          color: primaryGreen,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }

  Widget _statBox(IconData icon, String title, String value) {
    return NeoBox(
      padding: 8,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(icon, color: primaryGreen, size: 22),
          const SizedBox(height: 5),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              value,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: primaryGreen,
              ),
            ),
          ),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              title,
              style: const TextStyle(fontSize: 10),
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }

  Widget _processStep(IconData icon, String title, {VoidCallback? onTap}) {
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: primaryGreen.withValues(alpha: 0.05),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: primaryGreen.withValues(alpha: 0.25)),
          ),
          child: Column(
            children: [
              Icon(icon, color: accentGold, size: 30),
              const SizedBox(height: 5),
              Text(
                title,
                style: TextStyle(
                  color: primaryGreen,
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _imageFallback() {
    return Container(
      height: 200,
      width: double.infinity,
      decoration: BoxDecoration(
        gradient:
            LinearGradient(colors: [primaryGreen, const Color(0xFF143527)]),
        borderRadius: BorderRadius.circular(15),
      ),
      child: const Icon(Icons.mosque, size: 70, color: Colors.white24),
    );
  }
}
