<?php

/*
 *
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *   author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *   Copyright (c)
 *   GNU General Public License 3
 */


namespace Juggernaut\Module\Bamboo\Controllers;
require_once dirname(__DIR__, 4) . "/globals.php";

$report = new ReportDisplayRequest();
$report->url = $_POST['url'];
$report->fetchReportDisplay();
