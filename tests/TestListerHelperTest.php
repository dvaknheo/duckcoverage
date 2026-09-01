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
#PHASE_END

EOT;
        $options = [
            'path'=> $path,
            'namespace' =>'NoNamespace',
        ];
        TLApp::_()->init($options);
        TestListerHelper::_()->explainMarco($list);

        //TestListerHelper::_()->replaceLineStart($list);

        TestListerHelper::_()->listForAllCommand();
        TestListerHelper::_()->listForAllRoute();
        TestListerHelper::_()->listForAllBusiness();
        TestListerHelper::_()->listForAllModel();
        
        LibCoverage::_()->cleanDirectory($path);
        $_SERVER = $__SERVER;
        LibCoverage::End();
    }
    public function makeData($path)
    {
        //
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
    public static function GetTestList()
    {
        echo "COMMENT from TLAppChild\n";
        return "COMMENT from TLAppChild\n";
    }
}