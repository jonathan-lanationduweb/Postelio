/**
 * Formulaire de contact (envoi simulé).
 * En production : envoi vers APP_CONFIG.api.endpoints.contact.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("contact-form");
    if (!form) { return; }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!SS.validateForm(form)) { return; }
      form.hidden = true;
      var success = document.getElementById("contact-success");
      success.hidden = false;
      success.focus();
    });
  });
})();
