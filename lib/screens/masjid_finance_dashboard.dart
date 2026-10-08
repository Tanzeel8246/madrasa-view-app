import 'package:flutter/material.dart';
import '../utils/globals.dart';
import 'simple_finance.dart';
import 'reports_finance.dart';
import 'in_kind_donations.dart';
import 'masjid_assets.dart';
import 'acc_payroll.dart';
import 'loans_management.dart';
import '../widgets/app_drawer.dart';

class MasjidFinanceDashboardPage extends StatelessWidget {
  const MasjidFinanceDashboardPage({super.key});

  @override
  Widget build(BuildContext context) {
    bool isUrdu = isUrduGlobal.value;
    const primaryGreen = Color(0xFF284D3D);

    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      drawer: const AppDrawer(),
      appBar: AppBar(
        title: Text(isUrdu ? 'مسجد فائنانس و اکاؤنٹس' : 'Masjid Finance & Accounts'),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
        centerTitle: true,
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1100),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final bool isWide = constraints.maxWidth >= 750;
              return GridView.count(
                padding: const EdgeInsets.all(16),
                crossAxisCount: isWide ? 3 : 2,
                crossAxisSpacing: 16,
                mainAxisSpacing: 16,
                childAspectRatio: isWide ? 1.4 : 1.1,
                children: [
                  _buildCard(context, isUrdu ? 'آمدن و خرچ' : 'Income & Expense', Icons.account_balance_wallet, const SimpleFinancePage()),
                  _buildCard(context, isUrdu ? 'فائنانس رپورٹ' : 'Finance Report', Icons.bar_chart, const ReportsFinancePage()),
                  _buildCard(context, isUrdu ? 'اشیاء و عطیات' : 'In-Kind Donations', Icons.card_giftcard, const InKindDonationsScreen()),
                  _buildCard(context, isUrdu ? 'اثاثہ جات' : 'Assets', Icons.inventory_2, const MasjidAssetsPage()),
                  _buildCard(context, isUrdu ? 'وظائف عملہ' : 'Staff Stipends', Icons.payments, const AccPayrollPage()),
                  _buildCard(context, isUrdu ? 'ایڈوانس و قرض' : 'Loans & Advance', Icons.request_quote, const LoansManagementPage()),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _buildCard(BuildContext context, String title, IconData icon, Widget page) {
    return GestureDetector(
      onTap: () {
        Navigator.push(context, MaterialPageRoute(builder: (_) => page));
      },
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFF284D3D).withOpacity(0.3), width: 1.5),
          boxShadow: [
            BoxShadow(
              color: const Color(0xFF284D3D).withOpacity(0.1),
              blurRadius: 10,
              offset: const Offset(0, 4),
            )
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFF284D3D).withOpacity(0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 36, color: const Color(0xFF284D3D)),
            ),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: Color(0xFF284D3D),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
