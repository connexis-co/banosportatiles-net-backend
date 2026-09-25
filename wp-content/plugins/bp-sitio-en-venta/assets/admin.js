/**
 * «Sitio en venta» — live preview, counters, WCAG contrast, E.164 hint and mode defaults.
 * Vanilla JS; only the WordPress color picker (a jQuery plugin) is initialised through jQuery.
 */
(function () {
  'use strict';

  const cfg = window.bpSitioEnVenta || {};
  const form = document.getElementById('bpsev-form');
  if (!form) {
    return;
  }

  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const input = (name) => form.querySelector(`[name="bpsev[${name}]"]`) || form.querySelector(`[name="bpsev[${name}]"][type="checkbox"]`);
  const checkbox = (name) => form.querySelector(`input[type="checkbox"][name="bpsev[${name}]"]`);
  const modo = () => (input('modo') ? input('modo').value : 'venta');
  const defaults = (key, forModo = modo()) => ((cfg.defaults || {})[forModo] || {})[key] || '';

  // --- Counters ------------------------------------------------------------------------------------
  $$('.bpsev-counter').forEach((counter) => {
    const field = document.getElementById(counter.id.replace(/-count$/, ''));
    if (!field) {
      return;
    }
    const update = () => {
      const max = field.maxLength > 0 ? field.maxLength : 0;
      counter.textContent = `${field.value.length}/${max}`;
      counter.classList.toggle('is-near', max > 0 && field.value.length > max * 0.9);
    };
    field.addEventListener('input', update);
    update();
  });

  // --- Text → preview ------------------------------------------------------------------------------------
  const texts = ['headline', 'message', 'cta_whatsapp_label', 'secondary_label'];
  const renderTexts = () => {
    texts.forEach((key) => {
      const field = input(key);
      const value = field && field.value.trim() !== '' ? field.value.trim() : defaults(key);
      $$(`[data-bpv-text="${key}"]`).forEach((node) => {
        node.textContent = value;
      });
    });
    $$('[data-bpv-text="badge"]').forEach((node) => {
      node.textContent = (cfg.badges || {})[modo()] || '';
    });
  };
  texts.forEach((key) => input(key) && input(key).addEventListener('input', renderTexts));

  // --- Mode: swap suggested texts that were not customised -------------------------------------------------
  const modeField = input('modo');
  if (modeField) {
    let previous = modeField.value;
    modeField.addEventListener('change', () => {
      [...texts, 'whatsapp_message'].forEach((key) => {
        const field = input(key);
        if (field && (field.value.trim() === '' || field.value.trim() === defaults(key, previous))) {
          field.value = defaults(key, modeField.value);
          field.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
      previous = modeField.value;
      renderTexts();
      renderWhatsapp();
    });
  }

  // --- Toggles → show/hide -------------------------------------------------------------------------------
  const enabledBox = checkbox('enabled');
  const initiallyEnabled = enabledBox ? enabledBox.checked : false;
  const renderToggles = () => {
    ['show_whatsapp', 'show_secondary', 'dismissible'].forEach((key) => {
      const box = checkbox(key);
      $$(`[data-bpv-show="${key}"]`).forEach((node) => {
        node.hidden = !(box && box.checked);
      });
    });
    const dismissible = checkbox('dismissible');
    const days = form.querySelector('[data-bpsev-days]');
    if (days) {
      days.hidden = !(dismissible && dismissible.checked);
    }
    const status = document.querySelector('[data-bpsev-status]');
    if (status && enabledBox) {
      const pending = enabledBox.checked !== initiallyEnabled ? ' (sin guardar)' : '';
      status.textContent = (enabledBox.checked ? 'Aviso activo' : 'Aviso inactivo') + pending;
      status.classList.toggle('is-on', enabledBox.checked);
      status.classList.toggle('is-off', !enabledBox.checked);
    }
  };
  form.addEventListener('change', (event) => {
    if (event.target.matches('input[type="checkbox"]')) {
      renderToggles();
      if (event.target.name === 'bpsev[show_whatsapp]') {
        renderWhatsapp();
      }
    }
  });

  // --- WhatsApp: E.164 hint and wa.me link ----------------------------------------------------------------
  const normalizePhone = (raw) => {
    const value = raw.trim();
    if (value === '') {
      return '';
    }
    if (/[^\d\s+().-]/.test(value) || (value.match(/\+/g) || []).length > 1) {
      return null;
    }
    const digits = value.replace(/\D+/g, '');
    let candidate = '';
    if (value.startsWith('+')) candidate = `+${digits}`;
    else if (digits.startsWith('00')) candidate = `+${digits.slice(2)}`;
    else if (digits.length === 10 && digits[0] === '3') candidate = `+57${digits}`;
    else if (digits.length === 12 && digits.startsWith('57')) candidate = `+${digits}`;
    return /^\+[1-9]\d{7,14}$/.test(candidate) ? candidate : null;
  };
  const fillPlaceholders = (text) => text
    .replaceAll('{sitio}', (cfg.site || {}).name || '')
    .replaceAll('{url}', (cfg.site || {}).url || '');

  const renderWhatsapp = () => {
    const phoneField = input('whatsapp_number');
    const hint = form.querySelector('[data-bpsev-phone-hint]');
    const phone = phoneField ? normalizePhone(phoneField.value) : '';
    const messageField = input('whatsapp_message');
    const message = fillPlaceholders(messageField && messageField.value.trim() !== '' ? messageField.value.trim() : defaults('whatsapp_message'));
    const href = phone ? `https://wa.me/${phone.slice(1)}?text=${encodeURIComponent(message)}` : '#';

    if (hint) {
      hint.classList.remove('is-ok', 'is-error');
      if (phone === null) {
        hint.textContent = 'Número no válido: usa +57 300 123 4567 (E.164).';
        hint.classList.add('is-error');
      } else if (phone === '') {
        hint.textContent = 'Sin número: el botón de WhatsApp no se mostrará.';
      } else {
        hint.textContent = `Se guardará como ${phone}.`;
        hint.classList.add('is-ok');
      }
    }
    $$('[data-bpv-link="whatsapp"], [data-bpsev-wa-test]').forEach((link) => {
      link.setAttribute('href', href);
      link.setAttribute('aria-disabled', phone ? 'false' : 'true');
    });
  };
  ['whatsapp_number', 'whatsapp_message'].forEach((key) => input(key) && input(key).addEventListener('input', renderWhatsapp));

  const secondaryUrl = input('secondary_url');
  if (secondaryUrl) {
    secondaryUrl.addEventListener('input', () => {
      $$('[data-bpv-link="secondary"]').forEach((link) => link.setAttribute('href', secondaryUrl.value.trim() || '#'));
    });
  }

  // --- Colors: CSS variables + WCAG contrast -------------------------------------------------------------------
  const colorValue = (key) => {
    const field = form.querySelector(`[data-bpsev-color="${key}"]`);
    const value = field ? field.value.trim() : '';
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value) ? value : (cfg.colors || {})[key] || '#000000';
  };
  const luminance = (hex) => {
    let value = hex.replace('#', '');
    if (value.length === 3) value = value.split('').map((c) => c + c).join('');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(value.slice(i, i + 2), 16) / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const ratio = (a, b) => {
    const [la, lb] = [luminance(a), luminance(b)];
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };
  const format = (n) => n.toFixed(2).replace(/\.?0+$/, '').replace('.', ',');

  const renderColors = () => {
    const colors = {
      bg: colorValue('bg'),
      text: colorValue('text'),
      accent: colorValue('accent'),
      accent_text: colorValue('accent_text'),
      whatsapp_bg: colorValue('whatsapp_bg'),
      whatsapp_text: colorValue('whatsapp_text'),
    };
    $$('[data-bpv-theme]').forEach((node) => {
      node.style.setProperty('--bpv-bg', colors.bg);
      node.style.setProperty('--bpv-text', colors.text);
      node.style.setProperty('--bpv-accent', colors.accent);
      node.style.setProperty('--bpv-accent-text', colors.accent_text);
      node.style.setProperty('--bpv-wa-bg', colors.whatsapp_bg);
      node.style.setProperty('--bpv-wa-text', colors.whatsapp_text);
    });
    const list = form.querySelector('[data-bpsev-contrast]');
    if (!list) {
      return;
    }
    const aa = cfg.aa || { text: 4.5, ui: 3 };
    const checks = [
      ['Texto sobre el fondo', colors.text, colors.bg, aa.text],
      ['Texto de la etiqueta sobre el acento', colors.accent_text, colors.accent, aa.text],
      ['Acento sobre el fondo', colors.accent, colors.bg, aa.ui],
      ['Texto del botón de WhatsApp sobre el botón', colors.whatsapp_text, colors.whatsapp_bg, aa.text],
      ['Botón de WhatsApp sobre el fondo', colors.whatsapp_bg, colors.bg, aa.ui],
    ];
    list.replaceChildren(...checks.map(([label, fg, bg, min]) => {
      const value = ratio(fg, bg);
      const item = document.createElement('li');
      const ok = value >= min;
      item.className = ok ? 'is-ok' : 'is-fail';
      item.textContent = `${ok ? '✓' : '⚠'} ${label}: ${format(value)}:1 ${ok ? '(cumple WCAG AA)' : `— no cumple WCAG AA (mínimo ${format(min)}:1)`}`;
      return item;
    }));
  };

  const $ = window.jQuery;
  $$('.bpsev-color').forEach((field) => {
    field.addEventListener('input', renderColors);
    if ($ && $.fn && $.fn.wpColorPicker) {
      $(field).wpColorPicker({
        change: (event, ui) => {
          field.value = ui.color.toString();
          renderColors();
        },
        clear: () => {
          field.value = field.dataset.defaultColor || '';
          renderColors();
        },
      });
    }
  });

  // --- First paint -------------------------------------------------------------------------------------------
  renderTexts();
  renderToggles();
  renderWhatsapp();
  renderColors();
})();
