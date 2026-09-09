<?php
/**
 * ---------------------------------------------------------------------
 * Écran de connexion
 * ---------------------------------------------------------------------
 * Seule page accessible sans session. Gère aussi la déconnexion
 * (index.php?action=deconnexion) et l'affichage du message d'expiration
 * (index.php?action=expire).
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/inc_connexion.php';

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------
// Déconnexion volontaire
// ---------------------------------------------------------------------
if ($action === 'deconnexion') {
    logout_user();
    redirect_to('index.php?action=out');
}

// ---------------------------------------------------------------------
// Déjà connecté : on renvoie directement au tableau de bord
// ---------------------------------------------------------------------
if (current_user() !== null) {
    redirect_to('home.php');
}

// ---------------------------------------------------------------------
// Message d'accueil selon le contexte d'arrivée
// ---------------------------------------------------------------------
$notice = null;
$noticeType = 'info';

if ($action === 'out') {
    $notice = 'Vous êtes déconnecté.';
    $noticeType = 'success';
} elseif ($action === 'expire' || !empty($GLOBALS['session_expired'])) {
    $notice = 'Votre session a expiré. Reconnectez-vous.';
    $noticeType = 'warning';
}

$title         = 'Connexion — ' . ($config['app']['name'] ?? 'Deus DAF');
$page_id       = 'page_connexion';
$sans_menu     = true;
$page_publique = true;

include __DIR__ . '/inc_header.php';
?>
        <div class="login-wrapper">
            <div class="login-card">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-credit-card login-logo" aria-hidden="true"></i>
                    <h1 class="h4 mt-3 mb-1"><?= h($config['app']['name'] ?? 'Deus DAF') ?></h1>
                    <p class="text-secondary text-sm mb-0">Suivi des cartes et des abonnements</p>
                </div>

<?php if ($notice !== null) { ?>
                <div class="alert alert-<?= h($noticeType) ?> py-2 text-sm" role="status">
                    <?= h($notice) ?>
                </div>
<?php } ?>

                <form id="formConnexion" novalidate>
                    <div class="mb-3">
                        <label class="form-label" for="email">Adresse email</label>
                        <input type="email" class="form-control" id="email" name="email"
                               autocomplete="username" required autofocus>
                        <div class="invalid-feedback"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Mot de passe</label>
                        <input type="password" class="form-control" id="password" name="password"
                               autocomplete="current-password" required>
                        <div class="invalid-feedback"></div>
                    </div>

                    <div id="loginError" class="alert alert-danger py-2 text-sm d-none" role="alert"></div>

                    <button type="submit" class="btn btn-primary w-100" id="btnConnexion">
                        Se connecter
                    </button>
                </form>
            </div>
        </div>

<?php include __DIR__ . '/inc_footer.php'; ?>
        <script nonce="<?= h(csp_nonce()) ?>">
            document.addEventListener("DOMContentLoaded", function () {
                var form   = document.getElementById("formConnexion");
                var button = document.getElementById("btnConnexion");
                var box    = document.getElementById("loginError");

                form.addEventListener("submit", async function (event) {
                    event.preventDefault();

                    box.classList.add("d-none");
                    box.textContent = "";
                    form.querySelectorAll(".is-invalid").forEach(function (el) {
                        el.classList.remove("is-invalid");
                    });

                    button.disabled = true;
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Connexion…';

                    var payload = new FormData(form);
                    var result;

                    try {
                        var response = await fetch("access_ctrl.php", {
                            method: "POST",
                            headers: {
                                "X-CSRF-Token": window.DAF.csrfToken,
                                "X-Requested-With": "XMLHttpRequest"
                            },
                            body: payload,
                            credentials: "same-origin"
                        });
                        result = await response.json();
                    } catch (e) {
                        result = { success: false, message: "Connexion au serveur impossible." };
                    }

                    button.disabled = false;
                    button.textContent = "Se connecter";

                    if (result.success) {
                        window.location.href = result.data && result.data.redirect ? result.data.redirect : "home.php";
                        return;
                    }

                    if (result.errors) {
                        Object.keys(result.errors).forEach(function (field) {
                            var input = form.querySelector('[name="' + field + '"]');
                            if (!input) { return; }
                            input.classList.add("is-invalid");
                            var feedback = input.parentNode.querySelector(".invalid-feedback");
                            if (feedback) { feedback.textContent = result.errors[field]; }
                        });
                    }

                    if (result.message) {
                        box.textContent = result.message;
                        box.classList.remove("d-none");
                    }

                    document.getElementById("password").value = "";
                });
            });
        </script>
    </body>
</html>
