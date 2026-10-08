// ignore_for_file: deprecated_member_use, use_build_context_synchronously
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/smart_alert_service.dart';
import '../utils/globals.dart';
import '../db_helper.dart';
import 'hifz.dart';
import 'attendance.dart';
import 'requests.dart';
import 'student_report.dart';

class SmartNotificationsPage extends StatefulWidget {
  const SmartNotificationsPage({super.key});

  @override
  State<SmartNotificationsPage> createState() => _SmartNotificationsPageState();
}

class _SmartNotificationsPageState extends State<SmartNotificationsPage> {
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color accentGold = const Color(0xFFC29B38);
  final Color bgColor = const Color(0xFFF6F8F6);

  List<SmartAlert> _allAlerts = [];
  bool _isLoading = true;
  AlertCategory? _selectedCategory; // null = All
  String _selectedDateFilter = 'today';
  DateTime? _customStartDate;
  DateTime? _customEndDate;
  String _selectedStatusFilter = 'all';

  @override
  void initState() {
    super.initState();
    _loadAlerts();
  }

  Future<void> _loadAlerts() async {
    setState(() => _isLoading = true);
    final alerts = await SmartAlertService().getAllAlerts();
    if (mounted) {
      setState(() {
        _allAlerts = alerts;
        _isLoading = false;
      });
    }
  }

  bool _passesFilters(SmartAlert alert) {
    // 1. Status Filter
    if (_selectedStatusFilter == 'read' && !alert.isRead) return false;
    if (_selectedStatusFilter == 'unread' && alert.isRead) return false;

    // 2. Date Filter
    if (_selectedDateFilter == 'all') return true;

    final alertDate = DateTime.tryParse(alert.date);
    if (alertDate == null) return true;
    final now = DateTime.now();

    if (_selectedDateFilter == 'today') {
      return alertDate.year == now.year && alertDate.month == now.month && alertDate.day == now.day;
    } else if (_selectedDateFilter == 'this_week') {
      final startOfWeek = now.subtract(Duration(days: now.weekday - 1));
      final endOfWeek = startOfWeek.add(const Duration(days: 6));
      return alertDate.isAfter(startOfWeek.subtract(const Duration(days: 1))) &&
             alertDate.isBefore(endOfWeek.add(const Duration(days: 1)));
    } else if (_selectedDateFilter == 'this_month') {
      return alertDate.year == now.year && alertDate.month == now.month;
    } else if (_selectedDateFilter == 'custom') {
      if (_customStartDate != null && alertDate.isBefore(_customStartDate!)) return false;
      if (_customEndDate != null && alertDate.isAfter(_customEndDate!)) return false;
    }

    return true;
  }

  List<SmartAlert> get _filteredAlerts {
    return _allAlerts.where((a) {
      if (_selectedCategory != null && a.category != _selectedCategory) return false;
      return _passesFilters(a);
    }).toList();
  }

  int _countForCategory(AlertCategory? cat) {
    return _allAlerts.where((a) {
      if (cat != null && a.category != cat) return false;
      return _passesFilters(a);
    }).length;
  }

  Color _severityColor(AlertSeverity severity) {
    switch (severity) {
      case AlertSeverity.critical:
        return const Color(0xFFD32F2F); // Red
      case AlertSeverity.warning:
        return const Color(0xFFE65100); // Amber/Orange
      case AlertSeverity.info:
        return const Color(0xFF1976D2); // Blue/Info
    }
  }

  Color _severityBgColor(AlertSeverity severity) {
    switch (severity) {
      case AlertSeverity.critical:
        return const Color(0xFFFFEBEE);
      case AlertSeverity.warning:
        return const Color(0xFFFFF3E0);
      case AlertSeverity.info:
        return const Color(0xFFE3F2FD);
    }
  }

  IconData _categoryIcon(AlertCategory category) {
    switch (category) {
      case AlertCategory.academic:
        return Icons.menu_book;
      case AlertCategory.attendance:
        return Icons.how_to_reg;
      case AlertCategory.finance:
        return Icons.account_balance_wallet;
      case AlertCategory.admin:
        return Icons.admin_panel_settings;
    }
  }

