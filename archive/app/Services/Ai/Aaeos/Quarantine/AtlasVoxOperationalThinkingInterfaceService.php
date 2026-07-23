<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime contract for the Atlas Vox Operational Thinking Interface doc.
 *
 * This service does NOT re-implement the GATE V3 metric thresholds — that
 * already lives in App\Services\Ai\Vox\Gate\VoxV3PromotionGateService. Instead
 * it models the parts of the doc that had no runtime yet:
 *
 *   1. The "Escada Vox V0-V10" ladder: the eleven ordered qualitative levels,
 *      their canonical names/signals, and which level is the GATE-V3 freeze
 *      boundary (Lei 0.9).
 *   2. The promotion decision: you advance one level at a time (no skipping),
 *      and V4+ stays frozen until GATE V3 is green AND Vitor approves
 *      explicitly (Lei 0.9 + ADR 0003).
 *   3. The "10 Leis Vox" invariants plus the Leis 0 scope laws, and the
 *      "multiplicador negativo = stop-the-line" rule (Lei 10 / Lei 0.9).
 *   4. The canonical flow stages and the minimum VOX_* event taxonomy.
 *
 * Pure and deterministic. No DB, no provider, no side effects. Every public
 * method returns a typed array. Human-facing strings stay in PT-BR to match
 * the surrounding Vox runtime; key names stay in English/snake_case.
 *
 * @see docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
 */
final class AtlasVoxOperationalThinkingInterfaceService
{
    public const SCHEMA_VERSION = 'atlas.vox.operational_thinking_interface.v1';

    /**
     * Level at which the ladder freezes: V4 and everything above it cannot be
     * promoted to until GATE V3 is green (Lei 0.9). V0..V3 are the current
     * authorised Mac/Desktop local-first phase.
     */
    public const FREEZE_FROM_LEVEL = 4;

    /** Highest ladder level — the documented "objetivo final". */
    public const FINAL_LEVEL = 10;

    public const PROMOTION_ALLOWED = 'allowed';
    public const PROMOTION_FROZEN = 'frozen_until_gate_v3';
    public const PROMOTION_SKIP_BLOCKED = 'blocked_no_skip';
    public const PROMOTION_INVALID = 'invalid_level';
    public const PROMOTION_NOOP = 'already_at_or_below';

    /**
     * The Escada Vox V0-V10, verbatim from the "Escada Vox V0-V10" table.
     * Order is load-bearing: promotion advances exactly one rung at a time.
     *
     * @return list<array{level:int,code:string,name:string,signal:string,target:string,is_other_patamar:bool,is_final:bool,frozen_by_gate_v3:bool}>
     */
    public function ladder(): array
    {
        $rows = [
            [0, 'Dictation', 'fala vira texto', 'baseline'],
            [1, 'Prompt Polish', 'fala vira prompt melhor', 'utilidade inicial'],
            [2, 'Intent Compiler', 'fala vira intencao estruturada', 'primeiro salto real'],
            [3, 'Governed Executor', 'intencao vira acao com gates e receipts', 'operacional'],
            [4, 'Contextual Operator', 'Vox entende tela, projeto, terminal, estado e memoria', 'outro patamar inicial'],
            [5, 'Symbiotic Interlocutor', 'Vox questiona intencao com evidencia', 'salto Atlas'],
            [6, 'Ambient Cognitive Layer', 'Vox aparece no canal certo sem vigilancia', 'presenca governada'],
            [7, 'Longitudinal Voice Memory', 'padroes de fala/decisao viram memoria revisavel', 'espelho temporal'],
            [8, 'Cognitive Nervous System', 'Vox reconhece estado, risco e intencao incompleta', 'sistema nervoso cognitivo'],
            [9, 'Shared Inner Loop', 'Atlas participa do loop de formacao do pensamento', 'pensamento compartilhado'],
            [10, 'Sovereign Voice OS', 'voz opera IA, Mac, terminal, memoria e mundo digital sob governanca', 'interface de pensamento operacional'],
        ];

        $out = [];
        foreach ($rows as [$level, $name, $signal, $target]) {
            $out[] = [
                'level' => $level,
                'code' => 'V'.$level,
                'name' => $name,
                'signal' => $signal,
                'target' => $target,
                // The doc: "V4/V5 definem 'outro patamar' para Vox."
                'is_other_patamar' => $level === 4 || $level === 5,
                'is_final' => $level === self::FINAL_LEVEL,
                'frozen_by_gate_v3' => $level >= self::FREEZE_FROM_LEVEL,
            ];
        }

        return $out;
    }

