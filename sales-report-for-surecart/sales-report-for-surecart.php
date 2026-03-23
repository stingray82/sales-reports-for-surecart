<?php
/**
 * Plugin Name:       Sales Report for SureCart
 * Description:       CSV Sales Reports for Individual products or Price IDs using the SureCart API
 * Tested up to:      6.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Version:           1.0.1
 * Author:            reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sales-report-for-surecart
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) return;


define('RUP_SR4SC_NOTIFIER_VERSION', '1.0.1');
define('RUP_SR4SC_NOTIFIER_SLUG', 'sales-report-for-surecart'); // Replace with your unique slug if needed
define('RUP_SR4SC_NOTIFIER_MAIN_FILE', __FILE__);
define('RUP_SR4SC_NOTIFIER_DIR', plugin_dir_path(__FILE__));
define('RUP_SR4SC_NOTIFIER_URL', plugin_dir_url(__FILE__));



/* ----------------------------- Menu ----------------------------- */
add_action( 'admin_menu', function () {
	add_management_page(
		'SureCart Export',
		'SureCart Export',
		'manage_options',
		'scx-export',
		'scx_render_export_page'
	);
} );

/* ----------------------------- Page ----------------------------- */
function scx_render_export_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

	$opt_key        = 'scx_api_token';
	$prod_cache_key = 'scx_products_cache_v1';

	$token = get_option( $opt_key );

	// Save token inline
	if ( isset( $_POST['scx_save_token'] ) && check_admin_referer( 'scx_save_token', 'scx_nonce_token' ) ) {
		$new = isset( $_POST['scx_api_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['scx_api_token'] ) ) ) : '';
		if ( $new ) {
			update_option( $opt_key, $new, true );
			delete_transient( $prod_cache_key );
			echo '<div class="updated"><p>API token saved.</p></div>';
			$token = $new;
		} else {
			delete_option( $opt_key );
			delete_transient( $prod_cache_key );
			echo '<div class="updated"><p>API token cleared.</p></div>';
			$token = '';
		}
	}

	// Refresh products (server-side button still available)
	if ( isset( $_GET['scx_refresh_products'] ) && check_admin_referer( 'scx_refresh_products' ) ) {
		delete_transient( $prod_cache_key );
		echo '<div class="updated"><p>Products refreshed.</p></div>';
	}

	$products = scx_get_products_from_api( false );

	// Pre-selections (to keep form state on submission errors etc.)
	$selected_product = isset( $_REQUEST['product_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['product_id'] ) ) : '';
	$selected_mode    = isset( $_REQUEST['scope'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['scope'] ) ) : 'product';
	$selected_price   = isset( $_REQUEST['price_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['price_id'] ) ) : '';

	$tools_url = admin_url( 'tools.php?page=scx-export' );
	$refresh_products_url = wp_nonce_url( add_query_arg( [ 'scx_refresh_products' => 1 ], $tools_url ), 'scx_refresh_products' );

	// Nonce for AJAX
	$ajax_nonce = wp_create_nonce( 'scx_fetch_prices' );

	?>
	<div class="wrap">
		<h1>SureCart Export</h1>

		<!-- API Token -->
		<h2>API Token</h2>
		<form method="post" action="<?php echo esc_url( $tools_url ); ?>">
			<?php wp_nonce_field( 'scx_save_token', 'scx_nonce_token' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="scx_api_token">API Token</label></th>
					<td>
						<input type="password" id="scx_api_token" name="scx_api_token" class="regular-text"
							placeholder="<?php echo $token ? 'Token is set (enter to replace)' : 'Paste your API token'; ?>">
						<p class="description">Stored with <code>update_option()</code>; not revealed once saved.</p>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary" name="scx_save_token" value="1">Save Token</button></p>
		</form>

		<hr>

		<!-- Export -->
		<h2>Export Purchases</h2>
		<?php if ( empty( $token ) ) : ?>
			<div class="notice notice-warning"><p><strong>API token not set.</strong> Save it above first.</p></div>
		<?php endif; ?>

		<form id="scx-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'scx_do_export', 'scx_export_nonce' ); ?>
			<input type="hidden" name="action" value="scx_do_export">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="scx_product_id">Product</label></th>
					<td>
						<select id="scx_product_id" name="product_id" style="min-width:350px" required>
							<option value="">— Select a product —</option>
							<?php foreach ( $products as $p ) : ?>
								<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $selected_product, $p['id'] ); ?>>
									<?php echo esc_html( $p['name'] . ( ! empty( $p['archived'] ) ? ' (archived)' : '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<a href="<?php echo esc_url( $refresh_products_url ); ?>" class="button" style="margin-left:6px;">Refresh products</a>
						<p class="description">Product list pulled from SureCart API (cached 15 min).</p>
					</td>
				</tr>

				<tr>
					<th scope="row">Scope</th>
					<td>
						<label><input type="radio" name="scope" value="product" <?php checked( $selected_mode, 'product' ); ?>> Entire product</label>
						&nbsp;&nbsp;
						<label><input type="radio" name="scope" value="price" <?php checked( $selected_mode, 'price' ); ?>> Specific price</label>
						<p class="description">Choose “Specific price” to export only one price variation under the selected product.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="scx_price_id">Price (optional)</label></th>
					<td>
						<select id="scx_price_id" name="price_id" style="min-width:350px" disabled>
							<option value="">— Select a price —</option>
						</select>
						<button type="button" id="scx_refresh_prices" class="button" disabled style="margin-left:6px;">Refresh prices</button>
						<p class="description">Pick a product, switch to “Specific price,” and prices will load automatically (or click Refresh).</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="include_revoked">Include revoked purchases?</label></th>
					<td><label><input type="checkbox" id="include_revoked" name="include_revoked" value="1"> Yes (unchecked = only active)</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="per_page">API page size</label></th>
					<td><input id="per_page" name="per_page" type="number" class="small-text" value="100" min="10" max="100"> <span class="description">Max 100 per page.</span></td>
				</tr>
			</table>

			<p class="submit"><button type="submit" class="button button-primary">Download CSV</button></p>
		</form>
	</div>

	<?php
	// Inline JS to handle AJAX loading of prices + enabling controls.
	?>
	<script>
(function(){
  const ajaxUrl   = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
  const ajaxNonce = "<?php echo esc_js( wp_create_nonce( 'scx_fetch_prices' ) ); ?>";

  const scopeRadios    = document.querySelectorAll('input[name="scope"]');
  const productSelect  = document.getElementById('scx_product_id');
  const priceSelect    = document.getElementById('scx_price_id');
  const refreshBtn     = document.getElementById('scx_refresh_prices');

  function setPriceUI(enabled){ priceSelect.disabled=!enabled; refreshBtn.disabled=!enabled; }
  function clearPrices(){ priceSelect.innerHTML = '<option value="">— Select a price —</option>'; }
  function maybeEnablePrices(){
    const scopeIsPrice = document.querySelector('input[name="scope"]:checked')?.value === 'price';
    setPriceUI(scopeIsPrice && !!productSelect.value);
  }

  async function loadPrices(force=false){
    if(!productSelect.value){ clearPrices(); setPriceUI(false); return; }
    setPriceUI(true);
    priceSelect.innerHTML = '<option value="">Loading…</option>';

    const params = new URLSearchParams();
    params.append('action','scx_fetch_prices');
    params.append('nonce', ajaxNonce);
    params.append('product_id', productSelect.value);
    if (force) params.append('force','1');

    try{
      const res  = await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()});
      const data = await res.json().catch(()=>null);
      clearPrices();

      if(!res.ok || !data?.success){
        const msg = data?.data?.human || `HTTP ${res.status}`;
        priceSelect.innerHTML = `<option value="">(Failed: ${msg})</option>`;
        console.error('Price load failed:', data || res.status);
        return;
      }

      const prices = Array.isArray(data.data?.prices) ? data.data.prices : [];
      if(!prices.length){
        priceSelect.innerHTML = '<option value="">(No prices for this product)</option>';
        return;
      }
      for(const pr of prices){
        const opt = document.createElement('option');
        opt.value = pr.id || '';
        opt.textContent = pr.name || pr.id || '(unnamed)';
        priceSelect.appendChild(opt);
      }
    }catch(err){
      clearPrices();
      priceSelect.innerHTML = '<option value="">(Network error)</option>';
      console.error('Network/JS error loading prices', err);
    }
  }

  scopeRadios.forEach(r => r.addEventListener('change', () => {
    maybeEnablePrices();
    if (document.querySelector('input[name="scope"]:checked').value === 'price') loadPrices(false);
  }));
  productSelect.addEventListener('change', () => {
    maybeEnablePrices();
    if (document.querySelector('input[name="scope"]:checked').value === 'price') loadPrices(false);
    else { clearPrices(); setPriceUI(false); }
  });
  refreshBtn.addEventListener('click', () => loadPrices(true));

  // initial
  maybeEnablePrices();
  if (document.querySelector('input[name="scope"]:checked')?.value === 'price' && productSelect.value) {
    loadPrices(false);
  }
})();
</script>

	<?php
}

/* ----------------------------- Export Action ----------------------------- */
add_action( 'admin_post_scx_do_export', 'scx_handle_export' );

function scx_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
	if ( ! isset( $_POST['scx_export_nonce'] ) || ! wp_verify_nonce( $_POST['scx_export_nonce'], 'scx_do_export' ) ) {
		wp_die( 'Invalid request.' );
	}

	$product_id      = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
	$scope           = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'product';
	$price_id        = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
	$include_revoked = ! empty( $_POST['include_revoked'] );
	$per_page        = isset( $_POST['per_page'] ) ? max( 10, min( 100, absint( $_POST['per_page'] ) ) ) : 100;

	if ( empty( $product_id ) ) wp_die( 'Product is required.' );
	if ( 'price' === $scope && empty( $price_id ) ) wp_die( 'Choose a price or switch scope to Entire product.' );
	if ( ! get_option( 'scx_api_token' ) ) wp_die( 'API token is not set.' );

	while ( function_exists( 'ob_get_level' ) && ob_get_level() ) { ob_end_clean(); }

	$filename = sprintf(
		'surecart-customers-%s%s-%s.csv',
		preg_replace( '/[^a-zA-Z0-9\-]/', '', $product_id ),
		( 'price' === $scope && $price_id ) ? '-'.$price_id : '',
		gmdate( 'Ymd-His' )
	);

	$page = 1; $wrote = false; $out = null;

	while ( true ) {
		$resp = scx_api_get( '/purchases', [
			'limit'  => $per_page,
			'page'   => $page,
			'expand' => [ 'customer', 'price', 'price.product', 'initial_order' ],
			'revoked'=> $include_revoked ? null : 'false',
		] );

		if ( is_wp_error( $resp ) ) wp_die( 'API error: ' . esc_html( $resp->get_error_message() ) );
		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			$s = function_exists('mb_substr') ? mb_substr($body,0,1200) : substr($body,0,1200);
			wp_die( 'API request failed (purchases). Status ' . esc_html($code) . '<pre style="white-space:pre-wrap;">' . esc_html($s) . '</pre>' );
		}

		$data  = json_decode( $body, true );
		$items = $data['data'] ?? ( is_array( $data ) ? $data : [] );
		$pg    = $data['pagination'] ?? null;

		if ( ! $wrote ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'X-Content-Type-Options: nosniff' );
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, [ 'customer_id','customer_name','customer_email','product_id','product_name','price_id','price_name','order_id','purchase_id','created_at' ] );
			$wrote = true;
		}

		foreach ( $items as $p ) {
			$customer     = $p['customer']      ?? [];
			$price        = $p['price']         ?? [];
			$product      = $price['product']   ?? ( $p['product'] ?? [] );
			$initialOrder = $p['initial_order'] ?? [];

			if ( 'price' === $scope ) {
				if ( ($price['id'] ?? null) !== $price_id ) continue;
			} else {
				if ( ($product['id'] ?? null) !== $product_id ) continue;
			}

			fputcsv( $out, [
				$customer['id'] ?? '',
				trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) ),
				$customer['email'] ?? '',
				$product['id'] ?? '',
				$product['name'] ?? '',
				$price['id'] ?? '',
				$price['name'] ?? '',
				$initialOrder['id'] ?? '',
				$p['id'] ?? '',
				$p['created_at'] ?? '',
			] );
		}

		if ( is_array( $pg ) ) {
			$total = intval( $pg['count'] ?? 0 );
			$lim   = intval( $pg['limit'] ?? $per_page );
			$cur   = intval( $pg['page']  ?? $page );
			if ( $total <= $lim * $cur ) break; $page++;
		} else {
			if ( count( $items ) < $per_page ) break; $page++;
		}
	}

	if ( $out ) fclose( $out );
	exit;
}

