/* firefly/contributor-card — editor UI */
( function ( wp ) {
    var el                = wp.element.createElement;
    var Fragment          = wp.element.Fragment;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var RichText          = wp.blockEditor.RichText;
    var PanelBody         = wp.components.PanelBody;
    var TextControl       = wp.components.TextControl;

    var ARROW_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 17L17 7M17 7H9M17 7v8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

    function renderChips ( chipString ) {
        var chips = ( chipString || '' ).split( '·' );
        return el( 'div', { className: 'tools' },
            chips.map( function ( chip, i ) {
                var txt = chip.trim();
                if ( ! txt ) return null;
                return el( 'span', { className: 'chip', key: i }, txt );
            } )
        );
    }

    registerBlockType( 'firefly/contributor-card', {
        edit: function ( props ) {
            var a = props.attributes;
            var set = props.setAttributes;

            var blockProps = useBlockProps( { className: 'contrib-card' } );

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Contributor Card', initialOpen: true },
                        el( TextControl, {
                            label: 'Number',
                            value: a.number || '',
                            onChange: function ( v ) { set( { number: v } ); },
                            help: 'e.g. 01, 02, 03'
                        } ),
                        el( TextControl, {
                            label: 'Audience label',
                            value: a.audience || '',
                            onChange: function ( v ) { set( { audience: v } ); },
                            help: 'e.g. No-coders, Developers, AI agents'
                        } ),
                        el( TextControl, {
                            label: 'Tool chips',
                            value: a.chips || '',
                            onChange: function ( v ) { set( { chips: v } ); },
                            help: 'Separate with · (middle dot)'
                        } )
                    )
                ),
                el( 'article', blockProps,
                    el( 'span', {
                        className: 'arrow-out',
                        'aria-hidden': 'true',
                        dangerouslySetInnerHTML: { __html: ARROW_SVG }
                    } ),
                    el( 'span', { className: 'n' },
                        ( a.number || '' ) + ' · ' + ( a.audience || '' )
                    ),
                    el( RichText, {
                        tagName:     'h3',
                        className:   'wp-block-heading',
                        value:       a.title || '',
                        onChange:    function ( v ) { set( { title: v } ); },
                        placeholder: 'Card title'
                    } ),
                    el( RichText, {
                        tagName:     'p',
                        value:       a.description || '',
                        onChange:    function ( v ) { set( { description: v } ); },
                        placeholder: 'One paragraph describing this contributor…'
                    } ),
                    renderChips( a.chips )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
