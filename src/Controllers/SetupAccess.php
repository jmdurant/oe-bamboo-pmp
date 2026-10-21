<?php
namespace Juggernaut\Module\Bamboo\Controllers;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

/** Integration configuration does not depend on a selected patient. */
final class SetupAccess
{
    public static function requireAdmin(): void
    {
        $actor = filter_var(ReportAccess::get('authUserID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$actor || ReportAccess::get('patient_portal_onsite_two')
            || !AclMain::aclCheckCore('admin', 'manage_modules')) {
            throw new \RuntimeException('Setup access denied', 403);
        }
    }

    public static function requireToken(): void
    {
        $token = $_POST['csrf_token_form'] ?? null;
        $valid = is_string($token) && (class_exists(SessionWrapperFactory::class)
            ? CsrfUtils::verifyCsrfToken($token, SessionWrapperFactory::getInstance()->getActiveSession())
            : CsrfUtils::verifyCsrfToken($token));
        if (!$valid) throw new \RuntimeException('Invalid form token', 403);
    }
}
