<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Http\Resources\SemanticCurationProposalResource;
use App\Http\Resources\SemanticNoteResource;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticCurationProposal;
use App\Services\Semantic\CurationProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SemanticCurationReviewCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:semantic:curation-review
        {proposal : Semantic curation proposal id}
        {--decision=accept : accept, dismiss or postpone}
        {--path= : Override accepted semantic note path}
        {--body= : Override accepted semantic note body}
        {--promote-to-memory : Promote accepted proposal into Atlas Memory}
        {--memory-type= : Override promoted memory type}
        {--scope-type= : Override promoted memory scope type}
        {--scope-id= : Override promoted memory scope id}
        {--promoted-by=operator : Operator/reviewer id for receipts}
        {--promote-to-verbatim : Promote accepted proposal into Verbatim Memory}
        {--verbatim-type=evidence : Verbatim memory type}
        {--verbatim-privacy-class=normal : Verbatim privacy class}
        {--verbatim-external-ai-allowed : Allow external AI use of verbatim memory}
        {--json : Print machine-readable JSON}';

    protected $description = 'Review a semantic curation proposal and optionally promote it into Memory/Open Brain with receipts.';

    public function handle(CurationProposalService $service): int
    {
        $proposal = SemanticCurationProposal::query()->find((string) $this->argument('proposal'));
        if (! $proposal) {
            $this->error('Semantic curation proposal nao encontrada.');

            return self::FAILURE;
        }

        $decision = strtolower(trim((string) $this->option('decision')));

        return match ($decision) {
            'accept' => $this->accept($proposal, $service),
            'dismiss' => $this->dismiss($proposal, $service),
            'postpone' => $this->postpone($proposal, $service),
            default => $this->invalidDecision($decision),
        };
    }

    private function accept(SemanticCurationProposal $proposal, CurationProposalService $service): int
    {
        $edits = $this->acceptOptions();
        $validator = Validator::make($edits, [
            'path' => ['nullable', 'string', 'max:500'],
            'body_edits' => ['nullable', 'string'],
            'promote_to_memory' => ['boolean'],
            'memory_type' => ['nullable', 'string', 'max:40'],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'promoted_by' => ['nullable', 'string', 'max:120'],
            'promote_to_verbatim' => ['boolean'],
            'verbatim_type' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::TYPES)],
            'verbatim_privacy_class' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::PRIVACY_CLASSES)],
            'verbatim_external_ai_allowed' => ['boolean'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first() ?? 'Invalid curation review options.');

            return self::FAILURE;
        }

        $note = $service->accept($proposal, $validator->validated());
        $proposal = $proposal->refresh();
        $payload = $this->payload('accept', $proposal, $note);

        return $this->printPayload($payload);
    }

    private function dismiss(SemanticCurationProposal $proposal, CurationProposalService $service): int
    {
        $service->dismiss($proposal);

        return $this->printPayload($this->payload('dismiss', $proposal->refresh()));
    }

    private function postpone(SemanticCurationProposal $proposal, CurationProposalService $service): int
    {
        $service->postpone($proposal);

        return $this->printPayload($this->payload('postpone', $proposal->refresh()));
    }

    private function invalidDecision(string $decision): int
    {
        $this->error("Decision invalida: {$decision}. Use accept, dismiss ou postpone.");

        return self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptOptions(): array
    {
        return array_filter([
            'path' => $this->stringOption('path'),
            'body_edits' => $this->stringOption('body'),
            'promote_to_memory' => (bool) $this->option('promote-to-memory'),
            'memory_type' => $this->stringOption('memory-type'),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'promoted_by' => $this->stringOption('promoted-by') ?? 'operator',
            'promote_to_verbatim' => (bool) $this->option('promote-to-verbatim'),
            'verbatim_type' => $this->stringOption('verbatim-type') ?? 'evidence',
            'verbatim_privacy_class' => $this->stringOption('verbatim-privacy-class') ?? 'normal',
            'verbatim_external_ai_allowed' => (bool) $this->option('verbatim-external-ai-allowed'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $decision, SemanticCurationProposal $proposal, mixed $note = null): array
    {
        $memoryPromotion = data_get($proposal->metadata ?? [], 'memory_promotion');
        $verbatimPromotion = data_get($proposal->metadata ?? [], 'verbatim_promotion');

        return [
            'ok' => true,
            'decision' => $decision,
            'proposal' => (new SemanticCurationProposalResource($proposal))->resolve(),
            'note' => $note ? (new SemanticNoteResource($note))->resolve() : null,
            'memory_promotion' => $memoryPromotion,
            'verbatim_promotion' => $verbatimPromotion,
            'receipt_hashes' => [
                'memory' => is_array($memoryPromotion) ? ($memoryPromotion['receipt_hash'] ?? null) : null,
                'verbatim' => is_array($verbatimPromotion) ? ($verbatimPromotion['receipt_hash'] ?? null) : null,
            ],
            'safety' => [
                'schema_version' => 'atlas.semantic_curation_review.safety.v1',
                'operator_decision_recorded' => true,
                'memory_write_requested' => (bool) is_array($memoryPromotion),
                'verbatim_write_requested' => (bool) is_array($verbatimPromotion),
                'raw_capture_text_exposed' => false,
                'provider_export_allowed_by_command' => false,
                'context_injection_allowed_by_command' => false,
            ],
        ];
    }

    private function printPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Semantic curation review</>', (string) ($payload['decision'] ?? 'unknown'));
        $this->components->twoColumnDetail('Proposal', (string) data_get($payload, 'proposal.id'));
        $this->components->twoColumnDetail('Status', (string) data_get($payload, 'proposal.status'));
        $this->components->twoColumnDetail('Memory receipt', (string) (data_get($payload, 'receipt_hashes.memory') ?: '-'));
        $this->components->twoColumnDetail('Verbatim receipt', (string) (data_get($payload, 'receipt_hashes.verbatim') ?: '-'));

        return self::SUCCESS;
    }

}
