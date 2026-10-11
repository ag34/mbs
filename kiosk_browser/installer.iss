; Inno Setup Script for Ciftlikkoy MBS Kiosk Browser

[Setup]
AppName=Çiftlikköy MBS Kiosk
AppVersion=1.0.0
DefaultDirName={localappdata}\CiftlikkoyMBSKiosk
DefaultGroupName=Çiftlikköy MBS Kiosk
OutputDir=dist
OutputBaseFilename=KioskBrowser-Kurulum
Compression=lzma2/ultra64
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64
DisableProgramGroupPage=yes
DisableDirPage=no
PrivilegesRequired=lowest
UninstallDisplayName=Çiftlikköy MBS Kiosk Kaldır

[Files]
Source: "dist\KioskBrowser\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autodesktop}\MBS Kiosk Tarayıcı"; Filename: "{app}\KioskBrowser.exe"
Name: "{autoprograms}\MBS Kiosk Tarayıcı"; Filename: "{app}\KioskBrowser.exe"

[Run]
Filename: "{app}\KioskBrowser.exe"; Description: "MBS Kiosk Tarayıcıyı Başlat"; Flags: nowait postinstall skipifsilent
