<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
$id=(int)($_GET['id']??0);
if($id<=0){header('Location: index.php');exit;}
$s=$conn->prepare("SELECT s.*,pl.name price_list_name,COALESCE(c.customer_name,'Walk-in Customer') customer_name,c.phone,c.address,u.full_name cashier FROM sales s LEFT JOIN price_lists pl ON pl.id=s.price_list_id LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.sale_status='Completed'");
$s->execute([$id]);$sale=$s->fetch();
if(!$sale){http_response_code(404);exit('Sale not found.');}
$receiptRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$receiptRoleCode = strtoupper(trim((string)($_SESSION['role_code'] ?? '')));
if ((in_array($receiptRole, ['cashier', 'sales'], true) || in_array($receiptRoleCode, ['CASHIER', 'SALES'], true)) && (int)$sale['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(403);
    exit('You can only view receipts for sales recorded under your login.');
}
$i=$conn->prepare("SELECT si.quantity,si.price,si.subtotal,si.entered_quantity,si.sale_unit,si.conversion_factor,si.covering_quantity,si.covering_cm_per_book,si.covering_charge_per_book,p.product_name,p.sku,p.unit FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=? ORDER BY si.id");$i->execute([$id]);$items=$i->fetchAll();
$amountTendered=(float)($sale['amount_tendered']??$sale['paid_amount']);$drawerSaleId=(int)($_SESSION['cash_drawer_sale_id']??0);$hasSalePaymentLines=(bool)$conn->query("SHOW TABLES LIKE 'sale_payment_lines'")->fetchColumn();$paymentLines=[];$cashConfirmed=0.0;if($hasSalePaymentLines){$plq=$conn->prepare("SELECT payment_method,amount,status,transaction_reference,created_at,confirmed_at FROM sale_payment_lines WHERE sale_id=? ORDER BY id");$plq->execute([$id]);$paymentLines=$plq->fetchAll(PDO::FETCH_ASSOC);foreach($paymentLines as $pl){if($pl['payment_method']==='Cash'&&$pl['status']==='Confirmed')$cashConfirmed+=(float)$pl['amount'];}}else{$cashConfirmed=$sale['payment_method']==='Cash'?(float)$sale['paid_amount']:0.0;}$openCashDrawer=$drawerSaleId===$id&&$cashConfirmed>0;if($drawerSaleId===$id)unset($_SESSION['cash_drawer_sale_id']);
$flash=$_SESSION['sale_flash']??null;unset($_SESSION['sale_flash']);
$company=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='company_name' LIMIT 1")->fetchColumn()?:'Dibonke Bookshop');
if($company===''||strcasecmp($company,'Stalk & Stable')===0)$company='Dibonke Bookshop';
$currency=$conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES';
?>
<!doctype html><html><head><meta charset="utf-8"><title>Receipt <?=e($sale['invoice_number'])?></title><style>
body{margin:0;background:#eef2f7;font-family:Arial,sans-serif;color:#111827}.actions{padding:15px;text-align:center}.actions button,.actions a{border:0;background:#004a99;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;cursor:pointer;margin:0 4px;font-weight:700}.actions a{background:#64748b}.receipt{width:72mm;margin:10px auto;background:#fff;padding:3mm;box-sizing:border-box}.center{text-align:center}.company{font-size:19px;font-weight:900;letter-spacing:.2px}.small{font-size:10px;color:#475569}.line{border-top:1px dashed #111827;margin:9px 0}.meta{font-size:10px;line-height:1.45;overflow-wrap:anywhere}.items{width:100%;border-collapse:collapse;font-size:10px;table-layout:fixed}.items th:first-child,.items td:first-child{width:54%;overflow-wrap:anywhere}.items th:nth-child(2),.items td:nth-child(2){width:14%}.items th:last-child,.items td:last-child{width:32%}.items th{text-align:left;border-bottom:1px solid #111827;padding-bottom:5px}.items td{padding:4px 1px;vertical-align:top;overflow-wrap:anywhere}.num{text-align:right}.total{font-size:15px;font-weight:900}.thanks{margin-top:12px;font-size:10px;line-height:1.45}.flash{background:#ecfdf5;color:#166534;padding:8px;font-size:11px;text-align:center;margin-bottom:8px}@media print{html,body{width:80mm;background:#fff}.actions{display:none}.receipt{width:72mm;margin:0 auto;padding:3mm;box-shadow:none;page-break-after:avoid}@page{size:80mm auto;margin:0}}
</style></head><body>
<div class="actions"><button type="button" onclick="printReceiptDirect()"><i>🖨</i> Print Receipt</button><a href="index.php">Back to POS</a><div id="printStatus" class="small" role="status" aria-live="polite" style="margin-top:8px"></div></div>
<div class="receipt" data-open-drawer="<?= $openCashDrawer ? '1' : '0' ?>">
<?php if($flash): ?><div class="flash"><?=e($flash)?></div><?php endif; ?>
<div class="center"><div class="company"><?=e($company)?></div><div class="small">BOOKS · STATIONERY · GENERAL RETAIL</div><div class="small" style="font-weight:700;margin-top:3px">Tel: 0706 046963</div></div>
<div class="line"></div><div class="meta"><strong>Receipt:</strong> <?=e($sale['invoice_number'])?><br><strong>Date:</strong> <?=e($sale['sale_date'])?><br><strong>Cashier:</strong> <?=e($sale['cashier']?:'Staff')?></div>
<div class="line"></div>
<table class="items"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?=e($item['product_name'])?><?php if((int)$item['covering_quantity']>0): ?><br><small>Covering service: <?=number_format((int)$item['covering_quantity'])?> book(s) · Extra charge KES <?=number_format((float)$item['covering_charge_per_book']*(int)$item['covering_quantity'],2)?></small><?php endif; ?></td><td class="num"><?=e($item['entered_quantity'] ?? $item['quantity'])?> <?=e($item['sale_unit'] ?? $item['unit'])?></td><td class="num"><?=e($currency)?> <?=number_format((float)$item['subtotal'],2)?></td></tr><?php endforeach; ?></tbody></table>
<div class="line"><div class="meta"><div><span>Subtotal</span><span style="float:right"><?=e($currency)?> <?=number_format((float)$sale['subtotal'],2)?></span></div><div><span>Discount</span><span style="float:right"><?=e($currency)?> <?=number_format((float)$sale['discount'],2)?></span></div><div class="total"><span>TOTAL</span><span style="float:right"><?=e($currency)?> <?=number_format((float)$sale['total_amount'],2)?></span></div><div style="font-weight:800;margin-top:5px"><span>Amount paid</span><span style="float:right"><?=e($currency)?> <?=number_format((float)$sale['paid_amount'],2)?></span></div><div><span>Payment</span><span style="float:right"><?=e($sale['payment_method'])?></span></div><?php if($paymentLines):?><div style="margin-top:5px;font-size:10px;font-weight:800">Tender breakdown</div><?php foreach($paymentLines as $pl):?><div><span><?=e($pl['payment_method'])?><?=($pl['status']==='Pending'?' (pending)':($pl['status']==='Failed'?' (failed)':''))?></span><span style="float:right"><?=e($currency)?> <?=number_format((float)$pl['amount'],2)?></span></div><?php endforeach;?><?php endif;?><?php if($amountTendered>(float)$sale['paid_amount']&&$cashConfirmed>0):?><div><span>Cash change</span><span style="float:right"><?=e($currency)?> <?=number_format(max(0,$amountTendered-$cashConfirmed),2)?></span></div><?php endif;?></div></div>
<div class="center thanks">Thank you for shopping with us.<br>We appreciate your business.</div>
</div><script src="https://cdn.jsdelivr.net/npm/qz-tray@2.3.0/qz-tray.min.js"></script>
<script>
const DIBONKE_PRINTER_NAME = 'Xprinter XP-80';
let dibonkePrintBusy = false;
function setPrintStatus(message) {
    const el = document.getElementById('printStatus');
    if (el) el.textContent = message;
}
async function printReceiptDirect() {
    if (dibonkePrintBusy) return;
    dibonkePrintBusy = true;
    setPrintStatus('Connecting to the receipt printer…');
    let receiptPrinted = false;
    try {
        if (!window.qz) throw new Error('QZ Tray print library did not load. Check your internet connection.');
        // Authenticate Dibonke with QZ Tray before requesting printer access.
        qz.security.setCertificatePromise(function(resolve, reject) {
            fetch('qz_certificate.php', { cache: 'no-store', credentials: 'same-origin' })
                .then(function(response) {
                    if (!response.ok) throw new Error('Dibonke QZ certificate could not be loaded.');
                    return response.text();
                })
                .then(resolve)
                .catch(reject);
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function(toSign) {
            return function(resolve, reject) {
                fetch('qz_sign.php?request=' + encodeURIComponent(toSign), {
                    cache: 'no-store',
                    credentials: 'same-origin'
                })
                    .then(function(response) {
                        if (!response.ok) throw new Error('Dibonke could not sign the QZ Tray request.');
                        return response.text();
                    })
                    .then(resolve)
                    .catch(reject);
            };
        });
        if (!qz.websocket.isActive()) await qz.websocket.connect();
        const printer = await qz.printers.find(DIBONKE_PRINTER_NAME);
        const receipt = document.querySelector('.receipt');
        const css = document.querySelector('style').textContent;
        const html = '<!doctype html><html><head><meta charset="utf-8"><style>' +
            css +
            'html,body{width:80mm;margin:0;padding:0;background:#fff}.receipt{width:72mm;margin:0 auto;padding:3mm;box-shadow:none}@page{size:80mm auto;margin:0}' +
            '</style></head><body>' + receipt.outerHTML + '</body></html>';
        const config = qz.configs.create(printer, { margins: 0, orientation: 'portrait' });
        await qz.print(config, [{
            type: 'pixel',
            format: 'html',
            flavor: 'plain',
            data: html
        }]);
        receiptPrinted = true;
    } catch (error) {
        console.error('Dibonke direct receipt printing failed:', error);
        setPrintStatus('Direct printing is unavailable. Opening the normal print window instead.');
        dibonkePrintBusy = false;
        window.print();
        return;
    }

    const receipt = document.querySelector('.receipt');
    const shouldOpenDrawer = receipt && receipt.dataset.openDrawer === '1';
    if (shouldOpenDrawer) {
        try {
            setPrintStatus('Receipt printed. Opening the cash drawer…');
            const drawerConfig = qz.configs.create(DIBONKE_PRINTER_NAME, { forceRaw: true });
            await qz.print(drawerConfig, [{
                type: 'raw',
                format: 'command',
                data: '\x1B\x70\x00\x19\xFA'
            }]);
            setPrintStatus('Receipt printed and cash drawer command sent.');
        } catch (error) {
            console.error('Dibonke cash drawer command failed:', error);
            setPrintStatus('Receipt printed, but the cash drawer did not respond. Check its cable and printer drawer settings.');
        }
    } else {
        setPrintStatus('Receipt printed.');
    }
    dibonkePrintBusy = false;
}
window.addEventListener('load', () => {
    if (new URLSearchParams(location.search).get('autoprint') === '1') {
        setTimeout(printReceiptDirect, 600);
    }
});
</script></body></html>