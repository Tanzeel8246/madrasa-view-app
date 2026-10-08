// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class HostelScreen extends StatefulWidget {
  const HostelScreen({super.key});

  @override
  State<HostelScreen> createState() => _HostelScreenState();
}

class _HostelScreenState extends State<HostelScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _rooms = [];
  List<Map<String, dynamic>> _allocations = [];
  List<Map<String, dynamic>> _students = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final sys = SystemTypeGlobal.currentSystemType.value;
    final isMasjid = sys == 'Masjid';

    final rooms = await _dbHelper.getHostelRooms(systemType: sys);
    final allocs = await _dbHelper.getHostelAllocations(systemType: sys);

    final db = await _dbHelper.database;
    final stdTable = isMasjid ? 'maktab_students' : 'students';
    List<Map<String, dynamic>> stds = [];
    try {
      stds = await db.query(
        stdTable,
        columns: ['id', 'name', 'class', 'residence_status'],
        where: "status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL)",
        orderBy: 'name ASC',
      );
    } catch (_) {}

    setState(() {
      _rooms = rooms;
      _allocations = allocs;
      _students = stds;
      _isLoading = false;
    });
  }

  int get _occupiedBedsCount {
    return _allocations.where((a) => a['status'] == 'Occupied' || a['status'] == 'Active').length;
  }

  int get _totalBedsCapacity {
    int sum = 0;
    for (final r in _rooms) {
      sum += (r['capacity_beds'] as num?)?.toInt() ?? 4;
    }
    return sum;
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final title = sys == 'Masjid' ? 'دار الاقامہ و رہائش' : 'ہاسٹل مینیجمنٹ سسٹم';

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        appBar: AppBar(
          title: Text(
            title,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          bottom: TabBar(
            controller: _tabController,
            indicatorColor: Colors.amber,
            indicatorWeight: 3,
            labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
            tabs: [
              Tab(
                icon: const Icon(Icons.hotel),
                text: 'کمرہ جات (${_rooms.length})',
              ),
              Tab(
                icon: const Icon(Icons.group),
                text: 'مقیم طلبہ (${_occupiedBedsCount})',
              ),
            ],
          ),
        ),
        drawer: const AppDrawer(),
        body: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(
                controller: _tabController,
                children: [
                  _buildRoomsTab(),
                  _buildAllocationsTab(),
                ],
              ),
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          onPressed: () {
            if (_tabController.index == 0) {
              _showRoomFormDialog();
            } else {
              _showAllocationDialog();
            }
          },
          icon: Icon(_tabController.index == 0 ? Icons.add_home : Icons.person_add),
          label: Text(
            _tabController.index == 0 ? 'نیا کمرہ درج کریں' : 'طالب علم مقیم کریں',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }

  Widget _buildRoomsTab() {
    final vacantBeds = _totalBedsCapacity - _occupiedBedsCount;

    return Column(
      children: [
        Container(
          color: Colors.teal.shade50,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildStatItem('کل کمرے', '${_rooms.length}', Icons.meeting_room, primaryGreen),
              _buildStatItem('کل بیڈز', '$_totalBedsCapacity', Icons.bed, Colors.blue.shade800),
              _buildStatItem('مقیم طلبہ', '$_occupiedBedsCount', Icons.person, Colors.orange.shade800),
              _buildStatItem('خالی بیڈز', '$vacantBeds', Icons.check_circle_outline, Colors.green.shade700),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: _rooms.isEmpty
              ? const Center(child: Text('کوئی ہاسٹل کمرہ موجود نہیں ہے۔ نیا کمرہ شامل کریں۔'))
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 4),
                  itemCount: _rooms.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (ctx, idx) {
                    final r = _rooms[idx];
                    final cap = (r['capacity_beds'] as num?)?.toInt() ?? 4;
                    final fee = (r['monthly_fee'] as num?)?.toDouble() ?? 0.0;
                    final roomId = r['id'] as int;

                    // count occupied in this room
                    final roomAllocs = _allocations
                        .where((a) => a['room_id'] == roomId &&
                            (a['status'] == 'Occupied' || a['status'] == 'Active'))
                        .toList();
                    final occCount = roomAllocs.length;
                    final isFull = occCount >= cap;

                    return Card(
                      elevation: 1.5,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Row(
                                  children: [
                                    CircleAvatar(
                                      backgroundColor: isFull
                                          ? Colors.red.shade100
                                          : Colors.teal.shade100,
                                      child: Icon(
                                        Icons.hotel,
                                        color: isFull ? Colors.red : primaryGreen,
                                      ),
                                    ),
                                    const SizedBox(width: 10),
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          'کمرہ نمبر: ${r['room_number']}',
                                          style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 16,
                                          ),
                                        ),
                                        if (r['room_name'] != null &&
                                            r['room_name'].toString().isNotEmpty)
                                          Text(
                                            r['room_name'].toString(),
                                            style: TextStyle(
                                              color: Colors.grey.shade600,
                                              fontSize: 12,
                                            ),
                                          ),
                                      ],
                                    ),
                                  ],
                                ),
                                Row(
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color: isFull ? Colors.red : Colors.green,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text(
                                        isFull ? 'مکمل بھرا ہوا' : '$occCount / $cap مقیم',
                                        style: const TextStyle(
                                          color: Colors.white,
                                          fontSize: 11,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                    ),
                                    PopupMenuButton<String>(
                                      onSelected: (val) {
                                        if (val == 'edit') {
                                          _showRoomFormDialog(room: r);
                                        } else if (val == 'delete') {
                                          _confirmDeleteRoom(roomId);
                                        }
                                      },
                                      itemBuilder: (ctx) => [
                                        const PopupMenuItem(
                                          value: 'edit',
                                          child: Row(
                                            children: [
                                              Icon(Icons.edit, size: 18),
                                              SizedBox(width: 8),
                                              Text('ترمیم'),
                                            ],
                                          ),
                                        ),
                                        const PopupMenuItem(
                                          value: 'delete',
                                          child: Row(
                                            children: [
                                              Icon(Icons.delete, color: Colors.red, size: 18),
                                              SizedBox(width: 8),
                                              Text('حذف', style: TextStyle(color: Colors.red)),
                                            ],
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            const Divider(height: 16),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(
                                  'ماہانہ ہاسٹل فیس: Rs $fee',
                                  style: TextStyle(
                                    fontWeight: FontWeight.w600,
                                    color: Colors.grey.shade800,
                                  ),
                                ),
                                if (!isFull)
                                  TextButton.icon(
                                    style: TextButton.styleFrom(
                                      foregroundColor: primaryGreen,
                                      visualDensity: VisualDensity.compact,
                                    ),
                                    onPressed: () =>
                                        _showAllocationDialog(preSelectedRoom: r),
                                    icon: const Icon(Icons.person_add, size: 16),
                                    label: const Text('طالب علم الاٹ کریں'),
                                  ),
                              ],
                            ),
                            if (roomAllocs.isNotEmpty) ...[
                              const SizedBox(height: 6),
                              Wrap(
                                spacing: 6,
                                runSpacing: 4,
                                children: roomAllocs.map((al) {
                                  return Chip(
                                    avatar: const CircleAvatar(
                                      backgroundColor: Colors.white,
                                      child: Icon(Icons.person, size: 14),
                                    ),
                                    label: Text(
                                      '${al['student_name']} (${al['bed_number'] ?? ''})',
                                      style: const TextStyle(fontSize: 12),
                                    ),
                                    backgroundColor: Colors.grey.shade100,
                                    visualDensity: VisualDensity.compact,
                                  );
                                }).toList(),
                              ),
                            ],
                          ],
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _buildAllocationsTab() {
    final activeAllocs = _allocations
        .where((a) => a['status'] == 'Occupied' || a['status'] == 'Active')
        .toList();

    if (activeAllocs.isEmpty) {
      return const Center(
        child: Text('اس وقت کوئی طالب علم ہاسٹل میں مقیم نہیں ہے۔'),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 10),
      itemCount: activeAllocs.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (ctx, idx) {
        final a = activeAllocs[idx];
        final allocId = a['id'] as int;

        return Card(
          elevation: 1.5,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: ListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
            leading: CircleAvatar(
              backgroundColor: Colors.teal.shade50,
              child: const Icon(Icons.person, color: Color(0xFF284D3D)),
            ),
            title: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  a['student_name']?.toString() ?? 'طالب علم',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: Colors.green.shade50,
                    borderRadius: BorderRadius.circular(4),
                    border: Border.all(color: Colors.green.shade200),
                  ),
                  child: const Text('مقیم', style: TextStyle(color: Colors.green, fontSize: 11)),
                ),
              ],
            ),
            subtitle: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(height: 2),
                Text(
                  'کمرہ: ${a['room_number'] ?? a['room_id']}  |  بیڈ نمبر: ${a['bed_number'] ?? 'Bed-1'}',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                ),
                Text(
                  'تاریخ الاٹمنٹ: ${a['allocation_date'] ?? ''}',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                ),
              ],
            ),
            trailing: TextButton.icon(
              style: TextButton.styleFrom(
                foregroundColor: Colors.red,
                visualDensity: VisualDensity.compact,
              ),
              onPressed: () => _confirmVacateBed(allocId, a['student_name']?.toString() ?? ''),
              icon: const Icon(Icons.exit_to_app, size: 16),
              label: const Text('بیڈ خالی کریں'),
            ),
          ),
        );
      },
    );
  }

  Widget _buildStatItem(String title, String val, IconData icon, Color color) {
    return Column(
      children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 2),
        Text(
          val,
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: color),
        ),
        Text(title, style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
      ],
    );
  }

  void _showRoomFormDialog({Map<String, dynamic>? room}) {
    final isEdit = room != null;
    final numCtrl = TextEditingController(text: room?['room_number']?.toString() ?? '');
    final nameCtrl = TextEditingController(text: room?['room_name']?.toString() ?? '');
    final capCtrl = TextEditingController(
        text: room?['capacity_beds'] != null ? room!['capacity_beds'].toString() : '4');
    final feeCtrl = TextEditingController(
        text: room?['monthly_fee'] != null ? room!['monthly_fee'].toString() : '0');
    final remarksCtrl = TextEditingController(text: room?['remarks']?.toString() ?? '');

    showDialog(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: Text(isEdit ? 'ترمیم کمرہ' : 'نیا ہاسٹل کمرہ شامل کریں'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: numCtrl,
                  decoration: const InputDecoration(labelText: 'کمرہ نمبر / نام کوڈ *'),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(labelText: 'کمرے کا وضاحتی نام (اختیاری)'),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: capCtrl,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(labelText: 'کل بیڈز گنجائش *'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: feeCtrl,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(labelText: 'ماہانہ فیس (روپے)'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: remarksCtrl,
                  decoration: const InputDecoration(labelText: 'اضافی کیفیات / ریمارکس'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
              onPressed: () async {
                if (numCtrl.text.trim().isEmpty) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('برائے مہربانی کمرہ نمبر درج کریں')),
                  );
                  return;
                }

                final data = {
                  'room_number': numCtrl.text.trim(),
                  'room_name': nameCtrl.text.trim(),
                  'capacity_beds': int.tryParse(capCtrl.text.trim()) ?? 4,
                  'monthly_fee': double.tryParse(feeCtrl.text.trim()) ?? 0.0,
                  'remarks': remarksCtrl.text.trim(),
                  'status': 'Active',
                  'system_type': SystemTypeGlobal.currentSystemType.value,
                };

                if (isEdit) {
                  await _dbHelper.updateHostelRoom(room['id'] as int, data);
                } else {
                  await _dbHelper.insertHostelRoom(data);
                }

                Navigator.pop(ctx);
                _loadData();
              },
              child: Text(isEdit ? 'محفوظ کریں' : 'شامل کریں',
                  style: const TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  void _showAllocationDialog({Map<String, dynamic>? preSelectedRoom}) {
    Map<String, dynamic>? selectedRoom = preSelectedRoom;
    if (selectedRoom == null && _rooms.isNotEmpty) {
      selectedRoom = _rooms.first;
    }

    Map<String, dynamic>? selectedStudent;
    final bedCtrl = TextEditingController(text: 'Bed-1');
    final dateCtrl = TextEditingController(
      text: DateTime.now().toIso8601String().substring(0, 10),
    );
    final remarksCtrl = TextEditingController();

    // Filter available students (preferably those not yet resident)
    final availableStudents = _students;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: const Row(
              children: [
                Icon(Icons.hotel, color: Color(0xFF284D3D)),
                SizedBox(width: 8),
                Text('طالب علم ہاسٹل الاٹمنٹ'),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  DropdownButtonFormField<Map<String, dynamic>>(
                    value: selectedRoom,
                    decoration: const InputDecoration(labelText: 'کمرہ منتخب کریں *'),
                    items: _rooms
                        .map((r) => DropdownMenuItem(
                              value: r,
                              child: Text('کمرہ ${r['room_number']} (${r['room_name'] ?? ''})'),
                            ))
                        .toList(),
                    onChanged: (val) => setDlgState(() => selectedRoom = val),
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<Map<String, dynamic>>(
                    value: selectedStudent,
                    decoration: const InputDecoration(labelText: 'طالب علم منتخب کریں *'),
                    items: availableStudents
                        .map((s) => DropdownMenuItem(
                              value: s,
                              child: Text('${s['name']} - ${s['class'] ?? ''}'),
                            ))
                        .toList(),
                    onChanged: (val) => setDlgState(() => selectedStudent = val),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: bedCtrl,
                          decoration: const InputDecoration(labelText: 'بیڈ نمبر / شناخت *'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: dateCtrl,
                          decoration: const InputDecoration(labelText: 'تاریخ الاٹمنٹ *'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: remarksCtrl,
                    decoration: const InputDecoration(labelText: 'ریمارکس / ہدایات'),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: Colors.blue.shade50,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: const Row(
                      children: [
                        Icon(Icons.info_outline, color: Colors.blue, size: 16),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'طالب علم کا اسٹیٹس خودکار "مقیم" ہو جائے گا اور ماہانہ فیس انوائس میں ہاسٹل چارجز شامل ہوں گے۔',
                            style: TextStyle(fontSize: 11, color: Colors.blueGrey),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (selectedRoom == null || selectedStudent == null) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('برائے مہربانی کمرہ اور طالب علم منتخب کریں')),
                    );
                    return;
                  }

                  final success = await _dbHelper.allocateHostelBed(
                    selectedRoom!['id'] as int,
                    selectedStudent!['id'] as int,
                    studentName: selectedStudent!['name']?.toString(),
                    bedNumber: bedCtrl.text.trim(),
                    allocationDate: dateCtrl.text.trim(),
                    remarks: remarksCtrl.text.trim(),
                    systemType: SystemTypeGlobal.currentSystemType.value,
                  );

                  Navigator.pop(ctx);
                  if (success) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('ہاسٹل بیڈ کامیابی سے الاٹ کر دیا گیا')),
                    );
                    _loadData();
                  }
                },
                child: const Text('الاٹ کریں', style: TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _confirmVacateBed(int allocId, String studentName) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('بیڈ خالی کرنے کی تصدیق'),
          content: Text('کیا آپ واقعی $studentName کا ہاسٹل بیڈ خالی (Vacate) کرنا چاہتے ہیں؟'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('نہیں')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ہاں، خالی کریں', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );

    if (ok == true) {
      await _dbHelper.vacateHostelBed(allocId);
      _loadData();
    }
  }

  Future<void> _confirmDeleteRoom(int roomId) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('کمرہ حذف کی تصدیق'),
          content: const Text('کیا آپ واقعی یہ ہاسٹل کمرہ حذف کرنا چاہتے ہیں؟'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('نہیں')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('حذف کریں', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );

    if (ok == true) {
      await _dbHelper.deleteHostelRoom(roomId);
      _loadData();
    }
  }
}
