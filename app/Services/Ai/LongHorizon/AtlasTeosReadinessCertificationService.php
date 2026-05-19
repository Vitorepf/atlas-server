<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\ProgrammingRuntime\FilesystemRepoProbe;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Carbon\CarbonImmutable;

/**
 * Atlas Temporal Engineering Operating System (TEOS) — canonical readiness
 * + certification service.
 *
 * Schema: `atlas.teos.readiness_certification.v1`.
 *
 * **Honesty invariants (declared as hard, not heuristics):**
 *   1. Status `ready` is REFUSED while any P0/P1 check is `fail`.
 *   2. `external_claim_status` is ALWAYS `not_claimed`. This service does not
 *      promote TEOS completion against external rivals/benchmarks.
 *   3. `provider_calls_made` is ALWAYS `false`. Readiness must NEVER invoke
 *      Claude/Codex/Gemini or any other provider — the certification is a
 *      pure file-system + class-existence audit.
 *   4. `atlas_decide_topology_modified` is ALWAYS `false`. TEOS is forbidden
 *      from rewriting Atlas Decide / Provider Topology contracts.
 *
 * Companion to:
 *   - `AtlasProgrammingFinalCertificationService` (Programming Runtime).
 *   - `ProgrammingRuntimeReadinessService` (governance gap audit).
 *
 * Different from both: this service audits the TEOS-I1 canon (continuation
 * pack v2 + compaction receipt + freshness gate + recovery planner +
 * memory promotion guard + docs + tests + commands) without claiming the
 * TEOS architecture itself is complete. TEOS-I2/I3 land later; this readiness
 * intentionally returns `partial`/`blocked` until the canon ships every part.
 */
class AtlasTeosReadinessCertificationService
{
    public const SCHEMA_VERSION = 'atlas.teos.readiness_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_STATUS_PASS = 'pass';

    public const CHECK_STATUS_WARN = 'warn';

    public const CHECK_STATUS_FAIL = 'fail';

    public const SEVERITY_P0 = 'P0';

    public const SEVERITY_P1 = 'P1';

    public const SEVERITY_P2 = 'P2';

    public const EXTERNAL_CLAIM_NOT_CLAIMED = 'not_claimed';

    public const CHECK_CANONICAL_DOCS_PRESENT = 'canonical_docs_present';

    public const CHECK_LONG_HORIZON_CANON_SERVICE = 'long_horizon_canon_service';

    public const CHECK_CONTINUATION_PACK_PERSISTENCE = 'continuation_pack_persistence';

    public const CHECK_COMPACTION_RECEIPT_PERSISTENCE = 'compaction_receipt_persistence';

    public const CHECK_FRESHNESS_GATE_AVAILABLE = 'freshness_gate_available';

    public const CHECK_RECOVERY_PLANNER_AVAILABLE = 'recovery_planner_available';

    public const CHECK_MEMORY_PROMOTION_GUARD_AVAILABLE = 'memory_promotion_guard_available';

    public const CHECK_FOCUSED_TESTS_PRESENT = 'focused_tests_present';

    public const CHECK_HYPERFLOW_CANON_DOC_PRESENT = 'hyperflow_canon_doc_present';

    public const CHECK_DECIDE_TOPOLOGY_UNTOUCHED = 'decide_topology_untouched';

    public const CHECK_NO_REAL_PROVIDER_CALLED = 'no_real_provider_called';

    public const CHECK_NO_EXTERNAL_CLAIM_PROMOTED = 'no_external_claim_promoted';

