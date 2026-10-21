<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schedule generation lock
    |--------------------------------------------------------------------------
    |
    | Generation runs the AI engine and then writes a draft, and the engine
    | reads its cross-section constraints from the database at the start of
    | that run. Two sections generated for the same term at the same time would
    | therefore both read the database before either draft existed, and both
    | could claim the same faculty member or room. This lock serialises
    | generation within an academic term, so every run sees the drafts written
    | by the runs before it.
    |
    | The lock lives in the cache store, which must be shared across the app's
    | processes for this to be a cross-process guarantee (the `database` store
    | is configured by default; an in-process store such as `array` only
    | protects a single process).
    |
    | `ttl`  seconds the lock may be held before it expires on its own, so a
    |        crashed request cannot block the term forever.
    | `wait` seconds to wait for a contended lock before giving up with a 409.
    |
    */

    'generation_lock' => [
        'ttl' => (int) env('SCHEDULE_GENERATION_LOCK_TTL', 180),
        'wait' => (int) env('SCHEDULE_GENERATION_LOCK_WAIT', 30),
    ],

];
