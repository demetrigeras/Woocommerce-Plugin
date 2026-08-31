<?php
/**
 * Stablecoin Pay review / explainer page template.
 *
 * This page helps merchants understand that Stablecoin Pay is powered by Stablecoin Pay.
 *
 * @package Stablecoin Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Branding comes from sp-whitelabel-config.php - there is no API lookup.
$whitelabel_branding = new SP_Whitelabel_Branding();
$branding_data = array(
    'company'    => $whitelabel_branding->get_company_name() ?: __('Stablecoin Pay', 'stablecoin-pay'),
    'powered_by' => $whitelabel_branding->get_powered_by_text(),
);

$default_branding = array(
    'title'        => sprintf(__('About %s', 'stablecoin-pay'), $branding_data['company']),
    'subtitle'     => __('Your dedicated crypto payments experience', 'stablecoin-pay'),
    'logo_url'     => $whitelabel_branding->get_logo_url('default', 'light'),
    'powered_by'   => $branding_data['powered_by'],
    'description'  => sprintf(__('%s delivers a white-label checkout that inherits your brand while leveraging Stablecoin Pay\'s settlement rails and compliance tooling.', 'stablecoin-pay'), $branding_data['company']),
    'what_is'      => sprintf(__('%s is a cryptocurrency payment solution that enables your customers to pay with USDC and other stablecoins directly from their crypto wallets. All transactions are processed securely on the blockchain with instant settlement.', 'stablecoin-pay'), $branding_data['company']),
    'how_it_works' => array(
        sprintf(__('Customer selects %s at checkout', 'stablecoin-pay'), $branding_data['company']) => sprintf(__('Your customers choose %s as their payment method during checkout', 'stablecoin-pay'), $branding_data['company']),
        __('Connect crypto wallet', 'stablecoin-pay') => __('Customers connect their Web3 wallet (MetaMask, WalletConnect, etc.)', 'stablecoin-pay'),
        __('Approve payment', 'stablecoin-pay') => __('Customers approve the transaction in their wallet', 'stablecoin-pay'),
        __('Instant confirmation', 'stablecoin-pay') => __('Payment is confirmed on-chain and your order is automatically processed', 'stablecoin-pay'),
    ),
    'features'     => array(
        __('Accept USDC payments', 'stablecoin-pay') => __('Receive payments in USDC on multiple networks (Polygon, Ethereum, etc.)', 'stablecoin-pay'),
        __('Subscriptions support', 'stablecoin-pay') => __('Set up recurring payments for subscription products', 'stablecoin-pay'),
        __('Automatic reconciliation', 'stablecoin-pay') => __('Orders update automatically when payments are confirmed', 'stablecoin-pay'),
        __('Secure & compliant', 'stablecoin-pay') => __('Stablecoin Pay handles custody, compliance, and security infrastructure', 'stablecoin-pay'),
    ),
    'highlights'   => array(
        __('Branded checkout flows for every white-label partner', 'stablecoin-pay'),
        __('USDC on/off ramps, subscriptions, and automated reconciliation', 'stablecoin-pay'),
        __('Stablecoin Pay\'s infrastructure keeps custody and token routing secure', 'stablecoin-pay'),
    ),
    'cta_text'     => __('Return to Checkout', 'stablecoin-pay'),
    'cta_url'      => wc_get_page_permalink('checkout'),
    'support_text' => __('Need help linking your merchant ID or API key? Reach out to your platform admin or Stablecoin Pay support to get branded assets configured.', 'stablecoin-pay'),
);

$branding = wp_parse_args(
    apply_filters('sp_review_page_branding', array(), get_current_user_id()),
    $default_branding
);
?>

<main id="sp-review" class="sp-review">
    <div class="sp-review__hero">
        <?php if (!empty($branding['logo_url'])) : ?>
            <img class="sp-review__logo" src="<?php echo esc_url($branding['logo_url']); ?>" alt="<?php echo esc_attr($branding['powered_by']); ?>" />
        <?php endif; ?>
        <p class="sp-review__powered"><?php echo esc_html($branding['powered_by']); ?></p>
        <h1><?php echo esc_html($branding['title']); ?></h1>
        <p class="sp-review__subtitle"><?php echo esc_html($branding['subtitle']); ?></p>
    </div>

    <section class="sp-review__content">
        <p class="sp-review__description"><?php echo esc_html($branding['description']); ?></p>

        <?php if (!empty($branding['what_is'])) : ?>
            <div class="sp-review__section">
                <h2><?php _e('What is this?', 'stablecoin-pay'); ?></h2>
                <p><?php echo esc_html($branding['what_is']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['how_it_works']) && is_array($branding['how_it_works'])) : ?>
            <div class="sp-review__section">
                <h2><?php _e('How it works', 'stablecoin-pay'); ?></h2>
                <ol class="sp-review__steps">
                    <?php foreach ($branding['how_it_works'] as $step_title => $step_description) : ?>
                        <li>
                            <strong><?php echo esc_html($step_title); ?></strong>
                            <span><?php echo esc_html($step_description); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['features']) && is_array($branding['features'])) : ?>
            <div class="sp-review__section">
                <h2><?php _e('Key Features', 'stablecoin-pay'); ?></h2>
                <ul class="sp-review__features">
                    <?php foreach ($branding['features'] as $feature_title => $feature_description) : ?>
                        <li>
                            <strong><?php echo esc_html($feature_title); ?></strong>
                            <span><?php echo esc_html($feature_description); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['highlights']) && is_array($branding['highlights'])) : ?>
            <div class="sp-review__section">
                <h2><?php _e('Why choose this?', 'stablecoin-pay'); ?></h2>
                <ul class="sp-review__highlights">
                    <?php foreach ($branding['highlights'] as $highlight) : ?>
                        <li><?php echo esc_html($highlight); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="sp-review__section sp-review__footer">
            <p class="sp-review__support"><?php echo esc_html($branding['support_text']); ?></p>

            <?php if (!empty($branding['cta_url'])) : ?>
                <a class="sp-review__cta button" href="<?php echo esc_url($branding['cta_url']); ?>">
                    <?php echo esc_html($branding['cta_text']); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>
</main>

<style>
    .sp-review {
        max-width: 800px;
        margin: 0 auto;
        padding: 4rem 1.5rem;
    }
    .sp-review__hero {
        margin-bottom: 3rem;
        text-align: center;
    }
    .sp-review__logo {
        max-height: 64px;
        margin-bottom: 1rem;
    }
    .sp-review__powered {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #666;
        margin-bottom: 0.5rem;
    }
    .sp-review__subtitle {
        font-size: 1.2rem;
        color: #444;
    }
    .sp-review__content {
        text-align: left;
    }
    .sp-review__description {
        font-size: 1.1rem;
        line-height: 1.6;
        margin-bottom: 2rem;
        color: #333;
    }
    .sp-review__section {
        margin-bottom: 3rem;
    }
    .sp-review__section h2 {
        font-size: 1.5rem;
        margin-bottom: 1.5rem;
        color: #111827;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 0.5rem;
    }
    .sp-review__steps {
        list-style: none;
        padding: 0;
        margin: 0;
        counter-reset: step-counter;
    }
    .sp-review__steps li {
        counter-increment: step-counter;
        margin-bottom: 1.5rem;
        padding-left: 3rem;
        position: relative;
    }
    .sp-review__steps li::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        width: 2rem;
        height: 2rem;
        background: #111827;
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 0.9rem;
    }
    .sp-review__steps li strong {
        display: block;
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
        color: #111827;
    }
    .sp-review__steps li span {
        display: block;
        color: #555;
        line-height: 1.6;
    }
    .sp-review__features {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .sp-review__features li {
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #f9fafb;
        border-left: 4px solid #3b82f6;
        border-radius: 4px;
    }
    .sp-review__features li strong {
        display: block;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        color: #111827;
    }
    .sp-review__features li span {
        display: block;
        color: #555;
        line-height: 1.6;
    }
    .sp-review__highlights {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .sp-review__highlights li {
        margin-bottom: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        background: #f6f8fb;
        border: 1px solid #e1e5ee;
        position: relative;
        padding-left: 2.5rem;
    }
    .sp-review__highlights li::before {
        content: "✓";
        position: absolute;
        left: 1rem;
        color: #10b981;
        font-weight: bold;
        font-size: 1.2rem;
    }
    .sp-review__footer {
        text-align: center;
        margin-top: 3rem;
        padding-top: 2rem;
        border-top: 1px solid #e5e7eb;
    }
    .sp-review__support {
        color: #555;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }
    .sp-review__cta.button {
        display: inline-block;
        padding: 0.85rem 1.75rem;
        font-size: 1rem;
        border-radius: 999px;
        color: #fff;
        background: #111827;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .sp-review__cta.button:hover {
        opacity: 0.9;
    }
</style>

<?php
get_footer();

