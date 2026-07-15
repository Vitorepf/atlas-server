<?php

namespace App\Services\Ai;

use App\Models\AiTrace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Projeção mínima, segura e versionada do estado que uma superfície humana pode
 * mostrar durante uma execução. Não transporta prompt, stdout, erro cru ou
 * detalhe do provider: esses dados ficam no ledger/auditoria apropriados.
 */
class AiExecutionPresentationState
{
    public const SCHEMA = 'atlas.execution.presentation.v1';

    /**
     * Acrescenta o relógio público e persistente de uma execução ao estado de
     * apresentação. O valor acumulado nunca inclui uma pausa: ao retomar, o
     * novo marco de corrida fica explícito para Mobile, Desktop e widgets
     * continuarem o mesmo relógio após relaunch ou handoff.
     *
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function withTimer(
        AiTrace $trace,
        array $state,
        string $timing,
        CarbonInterface $now,
    ): array {
        if (! in_array($timing, ['running', 'paused', 'finished'], true)) {
            throw new \InvalidArgumentException('Unsupported execution timer timing.');
        }

        $previous = data_get($trace->metadata, 'presentation_state.timer');
        $elapsed = is_array($previous) && is_int($previous['elapsed_active_ms'] ?? null)
            ? max(0, $previous['elapsed_active_ms'])
            : 0;

        $runningSince = is_array($previous) && ($previous['timing'] ?? null) === 'running'
            ? $this->parseTimerTimestamp($previous['running_since'] ?? null)
            : null;

        if ($runningSince) {
            $elapsed += (int) max(0, $now->valueOf() - $runningSince->valueOf());
        } elseif (! is_array($previous) && $trace->created_at) {
            $elapsed = (int) max(0, $now->valueOf() - $trace->created_at->valueOf());
        }

        $timer = [
            'elapsed_active_ms' => $elapsed,
            'timing' => $timing,
        ];

        if ($timing === 'running') {
            $timer['running_since'] = $now->toIso8601String();
        } elseif ($timing === 'paused') {
            $timer['paused_at'] = $now->toIso8601String();
        } else {
            $timer['finished_at'] = $now->toIso8601String();
        }

        $state['timer'] = $timer;

        return $state;
    }

    private function parseTimerTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || strlen($value) > 80) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function stateWithTimer(?AiTrace $trace, array $state, string $timing, ?CarbonInterface $now = null): array
    {
        return $trace
            ? $this->withTimer($trace, $state, $timing, $now ?? now())
            : $state;
    }

    /**
     * @param  list<array<string,mixed>>  $options
     * @return array<string,mixed>
     */
    public function providerChoice(
        string $errorCode,
        array $options,
        ?string $resetAt = null,
        ?AiTrace $trace = null,
        ?CarbonInterface $now = null,
    ): array
    {
        $emittedAt = $now ?? now();
        [$title, $detail] = match ($errorCode) {
            'rate_limited' => [
                'Escolha como continuar',
                'O provedor atingiu o limite. Escolha uma alternativa para preservar a sessão.',
            ],
            'auth_expired' => [
                'Login necessário no provedor',
                'O provedor precisa de uma ação no Terminal antes de continuar.',
            ],
            default => [
                'Decisão necessária',
                'A execução foi pausada e precisa de uma decisão explícita para continuar.',
            ],
        };

        $state = [
            'schema' => self::SCHEMA,
            'kind' => 'attention_required',
            'title' => $title,
            'detail' => $detail,
            'checkpoint' => 'provider',
            // O instante vem do servidor e é persistido no ledger para que as
            // superfícies iOS congelem o timer no mesmo ponto após reconnect
            // ou relaunch, sem estimar uma pausa local.
            'paused_at' => $emittedAt->toIso8601String(),
            'actions' => $this->actions($options),
        ];

        if (is_string($resetAt) && $resetAt !== '') {
            $state['deadline'] = Str::limit($resetAt, 80, '');
        }

        return $this->stateWithTimer($trace, $state, 'paused', $emittedAt);
    }

