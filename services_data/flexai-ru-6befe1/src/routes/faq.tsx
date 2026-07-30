import { createFileRoute } from "@tanstack/react-router";
import { StaticPage } from "./terms";

export const Route = createFileRoute("/faq")({
  head: () => ({ meta: [{ title: "FAQ — FLEXAI" }] }),
  component: () => (
    <StaticPage title="FAQ">
      <h2 className="font-semibold">Что такое FLEXAI?</h2>
      <p className="text-sm">Это приложение для создания собственных ИИ-персонажей и общения с ними в формате чата.</p>
      <h2 className="font-semibold mt-4">Как работают монетки?</h2>
      <p className="text-sm">При регистрации вы получаете 50 монет. Каждый день можно забрать +25. Создание персонажа — 30 монет, одно сообщение — 2 монеты.</p>
      <h2 className="font-semibold mt-4">Какие лимиты?</h2>
      <p className="text-sm">1 персонаж в 24 часа и 5 сообщений в 24 часа.</p>
      <h2 className="font-semibold mt-4">Какие модели ИИ используются?</h2>
      <p className="text-sm">Современные большие языковые модели через шлюз Lovable AI.</p>
      <h2 className="font-semibold mt-4">Можно ли использовать в России?</h2>
      <p className="text-sm">Да, сервис работает без VPN.</p>
      <h2 className="font-semibold mt-4">Удалить аккаунт?</h2>
      <p className="text-sm">Напишите запрос в поддержку — данные будут удалены.</p>
    </StaticPage>
  ),
});
