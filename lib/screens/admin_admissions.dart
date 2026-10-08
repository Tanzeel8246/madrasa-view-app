// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../utils/globals.dart';
import '../utils/network_service.dart';
import '../widgets/app_drawer.dart';

class AdminAdmissionsPage extends StatefulWidget {
  const AdminAdmissionsPage({super.key});

  @override
  State<AdminAdmissionsPage> createState() => _AdminAdmissionsPageState();
}

class _AdminAdmissionsPageState extends State<AdminAdmissionsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color bgColor = const Color(0xFFF1EFE7);

  List<Map<String, dynamic>> _applications = [];
  Map<String, int> _counts = {
    'pending': 0,
    'interview': 0,
    'approved': 0,
    'rejected': 0
  };
  String _selectedFilter = 'all';
  bool _isLoading = true;
  String _errorMsg = '';

  final List<String> _filters = [
    'all',
    'pending',
    'interview',
    'approved',
    'rejected'
  ];

  @override
  void initState() {
    super.initState();
    _loadApplications();
  }

  Future<void> _loadApplications() async {
    setState(() {
      _isLoading = true;
      _errorMsg = '';
    });
    try {
      final res = await NetworkService.getWithFallbackAction(
          'get_admission_applications&filter=$_selectedFilter');
      if (res.statusCode == 200) {
        final body = jsonDecode(res.body);
        if (body['status'] == 'success') {
          setState(() {
            _applications =
                List<Map<String, dynamic>>.from(body['applications'] ?? []);
            final c = body['counts'] ?? {};
            _counts = {
              'pending': c['pending'] ?? 0,
              'interview': c['interview'] ?? 0,
              'approved': c['approved'] ?? 0,
              'rejected': c['rejected'] ?? 0,
            };
          });
        } else {
          setState(() => _errorMsg = body['message'] ?? 'خرابی');
        }
      }
    } catch (e) {
      setState(() => _errorMsg = 'نیٹ ورک خرابی: $e');
    } finally {
      setState(() => _isLoading = false);
    }
  }

  String _getPhotoUrl(String? raw) {
    if (raw == null || raw.trim().isEmpty) return '';
    final p = raw.trim();
    if (p.startsWith('http://') || p.startsWith('https://')) return p;
    String base = apiUrl.trim();
    if (base.endsWith('/')) base = base.substring(0, base.length - 1);
    base = base.replaceAll('/api.php', '');
    final cleanPath = p.startsWith('/') ? p : '/$p';
    return '$base$cleanPath';
  }

  Widget _buildPhotoAvatar(String? photo, {double size = 50}) {
    final url = _getPhotoUrl(photo);
    if (url.isEmpty) {
      return CircleAvatar(
        radius: size / 2,
        backgroundColor: Colors.grey.shade300,
        child: Icon(Icons.person, size: size * 0.6, color: Colors.grey.shade600),
      );
    }
    return CircleAvatar(
      radius: size / 2,
      backgroundColor: Colors.grey.shade200,
      backgroundImage: NetworkImage(url),
      onBackgroundImageError: (_, __) {},
    );
  }

  Future<void> _sendWhatsAppInterviewNotice(
      String sName, String fName, String phone, String dateStr, String timeStr) async {
    final rawPhone = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (rawPhone.isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('امیدوار کا فون نمبر موجود نہیں ہے!'),
            backgroundColor: Colors.orange,
          ),
        );
      }
      return;
    }
    final cleanPhone =
        rawPhone.startsWith('0') ? '92${rawPhone.substring(1)}' : rawPhone;
    final msg =
        "السلام علیکم ورحمۃ اللہ!\nمحترم $sName ولد $fName کی داخلہ درخواست برائے جامعہ اشرف المدارس پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔\n📅 تاریخ: $dateStr\n⏰ وقت: $timeStr\n📍 ادارہ: جامعہ اشرف المدارس، فروکہ\nبراہ کرم مقررہ تاریخ اور وقت پر اصل اسناد و شناختی دستاویزات کے ہمراہ تشریف لائیں۔ شکریہ!";
    final url =
        Uri.parse("https://wa.me/$cleanPhone?text=${Uri.encodeComponent(msg)}");
    try {
      await launchUrl(url, mode: LaunchMode.externalApplication);
    } catch (_) {
      await launchUrl(url);
    }
  }

  Future<void> _updateStatus(int appId, String status,
      {String? testDate, String? testTime, String? adminNote}) async {
    try {
      final res = await NetworkService.postWithFallbackAction(
        'update_admission_status',
        {
          'id': appId,
          'status': status,
          'test_date': testDate ?? '',
          'test_time': testTime ?? '',
          'admin_note': adminNote ?? '',
        },
      );
      if (res.statusCode == 200) {
        final body = jsonDecode(res.body);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(body['message'] ?? 'عمل مکمل'),
            backgroundColor:
                body['status'] == 'success' ? Colors.green : Colors.red,
          ));
        }
        _loadApplications();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('خرابی: $e'), backgroundColor: Colors.red));
      }
    }
  }

  Future<void> _resendSms(int appId) async {
    try {
      final res = await NetworkService.postWithFallbackAction(
        'resend_admission_sms',
        {'id': appId},
      );
      final body = jsonDecode(res.body);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(body['message'] ?? 'SMS عمل مکمل'),
          backgroundColor:
              body['status'] == 'success' ? Colors.green : Colors.red,
        ));
      }
      _loadApplications();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('SMS دوبارہ بھیجنے میں خرابی: $e'),
          backgroundColor: Colors.red,
        ));
      }
    }
  }

  void _showScheduleInterviewDialog(Map<String, dynamic> app) {
    final existingDate = (app['test_date'] ?? '').toString();
    final existingTime = (app['test_time'] ?? '').toString();
    final testDateCtrl = TextEditingController(
        text: existingDate.isNotEmpty
            ? existingDate
            : DateTime.now().toString().substring(0, 10));
    final testTimeCtrl = TextEditingController(
        text: existingTime.isNotEmpty ? existingTime : '09:00 AM');
    final noteCtrl = TextEditingController(
        text: (app['admin_note'] ?? '').toString());
    final sName = (app['student_name'] ?? '').toString();
    final fName = (app['father_name'] ?? '').toString();
    final phone =
        (app['mobile_number'] ?? app['mobile_no'] ?? '').toString();

    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Row(children: const [
          Icon(Icons.calendar_month, color: Colors.purple),
          SizedBox(width: 8),
          Text('ٹیسٹ و انٹرویو شیڈول کریں',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        ]),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('امیدوار: $sName ولد $fName',
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 12),
            TextField(
              controller: testDateCtrl,
              readOnly: true,
              decoration: const InputDecoration(
                  labelText: 'ٹیسٹ کی تاریخ',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.calendar_today)),
              onTap: () async {
                final d = await showDatePicker(
                    context: context,
                    initialDate: DateTime.now(),
                    firstDate:
                        DateTime.now().subtract(const Duration(days: 7)),
                    lastDate:
                        DateTime.now().add(const Duration(days: 365)));
                if (d != null) {
                  testDateCtrl.text = d.toString().substring(0, 10);
                }
              },
            ),
            const SizedBox(height: 10),
            TextField(
              controller: testTimeCtrl,
              decoration: const InputDecoration(
                  labelText: 'ٹیسٹ کا وقت',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.access_time)),
              onTap: () async {
                final t = await showTimePicker(
                    context: context, initialTime: TimeOfDay.now());
                if (t != null) testTimeCtrl.text = t.format(context);
              },
              readOnly: true,
            ),
            const SizedBox(height: 10),
            TextField(
              controller: noteCtrl,
              maxLines: 2,
              decoration: const InputDecoration(
                  labelText: 'ایڈمن نوٹ (اختیاری)',
                  border: OutlineInputBorder()),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: Colors.purple.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(8),
                border:
                    Border.all(color: Colors.purple.withValues(alpha: 0.2)),
              ),
              child: Row(children: const [
                Icon(Icons.info_outline, color: Colors.purple, size: 20),
                SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'شیڈول محفوظ کرنے کے ساتھ ہی واٹس ایپ پر 1-کلک نوٹس میسج بھیجا جائے گا۔',
                    style: TextStyle(fontSize: 11, color: Colors.purple),
                  ),
                ),
              ]),
            ),
          ]),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('منسوخ')),
          ElevatedButton.icon(
            icon: const Icon(Icons.save, color: Colors.white),
            label: const Text('محفوظ کریں اور واٹس ایپ بھیجیں',
                style: TextStyle(color: Colors.white)),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.purple),
            onPressed: () {
              final dStr = testDateCtrl.text;
              final tStr = testTimeCtrl.text;
              final nStr = noteCtrl.text;
              Navigator.pop(context);
              _updateStatus(app['id'], 'interview',
                  testDate: dStr, testTime: tStr, adminNote: nStr);
              _sendWhatsAppInterviewNotice(sName, fName, phone, dStr, tStr);
            },
          ),
        ],
      ),
    );
  }

  void _confirmApprove(Map<String, dynamic> app) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Row(children: const [
          Icon(Icons.check_circle, color: Colors.green),
          SizedBox(width: 8),
          Text('داخلہ منظور کریں',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
        ]),
        content: Text(
          'کیا آپ واقعی محترم ${app['student_name'] ?? ''} کا داخلہ باقاعدہ منظور کرنا چاہتے ہیں؟\nمنظوری پر طالب علم کا داخلہ نمبر خودکار بن جائے گا اور تصدیقی SMS ارسال ہوگا۔',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('منسوخ'),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.check, color: Colors.white),
            label: const Text('ہاں، منظور کریں',
                style: TextStyle(color: Colors.white)),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.green),
            onPressed: () {
              Navigator.pop(context);
              _updateStatus(app['id'], 'Approved');
            },
          ),
        ],
      ),
    );
  }

  void _confirmReject(Map<String, dynamic> app) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Row(children: const [
          Icon(Icons.cancel, color: Colors.red),
          SizedBox(width: 8),
          Text('درخواست مسترد کریں',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
        ]),
        content: Text(
          'کیا آپ واقعی محترم ${app['student_name'] ?? ''} کی داخلہ درخواست مسترد کرنا چاہتے ہیں؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('منسوخ'),
          ),
          ElevatedButton.icon(
            icon: const Icon(Icons.close, color: Colors.white),
            label: const Text('مسترد کریں',
                style: TextStyle(color: Colors.white)),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () {
              Navigator.pop(context);
              _updateStatus(app['id'], 'rejected');
            },
          ),
        ],
      ),
    );
  }

  void _showDetailsModal(Map<String, dynamic> app) {
    final status = (app['status'] ?? 'pending').toString().toLowerCase();
    final photo = app['photo']?.toString();
    final phone =
        (app['mobile_number'] ?? app['mobile_no'] ?? '').toString();
    final rawPhone = phone.replaceAll(RegExp(r'[^0-9]'), '');
    final cleanPhone =
        rawPhone.startsWith('0') ? '92${rawPhone.substring(1)}' : rawPhone;

    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Row(children: const [
          Icon(Icons.info_outline, color: Colors.blue),
          SizedBox(width: 8),
          Text('درخواست کی مکمل تفصیلات',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        ]),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              // Candidate Photo
              Center(
                child: _buildPhotoAvatar(photo, size: 80),
              ),
              const SizedBox(height: 10),
              Text(
                app['student_name'] ?? 'نامعلوم',
                style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF284D3D)),
              ),
              const SizedBox(height: 4),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: _badgeColor(status),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  _badgeLabel(status),
                  style: const TextStyle(color: Colors.white, fontSize: 11),
                ),
              ),
              const SizedBox(height: 14),
              _detailRow('ولدیت', app['father_name']),
              _detailRow('تاریخ پیدائش', app['dob'] ?? app['date_of_birth']),
              _detailRow('موبائل فون', phone),
              if ((app['test_date'] ?? '').toString().isNotEmpty)
                _detailRow('ٹیسٹ کی تاریخ', app['test_date']),
              if ((app['test_time'] ?? '').toString().isNotEmpty)
                _detailRow('ٹیسٹ کا وقت', app['test_time']),
              if ((app['admin_note'] ?? '').toString().isNotEmpty)
                _detailRow('ایڈمن نوٹ', app['admin_note']),
              _detailRow(
                'SMS حالت',
                status == 'approved'
                    ? (app['sms_sent']?.toString() == '1'
                        ? 'بھیجا گیا'
                        : 'نہیں بھیجا گیا')
                    : '-',
              ),
              _detailRow('شعبہ', app['department']),
              _detailRow('ای میل', app['email']),
              _detailRow('موجودہ پتہ', app['address']),
              _detailRow('مستقل پتہ', app['permanent_address']),
              _detailRow('گزشتہ تعلیم', app['qualification']),
              _detailRow('گزشتہ ادارہ', app['last_studied']),
              _detailRow('مقصدِ داخلہ', app['aim']),
              _detailRow('سابقہ مدارس', app['prev_madaris']),
              _detailRow('مدرسہ چھوڑنے کی وجہ', app['leave_reason']),
              const Divider(),
              Text('سرپرست کی معلومات',
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: Colors.grey.shade700)),
              const SizedBox(height: 4),
              _detailRow('نام سرپرست', app['guardian_name']),
              _detailRow('شناختی کارڈ', app['guardian_cnic']),
              _detailRow('رشتہ', app['guardian_relation']),
              _detailRow('پتہ', app['guardian_address']),
              const SizedBox(height: 10),
              if (cleanPhone.isNotEmpty)
                ElevatedButton.icon(
                  icon: const Icon(Icons.chat, color: Colors.white, size: 18),
                  label: const Text('واٹس ایپ پر رابطہ کریں',
                      style: TextStyle(color: Colors.white)),
                  style:
                      ElevatedButton.styleFrom(backgroundColor: Colors.green),
                  onPressed: () {
                    final url = Uri.parse("https://wa.me/$cleanPhone");
                    launchUrl(url, mode: LaunchMode.externalApplication);
                  },
                ),
            ],
          ),
        ),
        actions: [
          if (status == 'approved' && app['sms_sent']?.toString() != '1')
            TextButton.icon(
              icon: const Icon(Icons.sms),
              label: const Text('SMS دوبارہ بھیجیں'),
              onPressed: () {
                Navigator.pop(context);
                _resendSms(app['id']);
              },
            ),
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('بند کریں')),
        ],
      ),
    );
  }

  Widget _detailRow(String label, dynamic value) {
    String val = (value ?? '').toString();
    if (val.isEmpty) val = '-';
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
              flex: 2,
              child: Text('$label:',
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 13,
                      color: Colors.grey.shade800))),
          Expanded(flex: 3, child: Text(val, style: const TextStyle(fontSize: 13))),
        ],
      ),
    );
  }

  Color _badgeColor(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
        return Colors.green;
      case 'rejected':
        return Colors.red;
      case 'interview':
        return Colors.purple;
      default:
        return Colors.orange;
    }
  }

  String _badgeLabel(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
        return 'منظور شدہ';
      case 'rejected':
        return 'مسترد';
      case 'interview':
        return 'ٹیسٹ / انٹرویو';
      default:
        return 'زیرِ التواء';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: bgColor,
      appBar: AppBar(
        backgroundColor: primaryGreen,
        title: const Text('داخلہ درخواستیں',
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          IconButton(
              icon: const Icon(Icons.refresh), onPressed: _loadApplications),
        ],
      ),
      drawer: const AppDrawer(),
      body: Column(children: [
        // Stats Cards
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(children: [
            _statCard('زیر التواء', _counts['pending']!, Colors.orange),
            _statCard('انٹرویو', _counts['interview']!, Colors.purple),
            _statCard('منظور', _counts['approved']!, Colors.green),
            _statCard('مسترد', _counts['rejected']!, Colors.red),
          ]),
        ),
        // Filter Tabs
        SizedBox(
          height: 40,
          child: ListView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            itemCount: _filters.length,
            itemBuilder: (_, i) {
              final f = _filters[i];
              final label = {
                'all': 'تمام (${_applications.length})',
                'pending': 'زیر التواء (${_counts['pending']})',
                'interview': 'ٹیسٹ / انٹرویو (${_counts['interview']})',
                'approved': 'منظور شدہ (${_counts['approved']})',
                'rejected': 'مسترد (${_counts['rejected']})'
              }[f]!;
              return Padding(
                padding: const EdgeInsets.only(right: 8),
                child: FilterChip(
                  label: Text(label),
                  selected: _selectedFilter == f,
                  selectedColor: primaryGreen.withValues(alpha: 0.2),
                  onSelected: (_) {
                    setState(() => _selectedFilter = f);
                    _loadApplications();
                  },
                ),
              );
            },
          ),
        ),
        const SizedBox(height: 8),
        // List
        Expanded(
          child: _isLoading
              ? const Center(child: CircularProgressIndicator())
              : _errorMsg.isNotEmpty
                  ? Center(
                      child: Text(_errorMsg,
                          style: const TextStyle(color: Colors.red)))
                  : _applications.isEmpty
                      ? Center(
                          child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                              Icon(Icons.inbox_outlined,
                                  size: 64, color: Colors.grey.shade400),
                              const SizedBox(height: 12),
                              Text('کوئی درخواست موجود نہیں',
                                  style:
                                      TextStyle(color: Colors.grey.shade600)),
                            ]))
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 4),
                          itemCount: _applications.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: 8),
                          itemBuilder: (_, i) {
                            final app = _applications[i];
                            final status =
                                (app['status'] ?? 'pending').toString();
                            final phone = (app['mobile_number'] ??
                                    app['mobile_no'] ??
                                    '')
                                .toString();
                            final rawPhone =
                                phone.replaceAll(RegExp(r'[^0-9]'), '');
                            final cleanPhone = rawPhone.startsWith('0')
                                ? '92${rawPhone.substring(1)}'
                                : rawPhone;

                            return Card(
                              elevation: 2,
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12)),
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Row 1: Photo, Name, and Status Badge
                                    Row(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.center,
                                      children: [
                                        _buildPhotoAvatar(
                                            app['photo']?.toString(),
                                            size: 46),
                                        const SizedBox(width: 10),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                app['student_name'] ??
                                                    'نامعلوم',
                                                style: const TextStyle(
                                                    fontWeight: FontWeight.bold,
                                                    fontSize: 16),
                                              ),
                                              Text(
                                                'والد: ${app['father_name'] ?? ''}',
                                                style: TextStyle(
                                                    color: Colors.grey.shade700,
                                                    fontSize: 13),
                                              ),
                                            ],
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                              horizontal: 10, vertical: 4),
                                          decoration: BoxDecoration(
                                            color: _badgeColor(status),
                                            borderRadius:
                                                BorderRadius.circular(20),
                                          ),
                                          child: Text(
                                            _badgeLabel(status),
                                            style: const TextStyle(
                                                color: Colors.white,
                                                fontSize: 11,
                                                fontWeight: FontWeight.bold),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    // Row 2: Phone with direct WhatsApp button
                                    Row(
                                      children: [
                                        Icon(Icons.phone,
                                            size: 14,
                                            color: Colors.grey.shade600),
                                        const SizedBox(width: 4),
                                        Text(phone,
                                            style: TextStyle(
                                                color: Colors.grey.shade800,
                                                fontSize: 13)),
                                        if (cleanPhone.isNotEmpty) ...[
                                          const SizedBox(width: 8),
                                          InkWell(
                                            onTap: () {
                                              final url = Uri.parse(
                                                  "https://wa.me/$cleanPhone");
                                              launchUrl(url,
                                                  mode: LaunchMode
                                                      .externalApplication);
                                            },
                                            child: const Icon(
                                                Icons.chat_bubble_outline,
                                                color: Colors.green,
                                                size: 18),
                                          ),
                                        ],
                                        const Spacer(),
                                        if ((app['department'] ?? '')
                                            .toString()
                                            .isNotEmpty)
                                          Container(
                                            padding: const EdgeInsets.symmetric(
                                                horizontal: 8, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: Colors.grey.shade200,
                                              borderRadius:
                                                  BorderRadius.circular(6),
                                            ),
                                            child: Text(
                                              app['department'],
                                              style: TextStyle(
                                                  fontSize: 11,
                                                  color: Colors.grey.shade800),
                                            ),
                                          ),
                                      ],
                                    ),
                                    // Row 3: Test Date & Time if scheduled
                                    if ((app['test_date'] ?? '')
                                            .toString()
                                            .isNotEmpty ||
                                        (app['test_time'] ?? '')
                                            .toString()
                                            .isNotEmpty) ...[
                                      const SizedBox(height: 6),
                                      Container(
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 8, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: Colors.purple
                                              .withValues(alpha: 0.08),
                                          borderRadius:
                                              BorderRadius.circular(6),
                                        ),
                                        child: Row(
                                          children: [
                                            const Icon(Icons.calendar_today,
                                                size: 13,
                                                color: Colors.purple),
                                            const SizedBox(width: 6),
                                            Text(
                                              'ٹیسٹ: ${app['test_date'] ?? '-'}  ⏰ ${app['test_time'] ?? ''}',
                                              style: const TextStyle(
                                                  color: Colors.purple,
                                                  fontWeight: FontWeight.w600,
                                                  fontSize: 12),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                    if (status.toLowerCase() == 'approved') ...[
                                      const SizedBox(height: 4),
                                      Text(
                                        (app['sms_sent']?.toString() == '1')
                                            ? '✅ SMS بھیجا گیا'
                                            : '⚠️ SMS نہیں بھیجا گیا',
                                        style: TextStyle(
                                          color: (app['sms_sent']?.toString() ==
                                                  '1')
                                              ? Colors.green.shade700
                                              : Colors.orange.shade800,
                                          fontWeight: FontWeight.w600,
                                          fontSize: 12,
                                        ),
                                      ),
                                    ],
                                    const SizedBox(height: 10),
                                    // Action Buttons
                                    ElevatedButton.icon(
                                      icon: const Icon(Icons.visibility,
                                          size: 15, color: Colors.white),
                                      label: const Text('تفصیلات دیکھیں',
                                          style:
                                              TextStyle(color: Colors.white)),
                                      style: ElevatedButton.styleFrom(
                                          backgroundColor: Colors.blue,
                                          minimumSize:
                                              const Size(double.infinity, 34)),
                                      onPressed: () => _showDetailsModal(app),
                                    ),
                                    const SizedBox(height: 6),
                                    // Lifecycle Action Buttons
                                    if (status.toLowerCase() == 'pending') ...[
                                      Row(children: [
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(
                                                Icons.calendar_month,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('ٹیسٹ / انٹرویو',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 12)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor: Colors.purple,
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _showScheduleInterviewDialog(
                                                    app),
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(Icons.check,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('منظور',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 12)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor: Colors.green,
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _confirmApprove(app),
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(Icons.close,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('مسترد',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 12)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor: Colors.red,
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _confirmReject(app),
                                          ),
                                        ),
                                      ]),
                                    ] else if (status.toLowerCase() ==
                                        'interview') ...[
                                      Row(children: [
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(Icons.send,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('واٹس ایپ نوٹس',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 11)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor: Colors.green,
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _sendWhatsAppInterviewNotice(
                                              app['student_name'] ?? '',
                                              app['father_name'] ?? '',
                                              phone,
                                              app['test_date'] ?? '',
                                              app['test_time'] ?? '',
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(Icons.check_circle,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('داخلہ منظور',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 11)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor:
                                                    const Color(0xFF284D3D),
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _confirmApprove(app),
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            icon: const Icon(Icons.close,
                                                size: 15,
                                                color: Colors.white),
                                            label: const Text('مسترد',
                                                style: TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 11)),
                                            style: ElevatedButton.styleFrom(
                                                backgroundColor: Colors.red,
                                                minimumSize:
                                                    const Size(0, 36)),
                                            onPressed: () =>
                                                _confirmReject(app),
                                          ),
                                        ),
                                      ]),
                                    ] else if (status.toLowerCase() ==
                                            'approved' &&
                                        app['sms_sent']?.toString() !=
                                            '1') ...[
                                      OutlinedButton.icon(
                                        icon: const Icon(Icons.sms, size: 16),
                                        label: const Text('SMS دوبارہ بھیجیں'),
                                        style: OutlinedButton.styleFrom(
                                          minimumSize:
                                              const Size(double.infinity, 36),
                                        ),
                                        onPressed: () =>
                                            _resendSms(app['id']),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
        ),
      ]),
    );
  }

  Widget _statCard(String label, int count, Color color) {
    return Expanded(
      child: Card(
        margin: const EdgeInsets.symmetric(horizontal: 2),
        color: color.withValues(alpha: 0.1),
        shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
            side: BorderSide(color: color.withValues(alpha: 0.4))),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
          child: Column(children: [
            Text('$count',
                style: TextStyle(
                    fontSize: 20, fontWeight: FontWeight.bold, color: color)),
            Text(label,
                style: TextStyle(fontSize: 10, color: color),
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis),
          ]),
        ),
      ),
    );
  }
}

