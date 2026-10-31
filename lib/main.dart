import 'dart:io';
import 'dart:ui';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'utils/globals.dart';
import 'utils/network_service.dart';
import 'config/app_colors.dart';
import 'screens/home.dart';
import 'screens/dashboard.dart';
import 'screens/onboarding_screen.dart';
import 'utils/session.dart';
import 'utils/notification_service.dart';
import 'db_helper.dart';

final GlobalKey<NavigatorState> rootNavigatorKey = GlobalKey<NavigatorState>();

class AppScrollBehavior extends MaterialScrollBehavior {
  const AppScrollBehavior();

  @override
  Set<PointerDeviceKind> get dragDevices => {
        PointerDeviceKind.touch,
        PointerDeviceKind.mouse,
        PointerDeviceKind.trackpad,
        PointerDeviceKind.stylus,
      };

  @override
  Widget buildScrollbar(
      BuildContext context, Widget child, ScrollableDetails details) {
    final bool isDesktop = !kIsWeb &&
        (Platform.isWindows || Platform.isLinux || Platform.isMacOS);
    if (isDesktop) {
      return RawScrollbar(
        controller: details.controller,
        thumbVisibility: true,
        trackVisibility: true,
        interactive: true,
        thickness: 10.0,
        radius: const Radius.circular(8),
        thumbColor: AppColors.primary.withValues(alpha: 0.65),
        trackColor: Colors.black.withValues(alpha: 0.05),
        trackBorderColor: Colors.black.withValues(alpha: 0.08),
        fadeDuration: Duration.zero,
        timeToFade: Duration.zero,
        child: child,
      );
    }
    return super.buildScrollbar(context, child, details);
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Desktop SQLite FFI Initialization (Windows, Linux, macOS)
  if (!kIsWeb && (Platform.isWindows || Platform.isLinux || Platform.isMacOS)) {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  }

  await NotificationService.initialize();

  // Load Saved Server URL if configured
  try {
    const storage = FlutterSecureStorage();
    final customServer = await storage.read(key: 'custom_server_url');
    if (customServer != null && customServer.trim().isNotEmpty) {
      NetworkService.setActiveBaseUrl(customServer.trim());
    }
  } catch (_) {}

  // Load Language Preference (Persistent across app exit and re-login)
  try {
    const storage = FlutterSecureStorage();
    final savedLang = await storage.read(key: 'app_language_urdu');
    if (savedLang != null) {
      isUrduGlobal.value = (savedLang == 'true');
    }
    // Listen for language changes and persist immediately
    isUrduGlobal.addListener(() async {
      try {
        await storage.write(key: 'app_language_urdu', value: isUrduGlobal.value.toString());
      } catch (_) {}
    });
  } catch (_) {}

  // Load Dark Mode Preference
  try {
    const storage = FlutterSecureStorage();
    final savedDarkMode = await storage.read(key: 'is_dark_mode');
    if (savedDarkMode == 'true') {
      isDarkMode.value = true;
    }
  } catch (_) {}

  // Auto-login logic
  Widget initialScreen = const HomePage();
  final session = await SessionManager.getSession();
  if (session != null &&
      session['token'] != null &&
      session['token'].toString().isNotEmpty) {
    currentUserRole.value = session['role'];
    currentUserId.value = session['userId'];
    currentUserName.value = session['name'];
    currentUserToken.value = session['token']?.toString();
    if (session['permissions'] != null) {
      currentUserPermissions.value = List<String>.from(session['permissions']);
    }
    if (session['assignedClasses'] != null) {
      currentUserAssignedClasses.value =
          List<String>.from(session['assignedClasses']);
    }
    currentTenantCode.value = session['tenantCode']?.toString();
    currentTenantId.value = session['tenantId'] is int
        ? session['tenantId']
        : int.tryParse(session['tenantId']?.toString() ?? '');
    currentTenantName.value = session['tenantName']?.toString();
    currentTenantMasjidName.value = session['masjidName']?.toString();
    if (session['allowedModules'] != null) {
      currentTenantAllowedModules.value =
          List<String>.from(session['allowedModules']);
    } else {
      currentTenantAllowedModules.value = [];
    }
    currentTenantIsTrial.value = session['isTrial'] == true || session['isTrial']?.toString().toLowerCase() == 'true';
    currentTenantTrialDaysLeft.value = session['trialDaysLeft'] is int
        ? session['trialDaysLeft']
        : (int.tryParse(session['trialDaysLeft']?.toString() ?? '0') ?? 0);
    currentTenantPlanName.value = session['planName']?.toString() ?? 'بنیادی پیکج (Basic)';
    currentTenantPlanCode.value = session['planCode']?.toString() ?? 'basic';
    currentTenantSubscriptionEndsAt.value = session['subscriptionEndsAt']?.toString() ?? '';
    currentTenantSubscriptionStatus.value = session['subscriptionStatus']?.toString() ?? (currentTenantIsTrial.value ? 'trial' : 'active');
    currentTenantSystemType.value = session['systemType']?.toString() ?? 'Both';
    currentTenantMaxStudents.value = int.tryParse(session['maxStudents']?.toString() ?? '20') ?? 20;
    currentTenantMaxClasses.value = int.tryParse(session['maxClasses']?.toString() ?? '1') ?? 1;
    currentTenantMaxTeachers.value = int.tryParse(session['maxTeachers']?.toString() ?? '2') ?? 2;
    initialScreen = const DashboardPage();
  } else {
    currentUserRole.value = null;
    currentUserId.value = null;
    currentUserName.value = null;
    currentUserToken.value = null;
    currentUserPermissions.value = [];
    currentUserAssignedClasses.value = [];
    currentTenantAllowedModules.value = [];

    const storage = FlutterSecureStorage();
    final hasSeenOnboarding = await storage.read(key: 'has_seen_onboarding');
    if (hasSeenOnboarding != 'true') {
      initialScreen = const OnboardingScreen();
    } else {
      initialScreen = const HomePage();
    }
  }

  // Load institution branding from local database
  try {
    final s = await DBHelper().getSettings();
    if (s.isNotEmpty) {
      final mName = s['madrasa_name']?.toString().trim() ?? '';
      if (mName.contains('اشرف المدارس') || mName.toLowerCase().contains('ashraf')) {
        currentTenantName.value = 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)';
      } else if ((currentTenantName.value == null || currentTenantName.value!.isEmpty) && mName.isNotEmpty) {
        currentTenantName.value = mName;
      }
      final lPath = s['logo_path']?.toString().trim() ?? '';
      if (lPath.isNotEmpty && lPath != 'logo.png' && !lPath.contains('ashraf')) {
        currentTenantLogoPath.value = lPath;
      }
      if (s['masjid_name'] != null && s['masjid_name'].toString().trim().isNotEmpty) {
        currentTenantMasjidName.value = s['masjid_name'].toString().trim();
      }
      if (s['masjid_logo_path'] != null && s['masjid_logo_path'].toString().trim().isNotEmpty) {
        currentTenantMasjidLogoPath.value = s['masjid_logo_path'].toString().trim();
      }
    }
  } catch (_) {}

