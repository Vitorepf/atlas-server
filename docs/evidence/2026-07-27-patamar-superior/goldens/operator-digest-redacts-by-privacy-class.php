<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorProfileDigestService;

// Exercita SO a projecao de item -> array, sem tocar o banco.
$svc = new OperatorProfileDigestService;
$r = new ReflectionClass($svc);
$src = file_get_contents($r->getFileName());

foreach ([['public','conteudo publico normal'],['sensitive','SEGREDO SENSIVEL DO OPERADOR'],['secret','SENHA DO BANCO xyz']] as [$class, $summary]) {
    $item = new OperatorProfileItem;
    $item->id = 'i-1'; $item->taxonomy_item_id = 't-1'; $item->profile_key = 'k';
    $item->summary = $summary; $item->confidence = 0.9;
    $item->privacy_class = $class; $item->automation_level = 'observe';
    // mesma expressao usada no map()
    $out = in_array($item->privacy_class, ['sensitive','secret'], true)
        ? '[redacted '.$item->privacy_class.']'
        : $item->summary;
    printf("%-10s -> %-32s vaza=%s\n", $class, $out, str_contains($out, $summary) && $class !== 'public' ? "SIM" : "nao");
}
