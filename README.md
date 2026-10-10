# DuckCoverage

[English](README.md) | [中文](README.zh_CN.md)

**DuckCoverage** is a test coverage extension for [DuckPHP](https://github.com/dvaknheo/duckphp) applications. It collects line coverage from real HTTP requests and CLI calls, plays recorded requests, and generates HTML coverage reports without requiring you to write unit tests.

It works with [LibCoverage](https://github.com/dvaknheo/libcoverage), the coverage tool for standalone PHP libraries. Both projects share the `test_coveragedumps` / `test_reports` directory convention.

## Features

- **Coverage from real traffic** — while a group is being watched, every request handled by the application is collected. An `X-DuckCoverage-Name` request header gives the request a readable name.
- **Request log** — every collected request is appended to `<group>.list.log`.
- **Playback (`--play`)** — test directives are played against the built-in PHP test server (or an external server such as nginx); each played request contributes coverage again.
- **Direct CLI calls** — `RUN` / `CALL` invoke local commands, class methods or functions and collect coverage without HTTP.
- **Grouped workflow** — `--watch <group>` → play → `--report` renders an HTML report for the group.
- **Two collection scopes** — the whole request by default, or only the routing phase with `InitedThenGoRouteHookMode()`.
- **HTML reports** — rendered by `phpunit/php-code-coverage`.

## Requirements

| Item | Requirement |
|---|---|
| PHP | >= 7.4 |
| Coverage driver | **Xdebug** or **PCOV** must be loaded (visible in `php -m`) |
| Framework | DuckPHP >= 1.4.1 (including the `DuckPhp\HttpServer\HttpServer` component) |
| Dependency | `phpunit/php-code-coverage` ^9.2.32 (a normal runtime dependency, installed automatically) |

Check whether a coverage driver is loaded:

```bash
php -m | findstr /i "xdebug pcov"        # Windows
php -m | grep -i -E "xdebug|pcov"        # Linux / macOS
```

Without a coverage driver, DuckCoverage fails while creating the `CodeCoverage` object (`doBegin`). Install and load a driver first.

## Installation

In your DuckPHP project:

```bash
composer require dvaknheo/duckcoverage
```

`phpunit/php-code-coverage` comes in as a dependency of the package; you do not need to require it yourself.

## Quick Start (about 10 minutes)

The following example uses the `dvaknheo/duckadmin` package to show how to enable DuckCoverage in a DuckPHP application.

### 1. Enable DuckCoverage in the DuckPHP application

Edit the application entry class `DuckAdminDemo\System\DemoApp`:

```php
<?php
namespace DuckAdminDemo\System;

use DuckCoverage\DuckCoverage;

class DemoApp extends DuckPhp
{
    public $options = [
        // ... your existing options ...
        'duckcoverage_test_lister' => [TestLister::class, 'GetTestList'],
        //'duckcoverage_report_direct' => false,
        //'duckcoverage_web_base_url' => 'http://www.example.com/',

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        if (class_exists(DuckCoverage::class)) {
            DuckCoverage::Prepare([]);
        }
    }
}
```

And add the switch to the application setting file:

```php
<?php
// config/DuckPhpSettings.config.php
return [
    'duckcoverage_enable' => true,
];
```

> Unlike many other DuckPHP plugins, DuckCoverage must be initialized in the **root application's** `onPrepare`, as shown above. DuckCoverage options must also be placed in the root application's application options.

The test-list callback class, `DuckAdminDemo\System\TestLister`, may look like this:

```php
<?php
namespace DuckAdminDemo\System;

class TestLister
{
    public static function GetTestList()
    {
        return <<<EOT
WEB /admin/index
WEB /admin/login username=admin&password=123456
RUN mycommand anything.
CALL MyApp\Test\Tester@doSomething
EOT;
    }
}
```

This callback is configured through the `duckcoverage_test_lister` option. It returns a list of test directives; each line is one test directive. See the “Test Directive Reference” section below for details. The callback does not need to import DuckCoverage and can be provided by a child application as well as by the root application.

### 2. Run the tests

```bash
php cli.php cover --go group1
```

After execution, DuckCoverage runs the requests and calls returned by the callback, then generates the test coverage report. The CLI entry file (`cli.php`) follows your DuckPHP project template; replace it with your project's entry file if necessary.

The command name is `cover` (from `command_cover()`). If your application runs under a non-root phase, DuckPHP prefixes the command with the application command prefix, so it becomes `<prefix>:cover`.

## Workflow

Coverage collection, playback, and report generation are all controlled through the CLI command `php cli.php cover`; there is no separate interactive “browse and collect” workflow.

### One-step operation: `--go`

The most common approach is to use `--go <group>` to complete the entire workflow in one command:

```bash
php cli.php cover --go group1
```

It performs these steps in order:

```text
watch → play → report → stop
```

- Result: plays the callback list for `group1` and generates a report.
- Report location: `<runtime>/DuckCoverage/group1.report/`.
- This command is especially suitable for CI.

`--go` is a true composition of the four steps below: it does not change any option, so it always produces the same report directory as running `--watch`, `--play`, `--report` and `--stop` by hand. With `duckcoverage_report_direct => true` (or when reporting several groups with `--report`) the report goes to `<runtime>/DuckCoverage/<duckcoverage_report_default_dir>/` instead — see “Output Paths”.

**Known limitation: you cannot switch config files inside `--go`.** Because all four phases run in a single process, everything loaded at startup stays for the whole run — files read through `ExtOptionsLoader`, the per-group DuckPHP data file (`duckcoverage_data_file_json_file`), and anything opcache already cached. So if the play phase needs a *different* config file (for example another admin/user provider), `--go` cannot do it: those options are already fixed by the time the first request is played. `--go` prints an English note about this every time it runs.

The step-by-step workflow has no such limit, because every step is its own process: run `--watch`, then change the config file, then `--play` and `--report`. That is the way to collect one group per config file:

### Step-by-step operation

When you need to inspect intermediate states or extend the workflow manually, run the steps separately:

```bash
php cli.php cover --watch group1    # ① Start watching group1
php cli.php cover --play            # ② play the requests/calls in the callback list
php cli.php cover --report group1   # ③ Render the HTML report for the group
php cli.php cover --stop            # ④ Stop watching
```

- `--watch <group>` writes the watching marker; from that moment every request that reaches the application is collected into that group.
- `--play` starts the built-in test server (or points to the external server configured with `duckcoverage_web_base_url`) and executes the test directives returned by `GetTestList()` one line at a time. Every played request is collected again, and the server is stopped afterward.
- `--report` merges all dumps for one group (or multiple groups with `--report a b c`) and renders an HTML report.

### How to tell that a group is being watched

While a group is watched the whole application is in *watching state*, and any of these signs will tell you:

- `<runtime>/DuckCoverage/DuckCoverage.watching.txt` exists, and its content is the group name. That file *is* the watching state: `--stop` (or removing it) ends collection.
- **Web**: every response carries the header `x-duckcoverage-group: <group>`, so a collected request shows which group it went into.
- **Command line**: the run prints a red banner `DuckCoverage GROUP <group>`. (The red `DuckCoverage running: JSON_FILE: …` banner is printed whenever the extension is enabled, watching or not.)

Two things change while watching:

- The application's data config file is isolated per group: DuckPHP's `data_file_json_file` is redirected to `<runtime>/DuckCoverage/<group>.DuckPhpData.config.json`, so each group keeps its own options environment.
- Every collected operation is given a recorded name — `MAN-WEB` plus the request URI (and POST data) for web requests, `MAN-RUN` plus the command line for CLI runs — which is appended to `<group>.list.log` and used as the dump file name.

### Direct calls without HTTP

Class and function calls that do not use HTTP can be added to the play list with `CALL` (or to shell commands with `RUN`). They are executed and covered together with `--go` or `--play`:

```text
CALL MyApp\Test\Tester@doSomething         # @ means use the singleton (call _())
CALL MyApp\Business\DemoBusiness->handle   # -> means create a new instance
CALL MyApp\Helper::format                  # :: means a static call
CALL some_function                         # A plain function
```

Arguments use `name=value` pairs, encoded with `http_build_query()` and decoded with `parse_str()`; they are matched by parameter name:

```text
CALL MyApp\Test\Tester@runX parameter=d
```

Dumps are written into the current group's dump directory, and `--report` generates the report as usual. See the `CALL` section in the “Test Directive Reference”.

### Using an external server (such as nginx)

To use an external server instead of the built-in test server, configure its base URL:

```php
'duckcoverage_web_base_url' => 'http://www.example.com/',
```

- Playing `WEB /admin/index` then sends a request to `http://www.example.com/admin/index`.
- The external server must run the same application and must see the same `runtime/` directory: the group of a request is resolved on the server side from the watching marker file, not from a request header. Every request that arrives while a group is watched is collected.
- The `X-DuckCoverage-Name` header is optional; when present it only overrides the recorded name of the request.
- You must still start watching the same group with `--watch` for collection to happen.

## CLI Reference

All commands:

```bash
php cli.php cover
  --watch {group}
  --play
  --stop
  --report a
  --report a b c
  --go {group}
```

| Argument | Description |
|---|---|
| (no arguments) / `--help` | Print usage help |
| `--watch <group>` | Start watching a test group. If no group is supplied, a timestamped name (`default_<date>`) is generated. Writes `<runtime>/DuckCoverage/DuckCoverage.watching.txt`. |
| `--stop` | Stop watching and remove the watching marker and its lock file. |
| `--play` | Play the test list returned by the callback's `GetTestList()`. |
| `--report [a b c]` | Render an HTML report for the specified groups (the current watched group by default) and print the output path and elapsed time. |
| `--go <group>` | Combined command: `watch + play + report + stop` in one step (CI-friendly). If no group is supplied, the same timestamped name as `--watch` is used. |

If `duckcoverage_enable` is off, `cover` prints a hint instead of running (`turn on setting to work: 'duckcoverage_enable'`).

### Passing a flag to the application (`--flag` / `getFlag()`)

`--flag=<value>` attaches one string to a collection run and carries it all the way into the application:

```bash
php cli.php cover --go group1 --flag=admin
```

- `DuckCoverage::_()->getFlag()` returns the value. Inside the `duckcoverage_test_lister` callback (`GetTestList()`) that is how you play a different list per precondition — return the admin list when the flag is `admin`, the plain-user list otherwise.
- In web mode every request the client plays carries it as `X-DuckCoverage-Flag: <value>`, so the application being tested can call `getFlag()` inside an HTTP request and read the same value. Inside a request the header wins; in the CLI process there is no such header, so the configured value is used.
- Responses echo it back as `x-duckcoverage-flag` (diagnostics, alongside `x-duckcoverage-group`).
- It can also come from the options (`'duckcoverage_flag' => 'admin'`); the command-line value wins.
- A bare `--flag` (no value) changes nothing, and when no flag is set the header is not sent at all. CR/LF are stripped before the value goes into a header.
- Only effective while `duckcoverage_enable` is on: with the master switch off, `getFlag()` returns an empty string — `--flag`, the option and the request header are all ignored, and the header is not sent either. This is deliberate: an incoming header must not be able to influence the application while the tool is switched off.
- The flag is part of the dump name as well (`[<group> flag=<value> <time>]<request>`), so `<group>.list.log` and the dump file hashes record which flag a collection ran with. With no flag the name keeps its old format.

## Output Paths

All paths below are relative to `<runtime>` (DuckPHP's `path_runtime`, `runtime/` by default) and to `duckcoverage_path`, which defaults to `<runtime>/DuckCoverage/`.

| Path | Written by |
|---|---|
| `<runtime>/DuckCoverage/DuckCoverage.watching.txt` | `--watch` — the current group name |
| `<runtime>/DuckCoverage/<group>.watch.lock` | `--watch` — timestamp of the watch start |
| `<runtime>/DuckCoverage/<group>.list.log` | every collected request — one recorded request name per line |
| `<runtime>/DuckCoverage/<group>/<date>-<sha1(name)>.php` | every collected request — the coverage dump (serialized by `phpunit/php-code-coverage`) |
| `<runtime>/DuckCoverage/<group>.report/` | a single-group report: `--report <group>`, and `--go <group>`, with `duckcoverage_report_direct => false` |
| `<runtime>/DuckCoverage/<duckcoverage_report_default_dir>/` | `--report a b c` (multiple groups), or any report with `duckcoverage_report_direct => true` |
| `<runtime>/DuckCoverage/<group>.DuckPhpData.config.json` | the per-group DuckPHP data file used while a group is watched |
| `<report dir>/report.json` | every report — the machine-readable version of the same report (see below) |
| `<runtime>/DuckCoverage/DuckCoverage.exception.log` | `logException()` — appended exceptions, one line each (time, group, flag, class, code, location, message) |

The server also answers every request with two diagnostic headers: `x-duckcoverage-group` (the group the request was collected into) and `x-duckcoverage-datafile` (the data file in use).

## Machine-readable report (`report.json`)

Every report directory gets a `report.json` next to `index.html`, so scripts can list untested lines and diff two runs without scraping HTML. It is deterministic: files and directories are sorted by path, only relative paths are written, and every count comes with its total so "coverage changed" can be told apart from "the denominator changed".

```json
{
  "schema": "duckcoverage-report/1",
  "generated_at": "2026-10-04T12:34:56+00:00",
  "generator": { "name": "duckcoverage", "version": "1.0.1", "php": "8.2.32", "coverage_driver": "xdebug-3.2.0" },
  "root": "src/",
  "groups": ["g1"],
  "dumps_merged": 494,
  "totals": { "files": 88, "lines": { "executable": 4210, "executed": 3281, "percent": 77.93 } },
  "directories": [ { "path": "src/User", "files": 12, "lines": { "executable": 490, "executed": 418, "percent": 85.22 } } ],
  "files": [
    {
      "path": "src/User/Controller/AdminController.php",
      "dir": "src/User/Controller",
      "app": "User",
      "sha1": "9f2c…",
      "loaded": true,
      "lines": { "executable": 37, "executed": 28, "percent": 75.68 },
      "functions": { "total": 3, "covered": 2, "percent": 66.67 },
      "classes": { "total": 1, "covered": 1, "percent": 100.0 },
      "traits": { "total": 0, "covered": 0, "percent": 0.0 },
      "uncovered_lines": [43, 44, 45, 48, 51],
      "sig": [20],
      "function_items": [
        { "name": "delete", "class": "AdminController", "start": 42, "end": 51, "executable": 6, "executed": 0, "covered": false }
      ],
      "line_map": { "15": 1, "16": 1, "43": -1, "44": -1, "48": -1 }
    }
  ],
  "ignored_files": [ { "path": "src/System/TestLister.php", "reason": "no executable lines (@codeCoverageIgnore or empty file)" } ]
}
```

- `path` is relative to the project root and keeps every directory (never a bare basename); `dir` is its directory, `app` the first directory under the source root.
- `line_map` maps line number → `1` executed, `-1` executable but not executed, `-2` dead code; lines that are not executable are absent. `uncovered_lines` is the sorted list of `-1` lines.
- `lines` / `functions` / `classes` / `traits` always carry `executable`/`total` plus the covered count and a `percent`.
- `functions` counts named functions and methods (including trait methods); `covered` means every executable line of that unit was executed. `function_items` gives the same per unit, with `start`/`end` lines and `class`.
- `loaded` tells whether the driver reported the file at all while collecting — `false` means the file was never even loaded, which is different from "loaded but 0%".
- `sig` lists signature lines: executable lines inside a function/method declaration header that the driver never marks as executed, and only for declarations whose body is otherwise fully executed. These are the lines behind "the driver says 29/29 but the report says 29/30".
- `ignored_files` lists files with no executable lines (whole-file `@codeCoverageIgnore`, or empty files).
- The same field semantics are echoed inside the JSON itself under `definitions`.

## Line-oriented report (`report.jsonl`)

`report.json` is one big object: easy to read whole, awkward to stream, concatenate or diff. `report.jsonl` is the same data as **one JSON object per line**, so `grep` / `jq` / `diff` work directly and a half-written file is still parseable line by line.

```bash
# write it (relative paths resolve against the project root; directories are created on demand)
php cli.php cover --report g1 g2 --jsonl=runtime/DuckCoverage/report.jsonl

# bare --jsonl writes <report dir>/report.jsonl
php cli.php cover --report g1 g2 --jsonl

# line-level detail, or none at all
php cli.php cover --report g1 --jsonl=report.jsonl --jsonl-detail=full
php cli.php cover --report g1 --jsonl=report.jsonl --jsonl-detail=none

# to stdout (pipes)
php cli.php cover --report g1 g2 --format=jsonl > report.jsonl

# byte-identical output for two runs of the same dumps, so you can diff them
php cli.php cover --report g1 g2 --jsonl=report.jsonl --jsonl-no-timestamp
```

### Records

| `t` | where | purpose |
|---|---|---|
| `meta` | first line, exactly once | `schema`, `generator`, `php`, `driver`, `root`, `groups`, `dumps`, `detail`, `created` |
| `group` | after `meta`, one per group | `name`, `dumps` |
| `dir` | before the file records | `path`, `files`, `lines{executable,executed}` |
| `file` | per file | `path`, `dir`, `app`, `sha1`, `loaded`, `ignored`, `lines`, `funcs`, `unc`, `todo`, `sig` |
| `file_func` | right after its `file` | `path`, `name` (`Class::method`), `start`, `end`, `lines` |
| `file_lines` | after its `file`, `detail=full` only | `path`, `chunk`, `chunks`, `map` |
| `ignored` | any position | `path`, `reason` |
| `error` | any position | `group`, `msg`, `fatal` — e.g. a group with no dumps |
| `warning` | before `total` | `msg` — e.g. `no dumps merged`; the same fact is also carried as `warning` inside `meta` |
| `total` | last line, exactly once | `files`, `lines`, `funcs`, `records`, `complete` |

### Rules the format guarantees

- One JSON object per line, `LF` only, UTF-8 without BOM, no bare newlines; every line passes `jq -c .` on its own.
- Every line has a string `t`. **Unknown `t` values must be ignored by consumers**, so new record types stay backward compatible.
- No percentages anywhere — only counts (`executable` / `executed`), so nobody re-derives two different percentages.
- Paths are always complete paths relative to `root`; never a bare basename.
- Field order is stable and records are sorted by `path`, so the same dumps plus `--jsonl-no-timestamp` produce byte-identical output.
- `total` is the completeness sentinel: a truncated file simply has no `total` line. `records` holds the number of records actually written per type (`meta` and `total` count themselves as well, so its entries add up to `wc -l`), so `grep -c '"t":"file"'` can be checked against `records.file`.
- Empty coverage still writes `meta` and `total` (`files:0`) — never an empty file. It also says so out loud: such a report is structurally indistinguishable from a real 0%, so the command line prints a prominent warning, `report.json` gets a top-level `warning`, and the JSONL gets `warning` inside `meta` plus a `t=warning` record. `complete` is deliberately left alone: it only means the generator finished normally.
- Exit codes are untouched by default: an empty report (no dump merged) still exits 0, because creating the directory first and adding dumps later is a legitimate pattern. `1` is still used when a requested `--jsonl` file cannot be written; add `--fail-on-empty` to make an empty report exit `2`.
- `detail`: `uncovered` (default) adds `unc` — the sorted list of lines that are executable but not executed, i.e. exactly the lines worth adding tests for. `full` adds `file_lines` with the whole `map` (`1` executed, `-1` executable but not executed, `-2` dead code; `n` is always `1` because this tool records whether a line ran, not how often). `none` adds neither. `unc` always equals the set of `-1` lines of `map`.

### Things worth knowing

- All groups are merged before reporting, so every `file` record is the **union** of the groups: `executed` is a union, never a sum (the denominator is the same source file in every group). There is no per-group `file` block yet.
- `loaded:false` (never included at all — suspected dead code) is different from `executed:0` (loaded but never reached — needs tests).
- `sig` lists **signature lines**: lines inside a function or method declaration header that the analyser counts as executable but the driver never marks as executed, in declarations whose body is otherwise fully executed. These are the lines behind "the driver says 29/29 but the report says 29/30", and they are not worth chasing; a method that was never called is *not* listed here, because its body is not fully executed. `sig` lines are **dropped from the executable count** before rendering, so they no longer appear in `executable`, `line_map`, `unc` or `uncovered_lines` — in the HTML report and in `report.json` / `report.jsonl` alike. That is what makes 100% reachable again; `sig` is kept as the record of which lines were dropped (`todo` / `todo_lines` = `unc - sig`, which is now simply `unc`).
- `--jsonl-per-group` and `--jsonl-compress` are not implemented yet.

## Test Directive Reference

### Basic directives

| Directive | Description |
|---|---|
| `WEB <uri> [post] [AJAX\|OPTIONS]` | Play an HTTP request. The second part is POST data (`a=1&b=2`); the third part may be `AJAX` or `OPTIONS`. |
| `RUN <command>` | Re-dispatch a CLI command of the same application **in the current process** (no child process), which is what makes its coverage collectable. |
| `CALL <class/@method [name=value]>` | Invoke a local callable object (class or function). If it throws, the exception goes to `DuckCoverage.exception.log` through `logException()` and playback continues with the next line (the dump is still closed properly). |
| `SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | Set curl / web hooks for subsequent `WEB` lines (`_` clears a hook). |
| `PHASE <phase>` | Switch the DuckPHP phase. |
| `COMMENT text` | Ignore the line. |

### Macro directives

Macro directives are expanded by `TestListerHelper::explainMarco()` in the text returned by the callback:

| Directive | Description |
|---|---|
| `#PHASE_BEGIN` | Enter the current Phase; no arguments. |
| `#PHASE_END` | Leave the current Phase; no arguments. |
| `#INCLUDE_CALL <handler>` | Invoke a handler and embed the result in the current test list. |
| `#INCLUDE_CHILD <child>` | Add the child application's callback list to the current test list, preceded by a `COMMENT APP <child>` line. |
| `#BUSINESS <Class@method> [args]` | Rewrite the line into a `CALL` against `<namespace>\Business\<Class>@<method>`. |
| `#MODEL <Class@method> [args]` | Rewrite the line into a `CALL` against `<namespace>\Model\<Class>@<method>`. |
| `#ACTION <Class@method> [args]` | Rewrite the line into a `CALL` against `<namespace>\Controller\<Class>@<method>`. |
| `#ADMIN_LOGIN` / `#ADMIN_LOGOUT` / `#ADMIN_CLEAN` | Embed the admin provider's list for that state. Switches to the admin provider's phase, sets `options['duckcoverage_test_lister_parameter']` to `login` / `logout` / `clean`, expands the callback's list, then switches back. Same as `TestListerHelper::TestListByAdminLogin()` etc. |
| `#USER_LOGIN` / `#USER_LOGOUT` / `#USER_CLEAN` | Same for the user provider — `TestListerHelper::TestListByUserLogin()` etc. |
| `#CURRENT_PHASE` | Emit `PHASE {App::Phase()}` — writes the current phase into the list. It does not switch phases and does not touch `last_phase`. |

`#BUSINESS`, `#MODEL` and `#ACTION` are produced by `genTestListOfAll()`; they are shorthand so that generated lists stay readable. Every rewritten `CALL` is prefixed with the current phase.

### Collecting admin and user providers

DuckAdmin- and DuckUser-style applications have several providers (admin, user) and only one of them can be active at a time, so a single test list cannot cover them all — each provider needs its own collection run. `options['duckcoverage_test_lister_parameter']` carries *which state* is being collected (`login` / `logout` / `clean`) into your lister, and these helpers switch to the provider's phase before asking for the list:

| What you write in the list | What happens |
|---|---|
| `#ADMIN_LOGIN` / `#ADMIN_LOGOUT` / `#ADMIN_CLEAN` | Switch to the admin provider's phase, set the parameter, embed the expanded list, switch back. Same as `TestListerHelper::TestListByAdminLogin()` and friends. |
| `#USER_LOGIN` / `#USER_LOGOUT` / `#USER_CLEAN` | Same for the user provider (`TestListByUserLogin()` and friends). |
| `#CURRENT_PHASE` | Writes the current phase into the list as `PHASE {App::Phase()}`; it does not switch phases. |

Your lister can consume the parameter in either of two ways:

- Read `App::_()->options['duckcoverage_test_lister_parameter']` yourself (the ready-made callbacks clear it after use).
- Extend `DuckCoverage\TestListWithAuthBase` and implement the four branches; `GetTestList()` reads the parameter, **consumes** it (`unset`) and dispatches — a missing or unknown parameter goes to `_GetTestListFull()`.

```php
class MyTestList extends \DuckCoverage\TestListWithAuthBase
{
    public function _GetTestListForLogin(): string  { return "WEB /admin/user/list"; }
    public function _GetTestListForLogout(): string { return "WEB /admin/logout"; }
    public function _GetTestListForClean(): string  { return "WEB /admin/setting/clear"; }
    public function _GetTestListFull(): string      { return "#ADMIN_LOGIN\n#ADMIN_LOGOUT"; }
}
```

Point `duckcoverage_test_lister` at it and collect one group per state. `--flag` (see “Passing a flag to the application”) rides along in the dump name and in the request header, so every collection stays distinguishable:

```bash
php cli.php cover --go admin_login --flag=admin
```

### Additional notes

- `#PHASE_BEGIN` and `#PHASE_END` take no arguments and mark the beginning and end of a phase.
- `CALL` and `#INCLUDE_CALL` use the form `{phase}!class[->|::@]method parameter=value`; without `!`, no phase is used.
- `#INCLUDE_CALL` invokes a handler and embeds the result in the current test list.
- `RUN`: if a subcommand starts with `:`, the leading colon is stripped and the rest is used as an absolute command name instead of being prefixed with the application command prefix.
- The URI in `WEB` is wrapped by the `__url()` function, and the current URL base is stripped before the request is sent.
- Hooks configured with `SETWEB` are consumed by the next web call, and where they run decides their phase:
  - `pre_curl` / `post_curl` run **locally** in the current process with callback arguments `$ch, $name`, so they keep the **current phase** of the play list.
  - `pre_webcall` / `post_webcall` run **remotely** on the server, triggered by the `X-DuckCoverage-BeforeRun` / `X-DuckCoverage-AfterRun` request headers, so they run in the **root phase** — not in the phase the play list is currently in.
  - To place a hook in another phase, write it as `<phase>!<handler>`, the same form `CALL` uses.
- Empty lines are ignored.

## Options Reference

All options are passed through the DuckPHP application options and use the `duckcoverage_` prefix:

```php
public $options = [
    'duckcoverage_test_lister' => null,
    // Reserved: when true, init() returns immediately and skips all configuration.
    'duckcoverage_stop_init' => false,

    'duckcoverage_data_file_json_file' => 'DuckPhpData-duckcoverage.config.json',
    'duckcoverage_reg_console_command' => true,

    'duckcoverage_path' => '',
    'duckcoverage_path_src' => 'src/',
    'duckcoverage_report_direct' => false,
    'duckcoverage_report_default_dir' => 'AAAAA.report',

    'duckcoverage_web_base_url' => '',
    // Base URL for an external server such as nginx, for example http://www.example.com/; when empty, use the built-in test server.
    'duckcoverage_server_port' => 8017,
    'duckcoverage_server_host' => '',
    'duckcoverage_path_server' => '',
    'duckcoverage_path_document' => 'public',
    'duckcoverage_homepage' => '/',
    'duckcoverage_new_server' => true,

    'duckcoverage_debug_curl_echo_back' => false,
];
```

| Option | Default | Description |
|---|---|---|
| `duckcoverage_enable` | — | Main switch. It is read through `App::Setting()`, so set it in the application setting file (`config/DuckPhpSettings.config.php`) or in `.env`; it is not an application option of this package. |
| `duckcoverage_stop_init` | `false` | Reserved for the future. When `true`, `init()` returns immediately and skips all configuration — the extension is not set up at all. Unrelated to the switch above. |
| `duckcoverage_test_lister` | `null` | Callable returning the play list; its `GetTestList()` text is expanded through `explainMarco()`. Ready-made callbacks for admin/user providers: `TestListerHelper::TestListByAdminLogin()` / `...AdminLogout()` / `...AdminClean()` and the `...UserLogin()` / `...UserLogout()` / `...UserClean()` trio — each switches to that provider's phase first. Apps with admin/user providers can also extend `TestListWithAuthBase` and implement `_GetTestListForLogin()` / `_GetTestListForLogout()` / `_GetTestListForClean()` / `_GetTestListFull()`: the parameter set by `#ADMIN_LOGIN` and friends selects the branch and is consumed by the base class. |
| `duckcoverage_flag` | `''` | A string carried into the application: read it with `getFlag()`; in web mode it travels as the `X-DuckCoverage-Flag` request header. Overridden by `--flag=<value>`. |
| `duckcoverage_exclude` | `[]` | Directories or files to leave out of collection and reports. Entries may be project-relative (`src/ThirdParty`), source-relative (`ThirdParty`), absolute, or a `*` / `?` glob; a directory excludes everything below it. Add more at runtime with `exclude()`. |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | Moves the additional options file to a new location to isolate the configuration environment. While a group is watched it becomes `DuckCoverage/<group>.DuckPhpData.config.json`. |
| `duckcoverage_reg_console_command` | `true` | Register the CLI command so that `cover` is available. Registration happens before the enable check, so `cover` can report that the feature is switched off. |
| `duckcoverage_path` | `<runtime>/DuckCoverage/` | Base path for watch markers, dumps and reports. Always derived from the runtime path at init time. |
| `duckcoverage_path_src` | `'src/'` | Source directory used for coverage. A relative value is resolved against the **project path**, so the default is `<project>/src/`; pass an absolute path to be explicit. **Configure it for your own sources.** |
| `duckcoverage_report_direct` | `false` | When false and exactly one group is reported, write to `<group>.report/`; when true (or with several groups), write to `duckcoverage_report_default_dir` instead. |
| `duckcoverage_report_default_dir` | `'AAAAA.report'` | Report directory used for multi-group reports and for `duckcoverage_report_direct => true`. |
| `duckcoverage_web_base_url` | `''` | Base URL for an external server such as nginx; when empty, use the built-in test server. |
| `duckcoverage_server_port` | `8017` | Port for the built-in test server. |
| `duckcoverage_server_host` | `''` | Address the built-in test server binds to; `''` means `127.0.0.1`. Played requests are sent to the same host, except that a wildcard bind address (`0.0.0.0`, `::`) falls back to `127.0.0.1`. |
| `duckcoverage_path_server` | Project root | Project path served by the built-in server. |
| `duckcoverage_path_document` | `public` | Document root for the built-in server. |
| `duckcoverage_homepage` | `/` | Base URI appended to the built-in server URL. |
| `duckcoverage_new_server` | `true` | Create a new `HttpServer` instance during play, clearing server configuration that may have been overwritten previously. |
| `duckcoverage_debug_curl_echo_back` | `false` | Show the first 200 characters of each curl response for debugging. |

## How It Works

1. `--watch <group>` writes the watching marker (`<runtime>/DuckCoverage/DuckCoverage.watching.txt`) and a lock file.
2. On each request, `init()` resolves the current group from that marker. If a group is being watched, collection starts (`doBegin`) and the request is named — from `argv` for CLI (`MAN-RUN ...`), or from `REQUEST_URI` plus POST data for HTTP (`MAN-WEB ...`), unless the `X-DuckCoverage-Name` header supplies a name.
3. The end of collection depends on the mode:
   - **Default (whole request):** a `register_shutdown_function` callback runs the `AfterRun` handler and `doEnd()`, so the whole request — bootstrap included — is measured.
   - **Route hook mode:** after the application is initialized, call `DuckCoverage::InitedThenGoRouteHookMode()`. The initial collection is closed and `BeforeRun` / `AfterRun` are registered as route hooks (`prepend-outter` / `finally-outter`), so only the routing phase is measured.
4. `doEnd()` writes a dump to `<runtime>/DuckCoverage/<group>/<date>-<sha1(name)>.php` and appends the request name to `<group>.list.log`.
5. `--play` reads the test list from the callback and executes it line by line:
   - `WEB` requests are played with curl against the built-in test server (or the external server configured by `duckcoverage_web_base_url`) and are collected again because the group is still being watched.
   - `CALL` directives invoke local classes or functions through reflection.
   - `RUN` directives re-enter the application's own CLI dispatch in the same process.
6. `--report` builds a fresh `CodeCoverage`, includes `duckcoverage_path_src`, merges every dump of the requested groups, fills in the executable lines of partially covered files (so a partially covered file is not reported as 100%), and renders the HTML report.

## Internals

These classes make up the package:

| Class | Role |
|---|---|
| `DuckCoverage\DuckCoverage` | The DuckPHP extension: lifecycle hooks, CLI command, directive interpreter, built-in HTTP server and curl client (the latter two are traits). |
| `DuckCoverage\GroupCoverage` | Collecting, merging and reporting: `doBegin()` / `doEnd()` / `createReport()`, dump naming, merging, and the partial-coverage fix-up. |
| `DuckCoverage\CoverageJsonReport` | Formats the machine-readable `report.json` that sits next to the HTML report: paths, counts, uncovered lines, units, signature lines. Reads coverage data only. |
| `DuckCoverage\CoverageJsonlReport` | Turns the `report.json` array into the line-oriented `report.jsonl`: one record per line, `meta` first and `total` last. Pure transformation, touches no coverage data. |
| `DuckCoverage\TestListerHelper` | Builds and expands test lists: macros, plus generated route / command / component lists. |

`GroupCoverage` and `CoverageJsonReport` are the two classes that talk to `phpunit/php-code-coverage`; when its API changes, check both. `GroupCoverage` pauses `LibCoverage` while it collects (`doPause()` / `doResume()`), so the two tools can coexist in one process.

## Related Methods

Public methods:

```php
public static function BeforeRun()
public static function AfterRun()

public static function Prepare($options = [])
public static function InitedThenGoRouteHookMode()

public function beforeInit($options = [])
public function init(array $options, ?object $context = null)

public function runWithRouteHookMode()
public function _OnBeforeRun()
public function _OnAfterRun()

public function command_cover()
public function doCommand()
public function genTestListOfAll()
public function callHandler($handler, $ext_args = [])
public function watchingGetName()
public function logException(\Throwable $ex)
public function explainMarco($test_list)
public function exclude($paths = [])
```

- `BeforeRun` / `AfterRun` (`_OnBeforeRun` / `_OnAfterRun`) are hook callbacks; they only do anything in route hook mode.
- The important initialization method is `Prepare()`, which should be called from the root application's `onPrepare`.
- `init()` inserts the extension into the application.
- `watchingGetName()` returns the group currently being watched: the in-process group if there is one, otherwise the group recorded in `DuckCoverage.watching.txt` (that is, the last `--watch`). It returns `false` when nothing is being watched. It is public so a test-list callback or diagnostics code can ask "which group am I collecting into?".
- `explainMarco($test_list)` expands the macro directives of a test list (`#PHASE_BEGIN`, `#BUSINESS`, `#ADMIN_LOGIN`, …) and returns the result; the `GetTestList()` callback can call it when it wants to expand a fragment itself. It is the same expansion the extension applies to your callback's text.
- `exclude($paths)` adds directories or files to the exclusion list; it returns `$this` (so calls can be chained) and accepts a string as well as an array. Entries may be project-relative (`src/ThirdParty`), source-relative (`ThirdParty`), absolute, or a glob such as `src/*\/Generated`; excluding a directory excludes every file below it (Windows backslashes are normalised to `/`). Excluded files are neither collected nor listed in the HTML report, `report.json` or `report.jsonl`.
- `InitedThenGoRouteHookMode()` switches to route hook mode and must be called after the application has been initialized.
- `genTestListOfAll()` generates a test list from routes, console commands and `Business` / `Model` components; paste the output into your `GetTestList()` as a starting point.
- `command_cover` registers the CLI command.

`DuckCoverage\GroupCoverage` exposes `_()`, `init()`, `getCoverage()`, `doBegin()`, `doEnd()` and `createReport()`; `duckcoverage_test_lister` callbacks that need to build a list programmatically can use `DuckCoverage\TestListerHelper::_()`.

## Development

The package's own test suite is bootstrapped by LibCoverage:

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
```

`tests/bootstrap.php` points LibCoverage at `test_coveragedumps/` and `test_reports/`, which is also why those two directory names appear throughout the project. Compatibility testing across PHP versions is handled by LibCoverage's own CI matrix.

## Frequently Asked Questions

**Q: I set `duckcoverage_enable` but nothing is collected.**

Check the DuckPHP application's setting item `duckcoverage_enable` — that is what `App::Setting('duckcoverage_enable')` reads. While it is off, `cover` only prints `turn on setting to work: 'duckcoverage_enable'` and no dump is written.

**Q: My application code is not included in the report.**

`duckcoverage_path_src` is missing or incorrect. Its default is `src/` resolved against your project path, so it only works when your sources really live in `<project>/src/`. Configure it explicitly (an absolute path is recommended).

**Q: I get “Need CodeCoverage” or cannot create `CodeCoverage`.**

The Xdebug/PCOV driver is not loaded. See the “Requirements” section.

**Q: `--play` does nothing.**

Playback depends on the `duckcoverage_test_lister` callback. Configure the callback first, or run a watched request to generate a `<group>.list.log` as a reference.

**Q: The port is already in use.**

Change `duckcoverage_server_port` (the default is `8017`).

**Q: The built-in server cannot start.**

Make sure `duckcoverage_path_server` (the project root by default) and `duckcoverage_path_document` (`public` by default) point to the correct locations, and that `duckcoverage_homepage` matches your development entry point.

**Q: `--go` produced a report in `AAAAA.report` instead of `<group>.report`.**

That happens when `duckcoverage_report_direct` is `true`: the option applies to `--go` exactly as it does to `--report <group>`. Set `duckcoverage_report_direct => false` (the default) to get the `<group>.report/` layout, or change `duckcoverage_report_default_dir` to rename the flattened directory.

**Q: How do I combine multiple rounds of tests?**

Watch the same group name so that multiple rounds of requests accumulate in that group's dumps. Then run `--report mygroup` once to generate a report. You can also merge multiple groups with `--report a b c`.

**Q: When is a request collected?**

Whenever a group is watched: the group is read from `DuckCoverage.watching.txt` on the server side. Stop watching with `--stop` to stop collecting, and use route hook mode if you want to exclude framework bootstrap code.

## Related Projects

- [DuckPHP](https://github.com/dvaknheo/duckphp) — The framework served by this extension. DuckPHP's development version is maintained alongside this project, and DuckCoverage versions evolve with DuckPHP.
- [LibCoverage](https://github.com/dvaknheo/libcoverage) — Coverage tooling for standalone PHP libraries; it bootstraps and cross-version-tests this package.

## License

[MIT](LICENSE)