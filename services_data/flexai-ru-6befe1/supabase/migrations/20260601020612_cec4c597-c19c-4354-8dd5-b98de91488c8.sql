-- Idempotency for usage_log: prevent double-charge on retries/races
ALTER TABLE public.usage_log
  ADD COLUMN IF NOT EXISTS idempotency_key text;

-- Unique key per user+action+idempotency_key so a replay collides
CREATE UNIQUE INDEX IF NOT EXISTS usage_log_idem_unique
  ON public.usage_log (user_id, action, idempotency_key)
  WHERE idempotency_key IS NOT NULL;

-- Helpful index for daily-window counts
CREATE INDEX IF NOT EXISTS usage_log_user_action_created_at
  ON public.usage_log (user_id, action, created_at DESC);