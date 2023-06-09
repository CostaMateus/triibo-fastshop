<?php
/**
 * Provide a admin area view for the plugin
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @since      1.0.0
 * @package    Triibo_Fast_Shop
 * @subpackage Triibo_Fast_Shop/Templates
 * @author     Mateus Costa <mateus@costamateus.com.br>
 */
?>

<div class="wrap">
	<h2>Triibo Fast Shop</h2>

	<?php
		$instance = null;
		$shipping = new WC_Shipping_Zones();
		$zones    = $shipping->get_zones();
		$index    = array_key_first( $zones);
		$methods  = $zones[ $index ][ "shipping_methods" ];

		foreach ( $methods as $key => $method )
			if ( $method && $method->id === Triibo_Fast_Shop_Shipping::ID )
				$instance = $method->instance_id;

        $url  = esc_url( admin_url( "admin.php?page=wc-settings&tab=shipping&instance_id={$instance}" ) );
        $text = __( "WC Configurações", Triibo_Fast_Shop_Shipping::DOMAIN );
        $link = "<a href='{$url}' >{$text}</a>";

        echo "<p>{$link}</p>";
    ?>

	<h3>Frete</h3>
	<p> -Cálculo de frete</p>
	<p> -Atualiza preço e estoque quando produtos são colocados no carrinho</p>
	<hr>

	<h3>Pedidos</h3>
	<p> -Pedidos com mais de um HUB de entrega FS, é dividido em sub-pedidos</p>
	<p> -Notifica FS quando há pedidos ou alteração de status</p>
	<br>
	<p style="margin-bottom:0;"                      >Alterado: <b>09/06/23</b></p>
	<p style="text-decoration:line-through;margin:0;">TODOS os pedidos da FS entram como AGUARDANDO.</p>
	<p style="margin:0;"                             >Notificação de alteração quando:</p>
	<ul style="margin-top:0;" >
		<li> - qualquer status ( menos concluido ) mudar para aguardando | pedido é CRIADO na FS</li>
		<li> - status pendente mudar para processando | pedido é CRIADO na FS <small>(new)</small></li>
		<li> - status aguardando mudar para processando | pedido é APROVADO na FS</li>
		<li> - qualquer status ( menos pendente ) mudar para cancelado | pedido é CANCELADO na FS</li>
	</ul>

	<hr>

	<?php settings_errors(); ?>

	<form method="POST" action="options.php">
		<?php
			settings_fields( "triibo_fast_shop_general_settings" );
			do_settings_sections( "triibo_fast_shop_general_settings" );
			submit_button();
		?>
	</form>
</div>