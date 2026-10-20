<?php

/*
 *
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *   author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *   Copyright (c)
 *   GNU General Public License 3
 */

namespace Juggernaut\Module\Bamboo\Controllers;

use OpenEMR\Common\Crypto\CryptoGen;

class ReportDisplayRequest
{
    public string $url;
    private $cryptoGen;

    public function __construct()
    {
        $this->cryptoGen = new CryptoGen();
    }
    public function fetchReportDisplay()
    {
        $url = ReportDestination::validate($this->url);
        $certPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . "oe-bamboo-pmp/certificate/certificate.pfx";

        $credentials = new ResourcesConfig();
        $usernamePassword = $credentials->getConnectionData();
        $password = $this->cryptoGen->decryptStandard($usernamePassword['password'], null, 'database');
        $username = $usernamePassword['username'];

        // Define variables
        $nonce = bin2hex(random_bytes(16)); // Generate a random nonce
        $timestamp = time(); // Current Unix timestamp


// Create the combined string and hash it
        $combinedString = $password . ":" . $nonce . ":" . $timestamp;
        $passwordDigest = hash('sha256', $combinedString);
        $passwordDigest = strtolower($passwordDigest);

        // Initialize cURL session
        $ch = curl_init();
        ReportDestination::configure($ch);

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        // Set the HTTP headers
        $headers = [
            "X-Auth-Username: $username",
            "X-Auth-Timestamp: $timestamp",
            "X-Auth-Nonce: $nonce",
            "X-Auth-PasswordDigest: $passwordDigest",
        ];
        //curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        //curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
        //curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Check for errors
        if ($response === false || $status < 200 || $status >= 300) {
            curl_close($ch);
            throw new \RuntimeException('Report display failed');
        } else {
            // Output the response (HTML) directly to the browser
            header('Content-Type: text/html'); // Set the content type to HTML
            header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src https: data:");
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            echo $response;
        }

// Close the cURL session
        curl_close($ch);
    }
}
