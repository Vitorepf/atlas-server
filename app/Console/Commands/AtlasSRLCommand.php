<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\SRL\SRLEpisodeRepository;
use App\Services\Ai\Cognitive\SRL\SRLOrchestrator;
use App\Services\Ai\Cognitive\SRL\SRLPreferenceService;
use Illuminate\Console\Command;

class AtlasSRLCommand extends Command
{
    protected $signature = 'atlas:srl
        {action=status : on|off|status|start|observe|reflect|episode|history}
        {subject? : Episode id or target flow}
        {--domain=learning : Domain preference/history filter}
        {--days=30 : History window}
        {--objective= : Forethought objective}
        {--difficulty=3 : Expected difficulty 1..5}
        {--strategy=explore : Strategy chosen}
        {--duration=30 : Planned duration minutes}
        {--load=medium : Cognitive load}
        {--rating=3 : Self-rating 1..5}
        {--note= : Observation note}
        {--worked= : Reflection: what worked}
        {--didnt= : Reflection: what did not work}
        {--adjustment= : Reflection adjustment for next session}
        {--surprise= : Optional surprise}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage Atlas Self-Regulated Learning overlay episodes and preferences.';

    public function handle(
        SRLPreferenceService $preferences,
        SRLEpisodeRepository $episodes,
        SRLOrchestrator $orchestrator,
    ): int {
        $action = trim((string) $this->argument('action')) ?: 'status';
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $domain = $this->stringOption('domain') ?: 'learning';

        return match ($action) {
            'on' => $this->render(['status' => 'enabled', 'preference' => $preferences->set(true, $domain)]),
            'off' => $this->render(['status' => 'disabled', 'preference' => $preferences->set(false, $domain)]),
            'status' => $this->render([
                'schema_version' => 'atlas.cognitive.srl_cli.v1',
                'status' => 'ok',
                'mode' => 'status',
                'resolved' => $preferences->resolve($domain),
                'preferences' => $preferences->all(),
            ]),
            'start' => $this->render($orchestrator->beginIfEnabled($subject ?: 'learning.plan', [
                'domain' => $domain,
                'objective' => $this->stringOption('objective') ?: $subject,
                'expected_difficulty' => (int) $this->option('difficulty'),
                'strategy_chosen' => $this->stringOption('strategy') ?: 'explore',
                'planned_duration_min' => (int) $this->option('duration'),
            ])),
            'observe' => $this->render($episodes->observe((int) $subject, [
                'domain' => $domain,
                'cognitive_load' => $this->stringOption('load') ?: 'medium',
                'self_rating' => (int) $this->option('rating'),
                'note' => $this->stringOption('note') ?: '',
            ]), $subject !== '' ? self::SUCCESS : self::FAILURE),
            'reflect' => $this->render($episodes->reflect((int) $subject, [
                'domain' => $domain,
                'what_worked' => $this->stringOption('worked') ?: '',
                'what_didnt' => $this->stringOption('didnt') ?: '',
                'adjustment_for_next' => $this->stringOption('adjustment') ?: '',
                'surprise' => $this->stringOption('surprise') ?: '',
            ]), $subject !== '' ? self::SUCCESS : self::FAILURE),
            'episode' => $this->render([
                'schema_version' => 'atlas.cognitive.srl_cli.v1',
                'status' => 'ok',
                'mode' => 'episode',
                'episode' => $episodes->find((int) $subject),
            ], $subject !== '' ? self::SUCCESS : self::FAILURE),
            'history' => $this->render([
                'schema_version' => 'atlas.cognitive.srl_cli.v1',
                'status' => 'ok',
                'mode' => 'history',
                'episodes' => $episodes->history($domain, max(1, (int) $this->option('days'))),
            ]),
            default => $this->render(['status' => 'invalid_action'], self::FAILURE),
        };
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas SRL', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? data_get($payload, 'schema_version', 'n/a')));

        return $exit;
    }

    private function stringOption(string $key): ?string
    {
        $value = trim((string) ($this->option($key) ?? ''));

        return $value !== '' ? $value : null;
    }
}
