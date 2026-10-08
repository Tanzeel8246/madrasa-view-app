// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';
import '../widgets/neo_box.dart';
import '../db_helper.dart';
import 'dashboard.dart';
import 'package:intl/intl.dart' as intl;
import 'dart:ui' as ui;
import '../utils/sync_service.dart';

class NoticesPage extends StatefulWidget {
  const NoticesPage({super.key});

  @override
  State<NoticesPage> createState() => _NoticesPageState();
}

class _NoticesPageState extends State<NoticesPage> {
  final Color bgColor = const Color(0xFFF1EFE7);
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color textDark = const Color(0xFF2D2D2D);
  final Color dangerColor = const Color(0xFFDC3545);

  final _titleController = TextEditingController();
  final _messageController = TextEditingController();
  final _dateController = TextEditingController(
      text: intl.DateFormat('yyyy-MM-dd').format(DateTime.now()));

  List<Map<String, dynamic>> _notices = [];
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _loadNotices();
  }

  void _loadNotices() async {
    final data = await DBHelper().getNotices();
    setState(() {
      _notices = data;
    });
  }

  void _addNotice() async {
    if (_isSaving) return;
    if (_titleController.text.trim().isEmpty ||
        _messageController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(isUrduGlobal.value
                ? "عنوان اور تفصیل درکار ہیں"
                : "Title & Message required")),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      await DBHelper().insertNotice({
        'title': _titleController.text.trim(),
        'message': _messageController.text.trim(),
        'date': _dateController.text,
      });

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                isUrduGlobal.value ? "اعلان لگا دیا گیا!" : "Notice Added!")),
      );

      _titleController.clear();
      _messageController.clear();
      _loadNotices();
      SyncService.runTwoWaySync().then((_) {
        if (mounted) _loadNotices();
      }).catchError((_) {});
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? "اعلان محفوظ نہیں ہو سکا: $e"
              : "Notice could not be saved: $e"),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _deleteNotice(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'حذف کی تصدیق' : 'Confirm Delete'),
        content: Text(isUrduGlobal.value
            ? 'کیا آپ یہ اعلان حذف کرنا چاہتے ہیں؟'
            : 'Do you want to delete this notice?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(isUrduGlobal.value ? 'نہیں' : 'No'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(backgroundColor: dangerColor),
            onPressed: () => Navigator.pop(context, true),
            icon: const Icon(Icons.delete, color: Colors.white),
            label: Text(
              isUrduGlobal.value ? 'حذف کریں' : 'Delete',
              style: const TextStyle(color: Colors.white),
            ),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    await DBHelper().deleteNotice(id);
    _loadNotices();
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return PopScope(
          canPop: false,
          onPopInvokedWithResult: (didPop, result) {
            if (didPop) return;
            Navigator.pushReplacement(context,
                MaterialPageRoute(builder: (c) => const DashboardPage()));
          },
          child: Directionality(
            textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
            child: Scaffold(
              backgroundColor: bgColor,
              drawer: const AppDrawer(),
              appBar: AppBar(
                leading: Builder(
                  builder: (context) => IconButton(
                    icon: Icon(Icons.menu, color: primaryGreen),
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  ),
                ),
                title: Text(isUrdu ? "نوٹس بورڈ (اعلانات)" : "Notice Board",
                    style: TextStyle(
                        color: primaryGreen, fontWeight: FontWeight.bold)),
                backgroundColor: bgColor,
                elevation: 0,
                iconTheme: IconThemeData(color: primaryGreen),
                centerTitle: true,
                actions: [
                  IconButton(
                    icon: Icon(Icons.sync, color: primaryGreen),
                    onPressed: () async {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrdu
                              ? "ڈیٹا سنک ہو رہا ہے..."
                              : "Syncing data...")));
                      try {
                        await SyncService.runTwoWaySync();
                        _loadNotices();
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(
                                isUrdu ? "سنک مکمل ہو گیا!" : "Sync complete!"),
                            backgroundColor: Colors.green));
                      } catch (e) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                            content: Text(
                                isUrdu ? "سنک میں خرابی آئی" : "Sync failed"),
                            backgroundColor: Colors.red));
                      }
                    },
                  ),
                ],
              ),
              body: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1100),
                  child: Padding(
                    padding: const EdgeInsets.all(15.0),
                    child: Column(
                  children: [
                    NeoBox(
                      padding: 15,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              Icon(Icons.campaign, color: dangerColor),
                              const SizedBox(width: 10),
                              Text(
                                isUrdu ? "نیا اعلان (Notice)" : "New Notice",
                                style: TextStyle(
                                    fontSize: 18,
                                    color: dangerColor,
                                    fontWeight: FontWeight.bold),
                              )
                            ],
                          ),
                          const SizedBox(height: 15),
                          TextField(
                            controller: _titleController,
                            decoration: InputDecoration(
                              labelText: isUrdu ? "عنوان (Title)" : "Title",
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _dateController,
                            readOnly: true,
                            onTap: () async {
                              DateTime? picked = await showDatePicker(
                                context: context,
                                initialDate: DateTime.now(),
                                firstDate: DateTime(2000),
                                lastDate: DateTime(2100),
                              );
                              if (picked != null) {
                                setState(() {
                                  _dateController.text =
                                      intl.DateFormat('yyyy-MM-dd')
                                          .format(picked);
                                });
                              }
                            },
                            decoration: InputDecoration(
                              labelText: isUrdu ? "تاریخ" : "Date",
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: _messageController,
                            maxLines: 3,
                            decoration: InputDecoration(
                              labelText: isUrdu ? "تفصیل (Message)" : "Message",
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 15),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: dangerColor,
                            ),
                            onPressed: _isSaving ? null : _addNotice,
                            icon: _isSaving
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white,
                                    ),
                                  )
                                : const Icon(Icons.push_pin,
                                    color: Colors.white),
                            label: Text(
                              _isSaving
                                  ? (isUrdu
                                      ? "محفوظ ہو رہا ہے..."
                                      : "Saving...")
                                  : (isUrdu
                                      ? "نوٹس بورڈ پر لگائیں"
                                      : "Post Notice"),
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 15),
                    Expanded(
                      child: NeoBox(
                        padding: 10,
                        child: _notices.isEmpty
                            ? Center(
                                child: Text(
                                    isUrdu ? "کوئی اعلان نہیں" : "No notices"))
                            : ListView.builder(
                                itemCount: _notices.length,
                                itemBuilder: (context, index) {
                                  final n = _notices[index];
                                  return Card(
                                    color: Colors.white,
                                    margin: const EdgeInsets.only(bottom: 10),
                                    child: ListTile(
                                      title: Text(
                                        n['title'],
                                        style: TextStyle(
                                            fontWeight: FontWeight.bold,
                                            color: dangerColor),
                                      ),
                                      subtitle: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          const SizedBox(height: 5),
                                          Text(n['message']),
                                          const SizedBox(height: 5),
                                          Text(
                                            n['date'],
                                            style: const TextStyle(
                                                fontSize: 12,
                                                color: Colors.grey),
                                          ),
                                        ],
                                      ),
                                      trailing: IconButton(
                                        icon: const Icon(Icons.delete,
                                            color: Colors.red),
                                        onPressed: () => _deleteNotice(n['id']),
                                      ),
                                    ),
                                  );
                                },
                              ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
            ),
          ),
        );
      },
    );
  }
}
