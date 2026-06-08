<?php

namespace App\Services\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\HermesAdapterReceipt;
use Closure;
use Throwable;

/**
 * Drives ONE Atlas ExecutiveMission through a persistent `hermes acp` session
 * (the robust execution transport) instead of a per-call `hermes chat` subprocess.
 *
 * Sequence (all over JSON-RPC, structured — no human stdout parsing):
 *   initialize -> session/new(cwd=scope, mcpServers=governed) -> session/prompt.
 * The assistant text streams as `session/update` agent_message_chunk notifications
 * and is concatenated client-side; the `session/prompt` result carries stopReason
 * + usage. Mid-run `session/request_permission` requests are routed through
 * {@see HermesAcpPermissionGate} (Atlas decides — default-deny out of scope, the
 * human-in-the-loop power the CLI path never had). The run is mapped to the
 * canonical `atlas.hermes.result_packet.v1` via {@see HermesAcpResultMapper}.
 *
 * Sovereignty/fail-closed: ATLS decides, Hermes executes. ANY transport/protocol
 * failure returns a sealed `fallback_required` receipt so the caller transparently
 * falls back to the CLI provider — nothing is lost, nothing hangs silently.
 */
class AtlasHermesAcpRuntime
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly HermesAcpProtocol $protocol = new HermesAcpProtocol(),
        private readonly HermesAcpPermissionGate $permissionGate = new HermesAcpPermissionGate(),
        private readonly HermesAcpResultMapper $resultMapper = new HermesAcpResultMapper(),
    ) {}

    /**
     * @param  array<string,mixed>  $mission   atlas.hermes.executive_mission.v1
     * @param  array<string,mixed>  $invocation cli/runtime fingerprint (hashed into the packet)
     * @param  array<string,mixed>  $options    cwd, mcp_servers, *_timeout overrides
     * @return array<string,mixed>  result_packet.v1, or a sealed fallback_required receipt
     */
    public function run(array $mission, string $prompt, array $invocation, HermesAcpChannel $channel, array $options = []): array
    {
        $scope = is_array($mission['scope'] ?? null) ? $mission['scope'] : [];
        $mode = $this->normalizeMode((string) ($scope['permission_mode'] ?? 'read'));
        $permissionReceipts = [];
        $text = '';

        try {
            $channel->start();

            if (! $this->doInitialize($channel, 1, $scope, $mode, (float) ($options['init_timeout'] ?? 45), $text, $permissionReceipts)) {
                return $this->fallback('acp_initialize_failed', $mission, $invocation, $permissionReceipts);
            }

            return $this->promptCycle($channel, 2, 3, $mission, $prompt, $invocation, $options, $scope, $mode, $text, $permissionReceipts);
        } catch (Throwable $e) {
            return $this->fallback('acp_transport_exception', $mission, $invocation, $permissionReceipts, $text);
        } finally {
            $channel->stop();
        }
    }

    /**
     * Warm-pooled execution: reuse a per-worker `hermes acp` process (initialized
     * ONCE) across jobs so only the first job pays the ~5s cold start (proc spawn +
     * initialize + MCP registration). Each job still gets a FRESH `session/new`
     * (monotonic JSON-RPC ids drawn from the warm session) so jobs never share
     * conversational context. The session is reused ONLY after a clean success;
     * ANY failure/timeout discards it (stop + drop) so a half-consumed stream can
     * never bleed into the next job — and the caller falls back to the CLI for that
     * one job while the next job re-warms. Identical governance/output to {@see run}.
     *
     * @param  Closure():HermesAcpChannel  $factory  builds a fresh channel when none is warm
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>  result_packet.v1 or a sealed fallback_required receipt
     */
    public function runPooled(HermesAcpSessionPool $pool, string $key, Closure $factory, array $mission, string $prompt, array $invocation, array $options = []): array
    {
        $scope = is_array($mission['scope'] ?? null) ? $mission['scope'] : [];
        $mode = $this->normalizeMode((string) ($scope['permission_mode'] ?? 'read'));
        $session = $pool->lease($key, $factory);
        $permissionReceipts = [];
        $text = '';

        try {
            $session->channel->start(); // idempotent: no-op when the warm process is already running

            if (! $session->initialized) {
                if (! $this->doInitialize($session->channel, $session->nextId(), $scope, $mode, (float) ($options['init_timeout'] ?? 45), $text, $permissionReceipts)) {
                    $pool->discard($key);

                    return $this->fallback('acp_initialize_failed', $mission, $invocation, $permissionReceipts);
                }
                $session->initialized = true;
            }

            $packet = $this->promptCycle($session->channel, $session->nextId(), $session->nextId(), $mission, $prompt, $invocation, $options, $scope, $mode, $text, $permissionReceipts);

            if (($packet['fallback_required'] ?? true) !== false) {
                $pool->discard($key); // half-consumed/failed session is never reused

                return $packet;
            }

            $session->served++;
            $pool->release($session);

            return $packet;
        } catch (Throwable $e) {
            $pool->discard($key);

            return $this->fallback('acp_transport_exception', $mission, $invocation, $permissionReceipts, $text);
        }
    }

    /**
     * Send `initialize` with the given monotonic message id and pump for its result.
     *
     * @param  array<string,mixed>  $scope
     * @param  array<int,array<string,mixed>>  $permissionReceipts
     */
    private function doInitialize(HermesAcpChannel $channel, int $msgId, array $scope, string $mode, float $budget, string &$text, array &$permissionReceipts): bool
    {
        $channel->writeLine($this->protocol->encode($this->protocol->initializeRequest($msgId)));

        return $this->isResult($this->pump($channel, $msgId, $scope, $mode, $budget, $text, $permissionReceipts));
    }

    /**
     * One `session/new` + `session/prompt` exchange on an already-initialized
     * channel, using the supplied monotonic message ids. Returns the mapped
     * result_packet on success or a sealed fallback receipt on any stage failure.
     * Never starts or stops the channel (lifecycle is the caller's). Shared by the
     * cold one-shot {@see run} and the warm-pooled {@see runPooled} so there is
     * exactly ONE protocol path.
     *
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $scope
     * @param  array<int,array<string,mixed>>  $permissionReceipts
     * @return array<string,mixed>
     */
    private function promptCycle(HermesAcpChannel $channel, int $sessionMsgId, int $promptMsgId, array $mission, string $prompt, array $invocation, array $options, array $scope, string $mode, string &$text, array &$permissionReceipts): array
    {
        $cwd = $this->resolveCwd($options, $scope);
        $mcpServers = is_array($options['mcp_servers'] ?? null) ? $options['mcp_servers'] : [];
        $sessionBudget = (float) ($options['session_timeout'] ?? 45);
        $promptBudget = (float) ($options['prompt_timeout'] ?? 600);

        $channel->writeLine($this->protocol->encode($this->protocol->sessionNewRequest($sessionMsgId, $cwd, $mcpServers)));
        $sess = $this->pump($channel, $sessionMsgId, $scope, $mode, $sessionBudget, $text, $permissionReceipts);
        if (! $this->isResult($sess)) {
            return $this->fallback('acp_session_new_failed', $mission, $invocation, $permissionReceipts);
        }
        $sessionId = (string) ($sess['message']['result']['sessionId'] ?? '');
        if ($sessionId === '') {
            return $this->fallback('acp_session_id_missing', $mission, $invocation, $permissionReceipts);
        }

        $channel->writeLine($this->protocol->encode($this->protocol->sessionPromptRequest($promptMsgId, $sessionId, $prompt)));
        $promptMsg = $this->pump($channel, $promptMsgId, $scope, $mode, $promptBudget, $text, $permissionReceipts);
        if (! $this->isResult($promptMsg)) {
            return $this->fallback('acp_prompt_incomplete', $mission, $invocation, $permissionReceipts, $text, $sessionId);
        }

        $packet = $this->resultMapper->map(
            $text,
            $this->protocol->promptResultStopReason($promptMsg['message']),
            $this->protocol->promptResultUsage($promptMsg['message']),
            $sessionId,
            $mission,
            $invocation,
        );
        $packet['fallback_required'] = false;
        $packet['permission_decisions'] = $permissionReceipts;

        return $packet;
    }

    /**
     * Read frames until the JSON-RPC response with $expectId arrives (or budget
     * elapses / channel closes). Concatenates agent_message_chunk text into $text,
     * and governs every session/request_permission through the gate (default-deny).
     *
     * @param  array<string,mixed>  $scope
     * @param  array<int,array<string,mixed>>  $permissionReceipts
     * @return array<string,mixed>|null  the classified result/error frame, or null
     */
    private function pump(HermesAcpChannel $channel, int $expectId, array $scope, string $mode, float $budget, string &$text, array &$permissionReceipts): ?array
    {
        $deadline = microtime(true) + max(0.0, $budget);

        while (microtime(true) < $deadline) {
            $line = $channel->readLine($deadline - microtime(true));
            if ($line === null) {
                return null;
            }

            $msg = $this->protocol->classify($line);
            $type = $msg['type'] ?? 'noise';

            if ($type === 'result' || $type === 'error') {
                if (($msg['id'] ?? null) === $expectId) {
                    return $msg;
                }

                continue;
            }

            if ($type === 'agent_request' && $this->protocol->isPermissionRequest($msg['message'] ?? [])) {
                $decision = $this->permissionGate->decide($msg['message'] ?? [], $scope, $mode);
                $permissionReceipts[] = $decision;
                $optionId = ($decision['decision'] ?? 'deny') === 'allow' ? ($decision['option_id'] ?? null) : null;
                $reqId = $msg['id'] ?? null;
                if (is_int($reqId)) {
                    $channel->writeLine($this->protocol->encode($this->protocol->permissionResponse($reqId, $optionId)));
                }

                continue;
            }

            if ($type === 'notification' && $this->protocol->isAgentMessageChunk($msg['message'] ?? [])) {
                $chunk = $this->protocol->agentMessageChunkText($msg['message'] ?? []);
                if (is_string($chunk)) {
                    $text .= $chunk;
                }
            }
            // other agent_requests, other notifications and noise are ignored.
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $msg
     */
    private function isResult(?array $msg): bool
    {
        return is_array($msg) && ($msg['type'] ?? null) === 'result';
    }

    private function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));

        return in_array($m, ['read', 'write', 'danger'], true) ? $m : 'read';
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $scope
     */
    private function resolveCwd(array $options, array $scope): string
    {
        foreach ([$options['cwd'] ?? null, $scope['workdir'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return sys_get_temp_dir();
    }

    /**
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<int,array<string,mixed>>  $permissionReceipts
     * @return array<string,mixed>
     */
    private function fallback(string $reason, array $mission, array $invocation, array $permissionReceipts = [], string $partialText = '', string $sessionId = ''): array
    {
        return $this->withReceiptHash([
            'schema_version' => 'atlas.hermes.acp_runtime_fallback.v1',
            'transport' => 'acp',
            'fallback_required' => true,
            'reason' => $reason,
            'authority' => 'atlas',
            'hermes_acp_can_decide' => false,
            'partial_output_present' => $partialText !== '',
            'partial_output_hash' => $partialText !== '' ? $this->hashValue([$partialText]) : null,
            'session_id_present' => $sessionId !== '',
            'mission_id' => $mission['mission_id'] ?? null,
            'mission_hash' => $mission['mission_hash'] ?? null,
            'cli_invocation_hash' => $this->hashValue($invocation),
            'permission_decisions' => $permissionReceipts,
        ]);
    }
}
