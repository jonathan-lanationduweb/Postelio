/**
 * Espace recruteur — Entretiens (espace-entreprise-entretiens.html).
 * Liste des entretiens + planification (modale). Le candidat reçoit ensuite
 * (côté espace candidat, simulé) une notification pour CONFIRMER le rendez-vous.
 */
(function () {
  "use strict";

  var KEY = "ss_interviews_v1";

  document.addEventListener("DOMContentLoaded", function () {
    if (!window.EMP || !EMP.ready) { return; }
    if (!document.getElementById("interviews-list")) { return; }
    seedIfEmpty();
    buildHeader();
    buildModal();
    render();
  });

  function seedIfEmpty() {
    if (SS.store.get(KEY, null)) { return; }
    SS.store.set(KEY, [
      { id: "iv1", nom: "Julie Martin", poste: "Assistante commerciale", offre: "Assistant(e) commercial(e) — CDI", date: EMP.dateFromToday(3), heure: "14:30", mode: "Visioconférence", lieu: "Lien envoyé par e-mail", statut: "confirme" },
      { id: "iv2", nom: "Thomas Ravel", poste: "Préparateur de commandes", offre: "Préparateur de commandes — CDI", date: EMP.dateFromToday(4), heure: "10:00", mode: "Dans nos locaux", lieu: "Lyon 3e", statut: "attente" },
      { id: "iv3", nom: "Inès Fabre", poste: "Chargée de communication", offre: "Chargé(e) de communication — CDD", date: EMP.dateFromToday(6), heure: "16:15", mode: "Téléphone", lieu: "", statut: "attente" }
    ]);
  }

  function getAll() { return SS.store.get(KEY, []); }

  /* ---- En-tête : titre + bouton Planifier ---- */
  function buildHeader() {
    var list = document.getElementById("interviews-list");
    var h2 = document.querySelector('#entretiens h2, [aria-labelledby="h-entretiens"] h2, h2');
    var bar = document.createElement("div");
    bar.className = "dash-block__head";
    bar.innerHTML = '<p class="dash-block__hint">Planifiez vos rendez-vous : le candidat reçoit une notification pour confirmer.</p>' +
      '<button type="button" class="btn btn-accent btn-sm" id="iv-plan-btn">+ Planifier un entretien</button>';
    list.parentNode.insertBefore(bar, list);
  }

  /* ---- Rendu de la liste ---- */
  function render() {
    var box = document.getElementById("interviews-list");
    if (!box) { return; }
    var e = SS.escapeHtml;
    var items = getAll();

    if (!items.length) {
      box.innerHTML = '<div class="empty-state"><h3>Aucun entretien planifié</h3>' +
        '<p>Cliquez sur « Planifier un entretien » ou proposez-en un depuis le pipeline de candidatures.</p></div>';
      return;
    }

    box.innerHTML = items.map(function (it) {
      var badge = it.statut === "confirme"
        ? '<span class="status-badge status-preselection">Confirmé</span>'
        : '<span class="status-badge status-envoyee">En attente de confirmation</span>';
      var when = SS.formatDate(it.date) + " · " + e(it.heure);
      var mode = e(it.mode) + (it.lieu ? " — " + e(it.lieu) : "");
      return '<article class="appli-card interview-card">' +
          '<div class="appli-card__top">' +
            '<div><strong>' + e(it.nom) + '</strong><br><span class="text-muted">' + e(it.poste) + '</span></div>' +
            badge +
          '</div>' +
          '<p class="interview-card__when">' + when + '</p>' +
          '<p class="interview-card__mode text-muted">' + mode + (it.offre ? ' · ' + e(it.offre) : "") + '</p>' +
          '<div class="row-actions">' +
            '<button type="button" class="btn btn-outline btn-sm" data-toast="Ouverture du profil candidat (démonstration).">Voir le profil</button>' +
            '<a class="btn btn-ghost btn-sm" href="espace-entreprise-messages.html?to=' + encodeURIComponent(it.nom) + '">Message</a>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-iv-cancel="' + e(it.id) + '">Annuler</button>' +
          '</div>' +
        '</article>';
    }).join("");

    box.querySelectorAll("[data-iv-cancel]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (!window.confirm("Annuler cet entretien ?")) { return; }
        var id = btn.getAttribute("data-iv-cancel");
        SS.store.set(KEY, getAll().filter(function (x) { return x.id !== id; }));
        render();
        SS.toast("Entretien annulé.");
      });
    });
  }

  /* ---- Modale « Planifier un entretien » ---- */
  function buildModal() {
    var e = SS.escapeHtml;
    var candidates = ["Camille Reynaud", "Karim Haddad", "Sophie Lemaire", "Léa Dubois", "Malik Benhaddou", "Awa Diallo", "Autre candidat"];
    var overlay = document.createElement("div");
    overlay.className = "modal-overlay";
    overlay.id = "iv-modal";
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="iv-modal-title">' +
        '<div class="modal__head"><h2 class="modal__title" id="iv-modal-title">Planifier un entretien</h2>' +
          '<button type="button" class="modal-close" data-iv-close aria-label="Fermer">✕</button></div>' +
        '<form class="modal__body" id="iv-form">' +
          '<div class="field"><label for="iv-nom">Candidat</label>' +
            '<select id="iv-nom">' + candidates.map(function (c) { return '<option>' + e(c) + "</option>"; }).join("") + "</select></div>" +
          '<div class="field"><label for="iv-offre">Offre concernée</label>' +
            '<input type="text" id="iv-offre" placeholder="Ex. : Assistant(e) commercial(e) — CDI"></div>' +
          '<div class="form-row">' +
            '<div class="field"><label for="iv-date">Date</label><input type="date" id="iv-date"></div>' +
            '<div class="field"><label for="iv-heure">Heure</label><input type="time" id="iv-heure" value="14:30"></div>' +
          '</div>' +
          '<div class="field"><label for="iv-mode">Format</label>' +
            '<select id="iv-mode"><option>Visioconférence</option><option>Téléphone</option><option>Dans nos locaux</option></select></div>' +
          '<div class="field"><label for="iv-lieu">Lieu / lien</label><input type="text" id="iv-lieu" placeholder="Lien visio, adresse ou numéro"></div>' +
          '<div class="field"><label for="iv-msg">Message au candidat</label>' +
            '<textarea id="iv-msg" rows="3">Bonjour, nous serions ravis de vous rencontrer pour un entretien. Merci de confirmer votre disponibilité.</textarea></div>' +
        "</form>" +
        '<div class="modal__actions">' +
          '<button type="button" class="btn btn-outline" data-iv-close>Annuler</button>' +
          '<button type="button" class="btn btn-primary" id="iv-send">Envoyer l\'invitation</button>' +
        "</div>" +
      "</div>";
    document.body.appendChild(overlay);

    var dateInput = overlay.querySelector("#iv-date");
    if (dateInput) { dateInput.value = EMP.dateFromToday(3); }

    var planBtn = document.getElementById("iv-plan-btn");
    var lastFocus = null;
    function open(o) {
      overlay.hidden = !o;
      if (o) { lastFocus = document.activeElement; var f = overlay.querySelector("select,input,textarea"); if (f) { f.focus(); } }
      else if (lastFocus) { lastFocus.focus(); }
    }
    if (planBtn) { planBtn.addEventListener("click", function () { open(true); }); }
    overlay.querySelectorAll("[data-iv-close]").forEach(function (b) { b.addEventListener("click", function () { open(false); }); });
    overlay.addEventListener("click", function (ev) { if (ev.target === overlay) { open(false); } });
    document.addEventListener("keydown", function (ev) { if (ev.key === "Escape" && !overlay.hidden) { open(false); } });

    overlay.querySelector("#iv-send").addEventListener("click", function () {
      var g = function (id) { var el = overlay.querySelector("#" + id); return el ? el.value : ""; };
      var iv = {
        id: "iv" + Date.now(),
        nom: g("iv-nom"), poste: "Candidat", offre: g("iv-offre"),
        date: g("iv-date") || EMP.dateFromToday(3), heure: g("iv-heure") || "14:30",
        mode: g("iv-mode"), lieu: g("iv-lieu"), statut: "attente"
      };
      var all = getAll(); all.push(iv); SS.store.set(KEY, all);
      open(false);
      render();
      SS.toast("Invitation envoyée — le candidat recevra une notification pour confirmer.");
    });
  }
})();
