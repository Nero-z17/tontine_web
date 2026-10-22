djangui/
├── ARCHITECTURE_ET_EQUIPE.md        # Ce document de référence pour les 5 membres
├── .gitignore                       # Ignore les fichiers locaux inutiles
│
├── database/
│   └── djangui_complet.sql          # Script SQL des 10 tables + données de démo
│
├── assets/
│   ├── css/
│   │   └── style.css                # Design System Bi-Thème (Clair / Sombre) verrouillé
│   ├── js/
│   │   └── script.js                # Bascule Clair/Sombre + Calculs dynamiques JS
│   └── video/
│       └── djangui.mp4              # Vidéo locale optionnelle de la page d'accueil
│
├── includes/                        # SOCLE COMMUN (Inclus dans toutes les pages)
│   ├── db.php                       # Connexion PDO MySQL ($pdo)
│   ├── functions.php                # Fonctions globales (e, format_fcfa, verifier_connexion, CSRF...)
│   ├── header.php                   # <head>, détection du thème Clair/Sombre, CDN Bootstrap 5.3
│   ├── navbar.php                   # Barre du haut + Barre mobile du bas + Alertes Flash
│   └── footer.php                   # Pied de page (.footer-djangui) + Chargement des scripts JS
│
├── admin/                           # ESPACE SECRÉTAIRE / ADMINISTRATEUR (12 pages)
│   ├── dashboard.php                # Vue globale, KPIs, bouffeur du tour, solde caisse
│   ├── tontine-info.php             # Infos générales, code d'invitation, clôture définitive
│   ├── members.php                  # Création et gestion des membres (CNI visible ici uniquement)
│   ├── cycles.php                   # Création, configuration, activation et clôture de cycles
│   ├── draws.php                    # Tirage au sort aléatoire verrouillé et suivi des pots
│   ├── contributions.php            # Suivi des cotisations par séance et reliquats
│   ├── penalties.php                # Gestion de la Caisse de réserve (pénalités & bonus)
│   ├── meetings.php                 # Programmation et publication des réunions Zoom
│   ├── reports.php                  # Bilans financiers et historique multi-cycles
│   ├── subscription.php             # Abonnement SaaS (2 mois gratuits puis 2 000 FCFA/mois)
│   ├── transfer.php                 # Passation atomique du rôle d'admin en fin de cycle
│   └── settings.php                 # Paramètres du compte administrateur
│
├── member/                          # ESPACE MEMBRE COTISANT (11 pages)
│   ├── dashboard.php                # Tableau de bord personnel du membre (ou de la secrétaire)
│   ├── tontine.php                  # Règlement et liste des membres (sans numéro de CNI)
│   ├── contributions.php            # Mes cotisations + Paiement MoMo / Orange Money / Espèces
│   ├── payments.php                 # Historique de mes paiements (Part Bouffeur / Part Caisse)
│   ├── draw-rank.php                # Mon rang de bouffeur et suivi de réception de mon pot
│   ├── calendar.php                 # Calendrier des échéances et dates de bouffe
│   ├── penalties.php                # Mes pénalités et bonus
│   ├── meetings.php                 # Réunions publiées et accès direct au lien Zoom
│   ├── card.php                     # Ma carte de membre numérique avec QR Code sécurisé
│   ├── notifications.php            # Alertes et rappels calculés dynamiquement
│   └── profile.php                  # Mon profil, mon numéro MoMo/OM et mon mot de passe
│
└── (Racine : 8 fichiers publics & authentification)
    ├── index.php                    # Page d'accueil avec vidéo Hero et présentation
    ├── register-tontine.php         # Créer une tontine + Compte Admin (lance les 2 mois gratuits)
    ├── join-tontine.php             # Rejoindre une tontine via le code d'invitation
    ├── login.php                    # Connexion sécurisée + anti-force brute
    ├── logout.php                   # Déconnexion sécurisée
    ├── forgot-password.php          # Récupération de mot de passe
    ├── pricing.php                  # Détail du tarif SaaS (2 000 FCFA/mois par tontine)
    └── about-terms.php              # Fonctionnement détaillé et Conditions d'utilisation