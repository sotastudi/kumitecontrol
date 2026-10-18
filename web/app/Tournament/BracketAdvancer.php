<?php

namespace App\Tournament;

use App\Models\Bout;
use App\Models\Category;
use App\Models\Registration;

final class BracketAdvancer
{
    /** Resuelve los pases cuyo único participante ya está colocado. */
    public function settle(Category $category): void
    {
        $category->bouts()->where('required_participants', 1)->whereNull('winner_id')
            ->orderBy('round')->orderBy('position')->get()
            ->each(function (Bout $bout) {
                $bout->refresh();
                if (! $bout->isCompleted() && $bout->filledSlots() === 1) {
                    $this->complete($bout, $bout->slot_a_id ?? $bout->slot_b_id);
                }
            });
    }

    /** Fija el ganador y coloca ganador y perdedor en sus combates destino (en cascada para pases). */
    public function complete(Bout $bout, int $winnerId): void
    {
        $loserId = $bout->isBye() ? null : ($bout->slot_a_id === $winnerId ? $bout->slot_b_id : $bout->slot_a_id);

        $bout->update(['winner_id' => $winnerId]);

        $this->place($bout->next_bout_id, $bout->next_slot, $winnerId);
        $this->place($bout->loser_next_bout_id, $bout->loser_next_slot, $loserId);
    }

    /** Da por ganado al rival cada combate pendiente de un competidor de baja (si el rival ya está definido y no está de baja). */
    public function resolveWalkovers(Registration $registration): void
    {
        $registration->category->bouts()->where('required_participants', 2)->whereNull('winner_id')
            ->where(fn ($q) => $q->where('slot_a_id', $registration->id)->orWhere('slot_b_id', $registration->id))
            ->orderBy('round')->orderBy('position')->get()
            ->each(function (Bout $bout) {
                $bout->refresh();   // una cascada anterior pudo resolverlo
                $this->resolveIfOneSideWithdrawn($bout);
            });
    }

    /** Victoria por incomparecencia: igual que un resultado, marcado como walkover. */
    public function completeWalkover(Bout $bout, int $winnerId): void
    {
        $bout->update(['result_type' => 'walkover']);
        $this->complete($bout, $winnerId);
    }

    /**
     * Vuelve al estado recién generado (solo válido sin resultados reales) y re-resuelve los pases: vacía los huecos que
     * llena otro combate (ganador o perdedor) y conserva los exentos colocados directamente en la ronda 2.
     */
    public function reset(Category $category): void
    {
        $bouts = $category->bouts()->get();
        foreach (['a', 'b'] as $slot) {
            $fed = $bouts->filter(fn (Bout $b) => $b->next_slot === $slot)->pluck('next_bout_id')
                ->merge($bouts->filter(fn (Bout $b) => $b->loser_next_slot === $slot)->pluck('loser_next_bout_id'))
                ->filter()->unique()->values();
            if ($fed->isNotEmpty()) {
                $category->bouts()->where('round', '>', 1)->whereIn('id', $fed)->update(["slot_{$slot}_id" => null]);
            }
        }
        $category->bouts()->update(['winner_id' => null]);

        $this->settle($category);
    }

    private function place(?int $boutId, ?string $slot, ?int $registrationId): void
    {
        if ($boutId === null || $slot === null || $registrationId === null) {
            return;
        }

        $target = Bout::findOrFail($boutId);
        $target->update([$slot === 'a' ? 'slot_a_id' : 'slot_b_id' => $registrationId]);

        if ($target->isBye() && $target->filledSlots() === 1) {
            $this->complete($target, $registrationId);

            return;
        }

        $this->resolveIfOneSideWithdrawn($target);
    }

    /** Combate con dos participantes donde exactamente uno está de baja: gana el otro. Si ambos lo están, queda para resolución manual. */
    private function resolveIfOneSideWithdrawn(Bout $bout): void
    {
        if ($bout->isBye() || $bout->isCompleted() || $bout->filledSlots() !== 2) {
            return;
        }

        $a = Registration::findOrFail($bout->slot_a_id);
        $b = Registration::findOrFail($bout->slot_b_id);

        if ($a->isWithdrawn() !== $b->isWithdrawn()) {
            $this->completeWalkover($bout, $a->isWithdrawn() ? $b->id : $a->id);
        }
    }
}
