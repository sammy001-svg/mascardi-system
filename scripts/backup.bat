@echo off
REM Mascardi System — Automated Database Backup
REM Schedule this file in Windows Task Scheduler to run daily

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=%~dp0backup.php

echo Running database backup...
"%PHP_PATH%" "%SCRIPT_PATH%"

IF %ERRORLEVEL% EQU 0 (
    echo Backup completed successfully.
) ELSE (
    echo Backup FAILED. Check the error above.
)
