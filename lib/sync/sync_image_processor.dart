import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';

class SyncImageProcessor {
  static Future<List<Map<String, dynamic>>> processImagesForSync(
      List<Map<String, dynamic>> chunk, String table) async {
    List<Map<String, dynamic>> processed = [];
    for (var row in chunk) {
      var map = Map<String, dynamic>.from(row);
      if (table == 'fee_submissions' && map['screenshot'] != null) {
        map['screenshot'] = await fileToBase64(map['screenshot']);
      } else if (table == 'leave_applications' &&
          map['application_photo'] != null) {
        map['application_photo'] =
            await fileToBase64(map['application_photo']);
      } else if (table == 'parent_complaints' && map['evidence'] != null) {
        map['evidence'] = await fileToBase64(map['evidence']);
      } else if ((table == 'students' || table == 'maktab_students') &&
          map['photo'] != null) {
        map['photo'] = await fileToBase64(map['photo']);
      } else if ((table == 'teachers' || table == 'maktab_teachers') &&
          map['photo'] != null) {
        map['photo'] = await fileToBase64(map['photo']);
      } else if ((table == 'finance' || table == 'masjid_finance') &&
          map['bill_image'] != null) {
        map['bill_image'] = await fileToBase64(map['bill_image']);
      }
      processed.add(map);
    }
    return processed;
  }

  static Future<String?> fileToBase64(String path) async {
    if (path.isEmpty ||
        path.startsWith('data:image') ||
        path.startsWith('http')) {
      return path;
    }
    try {
      final file = File(path);
      if (await file.exists()) {
        final bytes = await file.readAsBytes();
        String ext = path.split('.').last.toLowerCase();
        if (ext != 'png') ext = 'jpeg';
        return "data:image/$ext;base64,${base64Encode(bytes)}";
      }
    } catch (e) {
      debugPrint("File to base64 error: $e");
    }
    return path;
  }
}
