
ALTER TABLE public.characters ADD COLUMN IF NOT EXISTS is_public boolean NOT NULL DEFAULT false;
ALTER TABLE public.characters ADD COLUMN IF NOT EXISTS chat_count integer NOT NULL DEFAULT 0;
ALTER TABLE public.characters ADD COLUMN IF NOT EXISTS category text NOT NULL DEFAULT 'Прочее';

CREATE POLICY "view public characters"
ON public.characters FOR SELECT
TO authenticated
USING (is_public = true);

CREATE POLICY "update own characters"
ON public.characters FOR UPDATE
TO authenticated
USING (auth.uid() = user_id);

CREATE INDEX IF NOT EXISTS idx_characters_public ON public.characters (is_public, chat_count DESC) WHERE is_public = true;
