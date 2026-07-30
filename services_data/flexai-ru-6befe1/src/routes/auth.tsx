import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { Link } from "@tanstack/react-router";
import { supabase } from "@/integrations/supabase/client";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "sonner";
import { Sparkles } from "lucide-react";

export const Route = createFileRoute("/auth")({ component: AuthPage });

function AuthPage() {
  const navigate = useNavigate();
  const { user, loading } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!loading && user) navigate({ to: "/chats" });
  }, [user, loading, navigate]);

  const onSignIn = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    const { error } = await supabase.auth.signInWithPassword({ email, password });
    setBusy(false);
    if (error) return toast.error(error.message);
    toast.success("Добро пожаловать!");
    navigate({ to: "/chats" });
  };

  const onSignUp = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    const { error } = await supabase.auth.signUp({
      email, password,
      options: { emailRedirectTo: `${window.location.origin}/` },
    });
    setBusy(false);
    if (error) return toast.error(error.message);
    toast.success("Письмо подтверждения отправлено на email");
  };

  return (
    <div className="min-h-screen bg-background flex flex-col items-center px-5 py-10">
      <div className="flex items-center gap-2 mb-8">
        <div className="h-12 w-12 rounded-2xl flex-gradient flex items-center justify-center flex-glow">
          <Sparkles className="h-6 w-6 text-primary-foreground" />
        </div>
        <h1 className="text-3xl font-bold flex-text-gradient">FLEXAI</h1>
      </div>
      <p className="text-center text-muted-foreground text-sm mb-8 max-w-xs">
        Создавай ИИ-персонажей и общайся с ними. Получай монетки каждый день.
      </p>

      <Tabs defaultValue="signin" className="w-full max-w-sm">
        <TabsList className="grid grid-cols-2 w-full">
          <TabsTrigger value="signin">Вход</TabsTrigger>
          <TabsTrigger value="signup">Регистрация</TabsTrigger>
        </TabsList>
        <TabsContent value="signin">
          <form onSubmit={onSignIn} className="space-y-4 mt-4">
            <div><Label>Email</Label><Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></div>
            <div><Label>Пароль</Label><Input type="password" required minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} /></div>
            <Button type="submit" className="w-full" disabled={busy}>{busy ? "..." : "Войти"}</Button>
          </form>
        </TabsContent>
        <TabsContent value="signup">
          <form onSubmit={onSignUp} className="space-y-4 mt-4">
            <div><Label>Email</Label><Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></div>
            <div><Label>Пароль</Label><Input type="password" required minLength={6} value={password} onChange={(e) => setPassword(e.target.value)} /></div>
            <Button type="submit" className="w-full" disabled={busy}>{busy ? "..." : "Создать аккаунт"}</Button>
            <p className="text-xs text-muted-foreground text-center">
              Подтвердите email письмом, которое мы отправим. Регистрируясь, вы соглашаетесь с {" "}
              <Link to="/terms" className="underline">условиями</Link> и {" "}
              <Link to="/privacy" className="underline">политикой</Link>.
            </p>
          </form>
        </TabsContent>
      </Tabs>

      <div className="mt-10 flex gap-4 text-xs text-muted-foreground">
        <Link to="/terms">Условия</Link>
        <Link to="/privacy">Политика</Link>
        <Link to="/faq">FAQ</Link>
        <Link to="/docs">Docs</Link>
      </div>
    </div>
  );
}
