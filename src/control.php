#!/usr/bin/php
<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/control.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','stderr');
const USAGE='headroom control: list | set-memory-limit | set-memory-priority | set-cpu-priority | set-swap-limit. '
    .'Input is one JSON object on stdin or after --json; output is one JSON object on stdout. See docs/HeadroomControl.md.';
function out(array $data, int $exit): never {
    $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    echo ($json===false?'{"ok":false,"error":{"code":"internal_error","message":"The result could not be encoded."}}':$json)."\n";
    exit($json===false?1:$exit);
}
function stdin_json(): ?string {
    if (stream_isatty(STDIN)) return null;
    $raw=stream_get_contents(STDIN,65537);
    if ($raw!==false && strlen($raw)>65536) throw new \InvalidArgumentException('Input is larger than 64 KiB.');
    return $raw===false||trim($raw)===''?null:$raw;
}
$command='';
try {
    if (posix_geteuid()!==0) out(['ok'=>false,'command'=>null,'error'=>['code'=>'forbidden','message'=>'Run as root; Headroom changes Unraid templates and control groups.']],1);
    $args=array_slice($argv,1); $command=(string)array_shift($args);
    if (in_array($command,['','help','--help','-h'],true)) out(['ok'=>true,'command'=>'help','usage'=>USAGE,'commands'=>CONTROL_COMMANDS],0);
    $json=null; $name=null;
    if (!in_array($command,CONTROL_COMMANDS,true)) throw new \InvalidArgumentException('Unknown command. Use: '.implode(', ',CONTROL_COMMANDS).'.');
    while ($args) {
        $flag=array_shift($args);
        if (($flag==='--json' || $flag==='--name') && $args) { if ($flag==='--json') $json=array_shift($args); else $name=array_shift($args); }
        else throw new \InvalidArgumentException('Unknown or incomplete argument: '.$flag.'. '.USAGE);
    }
    $readOnly=in_array($command,CONTROL_READ_ONLY,true);
    if ($json===null && !$readOnly) $json=stdin_json();
    if ($json===null && !$readOnly) throw new \InvalidArgumentException('This command needs a JSON object on stdin or after --json.');
    $input=$json===null?[]:json_decode($json,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input) || ($input!==[] && array_is_list($input))) throw new \InvalidArgumentException('The input must be a JSON object.');
    if ($name!==null) {
        if (isset($input['name']) && $input['name']!==$name) throw new \InvalidArgumentException('"name" was given twice with different values.');
        $input['name']=$name;
    }
    $result=control_execute($command,control_parse($command,$input));
    out(['ok'=>true,'command'=>$command]+$result,0);
} catch (\Throwable $e) {
    [$code,$status]=control_failure($e);
    out(['ok'=>false,'command'=>$command===''?null:$command,'error'=>['code'=>$code,'message'=>$e->getMessage()]],$status);
}
