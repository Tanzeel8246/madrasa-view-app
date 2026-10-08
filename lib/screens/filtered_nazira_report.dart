import 'package:flutter/material.dart';

class FilteredNaziraReportPage extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<Map<String, dynamic>> filteredRecords;
  final List<Map<String, dynamic>> allStudents;

  const FilteredNaziraReportPage({
    super.key,
    required this.title,
    required this.subtitle,
    required this.filteredRecords,
    required this.allStudents,
  });

  String _getStudentName(String studentId) {
    var student = allStudents.firstWhere(
      (s) => s['id'].toString() == studentId,
      orElse: () => {'name': 'نامعلوم'},
    );
    return student['name'] ?? 'نامعلوم';
  }

  String _getFatherName(String studentId) {
    var student = allStudents.firstWhere(
      (s) => s['id'].toString() == studentId,
      orElse: () => {'father_name': ''},
    );
    return (student['father_name'] ?? '').toString().trim();
  }
  
  String _getRollNo(String studentId) {
    var student = allStudents.firstWhere(
      (s) => s['id'].toString() == studentId,
      orElse: () => {'roll_number': '', 'admission_number': ''},
    );
    return student['roll_number']?.toString() ?? student['admission_number']?.toString() ?? '';
  }

  String translateStatusClean(String? status) {
    if (status == null || status.isEmpty || status == 'null') return '-';
    if (status == 'Excellent') return 'ممتاز';
    if (status == 'Good') return 'بہتر';
    if (status == 'Average') return 'معمولی';
    if (status == 'Weak') return 'کمزور';
      if (status == 'Absent') return 'غیر حاضر';
      if (status == 'Leave') return 'رخصت';
    return status;
  }

  String translateQtyClean(String? qty) {
    if (qty == null || qty.isEmpty || qty == 'null') return '-';
    return qty
        .replaceAll('Lines', 'لائنیں')
        .replaceAll('Pages', 'صفحات')
        .replaceAll('Ruku', 'رکوع')
        .replaceAll('Rubah', 'پاؤ')
        .replaceAll('Nisf', 'نصف')
        .replaceAll('Suls', 'ثُلث')
        .replaceAll('Full Para', 'مکمل پارہ')
        .trim();
  }

  String cleanUrduString(String? text) {
    if (text == null || text == 'null') return '-';
    return text
        .replaceAll('(3/4)', '')
        .replaceAll('(1/2)', '')
        .replaceAll('(1/4)', '')
        .replaceAll('(1)', '')
        .replaceAll('((', '(')
        .replaceAll('))', ')')
        .replaceAll('پارہ ', '')
        .trim();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      appBar: AppBar(
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: const Color(0xFF284D3D),
      ),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            color: Colors.white,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(subtitle, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.blueAccent)),
                const SizedBox(height: 5),
                Text("کل طلبہ: ${filteredRecords.length}", style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.black87)),
              ],
            ),
          ),
          Expanded(
            child: filteredRecords.isEmpty
                ? const Center(child: Text("کوئی ریکارڈ نہیں ملا", style: TextStyle(color: Colors.red, fontSize: 16, fontWeight: FontWeight.bold)))
                : ListView.builder(
                    padding: const EdgeInsets.all(8),
                    itemCount: filteredRecords.length,
                    itemBuilder: (context, index) {
                      final h = filteredRecords[index];
                      final sid = h['student_id']?.toString() ?? '';
                      final sName = _getStudentName(sid);
                      final fName = _getFatherName(sid);
                      final rNo = _getRollNo(sid);
                      final displayName = fName.isNotEmpty ? "$sName ولد $fName" : sName;

                      return Card(
                        elevation: 2,
                        margin: const EdgeInsets.only(bottom: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Expanded(
                                    child: Text("$displayName (${rNo.isNotEmpty ? 'رول: $rNo' : ''})", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF284D3D))),
                                  ),
                                  Text(h['date'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.grey, fontSize: 12)),
                                ],
                              ),
                              const Divider(),
                              _buildRow("صبح کا سبق:", "${cleanUrduString(h['morning_sabaq'])} - ${h['morning_qty'] ?? '-'}", translateStatusClean(h['morning_status'])),
                              const SizedBox(height: 5),
                              _buildRow("شام کا سبق:", "${cleanUrduString(h['evening_sabaq'])} - ${translateQtyClean(h['evening_qty'])}", translateStatusClean(h['evening_status'])),
                              const SizedBox(height: 5),
                              _buildRow("منزل:", "${cleanUrduString(h['morning_time'])} - ${translateQtyClean(h['evening_time'])}", translateStatusClean(h['null_field'])),
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

  Widget _buildRow(String label, String detail, String status) {
    Color statusColor = Colors.black87;
    if (status == 'ممتاز' || status == 'Excellent') statusColor = Colors.green;
    if (status == 'بہتر' || status == 'Good') statusColor = Colors.blue;
    if (status == 'معمولی' || status == 'Average') statusColor = Colors.orange;
    if (status == 'کمزور' || status == 'Weak' || status == 'ناغہ' || status == 'Absent' || status == '-') statusColor = Colors.red;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 50, child: Text(label, style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.black54))),
        Expanded(child: Text(detail, style: const TextStyle(fontWeight: FontWeight.w500))),
        Text(status, style: TextStyle(fontWeight: FontWeight.bold, color: statusColor)),
      ],
    );
  }
}
