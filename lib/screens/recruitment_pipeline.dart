// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class RecruitmentPipelineScreen extends StatefulWidget {
  const RecruitmentPipelineScreen({super.key});

  @override
  State<RecruitmentPipelineScreen> createState() =>
      _RecruitmentPipelineScreenState();
}

class _RecruitmentPipelineScreenState extends State<RecruitmentPipelineScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _applicants = [];
  List<Map<String, dynamic>> _jobPostings = [];

  String _selectedStage = 'All';

  final List<String> _stages = [
    'All',
    'Pending',
    'Interview',
    'Selected',
    'Rejected',
  ];

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

    final jobs = await _dbHelper.getJobPostings(systemType: sys);
    final apps = await _dbHelper.getJobApplicants(
      systemType: sys,
      status: _selectedStage != 'All' ? _selectedStage : null,
    );

    setState(() {
      _jobPostings = jobs;
      _applicants = apps;
      _isLoading = false;
    });
  }

  String _getStageUrdu(String stage) {
    switch (stage) {
      case 'Pending':
        return 'نئی درخواست';
      case 'Interview':
        return 'انٹرویو مرحلہ';
      case 'Selected':
        return 'منتخب شدہ';
      case 'Rejected':
        return 'مسترد';
      default:
        return stage;
    }
  }

  Color _getStageColor(String stage) {
    switch (stage) {
      case 'Pending':
        return Colors.blue.shade700;
      case 'Interview':
        return Colors.orange.shade800;
      case 'Selected':
        return Colors.green.shade700;
      case 'Rejected':
        return Colors.red.shade700;
      default:
        return Colors.grey.shade700;
    }
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final title = sys == 'Masjid' ? 'بھرتی ائمہ و عملہ' : 'بھرتی و ملازمت پائپ لائن';

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
                icon: const Icon(Icons.people_alt_outlined),
                text: 'امیدواران (${_applicants.length})',
              ),
              Tab(
                icon: const Icon(Icons.work_outline),
                text: 'ملازمت اشتہارات (${_jobPostings.length})',
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
                  _buildApplicantsTab(),
                  _buildJobsTab(),
                ],
              ),
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          onPressed: () {
            if (_tabController.index == 0) {
              _showApplicantFormDialog();
            } else {
              _showJobFormDialog();
            }
          },
          icon: Icon(_tabController.index == 0 ? Icons.person_add : Icons.post_add),
          label: Text(
            _tabController.index == 0 ? 'نیا امیدوار درج کریں' : 'نیا اشتہار لگائیں',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }

  Widget _buildApplicantsTab() {
    return Column(
      children: [
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: _stages.map((st) {
              final isSel = _selectedStage == st;
              return Padding(
                padding: const EdgeInsets.only(left: 6),
                child: ChoiceChip(
                  label: Text(st == 'All' ? 'تمام امیدوار' : _getStageUrdu(st)),
                  selected: isSel,
                  selectedColor: primaryGreen.withOpacity(0.2),
                  labelStyle: TextStyle(
                    color: isSel ? primaryGreen : Colors.black87,
                    fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                  ),
                  onSelected: (sel) {
                    if (sel) {
                      setState(() => _selectedStage = st);
                      _loadData();
                    }
                  },
                ),
              );
            }).toList(),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: _applicants.isEmpty
              ? const Center(child: Text('کوئی امیدوار موجود نہیں ہے۔'))
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 8),
                  itemCount: _applicants.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (ctx, idx) {
                    final a = _applicants[idx];
                    final st = a['status']?.toString() ?? 'Pending';
                    final color = _getStageColor(st);
                    final isHired = a['converted_teacher_id'] != null;

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
                                      backgroundColor: color.withOpacity(0.1),
                                      child: Icon(Icons.person, color: color),
                                    ),
                                    const SizedBox(width: 10),
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          a['applicant_name']?.toString() ?? '',
                                          style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 16,
                                          ),
                                        ),
                                        if (a['father_name'] != null &&
                                            a['father_name'].toString().isNotEmpty)
                                          Text(
                                            'ولد: ${a['father_name']}',
                                            style: TextStyle(
                                                color: Colors.grey.shade600, fontSize: 12),
                                          ),
                                      ],
                                    ),
                                  ],
                                ),
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 8, vertical: 4),
                                  decoration: BoxDecoration(
                                    color: color.withOpacity(0.15),
                                    borderRadius: BorderRadius.circular(20),
                                    border: Border.all(color: color),
                                  ),
                                  child: Text(
                                    _getStageUrdu(st),
                                    style: TextStyle(
                                      color: color,
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 8,
                              children: [
                                if (a['phone'] != null)
                                  Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Icon(Icons.phone, size: 14, color: Colors.grey),
                                      const SizedBox(width: 4),
                                      Text(a['phone'].toString(),
                                          style: const TextStyle(fontSize: 12)),
                                    ],
                                  ),
                                if (a['qualification'] != null)
                                  Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Icon(Icons.school, size: 14, color: Colors.grey),
                                      const SizedBox(width: 4),
                                      Text(a['qualification'].toString(),
                                          style: const TextStyle(fontSize: 12)),
                                    ],
                                  ),
                                if (a['experience'] != null)
                                  Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Icon(Icons.work_history,
                                          size: 14, color: Colors.grey),
                                      const SizedBox(width: 4),
                                      Text(a['experience'].toString(),
                                          style: const TextStyle(fontSize: 12)),
                                    ],
                                  ),
                              ],
                            ),
                            if (a['interview_date'] != null &&
                                a['interview_date'].toString().isNotEmpty) ...[
                              const SizedBox(height: 6),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 4),
                                decoration: BoxDecoration(
                                  color: Colors.orange.shade50,
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  'انٹرویو تاریخ: ${a['interview_date']}  ${a['interview_notes'] != null ? "(${a['interview_notes']})" : ""}',
                                  style: TextStyle(
                                      fontSize: 12, color: Colors.orange.shade900),
                                ),
                              ),
                            ],
                            const Divider(height: 16),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                DropdownButton<String>(
                                  value: st,
                                  isDense: true,
                                  underline: const SizedBox(),
                                  items: ['Pending', 'Interview', 'Selected', 'Rejected']
                                      .map((s) => DropdownMenuItem(
                                            value: s,
                                            child: Text('اسٹیج: ${_getStageUrdu(s)}',
                                                style: TextStyle(
                                                    color: _getStageColor(s),
                                                    fontWeight: FontWeight.bold,
                                                    fontSize: 13)),
                                          ))
                                      .toList(),
                                  onChanged: (newSt) async {
                                    if (newSt != null && newSt != st) {
                                      await _dbHelper.updateJobApplicant(
                                          a['id'] as int, {'status': newSt});
                                      _loadData();
                                    }
                                  },
                                ),
                                Row(
                                  children: [
                                    if (st == 'Pending' || st == 'Interview')
                                      TextButton.icon(
                                        style: TextButton.styleFrom(
                                          foregroundColor: Colors.orange.shade800,
                                          visualDensity: VisualDensity.compact,
                                        ),
                                        onPressed: () => _showInterviewScheduleDialog(a),
                                        icon: const Icon(Icons.event, size: 16),
                                        label: const Text('انٹرویو شیڈول'),
                                      ),
                                    if (!isHired && st == 'Selected')
                                      ElevatedButton.icon(
                                        style: ElevatedButton.styleFrom(
                                          backgroundColor: Colors.green.shade700,
                                          visualDensity: VisualDensity.compact,
                                        ),
                                        onPressed: () => _confirmHireTeacher(a),
                                        icon: const Icon(Icons.how_to_reg,
                                            size: 16, color: Colors.white),
                                        label: const Text('استاد بنائیں',
                                            style: TextStyle(color: Colors.white)),
                                      ),
                                    if (isHired)
                                      Container(
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 8, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: Colors.green.shade50,
                                          borderRadius: BorderRadius.circular(6),
                                        ),
                                        child: const Text('بھرتی مکمل (استاد)',
                                            style: TextStyle(
                                                color: Colors.green,
                                                fontSize: 11,
                                                fontWeight: FontWeight.bold)),
                                      ),
                                  ],
                                ),
                              ],
                            ),
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

  Widget _buildJobsTab() {
    if (_jobPostings.isEmpty) {
      return const Center(child: Text('کوئی فعال ملازمت کا اشتہار موجود نہیں ہے۔'));
    }

    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 10),
      itemCount: _jobPostings.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (ctx, idx) {
        final j = _jobPostings[idx];
        final isOpen = (j['status'] ?? 'Open') == 'Open';

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
                    Expanded(
                      child: Text(
                        j['title']?.toString() ?? '',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: isOpen ? Colors.green.shade50 : Colors.red.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                            color: isOpen ? Colors.green.shade300 : Colors.red.shade300),
                      ),
                      child: Text(
                        isOpen ? 'کھلا ہے' : 'بند ہو چکا',
                        style: TextStyle(
                          color: isOpen ? Colors.green.shade800 : Colors.red.shade800,
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  'شعبہ: ${j['department'] ?? 'عام'}  |  نوعیت: ${j['job_type'] ?? 'Full-time'}',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                ),
                if (j['salary_range'] != null && j['salary_range'].toString().isNotEmpty)
                  Text(
                    'متوقع مشاہرہ: ${j['salary_range']}',
                    style: TextStyle(color: Colors.grey.shade800, fontSize: 13),
                  ),
                if (j['requirements'] != null && j['requirements'].toString().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    'تقاضے: ${j['requirements']}',
                    style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                  ),
                ],
                const Divider(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'آخری تاریخ: ${j['deadline'] ?? 'تا حکمِ ثانی'}',
                      style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                    ),
                    Row(
                      children: [
                        IconButton(
                          icon: const Icon(Icons.edit, size: 18),
                          onPressed: () => _showJobFormDialog(job: j),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.red, size: 18),
                          onPressed: () => _confirmDeleteJob(j['id'] as int),
                        ),
                      ],
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  void _showJobFormDialog({Map<String, dynamic>? job}) {
    final isEdit = job != null;
    final titleCtrl = TextEditingController(text: job?['title']?.toString() ?? '');
    final deptCtrl = TextEditingController(text: job?['department']?.toString() ?? '');
    final salCtrl = TextEditingController(text: job?['salary_range']?.toString() ?? '');
    final reqCtrl = TextEditingController(text: job?['requirements']?.toString() ?? '');
    final deadCtrl = TextEditingController(text: job?['deadline']?.toString() ?? '');
    String jobType = job?['job_type']?.toString() ?? 'Full-time';
    String status = job?['status']?.toString() ?? 'Open';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: Text(isEdit ? 'ترمیم ملازمت اشتہار' : 'نیا ملازمت اشتہار بنائیں'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: titleCtrl,
                    decoration: const InputDecoration(
                        labelText: 'ملازمت کا عنوان *', hintText: 'مثلاً: مدرس تجوید و حفظ'),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: deptCtrl,
                          decoration: const InputDecoration(labelText: 'شعبہ'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: DropdownButtonFormField<String>(
                          value: ['Full-time', 'Part-time', 'Contract'].contains(jobType)
                              ? jobType
                              : 'Full-time',
                          decoration: const InputDecoration(labelText: 'نوعیت'),
                          items: const [
                            DropdownMenuItem(value: 'Full-time', child: Text('مکمل وقت (Full)')),
                            DropdownMenuItem(value: 'Part-time', child: Text('جزوی وقت (Part)')),
                            DropdownMenuItem(value: 'Contract', child: Text('معاہدہ (Contract)')),
                          ],
                          onChanged: (v) => setDlgState(() => jobType = v ?? 'Full-time'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: salCtrl,
                          decoration: const InputDecoration(
                              labelText: 'مشاہرہ حد', hintText: '25,000 - 35,000'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: deadCtrl,
                          decoration: const InputDecoration(
                              labelText: 'آخری تاریخ', hintText: '2026-10-15'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: reqCtrl,
                    maxLines: 2,
                    decoration: const InputDecoration(
                        labelText: 'لازمی قابلیت و شرائط',
                        hintText: 'وفاق المدارس سند، تجربہ کم از کم 2 سال'),
                  ),
                  if (isEdit) ...[
                    const SizedBox(height: 8),
                    DropdownButtonFormField<String>(
                      value: status,
                      decoration: const InputDecoration(labelText: 'اسٹیٹس'),
                      items: const [
                        DropdownMenuItem(value: 'Open', child: Text('اوپن (کھلا ہے)')),
                        DropdownMenuItem(value: 'Closed', child: Text('کلوزڈ (بند)')),
                      ],
                      onChanged: (v) => setDlgState(() => status = v ?? 'Open'),
                    ),
                  ],
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (titleCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('برائے مہربانی عنوان درج کریں')),
                    );
                    return;
                  }

                  final data = {
                    'title': titleCtrl.text.trim(),
                    'department': deptCtrl.text.trim(),
                    'job_type': jobType,
                    'salary_range': salCtrl.text.trim(),
                    'deadline': deadCtrl.text.trim(),
                    'requirements': reqCtrl.text.trim(),
                    'status': status,
                    'system_type': SystemTypeGlobal.currentSystemType.value,
                  };

                  if (isEdit) {
                    await _dbHelper.updateJobPosting(job['id'] as int, data);
                  } else {
                    await _dbHelper.insertJobPosting(data);
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
      ),
    );
  }

  void _showApplicantFormDialog() {
    final nameCtrl = TextEditingController();
    final fatherCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final qualCtrl = TextEditingController();
    final expCtrl = TextEditingController();
    final notesCtrl = TextEditingController();
    Map<String, dynamic>? selectedJob = _jobPostings.isNotEmpty ? _jobPostings.first : null;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: const Row(
              children: [
                Icon(Icons.person_add, color: Color(0xFF284D3D)),
                SizedBox(width: 8),
                Text('نیا امیدوار درج کریں'),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (_jobPostings.isNotEmpty)
                    DropdownButtonFormField<Map<String, dynamic>>(
                      value: selectedJob,
                      decoration: const InputDecoration(labelText: 'ملازمت پوسٹنگ *'),
                      items: _jobPostings
                          .map((j) => DropdownMenuItem(
                                value: j,
                                child: Text(j['title'].toString()),
                              ))
                          .toList(),
                      onChanged: (v) => setDlgState(() => selectedJob = v),
                    ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: nameCtrl,
                          decoration: const InputDecoration(labelText: 'امیدوار کا نام *'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: fatherCtrl,
                          decoration: const InputDecoration(labelText: 'والد کا نام'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: phoneCtrl,
                          keyboardType: TextInputType.phone,
                          decoration: const InputDecoration(labelText: 'موبائل نمبر *'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: emailCtrl,
                          decoration: const InputDecoration(labelText: 'ای میل'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: qualCtrl,
                          decoration: const InputDecoration(
                              labelText: 'قابلیت و اسناد', hintText: 'عالمیہ، قراءت'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: expCtrl,
                          decoration: const InputDecoration(
                              labelText: 'تدریسی تجربہ', hintText: '3 سال'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: notesCtrl,
                    decoration: const InputDecoration(labelText: 'اضافی نوٹس / حوالہ'),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (nameCtrl.text.trim().isEmpty || phoneCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('نام اور فون نمبر لازمی ہیں')),
                    );
                    return;
                  }

                  final data = {
                    'job_id': selectedJob?['id'],
                    'applicant_name': nameCtrl.text.trim(),
                    'father_name': fatherCtrl.text.trim(),
                    'phone': phoneCtrl.text.trim(),
                    'email': emailCtrl.text.trim(),
                    'qualification': qualCtrl.text.trim(),
                    'experience': expCtrl.text.trim(),
                    'notes': notesCtrl.text.trim(),
                    'status': 'Pending',
                    'system_type': SystemTypeGlobal.currentSystemType.value,
                  };

                  await _dbHelper.insertJobApplicant(data);
                  Navigator.pop(ctx);
                  _loadData();
                },
                child: const Text('شامل کریں', style: TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showInterviewScheduleDialog(Map<String, dynamic> app) {
    final dateCtrl = TextEditingController(
      text: DateTime.now().add(const Duration(days: 2)).toIso8601String().substring(0, 10),
    );
    final notesCtrl = TextEditingController(text: 'کمیٹی کے سامنے انٹرویو و ٹیسٹ');

    showDialog(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: Text('انٹرویو شیڈول برائے: ${app['applicant_name']}'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: dateCtrl,
                decoration: const InputDecoration(
                    labelText: 'انٹرویو تاریخ *', hintText: 'YYYY-MM-DD'),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: notesCtrl,
                decoration: const InputDecoration(labelText: 'انٹرویو ہدایات و وقت'),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.orange.shade800),
              onPressed: () async {
                await _dbHelper.updateJobApplicant(app['id'] as int, {
                  'status': 'Interview',
                  'interview_date': dateCtrl.text.trim(),
                  'interview_notes': notesCtrl.text.trim(),
                });
                Navigator.pop(ctx);
                _loadData();
              },
              child: const Text('شیڈول محفوظ کریں', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmHireTeacher(Map<String, dynamic> app) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('اساتذہ میں شمولیت (Hire to Teacher)'),
          content: Text(
              'کیا آپ واقعی ${app['applicant_name']} کو ادارے کے اساتذہ لسٹ میں شامل کر کے فعال بنانا چاہتے ہیں؟'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('نہیں')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.green.shade700),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ہاں، استاد بنائیں', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );

    if (ok == true) {
      final res = await _dbHelper.hireApplicantToTeacher(app['id'] as int);
      if (res) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('امیدوار کامیابی سے اساتذہ لسٹ میں شامل ہو گیا!')),
        );
        _loadData();
      }
    }
  }

  Future<void> _confirmDeleteJob(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('اشتہار حذف کی تصدیق'),
          content: const Text('کیا آپ واقعی یہ اشتہار حذف کرنا چاہتے ہیں؟'),
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
      await _dbHelper.deleteJobPosting(id);
      _loadData();
    }
  }
}
