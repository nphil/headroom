# Headroom control CLI: contract

Machine interface to Headroom's per-container settings. Made for automation (HomeLabber's MCP) calling over SSH as root. One JSON object in, one JSON object out. Nothing here restarts or recreates a container.

## Install path and calling

- Installed by the Headroom plugin at **`/usr/local/emhttp/plugins/headroom/control.php`** (mode 755, `#!/usr/bin/php`). Nothing is added to `/usr/local/sbin`, `PATH`, `/boot/config/go` or user scripts.
- Must run as **root**. Otherwise it prints an error with code `forbidden` and exits 1.
- Over SSH, send the JSON on **stdin** (no `-t`; a terminal on stdin is treated as "no input"):

```
ssh unraid /usr/local/emhttp/plugins/headroom/control.php list
ssh unraid /usr/local/emhttp/plugins/headroom/control.php list --name plex
echo '{"name":"plex","level":"low"}' | ssh unraid /usr/local/emhttp/plugins/headroom/control.php set-cpu-priority
ssh unraid /usr/local/emhttp/plugins/headroom/control.php set-swap-limit --json '{"name":"plex","limit":"2g"}'
```

- Arguments: `<command>` then optional `--json '<object>'` and `--name <container>`. `--name` is a shortcut for the `name` field (it must not contradict one inside the JSON). Any other argument is rejected. Stdin is read only when `--json` is absent, and only for `set-*` commands (64 KiB maximum). `list` never reads stdin.
- Input is never placed in a shell command. Docker is called with an argument array, never through a shell. Container names must match `^[A-Za-z0-9][A-Za-z0-9_. -]{0,127}$` and must exist.

## Output

Always exactly one line of JSON on stdout (UTF-8, compact), and the exit status below. Warnings go to stderr only.

Success: `{"ok":true,"command":"<command>", ...}`
Failure: `{"ok":false,"command":"<command>|null","error":{"code":"<code>","message":"<plain sentence>"}}`

| Exit | `error.code` | Meaning |
|---|---|---|
| 0 | none | Done. |
| 1 | `internal_error`, `forbidden` | Bug, an unexpected error, or not running as root. |
| 2 | `invalid_argument` | Bad command, bad or unknown field, bad value, bad JSON, input over 64 KiB. Nothing was changed. |
| 3 | `not_found` | No container with that name. |
| 4 | `failed` | A safety rule, Docker or the kernel refused the change. The message says why. If a live change was made and a later step failed, it was rolled back. |
| 5 | `busy` | Another Headroom change held the lock for 30 seconds. Retry. |

Calls are idempotent: repeating one that already holds changes nothing.

## Commands

All `set-*` commands return `{"ok":true,"command":...,"message":"<what happened>","container":{<record>}}`. The `container` record is read back after the change (see below), so the caller can check configured against live values.

### `version`

Input: none. Output: `{"ok":true,"command":"version","headroom":"<version>","unraid":"<version>","interface":1,"commands":[...],"features":["memory_limit","memory_priority","memory_priority_options","cpu_priority","cpu_pinning_read","swap_limit"],"memory_levels":["never","last","normal","early","first","default"],"cpu_levels":["high","normal","low","lowest"]}`. Use it to detect support: a Headroom without this CLI has no `control.php` (shell exit 127), and one with an older CLI answers `invalid_argument` ("Unknown command") with exit 2. `interface` rises only when an existing command changes incompatibly; new commands, fields and features are additive.

### `list`

Input: none, or `{"name":"<container>"}` (also `--name`).
Output: `{"ok":true,"command":"list","headroom":"<version>","unraid":"<version>","swap_limit_supported":true|false|null,"containers":[<record>, ...]}`. Containers are sorted by name. `swap_limit_supported` is `null` when no container is running.

### `set-memory-limit`

Input: `{"name":"plex","limit":"8g"}`. `limit` is a size string such as `"512m"`, `"1.5g"`, `"8g"` (units k, m, g, t, p; optional `i`/`b`, case-insensitive; fractions only where exact), a whole number of bytes, or `"none"` (or `"unlimited"`) to remove the limit.
Rules: at least 64 MiB and not above host RAM; refused if it leaves less than 10% or 256 MiB of room above current use; refused if below the container's reserved RAM; needs a unique Unraid template for the container. Applied live with `docker update`, then saved in Unraid's own Memory field (Unraid 7.4 and later) or the `--memory` option in Extra Parameters (earlier). If saving fails, the live change is undone.

### `set-memory-priority`

Input: `{"name":"plex","level":"normal"}`. `level` is one of the stored names:

| `level` | Label | OOM score |
|---|---|---|
| `never` | Always protected | -1000 |
| `last` | Keep running | -500 |
| `normal` | Normal | 0 |
| `early` | Gives way first | 500 |
| `first` | Gives way before all | 1000 |
| `default` | Docker default (removes the saved flag) | none (Normal) |

Saved as `--oom-score-adj` in the container's Unraid template (exactly one occurrence, other options kept), applied live, no restart. Reserved RAM is cleared for levels that cannot use it and graceful early stop is cleared for protected levels, as the Settings page does. Needs a unique Unraid template.
Optional extras so a caller can put these back after a change (read them from `memory_priority` in `list` first): `"reserve_mib"` (integer 0 to 65536; only Always protected and Keep running accept more than 0), `"whole"` (true/false: stop the whole app if one process runs out) and `"eligible"` (true/false: allow a graceful early stop; protected levels do not accept true). They are applied after the clearing above and checked by the same rules as the Settings page; a refused combination changes nothing (exit 2). Not allowed with `level` `default`.

