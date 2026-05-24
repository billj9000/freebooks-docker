<?php
/**
 * ZenCart → FreeBooks order import cron script.
 *
 * Pulls new orders from ZenCart via the REST API and creates FreeBooks
 * Sales Orders (journal 10).  Updates ZENCART_LAST_ORDER after each
 * successful import so reruns are safe.
 *
 * Usage:
 *   php /var/www/html/cron/zencart_sync.php [company_name]
 *
 * company_name may also be supplied via the FREEBOOKS_COMPANY environment
 * variable.  When omitted the script auto-detects the first company directory
 * found under my_files/.
 */

define('PAGE_EXECUTION_START_TIME', microtime(true));

// ─── Resolve the trunk root ───────────────────────────────────────────────────
$trunk = realpath(__DIR__ . '/..');
if ($trunk === false) {
    fwrite(STDERR, "Cannot resolve trunk path from " . __DIR__ . "\n");
    exit(1);
}
$fs_admin = $trunk . '/';

if (!file_exists($fs_admin . 'includes/configure.php')) {
    fwrite(STDERR, "configure.php not found at " . $fs_admin . "includes/configure.php\n");
    exit(1);
}
require($fs_admin . 'includes/configure.php');
// configure.php defines DIR_FS_ADMIN; define it here only if it didn't
if (!defined('DIR_FS_ADMIN')) define('DIR_FS_ADMIN', $fs_admin);

// ─── Path constants (mirrors application_top.php) ────────────────────────────
if (!defined('PATH_TO_MY_FILES')) define('PATH_TO_MY_FILES', 'my_files/');
define('DIR_WS_MODULES',   'modules/');
define('DIR_WS_MY_FILES',  PATH_TO_MY_FILES);
define('DIR_FS_INCLUDES',  DIR_FS_ADMIN . 'includes/');
define('DIR_FS_MODULES',   DIR_FS_ADMIN . 'modules/');
define('DIR_FS_MY_FILES',  DIR_FS_ADMIN . PATH_TO_MY_FILES);
define('DIR_FS_THEMES',    DIR_FS_ADMIN . 'themes/');
define('FILENAME_DEFAULT', 'index');
define('COG_ITEM_TYPES',   'si,sr,ms,mi,ma,sa');
define('DIR_WS_ICONS',     '');  // no icons needed in CLI context

if (file_exists(DIR_FS_ADMIN . 'vendor/autoload.php')) {
    require_once(DIR_FS_ADMIN . 'vendor/autoload.php');
}

// ─── Fake HTTP + session context for CLI ─────────────────────────────────────
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['PHP_SELF']    = '/cron/zencart_sync.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_REQUEST = $_GET = $_POST = [];
$_SESSION = ['language' => 'en_us', 'admin_id' => 1];

// ─── Resolve company ─────────────────────────────────────────────────────────
$company = (string)($argv[1] ?? getenv('FREEBOOKS_COMPANY') ?? '');
if ($company === '') {
    // Auto-detect: first directory in my_files/ that contains config.php
    $mf = DIR_FS_MY_FILES;
    if (is_dir($mf)) {
        foreach (scandir($mf) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($mf . $entry) && file_exists($mf . $entry . '/config.php')) {
                $company = $entry;
                break;
            }
        }
    }
}
if ($company === '' || !file_exists(DIR_FS_MY_FILES . $company . '/config.php')) {
    fwrite(STDERR, "Company '$company' not found. Usage: php zencart_sync.php <company_name>\n");
    exit(1);
}
$_SESSION['company'] = $company;

// ─── Load shared code ────────────────────────────────────────────────────────
// Suppress E_WARNING during language bootstrap: several constants (GEN_PRIMARY_NAME,
// TEXT_BUYER, TEXT_SALES_REP) are defined in multiple upstream language files.
$_prev_er = error_reporting(E_ALL & ~E_WARNING);
require_once(DIR_FS_MODULES . 'phreedom/language/en_us/language.php');
require_once(DIR_FS_MODULES . 'phreedom/defaults.php');
require_once(DIR_FS_INCLUDES . 'common_functions.php');
require_once(DIR_FS_INCLUDES . 'common_classes.php');
error_reporting($_prev_er); unset($_prev_er);

// ─── Database connection ──────────────────────────────────────────────────────
define('DB_DATABASE', $company);
require_once(DIR_FS_MY_FILES . $company . '/config.php');
if (!defined('DB_SERVER_HOST') && defined('DB_SERVER')) define('DB_SERVER_HOST', DB_SERVER);
require_once(DIR_FS_INCLUDES . 'db/' . DB_TYPE . '/query_factory.php');
$db = new queryFactory();
$db->connect(DB_SERVER_HOST, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);

// Load all pb_configuration constants
$_cfg = $db->Execute("SELECT configuration_key, configuration_value FROM " . DB_PREFIX . "configuration");
while (!$_cfg->EOF) {
    if (!defined($_cfg->fields['configuration_key'])) {
        define($_cfg->fields['configuration_key'], $_cfg->fields['configuration_value']);
    }
    $_cfg->MoveNext();
}

