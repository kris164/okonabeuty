@echo off
setlocal
cd /d "%~dp0"
set "PHP=php"
if exist ".runtime\php\php.exe" set "PHP=%~dp0.runtime\php\php.exe"
"%PHP%" setup.php
if errorlevel 1 exit /b 1
"%PHP%" -S 127.0.0.1:3000 -t www router.php
exit /b %errorlevel%
