<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — pdf/ticket.php                              ║
 * ║  Génération du ticket PDF d'inscription                     ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Mon choix : TCPDF
 * Justification : TCPDF est une bibliothèque PHP extrêmement complète pour générer du PDF de haute précision.
 * Elle intègre un générateur de codes QR vectoriels performant sans dépendance externe, ce qui évite de créer
 * et gérer des fichiers d'images temporaires sur le serveur. Son API permet de dessiner des éléments graphiques (fond, bandes, etc.)
 * facilement pour un rendu professionnel.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Génère le ticket PDF pour une inscription donnée.
 *
 * @param  PDO    $pdo
 * @param  int    $registrationId
 * @param  string $token           Token de désinscription
 * @param  string $output          'D' = download, 'F' = save to file, 'S' = string, 'I' = inline
 * @param  string $filePath        Chemin de sauvegarde si output = 'F'
 * @return string|void             Chemin du fichier si output='F', sinon envoi direct
 */
function generateTicketPDF(
    PDO    $pdo,
    int    $registrationId,
    string $token,
    string $output   = 'D',
    string $filePath = ''
) {
    // ── Récupérer les données de l'inscription ───────────────────────
    $stmt = $pdo->prepare(
        'SELECT r.id,
                r.name,
                r.email,
                r.registered_at,
                e.id AS event_id,
                e.title,
                e.event_date,
                e.location,
                e.category,
                e.capacity,
                COUNT(reg2.id) AS registered_count
         FROM   registrations r
         JOIN   events e         ON e.id = r.event_id
         LEFT JOIN registrations reg2 ON reg2.event_id = e.id
         WHERE  r.id    = :rid
           AND  r.token = :token
         GROUP BY r.id'
    );
    $stmt->execute([':rid' => $registrationId, ':token' => $token]);
    $data = $stmt->fetch();

    if (!$data) {
        http_response_code(404);
        die('Inscription introuvable ou token invalide.');
    }

    // ── Couleurs par catégorie ─────────────────────────────────────────
    $categoryColors = [
        'tech'     => ['primary' => [37, 99, 235], 'light' => [219, 234, 254], 'hex' => '#2563EB'],
        'design'   => ['primary' => [124, 58, 237], 'light' => [237, 233, 254], 'hex' => '#7C3AED'],
        'business' => ['primary' => [234, 88, 12], 'light' => [254, 243, 199], 'hex' => '#EA580C'],
        'science'  => ['primary' => [22, 163, 74], 'light' => [220, 252, 231], 'hex' => '#16A34A'],
    ];
    $colors = $categoryColors[$data['category']] ?? ['primary' => [15, 31, 61], 'light' => [248, 250, 252], 'hex' => '#0F1F3D'];

    // Instanciation TCPDF en A5 Paysage
    $pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8', false);
    
    // Configuration du document
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetAuthor('ENSA Marrakech');
    $pdf->SetTitle('Ticket — ' . $data['title']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Marges
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    
    $pdf->AddPage();

    // ── DÉFI CRÉATIF : Design Premium ──
    // Fond général gris clair
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Rect(0, 0, 210, 148, 'F');

    // Bandeau vertical couleur de la catégorie sur la gauche
    $pdf->SetFillColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
    $pdf->Rect(0, 0, 15, 148, 'F');

    // Carte blanche principale
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(22, 10, 178, 128, 'F');

    // Ligne supérieure de couleur de la catégorie
    $pdf->SetLineWidth(1.2);
    $pdf->SetDrawColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
    $pdf->Line(22, 10, 200, 10);

    // En-tête : Logo EventHub Pro et numéro de ticket
    $logoPath = __DIR__ . '/../assets/img/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 27, 15, 35, 10, 'PNG');
    } else {
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
        $pdf->Text(27, 15, 'EventHub Pro');
    }

    // Numéro de ticket
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Text(150, 16, 'TICKET N° ' . str_pad($data['id'], 5, '0', STR_PAD_LEFT));

    // Ligne de séparation
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(27, 30, 193, 30);

    // Infos événement
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetXY(27, 34);
    $pdf->Cell(110, 10, $data['title'], 0, 1, 'L', false, '', 1);

    // Catégorie (Badge)
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
    $pdf->SetFillColor($colors['light'][0], $colors['light'][1], $colors['light'][2]);
    $pdf->SetXY(27, 45);
    $pdf->Cell(25, 5, strtoupper($data['category']), 0, 1, 'C', true);

    // Date
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(27, 53);
    $formattedDate = date('d/m/Y à H:i', strtotime($data['event_date']));
    $pdf->Cell(110, 6, '📅  ' . $formattedDate, 0, 1);

    // Lieu
    $pdf->SetXY(27, 59);
    $pdf->Cell(110, 6, '📍  ' . $data['location'], 0, 1, 'L', false, '', 1);

    // Ligne pointillée
    $pdf->SetLineStyle(array('width' => 0.5, 'cap' => 'butt', 'join' => 'miter', 'dash' => '2,2', 'color' => array(203, 213, 225)));
    $pdf->Line(27, 72, 137, 72);
    $pdf->SetLineStyle(array('width' => 0.5, 'dash' => 0, 'color' => array(226, 232, 240))); // Reset

    // Infos Participant
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Text(27, 75, 'Participant :');
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Text(27, 80, $data['name']);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Text(27, 88, 'Email :');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Text(27, 93, $data['email']);

    // QR Code vectoriel (généré directement par TCPDF)
    $qrData = $data['event_id'] . '|' . $data['id'] . '|' . $token;
    $style = array(
        'border' => 1,
        'vpadding' => 'auto',
        'hpadding' => 'auto',
        'fgcolor' => array(15, 31, 61),
        'bgcolor' => false,
        'position' => 'N'
    );
    $pdf->write2DBarcode($qrData, 'QRCODE,M', 148, 34, 42, 42, $style, 'N');
    
    // Label sous le QR Code
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetXY(144, 78);
    $pdf->Cell(50, 4, 'SCANNEZ POUR ACCÉDER', 0, 1, 'C');

    // Pied du ticket
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Line(27, 104, 193, 104);

    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetXY(27, 107);
    $pdf->Cell(166, 5, 'EventHub Pro — ENSA Marrakech · Généré le ' . date('d/m/Y H:i'), 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(220, 38, 38);
    $pdf->SetXY(27, 112);
    $unsubUrl = 'http://localhost/eventhub_mvp/events/unsubscribe.php?token=' . $token;
    $pdf->Cell(166, 5, 'Désinscription : ' . $unsubUrl, 0, 1, 'C');

    // Sortie du PDF
    if ($output === 'F') {
        $pdf->Output($filePath, 'F');
        return $filePath;
    } else {
        $pdf->Output('ticket_' . $registrationId . '.pdf', $output);
        exit;
    }
}

// ── Point d'entrée GET (téléchargement direct) ────────────────────────────
if (php_sapi_name() !== 'cli' && isset($_GET['registration_id'], $_GET['token'])) {
    $pdo = getDB();
    generateTicketPDF(
        $pdo,
        (int)$_GET['registration_id'],
        trim($_GET['token']),
        'D'
    );
}
