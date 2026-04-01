<?php
defined( 'ABSPATH' ) || exit;

if ( ! $order ) {
	return;
}

$order_id         = $order->get_id();
$order_number     = $order->get_order_number();
$order_date       = wc_format_datetime( $order->get_date_created() );
$payment_method   = $order->get_payment_method_title();
$order_status     = wc_get_order_status_name( $order->get_status() );
$date_paid        = $order->get_date_paid() ? wc_format_datetime( $order->get_date_paid() ) : '';
$shipping_methods = $order->get_shipping_method();
$delivery_date    = $order->get_meta( '_fls_delivery_date' );

$vat_data       = $order->get_meta( '_fls_vat_breakdown', true );
$discount_rows  = $order->get_meta( '_fls_discount_rows', true );
$discount_total = (float) $order->get_meta( '_fls_discount_total', true );

$vat_data = is_array( $vat_data ) ? $vat_data : array(
	'total'        => (float) $order->get_total(),
	'vat'          => 0,
	'total_ex_vat' => (float) $order->get_total(),
	'decimals'     => wc_get_price_decimals(),
);

$discount_rows = is_array( $discount_rows ) ? $discount_rows : array();

$shipping_address_1 = $order->get_shipping_address_1();
$shipping_city      = $order->get_shipping_city();
$shipping_postcode  = $order->get_shipping_postcode();

if ( empty( $shipping_address_1 ) ) {
	$shipping_address_1 = $order->get_billing_address_1();
	$shipping_city      = $order->get_billing_city();
	$shipping_postcode  = $order->get_billing_postcode();
}

$items          = $order->get_items( 'line_item' );
$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();

