<?php
namespace tests\DuckCoverage;

use DuckCoverage\CoverageBase;
use LibCoverage\LibCoverage;

class CoverageBaseTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        if (_duckcoverage_has_driver()) {
            LibCoverage::Begin(CoverageBase::class);
        }
        $this->testInit();
        $this->testPaths();
        $this->testBeginEndDump();
        $this->testCreateReport();
        if (_duckcoverage_has_driver()) {
            LibCoverage::End();
        }
    }
    private function testInit()
    {
        $obj = new CoverageBase();
        $obj->init([
            'duckcoverage_path' => sys_get_temp_dir() . '/dc_test_init/',
            'unknown_option' => 123,
        ]);
        $this->assertTrue($obj->is_inited);
        // 未知选项被 array_intersect_key 过滤
        $this->assertArrayNotHasKey('unknown_option', $obj->options);
        $this->assertEquals(sys_get_temp_dir() . '/dc_test_init/', $obj->options['duckcoverage_path']);
        // 默认值
        $this->assertEquals('test_coveragedumps', $obj->options['duckcoverage_path_dump']);
        $this->assertEquals('test_reports', $obj->options['duckcoverage_path_report']);
        $this->assertEquals('', $obj->options['duckcoverage_group']);
    }
    private function testPaths()
    {
        $norm = function ($path) {
            return str_replace('\\', '/', $path);
        };
        $obj = new CoverageBase();
        $obj->init([
            'duckcoverage_path' => '/base',
            'duckcoverage_path_dump' => 'test_coveragedumps',
        ]);
        $ref = new \ReflectionMethod(CoverageBase::class, 'getSubPath');
        $ref->setAccessible(true);
        // 相对路径:拼接 duckcoverage_path
        $this->assertEquals('/base/test_coveragedumps/', $norm($ref->invoke($obj, 'duckcoverage_path_dump')));
        // 绝对路径:直接使用
        $abs = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/abs_dump';
        $obj->init([
            'duckcoverage_path' => '/base',
            'duckcoverage_path_dump' => $abs,
        ]);
        $this->assertEquals($abs . '/', $norm($ref->invoke($obj, 'duckcoverage_path_dump')));

        $is_abs = new \ReflectionMethod(CoverageBase::class, 'IsAbsPath');
        $is_abs->setAccessible(true);
        $this->assertTrue($is_abs->invoke(null, '/tmp/x'));
        $this->assertTrue($is_abs->invoke(null, sys_get_temp_dir()));
        $this->assertFalse($is_abs->invoke(null, 'relative/path'));
    }
    private function testBeginEndDump()
    {
        $path = sys_get_temp_dir() . '/dc_test_dump_' . uniqid();
        $obj = new CoverageBase();
        $obj->init([
            'duckcoverage_path' => $path . '/',
            'duckcoverage_path_src' => __DIR__ . '/../src/',
            'duckcoverage_path_dump' => 'test_coveragedumps',
            'duckcoverage_group' => 'testgroup',
            'duckcoverage_name' => 'testcase',
        ]);
        try {
            $obj->doBegin();
            $obj->doEnd();
        } catch (\Throwable $ex) {
            $this->markTestSkipped('No coverage driver: ' . $ex->getMessage());
            return;
        }
        $file = $path . '/test_coveragedumps/testgroup/' . md5('testcase') . '.php';
        $this->assertFileExists($file);
        $data = include $file;
        $this->assertInstanceOf(\SebastianBergmann\CodeCoverage\CodeCoverage::class, $data);
    }
    private function testCreateReport()
    {
        $path = sys_get_temp_dir() . '/dc_test_report_' . uniqid();
        $obj = new CoverageBase();
        $obj->init([
            'duckcoverage_path' => $path . '/',
            'duckcoverage_path_src' => __DIR__ . '/../src/',
            'duckcoverage_path_dump' => 'test_coveragedumps',
            'duckcoverage_path_report' => 'test_reports',
            'duckcoverage_group' => 'testgroup',
        ]);
        // 先采集一份 dump
        try {
            $obj->doBegin();
            $obj->doEnd();
        } catch (\Throwable $ex) {
            $this->markTestSkipped('No coverage driver: ' . $ex->getMessage());
            return;
        }
        $ret = $obj->createReport(['testgroup']);
        $this->assertArrayHasKey('lines_tested', $ret);
        $this->assertArrayHasKey('lines_total', $ret);
        $this->assertArrayHasKey('lines_percent', $ret);
        $this->assertFileExists($path . '/test_reports/index.html');
    }
}
