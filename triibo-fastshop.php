<?php
/**
 * Plugin Name:          #Triibo_Fast_Shop
 * Description:          Comunicação entre MKT, Integração e FastShop. Dependências: Triibo API Services e WooCommerce.
 * Author:               Mateus Costa
 * Author URI:           https://costamateus.com.br/
 * Version:              1.0.0
 * Text Domain:          triibo_fast_shop
 * RequiresPHP:          7.4
 * RequiresWP:           5.9
 * WC requires at least: 5.8
 * WC tested up to:      6.3
 *
 * @package Triibo_Fast_Shop
 */
defined( "ABSPATH" ) || exit;

if ( ! in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class Triibo_Fast_Shop
{
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = "1.0.0";

	const DOMAIN  = "triibo_fast_shop";

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;
		return self::$instance;
	}

	private function __construct()
    {
		if ( class_exists( "Triibo_Api_Services" ) )
		{
			if ( class_exists( "WooCommerce" ) )
			{
				// Order Class
				include_once dirname( __FILE__ ) . "/includes/class-triibo-fastshop-orders.php";
				// Shipping Class
				include_once dirname( __FILE__ ) . "/includes/class-triibo-fastshop-shipping.php";

				add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ]        );
				add_filter( "woocommerce_rest_suppress_image_upload_error",       [ $this, "image_upload_error"  ], 10, 4 );

				add_action( "admin_menu",                       [ $this, "add_submenu_page"    ], 11   );
				add_action( "triibo_api_service_add_button",    [ $this, "add_btn"             ], 11   );

				// Order Actions
				add_action( "woocommerce_order_status_changed", [ $this, "order_split"         ], 7, 4 );
				add_action( "woocommerce_order_status_changed", [ $this, "order_status_change" ], 9, 4 );
				// Shipping Actions
				add_filter( "woocommerce_shipping_methods",     [ $this, "shipping_method"     ]       );
				add_action( "woocommerce_after_shipping_rate",  [ $this, "after_shipping_rate" ], 100  );
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
	 */
	public static function woocommerce_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-woocommerce.php";
	}

	/**
	 * Triibo-Api-Service missing notice.
	 */
	public static function triibo_api_services_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Action links.
	 *
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

	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/" . self::DOMAIN . "-settings.php";
	}

	public function add_btn()
	{
		$instance = null;
		$shipping = new WC_Shipping_Zones();
		$zones    = $shipping->get_zones();
		$index    = array_key_first( $zones);
		$methods  = $zones[ $index ][ "shipping_methods" ];

		foreach ( $methods as $key => $method )
			if ( $method && $method->id === Triibo_Fast_Shop_Shipping::ID )
				$instance = $method->instance_id;

		$url  = esc_url( admin_url( "admin.php?page=wc-settings&tab=shipping&instance_id={$instance}" ) );
		$text = __( "Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Fast Shop | {$link}</p>";
	}

	/**
	 * Valid if order needs to be split, based on Fast Shop HUBs
	 */
	public function order_split( $order_id, $old_status, $new_status, $order )
	{
		$items      = $order->get_items();
		$countItems = count( $items );
		$auxCount   = 0;
		$hasFS      = false;
		$onlyFS     = true;

		foreach ( $items as $item )
		{
			$product = $item->get_product();

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

		if ( $auxCount != $countItems ) $onlyFS = false;

		if ( $hasFS && $onlyFS )
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
	 */
	public function order_status_change( $order_id, $old_status, $new_status, $order )
	{
		$subOrder = empty( $order->get_meta( "has_sub_order" ) ) ? false : true;

		if ( !$subOrder )
		{
			$items = $order->get_items();
			$hasFS = false;

			foreach ( $items as $item )
			{
				$product = $item->get_product();
				$hub     = $product->get_shipping_class();

				if ( strpos( $hub, "fast-shop" ) !== false )
				{
					$hasFS = true;
					break;
				}
			}

			if ( $hasFS )
			{
				/**
				 * qlqr_status ( -aguardando ) --> processando
				 * pedido é CRIADO e na sequencia APROVADO na FS
				 */
				if ( $old_status != "on-hold" && $new_status == "processing" )
				{
					$api = new Triibo_Fast_Shop_Orders( $order_id, "pending", "on-hold");
					$api->sendNotification(); // CRIA

					$api->set_process( "on-hold", "processing" );
					$api->sendNotification(); // APROVA
				}
				else
				{
					$api = new Triibo_Fast_Shop_Orders( $order_id, $old_status, $new_status );
					$api->sendNotification();
				}
			}
		}
	}

	/**
	 * Ignore image upload with error
	 */
	public function image_upload_error( $false, $upload, $product_get_id, $images )
	{
		return true;
	}

	/**
	 * Include Fastshop shipping to WooCommerce.
	 *
	 * @param  array $methods Default shipping methods.
	 * @return array
	 */
	public function shipping_method( $methods )
	{
        $methods[ "fastshop-shipping" ] = "Triibo_Fast_Shop_Shipping";

		return $methods;
	}

	/**
	 * Adds delivery forecast after method name.
	 *
	 * @param WC_Shipping_Rate $shipping_method Shipping method data.
	 */
	public function after_shipping_rate( $shipping_method )
	{
		$meta_data = $shipping_method->get_meta_data();
		$total     = isset( $meta_data[ "_delivery_forecast" ] ) ? intval( $meta_data[ "_delivery_forecast" ] ) : 0;

		if ( $total ) echo "<p><small>" . esc_html( sprintf( _n( "Entrega em %d dia útil", "Entrega em %d dias úteis", $total ), $total ) ) . "</small></p>";
	}
}

add_action( "plugins_loaded", array( "Triibo_Fast_Shop", "get_instance" ), 222 );
