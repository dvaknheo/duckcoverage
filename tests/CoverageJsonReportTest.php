<?php
namespace tests\DuckCoverage;

use DuckCoverage\CoverageJsonReport;
use DuckCoverage\GroupCoverage;
use LibCoverage\LibCoverage;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;

/**
 * report.json 的契约测试。
 *
 * 这里用假 Driver + 直接写入"采集完成后"的 lineCoverage 形态，因此不依赖 xdebug/pcov：
 *   已执行 = 非空数组(命中它的 test id)、可执行未执行 = 空数组、dead code = null。
 */
class CoverageJsonReportTest extends \PHPUnit\Framework\TestCase
{
    public function testJsonReport()
    {
        $old = LibCoverage::_();
        LibCoverage::Begin(CoverageJsonReport::class);

        $root = __DIR__ . '/data_for_tests/json_report/';
        $src = $root . 'src/Sub/';
        @mkdir($src, 0777, true);
        @mkdir($root . 'report', 0777, true);

        $app_file = $src . 'App.php';
        file_put_contents($app_file, <<<'PHP'
<?php
namespace Fixture;

function helper()
{
    return 11;
}

class App
{
    public function run()
    {
        return 22;
    }

    public function never()
    {
        return 33;
    }

    public function dead()
    {
        return 44;
    }
}
PHP);
        $ignored_file = $src . 'Ignored.php';
        file_put_contents($ignored_file, <<<'PHP'
<?php
/**
 * @codeCoverageIgnore
 */
class IgnoredFixture
{
    public function foo()
    {
        return 1;
    }
}
PHP);
        $never_file = $src . 'Never.php';
        file_put_contents($never_file, <<<'PHP'
<?php
class NeverFixture
{
    public function bar()
    {
        return 2;
    }
}
PHP);
        $trait_file = $src . 'Trait.php';
        file_put_contents($trait_file, <<<'PHP'
<?php
trait HelperTrait
{
    public function used()
    {
        return 55;
    }
}
PHP);
        $covered_file = $src . 'Covered.php';
        file_put_contents($covered_file, <<<'PHP'
<?php
class CoveredFixture
{
    public function go()
    {
        return 66;
    }
}
PHP);
        $comments_only_file = $src . 'OnlyComments.php';
        file_put_contents($comments_only_file, "<?php\n// 只有注释，没有可执行行\n");

        $line_in = static function (string $file, string $needle): int {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $text) {
                if (strpos($text, $needle) !== false) {
                    return $i + 1;
                }
            }
            return 0;
        };
        $line_never_called = $line_in($app_file, 'return 33;');
        $line_dead = $line_in($app_file, 'return 44;');

        $filter = new Filter();
        $filter->includeFiles([$app_file, $ignored_file, $never_file, $trait_file, $covered_file, $comments_only_file]);

        // 用 filter 里的真实键(与 fillPartialCoveredFiles 同源)，
        // 否则路径分隔符不同会让同一个文件在 lineCoverage 里出现两个键
        foreach ($filter->files() as $file) {
            if (basename($file) === 'App.php') {
                $app_file = $file;
            }
        }

        $coverage = new CodeCoverage(new CoverageJsonReportDriver(), $filter);
        $coverage->setTests(['T' => ['size' => 'unknown', 'status' => -1]]);
        // 直接注入采集后的数据：helper()/run() 已执行，dead() 是 dead code，
        // never() 驱动没报告(由 fillPartialCoveredFiles 补成"可执行未执行")
        $coverage->getData(true)->setLineCoverage([
            $app_file => [
                $line_in($app_file, 'return 11;') => ['T'],
                $line_in($app_file, 'return 22;') => ['T'],
                $line_dead => null,
            ],
            $trait_file => [$line_in($trait_file, 'return 55;') => ['T']],
            $covered_file => [$line_in($covered_file, 'return 66;') => ['T']],
            // 同一个文件换个分隔符再给一份(模拟 Windows 路径形态)：
            // 覆盖 indexLineCoverage 的合并优先级(已执行 > 空数组 > dead code)
            str_replace('/', '\\', $app_file) => [
                $line_in($app_file, 'return 11;') => [],
                $line_never_called => null,
            ],
        ]);
        $coverage->getData(true)->setFunctionCoverage([]);

        // 与 createReport() 同样顺序：先取 loaded 快照(原始数据)，再补坑，最后出 JSON
        $loaded = array_keys($coverage->getData(true)->lineCoverage());

        $group_coverage = new CoverageJsonReportGroupCoverage();
        $group_coverage->fillPartialCoveredFilesForTest($coverage);
        $json_report = CoverageJsonReport::_();
        $report = $json_report->render($coverage, [
            'groups' => ['g1'],
            'dumps_merged' => 7,
            'path_src' => $root . 'src/',
            'path_root' => $root,
            'loaded_files' => $loaded,
            'files' => array_values(array_filter($filter->files(), static function ($file) {
                return substr($file, -4) === '.php';
            })),
            'driver_class' => 'SebastianBergmann\\CodeCoverage\\Driver\\Xdebug3Driver',
        ]);
        $json_file = $json_report->write($report, $root . 'report/');
        $this->assertSame($json_file, $json_report->report($coverage, [
            'groups' => ['g1'],
            'dumps_merged' => 7,
            'path_src' => $root . 'src/',
            'path_root' => $root,
            'loaded_files' => $loaded,
            'files' => $filter->files(),
            'driver_class' => 'SebastianBergmann\\CodeCoverage\\Driver\\Xdebug3Driver',
        ], $root . 'report/'));

