<?php
namespace tests\DuckCoverage;

use DuckCoverage\CoverageBase;
use LibCoverage\LibCoverage;
use LibCoverage\GroupCoverageRunner;

class CoverageBaseTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $path = LibCoverage::_()->getClassTestPath(CoverageBase::class);
        LibCoverage::Begin(CoverageBase::class);
        GroupCoverageRunner::_(MyGroupCoverageRunner::_());
        @mkdir($path);
        @mkdir($path.'/runtime_dump');
        @mkdir($path.'/runtime_report');
        $this->makeData($path);
        $path_src = $path .'/src/';
        CoverageBase::_(CoverageBaseEx::_())->init([
            'duckcoverage_path' => $path,
            'duckcoverage_path_src' => 'src',
            'duckcoverage_path_dump' => 'runtime_dump',
            'duckcoverage_path_report' => 'runtime_report',
            'duckcoverage_report_direct' => true,
            'duckcoverage_group'=>'',
            'duckcoverage_name'=>'',

        ]);
        CoverageBase::_()->options['duckcoverage_group']="group1";
        CoverageBase::_()->options['duckcoverage_name']="abc";
        CoverageBase::Begin();
        try{
            include $path."src/App.php";
            (new \CoverageBaseApp)->foo();
        }catch(\Exception $ex){
            echo $ex->getTraceAsString();
        }
        CoverageBase::End();
        CoverageBase::_()->createReport($groups =[]);

        CoverageBase::_()->getCoverage();
        CoverageBaseEx::_()->testWatching();

        //LibCoverage::_()->cleanDirectory($path);
        LibCoverage::End();
    }
    protected function makeData($path)
    {
$str=<<<EOT
<?php
class CoverageBaseApp
{
    public function foo()
    {
        var_dump(DATE(DATE_ATOM));
    }
    public function foo2()
    {
        var_dump(DATE(DATE_ATOM));
    }
}
EOT;
        @mkdir($path.'src');
        file_put_contents($path.'src/App.php',$str);
    }


}
class CoverageBaseEx  extends  CoverageBase
{
    public function testWatching()
    {
        $this->watchingBegin("mygroup1");
        $this->watchingGetName();
        $this->watchingEnd();
    }
}
class MyGroupCoverageRunner extends GroupCoverageRunner
{

}