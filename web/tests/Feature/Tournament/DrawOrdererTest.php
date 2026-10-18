<?php

namespace Tests\Feature\Tournament;

use App\Enums\DrawCriteria;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\MartialLevels;
use App\Tournament\BracketGenerator;
use App\Tournament\DrawOrderer;
use App\Tournament\PodiumCalculator;
use App\Tournament\ResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawOrdererTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array{level:?string,weight:float,club:string}> $rows */
    private function category(array $rows): Category
    {
        $category = Category::factory()->create();
        foreach ($rows as $row) {
            $competitor = Competitor::factory()->create($row);
            Registration::factory()->confirmed()->forCategory($category)->create(['competitor_id' => $competitor->id]);
        }

        return $category;
    }

    /** @return array{slots: list<list<int>>, prelim: bool, warnings: list<string>} */
    private function draw(Category $category, DrawCriteria $criteria = DrawCriteria::LevelWeightClub): array
    {
        return app(DrawOrderer::class)->order($category->registrations()->with('competitor')->orderBy('id')->get(), $criteria);
    }

    /** @return list<int> ids de todos los huecos, en orden */
    private function placed(array $slots): array
    {
        return array_merge(...$slots);
    }

    /** @return array<int,string> id => nivel */
    private function levels(Category $category): array
    {
        return $category->registrations()->with('competitor')->get()->mapWithKeys(fn ($r) => [$r->id => $r->competitor->level])->all();
    }

    /** @return list<array{string,string}> parejas de "nivel/peso" del primer combate */
    private function firstRoundPairs(Category $category): array
    {
        return $category->bouts()->where('round', 1)->where('required_participants', 2)->get()
            ->map(fn ($b) => [$b->slotA->competitor->level.'/'.$b->slotA->competitor->weight, $b->slotB->competitor->level.'/'.$b->slotB->competitor->weight])
            ->all();
    }

    public function test_adjacent_level_and_weight_fight_each_other(): void
    {
        $category = $this->category([
            ['level' => '1º dan', 'weight' => 70, 'club' => 'A'],
            ['level' => '1º dan', 'weight' => 80, 'club' => 'B'],
            ['level' => '5º kyu', 'weight' => 60, 'club' => 'C'],
            ['level' => '5º kyu', 'weight' => 65, 'club' => 'D'],
        ]);

        app(BracketGenerator::class)->generate($category);

        foreach ($this->firstRoundPairs($category) as $pair) {
            $this->assertSame(substr($pair[0], 0, 6), substr($pair[1], 0, 6), 'se enfrentan niveles distintos');
        }
    }

    public function test_highest_level_is_exempt_and_the_two_lowest_fight_the_prelim(): void
    {
        $category = $this->category([
            ['level' => '3º dan', 'weight' => 70, 'club' => 'A'],
            ['level' => '1º kyu', 'weight' => 70, 'club' => 'B'],
            ['level' => '5º kyu', 'weight' => 70, 'club' => 'C'],
        ]);
        $levels = $this->levels($category);

        $draw = $this->draw($category);

        $this->assertTrue($draw['prelim']);
        $this->assertCount(2, $draw['slots']);
        $exempt = array_values(array_filter($draw['slots'], fn ($s) => count($s) === 1));
        $this->assertCount(1, $exempt);
        $this->assertSame('3º dan', $levels[$exempt[0][0]]);
    }

    public function test_twenty_distinct_levels_give_four_prelim_pairs_of_the_lowest_and_twelve_exempt(): void
    {
        $rows = [];
        foreach (MartialLevels::values() as $i => $level) {
            $rows[] = ['level' => $level, 'weight' => 70, 'club' => 'Club '.$i];
        }
        $category = $this->category($rows);
        $levels = $this->levels($category);
        $lowest = array_slice(MartialLevels::values(), 0, 8);

        $draw = $this->draw($category);

        $this->assertTrue($draw['prelim']);
        $this->assertCount(16, $draw['slots']);
        $pairs = array_values(array_filter($draw['slots'], fn ($s) => count($s) === 2));
        $exempt = array_values(array_filter($draw['slots'], fn ($s) => count($s) === 1));
        $this->assertCount(4, $pairs);
        $this->assertCount(12, $exempt);
        $this->assertEqualsCanonicalizing($lowest, array_map(fn ($id) => $levels[$id], array_merge(...$pairs)));
        $this->assertEqualsCanonicalizing(array_keys($levels), $this->placed($draw['slots']));

        // Cuatro cuadrantes de 4 huecos (5 competidores): una pareja de previa y tres exentos en cada uno.
        foreach (array_chunk($draw['slots'], 4) as $q => $quadrant) {
            $this->assertSame(1, count(array_filter($quadrant, fn ($s) => count($s) === 2)), "cuadrante {$q}");
            $this->assertSame(5, count(array_merge(...$quadrant)), "cuadrante {$q}");
        }
    }

    public function test_power_of_two_has_no_prelim_and_other_sizes_fill_a_power_of_two(): void
    {
        $category = Category::factory()->create();
        Registration::factory()->count(16)->confirmed()->forCategory($category)->create();
        $draw = $this->draw($category);
        $this->assertFalse($draw['prelim']);
        $this->assertCount(8, $draw['slots']);
        $this->assertTrue(collect($draw['slots'])->every(fn ($s) => count($s) === 2));

        foreach ([3 => 2, 6 => 4, 17 => 16, 31 => 16] as $n => $size) {
            $category = Category::factory()->create();
            Registration::factory()->count($n)->confirmed()->forCategory($category)->create();
            $draw = $this->draw($category);

            $this->assertTrue($draw['prelim'], "n={$n}");
            $this->assertCount($size, $draw['slots'], "n={$n}");
            $this->assertSame($n - $size, count(array_filter($draw['slots'], fn ($s) => count($s) === 2)), "n={$n}: combates de previa");
            $this->assertEqualsCanonicalizing($category->registrations()->pluck('id')->all(), $this->placed($draw['slots']), "n={$n}");
        }
    }

    public function test_pure_random_draw_still_has_eight_prelim_fighters_out_of_twenty(): void
    {
        $category = Category::factory()->create();
        Registration::factory()->count(20)->confirmed()->forCategory($category)->create();

        $draw = $this->draw($category, DrawCriteria::Random);

        $this->assertCount(16, $draw['slots']);
        $this->assertCount(8, array_merge(...array_filter($draw['slots'], fn ($s) => count($s) === 2)));
        $this->assertCount(12, array_filter($draw['slots'], fn ($s) => count($s) === 1));
    }

    public function test_exempt_from_the_same_club_do_not_meet_in_their_first_bout_when_avoidable(): void
    {
        // n=10: 8 huecos, 6 exentos y 2 parejas de previa; las semillas 3-6 y 4-5 son exentos que se cruzan entre sí.
        $rows = [];
        foreach (['6º dan' => 'A', '5º dan' => 'B', '4º dan' => 'Dojo X', '3º dan' => 'Dojo Y', '2º dan' => 'Dojo Y', '1º dan' => 'Dojo X'] as $level => $club) {
            $rows[] = ['level' => $level, 'weight' => 70, 'club' => $club];
        }
        foreach (range(1, 4) as $i) {
            $rows[] = ['level' => '5º kyu', 'weight' => 70 + $i, 'club' => 'Otro '.$i];
        }
        $category = $this->category($rows);
        $clubs = $category->registrations()->with('competitor')->get()->mapWithKeys(fn ($r) => [$r->id => $r->competitor->club])->all();

        $draw = $this->draw($category);

        $this->assertCount(8, $draw['slots']);
        $meetings = 0;
        foreach (array_chunk($draw['slots'], 2) as $j => [$a, $b]) {
            if (count($a) === 1 && count($b) === 1) {
                $meetings++;
                $this->assertNotSame($clubs[$a[0]], $clubs[$b[0]], "combate {$j}");
            }
        }
        $this->assertSame(2, $meetings);
        $this->assertSame([], $draw['warnings']);
    }

    public function test_exempt_of_a_single_club_meeting_is_reported_as_a_warning(): void
    {
        // n=10 de un único club: 2 parejas de previa y 2 cruces entre exentos.
        $rows = [];
        foreach (range(1, 10) as $i) {
            $rows[] = ['level' => '1º kyu', 'weight' => 60 + $i, 'club' => 'Dojo Único'];
        }

        $draw = $this->draw($this->category($rows));

        $this->assertCount(4, $draw['warnings']);
        $this->assertStringContainsString('mismo club (Dojo Único)', $draw['warnings'][0]);
    }

    public function test_first_bout_avoids_same_club_when_possible(): void
    {
        foreach (range(1, 15) as $i) {
            $category = $this->category([
                ['level' => '1º kyu', 'weight' => 60, 'club' => 'Dojo X'],
                ['level' => '1º kyu', 'weight' => 61, 'club' => 'dojo x '],
                ['level' => '1º kyu', 'weight' => 62, 'club' => 'Dojo Y'],
                ['level' => '1º kyu', 'weight' => 63, 'club' => 'Dojo Y'],
            ]);

            app(BracketGenerator::class)->generate($category);

            foreach ($category->bouts()->where('round', 1)->get() as $bout) {
                $this->assertNotSame(
                    mb_strtolower(trim($bout->slotA->competitor->club)),
                    mb_strtolower(trim($bout->slotB->competitor->club)),
                );
            }
        }
    }

    public function test_competitors_without_level_or_weight_do_not_break_the_draw(): void
    {
        $category = $this->category([
            ['level' => null, 'weight' => null, 'club' => 'A'],
            ['level' => null, 'weight' => null, 'club' => 'A'],
            ['level' => '2º dan', 'weight' => 70, 'club' => 'B'],
            ['level' => null, 'weight' => 55, 'club' => 'C'],
            ['level' => null, 'weight' => null, 'club' => 'D'],
        ]);

        $draw = $this->draw($category);
        $this->assertCount(5, array_unique($this->placed($draw['slots'])));

        app(BracketGenerator::class)->generate($category);

        $this->assertSame(5, $category->bouts()->where('round', '<=', 2)->where('is_bronze', false)->get()
            ->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter()->unique()->count());
    }

    public function test_category_criteria_overrides_the_event_default(): void
    {
        $category = Category::factory()->create();
        $this->assertSame(DrawCriteria::LevelWeightClub, $category->effectiveDrawCriteria());

        $category->event->forceFill(['draw_criteria' => DrawCriteria::Random])->save();
        $this->assertSame(DrawCriteria::Random, $category->fresh()->effectiveDrawCriteria());

        $category->forceFill(['draw_criteria' => DrawCriteria::WeightClub])->save();
        $this->assertSame(DrawCriteria::WeightClub, $category->fresh()->effectiveDrawCriteria());
    }

    public function test_weight_criteria_pairs_by_weight_ignoring_level(): void
    {
        $category = $this->category([
            ['level' => '1º dan', 'weight' => 50, 'club' => 'A'],
            ['level' => '5º kyu', 'weight' => 51, 'club' => 'B'],
            ['level' => '1º dan', 'weight' => 90, 'club' => 'C'],
            ['level' => '5º kyu', 'weight' => 91, 'club' => 'D'],
        ]);
        $category->forceFill(['draw_criteria' => DrawCriteria::WeightClub])->save();

        app(BracketGenerator::class)->generate($category);

        foreach ($this->firstRoundPairs($category) as $pair) {
            $w = array_map(fn ($x) => (float) explode('/', $x)[1], $pair);
            $this->assertLessThan(5, abs($w[0] - $w[1]));
        }
    }

    public function test_forms_and_validation_for_draw_criteria(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)->get(route('panel.events.edit', $event))->assertSee('name="draw_criteria"', false)->assertSee('Nivel &gt; peso &gt; club', false);
        $this->actingAs($user)->get(route('panel.categories.create', $event))->assertSee('Según el evento (Nivel &gt; peso &gt; club)', false);

        $payload = ['name' => 'X', 'bronze_rule' => 'bronze_match'];
        $this->actingAs($user)->post(route('panel.categories.store', $event), $payload + ['draw_criteria' => 'nope'])->assertSessionHasErrors('draw_criteria');
        $this->actingAs($user)->post(route('panel.categories.store', $event), $payload + ['draw_criteria' => 'random'])->assertSessionDoesntHaveErrors();
        $this->assertSame(DrawCriteria::Random, $event->categories()->firstOrFail()->draw_criteria);
    }

    public function test_a_forced_same_club_pairing_is_kept_and_reported_as_a_warning(): void
    {
        $rows = [];
        foreach (range(1, 6) as $i) {
            $rows[] = ['level' => '1º kyu', 'weight' => 60 + $i, 'club' => 'Dojo Único'];
        }
        $category = $this->category($rows);

        app(BracketGenerator::class)->generate($category);

        $category->refresh();
        // 6 = 2 exentos + 2 combates de previa (que se mantienen aunque sean del mismo club).
        $this->assertSame(2, $category->bouts()->where('round', 1)->where('required_participants', 2)->count());
        $this->assertCount(2, $category->draw_warnings);
        $this->assertStringContainsString('mismo club (Dojo Único)', $category->draw_warnings[0]);

        $owner = $category->event->user;
        $this->actingAs($owner)->get(route('panel.brackets.show', [$category->event, $category]))
            ->assertSee('Revisa el sorteo')->assertSee('mismo club (Dojo Único)');
    }

    public function test_no_warning_when_clubs_can_be_separated_and_regenerating_clears_old_warnings(): void
    {
        $category = $this->category([
            ['level' => '1º kyu', 'weight' => 60, 'club' => 'A'], ['level' => '1º kyu', 'weight' => 61, 'club' => 'A'],
            ['level' => '1º kyu', 'weight' => 62, 'club' => 'B'], ['level' => '1º kyu', 'weight' => 63, 'club' => 'B'],
        ]);
        $category->forceFill(['draw_warnings' => ['viejo aviso']])->save();

        app(BracketGenerator::class)->generate($category);

        $this->assertNull($category->fresh()->draw_warnings);
    }

    public function test_every_bracket_size_can_be_played_to_the_final(): void
    {
        foreach (range(2, 33) as $n) {
            $category = Category::factory()->create();
            Registration::factory()->count($n)->confirmed()->forCategory($category)->create();
            app(BracketGenerator::class)->generate($category);

            $recorder = app(ResultRecorder::class);
            while ($bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady())) {
                $recorder->record($bout, $bout->slot_a_id);
            }

            $final = $category->bouts()->get()->first(fn ($b) => $b->isFinal());
            $this->assertTrue($final->isCompleted(), "n={$n}: la final no se resolvió");
            $this->assertSame(0, $category->bouts()->where('required_participants', 2)->whereNull('winner_id')->count(), "n={$n}: combates sin ganador");
            $podium = app(PodiumCalculator::class)->for($category->fresh());
            $this->assertGreaterThanOrEqual(min(3, $n), count($podium), "n={$n}");
        }
    }
}
