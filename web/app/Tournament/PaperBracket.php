<?php

namespace App\Tournament;

use App\Models\Bout;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cuadro para imprimir y rellenar a mano (A4 horizontal). Solo constan los nombres de la primera ronda (y de los exentos
 * de la ronda previa, en su combate de ronda 2); en las demás hay que escribir el número de competidor. Calcula la
 * geometría en milímetros para que nada quede cortado. Cada fila de la hoja es un hueco de la ronda 2 (lo ocupa el
 * combate de 1.ª ronda que lo alimenta, o queda libre si es un exento): un cuadro de hasta 16 huecos cabe en una hoja; si
 * hay más, se reparte en bloques de 16 (una hoja por bloque con las rondas 1-4) y una hoja final con el resto de rondas
 * (de la 5.ª en adelante), el bronce y el podio.
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

    /** Alto mínimo de una tarjeta para que quepan cabecera y dos filas (normal y compacta, ver pdf/bracket.blade.php). */
    public const CARD_H = 10.6;

    public const CARD_COMPACT_H = 9.1;

    /** Espacio reservado bajo el cuadro en la última hoja para el bronce y el podio. */
    public const EXTRAS_H = 14.0;

    /** @var array<int,array<string,Bout>> combates de origen de cada combate, por hueco (next_bout_id / next_slot) */
    private array $sources = [];

    /**
     * @return list<array{title:string, subtitle:string, compact:bool, cards:list<array>, lines:list<array{x:float,y:float,w:float,h:float}>, extras:array}>
     */
    public function pages(Category $category): array
    {
        $bouts = $category->bouts()->with(['slotA.competitor', 'slotB.competitor'])->get();
        $main = $bouts->where('is_bronze', false);
        $bronze = $bouts->firstWhere('is_bronze', true);
        $rounds = (int) $main->max('round');

        $this->sources = [];
        foreach ($main->whereNotNull('next_bout_id') as $bout) {
            $this->sources[$bout->next_bout_id][$bout->next_slot] = $bout;
        }

        $pages = [];
        // Filas del cuadro completo: una por hueco de la ronda 2 (o por combate si solo hay una ronda).
        if (2 ** ($rounds - 1) <= self::BLOCK) {
            $pages[] = $this->page($main->where('round', $rounds)->sortBy('position')->values(), 1, $rounds, 'Cuadro completo', true, $bronze, $rounds);

            return $pages;
        }

        // Bloques de 16 filas: los combates de la ronda 4 de dos en dos (cada uno abarca 8 filas).
        $blocks = $main->where('round', self::BLOCK_ROUNDS)->sortBy('position')->values()->chunk(intdiv(self::BLOCK, 2 ** (self::BLOCK_ROUNDS - 1)))->values();
        foreach ($blocks as $i => $block) {
            $pages[] = $this->page($block->values(), 1, self::BLOCK_ROUNDS, 'Bloque '.($i + 1).' de '.$blocks->count().' · primera ronda', false, null, $rounds);
        }

        $pages[] = $this->page($main->where('round', $rounds)->sortBy('position')->values(), self::BLOCK_ROUNDS + 1, $rounds - self::BLOCK_ROUNDS, 'Fase final (ganadores de cada bloque)', true, $bronze, $rounds);

        return $pages;
    }

    /**
     * Dibuja el subárbol de los combates `$tops` (última columna) desde la ronda `$leafRound` (primera columna).
     *
     * @param  Collection<int,Bout>  $tops
     */
    private function page(Collection $tops, int $leafRound, int $columns, string $subtitle, bool $last, ?Bout $bronze, int $totalRounds): array
    {
        $columns = max(1, $columns);
        $areaX = self::MARGIN;
        $areaW = self::PAGE_W - 2 * self::MARGIN;
        $areaY = self::MARGIN + self::HEADER_H;
        $areaH = self::PAGE_H - self::MARGIN - self::FOOTER_H - $areaY - ($last ? self::EXTRAS_H : 0.0);

        // Filas de cada combate: los de la primera columna, una; los demás, las de sus dos huecos. Un hueco sin combate de
        // origen (exento) reserva las filas que ocuparía su subárbol completo, para que la geometría sea la de un cuadro lleno.
        $span = [];
        $present = [];
        $rows = 0;
        $place = function (Bout $bout) use (&$place, &$span, &$present, &$rows, $leafRound) {
            $present[$bout->round][] = $bout;
            if ($bout->round <= $leafRound) {
                $span[$bout->id] = [$rows, ++$rows];

                return;
            }
            $start = $rows;
            foreach (['a', 'b'] as $slot) {
                $child = $this->sources[$bout->id][$slot] ?? null;
                $child ? $place($child) : $rows += 2 ** ($bout->round - 1 - $leafRound);
            }
            $span[$bout->id] = [$start, $rows];
        };
        foreach ($tops as $top) {
            $place($top);
        }

        $colW = min(64.0, ($areaW - ($columns - 1) * self::GAP) / $columns);
        $rowH = min(15.0, $areaH / max(1, $rows));
        $cardH = min(11.5, $rowH - 0.6);
        // Con muchas filas la tarjeta usa letra más pequeña para que nada quede cortado.
        $compact = $cardH < self::CARD_H;

        $cards = [];
        $lines = [];
        $centerY = fn (Bout $b) => $areaY + (($span[$b->id][0] + $span[$b->id][1]) / 2) * $rowH;
        $line = function (float $x, float $y, float $w, float $h) use (&$lines) {
            $lines[] = ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round(max($w, 0.3), 2), 'h' => round(max($h, 0.3), 2)];
        };

        $lastRound = $leafRound + $columns - 1;
        for ($round = $leafRound; $round <= $lastRound; $round++) {
            $x = $areaX + ($round - $leafRound) * ($colW + self::GAP);
            foreach (collect($present[$round] ?? [])->sortBy('position') as $bout) {
                $cy = $centerY($bout);
                $cards[] = $this->card($bout, $x, $cy - $cardH / 2, $colW, $cardH, $totalRounds, $round === $leafRound && $leafRound > 1);

                // Conector hasta el combate siguiente: tramo de salida, vertical hasta su altura y tramo de entrada.
                if ($round < $lastRound && $bout->next_bout_id && isset($span[$bout->next_bout_id])) {
                    $mid = $x + $colW + self::GAP / 2;
                    $target = $areaY + (($span[$bout->next_bout_id][0] + $span[$bout->next_bout_id][1]) / 2) * $rowH;
                    $line($x + $colW, $cy, self::GAP / 2, 0.3);
                    $line($mid, min($cy, $target), 0.3, abs($target - $cy));
                    if ($bout->next_slot === 'a' || ! isset($this->sources[$bout->next_bout_id]['a'])) {
                        $line($mid, $target, self::GAP / 2, 0.3);
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

        return ['title' => '', 'subtitle' => $subtitle, 'compact' => $compact, 'cards' => $cards, 'lines' => $lines, 'extras' => $extras];
    }

    private function card(Bout $bout, float $x, float $y, float $w, float $h, int $totalRounds, bool $summaryLeaf): array
    {
        $head = ($bout->number ? 'Combate '.$bout->number : 'Pase').' · '.RoundLabel::for($bout->round, $totalRounds, $bout->is_prelim);
        $rows = [];

        foreach (['a' => 'aka', 'b' => 'shiro'] as $slot => $side) {
            if ($bout->isBye() && $slot === 'b') {
                continue;
            }

            $feeder = $this->sources[$bout->id][$slot] ?? null;
            $registration = $slot === 'a' ? $bout->slotA : $bout->slotB;

            // Primera ronda, o exento de la ronda previa (hueco ya ocupado sin combate de origen): nombre y club.
            if ($bout->round === 1 || (! $feeder && $registration)) {
                $competitor = $registration?->competitor;
                $label = $bout->round === 1 ? ($bout->isBye() ? 'Pase' : null) : 'Exento';
                $rows[] = $competitor
                    ? ['side' => $side, 'name' => Str::limit($competitor->displayName(), 30, '…'), 'club' => $competitor->club ? Str::limit($competitor->club, 18, '…') : null, 'bib' => $competitor->bib_number, 'label' => $label, 'blank' => false]
                    : ['side' => $side, 'name' => null, 'club' => null, 'bib' => null, 'label' => null, 'blank' => true];

                continue;
            }

            // Rondas posteriores: se escribe el número del competidor; si viene de un pase de primera ronda ya se conoce.
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

        return ['bout' => $bout->id, 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'head' => $head, 'rows' => $rows];
    }
}
