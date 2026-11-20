# Dibonke Bookshop — General User Manual

**For:** Cashiers, Sales Assistants, Storekeepers, Finance Staff and other everyday users.

This guide explains the day-to-day tasks you may perform in Dibonke. The options visible to you depend on the access assigned to your account.

## 1. Sign in and find your way around

1. Open the Dibonke system address given to you by your supervisor.
2. Sign in with your own username and password.
3. Use the navigation menu to open the area you need.
4. Sign out when you finish, especially on a shared computer.

Never share your password or use another employee's account.

## 2. Find and manage products

- Open **Products & Books** to search for an item or view its details.
- Product records may include a SKU/barcode, ISBN, category, buying price, selling price and available quantity.
- Products can have a smallest stock/sale unit (for example Piece) and a purchase unit (for example Box or Carton). The product setup shows how many stock units are in one purchase unit.
- Books may have an ISBN; stationery and general retail products may use a SKU or barcode.
- If you notice an incorrect description or price, report it to your supervisor rather than creating a duplicate product.

## Product barcodes and ISBNs

- **ISBN** means International Standard Book Number. It identifies a book edition and is usually printed near the barcode on the back cover. Modern ISBNs have 13 digits; older books may have 10 digits.
- **Barcode** is the number scanned by the scanner at the POS or when receiving stock. A book's barcode often represents its ISBN. When the barcode is the ISBN, you do not need to enter the same number twice: scan or enter it in the product's Barcode field and use the ISBN field for the book's ISBN only when it is separately available or required for catalogue records.
- **SKU** is Dibonke's internal product code. If you leave SKU blank when creating a product, the system generates one automatically.
- Stationery and other general products do not need an ISBN. A barcode is optional for products without one; they can still be found by product name or SKU.

### Add a product by scanning its barcode

1. Open **Products & Books** and select **Add Product**.
2. The Barcode field is focused automatically. Connect a standard USB barcode scanner configured as a keyboard (HID mode) to the computer before scanning.
3. Scan the barcode on the product. The scanner enters its digits into the Barcode field. You can also type the number manually.
4. Many scanners send Enter after a scan. In Dibonke, this moves the cursor to Product Name; it does not save the incomplete product.
5. Fill in the product name and any other required details, such as item type, category, buying and selling prices, units and opening stock.
6. Save the product. Check that the barcode is correct and is not already assigned to another product.

A scanner normally behaves like a keyboard, so no separate POS pairing is required for a standard USB HID scanner. If it does not type into the field, check its USB connection and scanner mode, then try typing into the field manually. A barcode scan does not fill in product descriptions or prices; enter any missing details yourself.

## 3. Receive stock

If you are assigned to receiving:

1. Open **Purchase Orders & Receiving** and select the relevant approved purchase order.
2. Scan the product barcode, ISBN or SKU. A successful scan adds one unit to the matching outstanding line.
3. Change the quantity when the delivered quantity is different.
4. Enter the supplier invoice number, invoice date and invoice total.
5. Check every item and quantity against the physical delivery and supplier invoice.
6. Submit the receipt to create the Goods Received Note.

Only stock that has been confirmed on the receipt is added to inventory. Report shortages, damaged items and wrong deliveries to the supervisor before posting.

Do not enter the same delivery again in the Purchase Register.

## 4. Make a walk-in sale at the POS

1. If you are a cashier, open **My Cashier Shift** first. Enter the actual opening float and open the shift.
2. Open **Sales & POS**.
3. Scan the barcode or type part of the product name, SKU or ISBN in the product search box. Matching products should appear as you type.
4. Select the product and enter the quantity. Add other items to the basket as needed.
5. Check the basket and total, then choose the payment method shown (Cash, M-Pesa or Bank).
6. Complete the sale and provide the customer with the receipt.

The POS is for quick walk-in sales. It does not create customer credit orders. Use **Customer Sales Orders** for an existing customer's order that needs review and invoicing.

