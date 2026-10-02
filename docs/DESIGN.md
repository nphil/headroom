# Headroom design

## Purpose and boundaries

Headroom keeps Unraid usable when apps compete for memory. It cannot promise that the kernel never runs out of memory, and a protection score is a preference, not a strict shutdown queue. A container's own memory limit can still cause an out-of-memory event even with plenty of host RAM. “Always protected” means excluded from the kernel's memory victim selection, not immortal against crashes or an administrator stopping it.

This is a native Unraid 7.x plugin, not an embedded web app. One PHP backend supplies the Settings page, Dashboard tile, command-line operations and background service. No framework, downloaded runtime, cloud service or custom go-script. PHP and util-linux are already supplied by Unraid. Kernel cgroup v2 is required.

## Reference review

Reviewed the installed John White unraid-plg-zram sources: configuration, actions, init, OOM inventory/application, collector, drive discovery/status, both .page files, PHP dashboard renderer, both application JavaScript files and array events. The bundled Chart.js is a third-party dependency, not reused. Upstream has no LICENSE file in its root; Nitin has permission from John White. Headroom is a new implementation, inspired by those conventions, with explicit credit in NOTICE and README; its original code is MIT.

Keep: version-keyed flash package caching; verified SHA256; fresh zram allocation via hot_add; probe only zram devices (never wake disks for a label scan); active-device adoption; true compressed payload distinct from total RAM occupied; native Dashboard tile markup; array-stop awareness; Unraid CSRF token checking.

Fix: scores drifting after Docker recreation, whole-container OOM forced globally, broad pgrep matching container processes as core services, non-atomic settings writes, destructive GET actions, unsafe disk-swap evacuation calculation, silent partial errors, overlarge hard reservations, and misleading ZFS swap safety claims. Never use loop devices to hide an unsupported swap filesystem.

## Features that earn their place

| Feature | Why this server needs it | Safety choice |
|---|---|---|
| Compressed swap (zram) | Absorbs short-lived AI bursts without SSD writes | Adopt existing labelled zram2 with no swapoff. Carry latest 16G, zstd, priority 100, swappiness 150. Changing size/algorithm/priority requires explicit Apply and a fresh evacuation check. |
| Optional disk swap | Useful if a suitable disk is added later | Off here. Only direct swap files on XFS/ext4 or single-device Btrfs; reject ZFS, loop, network, USB and array targets. ZFS files and zvols can deadlock while reclaiming memory. |
| Memory protection | Keep Unraid, cameras and media above expendable AI work | Scores −1000/−500/0/+500/+1000 labelled Always protected/Keep running/Normal/Gives way first/Gives way before all (stored values stay never/last/normal/early/first, so saved settings carry over). Core services locked Always protected. Per-container whole-app kill defaults off, including migration. |
| Reservations | A small reclaim shield can prevent protected workloads stalling | Explicit MiB per container/VM, memory.min for Always protected; memory.low for Keep running. Total at most 25% of physical RAM. Default zero: reserving an entire 24G or 10G cap can worsen global pressure. Do not move host processes into new groups. Clear old plugin's hard reservation values, keeping its service cgroups populated until processes naturally exit. |
| Container caps | Bound a runaway AI worker without losing the whole host | Show cgroup use + Docker limit. Live docker update, then matching template ExtraParams; back up first and compensate failures. No recreation. Reject a new cap below current use + 10% (minimum 256MiB margin). Clear supported. |
| ZFS ARC | Show how much RAM the filesystem cache occupies | Keep 8GiB max recommendation for 64GB with many apps; show size, target and hit ratio. Persist zfs_arc_max in /boot/config/modprobe.d/zfs.conf and runtime /etc counterpart, preserving other options. Never rewrite go. |
| Early warnings + OOM reporting | Swap has filled twice and a camera process was killed | PSI some/full (time spent waiting for RAM), available RAM, swap fill and RAM-disk growth. Sustained-pressure alert, swap-full warning only when RAM is also low (available below 1.5× the pressure threshold) or PSI shows waiting, with hysteresis; full zram with free RAM is shown as a plain informational note, recovery notice, rate limits. Kernel log parser records process/container and global vs container-limit cause. |
| History | Reveal recurring bursts over hours and days | SQLite in /tmp, 1-minute samples, 72h retention, size cap, top 8 containers per sample. No flash logging. History resets at reboot, explicitly shown. |
| RAM disks | Root, /tmp and /var/log consume real RAM | Show filesystem allocation (shared mounts not additive) and directory footprints refreshed every 5min by low-priority bounded du, with growth warnings. No container filesystem walk. |
| Optional act early | Can avoid a hard kill, but must not disrupt essential services | Off. Requires explicit per-container eligibility as well as global opt-in. Only Normal/Gives way first/Gives way before all. Docker graceful stop explicitly sends SIGTERM, overriding unsafe image stop signals, with an infinite force-kill timeout; 15min cooldown, at most one app per sustained-pressure episode. Never auto-restart. Never core, Always protected or Keep running. |
| Refresh swap | Occasionally return inactive pages to RAM after a burst | Same strict evacuation check; never presented as a cure for a leak. Leave substantial free-RAM reserve and refuse during PSI pressure. |

