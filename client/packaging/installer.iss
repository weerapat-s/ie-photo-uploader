#define AppVersion GetEnv("APP_VERSION")
[Setup]
AppName=IE-PHOTO Uploader
AppVersion={#AppVersion}
DefaultDirName={autopf}\IE-PHOTO Uploader
DefaultGroupName=IE-PHOTO Uploader
OutputDir=..\dist
OutputBaseFilename=IE-PHOTO-Uploader-Setup
PrivilegesRequired=lowest
Compression=lzma2
SolidCompression=yes
[Files]
Source: "..\dist\IE-PHOTO Uploader\*"; DestDir: "{app}"; Flags: recursesubdirs
[Icons]
Name: "{group}\IE-PHOTO Uploader"; Filename: "{app}\IE-PHOTO Uploader.exe"
Name: "{userdesktop}\IE-PHOTO Uploader"; Filename: "{app}\IE-PHOTO Uploader.exe"
