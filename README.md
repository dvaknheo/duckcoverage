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
        'setting' => [
            'duckcoverage_enable' => true,
        ],

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

> Unlike many other DuckPHP plugins, DuckCoverage must be initialized in the **root application's** `onPrepare`, as shown above. DuckCoverage options must also be placed in the root application's application options.
>
> Note where the switch lives: `Prepare()` reads it with `App::Setting('duckcoverage_enable')`, which resolves against the application **setting** — the `setting` option shown above, the setting file (`config/DuckPhpSettings.config.php`) or `.env`. A top-level `'duckcoverage_enable' => true` in `$options` does **not** enable DuckCoverage. An application that overrides `_Setting()` can of course answer for this key itself.

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

The server also answers every request with two diagnostic headers: `x-duckcoverage-group` (the group the request was collected into) and `x-duckcoverage-datafile` (the data file in use).

## Test Directive Reference

### Basic directives

| Directive | Description |
|---|---|
| `WEB <uri> [post] [AJAX\|OPTIONS]` | Play an HTTP request. The second part is POST data (`a=1&b=2`); the third part may be `AJAX` or `OPTIONS`. |
| `RUN <command>` | Re-dispatch a CLI command of the same application **in the current process** (no child process), which is what makes its coverage collectable. |
| `CALL <class/@method [name=value]>` | Invoke a local callable object (class or function). |
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

`#BUSINESS`, `#MODEL` and `#ACTION` are produced by `genTestListOfAll()`; they are shorthand so that generated lists stay readable. Every rewritten `CALL` is prefixed with the current phase.

### Additional notes

- `#PHASE_BEGIN` and `#PHASE_END` take no arguments and mark the beginning and end of a phase.
- `CALL` and `#INCLUDE_CALL` use the form `{phase}!class[->|::@]method parameter=value`; without `!`, no phase is used.
- `#INCLUDE_CALL` invokes a handler and embeds the result in the current test list.
- `RUN`: if a subcommand starts with `:`, the leading colon is stripped and the rest is used as an absolute command name instead of being prefixed with the application command prefix.
- The URI in `WEB` is wrapped by the `__url()` function, and the current URL base is stripped before the request is sent.
- Hooks configured with `SETWEB` are consumed by the next web call. `pre_curl` and `post_curl` run locally in the current process with callback arguments `$ch, $name`; `pre_webcall` and `post_webcall` run on the server, triggered by the `X-DuckCoverage-BeforeRun` / `X-DuckCoverage-AfterRun` request headers.
- Empty lines are ignored.

## Options Reference

All options are passed through the DuckPHP application options and use the `duckcoverage_` prefix:

```php
public $options = [
    // The switch is read through App::Setting(), so it belongs in the application setting.
    'setting' => [
        'duckcoverage_enable' => true,
    ],
    'duckcoverage_test_lister' => null,

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
| `duckcoverage_enable` | `true` | Main switch. `Prepare()` reads it through `App::Setting()`, so it must be present in the application **setting** (`$options['setting']['duckcoverage_enable']`, the setting file, or `.env`); a top-level option alone leaves DuckCoverage inert. The component option of the same name is checked again in `init()`, where a top-level `false` stops collection even if the setting says `true`. |
| `duckcoverage_test_lister` | `null` | Callable returning the play list; its `GetTestList()` text is expanded through `explainMarco()`. |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | Moves the additional options file to a new location to isolate the configuration environment. While a group is watched it becomes `DuckCoverage/<group>.DuckPhpData.config.json`. |
| `duckcoverage_reg_console_command` | `true` | Register the CLI command so that `cover` is available. Registration happens before the enable check, so `cover` can report that the feature is switched off. |
| `duckcoverage_path` | `<runtime>/DuckCoverage/` | Base path for watch markers, dumps and reports. Always derived from the runtime path at init time. |
| `duckcoverage_path_src` | `'src/'` | Source directory used for coverage. A relative value is resolved against the **project path**, so the default is `<project>/src/`; pass an absolute path to be explicit. **Configure it for your own sources.** |
| `duckcoverage_report_direct` | `false` | When false and exactly one group is reported, write to `<group>.report/`; when true (or with several groups), write to `duckcoverage_report_default_dir` instead. |
| `duckcoverage_report_default_dir` | `'AAAAA.report'` | Report directory used for multi-group reports and for `duckcoverage_report_direct => true`. |
| `duckcoverage_web_base_url` | `''` | Base URL for an external server such as nginx; when empty, use the built-in test server. |
| `duckcoverage_server_port` | `8017` | Port for the built-in test server. |
| `duckcoverage_server_host` | `''` | Host for the built-in test server. |
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

Three classes make up the package:

| Class | Role |
|---|---|
| `DuckCoverage\DuckCoverage` | The DuckPHP extension: lifecycle hooks, CLI command, directive interpreter, built-in HTTP server and curl client (the latter two are traits). |
| `DuckCoverage\GroupCoverage` | The only place that talks to `phpunit/php-code-coverage`: `doBegin()` / `doEnd()` / `createReport()`, dump naming, merging, and the partial-coverage fix-up. |
| `DuckCoverage\TestListerHelper` | Builds and expands test lists: macros, plus generated route / command / component lists. |

`GroupCoverage` pauses `LibCoverage` while it collects (`doPause()` / `doResume()`), so the two tools can coexist in one process.

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
```

- `BeforeRun` / `AfterRun` (`_OnBeforeRun` / `_OnAfterRun`) are hook callbacks; they only do anything in route hook mode.
- The important initialization method is `Prepare()`, which should be called from the root application's `onPrepare`.
- `init()` inserts the extension into the application.
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

The switch is read through `App::Setting()`, so it must be in the application setting (`$options['setting']['duckcoverage_enable'] => true`, the setting file or `.env`). Putting `'duckcoverage_enable' => true` at the top level of `$options` is not enough; in that case `cover` prints `turn on setting to work: 'duckcoverage_enable'` and no dump is written.

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
