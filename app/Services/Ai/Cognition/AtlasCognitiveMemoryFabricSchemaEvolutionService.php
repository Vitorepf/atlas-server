<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Cognitive Memory Fabric — Schema Evolution Proposer (Patamar 3 closure).
 *
 * Detects schemas across the codebase / scorecard and proposes a v+1
 * revision when one of the canon evolution triggers fires. Proposals are
 * append-only and require_human_approval=true — never auto-applied.
 *
 * Triggers (any one is sufficient):
 *   - operator_request               : explicit operator-supplied trigger.
 *   - frontmatter_drift              : doc canon declares a different schema version than the service constant.
 *   - extension_pressure             : the schema is referenced more than EXTENSION_PRESSURE_THRESHOLD times across services.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-cognitive-memory-fabric-schema-evolution.md
 *
 * Schemas:
 *   - atlas.acmf.schema_proposal.v1
 *   - atlas.acmf.schema_evolution_ticket.v1
 *
 * Invariantes:
 *   - is_proposal=true sempre;
 *   - requires_human_approval=true sempre;
 *   - never mutates source files — emits markdown skeleton only;
 *   - Kernel + Admission gates obrigatórios.
 */
final class AtlasCognitiveMemoryFabricSchemaEvolutionService
{
    public const PROPOSAL_SCHEMA = 'atlas.acmf.schema_proposal.v1';

    public const TICKET_SCHEMA = 'atlas.acmf.schema_evolution_ticket.v1';

    public const TRIGGER_OPERATOR = 'operator_request';

    public const TRIGGER_FRONTMATTER_DRIFT = 'frontmatter_drift';

    public const TRIGGER_EXTENSION_PRESSURE = 'extension_pressure';

    public const VALID_TRIGGERS = [
        self::TRIGGER_OPERATOR,
        self::TRIGGER_FRONTMATTER_DRIFT,
        self::TRIGGER_EXTENSION_PRESSURE,
    ];

    public const EXTENSION_PRESSURE_THRESHOLD = 4;
    public const FIELD_CHANGE_KIND = 'change_kind';
    public const FIELD_PROPOSED_EFFECT = 'proposed_effect';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_CURRENT_SCHEMA = 'current_schema';
    public const FIELD_PROPOSED_NEXT_SCHEMA = 'proposed_next_schema';
    public const FIELD_KERNEL_DECISION = 'kernel_decision';
    public const FIELD_ADDED_FIELDS = 'added_fields';
    public const FIELD_DEPRECATED_FIELDS = 'deprecated_fields';
    public const FIELD_TRIGGER = 'trigger';
    public const FIELD_RATIONALE = 'rationale';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_DECISION = 'decision';
    public const FIELD_REQUESTED_AUTONOMY = 'requested_autonomy';
    public const FIELD_PROPOSAL_ID = 'proposal_id';

    private ?string $proposalsLogOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setProposalsLogPathForTesting(?string $path): void
    {
        $this->proposalsLogOverride = $path;
    }

