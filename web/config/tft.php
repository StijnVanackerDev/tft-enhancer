<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Champion pool
    |--------------------------------------------------------------------------
    |
    | Copies of EACH champion in the shared pool, per cost. Riot has used
    | these numbers for several sets; check the patch notes when a new set
    | launches. Keep in sync with overlay/src/lib/poolSizes.js.
    |
    */

    'pool_sizes' => [
        1 => 30,
        2 => 25,
        3 => 18,
        4 => 10,
        5 => 9,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop odds
    |--------------------------------------------------------------------------
    |
    | Chance (%) that a shop slot shows a unit of each cost, per player level.
    | Values from recent sets; not yet verified for the current set. Check
    | them against the in-game level odds tooltip and update when Riot
    | changes them.
    |
    */

    'shop_odds' => [
        1 => [1 => 100, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        2 => [1 => 100, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        3 => [1 => 75, 2 => 25, 3 => 0, 4 => 0, 5 => 0],
        4 => [1 => 55, 2 => 30, 3 => 15, 4 => 0, 5 => 0],
        5 => [1 => 45, 2 => 33, 3 => 20, 4 => 2, 5 => 0],
        6 => [1 => 30, 2 => 40, 3 => 25, 4 => 5, 5 => 0],
        7 => [1 => 19, 2 => 30, 3 => 40, 4 => 10, 5 => 1],
        8 => [1 => 18, 2 => 25, 3 => 32, 4 => 22, 5 => 3],
        9 => [1 => 10, 2 => 20, 3 => 25, 4 => 35, 5 => 10],
        10 => [1 => 5, 2 => 10, 3 => 20, 4 => 40, 5 => 25],
    ],

    'shop_slots' => 5,

    /*
    |--------------------------------------------------------------------------
    | Unit roles within a comp
    |--------------------------------------------------------------------------
    |
    | Derived from our own boards of each comp. A unit that is 3-starred in at
    | least this share of the comp's boards is a "3★ target"; one holding at
    | least this many items on average is a "carry" (or item tank). The rest
    | are fillers, played for their traits, and ignored when judging whether
    | a comp is open.
    |
    */

    'roles' => [
        'target_three_star_rate' => 0.4,
        'carry_min_items' => 1.5,
    ],

];
