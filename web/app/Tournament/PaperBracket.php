<?php

namespace App\Tournament;

use App\Models\Bout;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cuadro para imprimir y rellenar a mano (A4 horizontal). Solo la primera ronda lleva nombres; en las demás hay que
 * escribir el número de competidor. Calcula la geometría en milímetros para que nada quede cortado: un cuadro de hasta
 * 16 combates en primera ronda cabe en una hoja; si hay más, se reparte en bloques de 16 (una hoja por bloque con las
 * rondas 1-4) y una hoja final con el resto de rondas (de la 5.ª en adelante), el bronce y el podio.
 */
final class PaperBracket
{
    public const PAGE_W = 297.0;

    public const PAGE_H = 210.0;

    public const MARGIN = 8.0;

    public const HEADER_H = 17.0;

    public const FOOTER_H = 6.0;

    public const GAP = 9.0;

    public const BLOCK = 16;

    public const BLOCK_ROUNDS = 4;

    /**
     * @return list<array{title:string, subtitle:string, cards:list<array>, lines:list<array{x:float,y:float,w:float,h:float}>, extras:array}>
     */
    public function pages(Category $category): array
    {
        $bouts = $category->bouts()->with(['slotA.competitor', 'slotB.competitor'])->get();
        $main = $bouts->where('is_bronze', false);
        $bronze = $bouts->firstWhere('is_bronze', true);
        $rounds = (int) $main->max('round');
        $byKey = $main->keyBy(fn (Bout $b) => $b->round.'-'.$b->position);
        $firstRound = $main->where('round', 1)->sortBy('position')->values();

        $pages = [];
        if ($firstRound->count() <= self::BLOCK) {
            $pages[] = $this->page($byKey, $firstRound, 1, $rounds, 'Cuadro completo', true, $bronze, $rounds);

            return $pages;
        }

        $blocks = $firstRound->chunk(self::BLOCK)->values();
        foreach ($blocks as $i => $block) {
            $pages[] = $this->page($byKey, $block->values(), 1, self::BLOCK_ROUNDS, 'Bloque '.($i + 1).' de '.$blocks->count().' · primera ronda', false, null, $rounds);
        }

        $summaryLeaves = $main->where('round', self::BLOCK_ROUNDS + 1)->sortBy('position')->values();
        $pages[] = $this->page($byKey, $summaryLeaves, self::BLOCK_ROUNDS + 1, $rounds - self::BLOCK_ROUNDS, 'Fase final (ganadores de cada bloque)', true, $bronze, $rounds);

        return $pages;
    }

    /**
     * @param  Collection<string,Bout>  $byKey
     * @param  Collection<int,Bout>  $leaves
     */
    private function page(Collection $byKey, Collection $leaves, int $leafRound, int $columns, string $subtitle, bool $last, ?Bout $bronze, int $totalRounds): array
    {
        $columns = max(1, $columns);
        $areaX = self::MARGIN;
        $areaW = self::PAGE_W - 2 * self::MARGIN;
        $areaY = self::MARGIN + self::HEADER_H;
        $areaH = self::PAGE_H - self::MARGIN - self::FOOTER_H - $areaY - ($last ? 20.0 : 0.0);

        $colW = min(64.0, ($areaW - ($columns - 1) * self::GAP) / $columns);
        $rowH = min(15.0, $areaH / max(1, $leaves->count()));
        $cardH = min(11.5, $rowH - 1.2);

        // Combates presentes en cada columna: los que tienen algún combate de origen en la página.
        $sets = [$leaves->values()->all()];
        for ($c = 1; $c < $columns; $c++) {
            $present = [];
            foreach ($sets[$c - 1] as $child) {
                $parent = $byKey[($leafRound + $c).'-'.intdiv($child->position + 1, 2)] ?? null;
                if ($parent) {
                    $present[$parent->id] = $parent;
                }
            }
            $sets[$c] = array_values($present);
        }

        // Filas ocupadas por cada combate (los de la primera columna, una; los demás, las de sus combates de origen).
        $span = [];
        foreach ($sets[0] as $j => $bout) {
            $span[$bout->id] = [$j, $j + 1];
        }
        for ($c = 1; $c < $columns; $c++) {
            foreach ($sets[$c] as $bout) {
                $children = collect([$byKey[($leafRound + $c - 1).'-'.(2 * $bout->position - 1)] ?? null, $byKey[($leafRound + $c - 1).'-'.(2 * $bout->position)] ?? null])
                    ->filter(fn ($b) => $b && isset($span[$b->id]));
                $span[$bout->id] = [$children->map(fn ($b) => $span[$b->id][0])->min(), $children->map(fn ($b) => $span[$b->id][1])->max()];
            }
        }

        $cards = [];
        $lines = [];
        $centerY = fn (Bout $b) => $areaY + (($span[$b->id][0] + $span[$b->id][1]) / 2) * $rowH;
        $line = function (float $x, float $y, float $w, float $h) use (&$lines) {
            $lines[] = ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round(max($w, 0.3), 2), 'h' => round(max($h, 0.3), 2)];
        };

