#!/usr/bin/php
<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/install.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $action=$argv[1]??'status';
    if ($action==='ramdisk-scan') {
        $old=json_file(RUN.'/ramdisks.json'); $new=ramdisk_footprints(); $c=config();
        foreach ($new as $path=>$bytes) if ($bytes!==null && isset($old['bytes'][$path]) && $bytes-$old['bytes'][$path]>$c['ramdisk_growth_mib']*MIB) {
            $message=$path.' grew by '.round(($bytes-$old['bytes'][$path])/MIB).' MiB in five minutes. These files consume RAM.';
            event('ramdisk',$message); if($c['alerts']) notify('RAM-disk growth',$message);
        }
        save_json(RUN.'/ramdisks.json',['t'=>time(),'bytes'=>$new],0644); exit;
    }
    $result=locked(function()use($action){
        return match($action) {
            'install'=>install(),
            'apply'=>apply_policy(config()),
            'status'=>json_file(RUN.'/status.json'),
            'snapshot'=>['sample'=>sample(inventory(config()),owned_zram()),'items'=>public_items(inventory(config()))],
            'notify-test'=>(function(){ notify('Headroom test','Test notification delivered by the normal Unraid notification system. No apps were stopped.','normal'); return ['ok'=>true]; })(),
            'array-stop'=>(function(){ atomic(RUN.'/array-stopping',(string)time()); $c=config(); if($c['disk_enabled']) { safe_swapoff($c['disk_mount'].'/.headroom.swap'); } return ['ok'=>true]; })(),
            'array-start'=>(function(){ @unlink(RUN.'/array-stopping'); $c=config(); if($c['disk_enabled']) configure_disk($c); return apply_policy($c); })(),
            'uninstall'=>(function(){ uninstall(); return ['ok'=>true]; })(),
            default=>throw new \InvalidArgumentException('Unknown command.')
        };
    });
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (isset($result['errors']) && $result['errors']) exit(1);
} catch (\Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
