<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/swap.php';
const SWAP_LOW_RAM_FACTOR=1.5; // "RAM is low" for swap = available below 1.5x the memory-pressure threshold (15% by default).
const SWAP_CLEAR_MARGIN=5;      // Swap warning clears 5 percentage points below where it started.

function db(): \SQLite3 {
    static $db=null;
    if ($db) return $db;
    if (!is_dir(RUN)) mkdir(RUN,0755,true);
    $db=new \SQLite3(RUN.'/history.sqlite'); $db->busyTimeout(3000); $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=DELETE; PRAGMA auto_vacuum=FULL; PRAGMA max_page_count=4096;');
    $db->exec('CREATE TABLE IF NOT EXISTS samples (t INTEGER PRIMARY KEY, data TEXT NOT NULL); CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY, t INTEGER NOT NULL, kind TEXT NOT NULL, message TEXT NOT NULL, fingerprint TEXT UNIQUE);');
    return $db;
}
function event(string $kind, string $message, ?string $fingerprint=null): bool {
    $db=db(); $s=$db->prepare('INSERT OR IGNORE INTO events(t,kind,message,fingerprint) VALUES(:t,:k,:m,:f)');
    $s->bindValue(':t',time(),SQLITE3_INTEGER); $s->bindValue(':k',$kind); $s->bindValue(':m',substr($message,0,3000)); $s->bindValue(':f',$fingerprint,$fingerprint===null?SQLITE3_NULL:SQLITE3_TEXT); $s->execute();
    $inserted=$db->changes()>0;
    $db->exec('DELETE FROM events WHERE id NOT IN (SELECT id FROM events ORDER BY id DESC LIMIT 500)');
    return $inserted;
}
function notify(string $subject, string $message, string $severity='warning'): void {
    run(['/usr/local/emhttp/plugins/dynamix/scripts/notify','-e','Headroom','-s',$subject,'-d',$message,'-i',$severity,'-l','/Settings/Headroom'],30);
    event('notification',$subject.': '.$message);
}
function sample(array $items, string $dev): array {
    $m=meminfo(); $p=psi(); $z=zram_stats($dev); $arc=arc(); $ss=swaps();
    $swapSize=0; $swapUsed=0; $diskUsed=0;
    foreach ($ss as $s) { $swapSize+=$s['size']; $swapUsed+=$s['used']; if (!str_starts_with($s['path'],'/dev/zram')) $diskUsed+=$s['used']; }
    $top=[];
    foreach ($items as $item) if ($item['kind']==='docker' && $item['state']==='running') $top[$item['name']]=$item['usage'];
    arsort($top); $top=array_slice($top,0,8,true);
    $ram=[];
    foreach (['/','/tmp','/var/log'] as $path) { $total=@disk_total_space($path); $free=@disk_free_space($path); $ram[$path]=['total'=>$total,'used'=>$total!==false&&$free!==false?$total-$free:null]; }
    return ['t'=>time(),'total'=>$m['MemTotal'],'available'=>$m['MemAvailable'],'ram_used'=>$m['MemTotal']-$m['MemAvailable'],
        'swap_size'=>$swapSize,'swap_used'=>$swapUsed,'disk_used'=>$diskUsed,'zram'=>$z,'zram_ram'=>$z['ram_cost']??0,
        'arc'=>$arc,'arc_size'=>$arc['size'],'psi_some'=>$p['some'],'psi_full'=>$p['full'],'top'=>$top,'ramdisks'=>$ram,
        'swappiness'=>(int)text('/proc/sys/vm/swappiness')];
}
function history_add(array $sample, int $hours): void {
    $db=db();
    $q=$db->prepare('DELETE FROM samples WHERE t < :t'); $q->bindValue(':t',time()-$hours*3600,SQLITE3_INTEGER); $q->execute();
    $q=$db->prepare('INSERT OR REPLACE INTO samples(t,data) VALUES(:t,:d)');
    $q->bindValue(':t',$sample['t'],SQLITE3_INTEGER); $q->bindValue(':d',json_encode($sample,JSON_THROW_ON_ERROR)); $q->execute();
}
function history(int $hours=24): array {
    $q=db()->prepare('SELECT data FROM samples WHERE t>=:t ORDER BY t');
    $q->bindValue(':t',time()-max(1,min(72,$hours))*3600,SQLITE3_INTEGER); $r=$q->execute(); $out=[];
    while ($row=$r->fetchArray(SQLITE3_ASSOC)) $out[]=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
    return $out;
}
function events(): array {
    $r=db()->query('SELECT t,kind,message FROM events ORDER BY id DESC LIMIT 100'); $out=[];
    while ($row=$r->fetchArray(SQLITE3_ASSOC)) $out[]=$row;
    return $out;
}
function pressure_step(array $state, array $s, array $c, int $now): array {
    $available=100*$s['available']/max(1,$s['total']);
    $pressure=$available<$c['available_percent'] || ($s['psi_some']!==null && $s['psi_some']>=$c['psi_some']) || ($s['psi_full']!==null && $s['psi_full']>=$c['psi_full']);
    // Full compressed swap is normal while RAM is free (cold pages parked cheaply). Swap only counts as a
    // warning when it is full AND the RAM left to absorb a burst is low, or tasks are visibly waiting for RAM.
    // Hysteresis: once raised, the warning clears only after clear improvement, so it cannot flap at a boundary.
    $was=!empty($state['swap_now']); $swapPercent=$s['swap_size']>0 ? 100*$s['swap_used']/$s['swap_size'] : 0;
    $tight=$c['available_percent']*SWAP_LOW_RAM_FACTOR*($was?1.25:1);
    $waiting=fn(?float $v,int $limit)=>$v!==null && $v>=$limit/2*($was?0.5:1);
    $full=$swapPercent >= $c['swap_percent']-($was?SWAP_CLEAR_MARGIN:0);
    $swap=$full && ($available<$tight || $waiting($s['psi_some'],$c['psi_some']) || $waiting($s['psi_full'],$c['psi_full']));
    $state['swap_full']=$swapPercent >= $c['swap_percent'];
    $messages=[];
    foreach (['pressure'=>$pressure,'swap'=>$swap] as $key=>$active) {
        $was=$state[$key]??['since'=>null,'notified'=>false];
        if ($active) {
            $was['since'] ??= $now;
            if (!$was['notified'] && $now-$was['since']>=$c['sustain_seconds']) { $messages[]=$key; $was['notified']=true; }
        } else {
            if ($was['notified']) $messages[]=$key.'_recovered';
            $was=['since'=>null,'notified'=>false];
        }
        $state[$key]=$was;
    }
    if (!$pressure) $state['acted_episode']=false;
    $state['pressure_now']=$pressure; $state['swap_now']=$swap;
    return ['state'=>$state,'messages'=>$messages];
}
function oom_parse(string $line, array &$pending): ?array {
    if (str_contains($line,'oom-kill:') && preg_match('/\bpid=(\d+)/',$line,$pid)) {
        preg_match('#task_memcg=([^,\s]+)#',$line,$cg);
        preg_match('#(?:/docker/|docker-)([a-f0-9]{64})#',$cg[1]??'',$id);
        $pending[(int)$pid[1]]=['container_id'=>$id[1]??'',
            'cause'=>str_contains($line,'CONSTRAINT_MEMCG')?'container memory limit':'host ran out of memory'];
        if (count($pending)>100) unset($pending[array_key_first($pending)]);
    }
    if (!preg_match('/(?:Out of memory:|Memory cgroup out of memory:)\s+Killed process (\d+) \((.*?)\)(.*)$/i',$line,$m)) return null;
    $pid=(int)$m[1]; $context=$pending[$pid]??['container_id'=>'','cause'=>str_contains(strtolower($line),'memory cgroup')?'container memory limit':'host ran out of memory'];
    unset($pending[$pid]);
    preg_match('/anon-rss:(\d+)kB/',$m[3],$rss);
    return $context+['pid'=>$pid,'process'=>$m[2],'rss'=>(int)($rss[1]??0)*1024];
}
function record_oom(string $line, array &$pending, array $containers, bool $send): void {
    $parsed=oom_parse($line,$pending); if (!$parsed) return;
    $name=$containers[$parsed['container_id']]??null;
    if (!$name && $parsed['container_id']) $name='container '.substr($parsed['container_id'],0,12);
    $msg='Kernel stopped '.$parsed['process'].' (PID '.$parsed['pid'].')'.($name?' in '.$name:'').': '.$parsed['cause'].'.';
    $key=hash('sha256',text('/proc/sys/kernel/random/boot_id').$line);
    if (event('oom',$msg,$key) && $send) notify('An app ran out of memory',$msg,'alert');
}
function ramdisk_footprints(): array {
    $out=[];
    foreach (['/','/tmp','/var/log'] as $path) {
        try {
            $v=run(['nice','-n','19','ionice','-c','3','du','-x','-s','-B1',$path],20);
            $out[$path]=preg_match('/^(\d+)/',$v,$m)?(int)$m[1]:null;
        } catch (\Throwable) { $out[$path]=null; }
    }
    return $out;
}
function early_candidate(array $items): ?array {
    $eligible=array_filter($items,fn($i)=>$i['kind']==='docker' && $i['state']==='running' && $i['policy']['eligible'] && ($i['policy']['score']??LEVELS[$i['policy']['level']])>=0);
    usort($eligible,fn($a,$b)=>(($b['policy']['score']??LEVELS[$b['policy']['level']])<=>($a['policy']['score']??LEVELS[$a['policy']['level']])) ?: ($b['usage']<=>$a['usage']));
    return $eligible[0]??null;
}
function monitor_tick(array $s, array $c, array $items): array {
    $c=config(); // Re-read under the operation lock before any optional action.
    $before=json_file(RUN.'/monitor.json'); $step=pressure_step($before,$s,$c,time()); $state=$step['state'];
    foreach ($step['messages'] as $message) {
        $description=match($message) {
            'pressure'=>'Memory pressure has stayed high. '.round($s['available']/GIB,1).' GiB available; waiting for RAM: some '.($s['psi_some']??'unknown').'%, full '.($s['psi_full']??'unknown').'%.',
            'swap'=>'Swap is '.round(100*$s['swap_used']/max(1,$s['swap_size'])).'% full and only '.round($s['available']/GIB,1).' GiB of RAM is available, so there is little room for the next burst of memory use.',
            'pressure_recovered'=>'Memory pressure has eased.', 'swap_recovered'=>'Swap warning cleared: RAM has room again.'};
        event('pressure',$description);
        if ($c['alerts']) notify('Memory '.(str_ends_with($message,'recovered')?'recovered':'warning'),$description,str_ends_with($message,'recovered')?'normal':'warning');
    }
    if ($c['act_early'] && $state['pressure_now'] && !($state['acted_episode']??false)
        && time()-($state['last_action']??0)>=900 && time()-$state['pressure']['since'] >=$c['act_seconds']) {
        $candidate=early_candidate($items);
        if ($candidate) {
            // Re-resolve the exact container immediately before sending a graceful-only signal.
            $fresh=docker_items(config()); $candidate=$fresh[$candidate['id']]??null;
            if ($candidate && early_candidate([$candidate])) {
                $state['last_action']=time(); $state['acted_episode']=true; save_json(RUN.'/monitor.json',$state);
                $msg='Requested a graceful stop of '.$candidate['name'].' after sustained memory pressure. No forced kill or automatic restart.';
                try { run(['docker','stop','--signal','SIGTERM','--time','-1',$candidate['container_id']],35); }
                catch (\Throwable $e) { $msg.=' Stop has not completed: '.$e->getMessage().'. Check the Docker tab; Headroom will not force it.'; }
                event('early-action',$msg); notify('Early memory action',$msg);
            }
        }
    }
    save_json(RUN.'/monitor.json',$state);
    return $state;
}
