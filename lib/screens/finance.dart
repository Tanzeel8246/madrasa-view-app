import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import 'dashboard.dart';
import 'simple_finance.dart';
import 'reports_finance.dart';
import 'annual_report.dart';
import 'loans_management.dart';
import 'in_kind_donations.dart';
import 'masjid_assets.dart';
import 'acc_invoices.dart';
import 'acc_payroll.dart';
import 'acc_chart.dart';
import 'acc_income_statement.dart';
import 'acc_trial_balance.dart';
import 'acc_balance_sheet.dart';
import 'acc_vouchers.dart';
import 'acc_ledger.dart';
import 'departments.dart';

class FinancePage extends StatefulWidget {
  const FinancePage({super.key});

  @override
  State<FinancePage> createState() => _FinancePageState();
}

class _FinancePageState extends State<FinancePage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);

  double totalRevenue = 0;
  double totalExpenses = 0;
  double netIncome = 0;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    final db = DBHelper();
    await db.ensureSimpleFinanceAccountingBackfill();
    final records = await db.getFinance(
        userRole: 'admin'); // Assuming admin can see all for ERP dashboard

    double inc = 0;
    double exp = 0;

    for (var r in records) {
      if (r['is_deleted'] == 1) continue;
      final type = r['type']?.toString().toLowerCase() ?? '';
      final amount = double.tryParse(r['amount']?.toString() ?? '0') ?? 0;
      if (type == 'income') {
        inc += amount;
      } else if (type == 'expense') {
        exp += amount;
      }
    }

    setState(() {
      totalRevenue = inc;
      totalExpenses = exp;
      netIncome = inc - exp;
    });
  }

  Widget _buildCard(String title, String amount, IconData icon, Color color) {
    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(15),
          gradient: LinearGradient(
            colors: [color.withOpacity(0.8), color],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 30, color: Colors.white),
            const SizedBox(height: 5),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                title,
                style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: Colors.white),
                textAlign: TextAlign.center,
              ),
            ),
            const SizedBox(height: 5),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                "Rs $amount",
                style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.white),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildGridMenuCard(String title, IconData icon, Widget page) {
    final isUrdu = isUrduGlobal.value;
    return GestureDetector(
      onTap: () =>
          Navigator.push(context, MaterialPageRoute(builder: (_) => page)),
      child: Card(
        elevation: 3,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            gradient: LinearGradient(
              colors: [primaryGreen.withOpacity(0.9), primaryGreen],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            boxShadow: [
              BoxShadow(
                color: primaryGreen.withOpacity(0.18),
                blurRadius: 6,
                offset: const Offset(0, 3),
              ),
            ],
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Icon(icon, size: 30, color: Colors.white),
              const SizedBox(height: 8),
              Expanded(
                child: Center(
                  child: Text(
                    title,
                    style: TextStyle(
                      fontSize: isUrdu ? 14 : 13,
                      fontWeight: FontWeight.bold,
                      color: Colors.white,
                      height: 1.25,
                    ),
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildGridSection(List<Widget> cards, {required int crossAxisCount, double childAspectRatio = 1.05}) {
    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: crossAxisCount,
      crossAxisSpacing: 12,
      mainAxisSpacing: 12,
      childAspectRatio: childAspectRatio,
      children: cards,
    );
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        } else {
          Navigator.pushReplacement(context,
              MaterialPageRoute(builder: (c) => const DashboardPage()));
        }
      },
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          actions: [
            IconButton(
              icon: const Icon(Icons.sync, color: Colors.white),
              tooltip: isUrdu ? 'ڈیٹا سنک کریں' : 'Sync Data',
              onPressed: () async {
                ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(isUrdu ? 'ڈیٹا سنک ہو رہا ہے...' : 'Syncing data...')));
                await _loadData();
              },
            )
          ],
          title: Text(isUrdu
              ? 'مالیات و اکاؤنٹس ڈیش بورڈ'
              : 'Finance & Accounts Dashboard'),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
        ),
        drawer: const AppDrawer(),
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1100),
            child: LayoutBuilder(
              builder: (context, constraints) {
                final bool isWide = constraints.maxWidth >= 750;
                final int gridCols = constraints.maxWidth < 420
                    ? 2
                    : (constraints.maxWidth < 700 ? 3 : 4);
                final double gridRatio = constraints.maxWidth < 420
                    ? 1.05
                    : (constraints.maxWidth < 700 ? 1.08 : 1.15);

                return SingleChildScrollView(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Summary cards
                      GridView.count(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        crossAxisCount: isWide ? 4 : 2,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        childAspectRatio: isWide ? 1.6 : 1.45,
                        children: [
                          _buildCard(
                              isUrdu ? 'کل آمدن' : 'Total Revenue',
                              totalRevenue.toStringAsFixed(0),
                              Icons.arrow_downward,
                              Colors.green.shade700),
                          _buildCard(
                              isUrdu ? 'کل اخراجات' : 'Total Expenses',
                              totalExpenses.toStringAsFixed(0),
                              Icons.arrow_upward,
                              Colors.red.shade700),
                          _buildCard(
                              isUrdu ? 'خالص بچت / سرپلس' : 'Net Surplus',
                              netIncome.toStringAsFixed(0),
                              Icons.account_balance_wallet,
                              Colors.teal.shade700),
                          _buildCard(
                              isUrdu ? 'نقد و بینک بیلنس' : 'Cash & Bank Balances',
                              netIncome.toStringAsFixed(0),
                              Icons.account_balance,
                              Colors.amber.shade800),
                        ],
                      ),
                      const SizedBox(height: 22),

                      // Section 1: Core Finance & Reports
                      _buildSectionHeader(isUrdu ? 'آمدن، خرچ و مالیاتی رپورٹس' : 'Income, Expense & Reports'),
                      const SizedBox(height: 8),
                      _buildGridSection([
                        _buildGridMenuCard(
                            isUrdu ? 'آمدن و خرچ (سادہ اندراج)' : 'Income & Expense (Simple)',
                            Icons.account_balance_wallet,
                            const SimpleFinancePage()),
                        _buildGridMenuCard(
                            isUrdu ? 'مالیاتی تفصیلی رپورٹ' : 'Finance Report',
                            Icons.bar_chart,
                            const ReportsFinancePage()),
                        _buildGridMenuCard(
                            isUrdu ? 'سالانہ جامع مالیاتی رپورٹ' : 'Annual Financial Report',
                            Icons.insert_chart,
                            const AnnualReportPage()),
                      ], crossAxisCount: gridCols, childAspectRatio: gridRatio),

                      const SizedBox(height: 20),

                      // Section 2: Loans, Advance, Donations & Assets
                      _buildSectionHeader(isUrdu ? 'قرض حسنہ، عطیات و اثاثہ جات' : 'Loans, Donations & Assets'),
                      const SizedBox(height: 8),
                      _buildGridSection([
                        _buildGridMenuCard(
                            isUrdu ? 'ایڈوانس و قرض حسنہ مینیجمنٹ' : 'Loans & Advance Management',
                            Icons.request_quote,
                            const LoansManagementPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'اشیاء و عطیات' : 'In-Kind Donations',
                            Icons.card_giftcard,
                            const InKindDonationsScreen()),
                        _buildGridMenuCard(
                            isUrdu ? 'اثاثہ جات مینیجمنٹ' : 'Assets Management',
                            Icons.inventory_2,
                            const MasjidAssetsPage()),
                      ], crossAxisCount: gridCols, childAspectRatio: gridRatio),

                      const SizedBox(height: 20),

                      // Section 3: Full ERP Accounting
                      _buildSectionHeader(isUrdu ? 'مکمل ای آر پی اکاؤنٹس (ERP Accounting)' : 'Full ERP Accounting'),
                      const SizedBox(height: 8),
                      _buildGridSection([
                        _buildGridMenuCard(
                            isUrdu ? 'وظائف عملہ و پے رول' : 'Staff Stipends & Payroll',
                            Icons.payments,
                            const AccPayrollPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'فیس انوائسز و بلنگ' : 'Fee Invoices & Billing',
                            Icons.receipt_long,
                            const AccInvoicesPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'چارٹ آف اکاؤنٹس (کھاتہ جات)' : 'Chart of Accounts',
                            Icons.account_tree,
                            const AccChartPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'اکاؤنٹنگ واؤچرز' : 'Accounting Vouchers',
                            Icons.receipt,
                            const AccVouchersPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'جنرل لیجر (کھاتہ بہی)' : 'General Ledger',
                            Icons.menu_book,
                            const AccLedgerPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'میزان پڑتال (Trial Balance)' : 'Trial Balance',
                            Icons.balance,
                            const AccTrialBalancePage()),
                        _buildGridMenuCard(
                            isUrdu ? 'گوشوارہ آمدن و اخراجات' : 'Income Statement',
                            Icons.pie_chart,
                            const AccIncomeStatementPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'بیلنس شیٹ (Balance Sheet)' : 'Balance Sheet',
                            Icons.account_balance,
                            const BalanceSheetPage()),
                        _buildGridMenuCard(
                            isUrdu ? 'شعبہ جات و برانچز' : 'Departments & Branches',
                            Icons.business,
                            const DepartmentsPage()),
                      ], crossAxisCount: gridCols, childAspectRatio: gridRatio),
                      const SizedBox(height: 25),
                    ],
                  ),
                );
              },
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildSectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4.0, vertical: 2.0),
      child: Text(
        title,
        style: TextStyle(
          fontWeight: FontWeight.bold,
          fontSize: 16,
          color: primaryGreen,
        ),
      ),
    );
  }
}
