// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../utils/network_service.dart';
import '../widgets/neo_box.dart';

/// سبسکرپشن کیفیت اور فیس ادائیگی کی اسکرین (Subscription Status & Billing)
/// موبائل اور ونڈوز ڈیسک ٹاپ دونوں پر مکمل ہم آہنگ
class SubscriptionStatusPage extends StatefulWidget {
  const SubscriptionStatusPage({super.key});

  @override
  State<SubscriptionStatusPage> createState() => _SubscriptionStatusPageState();
}

class _SubscriptionStatusPageState extends State<SubscriptionStatusPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  bool _isLoading = false;
  int _usedStudents = 0;
  int _usedClasses = 0;
  int _usedTeachers = 0;

  int _maxStudents = 20;
  int _maxClasses = 1;
  int _maxTeachers = 2;

  double _priceMonthly = 1000;
  double _priceYearly = 10000;

  @override
  void initState() {
    super.initState();
    _loadLocalStats();
    _fetchLiveSubscriptionData();
  }

  Future<void> _loadLocalStats() async {
    try {
      final db = DBHelper();
      final database = await db.database;

      final sysCond = SystemTypeGlobal.currentSystemType.value == 'Masjid'
          ? "system_type = 'Masjid'"
          : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
      final sRes = await database.rawQuery(
          "SELECT COUNT(*) as count FROM students WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL OR status = 'حاضر') AND $sysCond");
      final cRes = await database.rawQuery(
          "SELECT COUNT(*) as count FROM classes WHERE (status = 'Active' OR status = '' OR status IS NULL) AND $sysCond");
      final tRes = await database.rawQuery(
          "SELECT COUNT(*) as count FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL) AND $sysCond");

      if (mounted) {
        setState(() {
          _usedStudents = (sRes.first['count'] as num?)?.toInt() ?? 0;
          _usedClasses = (cRes.first['count'] as num?)?.toInt() ?? 0;
          _usedTeachers = (tRes.first['count'] as num?)?.toInt() ?? 0;
        });
      }
    } catch (_) {}
  }

  Future<void> _fetchLiveSubscriptionData() async {
    if (_isLoading) return;
    setState(() => _isLoading = true);

    try {
      final tId = currentTenantId.value ?? 1;
      final tCode = currentTenantCode.value ?? 'MAIN01';

      final res = await NetworkService.publicPostWithFallbackAction(
        'get_subscription_status',
        {'tenant_id': tId, 'tenant_code': tCode},
      );

      if (res.statusCode == 200 && res.body.trim().isNotEmpty) {
        final data = jsonDecode(res.body);
        if (data is Map<String, dynamic> && data['status'] == 'success') {
          if (mounted) {
            setState(() {
              if (data['is_trial'] != null) {
                currentTenantIsTrial.value = data['is_trial'] == true;
              }
              if (data['days_left'] != null) {
                currentTenantTrialDaysLeft.value =
                    int.tryParse(data['days_left'].toString()) ?? 0;
              }
              if (data['plan_name'] != null) {
                currentTenantPlanName.value = data['plan_name'].toString();
              }
              if (data['plan_code'] != null) {
                currentTenantPlanCode.value = data['plan_code'].toString();
              }
              if (data['subscription_ends_at'] != null) {
                currentTenantSubscriptionEndsAt.value =
                    data['subscription_ends_at'].toString();
              }

              _maxStudents =
                  int.tryParse(data['max_students']?.toString() ?? '20') ?? 20;
              _maxClasses =
                  int.tryParse(data['max_classes']?.toString() ?? '1') ?? 1;
              _maxTeachers =
                  int.tryParse(data['max_teachers']?.toString() ?? '2') ?? 2;

              currentTenantMaxStudents.value = _maxStudents;
              currentTenantMaxClasses.value = _maxClasses;
              currentTenantMaxTeachers.value = _maxTeachers;

              _priceMonthly = double.tryParse(
                      data['price_monthly']?.toString() ?? '1000') ??
                  1000;
              _priceYearly = double.tryParse(
                      data['price_yearly']?.toString() ?? '10000') ??
                  10000;

              if (data['used_students'] != null) {
                _usedStudents =
                    int.tryParse(data['used_students'].toString()) ?? _usedStudents;
              }
              if (data['used_classes'] != null) {
                _usedClasses =
                    int.tryParse(data['used_classes'].toString()) ?? _usedClasses;
              }
              if (data['used_teachers'] != null) {
                _usedTeachers =
                    int.tryParse(data['used_teachers'].toString()) ?? _usedTeachers;
              }
            });
          }
        }
      }
    } catch (_) {} finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _openWhatsApp() async {
    final tName = currentTenantName.value ?? 'ادارہ';
    final tCode = currentTenantCode.value ?? 'MAIN01';
    final plan = currentTenantPlanName.value;

    final msg = Uri.encodeComponent(
        'السلام علیکم! ہم اپنے ادارے [$tName] (کوڈ: $tCode) کا سبسکرپشن پلان [$plan] ری نیو / ایکٹیویٹ کروانا چاہتے ہیں۔');
    final uri = Uri.parse('https://wa.me/923012454595?text=$msg');

    try {
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final isTrial = currentTenantIsTrial.value;
    final daysLeft = currentTenantTrialDaysLeft.value;
    final planName = currentTenantPlanName.value;
    final endsAt = currentTenantSubscriptionEndsAt.value;

    final stdRatio =
        _maxStudents > 0 ? (_usedStudents / _maxStudents).clamp(0.0, 1.0) : 0.0;
    final clsRatio =
        _maxClasses > 0 ? (_usedClasses / _maxClasses).clamp(0.0, 1.0) : 0.0;
    final teaRatio =
        _maxTeachers > 0 ? (_usedTeachers / _maxTeachers).clamp(0.0, 1.0) : 0.0;

    return Scaffold(
      backgroundColor: bgColor,
      appBar: AppBar(
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        centerTitle: true,
        title: Text(
          isUrdu ? 'سبسکرپشن کیفیت و ادائیگی پورٹل' : 'Subscription & Status',
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
        ),
        actions: [
          IconButton(
            icon: _isLoading
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      color: Colors.white,
                      strokeWidth: 2,
                    ),
                  )
                : const Icon(Icons.refresh, color: Colors.white),
            tooltip: isUrdu ? 'تازہ ترین کیفیت معلوم کریں' : 'Refresh',
            onPressed: _isLoading ? null : _fetchLiveSubscriptionData,
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 850),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // 1. Status Banner Card
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(
                      colors: [Color(0xFF1E3A2F), Color(0xFF0F172A)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.15),
                        blurRadius: 10,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: Colors.white.withOpacity(0.15),
                              borderRadius: BorderRadius.circular(20),
                              border: Border.all(
                                  color: Colors.white.withOpacity(0.2)),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.fingerprint,
                                    size: 14, color: Colors.white70),
                                const SizedBox(width: 4),
                                Text(
                                  '${isUrdu ? "کوڈ" : "ID"}: ${currentTenantCode.value ?? "MAIN01"}',
                                  style: const TextStyle(
                                    fontFamily: 'monospace',
                                    fontWeight: FontWeight.bold,
                                    color: Colors.white,
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: isTrial
                                  ? Colors.red.withOpacity(0.85)
                                  : Colors.green.withOpacity(0.85),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  isTrial ? Icons.hourglass_top : Icons.verified,
                                  size: 13,
                                  color: Colors.white,
                                ),
                                const SizedBox(width: 4),
                                Text(
                                  isTrial
                                      ? (isUrdu ? '30 روزہ ٹرائل' : '30-Day Trial')
                                      : (isUrdu ? 'فعال پیکیج' : 'Active Plan'),
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: Colors.white,
                                    fontSize: 11,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      Text(
                        planName,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 22,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        currentTenantName.value ?? '',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.white70,
                          fontSize: 13,
                        ),
                      ),
                      const SizedBox(height: 16),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.08),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                              color: Colors.white.withOpacity(0.15)),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: [
                            Column(
                              children: [
                                Text(
                                  isUrdu ? 'باقی ایام' : 'Days Remaining',
                                  style: const TextStyle(
                                      color: Colors.white70, fontSize: 11),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '$daysLeft',
                                  style: const TextStyle(
                                    color: Colors.amberAccent,
                                    fontSize: 26,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ],
                            ),
                            Container(
                                width: 1,
                                height: 35,
                                color: Colors.white.withOpacity(0.2)),
                            Column(
                              children: [
                                Text(
                                  isUrdu ? 'اختتامی تاریخ' : 'Expiry Date',
                                  style: const TextStyle(
                                      color: Colors.white70, fontSize: 11),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  endsAt.isNotEmpty ? endsAt : '14 Days',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 14,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),

                const SizedBox(height: 16),

                // 2. Usage & Limits Box
                NeoBox(
                  padding: 16,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.pie_chart, color: primaryGreen, size: 20),
                          const SizedBox(width: 8),
                          Text(
                            isUrdu
                                ? 'پلان کی حدود و موجودہ استعمال'
                                : 'Plan Quotas & Usage',
                            style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: primaryGreen,
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 24),
                      _buildQuotaRow(
                        icon: Icons.school,
                        label: isUrdu ? 'زیرِ تعلیم طلبہ' : 'Students',
                        used: _usedStudents,
                        max: _maxStudents,
                        ratio: stdRatio,
                        color: Colors.teal,
                      ),
                      const SizedBox(height: 12),
                      _buildQuotaRow(
                        icon: Icons.class_,
                        label: isUrdu ? 'کلاسز و درجات' : 'Classes',
                        used: _usedClasses,
                        max: _maxClasses,
                        ratio: clsRatio,
                        color: Colors.indigo,
                      ),
                      const SizedBox(height: 12),
                      _buildQuotaRow(
                        icon: Icons.people,
                        label: isUrdu ? 'اساتذہ و عملہ' : 'Teachers & Staff',
                        used: _usedTeachers,
                        max: _maxTeachers,
                        ratio: teaRatio,
                        color: Colors.deepOrange,
                      ),
                    ],
                  ),
                ),

                const SizedBox(height: 16),

                // 3. Official Pricing Details Card
                NeoBox(
                  padding: 16,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.sell, color: Colors.green, size: 20),
                          const SizedBox(width: 8),
                          Text(
                            isUrdu ? 'آفیشل پیکیج فیس' : 'Official Package Pricing',
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: Colors.green,
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 20),
                      Row(
                        children: [
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: Colors.green.shade50,
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: Colors.green.shade200),
                              ),
                              child: Column(
                                children: [
                                  Text(
                                    isUrdu ? 'ماہانہ فیس' : 'Monthly Fee',
                                    style: TextStyle(
                                        fontSize: 11,
                                        color: Colors.green.shade900),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'روپے ${_priceMonthly.toInt()}',
                                    style: TextStyle(
                                      fontSize: 17,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.green.shade900,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: Colors.blue.shade50,
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(color: Colors.blue.shade200),
                              ),
                              child: Column(
                                children: [
                                  Text(
                                    isUrdu ? 'سالانہ رعایتی فیس' : 'Yearly Fee',
                                    style: TextStyle(
                                        fontSize: 11,
                                        color: Colors.blue.shade900),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'روپے ${_priceYearly.toInt()}',
                                    style: TextStyle(
                                      fontSize: 17,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.blue.shade900,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                const SizedBox(height: 16),

                // 4. Payment Accounts Box
                NeoBox(
                  padding: 16,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.account_balance,
                              color: Colors.indigo, size: 20),
                          const SizedBox(width: 8),
                          Text(
                            isUrdu
                                ? 'بینک و آن لائن ادائیگی تفصیلات'
                                : 'Official Payment Accounts',
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: Colors.indigo,
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 20),
                      _buildAccountItem(
                        icon: Icons.account_balance_outlined,
                        title: 'Meezan Bank Ltd',
                        subtitle: 'Madrasa Management SaaS',
                        number: '02830105820491',
                        color: Colors.blue,
                      ),
                      const SizedBox(height: 10),
                      _buildAccountItem(
                        icon: Icons.phone_android,
                        title: 'JazzCash / EasyPaisa',
                        subtitle: 'Muhammad Tanzil',
                        number: '0301-2454595',
                        color: Colors.red,
                      ),
                      const SizedBox(height: 16),
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF25D366),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                          elevation: 2,
                        ),
                        onPressed: _openWhatsApp,
                        icon: const Icon(Icons.chat, color: Colors.white),
                        label: Text(
                          isUrdu
                              ? 'واٹس ایپ پر رسید بھیجیں اور ایکٹیویٹ کروائیں'
                              : 'Send Payment Receipt on WhatsApp',
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 30),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildQuotaRow({
    required IconData icon,
    required String label,
    required int used,
    required int max,
    required double ratio,
    required Color color,
  }) {
    return Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Row(
              children: [
                Icon(icon, size: 16, color: color),
                const SizedBox(width: 6),
                Text(
                  label,
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                ),
              ],
            ),
            Text(
              '$used / ${max > 9000 ? "لامحدود" : max}',
              style: TextStyle(
                fontFamily: 'monospace',
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: color,
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: ratio,
            minHeight: 7,
            backgroundColor: color.withOpacity(0.12),
            valueColor: AlwaysStoppedAnimation<Color>(color),
          ),
        ),
      ],
    );
  }

  Widget _buildAccountItem({
    required IconData icon,
    required String title,
    required String subtitle,
    required String number,
    required Color color,
  }) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withOpacity(0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: color, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                ),
                Text(
                  subtitle,
                  style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                ),
              ],
            ),
          ),
          SelectableText(
            number,
            style: TextStyle(
              fontFamily: 'monospace',
              fontWeight: FontWeight.bold,
              fontSize: 14,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}
