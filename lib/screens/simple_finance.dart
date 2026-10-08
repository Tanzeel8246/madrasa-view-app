// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use

import 'dart:io';


import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'package:image_picker/image_picker.dart';

import 'package:path_provider/path_provider.dart';

import 'package:screenshot/screenshot.dart';

import 'package:share_plus/share_plus.dart';

import 'package:intl/intl.dart';

import '../db_helper.dart';

import '../utils/globals.dart';
import '../utils/hijri_helper.dart';

import '../widgets/app_drawer.dart';

import '../utils/sync_service.dart';

import '../utils/urdu_number_words.dart';

import 'dashboard.dart';
import 'acc_payroll.dart';
import '../utils/system_type_global.dart';
import 'dart:convert';
import 'package:flutter/services.dart';
import '../services/finance_otp_service.dart';



class SimpleFinancePage extends StatefulWidget {

  const SimpleFinancePage({super.key});



  @override

  State<SimpleFinancePage> createState() => _SimpleFinancePageState();

}



class _SimpleFinancePageState extends State<SimpleFinancePage> {

  final Color bgColor = const Color(0xFFF1EFE7);

  final Color primaryGreen = const Color(0xFF284D3D);

  final ScreenshotController _screenshotController = ScreenshotController();

  final ImagePicker _picker = ImagePicker();

  final ScrollController _scrollController = ScrollController();



  File? _signatureImage;

  bool isIncome = true;

  int? _editingId;
  int? _editingUserId;
  String? _editingUserName;
  int? _editingAccountId;
  int? _selectedEntryUserId;
  String? _selectedEntryUserName;
  List<Map<String, dynamic>> _systemUsersList = [];
  bool _isSaving = false;
  File? _billImageFile;
  String? _billImageString;
  int _pendingOtpsCount = 0;

  bool get _isAdminRole {
    final role = currentUserRole.value?.toLowerCase() ?? '';
    return role == 'admin' || role == 'superadmin' || role == 'super_admin' || role == 'administrator';
  }



  final _dateController = TextEditingController();

  final _amountController = TextEditingController();

  final _donorController = TextEditingController();
  final _spenderController = TextEditingController();

  final _addressController = TextEditingController();
  final _manualReceiptController = TextEditingController();

  final _descController = TextEditingController();

  int? _selectedPaymentAccountId;
  String _selectedPaymentMethod = 'Cash';

  int _selectedTabIndex = 0; // 0: Income, 1: Expense, 2: Transfers
  final _transferDateController = TextEditingController();
  final _transferAmountController = TextEditingController();
  final _transferDescController = TextEditingController();
  String? _selectedFromOption;
  String? _selectedToOption;
  bool _isTransferLoading = false;
  double _userAdvanceBalance = 0.0;
  Map<int, Map<String, dynamic>> _userAdvanceSummaries = {};
  int? _selectedAdvanceAccountId;
  double _totalOverallAdvanceReceived = 0.0;
  double _totalOverallAdvanceDeducted = 0.0;
  double _totalOverallAdvanceBalance = 0.0;

  double _userCollectionBalance = 0.0;
  Map<int, Map<String, dynamic>> _userCollectionSummaries = {};
  int? _selectedCollectionAccountId;
  double _totalOverallCollectionReceived = 0.0;
  double _totalOverallCollectionTransferred = 0.0;

  List<Map<String, dynamic>> _transferRecords = [];
  List<Map<String, dynamic>> _assetAccounts = [];
  List<Map<String, dynamic>> _teachersList = [];
  List<String> _otherUsersList = [];

  String? selectedHead;
  List<Map<String, dynamic>> _financeRecords = [];
  Map<int, Map<String, dynamic>> _userGroups = {};
  Set<int> _expandedUsers = {};
  final Map<String, bool> _customBoxExpansion = {};

  String _selectedPeriod = 'current_month'; // 'current_month', 'last_month', 'current_year', 'custom'
  DateTime? _customStartDate;
  DateTime? _customEndDate;

  DateTimeRange _getEffectiveDateRange() {
    final now = DateTime.now();
    switch (_selectedPeriod) {
      case 'last_month':
        final firstDayLastMonth = DateTime(now.year, now.month - 1, 1);
        final lastDayLastMonth = DateTime(now.year, now.month, 0);
        return DateTimeRange(start: firstDayLastMonth, end: lastDayLastMonth);
      case 'current_year':
        return DateTimeRange(start: DateTime(now.year, 1, 1), end: DateTime(now.year, 12, 31));
      case 'custom':
        return DateTimeRange(
          start: _customStartDate ?? DateTime(now.year, now.month, 1),
          end: _customEndDate ?? DateTime(now.year, now.month + 1, 0),
        );
      case 'current_month':
      default:
        return DateTimeRange(
          start: DateTime(now.year, now.month, 1),
          end: DateTime(now.year, now.month + 1, 0),
        );
    }
  }

  String _getPeriodDisplayLabel(bool isUrdu) {
    final now = DateTime.now();
    switch (_selectedPeriod) {
      case 'last_month':
        final lastMonthDate = DateTime(now.year, now.month - 1, 1);
        return isUrdu ? 'پچھلا مہینہ (${DateFormat('MMMM yyyy').format(lastMonthDate)})' : 'Last Month';
      case 'current_year':
        return isUrdu ? 'رواں سال (${now.year})' : 'Current Year (${now.year})';
      case 'custom':
        final range = _getEffectiveDateRange();
        return isUrdu
            ? 'کسٹم مدت (${DateFormat('yyyy-MM-dd').format(range.start)} تا ${DateFormat('yyyy-MM-dd').format(range.end)})'
            : 'Custom (${DateFormat('yyyy-MM-dd').format(range.start)} - ${DateFormat('yyyy-MM-dd').format(range.end)})';
      case 'current_month':
      default:
        return isUrdu ? 'رواں ماہ (${DateFormat('MMMM yyyy').format(now)})' : 'Current Month';
    }
  }

  DateTime? _tryParseAnyDate(String? raw) {
    if (raw == null) return null;
    final trimmed = raw.trim();
    if (trimmed.isEmpty) return null;

    try {
      final isoPart = trimmed.length >= 10 ? trimmed.substring(0, 10) : trimmed;
      if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(isoPart)) {
        return DateTime.parse(isoPart);
      }
    } catch (_) {}

    if (trimmed.contains('/')) {
      final parts = trimmed.split(' ')[0].split('/');
      if (parts.length == 3) {
        if (parts[0].length == 4) {
          final y = int.tryParse(parts[0]);
          final m = int.tryParse(parts[1]);
          final d = int.tryParse(parts[2]);
          if (y != null && m != null && d != null) {
            return DateTime(y, m, d);
          }
        } else if (parts[2].length == 4) {
          final d = int.tryParse(parts[0]);
          final m = int.tryParse(parts[1]);
          final y = int.tryParse(parts[2]);
          if (y != null && m != null && d != null) {
            return DateTime(y, m, d);
          }
        }
      }
    }

    if (trimmed.contains('-')) {
      final parts = trimmed.split(' ')[0].split('-');
      if (parts.length == 3 && parts[2].length == 4) {
        final d = int.tryParse(parts[0]);
        final m = int.tryParse(parts[1]);
        final y = int.tryParse(parts[2]);
        if (y != null && m != null && d != null) {
          return DateTime(y, m, d);
        }
      }
    }

