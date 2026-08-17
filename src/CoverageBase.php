<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

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
            $this->coverage = CodeCoverageHelper::create();
        } catch (\Throwable $e) {
            // 无覆盖驱动(xdebug/pcov)时延迟到 doBegin 再创建，保证 CLI 命令可用
            $this->coverage = null;
        }
        $this->is_inited = true;
        // auto start
        return $this;
    }
    protected $is_begin = false;
    public function doBegin()
    {
        if ($this->is_begin) {
            return; // 防止重复调用
        }
        if (!$this->coverage) {
            $this->coverage = CodeCoverageHelper::create();
        }
        $path_src = $this->getSubPath('duckcoverage_path_src');
        CodeCoverageHelper::includePath($this->coverage, $path_src);
        CodeCoverageHelper::begin($this->coverage, $this->options['duckcoverage_name']);
        $this->is_begin = true;
    }
    
    public function doEnd()
    {
        if (!$this->is_begin) {
            return; // 防止重复调用
        }
        CodeCoverageHelper::end($this->coverage);
        $path_dump = $this->getSubPath('duckcoverage_path_dump');
        $path_dump = $path_dump. $this->options['duckcoverage_group'].'/';
        
        $file = md5($this->options['duckcoverage_name']);
        
        CodeCoverageHelper::dump($this->coverage, $path_dump.$file.'.php');
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
        $coverage = CodeCoverageHelper::create();
        CodeCoverageHelper::includePath($coverage, $path_src);
        $coverage->setTests([
          'T' => [
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        
        foreach($groups as $group) {
            $current_path_dump = $path_dump. $group;
            CodeCoverageHelper::mergeFromDir($coverage, $current_path_dump);
        }
        // 补全部分覆盖文件：未执行的可执行行加入 lineCoverage（空数组），
        // 否则报告只统计已执行行，部分覆盖文件会错误显示为 100%
        CodeCoverageHelper::fillPartialCoveredFiles($coverage);
        $this->coverage = $coverage;
        $this->onBeforeReport();
        $ret = CodeCoverageHelper::renderReport($this->coverage, $path_report);
        
        $this->coverage = null; 
        return $ret;
    }
    protected function onBeforeReport()
    {
        //
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