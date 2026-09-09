-- =====================================================================
--  DEUS-DAF — Jeu de données de DÉMONSTRATION
-- =====================================================================
--  ⚠️  ENVIRONNEMENT DE TEST UNIQUEMENT.
--
--  Ce fichier crée des comptes dont le mot de passe est public :
--
--        Demo2026!Deus
--
--  Ne JAMAIS l'importer sur l'instance de production. Pour une mise en
--  service réelle, importer db/schema.sql puis exécuter
--  « php bin/install.php » afin de créer le premier administrateur avec
--  un mot de passe choisi.
-- ---------------------------------------------------------------------
--  Les dates d'expiration sont calculées relativement à la date du jour
--  pour que la démonstration reste pertinente dans le temps : il y a
--  toujours une carte expirée, une qui expire bientôt, une à surveiller
--  et une sans souci.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Utilisateurs
-- ---------------------------------------------------------------------
INSERT INTO `fi_users`
    (`id`, `first_name`, `last_name`, `email`, `password_hash`, `role`, `staff_type`, `is_active`, `theme`)
VALUES
    (1, 'Mickaël', 'Haddad',  'mickael@exemple.fr', '$2y$12$jsGZfEqM5TXlTEK2/etGaerGMJtYqMGH.D1kLlS23F15bdQsz0wBu', 'admin',  'director',     1, 'auto'),
    (2, 'Sophie',  'Bernard', 'sophie@exemple.fr',  '$2y$12$jsGZfEqM5TXlTEK2/etGaerGMJtYqMGH.D1kLlS23F15bdQsz0wBu', 'admin',  'director',     1, 'dark'),
    (3, 'Karim',   'Lefèvre', 'karim@exemple.fr',   '$2y$12$jsGZfEqM5TXlTEK2/etGaerGMJtYqMGH.D1kLlS23F15bdQsz0wBu', 'member', 'collaborator', 1, 'light'),
    (4, 'Julie',   'Marchand','julie@exemple.fr',   '$2y$12$jsGZfEqM5TXlTEK2/etGaerGMJtYqMGH.D1kLlS23F15bdQsz0wBu', 'member', 'collaborator', 1, 'auto'),
    (5, 'Thomas',  'Roux',    'thomas@exemple.fr',  '$2y$12$jsGZfEqM5TXlTEK2/etGaerGMJtYqMGH.D1kLlS23F15bdQsz0wBu', 'member', 'collaborator', 0, 'auto');

-- ---------------------------------------------------------------------
-- Cartes bancaires
-- ---------------------------------------------------------------------
-- Rappel : expires_on = dernier jour du mois d'expiration.
-- LAST_DAY() garantit cette règle quel que soit le mois.
INSERT INTO `fi_cards`
    (`id`, `label`, `last4`, `expires_on`, `issuer`, `holder_id`, `type`, `status`, `notes`, `created_by`)
VALUES
    -- Expirée depuis 2 mois, et elle porte encore des services actifs → alerte CRITIQUE
    (1, 'CB Pro Qonto — Mickaël', '4242', LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 2 MONTH)),
        'Qonto', 1, 'credit', 'active',
        'Carte principale des abonnements techniques. Renouvellement demandé à la banque.', 1),

    -- Expire dans ~45 jours → alerte AVERTISSEMENT
    (2, 'CB Pro Qonto — Sophie', '8817', LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 45 DAY)),
        'Qonto', 2, 'debit', 'active',
        'Abonnements bureautiques et communication.', 1),

    -- Expire dans ~80 jours → alerte INFORMATION
    (3, 'CB Revolut Business', '3391', LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 80 DAY)),
        'Revolut', 3, 'debit', 'active',
        'Outils de design et de supervision.', 1),

    -- Rien à signaler
    (4, 'CB virtuelle — Régie pub', '7025', LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 2 YEAR)),
        'Qonto', 1, 'virtual', 'active',
        'Carte virtuelle dédiée aux plateformes publicitaires, plafond mensuel 2 000 €.', 1),

    -- Résiliée : le statut saisi prime sur le calcul d'expiration
    (5, 'Ancienne CB BNP', '1104', LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 8 MONTH)),
        'BNP Paribas', 2, 'credit', 'cancelled',
        'Compte clôturé en même temps que le changement de banque.', 1),

    -- Active mais ne porte aucun service → candidate à la résiliation
    (6, 'CB prépayée événements', '5566', LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 14 MONTH)),
        'Revolut', 4, 'prepaid', 'active',
        'Ouverte pour un salon, plus utilisée depuis.', 1);

