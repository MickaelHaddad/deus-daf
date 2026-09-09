/** SET DATATABLES FR **/
/*
 * Les libellés sont déclarés en dur, et non chargés via language.url
 * comme dans deus-console. Raison : DataTables diffère l'initialisation
 * du tableau tant que le JSON n'est pas arrivé, et si la requête échoue
 * — fichier déplacé, faute de chemin — le tableau reste vide sans que
 * rien ne le signale à l'utilisateur. Une dépendance réseau de moins.
 */
if (window.DataTable) {
  Object.assign(DataTable.defaults, {
    language: {
      emptyTable: "Aucune donnée à afficher",
      info: "_START_ à _END_ sur _TOTAL_ éléments",
      infoEmpty: "Aucun élément",
      infoFiltered: "(filtrés parmi _MAX_ au total)",
      lengthMenu: "Afficher _MENU_ éléments",
      loadingRecords: "Chargement…",
      processing: "Traitement…",
      search: "",
      searchPlaceholder: "Rechercher…",
      zeroRecords: "Aucun élément ne correspond à cette recherche",
      paginate: {
        first: "Première",
        last: "Dernière",
        next: "Suivante",
        previous: "Précédente",
      },
      aria: {
        sortAscending: ": activer pour trier par ordre croissant",
        sortDescending: ": activer pour trier par ordre décroissant",
      },
    },
  });
}
/** /SET DATATABLES FR **/

document.addEventListener("DOMContentLoaded", function () {
  // Initialize Bootstrap tooltips
  const tooltipTriggerList = document.querySelectorAll(
    '[data-bs-toggle="tooltip"]'
  );
  const tooltipList = [...tooltipTriggerList].map(
    (tooltipTriggerEl) => new bootstrap.Tooltip(tooltipTriggerEl)
  );

  // Initialize Bootstrap dropdown-submenus
  document
    .querySelectorAll(".dropdown-submenu > button")
    .forEach(function (element) {
      element.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.nextElementSibling.classList.toggle("show");
      });
    });
});

$(document).ready(function () {
  /* CHECK DEVICE/NAVIGATEUR */
  var $userAgent = navigator.userAgent;
  var $classNames = [];
  //Device
  if ($userAgent.match(/(iPad|iPhone|iPod)/i)) $classNames.push("ios");
  else if ($userAgent.match(/android/i)) $classNames.push("android");
  //Navigateur
  if (/^((?!chrome|android).)*safari/i.test($userAgent))
    $classNames.push("safari");
  if (
    !!window.chrome &&
    !window.opera &&
    !($userAgent.indexOf("Edge") > -1) &&
    !$userAgent.match(/Edg/i)
  )
    $classNames.push("chrome");
  if (typeof window.InstallTrigger !== "undefined") $classNames.push("firefox");
  if (!!window.opera || $userAgent.indexOf(" OPR/") >= 0)
    $classNames.push("opera");
  if (
    typeof document !== "undefined" &&
    !!document.documentMode &&
    !($userAgent.indexOf("Edge") > -1)
  )
    $classNames.push("navigator-ie");
  if ($userAgent.indexOf("Edge") > -1) $classNames.push("edge-html");
  if (
    $userAgent.match(/Chrome/i) &&
    $userAgent.match(/Edg/i) &&
    $userAgent.match(/Safari/i) &&
    $userAgent.match(/Mozilla/i)
  )
    $classNames.push("chromium");

  var $html = document.getElementsByTagName("html")[0];
  if ($html.classList) $html.classList.add.apply($html.classList, $classNames);
  /* /CHECK DEVICE/NAVIGATEUR */

  /** INPUT ERROR FONCTIONNEMENT **/
  /* ENLEVE BORDURE ROUGE AU FOCUS */
  $("body").on("focus", "input, textarea, select", function () {
    $that = $(this);
    $that.removeClass("is-invalid");
    $that.removeClass("is-valid");
  });
  $("body").on("click", "input[type=checkbox]", function () {
    $that = $(this);
    $that.removeClass("is-invalid");
    $that.removeClass("is-valid");
  });
  $("body").on("click", "input[type=radio]", function () {
    $that = $(this).attr("name");
    $("[name=" + $that + "]").each(function () {
      $(this).removeClass("is-invalid");
      $(this).removeClass("is-valid");
    });
  });
  $("body").on("click", ".select2.select2-container", function () {
    $(this).siblings(".select2.is-invalid").removeClass("is-invalid");
    $(this).siblings(".select2.is-valid").removeClass("is-valid");
  });
  $("body").on("click", ".bootstrap-tagsinput", function () {
    $(this).siblings(".is-invalid").removeClass("is-invalid");
    $(this).siblings(".is-valid").removeClass("is-valid");
  });
  $("body").on("keyup", "input, textarea, select", function () {
    $that = $(this);
    $that.removeClass("is-invalid");
    $that.removeClass("is-valid");
  });
  /** /INPUT ERROR FONCTIONNEMENT **/
});

/** TOAST **/
/**
 * Affiche une notification éphémère.
 * Adaptations deus-daf par rapport à la version console :
 *  - plus d'attribut onclick inline (interdit par la CSP) ;
 *  - le texte est inséré en texte brut et non en HTML (anti-XSS) ;
 *  - attributs ARIA pour les lecteurs d'écran.
 *
 * @param {"success"|"danger"|"warning"|"info"} type
 * @param {string} text
 */
