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

	<h3>Frete</h3>
	<p> -Cálculo de frete</p>
	<p> -Atualiza preço e estoque quando produtos são colocados no carrinho</p>
	<hr>

	<h3>Pedidos</h3>
	<p> -Pedidos com mais de um HUB de entrega FS, é dividido em sub-pedidos</p>
	<p> -Notifica FS quando há pedidos ou alteração de status</p>
	<br>
	<p>
		Notificação de alteração quando:
		<ul>
			<li> - qualquer status ( menos processando e concluido ) muda para aguardando | pedido é criado na FS</li>
			<li> - status aguardando muda para processando | pedido é aprovado na FS</li>
			<li> - qualquer status ( menos pendente ) muda para cancelado | pedido é cancelado na FS</li>
			<li> - qualquer status ( menos aguardando ) muda para processando | pedido é criado e em seguida é aprovado na FS</li>
		</ul>
	</p>
</div>