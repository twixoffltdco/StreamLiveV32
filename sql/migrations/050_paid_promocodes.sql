ALTER TABLE channels ADD COLUMN paid_content TINYINT(1) NOT NULL DEFAULT 0;
-- promo_codes, promo_activations, mod_promo_creates создаются из paid_access.php
