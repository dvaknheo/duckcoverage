<?php
namespace tests\DuckCoverage;

use DuckCoverage\DuckCoverage;
use LibCoverage\LibCoverage;

class DuckCoverageTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        if (_duckcoverage_has_driver()) {
            LibCoverage::Begin(DuckCoverage::class);
        }
        $this->testDefaultOptions();
        if (_duckcoverage_has_driver()) {
            LibCoverage::End();
        }
    }
    private function testDefaultOptions()
    {
        $obj = new DuckCoverage();
        $this->assertTrue($obj->options['duckcoverage_enable']);
        $this->assertEquals('DuckPhpData-duckcoverage.config.json', $obj->options['duckcoverage_data_file_json_file']);
        $this->assertTrue($obj->options['duckcoverage_save_web_request_list']);
        $this->assertEquals(8080, $obj->options['duckcoverage_server_port']);
        $this->assertEquals('public', $obj->options['duckcoverage_path_document']);
        $this->assertEquals('/index_dev.php/', $obj->options['duckcoverage_homepage']);
        $this->assertTrue($obj->options['duckcoverage_new_server']);
        $this->assertEquals('', $obj->options['duckcoverage_web_base_url']);
        $this->assertNull($obj->options['duckcoverage_callback']);
        $this->assertTrue($obj->options['duckcoverage_report_direct']);
        $this->assertFalse($obj->options['duckcoverage_echo_back']);
        // 基类选项已合并
        $this->assertEquals('test_coveragedumps', $obj->options['duckcoverage_path_dump']);
        $this->assertEquals('test_reports', $obj->options['duckcoverage_path_report']);
    }
}
