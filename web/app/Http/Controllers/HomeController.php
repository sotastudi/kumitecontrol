<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class HomeController extends Controller
{
    private const PER_PAGE = 12;

    public function __invoke(Request $request): View
    {
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = ['q' => null, 'desde' => null, 'hasta' => null];
        $dateError = false;

        if ($validator->fails()) {
            // Un filtro inválido no rompe la portada: se ignoran las fechas (y el texto si es lo que falla) y se avisa.
            $valid = $validator->valid();
            $filters['q'] = $valid['q'] ?? null;
            $dateError = $validator->errors()->hasAny(['desde', 'hasta']);
        } else {
            $filters = array_merge($filters, $validator->validated());
            if ($filters['desde'] && $filters['hasta'] && $filters['desde'] > $filters['hasta']) {
                $filters['desde'] = $filters['hasta'] = null;
                $dateError = true;
            }
        }

        $showingPast = $request->boolean('pasados');
        $term = filled($filters['q']) ? trim($filters['q']) : null;

        $events = Event::listed()
            ->{$showingPast ? 'past' : 'upcoming'}()
            // «!» como carácter de escape: es portable entre MySQL y SQLite, y así % y _ se buscan literalmente.
            ->when($term, fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", ['%'.preg_replace('/([!%_])/', '!$1', $term).'%']))
            ->when($filters['desde'], fn ($q, $d) => $q->whereDate('starts_on', '>=', $d))
            ->when($filters['hasta'], fn ($q, $d) => $q->whereDate('starts_on', '<=', $d))
            ->orderBy('starts_on', $showingPast ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('home', [
            'events' => $events,
            'showingPast' => $showingPast,
            'filters' => ['q' => $term] + $filters,
            'dateError' => $dateError,
        ]);
    }
}
