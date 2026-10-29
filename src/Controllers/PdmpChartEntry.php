<?php

/**
 * PDMP checks documented in the chart (owner decision 2026-10-27, QUESTION-EXP-040).
 * Each successful Bamboo lookup adds a dated patient note: who checked, when, the NarxCare scores
 * returned and the report expiry. The full report is not saved. "Reviewed, no concerns" appends a
 * signed line to that note. Notes are Done so they stay in the chart without entering an inbox.
 */

namespace Juggernaut\Module\Bamboo\Controllers;

final class PdmpChartEntry
{
    public const NOTE_TYPE = 'PDMP';
    public const NOTE_TYPE_TITLE = 'PDMP Check';

    /** @return list<array{type:string,value:string}> */
    public static function scores(array $report): array
    {
        $scores = $report['Report']['NarxScores']['Score'] ?? [];
        if (!is_array($scores)) return [];
        if (isset($scores['ScoreType'])) $scores = [$scores]; // a single score decodes as an object
        $out = [];
        foreach ($scores as $score) {
            if (is_array($score) && is_scalar($score['ScoreType'] ?? null) && is_scalar($score['ScoreValue'] ?? null)) {
                $out[] = ['type' => (string)$score['ScoreType'], 'value' => (string)$score['ScoreValue']];
            }
        }
        return $out;
    }

    public static function body(string $checkedBy, string $checkedAt, array $scores, string $expiration): string
    {
        $lines = ["PDMP check (Bamboo NarxCare) by $checkedBy at $checkedAt."];
        $lines[] = $scores === [] ? 'No NarxCare scores returned.'
            : 'NarxCare scores: ' . implode('; ', array_map(static fn($s) => "{$s['type']} {$s['value']}", $scores)) . '.';
        if ($expiration !== '') $lines[] = "Report available until $expiration.";
        $lines[] = 'Full report not saved to the chart.';
        return implode("\n", $lines);
    }

    /** Records a successful lookup; returns the note id. */
    public static function record(array $context, array $report): int
    {
        $actor = sqlQuery('SELECT username, fname, lname FROM users WHERE id = ? AND active = 1', [$context['actor']]);
        if (!is_array($actor) || empty($actor['username'])) throw new \RuntimeException('Checking user unavailable');
        self::ensureNoteType();
        $now = date('Y-m-d H:i');
        $name = trim(($actor['fname'] ?? '') . ' ' . ($actor['lname'] ?? '')) ?: $actor['username'];
        $expiration = is_scalar($report['Report']['ReportExpiration'] ?? null) ? (string)$report['Report']['ReportExpiration'] : '';
        // Audit reference only: never persist the session's bearer report URL.
        // Hash the exact decoded gateway Report snapshot, not merely the scores.
        $reference = hash('sha256', json_encode($report['Report'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $id = sqlInsert(
            'INSERT INTO pnotes (date, body, pid, user, groupname, authorized, activity, title, assigned_to,
                message_status, update_by, update_date) VALUES (NOW(), ?, ?, ?, ?, 1, 1, ?, \'\', \'Done\', ?, NOW())',
            ["$now ({$actor['username']}) " . self::body($name, $now, self::scores($report), $expiration) . "\nReport snapshot reference (SHA-256): $reference.",
                $context['pid'], $actor['username'], 'Default', self::NOTE_TYPE, $context['actor']]
        );
        if (!$id) throw new \RuntimeException('PDMP chart entry not recorded');
        return (int)$id;
    }

    public static function markReviewed(array $context, int $noteId): void
    {
        $actor = sqlQuery('SELECT username FROM users WHERE id = ? AND active = 1', [$context['actor']]);
        $note = sqlQuery('SELECT id FROM pnotes WHERE id = ? AND pid = ? AND title = ? AND deleted = 0',
            [$noteId, $context['pid'], self::NOTE_TYPE]);
        if (!is_array($actor) || !is_array($note)) throw new \RuntimeException('PDMP entry not found', 404);
        $updated = sqlStatement('UPDATE pnotes SET body = CONCAT(body, ?), update_by = ?, update_date = NOW() WHERE id = ?',
            ["\n" . date('Y-m-d H:i') . " ({$actor['username']}) Reviewed, no concerns.", $context['actor'], $noteId]);
        if ($updated === false) throw new \RuntimeException('PDMP review not recorded');
    }

    private static function ensureNoteType(): void
    {
        sqlStatement("INSERT IGNORE INTO list_options (list_id, option_id, title, seq, activity)
            VALUES ('note_type', ?, ?, 100, 1)", [self::NOTE_TYPE, self::NOTE_TYPE_TITLE]);
    }
}
