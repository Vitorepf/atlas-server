<?php

namespace App\Console\Commands;

use App\Models\OperatorSkillProposal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * The operator's gate over auto-built skills — and the ONLY writer of the live skill vault.
 * Atlas generates + stages skills in a sandbox (OperatorSkillProposalBridge); nothing
 * reaches the live vault until the operator runs `approve <slug> --confirm` here. This is
 * what makes "Atlas builds its own skills" safe: the build is autonomous, the PROMOTION is
 * always an explicit human act, and it is reversible.
 */
class AtlasOperatorSkillCommand extends Command
{
    protected $signature = 'atlas:ai:operator-skill
        {action : list|show|approve|reject}
        {ref? : skill slug or proposal id}
        {--confirm : required to actually promote into the live vault}
        {--json : machine-readable output}';

    protected $description = 'Review and promote (or reject) skills Atlas auto-built from your recurring patterns. Promotion needs --confirm.';

    public function handle(): int
    {
        if (! DatabaseTableAvailability::has('operator_skill_proposals')) {
            $this->warn('operator_skill_proposals table unavailable.');

            return self::SUCCESS;
        }

        return match (strtolower((string) $this->argument('action'))) {
            'list' => $this->list(),
            'show' => $this->show(),
            'approve' => $this->approve(),
            'reject' => $this->reject(),
            default => $this->failWith('Unknown action (use list|show|approve|reject).'),
        };
    }

    private function list(): int
    {
        $staged = OperatorSkillProposal::query()
            ->where('status', OperatorSkillProposal::STATUS_STAGED)
            ->orderByDesc('confidence')->limit(100)->get();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($staged->map(fn (OperatorSkillProposal $p): array => [
                'id' => $p->id, 'slug' => $p->slug, 'title' => $p->title, 'confidence' => $p->confidence,
            ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($staged->count().' skill(s) Atlas built and staged for your review:');
        if ($staged->isNotEmpty()) {
            $this->table(['slug', 'title', 'conf'], $staged->map(fn (OperatorSkillProposal $p): array => [
                $p->slug, \Illuminate\Support\Str::limit((string) $p->title, 56), (string) round((float) $p->confidence, 2),
            ])->all());
            $this->line('Inspect: php artisan atlas:ai:operator-skill show <slug>   ·   Promote: ... approve <slug> --confirm');
        }

        return self::SUCCESS;
    }

    private function show(): int
    {
        $proposal = $this->resolve();
        if ($proposal === null) {
            return self::FAILURE;
        }
        $content = File::exists($proposal->staging_path) ? File::get($proposal->staging_path) : '(staging file missing)';
        $this->info('Staged skill: '.$proposal->slug.'  ['.$proposal->status.']');
        $this->line($content);

        return self::SUCCESS;
    }

    private function approve(): int
    {
        $proposal = $this->resolve();
        if ($proposal === null) {
            return self::FAILURE;
        }
        if ($proposal->status !== OperatorSkillProposal::STATUS_STAGED) {
            return $this->failWith('Skill is not in staged state (current: '.$proposal->status.').');
        }
        if (! File::exists($proposal->staging_path)) {
            return $this->failWith('Staged skill file is missing — nothing to promote.');
        }
        if (! (bool) $this->option('confirm')) {
            $this->warn('This will write "'.$proposal->slug.'" into the LIVE skill vault. Re-run with --confirm to proceed.');

            return self::SUCCESS;
        }

        // The ONLY live-vault write in the whole feature — explicit, operator-gated.
        $vaultPath = storage_path('app/atlas/vault/_skills/'.$proposal->slug.'/SKILL.md');
        File::ensureDirectoryExists(dirname($vaultPath));
        File::put($vaultPath, (string) File::get($proposal->staging_path));

        $proposal->forceFill([
            'status' => OperatorSkillProposal::STATUS_PROMOTED,
            'promoted_path' => $vaultPath,
            'promoted_at' => Carbon::now(),
        ])->save();

        $this->info('Promoted "'.$proposal->slug.'" into the live vault: '.$vaultPath);
        $this->line('Reversible — delete that file (or re-stage) to remove the skill.');

        return self::SUCCESS;
    }

    private function reject(): int
    {
        $proposal = $this->resolve();
        if ($proposal === null) {
            return self::FAILURE;
        }
        if (File::exists($proposal->staging_path)) {
            File::deleteDirectory(dirname($proposal->staging_path));
        }
        $proposal->forceFill(['status' => OperatorSkillProposal::STATUS_REJECTED])->save();
        $this->info('Rejected + removed staged skill "'.$proposal->slug.'".');

        return self::SUCCESS;
    }

    private function resolve(): ?OperatorSkillProposal
    {
        $ref = (string) $this->argument('ref');
        if (trim($ref) === '') {
            $this->failWith('A skill slug or proposal id is required.');

            return null;
        }
        // Resolve by slug; only try the uuid `id` column when $ref is actually a uuid
        // (a slug compared to a uuid column is a Postgres type error).
        $proposal = OperatorSkillProposal::query()->where('slug', $ref)->latest()->first();
        if ($proposal === null && preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $ref) === 1) {
            $proposal = OperatorSkillProposal::query()->find($ref);
        }
        if ($proposal === null) {
            $this->failWith('Skill proposal not found: '.$ref);
        }

        return $proposal;
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'error' => $message]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
