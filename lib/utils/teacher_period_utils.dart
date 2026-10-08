import 'dart:convert';

class TeacherPeriodAssignment {
  const TeacherPeriodAssignment({
    required this.teacherId,
    required this.teacherName,
    required this.classId,
    required this.className,
    required this.periodNo,
    required this.periodKey,
    required this.periodLabelUr,
    required this.periodLabelEn,
  });

  final int teacherId;
  final String teacherName;
  final int? classId;
  final String className;
  final int periodNo;
  final String periodKey;
  final String periodLabelUr;
  final String periodLabelEn;
}

String normalizeTeacherPeriodKey(String key) {
  final clean = key.trim();
  const legacyHifz = {
    'hifz_sabaq': 'hifz_morning',
    'hifz_sabqi': 'hifz_after_breakfast',
    'hifz_manzil': 'hifz_noon',
    'hifz_review': 'hifz_evening',
  };
  return legacyHifz[clean] ?? clean;
}

Set<String> splitTeacherValues(dynamic value) {
  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) return {};
  try {
    final decoded = jsonDecode(raw);
    if (decoded is List) {
      return decoded
          .map((item) => item.toString().trim())
          .where((item) => item.isNotEmpty)
          .toSet();
    }
  } catch (_) {}
  return raw
      .split(',')
      .map((item) => item.trim())
      .where((item) => item.isNotEmpty)
      .toSet();
}

Set<int> decodeTeacherIdSet(dynamic value) {
  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) return {};
  Iterable<dynamic> items;
  try {
    final decoded = jsonDecode(raw);
    items = decoded is List ? decoded : raw.split(',');
  } catch (_) {
    items = raw.split(',');
  }
  return items
      .map((item) => int.tryParse(item.toString().trim()) ?? 0)
      .where((id) => id > 0)
      .toSet();
}

bool _classTextContains(Map<String, dynamic> cls, List<String> needles) {
  final text = [cls['name'], cls['class_type'], cls['section']]
      .where((v) => v != null)
      .join(' ')
      .toLowerCase();
  return needles.any((needle) => text.contains(needle.toLowerCase()));
}

bool isActiveStatusValue(dynamic value) {
  final status = (value ?? '').toString().trim().toLowerCase();
  if (status.isEmpty) return true;
  if (status.contains('deleted') ||
      status.contains('inactive') ||
      status.contains('\u062d\u0630\u0641') ||
      status.contains('\u063a\u06cc\u0631 \u0641\u0639\u0627\u0644')) {
    return false;
  }
  return true;
}

bool isHusnSautClass(Map<String, dynamic> cls) {
  final ct = (cls['class_type'] ?? '').toString().toLowerCase();
  if (ct.contains('hifz') ||
      ct.contains('gardan') ||
      ct.contains('حفظ') ||
      ct.contains('گردان')) {
    return false;
  }
  return _classTextContains(cls, [
    'حسن',
    'صوت',
    'husn',
  ]);
}

bool isHifzClass(Map<String, dynamic> cls) {
  // اگر explicitly Hifz/Gardan class_type ہو تو ہمیشہ Hifz مانیں
  final ct = (cls['class_type'] ?? '').toString().toLowerCase();
  if (ct.contains('hifz') ||
      ct.contains('gardan') ||
      ct.contains('حفظ') ||
      ct.contains('گردان')) {
    return true;
  }
  // ورنہ پرانا لوجک (HusnSaut کو خارج کریں)
  return !isHusnSautClass(cls) &&
      _classTextContains(cls, [
        'حفظ',
        'گردان',
        'hifz',
        'gardan',
      ]);
}

bool isNaziraClass(Map<String, dynamic> cls) => _classTextContains(cls, [
      'ناظرہ',
      'nazira',
    ]);

bool isQaidaClass(Map<String, dynamic> cls) => _classTextContains(cls, [
      'قاعدہ',
      'qaida',
    ]);

