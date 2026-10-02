<?php
declare(strict_types=1);

namespace Headroom;

define(__NAMESPACE__.'\\VERSION', trim((string)file_get_contents(__DIR__.'/../version')));
const ROOT = '/usr/local/emhttp/plugins/headroom';
const CONFIG = '/boot/config/plugins/headroom';
const RUN = '/tmp/headroom';
const GIB = 1073741824;
const MIB = 1048576;
const LEVELS = ['never' => -1000, 'last' => -500, 'normal' => 0, 'early' => 500, 'first' => 1000];
const LABELS = ['never' => 'Always protected', 'last' => 'Keep running', 'normal' => 'Normal', 'early' => 'Gives way first', 'first' => 'Gives way before all'];
const CORE = ['emhttpd','nginx','php-fpm','dockerd','containerd','shfs','smbd','sshd','tailscaled','ttyd','rsyslogd','upsmon','usbhid-ups','upsd','libvirtd','virtqemud'];

function defaults(): array {
    return ['schema' => 1, 'zram_enabled' => true, 'zram_size' => '16G', 'zram_percent' => 25,
        'zram_algo' => 'zstd', 'zram_priority' => 100, 'swappiness' => 150,
        'disk_enabled' => false, 'disk_mount' => '', 'disk_size' => '16G', 'disk_priority' => 10,
        'arc_max' => 0, 'default_level' => 'normal', 'items' => [],
        'alerts' => true, 'psi_enabled' => true, 'available_percent' => 10, 'psi_some' => 10, 'psi_full' => 2,
        'sustain_seconds' => 60, 'swap_percent' => 85, 'ramdisk_growth_mib' => 512,
        'act_early' => false, 'act_seconds' => 180, 'history_hours' => 72];
}

function size_bytes(string $s): int {
    if (!preg_match('/^([1-9][0-9]{0,5})([MG])$/D', strtoupper($s), $m)) {
        throw new \InvalidArgumentException('Use a whole number followed by M or G, for example 512M or 16G.');
    }
    $n = (int)$m[1] * ($m[2] === 'G' ? GIB : MIB);
    if ($n > 1024 * GIB) throw new \InvalidArgumentException('Size must not exceed 1024 GiB.');
    return $n;
}

function valid_id(string $id): bool {
    return preg_match('/^(docker|vm|proc):[A-Za-z0-9][A-Za-z0-9_. -]{0,127}$/D', $id) === 1
        && !str_contains($id, '..');
}

function integer(mixed $v, int $min, int $max, string $name): int {
    if (!is_int($v) || $v < $min || $v > $max) throw new \InvalidArgumentException("$name must be between $min and $max.");
    return $v;
}

