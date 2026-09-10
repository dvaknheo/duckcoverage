# DuckCoverage

[English](README.md) | [中文](README.zh_CN.md)

**DuckCoverage** 是用于 [DuckPHP](https://github.com/dvaknheo/duckphp) 应用的测试覆盖率扩展。
它从真实的 HTTP 请求和 CLI 调用中采集行覆盖率，回放已记录的请求，并生成 HTML 覆盖率报告——无需编写任何单元测试。

它配合 [LibCoverage](https://github.com/dvaknheo/libcoverage)（面向独立 PHP 库的覆盖率工具）使用，两者共用 `test_coveragedumps` / `test_reports` 目录约定。

## 特性

- **对真实流量采集覆盖率** —— 在监听某个组期间，进入应用的每个请求都会被采集。请求可带 `X-DuckCoverage-Name` 头给这次请求取一个可读的名字。
- **请求日志** —— 每个被采集的请求都会追加到 `<组名>.list.log`。
- **回放（`--play`）** —— 测试指令在内置 PHP 测试服务器（或外部服务器，如 nginx）上执行，每个被回放的请求都会再次贡献覆盖率。
- **直接 CLI 调用** —— 用 `RUN`/`CALL` 调用本地命令、类方法或函数并采集覆盖率，无需经过 HTTP。
- **分组工作流** —— `--watch <组名>` → 回放 → `--report` 按组渲染 HTML 报告。
- **两种采集范围** —— 默认采集整个请求；用 `InitedThenGoRouteHookMode()` 可只采集路由阶段。
- **HTML 报告** —— 由 `phpunit/php-code-coverage` 提供渲染。

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | >= 7.4 |
| 覆盖率驱动 | 已加载 **Xdebug** 或 **PCOV**（`php -m` 里能看到其一） |
| 框架 | DuckPHP >= 1.4.1（含 `DuckPhp\HttpServer\HttpServer` 组件） |
| 依赖 | `phpunit/php-code-coverage` ^9.2.32（普通运行时依赖，Composer 自动安装） |

检查驱动是否加载：

```bash
php -m | findstr /i "xdebug pcov"        # Windows
php -m | grep -i -E "xdebug|pcov"        # Linux / macOS
```

没有驱动时，DuckCoverage 在创建 `CodeCoverage` 阶段会失败（`doBegin`）。请先安装驱动。

## 安装

在你的 DuckPHP 工程里：

```bash
composer require dvaknheo/duckcoverage
```

`phpunit/php-code-coverage` 会作为本包的依赖一并装上，无需自己再 require 一次。

## 快速开始（约 10 分钟上手）

在 DuckPHP 应用中启用的一般用法，以 `dvaknheo/duckadmin` 包为例。

### 1. 在 DuckPHP 应用中启用

编辑应用入口类 `DuckAdminDemo\System\DemoApp`：

```php
<?php
namespace DuckAdminDemo\System;

use DuckCoverage\DuckCoverage;

class DemoApp extends DuckPhp
{
    public $options = [
        // ... 你原有的选项 ...
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

并把这个设置项加进应用设置文件：

```php
<?php
// config/DuckPhpSettings.config.php
return [
    'duckcoverage_enable' => true,
];
```

> 和其他常见 DuckPHP 插件不同，DuckCoverage 必须按示例在**根应用**的 `onPrepare` 里做初始化。DuckCoverage 选项也需要放进根应用的应用选项里。

测试列表回调类 `DuckAdminDemo\System\TestLister`：

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

该回调通过 `duckcoverage_test_lister` 选项配置，返回一系列测试指令列表，每一行对应一个测试指令，详见后文「测试指令参考」。
这个回调无需引入 DuckCoverage，也不局限于根应用，子应用同样可以提供。

### 2. 执行测试

```bash
php cli.php cover --go group1
```

执行后，DuckCoverage 会根据回调返回的清单执行一系列请求/调用，然后生成测试覆盖报告。

CLI 入口文件（`cli.php`）遵循你的 DuckPHP 工程模板；如果工程用了别的入口请相应替换。

命令名是 `cover`（来自 `command_cover()`）。如果你的应用运行在非根 phase 下，DuckPHP 会给命令加上应用命令前缀，此时命令变成 `<前缀>:cover`。

## 工作流

DuckCoverage 的采集、回放与出报告**全部通过命令行**的 `php cli.php cover` 完成，没有额外的交互式"浏览采集"流程。

### 一步到位：`--go`

最常用的是用 `--go <组名>` 一条命令完成整套流程：

```bash
php cli.php cover --go group1
```

它按以下顺序执行：

```text
watch → play → report → stop
```

- 效果：以 `group1` 为组名执行回放并生成报告；报告生成在 `<runtime>/DuckCoverage/group1.report/`。
- CI 场景用这一条命令最合适。

`--go` 是下面四个步骤的**真正组合**：它不改动任何选项，因此生成的报告目录与手工依次执行 `--watch`、`--play`、`--report`、`--stop` 完全一致。只有在你设置了 `duckcoverage_report_direct => true`（或用 `--report` 一次报告多个组）时，报告才会改写到 `<runtime>/DuckCoverage/<duckcoverage_report_default_dir>/`——见「输出路径」。

### 分步执行

需要观察中间状态或手动扩展时，可拆成几步：

```bash
php cli.php cover --watch group1    # ① 开始监听组 group1
php cli.php cover --play            # ② 回放回调列表（GetTestList()）中的请求/调用
php cli.php cover --report group1   # ③ 为该组渲染 HTML 报告
php cli.php cover --stop            # ④ 停止监听
```

- `--watch <组名>` 写入监听标记；从这一刻起，进入应用的每个请求都会被采集到该组。
- `--play` 会启动内置测试服务器（或指向设置了 `duckcoverage_web_base_url` 的外部服务器），逐行执行回调类 `GetTestList()` 返回的测试指令；每个被回放的请求都会再次采集覆盖率，结束后停止服务器。
- `--report` 合并该组（或多个组：`--report a b c`）的所有 dump，渲染 HTML 报告。

### 如何判断已进入监视状态

有组正在被监视时，整个应用就处在「监视状态」，下面任一迹象都能看出来：

- `<runtime>/DuckCoverage/DuckCoverage.watching.txt` 存在，内容就是组名。这个文件**本身**就是监视状态：执行 `--stop`（或删掉它）采集即停止。
- **Web 方式**：每个响应都会带上 header `x-duckcoverage-group: <组名>`，被采集的请求因此能看出进了哪个组。
- **命令行方式**：会打印红色条幅 `DuckCoverage GROUP <组名>`。（红色条幅 `DuckCoverage running: JSON_FILE: …` 是扩展启用就会打印的，与是否在监视无关。）

监视状态下还有两件事会变：

- 应用的数据配置文件按组隔离：DuckPHP 的 `data_file_json_file` 被改写到 `<runtime>/DuckCoverage/<组名>.DuckPhpData.config.json`，每个组各留一份自己的选项环境。
- 每个被采集的操作都会得到一个记录名——web 请求是 `MAN-WEB` 加请求 URI（含 POST），命令行是 `MAN-RUN` 加命令行——它会被追加到 `<组名>.list.log`，并用作 dump 文件名。

### 直接调用（不经 HTTP）

不经过 HTTP 的类/函数调用，通过在回放列表里写 `CALL`（或 CLI 命令用 `RUN`）指令实现，随 `--go` / `--play` 一起执行并采集覆盖率：

```text
CALL MyApp\Test\Tester@doSomething         # @ 表示取单例（调 _() 方法）
CALL MyApp\Business\DemoBusiness->handle   # -> 表示 new 实例
CALL MyApp\Helper::format                  # :: 表示静态调用
CALL some_function                         # 纯函数
```

参数用 `name=value`，由 `http_build_query()` 编码、`parse_str()` 解码，按参数名匹配：

```text
CALL MyApp\Test\Tester@runX parameter=d
```

dump 会落在当前组的 dump 目录，`--report` 照常出报告。详见「测试指令参考」中的 `CALL`。

### 使用外部服务器（nginx 等）

不想用内置测试服务器时，配置外部服务器地址：

```php
'duckcoverage_web_base_url' => 'http://www.example.com/',
```

- 之后 `WEB /admin/index` 的回放会请求 `http://www.example.com/admin/index`。
- 外部服务器必须运行同一个应用，并且能看到同一个 `runtime/` 目录：请求属于哪个组是在**服务端**从监听标记文件里读出来的，不来自请求头。监听期间到达的每个请求都会被采集。
- `X-DuckCoverage-Name` 头是可选的，它只覆盖这次请求被记录下来的名字。
- 仍需先用 `--watch` 监听同一组，采集才会发生。

## 命令行参考

全部指令：

```bash
php cli.php cover
  --watch {group}
  --play
  --stop
  --report a
  --report a b c
  --go {group}
```

| 参数 | 说明 |
|---|---|
| （无参数）/ `--help` | 打印用法帮助 |
| `--watch <组名>` | 开始监听测试组（不写组名 → 生成带时间戳的 `default_<日期>`）。写入 `<runtime>/DuckCoverage/DuckCoverage.watching.txt` |
| `--stop` | 停止监听，移除监听标记与锁文件 |
| `--play` | 回放回调类 `GetTestList()` 返回的测试列表 |
| `--report [a b c]` | 为给定组渲染 HTML 报告（默认当前监听组），打印输出路径与耗时 |
| `--go <组名>` | 组合命令：`watch + play + report + stop` 一步到位（CI 友好）。不写组名时，与 `--watch` 一样生成带时间戳的组名 |

如果 `duckcoverage_enable` 是关的，`cover` 不会执行，而是打印提示（`turn on setting to work: 'duckcoverage_enable'`）。

## 输出路径

下表中的路径都相对于 `<runtime>`（DuckPHP 的 `path_runtime`，默认 `runtime/`）以及 `duckcoverage_path`（默认 `<runtime>/DuckCoverage/`）。

| 路径 | 由谁写入 |
|---|---|
| `<runtime>/DuckCoverage/DuckCoverage.watching.txt` | `--watch` —— 当前组名 |
| `<runtime>/DuckCoverage/<组名>.watch.lock` | `--watch` —— 监听开始时间 |
| `<runtime>/DuckCoverage/<组名>.list.log` | 每个被采集的请求 —— 每行一个请求名 |
| `<runtime>/DuckCoverage/<组名>/<日期>-<sha1(名字)>.php` | 每个被采集的请求 —— 覆盖率 dump（由 `phpunit/php-code-coverage` 序列化） |
| `<runtime>/DuckCoverage/<组名>.report/` | 单组报告：`--report <组名>` 与 `--go <组名>`，且 `duckcoverage_report_direct => false` |
| `<runtime>/DuckCoverage/<duckcoverage_report_default_dir>/` | `--report a b c`（多组），或任何 `duckcoverage_report_direct => true` 的报告 |
| `<runtime>/DuckCoverage/<组名>.DuckPhpData.config.json` | 监听某个组期间使用的按组 DuckPHP 数据文件 |

服务端还会在响应里带上两个诊断头：`x-duckcoverage-group`（这次请求被采集到的组）与 `x-duckcoverage-datafile`（正在使用的数据文件）。

## 测试指令参考

### 基础指令

| 指令 | 说明 |
|---|---|
| `WEB <uri> [post] [AJAX\|OPTIONS]` | 回放一个 HTTP 请求；第二段是 POST 参数（`a=1&b=2`），第三段可写 `AJAX` 或 `OPTIONS` |
| `RUN <命令>` | **在当前进程内**重新派发本应用的 CLI 命令（不单开进程），这也是它能被采集到覆盖率的原因 |
| `CALL <class/@method [name=value]>` | 调用本地可调用对象（类/函数） |
| `SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | 为后续 `WEB` 行设置 curl / web 钩子（`_` 表示清除） |
| `PHASE <phase>` | 切换 DuckPHP phase |
| `COMMENT 注释` | 忽略 |

### 宏指令

宏指令在回调返回的文本中由 `TestListerHelper::explainMarco()` 展开：

| 指令 | 说明 |
|---|---|
| `#PHASE_BEGIN` | 无参数，进入当前 Phase |
| `#PHASE_END` | 无参数，退出当前 Phase |
| `#INCLUDE_CALL <handler>` | 调用并把结果嵌入当前测试列表 |
| `#INCLUDE_CHILD <child>` | 把子应用的回调列表加入当前测试列表，并在前面插入一行 `COMMENT APP <child>` |
| `#BUSINESS <Class@method> [args]` | 把该行改写为对 `<namespace>\Business\<Class>@<method>` 的 `CALL` |
| `#MODEL <Class@method> [args]` | 把该行改写为对 `<namespace>\Model\<Class>@<method>` 的 `CALL` |
| `#ACTION <Class@method> [args]` | 把该行改写为对 `<namespace>\Controller\<Class>@<method>` 的 `CALL` |

`#BUSINESS`、`#MODEL`、`#ACTION` 由 `genTestListOfAll()` 生成，是一种让清单更易读的简写；改写出的 `CALL` 会带上当前 phase 前缀。

### 补充说明

- `#PHASE_BEGIN` / `#PHASE_END` 无参数，在进入/退出阶段时使用。
- `CALL` 与 `#INCLUDE_CALL` 以 `{phase}!class[->|::@]method 参数=值` 形式调用；若没有 `!` 则不带 phase。
- `#INCLUDE_CALL` 是调用的时候把结果嵌入当前测试列表。
- `RUN`：如果子命令以 `:` 开始，会去掉这个前导冒号，并把剩下的部分当作绝对命令名，而不是拼上应用的命令前缀。
- `WEB` 的 uri 会被 `__url()` 函数封装，并在发送前去掉当前 URL 基址。
- `SETWEB` 设置的钩子在下一个 web 调用时被消费，而它们**在哪执行决定了默认 phase**：
  - `pre_curl` / `post_curl` 在**本地**当前进程执行（回调参数是 `$ch, $name`），因此沿用播放列表的**当前 phase**。
  - `pre_webcall` / `post_webcall` 在**远程**（服务端）执行，由 `X-DuckCoverage-BeforeRun` / `X-DuckCoverage-AfterRun` 请求头触发，因此运行在 **root phase**——不是播放列表当前所在的 phase。
  - 需要把钩子放到别的 phase，写成 `<phase>!<handler>`，与 `CALL` 同一形式。
- 空行被忽略。

## 选项参考

所有配置都通过 DuckPHP 应用选项传入（key 以 `duckcoverage_` 开头）。

```php
public $options = [
    'duckcoverage_test_lister' => null,
    // 预留：置 true 时 init() 立即返回、跳过全部配置。
    'duckcoverage_stop_init' => false,

    'duckcoverage_data_file_json_file' => 'DuckPhpData-duckcoverage.config.json',
    'duckcoverage_reg_console_command' => true,

    'duckcoverage_path' => '',
    'duckcoverage_path_src' => 'src/',
    'duckcoverage_report_direct' => false,
    'duckcoverage_report_default_dir' => 'AAAAA.report',

    'duckcoverage_web_base_url' => '',
    // 外部服务器(如 nginx)基础 URL,如 http://www.example.com/ ;空则退回内部测试服务器
    'duckcoverage_server_port' => 8017,
    'duckcoverage_server_host' => '',
    'duckcoverage_path_server' => '',
    'duckcoverage_path_document' => 'public',
    'duckcoverage_homepage' => '/',
    'duckcoverage_new_server' => true,

    'duckcoverage_debug_curl_echo_back' => false,
];
```

| 选项 | 默认 | 说明 |
|---|---|---|
| `duckcoverage_enable` | — | 主开关。它由 `App::Setting()` 读取，所以配置在应用设置文件（`config/DuckPhpSettings.config.php`）或 `.env` 里；它不是本包的应用选项 |
| `duckcoverage_stop_init` | `false` | 预留。置 `true` 时 `init()` 立即返回、跳过全部配置——扩展完全不初始化。与上面的开关无关 |
| `duckcoverage_test_lister` | `null` | 返回回放清单的可调用对象；其 `GetTestList()` 文本会被 `explainMarco()` 展开 |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | 把额外选项文件移到新位置，隔离配置环境。监听某个组期间会变成 `DuckCoverage/<组名>.DuckPhpData.config.json` |
| `duckcoverage_reg_console_command` | `true` | 注册命令行，使 `cover` 指令生效。注册发生在开关判断之前，所以关掉开关时 `cover` 仍能提示功能未开启 |
| `duckcoverage_path` | `<runtime>/DuckCoverage/` | 基础路径（监听标记 / dump / 报告）。init 时始终由运行时路径推导 |
| `duckcoverage_path_src` | `'src/'` | 覆盖率对应的源码目录。相对值按**工程路径**解析，因此默认是 `<工程根>/src/`；建议显式传绝对路径。**请配置为你自己的源码目录** |
| `duckcoverage_report_direct` | `false` | 为 false 且只报告一个组时写入 `<组名>.report/`；为 true（或报告多个组）时改写入 `duckcoverage_report_default_dir` |
| `duckcoverage_report_default_dir` | `'AAAAA.report'` | 多组报告与 `duckcoverage_report_direct => true` 使用的报告目录 |
| `duckcoverage_web_base_url` | `''` | 外部服务器(如 nginx)基础 URL；空则退回内置测试服务器 |
| `duckcoverage_server_port` | `8017` | 内置测试服务器端口 |
| `duckcoverage_server_host` | `''` | 内置测试服务器绑定的地址；`''` 即 `127.0.0.1`。回放的请求也发往该地址，只有通配绑定地址（`0.0.0.0`、`::`）会回落到 `127.0.0.1` |
| `duckcoverage_path_server` | 工程根目录 | 内置服务器服务的项目路径 |
| `duckcoverage_path_document` | `public` | 内置服务器的文档根目录 |
| `duckcoverage_homepage` | `/` | 追加在内置服务器 URL 之后的基础 URI |
| `duckcoverage_new_server` | `true` | 回放时新建 `HttpServer` 实例，清理前面可能被覆盖的服务器配置 |
| `duckcoverage_debug_curl_echo_back` | `false` | 显示 curl 每个响应前 200 字符，用于调试 |

## 工作原理

1. `--watch <组名>` 写入监听标记（`<runtime>/DuckCoverage/DuckCoverage.watching.txt`）与锁文件。
2. 每个请求进来时，`init()` 从该标记文件读出当前组。若有组正在被监听，就开始采集（`doBegin`）并给请求命名——CLI 取 `argv`（`MAN-RUN ...`），HTTP 取 `REQUEST_URI` 加 POST 数据（`MAN-WEB ...`）；若带了 `X-DuckCoverage-Name` 头则用该名字。
3. 采集何时结束取决于模式：
   - **默认（整个请求）**：由 `register_shutdown_function` 注册的回调执行 `AfterRun` 处理并调用 `doEnd()`，因此连框架引导代码一起被计入。
   - **route hook 模式**：应用初始化完成后调用 `DuckCoverage::InitedThenGoRouteHookMode()`，先结束已开始的采集，再把 `BeforeRun` / `AfterRun` 注册为路由钩子（`prepend-outter` / `finally-outter`），只计量路由阶段。
4. `doEnd()` 把 dump 写到 `<runtime>/DuckCoverage/<组名>/<日期>-<sha1(名字)>.php`，并把请求名追加到 `<组名>.list.log`。
5. `--play` 从回调读取测试清单并逐行执行：
   - `WEB` 请求通过 curl 对内置测试服务器（或设置了 `duckcoverage_web_base_url` 时的外部服务器）发起，因为组仍在监听中，所以会被再次采集。
   - `CALL` 指令通过反射直接调用本地类/函数。
   - `RUN` 指令在同一进程内重新进入应用自己的 CLI 派发流程。
6. `--report` 新建一个 `CodeCoverage`，收录 `duckcoverage_path_src`，合并所请求各组的全部 dump，补齐部分覆盖文件中可执行行（避免部分覆盖的文件被报成 100%），最后渲染 HTML 报告。

## 内部结构

本包由三个类构成：

| 类 | 职责 |
|---|---|
| `DuckCoverage\DuckCoverage` | DuckPHP 扩展本体：生命周期钩子、CLI 命令、指令解释器、内置 HTTP 服务器与 curl 客户端（后两者是 trait） |
| `DuckCoverage\GroupCoverage` | 唯一直接接触 `phpunit/php-code-coverage` 的地方：`doBegin()` / `doEnd()` / `createReport()`、dump 命名、合并、部分覆盖补全 |
| `DuckCoverage\TestListerHelper` | 生成与展开测试清单：宏指令，以及路由 / 命令 / 组件清单生成 |

`GroupCoverage` 在采集期间会 `doPause()` 掉 `LibCoverage`，结束后 `doResume()`，因此两个工具可以在同一进程内共存。

## 相关方法

公开方法：

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

- `BeforeRun` / `AfterRun`（即 `_OnBeforeRun` / `_OnAfterRun`）只是钩子回调，仅在 route hook 模式下有行为。
- 你关心的应该是 `Prepare()`：在根应用 `onPrepare` 里调用。
- `init()` 会插入一个 ext 扩展运行。
- `InitedThenGoRouteHookMode()` 切到 route hook 模式，必须在应用初始化完成后调用。
- `genTestListOfAll()` 从路由、控制台命令以及 `Business` / `Model` 组件生成测试清单，输出可以直接粘进你的 `GetTestList()` 作为起点。
- `command_cover` 注册命令行。

`DuckCoverage\GroupCoverage` 对外提供 `_()`、`init()`、`getCoverage()`、`doBegin()`、`doEnd()`、`createReport()`；需要在回调里程序化生成清单时，可以用 `DuckCoverage\TestListerHelper::_()`。

## 开发

本包自身的测试由 LibCoverage 引导：

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix
```

`tests/bootstrap.php` 把 LibCoverage 指向 `test_coveragedumps/` 与 `test_reports/`，这也是这两个目录名在项目里反复出现的原因。跨 PHP 版本的兼容性测试由 LibCoverage 的 CI 矩阵保证。

## 常见问题

**Q：我配了 `duckcoverage_enable`，但什么都没采集到？**
检查 DuckPhp 应用的设置项 `duckcoverage_enable`——`App::Setting('duckcoverage_enable')` 读的就是它。没开启时 `cover` 只会打印 `turn on setting to work: 'duckcoverage_enable'`，也不会产生任何 dump。

**Q：报告里没有我的应用代码？**
`duckcoverage_path_src` 没配或配错。它默认是相对工程路径的 `src/`，只有你的源码确实在 `<工程根>/src/` 时才正确。请显式配置（推荐绝对路径）。

**Q：报错 "Need CodeCoverage" 或无法创建 CodeCoverage？**
没有加载 xdebug/pcov 驱动。见「环境要求」。

**Q：`--play` 什么都没做？**
回放依赖 `duckcoverage_test_lister` 回调。先配置回调类，或跑一轮被监听的请求产生 `<组名>.list.log` 作为参考。

**Q：端口被占用？**
改 `duckcoverage_server_port`（默认 `8017`）。

**Q：内置服务器起不来？**
确认 `duckcoverage_path_server`（默认工程根目录）与 `duckcoverage_path_document`（默认 `public`）指向正确，且 `duckcoverage_homepage` 与你的开发入口一致。

**Q：`--go` 生成的报告在 `AAAAA.report` 而不是 `<组名>.report`？**
这说明 `duckcoverage_report_direct` 是 `true`：该选项对 `--go` 的作用与对 `--report <组名>` 完全一样。把它设为 `false`（默认值）即可得到 `<组名>.report/` 结构；也可以用 `duckcoverage_report_default_dir` 给那个扁平目录改名。

**Q：怎么把多轮测试合并？**
用同一组名 watch，多轮请求都累积到该组的 dump；`--report mygroup` 一次出报告。也可以 `--report a b c` 一次合并多个组。

**Q：什么时候会采集一个请求？**
只要有组正在被监听就会采集：组名是服务端从 `DuckCoverage.watching.txt` 读出来的。用 `--stop` 停止监听即停止采集；想排除框架引导代码就改用 route hook 模式。

## 相关项目

- [DuckPHP](https://github.com/dvaknheo/duckphp) —— 本扩展所服务的框架（开发版本与本项目同步维护，DuckCoverage 版本跟随 DuckPHP 版本演进）。
- [LibCoverage](https://github.com/dvaknheo/libcoverage) —— 面向独立 PHP 库的覆盖率工具；本包的测试引导与跨版本兼容性测试都由它提供。

## 许可证

[MIT](LICENSE)
