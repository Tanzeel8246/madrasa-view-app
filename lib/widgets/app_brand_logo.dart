// ignore_for_file: library_private_types_in_public_api, deprecated_member_use
import 'dart:io';
import 'package:flutter/material.dart';

import '../config/app_colors.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../utils/system_type_global.dart';

/// مرکزی اور متحرک لوگو ویجیٹ برائے SaaS مدرسہ و مسجد مینجمنٹ سسٹم
/// 1. اگر ادارے نے سیٹنگز میں اپنا کسٹم لوگو اپلوڈ کیا ہے تو وہ دکھاتا ہے (لوکل فائل یا سرور نیٹ ورک)
/// 2. اگر کسٹم لوگو نہ ہو تو بطور ڈیفالٹ "مائنڈ کرافٹس اے آئی (MC-MMS)" کا آفیشل برانڈ لوگو دکھاتا ہے
class AppBrandLogo extends StatelessWidget {
  final double size;
  final bool isCircle;
  final Color? defaultColor;
  final Color? backgroundColor;
  final bool showBadge;

  const AppBrandLogo({
    super.key,
    this.size = 48,
    this.isCircle = true,
    this.defaultColor,
    this.backgroundColor,
    this.showBadge = false,
  });

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<String>(
      valueListenable: SystemTypeGlobal.currentSystemType,
      builder: (context, sysType, _) {
        final isMasjid = sysType == 'Masjid';

        return ValueListenableBuilder<String?>(
          valueListenable: isMasjid ? currentTenantMasjidLogoPath : currentTenantLogoPath,
          builder: (context, logoPath, _) {
            // اگر مسجد موڈ میں الگ لوگو نہ ہو تو مین مدرسہ لوگو پر فال بیک کریں
            final effectiveLogoPath = (logoPath != null && logoPath.trim().isNotEmpty)
                ? logoPath.trim()
                : (currentTenantLogoPath.value != null && currentTenantLogoPath.value!.trim().isNotEmpty
                    ? currentTenantLogoPath.value!.trim()
                    : null);

            Widget logoContent;

            if (effectiveLogoPath != null && effectiveLogoPath.isNotEmpty) {
              final isLocalFile = !effectiveLogoPath.startsWith('http') && 
                  !effectiveLogoPath.startsWith('uploads/') && 
                  File(effectiveLogoPath).existsSync();

              if (isLocalFile) {
                logoContent = Image.file(
                  File(effectiveLogoPath),
                  width: size,
                  height: size,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => _buildMindcraftsBrandLogo(isMasjid),
                );
              } else {
                final String fullUrl;
                if (effectiveLogoPath.startsWith('http')) {
                  fullUrl = effectiveLogoPath;
                } else {
                  final base = NetworkService.activeBaseUrl ?? 'https://madrasa.mamfss.com/';
                  final cleanBase = base.endsWith('/') ? base : '$base/';
                  final cleanPath = effectiveLogoPath.startsWith('/') ? effectiveLogoPath.substring(1) : effectiveLogoPath;
                  fullUrl = '$cleanBase$cleanPath';
                }

                logoContent = Image.network(
                  fullUrl,
                  width: size,
                  height: size,
                  fit: BoxFit.contain,
                  loadingBuilder: (context, child, loadingProgress) {
                    if (loadingProgress == null) return child;
                    return SizedBox(
                      width: size,
                      height: size,
                      child: Center(
                        child: SizedBox(
                          width: size * 0.4,
                          height: size * 0.4,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            value: loadingProgress.expectedTotalBytes != null
                                ? loadingProgress.cumulativeBytesLoaded / loadingProgress.expectedTotalBytes!
                                : null,
                          ),
                        ),
                      ),
                    );
                  },
                  errorBuilder: (_, __, ___) => _buildMindcraftsBrandLogo(isMasjid),
                );
              }
            } else {
              // کوئی کسٹم لوگو نہیں، ڈیفالٹ مائنڈ کرافٹس اے آئی ساس برانڈ لوگو
              logoContent = _buildMindcraftsBrandLogo(isMasjid);
            }

            if (isCircle) {
              return Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: backgroundColor ?? Colors.white.withValues(alpha: 0.15),
                ),
                child: ClipOval(child: logoContent),
              );
            }

            return Container(
              width: size,
              height: size,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(size * 0.18),
                color: backgroundColor,
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(size * 0.18),
                child: logoContent,
              ),
            );
          },
        );
      },
    );
  }

  /// مائنڈ کرافٹس اے آئی کا جدید و باوقار SaaS برانڈ لوگو
  Widget _buildMindcraftsBrandLogo(bool isMasjid) {
    final primaryColor = defaultColor ?? const Color(0xFF284D3D);
    final accentColor = const Color(0xFFD97706);

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: [
            primaryColor,
            primaryColor.withValues(alpha: 0.85),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: [
          BoxShadow(
            color: primaryColor.withValues(alpha: 0.25),
            blurRadius: size * 0.15,
            offset: Offset(0, size * 0.05),
          )
        ],
      ),
      child: Center(
        child: Stack(
          alignment: Alignment.center,
          children: [
            // بیرونی گول آرائشی بارڈر
            Container(
              width: size * 0.88,
              height: size * 0.88,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(
                  color: accentColor.withValues(alpha: 0.7),
                  width: size * 0.035,
                ),
              ),
            ),
            // مرکزی آئکن (مسجد یا مدرسہ و کتاب مع AI نبض)
            Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  isMasjid ? Icons.mosque : Icons.menu_book_rounded,
                  size: size * 0.42,
                  color: Colors.white,
                ),
                const SizedBox(height: 1),
                Text(
                  'MC-MMS',
                  style: TextStyle(
                    fontSize: (size * 0.15).clamp(7.0, 14.0),
                    fontWeight: FontWeight.w900,
                    letterSpacing: 0.6,
                    color: const Color(0xFFFEF3C7),
                    fontFamily: 'Roboto',
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
