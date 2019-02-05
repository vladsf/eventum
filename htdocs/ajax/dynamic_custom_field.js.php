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

use Eventum\CustomField\Factory;
use Eventum\CustomField\Fields\DynamicCustomFieldInterface;

require_once __DIR__ . '/../../init.php';

Auth::checkAuthentication();

if (!empty($_REQUEST['iss_id'])) {
    $fields = Custom_Field::getListByIssue(Auth::getCurrentProject(), $_REQUEST['iss_id'], null, false, true);
} else {
    $fields = Custom_Field::getListByProject(Auth::getCurrentProject(), $_REQUEST['form_type'], false, true);
}
$data = [];
foreach ($fields as $field) {
    $backend = Factory::create($field['fld_id']);
    if ($backend instanceof DynamicCustomFieldInterface) {
        $field['structured_data'] = $backend->getStructuredData();
        $data[] = $field;
    }
}

header('Content-Type: text/javascript; charset=UTF-8');
$tpl = new Template_Helper();
$tpl->setTemplate('js/dynamic_custom_field.tpl.js');
$tpl->assign('fields', $data);
$tpl->displayTemplate();
