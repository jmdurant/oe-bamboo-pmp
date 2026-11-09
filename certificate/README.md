# Certificate directory (not used)

Do not place the client certificate here. Bamboo's client certificate holds a private key, so the module refuses to load it from its own
source tree. Provision it in protected site storage with owner-only permissions (`chmod 600`):

```text
sites/default/documents/.practice/bamboo-client.p12
```

Set `BAMBOO_PMP_CERT_FILE` to use another location outside the module. Requests are refused when
the file is missing, inside the module, or readable by group or others. Never commit keystores or
passphrases.