/* ----------------------------- AJAX: fetch prices ----------------------------- */
add_action( 'wp_ajax_scx_fetch_prices', 'scx_ajax_fetch_prices' );
function scx_ajax_fetch_prices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'human' => 'Forbidden (capability)' ], 403 );
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'scx_fetch_prices' ) ) {
		wp_send_json_error( [ 'human' => 'Bad nonce (reload page)' ], 403 );
	}

	$product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
	$force      = ! empty( $_POST['force'] );

	if ( empty( $product_id ) ) {
		wp_send_json_success( [ 'prices' => [] ] );
	}

	// If you still have the cached helper, you can keep using it:
	// $prices = scx_get_prices_for_product( $product_id, $force );
	// wp_send_json_success( [ 'prices' => $prices ] );

	// Instead, call the API directly here so we can surface precise errors:
	$page  = 1;
	$limit = 100;
	$all   = [];

	while ( true ) {
		$resp = scx_api_get( '/prices', [
			'product_ids' => [ $product_id ], // per docs
			'limit'       => $limit,
			'page'        => $page,
		] );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( [ 'human' => $resp->get_error_message() ], 502 );
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );

		if ( $code !== 200 ) {
			// Send back trimmed raw API response so you can see exact reason (401/403/422/etc.)
			$sample = function_exists('mb_substr') ? mb_substr( $body, 0, 800 ) : substr( $body, 0, 800 );
			wp_send_json_error( [
				'human' => 'API /prices failed',
				'code'  => $code,
				'body'  => $sample,
			], $code );
		}

		$data  = json_decode( $body, true );
		$items = $data['data'] ?? ( is_array( $data ) ? $data : [] );
		if ( empty( $items ) ) break;

		foreach ( $items as $pr ) {
			$all[] = [
				'id'   => $pr['id']   ?? '',
				'name' => $pr['name'] ?? ($pr['id'] ?? ''),
			];
		}

		$pg = $data['pagination'] ?? null;
		if ( is_array( $pg ) ) {
			$total = (int) ( $pg['count'] ?? 0 );
			$lim   = (int) ( $pg['limit'] ?? $limit );
			$cur   = (int) ( $pg['page']  ?? $page );
			if ( $total <= $lim * $cur ) break;
			$page++;
		} else {
			if ( count( $items ) < $limit ) break;
			$page++;
		}
	}

	wp_send_json_success( [ 'prices' => $all ] );
}

