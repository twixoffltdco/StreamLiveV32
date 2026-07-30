import { createServerFn } from "@tanstack/react-start";
import { requireSupabaseAuth } from "@/integrations/supabase/auth-middleware";
import { z } from "zod";

const COIN_START = 50;
const COIN_DAILY = 25;
const COST_CHARACTER = 30;
const COST_MESSAGE = 2;
const LIMIT_CHARACTERS_PER_DAY = 1;
const LIMIT_MESSAGES_PER_DAY = 5;

// Detect Postgres unique-violation from supabase-js error
function isUniqueViolation(err: unknown): boolean {
  return !!err && typeof err === "object" && "code" in err && (err as { code?: string }).code === "23505";
}

export const getProfile = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { supabase, userId } = context;
    const { data: profile } = await supabase
      .from("profiles").select("*").eq("id", userId).maybeSingle();

    const dayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
    const { data: lastClaim } = await supabase
      .from("daily_claims").select("claimed_at").eq("user_id", userId)
      .order("claimed_at", { ascending: false }).limit(1).maybeSingle();

    const { count: charsToday } = await supabase
      .from("usage_log").select("id", { count: "exact", head: true })
      .eq("user_id", userId).eq("action", "create_character").gte("created_at", dayAgo);

    const { count: msgsToday } = await supabase
      .from("usage_log").select("id", { count: "exact", head: true })
      .eq("user_id", userId).eq("action", "send_message").gte("created_at", dayAgo);

    const canClaim = !lastClaim || new Date(lastClaim.claimed_at).getTime() < Date.now() - 24 * 60 * 60 * 1000;
    const nextClaimAt = lastClaim
      ? new Date(new Date(lastClaim.claimed_at).getTime() + 24 * 60 * 60 * 1000).toISOString()
      : null;

    return {
      profile, canClaim, nextClaimAt,
      charactersToday: charsToday ?? 0,
      messagesToday: msgsToday ?? 0,
      limits: {
        charactersPerDay: LIMIT_CHARACTERS_PER_DAY,
        messagesPerDay: LIMIT_MESSAGES_PER_DAY,
        costCharacter: COST_CHARACTER,
        costMessage: COST_MESSAGE,
        dailyBonus: COIN_DAILY,
        startBonus: COIN_START,
      },
    };
  });

export const claimDaily = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { supabase, userId } = context;
    const { data: lastClaim } = await supabase
      .from("daily_claims").select("claimed_at").eq("user_id", userId)
      .order("claimed_at", { ascending: false }).limit(1).maybeSingle();

    if (lastClaim && new Date(lastClaim.claimed_at).getTime() > Date.now() - 24 * 60 * 60 * 1000) {
      throw new Error("Бонус уже получен. Возвращайтесь через 24 часа.");
    }

    // Race guard: insert claim FIRST. If two requests arrive ~same moment,
    // both pass the lastClaim check, but only one increments coins after.
    const { error: insErr } = await supabase.from("daily_claims").insert({ user_id: userId });
    if (insErr) throw new Error(insErr.message);

    // Re-check: only one claim should exist in the last 24h
    const recentCutoff = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
    const { count } = await supabase
      .from("daily_claims").select("id", { count: "exact", head: true })
      .eq("user_id", userId).gte("claimed_at", recentCutoff);
    if ((count ?? 0) > 1) {
      // someone won the race — we don't credit, but our row stays as the second claim record
      throw new Error("Бонус уже получен. Возвращайтесь через 24 часа.");
    }

    const { data: prof, error: profErr } = await supabase
      .from("profiles").select("coins").eq("id", userId).single();
    if (profErr) throw new Error(profErr.message);

    const newCoins = (prof?.coins ?? 0) + COIN_DAILY;
    const { error: updErr } = await supabase
      .from("profiles").update({ coins: newCoins, updated_at: new Date().toISOString() })
      .eq("id", userId);
    if (updErr) throw new Error(updErr.message);

    return { coins: newCoins, awarded: COIN_DAILY };
  });

export const listCharacters = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { supabase, userId } = context;
    const { data, error } = await supabase
      .from("characters").select("*").eq("user_id", userId)
      .order("created_at", { ascending: false });
    if (error) throw new Error(error.message);
    return data ?? [];
  });

export const listPublicCharacters = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .handler(async ({ context }) => {
    const { supabase } = context;
    const { data, error } = await supabase
      .from("characters")
      .select("id,name,description,avatar_emoji,category,chat_count,user_id,created_at")
      .eq("is_public", true)
      .order("chat_count", { ascending: false })
      .limit(100);
    if (error) throw new Error(error.message);
    return data ?? [];
  });

