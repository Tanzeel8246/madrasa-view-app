// ignore_for_file: library_private_types_in_public_api, use_build_context_synchronously, deprecated_member_use
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:http/http.dart' as http;
import '../utils/globals.dart';
import '../widgets/neo_box.dart';
import 'dart:convert';

class AdmissionFormPage extends StatefulWidget {
  const AdmissionFormPage({super.key});

  @override
  State<AdmissionFormPage> createState() => _AdmissionFormPageState();
}

class _AdmissionFormPageState extends State<AdmissionFormPage> {
  final _formKey = GlobalKey<FormState>();
  final Color primaryGreen = const Color(0xFF284D3D);
  final Color accentGold = const Color(0xFFD4AC0D);

  // Controllers
  final TextEditingController _nameController = TextEditingController();
  final TextEditingController _fnameController = TextEditingController();
  final TextEditingController _dobController = TextEditingController();
  final TextEditingController _phoneController = TextEditingController();
  final TextEditingController _addressController = TextEditingController();
  final TextEditingController _permAddressController = TextEditingController();
  final TextEditingController _qualController = TextEditingController();
  final TextEditingController _lastStudiedController = TextEditingController();
  final TextEditingController _aimController = TextEditingController();
  final TextEditingController _prevMadarisController = TextEditingController();
  final TextEditingController _leaveReasonController = TextEditingController();
  final TextEditingController _guardianNameController = TextEditingController();
  final TextEditingController _guardianAddressController =
      TextEditingController();
  final TextEditingController _guardianRelationController =
      TextEditingController();
  final TextEditingController _guardianCnicController = TextEditingController();

  File? _image;
  final ImagePicker _picker = ImagePicker();
  bool _isLoading = false;

  Future<void> _pickImage(ImageSource source) async {
    final XFile? pickedFile = await _picker.pickImage(source: source);
    if (pickedFile != null) {
      setState(() {
        _image = File(pickedFile.path);
      });
    }
  }

  void _showImagePickerOptions() {
    showModalBottomSheet(
      context: context,
      builder: (context) => Wrap(
        children: [
          ListTile(
            leading: const Icon(Icons.camera_alt),
            title: const Text("کیمرہ (Camera)"),
            onTap: () {
              Navigator.pop(context);
              _pickImage(ImageSource.camera);
            },
          ),
          ListTile(
            leading: const Icon(Icons.photo_library),
            title: const Text("گیلری (Gallery)"),
            onTap: () {
              Navigator.pop(context);
              _pickImage(ImageSource.gallery);
            },
          ),
        ],
      ),
    );
  }

