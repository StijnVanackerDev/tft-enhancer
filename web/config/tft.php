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

];
