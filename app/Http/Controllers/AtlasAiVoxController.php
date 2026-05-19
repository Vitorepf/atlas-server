<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\Execution\VoxExecutionGate;
use App\Services\Ai\Vox\Execution\VoxExecutorRouter;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxCompiler;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxReceiptService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Atlas Vox V0–V3 Kernel surface.
 *
 * Scope (Wave 6 / V3 governed_execute · Claude I):
 *   - V0 dictation: pass-through, no compiled_prompt.
 *   - V1 prompt_polish: deterministic PT-BR polish + compiled_prompt.
 *   - V2 intent_compile: VoxIntentExtractor + VoxPromptCompiler emit
 *     full intent_packet with goal/constraints/risk/provider hints.
 *   - V3 governed_execute: same compilation as V2, but additionally
 *     issues a VoxConfirmationRequest (incl. confirmation_token) so the
 *     overlay can confirm before /execute dispatches a real executor.
 *
 * Endpoints:
 *   - GET  /ai/vox/health     — capability advertisement
 *   - POST /ai/vox/intent     — VoxTranscript → VoxIntentPacket + receipt
 *                               (+ confirmation_request when governed)
 *   - POST /ai/vox/execute    — record action outcome OR dispatch a
 *                               governed executor after gate validation
 *
 * Hard rules:
 *   - Raw audio fields are 422-rejected at the boundary.
 *   - confirmation_token is NEVER logged. ledger payloads carry
 *     `confirmation_token_in_ledger=false` as an explicit non-leak flag.
 *   - V0/V1/V2 decisions continue to work unchanged.
 */
final class AtlasAiVoxController extends Controller
{
    public function __construct(
        private readonly VoxCompiler $compiler,
        private readonly VoxReceiptService $receiptService,
        private readonly VoxActionOutcomeService $outcomeService,
        private readonly VoxEvidenceService $evidence,
        private readonly VoxConfirmationService $confirmation,
        private readonly VoxExecutionGate $executionGate,
        private readonly VoxExecutorRouter $executorRouter,
        private readonly VoxAutoModeRouter $autoModeRouter,
        private readonly VoxInterlocutorPolicy $interlocutor,
    ) {}

    public function health(): JsonResponse
    {
        $executorHealth = $this->executorRouter->healthSnapshot();

        return response()->json([
            'schema' => VoxSchema::HEALTH,
            'status' => 'available',
            'version' => VoxSchema::KERNEL_VOX_VERSION,
            'compiler_version' => VoxSchema::COMPILER_VERSION,
            'mode' => 'dictation_polish_intent_and_governed_execute',
            'laws' => ['0', '0.5', '0.75', '0.9'],
            'voice_realtime_status' => 'paused_until_v6',
            'supports' => [
                'dictation' => true,
                'prompt_polish' => true,
                'intent_compile' => true,
                'governed_execute' => true,
            ],
            'prompt_polish' => [
                'template' => VoxPromptPolisher::TEMPLATE_ID,
                'engine' => 'deterministic_rules',
                'language' => VoxSchema::DEFAULT_LANGUAGE,
                'provider_call' => false,
                'llm_call' => false,
            ],
            'intent_compile' => [
                'template_version' => VoxPromptCompiler::TEMPLATE_VERSION,
                'engine' => 'deterministic_rules',
                'language' => VoxSchema::DEFAULT_LANGUAGE,
                'provider_call' => false,
                'llm_call' => false,
                'executes' => false,
                'risk_classes_emitted' => ['R0', 'R1', 'R2', 'R3', 'R4'],
                'providers_supported_as_hint' => ['local', 'codex_cli', 'claude_cli', 'auto'],
                'output_formats_supported' => ['text', 'plan', 'diff', 'notes', 'diagnostic'],
            ],
            'governed_execute' => [
                'engine' => 'deterministic_rules_plus_governed_executor',
                'language' => VoxSchema::DEFAULT_LANGUAGE,
                'provider_call' => true,
                'llm_call' => false,
                'terminal_execute' => false,
                'destructive_auto_execute' => false,
                'confirmation_required_for' => ['R2', 'R3', 'R4'],
                'literal_confirmation_required_for' => ['R4'],
                'risk_classes_emitted' => ['R0', 'R1', 'R2', 'R3', 'R4'],
            ],
            'executors' => [
                VoxSchema::EXECUTOR_TERMINAL_PROPOSE => ['available' => true, 'never_executes' => true],
                VoxSchema::EXECUTOR_NOTE_CAPTURE => $executorHealth[VoxSchema::EXECUTOR_NOTE_CAPTURE] ?? ['available' => false, 'reason' => 'note_capture_unavailable'],
                VoxSchema::EXECUTOR_CODEX_CLI => $executorHealth[VoxSchema::EXECUTOR_CODEX_CLI] ?? ['available' => false, 'reason' => 'codex_cli_unavailable'],
                VoxSchema::EXECUTOR_CLAUDE_CLI => $executorHealth[VoxSchema::EXECUTOR_CLAUDE_CLI] ?? ['available' => false, 'reason' => 'claude_cli_unavailable'],
                VoxSchema::EXECUTOR_FILESYSTEM_EDIT => $executorHealth[VoxSchema::EXECUTOR_FILESYSTEM_EDIT] ?? ['available' => false, 'reason' => 'filesystem_edit_executor_not_ready'],
            ],
            'kernel_guarantees' => [
                'provider_call' => true, // V3 unlocks CLI shell-out behind a confirmation token
                'tool_call' => false,
                'raw_audio_accepted' => false,
                'external_side_effect' => true,
                'terminal_execute' => false,
                'destructive_auto_execute' => false,
                'confirmation_token_in_ledger' => false,
                'clipboard_handled_by' => 'atlas_desktop',
            ],
        ]);
    }

