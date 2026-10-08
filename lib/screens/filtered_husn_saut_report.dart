// ignore_for_file: library_private_types_in_public_api
import 'package:flutter/material.dart';
import 'dart:ui' as ui;
import '../widgets/madrasa_card.dart';
import '../utils/globals.dart';

class FilteredHusnSautReportPage extends StatelessWidget {
  final List<Map<String, dynamic>> records;
  final String title;

  const FilteredHusnSautReportPage({super.key, required this.records, required this.title});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
          child: Scaffold(
            backgroundColor: const Color(0xFFF1EFE7),
            appBar: AppBar(
              title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
              backgroundColor: const Color(0xFF6A1B9A),
              foregroundColor: Colors.white,
              centerTitle: true,
            ),
            body: records.isEmpty
                ? Center(child: Text(isUrdu ? 'کوئی ریکارڈ نہیں ملا' : 'No records found'))
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: records.length,
                    itemBuilder: (context, index) {
                      final r = records[index];
                      return MadrasaCard(
                        margin: const EdgeInsets.only(bottom: 10),
                        child: ListTile(
                          title: Text("${r['student_name'] ?? 'نامعلوم'} - ${r['date']}"),
                          subtitle: Text(
                            "منزل: ${r['manzil_sabaq'] ?? '-'} (${r['manzil_grade'] ?? '-'}) | " "مشق 1: ${r['mashq_1_grade'] ?? '-'}"
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
}