function showToast(type, text) {
  var iconClass = "";
  switch (type) {
    case "danger":
    case "warning":
      iconClass = "fa-regular fa-circle-xmark text-" + type;
      break;
    case "success":
      iconClass = "fa-regular fa-circle-check text-success";
      break;
    case "info":
      iconClass = "fa-regular fa-circle-question text-info";
      break;
  }

  var toast = document.createElement("div");
  toast.className = "toast toast-hide";
  toast.setAttribute("role", type === "danger" ? "alert" : "status");
  toast.setAttribute("aria-live", type === "danger" ? "assertive" : "polite");

  var container = document.createElement("div");
  container.className = "toast-container";

  if (iconClass) {
    var icon = document.createElement("i");
    icon.className = iconClass;
    icon.setAttribute("aria-hidden", "true");
    container.appendChild(icon);
  }

  var textNode = document.createElement("div");
  textNode.className = "toast-text";
  textNode.textContent = text;
  container.appendChild(textNode);

  toast.appendChild(container);
  toast.addEventListener("click", function () {
    hideToast(toast);
  });

  document.body.appendChild(toast);

  setTimeout(function () {
    toast.classList.remove("toast-hide");
  }, 200);
  setTimeout(function () {
    toast.classList.add("toast-hide");
  }, 5000);
  setTimeout(function () {
    if (toast.parentNode) {
      toast.parentNode.removeChild(toast);
    }
  }, 6000);
}

function hideToast(that) {
  var el = that instanceof Element ? that : that[0];
  if (!el) {
    return;
  }
  el.classList.add("toast-hide");
  setTimeout(function () {
    if (el.parentNode) {
      el.parentNode.removeChild(el);
    }
  }, 500);
}
/** /TOAST **/


/* OPEN MODAL */
function openModal(popup) {
  $id = "#" + popup;
  $file = popup + ".php";

  $.ajax({
    url: $file,
    success: function (returndata) {
      $("#modalContainer").html(returndata);
      $($id).modal("show");
      sessionStorage.setItem("modal-open", true);
      initPopupLoad();
    },
    dataType: "html",
  });
}

/* VALIDATION EMAIL */
function validateEmail(email) {
  var re =
    /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
  return re.test(email);
}

/* VALIDATION MINIMUM CARACTERES */
function validateMinCar(champ, nbr) {
  if (champ.length < nbr) {
    return false;
  } else {
    return true;
  }
}

/* VALIDATION TELEPHONE */
function validatePhone(phone) {
  var re = /^(?:(?:\+|00)33|0)\s*[1-9](?:[\s.-]*\d{2}){4}$/;
  return re.test(phone);
}

/* VALIDATION SIRET */
function validateSiret(siret) {
  var re = /\d{14}/;
  return re.test(siret);
}

/* VALIDATION CP */
function validateCP(cp) {
  var re = /\d{5}/;
  return re.test(cp);
}

/* VALIDATION SITE WEB */
function validateSiteWeb(url) {
  if (
    !url.startsWith("http://") ||
    !url.startsWith("ftp://") ||
    !url.startsWith("https://")
  ) {
    url = "https://" + url;
  }
  var re =
    /(http|ftp|https):\/\/[\w-]+(\.[\w-]+)+([\w.,@?^=%&amp;:\/~+#-]*[\w@?^=%&amp;\/~+#-])?/;
  return re.test(url);
}

/* VALIDATION MOT DE PASSE */
function validatePassword(password) {
  var re = /^(?=.*\d)(?=.*[A-Z]).{8,50}$/;
  return re.test(password);
}

/* VALIDATION FICHIER FILEPOND */
function validateFilePondInput(fileInput) {
  if (fileInput.getFiles().length === 0) {
    return false;
  }
  return true;
}

/* LIEN URL */
function lien(url, targetBlank) {
  if (targetBlank) {
    window.open(url);
  } else {
    window.location.href = url;
  }
}

/* LIEN RETOUR */
function goBack() {
  window.history.back();
}

/* RECUPERATION PARAMETRE GET */
function decodeGet(param) {
  var vars = {};
  window.location.href.replace(location.hash, "").replace(
    /[?&]+([^=&]+)=?([^&]*)?/gi, // regexp
    function (m, key, value) {
      // callback
      vars[key] = value !== undefined ? value : "";
    }
  );

  if (param) {
    return vars[param] ? vars[param] : null;
  }
  return vars;
}

/* TOGGLE PWD VISIBILITY */
function pwdVisibility(that) {
  $btn = $(that);
  $input = $btn.siblings("input");

  if ($input.attr("type") === "password") {
    $input.prop("type", "text");
  } else {
    $input.prop("type", "password");
  }
  $btn.find("i").toggleClass("fa-eye-slash fa-eye");
}

/* COPY TEXT */
function copyText(text) {
  //console.log(text);
  navigator.permissions.query({ name: "clipboard-write" }).then((result) => {
    //console.log(result);
    if (result.state === "granted" || result.state === "prompt") {
      navigator.clipboard.writeText(text).then(
        () => {
          showToast("success", "La copie a bien été effectuée.");
          //console.log("COPY OK");
        },
        () => {
          showToast("danger", "Erreur lors de la copie.");
          //console.log("COPY FAIL");
        }
      );
    }
  });
}

function runSelect2() {
    $(".search-select").select2({
        placeholder: "Select a State",
        allowClear: true
    });
};