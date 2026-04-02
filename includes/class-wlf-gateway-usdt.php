<?php
/**
 * WooCommerce Payment Gateway — USDT via Boltz Exchange (→ Lightning).
 * Creates a Lightning invoice, then redirects to Boltz for USDT payment.
 */

defined('ABSPATH') || exit;

class WLF_Gateway_USDT extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'lightning_fedi_usdt';
        $this->icon               = WLF_PLUGIN_URL . 'assets/img/usdt-icon.svg';
        $this->has_fields         = false;
        $this->method_title       = 'USDT → Lightning (Boltz) 💵';
        $this->method_description = 'El cliente paga con USDT (cualquier red). Boltz convierte a sats y llegan por Lightning. Non-custodial, sin KYC.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'Pagar con USDT 💵');
        $this->description = $this->get_option('description', 'Pagá con USDT desde cualquier wallet y red. Se convierte a Bitcoin automáticamente vía Boltz Exchange.');
        $this->enabled     = $this->get_option('enabled', 'yes');

        // Get Lightning settings from the main gateway
        $ln_settings      = get_option('woocommerce_lightning_fedi_settings', []);
        $this->ln_address = isset($ln_settings['ln_address']) ? $ln_settings['ln_address'] : '';
        $this->currency   = isset($ln_settings['store_currency']) ? $ln_settings['store_currency'] : 'ARS';
        $this->prefix     = isset($ln_settings['order_prefix']) ? $ln_settings['order_prefix'] : 'Spark101';
        $this->expiry     = isset($ln_settings['invoice_expiry']) ? (int) $ln_settings['invoice_expiry'] : 15;

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/Desactivar',
                'type'    => 'checkbox',
                'label'   => 'Activar pagos con USDT vía Boltz',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Lo que ve el cliente en el checkout.',
                'default'     => 'Pagar con USDT 💵',
            ],
            'description' => [
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Descripción del método de pago.',
                'default'     => 'Pagá con USDT desde cualquier wallet y red. Se convierte a Bitcoin automáticamente vía Boltz Exchange.',
            ],
            'default_network' => [
                'title'       => 'Red USDT por defecto',
                'type'        => 'select',
                'description' => 'Red que se pre-selecciona en Boltz. El cliente puede cambiarla.',
                'default'     => 'USDT0-ETH',
                'options'     => [
                    'USDT0-ETH'   => 'Ethereum',
                    'USDT0-OP'    => 'Optimism',
                    'USDT0-POL'   => 'Polygon',
                    'USDT0-TRON'  => 'Tron',
                    'USDT0-SOL'   => 'Solana',
                ],
            ],
        ];
    }

    /**
     * Process payment — generate Lightning invoice, show Boltz redirect page.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $total = (float) $order->get_total();

        if (empty($this->ln_address)) {
            wc_add_notice('Error: Lightning Address no configurada. Contactá al vendedor.', 'error');
            return ['result' => 'failure'];
        }

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
        $comment = "{$this->prefix} — Orden #{$order_id} (USDT)";
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
        $order->update_meta_data('_wlf_payment_method', 'usdt_boltz');
        if (!empty($invoice_data['verify'])) {
            $order->update_meta_data('_wlf_verify_url', $invoice_data['verify']);
        }
        $order->update_status('pending', 'Esperando pago USDT vía Boltz 💵→⚡');
        $order->save();

        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    /**
     * Thank you page — show Boltz redirect + Lightning invoice as fallback.
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);

        if ($order->get_payment_method() !== $this->id) return;
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            echo '<div class="wlf-paid"><h2>✅ Pago recibido</h2><p>¡Gracias! Tu pago fue confirmado.</p></div>';
            return;
        }

        $invoice = $order->get_meta('_wlf_invoice');
        $sats    = $order->get_meta('_wlf_sats');

        if (!$invoice) return;

        $usdt_network = $this->get_option('default_network', 'USDT0-ETH');
        $boltz_url = 'https://boltz.exchange/?sendAsset=' . urlencode($usdt_network) . '&receiveAsset=LN&destination=' . urlencode($invoice) . '&lang=es';
        ?>
        <div class="wlf-payment-box wlf-usdt-box" id="wlf-payment-box" data-order-id="<?php echo esc_attr($order_id); ?>">
            <h2>💵 Pagá con USDT</h2>
            <p class="wlf-amount">
                <strong><?php echo number_format($sats); ?> sats</strong>
                <span class="wlf-fiat">(<?php echo wc_price($order->get_total()); ?>)</span>
            </p>
            <p class="wlf-usdt-desc">Hacé click en el botón para abrir Boltz Exchange. Desde ahí enviás USDT y se convierte automáticamente.</p>
            <div class="wlf-actions">
                <a href="<?php echo esc_url($boltz_url); ?>" target="_blank" rel="noopener" class="wlf-btn wlf-btn-usdt-main">
                    Abrir Boltz Exchange →
                </a>
            </div>
            <div class="wlf-usdt-steps">
                <p><strong>Pasos:</strong></p>
                <ol>
                    <li>Click en "Abrir Boltz Exchange"</li>
                    <li>Elegí la red (Ethereum, Polygon, Tron, etc.)</li>
                    <li>Enviá USDT desde tu wallet</li>
                    <li>Boltz convierte y esta página se actualiza ✅</li>
                </ol>
            </div>
            <div class="wlf-status" id="wlf-status">
                <div class="wlf-spinner"></div>
                <p>Esperando pago... Se verifica automáticamente.</p>
            </div>
            <details class="wlf-fallback">
                <summary>¿Preferís pagar con Lightning directo?</summary>
                <div class="wlf-qr" style="margin-top:12px;">
                    <img src="<?php echo esc_url('https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode('lightning:' . $invoice)); ?>" alt="Lightning Invoice QR" />
                </div>
                <div class="wlf-actions" style="margin-top:12px;">
                    <a href="lightning:<?php echo esc_attr($invoice); ?>" class="wlf-btn wlf-btn-wallet">Abrir Wallet ⚡</a>
                    <button class="wlf-btn wlf-btn-copy" onclick="navigator.clipboard.writeText('<?php echo esc_js($invoice); ?>');this.textContent='¡Copiado!';">Copiar Invoice</button>
                </div>
            </details>
            <p class="wlf-expiry">El invoice expira en <?php echo esc_html($this->expiry); ?> minutos.</p>
            <p class="wlf-usdt-info">🔒 Non-custodial · Sin KYC · Powered by <a href="https://boltz.exchange" target="_blank" rel="noopener">Boltz Exchange</a></p>
        </div>
        <?php
    }
}
