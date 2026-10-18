<section id="pruebas" class="mb-4">
    <div class="card border-warning">
        <div class="card-header bg-warning-subtle fw-semibold">Datos de prueba (evento Test)</div>
        <div class="card-body">
            <p class="text-muted small">Genera categorías e inscripciones ficticias para probar el sorteo, los tatamis y los resultados. Los inscritos de prueba llevan la nota «DEMO». Nombres, logos y avatares salen del listado Manga Kyokushin (8 clubs, 20 por categoría); lo que exceda se completa con datos aleatorios.</p>
            <div class="row g-4">
                <div class="col-lg-5">
                    <h3 class="h6">Categorías de prueba</h3>
                    <form method="POST" action="{{ route('panel.test.categories', $event) }}" class="row g-2 align-items-end">@csrf
                        <div class="col-6">
                            <label for="test-categories-count" class="form-label small mb-1">Número de categorías</label>
                            <input type="number" id="test-categories-count" name="count" value="{{ old('count', 5) }}" min="1" max="{{ \App\Registrations\TestDataGenerator::MAX_CATEGORIES }}" class="form-control" required>
                        </div>
                        <div class="col-6"><button class="btn btn-warning w-100">Generar categorías</button></div>
                    </form>
                    <div class="form-text">Por defecto 5: infantil mixta, junior masculina y femenina, senior masculina y femenina. Con más se añaden categorías por peso. Se reparten entre los tatamis y no se duplican las que ya existen.</div>
                </div>
                <div class="col-lg-7">
                    <h3 class="h6">Inscripciones de prueba</h3>
                    @if ($event->categories->isEmpty())
                        <p class="text-muted small mb-0">Crea primero alguna categoría.</p>
                    @else
                        <form method="POST" action="{{ route('panel.test.registrations', $event) }}" class="row g-2 align-items-end">@csrf
                            <div class="col-12">
                                <label for="test-category" class="form-label small mb-1">Categoría</label>
                                <select id="test-category" name="category_id" class="form-select" required>
                                    @foreach ($event->categories as $category)
                                        <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label for="test-participants" class="form-label small mb-1">Participantes</label>
                                <input type="number" id="test-participants" name="participants" value="{{ old('participants', 20) }}" min="1" max="{{ \App\Registrations\TestDataGenerator::MAX_PARTICIPANTS }}" class="form-control" required>
                            </div>
                            <div class="col-6 col-md-4">
                                <label for="test-clubs" class="form-label small mb-1">Clubes</label>
                                <input type="number" id="test-clubs" name="clubs" value="{{ old('clubs', 8) }}" min="1" max="50" class="form-control" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <input type="hidden" name="confirmed" value="0">
                                <div class="form-check"><input type="checkbox" class="form-check-input" id="test-confirmed" name="confirmed" value="1" checked><label class="form-check-label" for="test-confirmed">Confirmadas</label></div>
                            </div>
                            <div class="col-12"><button class="btn btn-warning">Generar inscripciones</button></div>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('panel.test.clean', $event) }}" class="mt-3" onsubmit="return confirm('Se borrarán las inscripciones de prueba (DEMO) de las categorías sin resultados. ¿Continuar?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">Borrar inscripciones de prueba</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
