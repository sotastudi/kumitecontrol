<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Models\Competitor;
use App\Support\MartialLevels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CompetitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La propiedad se comprueba en el middleware can:manage,event.
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
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_photo' => ['boolean'],
            'photo_public' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Misma persona = nombre y apellidos normalizados + fecha de nacimiento, dentro del evento. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['first_name', 'last_name', 'birth_date'])) {
                return;
            }

            $first = Competitor::normalizeName($this->input('first_name'));
            $last = Competitor::normalizeName($this->input('last_name'));

            $duplicate = $this->route('event')->competitors()
                ->whereKeyNot($this->route('competitor')->id)
                ->whereDate('birth_date', $this->input('birth_date'))->get()
                ->contains(fn (Competitor $c) => Competitor::normalizeName($c->first_name) === $first
                    && Competitor::normalizeName($c->last_name) === $last);

            if ($duplicate) {
                $validator->errors()->add('last_name', 'Ya existe otro competidor con ese nombre y fecha de nacimiento en el evento.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser posterior a hoy ni a la fecha del evento.',
        ];
    }
}
