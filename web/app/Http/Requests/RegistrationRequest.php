<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Support\MartialLevels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Panel: la propiedad la comprueba can:manage,event. Público: PublicRegistrationRequest exige inscripción abierta.
    }

    /** photo_public es NOT NULL: un valor vacío se descarta para conservar el valor por defecto. */
    protected function prepareForValidation(): void
    {
        if ($this->has('photo_public') && $this->input('photo_public') === null) {
            $this->request->remove('photo_public');
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'club' => ['required', 'string', 'max:150'],
            'birth_date' => ['required', 'date', 'before_or_equal:today', 'before_or_equal:'.$this->route('event')->starts_on->toDateString()],
            'sex' => ['required', Rule::enum(Sex::class)],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'level' => ['required', Rule::in(MartialLevels::values())],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_contact' => ['nullable', 'string', 'max:255'],
            'guardian_consent' => ['nullable', 'boolean'],
            'photo_public' => ['boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('event_id', $this->route('event')->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser posterior a hoy ni a la fecha del evento.',
        ];
    }
}
