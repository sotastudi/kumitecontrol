<?php

namespace Tests\Feature\Tournament;

use App\Tournament\BracketAdjuster;
use App\Tournament\ResultRecorder;
use App\Tournament\TournamentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class BracketAdjusterTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    public function test_swap_exchanges_two_participants(): void
    {
        $category = $this->generated(4);
        $a = $this->bout($category, 1, 1)->slot_a_id;
        $b = $this->bout($category, 1, 2)->slot_a_id;

        app(BracketAdjuster::class)->swap($category, $a, $b);

        $this->assertSame($b, $this->bout($category, 1, 1)->slot_a_id);
        $this->assertSame($a, $this->bout($category, 1, 2)->slot_a_id);
    }

    public function test_swap_exchanges_the_exempt_with_a_prelim_fighter(): void
    {
        $category = $this->generated(3);
        $prelim = $this->bout($category, 1, 1);
        $exempt = $this->bout($category, 2, 1)->slot_a_id;

        app(BracketAdjuster::class)->swap($category, $exempt, $prelim->slot_a_id);

        $this->assertSame($prelim->slot_a_id, $this->bout($category, 2, 1)->slot_a_id);
        $this->assertSame($exempt, $this->bout($category, 1, 1)->slot_a_id);
    }

    public function test_swap_is_refused_after_a_result_and_for_unknown_participants(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);

        try {
            app(BracketAdjuster::class)->swap($category, $s1->slot_a_id, 999999);
            $this->fail('Debió rechazar un participante desconocido');
        } catch (TournamentException) {
        }

        app(ResultRecorder::class)->record($s1, $s1->slot_a_id);

        $this->expectException(TournamentException::class);
        app(BracketAdjuster::class)->swap($category->refresh(), $s2->slot_a_id, $s2->slot_b_id);
    }

    public function test_swapping_a_participant_with_itself_is_refused(): void
    {
        $category = $this->generated(4);
        $id = $this->bout($category, 1, 1)->slot_a_id;

        $this->expectException(TournamentException::class);
        app(BracketAdjuster::class)->swap($category, $id, $id);
    }
}
