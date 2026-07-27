<?php

declare(strict_types=1);

// Golden capture (standalone — never through phpunit) for the two pure methods
// duplicated between ProgrammingWorkItemSpecPlanService and
// AtlasCodeProgrammingWorkItemController.

$root = '/Users/vitorepf/develop/Atlas/atlas-server';
require $root.'/tests/bootstrap.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;

$workItem = new AtlasProgrammingWorkItem;
$workItem->forceFill([
    'id' => 'wi-golden-1',
    'code' => 'WI-07',
    'risk_level' => 'high',
    'owner' => 'atlas-code',
    'intent_text' => 'golden intent',
    'workspace' => '/tmp/ws-golden',
    'metadata_json' => ['obra_id' => 'proj-golden-1'],
]);

$project = new AtlasProject;
$project->forceFill([
    'id' => 'proj-golden-1',
    'metadata' => [
        'programming_work_item_code' => 'WI-07',
        'workspace_path' => '/tmp/ws-golden',
    ],
]);

$otherProject = new AtlasProject;
$otherProject->forceFill(['id' => 'proj-other', 'metadata' => []]);

$compiledTasks = [
    ['code' => 'T-1', 'title' => 'impl', 'type' => 'impl', 'allowed_files' => ['app/A.php', 'docs/a.md'],
        'forbidden_files' => ['routes/api.php'], 'depends_on' => [], 'order_index' => 1],
    ['code' => 'T-2', 'title' => 'test', 'type' => 'test', 'allowed_files' => ['tests/AT.php'],
        'depends_on' => ['T-1'], 'metadata' => ['commands' => ['phpunit tests/AT.php']], 'order_index' => 2],
    ['title' => 'no code', 'type' => 'impl', 'allowed_files' => ['docs/only.md']],
];
$plan = ['test_plan' => [['command' => 'phpunit --filter X'], ['nope' => 1]],
    'rollback_plan' => ['description' => 'revert plan']];
$spec = ['objective' => 'golden objective', 'likely_files' => ['app/A.php', 'docs/a.md'],
    'completion_criteria' => ['crit-a'], 'evidence_required' => ['ev-a'], 'rollback' => 'spec rollback'];

$cases = [
    ['data' => [], 'label' => 'defaults_from_spec'],
    ['data' => ['validation_commands' => ['cmd-1'], 'acceptance_criteria' => ['acc-1'],
        'evidence_required' => ['ev-1']], 'label' => 'explicit_data'],
];

/** Invoke a private method on an uninstantiated object. */
$call = static function (string $class, string $method, array $args) {
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    $obj = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    return $ref->invokeArgs($obj, $args);
};

$targets = [
    'service' => App\Services\Ai\Programming\ProgrammingWorkItemSpecPlanService::class,
    'controller' => App\Http\Controllers\AtlasCodeProgrammingWorkItemController::class,
    'support' => App\Services\Ai\Programming\ProgrammingWorkItemContractSupport::class,
];

$out = [];
foreach ($targets as $name => $class) {
    if (! class_exists($class)) {
        continue;
    }
    foreach ($cases as $case) {
        try {
            $out[$name]['taskContracts'][$case['label']] = $call(
                $class, 'taskContractsFromCompiledTasks',
                [$compiledTasks, $plan, $spec, $workItem, $case['data']],
            );
        } catch (Throwable $e) {
            $out[$name]['taskContracts'][$case['label']] = 'ERR: '.$e->getMessage();
        }
    }
    foreach (['match' => $project, 'mismatch' => $otherProject] as $label => $p) {
        try {
            $out[$name]['belongs'][$label] = $call($class, 'workItemBelongsToProject', [$p, $workItem]);
        } catch (Throwable $e) {
            $out[$name]['belongs'][$label] = 'ERR: '.$e->getMessage();
        }
    }
}

// Anti-vacuum: every captured path must carry real content. Before the fusion
// the owner is the service; after it, the support. Whichever holds the rules
// must still produce the same content.
$owner = isset($out['support']) && is_array($out['support']['taskContracts']['defaults_from_spec'] ?? null)
    ? 'support'
    : 'service';
$out = ['_owner' => $owner] + $out;
$out['service'] = $out[$owner];
$contracts = $out['service']['taskContracts']['defaults_from_spec'] ?? [];
if (count($contracts) !== 3
    || ($contracts[0]['task_id'] ?? null) !== 'T-1'
    || ($contracts[1]['validation_commands'] ?? []) !== ['phpunit tests/AT.php']
    || ($contracts[2]['task_id'] ?? null) !== 'WI-07-task-03'
    || ($contracts[0]['cartography_required'] ?? null) !== true
    || ($contracts[2]['cartography_required'] ?? null) !== false
    || ($out['service']['belongs']['match'] ?? null) !== true
    || ($out['service']['belongs']['mismatch'] ?? null) !== false) {
    fwrite(STDERR, "GOLDEN VACUOUS — capture asserts nothing\n");
    fwrite(STDERR, json_encode($out['service'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
