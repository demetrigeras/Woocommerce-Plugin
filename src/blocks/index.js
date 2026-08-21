/**
 * Stablecoin Pay — WooCommerce Blocks payment-method registration.
 *
 * Entry point compiled by @wordpress/scripts into build/index.js. Loaded on
 * the block-based checkout by `SP_Blocks_Payment_Method` (PHP).
 *
 * Build:    npm run build
 * Watch:    npm start
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import Label from './label';
import Edit from './edit';
import Content from './content';

const PAYMENT_METHOD_NAME = 'sp';

// Settings injected by SP_Blocks_Payment_Method::get_payment_method_data().
// `getSetting` reads `sp_data` because Woo prefixes/maps it from the
// PHP-side `name` of the payment method.
const settings = getSetting(`${ PAYMENT_METHOD_NAME }_data`, {});

registerPaymentMethod({
	name: PAYMENT_METHOD_NAME,
	label: <Label settings={ settings } />,
	content: <Content settings={ settings } />,
	edit: <Edit settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || 'Pay with Crypto',
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
