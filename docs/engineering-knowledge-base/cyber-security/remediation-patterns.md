---
id: atlas-ai-cyber-remediation-patterns
type: engineering_knowledge
title: Atlas AI Cyber Remediation Patterns
status: scaffold
category: knowledge-base
priority: 84
summary: Patterns canonicos de fix por classe de vulnerabilidade (CWE primario), com snippets por stack, anti-fixes, variants para Negative PoC e refactor playbook; consumido por skill desenvolvedor + cyber-* na fase remediacao.
tags:
  - atlas-ai
  - cyber-security
  - remediation
  - patterns
  - cwe
capabilities:
  - cyber_remediation_patterns_kb
decisions:
  - Um pattern por CWE primario; sub-CWEs viram variantes dentro do pattern.
  - Snippets por stack relevante (Node, Python, Java, Go, Ruby, .NET, PHP) inline; nao fragmentar por stack.
  - Negative PoC + Regression test sao obrigatorios em cada Patch (gate kernel).
maintenance:
  - Adicione pattern novo quando finding recorrente sem cobertura aparece.
  - Mantenha line_limit < 280.
related_paths:
  - atlas-server/docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
  - atlas-server/docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - AtlasVault/_skills/desenvolvedor/SKILL.md
owner: atlas-ai
layer: extension
line_limit: 280
---

# Atlas AI Cyber Remediation Patterns

Patterns canonicos de fix por classe de vulnerabilidade. Skill desenvolvedor + cyber-* consomem.

## Schema de pattern

```
ID: cyber-rem-<slug>
Cobre: CWE-XXX, OWASP ref, categoria
Fix canonico: prosa + snippet
Anti-fixes: lista
Variants para PoC: lista (cada variant deve aparecer em Negative PoC)
Refactor playbook: como aplicar em codebase grande
```

## cyber-rem-idor (CWE-639, CWE-862, CWE-863) — IDOR / BOLA

**Fix canonico**: validar ownership do recurso requisitado contra usuario autenticado **antes** de retornar resposta. Validacao em middleware/guard, nao no frontend.

**Snippets**:
- Node/Express: middleware `authorizeResourceOwner` que compara `req.params.id` com `req.user.id` antes de prosseguir; 403 + audit log se mismatch.
- Django REST: `IsResourceOwner` permission class checa `obj.user_id == request.user.id`.
- Spring: `@PreAuthorize("#id == authentication.principal.id")` no endpoint.
- Go: cheque inline com `chi.URLParam(r, "id")` vs `r.Context().Value("user_id")`.
- Repository scoping pattern: queries sempre `WHERE user_id = current_user.id` em base level.

**Anti-fixes**:
- Filtrar response no client (server ainda envia).
- Esconder ID com hash previsivel.
- Confiar em `Referer` header (spoofable).
- Mudar GET para POST.
- Aceitar `X-User-Id` header como confiavel.

**Variants para PoC**: direct ID swap (path/query/body), encoded ID (base64), UUID guess sequencial, sub-resource (`/users/{id}/orders/{order_id}`), GraphQL field-level, bulk endpoint.

## cyber-rem-sqli (CWE-89) — SQL Injection

**Fix canonico**: queries parametrizadas (prepared statements). Nunca concatenar input em SQL.

**Snippets**:
- Node/pg: `db.query("SELECT * FROM users WHERE email = $1", [email])`.
- Python/SQLAlchemy: `select(User).where(User.email == email)`.
- Java/JDBC: `PreparedStatement` com `setString`.
- Go/database/sql: `db.Query("SELECT * FROM users WHERE email = $1", email)`.

**Anti-fixes**: escapar quotes manual, whitelist de chars, WAF blocking keywords, stored procedure que ainda concatena.

**Variants para PoC**: union, boolean blind, time-based blind, error-based, second-order (input persisted then used em outro query), stacked queries (se DB permite).

## cyber-rem-xss (CWE-79) — XSS

**Fix canonico**: output encoding contextual (HTML, attribute, JS, URL, CSS). Nunca tratar input como markup.