    /**
     * Registra o desfecho real de uma escolha. Isso substitui na timeline a
     * atenção anterior; não apagar o evento é essencial para o histórico.
     *
     * @return array<string,mixed>
     */
    public function providerChoiceResolved(
        string $action,
        string $resultingStatus,
        ?string $availableAt = null,
        ?AiTrace $trace = null,
        ?CarbonInterface $now = null,
    ): array
    {
        $emittedAt = $now ?? now();
        if ($action === 'wait') {
            $state = [
                'schema' => self::SCHEMA,
                'kind' => 'awaiting_external',
                'title' => 'Aguardando disponibilidade do provedor',
                'detail' => 'A sessão permanece preservada e será retomada quando o provedor liberar.',
                'checkpoint' => 'provider',
                'paused_at' => $emittedAt->toIso8601String(),
                'actions' => [],
            ];
            if (is_string($availableAt) && $availableAt !== '') {
                $state['deadline'] = Str::limit($availableAt, 80, '');
            }

            return $this->stateWithTimer($trace, $state, 'paused', $emittedAt);
        }

        if ($resultingStatus === 'queued') {
            return $this->stateWithTimer($trace, [
                'schema' => self::SCHEMA,
                'kind' => 'recovering',
                'title' => 'Execução retomando',
                'detail' => 'A decisão foi registrada; o Atlas vai continuar a partir do próximo checkpoint.',
                'checkpoint' => 'provider',
                'actions' => [],
            ], 'running', $emittedAt);
        }

        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'failed',
            'title' => $resultingStatus === 'cancelled' ? 'Sessão encerrada' : 'Execução encerrada',
            'detail' => 'A decisão foi registrada e a conversa permanece preservada.',
            'checkpoint' => 'provider',
            'actions' => [],
        ], 'finished', $emittedAt);
    }

    /**
     * O fallback automático só é publicado depois que o job foi reenfileirado
     * para o provedor alternativo. A superfície humana recebe a recuperação
     * real, sem expor quota, stderr ou detalhes do provedor que falhou.
     *
     * @return array<string,mixed>
     */
    public function automaticProviderFallback(?AiTrace $trace = null, ?CarbonInterface $now = null): array
    {
        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'recovering',
            'title' => 'Execução retomando com alternativa',
            'detail' => 'A sessão foi preservada e continuará a partir do próximo checkpoint.',
            'checkpoint' => 'provider',
            'actions' => [],
        ], 'running', $now);
    }

    /**
     * O worker só emite esta recuperação depois de detectar que a tentativa
     * anterior expirou e reenfileirar o mesmo job. Não promete que o provider
     * já voltou nem expõe o erro técnico que deixou o processo stale.
     *
     * @return array<string,mixed>
     */
    public function staleWorkerRecovery(?AiTrace $trace = null, ?CarbonInterface $now = null): array
    {
        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'recovering',
            'title' => 'Recuperando execução interrompida',
            'detail' => 'A sessão foi preservada e voltou para a fila a partir do último checkpoint.',
            'checkpoint' => 'provider',
            'actions' => [],
        ], 'running', $now);
    }

    /**
     * Estado terminal emitido somente depois que o worker confirmou a
     * conclusão. A prova detalhada continua no ledger; esta projeção é segura
     * para superfícies humanas e para o replay móvel.
     *
     * @return array<string,mixed>
     */
    public function completed(?AiTrace $trace = null, ?CarbonInterface $now = null): array
    {
        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'completed',
            'title' => 'Execução concluída',
            'detail' => 'O resultado e as evidências foram registrados.',
            'checkpoint' => 'evidence',
            'actions' => [],
        ], 'finished', $now);
    }

    /**
     * O loop só publica replanejamento depois de enfileirar a próxima tentativa
     * de reparo. Os contadores vêm do contrato real de iteração, nunca da UI.
     *
     * @return array<string,mixed>
     */
    public function replanning(
        int $currentIteration,
        int $nextIteration,
        int $maxIterations,
        ?AiTrace $trace = null,
        ?CarbonInterface $now = null,
    ): array
    {
        $next = max(1, $nextIteration);
        $total = max($next, $maxIterations, $currentIteration, 1);

        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'replanning',
            'title' => 'Plano em correção',
            'detail' => "A verificação pediu correção antes de concluir. Próxima tentativa {$next} de {$total}.",
            'checkpoint' => 'quality',
            'actions' => [],
        ], 'running', $now);
    }

    /**
     * Estado terminal sem erro cru, prompt ou saída do provedor. O diagnóstico
     * técnico fica no ledger/auditoria correspondente.
     *
     * @return array<string,mixed>
     */
    public function failed(?AiTrace $trace = null, ?CarbonInterface $now = null): array
    {
        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'failed',
            'title' => 'Execução falhou',
            'detail' => 'A execução foi encerrada e o diagnóstico permanece disponível.',
            'checkpoint' => 'provider',
            'actions' => [],
        ], 'finished', $now);
    }

    /**
     * Cancelamento é terminal, mas não é uma falha do provedor. Mantemos o
     * mesmo kind de encerramento para a casca e distinguimos a causa pelo
     * título público, sem expor dados operacionais.
     *
     * @return array<string,mixed>
     */
    public function cancelled(?AiTrace $trace = null, ?CarbonInterface $now = null): array
    {
        return $this->stateWithTimer($trace, [
            'schema' => self::SCHEMA,
            'kind' => 'failed',
            'title' => 'Sessão encerrada',
            'detail' => 'A execução foi encerrada pelo operador e a conversa permanece preservada.',
            'checkpoint' => 'operator',
            'actions' => [],
        ], 'finished', $now);
    }

    /**
     * @param  list<array<string,mixed>>  $options
     * @return list<array{id:string,title:string,style:string}>
     */
    private function actions(array $options): array
    {
        $actions = [];
        $hasPrimary = false;

        foreach ($options as $option) {
            $id = $option['id'] ?? null;
            $label = $option['label'] ?? null;
            $action = $option['action'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-z0-9_-]{1,64}$/', $id)
                || ! is_string($label) || trim($label) === ''
                || ! is_string($action) || ! in_array($action, ['switch_provider', 'downgrade_model', 'wait', 'fail', 'cancel', 'retry_same'], true)) {
                continue;
            }

            $style = in_array($action, ['fail', 'cancel'], true)
                ? 'destructive'
                : ($hasPrimary ? 'secondary' : 'primary');
            $hasPrimary = $hasPrimary || $style === 'primary';
            $actions[] = [
                'id' => $id,
                'title' => Str::limit(trim($label), 120, ''),
                'style' => $style,
            ];

            if (count($actions) === 6) {
                break;
            }
        }

        return $actions;
    }
}
