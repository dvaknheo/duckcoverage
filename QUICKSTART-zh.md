# DuckCoverage 快速使用手册

本手册面向 DuckPHP 应用开发者，演示如何在 10 分钟内用 DuckCoverage 拿到一份 Web 应用的行覆盖率 HTML 报告。

> 语言版本：[English Quick Start](QUICKSTART.md) · [English README](README.md) · [中文 README](README-zh.md)

---

## 1. 前置条件

| 项目 | 要求 |
|---|---|
| PHP | >= 7.4 |
| 覆盖率驱动 | 已加载 **Xdebug** 或 **PCOV**（`php -m` 里能看到其一） |
| 框架 | DuckPHP >= 1.4（内置 `DuckPhp\HttpServer\HttpServer`） |
| 依赖包 | `phpunit/php-code-coverage`（9.x，php-code-coverage 的 `Driver\Selector` API） |

检查驱动：

```bash
php -m | findstr /i "xdebug pcov"        # Windows
php -m | grep -i -E "xdebug|pcov"        # Linux / macOS
```

没有驱动时，DuckCoverage 会给出报错（`doBegin` 阶段无法创建 `CodeCoverage`），先装上驱动再来。

---

## 2. 安装

在你的 DuckPHP 工程里：

```bash
composer require dvaknheo/duckcoverage
composer require --dev phpunit/php-code-coverage ^9.0   # 按你的 PHP 版本选择合适的版本
```

> duckcoverage 的 `composer.json` 只在 `require-dev` 里声明了 `dvaknheo/libcoverage`（用于本项目自身的开发测试），运行时依赖需要你显式安装。

---

## 3. 在 DuckPHP 应用中启用

编辑你的应用入口类（如 `src/System/App.php`）：

```php
<?php
namespace MyApp\System;

use DuckCoverage\DuckCoverage;

class App extends \DuckPhp\DuckPhp
{
    public $options = [
        // ... 你原有的选项 ...

        // 注册扩展
        'ext' => [
            DuckCoverage::class => true,
        ],

        // 常用配置（均为可选，括号内是默认值）
        'duckcoverage_enable' => true,                    // 总开关
        'duckcoverage_path_src' => __DIR__ . '/../',      // 要统计的源码目录（必配！见下方说明）
        'duckcoverage_server_port' => 8080,               // 内置测试服务器端口
        'duckcoverage_homepage' => '/index_dev.php/',     // 内置服务器基础 URI
        // 'duckcoverage_web_base_url' => 'http://admin.duckphp-local.com/', // 用外部服务器时设置
        // 'duckcoverage_callback_class' => \MyApp\Test\Tester::class,      // 回放回调类
    ];
}
```

> **重要：`duckcoverage_path_src` 必须显式配置。** 它的默认值是 duckcoverage 包自身的 `src/`（统计的是本扩展的源码），不配置的话报告里看不到你的应用代码。建议用绝对路径（如上面 `__DIR__ . '/../'`），或用相对 `runtime/` 的路径（如 `'../src'`）。

---

## 4. 流程 A：Web 测试（推荐）

这是 DuckCoverage 的核心用法：**真实浏览你的网站，覆盖率自动采集，然后回放并出报告。**

### 4.1 开始监听

```bash
php cli.php duckcover --watch mygroup
```

输出 `watching mygroup`，此时 `runtime/DuckCoverage.watching.txt` 已写入。组名 `mygroup` 随便起，用于区分多轮测试。

### 4.2 触发请求（带追踪头）

用 curl 携带追踪头访问你的应用：

```bash
curl -H "X-MyCoverage-Name: mygroup" "http://127.0.0.1:8080/index_dev.php/admin/index"
curl -H "X-MyCoverage-Name: mygroup" -d "username=admin&password=123456" "http://127.0.0.1:8080/index_dev.php/admin/login"
```

- 内置服务器：URL = `http://127.0.0.1:{duckcoverage_server_port}` + `duckcoverage_homepage` + 你的路径。
- 外部服务器（nginx 等）：先配置 `duckcoverage_web_base_url`，然后用外部 URL 访问即可。
- 浏览器手动测试也行，但需要能自定义请求头（如 DevTools 里改请求头，或用代理工具）。
- 每次命中追踪头的请求都会：① 追加到 `test_coveragedumps/mygroup.list`（请求记录）；② 采集并把覆盖率 dump 到 `test_coveragedumps/mygroup/`。

### 4.3 配置回放回调类

`--replay` 需要回调类提供测试列表。新建一个实现 `DuckCoverageCBInterface` 的类（如 `src/Test/Tester.php`）：

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
        // 可以直接把 test_coveragedumps/mygroup.list 的内容粘到这里，
        // 也可以手写（支持 #WEB / #CALL / #SETWEB / #PHASE / #URL_PREFIX 指令）
        return <<<EOT
