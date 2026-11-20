<?php

return [
    // How long a print waits for the drawer's Turbo Label app to answer.
    'wait_seconds' => (float) env('PRINT_AGENT_WAIT_SECONDS', 6),
];
