-- =====================================================================
--  Migration — introduction des comptes bancaires
--  2026-09 · fait passer fi_cards.issuer (texte libre) à
--            fi_cards.bank_id (relation vers fi_banks)
-- =====================================================================
--  À n'exécuter QUE sur une installation existante, créée avec une
--  version antérieure du schéma. Une base neuve importe directement
--  db/schema.sql, qui contient déjà la nouvelle structure.
--
--  ⚠️  SAUVEGARDEZ LA BASE AVANT : la dernière instruction supprime
--      définitivement la colonne « issuer ».
--
--  Les banques sont créées à partir des valeurs distinctes d'« issuer »
--  et rattachées par défaut à « Deus Communications ». La société de
--  chacune est à corriger ensuite depuis l'écran Banques.
-- =====================================================================

-- Sans cette ligne, les chaînes accentuées de ce fichier (« Banque à
-- préciser ») sont interprétées en latin1 et arrivent corrompues en
-- base. schema.sql la contient déjà, ce fichier l'oubliait.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. La table des comptes bancaires
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fi_banks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL                    COMMENT 'Nom de la banque : Qonto, BNP Paribas, Revolut...',
  `company`    VARCHAR(60)  NOT NULL                    COMMENT 'Société titulaire du compte, validée côté PHP',
  `notes`      TEXT         DEFAULT NULL                COMMENT 'Commentaire libre : agence, conseiller, IBAN partiel...',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_banks_name_company` (`name`, `company`),
  KEY `idx_banks_company` (`company`),
  CONSTRAINT `fk_fi_banks_created_by` FOREIGN KEY (`created_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fi_banks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `fi_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comptes bancaires, rattachés à une société du groupe';

-- ---------------------------------------------------------------------
-- 2. La colonne de rattachement, temporairement facultative
-- ---------------------------------------------------------------------
ALTER TABLE `fi_cards`
  ADD COLUMN `bank_id` INT UNSIGNED DEFAULT NULL AFTER `expires_on`;

-- ---------------------------------------------------------------------
-- 3. Une banque par émetteur distinct déjà saisi
-- ---------------------------------------------------------------------
INSERT INTO `fi_banks` (`name`, `company`)
SELECT DISTINCT TRIM(`issuer`), 'deus_communications'
  FROM `fi_cards`
 WHERE `issuer` IS NOT NULL AND TRIM(`issuer`) <> '';

-- ---------------------------------------------------------------------
-- 4. Une banque de repli pour les cartes sans émetteur renseigné
-- ---------------------------------------------------------------------
INSERT INTO `fi_banks` (`name`, `company`)
SELECT 'Banque à préciser', 'deus_communications' FROM DUAL
 WHERE EXISTS (
     SELECT 1 FROM `fi_cards` WHERE `issuer` IS NULL OR TRIM(`issuer`) = ''
 );

-- ---------------------------------------------------------------------
-- 5. Rattachement des cartes
-- ---------------------------------------------------------------------
UPDATE `fi_cards` c
  JOIN `fi_banks` b ON b.`name` = TRIM(c.`issuer`)
   SET c.`bank_id` = b.`id`
 WHERE c.`issuer` IS NOT NULL AND TRIM(c.`issuer`) <> '';

UPDATE `fi_cards` c
  JOIN `fi_banks` b ON b.`name` = 'Banque à préciser'
   SET c.`bank_id` = b.`id`
 WHERE c.`bank_id` IS NULL;

-- ---------------------------------------------------------------------
-- 6. Verrouillage de la relation
-- ---------------------------------------------------------------------
ALTER TABLE `fi_cards`
  MODIFY COLUMN `bank_id` INT UNSIGNED NOT NULL COMMENT 'Compte bancaire dont dépend la carte (référence fi_banks)';

ALTER TABLE `fi_cards`
  ADD KEY `idx_cards_bank` (`bank_id`);

ALTER TABLE `fi_cards`
  ADD CONSTRAINT `fk_fi_cards_bank` FOREIGN KEY (`bank_id`) REFERENCES `fi_banks` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 7. Suppression de l'ancienne colonne
-- ---------------------------------------------------------------------
ALTER TABLE `fi_cards` DROP COLUMN `issuer`;
