# DuckCoverage Quick Start

A hands-on guide for DuckPHP application developers: get an HTML line-coverage report of your web application in about 10 minutes.

> Languages: [中文快速手册](QUICKSTART-zh.md) · [English README](README.md) · [中文 README](README-zh.md)

---

## 1. Prerequisites

| Item | Requirement |
|---|---|
| PHP | >= 7.4 |
| Coverage driver | **Xdebug** or **PCOV** loaded (visible in `php -m`) |
| Framework | DuckPHP >= 1.4 (includes `DuckPhp\HttpServer\HttpServer`) |
| Package | `phpunit/php-code-coverage` (9.x, the `Driver\Selector` API) |

Check the driver:

```bash
php -m | findstr /i "xdebug pcov"        # Windows
php -m | grep -i -E "xdebug|pcov"        # Linux / macOS
```

Without a driver DuckCoverage fails when creating `CodeCoverage` (`doBegin` stage); install a driver first.

---

## 2. Installation

Inside your DuckPHP project:

```bash
composer require dvaknheo/duckcoverage
composer require --dev phpunit/php-code-coverage ^9.0   # pick the version matching your PHP
```

> duckcoverage's `composer.json` only declares `dvaknheo/libcoverage` under `require-dev` (used for developing this package itself); runtime dependencies must be installed explicitly.

---

## 3. Enable it in your DuckPHP application

Edit your application entry class (e.g. `src/System/App.php`):

```php
<?php
namespace MyApp\System;

use DuckCoverage\DuckCoverage;

class App extends \DuckPhp\DuckPhp
{
    public $options = [
        // ... your existing options ...

        // register the extension
        'ext' => [
            DuckCoverage::class => true,
        ],

        // common tuning (all optional; defaults in parentheses)
        'duckcoverage_enable' => true,                    // master switch
        'duckcoverage_path_src' => __DIR__ . '/../',      // source dirs to cover (REQUIRED! see below)
        'duckcoverage_server_port' => 8080,               // built-in test server port
        'duckcoverage_homepage' => '/index_dev.php/',     // built-in server base URI
        // 'duckcoverage_web_base_url' => 'http://admin.duckphp-local.com/', // set when using an external server
        // 'duckcoverage_callback_class' => \MyApp\Test\Tester::class,      // replay callback class
    ];
}
```

> **Important: `duckcoverage_path_src` must be configured explicitly.** Its default points to duckcoverage's own `src/` (you would measure this extension instead of your app). Use an absolute path (e.g. `__DIR__ . '/../'`) or a path relative to `runtime/` (e.g. `'../src'`).

---

## 4. Workflow A: Web testing (recommended)

The core use case: **browse your site for real, coverage is collected automatically, then replay and report.**

### 4.1 Start watching

```bash
php cli.php duckcover --watch mygroup
```

It prints `watching mygroup` and writes `runtime/DuckCoverage.watching.txt`. The group name is arbitrary; use it to separate test rounds.

### 4.2 Fire requests (with the tracing header)

Use curl with the tracing header:

```bash
curl -H "X-MyCoverage-Name: mygroup" "http://127.0.0.1:8080/index_dev.php/admin/index"
curl -H "X-MyCoverage-Name: mygroup" -d "username=admin&password=123456" "http://127.0.0.1:8080/index_dev.php/admin/login"
```

- Built-in server: URL = `http://127.0.0.1:{duckcoverage_server_port}` + `duckcoverage_homepage` + your path.
- External server (nginx etc.): set `duckcoverage_web_base_url` first, then use the external URL.
- Manual browser testing works too, but you need to customize the request header (e.g. via DevTools or a proxy tool).
- Every traced request will: ① append to `test_coveragedumps/mygroup.list` (request log); ② collect and dump coverage into `test_coveragedumps/mygroup/`.

### 4.3 Configure the replay callback class

`--replay` needs a callback class providing the test list. Create a class implementing `DuckCoverageCBInterface` (e.g. `src/Test/Tester.php`):

```php
<?php
namespace MyApp\Test;

use DuckCoverage\DuckCoverageCBInterface;

class Tester implements DuckCoverageCBInterface
{
    public static function BeforeReplayTest() {}
    public static function AfterReplayTest() {}
    public static function OnReport() {}

    public static function GetTestList()
    {
        // Paste the content of test_coveragedumps/mygroup.list here,
        // or write it by hand (directives: #WEB / #CALL / #SETWEB / #PHASE / #URL_PREFIX)
        return <<<EOT
#WEB /admin/index
#WEB /admin/login username=admin&password=123456
#CALL MyApp/Test/Tester@doSomething
EOT;
    }
}
```

