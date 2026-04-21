( function ( wp ) {
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;

    wp.blocks.registerBlockType( 'firefly/cli-terminal', {
        edit: function () {
            var bp = useBlockProps();
            var html = ( window.fireflyBlockHtml && window.fireflyBlockHtml.cliTerminal ) || '';
            return el( 'div', Object.assign( {}, bp, {
                dangerouslySetInnerHTML: { __html: html }
            } ) );
        },
        save: function () { return null; }
    } );
} )( window.wp );
