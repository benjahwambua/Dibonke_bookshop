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
- Use consistent SKUs/barcodes and enter ISBNs for books where available.
- Keep buying and selling prices accurate.
- Set reorder levels to support purchasing decisions.
- Check for duplicate products before creating new records.
- Record verified opening stock once only.

## 6. Suppliers, purchase orders and receiving

- Maintain accurate supplier records.
- Use **Purchase Orders & Receiving** for the controlled order and goods-received process.
- Review and approve purchase orders according to company procedure.
- Check supplier invoice number, date, total and actual quantities at receiving.
- Review GRNs and outstanding quantities.
- Use **Purchase Register** only for the separate direct-purchase workflow.

Do not record the same supplier invoice in both workflows. This can duplicate stock and financial records.

## 7. Customer credit and receivables

- Maintain correct customer names and contact details.
- Review credit terms and limits before authorising credit sales.
- Review **Aged Receivables** regularly and follow up overdue customer balances.
- Open customer records to review invoices and prepare statements.
- Reconcile customer collections against the correct customer and invoice.
- Investigate unallocated M-Pesa receipts before applying them to an account.

The receivables age buckets are based on invoice date. Where agreed customer due dates differ from invoice dates, use the actual credit agreement when following up.

## 8. Cash management and shift review

- Ensure each cashier opens their own shift before using POS.
- Review submitted shift closures and investigate variances.
- Check opening float, expected cash, counted cash and recorded cash movements.
- Require explanations and supporting evidence for differences.
- Follow company procedures for cash drops, refunds and paid-outs.

Do not treat a balanced screen as a substitute for physically counting and reconciling the till.

## 9. Expenses and categories

Expense categories are maintained in the expense workflow and are automatically populated for users. Review the category list periodically and keep naming consistent.

When reviewing expenses:
- Check date, category, description, amount and payment method.
- Confirm supporting receipts or approvals.
- Use filters to investigate a particular period or category.
- Review unusual or repeated expenses.

## 10. Human Resources administration

Open **Human Resources** to manage employee records and review leave and attendance information.

- Review leave requests and approve or decline them according to company policy.
- Check attendance records and follow up missing clock-ins or clock-outs.
- Ensure every employee has a linked user account where self-service is required.
- Maintain accurate leave types and employee information.

The employee self-service page is for each user to view their own records, clock in/out and submit leave requests.

## 11. Finance, accounting and reports

Use Finance Overview, Ledger, Financial Control, Aged Receivables, Chart of Accounts, Journal Entries, General Ledger and Trial Balance according to your assigned access.

For manual journals:
1. Confirm the correct accounts exist in the Chart of Accounts.
2. Create a journal entry with a clear date, description and reference.
3. Enter at least two lines and ensure total debits equal total credits.
4. Review the entry before posting.
5. Retain supporting documents for the transaction.

The Trial Balance and General Ledger show posted journal entries. Ensure the business has completed its accounting setup and reconciliations before using these reports as formal financial statements.

## 12. M-Pesa and eTIMS administration

### M-Pesa
- Use business-issued Daraja credentials only.
- Protect API credentials and restrict access to payment settings.
- Confirm callback addresses and business shortcode with the authorised payment administrator.
- Reconcile unmatched Till/PayBill receipts before allocating them to customer invoices.
- Keep transaction references and supporting evidence.

### KRA eTIMS
Use the eTIMS setup area to maintain the business onboarding details and configuration records. Complete the required KRA onboarding and verification before relying on electronic tax invoice submission. Follow current KRA instructions and your authorised integration provider's guidance.

## 13. Reports, exports and statements

Reports provide filters relevant to the selected area. Before exporting:
1. Set the correct date range and other filters.
2. Review the displayed records and totals.
3. Export to Excel or use Print / Save as PDF.
4. Store exports securely and share them only with authorised people.

Customer statements and reports contain confidential business information.

## 14. Security and operational controls

- Create one account per employee and deactivate access promptly when no longer required.
- Review administrator and finance permissions regularly.
- Never share passwords or payment credentials.
- Restrict access to customer statements and exported reports.
- Verify supplier invoices and payment references.
- Require approval for stock adjustments, refunds and unusual cash movements.
- Review audit/activity records where available.
- Follow the organisation's database backup and record-retention procedures.

## 15. Daily and periodic administrator checklist

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
