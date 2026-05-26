<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasPatamar4StateService;
use Illuminate\Console\Command;

class AtlasPatamar4StatusCommand extends Command
{
    protected $signature = 'atlas:patamar4:status
        {--tail=5 : how many recent items per section}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Patamar 4 — read-only operator view of the live autonomous loop (kernel · admission · CFA · reconciliation · TEOS-I4 · swarm · TDC + integration layer).';

    public function handle(AtlasPatamar4StateService $svc): int
    {
        $tail = max(1, min(50, (int) $this->option('tail')));
        $json = (bool) $this->option('json');

        $state = $svc->snapshot($tail);

        if ($json) {
            $this->line((string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('Atlas Patamar 4 — Live Status');
        $this->line(str_repeat('-', 60));
        $this->line('Generated at: '.$state['generated_at']);
        $this->line('Kernel hash:  '.$state['kernel']['kernel_hash']);
        $this->line('');
        $this->line('Cognition:');
        $this->line('  subsystems          : '.$state['cognitive_function_atlas']['subsystem_count']);
        $this->line('  groups              : '.$state['cognitive_function_atlas']['group_count']);
        $this->line('');
        $this->line('Reconciliation:');
        $this->line('  tick_count          : '.$state['reconciliation']['summary']['tick_count']);
        foreach ($state['reconciliation']['summary']['outcomes'] as $o => $n) {
            $this->line("    {$o}: {$n}");
        }
        $this->line('');
        $this->line('Kernel:');
        $this->line('  violations          : '.$state['kernel']['violation_count']);
        $this->line('Admission tickets    : '.$state['autonomy_admission']['ticket_count']);
        $this->line('TEOS-I4 trees        : '.$state['teos_i4']['tree_count']);
        $this->line('Swarm dispatches     : '.$state['swarm']['dispatch_count']);
        $this->line('TDC capsules active  : '.$state['temporary_domain']['active_capsule_count'].' (total '.$state['temporary_domain']['total_capsule_count'].')');
        $this->line('Gateway consults     : '.$state['gateway_consultations']['count']);
        $this->line('');
        $this->line('Antifragility:');
        $this->line('  wrapper_multiplier_m : '.$state['antifragility']['wrapper_multiplier_m']);
        foreach ($state['antifragility']['components'] as $k => $v) {
            $this->line("    {$k}: {$v}");
        }
        $this->line('');
        $this->line('claim_policy:');
        foreach ($state['claim_policy'] as $k => $v) {
            $this->line("  {$k}: ".(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
        }

        return self::SUCCESS;
    }
}
