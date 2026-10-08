import 'package:flutter_test/flutter_test.dart';
import 'package:madrasa_app/services/app_state_service.dart';
import 'package:madrasa_app/utils/globals.dart';
import 'package:madrasa_app/utils/system_type_global.dart';

void main() {
  group('AppStateService Tests', () {
    final appState = AppStateService.instance;

    test('Language changes sync with isUrduGlobal', () {
      appState.setUrdu(false);
      expect(appState.isUrdu, false);
      expect(isUrduGlobal.value, false);

      appState.toggleLanguage();
      expect(appState.isUrdu, true);
      expect(isUrduGlobal.value, true);
    });

    test('SystemType changes sync with SystemTypeGlobal and getters', () {
      appState.setSystemType('Masjid');
      expect(appState.isMasjid, true);
      expect(appState.isMadrasa, false);
      expect(SystemTypeGlobal.currentSystemType.value, 'Masjid');

      appState.setSystemType('Madrasa');
      expect(appState.isMadrasa, true);
      expect(appState.isMasjid, false);
      expect(SystemTypeGlobal.currentSystemType.value, 'Madrasa');
    });

    test('Session management sets role and login status correctly', () {
      appState.setUserSession(
        role: 'teacher',
        userId: 42,
        name: 'قاری احمد',
        token: 'test_token_123',
        permissions: ['mark_attendance'],
        assignedClasses: ['حفظ کلاس اول'],
      );

      expect(appState.isLoggedIn, true);
      expect(appState.isTeacher, true);
      expect(appState.isAdmin, false);
      expect(appState.userId, 42);
      expect(appState.userName, 'قاری احمد');
      expect(appState.assignedClasses.contains('حفظ کلاس اول'), true);
    });
  });
}
