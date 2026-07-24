<?php

namespace App\Console\Commands;

use App\Models\OperatorProfileItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * The operator's undo handle for everything Atlas learned about them — list what is
 * active, and reversibly archive (or restore) any single profile item. This is what
 * makes Phase-2 auto-apply safe to leave ON: nothing Atlas learns is permanent; one
 * command removes any item from the live context-injection, non-destructively.
 */
class AtlasOperatorProfileCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:operator-profile
        {action : list|archive|restore}
        {id? : profile item id (for archive/restore)}
        {--operator= : operator id (default from config)}
        {--limit=50 : max items to list}
        {--json : machine-readable output}';

    protected $description = 'Inspect and reversibly archive/restore what Atlas learned about you (operator profile items).';

    public function handle(): int
    {
        if (! DatabaseTableAvailability::has('operator_profile_items')) {
            $this->warn('operator_profile_items table unavailable.');

            return self::SUCCESS;
        }

        $action = strtolower((string) $this->argument('action'));
        $operatorId = (string) ($this->option('operator') ?: config('atlas_operator_intelligence.default_operator_id', 'default'));

        return match ($action) {
            'list' => $this->list($operatorId),
            'archive' => $this->transition('archive', OperatorProfileItem::STATUS_ARCHIVED),
            'restore' => $this->transition('restore', OperatorProfileItem::STATUS_ACTIVE),
            default => $this->failWith('Unknown action: '.$action.' (use list|archive|restore)'),
        };
    }

    private function list(string $operatorId): int
    {
        $items = OperatorProfileItem::query()
            ->where('operator_id', $operatorId)
            ->whereIn('status', [OperatorProfileItem::STATUS_ACTIVE, OperatorProfileItem::STATUS_PAUSED])
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();

        $rows = $items->map(fn (OperatorProfileItem $i): array => [
            'id' => (string) $i->id,
            'taxonomy_item_id' => (string) $i->taxonomy_item_id,
            'summary' => $i->privacy_class === 'normal' ? (string) $i->summary : '['.$i->privacy_class.' — redacted]',
            'confidence' => (string) $i->confidence,
            'automation_level' => (string) $i->automation_level,
            'scope' => $i->scope_type.($i->scope_id ? ':'.$i->scope_id : ''),
        ])->all();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['operator_id' => $operatorId, 'count' => count($rows), 'items' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Atlas learned %d active profile item(s) about operator "%s":', count($rows), $operatorId));
        if ($rows !== []) {
            $this->table(['id', 'taxonomy', 'summary', 'conf', 'automation', 'scope'],
                array_map(fn (array $r): array => [
                    substr($r['id'], 0, 8), $r['taxonomy_item_id'], \Illuminate\Support\Str::limit($r['summary'], 50),
                    $r['confidence'], $r['automation_level'], $r['scope'],
                ], $rows));
            $this->line('Undo any item:  php artisan atlas:ai:operator-profile archive <id>   (restore: ... restore <id>)');
        }

        return self::SUCCESS;
    }

    private function transition(string $verb, string $toStatus): int
    {
        $id = (string) $this->argument('id');
        if (trim($id) === '') {
            return $this->failWith('An item id is required for '.$verb.'.');
        }

        $item = OperatorProfileItem::query()->find($id);
        if ($item === null) {
            return $this->failWith('Profile item not found: '.$id);
        }

        $before = (string) $item->status;
        $item->forceFill([
            'status' => $toStatus,
            'value' => array_merge((array) $item->value, [
                'last_reversal' => ['verb' => $verb, 'from' => $before, 'to' => $toStatus, 'at' => Carbon::now()->toIso8601String()],
            ]),
        ])->save();

        $payload = ['ok' => true, 'action' => $verb, 'id' => $id, 'from' => $before, 'to' => $toStatus, 'reversible' => true];
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->info(sprintf('%s profile item %s: %s → %s (reversible).', ucfirst($verb), substr($id, 0, 8), $before, $toStatus));
        if ($verb === 'archive') {
            $this->line('It will no longer be injected into Atlas\'s context. Restore: php artisan atlas:ai:operator-profile restore '.$id);
        }

        return self::SUCCESS;
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