    public function intent(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'schema' => ['nullable', 'string', 'in:'.VoxSchema::TRANSCRIPT],
            'session_id' => ['required', 'string', 'max:120'],
            'transcript_id' => ['required', 'string', 'max:120'],
            'audio_handle' => ['nullable', 'string', 'max:120'],
            'language' => ['required', 'string', 'in:'.VoxSchema::DEFAULT_LANGUAGE],
            'engine' => ['nullable', 'string', 'max:120'],
            'engine_invocation_id' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:12000'],
            'text_raw' => ['nullable', 'string', 'max:12000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'words' => ['nullable', 'array'],
            'personal_dictionary_applied' => ['nullable', 'array'],
            'personal_dictionary_applied.*' => ['string', 'max:240'],
            'post_corrections' => ['nullable', 'array'],
            'latency_ms' => ['nullable', 'array'],
            'noise_signals' => ['nullable', 'array'],
            'raw_pcm_persisted' => ['required', 'boolean'],
            'eclipse_check' => ['nullable', 'string', 'in:passed,aborted_mid_capture'],
            'mode_requested' => ['required', 'string', 'in:auto,'
                .VoxSchema::MODE_DICTATION.','
                .VoxSchema::MODE_PROMPT_POLISH.','
                .VoxSchema::MODE_INTENT_COMPILE.','
                .VoxSchema::MODE_GOVERNED_EXECUTE,
            ],
            'provider_hint' => ['nullable', 'string', 'in:local,codex_cli,claude_cli,auto'],
            'output_format' => ['nullable', 'string', 'in:text,plan,diff,notes,diagnostic'],
            'context_refs' => ['nullable', 'array', 'max:8'],
            'context_refs.*.kind' => ['required_with:context_refs', 'string', 'in:file,selection,active_window,terminal_recent,workspace,surface,none'],
            'context_refs.*.ref' => ['nullable', 'string', 'max:512'],
            'context_refs.*.resolved' => ['nullable', 'boolean'],
            // V4 · context snapshot estruturado (atlas.vox.context_snapshot.v1).
            // Aceito como array livre — o builder canon vive no Desktop e a
            // forma é validada por shape (campos opcionais, nada de áudio bruto).
            'context_snapshot' => ['nullable', 'array'],
            'context_snapshot.schema' => ['nullable', 'string', 'max:120'],
            'context_snapshot.surface' => ['nullable', 'string', 'max:120'],
            'context_snapshot.workspace' => ['nullable', 'array'],
            'context_snapshot.workspace.root' => ['nullable', 'string', 'max:512'],
            'context_snapshot.workspace.name' => ['nullable', 'string', 'max:256'],
            'context_snapshot.active_view' => ['nullable', 'array'],
            'context_snapshot.active_view.kind' => ['nullable', 'string', 'in:atlas_ai,code,terminal,inbox,unknown'],
            'context_snapshot.active_view.label' => ['nullable', 'string', 'max:240'],
            'context_snapshot.selection' => ['nullable', 'array'],
            'context_snapshot.selection.kind' => ['nullable', 'string', 'in:text,none'],
            'context_snapshot.selection.text' => ['nullable', 'string', 'max:1500'],
            'context_snapshot.selection.truncated' => ['nullable', 'boolean'],
            'context_snapshot.thread' => ['nullable', 'array'],
            'context_snapshot.thread.id' => ['nullable', 'string', 'max:120'],
            'context_snapshot.thread.title' => ['nullable', 'string', 'max:240'],
            'context_snapshot.obra' => ['nullable', 'array'],
            'context_snapshot.obra.id' => ['nullable', 'string', 'max:120'],
            'context_snapshot.obra.title' => ['nullable', 'string', 'max:240'],
            'context_snapshot.terminal' => ['nullable', 'array'],
            'context_snapshot.terminal.cwd' => ['nullable', 'string', 'max:512'],
            'context_snapshot.terminal.last_output_excerpt' => ['nullable', 'string', 'max:1500'],
            'context_snapshot.terminal.available' => ['nullable', 'boolean'],
            'context_snapshot.privacy' => ['nullable', 'array'],
            'context_snapshot.privacy.raw_audio_included' => ['nullable', 'boolean'],
            'context_snapshot.privacy.full_screen_capture' => ['nullable', 'boolean'],
            'context_snapshot.privacy.clipboard_read' => ['nullable', 'boolean'],
            // V4 · override marker: Desktop pinou um modo manual contra a
            // sugestão anterior. Apenas telemetria — não muda execução.
            'manual_override' => ['nullable', 'boolean'],
        ]);

        // V4 · Bloqueio extra: snapshot NUNCA pode declarar áudio bruto ou
        // captura de tela. Privacidade pinada no canon do builder; se chegou
        // ligada, é um bug grave que precisa virar 422 ANTES do compile.
        $snapshotInput = $payload['context_snapshot'] ?? null;
        if (is_array($snapshotInput)) {
            $privacy = $snapshotInput['privacy'] ?? [];
            foreach (['raw_audio_included', 'full_screen_capture', 'clipboard_read'] as $forbidden) {
                if (! empty($privacy[$forbidden])) {
                    $this->evidence->actionBlocked(
                        reasonCode: 'context_snapshot_privacy_violation',
                        message: "context_snapshot.privacy.{$forbidden}=true é proibido (Lei 0.75)",
                        payload: [
                            'session_id' => $payload['session_id'],
                            'transcript_id' => $payload['transcript_id'],
                            'forbidden_flag' => $forbidden,
                        ],
                    );
                    throw ValidationException::withMessages([
                        "context_snapshot.privacy.{$forbidden}" =>
                            "Flag {$forbidden}=true é proibida — Vox V4 nunca aceita áudio bruto, captura de tela ou clipboard.",
                    ]);
                }
            }
        }

