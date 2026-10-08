import 'package:flutter/foundation.dart';

class SystemTypeGlobal {
  // Default system is 'Madrasa'. This toggles to 'Masjid'.
  static final ValueNotifier<String> currentSystemType = ValueNotifier<String>('Madrasa');
}
