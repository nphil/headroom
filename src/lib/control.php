<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/monitor.php';

/**
 * One place for per-container changes, shared by the Settings page (api.php) and the automation CLI (control.php).
 * Every change* function expects the caller to hold the operation lock, and never restarts a container.
 */
final class NotFound extends \RuntimeException {}
const CONTROL_COMMANDS=['version','list','set-memory-limit','set-memory-priority','set-cpu-priority','set-swap-limit'];
const CONTROL_READ_ONLY=['version','list'];
const CONTROL_INTERFACE=1;
const CONTROL_FIELDS=[
    'version'=>[],
    'list'=>['name'],
    'set-memory-limit'=>['name','limit'],
    'set-memory-priority'=>['name','level'],
    'set-cpu-priority'=>['name','level'],
    'set-swap-limit'=>['name','limit'],
];
/** Optional extras, only for set-memory-priority: the reserve and stop options a caller may need to put back after a change. */
const CONTROL_OPTIONAL=['set-memory-priority'=>['reserve_mib','whole','eligible']];

function container_name(mixed $name): string {
    if (!is_string($name) || !valid_id('docker:'.$name)) throw new \InvalidArgumentException('"name" must be a container name.');
    return $name;
}
function parse_bytes(mixed $value, string $what): int {
    if (is_int($value)) { if ($value<0) throw new \InvalidArgumentException($what.' cannot be negative.'); return $value; }
    $bytes=is_string($value)?memory_bytes($value):null;
    if ($bytes===null) throw new \InvalidArgumentException($what.' must be a size such as "512m" or "8g", or a whole number of bytes.');
    return $bytes;
}
/** Memory limit: a size, or "none" to remove the limit (returned as 0). */
function parse_memory_limit(mixed $value): int {
    if (is_string($value) && in_array(strtolower(trim($value)),['none','unlimited'],true)) return 0;
    return parse_bytes($value,'"limit"');
}
/** Swap limit: "unlimited" (null), "none" (0) or a size. */
function parse_swap_limit(mixed $value): ?int {
    if (is_string($value)) { $word=strtolower(trim($value)); if ($word==='unlimited') return null; if ($word==='none') return 0; }
    return parse_bytes($value,'"limit"');
}
/** Policy for a named memory priority: the saved options are kept, and the fields a level cannot use are cleared (as the Settings page does). Explicit options are applied after that and checked by validate(). */
function memory_priority_policy(array $saved, string $level, array $options=[]): array {
    if (!isset(LEVELS[$level])) throw new \InvalidArgumentException('"level" must be one of: '.implode(', ',array_keys(LEVELS)).', default.');
    $policy=array_replace($saved,['level'=>$level]);
    if (LEVELS[$level]>=0) $policy['reserve_mib']=0; else $policy['eligible']=false;
    return array_replace($policy,$options);
}
/** Validate one CLI request and return the normalised arguments. Pure: touches neither Docker nor the disk. */
function control_parse(string $command, array $input): array {
    if (!in_array($command,CONTROL_COMMANDS,true)) throw new \InvalidArgumentException('Unknown command. Use: '.implode(', ',CONTROL_COMMANDS).'.');
    $allowed=array_merge(CONTROL_FIELDS[$command],CONTROL_OPTIONAL[$command]??[]);
    $unknown=array_diff(array_keys($input),$allowed);
    if ($unknown) throw new \InvalidArgumentException('Unknown field: '.implode(', ',array_map('strval',$unknown)).'. Allowed: '.($allowed?implode(', ',$allowed):'none').'.');
    if ($command==='version') return [];
    if ($command==='list') return isset($input['name'])?['name'=>container_name($input['name'])]:[];
    $args=['name'=>container_name($input['name']??null)];
    foreach (array_slice(CONTROL_FIELDS[$command],1) as $field) if (!array_key_exists($field,$input)) throw new \InvalidArgumentException('"'.$field.'" is required.');
    $args+=match($command) {
        'set-memory-limit'=>['bytes'=>parse_memory_limit($input['limit'])],
        'set-swap-limit'=>['bytes'=>parse_swap_limit($input['limit'])],
        'set-memory-priority'=>['level'=>is_string($input['level']??null)&&($input['level']==='default'||isset(LEVELS[$input['level']]))?$input['level']:throw new \InvalidArgumentException('"level" must be one of: '.implode(', ',array_keys(LEVELS)).', default.')],
        'set-cpu-priority'=>['level'=>is_string($input['level']??null)&&isset(CPU_LEVELS[$input['level']])?$input['level']:throw new \InvalidArgumentException('"level" must be one of: '.implode(', ',array_keys(CPU_LEVELS)).'.')],
    };
    if ($command==='set-memory-priority') {
        $options=[];
        if (array_key_exists('reserve_mib',$input)) $options['reserve_mib']=is_int($input['reserve_mib'])&&$input['reserve_mib']>=0&&$input['reserve_mib']<=65536?$input['reserve_mib']:throw new \InvalidArgumentException('"reserve_mib" must be a whole number from 0 to 65536.');
        foreach (['whole','eligible'] as $flag) if (array_key_exists($flag,$input)) $options[$flag]=is_bool($input[$flag])?$input[$flag]:throw new \InvalidArgumentException('"'.$flag.'" must be true or false.');
        if ($options && $args['level']==='default') throw new \InvalidArgumentException('"default" removes the saved flag and takes no other options.');
        if ($options) $args['options']=$options;
    }
    return $args;
}
/** Map a failure to the CLI's stable error code and exit status. */
function control_failure(\Throwable $e): array {
    if ($e instanceof OperationBusy) return ['busy',5];
    if ($e instanceof NotFound) return ['not_found',3];
    if ($e instanceof \InvalidArgumentException || $e instanceof \JsonException) return ['invalid_argument',2];
    if ($e instanceof \RuntimeException) return ['failed',4];
    return ['internal_error',1];
}
/** The stable per-container record: what is configured, and what the container reports right now. */
function container_record(array $item): array {
    $p=$item['policy']; $template=$item['template_available']; $swap=$item['swap']; $running=$item['state']==='running';
    $liveShares=cpu_effective($item['cpu_shares']); $savedShares=$template?cpu_effective($item['cpu_template']):$liveShares;
    $cpuLevel=$item['cpu_template_error']?null:cpu_level($savedShares); $live=$swap['live'];
    $swapError=$swap['error']??($running&&!$swap['ok']?($swap['supported']?'The container reports a swap limit of '.$live.', not the saved one.':'This kernel has no per-container swap limit for this container.'):null);
    return [
        'name'=>$item['name'],'state'=>$item['state'],'container_id'=>substr($item['container_id'],0,12),
        'template'=>$template,'editable'=>$template,
        'memory_limit'=>['bytes'=>$item['limit']?:null,'usage_bytes'=>$item['usage'],'template'=>$item['limit_template']],
        'memory_priority'=>['level'=>$p['custom']?'custom':$p['level'],'label'=>$p['custom']?'Custom':LABELS[$p['level']],'score'=>$p['score'],'live_score'=>$item['adj'],'custom'=>$p['custom'],'source'=>$p['source'],'applied'=>$item['adj']===null?null:$item['adj']===$p['score'],'reserve_mib'=>$p['reserve_mib']??0,'whole'=>$p['whole']??false,'eligible'=>$p['eligible']??false],
        'cpu_priority'=>['level'=>$cpuLevel??'custom','label'=>$cpuLevel?CPU_LABELS[$cpuLevel]:'Custom','shares'=>$savedShares,'template_flag'=>$template?$item['cpu_template']:null,'live_shares'=>$liveShares,'live_weight'=>$item['cpu_weight'],'applied'=>$savedShares===$liveShares,'error'=>$item['cpu_template_error']],
        'cpu_pinning'=>['live'=>$item['cpuset'],'template'=>$item['cpuset_template'],'read_only'=>true,'manage_url'=>'/Settings/CPUset'],
        'swap_limit'=>['mode'=>$swap['wanted']===null?'unlimited':($swap['wanted']===0?'none':'limited'),'bytes'=>$swap['wanted'],'live'=>$live,'live_bytes'=>$live!==null&&ctype_digit($live)?(int)$live:null,'current_bytes'=>$swap['current'],'supported'=>$swap['supported'],'applied'=>$running?$swap['ok']:null,'error'=>$swapError],
    ];
}
function control_list(?string $name=null): array {
    $items=docker_items(config()); ksort($items,SORT_STRING|SORT_FLAG_CASE);
    if ($name!==null) { if (!isset($items['docker:'.$name])) throw new NotFound('No container named '.$name.'.'); $items=['docker:'.$name=>$items['docker:'.$name]]; }
    $records=array_map('Headroom\\container_record',array_values($items));
    $supported=array_column(array_column($records,'swap_limit'),'supported');
    return ['headroom'=>VERSION,'unraid'=>unraid_version(),'swap_limit_supported'=>in_array(true,$supported,true)?true:(in_array(false,$supported,true)?false:null),'containers'=>$records];
}
function control_version(): array {
    return ['headroom'=>VERSION,'unraid'=>unraid_version(),'interface'=>CONTROL_INTERFACE,'commands'=>CONTROL_COMMANDS,
        'features'=>['memory_limit','memory_priority','memory_priority_options','cpu_priority','cpu_pinning_read','swap_limit'],
        'memory_levels'=>array_merge(array_keys(LEVELS),['default']),'cpu_levels'=>array_keys(CPU_LEVELS)];
}
function require_container(string $name): void {
    if (!isset(docker_items(config())['docker:'.$name])) throw new NotFound('No container named '.$name.'.');
}
/** Memory priority: the saved Unraid template's --oom-score-adj, applied live. $policy null with $clear removes the flag (Docker default). */
function change_policy(string $id, ?array $policy, bool $clear=false): string {
    $old=config(); $new=$old;
    if (!valid_id($id)) throw new \InvalidArgumentException('Choose a valid app.');
    if ($clear) {
        if (!str_starts_with($id,'docker:')) throw new \InvalidArgumentException('Only containers have a native Docker flag.');
        $policy=array_replace(policy($old,$id),['level'=>'normal','reserve_mib'=>0]);
    }
    if ($policy===null) throw new \InvalidArgumentException('Choose a memory preference.');
    $items=inventory($old);
    if (!isset($items[$id])) throw new \InvalidArgumentException('App or service is no longer present.');
    $keepNative=($policy['level']??'')==='custom' && $items[$id]['kind']==='docker' && $items[$id]['policy']['custom'];
    $new['items'][$id]=$policy;
    if ($keepNative) $new['items'][$id]['level']=$items[$id]['policy']['level'];
    $new=validate($new);
    reservation_check($new,$items); $template=null;
    try {
        if ($items[$id]['kind']==='docker' && !$keepNative) $template=set_template_priority($items[$id]['name'],$clear?null:LEVELS[$new['items'][$id]['level']]);
        $result=apply_policy($new);
        if ($result['errors']) throw new \RuntimeException(implode('; ',$result['errors']));
        save_config($new);
    } catch (\Throwable $e) {
        if ($template && $template['changed']) atomic($template['path'],$template['old'],0644);
        apply_policy($old); throw $e;
    }
    return $items[$id]['name'].': '.($keepNative?'Custom priority kept':LABELS[$new['items'][$id]['level']]).'. Applied live'.($template?' and saved in its native Unraid template. No restart.':'.');
}
function change_memory_priority(string $name, string $level, array $options=[]): string {
    require_container($name); $id='docker:'.$name;
    if ($level==='default') return change_policy($id,null,true);
    return change_policy($id,memory_priority_policy(policy(config(),$id),$level,$options));
}
function change_limit(string $name, int $bytes): string {
    require_container($name);
    set_limit($name,$bytes);
    return $name.': memory limit '.($bytes?round($bytes/GIB,2).' GiB':'cleared').' live and '.(native_memory_supported()?'in Unraid\'s own Memory limit field':'in the Unraid template').'. No restart.';
}
function change_cpu_priority(string $name, string $level): string {
    require_container($name);
    set_cpu_priority($name,$level);
    return $name.': CPU priority '.CPU_LABELS[$level].' ('.CPU_LEVELS[$level].' shares). Applied live and saved in its Unraid template. No restart.';
}
function change_swap_limit(string $name, ?int $bytes): string {
    require_container($name);
    $r=set_swap_limit($name,$bytes);
    $what=$bytes===null?'unlimited':($bytes===0?'none':round($bytes/GIB,2).' GiB');
    if (!$r['live']) return $name.': swap allowance '.$what.' saved'.($bytes===null?'.':'. It is applied as soon as the app starts.');
    return $name.': swap allowance '.$what.', applied to the app\'s own control group'.($r['over']?'. It already holds more than that in swap; those pages stay until used, but no more are added.':'.');
}
/** Run one validated command and return its JSON result. Mutations take the operation lock, waiting up to 30 s for the daemon or Settings page. */
function control_execute(string $command, array $args): array {
    if ($command==='version') return control_version();
    if ($command==='list') return control_list($args['name']??null);
    $message=control_locked(fn()=>match($command) {
        'set-memory-limit'=>change_limit($args['name'],$args['bytes']),
        'set-memory-priority'=>change_memory_priority($args['name'],$args['level'],$args['options']??[]),
        'set-cpu-priority'=>change_cpu_priority($args['name'],$args['level']),
        'set-swap-limit'=>change_swap_limit($args['name'],$args['bytes']),
    });
    return ['message'=>$message,'container'=>control_list($args['name'])['containers'][0]];
}
function control_locked(callable $fn): mixed {
    $deadline=microtime(true)+30;
    for (;;) {
        try { return locked($fn,false); }
        catch (OperationBusy $e) { if (microtime(true)>=$deadline) throw $e; usleep(250000); }
    }
}
