# DuckCoverage

[中文](README-zh.md) | [Quick Start](QUICKSTART.md) | [中文快速手册](QUICKSTART-zh.md)

**DuckCoverage** is a test-coverage extension for [DuckPHP](https://github.com/dvaknheo/duckphp) applications.
It collects line coverage from real HTTP requests and CLI calls, replays recorded requests, and renders HTML coverage reports — without writing any unit tests.

It is the DuckPHP counterpart of [LibCoverage](https://github.com/dvaknheo/libcoverage) (coverage for plain PHP libraries), sharing the same `test_coveragedumps` / `test_reports` conventions.

## Features

- **Coverage from real traffic** — every web request (browser, curl, or automated tools) can be traced by sending a `X-MyCoverage-Name` header.
- **Request recording** — watched requests are appended to a replayable list file (`test_coveragedumps/<group>.list`).
- **Replay** — recorded requests are re-run against a built-in PHP test server or an external server (nginx, etc.), each replayed request contributing coverage.
- **Direct CLI calls** — call any class method or function locally with `--call` and cover it.
- **Group workflow** — `--watch <group>` → browse/replay → `--report` renders an HTML report per group.
- **HTML reports** — powered by `phpunit/php-code-coverage`.

## Requirements

- PHP >= 7.4
- DuckPHP (>= 1.4, includes the `DuckPhp\HttpServer\HttpServer` component)
- `phpunit/php-code-coverage` (9.x)
- A coverage driver: **Xdebug** or **PCOV** must be loaded

> **Note:** the current `composer.json` only declares `dvaknheo/libcoverage` as a dev dependency; DuckPHP, php-code-coverage and a coverage driver must be installed in your application explicitly (see [QUICKSTART.md](QUICKSTART.md)).

## Installation

```bash
composer require dvaknheo/duckcoverage
```

## Quick Start

```php
// src/System/App.php — register the extension in your DuckPHP application
class App extends \DuckPhp\DuckPhp
{
    public $options = [
        // ... your options ...
        'ext' => [
            \DuckCoverage\DuckCoverage::class => true,
        ],
        // optional tuning (defaults shown)
        // 'duckcoverage_enable' => true,
        // 'duckcoverage_path_src' => __DIR__ . '/../',   // source dirs to cover
        // 'duckcoverage_server_port' => 8080,
    ];
}
```

```bash
php cli.php duckcover --watch mygroup      # start watching group "mygroup"
curl -H "X-MyCoverage-Name: mygroup" http://127.0.0.1:8080/index_dev.php/
                                           # browse your app, every traced request is recorded & covered
php cli.php duckcover --replay             # replay recorded requests (requires a callback class, see below)
php cli.php duckcover --report             # render HTML report for the current group
# open runtime/test_reports/index.html
php cli.php duckcover --stop               # stop watching
```

The CLI entry file (`cli.php`) follows your DuckPHP project template; adjust it if your project uses another entry.

## CLI Reference

`php cli.php duckcover` (the DuckPHP CLI entry) supports the following parameters:

| Parameter | Description |
|---|---|
| *(no args)* / `--help` | print usage help |
| `--watch <name>` | start recording test group `<name>` (no name → auto timestamp). Writes `runtime/DuckCoverage.watching.txt` |
| `--stop` | stop watching (removes the watching marker) |
| `--replay` | replay the test list returned by the callback class `GetTestList()` |
| `--call <Class>@<method>` | directly call a local method and collect coverage for it (slashes are converted to backslashes) |
| `--report [a b c]` | render the HTML report for the given groups (default: current watching group), print output path & time cost |

## Options

All options are passed through the DuckPHP application options (keys starting with `duckcoverage_`).

### DuckCoverage options

| Option | Default | Description |
|---|---|---|
| `duckcoverage_enable` | `true` | master switch of the extension |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | overrides the App `data_file_json_file` while enabled |
| `duckcoverage_save_web_request_list` | `true` | append each traced web request to `test_coveragedumps/<group>.list` |
| `duckcoverage_save_local_call_list` | `false` | *(reserved)* whether `#CALL` commands are recorded to the list file |
| `duckcoverage_server_port` | `8080` | port of the built-in test server |
| `duckcoverage_server_host` | `''` | host of the built-in test server |
| `duckcoverage_path_server` | `''` | project path served by the built-in server (defaults to the project root) |
| `duckcoverage_path_document` | `'public'` | document root of the built-in server |
| `duckcoverage_homepage` | `'/index_dev.php/'` | base URI appended to the built-in server URL |
| `duckcoverage_new_server` | `true` | start a fresh `HttpServer` instance for replay |
| `duckcoverage_web_base_url` | `''` | external server base URL (e.g. `http://admin.duckphp-local.com/`); empty → use the built-in test server |
| `duckcoverage_callback_class` | `null` | callback class implementing `DuckCoverageCBInterface` |
| `duckcoverage_report_direct` | `true` | write reports directly under `test_reports/` (otherwise per-group / per-date subdirectories) |
| `duckcoverage_echo_back` | `false` | echo the first 200 chars of each replayed response |

### Coverage options (from `CoverageBase`)

| Option | Default | Description |
|---|---|---|
| `duckcoverage_path` | `runtime/` | base directory of dumps and reports |
| `duckcoverage_path_src` | package `src/` | source directory(ies) to include in coverage. **Configure it explicitly** to point at your application source |
| `duckcoverage_path_dump` | `test_coveragedumps` | directory of coverage dump files |
| `duckcoverage_path_report` | `test_reports` | directory of HTML reports |
| `duckcoverage_group` | `''` | current test group (set by `--watch`) |
| `duckcoverage_name` | `''` | current test name (per request / per call) |

## How It Works

1. `--watch <group>` writes the watching marker (`runtime/DuckCoverage.watching.txt`).
2. When a request arrives with the header `X-MyCoverage-Name: <group>` matching the watched group, `_OnBeforeRun` / `_OnAfterRun` (registered via `register_shutdown_function`) start/stop coverage, append the request to `<group>.list`, and dump coverage to `test_coveragedumps/<group>/`.
3. `--replay` reads the test list from the callback class `GetTestList()` and executes it line by line:
   - `#WEB` requests are replayed via curl against the built-in test server (or the external server when `duckcoverage_web_base_url` is set), carrying the `X-MyCoverage-Name` header so the traced code collects coverage again.
   - `#CALL` commands invoke local classes/functions directly via reflection.
4. `--report` merges all dumps of the group(s) and renders an HTML report into `runtime/test_reports/` (unless `duckcoverage_path` / `duckcoverage_path_report` are overridden).

### Callback class (`DuckCoverageCBInterface`)

Implement `GetTestList()` to drive the replay:

```php
class MyTester implements \DuckCoverage\DuckCoverageCBInterface
{
    public static function BeforeReplayTest() { /* hook, reserved */ }
    public static function AfterReplayTest() { /* hook, reserved */ }
    public static function OnReport() { /* hook, reserved */ }

    public static function GetTestList()
    {
        return <<<EOT
#WEB /admin/index
#WEB /admin/login
#SETWEB _ _ _ _
#CALL MyApp/Test/Tester@doSomething
EOT;
    }
}
```

Test-list directives:

| Directive | Meaning |
|---|---|
| `#WEB <uri> [post] [AJAX\|OPTIONS]` | replay an HTTP request (URI is prefixed with `#URL_PREFIX` when set) |
| `#CALL <Class>@<method>\|Class->method\|Class::method\|function [?param=value]` | invoke a local callable |
| `#SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | set curl / web hooks for the following `#WEB` lines (`_` clears) |
| `#PHASE <phase>` | switch the DuckPHP phase |
| `#URL_PREFIX <prefix>` | URL prefix prepended to later `#WEB` URIs |
| `## comment` / empty line | ignored |

## Related Projects

- [DuckPHP](https://github.com/dvaknheo/duckphp) — the framework this extension targets (its development version lives alongside this project; DuckCoverage tracks DuckPHP releases).
- [LibCoverage](https://github.com/dvaknheo/libcoverage) — coverage for standalone PHP libraries.

## License

[MIT](LICENSE)
