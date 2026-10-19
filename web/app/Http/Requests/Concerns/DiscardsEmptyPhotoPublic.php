<?php

namespace App\Http\Requests\Concerns;

trait DiscardsEmptyPhotoPublic
{
    /** photo_public es NOT NULL: un valor vacío se descarta para conservar el valor por defecto. */
    protected function prepareForValidation(): void
    {
        if ($this->has('photo_public') && $this->input('photo_public') === null) {
            $this->request->remove('photo_public');
        }
    }
}
