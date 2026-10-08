// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/globals.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class TeacherLeavesScreen extends StatefulWidget {
  const TeacherLeavesScreen({super.key});

  @override
  State<TeacherLeavesScreen> createState() => _TeacherLeavesScreenState();
}

class _TeacherLeavesScreenState extends State<TeacherLeavesScreen> {
  final DBHelper _dbHelper = DBHelper();
  bool _isLoading = true;
  List<Map<String, dynamic>> _leaves = [];
  List<Map<String, dynamic>> _teachers = [];
  Map<int, String> _teacherNames = {};

  int? _filterTeacherId;
  String? _filterStatus; // 'All', 'Pending', 'Approved', 'Rejected'

  bool get _canManage =>
      currentUserRole.value == 'admin' ||
      currentUserRole.value == 'nazim' ||
      currentUserRole.value == 'superadmin';

  bool get _isTeacher => currentUserRole.value == 'teacher';

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final db = await _dbHelper.database;

    final sysVal = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sysVal == 'Masjid';

    // Load Teachers based on mode
    List<Map<String, dynamic>> tRows = [];
    if (isMasjid) {
      tRows = await _dbHelper.getMaktabTeachers();
    } else {
      tRows = await db.query(
        'teachers',
        where: '(is_deleted = 0 OR is_deleted IS NULL) AND (system_type = \'Madrasa\' OR system_type IS NULL OR system_type = \'\')',
        orderBy: 'name ASC',
      );
    }

    final Map<int, String> tMap = {};
    for (var t in tRows) {
      final id = t['id'] as int?;
      if (id != null) {
        tMap[id] = t['name']?.toString() ?? 'استاذ';
      }
    }

    // Identify teacher if logged in
    int? currentTeacherId;
    if (_isTeacher) {
      final uid = currentUserId.value;
      if (uid != null) {
        final match = tRows.where((t) => t['id'] == uid).toList();
        if (match.isNotEmpty) {
          currentTeacherId = uid;
        } else if (tRows.isNotEmpty) {
          currentTeacherId = tRows.first['id'] as int?;
        }
      }
    }

    // Load Leaves for the active mode
    final effectiveTeacherId = _isTeacher ? currentTeacherId : _filterTeacherId;
    final lRows = await _dbHelper.getTeacherLeaves(
      teacherId: effectiveTeacherId,
      status: (_filterStatus != null && _filterStatus != 'All') ? _filterStatus : null,
      systemType: sysVal,
    );

