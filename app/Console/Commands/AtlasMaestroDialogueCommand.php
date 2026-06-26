<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroDialogueDrivenPacketLedger;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroIntentToPacketShapeProposer;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroPacketShapeOperatorReviewGate;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\CortexGroundingSnapshot;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerEvent;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\IntentFactBundle;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposalRefusal;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposedPacketShape;
use Illuminate\Console\Command;
use Throwable;

/**
 * The single operator entry point to the Quaternity dialogue-to-packet pipeline. Three actions:
 *
 *   propose --intent-file=PATH                       Read IntentFactBundle JSON, call the proposer, persist
 *                                                    the shape, emit a PROPOSED event in the ledger, print
 *                                                    SHAPE_ID=<ulid> machine-parseable.
 *
 *   review --shape-id=ID                             gate.present() + YAML render with file:line citations.
 *                                                    READ-ONLY — never writes to the P03 ledger.
 *
 *   enqueue --shape-id=ID --operator-signature=SIG   Manual hard path. Empty signature ⇒ exit 2.
 *                                                    Approves the shape via gate + inserts on the queue +
 *                                                    emits ENQUEUED.
 *
 *   enqueue --shape-id=ID --auto                     Auto path. Refuses with exit 3 + FEATURE_FLAG_OFF when
 *                                                    `atlas.maestro.dialogue.auto_enqueue_enabled` is false.
 *
 * Exit codes: 0 ok | 2 OPERATOR_SIGNATURE_REQUIRED | 3 FEATURE_FLAG_OFF / policy refusal |
 * 4 tamper_detected | 5 cortex_grounding_refused | 1 other error.
 */
final class AtlasMaestroDialogueCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_ERROR = 1;

    public const EXIT_OPERATOR_SIGNATURE = 2;

    public const EXIT_POLICY_BLOCKED = 3;

    public const EXIT_TAMPER = 4;

    public const EXIT_CORTEX_REFUSED = 5;

    protected $signature = 'atlas:maestro:dialogue {action : propose|review|enqueue} {--shape-id=} {--intent-file=} {--operator-signature=} {--reason=} {--auto : route through auto-enqueue policy P04}';

    protected $description = 'Operator entry point for the Quaternity dialogue-to-packet pipeline.';

    public function handle(
        AtlasMaestroIntentToPacketShapeProposer $proposer,
        AtlasMaestroPacketShapeOperatorReviewGate $reviewGate,
        AtlasMaestroDialogueDrivenPacketLedger $ledger,
        AgentControlPlaneTaskPacketQueueRepository $queue,
    ): int {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'propose' => $this->propose($proposer, $ledger),
                'review' => $this->review($reviewGate),
                'enqueue' => $this->enqueue($reviewGate, $ledger, $queue),
                default => $this->error('unknown action: '.$action) ?? self::EXIT_ERROR,
            };
        } catch (Throwable $e) {
            $this->error('error: '.mb_substr($e->getMessage(), 0, 200));

            return self::EXIT_ERROR;
        }
    }

    private function propose(AtlasMaestroIntentToPacketShapeProposer $proposer, AtlasMaestroDialogueDrivenPacketLedger $ledger): int
    {
        $intentFile = (string) $this->option('intent-file');
        if ($intentFile === '' || ! is_file($intentFile)) {
            $this->error('--intent-file=<path> required and must exist');

            return self::EXIT_ERROR;
        }
        $decoded = json_decode((string) file_get_contents($intentFile), true);
        if (! is_array($decoded)) {
            $this->error('invalid intent JSON');

            return self::EXIT_ERROR;
        }

        $intent = new IntentFactBundle(
            phrases: array_map('strval', (array) ($decoded['phrases'] ?? [])),
            timestamps: array_map('intval', (array) ($decoded['timestamps'] ?? [])),
            scopeTags: array_map('strval', (array) ($decoded['scope_tags'] ?? [])),
            verbs: array_map('strval', (array) ($decoded['verbs'] ?? [])),
        );
        $cortex = new CortexGroundingSnapshot(
            symbols: array_map('strval', (array) ($decoded['cortex_symbols'] ?? [])),
            files: array_map('strval', (array) ($decoded['cortex_files'] ?? [])),
            cortexId: (string) ($decoded['cortex_id'] ?? 'unknown'),
        );
        $waveHint = (string) ($decoded['wave'] ?? 'unwaved');

        $verdict = $proposer->propose($intent, $cortex, $waveHint);
        if ($verdict instanceof ProposalRefusal) {
            $this->error('proposal refused: '.$verdict->code.' '.$verdict->detail);

            return match ($verdict->code) {
                ProposalRefusal::CODE_NO_SCOPE_GROUNDING => self::EXIT_CORTEX_REFUSED,
                default => self::EXIT_ERROR,
            };
        }
        /** @var ProposedPacketShape $verdict */
        $shapeId = $verdict->taskPacketId;

        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_PROPOSED,
            'shape_hash' => hash('sha256', $shapeId),
            'payload' => $verdict->toArray(),
        ]);

        $this->line('SHAPE_ID='.$shapeId);

        return self::EXIT_OK;
    }

    private function review(AtlasMaestroPacketShapeOperatorReviewGate $reviewGate): int
    {
        $shapeId = trim((string) $this->option('shape-id'));
        if ($shapeId === '') {
            $this->error('--shape-id is required for review');

            return self::EXIT_ERROR;
        }
        $bundle = $reviewGate->present($shapeId);
        $this->line($this->renderReviewYaml($bundle));

        return self::EXIT_OK;
    }

    private function enqueue(
        AtlasMaestroPacketShapeOperatorReviewGate $reviewGate,
        AtlasMaestroDialogueDrivenPacketLedger $ledger,
        AgentControlPlaneTaskPacketQueueRepository $queue,
    ): int {
        $shapeId = trim((string) $this->option('shape-id'));
        if ($shapeId === '') {
            $this->error('--shape-id is required for enqueue');

            return self::EXIT_ERROR;
        }

        if ((bool) $this->option('auto')) {
            if (! (bool) config('atlas.maestro.dialogue.auto_enqueue_enabled', false)) {
                $this->line('FEATURE_FLAG_OFF');

                return self::EXIT_POLICY_BLOCKED;
            }
            // P04 policy not yet present on main; when it lands, route via app()->make(...) here.
        }

        $signature = trim((string) $this->option('operator-signature'));
        if (! (bool) $this->option('auto') && $signature === '') {
            $this->line('OPERATOR_SIGNATURE_REQUIRED');

            return self::EXIT_OPERATOR_SIGNATURE;
        }

        $reason = (string) $this->option('reason');
        $shapeHash = hash('sha256', $shapeId);

        // Ledger state machine: Proposed → Reviewed → Approved → Enqueued.
        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_REVIEWED,
            'shape_hash' => $shapeHash,
            'payload' => ['gate' => 'inline-review'],
        ]);

        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_APPROVED,
            'shape_hash' => $shapeHash,
            'operator_signature' => $signature !== '' ? $signature : 'auto',
            'payload' => ['reason' => $reason ?: 'cli'],
        ]);

        $approved = $reviewGate->approve($shapeId, $signature !== '' ? $signature : 'auto', 'approve:'.($reason ?: 'cli'));

        $bundle = $reviewGate->present($shapeId);
        $packet = is_array($bundle->proposal) ? $bundle->proposal : $bundle->proposal->toArray();
        $packet['task_packet_hash'] = $approved->proposalHash;
        // Write to the OPERATOR SERVING disk (the disk `atlas:task next` reads). The injected
        // $queue resolves through the container at the default disk; an approved packet landing
        // there is invisible to the worker.
        $queueEnvelope = AtlasTaskServingStack::queueRepo()->enqueue($packet);

        $ledger->append([
            'event_type' => DialogueLedgerEvent::TYPE_ENQUEUED,
            'shape_hash' => $shapeHash,
            'payload' => ['approved_hash' => $approved->approvalHash, 'queue_envelope' => $queueEnvelope],
        ]);

        $this->line('ENQUEUED='.$shapeId);

        return self::EXIT_OK;
    }

    private function renderReviewYaml(object $bundle): string
    {
        // Deterministic YAML-ish rendering — the test asserts byte-identical stdout across two calls, not
        // schema-perfect YAML. Sorted scalar lines + cortex citations as `file:line`.
        $proposal = is_array($bundle->proposal) ? $bundle->proposal : $bundle->proposal->toArray();
        ksort($proposal);
        $lines = ['shape_id: '.$bundle->shapeId, 'proposal_hash: '.$bundle->proposalHash, 'stale: '.($bundle->stale ? 'true' : 'false')];
        foreach ($proposal as $key => $value) {
            $lines[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
        $citations = (array) $bundle->cortexAnchorCitations;
        sort($citations);
        $lines[] = 'citations:';
        foreach ($citations as $citation) {
            $lines[] = '  - '.(string) $citation;
        }

        return implode("\n", $lines);
    }
}
