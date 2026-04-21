( function ( wp ) {
    var el                = wp.element.createElement;
    var Fragment          = wp.element.Fragment;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody         = wp.components.PanelBody;
    var TextareaControl   = wp.components.TextareaControl;

    wp.blocks.registerBlockType( 'firefly/substrate-logos', {
        edit: function ( props ) {
            var a = props.attributes, set = props.setAttributes;
            var bp = useBlockProps( { className: 'substrate-logos' } );
            var items = ( a.items || '' ).split( '·' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s.length; } );

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Substrate Logos', initialOpen: true },
                        el( TextareaControl, {
                            label:    'Pills',
                            help:     'One per line, or separate inline with · (middle dot).',
                            value:    items.join( '\n' ),
                            onChange: function ( v ) {
                                set( { items: v.split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s.length; } ).join( ' · ' ) } );
                            },
                            rows: 8
                        } )
                    )
                ),
                el( 'div', bp,
                    items.map( function ( t, i ) {
                        return el( 'span', { className: 'tech', key: i }, t );
                    } )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
