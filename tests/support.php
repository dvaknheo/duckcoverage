<?php
require_once('bootstrap.php');

class support extends \PHPUnit\Framework\TestCase
{
    public function testMain()
    {
        if (!_duckcoverage_has_driver()) {
            $this->markTestSkipped('No coverage driver (xdebug/pcov), report generation skipped');
            return;
        }
        LibCoverage\LibCoverage::G()->showAllReport();
    }
}
