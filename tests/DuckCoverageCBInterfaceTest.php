<?php
namespace tests\DuckCoverage;

use DuckCoverage\DuckCoverageCBInterface;
use LibCoverage\LibCoverage;

class DuckCoverageCBInterfaceTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        LibCoverage::Begin(DuckCoverageCBInterface::class);
        $ref = new \ReflectionClass(DuckCoverageCBInterface::class);
        $expected = ['BeforeReplayTest', 'GetTestList', 'AfterReplayTest', 'OnReport'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "missing method {$method}");
            $this->assertTrue($ref->getMethod($method)->isStatic(), "{$method} should be static");
        }
        LibCoverage::End();
    }
}
