<?php
namespace tests\DuckCoverage;

use DuckPhp\DuckPhp;
use DuckCoverage\DuckCoverage;
use DuckPhp\Core\Console;
use LibCoverage\LibCoverage;

class DuckCoverageTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $__SERVER = $_SERVER;
        $old = LibCoverage::_();
        $path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        LibCoverage::Begin(DuckCoverage::class);
        @mkdir($path);
        $this->makeData($path);
        DuckCoverage::_(DuckCoverageEx::_());

        include $path.'src/MyDuckCoverageApp.php';
        $options = [
            'path'=>$path,
            'duckcoverage_callback' =>[DuckCoverageTestList::class,'GetTestList']
        ];
        DuckCoverageApp::_(\MyDuckCoverageApp::_())->init($options);

        $this->cmd("duckcover --help");
        $this->cmd("duckcover --watch");
        $this->cmd("duckcover --replay");
        $this->cmd("duckcover --report");
        $this->cmd("duckcover --stop");

        DuckCoverage::_()->options['duckcoverage_report_direct'] = true;
        $this->cmd("duckcover --watch group1");
        $this->cmd("duckcover --replay group1");
        $this->cmd("duckcover --report group1 group2");
        $this->cmd("duckcover --report group1");
        $this->cmd("duckcover --go");

        DuckCoverageApp::_()->testMore();

        $__SERVER = $_SERVER;
        LibCoverage::_($old);
        //LibCoverage::_()->cleanDirectory($path);
        LibCoverage::End();
    }
    protected function cmd(string $str)
    {
        $my_argv = explode(' ', $str);
        array_unshift($my_argv, '-');
        $_SERVER['argv']=$my_argv;
        DuckCoverageApp::_()->run();
    }
    protected function makeData($path)
    {
        @mkdir($path.'src/');
        $str = <<<'EOT'
use DuckPhp\DuckPhp;
use DuckPhp\Core\Console;

class MyDuckCoverageApp extends tests\DuckCoverage\DuckCoverageApp
{
    public $options = [
        'is_debug' => true,
        'name'=> 'MyDuckCoverageApp',
        'cli_command_with_common' => true,
        'duckcoverage_enable'=>true,

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        $this->options['cmd'] = array_merge([static::class => true], $this->options['cmd']);
    }
    public function command_cmdback()
    {
        $data = Console::_()->getCliParameters();
        var_dump($data);
    }
    public static function Callback()
    {
        var_dump(DATE(DATE_ATOM));
        //$path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        //file_put_contents($path.'x.log',DATE(DATE_ATOM));
        return;
    }

}

EOT;
'EOT';
        file_put_contents($path.'src/MyDuckCoverageApp.php',"<"."?php declare(strict_types=1);
\n".$str);

    }
}
class DuckCoverageEx extends DuckCoverage
{
    public function readCommand($request)
    {
        return parent::readCommand($request);
    }
}
class DuckCoverageApp extends DuckPhp
{
    public $options =[
        'duckcoverage_enable'=>true,
    ];
    protected function onPrepare(): void
    {
        DuckCoverage::_()->beforeInit(); //mover  json data ;
        parent::onPrepare();
        // something from setting;
    }
    public function testMore()
    {
        $this->is_root = false;
        DuckCoverage::_()->init($this->options,null);
        $this->is_root = true;

        $this->options['duckcoverage_enable']=false;
        DuckCoverage::_()->options['duckcoverage_enable']=true;
        DuckCoverage::_()->init($this->options,null);
        $this->options['duckcoverage_enable']=true;

        $str=<<<EOT
#PHASE 
#CALL MyDuckCoverageApp::Callback
#CMD cmdback
#SETWEB _ _ _ _
#WEB /

EOT;
        $str1=<<<EOT
#CALL MyDuckCoverageApp::Callback
#WEB /

EOT;
        $cmds = explode("\n",$str);
        foreach($cmds as $cmd){
            DuckCoverageEx::_()->readCommand($cmd);
        }
    }
}
class DuckCoverageTestList
{
    public static function Callback()
    {
        $path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        file_put_contents($path.'x.log',DATE(DATE_ATOM));
        return;
    }
    public static function GetTestList()
    {

        $str=<<<EOT
#PHASE 
#CALL MyDuckCoverageApp::Callback
#CMD cmdback
#SETWEB _ _ _ _
#WEB /

EOT;
        $str=<<<EOT
#CMD cmdback

EOT;

        return $str;
    }
}