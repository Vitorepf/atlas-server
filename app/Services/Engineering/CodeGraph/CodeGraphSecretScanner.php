<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · G-5 (keystone, [php] half) — Secret / PII scanner at the INGESTION edge
 * of the code graph.
 *
 * Before any external file's code or text is allowed to enter the graph (be parsed,
 * embedded, summarised, or handed to a provider), it passes through this scanner so
 * that indexing a second repo can NEVER ingest a live credential, a private key, an
 * `.env` value, or operator PII. This is the COMPLEMENT of
 * {@see CodeGraphPrivacyFilter}: that one redacts sensitive *nodes* on the way OUT of
 * the graph (egress); this one detects + redacts secret *content* on the way IN
 * (ingress). Together they bracket the graph — nothing sovereign enters, nothing
 * sovereign leaves.
 *
 * Capabilities:
 *
 *   - {@see scan()}    — locate every secret/PII finding with its type, severity,
 *                        1-based line number, and a MASKED preview (never the raw
 *                        secret).
 *   - {@see redact()}  — return the text with every detected secret replaced by a
 *                        `[REDACTED:<type>]` placeholder (safe to index/embed/log).
 *   - {@see hasSecrets()} — cheap boolean for an admission gate.
 *
 * Detection families (regex core):
 *   high   — AWS access key id (AKIA…), AWS secret access key, PEM/OpenSSH PRIVATE
 *            KEY blocks, GitHub tokens (ghp_/gho_/ghs_/ghr_/ghu_/github_pat_),
 *            Slack tokens (xox[baprs]-…), JWTs (eyJ… header.payload.signature),
 *            Stripe live/test keys (sk_/rk_/pk_…), DB/broker connection strings
 *            carrying an inline `user:password@host` credential (host may be a bare IP),
 *            and `password|secret|token|*_key` assignments carrying a real value —
 *            including COMPOUND keys (`DB_PASSWORD=`, `STRIPE_SECRET=`, `APP_KEY=`).
 *   medium — credit-card-shaped numbers (13–16 digits, Luhn-valid only).
 *   low    — e-mail addresses (PII).
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider, no filesystem. The
 *     same text always yields byte-identical output. Findings are emitted in a total
 *     order: line ASC, then byte-offset ASC, then type ASC — never relying on the
 *     order patterns happen to fire.
 *   - Never throws on malformed/empty/huge input. Every `preg_*` result is checked;
 *     a regex engine failure (e.g. backtrack/recursion limit on a pathological blob)
 *     degrades to "no match for that pattern", never an exception. Empty / whitespace
 *     input yields an empty, well-formed result.
 *   - Previews are ALWAYS masked: first {@see PREVIEW_PREFIX} chars + '***'. A secret
 *     shorter than the prefix is fully starred — the full secret is never echoed, by
 *     construction, regardless of pattern.
 *   - Config (the toggle for the optional generic high-entropy fallback) is read with
 *     an inline default literal so it works with no config edits.
 *
 * NOTE: ML-based PII NER (names, addresses, phone numbers in free prose, context-aware
 * de-identification) is the GATED [py] follow-up that runs in the python data runtime.
 * This class is the deterministic regex secret/PII CORE that the [php] kernel owns and
 * governs — it decides admission; it does not run heavy models.
 *
 * This is a [php] Kernel service by the runtime-language boundary: it GOVERNS what may
 * enter the graph (an admission decision); it does no heavy ML.
 */
class CodeGraphSecretScanner
{
    public const SCHEMA = 'atlas.code_graph.secret_scanner.v1';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    /** Number of leading characters shown in a masked preview before '***'. */
    private const PREVIEW_PREFIX = 4;

    /** Mask suffix appended after the visible prefix. */
    private const MASK_SUFFIX = '***';

