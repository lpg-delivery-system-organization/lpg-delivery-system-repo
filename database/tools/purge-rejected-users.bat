@echo off
REM Hourly government-ID cleanup wrapper for Windows Task Scheduler.
REM Purges rejected-ID accounts past the grace window and sends reminders.
setlocal
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
"%PHP%" "%~dp0purge-rejected-users.php" >> "%~dp0purge-cleanup.log" 2>&1
endlocal
