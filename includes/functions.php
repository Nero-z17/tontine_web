<?php
// includes/functions.php — Fonctions simples de sécurité et d'affichage

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Adresse de base du projet dans XAMPP
define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . '/membre_tontine');

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