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

        // Only real, repeatable lines are listed; situational boards count in
        // the total but not as a comp.
        $enterable = array_values(array_filter($comps, fn (array $c) => $c['enterable']));

        return Inertia::render('comps/Index', [
            'set' => $set,
            'comps' => array_slice($enterable, 0, 40),
            'totalGames' => $meta->boardCount($set),
            'coveredGames' => array_sum(array_column($enterable, 'games')),
        ]);
    }
}