#WEB /admin/index
#WEB /admin/login username=admin&password=123456
#CALL MyApp/Test/Tester@doSomething
EOT;
    }
}
```

然后在 `App::$options` 里注册：

```php
'duckcoverage_callback_class' => \MyApp\Test\Tester::class,
```

### 4.4 回放

```bash
php cli.php duckcover --replay
```

回放会启动内置测试服务器（或请求外部服务器），逐条执行测试列表，每条请求都会重新采集覆盖率（curl 会自动带上 `X-MyCoverage-Name` 头）。结束时自动停止服务器。

### 4.5 生成报告

```bash
php cli.php duckcover --report
```

输出类似：

```
reporting...
time_cost   : 0.523 seconds
output path : runtime/test_reports/
```

用浏览器打开 `runtime/test_reports/index.html` 查看行覆盖率。红/黄标记的行就是没执行到的代码。

### 4.6 结束监听

```bash
php cli.php duckcover --stop
```

---

## 5. 流程 B：CLI 直接调用

不经过 HTTP，直接调用类方法/函数采集覆盖率：

```bash
# 类名用斜杠分隔（自动转成反斜杠），@ 表示单例 _() 调用
php cli.php duckcover --call MyApp/Test/Tester@doSomething

# 其他写法：Class->method（new 实例）、Class::method（静态）、纯函数
php cli.php duckcover --call MyApp/Business/DemoBusiness->handle
php cli.php duckcover --call MyApp/Helper::format
php cli.php duckcover --call some_function
```

带参数（`?key=value`，按方法参数名匹配）：

```bash
php cli.php duckcover --call MyApp/Test/Tester@runX?parameter=d
```

调用结果与 dump 都落入当前监听组的 `test_coveragedumps/`，之后照常 `--report`。

---

## 6. 测试列表指令速查

| 指令 | 示例 | 说明 |
|---|---|---|
| `#WEB` | `#WEB /admin/index` | 回放一个 Web 请求；第二段是 POST 参数（`a=1&b=2`），第三段可写 `AJAX` 或 `OPTIONS` |
| `#CALL` | `#CALL Foo/Bar@run?x=1` | 直接调用本地类/函数 |
| `#SETWEB` | `#SETWEB _ _ _ _` | 给后续 `#WEB` 设置钩子，依次为 `pre_curl pre_webcall post_webcall post_curl`，`_` 表示清除 |
| `#PHASE` | `#PHASE api` | 切换 DuckPHP phase |
| `#URL_PREFIX` | `#URL_PREFIX /v1` | 给后续 `#WEB` 的 URI 补前缀 |
| `##` | `## 注释` | 注释行，忽略 |

---

## 7. 使用外部服务器（nginx 等）

不想用内置测试服务器时，配置外部服务器地址：

```php
'duckcoverage_web_base_url' => 'http://admin.duckphp-local.com/',
```

- 之后 `#WEB /admin/index` 会请求 `http://admin.duckphp-local.com/admin/index`。
- 外部服务器必须能接收并转发 `X-MyCoverage-Name` 请求头（nginx 默认透传自定义头，无需额外配置）。
- 仍需要先 `--watch` 同一组名，保证 `isInHttpTest()` 命中。

---

## 8. 常见问题

**Q：报告里没有我的应用代码？**
`duckcoverage_path_src` 没配或配错。它默认指向 duckcoverage 包自身的 `src/`，请显式配置为你的源码目录（推荐绝对路径）。

**Q：报错 "Need CodeCoverage" 或无法创建 CodeCoverage？**
缺少 `phpunit/php-code-coverage`，或没有加载 xdebug/pcov 驱动。见第 1、2 节。

**Q：`--replay` 什么都没做？**
回放依赖 `duckcoverage_callback_class` 的 `GetTestList()`。先配置回调类（见 4.3），或先跑一轮带追踪头的请求生成 `.list` 作为参考。

**Q：端口 8080 被占用？**
改 `duckcoverage_server_port`。

**Q：内置服务器起不来？**
确认 `duckcoverage_path_server`（默认工程根目录）与 `duckcoverage_path_document`（默认 `public`）指向正确，且 `duckcoverage_homepage` 与你的开发入口一致。

**Q：怎么把多轮测试合并？**
用同一组名 watch，多轮请求都累积到该组的 dump；`--report mygroup` 一次出报告。也可以 `--report a b c` 一次合并多个组。

**Q：报告目录太乱？**
设置 `duckcoverage_report_direct => false`，报告会按组名（单组）或日期（多组）分目录存放。

---

## 9. 相关链接

- 项目主页：<https://github.com/dvaknheo/duckcoverage>
- DuckPHP：<https://github.com/dvaknheo/duckphp>
- LibCoverage（独立库覆盖率工具）：<https://github.com/dvaknheo/libcoverage>
- 许可证：[MIT](LICENSE)
