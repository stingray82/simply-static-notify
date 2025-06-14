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
SET "README=%PLUGIN_DIR%\readme.txt"
SET "TEMP_README=%PLUGIN_DIR%\readme_temp.txt"
SET "DEST_DIR=D:\updater.reallyusefulplugins.com\plugin-updates\custom-packages"

SET "DEPLOY_TARGET=private"  REM github or private
REM GitHub settings
SET "GITHUB_REPO=stingray82/RUPChangelog"
SET "TOKEN_FILE=C:\Ignore By Avast\0. PATHED Items\Plugins\deployscripts\github_token.txt"
SET /P GITHUB_TOKEN=<"%TOKEN_FILE%"
SET "ZIP_NAME=rup-changelogger.zip"

REM ─────────────────────────────────────────────────────
REM VERIFY REQUIRED FILES
REM ─────────────────────────────────────────────────────
IF NOT EXIST "%PLUGIN_FILE%" (
    echo ❌ Plugin file not found: %PLUGIN_FILE%
    pause & exit /b
)
IF NOT EXIST "%CHANGELOG_FILE%" (
    echo ❌ Changelog file not found: %CHANGELOG_FILE%
    pause & exit /b
)
IF NOT EXIST "%STATIC_FILE%" (
    echo ❌ Static readme file not found: %STATIC_FILE%
    pause & exit /b
)

REM ─────────────────────────────────────────────────────
REM RUN HEADER SCRIPT
REM ─────────────────────────────────────────────────────
php "%HEADER_SCRIPT%" "%PLUGIN_FILE%"

REM Extract metadata from plugin headers
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Requires at least:" "%PLUGIN_FILE%"') do for /f "tokens=* delims= " %%X in ("%%A") do set "requires_at_least=%%X"
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Tested up to:" "%PLUGIN_FILE%"') do for /f "tokens=* delims= " %%X in ("%%A") do set "tested_up_to=%%X"
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Version:" "%PLUGIN_FILE%"') do for /f "tokens=* delims= " %%X in ("%%A") do set "version=%%X"
for /f "tokens=2* delims=:" %%A in ('findstr /C:"Requires PHP:" "%PLUGIN_FILE%"') do for /f "tokens=* delims= " %%X in ("%%A") do set "requires_php=%%X"

REM ─────────────────────────────────────────────────────
REM CREATE README.TXT
REM ─────────────────────────────────────────────────────
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

type "%STATIC_FILE%" >> "%TEMP_README%"
echo. >> "%TEMP_README%"
echo == Changelog == >> "%TEMP_README%"
type "%CHANGELOG_FILE%" >> "%TEMP_README%"

IF EXIST "%README%" copy "%README%" "%README%.bak" >nul
move /Y "%TEMP_README%" "%README%"

REM ─────────────────────────────────────────────────────
REM GIT COMMIT AND PUSH CHANGES
REM ─────────────────────────────────────────────────────
pushd "%PLUGIN_DIR%"
git add -A

git diff --cached --quiet
IF %ERRORLEVEL% EQU 1 (
    git commit -m "Version %version% Release"
    git push origin main
    echo ✅ Git commit and push complete.
) ELSE (
    echo ⚠️ No changes to commit.
)
popd



REM ─────────────────────────────────────────────────────
REM ZIP PLUGIN FOLDER
REM ─────────────────────────────────────────────────────
SET "SEVENZIP=C:\Program Files\7-Zip\7z.exe"
for %%a in ("%PLUGIN_DIR%") do (
  set "PARENT_DIR=%%~dpa"
  set "FOLDER_NAME=%%~nxa"
)
SET "ZIP_FILE=%PARENT_DIR%%ZIP_NAME%"

pushd "%PARENT_DIR%"
"%SEVENZIP%" a -tzip "%ZIP_FILE%" "%FOLDER_NAME%"
popd
echo ✅ Zipped to: %ZIP_FILE%

