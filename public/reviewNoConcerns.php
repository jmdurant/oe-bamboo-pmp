<?php

/**
 * "Reviewed, no concerns" for a recorded PDMP check (QUESTION-EXP-040). POST with CSRF; the note
 * must be a PDMP Check on the patient in the clinician's current session.
 */

use Juggernaut\Module\Bamboo\Controllers\PdmpChartEntry;
use Juggernaut\Module\Bamboo\Controllers\ReportAccess;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

$sessionAllowWrite = true;
require_once dirname(__FILE__, 5) . '/globals.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('POST required', 405);
    $context = ReportAccess::context();
    $token = $_POST['csrf_token_form'] ?? null;
    $valid = is_string($token) && (class_exists(SessionWrapperFactory::class)
        ? CsrfUtils::verifyCsrfToken($token, SessionWrapperFactory::getInstance()->getActiveSession())
        : CsrfUtils::verifyCsrfToken($token));
    if (!$valid) throw new RuntimeException('Invalid form token', 403);
    $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$noteId || array_diff(array_keys($_POST), ['note_id', 'csrf_token_form'])) {
        throw new RuntimeException('Invalid review request', 400);
    }
    PdmpChartEntry::markReviewed($context, $noteId);
    http_response_code(204);
} catch (Throwable $error) {
    ReportAccess::fail($error);
}