**Snippets**:
- React: `<div>{userInput}</div>` (auto-escape); evitar `dangerouslySetInnerHTML`; se necessario, usar `DOMPurify.sanitize`.
- Vue: `{{ userInput }}` (auto-escape); evitar `v-html`.
- Server templates: garantir auto-escape ativo (Pug, EJS, Handlebars).
- URL context: `encodeURIComponent`.
- DOM: `element.textContent = ...`, NUNCA `element.innerHTML = ...`.

**Camadas de defesa**: output encoding (primaria) + CSP restritivo (`script-src 'self' 'nonce-{random}'`, sem `unsafe-inline`) + HttpOnly cookies + input validation (defesa em profundidade).

**Anti-fixes**: strip `<script>` regex, whitelist de chars, WAF blocking keywords, esconder em sandbox iframe.

**Variants para PoC**: reflected (URL param), stored (input persisted + render outra view), DOM (location.hash, postMessage), mutation XSS, SVG/MathML payload, polyglot.

## cyber-rem-ssrf (CWE-918) — SSRF

**Fix canonico**: allowlist de URLs permitidas; bloquear IPs internos, metadata endpoints, schemes nao-HTTP/S; resolver DNS antes de request e validar resultado.

**Snippets**: validar URL parsed contra allowlist + bloquear `169.254.169.254`, `127.0.0.0/8`, `10.0.0.0/8`, `192.168.0.0/16`, `172.16.0.0/12`, `[::1]`, decimal IPs; bloquear schemes `file://`, `gopher://`, `dict://`.

**Anti-fixes**: bloquear apenas string `localhost` (bypass com 127.1, 127.0.0.1, [::1]), validar URL antes de redirect (TOCTOU), bloquear apenas private IP (DNS rebind passa).

**Variants para PoC**: cloud metadata, internal services (localhost:port), redirect chain (server X redireciona para 169.254), DNS rebind (controlled DNS), `gopher://`/`dict://`/`file://` smuggling.

## cyber-rem-path-traversal (CWE-22) — Path Traversal / LFI

**Fix canonico**: nunca concatenar input com path; resolver path absoluto e validar prefix esperado; usar APIs canonicas (`path.resolve`, `os.path.normpath`).

**Anti-fixes**: regex stripping `../`, blacklist de strings.

**Variants para PoC**: `../`, `..%2f`, `..%252f`, double-encoded, unicode (`../`).

## cyber-rem-auth (CWE-287) — Authentication Bypass

**Fix canonico**: middleware authn obrigatorio em todas rotas protegidas (whitelist de rotas publicas); rate limit em endpoints auth (login, reset, MFA); JWT alg whitelist explicita; tokens com expiracao curta + refresh rotation; password hash Argon2id/bcrypt cost ≥12.

**Anti-fixes**: depender so do frontend, JWT sem alg validation, secret JWT compartilhado entre apps.

**Variants para PoC**: token expirado aceito, JWT alg=none, header injection (X-Forwarded-For: 127.0.0.1), MFA bypass via race condition, password reset token previsivel.

## cyber-rem-authz (CWE-862, CWE-863) — Missing/Incorrect Authorization

**Fix canonico**: authz check explicito em CADA endpoint; matriz role x action documentada; default-deny; teste por role.

**Anti-fixes**: confiar em obscurity de URL admin, role check so no frontend, header X-Role spoofable.

**Variants para PoC**: vertical privesc (user acessa admin endpoint), horizontal (cross-tenant), HTTP method tampering (GET vs DELETE), forced browsing.

## cyber-rem-csrf (CWE-352) — CSRF

**Fix canonico**: CSRF token sincronizado por sessao em endpoints state-changing; SameSite=Lax cookie; double-submit cookie OU header customizado em XHR/fetch.

**Anti-fixes**: Referer validation only (spoofable em alguns casos), token previsivel.

**Variants para PoC**: form sem token, token reusable cross-session, GET endpoints state-changing.

## cyber-rem-crypto-weak (CWE-327) — Weak Crypto

**Fix canonico**: algorithm whitelist (AES-GCM/ChaCha20-Poly1305, SHA-256+, HMAC-SHA-256+, Ed25519/X25519/ECDSA P-256+, RSA-3072+, Argon2id/bcrypt cost ≥12, PBKDF2-SHA256 iter ≥600k); usar bibliotecas tested.

**Anti-fixes**: implementar AES custom "porque mais rapido", upgrade so de versao sem mudar config.

