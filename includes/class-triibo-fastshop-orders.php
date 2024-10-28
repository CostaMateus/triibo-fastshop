<?php
/**
 * Fast Shop Orders
 *
 * @package Triibo_Fast_Shop/Classes
 * @since   1.0.0
 * @version 1.0.0
 */
defined( "ABSPATH" ) || exit;

/**
 * Plugins Order class.
 */
class Triibo_Fast_Shop_Orders
{
    const ID     = "triibo-fast-shop-orders";
    const SELLER = "fast_shop";

    private $api        = null;
    private $type       = null;

    private $order_id   = null;
    private $old_status = null;
    private $new_status = null;

	/**
	 * Initialize webservice.
	 *
     * @since   1.0.0
	 * @param   array $package Shipping package.
	 */
	public function __construct( $order_id = null, $old_status = null, $new_status = null )
    {
        $this->id  = self::ID;

        $this->api = new Triibo_Api_Php();

        if ( $order_id && $old_status && $new_status )
        {
            $this->order_id   = $order_id;
            $this->old_status = $old_status;
            $this->new_status = $new_status;

            $this->process_order();
        }
	}

    /**
     * Get processing type
     *
     * @since   1.1.0
     * @return  string
     */
    public function get_type()
    {
        return $this->type;
    }

    /**
     * Set process
     *
     * @param   string  $old_status
     * @param   string  $new_status
     * @return  void
     */
    public function set_process( $old_status, $new_status )
    {
		$this->old_status = $old_status;
		$this->new_status = $new_status;

        $this->process_order();
    }

	/**
	 * Process the order
	 *
     * @since   1.0.0
	 * @return  void
	 */
	private function process_order()
    {
        // // qlqr_status ( -processando -concluido )  --> aguardando   ==  cria FS
        // // pendente                                 --> malsucedido  ==  cria FS
        // // aguardando || malsucedido                --> processando  ==  aprova FS
        // // qlqr_status ( -pendente )                --> cancelado    ==  cancela FS
        // // qlqr_status ( -aguardando -malsucedido ) --> processando  ==  cria e aprova FS

        // // qlqr_status ( -processando -concluido )  --> aguardando   ==  cria FS
        // // aguardando                               --> processando  ==  aprova FS
        // // qlqr_status ( -pendente )                --> cancelado    ==  cancela FS
        // // qlqr_status ( -aguardando )              --> processando  ==  cria e aprova FS

        // 09/01/23
        // qlqr_status ( -concluido )               --> aguardando   ==  cria FS
        // aguardando                               --> processando  ==  aprova FS
        // qlqr_status ( -pendente )                --> cancelado    ==  cancela FS

        /**
         * qlqr_status ( -concluido ) --> aguardando
         * pendente --> processando
         * pedido é CRIADO na FS
         */
        if (
            ( $this->old_status != "completed" && $this->new_status == "on-hold"    )
            ||
            ( $this->old_status == "pending"   && $this->new_status == "processing" )
        )
        {
            $this->type = "ordercreation";
        }

        /**
         * aguardando --> processando
         * pedido é APROVADO na FS
         */
        elseif ( $this->old_status == "on-hold" && $this->new_status == "processing" )
        {
            $this->type = "orderapproved";
        }

        /**
         * qlqr_status ( -pendente ) --> cancelado
         * pedido é CANCELADO na FS
         */
        elseif ( $this->old_status != "pending" && $this->new_status == "cancelled" )
        {
            $this->type = "ordercancelation";
        }
    }

