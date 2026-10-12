# Budget Management & Reporting System

A PHP/MySQL application for managing budget assignments, transaction records, disbursement reporting, and payment request documents.

**Developer:** Ruangrit Srimuang

**Context:** Budget Office assignment during military service, November 2025–present.

**Portfolio:** https://ruangritworks.github.io/portfolio/

## My contribution

- Developed user access, unit information, budget assignments, and transaction record workflows.
- Built disbursement dashboards and consolidated withdrawal and incoming transfer data for monthly, quarterly, annual, and fiscal-year reporting.
- Automated payment request document generation and supported certification and approval workflows, reducing manual typing and administrative effort.

## Technology and architecture

PHP, MySQL/MariaDB, SQL, JavaScript, and PhpSpreadsheet for Excel reporting.

```text
User actions and document workflows
                |
      PHP validation / access checks
                |
   MySQL relational records and ledger
                |
      Consolidation / reporting logic
                |
Dashboards, printable documents, Excel exports
```

| Area | Source |
| --- | --- |
| Access control and request protection | config/auth.php, config/security.php |
| Database access and record helpers | config/db.php |
| Budget and control account workflows | control_accounts.php, config/ledger.php, config/financial_actions.php |
| Budget classification | budget_classification.php, config/organization.php |
| Vouchers and approval documents | payment_vouchers.php, document_workflow.php, voucher_signatures.php |
| Reporting and Excel generation | overview.php, config/reporting_35.php, config/report_workbook_35.php, exports/ |
| Database structure and migrations | database/ |

The code includes prepared database queries, relational constraints, financial transaction handling, document counters, and audit-related logic. It demonstrates operational data management and reporting; it is not presented as a cloud ETL platform or an automated bank/GFMIS payment integration.

## Public snapshot

This repository is a curated source snapshot from the development application, not a dump of the running system. It excludes live databases, account seed hashes, internal deployment instructions, credentials, session files, logs, financial records, screenshots, and original report workbooks.

The public config/environment.php is adapted for a local demo configuration and isolated runtime folder. It does not load the original operations directory. The operational application's source files and databases were not changed when preparing this snapshot.

## Local review

1. Use PHP 8.2+ with mysqli, mbstring, DOM/XML, zip, and the extensions required by Composer dependencies.
2. Place a checkout in a local Apache web directory such as htdocs/budget-management-system.
3. Run `composer install` to install locked dependencies.
4. Copy `config/local.example.php` to `config/local.php` and set a dedicated local demo database account.
5. Review `database/schema.sql` and the numbered migrations when preparing a **new empty disposable database**. These files describe schema evolution; do not run them blindly against any existing database. There is no operational data or default administrator password in this repository.
6. Provision synthetic units and users in that demo database. User passwords use PHP password_hash(); no shared demo login is published.
7. Supply independently prepared blank report templates if testing template-backed Excel exports. The original workbooks are omitted; see template/README.md.
8. Open http://localhost/budget-management-system/.

This snapshot has been checked for PHP syntax. A complete installation and end-to-end test with a newly provisioned demo database and blank report templates has not been performed; the repository is primarily intended for source review. GitHub Pages cannot execute this PHP application.

## Data engineering relevance

The project connects relational data storage, data validation, operational workflows, and consolidated reporting. Its value for a Junior Data Engineer application is practical work with structured data and reliability, rather than unverified performance metrics.

## Validation

The included CI checks PHP syntax without contacting or changing any database. Runtime behavior requires the local review setup above.
