#!/usr/bin/env php
<?php
declare(strict_types=1);
$xml=simplexml_load_file($argv[1]??'headroom.plg');
if(!$xml) throw new RuntimeException('Invalid plugin XML.');
foreach($xml->FILE as $file) {
    if((string)$file['Run']!=='/bin/bash') continue;
    $path=tempnam(sys_get_temp_dir(),'headroom-lint-');
    try {
        file_put_contents($path,trim((string)$file->INLINE)."
");
        foreach([['bash','-n',$path],['shellcheck',$path]] as $cmd) {
            $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
            if(!is_resource($p)||proc_close($p)!==0) throw new RuntimeException('Installer shell check failed.');
        }
    } finally { unlink($path); }
}
echo "Plugin XML and shell actions valid.
";
