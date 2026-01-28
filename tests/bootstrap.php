<?php
/**
 * PHPUnit Bootstrap
 */

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define test environment
define('KDOCS_TESTING', true);
