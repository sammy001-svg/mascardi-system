-- Migration 022: Expand user roles to cover all company departments

ALTER TABLE users
    MODIFY COLUMN role ENUM(
        -- System
        'admin',

        -- Management
        'general_manager',

        -- Finance
        'finance_manager',
        'accountant',
        'cashier',

        -- Sales & Client Relations
        'sales_manager',
        'sales_officer',
        'sales_person',
        'customer_relations',
        'receptionist',

        -- Workshop / Operations
        'workshop_manager',
        'mechanic',
        'driver',

        -- Inventory & Procurement
        'inventory_manager',
        'procurement_officer',

        -- HR
        'hr_manager',

        -- Legacy (kept for backward compatibility)
        'manager'
    ) NOT NULL DEFAULT 'mechanic';
