<?php
/**
 * Fast Shop Shipping.
 *
 * @package Triibo_Fast_Shop/Classes/Shipping
 * @since   1.0.0
 * @version 1.0.0
 */
defined( "ABSPATH" ) || exit;

/**
 * Fastshop shipping method class.
 */
class Triibo_Fast_Shop_Shipping extends WC_Shipping_Method
{
    const ID     = "triibo-fast-shop-shipping";
    const DOMAIN = Triibo_Fast_Shop::DOMAIN . "_shipping";
    const SELLER = "fast_shop";

    /**
     * Instance ID.
     *
     * @since   2.0.0
     * @var     string
     */
    public $id;

    /**
     * API.
     *
     * @since   2.0.0
     * @var     Triibo_Api_Php
     */
    protected $api;

    /**
     * Additional time.
     *
     * @since   2.0.0
     * @var     string
     */
    protected $additional_time;

    /**
     * Shipping classes ID.
     *
     * @since   2.0.0
     * @var     array
     */
    protected $shipping_class_id;

    /**
     * Shows delivery time.
     *
     * @since   2.0.0
     * @var     string
     */
    protected $show_delivery_time;

    /**
     * Initialize the Correios shipping method.
     *
     * @since   1.0.0
     * @param   int $instance_id Shipping zone instance ID.
     * @return  void
     */
    public function __construct( $instance_id = 0 )
    {
        $this->id                 = self::ID;
        $this->api                = new Triibo_Api_Php();

        $this->instance_id        = absint( $instance_id );

        $this->method_title       = __( "Fast Shop",                    self::DOMAIN );
        $this->method_description = __( "Entrega feita pela Fast Shop", self::DOMAIN );

        $this->enabled            = "yes";
        $this->title              = "Fast Shop";

        $this->supports           = [
            "shipping-zones",
            "instance-settings",
        ];

        $this->init();
    }

    /**
     * Init your settings
     *
     * @since   1.0.0
     * @access  public
     * @return  void
     */
    function init()
    {
        // Load the settings API
        // This is part of the settings API.
        // Override the method to add your own settings
        $this->init_form_fields();

        // Define user set variables.
        $this->enabled            = $this->get_option( "enabled"            );
        $this->title              = $this->get_option( "title"              );
        $this->additional_time    = $this->get_option( "additional_time"    );
        $this->show_delivery_time = $this->get_option( "show_delivery_time" );
        $this->shipping_class_id  = $this->get_option( "shipping_class_id", [ "0" ] );

        // Save settings in admin if you have any defined
        add_action( "woocommerce_update_options_shipping_" . $this->id, [ $this, "process_admin_options" ] );
    }

    /**
     * Admin options fields.
     *
     * @since   1.0.0
     * @return  void
     */
    public function init_form_fields()
    {
        $this->instance_form_fields = [
            "enabled"            => [
                "type"        => "checkbox",
                "title"       => __( "Habilitar/Desabilitar",                                                   self::DOMAIN ),
                "label"       => __( "Ativar este método de envio",                                             self::DOMAIN ),
                "default"     => "yes",
            ],
            "title"              => [
                "type"        => "text",
                "title"       => __( "Título",                                                                  self::DOMAIN ),
                "description" => __( "Isto controla o título que o usuário vê durante o checkout.",             self::DOMAIN ),
                "desc_tip"    => true,
                "default"     => $this->method_title,
            ],
            "behavior_options"   => [
                "title"       => __( "Opções de comportamento",                                                 self::DOMAIN ),
                "type"        => "title",
                "default"     => "",
            ],
            "shipping_class_id"  => [
                "type"        => "multiselect",
                "title"       => __( "Classe de entrega",                                                       self::DOMAIN ),
                "description" => __( "Se necessário, selecione uma classe de envio para aplicar neste método.", self::DOMAIN ),
                "desc_tip"    => true,
                "default"     => "",
                "class"       => "wc-enhanced-select",
                "options"     => $this->get_shipping_classes_options(),
            ],
            "show_delivery_time" => [
                "type"        => "checkbox",
                "title"       => __( "Tempo de entrega",                                                        self::DOMAIN ),
                "label"       => __( "Exibir estimativa de entrega",                                            self::DOMAIN ),
                "description" => __( "Exibir o tempo estimado de entrega em dias úteis.",                       self::DOMAIN ),
                "desc_tip"    => true,
                "default"     => "no",
            ],
            "additional_time"    => [
                "type"        => "text",
                "title"       => __( "Dias adicionais",                                                         self::DOMAIN ),
                "description" => __( "Dias úteis adicionais para a estimativa de entrega.",                     self::DOMAIN ),
                "desc_tip"    => true,
                "default"     => "0",
                "placeholder" => "0",
            ],
        ];
    }

    /**
     * Get shipping classes options.
     *
     * @since   1.0.0
     * @return  array
     */
    protected function get_shipping_classes_options()
    {
        $shipping_classes = WC()->shipping->get_shipping_classes();
        $options          = [
            "-1" => __( "Qualquer classe", self::DOMAIN ),
            "0"  => __( "Nenhuma classe",  self::DOMAIN ),
        ];

        if ( !empty( $shipping_classes ) ) $options += wp_list_pluck( $shipping_classes, "name", "term_id" );

        return $options;
    }

    /**
     * Check if package uses only the selected shipping class.
     *
     * @since   1.0.0
     * @param   array $package Cart package.
     * @return  bool
     */
    protected function has_only_selected_shipping_class( $package )
    {
        $only_selected = true;

        if ( in_array( -1, $this->shipping_class_id ) ) return $only_selected;

        foreach ( $package[ "contents" ] as $item_id => $values )
        {
            $product = $values[ "data"     ];
            $qty     = $values[ "quantity" ];

            if ( $qty > 0 && $product->needs_shipping() )
            {
                if ( !in_array( $product->get_shipping_class_id(), $this->shipping_class_id ) )
                {
                    $only_selected = false;
                    break;
                }
            }
        }

        return $only_selected;
    }

