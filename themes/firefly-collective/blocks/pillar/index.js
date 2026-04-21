/* firefly/pillar — editor UI */
( function ( wp ) {
    var el                 = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var registerBlockType  = wp.blocks.registerBlockType;
    var useBlockProps      = wp.blockEditor.useBlockProps;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var RichText           = wp.blockEditor.RichText;
    var PanelBody          = wp.components.PanelBody;
    var SelectControl      = wp.components.SelectControl;

    // Keep in sync with pillar/render.php (same SVG paths)
    var ICONS = {
        schema:  '<path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        scoping: '<rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.75"/><path d="M9 10h6M9 14h4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        cli:     '<path d="M8 9l-4 3 4 3M16 9l4 3-4 3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>',
        pwa:     '<path d="M3 12a9 9 0 1 0 9-9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="M12 3v9h9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
        shield:  '<path d="M12 2L4 6v6c0 5 4 9 8 10 4-1 8-5 8-10V6l-8-4z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>',
        geo:     '<circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.75"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>'
    };

    function iconSvg ( name ) {
        var inner = ICONS[ name ] || ICONS.schema;
        return el( 'span', {
            className: 'icon',
            dangerouslySetInnerHTML: {
                __html: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">' + inner + '</svg>'
            }
        } );
    }

    registerBlockType( 'firefly/pillar', {
        edit: function ( props ) {
            var a = props.attributes;
            var set = props.setAttributes;

            var blockProps = useBlockProps( {
                className: 'pillar',
                // use <article> in editor to match frontend semantics
            } );

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Pillar', initialOpen: true },
                        el( SelectControl, {
                            label: 'Icon',
                            value: a.iconName || 'schema',
                            options: [
                                { label: 'Schema / list',    value: 'schema' },
                                { label: 'Scoping / layers', value: 'scoping' },
                                { label: 'CLI / code',       value: 'cli' },
                                { label: 'PWA / refresh',    value: 'pwa' },
                                { label: 'Shield / trust',   value: 'shield' },
                                { label: 'GEO / target',     value: 'geo' }
                            ],
                            onChange: function ( v ) { set( { iconName: v } ); }
                        } )
                    )
                ),
                el( 'article', blockProps,
                    iconSvg( a.iconName ),
                    el( RichText, {
                        tagName:     'h3',
                        className:   'wp-block-heading',
                        value:       a.title || '',
                        onChange:    function ( v ) { set( { title: v } ); },
                        placeholder: 'Pillar title'
                    } ),
                    el( RichText, {
                        tagName:     'p',
                        value:       a.description || '',
                        onChange:    function ( v ) { set( { description: v } ); },
                        placeholder: 'One sentence describing this capability…'
                    } ),
                    el( RichText, {
                        tagName:     'div',
                        className:   'meta',
                        value:       a.meta || '',
                        onChange:    function ( v ) { set( { meta: v } ); },
                        placeholder: 'meta label (e.g. data/schemas/*.json)',
                        allowedFormats: []
                    } )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
