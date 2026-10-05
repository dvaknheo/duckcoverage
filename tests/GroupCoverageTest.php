<?php
namespace tests\DuckCoverage;

use DuckCoverage\GroupCoverage;
use LibCoverage\LibCoverage;

class GroupCoverageTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $old = LibCoverage::_();
        $path = LibCoverage::_()->getClassTestPath(GroupCoverage::class);
        
        LibCoverage::Begin(GroupCoverage::class);
        @mkdir($path);
        $this->makeData($path);

        GroupCoverage::_(GroupCoverageEx::_());
        GroupCoverage::_()->init([
            'path' => $path,
            'path_src' => $path.'src/',
            'path_dump' => 'path_dump',
            'path_report' => $path.'path_report',
            'groups' => [],
        ]);
        $name = 'test1;a,b,c';
        $group = 'group1';
        GroupCoverage::_()->getCoverage();
        GroupCoverage::_()->doBegin($name, $group, $path.'src/', $path.'path_dump/',);
        try{
            include $path."src/App.php";
            (new \GroupCoverageApp)->foo();
        }catch(\Exception $ex){
            echo $ex->getTraceAsString();
        }
        GroupCoverage::_()->doEnd();
        GroupCoverage::_()->doEnd();

        //createReport(array $groups, string $path_src, string $path_dump, string $path_report);
        GroupCoverageEx::_()->testCreateReport();

        // JSONL 分支：写文件 + stdout 文本 + 组不存在时的 error 记录
        $jsonl_path = $path.'jsonl/report.jsonl';
        $stats = GroupCoverageEx::_()->createReport(['group1'], $path.'src/', $path.'path_dump/', $path.'path_report/', '', [
            'path' => $jsonl_path,
            'stdout' => true,
            'detail' => 'full',
            'timestamp' => false,
        ]);
        $this->assertSame($jsonl_path, $stats['jsonl_report']);
        $this->assertFileExists($jsonl_path);
        $this->assertGreaterThan(0, $stats['dumps_merged']);
        $text = (string)$stats['jsonl_text'];
        $this->assertStringNotContainsString("\r", $text);
        $lines = explode("\n", rtrim($text, "\n"));
        $this->assertStringStartsWith('{"t":"meta"', $lines[0]);
        $this->assertStringStartsWith('{"t":"total"', (string)end($lines));
        $this->assertStringContainsString('"t":"file_lines"', $text);

        // 组不存在：dumps_merged 为 0，且 report.jsonl 里有 error 记录
        $stats2 = GroupCoverageEx::_()->createReport(['no_such_group'], $path.'src/', $path.'path_dump/', $path.'path_report/', '', [
            'path' => $path.'jsonl2/report.jsonl',
            'detail' => 'none',
        ]);
        $this->assertSame(0, $stats2['dumps_merged']);
        $empty_text = (string)file_get_contents($path.'jsonl2/report.jsonl');
        $this->assertStringContainsString('"t":"error"', $empty_text);
        $this->assertStringNotContainsString('"unc"', $empty_text);

       

        
        define('__SINGLETONEX_REPALACER',GroupCoverageSingletonExObject::class . '::CreateObject');
        LibCoverage::_($old);
        GroupCoverage::_();
        LibCoverage::_()->cleanDirectory($path);
        LibCoverage::End();
    }

    protected function makeData($path)
    {
$str=<<<EOT
<?php
class GroupCoverageApp
{
    public function foo()
    {
        var_dump(DATE(DATE_ATOM));
    }
}
EOT;
        @mkdir($path.'src');
        file_put_contents($path.'src/App.php',$str);
        //@mkdir($path.'src/sub');
        file_put_contents($path.'src/emptyfile.txt', DATE(DATE_ATOM));
        
        file_put_contents($path.'src/no_tested.php', DATE(DATE_ATOM));
        @mkdir($path.'path_dump/');
    }

}
class GroupCoverageSingletonExObject
{
    public static function CreateObject($class, $object)
    {
        static $_instance;
        $_instance = $_instance??[];
        $_instance[$class] = $object?:($_instance[$class]??($_instance[$class]??new $class));
        return $_instance[$class];
    }

}
class GroupCoverageEx extends GroupCoverage
{
    public function testCreateReport()
    {
        //createReport(array $groups, string $path_src, string $path_dump, string $path_report);
        $groups = ['group1'];
        $path = LibCoverage::_()->getClassTestPath(GroupCoverage::class);
        $path_src = $path.'src/';
        $path_dump = $path.'path_dump/';
        $path_report = $path.'path_report/';

        GroupCoverageEx::_()->createReport($groups, $path_src,  $path_dump, $path_report);
    }
}
