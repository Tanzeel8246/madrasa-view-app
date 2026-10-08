// ignore_for_file: deprecated_member_use
import 'dart:math';

class HifzProjectionResult {
  final String edition;
  final int linesPerPara;
  final int totalLines;
  final String sequenceCode;
  final String sequenceTitle;
  final int currentPara;
  final int completedParas;
  final int completedLines;
  final int remainingLines;
  final double progressPerc;
  final double avgDailyLines;
  final int sabaqDaysCount;
  final int netEducationalDays;
  final int skippedFridays;
  final int skippedVacationDays;
  final int totalCalendarDays;
  final DateTime expectedDate;
  final String durationText;
  final bool isCompleted;

  HifzProjectionResult({
    required this.edition,
    required this.linesPerPara,
    required this.totalLines,
    required this.sequenceCode,
    required this.sequenceTitle,
    required this.currentPara,
    required this.completedParas,
    required this.completedLines,
    required this.remainingLines,
    required this.progressPerc,
    required this.avgDailyLines,
    required this.sabaqDaysCount,
    required this.netEducationalDays,
    required this.skippedFridays,
    required this.skippedVacationDays,
    required this.totalCalendarDays,
    required this.expectedDate,
    required this.durationText,
    required this.isCompleted,
  });

  String get expectedDateFormatted {
    const months = [
      '', 'جنوری', 'فروری', 'مارچ', 'اپریل', 'مئی', 'جون',
      'جولائی', 'اگست', 'ستمبر', 'اکتوبر', 'نومبر', 'دسمبر'
    ];
    const days = [
      '', 'پیر', 'منگل', 'بدھ', 'جمعرات', 'جمعہ', 'ہفتہ', 'اتوار'
    ];
    final d = expectedDate.day;
    final m = months[expectedDate.month];
    final y = expectedDate.year;
    final dayName = days[expectedDate.weekday];
    return '$d $m $y ($dayName)';
  }
}

