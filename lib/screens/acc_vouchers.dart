// ignore_for_file: use_build_context_synchronously

import 'package:flutter/material.dart';
import '../utils/sync_service.dart';
import '../widgets/app_drawer.dart';
import 'package:intl/intl.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';

import '../db_helper.dart';

class AccVouchersPage extends StatefulWidget {
  const AccVouchersPage({super.key});

  @override
  State<AccVouchersPage> createState() => _AccVouchersPageState();
}

class _AccVouchersPageState extends State<AccVouchersPage> {
  final Color primary = const Color(0xFF284D3D);
  final DateFormat _fmt = DateFormat('yyyy-MM-dd');

  List<Map<String, dynamic>> _vouchers = [];
  List<Map<String, dynamic>> _accounts = [];
  List<Map<String, dynamic>> _departments = [];
  bool isLoading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final db = DBHelper();
    final vouchers = await db.getAccVouchersWithTotals();
    final accounts = await db.getAccChartAccounts();
    final departments = await db.getDepartments();
    setState(() {
      _vouchers = vouchers;
      _accounts = accounts;
      _departments =
          departments.where((d) => d['status'] != 'Inactive').toList();
      isLoading = false;
    });
  }

  Future<void> _showVoucherDialog({Map<String, dynamic>? editVoucher, List<Map<String, dynamic>>? editEntries}) async {
    final isEditing = editVoucher != null;
    final dateController = TextEditingController(
        text: isEditing ? (editVoucher['date'] ?? _fmt.format(DateTime.now())) : _fmt.format(DateTime.now()));
    final referenceController = TextEditingController(text: isEditing ? (editVoucher['reference'] ?? '') : '');
    final descriptionController = TextEditingController(text: isEditing ? (editVoucher['description'] ?? '') : '');
    String type = isEditing ? (editVoucher['type'] ?? 'Journal') : 'Journal';
    int? departmentId = isEditing ? (int.tryParse(editVoucher['department_id']?.toString() ?? '')) : null;
    
    List<_VoucherLine> lines = [];
    if (isEditing && editEntries != null) {
      for (var entry in editEntries) {
        final line = _VoucherLine();
        line.accountId = entry['account_id'];
        line.debit.text = (double.tryParse(entry['debit']?.toString() ?? '0') ?? 0) > 0 ? entry['debit'].toString() : '';
        line.credit.text = (double.tryParse(entry['credit']?.toString() ?? '0') ?? 0) > 0 ? entry['credit'].toString() : '';
        lines.add(line);
      }
    } else {
      lines = [_VoucherLine(), _VoucherLine()];
    }

    await showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          double totalDebit = _sum(lines, true);
          double totalCredit = _sum(lines, false);
          final balanced =
              totalDebit > 0 && (totalDebit - totalCredit).abs() <= 0.01;

          return AlertDialog(
            title: Text(isEditing ? 'واؤچر ترمیم (Edit)' : 'نیا واؤچر'),
            content: SizedBox(
              width: 720,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: dateController,
                            readOnly: true,
                            decoration: const InputDecoration(
                              labelText: 'تاریخ',
                              border: OutlineInputBorder(),
                            ),
                            onTap: () async {
                              final picked = await showDatePicker(
                                context: ctx,
                                initialDate:
                                    DateTime.tryParse(dateController.text) ??
                                        DateTime.now(),
                                firstDate: DateTime(2020),
                                lastDate: DateTime(2100),
                              );
                              if (picked != null) {
                                dateController.text = _fmt.format(picked);
                              }
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            value: type,
                            decoration: const InputDecoration(
                              labelText: 'قسم',
                              border: OutlineInputBorder(),
                            ),
                            items: ['Journal', 'Payment', 'Receipt', 'Contra']
                                .map((t) => DropdownMenuItem(
                                      value: t,
                                      child: Text(t),
                                    ))
                                .toList(),
                            onChanged: (v) =>
                                setDialogState(() => type = v ?? 'Journal'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: DropdownButtonFormField<int>(
                            value: departmentId,
                            decoration: const InputDecoration(
                              labelText: 'شعبہ',
                              border: OutlineInputBorder(),
                            ),
                            items: [
                              const DropdownMenuItem<int>(
                                  value: null, child: Text('-- کوئی نہیں --')),
                              ..._departments.map((d) => DropdownMenuItem<int>(
                                    value: d['id'] as int,
                                    child: Text(d['name']?.toString() ?? ''),
                                  ))
                            ],
                            onChanged: (v) =>
                                setDialogState(() => departmentId = v),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: referenceController,
                            decoration: const InputDecoration(
                              labelText: 'حوالہ نمبر',
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: TextField(
                            controller: descriptionController,
                            decoration: const InputDecoration(
                              labelText: 'تفصیل',
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    const Text('Ledger Entries',
                        style: TextStyle(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 8),
                    ...List.generate(lines.length, (i) {
                      final line = lines[i];
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Row(
                          children: [
                            Expanded(
                              flex: 3,
                              child: DropdownButtonFormField<int>(
                                value: line.accountId,
                                decoration: InputDecoration(
                                  labelText: 'اکاؤنٹ ${i + 1}',
                                  border: const OutlineInputBorder(),
                                ),
                                items: _accounts
                                    .map(
                                      (a) => DropdownMenuItem<int>(
                                        value: a['id'] as int,
                                        child: Text(
                                          '${a['code'] ?? ''} ${a['name'] ?? ''}',
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    )
                                    .toList(),
                                onChanged: (v) =>
                                    setDialogState(() => line.accountId = v),
                              ),
                            ),
                            const SizedBox(width: 6),
                            Expanded(
                              child: TextField(
                                controller: line.debit,
                                keyboardType: TextInputType.number,
                                decoration: const InputDecoration(
                                  labelText: 'Debit',
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (_) => setDialogState(() {}),
                              ),
                            ),
                            const SizedBox(width: 6),
                            Expanded(
                              child: TextField(
                                controller: line.credit,
                                keyboardType: TextInputType.number,
                                decoration: const InputDecoration(
                                  labelText: 'Credit',
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (_) => setDialogState(() {}),
                              ),
                            ),
                            IconButton(
                              onPressed: lines.length <= 2
                                  ? null
                                  : () =>
                                      setDialogState(() => lines.removeAt(i)),
                              icon: const Icon(Icons.delete, color: Colors.red),
                            ),
                          ],
                        ),
                      );
                    }),
                    Align(
                      alignment: Alignment.centerRight,
                      child: OutlinedButton.icon(
                        onPressed: () =>
                            setDialogState(() => lines.add(_VoucherLine())),
                        icon: const Icon(Icons.add),
                        label: const Text('لائن شامل کریں'),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: balanced
                            ? Colors.green.shade50
                            : Colors.red.shade50,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Debit: ${totalDebit.toStringAsFixed(2)}'),
                          Text('Credit: ${totalCredit.toStringAsFixed(2)}'),
                          Text(
                            balanced ? 'Balanced' : 'Not Balanced',
                            style: TextStyle(
                              color: balanced ? Colors.green : Colors.red,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('منسوخ'),
              ),
              ElevatedButton.icon(
                onPressed: balanced
                    ? () async {
                        final cleanLines = lines
                            .where((l) =>
                                l.accountId != null &&
                                (_amount(l.debit.text) > 0 ||
                                    _amount(l.credit.text) > 0))
                            .map(
                              (l) => {
                                'account_id': l.accountId,
                                'debit': _amount(l.debit.text),
                                'credit': _amount(l.credit.text),
                              },
                            )
                            .toList();
                        var syncFailed = false;
                        
                        if (isEditing) {
                          await DBHelper().updateAccVoucherWithEntries(
                            voucherId: editVoucher['id'],
                            voucher: {
                              'date': dateController.text,
                              'type': type,
                              'reference': referenceController.text.trim(),
                              'description': descriptionController.text.trim(),
                              'department_id': departmentId,
                              'status': editVoucher['status'],
                            },
                            entries: cleanLines,
                          );
                        } else {
                          await DBHelper().insertAccVoucherWithEntries(
                            voucher: {
                              'date': dateController.text,
                              'type': type,
                              'reference': referenceController.text.trim(),
                              'description': descriptionController.text.trim(),
                              'department_id': departmentId,
                              'status': 'Pending',
                              'system_type': SystemTypeGlobal.currentSystemType.value,
                            },
                            entries: cleanLines,
                          );
                        }
                        
                        try {
                          await SyncService.runTwoWaySync();
                        } catch (e) {
                          syncFailed = true;
                        }
                        if (mounted) {
                          Navigator.pop(ctx);
                          _load();
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(syncFailed
                                ? 'واؤچر محفوظ ہو گیا، مگر سنک نہیں ہو سکا۔'
                                : 'واؤچر محفوظ اور سنک ہو گیا۔'),
                          ));
                        }
                      }
                    : null,
                icon: const Icon(Icons.save),
                label: const Text('محفوظ کریں'),
              ),
            ],
          );
        },
      ),
    );
  }

  double _sum(List<_VoucherLine> lines, bool debit) => lines.fold(
        0,
        (sum, line) =>
            sum + _amount(debit ? line.debit.text : line.credit.text),
      );

  double _amount(String value) => double.tryParse(value.trim()) ?? 0;

  Future<void> _showVoucherDetails(Map<String, dynamic> voucher) async {
    final entries = await DBHelper().getAccVoucherEntries(voucher['id'] as int);
    if (!mounted) return;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(voucher['voucher_no']?.toString() ?? 'Voucher'),
        content: SizedBox(
          width: 520,
          child: entries.isEmpty
              ? const Text('کوئی ledger entry موجود نہیں')
              : Column(
                  mainAxisSize: MainAxisSize.min,
                  children: entries
                      .map(
                        (e) => ListTile(
                          title: Text(e['account_name']?.toString() ?? '-'),
                          subtitle: Text(e['code']?.toString() ?? ''),
                          trailing: Text(
                            'Dr ${e['debit']} / Cr ${e['credit']}',
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                        ),
                      )
                      .toList(),
                ),
        ),
        actions: [
          if (voucher['status'] == 'Pending' || currentUserRole.value == 'admin')
            TextButton.icon(
              onPressed: () {
                Navigator.pop(ctx);
                _showVoucherDialog(editVoucher: voucher, editEntries: entries);
              },
              icon: const Icon(Icons.edit, color: Colors.orange),
              label: const Text('ترمیم (Edit)', style: TextStyle(color: Colors.orange)),
            ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('بند کریں'),
          )
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawer: const AppDrawer(),
      appBar: AppBar(
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            onPressed: () async {
              ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Syncing data...')));
              try {
                await SyncService.runTwoWaySync();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Sync complete')));
                }
              } catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context)
                      .showSnackBar(SnackBar(content: Text('Sync failed: $e')));
                }
              }
            },
          )
        ],
        title: const Text('واؤچرز'),
        backgroundColor: primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: primary,
        foregroundColor: Colors.white,
        onPressed: _accounts.isEmpty ? null : _showVoucherDialog,
        icon: const Icon(Icons.add),
        label: const Text('نیا واؤچر'),
      ),
      body: isLoading
          ? const Center(child: CircularProgressIndicator())
          : _accounts.isEmpty
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(20),
                    child: Text(
                      'پہلے اکاؤنٹس کا چارٹ میں اکاؤنٹس شامل کریں۔',
                      textAlign: TextAlign.center,
                    ),
                  ),
                )
              : _vouchers.isEmpty
                  ? const Center(child: Text('ابھی تک کوئی واؤچر موجود نہیں'))
                  : ListView.builder(
                      itemCount: _vouchers.length,
                      itemBuilder: (context, index) {
                        final v = _vouchers[index];
                        final amount = double.tryParse(
                                v['total_debit']?.toString() ?? '0') ??
                            0;
                        return Card(
                          margin: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 6),
                          child: ListTile(
                            leading: CircleAvatar(
                              backgroundColor: primary.withOpacity(0.12),
                              child: const Icon(Icons.receipt_long),
                            ),
                            title: Text(
                              v['voucher_no']?.toString() ?? 'Voucher',
                              style:
                                  const TextStyle(fontWeight: FontWeight.bold),
                            ),
                            subtitle: Text(
                              '${v['date'] ?? ''} • ${v['type'] ?? ''}\n${v['description'] ?? ''}',
                            ),
                            isThreeLine: true,
                            trailing: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text(
                                  'Rs ${amount.toStringAsFixed(0)}',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: Colors.green),
                                ),
                                Text(v['status']?.toString() ?? 'Pending'),
                              ],
                            ),
                            onTap: () => _showVoucherDetails(v),
                          ),
                        );
                      },
                    ),
    );
  }
}

class _VoucherLine {
  int? accountId;
  final TextEditingController debit = TextEditingController();
  final TextEditingController credit = TextEditingController();
}
