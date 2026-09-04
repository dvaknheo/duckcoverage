<?php
namespace tests\DuckCoverage;

use DuckPhp\Core\SuperGlobal;
use DuckPhp\DuckPhp;
use DuckCoverage\DuckCoverage;
use DuckPhp\Core\Console;
use LibCoverage\LibCoverage;
use Override;

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
            'duckcoverage_test_lister' =>[DuckCoverageTestList::class,'GetTestList'],
            'duckcoverage_path_server' =>$path,
        ];
        //DuckCoverageApp::_(\MyDuckCoverageApp::_())->init($options);
        var_dump($_SERVER['argv']);
        $_SERVER['argv'] = ['-','duckcover'];
        DuckCoverageApp::_()->init($options);

        $this->cmd("duckcover --help");
        $this->cmd("duckcover --watch");
        $this->cmd("duckcover --play");
        $this->cmd("duckcover --report");
        $this->cmd("duckcover --stop");

        DuckCoverage::_()->options['duckcoverage_report_direct'] = true;
        $this->cmd("duckcover --watch group1");
        $this->cmd("duckcover --play group1");
        $this->cmd("duckcover --report group1 group2");
        $this->cmd("duckcover --report group1");
        $this->cmd("duckcover --go");

        DuckCoverageApp::_()->testMore();

        /////////////
        $_SERVER['argv'] = $__SERVER['argv'];
        DuckCoverageApp::_(new DuckCoverageApp);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_MYCOVERAGE_GROUP'] = 'group1';
        $_SERVER['HTTP_X_MYCOVERAGE_NAME'] = 'name1';
        $_SERVER['HTTP_X_MYCOVERAGE_BEFORERUN'] = DuckCoverageApp::class . '::beforerun';
        $_SERVER['HTTP_X_MYCOVERAGE_AFTERRUN'] = DuckCoverageApp::class . '::afterrun';
        $_SERVER['REQUEST_URI'] ='/';
        $_SERVER['PATH_INFO'] ='';
        DuckCoverageApp::_()->force_not_cli = true;
        DuckCoverageApp::_()->init($options);
        DuckCoverageEx::_()->cleanName();
        DuckCoverageApp::_()->serve();

        DuckCoverageApp::_()->testLists();
         $_SERVER['argv'] = ['-',''];
        DuckCoverageApp::_(new DuckCoverageApp)->init($options);



        $_SERVER = $__SERVER;
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

}

EOT;
'EOT';
        file_put_contents($path.'src/MyDuckCoverageApp.php',"<"."?php declare(strict_types=1);\n".$str);
        $str = <<<'EOT'
setcookie('CK'.DATE('His'),DATE('Y-m-d H:i:s'));
if($_GET['sleep']??null){
echo "sleep";
sleep(8);
}else{
echo "OK";
}
var_dump(DATE(DATE_ATOM));
EOT;
'EOT';

        file_put_contents($path.'public/index.php',"<"."?php declare(strict_types=1);\n".$str);
    }
}
class DuckCoverageEx extends DuckCoverage
{
    public $stop = false;
    public function checkHttp()
    {
        return ;//parent::checkHttp();
    }
    public function cleanName()
    {
        $this->current_group = "";
        $this->current_name = "";
    }
    #[Override]
    public function doBegin()
    {
        if($this->stop){return;}
        return parent::doBegin();
    }
    #[Override]
    public function doEnd()
    {
        if($this->stop){return;}
        return parent::doEnd();
    }
    public function readCommand($request)
    {
        $this->current_group = 'nogroup';
        $this->current_name = 'noname';
        return parent::readCommand($request);
    }
    public function testRunServer()
    {
        $this->startServer();
        $this->stopServer();
    }
    public  function cloze_curl()
    {
        $this->curl_file_get_contents(['http://ai.local.com/?sleep=1',"127.0.0.1:8017"]);
    }

}
class DuckCoverageApp extends DuckPhp
{
    public $force_not_cli =false;
    public function testLists()
    {
        try{
            $str = DuckCoverage::_()->genTestListOfAll();
        }catch(\Exception $e){
            //
        }
    }

