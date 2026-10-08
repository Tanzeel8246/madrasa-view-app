import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';

class MasjidItikafPage extends StatefulWidget {
  const MasjidItikafPage({super.key});
  @override
  _MasjidItikafPageState createState() => _MasjidItikafPageState();
}

class _MasjidItikafPageState extends State<MasjidItikafPage> {
  List<Map<String, dynamic>> _items = [];
  bool _isLoading = true;
  

  final TextEditingController _nameController = TextEditingController();
  final TextEditingController _phoneController = TextEditingController();
  final TextEditingController _yearController = TextEditingController();
  final TextEditingController _statusController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _refreshItems();
  }

  Future<void> _refreshItems() async {
    setState(() => _isLoading = true);
    try {
      final db = await DBHelper().database;
      final data = await db.query('masjid_itikaf', where: 'is_deleted = ?', whereArgs: [0]);
      setState(() => _items = data);
    } catch (e) {
      debugPrint('Error: $e');
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showAddDialog([Map<String, dynamic>? item]) {
    if (item != null) {
      _nameController.text = item['name']?.toString() ?? '';
      _phoneController.text = item['phone']?.toString() ?? '';
      _yearController.text = item['year']?.toString() ?? '';
      _statusController.text = item['status']?.toString() ?? '';
    } else {
      _nameController.clear();
  _phoneController.clear();
  _yearController.clear();
  _statusController.clear();
    }

    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(item == null ? (isUrduGlobal.value ? 'نیا اعتکاف ریکارڈ' : 'Add Itikaf Record') : (isUrduGlobal.value ? 'ترمیم اعتکاف ریکارڈ' : 'Edit Itikaf Record')),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              
              TextField(
                controller: _nameController,
                decoration: InputDecoration(labelText: isUrduGlobal.value ? 'نام' : 'Name'),
              ),
              TextField(
                controller: _phoneController,
                decoration: InputDecoration(labelText: 'فون'),
              ),
              TextField(
                controller: _yearController,
                decoration: InputDecoration(labelText: isUrduGlobal.value ? 'سال' : 'Year'),
              ),
              TextField(
                controller: _statusController,
                decoration: InputDecoration(labelText: isUrduGlobal.value ? 'سٹیٹس' : 'Status'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(isUrduGlobal.value ? 'منسوخ کریں' : 'Cancel'),
          ),
          ElevatedButton(
            onPressed: () async {
              final row = {
                'name': _nameController.text, 'phone': _phoneController.text, 'year': _yearController.text, 'status': _statusController.text,
                'is_synced': 0,
              };
              if (item != null) {
                final db = await DBHelper().database;
                await db.update('masjid_itikaf', row, where: 'id = ?', whereArgs: [item['id']]);
              } else {
                final db = await DBHelper().database;
                await db.insert('masjid_itikaf', row);
              }
              if (mounted) Navigator.pop(context);
              _refreshItems();
            },
            child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save'),
          ),
        ],
      ),
    );
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _yearController.dispose();
    _statusController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(isUrduGlobal.value ? 'اعتکاف ریکارڈ' : 'Itikaf Record'),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _showAddDialog(),
        child: const Icon(Icons.add),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columns: [
                  DataColumn(label: Text(isUrduGlobal.value ? 'نام' : 'Name')),DataColumn(label: Text('فون')),DataColumn(label: Text(isUrduGlobal.value ? 'سال' : 'Year')),DataColumn(label: Text(isUrduGlobal.value ? 'سٹیٹس' : 'Status')),
                  DataColumn(label: Text(isUrduGlobal.value ? 'ایکشن' : 'Action')),
                ],
                rows: _items.map((item) => DataRow(
                  cells: [
                    DataCell(Text('${item['name'] ?? ''} ')),DataCell(Text('${item['phone'] ?? ''} ')),DataCell(Text('${item['year'] ?? ''} ')),DataCell(Text('${item['status'] ?? ''} ')),
                    DataCell(Row(
                      children: [
                        IconButton(
                          icon: const Icon(Icons.edit, color: Colors.blue),
                          onPressed: () => _showAddDialog(item),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.red),
                          onPressed: () async {
                            final db = await DBHelper().database;
                            await db.update('masjid_itikaf', {'is_deleted': 1, 'is_synced': 0}, where: 'id = ?', whereArgs: [item['id']]);
                            _refreshItems();
                          },
                        ),
                      ],
                    )),
                  ],
                )).toList(),
              ),
            ),
    );
  }
}
