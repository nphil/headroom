<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/monitor.php';
require_once __DIR__.'/boot.php';

function runtime_baseline(): array {
    return ['swappiness'=>(int)text('/proc/sys/vm/swappiness'),'arc_max'=>(int)text('/sys/module/zfs/parameters/zfs_arc_max'),
        'arc_files'=>['/boot/config/modprobe.d/zfs.conf'=>is_file('/boot/config/modprobe.d/zfs.conf')?(string)file_get_contents('/boot/config/modprobe.d/zfs.conf'):null,
            '/etc/modprobe.d/zfs.conf'=>is_file('/etc/modprobe.d/zfs.conf')?(string)file_get_contents('/etc/modprobe.d/zfs.conf'):null], 'limits'=>[]];
}
function legacy_cleanup(): void {
    $old='/usr/local/emhttp/plugins/unraid-zram-card';
    $oldConfig='/boot/config/plugins/unraid-zram-card';
    $root='/boot/config/plugins/dockerMan/backup-'.date('Y-m-d').'/headroom-migration-'.date('His');
    if (!mkdir($root,0755,true)) throw new \RuntimeException('Cannot create migration backup.');
    foreach ([$old,$oldConfig,'/boot/config/plugins/unraid-zram-card.plg','/etc/libvirt/hooks/qemu'] as $path) {
        if (file_exists($path)) run(['cp','-a','--parents',$path,$root],60);
    }
    $pid=(int)text('/tmp/unraid-zram-card/collector.pid');
    if ($pid>1 && str_contains(text('/proc/'.$pid.'/cmdline'),'unraid-zram-card/zram_collector.php')) {
        posix_kill($pid,SIGTERM);
        for($i=0;$i<30 && is_dir('/proc/'.$pid);$i++) usleep(100000);
        if (is_dir('/proc/'.$pid) && str_contains(text('/proc/'.$pid.'/cmdline'),'unraid-zram-card/zram_collector.php')) throw new \RuntimeException('Old collector did not stop; migration paused without removing protection.');
    }
    $hook='/etc/libvirt/hooks/qemu';
    if (is_file($hook)) {
        $content=(string)file_get_contents($hook);
        $clean=preg_replace('/^(?:#|\/\/) BEGIN zram-oom-protection\R.*?^(?:#|\/\/) END zram-oom-protection\R?/ms','',$content);
        if ($clean!==$content) {
            $tmp=RUN.'/qemu-hook-check'; atomic($tmp,$clean,0755);
            run(str_contains(strtok($content,"\n"),'php')?['php','-l',$tmp]:['bash','-n',$tmp]);
            atomic($hook,$clean,0755); unlink($tmp);
        }
    }
    // Removing the old .plg's own remove action is intentional: it empties live
    // swap and clears scores. This migration removes only known plugin assets.
    foreach (['/boot/config/plugins/unraid-zram-card.plg','/var/log/plugins/unraid-zram-card.plg'] as $path) if (file_exists($path)||is_link($path)) unlink($path);
    if (is_dir($oldConfig) && !rename($oldConfig,$root.'/saved-settings')) throw new \RuntimeException('Could not archive old plugin settings.');
    if (is_dir($old)) run(['rm','-rf','--',$old]);
    foreach (glob('/sys/fs/cgroup/zram-protected/*/memory.min')?:[] as $path) write_kernel($path,0);
    foreach (glob('/sys/fs/cgroup/zram-protected/*/memory.low')?:[] as $path) write_kernel($path,0);
    // Do not move live core processes or delete their populated cgroups.
    save_json(CONFIG.'/migration.json',['time'=>time(),'backup'=>$root,'legacy_url'=>'https://raw.githubusercontent.com/johnpwhite/unraid-plg-zram/main/unraid-zram-card.plg',
        'exceptions'=>['Whole-app OOM switched off per container.','Legacy full-cap hard reservations replaced with explicit, bounded opt-in reservations.'],
        'device'=>owned_zram()]);
    event('migration','Imported legacy settings; archived old plugin at '.$root.' without disabling compressed swap.');
}
function install(): array {
    if (!is_file('/etc/unraid-version') || !is_file('/sys/fs/cgroup/cgroup.controllers')) throw new \RuntimeException('Headroom requires Unraid 7.x with cgroup v2.');
    if (!is_dir(CONFIG)) mkdir(CONFIG,0755,true);
    if (!is_file(CONFIG.'/restore.json')) save_json(CONFIG.'/restore.json',runtime_baseline());
    $legacy=is_dir('/usr/local/emhttp/plugins/unraid-zram-card');
    if (!is_file(CONFIG.'/settings.json')) {
        $old='/boot/config/plugins/unraid-zram-card/settings.ini';
        $c=is_file($old)?migrate(parse_ini_file($old,false,INI_SCANNER_RAW)?:[]):validate(defaults());
        $c['arc_max']=(int)text('/sys/module/zfs/parameters/zfs_arc_max');
        save_config($c);
    }
    $c=config(); $dev=configure_zram($c,false);
    migrate_native_priorities($c);
    write_kernel('/proc/sys/vm/swappiness',$c['swappiness']);
    if ($legacy) { $moved=restore_legacy_memberships(inventory($c)); if($moved) event('migration','Returned '.$moved.' container processes misplaced by the legacy plugin to their own Docker control groups. Host services were not moved.'); }
    $applied=apply_policy($c);
    if ($applied['errors']) throw new \RuntimeException('Protection apply failed; old plugin retained: '.implode('; ',$applied['errors']));
    if ($legacy) { legacy_cleanup(); $applied=apply_policy($c); }
    // Boot-time reapplication uses the saved ARC limit even if another modprobe
    // snippet predates this plugin. Normal install does not resize the ARC.
    if ($c['arc_max']>0) write_kernel('/sys/module/zfs/parameters/zfs_arc_max',$c['arc_max']);
    set_psi($c['psi_enabled']);
    $hook="#!/bin/bash\n# Headroom: libvirt executes qemu.d hooks independently of Unraid's VFIO hook.\nif [[ \"\${2:-}\" == started ]]; then\n  nohup /usr/bin/php /usr/local/emhttp/plugins/headroom/cli.php apply >/dev/null 2>&1 &\nfi\nexit 0\n";
    atomic('/etc/libvirt/hooks/qemu.d/50-headroom',$hook,0755);
    atomic(CONFIG.'/headroom.cron',"* * * * * /usr/local/emhttp/plugins/headroom/rc.headroom start >/dev/null 2>&1\n",0644);
    run(['/usr/local/sbin/update_cron']);
    if ($c['disk_enabled']) {
        try { configure_disk($c); } catch (\Throwable $e) { event('error','Disk swap not active: '.$e->getMessage()); }
    }
    return ['version'=>VERSION,'device'=>$dev,'protection'=>$applied,'migrated'=>$legacy];
}
function uninstall(): void {
    $c=config(); $restore=json_file(CONFIG.'/restore.json'); $items=inventory($c);
    restore_psi($restore,false);
    foreach ($restore['limits']??[] as $name=>$old) {
        $item=$items['docker:'.$name]??null; if (!$item) continue;
        template_for($name);
        if ($old['limit'] && $item['state']==='running' && $old['limit']<$item['usage']+max(256*MIB,(int)($item['usage']*.1))) throw new \RuntimeException('Uninstall refused: restoring the old limit would endanger '.$name.'. Stop that app or reduce its usage first.');
    }
    $dev=owned_zram(); $targets=[];
    if ($dev) $targets[]=$dev;
    if ($c['disk_mount']) $targets[]=$c['disk_mount'].'/.headroom.swap';
    $check=can_evacuate($targets);
    if (!$check['safe']) throw new \RuntimeException('Uninstall refused; plugin remains active. '.$check['reason']);
    foreach ($restore['limits']??[] as $name=>$old) {
        if (!isset($items['docker:'.$name])) continue;
        $path=template_for($name); $content=(string)file_get_contents($path);
        $xml=simplexml_load_string($content,'SimpleXMLElement',LIBXML_NONET);
        $params=limit_params((string)$xml->ExtraParams,(int)$old['limit']);
        $node='<ExtraParams>'.htmlspecialchars($params,ENT_XML1|ENT_QUOTES,'UTF-8').'</ExtraParams>';
        $new=preg_replace_callback('#<ExtraParams(?:\s[^>]*)?>.*?</ExtraParams>|<ExtraParams\s*/>#s',fn()=>$node,$content,1);
        backup($path); update_memory($items['docker:'.$name]['container_id'],(int)$old['limit']); atomic($path,$new,0644);
    }
    foreach ($targets as $path) safe_swapoff($path);
    foreach($restore['priorities']??[] as $name=>$score) {
        if(!isset(template_index()[$name])) continue;
        $path=template_for($name); $content=(string)file_get_contents($path);
        atomic($path,template_with_params($content,priority_params(template_params($content),$score)),0644);
    }
    $restoredDocker=docker_items($c);
    if ($dev) run(['zramctl','--reset',$dev]);
    write_kernel('/proc/sys/vm/swappiness',$restore['swappiness']??60);
    if (isset($restore['arc_max']) && is_file('/sys/module/zfs/parameters/zfs_arc_max')) write_kernel('/sys/module/zfs/parameters/zfs_arc_max',$restore['arc_max']);
    foreach ($restore['arc_files']??[] as $path=>$content) {
        if (is_file($path)) { $now=(string)file_get_contents($path); $baseline=[]; preg_match('/\bzfs_arc_max=(\d+)/',$content??'',$baseline);
            $new=$baseline?arc_config($now,(int)$baseline[1]):preg_replace('/\s+zfs_arc_max=\S+/','',$now); atomic($path,$new,0644); }
    }
    restore_psi($restore);
    foreach (json_file(RUN.'/reservation-parents.json') as $path=>$original) if(is_file($path)) write_kernel($path,$original);
    foreach ($items as $i) {
        foreach ($i['pids'] as $pid) if (is_file('/proc/'.$pid.'/oom_score_adj')) {
            if ($i['cgroup'] && !str_starts_with(cgroup($pid).'/',$i['cgroup'].'/')) continue;
            if ($i['kind']==='proc' && text('/proc/'.$pid.'/comm')!==$i['name']) continue;
            write_kernel('/proc/'.$pid.'/oom_score_adj',$i['kind']==='docker'?($restoredDocker[$i['id']]['policy']['score']??0):0);
        }
        if ($i['cgroup']) foreach (['memory.min','memory.low','memory.oom.group'] as $field) if (is_file($i['cgroup'].'/'.$field)) write_kernel($i['cgroup'].'/'.$field,0);
    }
    @unlink('/etc/libvirt/hooks/qemu.d/50-headroom');
    backup(CONFIG.'/headroom.cron'); @unlink(CONFIG.'/headroom.cron'); run(['/usr/local/sbin/update_cron']);
    event('service','Uninstalled; restored prior limits, boot parameter and runtime values. Saved settings and inactive swap file retained.');
}
