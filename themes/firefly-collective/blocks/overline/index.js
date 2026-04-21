( function ( wp ) {
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var RichText = wp.blockEditor.RichText;

    wp.blocks.registerBlockType( 'firefly/overline', {
        edit: function ( props ) {
            var bp = useBlockProps( { className: 'overline' } );
            return el( RichText, Object.assign( {}, bp, {
                tagName:     'span',
                value:       props.attributes.text || '',
                onChange:    function ( v ) { props.setAttributes( { text: v } ); },
                placeholder: 'Overline label',
                allowedFormats: []
            } ) );
        },
        save: function () { return null; }
    } );
} )( window.wp );