## Architecture

- `src/lib/config.php`: strict schema, pure level/migration/size parsing, atomic JSON config, exclusive operation lock, flash backup helper.
- `src/lib/system.php`: proc/sys/cgroup reads, Docker inventory, exact host-service discovery, per-process scores, reservations, template limit persistence, ARC, plain-language summary.
- `src/lib/boot.php`: default-label-only PSI boot parameter edit, preserving all other bytes and line endings, with owned on/off state and uninstall restoration.
- `src/lib/swap.php`: ownership, conservative evacuation, zram lifecycle, eligible disk mounts and direct-file lifecycle.
- `src/lib/monitor.php`: bounded SQLite history/activity, threshold state machine, kernel OOM parsing, notifications.
- `src/daemon.php`: one protected PHP process; nonblocking Docker event stream and kernel dmesg follow stream, reconnected on exit. Samples every 15s, refreshes inventories/core scores every 15s, history each minute. Missed Docker events repaired by periodic reconciliation.
- `src/rc.headroom`: singleton start/stop; native plugin cron starts it again if it dies. Process ownership checked before signalling.
- `src/cli.php`: shared trusted local entry point used by installer and libvirt/array hooks.
- `src/api.php`: Unraid-authenticated endpoint; all requests require server-issued CSRF token, mutations POST only; command arguments never interpreted as shell fragments.
- `src/Headroom.page`, `src/HeadroomDash.page`, `src/ui.php`, `src/headroom.js`, `src/headroom.css`: native host UI, no external dependencies.
- `headroom.plg`: native installer/remover. Cached release tarball + checksum; package staged before replacement. No boot network requirement once cached.

The daemon publishes a small atomic status snapshot. UI polling reads that snapshot rather than repeatedly forking Docker commands. Inventory contains names/limits/state only: no Docker environment variables or secrets. Errors remain visible; stale samples are labelled, not green zeros. Single operation lock prevents conflicting mutation/daemon writes. Atomic file writes use unique temporary files in the target directory.

## UI wireframes and host theme

Use Unraid's own font, controls, Settings navigation and orange accent; native light/dark theme wins over any standalone palette. No gradients, glass, hero marketing or second navigation shell. Adapt Lucent's restrained grouping, immediate press feedback, clear focus and 48px phone targets to this dense native tool. All additional structural/colour values live in one `--lu-*` token layer. Minimal-effects profile; do not claim a hardware performance tier without measurements.

Settings opening view: a plain status sentence (“Room to spare”, “Swap full, RAM low”, or “Memory pressure”), available RAM and last update; beneath it an Explain my server paragraph and four anchor links. A history chart offers 1h/24h/72h and metric selection, with labelled axes and accessible summary. Protection table follows: filter, app name/state, current RAM/limit, stop preference, whole-app checkbox, small explicit Save action. On phones each row becomes a labelled vertical group; no sideways page scrolling. VMs and core services are separate expandable sections. Changing a row does not reorder it or discard unsaved edits.

Below: compressed swap, disk swap (strong ZFS warning + eligible target selector), ARC, alerts and optional early action; each setting has a one-line explanation. Potentially disruptive actions name the target/change in a confirmation. Successful changes only after backend confirmation. Activity lists recent warning, OOM and settings actions. Native Dashboard tile: title, settings link, status, RAM available/swap/ARC/PSI, small RAM history, last update; no settings controls.

## Migration and rollback

