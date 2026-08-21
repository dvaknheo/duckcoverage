<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use LibCoverage\GroupCoverageRunner;

class CoverageBase
{
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
            $callback = __SINGLETONEX_REPALACER;            //@codeCoverageIgnore
            return ($callback)(static::class, $object);     //@codeCoverageIgnore
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
       return (bool) preg_match('#^(/|[a-zA-Z]:[\\\\/]|\\\\\\\\)#', (string)$path);
    }
    protected static function SlashDir($path)
    {
        $path = (string)$path;
        return $path !== '' ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR : '';
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
        $this->is_inited = true;
        return $this;
    }
    public function doBegin()
    {
        GroupCoverageRunner::_()->init([
            'path_src' => $this->getSubPath('duckcoverage_path_src'),
            'path_dump' => $this->getSubPath('duckcoverage_path_dump'),
        ])->doBegin(
            $this->options['duckcoverage_name'],
            $this->options['duckcoverage_group']
        );
    }
    
    public function doEnd()
    {
        GroupCoverageRunner::_()->doEnd();  //@codeCoverageIgnore
    }
    public function getCoverage()
    {
        return GroupCoverageRunner::_()->getCoverage();
    }
    protected function getReportPath($groups)
    {
        $path_report = $this->getSubPath('duckcoverage_path_report');
        if ($this->options['duckcoverage_report_direct']) {
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
        return GroupCoverageRunner::_()->createReport( $groups, $path_src, $path_dump, $path_report);
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