    try {
      return DateTime.tryParse(trimmed);
    } catch (_) {
      return null;
    }
  }

  bool _isRecordInSelectedPeriod(Map<String, dynamic> r) {
    if (_selectedPeriod == 'all_time') return true;
    final dateStr = r['date']?.toString() ?? '';
    if (dateStr.isEmpty) return false;
    final d = _tryParseAnyDate(dateStr);
    if (d == null) return false;
    final range = _getEffectiveDateRange();
    final startOnly = DateTime(range.start.year, range.start.month, range.start.day);
    final endOnly = DateTime(range.end.year, range.end.month, range.end.day, 23, 59, 59);
    return !d.isBefore(startOnly) && !d.isAfter(endOnly);
  }

  List<Map<String, dynamic>> _dbHeads = [];

  List<String> incomeHeadsUr = [
    'صدقہ',
    'عطیات',
    'فطرانہ',
    'کھالیں قربانی',
    'عشر',
    'فیس',
    'امداد',
    'زکوٰۃ',
    'متفرق آمدن'
  ];

  List<String> expenseHeadsUr = [
    'راشن',
    'بجلی بل',
    'مرمت',
    'تعمیرات',
    'ادویات',
    'انٹرنیٹ بل',
    'انعامات',
    'ضیافت',
    'موبائل پیکیج',
    'گیس بل',
    'متفرق خرچ'
  ];

  List<String> incomeHeadsEn = [
    'Sadaqah',
    'Donation',
    'Fitrana',
    'Qurbani Skin',
    'Ushr',
    'Fee',
    'Zakat',
    'Misc Income'
  ];

  List<String> expenseHeadsEn = [
    'Ration',
    'Electricity Bill',
    'Maintenance',
    'Construction',
    'Medical',
    'Internet Bill',
    'Rewards',
    'Hospitality',
    'Mobile Package',
    'Gas Bill',
    'Misc Expense'
  ];

  static const Map<String, String> _headTranslationsToUr = {
    'sadaqah': 'صدقہ',
    'donation': 'عطیات',
    'donation / sadqa': 'عطیات و صدقات',
    'fitrana': 'فطرانہ',
    'qurbani skin': 'کھالیں قربانی',
    'ushr': 'عشر',
    'fee': 'فیس',
    'fee income': 'آمدن فیس',
    'aid': 'امداد',
    'zakat': 'زکوٰۃ',
    'misc income': 'متفرق آمدن',
    'ration': 'راشن',
    'electricity bill': 'بجلی بل',
    'utility bills': 'یوٹیلیٹی بلز',
    'maintenance': 'مرمت',
    'construction': 'تعمیرات',
    'stipends': 'وظائف',
    'teacher salary': 'اساتذہ کی تنخواہیں',
    'medical': 'ادویات',
    'internet bill': 'انٹرنیٹ بل',
    'rewards': 'انعامات',
    'hospitality': 'ضیافت',
    'mobile package': 'موبائل پیکیج',
    'gas bill': 'گیس بل',
    'misc expense': 'متفرق خرچ',
  };

  static const Map<String, String> _headTranslationsToEn = {
    'صدقہ': 'Sadaqah',
    'عطیات': 'Donation',
    'عطیات و صدقات': 'Donation / Sadqa',
    'فطرانہ': 'Fitrana',
    'کھالیں قربانی': 'Qurbani Skin',
    'عشر': 'Ushr',
    'فیس': 'Fee',
    'آمدن فیس': 'Fee Income',
    'امداد': 'Aid',
    'زکوٰۃ': 'Zakat',
    'متفرق آمدن': 'Misc Income',
    'راشن': 'Ration',
    'بجلی بل': 'Electricity Bill',
    'یوٹیلیٹی بلز': 'Utility Bills',
    'مرمت': 'Maintenance',
    'تعمیرات': 'Construction',
    'وظائف': 'Stipends',
    'اساتذہ کی تنخواہیں': 'Teacher Salary',
    'ادویات': 'Medical',
    'انٹرنیٹ بل': 'Internet Bill',
    'انعامات': 'Rewards',
    'ضیافت': 'Hospitality',
    'موبائل پیکیج': 'Mobile Package',
    'گیس بل': 'Gas Bill',
    'متفرق خرچ': 'Misc Expense',
  };

  String _localizeHead(String name, bool isUrdu) {
    final trimmed = name.trim();
    if (trimmed.isEmpty) return trimmed;
    if (isUrdu) {
      return _headTranslationsToUr[trimmed.toLowerCase()] ?? trimmed;
    } else {
      return _headTranslationsToEn[trimmed] ??
          _headTranslationsToEn[trimmed.toLowerCase()] ??
          trimmed;
    }
  }

  List<String> get currentHeads {
    final isUrdu = isUrduGlobal.value;

    final fromDb = _dbHeads
        .where((h) {
          final t = (h['type'] ?? '').toString().toLowerCase();
          final match = isIncome
              ? (t == 'income' || t.contains('revenue'))
              : (t == 'expense' || t.contains('expenditure'));
          final notDeleted = (h['is_deleted'] ?? 0) == 0;
          return match && notDeleted;
        })
        .map((h) => _localizeHead((h['name'] ?? '').toString(), isUrdu))
        .where((n) => n.trim().isNotEmpty)
        .toSet()
        .toList();

    if (fromDb.isNotEmpty) {
      fromDb.sort((a, b) => a.compareTo(b));
      return fromDb;
    }

    // Fallback only if database heads are completely empty
    final defaultList = isUrdu
        ? (isIncome ? incomeHeadsUr : expenseHeadsUr)
        : (isIncome ? incomeHeadsEn : expenseHeadsEn);
    final list = defaultList.toList();
    list.sort((a, b) => a.compareTo(b));
    return list;
  }

  bool _isBoxExpanded(String key, {required bool isDefault}) {
    return _customBoxExpansion[key] ?? isDefault;
  }

  void _toggleBox(String key, {required bool currentDefault}) {
    setState(() {
      final current = _isBoxExpanded(key, isDefault: currentDefault);
      _customBoxExpansion[key] = !current;
    });
  }

  Key _autocompleteKey = UniqueKey();

  @override
  void initState() {
    super.initState();
    _dateController.text = _formatDate(DateTime.now());
    _transferDateController.text = _formatDate(DateTime.now());
    _loadTransactions();
    _loadHeads();
    _loadAdvanceBalance();
    _loadCollectionBalance();
    _loadTransferMetadata();
    _loadTransfers();
    _fetchAdminOtpsCount();
  }

  @override
  void dispose() {
    _dateController.dispose();
    _amountController.dispose();
    _donorController.dispose();
    _spenderController.dispose();
    _addressController.dispose();
    _manualReceiptController.dispose();
    _descController.dispose();
    _transferDateController.dispose();
    _transferAmountController.dispose();
    _transferDescController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  bool _isMyAdvanceAccount(String accountName) {
    final lowerAcc = accountName.toLowerCase();
    if (!lowerAcc.contains('ایڈوانس') && !lowerAcc.contains('خرچ کھاتہ') && !lowerAcc.contains('advance')) return false;

    final uname = (currentUserName.value ?? '').trim().toLowerCase();
    final role = (currentUserRole.value ?? '').trim().toLowerCase();
    final uid = currentUserId.value;

    if (uname.isEmpty && uid == null) return false;

    // Direct alias matching for key known users
    if (uid == 3 || uname.contains('saleem') || uname.contains('سلیم')) {
      if (lowerAcc.contains('سلیم') || lowerAcc.contains('saleem') || lowerAcc.contains('1503')) {
        return true;
      }
    }
    if (uid == 1 || uname.contains('tanzeel') || uname.contains('تنزیل')) {
      if (lowerAcc.contains('تنزیل') || lowerAcc.contains('tanzeel') || lowerAcc.contains('1502')) {
        return true;
      }
    }
    if (uid == 4 || uname.contains('usman') || uname.contains('عثمان')) {
      if (lowerAcc.contains('عثمان') || lowerAcc.contains('usman') || lowerAcc.contains('1501')) {
        return true;
      }
    }
    if (uid == 2 || uname.contains('hammad') || uname.contains('حماد')) {
      if (lowerAcc.contains('حماد') || lowerAcc.contains('hammad') || lowerAcc.contains('1633')) {
        return true;
      }
    }

    if (uname.isNotEmpty && lowerAcc.contains(uname)) return true;

    final pureAcc = lowerAcc
        .replaceAll('ایڈوانس', '')
        .replaceAll('خرچ کھاتہ', '')
        .replaceAll('advance', '')
        .replaceAll('-', '')
        .replaceAll(':', '')
        .trim();

    if (uname.isNotEmpty && pureAcc.isNotEmpty && (uname.contains(pureAcc) || pureAcc.contains(uname))) {
      return true;
    }

    if (role == 'nazim' && (lowerAcc.contains('ناظم') || lowerAcc.contains('nazim') || pureAcc.contains('ناظم'))) {
      return true;
    }

    for (final t in _teachersList) {
      final tName = (t['name']?.toString() ?? '').trim().toLowerCase();
      final tUser = (t['user_name']?.toString() ?? t['username']?.toString() ?? '').trim().toLowerCase();
      final tId = int.tryParse(t['id']?.toString() ?? '');
      bool matchesMe = false;
      if (uid != null && tId != null && tId == uid) {
        matchesMe = true;
      } else if (uname.isNotEmpty) {
        if (tName.isNotEmpty && (tName == uname || uname.contains(tName) || tName.contains(uname))) {
          matchesMe = true;
        } else if (tUser.isNotEmpty && (tUser == uname || uname.contains(tUser))) {
          matchesMe = true;
        }
      }
      if (matchesMe) {
        if (tName.isNotEmpty && (lowerAcc.contains(tName) || (pureAcc.isNotEmpty && (pureAcc.contains(tName) || tName.contains(pureAcc))))) {
          return true;
        }
        if (tUser.isNotEmpty && lowerAcc.contains(tUser)) {
          return true;
        }
      }
    }
    return false;
  }

  bool _isOtherUserAdvanceAccount(String accountName) {
    final lowerAcc = accountName.toLowerCase();
    if (!lowerAcc.contains('ایڈوانس') && !lowerAcc.contains('خرچ کھاتہ') && !lowerAcc.contains('advance')) return false;
    return !_isMyAdvanceAccount(accountName);
  }

  bool _isMyCollectionAccount(String accountName) {
    final lowerAcc = accountName.toLowerCase();
    final uname = (currentUserName.value ?? '').trim().toLowerCase();
    final uid = currentUserId.value;
    if (uid == 3 || uname.contains('saleem') || uname.contains('سلیم')) {
      if (lowerAcc.contains('سلیم') || lowerAcc.contains('saleem')) return true;
    }
    if (uid == 1 || uname.contains('tanzeel') || uname.contains('تنزیل')) {
      if (lowerAcc.contains('تنزیل') || lowerAcc.contains('tanzeel')) return true;
    }
    if (uname.isNotEmpty && lowerAcc.contains(uname)) return true;
    return false;
  }

  bool _isOtherUserCollectionAccount(String accountName) {
    final lowerAcc = accountName.toLowerCase();
    if (!lowerAcc.contains('وصولی') && !lowerAcc.contains('collection')) return false;
    return !_isMyCollectionAccount(accountName);
  }

  List<DropdownMenuItem<int>> _buildUserDropdownItems() {
    final list = <DropdownMenuItem<int>>[];
    final seenIds = <int>{};

    for (final u in _systemUsersList) {
      final uid = int.tryParse(u['id']?.toString() ?? '') ?? 0;
      if (uid <= 0 || seenIds.contains(uid)) continue;
      seenIds.add(uid);
      final uname = (u['name']?.toString().isNotEmpty == true)
          ? u['name'].toString()
          : (u['username']?.toString() ?? 'User #$uid');
      list.add(
        DropdownMenuItem<int>(
          value: uid,
          child: Text(
            '$uname (ID: $uid)',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
          ),
        ),
      );
    }

    for (final t in _teachersList) {
      final tid = int.tryParse(t['id']?.toString() ?? '') ?? 0;
      if (tid <= 0 || seenIds.contains(tid)) continue;
      seenIds.add(tid);
      final tname = t['name']?.toString() ?? 'استاد #$tid';
      list.add(
        DropdownMenuItem<int>(
          value: tid,
          child: Text(
            '$tname (استاد #$tid)',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
          ),
        ),
      );
    }

    if (!seenIds.contains(1)) {
      list.insert(
        0,
        const DropdownMenuItem<int>(
          value: 1,
          child: Text('Admin (ID: 1)', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        ),
      );
    }

    return list;
  }

  String _resolveUserName(int uid) {
    for (final u in _systemUsersList) {
      if (int.tryParse(u['id']?.toString() ?? '') == uid) {
        return (u['name']?.toString().isNotEmpty == true)
            ? u['name'].toString()
            : (u['username']?.toString() ?? 'Admin');
      }
    }
    for (final t in _teachersList) {
      if (int.tryParse(t['id']?.toString() ?? '') == uid) {
        return t['name']?.toString() ?? 'Teacher';
      }
    }
    return 'صارف #$uid';
  }

  bool _isMyTeacherRecord(Map<String, dynamic> teacher) {
    final uname = (currentUserName.value ?? '').trim().toLowerCase();
    if (uname.isEmpty) return false;
    final tName = (teacher['name']?.toString() ?? '').trim().toLowerCase();
    final tUser = (teacher['user_name']?.toString() ?? teacher['username']?.toString() ?? '').trim().toLowerCase();
    if (tName.isNotEmpty && (tName == uname || uname.contains(tName) || tName.contains(uname))) {
      return true;
    }
    if (tUser.isNotEmpty && (tUser == uname || uname.contains(tUser))) {
      return true;
    }
    return false;
  }

  List<Map<String, dynamic>> _getExpenseAccounts() {
    if (_isAdminRole) {
      final list = <Map<String, dynamic>>[];
      final addedIds = <String>{};

      // 1. Central Cash 1001 (مرکزی کیش) FIRST so it's default
      for (final a in _assetAccounts) {
        if (a['code']?.toString().trim() == '1001') {
          list.add(a);
          addedIds.add(a['id']?.toString() ?? '');
          break;
        }
      }

      // 2. Bank accounts (exclude loans)
      for (final a in _assetAccounts) {
        final code = a['code']?.toString().trim() ?? '';
        final name = (a['name']?.toString() ?? '').toLowerCase();
        final id = a['id']?.toString() ?? '';
        if (addedIds.contains(id)) continue;
        if (code.toUpperCase().contains('LOAN') || name.contains('قرض') || name.contains('لون')) continue;
        if (code == '1002' ||
            name.contains('bank') ||
            name.contains('بینک') ||
            name.contains('meezan') ||
            name.contains('hbl') ||
            name.contains('mcb') ||
            name.contains('ubl') ||
            name.contains('jazzcash') ||
            name.contains('easypaisa')) {
          list.add(a);
          addedIds.add(id);
        }
      }

      // 3. Exactly the 4 canonical users' expense accounts (deduplicated)
      const canonicalCodes = {'1501', '1502', '1503', '1633'};
      const excludedCodes = {'1504', '1505', '1632', '1635', '1636', '1637', '1639'};
      final seenUsers = <String>{};

      for (final a in _assetAccounts) {
        final code = a['code']?.toString().trim() ?? '';
        final name = a['name']?.toString() ?? '';
        final id = a['id']?.toString() ?? '';
        if (addedIds.contains(id)) continue;
        if (excludedCodes.contains(code) || code.toUpperCase().contains('LOAN') || name.contains('ٹیسٹ') || name.contains('قرض') || name.contains('لون')) continue;

        if (canonicalCodes.contains(code) ||
            ((name.contains('خرچ کھاتہ') || name.contains('ایڈوانس') || name.toLowerCase().contains('advance')) && !name.contains('وصولی'))) {
          final uKey = name.contains('عثمان') ? 'usman' : (
                       name.contains('تنزیل') ? 'tanzeel' : (
                       name.contains('سلیم') ? 'saleem' : (
                       name.contains('حماد') ? 'hammad' : name)));
          if (!seenUsers.contains(uKey)) {
            seenUsers.add(uKey);
            list.add(a);
            addedIds.add(id);
          }
        }
      }

      // 4. Admin's own collection account (if exists)
      for (final a in _assetAccounts) {
        final name = a['name']?.toString() ?? '';
        final id = a['id']?.toString() ?? '';
        if (addedIds.contains(id)) continue;
        if (_isMyCollectionAccount(name)) {
          list.add(a);
          addedIds.add(id);
        }
      }

      return list.isNotEmpty ? list : _assetAccounts;
    }

    // Non-admin users: ONLY their own expense account and their own collection account!
    final list = <Map<String, dynamic>>[];
    for (final a in _assetAccounts) {
      final name = a['name']?.toString() ?? '';
      final id = int.tryParse(a['id']?.toString() ?? '');

      final isMyAdv = _isMyAdvanceAccount(name) || (id != null && id == _selectedAdvanceAccountId);
      final isMyCol = _isMyCollectionAccount(name) || (id != null && id == _selectedCollectionAccountId);

      if (isMyAdv || isMyCol) {
        list.add(a);
      }
    }
    return list.isNotEmpty ? list : _assetAccounts;
  }

  List<Map<String, dynamic>> _getIncomeAccounts() {
    if (_isAdminRole) {
      final list = <Map<String, dynamic>>[];
      final addedIds = <String>{};

      // 1. Central Cash 1001
      for (final a in _assetAccounts) {
        if (a['code']?.toString().trim() == '1001') {
          list.add(a);
          addedIds.add(a['id']?.toString() ?? '');
          break;
        }
      }

      // 2. Bank Accounts
      for (final a in _assetAccounts) {
        final code = a['code']?.toString().trim() ?? '';
        final name = (a['name']?.toString() ?? '').toLowerCase();
        final id = a['id']?.toString() ?? '';
        if (addedIds.contains(id)) continue;
        if (code.toUpperCase().contains('LOAN') || name.contains('قرض') || name.contains('لون')) continue;
        if (code == '1002' ||
            name.contains('bank') ||
            name.contains('بینک') ||
            name.contains('meezan') ||
            name.contains('hbl') ||
            name.contains('mcb') ||
            name.contains('ubl') ||
            name.contains('jazzcash') ||
            name.contains('easypaisa')) {
          list.add(a);
          addedIds.add(id);
        }
      }

      // 3. User Collection Accounts (نقد وصولی) ONLY - NEVER expense accounts or loans!
      for (final a in _assetAccounts) {
        final code = a['code']?.toString().trim() ?? '';
        final name = a['name']?.toString() ?? '';
        final id = a['id']?.toString() ?? '';
        if (addedIds.contains(id)) continue;
        if (code.toUpperCase().contains('LOAN') || name.contains('قرض') || name.contains('لون')) continue;
        if (name.contains('خرچ') || name.contains('ایڈوانس') || name.toLowerCase().contains('advance')) continue;

        if (name.contains('وصولی') || code.startsWith('11')) {
          list.add(a);
          addedIds.add(id);
        }
      }

      return list.isNotEmpty ? list : _assetAccounts;
    }

    // Non-admin users deposit into their collection account
    final list = <Map<String, dynamic>>[];
    for (final a in _assetAccounts) {
      final name = a['name']?.toString() ?? '';
      final code = a['code']?.toString() ?? '';
      if (code == '1001') continue; // Central cash not accessible to non-admin directly
      if (_isOtherUserAdvanceAccount(name)) continue;
      if (_isOtherUserCollectionAccount(name)) continue;
      if (name.toLowerCase().contains('advance') || name.contains('ایڈوانس') || name.contains('خرچ کھاتہ') || name.contains('قرض') || name.contains('لون')) continue;
      list.add(a);
    }
    if (_selectedCollectionAccountId != null && _selectedCollectionAccountId! > 0) {
      final colAcc = _assetAccounts.firstWhere(
        (a) => (int.tryParse(a['id']?.toString() ?? '') == _selectedCollectionAccountId),
        orElse: () => {},
      );
      if (colAcc.isNotEmpty && !list.any((a) => (int.tryParse(a['id']?.toString() ?? '') == _selectedCollectionAccountId))) {
        list.insert(0, colAcc);
      }
    }
    return list.isNotEmpty ? list : _assetAccounts;
  }

  int? _getDefaultPaymentAccountId({bool isExp = false}) {
    // Admin default account is strictly Central Cash (1001)!
    if (_isAdminRole) {
      final list = isExp ? _getExpenseAccounts() : _assetAccounts;
      for (final acc in list) {
        if (acc['code']?.toString() == '1001') {
          return int.tryParse(acc['id']?.toString() ?? '');
        }
      }
      if (list.isNotEmpty) {
        return int.tryParse(list.first['id']?.toString() ?? '');
      }
      return null;
    }

    if (isExp) {
      if (_selectedAdvanceAccountId != null && _selectedAdvanceAccountId! > 0) {
        return _selectedAdvanceAccountId;
      }
      final myAdv = _getExpenseAccounts();
      if (myAdv.isNotEmpty) {
        return int.tryParse(myAdv.first['id']?.toString() ?? '');
      }
    } else {
      if (_selectedCollectionAccountId != null && _selectedCollectionAccountId! > 0) {
        return _selectedCollectionAccountId;
      }
      final myCol = _getIncomeAccounts();
      for (final acc in myCol) {
        final n = (acc['name']?.toString() ?? '').toLowerCase();
        if (n.contains('وصولی') || n.contains('collection')) {
          return int.tryParse(acc['id']?.toString() ?? '');
        }
      }
      if (myCol.isNotEmpty) {
        return int.tryParse(myCol.first['id']?.toString() ?? '');
      }
    }
    final list = isExp ? _getExpenseAccounts() : _assetAccounts;
    if (list.isNotEmpty) {
      return int.tryParse(list.first['id']?.toString() ?? '');
    }
    return null;
  }

  Future<void> _loadAdvanceBalance() async {
    try {
      final summaries = await DBHelper().getAllUserAdvanceSummaries();
      double totalRec = 0.0;
      double totalDed = 0.0;
      double totalBal = 0.0;

      int? selectedAid = _selectedAdvanceAccountId;
      double activeBal = 0.0;
      Map<int, Map<String, dynamic>> finalSummaries = {};

      if (!_isAdminRole) {
        // Find ONLY current user's advance account
        for (final s in summaries.values) {
          final aName = s['name']?.toString() ?? '';
          final uLabel = s['user_label']?.toString() ?? '';
          final sUid = s['user_id'] as int?;
          final sUids = s['user_ids'] as List<dynamic>?;
          bool matches = false;
          if (currentUserId.value != null) {
            if (sUid == currentUserId.value || (sUids != null && sUids.contains(currentUserId.value))) {
              matches = true;
            }
          }
          if (!matches && (_isMyAdvanceAccount(aName) || _isMyAdvanceAccount(uLabel))) {
            matches = true;
          }
          if (matches) {
            final aid = s['account_id'] as int;
            finalSummaries[aid] = s;
          }
        }
        if (finalSummaries.isEmpty) {
          final urole = (currentUserRole.value ?? '').toLowerCase();
          if (urole == 'nazim') {
            for (final s in summaries.values) {
              final aName = s['name']?.toString().toLowerCase() ?? '';
              final uLabel = s['user_label']?.toString().toLowerCase() ?? '';
              if (aName.contains('ناظم') || aName.contains('nazim') || uLabel.contains('ناظم') || uLabel.contains('nazim')) {
                final aid = s['account_id'] as int;
                finalSummaries[aid] = s;
                break;
              }
            }
          }
        }

        if (finalSummaries.isNotEmpty) {
          if (selectedAid == null || !finalSummaries.containsKey(selectedAid)) {
            selectedAid = finalSummaries.keys.first;
          }
          activeBal = (finalSummaries[selectedAid]!['net_balance'] as num?)?.toDouble() ?? 0.0;

          // Auto-reconcile for this specific user
          final uLabel = finalSummaries[selectedAid]?['user_label']?.toString() ?? currentUserName.value ?? '';
          await DBHelper().autoReconcileAdvanceExpenses(selectedAid, uLabel);
          final updated = await DBHelper().getAllUserAdvanceSummaries();
          if (updated.containsKey(selectedAid)) {
            finalSummaries[selectedAid] = updated[selectedAid]!;
            activeBal = (updated[selectedAid]!['net_balance'] as num?)?.toDouble() ?? activeBal;
          }

          for (final s in finalSummaries.values) {
            totalRec += (s['total_received'] as num?)?.toDouble() ?? 0.0;
            totalDed += (s['total_deducted'] as num?)?.toDouble() ?? 0.0;
            totalBal += (s['net_balance'] as num?)?.toDouble() ?? 0.0;
          }
        } else {
          selectedAid = null;
          activeBal = 0.0;
          totalRec = 0.0;
          totalDed = 0.0;
          totalBal = 0.0;
        }
      } else {
        finalSummaries = summaries;
        for (final s in summaries.values) {
          totalRec += (s['total_received'] as num?)?.toDouble() ?? 0.0;
          totalDed += (s['total_deducted'] as num?)?.toDouble() ?? 0.0;
          totalBal += (s['net_balance'] as num?)?.toDouble() ?? 0.0;
        }
        if (selectedAid == null || !summaries.containsKey(selectedAid)) {
          if (summaries.isNotEmpty) {
            selectedAid = summaries.keys.first;
          }
        }
        if (selectedAid != null && summaries.containsKey(selectedAid)) {
          activeBal = (summaries[selectedAid]!['net_balance'] as num?)?.toDouble() ?? 0.0;
        }
      }

      if (mounted) {
        setState(() {
          _userAdvanceSummaries = finalSummaries;
          _selectedAdvanceAccountId = selectedAid;
          _userAdvanceBalance = activeBal;
          _totalOverallAdvanceReceived = totalRec;
          _totalOverallAdvanceDeducted = totalDed;
          _totalOverallAdvanceBalance = totalBal;
        });
      }
    } catch (e) {
      debugPrint('Error loading advance balance: $e');
    }
  }

  Future<void> _loadCollectionBalance() async {
    try {
      final summaries = await DBHelper().getAllUserCollectionSummaries();
      double totalRec = 0.0;
      double totalTr = 0.0;

      int? selectedAid = _selectedCollectionAccountId;
      double activeBal = 0.0;
      Map<int, Map<String, dynamic>> finalSummaries = {};

      if (!_isAdminRole) {
        // Find ONLY current user's collection account
        for (final s in summaries.values) {
          final aName = s['name']?.toString() ?? '';
          final uLabel = s['user_label']?.toString() ?? '';
          final sUid = s['user_id'] as int?;
          final sUids = s['user_ids'] as List<dynamic>?;
          bool matches = false;
          if (currentUserId.value != null) {
            if (sUid == currentUserId.value || (sUids != null && sUids.contains(currentUserId.value))) {
              matches = true;
            }
          }
          if (!matches && (_isMyCollectionAccount(aName) || _isMyCollectionAccount(uLabel))) {
            matches = true;
          }
          if (matches) {
            final aid = s['account_id'] as int;
            finalSummaries[aid] = s;
          }
        }
        if (finalSummaries.isEmpty) {
          final uname = currentUserName.value?.trim() ?? '';
          if (uname.isNotEmpty) {
            final newAid = await DBHelper().ensureUserCollectionAccount(uname);
            if (newAid > 0) {
              final updated = await DBHelper().getAllUserCollectionSummaries();
              if (updated.containsKey(newAid)) {
                finalSummaries[newAid] = updated[newAid]!;
              }
            }
          }
        }

        if (finalSummaries.isNotEmpty) {
          if (selectedAid == null || !finalSummaries.containsKey(selectedAid)) {
            selectedAid = finalSummaries.keys.first;
          }
          activeBal = (finalSummaries[selectedAid]!['net_in_hand'] as num?)?.toDouble() ?? 0.0;
          for (final s in finalSummaries.values) {
            totalRec += (s['total_collected'] as num?)?.toDouble() ?? 0.0;
            totalTr += (s['total_transferred'] as num?)?.toDouble() ?? 0.0;
          }
        } else {
          selectedAid = null;
          activeBal = 0.0;
          totalRec = 0.0;
          totalTr = 0.0;
        }
      } else {
        finalSummaries = summaries;
        for (final s in summaries.values) {
          totalRec += (s['total_collected'] as num?)?.toDouble() ?? 0.0;
          totalTr += (s['total_transferred'] as num?)?.toDouble() ?? 0.0;
        }
        if (selectedAid == null || !summaries.containsKey(selectedAid)) {
          if (summaries.isNotEmpty) {
            selectedAid = summaries.keys.first;
          }
        }
        if (selectedAid != null && summaries.containsKey(selectedAid)) {
          activeBal = (summaries[selectedAid]!['net_in_hand'] as num?)?.toDouble() ?? 0.0;
        }
      }

      if (mounted) {
        setState(() {
          _userCollectionSummaries = finalSummaries;
          _selectedCollectionAccountId = selectedAid;
          _userCollectionBalance = activeBal;
          _totalOverallCollectionReceived = totalRec;
          _totalOverallCollectionTransferred = totalTr;
        });
      }
    } catch (e) {
      debugPrint('Error loading collection balance: $e');
    }
  }

  Future<void> _loadTransferMetadata() async {
    try {
      final uname = currentUserName.value?.trim() ?? '';
      if (uname.isNotEmpty) {
        await DBHelper().ensureUserAdvanceAccount(uname);
        await DBHelper().ensureUserCollectionAccount(uname);
      }
      if (_isAdminRole) {
        await DBHelper().ensureUserAdvanceAccount('admin');
        await DBHelper().ensureUserCollectionAccount('admin');
      }
      final accounts = await DBHelper().getPaymentAssetAccounts(
        systemType: SystemTypeGlobal.currentSystemType.value,
      );
      final teachers = await DBHelper().getTeachers();
      final sysAdmins = await DBHelper().getSystemAdmins();
      final otherUsers = <String>{};
      for (final a in accounts) {
        final aName = a['name']?.toString() ?? '';
        if (aName.startsWith('ایڈوانس - ')) {
          final u = aName.replaceFirst('ایڈوانس - ', '').trim();
          if (u.isNotEmpty) otherUsers.add(u);
        } else if (aName.startsWith('نقد وصولی - ')) {
          final u = aName.replaceFirst('نقد وصولی - ', '').trim();
          if (u.isNotEmpty) otherUsers.add(u);
        }
      }
      try {
        final db = await DBHelper().database;
        final fUsers = await db.rawQuery(
          "SELECT DISTINCT user_name FROM finance WHERE user_name IS NOT NULL AND TRIM(user_name) != ''",
        );
        for (final r in fUsers) {
          final u = r['user_name']?.toString().trim() ?? '';
          if (u.isNotEmpty) otherUsers.add(u);
        }
      } catch (_) {}

      final cleanAccounts = accounts.where((a) {
        final name = (a['name']?.toString() ?? '').toLowerCase();
        return !name.contains('لون') && !name.contains('قرض') && !name.contains('loan');
      }).toList();

      if (mounted) {
        setState(() {
          _teachersList = teachers;
          _systemUsersList = sysAdmins;
          _otherUsersList = otherUsers.toList();
          if (!_isAdminRole) {
            _assetAccounts = cleanAccounts.where((a) {
              final name = a['name']?.toString() ?? '';
              return !_isOtherUserAdvanceAccount(name) && !_isOtherUserCollectionAccount(name);
            }).toList();
          } else {
            _assetAccounts = cleanAccounts;
          }
        });
        await _loadAdvanceBalance();
        await _loadCollectionBalance();
        await _loadTransfers();
      }
    } catch (e) {
      debugPrint('Error loading transfer metadata: $e');
    }
  }

  Future<void> _loadTransfers() async {
    try {
      final sys = SystemTypeGlobal.currentSystemType.value;
      final records = await DBHelper().getFundTransfers(
        systemType: sys,
        userId: currentUserId.value,
        userRole: currentUserRole.value,
        username: currentUserName.value,
      );
      if (mounted) {
        setState(() {
          _transferRecords = records;
        });
      }
    } catch (e) {
      debugPrint('Error loading transfers: $e');
    }
  }

  Future<void> _loadHeads() async {
    try {
      final heads = await DBHelper().getHeads();
      if (!mounted) return;
      setState(() {
        _dbHeads = heads;
      });
    } catch (e, st) {
      debugPrint("Error loading heads: $e\n$st");
    }
  }



  String _formatDate(DateTime date) {

    return '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

  }



  DateTime _parseFinanceDate(String value) {

    final trimmed = value.trim();

    final isoDate = DateTime.tryParse(trimmed);

    if (isoDate != null) return isoDate;

    final parts = trimmed.split('/');

    if (parts.length == 3) {

      final day = int.tryParse(parts[0]);

      final month = int.tryParse(parts[1]);

      final year = int.tryParse(parts[2]);

      if (day != null && month != null && year != null) {

        return DateTime(year, month, day);

      }

    }

    return DateTime.now();

  }



  Future<void> _pickEntryDate() async {

    final picked = await showDatePicker(

      context: context,

      initialDate: _parseFinanceDate(_dateController.text),

      firstDate: DateTime(2000),

      lastDate: DateTime(2100),

    );

    if (picked == null) return;

    setState(() {

      _dateController.text = _formatDate(picked);

    });

  }







  Future<void> _reconcilePaidStipends() async {
    try {
      final db = await DBHelper().database;
      final sys = SystemTypeGlobal.currentSystemType.value;
      final isMasjid = sys == 'Masjid';
      final table = isMasjid ? 'masjid_finance' : 'finance';

      final paidPayrolls = await db.rawQuery('''
        SELECT p.*, t.name as teacher_name 
        FROM acc_payroll p 
        LEFT JOIN teachers t ON p.teacher_id = t.id 
        WHERE p.status = 'Paid' AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      ''');

      for (var p in paidPayrolls) {
        final tName = p['teacher_name']?.toString() ?? 'Staff';
        final mYear = p['month_year']?.toString() ?? '';
        final net = double.tryParse(p['net_salary']?.toString() ?? '0') ?? 0.0;
        if (net <= 0) continue;
        final pSys = p['system_type']?.toString() ?? 'Madrasa';
        final payDate = p['payment_date']?.toString() ?? DateFormat('yyyy-MM-dd').format(DateTime.now());
        final uId = int.tryParse(p['created_by']?.toString() ?? '1') ?? 1;

        final descPattern = "%ماہ $mYear: $tName%";
        final chk = await db.rawQuery(
          "SELECT id FROM $table WHERE type = 'Expense' AND description LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1",
          [descPattern],
        );
        if (chk.isEmpty) {
          final uName = currentUserName.value ?? 'Admin';
          final row = {
            'user_id': uId,
            'user_name': uName,
            'type': 'Expense',
            'head_id': 0,
            'head_name': isUrduGlobal.value ? 'اساتذہ وظائف (Salaries)' : 'Staff Salaries',
            'amount': net,
            'date': payDate,
            'donor_name': tName,
            'spender_name': tName,
            'description': isUrduGlobal.value
                ? "وظیفہ/تنخواہ ادا کی گئی برائے ماہ $mYear: $tName"
                : "Staff Salary Paid for $mYear: $tName",
            'system_type': pSys,
            'is_deleted': 0,
            'is_synced': 0,
          };
          if (isMasjid) {
            await db.insert('masjid_finance', row);
          } else {
            await DBHelper().insertFinance(row);
          }
        }
      }
    } catch (_) {}
  }

  Future<void> _loadTransactions() async {
    try {
      await _reconcilePaidStipends();
    } catch (e) {
      debugPrint('Error reconciling stipends: $e');
    }

    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
    try {
      if (_isAdminRole) {
        final grouped = isMasjid
            ? await DBHelper().getMasjidFinanceGroupedByUser()
            : await DBHelper().getFinanceGroupedByUser();
        final allRecords = isMasjid
            ? await DBHelper().getMasjidFinance(userRole: 'admin')
            : await DBHelper().getFinance(userRole: 'admin');
        if (!mounted) return;
        setState(() {
          _userGroups = Map<int, Map<String, dynamic>>.from(grouped);
          _financeRecords = List<Map<String, dynamic>>.from(allRecords);
          if (grouped.isNotEmpty) {
            _expandedUsers = grouped.keys.toSet();
          }
        });
      } else {
        final records = isMasjid
            ? await DBHelper().getMasjidFinance(
                userId: currentUserId.value,
                userRole: currentUserRole.value,
              )
            : await DBHelper().getFinance(
                userId: currentUserId.value,
                userRole: currentUserRole.value,
              );
        if (!mounted) return;
        setState(() {
          _financeRecords = List<Map<String, dynamic>>.from(records);
        });
      }
    } catch (e) {
      debugPrint('Error loading transactions: $e');
      try {
        final fallbackRecords = isMasjid
            ? await DBHelper().getMasjidFinance(
                userId: currentUserId.value,
                userRole: currentUserRole.value,
              )
            : await DBHelper().getFinance(
                userId: currentUserId.value,
                userRole: currentUserRole.value,
              );
        if (mounted) {
          setState(() {
            _financeRecords = fallbackRecords;
          });
        }
      } catch (e2) {
        debugPrint('Fallback loading transactions error: $e2');
      }
    }

    try {
      await _loadAdvanceBalance();
    } catch (e) {
      debugPrint('Error loading advance balance: $e');
    }

    try {
      await _loadTransfers();
    } catch (e) {
      debugPrint('Error loading transfers: $e');
    }
    _fetchAdminOtpsCount();
  }



  String _buildDescription() {

    final headVal = selectedHead?.trim() ?? '';

    final donorVal = _donorController.text.trim();

    final addrVal = _addressController.text.trim();

    final descVal = _descController.text.trim();



    if (headVal.isEmpty && donorVal.isEmpty && addrVal.isEmpty) {

      return descVal;

    }



    final buffer = StringBuffer();

    if (headVal.isNotEmpty) {

      buffer.write('\u0645\u062f: $headVal');

    }

    if (isIncome) {

      if (donorVal.isNotEmpty) {

        if (buffer.isNotEmpty) buffer.write(' | ');

        buffer.write('\u0646\u0627\u0645: $donorVal');

      }

      if (addrVal.isNotEmpty) {

        if (buffer.isNotEmpty) buffer.write(' | ');

        buffer.write('\u067e\u062a\u06c1: $addrVal');

      }

    }

    if (descVal.isNotEmpty) {

      if (buffer.isNotEmpty) buffer.write(' | ');

      buffer.write('\u062a\u0641\u0635\u06cc\u0644: $descVal');

    }

    return buffer.toString();

  }



  String _extractPart(String description, List<String> labels) {

    for (final raw in description.split('|')) {

      final part = raw.trim();

      for (final label in labels) {

        if (part.startsWith(label)) {

          return part.substring(label.length).trim();

        }

      }

    }

    return '';

  }



  void _populateForEdit(Map<String, dynamic> row) {

    final typeValue = (row['type'] ?? '').toString().toLowerCase();

    final description = (row['description'] ?? '').toString();

    final donorName = (row['donor_name'] ?? '').toString();
    final spenderName = (row['spender_name'] ?? '').toString();
    final donorAddress = (row['donor_address'] ?? '').toString();

    setState(() {
      _editingId = row['id'] as int?;
      _editingUserId = int.tryParse(row['user_id']?.toString() ?? '');
      _editingUserName = row['user_name']?.toString();
      _selectedEntryUserId = _editingUserId;
      _selectedEntryUserName = _editingUserName;
      _editingAccountId = int.tryParse(row['account_id']?.toString() ?? '');
      isIncome = typeValue == 'income';
      _selectedTabIndex = isIncome ? 0 : 1;
      _amountController.text = (row['amount'] ?? '').toString();
      _dateController.text = (row['date'] ?? '').toString();

      _selectedPaymentAccountId = int.tryParse(row['account_id']?.toString() ?? '');
      _selectedPaymentMethod = (row['payment_method']?.toString() ?? 'Cash');
      if (_selectedPaymentMethod.isEmpty) _selectedPaymentMethod = 'Cash';
      _manualReceiptController.text = (row['manual_receipt_no'] ?? '').toString();
      _spenderController.text = spenderName.isNotEmpty ? spenderName : donorName;
      _billImageFile = null;
      _billImageString = (row['bill_image'] ?? '').toString().trim();
      if (_billImageString!.isEmpty) _billImageString = null;

      String head = _extractPart(description, ['\u0645\u062f:', 'مد:']);
      if (head.isEmpty && row['head_name'] != null && row['head_name'].toString().isNotEmpty) {
        head = row['head_name'].toString();
      }
      selectedHead = head.isNotEmpty ? head : null;

      String donor = _extractPart(description, ['\u0646\u0627\u0645:', 'نام:']);
      if (donor.isEmpty && donorName.isNotEmpty) {
        donor = donorName;
      }
      _donorController.text = donor;

      String addr = _extractPart(description, ['\u067e\u062a\u06c1:', 'پت :']);
      if (addr.isEmpty && donorAddress.isNotEmpty) {
        addr = donorAddress;
      }
      _addressController.text = addr;

      String desc = _extractPart(description, ['\u062a\u0641\u0635\u06cc\u0644:', 'ت صیل:']);
      if (desc.isEmpty) {
        desc = description;
      }
      _descController.text = desc;
      _autocompleteKey = UniqueKey();
    });



    if (_scrollController.hasClients) {

      _scrollController.animateTo(

        0,

        duration: const Duration(milliseconds: 300),

        curve: Curves.easeOut,

      );

    }

  }



  Future<void> _deleteTransaction(Map<String, dynamic> row) async {
    if (!_isAdminRole) {
      final hasPerm = await _ensureEditPermission(row);
      if (!hasPerm) return;
    }

    final bool? confirmed = await showDialog<bool>(

      context: context,

      builder: (context) {

        return AlertDialog(

          title: Text(isUrduGlobal.value

              ? '\u062a\u0627\u0626\u06cc\u062f'

              : 'Confirm'),

          content: Text(

            isUrduGlobal.value

                ? '\u06a9\u06cc\u0627 \u0622\u067e \u06cc\u06c1 \u0641\u0646\u0627\u0646\u0633 \u0627\u0646\u0679\u0631\u06cc \u0688\u06cc\u0644\u06cc\u0679 \u06a9\u0631\u0646\u0627 \u0686\u0627\u06c1\u062a\u06d2 \u06c1\u06cc\u06ba\u061f'

                : 'Do you want to delete this finance entry?',

          ),

          actions: [

            TextButton(

              onPressed: () => Navigator.pop(context, false),

              child:

                  Text(isUrduGlobal.value ? '\u0646\u06c1\u06cc\u06ba' : 'No'),

            ),

            ElevatedButton(

              onPressed: () => Navigator.pop(context, true),

              child: Text(isUrduGlobal.value ? '\u06c1\u0627\u06ba' : 'Yes'),

            ),

          ],

        );

      },

    );



    if (confirmed != true) return;

    final recordId = int.tryParse(row['id']?.toString() ?? '0') ?? 0;
    if (recordId <= 0) return;

    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid' ||
        row['system_type'] == 'Masjid';

    try {
      if (isMasjid) {
        await DBHelper().deleteMasjidFinance(recordId);
      } else {
        await DBHelper().deleteFinance(recordId);
      }

      if (_editingId == recordId) {
        _resetForm();
      }

      if (mounted) {
        setState(() {
          _financeRecords = _financeRecords.where((r) => (int.tryParse(r['id']?.toString() ?? '0') ?? 0) != recordId).toList();
          for (var group in _userGroups.values) {
            if (group['income_records'] is List) {
              group['income_records'] = (group['income_records'] as List)
                  .where((r) => (int.tryParse(r['id']?.toString() ?? '0') ?? 0) != recordId)
                  .toList();
            }
            if (group['expense_records'] is List) {
              group['expense_records'] = (group['expense_records'] as List)
                  .where((r) => (int.tryParse(r['id']?.toString() ?? '0') ?? 0) != recordId)
                  .toList();
            }
            if (group['income'] is List) {
              group['income'] = (group['income'] as List)
                  .where((r) => (int.tryParse(r['id']?.toString() ?? '0') ?? 0) != recordId)
                  .toList();
            }
            if (group['expense'] is List) {
              group['expense'] = (group['expense'] as List)
                  .where((r) => (int.tryParse(r['id']?.toString() ?? '0') ?? 0) != recordId)
                  .toList();
            }
          }
        });
      }

      await _loadTransactions();

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isUrduGlobal.value
                ? 'انٹری ڈیلیٹ کر دی گئی ہے۔'
                : 'Finance entry deleted.',
          ),
          duration: const Duration(seconds: 2),
        ),
      );

      // Offline-first: Run two-way sync in background
      SyncService.runTwoWaySync().then((_) {
        if (mounted) {
          _loadTransactions();
        }
      }).catchError((e) {
        debugPrint('Delete transaction background sync notice: $e');
      });
    } catch (e) {
      debugPrint('Delete transaction error: $e');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              isUrduGlobal.value
                  ? 'انٹری ڈیلیٹ کرنے میں خرابی پیش آئی: $e'
                  : 'Error deleting entry: $e',
            ),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }



  Future<void> _shareReceipt() async {

    try {

      final Uint8List? image = await _screenshotController.capture();

      if (image == null) return;



      final directory = await getTemporaryDirectory();

      final imagePath = await File(

        '${directory.path}/receipt_${DateTime.now().millisecondsSinceEpoch}.png',

      ).create();

      await imagePath.writeAsBytes(image);



      await Share.shareXFiles(

        [XFile(imagePath.path)],

        text: isUrduGlobal.value

            ? '\u0645\u062f\u0631\u0633\u06c1 \u0627\u0634\u0631\u0641 \u0627\u0644\u0645\u062f\u0627\u0631\u0633 - \u0631\u0633\u06cc\u062f'

            : 'Madrasa Receipt',

      );

    } catch (e, stackTrace) {
      debugPrint('Sharing Error: $e\nStack: $stackTrace');
    }

  }



  Future<void> _openReceiptForTransaction(
      Map<String, dynamic> row, double amount) async {
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid' ||
        row['system_type'] == 'Masjid';
    final table = isMasjid ? 'masjid_finance' : 'finance';

    String? receipt = row['receipt_no']?.toString() ??
        row['computer_receipt_no']?.toString();

    if (receipt != null && receipt.trim().isNotEmpty && receipt.trim() != '0') {
      receipt = receipt.trim();
      if (isMasjid && !receipt.startsWith('M-')) {
        receipt = 'M-$receipt';
      }
      _showReceiptDialog(amount, receiptNo: receipt, transaction: row);
      return;
    }

    // Auto-allocate next receipt number from existing non-deleted records
    try {
      final db = await DBHelper().database;
      final uId = int.tryParse(row['user_id']?.toString() ?? '') ??
          (currentUserId.value ?? 1);
      final int minNo;
      final int maxLimit;
      if (uId == 1) {
        minNo = 300000;
        maxLimit = 400000;
      } else if (uId == 3) {
        minNo = 700000;
        maxLimit = 800000;
      } else {
        minNo = uId * 100000;
        maxLimit = (uId + 1) * 100000;
      }

      final result = await db.rawQuery(
          'SELECT MAX(computer_receipt_no) as max_no FROM $table WHERE computer_receipt_no >= ? AND computer_receipt_no < ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          [minNo, maxLimit]);

      int maxNo = minNo;
      if (result.isNotEmpty && result.first['max_no'] != null) {
        maxNo = result.first['max_no'] as int;
      }
      final nextNo = maxNo + 1;
      final formattedNo = isMasjid ? 'M-$nextNo' : '$nextNo';

      final rowId = row['id'];
      if (rowId != null) {
        await db.update(
          table,
          {
            'computer_receipt_no': nextNo,
            'receipt_no': formattedNo,
            'is_synced': 0,
          },
          where: 'id = ?',
          whereArgs: [rowId],
        );
      }

      final updatedRow = Map<String, dynamic>.from(row);
      updatedRow['computer_receipt_no'] = nextNo;
      updatedRow['receipt_no'] = formattedNo;

      if (mounted) {
        _showReceiptDialog(amount,
            receiptNo: formattedNo, transaction: updatedRow);
        _loadTransactions();
      }
    } catch (e) {
      debugPrint("Error auto-allocating receipt: $e");
      _showReceiptDialog(amount, transaction: row);
    }
  }

  bool _isSalaryHead(String text) {
    final lower = text.trim().toLowerCase();
    return lower.contains('وظیفہ') ||
        lower.contains('وظائف') ||
        lower.contains('تنخواہ') ||
        lower.contains('تنخواہیں') ||
        lower.contains('سیلری') ||
        lower.contains('salary') ||
        lower.contains('salaries') ||
        lower.contains('stipend') ||
        lower.contains('stipends');
  }

  void _showSalaryRestrictionNotice({TextEditingController? controller}) {
    if (controller != null) {
      controller.clear();
    }
    setState(() {
      selectedHead = null;
      _autocompleteKey = UniqueKey();
    });
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.warning_amber_rounded, color: Colors.amber, size: 28),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                isUrduGlobal.value ? 'اہم تنبیہ: وظائف و تنخواہ' : 'Staff Stipends Notice',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ),
          ],
        ),
        content: Text(
          isUrduGlobal.value
              ? 'وظیفہ، تنخواہ وغیرہ کی ادائیگی کا اندراج وظائف پیج پر ہی ہو سکتا ہے۔ لہذا آپ اس پیج پر انٹری فرمائیں۔'
              : 'Stipends and salary payments can only be recorded from the Staff Stipends section.\n\nPlease record them on the Staff Stipends page.',
          style: const TextStyle(fontSize: 14, height: 1.4),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(isUrduGlobal.value ? 'بند کریں' : 'Close'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF284D3D),
              foregroundColor: Colors.white,
            ),
            icon: const Icon(Icons.payments),
            label: Text(isUrduGlobal.value ? 'وظائف عملہ پیج پر جائیں' : 'Go to Staff Stipends'),
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.push(
                context,
                MaterialPageRoute(builder: (c) => const AccPayrollPage()),
              );
            },
          ),
        ],
      ),
    );
  }

  void _showReceiptDialog(double amount,
      {String? receiptNo, Map<String, dynamic>? transaction}) {
    final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid' ||
        transaction?['system_type'] == 'Masjid';
    final rawReceiptNo = receiptNo ??
        transaction?['receipt_no']?.toString() ??
        transaction?['computer_receipt_no']?.toString();
    String displayReceiptNo;
    if (rawReceiptNo != null &&
        rawReceiptNo.trim().isNotEmpty &&
        rawReceiptNo.trim() != '0') {
      displayReceiptNo = rawReceiptNo.trim();
      if (isMasjid && !displayReceiptNo.startsWith('M-')) {
        displayReceiptNo = 'M-$displayReceiptNo';
      }
    } else {
      final fallbackId = transaction?['id']?.toString() ?? '';
      displayReceiptNo = isMasjid
          ? (fallbackId.isNotEmpty ? 'M-$fallbackId' : 'M-')
          : (fallbackId.isNotEmpty ? '#$fallbackId' : '');
    }



    final String donorName = transaction != null

        ? (transaction['donor_name']?.toString() ?? '')

        : _donorController.text;

    final String dateStr = transaction != null

        ? (transaction['date']?.toString() ?? '')

        : _dateController.text;

    final String address = transaction != null

        ? (transaction['donor_address']?.toString() ?? '')

        : _addressController.text;

    final String head = transaction != null

        ? (transaction['head_name']?.toString() ?? '')

        : (selectedHead ?? '-');



    showDialog(

      context: context,

      barrierDismissible: false,

      builder: (BuildContext context) {

        return StatefulBuilder(

          builder: (context, setDialogState) {

            return Dialog(

              shape: RoundedRectangleBorder(

                  borderRadius: BorderRadius.circular(15)),

              child: SingleChildScrollView(

                child: Column(

                  mainAxisSize: MainAxisSize.min,

                  children: [

                    Screenshot(

                      controller: _screenshotController,

                      child: Container(

                        width: double.infinity,

                        color: Colors.white,

                        child: LayoutBuilder(

                          builder: (context, constraints) {

                            final w = constraints.maxWidth;

                            final receiptTextStyle = TextStyle(

                              fontSize: w * 0.034,

                              fontWeight: FontWeight.w700,

                              color: Colors.black,

                              height: 1.25,

                              decoration: TextDecoration.underline,

                              decorationStyle: TextDecorationStyle.dotted,

                              decorationColor: Colors.black45,

                            );



                            final isLongName = donorName.length > 20;

                            final nameHeight = isLongName ? 1.4 : 1.25;

                            final addressTop = isLongName ? w * 0.388 : w * 0.375;



                            return AspectRatio(

                              aspectRatio: 1.6,

                              child: Stack(

                                children: [

                                  Positioned.fill(

                                    child: Image.asset('assets/receipt_bg.webp',

                                        fit: BoxFit.fill),

                                  ),

                                  Positioned(

                                    top: w * 0.203,

                                    left: w * 0.12,

                                    child: Text(displayReceiptNo,

                                        style: receiptTextStyle.copyWith(

                                            fontSize: w * 0.036, height: 1.0, decoration: TextDecoration.none)),

                                  ),

                                  if (isIncome)

                                    Positioned(

                                      top: w * 0.288,

                                      right: w * 0.06,

                                      left: w * 0.42,

                                      child: Text(

                                        '\u0645\u062d\u062a\u0631\u0645 \u062c\u0646\u0627\u0628: $donorName',

                                        textDirection: ui.TextDirection.rtl,

                                        textAlign: TextAlign.right,

                                        style: receiptTextStyle.copyWith(height: nameHeight),

                                      ),

                                    ),

                                  Positioned(

                                    top: w * 0.297,

                                    left: w * 0.08,

                                    child: Text(

                                      '\u062a\u0627\u0631\u06cc\u062e: $dateStr ${HijriHelper.toUrduHijri(dateStr).isNotEmpty ? '(${HijriHelper.toUrduHijri(dateStr)})' : ''}',

                                      textDirection: ui.TextDirection.rtl,

                                      style: receiptTextStyle.copyWith(height: 1.0, fontSize: w * 0.027, decoration: TextDecoration.none),

                                    ),

                                  ),

                                  if (isIncome)

                                    Positioned(

                                      top: addressTop,

                                      right: w * 0.06,

                                      left: w * 0.35,

                                      child: Text(

                                        '\u067e\u062a\u06c1: ${address == '---' ? '' : address}',

                                        textDirection: ui.TextDirection.rtl,

                                        textAlign: TextAlign.right,

                                        maxLines: 1,

                                        overflow: TextOverflow.ellipsis,

                                        style: receiptTextStyle,

                                      ),

                                    ),

                                  Positioned(

                                    top: w * 0.458,

                                    right: w * 0.06,

                                    left: w * 0.38,

                                    child: Text(

                                      '\u0645\u062f: ${head == '---' ? '' : head}',

                                      textDirection: ui.TextDirection.rtl,

                                      textAlign: TextAlign.right,

                                      style: receiptTextStyle,

                                    ),

                                  ),

                                  Positioned(

                                    top: w * 0.458,

                                    left: w * 0.145,

                                    child: Column(

                                      crossAxisAlignment: CrossAxisAlignment.start,

                                      mainAxisSize: MainAxisSize.min,

                                      children: [

                                        Text(

                                          'Rs: ${NumberFormat('#,##0').format(double.tryParse(amount.toString()) ?? 0)}/-',

                                          textDirection: ui.TextDirection.ltr,

                                          style: receiptTextStyle.copyWith(height: 1.0, decoration: TextDecoration.none),

                                        ),

                                        SizedBox(height: w * 0.004),

                                        Text(

                                          '(${UrduNumberWords.convert(amount)})',

                                          textDirection: ui.TextDirection.rtl,

                                          style: receiptTextStyle.copyWith(

                                            fontSize: w * 0.024,

                                            fontWeight: FontWeight.w600,

                                            color: Colors.black87,

                                            height: 1.1,

                                            decoration: TextDecoration.none,

                                          ),

                                        ),

                                      ],

                                    ),

                                  ),

                                  //    -   label   

                                  Positioned(

                                    bottom: w * 0.038,

                                    right: w * 0.06,

                                    left: w * 0.30,

                                    child: Row(

                                      textDirection: ui.TextDirection.rtl,

                                      crossAxisAlignment:

                                          CrossAxisAlignment.center,

                                      children: [

                                        // label   

                                        Text(

                                          '\u062f\u0633\u062a\u062e\u0637 \u0648\u0635\u0648\u0644 \u06a9\u0646\u0646\u062f\u06c1:',

                                          textDirection: ui.TextDirection.rtl,

                                          style: receiptTextStyle.copyWith(

                                            fontSize: w * 0.027,

                                            color: Colors.black87,

                                          ),

                                        ),

                                        SizedBox(width: w * 0.015),

                                        //  label  

                                        SizedBox(

                                          width: w * 0.28,

                                          height: w * 0.10,

                                          child: _signatureImage != null

                                              ? Image.file(

                                                  _signatureImage!,

                                                  fit: BoxFit.contain,

                                                )

                                              : const SizedBox.shrink(),

                                        ),

                                      ],

                                    ),

                                  ),

                                ],

                              ),

                            );

                          },

                        ),

                      ),

                    ),

                    Padding(

                      padding: const EdgeInsets.all(12),

                      child: Column(

                        children: [

                          SizedBox(

                            width: double.infinity,

                            child: OutlinedButton.icon(

                              style: OutlinedButton.styleFrom(

                                  foregroundColor: primaryGreen),

                              onPressed: () async {

                                final XFile? image = await _picker.pickImage(

                                    source: ImageSource.gallery);

                                if (image == null) return;

                                setDialogState(() {

                                  _signatureImage = File(image.path);

                                });

                              },

                              icon: const Icon(Icons.upload_file, size: 18),

                              label: Text(

                                isUrduGlobal.value

                                    ? '\u0648\u0635\u0648\u0644 \u06a9\u0646\u0646\u062f\u06c1 \u06a9\u06d2 \u062f\u0633\u062a\u062e\u0637 \u06a9\u06cc \u062a\u0635\u0648\u06cc\u0631 \u0644\u06af\u0627\u0626\u06cc\u06ba'

                                    : 'Upload Signature Image',

                              ),

                            ),

                          ),

                          const SizedBox(height: 10),

                          Row(

                            children: [

                              Expanded(

                                child: ElevatedButton.icon(

                                  style: ElevatedButton.styleFrom(

                                      backgroundColor: Colors.green.shade800),

                                  onPressed: _shareReceipt,

                                  icon: const Icon(Icons.share,

                                      color: Colors.white, size: 18),

                                  label: Text(

                                    isUrduGlobal.value

                                        ? '\u0634\u06cc\u0626\u0631 \u06a9\u0631\u06cc\u06ba'

                                        : 'Share',

                                    style: const TextStyle(color: Colors.white),

                                  ),

                                ),

                              ),

                              const SizedBox(width: 8),

                              Expanded(

                                child: ElevatedButton(

                                  style: ElevatedButton.styleFrom(

                                      backgroundColor: Colors.grey.shade700),

                                  onPressed: () {

                                    Navigator.pop(context);

                                    _resetForm();

                                  },

                                  child: Text(

                                    isUrduGlobal.value

                                        ? '\u0628\u0646\u062f \u06a9\u0631\u06cc\u06ba'

                                        : 'Close',

                                    style: const TextStyle(color: Colors.white),

                                  ),

                                ),

                              ),

                            ],

                          ),

                        ],

                      ),

                    ),

                  ],

                ),

              ),

            );

          },

        );

      },

    );

  }



  void _resetForm() {
    setState(() {
      _editingId = null;
      _editingUserId = null;
      _editingUserName = null;
      _editingAccountId = null;
      _selectedEntryUserId = null;
      _selectedEntryUserName = null;
      _billImageFile = null;
      _billImageString = null;
      _amountController.clear();
      _donorController.clear();
      _spenderController.clear();
      _addressController.clear();
      _manualReceiptController.clear();
      _descController.clear();
      _signatureImage = null;
      selectedHead = null;
      isIncome = true;
      _selectedTabIndex = 0;
      _selectedPaymentAccountId = null;
      _selectedPaymentMethod = 'Cash';
      _autocompleteKey = UniqueKey();
      _dateController.text = _formatDate(DateTime.now());
    });
  }



  Future<void> _saveTransaction() async {
    if (_isSaving) return;

    if (selectedHead == null || _amountController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isUrduGlobal.value
                ? 'مہربانی کر کے لازمی خانے پر کریں۔'
                : 'Please fill the required fields.',
          ),
        ),
      );
      return;
    }

    final amount = double.tryParse(_amountController.text.trim()) ?? 0.0;
    if (amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isUrduGlobal.value
                ? 'براہ کرم درست رقم درج کریں۔'
                : 'Please enter a valid amount.',
          ),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    if (!isIncome) {
      final head = selectedHead?.trim().toLowerCase() ?? '';
      final desc = _descController.text.trim().toLowerCase();
      final donor = _donorController.text.trim().toLowerCase();
      final fullText = "$head $desc $donor";

      final blockedKeywords = [
        'سیلری',
        'وظیفہ',
        'وظائف',
        'تنخواہ',
        'تنخواہیں',
        'salary',
        'salaries',
        'stipend',
        'stipends',
      ];

      bool isBlocked = blockedKeywords.any((kw) => fullText.contains(kw));
      if (isBlocked) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: Row(
              children: [
                const Icon(Icons.block, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isUrduGlobal.value ? 'پابندی: وظائف اندراج' : 'Stipends Entry Notice',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            content: Text(
              isUrduGlobal.value
                  ? 'وظائف اور تنخواہوں کا اندراج براہِ راست فنانس میں کرنے کی اجازت نہیں ہے۔\n\nبراہِ کرم تمام اساتذہ و عملہ کے وظائف کا اندراج "وظائف عملہ" سیکشن سے کریں۔'
                  : 'Manual salary and stipend entries in general finance are restricted to prevent duplication.\n\nPlease record staff stipends in the Staff Stipends section.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(isUrduGlobal.value ? 'بند کریں' : 'Close'),
              ),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF284D3D),
                  foregroundColor: Colors.white,
                ),
                icon: const Icon(Icons.payments),
                label: Text(isUrduGlobal.value ? 'وظائف عملہ' : 'Staff Stipends'),
                onPressed: () {
                  Navigator.pop(ctx);
                  Navigator.push(
                    context,
                    MaterialPageRoute(builder: (c) => const AccPayrollPage()),
                  );
                },
              ),
            ],
          ),
        );
        return;
      }
    }

    setState(() => _isSaving = true);

    try {
      final editingId = _editingId;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final table = isMasjid ? 'masjid_finance' : 'finance';

      int? compReceiptNo;
      String? formattedReceiptNo;
      if (editingId == null && isIncome) {
        try {
          final db = await DBHelper().database;
          int userId = currentUserId.value ?? 1;
          final int minNo;
          final int maxLimit;
          if (userId == 1) {
            minNo = 300000;
            maxLimit = 400000;
          } else if (userId == 3) {
            minNo = 700000;
            maxLimit = 800000;
          } else {
            minNo = userId * 100000;
            maxLimit = (userId + 1) * 100000;
          }

          // Self-healing: Ensure deleted records never hold receipt numbers
          await db.rawUpdate(
              'UPDATE $table SET computer_receipt_no = NULL WHERE (is_deleted = 1) AND computer_receipt_no IS NOT NULL');
          if (userId == 1) {
            final countRes = await db.rawQuery(
                'SELECT COUNT(*) FROM $table WHERE computer_receipt_no > 300040 AND computer_receipt_no < 300057 AND (is_deleted = 0 OR is_deleted IS NULL)');
            final countBetween = (countRes.isNotEmpty && countRes.first.values.isNotEmpty)
                ? (int.tryParse(countRes.first.values.first?.toString() ?? '0') ?? 0)
                : 0;
            if (countBetween == 0) {
              await db.rawUpdate(
                  "UPDATE $table SET computer_receipt_no = 300041, receipt_no = '300041' WHERE (user_id = 1 OR user_id IS NULL) AND computer_receipt_no = 300057 AND (is_deleted = 0 OR is_deleted IS NULL)");
            }
          }

          final result = await db.rawQuery(
              'SELECT MAX(computer_receipt_no) as max_no FROM $table WHERE computer_receipt_no >= ? AND computer_receipt_no < ? AND (is_deleted = 0 OR is_deleted IS NULL)',
              [minNo, maxLimit]);
          int maxNo = minNo;
          if (result.isNotEmpty && result.first['max_no'] != null) {
            maxNo = result.first['max_no'] as int;
          }
          compReceiptNo = maxNo + 1;
        } catch (e) {
          debugPrint("Error fetching max receipt no: $e");
          int userId = currentUserId.value ?? 1;
          int baseMin = (userId == 1) ? 300000 : ((userId == 3) ? 700000 : userId * 100000);
          compReceiptNo = baseMin + 1;
        }
        formattedReceiptNo = isMasjid ? 'M-$compReceiptNo' : '$compReceiptNo';
      }

      int? matchedHeadId;
      if (selectedHead != null && selectedHead!.trim().isNotEmpty) {
        final sHeadTrim = selectedHead!.trim().toLowerCase();
        for (var h in _dbHeads) {
          if ((h['name']?.toString().trim().toLowerCase() ?? '') == sHeadTrim) {
            matchedHeadId = int.tryParse(h['id']?.toString() ?? '');
            break;
          }
        }
      }

      int? effectiveUserId = currentUserId.value ?? 1;
      String effectiveUserName = currentUserName.value ?? (currentUserRole.value == 'admin' ? 'Admin' : currentUserRole.value ?? 'User');
      if (_isAdminRole && _selectedEntryUserId != null) {
        effectiveUserId = _selectedEntryUserId;
        effectiveUserName = _selectedEntryUserName ?? _resolveUserName(_selectedEntryUserId!);
      } else if (editingId != null) {
        if (_editingUserId != null) {
          effectiveUserId = _editingUserId;
        }
        if (_editingUserName != null && _editingUserName!.isNotEmpty) {
          effectiveUserName = _editingUserName!;
        }
      }

      int? effectiveAccountId = _selectedPaymentAccountId;
      if (editingId != null && effectiveAccountId == null && _editingAccountId != null) {
        effectiveAccountId = _editingAccountId;
      }
      effectiveAccountId ??= _getDefaultPaymentAccountId(isExp: !isIncome);
      if (!isIncome && !_isAdminRole) {
        final allowedList = _getExpenseAccounts();
        if (effectiveAccountId != null && !allowedList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == effectiveAccountId))) {
          effectiveAccountId = _getDefaultPaymentAccountId(isExp: true);
        }
      } else if (isIncome && !_isAdminRole) {
        final allowedList = _getIncomeAccounts();
        if (effectiveAccountId != null && !allowedList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == effectiveAccountId))) {
          effectiveAccountId = _getDefaultPaymentAccountId(isExp: false);
        }
      }

      String paymentMethod = _selectedPaymentMethod;
      if (!isIncome) {
        if (effectiveAccountId != null) {
          final accObj = _assetAccounts.firstWhere(
            (a) => (int.tryParse(a['id']?.toString() ?? '') == effectiveAccountId),
            orElse: () => {},
          );
          final aName = accObj['name']?.toString().toLowerCase() ?? '';
          if (aName.contains('advance') || aName.contains('ایڈوانس') || _isMyAdvanceAccount(aName)) {
            paymentMethod = 'Advance';
          } else if (paymentMethod == 'Advance') {
            paymentMethod = 'Cash';
          }
        } else if (!_isAdminRole) {
          paymentMethod = 'Advance';
        }
      }

      final row = {
        'date': _dateController.text,
        'type': isIncome ? 'Income' : 'Expense',
        'amount': amount,
        'description': _buildDescription(),
        'head_id': matchedHeadId,
        'head_name': selectedHead ?? '',
        'manual_receipt_no': isIncome ? _manualReceiptController.text.trim() : '',
        'donor_name': isIncome ? _donorController.text.trim() : _spenderController.text.trim(),
        'spender_name': isIncome ? '' : _spenderController.text.trim(),
        'donor_address': isIncome ? _addressController.text.trim() : '',
        'account_id': effectiveAccountId,
        'payment_method': paymentMethod,
        'user_id': effectiveUserId,
        'user_name': effectiveUserName,
        'system_type': isMasjid ? 'Masjid' : 'Madrasa',
        'bill_image': isIncome ? '' : (_billImageFile?.path ?? _billImageString ?? ''),
      };

      if (compReceiptNo != null) {
        row['computer_receipt_no'] = compReceiptNo;
        row['receipt_no'] = formattedReceiptNo ?? (isMasjid ? 'M-$compReceiptNo' : '$compReceiptNo');
      }

      int? savedLocalId;
      if (editingId != null) {
        if (isMasjid) {
          await DBHelper().updateMasjidFinance(editingId, row);
        } else {
          await DBHelper().updateFinance(editingId, row);
        }
      } else {
        if (isMasjid) {
          savedLocalId = await DBHelper().insertMasjidFinance(row);
        } else {
          savedLocalId = await DBHelper().insertFinance(row);
        }
      }

      final currentLocalId = editingId ?? savedLocalId;
      if (!isIncome && currentLocalId != null && _billImageFile != null) {
        FinanceOtpService.uploadBillFile(
          financeId: currentLocalId,
          file: _billImageFile!,
        ).then((uploadedPath) {
          if (uploadedPath != null && uploadedPath.isNotEmpty) {
            if (isMasjid) {
              DBHelper().updateMasjidFinance(currentLocalId, {'bill_image': uploadedPath});
            } else {
              DBHelper().updateFinance(currentLocalId, {'bill_image': uploadedPath});
            }
          }
        }).catchError((_) {});
      }

      // Immediately reload local database records without waiting for network sync
      try {
        await _loadTransactions();
        await _loadHeads();
        await _loadAdvanceBalance();
        await _loadCollectionBalance();
      } catch (e) {
        debugPrint('Error loading transactions after save: $e');
      }
      if (!mounted) return;

      // Offline-first: Provide immediate visual feedback!
      if (editingId == null && isIncome) {
        String? finalReceiptNo;
        try {
          finalReceiptNo = await _findSavedReceiptNo(savedLocalId, row);
        } catch (_) {}
        finalReceiptNo ??= formattedReceiptNo ??
            (isMasjid ? 'M-$compReceiptNo' : compReceiptNo?.toString());
        _showReceiptDialog(amount, receiptNo: finalReceiptNo);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              editingId != null
                  ? (isUrduGlobal.value
                      ? 'انٹری کامیابی سے اپ ڈیٹ ہو گئی۔'
                      : 'Entry updated successfully.')
                  : (isUrduGlobal.value
                      ? 'انٹری کامیابی سے محفوظ ہو گئی۔'
                      : 'Entry saved successfully.'),
            ),
            backgroundColor: Colors.green,
            duration: const Duration(seconds: 2),
          ),
        );
        _resetForm();
      }

      // Offline-first: Trigger background sync without blocking user interaction
      final syncTable = isMasjid ? 'masjid_finance' : 'finance';
      SyncService.triggerBackgroundTableSync(syncTable);

    } catch (e, stack) {
      debugPrint('Finance save failed: $e\n$stack');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isUrduGlobal.value
                ? 'انٹری محفوظ نہیں ہو سکی: $e'
                : 'Could not save the entry: $e',
          ),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _isSaving = false);
      }
    }
  }

  Future<String?> _findSavedReceiptNo(
      int? localId, Map<String, dynamic> row) async {
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid' || row['system_type'] == 'Masjid';
      final table = isMasjid ? 'masjid_finance' : 'finance';

      final byLocalId = localId == null
          ? <Map<String, dynamic>>[]
          : await db.query(
              table,
              where: 'id = ?',
              whereArgs: [localId],
              limit: 1,
            );

      if (byLocalId.isNotEmpty) {
        final receipt = byLocalId.first['receipt_no']?.toString() ??
            byLocalId.first['computer_receipt_no']?.toString();
        if (receipt != null &&
            receipt.trim().isNotEmpty &&
            receipt.trim() != '0') {
          if (isMasjid && !receipt.trim().startsWith('M-')) {
            return 'M-${receipt.trim()}';
          }
          return receipt.trim();
        }
      }

      final rows = await db.query(
        table,
        where:
            'date = ? AND type = ? AND amount = ? AND description = ? AND user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)',
        whereArgs: [
          row['date'],
          row['type'],
          row['amount'],
          row['description'],
          row['user_id'],
        ],
        orderBy: 'id DESC',
        limit: 1,
      );

      if (rows.isEmpty) return null;

      final receipt = rows.first['receipt_no']?.toString() ??
          rows.first['computer_receipt_no']?.toString();
      if (receipt == null || receipt.trim().isEmpty || receipt.trim() == '0') {
        return null;
      }
      if (isMasjid && !receipt.trim().startsWith('M-')) {
        return 'M-${receipt.trim()}';
      }
      return receipt.trim();
    } catch (e) {
      debugPrint('Error finding saved receipt no: $e');
      return null;
    }
  }



  String _displayTitle(Map<String, dynamic> row) {
    final description = (row['description'] ?? '').toString();
    final head = _extractPart(description, ['\u0645\u062f:', 'مد:']);
    if (head.isNotEmpty) return head;

    final headName = (row['head_name'] ?? '').toString().trim();
    if (headName.isNotEmpty) return headName;

    return (row['type'] ?? '').toString();
  }

  String _displaySubtitle(Map<String, dynamic> row) {
    final note = _extractPart((row['description'] ?? '').toString(),
        ['\u062a\u0641\u0635\u06cc\u0644:', 'تصیل:']);
    if (note.isNotEmpty) return note;

    final spender = (row['spender_name'] ?? '').toString().trim();
    final donor = (row['donor_name'] ?? '').toString().trim();
    final person = spender.isNotEmpty ? spender : donor;
    final desc = (row['description'] ?? '').toString().trim();

    if (person.isNotEmpty && desc.isNotEmpty) {
      return '$person - $desc';
    } else if (person.isNotEmpty) {
      return person;
    }
    return desc;
  }



  @override

  Widget build(BuildContext context) {

    return ValueListenableBuilder<bool>(

      valueListenable: isUrduGlobal,

      builder: (context, isUrdu, child) {

        return PopScope(

          canPop: false,

          onPopInvokedWithResult: (didPop, result) {
            if (didPop) return;
            if (Navigator.of(context).canPop()) {
              Navigator.of(context).pop();
            } else {
              Navigator.pushReplacement(
                context,
                MaterialPageRoute(builder: (c) => const DashboardPage()),
              );
            }
          },

          child: Directionality(

            textDirection: isUrdu ? ui.TextDirection.rtl : ui.TextDirection.ltr,

            child: Builder(builder: (context) {
              final theme = Theme.of(context);
              final isDark = theme.brightness == Brightness.dark;
              final effectiveBg = theme.scaffoldBackgroundColor;
              final effectiveAppBarBg = isDark ? theme.colorScheme.surface : primaryGreen;
              final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';

              return Scaffold(

                backgroundColor: effectiveBg,

                drawer: const AppDrawer(),

                appBar: AppBar(

                  leading: Builder(

                    builder: (context) => IconButton(

                      icon: const Icon(Icons.menu, color: Colors.white),

                      onPressed: () => Scaffold.of(context).openDrawer(),

                    ),

                  ),

                  title: Text(isUrduGlobal.value

                      ? (isMasjid ? 'مسجد فنانس (آمدن و خرچ)' : 'آمدن و خرچ (سادہ فنانس)')

                      : (isMasjid ? 'Masjid Finance' : 'Simple Finance')),

                  backgroundColor: effectiveAppBarBg,

                  foregroundColor: Colors.white,

                  centerTitle: true,

                actions: [
                  if (_isAdminRole)
                    Stack(
                      alignment: Alignment.center,
                      children: [
                        IconButton(
                          tooltip: isUrduGlobal.value ? 'OTP اجازت نامے' : 'OTP Permissions',
                          icon: const Icon(Icons.key, color: Colors.white),
                          onPressed: _showAdminPendingOtpsDialog,
                        ),
                        if (_pendingOtpsCount > 0)
                          Positioned(
                            top: 6,
                            right: 6,
                            child: Container(
                              padding: const EdgeInsets.all(3),
                              decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                              constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                              child: Text(
                                '$_pendingOtpsCount',
                                style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                                textAlign: TextAlign.center,
                              ),
                            ),
                          ),
                      ],
                    ),
                  IconButton(

                    icon: const Icon(Icons.sync, color: Colors.white),

                    onPressed: () async {

                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                          content: Text(isUrduGlobal.value
                              ? "ڈیٹا سنک ہو رہا ہے..."
                              : "Syncing data...")));

                      try {

                        await SyncService.runTwoWaySync();

                        if (mounted) {

                          _loadTransactions();

                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrduGlobal.value
                                  ? "ڈیٹا کامیابی سے سنک ہو گیا"
                                  : "Sync complete!"),
                              backgroundColor: Colors.green));

                        }

                      } catch (e, stackTrace) {
                        debugPrint('Finance manual sync error: $e\nStack: $stackTrace');
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(isUrduGlobal.value
                                  ? "سنک ناکام ہو گیا"
                                  : "Sync failed"),
                              backgroundColor: Colors.red));
                        }
                      }

                    },

                  ),

                ],

              ),

              body: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1100),
                  child: SingleChildScrollView(
                    controller: _scrollController,
                    padding: const EdgeInsets.all(15),
                    child: Column(

                  children: [

                    if (_selectedTabIndex == 0)
                      _buildCollectionBalanceCard(isUrdu)
                    else if (_selectedTabIndex == 1)
                      _buildAdvanceBalanceCard(isUrdu),

                    Row(

                      children: [

                        Expanded(

                          child: _tabButton(

                            title: isUrduGlobal.value

                                ? '\u0622\u0645\u062f\u0646'

                                : 'Income',

                            icon: Icons.arrow_downward,

                            index: 0,

                            activeColor: Colors.green.shade800,

                          ),

                        ),

                        const SizedBox(width: 8),

                        Expanded(

                          child: _tabButton(

                            title: isUrduGlobal.value

                                ? '\u062e\u0631\u0686'

                                : 'Expense',

                            icon: Icons.arrow_upward,

                            index: 1,

                            activeColor: Colors.red.shade800,

                          ),

                        ),

                        const SizedBox(width: 8),

                        Expanded(

                          child: _tabButton(

                            title: isUrduGlobal.value

                                ? 'رقم منتقلی'

                                : 'Transfers',

                            icon: Icons.swap_horiz,

                            index: 2,

                            activeColor: Colors.orange.shade800,

                          ),

                        ),

                      ],

                    ),

                    if (_selectedTabIndex == 2) ...[

                      const SizedBox(height: 16),

                      _buildTransferForm(isUrdu),

                      const SizedBox(height: 16),

                      _buildTransferHistory(isUrdu),

                    ] else ...[

                      const SizedBox(height: 20),

                      _buildInputs(),

                      const SizedBox(height: 16),

                      if (_editingId != null) ...[
                        Container(
                          margin: const EdgeInsets.only(bottom: 12),
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                          decoration: BoxDecoration(
                            color: Colors.amber.shade50,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: Colors.amber.shade400),
                          ),
                          child: Row(
                            children: [
                              Icon(Icons.edit_note, color: Colors.amber.shade900),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  isUrduGlobal.value
                                      ? 'ترمیم برائے انٹری #$_editingId (اندراج کنندہ: ${_editingUserName ?? "صارف"} - کھاتہ اسی صارف کا رہے گا)'
                                      : 'Editing entry #$_editingId (Created by: ${_editingUserName ?? "User"} - remains in original user account)',
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.amber.shade900,
                                  ),
                                ),
                              ),
                              IconButton(
                                icon: const Icon(Icons.close, size: 18),
                                onPressed: _resetForm,
                                tooltip: isUrduGlobal.value ? 'منسوخ کریں' : 'Cancel',
                              ),
                            ],
                          ),
                        ),
                      ],

                      Row(

                        children: [

                          Expanded(

                            child: SizedBox(

                              height: 55,

                              child: ElevatedButton.icon(

                                style: ElevatedButton.styleFrom(

                                  backgroundColor: isIncome

                                      ? Colors.green.shade800

                                      : Colors.red.shade800,

                                  shape: RoundedRectangleBorder(

                                      borderRadius: BorderRadius.circular(10)),

                                ),

                                onPressed: _isSaving ? null : _saveTransaction,

                                icon: _isSaving
                                    ? const SizedBox(
                                        width: 20,
                                        height: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : const Icon(Icons.save, color: Colors.white),

                                label: Text(
                                  _isSaving
                                      ? (isUrduGlobal.value
                                          ? 'محفوظ ہو رہا ہے...'
                                          : 'Saving...')
                                      : _editingId != null
                                          ? (isUrduGlobal.value
                                              ? 'اپ ڈیٹ کریں'
                                              : 'Update Entry')
                                          : (isUrduGlobal.value
                                              ? (isIncome
                                                  ? 'محفوظ کریں اور رسید نکالیں'
                                                  : 'محفوظ کریں')
                                              : (isIncome
                                                  ? 'Save & Receipt'
                                                  : 'Save Entry')),

                                  style: const TextStyle(

                                      color: Colors.white,

                                      fontSize: 16,

                                      fontWeight: FontWeight.bold),

                                ),

                              ),

                            ),

                          ),

                          if (_editingId != null) ...[

                            const SizedBox(width: 10),

                            OutlinedButton(

                              onPressed: _resetForm,

                              child: Text(isUrduGlobal.value

                                  ? '\u0645\u0646\u0633\u0648\u062e'

                                  : 'Cancel'),

                            ),

                          ],

                        ],

                      ),

                      const SizedBox(height: 24),

                      _buildBalanceSummary(isUrdu),

                      const SizedBox(height: 16),

                      _buildFinanceSections(isUrdu),

                    ],

                  ],

                ),

              ),
            ),
          ),
        );
      }),

        ),

      );

      },

    );

  }



  Widget _buildPeriodFilterBar(bool isUrdu) {
    final periods = [
      {'key': 'current_month', 'labelUr': 'رواں ماہ', 'labelEn': 'Current Month'},
      {'key': 'last_month', 'labelUr': 'پچھلا مہینہ', 'labelEn': 'Last Month'},
      {'key': 'current_year', 'labelUr': 'رواں سال', 'labelEn': 'Current Year'},
      {'key': 'custom', 'labelUr': 'کسٹم', 'labelEn': 'Custom'},
    ];

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.grey.shade100,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            children: [
              Icon(Icons.calendar_month, size: 16, color: primaryGreen),
              const SizedBox(width: 6),
              Text(
                isUrdu ? 'مدت فلٹر:' : 'Filter:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.grey.shade800),
              ),
            ],
          ),
          Wrap(
            spacing: 6,
            children: periods.map((p) {
              final isSelected = _selectedPeriod == p['key'];
              return ChoiceChip(
                label: Text(
                  isUrdu ? p['labelUr']! : p['labelEn']!,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                    color: isSelected ? Colors.white : Colors.black87,
                  ),
                ),
                selected: isSelected,
                selectedColor: primaryGreen,
                backgroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 6),
                visualDensity: VisualDensity.compact,
                onSelected: (selected) async {
                  if (p['key'] == 'custom') {
                    final picked = await showDateRangePicker(
                      context: context,
                      firstDate: DateTime(2020),
                      lastDate: DateTime(2030),
                      initialDateRange: _customStartDate != null && _customEndDate != null
                          ? DateTimeRange(start: _customStartDate!, end: _customEndDate!)
                          : DateTimeRange(
                              start: DateTime(DateTime.now().year, DateTime.now().month, 1),
                              end: DateTime(DateTime.now().year, DateTime.now().month + 1, 0),
                            ),
                    );
                    if (picked != null) {
                      setState(() {
                        _selectedPeriod = 'custom';
                        _customStartDate = picked.start;
                        _customEndDate = picked.end;
                      });
                    }
                  } else {
                    setState(() {
                      _selectedPeriod = p['key']!;
                    });
                  }
                },
              );
            }).toList(),
          ),
        ],
      ),
    );
  }

  Widget _buildDesktopFinanceSummaryCard({
    required String title,
    required String value,
    required IconData icon,
    required Color color,
    String? subtitle,
    Widget? badge,
  }) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withOpacity(0.35), width: 1.2),
          boxShadow: [
            BoxShadow(
              color: color.withOpacity(0.06),
              blurRadius: 8,
              offset: const Offset(0, 3),
            ),
          ],
        ),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: color.withOpacity(0.12),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: color, size: 24),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        title,
                        style: TextStyle(
                          fontSize: 13,
                          color: Colors.grey.shade700,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      if (badge != null) badge,
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    value,
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: color,
                    ),
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey.shade600,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBalanceSummary(bool isUrdu) {
    double income = 0;
    double expense = 0;
    int incomeCount = 0;
    int expenseCount = 0;

    for (var r in _financeRecords) {
      if (!_isRecordInSelectedPeriod(r)) continue;
      double amt = double.tryParse(r['amount']?.toString() ?? '0') ?? 0;
      final typeStr = r['type']?.toString().toLowerCase().trim() ?? '';
      if (typeStr == 'income' || typeStr == 'آمدن') {
        income += amt;
        incomeCount++;
      } else if (typeStr == 'expense' || typeStr == 'خرچ') {
        expense += amt;
        expenseCount++;
      }
    }

    double balance = income - expense;
    final screenWidth = MediaQuery.of(context).size.width;
    final isDesktop = screenWidth >= 900;

    if (isDesktop) {
      return Column(
        children: [
          _buildPeriodFilterBar(isUrdu),
          Row(
            children: [
              _buildDesktopFinanceSummaryCard(
                title: isUrdu ? 'خالص بیلنس' : 'Net Balance',
                value: 'Rs. ${balance.toStringAsFixed(0)}',
                icon: Icons.account_balance_wallet,
                color: primaryGreen,
                subtitle: _getPeriodDisplayLabel(isUrdu),
              ),
              const SizedBox(width: 12),
              _buildDesktopFinanceSummaryCard(
                title: isUrdu ? 'کل آمدن' : 'Total Income',
                value: 'Rs. ${income.toStringAsFixed(0)}',
                icon: Icons.arrow_downward,
                color: const Color(0xFF1B5E20),
                badge: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F5E9),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    '$incomeCount ${isUrdu ? 'اندراجات' : 'items'}',
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32)),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              _buildDesktopFinanceSummaryCard(
                title: isUrdu ? 'کل اخراجات' : 'Total Expense',
                value: 'Rs. ${expense.toStringAsFixed(0)}',
                icon: Icons.arrow_upward,
                color: const Color(0xFFC62828),
                badge: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFEBEE),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    '$expenseCount ${isUrdu ? 'اندراجات' : 'items'}',
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFFC62828)),
                  ),
                ),
              ),
            ],
          ),
        ],
      );
    }

    return Column(
      children: [
        _buildPeriodFilterBar(isUrdu),
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: primaryGreen,
            borderRadius: BorderRadius.circular(15),
            boxShadow: const [
              BoxShadow(
                color: Colors.black26,
                blurRadius: 10,
                offset: Offset(0, 4),
              ),
            ],
          ),
          child: Column(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                margin: const EdgeInsets.only(bottom: 8),
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  _getPeriodDisplayLabel(isUrdu),
                  style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                ),
              ),
              Text(
                _isAdminRole
                    ? (isUrdu ? 'کل بیلنس (تمام صارفین)' : 'Total Balance (All Users)')
                    : (isUrdu ? 'کل بیلنس' : 'Total Balance'),
                style: const TextStyle(color: Colors.white70, fontSize: 14),
              ),
              const SizedBox(height: 5),
              Text(
                'Rs. ${balance.toStringAsFixed(0)}',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 32,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 15),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  _summaryItem(isUrdu ? 'کل آمدن' : 'Total Income', income, Colors.greenAccent),
                  _summaryItem(isUrdu ? 'کل اخراجات' : 'Total Expense', expense, Colors.orangeAccent),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }



  Widget _summaryItem(String label, double amount, Color color) {

    return Column(

      children: [

        Text(label,

            style: const TextStyle(color: Colors.white60, fontSize: 11)),

        Text('Rs. ${amount.toStringAsFixed(0)}',

            style: TextStyle(

                color: color, fontSize: 16, fontWeight: FontWeight.bold)),

      ],

    );

  }

  Widget _buildExpandableHistoryBox({
    required String title,
    required int count,
    required double totalAmount,
    required Color color,
    required bool isExpanded,
    required VoidCallback onToggle,
    required List<Widget> children,
    required bool isUrdu,
    required IconData icon,
  }) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: color.withOpacity(0.35), width: 1),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          InkWell(
            onTap: onToggle,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: color.withOpacity(0.08),
                border: Border(
                  bottom: BorderSide(
                    color: isExpanded ? color.withOpacity(0.2) : Colors.transparent,
                  ),
                ),
              ),
              child: Row(
                children: [
                  Icon(icon, color: color, size: 20),
                  const SizedBox(width: 8),
                  Text(
                    title,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: color,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    decoration: BoxDecoration(
                      color: color,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      '$count',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  const Spacer(),
                  Text(
                    'Rs. ${totalAmount.toStringAsFixed(0)}',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 13,
                      color: color,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Icon(
                    isExpanded
                        ? Icons.keyboard_arrow_up
                        : Icons.keyboard_arrow_down,
                    color: color,
                  ),
                ],
              ),
            ),
          ),
          if (isExpanded) ...[
            if (children.isEmpty)
              _emptySection(isUrdu)
            else
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 4),
                child: Column(children: children),
              ),
          ],
        ],
      ),
    );
  }

  Widget _buildFinanceSections(bool isUrdu) {
    if (_isAdminRole) {
      return _buildAdminUserWiseView(isUrdu);
    }

    final incomeList = _financeRecords
        .where((r) => r['type'].toString().toLowerCase() == 'income' && _isRecordInSelectedPeriod(r))
        .toList();
    final expenseList = _financeRecords
        .where((r) => r['type'].toString().toLowerCase() == 'expense' && _isRecordInSelectedPeriod(r))
        .toList();

    final double uInc = incomeList.fold(
        0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
    final double uExp = expenseList.fold(
        0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));

    final incomeBox = _buildExpandableHistoryBox(
      title: isUrdu ? 'آمدن اندراجات' : 'Income Entries',
      count: incomeList.length,
      totalAmount: uInc,
      color: Colors.green.shade800,
      icon: Icons.arrow_downward,
      isExpanded: _isBoxExpanded('single_income', isDefault: true),
      onToggle: () => _toggleBox('single_income', currentDefault: true),
      children: incomeList.map((r) => _transactionCard(r, true)).toList(),
      isUrdu: isUrdu,
    );

    final expenseBox = _buildExpandableHistoryBox(
      title: isUrdu ? 'خرچ اندراجات' : 'Expense Entries',
      count: expenseList.length,
      totalAmount: uExp,
      color: Colors.red.shade800,
      icon: Icons.arrow_upward,
      isExpanded: _isBoxExpanded('single_expense', isDefault: true),
      onToggle: () => _toggleBox('single_expense', currentDefault: true),
      children: expenseList.map((r) => _transactionCard(r, false)).toList(),
      isUrdu: isUrdu,
    );

    return Column(
      children: [
        if (isIncome) ...[
          incomeBox,
          expenseBox,
        ] else ...[
          expenseBox,
          incomeBox,
        ],
      ],
    );
  }

  Widget _buildAdminUserWiseView(bool isUrdu) {
    if (_userGroups.isEmpty) {
      if (_financeRecords.isNotEmpty) {
        final incomeList = _financeRecords
            .where((r) => r['type'].toString().toLowerCase() == 'income' && _isRecordInSelectedPeriod(r))
            .toList();
        final expenseList = _financeRecords
            .where((r) => r['type'].toString().toLowerCase() == 'expense' && _isRecordInSelectedPeriod(r))
            .toList();
        double uInc = incomeList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
        double uExp = expenseList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));

        final incomeBox = _buildExpandableHistoryBox(
          title: isUrdu ? 'آمدن اندراجات' : 'Income Entries',
          count: incomeList.length,
          totalAmount: uInc,
          color: Colors.green.shade800,
          icon: Icons.arrow_downward,
          isExpanded: _isBoxExpanded('admin_fallback_income', isDefault: true),
          onToggle: () => _toggleBox('admin_fallback_income', currentDefault: true),
          children: incomeList.map((r) => _transactionCard(r, true)).toList(),
          isUrdu: isUrdu,
        );

        final expenseBox = _buildExpandableHistoryBox(
          title: isUrdu ? 'خرچ اندراجات' : 'Expense Entries',
          count: expenseList.length,
          totalAmount: uExp,
          color: Colors.red.shade800,
          icon: Icons.arrow_upward,
          isExpanded: _isBoxExpanded('admin_fallback_expense', isDefault: true),
          onToggle: () => _toggleBox('admin_fallback_expense', currentDefault: true),
          children: expenseList.map((r) => _transactionCard(r, false)).toList(),
          isUrdu: isUrdu,
        );

        return Column(
          children: [
            if (isIncome) ...[
              incomeBox,
              expenseBox,
            ] else ...[
              expenseBox,
              incomeBox,
            ],
          ],
        );
      }

      return Padding(
        padding: const EdgeInsets.all(30),
        child: Center(
          child: Text(
            isUrdu ? 'کوئی ریکارڈ نہیں ملا' : 'No records found',
            style: const TextStyle(color: Colors.grey, fontSize: 16),
          ),
        ),
      );
    }

    return Column(
      children: _userGroups.entries.map((entry) {
        final uid = entry.key;
        final ug = entry.value;
        final uname = ug['name'] as String;
        final rawIncomeList = (ug['income'] as List).cast<Map<String, dynamic>>();
        final rawExpenseList =
            (ug['expense'] as List).cast<Map<String, dynamic>>();
        final incomeList = rawIncomeList.where(_isRecordInSelectedPeriod).toList();
        final expenseList = rawExpenseList.where(_isRecordInSelectedPeriod).toList();
        double uInc = incomeList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
        double uExp = expenseList.fold(
            0, (s, r) => s + (double.tryParse(r['amount'].toString()) ?? 0));
        double uBal = uInc - uExp;
        final hasAdv = ug['has_advance'] == true;
        final advRec = (ug['advance_received'] as num?)?.toDouble() ?? 0.0;
        final advDed = (ug['advance_deducted'] as num?)?.toDouble() ?? 0.0;
        final advBal = (ug['advance_balance'] as num?)?.toDouble() ?? (advRec - advDed);
        final otherExp = (ug['other_expenses'] as num?)?.toDouble() ?? 0.0;
        final isExpanded = _expandedUsers.contains(uid);

        final incomeBox = _buildExpandableHistoryBox(
          title: isUrdu ? 'آمدن اندراجات' : 'Income Entries',
          count: incomeList.length,
          totalAmount: uInc,
          color: Colors.green.shade800,
          icon: Icons.arrow_downward,
          isExpanded: _isBoxExpanded('u_${uid}_income', isDefault: true),
          onToggle: () =>
              _toggleBox('u_${uid}_income', currentDefault: true),
          children: incomeList.map((r) => _transactionCard(r, true)).toList(),
          isUrdu: isUrdu,
        );

        final expenseBox = _buildExpandableHistoryBox(
          title: isUrdu ? 'خرچ اندراجات' : 'Expense Entries',
          count: expenseList.length,
          totalAmount: uExp,
          color: Colors.red.shade800,
          icon: Icons.arrow_upward,
          isExpanded: _isBoxExpanded('u_${uid}_expense', isDefault: true),
          onToggle: () =>
              _toggleBox('u_${uid}_expense', currentDefault: true),
          children: expenseList.map((r) => _transactionCard(r, false)).toList(),
          isUrdu: isUrdu,
        );

        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          elevation: 3,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              // User header bar
              InkWell(
                onTap: () {
                  setState(() {
                    if (isExpanded) {
                      _expandedUsers.remove(uid);
                    } else {
                      _expandedUsers.add(uid);
                    }
                  });
                },
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  decoration: BoxDecoration(
                    color: primaryGreen,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.person, color: Colors.white70, size: 20),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              uname,
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Icon(
                            isExpanded ? Icons.expand_less : Icons.expand_more,
                            color: Colors.white70,
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: [
                            if (uInc > 0 || !hasAdv) ...[
                              _adminBadge(isUrdu ? 'آمدن' : 'In', uInc,
                                  Colors.greenAccent.shade700),
                              const SizedBox(width: 6),
                            ],
                            _adminBadge(
                                isUrdu ? 'خرچ' : 'Exp', uExp, Colors.redAccent),
                            const SizedBox(width: 6),
                            if (hasAdv) ...[
                              _adminBadge(
                                  isUrdu ? 'ایڈوانس' : 'Adv In',
                                  advRec,
                                  Colors.cyanAccent),
                              const SizedBox(width: 6),
                              _adminBadge(
                                  isUrdu ? 'منہا' : 'Adv Out',
                                  advDed,
                                  Colors.orangeAccent),
                              const SizedBox(width: 6),
                              _adminBadge(
                                  isUrdu ? 'بقایا' : 'Adv Bal',
                                  advBal,
                                  advBal >= 0
                                      ? Colors.lightGreenAccent
                                      : Colors.pinkAccent),
                              if (otherExp > 0) ...[
                                const SizedBox(width: 6),
                                _adminBadge(
                                    isUrdu ? 'کیش خرچ' : 'Cash Exp',
                                    otherExp,
                                    Colors.purpleAccent),
                              ],
                            ] else ...[
                              _adminBadge(
                                  isUrdu ? 'بقایا' : 'Bal',
                                  uBal,
                                  uBal >= 0
                                      ? Colors.lightBlueAccent
                                      : Colors.orangeAccent),
                            ],
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              // Expandable content
              if (isExpanded) ...[
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  color: Colors.grey.shade50,
                  child: Row(
                    children: [
                      if (uInc > 0 || !hasAdv) ...[
                        Expanded(
                            child: _userStatChip(isUrdu ? 'آمدن' : 'Income', uInc,
                                Colors.green.shade700)),
                        const SizedBox(width: 8),
                      ],
                      Expanded(
                          child: _userStatChip(isUrdu ? 'خرچ' : 'Expense', uExp,
                              Colors.red.shade700)),
                      const SizedBox(width: 8),
                      if (hasAdv) ...[
                        Expanded(
                            child: _userStatChip(isUrdu ? 'موصولہ ایڈوانس' : 'Adv In', advRec,
                                Colors.blue.shade700)),
                        const SizedBox(width: 8),
                        Expanded(
                            child: _userStatChip(isUrdu ? 'ایڈوانس سے منہا' : 'Adv Deducted', advDed,
                                Colors.deepOrange.shade700)),
                        const SizedBox(width: 8),
                        Expanded(
                            child: _userStatChip(
                          isUrdu ? 'بقایا ایڈوانس' : 'Adv Bal',
                          advBal,
                          advBal >= 0
                              ? Colors.teal.shade700
                              : Colors.pink.shade700,
                        )),
                        if (otherExp > 0) ...[
                          const SizedBox(width: 8),
                          Expanded(
                              child: _userStatChip(
                            isUrdu ? 'کیش خرچ' : 'Cash Exp',
                            otherExp,
                            Colors.purple.shade700,
                          )),
                        ],
                      ] else ...[
                        Expanded(
                            child: _userStatChip(
                          isUrdu ? 'بقایا' : 'Balance',
                          uBal,
                          uBal >= 0
                              ? Colors.blue.shade700
                              : Colors.orange.shade700,
                        )),
                      ],
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(8.0),
                  child: Column(
                    children: [
                      if (isIncome) ...[
                        incomeBox,
                        expenseBox,
                      ] else ...[
                        expenseBox,
                        incomeBox,
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 4),
              ],
            ],
          ),
        );
      }).toList(),
    );
  }



  Widget _adminBadge(String label, double amount, Color color) {

    return Container(

      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),

      decoration: BoxDecoration(

        color: color.withOpacity(0.15),

        borderRadius: BorderRadius.circular(8),

        border: Border.all(color: color.withOpacity(0.6)),

      ),

      child: Column(

        children: [

          Text(label,

              style: TextStyle(

                  color: color, fontSize: 8, fontWeight: FontWeight.bold)),

          Text(amount.toStringAsFixed(0),

              style: TextStyle(

                  color: color, fontSize: 10, fontWeight: FontWeight.bold)),

        ],

      ),

    );

  }



  Widget _userStatChip(String label, double amount, Color color) {

    return Container(

      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),

      decoration: BoxDecoration(

        color: color.withOpacity(0.08),

        borderRadius: BorderRadius.circular(10),

        border: Border.all(color: color.withOpacity(0.3)),

      ),

      child: Column(

        children: [

          Text(label,

              style: TextStyle(

                  fontSize: 11, color: color, fontWeight: FontWeight.bold)),

          const SizedBox(height: 2),

          Text('Rs.${amount.toStringAsFixed(0)}',

              style: TextStyle(

                  fontSize: 13, fontWeight: FontWeight.bold, color: color)),

        ],

      ),

    );

  }



  Widget _emptySection(bool isUrdu) {

    return Padding(

      padding: const EdgeInsets.all(20),

      child: Center(

          child: Text(isUrdu ? '  ' : 'No records',

              style: const TextStyle(color: Colors.grey))),

    );

  }



  Widget _transactionCard(Map<String, dynamic> row, bool isInc) {

    return Card(

      margin: const EdgeInsets.only(bottom: 10),

      child: ListTile(

        leading: CircleAvatar(

          backgroundColor: isInc ? Colors.green.shade50 : Colors.red.shade50,

          child: Icon(isInc ? Icons.arrow_downward : Icons.arrow_upward,

              color: isInc ? Colors.green.shade800 : Colors.red.shade800,

              size: 20),

        ),

        title: Text(_displayTitle(row),

            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),

        subtitle: Column(

          crossAxisAlignment: CrossAxisAlignment.start,

          children: [

            Text('${row['date']} | ${_displaySubtitle(row)}',

                style: const TextStyle(fontSize: 12)),

            if (_isAdminRole &&

                row['user_name'] != null &&

                row['user_name'].toString().isNotEmpty)

              Text('${isInc ? (isUrduGlobal.value ? "وصول کنندہ" : "Receiver") : (isUrduGlobal.value ? "ادا کنندہ" : "Payer")}: ${row['user_name']}',

                  style: const TextStyle(

                      fontSize: 10,

                      fontWeight: FontWeight.bold,

                      color: Colors.blueGrey)),

          ],

        ),

        trailing: Column(

          mainAxisAlignment: MainAxisAlignment.center,

          crossAxisAlignment: CrossAxisAlignment.end,

          children: [

            Text('Rs. ${row['amount']}',

                style: TextStyle(

                    fontWeight: FontWeight.bold,

                    color:

                        isInc ? Colors.green.shade800 : Colors.red.shade800)),

            const SizedBox(height: 2),

            Row(

              mainAxisSize: MainAxisSize.min,

              children: [
                if (row['bill_image'] != null && row['bill_image'].toString().trim().isNotEmpty) ...[
                  InkWell(
                    onTap: () => _showFullBillImage(row['bill_image'].toString()),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.amber.shade50,
                        borderRadius: BorderRadius.circular(4),
                        border: Border.all(color: Colors.amber.shade700, width: 0.5),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.attachment, size: 14, color: Colors.amber.shade900),
                          const SizedBox(width: 2),
                          Text(
                            isUrduGlobal.value ? 'بل' : 'Bill',
                            style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                ],
                if (isInc) ...[

                  InkWell(

                      onTap: () {

                        final amount =

                            double.tryParse(row['amount'].toString()) ?? 0.0;

                        _openReceiptForTransaction(row, amount);

                      },

                      child: Icon(Icons.receipt_long,

                          size: 16, color: Colors.blue.shade800)),

                  const SizedBox(width: 10),

                ],

                InkWell(

                    onTap: () async {
                      if (!_isAdminRole) {
                        final ok = await _ensureEditPermission(row);
                        if (!ok) return;
                      }
                      _populateForEdit(row);
                    },

                    child: Icon(Icons.edit, size: 16, color: primaryGreen)),

                const SizedBox(width: 10),

                InkWell(

                    onTap: () => _deleteTransaction(row),

                    child:

                        const Icon(Icons.delete, size: 16, color: Colors.red)),

              ],

            ),

          ],

        ),

      ),

    );

  }



  Widget _tabButton({
    required String title,
    required IconData icon,
    required int index,
    required Color activeColor,
  }) {
    final active = _selectedTabIndex == index;
    return GestureDetector(
      onTap: () => setState(() {
        _selectedTabIndex = index;
        if (index == 0) {
          isIncome = true;
          selectedHead = null;
          _autocompleteKey = UniqueKey();
          _customBoxExpansion.clear();
          _selectedPaymentMethod = 'Cash';
        } else if (index == 1) {
          isIncome = false;
          selectedHead = null;
          _autocompleteKey = UniqueKey();
          _customBoxExpansion.clear();
          if (!_isAdminRole && _selectedAdvanceAccountId != null) {
            _selectedPaymentAccountId = _selectedAdvanceAccountId;
            _selectedPaymentMethod = 'Advance';
          }
        }
      }),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
        decoration: BoxDecoration(
          color: active ? activeColor : Colors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
              color: active ? activeColor : Colors.grey.shade300, width: 2),
          boxShadow: active
              ? [
                  BoxShadow(
                    color: activeColor.withOpacity(0.3),
                    blurRadius: 4,
                    offset: const Offset(0, 2),
                  )
                ]
              : null,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 18, color: active ? Colors.white : activeColor),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: active ? Colors.white : Colors.black87,
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }



  Widget _buildCollectionBalanceCard(bool isUrdu) {
    final isAdmin = _isAdminRole;
    final activeCol = _userCollectionSummaries[_selectedCollectionAccountId];
    final bal = (activeCol?['net_in_hand'] as num?)?.toDouble() ?? _userCollectionBalance;
    final totalRec = (activeCol?['total_collected'] as num?)?.toDouble() ?? _totalOverallCollectionReceived;
    final totalTr = (activeCol?['total_transferred'] as num?)?.toDouble() ?? _totalOverallCollectionTransferred;
    final userLabel = activeCol?['user_label']?.toString() ?? currentUserName.value ?? (isUrdu ? 'کوئی نہیں' : 'None');

    if (!isAdmin) {
      return Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border(right: BorderSide(color: Colors.green.shade700, width: 4)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.04),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.green.shade50,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: Colors.green.shade200),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.inventory_2_outlined, size: 16, color: Colors.green.shade800),
                      const SizedBox(width: 4),
                      Text(
                        isUrdu ? 'نقد وصولی امانت برائے: $userLabel' : 'Collection In-Hand: $userLabel',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green.shade900),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: bal > 0 ? Colors.amber.shade50 : Colors.green.shade50,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: bal > 0 ? Colors.amber.shade300 : Colors.green.shade300),
                  ),
                  child: Text(
                    bal > 0
                        ? (isUrdu ? 'رقم پاس موجود ہے (امانت)' : 'Cash in Hand')
                        : (isUrdu ? 'کوئی نقد رقم بقایا نہیں' : 'No Cash Pending'),
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: bal > 0 ? Colors.amber.shade900 : Colors.green.shade800,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isUrdu ? 'میرے پاس وصول شدہ رقم (امانت):' : 'Your Collected Cash in Hand:',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.black87),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        Text(
                          isUrdu ? 'کل وصولی: ' : 'Total Collected: ',
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                        ),
                        Text(
                          'Rs. ${totalRec.toStringAsFixed(0)}',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.green.shade800),
                        ),
                        const Text(' | ', style: TextStyle(fontSize: 11, color: Colors.grey)),
                        Text(
                          isUrdu ? 'ایڈمن کو منتقل: ' : 'Handed Over: ',
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                        ),
                        Text(
                          'Rs. ${totalTr.toStringAsFixed(0)}',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                        ),
                      ],
                    ),
                  ],
                ),
                Text(
                  'Rs. ${bal.toStringAsFixed(0)}',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 20,
                    color: bal > 0 ? Colors.teal.shade900 : Colors.grey.shade600,
                  ),
                ),
              ],
            ),
            if (bal > 0) ...[
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: () {
                    final cashAcc = _assetAccounts.firstWhere(
                      (a) => a['code']?.toString() == '1001',
                      orElse: () => {},
                    );
                    final cashId = cashAcc['id']?.toString() ?? '';
                    final colId = activeCol?['account_id']?.toString() ?? (_selectedCollectionAccountId?.toString() ?? '');
                    setState(() {
                      _selectedTabIndex = 2; // Switch to Transfers
                      if (colId.isNotEmpty) {
                        _selectedFromOption = 'acc_$colId';
                      }
                      if (cashId.isNotEmpty) {
                        _selectedToOption = 'acc_$cashId';
                      }
                      _transferAmountController.text = bal > 0 ? bal.toStringAsFixed(0) : '';
                      _transferDescController.text = isUrdu
                          ? 'استاد ($userLabel) کی جمع شدہ نقد وصولی مرکزی گلے میں جمع کروائی گئی'
                          : 'Handover collected funds by $userLabel to admin';
                    });
                  },
                  icon: const Icon(Icons.send_rounded, size: 16),
                  label: Text(
                    isUrdu ? 'ایڈمن کو رقم جمع کروائیں (منتقلی)' : 'Handover Cash to Admin',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.teal.shade700,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                ),
              ),
            ],
          ],
        ),
      );
    }

    // Admin View
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border(right: BorderSide(color: Colors.green.shade700, width: 4)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.green.shade50,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: Colors.green.shade200),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.inventory_2_outlined, size: 16, color: Colors.green.shade800),
                        const SizedBox(width: 4),
                        Text(
                          isUrdu ? 'عملہ وصولی کھاتے (امانتیں)' : 'Staff Collection Accounts',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green.shade900),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  if (_userCollectionSummaries.isNotEmpty)
                    DropdownButton<int>(
                      value: _selectedCollectionAccountId,
                      underline: const SizedBox(),
                      isDense: true,
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green.shade900),
                      items: _userCollectionSummaries.entries.map((e) {
                        final s = e.value;
                        final lbl = s['user_label']?.toString() ?? s['name']?.toString() ?? '';
                        return DropdownMenuItem<int>(
                          value: e.key,
                          child: Text(lbl),
                        );
                      }).toList(),
                      onChanged: (newAid) {
                        if (newAid != null && _userCollectionSummaries.containsKey(newAid)) {
                          setState(() {
                            _selectedCollectionAccountId = newAid;
                            _userCollectionBalance = (_userCollectionSummaries[newAid]!['net_in_hand'] as num?)?.toDouble() ?? 0.0;
                          });
                        }
                      },
                    ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: bal > 0 ? Colors.amber.shade50 : Colors.green.shade50,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: bal > 0 ? Colors.amber.shade300 : Colors.green.shade300),
                ),
                child: Text(
                  bal > 0
                      ? (isUrdu ? 'عملہ کے پاس رقم موجود' : 'Cash in Hand with Staff')
                      : (isUrdu ? 'صاف / کوئی رقم نہیں' : 'Settled'),
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: bal > 0 ? Colors.amber.shade900 : Colors.green.shade800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    isUrdu ? 'منتخب صارف کے پاس نقد وصولی:' : 'Selected Staff Collection in Hand:',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.black87),
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Text(
                        isUrdu ? 'کل وصولی: ' : 'Collected: ',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                      ),
                      Text(
                        'Rs. ${totalRec.toStringAsFixed(0)}',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.green.shade800),
                      ),
                      const Text(' | ', style: TextStyle(fontSize: 11, color: Colors.grey)),
                      Text(
                        isUrdu ? 'منتقل شدہ: ' : 'Handed Over: ',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                      ),
                      Text(
                        'Rs. ${totalTr.toStringAsFixed(0)}',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                      ),
                    ],
                  ),
                ],
              ),
              Text(
                'Rs. ${bal.toStringAsFixed(0)}',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 20,
                  color: bal > 0 ? Colors.teal.shade900 : Colors.grey.shade600,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildAdvanceBalanceCard(bool isUrdu) {
    final isAdmin = _isAdminRole;
    final activeAdv = _userAdvanceSummaries[_selectedAdvanceAccountId];
    final bal = (activeAdv?['net_balance'] as num?)?.toDouble() ?? _userAdvanceBalance;
    final totalRec = (activeAdv?['total_received'] as num?)?.toDouble() ?? _totalOverallAdvanceReceived;
    final totalDed = (activeAdv?['total_deducted'] as num?)?.toDouble() ?? _totalOverallAdvanceDeducted;
    final userLabel = activeAdv?['user_label']?.toString() ?? currentUserName.value ?? (isUrdu ? 'کوئی نہیں' : 'None');

    if (!isAdmin) {
      return Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border(right: BorderSide(color: Colors.teal.shade600, width: 4)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.04),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.teal.shade50,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: Colors.teal.shade200),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.account_balance_wallet, size: 16, color: Colors.teal.shade800),
                      const SizedBox(width: 4),
                      Text(
                        isUrdu ? 'خرچ کھاتہ برائے: $userLabel' : 'Expense Account: $userLabel',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.teal.shade900),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: bal >= 0 ? Colors.green.shade50 : Colors.red.shade50,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: bal >= 0 ? Colors.green.shade300 : Colors.red.shade300),
                  ),
                  child: Text(
                    bal >= 0
                        ? (isUrdu ? 'بقایا رقم موجود ہے' : 'Balance Available')
                        : (isUrdu ? 'صارف کا ادارہ پر واجب الادا ہے' : 'Institution Owes User'),
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: bal >= 0 ? Colors.green.shade800 : Colors.red.shade800,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isUrdu ? 'آپ کا بقایا بیلنس:' : 'Your Balance:',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.black87),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        Text(
                          isUrdu ? 'کل موصولہ: ' : 'Received: ',
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                        ),
                        Text(
                          'Rs. ${totalRec.toStringAsFixed(0)}',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                        ),
                        const Text(' | ', style: TextStyle(fontSize: 11, color: Colors.grey)),
                        Text(
                          isUrdu ? 'منہا خرچ: ' : 'Deducted: ',
                          style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                        ),
                        Text(
                          'Rs. ${totalDed.toStringAsFixed(0)}',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.red.shade800),
                        ),
                      ],
                    ),
                  ],
                ),
                Text(
                  '${bal < 0 ? '-' : '+'} Rs. ${bal.abs().toStringAsFixed(0)}',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 20,
                    color: bal >= 0 ? Colors.green.shade800 : Colors.red.shade800,
                  ),
                ),
              ],
            ),
          ],
        ),
      );
    }

    // Admin view
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border(right: BorderSide(color: Colors.amber.shade700, width: 4)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      isUrdu ? 'صارف خرچ کھاتہ:' : 'User Expense Account:',
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                    ),
                  ),
                  const SizedBox(width: 8),
                  if (_userAdvanceSummaries.isNotEmpty)
                    DropdownButton<int>(
                      value: _selectedAdvanceAccountId,
                      isDense: true,
                      underline: const SizedBox(),
                      icon: const Icon(Icons.arrow_drop_down, color: Colors.black87),
                      items: _userAdvanceSummaries.entries.map((e) {
                        final aid = e.key;
                        final s = e.value;
                        final uB = (s['net_balance'] as num?)?.toDouble() ?? 0.0;
                        final uLbl = s['user_label']?.toString() ?? '';
                        return DropdownMenuItem<int>(
                          value: aid,
                          child: Text(
                            '$uLbl (${uB < 0 ? '-' : '+'}Rs. ${uB.abs().toStringAsFixed(0)})',
                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                          ),
                        );
                      }).toList(),
                      onChanged: (newAid) {
                        if (newAid != null && _userAdvanceSummaries.containsKey(newAid)) {
                          setState(() {
                            _selectedAdvanceAccountId = newAid;
                            _userAdvanceBalance = (_userAdvanceSummaries[newAid]!['net_balance'] as num?)?.toDouble() ?? 0.0;
                          });
                        }
                      },
                    ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: bal >= 0 ? Colors.green.shade50 : Colors.red.shade50,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: bal >= 0 ? Colors.green.shade300 : Colors.red.shade300),
                ),
                child: Text(
                  bal >= 0
                      ? (isUrdu ? 'صارف کے پاس بقایا ہے' : 'User has balance')
                      : (isUrdu ? 'صارف نے جیب سے زیادہ خرچ کیا' : 'Overspent'),
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: bal >= 0 ? Colors.green.shade800 : Colors.red.shade800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            isUrdu
                ? 'مجموعی جاری: Rs. ${_totalOverallAdvanceReceived.toStringAsFixed(0)} | کل منہا: Rs. ${_totalOverallAdvanceDeducted.toStringAsFixed(0)} | مجموعی بقایا: Rs. ${_totalOverallAdvanceBalance.toStringAsFixed(0)}'
                : 'Total Issued: Rs. ${_totalOverallAdvanceReceived.toStringAsFixed(0)} | Deducted: Rs. ${_totalOverallAdvanceDeducted.toStringAsFixed(0)} | Net: Rs. ${_totalOverallAdvanceBalance.toStringAsFixed(0)}',
            style: TextStyle(fontSize: 10, color: Colors.grey.shade600),
          ),
          const Divider(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    isUrdu ? 'خرچ کھاتہ برائے: $userLabel' : 'Expense Account: $userLabel',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.black87),
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Text(
                        isUrdu ? 'کل موصولہ ٹرانسفر: ' : 'Total Transfer: ',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                      ),
                      Text(
                        'Rs. ${totalRec.toStringAsFixed(0)}',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                      ),
                      const Text(' | ', style: TextStyle(fontSize: 11, color: Colors.grey)),
                      Text(
                        isUrdu ? 'منہا اخراجات: ' : 'Deducted Exp: ',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                      ),
                      Text(
                        'Rs. ${totalDed.toStringAsFixed(0)}',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.red.shade800),
                      ),
                    ],
                  ),
                ],
              ),
              Text(
                '${bal < 0 ? '-' : '+'} Rs. ${bal.abs().toStringAsFixed(0)}',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 20,
                  color: bal >= 0 ? Colors.green.shade800 : Colors.red.shade800,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  List<DropdownMenuItem<String>> _buildTransferDropdownItems() {
    final items = <DropdownMenuItem<String>>[];
    final isAdmin = _isAdminRole;

    for (final acc in _assetAccounts) {
      final id = acc['id']?.toString() ?? '';
      final name = acc['name']?.toString() ?? '';
      final code = acc['code']?.toString() ?? '';
      if (id.isNotEmpty) {
        if (!isAdmin && (_isOtherUserAdvanceAccount(name) || _isOtherUserCollectionAccount(name))) {
          continue;
        }
        String prefix = '';
        if (name.contains('وصولی') || name.contains('Collection')) {
          prefix = isUrduGlobal.value ? '[نقد وصولی امانت] ' : '[Collection] ';
        } else if (name.contains('ایڈوانس') || name.contains('Advance')) {
          prefix = isUrduGlobal.value ? '[ایڈوانس کھاتہ] ' : '[Advance] ';
        }
        items.add(
          DropdownMenuItem<String>(
            value: 'acc_$id',
            child: Text(
              '$prefix$name ($code)',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
            ),
          ),
        );
      }
    }

    for (final t in _teachersList) {
      final tName = t['name']?.toString().trim() ?? '';
      if (tName.isNotEmpty) {
        if (!isAdmin && !_isMyTeacherRecord(t)) {
          continue;
        }
        final val = 'user_$tName';
        if (!items.any((it) => it.value == val)) {
          items.add(
            DropdownMenuItem<String>(
              value: val,
              child: Text(
                '${isUrduGlobal.value ? "عملہ" : "Staff"}: $tName',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: Colors.blueGrey.shade800,
                ),
              ),
            ),
          );
        }
      }
    }

    final myUname = currentUserName.value?.trim() ?? '';
    if (myUname.isNotEmpty) {
      final myVal = 'user_$myUname';
      if (!items.any((it) => it.value == myVal)) {
        items.add(
          DropdownMenuItem<String>(
            value: myVal,
            child: Text(
              '${isUrduGlobal.value ? "میرا کھاتہ" : "My Account"}: $myUname',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                color: Colors.teal,
              ),
            ),
          ),
        );
      }
    }

    if (isAdmin) {
      for (final uName in _otherUsersList) {
        if (uName.isNotEmpty && uName.toLowerCase() != myUname.toLowerCase()) {
          final val = 'user_$uName';
          if (!items.any((it) => it.value == val)) {
            items.add(
              DropdownMenuItem<String>(
                value: val,
                child: Text(
                  '${isUrduGlobal.value ? "صارف / ایڈمن" : "User / Admin"}: $uName',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: Colors.indigo.shade800,
                  ),
                ),
              ),
            );
          }
        }
      }
    }

    return items;
  }

  Future<void> _submitTransfer() async {
    if (_isTransferLoading) return;

    final amountText = _transferAmountController.text.trim();
    final amount = double.tryParse(amountText) ?? 0.0;
    if (amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'براہ کرم درست رقم درج کریں۔'
              : 'Please enter a valid amount.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }
    if (_selectedFromOption == null || _selectedToOption == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'بھیجنے اور وصول کرنے والے دونوں کھاتے منتخب کریں۔'
              : 'Please select both sender and receiver accounts.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }
    if (_selectedFromOption == _selectedToOption) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'بھیجنے اور وصول کرنے والا کھاتہ ایک جیسا نہیں ہو سکتا۔'
              : 'Sender and receiver accounts cannot be the same.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _isTransferLoading = true);
    try {
      Future<int> resolveAccountId(String option) async {
        if (option.startsWith('acc_')) {
          return int.tryParse(option.substring(4)) ?? 0;
        } else if (option.startsWith('user_')) {
          final uname = option.substring(5);
          return await DBHelper().ensureUserAdvanceAccount(uname);
        }
        return 0;
      }

      final fromAccId = await resolveAccountId(_selectedFromOption!);
      final toAccId = await resolveAccountId(_selectedToOption!);

      if (fromAccId <= 0 || toAccId <= 0 || fromAccId == toAccId) {
        throw Exception(isUrduGlobal.value
            ? 'کھاتوں کے انتخاب میں خرابی ہے۔'
            : 'Invalid accounts specified.');
      }

      if (currentUserRole.value != 'admin') {
        final db = await DBHelper().database;
        final fRows = await db.query('acc_chart_of_accounts', columns: ['name'], where: 'id = ?', whereArgs: [fromAccId]);
        final tRows = await db.query('acc_chart_of_accounts', columns: ['name'], where: 'id = ?', whereArgs: [toAccId]);
        final fName = fRows.isNotEmpty ? (fRows.first['name']?.toString() ?? '') : '';
        final tName = tRows.isNotEmpty ? (tRows.first['name']?.toString() ?? '') : '';
        if (_isOtherUserAdvanceAccount(fName) || _isOtherUserAdvanceAccount(tName) ||
            _isOtherUserCollectionAccount(fName) || _isOtherUserCollectionAccount(tName)) {
          throw Exception(isUrduGlobal.value
              ? 'آپ کو دوسرے صارف کے کھاتے سے لین دین کا اختیار نہیں ہے۔'
              : 'Unauthorized transfer with another user account.');
        }
      }

      final sys = SystemTypeGlobal.currentSystemType.value;
      final date = _transferDateController.text.trim().isNotEmpty
          ? _transferDateController.text.trim()
          : _formatDate(DateTime.now());
      final desc = _transferDescController.text.trim();

      await DBHelper().transferFund(
        fromAccountId: fromAccId,
        toAccountId: toAccId,
        amount: amount,
        date: date,
        description: desc,
        userId: currentUserId.value ?? 1,
        systemType: sys,
      );

      _transferAmountController.clear();
      _transferDescController.clear();
      await _loadTransfers();
      await _loadAdvanceBalance();
      await _loadCollectionBalance();
      await _loadTransferMetadata();

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'رقم کامیابی سے منتقل ہو گئی اور واؤچر بن گیا۔'
              : 'Fund transferred successfully.'),
          backgroundColor: Colors.green,
          duration: const Duration(seconds: 2),
        ),
      );

      // Offline-first: Run two-way sync in background without blocking
      SyncService.runTwoWaySync().then((_) {
        if (mounted) {
          _loadTransfers();
          _loadAdvanceBalance();
          _loadCollectionBalance();
        }
      }).catchError((e) {
        debugPrint('Transfer background sync error: $e');
      });

    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
              '${isUrduGlobal.value ? "منتقلی میں خرابی: " : "Transfer failed: "}$e'),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) setState(() => _isTransferLoading = false);
    }
  }

  Future<void> _showEditTransferDialog(Map<String, dynamic> tr) async {
    final dateController =
        TextEditingController(text: tr['date']?.toString() ?? '');
    final amountController =
        TextEditingController(text: tr['amount']?.toString() ?? '');
    final descController =
        TextEditingController(text: tr['description']?.toString() ?? '');
    final voucherId = int.tryParse(tr['id']?.toString() ?? '') ?? 0;

    await showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              const Icon(Icons.edit, color: Colors.orange),
              const SizedBox(width: 8),
              Text(
                isUrduGlobal.value
                    ? 'ترمیم رقم منتقلی'
                    : 'Edit Fund Transfer',
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: dateController,
                  readOnly: true,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value ? 'تاریخ' : 'Date',
                    suffixIcon: const Icon(Icons.calendar_today),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                  onTap: () async {
                    final picked = await showDatePicker(
                      context: ctx,
                      initialDate: _parseFinanceDate(dateController.text),
                      firstDate: DateTime(2000),
                      lastDate: DateTime(2100),
                    );
                    if (picked != null) {
                      dateController.text = _formatDate(picked);
                    }
                  },
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: amountController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value ? 'رقم (Rs.)' : 'Amount (Rs.)',
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: descController,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? 'تفصیل / وجہ منتقلی'
                        : 'Description',
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child:
                  Text(isUrduGlobal.value ? 'منسوخ' : 'Cancel'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.orange.shade800,
                foregroundColor: Colors.white,
              ),
              onPressed: () async {
                final amt =
                    double.tryParse(amountController.text.trim()) ?? 0.0;
                if (amt <= 0) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(isUrduGlobal.value
                          ? 'درست رقم درج کریں۔'
                          : 'Enter a valid amount.'),
                      backgroundColor: Colors.red,
                    ),
                  );
                  return;
                }
                Navigator.pop(ctx);
                try {
                  await DBHelper().updateFundTransfer(
                    voucherId: voucherId,
                    amount: amt,
                    date: dateController.text.trim(),
                    description: descController.text.trim(),
                  );
                  await _loadTransfers();
                  await _loadAdvanceBalance();
                  await _loadCollectionBalance();
                  if (!mounted) return;
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(isUrduGlobal.value
                          ? 'منتقلی ریکارڈ کامیابی سے اپ ڈیٹ ہو گیا۔'
                          : 'Transfer updated successfully.'),
                      backgroundColor: Colors.green,
                      duration: const Duration(seconds: 2),
                    ),
                  );
                  // Offline-first: Run two-way sync in background
                  SyncService.runTwoWaySync().then((_) {
                    if (mounted) {
                      _loadTransfers();
                      _loadAdvanceBalance();
                      _loadCollectionBalance();
                    }
                  }).catchError((_) {});
                } catch (e) {
                  if (!mounted) return;
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(
                          '${isUrduGlobal.value ? "اپ ڈیٹ میں خرابی: " : "Update error: "}$e'),
                      backgroundColor: Colors.red,
                    ),
                  );
                }
              },
              child: Text(isUrduGlobal.value ? 'محفوظ کریں' : 'Save'),
            ),
          ],
        );
      },
    );
  }

  Future<void> _deleteTransferDialog(Map<String, dynamic> tr) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'تائید' : 'Confirm'),
        content: Text(isUrduGlobal.value
            ? 'کیا آپ واقعی یہ رقم منتقلی واؤچر ڈیلیٹ کرنا چاہتے ہیں؟'
            : 'Do you want to delete this fund transfer?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(isUrduGlobal.value ? 'نہیں' : 'No'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red.shade800,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isUrduGlobal.value ? 'ہاں' : 'Yes'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final voucherId = int.tryParse(tr['id']?.toString() ?? '') ?? 0;
    if (voucherId <= 0) return;

    try {
      await DBHelper().deleteFundTransfer(voucherId);
      await _loadTransfers();
      await _loadAdvanceBalance();
      await _loadCollectionBalance();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(isUrduGlobal.value
              ? 'رقم منتقلی ریکارڈ ڈیلیٹ کر دیا گیا۔'
              : 'Transfer record deleted.'),
          duration: const Duration(seconds: 2),
        ),
      );
      // Offline-first: Run two-way sync in background
      SyncService.runTwoWaySync().then((_) {
        if (mounted) {
          _loadTransfers();
          _loadAdvanceBalance();
          _loadCollectionBalance();
        }
      }).catchError((_) {});
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
              '${isUrduGlobal.value ? "ڈیلیٹ میں خرابی: " : "Delete error: "}$e'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  Widget _buildTransferForm(bool isUrdu) {
    final dropdownItems = _buildTransferDropdownItems();

    return Card(
      elevation: 3,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.swap_horiz, color: Colors.orange.shade800, size: 24),
                const SizedBox(width: 8),
                Text(
                  isUrdu
                      ? 'رقم منتقلی (ایڈوانس / فنڈ ٹرانسفر)'
                      : 'Fund Transfer / Advance',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    color: Colors.orange.shade900,
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            InkWell(
              onTap: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _parseFinanceDate(_transferDateController.text),
                  firstDate: DateTime(2000),
                  lastDate: DateTime(2100),
                );
                if (picked != null) {
                  setState(() {
                    _transferDateController.text = _formatDate(picked);
                  });
                }
              },
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.grey.shade400),
                  borderRadius: BorderRadius.circular(10),
                  color: Colors.white,
                ),
                child: Row(
                  children: [
                    const Icon(Icons.calendar_month, color: Colors.blueGrey),
                    const SizedBox(width: 10),
                    Text(
                      '${isUrdu ? "تاریخ" : "Date"}: ${_transferDateController.text}',
                      style: const TextStyle(
                          fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    const Spacer(),
                    const Icon(Icons.arrow_drop_down, color: Colors.grey),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 14),

            DropdownButtonFormField<String>(
              value: _selectedFromOption,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: isUrdu
                    ? 'کہاں سے رقم نکلی؟ (Sender / From)'
                    : 'From Account (Sender)',
                prefixIcon: const Icon(Icons.outbox, color: Colors.red),
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10)),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
              ),
              hint: Text(isUrdu
                  ? 'بھیجنے والا کھاتہ منتخب کریں'
                  : 'Select From Account'),
              items: dropdownItems,
              onChanged: (v) => setState(() => _selectedFromOption = v),
            ),
            const SizedBox(height: 14),

            DropdownButtonFormField<String>(
              value: _selectedToOption,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: isUrdu
                    ? 'کس کو رقم ملی؟ (Receiver / To)'
                    : 'To Account (Receiver)',
                prefixIcon:
                    const Icon(Icons.move_to_inbox, color: Colors.green),
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10)),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
              ),
              hint: Text(isUrdu
                  ? 'وصول کرنے والا کھاتہ منتخب کریں'
                  : 'Select To Account'),
              items: dropdownItems,
              onChanged: (v) => setState(() => _selectedToOption = v),
            ),
            const SizedBox(height: 14),

            TextField(
              controller: _transferAmountController,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: isUrdu ? 'رقم (Rs.)' : 'Amount (Rs.)',
                prefixIcon: const Icon(Icons.payments, color: Colors.orange),
                hintText: isUrdu ? 'مثلاً: 25000' : 'e.g. 25000',
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10)),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
              ),
            ),
            const SizedBox(height: 14),

            TextField(
              controller: _transferDescController,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: isUrdu
                    ? 'تفصیل / وجہ منتقلی (Description)'
                    : 'Description / Note',
                prefixIcon:
                    const Icon(Icons.description, color: Colors.blueGrey),
                hintText: isUrdu
                    ? 'مثلاً: کیش بکس سے قاری صاحب کو ایڈوانس دیا گیا'
                    : 'e.g. Advance paid to staff',
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10)),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
              ),
            ),
            const SizedBox(height: 16),

            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.blue.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.blue.shade200),
              ),
              child: Row(
                children: [
                  Icon(Icons.info_outline,
                      color: Colors.blue.shade800, size: 20),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      isUrdu
                          ? 'یہ اندراج دونوں کھاتوں کو خودکار متوازن رکھے گا اور آمدن یا خرچ پر غلط اثر نہیں ڈالے گا۔'
                          : 'This transfer keeps accounts balanced automatically without distorting income or expenses.',
                      style:
                          TextStyle(fontSize: 11, color: Colors.blue.shade900),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            SizedBox(
              height: 52,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.orange.shade800,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: _isTransferLoading ? null : _submitTransfer,
                icon: _isTransferLoading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.check_circle, color: Colors.white),
                label: Text(
                  isUrdu ? 'رقم منتقل کریں' : 'Transfer Funds',
                  style: const TextStyle(
                      fontSize: 16, fontWeight: FontWeight.bold),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTransferHistory(bool isUrdu) {
    return Card(
      elevation: 3,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            decoration: BoxDecoration(
              color: Colors.orange.shade800,
              borderRadius:
                  const BorderRadius.vertical(top: Radius.circular(12)),
            ),
            child: Row(
              children: [
                const Icon(Icons.history, color: Colors.white, size: 20),
                const SizedBox(width: 8),
                Text(
                  isUrdu
                      ? 'حالیہ رقم منتقلی ہسٹری (${_transferRecords.length})'
                      : 'Recent Transfer History (${_transferRecords.length})',
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 15,
                  ),
                ),
              ],
            ),
          ),
          if (_transferRecords.isEmpty)
            Padding(
              padding: const EdgeInsets.all(24),
              child: Center(
                child: Text(
                  isUrdu
                      ? 'کوئی رقم منتقلی ریکارڈ موجود نہیں ہے۔'
                      : 'No transfer records found.',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                ),
              ),
            )
          else
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: _transferRecords.length,
              separatorBuilder: (_, __) =>
                  Divider(height: 1, color: Colors.grey.shade200),
              itemBuilder: (ctx, i) {
                final tr = _transferRecords[i];
                final fromAcc = tr['from_account']?.toString() ?? '---';
                final toAcc = tr['to_account']?.toString() ?? '---';
                final amt = tr['amount']?.toString() ?? '0';
                final date = tr['date']?.toString() ?? '---';
                final voucherNo = tr['voucher_no']?.toString() ?? '';
                final desc = tr['description']?.toString() ?? '';
                final canManage = _isAdminRole ||
                    tr['created_by'] == currentUserId.value;

                return Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: Colors.orange.shade50,
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(
                                      color: Colors.orange.shade200),
                                ),
                                child: Text(
                                  voucherNo.isNotEmpty ? voucherNo : 'Contra',
                                  style: TextStyle(
                                    color: Colors.orange.shade900,
                                    fontWeight: FontWeight.bold,
                                    fontSize: 11,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Text(
                                date,
                                style: TextStyle(
                                    color: Colors.grey.shade600, fontSize: 12),
                              ),
                            ],
                          ),
                          Text(
                            'Rs. $amt',
                            style: TextStyle(
                              color: Colors.orange.shade900,
                              fontWeight: FontWeight.bold,
                              fontSize: 16,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Expanded(
                            child: Row(
                              children: [
                                const Icon(Icons.arrow_upward,
                                    color: Colors.red, size: 16),
                                const SizedBox(width: 4),
                                Expanded(
                                  child: Text(
                                    fromAcc,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 13),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const Icon(Icons.arrow_forward,
                              color: Colors.grey, size: 16),
                          Expanded(
                            child: Row(
                              children: [
                                const Icon(Icons.arrow_downward,
                                    color: Colors.green, size: 16),
                                const SizedBox(width: 4),
                                Expanded(
                                  child: Text(
                                    toAcc,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 13),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      if (desc.isNotEmpty) ...[
                        const SizedBox(height: 6),
                        Text(
                          desc,
                          style: TextStyle(
                              color: Colors.grey.shade700, fontSize: 12),
                        ),
                      ],
                      if (canManage) ...[
                        const SizedBox(height: 6),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.end,
                          children: [
                            InkWell(
                              onTap: () => _showEditTransferDialog(tr),
                              child: Row(
                                children: [
                                  Icon(Icons.edit,
                                      size: 16, color: primaryGreen),
                                  const SizedBox(width: 4),
                                  Text(
                                    isUrdu ? 'ترمیم' : 'Edit',
                                    style: TextStyle(
                                        fontSize: 12,
                                        color: primaryGreen,
                                        fontWeight: FontWeight.bold),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 16),
                            InkWell(
                              onTap: () => _deleteTransferDialog(tr),
                              child: Row(
                                children: [
                                  const Icon(Icons.delete,
                                      size: 16, color: Colors.red),
                                  const SizedBox(width: 4),
                                  Text(
                                    isUrdu ? 'ڈیلیٹ' : 'Delete',
                                    style: const TextStyle(
                                        fontSize: 12,
                                        color: Colors.red,
                                        fontWeight: FontWeight.bold),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
        ],
      ),
    );
  }

  void _openCalculatorDialog(TextEditingController targetController) {
    showDialog(
      context: context,
      builder: (ctx) {
        String display = targetController.text.trim().isNotEmpty
            ? targetController.text.trim()
            : '0';
        double? firstOperand;
        String? operator;

        return StatefulBuilder(
          builder: (context, setCalcState) {
            void onDigitPress(String digit) {
              setCalcState(() {
                if (display == '0' && digit != '.') {
                  display = digit;
                } else {
                  display += digit;
                }
              });
            }

            void onOperatorPress(String op) {
              setCalcState(() {
                firstOperand = double.tryParse(display);
                operator = op;
                display = '0';
              });
            }

            void onEquals() {
              if (firstOperand == null || operator == null) return;
              final secondOperand = double.tryParse(display) ?? 0;
              double result = 0;
              switch (operator) {
                case '+':
                  result = firstOperand! + secondOperand;
                  break;
                case '-':
                  result = firstOperand! - secondOperand;
                  break;
                case '×':
                  result = firstOperand! * secondOperand;
                  break;
                case '÷':
                  result = (secondOperand != 0) ? (firstOperand! / secondOperand) : 0;
                  break;
              }
              setCalcState(() {
                display = (result % 1 == 0)
                    ? result.toInt().toString()
                    : result.toStringAsFixed(2);
                firstOperand = null;
                operator = null;
              });
            }

            void onClear() {
              setCalcState(() {
                display = '0';
                firstOperand = null;
                operator = null;
              });
            }

            void onBackspace() {
              setCalcState(() {
                if (display.length > 1) {
                  display = display.substring(0, display.length - 1);
                } else {
                  display = '0';
                }
              });
            }

            Widget calcButton(String text, {Color? bg, Color? fg, VoidCallback? onTap, int flex = 1}) {
              return Expanded(
                flex: flex,
                child: Padding(
                  padding: const EdgeInsets.all(4.0),
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: bg ?? Colors.grey.shade100,
                      foregroundColor: fg ?? Colors.black87,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      elevation: 1,
                    ),
                    onPressed: onTap,
                    child: Text(text, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  ),
                ),
              );
            }

            return AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              titlePadding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              contentPadding: const EdgeInsets.all(12),
              title: Row(
                children: [
                  Icon(Icons.calculate, color: primaryGreen),
                  const SizedBox(width: 8),
                  Text(
                    isUrduGlobal.value ? "حساب کتاب / کیلکولیٹر" : "Calculator",
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close, size: 20),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              content: Focus(
                autofocus: true,
                onKeyEvent: (node, event) {
                  if (event is! KeyDownEvent) return KeyEventResult.ignored;
                  final key = event.logicalKey;
                  // Digits (NumPad + Row)
                  if (key == LogicalKeyboardKey.digit0 || key == LogicalKeyboardKey.numpad0) {
                    onDigitPress('0');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit1 || key == LogicalKeyboardKey.numpad1) {
                    onDigitPress('1');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit2 || key == LogicalKeyboardKey.numpad2) {
                    onDigitPress('2');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit3 || key == LogicalKeyboardKey.numpad3) {
                    onDigitPress('3');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit4 || key == LogicalKeyboardKey.numpad4) {
                    onDigitPress('4');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit5 || key == LogicalKeyboardKey.numpad5) {
                    onDigitPress('5');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit6 || key == LogicalKeyboardKey.numpad6) {
                    onDigitPress('6');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit7 || key == LogicalKeyboardKey.numpad7) {
                    onDigitPress('7');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit8 || key == LogicalKeyboardKey.numpad8) {
                    onDigitPress('8');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.digit9 || key == LogicalKeyboardKey.numpad9) {
                    onDigitPress('9');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.period || key == LogicalKeyboardKey.numpadDecimal) {
                    onDigitPress('.');
                    return KeyEventResult.handled;
                  }
                  // Operators
                  if (key == LogicalKeyboardKey.add || key == LogicalKeyboardKey.numpadAdd) {
                    onOperatorPress('+');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.minus || key == LogicalKeyboardKey.numpadSubtract) {
                    onOperatorPress('-');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.asterisk || key == LogicalKeyboardKey.numpadMultiply) {
                    onOperatorPress('×');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.slash || key == LogicalKeyboardKey.numpadDivide) {
                    onOperatorPress('÷');
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.equal || key == LogicalKeyboardKey.numpadEqual) {
                    onEquals();
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.enter || key == LogicalKeyboardKey.numpadEnter) {
                    if (operator != null) {
                      onEquals();
                    } else {
                      targetController.text = display;
                      Navigator.pop(ctx);
                    }
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.backspace) {
                    onBackspace();
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.delete || key == LogicalKeyboardKey.keyC) {
                    onClear();
                    return KeyEventResult.handled;
                  }
                  if (key == LogicalKeyboardKey.escape) {
                    Navigator.pop(ctx);
                    return KeyEventResult.handled;
                  }
                  // Fallback by character
                  final char = event.character;
                  if (char != null) {
                    if ('0123456789'.contains(char)) {
                      onDigitPress(char);
                      return KeyEventResult.handled;
                    }
                    if (char == '.') {
                      onDigitPress('.');
                      return KeyEventResult.handled;
                    }
                    if (char == '+') {
                      onOperatorPress('+');
                      return KeyEventResult.handled;
                    }
                    if (char == '-') {
                      onOperatorPress('-');
                      return KeyEventResult.handled;
                    }
                    if (char == '*' || char == 'x' || char == 'X') {
                      onOperatorPress('×');
                      return KeyEventResult.handled;
                    }
                    if (char == '/') {
                      onOperatorPress('÷');
                      return KeyEventResult.handled;
                    }
                    if (char == '=') {
                      onEquals();
                      return KeyEventResult.handled;
                    }
                    if (char == 'c' || char == 'C') {
                      onClear();
                      return KeyEventResult.handled;
                    }
                  }
                  return KeyEventResult.ignored;
                },
                child: SizedBox(
                  width: 320,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      // Display screen
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade900,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        alignment: Alignment.centerRight,
                        child: Text(
                          display,
                          style: const TextStyle(
                            color: Colors.greenAccent,
                            fontSize: 26,
                            fontWeight: FontWeight.bold,
                            letterSpacing: 1.2,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      const SizedBox(height: 12),
                      // Keypad
                      Row(
                        children: [
                          calcButton('C', bg: Colors.red.shade100, fg: Colors.red.shade900, onTap: onClear),
                          calcButton('⌫', bg: Colors.orange.shade100, fg: Colors.orange.shade900, onTap: onBackspace),
                          calcButton('÷', bg: Colors.blue.shade100, fg: Colors.blue.shade900, onTap: () => onOperatorPress('÷')),
                          calcButton('×', bg: Colors.blue.shade100, fg: Colors.blue.shade900, onTap: () => onOperatorPress('×')),
                        ],
                      ),
                      Row(
                        children: [
                          calcButton('7', onTap: () => onDigitPress('7')),
                          calcButton('8', onTap: () => onDigitPress('8')),
                          calcButton('9', onTap: () => onDigitPress('9')),
                          calcButton('-', bg: Colors.blue.shade100, fg: Colors.blue.shade900, onTap: () => onOperatorPress('-')),
                        ],
                      ),
                      Row(
                        children: [
                          calcButton('4', onTap: () => onDigitPress('4')),
                          calcButton('5', onTap: () => onDigitPress('5')),
                          calcButton('6', onTap: () => onDigitPress('6')),
                          calcButton('+', bg: Colors.blue.shade100, fg: Colors.blue.shade900, onTap: () => onOperatorPress('+')),
                        ],
                      ),
                      Row(
                        children: [
                          calcButton('1', onTap: () => onDigitPress('1')),
                          calcButton('2', onTap: () => onDigitPress('2')),
                          calcButton('3', onTap: () => onDigitPress('3')),
                          calcButton('=', bg: primaryGreen, fg: Colors.white, onTap: onEquals),
                        ],
                      ),
                      Row(
                        children: [
                          calcButton('0', flex: 2, onTap: () => onDigitPress('0')),
                          calcButton('.', onTap: () => onDigitPress('.')),
                          calcButton('00', onTap: () => onDigitPress('00')),
                        ],
                      ),
                      const SizedBox(height: 12),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          ),
                          icon: const Icon(Icons.check_circle_outline),
                          label: Text(
                            isUrduGlobal.value ? "یہ رقم درج کریں" : "Apply Amount",
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                          ),
                          onPressed: () {
                            targetController.text = display;
                            Navigator.pop(ctx);
                          },
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }

  void _showHeadPickerModal(TextEditingController controller) {
    final heads = currentHeads;
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return Container(
          padding: const EdgeInsets.all(16),
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(ctx).size.height * 0.6,
          ),
          child: Column(
            children: [
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey.shade400,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                isUrduGlobal.value ? 'مد منتخب کریں' : 'Select Head',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
              const SizedBox(height: 12),
              Expanded(
                child: ListView.separated(
                  itemCount: heads.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final h = heads[i];
                    return ListTile(
                      leading: Icon(Icons.label_outline, color: primaryGreen),
                      title: Text(h, style: const TextStyle(fontWeight: FontWeight.w600)),
                      trailing: selectedHead == h
                          ? Icon(Icons.check_circle, color: primaryGreen)
                          : null,
                      onTap: () {
                        if (!isIncome && _isSalaryHead(h)) {
                          Navigator.pop(ctx);
                          _showSalaryRestrictionNotice(controller: controller);
                          return;
                        }
                        setState(() {
                          selectedHead = h;
                          controller.text = h;
                        });
                        Navigator.pop(ctx);
                      },
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Future<String> _fetchNextReceiptNumber() async {
    try {
      final db = await DBHelper().database;
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      final table = isMasjid ? 'masjid_finance' : 'finance';
      int userId = currentUserId.value ?? 1;
      final int minNo;
      final int maxLimit;
      if (userId == 1) {
        minNo = 300000;
        maxLimit = 400000;
      } else if (userId == 3) {
        minNo = 700000;
        maxLimit = 800000;
      } else {
        minNo = userId * 100000;
        maxLimit = (userId + 1) * 100000;
      }
      // Self-healing: Ensure deleted records never hold receipt numbers
      await db.rawUpdate(
          'UPDATE $table SET computer_receipt_no = NULL WHERE (is_deleted = 1) AND computer_receipt_no IS NOT NULL');
      if (userId == 1) {
        final countRes = await db.rawQuery(
            'SELECT COUNT(*) FROM $table WHERE computer_receipt_no > 300040 AND computer_receipt_no < 300057 AND (is_deleted = 0 OR is_deleted IS NULL)');
        final countBetween = (countRes.isNotEmpty && countRes.first.values.isNotEmpty)
            ? (int.tryParse(countRes.first.values.first?.toString() ?? '0') ?? 0)
            : 0;
        if (countBetween == 0) {
          await db.rawUpdate(
              "UPDATE $table SET computer_receipt_no = 300041, receipt_no = '300041' WHERE (user_id = 1 OR user_id IS NULL) AND computer_receipt_no = 300057 AND (is_deleted = 0 OR is_deleted IS NULL)");
        }
      }

      final result = await db.rawQuery(
          'SELECT MAX(computer_receipt_no) as max_no FROM $table WHERE computer_receipt_no >= ? AND computer_receipt_no < ? AND (is_deleted = 0 OR is_deleted IS NULL)',
          [minNo, maxLimit]);
      int maxNo = minNo;
      if (result.isNotEmpty && result.first['max_no'] != null) {
        maxNo = result.first['max_no'] as int;
      }
      final nextNo = maxNo + 1;
      return isMasjid ? 'M-$nextNo' : '$nextNo';
    } catch (_) {
      final isMasjid = SystemTypeGlobal.currentSystemType.value == 'Masjid';
      int userId = currentUserId.value ?? 1;
      int baseMin = (userId == 1) ? 300000 : ((userId == 3) ? 700000 : userId * 100000);
      final nextNo = baseMin + 1;
      return isMasjid ? 'M-$nextNo' : '$nextNo';
    }
  }

  Widget _buildInputs() {
    return Card(
      elevation: 3,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          children: [
            if (isIncome) ...[
              FutureBuilder<String>(
                future: _fetchNextReceiptNumber(),
                builder: (context, snapshot) {
                  final receiptNo = snapshot.data;
                  return Container(
                    margin: const EdgeInsets.only(bottom: 15),
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(
                      color: Colors.green.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.green.shade300, width: 1.2),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Icon(Icons.receipt_long, color: Colors.green.shade800, size: 22),
                            const SizedBox(width: 8),
                            Text(
                              isUrduGlobal.value ? "کمپیوٹرائزڈ رسید نمبر:" : "Computer Receipt No:",
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                                color: Colors.green.shade900,
                              ),
                            ),
                          ],
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: Colors.green.shade700,
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            receiptNo != null
                                ? (receiptNo.startsWith('M-') ? receiptNo : "#$receiptNo")
                                : "...",
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 14,
                              letterSpacing: 1.0,
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ],
            if (_isAdminRole) ...[
              Container(
                margin: const EdgeInsets.only(bottom: 15),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                decoration: BoxDecoration(
                  color: isIncome ? Colors.green.shade50 : Colors.red.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: isIncome ? Colors.green.shade200 : Colors.red.shade200,
                  ),
                ),
                child: DropdownButtonFormField<int>(
                  value: _selectedEntryUserId ?? currentUserId.value ?? 1,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? (isIncome ? 'کس صارف کی آمدن ہے؟ (Record Owner)' : 'کس صارف کا خرچ ہے؟ (Record Owner)')
                        : 'Record Owner User',
                    prefixIcon: Icon(
                      Icons.person_pin,
                      color: isIncome ? Colors.green.shade700 : Colors.red.shade700,
                    ),
                    border: InputBorder.none,
                  ),
                  isExpanded: true,
                  items: _buildUserDropdownItems(),
                  onChanged: (val) {
                    if (val != null) {
                      setState(() {
                        _selectedEntryUserId = val;
                        _selectedEntryUserName = _resolveUserName(val);
                      });
                    }
                  },
                ),
              ),
            ],
            TextField(
              controller: _dateController,
              readOnly: true,
              onTap: _pickEntryDate,
              decoration: InputDecoration(
                labelText: isUrduGlobal.value
                    ? 'تاریخ'
                    : 'Date',
                border: const OutlineInputBorder(),
                prefixIcon: const Icon(Icons.calendar_today),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.edit_calendar),
                  onPressed: _pickEntryDate,
                ),
              ),
            ),
            const SizedBox(height: 15),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Autocomplete<String>(
                    key: _autocompleteKey,
                    initialValue: TextEditingValue(text: selectedHead ?? ''),
                    optionsBuilder: (TextEditingValue textEditingValue) {
                      if (textEditingValue.text.isEmpty) {
                        return currentHeads;
                      }
                      return currentHeads.where((String option) {
                        return option
                            .toLowerCase()
                            .contains(textEditingValue.text.toLowerCase());
                      });
                    },
                    onSelected: (String selection) {
                      if (!isIncome && _isSalaryHead(selection)) {
                        _showSalaryRestrictionNotice();
                        return;
                      }
                      setState(() => selectedHead = selection);
                    },
                    optionsViewBuilder: (BuildContext context,
                        AutocompleteOnSelected<String> onSelected,
                        Iterable<String> options) {
                      return Align(
                        alignment: Alignment.topLeft,
                        child: Material(
                          elevation: 6,
                          borderRadius: BorderRadius.circular(8),
                          color: Colors.white,
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(
                                maxHeight: 220, maxWidth: 300),
                            child: ListView.separated(
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              shrinkWrap: true,
                              itemCount: options.length,
                              separatorBuilder: (_, __) => const Divider(height: 1),
                              itemBuilder: (BuildContext context, int index) {
                                final String option = options.elementAt(index);
                                return InkWell(
                                  onTap: () => onSelected(option),
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 14, vertical: 12),
                                    child: Row(
                                      children: [
                                        Icon(Icons.label_outline,
                                            size: 18, color: primaryGreen),
                                        const SizedBox(width: 8),
                                        Expanded(
                                          child: Text(
                                            option,
                                            style: const TextStyle(
                                              fontSize: 14,
                                              fontWeight: FontWeight.w600,
                                              color: Colors.black87,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                );
                              },
                            ),
                          ),
                        ),
                      );
                    },
                    fieldViewBuilder:
                        (context, controller, focusNode, onEditingComplete) {
                      return TextFormField(
                        controller: controller,
                        focusNode: focusNode,
                        onChanged: (val) {
                          selectedHead = val;
                          if (!isIncome && _isSalaryHead(val)) {
                            _showSalaryRestrictionNotice(controller: controller);
                          }
                        },
                        decoration: InputDecoration(
                          labelText: isUrduGlobal.value
                              ? 'مد (تلاش کریں یا منتخب کریں)'
                              : 'Head (Search or Select)',
                          border: const OutlineInputBorder(),
                          suffixIcon: IconButton(
                            icon: const Icon(Icons.arrow_drop_down_circle_outlined),
                            color: primaryGreen,
                            tooltip: isUrduGlobal.value
                                ? 'مدات کی فہرست'
                                : 'Heads List',
                            onPressed: () => _showHeadPickerModal(controller),
                          ),
                        ),
                      );
                    },
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  height: 56, // Standard TextField height
                  decoration: BoxDecoration(
                      color: Colors.green.shade700,
                      borderRadius: BorderRadius.circular(4)),
                  child: IconButton(
                    icon: const Icon(Icons.add, color: Colors.white),
                    onPressed: _showAddHeadDialog,
                  ),
                ),
              ],
            ),

            const SizedBox(height: 15),

            TextField(
              controller: _amountController,
              readOnly: true,
              onTap: () => _openCalculatorDialog(_amountController),
              decoration: InputDecoration(
                labelText: isUrduGlobal.value ? 'رقم' : 'Amount',
                hintText: isUrduGlobal.value ? 'رقم درج کرنے کے لیے کلک کریں' : 'Click to enter amount',
                border: const OutlineInputBorder(),
                prefixIcon: const Icon(Icons.money),
                suffixIcon: IconButton(
                  icon: Icon(Icons.calculate, color: primaryGreen),
                  tooltip: isUrduGlobal.value ? 'کیلکولیٹر' : 'Calculator',
                  onPressed: () => _openCalculatorDialog(_amountController),
                ),
              ),
            ),

            const SizedBox(height: 15),

            // Account selection (وصولی کھاتہ / ادائیگی کھاتہ)
            Builder(
              builder: (context) {
                final accList = isIncome ? _getIncomeAccounts() : _getExpenseAccounts();
                final defaultId = _getDefaultPaymentAccountId(isExp: !isIncome);
                int? currentVal = _selectedPaymentAccountId;
                if (!isIncome && !_isAdminRole) {
                  if (accList.isNotEmpty && (currentVal == null || !accList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == currentVal)))) {
                    currentVal = defaultId ?? int.tryParse(accList.first['id']?.toString() ?? '');
                  }
                } else if (isIncome && !_isAdminRole) {
                  if (accList.isNotEmpty && (currentVal == null || !accList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == currentVal)))) {
                    currentVal = defaultId ?? int.tryParse(accList.first['id']?.toString() ?? '');
                  }
                } else if (currentVal == null || !accList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == currentVal))) {
                  currentVal = accList.any((a) => (int.tryParse(a['id']?.toString() ?? '') == defaultId))
                      ? defaultId
                      : (accList.isNotEmpty ? int.tryParse(accList.first['id']?.toString() ?? '') : null);
                }
                return DropdownButtonFormField<int>(
                  value: currentVal,
                  decoration: InputDecoration(
                    labelText: isUrduGlobal.value
                        ? (isIncome ? 'کہاں جمع ہوئی؟ (وصولی کھاتہ)' : 'کہاں سے ادا ہوئی؟ (ادائیگی کھاتہ)')
                        : (isIncome ? 'Deposit Account' : 'Payment Account'),
                    prefixIcon: const Icon(Icons.account_balance_wallet, color: Colors.teal),
                    border: const OutlineInputBorder(),
                  ),
                  isExpanded: true,
                  items: accList.map((acc) {
                    final aId = int.tryParse(acc['id']?.toString() ?? '') ?? 0;
                    final aName = acc['name']?.toString() ?? '';
                    final aCode = acc['code']?.toString() ?? '';
                    String extraLabel = '';
                    if (_userAdvanceSummaries.containsKey(aId)) {
                      final advInfo = _userAdvanceSummaries[aId]!;
                      final b = (advInfo['net_balance'] as num?)?.toDouble() ?? 0.0;
                      extraLabel = ' [بقایا ایڈوانس: ${b < 0 ? '-' : '+'}Rs. ${b.abs().toStringAsFixed(0)}]';
                    } else if (_userCollectionSummaries.containsKey(aId)) {
                      final colInfo = _userCollectionSummaries[aId]!;
                      final b = (colInfo['net_in_hand'] as num?)?.toDouble() ?? 0.0;
                      extraLabel = isIncome
                          ? ' [پاس موجود رقم: Rs. ${b.toStringAsFixed(0)}]'
                          : ' [بقایا امانت: Rs. ${b.toStringAsFixed(0)}]';
                    }
                    return DropdownMenuItem<int>(
                      value: aId,
                      child: Text(
                        '$aName ($aCode)$extraLabel',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    );
                  }).toList(),
                  onChanged: (val) {
                    setState(() => _selectedPaymentAccountId = val);
                  },
                );
              },
            ),

            if (isIncome) ...[
              const SizedBox(height: 15),
              TextField(
                controller: _donorController,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value
                      ? 'دینے والے کا نام (عطیہ دہندہ)'
                      : 'Donor Name',
                  prefixIcon: const Icon(Icons.person),
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 15),
              DropdownButtonFormField<String>(
                value: _selectedPaymentMethod,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value
                      ? 'طریقہ وصولی (Method)'
                      : 'Payment Method',
                  prefixIcon: const Icon(Icons.payments, color: Colors.blueGrey),
                  border: const OutlineInputBorder(),
                ),
                items: [
                  DropdownMenuItem(
                    value: 'Cash',
                    child: Text(isUrduGlobal.value ? 'نقد رقم (Cash)' : 'Cash'),
                  ),
                  DropdownMenuItem(
                    value: 'Bank Transfer',
                    child: Text(isUrduGlobal.value ? 'آن لائن بینک ٹرانسفر (Online Transfer)' : 'Bank Transfer'),
                  ),
                  DropdownMenuItem(
                    value: 'Cheque',
                    child: Text(isUrduGlobal.value ? 'چیک (Cheque)' : 'Cheque'),
                  ),
                ],
                onChanged: (val) {
                  if (val != null) setState(() => _selectedPaymentMethod = val);
                },
              ),
              const SizedBox(height: 15),
              TextField(
                controller: _manualReceiptController,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value
                      ? 'دستی رسید نمبر (Manual Receipt)'
                      : 'Manual Receipt No',
                  prefixIcon: const Icon(Icons.receipt),
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 15),
              TextField(
                controller: _addressController,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value ? 'پتہ (Address)' : 'Address',
                  prefixIcon: const Icon(Icons.location_on),
                  border: const OutlineInputBorder(),
                ),
              ),
            ] else ...[
              const SizedBox(height: 15),
              TextField(
                controller: _spenderController,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value
                      ? 'خرچ کرنے والے کا نام (Spender / Payee)'
                      : 'Spender / Payee Name',
                  prefixIcon: const Icon(Icons.person),
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 15),
              DropdownButtonFormField<String>(
                value: _selectedPaymentMethod,
                decoration: InputDecoration(
                  labelText: isUrduGlobal.value
                      ? 'طریقہ ادائیگی (Method)'
                      : 'Payment Method',
                  prefixIcon: const Icon(Icons.payments, color: Colors.blueGrey),
                  border: const OutlineInputBorder(),
                ),
                items: [
                  DropdownMenuItem(
                    value: 'Cash',
                    child: Text(isUrduGlobal.value ? 'نقد رقم (Cash)' : 'Cash'),
                  ),
                  DropdownMenuItem(
                    value: 'Bank Transfer',
                    child: Text(isUrduGlobal.value ? 'آن لائن بینک ٹرانسفر (Online Transfer)' : 'Bank Transfer'),
                  ),
                  DropdownMenuItem(
                    value: 'Cheque',
                    child: Text(isUrduGlobal.value ? 'چیک (Cheque)' : 'Cheque'),
                  ),
                ],
                onChanged: (val) {
                  if (val != null) setState(() => _selectedPaymentMethod = val);
                },
              ),
              const SizedBox(height: 15),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.receipt_long, color: primaryGreen, size: 20),
                        const SizedBox(width: 8),
                        Text(
                          isUrduGlobal.value ? 'خرچ کا بل / رسید (تصویر)' : 'Bill / Receipt Image',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        if (_billImageFile != null || (_billImageString != null && _billImageString!.isNotEmpty)) ...[
                          const Spacer(),
                          IconButton(
                            tooltip: isUrduGlobal.value ? 'تصویر ہٹائیں' : 'Remove Image',
                            icon: const Icon(Icons.cancel, color: Colors.red, size: 20),
                            onPressed: _clearBillImage,
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 8),
                    if (_billImageFile != null || (_billImageString != null && _billImageString!.isNotEmpty)) ...[
                      Center(
                        child: GestureDetector(
                          onTap: () => _showFullBillImage(_billImageFile?.path ?? _billImageString),
                          child: Stack(
                            alignment: Alignment.center,
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: _buildBillImageWidget(
                                  _billImageFile?.path ?? _billImageString!,
                                  width: double.infinity,
                                  height: 140,
                                  fit: BoxFit.cover,
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                decoration: BoxDecoration(
                                  color: Colors.black54,
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const Icon(Icons.fullscreen, color: Colors.white, size: 16),
                                    const SizedBox(width: 4),
                                    Text(
                                      isUrduGlobal.value ? 'بڑا کر کے دیکھیں' : 'Tap to zoom',
                                      style: const TextStyle(color: Colors.white, fontSize: 12),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                    ],
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              side: BorderSide(color: primaryGreen),
                              padding: const EdgeInsets.symmetric(vertical: 10),
                            ),
                            icon: Icon(Icons.camera_alt, color: primaryGreen, size: 18),
                            label: Text(
                              isUrduGlobal.value ? 'کیمرہ' : 'Camera',
                              style: TextStyle(color: primaryGreen, fontSize: 12),
                            ),
                            onPressed: () => _pickBillImage(ImageSource.camera),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              side: BorderSide(color: primaryGreen),
                              padding: const EdgeInsets.symmetric(vertical: 10),
                            ),
                            icon: Icon(Icons.photo_library, color: primaryGreen, size: 18),
                            label: Text(
                              isUrduGlobal.value ? 'گیلری' : 'Gallery',
                              style: TextStyle(color: primaryGreen, fontSize: 12),
                            ),
                            onPressed: () => _pickBillImage(ImageSource.gallery),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: 15),

            TextField(

              controller: _descController,

              maxLines: 2,

              decoration: InputDecoration(

                labelText: isUrduGlobal.value

                    ? '\u062a\u0641\u0635\u06cc\u0644'

                    : 'Details',

                border: const OutlineInputBorder(),

              ),

            ),

          ],

        ),

      ),

    );

  }



  void _showAddHeadDialog() {
    final TextEditingController newHeadCtrl = TextEditingController();
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(isUrduGlobal.value ? 'نئی مد شامل کریں' : 'Add New Head'),
        content: TextField(
          controller: newHeadCtrl,
          decoration: InputDecoration(
            labelText: isUrduGlobal.value ? 'مد کا نام' : 'Head Name',
            border: const OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(isUrduGlobal.value ? 'منسوخ' : 'Cancel'),
          ),
          ElevatedButton(
            onPressed: () async {
              if (newHeadCtrl.text.trim().isNotEmpty) {
                final newHead = newHeadCtrl.text.trim();
                final headType = isIncome ? 'Income' : 'Expense';
                await DBHelper().insertHead({
                  'name': newHead,
                  'type': headType,
                  'is_deleted': 0,
                  'is_synced': 0,
                });
                await _loadHeads();
                if (!mounted) return;
                setState(() {
                  selectedHead = newHead;
                  _autocompleteKey = UniqueKey();
                });
                SyncService.runTwoWaySync().catchError((e) {
                  debugPrint('Auto sync heads error: $e');
                });
              }
              Navigator.pop(context);
            },
            child: Text(isUrduGlobal.value ? 'شامل کریں' : 'Add'),
          ),
        ],
      ),
    );
  }

  Future<void> _pickBillImage(ImageSource source) async {
    try {
      final picked = await _picker.pickImage(source: source, imageQuality: 80);
      if (picked != null) {
        setState(() {
          _billImageFile = File(picked.path);
          _billImageString = picked.path;
        });
      }
    } catch (e) {
      debugPrint('Error picking bill image: $e');
    }
  }

  void _clearBillImage() {
    setState(() {
      _billImageFile = null;
      _billImageString = null;
    });
  }

  void _showFullBillImage(dynamic billSrc) {
    if (billSrc == null || billSrc.toString().trim().isEmpty) return;
    final src = billSrc.toString().trim();
    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        insetPadding: const EdgeInsets.all(12),
        backgroundColor: Colors.black87,
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                minScale: 0.5,
                maxScale: 4.0,
                child: _buildBillImageWidget(src, fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 10,
              right: 10,
              child: CircleAvatar(
                backgroundColor: Colors.black54,
                child: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.pop(ctx),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBillImageWidget(String pathOrUrl, {double? width, double? height, BoxFit fit = BoxFit.cover}) {
    if (pathOrUrl.isEmpty) {
      return const SizedBox.shrink();
    }
    // 1. Local file
    final localFile = File(pathOrUrl);
    if (localFile.existsSync()) {
      return Image.file(localFile, width: width, height: height, fit: fit);
    }
    // 2. Base64
    if (pathOrUrl.startsWith('data:image') || (pathOrUrl.length > 200 && !pathOrUrl.contains('/') && !pathOrUrl.contains('\\'))) {
      try {
        String b64 = pathOrUrl;
        if (b64.contains(',')) {
          b64 = b64.split(',')[1];
        }
        final bytes = base64Decode(b64);
        return Image.memory(bytes, width: width, height: height, fit: fit);
      } catch (e) {
        debugPrint('Error decoding base64 image: $e');
      }
    }
    // 3. Remote URL
    String fullUrl = pathOrUrl;
    if (!fullUrl.startsWith('http://') && !fullUrl.startsWith('https://')) {
      final cleanBase = FinanceOtpService.cleanApiUrl;
      fullUrl = '$cleanBase$pathOrUrl';
    }
    return Image.network(
      fullUrl,
      width: width,
      height: height,
      fit: fit,
      errorBuilder: (ctx, err, stack) => Container(
        width: width,
        height: height,
        color: Colors.grey.shade200,
        alignment: Alignment.center,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.broken_image, color: Colors.grey, size: 36),
            const SizedBox(height: 4),
            Text(
              isUrduGlobal.value ? 'تصویر دستیاب نہیں' : 'Image not available',
              style: const TextStyle(fontSize: 11, color: Colors.grey),
            ),
          ],
        ),
      ),
      loadingBuilder: (ctx, child, progress) {
        if (progress == null) return child;
        return Container(
          width: width,
          height: height,
          color: Colors.grey.shade100,
          alignment: Alignment.center,
          child: const CircularProgressIndicator(strokeWidth: 2),
        );
      },
    );
  }

  Future<void> _fetchAdminOtpsCount() async {
    if (!_isAdminRole) return;
    try {
      final otps = await FinanceOtpService.getAdminPendingOtps();
      if (mounted) {
        setState(() {
          _pendingOtpsCount = otps.length;
        });
      }
    } catch (e) {
      debugPrint('Error fetching admin pending OTPs count: $e');
    }
  }

  void _showAdminPendingOtpsDialog() async {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (modalCtx, setModalState) {
            return FutureBuilder<List<Map<String, dynamic>>>(
              future: FinanceOtpService.getAdminPendingOtps(),
              builder: (context, snapshot) {
                return Container(
                  padding: const EdgeInsets.all(16),
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.75,
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.key, color: primaryGreen),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              isUrduGlobal.value
                                  ? 'ترمیم و حذف کے لیے OTP اجازت نامے'
                                  : 'Edit/Delete OTP Requests',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                                color: primaryGreen,
                              ),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.refresh),
                            onPressed: () => setModalState(() {}),
                          ),
                        ],
                      ),
                      const Divider(),
                      if (snapshot.connectionState == ConnectionState.waiting)
                        const Padding(
                          padding: EdgeInsets.all(32),
                          child: CircularProgressIndicator(),
                        )
                      else if (snapshot.hasError)
                        Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(
                            isUrduGlobal.value
                                ? 'ڈیٹا حاصل کرنے میں خرابی ہوئی'
                                : 'Error loading OTP requests',
                            style: const TextStyle(color: Colors.red),
                          ),
                        )
                      else if (!snapshot.hasData || snapshot.data!.isEmpty)
                        Padding(
                          padding: const EdgeInsets.all(32),
                          child: Column(
                            children: [
                              Icon(Icons.check_circle_outline, size: 48, color: Colors.green.shade600),
                              const SizedBox(height: 12),
                              Text(
                                isUrduGlobal.value
                                    ? 'کوئی زیر التواء OTP درخواست موجود نہیں ہے۔'
                                    : 'No pending OTP requests.',
                                style: const TextStyle(fontSize: 14, color: Colors.grey),
                              ),
                            ],
                          ),
                        )
                      else
                        Flexible(
                          child: ListView.builder(
                            shrinkWrap: true,
                            itemCount: snapshot.data!.length,
                            itemBuilder: (context, index) {
                              final item = snapshot.data![index];
                              final otpCode = item['otp_code']?.toString() ?? '';
                              final reqUser = item['requester_user_name']?.toString() ?? (isUrduGlobal.value ? 'صارف' : 'User');
                              final finId = item['finance_id']?.toString() ?? '';
                              final desc = item['description']?.toString() ?? '';
                              final amount = item['amount']?.toString() ?? '';
                              final sys = item['system_type']?.toString() ?? 'Madrasa';

                              return Card(
                                elevation: 2,
                                margin: const EdgeInsets.symmetric(vertical: 6),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12),
                                  side: BorderSide(color: Colors.amber.shade400, width: 1),
                                ),
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text(
                                            'درخواست کنندہ: $reqUser',
                                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                          ),
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: primaryGreen.withOpacity(0.1),
                                              borderRadius: BorderRadius.circular(6),
                                            ),
                                            child: Text(
                                              sys,
                                              style: TextStyle(fontSize: 10, color: primaryGreen, fontWeight: FontWeight.bold),
                                            ),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        'انٹری #$finId - رقم: Rs. $amount ($desc)',
                                        style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                      const SizedBox(height: 8),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                        decoration: BoxDecoration(
                                          color: Colors.amber.shade50,
                                          borderRadius: BorderRadius.circular(8),
                                          border: Border.all(color: Colors.amber.shade600),
                                        ),
                                        child: Row(
                                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                          children: [
                                            Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  isUrduGlobal.value ? 'تصدیقی OTP کوڈ (24 گھنٹے میعاد):' : 'OTP Code (Valid 24h):',
                                                  style: TextStyle(fontSize: 11, color: Colors.amber.shade900),
                                                ),
                                                Text(
                                                  otpCode,
                                                  style: const TextStyle(
                                                    fontSize: 22,
                                                    fontWeight: FontWeight.w900,
                                                    letterSpacing: 4,
                                                    color: Colors.black87,
                                                  ),
                                                ),
                                              ],
                                            ),
                                            ElevatedButton.icon(
                                              style: ElevatedButton.styleFrom(
                                                backgroundColor: primaryGreen,
                                                foregroundColor: Colors.white,
                                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                              ),
                                              icon: const Icon(Icons.copy, size: 16),
                                              label: Text(
                                                isUrduGlobal.value ? 'کاپی' : 'Copy',
                                                style: const TextStyle(fontSize: 12),
                                              ),
                                              onPressed: () {
                                                Clipboard.setData(ClipboardData(text: otpCode));
                                                ScaffoldMessenger.of(context).showSnackBar(
                                                  SnackBar(
                                                    content: Text(
                                                      isUrduGlobal.value
                                                          ? 'OTP کوڈ $otpCode کاپی کر لیا گیا'
                                                          : 'OTP $otpCode copied!',
                                                    ),
                                                    backgroundColor: Colors.green,
                                                  ),
                                                );
                                              },
                                            ),
                                          ],
                                        ),
                                      ),
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
              },
            );
          },
        );
      },
    ).whenComplete(() {
      _fetchAdminOtpsCount();
    });
  }

  Future<bool> _ensureEditPermission(Map<String, dynamic> row) async {
    if (_isAdminRole) return true;

    final financeId = int.tryParse(row['server_id']?.toString() ?? '') ??
        int.tryParse(row['id']?.toString() ?? '0') ?? 0;

    if (financeId <= 0) {
      return true;
    }

    try {
      final alreadyPermitted = await FinanceOtpService.checkEditPermission(financeId: financeId);
      if (alreadyPermitted) {
        return true;
      }
    } catch (e) {
      debugPrint('Check permission error: $e');
    }

    if (!mounted) return false;

    final bool? granted = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _FinanceOtpDialog(
        financeId: financeId,
        recordSummary: '${row['type'] == 'Income' ? (isUrduGlobal.value ? "آمدن" : "Income") : (isUrduGlobal.value ? "خرچ" : "Expense")} - رقم: Rs. ${row['amount']} (${row['date']})',
        systemType: (row['system_type']?.toString().isNotEmpty == true)
            ? row['system_type'].toString()
            : SystemTypeGlobal.currentSystemType.value,
      ),
    );

    return granted == true;
  }

}

class _FinanceOtpDialog extends StatefulWidget {
  final int financeId;
  final String recordSummary;
  final String systemType;

  const _FinanceOtpDialog({
    required this.financeId,
    required this.recordSummary,
    required this.systemType,
  });

  @override
  State<_FinanceOtpDialog> createState() => _FinanceOtpDialogState();
}

class _FinanceOtpDialogState extends State<_FinanceOtpDialog> {
  final TextEditingController _otpCtrl = TextEditingController();
  bool _isRequesting = false;
  bool _isVerifying = false;
  String? _statusMessage;
  bool _isError = false;
  bool _otpSent = false;

  @override
  void dispose() {
    _otpCtrl.dispose();
    super.dispose();
  }

  Future<void> _sendOtpRequest() async {
    setState(() {
      _isRequesting = true;
      _statusMessage = null;
      _isError = false;
    });

    final res = await FinanceOtpService.requestEditOtp(
      financeId: widget.financeId,
      systemType: widget.systemType,
    );

    if (!mounted) return;
    setState(() {
      _isRequesting = false;
      if (res['success'] == true) {
        _otpSent = true;
        _statusMessage = isUrduGlobal.value
            ? 'او ٹی پی ایڈمن کے اکاؤنٹ میں بھیج دیا گیا ہے (میعاد: 24 گھنٹے)۔ براہ کرم ایڈمن سے 6 ہندسوں کا کوڈ لے کر نیچے درج کریں۔'
            : 'OTP sent to Admin (Valid 24 hours). Please get 6-digit code from Admin and enter below.';
      } else {
        _isError = true;
        _statusMessage = res['message'] ?? (isUrduGlobal.value ? 'او ٹی پی بھیجنے میں خرابی ہوئی' : 'Failed to request OTP');
      }
    });
  }

  Future<void> _verifyOtp() async {
    final code = _otpCtrl.text.trim();
    if (code.length != 6) {
      setState(() {
        _isError = true;
        _statusMessage = isUrduGlobal.value ? 'براہ کرم مکمل 6 ہندسوں کا OTP کوڈ درج کریں' : 'Please enter complete 6-digit OTP';
      });
      return;
    }

    setState(() {
      _isVerifying = true;
      _statusMessage = null;
      _isError = false;
    });

    final res = await FinanceOtpService.verifyEditOtp(
      financeId: widget.financeId,
      otpCode: code,
    );

    if (!mounted) return;
    setState(() => _isVerifying = false);

    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isUrduGlobal.value
                ? 'ایڈمن کی اجازت مل گئی! آپ اگلے 24 گھنٹے تک اس انٹری میں ترمیم کر سکتے ہیں۔'
                : 'Permission granted! You can edit this entry for the next 24 hours.',
          ),
          backgroundColor: Colors.green,
        ),
      );
      Navigator.pop(context, true);
    } else {
      setState(() {
        _isError = true;
        _statusMessage = res['message'] ?? (isUrduGlobal.value ? 'غلط او ٹی پی یا میعاد ختم ہو چکی ہے' : 'Invalid OTP or expired');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    const primaryGreen = Color(0xFF284D3D);
    return AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Row(
        children: [
          const Icon(Icons.lock_clock, color: Colors.orange, size: 24),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              isUrduGlobal.value ? 'ایڈمن کی اجازت درکار ہے' : 'Admin Permission Required',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.blueGrey.shade50,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                'انٹری: ${widget.recordSummary}',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              isUrduGlobal.value
                  ? 'محفوظ شدہ انٹری میں ترمیم یا حذف کے لیے ایڈمن کی اجازت لازمی ہے۔ ایڈمن کو سسٹم سے 24 گھنٹے کی میعاد والا OTP بھیجنے کے لیے نیچے بٹن دبائیں۔'
                  : 'Admin approval is required to edit/delete saved entries. Press below to send a 24-hour valid OTP to Admin.',
              style: const TextStyle(fontSize: 12, color: Colors.black87),
            ),
            const SizedBox(height: 12),
            Center(
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  side: BorderSide(color: primaryGreen),
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                ),
                icon: _isRequesting
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.send_to_mobile, size: 18),
                label: Text(
                  _otpSent
                      ? (isUrduGlobal.value ? 'دوبارہ OTP بھیجیں' : 'Resend OTP')
                      : (isUrduGlobal.value ? 'ایڈمن کو OTP کی درخواست بھیجیں' : 'Request OTP from Admin'),
                  style: TextStyle(color: primaryGreen, fontSize: 13, fontWeight: FontWeight.bold),
                ),
                onPressed: _isRequesting ? null : _sendOtpRequest,
              ),
            ),
            if (_statusMessage != null) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: _isError ? Colors.red.shade50 : Colors.green.shade50,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: _isError ? Colors.red.shade300 : Colors.green.shade300),
                ),
                child: Text(
                  _statusMessage!,
                  style: TextStyle(
                    fontSize: 11,
                    color: _isError ? Colors.red.shade900 : Colors.green.shade900,
                  ),
                ),
              ),
            ],
            const SizedBox(height: 16),
            TextField(
              controller: _otpCtrl,
              keyboardType: TextInputType.number,
              maxLength: 6,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, letterSpacing: 6),
              decoration: InputDecoration(
                labelText: isUrduGlobal.value ? '6 ہندسوں کا OTP کوڈ درج کریں' : 'Enter 6-Digit OTP',
                hintText: '123456',
                counterText: '',
                border: const OutlineInputBorder(),
                prefixIcon: const Icon(Icons.pin),
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: Text(isUrduGlobal.value ? 'منسوخ' : 'Cancel'),
        ),
        ElevatedButton(
          style: ElevatedButton.styleFrom(
            backgroundColor: primaryGreen,
            foregroundColor: Colors.white,
          ),
          onPressed: _isVerifying ? null : _verifyOtp,
          child: _isVerifying
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : Text(isUrduGlobal.value ? 'تصدیق کریں' : 'Verify'),
        ),
      ],
    );
  }
}



