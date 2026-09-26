<?php

namespace App\Http\Controllers;

use App\Services\Tft\MetaComps;
use Inertia\Inertia;
use Inertia\Response;

class CompsController extends Controller
{
    public function index(MetaComps $meta): Response
    {
        $set = $meta->latestSet();
        $comps = $meta->forSet($set);

        // Situational boards (e.g. a 5-cost with items on whatever fits) are
        // counted in the totals but not listed as comps.
        $enterable = array_values(array_filter($comps, fn (array $c) => $c['enterable']));

        return Inertia::render('comps/Index', [
            'set' => $set,
            'comps' => array_slice($enterable, 0, 40),
            'totalGames' => array_sum(array_column($comps, 'games')),
            'coveredGames' => array_sum(array_column($enterable, 'games')),
        ]);
    }
}
