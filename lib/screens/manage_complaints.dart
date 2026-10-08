import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class ManageComplaintsPage extends StatefulWidget {
  const ManageComplaintsPage({super.key});

  @override
  State<ManageComplaintsPage> createState() => _ManageComplaintsPageState();
}

class _ManageComplaintsPageState extends State<ManageComplaintsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  List<Map<String, dynamic>> _complaints = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadComplaints();
  }

  Future<void> _loadComplaints() async {
    setState(() => _isLoading = true);
    final db = DBHelper();
    final allComplaints = await db.getComplaints();
    final students = await db.getStudentsForUser();
    final allowedStudentIds = students.map((s) => s['id']).toSet();
    
    // Join with student names
    List<Map<String, dynamic>> enriched = [];
    for (var c in allComplaints) {
      if (c['is_deleted'] == 1) continue;
      if (!allowedStudentIds.contains(c['student_id'])) continue;
      final st = students.firstWhere((s) => s['id'] == c['student_id'], orElse: () => {});
      final stName = st['name'] ?? 'Unknown';
      enriched.add({
        ...c,
        'student_name': stName,
      });
    }
    
    setState(() {
      _complaints = enriched;
      _isLoading = false;
    });
  }

  Future<void> _deleteComplaint(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'تصدیق' : 'Confirm'),
        content: Text(isUrduGlobal.value ? 'کیا آپ یہ شکایت حذف کرنا چاہتے ہیں؟' : 'Are you sure you want to delete this complaint?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(isUrduGlobal.value ? 'نہیں' : 'No')),
          ElevatedButton(onPressed: () => Navigator.pop(c, true), child: Text(isUrduGlobal.value ? 'ہاں' : 'Yes')),
        ],
      )
    );

    if (confirmed == true) {
      await DBHelper().deleteComplaint(id);
      await _loadComplaints();
    }
  }

  Future<void> _editComplaint(Map<String, dynamic> c) async {
    final complaintController = TextEditingController(text: c['complaint'].toString());

    final result = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: Text(isUrduGlobal.value ? 'ترمیم کریں' : 'Edit Complaint'),
          content: TextField(
            controller: complaintController,
            maxLines: 3,
            decoration: InputDecoration(
              labelText: isUrduGlobal.value ? 'شکایت' : 'Complaint',
              border: const OutlineInputBorder(),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(isUrduGlobal.value ? 'منسوخ کریں' : 'Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: primaryGreen, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(context, true),
              child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save'),
            ),
          ],
        );
      }
    );

    if (result == true) {
      final text = complaintController.text;
      if (text.isNotEmpty) {
        await DBHelper().updateComplaint(c['id'], {
          'complaint': text,
        });
        await _loadComplaints();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(isUrduGlobal.value ? 'شکایات کا ریکارڈ' : 'Manage Complaints'),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      drawer: const AppDrawer(),

      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _buildGroupedComplaints(),
    );
  }


  Widget _buildGroupedComplaints() {
    // Group by Date -> Category
    Map<String, Map<String, List<Map<String, dynamic>>>> grouped = {};
    for (var c in _complaints) {
      String d = c['date'] ?? '';
      String raw = c['complaint'] ?? '';
      String cat = 'General';
      String desc = raw;
      
      final match = RegExp(r'^\[(.*?)\]\s*(.*)$', dotAll: true).firstMatch(raw);
      if (match != null) {
        cat = match.group(1) ?? 'General';
        desc = match.group(2) ?? '';
      }
      
      c['_category'] = cat;
      c['_desc'] = desc;
      
      grouped.putIfAbsent(d, () => {});
      grouped[d]!.putIfAbsent(cat, () => []);
      grouped[d]![cat]!.add(c);
    }
    
    if (grouped.isEmpty) {
      return Center(child: Text(isUrduGlobal.value ? 'کوئی شکایت موجود نہیں' : 'No complaints found'));
    }
    
    // Sort dates descending
    var sortedDates = grouped.keys.toList()..sort((a, b) => b.compareTo(a));
    
    return ListView.builder(
      itemCount: sortedDates.length,
      itemBuilder: (context, idx) {
        String date = sortedDates[idx];
        var categories = grouped[date]!;
        
        return Card(
          margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          shape: RoundedRectangleBorder(
            side: const BorderSide(color: Colors.red, width: 1),
            borderRadius: BorderRadius.circular(8),
          ),
          child: ExpansionTile(
            initiallyExpanded: idx == 0,
            collapsedBackgroundColor: Colors.red,
            backgroundColor: Colors.white,
            iconColor: primaryGreen,
            collapsedIconColor: Colors.white,
            title: Text(
              isUrduGlobal.value ? 'تاریخ: $date' : 'Date: $date',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                color: idx == 0 ? Colors.black : Colors.white,
              ),
            ),
            children: categories.entries.map((catEntry) {
              String catName = catEntry.key;
              List<Map<String, dynamic>> comps = catEntry.value;
              
              return Padding(
                padding: const EdgeInsets.all(8.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      catName,
                      style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.orange, fontSize: 16),
                    ),
                    const Divider(),
                    SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DataTable(
                        columnSpacing: 15,
                        headingRowColor: WidgetStateProperty.all(Colors.grey[200]),
                        columns: [
                          DataColumn(label: Text(isUrduGlobal.value ? 'طالب علم' : 'Student')),
                          DataColumn(label: Text(isUrduGlobal.value ? 'تفصیل' : 'Details')),
                          DataColumn(label: Text(isUrduGlobal.value ? 'ایکشن' : 'Action')),
                        ],
                        rows: comps.map((c) {
                          return DataRow(cells: [
                            DataCell(Text(c['student_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.blue))),
                            DataCell(SizedBox(
                              width: 150,
                              child: Text(c['_desc'] ?? '', softWrap: true),
                            )),
                            DataCell(Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                IconButton(
                                  icon: const Icon(Icons.edit, color: Colors.blue, size: 20),
                                  onPressed: () => _editComplaint(c),
                                  constraints: const BoxConstraints(),
                                  padding: const EdgeInsets.all(4),
                                ),
                                IconButton(
                                  icon: const Icon(Icons.delete, color: Colors.red, size: 20),
                                  onPressed: () => _deleteComplaint(c['id']),
                                  constraints: const BoxConstraints(),
                                  padding: const EdgeInsets.all(4),
                                ),
                              ],
                            )),
                          ]);
                        }).toList(),
                      ),
                    ),
                    const SizedBox(height: 10),
                  ],
                ),
              );
            }).toList(),
          ),
        );
      },
    );
  }

}
