<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lobby analysis
    |--------------------------------------------------------------------------
    |
    | Loads the match history of every player in a lobby and predicts which
    | comps and champions they are likely to go for. Riot's TFT policy does
    | not allow showing lobby/player aggregate stats during gameplay, so this
    | stays OFF in production unless Riot has approved it for this product.
    |
    */

    'lobby_analysis' => (bool) env('LOBBY_ANALYSIS_ENABLED', false),

];
