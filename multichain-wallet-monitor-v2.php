<?php
/**
 * Plugin Name: Multi-Chain Wallet Monitor
 * Description: Track ETH, BSC, and SOL wallets, log transactions, and send Discord alerts from a front-end control panel.
 * Version: 1.2.0
 * Author: Grok AI
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCWT_MultiChain_Wallet_Monitor {
    const OPTION_WALLETS = 'mcwt_wallets';
    const OPTION_KEYS = 'mcwt_api_keys';
    const OPTION_META = 'mcwt_wallet_meta';
    const OPTION_LOG = 'mcwt_tx_log';
    const OPTION_LAST_RUN = 'mcwt_last_poll';
    const CRON_HOOK = 'mcwt_poll_transactions';
    const CRON_SCHEDULE = 'mcwt_custom_interval';
    const DEFAULT_INTERVAL = 5; // minutes

    public function __construct() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_schedule']);
        add_action('init', [$this, 'maybe_run_poll'], 20);
        add_action(self::CRON_HOOK, [$this, 'poll_transactions']);

        add_shortcode('wallet_tracker', [$this, 'render_control_panel']);
        add_shortcode('wallet_latest_tx', [$this, 'render_transaction_log']);

        add_action('admin_post_mcwt_add_wallet', [$this, 'handle_add_wallet']);
        add_action('admin_post_mcwt_delete_wallet', [$this, 'handle_delete_wallet']);
        add_action('admin_post_mcwt_update_wallet', [$this, 'handle_update_wallet']);
        add_action('admin_post_mcwt_update_keys', [$this, 'handle_update_keys']);
        add_action('admin_post_mcwt_update_message', [$this, 'handle_update_message']);
    }

    public static function activate() {
        add_option(self::OPTION_WALLETS, []);
        add_option(self::OPTION_KEYS, [
            'etherscan' => '',
            'bscscan' => '',
            'solscan' => '',
            'discord_webhook' => '',
            'discord_message' => 'New transaction for {label} on {chain}: {hash}',
            'poll_interval' => self::DEFAULT_INTERVAL,
        ]);
        add_option(self::OPTION_META, []);
        add_option(self::OPTION_LOG, []);
        add_option(self::OPTION_LAST_RUN, 0);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function register_cron_schedules($schedules) {
        $interval = $this->get_poll_interval_minutes();
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => max(60, $interval * MINUTE_IN_SECONDS),
            'display' => sprintf(__('Every %d minutes', 'mcwt'), (int) $interval),
        ];
        return $schedules;
    }

    public function ensure_schedule() {
        $this->maybe_upgrade_options();
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : false;
        if ($event && isset($event->schedule) && self::CRON_SCHEDULE !== $event->schedule) {
            $this->reschedule_polling();
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public function maybe_run_poll() {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $last_run = (int) get_option(self::OPTION_LAST_RUN, 0);
        $interval = $this->get_poll_interval_minutes() * MINUTE_IN_SECONDS;
        if ($last_run && (time() - $last_run) < $interval) {
            return;
        }

        $this->poll_transactions();
    }

    private function maybe_upgrade_options() {
        $keys = get_option(self::OPTION_KEYS);
        if (!is_array($keys)) {
            return;
        }

        $updated = false;
        if (!isset($keys['poll_interval']) || (int) $keys['poll_interval'] < 1) {
            $keys['poll_interval'] = self::DEFAULT_INTERVAL;
            $updated = true;
        }

        if ($updated) {
            update_option(self::OPTION_KEYS, $keys);
        }

        $wallets = get_option(self::OPTION_WALLETS, []);
        if (is_array($wallets)) {
            $wallets_updated = false;
            foreach ($wallets as &$wallet) {
                if (is_array($wallet) && !isset($wallet['message'])) {
                    $wallet['message'] = '';
                    $wallets_updated = true;
                }
            }
            unset($wallet);
            if ($wallets_updated) {
                update_option(self::OPTION_WALLETS, $wallets);
            }
        }

        if (false === get_option(self::OPTION_LAST_RUN, false)) {
            add_option(self::OPTION_LAST_RUN, 0);
        }
    }

    public function render_control_panel() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>' . esc_html__('You do not have permission to manage wallets.', 'mcwt') . '</p>';
        }

        $wallets = $this->get_wallets();
        $search = isset($_GET['mcwt_search']) ? sanitize_text_field(wp_unslash($_GET['mcwt_search'])) : '';
        $page = isset($_GET['mcwt_page']) ? max(1, (int) $_GET['mcwt_page']) : 1;
        $per_page = 25;

        if ($search !== '') {
            $wallets = array_values(array_filter($wallets, static function ($wallet) use ($search) {
                return false !== stripos($wallet['label'], $search);
            }));
        }

        $total = count($wallets);
        $pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $page = min($page, $pages);
        $offset = ($page - 1) * $per_page;
        $display_wallets = array_slice($wallets, $offset, $per_page);

        $api_keys = $this->get_api_keys();
        $default_message = $api_keys['discord_message'];

        ob_start();
        ?>
        <div class="mcwt-control-panel">
            <h2><?php esc_html_e('Wallet Control Panel', 'mcwt'); ?></h2>
            <?php $this->render_notices(); ?>

            <section class="mcwt-add-wallet">
                <h3><?php esc_html_e('Add Wallet', 'mcwt'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mcwt_add_wallet'); ?>
                    <input type="hidden" name="action" value="mcwt_add_wallet" />
                    <p>
                        <label><?php esc_html_e('Label', 'mcwt'); ?><br />
                            <input type="text" name="mcwt_label" required />
                        </label>
                    </p>
                    <p>
                        <label><?php esc_html_e('Wallet Address', 'mcwt'); ?><br />
                            <input type="text" name="mcwt_address" required />
                        </label>
                    </p>
                    <p>
                        <label><?php esc_html_e('Chain', 'mcwt'); ?><br />
                            <select name="mcwt_chain">
                                <option value="eth"><?php esc_html_e('Ethereum', 'mcwt'); ?></option>
                                <option value="bsc"><?php esc_html_e('BSC', 'mcwt'); ?></option>
                                <option value="sol"><?php esc_html_e('Solana', 'mcwt'); ?></option>
                            </select>
                        </label>
                    </p>
                    <p>
                        <label><?php esc_html_e('Custom Discord Message', 'mcwt'); ?><br />
                            <textarea name="mcwt_message" rows="2" placeholder="<?php echo esc_attr__('Leave empty to use the default template.', 'mcwt'); ?>"></textarea>
                        </label>
                        <span class="description"><?php esc_html_e('Available tags: {label}, {address}, {hash}, {chain}, {amount}. Leave blank to use the default template.', 'mcwt'); ?></span>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Add Wallet', 'mcwt'); ?></button>
                    </p>
                </form>
            </section>

            <section class="mcwt-search">
                <h3><?php esc_html_e('Search Wallets', 'mcwt'); ?></h3>
                <form method="get">
                    <input type="text" name="mcwt_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search by label', 'mcwt'); ?>" />
                    <button type="submit" class="button"><?php esc_html_e('Search', 'mcwt'); ?></button>
                </form>
            </section>

            <section class="mcwt-wallet-table">
                <h3><?php esc_html_e('Tracked Wallets', 'mcwt'); ?></h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Address', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Chain', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Message', 'mcwt'); ?></th>
                            <th><?php esc_html_e('Actions', 'mcwt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($display_wallets)) : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No wallets found.', 'mcwt'); ?></td>
                        </tr>
                    <?php else :
                        foreach ($display_wallets as $wallet) :
                            $wallet_message = isset($wallet['message']) ? $wallet['message'] : '';
                            $message_preview = $wallet_message ? wp_trim_words($wallet_message, 16, '&hellip;') : wp_trim_words($default_message, 16, '&hellip;');
                            ?>
                            <tr>
                                <td><?php echo esc_html($wallet['label']); ?></td>
                                <td><code><?php echo esc_html($wallet['address']); ?></code></td>
                                <td><?php echo esc_html(strtoupper($wallet['chain'])); ?></td>
                                <td><?php echo esc_html($message_preview); ?></td>
                                <td>
                                    <div class="mcwt-wallet-actions">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mcwt-update-wallet">
                                            <?php wp_nonce_field('mcwt_update_wallet'); ?>
                                            <input type="hidden" name="action" value="mcwt_update_wallet" />
                                            <input type="hidden" name="mcwt_address" value="<?php echo esc_attr($wallet['address']); ?>" />
                                            <input type="hidden" name="mcwt_chain" value="<?php echo esc_attr($wallet['chain']); ?>" />
                                            <p>
                                                <label><?php esc_html_e('Label', 'mcwt'); ?><br />
                                                    <input type="text" name="mcwt_label" value="<?php echo esc_attr($wallet['label']); ?>" required />
                                                </label>
                                            </p>
                                            <p>
                                                <label><?php esc_html_e('Discord Message', 'mcwt'); ?><br />
                                                    <textarea name="mcwt_message" rows="3" placeholder="<?php echo esc_attr__('Leave empty to use the default template.', 'mcwt'); ?>"><?php echo esc_textarea($wallet_message); ?></textarea>
                                                </label>
                                                <span class="description"><?php esc_html_e('Available tags: {label}, {address}, {hash}, {chain}, {amount}. Leave blank to use the default template.', 'mcwt'); ?></span>
                                            </p>
                                            <p>
                                                <button type="submit" class="button button-primary button-small"><?php esc_html_e('Save', 'mcwt'); ?></button>
                                            </p>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remove this wallet?', 'mcwt')); ?>');">
                                            <?php wp_nonce_field('mcwt_delete_wallet'); ?>
                                            <input type="hidden" name="action" value="mcwt_delete_wallet" />
                                            <input type="hidden" name="mcwt_address" value="<?php echo esc_attr($wallet['address']); ?>" />
                                            <input type="hidden" name="mcwt_chain" value="<?php echo esc_attr($wallet['chain']); ?>" />
                        
                                            <button type="submit" class="button button-secondary button-small"><?php esc_html_e('Remove', 'mcwt'); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php
                        endforeach;
                    endif; ?>
                    </tbody>
                </table>
                <?php if ($pages > 1) : ?>
                    <div class="mcwt-pagination">
                        <?php for ($i = 1; $i <= $pages; $i++) :
                            $url = add_query_arg([
                                'mcwt_page' => $i,
                                'mcwt_search' => $search,
                            ]);
                            ?>
                            <a class="button <?php echo $i === $page ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($i); ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="mcwt-api-keys">
                <h3><?php esc_html_e('API Keys & Alerts', 'mcwt'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mcwt_update_keys'); ?>
                    <input type="hidden" name="action" value="mcwt_update_keys" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="mcwt_etherscan_key"><?php esc_html_e('Etherscan API Key', 'mcwt'); ?></label></th>
                            <td><input type="text" id="mcwt_etherscan_key" name="mcwt_keys[etherscan]" value="<?php echo esc_attr($api_keys['etherscan']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mcwt_bscscan_key"><?php esc_html_e('BscScan API Key', 'mcwt'); ?></label></th>
                            <td><input type="text" id="mcwt_bscscan_key" name="mcwt_keys[bscscan]" value="<?php echo esc_attr($api_keys['bscscan']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mcwt_solscan_key"><?php esc_html_e('Solscan API Token', 'mcwt'); ?></label></th>
                            <td><input type="text" id="mcwt_solscan_key" name="mcwt_keys[solscan]" value="<?php echo esc_attr($api_keys['solscan']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mcwt_discord_webhook"><?php esc_html_e('Discord Webhook URL', 'mcwt'); ?></label></th>
                            <td><input type="url" id="mcwt_discord_webhook" name="mcwt_keys[discord_webhook]" value="<?php echo esc_attr($api_keys['discord_webhook']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mcwt_poll_interval"><?php esc_html_e('Polling Interval (minutes)', 'mcwt'); ?></label></th>
                            <td>
                                <input type="number" id="mcwt_poll_interval" name="mcwt_keys[poll_interval]" value="<?php echo esc_attr($api_keys['poll_interval']); ?>" class="small-text" min="1" max="1440" />
                                <p class="description"><?php esc_html_e('How often to check the explorers for new transactions.', 'mcwt'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Save Keys', 'mcwt'); ?></button></p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mcwt_update_message'); ?>
                    <input type="hidden" name="action" value="mcwt_update_message" />
                    <p>
                        <label for="mcwt_discord_message"><?php esc_html_e('Discord Message Template', 'mcwt'); ?></label><br />
                        <textarea id="mcwt_discord_message" name="mcwt_message" rows="3" class="large-text"><?php echo esc_textarea($api_keys['discord_message']); ?></textarea>
                    </p>
                    <p class="description"><?php esc_html_e('Available tags: {label}, {address}, {hash}, {chain}, {amount}', 'mcwt'); ?></p>
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e('Save Message', 'mcwt'); ?></button></p>
                </form>
            </section>
        </div>
        <style>
            .mcwt-control-panel { max-width: 900px; margin: 2rem auto; }
            .mcwt-control-panel h2 { margin-bottom: 1rem; }
            .mcwt-control-panel section { margin-bottom: 2rem; }
            .mcwt-pagination { margin-top: 1rem; display: flex; gap: .5rem; }
            .mcwt-pagination .button { text-decoration: none; }
            .mcwt-wallet-actions { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; }
            .mcwt-wallet-actions form { margin: 0; }
            .mcwt-update-wallet input[type="text"], .mcwt-update-wallet textarea { width: 100%; }
            .mcwt-update-wallet textarea { resize: vertical; }
            .mcwt-wallet-actions .button-small { margin-top: .5rem; }
            .mcwt-wallet-actions .description, .mcwt-add-wallet .description { display: block; font-size: 12px; color: #666; margin-top: .3rem; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function render_transaction_log() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>' . esc_html__('You do not have permission to view logs.', 'mcwt') . '</p>';
        }

        $log = get_option(self::OPTION_LOG, []);

        ob_start();
        ?>
        <div class="mcwt-transaction-log">
            <h2><?php esc_html_e('Latest Transactions', 'mcwt'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Label', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Chain', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Amount', 'mcwt'); ?></th>
                        <th><?php esc_html_e('Hash', 'mcwt'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No transactions logged yet.', 'mcwt'); ?></td></tr>
                <?php else :
                    foreach ($log as $entry) :
                        ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
                            <td><?php echo esc_html($entry['label']); ?></td>
                            <td><?php echo esc_html(strtoupper($entry['chain'])); ?></td>
                            <td><?php echo esc_html($entry['amount']); ?></td>
                            <td><a href="<?php echo esc_url($entry['explorer']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($entry['hash']); ?></a></td>
                        </tr>
                    <?php
                    endforeach;
                endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            .mcwt-transaction-log { max-width: 900px; margin: 2rem auto; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function handle_add_wallet() {
        $this->verify_permissions('mcwt_add_wallet', 'manage_options');

        $label = isset($_POST['mcwt_label']) ? sanitize_text_field(wp_unslash($_POST['mcwt_label'])) : '';
        $address = isset($_POST['mcwt_address']) ? sanitize_text_field(wp_unslash($_POST['mcwt_address'])) : '';
        $chain = isset($_POST['mcwt_chain']) ? sanitize_key(wp_unslash($_POST['mcwt_chain'])) : '';
        $message = isset($_POST['mcwt_message']) ? sanitize_textarea_field(wp_unslash($_POST['mcwt_message'])) : '';

        if ($label && $address && in_array($chain, ['eth', 'bsc', 'sol'], true)) {
            $wallets = $this->get_wallets();
            $wallets[] = [
                'label' => $label,
                'address' => $address,
                'chain' => $chain,
                'message' => $message,
            ];
            update_option(self::OPTION_WALLETS, $wallets);
            $this->add_notice(__('Wallet added.', 'mcwt'));
        } else {
            $this->add_notice(__('Invalid wallet data.', 'mcwt'), 'error');
        }

        $this->redirect_back();
    }

    public function handle_delete_wallet() {
        $this->verify_permissions('mcwt_delete_wallet', 'manage_options');

        $address = isset($_POST['mcwt_address']) ? sanitize_text_field(wp_unslash($_POST['mcwt_address'])) : '';
        $chain = isset($_POST['mcwt_chain']) ? sanitize_key(wp_unslash($_POST['mcwt_chain'])) : '';

        if (!$address || !in_array($chain, ['eth', 'bsc', 'sol'], true)) {
            $this->add_notice(__('Invalid wallet selection.', 'mcwt'), 'error');
            $this->redirect_back();
        }

        $wallets = array_values(array_filter($this->get_wallets(), static function ($wallet) use ($address, $chain) {
            return !($wallet['address'] === $address && $wallet['chain'] === $chain);
        }));
        update_option(self::OPTION_WALLETS, $wallets);

        $meta = get_option(self::OPTION_META, []);
        $key = $this->wallet_meta_key($address, $chain);
        if (isset($meta[$key])) {
            unset($meta[$key]);
            update_option(self::OPTION_META, $meta);
        }

        $this->add_notice(__('Wallet removed.', 'mcwt'));
        $this->redirect_back();
    }

    public function handle_update_wallet() {
        $this->verify_permissions('mcwt_update_wallet', 'manage_options');

        $address = isset($_POST['mcwt_address']) ? sanitize_text_field(wp_unslash($_POST['mcwt_address'])) : '';
        $chain = isset($_POST['mcwt_chain']) ? sanitize_key(wp_unslash($_POST['mcwt_chain'])) : '';
        $label = isset($_POST['mcwt_label']) ? sanitize_text_field(wp_unslash($_POST['mcwt_label'])) : '';
        $message = isset($_POST['mcwt_message']) ? sanitize_textarea_field(wp_unslash($_POST['mcwt_message'])) : '';

        if (!$address || !$label || !in_array($chain, ['eth', 'bsc', 'sol'], true)) {
            $this->add_notice(__('Invalid wallet update request.', 'mcwt'), 'error');
            $this->redirect_back();
        }

        $wallets = $this->get_wallets();
        $updated = false;
        foreach ($wallets as &$wallet) {
            if ($wallet['address'] === $address && $wallet['chain'] === $chain) {
                $wallet['label'] = $label;
                $wallet['message'] = $message;
                $updated = true;
                break;
            }
        }
        unset($wallet);

        if ($updated) {
            update_option(self::OPTION_WALLETS, $wallets);
            $this->add_notice(__('Wallet updated.', 'mcwt'));
        } else {
            $this->add_notice(__('Wallet could not be updated.', 'mcwt'), 'error');
        }

        $this->redirect_back();
    }

    public function handle_update_keys() {
        $this->verify_permissions('mcwt_update_keys', 'manage_options');

        $keys = isset($_POST['mcwt_keys']) ? (array) wp_unslash($_POST['mcwt_keys']) : [];
        $current = $this->get_api_keys();
        foreach (['etherscan', 'bscscan', 'solscan'] as $key) {
            $current[$key] = isset($keys[$key]) ? sanitize_text_field($keys[$key]) : '';
        }
        $current['discord_webhook'] = isset($keys['discord_webhook']) ? esc_url_raw($keys['discord_webhook']) : '';

        $interval = isset($keys['poll_interval']) ? (int) $keys['poll_interval'] : self::DEFAULT_INTERVAL;
        $interval = max(1, min(1440, $interval));
        $current['poll_interval'] = $interval;

        update_option(self::OPTION_KEYS, $current);
        $this->reschedule_polling($interval);

        $this->add_notice(__('Settings saved.', 'mcwt'));
        $this->redirect_back();
    }

    public function handle_update_message() {
        $this->verify_permissions('mcwt_update_message', 'manage_options');

        $message = isset($_POST['mcwt_message']) ? wp_kses_post(wp_unslash($_POST['mcwt_message'])) : '';
        $keys = $this->get_api_keys();
        $keys['discord_message'] = $message ?: 'New transaction for {label} on {chain}: {hash}';
        update_option(self::OPTION_KEYS, $keys);

        $this->add_notice(__('Message template saved.', 'mcwt'));
        $this->redirect_back();
    }

    private function verify_permissions($action, $capability) {
        if (!is_user_logged_in() || !current_user_can($capability)) {
            wp_die(__('Unauthorized', 'mcwt'));
        }
        check_admin_referer($action);
    }

    private function redirect_back() {
        $referer = wp_get_referer();
        wp_safe_redirect($referer ? $referer : home_url());
        exit;
    }

    private function add_notice($message, $type = 'success') {
        $notices = get_transient('mcwt_notices');
        if (!is_array($notices)) {
            $notices = [];
        }
        $notices[] = [
            'message' => $message,
            'type' => $type,
        ];
        set_transient('mcwt_notices', $notices, 60);
    }

    private function render_notices() {
        $notices = get_transient('mcwt_notices');
        if (empty($notices)) {
            return;
        }
        delete_transient('mcwt_notices');
        echo '<div class="mcwt-notices">';
        foreach ($notices as $notice) {
            printf('<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($notice['type'] === 'error' ? 'error' : 'success'),
                esc_html($notice['message'])
            );
        }
        echo '</div>';
    }

    private function get_wallets() {
        $wallets = get_option(self::OPTION_WALLETS, []);
        if (!is_array($wallets)) {
            return [];
        }

        $normalized = [];
        foreach ($wallets as $wallet) {
            if (!is_array($wallet) || empty($wallet['address']) || empty($wallet['chain'])) {
                continue;
            }
            if (!isset($wallet['message'])) {
                $wallet['message'] = '';
            }
            $normalized[] = $wallet;
        }

        return $normalized;
    }

    private function get_api_keys() {
        $keys = get_option(self::OPTION_KEYS, []);
        $defaults = [
            'etherscan' => '',
            'bscscan' => '',
            'solscan' => '',
            'discord_webhook' => '',
            'discord_message' => 'New transaction for {label} on {chain}: {hash}',
            'poll_interval' => self::DEFAULT_INTERVAL,
        ];
        return wp_parse_args($keys, $defaults);
    }

    private function get_poll_interval_minutes() {
        $keys = $this->get_api_keys();
        $interval = isset($keys['poll_interval']) ? (int) $keys['poll_interval'] : self::DEFAULT_INTERVAL;
        if ($interval < 1) {
            $interval = self::DEFAULT_INTERVAL;
        }
        return min(1440, $interval);
    }

    private function reschedule_polling($minutes = null) {
        wp_clear_scheduled_hook(self::CRON_HOOK);

        $minutes = null === $minutes ? $this->get_poll_interval_minutes() : max(1, min(1440, (int) $minutes));
        if ($minutes < 1) {
            return;
        }

        wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    public function poll_transactions() {
        if (!$this->acquire_poll_lock()) {
            return;
        }

        try {
            $wallets = $this->get_wallets();
            $now = time();
            if (empty($wallets)) {
                update_option(self::OPTION_LAST_RUN, $now);
                return;
            }

            $meta = get_option(self::OPTION_META, []);
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta_changed = false;
            $keys = $this->get_api_keys();
            foreach ($wallets as $wallet) {
                $latest = $this->fetch_latest_transaction($wallet, $keys);
                if (!$latest) {
                    continue;
                }
                $key = $this->wallet_meta_key($wallet['address'], $wallet['chain']);
                $last_hash = isset($meta[$key]) ? $meta[$key] : '';
                if ($latest['hash'] === $last_hash || empty($latest['hash'])) {
                    continue;
                }

                $meta[$key] = $latest['hash'];
                $meta_changed = true;
                $this->log_transaction($wallet, $latest);
                $this->send_discord_alert($wallet, $latest, $keys);
            }

            if ($meta_changed) {
                update_option(self::OPTION_META, $meta);
            }

            update_option(self::OPTION_LAST_RUN, $now);
        } finally {
            $this->release_poll_lock();
        }
    }

    private function fetch_latest_transaction($wallet, $keys) {
        switch ($wallet['chain']) {
            case 'eth':
                return $this->fetch_etherscan_tx($wallet['address'], $keys['etherscan'], 'https://api.etherscan.io', 'eth');
            case 'bsc':
                return $this->fetch_etherscan_tx($wallet['address'], $keys['bscscan'], 'https://api.bscscan.com', 'bsc');
            case 'sol':
                return $this->fetch_solscan_tx($wallet['address'], $keys['solscan']);
        }
        return null;
    }

    private function fetch_etherscan_tx($address, $api_key, $base_url, $chain) {
        $url = add_query_arg([
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'startblock' => 0,
            'endblock' => 99999999,
            'page' => 1,
            'offset' => 1,
            'sort' => 'desc',
            'apikey' => $api_key,
        ], trailingslashit($base_url) . 'api');

        $response = wp_remote_get($url, [
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['status']) || (string) $body['status'] !== '1' || empty($body['result'][0])) {
            return null;
        }

        $tx = $body['result'][0];
        $value = isset($tx['value']) ? $this->format_value($tx['value'], $chain) : '0';
        $explorer_base = ('eth' === $chain) ? 'https://etherscan.io/tx/' : 'https://bscscan.com/tx/';
        return [
            'hash' => $tx['hash'],
            'from' => $tx['from'],
            'to' => $tx['to'],
            'timestamp' => isset($tx['timeStamp']) ? (int) $tx['timeStamp'] : time(),
            'amount' => $value,
            'explorer' => $explorer_base . $tx['hash'],
        ];
    }

    private function fetch_solscan_tx($address, $api_key) {
        $url = add_query_arg([
            'address' => $address,
            'limit' => 1,
        ], 'https://public-api.solscan.io/account/transactions');

        $args = [
            'timeout' => 20,
            'headers' => [],
        ];
        if (!empty($api_key)) {
            $args['headers']['token'] = $api_key;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body[0]['txHash'])) {
            return null;
        }

        $tx = $body[0];
        return [
            'hash' => $tx['txHash'],
            'timestamp' => isset($tx['blockTime']) ? (int) $tx['blockTime'] : time(),
            'amount' => isset($tx['changeAmount']) ? $tx['changeAmount'] : 'N/A',
            'explorer' => 'https://solscan.io/tx/' . $tx['txHash'],
        ];
    }

    private function format_value($value, $chain) {
        if ($chain === 'eth' || $chain === 'bsc') {
            $divisor = pow(10, 18);
            return number_format_i18n($value / $divisor, 6);
        }
        return (string) $value;
    }

    private function log_transaction($wallet, $data) {
        $log = get_option(self::OPTION_LOG, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            'timestamp' => isset($data['timestamp']) ? (int) $data['timestamp'] : time(),
            'label' => $wallet['label'],
            'chain' => $wallet['chain'],
            'amount' => $data['amount'],
            'hash' => $data['hash'],
            'explorer' => $data['explorer'],
        ]);
        $log = array_slice($log, 0, 200);
        update_option(self::OPTION_LOG, $log);
    }

    private function send_discord_alert($wallet, $data, $keys) {
        if (empty($keys['discord_webhook'])) {
            return;
        }

        $message = !empty($wallet['message']) ? $wallet['message'] : $keys['discord_message'];
        $replacements = [
            '{label}' => $wallet['label'],
            '{address}' => $wallet['address'],
            '{hash}' => $data['hash'],
            '{chain}' => strtoupper($wallet['chain']),
            '{amount}' => $data['amount'],
        ];
        $content = trim(strtr($message, $replacements));
        if ($content === '') {
            return;
        }

        wp_remote_post($keys['discord_webhook'], [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['content' => $content]),
        ]);
    }

    private function wallet_meta_key($address, $chain) {
        return strtolower($chain . ':' . preg_replace('/\s+/', '', $address));
    }

    private function acquire_poll_lock() {
        if (get_transient('mcwt_poll_lock')) {
            return false;
        }

        set_transient('mcwt_poll_lock', 1, 60);
        return true;
    }

    private function release_poll_lock() {
        delete_transient('mcwt_poll_lock');
    }
}

new MCWT_MultiChain_Wallet_Monitor();
