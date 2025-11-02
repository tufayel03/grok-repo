<?php
/**
 * Plugin Name: Dual-Chain Wallet Tracker
 * Plugin URI: https://example.com
 * Description: Track Ethereum and BNB Smart Chain wallets for token transfers and post Discord alerts. Includes wallet manager and transaction log shortcode.
 * Version: 2.1
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WALLET_TRACKER_VERSION', '2.0');
define('WALLET_TRACKER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WALLET_TRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Enqueue script for AJAX localization
function wallet_tracker_enqueue_assets() {
    wp_enqueue_script('jquery');
    wp_localize_script('jquery', 'wallet_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wallet_tracker_nonce')
    ));
}

// Data persistence functions
function get_wallet_data() {
    return get_option('wallet_tracker_data', [
        'wallets' => [],
        'config' => ['pollInterval' => 30],
        'logs' => []
    ]);
}

function save_wallet_data($data) {
    return update_option('wallet_tracker_data', $data);
}

// AJAX handlers
add_action('wp_ajax_wallet_tracker_load', 'handle_wallet_tracker_load');
add_action('wp_ajax_nopriv_wallet_tracker_load', 'handle_wallet_tracker_load');
function handle_wallet_tracker_load() {
    error_log('Wallet Tracker Load AJAX called');
    if (is_user_logged_in() && !wp_verify_nonce($_POST['nonce'], 'wallet_tracker_nonce')) {
        wp_send_json_error('Nonce verification failed');
    }
    $data = get_wallet_data();
    wp_send_json_success($data);
}

add_action('wp_ajax_wallet_tracker_save', 'handle_wallet_tracker_save');
add_action('wp_ajax_nopriv_wallet_tracker_save', 'handle_wallet_tracker_save');
function handle_wallet_tracker_save() {
    error_log('Wallet Tracker Save AJAX called');
    if (is_user_logged_in() && !wp_verify_nonce($_POST['nonce'], 'wallet_tracker_nonce')) {
        wp_send_json_error('Nonce verification failed');
    }
    if (!isset($_POST['data'])) {
        wp_send_json_error('No data provided');
    }
    $input_data = json_decode(stripslashes($_POST['data']), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Invalid JSON: ' . json_last_error_msg());
    }
    $saved = save_wallet_data($input_data);
    if ($saved) {
        wp_send_json_success('Data saved');
    } else {
        wp_send_json_error('Failed to update option');
    }
}

// Admin menu (unchanged)
add_action('admin_menu', 'wallet_tracker_admin_menu');
function wallet_tracker_admin_menu() {
    add_menu_page(
        'Wallet Tracker',
        'Wallet Tracker',
        'manage_options',
        'wallet-tracker',
        'wallet_tracker_admin_page',
        'dashicons-money-alt',
        30
    );
}

// Admin page content (embed main shortcode for consistency)
function wallet_tracker_admin_page() {
    wallet_tracker_enqueue_assets();
    echo do_shortcode('[wallet_tracker]');
}

// Shortcode for main control page
add_shortcode('wallet_tracker', 'wallet_tracker_shortcode');
function wallet_tracker_shortcode($atts) {
    wallet_tracker_enqueue_assets();
    ob_start();
    ?>
    <div class="wallet-tracker-container">
        <h1>Multi-Chain Wallet Tracker - Control Panel</h1>
        
        <div id="config-section" class="config-section">
            <h2>Configuration</h2>
            <p><strong>Note:</strong> Configure individual API keys for each chain. Create keys at <a href="https://etherscan.io/myapikey" target="_blank">Etherscan</a> and <a href="https://bscscan.com/myapikey" target="_blank">BscScan</a>.</p>
            <label>Etherscan API Key (ETH): <input type="text" id="ethApiKey" placeholder="Your Etherscan API Key"></label><br>
            <label>BscScan API Key (BSC): <input type="text" id="bscApiKey" placeholder="Your BscScan API Key"></label><br>
            <label>Discord Webhook URL: <input type="text" id="discordWebhook" placeholder="https://discord.com/api/webhooks/..."></label><br>
            <label>Poll Interval (seconds): <input type="number" id="pollInterval" placeholder="30" min="10" max="300"></label><br>
            <button onclick="saveConfig()">Save Config</button>
            <div id="configStatus"></div>
        </div>

        <div id="add-section" class="config-section">
            <h2>Add Wallet/Contract</h2>
            <p>Add an address (wallet or contract) to track any token transactions involving it (transfers to/from).</p>
            <div class="add-form">
                <select id="chain">
                    <option value="eth">Ethereum (ETH)</option>
                    <option value="bsc">BNB Smart Chain (BSC)</option>
                </select>
                <input type="text" id="address" placeholder="Wallet/Contract Address">
                <input type="text" id="label" placeholder="Custom Label">
                <input type="text" id="customText" placeholder="Custom Alert Text (use {token}, {amount})">
                <button onclick="addWallet()">Add</button>
            </div>
            <div id="addStatus"></div>
        </div>

        <div class="config-section">
            <h2>Search by Label</h2>
            <input type="text" id="searchInput" class="search-input" placeholder="Search wallets by label..." oninput="filterWallets()">
        </div>

        <div class="config-section">
            <h2>Actions</h2>
            <button onclick="manualCheck()">Manual Check for New Tx</button>
            <button onclick="exportWallets()">Export Wallets (JSON)</button>
            <button onclick="togglePolling()">Toggle Polling</button>
            <div id="pollingStatus"></div>
        </div>

        <div id="wallet-section" class="wallet-list">
            <h2>Tracked Wallets/Contracts</h2>
            <div id="walletContainer"></div>
            <div id="pagination"></div>
        </div>

        <div id="globalStatus" class="status hidden"></div>
    </div>

    <style>
        body { background-color: #1a1a1a !important; }
        .wallet-tracker-container { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #1a1a1a !important; 
            color: #ffffff; 
            padding: 20px; 
            max-width: 1200px; 
            width: 100%; 
            margin: 0 auto; 
            box-sizing: border-box;
        }
        .config-section { background: #2a2a2a; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        input, select, button { padding: 10px; margin: 5px; border: none; border-radius: 4px; background: #3a3a3a; color: #fff; }
        button { background: #00ff88; color: #000; cursor: pointer; }
        button:hover { background: #00cc66; }
        .wallet-list { background: #2a2a2a; padding: 20px; border-radius: 8px; }
        .wallet-item { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #444; flex-wrap: wrap; gap: 10px; }
        .wallet-item:last-child { border-bottom: none; }
        .remove-btn { background: #ff4444; color: #fff; }
        .search-input { width: 100%; margin-bottom: 20px; }
        .add-form { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .status { text-align: center; margin: 10px 0; padding: 10px; border-radius: 4px; }
        .success { background: #00ff88; color: #000; }
        .error { background: #ff4444; color: #fff; }
        .hidden { display: none; }
        .polling-on { color: #00ff88; }
        .polling-off { color: #ff4444; }
        h1, h2 { color: #00ff88; }
        .tx-item { background: #2a2a2a; padding: 10px; border-radius: 4px; margin: 5px 0; }
        .log-item { font-size: 0.9em; padding: 5px; border-left: 3px solid #00ff88; margin: 5px 0; }
        a { color: #00ff88; }
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        .pagination button {
            background: #00ff88;
            color: #000;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 5px;
        }
        .pagination button:hover {
            background: #00cc66;
        }
    </style>

    <script>
        // Use localized vars if available, fallback to hardcoded
        const ajaxurl = window.wallet_ajax ? window.wallet_ajax.ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const wallet_nonce = window.wallet_ajax ? window.wallet_ajax.nonce : '<?php echo esc_js(wp_create_nonce('wallet_tracker_nonce')); ?>';

        // API endpoints for each chain
        const SCAN_APIS = {
            eth: 'https://api.etherscan.io/api',
            bsc: 'https://api.bscscan.com/api'
        };

        // Data
        let data = {wallets: [], config: {pollInterval: 30}, logs: []};
        let wallets;
        let config;
        let logs;
        let pollIntervalId;
        let isPolling = localStorage.getItem('isPolling') !== 'false';
        let currentPage = 1;
        let currentSearch = '';

        // AJAX functions
        async function loadData() {
            console.log('Loading data...');
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_load');
            formData.append('nonce', wallet_nonce);
            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                console.log('Load response:', result);
                if (result.success) {
                    return result.data;
                } else {
                    console.error('Load failed:', result.data);
                    return {wallets: [], config: {pollInterval:30}, logs:[]};
                }
            } catch (error) {
                console.error('Load error:', error);
                return {wallets: [], config: {pollInterval:30}, logs:[]};
            }
        }

        async function saveData(serverData) {
            console.log('Saving data...', serverData);
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_save');
            formData.append('nonce', wallet_nonce);
            formData.append('data', JSON.stringify(serverData));
            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                });
                const text = await response.text();
                console.log('Save response text:', text);
                const result = JSON.parse(text);
                console.log('Save response:', result);
                return result.success;
            } catch (error) {
                console.error('Save error:', error);
                return false;
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', async () => {
            console.log('DOM loaded, ajaxurl:', ajaxurl, 'nonce:', wallet_nonce);
            data = await loadData();
            wallets = [...(data.wallets || [])];
            config = { ...data.config, pollInterval: data.config.pollInterval || 30 };
            logs = [...(data.logs || [])];
            loadConfig();
            renderWallets();
            if (isPolling) startPolling();
            updatePollingStatus();
        });

        function loadConfig() {
            const ethInput = document.getElementById('ethApiKey');
            if (ethInput) ethInput.value = config.ethApiKey || '';
            const bscInput = document.getElementById('bscApiKey');
            if (bscInput) bscInput.value = config.bscApiKey || '';
            const discordInput = document.getElementById('discordWebhook');
            if (discordInput) discordInput.value = config.discordWebhook || '';
            const pollInput = document.getElementById('pollInterval');
            if (pollInput) pollInput.value = config.pollInterval || 30;
        }

        async function saveConfig() {
            const ethInput = document.getElementById('ethApiKey');
            if (ethInput) config.ethApiKey = ethInput.value.trim();
            const bscInput = document.getElementById('bscApiKey');
            if (bscInput) config.bscApiKey = bscInput.value.trim();
            const discordInput = document.getElementById('discordWebhook');
            if (discordInput) config.discordWebhook = discordInput.value.trim();
            const pollInput = document.getElementById('pollInterval');
            if (pollInput) config.pollInterval = parseInt(pollInput.value) || 30;

            data.config = { ...config };
            const saveSuccess = await saveData(data);
            if (saveSuccess) {
                showStatus('configStatus', 'Config saved successfully!', 'success');
                if (isPolling) startPolling();
            } else {
                showStatus('configStatus', 'Failed to save config. Check console for errors.', 'error');
            }
        }

        window.saveConfig = saveConfig;

        async function addWallet() {
            const chainSelect = document.getElementById('chain');
            const addressInput = document.getElementById('address');
            const labelInput = document.getElementById('label');
            const customTextInput = document.getElementById('customText');
            
            if (!chainSelect || !addressInput || !labelInput) return;
            
            const chain = chainSelect.value;
            const address = addressInput.value.trim();
            const label = labelInput.value.trim();
            const customText = customTextInput ? customTextInput.value.trim() : '';

            if (!address || !label) {
                showStatus('addStatus', 'Address and label required!', 'error');
                return;
            }

            if (!(/^0x[a-fA-F0-9]{40}$/.test(address))) {
                showStatus('addStatus', 'Invalid ETH/BSC address!', 'error');
                return;
            }

            const wallet = {
                chain,
                address: address.toLowerCase(),
                label,
                customText: customText || `New {token} transfer on {label}: {amount} tokens.`,
                lastBlock: 0
            };

            wallets.push(wallet);
            data.wallets = [...wallets];
            const saveSuccess = await saveData(data);
            if (!saveSuccess) {
                showStatus('addStatus', 'Added locally but failed to save to backend. Check console.', 'error');
                wallets.pop(); // Revert local add on fail
                return;
            }

            await setInitialBlock(wallet);

            renderWallets();
            addressInput.value = '';
            labelInput.value = '';
            if (customTextInput) customTextInput.value = '';
            showStatus('addStatus', 'Wallet/Contract added!', 'success');
        }

        window.addWallet = addWallet;

        async function setInitialBlock(wallet) {
            const apiKey = wallet.chain === 'eth' ? config.ethApiKey : config.bscApiKey;
            if (!apiKey) return;

            const params = new URLSearchParams({
                module: 'proxy',
                action: 'eth_blockNumber',
                apikey: apiKey
            });

            try {
                const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params}`);
                const responseData = await response.json();

                const raw = responseData.result;
                if (!raw) return;
                const currentBlock = typeof raw === 'string' && raw.startsWith('0x') ? parseInt(raw, 16) : parseInt(raw, 10);
                if (!isNaN(currentBlock)) {
                    wallet.lastBlock = currentBlock - 1;
                    data.wallets = [...wallets];
                    await saveData(data);
                }
            } catch (error) {
                console.error('Error setting initial block:', error);
            }
        }

        async function removeWallet(index) {
            const removedWallet = wallets[index];
            wallets.splice(index, 1);
            logs = logs.filter(log => log.walletAddress !== removedWallet.address);
            data.wallets = [...wallets];
            data.logs = [...logs];
            const saveSuccess = await saveData(data);
            if (!saveSuccess) {
                showGlobalStatus('Remove succeeded locally but backend save failed. Check console.', 'error');
            }
            renderWallets();
        }

        window.removeWallet = removeWallet;

        function renderWallets() {
            let displayWallets = wallets;
            if (currentSearch) {
                displayWallets = wallets.filter(w => w.label.toLowerCase().includes(currentSearch));
            }
            const pageSize = 25;
            const totalPages = Math.ceil(displayWallets.length / pageSize);
            currentPage = Math.min(currentPage, totalPages) || 1;
            const start = (currentPage - 1) * pageSize;
            const pagedWallets = displayWallets.slice(start, start + pageSize);

            const container = document.getElementById('walletContainer');
            if (container) {
                container.innerHTML = pagedWallets.map((wallet) => {
                    const originalIndex = wallets.indexOf(wallet);
                    const latestLog = logs.find(log => log.walletAddress === wallet.address);
                    const lastTx = latestLog ? `Last Tx: ${latestLog.txHash.slice(0, 10)}… (${latestLog.token || 'Token'} ${typeof latestLog.amount === 'number' ? latestLog.amount.toFixed(4) : ''})` : 'No activity recorded yet';
                    return `
                        <div class="wallet-item" data-original-index="${originalIndex}">
                            <div>
                                <strong>${wallet.label}</strong>
                                <div>${wallet.chain.toUpperCase()} • ${wallet.address.slice(0,8)}…${wallet.address.slice(-4)}</div>
                                <div class="wallet-last-tx">${lastTx}</div>
                                <div class="wallet-custom">Alert: ${wallet.customText}</div>
                            </div>
                            <button class="remove-btn" onclick="removeWallet(${originalIndex})">Remove</button>
                        </div>
                    `;
                }).join('');
            }

            // Pagination
            const pagEl = document.getElementById('pagination');
            if (pagEl) {
                let pagHtml = '';
                if (totalPages > 1) {
                    pagHtml = `<div class="pagination">`;
                    if (currentPage > 1) {
                        pagHtml += `<button onclick="prevPage()">Prev</button>`;
                    }
                    pagHtml += `Page ${currentPage} of ${totalPages}`;
                    if (currentPage < totalPages) {
                        pagHtml += `<button onclick="nextPage()">Next</button>`;
                    }
                    pagHtml += `</div>`;
                }
                pagEl.innerHTML = pagHtml;
            }
        }

        window.renderWallets = renderWallets;

        function filterWallets() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                currentSearch = searchInput.value.toLowerCase();
            }
            currentPage = 1;
            renderWallets();
        }

        window.filterWallets = filterWallets;

        window.prevPage = () => {
            currentPage--;
            renderWallets();
        };

        window.nextPage = () => {
            currentPage++;
            renderWallets();
        };

        function showStatus(id, message, type) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = message;
                el.className = `status ${type}`;
                el.classList.remove('hidden');
                setTimeout(() => el.classList.add('hidden'), 3000);
            }
        }

        function showGlobalStatus(message, type) {
            const el = document.getElementById('globalStatus');
            if (el) {
                el.textContent = message;
                el.className = `status ${type}`;
                el.classList.remove('hidden');
                setTimeout(() => el.classList.add('hidden'), 5000);
            }
        }

        async function sendDiscordAlert(message) {
            if (!config.discordWebhook) {
                console.warn('No Discord webhook configured');
                return;
            }
            try {
                const response = await fetch(config.discordWebhook, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: message })
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
            } catch (error) {
                console.error('Discord alert failed:', error);
            }
        }

        async function logTx(wallet, txDetails, message) {
            const logEntry = {
                walletAddress: wallet.address,
                chain: wallet.chain,
                timestamp: new Date().toISOString(),
                txHash: txDetails.hash,
                token: txDetails.token,
                amount: txDetails.amount,
                label: wallet.label,
                message
            };
            logs.unshift(logEntry);
            if (logs.length > 250) {
                logs = logs.slice(0, 250);
            }
            data.logs = [...logs];
            await saveData(data);
        }

        async function checkWallet(wallet) {
            try {
                await checkScanWallet(wallet);
            } catch (error) {
                console.error(`Error checking ${wallet.label}:`, error);
            }
        }

        async function checkScanWallet(wallet) {
            const apiKey = wallet.chain === 'eth' ? config.ethApiKey : config.bscApiKey;
            if (!apiKey) return;

            const params = new URLSearchParams({
                module: 'account',
                action: 'tokentx',
                address: wallet.address,
                startblock: Math.max(wallet.lastBlock + 1, 0),
                endblock: 99999999,
                page: 1,
                offset: 25,
                sort: 'desc',
                apikey: apiKey
            });

            const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params}`);
            const responseData = await response.json();

            if (responseData.status !== '1') return;

            const txs = responseData.result || [];
            let hasNew = false;
            let latestBlock = wallet.lastBlock;
            let latestTxDetails = null;

            for (const tx of txs) {
                if (!tx.hash) continue;
                const from = (tx.from || '').toLowerCase();
                const to = (tx.to || '').toLowerCase();
                if (from === wallet.address || to === wallet.address) {
                    hasNew = true;
                    latestBlock = Math.max(latestBlock, parseInt(tx.blockNumber, 10));
                    const decimals = parseInt(tx.tokenDecimal || '18', 10);
                    latestTxDetails = {
                        hash: tx.hash,
                        token: tx.tokenSymbol || 'Unknown',
                        amount: parseFloat(tx.value || '0') / Math.pow(10, isNaN(decimals) ? 18 : decimals)
                    };
                    break;
                }
            }

            if (hasNew && latestTxDetails) {
                wallet.lastBlock = latestBlock;
                data.wallets = [...wallets];
                await saveData(data);
                const amountNumber = isNaN(latestTxDetails.amount) ? 0 : latestTxDetails.amount;
                const replacedCustom = wallet.customText
                    .replace('{label}', wallet.label)
                    .replace('{token}', latestTxDetails.token)
                    .replace('{amount}', amountNumber.toFixed(4));
                const explorerBase = wallet.chain === 'eth' ? 'https://etherscan.io' : 'https://bscscan.com';
                const alertMsg = [
                    `New transaction detected for **${wallet.label}** on ${wallet.chain.toUpperCase()}.`,
                    `Token: ${latestTxDetails.token}`,
                    `Amount: ${amountNumber.toFixed(4)}`,
                    `Explorer: ${explorerBase}/tx/${latestTxDetails.hash}`,
                    '',
                    replacedCustom
                ].join('\n');
                await sendDiscordAlert(alertMsg);
                await logTx(wallet, {...latestTxDetails, amount: amountNumber}, alertMsg);
                showGlobalStatus(`Alert: ${wallet.label} detected new ${latestTxDetails.token} transfer`, 'success');
            }
        }

        async function manualCheck() {
            showGlobalStatus('Manual check in progress...', 'success');
            for (const wallet of wallets) {
                await checkWallet(wallet);
            }
            showGlobalStatus('Manual check complete!', 'success');
        }

        window.manualCheck = manualCheck;

        function exportWallets() {
            const dataStr = JSON.stringify(data, null, 2);
            const dataBlob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'wallet-tracker-backup.json';
            link.click();
            URL.revokeObjectURL(url);
        }

        window.exportWallets = exportWallets;

        function togglePolling() {
            isPolling = !isPolling;
            localStorage.setItem('isPolling', isPolling);
            if (isPolling) {
                startPolling();
            } else {
                if (pollIntervalId) {
                    clearInterval(pollIntervalId);
                    pollIntervalId = null;
                }
            }
            updatePollingStatus();
        }

        window.togglePolling = togglePolling;

        function updatePollingStatus() {
            const el = document.getElementById('pollingStatus');
            if (el) {
                el.textContent = isPolling ? `Polling: ON (every ${config.pollInterval}s)` : 'Polling: OFF';
                el.className = `status ${isPolling ? 'success polling-on' : 'error polling-off'}`;
            }
        }

        function startPolling() {
            if (pollIntervalId) clearInterval(pollIntervalId);
            const needsEth = wallets.some(w => w.chain === 'eth');
            const needsBsc = wallets.some(w => w.chain === 'bsc');
            if (wallets.length === 0 || (needsEth && !config.ethApiKey) || (needsBsc && !config.bscApiKey)) {
                console.warn('Cannot start polling: Missing API keys or no wallets');
                return;
            }

            const intervalMs = (config.pollInterval || 30) * 1000;
            pollIntervalId = setInterval(async () => {
                for (const wallet of wallets) {
                    await checkWallet(wallet);
                }
            }, intervalMs);

            setTimeout(async () => {
                for (const wallet of wallets) {
                    await checkWallet(wallet);
                }
            }, 1000);
        }

        // Globals
        window.showStatus = showStatus;
    </script>
    <?php
    return ob_get_clean();
}

// Shortcode for Logs Page
add_shortcode('wallet_logs', 'wallet_logs_shortcode');
function wallet_logs_shortcode($atts) {
    wallet_tracker_enqueue_assets();
    ob_start();
    ?>
    <div class="wallet-tracker-container">
        <h1>Transaction Logs</h1>
        <div id="logsContainer"></div>
        <button onclick="clearLogs()">Clear All Logs</button>
        <button onclick="exportLogs()">Export Logs (JSON)</button>
    </div>

    <style>
        body { background-color: #1a1a1a !important; }
        .wallet-tracker-container { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #1a1a1a !important; 
            color: #ffffff; 
            padding: 20px; 
            max-width: 1200px; 
            width: 100%; 
            margin: 0 auto; 
            box-sizing: border-box;
        }
        .log-item { background: #2a2a2a; padding: 10px; border-radius: 4px; margin: 5px 0; border-left: 3px solid #00ff88; }
        .log-message { margin-top: 8px; color: #bbbbbb; font-size: 0.9em; }
        button { background: #00ff88; color: #000; padding: 10px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        h1 { color: #00ff88; }
        a { color: #00ff88; }
    </style>

    <script>
        // Use localized vars
        const ajaxurl = window.wallet_ajax ? window.wallet_ajax.ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const wallet_nonce = window.wallet_ajax ? window.wallet_ajax.nonce : '<?php echo esc_js(wp_create_nonce('wallet_tracker_nonce')); ?>';

        let data = {logs: [], wallets: []};
        let logs = [];
        let wallets = [];

        async function loadData() {
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_load');
            formData.append('nonce', wallet_nonce);
            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    return result.data;
                } else {
                    console.error('Load failed:', result.data);
                    return {logs: [], wallets: []};
                }
            } catch (error) {
                console.error('Load error:', error);
                return {logs: [], wallets: []};
            }
        }

        async function saveData(serverData) {
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_save');
            formData.append('nonce', wallet_nonce);
            formData.append('data', JSON.stringify(serverData));
            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                return result.success;
            } catch (error) {
                console.error('Save error:', error);
                return false;
            }
        }

        function escapeHtml(str) {
            const safe = (str ?? '').toString();
            return safe
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderLogs() {
            const container = document.getElementById('logsContainer');
            if (!container) return;
            const logList = logs.map(log => {
                const wallet = wallets.find(w => w.address === log.walletAddress);
                const label = wallet ? wallet.label : log.label || log.walletAddress.slice(0,8) + '...';
                const amountText = typeof log.amount === 'number' && !isNaN(log.amount) ? log.amount.toFixed(4) : '—';
                const messageHtml = log.message ? `<div class="log-message">${escapeHtml(log.message).replace(/\n/g, '<br>')}</div>` : '';
                return `
                    <div class="log-item">
                        <div><strong>${escapeHtml(label)}</strong> (${log.chain.toUpperCase()})</div>
                        <div>Recorded: ${new Date(log.timestamp).toLocaleString()}</div>
                        <div>Token: ${escapeHtml(log.token || 'Unknown')} • Amount: ${amountText}</div>
                        <div>Tx Hash: <a href="${getTxExplorerUrl(log.walletAddress, log.txHash, log.chain)}" target="_blank">${log.txHash}</a></div>
                        ${messageHtml}
                    </div>
                `;
            }).join('');
            container.innerHTML = logList || '<p>No logs available.</p>';
        }

        function getTxExplorerUrl(address, hash, chain) {
            switch (chain) {
                case 'eth': return `https://etherscan.io/tx/${hash}`;
                case 'bsc': return `https://bscscan.com/tx/${hash}`;
                default: return `https://etherscan.io/tx/${hash}`;
            }
        }

        async function clearLogs() {
            data.logs = [];
            const saveSuccess = await saveData(data);
            if (saveSuccess) {
                logs = data.logs;
                renderLogs();
            } else {
                alert('Failed to clear logs on backend.');
            }
        }

        function exportLogs() {
            const dataStr = JSON.stringify(logs, null, 2);
            const dataBlob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'wallet-logs.json';
            link.click();
            URL.revokeObjectURL(url);
        }

        document.addEventListener('DOMContentLoaded', async () => {
            data = await loadData();
            logs = [...(data.logs || [])];
            wallets = [...(data.wallets || [])];
            renderLogs();
        });
        window.clearLogs = clearLogs;
        window.exportLogs = exportLogs;
    </script>
    <?php
    return ob_get_clean();
}

// Enqueue on frontend if shortcodes used
add_action('wp_enqueue_scripts', 'wallet_tracker_frontend_enqueue');
function wallet_tracker_frontend_enqueue() {
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'wallet_tracker') || has_shortcode($post->post_content, 'wallet_logs'))) {
        wallet_tracker_enqueue_assets();
    }
}

// Admin enqueue
add_action('admin_enqueue_scripts', 'wallet_tracker_enqueue_scripts');
function wallet_tracker_enqueue_scripts($hook) {
    if ($hook !== 'toplevel_page_wallet-tracker') return;
    wallet_tracker_enqueue_assets();
}
?>
