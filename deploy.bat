@echo off
setlocal enabledelayedexpansion
REM ============================================
REM CONFIGURATION - adjust these paths as needed
REM ============================================
REM  Precede any special character (like &, |, ^, <, >) with a caret ^.
SET "PLUIGN_NAME=Simply Static Export ^& Notify"
SET "PLUGIN_TAGS=simplystatic, automation, export, static, "
SET "HEADER_SCRIPT=C:\Ignore By Avast\0. PATHED Items\Plugins\deployscripts\myplugin_headers.php"
SET "PLUGIN_DIR=C:\Users\Nathan\Git\simply-static-notify\simply-static-export-notify"
IF "%PLUGIN_DIR:~-1%"=="\" SET "PLUGIN_DIR=%PLUGIN_DIR:~0,-1%"
SET "PLUGIN_FILE=%PLUGIN_DIR%\simply-static-export-notify.php"
SET "CHANGELOG_FILE=C:\Users\Nathan\Git\rup-changelogs\simply-static-export-notify.txt"
SET "STATIC_FILE=static.txt"
SET "DEST_DIR=D:\updater.reallyusefulplugins.com\plugin-updates\custom-packages\"
REM ============================================
REM VERIFY REQUIRED FILES EXIST
REM ============================================
IF NOT EXIST "%PLUGIN_FILE%" (
    echo Plugin file not found: %PLUGIN_FILE%
    pause
    goto :EOF
)
IF NOT EXIST "%CHANGELOG_FILE%" (
    echo Changelog file not found: %CHANGELOG_FILE%
    pause
    goto :EOF
)
IF NOT EXIST "%STATIC_FILE%" (
    echo Static readme file not found: %STATIC_FILE%
    pause
    goto :EOF
)

REM ============================================
REM Running Header Update
REM ============================================
php "%HEADER_SCRIPT%" "%PLUGIN_FILE%"

REM — extract “Requires at least” —
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Requires at least:" "%PLUGIN_FILE%"') do (
  for /f "tokens=* delims= " %%X in ("%%A") do set "requires_at_least=%%X"
)

REM — extract “Tested up to” —
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Tested up to:" "%PLUGIN_FILE%"') do (
  for /f "tokens=* delims= " %%X in ("%%A") do set "tested_up_to=%%X"
)

REM — extract “Version” —
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Version:" "%PLUGIN_FILE%"') do (
  for /f "tokens=* delims= " %%X in ("%%A") do set "version=%%X"
)

REM — extract “Requires PHP” —
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Requires PHP:" "%PLUGIN_FILE%"') do (
  for /f "tokens=* delims= " %%X in ("%%A") do set "requires_php=%%X"
)



REM ============================================
REM CREATE THE WORDPRESS.ORG COMPATIBLE readme.txt
REM ============================================
SET "README=%PLUGIN_DIR%\readme.txt"
SET "TEMP_README=%PLUGIN_DIR%\readme_temp.txt"

REM Create the dynamic header section
(
    echo === %PLUIGN_NAME% ===
    echo Contributors: reallyusefulplugins
    echo Donate link: https://reallyusefulplugins.com/donate
    echo Tags: %PLUGIN_TAGS%
    echo Requires at least: %requires_at_least%
    echo Tested up to: %tested_up_to%
    echo Stable tag: %version%
    echo Requires PHP: %requires_php%
    echo License: GPL-2.0-or-later
    echo License URI: https://www.gnu.org/licenses/gpl-2.0.html
    echo.
) > "%TEMP_README%"

REM Append the static sections (Description, Installation, FAQ, Screenshots, Upgrade Notice, etc.)
type "%STATIC_FILE%" >> "%TEMP_README%"

REM Append the Changelog header and content
(
    echo.
    echo == Changelog ==
) >> "%TEMP_README%"

type "%CHANGELOG_FILE%" >> "%TEMP_README%"

REM Backup any existing readme.txt
if exist "%README%" (
    copy "%README%" "%README%.bak" >nul
)

REM Replace or create the readme.txt file with the updated version
move /Y "%TEMP_README%" "%README%"
echo readme.txt updated successfully.
echo.

@echo off
REM ============================================
REM ZIP THE PLUGIN FOLDER WITH ITS DIRECTORY USING 7‑ZIP
REM ============================================

REM Set the full path to 7-Zip executable
SET "SEVENZIP=C:\Program Files\7-Zip\7z.exe"

REM Extract the parent directory and the folder name from PLUGIN_DIR
for %%a in ("%PLUGIN_DIR%") do (
  set "PARENT_DIR=%%~dpa"
  set "FOLDER_NAME=%%~nxa"
)

echo Parent Directory: %PARENT_DIR%
echo Folder Name: %FOLDER_NAME%

REM Define the ZIP file to be created in the parent directory
SET "ZIP_FILE=%PARENT_DIR%%FOLDER_NAME%.zip"
echo Zip file will be: %ZIP_FILE%

REM Change directory to the parent directory of the plugin folder
pushd "%PARENT_DIR%"

REM Use 7‑Zip to compress the entire folder, ensuring the folder is at the root of the zip
"%SEVENZIP%" a -tzip "%ZIP_FILE%" "%FOLDER_NAME%"

popd
echo Plugin folder zipped to %ZIP_FILE%.

REM ============================================
REM COPY THE ZIP FILE TO THE DESTINATION FOLDER
REM ============================================
copy "%ZIP_FILE%" "%DEST_DIR%"
echo Zip file copied to %DEST_DIR%.
pause
