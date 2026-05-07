<?php
/**
 * Post Price Settings Tab View
 *
 * @package FLS_Checkout_Flow
 */

defined( 'ABSPATH' ) || exit;

$regions = array(
	'england'          => __( 'England', 'fls-checkout-flow' ),
	'northern_ireland' => __( 'Northern Ireland', 'fls-checkout-flow' ),
	'scotland'         => __( 'Scotland', 'fls-checkout-flow' ),
	'wales'            => __( 'Wales', 'fls-checkout-flow' ),
);

$shipping_classes        = WC()->shipping()->get_shipping_classes();
$saved_settings          = FLS_Checkout_Flow::init()->get_post_price_settings();
$enabled_regions         = isset( $saved_settings['enabled_regions'] ) ? (array) $saved_settings['enabled_regions'] : array();
$region_prices           = isset( $saved_settings['region_prices'] ) ? (array) $saved_settings['region_prices'] : array();
$free_shipping_threshold = isset( $saved_settings['free_shipping_threshold'] ) ? (float) $saved_settings['free_shipping_threshold'] : 0;
$free_shipping_regions   = isset( $saved_settings['free_shipping_regions'] ) ? (array) $saved_settings['free_shipping_regions'] : array();
?>

<div class="wrap woocommerce">
	<h1><?php esc_html_e( 'Config Post Price', 'fls-checkout-flow' ); ?></h1>

	<form method="post" action="" enctype="multipart/form-data" id="fls-post-price-form">
		<?php wp_nonce_field( 'fls-post-price-settings', 'fls_post_price_nonce' ); ?>

		<table class="form-table">
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label><?php esc_html_e( 'UK Regions', 'fls-checkout-flow' ); ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text">
							<span><?php esc_html_e( 'UK Regions', 'fls-checkout-flow' ); ?></span>
						</legend>

						<?php foreach ( $regions as $region_key => $region_label ) : ?>
							<label style="display:block; margin-bottom: 10px;">
								<input
									type="checkbox"
									name="fls_post_price_regions[]"
									value="<?php echo esc_attr( $region_key ); ?>"
									class="fls-post-price-region-toggle"
									data-region="<?php echo esc_attr( $region_key ); ?>"
									<?php checked( in_array( $region_key, $enabled_regions, true ) ); ?>
								/>
								<strong><?php echo esc_html( $region_label ); ?></strong>
							</label>

							<div
								id="fls-post-price-<?php echo esc_attr( $region_key ); ?>"
								class="fls-post-price-region-settings"
								style="<?php echo in_array( $region_key, $enabled_regions, true ) ? '' : 'display:none;'; ?> margin-left: 20px; margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1;"
							>
								<h4 style="margin-top: 0; margin-bottom: 15px;">
									<?php
									printf(
										/* translators: %s: Region name */
										esc_html__( 'Shipping Classes for %s', 'fls-checkout-flow' ),
										esc_html( $region_label )
									);
									?>
								</h4>

								<?php if ( ! empty( $shipping_classes ) ) : ?>
									<table class="widefat" style="margin-bottom: 0;">
										<thead>
											<tr>
												<th style="padding: 10px;"><?php esc_html_e( 'Shipping Class', 'fls-checkout-flow' ); ?></th>
												<th style="padding: 10px;"><?php esc_html_e( 'Price', 'fls-checkout-flow' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $shipping_classes as $shipping_class ) : ?>
												<?php
												$price_key = $region_key . '_' . $shipping_class->term_id;
												$price     = isset( $region_prices[ $price_key ] ) ? (float) $region_prices[ $price_key ] : 0;
												?>
												<tr>
													<td style="padding: 10px;">
														<label for="fls_post_price_<?php echo esc_attr( $price_key ); ?>">
															<?php echo esc_html( $shipping_class->name ); ?>
															<?php if ( ! empty( $shipping_class->description ) ) : ?>
																<span style="color: #666; font-size: 12px; display: block;">
																	<?php echo esc_html( $shipping_class->description ); ?>
																</span>
															<?php endif; ?>
														</label>
													</td>
													<td style="padding: 10px;">
														<input
															type="number"
															step="0.01"
															min="0"
															name="fls_post_price_region_prices[<?php echo esc_attr( $price_key ); ?>]"
															id="fls_post_price_<?php echo esc_attr( $price_key ); ?>"
															value="<?php echo esc_attr( $price ); ?>"
															style="width: 120px;"
														/>
														<span style="margin-left: 5px;"><?php echo esc_html( get_woocommerce_currency() ); ?></span>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								<?php else : ?>
									<p>
										<em><?php esc_html_e( 'No shipping classes found. Please add shipping classes in WooCommerce settings.', 'fls-checkout-flow' ); ?></em>
									</p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="fls_free_shipping_threshold">
						<?php esc_html_e( 'Free Shipping Threshold', 'fls-checkout-flow' ); ?>
					</label>
				</th>
				<td class="forminp">
					<input
						type="number"
						step="0.01"
						min="0"
						name="fls_free_shipping_threshold"
						id="fls_free_shipping_threshold"
						value="<?php echo esc_attr( $free_shipping_threshold ); ?>"
						style="width: 150px;"
					/>
					<span style="margin-left: 5px;"><?php echo esc_html( get_woocommerce_currency() ); ?></span>
					<p class="description">
						<?php esc_html_e( 'Minimum cart subtotal required to qualify for free shipping. Set to 0 to disable free shipping threshold.', 'fls-checkout-flow' ); ?>
					</p>

					<fieldset style="margin-top: 15px; padding: 12px 15px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 0 4px 4px 0;">
						<legend style="font-weight: 600; margin-bottom: 8px;">
							<?php esc_html_e( 'Apply free shipping to these regions:', 'fls-checkout-flow' ); ?>
						</legend>
						<?php foreach ( $regions as $region_key => $region_label ) : ?>
							<label style="display: inline-block; margin-right: 18px; margin-bottom: 6px;">
								<input
									type="checkbox"
									name="fls_free_shipping_regions[]"
									value="<?php echo esc_attr( $region_key ); ?>"
									<?php checked( in_array( $region_key, $free_shipping_regions, true ) ); ?>
								/>
								<?php echo esc_html( $region_label ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description" style="margin-top: 8px;">
							<?php esc_html_e( 'Select which regions qualify for free shipping when the cart meets the threshold. If none selected, the threshold will not apply.', 'fls-checkout-flow' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="submit" id="submit" class="woocommerce-button button button-primary button-large" value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>">
				<?php esc_html_e( 'Save changes', 'woocommerce' ); ?>
			</button>
		</p>
	</form>
</div>

<script type="text/javascript">
jQuery( document ).ready( function( $ ) {
	$( '.fls-post-price-region-toggle' ).on( 'change', function() {
		var region = $( this ).data( 'region' );
		var $panel = $( '#fls-post-price-' + region );

		if ( $( this ).is( ':checked' ) ) {
			$panel.slideDown( 200 );
		} else {
			$panel.slideUp( 200 );
		}
	} );
} );
</script>

<style type="text/css">
.fls-post-price-region-settings {
	border-radius: 0 4px 4px 0;
}
.fls-post-price-region-settings h4 {
	color: #2271b1;
}
.fls-post-price-region-settings tbody tr:nth-child(odd) {
	background-color: #fff;
}
.fls-post-price-region-settings tbody tr:hover {
	background-color: #f0f0f1;
}
</style>