1. Back up original plugin .plg, settings, installed source, hooks, and every changed /boot file beneath `/boot/config/plugins/dockerMan/backup-2026-10-02/headroom-<timestamp>/`. Record original runtime defaults for later uninstall.
2. Re-read the latest old settings at cutover. Strictly map old levels; preserve zram algorithm/size/percent/priorities/swappiness. Save the complete original settings in the migration backup and record that location in metadata. Deliberately replace global oom.group=yes with per-container false. Explain this exception.
3. Stage Headroom; import config and adopt existing labelled active device. Apply protections before removing old components. Stop only verified old collector. Remove old marked libvirt hook, old plugin files/registration/cron without running its dangerous remover (it swapoffs live zram and clears all scores). Reapply Headroom in the same locked migration. Keep saved old config in backup, not an active plugin folder.
4. Install own qemu.d hook (do not edit Unraid's VFIO code), cron watchdog and array events. Start daemon and verify settings, same device/size/label/priority, every score/group, Docker new-start and disposable restart, notifications and UI.
5. Rollback: in a safe low-pressure window with PSI active, remove Headroom through the native plugin manager (never force a refused removal), restore the migration backup's saved-settings/settings.ini to the original plugin directory, then reinstall `https://raw.githubusercontent.com/johnpwhite/unraid-plg-zram/main/unraid-zram-card.plg`. The old plugin recreates its compressed swap and reapplies the original protection settings. This restores its old whole-app behavior and lack of Docker-event reapplication. Until safe evacuation is possible, leave Headroom installed rather than disabling its watchdog.

Normal uninstall safely evacuates owned swap only after checks; refusal aborts removal and leaves the plugin managing it. Restore original swappiness/ARC, changed template caps and owned score/reservation settings, remove only Headroom hooks/cron/processes, keep saved config and backups. Never delete a user swap file or unrelated device.

## Verification

The 15 regression tests cover invalid config/size/levels, migration and the whole-app exception, foreign cgroup rejection, limit-template preservation, ARC option preservation, global/container OOM association, bounded OOM contexts, pressure duration/recovery, swap-only warnings, protected early-action exclusions, PSI boot-line preservation and evacuation boundaries. ShellCheck, PHP/JS syntax and generated-installer checks run in CI. Real Unraid smoke exercised adoption, every live process score, Docker start/restart/recreate, native notifications, live limit set/clear with template persistence and no restart, ARC persistence, safe SIGTERM override, and authenticated desktop/phone UI. There was no deliberate OOM, production app restart, destructive live swap resize, real pressure-induced early stop or server reboot. ZFS targets were inspected and rejected as available options; no disk swap was created because this server has no eligible spare target.

## Findings from the real Unraid smoke

- The kernel has CONFIG_PSI=y and CONFIG_PSI_DEFAULT_DISABLED=y. With owner approval, only the default Unraid OS append line gains psi=1, backed up first to dockerMan/backup-2026-10-02/syslinux.cfg.pre-psi. The UI says activation is after the next reboot. RAM/swap/OOM alerts work now; guarded swap evacuation remains unavailable until PSI is active. No reboot is requested.
- The legacy plugin had moved 56 container processes into shared host-service cgroups. Migration restores only processes whose private PID namespace uniquely identifies their container. Host processes are never moved. Docker cgroup identity is verified against its full container ID, never accepted from an arbitrary init-process cgroup.
- Docker treats --memory 0 as leave-unchanged. Clearing uses Linux's page-aligned unlimited sentinel (9223372036854771712), then verifies memory.max is literally max. The saved template loses its memory flag; no restart is needed. The same mechanism restores originally unlimited containers during uninstall.
- Unraid's native PHP prepend validates and removes submitted CSRF tokens. The endpoint independently checks the original SAPI form field after that validation, rather than weakening POST protection.
- Expected overlap between a settings change and a monitor pass is represented by a dedicated busy result. The nonblocking monitor tries its next pass one second later; it does not publish a false failure or stop following Docker/kernel streams.
- Dashboard cards share a native table, and an unrelated card on this server can widen that table beyond a phone. Headroom resets inherited no-wrap and bounds only its own content/header to the viewport, leaving other cards untouched. Charts use the available pixel width so phone axis labels stay readable; top-container history ranks by peak usage in the selected period.

## Native-priority and visual refinement (2026.10.02.2)

Container priority is owned by Docker's native --oom-score-adj in the saved Unraid template ExtraParams. Setting a named priority replaces duplicate flags, preserves other shell words and memory settings, backs up the template, and applies live process scores without a restart. Clearing removes the flag (Docker's Normal/0 default). The next normal Unraid update/recreate gives both the initial process and docker exec the native score. The Docker watcher compares before writing and logs only actual repairs; its role is compatibility for old, not-yet-recreated containers. Template edits outside Headroom win over its historical level metadata. Containers without a saved template follow Docker's own setting and must be edited in their owning Docker/Compose definition. Host services and VMs still use direct process protection. A one-time migration and per-template original-priority record support rollback. Early action remains off.

The liked reference UI uses bounded cards, restrained borders, compact coloured number chips, a short history chart, practical density and native tile headers. Preserve that compact, scannable character—not the initial Headroom's long stack of forms. The replacement uses Unraid's own font, colour variables, tile header, controls and table rhythm. There is no separate Lucent skin, fixed palette, heavy chart library or decorative motion.

