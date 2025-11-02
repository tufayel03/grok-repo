<?php
/**
 * Plugin Name: Multi-Chain Wallet Tracker
 * Description: Track Ethereum, BSC, and Solana wallets using respective explorer APIs and dispatch Discord alerts when transactions occur.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCWT_Plugin
{
    const OPTION_KEY = 'mcwt_settings';
    const CRON_HOOK = 'mcwt_poll_event';

    /**
     * Boot plugin hooks.
     */
    public static function init()
    {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedule']);

        add_action('init', [__CLASS__, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [__CLASS__, 'poll_transactions']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_shortcode('mcwt_control_panel', [__CLASS__, 'render_control_panel']);
        add_shortcode('mcwt_transaction_log', [__CLASS__, 'render_transaction_log']);

        add_action('wp_ajax_mcwt_add_wallet', [__CLASS__, 'ajax_add_wallet']);
        add_action('wp_ajax_mcwt_remove_wallet', [__CLASS__, 'ajax_remove_wallet']);
        add_action('wp_ajax_mcwt_update_settings', [__CLASS__, 'ajax_update_settings']);
        add_action('wp_ajax_mcwt_manual_poll', [__CLASS__, 'ajax_manual_poll']);
    }

    /**
     * Default settings for the plugin.
     */
    public static function default_settings()
    {
        return [
            'etherscan_key'      => '',
            'bscscan_key'        => '',
            'solscan_key'        => '',
            'discord_webhook'    => '',
            'discord_message'    => 'New {chain} transaction detected for {label} ({address}). Hash: {hash}',
            'wallets'            => [],
            'last_run'           => 0,
        ];
    }

    /**
     * Retrieve settings merged with defaults.
     */
    public static function get_settings()
    {
        $settings = get_option(self::OPTION_KEY, []);
        return wp_parse_args($settings, self::default_settings());
    }

    /**
     * Persist settings.
     */
    public static function save_settings($settings)
    {
        update_option(self::OPTION_KEY, $settings, false);
    }

    /**
     * Plugin activation tasks.
     */
    public static function activate()
    {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wallet_id varchar(64) NOT NULL,
            wallet_label varchar(255) NOT NULL,
            wallet_address varchar(255) NOT NULL,
            chain varchar(32) NOT NULL,
            tx_hash varchar(255) NOT NULL,
            tx_time datetime NOT NULL,
            direction varchar(16) NOT NULL,
            amount varchar(255) DEFAULT '' NOT NULL,
            explorer_url varchar(255) DEFAULT '' NOT NULL,
            raw_response longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY wallet_id (wallet_id),
            KEY chain (chain)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedule']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'five_minutes', self::CRON_HOOK);
        }

        $settings = self::get_settings();
        self::save_settings($settings);
    }

    /**
     * Remove cron and cleanup on deactivation.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Register custom cron schedule.
     */
    public static function register_cron_schedule($schedules)
    {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every Five Minutes', 'mcwt'),
            ];
        }
        return $schedules;
    }

    /**
     * Register cron event if not already scheduled.
     */
    public static function maybe_schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'five_minutes', self::CRON_HOOK);
        }
    }

    /**
     * Register assets for the front-end control panel.
     */
    public static function enqueue_assets()
    {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        if (has_shortcode($post->post_content, 'mcwt_control_panel')) {
            wp_register_style('mcwt-frontend', false, [], '1.0.0');
            wp_register_script('mcwt-frontend', false, ['jquery'], '1.0.0', true);
            wp_enqueue_style('mcwt-frontend');
            wp_enqueue_script('mcwt-frontend');
            wp_localize_script('mcwt-frontend', 'MCWT', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('mcwt_nonce'),
            ]);
        }
    }

    /**
     * Render the wallet control panel shortcode.
     */
    public static function render_control_panel($atts)
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>' . esc_html__('You do not have permission to view this control panel.', 'mcwt') . '</p>';
        }

        $settings = self::get_settings();
        $wallets = $settings['wallets'];

        ob_start();
        ?>
        <div class="mcwt-panel">
            <h2><?php esc_html_e('Multi-Chain Wallet Tracker Control Panel', 'mcwt'); ?></h2>

            <form class="mcwt-settings-form" method="post">
                <h3><?php esc_html_e('API & Alert Settings', 'mcwt'); ?></h3>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Etherscan API Key', 'mcwt'); ?></label>
                    <input type="text" name="etherscan_key" value="<?php echo esc_attr($settings['etherscan_key']); ?>" />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('BscScan API Key', 'mcwt'); ?></label>
                    <input type="text" name="bscscan_key" value="<?php echo esc_attr($settings['bscscan_key']); ?>" />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Solscan API Token', 'mcwt'); ?></label>
                    <input type="text" name="solscan_key" value="<?php echo esc_attr($settings['solscan_key']); ?>" />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Discord Webhook URL', 'mcwt'); ?></label>
                    <input type="url" name="discord_webhook" value="<?php echo esc_attr($settings['discord_webhook']); ?>" />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Discord Alert Message Template', 'mcwt'); ?></label>
                    <textarea name="discord_message" rows="4"><?php echo esc_textarea($settings['discord_message']); ?></textarea>
                    <p class="description"><?php esc_html_e('Available placeholders: {chain}, {label}, {address}, {hash}, {direction}, {amount}', 'mcwt'); ?></p>
                </div>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'mcwt'); ?></button>
            </form>

            <form class="mcwt-wallet-form" method="post">
                <h3><?php esc_html_e('Add Wallet', 'mcwt'); ?></h3>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Wallet Label', 'mcwt'); ?></label>
                    <input type="text" name="wallet_label" required />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Wallet Address', 'mcwt'); ?></label>
                    <input type="text" name="wallet_address" required />
                </div>
                <div class="mcwt-field">
                    <label><?php esc_html_e('Chain', 'mcwt'); ?></label>
                    <select name="wallet_chain">
                        <option value="eth"><?php esc_html_e('Ethereum', 'mcwt'); ?></option>
                        <option value="bsc"><?php esc_html_e('BSC', 'mcwt'); ?></option>
                        <option value="sol"><?php esc_html_e('Solana', 'mcwt'); ?></option>
                    </select>
                </div>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Add Wallet', 'mcwt'); ?></button>
            </form>

            <div class="mcwt-wallet-list">
                <h3><?php esc_html_e('Tracked Wallets', 'mcwt'); ?></h3>
                <table class="mcwt-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Address', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Chain', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Last Hash', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Actions', 'mcwt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($wallets)) : ?>
                            <?php foreach ($wallets as $wallet_id => $wallet) : ?>
                                <tr data-wallet-id="<?php echo esc_attr($wallet_id); ?>">
                                    <td><?php echo esc_html($wallet['label']); ?></td>
                                    <td class="mcwt-address"><?php echo esc_html($wallet['address']); ?></td>
                                    <td><?php echo esc_html(self::get_chain_name($wallet['chain'])); ?></td>
                                    <td><?php echo !empty($wallet['last_hash']) ? esc_html($wallet['last_hash']) : '&mdash;'; ?></td>
                                    <td><button class="button-link-delete mcwt-remove-wallet" data-wallet-id="<?php echo esc_attr($wallet_id); ?>"><?php esc_html_e('Remove', 'mcwt'); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="5"><?php esc_html_e('No wallets tracked yet.', 'mcwt'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button class="button mcwt-manual-poll"><?php esc_html_e('Run Manual Check', 'mcwt'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render transaction log shortcode.
     */
    public static function render_transaction_log($atts)
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>' . esc_html__('You do not have permission to view the transaction log.', 'mcwt') . '</p>';
        }

        global $wpdb;
        $table_name = self::get_table_name();
        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} ORDER BY tx_time DESC LIMIT %d", 200));

        ob_start();
        ?>
        <div class="mcwt-log">
            <h2><?php esc_html_e('Tracked Transactions', 'mcwt'); ?></h2>
            <table class="mcwt-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Timestamp', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Label', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Chain', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Direction', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Amount', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Transaction', 'mcwt'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs) : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html(get_date_from_gmt($log->tx_time, get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                                <td><?php echo esc_html($log->wallet_label); ?></td>
                                <td><?php echo esc_html(self::get_chain_name($log->chain)); ?></td>
                                <td><?php echo esc_html($log->direction); ?></td>
                                <td><?php echo esc_html($log->amount); ?></td>
                                <td><a href="<?php echo esc_url($log->explorer_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($log->tx_hash); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6"><?php esc_html_e('No transactions logged yet.', 'mcwt'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle wallet addition via AJAX.
     */
    public static function ajax_add_wallet()
    {
        self::verify_ajax_permissions();

        $label = sanitize_text_field($_POST['wallet_label'] ?? '');
        $address = sanitize_text_field($_POST['wallet_address'] ?? '');
        $chain = sanitize_text_field($_POST['wallet_chain'] ?? '');

        if (empty($label) || empty($address) || !in_array($chain, ['eth', 'bsc', 'sol'], true)) {
            wp_send_json_error(['message' => __('Invalid wallet data provided.', 'mcwt')]);
        }

        $settings = self::get_settings();
        $wallet_id = wp_generate_uuid4();
        $settings['wallets'][$wallet_id] = [
            'label'     => $label,
            'address'   => $address,
            'chain'     => $chain,
            'last_hash' => '',
        ];

        self::save_settings($settings);

        wp_send_json_success([
            'wallet_id' => $wallet_id,
            'wallet'    => $settings['wallets'][$wallet_id],
        ]);
    }

    /**
     * Handle wallet removal via AJAX.
     */
    public static function ajax_remove_wallet()
    {
        self::verify_ajax_permissions();

        $wallet_id = sanitize_text_field($_POST['wallet_id'] ?? '');
        if (empty($wallet_id)) {
            wp_send_json_error(['message' => __('Missing wallet identifier.', 'mcwt')]);
        }

        $settings = self::get_settings();
        if (!isset($settings['wallets'][$wallet_id])) {
            wp_send_json_error(['message' => __('Wallet not found.', 'mcwt')]);
        }

        unset($settings['wallets'][$wallet_id]);
        self::save_settings($settings);

        wp_send_json_success();
    }

    /**
     * Handle settings update via AJAX.
     */
    public static function ajax_update_settings()
    {
        self::verify_ajax_permissions();

        $settings = self::get_settings();
        $settings['etherscan_key'] = sanitize_text_field($_POST['etherscan_key'] ?? '');
        $settings['bscscan_key'] = sanitize_text_field($_POST['bscscan_key'] ?? '');
        $settings['solscan_key'] = sanitize_text_field($_POST['solscan_key'] ?? '');
        $settings['discord_webhook'] = esc_url_raw($_POST['discord_webhook'] ?? '');
        $settings['discord_message'] = wp_kses_post($_POST['discord_message'] ?? self::default_settings()['discord_message']);

        self::save_settings($settings);
        wp_send_json_success(['settings' => $settings]);
    }

    /**
     * Manual polling trigger via AJAX.
     */
    public static function ajax_manual_poll()
    {
        self::verify_ajax_permissions();
        self::poll_transactions(true);
        wp_send_json_success(['message' => __('Manual check completed.', 'mcwt')]);
    }

    /**
     * Ensure AJAX requests are authorized.
     */
    protected static function verify_ajax_permissions()
    {
        check_ajax_referer('mcwt_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mcwt')], 403);
        }
    }

    /**
     * Poll wallets for new transactions.
     */
    public static function poll_transactions($manual = false)
    {
        $settings = self::get_settings();
        if (empty($settings['wallets'])) {
            return;
        }

        foreach ($settings['wallets'] as $wallet_id => $wallet) {
            $transactions = self::fetch_transactions($wallet, $settings);
            if (empty($transactions)) {
                continue;
            }

            $last_hash = $wallet['last_hash'] ?? '';
            $new_transactions = [];

            foreach ($transactions as $transaction) {
                if ($transaction['hash'] === $last_hash) {
                    break;
                }
                $new_transactions[] = $transaction;
            }

            if (empty($new_transactions) && !empty($last_hash)) {
                continue;
            }

            $new_transactions = array_reverse($new_transactions);
            foreach ($new_transactions as $transaction) {
                self::log_transaction($wallet_id, $wallet, $transaction);
                self::send_discord_notification($wallet, $transaction, $settings);
                $last_hash = $transaction['hash'];
            }

            $settings['wallets'][$wallet_id]['last_hash'] = $last_hash ?: ($transactions[0]['hash'] ?? '');
        }

        $settings['last_run'] = time();
        self::save_settings($settings);
    }

    /**
     * Fetch most recent transactions for a wallet.
     */
    protected static function fetch_transactions($wallet, $settings)
    {
        switch ($wallet['chain']) {
            case 'eth':
                return self::fetch_etherscan_transactions($wallet['address'], $settings['etherscan_key']);
            case 'bsc':
                return self::fetch_bscscan_transactions($wallet['address'], $settings['bscscan_key']);
            case 'sol':
                return self::fetch_solscan_transactions($wallet['address'], $settings['solscan_key']);
            default:
                return [];
        }
    }

    protected static function fetch_etherscan_transactions($address, $api_key)
    {
        if (empty($api_key)) {
            return [];
        }

        $url = add_query_arg([
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'sort' => 'desc',
            'apikey' => $api_key,
        ], 'https://api.etherscan.io/api');

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['result']) || !is_array($body['result'])) {
            return [];
        }

        $transactions = [];
        foreach ($body['result'] as $item) {
            $transactions[] = [
                'hash'      => $item['hash'],
                'timestamp' => (int) $item['timeStamp'],
                'from'      => strtolower($item['from']),
                'to'        => strtolower($item['to']),
                'value'     => self::format_value($item['value'], 18),
                'explorer'  => 'https://etherscan.io/tx/' . $item['hash'],
            ];
        }

        return $transactions;
    }

    protected static function fetch_bscscan_transactions($address, $api_key)
    {
        if (empty($api_key)) {
            return [];
        }

        $url = add_query_arg([
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'sort' => 'desc',
            'apikey' => $api_key,
        ], 'https://api.bscscan.com/api');

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['result']) || !is_array($body['result'])) {
            return [];
        }

        $transactions = [];
        foreach ($body['result'] as $item) {
            $transactions[] = [
                'hash'      => $item['hash'],
                'timestamp' => (int) $item['timeStamp'],
                'from'      => strtolower($item['from']),
                'to'        => strtolower($item['to']),
                'value'     => self::format_value($item['value'], 18),
                'explorer'  => 'https://bscscan.com/tx/' . $item['hash'],
            ];
        }

        return $transactions;
    }

    protected static function fetch_solscan_transactions($address, $api_key)
    {
        $url = add_query_arg([
            'address' => $address,
            'limit'   => 20,
        ], 'https://public-api.solscan.io/account/transactions');

        $args = [
            'timeout' => 20,
        ];

        if (!empty($api_key)) {
            $args['headers'] = [
                'token' => $api_key,
            ];
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return [];
        }

        $transactions = [];
        foreach ($body as $item) {
            $hash = isset($item['txHash']) ? $item['txHash'] : '';
            if (!$hash) {
                continue;
            }
            $from = isset($item['src']) ? strtolower($item['src']) : '';
            $to = isset($item['dst']) ? strtolower($item['dst']) : '';
            $lamports = isset($item['lamport']) ? $item['lamport'] : 0;
            $transactions[] = [
                'hash'      => $hash,
                'timestamp' => isset($item['blockTime']) ? (int) $item['blockTime'] : time(),
                'from'      => $from,
                'to'        => $to,
                'value'     => self::format_value($lamports, 9),
                'explorer'  => 'https://solscan.io/tx/' . $hash,
            ];
        }

        return $transactions;
    }

    /**
     * Convert integer values to decimal string using precision.
     */
    protected static function format_value($value, $decimals)
    {
        if ($value === '' || $value === null) {
            return '0';
        }
        $value = (string) $value;
        if ($value === '0') {
            return '0';
        }

        $negative = false;
        if (strpos($value, '-') === 0) {
            $negative = true;
            $value = substr($value, 1);
        }

        $value = ltrim($value, '0');
        if ($value === '') {
            $value = '0';
        }

        if (strlen($value) <= $decimals) {
            $value = str_pad($value, $decimals, '0', STR_PAD_LEFT);
            $formatted = '0.' . $value;
        } else {
            $formatted = substr($value, 0, strlen($value) - $decimals) . '.' . substr($value, -$decimals);
        }

        $formatted = rtrim(rtrim($formatted, '0'), '.');

        if ($negative) {
            $formatted = '-' . $formatted;
        }

        return $formatted;
    }

    /**
     * Log transaction to database.
     */
    protected static function log_transaction($wallet_id, $wallet, $transaction)
    {
        global $wpdb;
        $table_name = self::get_table_name();

        $direction = (strcasecmp($transaction['to'], strtolower($wallet['address'])) === 0) ? 'IN' : 'OUT';

        $wpdb->insert($table_name, [
            'wallet_id'      => $wallet_id,
            'wallet_label'   => $wallet['label'],
            'wallet_address' => $wallet['address'],
            'chain'          => $wallet['chain'],
            'tx_hash'        => $transaction['hash'],
            'tx_time'        => gmdate('Y-m-d H:i:s', $transaction['timestamp']),
            'direction'      => $direction,
            'amount'         => $transaction['value'],
            'explorer_url'   => $transaction['explorer'],
            'raw_response'   => wp_json_encode($transaction),
            'created_at'     => current_time('mysql', true),
        ]);
    }

    /**
     * Send alert to Discord webhook.
     */
    protected static function send_discord_notification($wallet, $transaction, $settings)
    {
        if (empty($settings['discord_webhook'])) {
            return;
        }

        $replacements = [
            '{chain}'     => self::get_chain_name($wallet['chain']),
            '{label}'     => $wallet['label'],
            '{address}'   => $wallet['address'],
            '{hash}'      => $transaction['hash'],
            '{direction}' => (strcasecmp($transaction['to'], strtolower($wallet['address'])) === 0) ? 'IN' : 'OUT',
            '{amount}'    => $transaction['value'],
        ];

        $message = strtr($settings['discord_message'], $replacements);
        $payload = [
            'content' => $message,
        ];

        wp_remote_post($settings['discord_webhook'], [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);
    }

    /**
     * Retrieve human-friendly chain name.
     */
    protected static function get_chain_name($chain)
    {
        switch ($chain) {
            case 'eth':
                return __('Ethereum', 'mcwt');
            case 'bsc':
                return __('BSC', 'mcwt');
            case 'sol':
                return __('Solana', 'mcwt');
            default:
                return strtoupper($chain);
        }
    }

    /**
     * Determine database table name.
     */
    protected static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mcwt_transaction_logs';
    }
}