/* ----------------------------- API helpers ----------------------------- */
function scx_api_base() { return 'https://api.surecart.com/v1'; }

function scx_api_get( $path, array $params = [] ) {
	$token = get_option( 'scx_api_token' );
	if ( empty( $token ) ) return new WP_Error( 'scx_no_token', 'API token not set.' );

	$q = [];
	foreach ( $params as $k => $v ) {
		if ( is_array( $v ) ) foreach ( $v as $vv ) { $q[] = rawurlencode($k).'[]='.rawurlencode((string)$vv); }
		elseif ( $v !== '' && $v !== null ) { $q[] = rawurlencode($k).'='.rawurlencode((string)$v); }
	}
	$url = rtrim( scx_api_base(), '/' ) . $path . ( $q ? '?'.implode('&',$q) : '' );

	return wp_remote_get( $url, [
		'timeout' => 30,
		'headers' => [ 'Authorization' => 'Bearer '.$token, 'Accept'=>'application/json' ],
	] );
}

/* ----------------------------- Cached data loaders ----------------------------- */
function scx_get_products_from_api( $force_refresh = false ) {
	$cache_key = 'scx_products_cache_v1';
	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;
	}

	$out = []; $page = 1; $limit = 100;
	while ( true ) {
		$resp = scx_api_get( '/products', [ 'limit'=>$limit, 'page'=>$page ] );
		if ( is_wp_error($resp) ) break;
		if ( 200 !== wp_remote_retrieve_response_code($resp) ) break;
		$data  = json_decode( wp_remote_retrieve_body($resp), true );
		$items = $data['data'] ?? ( is_array($data) ? $data : [] );
		if ( empty($items) ) break;
		foreach ( $items as $p ) $out[] = [ 'id'=>$p['id']??'', 'name'=>$p['name']??'(no name)', 'archived'=>!empty($p['archived']) ];
		$pg = $data['pagination'] ?? null;
		if ( is_array($pg) ) { $total=intval($pg['count']??0); $lim=intval($pg['limit']??$limit); $cur=intval($pg['page']??$page); if ($total <= $lim*$cur) break; $page++; }
		else { if ( count($items) < $limit ) break; $page++; }
	}
	set_transient( $cache_key, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
}

