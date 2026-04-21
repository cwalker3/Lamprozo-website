( function ( wp ) {
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var RichText = wp.blockEditor.RichText;

    wp.blocks.registerBlockType( 'firefly/hero-meta', {
        edit: function ( props ) {
            var a = props.attributes, set = props.setAttributes;
            var bp = useBlockProps( { className: 'hero-meta' } );
            return el( 'div', bp,
                el( 'span', null,
                    el( 'span', { className: 'dot', 'aria-hidden': 'true' } ),
                    ' ',
                    el( RichText, {
                        tagName:     'span',
                        value:       a.status || '',
                        onChange:    function ( v ) { set( { status: v } ); },
                        placeholder: 'live status',
                        allowedFormats: []
                    } )
                ),
                el( RichText, {
                    tagName:     'span',
                    className:   'mono',
                    value:       a.tech || '',
                    onChange:    function ( v ) { set( { tech: v } ); },
                    placeholder: 'tech stack',
                    allowedFormats: []
                } )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
