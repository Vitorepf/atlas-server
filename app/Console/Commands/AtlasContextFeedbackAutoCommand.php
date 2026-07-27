<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * ARFL->ACRS closed loop, capture half (Obra #13 item 4).
 *
 * Distils a finished session transcript into ONE aggregated persisted
 * retrieval-feedback event: it extracts the `context_pack_hash=<16 hex>`
 * markers the UserPromptSubmit hook injected and records them through
 * AtlasRetrievalFeedbackLoopService::capture(record=true). Delivered refs are
 * recovered through the COM-01 delivered-pack ledger. O outcome é inferido do
 * transcript só quando há evidência mecânica (exit codes estruturados); a
 * ATRIBUIÇÃO de uso não é inferida — o corpo do pack entrava no próprio
 * transcript, então casar ref no texto media o eco. Registra-se o entregue com
 * attribution_quality='unmeasured'.
 *
 * Fail-open TOTAL: any fault (missing/unreadable transcript, no hashes,
 * missing table, capture exception) exits 0 with a note — this runs from the
 * session-end hook and must never stall or fail session end.
 */
final class AtlasContextFeedbackAutoCommand extends Command
{
    protected $signature = 'atlas:context:feedback-auto
        {--transcript= : Path to the session transcript file}
        {--outcome=unknown : Deprecated/ignored; outcome is inferred only from structured transcript tool results}
        {--json : Emit JSON}';

    protected $description = 'Close the ARFL->ACRS loop: record one aggregated retrieval-feedback event from the context_pack_hash markers in a session transcript (fail-open).';

    public function handle(): int
    {
        $result = [
            'status' => 'skipped',
            'note' => null,
            'context_pack_hashes' => [],
            'persisted' => false,
        ];

        try {
            $transcript = trim((string) $this->option('transcript'));
            if ($transcript === '' || ! is_file($transcript) || ! is_readable($transcript)) {
                $result['note'] = 'transcript_missing_or_unreadable';

                return $this->emit($result);
            }

            $contents = (string) file_get_contents($transcript);
            preg_match_all(
                '/context_pack_hash=([0-9a-f]{16,64})/i',
                $contents,
                $matches,
            );
            $hashes = AtlasCanonicalContextRef::uniqueStrings(array_map(
                static fn (string $hash): string => strtolower($hash),
                $matches[1] ?? [],
            ));
            $result['context_pack_hashes'] = $hashes;
            if ($hashes === []) {
                $result['note'] = 'no_context_pack_hash_in_transcript';

                return $this->emit($result);
            }

            $delivered = $this->deliveredContextRefsForTranscriptHashes($hashes);
            $resolvedHashes = (array) ($delivered['resolved_hashes'] ?? []);
            $captureHashes = $resolvedHashes !== [] ? $resolvedHashes : $hashes;
            $deliveredRefs = AtlasCanonicalContextRef::uniqueStrings([
                ...(array) ($delivered['delivered_refs'] ?? []),
                ...$this->deliveredMemoryRefsFromTranscriptMarkers($contents),
            ]);
            $usedRefs = $this->registerDeliveredContextRefs($deliveredRefs);
            $outcome = $this->inferStructuredOutcome($contents);
            // O outcome (passed/failed) segue inferido dos exit codes do transcript; a
            // ATRIBUIÇÃO de quais refs foram úteis não tem medidor — declarar 'unmeasured'
            // em vez de reciclar a qualidade do outcome, que dizia 'low' sobre um eco.
            $attributionQuality = 'unmeasured';

            $result['resolved_context_pack_hashes'] = $resolvedHashes;
            $result['delivered_context_refs'] = $deliveredRefs;
            $result['used_context_refs'] = $usedRefs;
            $result['outcome'] = $outcome['outcome'];
            $result['attribution_quality'] = $attributionQuality;

            $capture = app(AtlasRetrievalFeedbackLoopService::class)->capture([
                'record' => true,
                'outcome_status' => (string) $outcome['outcome'],
                'attribution_quality' => $attributionQuality,
                'flow_id' => 'claude.session.auto',
                // One event per session: the receipt id is derived from the hash set.
                'retrieval_receipt_id' => 'session-auto-'.sha1(implode(',', $captureHashes)),
                'context_pack_hashes' => $captureHashes,
                'delivered_context_refs' => $deliveredRefs,
                'used_context_refs' => $usedRefs,
            ]);

            $result['status'] = 'captured';
            $result['persisted'] = (bool) data_get($capture, 'persistence.persisted', false);
            $result['rag_feedback_id'] = data_get($capture, 'persistence.rag_feedback_id');
        } catch (Throwable $exception) {
            $result['note'] = 'fail_open: '.$exception->getMessage();
        }

        return $this->emit($result);
    }

    /**
     * @param  array<int,string>  $hashes
     * @return array{resolved_hashes:array<int,string>,delivered_refs:array<int,string>}
     */
    private function deliveredContextRefsForTranscriptHashes(array $hashes): array
    {
        try {
            $ledger = AtlasDeliveredPackLedger::fromConfig();
            $resolvedHashes = $this->resolveLedgerHashes($ledger, $hashes);
            if ($resolvedHashes === []) {
                return ['resolved_hashes' => [], 'delivered_refs' => []];
            }

            $lookup = $ledger->lookupMany($resolvedHashes);

            return [
                'resolved_hashes' => $resolvedHashes,
                'delivered_refs' => AtlasCanonicalContextRef::uniqueStrings((array) ($lookup['delivered_refs'] ?? [])),
            ];
        } catch (Throwable) {
            return ['resolved_hashes' => [], 'delivered_refs' => []];
        }
    }

    /**
     * UserPromptSubmit can inject the full pack marker before the delivered-pack
     * ledger exists or can be resolved. Extract only provider-safe memory ids and
     * slugs from structured JSON markers; prose remains ignored.
     *
     * @return array<int,string>
     */
    private function deliveredMemoryRefsFromTranscriptMarkers(string $transcript): array
    {
        $refs = [];
        foreach (preg_split('/\R/', $transcript) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->memoryRefsFromMarkerNode($decoded) as $ref) {
                $refs[] = $ref;
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($refs);
    }

    /**
     * @return array<int,string>
     */
    private function memoryRefsFromMarkerNode(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $refs = [];
        foreach (['delivered_context_refs', 'context_refs'] as $key) {
            foreach ($this->flattenMarkerScalars($node[$key] ?? []) as $value) {
                if ($this->isProviderSafeMemoryMarkerRef($value)) {
                    $refs[] = $value;
                }
            }
        }

        foreach ((array) ($node['memory'] ?? []) as $memory) {
            if (! is_array($memory)) {
                continue;
            }
            $id = trim((string) ($memory['id'] ?? ''));
            if ($id !== '' && $this->isProviderSafeMemoryMarkerRef($id)) {
                $refs[] = $id;
            }
            $slug = trim((string) ($memory['slug'] ?? ''));
            if ($slug !== '') {
                $refs[] = 'memory:'.$slug;
            }
        }

        foreach ($node as $value) {
            foreach ($this->memoryRefsFromMarkerNode($value) as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @return array<int,string>
     */
    private function flattenMarkerScalars(mixed $value): array
    {
        if (is_scalar($value)) {
            return [trim((string) $value)];
        }
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            foreach ($this->flattenMarkerScalars($item) as $scalar) {
                $strings[] = $scalar;
            }
        }

        return $strings;
    }

    private function isProviderSafeMemoryMarkerRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }
        $lower = strtolower($ref);
        if (str_starts_with($lower, 'memory:')
            || str_starts_with($lower, 'atlas_memory_entry:')
            || str_starts_with($lower, 'compounding_memory:')
            || str_starts_with($lower, 'ai_compounding_memory:')) {
            return true;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1;
    }

    /**
     * @param  array<int,string>  $hashes
     * @return array<int,string>
     */
    private function resolveLedgerHashes(AtlasDeliveredPackLedger $ledger, array $hashes): array
    {
        $wanted = AtlasCanonicalContextRef::uniqueStrings($hashes);
        if ($wanted === []) {
            return [];
        }

        $resolved = [];
        $rows = (new JsonlReceiptStore($ledger->path()))->replay();
        foreach ($wanted as $needle) {
            $match = '';
            foreach ($rows as $row) {
                $hash = strtolower(trim((string) ($row['context_pack_hash'] ?? '')));
                if ($hash !== '' && str_starts_with($hash, strtolower($needle))) {
                    $match = $hash;
                }
            }
            if ($match !== '') {
                $resolved[] = $match;
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($resolved);
    }

    /**
     * Registra os refs ENTREGUES. Não infere uso: a versão anterior filtrava por
     * `str_contains($transcript, $ref)`, e o transcript contém o próprio pack que
     * declarou o ref — media o eco, e por isso 502/502 eventos saíam com
     * attribution_quality='low' e used == included. Sem medidor real de uso, o
     * honesto é registrar o entregue e marcar a atribuição como 'unmeasured'.
     *
     * @param  array<int,string>  $deliveredRefs
     * @return array<int,string>
     */
    private function registerDeliveredContextRefs(array $deliveredRefs): array
    {
        return AtlasCanonicalContextRef::uniqueStrings($deliveredRefs);
    }

    /**
     * @return array{outcome:string,attribution_quality:string,exit_codes:array<int,int>}
     */
    private function inferStructuredOutcome(string $transcript): array
    {
        $exitCodes = $this->structuredBashExitCodes($transcript);
        if ($exitCodes === []) {
            return ['outcome' => 'unknown', 'attribution_quality' => 'low', 'exit_codes' => []];
        }

        foreach ($exitCodes as $exitCode) {
            if ($exitCode !== 0) {
                return ['outcome' => 'failed', 'attribution_quality' => 'transcript_inferred', 'exit_codes' => $exitCodes];
            }
        }

        return ['outcome' => 'passed', 'attribution_quality' => 'transcript_inferred', 'exit_codes' => $exitCodes];
    }

    /**
     * @return array<int,int>
     */
    private function structuredBashExitCodes(string $transcript): array
    {
        $bashToolIds = [];
        $exitCodes = [];
        foreach (preg_split('/\R/', $transcript) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->arrayNodes($decoded) as $node) {
                if (! $this->isBashToolUse($node)) {
                    continue;
                }
                $id = trim((string) ($node['id'] ?? $node['tool_use_id'] ?? $node['toolUseId'] ?? ''));
                if ($id !== '') {
                    $bashToolIds[$id] = true;
                }
            }

            foreach ($this->arrayNodes($decoded) as $node) {
                if (! $this->isBashToolResult($node, $bashToolIds)) {
                    continue;
                }
                $exitCode = $this->exitCodeFromNode($node);
                if ($exitCode !== null) {
                    $exitCodes[] = $exitCode;
                }
            }
        }

        return $exitCodes;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function arrayNodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $nodes = [];
        if (! array_is_list($value)) {
            $nodes[] = $value;
        }
        foreach ($value as $child) {
            foreach ($this->arrayNodes($child) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function isBashToolUse(array $node): bool
    {
        $type = strtolower(trim((string) ($node['type'] ?? $node['kind'] ?? '')));
        $name = $this->toolName($node);

        return in_array($type, ['tool_use', 'tool_call'], true)
            && in_array($name, ['bash', 'shell'], true);
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,true>  $bashToolIds
     */
    private function isBashToolResult(array $node, array $bashToolIds): bool
    {
        if ($this->exitCodeFromNode($node) === null) {
            return false;
        }

        $name = $this->toolName($node);
        if (in_array($name, ['bash', 'shell'], true)) {
            return true;
        }

        $toolUseId = trim((string) ($node['tool_use_id'] ?? $node['toolUseId'] ?? $node['tool_call_id'] ?? $node['toolCallId'] ?? ''));

        return $toolUseId !== '' && isset($bashToolIds[$toolUseId]);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function toolName(array $node): string
    {
        return strtolower(trim((string) ($node['name'] ?? $node['tool_name'] ?? $node['toolName'] ?? $node['tool'] ?? '')));
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function exitCodeFromNode(array $node): ?int
    {
        foreach (['exit_code', 'exitCode', 'return_code', 'returnCode'] as $key) {
            if (array_key_exists($key, $node) && is_numeric($node[$key])) {
                return (int) $node[$key];
            }
        }

        foreach (['result', 'metadata', 'output'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $exitCode = $this->exitCodeFromNode($node[$key]);
                if ($exitCode !== null) {
                    return $exitCode;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function emit(array $result): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line((string) ($result['note'] ?? $result['status']));
        }

        return self::SUCCESS;
    }
}
