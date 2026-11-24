<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/config.php';

/** Decode shell quoting without evaluating variables or executing anything. */
function shell_word(string $raw): string {
    $value=''; $quote=''; $length=strlen($raw);
    for ($i=0;$i<$length;$i++) {
        $ch=$raw[$i];
        if ($quote==="'") { if($ch==="'") $quote=''; else $value.=$ch; continue; }
        if ($ch==='\\' && $i+1<$length && ($quote==='' || str_contains('"\\$`' . "\n",$raw[$i+1]))) { $value.=$raw[++$i]; continue; }
        if ($ch==='"') { $quote=$quote==='"'?'':'"'; continue; }
        if ($ch==="'" && $quote==='') { $quote="'"; continue; }
        $value.=$ch;
    }
    if ($quote!=='') throw new \InvalidArgumentException('Unclosed quote in template ExtraParams.');
    return $value;
}
function expansion_end(string $text, int $start): int {
    $depth=1; $quote=''; $length=strlen($text);
    for($i=$start+2;$i<$length;$i++) {
        $ch=$text[$i];
        if($ch==='\\' && $quote!=="'") { $i++; continue; }
        if($quote==="'") { if($ch==="'") $quote=''; continue; }
        if($ch==='$' && ($text[$i+1]??'')==='(') { $i=expansion_end($text,$i); continue; }
        if($ch==='"') { $quote=$quote==='"'?'':'"'; continue; }
        if($quote!=='') continue;
        if($ch==="'") { $quote="'"; continue; }
        if($ch==='(') $depth++;
        if($ch===')' && --$depth===0) return $i;
    }
    throw new \InvalidArgumentException('Unclosed shell expression in template ExtraParams.');
}
function parameter_words(string $params): array {
    $words=[]; $word=''; $quote=''; $length=strlen($params);
    for($i=0;$i<$length;$i++) {
        $ch=$params[$i];
        if($ch==='\\' && $quote!=="'") {
            if($i+1===$length) throw new \InvalidArgumentException('Unclosed escape in template ExtraParams.');
            $word.=$ch.$params[++$i]; continue;
        }
        if($ch==='$' && $quote!=="'" && ($params[$i+1]??'')==='(') {
            $end=expansion_end($params,$i); $word.=substr($params,$i,$end-$i+1); $i=$end; continue;
        }
        if($quote!=='') { $word.=$ch; if($ch===$quote) $quote=''; continue; }
        if($ch==="'" || $ch==='"' || $ch===chr(96)) { $quote=$ch; $word.=$ch; continue; }
        if(ctype_space($ch)) { if($word!=='') { $words[]=$word; $word=''; } continue; }
        $word.=$ch;
    }
    if($quote!=='') throw new \InvalidArgumentException('Unclosed quote in template ExtraParams.');
    if($word!=='') $words[]=$word;
    return $words;
}
function parameter_entries(string $params): array {
    $words=array_map(fn($raw)=>['raw'=>$raw,'value'=>shell_word($raw)],parameter_words($params));
    $entries=[]; $booleans=['--detach','--rm','--privileged','--init','--interactive','--tty','--read-only','--oom-kill-disable','--publish-all','--sig-proxy','--no-healthcheck','--disable-content-trust','--help'];
    for($i=0;$i<count($words);$i++) {
        $word=$words[$i]; $value=$word['value']; $flag=$value; $arg=null; $raw=$word['raw'];
        if (str_starts_with($value,'--') && str_contains($value,'=')) [$flag,$arg]=explode('=',$value,2);
        elseif (preg_match('/^-([acehlmpuvw])(.+)$/s',$value,$m)) { $flag='-'.$m[1]; $arg=$m[2]; }
        elseif (str_starts_with($value,'-') && !in_array($value,$booleans,true) && !preg_match('/^-[ditP]+$/D',$value)) {
            if (!isset($words[$i+1])) throw new \InvalidArgumentException('A template option is missing its value.');
            $arg=$words[++$i]['value']; $raw.=' '.$words[$i]['raw'];
        }
        $entries[]=['flag'=>$flag,'value'=>$arg,'raw'=>$raw];
    }
    return $entries;
}
function replace_parameter(string $params, array $flags, ?string $value): string {
    $keep=[];
    foreach(parameter_entries($params) as $entry) if(!in_array($entry['flag'],$flags,true)) $keep[]=$entry['raw'];
    if($value!==null) $keep[]=$flags[0].'='.$value;
    return implode(' ',$keep);
}
function limit_params(string $params, int $bytes): string { return replace_parameter($params,['--memory','-m'],$bytes>0?(string)$bytes:null); }
/** Unraid release as MAJOR.MINOR.PATCH, with any -rc/-beta suffix kept so pre-releases never count as final. */
function unraid_version(string $path='/etc/unraid-version'): string {
    return preg_match('/^\s*version\s*=\s*"?([0-9]+(?:\.[0-9]+){0,2}(?:[-+][0-9A-Za-z.+-]+)?)"?\s*$/m',text($path),$m)?$m[1]:'';
}
function version_at_least(string $version, string $minimum): bool {
    if(!preg_match('/^([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?([-+].*)?$/D',$version,$v)) return false;
    $have=[(int)$v[1],(int)($v[2]??0),(int)($v[3]??0)]; $need=array_map('intval',explode('.',$minimum));
    if($have!==$need) return ($have<=>$need)>0;
    return !str_starts_with($v[4]??'','-'); // 7.4.0-rc.1 is older than 7.4.0
}
/** Unraid 7.4.0 added the Memory limit field (<Memory>) to container templates. */
function native_memory_supported(?string $version=null): bool { return version_at_least($version??unraid_version(),'7.4.0'); }
/** Parse a Docker memory size the way `docker run --memory` does (binary units); null when not an exact whole number of bytes. */
function memory_bytes(string $value): ?int {
    if(!preg_match('/^([0-9]+)(?:\.([0-9]+))? ?([kmgtp])?(?:i?b)?$/Di',trim($value),$m)) return null;
    $unit=strtolower($m[3]??''); $multiplier=$unit===''?1:1<<(10*(strpos('kmgtp',$unit)+1)); $fraction=$m[2]??'';
    if($fraction!=='' && (strlen($fraction)>6 || $multiplier>(1<<40))) return null;
    $whole=(int)$m[1]; if($m[1]!==(string)$whole || $whole>intdiv(PHP_INT_MAX,$multiplier)-1) return null;
    $bytes=$whole*$multiplier;
    if($fraction!=='') {
        $scale=10**strlen($fraction); $part=(int)$fraction*$multiplier;
        if($part%$scale!==0) return null;
        $bytes+=intdiv($part,$scale);
    }
    return $bytes;
}
/** Value for Unraid's Memory limit field (pattern [0-9]+[bkmgBKMG]?): the largest unit that divides the size exactly. */
function memory_field(int $bytes): string {
    foreach(['g'=>GIB,'m'=>MIB,'k'=>1024] as $unit=>$size) if($bytes>=$size && $bytes%$size===0) return intdiv($bytes,$size).$unit;
    return (string)$bytes;
}
function template_memory(string $content): string {
    $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET);
    if(!$xml || $xml->getName()!=='Container') throw new \RuntimeException('Invalid Unraid container template.');
    if(count($xml->Memory)>1) throw new \RuntimeException('The template has more than one Memory limit.');
    return trim((string)$xml->Memory);
}
/** Set Unraid's Memory limit; an empty value clears it. An absent element is created only when there is something to save. */
function template_with_memory(string $content, string $value): string {
    template_memory($content);
    if($value!=='' && !preg_match('/^[0-9]+[bkmgBKMG]?$/D',$value)) throw new \InvalidArgumentException('Memory limit is not in a format Unraid accepts.');
    $pattern='#<Memory(?:\s[^>]*?)?(?:/>|>.*?</Memory>)#s'; $node=$value===''?'<Memory/>':'<Memory>'.$value.'</Memory>';
    if(preg_match($pattern,$content)) $new=preg_replace_callback($pattern,fn()=>$node,$content,1);
    elseif($value==='') return $content;
    else $new=str_replace('</Container>','  '.$node."\n</Container>",$content);
    if(template_memory($new)!==$value) throw new \RuntimeException('Template validation failed.');
    return $new;
}
/** One source of truth for a container's limit: Unraid's Memory field on 7.4+, a --memory flag before that. */
function template_with_limit(string $content, int $bytes, bool $native): string {
    $params=template_params($content);
    if(!$native) return template_with_params($content,limit_params($params,$bytes));
    return template_with_memory(template_with_params($content,replace_parameter($params,['--memory','-m'],null)),$bytes>0?memory_field($bytes):'');
}
/**
 * Decide how to move a --memory/-m flag from ExtraParams into the Memory field.
 * 'none' = nothing to do, 'move' = 'content' is the new template, 'skip' = leave untouched ('reason' says why).
 */
