<?php

namespace App\Http\Requests;

use App\Models\Competitor;
use Illuminate\Support\Carbon;

class PublicRegistrationRequest extends RegistrationRequest
{
    public function authorize(): bool
    {
        // 404 (no 403) antes de validar: no revela si un evento privado o cerrado existe.
        abort_unless($this->route('event')->acceptsPublicRegistrations(), 404);

        // No hay inscripciones a título personal: solo un club con el registro aprobado en este evento.
        abort_unless($this->approvedClubName() !== null, 403);

        return true;
    }

    /** El club de la inscripción es siempre el de la cuenta, aunque el formulario envíe otro. */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (($club = $this->approvedClubName()) !== null) {
            $this->merge(['club' => $club]);
        }
    }

    private function approvedClubName(): ?string
    {
        $user = $this->user();
        $registration = $user?->isClubAccount() ? $user->clubRegistrationFor($this->route('event')) : null;

        return $registration?->isApproved() ? $user->name : null;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->isMinorOnEventDate()) {
            $rules['guardian_name'] = ['required', 'string', 'max:150'];
            $rules['guardian_contact'] = ['required', 'string', 'max:255'];
            $rules['guardian_consent'] = ['accepted'];
        } else {
            // Adulto: los campos del representante se ignoran por completo (ni se validan ni se guardan).
            unset($rules['guardian_name'], $rules['guardian_contact'], $rules['guardian_consent']);
        }

        return $rules;
    }

    /** Menor en la fecha del evento; una fecha de nacimiento no válida no cuenta (la rechaza su propia regla). */
    private function isMinorOnEventDate(): bool
    {
        $birth = $this->input('birth_date');
        if (! is_string($birth) || $birth === '') {
            return false;
        }

        try {
            Carbon::parse($birth);
        } catch (\Throwable) {
            return false;
        }

        return (new Competitor(['birth_date' => $birth]))->isMinorOn($this->route('event')->starts_on);
    }
}