    /**
     * Process the order, send to integration
     *
     * @since   1.0.0
     * @return  array
     */
    public function send_notification()
    {
        $status = get_option( "triibo_fast_shop_fs_status" );

        if ( $status != "on" )
        {
            $url = esc_url( admin_url( "admin.php?page=" . Triibo_Fast_Shop::DOMAIN . "-settings" ) );
            $this->log( "info", "Order notification is disabled. Enable in this link: {$url}", true );
            return;
        }

        if ( !$this->type )
        {
            $this->log( "info", "This changing of status is not mapped: {$this->old_status} >> {$this->new_status}", true );
            return;
        }

        switch ( $this->type )
        {
            case "ordercreation":
                $this->log( "info", "Creating order FS: #{$this->order_id}" );
                $response   = $this->api->fs_order_creation( $this->order_id, self::SELLER );
                $error_code = $response[ "data" ][ "error_code" ];
                $message    = ( $response[ "success" ] )
                                ? "Successfully created: #{$this->order_id}"
                                : "Error on creating #{$this->order_id} | " . "{$error_code} {$response[ "data" ][ "message" ]}";
            break;

            case "orderapproved":
                $this->log( "info", "Approving order FS: #{$this->order_id}" );
                $response   = $this->api->fs_order_approved( $this->order_id, self::SELLER );
                $error_code = $response[ "data" ][ "error_code" ];
                $message    = ( $response[ "success" ] )
                                ? "Successfully approved: #{$this->order_id}"
                                : "Error on approving #{$this->order_id} | " . "{$error_code} {$response[ "data" ][ "message" ]}";
            break;

            case "ordercancelation":
                $this->log( "info", "Canceling order FS: #{$this->order_id}" );
                $response   = $this->api->fs_order_cancelation( $this->order_id, self::SELLER );
                $error_code = $response[ "data" ][ "error_code" ];
                $message    = ( $response[ "success" ] )
                                ? "Successfully cancelled: #{$this->order_id}"
                                : "Error on canceling #{$this->order_id} | " . "{$error_code} {$response[ "data" ][ "message" ]}";
            break;
        }

        $this->log( "info", $message );

        return [
            "error_code" => $error_code,
            "success"    => $response[ "success" ],
            "message"    => $message,
        ];
    }

    /**
     * Valid if it is necessary to create sub-orders for the order in question
     *
     * @since   1.0.0
     * @return  void
     */
    public function maybe_split_orders( $parent_order_id, $parent_order )
    {
        $this->log( "info", sprintf( "TRIIBO: New Order #%d created. Init sub order.", $parent_order_id ) );

        if ( $parent_order->get_meta( "has_sub_order" ) == true )
        {
            $args = [
                "post_parent" => $parent_order_id,
                "post_type"   => "shop_order",
                "numberposts" => -1,
                "post_status" => "any",
            ];

            $child_orders = get_children( $args );

            foreach ( $child_orders as $child ) wp_delete_post( $child->ID, true );
        }

        $hubs        = [];
        $seller_id   = null;
        $order_items = $parent_order->get_items();

        foreach ( $order_items as $item )
        {
            $product = $item->get_product();
            $hub     = $product->get_shipping_class();

            if ( strpos( $hub, "fast-shop-" ) !== false )
            {
                $seller_id      = get_post_field( "post_author", $item[ "product_id" ] );
                $hubs[ $hub ][] = $item;
            }
        }

        if ( count( $hubs ) <= 1 )
        {
            $this->log( "info", "TRIIBO: 1 hub only, skipping sub order" );
            return;
        }

        $parent_order->update_meta_data( "has_sub_order", true );
        $parent_order->save();

        $this->log( "info", sprintf( "TRIIBO: Got %s hubs, starting sub order", count( $hubs ) ) );

        foreach ( $hubs as $hub => $hub_products )
        {
            $this->create_sub_order( $parent_order, $seller_id, $hub, $hub_products );
        }
    }

