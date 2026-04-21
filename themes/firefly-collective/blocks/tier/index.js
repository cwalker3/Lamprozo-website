( function ( wp ) {
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var RichText = wp.blockEditor.RichText;

    wp.blocks.registerBlockType( 'firefly/tier', {
        edit: function ( props ) {
            var a = props.attributes;
            var set = props.setAttributes;
            var bp = useBlockProps( { className: 'tier' } );

            return el( 'div', bp,
                el( RichText, {
                    tagName:  'strong',
                    value:    a.name || '',
                    onChange: function ( v ) { set( { name: v } ); },
                    placeholder: 'Tier name',
                    allowedFormats: []
                } ),
                el( RichText, {
                    tagName:   'span',
                    className: 'price',
                    value:     a.price || '',
                    onChange:  function ( v ) { set( { price: v } ); },
                    placeholder: 'Price',
                    allowedFormats: []
                } )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
