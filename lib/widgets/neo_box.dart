// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';

class NeoBox extends StatelessWidget {
  final Widget child;
  final double padding;
  final double borderRadius;
  final Color? color;

  const NeoBox({
    super.key,
    required this.child,
    this.padding = 16.0,
    this.borderRadius = 15.0,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final boxColor = color ?? Theme.of(context).cardColor;

    return Container(
      padding: EdgeInsets.all(padding),
      decoration: BoxDecoration(
        color: boxColor,
        borderRadius: BorderRadius.circular(borderRadius),
        border: isDark ? Border.all(color: Colors.white.withValues(alpha: 0.08), width: 1) : null,
        boxShadow: [
          BoxShadow(
            color: isDark ? Colors.black.withValues(alpha: 0.3) : Colors.black.withValues(alpha: 0.04),
            offset: const Offset(0, 4),
            blurRadius: 10,
          ),
        ],
      ),
      child: child,
    );
  }
}
