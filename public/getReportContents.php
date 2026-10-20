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

try {
    $context = ReportAccess::requirePost();
    $url = ReportAccess::resolve($_POST['report_ref'] ?? null, 'display', $context);
    $report = new ReportDisplayRequest();
    $report->url = $url;
    $report->fetchReportDisplay();
} catch (\Throwable $error) {
    ReportAccess::fail($error);
}
