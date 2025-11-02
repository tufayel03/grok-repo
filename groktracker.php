
<?php
/**
 * Plugin Name: Multi-Chain Wallet Tracker
 * Plugin URI: https://example.com
 * Description: Track Ethereum and Binance Smart Chain wallets with transaction logs and Discord alerts.
 * Version: 3.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WALLET_TRACKER_VERSION', '3.0.0');

register_activation_hook(__FILE__, 'wallet_tracker_activate');
register_deactivation_hook(__FILE__, 'wallet_tracker_deactivate');

add_filter('cron_schedules', 'wallet_tracker_register_schedule');
add_action('wallet_tracker_cron_event', 'wallet_tracker_check_transactions');

add_action('admin_menu', 'wallet_tracker_admin_menu');
add_shortcode('wallet_tracker', 'wallet_tracker_shortcode');
add_shortcode('wallet_transaction_logs', 'wallet_tracker_logs_shortcode');

add_action('wp_ajax_wallet_tracker_load', 'wallet_tracker_handle_load');
add_action('wp_ajax_wallet_tracker_save', 'wallet_tracker_handle_save');

function wallet_tracker_default_data()
{
    return [
        'config' => [
            'etherscanKey' => '',
            'bscscanKey' => '',
            'discordWebhook' => '',
            'pollInterval' => 300,
        ],
        'wallets' => [],
        'logs' => [],
        'lastRun' => 0,
    ];
}

function wallet_tracker_get_data()
{
    $data = get_option('wallet_tracker_data');
    if (!is_array($data)) {
        $data = wallet_tracker_default_data();
        add_option('wallet_tracker_data', $data);
    } else {
        $defaults = wallet_tracker_default_data();
        $data = array_merge($defaults, $data);
        $data['config'] = array_merge($defaults['config'], $data['config']);
        if (!isset($data['wallets']) || !is_array($data['wallets'])) {
            $data['wallets'] = [];
        }
        if (!isset($data['logs']) || !is_array($data['logs'])) {
            $data['logs'] = [];
        }
        if (!isset($data['lastRun'])) {
            $data['lastRun'] = 0;
        }
    }
    return $data;
}

function wallet_tracker_save_data($data)
{
    return update_option('wallet_tracker_data', $data);
}

function wallet_tracker_sanitize_interval($value)
{
    $interval = (int)$value;
    if ($interval < 60) {
        $interval = 60;
    }
    if ($interval > 3600) {
        $interval = 3600;
    }
    return $interval;
}

function wallet_tracker_register_schedule($schedules)
{
    $data = wallet_tracker_get_data();
    $interval = wallet_tracker_sanitize_interval($data['config']['pollInterval']);
    $schedules['wallet_tracker_interval'] = [
        'interval' => $interval,
        'display' => sprintf(__('Wallet Tracker (%d seconds)', 'wallet-tracker'), $interval),
    ];
    return $schedules;
}

function wallet_tracker_schedule_event($interval = null)
{
    if ($interval === null) {
        $data = wallet_tracker_get_data();
        $interval = wallet_tracker_sanitize_interval($data['config']['pollInterval']);
    }
    $interval = wallet_tracker_sanitize_interval($interval);

    wp_clear_scheduled_hook('wallet_tracker_cron_event');

    if ($interval > 0) {
        wp_schedule_event(time() + $interval, 'wallet_tracker_interval', 'wallet_tracker_cron_event');
    }
}

function wallet_tracker_activate()
{
    $data = wallet_tracker_get_data();
    wallet_tracker_save_data($data);
    wallet_tracker_schedule_event($data['config']['pollInterval']);
}

function wallet_tracker_deactivate()
{
    wp_clear_scheduled_hook('wallet_tracker_cron_event');
}

function wallet_tracker_enqueue_dependencies()
{
    wp_enqueue_script('jquery');
}

function wallet_tracker_render_styles()
{
    ob_start();
    ?>
    <style>
        .wallet-tracker-container {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #121212;
            color: #f5f5f5;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            max-width: 1200px;
        }
        .wallet-tracker-container h1,
        .wallet-tracker-container h2 {
            color: #61dafb;
            margin-bottom: 12px;
        }
        .wallet-tracker-section {
            margin-bottom: 32px;
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .wallet-tracker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .wallet-tracker-grid label {
            display: flex;
            flex-direction: column;
            font-weight: 600;
            color: #cfd8dc;
        }
        .wallet-tracker-grid input,
        .wallet-tracker-grid select {
            margin-top: 6px;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(18, 18, 18, 0.9);
            color: #fff;
        }
        .wallet-tracker-grid input:focus,
        .wallet-tracker-grid select:focus {
            outline: none;
            border-color: #61dafb;
            box-shadow: 0 0 0 2px rgba(97, 218, 251, 0.3);
        }
        .wallet-tracker-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .wallet-tracker-button {
            background: linear-gradient(135deg, #61dafb 0%, #33b5e5 100%);
            border: none;
            color: #0b1320;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .wallet-tracker-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(97, 218, 251, 0.25);
        }
        .wallet-tracker-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .wallet-tracker-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff4757 100%);
            color: #fff;
        }
        .wallet-table-wrapper {
            overflow-x: auto;
        }
        .wallet-tracker-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .wallet-tracker-table th,
        .wallet-tracker-table td {
            padding: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .wallet-tracker-table tbody tr:hover {
            background: rgba(97, 218, 251, 0.08);
        }
        .wallet-tracker-status {
            margin-bottom: 16px;
            padding: 12px 16px;
            border-radius: 8px;
            display: none;
        }
        .wallet-tracker-status.is-visible {
            display: block;
        }
        .wallet-tracker-status.is-success {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }
        .wallet-tracker-status.is-error {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        .wallet-tracker-status.is-info {
            background: rgba(52, 152, 219, 0.2);
            color: #61dafb;
        }
        .wallet-tracker-hash-link {
            color: #61dafb;
            text-decoration: none;
        }
        .wallet-tracker-hash-link:hover {
            text-decoration: underline;
        }
    </style>
    <?php
    return ob_get_clean();
}

function wallet_tracker_admin_menu()
{
    add_menu_page(
        __('Wallet Tracker', 'wallet-tracker'),
        __('Wallet Tracker', 'wallet-tracker'),
        'manage_options',
        'wallet-tracker',
        'wallet_tracker_admin_page',
        'dashicons-chart-area',
        30
    );
}

function wallet_tracker_admin_page()
{
    echo do_shortcode('[wallet_tracker]');
}

function wallet_tracker_shortcode($atts)
{
    if (!current_user_can('manage_options')) {
        return '<div class="wallet-tracker-container"><p>' . esc_html__('You do not have permission to view this page.', 'wallet-tracker') . '</p></div>';
    }

    wallet_tracker_enqueue_dependencies();

    ob_start();
    ?>
    <div class="wallet-tracker-container">
        <h1><?php esc_html_e('Wallet Tracker Control Panel', 'wallet-tracker'); ?></h1>
        <div class="wallet-tracker-status" id="walletTrackerStatus"></div>

        <section class="wallet-tracker-section">
            <h2><?php esc_html_e('API & Webhook Configuration', 'wallet-tracker'); ?></h2>
            <div class="wallet-tracker-grid">
                <label>
                    <?php esc_html_e('Etherscan API Key', 'wallet-tracker'); ?>
                    <input type="text" id="etherscanKey" placeholder="<?php esc_attr_e('Required for Ethereum tracking', 'wallet-tracker'); ?>">
                </label>
                <label>
                    <?php esc_html_e('BscScan API Key', 'wallet-tracker'); ?>
                    <input type="text" id="bscscanKey" placeholder="<?php esc_attr_e('Required for BSC tracking', 'wallet-tracker'); ?>">
                </label>
                <label>
                    <?php esc_html_e('Discord Webhook URL', 'wallet-tracker'); ?>
                    <input type="text" id="discordWebhook" placeholder="https://discord.com/api/webhooks/...">
                </label>
                <label>
                    <?php esc_html_e('Poll Interval (seconds)', 'wallet-tracker'); ?>
                    <input type="number" id="pollInterval" min="60" max="3600">
                </label>
            </div>
            <button class="wallet-tracker-button" id="saveConfigButton"><?php esc_html_e('Save Configuration', 'wallet-tracker'); ?></button>
        </section>

        <section class="wallet-tracker-section">
            <h2><?php esc_html_e('Tracked Wallets', 'wallet-tracker'); ?></h2>
            <div class="wallet-tracker-grid">
                <label>
                    <?php esc_html_e('Chain', 'wallet-tracker'); ?>
                    <select id="walletChain">
                        <option value="eth"><?php esc_html_e('Ethereum', 'wallet-tracker'); ?></option>
                        <option value="bsc"><?php esc_html_e('Binance Smart Chain', 'wallet-tracker'); ?></option>
                    </select>
                </label>
                <label>
                    <?php esc_html_e('Wallet Address', 'wallet-tracker'); ?>
                    <input type="text" id="walletAddress" placeholder="0x...">
                </label>
                <label>
                    <?php esc_html_e('Label', 'wallet-tracker'); ?>
                    <input type="text" id="walletLabel" placeholder="My hot wallet">
                </label>
                <label>
                    <?php esc_html_e('Discord Message Template', 'wallet-tracker'); ?>
                    <input type="text" id="walletMessage" placeholder="New {chain} tx for {label}: {amount} {token}">
                </label>
            </div>
            <div class="wallet-tracker-actions">
                <button class="wallet-tracker-button" id="addWalletButton"><?php esc_html_e('Add Wallet', 'wallet-tracker'); ?></button>
                <button class="wallet-tracker-button wallet-tracker-secondary" id="refreshButton"><?php esc_html_e('Refresh Data', 'wallet-tracker'); ?></button>
                <button class="wallet-tracker-button wallet-tracker-danger" id="clearLogsButton"><?php esc_html_e('Clear Logs', 'wallet-tracker'); ?></button>
            </div>
            <div class="wallet-table-wrapper">
                <table class="wallet-tracker-table" id="walletTable">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Chain', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Address', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Message Template', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Actions', 'wallet-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="wallet-tracker-section">
            <h2><?php esc_html_e('Transaction Logs', 'wallet-tracker'); ?></h2>
            <p><?php esc_html_e('Most recent transactions are shown first. Logs are stored in WordPress and alerts are sent to Discord using your webhook.', 'wallet-tracker'); ?></p>
            <div class="wallet-table-wrapper">
                <table class="wallet-tracker-table" id="logTable">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Label', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Chain', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Direction', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Amount', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Token', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('From', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('To', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Hash', 'wallet-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </div>
    <?php echo wallet_tracker_render_styles(); ?>

    <script>
        window.walletTrackerAjax = {
            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('wallet_tracker_nonce')); ?>'
        };
    </script>
    <script>
        (function ($) {
            const ajaxConfig = window.walletTrackerAjax || {};
            const tracker = {
                data: null,
                init() {
                    this.cache();
                    this.bindEvents();
                    this.load();
                },
                cache() {
                    this.$status = $('#walletTrackerStatus');
                    this.$etherscan = $('#etherscanKey');
                    this.$bscscan = $('#bscscanKey');
                    this.$webhook = $('#discordWebhook');
                    this.$interval = $('#pollInterval');
                    this.$walletTable = $('#walletTable tbody');
                    this.$logTable = $('#logTable tbody');
                    this.$chain = $('#walletChain');
                    this.$address = $('#walletAddress');
                    this.$label = $('#walletLabel');
                    this.$message = $('#walletMessage');
                },
                bindEvents() {
                    $('#saveConfigButton').on('click', () => this.saveConfig());
                    $('#addWalletButton').on('click', () => this.addWallet());
                    $('#refreshButton').on('click', () => this.load());
                    $('#clearLogsButton').on('click', () => this.clearLogs());
                },
                load() {
                    this.setStatus('<?php echo esc_js(__('Loading data...', 'wallet-tracker')); ?>', 'info');
                    $.post(ajaxConfig.url, {
                        action: 'wallet_tracker_load',
                        nonce: ajaxConfig.nonce
                    }).done((response) => {
                        if (response.success) {
                            this.data = response.data;
                            this.render();
                            this.setStatus('<?php echo esc_js(__('Data loaded.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Failed to load data.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                persist(payload) {
                    return $.post(ajaxConfig.url, {
                        action: 'wallet_tracker_save',
                        nonce: ajaxConfig.nonce,
                        data: JSON.stringify(payload)
                    });
                },
                saveConfig() {
                    if (!this.data) {
                        return;
                    }
                    this.data.config.etherscanKey = this.$etherscan.val().trim();
                    this.data.config.bscscanKey = this.$bscscan.val().trim();
                    this.data.config.discordWebhook = this.$webhook.val().trim();
                    this.data.config.pollInterval = parseInt(this.$interval.val(), 10) || this.data.config.pollInterval;

                    this.setStatus('<?php echo esc_js(__('Saving configuration...', 'wallet-tracker')); ?>', 'info');
                    this.persist({ config: this.data.config }).done((response) => {
                        if (response.success) {
                            this.data = response.data;
                            this.renderConfig();
                            this.setStatus('<?php echo esc_js(__('Configuration saved.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Unable to save configuration.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                addWallet() {
                    if (!this.data) {
                        return;
                    }
                    const chain = this.$chain.val();
                    const address = this.$address.val().trim();
                    if (!address) {
                        this.setStatus('<?php echo esc_js(__('Please provide a wallet address.', 'wallet-tracker')); ?>', 'error');
                        return;
                    }
                    const wallet = {
                        id: 'temp_' + Date.now(),
                        chain: chain,
                        address: address,
                        label: this.$label.val().trim(),
                        customText: this.$message.val().trim()
                    };
                    this.data.wallets.push(wallet);
                    this.setStatus('<?php echo esc_js(__('Saving wallet...', 'wallet-tracker')); ?>', 'info');
                    this.persist({ wallets: this.data.wallets }).done((response) => {
                        if (response.success) {
                            this.data = response.data;
                            this.renderWallets();
                            this.clearWalletForm();
                            this.setStatus('<?php echo esc_js(__('Wallet saved.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Unable to save wallet.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                removeWallet(id) {
                    if (!this.data) {
                        return;
                    }
                    this.data.wallets = this.data.wallets.filter((wallet) => wallet.id !== id);
                    this.setStatus('<?php echo esc_js(__('Updating wallets...', 'wallet-tracker')); ?>', 'info');
                    this.persist({ wallets: this.data.wallets }).done((response) => {
                        if (response.success) {
                            this.data = response.data;
                            this.renderWallets();
                            this.setStatus('<?php echo esc_js(__('Wallet removed.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Unable to update wallets.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                clearLogs() {
                    if (!this.data) {
                        return;
                    }
                    if (!window.confirm('<?php echo esc_js(__('Clear all stored logs?', 'wallet-tracker')); ?>')) {
                        return;
                    }
                    this.setStatus('<?php echo esc_js(__('Clearing logs...', 'wallet-tracker')); ?>', 'info');
                    this.persist({ logs: [] }).done((response) => {
                        if (response.success) {
                            this.data = response.data;
                            this.renderLogs();
                            this.setStatus('<?php echo esc_js(__('Logs cleared.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Unable to clear logs.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                clearWalletForm() {
                    this.$address.val('');
                    this.$label.val('');
                    this.$message.val('');
                },
                render() {
                    if (!this.data) {
                        return;
                    }
                    this.renderConfig();
                    this.renderWallets();
                    this.renderLogs();
                },
                renderConfig() {
                    this.$etherscan.val(this.data.config.etherscanKey || '');
                    this.$bscscan.val(this.data.config.bscscanKey || '');
                    this.$webhook.val(this.data.config.discordWebhook || '');
                    this.$interval.val(this.data.config.pollInterval || 300);
                },
                renderWallets() {
                    this.$walletTable.empty();
                    if (!this.data.wallets.length) {
                        this.$walletTable.append('<tr><td colspan="5"><?php echo esc_js(__('No wallets tracked yet.', 'wallet-tracker')); ?></td></tr>');
                        return;
                    }
                    this.data.wallets.forEach((wallet) => {
                        const row = $('<tr></tr>');
                        row.append($('<td></td>').text(wallet.label || '<?php echo esc_js(__('Unlabeled', 'wallet-tracker')); ?>'));
                        row.append($('<td></td>').text(wallet.chain === 'bsc' ? 'BSC' : 'ETH'));
                        row.append($('<td></td>').text(wallet.address));
                        row.append($('<td></td>').text(wallet.customText || '<?php echo esc_js(__('Default message', 'wallet-tracker')); ?>'));
                        const $actions = $('<td></td>');
                        $('<button class="wallet-tracker-button wallet-tracker-danger"></button>')
                            .text('<?php echo esc_js(__('Remove', 'wallet-tracker')); ?>')
                            .on('click', () => this.removeWallet(wallet.id))
                            .appendTo($actions);
                        row.append($actions);
                        this.$walletTable.append(row);
                    });
                },
                renderLogs() {
                    this.$logTable.empty();
                    if (!this.data.logs.length) {
                        this.$logTable.append('<tr><td colspan="9"><?php echo esc_js(__('No transactions recorded yet.', 'wallet-tracker')); ?></td></tr>');
                        return;
                    }
                    this.data.logs.forEach((log) => {
                        const row = $('<tr></tr>');
                        row.append($('<td></td>').text(this.formatDate(log.timestamp)));
                        row.append($('<td></td>').text(log.label || '<?php echo esc_js(__('Unlabeled', 'wallet-tracker')); ?>'));
                        row.append($('<td></td>').text(log.chain === 'bsc' ? 'BSC' : 'ETH'));
                        row.append($('<td></td>').text(log.direction === 'in' ? '<?php echo esc_js(__('In', 'wallet-tracker')); ?>' : '<?php echo esc_js(__('Out', 'wallet-tracker')); ?>'));
                        row.append($('<td></td>').text(log.amount));
                        row.append($('<td></td>').text(log.token));
                        row.append($('<td></td>').text(log.from));
                        row.append($('<td></td>').text(log.to));
                        if (log.txUrl) {
                            row.append($('<td></td>').append($('<a></a>').attr('href', log.txUrl).attr('target', '_blank').addClass('wallet-tracker-hash-link').text(log.hash)));
                        } else {
                            row.append($('<td></td>').text(log.hash));
                        }
                        this.$logTable.append(row);
                    });
                },
                formatDate(timestamp) {
                    if (!timestamp) {
                        return '';
                    }
                    const date = new Date(timestamp * 1000);
                    return date.toLocaleString();
                },
                setStatus(message, type) {
                    this.$status
                        .removeClass('is-success is-error is-info is-visible')
                        .addClass(type ? 'is-' + type : '')
                        .addClass('is-visible')
                        .text(message);
                }
            };

            $(document).ready(() => tracker.init());
        })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}

function wallet_tracker_logs_shortcode($atts)
{
    if (!current_user_can('manage_options')) {
        return '<div class="wallet-tracker-container"><p>' . esc_html__('You do not have permission to view this page.', 'wallet-tracker') . '</p></div>';
    }

    wallet_tracker_enqueue_dependencies();

    ob_start();
    ?>
    <div class="wallet-tracker-container">
        <h1><?php esc_html_e('Tracked Wallet Transaction Logs', 'wallet-tracker'); ?></h1>
        <div class="wallet-tracker-status" id="walletTrackerStatusLogs"></div>
        <div class="wallet-table-wrapper">
            <table class="wallet-tracker-table" id="logOnlyTable">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Label', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Chain', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Direction', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Amount', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Token', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('From', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('To', 'wallet-tracker'); ?></th>
                        <th><?php esc_html_e('Hash', 'wallet-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <?php echo wallet_tracker_render_styles(); ?>
    <script>
        window.walletTrackerAjax = window.walletTrackerAjax || {
            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('wallet_tracker_nonce')); ?>'
        };
    </script>
    <script>
        (function ($) {
            const ajaxConfig = window.walletTrackerAjax || {};
            const logViewer = {
                $status: null,
                $table: null,
                init() {
                    this.$status = $('#walletTrackerStatusLogs');
                    this.$table = $('#logOnlyTable tbody');
                    this.load();
                },
                load() {
                    this.setStatus('<?php echo esc_js(__('Loading logs...', 'wallet-tracker')); ?>', 'info');
                    $.post(ajaxConfig.url, {
                        action: 'wallet_tracker_load',
                        nonce: ajaxConfig.nonce
                    }).done((response) => {
                        if (response.success) {
                            this.render(response.data.logs || []);
                            this.setStatus('<?php echo esc_js(__('Logs loaded.', 'wallet-tracker')); ?>', 'success');
                        } else {
                            this.setStatus(response.data || response.message || '<?php echo esc_js(__('Failed to load logs.', 'wallet-tracker')); ?>', 'error');
                        }
                    }).fail(() => {
                        this.setStatus('<?php echo esc_js(__('Unable to contact server.', 'wallet-tracker')); ?>', 'error');
                    });
                },
                render(logs) {
                    this.$table.empty();
                    if (!logs.length) {
                        this.$table.append('<tr><td colspan="9"><?php echo esc_js(__('No transactions recorded yet.', 'wallet-tracker')); ?></td></tr>');
                        return;
                    }
                    logs.forEach((log) => {
                        const row = $('<tr></tr>');
                        const date = new Date((log.timestamp || 0) * 1000).toLocaleString();
                        row.append($('<td></td>').text(date));
                        row.append($('<td></td>').text(log.label || '<?php echo esc_js(__('Unlabeled', 'wallet-tracker')); ?>'));
                        row.append($('<td></td>').text(log.chain === 'bsc' ? 'BSC' : 'ETH'));
                        row.append($('<td></td>').text(log.direction === 'in' ? '<?php echo esc_js(__('In', 'wallet-tracker')); ?>' : '<?php echo esc_js(__('Out', 'wallet-tracker')); ?>'));
                        row.append($('<td></td>').text(log.amount));
                        row.append($('<td></td>').text(log.token));
                        row.append($('<td></td>').text(log.from));
                        row.append($('<td></td>').text(log.to));
                        if (log.txUrl) {
                            row.append($('<td></td>').append($('<a></a>').attr('href', log.txUrl).attr('target', '_blank').addClass('wallet-tracker-hash-link').text(log.hash)));
                        } else {
                            row.append($('<td></td>').text(log.hash));
                        }
                        this.$table.append(row);
                    });
                },
                setStatus(message, type) {
                    this.$status
                        .removeClass('is-success is-error is-info is-visible')
                        .addClass(type ? 'is-' + type : '')
                        .addClass('is-visible')
                        .text(message);
                }
            };
            $(document).ready(() => logViewer.init());
        })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}

function wallet_tracker_handle_load()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'wallet-tracker'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wallet_tracker_nonce')) {
        wp_send_json_error(__('Nonce verification failed', 'wallet-tracker'));
    }

    $data = wallet_tracker_get_data();
    wp_send_json_success($data);
}

function wallet_tracker_handle_save()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'wallet-tracker'));
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wallet_tracker_nonce')) {
        wp_send_json_error(__('Nonce verification failed', 'wallet-tracker'));
    }

    if (!isset($_POST['data'])) {
        wp_send_json_error(__('No data provided', 'wallet-tracker'));
    }

    $payload = json_decode(stripslashes((string)$_POST['data']), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(sprintf(__('Invalid JSON: %s', 'wallet-tracker'), json_last_error_msg()));
    }

    $data = wallet_tracker_get_data();
    $originalInterval = wallet_tracker_sanitize_interval($data['config']['pollInterval']);

    if (isset($payload['config']) && is_array($payload['config'])) {
        $config = $payload['config'];
        $data['config']['etherscanKey'] = sanitize_text_field($config['etherscanKey'] ?? '');
        $data['config']['bscscanKey'] = sanitize_text_field($config['bscscanKey'] ?? '');
        $data['config']['discordWebhook'] = esc_url_raw($config['discordWebhook'] ?? '');
        $data['config']['pollInterval'] = wallet_tracker_sanitize_interval($config['pollInterval'] ?? $data['config']['pollInterval']);
    }

    if (array_key_exists('wallets', $payload) && is_array($payload['wallets'])) {
        $data['wallets'] = wallet_tracker_sanitize_wallets($payload['wallets'], $data['wallets']);
    }

    if (isset($payload['logs']) && is_array($payload['logs']) && empty($payload['logs'])) {
        $data['logs'] = [];
    }

    wallet_tracker_save_data($data);

    $newInterval = wallet_tracker_sanitize_interval($data['config']['pollInterval']);
    if ($newInterval !== $originalInterval) {
        wallet_tracker_schedule_event($newInterval);
    }

    wp_send_json_success(wallet_tracker_get_data());
}

function wallet_tracker_sanitize_wallets($wallets, $existingWallets)
{
    $sanitized = [];
    $existingMap = [];
    foreach ($existingWallets as $wallet) {
        if (!empty($wallet['id'])) {
            $existingMap[$wallet['id']] = $wallet;
        }
    }

    foreach ($wallets as $wallet) {
        if (!is_array($wallet)) {
            continue;
        }

        $id = isset($wallet['id']) && is_string($wallet['id']) ? sanitize_key(str_replace(' ', '-', $wallet['id'])) : '';
        if (!$id) {
            $id = 'wallet_' . uniqid();
        }

        $address = isset($wallet['address']) ? strtolower(sanitize_text_field($wallet['address'])) : '';
        if (empty($address)) {
            continue;
        }

        $chain = isset($wallet['chain']) && in_array($wallet['chain'], ['eth', 'bsc'], true) ? $wallet['chain'] : 'eth';
        $label = isset($wallet['label']) ? sanitize_text_field($wallet['label']) : '';
        $customText = isset($wallet['customText']) ? sanitize_textarea_field($wallet['customText']) : '';

        $existing = $existingMap[$id] ?? [];
        $recentHashes = [];
        if (isset($existing['recentHashes']) && is_array($existing['recentHashes'])) {
            $recentHashes = array_slice(array_map('sanitize_text_field', $existing['recentHashes']), -50);
        }

        $sanitized[] = [
            'id' => $id,
            'chain' => $chain,
            'address' => $address,
            'label' => $label,
            'customText' => $customText,
            'lastNativeBlock' => isset($existing['lastNativeBlock']) ? (int)$existing['lastNativeBlock'] : 0,
            'lastTokenBlock' => isset($existing['lastTokenBlock']) ? (int)$existing['lastTokenBlock'] : 0,
            'recentHashes' => $recentHashes,
        ];
    }

    return $sanitized;
}

function wallet_tracker_check_transactions()
{
    $data = wallet_tracker_get_data();
    $config = $data['config'];

    $pollInterval = wallet_tracker_sanitize_interval($config['pollInterval']);
    $now = time();
    $lastRun = isset($data['lastRun']) ? (int)$data['lastRun'] : 0;
    if ($lastRun && ($now - $lastRun) < max(30, $pollInterval - 10)) {
        return;
    }

    if (empty($data['wallets'])) {
        $data['lastRun'] = $now;
        wallet_tracker_save_data($data);
        return;
    }

    $newLogs = [];
    $wallets = $data['wallets'];
    foreach ($wallets as $index => $wallet) {
        $result = wallet_tracker_collect_wallet_transactions($wallet, $config);
        if (!empty($result['logs'])) {
            $newLogs = array_merge($newLogs, $result['logs']);
        }
        $wallets[$index] = $result['wallet'];
    }

    if (!empty($newLogs)) {
        usort($newLogs, function ($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });
        $data['logs'] = array_slice(array_merge($newLogs, $data['logs']), 0, 200);
    }

    $data['wallets'] = $wallets;
    $data['lastRun'] = $now;
    wallet_tracker_save_data($data);
}

function wallet_tracker_collect_wallet_transactions($wallet, $config)
{
    $chain = $wallet['chain'];
    $apiKey = $chain === 'bsc' ? ($config['bscscanKey'] ?? '') : ($config['etherscanKey'] ?? '');
    if (empty($apiKey)) {
        return ['wallet' => $wallet, 'logs' => []];
    }

    $baseUrl = $chain === 'bsc' ? 'https://api.bscscan.com/api' : 'https://api.etherscan.io/api';
    $address = $wallet['address'];

    $nativeParams = [
        'module' => 'account',
        'action' => 'txlist',
        'address' => $address,
        'startblock' => max(0, (int)$wallet['lastNativeBlock'] - 1),
        'endblock' => 99999999,
        'sort' => 'asc',
        'apikey' => $apiKey,
    ];

    $tokenParams = [
        'module' => 'account',
        'action' => 'tokentx',
        'address' => $address,
        'startblock' => max(0, (int)$wallet['lastTokenBlock'] - 1),
        'endblock' => 99999999,
        'sort' => 'asc',
        'apikey' => $apiKey,
    ];

    $logs = [];
    $nativeTransactions = wallet_tracker_fetch_transactions($baseUrl, $nativeParams);
    if (!empty($nativeTransactions)) {
        $processed = wallet_tracker_process_transactions($wallet, $nativeTransactions, 'native', $chain, $config);
        $wallet = $processed['wallet'];
        $logs = array_merge($logs, $processed['logs']);
    }

    $tokenTransactions = wallet_tracker_fetch_transactions($baseUrl, $tokenParams);
    if (!empty($tokenTransactions)) {
        $processed = wallet_tracker_process_transactions($wallet, $tokenTransactions, 'token', $chain, $config);
        $wallet = $processed['wallet'];
        $logs = array_merge($logs, $processed['logs']);
    }

    return ['wallet' => $wallet, 'logs' => $logs];
}

function wallet_tracker_fetch_transactions($baseUrl, $params)
{
    $url = add_query_arg($params, $baseUrl);
    $response = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($response)) {
        return [];
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return [];
    }
    if (isset($body['status']) && (string)$body['status'] === '0') {
        return [];
    }
    if (!isset($body['result']) || !is_array($body['result'])) {
        return [];
    }
    return $body['result'];
}

function wallet_tracker_process_transactions($wallet, $transactions, $type, $chain, $config)
{
    $logs = [];
    $walletAddress = strtolower($wallet['address']);
    $recentHashes = isset($wallet['recentHashes']) && is_array($wallet['recentHashes']) ? $wallet['recentHashes'] : [];
    $recentHashes = array_map('strtolower', $recentHashes);
    $newRecentHashes = $recentHashes;
    $nativeBlock = isset($wallet['lastNativeBlock']) ? (int)$wallet['lastNativeBlock'] : 0;
    $tokenBlock = isset($wallet['lastTokenBlock']) ? (int)$wallet['lastTokenBlock'] : 0;

    foreach ($transactions as $transaction) {
        if (!is_array($transaction) || empty($transaction['hash'])) {
            continue;
        }
        $hash = strtolower($transaction['hash']);
        if (in_array($hash, $recentHashes, true)) {
            continue;
        }

        $blockNumber = isset($transaction['blockNumber']) ? (int)$transaction['blockNumber'] : 0;
        if ($type === 'native') {
            if ($blockNumber < $nativeBlock) {
                continue;
            }
        } else {
            if ($blockNumber < $tokenBlock) {
                continue;
            }
        }

        $timestamp = isset($transaction['timeStamp']) ? (int)$transaction['timeStamp'] : time();
        $from = strtolower($transaction['from'] ?? '');
        $to = strtolower($transaction['to'] ?? '');
        $direction = ($to === $walletAddress) ? 'in' : 'out';

        if ($type === 'native') {
            $amountRaw = $transaction['value'] ?? '0';
            $tokenSymbol = $chain === 'bsc' ? 'BNB' : 'ETH';
            $decimals = 18;
            $wallet['lastNativeBlock'] = max($blockNumber, $nativeBlock);
            $nativeBlock = $wallet['lastNativeBlock'];
        } else {
            $amountRaw = $transaction['value'] ?? '0';
            $tokenSymbol = $transaction['tokenSymbol'] ?? '';
            $decimals = isset($transaction['tokenDecimal']) ? (int)$transaction['tokenDecimal'] : 18;
            $wallet['lastTokenBlock'] = max($blockNumber, $tokenBlock);
            $tokenBlock = $wallet['lastTokenBlock'];
        }

        $amount = wallet_tracker_format_amount($amountRaw, $decimals);
        $txUrl = $chain === 'bsc' ? 'https://bscscan.com/tx/' . $transaction['hash'] : 'https://etherscan.io/tx/' . $transaction['hash'];

        $log = [
            'id' => 'log_' . uniqid(),
            'walletId' => $wallet['id'],
            'label' => $wallet['label'],
            'chain' => $chain,
            'type' => $type,
            'hash' => $transaction['hash'],
            'amount' => $amount,
            'token' => $tokenSymbol,
            'direction' => $direction,
            'from' => $transaction['from'] ?? '',
            'to' => $transaction['to'] ?? '',
            'timestamp' => $timestamp,
            'txUrl' => $txUrl,
        ];

        $log['message'] = wallet_tracker_build_message($wallet, $log);

        $logs[] = $log;
        $newRecentHashes[] = $hash;

        if (!empty($config['discordWebhook'])) {
            wallet_tracker_send_discord_message($config['discordWebhook'], $log['message']);
        }
    }

    $wallet['recentHashes'] = array_slice(array_map('strtolower', array_unique($newRecentHashes)), -50);

    return ['wallet' => $wallet, 'logs' => $logs];
}

function wallet_tracker_format_amount($value, $decimals = 18)
{
    $value = is_string($value) ? trim($value) : (string)$value;
    if ($value === '' || !ctype_digit(ltrim($value, '-'))) {
        return '0';
    }
    $isNegative = strpos($value, '-') === 0;
    if ($isNegative) {
        $value = substr($value, 1);
    }
    $value = ltrim($value, '0');
    if ($value === '') {
        $value = '0';
    }

    if ($decimals <= 0) {
        return $isNegative ? '-' . $value : $value;
    }

    if (strlen($value) <= $decimals) {
        $value = str_pad($value, $decimals + 1, '0', STR_PAD_LEFT);
    }

    $intPart = substr($value, 0, -$decimals);
    $fracPart = substr($value, -$decimals);
    $fracPart = rtrim($fracPart, '0');
    $formatted = $intPart === '' ? '0' : ltrim($intPart, '0');
    if ($formatted === '') {
        $formatted = '0';
    }
    if ($fracPart !== '') {
        $formatted .= '.' . $fracPart;
    }

    if ($isNegative && $formatted !== '0') {
        $formatted = '-' . $formatted;
    }

    return $formatted;
}

function wallet_tracker_build_message($wallet, $log)
{
    $template = !empty($wallet['customText']) ? $wallet['customText'] : 'New {chain} transaction for {label}: {direction} {amount} {token}. Hash: {hash}';
    $directionText = $log['direction'] === 'in' ? __('received', 'wallet-tracker') : __('sent', 'wallet-tracker');
    $replacements = [
        '{label}' => $wallet['label'] ?: $wallet['address'],
        '{address}' => $wallet['address'],
        '{chain}' => strtoupper($wallet['chain']),
        '{direction}' => $directionText,
        '{amount}' => $log['amount'],
        '{token}' => $log['token'],
        '{hash}' => $log['hash'],
        '{from}' => $log['from'],
        '{to}' => $log['to'],
        '{txUrl}' => $log['txUrl'],
        '{type}' => $log['type'],
    ];
    return strtr($template, $replacements);
}

function wallet_tracker_send_discord_message($webhookUrl, $message)
{
    if (empty($webhookUrl) || empty($message)) {
        return;
    }

    $payload = [
        'content' => $message,
    ];

    wp_remote_post($webhookUrl, [
        'timeout' => 5,
        'blocking' => false,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);
}