function memory_migration_plan(string $content): array {
    libxml_use_internal_errors(true);
    $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET);
    if(!$xml || $xml->getName()!=='Container') return ['action'=>'none'];
    $params=(string)$xml->ExtraParams; $skip=fn(string $reason)=>['action'=>'skip','reason'=>$reason];
    try { $entries=parameter_entries($params); }
    catch(\InvalidArgumentException $e) { return preg_match('/--memory|(?:^|\s)-m/',$params)?$skip('ExtraParams could not be read safely: '.$e->getMessage()):['action'=>'none']; }
    $isMemory=fn($e)=>in_array($e['flag'],['--memory','-m'],true);
    $flags=array_values(array_filter($entries,$isMemory));
    if(!$flags) return ['action'=>'none'];
    $sizes=[];
    foreach($flags as $flag) {
        $bytes=is_string($flag['value'])?memory_bytes($flag['value']):null;
        if($bytes===null) return $skip('the limit "'.($flag['value']??'').'" cannot be written exactly in the Memory limit field');
        $sizes[$bytes]=true;
    }
    if(count($sizes)>1) return $skip('ExtraParams sets more than one different memory limit');
    $bytes=array_key_first($sizes);
    if(count($xml->Memory)>1) return $skip('the template has more than one Memory limit');
    $existing=trim((string)$xml->Memory); $existingBytes=$existing===''?0:memory_bytes($existing);
    if($existingBytes===null) return $skip('the Memory limit field holds "'.$existing.'", which cannot be read');
    if($existingBytes!==0 && $existingBytes!==$bytes) return $skip('the Memory limit field ('.$existing.') differs from the --memory flag ('.memory_field($bytes).') that currently wins');
    $value=$bytes>0?($existingBytes===$bytes?$existing:memory_field($bytes)):$existing;
    try {
        $new=template_with_params($content,replace_parameter($params,['--memory','-m'],null));
        if($value!==$existing) $new=template_with_memory($new,$value);
        $check=simplexml_load_string($new,'SimpleXMLElement',LIBXML_NONET);
        $left=array_map(fn($e)=>$e['raw'],array_values(array_filter($entries,fn($e)=>!$isMemory($e))));
        $kept=array_map(fn($e)=>$e['raw'],parameter_entries((string)$check->ExtraParams));
        if($kept!==$left || trim((string)$check->Memory)!==$value || ($bytes>0 && memory_bytes($value)!==$bytes)) throw new \RuntimeException('Template validation failed.');
    } catch(\Throwable $e) { return $skip('the edited template did not validate ('.$e->getMessage().')'); }
    return ['action'=>'move','content'=>$new,'value'=>$value,'bytes'=>$bytes];
}
/**
 * Move every template's --memory/-m flag into its Memory field. All backups are taken before the first write,
 * each write is atomic, and a template that is ambiguous or changed meanwhile is left alone. Nothing restarts.
 */
