// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'package:flutter/material.dart';
import '../db_helper.dart';
import '../utils/system_type_global.dart';
import '../widgets/app_drawer.dart';

class AnnouncementsFeedScreen extends StatefulWidget {
  const AnnouncementsFeedScreen({super.key});

  @override
  State<AnnouncementsFeedScreen> createState() =>
      _AnnouncementsFeedScreenState();
}

class _AnnouncementsFeedScreenState extends State<AnnouncementsFeedScreen>
    with SingleTickerProviderStateMixin {
  final DBHelper _dbHelper = DBHelper();
  final Color primaryGreen = const Color(0xFF284D3D);

  late TabController _tabController;
  bool _isLoading = true;
  List<Map<String, dynamic>> _announcements = [];
  List<Map<String, dynamic>> _webPages = [];

  final List<String> _categories = [
    'خبر',
    'تقریب',
    'تعطیل / چھٹی',
    'اہم نوٹس',
    'امتحانات',
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

    final ann = await _dbHelper.getAnnouncements(systemType: sys);
    final pages = await _dbHelper.getWebPages(systemType: sys);

    setState(() {
      _announcements = ann;
      _webPages = pages;
      _isLoading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final sys = SystemTypeGlobal.currentSystemType.value;
    final title = sys == 'Masjid' ? 'مسجد اعلانات و خبریں' : 'خبریں، اعلانات و ویب CMS';

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
                icon: const Icon(Icons.campaign),
                text: 'اعلانات و خبریں (${_announcements.length})',
              ),
              Tab(
                icon: const Icon(Icons.language),
                text: 'ویب سائٹ پیجز (${_webPages.length})',
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
                  _buildAnnouncementsTab(),
                  _buildWebPagesTab(),
                ],
              ),
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: primaryGreen,
          foregroundColor: Colors.white,
          onPressed: () {
            if (_tabController.index == 0) {
              _showAnnouncementFormDialog();
            } else {
              _showWebPageFormDialog();
            }
          },
          icon: Icon(_tabController.index == 0 ? Icons.add_alert : Icons.add_to_photos),
          label: Text(
            _tabController.index == 0 ? 'نیا اعلان شائع کریں' : 'نیا پیج بنائیں',
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }

  Widget _buildAnnouncementsTab() {
    if (_announcements.isEmpty) {
      return const Center(
        child: Text('کوئی اعلانات موجود نہیں ہیں۔ نیا اعلان شائع کریں۔'),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 12),
      itemCount: _announcements.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (ctx, idx) {
        final a = _announcements[idx];
        final isPublic = (a['is_public'] ?? 1) == 1;
        final cat = a['category']?.toString() ?? 'خبر';

        Color catColor = primaryGreen;
        if (cat.contains('تعطیل')) catColor = Colors.orange.shade800;
        if (cat.contains('اہم')) catColor = Colors.red.shade700;
        if (cat.contains('تقریب')) catColor = Colors.purple.shade700;

        return Card(
          elevation: 2,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: catColor.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: catColor),
                      ),
                      child: Text(
                        cat,
                        style: TextStyle(
                          color: catColor,
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    Row(
                      children: [
                        if (isPublic)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.blue.shade50,
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Row(
                              children: [
                                Icon(Icons.public, size: 12, color: Colors.blue),
                                SizedBox(width: 4),
                                Text('پبلک ویب سائٹ',
                                    style: TextStyle(fontSize: 10, color: Colors.blue)),
                              ],
                            ),
                          ),
                        IconButton(
                          icon: const Icon(Icons.edit, size: 18),
                          onPressed: () => _showAnnouncementFormDialog(ann: a),
                        ),
                        IconButton(
                          icon: const Icon(Icons.delete, color: Colors.red, size: 18),
                          onPressed: () => _confirmDeleteAnnouncement(a['id'] as int),
                        ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  a['title']?.toString() ?? '',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  a['body']?.toString() ?? '',
                  style: TextStyle(
                    color: Colors.grey.shade800,
                    fontSize: 14,
                    height: 1.4,
                  ),
                ),
                const Divider(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'تاریخ: ${a['publish_date'] ?? ''}',
                      style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                    ),
                    Text(
                      'مخاطب: ${a['target_audience'] ?? 'سب کے لیے'}',
                      style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
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

  Widget _buildWebPagesTab() {
    return Column(
      children: [
        Container(
          color: Colors.blue.shade50,
          padding: const EdgeInsets.all(12),
          child: const Row(
            children: [
              Icon(Icons.info_outline, color: Colors.blue),
              SizedBox(width: 8),
              Expanded(
                child: Text(
                  'یہاں تبدیل کردہ پیجز خودکار طور پر ادارے کی پبلک ویب سائٹ (index.php) پر لائیو ہو جاتے ہیں۔',
                  style: TextStyle(fontSize: 12, color: Colors.blueGrey),
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: _webPages.isEmpty
              ? const Center(
                  child: Text('کوئی پیج موجود نہیں ہے۔ نیا ویب پیج شامل کریں۔'),
                )
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 80, left: 12, right: 12, top: 8),
                  itemCount: _webPages.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (ctx, idx) {
                    final p = _webPages[idx];
                    return Card(
                      elevation: 1.5,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: Colors.teal.shade50,
                          child: const Icon(Icons.language, color: Color(0xFF284D3D)),
                        ),
                        title: Text(
                          p['title']?.toString() ?? '',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                        subtitle: Text(
                          'سلگ: /${p['page_slug'] ?? ''}\n${p['meta_description'] ?? ''}',
                          style: TextStyle(color: Colors.grey.shade700, fontSize: 12),
                        ),
                        trailing: IconButton(
                          icon: const Icon(Icons.edit, color: Color(0xFF284D3D)),
                          onPressed: () => _showWebPageFormDialog(page: p),
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  void _showAnnouncementFormDialog({Map<String, dynamic>? ann}) {
    final isEdit = ann != null;
    final titleCtrl = TextEditingController(text: ann?['title']?.toString() ?? '');
    final bodyCtrl = TextEditingController(text: ann?['body']?.toString() ?? '');
    final dateCtrl = TextEditingController(
      text: ann?['publish_date']?.toString() ??
          DateTime.now().toIso8601String().substring(0, 10),
    );
    String selCat = ann?['category']?.toString() ?? 'خبر';
    bool isPublic = (ann?['is_public'] ?? 1) == 1;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: Text(isEdit ? 'ترمیم اعلان' : 'نیا اعلان شائع کریں'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: titleCtrl,
                    decoration: const InputDecoration(labelText: 'اعلان کا عنوان *'),
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: _categories.contains(selCat) ? selCat : 'خبر',
                    decoration: const InputDecoration(labelText: 'کیٹیگری'),
                    items: _categories
                        .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                        .toList(),
                    onChanged: (v) => setDlgState(() => selCat = v ?? 'خبر'),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: bodyCtrl,
                    maxLines: 4,
                    decoration: const InputDecoration(
                      labelText: 'تفصیلی مواد / اعلان کا متن *',
                      alignLabelWithHint: true,
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: dateCtrl,
                    decoration: const InputDecoration(
                      labelText: 'تاریخ اشاعت',
                      hintText: 'YYYY-MM-DD',
                    ),
                  ),
                  const SizedBox(height: 8),
                  SwitchListTile(
                    title: const Text('پبلک ویب سائٹ پر دکھائیں'),
                    subtitle: const Text('چیک کرنے پر ادارے کی پبلک ویب سائٹ پر لائیو ہوگا'),
                    value: isPublic,
                    activeColor: primaryGreen,
                    onChanged: (val) => setDlgState(() => isPublic = val),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('منسوخ')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: primaryGreen),
                onPressed: () async {
                  if (titleCtrl.text.trim().isEmpty || bodyCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('عنوان اور تفصیلی مواد لازمی ہیں')),
                    );
                    return;
                  }

                  final data = {
                    'title': titleCtrl.text.trim(),
                    'body': bodyCtrl.text.trim(),
                    'category': selCat,
                    'publish_date': dateCtrl.text.trim(),
                    'is_public': isPublic ? 1 : 0,
                    'target_audience': 'All',
                    'system_type': SystemTypeGlobal.currentSystemType.value,
                  };

                  if (isEdit) {
                    await _dbHelper.updateAnnouncement(ann['id'] as int, data);
                  } else {
                    await _dbHelper.insertAnnouncement(data);
                  }

                  Navigator.pop(ctx);
                  _loadData();
                },
                child: Text(isEdit ? 'محفوظ کریں' : 'شائع کریں',
                    style: const TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _showWebPageFormDialog({Map<String, dynamic>? page}) {
    final isEdit = page != null;
    final slugCtrl = TextEditingController(text: page?['page_slug']?.toString() ?? '');
    final titleCtrl = TextEditingController(text: page?['title']?.toString() ?? '');
    final descCtrl = TextEditingController(text: page?['meta_description']?.toString() ?? '');
    final contentCtrl = TextEditingController(text: page?['content_html']?.toString() ?? '');

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => Directionality(
          textDirection: TextDirection.rtl,
          child: AlertDialog(
            title: Text(isEdit ? 'ترمیم ویب پیج' : 'نیا ویب پیج بنائیں'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: titleCtrl,
                    decoration: const InputDecoration(labelText: 'پیج کا عنوان *'),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: slugCtrl,
                    enabled: !isEdit,
                    decoration: const InputDecoration(
                      labelText: 'پیج سلگ (URL Slug) *',
                      hintText: 'مثلاً: about-us, admissions',
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: descCtrl,
                    decoration: const InputDecoration(labelText: 'مختصر تعارف / میٹا ڈسکرپشن'),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: contentCtrl,
                    maxLines: 6,
                    decoration: const InputDecoration(
                      labelText: 'پیج کا تفصیلی مواد (HTML یا سادہ متن)',
                      alignLabelWithHint: true,
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
                  if (titleCtrl.text.trim().isEmpty || slugCtrl.text.trim().isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('عنوان اور سلگ لازمی ہیں')),
                    );
                    return;
                  }

                  final data = {
                    'page_slug': slugCtrl.text.trim(),
                    'title': titleCtrl.text.trim(),
                    'meta_description': descCtrl.text.trim(),
                    'content_html': contentCtrl.text.trim(),
                    'system_type': SystemTypeGlobal.currentSystemType.value,
                  };

                  await _dbHelper.saveWebPage(data);
                  Navigator.pop(ctx);
                  _loadData();
                },
                child: const Text('محفوظ کریں', style: TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _confirmDeleteAnnouncement(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          title: const Text('اعلان حذف کی تصدیق'),
          content: const Text('کیا آپ واقعی یہ اعلان حذف کرنا چاہتے ہیں؟'),
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
      await _dbHelper.deleteAnnouncement(id);
      _loadData();
    }
  }
}
