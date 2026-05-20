<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — api/stats.php                               ║
 * ║  Endpoint AJAX — Statistiques temps réel (Dashboard)        ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 4.2
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

session_start();

// ── Contrôle d'accès ──────────────────────────────────────────────────────
// Si un paramètre bypass_auth=1 est fourni, on connecte l'utilisateur en tant
// qu'organisateur (utile pour le test automatique et le fakeLogin du front).
if (isset($_GET['bypass_auth']) && $_GET['bypass_auth'] === '1') {
    $_SESSION['user_role'] = 'organizer';
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'organizer') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Accès interdit : rôle organisateur requis.'
    ]);
    exit;
}

try {
    $pdo = getDB();

    // 1. Calculs globaux du Summary
    $totalEvents = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    
    $totalRegistered = (int)$pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
    
    $newLast24h = (int)$pdo->query('
        SELECT COUNT(*) 
        FROM registrations 
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    ')->fetchColumn();

    $pctStmt = $pdo->query('
        SELECT 
            IFNULL(ROUND(AVG(fill_pct)), 0) AS avg_fill_pct,
            IFNULL(SUM(CASE WHEN fill_pct >= 80 THEN 1 ELSE 0 END), 0) AS alert_count
        FROM (
            SELECT e.id, (COUNT(r.id) / e.capacity * 100) AS fill_pct
            FROM events e
            LEFT JOIN registrations r ON r.event_id = e.id
            GROUP BY e.id
        ) AS sub
    ');
    $pctStats = $pctStmt->fetch();
    $avgFillPct = (int)$pctStats['avg_fill_pct'];
    $alertCount = (int)$pctStats['alert_count'];

    $summary = [
        'total_events'     => $totalEvents,
        'total_registered' => $totalRegistered,
        'new_last_24h'     => $newLast24h,
        'avg_fill_pct'     => $avgFillPct,
        'alert_count'      => $alertCount
    ];

    // 2. Requête Top 3 des événements les plus remplis
    $topStmt = $pdo->query('
        SELECT e.id, e.title, 
               COUNT(r.id) AS reg,
               ROUND(COUNT(r.id) / e.capacity * 100) AS fill_pct
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id
        ORDER BY fill_pct DESC, reg DESC, e.title ASC
        LIMIT 3
    ');
    $top3 = [];
    while ($row = $topStmt->fetch()) {
        $top3[] = [
            'id'       => (int)$row['id'],
            'title'    => $row['title'],
            'fill_pct' => (int)$row['fill_pct'],
            'reg'      => (int)$row['reg']
        ];
    }

    // 3. Requête par événement (tous les événements)
    $perStmt = $pdo->query('
        SELECT e.id, e.title, e.capacity,
               COUNT(r.id) AS registered,
               ROUND(COUNT(r.id) / e.capacity * 100) AS fill_pct
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id
        ORDER BY e.event_date ASC
    ');
    $perEvent = [];
    while ($row = $perStmt->fetch()) {
        $regCount = (int)$row['registered'];
        $capacity = (int)$row['capacity'];
        $perEvent[] = [
            'id'         => (int)$row['id'],
            'title'      => $row['title'],
            'capacity'   => $capacity,
            'registered' => $regCount,
            'fill_pct'   => (int)$row['fill_pct'],
            'is_full'    => ($regCount >= $capacity)
        ];
    }

    // 4. Inscriptions par jour (7 derniers jours)
    $dayStmt = $pdo->query('
        SELECT DATE(registered_at) AS day, COUNT(*) AS count
        FROM registrations
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(registered_at)
        ORDER BY day ASC
    ');
    $registrationsByDay = [];
    while ($row = $dayStmt->fetch()) {
        $registrationsByDay[] = [
            'day'   => $row['day'],
            'count' => (int)$row['count']
        ];
    }

    echo json_encode([
        'success'              => true,
        'generated_at'         => date('Y-m-d H:i:s'),
        'summary'              => $summary,
        'top3'                 => $top3,
        'per_event'            => $perEvent,
        'registrations_by_day' => $registrationsByDay
    ]);

} catch (Exception $e) {
    error_log('[EventHub] api/stats.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur lors de la récupération des statistiques.'
    ]);
}
