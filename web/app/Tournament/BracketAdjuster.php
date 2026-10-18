<?php

namespace App\Tournament;

use App\Models\Bout;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BracketAdjuster
{
    public function __construct(private readonly BracketAdvancer $advancer) {}

    /**
     * Intercambia dos participantes de la primera ronda (o exentos de la ronda 2). La estructura del cuadro no cambia.
     *
     * @throws TournamentException
     */
    public function swap(Category $category, int $registrationA, int $registrationB): void
    {
        if ($registrationA === $registrationB) {
            throw new TournamentException('Elige dos participantes distintos.');
        }
        if ($category->isRegenerationLocked()) {
            throw new TournamentException('Hay resultados o bajas registradas: el cuadro ya no se puede ajustar.');
        }

        // Rondas 1 y 2: con ronda previa, los exentos están directamente en la ronda 2.
        $bouts = $category->bouts()->where('round', '<=', 2)->where('is_bronze', false)->orderBy('round')->orderBy('position')->get();
        [$boutA, $slotA] = $this->locate($bouts, $registrationA);
        [$boutB, $slotB] = $this->locate($bouts, $registrationB);

        DB::transaction(function () use ($category, $boutA, $slotA, $boutB, $slotB, $registrationA, $registrationB) {
            $boutA->{$slotA} = $registrationB;
            $boutB->{$slotB} = $registrationA;
            $boutA->save();
            $boutB->save();

            $this->advancer->reset($category);
        });
    }

    /** @return array{0: Bout, 1: string} modelo y nombre de columna */
    private function locate(Collection $bouts, int $registrationId): array
    {
        foreach ($bouts as $bout) {
            foreach (['slot_a_id', 'slot_b_id'] as $column) {
                if ($bout->{$column} === $registrationId) {
                    return [$bout, $column];
                }
            }
        }

        throw new TournamentException('El participante no está en la primera ronda del cuadro.');
    }
}
