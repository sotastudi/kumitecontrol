<?php

namespace Tests\Feature\Tournament;

use App\Enums\EventStatus;
use App\Enums\PreResultsWithdrawalRule;
use App\Enums\WithdrawalType;
use App\Models\Registration;
use App\Tournament\ResultRecorder;
use App\Tournament\TournamentException;
use App\Tournament\WithdrawalRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class WithdrawalRecorderTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    private function withdraw(Registration|int $r, WithdrawalType $type = WithdrawalType::Withdrawn, ?string $reason = null): void
    {
        $r = $r instanceof Registration ? $r : Registration::findOrFail($r);
        app(WithdrawalRecorder::class)->record($r, $type, $reason);
    }

    private function win(int $round, int $position, $category, int $winnerId, bool $bronze = false): void
    {
        app(ResultRecorder::class)->record($this->bout($category, $round, $position, $bronze), $winnerId);
    }

    public function test_pending_final_participant_withdraws_and_rival_wins_by_walkover_when_he_arrives(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);
        $this->win(1, 1, $category, $s1->slot_a_id);

        $this->withdraw($s1->slot_a_id);
        $final = $this->bout($category, 2, 1);
        $this->assertNull($final->winner_id);

        $this->win(1, 2, $category, $s2->slot_a_id);

        $final->refresh();
        $this->assertSame($s2->slot_a_id, $final->winner_id);
        $this->assertTrue($final->isWalkover());
        $this->assertSame(2, (int) $final->required_participants);
    }

    public function test_withdrawing_a_participant_with_a_defined_rival_resolves_immediately(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $this->win(1, 2, $category, $s2->slot_a_id);

        $this->withdraw($s1->slot_a_id);

        $final = $this->bout($category, 2, 1);
        $this->assertSame($s2->slot_a_id, $final->winner_id);
        $this->assertTrue($final->isWalkover());
    }

    public function test_previous_results_are_kept(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $this->win(1, 1, $category, $s1->slot_a_id);

        $this->withdraw($s1->slot_a_id);

        $s1->refresh();
        $this->assertSame($s1->slot_a_id, $s1->winner_id);
        $this->assertFalse($s1->isWalkover());
        $r = Registration::find($s1->slot_a_id);
        $this->assertSame(WithdrawalType::Withdrawn, $r->withdrawal_type);
        $this->assertNotNull($r->withdrawn_at);
    }

    public function test_reason_is_stored(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $this->win(1, 1, $category, $s1->slot_a_id);

        $this->withdraw($s1->slot_b_id, WithdrawalType::Disqualified, 'lesión');

        $r = Registration::find($s1->slot_b_id);
        $this->assertTrue($r->isDisqualified());
        $this->assertSame('lesión', $r->withdrawal_reason);
    }

    public function test_requires_real_results(): void
    {
        $category = $this->generated(4);
        $r = $this->bout($category, 1, 1)->slot_a_id;

        try {
            $this->withdraw($r);
            $this->fail('Debió rechazar la baja sin resultados.');
        } catch (TournamentException $e) {
            $this->assertSame('Aún no hay resultados: cancela la inscripción y regenera el cuadro.', $e->getMessage());
        }
        $this->assertNull(Registration::find($r)->withdrawal_type);
    }

    public function test_cannot_withdraw_twice(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $this->withdraw($s1->slot_b_id);

        $this->expectException(TournamentException::class);
        $this->withdraw($s1->slot_b_id, WithdrawalType::Disqualified);
    }

    public function test_finished_event_is_rejected(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $category->event->update(['status' => EventStatus::Finished]);

        $this->expectException(TournamentException::class);
        $this->withdraw($s1->slot_b_id);
    }

    public function test_registration_not_confirmed_or_not_in_bracket_is_rejected(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $outsider = Registration::factory()->forCategory($category)->create(); // pendiente, fuera del cuadro

        $this->expectException(TournamentException::class);
        $this->withdraw($outsider);
    }

    public function test_both_withdrawn_bout_is_not_auto_resolved_and_admits_a_manual_result(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $this->withdraw($s1->slot_b_id);   // perdedor de la semifinal 1, ya en el bronce, sin rival
        $this->withdraw($s2->slot_a_id);   // semifinal 2 pendiente: gana s2.slot_b por walkover; s2.slot_a va al bronce

        $bronze = $this->bout($category, 2, 1, true);
        $this->assertSame($s1->slot_b_id, $bronze->slot_a_id);
        $this->assertSame($s2->slot_a_id, $bronze->slot_b_id);
        $this->assertNull($bronze->winner_id, 'Ambos de baja: no se auto-resuelve');
        $this->assertSame($s2->slot_b_id, $this->bout($category, 1, 2)->winner_id);

        app(ResultRecorder::class)->record($bronze, $s1->slot_b_id);   // resultado manual permitido
        $this->assertSame($s1->slot_b_id, $bronze->fresh()->winner_id);
        $this->assertFalse($bronze->fresh()->isWalkover());
    }

    public function test_walkover_cannot_be_corrected(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $this->withdraw($s2->slot_a_id);   // s2 pendiente, rival vivo -> walkover
        $walkover = $s2->fresh();
        $this->assertTrue($walkover->isWalkover());

        foreach ([$s2->slot_a_id, $s2->slot_b_id] as $id) {
            try {
                app(ResultRecorder::class)->record($walkover, $id);
                $this->fail('Un walkover no se corrige.');
            } catch (TournamentException $e) {
                $this->assertSame('Victoria por incomparecencia: no se puede corregir.', $e->getMessage());
            }
        }
    }

    public function test_correcting_a_bout_blocked_by_a_later_walkover_explains_why(): void
    {
        $category = $this->generated(4);
        $s1 = $this->bout($category, 1, 1);
        $s2 = $this->bout($category, 1, 2);
        $this->win(1, 1, $category, $s1->slot_a_id);
        $this->withdraw($s1->slot_a_id);              // el ganador de s1 se retira antes de la final
        $this->win(1, 2, $category, $s2->slot_a_id);  // la final se resuelve por walkover
        $this->assertTrue($this->bout($category, 2, 1)->isWalkover());

        try {
            app(ResultRecorder::class)->record($s1->fresh(), $s1->slot_b_id);
            $this->fail('No se puede corregir con una final por walkover.');
        } catch (TournamentException $e) {
            $this->assertSame('Un combate posterior se resolvió por incomparecencia y no puede corregirse.', $e->getMessage());
        }
    }

    public function test_walkover_cascades_to_the_next_round(): void
    {
        $category = $this->generated(8);
        $q = fn (int $pos) => $this->bout($category, 1, $pos);
        $this->win(1, 1, $category, $q(1)->slot_a_id);
        $this->win(1, 2, $category, $q(2)->slot_a_id);
        $this->win(1, 3, $category, $q(3)->slot_a_id);

        // Semifinal 1 (r2 p1) pendiente con ambos; semifinal 2 (r2 p2) tiene un solo participante (q3 ganador).
        $w1 = $q(1)->slot_a_id;
        $w2 = $q(2)->slot_a_id;
        $w3 = $q(3)->slot_a_id;

        $this->withdraw($w3);   // w3 espera rival en semi 2: nada que resolver
        $this->assertNull($this->bout($category, 2, 2)->winner_id);

        // Retirar a w1 -> w2 gana semi 1 por walkover y pasa a la final
        $this->withdraw($w1);
        $this->assertSame($w2, $this->bout($category, 2, 1)->winner_id);

        // El cuarto cuarto: llega el rival de w3 -> walkover en semi 2 -> el ganador llega a la final,
        // donde w2 (vivo) espera: sin más walkovers. Retiramos a w2 antes: la final se resuelve en cascada.
        $this->withdraw($w2);   // final: w2 solo, sin rival
        $this->win(1, 4, $category, $q(4)->slot_a_id);   // rival de w3 llega a semi 2 -> walkover -> final: w2(baja) vs ganador -> walkover
        $semi2 = $this->bout($category, 2, 2);
        $final = $this->bout($category, 3, 1);
        $this->assertTrue($semi2->isWalkover());
        $this->assertSame($q(4)->slot_a_id, $semi2->winner_id);
        $this->assertTrue($final->isWalkover());
        $this->assertSame($q(4)->slot_a_id, $final->winner_id);
    }

    public function test_withdrawal_is_allowed_when_category_is_locked_by_withdrawals_even_without_results(): void
    {
        // n=3: el exento no genera walkover al retirarse (su rival de la final aún no existe).
        $category = $this->generated(3);
        $category->event->forceFill(['withdrawal_before_results_rule' => PreResultsWithdrawalRule::AllowWalkover])->save();

        $byeRegistration = Registration::findOrFail($this->bout($category, 2, 1)->slot_a_id);
        $this->withdraw($byeRegistration);

        $category->refresh();
        $this->assertFalse($category->hasResults());
        $this->assertTrue($category->isRegenerationLocked());

        // Se vuelve a la regla por defecto y se retira a otro: el cuadro ya no se puede regenerar, así que no debe pedirlo.
        $category->event->forceFill(['withdrawal_before_results_rule' => PreResultsWithdrawalRule::CancelAndRegenerate])->save();
        $other = Registration::where('category_id', $category->id)->whereKeyNot($byeRegistration->id)->firstOrFail();

        $this->withdraw($other->fresh());

        $this->assertTrue($other->fresh()->isWithdrawn());
    }
}