        foreach ($sets as $c => $set) {
            $x = $areaX + $c * ($colW + self::GAP);
            foreach ($set as $bout) {
                $cy = $centerY($bout);
                $cards[] = $this->card($bout, $byKey, $x, $cy - $cardH / 2, $colW, $cardH, $totalRounds, $c === 0 && $leafRound > 1);

                if ($c + 1 < $columns && isset($byKey[($leafRound + $c + 1).'-'.intdiv($bout->position + 1, 2)])) {
                    $parent = $byKey[($leafRound + $c + 1).'-'.intdiv($bout->position + 1, 2)];
                    $mid = $x + $colW + self::GAP / 2;
                    $line($x + $colW, $cy, self::GAP / 2, 0.3);
                    // Un conector vertical por pareja (lo dibuja el combate de arriba) y el tramo hasta el combate siguiente.
                    if ($bout->position % 2 === 1) {
                        $sibling = $byKey[($leafRound + $c).'-'.($bout->position + 1)] ?? null;
                        $other = $sibling && isset($span[$sibling->id]) ? $centerY($sibling) : $cy;
                        $line($mid, min($cy, $other), 0.3, abs($other - $cy));
                        $line($mid, ($cy + $other) / 2, self::GAP / 2, 0.3);
                    }
                }
            }
        }

        $extras = [];
        if ($last) {
            $extras['bronze'] = $bronze ? ['number' => $bronze->number, 'bye' => $bronze->isBye()] : null;
            $extras['fourth'] = (bool) ($bronze && ! $bronze->isBye());
            $extras['y'] = $areaY + $areaH + 3.0;
        }

        return ['title' => '', 'subtitle' => $subtitle, 'cards' => $cards, 'lines' => $lines, 'extras' => $extras];
    }

    /** @param  Collection<string,Bout>  $byKey */
    private function card(Bout $bout, Collection $byKey, float $x, float $y, float $w, float $h, int $totalRounds, bool $summaryLeaf): array
    {
        $head = ($bout->number ? 'Combate '.$bout->number : 'Pase').' · '.RoundLabel::for($bout->round, $totalRounds, $bout->is_prelim);
        $rows = [];

        foreach (['a' => 'aka', 'b' => 'shiro'] as $slot => $side) {
            if ($bout->isBye() && $slot === 'b') {
                continue;
            }

            if ($bout->round === 1) {
                $registration = $slot === 'a' ? $bout->slotA : $bout->slotB;
                $competitor = $registration?->competitor;
                $rows[] = $competitor
                    ? ['side' => $side, 'name' => Str::limit($competitor->displayName(), 30, '…'), 'club' => $competitor->club ? Str::limit($competitor->club, 18, '…') : null, 'bib' => $competitor->bib_number, 'label' => $bout->isBye() ? 'Pase' : null, 'blank' => false]
                    : ['side' => $side, 'name' => null, 'club' => null, 'bib' => null, 'label' => null, 'blank' => true];

                continue;
            }

            // Rondas posteriores: se escribe el número del competidor; si viene de un pase de primera ronda ya se conoce.
            $feeder = $byKey[($bout->round - 1).'-'.($slot === 'a' ? 2 * $bout->position - 1 : 2 * $bout->position)] ?? null;
            $known = $feeder && $feeder->round === 1 && $feeder->isBye() ? ($feeder->slotA ?? $feeder->slotB)?->competitor : null;
            $row = ['side' => $side, 'name' => null, 'club' => null, 'bib' => $known?->bib_number, 'label' => null, 'blank' => true];
            if ($known && ! $known->bib_number) {
                $row['name'] = Str::limit($known->displayName(), 22, '…');
            }
            if ($summaryLeaf && $feeder && $feeder->number) {
                $row['label'] = 'gan. comb. '.$feeder->number;
            }
            $rows[] = $row;
        }

        return ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'head' => $head, 'rows' => $rows];
    }
}