    /**
     * Creates the sub-order
     *
     * @since   1.0.0
     * @return  void|WP_Error
     */
    private function create_sub_order( $parent_order, $seller_id, $hub, $hub_products )
    {
        $this->log( "info", "TRIIBO: Creating sub order for hub: " . $hub );

        $bill_ship = [
            "billing_country",
            "billing_first_name",
            "billing_last_name",
            "billing_company",
            "billing_address_1",
            "billing_address_2",
            "billing_city",
            "billing_state",
            "billing_postcode",
            "billing_email",
            "billing_phone",
            "shipping_country",
            "shipping_first_name",
            "shipping_last_name",
            "shipping_company",
            "shipping_address_1",
            "shipping_address_2",
            "shipping_city",
            "shipping_state",
            "shipping_postcode",
        ];

        try
        {
            $order = new \WC_Order();

            // save billing and shipping address
            foreach ( $bill_ship as $key )
                if ( is_callable( [ $order, "set_{$key}" ] ) )
                    $order->{ "set_{$key}" }( $parent_order->{"get_{$key}"}() );

            // now insert line items
            $this->create_line_items( $order, $hub_products );

            // do shipping
            $this->create_shipping( $order, $parent_order, $hub_products );

            // do tax
            $this->create_taxes( $order, $parent_order, $hub_products );

            // add coupons if any
            $this->create_coupons( $order, $parent_order, $hub_products );

            // save other details
            $order->set_created_via( "triibo-fastshop-orders" );
            $order->set_cart_hash( $parent_order->get_cart_hash() );
            $order->set_customer_id( $parent_order->get_customer_id() );
            $order->set_currency( $parent_order->get_currency() );
            $order->set_prices_include_tax( $parent_order->get_prices_include_tax() );
            $order->set_customer_ip_address( $parent_order->get_customer_ip_address() );
            $order->set_customer_user_agent( $parent_order->get_customer_user_agent() );
            $order->set_customer_note( $parent_order->get_customer_note() );
            $order->set_payment_method( $parent_order->get_payment_method() );
            $order->set_payment_method_title( $parent_order->get_payment_method_title() );
            $order->update_meta_data( "_dokan_vendor_id", $seller_id );

            // finally, let the order re-calculate itself and save
            $order->calculate_totals();

            $order->set_status( $parent_order->get_status() );
            $order->set_parent_id( $parent_order->get_id() );

            $order_id = $order->save();

            $this->log( "info", "TRIIBO: Created sub order : #" . $order_id );

            // update total_sales count for sub-order
            wc_update_total_sales_counts( $order_id );
        }
        catch ( Exception $e )
        {
            return new WP_Error( "dokan-suborder-error", $e->getMessage() );
        }
    }

    /**
     * Creates line items for the sub-order
     *
     * @since   1.0.0
     * @return  void
     */
    private function create_line_items( $order, $products )
    {
        foreach ( $products as $item )
        {
            $product_item = new \WC_Order_Item_Product();

            $product_item->set_name         ( $item->get_name()         );
            $product_item->set_product_id   ( $item->get_product_id()   );
            $product_item->set_variation_id ( $item->get_variation_id() );
            $product_item->set_quantity     ( $item->get_quantity()     );
            $product_item->set_tax_class    ( $item->get_tax_class()    );
            $product_item->set_subtotal     ( $item->get_subtotal()     );
            $product_item->set_subtotal_tax ( $item->get_subtotal_tax() );
            $product_item->set_total_tax    ( $item->get_total_tax()    );
            $product_item->set_total        ( $item->get_total()        );
            $product_item->set_taxes        ( $item->get_taxes()        );

            $metadata     = $item->get_meta_data();

            if ( $metadata )
                foreach ( $metadata as $meta )
                    $product_item->add_meta_data( $meta->key, $meta->value );

            $order->add_item( $product_item );
        }

        $order->save();
    }

