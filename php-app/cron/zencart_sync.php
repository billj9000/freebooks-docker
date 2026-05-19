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
define('DIR_FS_ADMIN', $trunk . '/');

if (!file_exists(DIR_FS_ADMIN . 'includes/configure.php')) {
    fwrite(STDERR, "configure.php not found at " . DIR_FS_ADMIN . "includes/configure.php\n");
    exit(1);
}
require(DIR_FS_ADMIN . 'includes/configure.php');

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
require_once(DIR_FS_MODULES . 'phreedom/language/en_us/language.php');
require_once(DIR_FS_MODULES . 'phreedom/defaults.php');
require_once(DIR_FS_INCLUDES . 'common_functions.php');
require_once(DIR_FS_INCLUDES . 'common_classes.php');

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

// Load TABLE_* constants from phreedom and installed module config files
require_once(DIR_FS_MODULES . 'phreedom/config.php');
foreach (scandir(DIR_FS_MODULES) as $_mod) {
    if ($_mod === '.' || $_mod === '..') continue;
    if (!is_dir(DIR_FS_MODULES . $_mod)) continue;
    if (defined('MODULE_' . strtoupper($_mod) . '_STATUS')) {
        $_cfg_file = DIR_FS_MODULES . $_mod . '/config.php';
        if (file_exists($_cfg_file)) require_once($_cfg_file);
    }
}

// ─── Core objects ────────────────────────────────────────────────────────────
$messageStack = new messageStack;
$currencies   = new currencies();

// Language strings needed by journal classes
gen_pull_language('contacts');          // GEN_CONTACT, GEN_ADDRESS1, …
gen_pull_language('phreebooks');        // TEXT_SUCCESSFULLY_POSTED, GL_ERROR_*, …
gen_pull_language('phreebooks', 'menu'); // ORD_TEXT_10_WINDOW_TITLE

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
    exit(0);
}
echo ts() . " Fetched " . count($zc_orders) . " order(s) from ZenCart.\n";

// ─── Import each order ────────────────────────────────────────────────────────
$default_tax_id = (int)(defined('ZENCART_DEFAULT_TAX_RATE_ID') ? ZENCART_DEFAULT_TAX_RATE_ID : 0);
$catchall_acct  = (int)(defined('ZENCART_CUSTOMER_ACCOUNT_ID') ? ZENCART_CUSTOMER_ACCOUNT_ID : 0);
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
        if (!$catchall_acct) {
            fwrite(STDERR, ts() . " Order $zc_id: no contact for '$email' and ZENCART_CUSTOMER_ACCOUNT_ID not set. Skipping.\n");
            continue;
        }
        $acct_id = $catchall_acct;
    }

    // Address sections
    $bill = $zc['billingAddress']  ?? $zc['customerAddress'] ?? [];
    $ship = $zc['shippingAddress'] ?? $bill;

    // Validate accounting period
    $post_date = date('Y-m-d', strtotime($zc['orderDate'] ?? 'now'));
    $period    = gen_calculate_period($post_date, true);
    if (!$period) {
        fwrite(STDERR, ts() . " Order $zc_id: $post_date is outside accounting periods. Skipping.\n");
        continue;
    }

    // Separate items: products / shipping / coupon discount
    $freight  = 0.0;
    $discount = 0.0;
    $items    = [];
    foreach ($zc['items'] ?? [] as $item) {
        $sku = (string)($item['sku'] ?? '');
        if ($sku === 'Shipping') {
            $freight += (float)($item['unitPrice'] ?? 0);
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

    // Build item_rows
    $item_rows = [];
    foreach ($items as $idx => $item) {
        $sku        = (string)($item['sku'] ?? '');
        $qty        = max(1, (int)($item['quantity'] ?? 1));
        $unit_price = round((float)($item['unitPrice'] ?? 0), 8);
        $inv_acct   = AR_DEF_GL_SALES_ACCT;
        $inv_tax    = $default_tax_id;

        if ($sku !== '') {
            $inv = $db->Execute(
                "SELECT account_sales_income, item_taxable"
                . " FROM " . TABLE_INVENTORY
                . " WHERE sku = '" . db_input($sku) . "' LIMIT 1"
            );
            if (!$inv->EOF) {
                if ($inv->fields['account_sales_income'] !== '') $inv_acct = $inv->fields['account_sales_income'];
                if ((int)$inv->fields['item_taxable'] > 0)       $inv_tax  = (int)$inv->fields['item_taxable'];
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

    $order->bill_primary_name   = (string)($bill['name']       ?? '');
    $order->bill_contact        = '';
    $order->bill_address1       = (string)($bill['addr1']      ?? '');
    $order->bill_address2       = (string)($bill['addr2']      ?? '');
    $order->bill_city_town      = (string)($bill['city']       ?? '');
    $order->bill_state_province = (string)($bill['state']      ?? '');
    $order->bill_postal_code    = (string)($bill['postalCode'] ?? '');
    $order->bill_country_code   = (string)($bill['country']    ?? COMPANY_COUNTRY);
    $order->bill_telephone1     = (string)($zc['phone']        ?? '');
    $order->bill_email          = $email;

    $order->ship_primary_name   = (string)($ship['name']       ?? '');
    $order->ship_contact        = '';
    $order->ship_address1       = (string)($ship['addr1']      ?? '');
    $order->ship_address2       = (string)($ship['addr2']      ?? '');
    $order->ship_city_town      = (string)($ship['city']       ?? '');
    $order->ship_state_province = (string)($ship['state']      ?? '');
    $order->ship_postal_code    = (string)($ship['postalCode'] ?? '');
    $order->ship_country_code   = (string)($ship['country']    ?? COMPANY_COUNTRY);

    $order->post_date     = $post_date;
    $order->period        = $period;
    $order->purch_order_id = $po_ref;
    $order->description   = $desc;
    $order->freight       = $freight;
    $order->discount      = $discount;
    $order->disc_percent  = 0;
    $order->item_rows     = $item_rows;

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

        $invoice->post_date      = $post_date;
        $invoice->period         = $period;
        $invoice->purch_order_id = '';  // auto-assigned invoice number
        $invoice->description    = $desc;
        $invoice->freight        = $freight;
        $invoice->discount       = $discount;
        $invoice->disc_percent   = 0;
        $invoice->so_po_ref_id   = $so_id;
        $invoice->item_rows      = $inv_rows;

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
                $pay->purchase_invoice_id = '';  // auto-assign receipt number
                $pay->shipper_code       = (string)($zc['paymentModule'] ?? 'freecharger');
                $pay->purch_order_id     = 'ZC-' . $zc_id;
                $pay->description        = 'ZenCart payment for order #' . $zc_id
                    . (($zc['paymentRef'] ?? '') !== '' ? ' ref ' . $zc['paymentRef'] : '');
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
                    echo ts() . " Applied payment {$pay->purchase_invoice_id} (\${$payment_amount}) for ZC order $zc_id\n";
                }
            }
        }
    }

    $highest_id = max($highest_id, $zc_id);
    write_configure('ZENCART_LAST_ORDER', (string)$highest_id);
    $imported++;
}

echo ts() . " Done. Imported $imported order(s). Highest ZC order ID: $highest_id\n";
exit($imported > 0 || $highest_id > $last_order ? 0 : 0);

// ─────────────────────────────────────────────────────────────────────────────
function ts(): string
{
    return '[' . date('Y-m-d H:i:s') . ']';
}
