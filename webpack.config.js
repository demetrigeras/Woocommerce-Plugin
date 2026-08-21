/**
 * Webpack config — extends the default @wordpress/scripts config to teach it
 * about WooCommerce's runtime externals.
 *
 * Imports like `@woocommerce/blocks-registry` and `@woocommerce/settings`
 * are NOT npm packages — they're provided by WooCommerce at runtime as
 * properties of `window.wc.*`. We need to:
 *
 *   1. Tell webpack to leave those imports as `external` references and
 *      rewrite them to the matching `window.wc.<name>` global.
 *   2. Tell @wordpress/dependency-extraction-webpack-plugin to add the
 *      matching script handle (e.g. `wc-blocks-registry`) to the generated
 *      `build/index.asset.php` dependency list, so WordPress enqueues the
 *      WooCommerce runtime BEFORE our bundle.
 *
 * Without this, `npm run build` errors with:
 *   Module not found: Error: Can't resolve '@woocommerce/blocks-registry'
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

// Map of WooCommerce runtime imports → window.wc.* property paths
// (used by webpack `externals`) and matching WordPress script handles
// (used by DependencyExtractionWebpackPlugin so .asset.php is correct).
const wcExternalsMap = {
	'@woocommerce/blocks-registry': {
		global: [ 'wc', 'wcBlocksRegistry' ],
		handle: 'wc-blocks-registry',
	},
	'@woocommerce/settings': {
		global: [ 'wc', 'wcSettings' ],
		handle: 'wc-settings',
	},
	'@woocommerce/blocks-checkout': {
		global: [ 'wc', 'blocksCheckout' ],
		handle: 'wc-blocks-checkout',
	},
};

const requestToExternal = ( request ) => {
	if ( wcExternalsMap[ request ] ) {
		return wcExternalsMap[ request ].global;
	}
};

const requestToHandle = ( request ) => {
	if ( wcExternalsMap[ request ] ) {
		return wcExternalsMap[ request ].handle;
	}
};

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !==
				'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			injectPolyfill: true,
			requestToExternal,
			requestToHandle,
		} ),
	],
};