### `set-cpu-priority`

Input: `{"name":"plex","level":"low"}`.

| `level` | Label | Docker `--cpu-shares` |
|---|---|---|
| `high` | Gets more CPU | 4096 |
| `normal` | Normal | 1024 (flag removed from the template) |
| `low` | Gives way | 512 |
| `lowest` | Gives way to everything | 2 |

Saved as `--cpu-shares` in the template's Extra Parameters (exactly one occurrence; other options, including quoted values and `$(...)`, are kept), then applied live with `docker update --cpu-shares` and read back from Docker. A custom value already in a template (for example 26000) is shown as level `custom` and is kept until you choose a named level. Shares only matter when the CPU is busy. Needs a unique Unraid template. CPU pinning is not changeable here (see `cpu_pinning`).

### `set-swap-limit`

Input: `{"name":"plex","limit":"2g"}`. `limit` is `"unlimited"` (default, no cap), `"none"` (no compressed swap at all), a size string, or whole bytes. Sizes: 64 MiB to 1024 GiB.
Stored in Headroom's settings (`swap_limits` in `/boot/config/plugins/headroom/settings.json`) and written to the container's **own** cgroup file `memory.swap.max`. The daemon re-applies it within about 15 seconds after every container start or restart, and when the live value drifts. For a running container the value is written and read back first; a refusal (exit 4) saves nothing. A stopped container only has the setting saved.
Caveats, also in the message: swap the container already holds above a new limit stays until it is used; no more is added. `none` on a container with a memory limit means the kernel stops a process inside it at the limit instead of using compressed swap. Docker itself cannot do this on Unraid (it ignores `--memory-swap` because Docker runs in the root cgroup), which is why it lives in Headroom.

## Container record

| Field | Type | Meaning |
|---|---|---|
| `name` | string | Container name. |
| `state` | `"running"`\|`"stopped"` | |
| `container_id` | string | First 12 characters. |
| `template` | bool | A unique Unraid template exists. Also `editable`: memory/CPU priority and memory limit can only be changed with one. |
| `memory_limit` | object | `bytes` (null = none), `usage_bytes`, `template` (the saved text, e.g. `"8g"`, or null). |
| `memory_priority` | object | `level` (`never`\|`last`\|`normal`\|`early`\|`first`\|`custom`), `label`, `score` (configured OOM score), `live_score` (main process now, null if stopped), `custom` (score is not one of the five), `source` (`template`\|`docker`), `applied` (live equals configured, null if stopped), `reserve_mib` (reserved RAM in MiB, 0 unless Always protected or Keep running), `whole` (stop the whole app if one process runs out), `eligible` (graceful early stop allowed; false for protected levels). |
| `cpu_priority` | object | `level` (`high`\|`normal`\|`low`\|`lowest`\|`custom`), `label`, `shares` (configured, 1024 when no flag), `template_flag` (literal value in the template, null if none), `live_shares` (Docker's value, 1024 when unset), `live_weight` (kernel `cpu.weight`, informational; its mapping depends on the runtime), `applied` (`shares` equals `live_shares`), `error` (null, or text when the template's flag is invalid). |
| `cpu_pinning` | object | Read only: `live` (Docker's cpuset, null = all CPUs), `template` (Unraid's CPU pinning for the container, null = none), `read_only: true`, `manage_url: "/Settings/CPUset"`. Change pinning on Unraid's own CPU Pinning page. |
| `swap_limit` | object | `mode` (`unlimited`\|`none`\|`limited`), `bytes` (saved cap; null when unlimited), `live` (kernel text: `"max"` or bytes; null when stopped or unsupported), `live_bytes`, `current_bytes` (swap in use), `supported` (the container's cgroup has `memory.swap.max`; null when stopped), `applied` (live matches saved; null when stopped), `error` (null, or why it is not in effect). |

## Example

```
$ echo '{"name":"CodyWorker","level":"lowest"}' | ssh unraid /usr/local/emhttp/plugins/headroom/control.php set-cpu-priority
{"ok":true,"command":"set-cpu-priority","message":"CodyWorker: CPU priority Gives way to everything (2 shares). Applied live and saved in its Unraid template. No restart.","container":{"name":"CodyWorker","state":"running","container_id":"0123456789ab","template":true,"editable":true,"memory_limit":{...},"memory_priority":{...},"cpu_priority":{"level":"lowest","label":"Gives way to everything","shares":2,"template_flag":2,"live_shares":2,"live_weight":1,"applied":true,"error":null},"cpu_pinning":{...},"swap_limit":{...}}}
```

## Notes for the caller

- Run `list` after a change if you need to be certain: compare `applied` flags, not just the exit status.
- Changes are serialized with Headroom's daemon and the Settings page. Concurrent callers wait their turn (30 s), then get `busy`.
- Every template edit is backed up under `/boot/config/plugins/dockerMan/backup-...` first by Headroom's usual backup step, and uninstalling Headroom restores the original `--cpu-shares`, `--oom-score-adj` and memory settings it changed.
- The same operations are available to the Settings page through `api.php` (actions `cpu-priority`, `swap-limit`, `limit`, `policy`); the CLI and the page share one implementation (`src/lib/control.php`).