class HifzCompletionCalculator {
  static HifzProjectionResult calculate({
    required Map<String, dynamic>? student,
    required List<Map<String, dynamic>> hifzRecords,
  }) {
    final edition = (student?['quran_edition']?.toString() ?? '16 سطری').trim();
    final seq = (student?['hifz_sequence']?.toString() ?? 'reverse').trim();

    // 1. لائنیں فی پارہ (16 سطری: 18 صفحات × 16 سطور = 288 لائنیں فی پارہ)
    int linesPerPara = 288;
    if (edition.contains('15')) {
      linesPerPara = 300;
    } else if (edition.contains('13')) {
      linesPerPara = 370;
    } else if (edition.contains('18')) {
      linesPerPara = 324;
    }
    final int totalLines = 30 * linesPerPara;

    // 2. طریقۂ حفظ (3 طریقے)
    final List<int> paraSequence = [];
    String seqTitle = '';
    if (seq == 'forward') {
      for (int i = 1; i <= 30; i++) {
        paraSequence.add(i);
      }
      seqTitle = 'پارہ 1 تا 30';
    } else if (seq == 'thirty_then_forward') {
      paraSequence.add(30);
      for (int i = 1; i <= 29; i++) {
        paraSequence.add(i);
      }
      seqTitle = 'پارہ عم پھر 1 تا 29';
    } else {
      for (int i = 30; i >= 1; i--) {
        paraSequence.add(i);
      }
      seqTitle = 'پارہ 30 تا 1';
    }

    // 3. طالب علم کا تازہ ترین نیا سبق اور اوسط رفتار
    int? latestPara;
    int latestLines = 0;
    int totalSabaqLines = 0;
    int sabaqDaysCount = 0;
    double avgDailyLines = 8.0;

    int parseLines(dynamic raw) {
      if (raw == null) return 0;
      final str = raw.toString().trim();
      final match = RegExp(r'(\d+)').firstMatch(str);
      if (match != null) {
        return int.tryParse(match.group(1)!) ?? 0;
      }
      return 0;
    }

    int? parseParah(dynamic raw) {
      if (raw == null) return null;
      final str = raw.toString().trim();
      if (str.isEmpty || str == '-' || str == 'نہیں سنایا') return null;
      final match = RegExp(r'\b([1-9]|[12][0-9]|30)\b').firstMatch(str);
      if (match != null) {
        return int.tryParse(match.group(1)!);
      }
      final anyNum = RegExp(r'(\d+)').firstMatch(str);
      if (anyNum != null) {
        final v = int.tryParse(anyNum.group(1)!);
        if (v != null && v >= 1 && v <= 30) return v;
      }
      if (str.contains('عم') || str.contains('النبأ') || str.contains('النباء')) return 30;
      if (str.contains('تبارک') || str.contains('الملک')) return 29;
      if (str.contains('قد سمع') || str.contains('المجادلہ')) return 28;
      if (str.contains('ذاریات')) return 27;
      if (str.contains('حم') || str.contains('الاحقاف')) return 26;
      if (str.contains('فمن اظلم') || str.contains('الزمر')) return 24;
      if (str.contains('وما لی') || str.contains('یس')) return 23;
      if (str.contains('ومن یقنت')) return 22;
      if (str.contains('اتل ما اوحی')) return 21;
      if (str.contains('امن خلق')) return 20;
      if (str.contains('وقال الذین')) return 19;
      if (str.contains('قد افلح')) return 18;
      if (str.contains('اقترب')) return 17;
      if (str.contains('قال الم') || str.contains('الکہف')) return 16;
      if (str.contains('سبحن')) return 15;
      if (str.contains('ربما')) return 14;
      if (str.contains('وما ابرئ')) return 13;
      if (str.contains('سیقول')) return 2;
      if (str.contains('الم')) return 1;
      return null;
    }

    // تاریخ کے تمام ریکارڈز کا تجزیہ
    for (final r in hifzRecords) {
      if (r['is_deleted'] == 1) continue;
      final parsedLines = parseLines(r['sabaq_lines']);
      final stRaw = (r['sabaq_status'] ?? r['sabaq_grade'] ?? '').toString().trim();
      final isMissed = stRaw.contains('ناغہ') || stRaw.contains('نہیں سنا') || stRaw.contains('غیر حاضر') || stRaw.contains('رخصت');

      if (parsedLines > 0 && !isMissed) {
        totalSabaqLines += parsedLines;
        sabaqDaysCount++;
      }

      final pRaw = r['sabaq_parah'] ?? r['sabaq_para'];
      final pNum = parseParah(pRaw);
      if (pNum != null && latestPara == null) {
        latestPara = pNum;
        latestLines = parsedLines;
      }
    }

    // اگر سبق میں پارہ نہیں ملا تو سبقی یا منزل سے دیکھیں
    if (latestPara == null) {
      for (final r in hifzRecords) {
        if (r['is_deleted'] == 1) continue;
        final omRaw = r['sabqi_parah'] ?? r['sabqi_para'] ?? r['manzil_parah'] ?? r['manzil_details'];
        final pNum = parseParah(omRaw);
        if (pNum != null) {
          latestPara = pNum;
          break;
        }
      }
    }

    latestPara ??= paraSequence.first;

    // طالب علم کی حقیقی یومیہ اوسط رفتار
    if (sabaqDaysCount > 0) {
      final double calculatedAvg = totalSabaqLines / sabaqDaysCount;
      if (calculatedAvg >= 0.5) {
        avgDailyLines = double.parse(calculatedAvg.toStringAsFixed(1));
      }
    }

    // ترتیب میں موجودہ پارے کی پوزیشن
    int paraIdx = paraSequence.indexOf(latestPara);
    if (paraIdx < 0) paraIdx = 0;
    final int completedParas = paraIdx;

    // موجودہ پارے میں سنائی گئی کل لائنیں
    int currentParaRecitedLines = 0;
    for (final r in hifzRecords) {
      if (r['is_deleted'] == 1) continue;
      final pRaw = r['sabaq_parah'] ?? r['sabaq_para'];
      final pNum = parseParah(pRaw);
      if (pNum == latestPara) {
        currentParaRecitedLines += parseLines(r['sabaq_lines']);
      }
    }

    if (currentParaRecitedLines <= 0 && latestLines > 0) {
      currentParaRecitedLines = latestLines;
    }
    currentParaRecitedLines = min(currentParaRecitedLines, linesPerPara - 1);

    final int completedLines = (completedParas * linesPerPara) + currentParaRecitedLines;
    final int remainingLines = max(0, totalLines - completedLines);
    final double progressPerc = min(100.0, double.parse(((completedLines / totalLines) * 100.0).toStringAsFixed(1)));
    final bool isCompleted = (remainingLines <= 0 || progressPerc >= 100.0);

    // 5. خالص درکار تدریسی ایام
    final int netEducationalDays = (avgDailyLines > 0)
        ? (remainingLines / avgDailyLines).ceil()
        : 0;

    // 6. پروجیکشن مع جمعہ، عیدین، امتحانات اور متوقع رخصتیں
    DateTime projectedDate = DateTime.now();
    int skippedFridays = 0;
    int skippedVacationDays = 0;
    int totalCalendarDays = 0;

    if (!isCompleted && netEducationalDays > 0) {
      int eduCounter = 0;
      while (eduCounter < netEducationalDays && totalCalendarDays < 5000) {
        projectedDate = projectedDate.add(const Duration(days: 1));
        totalCalendarDays++;
        if (projectedDate.weekday == DateTime.friday) {
          skippedFridays++;
          continue; // ہفتہ وار جمعہ کی چھٹی
        }
        // سالانہ عیدین، امتحانات اور متوقع رخصتوں کا متناسب بفر (ہر 6 تدریسی دنوں پر 1 چھٹی)
        if ((eduCounter + skippedVacationDays) % 6 == 5) {
          skippedVacationDays++;
          continue;
        }
        eduCounter++;
      }
    }

    int years = totalCalendarDays ~/ 365;
    final int remAfterY = totalCalendarDays % 365;
    int months = (remAfterY / 30).round();
    if (months >= 12) {
      years++;
      months = 0;
    }

    String durationText = '';
    if (years > 0) durationText += '$years سال ';
    if (months > 0) durationText += '$months ماہ';
    if (durationText.isEmpty) durationText = '$totalCalendarDays دن';
    durationText = durationText.trim();

    return HifzProjectionResult(
      edition: edition,
      linesPerPara: linesPerPara,
      totalLines: totalLines,
      sequenceCode: seq,
      sequenceTitle: seqTitle,
      currentPara: latestPara,
      completedParas: completedParas,
      completedLines: completedLines,
      remainingLines: remainingLines,
      progressPerc: progressPerc,
      avgDailyLines: avgDailyLines,
      sabaqDaysCount: sabaqDaysCount,
      netEducationalDays: netEducationalDays,
      skippedFridays: skippedFridays,
      skippedVacationDays: skippedVacationDays,
      totalCalendarDays: totalCalendarDays,
      expectedDate: projectedDate,
      durationText: durationText,
      isCompleted: isCompleted,
    );
  }
}
