<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Cognitive\PersonalWorkedExample\Sources\FeynmanSessionSourceProvider;
use App\Services\Ai\Cognitive\PersonalWorkedExample\Sources\ProgrammingPRSourceProvider;
use App\Services\Ai\Cognitive\PersonalWorkedExample\Sources\StrategicDecisionSourceProvider;
use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Gates\PersonalWorkedExamplePrivacySafeGate;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PersonalWorkedExampleExtractor
{
    public const SCHEMA_VERSION = 'atlas.cognitive.personal_worked_example_extractor.v1';

    public function __construct(
        private readonly ProgrammingPRSourceProvider $programming,
        private readonly StrategicDecisionSourceProvider $strategic,
        private readonly FeynmanSessionSourceProvider $feynman,
        private readonly PersonalWorkedExampleExtractionRepository $extractions,
        private readonly PersonalWorkedExampleQualityFilter $quality,
        private readonly PersonalWorkedExamplePrivacyRedactor $redactor,
        private readonly PersonalWorkedExamplePrivacySafeGate $privacyGate,
        private readonly WorkedExampleSerializer $serializer,
        private readonly WorkedExampleRepository $workedExamples,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(string $source = 'all', string $domain = 'any', int $days = 90, int $limit = 50): array
    {
        return $this->slo->measure('cognitive.personal_worked_example.extract', function () use ($source, $domain, $days, $limit): array {
            $this->record(LedgerEventType::PersonalWorkedExampleExtractionStarted, [
                'source' => $source,
                'domain' => $domain,
                'window_days' => $days,
            ]);

            $candidates = $this->candidates($source, $days, $limit);
            if ($domain !== 'any') {
                $candidates = array_values(array_filter($candidates, fn (array $candidate): bool => ($candidate['domain'] ?? null) === $domain));
            }

            $summary = [
                'total_scanned' => count($candidates),
                'extracted' => 0,
                'discarded_quality' => 0,
                'discarded_privacy' => 0,
                'discarded_duplicate' => 0,
            ];
            $items = [];

            foreach ($candidates as $candidate) {
                $items[] = $this->processCandidate($candidate, $summary);
            }

            $this->record(LedgerEventType::PersonalExtractionBatchCompleted, [
                'summary' => $summary,
                'source' => $source,
                'domain' => $domain,
            ]);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'mode' => 'extract',
                'summary' => $summary,
                'items' => $items,
            ];
        }, [
            'domain' => $domain,
            'source' => $source,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $status = 'any', int $limit = 50): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'mode' => 'status',
            'extractions' => $this->extractions->list($status, $limit),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidates(string $source, int $days, int $limit): array
    {
        return match ($source) {
            'programming_pr' => $this->programming->candidates($days, $limit),
            'strategic_decision' => $this->strategic->candidates($days, $limit),
            'feynman_session' => $this->feynman->candidates($days, $limit),
            default => array_slice(array_merge(
                $this->programming->candidates($days, $limit),
                $this->strategic->candidates($days, $limit),
                $this->feynman->candidates($days, $limit),
            ), 0, max(1, $limit)),
        };
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,int>  $summary
     * @return array<string,mixed>
     */
    private function processCandidate(array $candidate, array &$summary): array
    {
        $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
        $sourceRef = (string) ($candidate['source_ref'] ?? 'unknown');

        if ($this->extractions->alreadyProcessed($sourceType, $sourceRef)) {
            $summary['discarded_duplicate']++;
            $record = $this->extractions->record($candidate, 'discarded_dup', null, 'duplicate_source_ref');
            $this->record(LedgerEventType::PersonalWorkedExampleDiscardedDuplicate, ['candidate' => $candidate]);

            return $record;
        }

        $quality = $this->quality->evaluate($candidate);
        if (($quality['status'] ?? null) !== 'passed') {
            $summary['discarded_quality']++;
            $record = $this->extractions->record($candidate, 'discarded_quality', null, (string) ($quality['reason'] ?? 'quality_gate_blocked'), (array) ($candidate['quality_signals'] ?? []));
            $this->record(LedgerEventType::PersonalWorkedExampleDiscardedQuality, ['candidate' => $candidate, 'gate' => $quality]);

            return $record;
        }

        $redacted = $this->redactor->redact($candidate);
        $candidate = array_merge($redacted['candidate'], ['redaction_applied' => $redacted['redaction']]);
        $serialized = $this->serializer->serialize($candidate);
        $privacy = $this->slo->measure('cognitive.personal_worked_example.privacy_gate', fn (): array => $this->privacyGate->evaluate($serialized), [
            'domain' => (string) ($serialized['domain'] ?? 'learning'),
            'source_type' => $sourceType,
        ]);
        if (($privacy['status'] ?? null) !== 'passed') {
            $summary['discarded_privacy']++;
            $record = $this->extractions->record($candidate, 'discarded_privacy', null, (string) ($privacy['reason'] ?? 'privacy_gate_blocked'), (array) ($candidate['quality_signals'] ?? []), $redacted['redaction']);
            $this->record(LedgerEventType::PersonalWorkedExampleDiscardedPrivacy, ['candidate' => $candidate, 'gate' => $privacy]);

            return $record;
        }

        $example = $this->workedExamples->create(
            topic: (string) $serialized['topic'],
            domain: (string) $serialized['domain'],
            title: (string) $serialized['title'],
            problemContext: (string) $serialized['problem_context'],
            solutionFull: (array) $serialized['solution_full'],
            fadingLevels: (array) $serialized['fading_levels'],
            source: 'personal_ledger',
            authorEvidenceRefs: [$sourceType.':'.$sourceRef],
        );

        $summary['extracted']++;
        $record = $this->extractions->record($candidate, 'extracted', (int) ($example['id'] ?? 0), null, (array) ($candidate['quality_signals'] ?? []), $redacted['redaction']);
        $this->record(LedgerEventType::PersonalWorkedExampleExtracted, [
            'worked_example_id' => $example['id'] ?? null,
            'knowledge_node_id' => $example['knowledge_node_id'] ?? null,
            'candidate' => $candidate,
        ]);

        return $record;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function record(LedgerEventType $type, array $payload): void
    {
        $this->ledger->record($type, array_merge([
            'schema_version' => self::SCHEMA_VERSION,
        ], $payload), [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_worked_example_cli',
            'envelope_id' => 'personal_worked_example:'.sha1(json_encode($payload, JSON_THROW_ON_ERROR)),
            'correlation_id' => 'personal_worked_example',
            'emitter_stage' => 'atlas.cognitive.personal_worked_example',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);
    }
}