    public const ALL_CHECK_IDS = [
        self::CHECK_CANONICAL_DOCS_PRESENT,
        self::CHECK_LONG_HORIZON_CANON_SERVICE,
        self::CHECK_CONTINUATION_PACK_PERSISTENCE,
        self::CHECK_COMPACTION_RECEIPT_PERSISTENCE,
        self::CHECK_FRESHNESS_GATE_AVAILABLE,
        self::CHECK_RECOVERY_PLANNER_AVAILABLE,
        self::CHECK_MEMORY_PROMOTION_GUARD_AVAILABLE,
        self::CHECK_FOCUSED_TESTS_PRESENT,
        self::CHECK_HYPERFLOW_CANON_DOC_PRESENT,
        self::CHECK_DECIDE_TOPOLOGY_UNTOUCHED,
        self::CHECK_NO_REAL_PROVIDER_CALLED,
        self::CHECK_NO_EXTERNAL_CLAIM_PROMOTED,
    ];

    public function __construct(
        private readonly RepoProbe $probe = new FilesystemRepoProbe,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->checkCanonicalDocsPresent(),
            $this->checkLongHorizonCanonService(),
            $this->checkContinuationPackPersistence(),
            $this->checkCompactionReceiptPersistence(),
            $this->checkFreshnessGateAvailable(),
            $this->checkRecoveryPlannerAvailable(),
            $this->checkMemoryPromotionGuardAvailable(),
            $this->checkFocusedTestsPresent(),
            $this->checkHyperflowCanonDocPresent(),
            $this->checkDecideTopologyUntouched(),
            $this->checkNoRealProviderCalled(),
            $this->checkNoExternalClaimPromoted(),
        ];

        $status = $this->aggregateStatus($checks);
        $summary = $this->summarize($checks);
        $blockers = $this->collectBlockers($checks);
        $remediation = $this->collectRemediation($checks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'summary' => $summary,
            'checks' => $checks,
            'blockers' => $blockers,
            'remediation' => $remediation,
            'external_claim_status' => self::EXTERNAL_CLAIM_NOT_CLAIMED,
            'external_claim_note' => 'TEOS readiness NEVER claims completion against external rivals (Claude Code / Codex / Cursor). Promotion is a separate governance flow.',
            'provider_calls_made' => false,
            'atlas_decide_topology_modified' => false,
            'safety_invariants' => [
                'no_provider_invocation' => true,
                'no_topology_mutation' => true,
                'no_external_completion_claim' => true,
                'file_system_audit_only' => true,
            ],
            'evidence_refs' => $this->topLevelEvidenceRefs($checks),
        ];