    /**
     * Single-line detector table, evaluated in this fixed order for determinism.
     *
     * Each rule:
     *   - type     : stable machine label emitted in findings + `[REDACTED:<type>]`.
     *   - severity : one of the SEVERITY_* constants.
     *   - pattern  : a PCRE matching the SECRET ITSELF (not the whole line). When a
     *                rule needs surrounding context to anchor (e.g. an assignment),
     *                the secret token is captured in group 1 via {@see CAPTURE_GROUP}.
     *   - group    : (optional) capture-group index whose match is the actual secret
     *                to mask/redact. Defaults to 0 (the whole match) when absent.
     *   - validator: (optional) name of a private predicate (string): the match is a
     *                finding only if the predicate returns true (e.g. Luhn for cards).
     *
     * @var array<int,array{type:string, severity:string, pattern:string, group?:int, validator?:string}>
     */
    private const RULES = [
        // --- AWS ---
        [
            'type' => 'aws_access_key_id',
            'severity' => self::SEVERITY_HIGH,
            // AKIA/ASIA/AGPA/AIDA/AROA/AIPA/ANPA/ANVA/ASCA + 16 upper-alnum.
            'pattern' => '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA|AIPA|ANPA|ANVA|ASCA)[0-9A-Z]{16}\b/',
        ],
        [
            'type' => 'aws_secret_access_key',
            'severity' => self::SEVERITY_HIGH,
            // 40-char base64-ish secret tied to an aws_secret / aws-secret context so
            // we don't flag every 40-char blob. Secret captured in group 1.
            'pattern' => '/aws[_\-]?secret[_\-]?(?:access[_\-]?)?key[\"\'\s]*[:=][\"\'\s]*([A-Za-z0-9\/+=]{40})/i',
            'group' => 1,
        ],

        // --- Cloud / VCS provider tokens ---
        [
            'type' => 'github_token',
            'severity' => self::SEVERITY_HIGH,
            // Classic + fine-grained PATs and app tokens.
            'pattern' => '/\b(?:ghp|gho|ghs|ghr|ghu)_[A-Za-z0-9]{36,255}\b|\bgithub_pat_[A-Za-z0-9_]{22,255}\b/',
        ],
        [
            'type' => 'slack_token',
            'severity' => self::SEVERITY_HIGH,
            'pattern' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        ],

        // --- JWT ---
        [
            'type' => 'jwt',
            'severity' => self::SEVERITY_HIGH,
            // header.payload.signature; header starts with the base64url of '{"' = eyJ.
            'pattern' => '/\beyJ[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{2,}\b/',
        ],

        // --- Stripe (AP-815 G-5 D6: dedicated rule, placed BEFORE the generic
        // assignment so a BARE `sk_live_…` on its own line is caught — not only when it
        // happens to sit inside a recognized `api_key=…` assignment). ---
        [
            'type' => 'stripe_key',
            'severity' => self::SEVERITY_HIGH,
            'pattern' => self::STRIPE_KEY_PATTERN,
        ],

        // --- DB / broker connection strings (AP-815 G-5 D6: an inline credential in a
        // `scheme://user:password@host` URL. The host may be a bare IP, which the e-mail
        // PII shape could not catch). The password is captured in group 1 and validated
        // so a templated `…:${DB_PASS}@…` is not flagged. ---
        [
            'type' => 'connection_string',
            'severity' => self::SEVERITY_HIGH,
            'pattern' => self::CONNECTION_STRING_PATTERN,
            'group' => 1,
            'validator' => 'isRealAssignmentValue',
        ],

        // --- Generic credential assignments ---
        [
            'type' => 'generic_secret_assignment',
            'severity' => self::SEVERITY_HIGH,
            // password= / secret= / api_key= / apikey= / token= / passwd= / pwd= and
            // their COMPOUND forms (DB_PASSWORD=, STRIPE_SECRET=, APP_KEY=, *_auth_token=)
            // followed by a non-trivial value (quoted or bare, no whitespace). The
            // value is captured in group 1; surrounding quotes are excluded so the
            // mask/redaction targets the value, not the delimiters.
            'pattern' => self::ASSIGNMENT_PATTERN,
            'group' => 1,
            'validator' => 'isRealAssignmentValue',
        ],

        // --- PII ---
        [
            'type' => 'credit_card',
            'severity' => self::SEVERITY_MEDIUM,
            // 13–16 digits, optionally grouped by spaces or single hyphens. Whole
            // match validated by Luhn so random long numbers are not flagged.
            'pattern' => '/\b(?:\d[ -]?){12,15}\d\b/',
            'validator' => 'isLuhnValid',
        ],
        [
            'type' => 'email',
            'severity' => self::SEVERITY_LOW,
            'pattern' => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
        ],
    ];

