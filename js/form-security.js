(function ($) {
  "use strict";

  var securityReady = null;

  function showFormError(message) {
    var $msg = $("#mensaje_exito");
    if ($msg.length) {
      $msg.html(message).show();
    }
  }

  function ensureHiddenField($form, name, value) {
    var $field = $form.find('input[name="' + name + '"]');
    if ($field.length === 0) {
      $field = $('<input type="hidden">').attr("name", name);
      $form.prepend($field);
    }
    $field.val(value);
  }

  function ensureHoneypot($form) {
    if ($form.find('input[name="website"]').length > 0) {
      return;
    }
    var $honeypot = $(
      '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">' +
        '<label for="website_' + $form.attr("id") + '">No completar</label>' +
        '<input type="text" name="website" tabindex="-1" autocomplete="off">' +
      "</div>"
    );
    $form.prepend($honeypot);
  }

  function loadRecaptcha(siteKey) {
    if (!siteKey) {
      return $.Deferred().resolve().promise();
    }
    if (window.grecaptcha) {
      return $.Deferred().resolve().promise();
    }
    return $.getScript(
      "https://www.google.com/recaptcha/api.js?render=" + encodeURIComponent(siteKey)
    );
  }

  function getRecaptchaToken(siteKey) {
    if (!siteKey || !window.grecaptcha) {
      return $.Deferred().resolve("").promise();
    }
    return new $.Deferred(function (deferred) {
      grecaptcha.ready(function () {
        grecaptcha
          .execute(siteKey, { action: "submit" })
          .then(function (token) {
            deferred.resolve(token);
          })
          .catch(function () {
            deferred.resolve("");
          });
      });
    }).promise();
  }

  function prepareForm($form, data) {
    ensureHoneypot($form);
    ensureHiddenField($form, "csrf_token", data.csrf_token);
    return getRecaptchaToken(data.recaptcha_site_key).then(function (token) {
      if (token) {
        ensureHiddenField($form, "g-recaptcha-response", token);
      }
    });
  }

  function ensureFormReady($form) {
    if ($form.find('input[name="csrf_token"]').val()) {
      return $.Deferred().resolve().promise();
    }
    return window.FormSecurity.ready().then(function (data) {
      return prepareForm($form, data);
    });
  }

  window.FormSecurity = {
    ready: function () {
      if (!securityReady) {
        securityReady = $.getJSON("php/csrf_token.php")
          .then(function (data) {
            if (!data || !data.csrf_token) {
              return $.Deferred().reject("token_invalid").promise();
            }
            return loadRecaptcha(data.recaptcha_site_key).then(function () {
              return data;
            });
          })
          .fail(function () {
            securityReady = null;
          });
      }
      return securityReady;
    },

    prepareForm: prepareForm,

    refreshForm: function ($form) {
      securityReady = null;
      return window.FormSecurity.ready().then(function (data) {
        return prepareForm($form, data);
      });
    },

    ensureFormReady: ensureFormReady,

    showError: function () {
      showFormError("No se pudo preparar el formulario. Recargá la página e intentá de nuevo.");
    }
  };

  $(document).ready(function () {
    window.FormSecurity.ready()
      .then(function (data) {
        $("#form_contacto, .js-curriculum-form").each(function () {
          prepareForm($(this), data);
        });
      })
      .fail(function () {
        showFormError("No se pudo cargar la protección del formulario. Recargá la página.");
      });
  });
})(jQuery);