        if ($payload['raw_pcm_persisted'] === true) {
            $event = $this->evidence->actionBlocked(
                reasonCode: 'raw_pcm_persisted_forbidden',
                message: 'raw_pcm_persisted=true is rejected by Kernel Vox V0',
                payload: [
                    'session_id' => $payload['session_id'],
                    'transcript_id' => $payload['transcript_id'],
                ],
            );
            throw ValidationException::withMessages([
                'raw_pcm_persisted' => 'raw_pcm_persisted must be false in V0 (event: '.$event['event_kind'].')',
            ]);
        }

        if (($payload['eclipse_check'] ?? 'passed') === 'aborted_mid_capture') {
            $this->evidence->actionBlocked(
                reasonCode: 'eclipse_aborted_mid_capture',
                message: 'transcript was aborted mid-capture; Kernel discards',
                payload: [
                    'session_id' => $payload['session_id'],
                    'transcript_id' => $payload['transcript_id'],
                ],
            );
            throw ValidationException::withMessages([
                'eclipse_check' => 'eclipse aborted mid-capture; transcript discarded',
            ]);
        }

        $requestedMode = $payload['mode_requested'];
        $manualOverride = (bool) ($payload['manual_override'] ?? false);
        $snapshotForRouter = is_array($snapshotInput) ? $snapshotInput : [];

        // V4 · roda o Auto Mode Router ANTES do compile. O router é puro,
        // determinístico, sem rede, sem LLM, sem random. Devolve sempre uma
        // sugestão + razão + alternativas — o Desktop usa para mostrar a
        // cabine "Atlas entendeu". Quando o cliente pediu `mode_requested=auto`,
        // a sugestão também vira o modo efetivo do compile; caso contrário
        // a sugestão é apenas advisory (V3 continua intocada).
        $autoDecision = $this->autoModeRouter->decide(
            transcript: $payload,
            context: ['snapshot' => $snapshotForRouter],
        );

        $autoMode = (string) $autoDecision['selected_mode'];
        $mode = $requestedMode === 'auto' ? $autoMode : $requestedMode;

        $hints = [
            'provider_hint' => $payload['provider_hint'] ?? null,
            'output_format' => $payload['output_format'] ?? null,
            'context_refs' => $payload['context_refs'] ?? null,
        ];

        $transcriptReadyEvent = $this->evidence->transcriptReady($payload);

        $intentPacket = $this->compiler->compile($payload, $mode, $hints);
        $intentCompiledEvent = $this->evidence->intentCompiled($intentPacket);

        $events = [$transcriptReadyEvent, $intentCompiledEvent];

        if ($mode === VoxSchema::MODE_PROMPT_POLISH
            || $mode === VoxSchema::MODE_INTENT_COMPILE
            || $mode === VoxSchema::MODE_GOVERNED_EXECUTE
        ) {
            $events[] = $this->evidence->promptCompiled($intentPacket);
        }

        $receipt = $this->receiptService->issueR0($intentPacket);
        $policyEvent = $this->evidence->policyEvaluated($intentPacket, $receipt);
        $events[] = $policyEvent;

        // V3 — issue a confirmation request and bind a single-use token. The
        // token cache lifetime mirrors the request's expires_at. V0/V1/V2
        // skip this entire block; they return confirmation_required=false.
        $confirmationRequest = null;
        $confirmationRequired = false;
        $actions = $this->actionsFor($mode);
        if ($mode === VoxSchema::MODE_GOVERNED_EXECUTE) {
            $preview = $this->governedPreview($intentPacket, $payload['text']);
            $govActions = $this->governedActionsFor($intentPacket);
            $confirmationPair = $this->confirmation->issue(
                intentPacket: $intentPacket,
                receipt: $receipt,
                context: [
                    'mode' => $mode,
                    'risk_class' => (string) $intentPacket['risk_class'],
                    'preview' => $preview,
                    'actions_available' => $govActions,
                    'literal_confirmation_text' => null,
                ],
            );
            $confirmationRequest = $confirmationPair['request'];
            $confirmationRequired = true;
            $actions = $govActions;
            $events[] = $this->evidence->confirmationRequested($confirmationRequest);
        }

        // V5-A · Symbiotic Interlocutor. Camada determinística que olha
        // transcript + intent packet + auto_mode_decision e decide se deve
        // intervir (clarify / caution / disagree / suggest_better_prompt) ou
        // sair de cena (none). Roda DEPOIS do compile/risco para reusar a
        // classificação real do Kernel e ANTES de fechar o cache (a UI pode
        // bloquear o caminho de execução em risco destrutivo R4).
        $interlocutorDecision = $this->interlocutor->evaluate(
            transcript: $payload,
            intentPacket: $intentPacket,
            autoModeDecision: $autoDecision,
            context: ['snapshot' => $snapshotForRouter],
        );
        if (($interlocutorDecision['intervention'] ?? 'none') !== 'none') {
            $events[] = $this->evidence->interlocutorIntervened(
                $intentPacket,
                $interlocutorDecision,
            );
        }

        // Cache intent+receipt so /execute can validate the (intent_id, receipt_id)
        // pair without a database write.
        $this->stash($intentPacket, $receipt, $payload['text']);

        // V4 · derive override semantics for the audit trail.
        //   - `mode_resolution` traces how the effective mode was picked.
        //   - `auto_mode_overridden` flags a *manual* override (Desktop trocou
        //     modo depois da sugestão). Quando o cliente pinou um modo
        //     explícito que coincidentemente bate com a sugestão, isso NÃO
        //     conta como override.
        $autoSuggestion = $autoMode;
        $autoOverridden = false;
        if ($requestedMode === 'auto') {
            $modeResolution = 'auto_router';
        } elseif ($manualOverride && $autoSuggestion !== $requestedMode) {
            $modeResolution = 'manual_override';
            $autoOverridden = true;
        } elseif ($autoSuggestion !== $requestedMode) {
            $modeResolution = 'manual_diverges_from_suggestion';
        } else {
            $modeResolution = 'manual_matches_suggestion';
        }