    /**
     * Assignment detector. Kept as a named constant because it is referenced in the
     * rule table above. Group 1 = the secret value (without surrounding quotes).
     *
     * AP-815 G-5 D6: the leading anchor is
     * `(?<![A-Za-z0-9_])(?:[A-Za-z0-9]{1,64}_){0,8}` instead of a bare `\b`, so a COMPOUND
     * key whose keyword is prefixed by one or more `WORD_` segments (`DB_PASSWORD=`,
     * `STRIPE_SECRET=`, `MY_APP_AUTH_TOKEN=`) is caught — the old `\b(?:keyword)` missed
     * these because the `_` before the keyword is itself a word char, leaving no boundary.
     * The prefix repetition is bounded ({1,64} chars × {0,8} segments) so a pathological
     * `a_a_a_…` blob cannot drive catastrophic backtracking. The `_key` family uses a
     * CURATED prefix list (secret/private/app/api/…) so unambiguous secret keys
     * (`APP_KEY=`, `SECRET_KEY=`) are caught while non-secret ORM/cache identifiers
     * (`foreign_key=`, `cache_key=`, `primary_key=`) are NOT — that holds the
     * false-positive rate at the gate's measured floor. The trailing `\b` keeps the
     * keyword from matching mid-identifier (so `access_key_id=` does not fire here).
     */
    private const ASSIGNMENT_PATTERN =
        '/(?<![A-Za-z0-9_])(?:[A-Za-z0-9]{1,64}_){0,8}'
        .'(?:(?:secret|private|signing|encryption|encrypt|master|app|application|api|consumer|auth|access)[_\-]?key'
        .'|apikey'
        .'|client[_\-]?secret'
        .'|access[_\-]?token'
        .'|auth[_\-]?token'
        .'|password|passwd|pwd'
        .'|secret'
        .'|token)\b'
        ."[\"'\\s]*[:=]\\s*"
        .'(?:"([^"\n]{3,})"'      // double-quoted value
        ."|'([^'\\n]{3,})'"        // single-quoted value
        .'|([^\s"\'#;,)\]}]{3,}))/i'; // bare value up to a delimiter/comment

    /**
     * Stripe API keys (AP-815 G-5 D6). Secret (`sk_`), restricted (`rk_`) and
     * publishable (`pk_`) keys, live or test mode, e.g. `sk_live_…`. The token body is
     * base62; {16,255} covers both the legacy 24-char keys and the newer longer ones
     * without an unbounded quantifier. The `sk_live_`-style prefix is specific enough that
     * no surrounding assignment is needed and it stays false-positive-free on the corpus.
     */
    private const STRIPE_KEY_PATTERN =
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{16,255}\b/';

    /**
     * DB / message-broker connection strings carrying an INLINE credential
     * (`scheme://user:password@host…`), AP-815 G-5 D6. The `user:password@` shape is the
     * discriminator, so a plain URL with no credential (`https://host/path`) or a
     * host:port with no `@` (`http://host:8080/`) never trips. The host may be a hostname
     * OR a bare IP (`…@127.0.0.1/db`) — the IP case is exactly what the e-mail PII shape
     * (which needs a dotted alpha TLD) could not catch. Group 1 = the password; it is run
     * through {@see isRealAssignmentValue()} so a templated `…:${DB_PASS}@…` is dropped.
     */
    private const CONNECTION_STRING_PATTERN =
        '~\b[a-z][a-z0-9+.\-]*://[^\s:/@]+:([^\s:/@]{3,})@[^\s/?#]+~i';

