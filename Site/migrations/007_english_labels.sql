UPDATE roles SET name = 'Moderator', rank_level = 50 WHERE slug = 'moderator';
UPDATE roles SET name = 'Administrator', rank_level = 100 WHERE slug = 'admin';

UPDATE permissions SET name = 'Access the management panel' WHERE slug = 'admin.access';
UPDATE permissions SET name = 'View users' WHERE slug = 'users.view';
UPDATE permissions SET name = 'Manage users and roles' WHERE slug = 'users.manage';
UPDATE permissions SET name = 'Unlink devices and products' WHERE slug = 'devices.manage';
UPDATE permissions SET name = 'Create and assign licenses' WHERE slug = 'licenses.manage';
UPDATE permissions SET name = 'View the audit log' WHERE slug = 'audit.view';

UPDATE games SET short_description = 'Team-based tactical shooter.' WHERE slug = 'deadlock';
UPDATE games SET short_description = 'Competitive tactical FPS.' WHERE slug = 'counter-strike-2';

UPDATE plans SET name = '30 days' WHERE slug = '30-days';
UPDATE plans SET name = '90 days' WHERE slug = '90-days';
UPDATE plans SET name = 'Lifetime' WHERE slug = 'lifetime';
