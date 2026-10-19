<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HomeEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    private function event(string $name, string $date): Event
    {
        return Event::factory()->registrationOpen()->create(['name' => $name, 'starts_on' => $date]);
    }

    public function test_default_lists_upcoming_events_ascending_and_hides_past_ones(): void
    {
        $this->event('Mañana', '2026-11-11');
        $this->event('Hoy', '2026-11-10');
        $this->event('Ayer', '2026-11-09');

        $this->get(route('home'))->assertOk()->assertSeeInOrder(['Hoy', 'Mañana'])->assertDontSee('Ayer');
    }

    public function test_event_moves_to_past_the_day_after_its_date(): void
    {
        $this->event('Copa Final', '2026-11-10');

        $this->get(route('home'))->assertSee('Copa Final');
        $this->get(route('home', ['pasados' => 1]))->assertDontSee('Copa Final');

        Carbon::setTestNow('2026-11-11 00:30:00');
        $this->get(route('home'))->assertDontSee('Copa Final');
        $this->get(route('home', ['pasados' => 1]))->assertSee('Copa Final');
    }

    public function test_past_events_are_listed_most_recent_first_and_buttons_link_between_tabs(): void
    {
        $this->event('Antiguo', '2026-01-10');
        $this->event('Reciente', '2026-10-10');

        $this->get(route('home', ['pasados' => 1]))->assertOk()
            ->assertSeeInOrder(['Reciente', 'Antiguo'])
            ->assertSee('Ver próximos eventos')
            ->assertSee('href="'.route('home').'"', false);
        $this->get(route('home'))->assertSee('Ver eventos pasados')->assertSee(route('home', ['pasados' => 1]), false);
    }

    public function test_filters_by_name_case_insensitive_and_treats_wildcards_literally(): void
    {
        $this->event('Copa Otoño', '2026-12-01');
        $this->event('Trofeo Norte', '2026-12-02');

        $this->get(route('home', ['q' => 'copa']))->assertSee('Copa Otoño')->assertDontSee('Trofeo Norte');
        $this->get(route('home', ['q' => '%']))->assertDontSee('Copa Otoño')->assertDontSee('Trofeo Norte');
        $this->get(route('home', ['q' => '_']))->assertDontSee('Copa Otoño');
    }

    public function test_filters_by_date_range_open_ended_and_invalid_input_does_not_break(): void
    {
        $this->event('Uno', '2026-12-01');
        $this->event('Dos', '2026-12-10');
        $this->event('Tres', '2026-12-20');

        $this->get(route('home', ['desde' => '2026-12-10']))->assertDontSee('Uno')->assertSee('Dos')->assertSee('Tres');
        $this->get(route('home', ['hasta' => '2026-12-10']))->assertSee('Uno')->assertSee('Dos')->assertDontSee('Tres');
        $this->get(route('home', ['desde' => '2026-12-10', 'hasta' => '2026-12-10']))->assertDontSee('Uno')->assertSee('Dos')->assertDontSee('Tres');

        foreach ([['desde' => '2026-12-20', 'hasta' => '2026-12-01'], ['desde' => 'abc']] as $bad) {
            $this->get(route('home', $bad))->assertOk()->assertSee('periodo de fechas no válido')
                ->assertSee('Uno')->assertSee('Dos')->assertSee('Tres');
        }
    }

    public function test_results_are_paginated_by_12_and_keep_filters_in_links(): void
    {
        foreach (range(1, 13) as $i) {
            $this->event(sprintf('Copa %02d', $i), '2026-12-'.sprintf('%02d', $i));
        }

        $page1 = $this->get(route('home', ['q' => 'Copa']))->assertOk()->assertSee('Copa 12')->assertDontSee('Copa 13');
        $this->assertStringContainsString('q=Copa', html_entity_decode($page1->getContent()).'', 'el paginador conserva el filtro');
        $this->assertMatchesRegularExpression('#href="[^"]*page=2[^"]*"#', $page1->getContent());
        $this->assertMatchesRegularExpression('#href="[^"]*q=Copa[^"]*page=2|href="[^"]*page=2[^"]*q=Copa#', $page1->getContent());
        $this->get(route('home', ['q' => 'Copa', 'page' => 2]))->assertSee('Copa 13')->assertDontSee('Copa 01');
    }
}
