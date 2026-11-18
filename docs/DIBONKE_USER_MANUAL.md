# Dibonke Bookshop — User and Operations Manual

**System:** Dibonke Bookshop & General Retail Management System  
**Currency / timezone:** Configure the correct currency; application timezone defaults to Africa/Nairobi.  
**Audience:** Administrator, Manager, Cashier, Sales, Storekeeper and Finance users.

> This manual describes the workflows implemented in the current development branch. Some integrations and accounting controls remain preparation-only or require local database setup and testing. Do not treat this document as confirmation that a live KRA, Safaricom or production deployment is active.

## 1. Sign in and navigation

1. Open the Dibonke URL provided by your administrator.
2. Sign in using your assigned username and password.
3. Use the left navigation to open the modules allowed by your role.
4. Sign out when leaving a shared workstation.

Administrators should create individual accounts for staff and assign the correct role. Do not share administrator credentials. Link each user to their staff profile under Users so employee self-service can identify the correct person.

## 2. Administrator setup

Before daily use, an administrator should:

1. Confirm the company profile, currency, timezone and document settings.
2. Review Roles and Permissions and grant only the access needed for each job.
3. Create staff profiles and user accounts, then link the user account to the staff profile.
4. Create product categories, suppliers and customers.
5. Set up the chart of accounts if using the manual accounting journals.
6. Configure payment settings only with credentials issued for the correct business.
7. Confirm that the database migrations required by enabled modules have been applied.

A user with business history should normally be deactivated rather than deleted. The system protects privileged accounts and prevents deletion of accounts with linked records.

## 3. Products and stock

### Create a product

1. Open Products and choose Add Product.
2. Enter the product name, product type (Book, Stationery or General), category, SKU/barcode, unit, buying price, selling price and reorder level.
3. For books, enter the ISBN if available. ISBN is optional for other product types.
4. Enter opening stock only when this is the verified physical quantity at setup.
5. Save the product.

The opening stock action records an opening stock movement. Do not enter the same opening stock again as a manual adjustment.

### Receive stock through purchase orders

1. Create a Purchase Order for the supplier and add products and ordered quantities.
2. Approve the purchase order according to your permissions.
3. Open Purchase Orders & Receiving and select the order to receive.
4. Scan a product barcode/ISBN/SKU in the receiving screen. The scanner adds one unit to the matching outstanding PO line; adjust the quantity when receiving a different number.
5. Enter the supplier invoice number, invoice date and invoice total.
6. Confirm actual quantities received and post the Goods Received Note (GRN).

Only the quantities posted on the GRN increase stock. Partial receipts leave the outstanding quantity on the purchase order. Do not also record the same delivery through the legacy direct Purchase Register, or stock/payables may be duplicated.

### Stock adjustments and stocktaking

- Use Stock Movements for a controlled increase, decrease or set-to-physical-count adjustment.
- Enter a clear reason for every manual adjustment.
- Use Stocktaking to create a count, scan or enter counted quantities, review variances and post approved corrections.
- Do not post a count until the physical count has been checked.

Stock quantities currently use whole numbers. Fractional units such as kilograms or metres require a coordinated schema and application upgrade.

## 4. Point of Sale and receipts

1. A Cashier signs in and is directed to My Cashier Shift.
2. Enter the opening float and open the shift.
3. Open POS from the shift page or the POS shortcut.
4. Scan a barcode or search/select a product, confirm quantities and prices, then select the customer if the sale is on account.
5. Select the payment method and complete the sale.
6. Print or open the receipt and give it to the customer.

For cash sales, the receipt shows the amount received, the amount applied to the sale and change given. The receipt contact number is 0706 046963.

Cashier/Sales users are restricted to their own sales and shifts where the relevant access controls apply. A browser cannot independently trigger every physical cash drawer; drawer opening depends on compatible printer hardware and configuration.

## 5. Customer accounts, collections and statements

### Customer credit sale

Select the customer during POS checkout and complete the sale with the permitted credit/balance workflow. The customer's outstanding balance is updated by the sale process.

### Record a customer collection

1. Open Customer Payments.
2. Select the customer with an outstanding balance.
3. Enter the collection amount and payment method.
4. Add the transaction reference where applicable.
5. Save the payment and retain the receipt/reference.

The payment register supports date, method and keyword filters. Customer names link to the customer account.

### Customer statement

1. Open Customers and select the customer.
2. Open the customer account.
3. Choose the statement start and end dates.
4. Review the opening balance, invoices, receipts, running balance and invoice register.
5. Use Export to Excel or Print / Save as PDF.

The statement is based on recorded completed invoices and customer payments. Reconcile it to source records before sending it as a formal balance confirmation.

### Aged receivables

Open Finance → Aged Receivables. Filter by as-at date, customer/invoice/phone search and age bucket. Click a customer or invoice to open its account/receipt where linked.

Age is calculated from the invoice/sale date because the current sales schema does not provide contractual due dates. The Current (0–30 days) bucket is an invoice-age bucket, not proof that an invoice is not overdue under agreed credit terms.

## 6. Supplier purchasing and payments

- Use Purchase Orders & Receiving for controlled PO/GRN purchasing.
- Use Purchase Register only for the separate legacy direct-purchase workflow.
- Supplier Payments records settlement against supplier balances.
- Add M-Pesa/bank/cheque references and retain external proof of payment.
- Use the supplier payment date, method and search filters to locate records.

