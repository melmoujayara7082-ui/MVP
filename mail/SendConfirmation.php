<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — mail/SendConfirmation.php                   ║
 * ║  Email de confirmation d'inscription                        ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 2.1
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../pdf/ticket.php';

class SendConfirmation
{
    /**
     * Envoie l'email de confirmation d'inscription.
     *
     * @param  PDO    $pdo
     * @param  array  $event   Données de l'événement (depuis la BD)
     * @param  string $name    Nom du participant
     * @param  string $email   Email du participant
     * @param  string $token   Token unique de désinscription
     * @return bool            true si envoi réussi, false sinon
     */
    public static function send(PDO $pdo, array $event, string $name, string $email, string $token): bool
    {
        // ── Obtenir le registration ID ──
        $stmt = $pdo->prepare('SELECT id FROM registrations WHERE event_id = :event_id AND email = :email LIMIT 1');
        $stmt->execute([':event_id' => $event['id'], ':email' => $email]);
        $registration = $stmt->fetch();
        $registrationId = $registration ? (int)$registration['id'] : 0;

        // Base URL dynamique pour les liens
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/exam/MVP/';

        // Liens
        $ticketLink = $baseUrl . 'pdf/ticket.php?registration_id=' . $registrationId . '&token=' . $token;
        $unsubscribeLink = $baseUrl . 'events/unsubscribe.php?token=' . $token;

        // Formater la date en français (indépendant des configurations de locale du serveur)
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $timestamp = strtotime($event['event_date']);
        $w = date('w', $timestamp);
        $d = date('j', $timestamp);
        $m = date('n', $timestamp);
        $y = date('Y', $timestamp);
        $time = date('H\hi', $timestamp);
        $eventDateFr = ucfirst($days[$w]) . ' ' . $d . ' ' . $months[$m] . ' ' . $y . ' à ' . $time;

        // Charger le template HTML
        $templatePath = __DIR__ . '/templates/confirmation.html';
        if (!file_exists($templatePath)) {
            logMailError($pdo, 'confirmation', $email, "Template confirmation.html introuvable.");
            return false;
        }

        $html = file_get_contents($templatePath);
        $html = str_replace('{{PARTICIPANT_NAME}}', htmlspecialchars($name), $html);
        $html = str_replace('{{EVENT_TITLE}}',      htmlspecialchars($event['title']), $html);
        $html = str_replace('{{EVENT_DATE}}',       $eventDateFr, $html);
        $html = str_replace('{{EVENT_LOCATION}}',   htmlspecialchars($event['location']), $html);
        $html = str_replace('{{TICKET_LINK}}',      $ticketLink, $html);
        $html = str_replace('{{UNSUBSCRIBE_LINK}}', $unsubscribeLink, $html);
        $html = str_replace('{{YEAR}}',             date('Y'), $html);

        try {
            $mail = createMailer();
            $recipient = (defined('SMTP_TEST_RECEIVER') && SMTP_TEST_RECEIVER !== '') ? SMTP_TEST_RECEIVER : $email;
            $mail->addAddress($recipient, $name);
            $mail->Subject = 'Confirmation d\'inscription — ' . $event['title'];
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html); // Version texte brut

            // Générer le ticket PDF temporaire pour la pièce jointe
            $tempDir = sys_get_temp_dir();
            $pdfFileName = 'ticket_' . $registrationId . '_' . substr($token, 0, 8) . '.pdf';
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . $pdfFileName;

            // Appel de generateTicketPDF en mode sauvegarde fichier ('F')
            generateTicketPDF($pdo, $registrationId, $token, 'F', $pdfPath);

            if (file_exists($pdfPath)) {
                $mail->addAttachment($pdfPath, 'ticket_' . str_pad($registrationId, 5, '0', STR_PAD_LEFT) . '.pdf');
            }

            $mail->send();

            // Nettoyage du fichier temporaire après l'envoi
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }

            return true;

        } catch (\Exception $e) {
            logMailError($pdo, 'confirmation', $email, $e->getMessage());
            return false;
        }
    }
}
