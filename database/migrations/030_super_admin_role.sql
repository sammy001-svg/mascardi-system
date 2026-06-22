-- Migration 030: Add super_admin role
-- Super Admin = full comprehensive portal (original admin experience)
-- Admin       = simple focused portal (Workshop + Sales dashboards)

ALTER TABLE `users`
MODIFY COLUMN `role` ENUM(
    'admin',
    'super_admin',
    'general_manager',
    'workshop_manager',
    'finance_manager',
    'accountant',
    'cashier',
    'sales_manager',
    'sales_officer',
    'sales_person',
    'customer_relations',
    'receptionist',
    'mechanic',
    'driver',
    'inventory_manager',
    'procurement_officer',
    'hr_manager',
    'manager'
) NOT NULL DEFAULT 'mechanic';
