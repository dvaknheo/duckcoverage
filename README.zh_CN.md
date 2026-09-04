# DuckCoverage

[English](README.md) | [中文](README.zh_CN.md)

**DuckCoverage** 是用于 [DuckPHP](https://github.com/dvaknheo/duckphp) 应用的测试覆盖率扩展。
它从真实的 HTTP 请求和 CLI 调用中采集行覆盖率，回放已记录的请求，并生成 HTML 覆盖率报告——无需编写任何单元测试。

它配合 [LibCoverage](https://github.com/dvaknheo/libcoverage)（面向独立 PHP 库的覆盖率工具）使用，两者共用 `test_coveragedumps` / `test_reports` 目录约定。

## 特性

- **对真实流量采集覆盖率** —— 每个 Web 请求（浏览器、curl 或自动化工具）通过发送 `X-MyCoverage-Name` 头即可被追踪。
- **请求记录** —— 被 watch 的请求会追加到一个可回放的列表文件（`test_coveragedumps/<组名>.list`）。
- **回放** —— 已记录的请求在内置 PHP 测试服务器（或外部服务器，如 nginx）上重放，每个重放请求都会贡献覆盖率。
- **直接 CLI 调用** —— 用 `RUN`/`CALL` 在本地调用任意类方法或函数并采集覆盖率。
- **分组工作流** —— `--watch <组名>` → 浏览 / 回放 → `--report` 按组渲染 HTML 报告。
- **HTML 报告** —— 由 `phpunit/php-code-coverage` 提供渲染。

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | >= 7.4 |
| 覆盖率驱动 | 已加载 **Xdebug** 或 **PCOV**（`php -m` 里能看到其一） |
| 框架 | DuckPHP >= 1.4.1（含 `DuckPhp\HttpServer\HttpServer` 组件） |
| 依赖 | `phpunit/php-code-coverage`（9.x） |

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
composer require --dev phpunit/php-code-coverage ^9.0   # 按你的 PHP 版本选择
```

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

> 和其他常见 DuckPhp 插件不同，DuckCoverage 必须按示例在**根应用**的 `onPrepare` 里做初始化。DuckCoverage 选项也需要放进根应用的应用选项里。

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

它对应 `duckcoverage_callback` 的回调，返回一系列测试指令列表，每一行对应一个测试指令，详见后文「测试指令参考」。
`duckcoverage_callback` 无需引入 DuckCoverage，也不局限于根应用。

### 2. 执行测试

```bash
php cli.php duckcover --go group1
```

执行后，根据 `duckcoverage_callback` 返回的命令清单执行一系列请求/调用，
然后在 `runtime/DuckCoverage/group1.report/` 的位置生成测试覆盖报告。

CLI 入口文件（`cli.php`）遵循你的 DuckPHP 工程模板；如果工程用了别的入口请相应替换。

## 工作流

DuckCoverage 的采集、回放与出报告**全部通过命令行**的 `php cli.php duckcover` 完成，没有额外的交互式"浏览采集"流程。

### 一步到位：`--go`

最常用的是用 `--go <组名>` 一条命令完成整套流程（等价于 `watch → play → stop → report`）：

```bash
php cli.php duckcover --go group1
```

- 效果：以 `group1` 为组名一键执行回放并生成报告；报告生成在 `runtime/DuckCoverage/group1.report/`。
- CI 场景用这一条命令最合适。

### 分步执行

需要观察中间状态或手动扩展时，可拆成几步：

```bash
php cli.php duckcover --watch group1    # ① 开始监听组 group1
php cli.php duckcover --play          # ② 回放回调列表（GetTestList()）中的请求/调用
php cli.php duckcover --report group1   # ③ 为该组渲染 HTML 报告
php cli.php duckcover --stop            # ④ 停止监听
```

- `--play` 会启动内置测试服务器（或指向设置了 `duckcoverage_web_base_url` 的外部服务器），逐行执行回调类 `GetTestList()` 返回的测试指令；每个重放请求都会再次采集覆盖率，结束后停止服务器。
- `--report` 合并该组（或多个组：`--report a b c`）的所有 dump，在 `runtime/test_reports/` 下渲染 HTML 报告。

### 直接调用（不经 HTTP）

不经过 HTTP 的类/函数调用，通过在回放回调 `GetTestList()` 里写 `CALL`（或 shell 命令用 `RUN`）指令实现，随 `--go` / `--replay` 一起执行并采集覆盖率：

```text
CALL MyApp\Test\Tester@doSomething         # @ 表示取单例（调 _() 方法）
CALL MyApp\Business\DemoBusiness->handle   # -> 表示 new 实例
CALL MyApp\Helper::format                  # :: 表示静态调用
CALL some_function                         # 纯函数
```

参数用 url_endode 编码 带参数（`name=value`，按参数名匹配, 将被 parse_str 解码）：

```text
CALL MyApp\Test\Tester@runX parameter=d
```

生成的结果与 dump 会落在当前组的 `test_coveragedumps/`，`--report` 照常出报告。详见「测试指令参考」中的 `CALL`。

### 使用外部服务器（nginx 等）

不想用内置测试服务器时，配置外部服务器地址：

```php
'duckcoverage_web_base_url' => 'http://www.example.com/',
```

- 之后 `WEB /admin/index` 的重放会请求 `http://www.example.com/admin/index`。
- 外部服务器必须接收并转发 `X-MyCoverage-Name` 头（nginx 默认会转发自定义头）。
- 仍需先用 `--watch` 监听同一组，使匹配逻辑生效。


## 命令行参考

全部指令：

```bash
php cli.php duckcover
  --watch {group}
  --replay
  --stop
  --report group1
  --report group1 group2 group3
  --go {group}
```

| 参数 | 说明 |
|---|---|
| （无参数）/ `--help` | 打印用法帮助 |
| `--watch <组名>` | 开始监听测试组（不写组名 → 自动加时间戳）。写入 `runtime/DuckCoverage.watching.txt` |
| `--stop` | 停止监听（移除监听标记） |
| `--play` | 回放回调类 `GetTestList()` 返回的测试列表 |
| `--report [a b c]` | 为给定组渲染 HTML 报告（默认当前监听组），打印输出路径与耗时 |
| `--go <组名>` | 组合命令：`watch + play + stop + report` 一步到位（CI 友好） |

`--go group1` 相当于连续执行：

```bash
php cli.php duckcover --watch group1
php cli.php duckcover --play
php cli.php duckcover --stop
php cli.php duckcover --report group1
```

## 测试指令参考

### 基础指令

| 指令 | 说明 |
|---|---|
| `WEB <uri> [post] [AJAX\|OPTIONS]` | 回放一个 HTTP 请求；第二段是 POST 参数（`a=1&b=2`），第三段可写 `AJAX` 或 `OPTIONS` |
| `RUN <命令>` | 原样执行 shell 命令（不做转义；非 0 退出码只打印警告、不中断回放），不单开进程而是在当前进程模拟 |
| `CALL <class/@method [name=value]>` | 调用本地可调用对象（类/函数） |
| `SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | 为后续 `WEB` 行设置 curl / web 钩子（`_` 表示清除） |
| `PHASE <phase>` | 切换 DuckPHP phase（空值忽略） |
| `COMMENT 注释` | 忽略 |

### 宏指令

宏指令在回调返回的文本中由 `explainMarco` 展开：

| 指令 | 说明 |
|---|---|
| `#PHASE_BEGIN` | 无参数，进入当前 Phase |
| `#PHASE_END` | 无参数，退出当前 Phase |
| `#INCLUDE_CALL <handler>` | 调用并把结果嵌入当前测试列表 |
| `#INCLUDE_CHILD <child>` | 把子应用的回调列表加入当前测试列表 |

### 补充说明

- `#PHASE_BEGIN` / `#PHASE_END` 无参数，在进入/退出阶段时使用。
- `CALL` 与 `#INCLUDE_CALL` 以 `{phase}!class[->|::@]method uri_encode` 形式调用；若没有 `!` 则不带 phase。
- `#INCLUDE_CALL` 是调用的时候把结果嵌入当前测试列表。
- `RUN`：如果子命令以 `:` 开始，则不切成子命令。`run` 不会单开进程，而是在当前进程模拟。
- `WEB` 的 uri 会被 `__url()` 函数封装。
- `SETWEB` 设置的钩子在下一个 web 调用后还原。`pre_curl`、`post_curl` 在本地进程执行（回调参数是 `$ch, $name`）；`pre_webcall`、`post_webcall` 在服务端进行。
- 空行被忽略。

## 选项参考

所有配置都通过 DuckPHP 应用选项传入（key 以 `duckcoverage_` 开头）。

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
| `duckcoverage_enable` | `true` | 主开关，启用 DuckCoverage |
| `duckcoverage_callback` | `null` | 回调，`GetTestList()` 提供回放列表 |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | 把额外选项文件移到新位置，隔离配置环境 |
| `duckcoverage_reg_console_command` | `true` | 注册命令行，使 `duckcover` 指令生效 |
| `duckcoverage_path` | 运行时 `DuckCoverage/` | 基础路径（dump / 报告目录） |
| `duckcoverage_path_src` | 包自身 `src/` | 对应的源代码类目录。**必须显式配置**为你的源码目录（推荐绝对路径） |
| `duckcoverage_report_direct` | `false` | 直接写报告目录，而非按组/日期分目录 |
| `duckcoverage_web_base_url` | `''` | 外部服务器(如 nginx)基础 URL；空则退回内置测试服务器 |
| `duckcoverage_server_port` | `8017` | 内置测试服务器端口 |
| `duckcoverage_server_host` | `''` | 内置测试服务器主机 |
| `duckcoverage_path_server` | 工程根目录 | 内置服务器服务的项目路径 |
| `duckcoverage_path_document` | `public` | 内置服务器的文档根目录 |
| `duckcoverage_homepage` | `/` | 追加在内置服务器 URL 之后的基础 URI |
| `duckcoverage_new_server` | `true` | 回放时新建 `HttpServer` 实例，清理前面可能被覆盖的服务器配置 |
| `duckcoverage_debug_curl_echo_back` | `false` | 显示 curl 每个响应前 200 字符，用于调试 |

## 工作原理

1. `--watch <组名>` 写入监听标记（`runtime/DuckCoverage.watching.txt`）。
2. 当请求携带与监听组一致的 `X-MyCoverage-Name: <组名>` 头时，`_OnBeforeRun` / `_OnAfterRun`（通过 `register_shutdown_function` 注册）启动/停止采集、把请求追加到 `<组名>.list`，并把覆盖率 dump 到 `test_coveragedumps/<组名>/`。
3. `--play` 从回调类的 `GetTestList()` 读取测试列表并逐行执行：
   - `WEB` 请求通过 curl 对内置测试服务器（或设置了 `duckcoverage_web_base_url` 时的外部服务器）重放，请求携带 `X-MyCoverage-Name` 头，使被测代码再次采集覆盖率。
   - `CALL` 命令通过反射直接调用本地类/函数。
4. `--report` 合并该组（或多个组）的所有 dump，在 `runtime/test_reports/` 下渲染 HTML 报告。

## 相关方法

公开方法：

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

- `BeforeRun` / `AfterRun`（即 `_OnBeforeRun` / `_OnAfterRun`）只是钩子回调。
- 你关心的应该是 `Prepare()`：在根应用 `onPrepare` 里调用。
- `init()` 会插入一个 ext 扩展运行。
- `genTestListOfAll()` 用于辅助生成测试命令列表。
- `command_duckcover` 注册命令行。

## Docker 环境（可选）

`docker/test-php84/` 提供了一个 PHP 8.4 + Xdebug 的 docker compose 环境（镜像定义与 duckphp 开发版一致，可复用 Docker 构建缓存）：

```bash
cd docker/test-php84
./start-docker.sh                                   # 构建并启动容器 duckcoverage-test84
./exec-docker.sh composer install --no-interaction --prefer-dist  # 首次安装依赖（使用 composer-test-php84.json）
./exec-docker.sh php -l src/DuckCoverage.php        # 语法检查
./exec-docker.sh php -r 'var_dump(PHP_VERSION);'    # 在容器里执行任意命令
./stop-docker.sh                                    # 停止容器（保留容器与卷）
./end-docker.sh                                     # 停止并删除容器
```

说明：

- 项目根目录挂载到 `/DATA`；`composer-test-php84.json` 覆盖容器内的 `composer.json`（额外 require `dvaknheo/duckphp ^1.4.1` 与 `phpunit/php-code-coverage ^11.0` 用于测试）。`vendor/` 与 composer 缓存使用命名卷，跨容器保留。
- 已设置 `XDEBUG_MODE=coverage`，容器内可直接采集覆盖率。
- `test_reports/` 与 `test_coveragedumps/` 挂载进 docker 目录，便于在宿主机查看。

## 常见问题

**Q：报告里没有我的应用代码？**
`duckcoverage_path_src` 没配或配错。它默认指向 duckcoverage 包自身的 `src/`，请显式配置为你的源码目录（推荐绝对路径）。

**Q：报错 "Need CodeCoverage" 或无法创建 CodeCoverage？**
缺少 `phpunit/php-code-coverage`，或没有加载 xdebug/pcov 驱动。见「环境要求」与「安装」。

**Q：`--play` 什么都没做？**
回放依赖 `duckcoverage_callback` 的 `GetTestList()`。先配置回调类，或跑一轮带追踪头的请求产生 `.list` 作为参考。

**Q：端口被占用？**
改 `duckcoverage_server_port`（默认 `8017`）。

**Q：内置服务器起不来？**
确认 `duckcoverage_path_server`（默认工程根目录）与 `duckcoverage_path_document`（默认 `public`）指向正确，且 `duckcoverage_homepage` 与你的开发入口一致。

**Q：怎么把多轮测试合并？**
用同一组名 watch，多轮请求都累积到该组的 dump；`--report mygroup` 一次出报告。也可以 `--report a b c` 一次合并多个组。

**Q：报告目录太乱？**
设置 `duckcoverage_report_direct => false`，报告会按组名（单组）或日期（多组）分目录存放。

## 相关项目

- [DuckPHP](https://github.com/dvaknheo/duckphp) —— 本扩展所服务的框架（开发版本与本项目同步维护，DuckCoverage 版本跟随 DuckPHP 版本演进）。
- [LibCoverage](https://github.com/dvaknheo/libcoverage) —— 面向独立 PHP 库的覆盖率工具。

## 许可证

[MIT](LICENSE)