// ─── TABLE_* constants ────────────────────────────────────────────────────────
// Defined directly rather than loading module config.php files, which are
// menu-builders that require web UI language constants unavailable in CLI.
// phreedom
define('TABLE_AUDIT_LOG',                 DB_PREFIX . 'audit_log');
define('TABLE_CONFIGURATION',             DB_PREFIX . 'configuration');
define('TABLE_CURRENCIES',                DB_PREFIX . 'currencies');
define('TABLE_CURRENT_STATUS',            DB_PREFIX . 'current_status');
define('TABLE_DATA_SECURITY',             DB_PREFIX . 'data_security');
define('TABLE_EXTRA_FIELDS',              DB_PREFIX . 'xtra_fields');
define('TABLE_EXTRA_TABS',                DB_PREFIX . 'xtra_tabs');
define('TABLE_USERS',                     DB_PREFIX . 'users');
// phreebooks
define('TABLE_ACCOUNTING_PERIODS',        DB_PREFIX . 'accounting_periods');
define('TABLE_ACCOUNTS_HISTORY',          DB_PREFIX . 'accounts_history');
define('TABLE_CHART_OF_ACCOUNTS',         DB_PREFIX . 'chart_of_accounts');
define('TABLE_CHART_OF_ACCOUNTS_HISTORY', DB_PREFIX . 'chart_of_accounts_history');
define('TABLE_INVENTORY_ASSY_LIST',       DB_PREFIX . 'inventory_assy_list');
define('TABLE_INVENTORY_COGS_OWED',       DB_PREFIX . 'inventory_cogs_owed');
define('TABLE_INVENTORY_COGS_USAGE',      DB_PREFIX . 'inventory_cogs_usage');
define('TABLE_INVENTORY_HISTORY',         DB_PREFIX . 'inventory_history');
define('TABLE_INVENTORY_MS_LIST',         DB_PREFIX . 'inventory_ms_list');
define('TABLE_INVENTORY_PURCHASE',        DB_PREFIX . 'inventory_purchase_details');
define('TABLE_JOURNAL_ITEM',              DB_PREFIX . 'journal_item');
define('TABLE_JOURNAL_MAIN',              DB_PREFIX . 'journal_main');
define('TABLE_RECONCILIATION',            DB_PREFIX . 'reconciliation');
define('TABLE_TAX_AUTH',                  DB_PREFIX . 'tax_authorities');
define('TABLE_TAX_RATES',                 DB_PREFIX . 'tax_rates');
// contacts
define('TABLE_ADDRESS_BOOK',              DB_PREFIX . 'address_book');
define('TABLE_CONTACTS',                  DB_PREFIX . 'contacts');
define('TABLE_CONTACTS_LOG',              DB_PREFIX . 'contacts_log');
define('TABLE_DEPARTMENTS',               DB_PREFIX . 'departments');
define('TABLE_PRICE_SHEETS',              DB_PREFIX . 'price_sheets');
// inventory
define('TABLE_INVENTORY',                 DB_PREFIX . 'inventory');
define('TABLE_INVENTORY_SPECIAL_PRICES',  DB_PREFIX . 'inventory_special_prices');

// ─── Core objects ────────────────────────────────────────────────────────────
$messageStack = new messageStack;
$currencies   = new currencies();

// Language strings needed by journal classes and ZenCart module
$_prev_er = error_reporting(E_ALL & ~E_WARNING);
gen_pull_language('contacts');          // GEN_CONTACT, GEN_ADDRESS1, …
gen_pull_language('phreebooks');        // TEXT_SUCCESSFULLY_POSTED, GL_ERROR_*, …
gen_pull_language('phreebooks', 'menu'); // ORD_TEXT_10_WINDOW_TITLE
error_reporting($_prev_er); unset($_prev_er);
if (file_exists(DIR_FS_MODULES . 'zencart/language/' . $_SESSION['language'] . '/language.php')) {
    require_once(DIR_FS_MODULES . 'zencart/language/' . $_SESSION['language'] . '/language.php');
}

// Inventory helpers (inv_calculate_prices used by zencart::syncQuantities/uploadProduct)
require_once(DIR_FS_MODULES . 'inventory/functions/inventory.php');

// PhreeBooks journal classes and helpers
require_once(DIR_FS_MODULES . 'phreebooks/classes/gen_ledger.php');
require_once(DIR_FS_MODULES . 'phreebooks/classes/orders.php');
require_once(DIR_FS_MODULES . 'phreebooks/classes/banking.php');
require_once(DIR_FS_MODULES . 'phreebooks/functions/phreebooks.php');

// Journal 18 (Cash Receipts) subclass — bypasses the JOURNAL_ID constant check in
// banking::__construct() which would die() since JOURNAL_ID is defined as 10 above.
class zc_banking extends banking {
    function __construct() {
        $this->journal_id          = 18;
        $this->save_payment        = false;
        $this->currencies_code     = DEFAULT_CURRENCY;
        $this->currencies_value    = '1.0';
        $this->gl_acct_id          = defined('AR_SALES_RECEIPTS_ACCOUNT') ? AR_SALES_RECEIPTS_ACCOUNT : AR_DEFAULT_GL_ACCT;
        $this->gl_disc_acct_id     = defined('AR_DISCOUNT_SALES_ACCOUNT') ? AR_DISCOUNT_SALES_ACCOUNT : AR_DEFAULT_GL_ACCT;
        $this->purchase_invoice_id = '';
    }

