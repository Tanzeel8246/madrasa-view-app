// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import 'dart:ui' as ui;

class ComplaintsHistoryPage extends StatefulWidget {
  final int? studentId;
  const ComplaintsHistoryPage({super.key, this.studentId});

  @override
  State<ComplaintsHistoryPage> createState() => _ComplaintsHistoryPageState();
}

class _ComplaintsHistoryPageState extends State<ComplaintsHistoryPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);
  List<Map<String, dynamic>> _history = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory() async {
    final db = DBHelper();
    final data = await db.database;
    final res = await data.query('parent_complaints',
        where: widget.studentId != null ? 'student_id = ?' : null,
        whereArgs: widget.studentId != null ? [widget.studentId] : null,
        orderBy: 'date DESC');
    final students = await db.getStudentsForUser();
    final allowedStudentIds = students.map((s) => s['id']).toSet();
    final historyWithStudent = <Map<String, dynamic>>[];
    for (final item in res) {
      if (!allowedStudentIds.contains(item['student_id'])) continue;
      final student = students.firstWhere(
        (s) => s['id'] == item['student_id'],
        orElse: () => <String, dynamic>{},
      );
      final father = (student['father_name'] ?? student['father'] ?? '').toString().trim();
      final name = student['name'] ?? '';
      final fullName = father.isNotEmpty ? "$name ولد $father" : name;
      historyWithStudent.add({
        ...item,
        'student_name': fullName,
      });
    }

    setState(() {
      _history = historyWithStudent;
      _isLoading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: isUrduGlobal,
      builder: (context, isUrdu, child) {
        return Directionality(
          textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,
          child: Scaffold(
            backgroundColor: bgColor,
            appBar: AppBar(
              title: Text(
                isUrdu ? 'شکایات ہسٹری' : 'Complaints History',
                style: TextStyle(color: primaryGreen, fontWeight: FontWeight.bold),
              ),
              backgroundColor: bgColor,
              elevation: 0,
              iconTheme: IconThemeData(color: primaryGreen),
              centerTitle: true,
            ),
            body: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _history.isEmpty
                    ? Center(
                        child: Text(isUrdu ? "کوئی ریکارڈ نہیں ملا" : "No history found."),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.all(15),
                        itemCount: _history.length,
                        itemBuilder: (context, index) {
                          final h = _history[index];
                          final status = h['status'] ?? 'Pending';
                          Color statusColor = Colors.orange;
                          if (status == 'Resolved') statusColor = Colors.green;

                          return Card(
                            margin: const EdgeInsets.only(bottom: 10),
                            child: ListTile(
                              leading: const Icon(Icons.history, color: Colors.orange),
                              title: Text(h['category'] ?? '',
                                  style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  if ((h['student_name'] ?? '').toString().isNotEmpty)
                                    Text(h['student_name'].toString(),
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            color: Colors.blue,
                                            fontSize: 13)),
                                  Text(h['date'] ?? '',
                                      style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                  Text(h['description'] ?? '',
                                      maxLines: 3, overflow: TextOverflow.ellipsis),
                                ],
                              ),
                              trailing: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                decoration: BoxDecoration(
                                    color: statusColor,
                                    borderRadius: BorderRadius.circular(5)),
                                child: Text(status,
                                    style: const TextStyle(color: Colors.white, fontSize: 10)),
                              ),
                            ),
                          );
                        },
                      ),
          ),
        );
      },
    );
  }
}
