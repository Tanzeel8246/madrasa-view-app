import 'package:flutter/foundation.dart';
import 'package:sqflite/sqflite.dart';
import '../../utils/globals.dart';

class CoreSyncSaver {
  static Future<void> save(Database db, Batch batch, Map<String, dynamic> data) async {
    // 1. Settings
    if (data['settings'] != null) {
      try {
        var s = data['settings'];
        batch.insert(
          'settings',
          {
            'id': 1,
            'madrasa_name': s['madrasa_name']?.toString() ?? '',
            'current_hijri_year': s['current_hijri_year']?.toString() ?? '1445',
            'week_start_day':
                int.tryParse(s['week_start_day']?.toString() ?? '6') ?? 6,
            'ai_provider': s['ai_provider']?.toString() ?? 'gemini',
            'gemini_api_key': '',
            'groq_api_key': '',
            'whatsapp_api_key': '',
            'sms_api_key': '',
            'auto_notify_day':
                int.tryParse(s['auto_notify_day']?.toString() ?? '4') ?? 4,
            'auto_notify_time': s['auto_notify_time']?.toString() ?? '18:00',
            'address': s['address']?.toString() ?? '',
            'tagline': s['tagline']?.toString() ?? 'العلم نور - تعلیم و تربیت کا گہوارہ',
            'logo_path': s['logo_path']?.toString() ?? 'logo.png',
            'receipt_bg': s['receipt_bg']?.toString() ?? 'uploads/receipt_bg.webp',
            'masjid_receipt_bg': s['masjid_receipt_bg']?.toString() ?? 'uploads/MAMFSS.webp',
            'id_card_bg': s['id_card_bg']?.toString(),
            'sanad_bg': s['sanad_bg']?.toString(),
            'signature_stamp_path': s['signature_stamp_path']?.toString(),
            'sanad_layout_json': s['sanad_layout_json']?.toString(),
            'masjid_name': s['masjid_name']?.toString() ?? 'مسجد اشرف المدارس',
            'masjid_logo_path': s['masjid_logo_path']?.toString(),
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
        final syncedName = s['madrasa_name']?.toString().trim();
        if (syncedName != null && syncedName.isNotEmpty) {
          currentTenantName.value = syncedName;
        }
        final syncedLogo = s['logo_path']?.toString().trim();
        if (syncedLogo != null && syncedLogo.isNotEmpty) {
          currentTenantLogoPath.value = syncedLogo;
        }
        final syncedMasjid = s['masjid_name']?.toString().trim();
        if (syncedMasjid != null && syncedMasjid.isNotEmpty) {
          currentTenantMasjidName.value = syncedMasjid;
        }
        final syncedMasjidLogo = s['masjid_logo_path']?.toString().trim();
        if (syncedMasjidLogo != null && syncedMasjidLogo.isNotEmpty) {
          currentTenantMasjidLogoPath.value = syncedMasjidLogo;
        }
      } catch (e) {
        debugPrint("Sync Error (settings): $e");
      }
    }

    // 2. Classes (Madrasa)
    if (data['classes'] != null) {
      try {
        final clsTableInfo = await db.rawQuery("PRAGMA table_info(classes)");
        final validCols = clsTableInfo.map((r) => r['name'] as String).toSet();

        for (var item in data['classes']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          Map<String, dynamic> row = {
            'id': serverId > 0 ? serverId : null,
            'name': item['name'],
            'section': item['section'] ?? '',
            'status': item['status'] ?? 'Active',
            'class_type': item['class_type'] ?? 'Regular',
            'main_teacher_ids': item['main_teacher_ids'] ?? '',
            'p1_name': item['p1_name'] ?? 'Period 1',
            'p2_name': item['p2_name'] ?? 'Period 2',
            'p3_name': item['p3_name'] ?? 'Period 3',
            'p4_name': item['p4_name'] ?? 'Period 4',
            'p5_name': item['p5_name'] ?? 'Period 5',
            'p6_name': item['p6_name'] ?? 'Period 6',
            'subjects_list': item['subjects_list'] ?? '',
            'system_type': (item['system_type']?.toString().isNotEmpty == true)
                ? item['system_type'].toString()
                : 'Madrasa',
            'is_deleted':
                int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            'is_synced': 1,
          };
          row.removeWhere((k, v) => !validCols.contains(k));
          batch.insert('classes', row,
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      } catch (e) {
        debugPrint("Sync Error (classes): $e");
      }
    }

    // 3. Departments
    if (data['departments'] != null) {
      try {
        for (var item in data['departments']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'departments',
            {
              'id': serverId > 0 ? serverId : null,
              'name': item['name']?.toString() ?? '',
              'description': item['description']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Active',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (departments): $e");
      }
    }

    // 4. Notices
    if (data['notices'] != null) {
      try {
        for (var item in data['notices']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'notices',
            {
              'id': serverId > 0 ? serverId : null,
              'title': item['title']?.toString() ?? '',
              'message': item['message']?.toString() ?? '',
              'date': item['date']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_deleted':
                  int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'is_synced': 1,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (notices): $e");
      }
    }

    // 5. Masjid Assets
    if (data['masjid_assets'] != null) {
      try {
        for (var item in data['masjid_assets']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'masjid_assets',
            {
              'id': serverId > 0 ? serverId : null,
              'name': item['name']?.toString() ?? '',
              'category': item['category']?.toString() ?? '',
              'quantity': int.tryParse(item['quantity']?.toString() ?? '1') ?? 1,
              'condition': item['condition']?.toString() ?? 'نیا (New)',
              'condition_status': item['condition_status']?.toString() ?? 'نیا (New)',
              'asset_type': item['asset_type']?.toString() ?? 'Purchased',
              'donor_name': item['donor_name']?.toString() ?? '',
              'donor_father': item['donor_father']?.toString() ?? '',
              'donor_address': item['donor_address']?.toString() ?? '',
              'donor_phone': item['donor_phone']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Masjid',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (masjid_assets): $e");
      }
    }

    // 6. Admission Inquiries (Phase 2)
    if (data['admission_inquiries'] != null) {
      try {
        for (var item in data['admission_inquiries']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'admission_inquiries',
            {
              'id': serverId > 0 ? serverId : null,
              'student_name': item['student_name']?.toString() ?? '',
              'father_name': item['father_name']?.toString() ?? '',
              'phone': item['phone']?.toString() ?? '',
              'class_applied': item['class_applied']?.toString() ?? '',
              'gender': item['gender']?.toString() ?? 'Male',
              'address': item['address']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Pending',
              'interview_date': item['interview_date']?.toString(),
              'interview_notes': item['interview_notes']?.toString(),
              'admin_notes': item['admin_notes']?.toString(),
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'converted_student_id': int.tryParse(item['converted_student_id']?.toString() ?? ''),
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (admission_inquiries): $e");
      }
    }

    // 7. Library Books (Phase 2)
    if (data['lib_books'] != null) {
      try {
        for (var item in data['lib_books']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'lib_books',
            {
              'id': serverId > 0 ? serverId : null,
              'book_title': item['book_title']?.toString() ?? '',
              'author': item['author']?.toString() ?? '',
              'category': item['category']?.toString() ?? 'عام',
              'isbn': item['isbn']?.toString() ?? '',
              'rack_number': item['rack_number']?.toString() ?? '',
              'total_copies': int.tryParse(item['total_copies']?.toString() ?? '1') ?? 1,
              'available_copies': int.tryParse(item['available_copies']?.toString() ?? '1') ?? 1,
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (lib_books): $e");
      }
    }

    // 8. Library Issues (Phase 2)
    if (data['lib_issues'] != null) {
      try {
        for (var item in data['lib_issues']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'lib_issues',
            {
              'id': serverId > 0 ? serverId : null,
              'book_id': int.tryParse(item['book_id']?.toString() ?? '0') ?? 0,
              'borrower_type': item['borrower_type']?.toString() ?? 'student',
              'borrower_id': int.tryParse(item['borrower_id']?.toString() ?? '0') ?? 0,
              'borrower_name': item['borrower_name']?.toString() ?? '',
              'borrower_contact': item['borrower_contact']?.toString() ?? '',
              'issue_date': item['issue_date']?.toString() ?? '',
              'due_date': item['due_date']?.toString() ?? '',
              'return_date': item['return_date']?.toString(),
              'status': item['status']?.toString() ?? 'Issued',
              'fine_amount': double.tryParse(item['fine_amount']?.toString() ?? '0') ?? 0.0,
              'remarks': item['remarks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (lib_issues): $e");
      }
    }

    // 9. Inventory Items (Phase 3)
    if (data['inventory_items'] != null) {
      try {
        for (var item in data['inventory_items']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'inventory_items',
            {
              'id': serverId > 0 ? serverId : null,
              'item_code': item['item_code']?.toString() ?? '',
              'name': item['name']?.toString() ?? item['item_name']?.toString() ?? '',
              'category': item['category']?.toString() ?? 'عام',
              'unit_price': double.tryParse((item['unit_price'] ?? item['sale_price'])?.toString() ?? '0') ?? 0.0,
              'purchase_price': double.tryParse((item['purchase_price'] ?? item['cost_price'])?.toString() ?? '0') ?? 0.0,
              'current_stock': int.tryParse((item['current_stock'] ?? item['stock_quantity'])?.toString() ?? '0') ?? 0,
              'alert_threshold': int.tryParse((item['alert_threshold'] ?? item['min_stock_alert'])?.toString() ?? '5') ?? 5,
              'unit': item['unit']?.toString() ?? 'عدد',
              'description': item['description']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (inventory_items): $e");
      }
    }

    // 10. Inventory Sales (Phase 3)
    if (data['inventory_sales'] != null) {
      try {
        for (var item in data['inventory_sales']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'inventory_sales',
            {
              'id': serverId > 0 ? serverId : null,
              'sale_no': item['sale_no']?.toString() ?? item['invoice_no']?.toString() ?? '',
              'item_id': int.tryParse(item['item_id']?.toString() ?? ''),
              'item_name': item['item_name']?.toString() ?? '',
              'student_id': int.tryParse((item['student_id'] ?? item['buyer_id'])?.toString() ?? ''),
              'customer_name': item['customer_name']?.toString() ?? item['buyer_name']?.toString() ?? '',
              'quantity': int.tryParse(item['quantity']?.toString() ?? '1') ?? 1,
              'unit_price': double.tryParse(item['unit_price']?.toString() ?? '0') ?? 0.0,
              'total_amount': double.tryParse(item['total_amount']?.toString() ?? '0') ?? 0.0,
              'payment_mode': item['payment_mode']?.toString() ?? item['payment_method']?.toString() ?? 'Cash',
              'voucher_id': int.tryParse(item['voucher_id']?.toString() ?? ''),
              'date': item['date']?.toString() ?? item['sale_date']?.toString() ?? '',
              'remarks': item['remarks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (inventory_sales): $e");
      }
    }

    // 11. Hostel Rooms (Phase 3)
    if (data['hostel_rooms'] != null) {
      try {
        for (var item in data['hostel_rooms']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'hostel_rooms',
            {
              'id': serverId > 0 ? serverId : null,
              'room_number': item['room_number']?.toString() ?? '',
              'room_name': item['room_name']?.toString() ?? '',
              'capacity_beds': int.tryParse((item['capacity_beds'] ?? item['capacity'])?.toString() ?? '4') ?? 4,
              'monthly_fee': double.tryParse(item['monthly_fee']?.toString() ?? '0') ?? 0.0,
              'status': item['status']?.toString() ?? 'Active',
              'remarks': item['remarks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (hostel_rooms): $e");
      }
    }

    // 12. Hostel Allocations (Phase 3)
    if (data['hostel_allocations'] != null) {
      try {
        for (var item in data['hostel_allocations']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'hostel_allocations',
            {
              'id': serverId > 0 ? serverId : null,
              'room_id': int.tryParse(item['room_id']?.toString() ?? '0') ?? 0,
              'student_id': int.tryParse(item['student_id']?.toString() ?? '0') ?? 0,
              'student_name': item['student_name']?.toString() ?? '',
              'bed_number': item['bed_number']?.toString() ?? '',
              'allocation_date': item['allocation_date']?.toString() ?? '',
              'vacating_date': (item['vacating_date'] ?? item['vacation_date'])?.toString(),
              'status': item['status']?.toString() ?? 'Occupied',
              'remarks': item['remarks']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (hostel_allocations): $e");
      }
    }

    // 13. Job Postings (Phase 4)
    if (data['job_postings'] != null) {
      try {
        for (var item in data['job_postings']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'job_postings',
            {
              'id': serverId > 0 ? serverId : null,
              'title': item['title']?.toString() ?? '',
              'department': item['department']?.toString() ?? '',
              'job_type': item['job_type']?.toString() ?? 'Full-time',
              'requirements': item['requirements']?.toString() ?? item['description']?.toString() ?? '',
              'salary_range': item['salary_range']?.toString() ?? '${item['salary_min'] ?? 0} - ${item['salary_max'] ?? 0}',
              'deadline': item['deadline']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Open',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (job_postings): $e");
      }
    }

    // 14. Job Applicants (Phase 4)
    if (data['job_applicants'] != null) {
      try {
        for (var item in data['job_applicants']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'job_applicants',
            {
              'id': serverId > 0 ? serverId : null,
              'job_id': int.tryParse(item['job_id']?.toString() ?? ''),
              'applicant_name': item['applicant_name']?.toString() ?? '',
              'father_name': item['father_name']?.toString() ?? '',
              'phone': item['phone']?.toString() ?? '',
              'email': item['email']?.toString() ?? '',
              'qualification': item['qualification']?.toString() ?? '',
              'experience': item['experience']?.toString() ?? '${item['experience_years'] ?? 0} سال',
              'resume_path': item['resume_path']?.toString() ?? item['resume_notes']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Pending',
              'interview_date': item['interview_date']?.toString(),
              'interview_notes': item['interview_notes']?.toString(),
              'notes': item['notes']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (job_applicants): $e");
      }
    }

    // 15. Web Pages (Phase 4)
    if (data['web_pages'] != null) {
      try {
        for (var item in data['web_pages']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'web_pages',
            {
              'id': serverId > 0 ? serverId : null,
              'page_slug': item['page_slug']?.toString() ?? item['slug']?.toString() ?? '',
              'title': item['title']?.toString() ?? '',
              'content_html': item['content_html']?.toString() ?? item['content']?.toString() ?? '',
              'meta_description': item['meta_description']?.toString() ?? '',
              'status': item['status']?.toString() ?? 'Published',
              'sort_order': int.tryParse(item['sort_order']?.toString() ?? '0') ?? 0,
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'updated_at': item['updated_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (web_pages): $e");
      }
    }

    // 16. Announcements (Phase 4)
    if (data['announcements'] != null) {
      try {
        for (var item in data['announcements']) {
          final serverId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          batch.insert(
            'announcements',
            {
              'id': serverId > 0 ? serverId : null,
              'title': item['title']?.toString() ?? '',
              'body': item['body']?.toString() ?? item['content']?.toString() ?? '',
              'image_url': item['image_url']?.toString() ?? item['banner_image']?.toString() ?? '',
              'category': item['category']?.toString() ?? 'خبر',
              'is_public': int.tryParse(item['is_public']?.toString() ?? '1') ?? 1,
              'target_audience': item['target_audience']?.toString() ?? 'All',
              'publish_date': item['publish_date']?.toString() ?? '',
              'system_type': item['system_type']?.toString() ?? 'Madrasa',
              'is_synced': 1,
              'is_deleted': int.tryParse(item['is_deleted']?.toString() ?? '0') ?? 0,
              'created_at': item['created_at']?.toString(),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      } catch (e) {
        debugPrint("Sync Error (announcements): $e");
      }
    }
  }
}
