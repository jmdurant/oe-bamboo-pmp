<?php
namespace Juggernaut\Module\Bamboo\Controllers;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

/** Native 7.x sessions and 8.x wrappers share the report reference boundary. */
final class ReportAccess
{
    public static function get(string $key, $default = null)
    {
        return class_exists(SessionWrapperFactory::class)
            ? SessionWrapperFactory::getInstance()->getActiveSession()->get($key, $default)
            : ($_SESSION[$key] ?? $default);
    }

    public static function set(string $key, $value): void
    {
        if (class_exists(SessionWrapperFactory::class)) {
            SessionWrapperFactory::getInstance()->getActiveSession()->set($key, $value);
        } else {
            $_SESSION[$key] = $value;
        }
    }

    public static function context(): array
    {
        $actor = filter_var(self::get('authUserID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pid = filter_var(self::get('pid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$actor || !$pid || self::get('patient_portal_onsite_two')
            || !AclMain::aclCheckCore('patients', 'med', '', ['write', 'addonly'])) {
            throw new \RuntimeException('Report access denied', 403);
        }
        $patient = sqlQuery('SELECT pid, squad FROM patient_data WHERE pid = ?', [$pid]);
        if (!is_array($patient) || (int)($patient['pid'] ?? 0) !== $pid
            || (!empty($patient['squad']) && !AclMain::aclCheckCore('squads', $patient['squad']))) {
            throw new \RuntimeException('Report access denied', 403);
        }
        return ['actor' => $actor, 'pid' => $pid];
    }

    public static function csrf(): string
    {
        return class_exists(SessionWrapperFactory::class)
            ? CsrfUtils::collectCsrfToken(SessionWrapperFactory::getInstance()->getActiveSession())
            : CsrfUtils::collectCsrfToken();
    }

    public static function requirePost(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \RuntimeException('POST required', 405);
        $context = self::context();
        $token = $_POST['csrf_token_form'] ?? null;
        $valid = is_string($token) && (class_exists(SessionWrapperFactory::class)
            ? CsrfUtils::verifyCsrfToken($token, SessionWrapperFactory::getInstance()->getActiveSession())
            : CsrfUtils::verifyCsrfToken($token));
        if (!$valid) throw new \RuntimeException('Invalid form token', 403);
        if (array_diff(array_keys($_POST), ['report_ref', 'csrf_token_form'])) {
            throw new \RuntimeException('Invalid report request', 400);
        }
        return $context;
    }

    public static function issue(string $url, string $kind, array $context): string
    {
        ReportDestination::validate($url);
        if (!in_array($kind, ['request', 'display'], true)) throw new \InvalidArgumentException();
        $refs = self::get('bamboo_report_refs', []);
        $refs = is_array($refs) ? array_filter($refs, static fn($r) => is_array($r) && ($r['expires'] ?? 0) > time()) : [];
        $refs = array_slice($refs, -19, null, true);
        $ref = bin2hex(random_bytes(24));
        $refs[$ref] = $context + ['url' => $url, 'kind' => $kind, 'expires' => time() + 900];
        self::set('bamboo_report_refs', $refs);
        return $ref;
    }

    public static function resolve($ref, string $kind, array $context): string
    {
        $refs = self::get('bamboo_report_refs', []);
        $entry = is_string($ref) && preg_match('/^[a-f0-9]{48}$/D', $ref) && is_array($refs)
            ? ($refs[$ref] ?? null) : null;
        if (!is_array($entry) || ($entry['actor'] ?? null) !== $context['actor']
            || ($entry['pid'] ?? null) !== $context['pid'] || ($entry['kind'] ?? null) !== $kind
            || ($entry['expires'] ?? 0) <= time()) {
            throw new \RuntimeException('Report expired or unavailable; reload patient history', 403);
        }
        return ReportDestination::validate($entry['url'] ?? null);
    }

    public static function fail(\Throwable $error): void
    {
        $status = in_array($error->getCode(), [400, 403, 405], true) ? $error->getCode() : 502;
        http_response_code($status);
        if ($status === 405) header('Allow: POST');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Report unavailable. Reload patient history or contact your administrator.';
    }
}
