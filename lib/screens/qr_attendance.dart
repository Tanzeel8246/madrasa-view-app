// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart' as intl;
import '../db_helper.dart';
import '../utils/globals.dart';
import '../widgets/app_drawer.dart';

class QrAttendancePage extends StatefulWidget {
  const QrAttendancePage({super.key});

  @override
  State<QrAttendancePage> createState() => _QrAttendancePageState();
}

class _QrAttendancePageState extends State<QrAttendancePage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  final TextEditingController _inputController = TextEditingController();
  List<Map<String, dynamic>> _todayScannedStudents = [];
  Map<String, dynamic>? _lastScannedStudent;
  bool _isLoading = false;
  String _statusMessage = '';
  Color _statusColor = Colors.green;

  @override
  void initState() {
    super.initState();
    _loadTodayAttendance();
  }

  Future<void> _loadTodayAttendance() async {
    try {
      final db = DBHelper();
      final database = await db.database;
      final todayStr = intl.DateFormat('yyyy-MM-dd').format(DateTime.now());

      final attRows = await database.rawQuery(
        "SELECT a.*, s.name, s.father_name, s.class, s.admission_number, s.photo "
        "FROM attendance a "
        "LEFT JOIN students s ON a.student_id = s.id "
        "WHERE a.date = ? AND (a.status_1 = 'P' OR a.status = 'Present') "
        "ORDER BY a.id DESC",
        [todayStr],
      );

      setState(() {
        _todayScannedStudents = List<Map<String, dynamic>>.from(attRows);
      });
    } catch (e) {
      debugPrint("Load Today QR Attendance Error: $e");
    }
  }

  Future<void> _processQrCode(String code) async {
    final rawCode = code.trim();
    if (rawCode.isEmpty) return;

    setState(() {
      _isLoading = true;
      _statusMessage = '';
    });

    try {
      final db = DBHelper();
      final database = await db.database;
      final todayStr = intl.DateFormat('yyyy-MM-dd').format(DateTime.now());

      int? studentId;
      String? admNo;

      if (rawCode.startsWith('STD:')) {
        final parts = rawCode.split(':');
        if (parts.length >= 2) {
          studentId = int.tryParse(parts[1]);
        }
        if (parts.length >= 3) {
          admNo = parts[2];
        }
      } else {
        studentId = int.tryParse(rawCode);
        admNo = rawCode;
      }

      // Find student in DB
      List<Map<String, dynamic>> stdResult = [];
      if (studentId != null && studentId > 0) {
        stdResult = await database.query('students',
            where: 'id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [studentId]);
      }
      if (stdResult.isEmpty && admNo != null && admNo.isNotEmpty) {
        stdResult = await database.query('students',
            where: 'admission_number = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
            whereArgs: [admNo]);
      }

      if (stdResult.isEmpty) {
        // Feedback fail
        HapticFeedback.vibrate();
        setState(() {
          _statusMessage = '❌ طالب علم کا ریکارڈ نہیں ملا: $rawCode';
          _statusColor = Colors.red;
          _isLoading = false;
        });
        return;
      }

      final student = stdResult.first;
      final targetId = student['id'];

      // Mark Attendance
      final existing = await database.query('attendance',
          where: 'student_id = ? AND date = ?',
          whereArgs: [targetId, todayStr]);

      if (existing.isEmpty) {
        await database.insert('attendance', {
          'student_id': targetId,
          'date': todayStr,
          'status_1': 'P',
          'status_2': 'P',
          'status_3': 'P',
          'status_4': 'P',
          'status': 'Present',
          'is_synced': 0,
        });
      } else {
        await database.update(
          'attendance',
          {
            'status_1': 'P',
            'status': 'Present',
            'is_synced': 0,
          },
          where: 'student_id = ? AND date = ?',
          whereArgs: [targetId, todayStr],
        );
      }

      // Audio & Haptic Feedback
      SystemSound.play(SystemSoundType.click);
      HapticFeedback.mediumImpact();

      setState(() {
        _lastScannedStudent = student;
        _statusMessage = '✅ حاضری لگ گئی: ${student['name']} (${student['class']})';
        _statusColor = Colors.green;
        _inputController.clear();
      });

      _loadTodayAttendance();
    } catch (e) {
      debugPrint("QR Attendance Error: $e");
      setState(() {
        _statusMessage = 'خرابی: $e';
        _statusColor = Colors.red;
      });
    } finally {
      setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Scaffold(
      backgroundColor: bgColor,
      drawer: const AppDrawer(),
      appBar: AppBar(
        title: Text(
          isUrdu ? 'QR کوڈ اسکینر حاضری' : 'QR Attendance Scanner',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            // Scanner / Input Card
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              elevation: 4,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    Container(
                      height: 120,
                      decoration: BoxDecoration(
                        color: primaryGreen.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: primaryGreen.withOpacity(0.3), width: 2),
                      ),
                      child: Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.qr_code_scanner, size: 48, color: primaryGreen),
                            const SizedBox(height: 6),
                            Text(
                              isUrdu
                                  ? 'طالب علم کا QR کوڈ یا داخلہ نمبر درج کریں'
                                  : 'Scan QR Code or Enter Admission Number',
                              style: TextStyle(
                                  color: primaryGreen, fontWeight: FontWeight.bold, fontSize: 13),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 14),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _inputController,
                            autofocus: true,
                            decoration: InputDecoration(
                              hintText: isUrdu ? 'داخلہ نمبر یا QR ڈیٹا...' : 'Admission No / QR...',
                              prefixIcon: const Icon(Icons.qr_code),
                              isDense: true,
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            onSubmitted: _processQrCode,
                          ),
                        ),
                        const SizedBox(width: 8),
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                          onPressed: () => _processQrCode(_inputController.text),
                          child: _isLoading
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                              : Text(isUrdu ? 'حاضری لگائیں' : 'Mark'),
                        ),
                      ],
                    ),
                    if (_statusMessage.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: _statusColor.withOpacity(0.12),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: _statusColor),
                        ),
                        child: Text(
                          _statusMessage,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                              color: _statusColor, fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Last Scanned Student Preview Card
            if (_lastScannedStudent != null) ...[
              Card(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                color: Colors.white,
                elevation: 3,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: primaryGreen.withOpacity(0.1),
                        backgroundImage: (_lastScannedStudent!['photo'] != null &&
                                _lastScannedStudent!['photo'].toString().isNotEmpty)
                            ? NetworkImage(_lastScannedStudent!['photo'])
                            : null,
                        child: (_lastScannedStudent!['photo'] == null ||
                                _lastScannedStudent!['photo'].toString().isEmpty)
                            ? Icon(Icons.person, size: 32, color: primaryGreen)
                            : null,
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _lastScannedStudent!['name'] ?? '',
                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              'ولدیت: ${_lastScannedStudent!['father_name'] ?? ''} | درجہ: ${_lastScannedStudent!['class'] ?? ''}',
                              style: const TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                            Text(
                              'داخلہ نمبر: ${_lastScannedStudent!['admission_number'] ?? ''}',
                              style: TextStyle(fontSize: 12, color: primaryGreen, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.check_circle, color: Colors.green, size: 32),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
            ],

            // Today's Scanned List
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              elevation: 2,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          isUrdu ? 'آج کی QR حاضری لسٹ' : 'Today QR Attendance',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                          decoration: BoxDecoration(
                            color: Colors.green.shade50,
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: Colors.green),
                          ),
                          child: Text(
                            'کل حاضر: ${_todayScannedStudents.length}',
                            style: TextStyle(
                                color: Colors.green.shade800, fontWeight: FontWeight.bold, fontSize: 12),
                          ),
                        ),
                      ],
                    ),
                    const Divider(height: 20),
                    if (_todayScannedStudents.isEmpty)
                      Center(
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(
                            isUrdu ? 'آج ابھی تک کوئی حاضری اسکین نہیں ہوئی' : 'No attendance scanned yet today',
                            style: const TextStyle(color: Colors.grey),
                          ),
                        ),
                      )
                    else
                      ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: _todayScannedStudents.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final item = _todayScannedStudents[index];
                          return ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: CircleAvatar(
                              radius: 16,
                              backgroundColor: primaryGreen,
                              child: Text(
                                '${index + 1}',
                                style: const TextStyle(color: Colors.white, fontSize: 11),
                              ),
                            ),
                            title: Text(
                              '${item['name'] ?? 'طالب علم'} (${item['class'] ?? ''})',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                            ),
                            subtitle: Text('داخلہ نمبر: ${item['admission_number'] ?? item['student_id']}'),
                            trailing: const Text(
                              'حاضر',
                              style: TextStyle(color: Colors.green, fontWeight: FontWeight.bold, fontSize: 13),
                            ),
                          );
                        },
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
