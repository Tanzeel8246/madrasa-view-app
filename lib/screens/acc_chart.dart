import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/sync_service.dart';

class AccChartPage extends StatefulWidget {
  const AccChartPage({super.key});

  @override
  _AccChartPageState createState() => _AccChartPageState();
}

class _AccChartPageState extends State<AccChartPage> {
  List<Map<String, dynamic>> _heads = [];
  bool isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadHeads();
  }

  Future<void> _loadHeads() async {
    final db = DBHelper();
    final heads = await db.getHeads();
    setState(() {
      _heads = heads;
      isLoading = false;
    });
  }

  Future<void> _showAddHeadDialog({Map<String, dynamic>? head}) async {
    final nameController =
        TextEditingController(text: head?['name']?.toString() ?? '');
    showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: Text(
              head == null ? 'نیا اکاؤنٹ ہیڈ بنائیں' : 'اکاؤنٹ ہیڈ ایڈٹ کریں'),
          content: TextField(
            controller: nameController,
            decoration: const InputDecoration(labelText: 'اکاؤنٹ کا نام'),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('کینسل'),
            ),
            ElevatedButton(
              onPressed: () async {
                if (nameController.text.trim().isNotEmpty) {
                  if (head == null) {
                    await DBHelper()
                        .insertHead({'name': nameController.text.trim()});
                  } else {
                    await DBHelper().updateHead(
                        head['id'], {'name': nameController.text.trim()});
                  }
                  if (context.mounted) {
                    Navigator.pop(context);
                    _loadHeads();
                    try {
                      await SyncService.runTwoWaySync();
                    } catch (_) {}
                  }
                }
              },
              child: const Text('محفوظ کریں'),
            ),
          ],
        );
      },
    );
  }

  Future<void> _deleteHead(int id) async {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text("ڈیلیٹ کریں؟"),
        content:
            const Text("کیا آپ واقعی اس اکاؤنٹ ہیڈ کو ڈیلیٹ کرنا چاہتے ہیں؟"),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx), child: const Text("کینسل")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              await DBHelper().deleteHead(id);
              if (context.mounted) {
                Navigator.pop(ctx);
                _loadHeads();
                try {
                  await SyncService.runTwoWaySync();
                } catch (_) {}
              }
            },
            child:
                const Text("ڈیلیٹ کریں", style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('چارٹ آف اکاؤنٹس'),
        backgroundColor: const Color(0xFF284D3D),
        foregroundColor: Colors.white,
      ),
      body: isLoading
          ? const Center(child: CircularProgressIndicator())
          : ListView.builder(
              itemCount: _heads.length,
              itemBuilder: (context, index) {
                final head = _heads[index];
                return Card(
                  margin:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  child: ListTile(
                    leading: const CircleAvatar(
                      backgroundColor: Color(0xFF284D3D),
                      child: Icon(Icons.account_tree, color: Colors.white),
                    ),
                    title: Text(head['name'] ?? '',
                        style: const TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text('Head ID: ${head['id']}'),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          icon: const Icon(Icons.edit, color: Colors.blue),
                          onPressed: () => _showAddHeadDialog(head: head),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.red),
                          onPressed: () => _deleteHead(head['id']),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
      floatingActionButton: FloatingActionButton(
        backgroundColor: const Color(0xFF284D3D),
        onPressed: () => _showAddHeadDialog(),
        child: const Icon(Icons.add, color: Colors.white),
      ),
    );
  }
}
