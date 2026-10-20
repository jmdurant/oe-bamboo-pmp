<?php

/**
 * View history of a patient.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once dirname(__DIR__, 4) . "/globals.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use Juggernaut\Module\Bamboo\Controllers\PatientDataRequestMinXmlBuilder;
use Juggernaut\Module\Bamboo\Controllers\GatewayRequests;
use Juggernaut\Module\Bamboo\Controllers\ReportAccess;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
try {
    $context = ReportAccess::context();
    $csrf = ReportAccess::csrf();
} catch (Throwable $error) {
    ReportAccess::fail($error);
    exit;
}

$xmlBuilderMin = new PatientDataRequestMinXmlBuilder();
$gatewayRequests = new GatewayRequests($xmlBuilderMin);
$gatewayRequests->url = "https://mutualauth.pmpgateway.net/v5_1/patient";

//fetching the report data
try {
    $report = $gatewayRequests->fetchReportData($gatewayRequests->url);

//return results
libxml_use_internal_errors(TRUE);
if (str_contains($report, '<!DOCTYPE')) throw new RuntimeException();
$simpleXml = simplexml_load_string($report, 'SimpleXMLElement', LIBXML_NONET);
$objXmlDocument = json_encode($simpleXml);
$arrOutput = json_decode($objXmlDocument, TRUE);
$reportRef = null;
if (isset($arrOutput['Report']['ReportRequestURLs']['ViewableReport'])) {
    $reportRef = ReportAccess::issue($arrOutput['Report']['ReportRequestURLs']['ViewableReport'], 'request', $context);
}
} catch (Throwable $error) {
    ReportAccess::fail($error);
    exit;
}

?>
<html>
<head>
    <title><?php echo xlt("Bamboo PMP"); ?></title>
    <?php Header::setupHeader('common'); ?>

</head>
<body>
<div id="container_div" class="container mt-3">
    <div class="row">
        <div class="col-md-6 mt-3">
            <div>
                <span style="font-size: xx-large; padding-right: 20px"><?php echo xlt("NarxScores") ?></span>
                <a href="../../../../patient_file/summary/demographics.php" onclick="top.restoreSession()"
                   title="<?php echo xla('Go Back') ?>">
                    <i id="advanced-tooltip" class="fa fa-undo fa-2x" aria-hidden="true"></i></a><?php xlt('Back') ?>
            </div>
            <?php
            if (!$arrOutput) {
                echo "<p>" . xlt("Bamboo not authorized - return to dashboard") . "</p>";
                echo "</div></body>\n</html>\n";
                die;
            } elseif (isset($arrOutput['Error'])) {
                echo "<div class='alert alert-danger'>";
                echo "<p>" . xlt("Error fetching report - Missing data") . "</p>";
                echo "<p>" . xlt($arrOutput['Error']['Message']) . "</p>";
                echo "</div></body>\n</html>\n";
                die;
            }
            ?>
            <table class="table">
                <tr>
                    <th><?php echo xlt("Score Type") ?></th>
                    <th><?php echo xlt("Score") ?></th>
                </tr>
                <?php
                foreach ($arrOutput['Report']['NarxScores']['Score'] as $narxScore) {
                    echo "<tr>";
                    echo "<td>" . text($narxScore['ScoreType']) . "</td>";
                    echo "<td>" . text($narxScore['ScoreValue']) . "</td>";
                    echo "</tr>";
                }
                ?>
            </table>
            <h3><?php echo xlt("Report Exp") ?></h3>
            <p><?php echo text($arrOutput['Report']['ReportExpiration'] ?? '') ?></p>
            <p class="m-3"><?php echo text($arrOutput['Report']['Message'] ?? '') ?></p>
            <h3><?php echo xlt("Full Report") ?></h3>
            <?php if ($reportRef !== null): ?>
            <p><button class="btn btn-primary" onclick="requestFullReport()"><?php echo xlt("Request Full Report") ?></button> </p>
            <?php endif; ?>
            <p id="report-error" role="alert" hidden><?php echo xlt('Report unavailable. Reload patient history or contact your administrator.'); ?></p>
            <h3><?php echo xlt("Disclaimer") ?></h3>
            <p><?php echo xlt($arrOutput['Disclaimer']) ?></p>
        </div>
    </div>

</div><!--end of container div -->
<script>
    async function requestFullReport() {
        // Open synchronously to preserve the user gesture; no vendor URL comes from the browser.
        const reportWindow = window.open('about:blank', '_blank');
        if (reportWindow) reportWindow.opener = null;
        try {
        const response = await fetch('fetchFullReport.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                report_ref: <?php echo json_encode($reportRef); ?>,
                csrf_token_form: <?php echo json_encode($csrf); ?>
            })
        });
        if (!response.ok) throw new Error('Report unavailable');
        const link = await response.text(); // Server validates the vendor origin.
        if (reportWindow) reportWindow.location.replace(link);
        else window.location.assign(link);
        } catch (_) {
            if (reportWindow) reportWindow.close();
            document.getElementById('report-error').hidden = false;
        }
    }
</script>
</body>
</html>
