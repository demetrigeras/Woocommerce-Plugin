/**
 * Stablecoin Pay — block-checkout radio-button label.
 *
 * Shown next to the radio button in the block checkout's payment method list.
 * Renders the gateway title + logo using the data passed in from PHP.
 */

const Label = ( { settings } ) => {
	const title = settings?.title || 'Pay with Crypto';
	const logoUrl = settings?.logoUrl || '';

	return (
		<span
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '10px',
			} }
		>
			{ logoUrl && (
				<img
					src={ logoUrl }
					alt={ title }
					style={ { height: '24px', width: 'auto' } }
				/>
			) }
			<span>{ title }</span>
		</span>
	);
};

export default Label;
