<?php

/*
 *
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *   author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *   Copyright (c)
 *   GNU General Public License 3
 */

use Juggernaut\Module\Bamboo\Controllers\GatewayRequests;
use Juggernaut\Module\Bamboo\Controllers\ReportDataRequestMinXmlBuilder;
use Juggernaut\Module\Bamboo\Controllers\ReportAccess;
use Juggernaut\Module\Bamboo\Controllers\ReportDestination;

$sessionAllowWrite = true;
require_once dirname(__FILE__, 5) . '/globals.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try {
    $context = ReportAccess::requirePost();
    $url = ReportAccess::resolve($_POST['report_ref'] ?? null, 'request', $context);
    $gateway = new GatewayRequests(new ReportDataRequestMinXmlBuilder());
    $report = $gateway->fetchReportData($url);
    if (str_contains($report, '<!DOCTYPE')) throw new RuntimeException();
    $xml = simplexml_load_string($report, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if ($xml === false) throw new RuntimeException();
    $link = ReportDestination::validate((string)$xml->ReportLink);
    header('X-Bamboo-Report-Reference: ' . ReportAccess::issue($link, 'display', $context));
    header('Content-Type: text/plain; charset=UTF-8');
    echo $link;
} catch (Throwable $error) {
    ReportAccess::fail($error);
}
