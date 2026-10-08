import 'package:flutter/material.dart';

/// Centralized application color palette and brand design tokens.
/// 
/// Provides unified colors across Light mode, Dark mode (Obsidian),
/// and Masjid / Maktab mode.
class AppColors {
  AppColors._();

  // 🌿 Primary Brand Colors (Royal Emerald & Classic Islamic Theme)
  static const Color primary = Color(0xFF133E2F); // Royal Emerald
  static const Color primaryLight = Color(0xFF1B533F);
  static const Color primaryDark = Color(0xFF0D2B20);

  // 🌟 Secondary / Accent Colors (Metallic Gold)
  static const Color secondary = Color(0xFFB89230); // Metallic Gold
  static const Color gold = Color(0xFFB89230);
  static const Color goldLight = Color(0xFFD4AF37);
  static const Color accentRose = Color(0xFFFF6584);

  // 🌓 Light Mode Theme Surfaces
  static const Color backgroundLight = Color(0xFFF8F9FA); // Crisp Clean Light Background
  static const Color surfaceLight = Colors.white;
  static const Color cardLight = Colors.white;
  static const Color textPrimaryLight = Color(0xFF212529);
  static const Color textSecondaryLight = Color(0xFF6C757D);

  // 🌌 Dark Mode Theme Surfaces (Obsidian & Cyber Aqua)
  static const Color backgroundDark = Color(0xFF0D1117); // Deep Obsidian Dark
  static const Color surfaceDark = Color(0xFF161B26); // Obsidian Card Surface
  static const Color cardDark = Color(0xFF161B26);
  static const Color appBarDark = Color(0xFF10141D);
  static const Color cyberAqua = Color(0xFF00F2FE); // Obsidian Cyber Aqua
  static const Color textPrimaryDark = Color(0xFFF0F6FC);
  static const Color textSecondaryDark = Color(0xFF8B949E);

  // 🕌 Masjid Mode Brand Colors
  static const Color masjidPrimary = Color(0xFF1B4D3E);
  static const Color masjidSecondary = Color(0xFFD4AF37);

  // 🎨 Status Indicators
  static const Color success = Color(0xFF28A745);
  static const Color danger = Color(0xFFDC3545);
  static const Color warning = Color(0xFFFFC107);
  static const Color info = Color(0xFF17A2B8);
}
