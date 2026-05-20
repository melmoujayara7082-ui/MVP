-- ╔══════════════════════════════════════════════════════════════╗
-- ║  EventHub Pro — database/schema.sql                         ║
-- ║  Schéma de la base de données                               ║
-- ║  ENSA Marrakech — Examen PHP Avancé                         ║
-- ╚══════════════════════════════════════════════════════════════╝
--
-- STATUT : ⚠️ Partiel — Partie 1.1
--
-- FOURNI :
--   ✅  Table users
--   ✅  Table categories
--   ✅  Table events (structure de base)
--   ✅  Table mail_logs
--   ✅  Données de test pour users et categories
--
-- À COMPLÉTER (Partie 1.1) :
--   🔴  Table registrations         → définissez la structure optimale
--   🔴  Contraintes FK              → intégrité référentielle
--   🔴  Index de performance        → sur event_date, category_id
--   🔴  Colonne alert_sent          → dans events (pour Partie 2.2)
--   🔴  Données de test complètes   → 3+ événements, 5+ inscrits
--

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Base de données ────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS eventhub_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE eventhub_db;

-- ══════════════════════════════════════════════════════════════════════════
-- TABLE : users
-- ══════════════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150)  NOT NULL,
    email        VARCHAR(255)  NOT NULL UNIQUE,
    password     VARCHAR(255)  NOT NULL,           -- bcrypt hash
    role         ENUM('organizer', 'participant') NOT NULL DEFAULT 'participant',
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════════════
-- TABLE : categories
-- ══════════════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug         VARCHAR(50)   NOT NULL UNIQUE,    -- 'tech', 'design', etc.
    label        VARCHAR(100)  NOT NULL,
    color_primary VARCHAR(7)   NOT NULL DEFAULT '#2563EB',
    color_light   VARCHAR(7)   NOT NULL DEFAULT '#DBEAFE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════════════
-- TABLE : events
-- ══════════════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS events;
CREATE TABLE events (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255)  NOT NULL,
    description      TEXT          NOT NULL,
    event_date       DATETIME      NOT NULL,
    location         VARCHAR(255)  NOT NULL,
    capacity         SMALLINT UNSIGNED NOT NULL CHECK (capacity > 0),
    category         VARCHAR(50)   NOT NULL,
    organizer_email  VARCHAR(255)  NOT NULL,
    organizer_id     INT UNSIGNED  NULL,
    alert_sent       TINYINT(1)    NOT NULL DEFAULT 0,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_category FOREIGN KEY (category) REFERENCES categories(slug) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════════════
-- TABLE : registrations
-- ══════════════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS registrations;
CREATE TABLE registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registrations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT uq_event_email UNIQUE KEY (event_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════════════════
-- TABLE : mail_logs
-- ══════════════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS mail_logs;
CREATE TABLE mail_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          ENUM('confirmation', 'capacity_alert', 'ticket', 'other') NOT NULL,
    recipient     VARCHAR(255) NOT NULL,
    event_id      INT UNSIGNED NULL,
    error_message TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════════════════
-- INDEX DE PERFORMANCE
-- ══════════════════════════════════════════════════════════════════════════
-- Index composé sur la date de l'événement et sa catégorie.
-- Cet index est particulièrement utile car les utilisateurs vont fréquemment
-- filtrer ou trier les événements par leur date (ex: événements à venir) et
-- leur catégorie en même temps dans searchEvents(), ce qui évite un scan de table.
CREATE INDEX idx_events_date_category ON events (event_date, category);


-- ══════════════════════════════════════════════════════════════════════════
-- DONNÉES DE TEST
-- ══════════════════════════════════════════════════════════════════════════
INSERT INTO categories (slug, label, color_primary, color_light) VALUES
    ('tech',     'Tech',     '#2563EB', '#DBEAFE'),
    ('design',   'Design',   '#7C3AED', '#EDE9FE'),
    ('business', 'Business', '#EA580C', '#FEF3C7'),
    ('science',  'Science',  '#16A34A', '#DCFCE7');

-- Mot de passe : "password123" hashé avec bcrypt
INSERT INTO users (name, email, password, role) VALUES
    ('Organisateur ENSA',   'walidbouarifi@gmail.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'organizer'),
    ('Yassine El Fassi',    'yassine@example.ma', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Salma Benali',        'salma@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Mehdi Khalil',        'mehdi@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant'),
    ('Zineb Moussaoui',     'zineb@example.ma',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'participant');

INSERT INTO events (title, description, event_date, location, capacity, category, organizer_email, organizer_id) VALUES
    (
        'DevFest Marrakech 2025',
        'La grande conférence tech de Marrakech. Talks, ateliers pratiques et networking avec les professionnels du secteur.',
        '2025-09-20 09:00:00',
        'ENSA Marrakech — Grand Amphi',
        200,
        'tech',
        'walidbouarifi@gmail.com',
        1
    ),
    (
        'UX Design Workshop',
        'Atelier intensif de design UX : prototypage Figma, tests utilisateurs, design systems. Places très limitées.',
        '2025-07-28 14:00:00',
        'École Nationale des Arts, Marrakech',
        30,
        'design',
        'walidbouarifi@gmail.com',
        1
    ),
    (
        'PHP & MVC Day',
        'Journée dédiée à PHP 8.x, architecture MVC native, bonnes pratiques PDO et sécurité des applications web.',
        '2025-11-08 09:30:00',
        'ENSA Marrakech — Salle TP Informatique',
        5,
        'tech',
        'walidbouarifi@gmail.com',
        1
    );

-- Données de test pour la table registrations
INSERT INTO registrations (event_id, name, email, token) VALUES
    (1, 'Yassine El Fassi', 'yassine@example.ma', 'token_yassine_devfest_2025'),
    (1, 'Salma Benali', 'salma@example.ma', 'token_salma_devfest_2025'),
    (1, 'Mehdi Khalil', 'mehdi@example.ma', 'token_mehdi_devfest_2025'),
    (2, 'Yassine El Fassi', 'yassine@example.ma', 'token_yassine_ux_2025'),
    (3, 'Zineb Moussaoui', 'zineb@example.ma', 'token_zineb_php_mvc_day');

SET FOREIGN_KEY_CHECKS = 1;
