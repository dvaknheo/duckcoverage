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


        

        //@mkdir($path);
        //$this->makeData($path);
        //$this->testDefaultOptions();

        $__SERVER = $_SERVER;
        LibCoverage::_($old);

        LibCoverage::End();
    }
    protected function cmd(string $str)
    {
        $my_argv = explode(' ', $str);
        array_unshift($my_argv, '-');
        $_SERVER['argv']=$my_argv;
        DuckCoverageApp::_()->run();
    }
}
class DuckCoverageEx extends DuckCoverage
{
    //
}
class DuckCoverageApp extends DuckPhp
{
    public $options =[
        'duckcoverage_enable'=>true,
    ];
    public function __construct()
    {
        parent::__construct();

    }
    protected function onPrepare(): void
    {
        DuckCoverage::_()->beforeInit(); //mover  json data ;
        parent::onPrepare();
        // something from setting;
    }
    // public function command_cmdback()
    // {
    //     $data = Console::_()->getCliParameters();
    //     var_dump($data);
    // }
}
class DuckCoverageTestList
{
    public static function Callback()
    {
        return;
    }
    public static function GetTestList()
    {
// #SETWEB _ _ _ _
// #CMD

        $str=<<<EOT
#PHASE 
#CMD cmdback
#SETWEB _ _ _ _
#CALL DuckCoverageTestList::Callback

EOT;
        return $str;
    }
}