    /**
     * The PEM / OpenSSH PRIVATE KEY block header. Matching the header alone is enough
     * to classify the block (we never need — and must never echo — the key body).
     */
    private const PRIVATE_KEY_HEADER =
        '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY-----/';

    /**
     * Full PRIVATE KEY block (header … footer) used for redaction so the entire key
     * body is removed, not just the header line. The body is matched non-greedily and
     * across newlines via the `s` flag.
     */
    private const PRIVATE_KEY_BLOCK =
        '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY-----/s';

    /**
     * Scan text for secrets/PII.
     *
     * @param  string  $text  raw code or document text. May be empty, binary-ish, or
     *                        very large; the scanner degrades gracefully and never throws.
     * @return array{
     *   findings: array<int,array{type:string, severity:string, line:int, preview:string}>,
     *   has_secrets: bool,
     *   count: int
     * }
     *   `findings` is ordered (line ASC, offset ASC, type ASC). `preview` is ALWAYS
     *   masked. `has_secrets` is (count > 0). For empty input every field is the
     *   zeroed/empty form.
     */
    public function scan(string $text): array
    {
        if ($text === '') {
            return ['findings' => [], 'has_secrets' => false, 'count' => 0];
        }

        // Collect raw hits as {type, severity, offset (byte), secret-substring}. We
        // resolve line numbers once from the byte offset so multi-line and single-line
        // detectors share one source of truth.
        $hits = $this->collectHits($text);

        // De-duplicate exact overlaps: the same secret substring at the same byte
        // offset detected by more than one rule (e.g. an AWS secret value that is also
        // a generic-assignment value) is reported once, keeping the MORE SPECIFIC /
        // higher-severity rule that fired first in RULES order.
        $hits = $this->dedupeHits($hits);

        // Total, stable order on the HITS (offset carries line ordering): offset ASC,
        // then type ASC. Sorting hits — which still carry the byte offset — keeps the
        // order total before we project away the offset into findings.
        usort($hits, static function (array $a, array $b): int {
            return $a['offset'] <=> $b['offset']
                ?: strcmp($a['type'], $b['type']);
        });

        $lineStarts = $this->lineStartOffsets($text);

        $findings = [];
        foreach ($hits as $hit) {
            $findings[] = [
                'type' => $hit['type'],
                'severity' => $hit['severity'],
                'line' => $this->lineForOffset($hit['offset'], $lineStarts),
                'preview' => $this->mask($hit['secret']),
            ];
        }

        return [
            'findings' => array_values($findings),
            'has_secrets' => $findings !== [],
            'count' => count($findings),
        ];
    }

    /**
     * Replace every detected secret with a `[REDACTED:<type>]` placeholder.
     *
     * Redaction targets the secret SUBSTRING (the value, the token, the whole key
     * block), never the surrounding code, so structure is preserved and the result is
     * safe to index/embed/log. Clean text is returned UNCHANGED.
     *
     * Replacements are applied from the LAST byte offset to the FIRST so that earlier
     * offsets are never invalidated by length changes, and longer matches at the same
     * start win over shorter ones (most-specific redaction). Deterministic.
     */
    public function redact(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $hits = $this->collectHits($text);
        $hits = $this->dedupeHits($hits);
        if ($hits === []) {
            return $text;
        }

        // Apply right-to-left (descending offset). For equal offsets, redact the
        // LONGER secret first so the shorter one is already consumed (avoids leaving a
        // dangling fragment). Ties on length break by type for determinism.
        usort($hits, static function (array $a, array $b): int {
            return $b['offset'] <=> $a['offset']
                ?: strlen($b['secret']) <=> strlen($a['secret'])
                ?: strcmp($a['type'], $b['type']);
        });

        $result = $text;
        // Lowest byte offset already overwritten by a placeholder. Because we apply
        // strictly right-to-left, any subsequent hit whose span reaches INTO that
        // region overlaps an existing redaction (a nested sub-match, or a value that
        // sits inside a longer key block) and must be skipped — re-writing it would
        // corrupt the placeholder we already inserted. This is a robust interval guard,
        // not a same-start special case.
        $minRedactedStart = PHP_INT_MAX;
        foreach ($hits as $hit) {
            $start = $hit['offset'];
            $length = strlen($hit['secret']);
            if ($length <= 0 || $start < 0) {
                continue;
            }
            $end = $start + $length; // exclusive
            if ($end > $minRedactedStart) {
                continue; // overlaps an already-applied (further-right) redaction
            }
            $placeholder = '[REDACTED:'.$hit['type'].']';
            $result = substr_replace($result, $placeholder, $start, $length);
            $minRedactedStart = $start;
        }

        return $result;
    }

