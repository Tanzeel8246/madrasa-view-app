import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:madrasa_app/utils/globals.dart';
import 'package:madrasa_app/utils/network_service.dart';
import 'package:madrasa_app/screens/dashboard.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Security Fix 3: NetworkService Token Query Removal', () {
    test('buildActionUri never appends user_token in query string', () {
      currentUserToken.value = 'test_secret_jwt_token_12345';
      currentTenantCode.value = 'TEST01';

      final uri = NetworkService.buildActionUri(
        'https://madrasa.mamfss.com/',
        'sync_down',
      );

      final uriStr = uri.toString();
      expect(uriStr.contains('user_token='), isFalse,
          reason: 'user_token must never appear in URL query string');
      expect(uriStr.contains('tenant_code=TEST01'), isTrue);
      expect(uri.queryParameters.containsKey('user_token'), isFalse);
    });
  });

  group('Security Fix 2: Dashboard Backup Icon Role Gating', () {
    testWidgets('Backup icon is ABSENT for teacher role', (tester) async {
      currentUserRole.value = 'teacher';
      currentUserPermissions.value = ['manage_attendance'];

      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: DashboardPage(autoSync: false),
          ),
        ),
      );
      await tester.pump();

      // Check if backup icon exists
      final backupFinder = find.byIcon(Icons.backup_rounded);
      expect(backupFinder, findsNothing,
          reason: 'Teacher role must not see database backup icon');
    });

    testWidgets('Backup icon is PRESENT for admin role', (tester) async {
      currentUserRole.value = 'admin';
      currentUserPermissions.value = ['all'];

      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: DashboardPage(autoSync: false),
          ),
        ),
      );
      await tester.pump();

      // Check if backup icon exists
      final backupFinder = find.byIcon(Icons.backup_rounded);
      expect(backupFinder, findsOneWidget,
          reason: 'Admin role must see database backup icon');
    });
  });
}