function migrate_native_memory(?string $dir=null, ?callable $backup=null): array {
    $dir??='/boot/config/plugins/dockerMan/templates-user'; $backup??=fn(string $path)=>backup($path);
    $result=['moved'=>[],'skipped'=>[],'errors'=>[]]; $plans=[];
    libxml_use_internal_errors(true);
    foreach(glob($dir.'/*.xml')?:[] as $path) {
        $content=file_get_contents($path); if($content===false) continue;
        $plan=memory_migration_plan($content); if($plan['action']==='none') continue;
        $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET); $name=(string)$xml->Name!==''?(string)$xml->Name:basename($path,'.xml');
        if($plan['action']==='skip') { $result['skipped'][$name]=$plan['reason']; continue; }
        $plans[]=[$name,$path,$content,$plan];
    }
    $ready=[];
    foreach($plans as $item) {
        try { $backup($item[1]); $ready[]=$item; } catch(\Throwable $e) { $result['errors'][$item[0]]='backup failed, template untouched: '.$e->getMessage(); }
    }
    foreach($ready as [$name,$path,$content,$plan]) {
        try {
            if(file_get_contents($path)!==$content) { $result['skipped'][$name]='the template changed while Headroom was reading it; it is checked again at the next start'; continue; }
            atomic($path,$plan['content'],0644); $result['moved'][$name]=$plan['value']?:'no limit';
        } catch(\Throwable $e) { $result['errors'][$name]=$e->getMessage(); }
    }
    return $result;
}
function priority_params(string $params, ?int $score): string {
    if($score!==null) integer($score,-1000,1000,'Docker memory priority');
    return replace_parameter($params,['--oom-score-adj'],$score===null?null:(string)$score);
}
function priority_score(string $params): ?int {
    $score=null;
    foreach(parameter_entries($params) as $entry) if($entry['flag']==='--oom-score-adj') {
        if(!is_string($entry['value']) || !preg_match('/^[+-]?(?:0|[1-9][0-9]{0,3})$/D',$entry['value'])) throw new \InvalidArgumentException('Use a decimal oom-score-adj between -1000 and 1000 in the Unraid template.');
        $score=integer((int)$entry['value'],-1000,1000,'Docker memory priority');
    }
    return $score;
}
const CPU_SHARES_FLAGS=['--cpu-shares','-c'];
/** Docker's --cpu-shares / -c from ExtraParams: null when absent, 0 when Docker's own "use the default" spelling. A bad value throws rather than guessing. */
function cpu_shares_flag(string $params): ?int {
    $shares=null;
    foreach(parameter_entries($params) as $entry) if(in_array($entry['flag'],CPU_SHARES_FLAGS,true)) {
        if(!is_string($entry['value']) || !preg_match('/^(?:0|[1-9][0-9]{0,5})$/D',$entry['value'])) throw new \InvalidArgumentException('Use a whole number for --cpu-shares in the Unraid template.');
        $value=(int)$entry['value'];
        if($value!==0) integer($value,CPU_SHARES_MIN,CPU_SHARES_MAX,'CPU shares');
        $shares=$value;
    }
    return $shares;
}
/** Exactly one --cpu-shares=N (null removes it), other words kept. Already-correct text is returned byte for byte. */
function cpu_shares_params(string $params, ?int $shares): string {
    if($shares!==null) integer($shares,CPU_SHARES_MIN,CPU_SHARES_MAX,'CPU shares');
    $present=array_values(array_filter(parameter_entries($params),fn($e)=>in_array($e['flag'],CPU_SHARES_FLAGS,true)));
    if($shares===null && !$present) return $params;
    if($shares!==null && count($present)===1 && $present[0]['flag']==='--cpu-shares' && $present[0]['value']===(string)$shares) return $params;
    return replace_parameter($params,CPU_SHARES_FLAGS,$shares===null?null:(string)$shares);
}
/** Docker treats no flag and 0 as its default weight of 1024. */
function cpu_effective(?int $shares): int { return $shares?:1024; }
/** Named level for a share count, or null for a custom number. */
function cpu_level(?int $shares): ?string { $level=array_search(cpu_effective($shares),CPU_LEVELS,true); return $level===false?null:$level; }
function template_index(): array {
    $found=[]; libxml_use_internal_errors(true);
    foreach(glob('/boot/config/plugins/dockerMan/templates-user/*.xml')?:[] as $path) {
        $xml=simplexml_load_file($path,'SimpleXMLElement',LIBXML_NONET);
        if($xml && (string)$xml->Name!=='') $found[(string)$xml->Name][]=$path;
    }
    return $found;
}
function template_for(string $name): string {
    $found=template_index()[$name]??[];
    if(count($found)!==1) throw new \RuntimeException('Expected one saved Unraid template for '.$name.'. Save it in the Docker tab first.');
    return $found[0];
}
function template_params(string $content): string {
    $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET);
    if(!$xml || $xml->getName()!=='Container') throw new \RuntimeException('Invalid Unraid container template.');
    return (string)$xml->ExtraParams;
}
function template_with_params(string $content, string $params): string {
    template_params($content);
    $node='<ExtraParams>'.htmlspecialchars($params,ENT_XML1|ENT_QUOTES,'UTF-8').'</ExtraParams>';
    $pattern='#<ExtraParams(?:\s[^>]*)?>.*?</ExtraParams>|<ExtraParams\s*/>#s';
    $new=preg_match($pattern,$content)?preg_replace_callback($pattern,fn()=>$node,$content,1):str_replace('</Container>','  '.$node."\n</Container>",$content);
    template_params($new); return $new;
}
/** Save native priority first; caller holds the operation lock and applies live. */
function set_template_priority(string $name, ?int $score): array {
    $path=template_for($name); $old=(string)file_get_contents($path); $params=template_params($old);
    $new=template_with_params($old,priority_params($params,$score));
    if($new!==$old) {
        backup($path); $restore=json_file(CONFIG.'/restore.json');
        if(!array_key_exists($name,$restore['priorities']??[])) {
            $restore['priorities'][$name]=priority_score($params); save_json(CONFIG.'/restore.json',$restore);
        }
        atomic($path,$new,0644);
    }
    return ['path'=>$path,'old'=>$old,'changed'=>$new!==$old];
}
/** One parse for the template fields Headroom reads: ExtraParams, Unraid's own CPU pinning (<CPUset>) and Memory limit. */
function template_fields(string $content): array {
    $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET);
    if(!$xml || $xml->getName()!=='Container') throw new \RuntimeException('Invalid Unraid container template.');
    return ['params'=>(string)$xml->ExtraParams,'cpuset'=>trim((string)$xml->CPUset),'memory'=>trim((string)$xml->Memory)];
}
/** Save Docker's --cpu-shares in ExtraParams (null = Docker default, flag removed). Caller holds the operation lock and applies live. */
function set_template_cpu_shares(string $name, ?int $shares): array {
    $path=template_for($name); $old=(string)file_get_contents($path); $params=template_params($old);
    $original=cpu_shares_flag($params)?:null; // refuses a template whose current value cannot be read
    $new=template_with_params($old,cpu_shares_params($params,$shares));
    if($new!==$old) {
        backup($path); $restore=json_file(CONFIG.'/restore.json');
        if(!array_key_exists($name,$restore['cpu_shares']??[])) { $restore['cpu_shares'][$name]=$original; save_json(CONFIG.'/restore.json',$restore); }
        atomic($path,$new,0644);
    }
    return ['path'=>$path,'old'=>$old,'changed'=>$new!==$old];
}
function migrate_native_priorities(array $c): void {
    $restore=json_file(CONFIG.'/restore.json'); if($restore['native_priorities']??false) return;
    $templates=template_index(); $changes=[];
    try {
        foreach($c['items'] as $id=>$p) if(str_starts_with($id,'docker:')) {
            $name=substr($id,7);
            if(!isset($templates[$name])) {
                if(trim(run(['docker','ps','-aq','--filter','name=^/'.preg_quote($name,'/').'$']))!=='') throw new \RuntimeException('Save an Unraid template for '.$name.' before migrating its priority. Existing protection is unchanged.');
                continue;
            }
            $changes[]=set_template_priority($name,LEVELS[$p['level']]);
        }
        $restore=json_file(CONFIG.'/restore.json'); $restore['native_priorities']=true; save_json(CONFIG.'/restore.json',$restore);
    } catch(\Throwable $e) {
        foreach(array_reverse($changes) as $change) if($change['changed']) atomic($change['path'],$change['old'],0644);
        throw $e;
    }
}
function docker_policy(array $c, string $id, int $score, string $source): array {
    $p=policy($c,$id); $level=array_search($score,LEVELS,true);
    $p['level']=$level===false?($score<0?'last':($score>0?'early':'normal')):$level;
    $p['score']=$score; $p['custom']=$level===false; $p['source']=$source;
    if($score>=0) $p['reserve_mib']=0;
    if($score<0) $p['eligible']=false;
    return $p;
}