    public function proposalsLogPath(): string
    {
        if ($this->proposalsLogOverride !== null) {
            return $this->proposalsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/acmf')
            : sys_get_temp_dir().'/atlas/acmf';

        return $base.DIRECTORY_SEPARATOR.'schema_proposals.jsonl';
    }

    /**
     * Propose v+1 evolution for an existing schema.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function propose(array $input): array
    {
        $currentSchema = (AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_CURRENT_SCHEMA] ?? null) ?? '');
        if ($currentSchema === '' || ! preg_match('/^atlas\.[a-z0-9_\.]+\.v\d+$/', $currentSchema)) {
            throw new InvalidArgumentException("current_schema must match 'atlas.*.v<n>' canon (got '{$currentSchema}').");
        }
        $trigger = AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_TRIGGER] ?? null) ?? self::TRIGGER_OPERATOR;
        if (! in_array($trigger, self::VALID_TRIGGERS, true)) {
            throw new InvalidArgumentException("Unknown trigger '{$trigger}'.");
        }
        $rationale = (AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_RATIONALE] ?? null) ?? 'Operator-supplied schema evolution.');
        $addedFields = array_values(AiValueNormalizer::arrayOrEmpty($input[self::FIELD_ADDED_FIELDS] ?? null));
        $deprecatedFields = array_values(AiValueNormalizer::arrayOrEmpty($input[self::FIELD_DEPRECATED_FIELDS] ?? null));
        $actor = (AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_ACTOR] ?? null) ?? 'ACMF');

        $nextSchema = $this->bumpVersion($currentSchema);

        // Constitutional Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            self::FIELD_CHANGE_KIND => 'schema_evolution',
            self::FIELD_PROPOSED_EFFECT => "propose evolution {$currentSchema} -> {$nextSchema} (trigger={$trigger})",
            self::FIELD_SCOPE => [self::FIELD_PRIVACY_CLASS => 'normal'],
            self::FIELD_ACTOR => $actor,
        ]);

        // Autonomy admission.
        $admissionEnv = $this->admission->admit([
            self::FIELD_CHANGE_KIND => 'schema_evolution',
            self::FIELD_PROPOSED_EFFECT => "propose evolution {$currentSchema} -> {$nextSchema}",
            self::FIELD_SCOPE => [self::FIELD_PRIVACY_CLASS => 'normal'],
            self::FIELD_ACTOR => $actor,
            self::FIELD_REQUESTED_AUTONOMY => 'execute_with_approval',
        ]);

        $proposalId = 'acmf_'.substr(hash('sha256', $currentSchema.'|'.$nextSchema.'|'.$trigger), 0, 12);
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $proposal = [
            'schema_version' => self::PROPOSAL_SCHEMA,
            self::FIELD_PROPOSAL_ID => $proposalId,
            'generated_at' => $generatedAt,
            self::FIELD_CURRENT_SCHEMA => $currentSchema,
            self::FIELD_PROPOSED_NEXT_SCHEMA => $nextSchema,
            self::FIELD_TRIGGER => $trigger,
            self::FIELD_RATIONALE => $rationale,
            self::FIELD_ADDED_FIELDS => $addedFields,
            self::FIELD_DEPRECATED_FIELDS => $deprecatedFields,
            'doc_skeleton' => $this->docSkeleton($currentSchema, $nextSchema, $addedFields, $deprecatedFields),
            self::FIELD_KERNEL_DECISION => $kernelEnv[self::FIELD_DECISION],
            'admission_decision' => $admissionEnv[self::FIELD_DECISION],
            'requires_human_approval' => true,
            'is_proposal' => true,
        ];
        $proposal['proposal_hash'] = 'sha256:'.hash('sha256', json_encode([
            self::FIELD_SCHEMA => self::PROPOSAL_SCHEMA,
            self::FIELD_CURRENT_SCHEMA => $currentSchema,
            self::FIELD_PROPOSED_NEXT_SCHEMA => $nextSchema,
            self::FIELD_ADDED_FIELDS => $addedFields,
            self::FIELD_DEPRECATED_FIELDS => $deprecatedFields,
            self::FIELD_KERNEL_DECISION => $proposal[self::FIELD_KERNEL_DECISION],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->proposalsLogPath(), $proposal);

        return $proposal;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProposals(): array
    {
        return AppendOnlyJsonlStore::read($this->proposalsLogPath());
    }

    /**
     * Scan PHP files under rootDir counting references to a schema string.
     *
     * @param  string  $schema   the schema to search for (e.g. 'atlas.acmf.schema_proposal.v1')
     * @param  string|null  $rootDir  default app/; injectable for tests
     * @return array{schema:string,reference_count:int,files_matching:list<string>,files_scanned:int,threshold:int,pressure_detected:bool,scan_hash:string}
     */
    public function detectExtensionPressure(string $schema, ?string $rootDir = null): array
    {
        $rootDir ??= defined('base_path') && function_exists('base_path') ? base_path('app') : __DIR__.'/../../../..';
        $rootDir = rtrim((string) realpath($rootDir), '/\\').DIRECTORY_SEPARATOR;

        $threshold = self::EXTENSION_PRESSURE_THRESHOLD;

        $matched = [];
        $scanned = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($it as $splFileInfo) {
            /** @var \SplFileInfo $splFileInfo */
            if ($splFileInfo->getExtension() !== 'php') {
                continue;
            }
            $realPath = $splFileInfo->getRealPath();
            if ($realPath === false) {
                continue;
            }
            $scanned++;
            $contents = @file_get_contents($realPath);
            if ($contents !== false && str_contains($contents, $schema)) {
                $relative = str_replace($rootDir, '', $realPath);
                $matched[] = $relative;
            }
        }

        sort($matched, SORT_STRING);
        $referenceCount = count($matched);
        $scanHash = hash('sha256', implode("\n", $matched));

        return [
            self::FIELD_SCHEMA => $schema,
            'reference_count' => $referenceCount,
            'files_matching' => $matched,
            'files_scanned' => $scanned,
            'threshold' => $threshold,
            'pressure_detected' => $referenceCount > $threshold,
            'scan_hash' => $scanHash,
        ];
    }

    // ---------- internals ----------

    private function bumpVersion(string $schema): string
    {
        if (! preg_match('/^(atlas\.[a-z0-9_\.]+\.v)(\d+)$/', $schema, $m)) {
            throw new InvalidArgumentException("Unparseable schema '{$schema}'.");
        }
        $next = ((int) $m[2]) + 1;

        return $m[1].$next;
    }

    private function docSkeleton(string $current, string $next, array $added, array $deprecated): string
    {
        $lines = [];
        $lines[] = "# Schema Evolution Proposal: {$current} → {$next}";
        $lines[] = '';
        $lines[] = '> **Status:** proposed · `requires_human_approval=true`';
        $lines[] = '';
        $lines[] = '## Changes';
        if ($added !== []) {
            $lines[] = '### Added fields';
            foreach ($added as $f) {
                $lines[] = '- '.(is_array($f) ? json_encode($f) : (AiValueNormalizer::trimmedScalarStringOrNull($f) ?? ''));
            }
        }
        if ($deprecated !== []) {
            $lines[] = '### Deprecated fields';
            foreach ($deprecated as $f) {
                $lines[] = '- '.(is_array($f) ? json_encode($f) : (AiValueNormalizer::trimmedScalarStringOrNull($f) ?? ''));
            }
        }
        $lines[] = '';
        $lines[] = '## Migration';
        $lines[] = 'Operator-driven. ACMF does not auto-apply schema migrations.';

        return implode("\n", $lines);
    }

}
