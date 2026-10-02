<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/system.php';

function swaps(): array {
    $out=[];
    foreach (explode("\n",text('/proc/swaps')) as $line) {
        $p=preg_split('/\s+/',trim($line));
        if (count($p)!==5 || !ctype_digit($p[2])) continue;
        $path=preg_replace_callback('/\\\\([0-7]{3})/',fn($m)=>chr(octdec($m[1])),$p[0]);
        $out[$path]=['path'=>$path,'size'=>(int)$p[2]*1024,'used'=>(int)$p[3]*1024,'priority'=>(int)$p[4]];
    }
    return $out;
}
function owned_zram(): string {
    $found=[];
    foreach (glob('/sys/block/zram*')?:[] as $sys) {
        if ((int)text($sys.'/disksize')===0) continue;
        $dev='/dev/'.basename($sys);
        try { $label=run(['blkid','-c','/dev/null','-s','LABEL','-o','value',$dev]); }
        catch (\Throwable) { continue; }
        if (in_array($label,['HEADROOM','ZRAM_CARD'],true)) $found[]=$dev;
    }
    if (count($found)>1) throw new \RuntimeException('More than one Headroom/legacy-labelled zram device exists. Resolve ownership before changing swap.');
    return $found[0]??'';
}
function zram_stats(string $dev): ?array {
    if (!$dev) return null;
    $base='/sys/block/'.basename($dev);
    $mm=preg_split('/\s+/',text($base.'/mm_stat'));
    if (count($mm)<3) throw new \RuntimeException('Compressed swap statistics unavailable.');
    preg_match('/\[([^]]+)\]/',text($base.'/comp_algorithm'),$m);
    $swap=swaps()[$dev]??null;
    return ['device'=>$dev,'active'=>$swap!==null,'size'=>(int)text($base.'/disksize'),
        'used'=>$swap['used']??0,'data'=>(int)$mm[0],'compressed'=>(int)$mm[1],'ram_cost'=>(int)$mm[2],
        'algorithm'=>$m[1]??'unknown','priority'=>$swap['priority']??null,
        'saved'=>max(0,(int)$mm[0]-(int)$mm[2])];
}
function evacuation_check(int $used, int $available, int $total, ?float $some, ?float $full): array {
    $reserve=max(4*GIB,(int)ceil($total*.10));
    $need=(int)ceil($used*1.25)+$reserve;
    $safe=$some!==null && $full!==null && $some<5 && $full<1 && $available>=$need;
    return ['safe'=>$safe,'used'=>$used,'available'=>$available,'required'=>$need,'reserve'=>$reserve,
        'reason'=>$safe?'Enough RAM is available.':(($some===null || $full===null)?'Pressure statistics are not active. Safe swap evacuation becomes available after the next planned reboot with PSI enabled.':'Cannot safely move swap back to RAM now. Wait for lower pressure and at least '.round($need/GIB,1).' GiB available.')];
}
function can_evacuate(array $targets): array {
    $used=0; $current=swaps();
    foreach ($targets as $path) $used+=$current[$path]['used']??0;
    $m=meminfo(); $p=psi();
    return evacuation_check($used,$m['MemAvailable'],$m['MemTotal'],$p['some'],$p['full']);
}
function safe_swapoff(string $path): void {
    if (!isset(swaps()[$path])) return;
    $check=can_evacuate([$path]);
    if (!$check['safe']) throw new \RuntimeException($check['reason']);
    run(['swapoff',$path],120);
    if (isset(swaps()[$path])) throw new \RuntimeException('Swap remains active; nothing was removed.');
}
function new_zram(array $c): string {
    $total=meminfo()['MemTotal'];
    $bytes=$c['zram_size']==='auto' ? (int)(floor($total*$c['zram_percent']/100/MIB)*MIB) : size_bytes($c['zram_size']);
    if ($bytes> $total*.75 || $bytes<256*MIB) throw new \InvalidArgumentException('Compressed swap must be between 256 MiB and 75% of physical RAM.');
    run(['modprobe','zram']);
    $id=text('/sys/class/zram-control/hot_add');
    if (!ctype_digit($id)) throw new \RuntimeException('Could not allocate a new compressed-swap device.');
    $dev='/dev/zram'.$id; $sys='/sys/block/zram'.$id;
    try {
        $algos=preg_split('/\s+/',str_replace(['[',']'],'',text($sys.'/comp_algorithm')));
        if (!in_array($c['zram_algo'],$algos,true)) throw new \RuntimeException('The kernel does not support that compression algorithm.');
        write_kernel($sys.'/comp_algorithm',$c['zram_algo']);
        write_kernel($sys.'/disksize',$bytes);
        for ($i=0;$i<20 && !file_exists($dev);$i++) usleep(100000);
        run(['mkswap','-L','HEADROOM',$dev]);
        run(['swapon','-p',(string)$c['zram_priority'],$dev]);
        return $dev;
    } catch (\Throwable $e) {
        if (!isset(swaps()[$dev])) @file_put_contents('/sys/class/zram-control/hot_remove',$id);
        throw $e;
    }
}
function configure_zram(array $c, bool $apply=false): string {
    $old=owned_zram();
    if (!$c['zram_enabled']) {
        if ($old) { safe_swapoff($old); run(['zramctl','--reset',$old]); }
        return '';
    }
    if (!$old) return new_zram($c);
    if (!isset(swaps()[$old])) { run(['swapon','-p',(string)$c['zram_priority'],$old]); }
    if (!$apply) return $old; // Migration and boot adoption never swapoff an active device.
    $s=zram_stats($old); $m=meminfo();
    $desired=$c['zram_size']==='auto' ? (int)(floor($m['MemTotal']*$c['zram_percent']/100/MIB)*MIB) : size_bytes($c['zram_size']);
    if ($s['size']===$desired && $s['algorithm']===$c['zram_algo'] && $s['priority']===$c['zram_priority']) return $old;
    $check=can_evacuate([$old]); if (!$check['safe']) throw new \RuntimeException($check['reason']);
    // Build the replacement before removing the working tier. If evacuation fails,
    // both devices remain usable and the error names the recovery state explicitly.
    $new=new_zram($c);
    try { safe_swapoff($old); run(['zramctl','--reset',$old]); }
    catch (\Throwable $e) {
        try { safe_swapoff($new); run(['zramctl','--reset',$new]); }
        catch (\Throwable) { throw new \RuntimeException('Swap change stopped safely with two active devices: '.$old.' and '.$new.'. Do not reset either while active. '.$e->getMessage()); }
        throw $e;
    }
    return $new;
}
function refresh_swap(): void {
    $c=config(); $dev=owned_zram(); $targets=[];
    if ($dev && isset(swaps()[$dev])) $targets[]=$dev;
    $disk=$c['disk_mount'].'/.headroom.swap'; if ($c['disk_mount'] && isset(swaps()[$disk])) $targets[]=$disk;
    if (!$targets) throw new \RuntimeException('No Headroom swap is active.');
    $check=can_evacuate($targets); if (!$check['safe']) throw new \RuntimeException($check['reason']);
    foreach ($targets as $target) {
        $prio=swaps()[$target]['priority'];
        safe_swapoff($target);
        try { run(['swapon','-p',(string)$prio,$target]); }
        catch (\Throwable $e) { throw new \RuntimeException($target.' was emptied but could not be reactivated. '.$e->getMessage()); }
    }
}
function disk_candidates(): array {
    $out=[];
    foreach (explode("\n",text('/proc/mounts')) as $line) {
        $p=preg_split('/\s+/',trim($line)); if (count($p)<4) continue;
        [$dev,$mount,$fs,$opts]=$p;
        if (!preg_match('#^/mnt/[^/]+$|^/mnt/disks/[^/]+$#D',$mount)) continue;
        if (preg_match('#^/mnt/(?:disk\d+|user0?|remotes|addons|rootshare)(?:/|$)#',$mount)) continue;
        $reason='';
        if ($fs==='zfs') $reason='ZFS swap can deadlock under memory pressure, including zvols. Keep disk swap off.';
        elseif (!in_array($fs,['xfs','ext4','btrfs'],true)) $reason='Only local XFS, ext4 or single-device Btrfs is supported.';
        elseif (!str_starts_with($dev,'/dev/') || str_starts_with($dev,'/dev/loop')) $reason='Loop and network targets are not supported.';
        elseif (in_array('ro',explode(',',$opts),true)) $reason='This filesystem is read-only.';
        else {
            $block=realpath('/sys/class/block/'.basename(realpath($dev)?:$dev))?:'';
            if ($block==='' || str_contains($block,'/virtual/')) $reason='Virtual block devices, including ZFS zvols and loop devices, are not safe swap targets.';
            if (str_contains($block,'/usb') || text($block.'/removable')==='1' || text(dirname($block).'/removable')==='1') $reason='USB and removable disks are not safe swap targets.';
            if (!$reason && $fs==='btrfs') {
                try { $show=run(['btrfs','filesystem','show',$mount]); if (preg_match_all('/\bdevid\s+\d+/',$show)!==1) $reason='Btrfs multi-device pools are not supported for swap.'; }
                catch (\Throwable) { $reason='Could not verify single-device Btrfs.'; }
            }
        }
        $out[]=['mount'=>$mount,'filesystem'=>$fs,'allowed'=>$reason==='','reason'=>$reason,'free'=>$reason?null:@disk_free_space($mount)];
    }
    return $out;
}
function disk_target(array $c): string {
    foreach (disk_candidates() as $d) if ($d['mount']===$c['disk_mount']) {
        if (!$d['allowed']) throw new \RuntimeException($d['reason']);
        if (realpath($d['mount'])!==$d['mount']) throw new \RuntimeException('Swap mount must not be a symlink.');
        $path=$d['mount'].'/.headroom.swap';
        if (is_link($path)) throw new \RuntimeException('Swap file must not be a symlink.');
        return $path;
    }
    throw new \RuntimeException('Selected swap disk is not mounted or supported.');
}
function configure_disk(array $c, bool $create=false): void {
    $path=$c['disk_mount'].'/.headroom.swap';
    if (!$c['disk_enabled']) { if ($c['disk_mount'] && isset(swaps()[$path])) safe_swapoff($path); return; }
    if (is_file(RUN.'/array-stopping')) throw new \RuntimeException('The array is stopping; disk swap stays off.');
    $path=disk_target($c); $size=size_bytes($c['disk_size']);
    if (isset(swaps()[$path])) {
        if ($create && (abs(filesize($path)-$size)>4096 || swaps()[$path]['priority']!==$c['disk_priority'])) throw new \RuntimeException('Disable disk swap before changing its size or priority. Existing swap files are preserved.');
        return;
    }
    if (!is_file($path)) {
        if (!$create) throw new \RuntimeException('Disk swap file is missing; create it in Settings.');
        if ((@disk_free_space(dirname($path))?:0)<$size+2*GIB) throw new \RuntimeException('Leave at least 2 GiB free on the selected disk.');
        $f=fopen($path,'x'); if (!$f) throw new \RuntimeException('Could not exclusively create swap file.'); fclose($f); chmod($path,0600);
        try {
            $fs=run(['stat','-f','-c','%T',dirname($path)]);
            if ($fs==='btrfs') run(['chattr','+C',$path]);
            run(['fallocate','-l',(string)$size,$path],120);
            run(['mkswap','-L','HEADROOM_DISK',$path]);
        } catch (\Throwable $e) { unlink($path); throw $e; }
    }
    if (abs(filesize($path)-$size)>4096) throw new \RuntimeException('Existing file size differs. Preserve or remove the inactive file before creating a different size.');
    if (run(['blkid','-c','/dev/null','-s','LABEL','-o','value',$path])!=='HEADROOM_DISK') throw new \RuntimeException('Headroom does not own this swap file.');
    run(['swapon','-p',(string)$c['disk_priority'],$path]);
}
function remove_disk(array $c): void {
    $path=disk_target($c);
    if ($c['disk_enabled'] || isset(swaps()[$path])) throw new \RuntimeException('Disable disk swap safely before removing its file.');
    if (!is_file($path)) throw new \RuntimeException('No Headroom swap file exists on this disk.');
    if (run(['blkid','-c','/dev/null','-s','LABEL','-o','value',$path])!=='HEADROOM_DISK') throw new \RuntimeException('This file is not owned by Headroom.');
    if (!unlink($path)) throw new \RuntimeException('Could not remove the inactive swap file.');
}
