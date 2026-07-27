<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ai\SelfConstruction\AtlasArtisanBootSmokeGate;

$repo = '/Users/vitorepf/develop/Atlas/atlas-server';
$gate = new AtlasArtisanBootSmokeGate;

// A) escopo inocuo: baseline TEM de bootar, senao o gate e cego
$ok = $gate->differentialForScopedCommit($repo, ['README.md']);
printf("A. escopo inocuo   baseline_ok=%-5s introduced=%-5s already_broken=%s\n",
    var_export($ok['baseline']['ok'] ?? null, true),
    var_export($ok['introduced_failure'], true),
    var_export($ok['baseline_already_broken'], true));

// B) escopo que QUEBRA o boot: um provider com erro de sintaxe
// Comandos sao descobertos ANSIOSAMENTE no boot — foi assim que o scheduler
// morreu em 13/07. Estreitar Command::line() e fatal em discoverCommands().
$victim = $repo.'/app/Console/Commands/ZzBootBreakerCommand.php';
file_put_contents($victim, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Console\\Commands;\n\nuse Illuminate\\Console\\Command;\n\nfinal class ZzBootBreakerCommand extends Command\n{\n    protected \$signature = 'zz:boot-breaker';\n\n    protected function line(\$string, \$style = null, \$verbosity = null): void {}\n}\n");
$bad = $gate->differentialForScopedCommit($repo, ['app/Console/Commands/ZzBootBreakerCommand.php']);
printf("B. escopo quebrado baseline_ok=%-5s introduced=%-5s already_broken=%s\n",
    var_export($bad['baseline']['ok'] ?? null, true),
    var_export($bad['introduced_failure'], true),
    var_export($bad['baseline_already_broken'], true));
@unlink($victim);
