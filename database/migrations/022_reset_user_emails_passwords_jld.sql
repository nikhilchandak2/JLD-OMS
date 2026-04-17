-- Reset user emails from @example.com to @jldminerals.com and default passwords.
-- Default password for updated users: Jld@Passw0rd!

SET @new_pwd = '$2b$10$5sOYJsm5tXCUa7X1K1kcIetJNH8h5jYEAUko5PXtF78ZSFVqs3gT.';

-- Update email domain where target email does not already exist (avoid unique conflicts).
UPDATE users u
LEFT JOIN users existing
  ON existing.email = REPLACE(u.email, '@example.com', '@jldminerals.com')
 AND existing.id <> u.id
SET u.email = REPLACE(u.email, '@example.com', '@jldminerals.com')
WHERE u.email LIKE '%@example.com'
  AND existing.id IS NULL;

-- Reset password and unlock all users on jldminerals domain.
UPDATE users
SET password_hash = @new_pwd,
    failed_login_attempts = 0,
    locked_until = NULL,
    is_active = 1
WHERE email LIKE '%@jldminerals.com';

