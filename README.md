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

## License

This module is released under the **GNU General Public License v3.0** (GPL-3.0-only), consistent with OpenEMR.  
See [LICENSE](LICENSE).

## Support

Sherwin Gaddis / Juggernaut Systems Enterprise  
https://github.com/juggernautsei/oe-bamboo-pmp
