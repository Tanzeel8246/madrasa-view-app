import 'package:flutter/material.dart';

class MadrasaButton extends StatelessWidget {
  final String text;
  final VoidCallback onPressed;
  final IconData? icon;
  final Color? backgroundColor;
  final Color? textColor;
  final double width;

  const MadrasaButton({
    super.key,
    required this.text,
    required this.onPressed,
    this.icon,
    this.backgroundColor,
    this.textColor,
    this.width = double.infinity,
  });

  @override
  Widget build(BuildContext context) {
    final bg = backgroundColor ?? const Color(0xFF1B4332); // Primary Forest Green
    final txtColor = textColor ?? Colors.white;

    return SizedBox(
      width: width,
      height: 48,
      child: ElevatedButton(
        style: ElevatedButton.styleFrom(
          backgroundColor: bg,
          foregroundColor: txtColor,
          elevation: 2,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
        onPressed: onPressed,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (icon != null) ...[
              Icon(icon, size: 20),
              const SizedBox(width: 8),
            ],
            Text(
              text,
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class MadrasaTextField extends StatelessWidget {
  final TextEditingController? controller;
  final InputDecoration? decoration;
  final TextInputType? keyboardType;
  final bool readOnly;
  final VoidCallback? onTap;
  final int? maxLines;
  final TextAlign textAlign;
  final ValueChanged<String>? onChanged;
  final bool obscureText;
  final TextStyle? style;
  final bool autofocus;

  const MadrasaTextField({
    super.key,
    this.controller,
    this.decoration,
    this.keyboardType,
    this.readOnly = false,
    this.onTap,
    this.maxLines = 1,
    this.textAlign = TextAlign.start,
    this.onChanged,
    this.obscureText = false,
    this.style,
    this.autofocus = false,
  });

  @override
  Widget build(BuildContext context) {
    // Extract base decoration or create an empty one
    final baseDecoration = decoration ?? const InputDecoration();
    
    return TextField(
      controller: controller,
      keyboardType: keyboardType,
      readOnly: readOnly,
      onTap: onTap,
      maxLines: maxLines,
      textAlign: textAlign,
      onChanged: onChanged,
      obscureText: obscureText,
      style: style ?? const TextStyle(fontSize: 16),
      autofocus: autofocus,
      decoration: baseDecoration.copyWith(
        filled: true,
        fillColor: Colors.grey.shade100,
        labelStyle: TextStyle(
          color: Colors.grey.shade600,
          fontSize: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(
            color: Color(0xFF1B4332), // Primary Forest Green
            width: 1.5,
          ),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
    );
  }
}