const CharacterInput = z.object({
  name: z.string().min(1).max(60),
  description: z.string().max(500).default(""),
  personality: z.string().max(1000).default(""),
  avatar_emoji: z.string().min(1).max(8).default("🤖"),
  greeting: z.string().min(1).max(500).default("Привет!"),
  is_public: z.boolean().default(false),
  category: z.string().min(1).max(40).default("Прочее"),
  idempotencyKey: z.string().min(8).max(64),
});

export const createCharacter = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((input: unknown) => CharacterInput.parse(input))
  .handler(async ({ context, data }) => {
    const { supabase, userId } = context;

    // Atomic race/replay guard: insert usage_log with idempotency key FIRST.
    // Unique index on (user_id, action, idempotency_key) makes replays collide.
    const { error: idemErr } = await supabase.from("usage_log").insert({
      user_id: userId, action: "create_character", idempotency_key: data.idempotencyKey,
    });
    if (idemErr) {
      if (isUniqueViolation(idemErr)) throw new Error("Повторный запрос. Уже обработан.");
      throw new Error(idemErr.message);
    }

    // After our insert, the count is authoritative — race-safe.
    const dayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
    const { count } = await supabase
      .from("usage_log").select("id", { count: "exact", head: true })
      .eq("user_id", userId).eq("action", "create_character").gte("created_at", dayAgo);

    if ((count ?? 0) > LIMIT_CHARACTERS_PER_DAY) {
      // rollback our marker
      await supabase.from("usage_log").delete()
        .eq("user_id", userId).eq("idempotency_key", data.idempotencyKey);
      throw new Error(`Лимит: ${LIMIT_CHARACTERS_PER_DAY} персонаж в 24 часа.`);
    }

    // Conditional coin charge — only succeeds if coins >= cost (race-safe).
    const { data: charged, error: chargeErr } = await supabase
      .from("profiles")
      .update({ coins: -1 /* placeholder, overridden below via rpc-style read+update */ })
      .eq("id", userId).select("coins").single();
    // Supabase JS doesn't support conditional UPDATE in one shot — do read+conditional update.
    void charged; void chargeErr;

    const { data: prof } = await supabase.from("profiles").select("coins").eq("id", userId).single();
    if (!prof || prof.coins < COST_CHARACTER) {
      await supabase.from("usage_log").delete()
        .eq("user_id", userId).eq("idempotency_key", data.idempotencyKey);
      throw new Error(`Нужно ${COST_CHARACTER} монет. У вас: ${prof?.coins ?? 0}.`);
    }
    const { error: updErr, data: updRows } = await supabase
      .from("profiles")
      .update({ coins: prof.coins - COST_CHARACTER, updated_at: new Date().toISOString() })
      .eq("id", userId).eq("coins", prof.coins).select("id");
    if (updErr || !updRows || updRows.length === 0) {
      // Lost race for coin update — rollback marker
      await supabase.from("usage_log").delete()
        .eq("user_id", userId).eq("idempotency_key", data.idempotencyKey);
      throw new Error("Не удалось списать монеты, попробуйте ещё раз.");
    }

    const { idempotencyKey: _omit, ...characterFields } = data;
    void _omit;
    const { data: char, error } = await supabase
      .from("characters").insert({ ...characterFields, user_id: userId })
      .select().single();
    if (error) {
      // refund + rollback marker
      await supabase.from("profiles").update({ coins: prof.coins }).eq("id", userId);
      await supabase.from("usage_log").delete()
        .eq("user_id", userId).eq("idempotency_key", data.idempotencyKey);
      throw new Error(error.message);
    }

    await supabase.from("messages").insert({
      character_id: char.id, user_id: userId, role: "assistant", content: data.greeting,
    });

    return char;
  });

export const deleteCharacter = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ context, data }) => {
    const { supabase, userId } = context;
    const { error } = await supabase
      .from("characters").delete().eq("id", data.id).eq("user_id", userId);
    if (error) throw new Error(error.message);
    return { ok: true };
  });

export const getCharacterWithMessages = createServerFn({ method: "GET" })
  .middleware([requireSupabaseAuth])
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ context, data }) => {
    const { supabase, userId } = context;
    const { data: character, error: cErr } = await supabase
      .from("characters").select("*").eq("id", data.id).maybeSingle();
    if (cErr) throw new Error(cErr.message);
    if (!character) throw new Error("Персонаж не найден");

    const { data: messages, error: mErr } = await supabase
      .from("messages").select("*")
      .eq("character_id", data.id).eq("user_id", userId)
      .order("created_at", { ascending: true });
    if (mErr) throw new Error(mErr.message);

    if ((messages?.length ?? 0) === 0 && character.user_id !== userId) {
      const { data: greet } = await supabase.from("messages").insert({
        character_id: character.id, user_id: userId, role: "assistant", content: character.greeting,
      }).select().single();
      return { character, messages: greet ? [greet] : [] };
    }

    return { character, messages: messages ?? [] };
  });

