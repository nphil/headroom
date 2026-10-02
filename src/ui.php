<?php
declare(strict_types=1);
namespace Headroom;
require_once __DIR__.'/lib/config.php';
function asset_version(string $file): string { return VERSION.'-'.hash_file('crc32b',__DIR__.'/'.$file); }
function settings_ui(string $csrf): string {
    ob_start(); ?>
<link rel="stylesheet" href="/plugins/headroom/headroom.css?v=<?=asset_version('headroom.css')?>">
<div class="hr" data-headroom="settings" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
  <div class="hr-heading"><div><h2>Memory overview</h2><p class="hr-subtitle">Room for your apps. Protection for your server.</p></div><span class="hr-health" data-health>Checking memory</span></div>
  <div data-error role="alert" hidden></div><div data-feedback role="status" aria-live="polite"></div>
  <div class="hr-stats" data-stats aria-label="Live memory overview"></div>
  <div class="hr-overview-bottom">
    <section class="hr-panel hr-history" aria-labelledby="hr-history-title">
      <div class="hr-panel-heading"><h3 id="hr-history-title">Memory over time</h3><div class="hr-inline">
        <label><span class="hr-sr">History metric</span><select data-metric><option value="available">Available RAM</option><option value="ram_used">RAM in use</option><option value="zram_ram">Compressed RAM cost</option><option value="swap_used">Swap in use</option><option value="disk_used">Disk swap</option><option value="arc_size">ZFS cache</option><option value="psi_some">Waiting for RAM: some</option><option value="psi_full">Waiting for RAM: all</option><option value="top">Top apps</option></select></label>
        <label><span class="hr-sr">History period</span><select data-hours><option value="1" selected>1 hour</option><option value="24">24 hours</option><option value="72">3 days</option></select></label>
      </div></div><div data-chart class="hr-chart"></div><p data-chart-summary class="hr-help"></p>
    </section>
    <aside class="hr-panel hr-summary"><div class="hr-panel-heading"><h3>Your protection plan</h3><i class="fa fa-shield" aria-hidden="true"></i></div><div data-plan></div><details><summary>Explain my server</summary><p data-explain></p></details></aside>
  </div>
  <section id="hr-protection" class="hr-panel" aria-labelledby="hr-protection-title">
    <div class="hr-panel-heading"><div><h3 id="hr-protection-title">Memory protection</h3><p class="hr-help">Choose what gives way first if memory runs out. App limits still apply.</p></div><label class="hr-filter"><span class="hr-sr">Find an app</span><input type="search" data-filter placeholder="Find an app…" autocomplete="off"></label></div>
    <div class="hr-list-toolbar"><div class="hr-scopes" role="group" aria-label="Show applications"><button type="button" data-scope="docker" aria-pressed="true">Apps <span data-count="docker"></span></button><button type="button" data-scope="custom" aria-pressed="false">Priority set <span data-count="custom"></span></button><button type="button" data-scope="vm" aria-pressed="false">VMs <span data-count="vm"></span></button><button type="button" data-scope="proc" aria-pressed="false">Core services</button></div><details class="hr-level-guide"><summary>What do the levels mean?</summary><p><b>Never stop:</b> excluded from memory kills. <b>Stop last:</b> preferred to keep. <b>Normal:</b> usual choice. <b>Stop early:</b> more expendable. <b>Stop first:</b> most expendable. Memory use also affects the kernel's choice.</p></details></div>
    <div class="hr-table-head" aria-hidden="true"><span>Application</span><span>Memory in use</span><span>When memory runs out</span><span></span></div>
    <div data-apps></div><p data-empty hidden>No matching apps.</p>
    <div class="hr-pager"><span data-page-info></span><div><button type="button" data-page="-1" aria-label="Previous apps">Previous</button><button type="button" data-page="1" aria-label="Next apps">Next</button></div></div>
    <p class="hr-list-note">Container priorities live in their Unraid templates. Changes work now, survive updates, and never restart an app. <span data-updated></span></p>
    <details class="hr-absent"><summary>Saved preferences for removed apps</summary><div data-absent></div></details>
  </section>
  <details id="hr-swap" class="hr-panel hr-advanced"><summary><span>Swap &amp; ZFS cache</span><small>Compressed memory, cache limits &amp; safe tools</small></summary>
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
  </details>
  <details id="hr-alerts" class="hr-panel hr-advanced"><summary><span>Monitoring &amp; early warnings</span><small>Notifications, pressure statistics &amp; RAM disks</small></summary>
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
        <label>Unlisted VMs and non-core services <select name="default_level"><option value="normal">Normal</option><option value="early">Stop early</option><option value="first">Stop first</option></select></label><p class="hr-help">Containers use their native Docker template priority. With no priority flag, Docker uses Normal.</p>
      </fieldset>
      <fieldset><legend>Act early — optional</legend><label><input name="act_early" type="checkbox"> Request a graceful app stop before a hard memory kill</label>
        <p class="hr-warning">Off by default. This can interrupt work. It only chooses apps you also marked “Allow early stop” in their protection row. Never stop, Stop last, virtual machines and core services are excluded.</p>
        <label>Continuous pressure before action (seconds) <input name="act_seconds" type="number" min="60" max="1800" required></label>
        <p class="hr-help">The most expendable eligible running app is chosen, then the largest at that level. One app per pressure episode, at least 15 minutes between actions. Graceful stop only: no forced kill and no automatic restart.</p>
        <h4>Files that live in RAM</h4><div data-ramdisks></div><p class="hr-help">/ and /tmp may share the same RAM-backed filesystem — do not add their filesystem totals together. Directory footprints exclude nested mounts and refresh every five minutes.</p>
        <button type="button" data-action="notify-test">Send test notification</button>
      </fieldset></div><button type="submit">Save monitoring settings</button>
    </form>
  </details>
  <details id="hr-activity" class="hr-panel hr-advanced"><summary><span>Recent activity</span><small>Alerts, memory kills &amp; automatic repairs</small></summary><button type="button" data-action="events">Refresh activity</button><div data-events tabindex="0" role="region" aria-label="Recorded memory activity"></div></details>
  <p class="hr-footer">Headroom <?=VERSION?> · <a href="https://github.com/nphil/headroom" target="_blank" rel="noopener">Project &amp; help</a> · Inspired by John White's unraid-plg-zram, used with permission.</p>
</div><script src="/plugins/headroom/headroom.js?v=<?=asset_version('headroom.js')?>" defer></script>
<?php return ob_get_clean(); }
function dashboard_ui(string $csrf): string {
    ob_start(); ?>
<tbody title="Headroom memory management"><tr><td><span class="tile-header hr-tile-header"><span class="tile-header-left"><i class="icon-ram f32" aria-hidden="true"></i><div class="section"><h3 class="tile-header-main">Headroom</h3><span class="subtitle">Memory &amp; protection</span></div></span><span class="tile-header-right"><span class="tile-header-right-controls"><a href="/Settings/Headroom" title="Headroom settings" aria-label="Headroom settings"><i class="fa fa-fw fa-cog control" aria-hidden="true"></i></a></span></span></span></td></tr>
<tr><td><link rel="stylesheet" href="/plugins/headroom/headroom.css?v=<?=asset_version('headroom.css')?>"><div class="hr hr-tile" data-headroom="tile" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><div class="hr-tile-status"><span class="hr-health" data-health>Checking memory</span><span data-pressure></span></div><div data-error role="alert" hidden></div><div class="hr-stats" data-stats></div><div class="hr-spark-heading"><span>Available RAM</span><span>Last hour</span></div><div data-chart class="hr-chart"></div><p data-chart-summary class="hr-sr"></p><div class="hr-top-apps" data-top-apps></div><span class="hr-sr" data-updated></span></div><script src="/plugins/headroom/headroom.js?v=<?=asset_version('headroom.js')?>" defer></script></td></tr></tbody>
<?php return ob_get_clean(); }
