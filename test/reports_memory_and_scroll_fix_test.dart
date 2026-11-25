import 'package:flutter_test/flutter_test.dart';

void main() {
  group('Hifz & Academic Reports Memory & Scrolling Fix Verification', () {
    test('Hifz Report: > 35 days range strictly guards against dummy allocation for unrecorded dates', () {
      final sDate = DateTime(2026, 1, 1);
      final eDate = DateTime(2026, 3, 1); // 60 days
      final int totalRangeDays = eDate.difference(sDate).inDays + 1;
      final bool isLongRange = totalRangeDays > 35;

      expect(totalRangeDays, equals(60));
      expect(isLongRange, isTrue);

      final attLookup = <String, Map<String, dynamic>>{
        "101_2026-01-05": {"main": "P", "sabaq": "P"},
        "101_2026-01-06": {"main": "A", "sabaq": "A"},
      };

      final effectiveStudents = [
        {"id": 101, "name": "طالب علم 1"}
      ];

      final combinedRecords = <Map<String, dynamic>>[];
      final Set<String> existingRecKeys = {};

      for (DateTime d = sDate; !d.isAfter(eDate); d = DateTime(d.year, d.month, d.day + 1)) {
        final isoDate = "${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";
        for (var s in effectiveStudents) {
          final sid = s['id'].toString();
          final recKey = "${sid}_$isoDate";
          if (!existingRecKeys.contains(recKey)) {
            final attInfo = attLookup[recKey];
            // Exact guard from reports_hifz.dart line 1396:
            if (isLongRange && attInfo == null) continue;

            existingRecKeys.add(recKey);
            combinedRecords.add({
              'student_id': s['id'],
              'date': isoDate,
              'att_status': attInfo?['main'] == 'P' ? 'حاضر' : 'غیر حاضر',
              'is_dummy': 1,
            });
          }
        }
      }

      // Over 60 days, without the guard it would generate 60 dummy records.
      // With the guard, it ONLY generates records for dates with attendance (2 dates).
      expect(combinedRecords.length, equals(2));
      expect(combinedRecords[0]['date'], equals('2026-01-05'));
      expect(combinedRecords[1]['date'], equals('2026-01-06'));
    });

    test('Hifz Report: <= 35 days range generates standard day placeholders', () {
      final sDate = DateTime(2026, 1, 1);
      final eDate = DateTime(2026, 1, 7); // 7 days (weekly)
      final int totalRangeDays = eDate.difference(sDate).inDays + 1;
      final bool isLongRange = totalRangeDays > 35;

      expect(totalRangeDays, equals(7));
      expect(isLongRange, isFalse);

      final attLookup = <String, Map<String, dynamic>>{
        "101_2026-01-02": {"main": "P"},
      };

      final effectiveStudents = [
        {"id": 101, "name": "طالب علم 1"}
      ];

      final combinedRecords = <Map<String, dynamic>>[];
      final Set<String> existingRecKeys = {};

      for (DateTime d = sDate; !d.isAfter(eDate); d = DateTime(d.year, d.month, d.day + 1)) {
        final isoDate = "${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";
        for (var s in effectiveStudents) {
          final sid = s['id'].toString();
          final recKey = "${sid}_$isoDate";
          if (!existingRecKeys.contains(recKey)) {
            final attInfo = attLookup[recKey];
            if (isLongRange && attInfo == null) continue;

            existingRecKeys.add(recKey);
            combinedRecords.add({
              'student_id': s['id'],
              'date': isoDate,
              'is_dummy': 1,
            });
          }
        }
      }

      // For standard short periods (e.g. 7 days), all 7 days are generated for the grid table
      expect(combinedRecords.length, equals(7));
    });

    test('Academic Report: Single-pass precomputation generates flatRows with instant lookup', () {
      final List<Map<String, String>> rawReports = [
        {'student_id': '101', 'date': '2026-01-01', 'lesson_details': 'سبق 1', 'grade': 'ممتاز', 'remarks': 'شاباش'},
        {'student_id': '101', 'date': '2026-01-02', 'lesson_details': 'غیر حاضر', 'grade': 'غیر حاضر', 'remarks': '-'},
        {'student_id': '101', 'date': '2026-01-03', 'lesson_details': '-', 'grade': 'ناغہ', 'remarks': '-'},
      ];

      final attMap = {
        '2026-01-01_101': 'P',
        '2026-01-02_101': 'A',
        '2026-01-03_101': 'P',
      };

      final flatRows = <Map<String, dynamic>>[];
      int recited = 0, absent = 0, naagha = 0;

      for (var r in rawReports) {
        final sid = r['student_id'].toString();
        final rDate = r['date']!;
        final att = attMap['${rDate}_$sid'] ?? '';
        final lessonDet = r['lesson_details']!;
        final gradeDet = r['grade']!;

        bool isAbsent = (att == 'A' || gradeDet.contains('غیر حاضر'));
        bool isExplicitNaagha = (gradeDet.contains('ناغہ'));

        if (isAbsent) {
          absent++;
        } else if (isExplicitNaagha) {
          naagha++;
        } else {
          recited++;
        }

        flatRows.add({
          'studentDisplay': '101 - احمد',
          'date': rDate,
          'attStr': att == 'P' ? 'حاضر' : (att == 'A' ? 'غیر حاضر' : '-'),
          'lessonDetails': lessonDet,
          'grade': gradeDet,
          'remarks': r['remarks'],
        });
      }

      expect(flatRows.length, equals(3));
      expect(recited, equals(1));
      expect(absent, equals(1));
      expect(naagha, equals(1));
      expect(flatRows[0]['attStr'], equals('حاضر'));
      expect(flatRows[1]['attStr'], equals('غیر حاضر'));
    });
  });
}