        $this->assertSame('duckcoverage-report/1', $report['schema']);
        $this->assertSame('src/', $report['root']);
        $this->assertSame(['g1'], $report['groups']);
        $this->assertSame(7, $report['dumps_merged']);
        $this->assertSame(PHP_VERSION, $report['generator']['php']);

        $files = [];
        foreach ($report['files'] as $file_entry) {
            $files[$file_entry['path']] = $file_entry;
        }
        $this->assertArrayHasKey('src/Sub/App.php', $files);
        $app = $files['src/Sub/App.php'];

        // 完整相对路径 + 目录 + 子应用
        $this->assertSame('src/Sub', $app['dir']);
        $this->assertSame('Sub', $app['app']);
        $this->assertSame(sha1_file($app_file), $app['sha1']);
        $this->assertTrue($app['loaded']);

        // 行级：executed/executable 计数 + 未覆盖行 + line_map 的 1/-1/-2
        $this->assertSame(2, $app['lines']['executed']);
        $this->assertContains($line_never_called, $app['uncovered_lines']);
        $this->assertSame(-1, $app['line_map'][(string)$line_never_called]);
        $this->assertSame(-2, $app['line_map'][(string)$line_dead]);
        $this->assertSame(1, $app['line_map'][(string)$line_in($app_file, 'return 11;')]);
        $this->assertNotContains($line_dead, $app['uncovered_lines'], 'dead code 不应算进未覆盖行');
        $this->assertSame(
            $app['lines']['executable'],
            count(array_filter($app['line_map'], static function ($value) {
                return $value !== -2;
            }))
        );

        // 单元级：函数 + 方法，能定位到方法
        $covered = [];
        foreach ($app['function_items'] as $item) {
            $covered[($item['class'] ?? '') . '::' . $item['name']] = $item['covered'];
        }
        $this->assertTrue($covered['App::run']);
        $this->assertFalse($covered['App::never']);
        $this->assertArrayHasKey('::helper', $covered);
        $this->assertSame(count($app['function_items']), $app['functions']['total']);
        $this->assertSame(1, $app['classes']['total']);

        // ignored / 从未加载
        $ignored = array_column($report['ignored_files'], 'path');
        $this->assertContains('src/Sub/Ignored.php', $ignored);
        $this->assertArrayHasKey('src/Sub/Never.php', $files);
        $this->assertFalse($files['src/Sub/Never.php']['loaded']);
        $this->assertSame(0, $files['src/Sub/Never.php']['lines']['executed']);

        // 汇总口径与文件级一致
        $executable = 0;
        $executed = 0;
        foreach ($report['files'] as $file_entry) {
            $executable += $file_entry['lines']['executable'];
            $executed += $file_entry['lines']['executed'];
        }
        $this->assertSame($executable, $report['totals']['lines']['executable']);
        $this->assertSame($executed, $report['totals']['lines']['executed']);
        $this->assertSame(count($report['files']), $report['totals']['files']);

        // 落盘：可解析、路径已排序、不含绝对路径
        $this->assertFileExists($json_file);
        $json = (string)file_get_contents($json_file);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame($report['totals']['lines'], $decoded['totals']['lines']);
        $paths = array_column($report['files'], 'path');
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $paths);
        $this->assertStringNotContainsString(str_replace('\\', '/', $root), str_replace('\\', '/', $json));

        // 只有注释的文件没有可执行行
        $this->assertContains('src/Sub/OnlyComments.php', $ignored);
        // trait 统计 + 被完整覆盖的类
        $this->assertSame(1, $files['src/Sub/Trait.php']['traits']['total']);
        $this->assertSame(1, $files['src/Sub/Trait.php']['traits']['covered']);
        $this->assertSame(1, $files['src/Sub/Covered.php']['classes']['covered']);

        // 路径基准回退(root='.')、路径不带结尾斜杠、pcov 驱动名
        $fallback_context = [
            'groups' => ['g1'],
            'dumps_merged' => 0,
            'path_src' => rtrim($root, '/') . '/src',
            'path_root' => '/not-a-parent',
            'loaded_files' => $loaded,
            'files' => $filter->files(),
            'driver_class' => 'SebastianBergmann\\CodeCoverage\\Driver\\PcovDriver',
        ];
        $fallback = $json_report->render($coverage, $fallback_context);
        $this->assertSame('.', $fallback['root']);
        $this->assertStringStartsWith('pcov', (string)$fallback['generator']['coverage_driver']);
        $this->assertSame($json_file, $json_report->report($coverage, $fallback_context, $root . 'report/'));

        LibCoverage::_($old);
        LibCoverage::End();
    }
}

/**
 * 假 Driver：只为了能构造 CodeCoverage，本测试不经过采集流程
 */
class CoverageJsonReportDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'stub-1.0';
    }
    public function start(): void
    {
    }
    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
    }
}

/**
 * 暴露 protected 的补坑步骤(其余 JSON 逻辑已抽到 CoverageJsonReport，方法本身就是 public)
 */
class CoverageJsonReportGroupCoverage extends GroupCoverage
{
    public function fillPartialCoveredFilesForTest(CodeCoverage $coverage): void
    {
        $this->fillPartialCoveredFiles($coverage);
    }
}
