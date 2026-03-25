<?php
/**
 * WooCommerce Payment Gateway — Lightning Network via LNURL-pay.
 */

defined('ABSPATH') || exit;

class WLF_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'lightning_fedi';
        $this->icon               = WLF_PLUGIN_URL . 'assets/img/lightning-icon.svg';
        $this->has_fields         = false;
        $this->method_title       = 'Lightning Network ⚡';
        $this->method_description = 'Aceptá pagos en Bitcoin vía Lightning Network. Compatible con Fedi, Wallet of Satoshi, Phoenix, Muun y cualquier wallet Lightning.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'Pagar con Lightning ⚡');
        $this->description = $this->get_option('description', 'Pagá en Bitcoin usando cualquier wallet Lightning. Instantáneo y sin comisiones.');
        $this->ln_address  = $this->get_option('ln_address', '');
        $this->currency    = $this->get_option('store_currency', 'ARS');
        $this->prefix      = $this->get_option('order_prefix', 'Spark101');
        $this->expiry      = (int) $this->get_option('invoice_expiry', 15);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_api_wlf_callback', [$this, 'handle_callback']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/Desactivar',
                'type'    => 'checkbox',
                'label'   => 'Activar Lightning Network Gateway',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Lo que ve el cliente en el checkout.',
                'default'     => 'Pagar con Lightning ⚡',
            ],
            'description' => [
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Descripción del método de pago.',
                'default'     => 'Pagá en Bitcoin usando cualquier wallet Lightning. Instantáneo y sin comisiones.',
            ],
            'ln_address' => [
                'title'       => 'Lightning Address',
                'type'        => 'text',
                'description' => 'Tu Lightning Address (ej: cami@lacrypta.ar) o LNURL.',
                'placeholder' => 'user@domain.com',
            ],
            'store_currency' => [
                'title'       => 'Moneda de la tienda',
                'type'        => 'select',
                'description' => 'Moneda para la conversión a sats.',
                'default'     => 'ARS',
                'options'     => [
                    'ARS' => 'Peso Argentino (ARS)',
                    'USD' => 'Dólar (USD)',
                    'EUR' => 'Euro (EUR)',
                    'BRL' => 'Real (BRL)',
                    'BTC' => 'Bitcoin (BTC)',
                ],
            ],
            'order_prefix' => [
                'title'       => 'Prefijo de orden',
                'type'        => 'text',
                'description' => 'Prefijo para la descripción del invoice.',
                'default'     => 'Spark101',
            ],
            'invoice_expiry' => [
                'title'       => 'Expiración del invoice (minutos)',
                'type'        => 'number',
                'description' => 'Tiempo antes de que expire el invoice Lightning.',
                'default'     => '15',
            ],
            'check_interval' => [
                'title'       => 'Intervalo de verificación (segundos)',
                'type'        => 'number',
                'description' => 'Cada cuántos segundos verificar si se pagó.',
                'default'     => '3',
            ],
        ];
    }

    /**
     * Process payment — generate Lightning invoice and redirect to pay page.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $total = (float) $order->get_total();

        // Convert to millisatoshis
        if ($this->currency === 'BTC') {
            $msats = (int) round($total * 100000000 * 1000);
            $sats  = (int) round($total * 100000000);
        } else {
            $msats = WLF_Exchange::fiat_to_msats($total, $this->currency);
            $sats  = WLF_Exchange::fiat_to_sats($total, $this->currency);
        }

        if (!$msats) {
            wc_add_notice('Error al obtener la cotización BTC. Intentá de nuevo.', 'error');
            return ['result' => 'failure'];
        }

        // Resolve Lightning Address
        $lnurl_data = WLF_LNURL::resolve($this->ln_address);
        if (!$lnurl_data) {
            wc_add_notice('Error al conectar con la wallet Lightning. Intentá de nuevo.', 'error');
            return ['result' => 'failure'];
        }

        // Check amount limits
        if ($msats < $lnurl_data['minSendable'] || $msats > $lnurl_data['maxSendable']) {
            wc_add_notice('El monto está fuera del rango permitido por la wallet.', 'error');
            return ['result' => 'failure'];
        }

        // Request invoice
        $comment = "{$this->prefix} — Orden #{$order_id}";
        $invoice_data = WLF_LNURL::get_invoice($lnurl_data['callback'], $msats, $comment);

        if (!$invoice_data) {
            wc_add_notice('Error al generar el invoice Lightning. Intentá de nuevo.', 'error');
            return ['result' => 'failure'];
        }

        // Store invoice data
        $order->update_meta_data('_wlf_invoice', $invoice_data['pr']);
        $order->update_meta_data('_wlf_sats', $sats);
        $order->update_meta_data('_wlf_msats', $msats);
        $order->update_meta_data('_wlf_rate', WLF_Exchange::get_rate($this->currency));
        $order->update_meta_data('_wlf_created', time());
        if (!empty($invoice_data['verify'])) {
            $order->update_meta_data('_wlf_verify_url', $invoice_data['verify']);
        }
        $order->update_status('pending', 'Esperando pago Lightning ⚡');
        $order->save();

        // Reduce stock
        wc_reduce_stock_levels($order_id);

        // Empty cart
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    /**
     * Thank you page — show Lightning invoice QR code.
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if ($order->get_payment_method() !== $this->id) return;
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            echo '<div class="wlf-paid"><h2>✅ Pago recibido</h2><p>¡Gracias! Tu pago Lightning fue confirmado.</p></div>';
            return;
        }

        $invoice = $order->get_meta('_wlf_invoice');
        $sats    = $order->get_meta('_wlf_sats');

        if (!$invoice) return;

        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode('lightning:' . $invoice);
        $wallet_link = 'lightning:' . $invoice;
        ?>
        <div class="wlf-payment-box" id="wlf-payment-box" data-order-id="<?php echo esc_attr($order_id); ?>">
            <h2>⚡ Pagá con Lightning</h2>
            <p class="wlf-amount">
                <strong><?php echo number_format($sats); ?> sats</strong>
                <span class="wlf-fiat">(<?php echo wc_price($order->get_total()); ?>)</span>
            </p>
            <div class="wlf-qr">
                <img src="<?php echo esc_url($qr_url); ?>" alt="Lightning Invoice QR" />
            </div>
            <div class="wlf-actions">
                <a href="<?php echo esc_url($wallet_link); ?>" class="wlf-btn wlf-btn-wallet">
                    Abrir Wallet ⚡
                </a>
                <button class="wlf-btn wlf-btn-copy" onclick="navigator.clipboard.writeText('<?php echo esc_js($invoice); ?>');this.textContent='¡Copiado!';">
                    Copiar Invoice
                </button>
            </div>
            <div class="wlf-status" id="wlf-status">
                <div class="wlf-spinner"></div>
                <p>Esperando pago... Se verifica automáticamente.</p>
            </div>
            <p class="wlf-expiry">El invoice expira en <?php echo $this->expiry; ?> minutos.</p>
        </div>
        <?php
    }
}
