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
    const ID                      = "fastshop-shipping";
    const DOMAIN                  = Triibo_Fast_Shop::DOMAIN . "_shipping";
    protected $api                = null;
    protected $log                = null;
    protected $additional_time    = null;
    protected $shipping_class_id  = null;
    protected $show_delivery_time = null;

    /**
     * Initialize the Correios shipping method.
     *
     * @since   1.0.0
     * @param   int $instance_id Shipping zone instance ID.
     * @return  void
     */
    public function __construct( $instance_id = 0 )
    {
        $this->api                = new Triibo_Api_Php();
        $this->log                = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();

        $this->instance_id        = absint( $instance_id );

        $this->id                 = self::ID;
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
        $this->enabled            = $this->get_option( "enabled"                );
        $this->title              = $this->get_option( "title"                  );
        $this->shipping_class_id  = $this->get_option( "shipping_class_id", "0" );
        $this->show_delivery_time = $this->get_option( "show_delivery_time"     );
        $this->additional_time    = $this->get_option( "additional_time"        );

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
            $product = $values[ "data" ];
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
     * @param   mixed $package
     * @return  void
     */
    public function calculate_shipping( $package = [] )
    {
        // Check if valid to be calculeted
        if ( "" === $package[ "destination" ][ "postcode" ] || "BR" !== $package[ "destination" ][ "country" ] ) return;

        // Check if complete postcode
        $postcode = strlen($package[ "destination" ][ "postcode" ] );
        if ( $postcode < 8 || $postcode > 9 ) return;

        // Check for shipping classes.
        if ( ! $this->has_only_selected_shipping_class( $package ) ) return;

        // executa a consulta a api
        $shipping = $this->get_rate( $package );

        if ( $shipping[ "errorCode" ] )
        {
            $this->log( "calculate_shipping_error: " . json_encode( $shipping ) );
            return;
        }

        $meta  = [];
        $costs = [];
        $time  = 0;

        foreach ( $shipping[ "data" ] as $data )
        {
            if ( $data[ "errorCode" ] ) return;

            $item         = $data[ "data" ];
            $id           = $item[ "product_id" ];
            $costs[ $id ] = ( float ) $item[ "deliveryPrice" ];
            $time         = ( $item[ "deliveryTime" ] > $time ) ? $item[ "deliveryTime" ] : $time;
        }

        // Display delivery
        if ( "yes" === $this->show_delivery_time )
        {
            $meta = [
                "_delivery_forecast" => intval( $time ) + intval( $this->additional_time ),
            ];
        }

        $meta[ "price"   ] = $costs;
        $meta[ "company" ] = "Fast Shop";

        // Create the rate
        $rate = [
            "id"        => $this->id . $this->instance_id,
            "label"     => $this->title,
            "cost"      => $costs,
            "calc_tax"  => "per_item",
            "meta_data" => $meta,
            "package"   => $package
        ];

        // Register the rate
        $this->add_rate( $rate );
    }

	/**
	 * Get shipping rate.
	 *
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
        $shipping = $this->api->fs_freight_calculation( $body )[ "data" ];

        $this->log( json_encode( $shipping ) );

        foreach ( $shipping[ "data" ] as $sku => &$data )
        {
            $id      = wc_get_product_id_by_sku( $sku );
            $product = wc_get_product( $id );
            $data[ "data" ][ "product_id" ] = $id;

            $code    = $data[ "errorCode" ];
            $item    = $data[ "data"      ];

            $fs_undf = $this->api->env() ? 1    : 4744;

            $fs_     = $this->api->env() ? 4453 : 3970;
            $fs_05   = $this->api->env() ? 4454 : 4309;
            $fs_14   = $this->api->env() ? 4451 : 4310;
            $fs_33   = $this->api->env() ? 4345 : 4311;

            $fs_4e   = $this->api->env() ? 1    : 4740;
            $fs_25   = $this->api->env() ? 1    : 4741;
            $fs_60   = $this->api->env() ? 1    : 4742;
            $fs_61   = $this->api->env() ? 1    : 4743;

            $fs_19   = $this->api->env() ? 1    : 4745;
            $fs_74   = $this->api->env() ? 1    : 4746;

            $fs_3e   = $this->api->env() ? 1    : 4747;

            $fs_97   = $this->api->env() ? 1    : 4759;

            $fs_83   = $this->api->env() ? 1    : 4776;

            if ( in_array( $code, [ 4001, 4002 ] ) )
            {
                $product->set_stock_quantity( 0 );
                $product->set_stock_status( "outofstock" );
                $product->update_meta_data( "_fastshop_hub", "-" );
                $product->set_shipping_class_id( $fs_ );
            }
            else if ( $code == 4000 )
            {
                $stock   = $item[ "stock" ];
                $product->set_stock_quantity( $stock );

                $status  = ( $stock > 0 ) ? "instock" : "outofstock";
                $product->set_stock_status( $status );

                $product->update_meta_data( "_fastshop_hub", "-" );
                $product->set_shipping_class_id( $fs_ );
            }
            else
            {
                $stock   = $item[ "stock" ];
                $product->set_stock_quantity( $stock );

                $status  = ( $stock > 0 ) ? "instock" : "outofstock";
                $product->set_stock_status( $status );

                $hub     = !empty( $item[ "hub" ] ) ? $item[ "hub" ] : "-";
                $product->update_meta_data( "_fastshop_hub", $hub );

                if ( $hub != "-" )
                {
                    $last_p  = explode( "_", $hub )[ 2 ];

                    $hub_map = [
                        "05" => $fs_05,
                        "14" => $fs_14,
                        "33" => $fs_33,
                        "4E" => $fs_4e,
                        "25" => $fs_25,
                        "60" => $fs_60,
                        "61" => $fs_61,
                        "19" => $fs_19,
                        "74" => $fs_74,
                        "3E" => $fs_3e,
                        "97" => $fs_97,
                        "83" => $fs_83
                    ];

                    $id      = $hub_map[ strtoupper( $last_p ) ] ?? $fs_undf;

                    $product->set_shipping_class_id( $id );
                }
                else
                {
                    $product->set_shipping_class_id( $fs_ );
                }
            }

            if ( isset( $item[ "regular_price" ] ) && isset( $item[ "sale_price" ] ) )
            {
                $product->set_regular_price( $item[ "regular_price" ] );
                $product->set_sale_price( $item[ "sale_price" ] );
            }

            $product->save();
        }

		return $shipping;
	}

    /**
     * Process the package
     *
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
            $product = wc_get_product( $content[ "product_id" ] );
            $items[] = [
                "sku"      => $product->get_sku(),
                "quantity" => $content["quantity"]
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
     * @since   1.0.0
     * @param   string $data
     * @return  void
     */
    public function log( string $data )
    {
        $this->log->add( self::DOMAIN, $data );
    }
}
