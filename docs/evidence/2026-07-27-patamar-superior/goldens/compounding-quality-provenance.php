<?php
// Prova anti-vácuo: outcome sem status reportado não vira 'passed', e a
// procedência de cada dimensão de qualidade fica registrada.
require '/Users/vitorepf/develop/Atlas/atlas-server/tests/bootstrap.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Schema::create('ai_run_outcomes', function (Blueprint $t): void {
    $t->uuid('id')->primary();
    $t->string('schema_version')->nullable();
    $t->string('run_id')->nullable();
    $t->string('trace_id')->nullable();
    $t->string('flow_id')->nullable();
    $t->string('outcome_status', 40)->nullable();
    $t->unsignedTinyInteger('flow_quality')->default(0);
    $t->unsignedTinyInteger('retrieval_quality')->default(0);
    $t->unsignedTinyInteger('execution_quality')->default(0);
    $t->unsignedTinyInteger('evidence_quality')->default(0);
    $t->boolean('human_override')->default(false);
    $t->boolean('learning_required')->default(false);
    $t->json('missed_signals')->nullable();
    $t->json('evidence_refs')->nullable();
    $t->json('payload')->nullable();
    $t->string('outcome_hash')->nullable();
    $t->timestamp('evaluated_at')->nullable();
    $t->timestamps();
});

$evaluator = new App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator;

$bare = $evaluator->evaluate(['flow_id' => 'f1']);
$full = $evaluator->evaluate([
    'flow_id' => 'f2', 'outcome_status' => 'passed',
    'flow_quality' => 77, 'retrieval_quality' => 66,
    'execution_quality' => 55, 'evidence_quality' => 44,
]);

$pb = (array) ($bare->payload['quality_provenance'] ?? []);
$pf = (array) ($full->payload['quality_provenance'] ?? []);

printf("nada reportado  status=%-8s exec=%-3d provenance=%s\n", $bare->outcome_status, $bare->execution_quality, json_encode($pb));
printf("tudo medido     status=%-8s exec=%-3d provenance=%s\n", $full->outcome_status, $full->execution_quality, json_encode($pf));

$ok = $bare->outcome_status === 'unknown'
   && ($pb['execution_quality'] ?? '') === 'derived'
   && ($pb['outcome_status'] ?? '') === 'absent'
   && $full->outcome_status === 'passed'
   && $full->execution_quality === 55
   && ($pf['execution_quality'] ?? '') === 'measured'
   && ($pf['outcome_status'] ?? '') === 'reported';
echo $ok ? "PASS: derivado é rotulado derivado; medido continua medido\n" : "FAIL\n";
exit($ok ? 0 : 1);