Avoid entering one supplier invoice in both purchase workflows.

## 7. Expenses

1. Open Expenses.
2. Choose an active expense category from the automatically populated list.
3. Enter description/title, amount, payment method, date and notes.
4. Save the expense.

The category list is seeded/ensured by the application. If categories do not appear, confirm that the database user has permission to create/read the expense category table and check the PHP error log.

Filter expenses by the available date/category/search controls. Export to Excel or use Print / Save as PDF for a report copy.

## 8. Cashier shifts and cash control

Cashiers open their own shift before using POS. During the shift, use the supported cash movement actions for cash-in/cash-out where enabled. At close, count physical cash and compare it with the expected amount; record the variance and submit the close.

Managers should review shift variances. The current implementation still has limitations around split tenders and reconciliation of every non-POS cash movement. Do not rely on the shift screen as a complete till audit until all cash sources, cash drops, refunds and paid-outs have been locally tested.

## 9. Human Resources self-service

### Clock in and out

1. Sign in using your own account.
2. Open My HR Self-Service.
3. Select Clock In at the start of your workday.
4. Select Clock Out when finishing work.
5. Review your attendance history.

The system prevents duplicate daily clock-ins and clock-outs. Your login account must be linked to your staff profile.

### Request leave

1. Open My HR Self-Service.
2. Choose the leave type and requested start/end dates.
3. Enter the reason and submit the request.
4. Track the request status in your leave history.
5. A pending request may be cancelled by its owner.

Managers/HR users review requests in the HR workspace. The current working-day calculation excludes weekends but does not automatically exclude Kenyan public holidays or company-specific holidays.

## 10. Finance and accounting

### Operational finance reports

Finance Overview, Ledger, Financial Control, Expenses, Aged Receivables, Chart of Accounts, Journal Entries, General Ledger and Trial Balance are available subject to permissions and migration setup.

### Double-entry journal workflow

1. Set up the Chart of Accounts.
2. Open Journal Entries and create a draft with a date, description and reference.
3. Enter at least two lines, assigning an account and debit or credit amount to each.
4. Ensure total debits equal total credits.
5. Save the draft, review it, then post it.

Posted journals are not edited through the normal draft workflow. Correct posted entries using an approved reversal/correction process.

**Important accounting limitation:** the current double-entry layer is a manual journal register. Existing POS sales, purchases, collections, supplier payments, expenses, returns and stock movements are not yet automatically posted into journals. Opening balances and verified source-to-account mappings are also required. The current Trial Balance therefore reflects posted manual journals only; it is not yet a complete financial statement for the business.

## 11. M-Pesa and KRA eTIMS

### M-Pesa

The M-Pesa settings page supports configured Daraja credentials and STK Push workflow. C2B callback registration requires valid business credentials and publicly reachable HTTPS callback URLs. A local XAMPP address is not reachable by Safaricom.

For direct Till payments, Safaricom notifications may not include a reliable invoice/account reference. Such payments can remain unallocated and require reconciliation. PayBill account references or invoice-linked STK Push are more reliable for automatic allocation. Do not mark an unmatched payment as settled against a customer invoice without checking the transaction.

### KRA eTIMS

The eTIMS area records onboarding/configuration details and prepares invoice queue records. It is not a live KRA submission integration. Obtain the official KRA onboarding/API specifications and complete sandbox certification before relying on automated tax invoicing.

## 12. Reports, Excel and PDF

Where report controls are present:
- **Export to Excel** downloads an Excel-compatible XML Spreadsheet workbook with an `.xls` extension.
- **Print / Save as PDF** opens the browser print dialog. Choose the browser's Save as PDF destination.

PDF output depends on browser print settings and may need landscape orientation for wide reports. Always check the date range and filters before exporting.

## 13. Security and daily operating controls

- Use one account per staff member.
- Never share passwords or payment API secrets.
- Lock or sign out of shared POS devices when unattended.
- Verify supplier invoices and customer payment references before posting.
- Do not delete historical business records to correct mistakes; use returns, adjustments or approved correction entries.
- Back up the database before migrations and at a regular schedule.
- Restrict access to database dumps and exported customer statements.
- Review audit events and unusual balances regularly.

## 14. Current release limitations / go-live checklist

The following must be completed and verified on the actual Dibonke database before production sign-off:

- Confirm all required SQL migrations have been applied in dependency order.
- Complete a clean-database canonical base schema and test it independently; the current repository does not yet provide a verified fresh-install base schema.
- Run PHP lint and full XAMPP/browser tests using the target PHP 8.0.30 / MariaDB environment.
- Test POS cash, credit, M-Pesa, receipts, refunds, cash shifts and stock deduction end-to-end.
- Test partial and full GRN receiving and ensure stock/payables are not duplicated.
- Test customer collections, C2B unmatched receipts and manual allocation/reconciliation.
- Configure chart-of-account mappings and opening balances, then implement and reconcile automatic source transaction posting before treating the General Ledger/Trial Balance as complete.
- Complete KRA eTIMS technical onboarding and sandbox certification before live transmission.
- Test printer, scanner, cash drawer and all report exports on the actual hardware.
- Test Docker build, database restore, backups and deployment security.

A feature being present in the repository does not by itself mean it has passed local acceptance testing.
