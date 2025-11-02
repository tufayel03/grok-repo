<?php
/**
 * Plugin Name: Grok Multi-Chain Wallet Tracker
 * Description: Track Ethereum and Binance Smart Chain wallets, log transactions, and send Discord alerts when new activity is detected.
 * Version: 1.0.0
 * Author: Grok Solutions
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class Grok_Multi_Chain_Wallet_Tracker
{
    const SETTINGS_OPTION = 'grok_tracker_settings';
    const WALLETS_OPTION = 'grok_tracker_wallets';
    const LAST_SEEN_OPTION = 'grok_tracker_last_seen';
    const CRON_HOOK = 'grok_tracker_check_transactions';

    /** @var array */
    private $notices = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action(self::CRON_HOOK, [$this, 'check_transactions']);
    }

    public static function activate(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'grok_tracker_logs';
        $charset_collate = $wpdb->get_charset_collate();

        add_filter('cron_schedules', [self::class, 'register_cron_schedule']);

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wallet_id VARCHAR(64) NOT NULL,
            chain VARCHAR(10) NOT NULL,
            address VARCHAR(255) NOT NULL,
            label VARCHAR(255) DEFAULT '' NOT NULL,
            tx_hash VARCHAR(100) NOT NULL,
            amount VARCHAR(64) DEFAULT '' NOT NULL,
            from_address VARCHAR(255) DEFAULT '' NOT NULL,
            to_address VARCHAR(255) DEFAULT '' NOT NULL,
            alert_message LONGTEXT,
            tx_timestamp DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY wallet_idx (wallet_id),
            KEY tx_hash (tx_hash)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'five_minutes', self::CRON_HOOK);
        }

        if (!get_option(self::SETTINGS_OPTION)) {
            update_option(self::SETTINGS_OPTION, [
                'etherscan_keys' => [],
                'bscscan_keys'   => [],
                'discord_webhook' => '',
            ]);
        }

        if (!get_option(self::WALLETS_OPTION)) {
            update_option(self::WALLETS_OPTION, []);
        }

        if (!get_option(self::LAST_SEEN_OPTION)) {
            update_option(self::LAST_SEEN_OPTION, []);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function register_cron_schedule(array $schedules): array
    {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every Five Minutes', 'grok-tracker'),
            ];
        }

        return $schedules;
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Wallet Tracker', 'grok-tracker'),
            __('Wallet Tracker', 'grok-tracker'),
            'manage_options',
            'grok-wallet-tracker',
            [$this, 'render_admin_page'],
            'dashicons-chart-line',
            66
        );
    }

    public function handle_form_submissions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['grok_tracker_settings_nonce']) && wp_verify_nonce(sanitize_text_field($_POST['grok_tracker_settings_nonce']), 'grok_tracker_save_settings')) {
            $this->save_settings();
        }

        if (isset($_POST['grok_tracker_wallets_nonce']) && wp_verify_nonce(sanitize_text_field($_POST['grok_tracker_wallets_nonce']), 'grok_tracker_save_wallets')) {
            $this->save_wallets();
        }

        if (isset($_POST['grok_tracker_manual_check']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'grok_tracker_manual_check')) {
            $this->check_transactions();
            $this->add_notice(__('Manual transaction check completed.', 'grok-tracker'));
        }

        if (isset($_POST['grok_tracker_clear_logs']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'grok_tracker_clear_logs')) {
            $this->clear_logs();
            $this->add_notice(__('Transaction logs cleared.', 'grok-tracker'));
        }
    }

    private function add_notice(string $message, string $type = 'success'): void
    {
        $this->notices[] = [
            'message' => $message,
            'type'    => $type,
        ];
    }

    public function render_admin_notices(): void
    {
        foreach ($this->notices as $notice) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                wp_kses_post($notice['message'])
            );
        }
    }

    private function save_settings(): void
    {
        $etherscan_keys = $this->parse_api_keys($_POST['etherscan_keys'] ?? '');
        $bscscan_keys   = $this->parse_api_keys($_POST['bscscan_keys'] ?? '');
        $discord_webhook = isset($_POST['discord_webhook']) ? esc_url_raw($_POST['discord_webhook']) : '';

        update_option(self::SETTINGS_OPTION, [
            'etherscan_keys' => array_values(array_unique($etherscan_keys)),
            'bscscan_keys'   => array_values(array_unique($bscscan_keys)),
            'discord_webhook' => $discord_webhook,
        ]);

        $this->add_notice(__('Settings saved.', 'grok-tracker'));
    }

    private function save_wallets(): void
    {
        $wallets = [];

        $addresses = isset($_POST['wallet_address']) ? (array) $_POST['wallet_address'] : [];
        $chains    = isset($_POST['wallet_chain']) ? (array) $_POST['wallet_chain'] : [];
        $labels    = isset($_POST['wallet_label']) ? (array) $_POST['wallet_label'] : [];
        $messages  = isset($_POST['wallet_message']) ? (array) $_POST['wallet_message'] : [];
        $ids       = isset($_POST['wallet_id']) ? (array) $_POST['wallet_id'] : [];

        foreach ($addresses as $index => $address) {
            $address = trim(sanitize_text_field($address));
            if (empty($address)) {
                continue;
            }

            $wallets[] = [
                'id'      => isset($ids[$index]) && !empty($ids[$index]) ? sanitize_text_field($ids[$index]) : uniqid('wallet_', true),
                'address' => strtolower($address),
                'chain'   => isset($chains[$index]) ? sanitize_text_field($chains[$index]) : 'eth',
                'label'   => isset($labels[$index]) ? sanitize_text_field($labels[$index]) : '',
                'message' => isset($messages[$index]) ? sanitize_text_field($messages[$index]) : '',
            ];
        }

        update_option(self::WALLETS_OPTION, $wallets);
        $this->add_notice(__('Wallet list saved.', 'grok-tracker'));
    }

    private function clear_logs(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'grok_tracker_logs';
        $wpdb->query("TRUNCATE TABLE {$table}");
        update_option(self::LAST_SEEN_OPTION, []);
    }

    public function render_admin_page(): void
    {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $settings = get_option(self::SETTINGS_OPTION, []);
        $wallets = get_option(self::WALLETS_OPTION, []);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wallet Tracker', 'grok-tracker') . '</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = [
            'settings' => __('API & Alerts', 'grok-tracker'),
            'wallets'  => __('Tracked Wallets', 'grok-tracker'),
            'logs'     => __('Transaction Logs', 'grok-tracker'),
        ];

        foreach ($tabs as $tab_key => $label) {
            $class = $active_tab === $tab_key ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf('<a class="%1$s" href="%2$s">%3$s</a>', esc_attr($class), esc_url(add_query_arg('tab', $tab_key)), esc_html($label));
        }
        echo '</h2>';

        switch ($active_tab) {
            case 'wallets':
                $this->render_wallets_tab($wallets);
                break;
            case 'logs':
                $this->render_logs_tab();
                break;
            case 'settings':
            default:
                $this->render_settings_tab($settings);
                break;
        }

        echo '</div>';
    }

    private function render_settings_tab(array $settings): void
    {
        $etherscan_keys = $settings['etherscan_keys'] ?? [];
        $bscscan_keys = $settings['bscscan_keys'] ?? [];
        $discord_webhook = $settings['discord_webhook'] ?? '';
        ?>
        <form method="post">
            <?php wp_nonce_field('grok_tracker_save_settings', 'grok_tracker_settings_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Etherscan API Keys', 'grok-tracker'); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e('Enter one key per line. The first key will be used for Ethereum requests.', 'grok-tracker'); ?></p>
                        <textarea name="etherscan_keys" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $etherscan_keys)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('BscScan API Keys', 'grok-tracker'); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e('Enter one key per line. The first key will be used for BSC requests.', 'grok-tracker'); ?></p>
                        <textarea name="bscscan_keys" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $bscscan_keys)); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Discord Webhook URL', 'grok-tracker'); ?></th>
                    <td>
                        <input type="url" name="discord_webhook" value="<?php echo esc_attr($discord_webhook); ?>" class="regular-text" placeholder="https://discord.com/api/webhooks/..." />
                        <p class="description"><?php esc_html_e('Alerts for new transactions will be posted to this Discord channel.', 'grok-tracker'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'grok-tracker')); ?>
        </form>
        <?php
    }

    private function render_wallets_tab(array $wallets): void
    {
        ?>
        <form method="post">
            <?php wp_nonce_field('grok_tracker_save_wallets', 'grok_tracker_wallets_nonce'); ?>
            <table class="widefat fixed" style="margin-top: 1rem;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Label', 'grok-tracker'); ?></th>
                        <th><?php esc_html_e('Wallet Address', 'grok-tracker'); ?></th>
                        <th><?php esc_html_e('Chain', 'grok-tracker'); ?></th>
                        <th><?php esc_html_e('Discord Message Template', 'grok-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($wallets)) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No wallets tracked yet. Use the empty row below to add one.', 'grok-tracker'); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($wallets as $wallet) : ?>
                        <tr>
                            <td>
                                <input type="text" name="wallet_label[]" value="<?php echo esc_attr($wallet['label']); ?>" class="regular-text" />
                                <input type="hidden" name="wallet_id[]" value="<?php echo esc_attr($wallet['id']); ?>" />
                            </td>
                            <td><input type="text" name="wallet_address[]" value="<?php echo esc_attr($wallet['address']); ?>" class="regular-text" /></td>
                            <td>
                                <select name="wallet_chain[]">
                                    <option value="eth" <?php selected($wallet['chain'], 'eth'); ?>><?php esc_html_e('Ethereum', 'grok-tracker'); ?></option>
                                    <option value="bsc" <?php selected($wallet['chain'], 'bsc'); ?>><?php esc_html_e('BSC', 'grok-tracker'); ?></option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="wallet_message[]" value="<?php echo esc_attr($wallet['message']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Leave blank for default alert.', 'grok-tracker'); ?>" />
                                <p class="description"><?php esc_html_e('Available placeholders: {label}, {address}, {chain}, {hash}, {value}, {symbol}, {from}, {to}, {timestamp}', 'grok-tracker'); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><input type="text" name="wallet_label[]" class="regular-text" placeholder="<?php esc_attr_e('Label', 'grok-tracker'); ?>" /></td>
                        <td><input type="text" name="wallet_address[]" class="regular-text" placeholder="0x..." /></td>
                        <td>
                            <select name="wallet_chain[]">
                                <option value="eth"><?php esc_html_e('Ethereum', 'grok-tracker'); ?></option>
                                <option value="bsc"><?php esc_html_e('BSC', 'grok-tracker'); ?></option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="wallet_message[]" class="regular-text" placeholder="<?php esc_attr_e('Custom Discord message template', 'grok-tracker'); ?>" />
                            <input type="hidden" name="wallet_id[]" value="" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Save Wallets', 'grok-tracker')); ?>
        </form>
        <form method="post" style="margin-top: 1rem;">
            <?php wp_nonce_field('grok_tracker_manual_check'); ?>
            <input type="hidden" name="grok_tracker_manual_check" value="1" />
            <?php submit_button(__('Run Manual Transaction Check', 'grok-tracker'), 'secondary'); ?>
        </form>
        <?php
    }

    private function render_logs_tab(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'grok_tracker_logs';
        $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY tx_timestamp DESC LIMIT 200", ARRAY_A);
        ?>
        <form method="post" style="margin-bottom: 1rem;">
            <?php wp_nonce_field('grok_tracker_clear_logs'); ?>
            <input type="hidden" name="grok_tracker_clear_logs" value="1" />
            <?php submit_button(__('Clear Logs', 'grok-tracker'), 'delete'); ?>
        </form>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Timestamp', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('Label', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('Chain', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('Amount', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('From', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('To', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('Tx Hash', 'grok-tracker'); ?></th>
                    <th><?php esc_html_e('Alert Message', 'grok-tracker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr>
                        <td colspan="8"><?php esc_html_e('No transactions logged yet.', 'grok-tracker'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html(get_date_from_gmt($log['tx_timestamp'], get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                            <td><?php echo esc_html($log['label']); ?></td>
                            <td><?php echo esc_html(strtoupper($log['chain'])); ?></td>
                            <td><?php echo esc_html($log['amount']); ?></td>
                            <td><?php echo esc_html($log['from_address']); ?></td>
                            <td><?php echo esc_html($log['to_address']); ?></td>
                            <td><a href="<?php echo esc_url($this->get_explorer_link($log['chain'], $log['tx_hash'])); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($log['tx_hash']); ?></a></td>
                            <td><?php echo esc_html($log['alert_message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function check_transactions(): void
    {
        $wallets = get_option(self::WALLETS_OPTION, []);
        if (empty($wallets)) {
            return;
        }

        $settings = get_option(self::SETTINGS_OPTION, []);
        $last_seen = get_option(self::LAST_SEEN_OPTION, []);

        foreach ($wallets as $wallet) {
            $chain = $wallet['chain'] ?? 'eth';
            $address = $wallet['address'] ?? '';
            if (empty($address)) {
                continue;
            }

            $api_key = $this->get_api_key_for_chain($chain, $settings);
            if (!$api_key) {
                continue;
            }

            $response = $this->fetch_transactions($chain, $address, $api_key);
            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data['status']) || (string) $data['status'] !== '1' || empty($data['result'])) {
                continue;
            }

            $transactions = $data['result'];
            usort($transactions, static function ($a, $b) {
                return intval($a['timeStamp']) <=> intval($b['timeStamp']);
            });

            $wallet_last = $last_seen[$wallet['id']] ?? ['timestamp' => 0, 'hashes' => []];
            if (isset($wallet_last['hash']) && !isset($wallet_last['hashes'])) {
                $wallet_last['hashes'] = array_filter([$wallet_last['hash']]);
                unset($wallet_last['hash']);
            }
            if (!isset($wallet_last['hashes']) || !is_array($wallet_last['hashes'])) {
                $wallet_last['hashes'] = [];
            }

            foreach ($transactions as $tx) {
                $tx_time = intval($tx['timeStamp']);
                $tx_hash = $tx['hash'];

                if ($tx_time < $wallet_last['timestamp']) {
                    continue;
                }

                if ($tx_time === $wallet_last['timestamp'] && in_array($tx_hash, $wallet_last['hashes'], true)) {
                    continue;
                }

                $this->log_transaction($wallet, $tx);

                if ($tx_time > $wallet_last['timestamp']) {
                    $wallet_last['timestamp'] = $tx_time;
                    $wallet_last['hashes'] = [$tx_hash];
                } else {
                    $wallet_last['hashes'][] = $tx_hash;
                    $wallet_last['hashes'] = array_values(array_unique($wallet_last['hashes']));
                }
            }

            $last_seen[$wallet['id']] = $wallet_last;
        }

        update_option(self::LAST_SEEN_OPTION, $last_seen);
    }

    private function fetch_transactions(string $chain, string $address, string $api_key)
    {
        $base_url = $chain === 'bsc' ? 'https://api.bscscan.com/api' : 'https://api.etherscan.io/api';
        $query = [
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'startblock' => 0,
            'endblock' => 99999999,
            'sort' => 'asc',
            'apikey' => $api_key,
        ];

        $url = add_query_arg($query, $base_url);

        return wp_remote_get($url, [
            'timeout' => 20,
        ]);
    }

    private function log_transaction(array $wallet, array $tx): void
    {
        global $wpdb;

        $chain = $wallet['chain'];
        $symbol = $chain === 'bsc' ? 'BNB' : 'ETH';
        $decimals = 18;
        $value = $this->format_value($tx['value'], $decimals);
        $timestamp = gmdate('Y-m-d H:i:s', intval($tx['timeStamp']));

        $message = $this->build_alert_message($wallet, $tx, $value, $symbol, $timestamp);
        $this->send_discord_alert($message);

        $table = $wpdb->prefix . 'grok_tracker_logs';
        $wpdb->insert(
            $table,
            [
                'wallet_id'     => $wallet['id'],
                'chain'         => $chain,
                'address'       => $wallet['address'],
                'label'         => $wallet['label'],
                'tx_hash'       => $tx['hash'],
                'amount'        => $value . ' ' . $symbol,
                'from_address'  => $tx['from'],
                'to_address'    => $tx['to'],
                'alert_message' => $message,
                'tx_timestamp'  => $timestamp,
                'created_at'    => current_time('mysql', true),
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            ]
        );
    }

    private function build_alert_message(array $wallet, array $tx, string $value, string $symbol, string $timestamp): string
    {
        $template = $wallet['message'] ?: __('New {chain} transaction for {label} ({address}) — {value} transferred. Hash: {hash}', 'grok-tracker');

        $replacements = [
            '{label}'     => $wallet['label'] ?: $wallet['address'],
            '{address}'   => $wallet['address'],
            '{chain}'     => strtoupper($wallet['chain']),
            '{hash}'      => $tx['hash'],
            '{value}'     => $value,
            '{symbol}'    => $symbol,
            '{from}'      => $tx['from'],
            '{to}'        => $tx['to'],
            '{timestamp}' => get_date_from_gmt($timestamp, get_option('date_format') . ' ' . get_option('time_format')),
        ];

        return strtr($template, $replacements);
    }

    private function send_discord_alert(string $message): void
    {
        $settings = get_option(self::SETTINGS_OPTION, []);
        $webhook = $settings['discord_webhook'] ?? '';

        if (empty($webhook)) {
            return;
        }

        wp_remote_post($webhook, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode([
                'content' => $message,
            ]),
            'timeout' => 15,
        ]);
    }

    private function parse_api_keys($raw): array
    {
        $keys = [];

        foreach ((array) $raw as $value) {
            $value = sanitize_textarea_field((string) $value);
            $parts = preg_split('/[\r\n,]+/', $value);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $keys[] = $part;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function get_api_key_for_chain(string $chain, array $settings): ?string
    {
        $key_list = $chain === 'bsc' ? ($settings['bscscan_keys'] ?? []) : ($settings['etherscan_keys'] ?? []);

        if (empty($key_list)) {
            return null;
        }

        if (!is_array($key_list)) {
            $key_list = $this->parse_api_keys($key_list);
        }

        return isset($key_list[0]) ? trim($key_list[0]) : null;
    }

    private function format_value($value, int $decimals): string
    {
        if (!is_scalar($value)) {
            return '0';
        }

        $value = (string) $value;
        if (!ctype_digit($value)) {
            return '0';
        }

        if ($value === '0') {
            return '0';
        }

        $padded = str_pad($value, $decimals + 1, '0', STR_PAD_LEFT);
        $integer = substr($padded, 0, -$decimals);
        $fraction = substr($padded, -$decimals);
        $fraction = rtrim($fraction, '0');

        $formatted = $integer;
        if ($fraction !== '') {
            $formatted .= '.' . $fraction;
        }

        return $formatted;
    }

    private function get_explorer_link(string $chain, string $hash): string
    {
        $base = $chain === 'bsc' ? 'https://bscscan.com/tx/' : 'https://etherscan.io/tx/';
        return $base . rawurlencode($hash);
    }
}

add_filter('cron_schedules', ['Grok_Multi_Chain_Wallet_Tracker', 'register_cron_schedule']);
register_activation_hook(__FILE__, ['Grok_Multi_Chain_Wallet_Tracker', 'activate']);
register_deactivation_hook(__FILE__, ['Grok_Multi_Chain_Wallet_Tracker', 'deactivate']);

global $grok_wallet_tracker;
$grok_wallet_tracker = new Grok_Multi_Chain_Wallet_Tracker();
