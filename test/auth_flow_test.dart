import 'package:flutter_test/flutter_test.dart';

void main() {
  group('Auth Flow and Role Isolation Tests', () {
    test('parent student_ids mapping extracts valid integers only', () {
      final rawData = ['1', 2, '3', 'invalid', null, 0, -5, '10'];
      final ids = rawData
          .map((e) => int.tryParse(e?.toString() ?? '') ?? 0)
          .where((id) => id > 0)
          .toList();

      expect(ids, [1, 2, 3, 10]);
      expect(ids.contains(0), isFalse);
    });

    test('system type query filter returns accurate condition', () {
      String getSysCondition(String systemType) {
        return (systemType == 'Masjid')
            ? "system_type = 'Masjid'"
            : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      }

      expect(getSysCondition('Masjid'), "system_type = 'Masjid'");
      expect(getSysCondition('Madrasa'),
          "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')");
    });
  });
}
