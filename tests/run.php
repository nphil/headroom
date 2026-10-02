<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/../src/lib/monitor.php';
$passed=0; $failed=0;
require_once __DIR__.'/../src/lib/boot.php';
test('container identity never trusts a shared legacy host group',function(){
    $id=str_repeat('a',64);
    same(container_root('/sys/fs/cgroup/zram-protected/nginx',$id),'');
    same(container_root('/sys/fs/cgroup/docker/'.str_repeat('b',64),$id),'');
    same(container_root('/sys/fs/cgroup/docker/'.$id.'/worker',$id),'/sys/fs/cgroup/docker/'.$id);
    same(container_root('/sys/fs/cgroup/system.slice/docker-'.$id.'.scope',$id),'/sys/fs/cgroup/system.slice/docker-'.$id.'.scope');
});
test('PSI edit changes only default entry and preserves CRLF, other parameters and undo',function(){
    $old="default menu.c32\r\nlabel Unraid OS\r\n  menu default\r\n  append initrd=/bzroot mitigations=off nvidia-drm.modeset=1\r\nlabel Safe Mode\r\n  append initrd=/bzroot unraidsafemode\r\n";
    $on=psi_boot_plan($old,true);
    same($on['entry'],'Unraid OS'); same($on['original'],null);
    same($on['content'],str_replace('nvidia-drm.modeset=1','nvidia-drm.modeset=1 psi=1',$old));
    same(psi_boot_plan($on['content'],true)['content'],$on['content']);
    same(psi_boot_plan($on['content'],null)['content'],$old);
    same(psi_boot_plan($on['content'],false)['content'],str_replace('psi=1','psi=0',$on['content']));
    rejects(fn()=>psi_boot_plan(str_replace('  menu default','',$old),true));
});
function same(mixed $actual,mixed $expected): void { if($actual!==$expected) throw new \RuntimeException('Expected '.var_export($expected,true).'; got '.var_export($actual,true)); }
function rejects(callable $f): void { try { $f(); } catch(\InvalidArgumentException|\RuntimeException) { return; } throw new \RuntimeException('Unsafe input was accepted.'); }
function test(string $name,callable $f): void { global $passed,$failed; try { $f(); $passed++; echo "PASS $name\n"; } catch(\Throwable $e) { $failed++; echo "FAIL $name: ".$e->getMessage()."\n"; } }

