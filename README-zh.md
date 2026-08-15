# DuckCoverage

[English](README.md) | [English Quick Start](QUICKSTART.md) | [快速使用手册](QUICKSTART-zh.md)

**DuckCoverage** 是 [DuckPHP](https://github.com/dvaknheo/duckphp) 应用的测试覆盖率扩展。
它从真实的 HTTP 请求和 CLI 调用中采集行覆盖率，回放已记录的请求，并生成 HTML 覆盖率报告——无需编写任何单元测试。

它是 [LibCoverage](https://github.com/dvaknheo/libcoverage)（面向独立 PHP 库的覆盖率工具）的 DuckPHP 版本，两者共用 `test_coveragedumps` / `test_reports` 目录约定。

## 特性

- **真实流量采集**——浏览器、curl 或自动化工具发起的每个 Web 请求，只要带上 `X-MyCoverage-Name` 头即可被追踪。
- **请求记录**——被追踪的请求会追加到可回放的列表文件（`test_coveragedumps/<组名>.list`）。
- **回放**——记录下来的请求可对内置 PHP 测试服务器或外部服务器（nginx 等）重放，每次回放都会贡献覆盖率。
- **CLI 直接调用**——用 `--call` 直接调用任意类方法或函数并采集覆盖率。
- **分组工作流**——`--watch <组名>` → 浏览/回放 → `--report` 按组生成 HTML 报告。
- **HTML 报告**——基于 `phpunit/php-code-coverage` 生成。

## 环境要求

- PHP >= 7.4
- DuckPHP（>= 1.4.1，内置 `DuckPhp\HttpServer\HttpServer` 组件）
- `phpunit/php-code-coverage`（9.x）
- 覆盖率驱动：需加载 **Xdebug** 或 **PCOV** 之一

> **注意**：当前 `composer.json` 只在 dev 依赖里声明了 `dvaknheo/libcoverage`；DuckPHP、php-code-coverage 和覆盖率驱动需要在你自己的应用里显式安装（见 [快速使用手册](QUICKSTART-zh.md)）。

## 安装

```bash
composer require dvaknheo/duckcoverage
```

## 快速开始

```php
// src/System/App.php —— 在你的 DuckPHP 应用中注册扩展
class App extends \DuckPhp\DuckPhp
{
    public $options = [
        // ... 你的其他选项 ...
        'ext' => [
            \DuckCoverage\DuckCoverage::class => true,
        ],
        // 可选调优（括号内为默认值）
        // 'duckcoverage_enable' => true,
        // 'duckcoverage_path_src' => __DIR__ . '/../',   // 要统计的源码目录
        // 'duckcoverage_server_port' => 8080,
    ];
}
```

```bash
php cli.php duckcover --watch mygroup      # 开始监听测试组 mygroup
curl -H "X-MyCoverage-Name: mygroup" http://127.0.0.1:8080/index_dev.php/
                                           # 浏览你的应用，每个被追踪的请求都会被记录并采集覆盖率
php cli.php duckcover --replay             # 回放已记录请求（需要回调类，见下文）
php cli.php duckcover --report             # 为当前组生成 HTML 报告
# 打开 runtime/test_reports/index.html
php cli.php duckcover --stop               # 停止监听
```

CLI 入口文件（`cli.php`）遵循你的 DuckPHP 工程模板，如果工程用了别的入口请相应替换。

## CLI 命令参考

`php cli.php duckcover`（DuckPHP 的 CLI 入口）支持的参数如下：

| 参数 | 说明 |
|---|---|
| （无参数）/ `--help` | 打印用法帮助 |
| `--watch <名称>` | 开始记录测试组 `<名称>`（不写名称则自动用时间戳）。写入 `runtime/DuckCoverage.watching.txt` |
| `--stop` | 停止监听（删除监听标记文件） |
| `--replay` | 回放回调类 `GetTestList()` 返回的测试列表 |
| `--call <类>@<方法>` | 直接调用本地方法并采集覆盖率（斜杠自动转成反斜杠） |
| `--report [a b c]` | 为指定组生成 HTML 报告（缺省为当前监听组），并打印输出路径与耗时 |

## 配置项

所有配置都通过 DuckPHP 应用选项传入（key 以 `duckcoverage_` 开头）。

### DuckCoverage 选项

| 选项 | 默认值 | 说明 |
|---|---|---|
| `duckcoverage_enable` | `true` | 扩展总开关 |
| `duckcoverage_data_file_json_file` | `'DuckPhpData-duckcoverage.config.json'` | 启用时覆盖 App 的 `data_file_json_file` |
| `duckcoverage_save_web_request_list` | `true` | 把每个被追踪的 Web 请求追加到 `test_coveragedumps/<组名>.list` |
| `duckcoverage_save_local_call_list` | `false` | （预留）`#CALL` 命令是否记录到列表文件 |
| `duckcoverage_server_port` | `8080` | 内置测试服务器端口 |
| `duckcoverage_server_host` | `''` | 内置测试服务器主机 |
| `duckcoverage_path_server` | `''` | 内置服务器服务的工程路径（默认工程根目录） |
| `duckcoverage_path_document` | `'public'` | 内置服务器的文档根目录 |
| `duckcoverage_homepage` | `'/index_dev.php/'` | 拼在内置服务器 URL 后的基础 URI |
| `duckcoverage_new_server` | `true` | 回放时启动全新的 `HttpServer` 实例 |
| `duckcoverage_web_base_url` | `''` | 外部服务器基础 URL（如 `http://admin.duckphp-local.com/`）；为空则用内置测试服务器 |
| `duckcoverage_callback` | `null` | 实现 `DuckCoverageCBInterface` 的回调类 |
| `duckcoverage_report_direct` | `true` | 报告直接写到 `test_reports/` 下（否则按组/按日期分子目录） |
| `duckcoverage_echo_back` | `false` | 回放时回显每个响应前 200 字符 |

### 覆盖率选项（来自 `CoverageBase`）

| 选项 | 默认值 | 说明 |
|---|---|---|
| `duckcoverage_path` | `runtime/` | dump 与报告的基准目录 |
| `duckcoverage_path_src` | 本包 `src/` | 纳入覆盖率统计的源码目录。**请显式配置**为你的应用源码目录 |
| `duckcoverage_path_dump` | `test_coveragedumps` | 覆盖率 dump 文件目录 |
| `duckcoverage_path_report` | `test_reports` | HTML 报告目录 |
| `duckcoverage_group` | `''` | 当前测试组（由 `--watch` 设置） |
| `duckcoverage_name` | `''` | 当前测试名（按请求/调用） |

## 工作原理

1. `--watch <组名>` 写入监听标记（`runtime/DuckCoverage.watching.txt`）。
2. 当请求携带与监听组一致的 `X-MyCoverage-Name: <组名>` 头时，`_OnBeforeRun` / `_OnAfterRun`（通过 `register_shutdown_function` 注册）启动/停止采集、把请求追加到 `<组名>.list`，并把覆盖率 dump 到 `test_coveragedumps/<组名>/`。
3. `--replay` 从回调类的 `GetTestList()` 读取测试列表并逐行执行：
   - `#WEB` 请求通过 curl 对内置测试服务器（或设置了 `duckcoverage_web_base_url` 时的外部服务器）重放，请求携带 `X-MyCoverage-Name` 头，使被测代码再次采集覆盖率。
   - `#CALL` 命令通过反射直接调用本地类/函数。
4. `--report` 合并该组（或多个组）的所有 dump，在 `runtime/test_reports/` 下渲染 HTML 报告（除非覆盖了 `duckcoverage_path` / `duckcoverage_path_report`）。

### 回调类（`DuckCoverageCBInterface`）

实现 `GetTestList()` 来驱动回放：

```php
class MyTester implements \DuckCoverage\DuckCoverageCBInterface
{
    public static function BeforeReplayTest() { /* 钩子，预留 */ }
    public static function AfterReplayTest() { /* 钩子，预留 */ }
    public static function OnReport() { /* 钩子，预留 */ }

    public static function GetTestList()
    {
        return <<<EOT
#WEB /admin/index
#WEB /admin/login
#CMD php cli.php admin/clean
#SETWEB _ _ _ _
#CALL MyApp/Test/Tester@doSomething
EOT;
    }
}
```

测试列表指令：

| 指令 | 含义 |
|---|---|
| `#WEB <uri> [post] [AJAX\|OPTIONS]` | 回放一个 HTTP 请求（设置了 `#URL_PREFIX` 时会自动加前缀） |
| `#CALL <类>@<方法>\|类->方法\|类::方法\|函数 [?参数=值]` | 调用本地可调用对象 |
| `#SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | 为后续 `#WEB` 行设置 curl / web 钩子（`_` 表示清除） |
| `#PHASE <phase>` | 切换 DuckPHP phase（空值忽略） |
| `#URL_PREFIX <前缀>` | 给后续 `#WEB` 的 URI 补前缀 |
| `#CMD <命令>` | 原样执行 shell 命令（不做转义；非 0 退出码只打印警告、不中断回放）；以此方式启动的 DuckPHP CLI 入口同样采集覆盖率（组名通过 `MYCOVERAGE_NAME` 环境变量传递）。请仅回放可信来源的测试列表 |
| `## 注释` / 空行 | 忽略 |

## 相关项目

- [DuckPHP](https://github.com/dvaknheo/duckphp) —— 本扩展所服务的框架（开发版本与本项目同步维护，DuckCoverage 版本跟随 DuckPHP 版本演进）。
- [LibCoverage](https://github.com/dvaknheo/libcoverage) —— 面向独立 PHP 库的覆盖率工具。

## 许可证

[MIT](LICENSE)
