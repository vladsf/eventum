<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 - 2008 MySQL AB                                   |
// | Copyright (c) 2008 - 2009 Sun Microsystem Inc.                       |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: João Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id: process_mail_queue.php 3823 2009-02-10 06:46:03Z glen $

ini_set("memory_limit", "256M");

require_once(dirname(__FILE__) . "/../init.php");
require_once(APP_INC_PATH . "db_access.php");
require_once(APP_INC_PATH . "class.mail_queue.php");

// determine if this script is being called from the web or command line
$fix_lock = false;
if (isset($_SERVER['HTTP_HOST'])) {
    // web
    if (@$_GET['fix-lock'] == 1) {
        $fix_lock = true;
    }
} else {
    // command line
    if (in_array('--fix-lock', $argv)) {
        $fix_lock = true;
    }
}

// if requested, clear the lock
if ($fix_lock) {
    Mail_Queue::removeProcessFile();
    echo "The lock file was removed successfully.\n";
    exit;
}

if (!Mail_Queue::isSafeToRun()) {
    $pid = Lock::getProcessID('process_mail_queue');
    echo "ERROR: There is already a process (pid=$pid) of this script running. ";
    echo "If this is not accurate, you may fix it by running this script with '--fix-lock' as the only parameter.\n";
    exit;
}

// handle only pending emails
$limit = 50;
Mail_Queue::send('pending', $limit);

// handle emails that we tried to send before, but an error happened...
$limit = 50;
Mail_Queue::send('error', $limit);

Mail_Queue::removeProcessFile();
