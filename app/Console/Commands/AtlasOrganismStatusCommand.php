<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Organism\AtlasOrganismMissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AOBG N4.F3 — `atlas:organism:status`: inspect a commissioned cross-domain mission
 * (READ ONLY, cost-free).
 *
 * Shows the cross-domain proposal plan: each node's routed domain, its per-domain HONEST
 * validation (win-rate never appears), the ARPTL veto outcome for blocked crossings, and the
 * 'requires_operator' actuation gate for every node — WITHOUT spending or executing anything.
 * With no --mission it lists recent missions so the operator can find an id.
 *
 *     atlas:organism:status                          # list recent missions
 *     atlas:organism:status --mission=orgm-abc123    # the cross-domain proposal plan
 *     atlas:organism:status --mission=orgm-abc123 --json
 *
 * Local DB only — ZERO provider spend, no real-world action (it is PROPOSE-ONLY by construction).
 */
class AtlasOrganismStatusCommand extends Command
{
    public const SCHEMA = 'atlas.organism.status_command.v1';

    protected $signature = 'atlas:organism:status
        {--mission= : the mission id to inspect (from atlas:organism:commission). Omit to list recent missions}
        {--limit= : when listing, the max missions to show (default 20)}
        {--json : machine-readable output}';

    protected $description = 'AOBG N4.F3: inspect a cross-domain mission — routed domains, per-domain honest validation, ARPTL vetoes, and the requires_operator gate (READ ONLY, cost-free).';

    public function handle(AtlasOrganismMissionService $service): int
    {
        $missionId = $this->stringOption('mission');

        if ($missionId === null || $missionId === '') {
            return $this->renderList();
        }

        $status = $service->status($missionId);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($status ?? ['found' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $status === null ? self::FAILURE : self::SUCCESS;
        }

        if ($status === null) {
            $this->error('organism mission not found: '.$missionId);

            return self::FAILURE;
        }

        $this->render($status);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $status
     */
    private function render(array $status): void
    {
        $this->info(sprintf(
            'atlas:organism:status  mission=%s  anchor=%s  status=%s  nodes=%d  domains=[%s]',
            (string) ($status['mission_id'] ?? ''),
            (string) ($status['anchor_domain'] ?? ''),
            (string) ($status['status'] ?? ''),
            (int) ($status['node_count'] ?? 0),
            implode(', ', array_map('strval', (array) ($status['domains_spanned'] ?? []))),
        ));
        $this->line('');

        foreach ((array) ($status['nodes'] ?? []) as $node) {
            $validation = (array) ($node['validation'] ?? []);
            $sensitive = (bool) ($node['sensitive'] ?? false) ? ' [sensitive · on-machine]' : '';
            $this->line(sprintf(
                '  [%d] %s  → domain=%s%s  outcome=%s',
                (int) ($node['seq'] ?? 0),
                (string) ($node['title'] ?? ''),
                (string) ($node['domain'] ?? ''),
                $sensitive,
                (string) ($node['outcome'] ?? ''),
            ));
            if (($node['outcome'] ?? '') === AtlasOrganismMissionService::OUTCOME_VETOED) {
                $this->line('      VETOED (ARPTL): '.(string) ($node['veto_reason'] ?? ''));
            } else {
                $this->line(sprintf(
                    '      metric=%s value=%s passed=%s  method=%s',
                    (string) ($validation['metric'] ?? '?'),
                    $validation['value'] === null ? 'null' : (string) ($validation['value'] ?? 'null'),
                    ($validation['passed'] ?? false) ? 'yes' : 'no',
                    (string) ($validation['method'] ?? ''),
                ));
            }
            $this->line('      actuation: '.(string) ($node['actuation_gate'] ?? 'requires_operator'));
            $this->line('');
        }

        $this->comment('PROPOSE-ONLY ('.(string) ($status['ceiling'] ?? '').'): every node requires the operator to act. Atlas records the decision, never executes it.');
    }

    private function renderList(): int
    {
        $limit = $this->stringOption('limit');
        $max = ($limit !== null && is_numeric($limit)) ? max(1, (int) $limit) : 20;

        $rows = [];
        try {
            if (Schema::hasTable('atlas_organism_missions')) {
                $rows = DB::table('atlas_organism_missions')
                    ->orderByDesc('updated_at')
                    ->limit($max)
                    ->get(['id', 'anchor_domain', 'status', 'intent', 'updated_at'])
                    ->all();
            }
        } catch (Throwable) {
            $rows = [];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['missions' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->comment('No organism missions yet. Commission one: atlas:organism:commission "<intent that spans domains>"');

            return self::SUCCESS;
        }

        $this->info('Recent organism missions (READ ONLY):');
        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %s  anchor=%s  status=%s  %s',
                (string) $row->id,
                (string) $row->anchor_domain,
                (string) $row->status,
                mb_substr((string) $row->intent, 0, 80),
            ));
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $val = $this->option($name);

        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }
}
