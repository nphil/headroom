<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/../src/lib/monitor.php';
$passed=0; $failed=0;
require_once __DIR__.'/../src/lib/boot.php';
require_once __DIR__.'/../src/lib/control.php';
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
test('CPU shares edits keep one flag, custom parameters and flag-like labels',function(){
    $params='--label "--cpu-shares=7" --cpu-shares 512 --privileged -c 2048 -eNAME=value --cpu-shares=1024';
    same(cpu_shares_flag($params),1024);
    $result=cpu_shares_params($params,4096);
    same($result,'--label "--cpu-shares=7" --privileged -eNAME=value --cpu-shares=4096');
    same(cpu_shares_flag($result),4096);
    same(cpu_shares_params($result,4096),$result);
    same(cpu_shares_params($result,null),'--label "--cpu-shares=7" --privileged -eNAME=value');
    same(cpu_shares_flag(cpu_shares_params($result,null)),null);
    $plain='--hostname $(hostname -f) --label \'a --cpu-shares=9\'';
    same(cpu_shares_params($plain,null),$plain);
    same(cpu_shares_params($plain,512),$plain.' --cpu-shares=512');
    same(cpu_shares_flag('--cpu-shares=0'),0); same(cpu_shares_flag('--memory=1g'),null);
    foreach(['--cpu-shares=1','--cpu-shares=262145','--cpu-shares=abc','--cpu-shares=$(x)','--cpu-shares=-5','--cpu-shares=01','--cpu-shares'] as $bad) rejects(fn()=>cpu_shares_flag($bad));
    foreach([1,262145,-1] as $bad) rejects(fn()=>cpu_shares_params('',$bad));
});
test('CPU levels map to shares and unset means Normal',function(){
    same(CPU_LEVELS,['high'=>4096,'normal'=>1024,'low'=>512,'lowest'=>2]); same(array_keys(CPU_LABELS),array_keys(CPU_LEVELS));
    same(cpu_effective(null),1024); same(cpu_effective(0),1024); same(cpu_effective(512),512);
    same(cpu_level(null),'normal'); same(cpu_level(0),'normal'); same(cpu_level(1024),'normal'); same(cpu_level(2),'lowest'); same(cpu_level(26000),null);
});
test('CPU priority edits keep pinning, memory, OOM priority and unrelated XML',function(){
    $old=tpl('--label "a=b c" --cpu-shares=2 --oom-score-adj=500 --memory-swap=16g','8g','proof');
    $fields=template_fields($old);
    same($fields,['params'=>'--label "a=b c" --cpu-shares=2 --oom-score-adj=500 --memory-swap=16g','cpuset'=>'','memory'=>'8g']);
    $new=template_with_params($old,cpu_shares_params($fields['params'],4096));
    same(template_params($new),'--label "a=b c" --oom-score-adj=500 --memory-swap=16g --cpu-shares=4096');
    same(template_memory($new),'8g'); same(substr_count($new,'<CPUset'),1);
    $normal=template_with_params($new,cpu_shares_params(template_params($new),null));
    same(template_params($normal),'--label "a=b c" --oom-score-adj=500 --memory-swap=16g');
    $pinned='<Container><Name>x</Name><CPUset>0,8</CPUset><ExtraParams>-c 512</ExtraParams></Container>';
    same(template_fields($pinned),['params'=>'-c 512','cpuset'=>'0,8','memory'=>'']);
    $edited=template_with_params($pinned,cpu_shares_params('-c 512',1024));
    same(template_params($edited),'--cpu-shares=1024'); same(template_fields($edited)['cpuset'],'0,8');
    rejects(fn()=>template_fields('<NotAContainer/>'));
});
test('template memory text prefers a --memory flag, as Docker reads it',function(){
    same(template_limit_text(['params'=>'--memory=4g -m 2g','cpuset'=>'','memory'=>'8g']),'2g');
    same(template_limit_text(['params'=>'--cpus=1','cpuset'=>'','memory'=>'8g']),'8g');
    same(template_limit_text(['params'=>'','cpuset'=>'','memory'=>'']),null);
});
test('swap limits migrate additively and reject unsafe values',function(){
    $old=defaults(); unset($old['swap_limits']);
    same(validate($old)['swap_limits'],[]); same(validate([])['swap_limits'],[]);
    same(validate(['items'=>['docker:cam'=>['level'=>'early']]])['swap_limits'],[]);
    $c=validate(['swap_limits'=>['zeta'=>2*GIB,'alpha'=>0,'Mid_1'=>SWAP_LIMIT_MIN]]);
    same($c['swap_limits'],['Mid_1'=>SWAP_LIMIT_MIN,'alpha'=>0,'zeta'=>2*GIB]);
    same(validate($c)['swap_limits'],$c['swap_limits']);
    same(validate(['swap_limits'=>['app'=>1024*GIB]])['swap_limits'],['app'=>1024*GIB]);
    foreach([['app'=>SWAP_LIMIT_MIN-1],['app'=>-1],['app'=>'1g'],['app'=>1.5],['app'=>null],['app'=>1025*GIB],['../x'=>GIB],[''=>GIB],['vm:x'=>GIB],['a;b'=>GIB]] as $bad) rejects(fn()=>validate(['swap_limits'=>$bad]));
    rejects(fn()=>validate(['swap_limits'=>'none'])); rejects(fn()=>validate(['swap_limit'=>[]]));
});
test('swap read-back accepts only the kernel page rounding and never trusts a foreign path',function(){
    same(swap_limit_matches(null,'max'),true); same(swap_limit_matches(null,'0'),false);
    same(swap_limit_matches(0,'0'),true); same(swap_limit_matches(0,'max'),false);
    same(swap_limit_matches(GIB,(string)GIB),true); same(swap_limit_matches(GIB+100,(string)GIB),true);
    same(swap_limit_matches(GIB+65536,(string)GIB),false); same(swap_limit_matches(GIB,(string)(GIB+4096)),false);
    same(swap_limit_matches(GIB,'max'),false); same(swap_limit_matches(GIB,'garbage'),false);
    rejects(fn()=>write_swap_limit('/tmp/not-a-cgroup',GIB)); rejects(fn()=>write_swap_limit('/sys/fs/cgroup/docker/../../etc',null));
    same(swap_state('',GIB)['supported'],null); same(swap_state('',GIB)['ok'],false); same(swap_state('',null)['ok'],true);
});
test('memory priority requests clear the options a level cannot use',function(){
    $saved=['level'=>'early','whole'=>true,'reserve_mib'=>0,'eligible'=>true];
    same(memory_priority_policy($saved,'never'),['level'=>'never','whole'=>true,'reserve_mib'=>0,'eligible'=>false]);
    same(memory_priority_policy(['level'=>'last','whole'=>false,'reserve_mib'=>512,'eligible'=>false],'early'),['level'=>'early','whole'=>false,'reserve_mib'=>0,'eligible'=>false]);
    validate(['items'=>['docker:cam'=>memory_priority_policy($saved,'never')]]);
    rejects(fn()=>memory_priority_policy($saved,'default')); rejects(fn()=>memory_priority_policy($saved,'custom'));
});
test('automation requests are validated before anything runs',function(){
    same(control_parse('list',[]),[]); same(control_parse('list',['name'=>'plex']),['name'=>'plex']);
    same(control_parse('set-memory-limit',['name'=>'plex','limit'=>'8g']),['name'=>'plex','bytes'=>8*GIB]);
    same(control_parse('set-memory-limit',['name'=>'plex','limit'=>'none'])['bytes'],0);
    same(control_parse('set-memory-limit',['name'=>'plex','limit'=>536870912])['bytes'],536870912);
    same(control_parse('set-swap-limit',['name'=>'plex','limit'=>'unlimited']),['name'=>'plex','bytes'=>null]);
    same(control_parse('set-swap-limit',['name'=>'plex','limit'=>'NONE'])['bytes'],0);
    same(control_parse('set-swap-limit',['name'=>'plex','limit'=>'2g'])['bytes'],2*GIB);
    same(control_parse('set-memory-priority',['name'=>'plex','level'=>'default']),['name'=>'plex','level'=>'default']);
    same(control_parse('set-memory-priority',['name'=>'plex','level'=>'never'])['level'],'never');
    same(control_parse('set-cpu-priority',['name'=>'plex','level'=>'lowest'])['level'],'lowest');
    $bad=[
        ['set-memory-limit',['name'=>'plex']],['set-memory-limit',['limit'=>'8g']],['set-memory-limit',['name'=>'plex','limit'=>'8g','extra'=>1]],
        ['set-memory-limit',['name'=>'plex','limit'=>'8 gigs']],['set-memory-limit',['name'=>'plex','limit'=>'$(reboot)']],['set-memory-limit',['name'=>'plex','limit'=>-1]],
        ['set-memory-limit',['name'=>'plex','limit'=>1.5]],['set-memory-limit',['name'=>'plex','limit'=>true]],['set-memory-limit',['name'=>'plex','limit'=>null]],['set-memory-limit',['name'=>'plex','limit'=>['8g']]],
        ['set-swap-limit',['name'=>'plex','limit'=>'lots']],['set-swap-limit',['name'=>'plex','limit'=>null]],
        ['set-memory-priority',['name'=>'plex','level'=>'custom']],['set-memory-priority',['name'=>'plex','level'=>'high']],['set-memory-priority',['name'=>'plex','level'=>5]],
        ['set-cpu-priority',['name'=>'plex','level'=>'default']],['set-cpu-priority',['name'=>'plex','level'=>'custom']],['set-cpu-priority',['name'=>'plex','level'=>['high']]],
        ['set-cpu-priority',['name'=>'../etc','level'=>'high']],['set-cpu-priority',['name'=>'a;b','level'=>'high']],['set-cpu-priority',['name'=>'$(x)','level'=>'high']],
        ['set-cpu-priority',['name'=>"plex\n",'level'=>'high']],['set-cpu-priority',['name'=>7,'level'=>'high']],['set-cpu-priority',['name'=>'','level'=>'high']],
        ['list',['name'=>'a b;c']],['list',['limit'=>'8g']],['reboot',[]],['set-cpu-priority',[]],
    ];
    foreach($bad as [$command,$input]) { try { control_parse($command,$input); } catch(\InvalidArgumentException) { continue; } throw new \RuntimeException('Accepted '.$command.' '.json_encode($input)); }
});
test('automation failures map to stable codes and exit statuses',function(){
    same(control_failure(new \InvalidArgumentException('x')),['invalid_argument',2]); same(control_failure(new \JsonException('x')),['invalid_argument',2]);
    same(control_failure(new NotFound('x')),['not_found',3]); same(control_failure(new \RuntimeException('x')),['failed',4]);
    same(control_failure(new OperationBusy('x')),['busy',5]); same(control_failure(new \TypeError('x')),['internal_error',1]);
});
test('container records report configured and live values separately',function(){
    $item=['name'=>'plex','state'=>'running','container_id'=>str_repeat('a',64),'template_available'=>true,'limit'=>8*GIB,'usage'=>GIB,'limit_template'=>'8g','adj'=>0,
        'policy'=>['level'=>'normal','score'=>0,'custom'=>false,'source'=>'template'],'cpu_shares'=>0,'cpu_weight'=>100,'cpu_template'=>4096,'cpu_template_error'=>null,
        'cpuset'=>'0-3','cpuset_template'=>'0,8','swap'=>['supported'=>true,'wanted'=>GIB,'live'=>'max','current'=>0,'ok'=>false,'error'=>null]];
    $r=container_record($item);
    same($r['container_id'],'aaaaaaaaaaaa'); same($r['cpu_priority']['level'],'high'); same($r['cpu_priority']['live_shares'],1024); same($r['cpu_priority']['applied'],false);
    same($r['cpu_pinning'],['live'=>'0-3','template'=>'0,8','read_only'=>true,'manage_url'=>'/Settings/CPUset']);
    same($r['swap_limit']['mode'],'limited'); same($r['swap_limit']['applied'],false); same($r['swap_limit']['live_bytes'],null); same($r['swap_limit']['error']!==null,true);
    $r=container_record(array_replace($item,['cpu_template'=>26000,'swap'=>['supported'=>true,'wanted'=>null,'live'=>'max','current'=>5,'ok'=>true,'error'=>null]]));
    same($r['cpu_priority']['level'],'custom'); same($r['swap_limit']['mode'],'unlimited'); same($r['swap_limit']['error'],null);
    same(container_record(array_replace($item,['state'=>'stopped']))['swap_limit']['applied'],null);
    same(json_decode(json_encode($r),true),$r);
});
test('version command reports support and takes no input',function(){
    same(control_parse('version',[]),[]);
    rejects(fn()=>control_parse('version',['name'=>'plex']));
    $v=control_version();
    same($v['interface'],1); same(in_array('version',$v['commands'],true),true); same(in_array('swap_limit',$v['features'],true),true);
    same($v['cpu_levels'],['high','normal','low','lowest']); same($v['memory_levels'],['never','last','normal','early','first','default']);
    same(CONTROL_READ_ONLY,['version','list']);
});
test('memory priority options are validated and layered after the level rules',function(){
    $ok=control_parse('set-memory-priority',['name'=>'plex','level'=>'last','reserve_mib'=>512,'whole'=>false,'eligible'=>false]);
    same($ok,['name'=>'plex','level'=>'last','options'=>['reserve_mib'=>512,'whole'=>false,'eligible'=>false]]);
    same(control_parse('set-memory-priority',['name'=>'plex','level'=>'early'])['level'],'early');
    foreach([['reserve_mib'=>-1],['reserve_mib'=>65537],['reserve_mib'=>'512'],['reserve_mib'=>1.5],['whole'=>'yes'],['eligible'=>1],['level'=>'default','whole'=>true]] as $extra)
        rejects(fn()=>control_parse('set-memory-priority',array_replace(['name'=>'plex','level'=>'never'],$extra)));
    rejects(fn()=>control_parse('set-cpu-priority',['name'=>'plex','level'=>'low','reserve_mib'=>1]));
    $saved=['level'=>'early','whole'=>false,'reserve_mib'=>0,'eligible'=>true];
    $p=memory_priority_policy($saved,'last',['reserve_mib'=>512]);
    same($p['reserve_mib'],512); same($p['eligible'],false); validate(['items'=>['docker:cam'=>$p]]);
    rejects(fn()=>validate(['items'=>['docker:cam'=>memory_priority_policy($saved,'early',['reserve_mib'=>512])]]));
    rejects(fn()=>validate(['items'=>['docker:cam'=>memory_priority_policy($saved,'never',['eligible'=>true])]]));
    $item=['name'=>'plex','state'=>'running','container_id'=>str_repeat('a',64),'template_available'=>true,'limit'=>0,'usage'=>0,'limit_template'=>null,'adj'=>-500,
        'policy'=>['level'=>'last','score'=>-500,'custom'=>false,'source'=>'template','reserve_mib'=>512,'whole'=>true,'eligible'=>false],'cpu_shares'=>0,'cpu_weight'=>100,'cpu_template'=>null,'cpu_template_error'=>null,
        'cpuset'=>null,'cpuset_template'=>null,'swap'=>['supported'=>true,'wanted'=>null,'live'=>'max','current'=>0,'ok'=>true,'error'=>null]];
    $mp=container_record($item)['memory_priority'];
    same([$mp['reserve_mib'],$mp['whole'],$mp['eligible']],[512,true,false]);
});
echo "$passed passed, $failed failed\n"; exit($failed?1:0);
