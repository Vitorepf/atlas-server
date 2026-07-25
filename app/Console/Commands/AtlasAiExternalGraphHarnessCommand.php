<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasExternalGraphHarnessService;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiExternalGraphHarnessCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:external-graph-harness
        {--candidate-file= : Optional external_graph_candidate.v1 JSON file to validate read-only}
        {--scan-root= : Optional allowed repo root to build a sandbox external_graph_candidate.v1 without runtime or writes}
        {--max-files=80 : Maximum files for sandbox candidate generation}
        {--emit-review-inbox : Emit a proposal Inbox item for human review of an accepted candidate}
        {--json : Print machine-readable JSON}';

    protected $description = 'Publish the AP-684 External Graph Harness contract and validate graph candidates without writes.';

    public function handle(AtlasExternalGraphHarnessService $harness, ProposalInboxEmitter $inbox): int
    {
        $candidate = $this->candidateFromOption();

        if ($candidate === false) {
            return self::FAILURE;
        }

        $payload = [
            'status' => 'ok',
            'external_graph_harness' => $harness->report($candidate),
        ];

        $scanRoot = trim((string) $this->option('scan-root'));
        if ($scanRoot !== '') {
            $payload['sandbox_candidate'] = $harness->sandboxCandidate($scanRoot, (int) $this->option('max-files'));
            $payload['external_graph_harness'] = $harness->report(data_get($payload, 'sandbox_candidate.candidate'));
            $payload['status'] = data_get($payload, 'sandbox_candidate.status') === 'candidate_built_read_only'
                ? 'ok'
                : 'blocked';
        }

        $payload['emitted_inbox_item'] = $this->emitReviewInbox($payload['external_graph_harness'], $inbox);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $report = $payload['external_graph_harness'];
        $validation = $report['candidate_validation'];

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas External Graph Harness</>', $report['status']);
        $this->components->twoColumnDetail('Mode', $report['mode']);
        $this->components->twoColumnDetail('Authority', data_get($report, 'contract.authority'));
        $this->components->twoColumnDetail('Writes memory', YesNo::format(data_get($report, 'contract.guardrails.writes_memory_registry')));
        $this->components->twoColumnDetail('Provider calls', YesNo::format(data_get($report, 'contract.guardrails.provider_calls_enabled')));

        if (is_array($validation)) {
            $this->components->twoColumnDetail('Candidate', $validation['status']);
            $this->components->twoColumnDetail('Nodes', (string) $validation['node_count']);
            $this->components->twoColumnDetail('Edges', (string) $validation['edge_count']);
            $this->components->twoColumnDetail('Errors', (string) $validation['error_count']);
            $this->components->twoColumnDetail('Warnings', (string) $validation['warning_count']);
            $this->components->twoColumnDetail('Review packet', (string) data_get($validation, 'review_packet.status'));
            $this->components->twoColumnDetail('Auto promotion', data_get($validation, 'review_packet.auto_promotion_allowed') ? 'allowed' : 'blocked');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>|null
     */
    private function emitReviewInbox(array $report, ProposalInboxEmitter $inbox): ?array
    {
        if (! (bool) $this->option('emit-review-inbox')) {
            return null;
        }

        if (data_get($report, 'candidate_validation.status') !== 'accepted_read_only_candidate') {
            return [
                'status' => 'skipped',
                'reason' => 'external_graph_candidate_not_accepted',
            ];
        }

        $candidateHash = (string) data_get($report, 'candidate_validation.candidate_hash', 'unknown');
        $reviewPacket = data_get($report, 'candidate_validation.review_packet', []);

        $item = $inbox->emit([
            'title' => 'Revisar candidato de grafo externo do Atlas',
            'category' => 'code_intelligence',
            'source_type' => 'external_graph_harness',
            'source_id' => $candidateHash,
            'dedupe_key' => 'external-graph-review:'.$candidateHash,
            'problem' => 'Um candidato de grafo externo foi aceito em modo read-only e precisa de revisao humana antes de influenciar Code Intelligence, Memory, Context Builder, Constelacao ou runtime.',
            'solution' => 'Comparar o candidato contra o Code Intelligence nativo, registrar lacunas reais e decidir se vale abrir AP futuro para extractor nativo ou runtime Python governado.',
            'worth_it' => 'Esse caminho pode acelerar relacoes de codigo/docs sem criar cerebro paralelo, desde que permaneca proposal-only e fail-closed.',
            'metadata' => [
                'schema_version' => 'atlas.external_graph_review_inbox.v1',
                'review_signal' => [
                    'status' => 'review_required',
                    'severity' => 'medium',
                    'recommended_action' => 'review_external_graph_candidate',
                    'reasons' => [
                        'external_graph_candidate_accepted_read_only',
                        'curator_proposal_required',
                        'graph_rag_runtime_blocked_until_future_ap',
                    ],
                ],
            ],
            'payload' => [
                'external_graph_review' => [
                    'schema_version' => 'atlas.external_graph_review_inbox.v1',
                    'candidate_hash' => $candidateHash,
                    'node_count' => data_get($report, 'candidate_validation.node_count'),
                    'edge_count' => data_get($report, 'candidate_validation.edge_count'),
                    'review_packet' => $reviewPacket,
                    'review_only_constraints' => data_get($report, 'candidate_validation.review_only_constraints', []),
                    'comparison_plan' => $report['comparison_plan'] ?? [],
                    'promotion_allowed' => false,
                    'raw_graph_payload_persisted' => false,
                    'provider_call_allowed' => false,
                    'runtime_execution_allowed' => false,
                    'memory_write_allowed' => false,
                    'context_injection_allowed' => false,
                    'constelacao_promotion_allowed' => false,
                ],
            ],
            'source_refs' => [
                [
                    'type' => 'engineering_knowledge',
                    'id' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md',
                ],
                [
                    'type' => 'ap',
                    'id' => 'docs/ap/AP-684-graphify-external-graph-harness.md',
                ],
            ],
            'available_actions' => [
                ['id' => 'review_external_graph_candidate', 'label' => 'Revisar candidato', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ],
            'confidence' => 0.84,
        ]);

        if ($item === null) {
            return [
                'status' => 'not_emitted',
                'reason' => 'inbox_tables_unavailable',
            ];
        }

        return [
            'status' => 'emitted',
            'id' => $item->id,
            'title' => $item->title,
            'review_signal' => data_get($item->payload ?? [], 'proposal_contract.review_signal'),
        ];
    }

    /**
     * @return array<string,mixed>|false|null
     */
    private function candidateFromOption(): array|false|null
    {
        $path = trim((string) $this->option('candidate-file'));
        if ($path === '') {
            return null;
        }

        if (! File::isFile($path)) {
            $this->components->error("Candidate file not found: {$path}");

            return false;
        }

        try {
            $candidate = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->components->error('Candidate file is not valid JSON: '.$exception->getMessage());

            return false;
        }

        if (! is_array($candidate) || array_is_list($candidate)) {
            $this->components->error('Candidate file must contain a JSON object.');

            return false;
        }

        return $candidate;
    }
}
