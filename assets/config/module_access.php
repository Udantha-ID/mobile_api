<?php
// ── Module access control ─────────────────────────────────────────────────
// Add or remove employee IDs here any time — no Flutter rebuild needed.
// Keys must match what get_user_access.php returns to the app.

return [

    // HR Management section visibility
    "hr_management" => [26, 11, 14, 24, 25, 19],

    // Airport Parking tile visibility
    "airport_parking" => [26, 11, 14, 19, 24, 29, 52, 61, 80, 87, 93, 20, 43, 44, 52, 72, 74, 76, 77, 78, 94],

];
