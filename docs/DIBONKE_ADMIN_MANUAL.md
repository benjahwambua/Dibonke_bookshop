# Dibonke Bookshop — Administrator Manual

**For:** System Administrators and authorised Managers responsible for setting up users, controlling access and overseeing business operations.

This guide covers administrative tasks inside the Dibonke system. Give each employee only the access required for their duties.

## 1. Administrator responsibilities

An administrator is responsible for:
- Maintaining staff and user accounts.
- Assigning suitable roles and permissions.
- Keeping product, customer, supplier and expense-category records organised.
- Reviewing operational reports and exceptions.
- Ensuring daily cash, stock and payment controls are followed.
- Protecting business and customer information.

Use an individual administrator account. Do not share privileged credentials.

## 2. Set up staff and user accounts

### Create a staff profile
1. Open **Staff Directory** or **Human Resources**.
2. Add the employee's details and employment information.
3. Save the profile and confirm it is active.

### Create a login account
1. Open **Users** and choose the option to add a user.
2. Enter the employee's name, username and other required details.
3. Assign the role appropriate to the employee's duties.
4. Save the account.
5. Edit the user and link the matching **Staff Profile**.

The staff link is important for personal attendance and leave records. Each employee should have their own login.

### Deactivate or delete a user
- Deactivate an account when an employee leaves or no longer needs access. Reactivate it only when authorised.
- Delete is available only when the account has no linked business or audit history.
- Accounts with historical records should be deactivated rather than deleted.
- The system protects privileged accounts, your own account and the last active administrator account.

## 3. Manage roles and permissions

1. Open **Roles & Permissions**.
2. Review the role assigned to each job function.
3. Grant only the module actions required, such as view, create or edit.
4. Save changes and confirm the intended user can access the required screen.
5. Review access whenever responsibilities change or an employee leaves.

Do not give cashier, storekeeper or general staff administrator access merely to bypass a permission restriction. Assign the correct role and permission instead.

## 4. Company and system settings

Open **Settings** to review the available company profile and operating settings, such as:
- Business name and contact details.
- Currency and country.
- Timezone.
- Low-stock threshold and document settings where available.

Confirm the business details before issuing receipts and reports. Avoid changing settings during active trading unless necessary.

## 5. Maintain the product catalogue

- Create and maintain product categories and product records.
- Use the smallest stock/sale unit and purchase unit consistently. For bulk items, enter the number of smallest units contained in one purchase package (for example, 12 pieces per box).
- Set retail and wholesale prices for the smallest unit and the purchase package where applicable. Review the **Price Lists** matrix to compare all four values.
- Remember that stock is tracked in the smallest unit; package quantities are converted into that unit during purchasing and sales.
- Use consistent SKUs/barcodes and enter ISBNs for books where available.
- Keep buying and selling prices accurate.
- Set reorder levels to support purchasing decisions.
- Check for duplicate products before creating new records.
- Record verified opening stock once only.

### Barcode, ISBN and SKU setup

- Record a book's ISBN in the ISBN field when available. ISBN-13 is the current standard; older editions may carry ISBN-10.
- The Barcode field stores the value scanned at receiving and POS. A book's printed barcode often encodes its ISBN; avoid treating the same value as two different identifiers. If it is the same number, store it as the product barcode and populate ISBN as appropriate for catalogue reporting.
- SKU is the internal Dibonke product code and is generated automatically when left blank.
- ISBN is not applicable to stationery and general merchandise. Barcode is optional where a product has no barcode; staff can search by product name or SKU.
- The Add Product form opens with the Barcode field focused. A standard USB HID scanner enters the digits as keyboard input. The scanner's Enter suffix moves focus to Product Name rather than submitting the incomplete form. Staff then complete required product details manually and save.
- Check for duplicate barcode/ISBN values and verify the scanned digits before saving. A scanner reads the printed code; it does not automatically retrieve product names, descriptions, categories or prices.

## 6. Product units, package conversions and price lists

When setting up a product that is bought in bulk and sold in smaller quantities, define the smallest stock/sale unit and the purchase package clearly.

