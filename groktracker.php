<?php
/**
 * Plugin Name: Multi-Chain Wallet Tracker
 * Plugin URI: https://example.com
 * Description: Track wallets/contracts on ETH, BSC, SOL for token transactions. Alerts via Discord webhook with token/amount details. Frontend shortcodes: [wallet_tracker] for control, [wallet_latest_tx] for latest transactions, [wallet_logs] for logs. Etherscan V2 + Solscan support.
 * Version: 2.0
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
        'logs' => [],
        'latestTxs' => []
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
            <p><strong>Note:</strong> Use a single Etherscan V2 API key for both ETH and BSC (get at <a href="https://etherscan.io/myapikey" target="_blank">Etherscan API Dashboard</a>). For Solana, get free key at <a href="https://solscan.io/dapi" target="_blank">Solscan Developer Portal</a>.</p>
            <label>Etherscan V2 API Key (for ETH/BSC): <input type="text" id="ethApiKey" placeholder="Your Etherscan V2 API Key"></label><br>
            <label>Solscan API Key (for SOL): <input type="text" id="solApiKey" placeholder="Your Solscan API Key"></label><br>
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
                    <option value="bsc">Binance Smart Chain (BSC)</option>
                    <option value="sol">Solana (SOL)</option>
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

        // Constants - V2 for ETH/BSC, Solscan for SOL
        const SCAN_APIS = {
            eth: 'https://api.etherscan.io/v2/api',
            bsc: 'https://api.etherscan.io/v2/api',
            sol: 'https://public-api.solscan.io'
        };

        // Data
        let data = {wallets: [], config: {pollInterval: 30}, logs: [], latestTxs: {}};
        let wallets;
        let config;
        let logs;
        let latestTxs;
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
                    return {wallets: [], config: {pollInterval:30}, logs:[], latestTxs:{}};
                }
            } catch (error) {
                console.error('Load error:', error);
                return {wallets: [], config: {pollInterval:30}, logs:[], latestTxs:{}};
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
            latestTxs = { ...data.latestTxs };
            loadConfig();
            renderWallets();
            if (isPolling) startPolling();
            updatePollingStatus();
        });

        function loadConfig() {
            const ethInput = document.getElementById('ethApiKey');
            if (ethInput) ethInput.value = config.ethApiKey || '';
            const solInput = document.getElementById('solApiKey');
            if (solInput) solInput.value = config.solApiKey || '';
            const discordInput = document.getElementById('discordWebhook');
            if (discordInput) discordInput.value = config.discordWebhook || '';
            const pollInput = document.getElementById('pollInterval');
            if (pollInput) pollInput.value = config.pollInterval || 30;
        }

        async function saveConfig() {
            const ethInput = document.getElementById('ethApiKey');
            if (ethInput) config.ethApiKey = ethInput.value.trim();
            const solInput = document.getElementById('solApiKey');
            if (solInput) config.solApiKey = solInput.value.trim();
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

            if (chain === 'sol' && address.length !== 44) {
                showStatus('addStatus', 'Invalid Solana address!', 'error');
                return;
            } else if ((chain === 'eth' || chain === 'bsc') && !(/^0x[a-fA-F0-9]{40}$/.test(address))) {
                showStatus('addStatus', 'Invalid ETH/BSC address!', 'error');
                return;
            }

            const wallet = {
                chain,
                address: address.toLowerCase(),
                label,
                customText: customText || `New {token} transfer on {label}: {amount} tokens.`,
                lastBlock: 0,
                lastSignature: null
            };

            wallets.push(wallet);
            data.wallets = [...wallets];
            const saveSuccess = await saveData(data);
            if (!saveSuccess) {
                showStatus('addStatus', 'Added locally but failed to save to backend. Check console.', 'error');
                wallets.pop(); // Revert local add on fail
                return;
            }

            if (wallet.chain !== 'sol') {
                await setInitialBlock(wallet);
            }

            renderWallets();
            addressInput.value = '';
            labelInput.value = '';
            if (customTextInput) customTextInput.value = '';
            showStatus('addStatus', 'Wallet/Contract added!', 'success');
        }

        window.addWallet = addWallet;

        async function setInitialBlock(wallet) {
            const apiKey = config.ethApiKey;
            if (!apiKey) return;

            const chainId = wallet.chain === 'eth' ? '1' : '56';
            const params = new URLSearchParams({
                module: 'proxy',
                action: 'eth_blockNumber',
                chainid: chainId,
                apikey: apiKey
            });

            try {
                const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params}`);
                const responseData = await response.json();

                if (responseData.status === '1' && responseData.result) {
                    const currentBlock = parseInt(responseData.result, 16);
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
            delete latestTxs[removedWallet.address];
            data.wallets = [...wallets];
            data.logs = [...logs];
            data.latestTxs = { ...latestTxs };
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
                container.innerHTML = pagedWallets.map((wallet, idx) => {
                    const originalIndex = wallets.indexOf(wallet);
                    const lastTx = latestTxs[wallet.address] ? `Last Tx: ${latestTxs[wallet.address].hash.slice(0,10)}...` : 'No Tx Yet';
                    return `
                        <div class="wallet-item" data-original-index="${originalIndex}">
                            <span><strong>${wallet.label}</strong> (${wallet.chain.toUpperCase()}: ${wallet.address.slice(0,8)}...)</span>
                            <span>${lastTx}</span>
                            <span>${wallet.customText}</span>
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

        async function sendDiscordAlert(message, walletAddress) {
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

        async function logTx(walletAddress, txHash, chain, timestamp = new Date().toISOString()) {
            const logEntry = { walletAddress, txHash, chain, timestamp };
            logs.unshift(logEntry);
            if (logs.length > 100) logs = logs.slice(0, 100);
            latestTxs[walletAddress] = { hash: txHash, timestamp };
            data.logs = [...logs];
            data.latestTxs = { ...latestTxs };
            await saveData(data);
        }

        async function checkWallet(wallet) {
            try {
                if (wallet.chain === 'sol') {
                    await checkSolWallet(wallet);
                } else {
                    await checkScanWallet(wallet);
                }
            } catch (error) {
                console.error(`Error checking ${wallet.label}:`, error);
            }
        }

        async function checkScanWallet(wallet) {
            const apiKey = config.ethApiKey;
            if (!apiKey) return;

            const chainId = wallet.chain === 'eth' ? '1' : '56';
            const params = new URLSearchParams({
                module: 'account',
                action: 'tokentx',
                address: wallet.address,
                startblock: wallet.lastBlock + 1,
                endblock: 99999999,
                page: 1,
                offset: 10,
                sort: 'desc',
                chainid: chainId,
                apikey: apiKey
            });

            const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params}`);
            const responseData = await response.json();

            if (responseData.status !== '1') return;

            const txs = responseData.result || [];
            let newTx = false;
            let latestBlock = wallet.lastBlock;
            let latestTxDetails = null;

            for (const tx of txs) {
                if (tx.from.toLowerCase() === wallet.address || tx.to.toLowerCase() === wallet.address) {
                    newTx = true;
                    latestBlock = Math.max(latestBlock, parseInt(tx.blockNumber));
                    latestTxDetails = {
                        hash: tx.hash,
                        token: tx.tokenSymbol || 'Unknown',
                        amount: parseFloat(tx.value) / Math.pow(10, parseInt(tx.tokenDecimal || 18))
                    };
                }
            }

            if (newTx && latestTxDetails) {
                wallet.lastBlock = latestBlock;
                data.wallets = [...wallets];
                await saveData(data);
                const replacedCustom = wallet.customText
                    .replace('{label}', wallet.label)
                    .replace('{token}', latestTxDetails.token)
                    .replace('{amount}', latestTxDetails.amount.toFixed(4));
                const alertMsg = `New transaction detected for ${wallet.label} on ${wallet.chain.toUpperCase()}!\nToken: ${latestTxDetails.token}\nAmount: ${latestTxDetails.amount.toFixed(4)}\n\n${replacedCustom}`;
                await sendDiscordAlert(alertMsg, wallet.address);
                await logTx(wallet.address, latestTxDetails.hash, wallet.chain);
                showGlobalStatus(`Alert: ${wallet.label} detected new ${latestTxDetails.token} transaction!`, 'success');
            }
        }

        async function checkSolWallet(wallet) {
            const apiKey = config.solApiKey;
            if (!apiKey) return;

            const params = new URLSearchParams({
                address: wallet.address,
                offset: 0,
                limit: 10
            });

            const response = await fetch(`${SCAN_APIS.sol}/account/tokentxns?${params}`, {
                headers: { 'Authorization': `Bearer ${apiKey}` }
            });
            const responseData = await response.json();

            if (!responseData.data || responseData.data.length === 0) return;

            const txs = responseData.data;
            let newTx = false;
            let latestTxDetails = null;
            let latestSig = wallet.lastSignature;

            for (const tx of txs) {
                if (!latestSig || tx.signature > latestSig) {
                    newTx = true;
                    latestSig = tx.signature;
                    latestTxDetails = {
                        hash: tx.signature,
                        token: tx.mint || 'SOL',
                        amount: parseFloat(tx.tokenAmount ? tx.tokenAmount.raw : (tx.lamports || 0)) / 1e9
                    };
                }
            }

            if (newTx && latestTxDetails) {
                wallet.lastSignature = latestSig;
                data.wallets = [...wallets];
                await saveData(data);
                const replacedCustom = wallet.customText
                    .replace('{label}', wallet.label)
                    .replace('{token}', latestTxDetails.token)
                    .replace('{amount}', latestTxDetails.amount.toFixed(4));
                const alertMsg = `New transaction detected for ${wallet.label} on ${wallet.chain.toUpperCase()}!\nToken: ${latestTxDetails.token}\nAmount: ${latestTxDetails.amount.toFixed(4)}\n\n${replacedCustom}`;
                await sendDiscordAlert(alertMsg, wallet.address);
                await logTx(wallet.address, latestTxDetails.hash, wallet.chain);
                showGlobalStatus(`Alert: ${wallet.label} detected new ${latestTxDetails.token} transaction!`, 'success');
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
            if (wallets.length === 0 || (!config.ethApiKey && wallets.some(w => w.chain !== 'sol')) || (!config.solApiKey && wallets.some(w => w.chain === 'sol'))) {
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

// Shortcode for Latest Transactions Page
add_shortcode('wallet_latest_tx', 'wallet_latest_tx_shortcode');
function wallet_latest_tx_shortcode($atts) {
    wallet_tracker_enqueue_assets();
    ob_start();
    ?>
    <div class="wallet-tracker-container">
        <h1>Latest Transactions</h1>
        <div id="latestTxContainer"></div>
        <button onclick="clearLatestTxs()">Clear All</button>
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
        .tx-item { background: #2a2a2a; padding: 15px; border-radius: 8px; margin: 10px 0; }
        button { background: #00ff88; color: #000; padding: 10px; border: none; border-radius: 4px; cursor: pointer; }
        h1 { color: #00ff88; }
        a { color: #00ff88; }
    </style>

    <script>
        // Use localized vars
        const ajaxurl = window.wallet_ajax ? window.wallet_ajax.ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const wallet_nonce = window.wallet_ajax ? window.wallet_ajax.nonce : '<?php echo esc_js(wp_create_nonce('wallet_tracker_nonce')); ?>';

        let data = {wallets: [], latestTxs: {}};
        let latestTxs = {};
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
                    return {wallets: [], latestTxs: {}};
                }
            } catch (error) {
                console.error('Load error:', error);
                return {wallets: [], latestTxs: {}};
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

        function renderLatestTxs() {
            const container = document.getElementById('latestTxContainer');
            if (!container) return;
            const txList = Object.entries(latestTxs).map(([address, tx]) => {
                const wallet = wallets.find(w => w.address === address);
                const label = wallet ? wallet.label : address.slice(0,8) + '...';
                return `
                    <div class="tx-item">
                        <strong>${label}</strong> (${tx.timestamp})<br>
                        Chain: ${wallet ? wallet.chain.toUpperCase() : 'Unknown'}<br>
                        Tx Hash: <a href="${getTxExplorerUrl(address, tx.hash, wallet ? wallet.chain : 'eth')}" target="_blank">${tx.hash}</a>
                    </div>
                `;
            }).join('');
            container.innerHTML = txList || '<p>No latest transactions recorded.</p>';
        }

        function getTxExplorerUrl(address, hash, chain) {
            switch (chain) {
                case 'eth': return `https://etherscan.io/tx/${hash}`;
                case 'bsc': return `https://bscscan.com/tx/${hash}`;
                case 'sol': return `https://solscan.io/tx/${hash}`;
                default: return `https://etherscan.io/tx/${hash}`;
            }
        }

        async function clearLatestTxs() {
            data.latestTxs = {};
            const saveSuccess = await saveData(data);
            if (saveSuccess) {
                latestTxs = data.latestTxs;
                renderLatestTxs();
            } else {
                alert('Failed to clear latest TXs on backend.');
            }
        }

        document.addEventListener('DOMContentLoaded', async () => {
            data = await loadData();
            wallets = [...(data.wallets || [])];
            latestTxs = { ...data.latestTxs };
            renderLatestTxs();
        });
        window.clearLatestTxs = clearLatestTxs;
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

        function renderLogs() {
            const container = document.getElementById('logsContainer');
            if (!container) return;
            const logList = logs.map(log => {
                const wallet = wallets.find(w => w.address === log.walletAddress);
                const label = wallet ? wallet.label : log.walletAddress.slice(0,8) + '...';
                return `
                    <div class="log-item">
                        <strong>${label}</strong> (${log.chain.toUpperCase()}) - ${log.timestamp}<br>
                        Tx Hash: <a href="${getTxExplorerUrl(log.walletAddress, log.txHash, log.chain)}" target="_blank">${log.txHash}</a>
                    </div>
                `;
            }).join('');
            container.innerHTML = logList || '<p>No logs available.</p>';
        }

        function getTxExplorerUrl(address, hash, chain) {
            switch (chain) {
                case 'eth': return `https://etherscan.io/tx/${hash}`;
                case 'bsc': return `https://bscscan.com/tx/${hash}`;
                case 'sol': return `https://solscan.io/tx/${hash}`;
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
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'wallet_tracker') || has_shortcode($post->post_content, 'wallet_latest_tx') || has_shortcode($post->post_content, 'wallet_logs'))) {
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
