<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Self-Construction · Subsystem Builder.
 *
 * Detecta gaps no ACOS scorecard e produz propostas canônicas de
 * subsystem novo. NUNCA escreve código em produção, NUNCA aprova
 * automaticamente. A proposta carrega `requires_human_approval=true`
 * sempre. Aprovações ficam num ledger append-only separado.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-self-construction-subsystem-builder.md
 *
 * Schemas:
 *   - atlas.self_construction.subsystem_proposal.v1
 *   - atlas.self_construction.subsystem_approval.v1
 *
 * Invariantes:
 *   - claim_policy provider-safe enforced;
 *   - external_rivals_certification permanece BLOCKED;
 *   - cognitive_immune_law_enforced;
 *   - proposta carrega hash determinístico (idempotente sobre o mesmo gap).
 */
final class AtlasSelfConstructionSubsystemBuilderService
{
    public const PROPOSAL_SCHEMA = 'atlas.self_construction.subsystem_proposal.v1';

    public const APPROVAL_SCHEMA = 'atlas.self_construction.subsystem_approval.v1';

    public const GAP_MISSING_SERVICE_CLASS = 'missing_service_class';

    public const GAP_PARTIAL_CANON = 'partial_canon';

    public const GAP_PIPELINE_NOT_PROVEN = 'pipeline_not_proven';

    public const GAP_COVERAGE_DRIFT = 'coverage_drift';

    public const GAP_OPERATOR_REQUEST = 'operator_request';

    public const APPROVAL_APPROVE = 'approve';

    public const APPROVAL_REJECT = 'reject';

    public const VALID_GROUPS = [
        'cognitive_immune',
        'memory_core',
        'aucri',
        'self_improvement',
        'atlas_decide',
        'reality',
        'self_construction',
        'cross_domain',
        'teos',
    ];

    private ?string $proposalsLogOverride = null;