Settings wireframe: three balanced instruments (available RAM, compression savings, pressure); a compact history panel next to a short server-protection summary; a paged, searchable app list with priority and usage together; app details only when opened. Advanced swap/ZFS, monitoring and activity sections are closed initially. Phone columns stack, all controls remain at least 40px, and no horizontal scrolling is needed in Headroom. The Dashboard uses two compact metrics, a short available-RAM sparkline, pressure status and three largest apps inside the native header/collapse structure. Charts fit their actual column. Polls are single-flight and paused for hidden pages/collapsed tiles; stable row nodes preserve edits and focus.

Before/after evidence covers original source-rendered reference, first Headroom release, and the new live implementation, black/white themes at desktop/phone sizes. Reference rendering is isolated and read-only: the old plugin is not reinstalled and its collector/hooks are never run. Verification also covers tablet, azure/gray, keyboard/accessibility, native collapse, actual render/poll work and whole-dashboard CPU time. Theme comparisons change only the verification tab's native CSS, not the server's saved display preference.

## Measured verification

Verified on the actual authenticated Unraid 7.3.2 page in the shared Chromium browser, 2026-10-02. These are live-server observations, not a controlled benchmark. Raw bounded evidence: [verification-2026.10.02.2.json](verification-2026.10.02.2.json).

- 27 scoped accessibility/layout checks: Settings collapsed and expanded, Dashboard, black/white/azure/gray at 1440 and 360 pixels; tablet 768 and wide Settings 1920. Zero axe violations. Headroom has no horizontal overflow. Visible phone controls, including the footer link, have at least 40px hit areas. The native collapse control appears after initial page setup; bind it when Unraid adds it and preserve its own click behavior, adding keyboard/expanded-state access.
- Clean Settings run: first live frame 140ms after Headroom initialized; 2.409s from whole-page navigation, including native Unraid startup (1.334s to the first HTML byte). Eight scope/page/detail actions reached the next frame in 4.9–17.0ms. The two steady polls took 7.0/26.1ms through JSON decode, then 11.1/11.5ms to the next frame. No page errors; no task over 50ms after Headroom initialized in that run.
- Dashboard: one Headroom status request during a 17s visible window; zero requests in separate 17s collapsed, off-screen and genuinely backgrounded-tab windows. Keyboard Enter collapsed and restored the native tile. Whole-dashboard task CPU was 60.4% of one logical core visible versus 59.3% collapsed (script CPU 8.36% versus 8.04%). This includes all native/third-party widgets and is not a claim that Headroom uses 60% CPU, nor a reliable isolated attribution of the difference. Headroom poll-to-next-frame work was about 10ms in the measured dashboard pass.
- Real disposable app: native Unraid template recreation produced Docker/init/exec-child scores +500 while watcher repairs were locked out. Unraid’s actual update handler and a subsequent restart preserved +500. Deliberate +777 drift was repaired to +500 in 0.443s with one explanatory repair log. Clearing removed the flag and applied 0 without restart; a custom +250 survived an options save. Live limit set/clear preserved priority and the running container identity. The disposable container, template and temporary preference/restore entries were removed. No production app was recreated or restarted.
- A reproduced post-save bug initially displayed the previous limit after a successful edit. Mutations now return a fresh inventory under the same operation lock; the UI retains it until a newer monitor sample and builds editors from that snapshot. Immediate display and editor values were both verified.
- Eighteen permanent behavioral tests pass, plus PHP/JavaScript/shell/installer checks. A fresh UI test notification used Unraid’s normal notifier. Original zram adoption, every live process score, live memory limits, real kernel OOM association and safe SIGTERM-only behavior were exercised in the first-release migration, with final installed-release checks repeated for this release. No deliberate OOM, live swap drain, server reboot or destructive uninstall was performed. There is no eligible non-ZFS disk on this server, so live disk-swap creation cannot be exercised here.

Visual comparisons (black desktop; matching white/360px captures are alongside them):

| Surface | Original reference, read-only | First Headroom | Refined Headroom |
|---|---|---|---|
| Settings | [Original](screenshots/reference-zram-settings-black-1440.png) | [Before](screenshots/before-headroom-settings-black-1440.png) | [After](screenshots/after-headroom-settings-black-1440.png) |
| Dashboard | [Original](screenshots/reference-zram-dashboard-black-1440.png) | [Before](screenshots/before-headroom-dashboard-black-1440.png) | [After](screenshots/after-headroom-dashboard-black-1440.png) |

The original reference supplied the compact card density, quiet borders and native headers. The refinement keeps that familiarity while replacing confusing low/high labels, exposing the native priority source, and moving rarely used controls out of the everyday view.
