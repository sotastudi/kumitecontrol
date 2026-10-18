<?php

namespace Tests\Feature\Tournament;

use App\Enums\BronzeRule;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Tournament\BracketGenerator;
use App\Tournament\TournamentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class BracketGeneratorTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    public function test_zero_or_one_participant_generates_nothing_and_never_names_a_champion(): void
    {
        foreach ([0, 1] as $n) {
            $category = $this->categoryWithConfirmed($n);
            try {
                app(BracketGenerator::class)->generate($category);
                $this->fail("n={$n} debió lanzar excepción");
            } catch (TournamentException) {
                $this->assertSame(0, $category->bouts()->count());
            }
        }
    }

    public function test_only_confirmed_registrations_enter_the_bracket(): void
    {
        $category = $this->categoryWithConfirmed(3);
        Registration::factory()->forCategory($category)->create(['status' => RegistrationStatus::Pending]);
        Registration::factory()->forCategory($category)->create(['status' => RegistrationStatus::Cancelled]);

        app(BracketGenerator::class)->generate($category);

        $inBracket = $category->bouts()->where('round', 1)->get()->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter()->values()->all();
        $this->assertCount(3, $inBracket);
        $this->assertEqualsCanonicalizing(
            $category->registrations()->where('status', 'confirmed')->pluck('id')->all(),
            $inBracket,
        );
    }

    public function test_two_participants_generate_a_single_final(): void
    {
        $category = $this->generated(2);

        $this->assertSame(1, $category->bouts()->count());
        $final = $this->bout($category, 1, 1);
        $this->assertTrue($final->isFinal());
        $this->assertTrue($final->isReady());
        $this->assertNotNull($final->number);
    }

    public function test_four_participants_have_semis_final_and_bronze_without_byes(): void
    {
        $category = $this->generated(4);

        $this->assertSame(4, $category->bouts()->count());
        $this->assertSame(0, $category->bouts()->where('required_participants', 1)->count());
        $bronze = $this->bout($category, 2, 1, true);
        $this->assertSame(2, $bronze->required_participants);
        $this->assertSame($bronze->id, $this->bout($category, 1, 1)->loser_next_bout_id);
        $this->assertSame($bronze->id, $this->bout($category, 1, 2)->loser_next_bout_id);
        $this->assertSame([1, 2, 3, 4], $category->bouts()->orderBy('number')->pluck('number')->all());
    }

    public function test_five_participants_have_one_bye_per_round_at_most_and_it_advances_automatically(): void
    {
        $category = $this->generated(5);
        $ids = $category->registrations()->orderBy('id')->pluck('id');

        // Ronda 1: pase + 2 combates; ronda 2: 1 combate + 1 pase; final; bronce (pase: solo hay una semifinal real).
        $this->assertSame(7, $category->bouts()->count());
        $r1 = $category->bouts()->where('round', 1)->orderBy('position')->get();
        $this->assertCount(3, $r1);
        $this->assertSame(1, $r1->where('required_participants', 1)->count());
        $this->assertTrue($r1[0]->isBye() && $r1[0]->isCompleted() && $r1[0]->number === null);
        $this->assertSame([$ids[1], $ids[2]], [$r1[1]->slot_a_id, $r1[1]->slot_b_id]);
        $this->assertSame([$ids[3], $ids[4]], [$r1[2]->slot_a_id, $r1[2]->slot_b_id]);

        $semi = $this->bout($category, 2, 1);
        $this->assertSame($ids[0], $semi->slot_a_id);   // el suelto pasa directo
        $this->assertNull($semi->slot_b_id);            // espera al ganador del combate 2
        $this->assertTrue($this->bout($category, 2, 2)->isBye());

        // Ningún participante aparece dos veces en primera ronda.
        $placed = $r1->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter();
        $this->assertCount(5, $placed->unique());

        // Numeración contigua solo para combates reales: 2 de primera ronda, 1 semifinal y la final.
        $real = $category->bouts()->whereNotNull('number')->orderBy('number')->pluck('number')->all();
        $this->assertSame([1, 2, 3, 4], $real);
    }

    public function test_never_more_than_one_participant_left_loose_in_any_round(): void
    {
        foreach ([2, 3, 4, 5, 6, 7, 9, 11, 12, 13, 17, 23, 31, 33] as $n) {
            $category = $this->generated($n);
            $real = $category->bouts()->where('is_bronze', false)->get();

            foreach ($real->groupBy('round') as $round => $bouts) {
                $this->assertLessThanOrEqual(1, $bouts->filter->isBye()->count(), "n={$n} ronda {$round}: más de un pase");
            }
            // Todos combaten en la primera ronda salvo, como mucho, uno.
            $round1 = $real->where('round', 1);
            $this->assertSame($n % 2, $round1->filter->isBye()->count(), "n={$n}");
            $this->assertSame($n, $round1->sum(fn ($b) => $b->filledSlots()));
        }
    }

    public function test_three_participants_bronze_bout_is_a_single_participant_pase(): void
    {
        $category = $this->generated(3);
        $bronze = $this->bout($category, 2, 1, true);

        $this->assertSame(1, $bronze->required_participants);
        $this->assertNull($bronze->number);
        $this->assertSame(1, $category->bouts()->whereNull('number')->where('is_bronze', false)->count()); // el pase de semifinal
    }

    public function test_shared_bronze_creates_no_bronze_bout(): void
    {
        $category = $this->generated(4, BronzeRule::SharedBronze);

        $this->assertSame(3, $category->bouts()->count());
        $this->assertFalse($category->bouts()->where('is_bronze', true)->exists());
    }

    public function test_default_shuffle_places_every_participant_exactly_once(): void
    {
        $category = $this->categoryWithConfirmed(9);
        app(BracketGenerator::class)->generate($category);

        $placed = $category->bouts()->where('round', 1)->get()->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter();
        $this->assertCount(9, $placed->unique());
    }

    public function test_regenerate_replaces_the_bracket_while_there_are_no_results(): void
    {
        $category = $this->generated(4);
        Registration::factory()->confirmed()->forCategory($category)->create();

        app(BracketGenerator::class)->generate($category->refresh());

        $this->assertSame(8, $category->bouts()->count()); // 5 participantes ahora (cuadro de 4 huecos con 3 exentos)
        $this->assertNotNull($category->fresh()->bracket_generated_at);
    }

    public function test_regenerate_is_refused_once_a_result_exists(): void
    {
        $category = $this->generated(4);
        $semi = $this->bout($category, 1, 1);
        $semi->update(['winner_id' => $semi->slot_a_id]);

        $this->expectException(TournamentException::class);
        app(BracketGenerator::class)->generate($category->refresh());
    }
}
