<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use Throwable;

/**
 * Conservative wrapper around {@see AtlasOpenBrainService}.
 *
 * The current AtlasOpenBrainService surface (contextPack) does not accept a
 * granular char budget, so this adapter:
 *   1. asks the service for a provider-safe context_pack
 *   2. translates context_refs into the three programming buckets
 *   3. enforces the plan's budget post-hoc by truncating low-priority refs
 *   4. emits missing_sources for any required source not produced
 *   5. honours open_brain_mode (from the plan's truncation_policy)
 *
 * No provider call. Failures degrade to an empty projection — the engine
 * never blocks because Open Brain misbehaved.
 */
final class OpenBrainProjectionAdapter
{
    private const APPROX_CHARS_PER_REF = 220;

    public function __construct(
        private readonly AtlasOpenBrainService $openBrain,
    ) {}

    public function projectFor(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        ContextRetrievalPlan $plan,
    ): OpenBrainProgrammingProjection {
        $openBrainMode = $this->extractOpenBrainMode($plan);
        $budgetChars = $plan->budgetChars;
        $objectiveHash = hash('sha256', $envelope->normalizedIntent);

        $memoryRefs = [];
        $knowledgeRefs = [];
        $codeRefs = [];
        $missingSources = [];
        $truncationReasons = [];

        if ($openBrainMode === DocContextTierSelector::OPEN_BRAIN_MODE_OFF) {
            $truncationReasons[] = 'open_brain_mode=off';
            foreach ($plan->requiredSources as $source) {
                $missingSources[] = $source;
            }

            return $this->buildProjection(
                envelope: $envelope,
                objectiveHash: $objectiveHash,
                memoryRefs: $memoryRefs,
                knowledgeRefs: $knowledgeRefs,
                codeRefs: $codeRefs,
                missingSources: $missingSources,
                truncated: true,
                truncationReasons: $truncationReasons,
            );
        }

        $pack = $this->callOpenBrain($envelope, $compactSdd);

        if ($pack === null) {
            $truncationReasons[] = 'open_brain_unavailable';
            foreach ($plan->requiredSources as $source) {
                $missingSources[] = $source;
            }

            return $this->buildProjection(
                envelope: $envelope,
                objectiveHash: $objectiveHash,
                memoryRefs: $memoryRefs,
                knowledgeRefs: $knowledgeRefs,
                codeRefs: $codeRefs,
                missingSources: $missingSources,
                truncated: true,
                truncationReasons: $truncationReasons,
            );
        }

        [$memoryRefs, $knowledgeRefs, $codeRefs] = $this->translateRefs((array) ($pack['context_refs'] ?? []));

        $missingSources = $this->resolveMissingSources($plan->requiredSources, $memoryRefs, $knowledgeRefs, $codeRefs);

        $estimatedChars = $this->estimateChars($memoryRefs, $knowledgeRefs, $codeRefs);
        $truncated = false;
        if ($estimatedChars > $budgetChars) {
            $truncated = true;
            $truncationReasons[] = 'estimated_chars_exceeds_budget';
            [$memoryRefs, $knowledgeRefs, $codeRefs] = $this->trimToBudget(
                $memoryRefs,
                $knowledgeRefs,
                $codeRefs,
                $budgetChars,
            );
        }

        return $this->buildProjection(
            envelope: $envelope,
            objectiveHash: $objectiveHash,
            memoryRefs: $memoryRefs,
            knowledgeRefs: $knowledgeRefs,
            codeRefs: $codeRefs,
            missingSources: $missingSources,
            truncated: $truncated,
            truncationReasons: $truncationReasons,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function callOpenBrain(OperationEnvelope $envelope, CompactSdd $compactSdd): ?array
    {
        try {
            $result = $this->openBrain->contextPack([
                'objective' => $envelope->normalizedIntent,
                'workspace' => $envelope->workspace,
                'task_type' => 'programming',
                'desired_mode' => $compactSdd->mode,
                'agent' => 'atlas_dev_discovery',
                'intent' => 'memory_context_export',
                'payload' => [
                    'surface_id' => $envelope->surfaceId,
                    'thread_id' => $envelope->surfaceContext->threadId,
                    'conversation_id' => $envelope->surfaceContext->conversationId,
                    'risk_level' => $compactSdd->riskLevel,
                    'task_kind' => $compactSdd->taskKind,
                ],
                'options' => [],
            ], 'atlas_dev');
        } catch (Throwable) {
            return null;
        }

        if (! is_array($result) || ($result['ok'] ?? false) !== true) {
            return null;
        }

        return $result;
    }

    /**
     * @param  list<array<string,mixed>>  $contextRefs
     * @return array{0:list<ContextRef>,1:list<ContextRef>,2:list<ContextRef>}
     */
    private function translateRefs(array $contextRefs): array
    {
        $memory = [];
        $knowledge = [];
        $code = [];

        foreach ($contextRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $type = (string) ($ref['type'] ?? '');
            $id = (string) ($ref['id'] ?? '');
            if ($type === '' || $id === '') {
                continue;
            }

            switch ($type) {
                case 'atlas_memory_entry':
                    $memoryType = (string) ($ref['memory_type'] ?? 'decision');
                    $kind = match ($memoryType) {
                        'learning' => ContextRef::KIND_LEARNING,
                        'technical_context' => ContextRef::KIND_TECHNICAL_CONTEXT,
                        'harness_learning' => ContextRef::KIND_HARNESS_LEARNING,
                        default => ContextRef::KIND_DECISION,
                    };
                    $memory[$id] = new ContextRef(
                        kind: $kind,
                        ref: 'atlas-memory://entry/'.$id,
                        reason: 'open_brain memory '.$memoryType,
                    );
                    break;
                case 'atlas_verbatim_memory':
                    $memory[$id] = new ContextRef(
                        kind: ContextRef::KIND_MEMORY,
                        ref: 'atlas-memory://verbatim/'.$id,
                        reason: 'open_brain verbatim',
                    );
                    break;
                case 'semantic_note':
                    $title = (string) ($ref['title'] ?? '');
                    $knowledge[$id] = new ContextRef(
                        kind: ContextRef::KIND_KNOWLEDGE,
                        ref: 'semantic-note://'.$id,
                        reason: $title !== '' ? 'note: '.$title : 'open_brain semantic note',
                    );
                    break;
                case 'atlas_engineering_code_module':
                    $slug = (string) ($ref['slug'] ?? $id);
                    $code[$slug] = new ContextRef(
                        kind: ContextRef::KIND_CODE,
                        ref: 'code_intelligence://module/'.$slug,
                        reason: 'open_brain code module reference',
                    );
                    break;
                default:
                    // Unknown ref types are ignored — never speculate kind.
            }
        }

        $sortByRef = static fn (ContextRef $a, ContextRef $b): int => strcmp($a->ref, $b->ref);

        $memory = array_values($memory);
        usort($memory, $sortByRef);

        $knowledge = array_values($knowledge);
        usort($knowledge, $sortByRef);

        $code = array_values($code);
        usort($code, $sortByRef);

        return [$memory, $knowledge, $code];
    }

    /**
     * @param  list<string>  $required
     * @param  list<ContextRef>  $memory
     * @param  list<ContextRef>  $knowledge
     * @param  list<ContextRef>  $code
     * @return list<string>
     */
    private function resolveMissingSources(array $required, array $memory, array $knowledge, array $code): array
    {
        $haystack = [];
        foreach ([$memory, $knowledge, $code] as $bucket) {
            foreach ($bucket as $ref) {
                $haystack[] = strtolower($ref->ref.' :: '.$ref->reason);
            }
        }

        $missing = [];
        foreach ($required as $source) {
            if (! is_string($source) || $source === '') {
                continue;
            }
            if ($this->coverageMatches($source, $haystack)) {
                continue;
            }
            $missing[] = $source;
        }

        sort($missing, SORT_STRING);

        return array_values(array_unique($missing));
    }

    /**
     * @param  list<string>  $haystack  lowercase ref :: reason strings
     */
    private function coverageMatches(string $source, array $haystack): bool
    {
        $candidates = $this->coverageCandidates($source);

        foreach ($haystack as $entry) {
            foreach ($candidates as $needle) {
                if ($needle !== '' && str_contains($entry, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function coverageCandidates(string $source): array
    {
        $base = $this->slugFromSource($source);
        $unversioned = (string) preg_replace('/-v\d+$/', '', $base);

        return array_values(array_unique(array_filter([
            strtolower($source),
            strtolower($base),
            strtolower($unversioned),
        ], static fn (string $s): bool => $s !== '')));
    }

    private function slugFromSource(string $source): string
    {
        $clean = (string) preg_replace('@^[a-z]+://@i', '', $source);
        $clean = (string) preg_replace('/#.*$/', '', $clean);
        $base = basename($clean);
        $stripped = preg_replace('/\.(md|mdx|txt|yml|yaml|json|php|ts|tsx|js|jsx)$/i', '', $base);

        return is_string($stripped) ? $stripped : $base;
    }

    /**
     * @param  list<ContextRef>  $memory
     * @param  list<ContextRef>  $knowledge
     * @param  list<ContextRef>  $code
     */
    private function estimateChars(array $memory, array $knowledge, array $code): int
    {
        return (count($memory) + count($knowledge) + count($code)) * self::APPROX_CHARS_PER_REF;
    }

    /**
     * @param  list<ContextRef>  $memory
     * @param  list<ContextRef>  $knowledge
     * @param  list<ContextRef>  $code
     * @return array{0:list<ContextRef>,1:list<ContextRef>,2:list<ContextRef>}
     */
    private function trimToBudget(array $memory, array $knowledge, array $code, int $budgetChars): array
    {
        $perItem = self::APPROX_CHARS_PER_REF;
        $maxItems = max(1, (int) floor($budgetChars / max(1, $perItem)));

        $all = [];
        foreach ($memory as $ref) {
            $all[] = ['bucket' => 'memory', 'ref' => $ref];
        }
        foreach ($knowledge as $ref) {
            $all[] = ['bucket' => 'knowledge', 'ref' => $ref];
        }
        foreach ($code as $ref) {
            $all[] = ['bucket' => 'code', 'ref' => $ref];
        }

        if (count($all) <= $maxItems) {
            return [$memory, $knowledge, $code];
        }

        $trimmed = array_slice($all, 0, $maxItems);
        $memoryOut = [];
        $knowledgeOut = [];
        $codeOut = [];
        foreach ($trimmed as $item) {
            match ($item['bucket']) {
                'memory' => $memoryOut[] = $item['ref'],
                'knowledge' => $knowledgeOut[] = $item['ref'],
                'code' => $codeOut[] = $item['ref'],
            };
        }

        return [$memoryOut, $knowledgeOut, $codeOut];
    }

    private function extractOpenBrainMode(ContextRetrievalPlan $plan): string
    {
        $mode = $plan->truncationPolicy['open_brain_mode'] ?? DocContextTierSelector::OPEN_BRAIN_MODE_AUTO;

        return is_string($mode) ? $mode : DocContextTierSelector::OPEN_BRAIN_MODE_AUTO;
    }

    /**
     * @param  list<ContextRef>  $memoryRefs
     * @param  list<ContextRef>  $knowledgeRefs
     * @param  list<ContextRef>  $codeRefs
     * @param  list<string>  $missingSources
     * @param  list<string>  $truncationReasons
     */
    private function buildProjection(
        OperationEnvelope $envelope,
        string $objectiveHash,
        array $memoryRefs,
        array $knowledgeRefs,
        array $codeRefs,
        array $missingSources,
        bool $truncated,
        array $truncationReasons,
    ): OpenBrainProgrammingProjection {
        $truncation = [
            'reasons' => array_values($truncationReasons),
            'truncated' => $truncated,
        ];

        $payload = [
            'code_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $codeRefs),
            'knowledge_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $knowledgeRefs),
            'memory_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $memoryRefs),
            'missing_sources' => array_values($missingSources),
            'mode' => OpenBrainProgrammingProjection::MODE,
            'objective_hash' => $objectiveHash,
            'projection_hash' => '',
            'provider_safe' => true,
            'run_id' => $envelope->runId,
            'schema_version' => OpenBrainProgrammingProjection::SCHEMA_VERSION,
            'truncation' => $truncation,
        ];

        $projectionHash = CanonicalHasher::hashWithout($payload, 'projection_hash');

        return new OpenBrainProgrammingProjection(
            runId: $envelope->runId,
            mode: OpenBrainProgrammingProjection::MODE,
            objectiveHash: $objectiveHash,
            memoryRefs: $memoryRefs,
            knowledgeRefs: $knowledgeRefs,
            codeRefs: $codeRefs,
            missingSources: $missingSources,
            truncation: $truncation,
            providerSafe: true,
            projectionHash: $projectionHash,
        );
    }
}
