<?php
/**
 * QUESTION-EXP-040: each Bamboo lookup is documented in the chart with who, when and the NarxCare
 * scores; "Reviewed, no concerns" appends a signed line to that patient's PDMP note only.
 * Inert SQL doubles; no database or vendor call.
 */
if (PHP_SAPI !== 'cli') exit(2);
$GLOBALS['notes'] = []; $GLOBALS['options'] = 0;
function sqlQuery($sql, $binds = []) {
    if (str_starts_with($sql, 'SELECT username, fname, lname FROM users')) return ['username' => 'drsmith', 'fname' => 'Jamie', 'lname' => 'Smith'];
    if (str_starts_with($sql, 'SELECT username FROM users')) return ['username' => 'drsmith'];
    if (str_starts_with($sql, 'SELECT id FROM pnotes')) {
        $n = $GLOBALS['notes'][$binds[0]] ?? null;
        return $n && $n['pid'] === $binds[1] && $n['title'] === $binds[2] ? ['id' => $binds[0]] : false;
    }
    throw new RuntimeException('Unexpected query');
}
function sqlInsert($sql, $binds) {
    $id = count($GLOBALS['notes']) + 1;
    $GLOBALS['notes'][$id] = ['body' => $binds[0], 'pid' => $binds[1], 'user' => $binds[2], 'title' => $binds[4], 'done' => str_contains($sql, "'Done'")];
    return $id;
}
function sqlStatement($sql, $binds = []) {
    if (str_contains($sql, 'list_options')) { $GLOBALS['options']++; return true; }
    if (str_starts_with($sql, 'UPDATE pnotes SET body = CONCAT')) { if (!empty($GLOBALS['fail_review'])) return false; $GLOBALS['notes'][$binds[2]]['body'] .= $binds[0]; return true; }
    throw new RuntimeException('Unexpected statement');
}
require_once __DIR__ . '/../src/Controllers/PdmpChartEntry.php';
use Juggernaut\Module\Bamboo\Controllers\PdmpChartEntry;

$checks = 0;
$check = static function ($ok, $m) use (&$checks) { if (!$ok) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } ++$checks; };
$report = ['Report' => ['NarxScores' => ['Score' => [
    ['ScoreType' => 'Narcotic', 'ScoreValue' => '310'], ['ScoreType' => 'Stimulant', 'ScoreValue' => '150']]],
    'ReportExpiration' => '2026-10-28T10:00:00']];
$check(PdmpChartEntry::scores($report) === [['type' => 'Narcotic', 'value' => '310'], ['type' => 'Stimulant', 'value' => '150']], 'scores list');
$single = ['Report' => ['NarxScores' => ['Score' => ['ScoreType' => 'Sedative', 'ScoreValue' => '220']]]];
$check(PdmpChartEntry::scores($single) === [['type' => 'Sedative', 'value' => '220']], 'single score decodes');

$id = PdmpChartEntry::record(['actor' => 7, 'pid' => 42], $report);
$note = $GLOBALS['notes'][$id];
$check($note['pid'] === 42 && $note['user'] === 'drsmith' && $note['title'] === 'PDMP' && $note['done'], 'chart note on the patient, Done, by the checker');
$check(str_contains($note['body'], 'by Jamie Smith') && str_contains($note['body'], 'Narcotic 310; Stimulant 150')
    && str_contains($note['body'], 'Report available until 2026-10-28T10:00:00') && str_contains($note['body'], 'Full report not saved'),
    'who, scores, expiry and no-full-report statement');
$expected = hash('sha256', json_encode($report['Report'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$check(str_contains($note['body'], "Report snapshot reference (SHA-256): $expected."), 'exact report snapshot fingerprint retained');
$withLink = $report;
$withLink['Report']['ReportRequestURLs']['ViewableReport'] = 'https://pmpgateway.net/secret-report-token';
$linkId = PdmpChartEntry::record(['actor' => 7, 'pid' => 42], $withLink);
$check(!str_contains($GLOBALS['notes'][$linkId]['body'], 'secret-report-token') && !str_contains($GLOBALS['notes'][$linkId]['body'], 'https://'), 'report access URL never copied into the chart');
$check($GLOBALS['notes'][$linkId]['body'] !== $note['body'], 'different snapshot has a different reference');
$check($GLOBALS['options'] === 2, 'PDMP Check note type ensured');

PdmpChartEntry::markReviewed(['actor' => 7, 'pid' => 42], $id);
$check(str_ends_with($GLOBALS['notes'][$id]['body'], '(drsmith) Reviewed, no concerns.'), 'review appended with reviewer');
$denied = false;
try { PdmpChartEntry::markReviewed(['actor' => 7, 'pid' => 99], $id); } catch (RuntimeException $e) { $denied = true; }
$check($denied, "another patient's session cannot review this note");

$before = $GLOBALS['notes'][$id]['body'];
$GLOBALS['fail_review'] = true;
$failed = false;
try { PdmpChartEntry::markReviewed(['actor' => 7, 'pid' => 42], $id); } catch (RuntimeException $error) { $failed = $error->getMessage() === 'PDMP review not recorded'; }
$check($failed && $GLOBALS['notes'][$id]['body'] === $before, 'failed review write is not acknowledged');
$GLOBALS['fail_review'] = false;

$page = file_get_contents(__DIR__ . '/../public/history.php');
$check(str_contains($page, 'PdmpChartEntry::record($context, $arrOutput)') && str_contains($page, 'PdmpChartEntry::scores($arrOutput)'),
    'history page records the check and uses normalized scores');
echo "PASS $checks PDMP chart entry checks; inert SQL.\n";
