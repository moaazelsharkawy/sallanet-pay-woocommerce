# Local test

WordPress (git clone WordPress/WordPress) + WooCommerce zip (GitHub release) + SQLite drop-in, run with wp-cli.

1. Start the fake API: `php -S 127.0.0.1:8891 mock_api.php` (state in /tmp/wpt/mock/state.json).
2. Link the plugin into wp-content/plugins, activate, set store currency USD, api_key `test_key`, webhook secret `whsec_test`, pricing `usd_live`, release on complete `yes`, api_base `http://127.0.0.1:8891`.
3. Create a product with id 10 priced 10.37.
4. `wp eval-file plugin_test.php` (32 checks).