    // Simplified post_ordr() that skips the payment-processor machinery — the
    // payment was already captured by ZenCart; we only need to record it in the GL.
    function post_ordr($action) {
        global $db, $currencies, $messageStack;
        $this->journal_main_array = $this->build_journal_main_array();
        $this->journal_rows = [];

        // Credit: AR account for each invoice being paid
        $total = 0.0;
        foreach ($this->item_rows as $row) {
            $amount = round((float)$row['total'] + (float)$row['dscnt'], 8);
            $this->journal_rows[] = [
                'so_po_item_ref_id' => $row['id'],   // journal_main id of the invoice
                'gl_type'           => $row['gl_type'],
                'description'       => $row['desc'],
                'credit_amount'     => $amount,
                'gl_account'        => $row['acct'],
                'serialize_number'  => $row['inv'] ?? '',
                'post_date'         => $this->post_date,
            ];
            $total += $amount;
        }

        // Debit: bank / cash account
        $this->journal_rows[] = [
            'gl_type'      => 'ttl',
            'debit_amount' => round($total, 8),
            'description'  => $this->description,
            'gl_account'   => $this->gl_acct_id,
            'post_date'    => $this->post_date,
        ];

        $db->transStart();
        if (!$this->validate_purchase_invoice_id()) return false;
        if (!$this->Post('insert'))                 return false;
        if (!$this->increment_purchase_invoice_id()) return false;
        $db->transCommit();
        return true;
    }
}

// Journal 10 = Sales Order
define('JOURNAL_ID',      10);
define('GL_TYPE',         'soo');
define('DEF_GL_ACCT',     AR_DEFAULT_GL_ACCT);
define('DEF_INV_GL_ACCT', AR_DEF_GL_SALES_ACCT);

// ZenCart module
define('DIR_FS_WORKING', DIR_FS_MODULES . 'zencart/');
if (!file_exists(DIR_FS_WORKING . 'functions/zencart.php')) {
    fwrite(STDERR, "ZenCart module not installed in trunk/modules/zencart/. Aborting.\n");
    exit(1);
}
require_once(DIR_FS_WORKING . 'functions/zencart.php');
require_once(DIR_FS_WORKING . 'classes/zencart.php');

// ─── Helper: convert a country value to a 3-letter ISO code (FreeBooks format) ─
// Accepts ISO-2 ('US'), ISO-3 ('USA'), or full names ('United States').
// Falls back to COMPANY_COUNTRY when the input is empty or unrecognised.
function zc_country_3(string $raw): string {
    if ($raw === '') return COMPANY_COUNTRY;
    static $countries = null;
    if ($countries === null) $countries = gen_pull_countries();
    if (strlen($raw) === 3) return $raw;                           // already ISO-3
    if (strlen($raw) <= 2) {                                       // ISO-2 → ISO-3
        $iso3 = gen_get_country_iso_3_from_2($raw, $countries);
        return ($iso3 !== $raw) ? $iso3 : COMPANY_COUNTRY;
    }
    // Full country name — not supported; use company default
    return COMPANY_COUNTRY;
}