    /**
     * Decide whether Vox may be promoted from $currentLevel to $targetLevel.
     *
     * Enforces three documented rules at once:
     *   - No skipping: target must be exactly current+1 (the ladder is climbed
     *     one rung at a time).
     *   - Lei 0.9 freeze: any target >= V4 is blocked unless GATE V3 is green.
     *   - V4+ always needs explicit Vitor approval (the gate only recommends).
     *
     * @return array{
     *   schema_version:string,
     *   current_level:int,
     *   target_level:int,
     *   decision:string,
     *   allowed:bool,
     *   reason:string,
     *   requires_explicit_vitor_approval:bool,
     *   gate_v3_required:bool,
     *   gate_v3_green:bool
     * }
     */
    public function canPromoteTo(int $targetLevel, int $currentLevel = 3, bool $gateV3Green = false): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'current_level' => $currentLevel,
            'target_level' => $targetLevel,
        ];

        if ($targetLevel < 0 || $targetLevel > self::FINAL_LEVEL || $currentLevel < 0 || $currentLevel > self::FINAL_LEVEL) {
            return $base + [
                'decision' => self::PROMOTION_INVALID,
                'allowed' => false,
                'reason' => 'nivel fora da Escada Vox V0-V10',
                'requires_explicit_vitor_approval' => false,
                'gate_v3_required' => false,
                'gate_v3_green' => $gateV3Green,
            ];
        }

        $gateV3Required = $targetLevel >= self::FREEZE_FROM_LEVEL;
        // V4+ ALWAYS needs Vitor's explicit sign-off (Lei 0.9 / ADR 0003);
        // the gate only ever recommends.
        $needsApproval = $targetLevel >= self::FREEZE_FROM_LEVEL;

        // Already there or trying to go down/sideways → no-op, never a promotion.
        if ($targetLevel <= $currentLevel) {
            return $base + [
                'decision' => self::PROMOTION_NOOP,
                'allowed' => false,
                'reason' => 'alvo nao avanca a escada (igual ou abaixo do nivel atual)',
                'requires_explicit_vitor_approval' => $needsApproval,
                'gate_v3_required' => $gateV3Required,
                'gate_v3_green' => $gateV3Green,
            ];
        }

        // No skipping rungs.
        if ($targetLevel !== $currentLevel + 1) {
            return $base + [
                'decision' => self::PROMOTION_SKIP_BLOCKED,
                'allowed' => false,
                'reason' => 'a escada sobe um degrau por vez; pular niveis e proibido',
                'requires_explicit_vitor_approval' => $needsApproval,
                'gate_v3_required' => $gateV3Required,
                'gate_v3_green' => $gateV3Green,
            ];
        }

        // Lei 0.9 freeze for V4+.
        if ($gateV3Required && ! $gateV3Green) {
            return $base + [
                'decision' => self::PROMOTION_FROZEN,
                'allowed' => false,
                'reason' => 'Lei 0.9: V4-V10 congelados ate GATE V3 verde',
                'requires_explicit_vitor_approval' => $needsApproval,
                'gate_v3_required' => true,
                'gate_v3_green' => false,
            ];
        }

        return $base + [
            'decision' => self::PROMOTION_ALLOWED,
            'allowed' => true,
            'reason' => $needsApproval
                ? 'degrau valido e GATE V3 verde; ainda exige aprovacao explicita do Vitor'
                : 'degrau valido dentro da fase V0-V3 autorizada',
            'requires_explicit_vitor_approval' => $needsApproval,
            'gate_v3_required' => $gateV3Required,
            'gate_v3_green' => $gateV3Green,
        ];
    }

    /**
     * The "10 Leis Vox" plus the Leis 0 scope laws, as enforceable invariants.
     *
     * @return list<array{id:string,group:string,statement:string,stop_the_line:bool}>
     */
    public function laws(): array
    {
        $core = [
            'Voz nao e autoridade; voz e declaracao de intencao.',
            'Nenhuma acao relevante sem Decision Receipt.',
            'Nenhum provider direto fora do Kernel.',
            'Audio cru nao e memoria duravel por padrao.',
            'Presenca ambiental exige opt-in, indicador claro e eclipse.',
            'Controle do Mac exige policy por risco, confirmacao e receipt.',
            'Terminal por voz nunca executa comando destrutivo sem revisao explicita.',
            'Memoria longitudinal deve preservar agencia: observar drift, nunca corrigir identidade.',
            'Esquecer precisa ser tao disponivel quanto lembrar.',
            'Multiplicador negativo e stop-the-line.',
        ];

        $laws = [];
        foreach ($core as $i => $statement) {
            $n = $i + 1;
            $laws[] = [
                'id' => 'lei_vox_'.$n,
                'group' => 'leis_vox',
                'statement' => $statement,
                // Lei 10 is itself the stop-the-line trigger.
                'stop_the_line' => $n === 10,
            ];
        }

        // Leis 0 — scope laws for the current V0-V3 phase. "Violacao = stop-the-line."
        $scope = [
            ['lei_0', 'Mac/Desktop local-first. Sem LiveKit, sem mobile, sem runtime Python, sem provider de audio pago.'],
            ['lei_0_5', 'Voice Realtime Surface congelada como scaffold ate V6.'],
            ['lei_0_75', 'Vox NUNCA chama provider direto: Intent Packet -> Vox Router -> Kernel -> Decide -> Receipt -> Executor.'],
            ['lei_0_9', 'V4-V10 congelados ate GATE V3 verde, com aprovacao explicita de Vitor.'],
        ];
        foreach ($scope as [$id, $statement]) {
            $laws[] = [
                'id' => $id,
                'group' => 'leis_0',
                'statement' => $statement,
                'stop_the_line' => true,
            ];
        }

        return $laws;
    }

    /**
     * Evaluate a live Vox session/metric signal against the laws that map to a
     * hard stop. Returns the violated invariants and whether the line must stop.
     *
     * Inputs are the doc's own metric names. Defaults are the safe/zero values
     * the doc requires.
     *
     * @param  array<string,mixed>  $signals
     * @return array{
     *   schema_version:string,
     *   stop_the_line:bool,
     *   violations:list<array{law:string,statement:string,observed:mixed}>,
     *   evaluated:bool
     * }
     */
    public function evaluateStopTheLine(array $signals = []): array
    {
        $destructiveNoReceipt = (int) ($signals['destructive_action_without_receipt'] ?? 0);
        $rawAudioPersisted = (int) ($signals['raw_audio_persisted_count'] ?? 0);
        $confirmationBypass = (int) ($signals['confirmation_bypass_count'] ?? 0);
        $providerDirect = (int) ($signals['provider_direct_call_count'] ?? 0);
        // Lei 10: a negative multiplier (Vox worse than direct/dictation) stops the line.
        $voiceMultiplier = (float) ($signals['voice_multiplier'] ?? 1.0);

        $violations = [];

        if ($destructiveNoReceipt > 0) {
            $violations[] = [
                'law' => 'lei_vox_2',
                'statement' => 'Nenhuma acao relevante sem Decision Receipt.',
                'observed' => $destructiveNoReceipt,
            ];
        }
        if ($providerDirect > 0) {
            $violations[] = [
                'law' => 'lei_vox_3',
                'statement' => 'Nenhum provider direto fora do Kernel.',
                'observed' => $providerDirect,
            ];
        }
        if ($rawAudioPersisted > 0) {
            $violations[] = [
                'law' => 'lei_vox_4',
                'statement' => 'Audio cru nao e memoria duravel por padrao.',
                'observed' => $rawAudioPersisted,
            ];
        }
        if ($confirmationBypass > 0) {
            $violations[] = [
                'law' => 'lei_vox_6',
                'statement' => 'Controle do Mac exige policy por risco, confirmacao e receipt.',
                'observed' => $confirmationBypass,
            ];
        }
        if ($voiceMultiplier < 1.0) {
            $violations[] = [
                'law' => 'lei_vox_10',
                'statement' => 'Multiplicador negativo e stop-the-line.',
                'observed' => $voiceMultiplier,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stop_the_line' => $violations !== [],
            'violations' => $violations,
            'evaluated' => true,
        ];
    }

    /**
     * The canonical flow stages, in order, from the "Fluxo" section:
     * fala -> transcript -> intent packet -> pipeline -> receipt -> executor ->
     * gate -> evidence -> learning -> output.
     *
     * @return array{schema_version:string,count:int,stages:list<string>}
     */
    public function flow(): array
    {
        $stages = [
            'fala',
            'transcript',
            'intent_packet',
            'pipeline',
            'receipt',
            'executor',
            'gate',
            'evidence',
            'learning',
            'output',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($stages),
            'stages' => $stages,
        ];
    }

    /**
     * The "Eventos Minimos" VOX_* taxonomy that any Vox runtime must emit.
     *
     * @return array{schema_version:string,count:int,events:list<string>}
     */
    public function minimumEvents(): array
    {
        $events = [
            'VOX_SESSION_STARTED',
            'VOX_TRANSCRIPT_READY',
            'VOX_INTENT_COMPILED',
            'VOX_POLICY_EVALUATED',
            'VOX_CONFIRMATION_REQUESTED',
            'VOX_ACTION_DISPATCHED',
            'VOX_ACTION_BLOCKED',
            'VOX_EVIDENCE_RECORDED',
            'VOX_MEMORY_CANDIDATE_CREATED',
            'VOX_DISCORDANCE_PROPOSED',
            'VOX_ECLIPSE_ACTIVATED',
            'VOX_FORGETTING_REQUESTED',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($events),
            'events' => $events,
        ];
    }

    /**
     * Full governance snapshot for the doc — the default command surface.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'thesis' => 'Atlas Vox transforma voz em trabalho cognitivo governado ate virar interface de pensamento operacional.',
            'authorized_phase' => 'V0-V3 (Mac/Desktop local-first)',
            'freeze_from_level' => self::FREEZE_FROM_LEVEL,
            'final_level' => self::FINAL_LEVEL,
            'ladder' => $this->ladder(),
            'laws' => $this->laws(),
            'flow' => $this->flow(),
            'minimum_events' => $this->minimumEvents(),
        ];
    }
}
