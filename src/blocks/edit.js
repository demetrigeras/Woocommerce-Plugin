/**
 * Stablecoin Pay — block-editor preview.
 *
 * Rendered inside the Gutenberg editor when the merchant is editing the
 * Checkout page block. Just needs to identify the payment method to the
 * admin — customers never see this.
 */

const Edit = ( { settings } ) => {
	return (
		<div>
			{ settings?.description ||
				'Pay securely with cryptocurrency. (Stablecoin Pay block-checkout preview.)' }
		</div>
	);
};

export default Edit;