1. Open **Products & Books** and add or edit the product.
2. Select the smallest **Sale/Stock Unit** (for example Piece).
3. Select the **Purchase Unit** (for example Box or Carton).
4. Enter **Units per Purchase Unit**. For a box containing 12 covers, enter 12.
5. Enter the unit-level retail and wholesale prices, and the retail and wholesale package prices where applicable.
6. Save and check the product in the catalogue and price list.

Inventory is maintained in the smallest stock unit. The conversion means one box of 12 is recorded as 12 pieces in stock. Staff can then receive or sell either a box or individual pieces. Verify the package contents and conversion before the product is used in transactions. The current product setup supports one purchase-package conversion per product; do not assume it can represent several different package sizes for the same product.

The **Price Lists** screen presents the retail and wholesale price matrix for both the smallest stock unit and the purchase package. Review all four prices where relevant. Package prices can be set independently; if a package price is not entered, the system may calculate it from the unit price and conversion factor. Check the actual displayed price before authorising a transaction.

## 7. Suppliers, purchase orders and receiving

- Maintain accurate supplier records.
- Use **Purchase Orders & Receiving** for the controlled order and goods-received process.
- Review and approve purchase orders according to company procedure.
- Check supplier invoice number, date, total and actual quantities at receiving.
- Review GRNs and outstanding quantities.
- Use **Purchase Register** only for the separate direct-purchase workflow.

Do not record the same supplier invoice in both workflows. This can duplicate stock and financial records.

## 8. Customer sales orders, pending invoices and receivables

- Maintain correct customer names and contact details.
- Use **Customer Sales Orders** for existing-customer orders that require review before invoicing. Staff create a draft, review and validate it, then the validated order appears under **Pending Invoices**.
- Validation checks stock but does not reserve or deduct it. When an authorised user confirms the pending order, the system checks stock again, creates the credit invoice, deducts stock and updates the customer's outstanding balance.
- Confirm only after checking the customer, quantities, selling units, prices and total. Resolve stock shortages or credit-limit warnings before confirmation.
- The customer invoice can then be opened and printed. Customer payments are recorded separately through the customer payment workflow.
- Review credit terms and limits before authorising credit sales.
- Review **Aged Receivables** regularly and follow up overdue customer balances.
- Open customer records to review invoices and prepare statements.
- Reconcile customer collections against the correct customer and invoice.
- Investigate unallocated M-Pesa receipts before applying them to an account.

The receivables age buckets are based on invoice date. Where agreed customer due dates differ from invoice dates, use the actual credit agreement when following up.

## 9. Cash management and shift review

- Ensure each cashier opens their own shift before using POS.
- Review submitted shift closures and investigate variances.
- Check opening float, expected cash, counted cash and recorded cash movements.
- Require explanations and supporting evidence for differences.
- Follow company procedures for cash drops, refunds and paid-outs.

Do not treat a balanced screen as a substitute for physically counting and reconciling the till.

## 10. Expenses and categories

Expense categories are maintained in the expense workflow and are automatically populated for users. Review the category list periodically and keep naming consistent.

When reviewing expenses:
- Check date, category, description, amount and payment method.
- Confirm supporting receipts or approvals.
- Use filters to investigate a particular period or category.
- Review unusual or repeated expenses.

## 11. Human Resources administration

Open **Human Resources** to manage employee records and review leave and attendance information.

- Review leave requests and approve or decline them according to company policy.
- Check attendance records and follow up missing clock-ins or clock-outs.
- Ensure every employee has a linked user account where self-service is required.
- Maintain accurate leave types and employee information.

The employee self-service page is for each user to view their own records, clock in/out and submit leave requests.

## 12. Finance, accounting and reports

Use Finance Overview, Ledger, Financial Control, Aged Receivables, Chart of Accounts, Journal Entries, General Ledger and Trial Balance according to your assigned access.

For manual journals:
1. Confirm the correct accounts exist in the Chart of Accounts.
2. Create a journal entry with a clear date, description and reference.
3. Enter at least two lines and ensure total debits equal total credits.
4. Review the entry before posting.
5. Retain supporting documents for the transaction.

The Trial Balance and General Ledger show posted journal entries. Ensure the business has completed its accounting setup and reconciliations before using these reports as formal financial statements.