    /**
     * Creates shipping for the sub-order
     *
     * @since   1.0.0
     * @return  void
     */
    private function create_shipping( $order, $parent_order, $products )
    {
        $shipping_methods         = $parent_order->get_shipping_methods();

        if ( !$shipping_methods )
        {
            $this->log( "info", "TRIIBO: No shipping method found : Aborting : #" . $order->get_id() );
            return;
        }

        foreach ( $shipping_methods as $key => $shipping_method )
        {
            if ( is_a( $shipping_method, "WC_Order_Item_Shipping" ) )
            {
                $total    = 0;

                $item     = new \WC_Order_Item_Shipping();
                $metadata = $shipping_method->get_meta_data();

                if ( $metadata )
                {
                    foreach ( $metadata as $meta )
                    {
                        if ( $meta->key == "price" )
                        {
                            $data     = $meta->get_data();
                            $new_meta = [ "value" => [] ];

                            foreach ( $products as $product )
                            {
                                $product_id = $product->get_product_id();

                                if ( isset( $data[ "value" ][ $product_id ] ) )
                                {
                                    $value  = $data[ "value" ][ $product_id ];
                                    $total += $value;

                                    $new_meta[ "value" ][ $product_id ] = $value;
                                }
                            }

                            $item->add_meta_data( $meta->key, $new_meta );
                        }
                        else
                        {
                            $item->add_meta_data( $meta->key, $meta->value );
                        }
                    }
                }

                $item->set_props( [
                    "method_title" => $shipping_method->get_name(),
                    "method_id"    => $shipping_method->get_method_id(),
                    "taxes"        => $shipping_method->get_taxes(),
                    "total"        => $total,
                ] );

                $order->add_item( $item );
                $order->set_shipping_total( $shipping_method->get_total() );
                $order->save();
            }
        }
    }

    /**
     * Creates taxes for the sub-order
     *
     * @since   1.0.0
     * @return  void
     */
    private function create_taxes( $order, $parent_order, $products )
    {
        $shipping  = $order->get_items( "shipping" );
        $tax_total = 0;

        foreach ( $products as $item )
            $tax_total += $item->get_total_tax();

        foreach ( $parent_order->get_taxes() as $tax )
        {
            $seller_shipping = reset( $shipping );

            $item = new \WC_Order_Item_Tax();
            $item->set_props( [
                "rate_id"            => $tax->get_rate_id(),
                "label"              => $tax->get_label(),
                "compound"           => $tax->get_compound(),
                "rate_code"          => \WC_Tax::get_rate_code( $tax->get_rate_id() ),
                "tax_total"          => $tax_total,
                "shipping_tax_total" => is_bool( $seller_shipping ) ? "" : $seller_shipping->get_total_tax(),
            ] );

            $order->add_item( $item );
        }

        $order->save();
    }

    /**
     * Creates coupons for the sub-order
     *
     * @since   1.0.0
     * @return  void
     */
    private function create_coupons( $order, $parent_order, $products )
    {
        $used_coupons = $parent_order->get_items( "coupon" );
        $product_ids  = array_map(
            function( $item ) {
                return $item->get_product_id();
            }, $products
        );

        if ( !$used_coupons ) return;

        $seller_id = dokan_get_seller_id_by_order( $order->get_id() );

        if ( !$seller_id ) return;

        foreach ( $used_coupons as $item )
        {
            $coupon = new \WC_Coupon( $item->get_code() );

            if (
                $coupon &&
                !is_wp_error( $coupon ) &&
                ( array_intersect( $product_ids, $coupon->get_product_ids() ) ||
                    apply_filters( "dokan_is_order_have_admin_coupon", false, $coupon, [ $seller_id ], $product_ids )
                )
            ) {
                $new_item = new \WC_Order_Item_Coupon();
                $new_item->set_props( [
                    "code"         => $item->get_code(),
                    "discount"     => $item->get_discount(),
                    "discount_tax" => $item->get_discount_tax(),
                ] );

                $new_item->add_meta_data( "coupon_data", $coupon->get_data() );

                $order->add_item( $new_item );
            }
        }

        $order->save();
    }

    /**
     * Register log
     *
     * @since   2.0.0   Reworked log function.
     * @since   1.0.0
     * @param   string  $data
     * @return  void
     */
    public function log( string $level, string $message, bool $is_error = false )
    {
		$logger  = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();

		$context = [ "source" => $this->id ];

		if ( $is_error || $this->api->env() === true )
			$logger->$level( $message, $context );
	}
}