  Future<void> _makeCall(String phone) async {
    final cleanPhone = phone.replaceAll(RegExp(r'[^0-9+]'), '');
    if (cleanPhone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('فون نمبر دستیاب نہیں ہے')),
      );
      return;
    }
    final uri = Uri.parse('tel:$cleanPhone');
    try {
      final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!launched) {
        await launchUrl(uri);
      }
    } catch (_) {
      try {
        await launchUrl(uri);
      } catch (e) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('کال ملانے میں دشواری ہے')),
        );
      }
    }
  }

  Future<void> _openWhatsApp(String phone, String name) async {
    String cleanPhone = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (cleanPhone.startsWith('0092')) {
      cleanPhone = cleanPhone.substring(2);
    } else if (cleanPhone.startsWith('0')) {
      cleanPhone = '92${cleanPhone.substring(1)}';
    }
    if (cleanPhone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('واٹس ایپ نمبر دستیاب نہیں ہے')),
      );
      return;
    }
    final msg = Uri.encodeComponent('محترم سرپرست، جامعہ اشرف المدارس سے آپ کے بچے $name کے متعلق اطلاع پیش خدمت ہے۔');
    final webUri = Uri.parse('https://wa.me/$cleanPhone?text=$msg');
    final nativeUri = Uri.parse('whatsapp://send?phone=$cleanPhone&text=$msg');

    try {
      final launched = await launchUrl(webUri, mode: LaunchMode.externalApplication);
      if (!launched) {
        await launchUrl(nativeUri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {
      try {
        await launchUrl(nativeUri, mode: LaunchMode.externalApplication);
      } catch (e) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('واٹس ایپ کھولنے میں دشواری ہے')),
        );
      }
    }
  }

  void _handleViewReport(SmartAlert alert) {
    if (alert.studentId != null) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => StudentReportPage(forceStudentId: alert.studentId.toString()),
        ),
      );
    }
  }

  void _handleAction(SmartAlert alert) {
    switch (alert.actionType) {
      case 'hifz':
      case 'qaida':
        Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => HifzPage(className: alert.className)),
        );
        break;
      case 'attendance':
      case 'teacher_attendance':
        Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const AttendancePage()),
        );
        break;
      case 'fee':
      case 'leave':
      case 'complaint':
        Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const RequestsPage()),
        );
        break;
      default:
        if (alert.studentId != null) {
          _handleViewReport(alert);
        }
        break;
    }
  }

  Future<void> _toggleReadStatus(SmartAlert alert) async {
    final newStatus = !alert.isRead;
    final userId = currentUserId.value ?? 1;
    await DBHelper().toggleSmartAlertRead(alert.id, userId, newStatus);
    setState(() {
      alert.isRead = newStatus;
    });
  }

  @override
  Widget build(BuildContext context) {
    final isUrdu = isUrduGlobal.value;

    return Directionality(
      textDirection: isUrdu ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        backgroundColor: bgColor,
        appBar: AppBar(
          title: Text(
            isUrdu ? 'اسمارٹ نوٹیفیکیشن و الرٹ پینل' : 'Smart Notifications & Alerts',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
          ),
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          centerTitle: true,
          elevation: 2,
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh, color: Colors.white),
              tooltip: isUrdu ? 'تازہ کریں' : 'Refresh',
              onPressed: _loadAlerts,
            ),
          ],
        ),
        body: Column(
          children: [
            _buildTopFiltersBar(isUrdu),
            _buildCategoryFilterBar(isUrdu),
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _filteredAlerts.isEmpty
                      ? _buildEmptyState(isUrdu)
                      : RefreshIndicator(
                          onRefresh: _loadAlerts,
                          child: ListView.builder(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                            itemCount: _filteredAlerts.length,
                            itemBuilder: (context, index) {
                              final alert = _filteredAlerts[index];
                              return _buildAlertCard(alert, isUrdu);
                            },
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _selectCustomDateRange() async {
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
      initialDateRange: _customStartDate != null && _customEndDate != null
          ? DateTimeRange(start: _customStartDate!, end: _customEndDate!)
          : DateTimeRange(start: DateTime.now().subtract(const Duration(days: 7)), end: DateTime.now()),
    );
    if (range != null) {
      setState(() {
        _customStartDate = range.start;
        _customEndDate = range.end;
        _selectedDateFilter = 'custom';
      });
    } else {
      // Revert if cancelled and previously custom wasn't fully set
      if (_customStartDate == null) {
        setState(() => _selectedDateFilter = 'today');
      }
    }
  }

  Widget _buildTopFiltersBar(bool isUrdu) {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      child: Row(
        children: [
          Expanded(
            child: DropdownButtonFormField<String>(
              decoration: InputDecoration(
                labelText: isUrdu ? 'تاریخ فلٹر' : 'Date Filter',
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
              value: _selectedDateFilter,
              items: [
                DropdownMenuItem(value: 'today', child: Text(isUrdu ? 'آج' : 'Today')),
                DropdownMenuItem(value: 'this_week', child: Text(isUrdu ? 'رواں ہفتہ' : 'This Week')),
                DropdownMenuItem(value: 'this_month', child: Text(isUrdu ? 'رواں مہینہ' : 'This Month')),
                DropdownMenuItem(value: 'all', child: Text(isUrdu ? 'تمام' : 'All')),
                DropdownMenuItem(value: 'custom', child: Text(isUrdu ? 'منتخب تاریخ' : 'Custom')),
              ],
              onChanged: (val) {
                if (val == 'custom') {
                  _selectCustomDateRange();
                } else {
                  setState(() => _selectedDateFilter = val!);
                }
              },
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: DropdownButtonFormField<String>(
              decoration: InputDecoration(
                labelText: isUrdu ? 'اسٹیٹس فلٹر' : 'Status Filter',
                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
              value: _selectedStatusFilter,
              items: [
                DropdownMenuItem(value: 'all', child: Text(isUrdu ? 'تمام' : 'All')),
                DropdownMenuItem(value: 'unread', child: Text(isUrdu ? 'ان دیکھے' : 'Unread')),
                DropdownMenuItem(value: 'read', child: Text(isUrdu ? 'دیکھے گئے' : 'Read')),
              ],
              onChanged: (val) {
                setState(() => _selectedStatusFilter = val!);
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildCategoryFilterBar(bool isUrdu) {
    final categories = [
      {'label': isUrdu ? 'تمام' : 'All', 'cat': null},
      {'label': isUrdu ? 'حفظ و تعلیم' : 'Academics', 'cat': AlertCategory.academic},
      {'label': isUrdu ? 'حاضری' : 'Attendance', 'cat': AlertCategory.attendance},
      {'label': isUrdu ? 'مالیات' : 'Finance', 'cat': AlertCategory.finance},
      {'label': isUrdu ? 'انتظامیہ' : 'Admin', 'cat': AlertCategory.admin},
    ];

    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        padding: const EdgeInsets.symmetric(horizontal: 10),
        child: Row(
          children: categories.map((item) {
            final cat = item['cat'] as AlertCategory?;
            final label = item['label'] as String;
            final isSelected = _selectedCategory == cat;
            final count = _countForCategory(cat);

            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: FilterChip(
                selected: isSelected,
                label: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(label),
                    if (count > 0) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: isSelected ? Colors.white : primaryGreen.withOpacity(0.12),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text(
                          '$count',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: isSelected ? primaryGreen : Colors.black87,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
                backgroundColor: Colors.grey.shade100,
                selectedColor: primaryGreen,
                labelStyle: TextStyle(
                  color: isSelected ? Colors.white : Colors.black87,
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                ),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(20),
                  side: BorderSide(
                    color: isSelected ? primaryGreen : Colors.grey.shade300,
                  ),
                ),
                onSelected: (_) {
                  setState(() => _selectedCategory = cat);
                },
              ),
            );
          }).toList(),
        ),
      ),
    );
  }

  Widget _buildAlertCard(SmartAlert alert, bool isUrdu) {
    final sColor = alert.isRead ? Colors.grey.shade400 : _severityColor(alert.severity);
    final sBgColor = alert.isRead ? Colors.grey.shade200 : _severityBgColor(alert.severity);

    return Opacity(
      opacity: alert.isRead ? 0.65 : 1.0,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(
          color: alert.isRead ? Colors.grey.shade50 : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border(
          right: isUrdu ? BorderSide(color: sColor, width: 5) : BorderSide.none,
          left: !isUrdu ? BorderSide(color: sColor, width: 5) : BorderSide.none,
          top: BorderSide(color: Colors.grey.shade200),
          bottom: BorderSide(color: Colors.grey.shade200),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Padding(
        padding: EdgeInsets.only(
          top: 14,
          bottom: 12,
          left: isUrdu ? 12 : 16,
          right: isUrdu ? 16 : 12,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: sBgColor,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    _categoryIcon(alert.category),
                    color: sColor,
                    size: 20,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              alert.title,
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 15,
                                color: alert.severity == AlertSeverity.critical ? sColor : Colors.black87,
                              ),
                            ),
                          ),
                          if (alert.severity == AlertSeverity.critical)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: BoxDecoration(
                                color: sColor,
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: Text(
                                isUrdu ? 'فوری توجہ' : 'Urgent',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 10,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        alert.description,
                        style: const TextStyle(
                          fontSize: 13,
                          color: Colors.black87,
                          height: 1.4,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            const Divider(height: 1),
            const SizedBox(height: 8),
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 8,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.calendar_today, size: 13, color: Colors.grey.shade600),
                    const SizedBox(width: 4),
                    Text(
                      alert.date,
                      style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                    ),
                    if (alert.className != null && alert.className!.isNotEmpty) ...[
                      const SizedBox(width: 10),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: Colors.grey.shade300),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.school, size: 12, color: Colors.grey.shade700),
                            const SizedBox(width: 4),
                            Text(
                              alert.className!,
                              style: TextStyle(fontSize: 11, color: Colors.grey.shade700, fontWeight: FontWeight.w600),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (alert.contactNumber != null && alert.contactNumber!.isNotEmpty) ...[
                      IconButton(
                        icon: const Icon(Icons.call, color: Colors.green, size: 19),
                        tooltip: isUrdu ? 'فون کال' : 'Call',
                        onPressed: () => _makeCall(alert.contactNumber!),
                        constraints: const BoxConstraints(),
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                      ),
                      IconButton(
                        icon: const Icon(Icons.chat, color: Color(0xFF25D366), size: 19),
                        tooltip: isUrdu ? 'واٹس ایپ' : 'WhatsApp',
                        onPressed: () => _openWhatsApp(alert.contactNumber!, alert.studentName ?? 'طالب علم'),
                        constraints: const BoxConstraints(),
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                      ),
                    ],
                    if (alert.studentId != null)
                      IconButton(
                        icon: const Icon(Icons.visibility, color: Colors.blueGrey, size: 19),
                        tooltip: isUrdu ? 'رپورٹ دیکھیں' : 'View Report',
                        onPressed: () => _handleViewReport(alert),
                        constraints: const BoxConstraints(),
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                      ),
                    if (alert.actionType != null) ...[
                      const SizedBox(width: 4),
                      InkWell(
                        onTap: () => _handleAction(alert),
                        borderRadius: BorderRadius.circular(6),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: primaryGreen.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: primaryGreen.withOpacity(0.3)),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                isUrdu ? 'کارروائی' : 'Action',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                  color: primaryGreen,
                                ),
                              ),
                              const SizedBox(width: 4),
                              Icon(
                                isUrdu ? Icons.arrow_back : Icons.arrow_forward,
                                size: 12,
                                color: primaryGreen,
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(width: 4),
                    IconButton(
                      icon: Icon(
                        alert.isRead ? Icons.check_circle : Icons.check_circle_outline,
                        color: alert.isRead ? Colors.green : Colors.grey.shade400,
                        size: 20,
                      ),
                      tooltip: alert.isRead ? (isUrdu ? 'ان دیکھا کریں' : 'Mark Unread') : (isUrdu ? 'پڑھا ہوا مارک کریں' : 'Mark Read'),
                      onPressed: () => _toggleReadStatus(alert),
                      constraints: const BoxConstraints(),
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    ));
  }

  Widget _buildEmptyState(bool isUrdu) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.check_circle_outline, size: 70, color: Colors.green.shade400),
          const SizedBox(height: 14),
          Text(
            isUrdu ? 'کوئی نیا نوٹیفیکیشن یا الرٹ نہیں ہے!' : 'No new notifications or alerts!',
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black87),
          ),
          const SizedBox(height: 6),
          Text(
            isUrdu ? 'تمام طلبہ اور اساتذہ کا تعلیمی و انتظامی ریکارڈ مکمل ہے۔' : 'All academic and administrative records are in order.',
            style: TextStyle(fontSize: 13, color: Colors.grey.shade600),
          ),
        ],
      ),
    );
  }
}
