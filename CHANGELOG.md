# Changelog

## 2026.10.02.1
- First public Headroom release: native Settings page and Dashboard tile, with readable phone layouts.
- Automatic memory protection after container start, restart and recreation, VM start and core-service restart.
- Per-app whole-container stops are off by default; existing preferences and active compressed swap migrate safely.
- Live memory limits persist in Unraid templates, including verified clearing without a restart.
- ZFS ARC controls, RAM-disk awareness, bounded history, pressure warnings and kernel memory-kill reporting.
- Safe compressed/disk swap controls, owned next-boot PSI setting, backup and uninstall restoration.
- Optional early action is off, explicitly eligible only and SIGTERM-only without forced kills.
- Credit: based on / inspired by John White's unraid-plg-zram, used with permission.

