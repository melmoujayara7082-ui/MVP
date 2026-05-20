<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — events/register.php                         ║
 * ║  Inscription d'un participant à un événement                ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : 🔴 À compléter — Parties 2.1 + 2.2 + 4.1
 *
 * CE FICHIER REÇOIT (POST JSON) :
 *   {
 *     "event_id"  : 3,
 *     "name"      : "Yassine El Fassi",
 *     "email"     : "yassine@example.ma"
 *   }
 *
 * CE FICHIER DOIT :
 *   ✅  Vérifier que l'événement existe et n'est pas complet    (fourni)
 *   ✅  Vérifier que l'email n'est pas déjà inscrit             (fourni)
 *   🔴  Insérer l'inscription en BD avec un token unique        (à compléter)
 *   🔴  Envoyer l'email de confirmation avec ticket PDF         (à compléter)
 *   🔴  Détecter le seuil 80% et envoyer l'alerte organisateur  (à compléter)
 *   🔴  Retourner la réponse JSON appropriée                    (à compléter)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Validation basique (fournie) ──────────────────────────────────────────
$eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
$name    = isset($data['name'])     ? trim($data['name'])     : '';
$email   = isset($data['email'])    ? trim($data['email'])    : '';

if (!$eventId || !$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données manquantes ou invalides.']);
    exit;
}

try {
    $pdo = getDB();

    // ── Récupérer l'événement (fourni) ────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT e.*, 
                COUNT(r.id) AS registered_count
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE e.id = :id
         GROUP BY e.id'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Événement introuvable.']);
        exit;
    }

    // ── Vérifier capacité (fourni) ────────────────────────────────────────
    if ($event['registered_count'] >= $event['capacity']) {
        echo json_encode(['success' => false, 'error' => 'Événement complet.', 'full' => true]);
        exit;
    }

    // ── Vérifier doublon (fourni) ─────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id FROM registrations WHERE event_id = :eid AND email = :email'
    );
    $stmt->execute([':eid' => $eventId, ':email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Vous êtes déjà inscrit(e) à cet événement.']);
        exit;
    }

    // ── Insérer l'inscription ──
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO registrations (event_id, name, email, token, registered_at)
         VALUES (:event_id, :name, :email, :token, NOW())'
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':name'     => $name,
        ':email'    => $email,
        ':token'    => $token
    ]);
    $registrationId = (int)$pdo->lastInsertId();

    // ── Envoyer l'email de confirmation ──
    require_once __DIR__ . '/../mail/SendConfirmation.php';
    $emailSuccess = false;
    try {
        $emailSuccess = SendConfirmation::send($pdo, $event, $name, $email, $token);
    } catch (Exception $e) {
        error_log('[EventHub] Confirmation email failed: ' . $e->getMessage());
    }

    // ── Détecter le seuil 80% et envoyer l'alerte organisateur ──
    $newCount = $event['registered_count'] + 1;
    $pct      = ($newCount / $event['capacity']) * 100;
    $alertSent = false;

    if ($pct >= 80 && (int)$event['alert_sent'] === 0) {
        require_once __DIR__ . '/../mail/AlertMailer.php';
        // On met à jour l'objet event avec la nouvelle quantité d'inscrits pour l'envoi du mail
        $event['registered_count'] = $newCount;
        $alertSent = AlertMailer::sendCapacityAlert($pdo, $event);
    }

    $isFull = $newCount >= $event['capacity'];

    // ── Retourner la réponse JSON ──
    echo json_encode([
        'success'         => true,
        'registration_id' => $registrationId,
        'token'           => $token,
        'capacity_pct'    => round($pct),
        'is_full'         => $isFull,
        'alert_sent'      => $alertSent
    ]);

} catch (PDOException $e) {
    error_log('[EventHub] register.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}