**Variants para PoC**: MD5 em integrity, SHA-1 em sign, DES/3DES, RC4, ECB mode, IV reuse em GCM.

## cyber-rem-crypto-key (CWE-321, CWE-916) — Weak Key Management

**Fix canonico**: keys via KMS/HSM/Vault, nunca hardcoded; rotation policy explicita; per-tenant scoping; KDF (Argon2id/PBKDF2) com salt aleatorio.

**Anti-fixes**: env var commited em git, key rotada nunca, key compartilhada entre tenants.

## cyber-rem-secret-leak (CWE-798) — Hardcoded Secret

**Fix canonico**: vault (Atlas vault, AWS Secrets Manager, HashiCorp); secret scan em pre-commit (gitleaks); rotacionar secret leaked imediatamente.

**Anti-fixes**: env file commited, comentar secret no codigo, "vamos rotacionar depois".

## cyber-rem-deserialization (CWE-502) — Unsafe Deserialization

**Fix canonico**: nunca deserializar input untrusted com Pickle/Java native/PHP unserialize; usar JSON/Protobuf com schema validation; assinar payload (HMAC) se necessario.

**Anti-fixes**: tentar sanitizar antes de unserialize.

## cyber-rem-open-redirect (CWE-601) — Open Redirect

**Fix canonico**: allowlist de destinos permitidos; redirect interno via path relativo apenas; validar scheme + host.

**Variants para PoC**: `https://attacker.com`, `//attacker.com`, `/\\attacker.com`, encoded, `javascript:`.

## cyber-rem-mass-assignment (CWE-915) — Mass Assignment

**Fix canonico**: explicit allowlist de campos aceitos em update endpoints; ORM com `fillable`/`safe_attributes`; DTO com schema.

**Anti-fixes**: blacklist de campos sensiveis (escapa via case ou nome similar).

**Variants para PoC**: `is_admin: true` em profile update, `email_verified: true`, `account_balance` arbitrario, `role: admin`.

## cyber-rem-prompt-injection (LLM01) — Prompt Injection

**Fix canonico**: separacao clara entre system prompt e user input via delimitadores tokenizados; output sanitization (LLM output nao vai para `eval`/SQL/HTML sem escape); detection layer; least-privilege em agent tools; human-in-the-loop em acoes irreversiveis.

**Anti-fixes**: pedir ao LLM para "ignorar instrucoes anteriores" como defesa, prompt patching ad-hoc.

**Variants para PoC**: direct injection, indirect (payload em conteudo lido — webpage, doc, email), multi-turn degradation, cross-language, encoded (base64, ROT13), excessive agency (agent encadeia delete sem confirm).

## cyber-rem-rce (CWE-78, CWE-94) — RCE / Command Injection

**Fix canonico**: nunca passar input untrusted para `exec`/`spawn`/`eval`; usar APIs com argv array (sem shell); validar entrada contra schema rigido.

**Variants para PoC**: `; ls`, `| ls`, `` `ls` ``, `$(ls)`, newline `%0a`, eval em template.

## cyber-rem-hardening-headers (CWE-693) — Missing Security Headers

**Fix canonico**: middleware que injeta HSTS, CSP, X-Content-Type-Options, X-Frame-Options/CSP frame-ancestors, Referrer-Policy, Permissions-Policy. Cache-Control no-store em endpoints com PII.

## cyber-rem-user-enum (CWE-204) — User Enumeration

**Fix canonico**: mensagens de erro genericas em login/forgot/register; constant-time response timing; rate limit per-IP + per-account.

**Variants para PoC**: timing diff, mensagem distinta user existente vs inexistente.

## Variants taxonomy (cobertura obrigatoria em Negative PoC)

Cada variant listada acima DEVE aparecer em Negative PoC do Patch que aplica o pattern. Skill desenvolvedor gera Negative PoC a partir desta taxonomy.

## Anti-padroes cross-pattern

- Pattern aplicado sem Negative PoC -> Patch bloqueado por gate.
- Snippet copiado sem adaptar ao stack do cliente -> bug novo.
- Anti-fix ignorado -> regressao na proxima release.
- Coverage no path tocado < 100% em codigo `@security-critical` -> Patch bloqueado.
