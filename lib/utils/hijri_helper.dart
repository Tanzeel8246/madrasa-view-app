class HijriDate {
  final int hYear;
  final int hMonth;
  final int hDay;

  const HijriDate(this.hYear, this.hMonth, this.hDay);

  static const List<String> monthNamesUrdu = [
    '',
    'محرم الحرام',
    'صفر المظفر',
    'ربیع الاول',
    'ربیع الثانی',
    'جمادی الاول',
    'جمادی الثانی',
    'رجب المرجب',
    'شعبان المعظم',
    'رمضان المبارک',
    'شوال المکرم',
    'ذوالقعدہ',
    'ذوالحجہ'
  ];

  static const List<String> monthNamesEnglish = [
    '',
    'Muharram',
    'Safar',
    'Rabi-ul-Awwal',
    'Rabi-us-Sani',
    'Jumada-al-Awwal',
    'Jumada-as-Sani',
    'Rajab',
    'Shaban',
    'Ramadan',
    'Shawwal',
    'Zul-Qadah',
    'Zul-Hijjah'
  ];

  String formatUrdu() {
    final mName =
        (hMonth >= 1 && hMonth <= 12) ? monthNamesUrdu[hMonth] : '$hMonth';
    return '$hDay $mName $hYear ھ';
  }

  String formatEnglish() {
    final mName = (hMonth >= 1 && hMonth <= 12)
        ? monthNamesEnglish[hMonth]
        : '$hMonth';
    return '$hDay $mName $hYear AH';
  }

  @override
  String toString() => formatUrdu();
}

class HijriHelper {
  /// Converts Gregorian DateTime to HijriDate
  static HijriDate fromGregorian(DateTime date) {
    int year = date.year;
    int month = date.month;
    int day = date.day;

    int m = month;
    int y = year;
    if (m <= 2) {
      y -= 1;
      m += 12;
    }

    int a = (y / 100).floor();
    int b = 2 - a + (a / 4).floor();

    int jd = (365.25 * (y + 4716)).floor() +
        (30.6001 * (m + 1)).floor() +
        day +
        b -
        1524;

    // Convert JD to Hijri
    int l = jd - 1948440 + 10632;
    int n = ((l - 1) / 10631).floor();
    l = l - 10631 * n + 354;
    int j = (((10985 - l) / 5316).floor()) * (((50 * l) / 17719).floor()) +
        ((l / 5670).floor()) * (((43 * l) / 15238).floor());
    l = l -
        (((30 - j) / 15).floor()) * (((17719 * j) / 50).floor()) -
        ((j / 16).floor()) * (((15238 * j) / 43).floor()) +
        29;
    int hMonth = ((24 * l) / 709).floor();
    int hDay = l - ((709 * hMonth) / 24).floor();
    int hYear = 30 * n + j - 30;

    return HijriDate(hYear, hMonth, hDay);
  }

  /// Converts String date 'yyyy-MM-dd' to Hijri formatted string
  static String toUrduHijri(String? dateStr) {
    if (dateStr == null || dateStr.trim().isEmpty) return '';
    try {
      final dt = DateTime.parse(dateStr.trim());
      return fromGregorian(dt).formatUrdu();
    } catch (_) {
      return '';
    }
  }

  /// Gets today's Hijri Date in Urdu
  static String todayUrdu() {
    return fromGregorian(DateTime.now()).formatUrdu();
  }
}
