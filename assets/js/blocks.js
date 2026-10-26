/* Sepo Dictionary — the Dictionary, Dictionary word and Word of the day blocks. No build step. */
(function (wp, cfg) {
  if (!wp || !wp.blocks) { return; }
  var el = wp.element.createElement;
  var __ = wp.i18n.__;
  var be = wp.blockEditor || wp.editor;
  var C = wp.components;
  var SSR = wp.serverSideRender;
  var languages = (cfg && cfg.languages) || [];

  function preview(name, attrs, props) {
    return el('div', props, SSR ? el(SSR, { block: name, attributes: attrs }) : null);
  }
  function set(props, key) { return function (v) { var o = {}; o[key] = v; props.setAttributes(o); }; }

  wp.blocks.registerBlockType('sepo-dictionary/dictionary', {
    apiVersion: 2,
    title: __('Dictionary', 'sepo-dictionary'),
    description: __('The full dictionary: search, A to Z, community review and contributors.', 'sepo-dictionary'),
    icon: 'book-alt',
    category: 'widgets',
    keywords: [__('glossary', 'sepo-dictionary'), __('words', 'sepo-dictionary')],
    attributes: { perPage: { type: 'number', default: 0 }, wordOfDay: { type: 'boolean', default: true }, letters: { type: 'boolean', default: true }, language: { type: 'string', default: '' }, title: { type: 'string', default: '' } },
    edit: function (props) {
      var a = props.attributes;
      var bp = be.useBlockProps ? be.useBlockProps() : {};
      return el('div', bp,
        el(be.InspectorControls, null,
          el(C.PanelBody, { title: __('Dictionary', 'sepo-dictionary'), initialOpen: true },
            el(C.SelectControl, { label: __('Language', 'sepo-dictionary'), help: __('Show only one language\'s words.', 'sepo-dictionary'), value: a.language, options: languages, onChange: set(props, 'language') }),
            el(C.RangeControl, { label: __('Words per page (0 uses the setting)', 'sepo-dictionary'), value: a.perPage, min: 0, max: 120, onChange: function (v) { props.setAttributes({ perPage: v || 0 }); } }),
            el(C.ToggleControl, { label: __('Show the word of the day', 'sepo-dictionary'), checked: !!a.wordOfDay, onChange: set(props, 'wordOfDay') }),
            el(C.ToggleControl, { label: __('Show the A to Z', 'sepo-dictionary'), checked: !!a.letters, onChange: set(props, 'letters') }),
            el(C.TextControl, { label: __('Heading', 'sepo-dictionary'), value: a.title, onChange: set(props, 'title') })
          )
        ),
        preview('sepo-dictionary/dictionary', a, { style: { pointerEvents: 'none' } })
      );
    },
    save: function () { return null; }
  });

  wp.blocks.registerBlockType('sepo-dictionary/word', {
    apiVersion: 2,
    title: __('Dictionary word', 'sepo-dictionary'),
    description: __('One entry from the dictionary.', 'sepo-dictionary'),
    icon: 'editor-spellcheck',
    category: 'widgets',
    attributes: { term: { type: 'string', default: '' } },
    edit: function (props) {
      var a = props.attributes;
      var bp = be.useBlockProps ? be.useBlockProps() : {};
      var controls = el(be.InspectorControls, null, el(C.PanelBody, { title: __('Word', 'sepo-dictionary'), initialOpen: true },
        el(C.TextControl, { label: __('Word', 'sepo-dictionary'), value: a.term, onChange: set(props, 'term') })));
      if (!a.term) {
        return el('div', bp, controls, el(C.Placeholder, { icon: 'editor-spellcheck', label: __('Dictionary word', 'sepo-dictionary'), instructions: __('Type the word to show.', 'sepo-dictionary') },
          el(C.TextControl, { value: a.term, onChange: set(props, 'term') })));
      }
      return el('div', bp, controls, preview('sepo-dictionary/word', a, { style: { pointerEvents: 'none' } }));
    },
    save: function () { return null; }
  });

  wp.blocks.registerBlockType('sepo-dictionary/word-of-day', {
    apiVersion: 2,
    title: __('Word of the day', 'sepo-dictionary'),
    description: __('A word from the dictionary that changes every day.', 'sepo-dictionary'),
    icon: 'awards',
    category: 'widgets',
    attributes: { language: { type: 'string', default: '' }, title: { type: 'string', default: '' } },
    edit: function (props) {
      var a = props.attributes;
      var bp = be.useBlockProps ? be.useBlockProps() : {};
      return el('div', bp,
        el(be.InspectorControls, null, el(C.PanelBody, { title: __('Word of the day', 'sepo-dictionary'), initialOpen: true },
          el(C.SelectControl, { label: __('Language', 'sepo-dictionary'), value: a.language, options: languages, onChange: set(props, 'language') }),
          el(C.TextControl, { label: __('Heading', 'sepo-dictionary'), value: a.title, placeholder: __('Word of the day', 'sepo-dictionary'), onChange: set(props, 'title') }))),
        preview('sepo-dictionary/word-of-day', a, { style: { pointerEvents: 'none' } }));
    },
    save: function () { return null; }
  });
})(window.wp, window.SepoDictionaryBlocks);
