@php($competitor ??= null)
@php($lockedClub ??= null)
<div class="row">
    <div class="col-md-6"><x-field name="first_name" label="Nombre" :value="$competitor?->first_name" required /></div>
    <div class="col-md-6"><x-field name="last_name" label="Apellidos" :value="$competitor?->last_name" required /></div>
</div>
<x-field name="club" label="Club" :value="$lockedClub ?? $competitor?->club" :readonly="(bool) $lockedClub" required />
<div class="row">
    <div class="col-md-4"><x-field name="birth_date" type="date" label="Fecha de nacimiento" :value="$competitor?->birth_date?->format('Y-m-d')" required /></div>
    <div class="col-md-4"><x-select name="sex" label="Sexo" :options="\App\Enums\Sex::options()" :value="$competitor?->sex?->value" placeholder="Selecciona…" required /></div>
    <div class="col-md-4"><x-field name="weight" type="number" step="0.1" min="0" max="300" label="Peso (kg)" :value="$competitor?->weight" /></div>
</div>
<div data-category-fit class="alert alert-warning py-2 d-none" role="status"></div>
<x-select name="level" label="Nivel" :options="\App\Support\MartialLevels::options()" :value="$competitor?->level" placeholder="Selecciona…" required />
<div class="mb-3">
    <label for="photo" class="form-label">Foto del participante (opcional)</label>
    @if ($competitor?->hasPhoto())
        <div class="mb-2"><img src="{{ route('panel.competitors.photo', [$event, $competitor]) }}?v={{ $competitor->updated_at?->timestamp }}" alt="Foto actual" class="img-thumbnail" style="max-height: 10rem"></div>
    @endif
    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control @error('photo') is-invalid @enderror">
    <div class="form-text">JPG, PNG o WebP, máximo 3 MB. Solo se muestra públicamente si el organizador lo permite en el evento y la casilla de abajo está marcada. Si es menor, solo con el consentimiento de su representante.</div>
    @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($competitor?->hasPhoto())
        <x-check name="remove_photo" label="Quitar la foto actual" :checked="false" />
    @endif
    {{-- Nueva ficha: marcada por defecto (la columna es NOT NULL con valor true). --}}
    <x-check name="photo_public" label="Mostrar la foto públicamente (si el organizador lo permite)" :checked="$competitor?->photo_public ?? true" />
</div>
<div class="row">
    <div class="col-md-6"><x-field name="email" type="email" label="Correo electrónico" :value="$competitor?->email" /></div>
    <div class="col-md-6"><x-field name="phone" label="Teléfono" :value="$competitor?->phone" /></div>
</div>
