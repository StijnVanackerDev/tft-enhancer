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

        return Inertia::render('comps/Index', [
            'set' => $set,
            'comps' => array_slice($comps, 0, 40),
            'totalGames' => array_sum(array_column($comps, 'games')),
        ]);
    }
}
