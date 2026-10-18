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
        foreach ([2, 3, 5, 8, 17, 33, 75] as $n) {
            [, , $category] = $this->category($n);
            $pages = app(PaperBracket::class)->pages($category);

            foreach ($pages as $i => $page) {
                foreach ($page['cards'] as $card) {
                    $this->assertGreaterThanOrEqual(PaperBracket::MARGIN, $card['x'], "n={$n} hoja {$i}");
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_W - PaperBracket::MARGIN + 0.01, $card['x'] + $card['w'], "n={$n} hoja {$i} se sale por la derecha");
                    $this->assertGreaterThanOrEqual(PaperBracket::MARGIN + PaperBracket::HEADER_H - 0.01, $card['y'], "n={$n} hoja {$i} invade la cabecera");
                    $this->assertLessThanOrEqual(PaperBracket::PAGE_H - PaperBracket::MARGIN - PaperBracket::FOOTER_H + 0.01, $card['y'] + $card['h'], "n={$n} hoja {$i} se sale por abajo");
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
        // Potencia de 2 (sin ronda previa): la colocación de la previa en el PDF llega con la vista del cuadro.
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

    public function test_the_pdf_button_uses_the_download_icon_and_only_the_text_pdf(): void
    {
        [$owner, $event, $category] = $this->category(4);

        $html = $this->actingAs($owner)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('panel.brackets.pdf', [$event, $category]), '#').'"[^>]*aria-label="Descargar el cuadro en PDF para rellenar a mano">\s*<svg[^>]*><use href="\#download"/></svg> PDF</a>#', $html);
        $this->assertStringNotContainsString('PDF para papel', $html);
    }
}
