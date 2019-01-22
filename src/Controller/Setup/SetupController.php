<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Controller\Setup;

use Auth;
use Date_Helper;
use DB_Helper;
use Eventum\AppInfo;
use Eventum\Controller\BaseController;
use Eventum\Monolog\Logger;
use Eventum\Setup\DatabaseSetup;
use Eventum\Setup\SetupException;
use IntlCalendar;
use Misc;
use RuntimeException;
use Setup;
use Symfony\Component\Filesystem\Filesystem;

class SetupController extends BaseController
{
    /** @var string */
    protected $tpl_name = 'setup.tpl.html';

    /** @var string */
    private $cat;

    protected function configure()
    {
        $request = $this->getRequest();
        $this->cat = $request->request->get('cat');
    }

    protected function canAccess()
    {
        return true;
    }

    protected function defaultAction()
    {
        list($warnings, $errors) = $this->checkRequirements();
        if ($warnings || $errors) {
            Misc::displayRequirementErrors(array_merge($errors, $warnings), 'Eventum Setup');
            if ($errors) {
                exit(1);
            }
        }

        if ($this->cat === 'install') {
            $res = $this->install();
            $this->tpl->assign('result', $res);
            // check for the optional IMAP extension
            $this->tpl->assign('is_imap_enabled', function_exists('imap_open'));
        }
    }

    protected function prepareTemplate()
    {
        $appInfo = new AppInfo();
        $request = $this->getRequest();
        $relative_url = rtrim(dirname($request->getBaseUrl()), '/') . '/';
        $this->tpl->assign(
            [
                'core' => [
                    'rel_url' => $relative_url,
                    'app_title' => APP_NAME,
                    'app_version' => $appInfo->getVersion(),
                    'php_version' => PHP_VERSION,
                    'template_id' => 'setup',
                ],
                'userstyle' => '',
                'userscript' => '',
                'is_secure' => $request->isSecure(),
                'zones' => Date_Helper::getTimezoneList(),
                'default_timezone' => $this->getTimezone(),
                'default_weekday' => $this->getFirstWeekday(),
            ]
        );
    }

    protected function displayTemplate($tpl_name = null)
    {
        $this->tpl->displayTemplate(false);
    }

    /**
     * Checks for $file for write permission.
     *
     * IMPORTANT: if the file does not exist, an empty file is created.
     */
    private function checkPermissions($file, $desc, $is_directory = false)
    {
        clearstatcache();
        if (!file_exists($file)) {
            if (!$is_directory) {
                // try to create the file ourselves then
                $fp = @fopen($file, 'w');
                if (!$fp) {
                    return $this->getPermissionError($file, $desc, $is_directory, false);
                }
                @fclose($fp);
            } else {
                if (!@mkdir($file)) {
                    return $this->getPermissionError($file, $desc, $is_directory, false);
                }
            }
        }
        clearstatcache();
        if (!is_writable($file)) {
            if (stripos(PHP_OS, 'win') === false) {
                // let's try to change the permissions ourselves
                @chmod($file, 0644);
                clearstatcache();
                if (!is_writable($file)) {
                    return $this->getPermissionError($file, $desc, $is_directory, true);
                }
            } else {
                return $this->getPermissionError($file, $desc, $is_directory, true);
            }
        }
        if (stripos(PHP_OS, 'win') !== false) {
            // need to check whether we can really create files in this directory or not
            // since is_writable() is not trustworthy on windows platforms
            if (is_dir($file)) {
                $fp = @fopen($file . '/dummy.txt', 'w');
                if (!$fp) {
                    return "$desc is not writable";
                }
                @fwrite($fp, 'test');
                @fclose($fp);
                // clean up after ourselves
                @unlink($file . '/dummy.txt');
            }
        }

        return '';
    }

    private function getPermissionError($file, $desc, $is_directory, $exists)
    {
        if ($is_directory) {
            $title = 'Directory';
        } else {
            $title = 'File';
        }
        $error = "$title <b>'" . $file . ($is_directory ? '/' : '') . "'</b> ";

        if (!$exists) {
            $error .= "does not exist. Please create the $title and reload this page.";
        } else {
            $error .= "is not writeable. Please change this $title to be writeable by the web server.";
        }

        return $error;
    }

