# Deus DAF

Back office interne de suivi des cartes bancaires et des abonnements.
Il répond à une question simple : **qui paie quoi, avec quelle carte,
pour quel montant, et à quoi ça sert** — et surtout, **quels services
vont tomber en panne de paiement quand une carte expire**.

---

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Installation](#2-installation)
3. [Configuration du serveur web](#3-configuration-du-serveur-web)
4. [HTTPS](#4-https)
5. [Permissions des fichiers](#5-permissions-des-fichiers)
6. [Tâches planifiées](#6-tâches-planifiées)
7. [Checklist de sécurité post-installation](#7-checklist-de-sécurité-post-installation)
8. [Mises à jour](#8-mises-à-jour)
9. [Dépannage](#9-dépannage)
10. [Architecture du code](#10-architecture-du-code)

---

## 1. Prérequis

| Composant | Version | Remarque |
|---|---|---|
| PHP | 8.2 ou plus | extensions `pdo_mysql`, `mbstring`, `json`, `session` |
| MySQL | 8.0 ou plus | MariaDB 10.6+ convient, sauf pour la colonne `JSON` du journal |
| Apache | 2.4 | modules `rewrite`, `headers`, `expires`, `ssl`, `proxy_fcgi` |
| — ou Nginx | 1.18 ou plus | voir `deploy/nginx.conf.sample` |

**Aucune dépendance externe.** Ni Composer, ni npm, ni CDN : toutes les
librairies front (Bootstrap, jQuery, DataTables, FontAwesome, Noto Sans)
sont servies depuis `assets/`.

---

## 2. Installation

### 2.1 Déposer les fichiers

```bash
sudo mkdir -p /var/www/deus-daf
sudo rsync -a --exclude='.git' --exclude='inc_config.php' ./ /var/www/deus-daf/
```

> ⚠️ **Ne jamais copier le `inc_config.php` d'un poste de développement.**
> Il contient d'autres identifiants et, surtout, `'secure' => false`, ce
> qui autoriserait le cookie de session à circuler en clair.

### 2.2 Créer la base et son compte

Le compte MySQL n'a besoin d'aucun privilège d'administration.

```sql
CREATE DATABASE deus_daf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'deus_daf'@'localhost' IDENTIFIED BY 'UN_MOT_DE_PASSE_LONG_ET_ALEATOIRE';

GRANT SELECT, INSERT, UPDATE, DELETE ON deus_daf.* TO 'deus_daf'@'localhost';
FLUSH PRIVILEGES;
```

### 2.3 Importer le schéma

```bash
mysql -u root -p deus_daf < /var/www/deus-daf/db/schema.sql
```

Le script crée six tables préfixées `fi_` (`fi_users`, `fi_cards`,
`fi_services`, `fi_settings`, `fi_login_attempts`, `fi_activity_log`),
ce qui lui permet de cohabiter avec d'autres applications dans la même
base.

> ⚠️ `schema.sql` commence par des `DROP TABLE`. Ils ne visent que les
> tables `fi_*`, mais **le rejouer sur une base en service efface toutes
> les données de l'application**.

**Ne pas importer `db/seed.sql` en production** : c'est un jeu de
démonstration dont le mot de passe est public.

### 2.4 Configurer l'application

```bash
cd /var/www/deus-daf
cp inc_config.sample.php inc_config.php
nano inc_config.php
```

À renseigner impérativement :

- `db` — hôte, nom de base, utilisateur, mot de passe ;
- `app.base_url` — l'URL publique, **sans slash final** ;
- `app.timezone` — `Europe/Paris` ;
- `env` — laisser sur `production` ;
- `session.secure` — laisser sur `true` (exige HTTPS).

### 2.5 Créer le premier administrateur

```bash
php bin/install.php
```

Le script demande prénom, nom, email, position et mot de passe (12
caractères minimum, majuscules, minuscules et chiffres). La saisie du
mot de passe n'est pas affichée. Le hachage utilise **Argon2id** s'il
est disponible sur le serveur, bcrypt sinon.

---

## 3. Configuration du serveur web

Le `DocumentRoot` pointe sur la **racine du projet**. La protection des
fichiers sensibles repose donc entièrement sur la configuration du
serveur.

### Apache

Modèle complet : `deploy/apache-vhost.conf.sample`.

```bash
sudo a2enmod rewrite headers expires ssl proxy_fcgi
sudo cp deploy/apache-vhost.conf.sample /etc/apache2/sites-available/daf.exemple.fr.conf
sudo nano /etc/apache2/sites-available/daf.exemple.fr.conf   # domaine, chemin, version PHP
sudo a2ensite daf.exemple.fr
sudo apachectl configtest && sudo systemctl reload apache2
```

**`AllowOverride All` est indispensable** : c'est le `.htaccess` livré à
la racine qui bloque l'accès direct aux `inc_*.php`, à `db/`,
`storage/`, `bin/` et `deploy/`. Sans lui, `db/seed.sql` et
`storage/logs/php-error.log` seraient servis en clair.

### Nginx

Modèle complet : `deploy/nginx.conf.sample`.

**Nginx n'interprète pas les fichiers `.htaccess`.** Les blocages
doivent être reproduits par des directives `location`, présentes dans le
modèle. Ne pas déployer sous Nginx sans elles.

---

## 4. HTTPS

```bash
sudo apt install certbot python3-certbot-apache     # ou -nginx
sudo certbot --apache -d daf.exemple.fr
sudo systemctl status certbot.timer                 # renouvellement automatique
```

L'application émet elle-même l'en-tête `Strict-Transport-Security`
lorsqu'elle détecte une requête HTTPS, y compris derrière un proxy
inverse (via `X-Forwarded-Proto`).

---

## 5. Permissions des fichiers

`www-data` est l'utilisateur d'Apache et de PHP-FPM sur Debian et
Ubuntu ; adapter le cas échéant.

```bash
cd /var/www/deus-daf

# Propriété : lecture pour le serveur web, écriture pour l'administrateur
sudo chown -R root:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;

# Seuls ces deux dossiers ont besoin d'être en écriture
sudo chown -R www-data:www-data storage/logs storage/sessions
sudo chmod 770 storage/logs storage/sessions

# Le fichier de configuration contient le mot de passe de la base
sudo chmod 640 inc_config.php
```

Le serveur web **n'a besoin d'écrire nulle part ailleurs** : aucun
téléversement, aucun cache sur disque.

---

## 6. Tâches planifiées

Les deux scripts sont facultatifs et refusent de s'exécuter autrement
qu'en ligne de commande.

```cron
# Alertes d'expiration de carte, tous les lundis à 8 h
0 8 * * 1 /usr/bin/php /var/www/deus-daf/bin/notify_expiring_cards.php >> /var/log/deus-daf-cron.log 2>&1

# Purge des tentatives de connexion de plus de 7 jours, toutes les nuits
30 3 * * * /usr/bin/php /var/www/deus-daf/bin/purge_login_attempts.php >> /var/log/deus-daf-cron.log 2>&1
```

L'envoi d'emails est **désactivé par défaut**. Pour l'activer, passer
`mail.enabled` à `true` dans `inc_config.php`. Vérifier d'abord le
rendu :

```bash
php bin/notify_expiring_cards.php --dry-run
```

> Sans enregistrements **SPF et DKIM** correctement publiés pour votre
> domaine, ces messages finiront en indésirables — ce qui donne une
> fausse impression de sécurité. Le vrai filet reste le bloc « Alertes »
> du tableau de bord, visible à chaque connexion.

---

## 7. Checklist de sécurité post-installation

À dérouler une fois le site en ligne. Remplacer `daf.exemple.fr` par
votre domaine.

### Vérifications automatiques

```bash
DOMAINE=https://daf.exemple.fr

# 1. Ces URL doivent TOUTES répondre 403 ou 404
for u in inc_config.php inc_bdd.php inc_connexion.php inc_securite.php \
         db/schema.sql db/seed.sql storage/logs/php-error.log \
         bin/install.php deploy/nginx.conf.sample .gitignore .htaccess \
         inc_config.sample.php README.md; do
    printf '%-40s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$DOMAINE/$u")"
done

# 2. Une page protégée doit rediriger vers la connexion
curl -s -o /dev/null -w 'home.php : %{http_code} -> %{redirect_url}\n' "$DOMAINE/home.php"

# 3. L'API doit répondre 401 sans session
curl -s -X POST -d 'action=service_save' "$DOMAINE/action.php"

# 4. Le HTTP doit rediriger vers le HTTPS
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' "http://daf.exemple.fr/"

# 5. Les en-têtes de sécurité doivent être présents
curl -sI "$DOMAINE/index.php" | grep -iE 'content-security|strict-transport|x-frame|x-content|referrer'
```

### Vérifications manuelles

- [ ] `inc_config.php` contient `'env' => 'production'`
- [ ] `inc_config.php` contient `'session' => ['secure' => true]`
- [ ] Le mot de passe MySQL est long, aléatoire, et propre à cette application
- [ ] Le compte MySQL n'a que `SELECT, INSERT, UPDATE, DELETE`
- [ ] `db/seed.sql` **n'a pas** été importé en production
- [ ] Aucun compte ne porte encore un mot de passe de démonstration
- [ ] `chmod 640 inc_config.php`, et `storage/` en écriture pour le seul `www-data`
- [ ] Le certificat HTTPS est valide et se renouvelle automatiquement
- [ ] Une sauvegarde quotidienne de la base est en place et **restaurée une fois pour test**
- [ ] `storage/logs/php-error.log` est surveillé, ou au moins consulté après la mise en service
- [ ] Le journal d'activité (menu Administration) montre bien les connexions

### Rappel de conformité PCI DSS

L'application ne stocke **ni numéro de carte complet, ni CVV/CVC**.
Aucun champ ne les propose, et la base contrainte `last4` à exactement
quatre chiffres. Ne pas contourner cette limite en glissant un numéro
complet dans le champ « commentaire ».

---

## 8. Mises à jour

```bash
cd /var/www/deus-daf
sudo mysqldump -u root -p deus_daf > /root/deus-daf-$(date +%F).sql   # d'abord une sauvegarde
sudo rsync -a --exclude='.git' --exclude='inc_config.php' --exclude='storage/' ./nouvelle-version/ ./
```

`inc_config.php` et `storage/` sont exclus : ils portent votre
configuration et vos sessions.

**Ne jamais rejouer `db/schema.sql` sur une base en service.** Toute
évolution de structure doit passer par un script de migration écrit
pour l'occasion.

---

## 9. Dépannage

| Symptôme | Cause probable |
|---|---|
| Erreur 500 sur tout le site | Une directive `php_flag` dans un `.htaccess` alors que PHP tourne en FPM. Le `.htaccess` livré n'en contient aucune. |
| « Configuration absente » | `inc_config.php` n'a pas été créé à partir du modèle. |
| « Service momentanément indisponible » | Identifiants MySQL erronés, ou base injoignable. Détail dans `storage/logs/php-error.log`. |
| Déconnexion immédiate après connexion | `session.secure = true` alors que le site est servi en HTTP, ou `storage/sessions` non accessible en écriture. |
| Le tableau des services reste vide | Un fichier de `assets/` manque. Vérifier l'onglet Réseau du navigateur : aucune requête ne doit être en 404. |
| Boucle de redirection | `AllowOverride` absent : la règle HTTPS du `.htaccess` s'applique alors que le vhost redirige déjà. |
| Les accents s'affichent mal dans l'export | Ouvrir le CSV avec Excel via *Données → À partir d'un fichier texte*, encodage UTF-8. |

Journaux utiles :

```bash
tail -f /var/www/deus-daf/storage/logs/php-error.log
tail -f /var/log/apache2/deus-daf-error.log
```

---

## 10. Architecture du code

Modèle **plat, sans framework et sans MVC** : une page correspond à un
fichier, comme dans `deus-console`.

```
index.php               Connexion (seule page accessible sans session)
access_ctrl.php         Traitement de la connexion, réponse JSON
home.php                Tableau de bord et bloc Alertes
services_liste.php      Tableau des abonnements, totaux, filtres, export
cartes_liste.php        Vignettes des cartes, tri et filtres par échéance
utilisateurs_liste.php  Gestion des comptes (administrateurs)
journal.php             Journal d'activité (administrateurs)
reglages.php            Seuils d'alerte (administrateurs)
profil.php              Mot de passe et thème de l'utilisateur courant
export_services.php     Export CSV

popup_*.php             Formulaires chargés en AJAX dans une modale
action.php              Point d'entrée UNIQUE des écritures, réponse JSON

inc_connexion.php       Amorçage : config, BDD, session, utilisateur courant
inc_config.php          Configuration réelle (hors dépôt)
inc_bdd.php             Connexion PDO
inc_securite.php        CSP à nonce, CSRF, limitation des tentatives, rôles
inc_fonctions.php       Échappement, réponses JSON, lecture des entrées, journal
inc_metier.php          Règles métier : statuts de carte, coûts, alertes
inc_render.php          Rendu HTML partagé entre les pages et action.php
inc_header.php          Layout, navigation, thème
inc_footer.php          Scripts communs

assets/js/app.js        Couche AJAX, modales, confirmations, raccourcis
assets/js/script.js     Utilitaires repris de deus-console
assets/js/theme-modes.js Bascule clair/sombre
assets/scss/daf.css     Styles propres à cette application
```

### Conventions

- **Échanges** : toute écriture passe par `action.php` en `POST`, avec
  le token CSRF dans l'en-tête `X-CSRF-Token`. La réponse est toujours
  `{success, data, message, errors}`, où `errors` associe un message à
  chaque champ fautif.
- **SQL** : requêtes préparées exclusivement, aucune valeur concaténée.
- **Sortie** : `htmlspecialchars()` systématique via `h()`.
- **Statut des cartes** : « expirée » n'est jamais stocké en base. Il
  est recalculé à partir de `expires_on` à chaque lecture, donc toujours
  exact, sans tâche planifiée. Seul « résiliée » est saisi à la main, et
  il prime sur le calcul.
- **Coût mensualisé** : colonne générée `STORED` en base
  (`fi_services.monthly_cost`). MySQL la maintient, elle ne peut pas
  diverger du montant saisi.
- **Rendu des listes** : produit par PHP, y compris après un
  enregistrement AJAX — `action.php` renvoie le HTML de la ligne et du
  bandeau de totaux. Le navigateur ne recalcule aucun chiffre.
