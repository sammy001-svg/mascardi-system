<?php
/**
 * PHPUnit bootstrap — loads the application environment for tests.
 * Run: vendor/bin/phpunit
 */

// Point to a test database in .env (e.g. DB_NAME=mascardi_test)
putenv('APP_ENV=testing');

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
