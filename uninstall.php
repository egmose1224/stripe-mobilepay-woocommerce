<?php
/**
 * Deleting the plugin forgets the webhook secrets and the gateway settings. Order data (notes, _smpw_* meta) stays —
 * it is the record of payments. The endpoints at Stripe are left alone: remove them first with
 * `wp smpw webhook delete --mode=test|live` if the plugin goes for good.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'smpw_webhook_test' );
delete_option( 'smpw_webhook_live' );
delete_option( 'woocommerce_smpw_mobilepay_settings' );
