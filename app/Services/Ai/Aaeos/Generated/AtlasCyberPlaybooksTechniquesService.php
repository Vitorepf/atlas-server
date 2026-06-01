<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Playbooks and Techniques — pure, deterministic technique router.
 *
 * Encodes the canonical Red technique catalog the doc consolidates by category
 * (webapp, api, mobile, cloud, network/AD, source review, crypto, supply chain,
 * AI/ML, IoT, threat modeling, red team). A cyber-* skill consults this router to
 * answer, for a requested technique label: which canonical category owns it, the
 * governing frameworks (OWASP / CWE / MITRE refs from the coverage table), the
 * primary skill that owns it (and whether that skill is live or still "futuro"),
 * and — critically — which execution gate the doc attaches to that technique
 * (e.g. "apenas com clausula red-team-c2", "apenas sandbox", "apenas test
 * devices", scope_proof + cost gate). The router NEVER executes a technique; it
 * only classifies and surfaces the documented constraints, in line with the
 * decision "Profundidade de execucao = decisao da skill em runtime, nao da KB".
 *
 * Contract (from the frontmatter decisions, the coverage table and the per-
 * category sub-technique lists):
 *   Entrada: requested technique { technique, environment, clauses[] }.
 *   Saida:   classification { category, frameworks[], primary_skill,
 *            skill_status, gate, gate_satisfied, allowed_to_propose, refs[] }.
 *
 * Documented invariants this code enforces:
 *   - Decision "Skills cyber-* referenciam secao desta doc, nao copiam": every
 *     technique resolves to exactly one canonical category that owns the section.
 *   - Decision "Tecnicas listadas em formato compacto (categoria -> sub-tecnica
 *     -> CWE/OWASP/ATT&CK ref)": each category surfaces its governing frameworks
 *     and a matched sub-technique carries its canonical reference token.
 *   - Gating clauses are enforced verbatim from the doc body:
 *       * NTLM Relay (T1187), C2/persistence, the whole Red Team section, and
 *         the Defense-Evasion sub-techniques require the `red-team-c2` clause.
 *       * Credential dumping (LSASS) is sandbox-only.
 *       * IoT glitching / RF deauth / evil-twin require a `test-devices` /
 *         explicit `clause`.
 *       * Cloud ephemeral attack boxes require `scope_proof` (+ a cost gate).
 *       * Training-data poisoning requires the model be client-trained.
 *     A technique whose gate clause is not supplied resolves to
 *     allowed_to_propose=false (the skill must NOT propose it to Atlas Decide).
 *   - "Profundidade de execucao = decisao da skill": the router never decides
 *     depth; gate_satisfied only reports whether the documented precondition holds.
 *   - Anti-padroes cross-categoria: validateEngagement() rejects the 7 documented
 *     anti-patterns (scanner-only-then-close, finding-without-repro, skipping
 *     business-logic, test-in-prod-without-clause, etc.).
 *
 * @see docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
 */
final class AtlasCyberPlaybooksTechniquesService
{
    /** Stable classification schema id this router emits. */
    public const RESULT_KIND = 'cyber.technique_classification';

    /** Canonical category slugs (one per "##" section of the doc). */
    public const CAT_WEBAPP = 'webapp';
    public const CAT_API = 'api';
    public const CAT_MOBILE = 'mobile';
    public const CAT_CLOUD = 'cloud';
    public const CAT_NETWORK_AD = 'network-ad';
    public const CAT_SOURCE_REVIEW = 'source-review';
    public const CAT_CRYPTO = 'crypto';
    public const CAT_SUPPLY_CHAIN = 'supply-chain';
    public const CAT_AI_ML = 'ai-ml';
    public const CAT_IOT = 'iot';
    public const CAT_THREAT_MODELING = 'threat-modeling';
    public const CAT_RED_TEAM = 'red-team';
    public const CAT_UNKNOWN = 'unknown';

    /** Execution-gate modes attached to a technique by the doc body. */
    public const GATE_NONE = 'none';                 // standard authorized testing, no extra clause.
    public const GATE_RED_TEAM_C2 = 'red-team-c2';   // "apenas com clausula red-team-c2".
    public const GATE_SANDBOX = 'sandbox-only';      // "apenas sandbox" (e.g. LSASS dump).
    public const GATE_TEST_DEVICES = 'test-devices'; // "apenas test devices" (glitching / RF with clause).
    public const GATE_SCOPE_PROOF = 'scope-proof';   // ephemeral attack boxes: scope_proof + cost gate.
    public const GATE_CLIENT_TRAINED = 'client-trained-model'; // training-data poisoning precondition.

