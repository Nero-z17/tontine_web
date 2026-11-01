<?php
// includes/functions.php — Fonctions globales, sécurité et Horloge Virtuelle de test

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Détection automatique du dossier racine dans XAMPP (/djangui ou /tontine_web)
$racine_fs = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$doc_root  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$sous_chem = ($doc_root !== '' && str_starts_with($racine_fs, $doc_root))
    ? substr($racine_fs, strlen($doc_root))
    : '/djangui';
define('BASE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($sous_chem, '/'));

// ============================================================================
// HORLOGE VIRTUELLE (AVANCER / RECULER LE TEMPS DEPUIS LE FOOTER)
// ============================================================================
define('SIM_CLOCK_FILE', __DIR__ . '/sim_clock.json');

/**
 * Retourne le décalage en secondes stocké dans includes/sim_clock.json
 * (Persiste même si on se déconnecte/reconnecte avec un autre membre).
 */
function obtenir_decalage_temps(): int {
    if (!file_exists(SIM_CLOCK_FILE)) {
        return 0;
    }
    $data = json_decode((string)@file_get_contents(SIM_CLOCK_FILE), true);
    return isset($data['offset_seconds']) ? (int)$data['offset_seconds'] : 0;
}

/**
 * Enregistre un nouveau décalage temporel en secondes.
 */
function sauvegarder_decalage_temps(int $offset_seconds): void {
    @file_put_contents(SIM_CLOCK_FILE, json_encode([
        'offset_seconds' => $offset_seconds,
        'updated_at'     => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT));
}

/**
 * Retourne le timestamp UNIX actif (réel + décalage simulé).
 */
function temps_actuel(): int {
    return time() + obtenir_decalage_temps();
}

/**
 * Remplace date() pour toutes les comparaisons métier en PHP.
 */
function date_actuelle(string $format = 'Y-m-d'): string {
    return date($format, temps_actuel());
}

/**
 * Synchronise MySQL (CURDATE / NOW) et met à jour les statuts des cotisations
 * et des pénalités selon la date simulée active.
 */
function synchroniser_horloge_et_retards(PDO $pdo): void {
    $ts = temps_actuel();

    // 1. Aligner l'horloge de la session MySQL (CURDATE(), NOW(), CURRENT_TIMESTAMP)
    $pdo->exec("SET SESSION timestamp = " . (int)$ts);

    // 2. Si on a avancé le temps : passer en 'en_retard' les cotisations échues non soldées
    $pdo->exec("
        UPDATE contributions
        SET statut = 'en_retard'
        WHERE montant_paye < montant_attendu
          AND date_echeance < CURDATE()
          AND statut != 'en_retard'
    ");

    // 3. Si on a reculé le temps : remettre en 'en_attente' ou 'partiel' les cotisations futures
    $pdo->exec("
        UPDATE contributions
        SET statut = CASE WHEN montant_paye > 0 THEN 'partiel' ELSE 'en_attente' END
        WHERE montant_paye < montant_attendu
          AND date_echeance >= CURDATE()
          AND statut = 'en_retard'
    ");

    // 4. Si on a reculé le temps : supprimer les pénalités auto-générées 'en_attente' dont l'échéance est redevenue future
    $pdo->exec("
        DELETE p FROM penalties p
        INNER JOIN contributions co ON co.id = p.contribution_id
        WHERE p.statut = 'en_attente'
          AND p.type_operation = 'penalite'
          AND co.date_echeance >= CURDATE()
    ");

    // 5. Générer automatiquement les pénalités en_attente pour les cotisations en retard
    $stmtRetards = $pdo->query("
        SELECT co.id AS contribution_id,
               co.cycle_member_id,
               co.numero_seance,
               co.date_echeance,
               co.montant_attendu,
               c.montant_penalite_retard,
               t.admin_id
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        INNER JOIN cycles c ON c.id = cm.cycle_id
        INNER JOIN tontines t ON t.id = c.tontine_id
        WHERE c.statut = 'actif'
          AND co.date_echeance < CURDATE()
          AND co.montant_paye < co.montant_attendu
          AND c.montant_penalite_retard > 0
    ");

    if ($stmtRetards) {
        $stmtCheckPen = $pdo->prepare("SELECT id FROM penalties WHERE contribution_id = ? AND type_operation = 'penalite' LIMIT 1");
        $stmtInsPen   = $pdo->prepare("
            INSERT INTO penalties (cycle_member_id, contribution_id, type_operation, montant, motif, statut, date_operation, auteur_id)
            VALUES (?, ?, 'penalite', ?, ?, 'en_attente', NOW(), ?)
        ");

        foreach ($stmtRetards->fetchAll() as $ret) {
            $stmtCheckPen->execute([(int)$ret['contribution_id']]);
            if (!$stmtCheckPen->fetchColumn()) {
                $motif = sprintf(
                    'Retard sur la cotisation de la séance %d (échéance du %s)',
                    (int)$ret['numero_seance'],
                    date('d/m/Y', strtotime($ret['date_echeance']))
                );
                $stmtInsPen->execute([
                    (int)$ret['cycle_member_id'],
                    (int)$ret['contribution_id'],
                    (float)$ret['montant_penalite_retard'],
                    $motif,
                    (int)$ret['admin_id']
                ]);
            }
        }
    }
}

// ============================================================================
// FONCTIONS STANDARDS DU PROJET
// ============================================================================

// 1. Protéger l'affichage HTML contre les failles XSS
function e($texte) {
    return htmlspecialchars((string)$texte, ENT_QUOTES, 'UTF-8');
}

// 2. Afficher proprement un montant en FCFA (ex: 50 000 FCFA)
function format_fcfa($montant) {
    return number_format((float)$montant, 0, ',', ' ') . ' FCFA';
}

// 3. Enregistrer un message d'alerte (vert = success, rouge = danger)
function message_flash($type, $texte) {
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_texte'] = $texte;
}

// 4. Vérifier que l'utilisateur est connecté (pour l'Espace Membre)
function verifier_connexion() {
    if (empty($_SESSION['user_id'])) {
        message_flash('warning', 'Veuillez vous connecter pour accéder à cette page.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

// 5. Vérifier que l'utilisateur est bien la Secrétaire / Admin (pour l'Espace Admin)
function verifier_admin() {
    verifier_connexion();
    if ($_SESSION['role'] !== 'admin') {
        message_flash('danger', 'Accès refusé : cette page est réservée à l\'administrateur de la tontine.');
        header('Location: ' . BASE_URL . '/member/dashboard.php');
        exit;
    }
}

// 6. Protection CSRF simple pour les formulaires POST
function champ_csrf() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

function verifier_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token_recu = $_POST['csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || $token_recu !== $_SESSION['csrf_token']) {
            die('Erreur de sécurité (CSRF). Veuillez recharger la page et réessayer.');
        }
    }
}

// ============================================================================
// INTERCEPTION DES BOUTONS DE TEMPS DU FOOTER (POST) & SYNCHRONISATION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['djangui_time_action'])) {
    verifier_csrf();
    $time_action = $_POST['djangui_time_action'];
    $ts_courant  = temps_actuel();

    if ($time_action === 'reset') {
        sauvegarder_decalage_temps(0);
        $nouveau_ts = time();
    } elseif ($time_action === 'goto_date' && !empty($_POST['target_date'])) {
        $cible_ts = strtotime($_POST['target_date'] . ' ' . date('H:i:s'));
        if ($cible_ts !== false) {
            sauvegarder_decalage_temps($cible_ts - time());
            $nouveau_ts = $cible_ts;
        } else {
            $nouveau_ts = $ts_courant;
        }
    } else {
        // Exemples de valeurs : "+1 day", "+7 days", "+15 days", "+1 month", "-7 days", "-1 month"
        $modif = match ($time_action) {
            '-1m'  => '-1 month',
            '-15d' => '-15 days',
            '-7d'  => '-7 days',
            '-1d'  => '-1 day',
            '+1d'  => '+1 day',
            '+7d'  => '+7 days',
            '+15d' => '+15 days',
            '+1m'  => '+1 month',
            default => ''
        };
        if ($modif !== '') {
            $nouveau_ts = strtotime($modif, $ts_courant);
            sauvegarder_decalage_temps($nouveau_ts - time());
        } else {
            $nouveau_ts = $ts_courant;
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        synchroniser_horloge_et_retards($pdo);
    }

    message_flash('info', 'Horloge de simulation réglée au : ' . date('d/m/Y', $nouveau_ts));
    $redirection = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php');
    header('Location: ' . $redirection);
    exit;
}

if (isset($pdo) && $pdo instanceof PDO) {
    synchroniser_horloge_et_retards($pdo);
}