For cash payments, the receipt shows the amount received, the amount applied to the sale and the change due. A QR code is printed on the receipt to identify the receipt reference and total. The receipt does not display the internal price-list name. The receipt contact number is **0706 046963**.

For direct counter printing, keep **QZ Tray** running on the POS computer and leave the Xprinter connected. Dibonke sends the receipt to the Windows printer named **Xprinter XP-80** without opening Chrome's printer-settings window. If QZ Tray is unavailable, the normal browser print window is used as a fallback. QZ Tray may display a security permission prompt until this Dibonke site is trusted; fully silent printing requires a trusted QZ signing certificate to be configured.

The cash drawer is connected to the receipt printer. After a successful receipt print immediately following a new sale, Dibonke sends the drawer-open command only when the sale has a cash payment amount greater than zero. This is a one-time action for that new sale; reprinting an old receipt does not open the drawer again. M-Pesa and Bank sales do not trigger the drawer. If the receipt prints but the drawer does not open, check that the drawer cable is connected to the printer's cash-drawer port and report it to the administrator. Do not repeatedly complete test sales to troubleshoot the drawer.

If a product does not scan, search by name, SKU or ISBN. If typing does not show matching products, refresh the page and report the problem to your supervisor.

### Filter products by category

- Select a category button above the POS product list, such as **Pens**, to show only products assigned to that category.
- Select **All Products** to return to the full catalogue.
- Product search works within the selected category. Clear the search to see all products in that category again.

## 5. Customer sales orders and pending invoices

Use this process when an existing customer is ordering goods and the order must be checked before an invoice is created. Do not use the POS for this workflow.

### Create and validate an order
1. Open **Customer Sales Orders**.
2. Select the correct existing customer.
3. Add the products and enter the quantities. For products with pack or carton pricing, choose the appropriate selling unit.
4. Check the prices, quantities and order total, then save the order as a draft.
5. Open the saved order, check it against the customer's request and select **Validate** when it is correct.

Validation checks stock availability. It does not yet deduct stock or create the final invoice. The validated order moves to **Pending Invoices**.

### Confirm and print the invoice
1. Open **Pending Invoices** and select the order.
2. Review the customer, items, quantities, prices and total again.
3. Confirm the order only when it is ready to be invoiced. Stock is checked again at confirmation.
4. Open the resulting invoice and print it or save it as PDF.

Confirming the order creates the credit invoice and updates stock and the customer's outstanding balance. Collect and record the customer's payment separately through **Customer Payments**. If stock is no longer sufficient or the customer's credit limit would be exceeded, ask the supervisor to resolve the issue before confirming.

## 6. Customer accounts and payments

### Record a customer payment

1. Open **Customer Payments**.
2. Select the correct customer.
3. Enter the amount received and payment method.
4. Enter the transaction reference where applicable.
5. Save the payment and provide the customer with the receipt/reference.

Always confirm the customer's identity before recording a payment. Do not allocate an unmatched M-Pesa transaction to an invoice without checking it.

### View a customer statement

1. Open **Customers** and select the customer.
2. Open the customer account and choose the statement option.
3. Set the required date range.
4. Review the opening balance, invoices, receipts and running balance.
5. Use **Export to Excel** or **Print / Save as PDF** if those controls are available.

### Aged receivables

Users with access can open **Finance → Aged Receivables** to find unpaid invoices. Search by customer, phone or invoice and filter by age bucket. Select a customer or invoice link to view the related account or invoice.

## 7. Supplier payments

If your role allows supplier payments:

1. Open **Supplier Payments**.
2. Select the correct supplier.
3. Enter the payment amount, method and transaction reference.
4. Confirm the details before saving.

Keep the supplier's payment evidence and report any mismatch to Finance.

## 8. Record an expense

1. Open **Expenses**.
2. Select the appropriate expense category.
3. Enter the description, amount, payment method and date.
4. Add a useful note or reference, then save.

