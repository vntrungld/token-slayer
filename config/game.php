<?php

return [
    'base_hp' => (int) env('GAME_BASE_HP', 1_000_000),
    'idle_minutes' => (int) env('GAME_IDLE_MINUTES', 30),

    /*
    | Model id prefixes that earn a battlefield flair badge. A hardcoded array
    | rather than an env var: a list of prefixes has no natural env
    | representation, and this is a gameplay setting like the two above.
    | The blink duration is NOT here -- it is a JS constant in
    | resources/js/battlefield/config/timings.js, because nothing delivers a
    | server config value to the browser and it would be dead config.
    */
    'flair_models' => ['claude-fable'],
];
