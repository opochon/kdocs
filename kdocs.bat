@echo off
REM K-DOCS - CLI Commands
REM Usage: kdocs.bat [command] [args]

setlocal enabledelayedexpansion
cd /d "%~dp0"

set CMD=%1
if "%CMD%"=="" goto :help

if "%CMD%"=="migrate" goto :migrate
if "%CMD%"=="migrate:status" goto :migrate_status
if "%CMD%"=="migrate:rollback" goto :migrate_rollback
if "%CMD%"=="migrate:create" goto :migrate_create
if "%CMD%"=="backup" goto :backup
if "%CMD%"=="backup:list" goto :backup_list
if "%CMD%"=="backup:verify" goto :backup_verify
if "%CMD%"=="backup:restore" goto :backup_restore
if "%CMD%"=="config:check" goto :config_check
if "%CMD%"=="errors" goto :errors
if "%CMD%"=="errors:clear" goto :errors_clear
if "%CMD%"=="test" goto :test
if "%CMD%"=="help" goto :help

echo Unknown command: %CMD%
goto :help

:help
echo.
echo K-DOCS CLI
echo ==========
echo.
echo Commands:
echo   migrate              Run pending migrations
echo   migrate:status       Show migration status
echo   migrate:rollback     Rollback last migration
echo   migrate:create NAME  Create new migration
echo.
echo   backup               Create database backup
echo   backup:list          List available backups
echo   backup:verify FILE   Verify backup integrity
echo   backup:restore FILE  Restore from backup
echo.
echo   config:check         Validate configuration
echo   errors               Show recent errors
echo   errors:clear         Clear error logs
echo.
echo   test                 Run test suite
echo.
goto :end

:migrate
php -r "
require 'vendor/autoload.php';
\KDocs\Core\Database::getInstance();
\$m = new \KDocs\Core\Migrations();
\$executed = \$m->migrate();
if (empty(\$executed)) {
    echo 'Nothing to migrate.' . PHP_EOL;
} else {
    echo 'Executed ' . count(\$executed) . ' migration(s):' . PHP_EOL;
    foreach (\$executed as \$name) echo '  - ' . \$name . PHP_EOL;
}
"
goto :end

:migrate_status
php -r "
require 'vendor/autoload.php';
\KDocs\Core\Database::getInstance();
\$m = new \KDocs\Core\Migrations();
\$status = \$m->status();
echo 'Migration Status:' . PHP_EOL;
echo '  Total:    ' . \$status['total'] . PHP_EOL;
echo '  Executed: ' . \$status['executed'] . PHP_EOL;
echo '  Pending:  ' . \$status['pending'] . PHP_EOL;
if (!empty(\$status['pending_list'])) {
    echo PHP_EOL . 'Pending migrations:' . PHP_EOL;
    foreach (\$status['pending_list'] as \$name) echo '  - ' . \$name . PHP_EOL;
}
"
goto :end

:migrate_rollback
php -r "
require 'vendor/autoload.php';
\KDocs\Core\Database::getInstance();
\$m = new \KDocs\Core\Migrations();
\$rolled = \$m->rollback();
if (empty(\$rolled)) {
    echo 'Nothing to rollback.' . PHP_EOL;
} else {
    echo 'Rolled back:' . PHP_EOL;
    foreach (\$rolled as \$name) echo '  - ' . \$name . PHP_EOL;
}
"
goto :end

:migrate_create
if "%2"=="" (
    echo Usage: kdocs.bat migrate:create NAME
    goto :end
)
php -r "
require 'vendor/autoload.php';
\$m = new \KDocs\Core\Migrations(null);
\$filename = \$m->create('%2');
echo 'Created: database/migrations/' . \$filename . PHP_EOL;
"
goto :end

:backup
php -r "
require 'vendor/autoload.php';
\KDocs\Core\Database::getInstance();
\$b = new \KDocs\Services\BackupService();
echo 'Creating backup...' . PHP_EOL;
\$result = \$b->create();
echo 'Backup created: ' . \$result['filename'] . PHP_EOL;
echo 'Size: ' . round(\$result['size'] / 1024, 1) . ' KB' . PHP_EOL;
echo 'Tables: ' . \$result['tables'] . PHP_EOL;
"
goto :end

:backup_list
php -r "
require 'vendor/autoload.php';
\$b = new \KDocs\Services\BackupService();
\$list = \$b->list();
if (empty(\$list)) {
    echo 'No backups found.' . PHP_EOL;
} else {
    echo 'Available backups:' . PHP_EOL;
    foreach (\$list as \$backup) {
        \$size = round(\$backup['size'] / 1024, 1);
        echo '  ' . \$backup['filename'] . ' (' . \$size . ' KB) - ' . \$backup['created_at'] . PHP_EOL;
    }
}
"
goto :end

:backup_verify
if "%2"=="" (
    echo Usage: kdocs.bat backup:verify FILENAME
    goto :end
)
php -r "
require 'vendor/autoload.php';
\$b = new \KDocs\Services\BackupService();
\$result = \$b->verify('%2');
if (\$result['valid']) {
    echo 'Backup is valid!' . PHP_EOL;
    echo 'Tables: ' . \$result['tables'] . PHP_EOL;
    echo 'Size: ' . round(\$result['size'] / 1024, 1) . ' KB' . PHP_EOL;
} else {
    echo 'Backup INVALID: ' . \$result['error'] . PHP_EOL;
}
"
goto :end

:backup_restore
if "%2"=="" (
    echo Usage: kdocs.bat backup:restore FILENAME
    goto :end
)
echo WARNING: This will overwrite the current database!
set /p CONFIRM=Are you sure? (yes/no): 
if not "%CONFIRM%"=="yes" (
    echo Cancelled.
    goto :end
)
php -r "
require 'vendor/autoload.php';
\KDocs\Core\Database::getInstance();
\$b = new \KDocs\Services\BackupService();
echo 'Restoring backup...' . PHP_EOL;
\$b->restore('%2');
echo 'Restore completed!' . PHP_EOL;
"
goto :end

:config_check
php -r "
require 'vendor/autoload.php';
\$result = \KDocs\Core\ConfigValidator::check();
if (\$result['valid']) {
    echo 'Configuration OK' . PHP_EOL;
} else {
    echo 'Configuration ERRORS:' . PHP_EOL;
    foreach (\$result['errors'] as \$e) echo '  ERROR: ' . \$e . PHP_EOL;
}
if (!empty(\$result['warnings'])) {
    echo PHP_EOL . 'Warnings:' . PHP_EOL;
    foreach (\$result['warnings'] as \$w) echo '  WARNING: ' . \$w . PHP_EOL;
}
"
goto :end

:errors
php -r "
require 'vendor/autoload.php';
\$errors = \KDocs\Core\ErrorTracker::getRecent(20);
if (empty(\$errors)) {
    echo 'No recent errors.' . PHP_EOL;
} else {
    echo 'Recent errors (' . count(\$errors) . '):' . PHP_EOL;
    foreach (\$errors as \$e) {
        echo PHP_EOL . '[' . \$e['timestamp'] . '] ' . \$e['level'] . PHP_EOL;
        echo '  ' . \$e['message'] . PHP_EOL;
        if (isset(\$e['file'])) echo '  at ' . \$e['file'] . ':' . (\$e['line'] ?? '') . PHP_EOL;
    }
}
"
goto :end

:errors_clear
php -r "
require 'vendor/autoload.php';
\$removed = \KDocs\Core\ErrorTracker::cleanup(0);
echo 'Cleared ' . \$removed . ' error(s).' . PHP_EOL;
"
goto :end

:test
call test.bat %2
goto :end

:end
