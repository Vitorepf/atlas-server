<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingScopeGuardGate;

// repo temporario: um arquivo rastreado modificado + um arquivo NOVO fora do escopo
$ws = sys_get_temp_dir().'/scopeguard-'.bin2hex(random_bytes(4));
mkdir($ws, 0775, true);
shell_exec("cd $ws && git init -q . && git config user.email t@t && git config user.name t");
file_put_contents($ws.'/dentro.txt', "a\n");
shell_exec("cd $ws && git add -A && git commit -qm init");
file_put_contents($ws.'/dentro.txt', "modificado\n");
file_put_contents($ws.'/FORA-DO-ESCOPO.txt', "arquivo novo que ninguem autorizou\n");

$gate = new ProgrammingScopeGuardGate;
$item = new AtlasProgrammingWorkItem;
$item->workspace = $ws;

$r = new ReflectionMethod($gate, 'filesFromGit');
$files = $r->invoke($gate, $item);
sort($files);
echo "arquivos que o gate enxerga: ".json_encode($files)."\n";
echo "ve o arquivo NOVO fora do escopo? ".(in_array('FORA-DO-ESCOPO.txt', $files, true) ? "SIM" : "NAO")."\n";
shell_exec("rm -rf ".escapeshellarg($ws));
