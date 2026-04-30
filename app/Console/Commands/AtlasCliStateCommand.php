<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasCliStateCommand extends Command
{
    protected $signature = 'atlas:cli:state
        {action=show : show, set, objective, phase, topic, note, next, decision, open-loop, pending-steer, compact or handoff}
        {value?* : Value for shortcut actions}
        {--workspace= : Workspace path. Defaults to current directory}
        {--thread= : Atlas CLI thread id}
        {--objective= : Set session objective}
        {--phase= : Set current phase}
        {--topic= : Set current topic}
        {--position= : Set current operator position}
        {--decision=* : Add preserved decision}
        {--open-loop=* : Add open loop}
        {--next-step=* : Add next step}
        {--artifact=* : Add relevant artifact}
        {--constraint=* : Add constraint}
        {--note=* : Add operator note}
        {--provider= : Provider target for handoff}
        {--no-compact : Do not compact before handoff}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and update Atlas CLI long-session state.';

    public function handle(AtlasCliSessionService $sessions): int
    {
        $workspace = $this->workspace();
        $threadId = is_string($this->option('thread')) ? $this->option('thread') : null;
        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();
        $value = trim(implode(' ', (array) $this->argument('value')));

        try {
            $snapshot = match ($action) {
                'show', 'state' => $sessions->snapshot($workspace, $threadId),
                'set', 'update' => $sessions->update($workspace, $threadId, $this->changes()),
                'objective' => $sessions->update($workspace, $threadId, ['objective' => $value]),
                'phase' => $sessions->update($workspace, $threadId, ['phase' => $value]),
                'topic' => $sessions->update($workspace, $threadId, ['topic' => $value]),
                'position' => $sessions->update($workspace, $threadId, ['position' => $value]),
                'note' => $sessions->update($workspace, $threadId, ['notes' => [$value]]),
                'next' => $sessions->update($workspace, $threadId, ['next_steps' => [$value]]),
                'decision' => $sessions->update($workspace, $threadId, ['decisions' => [$value]]),
                'open-loop', 'loop' => $sessions->update($workspace, $threadId, ['open_loops' => [$value]]),
                'pending-steer', 'steer' => $this->pendingSteerAction($sessions, $workspace, $threadId, $value),
                'compact' => $sessions->compact($workspace, $threadId),
                'handoff' => $sessions->handoff($workspace, $threadId, $this->handoffProvider($value), ! (bool) $this->option('no-compact')),
                default => throw new \InvalidArgumentException("Acao invalida: {$action}."),
            };
        } catch (\Throwable $exception) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_merge(['ok' => true], $snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($snapshot);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function changes(): array
    {
        return [
            'objective' => $this->option('objective'),
            'phase' => $this->option('phase'),
            'topic' => $this->option('topic'),
            'position' => $this->option('position'),
            'decisions' => $this->option('decision'),
            'open_loops' => $this->option('open-loop'),
            'next_steps' => $this->option('next-step'),
            'artifacts' => $this->option('artifact'),
            'constraints' => $this->option('constraint'),
            'notes' => $this->option('note'),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function render(array $snapshot): void
    {
        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Atlas CLI State</>');
        $this->components->twoColumnDetail('Workspace', (string) ($snapshot['workspace'] ?? '-'));

        $thread = is_array($snapshot['thread'] ?? null) ? $snapshot['thread'] : null;
        if (! $thread) {
            $this->line('<fg=gray>Nenhuma thread Atlas CLI ativa neste workspace.</>');

            return;
        }

        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];
        $session = is_array($snapshot['session'] ?? null) ? $snapshot['session'] : null;

        $this->components->twoColumnDetail('Thread', $this->short((string) $thread['id']).' - '.(string) ($thread['title'] ?: 'sem titulo'));
        $this->components->twoColumnDetail('Session', $session ? $this->short((string) $session['id']).' '.$session['status'] : '-');
        $this->components->twoColumnDetail('Provider', (string) ($thread['provider'] ?: data_get($session, 'provider_last', '-')));
        $this->components->twoColumnDetail('State version', (string) ($state['version'] ?? '-'));
        $this->components->twoColumnDetail('Objective', (string) ($state['objective'] ?? '-'));
        $this->components->twoColumnDetail('Phase', (string) ($state['current_phase'] ?? '-'));
        $this->components->twoColumnDetail('Topic', (string) ($state['current_topic'] ?? '-'));
        if (is_string($state['pending_steer'] ?? null) && trim((string) $state['pending_steer']) !== '') {
            $this->components->twoColumnDetail('Pending steer', Str::limit((string) $state['pending_steer'], 120));
        }

        $this->listItems('Decisoes', (array) ($state['decisions'] ?? []));
        $this->listItems('Open loops', (array) ($state['open_loops'] ?? []));
        $this->listItems('Proximos passos', (array) ($state['next_steps'] ?? []));
        $this->listItems('Notas', (array) ($state['operator_notes'] ?? []));

        if (is_array($snapshot['created_compaction'] ?? null)) {
            $this->newLine();
            $this->components->twoColumnDetail('Compaction criada', $this->short((string) data_get($snapshot, 'created_compaction.id')));
        }

        if (is_array($snapshot['created_provider_handoff'] ?? null)) {
            $this->components->twoColumnDetail('Handoff criado', $this->short((string) data_get($snapshot, 'created_provider_handoff.id')).' -> '.data_get($snapshot, 'created_provider_handoff.to_provider'));
        }
    }

    /**
     * @param  array<int,mixed>  $items
     */
    private function listItems(string $label, array $items): void
    {
        $texts = collect($items)
            ->map(fn (mixed $item): ?string => is_array($item) ? (string) ($item['text'] ?? $item['value'] ?? '') : null)
            ->filter()
            ->take(8)
            ->values();

        if ($texts->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<fg=bright-blue>'.$label.'</>');
        foreach ($texts as $text) {
            $this->line('  - '.Str::limit($text, 140));
        }
    }

    private function handoffProvider(string $value): string
    {
        $provider = (string) ($this->option('provider') ?: $value);
        $provider = Str::of($provider)->lower()->trim()->value();

        return match ($provider) {
            'claude' => 'claude_cli',
            'codex' => 'codex_cli',
            default => $provider,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingSteerAction(AtlasCliSessionService $sessions, string $workspace, ?string $threadId, string $value): array
    {
        $value = trim($value);
        if ($value === '' || Str::of($value)->lower()->trim()->value() === 'status') {
            return $sessions->snapshot($workspace, $threadId);
        }

        $normalized = Str::of($value)->lower()->trim()->value();
        if (in_array($normalized, ['clear', 'limpar', 'reset'], true)) {
            return $sessions->clearPendingSteer($workspace, $threadId);
        }

        if (str_starts_with($normalized, 'set ')) {
            $value = trim(substr($value, 4));
        }

        if ($value === '') {
            throw new \InvalidArgumentException('Uso: atlas state pending-steer set "mensagem".');
        }

        return $sessions->setPendingSteer($workspace, $threadId, $value);
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function short(string $id): string
    {
        return substr($id, 0, 8);
    }
}
