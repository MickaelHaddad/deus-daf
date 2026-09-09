/**
 * ---------------------------------------------------------------------
 * app.js — couche applicative de deus-daf
 * ---------------------------------------------------------------------
 * Contient tout ce qui est spécifique à cet outil :
 *  - envoi AJAX des formulaires vers action.php ;
 *  - affichage des erreurs au niveau du champ concerné ;
 *  - chargement des modales (popup_*.php) ;
 *  - confirmation avant action destructive ;
 *  - déconnexion automatique à l'expiration de la session ;
 *  - raccourcis clavier.
 *
 * Le fichier script.js, repris de deus-console, reste générique
 * (showToast, gestion des classes is-invalid, tooltips).
 * ---------------------------------------------------------------------
 */
(() => {
  "use strict";

  const DAF = window.DAF || (window.DAF = {});

  // ===================================================================
  // Appels AJAX
  // ===================================================================

  /**
   * Envoie une action à action.php et renvoie la réponse JSON.
   *
   * @param {string} action  Nom de l'action, ex. "service_save"
   * @param {object|FormData} data
   * @returns {Promise<{success:boolean,data:*,message:?string,errors:?object}>}
   */
  DAF.post = async function (action, data) {
    let body;

    if (data instanceof FormData) {
      body = data;
      body.set("action", action);
    } else {
      body = new FormData();
      body.set("action", action);
      Object.entries(data || {}).forEach(([k, v]) => {
        body.set(k, v === null || v === undefined ? "" : String(v));
      });
    }

    let response;
    try {
      response = await fetch("action.php", {
        method: "POST",
        headers: {
          "X-CSRF-Token": DAF.csrfToken,
          "X-Requested-With": "XMLHttpRequest",
        },
        body: body,
        credentials: "same-origin",
      });
    } catch (e) {
      return {
        success: false,
        data: null,
        message: "Connexion au serveur impossible. Vérifiez votre réseau.",
        errors: null,
      };
    }

    // Session expirée : on renvoie l'utilisateur sur l'écran de connexion.
    if (response.status === 401) {
      window.location.href = "index.php?action=expire";
      return { success: false, data: null, message: null, errors: null };
    }

    let payload;
    try {
      payload = await response.json();
    } catch (e) {
      return {
        success: false,
        data: null,
        message: "Réponse inattendue du serveur (" + response.status + ").",
        errors: null,
      };
    }

    DAF.resetIdleTimer();

    return payload;
  };

  // ===================================================================
  // Erreurs de formulaire
  // ===================================================================

  /** Efface toutes les erreurs affichées dans un formulaire. */
  DAF.clearFormErrors = function (form) {
    form.querySelectorAll(".is-invalid").forEach((el) => el.classList.remove("is-invalid"));
    form.querySelectorAll(".invalid-feedback.daf-generated").forEach((el) => el.remove());
  };

  /**
   * Affiche les erreurs renvoyées par le serveur sous le champ concerné.
   * @param {HTMLFormElement} form
   * @param {Object<string,string>} errors
   */
  DAF.showFormErrors = function (form, errors) {
    DAF.clearFormErrors(form);
    let first = null;

    Object.entries(errors || {}).forEach(([field, message]) => {
      const input = form.querySelector('[name="' + CSS.escape(field) + '"]');
      if (!input) {
        return;
      }

      input.classList.add("is-invalid");
      if (!first) {
        first = input;
      }

      // On réutilise le .invalid-feedback existant s'il y en a un,
      // sinon on en crée un juste après le champ.
      let feedback = input.parentNode.querySelector(".invalid-feedback");
      if (!feedback) {
        feedback = document.createElement("div");
        feedback.className = "invalid-feedback daf-generated";
        input.parentNode.appendChild(feedback);
      }
      feedback.textContent = message;
      feedback.classList.add("d-block");
    });

    if (first) {
      first.focus();
      first.scrollIntoView({ block: "center", behavior: "smooth" });
    }
  };

  // ===================================================================
  // Formulaires AJAX
  // ===================================================================
  //
  // Convention :
  //   <form class="daf-ajax-form" data-action="service_save"
  //         data-success="Le service a bien été enregistré."
  //         data-close-modal="1">
  //
  // Après succès, si la page définit window.dafOnSaved(action, data),
  // celle-ci est appelée pour mettre à jour l'affichage en place.
  // Sinon la page est rechargée.
  //
  const bindAjaxForm = (form) => {
    if (form.dataset.dafBound === "1") {
      return;
    }
    form.dataset.dafBound = "1";

    form.addEventListener("submit", async (event) => {
      event.preventDefault();

      const action = form.dataset.action;
      const submitBtn = form.querySelector('[type="submit"]');
      const originalHtml = submitBtn ? submitBtn.innerHTML : null;

      DAF.clearFormErrors(form);

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML =
          '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Enregistrement…';
      }

      const result = await DAF.post(action, new FormData(form));

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
      }

      if (!result.success) {
        if (result.errors) {
          DAF.showFormErrors(form, result.errors);
        }
        if (result.message) {
          showToast("danger", result.message);
        }
        return;
      }

      showToast("success", result.message || form.dataset.success || "Enregistré.");

      if (form.dataset.closeModal === "1") {
        DAF.closePopup();
      }

      if (typeof window.dafOnSaved === "function") {
        window.dafOnSaved(action, result.data);
      } else {
        window.location.reload();
      }
    });
  };

  /** Active l'envoi AJAX sur tous les formulaires d'un conteneur. */
  DAF.bindForms = function (container) {
    (container || document).querySelectorAll("form.daf-ajax-form").forEach(bindAjaxForm);
  };

  // ===================================================================
  // Modales chargées en AJAX
  // ===================================================================

  /**
   * Charge une page popup_*.php dans #modalContainer et l'affiche.
   * @param {string} url ex. "popup_service.php?id=12"
   */
  DAF.openPopup = async function (url) {
    const container = document.getElementById("modalContainer");
    if (!container) {
      return;
    }

    container.setAttribute("aria-busy", "true");

    let html;
    try {
      const response = await fetch(url, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      });

      if (response.status === 401) {
        window.location.href = "index.php?action=expire";
        return;
      }
      if (!response.ok) {
        showToast("danger", "Impossible d'ouvrir ce formulaire.");
        return;
      }
      html = await response.text();
    } catch (e) {
      showToast("danger", "Connexion au serveur impossible.");
      return;
    } finally {
      container.removeAttribute("aria-busy");
    }

    container.innerHTML = html;

    const modalEl = container.querySelector(".modal");
    if (!modalEl) {
      return;
    }

    DAF.bindForms(modalEl);
    initTooltips(modalEl);

    const modal = new bootstrap.Modal(modalEl);
    modalEl.addEventListener("hidden.bs.modal", () => {
      container.innerHTML = "";
    });
    modalEl.addEventListener("shown.bs.modal", () => {
      const firstField = modalEl.querySelector("input:not([type=hidden]), select, textarea");
      if (firstField) {
        firstField.focus();
      }
    });

    modal.show();
    DAF.resetIdleTimer();
  };

  /** Ferme la modale actuellement ouverte. */
  DAF.closePopup = function () {
    const modalEl = document.querySelector("#modalContainer .modal");
    if (modalEl) {
      const instance = bootstrap.Modal.getInstance(modalEl);
      if (instance) {
        instance.hide();
      }
    }
  };

  // Compatibilité avec les noms utilisés dans deus-console.
  window.openPopup = DAF.openPopup;
  window.closePopup = DAF.closePopup;

  // ===================================================================
  // Confirmation avant action destructive
  // ===================================================================

  /**
   * Affiche une modale de confirmation et résout à true si l'utilisateur
   * confirme. Construite côté client : aucun aller-retour serveur.
   *
   * @returns {Promise<boolean>}
   */
  DAF.confirm = function (options) {
    const opts = Object.assign(
      {
        title: "Confirmer",
        message: "Confirmez-vous cette action ?",
        confirmLabel: "Confirmer",
        cancelLabel: "Annuler",
        variant: "danger",
      },
      options || {}
    );

    return new Promise((resolve) => {
      const wrapper = document.createElement("div");
      wrapper.innerHTML = [
        '<div class="modal fade" tabindex="-1" role="alertdialog"',
        '     aria-labelledby="dafConfirmTitle" aria-describedby="dafConfirmBody">',
        '  <div class="modal-dialog modal-dialog-centered">',
        '    <div class="modal-content">',
        '      <div class="modal-header">',
        '        <h2 class="modal-title h5" id="dafConfirmTitle"></h2>',
        '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>',
        "      </div>",
        '      <div class="modal-body" id="dafConfirmBody"></div>',
        '      <div class="modal-footer">',
        '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"></button>',
        '        <button type="button" class="btn" data-daf-confirm-ok></button>',
        "      </div>",
        "    </div>",
        "  </div>",
        "</div>",
      ].join("");

      const modalEl = wrapper.firstElementChild;
      modalEl.querySelector("#dafConfirmTitle").textContent = opts.title;
      modalEl.querySelector("#dafConfirmBody").textContent = opts.message;
      modalEl.querySelector('[data-bs-dismiss="modal"].btn').textContent = opts.cancelLabel;

      const okBtn = modalEl.querySelector("[data-daf-confirm-ok]");
      okBtn.textContent = opts.confirmLabel;
      okBtn.classList.add("btn-" + opts.variant);

      document.body.appendChild(modalEl);
      const modal = new bootstrap.Modal(modalEl);

      let confirmed = false;
      okBtn.addEventListener("click", () => {
        confirmed = true;
        modal.hide();
      });
      modalEl.addEventListener("hidden.bs.modal", () => {
        modalEl.remove();
        resolve(confirmed);
      });
      modalEl.addEventListener("shown.bs.modal", () => okBtn.focus());

      modal.show();
    });
  };

  // ===================================================================
  // Délégation de clics : ouverture de modale et actions destructives
  // ===================================================================
  //
  //   <button data-daf-popup="popup_service.php?id=12">Modifier</button>
  //
  //   <button data-daf-action="service_delete" data-daf-id="12"
  //           data-daf-confirm="Supprimer définitivement « Figma » ?">
  //
  document.addEventListener("click", async (event) => {
    const popupTrigger = event.target.closest("[data-daf-popup]");
    if (popupTrigger) {
      event.preventDefault();
      DAF.openPopup(popupTrigger.dataset.dafPopup);
      return;
    }

    const actionTrigger = event.target.closest("[data-daf-action]");
    if (!actionTrigger) {
      return;
    }

    event.preventDefault();

    const message = actionTrigger.dataset.dafConfirm;
    if (message) {
      const ok = await DAF.confirm({
        title: actionTrigger.dataset.dafConfirmTitle || "Confirmer",
        message: message,
        confirmLabel: actionTrigger.dataset.dafConfirmLabel || "Confirmer",
      });
      if (!ok) {
        return;
      }
    }

    const payload = {};
    Object.entries(actionTrigger.dataset).forEach(([key, value]) => {
      // data-daf-p-xxx devient le paramètre xxx
      if (key.startsWith("dafP") && key.length > 4) {
        payload[key.charAt(4).toLowerCase() + key.slice(5)] = value;
      }
    });
    if (actionTrigger.dataset.dafId) {
      payload.id = actionTrigger.dataset.dafId;
    }

    actionTrigger.disabled = true;
    const result = await DAF.post(actionTrigger.dataset.dafAction, payload);
    actionTrigger.disabled = false;

    if (!result.success) {
      showToast("danger", result.message || "L'action a échoué.");
      return;
    }

    showToast("success", result.message || "Action effectuée.");

    if (typeof window.dafOnSaved === "function") {
      window.dafOnSaved(actionTrigger.dataset.dafAction, result.data);
    } else {
      window.location.reload();
    }
  });

  // ===================================================================
  // Masques de saisie
  // ===================================================================
  //
  // Déclarés par attribut sur le champ :
  //   <input data-daf-mask="expiry">              → MM/AA
  //   <input data-daf-mask="digits" maxlength="4"> → chiffres uniquement
  //
  // Le traitement passe par délégation sur document, et non par un
  // script propre à chaque modale : le contenu des popups est injecté
  // via innerHTML, or un <script> inséré de cette façon n'est jamais
  // exécuté par le navigateur.
  //
  document.addEventListener("input", (event) => {
    const field = event.target;
    const mask = field.dataset ? field.dataset.dafMask : null;

    if (!mask) {
      return;
    }

    const limit = parseInt(field.getAttribute("maxlength"), 10);

    if (mask === "digits") {
      const max = isNaN(limit) ? 32 : limit;
      field.value = field.value.replace(/\D/g, "").slice(0, max);
      return;
    }

    if (mask === "expiry") {
      const digits = field.value.replace(/\D/g, "").slice(0, 6);
      field.value = digits.length > 2 ? digits.slice(0, 2) + "/" + digits.slice(2) : digits;
    }
  });

  // ===================================================================
  // Expiration de session côté client
  // ===================================================================

  let idleDeadline = 0;
  let warned = false;

  DAF.resetIdleTimer = function () {
    idleDeadline = Date.now() + (DAF.idleSeconds || 7200) * 1000;
    warned = false;
  };

  const watchIdle = () => {
    if (!DAF.connected) {
      return;
    }

    DAF.resetIdleTimer();

    setInterval(() => {
      const remaining = idleDeadline - Date.now();

      if (remaining <= 0) {
        window.location.href = "index.php?action=expire";
        return;
      }
      if (remaining <= 120000 && !warned) {
        warned = true;
        showToast("warning", "Votre session expirera dans 2 minutes faute d'activité.");
      }
    }, 15000);
  };

  // ===================================================================
  // Raccourcis clavier
  // ===================================================================
  //
  //   /        place le curseur dans le champ de recherche de la page
  //   Échap    ferme la modale ouverte (géré nativement par Bootstrap)
  //
  document.addEventListener("keydown", (event) => {
    const tag = (event.target.tagName || "").toLowerCase();
    const isTyping = tag === "input" || tag === "textarea" || tag === "select" || event.target.isContentEditable;

    if (event.key === "/" && !isTyping && !event.ctrlKey && !event.metaKey) {
      const search = document.querySelector("[data-daf-search]");
      if (search) {
        event.preventDefault();
        search.focus();
        search.select();
      }
    }
  });

  // ===================================================================
  // Divers
  // ===================================================================

  const initTooltips = (container) => {
    (container || document).querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
      if (!bootstrap.Tooltip.getInstance(el)) {
        new bootstrap.Tooltip(el);
      }
    });
  };

  /** Formate un nombre en euros : 1 234,50 € */
  DAF.money = function (value) {
    return (
      Number(value || 0)
        .toFixed(2)
        .replace(".", ",")
        .replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " €"
    );
  };

  document.addEventListener("DOMContentLoaded", () => {
    DAF.bindForms(document);
    initTooltips(document);
    watchIdle();
  });
})();
