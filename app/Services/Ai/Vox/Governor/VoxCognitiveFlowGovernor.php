<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Governor;

use App\Services\Ai\Vox\VoxSchema;

/**
 * Atlas Vox V6.8 · Cognitive Flow Governor.
 *
 * Camada determinística final entre "entendi a fala" e "o que a interface
 * deve permitir agora". Não chama provider, não lê histórico, não toca mobile,
 * não executa terminal e não cria memória. Consolida auto mode, intent packet,
 * flow decision e prompt quality em uma política única de ação.
 */
final class VoxCognitiveFlowGovernor
{
    public const SCHEMA = VoxSchema::COGNITIVE_FLOW_GOVERNOR;
    public const VERSION = VoxSchema::COGNITIVE_FLOW_GOVERNOR_VERSION;

    private const POLICY_NO_ACTION = 'no_action';
    private const POLICY_PREVIEW_ONLY = 'preview_only';
    private const POLICY_SINGLE_SAFE_ACTION = 'single_safe_action';
    private const POLICY_STEP_BY_STEP = 'step_by_step_confirmation';
    private const POLICY_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $transcript
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $flowDecision
     * @param  array<string,mixed>|null  $promptQuality
     * @return array<string,mixed>
     */
    public function govern(
        array $transcript,
        array $intentPacket,
        array $flowDecision,
        ?array $promptQuality = null,
    ): array {
        $text = $this->clean((string) ($transcript['text'] ?? ''));
        $mode = (string) ($flowDecision['mode'] ?? $intentPacket['mode'] ?? VoxSchema::MODE_DICTATION);
        $destination = (string) ($flowDecision['destination'] ?? $this->destinationFromIntent($intentPacket));
        $risk = $this->risk((string) ($flowDecision['risk_class'] ?? $intentPacket['risk_class'] ?? VoxSchema::RISK_R0));
        $confidence = (string) ($flowDecision['confidence'] ?? 'medium');
        $needsClarification = (bool) ($flowDecision['needs_clarification'] ?? false);
        $clarifyingQuestion = $this->nullableString($flowDecision['clarifying_question'] ?? null);
        $composite = is_array($flowDecision['composite'] ?? null) ? $flowDecision['composite'] : [];

        $missingItems = $this->missingItems(
            text: $text,
            mode: $mode,
            destination: $destination,
            needsClarification: $needsClarification,
            intentPacket: $intentPacket,
        );
        $contextMissing = $needsClarification || $missingItems !== [];
        $policy = $this->executionPolicy(
            risk: $risk,
            destination: $destination,
            contextMissing: $contextMissing,
            composite: $composite,
        );
        $allowedActions = $this->allowedActions($policy, $mode, $destination);
        $promptQualityIssues = $this->promptQualityIssues($promptQuality);
        $needsReview = $contextMissing
            || $confidence === 'low'
            || $risk === VoxSchema::RISK_R4
            || $promptQualityIssues !== [];

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'engine' => 'deterministic_local_rules',
            'local_only' => true,
            'v7_unlock_allowed' => false,
            'intent' => [
                'primary' => $this->primaryIntent($intentPacket, $flowDecision, $text),
                'secondary' => $this->secondaryIntents($composite),
                'user_words_preserved' => $this->preservedWords($text),
            ],
            'flow' => [
                'mode' => $mode,
                'destination' => $destination,
                'confidence' => $confidence,
                'reason' => $this->flowReason($flowDecision),
            ],
            'context' => [
                'missing' => $contextMissing,
                'missing_items' => $missingItems,
                'clarifying_question' => $contextMissing
                    ? ($clarifyingQuestion ?: $this->defaultClarifyingQuestion($missingItems, $mode))
                    : null,
            ],
            'risk' => [
                'class' => $risk,
                'reason' => $this->riskReason($intentPacket, $flowDecision),
                'requires_confirmation' => $this->requiresConfirmation($risk, $policy),
                'blocked' => $policy === self::POLICY_BLOCKED,
            ],
            'execution' => [
                'policy' => $policy,
                'next_step' => $this->nextStep($policy, $mode, $destination),
                'allowed_actions' => $allowedActions,
            ],
            'quality' => [
                'prompt_quality_required' => in_array($mode, [
                    VoxSchema::MODE_PROMPT_POLISH,
                    VoxSchema::MODE_INTENT_COMPILE,
                    VoxSchema::MODE_GOVERNED_EXECUTE,
                ], true),
                'needs_review' => $needsReview,
                'issues' => $promptQualityIssues,
            ],
            'human_preview' => [
                'heard' => (string) ($flowDecision['what_i_heard'] ?? $text),
                'understood' => (string) ($flowDecision['what_i_understood'] ?? $this->primaryIntent($intentPacket, $flowDecision, $text)),
                'will_do' => (string) ($flowDecision['what_i_will_do'] ?? $this->nextStep($policy, $mode, $destination)),
                'warning' => $this->warning($policy, $risk, $contextMissing, $promptQualityIssues),
            ],
            'guards' => [
                'raw_audio_accepted' => false,
                'cloud_stt' => false,
                'paid_api_required' => false,
                'terminal_execute' => false,
                'voice_realtime_touched' => false,
                'mobile_touched' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function missingItems(string $text, string $mode, string $destination, bool $needsClarification, array $intentPacket): array
    {
        $missing = [];
        $refs = $intentPacket['context_refs'] ?? [];
        $hasResolvedContext = $this->hasResolvedContext(is_array($refs) ? $refs : []);
        $normalized = mb_strtolower($text);

        if ($needsClarification) {
            $missing[] = 'intenção_clara';
        }
        if ($mode !== VoxSchema::MODE_DICTATION
            && preg_match('/\b(isso|esse|essa|aqui|ali|aquele|aquela)\b/u', $normalized) === 1
            && ! $hasResolvedContext
        ) {
            $missing[] = 'referência';
        }
        if ($mode === VoxSchema::MODE_PROMPT_POLISH && ! $hasResolvedContext && mb_strlen($text) < 25) {
            $missing[] = 'texto_para_melhorar';
        }
        if (in_array($destination, ['codex', 'claude'], true) && ! $this->hasActionableGoal($intentPacket, $text)) {
            $missing[] = 'objetivo_para_ia';
        }
        if ($mode === VoxSchema::MODE_GOVERNED_EXECUTE
            && ! $hasResolvedContext
            && ! $this->hasActionableGoal($intentPacket, $text)
            && preg_match('/\b(roda|executa|faz)\b/u', $normalized) === 1
        ) {
            $missing[] = 'alvo_da_execução';
        }

        return array_values(array_unique($missing));
    }

    private function hasResolvedContext(array $refs): bool
    {
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $kind = (string) ($ref['kind'] ?? '');
            $resolved = array_key_exists('resolved', $ref) ? (bool) $ref['resolved'] : ! empty($ref['ref']);
            if ($resolved && in_array($kind, ['file', 'selection', 'active_window', 'terminal_recent', 'workspace'], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasActionableGoal(array $intentPacket, string $text): bool
    {
        $goal = $this->clean((string) ($intentPacket['goal'] ?? ''));
        if (mb_strlen($goal) >= 12) {
            return true;
        }

        return mb_strlen($text) >= 24;
    }

    private function executionPolicy(string $risk, string $destination, bool $contextMissing, array $composite): string
    {
        if ($risk === VoxSchema::RISK_R4) {
            return self::POLICY_BLOCKED;
        }
        if ($contextMissing) {
            return self::POLICY_NO_ACTION;
        }
        $compositePolicy = (string) ($composite['execution_policy'] ?? '');
        if ($compositePolicy === VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP) {
            return self::POLICY_STEP_BY_STEP;
        }
        if ($destination === 'terminal_proposal') {
            return self::POLICY_PREVIEW_ONLY;
        }
        if (in_array($risk, [VoxSchema::RISK_R2, VoxSchema::RISK_R3], true)) {
            return self::POLICY_STEP_BY_STEP;
        }

        return self::POLICY_SINGLE_SAFE_ACTION;
    }

    /**
     * @return list<string>
     */
    private function allowedActions(string $policy, string $mode, string $destination): array
    {
        return match ($policy) {
            self::POLICY_BLOCKED => ['cancel'],
            self::POLICY_NO_ACTION => ['ask', 'cancel'],
            self::POLICY_STEP_BY_STEP => ['confirm', 'cancel'],
            self::POLICY_PREVIEW_ONLY => $destination === 'terminal_proposal'
                ? ['copy', 'cancel']
                : ['copy', 'send_to_atlas', 'cancel'],
            default => match ($mode) {
                VoxSchema::MODE_DICTATION => ['copy', 'insert', 'send_to_atlas', 'cancel'],
                VoxSchema::MODE_PROMPT_POLISH => ['copy', 'insert', 'send_to_atlas', 'cancel'],
                VoxSchema::MODE_INTENT_COMPILE => ['copy', 'send_to_atlas', 'cancel'],
                VoxSchema::MODE_GOVERNED_EXECUTE => ['confirm', 'cancel'],
                default => ['copy', 'cancel'],
            },
        };
    }

    private function primaryIntent(array $intentPacket, array $flowDecision, string $text): string
    {
        foreach ([
            $intentPacket['goal'] ?? null,
            $flowDecision['what_i_understood'] ?? null,
            $text,
        ] as $candidate) {
            $candidate = $this->clean(is_string($candidate) ? $candidate : '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Usar a fala capturada no Atlas Vox.';
    }

    /**
     * @return list<string>
     */
    private function secondaryIntents(array $composite): array
    {
        $steps = $composite['steps'] ?? [];
        if (! is_array($steps) || count($steps) < 2) {
            return [];
        }
        $out = [];
        foreach (array_slice($steps, 1) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $summary = $this->clean((string) ($step['summary'] ?? ''));
            if ($summary !== '') {
                $out[] = $summary;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function preservedWords(string $text): array
    {
        $patterns = [
            'não mexer' => '/\b((não|nao)\s+(mexe|mexer|edita|editar|altera|alterar)|sem\s+(editar|mexer|alterar)|não\s+editar|nao\s+editar)\b/u',
            'sem api paga' => '/\bsem\s+(api|apis|chamada|custo).*(paga|pago|custo)\b/u',
            'codex' => '/\bcodex\b/iu',
            'claude' => '/\bclaude\b/iu',
            'terminal' => '/\bterminal\b/iu',
            'rm -rf' => '/\brm\s+-rf\b/iu',
            'git push --force' => '/\bgit\s+push\s+--force\b/iu',
            'git reset --hard' => '/\bgit\s+reset\s+--hard\b/iu',
            'sudo' => '/\bsudo\b/iu',
        ];

        $out = [];
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $out[] = $label;
            }
        }

        return $out;
    }

    private function destinationFromIntent(array $intentPacket): string
    {
        $provider = (string) ($intentPacket['provider_hint'] ?? 'local');

        return match ($provider) {
            'codex_cli' => 'codex',
            'claude_cli' => 'claude',
            default => 'atlas',
        };
    }

    private function flowReason(array $flowDecision): string
    {
        $reason = $this->clean((string) ($flowDecision['why_this_flow'] ?? ''));

        return $reason !== '' ? $reason : 'Decisão local baseada no modo, risco, destino e contexto disponível.';
    }

    private function riskReason(array $intentPacket, array $flowDecision): string
    {
        $reason = $this->clean((string) ($intentPacket['risk_reasoning'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        return $this->clean((string) ($flowDecision['what_i_will_do'] ?? 'Risco classificado localmente pelo Kernel Vox.'));
    }

    private function requiresConfirmation(string $risk, string $policy): bool
    {
        return $policy === self::POLICY_STEP_BY_STEP
            || in_array($risk, [VoxSchema::RISK_R2, VoxSchema::RISK_R3, VoxSchema::RISK_R4], true);
    }

    private function nextStep(string $policy, string $mode, string $destination): string
    {
        if ($policy === self::POLICY_BLOCKED) {
            return 'Não executar. Bloquear esta ação e pedir revisão explícita.';
        }
        if ($policy === self::POLICY_NO_ACTION) {
            return 'Perguntar o que falta antes de continuar.';
        }
        if ($policy === self::POLICY_STEP_BY_STEP) {
            return 'Mostrar prévia e aguardar confirmação antes de qualquer ação.';
        }
        if ($policy === self::POLICY_PREVIEW_ONLY) {
            return 'Mostrar a proposta pronta para copiar. Não executar automaticamente.';
        }
        if ($mode === VoxSchema::MODE_DICTATION) {
            return 'Devolver o texto para copiar, inserir ou enviar ao Atlas.';
        }

        return $destination === 'atlas'
            ? 'Preparar o resultado e deixar o operador decidir o envio.'
            : 'Preparar o resultado para o destino escolhido sem executar automaticamente.';
    }

    /**
     * @return list<string>
     */
    private function promptQualityIssues(?array $promptQuality): array
    {
        if ($promptQuality === null) {
            return [];
        }
        $issues = [];
        foreach (['issues', 'warnings', 'failures'] as $key) {
            $value = $promptQuality[$key] ?? [];
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $issues[] = $item;
                }
            }
        }
        $status = (string) ($promptQuality['status'] ?? '');
        if (in_array($status, ['warn', 'fail'], true) && $issues === []) {
            $issues[] = 'prompt_quality_'.$status;
        }

        return array_values(array_unique($issues));
    }

    private function warning(string $policy, string $risk, bool $contextMissing, array $promptQualityIssues): ?string
    {
        if ($policy === self::POLICY_BLOCKED) {
            return 'Ação bloqueada por segurança.';
        }
        if ($contextMissing) {
            return 'Preciso de mais contexto antes de agir.';
        }
        if ($promptQualityIssues !== []) {
            return 'A qualidade do prompt precisa de revisão.';
        }
        if (in_array($risk, [VoxSchema::RISK_R2, VoxSchema::RISK_R3], true)) {
            return 'Vou pedir confirmação antes de agir.';
        }

        return null;
    }

    private function defaultClarifyingQuestion(array $missingItems, string $mode): string
    {
        if (in_array('referência', $missingItems, true)) {
            return 'Qual item você quer que eu use como referência?';
        }
        if (in_array('texto_para_melhorar', $missingItems, true)) {
            return 'Qual texto você quer melhorar?';
        }
        if (in_array('alvo_da_execução', $missingItems, true)) {
            return 'O que exatamente você quer executar?';
        }
        if ($mode === VoxSchema::MODE_INTENT_COMPILE) {
            return 'Qual é o objetivo principal do prompt?';
        }

        return 'Pode completar o pedido com o alvo ou contexto?';
    }

    private function risk(string $risk): string
    {
        return in_array($risk, [
            VoxSchema::RISK_R0,
            VoxSchema::RISK_R1,
            VoxSchema::RISK_R2,
            VoxSchema::RISK_R3,
            VoxSchema::RISK_R4,
        ], true) ? $risk : VoxSchema::RISK_R0;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = $this->clean($value);

        return $value === '' ? null : $value;
    }

    private function clean(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
