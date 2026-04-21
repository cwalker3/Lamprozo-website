( function ( wp ) {
    var el                = wp.element.createElement;
    var Fragment          = wp.element.Fragment;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var RichText          = wp.blockEditor.RichText;
    var PanelBody         = wp.components.PanelBody;
    var TextControl       = wp.components.TextControl;
    var ColorPicker       = wp.components.ColorPicker;

    wp.blocks.registerBlockType( 'firefly/template-card', {
        edit: function ( props ) {
            var a = props.attributes;
            var set = props.setAttributes;
            var bp = useBlockProps( { className: 'tmpl-card' } );
            var mockStyle = 'background: linear-gradient(180deg, ' + ( a.accent || '#f5b544' ) + ' 0%, transparent 30%), linear-gradient(180deg, #202225 0%, #141416 100%);';

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Template Card', initialOpen: true },
                        el( TextControl, {
                            label: 'Link URL',
                            value: a.url || '#',
                            onChange: function ( v ) { set( { url: v } ); }
                        } ),
                        el( ColorPicker, {
                            color: a.accent || '#f5b544',
                            onChangeComplete: function ( v ) { set( { accent: v.hex } ); },
                            disableAlpha: true
                        } )
                    )
                ),
                // use div in editor because Gutenberg can't easily make an <a> block editable
                el( 'div', bp,
                    el( 'div', { className: 'tmpl-thumb' },
                        el( 'div', { className: 'mock', style: { cssText: mockStyle } } )
                    ),
                    el( 'div', { className: 'tmpl-body' },
                        el( RichText, {
                            tagName:     'span',
                            className:   'title',
                            value:       a.name || '',
                            onChange:    function ( v ) { set( { name: v } ); },
                            placeholder: 'template-name',
                            allowedFormats: []
                        } ),
                        el( 'span', { className: 'meta' },
                            el( RichText, {
                                tagName:     'span',
                                value:       a.tag || '',
                                onChange:    function ( v ) { set( { tag: v } ); },
                                placeholder: 'category',
                                allowedFormats: []
                            } ),
                            el( 'span', { className: 'ok' },
                                'shipped in ',
                                el( RichText, {
                                    tagName:     'span',
                                    value:       a.days || '',
                                    onChange:    function ( v ) { set( { days: v } ); },
                                    placeholder: 'N',
                                    allowedFormats: []
                                } ),
                                ' days'
                            )
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
