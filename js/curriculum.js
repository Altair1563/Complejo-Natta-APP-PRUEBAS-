$(document).ready(function () {
  var AREAS_CON_REQUISITOS = [
    "jardin_inicial",
    "primaria",
    "secundario",
    "ingles",
    "informatica"
  ];

  function areaRequiereSuplencias(area) {
    return AREAS_CON_REQUISITOS.indexOf(area) !== -1;
  }

  function getRequisitosBlock($form) {
    return $form.find(".requisitos-suplencias");
  }

  function resetRequisitos($form) {
    var $block = getRequisitosBlock($form);
    $block.prop("hidden", true);
    $block.find('input[type="checkbox"]').prop("checked", false);
    $block.find(".requisitos-suplencias__error").prop("hidden", true).text("");
  }

  function toggleRequisitos($form) {
    var area = $form.find('[name="area"]').val() || "";
    var $block = getRequisitosBlock($form);

    if (!areaRequiereSuplencias(area)) {
      resetRequisitos($form);
      return;
    }

    $block.prop("hidden", false);
    $block.find(".requisitos-suplencias__error").prop("hidden", true).text("");
  }

  function validarRequisitos($form) {
    var area = $form.find('[name="area"]').val() || "";
    var $block = getRequisitosBlock($form);
    var $error = $block.find(".requisitos-suplencias__error");

    if (!areaRequiereSuplencias(area)) {
      return true;
    }

    var tituloOk = $block.find('[name="requisito_titulo_consejo"]').is(":checked");
    var certificadoOk = $block.find('[name="requisito_certificado_aptitud"]').is(":checked");

    if (tituloOk && certificadoOk) {
      $error.prop("hidden", true).text("");
      return true;
    }

    $error
      .text("Debés marcar los requisitos obligatorios (*) para enviar tu postulación.")
      .prop("hidden", false);
    $block[0].scrollIntoView({ behavior: "smooth", block: "nearest" });
    return false;
  }

  function initFileUpload($form) {
    var $fileInput = $form.find('input[type="file"][name="curriculum"]');
    var $fileZone = $form.find(".file-upload-zone");
    var $fileNameDisplay = $form.find(".file-name-display");

    $fileInput.on("change", function () {
      var file = this.files && this.files[0];
      if (file) {
        $fileNameDisplay.text(file.name);
        $fileZone.addClass("has-file");
      } else {
        $fileNameDisplay.text("");
        $fileZone.removeClass("has-file");
      }
    });
  }

  $(".js-curriculum-form").each(function () {
    var $form = $(this);
    initFileUpload($form);

    $form.find('[name="area"]').on("change", function () {
      toggleRequisitos($form);
    });

    $form.on("submit", function (event) {
      event.preventDefault();

      if (!validarRequisitos($form)) {
        return;
      }

      var $msg = $form.closest(".contact_form-container").find(".trabaja_alert").first();

      if (typeof window.FormSecurity === "undefined") {
        $msg
          .removeClass("alert-success")
          .addClass("alert alert-danger")
          .text("Error al cargar el formulario. Recargá la página.")
          .show();
        return;
      }

      var $submitBtn = $form.find('button[type="submit"]');
      $submitBtn.prop("disabled", true);
      $msg
        .removeClass("alert-success alert-danger")
        .addClass("alert")
        .text("Enviando...")
        .show();

      window.FormSecurity.ensureFormReady($form)
        .then(function () {
          var formData = new FormData($form[0]);
          return $.ajax({
            url: "php/procesar_curriculum.php",
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            dataType: "json"
          });
        })
        .done(function (response) {
          if (response && response.ok) {
            $msg
              .removeClass("alert-danger")
              .addClass("alert alert-success")
              .text(response.msg || "¡Gracias! Recibimos su CV.")
              .show();
            $form[0].reset();
            $form.find(".file-name-display").text("");
            $form.find(".file-upload-zone").removeClass("has-file");
            resetRequisitos($form);
            window.FormSecurity.refreshForm($form);
          } else {
            $msg
              .removeClass("alert-success")
              .addClass("alert alert-danger")
              .text((response && response.msg) || "No se pudo enviar el formulario.")
              .show();
          }
        })
        .fail(function (xhr) {
          var message = "Error de conexión. Intente nuevamente.";
          if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
            message = xhr.responseJSON.msg;
          } else if (!xhr || !xhr.responseJSON) {
            window.FormSecurity.showError();
            return;
          }
          $msg
            .removeClass("alert-success")
            .addClass("alert alert-danger")
            .text(message)
            .show();
        })
        .always(function () {
          $submitBtn.prop("disabled", false);
        });
    });
  });
});
