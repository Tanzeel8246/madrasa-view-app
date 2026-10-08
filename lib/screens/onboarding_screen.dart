import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../utils/globals.dart';
import 'home.dart';

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final PageController _pageController = PageController();
  int _currentPage = 0;

  final List<Map<String, dynamic>> _slides = [
    {
      'icon': Icons.school,
      'titleUrdu': 'جامع مدرسہ و مکتب مینجمنٹ',
      'titleEn': 'Complete Madrasa & Maktab ERP',
      'descUrdu':
          'طلبہ و اساتذہ کا مکمل ریکارڈ، حفظ، ناظرہ، قاعدہ اور روزانہ حاضری کا جدید ترین اور آسان نظام۔',
      'descEn':
          'Comprehensive records for students, teachers, Hifz, Nazira, Qaida, and smart daily attendance.',
      'color': const Color(0xFF284D3D),
    },
    {
      'icon': Icons.account_balance_wallet,
      'titleUrdu': 'شفاف فنانس و اکاؤنٹنگ',
      'titleEn': 'Double-Entry Finance & Accounting',
      'descUrdu':
          'آمدن و اخراجات کی کمپیوٹرائزڈ رسیدیں، پے رول تنخواہیں، اور چارٹ آف اکاؤنٹس کی مکمل سپورٹ۔',
      'descEn':
          'Computerized receipts for income & expenses, staff payroll, and double-entry chart of accounts.',
      'color': const Color(0xFF1E3C72),
    },
    {
      'icon': Icons.cloud_sync,
      'titleUrdu': 'آف لائن فرسٹ اور تیز رفتار سنک',
      'titleEn': 'Offline-First & Fast Two-Way Sync',
      'descUrdu':
          'بغیر انٹرنیٹ کے بھی ایپ مکمل فعال رہتی ہے۔ انٹرنیٹ دستیاب ہونے پر خودکار کلاؤڈ سنک ہو جاتی ہے۔',
      'descEn':
          'Works 100% offline without internet. Automatically syncs with the web panel whenever connected.',
      'color': const Color(0xFF0F5132),
    },
  ];

  Future<void> _completeOnboarding() async {
    const storage = FlutterSecureStorage();
    await storage.write(key: 'has_seen_onboarding', value: 'true');
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => const HomePage()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 550),
              child: Column(
            children: [
              Align(
                alignment:
                    isUrdu ? Alignment.topLeft : Alignment.topRight,
                child: TextButton(
                  onPressed: _completeOnboarding,
                  child: Text(
                    isUrdu ? 'چھوڑیئے (Skip)' : 'Skip',
                    style: TextStyle(
                        color: primaryGreen, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              Expanded(
                child: PageView.builder(
                  controller: _pageController,
                  itemCount: _slides.length,
                  onPageChanged: (idx) {
                    setState(() {
                      _currentPage = idx;
                    });
                  },
                  itemBuilder: (context, idx) {
                    final slide = _slides[idx];
                    return Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 30),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(30),
                            decoration: BoxDecoration(
                              color: (slide['color'] as Color)
                                  .withValues(alpha: 0.1),
                              shape: BoxShape.circle,
                            ),
                            child: Icon(
                              slide['icon'] as IconData,
                              size: 90,
                              color: slide['color'] as Color,
                            ),
                          ),
                          const SizedBox(height: 35),
                          Text(
                            isUrdu
                                ? slide['titleUrdu']
                                : slide['titleEn'],
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 22,
                              fontWeight: FontWeight.bold,
                              color: slide['color'] as Color,
                            ),
                          ),
                          const SizedBox(height: 15),
                          Text(
                            isUrdu
                                ? slide['descUrdu']
                                : slide['descEn'],
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                              fontSize: 15,
                              color: Colors.black87,
                              height: 1.6,
                            ),
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(
                  _slides.length,
                  (index) => Container(
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    width: _currentPage == index ? 24 : 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: _currentPage == index
                          ? primaryGreen
                          : Colors.grey.shade300,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 30),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 30),
                child: SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: primaryGreen,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () {
                      if (_currentPage < _slides.length - 1) {
                        _pageController.nextPage(
                          duration: const Duration(milliseconds: 300),
                          curve: Curves.easeInOut,
                        );
                      } else {
                        _completeOnboarding();
                      }
                    },
                    child: Text(
                      _currentPage == _slides.length - 1
                          ? (isUrdu ? 'شروع کریں (Get Started)' : 'Get Started')
                          : (isUrdu ? 'آگے بڑھیں (Next)' : 'Next'),
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 25),
            ],
          ),
        ),
      ),
    ),
  ),
);
  }
}