do_action( 'woocommerce_before_thankyou', $order_id );
?>

    <div class="fls-thankyou">
        <div class="fls-thankyou__hero">
            <div class="fls-thankyou__icon">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="48" height="48" rx="24" fill="#2F9B57"/>
                    <path d="M33.3337 17L20.5003 29.8333L14.667 24" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h1 class="fls-thankyou__title"><?php esc_html_e( 'Thank you for your purchase!', 'fls-checkout-flow' ); ?></h1>
            <p class="fls-thankyou__subtitle"><?php esc_html_e( 'Your order has been successfully processed.', 'fls-checkout-flow' ); ?></p>
            <p class="fls-thankyou__meta">
				<?php echo esc_html( sprintf( __( 'Order #%1$s · %2$s', 'fls-checkout-flow' ), $order_number, $order_date ) ); ?>
            </p>
        </div>

        <div class="fls-thankyou__cards">

            <div class="fls-thankyou-card">
                <h3 class="fls-thankyou-card__title">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7.33333 14.4867C7.53603 14.6037 7.76595 14.6653 8 14.6653C8.23405 14.6653 8.46397 14.6037 8.66667 14.4867L13.3333 11.82C13.5358 11.7031 13.704 11.535 13.821 11.3326C13.938 11.1301 13.9998 10.9005 14 10.6667V5.33335C13.9998 5.09953 13.938 4.86989 13.821 4.66746C13.704 4.46503 13.5358 4.29692 13.3333 4.18002L8.66667 1.51335C8.46397 1.39633 8.23405 1.33472 8 1.33472C7.76595 1.33472 7.53603 1.39633 7.33333 1.51335L2.66667 4.18002C2.46418 4.29692 2.29599 4.46503 2.17897 4.66746C2.06196 4.86989 2.00024 5.09953 2 5.33335V10.6667C2.00024 10.9005 2.06196 11.1301 2.17897 11.3326C2.29599 11.535 2.46418 11.7031 2.66667 11.82L7.33333 14.4867Z" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M8 14.6667V8" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2.19336 4.66663L8.00003 7.99996L13.8067 4.66663" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M5 2.84668L11 6.28001" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
		            <?php esc_html_e( 'Order details', 'fls-checkout-flow' ); ?>
                </h3>

                <div class="fls-thankyou-card__body">
			        <?php foreach ( $items as $item_id => $item ) : ?>
				        <?php
				        $qty         = (int) $item->get_quantity();
				        $name        = $item->get_name();
				        $is_sample   = 'yes' === $item->get_meta( '_fls_is_sample_product', true );
				        $pack_count  = $item->get_meta( '_fls_pack_count', true );
				        $room_size   = $item->get_meta( '_fls_room_size', true );
				        ?>
                        <div class="fls-thankyou-card__row">
                            <span><?php esc_html_e( 'Product', 'woocommerce' ); ?></span>
                            <strong><?php echo esc_html( $name ); ?></strong>
                        </div>

				        <?php if ( $is_sample ) : ?>
                            <div class="fls-thankyou-card__row">
                                <span><?php esc_html_e( 'Type', 'fls-checkout-flow' ); ?></span>
                                <strong><?php esc_html_e( 'Sample product', 'fls-checkout-flow' ); ?></strong>
                            </div>
				        <?php else : ?>
                            <div class="fls-thankyou-card__row">
                                <span><?php esc_html_e( 'Quantity', 'woocommerce' ); ?></span>
                                <strong>
							        <?php
							        if ( ! empty( $pack_count ) ) {
								        echo esc_html( $pack_count . ' ' . _n( 'pack', 'packs', (int) $pack_count, 'fls-checkout-flow' ) );
							        } else {
								        echo esc_html( $qty );
							        }
							        ?>
                                </strong>
                            </div>

					        <?php if ( '' !== (string) $room_size ) : ?>
                                <div class="fls-thankyou-card__row">
                                    <span><?php esc_html_e( 'Room size', 'fls-checkout-flow' ); ?></span>
                                    <strong><?php echo esc_html( wc_format_decimal( (float) $room_size, 2 ) . 'm²' ); ?></strong>
                                </div>
					        <?php endif; ?>
				        <?php endif; ?>
			        <?php endforeach; ?>

                    <hr class="fls-thankyou-card__divider">

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                        <strong><?php echo wp_kses_post( wc_price( (float) $order->get_subtotal() ) ); ?></strong>
                    </div>

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Delivery', 'fls-checkout-flow' ); ?></span>
                        <strong><?php echo wp_kses_post( wc_price( $shipping_total ) ); ?></strong>
                    </div>

			        <?php foreach ( $discount_rows as $discount_row ) : ?>
                        <div class="fls-thankyou-card__row fls-thankyou-card__row--discount">
                            <span><?php echo esc_html( $discount_row['label'] ); ?></span>
                            <strong>- <?php echo wp_kses_post( wc_price( (float) $discount_row['amount'] ) ); ?></strong>
                        </div>
			        <?php endforeach; ?>

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'VAT included', 'fls-checkout-flow' ); ?></span>
                        <strong><?php echo wp_kses_post( wc_price( (float) $vat_data['vat'] ) ); ?></strong>
                    </div>

                    <hr class="fls-thankyou-card__divider">

	                <?php if ( $discount_total > 0 && count( $discount_rows ) > 1 ) : ?>
                        <div class="fls-thankyou-card__row fls-thankyou-card__row--discount-total">
                            <span><?php esc_html_e( 'Discount total', 'fls-checkout-flow' ); ?></span>
                            <strong>- <?php echo wp_kses_post( wc_price( $discount_total ) ); ?></strong>
                        </div>
	                <?php endif; ?>

                    <div class="fls-thankyou-card__row fls-thankyou-card__row--total">
                        <span><?php esc_html_e( 'Total', 'woocommerce' ); ?></span>
                        <strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
                    </div>
                </div>
            </div>

            <div class="fls-thankyou-card">
                <h3 class="fls-thankyou-card__title">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9.33301 12V3.99996C9.33301 3.64634 9.19253 3.3072 8.94248 3.05715C8.69243 2.8071 8.3533 2.66663 7.99967 2.66663H2.66634C2.31272 2.66663 1.97358 2.8071 1.72353 3.05715C1.47348 3.3072 1.33301 3.64634 1.33301 3.99996V11.3333C1.33301 11.5101 1.40325 11.6797 1.52827 11.8047C1.65329 11.9297 1.82286 12 1.99967 12H3.33301" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 12H6" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M12.6663 12H13.9997C14.1765 12 14.3461 11.9298 14.4711 11.8048C14.5961 11.6798 14.6663 11.5102 14.6663 11.3334V8.90004C14.6661 8.74875 14.6144 8.60205 14.5197 8.48404L12.1997 5.58404C12.1373 5.50596 12.0582 5.44289 11.9682 5.39951C11.8782 5.35612 11.7796 5.33352 11.6797 5.33337H9.33301" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11.3333 13.3333C12.0697 13.3333 12.6667 12.7363 12.6667 12C12.6667 11.2636 12.0697 10.6666 11.3333 10.6666C10.597 10.6666 10 11.2636 10 12C10 12.7363 10.597 13.3333 11.3333 13.3333Z" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M4.66634 13.3333C5.40272 13.3333 5.99967 12.7363 5.99967 12C5.99967 11.2636 5.40272 10.6666 4.66634 10.6666C3.92996 10.6666 3.33301 11.2636 3.33301 12C3.33301 12.7363 3.92996 13.3333 4.66634 13.3333Z" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php esc_html_e( 'Delivery information', 'fls-checkout-flow' ); ?>
                </h3>

                <div class="fls-thankyou-card__body">
                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Street address', 'fls-checkout-flow' ); ?></span>
                        <strong><?php echo esc_html( $shipping_address_1 ); ?></strong>
                    </div>

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Town/City', 'fls-checkout-flow' ); ?></span>
                        <strong><?php echo esc_html( $shipping_city ); ?></strong>
                    </div>

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Postcode', 'woocommerce' ); ?></span>
                        <strong><?php echo esc_html( $shipping_postcode ); ?></strong>
                    </div>

                    <hr class="fls-thankyou-card__divider">

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Shipping method', 'woocommerce' ); ?></span>
                        <strong><?php echo esc_html( $shipping_methods ); ?></strong>
                    </div>

					<?php if ( ! empty( $delivery_date ) ) : ?>
                        <div class="fls-thankyou-card__row">
                            <span><?php esc_html_e( 'Delivery date', 'fls-checkout-flow' ); ?></span>
                            <strong><?php echo esc_html( $delivery_date ); ?></strong>
                        </div>
					<?php endif; ?>

                    <div class="fls-thankyou-card__note">
						<?php esc_html_e( 'Please ensure someone over 18 is available to receive the delivery and that access is clear for the driver.', 'fls-checkout-flow' ); ?>
                    </div>
                </div>
            </div>

            <div class="fls-thankyou-card">
                <h3 class="fls-thankyou-card__title">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13.333 3.33331H2.66634C1.92996 3.33331 1.33301 3.93027 1.33301 4.66665V11.3333C1.33301 12.0697 1.92996 12.6666 2.66634 12.6666H13.333C14.0694 12.6666 14.6663 12.0697 14.6663 11.3333V4.66665C14.6663 3.93027 14.0694 3.33331 13.333 3.33331Z" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M1.33301 6.66669H14.6663" stroke="#1F7CD4" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php esc_html_e( 'Payment', 'woocommerce' ); ?>
                </h3>

                <div class="fls-thankyou-card__body">
                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Method', 'woocommerce' ); ?></span>
                        <strong><?php echo esc_html( $payment_method ); ?></strong>
                    </div>

                    <div class="fls-thankyou-card__row">
                        <span><?php esc_html_e( 'Status', 'fls-checkout-flow' ); ?></span>
                        <strong><?php echo esc_html( $order_status ); ?></strong>
                    </div>

					<?php if ( ! empty( $date_paid ) ) : ?>
                        <div class="fls-thankyou-card__row">
                            <span><?php esc_html_e( 'Paid on', 'fls-checkout-flow' ); ?></span>
                            <strong><?php echo esc_html( $date_paid ); ?></strong>
                        </div>
					<?php endif; ?>

                    <p class="fls-thankyou-card__footnote">
						<?php esc_html_e( 'A copy of your receipt has been emailed to you.', 'fls-checkout-flow' ); ?>
                    </p>
                </div>
            </div>

        </div>

        <div class="fls-thankyou__support">
			<?php esc_html_e( 'Questions about your order?', 'fls-checkout-flow' ); ?>
            <a href="mailto:support@flooringsurgeons.co.uk">support@flooringsurgeons.co.uk</a>
        </div>
    </div>

<?php
do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order_id );
do_action( 'woocommerce_thankyou', $order_id );