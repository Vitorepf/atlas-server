<?php

namespace App\Services\Ai\Memory;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasProject;
use App\Models\AtlasTask;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\FactPairPolarityContradictionDetector;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector;
use App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentSecretScanner;
use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\MemoryGovernance\AtlasMemorySourcePrivacyPolicy;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\MemoryScopeHelpers;
use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class AtlasMemoryRegistryService
{
    use MemoryScopeHelpers;

    private const CALLER_PROTECTED_SIGNAL_KEYS = [
        'contains_secret',
        'contains_sensitive_unnecessary',
        'contradicts_newer',
        'provider_safe',
        'signal_sources',
        'derived_signals',
    ];

    private AtlasMemoryPrivacyService $privacy;

    private MemoryQueryInput $input;

    private AtlasMemorySemanticIndexer $semanticIndexer;

    private ?AtlasRealityGraphIngestionService $realityGraphIngestion;

    private ?AtlasMemoryJournal $journal;

    private CognitiveImmunePromotionGateEvaluator $admissionEvaluator;

    private CodeGraphSecretScanner $codeGraphSecretScanner;

    private LocalAgentSecretScanner $localAgentSecretScanner;

    private NumericRangeOverlapContradictionDetector $numericRangeDetector;

    private FactPairPolarityContradictionDetector $factPolarityDetector;

    private AtlasMemoryTemporalDefaultDeriver $temporalDefaultDeriver;

    public function __construct(
        ?AtlasMemoryPrivacyService $privacy = null,
        ?MemoryQueryInput $input = null,
        ?AtlasMemorySemanticIndexer $semanticIndexer = null,
        ?AtlasRealityGraphIngestionService $realityGraphIngestion = null,
        ?AtlasMemoryJournal $journal = null,
        ?CognitiveImmunePromotionGateEvaluator $admissionEvaluator = null,
        ?CodeGraphSecretScanner $codeGraphSecretScanner = null,
        ?LocalAgentSecretScanner $localAgentSecretScanner = null,
        ?NumericRangeOverlapContradictionDetector $numericRangeDetector = null,
        ?FactPairPolarityContradictionDetector $factPolarityDetector = null,
        ?AtlasMemoryTemporalDefaultDeriver $temporalDefaultDeriver = null,
    ) {
        $this->privacy = $privacy ?? app(AtlasMemoryPrivacyService::class);
        $this->input = $input ?? app(MemoryQueryInput::class);
        $this->semanticIndexer = $semanticIndexer ?? app(AtlasMemorySemanticIndexer::class);
        // Lazy (built on first accrual, not here): the memory write path must never
        // pay for — or fail on — brain wiring it might not even use.
        $this->realityGraphIngestion = $realityGraphIngestion;
        // SIS8 — journal-first reversibility; lazy + fail-open like the accrual above.
        $this->journal = $journal;
        $this->admissionEvaluator = $admissionEvaluator ?? app(CognitiveImmunePromotionGateEvaluator::class);
        $this->codeGraphSecretScanner = $codeGraphSecretScanner ?? app(CodeGraphSecretScanner::class);
        $this->localAgentSecretScanner = $localAgentSecretScanner ?? app(LocalAgentSecretScanner::class);
        $this->numericRangeDetector = $numericRangeDetector ?? app(NumericRangeOverlapContradictionDetector::class);
        $this->factPolarityDetector = $factPolarityDetector ?? app(FactPairPolarityContradictionDetector::class);
        $this->temporalDefaultDeriver = $temporalDefaultDeriver ?? app(AtlasMemoryTemporalDefaultDeriver::class);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): AtlasMemoryEntry
    {
        $payload = $this->normalize($attributes);
        $payload = $this->applyAdmission($payload, 'record', $attributes);

        $entry = AtlasMemoryEntry::query()->create($payload);
        // SIS8 — journal-first: record the authoritative mutation before any
        // derived state (vectors/graph) so the brain is reconstructable from
        // the journal alone.
        $this->journal($entry, 'record');
        // R1: embed-on-write with the REAL embedding engine so recall can rank by
        // vector similarity. Best-effort + pgsql-only; on sqlite/no-venv it skips
        // honestly and recall falls back to lexical (never a fake vector).
        $this->semanticIndexer->indexEntry($entry);
        $this->accrueRealityGraph($entry);
        $this->accrueRelations($entry);

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $identity
     * @param  array<string,mixed>  $attributes
     */
    public function upsert(array $identity, array $attributes): AtlasMemoryEntry
    {
        $payload = $this->normalize($attributes);
        $payload = $this->applyAdmission($payload, 'upsert', $attributes);

        $entry = AtlasMemoryEntry::query()->updateOrCreate($identity, $payload);
        $this->journal($entry, 'upsert');
        $this->semanticIndexer->indexEntry($entry);
        $this->accrueRealityGraph($entry);
        $this->accrueRelations($entry);

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function curate(AtlasMemoryEntry|string $entry, array $attributes): AtlasMemoryEntry
    {
        $entry = $entry instanceof AtlasMemoryEntry
            ? $entry
            : AtlasMemoryEntry::query()->findOrFail($entry);

        $metadata = $entry->metadata ?? [];
        $history = array_values((array) data_get($metadata, 'curation_history', []));
        $history[] = array_filter([
            'action' => ($attributes['status'] ?? null) === 'archived' ? 'archive' : 'summary_update',
            'note' => isset($attributes['curation_note']) ? trim((string) $attributes['curation_note']) : null,
            'at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        data_set($metadata, 'curation_history', $history);
        if (is_array($attributes['metadata'] ?? null)) {
            data_set($attributes, 'metadata.curation_history', $history);
        }

        $current = array_intersect_key($entry->getAttributes(), array_flip($entry->getFillable()));
        $merged = array_merge($current, [
            'tags' => $entry->tags ?? [],
            'metadata' => $metadata,
        ], $attributes);
        foreach ([
            'title' => 'redacted_title',
            'body' => 'redacted_body',
            'summary' => 'redacted_summary',
        ] as $contentField => $projectionField) {
            if (array_key_exists($contentField, $attributes)
                && ! array_key_exists($projectionField, $attributes)) {
                unset($merged[$projectionField]);
            }
        }
        if (array_intersect_key($attributes, array_flip(['title', 'body', 'summary'])) !== []
            && ! array_key_exists('content_hash', $attributes)) {
            unset($merged['content_hash']);
        }
        $payload = $this->normalize($merged);
        $payload = $this->applyAdmission($payload, 'curate', $attributes);

        $entry->fill($payload)->save();
        $this->journal($entry, 'curate');
        $this->semanticIndexer->indexEntry($entry);
        $this->accrueRealityGraph($entry);
        $this->accrueRelations($entry);

        return $entry->refresh();
    }

    /**
     * F4 (Salto 1 — "AURG vivo") COMPOUNDING: every memory write best-effort upserts
     * its fused-store brain node and re-runs the deterministic memory→code /
     * memory→domain linkers FOR THIS ROW ONLY — never a full sync inline. Mirrors
     * the AtlasMemorySemanticIndexer wiring on this same write path: optional dep,
     * fail-open, NEVER throws into the memory write (a missing brain table, a
     * disabled flag or any brain fault leaves the memory write untouched).
     */
    private function accrueRealityGraph(AtlasMemoryEntry $entry): void
    {
        try {
            if (! (bool) config('atlas.aurg.enabled', true) || ! (bool) config('atlas.aurg.ingest_on_write', true)) {
                return;
            }

            // Built without the cross-domain mesh: mesh edges are a domains-sync
            // concern; the per-row accrual path never assembles domain topology.
            $service = $this->realityGraphIngestion ??= new AtlasRealityGraphIngestionService(
                app(CrossDomainTaxonomyMap::class),
                $this->privacy,
                null,
            );
            $service->ingestMemoryEntry($entry);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * D3 (Obra #18) — auto-relation accrual: relate a just-written entry against its
     * (type,scope) bucket via the governance hook, so duplicate/conflict edges accrue
     * continuously on write instead of only on a manual `atlas:memory:govern scan`.
     * Same fail-open contract as accrueRealityGraph — a relation fault never breaks the
     * memory write (the governance hook is itself fail-open; this is belt-and-suspenders).
     */
    private function accrueRelations(AtlasMemoryEntry $entry): void
    {
        try {
            app(AtlasMemoryGovernanceService::class)->relateNewEntry($entry);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * ASI-02 (+ELEV-08) — the single memory-admission chokepoint. Every Registry
     * mutation receives the same G0-G8 cognitive-immune receipt. Lote 1 stays in
     * observe by default: forbid gates are recorded per writer but do not block
     * until the operator flips the mode to enforce.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $rawAttributes
     * @return array<string,mixed>
     */
    private function applyAdmission(array $payload, string $operation, array $rawAttributes = []): array
    {
        $writer = $this->admissionWriter($payload, $operation);
        $receipt = $this->evaluateAdmission(array_merge($payload, [
            'immune_signals' => $rawAttributes['immune_signals'] ?? data_get($payload, 'metadata.immune_signals', []),
        ]), $writer);

        if (($receipt['mode'] ?? 'observe') === 'enforce' && ($receipt['blocks_write'] ?? false) === true) {
            throw new InvalidArgumentException('memory_admission_blocked:'.implode(',', (array) data_get($receipt, 'verdict.blocking_gate_ids', [])));
        }

        $this->appendAdmissionVerdictToImmuneLedger($payload, $operation, $writer, $receipt);

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        data_set($metadata, 'acos_max.asi_02.admission', $receipt);
        $metadata = $this->stampCaptureHmacLineageOnAdmission($metadata, $payload);
        $payload['metadata'] = $metadata;

        return $payload;
    }

    /**
     * Append o veredito imune ao ImmuneVerdictLedger.
     *
     * O gate que roda de verdade era este: 31 entradas de memória carregam o receipt
     * ASI-02 no metadata. Mas o ledger tinha 0 linhas, porque o único produtor era o
     * CaptureService — que roda sobre `captures`, uma tabela com 0 linhas. Ou seja: o
     * gate que decide não registrava, e o que registrava nunca decidia. Sem esta linha
     * não há série para calibrar (true_block / false_block / missed_poison).
     *
     * Fail-open: um ledger indisponível nunca pode derrubar uma escrita de memória —
     * recordVerdict() já devolve null em vez de lançar quando a tabela não existe.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $receipt
     */
    private function appendAdmissionVerdictToImmuneLedger(array $payload, string $operation, string $writer, array $receipt): void
    {
        try {
            $candidateHash = hash('sha256', (string) ($payload['title'] ?? '').'|'.(string) ($payload['body'] ?? ''));

            app(ImmuneVerdictLedger::class)->recordVerdict(
                $candidateHash,
                'memory_registry:'.$writer,
                (array) ($receipt['verdict'] ?? []),
                [
                    'decided_at' => now()->toIso8601String(),
                    'metadata' => [
                        'operation' => $operation,
                        'mode' => (string) ($receipt['mode'] ?? 'observe'),
                        'blocks_write' => (bool) ($receipt['blocks_write'] ?? false),
                        'memory_type' => (string) ($payload['memory_type'] ?? $payload['type'] ?? 'unknown'),
                        'scope' => (string) ($payload['scope'] ?? 'global'),
                    ],
                ],
            );
        } catch (Throwable) {
            // fail-open: registrar a decisão nunca pode custar a escrita da memória.
        }
    }

    /**
     * MAXI-07 — ASI-02 promotion stamps the memory-stage HMAC lineage link.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stampCaptureHmacLineageOnAdmission(array $metadata, array $payload): array
    {
        $lineage = app(CaptureHmacLineageService::class);
        $existing = is_array(data_get($metadata, 'acos_max.maxi_07.capture_hmac_lineage'))
            ? data_get($metadata, 'acos_max.maxi_07.capture_hmac_lineage')
            : (is_array(data_get($metadata, 'capture_hmac_lineage_prior'))
                ? data_get($metadata, 'capture_hmac_lineage_prior')
                : []);

        $title = (string) ($payload['title'] ?? '');
        $body = (string) ($payload['body'] ?? '');

        $stamped = $lineage->stampStage(
            is_array($existing) ? $existing : [],
            CaptureHmacLineageService::STAGE_MEMORY,
            [
                'memory_type' => (string) ($payload['memory_type'] ?? $payload['type'] ?? 'unknown'),
                'scope' => (string) ($payload['scope'] ?? 'global'),
                'source_type' => (string) ($payload['source_type'] ?? 'registry'),
                'title_hash' => hash('sha256', $title),
                'body_hash' => hash('sha256', $body),
            ],
        );

        data_set($metadata, 'acos_max.maxi_07.capture_hmac_lineage', $lineage->providerSafeChain($stamped));

        return $metadata;
    }

    /**
     * Exposed for existing candidate/write-back doors so they can delegate to the
     * same G0-G8 evaluator without creating a second admission policy.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function evaluateAdmission(array $attributes, string $writer = 'registry'): array
    {
        $mode = strtolower((string) config('atlas.memory_admission.mode', 'observe'));
        if (! in_array($mode, ['observe', 'enforce'], true)) {
            $mode = 'observe';
        }

        $signals = $this->admissionSignals($attributes);
        $verdict = $this->admissionEvaluator->evaluate($signals);
        $blockingGateIds = array_values((array) ($verdict['blocking_gate_ids'] ?? []));

        return [
            'schema_version' => 'atlas.acos_max.memory_admission.v1',
            'slice' => 'ASI-02',
            'elevation' => 'ELEV-08',
            'mode' => $mode,
            'writer' => $writer,
            'blocks_write' => $mode === 'enforce' && $blockingGateIds !== [],
            'verdict' => $verdict,
            'derived_signals' => $this->admissionDerivedSignalSummary($signals),
            'signal_sources' => is_array($signals['_signal_sources'] ?? null) ? $signals['_signal_sources'] : [],
            'evaluated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function admissionWindowReport(int $minWrites = 50): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [
                'status' => 'pending_window',
                'writes' => 0,
                'gate_evaluations' => 0,
                'min_writes' => $minWrites,
                'note' => 'ELEV-14: observe window awaits real memory writes; do not fake green.',
            ];
        }

        $entries = AtlasMemoryEntry::query()->get(['id', 'metadata']);
        $writes = $entries->count();
        $evaluations = $entries
            ->filter(fn (AtlasMemoryEntry $entry): bool => is_array(data_get($entry->metadata, 'acos_max.asi_02.admission')))
            ->count();

        return [
            'status' => $writes >= $minWrites && $evaluations === $writes ? 'ready' : 'pending_window',
            'writes' => $writes,
            'gate_evaluations' => $evaluations,
            'min_writes' => $minWrites,
            'note' => $writes >= $minWrites
                ? 'ASI-02 observe window has enough real writes; require gate_evaluations == writes before enforce.'
                : 'ELEV-14: observe window may be filled by Autonomos at 1 worker; pending_window is honest until >=50 real writes.',
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function admissionSignals(array $attributes): array
    {
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $immuneSignals = is_array($attributes['immune_signals'] ?? null)
            ? $attributes['immune_signals']
            : (is_array(data_get($metadata, 'immune_signals')) ? data_get($metadata, 'immune_signals') : []);
        $callerSignals = $this->callerAdmissionSignals($immuneSignals);
        $privacyClass = strtolower($this->stringValue($attributes['privacy_class'] ?? data_get($metadata, 'privacy.class') ?? 'normal'));
        $externalAllowed = ! array_key_exists('external_ai_allowed', $attributes) || $attributes['external_ai_allowed'] !== false;
        $body = $this->stringValue($attributes['body'] ?? '');
        $summary = $this->stringValue($attributes['summary'] ?? '');
        $title = $this->stringValue($attributes['title'] ?? '');
        $sourcePresent = $this->stringValue($attributes['source_type'] ?? '') !== ''
            || $this->stringValue($attributes['source_id'] ?? '') !== ''
            || $this->stringValue($attributes['source_label'] ?? '') !== ''
            || (array) data_get($metadata, 'evidence_refs', []) !== []
            || (array) data_get($metadata, 'paths', []) !== [];
        $derivedSignals = $this->deriveAdmissionSignals($attributes, $privacyClass, $externalAllowed, $title, $summary, $body);

        return array_merge($callerSignals, [
            'atomic_claim_present' => trim($body.$summary.$title) !== '',
            'claim_type' => $this->stringValue($attributes['memory_type'] ?? ($attributes['kind'] ?? 'technical_context')),
            'claim_source_present' => $sourcePresent,
            'scope' => $this->stringValue($attributes['scope_type'] ?? ($attributes['scope'] ?? 'global')),
            'consent_granted' => (bool) data_get($metadata, 'consent_granted', true),
            'retention_ok' => (bool) data_get($metadata, 'retention_ok', true),
            'privacy_class' => $privacyClass !== '' ? $privacyClass : 'normal',
            'future_utility' => true,
            'novelty' => true,
            'outcome_validated' => (bool) data_get($metadata, 'outcome_validated', false),
            'promotion_mode_hint' => $this->stringValue(data_get($metadata, 'promotion_mode_hint', 'review')),
            'on_probation' => (bool) data_get($metadata, 'on_probation', false),
            'probation_watch_age_days' => (int) data_get($metadata, 'probation_watch_age_days', 0),
            'probation_recall_actor_counts' => (array) data_get($metadata, 'probation_recall_actor_counts', []),
            'probation_negative_feedback_count' => (int) data_get($metadata, 'probation_negative_feedback_count', 0),
            'probation_supervening_contradiction_count' => (int) data_get($metadata, 'probation_supervening_contradiction_count', 0),
        ], $derivedSignals);
    }

    /**
     * @param  array<string,mixed>  $immuneSignals
     * @return array<string,mixed>
     */
    private function callerAdmissionSignals(array $immuneSignals): array
    {
        $protected = array_flip(self::CALLER_PROTECTED_SIGNAL_KEYS);

        return array_filter(
            $immuneSignals,
            static fn (mixed $value, string|int $key): bool => is_string($key)
                && ! isset($protected[$key])
                && ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function deriveAdmissionSignals(array $attributes, string $privacyClass, bool $externalAllowed, string $title, string $summary, string $body): array
    {
        $text = trim($title."\n".$summary."\n".$body);
        $sources = [
            'G3' => [],
            'G4' => [],
        ];

        $containsSecret = in_array($privacyClass, ['secret'], true);
        $containsSensitiveUnnecessary = in_array($privacyClass, ['sensitive'], true) || ! $externalAllowed;

        $localScan = $this->localAgentSecretScanner->scanAndRedact($text);
        if ($localScan['findings'] !== []) {
            $containsSecret = true;
            $sources['G3'][] = 'LocalAgentSecretScanner';
        }

        $codeGraphScan = $this->codeGraphSecretScanner->scan($text);
        foreach ($codeGraphScan['findings'] as $finding) {
            $severity = (string) ($finding['severity'] ?? '');
            if ($severity === CodeGraphSecretScanner::SEVERITY_HIGH) {
                $containsSecret = true;
            } elseif ($severity !== '') {
                $containsSensitiveUnnecessary = true;
            }
        }
        if ($codeGraphScan['findings'] !== []) {
            $sources['G3'][] = 'CodeGraphSecretScanner';
        }

        [$contradictsNewer, $contradictionSources] = $this->detectNewerMemoryContradiction($attributes, $text);
        $sources['G4'] = $contradictionSources;

        $containsSensitiveUnnecessary = $containsSensitiveUnnecessary && ! $containsSecret;

        return [
            'provider_safe' => $externalAllowed
                && ! in_array($privacyClass, ['secret', 'sensitive'], true)
                && ! $containsSecret
                && ! $containsSensitiveUnnecessary,
            'contains_secret' => $containsSecret,
            'contains_sensitive_unnecessary' => $containsSensitiveUnnecessary,
            'contradicts_newer' => $contradictsNewer,
            '_signal_sources' => [
                'G3' => array_values(array_unique($sources['G3'])),
                'G4' => array_values(array_unique($sources['G4'])),
            ],
        ];
    }

    /**
     * @return array{0: bool, 1: list<string>}
     */
    private function detectNewerMemoryContradiction(array $attributes, string $candidateText): array
    {
        $candidateFacts = $this->extractNumericFacts($candidateText);
        $candidateRanges = $this->extractNumericRanges($candidateText);
        if ($candidateFacts === [] && $candidateRanges === []) {
            return [false, []];
        }

        $candidateRecordedAt = $this->recordedAt($attributes['recorded_at'] ?? null);
        if (! $candidateRecordedAt instanceof Carbon) {
            return [false, []];
        }

        $scopeType = $this->stringValue($attributes['scope_type'] ?? ($attributes['scope'] ?? 'global'));
        $scopeType = $scopeType !== '' ? $scopeType : 'global';
        $scopeId = $scopeType === 'global'
            ? null
            : $this->stringValue($attributes['scope_id'] ?? null);

        $query = AtlasMemoryEntry::query()
            ->where('scope_type', $scopeType)
            ->where('recorded_at', '>', $candidateRecordedAt);
        $scopeId === null
            ? $query->whereNull('scope_id')
            : $query->where('scope_id', $scopeId);

        $newerEntries = $query
            ->orderByDesc('recorded_at')
            ->limit(50)
            ->get(['title', 'summary', 'body']);

        $sources = [];
        foreach ($newerEntries as $entry) {
            $newerText = trim((string) $entry->title."\n".(string) $entry->summary."\n".(string) $entry->body);
            foreach ($candidateFacts as $candidateFact) {
                foreach ($this->extractNumericFacts($newerText) as $newerFact) {
                    $result = $this->factPolarityDetector->detect($candidateFact, $newerFact);
                    if ($result['contradicts']) {
                        $sources[] = 'FactPairPolarityContradictionDetector';

                        return [true, $sources];
                    }
                }
            }

            foreach ($candidateRanges as $candidateRange) {
                foreach ($this->extractNumericRanges($newerText) as $newerRange) {
                    if ($candidateRange['subject'] !== $newerRange['subject']) {
                        continue;
                    }

                    $relationship = $this->numericRangeDetector->detect(
                        $candidateRange['min'],
                        $candidateRange['max'],
                        $newerRange['min'],
                        $newerRange['max'],
                    );
                    if ($relationship === 'disjoint') {
                        $sources[] = 'NumericRangeOverlapContradictionDetector';

                        return [true, $sources];
                    }
                }
            }
        }

        return [false, []];
    }

    /**
     * @return list<array{subject:string,predicate:string,negated:bool,value:string}>
     */
    private function extractNumericFacts(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all(
            '/\b([A-Za-z][A-Za-z0-9 _\/-]{2,80}?)\s+(?:is|=|:)\s*(-?\d+(?:\.\d+)?)(?:\s*([A-Za-z%]+))?/i',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        $facts = [];
        foreach ($matches as $match) {
            $subject = $this->normalizeFactSubject((string) ($match[1] ?? ''));
            if ($subject === '') {
                continue;
            }
            $unit = strtolower((string) ($match[3] ?? ''));
            $facts[] = [
                'subject' => $subject,
                'predicate' => $unit !== '' ? 'value:'.$unit : 'value',
                'negated' => false,
                'value' => (string) ($match[2] ?? ''),
            ];
        }

        return $facts;
    }

    /**
     * @return list<array{subject:string,min:float,max:float}>
     */
    private function extractNumericRanges(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all(
            '/\b([A-Za-z][A-Za-z0-9 _\/-]{2,80}?)\s+(?:range|window)\s*(?:is|=|:)?\s*(-?\d+(?:\.\d+)?)\s*(?:\.\.|-|to)\s*(-?\d+(?:\.\d+)?)/i',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        $ranges = [];
        foreach ($matches as $match) {
            $subject = $this->normalizeFactSubject((string) ($match[1] ?? ''));
            if ($subject === '') {
                continue;
            }
            $min = (float) ($match[2] ?? 0);
            $max = (float) ($match[3] ?? 0);
            $ranges[] = [
                'subject' => $subject,
                'min' => min($min, $max),
                'max' => max($min, $max),
            ];
        }

        return $ranges;
    }

    private function normalizeFactSubject(string $subject): string
    {
        $subject = strtolower(trim(preg_replace('/\s+/', ' ', $subject) ?? ''));
        $lastSentence = preg_split('/[.!?]\s+/', $subject);
        $subject = is_array($lastSentence) ? (string) end($lastSentence) : $subject;

        return trim($subject, " \t\n\r\0\x0B:-");
    }

    private function recordedAt(mixed $value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            if ($value instanceof \DateTimeInterface || is_string($value)) {
                return Carbon::parse($value);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{contains_secret: bool, contains_sensitive_unnecessary: bool, contradicts_newer: bool}
     */
    private function admissionDerivedSignalSummary(array $signals): array
    {
        return [
            'contains_secret' => ($signals['contains_secret'] ?? false) === true,
            'contains_sensitive_unnecessary' => ($signals['contains_sensitive_unnecessary'] ?? false) === true,
            'contradicts_newer' => ($signals['contradicts_newer'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function admissionWriter(array $payload, string $operation): string
    {
        $source = trim((string) ($payload['source_type'] ?? 'registry'));

        return $source !== '' ? $operation.':'.$source : $operation.':registry';
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * SIS8 (Obra #20) — journal-first reversibility. Append the authoritative
     * post-write snapshot to the hash-chained disk journal so `atlas:brain:replay`
     * can rebuild the brain after a DROP DATABASE. Fail-open exactly like the
     * semantic indexer and
     * reality-graph accrual on this same path: a journal fault must never break
     * the memory write.
     *
     * ponytail: journals AFTER persist, so a process death in the µs between the
     * DB write and the append leaves that one mutation un-journaled. Close the
     * window with a two-phase intent/commit line only if durability demands it.
     */
    private function journal(AtlasMemoryEntry $entry, string $op): void
    {
        try {
            $journal = $this->journal ??= app(AtlasMemoryJournal::class);
            if (! $journal->enabled()) {
                return;
            }
            $journal->append($op, $entry->getTable(), (string) $entry->getKey(), $entry->attributesToArray());
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function search(array $filters = [], int $limit = 50): Collection
    {
        $query = AtlasMemoryEntry::query();

        if (is_string($filters['scope_type'] ?? null) && $filters['scope_type'] !== '') {
            $query->where('scope_type', $filters['scope_type']);
        }
        if (is_string($filters['scope_id'] ?? null) && $filters['scope_id'] !== '') {
            $query->where('scope_id', $filters['scope_id']);
        }
        foreach (['project_id', 'task_id', 'engineering_run_id', 'trace_id', 'session_id', 'user_id'] as $column) {
            if (is_string($filters[$column] ?? null) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function forScope(string $scopeType, ?string $scopeId = null, array $filters = [], int $limit = 50): Collection
    {
        return $this->applyFilters(AtlasMemoryEntry::query()->forScope($scopeType, $scopeId), $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForTask(AtlasTask $task, array $filters = [], int $limit = 25): Collection
    {
        $task->loadMissing('project');

        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($task): void {
            $query->where('scope_type', 'global')
                ->orWhere('task_id', $task->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'task')
                    ->where('scope_id', $task->id));

            if ($task->project_id) {
                $query->orWhere('project_id', $task->project_id)
                    ->orWhere(fn (Builder $nested) => $nested
                        ->where('scope_type', 'project')
                        ->where('scope_id', $task->project_id));
            }
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForProject(AtlasProject $project, array $filters = [], int $limit = 25): Collection
    {
        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($project): void {
            $query->where('scope_type', 'global')
                ->orWhere('project_id', $project->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'project')
                    ->where('scope_id', $project->id));
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForRun(AtlasEngineeringRun $run, array $filters = [], int $limit = 25): Collection
    {
        $query = AtlasMemoryEntry::query()->where(function (Builder $query) use ($run): void {
            $query->where('scope_type', 'global')
                ->orWhere('engineering_run_id', $run->id)
                ->orWhere(fn (Builder $nested) => $nested
                    ->where('scope_type', 'engineering_run')
                    ->where('scope_id', $run->id));

            if ($run->task_id) {
                $query->orWhere('task_id', $run->task_id);
            }
            if ($run->project_id) {
                $query->orWhere('project_id', $run->project_id);
            }
        });

        return $this->applyFilters($query, $filters)
            ->limit($this->limit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntry>
     */
    public function relevantForContext(array $context, array $filters = [], int $limit = 12): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return collect();
        }

        $projectId = $this->uuidOrNull($context['project_id'] ?? null);
        $taskId = $this->uuidOrNull($context['task_id'] ?? null);
        $runId = $this->uuidOrNull($context['engineering_run_id'] ?? ($context['run_id'] ?? null));
        $sessionId = $this->uuidOrNull($context['session_id'] ?? null);
        $userId = $this->stringOrNull($context['user_id'] ?? null);
        $workspaceId = $this->workspaceScopeId($context['workspace'] ?? ($context['workspace_path'] ?? null));

        if ($runId && (! $projectId || ! $taskId) && DatabaseTableAvailability::has('atlas_engineering_runs')) {
            $run = AtlasEngineeringRun::query()->find($runId);
            $projectId ??= $run?->project_id;
            $taskId ??= $run?->task_id;
        }

        if ($taskId && ! $projectId && DatabaseTableAvailability::has('atlas_tasks')) {
            $projectId = AtlasTask::query()->find($taskId)?->project_id;
        }

        $scopeWhere = function (Builder $query) use ($projectId, $taskId, $runId, $sessionId, $userId, $workspaceId): void {
            $query->where('scope_type', 'global');

            if ($projectId) {
                $query->orWhere('project_id', $projectId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'project')->where('scope_id', $projectId));
            }

            if ($taskId) {
                $query->orWhere('task_id', $taskId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'task')->where('scope_id', $taskId));
            }

            if ($runId) {
                $query->orWhere('engineering_run_id', $runId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'engineering_run')->where('scope_id', $runId));
            }

            if ($sessionId) {
                $query->orWhere('session_id', $sessionId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'session')->where('scope_id', $sessionId));
            }

            if ($userId) {
                $query->orWhere('user_id', $userId)
                    ->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'user')->where('scope_id', $userId));
            }

            if ($workspaceId) {
                $query->orWhere(fn (Builder $nested) => $nested->where('scope_type', 'workspace')->where('scope_id', $workspaceId));
            }
        };

        $limit = $this->limit($limit);
        $priorityQuery = fn (): Builder => $this->applyFilters(
            AtlasMemoryEntry::query()->where($scopeWhere),
            $filters,
        )->limit($limit);

        // WO-17-T0.1 — the QUESTION enters candidate selection. Absent or empty
        // query ⇒ byte-identical to the blind scope+priority+LIMIT path.
        $queryText = trim((string) ($context['query'] ?? ''));
        if ($queryText === '') {
            return $priorityQuery()->get();
        }

        // Present ⇒ union of (a) ids relevant to the question — which the priority
        // cut would otherwise make invisible — and (b) the priority ids, capped at
        // $limit, then one typed fetch that preserves the union order.
        $priorityIds = $priorityQuery()->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $relevantIds = $this->queryRelevantEntries($queryText, $scopeWhere, $filters, $limit);
        $orderedIds = $this->mergeIdsCapped($relevantIds, $priorityIds, $limit);

        $byId = AtlasMemoryEntry::query()->whereIn('id', $orderedIds)->get()->keyBy('id');

        return collect($orderedIds)
            ->map(fn (string $id): ?AtlasMemoryEntry => $byId->get($id))
            ->filter()
            ->values();
    }

    /**
     * WO-17-T0.1 — ids of entries whose CONTENT matches the question, so a
     * low-priority but on-topic memory is no longer invisible. pgsql ranks by
     * real vector similarity; any other driver (sqlite in the suite) — or a
     * vector failure — degrades silently to a case-insensitive lexical match,
     * never an exception to the caller.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,string>
     */
    private function queryRelevantEntries(string $queryText, Closure $scopeWhere, array $filters, int $limit): array
    {
        if (AtlasMemoryEntry::query()->getModel()->getConnection()->getDriverName() === 'pgsql') {
            try {
                $vector = app(AtlasMemoryVectorSearchService::class);
                if ($vector->available()) {
                    $poolIds = $this->applyFilters(AtlasMemoryEntry::query()->where($scopeWhere), $filters)
                        ->limit(max($limit * 10, 100))
                        ->pluck('id')
                        ->map(fn ($id): string => (string) $id)
                        ->all();

                    $scores = $vector->scoreEntries($queryText, $poolIds);
                    arsort($scores);
                    $topIds = array_slice(array_keys($scores), 0, $limit);

                    if ($topIds !== []) {
                        return array_map(fn ($id): string => (string) $id, $topIds);
                    }
                }
            } catch (Throwable $throwable) {
                report($throwable); // degrade silently to lexical
            }
        }

        return $this->lexicalMatch($queryText, $scopeWhere, $filters, $limit);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,string>
     */
    private function lexicalMatch(string $queryText, Closure $scopeWhere, array $filters, int $limit): array
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower($queryText)) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));
        if ($tokens === []) {
            return [];
        }

        $rows = $this->applyFilters(AtlasMemoryEntry::query()->where($scopeWhere), $filters)
            ->where(function (Builder $inner) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $inner->orWhereRaw('lower(title) like ?', [$like])
                        ->orWhereRaw('lower(summary) like ?', [$like])
                        ->orWhereRaw('lower(body) like ?', [$like]);
                }
            })
            ->limit(max($limit * 10, 100))
            ->get();

        return $rows
            ->sortByDesc(fn (AtlasMemoryEntry $entry): float => $this->lexicalRelevanceScore($tokens, $entry))
            ->take($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  array<int,string>  $tokens
     */
    private function lexicalRelevanceScore(array $tokens, AtlasMemoryEntry $entry): float
    {
        $title = mb_strtolower((string) $entry->title);
        $summary = mb_strtolower((string) $entry->summary);
        $body = mb_strtolower((string) $entry->body);
        $score = 0.0;

        foreach ($tokens as $token) {
            $score += substr_count($title, $token) * 4.0;
            $score += substr_count($summary, $token) * 2.0;
            $score += substr_count($body, $token);
        }

        return $score;
    }

    /**
     * @param  array<int,string>  $primary
     * @param  array<int,string>  $secondary
     * @return array<int,string>
     */
    private function mergeIdsCapped(array $primary, array $secondary, int $limit): array
    {
        $ordered = [];
        foreach ([...$primary, ...$secondary] as $id) {
            if (! in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
            if (count($ordered) >= $limit) {
                break;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordHarnessLearning(AtlasEngineeringRun $run, array $context = []): ?AtlasMemoryEntry
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return null;
        }

        $run->loadMissing('task');
        $decision = (string) ($run->decision ?: 'unknown');
        $score = $run->score === null ? 'n/a' : (string) $run->score;
        $blockingReasons = array_values((array) data_get($run->metadata, 'blocking_reasons', []));
        $body = 'Engineering Harness Runner finalizou com decision='.$decision.' score='.$score.'.';
        if ($blockingReasons !== []) {
            $body .= ' Bloqueios/observacoes: '.implode(' | ', array_map('strval', $blockingReasons));
            $body .= ' motivo: '.implode(' | ', array_map('strval', $blockingReasons));
        } else {
            $body .= ' motivo: resultado registrado para preservar aprendizado operacional do engineering_run.';
        }

        return $this->upsert([
            'memory_type' => 'harness_learning',
            'source_type' => 'engineering_run',
            'source_id' => $run->id,
        ], [
            'memory_type' => 'harness_learning',
            'scope_type' => 'engineering_run',
            'scope_id' => $run->id,
            'project_id' => $run->project_id,
            'task_id' => $run->task_id,
            'engineering_run_id' => $run->id,
            'trace_id' => $run->trace_id,
            'title' => 'Harness run '.$decision,
            'body' => $body,
            'summary' => Str::limit($body, 240),
            'importance' => in_array($decision, ['unsafe', 'unresolved'], true) ? 4 : 3,
            'priority' => match ($decision) {
                'unsafe', 'unresolved' => 90,
                'partial' => 75,
                default => 60,
            },
            'confidence' => $decision === 'resolved' ? 0.92 : 0.7,
            'source_type' => 'engineering_run',
            'source_id' => $run->id,
            'source_label' => 'Atlas Engineering Harness Runner',
            'metadata' => array_merge($context, [
                'decision' => $decision,
                'status' => $run->status,
                'score' => $run->score,
                'attempt_count' => $run->attempt_count,
                'context_pack_id' => $run->context_pack_id,
                'context_pack_hash' => $run->context_pack_hash,
                'blocking_reasons' => $blockingReasons,
            ]),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function sourceMap(): array
    {
        return [
            ['source' => 'tasks', 'tables' => ['atlas_tasks', 'atlas_task_events'], 'memory_role' => 'task intent, execution state, decisions and event history'],
            ['source' => 'projects', 'tables' => ['atlas_projects', 'atlas_project_events', 'atlas_project_steps', 'atlas_project_blockers'], 'memory_role' => 'project scope, outcomes, blockers and delivery flow'],
            ['source' => 'notes', 'tables' => ['semantic_notes', 'semantic_note_links', 'semantic_note_activations', 'semantic_curation_proposals'], 'memory_role' => 'human-readable semantic notes, activations and curation proposals', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'ai_interactions', 'tables' => ['ai_traces', 'ai_jobs', 'ai_messages', 'ai_threads', 'ai_sessions', 'ai_context_snapshots', 'ai_memory_deltas'], 'memory_role' => 'provider traces, feedback, session continuity and reviewed deltas', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'engineering_harness', 'tables' => ['atlas_engineering_runs', 'atlas_engineering_run_attempts', 'atlas_engineering_context_packs', 'atlas_engineering_evidence', 'atlas_engineering_review_findings', 'atlas_engineering_test_runs', 'atlas_engineering_benchmark_results', 'atlas_engineering_patch_artifacts'], 'memory_role' => 'engineering run outcomes, context packs, evidence, tests, findings and benchmark observations', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'engineering_knowledge', 'tables' => ['atlas_engineering_knowledge_items'], 'memory_role' => 'canonical architecture, maintenance playbooks, ADRs and capability registry for engineering work', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'memory_quality', 'tables' => ['atlas_memory_quality_snapshots'], 'memory_role' => 'operational scorecard history for memory readiness, provider-safety, governance, freshness, feedback and completeness', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'attachments', 'tables' => ['ai_attachment_index_entries'], 'memory_role' => 'uploaded images/documents, rendered pages, OCR excerpts and captions', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
            ['source' => 'context_bundles', 'tables' => ['ai_context_bundles'], 'memory_role' => 'mobile/app context bundles prepared for user-facing follow-up', 'privacy_policy' => AtlasMemorySourcePrivacyPolicy::POLICY_VERSION],
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function normalize(array $attributes): array
    {
        $scopeType = $this->scopeType($attributes);
        $scopeId = $this->scopeId($scopeType, $attributes);
        $projectId = $this->uuidOrNull($attributes['project_id'] ?? null);
        $taskId = $this->uuidOrNull($attributes['task_id'] ?? null);
        $runId = $this->uuidOrNull($attributes['engineering_run_id'] ?? ($attributes['run_id'] ?? null));

        if ($scopeType === 'project') {
            $projectId ??= $this->uuidOrNull($scopeId);
        }
        if ($scopeType === 'task') {
            $taskId ??= $this->uuidOrNull($scopeId);
        }
        if ($scopeType === 'engineering_run') {
            $runId ??= $this->uuidOrNull($scopeId);
        }

        if ($runId && (! $taskId || ! $projectId)) {
            $run = AtlasEngineeringRun::query()->find($runId);
            $taskId ??= $run?->task_id;
            $projectId ??= $run?->project_id;
        }

        if ($taskId && ! $projectId) {
            $projectId = AtlasTask::query()->find($taskId)?->project_id;
        }

        $status = in_array($attributes['status'] ?? 'active', AtlasMemoryEntry::STATUSES, true)
            ? (string) ($attributes['status'] ?? 'active')
            : 'active';
        $memoryType = $this->memoryType($attributes['memory_type'] ?? ($attributes['type'] ?? 'technical_context'));
        $body = trim((string) ($attributes['body'] ?? $attributes['content'] ?? ''));
        $summary = isset($attributes['summary']) ? trim((string) $attributes['summary']) : null;
        $recordedAt = $attributes['recorded_at'] ?? now();

        $payload = [
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'global' ? null : $scopeId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'engineering_run_id' => $runId,
            'trace_id' => $this->uuidOrNull($attributes['trace_id'] ?? null),
            'session_id' => $this->uuidOrNull($attributes['session_id'] ?? null),
            'user_id' => isset($attributes['user_id']) ? trim((string) $attributes['user_id']) : null,
            'title' => isset($attributes['title']) ? Str::limit(trim((string) $attributes['title']), 180, '') : null,
            'body' => $body,
            'summary' => $summary,
            'importance' => max(1, min(5, (int) ($attributes['importance'] ?? 3))),
            'priority' => max(0, min(100, (int) ($attributes['priority'] ?? 50))),
            'confidence' => $this->confidence($attributes['confidence'] ?? null),
            'source_type' => trim((string) ($attributes['source_type'] ?? 'manual')),
            'source_id' => isset($attributes['source_id']) ? trim((string) $attributes['source_id']) : null,
            'source_label' => isset($attributes['source_label']) ? Str::limit(trim((string) $attributes['source_label']), 180, '') : null,
            'status' => $status,
            'tags' => array_values(array_filter((array) ($attributes['tags'] ?? []), fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')),
            'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            'recorded_at' => $recordedAt,
            'last_used_at' => $attributes['last_used_at'] ?? null,
            'archived_at' => $status === 'archived' ? ($attributes['archived_at'] ?? now()) : ($attributes['archived_at'] ?? null),
        ];

        $payload['metadata'] = $this->applyRationaleGuard($body, (array) $payload['metadata']);

        if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'content_hash')) {
            $payload['content_hash'] = $this->contentHash($attributes['content_hash'] ?? null, $memoryType, $scopeType, $scopeId, $body, $summary);
        }
        if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'valid_from')) {
            $payload['valid_from'] = $attributes['valid_from'] ?? $recordedAt;
        }
        foreach (['valid_until', 'observed_at', 'verified_at', 'stale_after', 'source_hash', 'authority_level'] as $temporalField) {
            if (array_key_exists($temporalField, $attributes)
                && DatabaseTableAvailability::hasColumn('atlas_memory_entries', $temporalField)) {
                $payload[$temporalField] = $attributes[$temporalField];
            }
        }
        $payload = $this->applyTemporalDefaults($payload);

        return $this->privacy->normalizeForStorage($payload, $attributes);
    }

    /**
     * MAXH-02 — derive default temporal truth fields at the single Registry
     * funnel. These defaults are explicitly tagged as type-map defaults so
     * MAXH-01 does not count them as non-default temporal provenance.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function applyTemporalDefaults(array $payload): array
    {
        foreach (['observed_at', 'stale_after', 'authority_level'] as $field) {
            if (! DatabaseTableAvailability::hasColumn('atlas_memory_entries', $field)) {
                return $payload;
            }
        }

        return $this->temporalDefaultDeriver->applyMissing($payload);
    }

    /**
     * MEM-08 fail-open guard: never blocks a memory write, but marks thin bodies so
     * quality/digest can find and reverse them. Uses the same marker policy as the
     * quality score.
     *
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function applyRationaleGuard(string $body, array $metadata): array
    {
        $warnings = array_values((array) data_get($metadata, 'quality.warnings', []));
        $warnings = array_values(array_filter($warnings, fn (mixed $warning): bool => is_string($warning) && $warning !== 'missing_rationale_marker'));

        if (AtlasMemoryRationalePolicy::hasRationale($body)) {
            data_set($metadata, 'quality.needs_rationale', false);
            data_set($metadata, 'quality.warnings', $warnings);

            return $metadata;
        }

        $warnings[] = 'missing_rationale_marker';
        data_set($metadata, 'quality.needs_rationale', true);
        data_set($metadata, 'quality.warnings', array_values(array_unique($warnings)));

        return $metadata;
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);
        $requestedStatus = is_string($filters['status'] ?? null) && $filters['status'] !== '' ? $filters['status'] : null;
        if (! $includeInactive && $requestedStatus === null) {
            $query->active();
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? $filters['memory_type'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('memory_type', $types);
        }

        if (is_string($filters['source_type'] ?? null) && $filters['source_type'] !== '') {
            $query->where('source_type', $filters['source_type']);
        }

        if ((bool) ($filters['never_recalled'] ?? false) && DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            $query->whereDoesntHave('usages', fn (Builder $usage): Builder => $usage->where('source_type', 'memory_recall'));
        }

        if ((bool) ($filters['thin'] ?? false)) {
            $query
                ->whereNotNull('title')
                ->whereNotNull('summary')
                ->whereRaw("trim(title) <> ''")
                ->whereRaw("trim(summary) <> ''")
                ->whereRaw('lower(trim(summary)) = lower(trim(title))');
        }

        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '' && DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'privacy_class')) {
            $query->where('privacy_class', $filters['privacy_class']);
        }

        if ($requestedStatus !== null) {
            $query->where('status', $requestedStatus);
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('importance')
            ->latest('recorded_at')
            ->orderBy('id');
    }

    private function memoryType(mixed $type): string
    {
        $type = is_string($type) ? $type : 'technical_context';

        return in_array($type, AtlasMemoryEntry::TYPES, true) ? $type : 'technical_context';
    }

    private function confidence(mixed $confidence): ?float
    {
        if ($confidence === null || $confidence === '') {
            return 0.5;
        }

        return max(0, min(1, (float) $confidence));
    }

    private function contentHash(mixed $hash, string $memoryType, string $scopeType, ?string $scopeId, string $body, ?string $summary): string
    {
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) {
            return strtolower($hash);
        }

        return hash('sha256', json_encode([
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'body' => $body,
            'summary' => $summary,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function limit(int $limit): int
    {
        return $this->input->registryLimit($limit);
    }
}
