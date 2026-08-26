# DuckCoverage 快速使用手册

本手册面向 DuckPHP 应用开发者，演示如何在 10 分钟内用 DuckCoverage 拿到一份 Web 应用的行覆盖率 HTML 报告。

> 语言版本：[English Quick Start](QUICKSTART.md) · [English README](README.md) · [中文 README](README-zh.md)

---

## 1. 前置条件

| 项目 | 要求 |
|---|---|
| PHP | >= 7.4 |
| 覆盖率驱动 | 已加载 **Xdebug** 或 **PCOV**（`php -m` 里能看到其一） |
| 框架 | DuckPHP >= 1.4.1（内置 `DuckPhp\HttpServer\HttpServer`） |

---

## 2. 安装

在你的 DuckPHP 工程里：

```bash
composer require dvaknheo/duckcoverage
```

---

## 3. 在 DuckPHP 应用中启用

一般用法，我们以 dvaknheo/duckadmin 包为例

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
        'duckcoverage_report_direct' => false,
        'duckcoverage_web_base_url' => 'http://www.***.com/',

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        if (class_exsits(DuckCoverage::class)) {
            DuckCoverage::Prepare();
        }
    }
    public function serve(): bool
    {
        if (!class_exsits(DuckCoverage::class)) {
            return parent::serve();
        }
        DuckCoverage::BeforeRun();
        $flag = $parent::serve();
        DuckCoverage::AfterRun();
        return $flag;
    }
}
```

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
CMD php cli.php admin/clean
CALL MyApp/Test/Tester@doSomething
EOT;
    }
}
```

// 和其他常见 DuckPhp 插件不同的, DuckCoverage 必须按示例在根应用做初始化。 DuckCoverage 选项也需要放进 根应用的应用选项里。


### 3.1 执行测试

```bash
php cli.php duckcover --go group1
```
//TODO 结果展示
执行之后
根据 duckcoverage_callback 返回的 命令清单执行一系列结果
然后在 `runtime/DuckCoverage/group1.report/` 的位置生成测试覆盖报告。

## 4. 命令行参考

```bash
php cli.php duckcover 
--watch {group}
--replay
--stop
--report group1
--report group1 group2 group3
--go {group}

```


## 5 测试指令参考

下面是全部测试指令：
| 指令 | 示例 | 说明 |
|---|---|---|
| `WEB` | `WEB /admin/index` | 回放一个 Web 请求；第二段是 POST 参数（`a=1&b=2`），第三段可写 `AJAX` 或 `OPTIONS` |
| `CMD` | `CMD callme admin/clean` | 原样执行 shell 命令 |
| `CALL` | `CALL Foo/Bar@run x=1` | 直接调用本地类/函数 |
| `SETWEB` | `SETWEB _ _ _ _` | 给后续 `#WEB` 设置钩子，依次为 `pre_curl pre_webcall post_webcall post_curl`，`_` 表示清除 |
| `PHASE` | `PHASE api` | 切换 DuckPHP phase |
---

## 6. 选项参考

``` php
    public $options = [
        'duckcoverage_enable' => true,
        'duckcoverage_callback' => null,

        'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',
        'duckcoverage_reg_console_command' => true,

        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/',
        'duckcoverage_report_direct' => false,

        'duckcoverage_web_base_url' => '',
        // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器
        'duckcoverage_server_port' => 8017,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/',
        'duckcoverage_new_server' => true,
        'duckcoverage_debug_curl_echo_back' => false,

    ];
```
### 6.1 说明

### 6.2 使用外部服务器（nginx 等）

不想用内置测试服务器时，配置外部服务器地址：

```php
'duckcoverage_web_base_url' => 'http://admin.duckphp-local.com/',
```

- 之后 `#WEB /admin/index` 会请求 `http://admin.duckphp-local.com/admin/index`。
- 外部服务器必须能接收并转发 `X-MyCoverage-Name` 请求头（nginx 默认透传自定义头，无需额外配置）。
- 仍需要先 `--watch` 同一组名，保证 `isInHttpTest()` 命中。

---

## 7. 常见问题

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

---

## 10. 相关链接

- 项目主页：<https://github.com/dvaknheo/duckcoverage>
- DuckPHP：<https://github.com/dvaknheo/duckphp>
- LibCoverage（独立库覆盖率工具）：<https://github.com/dvaknheo/libcoverage>
- 许可证：[MIT](LICENSE)