Choose the closest accurate category. Do not create a duplicate category to work around a missing option; ask an administrator to review the category list.

## 9. Cashier shift duties

- Open your own shift and enter the actual opening float before starting sales.
- Record cash-in and cash-out using the available shift actions.
- Keep business cash separate from personal money.
- At the end of the shift, count the physical cash carefully.
- Enter the counted amount and submit the shift for closing/review.
- Report any difference immediately. Do not change figures simply to make the shift balance.

## 10. Attendance and leave

Open **My HR Self-Service**.

### Clock in and out
- Select **Clock In** when you begin work.
- Select **Clock Out** when you finish work.
- Check your attendance history if you need to confirm a recorded time.

### Request leave
1. Choose the leave type and requested dates.
2. Enter the reason.
3. Submit the request and check its status later.
4. You may cancel your own request while it is still pending.

Submitting a request does not mean leave has been approved. Wait for the decision from your manager or HR.

## 11. Reports and printing

Where report controls are available:

- Use the filters to choose the correct date range, customer, supplier, category or status.
- Select **Export to Excel** to download a spreadsheet-compatible report.
- Select **Print / Save as PDF** and choose **Save as PDF** in the print window.

Check the filters and totals before sharing a report. Keep customer and supplier information confidential.

## 12. Good daily practices

- Use your own login.
- Check quantities, prices and payment methods before saving transactions.
- Scan or search carefully to avoid duplicate items.
- Keep receipts and supplier documents.
- Report errors, damaged stock, missing cash or suspicious activity promptly.
- Do not delete or alter historical transactions to correct a mistake. Ask your supervisor to use the approved correction process.


## 13. Deliveries and fleet (where assigned)

If your role includes delivery work, open **Deliveries** to review assigned delivery records. Follow the dispatcher's instructions and confirm the correct customer, delivery details and items before leaving. Drivers should report delays, failed deliveries, vehicle defects or accidents promptly. Do not change vehicle or delivery assignments without authorisation.

## 14. Product units, packaging and product record changes

Where your role permits product maintenance, authorised users can choose the purchase unit and sale/stock unit from the available unit list. For example, a cover packed in a box of 12 can be set up with Piece as the stock unit, Box as the purchase unit and 12 as the units per box. Stock is tracked in pieces, while a sale or purchase can be entered in boxes or pieces. Check the conversion carefully before saving; an incorrect conversion changes stock quantities.

The product price list shows retail and wholesale prices for both the smallest stock unit and the purchase package where package prices have been set. For example, a product can have a retail piece price, retail box price, wholesale piece price and wholesale box price. Use the price list and selling unit displayed on the order to confirm the correct price.

Where your role permits product maintenance, authorised users can add or update a product photo from the computer using a PNG or JPEG image. Keep images clear and relevant to the item. If the system rejects an image, check that it is a genuine PNG/JPEG and is within the displayed upload size limit. Do not create a duplicate product to replace a missing photo. Ask an administrator to correct the existing record.

Product edits and deletion are permission-controlled. Products with sales, purchase or stock movement history should normally be deactivated rather than deleted so historical records remain intact.

## 15. Help and support

Use **Help & Support → General User Manual** for these instructions. Contact your supervisor or the system administrator first for access changes, incorrect business records or approval questions. For technical support covered by the service arrangement, contact FlexiScriptHub on **0705259931**.


## Customer receipts and supplier payments

- A customer receipt is automatically allocated to the customer's oldest outstanding completed invoice first. If the receipt is larger than that invoice balance, the remaining amount is applied to the next oldest invoice.
- Invoice balances and the customer account balance are updated together. If the receipt is greater than the outstanding invoice balances, Dibonke stops the transaction and asks for the account to be reconciled.
- Supplier payments are allocated to the oldest outstanding received purchase or Goods Received Note (GRN) first, then to the next oldest supplier document if money remains.
- Use Finance → Aged Receivables to review customer invoices and Finance → Aged Payables to review supplier purchases by age.
- POS payments are linked directly to the invoice created by that sale. Confirmed M-PESA payments are also linked to the relevant invoice or customer account.

