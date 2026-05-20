<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — api/events.php                              ║
 * ║  Endpoint AJAX — Liste et recherche des événements          ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ⚠️ Partiel — Partie 1.3 + Partie 4.1
 *
 * FOURNI :
 *   ✅  Structure de réponse JSON
 *   ✅  Lecture des paramètres GET/POST
 *   ✅  Requête de base sans filtre
 *
 * À COMPLÉTER :
 *   🔴  searchEvents() — filtres dynamiques combinables (Partie 1.3)
 *   🔴  Pagination des résultats
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

// ── Lecture des paramètres (GET ou POST JSON) ─────────────────────────────
$params = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = file_get_contents('php://input');
    $params = json_decode($body, true) ?? [];
} else {
    $params = $_GET;
}

$keyword  = isset($params['keyword'])  ? trim($params['keyword'])   : '';
$category = isset($params['category']) ? trim($params['category'])  : '';
$dateFrom = isset($params['date_from'])? trim($params['date_from']) : '';
$dateTo   = isset($params['date_to'])  ? trim($params['date_to'])   : '';
$hasPlaces= isset($params['has_places'])? (bool)$params['has_places']: false;
$page     = isset($params['page'])     ? max(1, (int)$params['page']): 1;
$perPage  = 6;
$tab      = isset($params['tab'])      ? trim($params['tab'])       : 'all';
$onlyFull = ($tab === 'full');
if ($tab === 'upcoming') {
    $hasPlaces = true;
}

try {
    $pdo    = getDB();
    $result = searchEvents($pdo, $keyword, $category, $dateFrom, $dateTo, $hasPlaces, $onlyFull, $page, $perPage);

    echo json_encode([
        'success' => true,
        'data'    => $result['events'],
        'meta'    => [
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => ceil($result['total'] / $perPage),
        ]
    ]);

} catch (Exception $e) {
    error_log('[EventHub] api/events.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}


// ═════════════════════════════════════════════════════════════════════════
// TODO 1.3 — Implémenter searchEvents() avec filtres dynamiques
// ═════════════════════════════════════════════════════════════════════════

/**
 * Recherche des événements avec filtres combinables.
 *
 * CONTRAINTES OBLIGATOIRES :
 *   → Tous les filtres sont OPTIONNELS (aucun n'est requis)
 *   → Les filtres actifs se COMBINENT (AND)
 *   → Aucune concaténation SQL directe → requêtes préparées uniquement
 *   → La requête doit être construite dynamiquement selon les filtres reçus
 *
 * STRATÉGIE SUGGÉRÉE (plusieurs approches valides) :
 *   Construire un tableau $conditions[] et un tableau $bindings[]
 *   Ajouter une condition à chaque filtre actif
 *   Assembler : WHERE implode(' AND ', $conditions)
 *   Binder : execute($bindings)
 *
 * CHAMPS RETOURNÉS PAR ÉVÉNEMENT :
 *   id, title, description, event_date, location,
 *   capacity, category, organizer_email,
 *   registered_count   (COUNT via JOIN)
 *   available_places   (capacity - registered_count)
 *   fill_percentage    (arrondi à l'entier)
 *
 * @param PDO    $pdo
 * @param string $keyword     Recherche dans title et description
 * @param string $category    Filtre par catégorie exacte
 * @param string $dateFrom    Date minimum (format Y-m-d)
 * @param string $dateTo      Date maximum (format Y-m-d)
 * @param bool   $hasPlaces   Si true : exclure les événements complets
 * @param int    $page        Numéro de page (pagination)
 * @param int    $perPage     Résultats par page
 * @return array              ['events' => [...], 'total' => int]
 */
function searchEvents(
    PDO    $pdo,
    string $keyword   = '',
    string $category  = '',
    string $dateFrom  = '',
    string $dateTo    = '',
    bool   $hasPlaces = false,
    bool   $onlyFull   = false,
    int    $page      = 1,
    int    $perPage   = 6
): array {

    // ── Requête de base (fournie) ─────────────────────────────────────
    $baseSelect = "SELECT e.id,
                          e.title,
                          e.description,
                          e.event_date,
                          e.location,
                          e.capacity,
                          e.category,
                          e.organizer_email,
                          COUNT(r.id)                                  AS registered_count,
                          (e.capacity - COUNT(r.id))                   AS available_places,
                          ROUND(COUNT(r.id) / e.capacity * 100)        AS fill_percentage
                   FROM   events e
                   LEFT JOIN registrations r ON r.event_id = e.id";

    $conditions = [];
    $bindings   = [];
    $having     = [];

    // 1. Filtre par mot-clé (titre ou description)
    if ($keyword !== '') {
        $conditions[] = '(e.title LIKE :keyword OR e.description LIKE :keyword)';
        $bindings[':keyword'] = '%' . $keyword . '%';
    }

    // 2. Filtre par catégorie
    if ($category !== '') {
        $conditions[] = 'e.category = :category';
        $bindings[':category'] = $category;
    }

    // 3. Filtre par date de début
    if ($dateFrom !== '') {
        $conditions[] = 'DATE(e.event_date) >= :dateFrom';
        $bindings[':dateFrom'] = $dateFrom;
    }

    // 4. Filtre par date de fin
    if ($dateTo !== '') {
        $conditions[] = 'DATE(e.event_date) <= :dateTo';
        $bindings[':dateTo'] = $dateTo;
    }

    // 5. Filtre places disponibles (exclure les événements complets)
    if ($hasPlaces) {
        $having[] = 'COUNT(r.id) < e.capacity';
    }

    // 6. Filtre événements complets seulement
    if ($onlyFull) {
        $having[] = 'COUNT(r.id) >= e.capacity';
    }

    // Construction dynamique des clauses WHERE et HAVING
    $whereClause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $havingClause = !empty($having) ? ' HAVING ' . implode(' AND ', $having) : '';

    // ── Requête de comptage (total) pour la pagination ──
    $countSql = "SELECT COUNT(*) FROM (
        SELECT e.id
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        $whereClause
        GROUP BY e.id
        $havingClause
    ) AS sub";

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($bindings);
    $total = (int)$countStmt->fetchColumn();

    // ── Requête principale avec pagination ──
    $sql = $baseSelect . $whereClause . ' GROUP BY e.id ' . $havingClause . ' ORDER BY e.event_date ASC';
    
    // Pagination sécurisée par casting explicite en entier
    $offset = ($page - 1) * $perPage;
    $sql .= ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);
    $events = $stmt->fetchAll();

    return ['events' => $events, 'total' => $total];
}
