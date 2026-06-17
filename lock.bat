@echo off
REM K-DOCS - Agent Lock Manager
REM Wrapper pour agent_lock.php

cd /d "%~dp0"
php agent_lock.php %*
