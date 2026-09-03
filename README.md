# DuckCoverage

[English](README.md) | [中文](README.zh_CN.md)

**DuckCoverage** is a test coverage extension for [DuckPHP](https://github.com/dvaknheo/duckphp) applications. It collects line coverage from real HTTP requests and CLI calls, replays recorded requests, and generates HTML coverage reports without requiring you to write unit tests.

It works with [LibCoverage](https://github.com/dvaknheo/libcoverage), the coverage tool for standalone PHP libraries. Both projects use the `test_coveragedumps` / `test_reports` directory convention.

## Features

- **Coverage from real traffic** — Every web request (from a browser, curl, or an automated tool) can be traced by sending an `X-MyCoverage-Name` header.
- **Request recording** — Watched requests are appended to a replayable list file (`test_coveragedumps/<group>.list`).
- **Replay** — Recorded requests are replayed against the built-in PHP test server (or an external server such as nginx); every replayed request contributes coverage.
- **Direct CLI calls** — Use `RUN` / `CALL` to invoke local class methods or functions and collect coverage.
- **Grouped workflow** — `--watch <group>` → browse / replay → `--report` renders an HTML report for the group.
- **HTML reports** — Reports are rendered by `phpunit/php-code-coverage`.

## Requirements

| Item | Requirement |
|---|---|
| PHP | >= 7.4 |
| Coverage driver | **Xdebug** or **PCOV** must be loaded (visible in `php -m`) |
| Framework | DuckPHP >= 1.4.1 (including the `DuckPhp\HttpServer\HttpServer` component) |
| Dependency | `phpunit/php-code-coverage` (9.x) |

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
composer require --dev phpunit/php-code-coverage ^9.0   # Choose a version compatible with your PHP
```

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
        'duckcoverage_enable' => true,

        'duckcoverage_callback'=> [TestLister::class ,'GetTestList'],
        //'duckcoverage_report_direct' => false,
        //'duckcoverage_web_base_url' => 'http://www.example.com/',

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        if (class_exsits(DuckCoverage::class)) {
            DuckCoverage::Prepare([]);
        }
    }
}
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

This corresponds to the `duckcoverage_callback` callback. It returns a list of test directives; each line is one test directive. See the “Test Directive Reference” section below for details. `duckcoverage_callback` does not need to import DuckCoverage and can be used by child applications as well as the root application.

### 2. Run the tests

```bash
php cli.php duckcover --go group1
```

After execution, DuckCoverage runs the requests and calls returned by `duckcoverage_callback`, then generates the test coverage report at:

```text
runtime/DuckCoverage/group1.report/
```

The CLI entry file (`cli.php`) follows your DuckPHP project template. Replace it with your project's entry file if necessary.

## Workflow

Coverage collection, replay, and report generation are all controlled through the CLI command `php cli.php duckcover`; there is no separate interactive “browse and collect” workflow.

### One-step operation: `--go`

The most common approach is to use `--go <group>` to complete the entire workflow in one command. It is equivalent to:

```text
watch → replay → stop → report
```

```bash
php cli.php duckcover --go group1
```

- Result: runs the complete replay and generates a report for `group1`.
- Report location: `runtime/DuckCoverage/group1.report/`.
- This command is especially suitable for CI.

### Step-by-step operation

When you need to inspect intermediate states or extend the workflow manually, run the steps separately:

```bash
php cli.php duckcover --watch group1    # ① Start watching group1
php cli.php duckcover --replay          # ② Replay the requests/calls in the callback list
php cli.php duckcover --report group1   # ③ Render the HTML report for the group
php cli.php duckcover --stop            # ④ Stop watching
```

- `--replay` starts the built-in test server (or points to the external server configured with `duckcoverage_web_base_url`) and executes the test directives returned by `GetTestList()` one line at a time. Every replayed request is collected again, and the server is stopped afterward.
- `--report` merges all dumps for one group (or multiple groups with `--report a b c`) and renders an HTML report under `runtime/test_reports/`.

### Direct calls without HTTP

Class and function calls that do not use HTTP can be added to the replay callback with `CALL` (or to shell commands with `RUN`). They are executed and covered together with `--go` or `--replay`:

```text
CALL MyApp\Test\Tester@doSomething         # @ means use the singleton (call _())
CALL MyApp\Business\DemoBusiness->handle   # -> means create a new instance
CALL MyApp\Helper::format                  # :: means a static call
CALL some_function                         # A plain function
```

Arguments use `name=value` and are matched by parameter name:

```text
CALL MyApp\Test\Tester@runX parameter=d
```

Generated results and dumps are placed in the current group's `test_coveragedumps/` directory. `--report` generates the report as usual. See the `CALL` section in the “Test Directive Reference”.

### Using an external server (such as nginx)

To use an external server instead of the built-in test server, configure its base URL:

```php
'duckcoverage_web_base_url' => 'http://www.example.com/',
```

- Replaying `WEB /admin/index` then sends a request to `http://www.example.com/admin/index`.
- The external server must accept and forward the `X-MyCoverage-Name` header (nginx forwards custom headers by default).
- You must still start watching the same group with `--watch` for the matching logic to take effect.

## CLI Reference

All commands:

```bash
php cli.php duckcover
  --watch {group}
  --replay
  --stop
  --report group1
  --report group1 group2 group3
  --go {group}
```

| Argument | Description |
|---|---|
| (no arguments) / `--help` | Print usage help |
| `--watch <group>` | Start watching a test group. If no group is supplied, a timestamp is generated. Writes `runtime/DuckCoverage.watching.txt`. |
| `--stop` | Stop watching and remove the watching marker. |
| `--replay` | Replay the test list returned by the callback's `GetTestList()`. |
| `--report [a b c]` | Render an HTML report for the specified groups (the current watched group by default) and print the output path and elapsed time. |
| `--go <group>` | Combined command: `watch + replay + stop + report` in one step (CI-friendly). |

`--go group1` is equivalent to running:

```bash
php cli.php duckcover --watch group1
php cli.php duckcover --replay
php cli.php duckcover --stop
php cli.php duckcover --report group1
```

## Test Directive Reference

### Basic directives

| Directive | Description |
|---|---|
| `WEB <uri> [post] [AJAX|OPTIONS]` | Replay an HTTP request. The second part is POST data (`a=1&b=2`); the third part may be `AJAX` or `OPTIONS`. |
| `RUN <command>` | Execute a shell command unchanged (no escaping; a non-zero exit code only prints a warning and does not interrupt replay). It runs in the current process rather than a separate process. |
| `CALL <class/@method [name=value]>` | Invoke a local callable object (class or function). |
| `SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | Set curl / web hooks for subsequent `WEB` lines (`_` clears a hook). |
| `PHASE <phase>` | Switch the DuckPHP phase (an empty value is ignored). |
| `COMMENT text` | Ignore the line. |

### Macro directives

Macro directives are expanded by `explainMarco` in the text returned by the callback:

| Directive | Description |
|---|---|
| `#PHASE_BEGIN` | Enter the current Phase; no arguments. |
| `#PHASE_END` | Leave the current Phase; no arguments. |
| `#INCLUDE_CALL <handler>` | Invoke a handler and embed the result in the current test list. |
| `#INCLUDE_CHILD <child>` | Add the child application's callback list to the current test list. |

### Additional notes

- `#PHASE_BEGIN` and `#PHASE_END` take no arguments and mark the beginning and end of a phase.
- `CALL` and `#INCLUDE_CALL` use the form `{phase}!class[->|::@]method uri_encode`; without `!`, no phase is used.
- `#INCLUDE_CALL` invokes a handler and embeds the result in the current test list.
- `RUN`: if a subcommand starts with `:`, it is not treated as a subcommand. `RUN` is simulated in the current process rather than run in a separate process.
- The URI in `WEB` is wrapped by the `__url()` function.
- Hooks configured with `SETWEB` are restored after the next web call. `pre_curl` and `post_curl` run locally in the current process with callback arguments `$ch, $name`; `pre_webcall` and `post_webcall` run on the server.
- Empty lines are ignored.

## Options Reference

All options are passed through the DuckPHP application options and use the `duckcoverage_` prefix:

```php
public $options = [
    'duckcoverage_enable' => true,
    'duckcoverage_callback' => null,

    'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',
    'duckcoverage_reg_console_command' => true,

    'duckcoverage_path' => '',
    'duckcoverage_path_src' => 'src/',
    'duckcoverage_report_direct' => false,

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
| `duckcoverage_enable` | `true` | Main switch that enables DuckCoverage. |
| `duckcoverage_callback` | `null` | Callback whose `GetTestList()` provides the replay list. |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | Move the additional options file to a new location to isolate the configuration environment. |
| `duckcoverage_reg_console_command` | `true` | Register the CLI command so that `duckcover` is available. |
| `duckcoverage_path` | Runtime `DuckCoverage/` | Base path for dump and report directories. |
| `duckcoverage_path_src` | Package `src/` | Source directory used for coverage. **Configure this explicitly** to your source directory (an absolute path is recommended). |
| `duckcoverage_report_direct` | `false` | Write directly to the report directory instead of using group/date subdirectories. |
| `duckcoverage_web_base_url` | `''` | Base URL for an external server such as nginx; when empty, use the built-in test server. |
| `duckcoverage_server_port` | `8017` | Port for the built-in test server. |
| `duckcoverage_server_host` | `''` | Host for the built-in test server. |
| `duckcoverage_path_server` | Project root | Project path served by the built-in server. |
| `duckcoverage_path_document` | `public` | Document root for the built-in server. |
| `duckcoverage_homepage` | `/` | Base URI appended to the built-in server URL. |
| `duckcoverage_new_server` | `true` | Create a new `HttpServer` instance during replay, clearing server configuration that may have been overwritten previously. |
| `duckcoverage_debug_curl_echo_back` | `false` | Show the first 200 characters of each curl response for debugging. |

## How It Works

1. `--watch <group>` writes the watching marker (`runtime/DuckCoverage.watching.txt`).
2. When a request carries an `X-MyCoverage-Name: <group>` header matching the watched group, `_OnBeforeRun` / `_OnAfterRun` (registered through `register_shutdown_function`) start/stop collection, append the request to `<group>.list`, and write coverage dumps to `test_coveragedumps/<group>/`.
3. `--replay` reads the test list from the callback's `GetTestList()` and executes it line by line:
   - `WEB` requests are replayed with curl against the built-in test server (or the external server configured by `duckcoverage_web_base_url`) and include the `X-MyCoverage-Name` header, so the covered code is collected again.
   - `CALL` directives directly invoke local classes or functions through reflection.
4. `--report` merges all dumps for one or more groups and renders an HTML report under `runtime/test_reports/`.

## Related Methods

Public methods:

```php
public static function BeforeRun()
public static function AfterRun()

public static function Prepare($options = [])

public function beforeInit($options = [])
public function _OnAfterRun()
public function _OnBeforeRun()

public function init(array $options, ?object $context = null)
public function genTestListOfAll()
public function command_duckcover()
```

- `BeforeRun` / `AfterRun` (`_OnBeforeRun` / `_OnAfterRun`) are hook callbacks.
- The important initialization method is `Prepare()`, which should be called from the root application's `onPrepare`.
- `init()` inserts the extension into the application.
- `genTestListOfAll()` helps generate a list of test commands.
- `command_duckcover` registers the CLI command.

## Docker Environment (Optional)

The `docker/test-php84/` directory provides a PHP 8.4 + Xdebug Docker Compose environment. Its image definition matches the DuckPHP development version and can reuse the Docker build cache:

```bash
cd docker/test-php84
./start-docker.sh                                   # Build and start the duckcoverage-test84 container
./exec-docker.sh composer install --no-interaction --prefer-dist  # First dependency install (uses composer-test-php84.json)
./exec-docker.sh php -l src/DuckCoverage.php        # Syntax check
./exec-docker.sh php -r 'var_dump(PHP_VERSION);'    # Run any command in the container
./stop-docker.sh                                    # Stop the container (preserve container and volumes)
./end-docker.sh                                     # Stop and remove the container
```

Notes:

- The project root is mounted at `/DATA`; `composer-test-php84.json` overrides `composer.json` in the container (it additionally requires `dvaknheo/duckphp ^1.4.1` and `phpunit/php-code-coverage ^11.0` for testing). `vendor/` and the Composer cache use named volumes and are preserved across containers.
- `XDEBUG_MODE=coverage` is set, so coverage can be collected directly inside the container.
- `test_reports/` and `test_coveragedumps/` are mounted into the Docker directory for viewing on the host.

## Frequently Asked Questions

**Q: My application code is not included in the report.**

`duckcoverage_path_src` is missing or incorrect. Its default points to the DuckCoverage package's own `src/`; configure it explicitly to your source directory (an absolute path is recommended).

**Q: I get “Need CodeCoverage” or cannot create `CodeCoverage`.**

The `phpunit/php-code-coverage` package is missing, or the Xdebug/PCOV driver is not loaded. See the “Requirements” and “Installation” sections.

**Q: `--replay` does nothing.**

Replay depends on the `GetTestList()` method returned by the `duckcoverage_callback` callback. Configure the callback first, or run a traced request to generate a `.list` file as a reference.

**Q: The port is already in use.**

Change `duckcoverage_server_port` (the default is `8017`).

**Q: The built-in server cannot start.**

Make sure `duckcoverage_path_server` (the project root by default) and `duckcoverage_path_document` (`public` by default) point to the correct locations, and that `duckcoverage_homepage` matches your development entry point.

**Q: How do I combine multiple rounds of tests?**

Watch the same group name so that multiple rounds of requests accumulate in that group's dumps. Then run `--report mygroup` once to generate a report. You can also merge multiple groups with `--report a b c`.

**Q: The report directory is too cluttered.**

Set `duckcoverage_report_direct => false`; reports are then organized into group directories (single group) or a date-based directory (multiple groups).

## Related Projects

- [DuckPHP](https://github.com/dvaknheo/duckphp) — The framework served by this extension. DuckPHP's development version is maintained alongside this project, and DuckCoverage versions evolve with DuckPHP.
- [LibCoverage](https://github.com/dvaknheo/libcoverage) — Coverage tooling for standalone PHP libraries.

## License

[MIT](LICENSE)
