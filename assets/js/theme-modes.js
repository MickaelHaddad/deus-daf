/**
 * ---------------------------------------------------------------------
 * Bascule de thème clair / sombre
 * ---------------------------------------------------------------------
 * Ordre de priorité :
 *   1. le choix mémorisé dans ce navigateur (localStorage) ;
 *   2. la préférence enregistrée sur le profil utilisateur, transmise
 *      par PHP dans l'attribut data-user-theme de <html> ;
 *   3. la préférence du système (prefers-color-scheme).
 *
 * Ce fichier est chargé dans <head>, avant le premier rendu, pour que
 * la page ne clignote jamais du clair vers le sombre.
 * ---------------------------------------------------------------------
 */
(() => {
  "use strict";

  const root = document.documentElement;
  const media = window.matchMedia("(prefers-color-scheme: dark)");

  const readStored = () => {
    try {
      return localStorage.getItem("theme");
    } catch (e) {
      return null; // navigation privée ou stockage bloqué
    }
  };

  const writeStored = (theme) => {
    try {
      localStorage.setItem("theme", theme);
    } catch (e) {
      /* le thème restera valable pour la session en cours seulement */
    }
  };

  /** Thème à appliquer : "light", "dark" ou "auto". */
  const getPreferredTheme = () => {
    const stored = readStored();
    if (stored === "light" || stored === "dark" || stored === "auto") {
      return stored;
    }

    const serverPref = root.getAttribute("data-user-theme");
    if (serverPref === "light" || serverPref === "dark") {
      return serverPref;
    }

    return "auto";
  };

  const setTheme = (theme) => {
    const resolved = theme === "auto" ? (media.matches ? "dark" : "light") : theme;
    root.setAttribute("data-bs-theme", resolved);
  };

  /** Met à jour l'icône et l'état actif du menu de thème, s'il existe. */
  const showActiveTheme = (theme) => {
    const activeIcon = document.querySelector(".theme-icon-active");
    const btnToActivate = document.querySelector(`[data-bs-theme-value="${theme}"]`);

    if (!btnToActivate) {
      return; // page sans menu, l'écran de connexion par exemple
    }

    const icon = btnToActivate.querySelector("i");
    if (icon && activeIcon) {
      activeIcon.className =
        "fa-solid fa-fw theme-icon-active " +
        [...icon.classList].filter((c) => c.startsWith("fa-") && c !== "fa-fw").join(" ");
    }

    document.querySelectorAll("[data-bs-theme-value]").forEach((el) => {
      el.classList.remove("active");
      el.setAttribute("aria-pressed", "false");
    });
    btnToActivate.classList.add("active");
    btnToActivate.setAttribute("aria-pressed", "true");
  };

  // Application immédiate, avant le rendu.
  setTheme(getPreferredTheme());

  // Suivi du réglage système tant que l'utilisateur est en mode auto.
  media.addEventListener("change", () => {
    if (getPreferredTheme() === "auto") {
      setTheme("auto");
    }
  });

  window.addEventListener("DOMContentLoaded", () => {
    showActiveTheme(getPreferredTheme());

    document.querySelectorAll("[data-bs-theme-value]").forEach((toggle) => {
      toggle.addEventListener("click", () => {
        const theme = toggle.getAttribute("data-bs-theme-value");

        writeStored(theme);
        setTheme(theme);
        showActiveTheme(theme);

        // Persistance sur le profil, pour retrouver le même thème
        // depuis un autre poste. Silencieux en cas d'échec.
        if (window.DAF && window.DAF.connected) {
          const body = new URLSearchParams({ action: "profil_theme", theme: theme });
          fetch("action.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
              "X-CSRF-Token": window.DAF.csrfToken,
              "X-Requested-With": "XMLHttpRequest",
            },
            body: body.toString(),
            credentials: "same-origin",
          }).catch(() => {});
        }
      });
    });
  });
})();
