<?php
namespace tests\DuckCoverage;

use DuckCoverage\TestFileCreator;
use LibCoverage\LibCoverage;

class TestFileCreatorTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        if (_duckcoverage_has_driver()) {
            LibCoverage::Begin(TestFileCreator::class);
        }
        $this->testDefaultOptions();
        if (_duckcoverage_has_driver()) {
            LibCoverage::End();
        }
    }
    private function testDefaultOptions()
    {
        $obj = new TestFileCreator();
        $this->assertEquals('bb', $obj->options['aa']);
    }
}
