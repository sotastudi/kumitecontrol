<?php

namespace Tests\Feature\Tournament;

use App\Enums\BronzeRule;
use App\Enums\RegistrationStatus;
use App\Models\Bout;
use App\Models\Category;
use App\Models\Registration;
use App\Tournament\BracketGenerator;
use App\Tournament\ResultRecorder;
use App\Tournament\TournamentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class BracketGeneratorTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    /** @return list<int> participantes colocados en la ronda previa y la siguiente (sin bronce) */
    private function placed(Category $category): array
    {
        return $category->bouts()->where('round', '<=', 2)->where('is_bronze', false)->get()
            ->flatMap(fn (Bout $b) => [$b->slot_a_id, $b->slot_b_id])->filter()->unique()->values()->all();
    }

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

        $inBracket = $this->placed($category);
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

    public function test_five_participants_have_a_one_bout_prelim_semis_final_and_bronze(): void
    {
        $category = $this->generated(5);
        $ids = $category->registrations()->orderBy('id')->pluck('id');

        // Orden identidad = mérito: los 3 primeros exentos, los 2 últimos disputan la previa.
        $this->assertSame(5, $category->bouts()->count());
        $this->assertSame(0, $category->bouts()->where('required_participants', 1)->count());
        $prelim = $this->bout($category, 1, 1);
        $this->assertTrue($prelim->is_prelim);
        $this->assertSame([$ids[3], $ids[4]], [$prelim->slot_a_id, $prelim->slot_b_id]);
        $this->assertSame(1, $prelim->number);

        $semi1 = $this->bout($category, 2, 1);
        $semi2 = $this->bout($category, 2, 2);
        $this->assertFalse($semi1->is_prelim);
        $this->assertSame([$ids[0], null], [$semi1->slot_a_id, $semi1->slot_b_id]);   // la semilla 1 espera al ganador de la previa
        $this->assertSame([$prelim->next_bout_id, $prelim->next_slot], [$semi1->id, 'b']);
        $this->assertSame([$ids[2], $ids[1]], [$semi2->slot_a_id, $semi2->slot_b_id]);
        $this->assertTrue($semi2->isReady());

        $this->assertSame(2, $this->bout($category, 3, 1, true)->required_participants);
        $this->assertSame([1, 2, 3, 4, 5], $category->bouts()->orderBy('number')->pluck('number')->all());
    }

    public function test_twenty_participants_make_a_four_bout_prelim_and_twelve_exempt(): void
    {
        $category = $this->generated(20);
        $real = $category->bouts()->where('is_bronze', false)->get();

        $this->assertSame(5, $real->max('round'));
        $prelim = $real->where('round', 1);
        $this->assertCount(4, $prelim);
        $this->assertTrue($prelim->every(fn (Bout $b) => $b->is_prelim && $b->required_participants === 2));
        $this->assertSame([1, 2, 3, 4], $prelim->pluck('number')->sort()->values()->all());

        $octavos = $real->where('round', 2)->keyBy('id');
        $this->assertCount(8, $octavos);
        $this->assertFalse($octavos->contains('is_prelim', true));
        $this->assertSame(12, $octavos->sum(fn (Bout $b) => $b->filledSlots()));

        // Una previa por cuadrante (octavos 1-2, 3-4, 5-6, 7-8) y cada una a un combate distinto.
        $targets = $prelim->map(fn (Bout $b) => $octavos[$b->next_bout_id]->position)->sort()->values();
        $this->assertCount(4, $targets->unique());
        $this->assertSame([0, 1, 2, 3], $targets->map(fn ($p) => intdiv($p - 1, 2))->all());
        foreach ($prelim as $b) {
            $this->assertNull($octavos[$b->next_bout_id]->{$b->next_slot === 'a' ? 'slot_a_id' : 'slot_b_id'});
        }

        $this->assertCount(20, $this->placed($category));
        $this->assertSame(range(1, 20), $category->bouts()->orderBy('number')->pluck('number')->all()); // 4+8+4+2+bronce+final
    }

    public function test_prelim_fighters_reaching_the_final_play_five_bouts_and_exempt_four(): void
    {
        foreach (['prelim' => true, 'exempt' => false] as $who => $prelimWins) {
            $category = $this->generated(20);
            $fighters = $category->bouts()->where('is_prelim', true)->get()->flatMap(fn (Bout $b) => [$b->slot_a_id, $b->slot_b_id])->all();

            while ($bout = $category->bouts()->orderBy('number')->get()->first(fn (Bout $b) => $b->isReady())) {
                $aIsPrelim = in_array($bout->slot_a_id, $fighters, true);
                $winner = $aIsPrelim === $prelimWins ? $bout->slot_a_id : $bout->slot_b_id;
                app(ResultRecorder::class)->record($bout, $winner);
            }

            $real = $category->bouts()->where('is_bronze', false)->get();
            $final = $real->first(fn (Bout $b) => $b->isFinal());
            foreach ([$final->slot_a_id, $final->slot_b_id] as $finalist) {
                $this->assertSame($prelimWins, in_array($finalist, $fighters, true), $who);
                $played = $real->filter(fn (Bout $b) => in_array($finalist, [$b->slot_a_id, $b->slot_b_id], true))->count();
                $this->assertSame($prelimWins ? 5 : 4, $played, $who);
            }
        }
    }

    public function test_power_of_two_has_no_prelim_and_small_sizes_build_the_right_rounds(): void
    {
        $category = $this->generated(16);
        $this->assertSame(4, $category->bouts()->max('round'));
        $this->assertFalse($category->bouts()->where('is_prelim', true)->exists());
        $this->assertSame(8, $category->bouts()->where('round', 1)->where('required_participants', 2)->whereNotNull('slot_b_id')->count());

        // n=3: previa de un combate + final con el exento; el bronce es un pase para el perdedor de la previa.
        $category = $this->generated(3);
        $prelim = $this->bout($category, 1, 1);
        $final = $this->bout($category, 2, 1);
        $this->assertTrue($prelim->is_prelim);
        $this->assertTrue($final->isFinal());
        $this->assertNotNull($final->slot_a_id);
        $this->assertSame([$final->id, 'b'], [$prelim->next_bout_id, $prelim->next_slot]);
        $this->assertSame(3, $category->bouts()->count());
    }

    public function test_every_size_feeds_a_power_of_two_round_with_exempt_and_prelim_winners(): void
    {
        foreach ([3, 5, 6, 7, 9, 11, 12, 13, 17, 23, 31, 33] as $n) {
            $category = $this->generated($n);
            $m = 2 ** (int) floor(log($n, 2));
            $bouts = $category->bouts()->where('is_bronze', false)->get();
            $prelim = $bouts->where('round', 1);
            $next = $bouts->where('round', 2);

            $this->assertSame($n - $m, $prelim->count(), "n={$n}: combates de previa");
            $this->assertTrue($prelim->every(fn (Bout $b) => $b->is_prelim && $b->filledSlots() === 2), "n={$n}");
            $this->assertCount(intdiv($m, 2), $next, "n={$n}");
            $this->assertFalse($bouts->contains(fn (Bout $b) => $b->isBye()), "n={$n}: sin pases");

            // Cada hueco de la ronda siguiente es un exento o el ganador de exactamente una previa.
            $fed = $prelim->map(fn (Bout $b) => $b->next_bout_id.$b->next_slot)->all();
            $this->assertCount(count($fed), array_unique($fed), "n={$n}");
            foreach ($next as $b) {
                foreach (['a' => $b->slot_a_id, 'b' => $b->slot_b_id] as $slot => $id) {
                    $this->assertNotSame($id === null, ! in_array($b->id.$slot, $fed, true), "n={$n} combate {$b->position}{$slot}");
                }
            }
            $this->assertCount($n, $this->placed($category), "n={$n}");
        }
    }

    public function test_three_participants_bronze_bout_is_a_single_participant_pase(): void
    {
        $category = $this->generated(3);
        $bronze = $this->bout($category, 2, 1, true);

        $this->assertSame(1, $bronze->required_participants);
        $this->assertNull($bronze->number);
        $this->assertSame($bronze->id, $this->bout($category, 1, 1)->loser_next_bout_id);
        $this->assertSame(0, $category->bouts()->whereNull('number')->where('is_bronze', false)->count());
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

        $this->assertCount(9, $this->placed($category));
    }

    public function test_regenerate_replaces_the_bracket_while_there_are_no_results(): void
    {
        $category = $this->generated(4);
        Registration::factory()->confirmed()->forCategory($category)->create();

        app(BracketGenerator::class)->generate($category->refresh());

        $this->assertSame(5, $category->bouts()->count()); // 5 participantes ahora: previa, 2 semifinales, final y bronce
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
