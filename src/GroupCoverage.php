<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector as CodeCoverageSelector;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as ReportOfHtmlOfFacade;
use SebastianBergmann\CodeCoverage\Report\PHP as ReportOfPHP;

/**
 * 封装 php-code-coverage 的全部直接依赖(创建/采集/dump/合并/报告)。
 * 组件风格:_() 单例 + init() 初始化。
 * 按组(group)驱动覆盖率工作流:begin() 采集 -> end() 停止并 dump 到组目录 -> createReport()/showAllReport() 按组合并出报告。
 * 对外只暴露合并后的方法,避免调用方零散接触底层 API。
 *
 * @phpstan-consistent-constructor  _() 里会用 new static() 实例化子类，
 * 因此要求子类与本类一样可以无参构造(否则请用 __SINGLETONEX_REPALACER 替换实例)。
 */
class GroupCoverage
{
    public $options = [
    ];
    public $is_inited = false;

    protected $coverage;
    protected $current_path_dump = '';
    protected $current_name = '';
    protected $current_group = '';

    protected $is_end = false;

    protected $driver_class = '';

    protected static $_instances = [];

    //embed
    /**
     * @return static
     */
    public static function _($object = null)
    {
        if (defined('__SINGLETONEX_REPALACER')) {
            $callback = __SINGLETONEX_REPALACER;
            return ($callback)(static::class, $object);
        }
        if ($object) {
            self::$_instances[static::class] = $object;
            return $object;
        }
        $me = self::$_instances[static::class] ?? null;
        if (null === $me) {
            $me = new static();
            self::$_instances[static::class] = $me;
        }
        return $me;
    }
    public function __construct()
    {
    }

