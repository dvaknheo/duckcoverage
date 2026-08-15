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

class CoverageBase
{
    protected $coverage;

    public $options = [
        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/',
        'duckcoverage_path_dump' => 'test_coveragedumps',
        'duckcoverage_path_report' => 'test_reports',
        'duckcoverage_report_direct' => true,
        'duckcoverage_group'=>'',
        'duckcoverage_name'=>'',
    ];
    public $is_inited = false;
    
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
    public static function Begin()
    {
        return static::_()->doBegin();
    }
    public static function End()
    {
        return static::_()->doEnd();
    }
    protected static function IsAbsPath($path)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            //Linux
            if (substr($path, 0, 1) === '/') {
                return true;
            }
        } else { // @codeCoverageIgnoreStart
            // Windows
            if (preg_match('/^([a-zA-Z]:[\\\\\/]?|\\\\\\\\)/', $path)) {
            }
        }   // @codeCoverageIgnoreEnd
        return false;
    }
    protected static function SlashDir($path)
    {
        $path = ($path !== '') ? rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : '';
        return $path;
    }
    protected function getSubPath($path_key)
    {
        if (static::IsAbsPath($this->options[$path_key])) {
            return static::SlashDir($this->options[$path_key]);
        } else {
            return static::SlashDir($this->options['duckcoverage_path']) . static::SlashDir($this->options[$path_key]);
        }
    }
    public function init(array $options, ?object $context = null)
    {
        $this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
        
        try {
            $this->coverage = $this->createCoverage();
        } catch (\Throwable $e) {
            // 无覆盖驱动(xdebug/pcov)时延迟到 doBegin 再创建，保证 CLI 命令可用
            $this->coverage = null;
        }
        $this->is_inited = true;
        // auto start
        return $this;
    }
    /**
     * php-code-coverage 9.x: CodeCoverage 必须显式传入 Driver + Filter
     */
    /**
     * php-code-coverage 9.x 用 Filter::includeDirectory();11.x 已移除,需要把目录展开为文件列表
     */
    protected static function filterIncludePath($filter, $path)
    {
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
    protected function createCoverage(): CodeCoverage
    {
        $filter = new CodeCoverageFilter();
        $driver = (new CodeCoverageSelector())->forLineCoverage($filter);
        return new CodeCoverage($driver, $filter);
    }
    protected $is_begin = false;
    public function doBegin()
    {
        if ($this->is_begin) {
            return; // 防止重复调用
        }
        if (!$this->coverage) {
            $this->coverage = $this->createCoverage();
        }
        $path_src = $this->getSubPath('duckcoverage_path_src');
        static::filterIncludePath($this->coverage->filter(), $path_src);
        
        // php-code-coverage 9.x:start($id,$append=true);11.x:start($id,?TestSize $size=null)。不传第二参数两版兼容
        $this->coverage->start($this->options['duckcoverage_name']);
        $this->is_begin = true;
    }
    
    public function doEnd()
    {
        if (!$this->is_begin) {
            return; // 防止重复调用
        }
        $this->coverage->stop();
        $path_dump = $this->getSubPath('duckcoverage_path_dump');
        $path_dump = $path_dump. $this->options['duckcoverage_group'].'/';
        
        $file = md5($this->options['duckcoverage_name']);
        
        (new ReportOfPHP)->process($this->coverage, $path_dump.$file.'.php');
        //$this->coverage = null;
        $this->is_begin = false;
    }
    public function getCoverage()
    {
        return $this->coverage;
    }
    protected function getReportPath($groups)
    {
        $path_report = $this->getSubPath('duckcoverage_path_report');
        if(!($this->options['duckcoverage_report_direct'] ?? true)){
            if(empty($groups)){
                $groups =[$this->options['duckcoverage_group']];
            }
            if(count($groups)===1){
                $path_report = $path_report. $groups[0];
            }else{
                $path_report = $path_report. DATE('y-m-d');
            }
        }
        return $path_report;
    }
    protected $path_report = null;
    
    public function createReport($groups =[])
    {
        $path_src = $this->getSubPath('duckcoverage_path_src');
        $path_dump = $this->getSubPath('duckcoverage_path_dump');
        
        
        
        $path_report=$this->getReportPath($groups);
        $this->path_report = $path_report;
        $coverage = $this->createCoverage();
        static::filterIncludePath($coverage->filter(), $path_src);
        $coverage->setTests([
          'T' => [
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        
        foreach($groups as $group) {
            $current_path_dump = $path_dump. $group;
            $directory = new \RecursiveDirectoryIterator($current_path_dump, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);

            $iterator = new \RecursiveIteratorIterator($directory);
            $files = \iterator_to_array($iterator, false);
            foreach ($files as $file) {
                // 要重复两遍才能 100% ，所以 ignore 得了，使用 include 会导致一个 Bug 。
                $t = static::include_file($file);    //@codeCoverageIgnore
                $coverage->merge($t);   //@codeCoverageIgnore
            }
        }
        // 补全部分覆盖文件：未执行的可执行行加入 lineCoverage（空数组），
        // 否则报告只统计已执行行，部分覆盖文件会错误显示为 100%
        $this->fillPartialCoveredFiles($coverage);
        $this->coverage = $coverage;
        $this->onBeforeReport();
        (new ReportOfHtmlOfFacade)->process($this->coverage, $path_report);
        $report = $this->coverage->getReport();
        $lines_tested = $report->numberOfExecutedLines();
        $lines_total = $report->numberOfExecutableLines();
        $lines_percent = sprintf('%0.2f%%', $lines_tested / $lines_total * 100);
        
        $this->coverage = null; 
        return [
            'lines_tested' => $lines_tested,
            'lines_total' => $lines_total,
            'lines_percent' => $lines_percent,
        ];
    }
    protected function onBeforeReport()
    {
        //
    }
    /**
     * 补全部分覆盖文件：把 filter 内已有覆盖数据的文件的可执行行补进 lineCoverage（未执行的为空数组）。
     * php-code-coverage 9.x 只对"完全未覆盖"文件补未执行行（addUncoveredFilesFromFilter），
     * 部分覆盖文件若缺失未执行行，报告会把该文件错误统计为 100%。
     */
    protected function fillPartialCoveredFiles(\SebastianBergmann\CodeCoverage\CodeCoverage $coverage): void
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
    protected static function include_file($file)
    {
        return include $file;
    }
    ////[[[[
    protected function watchingBegin($name)
    {
        file_put_contents($this->options['duckcoverage_path'].'DuckCoverage.watching.txt',$name);
    }
    protected function watchingEnd()
    {
        @unlink($this->options['duckcoverage_path'].'DuckCoverage.watching.txt');
    }
    protected function watchingGetName()
    {
        $group = @file_get_contents($this->options['duckcoverage_path'].'DuckCoverage.watching.txt');
        return $group;    
    }
    ////]]]]
}