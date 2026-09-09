-- =====================================================================
--  DEUS-DAF — Back office de suivi des cartes bancaires et abonnements
--  Schéma de base de données — MySQL 8.0+ / MariaDB 10.6+
--  Encodage : utf8mb4_unicode_ci — Moteur : InnoDB
-- =====================================================================
--  Convention : tous les identifiants et noms de colonnes sont en
--  anglais ; les commentaires sont en français.
--
--  PRÉFIXE « fi_ » : toutes les tables de cette application le portent,
--  afin de cohabiter sans ambiguïté avec d'autres tables dans la même
--  base. Les contraintes de clé étrangère et les CHECK sont préfixées
--  elles aussi (fk_fi_…, chk_fi_…) car, dans InnoDB, leurs noms sont
--  uniques à l'échelle de la BASE et non de la table.
--
--  ATTENTION : ce script commence par des DROP TABLE. Ils ne visent que
--  les tables fi_* et ne touchent à rien d'autre, mais réexécuter ce
--  fichier sur une base en service EFFACE toutes les données de
--  l'application. Pour une simple mise à jour, écrire un script de
--  migration dédié.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Table : fi_users
-- Utilisateurs de l'outil. Sert aussi de référentiel pour les titulaires
-- de carte et les référents de service. Suppression interdite : on
-- désactive (is_active = 0).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_users`;
CREATE TABLE `fi_users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`     VARCHAR(80)  NOT NULL                COMMENT 'Prénom',
  `last_name`      VARCHAR(80)  NOT NULL                COMMENT 'Nom de famille',
  `email`          VARCHAR(190) NOT NULL                COMMENT 'Email, identifiant de connexion (unique)',
  `password_hash`  VARCHAR(255) NOT NULL                COMMENT 'Hash password_hash() : Argon2id si dispo, bcrypt sinon',
  `role`           ENUM('admin','member') NOT NULL DEFAULT 'member'
                                                        COMMENT 'Rôle applicatif : admin gère utilisateurs et réglages',
  `staff_type`     ENUM('director','collaborator') NOT NULL DEFAULT 'collaborator'
                                                        COMMENT 'Position dans la structure : dirigeant ou collaborateur',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1      COMMENT 'Soft delete : 0 = compte désactivé, connexion refusée',
  `theme`          ENUM('auto','light','dark') NOT NULL DEFAULT 'auto'
                                                        COMMENT 'Préférence de thème persistée sur le profil',
  `totp_secret`    VARCHAR(64)  DEFAULT NULL            COMMENT 'Réservé 2FA (v2) — non utilisé en v1',
  `totp_enabled`   TINYINT(1)   NOT NULL DEFAULT 0      COMMENT 'Réservé 2FA (v2) — non utilisé en v1',
  `last_login_at`  DATETIME     DEFAULT NULL            COMMENT 'Dernière connexion réussie (heure locale, voir app.timezone)',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by`     INT UNSIGNED DEFAULT NULL            COMMENT 'Auteur de la création (NULL = script d''installation)',
  `updated_by`     INT UNSIGNED DEFAULT NULL            COMMENT 'Auteur de la dernière modification',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_active` (`is_active`),
  KEY `idx_users_name` (`last_name`, `first_name`),
  CONSTRAINT `fk_fi_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fi_users_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fi_users_email` CHECK (`email` LIKE '%_@_%._%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Utilisateurs de l''outil, titulaires de carte et référents';

-- ---------------------------------------------------------------------
-- Table : fi_banks
-- Comptes bancaires de la structure. Une banque appartient à une des
-- sociétés du groupe et porte une ou plusieurs cartes.
--
-- « company » est un VARCHAR et non un ENUM : la liste des sociétés est
-- tenue côté PHP (fonction companies() dans inc_metier.php) et validée
-- à l'écriture. Ajouter une société est ainsi une ligne de PHP, sans
-- ALTER TABLE sur une base partagée avec d'autres applications.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_banks`;
CREATE TABLE `fi_banks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL                    COMMENT 'Nom de la banque : Qonto, BNP Paribas, Revolut...',
  `company`    VARCHAR(60)  NOT NULL                    COMMENT 'Société titulaire du compte, validée côté PHP',
  `notes`      TEXT         DEFAULT NULL                COMMENT 'Commentaire libre : agence, conseiller, IBAN partiel...',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- Une même banque peut servir plusieurs sociétés : c'est le couple
  -- qui doit être unique, pas le nom seul.
  UNIQUE KEY `uq_banks_name_company` (`name`, `company`),
  KEY `idx_banks_company` (`company`),
  CONSTRAINT `fk_fi_banks_created_by` FOREIGN KEY (`created_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fi_banks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comptes bancaires, rattachés à une société du groupe';