        if ($autoOverridden) {
            $events[] = $this->evidence->actionBlocked(
                reasonCode: 'vox_auto_mode_overridden',
                message: 'Operador trocou o modo sugerido pelo Auto Mode Router manualmente.',
                payload: [
                    'session_id' => (string) ($payload['session_id'] ?? ''),
                    'transcript_id' => (string) ($payload['transcript_id'] ?? ''),
                    'suggested_mode' => $autoSuggestion,
                    'chosen_mode' => $requestedMode,
                    'router_confidence' => $autoDecision['confidence'] ?? null,
                ],
            );
        }

        return response()->json([
            'schema' => VoxSchema::INTENT_RESPONSE,
            'intent_packet' => $intentPacket,
            'receipt' => $receipt,
            'preview' => $this->previewFor($mode, $intentPacket, $payload['text']),
            'confirmation_required' => $confirmationRequired,
            'confirmation_request' => $confirmationRequest,
            'actions_available' => $actions,
            // V4 · canonical Auto Mode Decision payload (also mirrored as
            // suggested_mode / suggested_mode_reason for the Desktop bridge
            // that already speaks those fields).
            'auto_mode_decision' => $autoDecision,
            'suggested_mode' => $autoSuggestion,
            'suggested_mode_reason' => (string) ($autoDecision['reason_pt_br'] ?? ''),
            'mode_resolution' => $modeResolution,
            'manual_override' => $autoOverridden,
            // V5-A · conversational layer. `intervention=none` quando o policy
            // decidiu não pedir nada; o frontend só mostra UI se vier do Kernel.
            'interlocutor' => $interlocutorDecision,
            'events' => $events,
        ]);
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    private function previewFor(string $mode, array $intentPacket, string $heardText): array
    {
        if ($mode === VoxSchema::MODE_GOVERNED_EXECUTE) {
            return $this->governedPreview($intentPacket, $heardText);
        }

        if ($mode === VoxSchema::MODE_INTENT_COMPILE) {
            return [
                'what_i_heard' => $heardText,
                'what_i_understood' => 'Compilar prompt operacional a partir da fala, com restrições e contexto.',
                'what_i_will_do' => 'Retornar prompt compilado ao Atlas Desktop para copiar/inserir; Kernel NÃO executa.',
                'spoken_summary' => self::spokenSummaryForCompile($intentPacket),
                'risk_class' => $intentPacket['risk_class'],
                'risk_reasoning' => $intentPacket['risk_reasoning'] ?? '',
                'evidence_promise' => 'Registrar VOX_TRANSCRIPT_READY, VOX_INTENT_COMPILED, VOX_PROMPT_COMPILED, VOX_POLICY_EVALUATED.',
                'compiled_prompt' => $intentPacket['compiled_prompt'],
                'compiled_prompt_template' => $intentPacket['compiled_prompt_template'],
                'goal' => $intentPacket['goal'],
                'constraints' => $intentPacket['constraints'],
                'provider_hint' => $intentPacket['provider_hint'],
                'executor_hint' => $intentPacket['executor_hint'] ?? 'none',
                'output_format' => $intentPacket['output_format'] ?? 'text',
                'context_refs' => $intentPacket['context_refs'] ?? [],
                'risk_markers' => (array) data_get($intentPacket, 'compiler_telemetry.risk_markers', []),
            ];
        }

        if ($mode === VoxSchema::MODE_PROMPT_POLISH) {
            return [
                'what_i_heard' => $heardText,
                'what_i_understood' => 'Polir texto/prompt localmente, preservando intenção e restrições.',
                'what_i_will_do' => 'Retornar prompt polido ao Atlas Desktop para copiar/inserir.',
                'spoken_summary' => 'Vou polir esse texto pra você copiar ou inserir — não executo nada.',
                'risk_class' => $intentPacket['risk_class'],
                'evidence_promise' => 'Registrar VOX_TRANSCRIPT_READY, VOX_INTENT_COMPILED, VOX_PROMPT_COMPILED, VOX_POLICY_EVALUATED e receipt R0.',
                'compiled_prompt' => $intentPacket['compiled_prompt'],
                'compiled_prompt_template' => $intentPacket['compiled_prompt_template'],
                'goal' => $intentPacket['goal'],
                'constraints' => $intentPacket['constraints'],
                'provider_hint' => $intentPacket['provider_hint'],
            ];
        }

        return [
            'what_i_heard' => $heardText,
            'what_i_understood' => 'Ditado local: texto pronto para inserir/copiar.',
            'what_i_will_do' => 'Retornar texto ao Atlas Desktop para clipboard/campo focado.',
            'spoken_summary' => 'Vou devolver o texto pra você usar no clipboard ou no campo focado.',
            'risk_class' => $intentPacket['risk_class'],
            'evidence_promise' => 'Registrar VOX_TRANSCRIPT_READY, VOX_INTENT_COMPILED, VOX_POLICY_EVALUATED e receipt R0.',
        ];
    }

    /**
     * V6-GEF · resumo falado para `intent_compile`. Uma frase única,
     * primeira pessoa, em PT-BR. Não inventa execução — deixa claro que
     * o Kernel só devolve o prompt compilado.
     *
     * @param  array<string,mixed>  $intentPacket
     */
    private static function spokenSummaryForCompile(array $intentPacket): string
    {
        $provider = (string) ($intentPacket['provider_hint'] ?? 'local');
        $format = (string) ($intentPacket['output_format'] ?? 'text');

        return match (true) {
            $provider === 'codex_cli' => 'Vou montar um prompt forte pra você colar no Codex — Kernel não chama Codex, você é quem aperta.',
            $provider === 'claude_cli' => 'Vou montar um prompt forte pra você colar no Claude — Kernel não chama Claude, você é quem aperta.',
            $provider === 'atlas' => 'Vou montar uma resposta direta vinda do canon do Atlas, sem provider externo.',
            $format === 'plan' => 'Vou estruturar um plano que você possa revisar antes de implementar.',
            $format === 'diagnostic' => 'Vou montar um diagnóstico do que você descreveu, sem editar nada.',
            default => 'Vou compilar o prompt para você usar; Kernel não executa.',
        };
    }

