/* firefly/signup-form — editor UI.
   Renders the same markup as render.php (read-only inputs in the
   editor) so authors see what visitors see. The form structure is
   fixed; only labels + placeholders are editable as block attributes. */
( function ( wp ) {
    var el                = wp.element.createElement;
    var Fragment          = wp.element.Fragment;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var TextControl       = wp.components.TextControl;
    var PanelBody         = wp.components.PanelBody;

    wp.blocks.registerBlockType( 'firefly/signup-form', {
        edit: function ( props ) {
            var a   = props.attributes;
            var set = props.setAttributes;
            var bp  = useBlockProps( { className: 'signup-form' } );

            function field( key, label ) {
                return el( TextControl, {
                    label: label,
                    value: a[ key ],
                    onChange: function ( v ) {
                        var patch = {}; patch[ key ] = v;
                        set( patch );
                    }
                } );
            }

            var inspector = el( InspectorControls, null,
                el( PanelBody, { title: 'Method toggle' },
                    field( 'directLabel',     'Direct option label' ),
                    field( 'thirdPartyLabel', 'Third-party option label' )
                ),
                el( PanelBody, { title: 'Direct signup fields', initialOpen: false },
                    field( 'fnamePlaceholder', 'First name placeholder' ),
                    field( 'lnamePlaceholder', 'Last name placeholder' ),
                    field( 'emailPlaceholder', 'Email placeholder' ),
                    field( 'phonePlaceholder', 'Phone placeholder' ),
                    field( 'passwordToggleLabel', 'Username/password toggle label' ),
                    field( 'usernamePlaceholder', 'Username placeholder' ),
                    field( 'passwordPlaceholder', 'Password placeholder' ),
                    field( 'joinLabel', 'Submit button label' )
                ),
                el( PanelBody, { title: 'Third-party signup', initialOpen: false },
                    field( 'googleLabel', 'Google button label' )
                )
            );

            var ro = { readOnly: true };

            return el( Fragment, null,
                inspector,
                el( 'div', bp,
                    el( 'div', { id: 'error-txt' } ),
                    el( 'div', { className: 'signup-method' },
                        el( 'label', null,
                            el( 'input', { type: 'radio', name: 'signup-method', value: 'direct', defaultChecked: true, disabled: true } ),
                            ' ' + a.directLabel
                        ),
                        el( 'label', null,
                            el( 'input', { type: 'radio', name: 'signup-method', value: 'third', disabled: true } ),
                            ' ' + a.thirdPartyLabel
                        )
                    ),
                    el( 'div', { id: 'direct-signup-fields' },
                        el( 'input', Object.assign( { type: 'text',  id: 'signup-form-fname', placeholder: a.fnamePlaceholder }, ro ) ),
                        el( 'input', Object.assign( { type: 'text',  id: 'signup-form-lname', placeholder: a.lnamePlaceholder }, ro ) ),
                        el( 'input', Object.assign( { type: 'email', id: 'signup-form-email', placeholder: a.emailPlaceholder }, ro ) ),
                        el( 'input', Object.assign( { type: 'tel',   id: 'signup-form-phone', placeholder: a.phonePlaceholder }, ro ) ),
                        el( 'label', null,
                            el( 'input', { type: 'checkbox', id: 'enable-username-password', disabled: true } ),
                            ' ' + a.passwordToggleLabel
                        ),
                        el( 'div', { id: 'signup-btn' },
                            el( 'button', { type: 'button', id: 'join-now-btn', disabled: true }, a.joinLabel )
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    } );
} )( window.wp );