MCWT_Plugin::init();

/**
 * Provide inline assets when dedicated files are not available.
 */
add_action('wp_enqueue_scripts', function () {
    $handle = 'mcwt-frontend';
    if (wp_script_is($handle, 'enqueued')) {
        $inline_js = <<<'JS'
        (function ($) {
            function showNotice(message, type) {
                const notice = $('<div class="mcwt-notice mcwt-notice-' + type + '">' + message + '</div>');
                $('.mcwt-panel').prepend(notice);
                setTimeout(function () { notice.fadeOut(400, function () { $(this).remove(); }); }, 5000);
            }

            $('.mcwt-settings-form').on('submit', function (event) {
                event.preventDefault();
                const data = $(this).serializeArray();
                data.push({ name: 'action', value: 'mcwt_update_settings' });
                data.push({ name: 'nonce', value: MCWT.nonce });
                $.post(MCWT.ajaxUrl, data)
                    .done(function () { showNotice('Settings saved successfully.', 'success'); })
                    .fail(function () { showNotice('Failed to save settings.', 'error'); });
            });

            $('.mcwt-wallet-form').on('submit', function (event) {
                event.preventDefault();
                const $form = $(this);
                const data = $form.serializeArray();
                data.push({ name: 'action', value: 'mcwt_add_wallet' });
                data.push({ name: 'nonce', value: MCWT.nonce });
                $.post(MCWT.ajaxUrl, data)
                    .done(function (response) {
                        if (!response.success) {
                            showNotice(response.data.message || 'Unable to add wallet.', 'error');
                            return;
                        }
                        const wallet = response.data.wallet;
                        const row = '<tr data-wallet-id="' + response.data.wallet_id + '">' +
                            '<td>' + wallet.label + '</td>' +
                            '<td class="mcwt-address">' + wallet.address + '</td>' +
                            '<td>' + wallet.chain.toUpperCase() + '</td>' +
                            '<td>&mdash;</td>' +
                            '<td><button class="button-link-delete mcwt-remove-wallet" data-wallet-id="' + response.data.wallet_id + '">Remove</button></td>' +
                            '</tr>';
                        $('.mcwt-wallet-list tbody').append(row);
                        $form[0].reset();
                        showNotice('Wallet added successfully.', 'success');
                    })
                    .fail(function () { showNotice('Failed to add wallet.', 'error'); });
            });

            $(document).on('click', '.mcwt-remove-wallet', function (event) {
                event.preventDefault();
                const walletId = $(this).data('wallet-id');
                const data = {
                    action: 'mcwt_remove_wallet',
                    wallet_id: walletId,
                    nonce: MCWT.nonce,
                };
                $.post(MCWT.ajaxUrl, data)
                    .done(function (response) {
                        if (!response.success) {
                            showNotice(response.data.message || 'Unable to remove wallet.', 'error');
                            return;
                        }
                        $('tr[data-wallet-id="' + walletId + '"]').remove();
                        showNotice('Wallet removed.', 'success');
                    })
                    .fail(function () { showNotice('Failed to remove wallet.', 'error'); });
            });

            $('.mcwt-manual-poll').on('click', function (event) {
                event.preventDefault();
                const data = {
                    action: 'mcwt_manual_poll',
                    nonce: MCWT.nonce,
                };
                $.post(MCWT.ajaxUrl, data)
                    .done(function (response) {
                        showNotice(response.data.message || 'Manual check completed.', 'success');
                    })
                    .fail(function () {
                        showNotice('Failed to run manual check.', 'error');
                    });
            });
        })(jQuery);
        JS;
        wp_add_inline_script($handle, $inline_js);

        $inline_css = <<<'CSS'
        .mcwt-panel, .mcwt-log {
            border: 1px solid #ccd0d4;
            padding: 20px;
            border-radius: 4px;
            background: #fff;
            margin-bottom: 30px;
        }
        .mcwt-panel h2, .mcwt-log h2 {
            margin-top: 0;
        }
        .mcwt-field {
            margin-bottom: 15px;
        }
        .mcwt-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .mcwt-field input[type="text"],
        .mcwt-field input[type="url"],
        .mcwt-field textarea,
        .mcwt-field select {
            width: 100%;
            max-width: 480px;
        }
        .mcwt-table {
            width: 100%;
            border-collapse: collapse;
        }
        .mcwt-table th,
        .mcwt-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e1e1e1;
        }
        .mcwt-notice {
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .mcwt-notice-success {
            background: #ecf7ed;
            border: 1px solid #46b450;
        }
        .mcwt-notice-error {
            background: #fbeaea;
            border: 1px solid #dc3232;
        }
        .mcwt-address {
            font-family: monospace;
        }
        CSS;
        wp_add_inline_style($handle, $inline_css);
    }
});
