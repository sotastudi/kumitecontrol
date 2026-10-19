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

    /** Ids de participantes en huecos de la ronda 1 (luchadores de previa) y de la ronda 2 (exentos). */
    private function prelimAndExempt(\App\Models\Category $category): array
    {
        $ids = fn (int $round) => $category->bouts()->where('round', $round)->where('is_bronze', false)->get()
            ->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter()->values();

        return [$ids(1), $ids(2)];
    }

    private function slotOf(\App\Models\Category $category, int $round, int $registrationId): bool
    {
        return $category->bouts()->where('round', $round)->where('is_bronze', false)
            ->where(fn ($q) => $q->where('slot_a_id', $registrationId)->orWhere('slot_b_id', $registrationId))->exists();
    }

    public function test_swap_exempt_and_prelim_fighter_in_both_directions_with_20(): void
    {
        $category = $this->generated(20);
        [$prelim, $exempt] = $this->prelimAndExempt($category);
        $p = $prelim->first();
        $e = $exempt->first(fn ($id) => ! $prelim->contains($id));

        app(BracketAdjuster::class)->swap($category, $e, $p);
        $this->assertTrue($this->slotOf($category, 1, $e));
        $this->assertTrue($this->slotOf($category, 2, $p));

        app(BracketAdjuster::class)->swap($category->refresh(), $p, $e);
        $this->assertTrue($this->slotOf($category, 1, $p));
        $this->assertTrue($this->slotOf($category, 2, $e));
    }

    public function test_swap_between_two_exempt_participants(): void
    {
        $category = $this->generated(20);
        [, $exempt] = $this->prelimAndExempt($category);
        $directs = $category->bouts()->where('round', 2)->where('is_bronze', false)->orderBy('position')->get()
            ->flatMap(fn ($b) => [['b' => $b->id, 'f' => 'slot_a_id', 'r' => $b->slot_a_id], ['b' => $b->id, 'f' => 'slot_b_id', 'r' => $b->slot_b_id]])
            ->filter(fn ($x) => $x['r'])->values();
        [$x, $y] = [$directs[0], $directs[1]];

        app(BracketAdjuster::class)->swap($category, $x['r'], $y['r']);

        $this->assertSame($y['r'], \App\Models\Bout::find($x['b'])->{$x['f']});
        $this->assertSame($x['r'], \App\Models\Bout::find($y['b'])->{$y['f']});
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
