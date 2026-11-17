<?php
// get_customer_pricing.php
session_start();

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$customer_code = $_GET['customer_code'] ?? '';

if (empty($customer_code)) {
    echo json_encode(['success' => false, 'error' => 'Customer code required']);
    exit;
}

// ------------------------------------------------------------
// Helper: build combo key based on matching rule
// - PRIME MOVER  → "TYPE|FOOTER"       (wheel_count ignored)
// - others       → "TYPE|WHEEL|FOOTER" (footer may be empty)
// ------------------------------------------------------------
function buildComboKey($truckType, $wheelCount, $footer) {
    $tt = trim((string)$truckType);
    $ft = trim((string)$footer);

    if (strcasecmp($tt, 'PRIME MOVER') === 0) {
        return $tt . '|' . $ft;
    }

    $wc = trim((string)$wheelCount);
    return $tt . '|' . $wc . '|' . $ft;
}

function isPrimeMover($truckType) {
    return strcasecmp(trim((string)$truckType), 'PRIME MOVER') === 0;
}

// ------------------------------------------------------------
// 1. Get all active pricing rows for this customer
// ------------------------------------------------------------
$pricingQuery = "
    SELECT 
        id,
        customer_code,
        full_name,
        truck_type,
        footer,
        wheel_count,
        zone_from,
        zone_to,
        rate_per_trip,
        minimum_charge,
        rate_per_km,
        rate_per_hour,
        rate_per_kg,
        rate_per_cbm,
        payment_terms,
        COALESCE(rate_per_trip, minimum_charge, 0) AS effective_unit_price
    FROM customer_pricing
    WHERE customer_code = ?
      AND (status = 'active' OR status IS NULL)
    ORDER BY truck_type, wheel_count, footer, zone_from, zone_to
";

$stmt = $conn->prepare($pricingQuery);
$stmt->bind_param('s', $customer_code);
$stmt->execute();
$result = $stmt->get_result();

$pricing = [];
while ($row = $result->fetch_assoc()) {
    $pricing[] = $row;
}
$stmt->close();

// ------------------------------------------------------------
// 2. Build distinct combos (rule-based)
// ------------------------------------------------------------
$combos       = [];
$zonesByCombo = [];
$comboMeta    = [];

foreach ($pricing as $p) {
    $tt = $p['truck_type'] ?? '';
    $wc = $p['wheel_count'] ?? '';
    $ft = $p['footer'] ?? '';

    // truck_type always required
    if ($tt === '') continue;

    $prime = isPrimeMover($tt);

    if ($prime) {
        // PRIME MOVER: footer required (wheel_count ignored)
        if ($ft === '') continue;
    } else {
        // Standard: wheel_count required; footer OPTIONAL
        if ($wc === '') continue;
    }

    $key = buildComboKey($tt, $wc, $ft);

    if (!isset($combos[$key])) {
        $combos[$key] = [
            'truck_type'     => $tt,
            'wheel_count'    => $prime ? null : (int)$wc,
            'footer'         => ($ft === '') ? null : $ft,
            'is_prime_mover' => $prime,
        ];
        $comboMeta[$key] = $combos[$key];
    }

    $zonesByCombo[$key][] = [
        'pricing_id'           => $p['id'],
        'zone_from'            => $p['zone_from'],
        'zone_to'              => $p['zone_to'],
        'rate_per_trip'        => $p['rate_per_trip'],
        'minimum_charge'       => $p['minimum_charge'],
        'rate_per_km'          => $p['rate_per_km'],
        'rate_per_hour'        => $p['rate_per_hour'],
        'rate_per_kg'          => $p['rate_per_kg'],
        'rate_per_cbm'         => $p['rate_per_cbm'],
        'payment_terms'        => $p['payment_terms'],
        'effective_unit_price' => $p['effective_unit_price'],
    ];
}

// ------------------------------------------------------------
// 3. For each combo, find matching trucks
//    - PRIME MOVER                → truck_type ONLY
//    - non-prime, footer empty    → truck_type + wheel_count
//    - non-prime, footer present  → truck_type + wheel_count + footer
// ------------------------------------------------------------
$trucksByCombo = [];

foreach ($combos as $key => $combo) {
    if (!empty($combo['is_prime_mover'])) {
        // PRIME MOVER: match truck_type ONLY
        $tq = "
            SELECT id, truck_code, plate_number, brand, model, truck_type,
                   wheel_count, footer, body_type
            FROM truck_masterlist
            WHERE truck_type = ?
              AND (status = 'active' OR status IS NULL OR status = '')
            ORDER BY plate_number
        ";
        $tstmt = $conn->prepare($tq);
        $tstmt->bind_param('s', $combo['truck_type']);
    } else if ($combo['footer'] === null) {
        // Non-prime, no footer on pricing → match by type + wheel only
        $tq = "
            SELECT id, truck_code, plate_number, brand, model, truck_type,
                   wheel_count, footer, body_type
            FROM truck_masterlist
            WHERE truck_type = ?
              AND wheel_count = ?
              AND (status = 'active' OR status IS NULL OR status = '')
            ORDER BY plate_number
        ";
        $tstmt = $conn->prepare($tq);
        $tstmt->bind_param('si', $combo['truck_type'], $combo['wheel_count']);
    } else {
        // Non-prime with footer → match all three
        $tq = "
            SELECT id, truck_code, plate_number, brand, model, truck_type,
                   wheel_count, footer, body_type
            FROM truck_masterlist
            WHERE truck_type = ?
              AND wheel_count = ?
              AND footer = ?
              AND (status = 'active' OR status IS NULL OR status = '')
            ORDER BY plate_number
        ";
        $tstmt = $conn->prepare($tq);
        $tstmt->bind_param(
            'sis',
            $combo['truck_type'],
            $combo['wheel_count'],
            $combo['footer']
        );
    }

    $tstmt->execute();
    $tres = $tstmt->get_result();

    $trucksByCombo[$key] = [];
    while ($t = $tres->fetch_assoc()) {
        $trucksByCombo[$key][] = $t;
    }
    $tstmt->close();
}

// ------------------------------------------------------------
// 4. Return everything
// ------------------------------------------------------------
echo json_encode([
    'success'         => true,
    'pricing'         => $pricing,
    'combos'          => array_values($combos),
    'combo_meta'      => $comboMeta,
    'zones_by_combo'  => $zonesByCombo,
    'trucks_by_combo' => $trucksByCombo,
]);

$conn->close();
?>