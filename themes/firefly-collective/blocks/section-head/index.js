/* firefly/section-head — editor UI
   Renders identical markup to render.php so the editor and frontend look the same.
*/
( function ( wp ) {
    var el                 = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var registerBlockType  = wp.blocks.registerBlockType;
    var useBlockProps      = wp.blockEditor.useBlockProps;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var RichText           = wp.blockEditor.RichText;
    var PanelBody          = wp.components.PanelBody;
    var ToggleControl      = wp.components.ToggleControl;

    registerBlockType( 'firefly/section-head', {
        edit: function ( props ) {
            var a = props.attributes;
            var set = props.setAttributes;

            var classes = 'section-head reveal';
            if ( a.centered ) classes += ' center';

            var blockProps = useBlockProps( { className: classes } );

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: 'Section Head', initialOpen: true },
                        el( ToggleControl, {
                            label: 'Centered',
                            checked: !! a.centered,
                            onChange: function ( v ) { set( { centered: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( RichText, {
                        tagName:     'span',
                        className:   'overline',
                        value:       a.overline || '',
                        onChange:    function ( v ) { set( { overline: v } ); },
                        placeholder: 'Overline label',
                        allowedFormats: []
                    } ),
                    el( RichText, {
                        tagName:     'h2',
                        className:   'wp-block-heading',
                        value:       a.heading || '',
                        onChange:    function ( v ) { set( { heading: v } ); },
                        placeholder: 'Section heading'
                    } ),
                    el( RichText, {
                        tagName:     'p',
                        className:   'lead',
                        value:       a.lead || '',
                        onChange:    function ( v ) { set( { lead: v } ); },
                        placeholder: 'Optional lead paragraph…'
                    } )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
