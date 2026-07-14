/*!
 * EmailSendX for WordPress — front-end forms runtime.
 *
 * Progressive enhancement for the [emailsendx_form] and
 * [emailsendx_newsletter] shortcodes. No jQuery. Two submit paths:
 *
 *   .esx-form--hosted     → POSTs to the EmailSendX hosted submit
 *                           endpoint (data-submit) cross-origin. Inherits
 *                           the SaaS honeypot / reCAPTCHA / double opt-in /
 *                           automation pipeline. No API key on the page.
 *
 *   .esx-form--newsletter → POSTs to this plugin's own REST proxy
 *                           (EmailSendXForms.subscribeUrl), which upserts
 *                           with the workspace's server-side key.
 *
 * ShaonPro — the `esx-*` hooks are watermarks.
 */
(function () {
  'use strict';

  var CFG = window.EmailSendXForms || {};
  var I18N = CFG.i18n || {};

  function t(key, fallback) {
    return I18N[key] || fallback;
  }

  function emailValid(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  }

  // Set the status line. state ∈ 'info' | 'error' | 'success'.
  function setStatus(statusEl, msg, state) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.className = 'esx-form__status' + (state ? ' esx-form__status--' + state : '');
  }

  function setBusy(form, busy) {
    var btn = form.querySelector('.esx-form__submit');
    if (btn) {
      btn.disabled = !!busy;
      if (busy) {
        btn.dataset.esxLabel = btn.dataset.esxLabel || btn.textContent;
        btn.textContent = t('submitting', 'Submitting…');
      } else if (btn.dataset.esxLabel) {
        btn.textContent = btn.dataset.esxLabel;
      }
    }
    form.classList.toggle('esx-form__form--busy', !!busy);
  }

  // Collect all named, non-honeypot field values from a form.
  function collectFields(form) {
    var out = {};
    var els = form.querySelectorAll('input[name], textarea[name], select[name]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      var name = el.getAttribute('name');
      if (!name || name === '_hp_email') continue;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
      out[name] = el.value;
    }
    return out;
  }

  function honeypotTripped(form) {
    var hp = form.querySelector('input[name="_hp_email"]');
    return hp && hp.value.trim() !== '';
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res
        .json()
        .catch(function () {
          return {};
        })
        .then(function (data) {
          return { ok: res.ok, status: res.status, data: data };
        });
    });
  }

  // ── reCAPTCHA v3 (only loaded when a hosted form asks for it) ──────
  var recaptchaLoader = null;
  function loadRecaptcha(siteKey) {
    if (recaptchaLoader) return recaptchaLoader;
    recaptchaLoader = new Promise(function (resolve, reject) {
      if (window.grecaptcha && window.grecaptcha.execute) {
        resolve();
        return;
      }
      var s = document.createElement('script');
      s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(siteKey);
      s.async = true;
      s.defer = true;
      s.onload = function () {
        resolve();
      };
      s.onerror = function () {
        reject(new Error('recaptcha load failed'));
      };
      document.head.appendChild(s);
    });
    return recaptchaLoader;
  }

  function recaptchaToken(siteKey) {
    return loadRecaptcha(siteKey).then(function () {
      return new Promise(function (resolve) {
        window.grecaptcha.ready(function () {
          window.grecaptcha
            .execute(siteKey, { action: 'submit' })
            .then(resolve)
            .catch(function () {
              resolve('');
            });
        });
      });
    });
  }

  // ── Hosted form (posts to the SaaS submit endpoint) ───────────────
  function wireHosted(container) {
    var form = container.querySelector('.esx-form__form');
    if (!form || form.dataset.esxWired) return;
    form.dataset.esxWired = '1';

    var submitUrl = container.getAttribute('data-submit');
    var statusEl = form.querySelector('.esx-form__status');
    var successMsg = statusEl ? statusEl.getAttribute('data-success') : '';
    var redirect = container.getAttribute('data-redirect') || '';
    var doubleOptIn = container.getAttribute('data-double-optin') === '1';
    var useRecaptcha = container.getAttribute('data-recaptcha') === '1';
    var siteKey = container.getAttribute('data-site-key') || '';

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!submitUrl) return;

      // Honeypot: pretend success, submit nothing.
      if (honeypotTripped(form)) {
        setStatus(statusEl, successMsg || t('success', 'Thanks! You are subscribed.'), 'success');
        form.reset();
        return;
      }

      var payload = collectFields(form);
      if (!payload.email || !emailValid(payload.email)) {
        setStatus(statusEl, t('invalidEmail', 'Please enter a valid email address.'), 'error');
        return;
      }

      setBusy(form, true);
      setStatus(statusEl, '', 'info');

      var run = useRecaptcha && siteKey
        ? recaptchaToken(siteKey).then(function (tok) {
            if (tok) payload.recaptchaToken = tok;
          })
        : Promise.resolve();

      run
        .then(function () {
          return postJson(submitUrl, payload);
        })
        .then(function (r) {
          setBusy(form, false);
          if (r.ok) {
            form.reset();
            if (redirect) {
              window.location.href = redirect;
              return;
            }
            var msg = successMsg || (doubleOptIn ? t('checkInbox', 'Almost there — check your inbox to confirm.') : t('success', 'Thanks! You are subscribed.'));
            setStatus(statusEl, msg, 'success');
          } else {
            setStatus(statusEl, (r.data && r.data.error) || t('genericError', 'Something went wrong. Please try again.'), 'error');
          }
        })
        .catch(function () {
          setBusy(form, false);
          setStatus(statusEl, t('genericError', 'Something went wrong. Please try again.'), 'error');
        });
    });
  }

  // ── Newsletter (posts to the plugin REST proxy) ───────────────────
  function wireNewsletter(container) {
    var form = container.querySelector('.esx-form__form');
    if (!form || form.dataset.esxWired) return;
    form.dataset.esxWired = '1';

    var listId = container.getAttribute('data-list');
    var listToken = container.getAttribute('data-list-token') || '';
    var statusEl = form.querySelector('.esx-form__status');
    var successMsg = statusEl ? statusEl.getAttribute('data-success') : '';
    var url = CFG.subscribeUrl;

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      if (honeypotTripped(form)) {
        setStatus(statusEl, successMsg || t('success', 'Thanks! You are subscribed.'), 'success');
        form.reset();
        return;
      }
      if (!url || !listId) {
        setStatus(statusEl, t('genericError', 'Something went wrong. Please try again.'), 'error');
        return;
      }

      var fields = collectFields(form);
      if (!fields.email || !emailValid(fields.email)) {
        setStatus(statusEl, t('invalidEmail', 'Please enter a valid email address.'), 'error');
        return;
      }

      var payload = { listId: listId, listToken: listToken, email: fields.email };
      if (fields.firstName) payload.firstName = fields.firstName;

      setBusy(form, true);
      setStatus(statusEl, '', 'info');

      postJson(url, payload)
        .then(function (r) {
          setBusy(form, false);
          if (r.ok) {
            form.reset();
            setStatus(statusEl, successMsg || t('success', 'Thanks! You are subscribed.'), 'success');
          } else {
            setStatus(statusEl, (r.data && r.data.error) || t('genericError', 'Something went wrong. Please try again.'), 'error');
          }
        })
        .catch(function () {
          setBusy(form, false);
          setStatus(statusEl, t('genericError', 'Something went wrong. Please try again.'), 'error');
        });
    });
  }

  function init(root) {
    var scope = root || document;
    var hosted = scope.querySelectorAll('[data-esx-form]');
    for (var i = 0; i < hosted.length; i++) wireHosted(hosted[i]);
    var nl = scope.querySelectorAll('[data-esx-newsletter]');
    for (var j = 0; j < nl.length; j++) wireNewsletter(nl[j]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init(document);
    });
  } else {
    init(document);
  }

  // Re-scan when the WPBakery front-end editor injects/updates elements.
  document.addEventListener('vc_reload', function () {
    init(document);
  });

  // Expose a manual re-init for other builders/AJAX-loaded content.
  window.EmailSendXFormsInit = init;
})();
