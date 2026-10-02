<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/monitor.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/lib/boot.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(array $data, int $code=200): never { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; }
try {
    $method=$_SERVER['REQUEST_METHOD']??'';
    $v=@parse_ini_file('/var/local/emhttp/var.ini',false,INI_SCANNER_RAW)?:[];
    // Unraid validates and unsets POST tokens in local_prepend.php. Read the original SAPI form field for our independent check.
    $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf_token']??filter_input(INPUT_POST,'csrf_token',FILTER_UNSAFE_RAW)??'');
    if (empty($v['csrf_token']) || !hash_equals((string)$v['csrf_token'],$csrf)) respond(['ok'=>false,'error'=>'Your Unraid session could not be verified. Reload this page.'],403);
    $action=(string)($_GET['action']??'status');
    if ($method==='GET') {
        if ($action==='status') {
            $status=json_file(RUN.'/status.json');
            if (!$status) respond(['ok'=>false,'error'=>'Headroom is starting or unavailable. The watchdog checks once a minute.'],503);
            $status['stale']=time()-($status['updated']??0)>45;
            respond(['ok'=>true]+$status);
        }
        if ($action==='config') respond(['ok'=>true,'config'=>config(),'levels'=>LABELS,'disks'=>disk_candidates(),'migration'=>json_file(CONFIG.'/migration.json')]);
        if ($action==='history') respond(['ok'=>true,'samples'=>history((int)($_GET['hours']??24))]);
        if ($action==='events') respond(['ok'=>true,'events'=>events()]);
        respond(['ok'=>false,'error'=>'Unknown read action.'],400);
    }
    if ($method!=='POST') respond(['ok'=>false,'error'=>'Use POST for changes.'],405);
    $raw=(string)($_POST['payload']??file_get_contents('php://input'));
    if (strlen($raw)>65536) respond(['ok'=>false,'error'=>'Request too large.'],413);
    $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new \InvalidArgumentException('Expected an object.');
    $message=locked(function()use($action,$body){
        $old=config(); $new=$old;
        if ($action==='policy') {
            $id=$body['id']??'';
            if (!is_string($id)||!valid_id($id)||!is_array($body['policy']??null)) throw new \InvalidArgumentException('Choose a valid app.');
            $new['items'][$id]=$body['policy']; $new=validate($new); $items=inventory($old);
            if (!isset($items[$id])) throw new \InvalidArgumentException('App or service is no longer present.');
            reservation_check($new,$items);
            $result=apply_policy($new,$items);
            if ($result['errors']) { apply_policy($old,$items); throw new \RuntimeException(implode('; ',$result['errors'])); }
            try { save_config($new); } catch (\Throwable $e) { apply_policy($old,$items); throw $e; }
            $message=$items[$id]['name'].': '.LABELS[$new['items'][$id]['level']].'. Protection saved and applied.';
        } elseif ($action==='forget') {
            $id=(string)($body['id']??''); if(!valid_id($id)) throw new \InvalidArgumentException('Invalid app.');
            if(isset(inventory($old)[$id])) throw new \RuntimeException('This app still exists. Set its preference instead.');
            unset($new['items'][$id]); save_config($new); $message='Removed the absent app preference.';
        } elseif ($action==='limit') {
            $name=$body['name']??''; $bytes=$body['bytes']??null;
            if(!is_string($name)||!valid_id('docker:'.$name)||!is_int($bytes)) throw new \InvalidArgumentException('Invalid memory limit.');
            set_limit($name,$bytes); $message=$name.': memory limit '.($bytes?round($bytes/GIB,2).' GiB':'cleared').' live and in the Unraid template. No restart.';
        } elseif ($action==='zram') {
            $allowed=['zram_enabled','zram_size','zram_percent','zram_algo','zram_priority','swappiness'];
            if(array_diff(array_keys($body),$allowed)) throw new \InvalidArgumentException('Unknown compressed-swap setting.');
            $new=validate(array_replace($old,$body));
            $dev=configure_zram($new,true); write_kernel('/proc/sys/vm/swappiness',$new['swappiness']); save_config($new);
            $message=$dev?'Compressed swap active on '.$dev.'. Settings saved.':'Compressed swap safely disabled.';
        } elseif ($action==='disk') {
            $allowed=['disk_enabled','disk_mount','disk_size','disk_priority'];
            if(array_diff(array_keys($body),$allowed)) throw new \InvalidArgumentException('Unknown disk-swap setting.');
            $new=validate(array_replace($old,$body));
            if ($old['disk_enabled'] && $new['disk_mount']!==$old['disk_mount']) throw new \RuntimeException('Disable the current disk swap before choosing another target.');
            configure_disk($new,true); save_config($new); $message='Disk-swap settings applied. Inactive files are preserved.';
        } elseif ($action==='disk-remove') {
            remove_disk($old); $message='Removed the inactive Headroom swap file. Other files were not changed.';
        } elseif ($action==='arc') {
            $bytes=$body['bytes']??null; if(!is_int($bytes)) throw new \InvalidArgumentException('Invalid ARC limit.');
            set_arc($bytes); $new['arc_max']=$bytes; save_config($new); $message='ARC maximum saved for now and future boots. ZFS shrinks the cache as it can.';
        } elseif ($action==='psi') {
            if(array_keys($body)!==['psi_enabled'] || !is_bool($body['psi_enabled'])) throw new \InvalidArgumentException('Choose on or off for pressure statistics.');
            set_psi($body['psi_enabled']); $new['psi_enabled']=$body['psi_enabled']; save_config($new);
            $message='Pressure statistics will be '.($new['psi_enabled']?'enabled':'disabled').' after the next planned reboot. No reboot was requested.';
        } elseif ($action==='alerts') {
            $allowed=['alerts','available_percent','psi_some','psi_full','sustain_seconds','swap_percent','ramdisk_growth_mib','act_early','act_seconds','history_hours','default_level'];
            if(array_diff(array_keys($body),$allowed)) throw new \InvalidArgumentException('Unknown monitoring setting.');
            $new=validate(array_replace($old,$body)); save_config($new); $message='Monitoring settings saved.';
        } elseif ($action==='refresh') {
            refresh_swap(); $message='Swapped pages returned to RAM; swap remains ready for the next burst.';
        } elseif ($action==='notify-test') {
            notify('Headroom test','Test notification delivered by the normal Unraid notification system. No apps were stopped.','normal'); $message='Test sent through Unraid notifications.';
        } else { throw new \InvalidArgumentException('Unknown action.'); }
        event('settings',$message); return $message;
    });
    respond(['ok'=>true,'message'=>$message]);
} catch (\InvalidArgumentException|\JsonException $e) { respond(['ok'=>false,'error'=>$e->getMessage()],400); }
catch (\Throwable $e) { respond(['ok'=>false,'error'=>$e->getMessage()],409); }
