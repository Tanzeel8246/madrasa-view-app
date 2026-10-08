; ==============================================================================
; جامعہ اشرف المدارس - مدرسہ و مکتب مینجمنٹ سسٹم
; Inno Setup Script برائے ونڈوز انسٹال ایبل سیٹ اپ
; ==============================================================================

#define MyAppName "مدرسہ و مکتب مینجمنٹ سسٹم"
#define MyAppEnglishName "Madrasa & Maktab Management System"
#define MyAppVersion "5.7.85"
#define MyAppPublisher "جامعہ اشرف المدارس (Jamia Ashraf-ul-Madaris)"
#define MyAppExeName "madrasa_app.exe"
#define MyAppIcon "windows\runner\resources\app_icon.ico"

[Setup]
; ایپلیکیشن کی بنیادی شناخت اور معلومات
AppId={{D37E8C12-9F1B-4A2B-8C4D-998877665544}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} v{#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\MadrasaApp
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes

; ونڈوز کی جدید آرکیٹیکچر (64-bit)
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible

; انسٹالر فائل کی لوکیشن اور نام
OutputDir=installers
OutputBaseFilename=Madrasa_App_Setup_v{#MyAppVersion}
SetupIconFile={#MyAppIcon}
UninstallDisplayIcon={app}\{#MyAppExeName}

; انسٹالیشن کا جدید لک اور اسٹائل
WizardStyle=modern
Compression=lzma2/ultra64
SolidCompression=yes
PrivilegesRequired=lowest
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "ڈیسک ٹاپ پر شارٹ کٹ بنائیں (Create Desktop Shortcut)"; GroupDescription: "اضافی شارٹ کٹس:"; Flags: unchecked

[Files]
; ونڈوز ریلیز بلڈ کی تمام فائلز (Executable, DLLs, Data directory)
; اگر 64-bit کا ڈیفالٹ پاتھ ہے:
Source: "build\windows\x64\runner\Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
; متبادل پاتھ (اگر پرانے فلٹر ورژن کا پاتھ ہو):
; Source: "build\windows\runner\Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
; اسٹارٹ مینو اور ڈیسک ٹاپ شارٹ کٹس
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; IconFilename: "{app}\{#MyAppExeName}"
Name: "{group}\ایپ کو ان انسٹال کریں (Uninstall)"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon; IconFilename: "{app}\{#MyAppExeName}"

[Run]
; انسٹالیشن کے اختتام پر ایپلیکیشن چلانے کا آپشن
Filename: "{app}\{#MyAppExeName}"; Description: "{#MyAppName} ابھی چلائیں (Launch Application)"; Flags: nowait postinstall skipifsilent
