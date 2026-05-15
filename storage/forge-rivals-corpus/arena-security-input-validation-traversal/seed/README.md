# arena-security-input-validation-traversal · seed

Endpoint atual aceita filename cru — `..` permite leitura arbitrária do
storage. Arm bloqueia 6 payloads de traversal, valida allowlist de
extensão `.json .md .log .zip`, canonicaliza path sob storage/evidence.
