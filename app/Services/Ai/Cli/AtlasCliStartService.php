<?php

namespace App\Services\Ai\Cli;

use App\Models\AiThread;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class AtlasCliStartService
{
    public function __construct(
        private readonly AtlasCliSessionService $sessions,
    ) {}

    /**
     * @return array{
     *   workspace:string,
     *   has_db:bool,
     *   thread:?array<string,mixed>,
     *   state:?array<string,mixed>,
     *   resumable:?array<string,mixed>,
     *   next_action:?array<string,mixed>
     * }
     */
    public function briefing(string $workspace): array
    {
        if (! DatabaseTableAvailability::has('ai_threads')) {
            return [
                'workspace' => $workspace,
                'has_db' => false,
                'thread' => null,
                'state' => null,
                'resumable' => null,
                'next_action' => $this->freshAction(),
            ];
        }

        $thread = AiThread::query()
            ->where('surface', 'atlas_cli')
            ->where('workspace', $workspace)
            ->where('status', 'active')
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->first();

        if (! $thread) {
            return [
                'workspace' => $workspace,
                'has_db' => true,
                'thread' => null,
                'state' => null,
                'resumable' => null,
                'next_action' => $this->freshAction(),
            ];
        }

        $snapshot = $this->sessions->snapshot($workspace, (string) $thread->id);
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : null;
        $resumable = $this->sessions->findResumablePlan($workspace, (string) $thread->id);
        $threadPayload = is_array($snapshot['thread'] ?? null) ? $snapshot['thread'] : [
            'id' => (string) $thread->id,
            'title' => $thread->title,
            'last_message_at' => $thread->last_message_at?->toJSON(),
            'last_provider' => $thread->last_provider,
        ];
        $threadPayload['last_message_human'] = $this->humanTime($thread->last_message_at);

        $nextAction = $this->pickNextAction($workspace, $threadPayload, $state, $resumable);

        return [
            'workspace' => $workspace,
            'has_db' => true,
            'thread' => $threadPayload,
            'state' => $state,
            'resumable' => $resumable,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $thread
     * @param  array<string,mixed>|null  $state
     * @param  array<string,mixed>|null  $resumable
     * @return array<string,mixed>
     */
    private function pickNextAction(string $workspace, array $thread, ?array $state, ?array $resumable): array
    {
        if ($resumable) {
            return [
                'kind' => 'resume',
                'title' => (string) ($resumable['task'] ?? 'plano anterior'),
                'reason' => (string) ($resumable['reason'] ?? 'plano nao finalizado'),
                'microaction' => $this->microactionFromState($state)
                    ?? 'reabrir o plano e seguir da fase '.($resumable['reason'] ?? 'anterior'),
                'command' => 'atlas continue',
                'plan_id' => (string) ($resumable['plan_id'] ?? ''),
            ];
        }

        $pendingSteer = is_string($state['pending_steer'] ?? null) ? trim((string) $state['pending_steer']) : '';
        if ($pendingSteer !== '') {
            return [
                'kind' => 'pending_steer',
                'title' => 'absorver steer pendente: '.Str::limit($pendingSteer, 80),
                'reason' => 'steer ainda nao foi entregue',
                'microaction' => 'rode atlas chat e cole o contexto do steer',
                'command' => 'atlas chat',
            ];
        }

        $nextSteps = $this->normalizeTextItems($state['next_steps'] ?? []);
        if ($nextSteps !== []) {
            $first = $nextSteps[0];

            return [
                'kind' => 'next_step',
                'title' => $first,
                'reason' => 'proximo passo registrado na sessao',
                'microaction' => $first,
                'command' => 'atlas chat',
            ];
        }

        $objective = is_string($state['objective'] ?? null) ? trim((string) $state['objective']) : '';
        if ($objective !== '') {
            return [
                'kind' => 'objective',
                'title' => $objective,
                'reason' => 'objetivo da sessao',
                'microaction' => $this->microactionFromState($state)
                    ?? 'pergunte: atlas chat "qual a primeira microacao para: '.Str::limit($objective, 60).'"',
                'command' => 'atlas chat',
            ];
        }

        $openLoops = $this->normalizeTextItems($state['open_loops'] ?? []);
        if ($openLoops !== []) {
            $first = $openLoops[0];

            return [
                'kind' => 'open_loop',
                'title' => 'fechar loop: '.$first,
                'reason' => 'loop em aberto',
                'microaction' => 'pergunte: atlas chat "como fechar este loop: '.Str::limit($first, 60).'"',
                'command' => 'atlas chat',
            ];
        }

        $title = is_string($thread['title'] ?? null) ? trim((string) $thread['title']) : '';
        if ($title !== '' && $title !== 'sem titulo') {
            return [
                'kind' => 'thread_resume',
                'title' => $title,
                'reason' => 'thread mais recente sem objetivo claro',
                'microaction' => 'reabrir e revisar o que ficou em aberto',
                'command' => 'atlas chat',
            ];
        }

        return $this->freshAction();
    }

    /**
     * @param  array<string,mixed>|null  $state
     */
    private function microactionFromState(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }
        $next = $this->normalizeTextItems($state['next_steps'] ?? []);
        if ($next !== []) {
            return $next[0];
        }
        $topic = is_string($state['current_topic'] ?? null) ? trim((string) $state['current_topic']) : '';
        if ($topic !== '') {
            return 'voltar ao topico: '.Str::limit($topic, 80);
        }
        $position = is_string($state['user_position'] ?? null) ? trim((string) $state['user_position']) : '';
        if ($position !== '') {
            return 'retomar de: '.Str::limit($position, 80);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function freshAction(): array
    {
        return [
            'kind' => 'fresh',
            'title' => 'sem contexto recente neste workspace',
            'reason' => 'comeco limpo',
            'microaction' => 'descreva em uma frase o que voce quer fazer',
            'command' => 'atlas dev "<o que voce quer fazer hoje?>"',
        ];
    }

    /**
     * @param  mixed  $items
     * @return list<string>
     */
    private function normalizeTextItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (mixed $item): ?string {
                if (is_string($item)) {
                    $trimmed = trim($item);

                    return $trimmed === '' ? null : $trimmed;
                }
                if (is_array($item)) {
                    $text = (string) ($item['text'] ?? $item['value'] ?? '');
                    $trimmed = trim($text);

                    return $trimmed === '' ? null : $trimmed;
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function humanTime(?CarbonInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $diff = $time->diffInMinutes(now(), true);
        if ($diff < 5) {
            return 'agora';
        }
        if ($diff < 60) {
            return ((int) $diff).'m atras';
        }
        if ($diff < 60 * 24) {
            $hours = (int) round($diff / 60);

            return $hours.'h atras';
        }
        $days = (int) round($diff / (60 * 24));
        if ($days === 1) {
            return 'ontem '.$time->format('H:i');
        }
        if ($days < 7) {
            return $days.' dias atras';
        }

        return $time->format('d.m H:i');
    }
}
