import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  Outlet,
  Link,
  createRootRouteWithContext,
  useRouter,
  HeadContent,
  Scripts,
} from "@tanstack/react-router";
import { Toaster } from "@/components/ui/sonner";
import { AuthProvider } from "@/lib/auth-context";
import { Splash } from "@/components/Splash";
import appCss from "../styles.css?url";

function NotFoundComponent() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-7xl font-bold flex-text-gradient">404</h1>
        <h2 className="mt-4 text-xl font-semibold">Страница не найдена</h2>
        <Link to="/" className="mt-6 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">На главную</Link>
      </div>
    </div>
  );
}

function ErrorComponent({ error, reset }: { error: Error; reset: () => void }) {
  const router = useRouter();
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="text-xl font-semibold">Что-то пошло не так</h1>
        <p className="mt-2 text-sm text-muted-foreground">{error.message}</p>
        <button
          onClick={() => { router.invalidate(); reset(); }}
          className="mt-6 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
        >
          Повторить
        </button>
      </div>
    </div>
  );
}

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1, viewport-fit=cover" },
      { name: "theme-color", content: "#1a1a2e" },
      { title: "FLEXAI — Создавай и общайся с ИИ-персонажами" },
      { name: "description", content: "FLEXAI — мобильное приложение для создания и общения с ИИ-персонажами. Зарабатывай монетки и оживляй своих героев." },
      { property: "og:title", content: "FLEXAI — Создавай и общайся с ИИ-персонажами" },
      { property: "og:description", content: "FLEXAI — мобильное приложение для создания и общения с ИИ-персонажами. Зарабатывай монетки и оживляй своих героев." },
      { property: "og:type", content: "website" },
      { name: "twitter:title", content: "FLEXAI — Создавай и общайся с ИИ-персонажами" },
      { name: "twitter:description", content: "FLEXAI — мобильное приложение для создания и общения с ИИ-персонажами. Зарабатывай монетки и оживляй своих героев." },
      { property: "og:image", content: "https://pub-bb2e103a32db4e198524a2e9ed8f35b4.r2.dev/5ad4295f-d380-413a-a1a7-182ca98e3ea4/id-preview-43de6832--2d1e876a-da43-49e6-8775-faed57d93914.lovable.app-1778657127170.png" },
      { name: "twitter:image", content: "https://pub-bb2e103a32db4e198524a2e9ed8f35b4.r2.dev/5ad4295f-d380-413a-a1a7-182ca98e3ea4/id-preview-43de6832--2d1e876a-da43-49e6-8775-faed57d93914.lovable.app-1778657127170.png" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
    links: [
      { rel: "stylesheet", href: appCss },
      { rel: "manifest", href: "/manifest.webmanifest" },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
  errorComponent: ErrorComponent,
});

function RootShell({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ru">
      <head><HeadContent /></head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  );
}

function RootComponent() {
  const { queryClient } = Route.useRouteContext();
  // Register PWA service worker (production only — guarded inside helper)
  if (typeof window !== "undefined") {
    // dynamic import keeps it out of SSR
    import("@/lib/sw-register").then((m) => m.registerSW()).catch(() => null);
  }
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Splash />
        <Outlet />
        <Toaster />
      </AuthProvider>
    </QueryClientProvider>
  );
}