REM ─────────────────────────────────────────────────────
REM DEPLOY LOGIC
REM ─────────────────────────────────────────────────────
IF /I "%DEPLOY_TARGET%"=="private" (
    echo 🔄 Deploying to private server...
    copy "%ZIP_FILE%" "%DEST_DIR%"
    echo ✅ Copied to %DEST_DIR%
) ELSE IF /I "%DEPLOY_TARGET%"=="github" (
    echo 🚀 Deploying to GitHub...

    setlocal enabledelayedexpansion
    set "RELEASE_TAG=v%version%"
    set "RELEASE_NAME=%version%"
    set "BODY_FILE=%TEMP%\changelog_body.json"
    set "CHANGELOG_BODY="

    echo Creating body file...

    for /f "usebackq delims=" %%l in ("%CHANGELOG_FILE%") do (
        set "line=%%l"
        set "line=!line:"=\\\"!"
        set "CHANGELOG_BODY=!CHANGELOG_BODY!!line!\n"
    )
    set "CHANGELOG_BODY=!CHANGELOG_BODY:~0,-2!"

    (
        echo {
        echo   "tag_name": "!RELEASE_TAG!",
        echo   "name": "!RELEASE_NAME!",
        echo   "body": "!CHANGELOG_BODY!",
        echo   "draft": false,
        echo   "prerelease": false
        echo }
    ) > "!BODY_FILE!"

    echo -------- BEGIN JSON BODY --------
    type "!BODY_FILE!"
    echo -------- END JSON BODY ----------

    REM Try to get existing release by tag
    curl -s -w "%%{http_code}" -o "%TEMP%\github_release_response.json" ^
        -H "Authorization: token %GITHUB_TOKEN%" ^
        -H "Accept: application/vnd.github+json" ^
        https://api.github.com/repos/%GITHUB_REPO%/releases/tags/!RELEASE_TAG! > "%TEMP%\github_http_status.txt"

    set /p HTTP_STATUS=<"%TEMP%\github_http_status.txt"

    set "RELEASE_ID="

    if "!HTTP_STATUS!"=="200" (
        for /f "tokens=2 delims=:," %%i in ('findstr /C:"\"id\"" "%TEMP%\github_release_response.json"') do (
            if not defined RELEASE_ID set "RELEASE_ID=%%i"
        )
        set "RELEASE_ID=!RELEASE_ID: =!"
        set "RELEASE_ID=!RELEASE_ID:,=!"
        echo 📝 Release already exists. Updating body...

        curl -s -X PATCH "https://api.github.com/repos/%GITHUB_REPO%/releases/!RELEASE_ID!" ^
            -H "Authorization: token %GITHUB_TOKEN%" ^
            -H "Accept: application/vnd.github+json" ^
            -H "Content-Type: application/json" ^
            --data-binary "@!BODY_FILE!"
    ) else (
        echo 🆕 Creating new release...

        curl -s -X POST "https://api.github.com/repos/%GITHUB_REPO%/releases" ^
            -H "Authorization: token %GITHUB_TOKEN%" ^
            -H "Accept: application/vnd.github+json" ^
            -H "Content-Type: application/json" ^
            --data-binary "@!BODY_FILE!" > "%TEMP%\github_release_response.json"

        for /f "tokens=2 delims=:," %%i in ('findstr /C:"\"id\"" "%TEMP%\github_release_response.json"') do (
            if not defined RELEASE_ID set "RELEASE_ID=%%i"
        )
        set "RELEASE_ID=!RELEASE_ID: =!"
        set "RELEASE_ID=!RELEASE_ID:,=!"
    )

    IF NOT DEFINED RELEASE_ID (
        echo ❌ Could not determine release ID.
        type "%TEMP%\github_release_response.json"
        exit /b
    )

    echo ✅ Using Release ID: !RELEASE_ID!

    curl -s -X POST "https://uploads.github.com/repos/%GITHUB_REPO%/releases/!RELEASE_ID!/assets?name=%ZIP_NAME%" ^
        -H "Authorization: token %GITHUB_TOKEN%" ^
        -H "Accept: application/vnd.github+json" ^
        -H "Content-Type: application/zip" ^
        --data-binary "@%ZIP_FILE%"

    endlocal
)

echo.
