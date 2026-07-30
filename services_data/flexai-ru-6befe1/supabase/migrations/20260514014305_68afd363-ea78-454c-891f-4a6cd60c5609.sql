
-- daily_claims: allow inserting own claims
CREATE POLICY "insert own claims" ON public.daily_claims
  FOR INSERT TO authenticated
  WITH CHECK (auth.uid() = user_id);

-- usage_log: allow inserting own usage
CREATE POLICY "insert own usage" ON public.usage_log
  FOR INSERT TO authenticated
  WITH CHECK (auth.uid() = user_id);

-- characters: allow insert
CREATE POLICY "insert own characters" ON public.characters
  FOR INSERT TO authenticated
  WITH CHECK (auth.uid() = user_id);

-- messages: allow insert
CREATE POLICY "insert own messages" ON public.messages
  FOR INSERT TO authenticated
  WITH CHECK (auth.uid() = user_id);

-- messages: allow delete (when deleting characters cascades is not set, but allow delete by user)
CREATE POLICY "delete own messages" ON public.messages
  FOR DELETE TO authenticated
  USING (auth.uid() = user_id);
