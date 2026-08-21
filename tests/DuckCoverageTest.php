<?php
namespace tests\DuckCoverage;

use DuckCoverage\DuckCoverage;
use LibCoverage\LibCoverage;

class DuckCoverageTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        LibCoverage::Begin(DuckCoverage::class);
        $this->testDefaultOptions();
        LibCoverage::End();
    }
    private function testDefaultOptions()
    {

    }
}
