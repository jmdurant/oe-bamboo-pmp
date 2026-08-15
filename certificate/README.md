# Certificate directory

Place your Bamboo Health / PMP **client certificate** here for local or server deployment:

```text
certificate/certificate.pfx
```

This file is **private** (contains a private key). It is listed in `.gitignore` and must **never** be committed to git.

## Setup

1. Obtain a `.pfx` / `.p12` from your PMP vendor or organization.
2. Copy it to this directory as `certificate.pfx` (or configure the module path if different).
3. Restrict filesystem permissions, e.g. `chmod 600 certificate.pfx`.
4. Store the PFX passphrase in encrypted module settings or a secrets manager — not in source control.

If a certificate was ever committed to a public repository, treat it as compromised and **rotate/reissue** it.
