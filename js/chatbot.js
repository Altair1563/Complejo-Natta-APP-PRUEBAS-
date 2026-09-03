/* =========================================================
   CHATBOT - Widget flotante (Complejo Natta)
   - Crea botón + ventana
   - Maneja sugerencias
   - Envía mensajes a /php/chat_gemini.php
========================================================= */
  // ====== CONFIG BÁSICA ======
  const INITIAL_MESSAGE = `Hola 👋 Soy el asistente virtual del Complejo Educativo Pbro. Eliseo Esteban Natta.

Para ayudarte correctamente...
1️⃣ Indicá primero la **institución**
2️⃣ Luego contame **qué necesitás** (inscripción, vacantes, pagos, contacto, horarios)
Ejemplo:
👉 Quiero inscribirme en La Casita de Jesús
👉 Contacto del Instituto Santa Cruz
👉 Pagos del Instituto Manuel Belgrano

De esta manera puedo ayudarte sin confusiones 🙂`;

  const SUGGESTED_QUESTIONS = [
    "¿Cómo hago la Solicitud de Vacante?",
    "¿Cómo contacto Administración?"
  ];

// ===== API: enviar mensaje al backend =====
async function sendMessageToGemini(message) {
  const url = "/php/chat_gemini.php";

  let r;
  try {
    r = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ message })
    });
  } catch (err) {
    throw new Error("No se pudo conectar con el servidor. Revisá /php/chat_gemini.php.");
  }

  const raw = await r.text();
  let data = null;
  try { data = JSON.parse(raw); } catch (_) { /* puede venir texto o HTML */ }

  if (!r.ok) {
    const msg = (data && (data.error || data.message)) ? (data.error || data.message) : raw;
    throw new Error(`HTTP ${r.status}: ${String(msg).slice(0, 300)}`);
  }

  if (data && typeof data.text === "string" && data.text.trim() !== "") return data.text;
  if (!data && raw.trim() !== "") return raw;

  return "No pude generar una respuesta. Intentá nuevamente.";
}

// ===== Widget =====
class ChatWidget {
  constructor() {
    this.isOpen = false;

    // Contenedor
    this.container = document.createElement("div");
    this.container.className = "chatbot-container";

    // Ventana
    this.chatWindow = document.createElement("div");
    this.chatWindow.className = "chatbot-window chatbot-hidden";
    this.chatWindow.innerHTML = `
      <div class="chatbot-header">
        <div>
          <div class="chatbot-title">Asistente Virtual (beta)</div>
          <div class="chatbot-subtitle">Complejo Natta</div>
        </div>
        <button type="button" id="close-chat-btn" class="chatbot-close" aria-label="Cerrar">✕</button>
      </div>

      <div id="chat-messages" class="chatbot-messages"></div>

      <div id="suggested-questions" class="chatbot-suggestions"></div>

      <form id="chat-form" class="chatbot-form" autocomplete="off">
        <input id="chat-input" type="text" placeholder="Escribe tu consulta..." autocomplete="off" />
        <button id="send-btn" type="submit" aria-label="Enviar">➤</button>
      </form>
    `;

    // Botón flotante
    this.toggleButton = document.createElement("button");
    this.toggleButton.type = "button";
    this.toggleButton.className = "chatbot-fab";
    this.toggleButton.title = "¿Necesitas ayuda?";
    this.toggleButton.innerHTML = `
      <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
      <span class="sr-only">Abrir chat</span>
    `;

    // Montar en el DOM
    this.container.appendChild(this.chatWindow);
    this.container.appendChild(this.toggleButton);
    document.body.appendChild(this.container);

    // Referencias
    this.messagesContainer = this.chatWindow.querySelector("#chat-messages");
    const closeBtn = this.chatWindow.querySelector("#close-chat-btn");
    const form = this.chatWindow.querySelector("#chat-form");
    const input = this.chatWindow.querySelector("#chat-input");
    const sendBtn = this.chatWindow.querySelector("#send-btn");

    // Eventos
    this.toggleButton.addEventListener("click", () => this.toggle());
    closeBtn.addEventListener("click", () => this.toggle());

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const text = input.value.trim();
      if (!text) return;

      input.value = "";
      this.addMessage("user", text);

      this.setLoading(true);
      input.disabled = true;
      sendBtn.disabled = true;

      try {
        const resp = await sendMessageToGemini(text);
        this.setLoading(false);
        this.addMessage("model", resp);
      } catch (err) {
        this.setLoading(false);
        this.addMessage("model", "⚠️ " + (err?.message || String(err)));
      } finally {
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
      }
    });

    // Sugerencias
    const suggestions = this.chatWindow.querySelector("#suggested-questions");
    SUGGESTED_QUESTIONS.forEach((q) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chatbot-sug-btn";
      b.textContent = q;
      b.addEventListener("click", () => {
        input.value = q;
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit"));
      });
      suggestions.appendChild(b);
    });

    // Mensaje inicial
    this.addMessage("model", INITIAL_MESSAGE);
  }

  toggle() {
    this.isOpen = !this.isOpen;

    if (this.isOpen) {
      this.chatWindow.classList.remove("chatbot-hidden");
      this.scrollToBottom();
      this.chatWindow.querySelector("#chat-input")?.focus();
    } else {
      this.chatWindow.classList.add("chatbot-hidden");
    }
  }

  addMessage(role, text) {
    const wrap = document.createElement("div");
    wrap.className = "chatbot-msg " + (role === "user" ? "chatbot-msg-user" : "chatbot-msg-model");
    wrap.innerHTML = `<div class="chatbot-bubble">${this.formatText(text)}</div>`;
    this.messagesContainer.appendChild(wrap);
    this.scrollToBottom();
  }

  // Sanitiza y soporta **negrita** + saltos de línea
  formatText(t) {
    return String(t)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>")
      .replace(/\n/g, "<br>");
  }

  setLoading(on) {
    let el = this.messagesContainer.querySelector("#chatbot-loading");

    if (on) {
      if (el) return;
      el = document.createElement("div");
      el.id = "chatbot-loading";
      el.className = "chatbot-msg chatbot-msg-model";
      el.innerHTML = `<div class="chatbot-bubble">Escribiendo…</div>`;
      this.messagesContainer.appendChild(el);
      this.scrollToBottom();
      return;
    }

    el?.remove();
  }

  scrollToBottom() {
    this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
  }
}

// Inicializar
document.addEventListener("DOMContentLoaded", () => {
  new ChatWidget();
});