    private function checkRequirements()
    {
        $errors = [];
        $warnings = [];

        $requiredExtensions = [
            'ctype',
            'gd',
            'iconv',
            'intl',
            'json',
            'mbstring',
            'pdo_mysql',
            'session',
        ];

        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = sprintf('The %s extension needs to be enabled in your PHP.INI file in order for Eventum to work properly.', $extension);
            }
        }

        // check for the file_uploads php.ini directive
        if (ini_get('file_uploads') != '1') {
            $errors[]
                = "The 'file_uploads' directive needs to be enabled in your PHP.INI file in order for Eventum to work properly.";
        }

        $configPath = Setup::getConfigPath();
        $setupFile = Setup::getSetupFile();

        $error = $this->checkPermissions($configPath, "Directory '" . $configPath . "'", true);
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions($setupFile, "File '" . $setupFile . "'");
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions(
            $configPath . '/private_key.php', "File '" . $configPath . '/private_key.php' . "'"
        );
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions(
            $configPath . '/config.php', "File '" . $configPath . '/config.php' . "'"
        );
        if (!empty($error)) {
            $errors[] = $error;
        }

        $error = $this->checkPermissions(APP_LOCKS_PATH, "Directory '" . APP_LOCKS_PATH . "'", true);
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions(APP_LOG_PATH, "Directory '" . APP_LOG_PATH . "'", true);
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions(APP_TPL_COMPILE_PATH, "Directory '" . APP_TPL_COMPILE_PATH . "'", true);
        if (!empty($error)) {
            $errors[] = $error;
        }
        $error = $this->checkPermissions(APP_ERROR_LOG, "File '" . APP_ERROR_LOG . "'");
        if (!empty($error)) {
            $errors[] = $error;
        }

        return [$warnings, $errors];
    }

    private function getTimezone()
    {
        $ini = ini_get('date.timezone');
        if ($ini) {
            return $ini;
        }

        // if php.ini is not configured, this function is noisy
        return @date_default_timezone_get();
    }

    private function getFirstWeekday()
    {
        // this works on Linux
        // http://stackoverflow.com/questions/727471/how-do-i-get-the-first-day-of-the-week-for-the-current-locale-php-l8n
        $weekday = exec('locale first_weekday');
        if ($weekday) {
            // Returns Monday=2, but we need 1 for Monday
            // see http://man7.org/linux/man-pages/man5/locale.5.html
            return --$weekday;
        }

        // This would work in PHP 5.5
        if (class_exists('IntlCalendar')) {
            $cal = IntlCalendar::createInstance();

            return $cal->getFirstDayOfWeek() === IntlCalendar::DOW_MONDAY ? 1 : 0;
        }

        // default to Monday as it's default for "World" in CLDR's supplemental data
        return 1;
    }

    private function e($s)
    {
        return var_export($s, 1);
    }

    private function initlogger()
    {
        // init timezone, logger needs it
        if (!defined('APP_DEFAULT_TIMEZONE')) {
            $tz = $this->getRequest()->request->get('default_timezone');
            define('APP_DEFAULT_TIMEZONE', $tz ?: 'UTC');
        }

        Logger::initialize();
    }

    /**
     * return error message as string, or true indicating success
     * requires setup to be written first.
     */
    private function setup_database()
    {
        $this->initlogger();
        $post = $this->getRequest()->request;

        $db_config = [
            'db_name' => $post->get('db_name'),
            'user' => $post->get('eventum_user'),
            'password' => $post->get('eventum_password'),

            'drop_tables' => $post->get('drop_tables') === 'yes',
            'create_db' => $post->get('create_db') === 'yes',
            'alternate_user' => $post->get('alternate_user') === 'yes',
            'create_user' => $post->get('create_user') === 'yes',
        ];

        $dbs = new DatabaseSetup();
        try {
            $db_result = $dbs->run($db_config);
        } catch (SetupException $e) {
            $this->tpl->assign('db_result', $e->getMessage());
            throw new RuntimeException($e->getType());
        }
        $this->tpl->assign('db_result', $db_result);
    }

    /**
     * write initial values for setup file
     */
    private function write_setup()
    {
        $post = $this->getRequest()->request;
        $setup = $post->get('setup');
        $setup['update'] = 1;
        $setup['closed'] = 1;
        $setup['emails'] = 1;
        $setup['files'] = 1;
        $setup['support_email'] = 'enabled';

        $db_hostname = $post->get('db_hostname');
        $parts = explode(':', $db_hostname, 2);
        if (count($parts) > 1) {
            list($hostname, $socket) = $parts;
        } else {
            list($hostname) = $parts;
            $socket = null;
        }

        $setup['database'] = [
            // connection info
            'hostname' => $hostname,
            'database' => '', // NOTE: db name has to be written after the table has been created
            'username' => $post->get('db_username'),
            'password' => $post->get('db_password'),
            'port' => 3306,
            'charset' => 'utf8',
            'socket' => $socket,
        ];

        Setup::save($setup);
    }

    private function write_config()
    {
        $post = $this->getRequest()->request;
        $configPath = Setup::getConfigPath();
        $configFilePath = $configPath . '/config.php';

        // disable the full-text search feature for certain mysql server users
        $mysql_version = DB_Helper::getInstance(false)->getOne('SELECT VERSION()');
        preg_match('/(\d{1,2}\.\d{1,2}\.\d{1,2})/', $mysql_version, $matches);
        $enable_fulltext = $matches[1] > '4.0.23';

        $protocol_type = $post->get('is_ssl') === 'yes' ? 'https://' : 'http://';

        $replace = [
            "'%{APP_HOSTNAME}%'" => $this->e($post->get('hostname')),
            "'%{CHARSET}%'" => $this->e(APP_CHARSET),
            "'%{APP_RELATIVE_URL}%'" => $this->e($post->get('relative_url')),
            "'%{APP_DEFAULT_TIMEZONE}%'" => $this->e($post->get('default_timezone')),
            "'%{APP_DEFAULT_WEEKDAY}%'" => (int)$post->getInt('default_weekday'),
            "'%{PROTOCOL_TYPE}%'" => $this->e($protocol_type),
            "'%{APP_ENABLE_FULLTEXT}%'" => $this->e($enable_fulltext),
        ];

        $config_contents = file_get_contents($configPath . '/config.dist.php');
        $config_contents = str_replace(array_keys($replace), array_values($replace), $config_contents);

        $fs = new Filesystem();
        $fs->dumpFile($configFilePath, $config_contents, null);
    }

    private function install()
    {
        try {
            Auth::generatePrivateKey();
            $this->write_setup();
            $this->setup_database();
            $this->write_config();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return 'success';
    }
}