    /**
     * calculate_shipping function.
     *
     * @since   1.0.0
     * @access  public
     * @param   mixed   $package
     * @return  void
     */
    public function calculate_shipping( $package = [] )
    {
        if ( "" === $package[ "destination" ][ "postcode" ] || "BR" !== $package[ "destination" ][ "country" ] ) return;

        $postcode = strlen( $package[ "destination" ][ "postcode" ] );
        if ( $postcode < 8 || $postcode > 9 ) return;

        if ( !$this->has_only_selected_shipping_class( $package ) ) return;

        $shipping = $this->get_rate( $package );

        if ( $shipping[ "error_code" ] !== null )
        {
			$this->log( "error", json_encode( [
                "function" => "calculate_shipping_error",
                "package"  => $package,
                "return"   => $shipping,
            ] ), true );

            return;
        }

        $meta  = [];
        $costs = [];
        $time  = 0;

        foreach ( $shipping[ "data" ] as $data )
        {
            if ( $data[ "error_code" ] !== null )
                return;

            $item         = $data[ "data"       ];
            $id           = $item[ "product_id" ];

            $costs[ $id ] = ( float ) $item[ "delivery_price" ];
            $time         = ( $item[ "delivery_time" ] > $time ) ? $item[ "delivery_time" ] : $time;
        }

        if ( "yes" === $this->show_delivery_time )
        {
            $meta = [
                "_delivery_forecast" => intval( $time ) + intval( $this->additional_time ),
            ];
        }

        $meta[ "price"   ] = $costs;
        $meta[ "company" ] = "Fast Shop";

        $rate = [
            "id"        => $this->id . $this->instance_id,
            "label"     => $this->title,
            "cost"      => $costs,
            "calc_tax"  => "per_item",
            "meta_data" => $meta,
            "package"   => $package
        ];

        $this->add_rate( $rate );
    }

	/**
	 * Get shipping rate.
	 *
     * @since   2.0.0 New api FS/Vtex
     * @since   1.6.2 Added more FS HUBs (83)
     * @since   1.6.0 Added more FS HUBs (97)
     * @since   1.5.0 Added more FS HUBs (3E)
     * @since   1.4.0 Added more FS HUBs (19, 74)
     * @since   1.3.0 Added more FS HUBs (4E, 25, 60, 61)
     * @since   1.0.0
	 * @param   array $package Cart package.
	 * @return  SimpleXMLElement|null
	 */
	protected function get_rate( $package )
    {
        $body     = $this->process_package( $package );
        $response = $this->api->freight_calculation( $body, self::SELLER );

        if ( $response[ "code" ] !== 200 )
        {
            $this->log( "critical", print_r( $response, true ), true );

            $response[ "error_code" ] = $response[ "code" ];

            return $response;
        }

        $shipping = $response[ "data" ];

        foreach ( $shipping[ "data" ] as $sku => &$data )
        {
            $product_id = wc_get_product_id_by_sku( $sku );
            $product    = wc_get_product( $product_id );

            $code       = $data[ "error_code" ];
            $item       = $data[ "data"       ];

            if ( get_home_url() === "http://shopkeeper.local" )
            {
                $fs_undefined = 153;
                $fs_default   = 154;
                $fs_normal    = 155;
            }
            else
            {
                $fs_undefined = $this->api->env() ? 4744 : 4744;
                $fs_default   = $this->api->env() ? 3970 : 3970;
                $fs_normal    = $this->api->env() ? 4824 : 4824;
            }

            $status     = $item[ "availability" ] ? "instock" : "outofstock";
            $product->set_stock_status( $status );

            if ( in_array( $code, [ 4000, 4001 ] ) )
            {
                $product->update_meta_data( "_fastshop_hub", "-" );
                $product->set_shipping_class_id( $fs_default );
            }
            else
            {
                $hub = !empty( $item[ "hub" ] ) ? $item[ "hub" ] : "-";
                $product->update_meta_data( "_fastshop_hub", $hub );

                if ( $hub != "-" )
                {
                    $hub_map = [ "normal" => $fs_normal ];

                    $hub_id  = $hub_map[ $hub ] ?? $fs_undefined;

                    $product->set_shipping_class_id( $hub_id );
                }
                else
                {
                    $product->set_shipping_class_id( $fs_default );
                }
            }

            if ( isset( $item[ "regular_price" ] ) && isset( $item[ "sale_price" ] ) )
            {
                $product->set_regular_price( $item[ "regular_price" ] );
                $product->set_sale_price( $item[ "sale_price" ] );
            }

            $data[ "data" ][ "product_id" ] = $product_id;

            $product->save();
        }

		return $shipping;
	}

    /**
     * Process the package
     *
     * @since   2.0.0 New api FS/Vtex
     * @since   1.0.0
     * @param   array $package
     * @return  array
     */
    private function process_package( $package )
    {
        $postalCode = $package[ "destination" ][ "postcode" ];
        $items      = [];

        foreach ( $package[ "contents" ] as $content )
        {
            $product       = wc_get_product( $content[ "product_id" ] );
            $fs_product_id = $product->get_meta( "_fs_product_id" );

            $items[]       = [
                "sku"        => $product->get_sku(),
                "product_id" => $fs_product_id,
                "quantity"   => $content[ "quantity" ]
            ];
        }

        return [
            "postalCode" => $postalCode,
            "items"      => $items
        ];
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
