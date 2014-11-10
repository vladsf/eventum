#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/init.php';

if (!defined('APP_SQL_PATCHES_PATH')) {
    define('APP_SQL_PATCHES_PATH', APP_PATH . '/upgrade/patches');
}

$in_setup = defined('IN_SETUP');
$dbconfig = DB_Helper::getConfig();

function db_getAll($query)
{
    return DB_Helper::getInstance()->getAll($query);
}

function db_getOne($query)
{
    return DB_Helper::getInstance()->getOne($query);
}

function db_getCol($query)
{
    return DB_Helper::getInstance()->getColumn($query);
}

function db_query($query)
{
    return DB_Helper::getInstance()->query($query);
}

function exec_sql_file($input_file)
{
    if (!file_exists($input_file) && !is_readable($input_file)) {
        throw new RuntimeException("Can't read file: $input_file");
    }

    // use *.php for complex updates
    if (substr($input_file, -4) == '.php') {
        $queries = array();
        require $input_file;
    } else {
        $queries = explode(';', file_get_contents($input_file));
    }

    foreach ($queries as $query) {
        $query = trim($query);
        if ($query) {
            db_query($query);
        }
    }
}

function read_patches($update_path)
{
    $handle = opendir($update_path);
    if (!$handle) {
        throw new RuntimeException("Could not read: $update_path");
    }
    while (false !== ($file = readdir($handle))) {
        $number = substr($file, 0, strpos($file, '_'));
        if (in_array(substr($file, -4), array('.sql', '.php')) && is_numeric($number)) {
            $files[(int)$number] = trim($update_path) . (substr(trim($update_path), -1) == '/' ? '' : '/') . $file;
        }
    }
    closedir($handle);
    ksort($files);

    return $files;
}

function patch_database()
{
    // sanity check. check that the version table exists.
    $last_patch = db_getOne("SELECT ver_version FROM {{%version}}");
    if (!isset($last_patch)) {
        // insert initial value
        db_query("INSERT INTO {{%version}} SET ver_version=0");
        $last_patch = 0;
    }

    $files = read_patches(APP_SQL_PATCHES_PATH);

    $addCount = 0;
    foreach ($files as $number => $file) {
        if ($number > $last_patch) {
            echo "* Applying patch: ", $number, " (", basename($file), ")\n";
            exec_sql_file($file);
            db_query("UPDATE {{%version}} SET ver_version=$number");
            $addCount++;
        }
    }

    $version = max(array_keys($files));
    if ($addCount == 0) {
        echo "* Your database is already up-to-date. Version $version\n";
    } else {
        echo "* Your database is now up-to-date. Updated from $last_patch to $version\n";
    }
}

if (!$in_setup && php_sapi_name() != 'cli') {
    echo "<pre>\n";
}

try {
    patch_database();
} catch (Exception $e) {
    if ($in_setup) {
        throw $e;
    }
    echo $e->getMessage(), "\n";
    exit(1);
}

if (!$in_setup && php_sapi_name() != 'cli') {
    echo "</pre>\n";
}