-- ---------------------------------------------------------------------
-- Table : fi_cards
-- Cartes bancaires de la structure, rattachées à un compte bancaire.
-- CONFORMITÉ PCI DSS : aucune colonne ne peut recevoir un PAN complet
-- ni un CVV/CVC. Seuls les 4 derniers chiffres sont stockés, contraints
-- à exactement 4 caractères numériques.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_cards`;
CREATE TABLE `fi_cards` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`       VARCHAR(120) NOT NULL                   COMMENT 'Libellé lisible, ex. « CB Pro Qonto Mickael »',
  `last4`       CHAR(4)      NOT NULL                   COMMENT '4 derniers chiffres UNIQUEMENT — jamais le numéro complet',
  `expires_on`  DATE         NOT NULL                   COMMENT 'Dernier jour du mois d''expiration : la carte reste valable jusqu''à cette date incluse',
  `bank_id`     INT UNSIGNED NOT NULL                   COMMENT 'Compte bancaire dont dépend la carte (référence fi_banks)',
  `holder_id`   INT UNSIGNED NOT NULL                   COMMENT 'Titulaire de la carte (référence users)',
  `type`        ENUM('debit','credit','virtual','prepaid') NOT NULL DEFAULT 'debit'
                                                        COMMENT 'Débit / crédit / virtuelle / prépayée',
  `status`      ENUM('active','cancelled') NOT NULL DEFAULT 'active'
                                                        COMMENT 'Statut SAISI. « expirée » n''est jamais stocké : il est calculé à la lecture depuis expires_on. « cancelled » prime sur le calcul.',
  `notes`       TEXT         DEFAULT NULL               COMMENT 'Commentaire libre',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cards_expires` (`expires_on`),
  KEY `idx_cards_holder` (`holder_id`),
  KEY `idx_cards_status` (`status`, `expires_on`),
  KEY `idx_cards_bank` (`bank_id`),
  CONSTRAINT `fk_fi_cards_bank`       FOREIGN KEY (`bank_id`)    REFERENCES `fi_banks` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fi_cards_holder`     FOREIGN KEY (`holder_id`)  REFERENCES `fi_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fi_cards_created_by` FOREIGN KEY (`created_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fi_cards_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fi_cards_last4` CHECK (`last4` REGEXP '^[0-9]{4}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cartes bancaires — 4 derniers chiffres et expiration uniquement (PCI DSS)';

-- ---------------------------------------------------------------------
-- Table : fi_services
-- Services tiers / abonnements payés par la structure.
-- monthly_cost est une colonne générée STORED : elle normalise tous les
-- montants en coût mensuel, ce qui rend les totaux et les tris triviaux
-- et impossibles à désynchroniser.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_services`;
CREATE TABLE `fi_services` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150)  NOT NULL              COMMENT 'Nom du service (obligatoire)',
  `url`             VARCHAR(500)  DEFAULT NULL          COMMENT 'URL du site, validée côté serveur (http/https uniquement)',
  `billing_cycle`   ENUM('monthly','yearly','on_demand') NOT NULL DEFAULT 'monthly'
                                                        COMMENT 'Type d''abonnement : mensuel / annuel / à la demande',
  `card_id`         INT UNSIGNED  DEFAULT NULL          COMMENT 'CB utilisée. NULL = service gratuit ou non payant',
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Montant théorique en EUR, pour un cycle de facturation',
  `monthly_cost`    DECIMAL(12,4) GENERATED ALWAYS AS (
                      CASE `billing_cycle`
                        WHEN 'monthly' THEN `amount`
                        WHEN 'yearly'  THEN `amount` / 12
                        ELSE 0
                      END
                    ) STORED                            COMMENT 'Coût mensualisé calculé automatiquement (annuel / 12, à la demande = 0)',
  `next_renewal_on` DATE          DEFAULT NULL          COMMENT 'Prochaine échéance connue (facultatif)',
  `owner_id`        INT UNSIGNED  DEFAULT NULL          COMMENT 'Utilisateur référent. NULL déclenche une alerte au tableau de bord',
  `notes`           TEXT          DEFAULT NULL          COMMENT '« À quoi ça sert » — usage interne',
  `status`          ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active'
                                                        COMMENT 'Actif / suspendu / résilié',
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by`      INT UNSIGNED  DEFAULT NULL,
  `updated_by`      INT UNSIGNED  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_services_card` (`card_id`),
  KEY `idx_services_owner` (`owner_id`),
  KEY `idx_services_status` (`status`),
  KEY `idx_services_renewal` (`next_renewal_on`),
  KEY `idx_services_updated` (`updated_at`),
  KEY `idx_services_cost` (`monthly_cost`),
  CONSTRAINT `fk_fi_services_card`       FOREIGN KEY (`card_id`)    REFERENCES `fi_cards` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fi_services_owner`      FOREIGN KEY (`owner_id`)   REFERENCES `fi_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fi_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fi_services_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fi_services_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Services tiers et abonnements, avec coût mensualisé calculé';

-- ---------------------------------------------------------------------
-- Table : fi_settings
-- Réglages applicatifs modifiables par un administrateur depuis
-- l'interface (seuils d'alerte notamment). Stockage clé/valeur simple.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_settings`;
CREATE TABLE `fi_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL                 COMMENT 'Clé technique du réglage',
  `setting_value` VARCHAR(255) NOT NULL                 COMMENT 'Valeur, toujours stockée en texte et castée à la lecture',
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`setting_key`),
  CONSTRAINT `fk_fi_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Réglages applicatifs éditables par les administrateurs';

-- ---------------------------------------------------------------------
-- Table : fi_login_attempts
-- Journal des tentatives de connexion, utilisé pour la temporisation
-- progressive (par email ET par IP). Purgé périodiquement.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_login_attempts`;
CREATE TABLE `fi_login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(190) NOT NULL                  COMMENT 'Email saisi, même inexistant',
  `ip_address`   VARBINARY(16) NOT NULL                 COMMENT 'IP au format binaire (inet_pton), IPv4 et IPv6',
  `succeeded`    TINYINT(1)   NOT NULL DEFAULT 0        COMMENT '1 = connexion réussie',
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email` (`email`, `attempted_at`),
  KEY `idx_attempts_ip` (`ip_address`, `attempted_at`),
  KEY `idx_attempts_date` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tentatives de connexion pour la limitation de débit';

-- ---------------------------------------------------------------------
-- Table : fi_activity_log
-- Journal d'activité consultable par les administrateurs.
-- entity_label est un instantané du libellé au moment de l'action, pour
-- que le journal reste lisible même après renommage ou suppression.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fi_activity_log`;
CREATE TABLE `fi_activity_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED DEFAULT NULL              COMMENT 'Auteur de l''action (NULL si compte supprimé ou action anonyme)',
  `action`       VARCHAR(40)  NOT NULL                  COMMENT 'create, update, delete, login, logout, login_failed, password_change...',
  `entity_type`  VARCHAR(40)  DEFAULT NULL              COMMENT 'user, card, service, setting',
  `entity_id`    INT UNSIGNED DEFAULT NULL              COMMENT 'Identifiant de l''entité concernée',
  `entity_label` VARCHAR(190) DEFAULT NULL              COMMENT 'Libellé figé au moment de l''action',
  `changes`		 JSON         DEFAULT NULL              COMMENT 'Diff des champs modifiés : {"champ":{"from":x,"to":y}}',
  `ip_address`   VARBINARY(16) DEFAULT NULL             COMMENT 'IP au format binaire (inet_pton)',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_date` (`created_at`),
  KEY `idx_log_user` (`user_id`, `created_at`),
  KEY `idx_log_entity` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_fi_log_user` FOREIGN KEY (`user_id`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Journal d''activité : qui a fait quoi, quand, et ce qui a changé';

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Réglages par défaut
-- ---------------------------------------------------------------------
INSERT INTO `fi_settings` (`setting_key`, `setting_value`) VALUES
  ('card_alert_warning_days', '60'),
  ('card_alert_info_days',    '90'),
  ('service_renewal_days',    '30'),
  ('session_idle_minutes',    '120'),
  ('company_name',            'Deus');