List<String> defaultPeriodKeysForClass(Map<String, dynamic> cls) {
  if (isNaziraClass(cls) || isQaidaClass(cls)) {
    return const ['1', '2'];
  }
  if (isHifzClass(cls) || isHusnSautClass(cls)) {
    return const ['1', '2', '3', '4'];
  }
  return const ['1', '2', '3', '4', '5', '6', '7', '8'];
}

Map<String, Set<String>> decodeTeacherAssignedPeriods(
  dynamic value,
  Set<String> selectedClasses,
) {
  final raw = value?.toString().trim() ?? '';
  final result = <String, Set<String>>{};
  if (raw.isNotEmpty) {
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        decoded.forEach((key, val) {
          if (val is List) {
            result[key.toString()] = val
                .map((e) => normalizeTeacherPeriodKey(e.toString()))
                .where((e) => e.isNotEmpty)
                .toSet();
          } else if (val != null) {
            result[key.toString()] = val
                .toString()
                .split(',')
                .map((e) => normalizeTeacherPeriodKey(e.trim()))
                .where((e) => e.isNotEmpty)
                .toSet();
          }
        });
        return result;
      }
    } catch (_) {}

    final legacy = splitTeacherValues(raw)
        .map(normalizeTeacherPeriodKey)
        .where((e) => e.isNotEmpty)
        .toSet();
    for (final className in selectedClasses) {
      result[className] = {...legacy};
    }
  }
  return result;
}

int periodNoFromKey(String key) {
  switch (normalizeTeacherPeriodKey(key)) {
    case 'hifz_morning':
    case 'nazira_morning':
      return 1;
    case 'hifz_after_breakfast':
    case 'nazira_evening':
      return 2;
    case 'hifz_noon':
      return 3;
    case 'hifz_evening':
      return 4;
    default:
      return int.tryParse(key) ?? 0;
  }
}

String periodKeyFromNoForClass(Map<String, dynamic> cls, int periodNo) {
  if (isHifzClass(cls)) {
    return const {
          1: 'hifz_morning',
          2: 'hifz_after_breakfast',
          3: 'hifz_noon',
          4: 'hifz_evening',
        }[periodNo] ??
        periodNo.toString();
  }
  if (isNaziraClass(cls)) {
    return const {1: 'nazira_morning', 2: 'nazira_evening'}[periodNo] ??
        periodNo.toString();
  }
  return periodNo.toString();
}

String periodKeyForClass(Map<String, dynamic> cls, String key) {
  final normalized = normalizeTeacherPeriodKey(key);
  final no = periodNoFromKey(normalized);
  if (no <= 0) return normalized;
  if (isHifzClass(cls) || isNaziraClass(cls)) {
    return periodKeyFromNoForClass(cls, no);
  }
  return normalized;
}

String teacherPeriodLabel(String key, bool isUrdu) {
  switch (normalizeTeacherPeriodKey(key)) {
    case 'hifz_morning':
    case 'nazira_morning':
      return isUrdu ? '\u0635\u0628\u062d' : 'Morning';
    case 'hifz_after_breakfast':
      return isUrdu
          ? '\u0646\u0627\u0634\u062a\u06c1 \u06a9\u06d2 \u0628\u0639\u062f'
          : 'After Breakfast';
    case 'hifz_noon':
      return isUrdu
          ? '\u062f\u0648\u067e\u06c1\u0631 (\u0638\u06c1\u0631 \u06a9\u06d2 \u0628\u0639\u062f)'
          : 'Noon (After Zuhr)';
    case 'hifz_evening':
      return isUrdu
          ? '\u0634\u0627\u0645 (\u0645\u063a\u0631\u0628 \u06a9\u06d2 \u0628\u0639\u062f)'
          : 'Evening (After Maghrib)';
    case 'nazira_evening':
      return isUrdu ? '\u0634\u0627\u0645' : 'Evening';
    default:
      final no = int.tryParse(key);
      if (no != null) {
        return isUrdu ? '\u067e\u06cc\u0631\u06cc\u0688 $no' : 'Period $no';
      }
      return key;
  }
}