function scx_get_prices_for_product( $product_id, $force_refresh = false ) {
	$cache_key = 'scx_prices_cache_v4_' . md5( $product_id );
	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;
	}

	$out   = [];
	$page  = 1;
	$limit = 100;

	while ( true ) {
		$resp = scx_api_get( '/prices', [
			'product_ids' => [ $product_id ],  // <-- official param per API docs
			'limit'       => $limit,
			'page'        => $page,
		] );

		if ( is_wp_error( $resp ) ) break;
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) break;

		$data  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$items = $data['data'] ?? [];
		if ( empty( $items ) ) break;

		foreach ( $items as $pr ) {
			$out[] = [
				'id'   => $pr['id']   ?? '',
				'name' => $pr['name'] ?? $pr['id'], // fall back to ID if name missing
			];
		}

		$pg = $data['pagination'] ?? null;
		if ( is_array( $pg ) ) {
			$total = intval( $pg['count'] ?? 0 );
			$lim   = intval( $pg['limit'] ?? $limit );
			$cur   = intval( $pg['page']  ?? $page );
			if ( $total <= $lim * $cur ) break;
			$page++;
		} else {
			if ( count( $items ) < $limit ) break;
			$page++;
		}
	}

	set_transient( $cache_key, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
}


// ──────────────────────────────────────────────────────────────────────────
//  Updater bootstrap (plugins_loaded priority 20):
// ──────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // 1) Load our universal drop-in. Because that file begins with "namespace UUPD\V1;",
    //    both the class and the helper live under UUPD\V2.
    require_once __DIR__ . '/inc/updater.php';

    // 2) Build a single $updater_config array:
    $updater_config = [
    	'vendor'      => 'RUP',
        'plugin_file' => plugin_basename(__FILE__),             // e.g. "simply-static-export-notify/simply-static-export-notify.php"
        'slug'        => RUP_SR4SC_NOTIFIER_SLUG,           // must match your updater‐server slug
        'name'        => 'Sales Report for SureCart',         // human‐readable plugin name
        'version'     => RUP_SR4SC_NOTIFIER_VERSION, // same as the VERSION constant above
        'key'         => '',                 // your secret key for private updater
        'server'      => 'https://raw.githubusercontent.com/stingray82/sales-reports-for-surecart/main/uupd/index.json',
    ];

    // 3) Call the helper in the UUPD\V2 namespace:
    \RUP\Updater\Updater_V2::register( $updater_config );
}, 20 );

// MainWP Icon Filter

add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {

    if ('sales-report-for-surecart/sales-report-for-surecart.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/sales-reports-for-surecart/main/uupd/icon-128.png'; // Supported types: jpeg, jpg, gif, ico, png
    }

    return $info;

}, 10, 2);
