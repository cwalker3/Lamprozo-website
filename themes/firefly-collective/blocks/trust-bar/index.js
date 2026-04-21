( function ( wp ) {
    var el                = wp.element.createElement;
    var Fragment          = wp.element.Fragment;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var RichText          = wp.blockEditor.RichText;
    var PanelBody         = wp.components.PanelBody;
    var TextareaControl   = wp.components.TextareaControl;

    function parseSites ( str ) {
        return ( str || '' ).split( ';' )
            .map( function ( chunk ) { return chunk.trim(); } )
            .filter( function ( c ) { return c.length; } )
            .map( function ( c ) {
                var parts = c.split( '|' );
                return { name: ( parts[0] || '' ).trim(), count: ( parts[1] || '' ).trim() };
            } );
    }

    wp.blocks.registerBlockType( 'firefly/trust-bar', {
        edit: function ( props ) {
            var a = props.attributes, set = props.setAttributes;
            var bp = useBlockProps( { className: 'container' } );
            var sites = parseSites( a.sites );

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Trust Bar', initialOpen: true },
                        el( TextareaControl, {
                            label:    'Sites',
                            help:     'One per line: "site-name | page count". Separate with ; if inline.',
                            value:    ( a.sites || '' ).split( ';' ).map( function ( s ) { return s.trim(); } ).join( '\n' ),
                            onChange: function ( v ) { set( { sites: v.split( '\n' ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return s.length; } ).join( '; ' ) } ); },
                            rows: 6
                        } )
                    )
                ),
                el( 'div', bp,
                    el( 'div', { className: 'trust-grid' },
                        el( RichText, {
                            tagName:     'div',
                            className:   'trust-label',
                            value:       a.label || '',
                            onChange:    function ( v ) { set( { label: v } ); },
                            placeholder: 'Built on Firefly',
                            allowedFormats: []
                        } ),
                        el( 'div', { className: 'trust-items' },
                            sites.map( function ( s, i ) {
                                return el( 'span', { className: 'site', key: i },
                                    s.name,
                                    s.count ? el( 'span', { className: 'count' }, s.count ) : null
                                );
                            } )
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
