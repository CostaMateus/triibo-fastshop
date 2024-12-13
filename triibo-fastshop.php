<?php
/**
 * Plugin Name: 			#Triibo_Fast_Shop
 * Plugin URI: 				https://triibo.com.br
 * Description: 			Comunicação entre MKT, Integração e FastShop. Dependências: Triibo API Services e WooCommerce.
 * Author: 					Mateus Costa
 * Author URI: 				https://costamateus.com.br/
 * Version: 				2.0.0
 * Text Domain: 			triibo_fast_shop
 * Requires at least: 		6.4
 * Requires PHP: 			7.4
 * WC requires at least: 	8.0
 * WC tested up to: 		8.2
 *
 * @package 	Triibo_Fast_Shop
 * @version 	2.0.0
 */
defined( "ABSPATH" ) || exit;

if ( ! in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class Triibo_Fast_Shop
{
	/**
	 * Plugin version.
	 *
     * @since   1.0.0
	 * @var 	string
	 */
	const VERSION = "2.0.0";

	/**
	 * Plugin domain.
	 *
     * @since   1.0.0
	 * @var 	string
	 */
	const DOMAIN  = "triibo_fast_shop";

	/**
	 * Seller ID.
	 *
     * @since   2.0.0
	 * @var 	string
	 */
    const SELLER = "fast_shop";

	/**
	 * Instance of this class.
	 *
     * @since   1.0.0
	 * @var 	object
	 */
	protected static $instance = null;

	/**
	 * Return an instance of this class.
	 *
     * @since   1.0.0
	 * @return 	object A single instance of this class.
	 */
	public static function get_instance()
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 	1.7.0 	Added HPOS compatibility declaration
	 * @since 	1.2.0 	Added plugin change history
	 * @since 	1.0.0
	 */
	private function __construct()
    {
		if ( class_exists( "Triibo_Api_Services" ) )
		{
			if ( class_exists( "WooCommerce" ) )
			{
				/**
				 * @since 1.7.0
				 */
				add_action( "before_woocommerce_init", [ $this, "setup_hpos_compatibility" ] );

				// Order Class
				include_once dirname( __FILE__ ) . "/includes/class-triibo-fastshop-orders.php";
				// Shipping Class
				include_once dirname( __FILE__ ) . "/includes/class-triibo-fastshop-shipping.php";

				add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ]        );
				add_filter( "woocommerce_rest_suppress_image_upload_error",       [ $this, "image_upload_error"  ], 10, 4 );

				/**
				 * @since 1.2.0
				 */
				add_filter( "plugin_row_meta",                                    [ $this, "plugin_row_meta"     ], 10, 3 );

				/**
				 * @since 2.0.0
				 */
				add_action( "woocommerce_rest_prepare_shop_order_object",         [ $this, "add_vtex_meta_data"  ], 10, 3 );

				add_action( "admin_menu",                         [ $this, "add_submenu_page"         ], 11    );
				add_action( "triibo_api_service_add_button",      [ $this, "add_btn"                  ], 11    );
				add_action( "triibo_api_service_list_plugin_php", [ $this, "add_info_dependency"      ],       );

				// Update stock when added to cart
				add_action( "woocommerce_add_to_cart",            [ $this, "add_to_cart_update_stock" ], 10, 6 );

				// Order Actions
				add_action( "woocommerce_order_status_changed",   [ $this, "order_split"              ], 7, 4  );
				add_action( "woocommerce_order_status_changed",   [ $this, "order_status_change"      ], 9, 4  );
				add_action( "admin_init",                         [ $this, "order_notification"       ]        );
				// Shipping Actions
				add_filter( "woocommerce_shipping_methods",       [ $this, "shipping_method"          ]        );

				// Order Box Actions
				add_action( "woocommerce_order_actions",                         [ $this, "add_order_box_action"     ] );
				add_action( "woocommerce_order_action_wc_fastshop_order_action", [ $this, "process_order_box_action" ] );
			}
			else
			{
				add_action( "admin_notices", [ $this, "woocommerce_missing_notice" ] );
			}
		}
		else
		{
			add_action( "admin_notices", [ $this, "triibo_api_services_missing_notice" ] );
		}
	}

	/**
	 * WooCommerce missing notice.
	 *
     * @since   1.0.0
     * @return  void
	 */
	public static function woocommerce_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-woocommerce.php";
	}

	/**
	 * Triibo-Api-Service missing notice.
	 *
     * @since   1.0.0
     * @return  void
	 */
	public static function triibo_api_services_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Setup WooCommerce HPOS compatibility.
	 *
	 * @since 	1.7.0
	 * @return 	void
	 */
	public function setup_hpos_compatibility() : void
	{
		if ( defined( "WC_VERSION" ) && version_compare( WC_VERSION, "7.1", "<" ) )
			return;

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) )
		{
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				"custom_order_tables",
				__FILE__,
				true
			);
		}
	}

	/**
	 * Action links.
	 *
     * @since   1.0.0
	 * @param 	array $links
	 * @return 	array
	 */
	public static function plugin_action_links( $links )
    {
        $url            = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
        $text           = __( "Configurações", self::DOMAIN );
		$plugin_links   = [];
		$plugin_links[] = "<a href='{$url}' >{$text}</a>";

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Add submenu page
	 *
     * @since   1.0.0
	 * @return 	void
	 */
	public function add_submenu_page()
	{
		add_submenu_page(
			Triibo_Api_Services::get_name(),
			"Triibo Fast Shop",
			"Fast Shop",
			"manage_options",
			self::DOMAIN . "-settings",
			[ $this, "display_admin_menu_settings" ]
		);
	}

	/**
	 * Display erros settings
	 *
     * @since   1.0.0
	 * @return 	void
	 */
	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/" . self::DOMAIN . "-settings.php";
	}

	/**
	 * Add btn link
	 *
     * @since   1.0.0
	 * @return 	void
	 */
	public function add_btn()
	{
		$instance = null;
		$shipping = new WC_Shipping_Zones();
		$zones    = $shipping->get_zones();
		$index    = array_key_first( $zones );
		$methods  = $zones[ $index ][ "shipping_methods" ];

		foreach ( $methods as $method )
			if ( $method && $method->id === Triibo_Fast_Shop_Shipping::ID )
				$instance = $method->instance_id;

		$url  = esc_url( admin_url( "admin.php?page=wc-settings&tab=shipping&instance_id={$instance}" ) );
		$text = __( "WC Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

        $url2  = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
        $text2 = __( "Configurações", self::DOMAIN );
		$link2 = "<a href='{$url2}' >{$text2}</a>";

		echo "<p>Triibo Fast Shop | {$link} | {$link2}</p>";
	}

	/**
	 * Add info dependency
	 *
     * @since   1.0.0
	 * @return 	void
	 */
	public function add_info_dependency()
	{
		echo "<p> - Triibo Fast Shop</p>";
	}

    /**
     * Update stock when added to cart
	 *
	 * @since 	2.0.1 	Fix to use '_fs_product_id' metadata instead of 'SKU'.
     * @since   1.0.0
     * @return  void
     */
	public function add_to_cart_update_stock( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data )
	{
        $product = wc_get_product( $product_id );
        $sku     = $product->get_meta( "_fs_product_id" );

        if ( $sku )
        {
			try
			{
				$api = new Triibo_Api_Php();
				$api->update_sku( "{$product_id}_{$sku}", self::SELLER );
			}
			catch ( Exception $e )
			{
				$log = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
				$log->add( "triibo-fast-shop-shipping", $e->getMessage() );
			}
        }

        return;
	}

	/**
	 * Validates if the order is only from FastShop
	 *
     * @since   1.0.0
	 * @return 	bool
	 */
	private function is_only_fs( $order )
	{
		$items      = $order->get_items();
		$countItems = count( $items );
		$auxCount   = 0;
		$hasFS      = false;
		$onlyFS     = true;

		foreach ( $items as $item )
		{
			$product = $item->get_product();

			if ( is_a( $product, "WC_Product" ) )
			{
				if ( class_exists( "WC_Subscriptions_Product" ) &&
					WC_Subscriptions_Product::is_subscription( $product ) )
				{
					$hasFS  = false;
					$onlyFS = false;
					break;
				}

				$hub     = $product->get_shipping_class();

				if ( strpos( $hub, "fast-shop" ) !== false )
				{
					$auxCount++;
					$hasFS = true;
				}
			}
		}

		if ( $auxCount != $countItems ) $onlyFS = false;

		return ( $hasFS == true && $onlyFS == true );
	}

	/**
	 * validates if the order is a suborder from FastShop
	 *
     * @since   1.0.0
	 * @return 	bool
	 */
	private function is_suborder_fs( $order )
	{
		$subOrder = empty( $order->get_meta( "has_sub_order" ) ) ? false : true;

		if ( !$subOrder )
		{
			$items = $order->get_items();

			foreach ( $items as $item )
			{
				$product = $item->get_product();
				$hub     = $product->get_shipping_class();

				if ( strpos( $hub, "fast-shop" ) !== false ) return true;
			}
		}

		return false;
	}

	/**
	 * Valid if order needs to be split, based on Fast Shop HUBs
	 *
     * @since   1.0.0
     * @return  void
	 */
	public function order_split( $order_id, $old_status, $new_status, $order )
	{
		if ( $this->is_only_fs( $order ) )
		{
			if ( $old_status == "pending" )
			{
				// Remove the hook to prevent recursive callas.
				remove_action( "woocommerce_order_status_changed", [ $this, "order_split" ], 7 );

				// Split the order.
				$triibo = new Triibo_Fast_Shop_Orders();
				$triibo->maybe_split_orders( $order_id, $order );

				// Add the hook back.
				add_action( "woocommerce_order_status_changed", [ $this, "order_split" ], 7, 4 );
			}
		}
	}

	/**
	 * Valid if it is a Fast Shop order and sends notification for integration
	 *
     * @since   1.0.0
     * @return  void
	 */
	public function order_status_change( $order_id, $old_status, $new_status, $order )
	{
		if ( $this->is_suborder_fs( $order ) )
		{
			if ( $old_status == "on-hold" && $new_status == "processing" ) return;

			$api      = new Triibo_Fast_Shop_Orders( $order_id, $old_status, $new_status );
			$response = $api->send_notification();

			/**
			 * Add note when order cancelation fails.
			 *
			 * @since 1.1.0
			 */
			if ( $api->get_type() == "ordercancelation" && !$response[ "success" ] )
			{
				switch ( $response[ "errorCode" ] )
				{
					case "4001":
						$message = "FALHA ao cancelar pedido na FASTSHOP.";
					break;

					case "4002":
						$message = "Pedido sem ID FASTSHOP, não encontrado!";
					break;
				}

				$order->add_order_note( $message );
			}
		}
	}

	/**
	 * Register and build the plugin fields
	 *
	 * @since 	1.0.0
     * @return  void
	 */
	public function order_notification()
	{
		$prefix    = self::DOMAIN . "_";
		$sufix     = "_section";

		$page      = $prefix . "general_settings";

		$fs_fields = [
			[
				"key"   => "fs_status",
				"title" => "Ativar / Desativar",
				"label" => "Ativar Notificação de pedido para Fast Shop",
				"value" => false,
			],
		];

		register_setting( $page, $prefix . "options" );

		$this->add_allowed_options( $prefix, $page, [ $fs_fields ] );

		add_settings_section( $prefix . "fs" . $sufix, "Notificação de pedidos", [ $this, "message_section" ], $page );

		foreach ( $fs_fields as $index => $field )
		{
			$this->add_settings_field( $prefix, $sufix, $page, "fs", $index, $field );
			$this->register_settings( $prefix, $page, $field );
		}
	}

	/**
	 * Adds plugin fields to allowed options
	 *
	 * @since 	1.0.0
	 * @param 	string $page
	 * @param 	array $data
	 * @return  void
	 */
	public function add_allowed_options( $prefix, $page, $data )
	{
		$new_options = [ $page => [] ];

		foreach ( $data as $api )
			foreach ( $api as $field )
				$new_options[ $page ][] = $prefix . $field[ "key" ];

		add_allowed_options( $new_options );
	}

	/**
	 * Echos out any content at the top of the section (between heading and fields).
	 *
	 * @since 	1.0.0
	 * @param 	array $args
	 * @return 	void
	 */
	public function message_section( $args )
	{
		echo "<p>Configurações</p>";
	}

	/**
	 * Configure each plugin field
	 *
	 * @since 	1.0.0
	 * @param 	string $section
	 * @param 	string $index
	 * @param 	array $field
	 */
	public function add_settings_field( $prefix, $sufix, $page, $section, $index, $field )
	{
		$id      = $prefix . $field[ "key" ];
		$title   = $field[ "title" ];
		$section = $prefix . $section . $sufix;

		add_settings_field(
            $id, $title, [ $this, "render_settings_field" ], $page, $section,
			[
				"type"             => "input",
				"subtype"          => ( in_array( $index, [ 0, 1 ] ) ) ? "checkbox"        : "text",
				"label"            => ( in_array( $index, [ 0, 1 ] ) ) ? $field[ "label" ] : "",
				"value"            => ( $index == 1 ) ? $field[ "value" ] : "",
				"id"               => $id,
				"name"             => $id,
				"required"         => true,
				"get_options_list" => "",
				"value_type"       => "normal",
				"wp_data"          => "option"
			]
		);
	}

	/**
	 * Renders each field according to the configured args
	 *
	 * @since 	1.0.0
	 * @param 	array $args
	 */
	public function render_settings_field( $args )
	{
		if ( $args[ "wp_data" ] == "option" )
		{
			$wp_data_value = get_option( $args[ "name" ] );
		}
		elseif ( $args[ "wp_data" ] == "post_meta" )
		{
			$wp_data_value = get_post_meta( $args[ "post_id" ], $args[ "name" ], true );
		}

		switch ( $args[ "type" ] )
		{
			case "input":
				$status = 0;

				if ( strpos( $args[ "id" ], "fs" ) !== false ) $status = get_option( self::DOMAIN . "_fs_status" );

				$value = ( $args[ "value_type" ] == "serialized" ) ? serialize( $wp_data_value ) : $wp_data_value;

				if ( $args[ "subtype" ] != "checkbox")
				{
					$prependStart = ( isset( $args[ "prepend_value" ] ) ) ? "<div class='input-prepend'> <span class='add-on'>{$args[ "prepend_value" ]}</span>" : "";
					$prependEnd   = ( isset( $args[ "prepend_value" ] ) ) ? "</div>"                   : "";
					$step         = ( isset( $args[ "step"          ] ) ) ? "step='{$args[ "step" ]}'" : "";
					$min          = ( isset( $args[ "min"           ] ) ) ? "min='{$args[ "min" ]}'"   : "";
					$max          = ( isset( $args[ "max"           ] ) ) ? "max='{$args[ "max" ]}'"   : "";
					$required     = ( $args[ "required" ] && $status    ) ? "required='required'"      : "";

					if ( isset( $args[ "disabled" ] ) )
					{
						// hide the actual input bc if it was just a disabled input the info saved in the database would be wrong - bc it would pass empty values and wipe the actual information
						echo $prependStart . "
							<input type='{$args[ "subtype" ]}' id='{$args[ "id" ]}_disabled' {$step} {$max} {$min} name='{$args[ "name" ]}_disabled' disabled value='" . esc_attr( $value ) . "' />
							<input type='hidden' id='{$args[ "id" ]}' {$step} {$max} {$min} name='{$args[ "name" ]}' value='" . esc_attr( $value ) . "' />" . $prependEnd;
					}
					else
					{
						echo $prependStart . "
							<input type='{$args[ "subtype" ]}' id='{$args[ "id" ]}' {$required} {$step} {$max} {$min} name='{$args[ "name" ]}' value='" . esc_attr( $value ) . "' />" . $prependEnd;
					}
				}
				else
				{
					$checked = ( $value ) ? "checked" : "";
					echo "<input type='{$args[ "subtype" ]}' id='{$args[ "id" ]}' name='{$args[ "name" ]}' {$checked} />";
					echo "<label for='{$args[ "id" ]}' >{$args[ "label" ]}</label>";
				}
			break;

			// default:
			// 	# code...
			// break;
		}
	}

	/**
	 * Register each plugin field
	 *
	 * @since 	1.0.0
	 * @param 	array $field
	 */
	public function register_settings( $prefix, $page, $field )
	{
		$name = $prefix . $field[ "key" ];

		register_setting( $page, $name );
	}

	/**
	 * Ignore image upload with error
	 *
     * @since   1.0.0
	 * @return 	bool
	 */
	public function image_upload_error( $false, $upload, $product_get_id, $images )
	{
		return true;
	}

	/**
	 * Add link to changelog modal
	 *
	 * @since 	1.2.0
	 * @param 	array 	$plugin_meta
	 * @param 	string 	$plugin_file
	 * @param 	array 	$plugin_data
	 * @return 	array
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data )
	{
		$path = path_join( WP_PLUGIN_DIR, $plugin_file );

		if ( DIRECTORY_SEPARATOR == "\\" )
			$path = str_replace( "/", "\\", $path );

        if ( __FILE__ === $path )
		{
            $url = plugins_url( "readme.txt", __FILE__ );

            $plugin_meta[] = sprintf(
                "<a href='%s' class='thickbox open-plugin-details-modal' aria-label='%s' data-title='%s'>%s</a>",
                add_query_arg( "TB_iframe", "true", $url ),
                esc_attr( sprintf( __( "More information about %s" ), $plugin_data[ "Name" ] ) ),
                esc_attr( $plugin_data[ "Name" ] ),
                __( "Histórico de alterações" )
            );
        }

        return $plugin_meta;
	}

	/**
	 * Include Fastshop shipping to WooCommerce.
	 *
     * @since   1.0.0
	 * @param  	array $methods Default shipping methods.
	 * @return 	array
	 */
	public function shipping_method( $methods )
	{
        $methods[ "fastshop-shipping" ] = "Triibo_Fast_Shop_Shipping";

		return $methods;
	}

	/**
	 * Add custom action to order box.
	 *
     * @since   1.0.0
	 * @param 	array $actions
     * @return  array
	 */
	public function add_order_box_action( $actions )
	{
		global $theorder;

		if ( $this->is_only_fs( $theorder ) && $theorder->has_status( "processing" ) && !$theorder->get_meta( "_fastshop_order_approved", true ) )
		{
			$actions[ "wc_fastshop_order_action" ] = __( "Aprovar na Fast Shop", self::DOMAIN );

			return $actions;
		}

		return $actions;
	}

	/**
	 * Process custom action order box.
	 *
     * @since   1.0.0
     * @return  void
	 */
	public function process_order_box_action( $order )
	{
		$api      = new Triibo_Fast_Shop_Orders( $order->get_id(), "on-hold", "processing" );
		$response = $api->send_notification();

		if ( $response[ "success" ] )
		{
			$user_name =  wp_get_current_user()->display_name;

			/**
			 * log when manual approval happens
			 *
			 * @since 	1.6.1
			 */
			$api->log( "info", "Manually approved by: {$user_name}" );

			$order->add_order_note( "Pedido aprovado manualmente na FS por: {$user_name}" );
		}
		else
		{
			$order->add_order_note( $response[ "message" ] );
		}
	}

	/**
	 * Added FS product id to the API response data.
	 *
     * @since   2.0.0
	 * @param 	object $response
	 * @param 	object $post
	 * @param 	object $request
	 * @return 	object
	 */
	public function add_vtex_meta_data( $response, $post, $request )
	{
		foreach ( $response->data[ "line_items" ] as $index => &$item )
		{
            $product = wc_get_product( $item[ "product_id" ] );

			if ( $product )
			{
				$meta    = $product->get_meta( "_fs_product_id" );

				$item[ "meta_data" ][] = [
					"key"   => "_fs_product_id",
					"value" => $meta
				];
			}
		}

		return $response;
	}
}

add_action( "plugins_loaded", array( "Triibo_Fast_Shop", "get_instance" ), 222 );
