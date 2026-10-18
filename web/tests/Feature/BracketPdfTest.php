<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Tournament\BracketGenerator;
use App\Tournament\PaperBracket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BracketPdfTest extends TestCase
{
    use RefreshDatabase;

    private function category(int $n): array
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => EventStatus::InProgress]);
        $category = Category::factory()->for($event)->create(['name' => 'Cat PDF']);
        Registration::factory()->count($n)->confirmed()->forCategory($category)->create();
        app(BracketGenerator::class)->generate($category, fn ($ids) => $ids->values());

        return [$owner, $event, $category];
    }

    public function test_pdf_is_generated_for_the_owner_in_landscape_a4_and_denied_to_others(): void
    {
        [$owner, $event, $category] = $this->category(11);
        $url = route('panel.brackets.pdf', [$event, $category]);

        $response = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('inline; filename="', $response->headers->get('Content-Disposition'));
        // Una sola hoja, A4 horizontal (595 x 842 pt invertido).
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+841\.89\d*\s+595\.27\d*\s*\]#', $response->getContent());
        $this->assertSame(1, preg_match_all('#/Type\s*/Page\b#', $response->getContent()));

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        $empty = Category::factory()->for($event)->create();
        $this->actingAs($owner)->get(route('panel.brackets.pdf', [$event, $empty]))->assertNotFound();
    }

    public function test_only_first_round_has_names_and_later_rounds_ask_for_the_competitor_number(): void
    {
        [, , $category] = $this->category(8);
        $pages = app(PaperBracket::class)->pages($category);

        $this->assertCount(1, $pages);
        $cards = collect($pages[0]['cards']);
        $first = $cards->filter(fn ($c) => str_contains($c['head'], 'Cuartos'));
        $later = $cards->filter(fn ($c) => ! str_contains($c['head'], 'Cuartos'));
        $this->assertCount(4, $first);
        $this->assertTrue($first->every(fn ($c) => collect($c['rows'])->every(fn ($r) => $r['name'] !== null)), 'nombres en la primera ronda');
        $this->assertTrue($later->isNotEmpty() && $later->every(fn ($c) => collect($c['rows'])->every(fn ($r) => $r['name'] === null && $r['blank'])), 'solo casillas de número después');
    }

    public function test_nothing_falls_outside_the_page_for_any_bracket_size(): void
    {
        foreach ([2, 3, 5, 8, 17, 20, 31, 32, 33, 64, 75] as $n) {
            [, , $category] = $this->category($n);
            $pages = app(PaperBracket::class)->pages($category);

            foreach ($pages as $i => $page) {
                foreach ($page['cards'] as $card) {
                    $this->assertGreaterThanOrEqual(PaperBracket::MARGIN, $card['x'], "n={$n} hoja {$i}");
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_W - PaperBracket::MARGIN + 0.01, $card['x'] + $card['w'], "n={$n} hoja {$i} se sale por la derecha");
                    $this->assertGreaterThanOrEqual(PaperBracket::MARGIN + PaperBracket::HEADER_H - 0.01, $card['y'], "n={$n} hoja {$i} invade la cabecera");
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_H - PaperBracket::MARGIN - PaperBracket::FOOTER_H + 0.01, $card['y'] + $card['h'], "n={$n} hoja {$i} se sale por abajo");
                    $this->assertGreaterThanOrEqual($page['compact'] ? PaperBracket::CARD_COMPACT_H : PaperBracket::CARD_H, $card['h'], "n={$n} hoja {$i} tarjeta cortada");
                }
                if (isset($page['extras']['y'])) {
                    // Recuadros de bronce y podio (unos 8 mm de alto) por encima del pie.
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_H - PaperBracket::MARGIN - PaperBracket::FOOTER_H, $page['extras']['y'] + 8.0, "n={$n} podio fuera");
                }
                foreach ($page['lines'] as $line) {
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_W - PaperBracket::MARGIN + 0.5, $line['x'] + $line['w'], "n={$n} línea fuera");
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_H, $line['y'] + $line['h'], "n={$n} línea fuera");
                }
            }

            // Cada combate real de la primera ronda aparece exactamente una vez, con su nombre.
            $round1 = $category->bouts()->where('round', 1)->count();
            $shown = collect($pages)->flatMap(fn ($p) => $p['cards'])->filter(fn ($c) => str_contains($c['head'], 'Combate') || str_contains($c['head'], 'Pase'))->count();
            $this->assertGreaterThanOrEqual($round1, $shown, "n={$n}");
        }
    }

    public function test_big_brackets_are_split_in_blocks_of_16_plus_a_final_sheet_with_bronze_and_podium(): void
    {
        [$owner, $event, $category] = $this->category(64);
        $pages = app(PaperBracket::class)->pages($category);

        // 64 participantes → 32 combates de primera ronda → bloques de 16 y 16 + la hoja final.
        $this->assertCount(3, $pages);
        $firstRoundNames = [];
        foreach (array_slice($pages, 0, 2) as $page) {
            $names = collect($page['cards'])->filter(fn ($c) => str_contains($c['head'], 'Ronda 1'))->count();
            $firstRoundNames[] = $names;
        }
        $this->assertSame([16, 16], $firstRoundNames);
        $this->assertTrue(collect($pages[2]['cards'])->every(fn ($c) => ! str_contains($c['head'], 'Ronda 1')), 'la hoja final no repite la primera ronda');
        $this->assertNotNull($pages[2]['extras']['bronze'] ?? null);
        $this->assertSame([], $pages[0]['extras'], 'bronce y podio solo en la hoja final');

        $pdf = $this->actingAs($owner)->get(route('panel.brackets.pdf', [$event, $category]))->assertOk()->getContent();
        $this->assertSame(3, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    public function test_twenty_participants_show_the_prelim_left_of_its_bout_and_the_exempt_names(): void
    {
        [$owner, $event, $category] = $this->category(20);
        $pages = app(PaperBracket::class)->pages($category);

        $this->assertCount(1, $pages);
        $cards = collect($pages[0]['cards']);
        $prelim = $cards->filter(fn ($c) => str_contains($c['head'], 'Ronda previa'));
        $this->assertCount(4, $prelim);
        $this->assertCount(8, $cards->filter(fn ($c) => str_contains($c['head'], 'Octavos')));
        $this->assertSame(12, $cards->flatMap(fn ($c) => $c['rows'])->where('label', 'Exento')->count());
        $this->assertNamesShownOnce($category, $pages);
        $this->assertPrelimJoinsItsBout($category, $pages);

        $this->actingAs($owner)->get(route('panel.brackets.pdf', [$event, $category]))->assertOk();
        $html = view('pdf.bracket', ['event' => $event, 'category' => $category, 'pages' => $pages])->render();
        $this->assertStringContainsString('Ronda previa', $html);
        $this->assertStringContainsString('Exento', $html);
    }

    public function test_big_bracket_with_prelim_is_split_by_round_two_slots_and_shows_every_name(): void
    {
        // 75 → cuadro de 64: 11 combates de previa y 53 exentos; 64 huecos de ronda 2 = 4 bloques de 16 filas + hoja final.
        [$owner, $event, $category] = $this->category(75);
        $pages = app(PaperBracket::class)->pages($category);

        $this->assertCount(5, $pages);
        $cards = collect($pages)->flatMap(fn ($p) => $p['cards']);
        $this->assertCount(11, $cards->filter(fn ($c) => str_contains($c['head'], 'Ronda previa')));
        $this->assertSame(53, $cards->flatMap(fn ($c) => $c['rows'])->where('label', 'Exento')->count());
        $this->assertTrue(collect($pages[4]['cards'])->every(fn ($c) => ! str_contains($c['head'], 'Ronda previa')));
        $this->assertNotNull($pages[4]['extras']['bronze'] ?? null);
        $this->assertNamesShownOnce($category, $pages);
        $this->assertPrelimJoinsItsBout($category, $pages);

        // Ninguna tarjeta se solapa con otra de la misma columna.
        foreach ($pages as $i => $page) {
            foreach (collect($page['cards'])->groupBy('x') as $column) {
                $sorted = $column->sortBy('y')->values();
                for ($k = 1; $k < $sorted->count(); $k++) {
                    $this->assertGreaterThanOrEqual($sorted[$k - 1]['y'] + $sorted[$k - 1]['h'], $sorted[$k]['y'] + 0.01, "hoja {$i}");
                }
            }
        }

        $pdf = $this->actingAs($owner)->get(route('panel.brackets.pdf', [$event, $category]))->assertOk()->getContent();
        $this->assertSame(5, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    /** Cada participante aparece con su nombre exactamente una vez: en su combate de previa o como exento. */
    private function assertNamesShownOnce(Category $category, array $pages): void
    {
        $shown = collect($pages)->flatMap(fn ($p) => $p['cards'])->flatMap(fn ($c) => $c['rows'])->pluck('name')->filter()->countBy();
        foreach ($category->registrations()->with('competitor')->get() as $registration) {
            $name = Str::limit($registration->competitor->displayName(), 30, '…');
            $this->assertGreaterThanOrEqual(1, $shown[$name] ?? 0, "falta {$name}");
        }
        $this->assertSame($category->registrations()->count(), $shown->sum());
    }

    /** Cada combate de previa está a la izquierda de su combate de ronda 2 y una línea vertical une sus centros. */
    private function assertPrelimJoinsItsBout(Category $category, array $pages): void
    {
        $prelims = $category->bouts()->where('is_prelim', true)->get();
        foreach ($prelims as $prelim) {
            foreach ($pages as $page) {
                $cards = collect($page['cards'])->keyBy('bout');
                if (! isset($cards[$prelim->id])) {
                    continue;
                }
                $from = $cards[$prelim->id];
                $to = $cards[$prelim->next_bout_id];
                $this->assertGreaterThan($from['x'] + $from['w'], $to['x']);
                [$y1, $y2] = [$from['y'] + $from['h'] / 2, $to['y'] + $to['h'] / 2];
                $joined = collect($page['lines'])->contains(fn ($l) => $l['x'] > $from['x'] + $from['w'] && $l['x'] < $to['x']
                    && $l['y'] <= min($y1, $y2) + 0.05 && max($y1, $y2) - 0.05 <= $l['y'] + $l['h']);
                $this->assertTrue($joined, "previa {$prelim->number} sin línea hacia su combate");

                continue 2;
            }
            $this->fail("previa {$prelim->number} no aparece");
        }
    }

    public function test_the_pdf_button_uses_the_download_icon_and_only_the_text_pdf(): void
    {
        [$owner, $event, $category] = $this->category(4);

        $html = $this->actingAs($owner)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('panel.brackets.pdf', [$event, $category]), '#').'"[^>]*aria-label="Descargar el cuadro en PDF para rellenar a mano">\s*<svg[^>]*><use href="\#download"/></svg> PDF</a>#', $html);
        $this->assertStringNotContainsString('PDF para papel', $html);
    }
}
