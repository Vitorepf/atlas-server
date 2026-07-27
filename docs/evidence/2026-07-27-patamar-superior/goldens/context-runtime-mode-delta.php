<?php

/**
 * Probe: does AtlasContextRuntime's `unified` retrieval path actually work?
 *
 * The fusion is written but default-OFF (atlas.context_runtime.unified_retrieval_enabled
 * = false), so every caller today gets retrieval_core_status=legacy and $fused is
 * never built. This forces shadow + default and reports what comes out.
 *
 * Isolation: storage is redirected to a temp dir BEFORE any service resolves, so
 * pack cache / delivered-pack ledger / session working set cannot touch live state.
 */
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';

$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$tmp = sys_get_temp_dir().'/atlas-unified-probe-'.getmypid();
@mkdir($tmp.'/atlas/aobg', 0777, true);
$app->useStoragePath($tmp);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->useStoragePath($tmp);

config([
    'atlas.aobg.delivered_pack_ledger.path' => $tmp.'/atlas/aobg/delivered-pack-ledger.jsonl',
]);

use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\ValueObjects\AiTaskRequest;

$input = 'Refatorar o compositor de contexto do Atlas para um bloco unificado.';
$payload = ['atlas_workflow_mode' => 'programming.dev', 'routing_task' => 'programming.dev', 'task_type' => 'programming.dev', 'programming_flow' => 'programming.dev'];
$task = AiTaskRequest::fromInput($input, ['agent_slug' => 'orquestrador', 'payload' => $payload], ['agent' => 'orquestrador', 'intent' => 'programming']);
$options = ['workspace' => '/Users/vitorepf/develop/Atlas/atlas-server', 'flow_id' => 'probe-unified', 'payload' => $payload, 'open_brain' => ['mode' => 'auto']];


$mode = $argv[1] ?? 'legacy';
$cfgs = [
    'legacy'  => ['atlas.context_runtime.unified_retrieval_enabled' => false],
    'shadow'  => ['atlas.context_runtime.unified_retrieval_enabled' => true, 'atlas.context_runtime.unified_retrieval_mode' => 'shadow'],
    'unified' => ['atlas.context_runtime.unified_retrieval_enabled' => true, 'atlas.context_runtime.unified_retrieval_mode' => 'default'],
];
config($cfgs[$mode]);
$t = microtime(true);
$c = app(AtlasContextRuntime::class)->compose($input, $task, $options);
$ms = (int) round((microtime(true) - $t) * 1000);
$a = $c->pack;
file_put_contents("/tmp/pack-$mode.json", json_encode($a['context_pack'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents("/tmp/inj-$mode.json", json_encode($a['open_brain_injection'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$inj = $a['open_brain_injection'] ?? [];
printf("%-8s %sms status=%-8s inj=%s/%s refs=%s prompt=%s\n", $mode, $ms, $a['retrieval_core_status'] ?? '?', $inj['status'] ?? '?', $inj['reason'] ?? '-', count($inj['context_refs'] ?? []), strlen((string)($inj['prompt_section'] ?? '')));
