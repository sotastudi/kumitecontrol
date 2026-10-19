@php($event ??= null)
<x-field name="name" label="Nombre" :value="$event?->name" required />
<x-field name="discipline" label="Disciplina" :value="$event?->discipline ?? 'KARATE KYOKUSHIN'" required />
<div class="row">
    <div class="col-12 col-md-6"><x-field name="starts_on" type="date" label="Fecha" :value="$event?->starts_on?->format('Y-m-d')" required /></div>
    <div class="col-12 col-md-6"><x-field name="start_time" type="time" label="Hora de inicio" :value="$event?->startTime()" help="Opcional. Se muestra junto a la fecha del evento." /></div>
</div>
<x-field name="venue" label="Lugar" :value="$event?->venue" required />
<x-textarea name="description" label="Descripción" :value="$event?->description" />
<div class="mb-3">
    <label for="poster" class="form-label">Póster del evento</label>
    @if ($event?->hasPoster())
        <div class="mb-2"><img src="{{ route('panel.events.poster', $event) }}?v={{ $event->updated_at?->timestamp }}" alt="Póster actual" class="img-thumbnail" style="max-height: 12rem"></div>
    @endif
    <input type="file" id="poster" name="poster" accept="image/jpeg,image/png,image/webp" class="form-control @error('poster') is-invalid @enderror">
    <div class="form-text">JPG, PNG o WebP, máximo 4 MB. Se muestra en la portada y en la página pública del evento.</div>
    @error('poster')<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($event?->hasPoster())
        <x-check name="remove_poster" label="Quitar el póster actual" :checked="false" />
    @endif
</div>
<x-textarea name="rules_text" label="Normas del campeonato (texto)" :value="$event?->rules_text" :rows="6" />
<div class="mb-3">
    <label for="rules_pdf" class="form-label">Normas en PDF (descargable)</label>
    @if ($event?->hasRulesPdf())
        <div class="mb-2"><a href="{{ route('panel.events.rules', $event) }}">{{ $event->rules_pdf_name }}</a></div>
    @endif
    <input type="file" id="rules_pdf" name="rules_pdf" accept="application/pdf" class="form-control @error('rules_pdf') is-invalid @enderror">
    <div class="form-text">Solo PDF, máximo 10 MB. Se ofrece para descargar en la página pública del evento.</div>
    @error('rules_pdf')<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($event?->hasRulesPdf())
        <x-check name="remove_rules_pdf" label="Quitar el PDF actual" :checked="false" />
    @endif
</div>
<x-select name="status" label="Estado" :options="\App\Enums\EventStatus::options()" :value="$event?->status?->value ?? 'draft'" required />
<x-check name="is_test" label="Evento de prueba (Test)" :checked="$event?->is_test ?? false"
         help="Habilita en el panel del evento la generación de categorías e inscripciones ficticias para probarlo." />
<x-check name="is_public" label="Evento público" :checked="$event?->is_public ?? false"
         help="Un evento público y no en borrador aparece en la portada y tiene página pública." />
<x-check name="registration_form_enabled" label="Habilitar formulario público de inscripción" :checked="$event?->registration_form_enabled ?? false"
         help="Solo funciona con el evento público y en estado «Inscripciones abiertas». Ver docs/menores.md." />
<x-check name="show_photos_publicly" label="Mostrar públicamente las fotos de los inscritos" :checked="$event?->show_photos_publicly ?? false"
         help="Cada participante decide además si su foto se muestra (casilla en su ficha). Los menores solo si su representante ha dado su consentimiento." />
<x-select name="tatami_count" label="Número de tatamis" :options="array_combine(range(1, 10), range(1, 10))" :value="$event?->tatamiCount() ?? 1" required />
<div class="form-text mb-3" style="margin-top: -.75rem">De 1 a 10. Cada categoría se asigna a un tatami en su formulario. No hay calendario automático.</div>
<div class="row">
    <div class="col-md-6"><x-field name="registration_opens_at" type="datetime-local" label="Inicio de inscripciones" :value="$event?->registration_opens_at?->format('Y-m-d\TH:i')" help="Opcional. Hora local del evento. Sin fecha, las inscripciones se abren en cuanto el estado es «Inscripciones abiertas»." /></div>
    <div class="col-md-6"><x-field name="registration_closes_at" type="datetime-local" label="Cierre de inscripciones" :value="$event?->registration_closes_at?->format('Y-m-d\TH:i')" help="Opcional. Pasada esta fecha y hora el formulario público deja de aceptar inscripciones." /></div>
</div>

<fieldset class="mt-4">
    <legend class="h5">Reglas del campeonato</legend>
    <p class="text-muted small">Valen para todas las categorías del evento. Cambiarlas con el evento en marcha recalcula la clasificación al momento; no se modifica ningún combate ni resultado.</p>
    <x-select name="dq_podium_rule" label="Puesto de un descalificado" :options="\App\Enums\DisqualifiedPodiumRule::options()" :value="($event?->dqPodiumRule()->value ?? 'shift_up')" required />
    <div class="form-text mb-3">«Vacante»: el puesto del descalificado queda sin cubrir y los demás conservan el suyo (se avisa en el panel). «Suben»: los siguientes ascienden un puesto, respetando empates.</div>
    <x-select name="unfought_withdrawal_rule" label="Retirado que no llegó a combatir" :options="\App\Enums\UnfoughtWithdrawalRule::options()" :value="($event?->unfoughtWithdrawalRule()->value ?? 'no_place')" required />
    <div class="form-text mb-3">Afecta solo a quien se retira sin haber disputado ningún combate real (los pases y las incomparecencias no cuentan). Quien sí combatió siempre conserva su puesto.</div>
    <x-select name="default_bronze_rule" label="Regla del bronce por defecto" :options="\App\Enums\BronzeRule::options()" :value="($event?->defaultBronzeRule()->value ?? 'bronze_match')" required />
    <div class="form-text mb-3">Solo es el valor que se precarga al crear una categoría; cada categoría guarda su propia regla y se puede cambiar en su formulario.</div>
    <x-select name="withdrawal_before_results_rule" label="Baja antes de que haya resultados" :options="\App\Enums\PreResultsWithdrawalRule::options()" :value="($event?->preResultsWithdrawalRule()->value ?? 'cancel_and_regenerate')" required />
    <div class="form-text mb-3">Con «permitir baja desde el principio», la baja cuenta como resultado y bloquea el cuadro: ya no se podrá regenerar ni ajustar, y una victoria por incomparecencia no se puede corregir.</div>
    <x-select name="draw_criteria" label="Criterios del sorteo" :options="\App\Enums\DrawCriteria::options()" :value="($event?->drawCriteria()->value ?? 'level_weight_club')" required />
    <div class="form-text mb-3">Cómo se ordenan los inscritos al generar el cuadro: los de nivel/peso parecidos se enfrentan entre sí, los de mayor nivel reciben los pases y se evita que el primer combate sea entre el mismo club. Cada categoría puede usar otro criterio.</div>
</fieldset>