    public function isCli()
    {
        if ($this->force_not_cli){
            return false;
        }
        return parent::isCli();
    }
    public function command_mycmd()
    {
        //$this->assertTrue(true);
        $data = Console::_()->getCliParameters();
        //var_dump($data);
    }

    public static function beforerun()
    {
        //$this->assertTrue(true);
        
    }
    public static function afterrun()
    {
        //$this->assertTrue(true);
    }
    public static function pre_curl($ch,$name)
    {
        //$this->assertTrue(true);
    }
    public static function post_curl($ch,$name)
    {
        var_dump($name);
    }
    public static function pre_web()
    {
        //$this->assertTrue(true);
    }
    public static function post_web()
    {
        //$this->assertTrue(true);
    }
    public static function Callback()
    {
        //$this->assertTrue(true);
        return;
    }

    public $options =[
        'is_debug' => true,
        'duckcoverage_enable'=>true,
        'duckcoverage_debug_curl_echo_back' => true,
        'path_namespace' => 'app',
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
        $this->options['cmd'] = array_merge([static::class => true], $this->options['cmd']);

    }

    public function action_index()
    {
        var_dump(static::class);
        
    }
    protected function onPrepare(): void
    {
        parent::onPrepare();
        DuckCoverage::Prepare();
    }
    public function func($name, $default='_')
    {

    }
    public function testMore()
    {
        $this->is_root = false;
        DuckCoverage::_()->init($this->options,null);
        DuckCoverage::_()->beforeInit();
        $this->is_root = true;

        $this->options['duckcoverage_enable']=false;
        DuckCoverage::_()->options['duckcoverage_enable']=true;
        DuckCoverage::_()->init($this->options,null);
        DuckCoverage::_()->beforeInit();
        $this->options['duckcoverage_enable']=true;

        $str=<<<EOT
COMMENT just a test
BAD 
PHASE 
CALL {static}::Callback
SETWEB OPTIONS _ _ _


RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN :mycmd

CALL @bad
CALL !is_string value=ok
CALL is_string value=ok
CALL {static}->func name=n1
CALL {static}@func name=n2
CALL {static}@func

EOT;

        $str .= <<<EOT
WEB /
SETWEB {static}::pre_curl {static}::pre_web {static}::prost_web {static}::post_curl
WEB /
WEB / a=b POST
SETWEB AJAX _ _ _
WEB /

SETWEB {static}::pre_cloze_curl _ _ _
CALL {static}::cloze_curl


EOT;

$testInputs = <<<'TESTCASES'

a b
'a b'
"a b"
a\ b
"a\"b" "c\d"
\xF0\x9F\x98\x80
TESTCASES;
        $t = explode("\n",$testInputs);
        foreach($t as $v){
            $str = $this->str_replace_first("{ARG}",$v, $str);
        }
        
        $str = str_replace('{static}',static::class,$str);
        $str = str_replace('{cliprefix}',$this->getThisCommandPrefix(),$str);
        $cmds = explode("\n",$str);
        DuckCoverageEx::_()->stop =true;
        foreach($cmds as $cmd){
            DuckCoverageEx::_()->readCommand($cmd);
global $time_start;var_dump(microtime(true)-$time_start);
        }

        DuckCoverageEx::_()->testRunServer();


        DuckCoverageEx::_()->options['duckcoverage_enable'] = true;
        DuckCoverageEx::_()->checkHttp();

        DuckCoverageEx::_()->options['duckcoverage_enable'] =false;
        DuckCoverageEx::_()->_OnBeforeRun();
        DuckCoverageEx::_()->options['duckcoverage_enable'] = true;
        DuckCoverageEx::_()->stop = false;
    }
    private function str_replace_first(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) return $subject;
        return substr_replace($subject, $replace, $pos, strlen($search));
    }
    public static function pre_cloze_curl($ch,$name)
    {
        var_dump(__FUNCTION__);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        var_dump($name);
    }
    public static function cloze_curl()
    {
        DuckCoverageEx::_()->cloze_curl();
    }
}
class DuckCoverageTestList
{
    public static function Callback()
    {
        $path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        return;
    }
    public static function GetTestList()
    {

        $str=<<<EOT
COMMENT just a test

EOT;


        return $str;
    }
}