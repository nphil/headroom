# Changelog

## 2026.10.02.3
Full compressed swap no longer raises a warning on its own: with zram it is normal for swap to fill with rarely-used data while RAM is free, so the badge, dashboard and notification now need low available RAM (or real waiting-for-RAM pressure) as well, and a plain note explains the healthy case. The warning has a clear-down margin so it does not flap. Protection levels are renamed to match the plan card: Always protected, Keep running, Normal, Gives way first and Gives way before all. Saved settings are unchanged.

## 2026.10.02.2
Native Unraid memory controls with a compact, theme-aware interface. New RAM, compressed-memory and pressure gauges, focused app rows, expandable advanced controls and a lighter dashboard with top apps. Docker priority now lives in the saved Unraid template's --oom-score-adj flag; current priorities migrate automatically and apply live without restarting apps. The watcher repairs only genuine differences. Settings, memory limits, swap, core protections and opt-in safeguards are preserved. Phone layouts, reduced-motion support and visibility-aware polling keep the interface responsive. Original compressed swap is adopted without interruption; early stopping remains off by default.

## 2026.10.02.1
- First public Headroom release: native Settings page and Dashboard tile, with readable phone layouts.
- Automatic memory protection after container start, restart and recreation, VM start and core-service restart.
- Per-app whole-container stops are off by default; existing preferences and active compressed swap migrate safely.
- Live memory limits persist in Unraid templates, including verified clearing without a restart.
- ZFS ARC controls, RAM-disk awareness, bounded history, pressure warnings and kernel memory-kill reporting.
- Safe compressed/disk swap controls, owned next-boot PSI setting, backup and uninstall restoration.
- Optional early action is off, explicitly eligible only and SIGTERM-only without forced kills.
- Credit: based on / inspired by John White's unraid-plg-zram, used with permission.

