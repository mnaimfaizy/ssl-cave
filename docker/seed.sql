-- Demo inventory for local/Docker UI (no real certs).
INSERT INTO domains (
  domain, cpanel_expiry, acme_expiry, deploy_hook, webroot, status,
  notes, in_cpanel, in_acme, last_synced_at, created_at, updated_at
) VALUES
(
  'example.com',
  DATE_ADD(CURDATE(), INTERVAL 60 DAY),
  DATE_ADD(CURDATE(), INTERVAL 60 DAY),
  1,
  '/home/docker/example.com',
  'ok',
  'Seeded demo domain (ok).',
  1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
),
(
  'expiring.example.com',
  DATE_ADD(CURDATE(), INTERVAL 10 DAY),
  DATE_ADD(CURDATE(), INTERVAL 10 DAY),
  1,
  '/home/docker/expiring.example.com',
  'expiring',
  'Seeded demo domain (expiring).',
  1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
),
(
  'drift.example.com',
  DATE_ADD(CURDATE(), INTERVAL 40 DAY),
  DATE_ADD(CURDATE(), INTERVAL 20 DAY),
  0,
  '/home/docker/drift.example.com',
  'drift',
  'Seeded demo domain (drift).',
  1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

INSERT INTO settings (`key`, `value`) VALUES
  ('alert_email', 'dev@localhost'),
  ('last_sync_at', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
