<?php

namespace Tests\Unit;

use App\Tournament\BracketSeeding;
use PHPUnit\Framework\TestCase;

class BracketSeedingTest extends TestCase
{
    public function test_order_places_seeds_in_bracket_slot_order(): void
    {
        $this->assertSame([1], BracketSeeding::order(1));
        $this->assertSame([1, 2], BracketSeeding::order(2));
        $this->assertSame([1, 4, 3, 2], BracketSeeding::order(4));
        $this->assertSame([1, 8, 5, 4, 3, 6, 7, 2], BracketSeeding::order(8));
    }
}