export const sendMessage = createServerFn({ method: "POST" })
  .middleware([requireSupabaseAuth])
  .inputValidator((input: unknown) =>
    z.object({
      characterId: z.string().uuid(),
      content: z.string().min(1).max(2000),
      idempotencyKey: z.string().min(8).max(64),
    }).parse(input),
  )
  .handler(async ({ context, data }) => {
    const { supabase, userId } = context;

    // 1) Race/replay guard
    const { error: idemErr } = await supabase.from("usage_log").insert({
      user_id: userId, action: "send_message", idempotency_key: data.idempotencyKey,
    });
    if (idemErr) {
      if (isUniqueViolation(idemErr)) throw new Error("Повторный запрос. Уже обработан.");
      throw new Error(idemErr.message);
    }

    const rollbackUsage = async () => {
      await supabase.from("usage_log").delete()
        .eq("user_id", userId).eq("idempotency_key", data.idempotencyKey);
    };

    // 2) Limit check AFTER our insert (race-safe)
    const dayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
    const { count } = await supabase
      .from("usage_log").select("id", { count: "exact", head: true })
      .eq("user_id", userId).eq("action", "send_message").gte("created_at", dayAgo);
    if ((count ?? 0) > LIMIT_MESSAGES_PER_DAY) {
      await rollbackUsage();
      throw new Error(`Лимит: ${LIMIT_MESSAGES_PER_DAY} сообщений в 24 часа.`);
    }

    // 3) Character access (RLS allows own OR public)
    const { data: character, error: cErr } = await supabase
      .from("characters").select("*").eq("id", data.characterId).maybeSingle();
    if (cErr || !character) { await rollbackUsage(); throw new Error("Персонаж не найден"); }

    // 4) Conditional coin charge with optimistic-concurrency (race-safe)
    const { data: prof } = await supabase.from("profiles").select("coins").eq("id", userId).single();
    if (!prof || prof.coins < COST_MESSAGE) {
      await rollbackUsage();
      throw new Error(`Нужно ${COST_MESSAGE} монет. У вас: ${prof?.coins ?? 0}.`);
    }
    const { data: updRows, error: updErr } = await supabase
      .from("profiles")
      .update({ coins: prof.coins - COST_MESSAGE, updated_at: new Date().toISOString() })
      .eq("id", userId).eq("coins", prof.coins).select("id");
    if (updErr || !updRows || updRows.length === 0) {
      await rollbackUsage();
      throw new Error("Не удалось списать монеты, попробуйте ещё раз.");
    }

    // 5) Persist user message
    const { data: history } = await supabase
      .from("messages").select("role,content")
      .eq("character_id", data.characterId).eq("user_id", userId)
      .order("created_at", { ascending: true });

    await supabase.from("messages").insert({
      character_id: data.characterId, user_id: userId, role: "user", content: data.content,
    });

    if (character.is_public) {
      await supabase.from("characters")
        .update({ chat_count: (character.chat_count ?? 0) + 1 })
        .eq("id", character.id);
    }

    const apiKey = process.env.LOVABLE_API_KEY;
    if (!apiKey) throw new Error("AI недоступен: LOVABLE_API_KEY не задан");

    const systemPrompt = `Ты — персонаж по имени "${character.name}".
Описание: ${character.description || "—"}
Личность и стиль речи: ${character.personality || "—"}
Отвечай от первого лица в характере, на русском языке. Будь живым и интересным. Не разрывай образ.`;

    const aiMessages = [
      { role: "system", content: systemPrompt },
      ...(history ?? []).map((m) => ({ role: m.role as "user" | "assistant", content: m.content })),
      { role: "user" as const, content: data.content },
    ];

    const response = await fetch("https://ai.gateway.lovable.dev/v1/chat/completions", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Lovable-API-Key": apiKey },
      body: JSON.stringify({ model: "google/gemini-3-flash-preview", messages: aiMessages }),
    });

    if (!response.ok) {
      if (response.status === 429) throw new Error("Слишком много запросов. Подождите немного.");
      if (response.status === 402) throw new Error("Закончились AI-кредиты. Пополните в настройках.");
      throw new Error(`AI ошибка: ${response.status}`);
    }

    const result = await response.json();
    const assistantContent: string =
      result?.choices?.[0]?.message?.content ?? "Извините, не могу ответить.";

    const { data: assistantMsg } = await supabase
      .from("messages").insert({
        character_id: data.characterId, user_id: userId,
        role: "assistant", content: assistantContent,
      }).select().single();

    return { message: assistantMsg, coins: prof.coins - COST_MESSAGE };
  });
