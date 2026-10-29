<?php
/** Opt-in local synthetic chart receipt check. No Bamboo request or full report. */
if (PHP_SAPI !== 'cli' || getenv('PDMP_CHART_NATIVE') !== '1') exit(2);
$_GET['site'] = 'default'; $ignoreAuth = true;
require '/var/www/localhost/htdocs/openemr/interface/globals.php';
require_once getenv('PDMP_CHART_SOURCE') ?: dirname(__DIR__) . '/src/Controllers/PdmpChartEntry.php';
use Juggernaut\Module\Bamboo\Controllers\PdmpChartEntry;
$actor = sqlQuery("SELECT id FROM users WHERE username='jamessmith' AND active=1");
$patient = sqlQuery('SELECT pid FROM patient_data ORDER BY pid LIMIT 1');
if (!$actor || !$patient) throw new RuntimeException('Synthetic local fixtures unavailable');
$context = ['actor' => (int)$actor['id'], 'pid' => (int)$patient['pid']];
$report = ['Report' => ['NarxScores' => ['Score' => ['ScoreType' => 'Stimulant', 'ScoreValue' => 'synthetic']],
    'ReportExpiration' => 'synthetic expiry',
    'ReportRequestURLs' => ['ViewableReport' => 'https://pmpgateway.net/synthetic-private-reference']]];
$id = 0; $checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException("FAIL: $label"); ++$checks; };
try {
    $id = PdmpChartEntry::record($context, $report);
    $note = sqlQuery('SELECT pid, body, title, message_status FROM pnotes WHERE id=?', [$id]);
    $check($note && (int)$note['pid'] === $context['pid'] && $note['title'] === 'PDMP' && $note['message_status'] === 'Done', 'own chart receipt');
    $digest = hash('sha256', json_encode($report['Report'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $check(str_contains($note['body'], "Report snapshot reference (SHA-256): $digest."), 'stored exact report reference');
    $check(!str_contains($note['body'], 'https://') && !str_contains($note['body'], 'synthetic-private-reference'), 'no report bearer link stored');
    PdmpChartEntry::markReviewed($context, $id);
    $reviewed = sqlQuery('SELECT body FROM pnotes WHERE id=?', [$id]);
    $check(str_contains($reviewed['body'], 'Reviewed, no concerns.'), 'review persisted');
    echo "PASS $checks native synthetic PDMP chart checks; no vendor request\n";
} finally {
    if ($id > 0) sqlStatement('DELETE FROM pnotes WHERE id=? AND pid=?', [$id, $context['pid']]);
    echo "Synthetic PDMP note removed\n";
}
