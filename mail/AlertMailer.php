<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — mail/AlertMailer.php                        ║
 * ║  Email d'alerte organisateur (seuil 80%)                    ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 2.2
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../pdf/report.php';

class AlertMailer
{
    /**
     * Envoie l'email d'alerte de capacité à l'organisateur.
     *
     * @param  PDO   $pdo
     * @param  array $event   Données complètes de l'événement
     * @return bool
     */
    public static function sendCapacityAlert(PDO $pdo, array $event): bool
    {
        // ════════════════════════════════════════════════════════════════
        // TODO 2.2.A — Vérifier si l'alerte a déjà été envoyée
        // ════════════════════════════════════════════════════════════════
        //
        // SOLUTION POUR ÉVITER LES ENVOIS MULTIPLES :
        // Pour contrer les accès concurrents lorsque le seuil est dépassé par plusieurs requêtes AJAX simultanées :
        // 1. On démarre une transaction SQL.
        // 2. On verrouille la ligne correspondante de l'événement avec un verrou de lecture exclusive
        //    (SELECT FOR UPDATE), garantissant que toute autre requête concurrente doive attendre sa libération.
        // 3. On inspecte la valeur de `alert_sent`. Si elle vaut déjà 1, on annule la transaction et retourne `false`.
        // 4. Si elle vaut 0, on la passe immédiatement à 1 en base de données, on valide la transaction (COMMIT),
        //    puis on procède à la génération du PDF et à l'envoi de l'email d'alerte.
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT alert_sent, organizer_email, organizer_id, capacity FROM events WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $event['id']]);
            $dbEvent = $stmt->fetch();

            if (!$dbEvent || (int)$dbEvent['alert_sent'] === 1) {
                $pdo->rollBack();
                return false;
            }

            // Mise à jour immédiate de l'état en BD pour verrouiller
            $updateStmt = $pdo->prepare('UPDATE events SET alert_sent = 1 WHERE id = :id');
            $updateStmt->execute([':id' => $event['id']]);
            $pdo->commit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[EventHub] sendCapacityAlert transaction error: ' . $e->getMessage());
            return false;
        }

        // Récupération du nom de l'organisateur
        $organizerName = 'Organisateur';
        if ($dbEvent['organizer_id']) {
            $stmtUser = $pdo->prepare('SELECT name FROM users WHERE id = :id');
            $stmtUser->execute([':id' => $dbEvent['organizer_id']]);
            $organizerName = $stmtUser->fetchColumn() ?: 'Organisateur';
        }

        // Calculs de capacité
        $registered = (int)$event['registered_count'];
        $capacity = (int)$event['capacity'];
        $available = $capacity - $registered;
        $fillPct = round(($registered / $capacity) * 100);

        // Liens dynamiques
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/exam/MVP/';
        $dashboardLink = $baseUrl . 'dashboard.php';

        // Charger le template HTML
        $templatePath = __DIR__ . '/templates/alert.html';
        if (!file_exists($templatePath)) {
            error_log("[EventHub] Template alert.html introuvable.");
            return false;
        }

        $html = file_get_contents($templatePath);
        $html = str_replace('{{ORGANIZER_NAME}}', htmlspecialchars($organizerName), $html);
        $html = str_replace('{{EVENT_TITLE}}',      htmlspecialchars($event['title']), $html);
        $html = str_replace('{{FILL_PCT}}',         $fillPct, $html);
        $html = str_replace('{{REGISTERED}}',       $registered, $html);
        $html = str_replace('{{CAPACITY}}',         $capacity, $html);
        $html = str_replace('{{AVAILABLE}}',        $available, $html);
        $html = str_replace('{{DASHBOARD_LINK}}',   $dashboardLink, $html);
        $html = str_replace('{{YEAR}}',             date('Y'), $html);

        // ════════════════════════════════════════════════════════════════
        // TODO 2.2.B — Générer le rapport PDF en fichier temporaire
        // ════════════════════════════════════════════════════════════════
        $tempPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'report_event_' . $event['id'] . '.pdf';
        generateReportPDF($pdo, (int)$event['id'], 'F', $tempPdf);

        // ════════════════════════════════════════════════════════════════
        // TODO 2.2.C — Charger le template et envoyer l'email
        // ════════════════════════════════════════════════════════════════
        try {
            $mail = createMailer();
            $recipient = (defined('SMTP_TEST_RECEIVER') && SMTP_TEST_RECEIVER !== '') ? SMTP_TEST_RECEIVER : $dbEvent['organizer_email'];
            $mail->addAddress($recipient, $organizerName);
            $mail->Subject = '⚠️ Alerte capacité — ' . $event['title'];
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            
            if (file_exists($tempPdf)) {
                $mail->addAttachment($tempPdf, 'rapport_' . $event['id'] . '.pdf');
            }
            
            $mail->send();

            // Nettoyage
            if (file_exists($tempPdf)) {
                unlink($tempPdf);
            }

            return true;

        } catch (\Exception $e) {
            logMailError($pdo, 'capacity_alert', $dbEvent['organizer_email'], $e->getMessage());
            if (file_exists($tempPdf)) {
                unlink($tempPdf);
            }
            return false;
        }
    }
}
