<?php
// Prova anti-vácuo: um músculo que só reporta a STRING do comando não pode
// mais ganhar tests_run/assertions/counts_parseable/claimed_status='passed'.
require '/Users/vitorepf/develop/Atlas/atlas-server/tests/bootstrap.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$src = file_get_contents('/Users/vitorepf/develop/Atlas/atlas-server/app/Services/Ai/SelfConstruction/AtlasTaskServingService.php');
$i = strpos($src, '$muscleEvidence = (array) ($payload[\'evidence\'] ?? []);');
$j = strpos($src, "\$commit = \$this->committer->commitScope(", $i);
$block = substr($src, $i, $j - $i);

$run = function (array $payload, array $scope) use ($block): array {
    $verification = [];
    eval($block);
    return $verification;
};

$scope = ['allowed_files' => ['tests/Unit/FooTest.php'], 'objective' => 'x'];

$onlyCommand = $run(['evidence' => ['commands_run' => ['php artisan test']]], $scope);
$withCounters = $run(['evidence' => [
    'commands_run' => ['php artisan test'],
    'tests_run' => 12, 'assertions_executed' => 40,
    'tests_or_gates_result' => 'passed',
]], $scope);

$e1 = $onlyCommand['execution_evidence'] ?? [];
$e2 = $withCounters['execution_evidence'] ?? [];

printf("SÓ A STRING     tests_run=%s assertions=%s parseable=%s status=%s proof=%s\n",
    var_export($e1['tests_run'] ?? null, true), var_export($e1['assertions_executed'] ?? null, true),
    var_export($e1['counts_parseable'] ?? null, true), var_export($e1['claimed_status'] ?? null, true),
    var_export($onlyCommand['proof_strength'] ?? null, true));
printf("COM CONTADORES  tests_run=%s assertions=%s parseable=%s status=%s proof=%s\n",
    var_export($e2['tests_run'] ?? null, true), var_export($e2['assertions_executed'] ?? null, true),
    var_export($e2['counts_parseable'] ?? null, true), var_export($e2['claimed_status'] ?? null, true),
    var_export($withCounters['proof_strength'] ?? null, true));

$ok = ($e1['tests_run'] ?? -1) === 0
   && ($e1['counts_parseable'] ?? true) === false
   && ($e1['claimed_status'] ?? '') !== 'passed'
   && ! isset($onlyCommand['proof_strength'])
   && ($e2['tests_run'] ?? 0) === 12
   && ($e2['counts_parseable'] ?? false) === true
   && ($withCounters['proof_strength'] ?? null) === 'task_tests_proven';
echo $ok ? "PASS: string não prova nada; contadores reais continuam provando\n" : "FAIL\n";
exit($ok ? 0 : 1);
