import 'dart:async';
import 'package:flutter/foundation.dart';

/// سسٹم کے مختلف ڈیٹا ڈومینز
enum DataDomain {
  students,
  attendance,
  finance,
  academics,
  masjid,
  inventory,
  library,
  hostel,
  teachers,
  announcements,
  settings,
  all,
}

/// ڈیٹا ریفریش ایونٹ ماڈل
class DataRefreshEvent {
  final DataDomain domain;
  final String? action; // 'insert', 'update', 'delete', 'sync'
  final dynamic entityId;
  final Map<String, dynamic>? metadata;
  final DateTime timestamp;

  DataRefreshEvent({
    required this.domain,
    this.action,
    this.entityId,
    this.metadata,
    DateTime? timestamp,
  }) : timestamp = timestamp ?? DateTime.now();

  @override
  String toString() => 'DataRefreshEvent(domain: $domain, action: $action, entityId: $entityId)';
}

/// سینٹرلائزڈ ری ایکٹیو ایونٹ بس
/// ڈیٹا بیس میں کسی بھی قسم کی تبدیلی پر تمام سکرینز اور ڈیش بورڈ بغیر ری لوڈ کے لائیو اپ ڈیٹ ہوتے ہیں
class DataRefreshBus {
  static final StreamController<DataRefreshEvent> _controller =
      StreamController<DataRefreshEvent>.broadcast();

  /// ویلیو نوٹیفائر برائے تیز رفتار UI بائنڈنگ
  static final ValueNotifier<int> tickNotifier = ValueNotifier<int>(0);

  /// تمام ایونٹس کا براڈکاسٹ اسٹریم
  static Stream<DataRefreshEvent> get stream => _controller.stream;

  /// کسی مخصوص ڈومین کے ایونٹس سننے کے لیے اسٹریم فلٹر
  static Stream<DataRefreshEvent> on(DataDomain domain) {
    return _controller.stream.where(
      (e) => e.domain == domain || e.domain == DataDomain.all,
    );
  }

  /// تبدیلی کی اطلاع بھیجیں
  static void notify(
    DataDomain domain, {
    String? action,
    dynamic entityId,
    Map<String, dynamic>? metadata,
  }) {
    final event = DataRefreshEvent(
      domain: domain,
      action: action,
      entityId: entityId,
      metadata: metadata,
    );
    if (!_controller.isClosed) {
      _controller.add(event);
    }
    tickNotifier.value++;
    debugPrint('🔔 [DataRefreshBus] Notified: $domain (action: $action, id: $entityId)');
  }

  /// تمام ڈومینز کو یکمشت ریفریش کی ہدایت
  static void notifyAll([String? reason]) {
    notify(DataDomain.all, action: 'refresh_all', metadata: {'reason': reason});
  }
}
