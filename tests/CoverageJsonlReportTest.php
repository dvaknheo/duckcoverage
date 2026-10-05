<?php
namespace tests\DuckCoverage;

use DuckCoverage\CoverageJsonReport;
use DuckCoverage\CoverageJsonlReport;
use DuckCoverage\GroupCoverage;
use LibCoverage\LibCoverage;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;

/**
 * report.jsonl 的契约测试(规格 coverage-report-jsonl-proposal.md §11)。
 *
 * 和 CoverageJsonReportTest 一样：用假 Driver + 直接写入"采集完成后"的 lineCoverage 形态，
 * 因此不依赖 xdebug/pcov。CoverageJsonlReport 只做纯变换，这里喂给它 CoverageJsonReport 渲染出的数组。
 */
class CoverageJsonlReportTest extends \PHPUnit\Framework\TestCase
{
    public function testJsonlReport()
    {
        $old = LibCoverage::_();
        // LibCoverage 一次只收集 Begin() 那个类自己的文件；本测试除了新类，还要覆盖
        // CoverageJsonReport 里新增的签名行(sig)逻辑，所以把它加成 extFile 一起收集。
        LibCoverage::_()->addExtFile(dirname(__DIR__) . '/src/CoverageJsonReport.php');
        LibCoverage::Begin(CoverageJsonlReport::class);

        $root = __DIR__ . '/data_for_tests/jsonl_report/';
        $src = $root . 'src/';
        @mkdir($src . 'Inner/', 0777, true);
        @mkdir($root . 'report/', 0777, true);

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

    public function multi(
        $a,
        array $ext = []
    ) {
        return 44;
    }

    public function unused(
        $a,
        array $ext = array()
    ) {
        if ($a) {
            return 66;
        }
        return 67;
    }

    public function dead()
    {
        return 55;
    }

    public function interp(
        $a
    ) {
        return "x{$a}y";
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
        $comments_file = $src . 'OnlyComments.php';
        file_put_contents($comments_file, "<?php\n// 只有注释，没有可执行行\n");
        $inner_file = $src . 'Inner/Deep.php';
        file_put_contents($inner_file, <<<'PHP'
<?php
class DeepFixture
{
    public function go()
    {
        return 77;
    }
}
PHP);
        $abstract_file = $src . 'AbstractFixture.php';
        file_put_contents($abstract_file, <<<'PHP'
<?php
abstract class AbstractFixture
{
    abstract public function must(
        $a,
        array $ext = []
    );
}
PHP);

        $filter = new Filter();
        $filter->includeFiles([$app_file, $ignored_file, $never_file, $trait_file, $covered_file, $comments_file, $inner_file, $abstract_file]);
        $filter_files = array_values(array_filter($filter->files(), static function ($file) {
            return substr($file, -4) === '.php';
        }));
        // 用 filter 里的真实键(与 fillPartialCoveredFiles 同源)，否则路径分隔符不同会多出一个键
        $file_of = [];
        foreach ($filter_files as $file) {
            $file_of[basename($file)] = $file;
        }
        $app_file = $file_of['App.php'];
        $trait_file = $file_of['Trait.php'];
        $covered_file = $file_of['Covered.php'];
        $inner_file = $file_of['Deep.php'];

        $line_helper = $this->lineIn($app_file, 'return 11;');
        $line_run = $this->lineIn($app_file, 'return 22;');
        $line_never = $this->lineIn($app_file, 'return 33;');
        $line_multi_sig = $this->lineIn($app_file, 'array $ext = []');
        $line_multi_body = $this->lineIn($app_file, 'return 44;');
        $line_unused_sig = $this->lineIn($app_file, 'array $ext = array()');
        $line_unused_body = $this->lineIn($app_file, 'return 66;');
        $line_dead = $this->lineIn($app_file, 'return 55;');
        $line_interp = $this->lineIn($app_file, 'return "x{$a}y";');

        $coverage = new CodeCoverage(new CoverageJsonlReportDriver(), $filter);
        $coverage->setTests(['T' => ['size' => 'unknown', 'status' => -1]]);
        // 直接注入采集后的数据：
        // - helper()/run()/multi()/interp() 的函数体执行过；
        // - multi() 的签名行(默认值)故意不写，交给 fillPartialCoveredFiles 补成"可执行未执行"；
        // - never()/unused() 完全不给：驱动从没报告过它们(整个方法从没被调用)；
        // - dead() 是 dead code；
        // - unused() 的函数体带嵌套花括号、interp() 带字符串插值：给 token 扫描的边界计数当夹具。
        $coverage->getData(true)->setLineCoverage([
            $app_file => [
                $line_helper => ['T'],
                $line_run => ['T'],
                $line_multi_body => ['T'],
                $line_interp => ['T'],
                $line_dead => null,
            ],
            $trait_file => [$this->lineIn($trait_file, 'return 55;') => ['T']],
            $covered_file => [$this->lineIn($covered_file, 'return 66;') => ['T']],
            $inner_file => [$this->lineIn($inner_file, 'return 77;') => ['T']],
        ]);
        $coverage->getData(true)->setFunctionCoverage([]);

        // 与 createReport() 同样顺序：先取 loaded 快照(原始数据)，再补坑，最后出报表
        $loaded = array_keys($coverage->getData(true)->lineCoverage());
        $group_coverage = new CoverageJsonlReportGroupCoverage();
        $group_coverage->fillPartialCoveredFilesForTest($coverage);

        $report = CoverageJsonReport::_()->render($coverage, [
            'groups' => ['g1', 'g2'],
            'dumps_merged' => 10,
            'path_src' => $root . 'src/',
            'path_root' => $root,
            'loaded_files' => $loaded,
            'files' => $filter_files,
            'driver_class' => 'SebastianBergmann\\CodeCoverage\\Driver\\Xdebug3Driver',
        ]);
        $file_records = [];
        foreach ($report['files'] as $item) {
            $file_records[$item['path']] = $item;
        }

        $jsonl_report = CoverageJsonlReport::_();
        $context = [
            'detail' => 'uncovered',
            'timestamp' => false,
            'group_dumps' => ['g1' => 7],
            'errors' => [
                ['group' => 'g2', 'msg' => 'group skipped: provider not selectable', 'fatal' => true],
                ['msg' => 'non fatal trouble', 'fatal' => false],
            ],
        ];
        $jsonl = $jsonl_report->render($report, $context);

        // §11-2：逐行 json_decode 都要过，无裸换行、无 CRLF、无 BOM
        $this->assertStringNotContainsString("\r", $jsonl, '不允许 CRLF');
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $jsonl, '不允许 BOM');
        $lines = explode("\n", $jsonl);
        $this->assertSame('', array_pop($lines), '最后一行必须由 "\n" 收尾');
        $this->assertSame(count($lines), substr_count($jsonl, "\n"), '除了行终止符不该有别的换行');

        $types = [];
        $decoded = [];
        foreach ($lines as $index => $line) {
            $record = json_decode($line, true);
            $this->assertIsArray($record, '第 ' . ($index + 1) . ' 行必须能单独解析');
            $this->assertIsString($record['t'] ?? null, '每行都要有字符串字段 t');
            $types[$index] = $record['t'];
            $decoded[$index] = $record;
        }
        $first_of = static function (array $types, string $type): int {
            $index = array_search($type, $types, true);
            return $index === false ? -1 : (int)$index;
        };

        // §11-1：首行 meta、末行 total，且各只有一行
        $this->assertSame('meta', $types[0]);
        $this->assertSame('total', $types[count($types) - 1]);
        $this->assertSame(1, count(array_keys($types, 'meta', true)));
        $this->assertSame(1, count(array_keys($types, 'total', true)));

        // §11-3：同一份数据 + timestamp=false 渲染两次逐字节一致
        $this->assertSame($jsonl, $jsonl_report->render($report, $context));

        $meta = $decoded[0];
        $total = $decoded[count($decoded) - 1];
        $this->assertSame('duckcoverage-report-jsonl/1', $meta['schema']);
        $this->assertSame(['name' => 'duckcoverage', 'version' => $report['generator']['version']], $meta['generator']);
        $this->assertSame(['t', 'schema', 'generator', 'php', 'driver', 'root', 'groups', 'dumps', 'detail'], array_keys($meta));
        $this->assertSame(PHP_VERSION, $meta['php']);
        $this->assertStringStartsWith('xdebug', $meta['driver']);
        $this->assertSame('src/', $meta['root']);
        $this->assertSame(['g1', 'g2'], $meta['groups']);
        $this->assertSame(10, $meta['dumps']);
        $this->assertSame('uncovered', $meta['detail']);

        // §6.2：时间戳只出现在 meta；timestamp=false 时连字段都没有
        $with_time = $jsonl_report->render($report, ['detail' => 'uncovered']);
        $this->assertSame(1, substr_count($with_time, '"created"'));
        $this->assertStringContainsString('"created":"' . $report['generated_at'] . '"', $with_time);
        $this->assertSame($report['generated_at'], json_decode(explode("\n", $with_time)[0], true)['created']);

        // total：完整性哨兵
        $this->assertTrue($total['complete']);
        $this->assertSame(count($file_records), $total['files']);
        $this->assertSame($report['totals']['lines']['executable'], $total['lines']['executable']);
        $this->assertSame($report['totals']['lines']['executed'], $total['lines']['executed']);
        $this->assertSame($report['totals']['functions']['total'], $total['funcs']['total']);
        $this->assertSame(
            ['file', 'file_func', 'file_lines', 'dir', 'group', 'ignored', 'error', 'meta', 'total'],
            array_keys($total['records'])
        );

        // §11-4：records 是各类记录的实际条数，其和等于文件行数
        $counted = [];
        foreach ($types as $type) {
            $counted[$type] = ($counted[$type] ?? 0) + 1;
        }
        foreach ($total['records'] as $type => $count) {
            $this->assertSame($counted[$type] ?? 0, $count, 'records.' . $type);
        }
        $this->assertSame(count($lines), array_sum($total['records']));
        $this->assertSame(2, $total['records']['group']);
        $this->assertSame(2, $total['records']['dir']);
        $this->assertSame(2, $total['records']['ignored']);
        $this->assertSame(2, $total['records']['error']);
        $this->assertSame(0, $total['records']['file_lines'], 'detail=uncovered 时不输出 file_lines');

        // §11-5：删掉最后一行就没有 total，消费方能判定"不完整"
        $truncated = implode("\n", array_slice($lines, 0, count($lines) - 1)) . "\n";
        $this->assertStringNotContainsString('"t":"total"', $truncated);
        $this->assertSame(1, substr_count($jsonl, '"t":"total"'));

        // 记录顺序：meta -> group -> dir -> file/file_func -> ignored -> error -> total
        $this->assertSame(0, $first_of($types, 'meta'));
        $this->assertSame(count($types) - 1, $first_of($types, 'total'));
        $this->assertLessThan($first_of($types, 'file'), $first_of($types, 'dir'), 'dir 必须在 file 之前');
        $this->assertLessThan($first_of($types, 'file_func'), $first_of($types, 'file'));
        $this->assertLessThan($first_of($types, 'ignored'), $first_of($types, 'file'));
        $this->assertLessThan($first_of($types, 'error'), $first_of($types, 'ignored'));

        // group 记录：顺序 = meta.groups，dumps 取自 group_dumps(没传的按 0)
        $group_records = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'group') {
                $group_records[$record['name']] = $record;
            }
        }
        $this->assertSame(['g1', 'g2'], array_keys($group_records));
        $this->assertSame(['t', 'name', 'dumps'], array_keys($group_records['g1']));
        $this->assertSame(7, $group_records['g1']['dumps']);
        $this->assertSame(0, $group_records['g2']['dumps']);

        // dir 记录：只有 executable/executed，没有百分比，也没有 funcs
        $dir_records = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'dir') {
                $dir_records[$record['path']] = $record;
            }
        }
        $this->assertSame(['src', 'src/Inner'], array_keys($dir_records));
        $this->assertSame(['t', 'path', 'files', 'lines'], array_keys($dir_records['src']));
        $this->assertSame(5, $dir_records['src']['files']);
        $this->assertSame(1, $dir_records['src/Inner']['files']);
        $this->assertSame(['executable', 'executed'], array_keys($dir_records['src']['lines']));
        $this->assertGreaterThan(0, $dir_records['src']['lines']['executable']);
        $this->assertArrayNotHasKey('percent', $dir_records['src']['lines']);

        // file 记录：完整相对路径、不输出百分比、没有 group(全组合并后的数据)
        $jsonl_files = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'file') {
                $jsonl_files[$record['path']] = $record;
            }
        }
        $this->assertSame(
            ['src/AbstractFixture.php', 'src/App.php', 'src/Covered.php', 'src/Inner/Deep.php', 'src/Never.php', 'src/Trait.php'],
            array_keys($jsonl_files)
        );
        foreach ($jsonl_files as $path => $record) {
            // §11-6：都是相对根的完整路径，绝不是 basename
            $this->assertStringContainsString('/', $path);
            $this->assertNotSame(basename($path), $path);
            $this->assertArrayNotHasKey('group', $record);
            $this->assertArrayNotHasKey('percent', $record['lines']);
            $this->assertSame(
                ['t', 'path', 'dir', 'app', 'sha1', 'loaded', 'ignored', 'lines', 'funcs', 'unc', 'todo', 'sig'],
                array_keys($record)
            );
            // todo = unc - sig：真正还能补的行（签名行扣掉之后）
            $this->assertSame(array_values(array_diff($record['unc'], $record['sig'])), $record['todo']);
            $this->assertSame(['executable', 'executed'], array_keys($record['lines']));
            $this->assertSame(['total', 'covered'], array_keys($record['funcs']));
            $this->assertFalse($record['ignored']);
        }
        $this->assertSame('src/Inner', $jsonl_files['src/Inner/Deep.php']['dir']);
        $this->assertSame('Inner', $jsonl_files['src/Inner/Deep.php']['app']);
        $this->assertSame($file_records['src/App.php']['sha1'], $jsonl_files['src/App.php']['sha1']);

        // §11-13：未加载的文件 loaded=false、executed=0、executable>0
        $never = $jsonl_files['src/Never.php'];
        $this->assertFalse($never['loaded']);
        $this->assertSame(0, $never['lines']['executed']);
        $this->assertGreaterThan(0, $never['lines']['executable']);
        $this->assertTrue($jsonl_files['src/App.php']['loaded']);

        // §11-12：被忽略的文件只出现在 ignored 里
        $ignored_records = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'ignored') {
                $ignored_records[$record['path']] = $record;
            }
        }
        $this->assertSame(['src/Ignored.php', 'src/OnlyComments.php'], array_keys($ignored_records));
        $this->assertArrayNotHasKey('src/Ignored.php', $jsonl_files);
        $this->assertArrayNotHasKey('src/OnlyComments.php', $jsonl_files);
        $this->assertSame(['t', 'path', 'reason'], array_keys($ignored_records['src/Ignored.php']));
        $this->assertNotSame('', $ignored_records['src/Ignored.php']['reason']);

        // file_func：方法级定位，name 用 Class::method，按 start 升序
        $app_funcs = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'file_func' && $record['path'] === 'src/App.php') {
                $app_funcs[] = $record;
            }
        }
        $this->assertSame(
            ['helper', 'App::run', 'App::never', 'App::multi', 'App::unused', 'App::dead', 'App::interp'],
            array_column($app_funcs, 'name')
        );
        $this->assertSame(['t', 'path', 'name', 'start', 'end', 'lines'], array_keys($app_funcs[0]));
        $starts = array_column($app_funcs, 'start');
        $sorted_starts = $starts;
        sort($sorted_starts, SORT_NUMERIC);
        $this->assertSame($sorted_starts, $starts, 'file_func 按 start 升序');
        $this->assertSame($this->lineIn($app_file, 'function helper()'), $app_funcs[0]['start']);
        $this->assertSame($this->lineIn($app_file, 'public function multi('), $app_funcs[3]['start']);
        $this->assertGreaterThan($app_funcs[3]['start'], $app_funcs[3]['end']);
        $unit_lines = [];
        foreach ($app_funcs as $record) {
            $unit_lines[$record['name']] = $record['lines'];
        }
        $this->assertSame(['executable' => 1, 'executed' => 1], $unit_lines['helper']);
        $this->assertSame(['executable' => 1, 'executed' => 1], $unit_lines['App::run']);
        $this->assertSame(['executable' => 1, 'executed' => 0], $unit_lines['App::never']);
        // multi()：签名行 + 方法体，只有方法体命中
        $this->assertSame(['executable' => 2, 'executed' => 1], $unit_lines['App::multi']);
        // unused()：签名行(默认值) + if 条件 + 两个 return，全都没命中
        $this->assertSame(['executable' => 4, 'executed' => 0], $unit_lines['App::unused']);
        $this->assertSame(['executable' => 0, 'executed' => 0], $unit_lines['App::dead']);
        $this->assertSame(['executable' => 1, 'executed' => 1], $unit_lines['App::interp']);
        $this->assertLessThan($first_of($types, 'ignored'), $first_of($types, 'file_func'));

        // error：按传入顺序，group 可选
        $error_records = [];
        foreach ($decoded as $record) {
            if ($record['t'] === 'error') {
                $error_records[] = $record;
            }
        }
        $this->assertSame(
            [
                ['t' => 'error', 'group' => 'g2', 'msg' => 'group skipped: provider not selectable', 'fatal' => true],
                ['t' => 'error', 'msg' => 'non fatal trouble', 'fatal' => false],
            ],
            $error_records
        );

        // §5.4 签名行：多行签名里的默认值行被静态分析算成可执行，驱动永远不会执行它
        $app = $file_records['src/App.php'];
        $this->assertSame([$line_multi_sig], $app['sig']);
        $this->assertSame($app['sig'], $jsonl_files['src/App.php']['sig']);
        $this->assertNotContains($line_multi_body, $app['sig']);
        // 整个方法从没被调用过时不能标签名行(否则会把"没测到"说成"测不到")
        $this->assertNotContains($line_unused_sig, $app['sig']);
        $this->assertNotContains($line_never, $app['sig']);
        $this->assertContains($line_multi_sig, $app['uncovered_lines']);
        // multi() 方法体那一行是命中的，而文件仍不是 100%：差额正是签名行
        $this->assertSame(1, $app['line_map'][(string)$line_multi_body]);
        $this->assertSame(-1, $app['line_map'][(string)$line_multi_sig]);
        $this->assertArrayHasKey('sig', $report['definitions']);

        // §5.1 + §11-7：detail=full 时 unc == map 中 -1 的键集合，升序；-2(dead code)保留在 map 里但不进 unc
        $full_jsonl = $jsonl_report->render($report, ['detail' => 'full', 'timestamp' => false]);
        $full_lines = explode("\n", $full_jsonl);
        $this->assertSame('', array_pop($full_lines));
        $unc_of = [];
        $map_of = [];
        $chunks_of = [];
        foreach ($full_lines as $line) {
            $record = json_decode($line, true);
            if ($record['t'] === 'file') {
                $unc_of[$record['path']] = $record['unc'];
            }
            if ($record['t'] === 'file_lines') {
                $this->assertSame(['t', 'path', 'chunk', 'chunks', 'map'], array_keys($record));
                $this->assertSame(1, $record['chunk']);
                $this->assertSame(1, $record['chunks'], '默认上限下不该分片');
                $map_of[$record['path']] = $record['map'];
            }
        }
        $this->assertSame(array_keys($jsonl_files), array_keys($map_of));
        foreach ($unc_of as $path => $unc) {
            $sorted = $unc;
            sort($sorted, SORT_NUMERIC);
            $this->assertSame($sorted, $unc, $path . ' 的 unc 必须升序');
            $expected = [];
            foreach ($map_of[$path] as $line => $value) {
                if ($value === -1) {
                    $expected[] = (int)$line;
                }
            }
            $this->assertSame($expected, $unc, $path . ' 的 unc 必须等于 map 里 -1 的行号集合');
        }
        // -2(dead code)保留在 map 里，但不进 unc
        $this->assertSame(-2, $map_of['src/App.php'][(string)$line_dead]);
        $this->assertNotContains($line_dead, $unc_of['src/App.php']);
        $this->assertSame(1, $map_of['src/App.php'][(string)$line_multi_body]);
        $this->assertSame(-1, $map_of['src/App.php'][(string)$line_unused_body]);

        // §5：detail=none 只有汇总数字，没有 unc、没有 file_lines
        $none_jsonl = $jsonl_report->render($report, ['detail' => 'none', 'timestamp' => false]);
        $none_lines = explode("\n", $none_jsonl);
        $this->assertSame('', array_pop($none_lines));
        foreach ($none_lines as $line) {
            $record = json_decode($line, true);
            $this->assertNotSame('file_lines', $record['t']);
            if ($record['t'] === 'file') {
                $this->assertArrayNotHasKey('unc', $record);
                $this->assertArrayHasKey('sig', $record);
            }
        }
        $this->assertSame(0, json_decode($none_lines[count($none_lines) - 1], true)['records']['file_lines']);

        // detail 非法值按缺省(uncovered)处理
        $bogus = $jsonl_report->render($report, ['detail' => 'bogus', 'timestamp' => false]);
        $this->assertStringContainsString('"detail":"uncovered"', $bogus);
        $this->assertStringContainsString('"unc":', $bogus);

        // §11-10：空数据也要 meta + total(files:0)，不输出空文件
        $empty_coverage = new CodeCoverage(new CoverageJsonlReportDriver(), new Filter());
        $empty_report = CoverageJsonReport::_()->render($empty_coverage, [
            'groups' => [],
            'dumps_merged' => 0,
            'path_src' => $root . 'src/',
            'path_root' => $root,
            'loaded_files' => [],
            'files' => [],
            'driver_class' => '',
        ]);
        $empty_lines = explode("\n", $jsonl_report->render($empty_report, ['timestamp' => false]));
        $this->assertSame('', array_pop($empty_lines));
        $this->assertCount(2, $empty_lines);
        $this->assertSame('meta', json_decode($empty_lines[0], true)['t']);
        $empty_total = json_decode($empty_lines[1], true);
        $this->assertSame('total', $empty_total['t']);
        $this->assertSame(0, $empty_total['files']);
        $this->assertTrue($empty_total['complete']);
        $this->assertSame(2, array_sum($empty_total['records']));
        $this->assertSame(0, $empty_total['records']['file']);

        // 防御性契约：file 的 line_map 为空时也要输出 {} 而不是 []，unc/sig 空数组也要在
        $synthetic = [
            'schema' => 'duckcoverage-report/1',
            'generated_at' => '2026-10-04T12:34:56+00:00',
            'generator' => ['name' => 'duckcoverage', 'version' => '1.0.1', 'php' => '8.2.32', 'coverage_driver' => 'xdebug-3.2.0'],
            'root' => 'src/',
            'groups' => [],
            'dumps_merged' => 0,
            'totals' => ['files' => 1, 'lines' => ['executable' => 1, 'executed' => 0, 'percent' => 0.0], 'functions' => ['total' => 0, 'covered' => 0, 'percent' => 0.0]],
            'directories' => [],
            'files' => [[
                'path' => 'src/EmptyMap.php',
                'dir' => 'src',
                'app' => '',
                'sha1' => '',
                'loaded' => false,
                'lines' => ['executable' => 1, 'executed' => 0, 'percent' => 0.0],
                'functions' => ['total' => 0, 'covered' => 0, 'percent' => 0.0],
                'uncovered_lines' => [],
                'sig' => [],
                'function_items' => [],
                'line_map' => [],
            ]],
            'ignored_files' => [],
        ];
        $synthetic_jsonl = $jsonl_report->render($synthetic, ['detail' => 'full', 'timestamp' => false]);
        $this->assertStringContainsString('"map":{}', $synthetic_jsonl);
        $this->assertStringContainsString('"unc":[]', $synthetic_jsonl);
        $this->assertStringContainsString('"sig":[]', $synthetic_jsonl);

        // §5.3 分片：把上限改小(1MB 的路径在单测里跑不动)，验证按行号连续切片
        $chunked_report = new CoverageJsonlReportSmallLineLimit();
        $chunked_lines = explode("\n", $chunked_report->render($report, ['detail' => 'full', 'timestamp' => false]));
        $this->assertSame('', array_pop($chunked_lines));
        $chunk_records = [];
        $chunk_order = [];
        foreach ($chunked_lines as $line) {
            $record = json_decode($line, true);
            if ($record['t'] !== 'file_lines') {
                continue;
            }
            $chunk_order[] = $record['path'];
            $chunk_records[$record['path']][] = $record;
            $this->assertLessThanOrEqual(
                CoverageJsonlReportSmallLineLimit::MAX_LINE_BYTES,
                strlen($line),
                '分片后的每行都不能超过上限'
            );
        }
        $this->assertSame(['src/AbstractFixture.php', 'src/App.php', 'src/Covered.php', 'src/Inner/Deep.php', 'src/Never.php', 'src/Trait.php'], array_keys($chunk_records));
        // 同一 path 的分片必须连续输出
        $this->assertSame(array_keys($chunk_records), array_values(array_unique($chunk_order)));
        // App.php 的 map 够大，必须被切成多片
        $this->assertGreaterThan(1, count($chunk_records['src/App.php']));
        foreach ($chunk_records as $path => $records) {
            $this->assertSame(count($records), $records[0]['chunks']);
            $merged = [];
            foreach ($records as $index => $record) {
                $this->assertSame($index + 1, $record['chunk'], 'chunk 从 1 开始且连续');
                $this->assertSame($records[0]['chunks'], $record['chunks']);
                $merged += $record['map'];
            }
            ksort($merged, SORT_NUMERIC);
            $this->assertSame($map_of[$path], $merged, $path . ' 的分片拼起来必须等于整份 map');
        }

        // write()：目录不存在时自动创建，返回写出的路径，内容一定 LF 结尾
        $target = $root . 'report/deep/jsonl/report.jsonl';
        @unlink($target);
        $this->assertSame($target, $jsonl_report->write($jsonl, $target));
        $this->assertFileExists($target);
        $this->assertSame($jsonl, file_get_contents($target));
        $loose = $root . 'report/loose.jsonl';
        $this->assertSame($loose, $jsonl_report->write("a\r\nb", $loose));
        $this->assertSame("a\nb\n", file_get_contents($loose));

        // 写失败必须抛 \RuntimeException，消息里带路径(调用方负责非零退出)
        $blocked = $root . 'report/blocked';
        @unlink($blocked);
        file_put_contents($blocked, 'not a directory');
        try {
            $jsonl_report->write($jsonl, $blocked . '/report.jsonl');
            $this->fail('目录建不出来时应当抛 RuntimeException');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString($blocked, $ex->getMessage());
        }
        $as_dir = $root . 'report/as_dir.jsonl';
        @unlink($as_dir);
        @mkdir($as_dir, 0777, true);
        try {
            $jsonl_report->write($jsonl, $as_dir);
            $this->fail('写不进去时应当抛 RuntimeException');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString($as_dir, $ex->getMessage());
        }

        LibCoverage::_($old);
        LibCoverage::End();
    }

    /**
     * 第几个匹配的 needle 所在行号(1 起)；找不到返回 0
     */
    protected function lineIn(string $file, string $needle, int $occurrence = 1): int
    {
        $found = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
            if (strpos($text, $needle) === false) {
                continue;
            }
            $found++;
            if ($found === $occurrence) {
                return $index + 1;
            }
        }
        return 0;
    }
}

/**
 * 假 Driver：只为了能构造 CodeCoverage，本测试不经过采集流程
 */
class CoverageJsonlReportDriver extends Driver
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
 * 暴露 protected 的补坑步骤(其余逻辑已抽到 CoverageJsonReport/CoverageJsonlReport)
 */
class CoverageJsonlReportGroupCoverage extends GroupCoverage
{
    public function fillPartialCoveredFilesForTest(CodeCoverage $coverage): void
    {
        $this->fillPartialCoveredFiles($coverage);
    }
}

/**
 * 把 1MB 的分片上限改小，用来验证 §5.3 的分片逻辑
 */
class CoverageJsonlReportSmallLineLimit extends CoverageJsonlReport
{
    public const MAX_LINE_BYTES = 120;
}
