<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/config.php';

/** Change only the append line of the default label, preserving every other byte. */
function psi_boot_plan(string $content, ?bool $enabled): array {
    $parts=preg_split('/(\r\n|\n|\r)/',$content,-1,PREG_SPLIT_DELIM_CAPTURE);
    $labels=[]; $current=null; $default=null; $menuDefaults=[];
    for($i=0;$i<count($parts);$i+=2) {
        $line=$parts[$i];
        if(preg_match('/^\s*label\s+(.+?)\s*$/i',$line,$m)) { $current=$m[1]; if(isset($labels[$current])) throw new \RuntimeException('Duplicate boot label.'); $labels[$current]=[]; }
        elseif($current!==null && preg_match('/^\s*menu\s+default\s*$/i',$line)) $menuDefaults[]=$current;
        elseif($current!==null && preg_match('/^\s*append\s+/i',$line)) $labels[$current][]=$i;
        elseif($current===null && preg_match('/^\s*default\s+(.+?)\s*$/i',$line,$m)) $default=$m[1];
    }
    $entry=isset($labels[$default??''])?$default:(count($menuDefaults)===1?$menuDefaults[0]:null);
    if($entry===null || count($labels[$entry])!==1) throw new \RuntimeException('Cannot identify exactly one append line in the default boot entry. No boot settings changed.');
    $index=$labels[$entry][0]; $line=$parts[$index];
    preg_match_all('/(?<!\S)psi=(\S+)/',$line,$matches);
    if(count($matches[1])>1 || (isset($matches[1][0]) && !in_array($matches[1][0],['0','1'],true))) throw new \RuntimeException('Ambiguous existing PSI boot parameter. No boot settings changed.');
    $original=isset($matches[1][0])?$matches[1][0]==='1':null;
    if($enabled===null) $line=preg_replace('/[ \t]+psi=[01](?=\s|$)/','',$line);
    elseif($matches[1]) $line=preg_replace('/(?<!\S)psi=[01](?=\s|$)/','psi='.($enabled?'1':'0'),$line);
    else $line=preg_replace('/([ \t]*)$/',' psi='.($enabled?'1':'0').'$1',$line,1);
    $parts[$index]=$line;
    return ['content'=>implode('',$parts),'entry'=>$entry,'original'=>$original];
}
function set_psi(bool $enabled): void {
    $path='/boot/syslinux/syslinux.cfg';
    if(!is_file($path)) throw new \RuntimeException('Syslinux configuration is unavailable; no boot parameter changed.');
    $old=(string)file_get_contents($path); $plan=psi_boot_plan($old,$enabled);
    $restore=json_file(CONFIG.'/restore.json');
    if(isset($restore['psi']) && $restore['psi']['entry']!==$plan['entry']) throw new \RuntimeException('The default boot entry changed since Headroom configured PSI. Restore its previous default before editing this toggle.');
    if(!isset($restore['psi'])) {
        $backup='/boot/config/plugins/dockerMan/backup-2026-10-02/syslinux.cfg.pre-psi';
        if(!is_file($backup)) {
            if(!is_dir(dirname($backup))) mkdir(dirname($backup),0755,true);
            if(!copy($path,$backup)) throw new \RuntimeException('Could not back up the boot configuration.');
        }
        $restore['psi']=['entry'=>$plan['entry'],'original'=>$plan['original']]; save_json(CONFIG.'/restore.json',$restore);
    }
    atomic($path,$plan['content'],0644);
}
function restore_psi(array $restore, bool $apply=true): void {
    if(!isset($restore['psi'])) return;
    $path='/boot/syslinux/syslinux.cfg'; $old=(string)file_get_contents($path);
    $plan=psi_boot_plan($old,$restore['psi']['original']);
    if($plan['entry']!==$restore['psi']['entry']) throw new \RuntimeException('Default boot entry changed. Restore the original default before uninstalling, so only Headroom’s boot parameter is removed.');
    if($apply) atomic($path,$plan['content'],0644);
}
