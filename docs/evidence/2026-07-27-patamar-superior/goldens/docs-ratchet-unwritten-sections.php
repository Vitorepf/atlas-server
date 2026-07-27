<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocStructureAnalyzer;
$a = new AtlasDocStructureAnalyzer();
$fm = "---\ndoc_schema: atlas_canonical_module_doc.v1\ngraph_layer: module\n---\n";
$secs = AtlasDocStructureAnalyzer::REQUIRED_SECTIONS;
$mk = function (callable $body) use ($fm, $secs): string {
    $out = $fm;
    foreach ($secs as $s) { $out .= "\n## {$s}\n\n".$body($s)."\n"; }
    return $out;
};
$count = fn (array $v, string $kind): int => count(array_filter($v, fn ($x) => str_starts_with($x, $kind)));

$ausente = $a->analyzeContent('x.md', $fm);
$stub    = $a->analyzeContent('x.md', $mk(fn ($s) => AtlasDocStructureAnalyzer::PLACEHOLDER_MARKER."\nTexto generico."));
$escrito = $a->analyzeContent('x.md', $mk(fn ($s) => "Conteudo real sobre {$s} deste documento."));

printf("A. sem secao alguma      -> missing=%d unwritten=%d\n", $count($ausente['violations'],'missing_section'), $count($ausente['violations'],'unwritten_section'));
printf("B. 12 secoes do gerador  -> missing=%d unwritten=%d\n", $count($stub['violations'],'missing_section'), $count($stub['violations'],'unwritten_section'));
printf("C. 12 secoes escritas    -> missing=%d unwritten=%d  (total violacoes=%d)\n", $count($escrito['violations'],'missing_section'), $count($escrito['violations'],'unwritten_section'), count($escrito['violations']));
