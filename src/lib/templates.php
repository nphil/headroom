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