## 13. M-Pesa and eTIMS administration

### M-Pesa
- Use business-issued Daraja credentials only.
- Protect API credentials and restrict access to payment settings.
- Confirm callback addresses and business shortcode with the authorised payment administrator.
- Reconcile unmatched Till/PayBill receipts before allocating them to customer invoices.
- Keep transaction references and supporting evidence.

### KRA eTIMS
Use the eTIMS setup area to maintain the business onboarding details and configuration records. Complete the required KRA onboarding and verification before relying on electronic tax invoice submission. Follow current KRA instructions and your authorised integration provider's guidance.

## 14. Reports, exports and statements

Reports provide filters relevant to the selected area. Before exporting:
1. Set the correct date range and other filters.
2. Review the displayed records and totals.
3. Export to Excel or use Print / Save as PDF.
4. Store exports securely and share them only with authorised people.

Customer statements and reports contain confidential business information.

## 15. Security and operational controls

- Create one account per employee and deactivate access promptly when no longer required.
- Review administrator and finance permissions regularly.
- Never share passwords or payment credentials.
- Restrict access to customer statements and exported reports.
- Verify supplier invoices and payment references.
- Require approval for stock adjustments, refunds and unusual cash movements.
- Review audit/activity records where available.
- Follow the organisation's database backup and record-retention procedures.

## 16. Daily and periodic administrator checklist

**Daily**
- Check cashier shift openings and closures.
- Review cash variances and unmatched payments.
- Review sales, returns and unusual stock movements.
- Confirm urgent receiving and expense records are supported.

**Weekly**
- Review aged receivables and supplier balances.
- Review low-stock items and pending purchase orders.
- Review leave requests and attendance exceptions.
- Check user access changes.

**Monthly**
- Review sales, expenses and financial reports.
- Reconcile customer and supplier balances.
- Review product prices and expense categories.
- Review user roles and access.


## 17. Master data and option-list management

Use the management links provided beside the relevant forms to maintain lists rather than entering duplicate or inconsistent values.

- **Products:** authorised users can edit product details and manage product records. Products with transaction or stock history should be deactivated rather than deleted.
- **Product categories, brands and suppliers:** use the catalogue management links to add and maintain these lists.
- **Price lists:** use the Price Lists screen to add, edit, activate/deactivate and manage applicable price lists. Protected system lists retain their required system codes and cannot be removed where the application protects them.
- **Expense categories:** use **Expenses → Manage Categories** to add, rename, activate/deactivate or delete unused categories. A category linked to expense history must be deactivated, not deleted.
- **Leave types:** use **Human Resources → Manage Leave Types** to configure leave options, annual day allowance and paid/unpaid status. A leave type with request history must be deactivated rather than deleted.
- **Chart of Accounts:** authorised users can maintain account names and status. Accounts linked to journal lines and protected system accounts cannot be deleted; deactivate or retain them as appropriate.

Changes to master data can affect future transactions and reports. Review the impact before deactivating an option that employees currently use.

## 18. Fleet management

Open **Deliveries → Fleet Management** to register and maintain vehicles. Record the registration number, vehicle type, make/model, capacity, assigned driver, insurance expiry, inspection expiry, odometer and operational status. Use the maintenance section to record service date, service type, odometer, cost, garage/supplier and next service date. Active vehicles can be selected when creating deliveries.

A vehicle with delivery or maintenance history cannot be deleted; set it to **Inactive** when it is no longer in service. Keep insurance and inspection dates current and do not dispatch a vehicle that is unsafe or not legally roadworthy.

## 19. Automatic double-entry posting and posting mappings

The system includes automatic journal posting for supported new transactions, provided the required accounting migrations have been applied and automatic posting has been enabled.

Before enabling it:
1. Confirm that the Chart of Accounts and Journal Entries are configured.
2. Open **Finance → Posting Mappings**.
3. Review the mappings for cash, M-Pesa clearing, bank, other/mixed payment clearing, receivables, inventory, payables, VAT, sales revenue, cost of goods sold, input VAT, unallocated receipts and default expenses.
4. Map individual expense categories to the correct Expense accounts where needed.
5. Confirm the accounts are active and of the correct account type.
6. Save the mappings and review them with the person responsible for the books.
7. Enable automatic posting only after the mappings have been checked.

