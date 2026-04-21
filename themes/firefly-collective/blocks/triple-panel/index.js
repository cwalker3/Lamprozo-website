( function ( wp ) {
    var el = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;

    wp.blocks.registerBlockType( 'firefly/triple-panel', {
        edit: function () {
            var bp = useBlockProps();
            // window.fireflyBlockHtml.triplePanel is injected by blocks/register.php
            // on enqueue_block_editor_assets so editor preview matches render.php.
            var html = ( window.fireflyBlockHtml && window.fireflyBlockHtml.triplePanel ) || '';
            return el( 'div', Object.assign( {}, bp, {
                dangerouslySetInnerHTML: { __html: html }
            } ) );
        },
        save: function () { return null; }
    } );
} )( window.wp );
