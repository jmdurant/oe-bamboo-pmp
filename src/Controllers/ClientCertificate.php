<?php

namespace Juggernaut\Module\Bamboo\Controllers;

/**
 * The PMP client certificate holds a private key, so it lives in protected site storage, never in
 * the web-installed module tree. Override the location with BAMBOO_PMP_CERT_FILE.
 */
final class ClientCertificate
{
    public const DEFAULT_PATH = '/var/www/localhost/htdocs/openemr/sites/default/documents/.practice/bamboo-client.p12';

    public static function path(): string
    {
        $path = getenv('BAMBOO_PMP_CERT_FILE') ?: self::DEFAULT_PATH;
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException('Bamboo client certificate must be provisioned in protected storage');
        }
        $module = realpath(dirname(__DIR__, 2));
        if ($module !== false && str_starts_with($resolved, $module . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Bamboo client certificate cannot be stored in module source');
        }
        if ((fileperms($resolved) & 0077) !== 0) {
            throw new \RuntimeException('Bamboo client certificate requires private file permissions');
        }
        return $resolved;
    }
}