function validate(array $input): array {
    $base = defaults();
    foreach ($input as $k => $_) if (!array_key_exists($k, $base)) throw new \InvalidArgumentException("Unknown setting: $k");
    $c = array_replace($base, $input);
    foreach (['zram_enabled','disk_enabled','alerts','act_early','psi_enabled'] as $k) {
        if (!is_bool($c[$k])) throw new \InvalidArgumentException("$k must be on or off.");
    }
    foreach (['zram_percent'=>[5,75], 'zram_priority'=>[1,32767], 'disk_priority'=>[0,32766],
        'swappiness'=>[0,200], 'available_percent'=>[2,40], 'psi_some'=>[1,80], 'psi_full'=>[1,50],
        'sustain_seconds'=>[15,600], 'swap_percent'=>[50,99], 'ramdisk_growth_mib'=>[64,8192],
        'act_seconds'=>[60,1800], 'history_hours'=>[1,72], 'arc_max'=>[0,1024*GIB]] as $k=>$range) {
        integer($c[$k], $range[0], $range[1], $k);
    }
    if ($c['act_seconds'] < $c['sustain_seconds']) throw new \InvalidArgumentException('Early action must wait at least as long as the alert.');
    if ($c['zram_priority'] <= $c['disk_priority']) throw new \InvalidArgumentException('Compressed swap priority must be higher than disk swap.');
    if (!is_string($c['zram_size']) || !is_string($c['disk_size'])) throw new \InvalidArgumentException('Invalid swap size.');
    if ($c['zram_size'] !== 'auto') size_bytes($c['zram_size']);
    size_bytes($c['disk_size']);
    if (!in_array($c['zram_algo'], ['zstd','lz4','lzo','lzo-rle','deflate','842'], true)) throw new \InvalidArgumentException('Unknown compression algorithm.');
    if (!is_string($c['disk_mount']) || ($c['disk_mount'] !== '' && !preg_match('#^/mnt/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)?$#D', $c['disk_mount']))) throw new \InvalidArgumentException('Choose a local mounted disk.');
    if (!in_array($c['default_level'], ['normal','early','first'], true)) throw new \InvalidArgumentException('Unlisted apps must remain available to stop.');
    if (!is_array($c['items'])) throw new \InvalidArgumentException('Invalid protection settings.');
    foreach ($c['items'] as $id => &$item) {
        if (!is_string($id) || !valid_id($id) || !is_array($item)) throw new \InvalidArgumentException('Invalid app or service name.');
        if (array_diff(array_keys($item), ['level','whole','reserve_mib','eligible'])) throw new \InvalidArgumentException('Unknown item setting.');
        $item = array_replace(['level'=>$c['default_level'],'whole'=>false,'reserve_mib'=>0,'eligible'=>false], $item);
        if (!isset(LEVELS[$item['level']]) || !is_bool($item['whole']) || !is_bool($item['eligible'])) throw new \InvalidArgumentException('Invalid memory preference.');
        integer($item['reserve_mib'], 0, 65536, 'Reservation (MiB)');
        if (str_starts_with($id, 'proc:') && $item['reserve_mib'] !== 0) throw new \InvalidArgumentException('Host-service reservations are not supported; core processes are protected from memory kills.');
        if ($item['reserve_mib'] && !in_array($item['level'], ['never','last'], true)) throw new \InvalidArgumentException('Only Always protected / Keep running apps can reserve memory.');
        if (($item['whole'] || $item['eligible']) && !str_starts_with($id, 'docker:')) throw new \InvalidArgumentException('Whole-app and early-action settings apply only to containers.');
        if ($item['eligible'] && LEVELS[$item['level']] < 0) throw new \InvalidArgumentException('Protected apps cannot be eligible for early action.');
        if (str_starts_with($id, 'proc:') && in_array(substr($id,5), CORE, true) && $item['level'] !== 'never') throw new \InvalidArgumentException('Core Unraid services must remain Always protected.');
    }
    unset($item);
    foreach (CORE as $name) $c['items']['proc:'.$name] = ['level'=>'never','whole'=>false,'reserve_mib'=>0,'eligible'=>false];
    return $c;
}

function migrate(array $old): array {
    $c = defaults();
    if (isset($old['enabled']) && !in_array($old['enabled'],['yes','no'],true)) throw new \InvalidArgumentException('Invalid legacy enable setting.');
    $c['zram_enabled']=($old['enabled']??'yes')==='yes';
    foreach (['zram_percent','zram_priority','swappiness','ssd_swap_priority'] as $key) if (isset($old[$key]) && !preg_match('/^\d+$/D',(string)$old[$key])) throw new \InvalidArgumentException('Invalid legacy numeric setting: '.$key);
    if (isset($old['ssd_swap_size'])) $c['disk_size']=(string)$old['ssd_swap_size'];
    foreach (['zram_size','zram_algo'] as $key) if (isset($old[$key])) $c[$key] = (string)$old[$key];
    foreach (['zram_percent','zram_priority','swappiness'] as $key) if (isset($old[$key])) $c[$key] = (int)$old[$key];
    $c['disk_priority'] = (int)($old['ssd_swap_priority'] ?? 10);
    // ZFS/loop swap is never silently adopted as a safe disk tier.
    if (($old['ssd_swap_enabled'] ?? 'no') === 'yes') throw new \RuntimeException('Disable the old disk swap safely before migrating. Headroom does not adopt loop/ZFS swap.');
    $mapping = ['protected'=>'never','high'=>'last','normal'=>'normal','low'=>'early','killfirst'=>'first'];
    foreach (explode(',', (string)($old['oom_levels'] ?? '')) as $part) {
        if (trim($part) === '') continue;
        $at = strrpos($part, '=');
        if ($at === false) throw new \InvalidArgumentException('Malformed old protection entry.');
        $id = trim(substr($part,0,$at)); $level = trim(substr($part,$at+1));
        if (!valid_id($id) || !isset($mapping[$level])) throw new \InvalidArgumentException('Unrecognised old protection entry: '.$id);
        $c['items'][$id] = ['level'=>$mapping[$level],'whole'=>false,'reserve_mib'=>0,'eligible'=>false];
    }
    $default = $mapping[$old['oom_default_level'] ?? 'normal'] ?? null;
    if ($default === null) throw new \InvalidArgumentException('Unknown old default level.');
    $c['default_level'] = $default;
    return validate($c);
}