When enabled, supported new POS sales, direct purchases, purchase-order goods receipts and expenses generate balanced journals in the same transaction. Supported customer collections, supplier settlements and confirmed M-Pesa STK/C2B receipts also post settlement journals. Source references prevent duplicate journals. If journal posting fails, the originating transaction is rolled back.

Automatic posting does not automatically create journals for historical transactions. Opening balances and prior-period transactions must be entered or reconciled separately. Unmatched M-Pesa receipts are held in the Unallocated M-Pesa Receipts liability account until properly allocated. Mixed POS tender is subject to the tender data actually captured by the POS; do not assume unrecorded tender splits are available.

The General Ledger and Trial Balance report posted journals, not a substitute for reviewing source records and reconciling bank, cash, stock, receivables and payables. Have the bookkeeper/accountant verify mappings and opening balances before relying on these reports for formal financial reporting.

## 20. Backups, Docker and deployment

For the current local installation, maintain separate backups of the database and the uploads directory, including product images. Store at least one backup away from the computer running the application and periodically test restoration. Do not experiment with Docker or database migrations against the live trading database.

Docker deployment is optional. The repository's Docker setup packages application code in an image and uses persistent volumes for MariaDB data and uploads, but the repository documentation notes that a complete verified fresh-install base schema is not yet supplied. Do not treat a successful container start as proof that the database is correctly migrated. Follow docs/DOCKER.md and database/README.md, use a separate test database, and verify backup/restore before any production move.

## 21. Support and maintenance

The included maintenance period is governed by the separately signed Maintenance and Technical Support Agreement. Record the installation/handover date and maintenance expiry date. Report defects with the affected screen, steps to reproduce, exact error message and screenshot where possible. New modules, major customisations, additional branches, hardware repairs and third-party service charges may fall outside included maintenance; obtain written approval and a quotation before commissioning extra work.


## Payment allocation and reconciliation

Apply the database migration `database/dibonke_payment_allocations.sql` before deploying the matching PHP changes. It creates customer invoice and supplier purchase allocation ledgers and migrates matching legacy user roles to configured role IDs. Existing payment records are not deleted or rewritten.

- Customer receipts allocate FIFO to completed sales with an outstanding balance.
- Supplier payments allocate FIFO across received purchases and Goods Received Notes (GRNs) with an outstanding balance. Legacy supplier payments linked directly to a GRN are included in its paid amount.
- POS and confirmed M-PESA invoice payments are linked directly to their sale.
- Review aged receivables and aged payables after migration. Historical customer and supplier summary balances may not agree with the sum of invoice balances or GRN balances; investigate these differences rather than forcing balances to match without supporting records. Supplier payments that were historically recorded without a purchase or GRN reference are deliberately not guessed or auto-applied. The system blocks further supplier payments until the supplier summary balance reconciles to its open purchase/GRN documents.
- The allocation helper rejects payments that exceed the underlying open invoices/purchases even when the aggregate customer/supplier balance suggests otherwise.

## Inventory costing

Purchase receipts and GRNs now update product buying cost using a weighted-average cost. Existing stock quantity is weighted at its current buying cost and received quantity at the incoming unit cost. This improves the stock valuation estimate and the cost snapshot used for subsequent sales. It does not yet calculate full landed cost or maintain separate FIFO cost layers.

## Roles and permissions

After the payment-allocation migration has been applied, module visibility is read from the database role-permission table. Legacy role visibility is seeded into explicit `role_permissions` rows by the migration. Review each role in Staff & Access → Roles & Permissions and assign view/create/edit/delete rights deliberately. Superuser and administrator bypass rules remain in place.

## M-PESA callback controls

The STK callback now requires a matching stored checkout request and merchant request ID. A previously successful callback is acknowledged as a duplicate without overwriting its success status or applying the payment twice. Keep the callback endpoint publicly reachable over HTTPS and test sandbox callbacks before production use.

## eTIMS status

