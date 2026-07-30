( function () {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getPaymentMethodData } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { RawHTML, createElement } = window.wp.element;
    const { sanitizeHTML } = window.wc.sanitize;

    const registerOenPaymentMethod = ( name ) => {
        const settings = getPaymentMethodData( name, null );

        if ( ! settings ) {
            return;
        }

        const title = decodeEntities( settings.title || '' );
        const Description = () =>
            createElement( RawHTML, {
                children: sanitizeHTML( settings.description || '' ),
            } );
        const Label = ( props ) => {
            const { PaymentMethodLabel } = props.components;
            return createElement( PaymentMethodLabel, { text: title } );
        };

        registerPaymentMethod( {
            name,
            label: createElement( Label ),
            content: createElement( Description ),
            edit: createElement( Description ),
            canMakePayment: () => true,
            ariaLabel: title,
            supports: {
                features: settings.supports || [ 'products' ],
            },
        } );
    };

    registerOenPaymentMethod( 'oen_credit' );
    registerOenPaymentMethod( 'oen_cvs' );
} )();