  runApp(MyApp(initialScreen: initialScreen));
}

class MyApp extends StatelessWidget {
  final Widget initialScreen;
  const MyApp({super.key, required this.initialScreen});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isDarkMode,
      builder: (context, isDark, _) {
        return ValueListenableBuilder<bool>(
          valueListenable: isUrduGlobal,
          builder: (context, isUrdu, child) {
            return MaterialApp(
              navigatorKey: rootNavigatorKey,
              scrollBehavior: const AppScrollBehavior(),
              debugShowCheckedModeBanner: false,
              title: 'MC-MMS (مائنڈکرافٹ مدرسہ مینیجمنٹ سسٹم)',
              themeMode: isDark ? ThemeMode.dark : ThemeMode.light,
              // 🌟 پوری ایپ کی ڈائریکشن (RTL/LTR)، کی بورڈ بیک شارٹ کٹس، اور ونڈوز فونٹ اسکیلنگ
              builder: (context, child) {
                final Widget app = Directionality(
                  textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
                  child: child!,
                );
                // Windows / Desktop Font Scaling for clear and comfortable reading
                final bool isDesktop = !kIsWeb &&
                    (Platform.isWindows || Platform.isLinux || Platform.isMacOS);
                final Widget scaledApp = isDesktop
                    ? MediaQuery(
                        data: MediaQuery.of(context).copyWith(
                          textScaler: const TextScaler.linear(1.15),
                        ),
                        child: app,
                      )
                    : app;

                // 🌟 کی بورڈ بیک بٹن بی ہیوئر صرف اور صرف ڈیسک ٹاپ (ونڈوز/لینکس/میک) پر لاگو کریں تاکہ موبائل کی بورڈ کا بیک سپیس/ڈیلیٹ بٹن متاثر نہ ہو
                if (isDesktop) {
                  return CallbackShortcuts(
                    bindings: <ShortcutActivator, VoidCallback>{
                      const SingleActivator(LogicalKeyboardKey.backspace): () {
                        final primaryFocusContext = FocusManager.instance.primaryFocus?.context;
                        final isEditing = primaryFocusContext != null &&
                            primaryFocusContext.findAncestorWidgetOfExactType<EditableText>() != null;
                        if (!isEditing && (rootNavigatorKey.currentState?.canPop() ?? false)) {
                          rootNavigatorKey.currentState?.maybePop();
                        }
                      },
                      const SingleActivator(LogicalKeyboardKey.escape): () {
                        if (rootNavigatorKey.currentState?.canPop() ?? false) {
                          rootNavigatorKey.currentState?.maybePop();
                        }
                      },
                      const SingleActivator(LogicalKeyboardKey.browserBack): () {
                        if (rootNavigatorKey.currentState?.canPop() ?? false) {
                          rootNavigatorKey.currentState?.maybePop();
                        }
                      },
                      const SingleActivator(LogicalKeyboardKey.arrowLeft, alt: true): () {
                        if (rootNavigatorKey.currentState?.canPop() ?? false) {
                          rootNavigatorKey.currentState?.maybePop();
                        }
                      },
                    },
                    child: Focus(
                      autofocus: true,
                      child: scaledApp,
                    ),
                  );
                }

                // موبائل پر سادہ اور براہ راست ریٹرن تاکہ کی بورڈ کے تمام کیز (بیک سپیس، ڈیلیٹ، ایڈٹنگ) 100 فیصد روانی سے کام کریں
                return scaledApp;
              },
              theme: ThemeData(
                brightness: Brightness.light,
                fontFamily: isUrdu
                    ? 'JameelNooriNastaleeq'
                    : GoogleFonts.poppins().fontFamily,
                textTheme: _buildTextTheme(isUrdu, false),
                primaryColor: AppColors.primary, // Classic Royal Emerald
                scaffoldBackgroundColor:
                    AppColors.backgroundLight, // Crisp Clean Light Background
                cardColor: AppColors.cardLight,
                scrollbarTheme: ScrollbarThemeData(
                  thumbVisibility: const WidgetStatePropertyAll(true),
                  trackVisibility: const WidgetStatePropertyAll(true),
                  interactive: true,
                  thickness: const WidgetStatePropertyAll(10.0),
                  radius: const Radius.circular(8),
                  thumbColor: WidgetStateProperty.resolveWith((states) {
                    if (states.contains(WidgetState.dragged) ||
                        states.contains(WidgetState.hovered)) {
                      return AppColors.primary;
                    }
                    return AppColors.primary.withValues(alpha: 0.65);
                  }),
                  trackColor: WidgetStatePropertyAll(Colors.black.withValues(alpha: 0.05)),
                  trackBorderColor: WidgetStatePropertyAll(Colors.black.withValues(alpha: 0.08)),
                ),
                appBarTheme: const AppBarTheme(
                  backgroundColor: AppColors.backgroundLight,
                  elevation: 0,
                  iconTheme: IconThemeData(color: AppColors.primary),
                ),
                colorScheme: const ColorScheme.light(
                  primary: AppColors.primary,
                  secondary: AppColors.secondary,
                  surface: AppColors.surfaceLight,
                ),
              ),
              darkTheme: ThemeData(
                brightness: Brightness.dark,
                fontFamily: isUrdu
                    ? 'JameelNooriNastaleeq'
                    : GoogleFonts.poppins().fontFamily,
                textTheme: _buildTextTheme(isUrdu, true),
                primaryColor: AppColors.cyberAqua, // Obsidian Cyber Aqua
                scaffoldBackgroundColor:
                    AppColors.backgroundDark, // Deep Obsidian Dark
                cardColor: AppColors.cardDark, // Obsidian Card Surface
                scrollbarTheme: ScrollbarThemeData(
                  thumbVisibility: const WidgetStatePropertyAll(true),
                  trackVisibility: const WidgetStatePropertyAll(true),
                  interactive: true,
                  thickness: const WidgetStatePropertyAll(10.0),
                  radius: const Radius.circular(8),
                  thumbColor: WidgetStateProperty.resolveWith((states) {
                    if (states.contains(WidgetState.dragged) ||
                        states.contains(WidgetState.hovered)) {
                      return AppColors.cyberAqua;
                    }
                    return AppColors.cyberAqua.withValues(alpha: 0.65);
                  }),
                  trackColor: WidgetStatePropertyAll(Colors.white.withValues(alpha: 0.05)),
                  trackBorderColor: WidgetStatePropertyAll(Colors.white.withValues(alpha: 0.08)),
                ),
                appBarTheme: const AppBarTheme(
                  backgroundColor: AppColors.appBarDark,
                  elevation: 0,
                  iconTheme: IconThemeData(color: AppColors.cyberAqua),
                ),
                colorScheme: const ColorScheme.dark(
                  primary: AppColors.cyberAqua,
                  secondary: AppColors.accentRose,
                  surface: AppColors.cardDark,
                ),
              ),
              home: initialScreen,
            );
          },
        );
      },
    );
  }

  TextTheme _buildTextTheme(bool isUrdu, bool isDark) {
    TextTheme baseTheme =
        isDark ? ThemeData.dark().textTheme : ThemeData.light().textTheme;
    final bool isDesktop = !kIsWeb &&
        (Platform.isWindows || Platform.isLinux || Platform.isMacOS);
    final double desktopFactor = isDesktop ? 1.10 : 1.0;

    if (isUrdu) {
      const double urduHeight = 1.8;

      TextStyle? safeStyle(TextStyle? style) {
        if (style == null) return null;
        final double? scaled =
            style.fontSize != null ? style.fontSize! * 1.3 * desktopFactor : null;
        return style.copyWith(
          fontFamily: 'JameelNooriNastaleeq',
          height: urduHeight,
          fontSize: scaled,
          color: isDark ? Colors.white : null,
        );
      }

      return baseTheme.copyWith(
        displayLarge: safeStyle(baseTheme.displayLarge),
        displayMedium: safeStyle(baseTheme.displayMedium),
        displaySmall: safeStyle(baseTheme.displaySmall),
        headlineLarge: safeStyle(baseTheme.headlineLarge),
        headlineMedium: safeStyle(baseTheme.headlineMedium),
        headlineSmall: safeStyle(baseTheme.headlineSmall),
        titleLarge: safeStyle(baseTheme.titleLarge),
        titleMedium: safeStyle(baseTheme.titleMedium),
        titleSmall: safeStyle(baseTheme.titleSmall),
        bodyLarge: safeStyle(baseTheme.bodyLarge),
        bodyMedium: safeStyle(baseTheme.bodyMedium),
        bodySmall: safeStyle(baseTheme.bodySmall),
        labelLarge: safeStyle(baseTheme.labelLarge),
        labelMedium: safeStyle(baseTheme.labelMedium),
        labelSmall: safeStyle(baseTheme.labelSmall),
      );
    }
    if (isDesktop) {
      baseTheme = baseTheme.apply(fontSizeFactor: 1.10);
    }
    return GoogleFonts.poppinsTextTheme(baseTheme);
  }
}
