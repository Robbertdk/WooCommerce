<?php
/**
 * Shows an order item
 *
 * @package WooCommerce/Admin
 * @var object $item The item being displayed
 * @var int $item_id The id of the item being displayed
 */

defined( 'ABSPATH' ) || exit;

$product      = $item->get_product();
$product_link = $product ? admin_url( 'post.php?post=' . $item->get_product_id() . '&action=edit' ) : '';
$thumbnail    = $product ? apply_filters( 'woocommerce_admin_order_item_thumbnail', $product->get_image( 'thumbnail', array( 'title' => '' ), false ), $item_id, $item ) : '';
$row_class    = apply_filters( 'woocommerce_admin_html_order_item_class', ! empty( $class ) ? $class : '', $item, $order );
?>
<tr class="capture-item <?php echo esc_attr( $row_class ); ?>" data-order_item_id="<?php echo esc_attr( $item_id ); ?>">
	<td class="thumb">
		<?php echo '<div class="wc-order-item-thumbnail">' . wp_kses_post( $thumbnail ) . '</div>'; ?>
	</td>
	<td class="name" data-sort-value="<?php echo esc_attr( $item->get_name() ); ?>">
		<?php
		echo $product_link ? '<a href="' . esc_url( $product_link ) . '" class="wc-order-item-name">' . wp_kses_post( $item->get_name() ) . '</a>' : '<div class="wc-order-item-name">' . wp_kses_post( $item->get_name() ) . '</div>';

		if ( $product && $product->get_sku() ) {
			echo '<div class="wc-order-item-sku"><strong>' . esc_html__( 'SKU:', 'woocommerce' ) . '</strong> ' . esc_html( $product->get_sku() ) . '</div>';
		}

		if ( $item->get_variation_id() ) {
			echo '<div class="wc-order-item-variation"><strong>' . esc_html__( 'Variation ID:', 'woocommerce' ) . '</strong> ';
			if ( 'product_variation' === get_post_type( $item->get_variation_id() ) ) {
				echo esc_html( $item->get_variation_id() );
			} else {
				/* translators: %s: variation id */
				printf( esc_html__( '%s (No longer exists)', 'woocommerce' ), esc_html( $item->get_variation_id() ) );
			}
			echo '</div>';
		}
		?>
		<input type="hidden" class="order_item_id" name="order_item_id[]" value="<?php echo esc_attr( $item_id ); ?>" />
		<input type="hidden" name="order_item_tax_class[<?php echo absint( $item_id ); ?>]" value="<?php echo esc_attr( $item->get_tax_class() ); ?>" />

		<?php do_action( 'woocommerce_before_order_itemmeta', $item_id, $item, $product ); ?>
		<?php require 'html-order-item-meta.php'; ?>
		<?php do_action( 'woocommerce_after_order_itemmeta', $item_id, $item, $product ); ?>
	</td>

	<?php do_action( 'woocommerce_admin_order_item_values', $product, $item, absint( $item_id ) ); ?>

	<td class="item_cost" width="1%" data-sort-value="<?php echo esc_attr( $order->get_item_subtotal( $item, false, true ) ); ?>">
		<div class="view">
			<?php
			echo wc_price( $order->get_item_total( $item, false, true ), array( 'currency' => $order->get_currency() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	</td>
	<td class="quantity" width="1%">
		<div class="view">
			<?php
			echo '<small class="times">&times;</small> ' . esc_html( $item->get_quantity() );

			$captured_qty = 0;
			$amountLeft = $item->get_quantity();
			
			if ($captures != false){

			foreach($captures as $capture){
				$itemsCaptureedArray = json_decode($capture["line_item_qtys"], true);

				if (array_key_exists($item_id, $itemsCaptureedArray )){
					$captured_qty += $itemsCaptureedArray[$item_id];
				}
			}

			$amountLeft = $item->get_quantity() - $captured_qty;
		}

		if ( $captured_qty ) {
			echo '<small class="captured"> (-' . esc_html( $captured_qty * 1 ) . ')</small>';
		}
			?>
		</div>
		<div class="edit" style="display: none;">
			<input type="number" step="<?php echo esc_attr( apply_filters( 'woocommerce_quantity_input_step', '1', $product ) ); ?>" min="0" autocomplete="off" name="order_item_qty[<?php echo absint( $item_id ); ?>]" placeholder="0" value="<?php echo esc_attr( $item->get_quantity() ); ?>" data-qty="<?php echo esc_attr( $item->get_quantity() ); ?>" size="4" class="quantity" />
		</div>
		<div class="capture" style="display: none;">
			<?php if (!isset($amountLeft)) {
                    $amountLeft = $item->get_quantity();
			}
			?>
			<input type="number" step="<?php echo esc_attr( apply_filters( 'woocommerce_quantity_input_step', '1', $product ) ); ?>" value="<?php echo esc_attr($amountLeft); ?>" min="0" max="<?php echo esc_attr($amountLeft); ?>" autocomplete="off" name="capture_order_item_qty[<?php echo absint( $item_id ); ?>]" placeholder="0" size="4" class="capture_order_item_qty" />
		</div>
	</td>
	<td class="line_cost" width="1%" data-sort-value="<?php echo esc_attr( $item->get_total() ); ?>">
		<div class="view">
			<?php
			echo esc_html(wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ));

			if ( $item->get_subtotal() !== $item->get_total() ) {
				/* translators: %s: discount amount */
				echo '<span class="wc-order-item-discount">' . sprintf( esc_html__( '%s discount', 'woocommerce' ), esc_html(wc_price( wc_format_decimal( $item->get_subtotal() - $item->get_total()), '' ), array( 'currency' => $order->get_currency() ) ) ) . '</span>';
			}

            $captured = false;

			if ( $captured ) {
				echo '<small class="captured">-' . esc_html(wc_price( $captured, array( 'currency' => $order->get_currency() ) )) . '</small>';
			}
			?>
		</div>
		<div class="edit" style="display: none;">
			<div class="split-input">
				<div class="input">
					<label><?php esc_attr_e( 'Before discount', 'woocommerce' ); ?></label>
					<input type="text" name="line_subtotal[<?php echo absint( $item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $item->get_subtotal() ) ); ?>" class="line_subtotal wc_input_price" data-subtotal="<?php echo esc_attr( wc_format_localized_price( $item->get_subtotal() ) ); ?>" />
				</div>
				<div class="input">
					<label><?php esc_attr_e( 'Total', 'woocommerce' ); ?></label>
					<input type="text" name="line_total[<?php echo absint( $item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $item->get_total() ) ); ?>" class="line_total wc_input_price" data-tip="<?php esc_attr_e( 'After pre-tax discounts.', 'woocommerce' ); ?>" data-total="<?php echo esc_attr( wc_format_localized_price( $item->get_total() ) ); ?>" />
				</div>
			</div>
		</div>
		<div class="capture" style="display: none;">
			<input type="text" disabled="true" value=<?php echo esc_attr($amountLeft * $order->get_item_total( $item, false, true )); ?> name="capture_line_total[<?php echo absint( $item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" class="capture_line_total wc_input_price" />
		</div>
	</td>

	<?php
	$tax_data = wc_tax_enabled() ? $item->get_taxes() : false;

	if ( $tax_data ) {
		foreach ( $order_taxes as $tax_item ) {
			$tax_item_id       = $tax_item->get_rate_id();
			$tax_item_total    = isset( $tax_data['total'][ $tax_item_id ] ) ? $tax_data['total'][ $tax_item_id ] : '';
			$tax_item_subtotal = isset( $tax_data['subtotal'][ $tax_item_id ] ) ? $tax_data['subtotal'][ $tax_item_id ] : '';
			?>
			<td class="line_tax" width="1%">
				<div class="view">
					<?php
					if ( '' !== $tax_item_total ) {
						echo esc_html(wc_price( wc_round_tax_total( $tax_item_total ), array( 'currency' => $order->get_currency() ) ));
					} else {
						echo '&ndash;';
					}

                    $captured = false;

					if ( $captured ) {
						echo '<small class="captured">-' . esc_html(wc_price( $captured, array( 'currency' => $order->get_currency() ) )) . '</small>';
					}
					?>
				</div>
				<div class="edit" style="display: none;">
					<div class="split-input">
						<div class="input">
							<label><?php esc_attr_e( 'Before discount', 'woocommerce' ); ?></label>
							<input type="text" name="line_subtotal_tax[<?php echo absint( $item_id ); ?>][<?php echo esc_attr( $tax_item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $tax_item_subtotal ) ); ?>" class="line_subtotal_tax wc_input_price" data-subtotal_tax="<?php echo esc_attr( wc_format_localized_price( $tax_item_subtotal ) ); ?>" data-tax_id="<?php echo esc_attr( $tax_item_id ); ?>" />
						</div>
						<div class="input">
							<label><?php esc_attr_e( 'Total', 'woocommerce' ); ?></label>
							<input type="text" name="line_tax[<?php echo absint( $item_id ); ?>][<?php echo esc_attr( $tax_item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $tax_item_total ) ); ?>" class="line_tax wc_input_price" data-total_tax="<?php echo esc_attr( wc_format_localized_price( $tax_item_total ) ); ?>" data-tax_id="<?php echo esc_attr( $tax_item_id ); ?>" />
						</div>
					</div>
				</div>
				<div class="capture" style="display: none;">
					<input type="text" disabled="true" value=<?php echo esc_attr(roundAmount(floatval($tax_item_total) / $item->get_quantity() * $amountLeft)); ?> name="capture_line_tax[<?php echo absint( $item_id ); ?>][<?php echo esc_attr( $tax_item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" class="capture_line_tax wc_input_price" data-tax_id="<?php echo esc_attr( $tax_item_id ); ?>" />
				</div>
			</td>
			<?php
		}
	}
	?>
	<td class="wc-order-edit-line-item" width="1%">
		<div class="wc-order-edit-line-item-actions">
			<?php if ( $order->is_editable() ) : ?>
				<a class="edit-order-item tips" href="#" data-tip="<?php esc_attr_e( 'Edit item', 'woocommerce' ); ?>"></a><a class="delete-order-item tips" href="#" data-tip="<?php esc_attr_e( 'Delete item', 'woocommerce' ); ?>"></a>
			<?php endif; ?>
		</div>
	</td>
</tr>
