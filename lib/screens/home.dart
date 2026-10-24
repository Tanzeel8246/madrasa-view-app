// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';

import 'package:flutter/material.dart';

import 'package:sqflite/sqflite.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../widgets/neo_box.dart';
import '../widgets/app_brand_logo.dart';
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
                      : (isUrdu ? 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)' : 'MC-MMS (Mindcrafts Madrasa Management System)');
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
                                const SizedBox(height: 25),
                                _aiFeaturesShowcase(isUrdu, isSmall),
                                const SizedBox(height: 25),
                                _systemFeaturesGrid(isUrdu, isSmall),
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
          const AppBrandLogo(size: 80),
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
                  : (isUrdu ? 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)' : 'MC-MMS (Mindcrafts Madrasa Management System)');
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

  Widget _aiFeaturesShowcase(bool isUrdu, bool isSmall) {
    final aiItems = [
      (
        Icons.mic_none_outlined,
        isUrdu ? 'AI وائس ٹائپنگ و صوتی اندراج' : 'AI Voice-to-Text Input',
        isUrdu
            ? 'کی بورڈ کے بغیر بول کر اسباق، حاضری، نوٹس اور کوائف کی فوری اردو و انگریزی ٹائپنگ اور اندراج۔'
            : 'Instant hands-free voice typing for lessons, attendance, and student notes in Urdu & English.',
        const Color(0xFF0D9488),
      ),
      (
        Icons.auto_awesome,
        isUrdu ? 'AI تعلیمی تجزیہ و پیش گوئی' : 'AI Academic Intelligence',
        isUrdu
            ? 'طالب علم کی رفتارِ حفظ و ناظرہ کا سمارٹ تجزیہ، کمزوریوں کی پیشگی نشاندہی اور حفظ کی تکمیل کا تخمینہ۔'
            : 'Smart tracking of student memorization velocity, weak-point detection, and completion forecasts.',
        const Color(0xFF7C3AED),
      ),
      (
        Icons.notifications_active_outlined,
        isUrdu ? 'AI سمارٹ الرٹس و آٹو واٹس ایپ' : 'Smart WhatsApp & Alerts',
        isUrdu
            ? 'طلبہ کی غیر حاضری، فیس بقایا جات اور سبقی پیش رفت پر والدین اور اساتذہ کو خودکار فوری پیغامات۔'
            : 'Automated real-time notifications to parents for absentees, pending fees, and lesson updates.',
        const Color(0xFF2563EB),
      ),
      (
        Icons.insights,
        isUrdu ? 'AI مالیاتی تخمینہ و سمارٹ بجٹ' : 'AI Financial Analytics',
        isUrdu
            ? 'ماہانہ اخراجات و آمدن کا جدید موازنہ، ممکنہ خسارے کا پیشگی الارم اور سمارٹ بجٹ تجاویز۔'
            : 'Predictive budgeting, expense trend analytics, and automated financial health indicators.',
        const Color(0xFFD97706),
      ),
    ];

    return Container(
      margin: const EdgeInsets.only(top: 5, bottom: 10),
      padding: EdgeInsets.all(isSmall ? 16 : 22),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            const Color(0xFF0F2B1D),
            const Color(0xFF1B4332),
            const Color(0xFF0A1F16),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: accentGold.withValues(alpha: 0.6),
          width: 1.5,
        ),
        boxShadow: [
          BoxShadow(
            color: primaryGreen.withValues(alpha: 0.35),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header Badge
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: accentGold.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: accentGold),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.auto_awesome, color: accentGold, size: 16),
                    const SizedBox(width: 6),
                    Text(
                      isUrdu ? 'مصنوعی ذہانت سے لیس (AI Powered)' : 'AI Powered ERP',
                      style: TextStyle(
                        color: accentGold,
                        fontWeight: FontWeight.bold,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  'MC-MMS 2026',
                  style: TextStyle(
                    color: Colors.white70,
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            isUrdu
                ? 'جدید مصنوعی ذہانت (AI) کا انقلابی انضمام'
                : 'Revolutionary AI Integration',
            style: TextStyle(
              color: Colors.white,
              fontSize: isSmall ? 18 : 22,
              fontWeight: FontWeight.bold,
              height: 1.3,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            isUrdu
                ? 'تعلیمی اور انتظامی امور کو خودکار بنانے کے لیے جدید AI ٹیکنالوجی کو مربوط کیا گیا ہے، جس سے اساتذہ اور انتظامیہ کا 70% قیمتی وقت بچتا ہے:'
                : 'Empowering institutions with next-generation AI tools that automate repetitive tasks and save 70% administrative time:',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.85),
              fontSize: isSmall ? 12 : 13,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 18),
          LayoutBuilder(
            builder: (ctx, constraints) {
              final isNarrow = constraints.maxWidth < 600;
              return GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: isNarrow ? 1 : 2,
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                  childAspectRatio: isNarrow ? 2.6 : 1.9,
                ),
                itemCount: aiItems.length,
                itemBuilder: (ctx, idx) {
                  final item = aiItems[idx];
                  return Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.07),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: Colors.white.withValues(alpha: 0.12),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: item.$4.withValues(alpha: 0.2),
                            shape: BoxShape.circle,
                            border: Border.all(
                              color: item.$4.withValues(alpha: 0.6),
                              width: 1.5,
                            ),
                          ),
                          child: Icon(item.$1, color: Colors.white, size: 20),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Text(
                                item.$2,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                item.$3,
                                style: TextStyle(
                                  color: Colors.white.withValues(alpha: 0.75),
                                  fontSize: 11,
                                  height: 1.4,
                                ),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  );
                },
              );
            },
          ),
        ],
      ),
    );
  }

  Widget _systemFeaturesGrid(bool isUrdu, bool isSmall) {
    final modules = [
      (
        Icons.menu_book,
        isUrdu ? 'جامع شعبہ تعلیم و امتحانات' : 'Academics & Exams',
        isUrdu
            ? 'روزانہ کا سبق، سبقی، منزل، کتب، حاضری رجسٹر اور ششماہی و سالانہ رزلٹ کارڈز کا خودکار انتظام۔'
            : 'Comprehensive management for Daily Sabaq, Sabqi, Manzil, Dars-e-Nizami, and auto result cards.',
        const Color(0xFF10B981),
      ),
      (
        Icons.account_balance_wallet,
        isUrdu ? 'فنانس، فیس و ڈیجیٹل رسیدات' : 'Finance & Fee ERP',
        isUrdu
            ? 'ڈبل انٹری کیش بک، آن لائن فیس واؤچرز، عملہ تنخواہیں، اخراجات اور تھرمل و پی ڈی ایف رسیدیں۔'
            : 'Double-entry accounting, student fee challans, staff payroll, and thermal receipt printing.',
        const Color(0xFFF59E0B),
      ),
      (
        Icons.fingerprint,
        isUrdu ? 'بائیو میٹرک و کیو آر حاضری' : 'Biometric & QR Attendance',
        isUrdu
            ? 'طلباء و اساتذہ کی ڈیجیٹل کارڈ اور فنگر پرنٹ سے حاضری، چھٹی کی درخواستیں اور تفصیلی لسٹ۔'
            : 'Digital QR card & biometric attendance, staff leave requests, and absentee tracking.',
        const Color(0xFF06B6D4),
      ),
      (
        Icons.family_restroom,
        isUrdu ? 'پیرنٹس موبائل پورٹل' : 'Parents Live Portal',
        isUrdu
            ? 'والدین کے لیے گھر بیٹھے اپنے بچوں کے اسباق، حاضری، تعلیمی رپورٹس اور فیس کی مکمل معلومات۔'
            : 'Real-time parent access to daily attendance, academic progress, fee status, and teacher chat.',
        const Color(0xFF8B5CF6),
      ),
      (
        Icons.night_shelter,
        isUrdu ? 'دارالاقامہ، کتب خانہ و راشن' : 'Hostel & Library',
        isUrdu
            ? 'رہائشی طلباء کمرے، میس انوینٹری، کتب اجراء و واپسی اور مدرسہ سٹور اسٹاک کا مکمل کنٹرول۔'
            : 'Room allotments, mess inventory, library book checkouts, and institutional store management.',
        const Color(0xFFEC4899),
      ),
      (
        Icons.cloud_sync,
        isUrdu ? 'کلاؤڈ ساس و ملٹی ٹیننسی' : 'Cloud SaaS & Offline Sync',
        isUrdu
            ? 'انٹرنیٹ کے بغیر بھی آف لائن کام، کلاؤڈ کے ساتھ محفوظ بیک اپ اور تینوں پلیٹ فارمز پر بیک وقت یکسانیت۔'
            : 'Offline-first SQLite local engine with secure cloud sync across Web, Mobile, and Desktop.',
        const Color(0xFF3B82F6),
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 10),
        _sectionTitle(isUrdu ? 'نظام کے جامع ماڈیولز و خصوصیات' : 'Core ERP Modules & Features'),
        const SizedBox(height: 8),
        Text(
          isUrdu
              ? 'مدرسہ اور جامع مسجد کی تمام تر انتظامی، تدریسی اور مالیاتی ضروریات کو پورا کرنے والا واحد ہمہ جہت سافٹ ویئر'
              : 'All-in-one institutional management suite designed specifically for Islamic institutes & Mosques',
          style: const TextStyle(
            fontSize: 12,
            color: Colors.black54,
            height: 1.5,
          ),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 16),
        LayoutBuilder(
          builder: (ctx, constraints) {
            final isNarrow = constraints.maxWidth < 650;
            return GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: isNarrow ? 1 : 2,
                crossAxisSpacing: 12,
                mainAxisSpacing: 12,
                childAspectRatio: isNarrow ? 3.0 : 2.2,
              ),
              itemCount: modules.length,
              itemBuilder: (ctx, idx) {
                final m = modules[idx];
                return NeoBox(
                  padding: 14,
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: m.$4.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Icon(m.$1, color: m.$4, size: 24),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(
                              m.$2,
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                                color: primaryGreen,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              m.$3,
                              style: const TextStyle(
                                fontSize: 11,
                                color: Colors.black54,
                                height: 1.35,
                              ),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                );
              },
            );
          },
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
                    ? 'نظامِ MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم) قرآن، تجوید، حسن صوت اور دینی و اخلاقی تربیت کے اداروں کے لیے تیار کیا گیا ایک جدید اور جامع نظام ہے، جہاں روایتی دینی تعلیم کو عصرِ حاضر کے تقاضوں سے ہم آہنگ کیا گیا ہے۔\n\n'
                      'اس نظام نے تعلیمی و انتظامی عمل کو مکمل طور پر ڈیجیٹلائز کر دیا ہے تاکہ شفافیت اور سہولت کو یقینی بنایا جا سکے۔ اس ایپ میں اہم پینلز موجود ہیں:\n\n'
                      '۱۔ ایڈمن و ٹیچر پینل: جس کے ذریعے اساتذہ اور انتظامیہ طلباء کی حاضری، روزانہ کا سبق (حفظ و ناظرہ)، امتحانات اور فیس کا ریکارڈ باآسانی مرتب کرتے ہیں۔\n'
                      '۲۔ پیرنٹس (والدین) پینل: جس کے ذریعے والدین گھر بیٹھے اپنے بچوں کی کارکردگی، روزانہ کی حاضری، تعلیمی رپورٹس اور فیس کی تفصیلات دیکھ سکتے ہیں، نیز آن لائن چھٹی کی درخواست اور اساتذہ سے رابطہ بھی کر سکتے ہیں۔\n\n'
                      'اس ڈیجیٹل نظام کی بدولت مدرسہ اور والدین کے درمیان رابطہ مضبوط ہوا ہے اور بچوں کی تعلیمی و اخلاقی تربیت میں خاطر خواہ بہتری آئی ہے۔'
                    : 'MC-MMS (Mindcrafts Madrasa Management System) is a comprehensive ERP for Quranic learning and moral training centers, blending traditional Islamic education with modern technological advancements.\n\n'
                      'The system digitalizes administrative and academic processes to ensure transparency and efficiency. This application features dedicated panels:\n\n'
                      '1. Admin & Teacher Panel: Empowers the administration to seamlessly manage daily attendance, academic progress (Hifz & Nazra), exams, and financial records.\n'
                      '2. Parents Panel: Allows parents to stay connected with their child\'s educational journey from home, offering real-time access to daily reports, attendance, fee statuses, and the ability to submit online leave requests.\n\n'
                      'This digital transformation bridges the gap between institutions and parents, fostering a collaborative environment for student excellence.',
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
