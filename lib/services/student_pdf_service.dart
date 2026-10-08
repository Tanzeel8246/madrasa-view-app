import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:screenshot/screenshot.dart';

class StudentPdfService {
  static final ScreenshotController _screenshotController = ScreenshotController();

  /// Prints an ID card from captured widget image
  static Future<void> printIdCard({
    required BuildContext context,
    required Widget idCardWidget,
    required String studentName,
    required String studentId,
  }) async {
    final Uint8List imageBytes = await _screenshotController.captureFromWidget(
      Material(
        color: Colors.white,
        child: idCardWidget,
      ),
      targetSize: const Size(337.5, 212.5),
      pixelRatio: 3.0,
      delay: const Duration(milliseconds: 150),
      context: context,
    );

    final pdf = pw.Document();
    pdf.addPage(
      pw.Page(
        pageFormat: const PdfPageFormat(
          3.375 * PdfPageFormat.inch,
          2.125 * PdfPageFormat.inch,
          marginAll: 0,
        ),
        build: (pw.Context ctx) {
          return pw.FullPage(
            ignoreMargins: true,
            child: pw.Center(
              child: pw.Image(pw.MemoryImage(imageBytes), fit: pw.BoxFit.contain),
            ),
          );
        },
      ),
    );

    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name: 'ID_Card_${studentId.replaceAll(RegExp(r'[^a-zA-Z0-9.-]'), '')}.pdf',
    );
  }
}
