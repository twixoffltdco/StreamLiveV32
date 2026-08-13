(function () {
  var cfg = window.__FXW__ || {};
  var host = document.getElementById('fxw-canvas');
  if (!host || typeof THREE === 'undefined') return;
  var isMobile = /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent) || (matchMedia && matchMedia('(pointer:coarse)').matches);

  var mapId = Math.max(1, Math.min(100, cfg.map || 1));
  function seeded(n) {
    var x = Math.sin(n * 12.9898 + mapId * 78.233) * 43758.5453;
    return x - Math.floor(x);
  }

  var scene = new THREE.Scene();
  var sky = new THREE.Color().setHSL(0.5 + (mapId % 20) * 0.02, 0.4, 0.65 + (mapId % 5) * 0.03);
  scene.background = sky;
  scene.fog = new THREE.Fog(sky, 40 + (mapId % 10) * 3, 110 + (mapId % 15) * 2);

  var w = host.clientWidth || innerWidth, h = host.clientHeight || 480;
  var camera = new THREE.PerspectiveCamera(70, w / h, 0.1, 250);
  var renderer = new THREE.WebGLRenderer({ antialias: !isMobile });
  renderer.setSize(w, h);
  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, isMobile ? 1.4 : 2));
  host.innerHTML = '';
  host.appendChild(renderer.domElement);
  var canvas = renderer.domElement;
  canvas.style.cssText = 'display:block;width:100%;height:100%;touch-action:none';
  scene.add(new THREE.AmbientLight(0xffffff, 0.7));
  var sun = new THREE.DirectionalLight(0xffffff, 0.95); sun.position.set(25, 40, 15); scene.add(sun);

  var obstacles = [];
  function addBox(sx, sy, sz, color, x, y, z, solid) {
    var m = new THREE.Mesh(new THREE.BoxGeometry(sx, sy, sz), new THREE.MeshLambertMaterial({ color: color }));
    m.position.set(x, y, z); scene.add(m);
    if (solid !== false) obstacles.push({ min:[x-sx/2,y-sy/2,z-sz/2], max:[x+sx/2,y+sy/2,z+sz/2] });
    return m;
  }
  function collides(px, py, pz) {
    var r = 0.4, hg = 1.7;
    for (var i = 0; i < obstacles.length; i++) {
      var o = obstacles[i];
      if (px+r>o.min[0]&&px-r<o.max[0]&&py+hg>o.min[1]&&py<o.max[1]&&pz+r>o.min[2]&&pz-r<o.max[2]) return true;
    }
    return false;
  }

  // Ground
  var groundCols = [0x4a5568, 0x3f6212, 0x1e3a5f, 0x7c2d12, 0x334155, 0x365314, 0x4c1d95];
  addBox(140, 1, 140, groundCols[mapId % groundCols.length], 0, -0.5, 0, false);

  // Map layouts
  var type = mapId % 10;
  var density = 8 + (mapId % 12);
  if (type === 0 || type === 1) {
    for (var i = 0; i < density; i++) {
      var bx = -50 + seeded(i) * 100, bz = -50 + seeded(i + 50) * 100;
      addBox(2 + seeded(i+2)*4, 2 + seeded(i+3)*10, 2 + seeded(i+4)*4, 0x64748b + (mapId*17)%0x202020, bx, 1+seeded(i)*4, bz, true);
    }
  } else if (type === 2) {
    for (var i = 0; i < 8 + mapId % 6; i++) addBox(2.2, 0.4, 2.2, 0x22c55e, 3+i*2.2, 0.5+i*0.55, -8 + (mapId%5), true);
  } else if (type === 3) {
    // Vice City inspired: pastel towers, neon, ocean strip
    scene.background = new THREE.Color(0x87ceeb);
    scene.fog = new THREE.Fog(0x87ceeb, 50, 130);
    var vcCols = [0xf9a8d4, 0x67e8f9, 0xfde68a, 0xc4b5fd, 0x86efac, 0xfca5a5, 0x93c5fd];
    for (var cx = -36; cx <= 36; cx += 7)
      for (var cz = -36; cz <= 20; cz += 7)
        if (Math.abs(cx) > 5 || Math.abs(cz) > 5) {
          var ch = 3 + Math.abs((cx * 3 + cz * 5 + mapId) % 12);
          addBox(4.5, ch, 4.5, vcCols[Math.abs(cx + cz * 2) % vcCols.length], cx, ch / 2, cz, true);
        }
    // roads
    addBox(90, 0.08, 6, 0x1f2937, 0, 0.04, 0, false);
    addBox(6, 0.08, 90, 0x1f2937, 0, 0.04, 0, false);
    addBox(90, 0.08, 6, 0x1f2937, 0, 0.04, 18, false);
    addBox(90, 0.08, 6, 0x1f2937, 0, 0.04, -18, false);
    // ocean
    addBox(120, 0.05, 28, 0x0ea5e9, 0, 0.01, 48, false);
    // palm-ish props
    for (var pi = 0; pi < 8; pi++) {
      addBox(0.35, 3.5, 0.35, 0x166534, -30 + pi * 8, 1.8, 28, true);
      addBox(1.8, 0.3, 1.8, 0x22c55e, -30 + pi * 8, 3.7, 28, false);
    }
  } else if (type === 4) {
    var R = 22 + mapId % 10;
    for (var a = 0; a < 16; a++) {
      var ang = a / 16 * Math.PI * 2;
      addBox(2, 3+(a+mapId)%4, 2, 0xa855f7, Math.cos(ang)*R, 2, Math.sin(ang)*R, true);
    }
  } else if (type === 5) {
    for (var i = 0; i < 20; i++) addBox(1.2, 0.4+seeded(i)*1.5, 8+seeded(i+1)*6, 0x0ea5e9, -40+i*4, 0.5, -20+seeded(i)*40, true);
  } else if (type === 6) {
    addBox(50, 2, 2, 0x334155, 0, 1, -25, true); addBox(50, 2, 2, 0x334155, 0, 1, 25, true);
    addBox(2, 2, 50, 0x334155, -25, 1, 0, true); addBox(2, 2, 50, 0x334155, 25, 1, 0, true);
    for (var i = 0; i < 6; i++) addBox(3, 5, 3, 0xf97316, -15+i*6, 2.5, 0, true);
  } else if (type === 7) {
    for (var i = 0; i < 15; i++) addBox(8, 0.5, 1.5, 0xeab308, -30+i*4, 0.4, -10+seeded(i)*20, true);
  } else if (type === 8) {
    for (var i = 0; i < 25; i++) addBox(1.5, 1+seeded(i)*8, 1.5, 0xec4899, -40+seeded(i)*80, 1, -40+seeded(i+9)*80, true);
  } else {
    for (var i = 0; i < density; i++) addBox(3, 1+seeded(i)*3, 3, 0x14b8a6, -35+seeded(i)*70, 1, -35+seeded(i+3)*70, true);
  }
  for (var k = 0; k < 3 + (mapId % 5); k++) {
    addBox(1, 1, 1, 0xfbbf24, -20 + seeded(100+k)*40, 0.5, -20 + seeded(200+k)*40, true);
  }

  // ---------- NPCs (humanoid R6, same map for all) ----------
  var NPC_LINES = [
    'Привет! Нужна помощь? Нажми E рядом.',
    'Я NPC. Карта №' + mapId + ' — одна на всех.',
    'Открой телефон (P) — внутри вся платформа.',
    'Payday в :30 — не пропусти.',
    'Оружие: 1 кулаки, 2 бита, 3 пистолет. ЛКМ — удар.',
    'За убийство дают 10 000 XP.',
    'Админы и модеры с префиксами — как на Arizona.',
    'Если АФК 5 мин — кик. Двигайся!',
    'Подойди ближе — расскажу квест.',
    'Квест: поговори с тремя NPC на карте.'
  ];
  var npcs = [];
  function makeNpc(x, z, name) {
    var g = makeR6({
      head_color: '#fde68a', torso_color: '#f59e0b',
      arms_color: '#fde68a', legs_color: '#78350f'
    }, name, {});
    g.position.set(x, 1, z);
    g.userData.npc = true;
    g.userData.name = name;
    g.userData.line = NPC_LINES[Math.floor(seeded(x * 10 + z) * NPC_LINES.length)];
    g.userData.hp = 100;
    scene.add(g);
    npcs.push(g);
    return g;
  }
  // Fixed positions from seed → same map for everyone
  makeNpc(-8 + (mapId % 7), 6, 'Вова');
  makeNpc(12, -5 - (mapId % 4), 'Марина');
  makeNpc(-15 + (mapId % 5), -12, 'Квестёр');
  makeNpc(5, 15, 'Алексей');
  makeNpc(-22, 8, 'Игорь');
  makeNpc(18, 10, 'Света');
  if (mapId % 3 === 0) makeNpc(0, -18, 'Старик');

  // ---------- Character factory ----------
  function makeNameTag(name, role, hasCrown) {
    var cv = document.createElement('canvas');
    cv.width = 320; cv.height = 64;
    var ctx = cv.getContext('2d');
    ctx.clearRect(0, 0, 320, 64);
    var x = 10;
    if (role === 'admin') {
      ctx.fillStyle = '#dc2626';
      roundRect(ctx, x, 18, 78, 28, 12); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'center';
      ctx.fillText('Админ', x + 39, 37);
      x += 86;
    } else if (role === 'moderator') {
      ctx.fillStyle = '#2563eb';
      roundRect(ctx, x, 18, 72, 28, 12); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'center';
      ctx.fillText('Модер', x + 36, 37);
      x += 80;
    }
    if (hasCrown) {
      ctx.fillStyle = '#fbbf24';
      ctx.beginPath();
      ctx.moveTo(x + 4, 40); ctx.lineTo(x + 12, 20); ctx.lineTo(x + 20, 32);
      ctx.lineTo(x + 28, 18); ctx.lineTo(x + 36, 40); ctx.closePath(); ctx.fill();
      x += 44;
    }
    ctx.fillStyle = 'rgba(0,0,0,0.55)';
    roundRect(ctx, x, 16, Math.min(200, 16 + String(name).length * 12), 32, 8); ctx.fill();
    ctx.fillStyle = '#fff'; ctx.font = 'bold 18px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText(String(name).slice(0, 16), x + 8, 38);
    var spr = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(cv), transparent: true }));
    spr.scale.set(2.8, 0.56, 1); spr.position.set(0, 2.75, 0);
    return spr;
  }
  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  function makeR6(av, name, opts) {
    opts = opts || {};
    av = av || {};
    var g = new THREE.Group();
    function p(sx, sy, sz, c, x, y, z) {
      var m = new THREE.Mesh(new THREE.BoxGeometry(sx, sy, sz), new THREE.MeshLambertMaterial({ color: new THREE.Color(c || '#888') }));
      m.position.set(x, y, z); return m;
    }
    g.add(p(0.8, 0.8, 0.8, av.head_color || av.head || '#f5d0c5', 0, 1.7, 0));
    g.add(p(1.0, 1.2, 0.5, av.torso_color || av.torso || '#00a2ff', 0, 0.9, 0));
    var armL = p(0.35, 1.1, 0.35, av.arms_color || '#f5d0c5', -0.7, 0.9, 0);
    var armR = p(0.35, 1.1, 0.35, av.arms_color || '#f5d0c5', 0.7, 0.9, 0);
    g.add(armL); g.add(armR);
    g.userData.armL = armL; g.userData.armR = armR;
    g.add(p(0.4, 1.0, 0.4, av.legs_color || '#1e3a5f', -0.3, 0.1, 0));
    g.add(p(0.4, 1.0, 0.4, av.legs_color || '#1e3a5f', 0.3, 0.1, 0));
    if (av.hat && av.hat !== 'none') g.add(p(0.95, 0.28, 0.95, '#222', 0, 2.22, 0));
    if (av.accessory === 'glasses') g.add(p(0.7, 0.12, 0.5, '#111', 0, 1.75, 0.35));
    if (av.accessory === 'backpack') g.add(p(0.7, 0.8, 0.3, '#166534', 0, 1.0, -0.4));
    if (opts.crown || av.crown) {
      var c = new THREE.Mesh(new THREE.ConeGeometry(0.28, 0.35, 5), new THREE.MeshLambertMaterial({ color: 0xfbbf24 }));
      c.position.set(0, 2.4, 0); g.add(c);
    }
    // Phone — realistic slab + glowing screen (shows title of what user watches)
    var phone = new THREE.Group();
    var body = new THREE.Mesh(
      new THREE.BoxGeometry(0.42, 0.78, 0.07),
      new THREE.MeshLambertMaterial({ color: 0x0a0a0c })
    );
    phone.add(body);
    var screen = new THREE.Mesh(
      new THREE.PlaneGeometry(0.36, 0.68),
      new THREE.MeshBasicMaterial({ color: 0x1e3a5f })
    );
    screen.position.set(0, 0, 0.035);
    phone.add(screen);
    // canvas texture for live title on screen
    var pcv = document.createElement('canvas'); pcv.width = 128; pcv.height = 192;
    var pctx = pcv.getContext('2d');
    pctx.fillStyle = '#0f172a'; pctx.fillRect(0,0,128,192);
    pctx.fillStyle = '#00a2ff'; pctx.font = 'bold 14px sans-serif'; pctx.textAlign = 'center';
    pctx.fillText('StreamLive', 64, 28);
    pctx.fillStyle = '#94a3b8'; pctx.font = '11px sans-serif';
    pctx.fillText('телефон', 64, 48);
    var ptex = new THREE.CanvasTexture(pcv);
    screen.material = new THREE.MeshBasicMaterial({ map: ptex });
    phone.userData.screenTex = ptex;
    phone.userData.screenCtx = pctx;
    phone.userData.screenCanvas = pcv;
    phone.position.set(0.32, 1.25, 0.48);
    phone.rotation.x = -0.35;
    phone.visible = false;
    g.add(phone);
    g.userData.phone = phone;
    // Weapon meshes
    var bat = new THREE.Group();
    var batShaft = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.07, 1.05, 8), new THREE.MeshLambertMaterial({ color: 0xb45309 }));
    batShaft.rotation.z = 0.12; bat.add(batShaft);
    var batGrip = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 0.22, 8), new THREE.MeshLambertMaterial({ color: 0x44403c }));
    batGrip.position.set(-0.03, -0.48, 0); batGrip.rotation.z = 0.12; bat.add(batGrip);
    bat.position.set(0.72, 1.05, 0.22); bat.visible = false; g.add(bat); g.userData.bat = bat;
    var gun = new THREE.Group();
    gun.add(new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.14, 0.09), new THREE.MeshLambertMaterial({ color: 0x94a3b8 })));
    var barrel = new THREE.Mesh(new THREE.BoxGeometry(0.2, 0.07, 0.07), new THREE.MeshLambertMaterial({ color: 0xcbd5e1 }));
    barrel.position.set(0.26, 0.02, 0); gun.add(barrel);
    var grip = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.22, 0.08), new THREE.MeshLambertMaterial({ color: 0x57534e }));
    grip.position.set(-0.05, -0.14, 0); gun.add(grip);
    gun.position.set(0.65, 1.05, 0.38); gun.visible = false; g.add(gun); g.userData.gun = gun;

    if (av.merch_url && /^https?:\/\//i.test(String(av.merch_url))) {
      try {
        var ld = new THREE.TextureLoader();
        ld.crossOrigin = 'anonymous';
        ld.load(String(av.merch_url), function (tex) {
          var dec = new THREE.Mesh(
            new THREE.PlaneGeometry(0.75, 0.75),
            new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false, side: THREE.DoubleSide })
          );
          dec.position.set(0, 0.95, 0.28);
          g.add(dec);
        }, undefined, function () {});
      } catch (e) {}
    }
    if (name) {
      g.add(makeNameTag(name, opts.role || '', !!(opts.crown || av.crown)));
    }
    g.userData.role = opts.role || 'user';
    g.userData.hp = 100;
    return g;
  }

  var player = makeR6(cfg.avatar || {}, null, { crown: cfg.crown, role: cfg.role });
  player.position.set(0, 1, 0);
  scene.add(player);

  // ---------- Phone system ----------
  var phoneOn = false, phoneUrl = '', phoneTitle = '';
  var phoneEl = document.getElementById('fx-phone');
  var phoneFrame = document.getElementById('fx-phone-frame');
  var phoneTabs = {
    videos: { url: '/videos.php', title: 'Видео' },
    channels: { url: '/channels.php', title: 'Каналы' },
    forum: { url: '/forum.php', title: 'Форум' },
    resources: { url: '/resources.php', title: 'Ресурсы' },
    profile: { url: '/profile.php', title: 'Профиль' },
    rating: { url: '/rating.php', title: 'Рейтинг' }
  };
  var currentTab = 'videos';

  function paintPhoneScreen(title) {
    var ph = player.userData.phone;
    if (!ph || !ph.userData || !ph.userData.screenCtx) return;
    var ctx = ph.userData.screenCtx, cv = ph.userData.screenCanvas;
    ctx.fillStyle = '#0f172a'; ctx.fillRect(0,0,128,192);
    ctx.fillStyle = '#00a2ff'; ctx.font = 'bold 13px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('StreamLive', 64, 24);
    ctx.fillStyle = '#e2e8f0'; ctx.font = 'bold 12px sans-serif';
    var t = String(title || 'контент').slice(0, 18);
    ctx.fillText(t, 64, 90);
    ctx.fillStyle = '#64748b'; ctx.font = '10px sans-serif';
    ctx.fillText('смотрит сейчас', 64, 110);
    ph.userData.screenTex.needsUpdate = true;
  }
  function openPhone(tab) {
    phoneOn = true;
    questProgress('phone', 1);
    if (tab) currentTab = tab;
    if (player.userData.phone) player.userData.phone.visible = true;
    paintPhoneScreen(currentTab);
    // arms "holding phone" pose
    if (player.userData.armL) player.userData.armL.rotation.x = -0.9;
    if (player.userData.armR) player.userData.armR.rotation.x = -1.1;
    if (phoneEl) phoneEl.classList.add('open');
    if (phoneFrame) {
      var tab = phoneTabs[currentTab] || phoneTabs.videos;
      phoneUrl = tab.url || tab;
      phoneTitle = tab.title || currentTab;
      phoneFrame.src = phoneUrl;
      paintPhoneScreen(phoneTitle);
    }
    // prevent body scroll while phone open
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
    updateTabUI();
  }
  function closePhone() {
    phoneOn = false;
    if (player.userData.phone) player.userData.phone.visible = false;
    if (player.userData.armL) player.userData.armL.rotation.x = 0;
    if (player.userData.armR) player.userData.armR.rotation.x = 0;
    if (phoneEl) phoneEl.classList.remove('open');
    if (phoneFrame) phoneFrame.src = 'about:blank';
    document.body.style.overflow = '';
    document.documentElement.style.overflow = '';
    phoneUrl = ''; phoneTitle = '';
  }
  function updateTabUI() {
    var tabs = document.querySelectorAll('#fx-phone-tabs button');
    tabs.forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === currentTab);
    });
  }
  if (phoneEl) {
    document.getElementById('fx-phone-close').onclick = function (e) { e.stopPropagation(); closePhone(); };
    document.querySelectorAll('#fx-phone-tabs button').forEach(function (b) {
      b.onclick = function (e) {
        e.stopPropagation();
        currentTab = b.getAttribute('data-tab');
        openPhone(currentTab);
      };
    });
    // stop clicks inside phone from affecting game
    phoneEl.addEventListener('touchmove', function (e) { e.stopPropagation(); }, { passive: true });
    phoneEl.addEventListener('wheel', function (e) { e.stopPropagation(); }, { passive: true });
  }

  // ---------- Weapons & HP ----------
  var weapons = [
    { id: 0, name: 'Кулаки', dmg: 12, range: 2.2, cooldown: 450 },
    { id: 1, name: 'Бита', dmg: 28, range: 2.8, cooldown: 550 },
    { id: 2, name: 'Пистолет', dmg: 35, range: 28, cooldown: 400, gun: true }
  ];
  var weaponId = 0;
  var lastAttack = 0;
  var hp = 100;
  var dead = false;
  var remote = {};
  var remoteHp = {};

  function setWeapon(id) {
    weaponId = Math.max(0, Math.min(2, id));
    if (player.userData.bat) player.userData.bat.visible = weaponId === 1;
    if (player.userData.gun) player.userData.gun.visible = weaponId === 2;
    document.querySelectorAll('#fx-weapons button').forEach(function (b) {
      b.classList.toggle('active', +b.getAttribute('data-w') === weaponId);
    });
  }
  document.querySelectorAll('#fx-weapons button').forEach(function (b) {
    b.onclick = function () { setWeapon(+b.getAttribute('data-w')); bump(); };
  });

  function updateHpBar() {
    var fill = document.getElementById('fx-hp-fill');
    if (fill) fill.style.width = Math.max(0, Math.min(100, hp)) + '%';
  }
  updateHpBar();

  function showDeath(killerName) {
    dead = true;
    var el = document.getElementById('fx-death');
    var msg = document.getElementById('fx-death-msg');
    if (msg) msg.textContent = killerName ? ('Убил: ' + killerName) : 'Вас убили';
    if (el) el.classList.add('show');
  }
  function respawn() {
    dead = false; hp = 100; updateHpBar();
    player.position.set(0, 1, 0);
    var el = document.getElementById('fx-death');
    if (el) el.classList.remove('show');
    bump();
  }
  var respawnBtn = document.getElementById('fx-respawn');
  if (respawnBtn) respawnBtn.onclick = respawn;

  function attack() {
    if (dead || phoneOn) return;
    var now = performance.now();
    var w = weapons[weaponId];
    if (now - lastAttack < w.cooldown) return;
    lastAttack = now;
    bump();

    // simple forward ray / melee check
    var forward = new THREE.Vector3(-Math.sin(yaw), 0, -Math.cos(yaw));
    var origin = player.position.clone(); origin.y += 1.2;

    // hit players
    Object.keys(remote).forEach(function (id) {
      var g = remote[id];
      if (!g || g.userData.npc) return;
      var d = player.position.distanceTo(g.position);
      if (d > w.range) return;
      var to = g.position.clone().sub(player.position).normalize();
      if (to.dot(forward) < 0.55) return;
      // send damage
      fetch('/api/flex_world.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'hit', server: cfg.server, map: cfg.map,
          target_id: +id, damage: w.dmg, weapon: weaponId
        })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.killed) { toast('Убил ' + (d.target_name || id) + ' · +' + (d.xp || 10000) + ' XP'); questProgress('kill', 1); }
        else if (d && d.ok) toast('Попал · -' + w.dmg + ' HP');
      }).catch(function () {});
    });

    // hit NPCs (local only visual)
    npcs.forEach(function (n) {
      var d = player.position.distanceTo(n.position);
      if (d > w.range) return;
      var to = n.position.clone().sub(player.position).normalize();
      if (to.dot(forward) < 0.5) return;
      n.userData.hp = (n.userData.hp || 100) - w.dmg;
      if (n.userData.hp <= 0) {
        toast(n.userData.name + ' повержен');
        n.userData.hp = 100;
        n.position.x += (seeded(n.position.x) - 0.5) * 20;
        n.position.z += (seeded(n.position.z + 3) - 0.5) * 20;
      } else {
        toast(n.userData.name + ' · -' + w.dmg);
      }
    });
  }

  // ---------- Input ----------
  var velY = 0, yaw = 0, pitch = 0.3, keys = {}, stick = { dx: 0, dy: 0, jump: false };
  var animMode = null; // null | sit | dance | wave

  // --- Квесты (NPC) ---
  var quests = [
    { id: 'first_blood', title: 'Первая кровь', desc: 'Убей любого игрока 1 раз', need: 1, type: 'kill', xp: 3000 },
    { id: 'walker', title: 'Прогулка по району', desc: 'Пройди 80 метров по карте', need: 80, type: 'walk', xp: 2000 },
    { id: 'social', title: 'Светский разговор', desc: 'Поговори с 3 разными NPC', need: 3, type: 'npc', xp: 1500 },
    { id: 'phone_user', title: 'В сети', desc: 'Открой телефон 2 раза', need: 2, type: 'phone', xp: 1000 },
    { id: 'survivor', title: 'Выживший', desc: 'Останься в мире 3 минуты', need: 180, type: 'time', xp: 2500 }
  ];
  var questState = { active: null, progress: 0, walked: {}, walked: 0, npcTalked: {}, phoneOpens: 0, playSec: 0, kills: 0 };
  try {
    var qs = localStorage.getItem('fx_quest_' + (cfg.uid || '0'));
    if (qs) questState = Object.assign(questState, JSON.parse(qs));
  } catch (e) {}
  function saveQuest() {
    try { localStorage.setItem('fx_quest_' + (cfg.uid || '0'), JSON.stringify(questState)); } catch (e) {}
  }
  function startQuest(q) {
    questState.active = q.id;
    questState.progress = 0;
    saveQuest();
    toast('Квест: ' + q.title);
  }
  function questProgress(type, amount) {
    amount = amount || 1;
    if (type === 'walk') questState.walked += amount;
    if (type === 'kill') questState.kills += amount;
    if (type === 'phone') questState.phoneOpens += amount;
    if (type === 'time') questState.playSec += amount;
    var q = quests.filter(function (x) { return x.id === questState.active; })[0];
    if (!q) return;
    if (q.type === 'kill') questState.progress = questState.kills;
    if (q.type === 'walk') questState.progress = Math.floor(questState.walked);
    if (q.type === 'npc') questState.progress = Object.keys(questState.npcTalked).length;
    if (q.type === 'phone') questState.progress = questState.phoneOpens;
    if (q.type === 'time') questState.progress = questState.playSec;
    if (questState.progress >= q.need && !questState.done[q.id]) {
      questState.done[q.id] = 1;
      questState.active = null;
      saveQuest();
      toast('Квест выполнен: ' + q.title + ' +' + q.xp + ' XP');
      fetch('/api/flex_world.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'quest_xp', server: cfg.server, map: cfg.map, xp: q.xp, quest: q.id })
      }).catch(function () {});
    } else {
      saveQuest();
    }
  }

  // Mobile anim buttons
  (function () {
    var bar = document.getElementById('fx-anim-bar');
    if (!bar) return;
    if (isMobile) bar.classList.add('show');
    bar.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('touchstart', function (e) {
        e.preventDefault(); e.stopPropagation();
        var a = btn.getAttribute('data-anim') || '';
        animMode = a || null;
        toast(a ? ('Аним: ' + a) : 'Стоп аним');
        bump();
      }, { passive: false });
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var a = btn.getAttribute('data-anim') || '';
        animMode = a || null;
        toast(a ? ('Аним: ' + a) : 'Стоп аним');
        bump();
      });
    });
  })();
  var animT = 0;
  var lastInput = performance.now(), lastMove = performance.now(), kicked = false;
  var AFK_MS = (cfg.crown && !cfg.staff) ? (5 * 60 * 1000) : (1 * 60 * 1000);
  function bump() { lastInput = performance.now(); }

  document.addEventListener('keydown', function (e) {
    if (phoneOn && e.code !== 'KeyP' && e.code !== 'Escape') return;
    keys[e.code] = true; bump();
    if (e.code === 'KeyP' || e.code === 'Escape') {
      if (phoneOn) closePhone(); else openPhone();
    }
    if (e.code === 'KeyE') tryNpc();
    if (e.code === 'KeyG') { animMode = animMode === 'wave' ? null : 'wave'; toast(animMode ? 'Машет' : 'Стоп'); }
    if (e.code === 'KeyH') { animMode = animMode === 'sit' ? null : 'sit'; toast(animMode ? 'Сидит' : 'Встал'); }
    if (e.code === 'KeyJ') { animMode = animMode === 'dance' ? null : 'dance'; toast(animMode ? 'Танцует' : 'Стоп'); }
    if (e.code === 'KeyK') { animMode = animMode === 'clap' ? null : 'clap'; toast(animMode ? 'Хлопает' : 'Стоп'); }
    if (e.code === 'KeyL') { animMode = animMode === 'lay' ? null : 'lay'; toast(animMode ? 'Лежит' : 'Встал'); }
    if (e.code === 'KeyB') { if (typeof placeBlock === 'function') placeBlock(); }
    if (e.code === 'KeyN') { if (typeof removeBlock === 'function') removeBlock(); }
    if (e.code === 'Digit1') setWeapon(0);
    if (e.code === 'Digit2') setWeapon(1);
    if (e.code === 'Digit3') setWeapon(2);
  });
  document.addEventListener('keyup', function (e) { keys[e.code] = false; });

  if (!isMobile) {
    canvas.addEventListener('click', function () {
      if (phoneOn) return;
      canvas.requestPointerLock && canvas.requestPointerLock();
    });
    document.addEventListener('mousemove', function (e) {
      if (document.pointerLockElement !== canvas || phoneOn) return;
      yaw -= e.movementX * 0.0025;
      pitch = Math.max(-1.1, Math.min(1.1, pitch - e.movementY * 0.0025));
      bump();
    });
    document.addEventListener('mousedown', function (e) {
      if (e.button === 0 && !phoneOn && document.pointerLockElement === canvas) attack();
    });
  } else {
    var hud = document.createElement('div');
    hud.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:10';
    hud.innerHTML =
      '<div id="fx-st" style="position:absolute;left:16px;bottom:20px;width:110px;height:110px;border-radius:50%;background:rgba(255,255,255,.12);border:2px solid rgba(255,255,255,.25);pointer-events:auto;touch-action:none"><div id="fx-kn" style="position:absolute;left:35px;top:35px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.45)"></div></div>' +
      '<button id="fx-jp" type="button" style="position:absolute;right:16px;bottom:24px;width:68px;height:68px;border:0;border-radius:50%;background:#02b757;color:#fff;font-weight:800;pointer-events:auto">JUMP</button>' +
      '<button id="fx-ph" type="button" style="position:absolute;right:16px;bottom:100px;width:52px;height:52px;border:0;border-radius:50%;background:#00a2ff;color:#fff;pointer-events:auto">📱</button>' +
      '<button id="fx-atk" type="button" style="position:absolute;right:16px;bottom:160px;width:52px;height:52px;border:0;border-radius:50%;background:#e74c3c;color:#fff;font-weight:800;pointer-events:auto">⚔</button>' +
      '<button id="fx-npc" type="button" style="position:absolute;right:16px;bottom:220px;width:52px;height:52px;border:0;border-radius:50%;background:#f59e0b;color:#fff;font-weight:800;pointer-events:auto">E</button>' +
      '<button id="fx-anim" type="button" style="position:absolute;right:16px;bottom:280px;width:52px;height:52px;border:0;border-radius:50%;background:#a855f7;color:#fff;font-weight:800;pointer-events:auto;font-size:11px">ANIM</button>';
    document.getElementById('fxw-root').appendChild(hud);
    var st = document.getElementById('fx-st'), kn = document.getElementById('fx-kn'), sid = null;
    function apply(cx, cy) {
      var r = st.getBoundingClientRect(), dx = cx-(r.left+r.width/2), dy = cy-(r.top+r.height/2), len = Math.sqrt(dx*dx+dy*dy)||1;
      if (len > 38) { dx*=38/len; dy*=38/len; }
      kn.style.left = (35+dx)+'px'; kn.style.top = (35+dy)+'px'; stick.dx = dx/38; stick.dy = dy/38; bump();
    }
    st.addEventListener('touchstart', function (e) { e.preventDefault(); sid = e.changedTouches[0].identifier; apply(e.changedTouches[0].clientX, e.changedTouches[0].clientY); }, { passive: false });
    st.addEventListener('touchmove', function (e) { e.preventDefault(); for (var i=0;i<e.changedTouches.length;i++) if (e.changedTouches[i].identifier===sid) apply(e.changedTouches[i].clientX, e.changedTouches[i].clientY); }, { passive: false });
    st.addEventListener('touchend', function () { stick.dx=0; stick.dy=0; kn.style.left='35px'; kn.style.top='35px'; sid=null; });
    document.getElementById('fx-jp').ontouchstart = function (e) { e.preventDefault(); stick.jump = true; bump(); };
    document.getElementById('fx-jp').ontouchend = function () { stick.jump = false; };
    document.getElementById('fx-ph').ontouchstart = function (e) { e.preventDefault(); if (phoneOn) closePhone(); else openPhone(); bump(); };
    document.getElementById('fx-atk').ontouchstart = function (e) { e.preventDefault(); attack(); };
    document.getElementById('fx-npc').ontouchstart = function (e) { e.preventDefault(); tryNpc(); };
    var lookId = null, lx=0, ly=0;
    canvas.addEventListener('touchstart', function (e) {
      if (phoneOn) return;
      var t=e.changedTouches[0], r=canvas.getBoundingClientRect();
      if (t.clientX > r.left + r.width*0.45) { lookId=t.identifier; lx=t.clientX; ly=t.clientY; }
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) {
      if (phoneOn) return;
      for (var i=0;i<e.changedTouches.length;i++) {
        var t=e.changedTouches[i]; if (t.identifier!==lookId) continue;
        yaw -= (t.clientX-lx)*0.006; pitch = Math.max(-1.1, Math.min(1.1, pitch-(t.clientY-ly)*0.006));
        lx=t.clientX; ly=t.clientY; bump();
      }
    }, { passive: true });
    canvas.addEventListener('touchend', function (e) { for (var i=0;i<e.changedTouches.length;i++) if (e.changedTouches[i].identifier===lookId) lookId=null; }, { passive: true });
  }


  var buildBlocks = [];
  function placeBlock() {
    if (phoneOn || (typeof dead !== 'undefined' && dead)) return;
    var fx = Math.round(player.position.x - Math.sin(yaw) * 2.2);
    var fz = Math.round(player.position.z - Math.cos(yaw) * 2.2);
    var fy = Math.max(0, Math.round(player.position.y));
    if (buildBlocks.length > 150) { toast('Лимит'); return; }
    var mesh = addBox(1, 1, 1, 0x3b82f6, fx, fy + 0.5, fz, true);
    buildBlocks.push({ x: fx, y: fy, z: fz, mesh: mesh });
    bump();
    fetch('/api/flex_world.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'build_add', server:cfg.server, map:cfg.map, x:fx, y:fy, z:fz, color:3947580 }) }).catch(function(){});
    toast('Блок +');
  }
  function removeBlock() {
    if (phoneOn) return;
    var fx = Math.round(player.position.x - Math.sin(yaw) * 2.2);
    var fz = Math.round(player.position.z - Math.cos(yaw) * 2.2);
    var fy = Math.max(0, Math.round(player.position.y));
    for (var i = buildBlocks.length - 1; i >= 0; i--) {
      var b = buildBlocks[i];
      if (b.x === fx && b.y === fy && b.z === fz) {
        if (b.mesh) scene.remove(b.mesh);
        buildBlocks.splice(i, 1);
        fetch('/api/flex_world.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ action:'build_del', server:cfg.server, map:cfg.map, x:fx, y:fy, z:fz }) }).catch(function(){});
        toast('Блок -'); bump(); return;
      }
    }
  }
  fetch('/api/flex_world.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'build_list', server:cfg.server, map:cfg.map }) })
    .then(function(r){return r.json();}).then(function(d){
      if (!d || !d.blocks) return;
      d.blocks.forEach(function(b){
        var mesh = addBox(1,1,1,(+b.color)||0x3b82f6,+b.x,(+b.y)+0.5,+b.z,true);
        buildBlocks.push({x:+b.x,y:+b.y,z:+b.z,mesh:mesh});
      });
    }).catch(function(){});

  function tryNpc() {
    var best = null, bd = 3.5;
    npcs.forEach(function (n) {
      var d = player.position.distanceTo(n.position);
      if (d < bd) { bd = d; best = n; }
    });
    var box = document.getElementById('fxw-npc-talk');
    if (!best || !box) return;
    box.style.display = 'block';
    box.innerHTML = '<b>' + best.userData.name + '</b><br>' + best.userData.line;
    clearTimeout(tryNpc._t);
    tryNpc._t = setTimeout(function () { box.style.display = 'none'; }, 9000);
    // квест
    var nname = (near.userData && near.userData.name) || 'NPC';
    questState.npcTalked[nname] = 1;
    questProgress('npc', 1);
    var avail = quests.filter(function (q) { return !questState.done[q.id]; })[0];
    if (avail && !questState.active) {
      startQuest(avail);
      if (txt) txt.textContent = (txt.textContent || '') + '\n\n📋 Квест «' + avail.title + '»: ' + avail.desc + ' (+' + avail.xp + ' XP)';
    } else if (questState.active) {
      var aq = quests.filter(function (q) { return q.id === questState.active; })[0];
      if (aq && txt) txt.textContent = (txt.textContent || '') + '\n\n📋 Сейчас: «' + aq.title + '» ' + Math.min(questState.progress, aq.need) + '/' + aq.need;
    }
  }

  function toast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:absolute;left:50%;top:16%;transform:translateX(-50%);background:rgba(0,0,0,.8);color:#fff;padding:8px 14px;border-radius:10px;z-index:40;font-weight:800;font-size:13px';
    document.getElementById('fxw-root').appendChild(t);
    setTimeout(function () { t.remove(); }, 2500);
  }

  function afkKick() {
    if (kicked) return; kicked = true;
    fetch('/api/flex_world.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'leave', server: cfg.server }) });
    var ov = document.createElement('div');
    ov.style.cssText = 'position:absolute;inset:0;z-index:50;background:rgba(0,0,0,.92);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:20px;text-align:center';
    ov.innerHTML = '<div style="font-size:22px;font-weight:900">AFK ' + ((cfg.crown && !cfg.staff) ? '5' : '1') + ' мин</div><a href="/flex/world.php" style="padding:10px 16px;background:#00a2ff;color:#fff;border-radius:10px;font-weight:800;text-decoration:none">К серверам</a><a href="/platforma/switch.php?mode=platforma&redirect=/" style="padding:10px 16px;background:#ff0000;color:#fff;border-radius:10px;font-weight:800;text-decoration:none">Платформа</a>';
    document.getElementById('fxw-root').appendChild(ov);
  }

  // ---------- Sync ----------
  function sync() {
    if (kicked || dead) return;
    if (performance.now() - lastMove > AFK_MS) { afkKick(); return; }
    fetch('/api/flex_world.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        server: cfg.server, map: cfg.map,
        x: player.position.x, y: player.position.y, z: player.position.z, yaw: yaw,
        afk: (performance.now()-lastInput)>60000?1:0,
        phone: phoneOn?1:0,
        activity: phoneOn ? ('телефон: '+(phoneTitle||'контент')) : 'гуляет',
        phone_url: phoneOn ? phoneUrl : '',
        phone_title: phoneOn ? phoneTitle : '',
        weapon: weaponId,
        hp: hp
      })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) { if (data && data.error === 'full') afkKick(); return; }
      var el = document.getElementById('fxw-online'); if (el) el.textContent = (data.online||1)+'/10';
      if (data.xp) toast('+'+data.xp+' XP');
      if (data.payday) toast('Зарплата +'+data.payday+' XP');
      if (data.daily_xp) toast('Ежедневный бонус +'+data.daily_xp+' XP');

      // incoming damage / kill
      if (data.damage_taken && data.damage_taken > 0) {
        hp = Math.max(0, hp - data.damage_taken);
        updateHpBar();
        toast('Вас ранил ' + (data.attacker_name || 'кто-то') + ' · -' + data.damage_taken);
        if (hp <= 0) showDeath(data.attacker_name);
      }
      if (data.you_died) showDeath(data.attacker_name);

      var seen = {};
      (data.players||[]).forEach(function (pl) {
        var id = String(pl.user_id); seen[id]=1;
        if (!remote[id]) {
          remote[id] = makeR6({
            head_color: pl.head_color, torso_color: pl.torso_color,
            merch_url: pl.merch_url, hat: pl.hat, crown: !!+pl.has_crown
          }, pl.username, { crown: !!+pl.has_crown, role: pl.role || 'user', phone: !!+pl.phone_on });
          scene.add(remote[id]);
        }
        var g = remote[id];
        g.userData.target = { x:+pl.x, y:+pl.y, z:+pl.z, yaw:+pl.yaw };
        g.userData.phone_url = pl.phone_url || '';
        g.userData.phone_title = pl.phone_title || pl.activity || '';
        g.userData.phone_on = !!+pl.phone_on;
        if (g.userData.phone) {
          g.userData.phone.visible = !!+pl.phone_on;
          // paint what they watch on the phone screen so others see it
          if (g.userData.phone_on && g.userData.phone.userData && g.userData.phone.userData.screenCtx) {
            var ctx = g.userData.phone.userData.screenCtx;
            var cv = g.userData.phone.userData.screenCanvas;
            var title = (pl.phone_title || pl.activity || 'контент').slice(0, 16);
            ctx.fillStyle = '#0f172a'; ctx.fillRect(0,0,128,192);
            ctx.fillStyle = '#00a2ff'; ctx.font = 'bold 12px sans-serif'; ctx.textAlign = 'center';
            ctx.fillText('StreamLive', 64, 22);
            ctx.fillStyle = '#e2e8f0'; ctx.font = 'bold 11px sans-serif';
            ctx.fillText(title, 64, 88);
            ctx.fillStyle = '#64748b'; ctx.font = '9px sans-serif';
            ctx.fillText('смотрит', 64, 108);
            g.userData.phone.userData.screenTex.needsUpdate = true;
          }
        }
        if (g.userData.armL) g.userData.armL.rotation.x = g.userData.phone_on ? -0.9 : 0;
        if (g.userData.armR) g.userData.armR.rotation.x = g.userData.phone_on ? -1.1 : 0;
        var wid = +(pl.weapon || 0);
        if (g.userData.bat) g.userData.bat.visible = wid === 1;
        if (g.userData.gun) g.userData.gun.visible = wid === 2;
      });
      Object.keys(remote).forEach(function (id) {
        if (!seen[id]) { scene.remove(remote[id]); delete remote[id]; }
      });
    }).catch(function () {});
  }

  setTimeout(sync, 1200);
  setInterval(sync, 10000);

  setInterval(function () {
    var el = document.getElementById('fxw-clock');
    if (!el) return;
    var d = new Date();
    el.textContent = d.toLocaleDateString('ru-RU') + ' · ' + d.toLocaleTimeString('ru-RU');
  }, 1000);

  window.addEventListener('beforeunload', function () {
    navigator.sendBeacon && navigator.sendBeacon('/api/flex_world.php', JSON.stringify({ action:'leave', server: cfg.server }));
  });

  // ---------- Animate ----------
  var clock = new THREE.Clock();
  function animate() {
    requestAnimationFrame(animate);
    if (kicked) return;
    var dt = Math.min(clock.getDelta(), 0.05);

    if (!dead && !phoneOn) {
      var speed = (isMobile ? 7 : 8.5) * dt;
      var forward = new THREE.Vector3(-Math.sin(yaw), 0, -Math.cos(yaw));
      var right = new THREE.Vector3(Math.cos(yaw), 0, -Math.sin(yaw));
      var move = new THREE.Vector3();
      if (keys['KeyW']||keys['ArrowUp']) move.add(forward);
      if (keys['KeyS']||keys['ArrowDown']) move.sub(forward);
      if (keys['KeyA']||keys['ArrowLeft']) move.sub(right);
      if (keys['KeyD']||keys['ArrowRight']) move.add(right);
      if (Math.abs(stick.dx)>0.1||Math.abs(stick.dy)>0.1) {
        move.add(forward.clone().multiplyScalar(-stick.dy));
        move.add(right.clone().multiplyScalar(stick.dx));
      }
      if (move.lengthSq() > 0.0001) {
        move.normalize().multiplyScalar(speed);
        var nx = player.position.x+move.x, nz = player.position.z+move.z;
        if (!collides(nx, player.position.y, player.position.z)) player.position.x = nx;
        if (!collides(player.position.x, player.position.y, nz)) player.position.z = nz;
        lastMove = performance.now(); bump();
      }
      velY -= 22 * dt;
      if ((keys['Space']||stick.jump) && player.position.y <= 1.08) velY = 8.5;
      player.position.y = Math.max(1, player.position.y + velY * dt);
      if (player.position.y <= 1.01) velY = 0;
      player.position.x = Math.max(-65, Math.min(65, player.position.x));
      player.position.z = Math.max(-65, Math.min(65, player.position.z));
      player.rotation.y = yaw;
    if (typeof questProgress === 'function') {
      var spd = Math.sqrt((keys['KeyW']||keys['KeyS']||keys['KeyA']||keys['KeyD']||stick.dx||stick.dy) ? 1 : 0);
      if (spd) questProgress('walk', dt * 4);
      questProgress('time', dt);
    }
      // animations
      animT += dt;
      if (player.userData.armL && player.userData.armR && !phoneOn) {
        if (animMode === 'wave') {
          player.userData.armR.rotation.x = -1.6 + Math.sin(animT * 8) * 0.5;
          player.userData.armL.rotation.x = 0;
          player.position.y = 1;
        } else if (animMode === 'sit') {
          player.position.y = 0.55;
          player.userData.armL.rotation.x = -0.4;
          player.userData.armR.rotation.x = -0.4;
        } else if (animMode === 'dance') {
          player.position.y = 1 + Math.abs(Math.sin(animT * 6)) * 0.25;
          player.userData.armL.rotation.x = -1.2 + Math.sin(animT * 7) * 0.6;
          player.userData.armR.rotation.x = -1.2 + Math.cos(animT * 7) * 0.6;
          player.rotation.y = yaw + Math.sin(animT * 3) * 0.3;
        } else if (!phoneOn) {
          player.userData.armL.rotation.x = 0;
          player.userData.armR.rotation.x = 0;
          if (player.position.y < 1 && velY === 0 && player.position.y > 0.9) player.position.y = 1;
        }
      }
    }

    Object.keys(remote).forEach(function (id) {
      var g = remote[id], tg = g.userData.target; if (!tg) return;
      g.position.x += (tg.x-g.position.x)*Math.min(1,10*dt);
      g.position.y += (tg.y-g.position.y)*Math.min(1,10*dt);
      g.position.z += (tg.z-g.position.z)*Math.min(1,10*dt);
      g.rotation.y = tg.yaw;
    });

    // peek phone of nearest player
    var peek = document.getElementById('fxw-phone-peek');
    var peekFrame = document.getElementById('fxw-peek-frame');
    var peekTitle = document.getElementById('fxw-peek-title');
    var near = null, nd = 2.8;
    Object.keys(remote).forEach(function (id) {
      var g = remote[id];
      var d = player.position.distanceTo(g.position);
      if (d < nd && g.userData.phone_on) { nd = d; near = g; }
    });
    if (near && peek && near.userData.phone_url) {
      peek.style.display = 'block';
      if (peekTitle) peekTitle.textContent = (near.userData.phone_title || 'Телефон').slice(0, 40);
      if (peekFrame && peekFrame.dataset.url !== near.userData.phone_url) {
        peekFrame.dataset.url = near.userData.phone_url;
        peekFrame.src = near.userData.phone_url;
      }
    } else if (peek) {
      peek.style.display = 'none';
      if (peekFrame) { peekFrame.dataset.url = ''; peekFrame.src = 'about:blank'; }
    }

    var dist = isMobile ? 5.5 : 6.5;
    camera.position.set(
      player.position.x + Math.sin(yaw)*dist*Math.cos(pitch),
      player.position.y + 2 + Math.sin(pitch)*3,
      player.position.z + Math.cos(yaw)*dist*Math.cos(pitch)
    );
    camera.lookAt(player.position.x, player.position.y+1.1, player.position.z);
    renderer.render(scene, camera);
  }
  animate();
  window.addEventListener('resize', function () {
    var ww=host.clientWidth, hh=host.clientHeight||480;
    camera.aspect=ww/hh; camera.updateProjectionMatrix(); renderer.setSize(ww,hh);
  });
})();