    /**
     *
     * @param array<string,mixed> $options
     * @param ?object $context
     * @return static
     */
    public function init(array $options, ?object $context = null)
    {
        //$this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
        $this->coverage = $this->createCoverage();
        $this->is_inited = true;
        return $this;
    }
    public function getCoverage()
    {
        return $this->coverage;
    }
    /**
     * 开始采集：懒创建 coverage + 收录源码目录(options['path_src']) + start（内部防重入）。
     * 测试名与组名由参数传入（组名为空时回落到 options['group']），供 doEnd() 无参 dump 使用。
     */
    public function doBegin(string $name, string $group, string $path_src, string $path_dump): void
    {
        $this->is_end = false;
        $this->pre_begin($name, $group, $path_src, $path_dump);    // @codeCoverageIgnore
        if (class_exists(\LibCoverage\LibCoverage::class)) {
            \LibCoverage\LibCoverage::_()->doPause();   // @codeCoverageIgnore
        }
        $this->coverage->start($name);      // @codeCoverageIgnore
    }
    protected function pre_begin(string $name, string $group, string $path_src, string $path_dump): void
    {
        if (!$this->coverage) {
            $this->coverage = $this->createCoverage(); // @codeCoverageIgnore
        }
        $this->current_path_dump = $path_dump;
        $this->current_name = $name;
        $this->current_group = $group;

        $this->includePath($this->coverage, $path_src);
    }
    /**
     * 结束采集并 dump：stop + Report\PHP 序列化到 {path_dump}/{group}/{md5(name)}.php。
     * 路径/组/名来自 doBegin() 捕获的状态与 options['path_dump']。
     */
    public function doEnd(): void
    {
        if ($this->is_end) {
            return;
        }
        $this->coverage->stop();        // @codeCoverageIgnore
        if (class_exists(\LibCoverage\LibCoverage::class)) { // @codeCoverageIgnore
            \LibCoverage\LibCoverage::_()->doResume();   // @codeCoverageIgnore
        }
        $this->post_end();
    }
    protected function post_end()
    {
        $path_dump = $this->current_path_dump. $this->current_group;
        @mkdir($path_dump, 0777, true);
        @chmod($path_dump, 0777);
        $file = (string)  $path_dump. DIRECTORY_SEPARATOR . $this->make_filename($this->current_name);
        (new ReportOfPHP)->process($this->coverage, $file);
        $ext = "\n// ".$this->current_name ."\n";
        file_put_contents($file, $ext, FILE_APPEND);
        $perms = fileperms($file);
        chmod($file, $perms | 0002);

        $this->is_end = true;
    }
    protected function make_filename($name)
    {
        return (new \DateTime())->format('Y-m-d_H_i_s_v').'-'.\sha1($name).'.php';
    }
    ////////////////////////////////////////////////////////////////////////////
    /**
     * 生成报告：新建 coverage 收录源码 -> 按组合并 dump -> 补全部分覆盖文件 -> 渲染 HTML、输出 JSON 并统计。
     *
     * $path_root 是工程根，用来把 JSON 里的路径写成"相对工程根"的形式；为空或源码不在其下时，
     * JSON 路径相对源码根，root 记为 '.'。
     *
     * $jsonl 非空时额外输出 JSONL 报告（键：path/stdout/detail/timestamp，见 CoverageJsonlReport）：
     * path 为空表示不写文件，stdout=true 表示由调用方把文本写到 stdout。
     *
     * @param array<string>  $groups
     * @param array<string,mixed> $jsonl
     * @return array{lines_tested:int, lines_total:int, lines_percent:string, json_report:string, dumps_merged:int}
     */
    public function createReport(array $groups, string $path_src, string $path_dump, string $path_report, string $path_root = '', array $jsonl = []): array
    {
        $coverage = $this->createCoverage();
        $this->includePath($coverage, $path_src);
        $coverage->setTests([
          'T' => [
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        $dumps_merged = 0;
        $group_dumps = [];
        $errors = [];
        foreach ($groups as $group) {
            $dir = $path_dump.$group;
            $merged = $this->mergeFromDir($coverage, $dir);
            $group_dumps[$group] = $merged;
            $dumps_merged += $merged;
            if ($merged === 0) {
                // 规格 §4.8：组没有 dump、或组目录不存在，都不要静默吞掉
                $errors[] = [
                    'group' => $group,
                    'msg' => is_dir($dir) ? "group skipped: no dump in {$group}" : "group skipped: dump dir not found: {$group}",
                    'fatal' => false,
                ];
            }
        }
        // 补坑之前先记下"驱动报告过的文件"＝运行时真的被加载过(JSON 的 loaded 字段)。
        // 必须取原始数据：getData() 默认会把"只在 filter 里、从未加载"的文件也补进来。
        $loaded_files = array_keys($coverage->getData(true)->lineCoverage());

        // 补全部分覆盖文件：未执行的可执行行加入 lineCoverage（空数组），
        // 否则报告只统计已执行行，部分覆盖文件会错误显示为 100%
        $sig_lines = $this->fillPartialCoveredFiles($coverage);
        $stats = $this->renderReport($coverage, $path_report);
        $report = CoverageJsonReport::_()->render($coverage, [
            'groups' => $groups,
            'dumps_merged' => $dumps_merged,
            'path_src' => $path_src,
            'path_root' => $path_root,
            'loaded_files' => $loaded_files,
            'files' => $this->sourceFiles($path_src),
            'driver_class' => $this->driver_class,
            'sig_lines' => $sig_lines,
        ]);
        $stats['json_report'] = CoverageJsonReport::_()->write($report, $path_report);
        $stats['dumps_merged'] = $dumps_merged;
        if ($jsonl) {
            // JSONL 是同一份数据的行导向输出：只做变换，不再碰 php-code-coverage
            $jsonl_report = CoverageJsonlReport::_();
            $text = $jsonl_report->render($report, $jsonl + ['group_dumps' => $group_dumps, 'errors' => $errors]);
            if (!empty($jsonl['path'])) {
                $stats['jsonl_report'] = $jsonl_report->write($text, (string)$jsonl['path']);
            }
            if (!empty($jsonl['stdout'])) {
                $stats['jsonl_text'] = $text;
            }
        }
        return $stats;
    }
    /**
     * 创建 CodeCoverage。php-code-coverage 9.x 起必须显式传入 Driver + Filter
     */
    protected function createCoverage(): CodeCoverage
    {
        $filter = new CodeCoverageFilter();
        $driver = (new CodeCoverageSelector())->forLineCoverage($filter);
        $this->driver_class = get_class($driver);
        return new CodeCoverage($driver, $filter);
    }
    /**
     * 把目录或文件加入 filter。9.x 用 includeDirectory;11.x 已移除,需展开目录(只收录 .php)
     */
    protected function includePath(CodeCoverage $coverage, string $path): void
    {
        $coverage->filter()->includeFiles($this->sourceFiles($path));
    }
    /**
     * 源码目录下所有 .php 文件(绝对路径)。排序后再用，保证 HTML/JSON 报告都是确定性的。
     *
     * @return array<int, string>
     */
    protected function sourceFiles(string $path): array
    {
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = [];
        foreach ($iterator as $file) {
            if (is_file($file) && substr($file, -4) === '.php') {
                $files[] = $file;
            }
        }
        sort($files);
        return $files;
    }
    /**
     * 合并一个组目录下的所有 dump
     *
     * @return int 合并的 dump 个数
     */
    protected function mergeFromDir(CodeCoverage $coverage, string $dir): int
    {
        if (!is_dir($dir)) {
            return 0; // @codeCoverageIgnore
        }
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = \iterator_to_array($iterator, false);
        $count = 0;
        foreach ($files as $file) {
            $t = include $file;
            $coverage->merge($t);
            $count++;
        }
        return $count;
    }
    /**
     * 补全部分覆盖文件：把 filter 内已有覆盖数据的文件的可执行行补进 lineCoverage（未执行的为空数组）。
     * php-code-coverage 9.x 只对"完全未覆盖"文件补未执行行（addUncoveredFilesFromFilter），
     * 部分覆盖文件若缺失未执行行，报告会把该文件错误统计为 100%。
     * 修复：使用 ParsingFileAnalyser(true, true) 来正确处理 @codeCoverageIgnore 注解
     *       对于被忽略的行，不填充空数组，让它们保持未填充状态
     */
    protected function fillPartialCoveredFiles(CodeCoverage $coverage): array
    {
        $analyser = new \SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser(true, true);
        $lineCoverage = $coverage->getData()->lineCoverage();
        $filter = $coverage->filter();

        foreach ($filter->files() as $file) {
            $executableLines = array_keys($analyser->executableLinesIn($file));
            $ignoredLines = $analyser->ignoredLinesFor($file);

            // 如果没有可执行行，跳过
            if (empty($executableLines)) {
                continue;
            }

            // 确保 lineCoverage 中有该文件的条目
            if (!isset($lineCoverage[$file])) {
                $lineCoverage[$file] = [];
            }

            // 只为非忽略的行填充空数组。
            // 这里用 array_key_exists 而不是 isset：值为 null 表示驱动判定为 dead code，
            // 不能覆盖成"可执行未执行"，否则 dead code 会被算进分母(文件永远到不了 100%)
            foreach ($executableLines as $line) {
                if (in_array($line, $ignoredLines, true)) {
                    continue;   // 跳过被忽略的行
                }
                if (!array_key_exists($line, $lineCoverage[$file])) {
                    $lineCoverage[$file][$line] = [];
                }
            }
        }

        // 补坑之后才算签名行（这时"函数体其余行是否都执行了"才判得准），
        // 然后把它们从 lineCoverage 里整个剔除：签名行被静态分析算可执行，
        // 但驱动永远不会把它标为执行，留着会让文件永远到不了 100%。
        // 剔除后 HTML / report.json / report.jsonl 三处口径一致。
        $sig_lines = [];
        foreach ($filter->files() as $file) {
            $lines = $lineCoverage[$file] ?? [];
            if (!$lines) {
                continue;
            }
            $sig = CoverageJsonReport::_()->signatureLines($file, $lines);
            if (!$sig) {
                continue;
            }
            foreach ($sig as $line) {
                unset($lineCoverage[$file][$line]);
            }
            $sig_lines[$file] = $sig;
        }

        $coverage->getData()->setLineCoverage($lineCoverage);
        return $sig_lines;
    }
    /**
     * 渲染 HTML 报告并返回行统计
     *
     * @return array{lines_tested:int, lines_total:int, lines_percent:string}
     */
    protected function renderReport(CodeCoverage $coverage, string $path_report): array
    {
        (new ReportOfHtmlOfFacade)->process($coverage, $path_report);
        $report = $coverage->getReport();
        $lines_tested = $report->numberOfExecutedLines();
        $lines_total = $report->numberOfExecutableLines();
        $lines_percent = $lines_total > 0 ? sprintf('%0.2f%%', $lines_tested / $lines_total * 100) : '0.00%';
        return [
            'lines_tested' => $lines_tested,
            'lines_total' => $lines_total,
            'lines_percent' => $lines_percent,
        ];
    }
}
