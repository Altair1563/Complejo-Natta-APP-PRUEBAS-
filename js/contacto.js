$(document).ready(function () {
  $("#form_contacto").on("submit", function (event) {
    event.preventDefault();

    var $form = $(this);

    if (typeof window.FormSecurity === "undefined") {
      $("#mensaje_exito")
        .html("Error al cargar el formulario. Recargá la página.")
        .show();
      return;
    }

    window.FormSecurity.ensureFormReady($form)
      .then(function () {
        $.ajax({
          type: "POST",
          url: "php/contacto.php",
          data: $form.serialize(),
          dataType: "json",
          success: function (response) {
            if (response.status === "success") {
              $("#mensaje_exito").html("Mensaje enviado con éxito.").show();
              $form[0].reset();
              window.FormSecurity.refreshForm($form);
            } else {
              $("#mensaje_exito")
                .html("Error: " + (response.message || "Hubo un problema"))
                .show();
            }
          },
          error: function (xhr) {
            var message = "Error de conexión o respuesta inválida.";
            if (xhr.responseJSON && xhr.responseJSON.message) {
              message = xhr.responseJSON.message;
            }
            $("#mensaje_exito").html(message).show();
          }
        });
      })
      .fail(function () {
        window.FormSecurity.showError();
      });
  });
});
