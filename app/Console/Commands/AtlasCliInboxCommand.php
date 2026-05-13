<?php

namespace App\Console\Commands;

use App\Console\Commands\Support\AtlasCliLimitInput;
use App\Http\Resources\AiInboxItemResource;
use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasCliInboxCommand extends Command
{
    protected $signature = 'atlas:cli:inbox
        {action=list : list, show, respond, dismiss or discuss}
        {id? : Inbox item id}
        {--action= : Action id for respond}
        {--reason=}
        {--filter=active : active, unread, read, all, approval, alert, completion, insight, proposal, job_result, self_diagnostic}
        {--severity= : debug, info, warning or critical}
        {--limit=50 : Number of items to list}
        {--cursor= : Cursor returned by a previous JSON list response}
        {--projection-hours= : Hours window for run_ledger_projection}
        {--projection-limit= : Max ledger events for run_ledger_projection}
        {--snoozed-until= : ISO-8601 timestamp for snooze actions}
        {--provider= : Provider key for provider cost-rate actions}
        {--model= : Model/runtime identifier for provider cost-rate actions}
        {--input-microusd= : Input price in micro-USD per 1K tokens for provider cost-rate actions}
        {--output-microusd= : Output price in micro-USD per 1K tokens for provider cost-rate actions}
        {--currency=USD : Currency code for provider cost-rate actions}
        {--effective-from= : Effective start datetime for provider cost-rate actions}
        {--effective-until= : Optional effective end datetime for provider cost-rate actions}
        {--dry-run : Preview run_ledger_projection without writing projection tables}
        {--json : Print machine-readable JSON}';

    protected $description = 'Read and act on the Atlas operational inbox.';

    private ?AtlasCliLimitInput $limits = null;

    public function __construct(?AtlasCliLimitInput $limits = null)
    {
        parent::__construct();

        $this->limits = $limits;
    }

    public function handle(AtlasInboxService $inbox, InboxActionRegistry $actions): int
    {
        if (! Schema::hasTable('ai_inbox_items')) {
            $this->error('Tabela ai_inbox_items ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        return match ($action) {
            'list' => $this->list($inbox),
            'show' => $this->show(),
            'dismiss' => $this->dismiss($inbox),
            'respond' => $this->respond($actions),
            'discuss' => $this->discuss($actions),
            default => $this->invalid($action),
        };
    }

    private function list(AtlasInboxService $inbox): int
    {
        $filter = (string) $this->option('filter');
        $status = in_array($filter, ['active', 'unread', 'read', 'actioned', 'resolved', 'dismissed', 'expired', 'snoozed', 'all'], true) ? $filter : 'all';
        $type = in_array($filter, AiInboxItem::TYPES, true) ? $filter : null;
        $severity = $this->severityOption();
        $page = $inbox->listPage(
            'vitor',
            $status,
            $type,
            $this->limitOption(),
            $severity,
            $this->stringOption('cursor'),
        );
        $items = $page['items'];
        $payload = AiInboxItemResource::collection($items)->resolve();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'items' => $payload,
                'next_cursor' => $page['next_cursor'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($items->isEmpty()) {
            $this->line('Inbox vazio para o filtro atual.');

            return self::SUCCESS;
        }

        $this->table(['id', 'type', 'severity', 'status', 'title', 'created'], collect($payload)->map(fn (array $row): array => [
            $row['id'],
            $row['type'],
            $row['severity'],
            $row['status'],
            Str::limit($row['title'], 70),
            $row['created_at'],
        ])->all());

        return self::SUCCESS;
    }

    private function show(): int
    {
        $item = $this->item();
        if (! $item) {
            return self::FAILURE;
        }

        $payload = (new AiInboxItemResource($item->load('contextBundle')))->resolve();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['item' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function dismiss(AtlasInboxService $inbox): int
    {
        $item = $this->item();
        if (! $item) {
            return self::FAILURE;
        }

        $updated = $inbox->dismiss($item, is_string($this->option('reason')) ? $this->option('reason') : null);

        return $this->printItem($updated);
    }

    private function respond(InboxActionRegistry $actions): int
    {
        $item = $this->item();
        if (! $item) {
            return self::FAILURE;
        }

        $action = $this->option('action');
        if (! is_string($action) || $action === '') {
            $this->error('Informe --action=<id>.');

            return self::FAILURE;
        }

        $result = $actions->handle($item, $action, [
            'reason' => is_string($this->option('reason')) ? $this->option('reason') : null,
            'projection_hours' => $this->option('projection-hours'),
            'projection_limit' => $this->option('projection-limit'),
            'snoozed_until' => $this->option('snoozed-until'),
            'provider' => $this->option('provider'),
            'model' => $this->option('model'),
            'input_microusd' => $this->option('input-microusd'),
            'output_microusd' => $this->option('output-microusd'),
            'currency' => $this->option('currency'),
            'effective_from' => $this->option('effective-from'),
            'effective_until' => $this->option('effective-until'),
            'dry_run' => (bool) $this->option('dry-run'),
        ], 'cli-'.$action.'-'.$item->id);

        return $this->printItem($result['item'], $result['result'] ?? []);
    }

    private function discuss(InboxActionRegistry $actions): int
    {
        $item = $this->item();
        if (! $item) {
            return self::FAILURE;
        }

        $result = $actions->handle($item, 'discuss', [], 'cli-discuss-'.$item->id);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Thread: '.($result['result']['thread_id'] ?? '-'));
            $this->line($result['result']['deep_link'] ?? '');
        }

        return self::SUCCESS;
    }

    private function item(): ?AiInboxItem
    {
        $id = $this->argument('id');
        if (! is_string($id) || $id === '') {
            $this->error('Informe o inbox item id.');

            return null;
        }

        $item = AiInboxItem::query()->find($id);
        if (! $item) {
            $this->error('Inbox item nao encontrado.');
        }

        return $item;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function printItem(AiInboxItem $item, array $result = []): int
    {
        $payload = (new AiInboxItemResource($item))->resolve();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'result' => $result,
                'item' => $payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Inbox item atualizado: '.$item->id.' ['.$item->status.']');
        }

        return self::SUCCESS;
    }

    private function limitOption(): int
    {
        return $this->cliLimits()->inboxLimit($this->option('limit'));
    }

    private function cliLimits(): AtlasCliLimitInput
    {
        return $this->limits ?? app(AtlasCliLimitInput::class);
    }

    private function severityOption(): ?string
    {
        $value = $this->stringOption('severity');

        return in_array($value, ['debug', 'info', 'warning', 'critical'], true) ? $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function invalid(string $action): int
    {
        $this->error("Acao invalida para atlas inbox: {$action}");

        return self::FAILURE;
    }
}
