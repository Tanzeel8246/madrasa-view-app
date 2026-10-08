// ignore_for_file: deprecated_member_use
import 'package:flutter/material.dart';
import '../utils/globals.dart';
import '../screens/subscription_status.dart';

/// ٹاپ ہیڈر پر مسلسل جلتی بجھتی سرخ بتی والا انڈیکیٹر (Blinking Red Trial Beacon)
/// کلک کرنے پر آزمائشی مدت کی وارننگ اور سبسکرپشن اسٹیٹس اسکرین پر جانے کا راستہ فراہم کرتا ہے
class BlinkingTrialBeacon extends StatefulWidget {
  final bool compact;
  const BlinkingTrialBeacon({super.key, this.compact = false});

  @override
  State<BlinkingTrialBeacon> createState() => _BlinkingTrialBeaconState();
}

class _BlinkingTrialBeaconState extends State<BlinkingTrialBeacon>
    with SingleTickerProviderStateMixin {
  late AnimationController _animController;
  late Animation<double> _glowAnimation;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..repeat(reverse: true);

    _glowAnimation = Tween<double>(begin: 0.25, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _animController.dispose();
    super.dispose();
  }

  void _showWarningDialog(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    final planName = currentTenantPlanName.value;
    final days = currentTenantTrialDaysLeft.value;

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: Colors.red.shade100,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.warning_amber_rounded, color: Colors.red, size: 26),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                isUrdu ? 'آزمائشی مدت کی یاد دہانی' : 'Trial Notice',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
              ),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isUrdu
                  ? 'آپ کے $planName کے صرف $days دن کا ٹرائل باقی ہے۔ سبسکرپشن چارجز ادا کر کے اپنا پلان ایکٹیویٹ / ری نیو کروائیں تاکہ تمام خدمات، موبائل ایپ اور بیک اپ کی بلا تعطل فراہمی جاری رہے۔'
                  : 'Only $days days remaining in your $planName trial. Please pay subscription charges to activate/renew your plan.',
              style: const TextStyle(fontSize: 14, height: 1.5),
            ),
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.red.shade200),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    isUrdu ? 'باقی ایام:' : 'Days Remaining:',
                    style: TextStyle(fontSize: 13, color: Colors.red.shade800),
                  ),
                  Text(
                    isUrdu ? '$days دن' : '$days Days',
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.bold,
                      color: Colors.red.shade900,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(isUrdu ? 'سمجھ گیا' : 'Dismiss'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF284D3D),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            ),
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const SubscriptionStatusPage()),
              );
            },
            icon: const Icon(Icons.credit_card, size: 16),
            label: Text(
              isUrdu ? 'کیفیت و ادائیگی تفصیلات' : 'View Billing Details',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: currentTenantIsTrial,
      builder: (context, isTrial, _) {
        if (!isTrial) return const SizedBox.shrink();

        return ValueListenableBuilder<int>(
          valueListenable: currentTenantTrialDaysLeft,
          builder: (context, daysLeft, _) {
            final isUrdu = isUrduGlobal.value;

            return AnimatedBuilder(
              animation: _glowAnimation,
              builder: (context, child) {
                final opacity = _glowAnimation.value;
                return InkWell(
                  onTap: () => _showWarningDialog(context),
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    margin: const EdgeInsets.symmetric(vertical: 9, horizontal: 4),
                    padding: EdgeInsets.symmetric(
                      horizontal: widget.compact ? 8 : 10,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.red.withOpacity(0.18 + (opacity * 0.12)),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(
                        color: Colors.redAccent.withOpacity(0.5 + (opacity * 0.5)),
                        width: 1.4,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.red.withOpacity(0.3 * opacity),
                          blurRadius: 6 * opacity,
                          spreadRadius: 1 * opacity,
                        )
                      ],
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: Colors.red.withOpacity(0.4 + (opacity * 0.6)),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.red.withOpacity(opacity),
                                blurRadius: 4,
                                spreadRadius: 1,
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 5),
                        Text(
                          widget.compact
                              ? (isUrdu ? '$daysLeft دن' : '${daysLeft}d')
                              : (isUrdu ? 'ٹرائل: $daysLeft دن' : 'Trial: ${daysLeft}d'),
                          style: const TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            );
          },
        );
      },
    );
  }
}
