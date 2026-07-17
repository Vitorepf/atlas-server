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
        $currentSchema = (AiValueNormalizer::trimmedStringOrNull($input['current_schema'] ?? null) ?? '');
        if ($currentSchema === '' || ! preg_match('/^atlas\.[a-z0-9_\.]+\.v\d+$/', $currentSchema)) {
            throw new InvalidArgumentException("current_schema must match 'atlas.*.v<n>' canon (got '{$currentSchema}').");
        }
        $trigger = (string) ($input['trigger'] ?? self::TRIGGER_OPERATOR);
        if (! in_array($trigger, self::VALID_TRIGGERS, true)) {
            throw new InvalidArgumentException("Unknown trigger '{$trigger}'.");
        }
        $rationale = (AiValueNormalizer::trimmedStringOrNull($input['rationale'] ?? null) ?? 'Operator-supplied schema evolution.');
        $addedFields = array_values(AiValueNormalizer::arrayOrEmpty($input['added_fields'] ?? null));
        $deprecatedFields = array_values(AiValueNormalizer::arrayOrEmpty($input['deprecated_fields'] ?? null));
        $actor = (AiValueNormalizer::trimmedStringOrNull($input['actor'] ?? null) ?? 'ACMF');

        $nextSchema = $this->bumpVersion($currentSchema);

        // Constitutional Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'schema_evolution',
            'proposed_effect' => "propose evolution {$currentSchema} -> {$nextSchema} (trigger={$trigger})",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
        ]);

        // Autonomy admission.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'schema_evolution',
            'proposed_effect' => "propose evolution {$currentSchema} -> {$nextSchema}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
            'requested_autonomy' => 'execute_with_approval',
        ]);

        $proposalId = 'acmf_'.substr(hash('sha256', $currentSchema.'|'.$nextSchema.'|'.$trigger), 0, 12);
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $proposal = [
            'schema_version' => self::PROPOSAL_SCHEMA,
            'proposal_id' => $proposalId,
            'generated_at' => $generatedAt,
            'current_schema' => $currentSchema,
            'proposed_next_schema' => $nextSchema,
            'trigger' => $trigger,
            'rationale' => $rationale,
            'added_fields' => $addedFields,
            'deprecated_fields' => $deprecatedFields,
            'doc_skeleton' => $this->docSkeleton($currentSchema, $nextSchema, $addedFields, $deprecatedFields),
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'requires_human_approval' => true,
            'is_proposal' => true,
        ];
        $proposal['proposal_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::PROPOSAL_SCHEMA,
            'current_schema' => $currentSchema,
            'proposed_next_schema' => $nextSchema,
            'added_fields' => $addedFields,
            'deprecated_fields' => $deprecatedFields,
            'kernel_decision' => $proposal['kernel_decision'],
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
            'schema' => $schema,
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
                $lines[] = '- '.(is_array($f) ? json_encode($f) : (string) $f);
            }
        }
        if ($deprecated !== []) {
            $lines[] = '### Deprecated fields';
            foreach ($deprecated as $f) {
                $lines[] = '- '.(is_array($f) ? json_encode($f) : (string) $f);
            }
        }
        $lines[] = '';
        $lines[] = '## Migration';
        $lines[] = 'Operator-driven. ACMF does not auto-apply schema migrations.';

        return implode("\n", $lines);
    }

}