List<TeacherPeriodAssignment> buildTeacherPeriodAssignments({
  required List<Map<String, dynamic>> teachers,
  required List<Map<String, dynamic>> classes,
  String? className,
  int? periodNo,
}) {
  final activeClasses = classes.where((cls) {
    final name = cls['name']?.toString() ?? '';
    final deleted = int.tryParse(cls['is_deleted']?.toString() ?? '0') ?? 0;
    if (deleted != 0) return false;
    if (!isActiveStatusValue(cls['status'])) return false;
    return className == null || name == className;
  }).toList();

  final classByName = <String, Map<String, dynamic>>{};
  final classNameById = <String, String>{};
  final classNamesByTeacherId = <int, Set<String>>{};
  for (final cls in activeClasses) {
    final name = cls['name']?.toString() ?? '';
    if (name.isEmpty) continue;
    classByName[name] = cls;
    final idText = cls['id']?.toString() ?? '';
    if (idText.isNotEmpty) classNameById[idText] = name;
    for (final teacherId in decodeTeacherIdSet(cls['main_teacher_ids'])) {
      classNamesByTeacherId.putIfAbsent(teacherId, () => {}).add(name);
    }
  }

  final rows = <TeacherPeriodAssignment>[];
  final seen = <String>{};
  for (final teacher in teachers) {
    final deleted = int.tryParse(teacher['is_deleted']?.toString() ?? '0') ?? 0;
    if (deleted != 0 || !isActiveStatusValue(teacher['status'])) continue;

    final teacherId = int.tryParse(teacher['id']?.toString() ?? '') ?? 0;
    if (teacherId == 0) continue;
    final teacherName = teacher['name']?.toString() ?? '';
    final selectedClasses = <String>{};
    for (final value in splitTeacherValues(teacher['assigned_classes'])) {
      selectedClasses.add(classNameById[value] ?? value);
    }
    selectedClasses.addAll(classNamesByTeacherId[teacherId] ?? {});
    final assignedPeriods = decodeTeacherAssignedPeriods(
      teacher['assigned_periods'],
      selectedClasses,
    );

    for (final assignedClassName in selectedClasses) {
      final cls = classByName[assignedClassName];
      if (cls == null) continue;
      final clsIdKey = cls['id']?.toString() ?? '';
      final classPeriods = assignedPeriods[assignedClassName] ??
          (clsIdKey.isNotEmpty ? assignedPeriods[clsIdKey] : null);
      final keys = classPeriods?.isNotEmpty == true
          ? classPeriods!
              .map((key) => periodKeyForClass(cls, key))
              .toSet()
              .toList()
          : defaultPeriodKeysForClass(cls).toList();
      keys.sort((a, b) => periodNoFromKey(a).compareTo(periodNoFromKey(b)));
      for (final key in keys) {
        final pNo = periodNoFromKey(key);
        if (pNo <= 0) continue;
        if (periodNo != null && pNo != periodNo) continue;
        final clsId = int.tryParse(cls['id']?.toString() ?? '');
        final marker = '$teacherId|$assignedClassName|$pNo';
        if (!seen.add(marker)) continue;
        rows.add(TeacherPeriodAssignment(
          teacherId: teacherId,
          teacherName: teacherName,
          classId: clsId,
          className: assignedClassName,
          periodNo: pNo,
          periodKey: key,
          periodLabelUr: teacherPeriodLabel(key, true),
          periodLabelEn: teacherPeriodLabel(key, false),
        ));
      }
    }
  }

  rows.sort((a, b) {
    final classCompare = a.className.compareTo(b.className);
    if (classCompare != 0) return classCompare;
    final periodCompare = a.periodNo.compareTo(b.periodNo);
    if (periodCompare != 0) return periodCompare;
    return a.teacherName.compareTo(b.teacherName);
  });
  return rows;
}