  Future<void> _submitForm() async {
    if (!_formKey.currentState!.validate()) return;
    if (_image == null) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("براہ کرم تصویر اپلوڈ کریں")));
      return;
    }

    _showTCPage();
  }

  void _showTCPage() {
    bool isAgreed = false;
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text("عہدنامہ ازطالب علم (Rules & Regulations)",
              textAlign: TextAlign.center,
              style: TextStyle(fontWeight: FontWeight.bold)),
          content: SizedBox(
            width: double.maxFinite,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Expanded(
                  child: SingleChildScrollView(
                    child: Text(
                      "میں نے تمام قواعد و ضوابط کا بغور مطالعہ کیا ہے اور میں یہ عہد کرتا ہوں اور اپنے اساتذہ کو یقین دلاتا ہوں کہ ان تمام قواعد و ضوابط اور ان کے علاوہ بھی وقتا فوقتا اساتذہ کی طرف سے ملنے والی ہدایات پر عمل کروں گا اور قطعاً شکایت کا موقع نہی دوں گا۔",
                      style: TextStyle(fontSize: 13, height: 1.6),
                      textAlign: TextAlign.justify,
                      textDirection: TextDirection.rtl,
                    ),
                  ),
                ),
                const Divider(),
                CheckboxListTile(
                  title: const Text(
                      "میں تمام قواعد و ضوضابط سے اتفاق کرتا ہوں (I Agree)",
                      style:
                          TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                  value: isAgreed,
                  onChanged: (val) =>
                      setDialogState(() => isAgreed = val ?? false),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text("کینسل")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                  backgroundColor: primaryGreen, foregroundColor: Colors.white),
              onPressed: isAgreed
                  ? () {
                      Navigator.pop(context);
                      _finalSubmit();
                    }
                  : null,
              child: const Text("جمع کرائیں (Submit)"),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _finalSubmit() async {
    setState(() => _isLoading = true);

    try {
      var request =
          http.MultipartRequest('POST', Uri.parse("${apiUrl}apply.php"));
      request.fields['student_name'] = _nameController.text;
      request.fields['father_name'] = _fnameController.text;
      request.fields['dob'] = _dobController.text;
      request.fields['phone'] = _phoneController.text;
      request.fields['address'] = _addressController.text;
      request.fields['permanent_address'] = _permAddressController.text;
      request.fields['qualification'] = _qualController.text;
      request.fields['last_studied'] = _lastStudiedController.text;
      request.fields['aim'] = _aimController.text;
      request.fields['prev_madaris'] = _prevMadarisController.text;
      request.fields['leave_reason'] = _leaveReasonController.text;
      request.fields['guardian_name'] = _guardianNameController.text;
      request.fields['guardian_address'] = _guardianAddressController.text;
      request.fields['guardian_relation'] = _guardianRelationController.text;
      request.fields['guardian_cnic'] = _guardianCnicController.text;

      request.files
          .add(await http.MultipartFile.fromPath('photo', _image!.path));

      var streamedResponse = await request.send();
      var response = await http.Response.fromStream(streamedResponse);

      final resData = json.decode(response.body);
      if (resData['success'] == true) {
        showSuccessDialog(resData['message']);
      } else {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(resData['message'])));
      }
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text("Error: $e")));
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void showSuccessDialog(String msg) {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Icon(Icons.check_circle, color: Colors.green, size: 60),
        content: Text(msg, textAlign: TextAlign.center),
        actions: [
          TextButton(
              onPressed: () {
                Navigator.pop(context); // Close dialog
                Navigator.pop(context); // Go back home
              },
              child: const Text("ٹھیک ہے"))
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF1EFE7),
      appBar: AppBar(
        title: Row(
          children: [
            const Icon(Icons.mosque, color: Colors.white, size: 30),
            Expanded(
              child: Center(
                child: Text(
                  (currentTenantName.value ?? '').trim().isNotEmpty
                      ? currentTenantName.value!.trim()
                      : "مدرسہ مینجمنٹ سسٹم",
                  style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: accentGold,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 30), // Balance the icon space
          ],
        ),
        centerTitle: true,
        backgroundColor: primaryGreen,
        foregroundColor: Colors.white,
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(15),
              child: Form(
                key: _formKey,
                child: Directionality(
                  textDirection: TextDirection.rtl,
                  child: Column(
                    children: [
                      // Photo Section
                      GestureDetector(
                        onTap: _showImagePickerOptions,
                        child: NeoBox(
                          child: Column(
                            children: [
                              _image == null
                                  ? Icon(Icons.add_a_photo,
                                      size: 50, color: primaryGreen)
                                  : ClipRRect(
                                      borderRadius: BorderRadius.circular(10),
                                      child: Image.file(_image!,
                                          height: 150,
                                          width: 150,
                                          fit: BoxFit.cover)),
                              const SizedBox(height: 10),
                              const Text("طالب علم کی تصویر (Required)",
                                  style:
                                      TextStyle(fontWeight: FontWeight.bold)),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),

                      _buildTextField("نام طالب علم", _nameController,
                          isRequired: true),
                      _buildTextField("ولدیت", _fnameController,
                          isRequired: true),
                      _buildTextField(
                          "تاریخ پیدائش (مثلاً 2010-01-01)", _dobController,
                          isRequired: true,
                          keyboardType: TextInputType.datetime),
                      _buildTextField("موبائل نمبر", _phoneController,
                          isRequired: true, keyboardType: TextInputType.phone),
                      _buildTextField("مکمل رہائشی پتہ", _addressController,
                          isRequired: true, maxLines: 2),

                      const Divider(height: 40),
                      const Text("اضافی معلومات (Optional)",
                          style: TextStyle(
                              color: Colors.grey, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 10),

                      _buildTextField("مستقل پتہ", _permAddressController,
                          maxLines: 2),
                      _buildTextField("تعلیمی قابلیت", _qualController),
                      _buildTextField(
                          "گذشتہ سال کیا پڑھا ہے", _lastStudiedController),
                      _buildTextField("اب کیا پڑھنا چاہتے ہیں", _aimController),
                      _buildTextField("پہلے کن مدارس میں تعلیم حاصل کی",
                          _prevMadarisController),
                      _buildTextField(
                          "پہلا مدرسہ چھوڑنے کی وجہ", _leaveReasonController),

                      const Divider(height: 40),
                      const Text("سرپرست کی معلومات",
                          style: TextStyle(
                              color: Colors.grey, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 10),

                      _buildTextField("سرپرست کا نام", _guardianNameController),
                      _buildTextField(
                          "سرپرست کا پتہ", _guardianAddressController),
                      _buildTextField(
                          "سرپرست سے رشتہ داری", _guardianRelationController),
                      _buildTextField("سرپرست کا شناختی کارڈ نمبر",
                          _guardianCnicController),

                      const SizedBox(height: 30),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(
                            backgroundColor: primaryGreen,
                            foregroundColor: Colors.white,
                            minimumSize: const Size(double.infinity, 50),
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(10))),
                        onPressed: _submitForm,
                        child: const Text("منظوری اور ارسال",
                            style: TextStyle(
                                fontSize: 18, fontWeight: FontWeight.bold)),
                      ),
                      const SizedBox(height: 50),
                    ],
                  ),
                ),
              ),
            ),
    );
  }

  Widget _buildTextField(String label, TextEditingController controller,
      {bool isRequired = false,
      TextInputType keyboardType = TextInputType.text,
      int maxLines = 1}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 15),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        maxLines: maxLines,
        decoration: InputDecoration(
          labelText: label + (isRequired ? " *" : ""),
          border: const OutlineInputBorder(),
          filled: true,
          fillColor: Colors.white,
        ),
        validator: isRequired
            ? (val) => (val == null || val.isEmpty)
                ? "یہ خانہ پُر کرنا لازمی ہے"
                : null
            : null,
      ),
    );
  }
}
