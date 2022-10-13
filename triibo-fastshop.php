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
				// include_once dirname( __FILE__ ) . "/includes/assinaturas-api.php";
				// include_once dirname( __FILE__ ) . "/includes/assinaturas-gateway.php";

				add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ] );

				add_action( "admin_menu",                    [ $this, "add_submenu_page" ], 11 );
				add_action( "triibo_api_service_add_button", [ $this, "add_btn"          ], 11 );
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
			"Triibo Fast Shop",
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
        $url  = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
		$text = __( "Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Fast Shop | {$link}</p>";
	}
}

add_action( "plugins_loaded", array( "Triibo_Fast_Shop", "get_instance" ), 222 );
