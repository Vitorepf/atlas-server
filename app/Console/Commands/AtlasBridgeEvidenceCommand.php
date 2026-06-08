<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLoopService;
use App\Services\Ai\Aaeos\Generated\AtlasRuntimeEvidenceLearningService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;

/**
 * Governed evidence-OUT funnel: the sovereignty-preserving channel by which a
 * provider-side workflow (native Claude Code Dynamic Workflows, or any CLI-capable
 * engine) returns evidence INTO Atlas governed memory — over the local CLI shell,
 * never over MCP.
 *
 * The funnel is structurally incapable of self-promotion: it routes the inbound
 * packet through the REAL admissibility gates (admitEvidence + governSignal), the
 * secret-class guard, and stamps an append-only evidence event — always at
 * decision=hold / promotion_allowed=false. It NEVER invokes the compounding
 * promote path. Promotion stays a separate, re-checkable Atlas-side decision gated
 * on a deliver-code envelope or a frozen-judge verdict, never on a provider's own
 * self-reported confidence.
 */
class AtlasBridgeEvidenceCommand extends Command
{
    protected $signature = 'atlas:ai:bridge-evidence
        {--kind=provider_call : Evidence kind (closed vocab: decision|provider_call|tool_run|gate_result|repair_attempt|output_rendered|proposal_created|human_review_outcome)}
        {--evidence-ref= : Id/path of the backing evidence-ledger record (blank cannot govern)}
        {--evidence-complete : The backing evidence record is whole, not partial}
        {--gate-result= : pass|fail — the gate verdict, if any}
        {--has-outcome : A real outcome was recorded}
        {--claims-success : The packet asserts a success result}
        {--metric-value= : Raw metric (cost/latency/score)}
        {--metric-min= : Lower sane bound (default 0)}
        {--metric-max= : Upper sane bound (default +INF)}
        {--contaminated : Caller flags the metric as tainted}
        {--privacy-class=normal : normal|private|sensitive|secret — secret is refused on the provider path}
        {--source=provider : Provider/source label, e.g. claude_cli_workflow}
        {--summary= : Provider-safe one-line summary}
        {--correlation-id= : Correlation id tying this to the inbound run}
        {--envelope-id= : Governing envelope id}
        {--operator-id=system : Operator id}
        {--tenant-id=default : Tenant id}
        {--parent-receipt-id= : Parent decision-receipt id (nested-run causation chain)}
        {--causation-id= : Id of the event/decision that caused this evidence}
        {--packet= : Path to a JSON packet (its keys override the flags above)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Govern and ledger provider/workflow evidence into Atlas (held, never auto-promoted).';

    public function handle(
        AtlasRuntimeEvidenceLearningService $learning,
        AtlasEvidenceLoopService $loop,
        AtlasEvidenceLedger $ledger,
    ): int {
        $packet = $this->packet();

        $kind = strtolower(trim((string) ($packet['kind'] ?? 'provider_call')));
        $source = (string) ($packet['source'] ?? 'provider');
        $privacyClass = strtolower(trim((string) ($packet['privacy_class'] ?? 'normal')));
        $correlationId = (string) ($packet['correlation_id'] ?? '') ?: null;
        $envelopeId = (string) ($packet['envelope_id'] ?? '') ?: ($correlationId ?? 'bridge-evidence');
        $parentReceiptId = (string) ($packet['parent_receipt_id'] ?? '') ?: null;
        $causationId = (string) ($packet['causation_id'] ?? '') ?: null;
        $context = [
            'tenant_id' => (string) ($packet['tenant_id'] ?? 'default'),
            'operator_id' => (string) ($packet['operator_id'] ?? 'system'),
            'envelope_id' => $envelopeId,
            'correlation_id' => $correlationId ?? $envelopeId,
            // Nested-run audit chain: a child evidence event carries the parent
            // receipt + the causing event so the ledger reconstructs the lineage.
            'receipt_id' => $parentReceiptId,
            'causation_id' => $causationId,
            'emitter_stage' => 'atlas.ai.bridge_evidence',
            'emitter_version' => 'bridge-evidence-v1',
        ];

        // Gate 1 — admit by closed evidence vocabulary.
        $admit = $learning->admitEvidence($kind);
        if (! ($admit['admitted'] ?? false)) {
            return $this->blocked($ledger, $context, LedgerEventType::OperationBlocked, [
                'reason' => $admit['reason'] ?? 'unknown_evidence_kind',
                'kind' => $kind,
                'source' => $source,
                'stage' => 'admit_evidence',
            ]);
        }

        // Gate 2 — secret-class guard: secret never travels the provider path.
        if ($privacyClass === 'secret') {
            return $this->blocked($ledger, $context, LedgerEventType::OperationBlocked, [
                'reason' => 'secret_class_refused_on_provider_path',
                'kind' => $kind,
                'source' => $source,
                'stage' => 'secret_class_guard',
            ]);
        }

        // Gate 3 — govern the signal (rejects inferred success, contaminated/out-of-bounds metrics).
        $signal = [
            'kind' => $kind,
            'evidence_ref' => (string) ($packet['evidence_ref'] ?? ''),
            'evidence_complete' => (bool) ($packet['evidence_complete'] ?? false),
            'gate_result' => (string) ($packet['gate_result'] ?? ''),
            'has_outcome' => (bool) ($packet['has_outcome'] ?? false),
            'claims_success' => (bool) ($packet['claims_success'] ?? false),
            'contaminated' => (bool) ($packet['contaminated'] ?? false),
        ];
        if (isset($packet['metric_value'])) {
            $signal['metric_value'] = (float) $packet['metric_value'];
        }
        if (isset($packet['metric_min'])) {
            $signal['metric_min'] = (float) $packet['metric_min'];
        }
        if (isset($packet['metric_max'])) {
            $signal['metric_max'] = (float) $packet['metric_max'];
        }

        $govern = $loop->governSignal($signal);
        $action = (string) ($govern['action'] ?? AtlasEvidenceLoopService::ACTION_REJECT);

        if ($action !== AtlasEvidenceLoopService::ACTION_GOVERN) {
            $type = $action === AtlasEvidenceLoopService::ACTION_QUARANTINE
                ? LedgerEventType::OperationNeedsReview
                : LedgerEventType::OperationBlocked;

            return $this->blocked($ledger, $context, $type, [
                'reason' => 'signal_not_governable',
                'govern_action' => $action,
                'reject_reasons' => $govern['reject_reasons'] ?? [],
                'quarantine_reasons' => $govern['quarantine_reasons'] ?? [],
                'kind' => $kind,
                'source' => $source,
                'stage' => 'govern_signal',
            ]);
        }

        // Governed + clean — stamp ONE append-only evidence event, always HELD.
        $receipt = [
            'schema_version' => 'atlas.ai.bridge_evidence.v1',
            'kind' => $kind,
            'source' => $source,
            'privacy_class' => $privacyClass,
            'evidence_ref' => (string) ($packet['evidence_ref'] ?? ''),
            'summary' => (string) ($packet['summary'] ?? ''),
            'govern_action' => $action,
            'evidence_backed' => (bool) ($govern['evidence_backed'] ?? false),
            'parent_receipt_id' => $parentReceiptId,
            'causation_id' => $causationId,
            // The two non-negotiable safety invariants of this funnel:
            'decision' => 'hold',
            'promotion_allowed' => false,
        ];
        $receipt['evidence_receipt_hash'] = self::canonicalHash($receipt);

        $event = $ledger->record(LedgerEventType::EvidencePacked, $receipt, $context);

        return $this->emit([
            'ok' => true,
            'admitted' => true,
            'governed' => true,
            'decision' => 'hold',
            'promotion_allowed' => false,
            'govern_action' => $action,
            'evidence_receipt_hash' => $receipt['evidence_receipt_hash'],
            'ledger_event_id' => $event?->event_id,
            'ledgered' => $event !== null,
        ], self::SUCCESS, 'Evidence governed and held: '.$kind.' ('.$source.')');
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $payload
     */
    private function blocked(AtlasEvidenceLedger $ledger, array $context, LedgerEventType $type, array $payload): int
    {
        $payload['schema_version'] = 'atlas.ai.bridge_evidence.block.v1';
        $payload['decision'] = 'hold';
        $payload['promotion_allowed'] = false;
        $event = $ledger->record($type, $payload, $context);

        return $this->emit([
            'ok' => false,
            'admitted' => ($payload['stage'] ?? '') !== 'admit_evidence',
            'governed' => false,
            'decision' => 'hold',
            'promotion_allowed' => false,
            'reason' => $payload['reason'] ?? 'blocked',
            'govern_action' => $payload['govern_action'] ?? null,
            'ledger_event_id' => $event?->event_id,
            'ledgered' => $event !== null,
        ], self::FAILURE, 'Evidence blocked: '.(string) ($payload['reason'] ?? 'blocked'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $code, string $human): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($code === self::SUCCESS) {
            $this->info($human);
        } else {
            $this->warn($human);
        }

        return $code;
    }

    /**
     * Merge the optional --packet JSON file over the CLI flags.
     *
     * @return array<string,mixed>
     */
    private function packet(): array
    {
        $flags = [
            'kind' => $this->opt('kind'),
            'evidence_ref' => $this->opt('evidence-ref'),
            'evidence_complete' => (bool) $this->option('evidence-complete') ?: null,
            'gate_result' => $this->opt('gate-result'),
            'has_outcome' => (bool) $this->option('has-outcome') ?: null,
            'claims_success' => (bool) $this->option('claims-success') ?: null,
            'contaminated' => (bool) $this->option('contaminated') ?: null,
            'metric_value' => $this->opt('metric-value'),
            'metric_min' => $this->opt('metric-min'),
            'metric_max' => $this->opt('metric-max'),
            'privacy_class' => $this->opt('privacy-class'),
            'source' => $this->opt('source'),
            'summary' => $this->opt('summary'),
            'correlation_id' => $this->opt('correlation-id'),
            'envelope_id' => $this->opt('envelope-id'),
            'operator_id' => $this->opt('operator-id'),
            'tenant_id' => $this->opt('tenant-id'),
            'parent_receipt_id' => $this->opt('parent-receipt-id'),
            'causation_id' => $this->opt('causation-id'),
        ];
        $flags = array_filter($flags, static fn (mixed $v): bool => $v !== null && $v !== '');

        $path = $this->opt('packet');
        if ($path !== null && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return array_merge($flags, $decoded);
            }
        }

        return $flags;
    }

    private function opt(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Deterministic SHA-256 over a key-sorted payload (mirrors the ledger/tool-evidence hashing).
     *
     * @param  array<string,mixed>  $payload
     */
    public static function canonicalHash(array $payload): string
    {
        $sorter = static function (mixed $value) use (&$sorter): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($sorter, $value);
            }
            ksort($value);

            return array_map($sorter, $value);
        };

        $encoded = json_encode($sorter($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? '' : $encoded);
    }
}
