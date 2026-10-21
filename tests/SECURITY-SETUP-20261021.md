# Bamboo setup boundary regression

The setup POST previously passed browser input directly to
`ResourcesConfig::storeConnectionData()` before checking whether credentials
already existed. Neither setup entry point enforced module administration
permission or checked a CSRF token. The configured-state warning only hid the form.

`setup.php` now checks a positive authenticated actor, excludes the patient portal,
and requires OpenEMR's existing `admin/manage_modules` permission before reading
credentials or rendering setup. This permission matches the core Module Manager
register/unregister actions (parent investigation: `InstallerController.php`,
`registerAction` line 155 and `unregisterAction` line 194, read-only image
`practice-openemr:latest-security-20260921`). It adds no role, account, or grant.

POST requires a string native CSRF token before encryption or insertion. Configured
POST returns HTTP 409 without writing. Invalid credentials represented as arrays or
missing fields return 400. Access and token denial return 403 with a generic setup
message. First save retains database-mode encryption and parameterized insertion;
configured GET retains the existing warning. Session writes are enabled before
OpenEMR globals load, and native 7.x / wrapped 8.x session APIs follow the existing
report helper precedent. Setup does not require a selected patient. Clinical
credential readers and report permissions are unchanged.

## Reusable tests

Run `php tests/setup-boundary.php`. It executes the actual `setup.php` and
`moduleConfig.php` entry points in isolated child processes with synthetic OpenEMR
APIs, an inert encryption substitute, and counted database operations. Both native
and wrapped session modes cover authorized first save and GET without a patient,
configured GET/POST, nonadmin GET/POST, portal and absent/array actors, missing/wrong/
array CSRF tokens, and array credentials. Rejected writes must perform zero
encryption and zero inserts; unauthorized requests must also perform zero reads.
It checks the exact existing admin permission, CSRF API argument shapes, bootstrap
session-write setting, rendered hidden token, and encryption parameters.

Before patching setup, this test failed at
`setup.php/nonadmin/native status`, after the valid first-save control passed.
After the patch it reports `PASS 244 setup boundary checks`.

Verification commands:

```sh
php -l setup.php
php -l src/Controllers/SetupAccess.php
php -l tests/setup-boundary.php
php tests/setup-boundary.php
php tests/report-boundary.php
php tests/report-boundary.php --wrapped
git diff --check
```

All commands above passed. The report tests each report 64 checks. `composer test`
could not run because Composer is absent from this shell; all three PHP commands
in its test script passed directly. Tests use no vendor service, live database,
real account, certificate, or deployed application. They establish the module
boundary against native API substitutes, not a live OpenEMR acceptance result.
This patch does not add a database singleton constraint or redesign credential or
certificate storage; concurrent first-save requests are not serialized here.

One fresh read-only candidate review found no concrete surviving bypass or
regression. Parent separately read native OpenEMR `CsrfUtils.php` from the same
read-only image: collectCsrfToken(SessionInterface, subject) and
verifyCsrfToken(token, SessionInterface, subject) match the wrapped-session
calls. The preexisting report helper retains the older no-wrapper overload.
This is API/source compatibility evidence, not a live administrative login test.
