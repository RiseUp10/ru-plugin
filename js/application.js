// Aplicación al monoprodotto — UX de una pregunta a la vez.
// Ver ru-plugin/includes/application-core.php para los endpoints AJAX y
// ru-plugin/RU-SUBSCRIPTION-SYSTEM-PLAN.md sección 7.5 para el flujo.
document.addEventListener('DOMContentLoaded', function () {
  const root = document.getElementById('ru-application-root');
  if (!root) return;

  const STORAGE_KEY = 'ru_application_id';

  // Preguntas de negocio, en orden — se muestran recién después de
  // verificar email + celular. "optional: true" no bloquea el avance.
  const QUESTIONS = [
    { field: 'business_name', label: 'Come si chiama la tua attività?', type: 'text' },
    { field: 'story',         label: 'Raccontami qualcosa della tua attività.', type: 'textarea' },
    { field: 'goal',          label: 'Cosa vuoi ottenere con questo sito web?', type: 'textarea' },
    {
      // Grupo de campos en una sola pantalla — todos opcionales, dejarlos
      // todos vacíos ya es un dato en sí (no tiene presencia todavía).
      label: 'Hai già un sito web o degli account social? (facoltativo)',
      optional: true,
      fields: [
        { field: 'website',   label: 'Sito web',  type: 'text' },
        { field: 'instagram', label: 'Instagram', type: 'text' },
        { field: 'tiktok',    label: 'TikTok',    type: 'text' },
        { field: 'facebook',  label: 'Facebook',  type: 'text' },
      ],
    },
    { field: 'location', label: 'Dove si trova la tua attività? (facoltativo)', type: 'text', optional: true },
  ];

  function post(action, data) {
    const formData = new FormData();
    formData.append('action', action);
    Object.keys(data || {}).forEach((k) => formData.append(k, data[k]));
    return fetch(ruApplicationData.ajaxurl, { method: 'POST', body: formData }).then((r) => r.json());
  }

  function render(html) {
    root.innerHTML = html;
  }

  function renderError(message) {
    const box = root.querySelector('.ru-app-error');
    if (box) box.textContent = message || 'Errore, riprova.';
  }

  // -------------------------------------------------------------
  // Paso: email
  // -------------------------------------------------------------
  function showEmailStep() {
    render(`
      <div class="ru-app-step">
        <p class="ru-app-intro">Prima di tutto verifichiamo che sia davvero tu — giusto per evitare richieste inutili.</p>
        <label>Qual è la tua email?</label>
        <input type="email" id="ru-app-email" autocomplete="email" required>
        <input type="text" name="hp_field" id="ru-app-hp" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;">
        <button type="button" id="ru-app-email-submit">Avanti</button>
        <p class="ru-app-error"></p>
      </div>
    `);

    const submit = () => {
      const email = root.querySelector('#ru-app-email').value.trim();
      const hp = root.querySelector('#ru-app-hp').value;
      if (!email) return renderError('Inserisci un\'email valida.');

      post('ru_application_start', { email, hp_field: hp }).then((res) => {
        if (!res.success) return renderError(res.message);
        if (res.post_id) localStorage.setItem(STORAGE_KEY, res.post_id);
        showEmailWaitingStep(res.post_id);
      });
    };

    root.querySelector('#ru-app-email-submit').addEventListener('click', submit);
    root.querySelector('#ru-app-email').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') submit();
    });
  }

  function showEmailWaitingStep(postId) {
    render(`
      <div class="ru-app-step">
        <p>Controlla la tua email e clicca sul link di conferma per continuare.</p>
        <p><button type="button" id="ru-app-resend" class="ru-app-link-button">Non hai ricevuto nulla? Invia di nuovo l'email</button></p>
        <p class="ru-app-error"></p>
      </div>
    `);

    root.querySelector('#ru-app-resend').addEventListener('click', () => {
      post('ru_application_resend_email', { post_id: postId }).then((res) => {
        renderError(res.message);
      });
    });
  }

  // -------------------------------------------------------------
  // Paso: celular (código SMS)
  // -------------------------------------------------------------
  function showPhoneStep(postId) {
    render(`
      <div class="ru-app-step">
        <p class="ru-app-intro">Ancora un attimo per confermare che sei proprio tu.</p>
        <label>Qual è il tuo numero di cellulare?</label>
        <input type="tel" id="ru-app-phone" autocomplete="tel" required>
        <button type="button" id="ru-app-phone-submit">Invia codice</button>
        <p class="ru-app-error"></p>
      </div>
    `);

    const submit = () => {
      const phone = root.querySelector('#ru-app-phone').value.trim();
      if (!phone) return renderError('Inserisci un numero valido.');

      post('ru_application_send_sms_code', { post_id: postId, phone }).then((res) => {
        if (!res.success) return renderError(res.message);
        showSmsCodeStep(postId);
      });
    };

    root.querySelector('#ru-app-phone-submit').addEventListener('click', submit);
    root.querySelector('#ru-app-phone').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') submit();
    });
  }

  function showSmsCodeStep(postId) {
    render(`
      <div class="ru-app-step">
        <label>Inserisci il codice che ti abbiamo mandato via SMS.</label>
        <input type="text" id="ru-app-code" inputmode="numeric" maxlength="6" required>
        <button type="button" id="ru-app-code-submit">Conferma</button>
        <p class="ru-app-error"></p>
      </div>
    `);

    const submit = () => {
      const code = root.querySelector('#ru-app-code').value.trim();
      if (!code) return renderError('Inserisci il codice.');

      post('ru_application_verify_sms_code', { post_id: postId, code }).then((res) => {
        if (!res.success) return renderError(res.message);
        showQuestionStep(postId, 0);
      });
    };

    root.querySelector('#ru-app-code-submit').addEventListener('click', submit);
    root.querySelector('#ru-app-code').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') submit();
    });
  }

  // -------------------------------------------------------------
  // Paso: preguntas de negocio (una a la vez)
  // -------------------------------------------------------------
  // Enter avanza en inputs de una línea; en textarea, Enter hace salto de
  // línea como es esperable (no dispara el submit).
  function bindEnterToSubmit(el, submit) {
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submit();
      }
    });
  }

  function showQuestionStep(postId, index) {
    if (index >= QUESTIONS.length) {
      return submitApplication(postId);
    }

    const q = QUESTIONS[index];

    // Pregunta agrupada (varios campos en una sola pantalla, ej. presencia digital).
    if (q.fields) {
      const inputsHtml = q.fields.map((f) => `
        <label>${f.label}</label>
        <input type="text" id="ru-app-group-${f.field}">
      `).join('');

      render(`
        <div class="ru-app-step">
          <label class="ru-app-group-label">${q.label}</label>
          ${inputsHtml}
          <button type="button" id="ru-app-field-submit">Avanti</button>
          <p class="ru-app-error"></p>
        </div>
      `);

      const submit = () => {
        const saves = q.fields
          .map((f) => ({ field: f.field, value: root.querySelector(`#ru-app-group-${f.field}`).value.trim() }))
          .filter((f) => f.value !== '')
          .map((f) => post('ru_application_save_field', { post_id: postId, field: f.field, value: f.value }));

        Promise.all(saves).then((results) => {
          const failed = results.find((res) => !res.success);
          if (failed) return renderError(failed.message);
          showQuestionStep(postId, index + 1);
        });
      };

      root.querySelector('#ru-app-field-submit').addEventListener('click', submit);
      q.fields.forEach((f) => bindEnterToSubmit(root.querySelector(`#ru-app-group-${f.field}`), submit));
      return;
    }

    // Pregunta simple (un solo campo).
    const fieldHtml = q.type === 'textarea'
      ? `<textarea id="ru-app-field" rows="4"></textarea>`
      : `<input type="text" id="ru-app-field">`;

    render(`
      <div class="ru-app-step">
        <label>${q.label}</label>
        ${fieldHtml}
        <button type="button" id="ru-app-field-submit">Avanti</button>
        <p class="ru-app-error"></p>
      </div>
    `);

    const submit = () => {
      const value = root.querySelector('#ru-app-field').value.trim();
      if (!value && !q.optional) return renderError('Questo campo è obbligatorio.');

      post('ru_application_save_field', { post_id: postId, field: q.field, value }).then((res) => {
        if (!res.success) return renderError(res.message);
        showQuestionStep(postId, index + 1);
      });
    };

    root.querySelector('#ru-app-field-submit').addEventListener('click', submit);
    // Textarea: Enter hace salto de línea (no submit) — solo el input de
    // una línea avanza con Enter.
    if (q.type !== 'textarea') {
      bindEnterToSubmit(root.querySelector('#ru-app-field'), submit);
    }
  }

  function submitApplication(postId) {
    post('ru_application_submit', { post_id: postId }).then((res) => {
      render(`<div class="ru-app-step"><p>${res.message || 'Candidatura inviata.'}</p></div>`);
      localStorage.removeItem(STORAGE_KEY);
    });
  }

  // -------------------------------------------------------------
  // Punto de entrada — decide dónde arrancar/retomar
  // -------------------------------------------------------------
  const urlParams = new URLSearchParams(window.location.search);
  const urlPostId = urlParams.get('post_id');
  const urlStatus = urlParams.get('status');
  const storedPostId = localStorage.getItem(STORAGE_KEY);

  // Nunca confiar ciegamente en el query string (post_id es adivinable) —
  // siempre se re-chequea el estado real contra el servidor.
  const postId = urlPostId || storedPostId;

  if (!postId) {
    showEmailStep();
  } else {
    localStorage.setItem(STORAGE_KEY, postId);
    post('ru_application_status', { post_id: postId }).then((res) => {
      if (!res.success) return showEmailStep();

      if (!res.email_verified) {
        // Si llegó con status=ok pero el server dice que no está confirmado,
        // algo no cuadra (link viejo/inválido) — se muestra la espera igual,
        // más seguro que reiniciar desde cero.
        return showEmailWaitingStep(postId);
      }
      if (!res.phone_verified) {
        return showPhoneStep(postId);
      }
      if (res.application_status === 'submitted') {
        return render('<div class="ru-app-step"><p>Candidatura già inviata. Ti risponderemo a breve.</p></div>');
      }
      showQuestionStep(postId, 0);
    });
  }
});
