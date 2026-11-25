import 'dart:io';
import 'package:flutter/material.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:share_plus/share_plus.dart';

class CopiedFileInfo {
  final String originalPath;
  final String destinationPath;
  final int sizeInBytes;
  final bool isDb;

  CopiedFileInfo({
    required this.originalPath,
    required this.destinationPath,
    required this.sizeInBytes,
    required this.isDb,
  });

  String get fileName => p.basename(originalPath);
  String get formattedSize {
    if (sizeInBytes < 1024) return '$sizeInBytes B';
    if (sizeInBytes < 1024 * 1024) return '${(sizeInBytes / 1024).toStringAsFixed(2)} KB';
    return '${(sizeInBytes / (1024 * 1024)).toStringAsFixed(2)} MB';
  }
}

class RecoveryScreen extends StatefulWidget {
  const RecoveryScreen({super.key});

  @override
  State<RecoveryScreen> createState() => _RecoveryScreenState();
}

class _RecoveryScreenState extends State<RecoveryScreen> {
  bool _isRunning = true;
  String _statusMessage = 'فائل سسٹم کی تلاش اور خام نقل شروع ہو رہی ہے...';
  List<CopiedFileInfo> _copiedFiles = [];
  final List<String> _targetDirs = [];
  bool _dbFound = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _executeRecovery();
    });
  }

  Future<void> _executeRecovery() async {
    try {
      // 1. درخواست اجازت برائے اسٹوریج
      try {
        await Permission.storage.request();
        if (await Permission.manageExternalStorage.isRestricted == false) {
          await Permission.manageExternalStorage.request();
        }
      } catch (_) {}

      // 2. تلاش کے اندرونی ممکنہ مقامات حاصل کریں
      final List<Directory> searchRoots = [];

      try {
        final docDir = await getApplicationDocumentsDirectory();
        searchRoots.add(docDir);
        // ایپ کی مین پرائیویٹ ڈائرکٹری حاصل کریں
        final appRootDir = docDir.parent;
        searchRoots.add(appRootDir);

        final databasesDir = Directory(p.join(appRootDir.path, 'databases'));
        if (await databasesDir.exists()) searchRoots.add(databasesDir);

        final filesDir = Directory(p.join(appRootDir.path, 'files'));
        if (await filesDir.exists()) searchRoots.add(filesDir);
      } catch (_) {}

      try {
        final supportDir = await getApplicationSupportDirectory();
        searchRoots.add(supportDir);
      } catch (_) {}

      // 3. ٹارگٹ ڈائریکٹریز تیار کریں
      final List<Directory> destDirs = [];

      // مقام الف: پبلک Download/MadrasaRecovery
      final publicDownloadRecovery = Directory('/storage/emulated/0/Download/MadrasaRecovery');
      try {
        if (!await publicDownloadRecovery.exists()) {
          await publicDownloadRecovery.create(recursive: true);
        }
        destDirs.add(publicDownloadRecovery);
        _targetDirs.add(publicDownloadRecovery.path);
      } catch (e) {
        debugPrint('Could not create public Download folder: $e');
      }

      // مقام ب: ایپ کی بیرونی محفوظ ڈائرکٹری (External App Storage)
      try {
        final extDirs = await getExternalStorageDirectories();
        if (extDirs != null && extDirs.isNotEmpty) {
          final extRecovery = Directory(p.join(extDirs.first.path, 'MadrasaRecovery'));
          if (!await extRecovery.exists()) {
            await extRecovery.create(recursive: true);
          }
          destDirs.add(extRecovery);
          _targetDirs.add(extRecovery.path);
        }
      } catch (_) {}

      // اگر کوئی بھی ڈیسٹینیشن نہ بنی تو Documents کے تحت
      if (destDirs.isEmpty) {
        final fallback = Directory(p.join((await getApplicationDocumentsDirectory()).path, 'MadrasaRecovery'));
        await fallback.create(recursive: true);
        destDirs.add(fallback);
        _targetDirs.add(fallback.path);
      }

      // 4. تمام اندرونی مقامات سے ممکنہ فائلیں اسکین کریں
      final Set<String> scannedFilePaths = {};
      final List<File> candidateFiles = [];

      for (final root in searchRoots) {
        if (!await root.exists()) continue;
        try {
          final entities = root.listSync(recursive: true, followLinks: false);
          for (final entity in entities) {
            if (entity is File) {
              final path = entity.path;
              if (scannedFilePaths.contains(path)) continue;

              final name = p.basename(path).toLowerCase();
              final ext = p.extension(path).toLowerCase();

              // صرف ڈیٹا بیس، وال، جرنل یا مدراسہ سے متعلقہ فائلیں
              final isDbCandidate = ext == '.db' ||
                  ext == '.sqlite' ||
                  ext == '.sqlite3' ||
                  name.contains('-wal') ||
                  name.contains('-shm') ||
                  name.contains('-journal') ||
                  name.contains('madrasa') ||
                  path.contains('/databases/');

              if (isDbCandidate) {
                scannedFilePaths.add(path);
                candidateFiles.add(entity);
              }
            }
          }
        } catch (e) {
          debugPrint('Error scanning dir ${root.path}: $e');
        }
      }

      // 5. بائٹ ٹو بائٹ خام نقل (Raw Copy)
      final List<CopiedFileInfo> copiedList = [];
      bool hasAnyDb = false;

      for (final file in candidateFiles) {
        try {
          final originalPath = file.path;
          final fileName = p.basename(originalPath);
          final bytes = await file.readAsBytes();
          final fileSize = bytes.length;

          final isDb = fileName.endsWith('.db') || fileName.endsWith('.sqlite') || fileName.endsWith('.sqlite3');
          if (isDb) hasAnyDb = true;

          // تمام ٹارگٹ ڈائریکٹریز میں کاپی کریں
          String primaryDest = '';
          for (int i = 0; i < destDirs.length; i++) {
            final destDir = destDirs[i];
            final destPath = p.join(destDir.path, fileName);
            final destFile = File(destPath);
            await destFile.writeAsBytes(bytes, flush: true);
            if (i == 0) primaryDest = destPath;
          }

          copiedList.add(CopiedFileInfo(
            originalPath: originalPath,
            destinationPath: primaryDest,
            sizeInBytes: fileSize,
            isDb: isDb,
          ));
        } catch (e) {
          debugPrint('Error copying ${file.path}: $e');
        }
      }

      setState(() {
        _copiedFiles = copiedList;
        _dbFound = hasAnyDb;
        _isRunning = false;
        if (!hasAnyDb && copiedList.isEmpty) {
          _statusMessage = '⚠️ کوئی ڈیٹا بیس یا متعلقہ فائل نہیں ملی!';
        } else if (!hasAnyDb) {
          _statusMessage = '⚠️ فائلیں ملیں مگر کوئی باقاعدہ .db فائل نہیں ملی!';
        } else {
          _statusMessage = '✅ خام نقل کامیابی سے مکمل ہو گئی۔';
        }
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
        _isRunning = false;
        _statusMessage = 'خرابی پیش آگئی: $e';
      });
    }
  }

  Future<void> _shareFiles() async {
    if (_copiedFiles.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('شیئر کرنے کے لیے کوئی فائل موجود نہیں ہے۔')),
      );
      return;
    }

    try {
      final List<XFile> xFiles = [];
      for (final info in _copiedFiles) {
        final f = File(info.destinationPath);
        if (await f.exists()) {
          xFiles.add(XFile(f.path, name: info.fileName));
        }
      }

      if (xFiles.isNotEmpty) {
        await Share.shareXFiles(
          xFiles,
          text: 'Madrasa App Raw Database Recovery Files (+361)',
          subject: 'Madrasa App Raw Database Files',
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('شیئرنگ میں خرابی: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        centerTitle: true,
        title: const Text(
          'ریکوری موڈ — خام ڈیٹا بیس محفوظ ساز',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: Colors.white),
        ),
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // اسٹیٹس بینر
              if (_errorMessage != null)
                Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.red.shade900,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    'تفصیل خرابی: $_errorMessage',
                    style: const TextStyle(color: Colors.white, fontSize: 12),
                  ),
                ),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: _isRunning
                      ? const Color(0xFF1E293B)
                      : (_dbFound ? const Color(0xFF065F46) : const Color(0xFF991B1B)),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                    color: _isRunning
                        ? const Color(0xFF334155)
                        : (_dbFound ? const Color(0xFF10B981) : const Color(0xFFEF4444)),
                  ),
                ),
                child: Row(
                  children: [
                    _isRunning
                        ? const SizedBox(
                            width: 24,
                            height: 24,
                            child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.amber),
                          )
                        : Icon(
                            _dbFound ? Icons.check_circle : Icons.warning_amber_rounded,
                            color: Colors.white,
                            size: 28,
                          ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        _statusMessage,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 12),

              // محفوظ شدہ فولڈر کا پاتھ
              if (_targetDirs.isNotEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: const Color(0xFF1E293B),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'منزل کا فولڈر (Target Location):',
                        style: TextStyle(color: Colors.grey, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 4),
                      SelectableText(
                        _targetDirs.first,
                        style: const TextStyle(color: Colors.amber, fontSize: 12, fontFamily: 'monospace'),
                      ),
                    ],
                  ),
                ),

              const SizedBox(height: 16),

              // نقل شدہ فائلوں کی فہرست
              Expanded(
                child: Container(
                  decoration: BoxDecoration(
                    color: const Color(0xFF1E293B),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFF334155)),
                  ),
                  child: _isRunning
                      ? const Center(
                          child: Text(
                            'فائلیں تلاش کر کے نقل کی جا رہی ہیں...\nبراہ کرم چند لمحے انتظار فرمائیں۔',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.grey, fontSize: 14),
                          ),
                        )
                      : _copiedFiles.isEmpty
                          ? const Center(
                              child: Text(
                                'کوئی فائل نقل نہیں ہو سکی۔',
                                style: TextStyle(color: Colors.redAccent, fontSize: 15, fontWeight: FontWeight.bold),
                              ),
                            )
                          : ListView.separated(
                              padding: const EdgeInsets.all(12),
                              itemCount: _copiedFiles.length,
                              separatorBuilder: (_, __) => const Divider(color: Color(0xFF334155), height: 12),
                              itemBuilder: (context, index) {
                                final info = _copiedFiles[index];
                                return ListTile(
                                  dense: true,
                                  contentPadding: EdgeInsets.zero,
                                  leading: CircleAvatar(
                                    backgroundColor: info.isDb ? const Color(0xFF047857) : const Color(0xFF334155),
                                    child: Icon(
                                      info.isDb ? Icons.storage : Icons.insert_drive_file,
                                      color: Colors.white,
                                      size: 20,
                                    ),
                                  ),
                                  title: Text(
                                    info.fileName,
                                    style: TextStyle(
                                      color: info.isDb ? Colors.white : Colors.grey[300],
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14,
                                    ),
                                  ),
                                  subtitle: Text(
                                    'سائز: ${info.formattedSize}\nمنبع: ${info.originalPath}',
                                    style: const TextStyle(color: Colors.grey, fontSize: 11),
                                  ),
                                );
                              },
                            ),
                ),
              ),

              const SizedBox(height: 16),

              // شیئر بٹن
              if (!_isRunning && _copiedFiles.isNotEmpty)
                ElevatedButton.icon(
                  onPressed: _shareFiles,
                  icon: const Icon(Icons.share, color: Colors.white),
                  label: const Text(
                    'تمام فائلیں شیئر کریں (Share via WhatsApp / Files)',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.white),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF059669),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                ),

              const SizedBox(height: 8),

              // احتیاطی نوٹ
              const Text(
                'نوٹ: تمام خام فائلیں فون کے Downloads/MadrasaRecovery/ فولڈر میں محفوظ کر دی گئی ہیں۔ ایپ کو یہیں رہنے دیں، آگے کوئی فلو نہیں چلے گا۔',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey, fontSize: 11),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
