<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/config.php';
function asset_version(string $file): string { return VERSION.'-'.hash_file('crc32b',__DIR__.'/'.$file); }
function settings_ui(string $csrf): string {
    ob_start(); ?>
<link rel="stylesheet" href="/plugins/headroom/headroom.css?v=<?=asset_version('headroom.css')?>">
<div class="hr" data-headroom="settings" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
  <div class="hr-status"><h2 data-health>Checking memory</h2><span data-updated>Waiting for the first sample</span></div>
  <div data-error role="alert" hidden></div>
  <div data-feedback role="status" aria-live="polite"></div>
  <div class="hr-stats" data-stats></div>
  <details class="hr-explain" open><summary>Explain my server</summary><p data-explain></p></details>
  <nav class="hr-jump" aria-label="Headroom sections"><a href="#hr-protection">Memory protection</a><a href="#hr-swap">Swap &amp; ZFS</a><a href="#hr-alerts">Monitoring</a><a href="#hr-activity">Activity</a></nav>
  <section aria-labelledby="hr-history-title">
    <div class="hr-section-title"><h3 id="hr-history-title">Memory over time</h3><div class="hr-inline">
      <label>Show <select data-metric><option value="ram_used">RAM in use</option><option value="zram_ram">Compressed swap RAM cost</option><option value="swap_used">All swap in use</option><option value="disk_used">Disk swap in use</option><option value="arc_size">ZFS cache</option><option value="psi_some">Waiting for RAM: some</option><option value="psi_full">Waiting for RAM: all</option><option value="top">Top containers</option></select></label>
      <label>Period <select data-hours><option value="1">Last hour</option><option value="24" selected>Last 24 hours</option><option value="72">Last 3 days</option></select></label></div></div>
    <div data-chart class="hr-chart"></div><p data-chart-summary class="hr-help"></p>
    <p class="hr-help">One point per minute. Up to 3 days kept in RAM, not on your flash drive. History starts again after a reboot. Waiting for RAM (PSI) measures delays, not how full RAM is.</p>
  </section>
  <section id="hr-protection" aria-labelledby="hr-protection-title">
    <h3 id="hr-protection-title">Memory protection</h3>
    <p>If memory runs out, tell Unraid what it may stop first. A limit can still stop a process inside an app even when the server has spare RAM.</p>
    <div class="hr-level-help"><b>Never stop</b> excluded from memory kills. <b>Stop last</b> preferred to keep. <b>Normal</b> usual choice. <b>Stop early</b> more expendable. <b>Stop first</b> most expendable. Memory use also affects the kernel's choice.</div>
    <label class="hr-filter">Find an app <input type="search" data-filter placeholder="Filter by name" autocomplete="off"></label>
    <div data-apps></div>
    <details><summary>Virtual machines</summary><div data-vms></div></details>
    <details><summary>Core Unraid services — always Never stop</summary><p class="hr-help">Exact host process names only. Container processes are not mistaken for host services. New service processes are checked every 15 seconds.</p><div data-services></div></details>
    <details><summary>Saved preferences for absent apps</summary><div data-absent></div></details>
    <p class="hr-help">“Whole app” is off by default: the kernel may stop only the memory-hungry process. Turning it on may end every chat or camera task in that app. Reservations are an optional reclaim shield, not extra RAM: MiB for Never stop is a hard floor; Stop last is best-effort. Combined reservations cannot exceed 25% of RAM.</p>
  </section>
  <section id="hr-swap" aria-labelledby="hr-swap-title">
    <h3 id="hr-swap-title">Swap &amp; ZFS cache</h3>
    <div class="hr-columns">
      <form data-form="zram"><fieldset><legend>Compressed swap (zram)</legend><p data-zram-live></p>
        <label><input name="zram_enabled" type="checkbox"> Enable compressed swap</label><p class="hr-help">Stores inactive memory compressed in RAM. It does not create physical RAM.</p>
        <label>Size <input name="zram_size" required placeholder="16G or auto"></label><p class="hr-help">Use 16G for a fixed size, or auto for the percentage below. 16 GiB suits this server's short bursts.</p>
        <label>Automatic size (% of RAM) <input name="zram_percent" type="number" min="5" max="75" required></label>
        <label>Compression <select name="zram_algo"><option>zstd</option><option>lz4</option><option>lzo</option><option>lzo-rle</option><option>deflate</option><option>842</option></select></label><p class="hr-help">zstd saves more RAM; lz4 prioritises speed. Unsupported kernel algorithms are rejected.</p>
        <label>Swap priority <input name="zram_priority" type="number" min="1" max="32767" required></label><p class="hr-help">Higher is used first. Keep compressed swap above disk swap (normally 100 versus 10).</p>
        <label>Swappiness <input name="swappiness" type="number" min="0" max="200" required></label><p class="hr-help">How readily Unraid moves inactive pages to swap. 150 is a useful compressed-swap setting; this applies to the whole server.</p>
        <button type="submit">Apply compressed swap</button><p class="hr-help">Changing size, compression or priority temporarily moves data out of the old swap. Headroom refuses if RAM cannot safely absorb it.</p>
      </fieldset></form>
      <form data-form="disk"><fieldset><legend>Disk swap (optional)</legend>
        <p class="hr-warning">Recommended off on this server. All its SSD pools use ZFS. Swap on ZFS — including volumes called zvols — can deadlock under the very memory pressure it is meant to relieve.</p>
        <label><input name="disk_enabled" type="checkbox"> Enable disk swap</label><p class="hr-help">Only a directly mounted non-ZFS disk is allowed. No array disks, USB, network or loop-backed targets.</p>
        <label>Disk <select name="disk_mount"><option value="">Choose a supported disk</option></select></label><div data-disk-reasons class="hr-help"></div>
        <label>Size <input name="disk_size" required placeholder="16G"></label><p class="hr-help">A dedicated .headroom.swap file; at least 2 GiB is left free. Inactive files are preserved.</p>
        <label>Swap priority <input name="disk_priority" type="number" min="0" max="32766" required></label>
        <button type="submit">Apply disk swap</button> <button type="button" data-action="disk-remove">Remove inactive swap file</button>
      </fieldset></form>
      <form data-form="arc"><fieldset><legend>ZFS cache (ARC)</legend><p data-arc-live></p>
        <p>The ARC caches recently used files. It competes with apps for RAM, but can give memory back.</p>
        <label>Maximum (GiB) <input name="gib" type="number" min="1" step="0.5" required></label>
        <p class="hr-help">Recommendation: keep 8 GiB for this 64 GB server and its cameras, media and AI apps. Increase only if history shows spare RAM and storage performance needs it. Saved through Unraid's ZFS module settings.</p>
        <button type="submit">Apply cache limit</button>
      </fieldset></form>
      <fieldset><legend>Refresh swap safely</legend><p>Move swapped data back to RAM and leave swap ready for the next burst. This is not a fix for a leaking app.</p><p class="hr-help">Requires free RAM for 125% of swapped data plus the larger of 4 GiB or 10% of physical RAM, and low memory waiting time.</p><button type="button" data-action="refresh">Refresh swap</button></fieldset>
    </div>
  </section>
  <section id="hr-alerts" aria-labelledby="hr-alert-title"><h3 id="hr-alert-title">Monitoring &amp; early warnings</h3>
    <form data-form="psi"><fieldset><legend>Kernel pressure statistics</legend><p data-psi-note></p><label><input name="psi_enabled" type="checkbox"> Enable pressure statistics after the next reboot</label><p class="hr-help">Measures time spent waiting for memory. Changes only psi= in the default Unraid boot entry. RAM, swap and memory-kill alerts work before then. Headroom never restarts your server.</p><button type="submit">Save next-boot setting</button></fieldset></form><br>
    <form data-form="alerts"><div class="hr-columns">
      <fieldset><legend>When to warn</legend>
        <label><input name="alerts" type="checkbox"> Send Unraid notifications</label><p class="hr-help">Includes memory pressure, swap filling, RAM-disk growth, recovery and kernel memory kills. Delivery channels follow Unraid's notification settings.</p>
        <label>Warn below available RAM (%) <input name="available_percent" type="number" min="2" max="40" required></label>
        <label>Warn above waiting for RAM: some (%) <input name="psi_some" type="number" min="1" max="80" required></label><p class="hr-help">Time that at least one task is waiting for RAM.</p>
        <label>Warn above waiting for RAM: all (%) <input name="psi_full" type="number" min="1" max="50" required></label><p class="hr-help">Time that all active tasks are stalled for RAM.</p>
        <label>Wait before warning (seconds) <input name="sustain_seconds" type="number" min="15" max="600" required></label><p class="hr-help">A brief burst is not an incident. Send one warning per sustained episode, then a recovery notice.</p>
        <label>Swap warning at (%) <input name="swap_percent" type="number" min="50" max="99" required></label>
        <label>RAM-disk growth in 5 minutes (MiB) <input name="ramdisk_growth_mib" type="number" min="64" max="8192" required></label>
        <label>Keep history (hours) <input name="history_hours" type="number" min="1" max="72" required></label>
        <label>Unlisted apps <select name="default_level"><option value="normal">Normal</option><option value="early">Stop early</option><option value="first">Stop first</option></select></label><p class="hr-help">New or recreated apps inherit this preference automatically unless you gave them their own.</p>
      </fieldset>
      <fieldset><legend>Act early — optional</legend><label><input name="act_early" type="checkbox"> Request a graceful app stop before a hard memory kill</label>
        <p class="hr-warning">Off by default. This can interrupt work. It only chooses apps you also marked “Allow early stop” in their protection row. Never stop, Stop last, virtual machines and core services are excluded.</p>
        <label>Continuous pressure before action (seconds) <input name="act_seconds" type="number" min="60" max="1800" required></label>
        <p class="hr-help">The most expendable eligible running app is chosen, then the largest at that level. One app per pressure episode, at least 15 minutes between actions. Graceful stop only: no forced kill and no automatic restart.</p>
        <h4>Files that live in RAM</h4><div data-ramdisks></div><p class="hr-help">/ and /tmp may share the same RAM-backed filesystem — do not add their filesystem totals together. Directory footprints exclude nested mounts and refresh every five minutes.</p>
        <button type="button" data-action="notify-test">Send test notification</button>
      </fieldset></div><button type="submit">Save monitoring settings</button>
    </form>
  </section>
  <section id="hr-activity" aria-labelledby="hr-activity-title"><div class="hr-section-title"><h3 id="hr-activity-title">Recent activity</h3><button type="button" data-action="events">Refresh activity</button></div><div data-events tabindex="0" role="region" aria-label="Recorded memory activity"></div></section>
  <p class="hr-footer">Headroom <?=VERSION?> · <a href="https://github.com/nphil/headroom" target="_blank" rel="noopener">Project &amp; help</a> · Inspired by John White's unraid-plg-zram, used with permission.</p>
</div><script src="/plugins/headroom/headroom.js?v=<?=asset_version('headroom.js')?>" defer></script>
<?php return ob_get_clean(); }
function dashboard_ui(string $csrf): string {
    ob_start(); ?>
<tbody title="Headroom memory management"><tr><td><span class="tile-header hr-tile-header"><span class="tile-header-left"><i class="icon-ram f32" aria-hidden="true"></i><div class="section"><h3 class="tile-header-main">HEADROOM</h3><span class="subtitle">Memory management</span></div></span><span class="tile-header-right"><span class="tile-header-right-controls"><a href="/Settings/Headroom" title="Headroom settings"><i class="fa fa-fw fa-cog control" aria-hidden="true"></i><span class="hr-sr">Headroom settings</span></a></span></span></span></td></tr>
<tr><td><link rel="stylesheet" href="/plugins/headroom/headroom.css?v=<?=asset_version('headroom.css')?>"><div class="hr hr-tile" data-headroom="tile" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><strong data-health>Checking memory</strong><div data-error role="alert" hidden></div><div class="hr-stats" data-stats></div><h4>Available RAM · last hour</h4><div data-chart class="hr-chart"></div><p data-chart-summary class="hr-help"></p><span data-updated></span></div><script src="/plugins/headroom/headroom.js?v=<?=asset_version('headroom.js')?>" defer></script></td></tr></tbody>
<?php return ob_get_clean(); }