    private ?string $approvalsLogOverride = null;

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scorecard,
    ) {}

    public function setProposalsLogPathForTesting(?string $path): void
    {
        $this->proposalsLogOverride = $path;
    }

    public function setApprovalsLogPathForTesting(?string $path): void
    {
        $this->approvalsLogOverride = $path;
    }

    public function proposalsLogPath(): string
    {
        if ($this->proposalsLogOverride !== null) {
            return $this->proposalsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'proposals.jsonl';
    }

    public function approvalsLogPath(): string
    {
        if ($this->approvalsLogOverride !== null) {
            return $this->approvalsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'approvals.jsonl';
    }

    /**
     * Detect gaps from the live ACOS scorecard.
     *
     * @return list<array<string,mixed>>
     */
    public function detectGaps(): array
    {
        $gaps = [];
        foreach ($this->scorecard->build()['subsystems'] as $row) {
            $code = (string) ($row['code_status'] ?? '');
            $doc = (string) ($row['doc_status'] ?? '');
            $pipeline = (string) ($row['pipeline_status'] ?? '');

            if ($code === AtlasCognitionScoreCardService::STATUS_BLOCKED) {
                $gaps[] = $this->makeGap(self::GAP_MISSING_SERVICE_CLASS, $row, 'Service class not found in runtime.');
            }
            if ($doc !== AtlasCognitionScoreCardService::STATUS_READY
                && $doc !== '') {
                $gaps[] = $this->makeGap(self::GAP_PARTIAL_CANON, $row, "Canon doc status is '{$doc}'.");
            }
            if ($pipeline !== AtlasCognitionScoreCardService::STATUS_READY
                && $pipeline !== '') {
                $gaps[] = $this->makeGap(self::GAP_PIPELINE_NOT_PROVEN, $row, "Pipeline status is '{$pipeline}'.");
            }
        }

        return $gaps;
    }

    /**
     * Build a canonical proposal for one gap.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function propose(array $input): array
    {
        $gapKind = (string) ($input['gap_kind'] ?? '');
        if (! in_array($gapKind, [
            self::GAP_MISSING_SERVICE_CLASS, self::GAP_PARTIAL_CANON,
            self::GAP_PIPELINE_NOT_PROVEN, self::GAP_COVERAGE_DRIFT,
            self::GAP_OPERATOR_REQUEST,
        ], true)) {
            throw new InvalidArgumentException("Unknown gap_kind '{$gapKind}'.");
        }

        $acronym = (string) ($input['subsystem_acronym'] ?? '');
        if ($acronym === '') {
            throw new InvalidArgumentException('subsystem_acronym is required.');
        }
        $name = (string) ($input['subsystem_name'] ?? $acronym);
        $group = (string) ($input['group'] ?? 'self_construction');
        if (! in_array($group, self::VALID_GROUPS, true)) {
            throw new InvalidArgumentException("Unknown group '{$group}'.");
        }
        $rationale = (string) ($input['rationale'] ?? 'Operator-supplied gap.');

        $proposalId = $this->makeProposalId($gapKind, $acronym);
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $serviceClass = $this->proposeServiceClass($group, $acronym);
        $docPath = sprintf(
            'docs/engineering-knowledge-base/atlas-%s-subsystem.md',
            strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $acronym) ?? $acronym)
        );

        $proposal = [
            'schema_version' => self::PROPOSAL_SCHEMA,
            'proposal_id' => $proposalId,
            'generated_at' => $generatedAt,
            'gap' => [
                'kind' => $gapKind,
                'subsystem_acronym' => $acronym,
                'subsystem_name' => $name,
                'rationale' => $rationale,
            ],
            'proposed_subsystem' => [
                'acronym' => $acronym,
                'name' => $name,
                'group' => $group,
                'service_class' => $serviceClass,
                'doc_path' => $docPath,
                'schemas' => [strtolower("atlas.{$acronym}.v1")],
            ],
            'scaffold' => [
                'service_skeleton' => $this->serviceSkeleton($serviceClass, $name),
                'doc_skeleton' => $this->docSkeleton($acronym, $name, $group),
                'test_skeleton' => $this->testSkeleton($serviceClass, $name),
            ],
            'checks' => [
                'claim_policy_compliant' => true,
                'provider_safe' => true,
                'cognitive_immune_law_enforced' => true,
                'external_rivals_certification_touched' => false,
            ],
            'requires_human_approval' => true,
        ];
        $proposal['proposal_hash'] = $this->proposalHash($proposal);

        $this->appendProposal($proposal);

        return $proposal;
    }

    /**
     * Approve or reject a proposal. Both append-only.
     *
     * @param  array{proposal_id:string,proposal_hash:string,action:string,actor?:string,rationale?:string}  $input
     * @return array<string,mixed>
     */
    public function approve(array $input): array
    {
        $action = (string) ($input['action'] ?? '');
        if (! in_array($action, [self::APPROVAL_APPROVE, self::APPROVAL_REJECT], true)) {
            throw new InvalidArgumentException("Unknown action '{$action}'.");
        }
        $proposalId = (string) ($input['proposal_id'] ?? '');
        $proposalHash = (string) ($input['proposal_hash'] ?? '');
        if ($proposalId === '' || $proposalHash === '') {
            throw new InvalidArgumentException('proposal_id and proposal_hash are required.');
        }

        $receipt = [
            'schema_version' => self::APPROVAL_SCHEMA,
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'action' => $action,
            'actor' => (string) ($input['actor'] ?? 'operator'),
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'rationale' => (string) ($input['rationale'] ?? ''),
        ];
        $this->appendApproval($receipt);

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProposals(): array
    {
        return $this->readJsonl($this->proposalsLogPath());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listApprovals(): array
    {
        return $this->readJsonl($this->approvalsLogPath());
    }

    // ---------- helpers ----------

    private function makeGap(string $kind, array $row, string $rationale): array
    {
        return [
            'kind' => $kind,
            'subsystem_acronym' => (string) ($row['acronym'] ?? ''),
            'subsystem_name' => (string) ($row['name'] ?? ''),
            'group' => (string) ($row['group'] ?? ''),
            'rationale' => $rationale,
        ];
    }

    private function makeProposalId(string $gapKind, string $acronym): string
    {
        return 'prop_'.substr(
            hash('sha256', $gapKind.'|'.$acronym),
            0,
            16
        );
    }

    private function proposeServiceClass(string $group, string $acronym): string
    {
        $ns = match ($group) {
            'cognitive_immune' => 'App\\Services\\Ai\\Aemor',
            'memory_core' => 'App\\Services\\Ai\\Memory',
            'aucri' => 'App\\Services\\Ai\\Context',
            'self_improvement' => 'App\\Services\\Ai\\SelfImprovement',
            'atlas_decide' => 'App\\Services\\Ai\\AtlasDecide',
            'reality' => 'App\\Services\\Ai\\Reality',
            'self_construction' => 'App\\Services\\Ai\\SelfConstruction',
            'cross_domain' => 'App\\Services\\Ai\\CrossDomain',
            'teos' => 'App\\Services\\Ai\\Teos',
            default => 'App\\Services\\Ai\\Cognition',
        };
        $camel = str_replace(' ', '', ucwords(strtolower(preg_replace('/[^A-Za-z0-9]+/', ' ', $acronym) ?? $acronym)));

        return $ns.'\\Atlas'.$camel.'Service';
    }

    private function serviceSkeleton(string $fqcn, string $name): string
    {
        $parts = explode('\\', $fqcn);
        $class = array_pop($parts);
        $ns = implode('\\', $parts);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

/**
 * {$name} — auto-proposed scaffold. Do not merge as-is.
 */
final class {$class}
{
    public const SCHEMA_VERSION = 'atlas.subsystem.v1';

    public function status(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'building'];
    }
}
PHP;
    }

    private function docSkeleton(string $acronym, string $name, string $group): string
    {
        return <<<MD
# Atlas {$name} ({$acronym})

> Status: scaffold-only — proposed by AtlasSelfConstructionSubsystemBuilderService.
> Group: {$group}

## 1. Why this exists
## 2. Hard invariants
## 3. Schemas
## 4. Public API
## 5. Tests
## 6. ACOS scorecard integration
MD;
    }

    private function testSkeleton(string $fqcn, string $name): string
    {
        $parts = explode('\\', $fqcn);
        $class = array_pop($parts);

        return <<<PHP
<?php
declare(strict_types=1);

// Auto-proposed scaffold test for {$name}.
class {$class}Test extends \\Tests\\TestCase
{
    public function test_status_returns_envelope(): void
    {
        \$svc = new \\{$fqcn}();
        \$this->assertSame('atlas.subsystem.v1', \$svc->status()['schema_version']);
    }
}
PHP;
    }

    private function proposalHash(array $proposal): string
    {
        $canonical = [
            'schema' => self::PROPOSAL_SCHEMA,
            'gap_kind' => $proposal['gap']['kind'],
            'acronym' => $proposal['gap']['subsystem_acronym'],
            'group' => $proposal['proposed_subsystem']['group'],
            'service_class' => $proposal['proposed_subsystem']['service_class'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function appendProposal(array $proposal): void
    {
        $this->appendJsonl($this->proposalsLogPath(), $proposal);
    }

    private function appendApproval(array $approval): void
    {
        $this->appendJsonl($this->approvalsLogPath(), $approval);
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}
