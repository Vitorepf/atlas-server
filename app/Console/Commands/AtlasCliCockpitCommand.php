<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Services\Ai\SelfConstruction\AtlasTaskLandingReviewPublisher;
use App\Support\TerminalMarkdownRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * GAP-CLI-01 + GAP-COCKPIT-03 · the ONE terminal cockpit of the LIVE engine: answers
 * "o que o Atlas faz AGORA e o que precisa de mim" by COMPOSING the read-models that
 * already exist (brain:summary, task:health, autonomy:status, resolved receipts, inbox,
 * AiJob) — it builds no new state and writes nothing.
 *
 * Every section is fail-open and independent: a broken organ renders as "indisponível",
 * never kills the cockpit.
 */
class AtlasCliCockpitCommand extends Command
{
    protected $signature = 'atlas:cli:cockpit
        {--landings=8 : Quantas landings recentes do autônomo mostrar}
        {--json : Print machine-readable JSON}';

    protected $description = 'Cockpit único do motor vivo: cérebro + fila + landings recentes + review pendente + Dev/Forge.';

    public function handle(AtlasTaskLandingReviewPublisher $publisher, TerminalMarkdownRenderer $renderer): int
    {
        $payload = [
            'schema_version' => 'atlas.cli.cockpit.v1',
            'brain' => $this->section(fn (): array => $this->brain()),
            'task_queue' => $this->section(fn (): array => $this->taskHealth()),
            'autonomy' => $this->section(fn (): array => $this->autonomy()),
            'recent_landings' => $this->section(fn (): array => $this->landings($publisher)),
            'review_inbox' => $this->section(fn (): array => $this->reviewInbox()),
            'dev_jobs' => $this->section(fn (): array => $this->devJobs()),
            'forge_obra' => $this->section(fn (): array => $this->forgeObra()),
            'aaeos' => $this->section(fn (): array => $this->aaeos()),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->output->write($renderer->render($this->markdown($payload)));

        return self::SUCCESS;
    }

    /**
     * @param  callable():array<string,mixed>  $build
     * @return array<string,mixed>
     */

    /**
     * @return array<string,mixed>
     */
    private function aaeos(): array
    {
        $org = (new \App\Services\Ai\Aaeos\Control\AaeosOrgStateProjector)->project();
        $card = (new \App\Services\Ai\Aaeos\Control\AaeosScorecardProjector)->project();
        $world = (new \App\Services\Ai\Aaeos\Control\AaeosWorldSnapshotBuilder)->build()->toArray();

        return [
            'daily_port' => 'php artisan atlas:aaeos:run "<intent>"',
            'org_status' => $org['status'] ?? null,
            'executor_modes' => $org['executor_modes'] ?? [],
            'same_bar' => $org['same_bar'] ?? true,
            'composite' => $card['composite'] ?? null,
            'god_sota' => $card['god_sota'] ?? null,
            'aaeos_tree_pure' => $card['aaeos_tree']['pure'] ?? null,
            'queue_depth' => $world['queue_depth'] ?? 0,
            'world_source' => $world['world_source'] ?? null,
            'next' => 'php artisan atlas:aaeos:run --help',
        ];
    }

    private function section(callable $build): array
    {
        try {
            return ['ok' => true, ...$build()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function brain(): array
    {
        // brain:summary escreve fora do buffer do Artisan::call — shell-out fiel.
        $process = new \Symfony\Component\Process\Process(
            [PHP_BINARY, 'artisan', 'atlas:brain:summary', '--compact'],
            base_path(),
        );
        $process->setTimeout(30);
        $process->run();
        $line = trim($process->getOutput());

        return ['summary' => $line !== '' ? $line : 'sem sinal do cérebro'];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskHealth(): array
    {
        $decoded = $this->artisanJson('atlas:task:health');
        $interventions = (array) data_get($decoded, 'interventions.interventions', []);

        return [
            'healthy' => $decoded['healthy'] ?? null,
            'counts' => [
                'servable_now' => $decoded['servable_now'] ?? null,
                'claimable_depth' => $decoded['claimable_depth'] ?? null,
                'active_leases' => $decoded['active_leases'] ?? null,
                'quarantined' => $decoded['quarantined_count'] ?? null,
                'status' => $decoded['queue_status_distribution'] ?? null,
            ],
            'interventions' => array_slice($interventions, 0, 3),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function autonomy(): array
    {
        $decoded = $this->artisanJson('atlas:autonomy:status');
        $areas = (array) ($decoded['areas'] ?? []);
        $active = array_keys(array_filter($areas, static fn (mixed $a): bool => is_array($a)
            ? (bool) ($a['autonomy_tier_active'] ?? false)
            : (bool) $a));

        return ['areas_total' => count($areas), 'areas_active' => $active];
    }

    /**
     * @return array<string,mixed>
     */
    private function landings(AtlasTaskLandingReviewPublisher $publisher): array
    {
        $landings = array_map(static fn (array $r): array => [
            'task_packet_id' => $r['task_packet_id'] ?? null,
            'sha' => substr((string) ($r['commit_sha'] ?? ''), 0, 10),
            'agent_id' => $r['agent_id'] ?? null,
            'resolved_at' => $r['resolved_at'] ?? null,
            'objective' => $r['objective_excerpt'] ?? null,
        ], $publisher->recentLandings(max(1, (int) $this->option('landings'))));

        return ['landings' => $landings];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewInbox(): array
    {
        $pending = AiInboxItem::query()
            ->whereIn('status', ['unread', 'read'])
            ->whereIn('category', ['task_landing_review', 'loop_operator_review'])
            ->count();
        $jobResults = AiInboxItem::query()
            ->where('type', 'job_result')
            ->whereIn('status', ['unread', 'read'])
            ->count();

        // O5: next-step EXECUTÁVEL por estado — do cockpit ao veredito em ≤2 comandos.
        $next = match (true) {
            $pending > 0 => 'php artisan atlas:task:review:decide <sha|task_id> [--reject] (lote OK; detalhes: atlas:cli:inbox show <id>)',
            $jobResults > 0 => 'php artisan atlas:cli:inbox list',
            default => 'php artisan atlas:task:review:publish',
        };

        return [
            'landing_reviews_pending' => $pending,
            'job_results_pending' => $jobResults,
            'next' => $next,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function devJobs(): array
    {
        $jobs = AiJob::query()
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'kind', 'status', 'provider', 'agent_slug', 'updated_at'])
            ->map(static fn (AiJob $j): array => [
                'kind' => $j->kind,
                'status' => $j->status,
                'provider' => $j->provider,
                'agent' => $j->agent_slug,
                'updated_at' => $j->updated_at?->toIso8601String(),
            ])
            ->all();

        return ['recent' => $jobs];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeObra(): array
    {
        $decoded = $this->artisanJson('atlas:code:obra-command-center');

        return [
            'obra_present' => (bool) ($decoded['obra_present'] ?? false),
            'obra_title' => $decoded['obra_title'] ?? null,
            'status' => $decoded['human_status_label'] ?? $decoded['status'] ?? null,
            'current_phase' => $decoded['current_phase'] ?? null,
            'next_safe_action' => $decoded['next_safe_action'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artisanJson(string $command): array
    {
        Artisan::call($command, ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $p
     */
    private function markdown(array $p): string
    {
        $md = "# Atlas Cockpit — motor vivo\n\n";

        $md .= "## Cérebro\n";
        $md .= $this->renderSection($p['brain'], fn (array $s): string => '`'.$s['summary'].'`');

        $md .= "\n## Fila de tasks\n";
        $md .= $this->renderSection($p['task_queue'], function (array $s): string {
            $counts = is_array($s['counts'] ?? null) ? json_encode($s['counts']) : 'sem contadores';
            $out = 'healthy: **'.var_export($s['healthy'], true).'** · '.$counts;
            foreach ((array) $s['interventions'] as $i) {
                $out .= "\n- intervenção: ".(is_array($i) ? (string) ($i['summary'] ?? json_encode($i)) : (string) $i);
            }

            return $out;
        });

        $md .= "\n## Autonomia\n";
        $md .= $this->renderSection($p['autonomy'], fn (array $s): string => sprintf(
            '%d áreas · ativas: %s',
            (int) $s['areas_total'],
            $s['areas_active'] === [] ? 'nenhuma' : implode(', ', $s['areas_active']),
        ));

        $md .= "\n## Landings recentes do autônomo\n";
        $md .= $this->renderSection($p['recent_landings'], function (array $s): string {
            if ((array) $s['landings'] === []) {
                return 'nenhuma landing registrada';
            }
            $out = '';
            foreach ((array) $s['landings'] as $l) {
                $out .= sprintf(
                    "- `%s` %s · %s — %s\n",
                    (string) ($l['sha'] ?? ''),
                    (string) ($l['task_packet_id'] ?? ''),
                    (string) ($l['agent_id'] ?? ''),
                    (string) ($l['objective'] ?? ''),
                );
            }

            return rtrim($out);
        });

        $md .= "\n## Review pendente\n";
        $md .= $this->renderSection($p['review_inbox'], fn (array $s): string => sprintf(
            "landings aguardando veredito: **%d** · job_results pendentes: **%d**\npróximo passo: `%s`",
            (int) $s['landing_reviews_pending'],
            (int) $s['job_results_pending'],
            (string) $s['next'],
        ));

        $md .= "\n## AAEOS (porta diária)\n";
        $md .= $this->renderSection($p['aaeos'] ?? ['ok' => false], fn (array $s): string => sprintf(
            "porta: `%s`\ncomposite: **%s** · tree_pure: **%s** · queue_depth: **%s**\npróximo: `%s`",
            (string) ($s['daily_port'] ?? ''),
            (string) ($s['composite'] ?? '?'),
            var_export($s['aaeos_tree_pure'] ?? null, true),
            (string) ($s['queue_depth'] ?? 0),
            (string) ($s['next'] ?? ''),
        ));

        $md .= "\n## Dev (jobs recentes)\n";
        $md .= $this->renderSection($p['dev_jobs'], function (array $s): string {
            if ((array) $s['recent'] === []) {
                return 'nenhum job recente';
            }
            $out = '';
            foreach ((array) $s['recent'] as $j) {
                $out .= sprintf(
                    "- %s · **%s** · %s/%s · %s\n",
                    (string) ($j['kind'] ?? ''),
                    (string) ($j['status'] ?? ''),
                    (string) ($j['provider'] ?? ''),
                    (string) ($j['agent'] ?? ''),
                    (string) ($j['updated_at'] ?? ''),
                );
            }

            return rtrim($out);
        });

        $md .= "\n## Forge (obra ativa)\n";
        $md .= $this->renderSection($p['forge_obra'], function (array $s): string {
            if (! (bool) $s['obra_present']) {
                return 'nenhuma obra ativa';
            }

            return sprintf(
                '**%s** · %s · fase: %s · próximo passo seguro: %s',
                (string) ($s['obra_title'] ?? ''),
                (string) ($s['status'] ?? ''),
                (string) ($s['current_phase'] ?? ''),
                is_array($s['next_safe_action']) ? json_encode($s['next_safe_action']) : (string) ($s['next_safe_action'] ?? ''),
            );
        });

        return $md."\n";
    }

    /**
     * @param  array<string,mixed>  $section
     * @param  callable(array<string,mixed>):string  $render
     */
    private function renderSection(array $section, callable $render): string
    {
        if (($section['ok'] ?? false) !== true) {
            return '_indisponível: '.(string) ($section['error'] ?? 'erro desconhecido')."_\n";
        }

        return $render($section)."\n";
    }
}
