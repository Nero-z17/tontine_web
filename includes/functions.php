<?php
// includes/functions.php — Fonctions globales, sécurité, calcul des séances et Horloge Virtuelle

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Détection automatique du dossier racine dans XAMPP/WAMP (/djangui ou /tontine_web)
$racine_fs = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$doc_root  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$sous_chem = ($doc_root !== '' && str_starts_with($racine_fs, $doc_root))
    ? substr($racine_fs, strlen($doc_root))
    : '/djangui';
define('BASE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($sous_chem, '/'));

// ============================================================================
// 1. HORLOGE VIRTUELLE & PLANCHER DATE DE CRÉATION DE LA TONTINE (POINT 6)
// ============================================================================
define('SIM_CLOCK_FILE', __DIR__ . '/sim_clock.json');

function obtenir_decalage_temps(): int {
    if (!file_exists(SIM_CLOCK_FILE)) {
        return 0;
    }
    $data = json_decode((string)@file_get_contents(SIM_CLOCK_FILE), true);
    return isset($data['offset_seconds']) ? (int)$data['offset_seconds'] : 0;
}

function sauvegarder_decalage_temps(int $offset_seconds): void {
    @file_put_contents(SIM_CLOCK_FILE, json_encode([
        'offset_seconds' => $offset_seconds,
        'updated_at'     => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT));
}

function temps_actuel(): int {
    return time() + obtenir_decalage_temps();
}

function date_actuelle(string $format = 'Y-m-d'): string {
    return date($format, temps_actuel());
}

/**
 * Retourne le timestamp minimal autorisé pour l'horloge virtuelle :
 * la date de création (ou début d'essai) de la tontine courante (Point 6).
 */
function obtenir_timestamp_min_tontine(PDO $pdo): int {
    $tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
    if ($tontine_id > 0) {
        $stmt = $pdo->prepare("SELECT LEAST(DATE(created_at), DATE(date_debut_essai)) FROM tontines WHERE id = ? LIMIT 1");
        $stmt->execute([$tontine_id]);
        $date_min = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->query("SELECT MIN(LEAST(DATE(created_at), DATE(date_debut_essai))) FROM tontines");
        $date_min = $stmt ? $stmt->fetchColumn() : false;
    }

    if ($date_min) {
        $ts = strtotime($date_min . ' 00:00:00');
        if ($ts !== false) {
            return $ts;
        }
    }
    return strtotime('2026-01-01 00:00:00');
}

// ============================================================================
// 2. CALCUL DES DATES ET INTERVALLES DE SÉANCES (POINT 10)
// ============================================================================
/**
 * Règle Point 10 :
 * - Séance #1 : le jour même du lancement du cycle ($date_debut_cycle, ex: 01/10/2026)
 * - Séance #2 : du lendemain (02/10/2026) jusqu'à +1 période (ex: 08/10/2026 en hebdo)
 * - Séance #k : du lendemain de l'échéance #(k-1) jusqu'à l'échéance #k
 */
function calculer_echeance_seance(string $date_debut_cycle, int $numero_seance, string $frequence): string {
    if ($numero_seance <= 1) {
        return date('Y-m-d', strtotime($date_debut_cycle));
    }
    $nb_periodes = $numero_seance - 1;
    $decalage = match ($frequence) {
        'hebdo'     => '+' . ($nb_periodes * 7) . ' days',
        'bimensuel' => '+' . ($nb_periodes * 15) . ' days',
        default     => '+' . $nb_periodes . ' months',
    };
    return date('Y-m-d', strtotime($date_debut_cycle . ' ' . $decalage));
}

/**
 * Retourne l'intervalle lisible d'une séance :
 * - Séance #1 : "Le 01/10/2026 (Jour d'ouverture)"
 * - Séance #2 : "Du 02/10/2026 au 08/10/2026"
 */
function formater_periode_seance(string $date_debut_cycle, int $numero_seance, string $frequence, ?string $date_echeance = null): string {
    $ech = $date_echeance ?: calculer_echeance_seance($date_debut_cycle, $numero_seance, $frequence);
    if ($numero_seance <= 1) {
        return 'Le ' . date('d/m/Y', strtotime($ech));
    }
    $ech_precedente = calculer_echeance_seance($date_debut_cycle, $numero_seance - 1, $frequence);
    $debut_seance   = date('d/m/Y', strtotime($ech_precedente . ' +1 day'));
    return 'Du ' . $debut_seance . ' au ' . date('d/m/Y', strtotime($ech));
}

// ============================================================================
// 3. VERROUILLAGE SÉQUENTIEL DES SÉANCES (POINT 1)
// ============================================================================
/**
 * Détermine le numéro de la séance maximale actuellement ouverte aux cotisations.
 * Une séance N (N > 1) n'est ouverte que si la séance N-1 est clôturée :
 * c'est-à-dire que tous les membres ont soldé la séance N-1 OU que la date limite
 * de la séance N-1 est dépassée (la séance N a commencé).
 */
function obtenir_seance_ouverte_max(PDO $pdo, int $cycle_id): int {
    $stmt = $pdo->prepare("
        SELECT co.numero_seance,
               MIN(co.date_echeance) AS date_echeance,
               SUM(CASE WHEN co.cycle_member_id != co.beneficiaire_cycle_member_id AND co.montant_paye < co.montant_attendu THEN 1 ELSE 0 END) AS nb_impayes
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
        GROUP BY co.numero_seance
        ORDER BY co.numero_seance ASC
    ");
    $stmt->execute([$cycle_id]);
    $seances = $stmt->fetchAll();

    if (empty($seances)) {
        return 1;
    }

    $aujourdhui = date_actuelle('Y-m-d');
    $seance_ouverte = 1;

    foreach ($seances as $s) {
        $num = (int)$s['numero_seance'];
        $ech = $s['date_echeance'];
        $impayes = (int)$s['nb_impayes'];

        $seance_ouverte = $num;

        // La séance courante est-elle clôturée (100% payée OU échéance passée) ?
        $est_cloturee = ($impayes === 0) || (strtotime($aujourdhui) > strtotime($ech));
        if (!$est_cloturee) {
            // Tant que cette séance n'est pas clôturée, les séances suivantes restent verrouillées
            break;
        }
    }

    return $seance_ouverte;
}

// ============================================================================
// 4. SYNCHRONISATION HORLOGE, RETARDS ET AUTO-COMPENSATION DU BOUFFEUR (POINT 5)
// ============================================================================
function synchroniser_horloge_et_retards(PDO $pdo): void {
    $ts = temps_actuel();

    // 1. Aligner l'horloge de la session MySQL (CURDATE(), NOW(), CURRENT_TIMESTAMP)
    $pdo->exec("SET SESSION timestamp = " . (int)$ts);

    // 2. Si on a reculé le temps : remettre en 'en_attente' ou 'partiel' les cotisations futures
    $pdo->exec("
        UPDATE contributions
        SET statut = CASE WHEN montant_paye > 0 THEN 'partiel' ELSE 'en_attente' END
        WHERE montant_paye < montant_attendu
          AND date_echeance >= CURDATE()
          AND statut = 'en_retard'
    ");

    // 3. Si on a reculé le temps : supprimer les pénalités auto-générées 'en_attente' dont l'échéance est redevenue future
    $pdo->exec("
        DELETE p FROM penalties p
        INNER JOIN contributions co ON co.id = p.contribution_id
        WHERE p.statut = 'en_attente'
          AND p.type_operation = 'penalite'
          AND co.date_echeance >= CURDATE()
    ");

    // 4. POINT 5A : Le bouffeur d'une séance ne cotise pas en espèces/MoMo pour lui-même
    //    et ne subit jamais de retard ni de pénalité sur sa propre séance.
    //    Pour toute séance ouverte, sa propre part est automatiquement compensée sur son pot.
    $stmtCyclesActifs = $pdo->query("SELECT id, tontine_id FROM cycles WHERE statut = 'actif'");
    $cycles_actifs = $stmtCyclesActifs ? $stmtCyclesActifs->fetchAll() : [];

    foreach ($cycles_actifs as $cyc) {
        $cid = (int)$cyc['id'];
        $tid = (int)$cyc['tontine_id'];
        $max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

        // Récupérer les séances ouvertes où la propre part du bouffeur n'est pas encore marquée compensée
        $stmtAutoSelf = $pdo->prepare("
            SELECT co.id AS contribution_id,
                   co.cycle_member_id,
                   co.numero_seance,
                   co.montant_attendu,
                   co.montant_paye,
                   cm.user_id,
                   u.canal_paiement
            FROM contributions co
            INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
              AND co.cycle_member_id = co.beneficiaire_cycle_member_id
              AND co.numero_seance <= ?
              AND co.statut != 'paye'
        ");
        $stmtAutoSelf->execute([$cid, $max_ouverte]);

        foreach ($stmtAutoSelf->fetchAll() as $selfCo) {
            $reste_self = max(0.0, (float)$selfCo['montant_attendu'] - (float)$selfCo['montant_paye']);
            if ($reste_self > 0) {
                $pdo->prepare("UPDATE contributions SET montant_paye = montant_attendu, statut = 'paye' WHERE id = ?")
                    ->execute([(int)$selfCo['contribution_id']]);

                $pdo->prepare("
                    UPDATE cycle_members
                    SET montant_pot_recu = montant_pot_recu + ?,
                        pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                        date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                    WHERE id = ?
                ")->execute([$reste_self, $reste_self, $reste_self, (int)$selfCo['cycle_member_id']]);

                $ref = 'AUTO-POT-S' . (int)$selfCo['numero_seance'] . '-' . (int)$selfCo['contribution_id'];
                $pdo->prepare("
                    INSERT INTO payments (
                        contribution_id, paye_par_user_id, montant_total,
                        part_beneficiaire, part_caisse_reserve, mode_paiement,
                        reference_paiement, date_paiement, est_en_retard,
                        statut_paiement, commentaire
                    ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 0, 'valide', ?)
                ")->execute([
                    (int)$selfCo['contribution_id'],
                    (int)$selfCo['user_id'],
                    $reste_self,
                    $reste_self,
                    $selfCo['canal_paiement'] ?: 'momo',
                    $ref,
                    "Compensation automatique sur le pot (Bouffeur de la Séance #{$selfCo['numero_seance']})"
                ]);
            }
        }
    }

    // 5. Passer en 'en_retard' les cotisations échues non soldées (hors bouffeur de la séance)
    $pdo->exec("
        UPDATE contributions
        SET statut = 'en_retard'
        WHERE montant_paye < montant_attendu
          AND cycle_member_id != beneficiaire_cycle_member_id
          AND date_echeance < CURDATE()
          AND statut != 'en_retard'
    ");

    // 6. Générer automatiquement les pénalités en_attente pour les cotisations en retard (hors bouffeur)
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
          AND co.cycle_member_id != co.beneficiaire_cycle_member_id
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

    // 7. POINT 5B : Lorsque le tour de pot d'un membre arrive et qu'il avait des dettes
    //    et/ou des pénalités en attente, on les déduit directement sur son pot !
    //    (Si son pot n'est pas encore assez garni, il prélève ce qui est disponible et devra le reste après).
    foreach ($cycles_actifs as $cyc) {
        $cid = (int)$cyc['id'];
        $tid = (int)$cyc['tontine_id'];
        $max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

        // Chercher les bouffeurs dont le tour est arrivé (rang_beneficiaire <= $max_ouverte)
        $stmtBouffeurs = $pdo->prepare("
            SELECT cm.id AS cm_id, cm.user_id, cm.rang_beneficiaire, cm.montant_pot_recu, u.canal_paiement
            FROM cycle_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
              AND cm.rang_beneficiaire IS NOT NULL
              AND cm.rang_beneficiaire <= ?
              AND cm.montant_pot_recu > 0
            ORDER BY cm.rang_beneficiaire ASC
        ");
        $stmtBouffeurs->execute([$cid, $max_ouverte]);

        foreach ($stmtBouffeurs->fetchAll() as $bf) {
            $bf_cm_id   = (int)$bf['cm_id'];
            $bf_user_id = (int)$bf['user_id'];
            $bf_rang    = (int)$bf['rang_beneficiaire'];
            $bf_canal   = $bf['canal_paiement'] ?: 'momo';

            // Fonds disponibles sur son pot (hors sa propre part auto-compensée) pour éponger ses dettes/pénalités
            $stmtProprePart = $pdo->prepare("
                SELECT COALESCE(SUM(montant_paye), 0)
                FROM contributions
                WHERE cycle_member_id = ? AND beneficiaire_cycle_member_id = ?
            ");
            $stmtProprePart->execute([$bf_cm_id, $bf_cm_id]);
            $propre_part = (float)$stmtProprePart->fetchColumn();

            $pot_disponible = max(0.0, (float)$bf['montant_pot_recu'] - $propre_part);
            if ($pot_disponible <= 0) {
                continue;
            }

            // A) Déduire d'abord ses dettes de cotisations des séances précédentes (< son tour)
            $stmtDettes = $pdo->prepare("
                SELECT id, beneficiaire_cycle_member_id, numero_seance, montant_attendu, montant_paye
                FROM contributions
                WHERE cycle_member_id = ?
                  AND beneficiaire_cycle_member_id != ?
                  AND numero_seance < ?
                  AND montant_paye < montant_attendu
                ORDER BY numero_seance ASC
            ");
            $stmtDettes->execute([$bf_cm_id, $bf_cm_id, $bf_rang]);

            foreach ($stmtDettes->fetchAll() as $dette) {
                if ($pot_disponible <= 0) {
                    break;
                }
                $reste_dette = max(0.0, (float)$dette['montant_attendu'] - (float)$dette['montant_paye']);
                $prelevement = min($pot_disponible, $reste_dette);

                if ($prelevement > 0) {
                    $nouveau_paye = (float)$dette['montant_paye'] + $prelevement;
                    $nouveau_statut = ($nouveau_paye >= (float)$dette['montant_attendu']) ? 'paye' : 'partiel';

                    // 1. Mettre à jour la cotisation en retard du bouffeur
                    $pdo->prepare("UPDATE contributions SET montant_paye = ?, statut = ? WHERE id = ?")
                        ->execute([$nouveau_paye, $nouveau_statut, (int)$dette['id']]);

                    // 2. Déduire du pot du bouffeur actuel et créditer le pot de l'ancien bouffeur à qui il devait !
                    $pdo->prepare("
                        UPDATE cycle_members
                        SET montant_pot_recu = GREATEST(0, montant_pot_recu - ?),
                            pot_totalement_recu = CASE WHEN (montant_pot_recu - ?) >= montant_pot_attendu THEN 1 ELSE 0 END
                        WHERE id = ?
                    ")->execute([$prelevement, $prelevement, $bf_cm_id]);

                    $pdo->prepare("
                        UPDATE cycle_members
                        SET montant_pot_recu = montant_pot_recu + ?,
                            pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                            date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                        WHERE id = ?
                    ")->execute([$prelevement, $prelevement, $prelevement, (int)$dette['beneficiaire_cycle_member_id']]);

                    // 3. Créer le reçu de retenue sur pot dans payments
                    $ref = 'RET-DETTE-S' . $bf_rang . '-C' . (int)$dette['id'] . '-' . random_int(10, 99);
                    $pdo->prepare("
                        INSERT INTO payments (
                            contribution_id, paye_par_user_id, montant_total,
                            part_beneficiaire, part_caisse_reserve, mode_paiement,
                            reference_paiement, date_paiement, est_en_retard,
                            statut_paiement, commentaire
                        ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 1, 'valide', ?)
                    ")->execute([
                        (int)$dette['id'],
                        $bf_user_id,
                        $prelevement,
                        $prelevement,
                        $bf_canal,
                        $ref,
                        "Retenue automatique sur son pot (Tour #{$bf_rang}) pour régler sa dette de la Séance #{$dette['numero_seance']}"
                    ]);

                    $pot_disponible -= $prelevement;
                }
            }

            // B) Déduire ensuite ses pénalités en attente sur son pot et les verser dans la Caisse de réserve
            $stmtPenAttente = $pdo->prepare("
                SELECT id, contribution_id, montant, motif
                FROM penalties
                WHERE cycle_member_id = ?
                  AND type_operation = 'penalite'
                  AND statut = 'en_attente'
                ORDER BY id ASC
            ");
            $stmtPenAttente->execute([$bf_cm_id]);

            foreach ($stmtPenAttente->fetchAll() as $pen) {
                if ($pot_disponible <= 0) {
                    break;
                }
                $montant_pen = (float)$pen['montant'];
                $prelevement_pen = min($pot_disponible, $montant_pen);

                if ($prelevement_pen > 0) {
                    // Déduire du pot du bouffeur
                    $pdo->prepare("
                        UPDATE cycle_members
                        SET montant_pot_recu = GREATEST(0, montant_pot_recu - ?),
                            pot_totalement_recu = CASE WHEN (montant_pot_recu - ?) >= montant_pot_attendu THEN 1 ELSE 0 END
                        WHERE id = ?
                    ")->execute([$prelevement_pen, $prelevement_pen, $bf_cm_id]);

                    // Créditer la caisse de réserve de la tontine
                    $pdo->prepare("UPDATE tontines SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                        ->execute([$prelevement_pen, $tid]);

                    if ($prelevement_pen >= $montant_pen) {
                        // Pénalité intégralement soldée par retenue sur le pot
                        $nouveau_motif = str_contains($pen['motif'], '[Déduit sur pot]')
                            ? $pen['motif']
                            : $pen['motif'] . ' [Déduit sur pot]';
                        $pdo->prepare("UPDATE penalties SET statut = 'regle', date_reglement = NOW(), motif = ? WHERE id = ?")
                            ->execute([$nouveau_motif, (int)$pen['id']]);
                    } else {
                        // Pot insuffisant : on réduit la pénalité en attente du montant déjà prélevé (il devra le reste après)
                        $reste_pen = $montant_pen - $prelevement_pen;
                        $pdo->prepare("UPDATE penalties SET montant = ?, motif = ? WHERE id = ?")
                            ->execute([
                                $reste_pen,
                                $pen['motif'] . ' (acompte de ' . format_fcfa($prelevement_pen) . ' déduit sur pot)',
                                (int)$pen['id']
                            ]);
                    }

                    $pot_disponible -= $prelevement_pen;
                }
            }
        }
    }
}

// ============================================================================
// 5. FONCTIONS STANDARDS DE SÉCURITÉ ET D'AFFICHAGE
// ============================================================================
function e($texte) {
    return htmlspecialchars((string)$texte, ENT_QUOTES, 'UTF-8');
}

function format_fcfa($montant) {
    return number_format((float)$montant, 0, ',', ' ') . ' FCFA';
}

function message_flash($type, $texte) {
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_texte'] = $texte;
}

function verifier_connexion() {
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        message_flash('warning', 'Veuillez vous connecter pour accéder à cette page.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Audit de sécurité : synchroniser le rôle et vérifier que le compte est toujours actif
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT role, statut_membre, statut_validation, tontine_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u || $u['statut_membre'] !== 'actif' || $u['statut_validation'] !== 'accepte') {
            $_SESSION = [];
            session_destroy();
            session_start();
            message_flash('danger', 'Votre session a été fermée car votre statut de membre a changé.');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        $_SESSION['role']       = $u['role'];
        $_SESSION['tontine_id'] = (int)$u['tontine_id'];
    }
}

function verifier_admin() {
    verifier_connexion();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        message_flash('danger', 'Accès refusé : cette page est réservée à l\'administrateur de la tontine.');
        header('Location: ' . BASE_URL . '/member/dashboard.php');
        exit;
    }
}

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
// 6. INTERCEPTION DES BOUTONS DE TEMPS DU FOOTER (POST) & SYNCHRONISATION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['djangui_time_action'])) {
    verifier_csrf();
    $time_action = $_POST['djangui_time_action'];
    $ts_courant  = temps_actuel();
    $ts_min      = (isset($pdo) && $pdo instanceof PDO) ? obtenir_timestamp_min_tontine($pdo) : strtotime('2026-01-01');
    $bloque_min  = false;

    if ($time_action === 'reset') {
        sauvegarder_decalage_temps(0);
        $nouveau_ts = time();
    } elseif ($time_action === 'goto_date' && !empty($_POST['target_date'])) {
        $cible_ts = strtotime($_POST['target_date'] . ' ' . date('H:i:s'));
        if ($cible_ts !== false) {
            if ($cible_ts < $ts_min) {
                $cible_ts   = $ts_min;
                $bloque_min = true;
            }
            sauvegarder_decalage_temps($cible_ts - time());
            $nouveau_ts = $cible_ts;
        } else {
            $nouveau_ts = $ts_courant;
        }
    } else {
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
            // Point 6 : Interdiction de reculer avant la date de création de la tontine
            if ($nouveau_ts < $ts_min) {
                $nouveau_ts = $ts_min;
                $bloque_min = true;
            }
            sauvegarder_decalage_temps($nouveau_ts - time());
        } else {
            $nouveau_ts = $ts_courant;
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        synchroniser_horloge_et_retards($pdo);
    }

    if ($bloque_min) {
        message_flash('warning', 'Action bloquée : impossible de reculer à une date antérieure à la création de la tontine (' . date('d/m/Y', $ts_min) . ').');
    } else {
        message_flash('info', 'Horloge de simulation réglée au : ' . date('d/m/Y', $nouveau_ts));
    }

    $redirection = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php');
    header('Location: ' . $redirection);
    exit;
}

if (isset($pdo) && $pdo instanceof PDO) {
    synchroniser_horloge_et_retards($pdo);
}