## Inventory cost and stock quantities

- Product quantities are maintained in each product's configured stock unit. Do not add pieces, reams, boxes and other different units together as if they were the same unit.
- On receiving a purchase, Dibonke updates the product's buying cost using a weighted-average cost based on the existing stock and the newly received quantity.
- Inventory value is an operational estimate using the recorded weighted-average cost. It is not a substitute for a full landed-cost valuation where freight, duties or other acquisition costs need to be capitalised.


## Book covering at the POS

Some exercise books can be sold with an optional covering service. When an exercise book has a covering charge configured, the cashier will see **Add covering service** beneath that item in the basket. Tick it to add the extra charge for each book; leave it unticked for an ordinary sale. The covering charge is shown on the receipt separately from the book's normal price.

The cover roll is a normal stock product sold by the whole roll. Set its stock unit to **Roll** and its retail selling price to **KES 200** (or the current shop price). When a customer buys a roll, add it to the basket like any other product; one roll is deducted from stock when the sale is completed. The covering service charge is separate and is not included in the roll price.

An authorised product administrator maintains per-book covering charges under **Catalogue → Book Covering Setup**. If the shop takes a complete roll from its own stock for internal covering work instead of selling it, use **Record cover rolls used internally** on that page. Enter the roll product, number of whole rolls and a reason. Dibonke reduces stock, records the inventory movement and audit event, and values the issue using the product's buying cost (not its KES 200 selling price).

When automatic accounting is enabled and the accounting mappings are configured, Dibonke posts the internal-use cost as **Debit Internal Inventory Use Expense / Credit Inventory**. If automatic accounting is disabled, the stock movement is still recorded but Finance must post the journal manually. The accounting setup requires the `dibonke_internal_inventory_use_accounting.sql` migration. This internal-use control records whole rolls only. Covering a book does not automatically deduct part of a roll, because material usage is not being measured in this workflow.

A cover roll sold to a customer is added to the POS as a normal product and deducts one whole roll when the sale is completed. Do not use the internal-use form for a roll sold to a customer.


## Stocktaking (authorised users)

Stocktaking is used to compare the quantity physically in the shop with the quantity recorded in Dibonke.

1. Open **Launch Operations → Stocktaking**.
2. Enter a clear count title, such as **November full stock count**, and select **Start new count**. Dibonke captures the system quantity for each active product when the count is opened.
3. Work through the count sheet. Search by product name, SKU or barcode, or scan a product's SKU/barcode using a USB scanner. Each scan adds one to that product's physical quantity.
4. You can also type the physical quantity. Enter **0** when no units are physically available. Leave a field blank only when that product has not yet been counted.
5. Save physical counts as you work. You can save more than once while the count remains open.
6. Review the variance column. A negative number is a shortage; a positive number is a surplus. The printed count sheet can be used for a paper count or filed with the final report.
7. When every product has been counted, an authorised approver reviews the result and selects **Approve & post variances**. Only then does Dibonke update stock and create linked stock movement records.
8. Use **View / Print** in the Stock Count Register to review or print the count sheet and final variance report.

Do not guess a quantity to complete a count. If no stock is found, enter zero. If the count is wrong, correct and save it before approval. An active count can be cancelled by an authorised user; cancellation does not change inventory. Once approved, the count is a historical record and its posted stock movements should not be deleted. Report any correction through the approved stock adjustment process.

A count captures the system quantities at the time it is started. If sales, receiving or other stock movements continue during counting, the approver should investigate significant variances before posting the count.


If stock is sold, received or adjusted after an individual product has been counted, Dibonke carries forward the later recorded stock movements when the count is approved. If you physically recount an item, tick **Recounted** for that item before saving so its count time is refreshed. This helps prevent transactions made during a long stocktake from being overwritten by an earlier physical count.