    /**
     * V3 preview · used both inside the JSON response and inside the
     * VoxConfirmationRequest sub-object (mirrors VoxConfirmation.v1 spec).
     *
     * V6-GEF · agora inclui `spoken_summary` (frase única em PT-BR voz "Vou…")
     * e `action_label` (slug curto pra UI), sem nunca prometer execução que o
     * Kernel não faz.
     *
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>
     */
    private function governedPreview(array $intentPacket, string $heardText): array
    {
        $risk = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);
        $executor = (string) ($intentPacket['executor_hint'] ?? 'none');
        $provider = (string) ($intentPacket['provider_hint'] ?? 'local');

        return [
            'what_i_heard' => $heardText,
            'what_i_understood' => (string) ($intentPacket['goal'] ?? 'Operador descreveu uma ação operacional via voz.'),
            'what_i_will_do' => $this->describeGovernedAction($executor, $provider),
            'spoken_summary' => self::spokenSummaryForGovernedExecute($risk, $provider, $executor),
            'action_label' => self::actionLabelFor($provider, $executor),
            'requires_confirmation' => true,
            'requires_literal_confirmation' => $risk === VoxSchema::RISK_R4,
            'risk_class' => $risk,
            'risk_label' => self::riskLabel($risk),
            'risk_reasoning' => (string) ($intentPacket['risk_reasoning'] ?? ''),
            'evidence_promise' => 'Registrar VOX_TRANSCRIPT_READY, VOX_INTENT_COMPILED, VOX_PROMPT_COMPILED, VOX_POLICY_EVALUATED, VOX_CONFIRMATION_REQUESTED e (após execução) VOX_ACTION_DISPATCHED + VOX_EVIDENCE_RECORDED.',
            'compiled_prompt' => $intentPacket['compiled_prompt'] ?? null,
            'compiled_prompt_template' => $intentPacket['compiled_prompt_template'] ?? null,
            'goal' => $intentPacket['goal'] ?? '',
            'constraints' => $intentPacket['constraints'] ?? [],
            'provider_hint' => $provider,
            'executor_hint' => $executor,
            'output_format' => (string) ($intentPacket['output_format'] ?? 'text'),
            'context_refs' => $intentPacket['context_refs'] ?? [],
            'command_proposal' => null,
            'affected_paths' => [],
            'diff_preview' => null,
        ];
    }

    private function describeGovernedAction(string $executor, string $provider): string
    {
        return match (true) {
            $provider === 'codex_cli' => 'Invocar Codex CLI local (autenticado) com o prompt compilado, capturar stdout e gerar receipt.',
            $provider === 'claude_cli' => 'Invocar Claude CLI local (autenticado) com o prompt compilado, capturar stdout e gerar receipt.',
            $executor === 'terminal_propose' || $executor === 'shell' => 'Propor comando de terminal ao Atlas Desktop — Kernel NUNCA executa shell automaticamente.',
            $executor === 'note' => 'Capturar a fala como nota Atlas Inbox quando disponível, senão devolver desktop_action save_as_note.',
            $executor === 'edit' => 'Edição de filesystem governada por Vox V3 ainda não está disponível — Kernel responde blocked honesto.',
            default => 'Acionar executor governado conforme intent_packet.executor_hint após confirmação humana.',
        };
    }

    /**
     * V6-GEF · resumo falado para governed_execute. Frase única, primeira
     * pessoa, voz "Vou…". Risco vence: R4 sempre fala "arriscado e
     * irreversível"; R3 fala "encosta em execução externa"; depois cai no
     * caminho do executor/provider. Mantém honesto: terminal_propose nunca
     * promete executar.
     */
    private static function spokenSummaryForGovernedExecute(
        string $risk,
        string $provider,
        string $executor,
    ): string {
        if ($risk === VoxSchema::RISK_R4) {
            return 'Isso é arriscado e potencialmente irreversível — vou precisar que você digite a confirmação literal antes de qualquer coisa.';
        }
        if ($risk === VoxSchema::RISK_R3) {
            return 'Isso encosta em execução externa — vou propor o passo, mas só sigo depois que você confirmar.';
        }
        if ($executor === 'terminal_propose' || $executor === 'shell') {
            return 'Vou propor este comando, mas não vou apertar Enter — você copia e executa quando quiser.';
        }
        if ($provider === 'codex_cli') {
            return 'Vou pedir para o Codex analisar isso e devolver a resposta pra você.';
        }
        if ($provider === 'claude_cli') {
            return 'Vou pedir para o Claude analisar isso e devolver a resposta pra você.';
        }
        if ($executor === 'note') {
            return 'Vou salvar isso como nota no Atlas Inbox.';
        }
        if ($executor === 'edit') {
            return 'Edição governada por voz ainda não está disponível — vou bloquear honestamente.';
        }

        return 'Vou seguir o caminho governado, sempre com confirmação humana antes de qualquer execução.';
    }

    /**
     * V6-GEF · slug curto pra UI grudar num botão ou pill. Determinístico,
     * sem traduzir provider/executor — só faz "Codex CLI", "Claude CLI",
     * "Propor comando", "Salvar como nota", "Edição (indisponível)".
     */
    private static function actionLabelFor(string $provider, string $executor): string
    {
        return match (true) {
            $provider === 'codex_cli' => 'Codex CLI',
            $provider === 'claude_cli' => 'Claude CLI',
            $executor === 'terminal_propose' || $executor === 'shell' => 'Propor comando',
            $executor === 'note' => 'Salvar como nota',
            $executor === 'edit' => 'Edição (indisponível)',
            default => 'Ação governada',
        };
    }

    private static function riskLabel(string $risk): string
    {
        return match ($risk) {
            VoxSchema::RISK_R0 => 'R0 · sem efeito externo',
            VoxSchema::RISK_R1 => 'R1 · leitura / análise',
            VoxSchema::RISK_R2 => 'R2 · edição local reversível',
            VoxSchema::RISK_R3 => 'R3 · execução externa contida',
            VoxSchema::RISK_R4 => 'R4 · destrutivo / irreversível',
            default => $risk,
        };
    }

    /**
     * @return list<string>
     */
    private function actionsFor(string $mode): array
    {
        if ($mode === VoxSchema::MODE_PROMPT_POLISH || $mode === VoxSchema::MODE_INTENT_COMPILE) {
            return [
                VoxSchema::DESKTOP_ACTION_COPY_COMPILED_PROMPT,
                VoxSchema::DESKTOP_ACTION_INSERT_COMPILED_PROMPT,
                VoxSchema::DESKTOP_ACTION_COPY_ORIGINAL,
                VoxSchema::DESKTOP_ACTION_CANCEL,
            ];
        }

        return [
            VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD,
            VoxSchema::DESKTOP_ACTION_INSERT_TEXT,
            VoxSchema::DESKTOP_ACTION_CANCEL,
        ];
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @return list<string>
     */
    private function governedActionsFor(array $intentPacket): array
    {
        // V3 always offers execute/edit_intent/save_as_note/cancel per
        // VoxConfirmation.v1 §1. Operator can additionally fall back to
        // copy/insert paths from V1/V2 — they're always safe and bypass
        // the executor entirely.
        $base = [
            VoxSchema::DESKTOP_ACTION_EXECUTE,
            VoxSchema::DESKTOP_ACTION_EDIT_INTENT,
            VoxSchema::DESKTOP_ACTION_SAVE_AS_NOTE,
            VoxSchema::DESKTOP_ACTION_COPY_COMPILED_PROMPT,
            VoxSchema::DESKTOP_ACTION_INSERT_COMPILED_PROMPT,
            VoxSchema::DESKTOP_ACTION_COPY_ORIGINAL,
            VoxSchema::DESKTOP_ACTION_CANCEL,
        ];
        unset($intentPacket);

        return $base;
    }

    public function execute(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'intent_id' => ['required', 'string', 'max:120'],
            'receipt_id' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'string', 'in:'
                .VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD.','
                .VoxSchema::DESKTOP_ACTION_INSERT_TEXT.','
                .VoxSchema::DESKTOP_ACTION_COPY_COMPILED_PROMPT.','
                .VoxSchema::DESKTOP_ACTION_INSERT_COMPILED_PROMPT.','
                .VoxSchema::DESKTOP_ACTION_COPY_ORIGINAL.','
                .VoxSchema::DESKTOP_ACTION_EXECUTE.','
                .VoxSchema::DESKTOP_ACTION_EDIT_INTENT.','
                .VoxSchema::DESKTOP_ACTION_SAVE_AS_NOTE.','
                .VoxSchema::DESKTOP_ACTION_CANCEL,
            ],
            'request_id' => ['nullable', 'string', 'max:120'],
            'confirmation_token' => ['nullable', 'string', 'max:240'],
            'literal_confirmation_input' => ['nullable', 'string', 'max:240'],
        ]);

        $stashed = $this->fetch($payload['intent_id'], $payload['receipt_id']);
        if ($stashed === null) {
            $this->evidence->actionBlocked(
                reasonCode: 'intent_receipt_unknown_or_expired',
                message: 'No active Vox intent matched (intent_id, receipt_id).',
                payload: [
                    'intent_id' => $payload['intent_id'],
                    'receipt_id' => $payload['receipt_id'],
                ],
            );
            throw ValidationException::withMessages([
                'intent_id' => 'unknown or expired intent/receipt pair',
            ]);
        }

        $intentPacket = $stashed['intent_packet'];
        $receipt = $stashed['receipt'];
        $originalText = $stashed['text'];
        $mode = (string) ($intentPacket['mode'] ?? VoxSchema::MODE_DICTATION);
        $decision = (string) $payload['decision'];

        if ($decision === VoxSchema::DESKTOP_ACTION_CANCEL) {
            $outcome = $this->outcomeService->cancelled($intentPacket, $receipt);
            $event = $this->evidence->evidenceRecorded($outcome);
            $this->forget($payload['intent_id'], $payload['receipt_id']);
            if (! empty($payload['request_id'])) {
                $this->confirmation->forget((string) $payload['request_id']);
            }

            return response()->json([
                'schema' => VoxSchema::EXECUTE_RESPONSE,
                'status' => 'cancelled',
                'action_outcome' => $outcome,
                'desktop_action' => null,
                'events' => [$event],
            ]);
        }

        // V3 governed_execute decisions go through the gate + router.
        $governedDecisions = [
            VoxSchema::DESKTOP_ACTION_EXECUTE,
            VoxSchema::DESKTOP_ACTION_EDIT_INTENT,
            VoxSchema::DESKTOP_ACTION_SAVE_AS_NOTE,
        ];
        if (in_array($decision, $governedDecisions, true)) {
            return $this->handleGovernedExecute(
                payload: $payload,
                intentPacket: $intentPacket,
                receipt: $receipt,
                request: $request,
            );
        }

        // V0/V1/V2 clipboard-style decisions remain.
        $resolution = $this->resolveDesktopAction(
            decision: $decision,
            mode: $mode,
            originalText: $originalText,
            compiledPrompt: $intentPacket['compiled_prompt'] ?? null,
        );
        if ($resolution === null) {
            $this->evidence->actionBlocked(
                reasonCode: 'decision_not_available_for_mode',
                message: "Decision '{$decision}' is not available for mode '{$mode}'.",
                payload: [
                    'intent_id' => $payload['intent_id'],
                    'receipt_id' => $payload['receipt_id'],
                    'decision' => $decision,
                    'mode' => $mode,
                ],
            );
            throw ValidationException::withMessages([
                'decision' => "decision '{$decision}' is not available for mode '{$mode}'",
            ]);
        }

        $outcome = $this->outcomeService->completed(
            intentPacket: $intentPacket,
            receipt: $receipt,
            executor: VoxSchema::EXECUTOR_CLIPBOARD_WRITE,
            clipboardText: $resolution['text'],
            sourceText: $resolution['source_text'],
            desktopActionKind: $decision,
        );
        $event = $this->evidence->evidenceRecorded($outcome);
        $this->forget($payload['intent_id'], $payload['receipt_id']);

        return response()->json([
            'schema' => VoxSchema::EXECUTE_RESPONSE,
            'status' => 'completed',
            'action_outcome' => $outcome,
            'desktop_action' => [
                'kind' => $decision,
                'text' => $resolution['text'],
                'source_text' => $resolution['source_text'],
            ],
            'events' => [$event],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     */
    private function handleGovernedExecute(
        array $payload,
        array $intentPacket,
        array $receipt,
        Request $request,
    ): JsonResponse {
        $decision = (string) $payload['decision'];

        if (($intentPacket['mode'] ?? '') !== VoxSchema::MODE_GOVERNED_EXECUTE) {
            $this->evidence->actionBlocked(
                reasonCode: 'governed_decision_for_non_governed_intent',
                message: "Decision '{$decision}' requires mode=governed_execute.",
                payload: [
                    'intent_id' => $payload['intent_id'],
                    'receipt_id' => $payload['receipt_id'],
                    'decision' => $decision,
                    'mode' => (string) ($intentPacket['mode'] ?? ''),
                ],
            );
            throw ValidationException::withMessages([
                'decision' => "decision '{$decision}' requires mode=governed_execute",
            ]);
        }

        // save_as_note is the only governed decision that can route without a
        // confirmation token IF the operator declines to execute via Codex/Claude
        // — but we still want a token (V3 contract), so we require it.
        if (empty($payload['request_id']) || empty($payload['confirmation_token'])) {
            $this->evidence->actionBlocked(
                reasonCode: 'confirmation_token_missing',
                message: 'governed_execute decisions require request_id and confirmation_token.',
                payload: [
                    'intent_id' => $payload['intent_id'],
                    'receipt_id' => $payload['receipt_id'],
                    'decision' => $decision,
                ],
            );
            throw ValidationException::withMessages([
                'confirmation_token' => 'governed_execute decisions require request_id + confirmation_token',
            ]);
        }

        if ($decision === VoxSchema::DESKTOP_ACTION_EDIT_INTENT) {
            // Re-issue is left to the Desktop in V3: it can call POST /intent
            // again with the edited transcript text. The Kernel just records
            // the cancellation of the current confirmation slot here.
            $this->confirmation->forget((string) $payload['request_id']);
            $outcome = $this->outcomeService->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: 'none',
                reasonCode: 'edit_intent_requires_new_intent_call',
                message: 'edit_intent: Desktop deve re-emitir POST /ai/vox/intent com a fala editada para gerar novo receipt + confirmation.',
            );
            $event = $this->evidence->evidenceRecorded($outcome);

            return response()->json([
                'schema' => VoxSchema::EXECUTE_RESPONSE,
                'status' => 'edit_requested',
                'action_outcome' => $outcome,
                'desktop_action' => null,
                'events' => [$event],
            ]);
        }

        // execute / save_as_note both flow through the gate.
        $executor = $decision === VoxSchema::DESKTOP_ACTION_SAVE_AS_NOTE
            ? VoxSchema::EXECUTOR_NOTE_CAPTURE
            : $this->executorRouter->pick(
                executorHint: (string) ($intentPacket['executor_hint'] ?? 'none'),
                providerHint: (string) ($intentPacket['provider_hint'] ?? 'local'),
            );

        $gateDecision = $this->executionGate->evaluate(
            intentPacket: $intentPacket,
            receipt: $receipt,
            request: [
                'request_id' => (string) $payload['request_id'],
                'decision' => $decision,
                'confirmation_token' => (string) ($payload['confirmation_token'] ?? ''),
                'literal_confirmation_input' => $payload['literal_confirmation_input'] ?? null,
                'executor' => $executor,
                'prompt_text' => (string) ($intentPacket['compiled_prompt'] ?? ''),
                'raw_payload' => $request->all(),
            ],
        );

        if (! $gateDecision['allowed']) {
            $code = (string) ($gateDecision['reason_code'] ?? 'blocked');
            $message = (string) ($gateDecision['message'] ?? 'blocked by VoxExecutionGate');

            $outcome = $this->outcomeService->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $executor,
                reasonCode: $code,
                message: $message,
            );
            // Single-use intent on the failure path too — the operator MUST
            // re-issue intent + confirmation rather than retry the token.
            $this->forget($payload['intent_id'], $payload['receipt_id']);
            $this->confirmation->forget((string) $payload['request_id']);

            $events = [];
            if (isset($gateDecision['event']) && is_array($gateDecision['event'])) {
                $events[] = $gateDecision['event'];
            }
            $events[] = $this->evidence->evidenceRecorded($outcome);

            return response()->json([
                'schema' => VoxSchema::EXECUTE_RESPONSE,
                'status' => 'blocked',
                'reason_code' => $code,
                'message' => $message,
                'action_outcome' => $outcome,
                'desktop_action' => null,
                'events' => $events,
            ], 422);
        }

        // Gate said yes — dispatch.
        $dispatchEvent = $this->evidence->actionDispatched([
            'request_id' => (string) $payload['request_id'],
            'session_id' => (string) ($intentPacket['session_id'] ?? ''),
            'intent_id' => (string) ($intentPacket['intent_id'] ?? ''),
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'executor' => $executor,
            'risk_class' => (string) ($intentPacket['risk_class'] ?? ''),
            'decision' => $decision,
            'provider_hint' => (string) ($intentPacket['provider_hint'] ?? ''),
        ]);

        $outcome = $this->executorRouter->dispatch(
            intentPacket: $intentPacket,
            receipt: $receipt,
            context: [
                'executor' => $executor,
                'request_id' => (string) $payload['request_id'],
                'decision' => $decision,
                'source' => 'governed_execute',
            ],
        );

        $evidenceEvent = $this->evidence->evidenceRecorded($outcome);
        $this->forget($payload['intent_id'], $payload['receipt_id']);

        $desktopAction = $this->desktopActionForGovernedOutcome($executor, $outcome, $intentPacket);

        $status = (string) ($outcome['status'] ?? 'completed');
        $httpStatus = $status === 'completed' ? 200 : ($status === 'aborted' ? 422 : 200);

        return response()->json([
            'schema' => VoxSchema::EXECUTE_RESPONSE,
            'status' => $status,
            'action_outcome' => $outcome,
            'desktop_action' => $desktopAction,
            'events' => [$dispatchEvent, $evidenceEvent],
        ], $httpStatus);
    }

    /**
     * @param  array<string,mixed>  $outcome
     * @param  array<string,mixed>  $intentPacket
     * @return array<string,mixed>|null
     */
    private function desktopActionForGovernedOutcome(string $executor, array $outcome, array $intentPacket): ?array
    {
        if ($executor === VoxSchema::EXECUTOR_TERMINAL_PROPOSE) {
            $cmd = (string) data_get($outcome, 'metadata.command_proposed', '');

            return [
                'kind' => 'terminal_proposal',
                'text' => $cmd,
                'source_text' => 'command_proposal',
                'command_executed' => false,
            ];
        }

        if ($executor === VoxSchema::EXECUTOR_NOTE_CAPTURE) {
            $inboxAvailable = (bool) data_get($outcome, 'metadata.inbox_available', false);
            if (! $inboxAvailable) {
                return [
                    'kind' => VoxSchema::DESKTOP_ACTION_SAVE_AS_NOTE,
                    'text' => (string) ($intentPacket['compiled_prompt'] ?? ($intentPacket['human_input_text'] ?? '')),
                    'source_text' => VoxSchema::SOURCE_TEXT_COMPILED_PROMPT,
                ];
            }

            return null;
        }

        return null;
    }

    /**
     * Map a (decision, mode) pair to the actual text the Desktop should
     * paste/insert and the audit-friendly source tag. Returns null when
     * the decision is illegal for the mode (e.g. asking for a compiled
     * prompt in dictation mode).
     *
     * @return array{text: string, source_text: string}|null
     */
    private function resolveDesktopAction(
        string $decision,
        string $mode,
        string $originalText,
        ?string $compiledPrompt,
    ): ?array {
        switch ($decision) {
            case VoxSchema::DESKTOP_ACTION_COPY_TO_CLIPBOARD:
            case VoxSchema::DESKTOP_ACTION_INSERT_TEXT:
                if ($mode !== VoxSchema::MODE_DICTATION) {
                    return null;
                }

                return [
                    'text' => $originalText,
                    'source_text' => VoxSchema::SOURCE_TEXT_ORIGINAL,
                ];
            case VoxSchema::DESKTOP_ACTION_COPY_COMPILED_PROMPT:
            case VoxSchema::DESKTOP_ACTION_INSERT_COMPILED_PROMPT:
                $compiledModes = [
                    VoxSchema::MODE_PROMPT_POLISH,
                    VoxSchema::MODE_INTENT_COMPILE,
                    VoxSchema::MODE_GOVERNED_EXECUTE,
                ];
                if (! in_array($mode, $compiledModes, true)
                    || $compiledPrompt === null
                    || $compiledPrompt === '') {
                    return null;
                }

                return [
                    'text' => $compiledPrompt,
                    'source_text' => VoxSchema::SOURCE_TEXT_COMPILED_PROMPT,
                ];
            case VoxSchema::DESKTOP_ACTION_COPY_ORIGINAL:
                $originalModes = [
                    VoxSchema::MODE_PROMPT_POLISH,
                    VoxSchema::MODE_INTENT_COMPILE,
                    VoxSchema::MODE_GOVERNED_EXECUTE,
                ];
                if (! in_array($mode, $originalModes, true)) {
                    return null;
                }

                return [
                    'text' => $originalText,
                    'source_text' => VoxSchema::SOURCE_TEXT_ORIGINAL,
                ];
            default:
                return null;
        }
    }

    private function rejectAudioFields(Request $request): void
    {
        $forbidden = VoxSchema::prohibitedAudioFields();
        $all = $request->all();
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $all)) {
                $this->evidence->actionBlocked(
                    reasonCode: 'raw_audio_field_forbidden',
                    message: "Field {$field} is forbidden in Vox Kernel V0",
                    payload: [
                        'forbidden_field' => $field,
                    ],
                );
                throw ValidationException::withMessages([
                    $field => "Field '{$field}' is forbidden — raw audio must never reach the Kernel (Lei 0.75)",
                ]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     */
    private function stash(array $intentPacket, array $receipt, string $text): void
    {
        $key = $this->stashKey((string) $intentPacket['intent_id'], (string) $receipt['receipt_id']);
        Cache::put($key, [
            'intent_packet' => $intentPacket,
            'receipt' => $receipt,
            'text' => $text,
        ], now()->addMinutes(5));
    }

    /**
     * @return array{intent_packet: array<string,mixed>, receipt: array<string,mixed>, text: string}|null
     */
    private function fetch(string $intentId, string $receiptId): ?array
    {
        $key = $this->stashKey($intentId, $receiptId);
        $value = Cache::get($key);
        if (! is_array($value)) {
            return null;
        }
        if (! isset($value['intent_packet'], $value['receipt'], $value['text'])) {
            return null;
        }

        return $value;
    }

    private function forget(string $intentId, string $receiptId): void
    {
        Cache::forget($this->stashKey($intentId, $receiptId));
    }

    private function stashKey(string $intentId, string $receiptId): string
    {
        return 'vox:v0:intent:'.$intentId.':'.$receiptId;
    }
}
