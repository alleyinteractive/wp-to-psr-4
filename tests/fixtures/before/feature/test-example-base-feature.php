<?php

namespace Alley\WpToPsr4\Fixtures\Tests\Feature;

use PHPUnit\Framework\TestCase;

class Test_Example_Base_Feature extends TestCase
{
    use Concerns\Example_Concern;

    public function testExample() {
        $this->assertTrue(true);
    }
}
