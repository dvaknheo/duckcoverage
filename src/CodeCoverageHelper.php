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
 * CoverageBase 只做流程编排,不直接触碰 CodeCoverage API。
 */
class CodeCoverageHelper
{
    /**
     * 创建 CodeCoverage。php-code-coverage 9.x 起必须显式传入 Driver + Filter
     */
    public static function create(): CodeCoverage
    {
        $filter = new CodeCoverageFilter();
        $driver = (new CodeCoverageSelector())->forLineCoverage($filter);
        return new CodeCoverage($driver, $filter);
    }
    /**
     * 把目录或文件加入 filter。9.x 用 includeDirectory;11.x 已移除,需展开目录(只收录 .php)
     */
    public static function includePath(CodeCoverage $coverage, string $path): void
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
     * 开始采集。9.x:start($id,$append=true);11.x:start($id,?TestSize $size=null)。不传第二参数两版兼容
     */
    public static function begin(CodeCoverage $coverage, string $name): void
    {
        $coverage->start($name);
    }
    /**
     * 结束采集
     */
    public static function end(CodeCoverage $coverage): void
    {
        $coverage->stop();
    }
    /**
     * 把当前采集结果序列化为 dump 文件(Report\PHP)
     */
    public static function dump(CodeCoverage $coverage, string $file): void
    {
        (new ReportOfPHP)->process($coverage, $file);
    }
    /**
     * 合并目录下所有 dump 文件到 coverage
     */
    public static function mergeFromDir(CodeCoverage $coverage, string $dir): void
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
    public static function fillPartialCoveredFiles(CodeCoverage $coverage): void
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
    public static function renderReport(CodeCoverage $coverage, string $path_report): array
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
