import 'package:flutter/material.dart';

class MadrasaCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry? margin;
  final double? elevation;
  final ShapeBorder? shape;
  final Clip? clipBehavior;
  final double padding;
  final double borderRadius;
  final bool highlightTop;
  final Color? highlightColor;
  final bool showWatermark;
  final Color? color;

  const MadrasaCard({
    super.key,
    required this.child,
    this.margin,
    this.elevation,
    this.shape,
    this.clipBehavior,
    this.padding = 16.0,
    this.borderRadius = 16.0,
    this.highlightTop = true,
    this.highlightColor,
    this.showWatermark = true,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final topColor = highlightColor ?? const Color(0xFFD4AF37); // Accent Gold by default
    return Container(
      margin: margin,
      decoration: BoxDecoration(
        color: color ?? Colors.white,
        borderRadius: BorderRadius.circular(borderRadius),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            offset: const Offset(0, 4),
            blurRadius: elevation ?? 12,
            spreadRadius: 1,
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(borderRadius),
        clipBehavior: clipBehavior ?? Clip.antiAlias,
        child: Container(
          decoration: BoxDecoration(
            border: highlightTop
                ? Border(
                    top: BorderSide(
                      color: topColor,
                      width: 4.0,
                    ),
                  )
                : null,
          ),
          child: Stack(
            children: [
              // Watermark Logo
              if (showWatermark)
                Positioned(
                  bottom: -20,
                  right: -20,
                  child: Opacity(
                    opacity: 0.05, // Very faint watermark
                    child: Image.asset(
                      'assets/logo.png',
                      width: 150,
                      height: 150,
                      fit: BoxFit.contain,
                    ),
                  ),
                ),
              // Content
              Padding(
                padding: EdgeInsets.all(padding),
                child: child,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