function text(string $path): string { return trim((string)@file_get_contents($path)); }
function json_file(string $path, array $fallback = []): array {
    if (!is_file($path)) return $fallback;
    $v = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($v)) throw new \RuntimeException("Invalid JSON in $path");
    return $v;
}
function config(): array { return validate(json_file(CONFIG.'/settings.json', defaults())); }
function atomic(string $path, string $value, int $mode = 0600): void {
    if (is_file($path) && file_get_contents($path)===$value) return;
    if (str_starts_with($path,'/boot/')) backup($path);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) throw new \RuntimeException('Cannot create configuration directory.');
    $tmp = tempnam(dirname($path), '.headroom-');
    if ($tmp === false) throw new \RuntimeException('Cannot create temporary file.');
    try {
        if (file_put_contents($tmp, $value) !== strlen($value)) throw new \RuntimeException('Could not write '.$path);
        chmod($tmp,$mode);
        if (!rename($tmp,$path)) throw new \RuntimeException('Could not replace '.$path);
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function save_json(string $path, array $value, int $mode = 0600): void { atomic($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",$mode); }
function backup(string $path): ?string {
    if (!file_exists($path)) return null;
    $root = CONFIG.'/backups';
    if (str_starts_with($path,'/boot/')) $root = '/boot/config/plugins/dockerMan/backup-'.date('Y-m-d').'/headroom';
    $dest = $root.'/'.date('His').'-'.bin2hex(random_bytes(3)).'/'.ltrim($path,'/');
    if (!mkdir(dirname($dest),0755,true) || !copy($path,$dest)) throw new \RuntimeException('Backup failed for '.$path);
    return $dest;
}
function save_config(array $c): void {
    $c = validate($c);
    if (is_file(CONFIG.'/settings.json') && config() === $c) return;
    save_json(CONFIG.'/settings.json',$c);
}
final class OperationBusy extends \RuntimeException {}
function locked(callable $fn, bool $wait = true): mixed {
    if (!is_dir(RUN)) mkdir(RUN,0755,true);
    $f = fopen(RUN.'/operation.lock','c');
    if (!$f) throw new \RuntimeException('Cannot open the Headroom operation lock.');
    if (!flock($f, LOCK_EX | ($wait ? 0 : LOCK_NB))) { fclose($f); throw new OperationBusy('Another Headroom operation is running. Try again shortly.'); }
    try { return $fn(); } finally { flock($f,LOCK_UN); fclose($f); }
}
function run(array $argv, int $seconds = 20): string {
    $cmd = array_merge(['/usr/bin/timeout','--signal=TERM', (string)$seconds], $argv);
    $p = proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL'=>'C']);
    if (!is_resource($p)) throw new \RuntimeException('Could not start '.$argv[0]);
    stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
    $out = ''; $err = '';
    do {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        if (strlen($out)+strlen($err)>16*MIB) { proc_terminate($p); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); throw new \RuntimeException('Command returned too much data.'); }
        $status = proc_get_status($p);
        if ($status['running']) usleep(10000);
    } while ($status['running']);
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    if (($status['exitcode'] >= 0 ? $status['exitcode'] : $code) !== 0) throw new \RuntimeException(basename($argv[0]).': '.substr(trim($err ?: $out),0,1000));
    return trim($out);
}
function write_kernel(string $path, string|int $value): void {
    if (text($path) === (string)$value) return;
    if (@file_put_contents($path,(string)$value) === false) throw new \RuntimeException('Could not apply '.$path);
}
