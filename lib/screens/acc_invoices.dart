// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:image_picker/image_picker.dart';
import 'dart:io';
import 'package:path_provider/path_provider.dart';

import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/sync_service.dart';
import '../widgets/app_drawer.dart';

class AccInvoicesPage extends StatefulWidget {
  const AccInvoicesPage({super.key});

  @override
  State<AccInvoicesPage> createState() => _AccInvoicesPageState();
}

class _AccInvoicesPageState extends State<AccInvoicesPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  List<Map<String, dynamic>> _invoices = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadInvoices();
  }

  Future<void> _loadInvoices() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final allInvoices = await db.getAccInvoices();
    final students = await db.getStudents();
    
    List<Map<String, dynamic>> enriched = [];
    for (var inv in allInvoices) {
      final st = students.firstWhere((s) => s['id'] == inv['student_id'], orElse: () => {});
      final stName = st['name'] ?? 'Unknown';
      final stRoll = st['roll_number'] ?? '';
      enriched.add({
        ...inv,
        'student_name': stName,
        'roll_number': stRoll,
      });
    }
    
    setState(() {
      _invoices = enriched;
      _isLoading = false;
    });
  }

  Future<void> _generateBulkInvoices() async {
    final monthController = TextEditingController(text: "${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}");
    final amountController = TextEditingController();

    final result = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: Text(isUrduGlobal.value ? 'فیس انوائس بنائیں' : 'Generate Invoices'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: monthController,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value ? 'مہینہ (YYYY-MM)' : 'Month (YYYY-MM)',
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: amountController,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value ? 'فیس کی رقم' : 'Fee Amount',
                  border: const OutlineInputBorder(),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(isUrduGlobal.value ? 'منسوخ کریں' : 'Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(context, true),
              child: Text(isUrduGlobal.value ? 'بنائیں' : 'Generate'),
            ),
          ],
        );
      }
    );

    if (result == true) {
      final amount = double.tryParse(amountController.text) ?? 0.0;
      final month = monthController.text;
      if (amount > 0 && month.isNotEmpty) {
        final db = DBHelper();
        final students = await db.getStudents();
        for (var st in students) {
          final stId = st['id'];
          final existing = _invoices.where((i) => i['student_id'] == stId && i['month_year'] == month).toList();
          if (existing.isEmpty) {
            await db.insertAccInvoice({
              'student_id': stId,
              'invoice_no': "INV-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}",
              'month_year': month,
              'amount': amount,
              'status': 'Unpaid',
            });
          }
        }
        await _loadInvoices();
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(isUrduGlobal.value ? 'انوائسز بن گئی ہیں' : 'Invoices Generated')));
        }
      }
    }
  }

  Future<void> _markPaid(int id) async {
    await DBHelper().updateAccInvoice(id, {'status': 'Paid'});
    await _loadInvoices();
  }

  void _showReceipt(BuildContext context, Map<String, dynamic> inv) {
    final ScreenshotController screenshotController = ScreenshotController();
    String? signaturePath; // local path for signature image

    showDialog(
      context: context,
      builder: (c) {
        return StatefulBuilder(
          builder: (context, setStateDialog) {
            return AlertDialog(
              contentPadding: const EdgeInsets.all(0),
              title: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(isUrduGlobal.value ? 'فیس رسید' : 'Fee Receipt'),
                  TextButton.icon(
                    icon: const Icon(Icons.draw, size: 16),
                    label: Text(isUrduGlobal.value ? 'دستخط اپلوڈ' : 'Upload Sign'),
                    onPressed: () async {
                      final picker = ImagePicker();
                      final picked = await picker.pickImage(source: ImageSource.gallery, imageQuality: 70);
                      if (picked != null) {
                        setStateDialog(() => signaturePath = picked.path);
                      }
                    },
                  ),
                ],
              ),
              content: SingleChildScrollView(
                child: Screenshot(
                  controller: screenshotController,
                  child: Container(
                    width: 380,
                    padding: const EdgeInsets.all(20),
                    color: Colors.white,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Logo + Header
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Image.asset('assets/logo.png', width: 50, height: 50,
                              errorBuilder: (_, __, ___) => const Icon(Icons.school, size: 50, color: Color(0xFF284D3D))),
                            const SizedBox(width: 12),
                            const Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text("مدرسہ اشرف المدارس", style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, fontFamily: 'JameelNooriNastaleeq')),
                                  Text("فاروقہ، ضلع سرگودھا", style: TextStyle(fontSize: 11, color: Colors.grey, fontFamily: 'JameelNooriNastaleeq')),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          color: const Color(0xFF284D3D),
                          child: const Text("فیس رسید", textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold, fontFamily: 'JameelNooriNastaleeq')),
                        ),
                        const SizedBox(height: 8),
                        _receiptRow("طالب علم", "${inv['student_name']} (${inv['roll_number']})"),
                        _receiptRow("مہینہ", "${inv['month_year']}"),
                        const Divider(),
                        _receiptRow("بنیادی فیس", "Rs ${inv['amount']}"),
                        _receiptRow("اضافی رقم/بقایا جات", "+ Rs ${inv['arrears'] ?? 0.0}"),
                        _receiptRow("رعایت", "- Rs ${inv['discount'] ?? 0.0}"),
                        const Divider(thickness: 2),
                        _receiptRow("خالص رقم", "Rs ${inv['net_amount'] ?? inv['amount']}", bold: true),
                        _receiptRow("سٹیٹس", inv['status'] == 'Paid' ? 'ادا شدہ ✓' : 'غیر ادا شدہ'),
                        const SizedBox(height: 16),
                        const Divider(thickness: 1),
                        // Signature area
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text("دستخط / مہر:", style: TextStyle(fontSize: 12, fontFamily: 'JameelNooriNastaleeq')),
                            if (signaturePath != null && File(signaturePath!).existsSync())
                              Image.file(File(signaturePath!), height: 50, width: 100, fit: BoxFit.contain)
                            else
                              const Text("_____________________", style: TextStyle(fontSize: 12)),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text("تاریخ: ${DateTime.now().toString().split(' ')[0]}", 
                          style: const TextStyle(fontSize: 10, color: Colors.grey)),
                      ],
                    ),
                  ),
                ),
              ),
              actions: [
                TextButton(onPressed: () => Navigator.pop(c), child: const Text("بند کریں")),
                ElevatedButton.icon(
                  icon: const Icon(Icons.share),
                  label: Text(isUrduGlobal.value ? "شیئر کریں" : "Share"),
                  style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white),
                  onPressed: () async {
                    final image = await screenshotController.capture();
                    if (image != null) {
                      final directory = await getApplicationDocumentsDirectory();
                      final imagePath = await File('${directory.path}/invoice_${inv['id']}.png').create();
                      await imagePath.writeAsBytes(image);
                      await Share.shareXFiles([XFile(imagePath.path)], text: 'فیس رسید - ${inv['student_name']}');
                    }
                  },
                ),
              ],
            );
          },
        );
      },
    );
  }

  Widget _receiptRow(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 13, fontFamily: 'JameelNooriNastaleeq')),
          Text(value, style: TextStyle(
            fontSize: 13, 
            fontWeight: bold ? FontWeight.bold : FontWeight.normal,
            fontFamily: 'JameelNooriNastaleeq',
          )),
        ],
      ),
    );
  }

  void _showEditInvoiceDialog(BuildContext context, Map<String, dynamic> inv) {
    final arrearsController = TextEditingController(text: (inv['arrears'] ?? 0.0).toString());
    final discountController = TextEditingController(text: (inv['discount'] ?? 0.0).toString());

    showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: Text(isUrduGlobal.value ? 'اضافی رقم / کٹوتی' : 'Add Arrears / Discount'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: arrearsController,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: isUrduGlobal.value ? 'بقایا جات / اضافی رقم (Arrears)' : 'Arrears / Fine'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: discountController,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: isUrduGlobal.value ? 'رعایت / کٹوتی (Discount)' : 'Discount'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text(isUrduGlobal.value ? 'کینسل' : 'Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white),
              onPressed: () async {
                final arrears = double.tryParse(arrearsController.text) ?? 0.0;
                final discount = double.tryParse(discountController.text) ?? 0.0;
                final baseAmount = double.tryParse(inv['amount'].toString()) ?? 0.0;
                final netAmount = baseAmount + arrears - discount;

                await DBHelper().updateAccInvoice(inv['id'], {
                  'arrears': arrears,
                  'discount': discount,
                  'net_amount': netAmount,
                });
                if (context.mounted) {
                  Navigator.pop(context);
                  _loadInvoices();
                  try { await SyncService.runTwoWaySync(); } catch (_) {}
                }
              },
              child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save'),
            ),
          ],
        );
      }
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(isUrduGlobal.value ? 'فیس انوائس' : 'Fee Invoices'),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      drawer: const AppDrawer(),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(8.0),
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.orange.shade700,
                      foregroundColor: Colors.white,
                      minimumSize: const Size(double.infinity, 50),
                    ),
                    icon: const Icon(Icons.auto_awesome),
                    label: Text(isUrduGlobal.value ? 'بلک انوائس بنائیں' : 'Generate Bulk Invoices'),
                    onPressed: _generateBulkInvoices,
                  ),
                ),
                Expanded(
                  child: _invoices.isEmpty
                      ? Center(child: Text(isUrduGlobal.value ? 'کوئی انوائس نہیں' : 'No invoices found'))
                      : ListView.builder(
                          itemCount: _invoices.length,
                          itemBuilder: (context, index) {
                            final inv = _invoices[index];
                            final isPaid = inv['status'] == 'Paid';
                            return Card(
                              margin: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              child: ListTile(
                                title: Text("${inv['student_name']} (${inv['roll_number']})", style: const TextStyle(fontWeight: FontWeight.bold)),
                                subtitle: Text("${isUrduGlobal.value ? 'رقم' : 'Amount'}: Rs ${inv['net_amount'] ?? inv['amount']} | ${inv['month_year']}"),
                                trailing: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color: isPaid ? Colors.green : Colors.orange,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text(isPaid ? (isUrduGlobal.value ? 'ادا شدہ' : 'Paid') : (isUrduGlobal.value ? 'غیر ادا' : 'Unpaid'), style: const TextStyle(color: Colors.white, fontSize: 12)),
                                    ),
                                    if (!isPaid)
                                      IconButton(
                                        icon: const Icon(Icons.check_circle, color: Colors.green),
                                        onPressed: () => _markPaid(inv['id']),
                                      ),
                                    IconButton(
                                      icon: const Icon(Icons.edit, color: Colors.blue),
                                      onPressed: () => _showEditInvoiceDialog(context, inv),
                                    ),
                                    IconButton(
                                      icon: const Icon(Icons.receipt_long, color: Colors.indigo),
                                      onPressed: () => _showReceipt(context, inv),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
    );
  }
}
