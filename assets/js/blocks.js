/*!
 * EmailSendX for WordPress — Block Editor (Gutenberg) blocks.
 *
 * Two DYNAMIC blocks. `save()` returns null; the markup comes from the PHP
 * render_callback, which runs the same shortcodes WPBakery and Elementor use.
 * That's why the editor preview is a <ServerSideRender> — it renders through
 * the real PHP path, so what you see in the editor is exactly what ships, and
 * there's no second copy of the render logic to drift out of sync.
 *
 * Written against the global wp.* APIs (no JSX) so the plugin ships a block
 * without dragging in an npm/webpack build step.
 *
 * ShaonPro.
 */
(function (blocks, element, blockEditor, components, i18n, ServerSideRender) {
  'use strict';

  if (!blocks || !element || !blockEditor) return;

  var el = element.createElement;
  var Fragment = element.Fragment;
  var InspectorControls = blockEditor.InspectorControls;
  var ColorPalette = blockEditor.ColorPalette;
  var PanelBody = components.PanelBody;
  var BaseControl = components.BaseControl;
  var SelectControl = components.SelectControl;
  var TextControl = components.TextControl;
  var TextareaControl = components.TextareaControl;
  var ToggleControl = components.ToggleControl;
  var __ = i18n.__;

  var CFG = window.EmailSendXBlocks || {};
  var CHOICES = CFG.choices || {};

  // { value: label } → [{ value, label }] for SelectControl.
  function toOptions(map) {
    var out = [];
    Object.keys(map || {}).forEach(function (key) {
      out.push({ value: key, label: map[key] });
    });
    return out;
  }

  function set(props, key, value) {
    var patch = {};
    patch[key] = value;
    props.setAttributes(patch);
  }

  function selectCtl(props, key, label, help) {
    return el(SelectControl, {
      key: key,
      label: label,
      help: help,
      value: props.attributes[key] || '',
      options: toOptions(CHOICES[key]),
      onChange: function (v) { set(props, key, v); }
    });
  }

  function colorCtl(props, key, label, help) {
    return el(
      BaseControl,
      { key: key, label: label, help: help },
      el(ColorPalette, {
        value: props.attributes[key] || '',
        onChange: function (v) { set(props, key, v || ''); }
      })
    );
  }

  function textCtl(props, key, label, help) {
    return el(TextControl, {
      key: key,
      label: label,
      help: help,
      value: props.attributes[key] || '',
      onChange: function (v) { set(props, key, v); }
    });
  }

  function areaCtl(props, key, label, help) {
    return el(TextareaControl, {
      key: key,
      label: label,
      help: help,
      rows: 3,
      value: props.attributes[key] || '',
      onChange: function (v) { set(props, key, v); }
    });
  }

  // Switchers store 'yes' / '' to match the shortcode attribute contract.
  function toggleCtl(props, key, label) {
    return el(ToggleControl, {
      key: key,
      label: label,
      checked: props.attributes[key] === 'yes',
      onChange: function (on) { set(props, key, on ? 'yes' : ''); }
    });
  }

  // The same 16 style controls the other builders expose.
  function stylePanels(props) {
    return [
      el(PanelBody, { key: 'esx-layout', title: __('Layout', 'emailsendx-sync'), initialOpen: false }, [
        selectCtl(props, 'align', __('Alignment', 'emailsendx-sync')),
        selectCtl(props, 'width', __('Width', 'emailsendx-sync'), __('Use Full width to fill the column it sits in.', 'emailsendx-sync')),
        selectCtl(props, 'size', __('Size', 'emailsendx-sync')),
        selectCtl(props, 'spacing', __('Field spacing', 'emailsendx-sync'))
      ]),
      el(PanelBody, { key: 'esx-fields', title: __('Fields', 'emailsendx-sync'), initialOpen: false }, [
        selectCtl(props, 'field_style', __('Field style', 'emailsendx-sync')),
        selectCtl(props, 'radius', __('Corner radius', 'emailsendx-sync')),
        selectCtl(props, 'labels', __('Labels', 'emailsendx-sync')),
        colorCtl(props, 'field_bg', __('Field background', 'emailsendx-sync'), __('Set this for forms on a dark or tinted section.', 'emailsendx-sync')),
        colorCtl(props, 'field_color', __('Field text colour', 'emailsendx-sync')),
        colorCtl(props, 'border_color', __('Field border colour', 'emailsendx-sync'))
      ]),
      el(PanelBody, { key: 'esx-button', title: __('Button & text', 'emailsendx-sync'), initialOpen: false }, [
        colorCtl(props, 'accent', __('Accent colour', 'emailsendx-sync'), __('Button background + input focus ring.', 'emailsendx-sync')),
        colorCtl(props, 'button_color', __('Button text colour', 'emailsendx-sync')),
        selectCtl(props, 'button_style', __('Button style', 'emailsendx-sync')),
        selectCtl(props, 'button_align', __('Button alignment', 'emailsendx-sync')),
        toggleCtl(props, 'button_full', __('Full-width button', 'emailsendx-sync')),
        colorCtl(props, 'text_color', __('Text colour', 'emailsendx-sync'))
      ])
    ];
  }

  var brandIcon = CFG.icon
    ? el('img', { src: CFG.icon, width: 24, height: 24, alt: '', style: { borderRadius: '4px' } })
    : 'email-alt';

  function preview(name, props) {
    if (!ServerSideRender) return null;
    return el(ServerSideRender, { block: name, attributes: props.attributes });
  }

  /*
   * Inserter hover-preview.
   *
   * A dynamic block with no `example` shows "No preview available", but pointing
   * `example` at ServerSideRender doesn't help either — the preview has no form
   * or list selected, so the server would just return the "choose a form" hint.
   *
   * So `example` sets a `_preview` flag and we draw a static mock instead. It's
   * instant (no REST round-trip) and it's genuinely representative, because the
   * mock reuses the very same .esx-form* classes the real form renders with, and
   * forms.css is loaded in the editor — so the preview is styled by the real
   * stylesheet, not a hand-drawn imitation of it.
   */
  function mockField(label, placeholder) {
    return el('div', { className: 'esx-form__field' }, [
      el('label', { key: 'l', className: 'esx-form__label' }, label),
      el('input', {
        key: 'i',
        className: 'esx-form__input',
        type: 'text',
        placeholder: placeholder || '',
        readOnly: true,
        tabIndex: -1
      })
    ]);
  }

  function mock(kind) {
    var rows = kind === 'newsletter'
      ? [mockField(__('Email', 'emailsendx-sync'), 'you@example.com')]
      : [
          mockField(__('Name', 'emailsendx-sync'), ''),
          mockField(__('Email', 'emailsendx-sync'), 'you@example.com')
        ];

    return el(
      'div',
      { className: 'esx-form esx-form--' + (kind === 'newsletter' ? 'newsletter' : 'hosted') },
      el('div', { className: 'esx-form__form' }, rows.concat([
        el('button', { key: 'b', type: 'button', className: 'esx-form__submit' },
          kind === 'newsletter' ? __('Subscribe', 'emailsendx-sync') : __('Send', 'emailsendx-sync'))
      ]))
    );
  }

  /* ── EmailSendX Form ─────────────────────────────────────────────── */
  blocks.registerBlockType('emailsendx/form', {
    apiVersion: 2,
    title: __('EmailSendX Form', 'emailsendx-sync'),
    description: __('Embed an opt-in form.', 'emailsendx-sync'),
    icon: brandIcon,
    category: CFG.category || 'widgets',
    keywords: ['emailsendx', 'form', 'signup', 'subscribe', 'optin'],
    attributes: (CFG.attributes && CFG.attributes.form) || {},
    example: { attributes: { _preview: true } },
    edit: function (props) {
      if (props.attributes._preview) return mock('form');

      return el(
        Fragment,
        {},
        el(InspectorControls, {}, [
          el(PanelBody, { key: 'esx-form', title: __('Form', 'emailsendx-sync'), initialOpen: true },
            el(SelectControl, {
              label: __('Form', 'emailsendx-sync'),
              help: (CFG.help && CFG.help.forms) || '',
              value: props.attributes.id || '',
              options: toOptions(CFG.forms),
              onChange: function (v) { set(props, 'id', v); }
            })
          )
        ].concat(stylePanels(props))),
        preview('emailsendx/form', props)
      );
    },
    // Dynamic block — the front end comes from the PHP render_callback.
    save: function () { return null; }
  });

  /* ── EmailSendX Newsletter ───────────────────────────────────────── */
  blocks.registerBlockType('emailsendx/newsletter', {
    apiVersion: 2,
    title: __('EmailSendX Newsletter', 'emailsendx-sync'),
    description: __('Quick subscribe box.', 'emailsendx-sync'),
    icon: brandIcon,
    category: CFG.category || 'widgets',
    keywords: ['emailsendx', 'newsletter', 'subscribe', 'signup', 'email'],
    attributes: (CFG.attributes && CFG.attributes.newsletter) || {},
    example: { attributes: { _preview: true } },
    edit: function (props) {
      if (props.attributes._preview) return mock('newsletter');

      return el(
        Fragment,
        {},
        el(InspectorControls, {}, [
          el(PanelBody, { key: 'esx-nl', title: __('Newsletter', 'emailsendx-sync'), initialOpen: true }, [
            el(SelectControl, {
              key: 'list',
              label: __('Contact list', 'emailsendx-sync'),
              help: (CFG.help && CFG.help.lists) || '',
              value: props.attributes.list || '',
              options: toOptions(CFG.lists),
              onChange: function (v) { set(props, 'list', v); }
            }),
            textCtl(props, 'title', __('Heading', 'emailsendx-sync')),
            areaCtl(props, 'description', __('Description', 'emailsendx-sync')),
            toggleCtl(props, 'name', __('Also collect first name', 'emailsendx-sync')),
            textCtl(props, 'button', __('Button text', 'emailsendx-sync')),
            textCtl(props, 'placeholder', __('Email placeholder', 'emailsendx-sync')),
            textCtl(props, 'success', __('Success message', 'emailsendx-sync')),
            areaCtl(props, 'consent', __('Consent / fine print', 'emailsendx-sync'))
          ])
        ].concat(stylePanels(props))),
        preview('emailsendx/newsletter', props)
      );
    },
    save: function () { return null; }
  });
})(
  window.wp.blocks,
  window.wp.element,
  window.wp.blockEditor,
  window.wp.components,
  window.wp.i18n,
  window.wp.serverSideRender
);
