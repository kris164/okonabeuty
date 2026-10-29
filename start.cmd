@echo off
setlocal
cd /d "%~dp0"
set "NODE=node"
if exist ".runtime\node.exe" set "NODE=%~dp0.runtime\node.exe"
"%NODE%" setup.mjs
if errorlevel 1 exit /b 1
"%NODE%" --env-file=.env server.mjs
exit /b %errorlevel%
