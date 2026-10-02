#!/usr/bin/php
<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/monitor.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!is_dir(RUN)) mkdir(RUN,0755,true);
$singleton=fopen(RUN.'/daemon.lock','c');
if (!$singleton || !flock($singleton,LOCK_EX|LOCK_NB)) exit;
write_kernel('/proc/self/oom_score_adj',-1000);
save_json(RUN.'/daemon.json',['pid'=>getmypid(),'started'=>time()]);
$alive=true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM,function()use(&$alive){$alive=false;});
pcntl_signal(SIGINT,function()use(&$alive){$alive=false;});
$streams=[]; $pending=[]; $containers=[]; $items=[]; $itemsUpdated=0.; $errors=[]; $dev='';
$nextInventory=0; $nextSample=0; $nextHistory=0; $nextRam=0; $nextOwnership=0; $ram=[];
$watchdogReady=false; $nextWatchdog=0;
function open_stream(array $command): array {
    $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
    if (!is_resource($p)) throw new \RuntimeException('Cannot open event stream.');
    stream_set_blocking($pipes[1],false);
    return ['proc'=>$p,'pipe'=>$pipes[1],'buffer'=>'','started'=>time()];
}
try {
    $c=config();
    // Recover recent kernel incidents once, without sending historical alerts.
    $initial=run(['dmesg','--time-format','raw'],10);
    $initialItems=docker_items($c);
    foreach ($initialItems as $i) $containers[$i['container_id']]=$i['name'];
    foreach (explode("\n",$initial) as $line) record_oom($line,$pending,$containers,false);
    $pending=[];
    event('service','Headroom '.VERSION.' started.');
    while ($alive) {
        $now=time();
        if (!$watchdogReady && $now>=$nextWatchdog && is_file('/var/log/plugins/headroom.plg')) {
            try { run(['/usr/local/sbin/update_cron']); $watchdogReady=true; unset($errors['watchdog']); }
            catch (\Throwable $e) { $errors['watchdog']=$e->getMessage(); }
            $nextWatchdog=$now+60;
        }
        foreach (['docker'=>['docker','events','--filter','type=container','--format','{{json .}}'],
            'kernel'=>['dmesg','--follow','--time-format','raw']] as $key=>$command) {
            if (isset($streams[$key]) && !proc_get_status($streams[$key]['proc'])['running']) {
                fclose($streams[$key]['pipe']); proc_close($streams[$key]['proc']); unset($streams[$key]);
                $errors[$key]='Event stream disconnected; retrying. Periodic protection remains active.';
                $nextInventory=0;
            }
            if (!isset($streams[$key]) && $now>=($errors[$key.'_retry']??0)) {
                try { $streams[$key]=open_stream($command); unset($errors[$key]); }
                catch (\Throwable $e) { $errors[$key]=$e->getMessage(); }
                $errors[$key.'_retry']=$now+10;
            }
        }
        foreach ($streams as $key=>&$stream) {
            $chunk=stream_get_contents($stream['pipe']);
            $stream['buffer'].=$chunk;
            if (strlen($stream['buffer'])>MIB) $stream['buffer']='';
            $count=0;
            while (($pos=strpos($stream['buffer'],"\n"))!==false && $count++<200) {
                $line=substr($stream['buffer'],0,$pos); $stream['buffer']=substr($stream['buffer'],$pos+1);
                if ($key==='kernel') { try { record_oom($line,$pending,$containers,$c['alerts']); } catch (\Throwable $e) { $errors['notifications']=$e->getMessage(); } }
                else {
                    $d=json_decode($line,true); $action=$d['Action']??$d['status']??'';
                    if (in_array($action,['start','restart','unpause','exec_start','die','destroy','update'],true)) {
                        $nextInventory=0;
                        if (in_array($action,['start','restart'],true)) event('docker','Container '.($d['Actor']['Attributes']['name']??'unknown').' '.$action.'; checking native priority.');
                    }
                }
            }
        }
        unset($stream);
        if ($now>=$nextInventory) {
            $nextInventory=$now+15;
            try {
                locked(function()use(&$c,&$items,&$itemsUpdated,&$containers,&$errors){
                    $c=config(); $items=inventory($c); $itemsUpdated=microtime(true); $result=apply_policy($c,$items);
                    foreach($result['repairs'] as $name=>$count) event('priority-repair',$name.': safety net corrected '.$count.' process score(s) to match its native template/Docker priority.');
                    if ($result['errors']) $errors['protection']=implode('; ',$result['errors']); else unset($errors['protection']);
                    foreach ($items as $i) if ($i['kind']==='docker') $containers[$i['container_id']]=$i['name'];
                    if (count($containers)>500) $containers=array_slice($containers,-500,null,true);
                },false);
                unset($errors['inventory']);
            } catch (OperationBusy) { $nextInventory=$now+1; }
            catch (\Throwable $e) { $errors['inventory']=$e->getMessage(); }
        }
        if ($now>=$nextOwnership) {
            $nextOwnership=$now+60;
            try { locked(function()use(&$dev){ $dev=owned_zram(); $saved=config(); if($saved['disk_enabled']) configure_disk($saved); },false); unset($errors['swap']); }
            catch (OperationBusy) { $nextOwnership=$now+1; }
            catch (\Throwable $e) { $errors['swap']=$e->getMessage(); }
        }
        if ($now>=$nextRam) {
            // Scan in a separate low-priority process; never block the Docker watcher.
            if (!isset($ramProcess) || !proc_get_status($ramProcess)['running']) {
                if (isset($ramProcess)) proc_close($ramProcess);
                $ramProcess=proc_open(['nice','-n','19','ionice','-c','3','php',__DIR__.'/cli.php','ramdisk-scan'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$rp);
            }
            $nextRam=$now+300;
        }
        if ($now>=$nextSample) {
            $nextSample=$now+15;
            try {
                $s=sample($items,$dev); $ram=json_file(RUN.'/ramdisks.json'); $s['footprints']=$ram;
                $state=locked(fn()=>monitor_tick($s,$c,$items),false);
                if ($now>=$nextHistory) { history_add($s,$c['history_hours']); $nextHistory=$now+60; }
                $visibleErrors=array_filter($errors,fn($k)=>!str_ends_with((string)$k,'_retry'),ARRAY_FILTER_USE_KEY);
                save_json(RUN.'/status.json',['version'=>VERSION,'sample'=>$s,'items'=>public_items($items),'items_updated'=>$itemsUpdated,'explain'=>explain($c,$s,$items),
                    'psi_requested'=>$c['psi_enabled'],'health'=>$state['pressure_now']?'Memory pressure':($state['swap_now']?'Swap nearly full':'Room to spare'),
                    'errors'=>$visibleErrors,'watchers'=>array_keys($streams),'updated'=>$now],0644);
                unset($errors['sample']);
            } catch (OperationBusy) { $nextSample=$now+1; }
            catch (\Throwable $e) { $errors['sample']=$e->getMessage(); event('error',$e->getMessage(),hash('sha256',$e->getMessage().intdiv($now,300))); }
        }
        usleep(200000);
    }
} catch (\Throwable $e) { event('error','Headroom service stopped: '.$e->getMessage()); }
finally {
    foreach ($streams as $stream) { proc_terminate($stream['proc']); fclose($stream['pipe']); proc_close($stream['proc']); }
    if (isset($ramProcess) && is_resource($ramProcess)) { proc_terminate($ramProcess); proc_close($ramProcess); }
    @unlink(RUN.'/daemon.json'); flock($singleton,LOCK_UN); fclose($singleton);
}
