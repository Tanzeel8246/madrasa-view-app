// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../screens/dashboard.dart';
import '../utils/globals.dart';

/// یہ Widget ہر صفحے کے لیے استعمال ہو گا
/// اس میں Sync بٹن، Menu بٹن، اور Back بٹن کا صحیح رویہ شامل ہے
class MadrasaScaffold extends StatelessWidget {
  final String title;
  final Widget body;
  final Widget drawer;
  final VoidCallback? onSync;
  final List<Widget>? actions;
  final bool showSyncButton;
  final Color? appBarColor;
  final Color? appBarTextColor;
  final Widget? floatingActionButton;
  final PreferredSizeWidget? bottom;

  const MadrasaScaffold({
    super.key,
    required this.title,
    required this.body,
    required this.drawer,
    this.onSync,
    this.actions,
    this.showSyncButton = true,
    this.appBarColor,
    this.appBarTextColor,
    this.floatingActionButton,
    this.bottom,
  });

  @override
  Widget build(BuildContext context) {
    final Color barColor = appBarColor ?? const Color(0xFF284D3D);
    final Color textColor = appBarTextColor ?? Colors.white;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;

        // اگر پچھلی اسکرین موجود ہے تو پچھلی اسکرین پر واپس جائیں
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
          return;
        }

        // ڈیش بورڈ پر ہیں تو ایپ بند کرنے کی پرمیشن مانگیں
        final currentRoute = ModalRoute.of(context)?.settings.name;
        if (currentRoute == '/dashboard' || context.widget is DashboardPage) {
          final shouldExit = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: Text(isUrduGlobal.value ? 'ایپ بند کریں؟' : 'Exit App?'),
              content: Text(isUrduGlobal.value
                  ? 'کیا آپ واقعی ایپ بند کرنا چاہتے ہیں؟'
                  : 'Are you sure you want to exit?'),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(false),
                  child: Text(isUrduGlobal.value ? 'نہیں' : 'No'),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: Text(
                      isUrduGlobal.value ? 'ہاں، بند کریں' : 'Yes, Exit',
                      style: const TextStyle(color: Colors.white)),
                ),
              ],
            ),
          );
          if (shouldExit == true) {
            SystemNavigator.pop();
          }
        } else {
          // اگر پچھلی اسکرین نہیں اور ڈیش بورڈ پر بھی نہیں تو ڈیش بورڈ پر جائیں
          Navigator.pushReplacement(
            context,
            MaterialPageRoute(builder: (_) => const DashboardPage()),
          );
        }
      },
      child: Scaffold(
        backgroundColor: const Color(0xFFF1EFE7),
        appBar: AppBar(
          backgroundColor: barColor,
          elevation: 0,
          centerTitle: true,
          title: Text(
            title,
            style: TextStyle(
              color: textColor,
              fontWeight: FontWeight.bold,
              fontSize: 18,
            ),
          ),
          iconTheme: IconThemeData(color: textColor),
          // Sync بٹن بائیں طرف (leading میں)
          leading: showSyncButton && onSync != null
              ? IconButton(
                  icon: Icon(Icons.sync, color: textColor),
                  tooltip:
                      isUrduGlobal.value ? 'ڈیٹا ہم آہنگ کریں' : 'Sync Data',
                  onPressed: onSync,
                )
              : null,
          // دائیں طرف Menu ہمیشہ رہے گا (drawer کا)
          actions: actions,
          bottom: bottom,
        ),
        drawer: drawer,
        floatingActionButton: floatingActionButton,
        body: body,
      ),
    );
  }
}