    /** Skill lifecycle status from "Skill primaria" lines ("futuro" vs live). */
    public const SKILL_LIVE = 'live';
    public const SKILL_FUTURE = 'future';

    /**
     * Per-category descriptor from the "## <Category>" sections and the
     * "Cobertura de frameworks" table.
     *
     * @var array<string, array{
     *     frameworks:list<string>,
     *     primary_skill:string,
     *     skill_status:string
     * }>
     */
    private const CATEGORIES = [
        self::CAT_WEBAPP => [
            'frameworks' => ['OWASP Top 10 Web 2021', 'OWASP WSTG 4.2', 'CWE'],
            'primary_skill' => 'cyber-pentest-webapp',
            'skill_status' => self::SKILL_LIVE,
        ],
        self::CAT_API => [
            'frameworks' => ['OWASP API Top 10 2023', 'CWE'],
            'primary_skill' => 'cyber-pentest-webapp',
            'skill_status' => self::SKILL_LIVE, // live skill attends API too (future cyber-pentest-api).
        ],
        self::CAT_MOBILE => [
            'frameworks' => ['OWASP MASTG/MASVS v2', 'MITRE ATT&CK Mobile v15+'],
            'primary_skill' => 'cyber-pentest-mobile',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_CLOUD => [
            'frameworks' => ['CIS Hardening', 'MITRE ATT&CK Cloud'],
            'primary_skill' => 'cyber-pentest-cloud',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_NETWORK_AD => [
            'frameworks' => ['MITRE ATT&CK Enterprise v15+'],
            'primary_skill' => 'cyber-pentest-network-ad',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_SOURCE_REVIEW => [
            'frameworks' => ['OWASP ASVS', 'CWE Top 25', 'SANS Top 25'],
            'primary_skill' => 'cyber-pentest-webapp', // extends for source; future cyber-source-reviewer.
            'skill_status' => self::SKILL_LIVE,
        ],
        self::CAT_CRYPTO => [
            'frameworks' => ['NIST 800-131A', 'OWASP Cryptographic Storage'],
            'primary_skill' => 'cyber-crypto-reviewer',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_SUPPLY_CHAIN => [
            'frameworks' => ['CycloneDX', 'SLSA', 'MITRE ATT&CK Supply Chain'],
            'primary_skill' => 'cyber-supply-chain-auditor',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_AI_ML => [
            'frameworks' => ['OWASP LLM Top 10 2024', 'MITRE ATLAS v4+'],
            'primary_skill' => 'cyber-llm-redteamer',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_IOT => [
            'frameworks' => ['OWASP IoT Top 10', 'MITRE ATT&CK ICS'],
            'primary_skill' => 'cyber-pentest-iot',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_THREAT_MODELING => [
            'frameworks' => ['STRIDE', 'PASTA', 'Attack Trees', 'LINDDUN'],
            'primary_skill' => 'cyber-threat-modeler',
            'skill_status' => self::SKILL_FUTURE,
        ],
        self::CAT_RED_TEAM => [
            'frameworks' => ['MITRE ATT&CK Enterprise v15+'],
            'primary_skill' => 'cyber-red-teamer',
            'skill_status' => self::SKILL_FUTURE,
        ],
    ];

    /**
     * Canonical sub-technique map: needle -> {category, ref, gate}. Ordered most-
     * specific first so a gated needle (e.g. "ntlm relay") wins over a broader one.
     * `ref` is the doc's compact CWE/OWASP/ATT&CK token; `gate` is the documented
     * execution precondition.
     *
     * @var list<array{needles:list<string>,category:string,ref:string,gate:string}>
     */
    private const TECHNIQUES = [
        // --- Network / AD : the gated ones first (red-team-c2 / sandbox) ---
        ['needles' => ['ntlm relay'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'T1187', 'gate' => self::GATE_RED_TEAM_C2],
        ['needles' => ['lsass', 'credential dumping', 'lsass dump'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'T1003', 'gate' => self::GATE_SANDBOX],
        ['needles' => ['kerberoast', 'getuserspns'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'T1558.003', 'gate' => self::GATE_NONE],
        ['needles' => ['asreproast', 'getnpusers'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'T1558.004', 'gate' => self::GATE_NONE],
        ['needles' => ['pass-the-hash', 'pass the hash', 'pass-the-ticket', 'pth'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'T1550.002', 'gate' => self::GATE_NONE],
        ['needles' => ['gpp decrypt', 'gpp-decrypt', 'cpassword', 'group policy preferences'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'CVE-2014-1812', 'gate' => self::GATE_NONE],
        ['needles' => ['ad cs', 'esc1', 'esc8', 'certipy', 'certified pre-owned'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'ESC1-15', 'gate' => self::GATE_NONE],
        ['needles' => ['bloodhound', 'sharphound', 'dcsync', 'ldap enum'], 'category' => self::CAT_NETWORK_AD, 'ref' => 'MITRE ATT&CK', 'gate' => self::GATE_NONE],

        // --- Red Team / Adversary Emulation (whole section gated red-team-c2) ---
        ['needles' => ['c2 channel', 'command and control', 'c2'], 'category' => self::CAT_RED_TEAM, 'ref' => 'ATT&CK C2', 'gate' => self::GATE_RED_TEAM_C2],
        ['needles' => ['amsi bypass', 'etw patching', 'process injection', 'defense evasion'], 'category' => self::CAT_RED_TEAM, 'ref' => 'ATT&CK Defense Evasion', 'gate' => self::GATE_RED_TEAM_C2],
        ['needles' => ['adversary emulation', 'red team', 'persistence'], 'category' => self::CAT_RED_TEAM, 'ref' => 'ATT&CK Enterprise', 'gate' => self::GATE_RED_TEAM_C2],

        // --- Webapp ---
        ['needles' => ['single-packet', 'single packet race', 'turbo-intruder'], 'category' => self::CAT_WEBAPP, 'ref' => 'Race (Kettle 2023-2024)', 'gate' => self::GATE_NONE],
        ['needles' => ['request smuggling', 'desync', 'cl.te', 'te.cl', 'te.te'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-444', 'gate' => self::GATE_NONE],
        ['needles' => ['sqli', 'sql injection'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-89', 'gate' => self::GATE_NONE],
        ['needles' => ['xss', 'cross-site scripting'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-79', 'gate' => self::GATE_NONE],
        ['needles' => ['ssrf'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-918', 'gate' => self::GATE_NONE],
        ['needles' => ['idor', 'bola', 'broken object level'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-639', 'gate' => self::GATE_NONE],
        ['needles' => ['mass assignment'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-915', 'gate' => self::GATE_NONE],
        ['needles' => ['csrf'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-352', 'gate' => self::GATE_NONE],
        ['needles' => ['deserialization', 'insecure deserialization'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-502', 'gate' => self::GATE_NONE],
        ['needles' => ['xxe'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-611', 'gate' => self::GATE_NONE],
        ['needles' => ['path traversal', 'lfi', 'rfi'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-22', 'gate' => self::GATE_NONE],
        ['needles' => ['open redirect'], 'category' => self::CAT_WEBAPP, 'ref' => 'CWE-601', 'gate' => self::GATE_NONE],
        ['needles' => ['business logic', 'race condition'], 'category' => self::CAT_WEBAPP, 'ref' => 'OWASP Business Logic', 'gate' => self::GATE_NONE],

        // --- API ---
        ['needles' => ['graphql introspection', 'graphql'], 'category' => self::CAT_API, 'ref' => 'OWASP API 2023', 'gate' => self::GATE_NONE],
        ['needles' => ['grpc reflection', 'grpc'], 'category' => self::CAT_API, 'ref' => 'OWASP API 2023', 'gate' => self::GATE_NONE],
        ['needles' => ['broken function level', 'api5', 'function level auth'], 'category' => self::CAT_API, 'ref' => 'CWE-862', 'gate' => self::GATE_NONE],

        // --- Cloud (ephemeral attack box is gated scope-proof) ---
        ['needles' => ['attack box', 'ephemeral attack', 'attack boxes efemeras'], 'category' => self::CAT_CLOUD, 'ref' => 'IaC ofensivo', 'gate' => self::GATE_SCOPE_PROOF],
        ['needles' => ['iac offensive', 'iac ofensivo', 'checkov', 'tfsec', 'terraform drift'], 'category' => self::CAT_CLOUD, 'ref' => 'IaC ofensivo', 'gate' => self::GATE_NONE],
        ['needles' => ['imdsv1', 'imds'], 'category' => self::CAT_CLOUD, 'ref' => 'CIS AWS', 'gate' => self::GATE_NONE],
        ['needles' => ['public bucket', 's3 public', 'public s3'], 'category' => self::CAT_CLOUD, 'ref' => 'CIS AWS', 'gate' => self::GATE_NONE],
        ['needles' => ['cluster-admin', 'container escape', 'privileged pod', 'kubernetes rbac'], 'category' => self::CAT_CLOUD, 'ref' => 'CIS Kubernetes', 'gate' => self::GATE_NONE],

        // --- Mobile ---
        ['needles' => ['cert pinning', 'frida'], 'category' => self::CAT_MOBILE, 'ref' => 'MASTG Network', 'gate' => self::GATE_NONE],
        ['needles' => ['exported component', 'deep link', 'allowbackup'], 'category' => self::CAT_MOBILE, 'ref' => 'MASTG IPC', 'gate' => self::GATE_NONE],
        ['needles' => ['root detection bypass', 'jailbreak detection', 'keychain dump'], 'category' => self::CAT_MOBILE, 'ref' => 'MASTG Runtime', 'gate' => self::GATE_NONE],

        // --- Crypto ---
        ['needles' => ['padding oracle'], 'category' => self::CAT_CRYPTO, 'ref' => 'OWASP Crypto', 'gate' => self::GATE_NONE],
        ['needles' => ['jwt alg none', 'jwt manipulation', 'weak jwt secret'], 'category' => self::CAT_CRYPTO, 'ref' => 'JWT alg whitelist', 'gate' => self::GATE_NONE],
        ['needles' => ['nonce reuse', 'iv reuse', 'ecb', 'weak crypto'], 'category' => self::CAT_CRYPTO, 'ref' => 'NIST 800-131A', 'gate' => self::GATE_NONE],

        // --- Supply Chain ---
        ['needles' => ['sbom', 'syft'], 'category' => self::CAT_SUPPLY_CHAIN, 'ref' => 'CycloneDX', 'gate' => self::GATE_NONE],
        ['needles' => ['dependency confusion', 'typosquatting'], 'category' => self::CAT_SUPPLY_CHAIN, 'ref' => 'ATT&CK Supply Chain', 'gate' => self::GATE_NONE],
        ['needles' => ['cosign', 'slsa provenance', 'attestation'], 'category' => self::CAT_SUPPLY_CHAIN, 'ref' => 'SLSA', 'gate' => self::GATE_NONE],

        // --- AI/ML (training-data poisoning is client-trained gated) ---
        ['needles' => ['training data poisoning', 'training-data poisoning', 'llm03'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM03', 'gate' => self::GATE_CLIENT_TRAINED],
        ['needles' => ['prompt injection', 'llm01'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM01', 'gate' => self::GATE_NONE],
        ['needles' => ['rag poisoning', 'vector store contamination', 'embedding inversion'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM RAG poisoning', 'gate' => self::GATE_NONE],
        ['needles' => ['insecure output handling', 'llm02'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM02', 'gate' => self::GATE_NONE],
        ['needles' => ['excessive agency', 'llm08'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM08', 'gate' => self::GATE_NONE],
        ['needles' => ['jailbreak', 'dan', 'persona override'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM jailbreak', 'gate' => self::GATE_NONE],
        ['needles' => ['model theft', 'llm10', 'model extraction'], 'category' => self::CAT_AI_ML, 'ref' => 'LLM10', 'gate' => self::GATE_NONE],

        // --- IoT (glitching / RF gated test-devices) ---
        ['needles' => ['glitching', 'voltage glitch', 'fault injection'], 'category' => self::CAT_IOT, 'ref' => 'Hardware', 'gate' => self::GATE_TEST_DEVICES],
        ['needles' => ['deauth', 'evil twin', 'rogue ap'], 'category' => self::CAT_IOT, 'ref' => 'RF/wireless', 'gate' => self::GATE_TEST_DEVICES],
        ['needles' => ['firmware extraction', 'binwalk', 'uart shell', 'jtag'], 'category' => self::CAT_IOT, 'ref' => 'Firmware', 'gate' => self::GATE_NONE],

        // --- Source Review ---
        ['needles' => ['sast', 'semgrep', 'taint analysis'], 'category' => self::CAT_SOURCE_REVIEW, 'ref' => 'OWASP ASVS', 'gate' => self::GATE_NONE],
        ['needles' => ['secret scan', 'gitleaks', 'trufflehog'], 'category' => self::CAT_SOURCE_REVIEW, 'ref' => 'CWE Top 25', 'gate' => self::GATE_NONE],

        // --- Threat Modeling ---
        ['needles' => ['stride', 'threat model', 'attack tree', 'data flow diagram'], 'category' => self::CAT_THREAT_MODELING, 'ref' => 'STRIDE', 'gate' => self::GATE_NONE],
    ];

    /**
     * Which supplied clause token satisfies each gate. A gate is satisfied when
     * the corresponding token is present in the request `clauses` list — except
     * GATE_NONE which is always satisfied and GATE_SANDBOX which is satisfied by
     * asserting the environment is a sandbox.
     *
     * @var array<string, string>
     */
    private const GATE_CLAUSE = [
        self::GATE_RED_TEAM_C2 => 'red-team-c2',
        self::GATE_TEST_DEVICES => 'test-devices',
        self::GATE_SCOPE_PROOF => 'scope_proof',
        self::GATE_CLIENT_TRAINED => 'client_trained_model',
    ];

    /**
     * The 7 cross-category anti-patterns from "## Anti-padroes cross-categoria".
     * Each is a documented engagement failure that validateEngagement() rejects.
     *
     * @var array<string, string>
     */
    private const ANTI_PATTERNS = [
        'scanner_only_closed_as_pentest' => 'Running only a scanner (C1) and closing the engagement as a pentest.',
        'finding_without_repro' => 'Reporting a finding without reproduction in a canonical sub-technique of this KB.',
        'skipped_business_logic' => 'Skipping Business Logic "because it is hard".',
        'test_in_prod_without_clause' => 'Testing in prod without an explicit clause from the BB program.',
        'mass_assignment_ignored' => 'Ignoring mass assignment "because the API looks ok".',
        'xss_reflected_no_persistence_variant' => 'Reporting reflected XSS without testing the persistence variant.',
        'coverage_as_success_metric' => '100% coverage as a success metric (depth becomes the victim).',
    ];

    /**
     * Classify one requested technique and surface its category, frameworks,
     * owning skill and execution gate.
     *
     * @param array<string,mixed> $request
     *        technique   : string  the requested technique/action label.
     *        environment : string  prod | sandbox | test (default prod → conservative).
     *        clauses     : list    named clause tokens the operator supplied (default []).
     *
     * @return array<string,mixed>
     */
    public function classify(array $request): array
    {
        $technique = strtolower($this->str($request['technique'] ?? null) ?? '');
        $environment = $this->normalizeEnvironment($request['environment'] ?? null);
        $clauses = $this->normalizeClauses($request['clauses'] ?? []);

        $match = $this->matchTechnique($technique);

        if ($match === null) {
            // Unmatched technique: not in the canonical catalog. The doc forbids
            // reporting a finding without a canonical sub-technique, so an unknown
            // technique is NOT proposable until catalogued.
            return [
                'result_kind' => self::RESULT_KIND,
                'technique' => $technique,
                'matched' => false,
                'category' => self::CAT_UNKNOWN,
                'frameworks' => [],
                'primary_skill' => null,
                'skill_status' => null,
                'reference' => null,
                'gate' => self::GATE_NONE,
                'gate_satisfied' => false,
                'missing_clause' => 'uncatalogued_technique',
                'allowed_to_propose' => false,
                'rationale' => 'Technique is not in the canonical KB catalog; report only via a canonical sub-technique.',
            ];
        }

        $category = $match['category'];
        $cat = self::CATEGORIES[$category];
        $gate = $match['gate'];

        [$gateSatisfied, $missingClause] = $this->resolveGate($gate, $environment, $clauses);

        return [
            'result_kind' => self::RESULT_KIND,
            'technique' => $technique,
            'matched' => true,
            'category' => $category,
            'frameworks' => $cat['frameworks'],
            'primary_skill' => $cat['primary_skill'],
            'skill_status' => $cat['skill_status'],
            'reference' => $match['ref'],
            'gate' => $gate,
            'gate_satisfied' => $gateSatisfied,
            'missing_clause' => $missingClause,
            // A skill may only propose the technique to Atlas Decide when its
            // documented gate precondition holds.
            'allowed_to_propose' => $gateSatisfied,
            'rationale' => $gateSatisfied
                ? 'Technique is catalogued and its execution gate is satisfied.'
                : sprintf('Technique requires gate "%s"; precondition not met.', $gate),
        ];
    }

    /**
     * Validate a proposed engagement plan against the 7 documented cross-category
     * anti-patterns. Returns the list of violations (empty => clean).
     *
     * @param array<string,mixed> $plan
     *        scanner_only         : bool  closed using only an automated scanner.
     *        findings_reproduced  : bool  every finding reproduced via a canonical sub-technique.
     *        business_logic_tested: bool  business-logic class was actually tested.
     *        in_prod              : bool  testing touches production.
     *        prod_clause          : bool  explicit prod clause from the BB program.
     *        mass_assignment_tested : bool  mass assignment was tested.
     *        reflected_xss_found  : bool  a reflected XSS was found.
     *        persistence_variant_tested : bool  its stored/persistence variant was tested.
     *        success_metric       : string  the stated success metric (e.g. "coverage", "depth").
     *
     * @return array<string,mixed> { valid:bool, violations:list<array{id,reason}> }
     */
    public function validateEngagement(array $plan): array
    {
        $violations = [];

        if (($plan['scanner_only'] ?? false) === true) {
            $violations[] = $this->violation('scanner_only_closed_as_pentest');
        }

        // Default true: a plan that does not assert reproduction is treated as
        // reproducing (so only an explicit false trips the rule).
        if (($plan['findings_reproduced'] ?? true) === false) {
            $violations[] = $this->violation('finding_without_repro');
        }

        if (($plan['business_logic_tested'] ?? true) === false) {
            $violations[] = $this->violation('skipped_business_logic');
        }

        if (($plan['in_prod'] ?? false) === true && ($plan['prod_clause'] ?? false) !== true) {
            $violations[] = $this->violation('test_in_prod_without_clause');
        }

        if (($plan['mass_assignment_tested'] ?? true) === false) {
            $violations[] = $this->violation('mass_assignment_ignored');
        }

        if (($plan['reflected_xss_found'] ?? false) === true
            && ($plan['persistence_variant_tested'] ?? false) !== true) {
            $violations[] = $this->violation('xss_reflected_no_persistence_variant');
        }

        if (strtolower($this->str($plan['success_metric'] ?? null) ?? '') === 'coverage') {
            $violations[] = $this->violation('coverage_as_success_metric');
        }

        return [
            'result_kind' => 'cyber.engagement_validation',
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * Resolve a gate against the environment and supplied clauses.
     *
     * @param list<string> $clauses
     * @return array{0:bool,1:?string} [satisfied, missing_clause_or_null]
     */
    private function resolveGate(string $gate, string $environment, array $clauses): array
    {
        if ($gate === self::GATE_NONE) {
            return [true, null];
        }

        // Sandbox-only techniques (LSASS dump) require the environment be sandbox.
        if ($gate === self::GATE_SANDBOX) {
            return $environment === 'sandbox'
                ? [true, null]
                : [false, 'environment_must_be_sandbox'];
        }

        $needed = self::GATE_CLAUSE[$gate] ?? null;
        if ($needed === null) {
            // Unknown gate → conservative refusal.
            return [false, 'unknown_gate'];
        }

        return in_array($needed, $clauses, true)
            ? [true, null]
            : [false, $needed];
    }

    /**
     * Match a technique label against the canonical sub-technique table. First
     * (most-specific) hit wins.
     *
     * @return array{category:string,ref:string,gate:string}|null
     */
    private function matchTechnique(string $technique): ?array
    {
        if ($technique === '') {
            return null;
        }

        foreach (self::TECHNIQUES as $entry) {
            foreach ($entry['needles'] as $needle) {
                if (str_contains($technique, $needle)) {
                    return [
                        'category' => $entry['category'],
                        'ref' => $entry['ref'],
                        'gate' => $entry['gate'],
                    ];
                }
            }
        }

        return null;
    }

    /** @return array{id:string,reason:string} */
    private function violation(string $id): array
    {
        return ['id' => $id, 'reason' => self::ANTI_PATTERNS[$id]];
    }

    /** @return list<string> the canonical category slugs this router resolves. */
    public function categories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** @return list<string> the documented cross-category anti-pattern ids. */
    public function antiPatternIds(): array
    {
        return array_keys(self::ANTI_PATTERNS);
    }

    private function normalizeEnvironment(mixed $env): string
    {
        if (is_string($env)) {
            $key = strtolower(trim($env));
            if ($key === 'sandbox' || $key === 'test' || $key === 'prod') {
                return $key;
            }
        }

        // Unknown environment defaults to prod (the most conservative gate).
        return 'prod';
    }

    /**
     * @param mixed $clauses
     * @return list<string>
     */
    private function normalizeClauses(mixed $clauses): array
    {
        if (! is_array($clauses)) {
            return [];
        }

        $clean = [];
        foreach ($clauses as $c) {
            if (is_string($c) && trim($c) !== '') {
                $clean[] = strtolower(trim($c));
            }
        }

        return array_values(array_unique($clean));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
