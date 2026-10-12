<?php
// Copy to local.php. Use only a fresh, isolated demo database.
// Never point a portfolio checkout at an operational database.
return [
    'host' => getenv('BUDGET_DB_HOST') ?: '127.0.0.1',
    'username' => getenv('BUDGET_DB_USER') ?: 'budget_demo_user',
    'password' => getenv('BUDGET_DB_PASSWORD') ?: '',
    'database' => getenv('BUDGET_DB_NAME') ?: 'budget_portfolio_demo',
    'base_path' => getenv('BUDGET_BASE_PATH') ?: '/budget-management-system',
];
