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
test('full swap with plenty of free RAM is healthy: no warning, only an informational flag',function(){
    // Mirrors this server: zram ~91% full, ~34% of RAM available, PSI idle.
    $s=['total'=>100,'available'=>34,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>91];
    $r=pressure_step([],$s,defaults(),100); $r=pressure_step($r['state'],$s,defaults(),400);
    same($r['messages'],[]); same($r['state']['swap_now'],false); same($r['state']['pressure_now'],false); same($r['state']['swap_full'],true);
    $s['psi_some']=null; $s['psi_full']=null; $r=pressure_step($r['state'],$s,defaults(),800); // PSI not enabled yet
    same($r['messages'],[]); same($r['state']['swap_now'],false);
});
test('full swap with low RAM warns once, is not a graceful-stop trigger, and recovers',function(){
    $s=['total'=>100,'available'=>12,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>95];
    $r=pressure_step([],$s,defaults(),100); same($r['messages'],[]);
    $r=pressure_step($r['state'],$s,defaults(),160); same($r['messages'],['swap']); same($r['state']['pressure_now'],false); same($r['state']['swap_now'],true);
    $r=pressure_step($r['state'],$s,defaults(),200); same($r['messages'],[]);
    $s['available']=40; $r=pressure_step($r['state'],$s,defaults(),210); same($r['messages'],['swap_recovered']);
});
test('full swap with real waiting-for-RAM pressure warns even when RAM looks adequate',function(){
    $s=['total'=>100,'available'=>30,'psi_some'=>6.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>95];
    $r=pressure_step([],$s,defaults(),100); $r=pressure_step($r['state'],$s,defaults(),160);
    same($r['messages'],['swap']);
});
test('low RAM without full swap does not raise the swap warning',function(){
    $s=['total'=>100,'available'=>12,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>40];
    $r=pressure_step([],$s,defaults(),100); $r=pressure_step($r['state'],$s,defaults(),160);
    same($r['messages'],[]); same($r['state']['swap_now'],false);
});
test('swap warning does not flap around its thresholds',function(){
    $c=defaults(); $mk=fn($avail,$used)=>['total'=>100,'available'=>$avail,'psi_some'=>0.,'psi_full'=>0.,'swap_size'=>100,'swap_used'=>$used];
    $r=pressure_step([],$mk(14,90),$c,0); $r=pressure_step($r['state'],$mk(14,90),$c,60); same($r['messages'],['swap']);
    $t=60; $sent=[];
    // Just inside the clear margins on both axes (RAM 16-18% vs 15% trigger; swap 81-84% vs 85%): stays raised, silent.
    foreach ([[16,84],[18,83],[16,81],[14,90],[18,82]] as [$avail,$used]) { $r=pressure_step($r['state'],$mk($avail,$used),$c,$t+=15); $sent=array_merge($sent,$r['messages']); same($r['state']['swap_now'],true); }
    same($sent,[]);
    // Clearly better: RAM above 18.75% -> recovered once; and it needs the full trigger again to return.
    $r=pressure_step($r['state'],$mk(19,90),$c,$t+=15); same($r['messages'],['swap_recovered']);
    $r=pressure_step($r['state'],$mk(16,90),$c,$t+=15); same($r['state']['swap_now'],false); same($r['messages'],[]);
    // Swap dropping below 80% also clears.
    $r=pressure_step($r['state'],$mk(14,90),$c,$t+=15); $r=pressure_step($r['state'],$mk(14,90),$c,$t+=60); same($r['messages'],['swap']);
    $r=pressure_step($r['state'],$mk(14,79),$c,$t+=15); same($r['messages'],['swap_recovered']);
});
test('early candidate excludes VMs, protected and unapproved apps',function(){
    $make=fn($name,$kind,$level,$eligible,$usage)=>['name'=>$name,'kind'=>$kind,'state'=>'running','usage'=>$usage,'policy'=>['level'=>$level,'eligible'=>$eligible]];
    $items=[$make('camera','docker','last',true,999),$make('host','proc','first',true,999),$make('vm','vm','first',true,999),$make('unapproved','docker','first',false,999),$make('small-first','docker','first',true,2),$make('large-first','docker','first',true,3),$make('big-normal','docker','normal',true,100)];
    same(early_candidate($items)['name'],'large-first'); same(early_candidate(array_slice($items,0,4)),null);
});
test('native priority replacement preserves flag-like labels and removes duplicates',function(){
    $params='--label "--oom-score-adj=777" --memory=4G --oom-score-adj -500 --privileged --oom-score-adj=1000 -eNAME=value';
    $result=priority_params($params,500);
    same($result,'--label "--oom-score-adj=777" --memory=4G --privileged -eNAME=value --oom-score-adj=500');
    same(priority_score($result),500);
    same(priority_score(priority_params($result,null)),null);
    same(priority_score('--oom-score-adj=-500 --oom-score-adj 0'),0);
    same(priority_score('"--oom-score-adj"="-1000"'),-1000);
    $expression='--hostname $(hostname -f) --label \'literal --oom-score-adj=42\' --oom-score-adj=-500';
    same(priority_params($expression,500),'--hostname $(hostname -f) --label \'literal --oom-score-adj=42\' --oom-score-adj=500');
    same(priority_score('--hostname "$(printf \'host name\')" --oom-score-adj=500'),500);
    rejects(fn()=>priority_params('--label "unclosed',0));
    rejects(fn()=>priority_score('--oom-score-adj=1001'));
    rejects(fn()=>priority_score('--oom-score-adj=$(something)'));
});
test('template edits retain unrelated XML and priority when setting a memory cap',function(){
    $old='<Container version="2"><Name>proof</Name><ExtraParams>--label &quot;a=b c&quot; --oom-score-adj=-500</ExtraParams><PostArgs>sleep 99</PostArgs></Container>';
    $new=template_with_params($old,limit_params(template_params($old),512*MIB));
    same(template_params($new),'--label "a=b c" --oom-score-adj=-500 --memory=536870912');
    same(str_replace(' --memory=536870912','',$new),$old);
    same(template_params(template_with_params('<Container><Name>x</Name><ExtraParams/></Container>','--oom-score-adj=500')),'--oom-score-adj=500');
});
test('native priority overrides stale saved level and protects custom negative scores',function(){
    $c=validate(['items'=>['docker:camera'=>['level'=>'early','eligible'=>true]]]);
    $p=docker_policy($c,'docker:camera',-500,'template');
    same($p['score'],-500); same($p['level'],'last'); same($p['eligible'],false);
    $p=docker_policy($c,'docker:camera',-123,'docker');
    same($p['score'],-123); same($p['custom'],true); same($p['eligible'],false);
    same(docker_policy($c,'docker:camera',0,'template')['level'],'normal');
});
function tpl(string $extra, ?string $memory=null, string $name='app'): string {
    return '<?xml version="1.0"?>'."\n<Container version=\"2\">\n  <Name>$name</Name>\n  <CPUset></CPUset>\n".($memory===null?'':"  <Memory>$memory</Memory>\n").'  <ExtraParams>'.htmlspecialchars($extra,ENT_XML1|ENT_QUOTES,'UTF-8')."</ExtraParams>\n  <PostArgs></PostArgs>\n</Container>\n";
}
test('Unraid version gate: 7.4.0 and later use the Memory field, earlier and pre-release builds do not',function(){
    same(native_memory_supported('7.3.3'),false); same(native_memory_supported('7.4.0'),true); same(native_memory_supported('7.4.1'),true);
    same(native_memory_supported('7.10.0'),true); same(native_memory_supported('8.0.0'),true); same(native_memory_supported('7.4.0-rc.1'),false);
    same(native_memory_supported('7.4.1-beta.2'),true); same(native_memory_supported('7.4'),true); same(native_memory_supported(''),false); same(native_memory_supported('garbage'),false);
    $f=tempnam(sys_get_temp_dir(),'hrver'); try { file_put_contents($f,"version=\"7.4.0\"\n"); same(unraid_version($f),'7.4.0'); same(native_memory_supported(unraid_version($f)),true); file_put_contents($f,"version=\"7.3.3\"\n"); same(unraid_version($f),'7.3.3'); same(unraid_version('/nonexistent'),''); } finally { unlink($f); }
});
test('Docker memory sizes parse like docker run and format for the Unraid field',function(){
    same(memory_bytes('24g'),24*GIB); same(memory_bytes('2G'),2*GIB); same(memory_bytes('256m'),256*MIB); same(memory_bytes('128M'),128*MIB);
    same(memory_bytes('1536m'),1536*MIB); same(memory_bytes('1.5g'),1536*MIB); same(memory_bytes('2GB'),2*GIB); same(memory_bytes('2 GiB'),2*GIB);
    same(memory_bytes('4096'),4096); same(memory_bytes('0'),0); same(memory_bytes('1t'),1024*GIB);
    foreach(['','-1','g','1.5','1.0000001g','abc','$(echo 4g)','4gb extra','1e3'] as $bad) same(memory_bytes($bad),null);
    same(memory_field(24*GIB),'24g'); same(memory_field(2*GIB),'2g'); same(memory_field(256*MIB),'256m'); same(memory_field(1536*MIB),'1536m');
    same(memory_field(3*1024),'3k'); same(memory_field(1000),'1000'); same(memory_field(10737418240),'10g');
    foreach([24*GIB,512*MIB,1536*MIB,5000,12345678] as $n) { same(memory_bytes(memory_field($n)),$n); if(!preg_match('/^[0-9]+[bkmgBKMG]?$/D',memory_field($n))) throw new \RuntimeException('Value does not fit the Unraid field.'); }
});
test('the 12 live ExtraParams memory forms migrate into the Memory field without losing other options',function(){
    $cases=[ // name => [ExtraParams, expected remaining ExtraParams, expected Memory]
        'Cody'=>['--memory=24g --memory-swap=-1','--memory-swap=-1','24g'],
        'KokoroTTS'=>['--memory=4g --cpus=2','--cpus=2','4g'],
        'MariaDB-Official'=>['--memory=2G','','2g'],
        'NginxProxyManager'=>['--memory=1G --restart=unless-stopped','--restart=unless-stopped','1g'],
        'Updates'=>['--memory=256m --memory-reservation=128m','--memory-reservation=128m','256m'],
        'hypermind'=>['--memory 2G','','2g'],
        'kestrel-audio'=>['--memory=4g --memory-swap=8g --memory-reservation=2g','--memory-swap=8g --memory-reservation=2g','4g'],
        'lumashow-logs'=>['--memory=128m','','128m'],
        'reprise'=>['--oom-score-adj=-500 --memory=6g','--oom-score-adj=-500','6g'],
        'scrypted'=>['--memory=10g --label "a=b c"','--label "a=b c"','10g'],
        'wyoming-openai'=>['--memory=1g','','1g'],
        'short-flag'=>['-m 4G --cpus=1','--cpus=1','4g'],
    ];
    same(count($cases),12);
    foreach($cases as $name=>[$extra,$left,$memory]) {
        $old=tpl($extra,'',$name); $plan=memory_migration_plan($old);
        same($plan['action'],'move'); $xml=simplexml_load_string($plan['content']);
        same((string)$xml->ExtraParams,$left); same(trim((string)$xml->Memory),$memory); same((string)$xml->Name,$name); same((string)$xml->PostArgs,'');
        same(memory_migration_plan($plan['content'])['action'],'none');
    }
    // Other spellings and a template that has no <Memory> element at all (older Unraid saved it).
    foreach([['-m4G','4g'],['--memory="6g"','6g'],['-m 1536m','1536m'],['--memory=1.5g','1536m'],['--memory=2147483648','2g'],['--memory=1000','1000'],['--memory=1g -m 1G','1g']] as [$extra,$memory]) {
        $plan=memory_migration_plan(tpl($extra)); same($plan['action'],'move'); $xml=simplexml_load_string($plan['content']);
        same((string)$xml->ExtraParams,''); same(trim((string)$xml->Memory),$memory); same(count($xml->Memory),1);
    }
});
test('migration leaves ambiguous templates untouched and says why',function(){
    foreach([
        'conflicting Memory field'=>tpl('--memory=2g','4g'),
        'conflict with unlimited flag'=>tpl('--memory=0','4g'),
        'two different limits'=>tpl('--memory=2g -m 4g'),
        'inexact value'=>tpl('--memory=1.0000001g'),
        'shell expression'=>tpl('--memory=$(cat /tmp/limit)'),
        'empty value'=>tpl('--memory='),
        'unreadable Memory field'=>tpl('--memory=2g','lots'),
        'dangling flag'=>tpl('--label x --memory'),
        'unclosed quote'=>tpl('--label "x --memory=2g'),
        'two Memory elements'=>str_replace('<PostArgs>','<Memory>2g</Memory><PostArgs>',tpl('--memory=2g','2g')),
    ] as $why=>$content) { $plan=memory_migration_plan($content); same($plan['action'].' '.$why,'skip '.$why); if(($plan['reason']??'')==='') throw new \RuntimeException('No reason given for: '.$why); }
    // Not a problem: nothing to move, equal values, a zero flag next to an empty field.
    same(memory_migration_plan(tpl('--memory-swap=-1 --memory-reservation=1g','2g'))['action'],'none');
    same(memory_migration_plan(tpl('--label "--memory=2g"'))['action'],'none');
    same(memory_migration_plan('<Container><Name>x</Name></Container>')['action'],'none'); same(memory_migration_plan('not xml')['action'],'none');
    $same=memory_migration_plan(tpl('--memory=2g --cpus=1','2G')); same($same['action'],'move'); same(trim((string)simplexml_load_string($same['content'])->Memory),'2G'); same((string)simplexml_load_string($same['content'])->ExtraParams,'--cpus=1');
    $zero=memory_migration_plan(tpl('--memory=0 --cpus=1','')); same($zero['action'],'move'); same(trim((string)simplexml_load_string($zero['content'])->Memory),'');
    $stale=memory_migration_plan(tpl('--memory=2g','0')); same($stale['action'],'move'); same(trim((string)simplexml_load_string($stale['content'])->Memory),'2g');
});
test('migrating a directory backs up first, writes atomically, skips conflicts and changes nothing else',function(){
    $dir=sys_get_temp_dir().'/hrmig-'.bin2hex(random_bytes(4)); mkdir($dir); $log=[];
    try {
        $files=['a'=>tpl('--memory=24g --memory-swap=-1','','Cody'),'b'=>tpl('--memory 2G','','hypermind'),'c'=>tpl('--memory=2g','4g','conflict'),'d'=>tpl('--cpus=1','1g','plain'),'e'=>tpl('--memory=$(x)','','dynamic')];
        foreach($files as $k=>$v) file_put_contents("$dir/$k.xml",$v);
        file_put_contents("$dir/notes.txt",'leave me'); file_put_contents("$dir/broken.xml",'<Container><Name>');
        $result=migrate_native_memory($dir,function($path)use(&$log,$dir){ $log[]=['backup',basename($path)]; $copy=$dir.'/backups/'.basename($path); @mkdir($dir.'/backups'); copy($path,$copy); return $copy; });
        same($result['moved'],['Cody'=>'24g','hypermind'=>'2g']); same(array_keys($result['skipped']),['conflict','dynamic']); same($result['errors'],[]);
        same(array_column($log,1),['a.xml','b.xml']);
        same(file_get_contents("$dir/backups/a.xml"),$files['a']); same(file_get_contents("$dir/backups/b.xml"),$files['b']);
        foreach(['c','d','e'] as $k) same(file_get_contents("$dir/$k.xml"),$files[$k]);
        same(file_get_contents("$dir/notes.txt"),'leave me');
        $a=simplexml_load_file("$dir/a.xml"); same((string)$a->ExtraParams,'--memory-swap=-1'); same((string)$a->Memory,'24g'); same(substr(sprintf('%o',fileperms("$dir/a.xml")),-3),'644');
        same(glob($dir.'/.headroom-*')?:[],[]);
        $again=migrate_native_memory($dir,fn($p)=>throw new \RuntimeException('Nothing should be backed up twice.')); same($again['moved'],[]); same($again['errors'],[]);
        // A failed backup stops that template from being written.
        file_put_contents("$dir/f.xml",tpl('--memory=1g','','late'));
        $failed=migrate_native_memory($dir,fn($p)=>throw new \RuntimeException('disk full')); same($failed['moved'],[]); same(array_keys($failed['errors']),['late']);
        same(template_params((string)file_get_contents("$dir/f.xml")),'--memory=1g');
    } finally { foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); rmdir($dir); }
});
test('on 7.4+ a limit lives only in the Memory field; before that only in ExtraParams',function(){
    $old=tpl('--label "a=b c" --memory=24g --memory-swap=-1 --oom-score-adj=-500','','Cody');
    $new=template_with_limit($old,8*GIB,true); $xml=simplexml_load_string($new);
    same((string)$xml->ExtraParams,'--label "a=b c" --memory-swap=-1 --oom-score-adj=-500'); same((string)$xml->Memory,'8g');
    same(template_memory(template_with_limit($new,1536*MIB,true)),'1536m'); same(template_params(template_with_limit($new,1536*MIB,true)),(string)$xml->ExtraParams);
    $cleared=template_with_limit($new,0,true); same(template_memory($cleared),''); same(template_params($cleared),'--label "a=b c" --memory-swap=-1 --oom-score-adj=-500');
    same(substr_count($cleared,'<Memory'),1);
    $missing=template_with_limit(tpl('-m 2g'),3*GIB,true); same(template_memory($missing),'3g'); same(template_params($missing),''); same(substr_count($missing,'<Memory'),1);
    same(template_with_limit(tpl('--cpus=1'),0,true),tpl('--cpus=1')); // nothing to clear, nothing invented
    same(template_memory(template_with_memory('<Container><Name>x</Name><Memory a="b"/></Container>','512m')),'512m');
    rejects(fn()=>template_with_memory(tpl(''),'2 gigs')); rejects(fn()=>template_with_memory(tpl('','1g'),'$(x)'));
    // Older Unraid keeps the existing behaviour and never touches <Memory>.
    $legacy=template_with_limit(tpl('--memory=24g --cpus=1','','Cody'),8*GIB,false); $xml=simplexml_load_string($legacy);
    same((string)$xml->ExtraParams,'--cpus=1 --memory=8589934592'); same(trim((string)$xml->Memory),'');
    $legacyClear=template_with_limit(tpl('--memory=24g --cpus=1','2g'),0,false); same(template_params($legacyClear),'--cpus=1'); same(template_memory($legacyClear),'2g');
});
echo "$passed passed, $failed failed\n"; exit($failed?1:0);
