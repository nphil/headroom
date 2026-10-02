<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/templates.php';
// Linux's page-aligned unlimited sentinel. Docker's --memory 0 means 'leave unchanged'.
const UNLIMITED_MEMORY = 9223372036854771712;

function meminfo(): array {
    preg_match_all('/^(\w+):\s+(\d+) kB/m',text('/proc/meminfo'),$m,PREG_SET_ORDER);
    $out=[]; foreach ($m as $row) $out[$row[1]]=(int)$row[2]*1024;
    if (!isset($out['MemTotal'],$out['MemAvailable'])) throw new \RuntimeException('Memory information is unavailable.');
    return $out;
}
function psi(): array {
    $out=['some'=>null,'full'=>null];
    foreach (explode("\n",text('/proc/pressure/memory')) as $row) if (preg_match('/^(some|full) avg10=([0-9.]+)/',$row,$m)) $out[$m[1]]=(float)$m[2];
    return $out;
}
function arc(): array {
    $out=[];
    foreach (explode("\n",text('/proc/spl/kstat/zfs/arcstats')) as $line) {
        $p=preg_split('/\s+/',trim($line));
        if (count($p)===3 && ctype_digit($p[2])) $out[$p[0]]=(int)$p[2];
    }
    $hits=$out['hits']??0; $miss=$out['misses']??0;
    return ['size'=>$out['size']??0,'target'=>$out['c']??0,'max'=>(int)text('/sys/module/zfs/parameters/zfs_arc_max'),
        'hit_percent'=>$hits+$miss ? round(100*$hits/($hits+$miss),2) : null, 'present'=>is_file('/proc/spl/kstat/zfs/arcstats')];
}
function cgroup(int $pid, bool $vm=false): string {
    if (!preg_match('/^0::(\/[^\n]*)$/m',text('/proc/'.$pid.'/cgroup'),$m)) return '';
    $p=$m[1]; if ($vm) $p=preg_replace('#/emulator$#','',$p);
    if ($p==='/' || str_contains($p,'..')) return '';
    $real=realpath('/sys/fs/cgroup'.$p);
    return $real && str_starts_with($real,'/sys/fs/cgroup/') ? $real : '';
}
function cgroup_pids(string $cg): array {
    if ($cg==='' || !is_dir($cg)) return [];
    $paths=[$cg.'/cgroup.procs'];
    $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cg,\FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->getFilename()==='cgroup.procs') $paths[]=$f->getPathname();
    $pids=[];
    foreach ($paths as $path) foreach (explode("\n",text($path)) as $p) if (ctype_digit($p) && (int)$p>1) $pids[(int)$p]=true;
    return array_keys($pids);
}
function policy(array $c, string $id): array {
    return $c['items'][$id] ?? ['level'=>$c['default_level'],'whole'=>false,'reserve_mib'=>0,'eligible'=>false];
}
function container_root(string $path, string $id): string {
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return '';
    return preg_match('#^(/sys/fs/cgroup/(?:[^/]+/)*(?:docker-)?'.$id.'(?:\.scope)?)(?:/|$)#',$path,$m)?$m[1]:'';
}
function container_group(string $id, int $pid): string {
    $fromPid=container_root(cgroup($pid),$id);
    foreach (array_filter([$fromPid,'/sys/fs/cgroup/docker/'.$id,'/sys/fs/cgroup/system.slice/docker-'.$id.'.scope']) as $path) if (is_file($path.'/memory.current')) return $path;
    return '';
}
function restore_legacy_memberships(array $items): int {
    $owners=[]; $hostns=@readlink('/proc/1/ns/pid'); $moved=0;
    foreach ($items as $item) if ($item['kind']==='docker' && $item['state']==='running' && $item['cgroup']) {
        $ns=@readlink('/proc/'.$item['pid'].'/ns/pid');
        if ($ns && $ns!==$hostns) $owners[$ns][]=$item;
    }
    foreach (glob('/sys/fs/cgroup/zram-protected/*/cgroup.procs')?:[] as $path) {
        foreach (explode("\n",text($path)) as $pid) {
            if (!ctype_digit($pid)) continue;
            $ns=@readlink('/proc/'.$pid.'/ns/pid'); $matches=$owners[$ns?:'']??[];
            if (count($matches)!==1 || !str_starts_with(cgroup((int)$pid),'/sys/fs/cgroup/zram-protected/')) continue;
            if (@readlink('/proc/'.$pid.'/ns/pid')!==$ns) continue;
            try { write_kernel($matches[0]['cgroup'].'/cgroup.procs',$pid); $moved++; }
            catch (\Throwable $e) { if(is_dir('/proc/'.$pid)) throw $e; }
        }
    }
    return $moved;
}
function docker_items(array $c): array {
    if (!file_exists('/var/run/docker.sock')) return [];
    $ids=preg_split('/\s+/',run(['docker','ps','-aq','--no-trunc']));
    if (!$ids || $ids===['']) return [];
    $format='{"id":{{json .Id}},"name":{{json .Name}},"pid":{{.State.Pid}},"running":{{.State.Running}},"limit":{{.HostConfig.Memory}},"swap_limit":{{.HostConfig.MemorySwap}},"native_score":{{.HostConfig.OomScoreAdj}},"restart":{{json .HostConfig.RestartPolicy.Name}}}';
    $rows=explode("\n",run(array_merge(['docker','inspect','--format',$format],$ids)));
    $out=[]; $templates=template_index();
    foreach ($rows as $row) {
        $d=json_decode($row,true,32,JSON_THROW_ON_ERROR); $name=ltrim($d['name'],'/');
        $id='docker:'.$name; $cg=$d['running'] ? container_group($d['id'],(int)$d['pid']) : '';
        $paths=$templates[$name]??[]; $hasTemplate=count($paths)===1;
        $flag=$hasTemplate?priority_score(template_params((string)file_get_contents($paths[0]))):null;
        $desired=$hasTemplate?($flag??0):(int)$d['native_score'];
        $out[$id]=['id'=>$id,'name'=>$name,'kind'=>'docker','state'=>$d['running']?'running':'stopped',
            'container_id'=>$d['id'],'pid'=>(int)$d['pid'],'cgroup'=>$cg,'usage'=>$cg?(int)text($cg.'/memory.current'):0,
            'limit'=>(int)$d['limit']>=UNLIMITED_MEMORY?0:(int)$d['limit'],'swap_limit'=>text($cg.'/memory.swap.max'),'restart'=>$d['restart'],
            'adj'=>$d['pid']?(int)text('/proc/'.$d['pid'].'/oom_score_adj'):null,
            'whole_actual'=>$cg?(int)text($cg.'/memory.oom.group'):null,
            'reserve_min'=>$cg?(int)text($cg.'/memory.min'):0,'reserve_low'=>$cg?(int)text($cg.'/memory.low'):0,
            'native_score'=>(int)$d['native_score'],'template_available'=>$hasTemplate,'template_priority'=>$flag,
            'policy'=>docker_policy($c,$id,$desired,$hasTemplate?'template':'docker'),'pids'=>$cg?cgroup_pids($cg):[]];
    }
    return $out;
}
function host_items(array $c): array {
    $names=CORE;
    foreach (array_keys($c['items']) as $id) if (str_starts_with($id,'proc:')) $names[]=substr($id,5);
    $names=array_unique($names); $map=[]; $hostns=@readlink('/proc/1/ns/pid');
    foreach (explode("\n",run(['ps','-e','-o','pid=,comm='])) as $line) {
        if (!preg_match('/^\s*(\d+)\s+(.+)$/',$line,$m)) continue;
        $pid=(int)$m[1]; $name=trim($m[2]);
        if (!in_array($name,$names,true) || @readlink('/proc/'.$pid.'/ns/pid')!==$hostns) continue;
        $cg=text('/proc/'.$pid.'/cgroup');
        if (preg_match('#(?:/docker/|docker-[a-f0-9]+\.scope)#',$cg)) continue;
        $map[$name][]=$pid;
    }
    $out=[];
    foreach ($names as $name) {
        $id='proc:'.$name; $pids=$map[$name]??[]; $usage=0;
        foreach ($pids as $pid) if (preg_match('/^VmRSS:\s+(\d+)/m',text('/proc/'.$pid.'/status'),$m)) $usage+=(int)$m[1]*1024;
        $out[$id]=['id'=>$id,'name'=>$name,'kind'=>'proc','state'=>$pids?'running':'idle','usage'=>$usage,'limit'=>0,
            'adj'=>$pids?(int)text('/proc/'.$pids[0].'/oom_score_adj'):null,'pids'=>$pids,'cgroup'=>'',
            'core'=>in_array($name,CORE,true),'policy'=>policy($c,$id)];
    }
    return $out;
}
function vm_items(array $c): array {
    if (!file_exists('/var/run/libvirt/libvirt-sock')) return [];
    $out=[];
    foreach (explode("\n",run(['virsh','list','--all','--name'])) as $name) {
        if ($name==='' || !valid_id('vm:'.$name)) continue;
        $id='vm:'.$name; $pid=(int)text('/run/libvirt/qemu/'.$name.'.pid');
        $cg=$pid?cgroup($pid,true):'';
        $out[$id]=['id'=>$id,'name'=>$name,'kind'=>'vm','state'=>$cg?'running':'stopped','pid'=>$pid,'cgroup'=>$cg,
            'usage'=>$cg?(int)text($cg.'/memory.current'):0,'limit'=>0,'adj'=>$pid?(int)text('/proc/'.$pid.'/oom_score_adj'):null,
            'pids'=>$cg?cgroup_pids($cg):[],'policy'=>policy($c,$id),
            'reserve_min'=>$cg?(int)text($cg.'/memory.min'):0,'reserve_low'=>$cg?(int)text($cg.'/memory.low'):0];
    }
    return $out;
}
function inventory(array $c): array { return docker_items($c)+vm_items($c)+host_items($c); }
function reservation_check(array $c, array $items): void {
    $sum=0;
    foreach ($c['items'] as $id=>$p) {
        $bytes=$p['reserve_mib']*MIB; $sum+=$bytes;
        $limit=$items[$id]['limit']??0;
        if ($limit && $bytes>$limit) throw new \InvalidArgumentException('Reservation exceeds the memory limit for '.$id);
    }
    if ($sum > meminfo()['MemTotal']/4) throw new \InvalidArgumentException('Total reservations may not exceed 25% of physical RAM.');
}
function reservation_parents(array $c, array $items): void {
    $wanted=[]; $baseline=json_file(RUN.'/reservation-parents.json');
    foreach ($items as $id=>$item) {
        $p=$item['kind']==='docker'?$item['policy']:policy($c,$id); if (!$item['cgroup'] || !$p['reserve_mib']) continue;
        $field=$p['level']==='never'?'memory.min':'memory.low';
        for ($parent=dirname($item['cgroup']); str_starts_with($parent,'/sys/fs/cgroup/'); $parent=dirname($parent)) {
            $path=$parent.'/'.$field; $wanted[$path]=($wanted[$path]??0)+$p['reserve_mib']*MIB;
            if (!isset($baseline[$path])) $baseline[$path]=(int)text($path);
        }
    }
    save_json(RUN.'/reservation-parents.json',$baseline);
    foreach ($baseline as $path=>$original) if (is_file($path)) write_kernel($path,max($original,$wanted[$path]??0));
}
function apply_policy(array $c, ?array $items=null): array {
    $items ??= inventory($c); reservation_check($c,$items); $errors=[]; $changed=0; $repairs=[];
    try { reservation_parents($c,$items); } catch (\Throwable $e) { $errors[]=$e->getMessage(); }
    foreach ($items as $id=>$item) {
        if ($item['kind']==='docker' && $item['state']==='running' && !$item['cgroup']) { $errors[]=$id.': container control group could not be verified.'; continue; }
        $p=$item['kind']==='docker'?$item['policy']:policy($c,$id); $score=$p['score']??LEVELS[$p['level']];
        foreach ($item['pids'] as $pid) {
            // Recheck identity's cgroup before writing a PID that may have exited.
            if (!is_file('/proc/'.$pid.'/oom_score_adj')) continue;
            if ($item['cgroup'] && !str_starts_with(cgroup($pid).'/',$item['cgroup'].'/')) continue;
            if ($item['kind']==='proc' && (text('/proc/'.$pid.'/comm')!==$item['name'] || @readlink('/proc/'.$pid.'/ns/pid')!==@readlink('/proc/1/ns/pid'))) continue;
            if((int)text('/proc/'.$pid.'/oom_score_adj')===$score) continue;
            try { write_kernel('/proc/'.$pid.'/oom_score_adj',$score); $changed++; if($item['kind']==='docker') $repairs[$item['name']]=($repairs[$item['name']]??0)+1; }
            catch (\Throwable $e) { if (is_dir('/proc/'.$pid)) $errors[]=$id.': '.$e->getMessage(); }
        }
        $cg=$item['cgroup'];
        if (!$cg) continue;
        try {
            if ($item['kind']==='docker') write_kernel($cg.'/memory.oom.group',$p['whole']?1:0);
            write_kernel($cg.'/memory.min',$p['level']==='never'?$p['reserve_mib']*MIB:0);
            write_kernel($cg.'/memory.low',$p['level']==='last'?$p['reserve_mib']*MIB:0);
        } catch (\Throwable $e) { if (is_dir($cg)) $errors[]=$id.': '.$e->getMessage(); }
    }
    return ['processes'=>$changed,'repairs'=>$repairs,'errors'=>$errors];
}
function update_memory(string $id, int $bytes): void {
    $args=['docker','update','--memory',(string)($bytes?:UNLIMITED_MEMORY)];
    if (!$bytes) $args=array_merge($args,['--memory-swap','-1']);
    $args[]=$id; run($args);
    $pid=(int)run(['docker','inspect','--format','{{.State.Pid}}',$id]);
    if ($pid>0) {
        $cg=container_group($id,$pid); $actual=text($cg.'/memory.max');
        if (!$cg || (!$bytes && $actual!=='max') || ($bytes && ($actual==='max' || abs((int)$actual-$bytes)>=4096))) throw new \RuntimeException('Docker did not apply the requested memory limit. Check the live setting before retrying.');
    }
}
function set_limit(string $name, int $bytes): void {
    $items=docker_items(config()); $item=$items['docker:'.$name]??null;
    if (!$item) throw new \RuntimeException('Container not found.');
    integer($bytes,0,meminfo()['MemTotal'],'Memory limit');
    if ($bytes && $bytes < 64*MIB) throw new \InvalidArgumentException('Limit must be at least 64 MiB.');
    if ($bytes && $item['state']==='running' && $bytes < $item['usage']+max(256*MIB,(int)($item['usage']*.1))) throw new \RuntimeException('That limit is too close to current use. Leave at least 10% or 256 MiB of room, whichever is larger.');
    if ($bytes && $item['policy']['reserve_mib']*MIB>$bytes) throw new \InvalidArgumentException('Lower the reservation before lowering the app limit.');
    $path=template_for($name); $old=(string)file_get_contents($path);
    $xml=simplexml_load_string($old,'SimpleXMLElement',LIBXML_NONET);
    $params=limit_params((string)$xml->ExtraParams,$bytes);
    $new=template_with_params($old,$params);
    if ($new===$old && $item['limit']===$bytes) return;
    if (!simplexml_load_string($new,'SimpleXMLElement',LIBXML_NONET)) throw new \RuntimeException('Template validation failed.');
    $backup=backup($path);
    $restore=json_file(CONFIG.'/restore.json');
    if (!isset($restore['limits'][$name])) { $restore['limits'][$name]=['limit'=>$item['limit'],'params'=>(string)$xml->ExtraParams]; save_json(CONFIG.'/restore.json',$restore); }
    update_memory($item['container_id'],$bytes);
    try { atomic($path,$new,0644); }
    catch (\Throwable $e) {
        try { update_memory($item['container_id'],$item['limit']); }
        catch (\Throwable $rollback) { throw new \RuntimeException('Template save and live rollback failed. Backup: '.$backup.'; '.$rollback->getMessage()); }
        throw $e;
    }
}
function arc_config(string $old, int $bytes): string {
    $lines=explode("\n",rtrim($old)); $found=false;
    foreach ($lines as &$line) {
        if (!preg_match('/^\s*options\s+zfs(?:\s|$)/',$line)) continue;
        $parts=explode('#',$line,2); $line=rtrim(preg_replace('/\s+zfs_arc_max=\S+/','',$parts[0]));
        if (!$found) { $line.=' zfs_arc_max='.$bytes; $found=true; }
        if(isset($parts[1])) $line.=' #'.$parts[1];
    }
    unset($line);
    if (!$found) $lines[]='options zfs zfs_arc_max='.$bytes;
    return implode("\n",$lines)."\n";
}
function set_arc(int $bytes): void {
    $mem=meminfo();
    if (!is_file('/sys/module/zfs/parameters/zfs_arc_max')) throw new \RuntimeException('ZFS is not loaded.');
    if ($bytes<GIB || $bytes>$mem['MemTotal']/2) throw new \InvalidArgumentException('ARC maximum must be between 1 GiB and half of physical RAM.');
    if ($bytes < (int)text('/sys/module/zfs/parameters/zfs_arc_min')) throw new \InvalidArgumentException('Maximum cannot be below the current ARC minimum.');
    $oldRuntime=text('/sys/module/zfs/parameters/zfs_arc_max');
    $paths=['/boot/config/modprobe.d/zfs.conf','/etc/modprobe.d/zfs.conf']; $old=[];
    foreach ($paths as $path) { $old[$path]=is_file($path)?(string)file_get_contents($path):null; backup($path); }
    try {
        foreach ($paths as $path) atomic($path,arc_config($old[$path]??'',$bytes),0644);
        write_kernel('/sys/module/zfs/parameters/zfs_arc_max',$bytes);
    } catch (\Throwable $e) {
        foreach ($paths as $path) { if ($old[$path]!==null) atomic($path,$old[$path],0644); elseif (is_file($path)) unlink($path); }
        write_kernel('/sys/module/zfs/parameters/zfs_arc_max',$oldRuntime); throw $e;
    }
}
function public_items(array $items): array {
    foreach ($items as &$item) unset($item['pids'],$item['cgroup'],$item['container_id'],$item['pid']);
    unset($item); return $items;
}
function explain(array $c, array $sample, array $items): string {
    $early=[]; $last=[]; $never=[];
    foreach ($items as $id=>$item) {
        $p=$item['policy'];
        if (str_starts_with($id,'proc:')) continue;
        if (($p['score']??LEVELS[$p['level']])>0) $early[]=substr($id,strpos($id,':')+1);
        if ($p['level']==='last') $last[]=substr($id,strpos($id,':')+1);
        if ($p['level']==='never') $never[]=substr($id,strpos($id,':')+1);
    }
    $text='About '.round(($sample['available']??0)/GIB,1).' GiB is available now. ';
    $text.=($early?implode(', ',$early).' may be stopped before normal-priority apps. ':'Containers follow their native Docker priority; no flag means Normal. ');
    if ($last) $text.=implode(', ',$last).' are preferred to keep running. ';
    $text.='Core Unraid services'.($never?' and '.implode(', ',$never):'').' are excluded from memory kills. These are preferences, not a guaranteed order. App limits still apply. ';
    return $text.($c['act_early']?'Early action is enabled only for apps you explicitly allowed.':'Headroom will not proactively stop apps; early action is off.');
}