    /**
     * Cheap admission predicate: does the text contain ANY secret/PII?
     */
    public function hasSecrets(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        // Short-circuit on the first hit rather than building the full finding list.
        if ($this->matchSafe(self::PRIVATE_KEY_HEADER, $text)) {
            return true;
        }
        foreach (self::RULES as $rule) {
            $hit = $this->firstHitForRule($rule, $text);
            if ($hit !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect every raw hit across all detectors.
     *
     * @return array<int,array{type:string, severity:string, offset:int, secret:string}>
     */
    private function collectHits(string $text): array
    {
        $hits = [];

        // Multi-line: PRIVATE KEY blocks. Redact/preview the WHOLE block, but the
        // preview only ever shows the masked header prefix (mask() handles length).
        if (preg_match_all(self::PRIVATE_KEY_BLOCK, $text, $blockMatches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($blockMatches[0] as $m) {
                $hits[] = [
                    'type' => 'private_key',
                    'severity' => self::SEVERITY_HIGH,
                    'offset' => $this->offsetOf($m),
                    'secret' => is_string($m[0]) ? $m[0] : '',
                ];
            }
        } elseif ($this->matchSafe(self::PRIVATE_KEY_HEADER, $text, $headerMatch, PREG_OFFSET_CAPTURE)) {
            // Header present but no closing footer (truncated paste). Still a secret:
            // flag from the header to end-of-line so we never miss a leaked key.
            $offset = $this->offsetOf($headerMatch[0]);
            $secret = is_string($headerMatch[0][0]) ? $headerMatch[0][0] : '';
            $hits[] = [
                'type' => 'private_key',
                'severity' => self::SEVERITY_HIGH,
                'offset' => $offset,
                'secret' => $secret,
            ];
        }

        foreach (self::RULES as $rule) {
            foreach ($this->allHitsForRule($rule, $text) as $hit) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    /**
     * Every hit for a single rule.
     *
     * @param  array{type:string, severity:string, pattern:string, group?:int, validator?:string}  $rule
     * @return array<int,array{type:string, severity:string, offset:int, secret:string}>
     */
    private function allHitsForRule(array $rule, string $text): array
    {
        $matches = [];
        $count = @preg_match_all($rule['pattern'], $text, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0 || ! isset($matches[0]) || ! is_array($matches[0])) {
            return [];
        }

        $group = $rule['group'] ?? 0;
        $validator = $rule['validator'] ?? null;

        $hits = [];
        $total = count($matches[0]);
        for ($i = 0; $i < $total; $i++) {
            // The secret token is the captured group when present and non-empty, else
            // the whole match. Some alternations leave intermediate groups empty (the
            // assignment rule has three value groups, only one fills) — pick the first
            // non-empty group from `group` upward so the masked value is correct.
            [$secret, $secretOffset] = $this->extractSecret($matches, $group, $i);
            if ($secret === '') {
                continue;
            }

            if ($validator !== null && ! $this->runValidator($validator, $secret)) {
                continue;
            }

            $hits[] = [
                'type' => $rule['type'],
                'severity' => $rule['severity'],
                'offset' => $secretOffset,
                'secret' => $secret,
            ];
        }

        return $hits;
    }

    /**
     * The first hit for a rule (used by the short-circuiting {@see hasSecrets()}).
     *
     * @param  array{type:string, severity:string, pattern:string, group?:int, validator?:string}  $rule
     * @return array{type:string, severity:string, offset:int, secret:string}|null
     */
    private function firstHitForRule(array $rule, string $text): ?array
    {
        $hits = $this->allHitsForRule($rule, $text);

        return $hits[0] ?? null;
    }

    /**
     * Resolve the secret substring + its byte offset from a PREG_OFFSET_CAPTURE
     * match set for match index $i, preferring capture group $group but falling
     * through to later groups (for multi-alternation value captures) and finally to
     * the whole match.
     *
     * @param  array<int,array<int,array{0:string,1:int}>>  $matches
     * @return array{0:string,1:int} [secret, byteOffset]
     */
    private function extractSecret(array $matches, int $group, int $i): array
    {
        // Try the requested group, then any subsequent groups (alternation), then 0.
        $candidates = [];
        if ($group > 0) {
            $maxGroup = count($matches) - 1;
            for ($g = $group; $g <= $maxGroup; $g++) {
                $candidates[] = $g;
            }
        }
        $candidates[] = 0;

        foreach ($candidates as $g) {
            if (! isset($matches[$g][$i]) || ! is_array($matches[$g][$i])) {
                continue;
            }
            $value = $matches[$g][$i][0];
            $offset = $this->offsetOf($matches[$g][$i]);
            // offset -1 means the group did not participate in this match.
            if (is_string($value) && $value !== '' && $offset >= 0) {
                return [$value, $offset];
            }
        }

        return ['', 0];
    }

    /**
     * De-duplicate hits that share the same byte offset AND the same secret text,
     * keeping the FIRST occurrence (RULES order: private_key, then AWS, …) which is
     * the most specific/severe. Also drops a hit whose span is fully contained in
     * another hit at the same start (a sub-match of a broader detector).
     *
     * @param  array<int,array{type:string, severity:string, offset:int, secret:string}>  $hits
     * @return array<int,array{type:string, severity:string, offset:int, secret:string}>
     */
    private function dedupeHits(array $hits): array
    {
        $seen = [];
        $unique = [];
        foreach ($hits as $hit) {
            $key = $hit['offset'].':'.$hit['secret'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $hit;
        }

        return $unique;
    }

    /**
     * Mask a secret for display: first {@see PREVIEW_PREFIX} characters + '***'.
     * A secret shorter than the prefix is entirely starred so the full value is never
     * echoed. Multi-byte safe (operates on characters, not bytes) and newline-free
     * (a multi-line key block previews only its first line's leading chars).
     */
    private function mask(string $secret): string
    {
        // Collapse to the first line so a key block never leaks body bytes into a
        // preview, and trim so leading whitespace does not eat the visible prefix.
        $firstLine = $secret;
        $newlinePos = strpos($firstLine, "\n");
        if ($newlinePos !== false) {
            $firstLine = substr($firstLine, 0, $newlinePos);
        }
        $firstLine = rtrim($firstLine, "\r");

        $length = $this->charLength($firstLine);
        if ($length <= self::PREVIEW_PREFIX) {
            // Too short to safely show a prefix → fully masked.
            return self::MASK_SUFFIX;
        }

        $prefix = $this->charSubstr($firstLine, 0, self::PREVIEW_PREFIX);

        return $prefix.self::MASK_SUFFIX;
    }

    /**
     * Byte offsets at which each line starts (line 1 starts at 0). Used to map a
     * byte offset to a 1-based line number.
     *
     * @return array<int,int> ascending list of line-start byte offsets
     */
    private function lineStartOffsets(string $text): array
    {
        $starts = [0];
        $pos = 0;
        $len = strlen($text);
        while ($pos < $len) {
            $nl = strpos($text, "\n", $pos);
            if ($nl === false) {
                break;
            }
            $starts[] = $nl + 1;
            $pos = $nl + 1;
        }

        return $starts;
    }

    /**
     * 1-based line number for a byte offset given precomputed line-start offsets.
     *
     * @param  array<int,int>  $lineStarts
     */
    private function lineForOffset(int $offset, array $lineStarts): int
    {
        if ($offset <= 0) {
            return 1;
        }

        // Binary search for the greatest line-start <= offset.
        $lo = 0;
        $hi = count($lineStarts) - 1;
        $line = 0;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($lineStarts[$mid] <= $offset) {
                $line = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $line + 1; // 1-based
    }

    /**
     * Validate a generic-assignment value: reject obvious non-secrets so we don't
     * flood findings with `password=` placeholders. A value is "real" when it is not a
     * common placeholder/empty/templated token.
     */
    private function isRealAssignmentValue(string $value): bool
    {
        $trimmed = trim($value);
        if ($this->charLength($trimmed) < 3) {
            return false;
        }

        // Template / interpolation markers → not a literal secret (e.g. ${PASSWORD},
        // {{ secret }}, <your-password>, %env(SECRET)%).
        if (preg_match('/[\$\{\}<>%]/', $trimmed) === 1) {
            return false;
        }

        $lower = strtolower($trimmed);
        $placeholders = [
            'null', 'nil', 'none', 'true', 'false', 'changeme', 'change_me',
            'password', 'secret', 'example', 'your_password', 'your-password',
            'xxx', 'xxxx', 'todo', 'redacted', 'placeholder', 'test', 'dummy',
        ];

        return ! in_array($lower, $placeholders, true);
    }

    /**
     * Luhn check for a candidate credit-card number (digits may be space/hyphen
     * separated). Returns true only for a 13–16 digit Luhn-valid sequence — this is
     * what keeps random long integers (timestamps, ids) from being flagged.
     */
    private function isLuhnValid(string $candidate): bool
    {
        $digits = preg_replace('/\D+/', '', $candidate);
        if (! is_string($digits)) {
            return false;
        }
        $len = strlen($digits);
        if ($len < 13 || $len > 16) {
            return false;
        }

        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    /**
     * Dispatch a named validator predicate by string (table-driven, no variable
     * method-call surprises). Unknown validator names fail OPEN to "valid" only after
     * the regex already matched, but we keep a closed allow-list so a typo never
     * silently disables a check — an unknown name is treated as a non-match (safe:
     * better to drop a finding than to crash, and the allow-list is fixed in code).
     */
    private function runValidator(string $name, string $value): bool
    {
        return match ($name) {
            'isLuhnValid' => $this->isLuhnValid($value),
            'isRealAssignmentValue' => $this->isRealAssignmentValue($value),
            default => true,
        };
    }

    /**
     * Safe single-match wrapper around preg_match: returns false (no match) instead of
     * throwing/emitting on a regex engine failure.
     *
     * @param  array<int,mixed>|null  $matches
     */
    private function matchSafe(string $pattern, string $subject, ?array &$matches = null, int $flags = 0): bool
    {
        $matches = [];
        $result = @preg_match($pattern, $subject, $matches, $flags);

        return $result === 1;
    }

    /**
     * Byte offset from a single PREG_OFFSET_CAPTURE entry [string, offset], guarding
     * against malformed/non-int offsets.
     */
    private function offsetOf(mixed $entry): int
    {
        if (is_array($entry) && isset($entry[1]) && is_int($entry[1])) {
            return $entry[1];
        }

        return 0;
    }

    /** Multi-byte-aware character length, falling back to byte length. */
    private function charLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    /** Multi-byte-aware substring, falling back to byte substring. */
    private function charSubstr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length, 'UTF-8');
        }

        return (string) substr($value, $start, $length);
    }
}
