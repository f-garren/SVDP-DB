<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$auth->requireLogin();

header('Content-Type: application/json');

$query = sanitizeString($_GET['q'] ?? '', 100);

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$db = Database::getInstance();
$searchTerm = '%' . $query . '%';

// Optimized single query using UNION to combine both searches
$stmt = $db->query(
    "(SELECT c.id, c.customer_id, c.name, c.address, c.city, c.state, c.phone_local_number
      FROM customers c
      WHERE c.name LIKE ? OR c.customer_id LIKE ? OR c.address LIKE ? 
            OR c.phone_local_number LIKE ? OR c.city LIKE ?
      LIMIT 20)
     UNION
     (SELECT DISTINCT c.id, c.customer_id, c.name, c.address, c.city, c.state, c.phone_local_number
      FROM customers c
      INNER JOIN household_members hm ON c.id = hm.customer_id
      WHERE hm.name LIKE ?
      LIMIT 20)
     ORDER BY name
     LIMIT 20",
    [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
);

// Use array key to automatically deduplicate by id
$unique = [];
while ($row = $stmt->fetch()) {
    $unique[$row['id']] = $row;
}

echo json_encode(array_values($unique));