// ─── Helper: normalize an address field string (trim + collapse whitespace) ───
function zc_addr(string $s): string {
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ─── Helper: create a FreeBooks customer contact from ZenCart address data ────
function zc_create_customer(array $bill, string $email, string $date): array
{
    global $db;

    // Derive a unique short_name (max 32 chars): email → phone → billing name
    $raw = $email !== '' ? $email : trim((string)($bill['phone'] ?? ''));
    if ($raw === '') $raw = trim((string)($bill['name'] ?? ''));
    $base = substr($raw, 0, 28);
    $short_name = $base;
    $n = 1;
    while (true) {
        $dup = $db->Execute("SELECT id FROM " . TABLE_CONTACTS
            . " WHERE short_name = '" . db_input($short_name) . "' LIMIT 1");
        if ($dup->EOF) break;
        $short_name = $base . '-' . (++$n);
    }

    $contact = [
        'type'        => 'c',
        'short_name'  => $short_name,
        'inactive'    => '0',
        'first_date'  => $date,
        'last_update' => $date,
    ];
    $r = db_perform(TABLE_CONTACTS, $contact, 'insert');
    if ($r->AffectedRows() != 1) return [0, 0];
    $acct_id = db_insert_id();

    $cust_company = zc_addr((string)($bill['company'] ?? ''));
    $cust_person  = zc_addr((string)($bill['name']    ?? ''));
    $addr = [
        'ref_id'         => $acct_id,
        'type'           => 'cm', // customer main — matches what load_bill.php AJAX searches for
        'primary_name'   => substr($cust_company !== '' ? $cust_company : $cust_person, 0, 32),
        'contact'        => substr($cust_company !== '' ? $cust_person  : '', 0, 32),
        'address1'       => substr(zc_addr((string)($bill['addr1']      ?? '')), 0, 32),
        'address2'       => substr(zc_addr((string)($bill['addr2']      ?? '')), 0, 32),
        'city_town'      => substr(zc_addr((string)($bill['city']       ?? '')), 0, 24),
        'state_province' => substr(zc_addr((string)($bill['state']      ?? '')), 0, 24),
        'postal_code'    => substr(zc_addr((string)($bill['postalCode'] ?? '')), 0, 10),
        'country_code'   => zc_country_3($bill['countryCode'] ?? $bill['country'] ?? ''),
        'telephone1'     => substr(zc_addr((string)($bill['phone']      ?? '')), 0, 20),
        'email'          => substr($email, 0, 48),
    ];
    $r = db_perform(TABLE_ADDRESS_BOOK, $addr, 'insert');
    if ($r->AffectedRows() != 1) return [0, 0];
    $addr_id = db_insert_id();

    return [$acct_id, $addr_id];
}

// ─── Helper: extract plain-text messages from messageStack ───────────────────
function zc_drain_messages(): string
{
    $parts = [];
    foreach ($_SESSION['messageToStack'] ?? [] as $m) {
        $parts[] = strip_tags($m['text'] ?? '');
    }
    unset($_SESSION['messageToStack']);
    return implode('; ', array_filter($parts));
}

// ─── Fetch new orders from ZenCart ───────────────────────────────────────────
if (!defined('ZENCART_URL') || ZENCART_URL === 'https://') {
    echo ts() . " ZenCart not configured (ZENCART_URL unset). Exiting.\n";
    exit(0);
}

$last_order = (int)(defined('ZENCART_LAST_ORDER') ? ZENCART_LAST_ORDER : 0);
$response   = zencart_api_call('orders', null, ['last_order' => $last_order]);

if ($response === false) {
    fwrite(STDERR, ts() . " Failed to fetch orders from ZenCart: " . zc_drain_messages() . "\n");
    exit(1);
}

$zc_orders = $response['orders'] ?? [];
if (empty($zc_orders)) {
    echo ts() . " No new orders (last_order=$last_order).\n";
    zc_sync_stock();
    exit(0);
}
echo ts() . " Fetched " . count($zc_orders) . " order(s) from ZenCart.\n";

// ─── Import each order ────────────────────────────────────────────────────────
$default_tax_id = (int)(defined('ZENCART_DEFAULT_TAX_RATE_ID') ? ZENCART_DEFAULT_TAX_RATE_ID : 0);
$catchall_acct  = (int)(defined('ZENCART_CUSTOMER_ACCOUNT_ID') ? ZENCART_CUSTOMER_ACCOUNT_ID : 0);

// Build a map of whole-number VAT percentage → FreeBooks tax_rate_id (type='c' / AR only).
// Also keep the full rate list and authority table for rounding pre-calculation.
$zc_tax_rates   = ord_calculate_tax_drop_down('c'); // [{id, rate, text, auths}, ...]
$zc_tax_auths   = gen_build_tax_auth_array();        // [auth_id => {tax_rate, account_id, ...}]
$zc_tax_pct_map = [];
foreach ($zc_tax_rates as $rate) {
    if ((int)$rate['id'] > 0) {
        $pct = (int)round((float)$rate['rate']);
        if (!isset($zc_tax_pct_map[$pct])) {
            $zc_tax_pct_map[$pct] = (int)$rate['id'];
        }
    }
}
// Build a map of ZenCart payment module code → [fbmodule, glacct].
// Configured as "zcmodule=fbmodule:glacct,..." in ZENCART_PAYMENT_MAP.
// Either fbmodule or glacct may be omitted (e.g. "paypalexpress=paypal_nvp" or "stripe=:1030").
$payment_map = [];
if (defined('ZENCART_PAYMENT_MAP') && ZENCART_PAYMENT_MAP !== '') {
    foreach (explode(',', ZENCART_PAYMENT_MAP) as $_entry) {
        $_parts = explode('=', trim($_entry), 2);
        if (count($_parts) === 2 && trim($_parts[0]) !== '') {
            $_val   = explode(':', trim($_parts[1]), 2);
            $payment_map[trim($_parts[0])] = [
                'module' => trim($_val[0]),
                'gl'     => isset($_val[1]) ? trim($_val[1]) : '',
            ];
        }
    }
}
unset($_entry, $_parts, $_val);

$imported       = 0;
$highest_id     = $last_order;

foreach ($zc_orders as $zc) {
    $zc_id  = (int)($zc['orderId'] ?? 0);
    $po_ref = 'ZC-' . $zc_id;

    // Skip orders already imported
    $dup = $db->Execute(
        "SELECT id FROM " . TABLE_JOURNAL_MAIN
        . " WHERE purch_order_id = '" . db_input($po_ref) . "' LIMIT 1"
    );
    if (!$dup->EOF) {
        echo ts() . " Order $zc_id already imported (FB id {$dup->fields['id']}), skipping.\n";
        $highest_id = max($highest_id, $zc_id);
        continue;
    }

    // Address sections and date — needed for both customer creation and order posting
    $bill = $zc['billingAddress']  ?? $zc['customerAddress'] ?? [];
    // Fall back to billing when ZenCart delivery_* columns are all NULL
    $ship_raw = $zc['shippingAddress'] ?? [];
    $ship = (trim((string)($ship_raw['name'] ?? '')) !== '' || trim((string)($ship_raw['addr1'] ?? '')) !== '')
        ? $ship_raw : $bill;
    // Order currency — use ZenCart's currency if it exists in FreeBooks, else fall back
    $zc_currency  = strtoupper(trim((string)($zc['currency'] ?? '')));
    $zc_conv_rate = (float)($zc['conversionRate'] ?? 1.0);
    if ($zc_currency === '' || $zc_currency === DEFAULT_CURRENCY) {
        $zc_currency  = DEFAULT_CURRENCY;
        $zc_conv_rate = 1.0;
    } elseif (!isset($currencies->currencies[$zc_currency])) {
        fwrite(STDERR, ts() . " Order $zc_id: currency '$zc_currency' not in FreeBooks currencies table — using default.\n");
        $zc_currency  = DEFAULT_CURRENCY;
        $zc_conv_rate = 1.0;
    }

    $post_date = date('Y-m-d', strtotime($zc['orderDate'] ?? 'now'));
    $period    = gen_calculate_period($post_date, true);
    if (!$period) {
        fwrite(STDERR, ts() . " Order $zc_id: $post_date is outside accounting periods. Skipping.\n");
        continue;
    }

    // Find customer contact by email
    $email   = trim($zc['email'] ?? '');
    $acct_id = 0;
    $addr_id = 0;
    if ($email !== '') {
        $cust = $db->Execute(
            "SELECT a.ref_id, a.address_id"
            . " FROM " . TABLE_ADDRESS_BOOK . " a"
            . " INNER JOIN " . TABLE_CONTACTS . " c ON c.id = a.ref_id"
            . " WHERE c.type = 'c'"
            . "   AND a.type IN ('bm','cm')"
            . "   AND a.email = '" . db_input($email) . "'"
            . " ORDER BY a.address_id ASC LIMIT 1"
        );
        if (!$cust->EOF) {
            $acct_id = (int)$cust->fields['ref_id'];
            $addr_id = (int)$cust->fields['address_id'];
        }
    }
    if (!$acct_id) {
        [$acct_id, $addr_id] = zc_create_customer($bill, $email, $post_date);
        if (!$acct_id) {
            fwrite(STDERR, ts() . " Order $zc_id: could not create customer for '$email'. Skipping.\n");
            continue;
        }
        echo ts() . " Created customer contact $acct_id for '$email'\n";
    }

    // Separate items: products / shipping / coupon discount
    $discount     = 0.0;
    $items        = [];
    $ship_item    = null;
    foreach ($zc['items'] ?? [] as $item) {
        $sku = (string)($item['sku'] ?? '');
        if ($sku === 'Shipping') {
            $ship_item = $item; // handled below as a taxed line item
        } elseif ($sku === 'Discount') {
            $discount += abs((float)($item['unitPrice'] ?? 0));
        } else {
            $items[] = $item;
        }
    }
    if (empty($items)) {
        fwrite(STDERR, ts() . " Order $zc_id: no product lines. Skipping.\n");
        continue;
    }

    // Helper: map a ZenCart tax% to a FreeBooks tax_rate_id
    $zc_tax_id = function(float $pct) use ($zc_tax_pct_map, $default_tax_id): int {
        if ($pct > 0.0) return $zc_tax_pct_map[(int)round($pct)] ?? $default_tax_id;
        if ($pct === 0.0) return 0;
        return $default_tax_id; // field absent
    };

    // Build item_rows for products
    $item_rows = [];
    foreach ($items as $idx => $item) {
        $sku        = (string)($item['sku'] ?? '');
        $qty        = max(1, (int)($item['quantity'] ?? 1));
        $unit_price = round((float)($item['unitPrice'] ?? 0), 8);
        $inv_acct   = AR_DEF_GL_SALES_ACCT;
        $inv_tax    = $zc_tax_id((float)($item['tax'] ?? -1.0));

        if ($sku !== '') {
            $inv = $db->Execute(
                "SELECT account_sales_income"
                . " FROM " . TABLE_INVENTORY
                . " WHERE sku = '" . db_input($sku) . "' LIMIT 1"
            );
            if (!$inv->EOF) {
                if ($inv->fields['account_sales_income'] !== '') $inv_acct = $inv->fields['account_sales_income'];
                // Do NOT use inventory.item_taxable: FreeBooks defaults it to 0 (untaxed),
                // which would silently strip VAT from every known SKU.
                // ZenCart's per-item tax% is the authoritative source for taxability.
            }
        }

        $item_rows[] = [
            'id'                     => '',
            'so_po_item_ref_id'      => '',
            'item_cnt'               => $idx + 1,
            'gl_type'                => GL_TYPE,
            'sku'                    => $sku,
            'qty'                    => $qty,
            'pstd'                   => 0,
            'desc'                   => (string)($item['name'] ?? $sku),
            'proj'                   => '',
            'purch_package_quantity' => 1,
            'date_1'                 => '',
            'price'                  => $unit_price,
            'full'                   => $unit_price,
            'acct'                   => $inv_acct,
            'tax'                    => $inv_tax,
            'total'                  => round($unit_price * $qty, 8),
            'weight'                 => (float)($item['weight'] ?? 0),
            'serial'                 => '',
            'stock'                  => '',
            'inactive'               => '0',
            'lead_time'              => 0,
        ];
    }

    // Shipping goes into the Freight field. Tax rate is derived from ZenCart's shipping tax %,
    // mapped to a FreeBooks tax rate ID via the same lookup used for product lines.
    // ZenCart's ot_shipping value is already ex-VAT.
    $ship_ex_vat         = ($ship_item !== null) ? round((float)($ship_item['unitPrice'] ?? 0), 8) : 0.0;
    $ship_freight_tax_id = ($ship_item !== null) ? $zc_tax_id((float)($ship_item['tax'] ?? 0)) : 0;

    // disc_percent: with ENABLE_ORDER_DISCOUNT=1 and AR_TAX_BEFORE_DISCOUNT=0, FreeBooks
    // multiplies each taxable line total by (1 - disc_percent) before computing tax.
    // Freight is excluded from discount (FreeBooks never applies disc_percent to frt rows).
    $_all_line_totals = array_sum(array_column($item_rows, 'total'));
    $disc_percent = ($discount > 0.0 && $_all_line_totals > 0.0)
        ? $discount / $_all_line_totals
        : 0.0;
    unset($_all_line_totals);

    // Rounding adjustment: pre-calculate the tax FreeBooks will produce (mirroring
    // add_tax_journal_rows) and compare with ZenCart's paid total. Any difference is
    // a rounding discrepancy — add a zero-tax line to make the totals agree exactly.
    $zc_paid = round((float)($zc['totalAmount'] ?? 0), 2);
    $_auth_totals = [];
    foreach ($item_rows as $_row) {
        if (($_row['tax'] ?? 0) > 0) {
            foreach ($zc_tax_rates as $_rate) {
                if ($_rate['id'] == $_row['tax']) {
                    foreach (explode(':', $_rate['auths']) as $_auth) {
                        $_auth_totals[$_auth] = ($_auth_totals[$_auth] ?? 0.0)
                            + ($zc_tax_auths[$_auth]['tax_rate'] / 100) * $_row['total'] * (1 - $disc_percent);
                    }
                }
            }
        }
    }
    // Freight tax is not discounted
    if ($ship_ex_vat > 0.0 && $ship_freight_tax_id > 0) {
        foreach ($zc_tax_rates as $_rate) {
            if ($_rate['id'] == $ship_freight_tax_id) {
                foreach (explode(':', $_rate['auths']) as $_auth) {
                    $_auth_totals[$_auth] = ($_auth_totals[$_auth] ?? 0.0)
                        + ($zc_tax_auths[$_auth]['tax_rate'] / 100) * $ship_ex_vat;
                }
                break;
            }
        }
    }
    $_dec = $currencies->currencies[$zc_currency]['decimal_places'] ?? $currencies->currencies[DEFAULT_CURRENCY]['decimal_places'];
    $_fb_tax = 0.0;
    foreach ($_auth_totals as $_a => $_t) {
        $_fb_tax += defined('ROUND_TAX_BY_AUTH') && ROUND_TAX_BY_AUTH
            ? (float)number_format($_t, $_dec, '.', '')
            : $_t;
    }
    $_fb_total = round(
        array_sum(array_column($item_rows, 'total')) + $ship_ex_vat + $_fb_tax - $discount,
        2
    );
    $_rounding = round($zc_paid - $_fb_total, 2);
    if (abs($_rounding) > 0.001 && abs($_rounding) < 1.00) {
        $item_rows[] = [
            'id'                     => '',
            'so_po_item_ref_id'      => '',
            'item_cnt'               => count($item_rows) + 1,
            'gl_type'                => GL_TYPE,
            'sku'                    => 'ROUNDING',
            'qty'                    => 1,
            'pstd'                   => 0,
            'desc'                   => 'Rounding adjustment',
            'proj'                   => '',
            'purch_package_quantity' => 1,
            'date_1'                 => '',
            'price'                  => $_rounding,
            'full'                   => $_rounding,
            'acct'                   => AR_DEF_GL_SALES_ACCT,
            'tax'                    => 0,
            'total'                  => $_rounding,
            'weight'                 => 0,
            'serial'                 => '',
            'stock'                  => '',
            'inactive'               => '0',
            'lead_time'              => 0,
        ];
        echo ts() . " Order $zc_id: rounding adjustment {$_rounding}\n";
    }
    unset($_auth_totals, $_fb_tax, $_fb_total, $_rounding, $_dec, $_a, $_t, $_row, $_rate, $_auth);

    // Build order description (stored in journal_main.description)
    $desc = 'ZenCart Order #' . $zc_id;
    if (!empty($zc['comments'])) {
        $desc .= ' - ' . substr((string)$zc['comments'], 0, 180);
    }

    // Populate orders object
    $order = new orders();
    $order->account_type     = 'c';
    $order->bill_add_update  = 0;    // use existing contact
    $order->ship_add_update  = 0;
    $order->bill_acct_id     = $acct_id;
    $order->bill_address_id  = $addr_id;
    $order->ship_acct_id     = $acct_id;
    $order->ship_address_id  = $addr_id;

    $bill_company = zc_addr((string)($bill['company'] ?? ''));
    $bill_person  = zc_addr((string)($bill['name']    ?? ''));
    $order->bill_primary_name   = $bill_company !== '' ? $bill_company : $bill_person;
    $order->bill_contact        = $bill_company !== '' ? $bill_person  : '';
    $order->bill_address1       = zc_addr((string)($bill['addr1']      ?? ''));
    $order->bill_address2       = zc_addr((string)($bill['addr2']      ?? ''));
    $order->bill_city_town      = zc_addr((string)($bill['city']       ?? ''));
    $order->bill_state_province = zc_addr((string)($bill['state']      ?? ''));
    $order->bill_postal_code    = zc_addr((string)($bill['postalCode'] ?? ''));
    $order->bill_country_code   = zc_country_3($bill['countryCode'] ?? $bill['country'] ?? '');
    $order->bill_telephone1     = zc_addr((string)($zc['phone']        ?? ''));
    $order->bill_email          = $email;

    $ship_company = zc_addr((string)($ship['company'] ?? ''));
    $ship_person  = zc_addr((string)($ship['name']    ?? ''));
    $order->ship_primary_name   = $ship_company !== '' ? $ship_company : $ship_person;
    $order->ship_contact        = $ship_company !== '' ? $ship_person  : '';
    $order->ship_address1       = zc_addr((string)($ship['addr1']      ?? ''));
    $order->ship_address2       = zc_addr((string)($ship['addr2']      ?? ''));
    $order->ship_city_town      = zc_addr((string)($ship['city']       ?? ''));
    $order->ship_state_province = zc_addr((string)($ship['state']      ?? ''));
    $order->ship_postal_code    = zc_addr((string)($ship['postalCode'] ?? ''));
    $order->ship_country_code   = zc_country_3($ship['countryCode'] ?? $ship['country'] ?? '');
    $order->ship_telephone1     = $order->bill_telephone1;
    $order->ship_email          = $order->bill_email;

    $order->post_date     = $post_date;
    $order->period        = $period;
    $order->purch_order_id = $po_ref;
    $order->description   = $desc;
    $order->freight          = $ship_ex_vat;
    $order->freight_tax_id   = $ship_freight_tax_id;
    $order->discount         = $discount;
    $order->disc_percent     = $disc_percent;
    $order->currencies_code  = $zc_currency;
    $order->currencies_value = $zc_conv_rate;
    $order->rep_id           = (int)(defined('ZENCART_DEFAULT_REP_ID') ? ZENCART_DEFAULT_REP_ID : 0);
    $order->item_rows        = $item_rows;

    if (!$order->post_ordr('insert')) {
        $errs = zc_drain_messages();
        fwrite(STDERR, ts() . " Order $zc_id: post_ordr failed" . ($errs ? ": $errs" : '') . "\n");
        break; // preserve order sequence — stop on first failure
    }

    $so_id  = (int)$order->id;
    $so_num = $order->purchase_invoice_id;
    zc_drain_messages(); // discard "Successfully posted …" message
    echo ts() . " Imported ZC order $zc_id → FB SO $so_num\n";

    // Optionally create a linked Sales Invoice (journal 12)
    if ((defined('ZENCART_CREATE_INVOICE') ? ZENCART_CREATE_INVOICE : '0') === '1' && $so_id > 0) {
        // Fetch the SO's journal_item rows so we can link invoice lines to them
        $so_items = $db->Execute(
            "SELECT id, item_cnt FROM " . TABLE_JOURNAL_ITEM
            . " WHERE ref_id = $so_id AND gl_type = 'soo'"
            . " ORDER BY item_cnt ASC"
        );
        $so_item_map = []; // item_cnt => so journal_item id
        while (!$so_items->EOF) {
            $so_item_map[(int)$so_items->fields['item_cnt']] = (int)$so_items->fields['id'];
            $so_items->MoveNext();
        }

        // Build invoice item_rows — same as SO but pstd=qty (fully invoiced) and linked back
        $inv_rows = [];
        foreach ($item_rows as $row) {
            $cnt = (int)$row['item_cnt'];
            $row['gl_type']          = 'sos';
            $row['pstd']             = $row['qty'];
            $row['so_po_item_ref_id'] = $so_item_map[$cnt] ?? '';
            $inv_rows[] = $row;
        }

        $invoice = new orders();
        $invoice->journal_id    = 12;
        $invoice->gl_type       = 'sos';
        $invoice->account_type     = 'c';
        $invoice->bill_add_update  = 0;
        $invoice->ship_add_update  = 0;
        $invoice->bill_acct_id     = $order->bill_acct_id;
        $invoice->bill_address_id  = $order->bill_address_id;
        $invoice->ship_acct_id     = $order->ship_acct_id;
        $invoice->ship_address_id  = $order->ship_address_id;

        $invoice->bill_primary_name   = $order->bill_primary_name;
        $invoice->bill_contact        = $order->bill_contact;
        $invoice->bill_address1       = $order->bill_address1;
        $invoice->bill_address2       = $order->bill_address2;
        $invoice->bill_city_town      = $order->bill_city_town;
        $invoice->bill_state_province = $order->bill_state_province;
        $invoice->bill_postal_code    = $order->bill_postal_code;
        $invoice->bill_country_code   = $order->bill_country_code;
        $invoice->bill_telephone1     = $order->bill_telephone1;
        $invoice->bill_email          = $order->bill_email;

        $invoice->ship_primary_name   = $order->ship_primary_name;
        $invoice->ship_contact        = $order->ship_contact;
        $invoice->ship_address1       = $order->ship_address1;
        $invoice->ship_address2       = $order->ship_address2;
        $invoice->ship_city_town      = $order->ship_city_town;
        $invoice->ship_state_province = $order->ship_state_province;
        $invoice->ship_postal_code    = $order->ship_postal_code;
        $invoice->ship_country_code   = $order->ship_country_code;

        $invoice->post_date          = $post_date;
        $invoice->period             = $period;
        $invoice->purch_order_id     = '';  // auto-assigned invoice number
        $invoice->description        = $desc;
        $invoice->freight            = $ship_ex_vat;
        $invoice->freight_tax_id     = $ship_freight_tax_id;
        $invoice->discount           = $discount;
        $invoice->disc_percent       = $disc_percent;
        $invoice->currencies_code    = $order->currencies_code;
        $invoice->currencies_value   = $order->currencies_value;
        $invoice->rep_id             = $order->rep_id;
        $invoice->so_po_ref_id       = $so_id;
        $invoice->item_rows          = $inv_rows;

        if (!$invoice->post_ordr('insert')) {
            $errs = zc_drain_messages();
            fwrite(STDERR, ts() . " Order $zc_id: invoice post_ordr failed" . ($errs ? ": $errs" : '') . "\n");
            // SO was already committed — log the error but don't break the import loop
        } else {
            $invoice_id  = (int)$invoice->id;
            $invoice_num = $invoice->purchase_invoice_id;
            zc_drain_messages();
            echo ts() . " Created invoice $invoice_num for ZC order $zc_id\n";

            // Apply the ZenCart payment to the invoice when a payment amount is present
            $payment_amount = round((float)($zc['totalAmount'] ?? 0.0), 8);
            if ($payment_amount > 0 && $invoice_id > 0) {
                $pay = new zc_banking();
                $pay->bill_acct_id       = $acct_id;
                $pay->bill_address_id    = $addr_id;
                $pay->bill_primary_name  = $order->bill_primary_name;
                $pay->bill_contact       = $order->bill_contact;
                $pay->bill_address1      = $order->bill_address1;
                $pay->bill_address2      = $order->bill_address2;
                $pay->bill_city_town     = $order->bill_city_town;
                $pay->bill_state_province = $order->bill_state_province;
                $pay->bill_postal_code   = $order->bill_postal_code;
                $pay->bill_country_code  = $order->bill_country_code;
                $pay->bill_telephone1    = $order->bill_telephone1;
                $pay->bill_email         = $order->bill_email;
                $pay->post_date          = $post_date;
                $pay->period             = $period;
                $pay->currencies_code    = $order->currencies_code;
                $pay->currencies_value   = $order->currencies_value;
                $pay->purchase_invoice_id = 'DP' . str_replace('-', '', $post_date);
                $pm = (string)($zc['paymentModule'] ?? '');
                $pay->shipper_code = $pm !== '' ? $pm : 'freecharger';
                if ($pm !== '' && isset($payment_map[$pm])) {
                    if ($payment_map[$pm]['module'] !== '') $pay->shipper_code = $payment_map[$pm]['module'];
                    if ($payment_map[$pm]['gl']     !== '') $pay->gl_acct_id   = $payment_map[$pm]['gl'];
                }
                $pay_ref = (string)($zc['paymentRef'] ?? '');
                $pay->purch_order_id = $pay_ref !== '' ? $pay_ref : 'ZC-' . $zc_id;
                $pay->description    = 'ZenCart order #' . $zc_id
                    . ($pay_ref !== '' ? ' — ' . $pay_ref : '');
                $pay->admin_id           = (int)$_SESSION['admin_id'];
                $pay->total_amount       = $payment_amount;

                $pay->item_rows[] = [
                    'id'      => $invoice_id,         // journal_main id of the invoice
                    'gl_type' => 'pmt',
                    'amt'     => $payment_amount,
                    'desc'    => 'Invoice ' . $invoice_num,
                    'dscnt'   => 0.0,
                    'total'   => $payment_amount,
                    'inv'     => $invoice_num,
                    'prcnt'   => 0,
                    'early'   => $post_date,
                    'due'     => $post_date,
                    'pay'     => true,
                    'acct'    => AR_DEFAULT_GL_ACCT,
                ];

                if (!$pay->post_ordr('insert')) {
                    $errs = zc_drain_messages();
                    fwrite(STDERR, ts() . " Order $zc_id: payment post failed" . ($errs ? ": $errs" : '') . "\n");
                    // invoice is already committed — log the error but continue
                } else {
                    zc_drain_messages();
                    echo ts() . " Applied payment {$pay->purchase_invoice_id} ({$zc_currency} {$payment_amount}) for ZC order $zc_id\n";
                }
            }
        }
    }

    $highest_id = max($highest_id, $zc_id);
    write_configure('ZENCART_LAST_ORDER', (string)$highest_id);
    $imported++;
}

echo ts() . " Done. Imported $imported order(s). Highest ZC order ID: $highest_id\n";
zc_sync_stock();
exit(0);

// ─────────────────────────────────────────────────────────────────────────────
function zc_sync_stock(): void
{
    echo ts() . " Syncing stock quantities to ZenCart...\n";
    $sync = new zencart();
    $sync_ok = $sync->syncQuantities();
    $sync_msgs = zc_drain_messages();
    if ($sync_ok) {
        echo ts() . " Stock sync complete." . ($sync_msgs ? " ($sync_msgs)" : '') . "\n";
    } else {
        fwrite(STDERR, ts() . " Stock sync failed" . ($sync_msgs ? ": $sync_msgs" : '') . "\n");
    }
}

function ts(): string
{
    return '[' . date('Y-m-d H:i:s') . ']';
}