Then register it in `App::$options`:

```php
'duckcoverage_callback_class' => \MyApp\Test\Tester::class,
```

### 4.4 Replay

```bash
php cli.php duckcover --replay
```

Replay starts the built-in test server (or targets the external one), executes the test list line by line, and every request collects coverage again (curl automatically adds the `X-MyCoverage-Name` header). The server is stopped when it finishes.

### 4.5 Generate the report

```bash
php cli.php duckcover --report
```

Example output:

```
reporting...
time_cost   : 0.523 seconds
output path : runtime/test_reports/
```

Open `runtime/test_reports/index.html` in a browser to inspect line coverage. Red/yellow rows are lines that were never executed.

### 4.6 Stop watching

```bash
php cli.php duckcover --stop
```

---

## 5. Workflow B: Direct CLI calls

Call classes/methods/functions directly without HTTP:

```bash
# class names use slashes (converted to backslashes); @ means singleton _() call
php cli.php duckcover --call MyApp/Test/Tester@doSomething

# other forms: Class->method (new instance), Class::method (static), plain function
php cli.php duckcover --call MyApp/Business/DemoBusiness->handle
php cli.php duckcover --call MyApp/Helper::format
php cli.php duckcover --call some_function
```

With arguments (`?key=value`, matched by parameter name):

```bash
php cli.php duckcover --call MyApp/Test/Tester@runX?parameter=d
```

Results and dumps land in the current group's `test_coveragedumps/`; then run `--report` as usual.

---

## 6. Test-list directive cheat sheet

| Directive | Example | Meaning |
|---|---|---|
| `#WEB` | `#WEB /admin/index` | replay a web request; 2nd segment is POST params (`a=1&b=2`), 3rd can be `AJAX` or `OPTIONS` |
| `#CALL` | `#CALL Foo/Bar@run?x=1` | call a local class/function directly |
| `#SETWEB` | `#SETWEB _ _ _ _` | set hooks for following `#WEB` lines: `pre_curl pre_webcall post_webcall post_curl`; `_` clears |
| `#PHASE` | `#PHASE api` | switch the DuckPHP phase |
| `#URL_PREFIX` | `#URL_PREFIX /v1` | prefix later `#WEB` URIs |
| `##` | `## comment` | comment line, ignored |

---

## 7. Using an external server (nginx etc.)

To bypass the built-in test server, configure the external base URL:

```php
'duckcoverage_web_base_url' => 'http://admin.duckphp-local.com/',
```

- `#WEB /admin/index` will then hit `http://admin.duckphp-local.com/admin/index`.
- The external server must receive and forward the `X-MyCoverage-Name` header (nginx forwards custom headers by default).
- You still need to `--watch` the same group first so that `isInHttpTest()` matches.

---

## 8. FAQ

**Q: My application code is missing from the report?**
`duckcoverage_path_src` is unset or wrong. It defaults to duckcoverage's own `src/`; configure it explicitly to your source directory (absolute path recommended).

**Q: "Need CodeCoverage" error, or CodeCoverage cannot be created?**
`phpunit/php-code-coverage` is missing, or the xdebug/pcov driver is not loaded. See sections 1 and 2.

**Q: `--replay` does nothing?**
Replay depends on `GetTestList()` from `duckcoverage_callback_class`. Configure the callback class first (see 4.3), or run a round of traced requests to produce a `.list` as reference.

**Q: Port 8080 is already in use?**
Change `duckcoverage_server_port`.

**Q: The built-in server won't start?**
Check `duckcoverage_path_server` (defaults to the project root) and `duckcoverage_path_document` (defaults to `public`), and make sure `duckcoverage_homepage` matches your development entry.

**Q: How do I merge several test rounds?**
Use the same group name for `--watch`: all rounds accumulate into that group's dumps, and `--report mygroup` renders one report. You can also pass multiple groups: `--report a b c`.

**Q: The report directory is messy?**
Set `duckcoverage_report_direct => false`; reports are then stored per group (single group) or per date (multiple groups).

---

## 9. Links

- Project: <https://github.com/dvaknheo/duckcoverage>
- DuckPHP: <https://github.com/dvaknheo/duckphp>
- LibCoverage (coverage for standalone libraries): <https://github.com/dvaknheo/libcoverage>
- License: [MIT](LICENSE)