    if (mounted) {
      setState(() {
        _teachers = tRows;
        _teacherNames = tMap;
        _leaves = lRows;
        _isLoading = false;
      });
    }
  }

  // Summary Counts
  int get _totalCount => _leaves.length;
  int get _pendingCount => _leaves.where((l) => l['status'] == 'Pending').length;
  int get _approvedCount => _leaves.where((l) => l['status'] == 'Approved').length;
  int get _rejectedCount => _leaves.where((l) => l['status'] == 'Rejected').length;

  void _showAddLeaveDialog() {
    int? selectedTeacherId = _isTeacher
        ? (currentUserId.value ?? (_teachers.isNotEmpty ? _teachers.first['id'] as int? : null))
        : (_filterTeacherId ?? (_teachers.isNotEmpty ? _teachers.first['id'] as int? : null));

    String selectedLeaveType = 'اتفاقی (Casual)';
    DateTime startDate = DateTime.now();
    DateTime endDate = DateTime.now();
    final reasonCtrl = TextEditingController();
    bool autoApprove = _canManage;

    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (dialogContext, setDialogState) {
            return Directionality(
              textDirection: TextDirection.rtl,
              child: AlertDialog(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                title: Row(
                  children: [
                    const Icon(Icons.edit_calendar, color: Color(0xFF284D3D)),
                    const SizedBox(width: 8),
                    const Text('نئی رخصت کا اندراج', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  ],
                ),
                content: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Teacher Dropdown
                      if (!_isTeacher) ...[
                        const Text('استاذ منتخب کریں:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                        const SizedBox(height: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12),
                          decoration: BoxDecoration(
                            border: Border.all(color: Colors.grey.shade300),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: DropdownButtonHideUnderline(
                            child: DropdownButton<int>(
                              value: selectedTeacherId,
                              isExpanded: true,
                              hint: const Text('استاذ کا انتخاب کریں'),
                              items: _teachers.map((t) {
                                return DropdownMenuItem<int>(
                                  value: t['id'] as int?,
                                  child: Text(t['name']?.toString() ?? ''),
                                );
                              }).toList(),
                              onChanged: (v) {
                                setDialogState(() => selectedTeacherId = v);
                              },
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                      ],

                      // Leave Type Dropdown
                      const Text('نوعیتِ رخصت:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                      const SizedBox(height: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(
                          border: Border.all(color: Colors.grey.shade300),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: DropdownButtonHideUnderline(
                          child: DropdownButton<String>(
                            value: selectedLeaveType,
                            isExpanded: true,
                            items: const [
                              DropdownMenuItem(value: 'اتفاقی (Casual)', child: Text('اتفاقی رخصت (Casual)')),
                              DropdownMenuItem(value: 'طبی / بیماری (Medical)', child: Text('طبی رخصت (Medical)')),
                              DropdownMenuItem(value: 'ضروری کام (Personal)', child: Text('ضروری کام (Personal)')),
                              DropdownMenuItem(value: 'حج / عمرہ (Pilgrimage)', child: Text('حج / عمرہ (Pilgrimage)')),
                              DropdownMenuItem(value: 'دیگر (Other)', child: Text('دیگر (Other)')),
                            ],
                            onChanged: (v) {
                              if (v != null) setDialogState(() => selectedLeaveType = v);
                            },
                          ),
                        ),
                      ),
                      const SizedBox(height: 14),

                      // Dates Row
                      Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('تاریخ (سے):', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                const SizedBox(height: 6),
                                InkWell(
                                  onTap: () async {
                                    final picked = await showDatePicker(
                                      context: dialogContext,
                                      initialDate: startDate,
                                      firstDate: DateTime(2020),
                                      lastDate: DateTime(2035),
                                    );
                                    if (picked != null) {
                                      setDialogState(() {
                                        startDate = picked;
                                        if (endDate.isBefore(startDate)) endDate = startDate;
                                      });
                                    }
                                  },
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                                    decoration: BoxDecoration(
                                      border: Border.all(color: Colors.grey.shade300),
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.calendar_today, size: 16, color: Color(0xFF284D3D)),
                                        const SizedBox(width: 6),
                                        Text(startDate.toIso8601String().substring(0, 10), style: const TextStyle(fontSize: 12)),
                                      ],
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('تاریخ (تک):', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                const SizedBox(height: 6),
                                InkWell(
                                  onTap: () async {
                                    final picked = await showDatePicker(
                                      context: dialogContext,
                                      initialDate: endDate.isBefore(startDate) ? startDate : endDate,
                                      firstDate: startDate,
                                      lastDate: DateTime(2035),
                                    );
                                    if (picked != null) {
                                      setDialogState(() => endDate = picked);
                                    }
                                  },
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                                    decoration: BoxDecoration(
                                      border: Border.all(color: Colors.grey.shade300),
                                      borderRadius: BorderRadius.circular(10),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.calendar_today, size: 16, color: Color(0xFF284D3D)),
                                        const SizedBox(width: 6),
                                        Text(endDate.toIso8601String().substring(0, 10), style: const TextStyle(fontSize: 12)),
                                      ],
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),

                      // Reason
                      const Text('وجہ / تفصیل:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                      const SizedBox(height: 6),
                      TextField(
                        controller: reasonCtrl,
                        maxLines: 2,
                        decoration: InputDecoration(
                          hintText: 'چھٹی کا عذر یا تفصیل لکھیں...',
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                          contentPadding: const EdgeInsets.all(10),
                        ),
                      ),
                      const SizedBox(height: 12),

                      // Auto-approve switch for management
                      if (_canManage)
                        SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          title: const Text('فوری منظور کریں مع خودکار حاضری', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                          value: autoApprove,
                          activeColor: const Color(0xFF284D3D),
                          onChanged: (val) {
                            setDialogState(() => autoApprove = val);
                          },
                        ),
                    ],
                  ),
                ),
                actions: [
                  TextButton(
                    onPressed: () => Navigator.pop(dialogContext),
                    child: const Text('منسوخ کریں', style: TextStyle(color: Colors.grey)),
                  ),
                  ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF284D3D),
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                    icon: const Icon(Icons.save, size: 18),
                    label: const Text('محفوظ کریں'),
                    onPressed: () async {
                      if (selectedTeacherId == null || selectedTeacherId! <= 0) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('براہ کرم استاذ منتخب کریں!')),
                        );
                        return;
                      }

                      final startStr = startDate.toIso8601String().substring(0, 10);
                      final endStr = endDate.toIso8601String().substring(0, 10);
                      final status = autoApprove ? 'Approved' : 'Pending';

                      await _dbHelper.insertTeacherLeave({
                        'teacher_id': selectedTeacherId,
                        'leave_type': selectedLeaveType,
                        'start_date': startStr,
                        'end_date': endStr,
                        'reason': reasonCtrl.text.trim(),
                        'status': status,
                        'approved_by': autoApprove ? (currentUserName.value ?? 'ناظم') : null,
                        'system_type': SystemTypeGlobal.currentSystemType.value,
                      });

                      Navigator.pop(dialogContext);
                      _loadData();
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(content: Text(autoApprove ? 'رخصت منظور کر کے حاضری میں خودکار اندراج ہو گیا!' : 'رخصت کی درخواست جمع ہو گئی!')),
                      );
                    },
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  Future<void> _updateStatus(int leaveId, String newStatus) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          title: Text(newStatus == 'Approved' ? 'منظوری کی تصدیق' : 'مسترد کرنے کی تصدیق'),
          content: Text(newStatus == 'Approved'
              ? 'کیا آپ واقعی یہ رخصت منظور کرنا چاہتے ہیں؟ اساتذہ کے حاضری رجسٹر میں اس تاریخ پر خودکار رخصت (L) درج ہو جائے گی۔'
              : 'کیا آپ واقعی یہ درخواست مسترد کرنا چاہتے ہیں؟'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: newStatus == 'Approved' ? Colors.green : Colors.red,
                foregroundColor: Colors.white,
              ),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(newStatus == 'Approved' ? 'منظور کریں' : 'مسترد کریں'),
            ),
          ],
        ),
      ),
    );

    if (confirm == true) {
      final approver = currentUserName.value ?? 'ناظم';
      await _dbHelper.updateTeacherLeaveStatus(leaveId, newStatus, approvedBy: approver);
      _loadData();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(newStatus == 'Approved' ? 'رخصت منظور اور حاضری اپڈیٹ ہو گئی!' : 'رخصت مسترد کر دی گئی!'),
          backgroundColor: newStatus == 'Approved' ? Colors.green : Colors.red,
        ),
      );
    }
  }

  Future<void> _deleteLeave(int leaveId) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          title: const Text('حذف کی تصدیق'),
          content: const Text('کیا آپ واقعی یہ رخصت ریکارڈ حذف کرنا چاہتے ہیں؟'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('منسوخ'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('حذف کریں'),
            ),
          ],
        ),
      ),
    );

    if (confirm == true) {
      await _dbHelper.deleteTeacherLeave(leaveId);
      _loadData();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('ریکارڈ حذف کر دیا گیا!')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('اساتذہ رخصت نظام', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
          backgroundColor: const Color(0xFF284D3D),
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh),
              tooltip: 'تازہ کریں',
              onPressed: _loadData,
            ),
          ],
        ),
        drawer: const AppDrawer(),
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: const Color(0xFF284D3D),
          foregroundColor: Colors.white,
          onPressed: _showAddLeaveDialog,
          icon: const Icon(Icons.add),
          label: const Text('نئی رخصت'),
        ),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1100),
                  child: RefreshIndicator(
                    onRefresh: _loadData,
                    child: ListView(
                  padding: const EdgeInsets.all(12),
                  children: [
                    // Top Stat Cards
                    _buildStatsGrid(),
                    const SizedBox(height: 12),

                    // Filters Card
                    _buildFiltersCard(),
                    const SizedBox(height: 12),

                    // List Header
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'درخواستوں کی فہرست',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF284D3D)),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade200,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text('کل: ${_leaves.length}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    // Leave Items
                    if (_leaves.isEmpty)
                      Center(
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 40),
                          child: Column(
                            children: [
                              Icon(Icons.inbox, size: 55, color: Colors.grey.shade400),
                              const SizedBox(height: 8),
                              Text('کوئی درخواست رخصت نہیں ملی', style: TextStyle(color: Colors.grey.shade600, fontWeight: FontWeight.bold)),
                            ],
                          ),
                        ),
                      )
                    else
                      ..._leaves.map((item) => _buildLeaveCard(item)),
                    const SizedBox(height: 70), // Bottom padding for FAB
                  ],
                ),
              ),
            ),
          ),
      ),
    );
  }

  Widget _buildStatsGrid() {
    return Row(
      children: [
        _buildStatCard('کل', '$_totalCount', Colors.blue, Icons.list_alt),
        const SizedBox(width: 8),
        _buildStatCard('زیرِ غور', '$_pendingCount', Colors.orange, Icons.hourglass_top),
        const SizedBox(width: 8),
        _buildStatCard('منظور', '$_approvedCount', Colors.green, Icons.check_circle_outline),
        const SizedBox(width: 8),
        _buildStatCard('مسترد', '$_rejectedCount', Colors.red, Icons.cancel_outlined),
      ],
    );
  }

  Widget _buildStatCard(String label, String count, Color color, IconData icon) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 6),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withOpacity(0.3)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.03),
              blurRadius: 5,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          children: [
            Icon(icon, size: 18, color: color),
            const SizedBox(height: 4),
            Text(count, style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: color)),
            Text(label, style: const TextStyle(fontSize: 11, color: Colors.grey)),
          ],
        ),
      ),
    );
  }

  Widget _buildFiltersCard() {
    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          children: [
            // Status filter chips
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _buildStatusChip('All', 'تمام'),
                  const SizedBox(width: 6),
                  _buildStatusChip('Pending', 'زیرِ غور'),
                  const SizedBox(width: 6),
                  _buildStatusChip('Approved', 'منظور شدہ'),
                  const SizedBox(width: 6),
                  _buildStatusChip('Rejected', 'مسترد شدہ'),
                ],
              ),
            ),
            if (!_isTeacher && _teachers.isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                height: 38,
                padding: const EdgeInsets.symmetric(horizontal: 10),
                decoration: BoxDecoration(
                  color: Colors.grey.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.grey.shade200),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<int?>(
                    value: _filterTeacherId,
                    isExpanded: true,
                    hint: const Text('تمام اساتذہ', style: TextStyle(fontSize: 12)),
                    items: [
                      const DropdownMenuItem<int?>(value: null, child: Text('تمام اساتذہ', style: TextStyle(fontSize: 12))),
                      ..._teachers.map((t) => DropdownMenuItem<int?>(
                            value: t['id'] as int?,
                            child: Text(t['name']?.toString() ?? '', style: const TextStyle(fontSize: 12)),
                          )),
                    ],
                    onChanged: (v) {
                      setState(() => _filterTeacherId = v);
                      _loadData();
                    },
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildStatusChip(String value, String label) {
    final isSelected = (_filterStatus ?? 'All') == value;
    return ChoiceChip(
      label: Text(label, style: TextStyle(fontSize: 11, color: isSelected ? Colors.white : Colors.black87)),
      selected: isSelected,
      selectedColor: const Color(0xFF284D3D),
      backgroundColor: Colors.grey.shade100,
      onSelected: (selected) {
        if (selected) {
          setState(() => _filterStatus = value);
          _loadData();
        }
      },
    );
  }

  Widget _buildLeaveCard(Map<String, dynamic> item) {
    final leaveId = item['id'] as int? ?? 0;
    final teacherId = item['teacher_id'] as int? ?? 0;
    final teacherName = _teacherNames[teacherId] ?? 'استاذ #$teacherId';
    final leaveType = item['leave_type']?.toString() ?? 'اتفاقی';
    final startDate = item['start_date']?.toString() ?? '';
    final endDate = item['end_date']?.toString() ?? startDate;
    final reason = item['reason']?.toString() ?? '';
    final status = item['status']?.toString() ?? 'Pending';
    final approvedBy = item['approved_by']?.toString();

    // Calculate days
    int days = 1;
    final sDt = DateTime.tryParse(startDate);
    final eDt = DateTime.tryParse(endDate);
    if (sDt != null && eDt != null && !eDt.isBefore(sDt)) {
      days = eDt.difference(sDt).inDays + 1;
    }

    Color statusColor;
    String statusUrdu;
    IconData statusIcon;
    if (status == 'Approved') {
      statusColor = Colors.green;
      statusUrdu = 'منظور شدہ';
      statusIcon = Icons.check_circle;
    } else if (status == 'Rejected') {
      statusColor = Colors.red;
      statusUrdu = 'مسترد شدہ';
      statusIcon = Icons.cancel;
    } else {
      statusColor = Colors.orange;
      statusUrdu = 'زیرِ غور';
      statusIcon = Icons.hourglass_top;
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      elevation: 1.5,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header: Name, Leave type & Status
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 16,
                        backgroundColor: const Color(0xFF284D3D).withOpacity(0.1),
                        child: const Icon(Icons.person, size: 18, color: Color(0xFF284D3D)),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(teacherName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: BoxDecoration(
                                color: Colors.blue.shade50,
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: Text(leaveType, style: TextStyle(fontSize: 10, color: Colors.blue.shade800)),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: statusColor.withOpacity(0.4)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(statusIcon, size: 13, color: statusColor),
                      const SizedBox(width: 4),
                      Text(statusUrdu, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: statusColor)),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 18),

            // Date Range & Days
            Row(
              children: [
                const Icon(Icons.date_range, size: 15, color: Colors.grey),
                const SizedBox(width: 4),
                Text(
                  startDate == endDate ? startDate : '$startDate تا $endDate',
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                ),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text('$days دن', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.black87)),
                ),
              ],
            ),

            if (reason.isNotEmpty) ...[
              const SizedBox(height: 6),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.notes, size: 15, color: Colors.grey),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(
                      reason,
                      style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                    ),
                  ),
                ],
              ),
            ],

            if (approvedBy != null && approvedBy.isNotEmpty) ...[
              const SizedBox(height: 4),
              Row(
                children: [
                  const Icon(Icons.verified_user, size: 14, color: Colors.grey),
                  const SizedBox(width: 4),
                  Text('منظور کنندہ: $approvedBy', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                ],
              ),
            ],

            // Action Buttons for Nazim/Admin
            if (_canManage) ...[
              const SizedBox(height: 10),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  if (status != 'Approved')
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.green,
                        side: const BorderSide(color: Colors.green),
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      icon: const Icon(Icons.check, size: 15),
                      label: const Text('منظور کریں', style: TextStyle(fontSize: 11)),
                      onPressed: () => _updateStatus(leaveId, 'Approved'),
                    ),
                  if (status != 'Approved') const SizedBox(width: 6),
                  if (status != 'Rejected')
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.red,
                        side: const BorderSide(color: Colors.red),
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      icon: const Icon(Icons.close, size: 15),
                      label: const Text('مسترد', style: TextStyle(fontSize: 11)),
                      onPressed: () => _updateStatus(leaveId, 'Rejected'),
                    ),
                  const SizedBox(width: 6),
                  IconButton(
                    icon: const Icon(Icons.delete_outline, size: 18, color: Colors.grey),
                    tooltip: 'حذف کریں',
                    onPressed: () => _deleteLeave(leaveId),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