-- ---------------------------------------------------------------------
-- Services / abonnements
-- ---------------------------------------------------------------------
-- monthly_cost n'apparaît pas : c'est une colonne générée, MySQL la
-- calcule seul à partir de amount et billing_cycle.
INSERT INTO `fi_services`
    (`name`, `url`, `billing_cycle`, `card_id`, `amount`, `next_renewal_on`, `owner_id`, `status`, `notes`, `created_by`)
VALUES
    -- Portés par la carte EXPIRÉE (n° 1) : c'est le cœur de l'alerte critique
    ('OVH — Hébergement mutualisé', 'https://www.ovhcloud.com/fr/', 'yearly', 1, 287.88,
     DATE_ADD(CURDATE(), INTERVAL 3 MONTH), 1, 'active',
     'Hébergement de 11 sites vitrines. Renouvellement automatique en fin d''année.', 1),

    ('Cloudflare Pro', 'https://dash.cloudflare.com/', 'monthly', 1, 20.00,
     NULL, 3, 'active',
     'CDN, WAF et cache sur les 4 sites à fort trafic.', 1),

    ('Mailjet — Envoi transactionnel', 'https://app.mailjet.com/', 'monthly', 1, 35.00,
     NULL, 4, 'active',
     'Emails transactionnels : inscriptions, mots de passe oubliés, factures.', 1),

    ('Datadog — Supervision', 'https://app.datadoghq.eu/', 'monthly', 1, 120.00,
     NULL, NULL, 'active',
     'Métriques serveurs et alertes. Référent à désigner.', 1),

    -- Carte n° 2, expiration proche
    ('Google Workspace', 'https://admin.google.com/', 'monthly', 2, 57.60,
     NULL, 2, 'active',
     'Messagerie et Drive, 8 boîtes à 7,20 € HT.', 1),

    ('Adobe Creative Cloud', 'https://account.adobe.com/', 'monthly', 2, 71.99,
     NULL, 4, 'active',
     'Photoshop et Illustrator pour la production des visuels.', 1),

    ('Slack Pro', 'https://slack.com/', 'yearly', 2, 828.00,
     DATE_ADD(CURDATE(), INTERVAL 20 DAY), 2, 'active',
     'Communication interne. Échéance proche, à arbitrer avant reconduction.', 1),

    -- Carte n° 3
    ('Figma Professional', 'https://www.figma.com/', 'yearly', 3, 180.00,
     DATE_ADD(CURDATE(), INTERVAL 7 MONTH), 4, 'active',
     'Maquettes et design system, 1 éditeur.', 1),

    ('Sentry — Suivi des erreurs', 'https://sentry.io/', 'monthly', 3, 26.00,
     NULL, 3, 'active',
     'Remontée des erreurs JS et PHP de tous les sites.', 1),

    -- Carte n° 4
    ('GitHub Team', 'https://github.com/', 'monthly', 4, 16.00,
     NULL, 1, 'active',
     'Dépôts de code, 4 utilisateurs.', 1),

    ('Google Ads', 'https://ads.google.com/', 'on_demand', 4, 0.00,
     NULL, 2, 'active',
     'Budget variable selon les campagnes, entre 400 et 3 000 € par mois.', 1),

    -- Sans carte rattachée → alerte « service actif sans CB »
    ('Notion — Base de connaissances', 'https://www.notion.so/', 'monthly', NULL, 40.00,
     NULL, 3, 'active',
     'Documentation interne. Facturé sur le compte perso de Karim, à régulariser.', 1),

    -- Gratuit, sans carte : situation normale, ne doit PAS déclencher d''alerte
    ('Google Search Console', 'https://search.google.com/search-console', 'on_demand', NULL, 0.00,
     NULL, 3, 'active',
     'Gratuit. Suivi de l''indexation des sites.', 1),

    -- Suspendu et résilié : ne comptent pas dans les totaux
    ('Hotjar — Enregistrement de sessions', 'https://www.hotjar.com/', 'monthly', 3, 39.00,
     NULL, 4, 'suspended',
     'Suspendu le temps de trancher sur la conformité RGPD.', 1),

    ('Trello Business', 'https://trello.com/', 'yearly', 5, 120.00,
     NULL, 2, 'cancelled',
     'Remplacé par Notion. Abonnement résilié, carte clôturée.', 1);
