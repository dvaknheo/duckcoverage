# DuckCoverage

[English](README.md) | [English Quick Start](QUICKSTART.md) | [快速使用手册](QUICKSTART-zh.md)

**DuckCoverage** 是用用于 [DuckPHP](https://github.com/dvaknheo/duckphp) 应用的测试覆盖率扩展。
它从真实的 HTTP 请求和 CLI 调用中采集行覆盖率，回放已记录的请求，并生成 HTML 覆盖率报告。

它配合 [LibCoverage](https://github.com/dvaknheo/libcoverage)（面向独立 PHP 库的覆盖率工具）使用，两者共用 `test_coveragedumps` / `test_reports` 目录约定。

## 特性

TODO  AIFIX

## 环境要求

- PHP >= 7.4
- DuckPHP（>= 1.4.1）
- `phpunit/php-code-coverage`（9.x）
- 覆盖率驱动：需加载 **Xdebug** 或 **PCOV** 之一

## 安装

```bash
composer require dvaknheo/duckcoverage
```

## 快速开始

在 DuckPHP 应用中启用的一般用法，我们以 `dvaknheo/duckadmin` 包为例

应用入口类 `DuckAdminDemo\System\DemoApp`

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
        // 'duckcoverage_web_base_url' => 'http://www.example.com/',
        // ...

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        if (class_exsits(DuckCoverage::class)) {
            DuckCoverage::Prepare([]);
        }
    }
```

// 和其他常见 DuckPhp 插件不同的, DuckCoverage 必须按示例在根应用的 `onPrepare` 做初始化。 DuckCoverage 选项也需要放进 根应用的应用选项里。


测试列表回调类 `DuckAdminDemo\System\TestLister`
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
CMD mycommand anything.
CALL MyApp\Test\Tester@doSomething
EOT;
    }
}
```
对应 `duckcoverage_callback` 的回调。 返回一系列测试指令列表， 每一行对应一个测试指令，详见后续详细说明。

`duckcoverage_callback` 应用选项。 无需引入 DuckCoverage 。 也不局限于 根应用。

### 执行测试

```bash
php cli.php duckcover --go group1
```
执行之后根据 duckcoverage_callback 返回的 命令清单执行一系列结果

然后在 `runtime/DuckCoverage/group1.report/` 的位置生成测试覆盖报告。

## 命令行参考

全部指令
```bash
php cli.php duckcover 
  --watch {group}
  --replay
  --stop
  --report group1
  --report group1 group2 group3
  --go {group}

```
CLI 入口文件（`cli.php`）遵循你的 DuckPHP 工程模板，如果工程用了别的入口请相应替换。



```bash
php cli.php duckcover --go group1
```
通常情况下，用 `--go` 跟踪，播放测试动作， 生成测试报告.相当于连续执行
```bash
php cli.php duckcover --watch group1
php cli.php duckcover --replay
php cli.php duckcover --stop
php cli.php duckcover --report group1
```

其他

```bash
php cli.php duckcover --watch mygroup      # 开始监听测试组 mygroup
                                           # 浏览你的应用，每个被追踪的请求都会被记录并采集覆盖率
php cli.php duckcover --replay             # 回放已记录请求（需要回调类，见下文）
php cli.php duckcover --report             # 为当前组生成 HTML 报告

php cli.php duckcover --stop               # 停止监听
```
### 工作原理
1. `--watch <组名>` 写入监听标记（`runtime/DuckCoverage.watching.txt`）。
2. 当请求携带与监听组一致的 `X-MyCoverage-Name: <组名>` 头时，`_OnBeforeRun` / `_OnAfterRun`（通过 `register_shutdown_function` 注册）启动/停止采集、把请求追加到 `<组名>.list`，并把覆盖率 dump 到 `test_coveragedumps/<组名>/`。
3. `--replay` 从回调类的 `GetTestList()` 读取测试列表并逐行执行：
   - `#WEB` 请求通过 curl 对内置测试服务器（或设置了 `duckcoverage_web_base_url` 时的外部服务器）重放，请求携带 `X-MyCoverage-Name` 头，使被测代码再次采集覆盖率。
   - `#CALL` 命令通过反射直接调用本地类/函数。
4. `--report` 合并该组（或多个组）的所有 dump，在 `runtime/test_reports/` 下渲染 HTML 报告。

## 选项参考

所有配置都通过 DuckPHP 应用选项传入（key 以 `duckcoverage_` 开头）。

        'duckcoverage_enable' => true,
启用 duckcoverage_enable

        'duckcoverage_callback' => null,
回调
        'duckcoverage_reg_console_command' => true,
注册命令行，使得  duckcover 指令生效

        'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',
移动 额外选项文件到新文件位置，隔离配置环境

        'duckcoverage_path' => '',
基础路径
        'duckcoverage_path_src' => 'src/',
对应的 源代码类

        'duckcoverage_report_direct' => false,
直接报告目录，而不是

        'duckcoverage_web_base_url' => '',
外部服务器(如 nginx)基础 URL,如 http://www.example.com/ ;空则退回内部测试服务器

        'duckcoverage_server_port' => 8017,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/',
        'duckcoverage_new_server' => true,
使用内置服务器 `duckcoverage_new_server` 表示清理前面可能被覆盖的服务器配置。

        'duckcoverage_debug_curl_echo_back' => false,
显示 curl 每个响应前 200 字符，用于调试


## 测试指令参考

下面是全部测试指令：

| 指令 | 说明 |
|---|---|
| `#PHASE_BEGIN` | 宏指令,无参数， 进入当前 Phase|
| `#PHASE_END` | 宏指令,无参数， 退出当前 Phase|
| `#INCLUDE_CALL` | 宏指令,把调用结果加入当前列表|
| `#INCLUDE_CHILD` | 宏指令,把子应用加入当前列表|
| `WEB <uri> [post] [AJAX\|OPTIONS]` | 回放一个 HTTP 请求 |
| `RUN <命令>` | 原样执行 shell 命令（不做转义；非 0 退出码只打印警告、不中断回放）；
| `CALL ` | 调用本地可调用对象 |
| `SETWEB <pre_curl> <pre_webcall> <post_webcall> <post_curl>` | 为后续 `#WEB` 行设置 curl / web 钩子（`_` 表示清除） |
| `PHASE <phase>` | 切换 DuckPHP phase（空值忽略） |
| `COMMENT 注释`| 忽略 |

### 说明

`#PHASE_BEGIN` `#PHASE_END` 无参数。在进入和退出 阶段使用

`CALL` ,  `#INCLUDE_CALL` 以 `{phase}!class[->|::@]method uri_encode` 调用 。如果没有 ! 

`#INCLUDE_CALL`  是调用的时候，嵌入把结果嵌入当前测试列表

`RUN `  如果子命令 以 : 开始. 那么不切成子命令。 
run 不会单开进程，而是在当前进程模拟。

`WEB` uri 会被 `__url()` 函数封装

SETWEB 设置钩子下一个web调用后会还原。 
pre_curl,post_curl  在本地进程执行。 回调参数是 （$ch, $name）,你
pre_webcall, post_webcall 在 服务端进行




## 相关方法

## 公开方法

    public static function BeforeRun()
    public static function AfterRun()

    public static function Prepare($options = [])

    public function beforeInit($options = [])
    public function _OnAfterRun()
    public function _OnBeforeRun()

    public function init(array $options, ?object $context = null)
    public function genTestListOfAll()
    public function command_duckcover()

BeforeRun(_OnBeforeRun) AfterRun(_OnBeforeRun) ，只是钩子回调用。

你关心的应该是 Prepare()
init() 会插入 一个 ext 扩展 运行

genTestListOfAll() 用于辅助生成测试命令列表。

command_duckcover 注册命令行

## 原理

## 常见问题

**Q：报告里没有我的应用代码？**
`duckcoverage_path_src` 没配或配错。它默认指向 duckcoverage 包自身的 `src/`，请显式配置为你的源码目录（推荐绝对路径）。

**Q：报错 "Need CodeCoverage" 或无法创建 CodeCoverage？**
缺少 `phpunit/php-code-coverage`，或没有加载 xdebug/pcov 驱动。见第 1、2 节。

**Q：`--replay` 什么都没做？**
回放依赖 `duckcoverage_callback` 的 `GetTestList()`。

**Q：端口 8080 被占用？**
改 `duckcoverage_server_port`。

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
