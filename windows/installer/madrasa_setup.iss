; =====================================================================
; Inno Setup Script برائے مدرسہ اشرف المدارس مینجمنٹ سسٹم
; تیار کردہ: اینٹی گریوٹی (Pair Programming Assistant)
; =====================================================================

#define MyAppName "مدرسہ مینجمنٹ سسٹم"
#define MyAppEnglishName "Madrasa Management System"
#define MyAppVersion "5.7.82"
#define MyAppPublisher "Ashraf-ul-Madaris"
#define MyAppURL "https://github.com"
#define MyAppExeName "madrasa_app.exe"
#define MyAppIcon "..\runner\resources\app_icon.ico"

; فلٹر ونڈوز بلڈ کا راستہ (Flutter 3.16+ کے لیے x64 فولڈر بنتا ہے)
; اگر آپ کا پرانا بلڈ ہو تو "..\..\build\windows\runner\Release" کر لیں
#define MyAppBuildDir "..\..\build\windows\x64\runner\Release"

[Setup]
; انفرادی اور مستقل AppId تاکہ اپڈیٹس درست طریقے سے ایک ہی فولڈر میں انسٹال ہوں
AppId={{8B1A2C3D-4E5F-6A7B-8C9D-0E1F2A3B4C5D}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} v{#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
DefaultDirName={autopf}\MadrasaApp
DefaultGroupName={#MyAppEnglishName}
AllowNoIcons=yes
OutputDir=..\..\Output
OutputBaseFilename=Madrasa_App_Setup_v{#MyAppVersion}
SetupIconFile={#MyAppIcon}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64compatible
UninstallDisplayIcon={app}\{#MyAppExeName}
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked

[Files]
; مرکزی ایپلیکیشن ایگزیکیوٹیبل
Source: "{#MyAppBuildDir}\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion

; فلٹر اور سی پلس پلس کی تمام DLLs (بشمول flutter_windows.dll، sqlite3.dll وغیرہ)
Source: "{#MyAppBuildDir}\*.dll"; DestDir: "{app}"; Flags: ignoreversion

; ڈیٹا ڈائریکٹری (بشمول flutter_assets، icudtl.dat، فونٹس، تصاویر)
Source: "{#MyAppBuildDir}\data\*"; DestDir: "{app}\data"; Flags: ignoreversion recursesubdirs createallsubdirs

; نوٹ: اگر کوئی دیگر اضافی فائلز یا ڈائریکٹریز ریلیز میں بنیں تو انہیں بھی شامل رکھیں
Source: "{#MyAppBuildDir}\*"; DestDir: "{app}"; Flags: ignoreversion; Excludes: "{#MyAppExeName},*.dll,data\*"

[Icons]
; اسٹارٹ مینو اور ڈیسک ٹاپ کے شارٹ کٹس
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; IconFilename: "{app}\{#MyAppExeName}"
Name: "{group}\Uninstall {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; IconFilename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
; انسٹالیشن کے اختتام پر ایپلیکیشن چلانے کا آپشن
Description: "{cm:LaunchProgram,{#StringChange(MyAppName, '&', '&&')}}"; Filename: "{app}\{#MyAppExeName}"; Flags: nowait postinstall skipifsilent
