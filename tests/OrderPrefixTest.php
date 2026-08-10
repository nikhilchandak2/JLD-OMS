<?php

namespace Tests;

use App\Support\OrderPrefix;
use PHPUnit\Framework\TestCase;

class OrderPrefixTest extends TestCase
{
    public function testSuggestFromKnownCompanyNames(): void
    {
        $this->assertSame('JLDMM', OrderPrefix::suggestFromName('J L daga Mines & Minerals'));
        $this->assertSame('JLDMPL', OrderPrefix::suggestFromName('JLD Minerals Private Limited'));
        $this->assertSame('JLD', OrderPrefix::suggestFromName('Jaichand Lal Daga'));
    }

    public function testFormatPadsToFourDigits(): void
    {
        $this->assertSame('JLDMPL-0001', OrderPrefix::format('JLDMPL', 1));
        $this->assertSame('JLD-0100', OrderPrefix::format('JLD', 100));
        $this->assertSame('JLDMM-10000', OrderPrefix::format('JLDMM', 10000));
    }
}
