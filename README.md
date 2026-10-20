# OpenEMR Bamboo PMP (PDMP / NarxCare)

OpenEMR custom module for Bamboo Health prescription drug monitoring (PMP/PDMP) reporting and NarxCare-related workflows.

**Module Manager name:** Bamboo PMP Report  
**Package version:** 1.0.0 (`version.php`)  
**Author:** Sherwin Gaddis

## Compatibility

| Target | Status |
|--------|--------|
| OpenEMR **7.0.x** | Primary target for this line |
| OpenEMR **8.0+** | May require global-bag / session-wrapper updates |

- PHP **8.1+** recommended  
- Requires PHP `curl` extension  
- Client TLS certificate (`.pfx`) is **not** shipped in git — see `certificate/README.md`

## Certificate

Place your vendor-issued client certificate at:

```text
certificate/certificate.pfx
```

This path is gitignored. Never commit keystores or passphrases.

## Installation

```bash
cd /path/to/openemr/interface/modules/custom_modules
git clone https://github.com/juggernautsei/oe-bamboo-pmp.git
```

Register and enable in Module Manager. Configure credentials and certificate on the server outside of source control.

## Report request boundary

Deploy the report pages and controllers together. The normal **Request Full
Report** flow remains: patient history obtains a vendor report request, then the
browser opens the validated final vendor link. The browser now submits a random
`report_ref` and native `csrf_token_form`, not a URL. References are held in the
native session, bound to the user and selected patient, and expire after 15
minutes (at most 20 per session). Reload patient history after expiry.

Both report POST endpoints require the selected patient's medical-record
permission (`patients/med` write or add-only, as intended by the existing history
page) and squad access. The initial history request checks these before contacting
Bamboo. OpenEMR 7.x session/CSRF APIs and 8.x session wrappers are supported.

Only exact HTTPS hosts documented by Bamboo are accepted; other schemes, userinfo,
nonstandard ports, malformed URLs and redirects are refused before report data or
credentials are sent. The conflicting fixed pre-production Host header is removed.
See [Bamboo's Gateway host documentation](https://ssltls.bamboohealth.com/pmpgateway-cert-update-2025.html).
No arbitrary host override is accepted from a browser request.

The optional `getReportContents.php` helper also requires a session-bound display
reference, returned by `fetchFullReport.php` in `X-Bamboo-Report-Reference`, plus
CSRF. Its HTML response is sandboxed. Existing raw-URL callers must migrate; the
shipped UI opens the final vendor link directly and does not use that helper.
URLs, report XML and vendor errors are not written to debug files or browser logs.

Run the offline regression suite with `composer test`, or:

```bash
php tests/report-boundary.php
php tests/report-boundary.php --wrapped
```

These tests exercise actual module helpers and transports with inert native API
and network substitutes. They are not live vendor/certificate acceptance.

## License

This module is released under the **GNU General Public License v3.0** (GPL-3.0-only), consistent with OpenEMR.  
See [LICENSE](LICENSE).

## Support

Sherwin Gaddis / Juggernaut Systems Enterprise  
https://github.com/juggernautsei/oe-bamboo-pmp
