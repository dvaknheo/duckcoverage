<?php 
namespace tests\DuckCoverage;

use DuckCoverage\TestListerHelper;
use DuckPhp\DuckPhp;

use LibCoverage\LibCoverage;

class TestListerHelperTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $__SERVER = $_SERVER;
        LibCoverage::Begin(TestListerHelper::class);
        $path = LibCoverage::_()->getClassTestPath(TestListerHelper::class);
        @mkdir($path);
        $this->makeData($path);

        $list = <<<EOT
#PHASE_BEGIN
#INCLUDE_CALL tests\DuckCoverage\TLApp::ExtList
#INCLUDE_CHILD tests\DuckCoverage\TLAppChild
#BUSINESS NoBusiness@foo
#MODEL NoModel@foo
#ACTION NoAction@foo
#COMPONENT System\\Foo@foo

#PHASE_END

EOT;
        $options = [
            'path'=> $path,
            'namespace' =>'NoNamespace',
        ];
        \tests\DuckCoverage\TLApp::_()->init($options);
        echo TestListerHelper::_()->explainMarco($list);
        TestListerHelper::_()->explainMarco('');

        //TestListerHelper::_()->replaceLineStart($list);


        $this->runMore($path);
        
        LibCoverage::_()->cleanDirectory($path);
        $_SERVER = $__SERVER;
        LibCoverage::End();
    }
    public function makeData($path)
    {
        $this->writeFile($path.'src/System/TLHostApp.php', <<<'EOT'
<?php
namespace TestListHelpInner\System;
use DuckPhp\DuckPhp;
use TestListHelpInner\Controller\TLCommand;

class TLHostApp extends DuckPhp
{
    public $options = [
        "path_namespace" => "src",
        "namespace" => "TestListHelpInner",
        "controller_welcome_class" => "TLController",
        "controller_class_postfix" => "",
        "controller_method_prefix" => "action_",
        "cmd" => [
            TLCommand::class => true,
            'NoExists' => false,
        ],
    ];
}
EOT);
        $this->writeFile($path.'src/Controller/TLCommand.php', <<<'EOT'
<?php
namespace TestListHelpInner\Controller;

class TLCommand
{
    public static function _(){ return new self(); }
    public function command_hello(){}
    public function command_routes(){}
    public function help(){}
    public static function command_static(){}
    public function __construct(){}
}
EOT);
        $this->writeFile($path.'src/Controller/TLController.php', <<<'EOT'
<?php
namespace TestListHelpInner\Controller;

class TLController
{
    public function action_index(){}
    public function action_login(){}
}
EOT);
        $this->writeFile($path.'src/Business/TLBusiness.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

class TLBusiness
{
    public static function _(){ return new self(); }
    public function doIt($a='1'){}
    public function need($req){}
    public function arrayDefault($opt=[]){}
}
EOT);
        $this->writeFile($path.'src/Business/TLAbstract.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

abstract class TLAbstract
{
    public function abs(){}
}
EOT);
        $this->writeFile($path.'src/Business/TLInterface.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

interface TLInterface
{
    public function run();
}
EOT);
        $this->writeFile($path.'src/Business/TLTrait.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

trait TLTrait
{
    public function fromTrait(){}
}
EOT);
        $this->writeFile($path.'src/Business/TLBase.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

class TLBase
{
    public function inherited(){}
}
EOT);
        $this->writeFile($path.'src/Business/TLChild.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

class TLChild extends TLBase
{
    public static function _(){ return new self(); }
    public function childMethod(){}
}
EOT);
        $this->writeFile($path.'src/Business/TLSpecial.php', <<<'EOT'
<?php
namespace TestListHelpInner\Business;

class TLSpecial
{
    public static function _(){ return new self(); }
    public static function staticOnly(){}
    public function __construct(){}
}
EOT);
        $this->writeFile($path.'src/Model/TLModel.php', <<<'EOT'
<?php
namespace TestListHelpInner\Model;

class TLModel
{
    public function hi(){}
}
EOT);
        // 非 php 文件：覆盖 getComponentCalls 里"非 .php 跳过"分支
        file_put_contents($path.'src/Business/notphp.txt', "x");
        // .php 但类名不匹配：触发 getComponentCalls 的 ReflectionException 分支
        // （推导出的类 TestListHelpInner\Business\TLMissing 不存在）
        file_put_contents($path.'src/Business/TLMissing.php', "<?php\nnamespace TestListHelpInner\\Other;\nclass TLMissingNotHere {}\n");
    }
    protected function writeFile($file, $content)
    {
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }
    public function runMore($path)
    {
        foreach ([
            'src/System/TLHostApp.php',
            'src/Controller/TLCommand.php',
            'src/Controller/TLController.php',
            'src/Business/TLBusiness.php',
            'src/Business/TLAbstract.php',
            'src/Business/TLInterface.php',
            'src/Business/TLTrait.php',
            'src/Business/TLBase.php',
            'src/Business/TLChild.php',
            'src/Business/TLSpecial.php',
            'src/Model/TLModel.php',
        ] as $f) {
            include $path.$f;
        }

        \TestListHelpInner\System\TLHostApp::_()->init(['path' => $path, 'namespace' => 'TestListHelpInner']);
        TestListerHelper::_()->genTestListOfAll();

        // 显式调用各项并断言基本形态，确保新方法被执行
        $routes = TestListerHelper::_()->genTestListOfRoutes();
        $this->assertStringContainsString('WEB /', (string)$routes);

        $commands = TestListerHelper::_()->genTestListOfCommands();
        $this->assertStringContainsString('RUN ', (string)$commands);

        $components = TestListerHelper::_()->genTestListOfComponents();
        $this->assertStringContainsString('BUSINESS ', (string)$components);

        // genTestListOfComponents 扫描 src/Business 时会遇到 TLMissing.php，
        // 其对应类 TestListHelpInner\Business\TLMissing 不存在，
        // 以此覆盖 getComponentCalls 的 ReflectionException 捕获分支。

        try{
            \TestListHelpInner\System\TLHostApp::_()->options['path_namespace']=null;
            TestListerHelper::_()->genTestListOfComponents();
        }catch(\LogicException $e){
            //ignore;
        }
    }
}
class TLApp extends DuckPhp
{
    public $options = [
        'name'=>'TLApp',
        'app' => [
            TLAppChild::class => [
                'controller_url_prefix' =>'child/',
            ],
        ],
    ];
    public static function ExtList()
    {
        echo "COMMENT from ExtList\n";
        return "COMMENT from ExtList\n";
    }
}
class TLAppChild extends DuckPhp
{
    public $options = [
        'name'=>'TLAppChild',
        'duckcoverage_test_lister'=>[TLAppChild::class, 'GetTestList'],
    ];
    public function getThisClassName()
    {
        if (static::class === 'tests\\DuckCoverage\\TLAppChild') {
            return 'TestListHelpInner\\TLHostApp';
        }
        return $this->this_class;
    }
    public static function GetTestList()
    {
        return "COMMENT from TLAppChild\n";
    }
}