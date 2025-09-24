@echo off
REM This script uses 7-Zip to create a zip archive.
REM Ensure 7-Zip is installed and 7z.exe is in your PATH, or set the correct path below.

REM Set the path to 7z.exe if it's not in your PATH.  If it is in your PATH, this line is not needed.
set "SEVENZIP_PATH=C:\Program Files\7-Zip\7z.exe"

REM Set the name of the zip file you want to create.
set "ZIP_FILENAME=ncoa-blogs.zip"
set "OUTPUT_FOLDER=release"

REM Set the files and folders to include in the zip file.
set "FILES_TO_ADD=index.php ncoa-blogs.php readme.md updater.php assets languages"

REM Check if 7z.exe exists
if not exist "%SEVENZIP_PATH%" (
    echo Error: 7-Zip is not installed or the path is incorrect.
    echo Please install 7-Zip or update the SEVENZIP_PATH variable in the script.
    pause
    exit /b 1
)

REM Create the output directory if it does not exist
if not exist "%OUTPUT_FOLDER%" (
    echo Creating directory %OUTPUT_FOLDER%
    mkdir "%OUTPUT_FOLDER%"
    if %errorlevel% neq 0 (
        echo Failed to create directory %OUTPUT_FOLDER%
        pause
        exit /b 1
    )
)

echo Adding files and folders to %ZIP_FILENAME% in %OUTPUT_FOLDER%...
echo Files and folders being added: %FILES_TO_ADD%

REM Create the zip archive in the specified output folder.
"%SEVENZIP_PATH%" a -tzip "%OUTPUT_FOLDER%\%ZIP_FILENAME%" %FILES_TO_ADD%

REM Check the result of the zip operation.
if %errorlevel% equ 0 (
    echo Successfully created %OUTPUT_FOLDER%\%ZIP_FILENAME%.
) else (
    echo An error occurred while creating %OUTPUT_FOLDER%\%ZIP_FILENAME%.
    echo Check the command and ensure all files and folders exist.
)

pause
exit /b 0