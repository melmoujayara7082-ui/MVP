<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — events/create.php                           ║
 * ║  Création d'un événement                                    ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : 🔴 À compléter — Partie 1.2
 *
 * Ce fichier reçoit les données du formulaire (POST JSON)
 * et les insère en base de données.
 *
 * BUGS INTENTIONNELS À CORRIGER (Partie 1.2) :
 *   ❌  Injection SQL directe dans createEvent()
 *   ❌  Aucune validation des données entrantes
 *   ❌  Retour toujours true même en cas d'échec
 *   ❌  Aucune gestion d'exception PDO
 *
 * À IMPLÉMENTER :
 *   ✅  Corriger createEvent() avec requêtes préparées
 *   ✅  Valider et assainir les données reçues
 *   ✅  Retourner une vraie réponse JSON success/error
 *   ✅  Brancher l'appel fetch() depuis assets/js/app.js
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../config/db.php';

// ── Point d'entrée ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// Lecture du body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données JSON invalides.']);
    exit;
}

// ── Validation des champs obligatoires (Partie 1.2)
$required = ['title', 'description', 'date', 'location', 'capacity', 'category', 'organizer_email'];
foreach ($required as $field) {
    if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => "Le champ '$field' est requis et ne doit pas être vide."
        ]);
        exit;
    }
}

// Validation du format d'email
$email = filter_var($data['organizer_email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "L'adresse email de l'organisateur est invalide."
    ]);
    exit;
}

// Validation de la capacité
$capacity = filter_var($data['capacity'], FILTER_VALIDATE_INT);
if ($capacity === false || $capacity <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "La capacité doit être un nombre entier positif."
    ]);
    exit;
}

// Validation de la catégorie
$allowed_categories = ['tech', 'design', 'business', 'science'];
if (!in_array($data['category'], $allowed_categories, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "La catégorie est invalide. Les valeurs possibles sont : " . implode(', ', $allowed_categories)
    ]);
    exit;
}

// Validation de la date
$event_time = strtotime($data['date']);
if ($event_time === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "Le format de la date est invalide."
    ]);
    exit;
}

try {
    $pdo    = getDB();
    $result = createEvent($pdo, $data);

    echo json_encode([
        'success'  => true,
        'event_id' => $result,
        'message'  => 'Événement créé avec succès.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ═════════════════════════════════════════════════════════════════════════
// FONCTION PRINCIPALE — CORRIGÉE (Partie 1.2)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Insère un nouvel événement en base de données.
 *
 * Corrections apportées :
 *   1. Utilisation de requêtes préparées avec des paramètres nommés (:title, etc.)
 *      au lieu de la concaténation directe, ce qui neutralise complètement les injections SQL.
 *   2. Remplacement de query() par prepare() + execute() pour déléguer la compilation de la requête
 *      au serveur SQL avant d'injecter les valeurs de manière sécurisée.
 *   3. Changement du type de retour pour renvoyer le lastInsertId() (l'identifiant unique généré),
 *      ce qui permet à l'appelant de connaître la clé de l'événement inséré, plutôt que de toujours renvoyer true.
 *   4. Ajout d'une gestion des erreurs PDO (si l'exécution échoue, une exception est levée).
 *
 * @param  PDO   $pdo
 * @param  array $data  Données validées issues du formulaire
 * @return int          Identifiant de l'événement nouvellement créé
 * @throws Exception    Si l'insertion échoue
 */
function createEvent(PDO $pdo, array $data): int
{
    $sql = "INSERT INTO events (title, description, event_date, location, capacity, category, organizer_email, alert_sent, created_at)
            VALUES (:title, :description, :event_date, :location, :capacity, :category, :organizer_email, 0, NOW())";

    $stmt = $pdo->prepare($sql);
    
    $success = $stmt->execute([
        ':title'           => htmlspecialchars(trim($data['title'])),
        ':description'     => htmlspecialchars(trim($data['description'])),
        ':event_date'      => date('Y-m-d H:i:s', strtotime($data['date'])),
        ':location'        => htmlspecialchars(trim($data['location'])),
        ':capacity'        => (int)$data['capacity'],
        ':category'        => trim($data['category']),
        ':organizer_email' => trim($data['organizer_email'])
    ]);

    if (!$success) {
        throw new Exception("L'insertion de l'événement a échoué en base de données.");
    }

    return (int)$pdo->lastInsertId();
}
