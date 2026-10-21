<?php
/** Exercise the real setup entry points with inert OpenEMR/database substitutes. */
if (($argv[1] ?? '') === '--case') {
    $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $root = getenv('BAMBOO_TEST_SOURCE') ?: dirname(__DIR__);
    $fixture = sys_get_temp_dir() . '/bamboo-setup-' . bin2hex(random_bytes(8));
    $module = "$fixture/interface/modules/custom_modules/oe-bamboo-pmp";
    mkdir($module, 0700, true);
    $globals = <<<'PHP'
<?php
namespace OpenEMR\Common\Acl {
    class AclMain {
        static function aclCheckCore(...$args) {
            $GLOBALS['acl_args'][] = $args;
            return $args === ['admin', 'manage_modules'] && $GLOBALS['scenario']['admin'];
        }
    }
}
namespace OpenEMR\Common\Csrf {
    class CsrfUtils {
        static function collectCsrfToken(...$args) { self::check($args); return 'synthetic-csrf'; }
        static function verifyCsrfToken($token, ...$args) { self::check($args); return $token === 'synthetic-csrf'; }
        static function check($args) {
            if ($GLOBALS['scenario']['wrapped'] ? count($args) !== 1 || !is_object($args[0]) : count($args) !== 0) {
                throw new \RuntimeException('Wrong session API');
            }
        }
    }
}
namespace OpenEMR\Common\Crypto {
    class CryptoGen {
        function encryptStandard($password, $unused, $mode) {
            $GLOBALS['encryptions']++;
            if ($mode !== 'database') throw new \RuntimeException('Wrong encryption mode');
            return 'encrypted-' . $password;
        }
    }
}
namespace OpenEMR\Core { class Header { static function setupHeader() {} } }
namespace {
    $GLOBALS['write_enabled_at_bootstrap'] = ($GLOBALS['sessionAllowWrite'] ?? false) === true;
    function xlt($s) { return $s; }
    function xla($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function text($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function sqlQuery($sql, $params = []) {
        $GLOBALS['reads']++;
        return $GLOBALS['scenario']['configured'] ? ['password' => 'existing', 'username' => 'existing'] : ['password' => null];
    }
    function sqlStatement($sql, $params = []) {
        $GLOBALS['inserts'][] = $params;
        $GLOBALS['scenario']['configured'] = true;
        return true;
    }
    spl_autoload_register(function ($class) {
        $prefix = 'Juggernaut\\Module\\Bamboo\\';
        if (str_starts_with($class, $prefix)) require $GLOBALS['source_root'] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    });
}
PHP;
    file_put_contents("$fixture/interface/globals.php", $globals);
    foreach (['setup.php', 'moduleConfig.php'] as $entry) copy("$root/$entry", "$module/$entry");
    $GLOBALS['source_root'] = $root;
    $GLOBALS['scenario'] = $scenario;
    $GLOBALS['encryptions'] = $GLOBALS['reads'] = 0;
    $GLOBALS['inserts'] = $GLOBALS['acl_args'] = [];
    if ($scenario['wrapped']) {
        eval('namespace OpenEMR\\Common\\Session; class SessionWrapperFactory {
            static function getInstance() { return new self; }
            function getActiveSession() { return $this; }
            function get($key, $default = null) { return $GLOBALS["session_data"][$key] ?? $default; }
        }');
    }
    $_SESSION = $GLOBALS['session_data'] = $scenario['session'];
    $_SERVER['REQUEST_METHOD'] = $scenario['method'];
    $_POST = $scenario['post'];
    register_shutdown_function(function () use ($fixture, $module) {
        $body = ob_get_contents();
        if (ob_get_level()) ob_end_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body,
            'inserts' => $GLOBALS['inserts'], 'encryptions' => $GLOBALS['encryptions'],
            'reads' => $GLOBALS['reads'], 'acl' => $GLOBALS['acl_args'],
            'write_enabled' => $GLOBALS['write_enabled_at_bootstrap'], 'error' => error_get_last()]);
        foreach (['setup.php', 'moduleConfig.php'] as $entry) unlink("$module/$entry");
        unlink("$fixture/interface/globals.php");
        $dir = $module;
        while ($dir !== $fixture) { rmdir($dir); $dir = dirname($dir); }
        rmdir($fixture);
    });
    ob_start();
    chdir($module);
    require "$module/{$scenario['entry']}";
    exit;
}
$checks = 0;
function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
foreach ([false, true] as $wrapped) foreach (['setup.php', 'moduleConfig.php'] as $entry) {
    $base = ['wrapped' => $wrapped, 'entry' => $entry, 'admin' => true, 'configured' => false,
        'session' => ['authUserID' => 3], 'method' => 'POST',
        'post' => ['username' => 'synthetic', 'password' => 'secret', 'csrf_token_form' => 'synthetic-csrf']];
    $cases = [
        'valid first save' => [[], 200, 1],
        'nonadmin' => [['admin' => false], 403, 0],
        'portal' => [['session' => ['authUserID' => 3, 'patient_portal_onsite_two' => true]], 403, 0],
        'no actor' => [['session' => []], 403, 0],
        'array actor' => [['session' => ['authUserID' => [3]]], 403, 0],
        'missing token' => [['post' => ['username' => 'synthetic', 'password' => 'secret']], 403, 0],
        'wrong token' => [['post' => ['csrf_token_form' => 'wrong']], 403, 0],
        'array token' => [['post' => ['csrf_token_form' => ['synthetic-csrf']]], 403, 0],
        'array credentials' => [['post' => ['username' => ['synthetic'], 'password' => 'secret', 'csrf_token_form' => 'synthetic-csrf']], 400, 0],
        'configured POST' => [['configured' => true], 409, 0],
        'first setup GET without patient' => [['method' => 'GET', 'post' => []], 200, 0],
        'configured GET' => [['method' => 'GET', 'post' => [], 'configured' => true], 200, 0],
        'nonadmin GET' => [['method' => 'GET', 'post' => [], 'admin' => false], 403, 0],
    ];
    foreach ($cases as $label => [$overrides, $status, $writes]) {
        $scenario = array_replace($base, $overrides);
        $cmd = [PHP_BINARY, __FILE__, '--case', json_encode($scenario, JSON_THROW_ON_ERROR)];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $result = json_decode(stream_get_contents($pipes[1]), true);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $tag = "$entry/$label/" . ($wrapped ? 'wrapped' : 'native');
        check($exit === 0 && $stderr === '' && is_array($result), "$tag execution: $stderr");
        check($result['status'] === $status, "$tag status");
        check(count($result['inserts']) === $writes && $result['encryptions'] === $writes, "$tag side effects");
        check($result['write_enabled'], "$tag write enabled at bootstrap");
        if ($writes) check($result['inserts'][0] === [null, 'synthetic', 'encrypted-secret'], "$tag encryption contract");
        if ($label === 'first setup GET without patient') {
            check(str_contains($result['body'], 'name="csrf_token_form"') && str_contains($result['body'], 'synthetic-csrf'), "$tag form token");
        }
        if (in_array($label, ['configured GET', 'valid first save'], true)) check(str_contains($result['body'], 'Credentials already saved'), "$tag existing warning");
        if (in_array($label, ['nonadmin', 'nonadmin GET', 'portal', 'no actor', 'array actor'], true)) check($result['reads'] === 0, "$tag no credential read");
    }
}
echo "PASS $checks setup boundary checks\n";