test('sizes reject expressions, negatives, overflow and ambiguous units',function(){
    same(size_bytes('16G'),16*GIB); same(size_bytes('256M'),256*MIB);
    foreach(['0G','-1G','1T','1.5G','16G;reboot','99999999999999G','20'] as $v) rejects(fn()=>size_bytes($v));
});
test('migration preserves deliberate priorities and removes dangerous group default',function(){
    $c=migrate(['enabled'=>'yes','zram_size'=>'16G','zram_algo'=>'zstd','zram_percent'=>'50','zram_priority'=>'100','swappiness'=>'150','oom_oom_group'=>'yes','vm_memory_min'=>'yes','oom_levels'=>'docker:Cody=low,docker:scrypted=high,proc:sshd=protected,vm:Linux=normal,docker:Temp=killfirst']);
    same($c['zram_size'],'16G'); same($c['swappiness'],150);
    same(LEVELS[$c['items']['docker:Cody']['level']],500); same(LEVELS[$c['items']['docker:scrypted']['level']],-500);
    same(LEVELS[$c['items']['proc:sshd']['level']],-1000); same(LEVELS[$c['items']['docker:Temp']['level']],1000);
    same($c['items']['docker:scrypted']['whole'],false); same($c['items']['vm:Linux']['reserve_mib'],0);
    same(migrate(['enabled'=>'no'])['zram_enabled'],false);
});
test('migration rejects unknown levels and corrupt numeric settings',function(){
    rejects(fn()=>migrate(['oom_levels'=>'docker:Cody=unrecognised']));
    rejects(fn()=>migrate(['swappiness'=>'150oops']));
    rejects(fn()=>migrate(['ssd_swap_enabled'=>'yes']));
});
test('protected apps cannot become early-action targets and core cannot lose protection',function(){
    foreach(['never','last'] as $level) rejects(fn()=>validate(['items'=>['docker:cam'=>['level'=>$level,'eligible'=>true]]]));
    rejects(fn()=>validate(['items'=>['proc:sshd'=>['level'=>'first']]]));
    rejects(fn()=>validate(['default_level'=>'never']));
    rejects(fn()=>validate(['items'=>['docker:app'=>['level'=>'early','reserve_mib'=>512]]]));
    rejects(fn()=>validate(['items'=>['vm:Linux'=>['whole'=>true]]]));
    same(validate([])['items']['proc:emhttpd']['level'],'never');
});
test('unknown keys, string booleans and unsafe names are rejected',function(){
    rejects(fn()=>validate(['act_early'=>'false'])); rejects(fn()=>validate(['surprise'=>true]));
    rejects(fn()=>validate(['items'=>['docker:../oops'=>[]]])); rejects(fn()=>validate(['disk_mount'=>'/mnt/x/../../boot']));
    rejects(fn()=>validate(['zram_priority'=>10,'disk_priority'=>10]));
});
test('swap evacuation reserves real RAM, rejects pressure and missing PSI',function(){
    $required=10*GIB+(int)ceil(64*GIB*.1);
    same(evacuation_check(8*GIB,$required,64*GIB,0.,0.)['safe'],true);
    same(evacuation_check(8*GIB,$required-1,64*GIB,0.,0.)['safe'],false);
    same(evacuation_check(0,60*GIB,64*GIB,5.,0.)['safe'],false);
    same(evacuation_check(0,60*GIB,64*GIB,0.,1.)['safe'],false);
    same(evacuation_check(0,60*GIB,64*GIB,null,0.)['safe'],false);
});
test('memory template edit preserves unrelated quoted flags and swap limit',function(){
    same(limit_params('--label "greeting=hello world" --memory 24G --cpus=4 --memory-swap=-1',8*GIB),'--label "greeting=hello world" --cpus=4 --memory-swap=-1 --memory=8589934592');
    same(limit_params('--memory=24G -m8G --restart unless-stopped',0),'--restart unless-stopped');
    same(limit_params("--label 'x=y z' -m 4G",0),"--label 'x=y z'");
    rejects(fn()=>limit_params('--memory',0));
});
test('ARC updates preserve comments and other options across multiple lines',function(){
    $value=arc_config("# leave this\noptions zfs zfs_arc_max=1 zfs_txg_timeout=5 # disk tuning\noptions zfs zfs_arc_max=2 zfs_prefetch_disable=0\n",8*GIB);
    same($value,"# leave this\noptions zfs zfs_txg_timeout=5 zfs_arc_max=8589934592 # disk tuning\noptions zfs zfs_prefetch_disable=0\n");
});
test('kernel OOM parser associates exact victim with container and limit cause',function(){
    $pending=[]; $id=str_repeat('a',64);
    same(oom_parse("[1.234] oom-kill:constraint=CONSTRAINT_MEMCG,task_memcg=/docker/$id,task=python,pid=54321,uid=0",$pending),null);
    $r=oom_parse('[1.235] Memory cgroup out of memory: Killed process 54321 (python) total-vm:123kB, anon-rss:456kB, file-rss:0kB',$pending);
    same($r['cause'],'container memory limit'); same($r['container_id'],$id); same($r['rss'],456*1024); same($r['process'],'python');
    same(oom_parse('[2] innocent log about low memory',$pending),null);
    same(oom_parse('[3] Out of memory: Killed process 7 (node) anon-rss:8kB',$pending)['cause'],'host ran out of memory');
});
test('OOM context cap never renumbers process IDs',function(){
    $pending=[]; $id=str_repeat('b',64);
    for($i=2000;$i<2101;$i++) oom_parse("oom-kill:constraint=CONSTRAINT_MEMCG,task_memcg=/docker/$id,pid=$i",$pending);
    same(oom_parse('Memory cgroup out of memory: Killed process 2100 (test) anon-rss:1kB',$pending)['container_id'],$id);
});
test('brief pressure does not alert; sustained alert occurs once then recovery',function(){
    $c=defaults(); $s=['total'=>100,'available'=>5,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>10];
    $r=pressure_step([],$s,$c,100); same($r['messages'],[]);
    $r=pressure_step($r['state'],$s,$c,159); same($r['messages'],[]);
    $r=pressure_step($r['state'],$s,$c,160); same($r['messages'],['pressure']);
    $r=pressure_step($r['state'],$s,$c,200); same($r['messages'],[]);
    $s['available']=40; $r=pressure_step($r['state'],$s,$c,201); same($r['messages'],['pressure_recovered']);
    $r=pressure_step($r['state'],$s,$c,250); same($r['messages'],[]);
});
test('full swap alone is a warning, not a graceful-stop trigger',function(){
    $s=['total'=>100,'available'=>50,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>95];
    $r=pressure_step([],$s,defaults(),100); $r=pressure_step($r['state'],$s,defaults(),160);
    same($r['messages'],['swap']); same($r['state']['pressure_now'],false);
});
test('early candidate excludes VMs, protected and unapproved apps',function(){
    $make=fn($name,$kind,$level,$eligible,$usage)=>['name'=>$name,'kind'=>$kind,'state'=>'running','usage'=>$usage,'policy'=>['level'=>$level,'eligible'=>$eligible]];
    $items=[$make('camera','docker','last',true,999),$make('host','proc','first',true,999),$make('vm','vm','first',true,999),$make('unapproved','docker','first',false,999),$make('small-first','docker','first',true,2),$make('large-first','docker','first',true,3),$make('big-normal','docker','normal',true,100)];
    same(early_candidate($items)['name'],'large-first'); same(early_candidate(array_slice($items,0,4)),null);
});
echo "$passed passed, $failed failed\n"; exit($failed?1:0);
