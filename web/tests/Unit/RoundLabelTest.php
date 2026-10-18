<?php

namespace Tests\Unit;

use App\Tournament\RoundLabel;
use PHPUnit\Framework\TestCase;

class RoundLabelTest extends TestCase
{
    public function test_prelim_round_is_labelled_ronda_previa(): void
    {
        $this->assertSame('Ronda previa', RoundLabel::for(1, 5, true));
        $this->assertSame('Ronda previa', RoundLabel::for(1, 3, true));
    }

    public function test_labels_without_prelim_are_unchanged(): void
    {
        $this->assertSame('Octavos de final', RoundLabel::for(2, 5));
        $this->assertSame('Octavos de final', RoundLabel::for(1, 4));
        $this->assertSame('Ronda 1', RoundLabel::for(1, 5));
    }
}
