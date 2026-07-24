<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyUntrimmedStringOption;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Append a verifiable evidence receipt to a work item.
 *
 * Receipts without mechanical proof (command, output, files, tests,
 * diff_path or artifact_url) are rejected — narrative-only summaries are
 * not evidence per Contrato 5.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 5)
 */
class AtlasProgrammingReceiptCommand extends Command
{
    use ReadsNonEmptyUntrimmedStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:receipt
        {work_item : Work item code or UUID}
        {--command= : Command executed}
        {--output= : Captured output excerpt}
        {--file=* : Repeatable file changed/inspected (hashed against workspace)}
        {--test=* : Repeatable test command/identifier executed}
        {--diff-path= : Path to diff artifact (read, hashed, excerpted)}
        {--artifact-url= : URL to artifact (screenshot, log, etc.)}
        {--evidence-type= : Override evidence_type (default programming.governance.receipt)}
        {--summary= : Optional human summary (not evidence by itself)}
        {--status=passed : Status field for the receipt}
        {--gap=* : Repeatable explicit gap recorded together with the receipt}
        {--parent-receipt= : Receipt id of a previous receipt this one builds on}
        {--from-file= : Path to JSON receipt file (overrides flags)}
        {--from-stdin : Read JSON receipt from STDIN}
        {--strict : Exit non-zero when the receipt lacks mechanical proof}
        {--json : Print machine-readable JSON}';

    protected $description = 'Append a verifiable evidence receipt to a programming work item.';

    public function handle(
        ProgrammingGovernanceService $governance,
        ProgrammingEvidenceLedger $ledger,
    ): int {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $input = $this->loadInput();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $hasProof = $ledger->hasMechanicalProof($input);
        if (! $hasProof) {
            $payload = [
                'status' => 'rejected',
                'reason' => 'no_mechanical_proof',
                'required_one_of' => ['command', 'output', 'files', 'tests', 'diff_path', 'artifact_url'],
                'input_received' => array_keys($input),
            ];
            if ((bool) $this->option('json')) {
                $this->line($this->encodeOrEmptyObject($payload));
            } else {
                $this->error('Receipt rejected: '.$payload['reason']);
            }

            return (bool) $this->option('strict') ? self::FAILURE : self::FAILURE;
        }

        try {
            $receipt = $ledger->record($workItem, $input);
            $snapshot = $governance->appendEvidence($workItem, $receipt);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject(['receipt' => $receipt, 'work_item' => $snapshot]));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Receipt</>', $snapshot['code']);
        $this->components->twoColumnDetail('Receipt id', $receipt['receipt_id']);
        $this->components->twoColumnDetail('Persisted in', (string) data_get($receipt, 'storage.table', '-'));
        $this->components->twoColumnDetail('Total receipts', (string) count($snapshot['evidence_refs']));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadInput(): array
    {
        $fromFile = $this->option('from-file');
        if (is_string($fromFile) && $fromFile !== '') {
            $contents = @file_get_contents($fromFile);
            if ($contents === false) {
                throw new RuntimeException("receipt_file_unreadable:{$fromFile}");
            }

            return $this->decode($contents);
        }

        if ((bool) $this->option('from-stdin')) {
            $contents = stream_get_contents(STDIN);
            if ($contents === false || trim((string) $contents) === '') {
                throw new RuntimeException('receipt_stdin_empty');
            }

            return $this->decode((string) $contents);
        }

        return array_filter([
            'evidence_type' => $this->stringOption('evidence-type'),
            'status' => $this->stringOption('status') ?? 'passed',
            'command' => $this->stringOption('command'),
            'output' => $this->stringOption('output'),
            'files' => $this->arrayOption('file'),
            'tests' => $this->arrayOption('test'),
            'diff_path' => $this->stringOption('diff-path'),
            'artifact_url' => $this->stringOption('artifact-url'),
            'summary' => $this->stringOption('summary'),
            'gaps' => $this->arrayOption('gap'),
            'parent_receipt_id' => $this->stringOption('parent-receipt'),
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('receipt_payload_not_valid_json_object');
        }

        return $decoded;
    }


    /**
     * @return list<string>
     */
    private function arrayOption(string $key): array
    {
        return array_values(array_filter(
            (array) $this->option($key),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
    }

}
