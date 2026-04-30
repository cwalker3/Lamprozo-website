/* firefly/contact-form — editor UI.
   Renders the same markup as render.php so the editor canvas previews
   accurately. The form fields are baked into the block; only the
   placeholder copy + submit label are editable as block attributes. */
( function ( wp ) {
    var el            = wp.element.createElement;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var TextControl   = wp.components && wp.components.TextControl;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody     = wp.components && wp.components.PanelBody;

    wp.blocks.registerBlockType( 'firefly/contact-form', {
        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var bp  = useBlockProps( { className: 'contact-form' } );

            var inspector = el( InspectorControls, null,
                el( PanelBody, { title: 'Form labels' },
                    el( TextControl, {
                        label: 'Name placeholder',
                        value: a.namePlaceholder,
                        onChange: function ( v ) { set( { namePlaceholder: v } ); }
                    } ),
                    el( TextControl, {
                        label: 'Email placeholder',
                        value: a.emailPlaceholder,
                        onChange: function ( v ) { set( { emailPlaceholder: v } ); }
                    } ),
                    el( TextControl, {
                        label: 'Message placeholder',
                        value: a.messagePlaceholder,
                        onChange: function ( v ) { set( { messagePlaceholder: v } ); }
                    } ),
                    el( TextControl, {
                        label: 'Submit button label',
                        value: a.submitLabel,
                        onChange: function ( v ) { set( { submitLabel: v } ); }
                    } )
                )
            );

            return el( wp.element.Fragment, null,
                inspector,
                el( 'div', bp,
                    el( 'input', { type: 'text',  id: 'contact-form-name',    placeholder: a.namePlaceholder,    readOnly: true } ),
                    el( 'input', { type: 'email', id: 'contact-form-email',   placeholder: a.emailPlaceholder,   readOnly: true } ),
                    el( 'textarea', {              id: 'contact-form-message', placeholder: a.messagePlaceholder, readOnly: true } ),
                    el( 'div', { id: 'send-message-btn' },
                        el( 'button', { type: 'button', disabled: true }, a.submitLabel )
                    )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
