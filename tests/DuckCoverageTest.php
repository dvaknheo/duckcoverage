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
        LibCoverage::_()->cleanDirectory($path);
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
        LibCoverage::_()->cleanDirectory($path);
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

        @mkdir($path.'runtime/', 0777, true); 
        @mkdir($path.'src/');
        @mkdir($path.'public/');
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
        file_put_contents($path.'src/MyDuckCoverageApp.php',"<"."?php declare(strict_types=1);\n".$str);
        $str = <<<'EOT'
setcookie('CK'.DATE('His'),DATE('Y-m-d H:i:s'));
var_dump(DATE(DATE_ATOM));
EOT;
'EOT';

        file_put_contents($path.'public/index.php',"<"."?php declare(strict_types=1);\n".$str);
    }
}
class DuckCoverageEx extends DuckCoverage
{
    public function readCommand($request)
    {
        return parent::readCommand($request);
    }
    public function testRunServer()
    {
        $this->startServer();
        $this->stopServer();
    }

}
class DuckCoverageApp extends DuckPhp
{
    public static function pre_curl($ch,$name)
    {
        var_dump($name);
    }
    public static function post_curl($ch,$name)
    {
        var_dump($name);
    }
    public static function pre_web()
    {
        var_dump(DATE(DATE_ATOM));
    }
    public static function post_web()
    {
        var_dump(DATE(DATE_ATOM));
    }

    public $options =[
        'duckcoverage_enable'=>true,
        'duckcoverage_echo_back' => true,

    ];
    public function __construct()
    {
        parent::__construct();
        $path = explode('\\', static::class);
        $short_class = array_pop($path);
        $namespace = implode("\\", $path);
        $ext_options = [
            'namespace_controller' => "\\".$namespace,
            'name' => 'DuckCoverageApp',
            'controller_welcome_class' => $short_class ,
            'controller_class_postfix' => '',
            'controller_method_prefix' => 'action_',
        ];
        $this->options = array_merge($this->options, $ext_options);
    }
    public function action_index()
    {
        setcookie('CK'.DATE('His'),DATE('Y-m-d H:i:s'));
        var_dump(DATE(DATE_ATOM));
    }
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
#SETWEB {static}::pre_curl {static}::pre_web {static}::prost_web {static}::post_curl
#WEB /
#WEB / a=b POST
#SETWEB AJAX _ _ _
#WEB /
#SETWEB OPTIONS _ _ _
#WEB /

#CMD cmdback

EOT;
        $str = str_replace('{static}',static::class,$str);
        $cmds = explode("\n",$str);
        foreach($cmds as $cmd){
            DuckCoverageEx::_()->readCommand($cmd);
        }
        DuckCoverageEx::_()->testRunServer();
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