        $hashPayload = $payload;
        $payload['generated_at'] = CarbonImmutable::now()->toISOString();
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCanonicalDocsPresent(): array
    {
        $docs = [
            'docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md',
            'docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md',
            'docs/engineering-knowledge-base/atlas-teos-existing-code-map.md',
        ];

        $missing = [];
        foreach ($docs as $doc) {
            if (! $this->probe->fileExists($doc)) {
                $missing[] = $doc;
            }
        }

        if ($missing === []) {
            return $this->pass(
                self::CHECK_CANONICAL_DOCS_PRESENT,
                self::SEVERITY_P0,
                'All 6 TEOS canon docs (plan + temporal-os trinity + long-horizon layer + code map) present',
                $docs,
            );
        }

        return $this->fail(
            self::CHECK_CANONICAL_DOCS_PRESENT,
            self::SEVERITY_P0,
            'Missing TEOS canon docs: '.implode(', ', $missing),
            'restore the missing canonical docs before claiming TEOS readiness; each maps to a specific increment of the plan',
            $docs,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLongHorizonCanonService(): array
    {
        $path = 'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php';
        if (! $this->probe->fileExists($path)) {
            return $this->fail(
                self::CHECK_LONG_HORIZON_CANON_SERVICE,
                self::SEVERITY_P0,
                'AtlasLongHorizonCanon class is missing',
                'reinstate AtlasLongHorizonCanon — it owns the 11 canonical scope_types and 7 safe_resume_modes',
                [$path],
            );
        }
        $source = (string) $this->probe->readFile($path);
        $missingTokens = [];
        foreach ([
            'CONTINUATION_PACK_SCHEMA_VERSION',
            'COMPACTION_RECEIPT_SCHEMA_VERSION',
            'RECOVERY_PLAN_SCHEMA_VERSION',
            'ALLOWED_SAFE_RESUME_MODES',
            'ALLOWED_SCOPE_TYPES',
        ] as $token) {
            if (! str_contains($source, $token)) {
                $missingTokens[] = $token;
            }
        }
        if ($missingTokens !== []) {
            return $this->fail(
                self::CHECK_LONG_HORIZON_CANON_SERVICE,
                self::SEVERITY_P0,
                'AtlasLongHorizonCanon present but missing tokens: '.implode(',', $missingTokens),
                'restore the canonical constants — they are referenced by every TEOS-I1 service',
                [$path],
            );
        }

        return $this->pass(
            self::CHECK_LONG_HORIZON_CANON_SERVICE,
            self::SEVERITY_P0,
            'AtlasLongHorizonCanon present and exposes canonical schemas/enums',
            [$path],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContinuationPackPersistence(): array
    {
        $required = [
            'model' => 'app/Models/AtlasLongHorizonContinuationPack.php',
            'migration' => 'database/migrations/2026_05_19_040000_create_atlas_long_horizon_continuation_pack_and_compaction_receipt_tables.php',
            'test_trait' => 'tests/Concerns/CreatesLongHorizonPersistenceTables.php',
        ];

        $missing = [];
        foreach ($required as $label => $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $label.':'.$path;
            }
        }

        return $missing === []
            ? $this->pass(
                self::CHECK_CONTINUATION_PACK_PERSISTENCE,
                self::SEVERITY_P0,
                'continuation_pack.v2 persistence wired (model + migration + test trait)',
                array_values($required),
            )
            : $this->fail(
                self::CHECK_CONTINUATION_PACK_PERSISTENCE,
                self::SEVERITY_P0,
                'continuation_pack.v2 persistence incomplete: '.implode(', ', $missing),
                'ship the missing pieces under TEOS-I1 Sprint 2 before claiming readiness',
                array_values($required),
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompactionReceiptPersistence(): array
    {
        $path = 'app/Models/AtlasLongHorizonCompactionReceipt.php';

        return $this->probe->fileExists($path)
            ? $this->pass(
                self::CHECK_COMPACTION_RECEIPT_PERSISTENCE,
                self::SEVERITY_P0,
                'compaction_receipt.v1 model present (shares migration with continuation pack)',
                [$path],
            )
            : $this->fail(
                self::CHECK_COMPACTION_RECEIPT_PERSISTENCE,
                self::SEVERITY_P0,
                'compaction_receipt.v1 model missing',
                'restore AtlasLongHorizonCompactionReceipt; consumed by AiCompactionService outputs',
                [$path],
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFreshnessGateAvailable(): array
    {
        $gate = 'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php';
        $result = 'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGateResult.php';

        return $this->probe->fileExists($gate) && $this->probe->fileExists($result)
            ? $this->pass(
                self::CHECK_FRESHNESS_GATE_AVAILABLE,
                self::SEVERITY_P1,
                'LongHorizonContextFreshnessGate + result DTO present',
                [$gate, $result],
            )
            : $this->fail(
                self::CHECK_FRESHNESS_GATE_AVAILABLE,
                self::SEVERITY_P1,
                'LongHorizonContextFreshnessGate not fully wired',
                'restore gate service + result DTO; consumed by RecoveryPlannerService',
                [$gate, $result],
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRecoveryPlannerAvailable(): array
    {
        $path = 'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php';

        return $this->probe->fileExists($path)
            ? $this->pass(
                self::CHECK_RECOVERY_PLANNER_AVAILABLE,
                self::SEVERITY_P0,
                'LongHorizonRecoveryPlannerService present (emits atlas.long_horizon.recovery_plan.v1)',
                [$path],
            )
            : $this->fail(
                self::CHECK_RECOVERY_PLANNER_AVAILABLE,
                self::SEVERITY_P0,
                'LongHorizonRecoveryPlannerService missing',
                'restore the recovery planner — it owns the safe_resume_mode decision logic',
                [$path],
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMemoryPromotionGuardAvailable(): array
    {
        $guard = 'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionGuard.php';
        $exception = 'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionRefusedException.php';

        return $this->probe->fileExists($guard) && $this->probe->fileExists($exception)
            ? $this->pass(
                self::CHECK_MEMORY_PROMOTION_GUARD_AVAILABLE,
                self::SEVERITY_P1,
                'LongHorizonMemoryPromotionGuard + refused exception present',
                [$guard, $exception],
            )
            : $this->warn(
                self::CHECK_MEMORY_PROMOTION_GUARD_AVAILABLE,
                self::SEVERITY_P1,
                'Memory promotion guard partially wired',
                'restore guard + exception; auto-promotion of long-horizon memory is forbidden without operator approval',
                [$guard, $exception],
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFocusedTestsPresent(): array
    {
        $required = [
            'tests/Feature/Ai/LongHorizon/AtlasLongHorizonPersistenceTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonRecoveryPlannerServiceTest.php',
        ];
        $optional = [
            'tests/Feature/Ai/LongHorizon/LongHorizonContextFreshnessGateTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonMemoryScopesAndPromotionGuardTest.php',
        ];

        $missing = [];
        foreach ($required as $path) {
            if (! $this->probe->fileExists($path)) {
                $missing[] = $path;
            }
        }
        $missingOptional = [];
        foreach ($optional as $path) {
            if (! $this->probe->fileExists($path)) {
                $missingOptional[] = $path;
            }
        }

        if ($missing !== []) {
            return $this->fail(
                self::CHECK_FOCUSED_TESTS_PRESENT,
                self::SEVERITY_P0,
                'Required TEOS-I1 tests missing: '.implode(', ', $missing),
                'reinstate the canonical persistence + recovery planner tests before readiness can flip to ready',
                array_merge($required, $optional),
            );
        }
        if ($missingOptional !== []) {
            return $this->warn(
                self::CHECK_FOCUSED_TESTS_PRESENT,
                self::SEVERITY_P1,
                'Optional TEOS-I1 tests absent: '.implode(', ', $missingOptional),
                'add freshness gate + memory promotion tests to lift the warn flag',
                array_merge($required, $optional),
            );
        }

        return $this->pass(
            self::CHECK_FOCUSED_TESTS_PRESENT,
            self::SEVERITY_P0,
            'TEOS-I1 focused tests (persistence + recovery planner + freshness gate + promotion guard) present',
            array_merge($required, $optional),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkHyperflowCanonDocPresent(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-hyperflow-operation.md';

        return $this->probe->fileExists($path)
            ? $this->pass(
                self::CHECK_HYPERFLOW_CANON_DOC_PRESENT,
                self::SEVERITY_P1,
                'Atlas Hyperflow operation canon doc present (TEOS routes via Hyperflow, never bypasses)',
                [$path],
            )
            : $this->warn(
                self::CHECK_HYPERFLOW_CANON_DOC_PRESENT,
                self::SEVERITY_P1,
                'Hyperflow operation canon doc absent',
                'restore the Hyperflow doc so TEOS readiness can audit cross-canon alignment',
                [$path],
            );
    }

    /**
     * Audita que TEOS NÃO altere o contrato de Atlas Decide / Provider
     * Topology. Esta checagem é estática: garante que nenhum service do
     * namespace LongHorizon/ importa ou mutates os services canônicos de
     * Atlas Decide / Provider Topology (que são propriedade de outro canon).
     *
     * @return array<string,mixed>
     */
    private function checkDecideTopologyUntouched(): array
    {
        $files = [
            'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php',
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionGuard.php',
            'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php',
        ];
        $forbiddenWriteTokens = [
            'AtlasForgeProviderTopologyService',
            'AtlasForgeProviderFallbackPolicyService',
        ];

        $offenders = [];
        foreach ($files as $f) {
            if (! $this->probe->fileExists($f)) {
                continue;
            }
            $src = (string) $this->probe->readFile($f);
            foreach ($forbiddenWriteTokens as $token) {
                if ($this->sourceUsesToken($src, $token)) {
                    $offenders[] = $f.':'.$token;
                }
            }
        }

        return $offenders === []
            ? $this->pass(
                self::CHECK_DECIDE_TOPOLOGY_UNTOUCHED,
                self::SEVERITY_P0,
                'LongHorizon namespace does NOT reference Atlas Decide / Provider Topology services (boundary preserved)',
                $files,
            )
            : $this->fail(
                self::CHECK_DECIDE_TOPOLOGY_UNTOUCHED,
                self::SEVERITY_P0,
                'TEOS code referenced Atlas Decide / Provider Topology services — boundary violation: '.implode(', ', $offenders),
                'remove the cross-canon import; TEOS must consume Atlas Decide via documented contract, not direct service mutation',
                $files,
            );
    }

    /**
     * Audita estaticamente que nenhum serviço TEOS **usa** provider externo
     * (Claude/Codex/Gemini). O detector procura uso real (statements `use `
     * ou `new `) e não menções textuais — caso contrário esta própria classe,
     * que enumera os tokens para auditoria, seria flag falsamente positivo.
     *
     * @return array<string,mixed>
     */
    private function checkNoRealProviderCalled(): array
    {
        // Files audited. NOTE: este service NÃO está aqui — ele lista os
        // tokens proibidos textualmente como parte do contrato de auditoria,
        // não como uso. O escaneamento de uso real cobre os 5 services TEOS.
        $files = [
            'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php',
            'app/Services/Ai/LongHorizon/LongHorizonRecoveryPlannerService.php',
            'app/Services/Ai/LongHorizon/LongHorizonMemoryPromotionGuard.php',
            'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php',
            'app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGateResult.php',
        ];
        $forbiddenInvocationTokens = [
            'AtlasForgeClaudeCliInvocationDriver',
            'AtlasForgeCodexCliInvocationDriver',
            'AtlasForgeGeminiCliInvocationDriver',
            'AtlasForgeProviderInvocationService',
            'AtlasForgeProviderProcessRunner',
        ];

        $offenders = [];
        foreach ($files as $f) {
            if (! $this->probe->fileExists($f)) {
                continue;
            }
            $src = (string) $this->probe->readFile($f);
            foreach ($forbiddenInvocationTokens as $token) {
                if ($this->sourceUsesToken($src, $token)) {
                    $offenders[] = $f.':'.$token;
                }
            }
        }

        return $offenders === []
            ? $this->pass(
                self::CHECK_NO_REAL_PROVIDER_CALLED,
                self::SEVERITY_P0,
                'TEOS readiness does NOT invoke any provider CLI driver (Claude / Codex / Gemini)',
                $files,
            )
            : $this->fail(
                self::CHECK_NO_REAL_PROVIDER_CALLED,
                self::SEVERITY_P0,
                'TEOS code references provider invocation drivers — readiness must remain provider-free: '.implode(', ', $offenders),
                'remove provider invocation imports from LongHorizon services; readiness is a static audit only',
                $files,
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoExternalClaimPromoted(): array
    {
        // This check is intrinsic: the service hard-codes
        // `external_claim_status = not_claimed`. The check exists so the
        // payload shows the operator we audited this invariant explicitly.
        return $this->pass(
            self::CHECK_NO_EXTERNAL_CLAIM_PROMOTED,
            self::SEVERITY_P2,
            'TEOS readiness emits external_claim_status=not_claimed (no external benchmark/rival completion promoted)',
            [
                'docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md',
                'docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md',
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $hasFail = false;
        $hasP0OrP1Warn = false;
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            $severity = (string) ($check['severity'] ?? '');
            if ($status === self::CHECK_STATUS_FAIL) {
                $hasFail = true;
            }
            if ($status === self::CHECK_STATUS_WARN
                && in_array($severity, [self::SEVERITY_P0, self::SEVERITY_P1], true)) {
                $hasP0OrP1Warn = true;
            }
        }
        if ($hasFail) {
            return self::STATUS_BLOCKED;
        }
        if ($hasP0OrP1Warn) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = [
            'total' => count($checks),
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'p0_blockers' => 0,
            'p1_blockers' => 0,
        ];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            $severity = (string) ($check['severity'] ?? '');
            if ($status === self::CHECK_STATUS_PASS) {
                $summary['pass']++;
            } elseif ($status === self::CHECK_STATUS_WARN) {
                $summary['warn']++;
            } elseif ($status === self::CHECK_STATUS_FAIL) {
                $summary['fail']++;
                if ($severity === self::SEVERITY_P0) {
                    $summary['p0_blockers']++;
                }
                if ($severity === self::SEVERITY_P1) {
                    $summary['p1_blockers']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function collectBlockers(array $checks): array
    {
        $blockers = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === self::CHECK_STATUS_FAIL) {
                $blockers[] = [
                    'check_id' => $check['check_id'],
                    'severity' => $check['severity'],
                    'reason' => $check['reason'],
                    'evidence_refs' => array_values((array) ($check['evidence_refs'] ?? [])),
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    private function collectRemediation(array $checks): array
    {
        $remediation = [];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if ($status === self::CHECK_STATUS_PASS) {
                continue;
            }
            $action = (string) ($check['remediation'] ?? '');
            if ($action === '') {
                continue;
            }
            $remediation[] = [
                'check_id' => $check['check_id'],
                'severity' => $check['severity'],
                'action' => $action,
            ];
        }

        return $remediation;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<string>
     */
    private function topLevelEvidenceRefs(array $checks): array
    {
        $refs = [];
        foreach ($checks as $check) {
            foreach ((array) ($check['evidence_refs'] ?? []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * Detecta uso REAL de uma classe num arquivo PHP. Uso real significa:
     *   - `use Some\Namespace\Class;` (import statement)
     *   - `new Class(` (instantiation)
     *   - `Class::` (static call)
     * Menção dentro de string literal, comentário ou docblock NÃO conta como
     * uso — caso contrário este próprio service (que lista os tokens
     * proibidos textualmente) seria flagado.
     */
    private function sourceUsesToken(string $src, string $token): bool
    {
        // Strip line comments + docblock-style content before scanning.
        $stripped = preg_replace('#//[^\n]*#', '', $src) ?? $src;
        $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped) ?? $stripped;
        // Strip single/double quoted string literals so token mentions in
        // canon arrays do not count as usage.
        $stripped = preg_replace("#'[^']*'#", "''", $stripped) ?? $stripped;
        $stripped = preg_replace('#"[^"]*"#', '""', $stripped) ?? $stripped;

        $useMatch = '/\\buse\\s+[A-Za-z0-9_\\\\]+\\\\'.preg_quote($token, '/').'\\b/';
        $newMatch = '/\\bnew\\s+'.preg_quote($token, '/').'\\b/';
        $staticMatch = '/\\b'.preg_quote($token, '/').'::/';
        $typeMatch = '/\\btrait\\s+'.preg_quote($token, '/').'\\b/'; // catches the test fixture `use AtlasForge...` after class header

        return preg_match($useMatch, $stripped) === 1
            || preg_match($newMatch, $stripped) === 1
            || preg_match($staticMatch, $stripped) === 1
            || preg_match($typeMatch, $stripped) === 1
            || preg_match('/\\bclass\\s+\\w+\\s+\\{\\s*use\\s+'.preg_quote($token, '/').'\\b/', $stripped) === 1;
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function pass(string $checkId, string $severity, string $reason, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_PASS,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => null,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function warn(string $checkId, string $severity, string $reason, string $remediation, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_WARN,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function fail(string $checkId, string $severity, string $reason, string $remediation, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_FAIL,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }
}
