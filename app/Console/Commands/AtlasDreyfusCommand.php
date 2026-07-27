<?php

namespace App\Console\Commands;

use App\Services\Ai\Learning\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

class AtlasDreyfusCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:dreyfus
        {node? : Knowledge node UUID or topic string}
        {--domain=learning : Domain for the overlay}
        {--all : List all overlays}
        {--level= : Upsert explicit Dreyfus level 1..5 for the node}
        {--confidence=0.70 : Confidence for explicit upsert}
        {--specialist= : Optional specialist profile}
        {--dispute= : Open an operator dispute reason for this node}
        {--resolve-dispute= : Resolve a prior dispute with a curator/operator note}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect or update Atlas Cognitive Dreyfus overlays.';

    public function handle(DreyfusOverlayRepository $overlays, AtlasEvidenceLedger $ledger): int
    {
        $domain = trim((string) $this->option('domain')) ?: 'learning';
        $node = trim((string) ($this->argument('node') ?? ''));

        if ((bool) $this->option('all') || $node === 'all') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
                'status' => 'ok',
                'mode' => 'all',
                'domain' => $domain,
                'overlays' => $overlays->all($domain),
            ]);
        }

        if ($node === '') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
                'status' => 'invalid_input',
                'reason' => 'node_required_unless_all',
            ], self::FAILURE);
        }

        $nodeId = $this->nodeId($node, $overlays);

        if (is_numeric($this->option('level'))) {
            $overlay = $overlays->upsert(
                knowledgeNodeId: $nodeId,
                domain: $domain,
                level: (int) $this->option('level'),
                confidence: (float) $this->option('confidence'),
                evidenceRefs: ['operator_cli:atlas:dreyfus'],
                lastUpdatedVia: 'operator_dispute',
                specialistProfile: $this->stringOption('specialist'),
            );

            $ledger->record(LedgerEventType::DreyfusLevelDeltaRecorded, [
                'schema_version' => 'atlas.cognitive.dreyfus_level_delta.v1',
                'knowledge_node_id' => $nodeId,
                'domain' => $domain,
                'current_level' => $overlay['current_level'] ?? null,
                'confidence' => $overlay['confidence'] ?? null,
                'last_updated_via' => 'operator_dispute',
            ], $this->ledgerContext($nodeId));

            return $this->render([
                'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
                'status' => 'updated',
                'mode' => 'upsert',
                'overlay' => $overlay,
            ]);
        }

        $dispute = $this->stringOption('dispute');
        if ($dispute !== null) {
            $ledger->record(LedgerEventType::DreyfusDisputeOpened, [
                'schema_version' => 'atlas.cognitive.dreyfus_dispute.v1',
                'knowledge_node_id' => $nodeId,
                'domain' => $domain,
                'reason_hash' => hash('sha256', $dispute),
                'reason_length' => strlen($dispute),
                'status' => 'opened',
            ], $this->ledgerContext($nodeId));

            return $this->render([
                'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
                'status' => 'opened',
                'mode' => 'dispute',
                'knowledge_node_id' => $nodeId,
                'domain' => $domain,
                'reason_hash' => hash('sha256', $dispute),
            ]);
        }

        $resolution = $this->stringOption('resolve-dispute');
        if ($resolution !== null) {
            $ledger->record(LedgerEventType::DreyfusDisputeResolved, [
                'schema_version' => 'atlas.cognitive.dreyfus_dispute_resolution.v1',
                'knowledge_node_id' => $nodeId,
                'domain' => $domain,
                'resolution_hash' => hash('sha256', $resolution),
                'resolution_length' => strlen($resolution),
                'status' => 'resolved',
            ], $this->ledgerContext($nodeId));

            return $this->render([
                'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
                'status' => 'resolved',
                'mode' => 'resolve_dispute',
                'knowledge_node_id' => $nodeId,
                'domain' => $domain,
                'resolution_hash' => hash('sha256', $resolution),
            ]);
        }

        return $this->render([
            'schema_version' => 'atlas.cognitive.dreyfus_cli.v1',
            'status' => 'ok',
            'mode' => 'show',
            'knowledge_node_id' => $nodeId,
            'domain' => $domain,
            'overlay' => $overlays->find($nodeId, $domain),
        ]);
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Dreyfus', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? 'n/a'));
        $this->components->twoColumnDetail('Domain', (string) ($payload['domain'] ?? 'n/a'));
        $this->components->twoColumnDetail('Node', (string) ($payload['knowledge_node_id'] ?? data_get($payload, 'overlay.knowledge_node_id', 'n/a')));
        $this->components->twoColumnDetail('Level', (string) data_get($payload, 'overlay.current_level', 'n/a'));
        $this->components->twoColumnDetail('Confidence', (string) data_get($payload, 'overlay.confidence', 'n/a'));

        return $exit;
    }

    private function nodeId(string $node, DreyfusOverlayRepository $overlays): string
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $node) === 1
            ? strtolower($node)
            : $overlays->nodeIdForTopic($node);
    }


    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(string $nodeId): array
    {
        return [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_dreyfus_cli',
            'envelope_id' => 'dreyfus_overlay:'.$nodeId,
            'correlation_id' => 'dreyfus_overlay:'.$nodeId,
            'emitter_stage' => 'atlas.dreyfus.cli',
            'emitter_version' => 'atlas.cognitive.dreyfus_cli.v1',
        ];
    }
}
