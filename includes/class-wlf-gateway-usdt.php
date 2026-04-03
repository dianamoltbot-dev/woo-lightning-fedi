<?php
/**
 * WooCommerce Payment Gateway — USDT via Boltz Exchange (→ Lightning).
 * Creates a Lightning invoice, then shows a network selector + Boltz redirect.
 */

defined('ABSPATH') || exit;

class WLF_Gateway_USDT extends WC_Payment_Gateway {

    /**
     * All USDT networks supported by Boltz Exchange.
     */
    const NETWORKS = [
        'USDT0-ETH'     => ['name' => 'Ethereum',    'icon' => '⟠',  'gas' => '~$0.50'],
        'USDT0-ARB'     => ['name' => 'Arbitrum',     'icon' => '🔵', 'gas' => '~$0.01'],
        'USDT0-OP'      => ['name' => 'Optimism',     'icon' => '🔴', 'gas' => '~$0.01'],
        'USDT0-POL'     => ['name' => 'Polygon',      'icon' => '🟣', 'gas' => '~$0.01'],
        'USDT0-SOL'     => ['name' => 'Solana',       'icon' => '◎',  'gas' => '~$0.01'],
        'USDT0-TRON'    => ['name' => 'Tron',         'icon' => '♦️',  'gas' => '~$1.00'],
        'USDT0-BASE'    => ['name' => 'Base',         'icon' => '🔵', 'gas' => '~$0.01'],
        'USDT0-BERA'    => ['name' => 'Berachain',    'icon' => '🐻', 'gas' => '~$0.01'],
        'USDT0-UNI'     => ['name' => 'Unichain',     'icon' => '🦄', 'gas' => '~$0.01'],
        'USDT0-MNT'     => ['name' => 'Mantle',       'icon' => '🟢', 'gas' => '~$0.01'],
        'USDT0-SEI'     => ['name' => 'Sei',          'icon' => '🌊', 'gas' => '~$0.01'],
        'USDT0-INK'     => ['name' => 'Ink',          'icon' => '🖊️',  'gas' => '~$0.01'],
        'USDT0-HBAR'    => ['name' => 'Hedera',       'icon' => 'ℏ',  'gas' => '~$0.01'],
        'USDT0-FLR'     => ['name' => 'Flare',        'icon' => '🔥', 'gas' => '~$0.01'],
        'USDT0-CORN'    => ['name' => 'Corn',         'icon' => '🌽', 'gas' => '~$0.01'],
        'USDT0-HYPE'    => ['name' => 'Hyperliquid',  'icon' => '⚡', 'gas' => '~$0.01'],
        'USDT0-MEGAETH'  => ['name' => 'MegaETH',     'icon' => '🔷', 'gas' => '~$0.01'],
        'USDT0-MON'     => ['name' => 'Monad',        'icon' => '🟡', 'gas' => '~$0.01'],
        'USDT0-MORPH'   => ['name' => 'Morph',        'icon' => '🦋', 'gas' => '~$0.01'],
        'USDT0-CFX'     => ['name' => 'Conflux',      'icon' => '🌀', 'gas' => '~$0.01'],
        'USDT0-PLASMA'  => ['name' => 'Plasma',       'icon' => '💠', 'gas' => '~$0.01'],
        'USDT0-RBTC'    => ['name' => 'Rootstock',    'icon' => '🟠', 'gas' => '~$0.01'],
        'USDT0-STABLE'  => ['name' => 'Stable',       'icon' => '💎', 'gas' => '~$0.01'],
        'USDT0-XLAYER'  => ['name' => 'X Layer',      'icon' => '✖️',  'gas' => '~$0.01'],
    ];

    /** Top networks shown by default (rest in "Ver más redes") */
    const TOP_NETWORKS = ['USDT0-ARB', 'USDT0-ETH', 'USDT0-OP', 'USDT0-POL', 'USDT0-SOL', 'USDT0-TRON', 'USDT0-BASE'];

