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
 * 对外只暴露合并后的方法(begin/endAndDump/createReport),避免调用方零散接触底层 API。
 */
class CodeCoverageHelper
{
    public $options = [
        'path_src' => 'src/',
        'path_dump' => 'test_coveragedumps',
        'path_report' => 'test_reports',
        'group' => '',
        'name' => '',
    ];
    public $is_inited = false;

    protected $coverage;
    protected $is_begin = false;

    protected static $_instances = [];
    //embed
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
    public function init(array $options, ?object $context = null)
    {
        $this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
        try {
            $this->coverage = $this->createCoverage();
        } catch (\Throwable $e) {
            // 无覆盖驱动(xdebug/pcov)时延迟到 begin 再创建，保证 CLI 命令可用
            $this->coverage = null;
        }
        $this->is_inited = true;
        return $this;
    }
    public function getCoverage()
    {
        return $this->coverage;
    }
    /**
     * 开始采集：懒创建 coverage + 收录源码目录 + start（内部防重入）
     */
    public function begin(string $name, string $path_src): void
    {
        if ($this->is_begin) {
            return; // 防止重复调用
        }
        if (!$this->coverage) {
            $this->coverage = $this->createCoverage();
        }
        static::includePath($this->coverage, $path_src);
        // php-code-coverage 9.x:start($id,$append=true);11.x:start($id,?TestSize $size=null)。不传第二参数两版兼容
        $this->coverage->start($name);
        $this->is_begin = true;
    }
    /**
     * 结束采集并 dump：stop + Report\PHP 序列化到 {path_dump}/{group}/{md5(name)}.php
     */
    public function endAndDump(string $path_dump, string $group, string $name): void
    {
        if (!$this->is_begin) {
            return; // 防止重复调用
        }
        $this->coverage->stop();
        $file = $path_dump . $group . '/' . md5($name) . '.php';
        (new ReportOfPHP)->process($this->coverage, $file);
        $this->is_begin = false;
    }
    /**
     * 生成报告：新建 coverage 收录源码 -> 合并各组 dump -> 补全部分覆盖文件 -> 渲染 HTML 并统计。
     * $before_render 回调在渲染前调用（参数为合并后的 coverage），供外层 hook（如 onBeforeReport）使用。
     *
     * @return array{lines_tested:int, lines_total:int, lines_percent:string}
     */
    public function createReport(string $path_src, array $groups, string $path_dump, string $path_report, ?callable $before_render = null): array
    {
        $coverage = $this->createCoverage();
        static::includePath($coverage, $path_src);
        $coverage->setTests([
          'T' => [
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        foreach ($groups as $group) {
            static::mergeFromDir($coverage, $path_dump . $group);
        }
        // 补全部分覆盖文件：未执行的可执行行加入 lineCoverage（空数组），
        // 否则报告只统计已执行行，部分覆盖文件会错误显示为 100%
        static::fillPartialCoveredFiles($coverage);
        if ($before_render) {
            $before_render($coverage);
        }
        return static::renderReport($coverage, $path_report);
    }
    /////////////////////////////
    // 以下为内部实现（兼容 php-code-coverage 9.x / 11.x）
    /////////////////////////////
    /**
     * 创建 CodeCoverage。php-code-coverage 9.x 起必须显式传入 Driver + Filter
     */
    protected static function createCoverage(): CodeCoverage
    {
        $filter = new CodeCoverageFilter();
        $driver = (new CodeCoverageSelector())->forLineCoverage($filter);
        return new CodeCoverage($driver, $filter);
    }
    /**
     * 把目录或文件加入 filter。9.x 用 includeDirectory;11.x 已移除,需展开目录(只收录 .php)
     */
    protected static function includePath(CodeCoverage $coverage, string $path): void
    {
        $filter = $coverage->filter();
        if (method_exists($filter, 'includeDirectory')) {
            $filter->includeDirectory($path); // 9.x 只收录 .php 文件 // @codeCoverageIgnore
            return; // @codeCoverageIgnore
        }
        if (is_file($path)) {
            if (substr($path, -4) === '.php') {
                $filter->includeFile($path);
            }
            return;
        }
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = [];
        foreach ($iterator as $file) {
            if (is_file($file) && substr($file, -4) === '.php') {
                $files[] = $file;
            }
        }
        $filter->includeFiles($files);
    }
    /**
     * 合并目录下所有 dump 文件到 coverage
     */
    protected static function mergeFromDir(CodeCoverage $coverage, string $dir): void
    {
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = \iterator_to_array($iterator, false);
        foreach ($files as $file) {
            // 要重复两遍才能 100% ，所以 ignore 得了，使用 include 会导致一个 Bug 。
            $t = include $file;    //@codeCoverageIgnore
            $coverage->merge($t);   //@codeCoverageIgnore
        }
    }
    /**
     * 补全部分覆盖文件：把 filter 内已有覆盖数据的文件的可执行行补进 lineCoverage（未执行的为空数组）。
     * php-code-coverage 9.x 只对"完全未覆盖"文件补未执行行（addUncoveredFilesFromFilter），
     * 部分覆盖文件若缺失未执行行，报告会把该文件错误统计为 100%。
     */
    protected static function fillPartialCoveredFiles(CodeCoverage $coverage): void
    {
        $analyser = new \SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser(true, false);
        $lineCoverage = $coverage->getData()->lineCoverage();
        foreach ($coverage->filter()->files() as $file) {
            if (!isset($lineCoverage[$file])) {
                continue; // 完全未覆盖文件由框架的 addUncoveredFilesFromFilter 处理
            }
            foreach (array_keys($analyser->executableLinesIn($file)) as $line) {
                if (!isset($lineCoverage[$file][$line])) {
                    $lineCoverage[$file][$line] = [];
                }
            }
        }
        $coverage->getData()->setLineCoverage($lineCoverage);
    }
    /**
     * 渲染 HTML 报告并返回行统计
     *
     * @return array{lines_tested:int, lines_total:int, lines_percent:string}
     */
    protected static function renderReport(CodeCoverage $coverage, string $path_report): array
    {
        (new ReportOfHtmlOfFacade)->process($coverage, $path_report);
        $report = $coverage->getReport();
        $lines_tested = $report->numberOfExecutedLines();
        $lines_total = $report->numberOfExecutableLines();
        $lines_percent = sprintf('%0.2f%%', $lines_tested / $lines_total * 100);
        return [
            'lines_tested' => $lines_tested,
            'lines_total' => $lines_total,
            'lines_percent' => $lines_percent,
        ];
    }
}
