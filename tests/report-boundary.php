<?php
/** Offline regression: real module helpers/transports; native APIs and network are inert. */
namespace OpenEMR\Common\Acl {
    class AclMain { public static function aclCheckCore(...$args) { return $GLOBALS['allowed'] ?? true; } }
}
namespace OpenEMR\Common\Crypto {
    class CryptoGen { public function decryptStandard(...$args) { return 'synthetic'; } }
}
namespace OpenEMR\Common\Csrf {
    class CsrfUtils {
        public static function collectCsrfToken(...$args) { return 'synthetic-csrf'; }
        public static function verifyCsrfToken($token, ...$args) { return $token === 'synthetic-csrf'; }
    }
}
namespace Juggernaut\Module\Bamboo\Controllers {
    function curl_init($url = null) { $GLOBALS['curl_count']++; $GLOBALS['curl_options'] = []; return (object)['url'=>$url]; }
    function curl_setopt($handle, $option, $value) { $GLOBALS['curl_options'][$option] = $value; return true; }
    function curl_exec($handle) { return $GLOBALS['response'] ?? '<ReportLink>https://pmpgateway.net/v5_1/report_link/synthetic</ReportLink>'; }
    function curl_getinfo($handle, $option) { return $GLOBALS['status'] ?? 200; }
    function curl_close($handle) {}
}
namespace {
    // Run with `--wrapped` as well to exercise the 8.x session branch in a fresh process.
    if (in_array('--wrapped', $argv, true)) {
        eval('namespace OpenEMR\\Common\\Session; class SessionWrapperFactory {
            static function getInstance() { return new self; }
            function getActiveSession() { return $this; }
            function get($key, $default=null) { return $GLOBALS["wrapped_session"][$key] ?? $default; }
            function set($key, $value) { $GLOBALS["wrapped_session"][$key]=$value; }
        }');
    }
    foreach (['CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_REDIR_PROTOCOLS','CURLOPT_FOLLOWLOCATION',
        'CURLOPT_SSL_VERIFYHOST','CURLOPT_SSL_VERIFYPEER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT',
        'CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_RETURNTRANSFER','CURLOPT_HTTPHEADER',
        'CURLOPT_SSLCERT','CURLOPT_SSLCERTTYPE','CURLINFO_RESPONSE_CODE','CURLOPT_URL'] as $i => $constant) {
        if (!defined($constant)) define($constant, 1000+$i);
    }
    function sqlQuery($sql, $params = []) {
        $GLOBALS['queries']++;
        return str_contains($sql, 'patient_data') ? ['pid'=>$params[0], 'squad'=>'']
            : ['username'=>'synthetic', 'password'=>'synthetic'];
    }
    $root = dirname(__DIR__);
    require "$root/src/Interfaces/DataRequestXmlBuilders.php";
    foreach (['ReportDestination','ReportAccess','ResourcesConfig','GatewayRequests','ReportDisplayRequest'] as $class) {
        require "$root/src/Controllers/$class.php";
    }
    use Juggernaut\Module\Bamboo\Controllers\ReportDestination as Destination;
    use Juggernaut\Module\Bamboo\Controllers\ReportAccess as Access;
    use Juggernaut\Module\Bamboo\Controllers\GatewayRequests;
    use Juggernaut\Module\Bamboo\Controllers\ReportDisplayRequest;
    $checks = 0;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function refused(callable $call, $label) {
        try { $call(); } catch (\InvalidArgumentException | \RuntimeException $e) { check(true, $label); return; }
        throw new \RuntimeException("Not refused: $label");
    }
    $GLOBALS['queries'] = $GLOBALS['curl_count'] = 0;
    $builder = new class implements \Juggernaut\Module\Bamboo\Interfaces\DataRequestXmlBuilders {
        public function buildReportDataRequestXml() { $GLOBALS['built'] = true; return '<synthetic/>'; }
    };
    $gateway = new GatewayRequests($builder);
    $invalid = ['', 'http://pmpgateway.net/report', 'file:///etc/hosts', 'https://127.0.0.1/',
        'https://pmpgateway.net.evil.test/', 'https://pmpgateway.net@evil.test/',
        'https://evil.test@pmpgateway.net/', 'https://pmpgateway.net:444/',
        'https://pmpgateway.net./', "https://pmpgateway.net/\r\nx", 'https://pmpgateway.net/%0d',
        'https://pmpgateway.net\\@evil.test/', 'https://%70mpgateway.net/',
        'https://pmpgateway.net/#x', 'https://pmpgateway.net/%zz'];
    foreach ($invalid as $url) {
        refused(fn() => $gateway->fetchReportData($url), 'gateway URL');
        $display = new ReportDisplayRequest(); $display->url = $url;
        refused(fn() => $display->fetchReportDisplay(), 'display URL');
    }
    check($GLOBALS['queries'] === 0 && $GLOBALS['curl_count'] === 0 && empty($GLOBALS['built']), 'reject before credentials, XML and transport');
    foreach (['mutualauth.pmpgateway.net','secure.prep.pmpgateway.net','mtls.pmpgateway.net',
        'mtls.prep.pmpgateway.net','pmpgateway.net','prep.pmpgateway.net'] as $host) {
        $url = "https://$host:443/v5_1/report?a=1&b=x%2By";
        check(Destination::validate($url) === $url, 'documented host and unchanged query');
    }
    $gateway->fetchReportData('https://mutualauth.pmpgateway.net/v5_1/report');
    check($GLOBALS['curl_count'] === 1, 'positive gateway transport');
    $options = $GLOBALS['curl_options'];
    check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'HTTPS without redirects');
    check($options[CURLOPT_SSL_VERIFYHOST] === 2 && $options[CURLOPT_SSL_VERIFYPEER] === true, 'verified TLS');
    check(count(array_filter($options[CURLOPT_HTTPHEADER], fn($h)=>str_starts_with($h,'Host:'))) === 0, 'no conflicting authority');
    $GLOBALS['status'] = 302;
    refused(fn() => $gateway->fetchReportData('https://mutualauth.pmpgateway.net/v5_1/report'), 'redirect is not a report');
    $GLOBALS['status'] = 200;
    $_SESSION = [];
    Access::set('authUserID', 3); Access::set('pid', 9);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token_form'=>'synthetic-csrf'];
    $context = Access::requirePost();
    $url = 'https://mutualauth.pmpgateway.net/v5_1/report?ticket=a%2Bb&x=1';
    $ref = Access::issue($url, 'request', $context);
    check(Access::resolve($ref, 'request', $context) === $url, 'session report resolves exactly');
    refused(fn()=>Access::resolve($ref, 'display', $context), 'wrong phase');
    refused(fn()=>Access::resolve($ref, 'request', ['actor'=>4,'pid'=>9]), 'wrong actor');
    refused(fn()=>Access::resolve($ref, 'request', ['actor'=>3,'pid'=>10]), 'wrong patient');
    refused(fn()=>Access::resolve([], 'request', $context), 'array reference');
    $refs = Access::get('bamboo_report_refs'); $refs[$ref]['expires'] = time()-1;
    Access::set('bamboo_report_refs', $refs);
    refused(fn()=>Access::resolve($ref, 'request', $context), 'expired reference');
    $_POST['url'] = 'https://pmpgateway.net/other';
    refused(fn()=>Access::requirePost(), 'even allowlisted caller URL rejected'); unset($_POST['url']);
    $_POST['csrf_token_form'] = 'wrong'; refused(fn()=>Access::requirePost(), 'CSRF');
    $_POST['csrf_token_form'] = 'synthetic-csrf';
    $_SERVER['REQUEST_METHOD'] = 'GET'; refused(fn()=>Access::requirePost(), 'method');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['allowed'] = false; refused(fn()=>Access::requirePost(), 'ACL'); $GLOBALS['allowed'] = true;
    Access::set('patient_portal_onsite_two', 1); refused(fn()=>Access::context(), 'portal'); Access::set('patient_portal_onsite_two', null);
    for ($i=0;$i<25;$i++) Access::issue($url, 'request', $context);
    check(count(Access::get('bamboo_report_refs')) === 20, 'bounded session references');
    $display = new ReportDisplayRequest(); $display->url = 'https://pmpgateway.net/v5_1/report_link/synthetic';
    $GLOBALS['response'] = '<p>synthetic report</p>';
    ob_start(); $display->fetchReportDisplay(); $html = ob_get_clean();
    check($html === $GLOBALS['response'], 'positive display transport');
    foreach (['fetchFullReport.php','getReportContents.php'] as $file) {
        $source = file_get_contents("$root/public/$file");
        check(!str_contains($source, "\$_POST['url']"), 'no raw URL endpoint');
        check(strpos($source, 'requirePost()') < strpos($source, 'new '), 'admission before builder');
    }
    foreach (['ReportDataRequestMinXmlBuilder','PatientDataRequestMinXmlBuilder','RequestData'] as $class) {
        check(!str_contains(file_get_contents("$root/src/Controllers/$class.php"), '$_SESSION'), 'builder uses the admitted session abstraction');
    }
    // Load unmodified XML-builder source in its native directory layout with an
    // inert patient-library substitute; exercise constructors, not just text.
    $fixture = sys_get_temp_dir() . '/bamboo-builder-' . bin2hex(random_bytes(8));
    $controllers = "$fixture/interface/modules/custom_modules/oe-bamboo-pmp/src/Controllers";
    mkdir($controllers, 0700, true); mkdir("$fixture/library", 0700);
    file_put_contents("$fixture/library/patient.inc.php", '<?php function getPatientData($pid) { $GLOBALS["builder_pid"]=$pid; return ["pid"=>$pid]; }');
    try {
        foreach (['ReportDataRequestMinXmlBuilder','PatientDataRequestMinXmlBuilder'] as $class) {
            copy("$root/src/Controllers/$class.php", "$controllers/$class.php");
            require "$controllers/$class.php";
            $fqcn = 'Juggernaut\\Module\\Bamboo\\Controllers\\' . $class;
            new $fqcn();
            check($GLOBALS['builder_pid'] === 9, 'real builder receives wrapper-selected patient');
        }
    } finally {
        foreach (['ReportDataRequestMinXmlBuilder','PatientDataRequestMinXmlBuilder'] as $class) unlink("$controllers/$class.php");
        unlink("$fixture/library/patient.inc.php"); rmdir("$fixture/library");
        $dir = $controllers;
        while ($dir !== $fixture) { rmdir($dir); $dir = dirname($dir); }
        rmdir($fixture);
    }
    echo "PASS $checks report boundary checks\n";
}