The eTIMS page currently stores onboarding details and maintains an internal queue only. It does not transmit invoices to KRA, and production transmission remains disabled until the approved integration, credentials, sandbox testing and certification are completed.

## Backup and recovery

See `docs/DIBONKE_BACKUP_RECOVERY.md` for the required database and uploads backup procedure and restore verification checklist.


## Returns and refund accounting

Approved returns now post to the Sales Returns and Allowances contra-revenue account. The credit applied to an outstanding customer invoice reduces Accounts Receivable; the remaining refund is recorded as Customer Refunds Payable. When the refund is paid, the payable is cleared against the selected cash, M-PESA clearing, bank or other clearing account. Restocked goods reverse their original sale cost from Cost of Goods Sold back to Inventory. These journals are created only when automatic accounting is enabled and the required mappings are present.

The payment-allocation migration adds the `sales_returns` and `refund_payable` mappings. Confirm these mappings in Finance → Automatic Posting Mappings before enabling automatic posting.

## M-PESA production safety

The current Daraja credentials are not encrypted at rest in the existing settings store. The Developer Settings page now blocks switching to Production until credential encryption and end-to-end live callback verification are implemented. Continue using Sandbox only; do not manually change the environment setting to `live`.


## Exercise-book covering configuration

1. Create the cover roll as a normal stock product. Set both its stock unit and purchase unit to **Roll**, and set its retail selling price to **KES 200 per roll** (or the current shop price).
2. Receive cover rolls through the normal goods-receiving workflow. Stock is counted in whole rolls.
3. Open **Catalogue → Book Covering Setup** and configure the extra covering charge for each exercise-book product. The POS offers **Add covering service** for configured books and adds the charge per book.
4. The covering service does not deduct cover-roll stock. A customer who buys a roll must have the roll product added to the basket separately; the normal sale process deducts one whole roll.
5. When staff take complete rolls from the shop's own stock for internal covering work, use **Record cover rolls used internally**. Enter the number of whole rolls and a reason. Dibonke records an inventory adjustment and audit event.
6. The internal-use value is calculated at the product's buying cost, not the KES 200 selling price. When automatic accounting is enabled, Dibonke posts **Debit Internal Inventory Use Expense / Credit Inventory** for that cost. If automatic accounting is disabled, the stock adjustment is still recorded but the journal must be posted manually.

The internal-use form records whole rolls only. It does not estimate or deduct partial-roll consumption per covered book. Apply `database/dibonke_book_covering.sql` and then `database/dibonke_book_covering_service.sql` before deploying the current covering workflow. Also apply `database/dibonke_internal_inventory_use_accounting.sql` to create the expense account mapping.


## Stocktaking controls and approval

Stocktaking is managed under **Launch Operations → Stocktaking**.

- Give **stocktaking: view** to staff who need to review count sheets and reports.
- Give **stocktaking: create** to staff who are allowed to start counts and enter physical quantities.
- Give **stocktaking: edit** only to managers or other authorised approvers who may approve variances or cancel an active count.
- Dibonke allows one active count session at a time. Starting a count captures the then-current stock quantity for every active product into the count sheet.
- Count takers can search the sheet by product name, SKU or barcode, scan a SKU/barcode to increment the physical count by one, or enter a quantity manually. A zero is a valid counted quantity; a blank field means not yet counted.
- Quantities can be saved repeatedly while the count is open. Approval is blocked until every item has a physical quantity.
- Before approval, review shortages and surpluses, check unexpected variances against sales, goods receipts, returns and stock movements since the count began, and obtain explanations where needed.
- Approval updates each affected product's stock quantity and writes a stock_movements row with movement type Adjustment, reference type stock_count and the count ID. The count is then marked Approved and an audit event is recorded.
- Cancellation is permitted only while the count is open. It marks the count Cancelled and does not alter stock.
- Use **View / Print** in the register for the count sheet and variance report. Retain approved reports with the supporting stocktake paperwork.

The system quantity on the count sheet is the snapshot from the start of the count. If the shop continues trading during the count, the approver must investigate stock movements between the snapshot and approval. Do not approve unexplained differences merely to close the count. Approved counts should be corrected only through a separately authorised adjustment with its own audit trail.
