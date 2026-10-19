<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id', 'number', 'round', 'position', 'is_bronze', 'is_prelim', 'required_participants',
    'slot_a_id', 'slot_b_id', 'winner_id', 'result_type', 'next_bout_id', 'next_slot', 'loser_next_bout_id', 'loser_next_slot',
])]
class Bout extends Model
{
    protected function casts(): array
    {
        return [
            'is_bronze' => 'boolean',
            'is_prelim' => 'boolean',
            'slot_a_id' => 'integer',
            'slot_b_id' => 'integer',
            'winner_id' => 'integer',
            'next_bout_id' => 'integer',
            'loser_next_bout_id' => 'integer',
            'a_ippon' => 'boolean',
            'b_ippon' => 'boolean',
            'judge_votes' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function slotA(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'slot_a_id');
    }

    public function slotB(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'slot_b_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'winner_id');
    }

    public function filledSlots(): int
    {
        return (int) ($this->slot_a_id !== null) + (int) ($this->slot_b_id !== null);
    }

    /** Pase: combate que se resuelve solo porque solo hay un participante posible. */
    public function isBye(): bool
    {
        return (int) $this->required_participants === 1;
    }

    /** Victoria por incomparecencia del rival (retirado o descalificado). */
    public function isWalkover(): bool
    {
        return $this->result_type === 'walkover';
    }

    public function isCompleted(): bool
    {
        return $this->winner_id !== null;
    }

    /** Listo para registrar resultado: ambos participantes definidos y aún sin ganador. */
    public function isReady(): bool
    {
        return ! $this->isBye() && ! $this->isCompleted() && $this->filledSlots() === 2;
    }

    public function isFinal(): bool
    {
        return ! $this->is_bronze && $this->next_bout_id === null;
    }

    public function loserId(): ?int
    {
        if (! $this->isCompleted() || $this->isBye()) {
            return null;
        }

        return $this->slot_a_id === $this->winner_id ? $this->slot_b_id : $this->slot_a_id;
    }
}
