<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentFactExtractor;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentStreamReader;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\IntentReceiptDecision;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentFact;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentMessage;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\VagueIntentRejection;
use Illuminate\Console\Command;

/**
 * Operator front door for the Quaternity intent-ingest pipeline. Three subcommands:
 *
 *   ingest --json [--since=OFFSET]
 *       Read new messages from the stream, run the extractor, validate via the SchemaRegistry, record receipts
 *       in the ReceiptLedger, emit a deterministic JSON summary.
 *
 *   inspect --message="text" --json
 *       Run the extractor over a one-off text and print the verdict (fact JSON or VagueIntentRejection).
 *
 *   history [--fact-id=ID] [--json]
 *       Print the ordered receipts for a fact (or all receipts if no id).
 */
final class AtlasLoopOperatorIntentCli extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:intent {action : ingest|inspect|history} {--message=} {--fact-id=} {--since=0} {--json}';

    protected $description = 'Operator CLI for the intent ingest pipeline: ingest | inspect | history.';

    public function handle(
        AtlasLoopOperatorIntentStreamReader $stream,
        AtlasLoopOperatorIntentFactExtractor $extractor,
        AtlasLoopOperatorIntentSchemaRegistry $schemas,
        AtlasLoopOperatorIntentReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'ingest' => $this->ingest($stream, $extractor, $schemas, $ledger),
            'inspect' => $this->inspect($extractor),
            'history' => $this->history($ledger),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function ingest(
        AtlasLoopOperatorIntentStreamReader $stream,
        AtlasLoopOperatorIntentFactExtractor $extractor,
        AtlasLoopOperatorIntentSchemaRegistry $schemas,
        AtlasLoopOperatorIntentReceiptLedger $ledger,
    ): int {
        $since = max(0, (int) $this->option('since'));
        $chunk = $stream->tailSince($since);

        $accepted = 0;
        $rejectedVague = 0;
        $rejectedSchema = 0;

        // Malformed JSON lines are rejected at the schema level (the message itself failed to parse).
        foreach ($chunk['errors'] as $parseError) {
            $rejectedSchema++;
            $ledger->record(
                'parse_error_line_'.$parseError->lineNumber,
                IntentReceiptDecision::REJECTED_SCHEMA,
                $parseError->reason,
            );
        }

        foreach ($chunk['messages'] as $message) {
            /** @var OperatorIntentMessage $message */
            $verdict = $extractor->extract($message);
            if ($verdict instanceof VagueIntentRejection) {
                $rejectedVague++;
                $ledger->record($message->id, IntentReceiptDecision::REJECTED_VAGUE, $verdict->axis);

                continue;
            }
            $payload = $this->factPayload($message, $verdict);
            $validation = $schemas->validate($payload);
            if (! $validation->ok) {
                $rejectedSchema++;
                $ledger->record($message->id, IntentReceiptDecision::REJECTED_SCHEMA, 'schema:'.json_encode($validation->toArray()['violations'][0] ?? null));

                continue;
            }
            $accepted++;
            $ledger->record($message->id, IntentReceiptDecision::ACCEPTED, 'extracted', $verdict->verb->value.':'.$verdict->object);
        }

        $summary = [
            'ingested' => $accepted + $rejectedVague + $rejectedSchema,
            'accepted' => $accepted,
            'rejected_vague' => $rejectedVague,
            'rejected_schema' => $rejectedSchema,
            'last_offset' => $chunk['next_offset'],
        ];
        $this->emit($summary, 'ingested='.$summary['ingested'].' accepted='.$accepted);

        return self::EXIT_OK;
    }

    private function inspect(AtlasLoopOperatorIntentFactExtractor $extractor): int
    {
        $text = (string) $this->option('message');
        if ($text === '') {
            return $this->usage('--message is required for inspect');
        }
        $message = new OperatorIntentMessage(hash('sha256', $text), 0, 'operator', $text, 'cli');
        $verdict = $extractor->extract($message);

        $payload = $verdict instanceof OperatorIntentFact
            ? ['fact' => $verdict->toArray()]
            : ['rejection' => $verdict->axis];
        $this->emit($payload, $verdict instanceof OperatorIntentFact ? 'fact verb='.$verdict->verb->value : 'rejection='.$verdict->axis);

        return self::EXIT_OK;
    }

    private function history(AtlasLoopOperatorIntentReceiptLedger $ledger): int
    {
        $factId = trim((string) $this->option('fact-id')) ?: null;
        $rows = $ledger->history($factId);
        $payload = ['fact_id' => $factId, 'receipts' => array_map(static fn ($r): array => $r->toArray(), $rows)];
        $this->emit($payload, 'receipts='.count($rows));

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>
     */
    private function factPayload(OperatorIntentMessage $message, OperatorIntentFact $fact): array
    {
        return [
            'schema' => AtlasLoopOperatorIntentSchemaRegistry::V1,
            'id' => $message->id,
            'ts' => $message->ts,
            'verb' => $fact->verb->value,
            'object' => $fact->object,
            'constraints' => $fact->constraints,
            'source_message_id' => $message->id,
            'source' => $message->source,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line($humanLine);
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
