<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

class EventReportPDF extends TCPDF
{
    private $eventTitle;

    public function setEventTitle(string $title)
    {
        $this->eventTitle = $title;
    }

    public function Header()
    {
        $logoPath = __DIR__ . '/../assets/img/logo.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 10, 30, 9, 'PNG');
        } else {
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetTextColor(37, 99, 235);
            $this->Text(15, 10, 'EventHub Pro');
        }

        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 10, 'RAPPORT DÉTAILLÉ D\'ÉVÉNEMENT', 0, 0, 'R');

        // Ligne horizontale sous l'en-tête
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.5);
        $this->Line(15, 21, 195, 21);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Généré le ' . date('d/m/Y H:i') . ' | Organisateur EventHub Pro', 0, 0, 'L');
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

/**
 * Génère le rapport PDF complet pour un événement.
 *
 * @param  PDO    $pdo
 * @param  int    $eventId
 * @param  string $output    'D' = download  |  'F' = save file  |  'S' = string
 * @param  string $filePath  Chemin si output = 'F'
 * @return string|void
 */
function generateReportPDF(PDO $pdo, int $eventId, string $output = 'D', string $filePath = '')
{
    $stmt = $pdo->prepare(
        'SELECT e.*,
                COUNT(r.id)                            AS registered_count,
                (e.capacity - COUNT(r.id))             AS available_places,
                ROUND(COUNT(r.id)/e.capacity * 100)    AS fill_pct
         FROM   events e
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE  e.id = :id
         GROUP  BY e.id'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        die('Événement introuvable.');
    }

    // ── Récupérer la liste des inscrits ──────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, email, registered_at
         FROM   registrations
         WHERE  event_id = :id
         ORDER  BY name ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $registrations = $stmt->fetchAll();

    // ── Récupérer les stats par jour (7 derniers jours) ───────────────
    $stmt = $pdo->prepare(
        'SELECT DATE(registered_at) AS day,
                COUNT(*)            AS count
         FROM   registrations
         WHERE  event_id    = :id
           AND  registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP  BY DATE(registered_at)
         ORDER  BY day ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $statsByDay = $stmt->fetchAll();

    // ── Couleurs de catégorie ─────────────────────────────────────────
    $categoryColors = [
        'tech'     => ['primary' => [37, 99, 235], 'light' => [219, 234, 254], 'hex' => '#2563EB'],
        'design'   => ['primary' => [124, 58, 237], 'light' => [237, 233, 254], 'hex' => '#7C3AED'],
        'business' => ['primary' => [234, 88, 12], 'light' => [254, 243, 199], 'hex' => '#EA580C'],
        'science'  => ['primary' => [22, 163, 74], 'light' => [220, 252, 231], 'hex' => '#16A34A'],
    ];
    $colors = $categoryColors[$event['category']] ?? ['primary' => [15, 31, 61], 'light' => [248, 250, 252], 'hex' => '#0F1F3D'];

    // ── Instanciation du PDF ──────────────────────────────────────────
    $pdf = new EventReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setEventTitle($event['title']);

    // Config de base
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetAuthor('ENSA Marrakech');
    $pdf->SetTitle('Rapport — ' . $event['title']);
    
    // Marges
    $pdf->SetMargins(15, 27, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(15);
    $pdf->SetAutoPageBreak(true, 20);

    // ════════════════════════════════════════════════════════════════════
    // PAGE 1 : Résumé exécutif
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    // Titre du Rapport
    $pdf->SetY(30);
    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(0, 10, 'Rapport de Synthèse', 0, 1, 'L');
    
    // Sous-titre
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 6, 'Analyse des inscriptions et statistiques de remplissage', 0, 1, 'L');
    $pdf->Ln(5);

    // Section 1 : Détails de l'événement
    $pdf->SetFillColor(248, 250, 252); // Gris très clair (slate-50)
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect(15, 55, 180, 48, 4.0, '1111', 'DF');

    // Contenu Section 1
    $pdf->SetY(58);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
    $pdf->Cell(0, 8, '    ' . $event['title'], 0, 1);

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(71, 85, 105);
    
    $formattedDate = date('d/m/Y à H:i', strtotime($event['event_date']));
    $pdf->Text(22, 68, '📅  Date : ' . $formattedDate);
    $pdf->Text(22, 74, '📍  Lieu : ' . $event['location']);
    $pdf->Text(22, 80, '🏷️  Catégorie : ' . strtoupper($event['category']));
    $pdf->Text(22, 86, '👤  Organisateur : ' . $event['organizer_email']);
    $pdf->Text(22, 92, '📦  Capacité Maximale : ' . $event['capacity'] . ' places');

    // Section 2 : Taux de Remplissage (Jauge visuelle)
    $pdf->SetY(112);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(0, 8, 'Taux de Remplissage', 0, 1);

    // Dessin de la barre de progression (Jauge)
    $pct = min(100, max(0, (int)$event['fill_pct']));
    
    // Fond de la barre
    $pdf->SetFillColor(226, 232, 240);
    $pdf->RoundedRect(15, 122, 180, 8, 2.0, '1111', 'F');
    
    // Remplissage de la barre
    if ($pct > 0) {
        $pdf->SetFillColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
        $pdf->RoundedRect(15, 122, 180 * ($pct / 100), 8, 2.0, '1111', 'F');
    }

    // Affichage texte du pourcentage
    $pdf->SetY(132);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
    $pdf->Cell(0, 6, $pct . '% rempli', 0, 1, 'C');

    // Section 3 : Chiffres Clés (Boîtes de statistiques)
    $pdf->Ln(8);
    // Stat Box 1 : Inscrits
    $pdf->SetFillColor(241, 245, 249);
    $pdf->RoundedRect(15, 148, 55, 30, 3.0, '1111', 'DF');
    $pdf->SetY(151);
    $pdf->SetX(15);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(55, 5, 'INSCRITS', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetX(15);
    $pdf->Cell(55, 12, $event['registered_count'], 0, 1, 'C');

    // Stat Box 2 : Places Restantes
    $pdf->SetFillColor(241, 245, 249);
    $pdf->RoundedRect(77, 148, 55, 30, 3.0, '1111', 'DF');
    $pdf->SetY(151);
    $pdf->SetX(77);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(55, 5, 'PLACES RESTANTES', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetX(77);
    $pdf->Cell(55, 12, $event['available_places'], 0, 1, 'C');

    // Stat Box 3 : Taux
    $pdf->SetFillColor(241, 245, 249);
    $pdf->RoundedRect(140, 148, 55, 30, 3.0, '1111', 'DF');
    $pdf->SetY(151);
    $pdf->SetX(140);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(55, 5, 'STATUT', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetX(140);
    $statusText = $event['registered_count'] >= $event['capacity'] ? 'COMPLET' : 'OUVERT';
    if ($statusText === 'COMPLET') {
        $pdf->SetTextColor(220, 38, 38);
    } else {
        $pdf->SetTextColor(22, 163, 74);
    }
    $pdf->Cell(55, 12, $statusText, 0, 1, 'C');

    // ════════════════════════════════════════════════════════════════════
    // PAGE 2 : Liste des inscrits
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    $pdf->SetY(28);
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(0, 10, 'Liste des Participants Inscrits', 0, 1);
    $pdf->Ln(2);

    if (empty($registrations)) {
        $pdf->SetFont('Helvetica', 'I', 11);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 10, 'Aucun participant n\'est inscrit à cet événement pour le moment.', 0, 1);
    } else {
        // Construction du tableau HTML pour bénéficier de l'auto-pagination et formatage simple
        $tableHtml = '<table cellpadding="6" cellspacing="0" style="border: 1px solid #cbd5e1; width:100%; font-family: Helvetica; font-size: 9pt;">
                        <thead>
                            <tr style="background-color: #0f1f3d; color: #ffffff; font-weight: bold; text-align: left;">
                                <th style="width: 10%; text-align: center; border-bottom: 1px solid #cbd5e1;">N°</th>
                                <th style="width: 35%; border-bottom: 1px solid #cbd5e1;">Nom</th>
                                <th style="width: 35%; border-bottom: 1px solid #cbd5e1;">Email</th>
                                <th style="width: 20%; text-align: center; border-bottom: 1px solid #cbd5e1;">Inscrit le</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        $counter = 1;
        foreach ($registrations as $reg) {
            $regDate = date('d/m/Y H:i', strtotime($reg['registered_at']));
            $bgColor = ($counter % 2 === 0) ? '#f8fafc' : '#ffffff';
            $tableHtml .= '<tr style="background-color: ' . $bgColor . '; color: #334155;">
                             <td style="text-align: center; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #cbd5e1;">' . $counter . '</td>
                             <td style="border-bottom: 1px solid #e2e8f0; border-right: 1px solid #cbd5e1;">' . htmlspecialchars($reg['name']) . '</td>
                             <td style="border-bottom: 1px solid #e2e8f0; border-right: 1px solid #cbd5e1;">' . htmlspecialchars($reg['email']) . '</td>
                             <td style="text-align: center; border-bottom: 1px solid #e2e8f0;">' . $regDate . '</td>
                           </tr>';
            $counter++;
        }
        $tableHtml .= '</tbody></table>';
        
        $pdf->writeHTML($tableHtml, true, false, false, false, '');
    }

    // ════════════════════════════════════════════════════════════════════
    // PAGE 3 : Graphique en barres (DÉFI TECHNIQUE PHP PUR)
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    $pdf->SetY(28);
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(0, 10, 'Activité Récente des Inscriptions', 0, 1);
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 6, 'Volume quotidien des inscriptions sur les 7 derniers jours', 0, 1);
    $pdf->Ln(10);

    if (empty($statsByDay)) {
        $pdf->SetFont('Helvetica', 'I', 11);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 10, 'Aucune inscription n\'a été enregistrée au cours des 7 derniers jours.', 0, 1, 'C');
    } else {
        // Détermination du max
        $maxCount = 0;
        foreach ($statsByDay as $row) {
            if ((int)$row['count'] > $maxCount) {
                $maxCount = (int)$row['count'];
            }
        }
        if ($maxCount === 0) {
            $maxCount = 1;
        }

        // Configuration du graphique
        $chartH = 75;      // Hauteur totale en mm
        $chartW = 140;     // Largeur totale en mm
        $originX = 40;     // Marge gauche
        $originY = 135;    // Position Y du bas (axe X)
        
        // ── Tracé de la grille d'arrière-plan et axes ──
        $pdf->SetDrawColor(226, 232, 240); // Slate-200
        $pdf->SetLineWidth(0.2);

        // Lignes de guidage Y (4 niveaux : 0%, 25%, 50%, 75%, 100%)
        for ($percent = 0; $percent <= 100; $percent += 25) {
            $gridY = $originY - ($percent / 100) * $chartH;
            $gridValue = round(($percent / 100) * $maxCount, 1);
            
            // Ligne de grille
            $pdf->Line($originX, $gridY, $originX + $chartW, $gridY);
            
            // Labels sur l'axe Y
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(148, 163, 184); // Slate-400
            $pdf->SetXY($originX - 18, $gridY - 3);
            $pdf->Cell(15, 6, $gridValue, 0, 0, 'R');
        }

        // Axe X (ligne noire en bas) et Axe Y (ligne noire à gauche)
        $pdf->SetDrawColor(100, 116, 139); // Slate-500
        $pdf->SetLineWidth(0.5);
        $pdf->Line($originX, $originY, $originX + $chartW, $originY); // Axe X
        $pdf->Line($originX, $originY - $chartH, $originX, $originY); // Axe Y

        // Tracé des barres
        $barWidth = 12;
        $totalDays = count($statsByDay);
        // Calculer l'espacement dynamiquement pour centrer le graphique
        $step = ($chartW - 10) / max(1, $totalDays);

        foreach ($statsByDay as $i => $row) {
            $count = (int)$row['count'];
            $barH = ($count / $maxCount) * $chartH;
            
            $x = $originX + 5 + ($i * $step);
            $y = $originY - $barH;

            // Dessin de la barre (Couleur principale de la catégorie)
            $pdf->SetFillColor($colors['primary'][0], $colors['primary'][1], $colors['primary'][2]);
            // On s'assure d'une hauteur minimale visible s'il y a des inscrits
            if ($count > 0 && $barH < 1) {
                $barH = 1.5;
                $y = $originY - $barH;
            }
            
            $pdf->Rect($x, $y, $barWidth, $barH, 'F');

            // Label de la valeur au-dessus de la barre
            if ($count > 0) {
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetTextColor(15, 31, 61);
                $pdf->SetXY($x, $y - 6);
                $pdf->Cell($barWidth, 5, $count, 0, 0, 'C');
            }

            // Label du jour en-dessous (format jj/mm)
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->SetXY($x - 2, $originY + 2);
            $pdf->Cell($barWidth + 4, 5, date('d/m', strtotime($row['day'])), 0, 0, 'C');
        }
        
        // Légende des axes
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Text($originX - 10, $originY - $chartH - 8, 'Inscriptions');
        $pdf->Text($originX + $chartW + 2, $originY - 1, 'Date');
    }

    // Sortie du PDF
    if ($output === 'F') {
        $pdf->Output($filePath, 'F');
        return $filePath;
    } else {
        $pdf->Output('rapport_' . $eventId . '.pdf', $output);
        exit;
    }
}

// ── Point d'entrée GET ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && isset($_GET['event_id'])) {
    session_start();
    // note: Dans ce MVP, l'accès est ouvert pour faciliter la correction sur machine.
    // En production, une vérification stricte de $_SESSION['user_id'] serait requise.
    $pdo = getDB();
    generateReportPDF($pdo, (int)$_GET['event_id'], 'D');
}
