<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

$employee_id = intval($_GET['employee_id'] ?? 0);
if (!$employee_id) {
    echo json_encode(["success" => false, "message" => "employee_id required"]);
    exit;
}

// ── 1. Old usage count (requests approved before attempt_number tracking) ──
// This table is no longer incremented on new approvals, but existing rows
// represent historically completed attempts that have no attempt_number in
// transport_services.
$stUsage = $conn->prepare("
    SELECT usage_count FROM employee_personal_vehicle_usage
    WHERE employee_id = ? LIMIT 1
");
$stUsage->bind_param("i", $employee_id);
$stUsage->execute();
$usageRow  = $stUsage->get_result()->fetch_assoc();
$stUsage->close();
$oldCount = (int)($usageRow['usage_count'] ?? 0);

// ── 2. New-system slot map from transport_services.attempt_number ──────────
// Only rows that have attempt_number set (new requests after migration).
$stNew = $conn->prepare("
    SELECT attempt_number, status
    FROM transport_services
    WHERE employee_id = ?
      AND type = 'personal'
      AND attempt_number IS NOT NULL
      AND deleted_at IS NULL
      AND status NOT IN ('REJECTED', 'CANCELLED')
    ORDER BY id ASC
");
$stNew->bind_param("i", $employee_id);
$stNew->execute();
$result = $stNew->get_result();

$slotMap = [];   // attempt_number (int) → 'pending' | 'approved'
while ($r = $result->fetch_assoc()) {
    $num = (int)$r['attempt_number'];
    $slotMap[$num] = in_array(strtoupper($r['status']), ['COMPLETED', 'APPROVED', 'ASSIGNED'])
        ? 'approved'
        : 'pending';
}
$stNew->close();

// ── 3. Slot definitions ────────────────────────────────────────────────────
$slotDefs = [
    1 => ["label" => "1st",  "discount" => "FREE",    "discount_pct" => 100, "max_days" => 2,    "car_only" => true],
    2 => ["label" => "2nd",  "discount" => "FREE",    "discount_pct" => 100, "max_days" => 2,    "car_only" => true],
    3 => ["label" => "3rd",  "discount" => "50% OFF", "discount_pct" => 50,  "max_days" => 3,    "car_only" => false],
    4 => ["label" => "4th",  "discount" => "50% OFF", "discount_pct" => 50,  "max_days" => 3,    "car_only" => false],
    5 => ["label" => "5th",  "discount" => "50% OFF", "discount_pct" => 50,  "max_days" => 3,    "car_only" => false],
    6 => ["label" => "More",  "discount" => "0%",      "discount_pct" => 0,   "max_days" => null, "car_only" => false],
];

// ── 4. Determine status for each slot ─────────────────────────────────────
// Priority:
//   a) transport_services.attempt_number entry  → exact status (pending/approved)
//   b) slot number ≤ old usage_count (slots 1-5 only) → approved (legacy data)
//   c) otherwise → available
// Slot 6 (6th+) is always re-selectable unless explicitly in slotMap.
$slots = [];
foreach ($slotDefs as $num => $def) {
    if (isset($slotMap[$num])) {
        $status = $slotMap[$num];
    } elseif ($num <= 5 && $num <= $oldCount) {
        $status = 'approved';
    } else {
        $status = 'available';
    }

    if ($num === 6 && !isset($slotMap[6])) {
        $status = 'available';
    }

    $slots[] = [
        "attempt_number" => $num,
        "label"          => $def["label"],
        "discount"       => $def["discount"],
        "discount_pct"   => $def["discount_pct"],
        "max_days"       => $def["max_days"],
        "car_only"       => $def["car_only"],
        "status"         => $status,
    ];
}

echo json_encode([
    "success" => true,
    "data"    => ["slots" => $slots],
]);
