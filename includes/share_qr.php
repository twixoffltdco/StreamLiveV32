<?php
function share_qr_url(string $url, int $size = 180): string {
  // Внешний QR без ключа (можно заменить на локальный позже)
  return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . rawurlencode($url);
}

function share_block_html(string $url, string $title = 'Поделиться'): string {
  $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
  $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
  $qr = htmlspecialchars(share_qr_url(html_entity_decode($url, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8');
  return '<div class="share-box" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:12px 0;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.1)">'
    . '<img src="' . $qr . '" width="100" height="100" alt="QR" style="border-radius:8px;background:#fff">'
    . '<div style="flex:1;min-width:180px">'
    . '<div style="font-weight:600;margin-bottom:6px">' . $title . '</div>'
    . '<input type="text" readonly value="' . $url . '" onclick="this.select()" style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.25);color:inherit;font-size:12px">'
    . '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">'
    . '<a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="https://t.me/share/url?url=' . rawurlencode(html_entity_decode($url, ENT_QUOTES, 'UTF-8')) . '">Telegram</a>'
    . '<a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="https://vk.com/share.php?url=' . rawurlencode(html_entity_decode($url, ENT_QUOTES, 'UTF-8')) . '">VK</a>'
    . '</div></div></div>';
}