    public function __construct() {
        $this->id                 = 'lightning_fedi_usdt';
        $this->icon               = WLF_PLUGIN_URL . 'assets/img/usdt-icon.svg';
        $this->has_fields         = false;
        $this->method_title       = 'USDT → Lightning (Boltz)';
        $this->method_description = 'El cliente paga con USDT en la red que prefiera. Boltz convierte a sats y llegan por Lightning. Non-custodial, sin KYC.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'Pagar con USDT');
        $this->description = $this->get_option('description', 'Pagá con USDT desde cualquier wallet. Elegí tu red y Boltz hace la conversión automáticamente.');
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
        $network_options = [];
        foreach (self::NETWORKS as $key => $net) {
            $network_options[$key] = $net['name'];
        }

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
                'default'     => 'Pagar con USDT',
            ],
            'description' => [
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Descripción del método de pago.',
                'default'     => 'Pagá con USDT desde cualquier wallet. Elegí tu red y Boltz hace la conversión automáticamente.',
            ],
            'default_network' => [
                'title'       => 'Red por defecto',
                'type'        => 'select',
                'description' => 'Red pre-seleccionada. El cliente puede cambiarla.',
                'default'     => 'USDT0-ARB',
                'options'     => $network_options,
            ],
        ];
    }

    /**
     * Process payment — generate Lightning invoice, show network selector page.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $total = (float) $order->get_total();

        if (empty($this->ln_address)) {
            wc_add_notice('Error: Lightning Address no configurada. Contactá al vendedor.', 'error');
            return ['result' => 'failure'];
        }

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

        $lnurl_data = WLF_LNURL::resolve($this->ln_address);
        if (!$lnurl_data) {
            wc_add_notice('Error al conectar con la wallet Lightning. Intentá de nuevo.', 'error');
            return ['result' => 'failure'];
        }

        if ($msats < $lnurl_data['minSendable'] || $msats > $lnurl_data['maxSendable']) {
            wc_add_notice('El monto está fuera del rango permitido por la wallet.', 'error');
            return ['result' => 'failure'];
        }

        $comment = "{$this->prefix} — Orden #{$order_id} (USDT)";
        $invoice_data = WLF_LNURL::get_invoice($lnurl_data['callback'], $msats, $comment);

        if (!$invoice_data) {
            wc_add_notice('Error al generar el invoice Lightning. Intentá de nuevo.', 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_wlf_invoice', $invoice_data['pr']);
        $order->update_meta_data('_wlf_sats', $sats);
        $order->update_meta_data('_wlf_msats', $msats);
        $order->update_meta_data('_wlf_rate', WLF_Exchange::get_rate($this->currency));
        $order->update_meta_data('_wlf_created', time());
        $order->update_meta_data('_wlf_payment_method', 'usdt_boltz');
        if (!empty($invoice_data['verify'])) {
            $order->update_meta_data('_wlf_verify_url', $invoice_data['verify']);
        }
        $order->update_status('pending', 'Esperando pago USDT vía Boltz');
        $order->save();

        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    /**
     * Thank you page — network selector + Boltz redirect.
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

        $default_network = $this->get_option('default_network', 'USDT0-ARB');
        $networks = self::NETWORKS;
        $top = self::TOP_NETWORKS;
        ?>
        <div class="wlf-payment-box wlf-usdt-box" id="wlf-payment-box" data-order-id="<?php echo esc_attr($order_id); ?>">
            <h2>Pagá con USDT</h2>
            <p class="wlf-amount">
                <strong><?php echo number_format($sats); ?> sats</strong>
                <span class="wlf-fiat">(<?php echo wc_price($order->get_total()); ?>)</span>
            </p>

            <div class="wlf-network-selector">
                <p class="wlf-network-label"><strong>¿En qué red tenés tus USDT?</strong></p>
                <div class="wlf-network-grid" id="wlf-network-grid">
                    <?php foreach ($top as $key) :
                        if (!isset($networks[$key])) continue;
                        $net = $networks[$key];
                        $selected = ($key === $default_network) ? 'wlf-net-selected' : '';
                    ?>
                    <button type="button"
                            class="wlf-net-btn <?php echo $selected; ?>"
                            data-network="<?php echo esc_attr($key); ?>"
                            data-gas="<?php echo esc_attr($net['gas']); ?>">
                        <span class="wlf-net-icon"><?php echo $net['icon']; ?></span>
                        <span class="wlf-net-name"><?php echo esc_html($net['name']); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <details class="wlf-more-networks">
                    <summary>Ver más redes (<?php echo count($networks) - count($top); ?>+)</summary>
                    <div class="wlf-network-grid wlf-network-grid-more" id="wlf-network-grid-more">
                        <?php foreach ($networks as $key => $net) :
                            if (in_array($key, $top)) continue;
                            $selected = ($key === $default_network) ? 'wlf-net-selected' : '';
                        ?>
                        <button type="button"
                                class="wlf-net-btn <?php echo $selected; ?>"
                                data-network="<?php echo esc_attr($key); ?>"
                                data-gas="<?php echo esc_attr($net['gas']); ?>">
                            <span class="wlf-net-icon"><?php echo $net['icon']; ?></span>
                            <span class="wlf-net-name"><?php echo esc_html($net['name']); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </details>
                <p class="wlf-gas-info" id="wlf-gas-info">
                    Gas estimado: <strong id="wlf-gas-amount"><?php echo esc_html($networks[$default_network]['gas'] ?? '~$0.01'); ?></strong>
                </p>
            </div>

            <div class="wlf-actions">
                <a href="#" id="wlf-boltz-link" target="_blank" rel="noopener" class="wlf-btn wlf-btn-usdt-main">
                    Abrir Boltz Exchange →
                </a>
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

        <script>
        (function() {
            var invoice = <?php echo json_encode($invoice); ?>;
            var defaultNet = <?php echo json_encode($default_network); ?>;
            var selectedNet = defaultNet;
            var boltzLink = document.getElementById('wlf-boltz-link');
            var gasDisplay = document.getElementById('wlf-gas-amount');

            function updateLink() {
                boltzLink.href = 'https://boltz.exchange/?sendAsset=' + encodeURIComponent(selectedNet)
                    + '&receiveAsset=LN&destination=' + encodeURIComponent(invoice) + '&lang=es';
            }
            updateLink();

            document.querySelectorAll('.wlf-net-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.wlf-net-btn').forEach(function(b) {
                        b.classList.remove('wlf-net-selected');
                    });
                    this.classList.add('wlf-net-selected');
                    selectedNet = this.getAttribute('data-network');
                    gasDisplay.textContent = this.getAttribute('data-gas');
                    updateLink();
                });
            });
        })();
        </script>
        <?php
    }
}
