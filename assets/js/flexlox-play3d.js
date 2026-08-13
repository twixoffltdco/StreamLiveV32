(function () {
  var host = document.getElementById('flx-canvas3d');
  if (!host) return;
  if (typeof THREE === 'undefined') {
    host.innerHTML = '<p style="padding:20px;color:#aaa">Three.js не загрузился.</p>';
    return;
  }

  var data = window.__FLX_PLAY__ || { mode: 'open', template: 'baseplate' };
  if (typeof data === 'string') {
    try { data = JSON.parse(data); } catch (e) { data = { mode: 'open' }; }
  }
  var av = window.__FLX_AVATAR__ || {};
  var gameMode = data.game_mode || data.mode || 'explore';
  if (gameMode === 'open') gameMode = 'explore';
  var isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) ||
    (window.matchMedia && matchMedia('(pointer: coarse)').matches);

  var scene = new THREE.Scene();
  scene.background = new THREE.Color(data.sky || 0x7ec8f0);
  scene.fog = new THREE.Fog(scene.background, 60, 140);

  var w = host.clientWidth || 800;
  var h = Math.max(host.clientHeight || 0, isMobile ? 420 : 520);
  host.style.position = 'relative';
  host.style.height = h + 'px';

  var camera = new THREE.PerspectiveCamera(70, w / h, 0.1, 250);
  var renderer = new THREE.WebGLRenderer({ antialias: !isMobile });
  renderer.setSize(w, h);
  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, isMobile ? 1.75 : 2));
  host.innerHTML = '';
  host.appendChild(renderer.domElement);
  var canvas = renderer.domElement;
  canvas.style.cssText = 'display:block;width:100%;height:100%;touch-action:none';

  scene.add(new THREE.AmbientLight(0xffffff, 0.6));
  var sun = new THREE.DirectionalLight(0xffffff, 1.05);
  sun.position.set(40, 60, 20);
  scene.add(sun);

  var obstacles = [];
  var solid = {};
  function k(x, y, z) { return x + ',' + y + ',' + z; }

  function addBox(sx, sy, sz, color, x, y, z, block) {
    var m = new THREE.Mesh(
      new THREE.BoxGeometry(sx, sy, sz),
      new THREE.MeshLambertMaterial({ color: color })
    );
    m.position.set(x, y, z);
    scene.add(m);
    if (block !== false) {
      obstacles.push({
        min: [x - sx / 2, y - sy / 2, z - sz / 2],
        max: [x + sx / 2, y + sy / 2, z + sz / 2]
      });
    }
    return m;
  }

  var base = new THREE.Mesh(
    new THREE.BoxGeometry(140, 1, 140),
    new THREE.MeshLambertMaterial({ color: 0x5a5a5a })
  );
  base.position.set(0, -0.5, 0);
  scene.add(base);
  addBox(16, 0.12, 16, 0x3d3d3d, 0, 0.06, 0, false);

  var template = data.template || 'baseplate';
  var hasCustomBuild = (data.build_blocks && data.build_blocks.length > 0);
  if (template === 'obby' || gameMode === 'obby') {
    for (var i = 0; i < 12; i++) {
      addBox(2.4, 0.35, 2.4, i % 2 ? 0x22c55e : 0x00a2ff, 6 + i * 2.8, 0.5 + i * 0.85, -8 + (i % 3), true);
    }
    addBox(4, 0.4, 4, 0xf59e0b, 42, 11, -6, true);
  } else if (template === 'city' || template === 'vice_city') {
    scene.background = new THREE.Color(template === 'vice_city' ? 0x87ceeb : 0x6ec1e4);
    var cols = template === 'vice_city'
      ? [0xf9a8d4, 0x67e8f9, 0xfde68a, 0xc4b5fd, 0x86efac]
      : [0x475569, 0x64748b, 0x334155, 0x1e293b, 0x78716c];
    for (var cx = -40; cx <= 40; cx += 8) {
      for (var cz = -40; cz <= 40; cz += 8) {
        if (Math.abs(cx) < 6 && Math.abs(cz) < 6) continue;
        var ch = 5 + Math.abs((cx * 5 + cz * 3) % 14);
        addBox(5.5, ch, 5.5, cols[Math.abs(cx + cz * 2) % cols.length], cx, ch / 2, cz, true);
      }
    }
    addBox(100, 0.12, 8, 0x1f2937, 0, 0.06, 0, false);
    addBox(8, 0.12, 100, 0x1f2937, 0, 0.06, 0, false);
    addBox(100, 0.12, 8, 0x1f2937, 0, 0.06, 20, false);
    addBox(100, 0.12, 8, 0x1f2937, 0, 0.06, -20, false);
    addBox(100, 0.12, 8, 0x1f2937, 0, 0.06, 36, false);
    addBox(100, 0.12, 8, 0x1f2937, 0, 0.06, -36, false);
    if (template === 'vice_city') addBox(120, 0.08, 22, 0x0ea5e9, 0, 0.02, 52, false);
  } else if (template === 'town') {
    for (var tx = -20; tx <= 20; tx += 10) {
      for (var tz = -20; tz <= 20; tz += 10) {
        if (Math.abs(tx) < 6 && Math.abs(tz) < 6) continue;
        var hh = 3 + Math.abs((tx * 3 + tz * 7) % 5);
        addBox(4, hh, 4, 0x64748b, tx, hh / 2, tz, true);
      }
    }
    addBox(24, 0.15, 4, 0x374151, 0, 0.08, 0, false);
    addBox(4, 0.15, 24, 0x374151, 0, 0.08, 0, false);
  } else if (template === 'arena' || gameMode === 'shooter') {
    addBox(40, 1.2, 1, 0x475569, 0, 0.6, -20, true);
    addBox(40, 1.2, 1, 0x475569, 0, 0.6, 20, true);
    addBox(1, 1.2, 40, 0x475569, -20, 0.6, 0, true);
    addBox(1, 1.2, 40, 0x475569, 20, 0.6, 0, true);
    addBox(3, 1.5, 3, 0x334155, 8, 0.75, 8, true);
    addBox(3, 1.5, 3, 0x334155, -8, 0.75, -8, true);
    addBox(2, 1.2, 6, 0x1e293b, 0, 0.6, 0, true);
  } else if (!hasCustomBuild) {
    addBox(2, 6, 2, 0x00a2ff, 12, 3, 8, true);
    addBox(2, 4, 2, 0x02b757, -10, 2, 12, true);
    addBox(3, 1.5, 3, 0xf59e0b, 8, 0.75, -10, true);
    addBox(1.5, 8, 1.5, 0xa855f7, -14, 4, -6, true);
    for (var j = 0; j < 6; j++) {
      addBox(2.2, 0.4, 2.2, 0x22c55e, 18 + j * 2.5, 0.4 + j * 0.7, -5, true);
    }
  }

  // Custom props from studio (PNG billboards / boxes)
  var props = data.props || [];
  var vehicles = [];
  (data.build_blocks || []).forEach(function (b) {
    var g = 2;
    var sx = (+b.w || 1) * g, sy = (+b.h || 1) * g, sz = (+b.d || 1) * g;
    var col = b.color || '#64748b';
    if (typeof col === 'string' && col.charAt(0) === '#') col = parseInt(col.slice(1), 16);
    var wx = (+b.x || 0) * g + sx / 2 - g / 2;
    var wy = (+b.y || 0) * g + sy / 2;
    var wz = (+b.z || 0) * g + sz / 2 - g / 2;
    addBox(sx, sy, sz, col, wx, wy, wz, true);
  });
  (data.buildings || []).forEach(function (b) {
    var col = b.color || '#64748b';
    if (typeof col === 'string' && col.charAt(0) === '#') col = parseInt(col.slice(1), 16);
    addBox(+b.w || 4, +b.h || 6, +b.d || 4, col, +b.x || 0, (+b.h || 6) / 2, +b.z || 0, true);
  });
  
  var inVehicle = null;
  function spawnCarAtPlayer(pngUrl) {
    var px = player.position.x - Math.sin(yaw) * 6;
    var pz = player.position.z - Math.cos(yaw) * 6;
    var body = new THREE.Group();
    // body
    var chassis = new THREE.Mesh(
      new THREE.BoxGeometry(3.6, 0.7, 1.8),
      new THREE.MeshLambertMaterial({ color: 0xdc2626 })
    );
    chassis.position.y = 0.55;
    body.add(chassis);
    var cabin = new THREE.Mesh(
      new THREE.BoxGeometry(1.5, 0.75, 1.6),
      new THREE.MeshLambertMaterial({ color: 0x0f172a })
    );
    cabin.position.set(-0.35, 1.15, 0);
    body.add(cabin);
    var hood = new THREE.Mesh(
      new THREE.BoxGeometry(1.1, 0.25, 1.7),
      new THREE.MeshLambertMaterial({ color: 0xb91c1c })
    );
    hood.position.set(1.1, 0.85, 0);
    body.add(hood);
    var wheels = [];
    [[1.15, 0.85], [1.15, -0.85], [-1.15, 0.85], [-1.15, -0.85]].forEach(function (wz) {
      var wh = new THREE.Mesh(
        new THREE.CylinderGeometry(0.38, 0.38, 0.28, 12),
        new THREE.MeshLambertMaterial({ color: 0x111111 })
      );
      wh.rotation.z = Math.PI / 2;
      wh.position.set(wz[0], 0.38, wz[1]);
      body.add(wh);
      wheels.push(wh);
    });
    body.position.set(px, 0, pz);
    body.rotation.y = yaw;
    body.userData.kind = 'vehicle';
    body.userData.speed = 0;
    body.userData.wheels = wheels;
    body.userData.hasPng = false;
    scene.add(body);
    vehicles.push(body);
    if (pngUrl && /^https?:\/\//i.test(String(pngUrl))) {
      try {
        var ld = new THREE.TextureLoader();
        ld.crossOrigin = 'anonymous';
        ld.load(String(pngUrl), function (tex) {
          tex.colorSpace = THREE.SRGBColorSpace || tex.encoding;
          // side sprites (left/right) so car readable from angle
          [-1, 1].forEach(function (side) {
            var plane = new THREE.Mesh(
              new THREE.PlaneGeometry(3.6, 1.6),
              new THREE.MeshBasicMaterial({ map: tex, transparent: true, side: THREE.DoubleSide, depthWrite: false })
            );
            plane.position.set(0, 1.0, side * 0.95);
            plane.rotation.y = side > 0 ? 0 : Math.PI;
            body.add(plane);
          });
          // hide blocky cabin a bit when png ok
          chassis.visible = true;
          body.userData.hasPng = true;
        }, undefined, function () {});
      } catch (e) {}
    }
    return body;
  }

  props.forEach(function (pr) {
    var x = +pr.x || 0, y = +pr.y || 1, z = +pr.z || 0;
    if (pr.png) {
      try {
        var ld = new THREE.TextureLoader();
        ld.crossOrigin = 'anonymous';
        ld.load(pr.png, function (tex) {
          var plane = new THREE.Mesh(
            new THREE.PlaneGeometry(pr.w || 2, pr.h || 2),
            new THREE.MeshBasicMaterial({ map: tex, transparent: true, side: THREE.DoubleSide, depthWrite: false })
          );
          plane.position.set(x, y, z);
          plane.userData.kind = pr.kind || 'decor';
          plane.userData.baseY = y;
          scene.add(plane);
          if ((pr.kind || '') === 'vehicle') {
            vehicles.push(plane);
          }
        });
      } catch (e) {}
    } else {
      addBox(pr.w || 2, pr.h || 2, pr.d || 2, pr.color || 0x888888, x, y, z, pr.solid !== false);
    }
  });

  
  function setPlayerBadges(g, opts) {
    opts = opts || {};
    // remove old badges
    var remove = [];
    g.children.forEach(function(ch){ if (ch.userData && ch.userData.badge) remove.push(ch); });
    remove.forEach(function(ch){ g.remove(ch); });
    var label = String(opts.name || 'player').slice(0, 16);
    if (opts.afk) label = '[AFK] ' + label;
    if (opts.guest) label = label + ' (guest)';
    var c2 = document.createElement('canvas');
    c2.width = 320; c2.height = 64;
    var ctx = c2.getContext('2d');
    ctx.fillStyle = opts.afk ? 'rgba(80,80,80,0.75)' : 'rgba(0,0,0,0.55)';
    ctx.fillRect(0, 0, 320, 64);
    ctx.fillStyle = '#fff';
    ctx.font = 'bold 26px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(label, 160, 40);
    var spr = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(c2), transparent: true }));
    spr.scale.set(2.6, 0.55, 1);
    spr.position.set(0, 2.65, 0);
    spr.userData.badge = 1;
    g.add(spr);
    if (opts.crown) {
      var crown = new THREE.Mesh(
        new THREE.ConeGeometry(0.28, 0.35, 5),
        new THREE.MeshLambertMaterial({ color: 0xfbbf24 })
      );
      crown.position.set(0, 2.35, 0);
      crown.userData.badge = 1;
      g.add(crown);
      // small jewels
      for (var i = 0; i < 3; i++) {
        var j = new THREE.Mesh(new THREE.SphereGeometry(0.06, 6, 6), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
        j.position.set((i - 1) * 0.12, 2.48, 0.12);
        j.userData.badge = 1;
        g.add(j);
      }
    }
  }

  function makeR6(avt, name) {
    var g = new THREE.Group();
    function p(sx, sy, sz, c, x, y, z) {
      var m = new THREE.Mesh(
        new THREE.BoxGeometry(sx, sy, sz),
        new THREE.MeshLambertMaterial({ color: new THREE.Color(c || '#00a2ff') })
      );
      m.position.set(x, y, z);
      return m;
    }
    g.add(p(0.8, 0.8, 0.8, avt.head || avt.head_color || '#f5d0c5', 0, 1.7, 0));
    g.add(p(1.0, 1.2, 0.5, avt.torso || avt.torso_color || '#00a2ff', 0, 0.9, 0));
    g.add(p(0.35, 1.1, 0.35, avt.arms || avt.arms_color || '#f5d0c5', -0.7, 0.9, 0));
    g.add(p(0.35, 1.1, 0.35, avt.arms || avt.arms_color || '#f5d0c5', 0.7, 0.9, 0));
    g.add(p(0.4, 1.0, 0.4, avt.legs || avt.legs_color || '#1e3a5f', -0.3, 0.1, 0));
    g.add(p(0.4, 1.0, 0.4, avt.legs || avt.legs_color || '#1e3a5f', 0.3, 0.1, 0));
    if (avt.hat && avt.hat !== 'none') g.add(p(0.95, 0.28, 0.95, '#222', 0, 2.22, 0));
    var merch = avt.merch_url || avt.merch || '';
    if (merch && /^https?:\/\//i.test(merch)) {
      try {
        var ld = new THREE.TextureLoader();
        ld.crossOrigin = 'anonymous';
        ld.load(merch, function (tex) {
          var dec = new THREE.Mesh(
            new THREE.PlaneGeometry(0.7, 0.7),
            new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false })
          );
          dec.position.set(0, 0.95, 0.28);
          g.add(dec);
        });
      } catch (e) {}
    }
    if (name) {
      var c2 = document.createElement('canvas');
      c2.width = 256; c2.height = 64;
      var ctx = c2.getContext('2d');
      ctx.fillStyle = 'rgba(0,0,0,0.55)';
      ctx.fillRect(0, 0, 256, 64);
      ctx.fillStyle = '#fff';
      ctx.font = 'bold 26px sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(String(name).slice(0, 18), 128, 40);
      var spr = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(c2), transparent: true }));
      spr.scale.set(2.4, 0.6, 1);
      spr.position.set(0, 2.65, 0);
      g.add(spr);
    }
    g.userData.parts = { legL: g.children[4], legR: g.children[5], armL: g.children[2], armR: g.children[3] };
    return g;
  }

  // NPCs — full characters, custom dialog from map
  var interactables = [];
  var npcList = data.npcs || [];
  if (!npcList.length) {
    npcList = [{
      name: 'Вова Артемий',
      x: 4, z: 4,
      head: '#f5d0c5', torso: '#22c55e', arms: '#f5d0c5', legs: '#1e3a5f', hat: 'cap',
      lines: ['Привет! Эта игра создана на Flexlox.', 'Меня зовут Вова Артемий.', 'Жми E, чтобы говорить со мной.']
    }];
  }
  npcList.forEach(function (n) {
    var g = makeR6(n, n.name || 'NPC');
    g.position.set(+n.x || 4, 1, +n.z || 4);
    scene.add(g);
    interactables.push({
      pos: g.position.clone(),
      label: n.name || 'NPC',
      lines: n.lines || ['Привет!'],
      mesh: g
    });
  });

  function collides(px, py, pz) {
    var rad = 0.4, hgt = 1.7;
    for (var i = 0; i < obstacles.length; i++) {
      var o = obstacles[i];
      if (px + rad > o.min[0] && px - rad < o.max[0] &&
          py + hgt > o.min[1] + 0.05 && py + 0.1 < o.max[1] &&
          pz + rad > o.min[2] && pz - rad < o.max[2]) return true;
    }
    return false;
  }

  // Local player — merch ALWAYS on self
  var player = makeR6({
    head: av.head || av.head_color,
    torso: av.torso || av.torso_color,
    arms: av.arms || av.arms_color,
    legs: av.legs || av.legs_color,
    hat: av.hat,
    merch_url: av.merch_url || av.merch || ''
  }, null);
  player.position.set(0, 1, 0);
  scene.add(player);
  if (gameMode === 'drive' || data.game_mode === 'drive') {
    setTimeout(function(){ if (typeof spawnCarAtPlayer === 'function') spawnCarAtPlayer(data.default_car_png || ''); }, 500);
  }
  if (window.__FLX_CROWN__) setPlayerBadges(player, { name: '', crown: true, afk: false, guest: false });

  // Weapon sprite (shooter)
  var weaponUrl = data.weapon_png || av.weapon_png || '';
  var weaponMesh = null;
  if (gameMode === 'shooter' || weaponUrl || (data.weapon_type === 'gun' || data.weapon_type === 'melee')) {
    var wUrl = weaponUrl || '';
    if (wUrl && /^https?:\/\//i.test(wUrl)) {
      try {
        var wld = new THREE.TextureLoader();
        wld.crossOrigin = 'anonymous';
        wld.load(wUrl, function (tex) {
          weaponMesh = new THREE.Mesh(
            new THREE.PlaneGeometry(0.9, 0.45),
            new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false, side: THREE.DoubleSide })
          );
          weaponMesh.position.set(0.55, 1.0, -0.4);
          player.add(weaponMesh);
        });
      } catch (e) {}
    } else {
      weaponMesh = new THREE.Mesh(
        new THREE.BoxGeometry(0.15, 0.15, 0.7),
        new THREE.MeshLambertMaterial({ color: 0x222222 })
      );
      weaponMesh.position.set(0.55, 1.0, -0.35);
      player.add(weaponMesh);
    }
  }

  var projectiles = [];
  function shoot() {
    var wt = (typeof weaponType !== 'undefined') ? weaponType : (data.weapon_type || (gameMode === 'shooter' ? 'gun' : 'none'));
    if (wt !== 'gun' && gameMode !== 'shooter') return;
    var dir = new THREE.Vector3(-Math.sin(yaw), -pitch * 0.3, -Math.cos(yaw)).normalize();
    var ball = new THREE.Mesh(
      new THREE.SphereGeometry(0.12, 8, 8),
      new THREE.MeshBasicMaterial({ color: 0xfbbf24 })
    );
    ball.position.copy(player.position).add(new THREE.Vector3(0, 1.2, 0)).add(dir.clone().multiplyScalar(1.2));
    scene.add(ball);
    projectiles.push({ mesh: ball, vel: dir.multiplyScalar(28), life: 2 });
  }

  var velY = 0, yaw = 0, pitch = 0.28, walkPhase = 0;
  var keys = {};
  var stick = { dx: 0, dy: 0, jump: false, active: false };
  var lookId = null, lookX = 0, lookY = 0;
  var dialogOpen = false;
  var lastInputAt = performance.now();
  var lastMoveAt = performance.now();
  var lastPos = { x: 0, y: 1, z: 0 };
  var amAfk = false;
  var AFK_KICK_MS = 10 * 60 * 1000; // 10 min
  var kickedAfk = false;
  function doAfkKick() {
    if (kickedAfk) return;
    kickedAfk = true;
    window.__FLX_MP__ = false;
    var ov = document.createElement('div');
    ov.style.cssText = 'position:absolute;inset:0;z-index:100;background:rgba(0,0,0,.88);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:20px';
    ov.innerHTML = '<div style="font-size:22px;font-weight:900">Кик за AFK</div><div style="color:#aaa;max-width:320px">Вас кикнули, потому что вы АФК (нет движения 10 минут).</div><a href="/flexlox/" style="margin-top:8px;padding:10px 18px;background:#00a2ff;color:#fff;border-radius:10px;font-weight:800;text-decoration:none">В Flexlox</a>';
    host.appendChild(ov);
  }
  function bumpInput() { lastInputAt = performance.now(); amAfk = false; }
  ['keydown','mousemove','touchstart','pointerdown'].forEach(function(ev){
    window.addEventListener(ev, bumpInput, { passive: true });
  });

  document.addEventListener('keydown', function (e) {
    keys[e.code] = true;
    if (e.code === 'KeyE') tryInteract();
    if (e.code === 'KeyF') {
      if (portal && portal.map_id) {
        var dx = player.position.x - (+portal.x || 10), dz = player.position.z - (+portal.z || 10);
        if (Math.sqrt(dx*dx+dz*dz) < 3.5) { tryPortal(); }
        else toggleVehicle();
      } else toggleVehicle();
    }
    if (['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].indexOf(e.code) >= 0) e.preventDefault();
  });
  document.addEventListener('keyup', function (e) { keys[e.code] = false; });

  var dlg = document.createElement('div');
  dlg.id = 'flx-dialog';
  dlg.style.cssText = 'display:none;position:absolute;left:50%;bottom:16%;transform:translateX(-50%);z-index:20;max-width:92%;width:380px;background:rgba(20,20,24,.95);color:#fff;padding:14px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.15);font-size:14px';
  dlg.innerHTML = '<div id="flx-dlg-title" style="font-weight:800;margin-bottom:6px"></div><div id="flx-dlg-text" style="line-height:1.45;color:#ddd"></div><button type="button" id="flx-dlg-close" style="margin-top:10px;padding:8px 14px;border:none;border-radius:8px;background:#00a2ff;color:#fff;font-weight:700">OK</button>';
  host.appendChild(dlg);
  document.getElementById('flx-dlg-close').onclick = function () { dlg.style.display = 'none'; dialogOpen = false; };

  var promptEl = document.createElement('div');
  promptEl.style.cssText = 'display:none;position:absolute;left:50%;top:12%;transform:translateX(-50%);z-index:15;background:rgba(0,0,0,.55);color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700';
  host.appendChild(promptEl);

  var modeBadge = document.createElement('div');
  modeBadge.style.cssText = 'position:absolute;left:10px;top:36px;z-index:8;background:rgba(0,0,0,.5);color:#fff;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700';
  var wt0 = data.weapon_type || (gameMode === 'shooter' ? 'gun' : 'none');
  modeBadge.textContent = 'Mode: ' + gameMode + (wt0 === 'gun' ? ' · клик стрельба' : (wt0 === 'melee' ? ' · клик удар' : ''));
  host.appendChild(modeBadge);

  // ——— Combat ———
  var myHp = 100;

  // Minigame timer
  var mgSec = parseInt(data.minigame_timer || 0, 10) || 0;
  if (mgSec > 0) {
    var mgEl = document.createElement('div');
    mgEl.id = 'flx-mg-timer';
    mgEl.style.cssText = 'position:absolute;top:48px;left:50%;transform:translateX(-50%);z-index:24;background:rgba(0,0,0,.55);color:#fff;padding:6px 14px;border-radius:8px;font-weight:800;font-size:14px';
    host.appendChild(mgEl);
    var mgLeft = mgSec;
    mgEl.textContent = '⏱ ' + mgLeft + 's';
    var mgIv = setInterval(function() {
      if (typeof kickedAfk !== 'undefined' && kickedAfk) { clearInterval(mgIv); return; }
      mgLeft--;
      mgEl.textContent = '⏱ ' + Math.max(0, mgLeft) + 's';
      if (mgLeft <= 0) {
        clearInterval(mgIv);
        showKillBanner('Время вышло', 'Мини-игра окончена');
      }
    }, 1000);
  }

  var weaponType = (data.weapon_type || (gameMode === 'shooter' ? 'gun' : 'none')); // none|gun|melee
  var weaponPng = data.weapon_png || '';
  var lastHitAt = 0;
  var hpBar = document.createElement('div');
  hpBar.style.cssText = 'position:absolute;left:50%;bottom:70px;transform:translateX(-50%);z-index:22;width:min(220px,60%);height:10px;background:rgba(0,0,0,.5);border-radius:6px;overflow:hidden;border:1px solid rgba(255,255,255,.15)';
  hpBar.innerHTML = '<i id="flx-hp-fill" style="display:block;height:100%;width:100%;background:#22c55e"></i>';
  host.appendChild(hpBar);
  function setHp(v) {
    myHp = Math.max(0, Math.min(100, v));
    var f = document.getElementById('flx-hp-fill');
    if (f) { f.style.width = myHp + '%'; f.style.background = myHp > 40 ? '#22c55e' : (myHp > 15 ? '#f59e0b' : '#ef4444'); }
    if (myHp <= 0) onDeath('world');
  }
  function showKillBanner(title, sub) {
    var old = document.getElementById('flx-kill-banner');
    if (old) old.remove();
    var ov = document.createElement('div');
    ov.id = 'flx-kill-banner';
    ov.style.cssText = 'position:absolute;inset:0;z-index:90;background:rgba(0,0,0,.82);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:24px';
    ov.innerHTML = '<div style="font-size:26px;font-weight:900">' + title + '</div><div style="color:#aaa">' + (sub || '') + '</div><button type="button" id="flx-respawn" style="margin-top:12px;padding:10px 18px;border:none;border-radius:10px;background:#00a2ff;color:#fff;font-weight:800;cursor:pointer">Respawn</button>';
    host.appendChild(ov);
    document.getElementById('flx-respawn').onclick = function() {
      ov.remove();
      myHp = 100; setHp(100);
      player.position.set(0, 1, 0);
      player.visible = true;
    };
  }
  function onDeath(by) {
    player.visible = false;
    showKillBanner('Вас убили', by && by !== 'world' ? ('Убийца: ' + by) : 'Вы проиграли');
  }
  function tryAttack() {
    if (weaponType === 'none') return;
    var now = performance.now();
    if (now - lastHitAt < (weaponType === 'melee' ? 450 : 220)) return;
    lastHitAt = now;
    if (weaponType === 'gun') {
      shoot();
      // local dummy damage if ray near NPC (demo)
      return;
    }
    // melee: damage nearest player/npc in range
    var best = null, bd = 2.8;
    interactables.forEach(function(n) {
      var d = player.position.distanceTo(n.pos);
      if (d < bd) { bd = d; best = n; }
    });
    if (best) {
      best.hp = (best.hp != null ? best.hp : 100) - 34;
      toast('удар: ' + (best.label || 'NPC') + ' (' + Math.max(0,best.hp) + ' HP)');
      if (best.mesh) {
        best.mesh.rotation.z = 0.2;
        setTimeout(function(){ if (best.mesh) best.mesh.rotation.z = 0; }, 120);
      }
      if (best.hp <= 0) {
        toast((best.label||'NPC') + ' down');
        if (best.mesh) best.mesh.visible = false;
        best.pos.set(9999, 0, 9999);
      }
    }
  }


  // Simple Roblox-like chat (local broadcast via MP payload optional later)
  var chatBar = document.createElement('div');
  chatBar.style.cssText = 'position:absolute;left:10px;bottom:10px;z-index:25;display:flex;gap:6px;max-width:70%';
  chatBar.innerHTML = '<input id="flx-chat-in" maxlength="80" placeholder="/" style="flex:1;min-width:140px;padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:rgba(0,0,0,.55);color:#fff;font-size:13px" /><button type="button" id="flx-chat-send" style="padding:8px 12px;border:none;border-radius:8px;background:#00a2ff;color:#fff;font-weight:800">Chat</button>';
  host.appendChild(chatBar);
  
  var creatorCommands = data.commands || [];
  function runChatCommand(text) {
    text = String(text || '').trim();
    if (!text) return false;
    if (text.charAt(0) !== '/') {
      sayBubble(text);
      return true;
    }
    var parts = text.slice(1).split(/\s+/);
    var cmd = (parts[0] || '').toLowerCase();
    // built-in
    if (cmd === 'help') {
      var list = ['/help', '/car', '/spawn'];
      creatorCommands.forEach(function(c){ if (c.cmd) list.push('/' + c.cmd); });
      toast(list.join(' '));
      return true;
    }
    if (cmd === 'car' || cmd === 'spawn') {
      var png = (parts[1] && /^https?:\/\//i.test(parts[1])) ? parts[1] : (data.default_car_png || '');
      creatorCommands.forEach(function(c){
        if ((c.cmd || '').toLowerCase() === 'car' && c.png) png = c.png;
      });
      if (typeof spawnCarAtPlayer === 'function') spawnCarAtPlayer(png);
      else toast('spawn broken');
      toast('машина');
      return true;
    }
    // creator-defined
    var found = null;
    creatorCommands.forEach(function(c){
      if ((c.cmd || '').toLowerCase() === cmd) found = c;
    });
    if (found) {
      if (found.action === 'spawn_vehicle' || found.action === 'car') {
        if (typeof spawnCarAtPlayer === 'function') spawnCarAtPlayer(found.png || data.default_car_png || '');
        else runChatCommand('/car ' + (found.png || ''));
      } else if (found.action === 'say' && found.text) {
        toast(found.text);
      } else if (found.action === 'tp' && found.x != null) {
        player.position.set(+found.x, +found.y || 1, +found.z || 0);
        toast('tp');
      } else {
        toast('/' + cmd);
      }
      return true;
    }
    toast('нет /' + cmd);
    return true;
  }

  
  function toast(msg) {
    msg = String(msg || '');
    if (!msg) return;
    var el = document.getElementById('flx-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'flx-toast';
      el.style.cssText = 'position:absolute;left:50%;top:20%;transform:translateX(-50%);z-index:40;background:rgba(0,0,0,.75);color:#fff;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:700;pointer-events:none;opacity:0;transition:opacity .15s';
      host.appendChild(el);
    }
    el.textContent = msg;
    el.style.opacity = '1';
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.style.opacity = '0'; }, 1600);
  }

  function sayBubble(text) {
    text = String(text || '').slice(0, 80);
    if (!text) return;
    var c = document.createElement('canvas');
    c.width = 320; c.height = 80;
    var ctx = c.getContext('2d');
    ctx.fillStyle = 'rgba(255,255,255,0.92)';
    ctx.fillRect(0, 0, 320, 80);
    ctx.fillStyle = '#111';
    ctx.font = '20px sans-serif';
    ctx.fillText(text.slice(0, 28), 12, 36);
    if (text.length > 28) ctx.fillText(text.slice(28, 56), 12, 60);
    var spr = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(c), transparent: true }));
    spr.scale.set(2.4, 0.6, 1);
    spr.position.set(0, 3.2, 0);
    player.add(spr);
    setTimeout(function(){ player.remove(spr); }, 4000);
  }
  document.getElementById('flx-chat-send').onclick = function(){
    var v = document.getElementById('flx-chat-in').value;
    document.getElementById('flx-chat-in').value = '';
    runChatCommand(v);
  };
  var chatIn = document.getElementById('flx-chat-in');
  if (chatIn) {
    chatIn.addEventListener('keydown', function(e){
      e.stopPropagation();
      if (e.code === 'Enter' || e.key === 'Enter') {
        e.preventDefault();
        var v = chatIn.value;
        chatIn.value = '';
        if (typeof runChatCommand === 'function') runChatCommand(v);
      }
    });
    chatIn.addEventListener('keyup', function(e){ e.stopPropagation(); });
    chatIn.addEventListener('keypress', function(e){ e.stopPropagation(); });
  }
  // Slash opens chat focus
  document.addEventListener('keydown', function(e) {
    if (e.code === 'Slash' && document.activeElement !== chatIn) {
      e.preventDefault();
      if (chatIn) { chatIn.focus(); chatIn.value = '/'; }
    }
  });


  // Roblox-like top chrome
  var igTop = document.createElement('div');
  igTop.className = 'flx-ig-top';
  igTop.innerHTML = '<a class="flx-ig-logo" href="/flexlox/">Flexlox</a>' +
    '<span class="flx-ig-chip" id="flx-ig-title">Experience</span>' +
    '<span class="flx-ig-chip" id="flx-mp-count">1 online</span>' +
    '<a class="flx-ig-leave" href="/flexlox/">Leave</a>';
  host.appendChild(igTop);
  if (window.__FLX_TITLE__) {
    var tt = document.getElementById('flx-ig-title');
    if (tt) tt.textContent = String(window.__FLX_TITLE__).slice(0, 40);
  }
  var loadEl = document.createElement('div');
  loadEl.className = 'flx-load';
  loadEl.innerHTML = '<div style="font-weight:900;font-size:20px">Flexlox</div>' +
    '<div id="flx-load-msg" style="color:#aaa;font-size:13px">Загрузка…</div>' +
    '<div class="flx-load-bar"><i id="flx-load-bar"></i></div>';
  host.appendChild(loadEl);
  function showLoading(msg, thenUrl) {
    loadEl.classList.add('show');
    document.getElementById('flx-load-msg').textContent = msg || 'Загрузка…';
    var bar = document.getElementById('flx-load-bar');
    var p = 0;
    var iv = setInterval(function () {
      p += 8 + Math.random() * 12;
      if (p > 100) p = 100;
      bar.style.width = p + '%';
      if (p >= 100) {
        clearInterval(iv);
        if (thenUrl) location.href = thenUrl;
      }
    }, 120);
  }
  // Portal
  var portal = data.portal || null;
  var portalMesh = null;
  var portalTimer = 0;
  if (portal && portal.map_id) {
    portalMesh = addBox(2.2, 3.2, 0.4, 0x00a2ff, +portal.x || 10, 1.6, +portal.z || 10, false);
    var ring = addBox(2.6, 0.15, 2.6, 0x38bdf8, +portal.x || 10, 0.1, +portal.z || 10, false);
  }
  function tryPortal() {
    if (!portal || !portal.map_id) return;
    var dx = player.position.x - (+portal.x || 10);
    var dz = player.position.z - (+portal.z || 10);
    if (Math.sqrt(dx * dx + dz * dz) > 3.5) return;
    showLoading(portal.label || 'Переход…', '/flexlox/play.php?id=' + portal.map_id);
  }


  function nearestVehicle() {
    var best = null, bd = 4;
    for (var i = 0; i < vehicles.length; i++) {
      var d = player.position.distanceTo(vehicles[i].position);
      if (d < bd) { bd = d; best = vehicles[i]; }
    }
    return best;
  }
  function toggleVehicle() {
    if (typeof vehicles === 'undefined') return;
    if (inVehicle) {
      player.position.set(inVehicle.position.x, 1, inVehicle.position.z);
      player.visible = true;
      inVehicle.userData.speed = 0;
      inVehicle = null;
      return;
    }
    var v = nearestVehicle();
    if (!v) return;
    inVehicle = v;
    v.userData.speed = 0;
    yaw = v.rotation.y || yaw;
    player.visible = false;
  }
  function nearestNPC() {
    var best = null, bd = 3.4;
    for (var i = 0; i < interactables.length; i++) {
      var d = player.position.distanceTo(interactables[i].pos);
      if (d < bd) { bd = d; best = interactables[i]; }
    }
    return best;
  }
  function tryInteract() {
    var n = nearestNPC();
    if (!n) return;
    dialogOpen = true;
    document.getElementById('flx-dlg-title').textContent = n.label;
    var lines = n.lines || ['...'];
    document.getElementById('flx-dlg-text').textContent = lines[Math.floor(Math.random() * lines.length)];
    dlg.style.display = 'block';
  }

  if (!isMobile) {
    canvas.addEventListener('click', function (e) {
      if (dialogOpen) return;
      if ((gameMode === 'shooter' || weaponType === 'gun' || weaponType === 'melee') && document.pointerLockElement === canvas) {
        tryAttack();
        return;
      }
      canvas.requestPointerLock && canvas.requestPointerLock();
      if (gameMode === 'shooter') setTimeout(function () { if (document.pointerLockElement === canvas) shoot(); }, 50);
    });
    document.addEventListener('mousemove', function (e) {
      if (document.pointerLockElement !== canvas) return;
      yaw -= e.movementX * 0.0025;
      pitch = Math.max(-1.1, Math.min(1.1, pitch - e.movementY * 0.0025));
    });
  }

  if (isMobile) {
    var hud = document.createElement('div');
    hud.innerHTML = '<div id="flx-stick-zone"><div id="flx-stick-base"><div id="flx-stick-knob"></div></div></div>' +
      '<button type="button" id="flx-jump-btn">JUMP</button><button type="button" id="flx-int-btn">E</button>' +
      ((gameMode === 'shooter' || (typeof weaponType!=='undefined' && (weaponType==='gun'||weaponType==='melee')) || data.weapon_type==='gun' || data.weapon_type==='melee') ? '<button type="button" id="flx-shoot-btn">FIRE</button>' : '');
    hud.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:10';
    host.appendChild(hud);
    var st = document.createElement('style');
    st.textContent = '#flx-stick-zone{position:absolute;left:0;bottom:0;width:50%;height:50%;pointer-events:auto;z-index:11}' +
      '#flx-stick-base{position:absolute;left:16px;bottom:20px;width:128px;height:128px;border-radius:50%;background:rgba(255,255,255,.14);border:2px solid rgba(255,255,255,.3);touch-action:none}' +
      '#flx-stick-knob{position:absolute;left:44px;top:44px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.55)}' +
      '#flx-jump-btn,#flx-int-btn,#flx-shoot-btn{position:absolute;border:none;border-radius:50%;color:#fff;font-weight:800;pointer-events:auto;touch-action:none;z-index:12}' +
      '#flx-jump-btn{right:16px;bottom:24px;width:84px;height:84px;background:rgba(2,183,87,.9);font-size:13px}' +
      '#flx-int-btn{right:20px;bottom:118px;width:56px;height:56px;background:rgba(0,162,255,.9)}' +
      '#flx-shoot-btn{right:90px;bottom:40px;width:70px;height:70px;background:rgba(239,68,68,.9);font-size:12px}';
    document.head.appendChild(st);
    var baseEl = document.getElementById('flx-stick-base');
    var knob = document.getElementById('flx-stick-knob');
    var maxR = 42, stickId = null;
    function applyStick(cx, cy) {
      var r = baseEl.getBoundingClientRect();
      var dx = cx - (r.left + r.width / 2), dy = cy - (r.top + r.height / 2);
      var len = Math.sqrt(dx * dx + dy * dy) || 1;
      if (len > maxR) { dx *= maxR / len; dy *= maxR / len; }
      knob.style.left = (44 + dx) + 'px'; knob.style.top = (44 + dy) + 'px';
      stick.dx = dx / maxR; stick.dy = dy / maxR;
    }
    function clearStick() {
      stick.dx = 0; stick.dy = 0; stick.active = false; stickId = null;
      knob.style.left = '44px'; knob.style.top = '44px';
    }
    baseEl.addEventListener('touchstart', function (e) {
      e.preventDefault(); var t = e.changedTouches[0]; stickId = t.identifier; stick.active = true; applyStick(t.clientX, t.clientY);
    }, { passive: false });
    baseEl.addEventListener('touchmove', function (e) {
      e.preventDefault();
      for (var i = 0; i < e.changedTouches.length; i++) {
        if (e.changedTouches[i].identifier === stickId) applyStick(e.changedTouches[i].clientX, e.changedTouches[i].clientY);
      }
    }, { passive: false });
    baseEl.addEventListener('touchend', function (e) {
      for (var i = 0; i < e.changedTouches.length; i++) if (e.changedTouches[i].identifier === stickId) clearStick();
    }, { passive: false });
    document.getElementById('flx-jump-btn').addEventListener('touchstart', function (e) { e.preventDefault(); stick.jump = true; }, { passive: false });
    document.getElementById('flx-jump-btn').addEventListener('touchend', function () { stick.jump = false; });
    document.getElementById('flx-int-btn').addEventListener('touchstart', function (e) { e.preventDefault(); tryInteract(); }, { passive: false });
    var sb = document.getElementById('flx-shoot-btn');
    if (sb) sb.addEventListener('touchstart', function (e) { e.preventDefault(); shoot(); }, { passive: false });

    canvas.addEventListener('touchstart', function (e) {
      for (var i = 0; i < e.changedTouches.length; i++) {
        var t = e.changedTouches[i], rect = canvas.getBoundingClientRect();
        if (t.clientX > rect.left + rect.width * 0.42) { lookId = t.identifier; lookX = t.clientX; lookY = t.clientY; }
      }
    }, { passive: true });
    canvas.addEventListener('touchmove', function (e) {
      for (var i = 0; i < e.changedTouches.length; i++) {
        var t = e.changedTouches[i];
        if (t.identifier !== lookId) continue;
        yaw -= (t.clientX - lookX) * 0.006;
        pitch = Math.max(-1.1, Math.min(1.1, pitch - (t.clientY - lookY) * 0.006));
        lookX = t.clientX; lookY = t.clientY;
      }
    }, { passive: true });
    canvas.addEventListener('touchend', function (e) {
      for (var i = 0; i < e.changedTouches.length; i++) if (e.changedTouches[i].identifier === lookId) lookId = null;
    }, { passive: true });
  }

  // Multiplayer — smoother (0.7s poll + stronger lerp)
  var remotePlayers = {};
  var mapId = window.__FLX_MAP_ID__ || 0;
  var mpEnabled = window.__FLX_MP__ !== false;
  function mpSync() {
    if (!mpEnabled || kickedAfk) return;
    fetch('/api/flexlox_mp.php?map_id=' + mapId, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ map_id: mapId, x: player.position.x, y: player.position.y, z: player.position.z, yaw: yaw, afk: (performance.now() - lastInputAt > 20000) ? 1 : 0 })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) return;
      var seen = {};
      (data.players || []).forEach(function (pl) {
        var id = String(pl.user_id);
        seen[id] = 1;
        if (!remotePlayers[id]) {
          remotePlayers[id] = makeR6(pl, pl.username);
          remotePlayers[id].position.set(+pl.x, +pl.y, +pl.z);
          scene.add(remotePlayers[id]);
        }
        var g = remotePlayers[id];
        g.userData.target = { x: +pl.x, y: +pl.y, z: +pl.z, yaw: +pl.yaw };
        var flagKey = (pl.is_afk ? '1' : '0') + (pl.has_crown ? '1' : '0') + (pl.is_guest ? '1' : '0') + String(pl.username || '');
        if (g.userData.flagKey !== flagKey) {
          g.userData.flagKey = flagKey;
          setPlayerBadges(g, { name: pl.username, afk: !!+pl.is_afk, crown: !!+pl.has_crown, guest: !!+pl.is_guest });
        }
        // store prev for velocity estimate
        if (!g.userData.prev) g.userData.prev = { x: +pl.x, y: +pl.y, z: +pl.z, t: performance.now() };
        else {
          var now = performance.now();
          var dt = Math.max(0.05, (now - g.userData.prev.t) / 1000);
          g.userData.vel = {
            x: (+pl.x - g.userData.prev.x) / dt,
            y: (+pl.y - g.userData.prev.y) / dt,
            z: (+pl.z - g.userData.prev.z) / dt
          };
          g.userData.prev = { x: +pl.x, y: +pl.y, z: +pl.z, t: now };
        }
      });
      Object.keys(remotePlayers).forEach(function (id) {
        if (!seen[id]) { scene.remove(remotePlayers[id]); delete remotePlayers[id]; }
      });
      var el = document.getElementById('flx-mp-count');
      if (el) el.textContent = (1 + (data.players || []).length) + ' online';
    }).catch(function () {});
  }
  if (mpEnabled) {
    setTimeout(mpSync, 300);
    setInterval(mpSync, 2500);
  }

  var clock = new THREE.Clock();
  function animate() {
    requestAnimationFrame(animate);
    var dt = Math.min(clock.getDelta(), 0.05);
    if (portal && portal.auto_sec > 0) {
      portalTimer += dt;
      if (portalTimer >= portal.auto_sec) {
        portal.auto_sec = 0;
        showLoading(portal.label || 'Переход…', '/flexlox/play.php?id=' + portal.map_id);
      }
    }
    var speed = (isMobile ? 7.5 : 8.5) * dt;
    var forward = new THREE.Vector3(-Math.sin(yaw), 0, -Math.cos(yaw));
    var right = new THREE.Vector3(Math.cos(yaw), 0, -Math.sin(yaw));
    var move = new THREE.Vector3();
    if (keys['KeyW'] || keys['ArrowUp']) move.add(forward);
    if (keys['KeyS'] || keys['ArrowDown']) move.sub(forward);
    if (keys['KeyA'] || keys['ArrowLeft']) move.sub(right);
    if (keys['KeyD'] || keys['ArrowRight']) move.add(right);
    if (Math.abs(stick.dx) > 0.08 || Math.abs(stick.dy) > 0.08) {
      move.add(forward.clone().multiplyScalar(-stick.dy));
      move.add(right.clone().multiplyScalar(stick.dx));
    }
    // driving physics
    if (inVehicle) {
      var accel = 0;
      if (keys['KeyW'] || keys['ArrowUp'] || stick.dy < -0.2) accel += 1;
      if (keys['KeyS'] || keys['ArrowDown'] || stick.dy > 0.2) accel -= 0.7;
      var steer = 0;
      if (keys['KeyA'] || keys['ArrowLeft'] || stick.dx < -0.2) steer += 1;
      if (keys['KeyD'] || keys['ArrowRight'] || stick.dx > 0.2) steer -= 1;
      var maxSp = 28;
      inVehicle.userData.speed = inVehicle.userData.speed || 0;
      inVehicle.userData.speed += accel * 36 * dt;
      inVehicle.userData.speed *= (accel === 0 ? 0.98 : 0.995);
      if (inVehicle.userData.speed > maxSp) inVehicle.userData.speed = maxSp;
      if (inVehicle.userData.speed < -8) inVehicle.userData.speed = -8;
      var spNow = inVehicle.userData.speed;
      // steer more at speed
      if (Math.abs(spNow) > 0.4) {
        inVehicle.rotation.y += steer * 2.4 * dt * (spNow > 0 ? 1 : -1);
        yaw = inVehicle.rotation.y;
      }
      var fx = -Math.sin(inVehicle.rotation.y) * spNow * dt;
      var fz = -Math.cos(inVehicle.rotation.y) * spNow * dt;
      var nx = inVehicle.position.x + fx;
      var nz = inVehicle.position.z + fz;
      if (!collides(nx, 1, inVehicle.position.z)) inVehicle.position.x = nx;
      else inVehicle.userData.speed *= 0.3;
      if (!collides(inVehicle.position.x, 1, nz)) inVehicle.position.z = nz;
      else inVehicle.userData.speed *= 0.3;
      inVehicle.position.x = Math.max(-70, Math.min(70, inVehicle.position.x));
      inVehicle.position.z = Math.max(-70, Math.min(70, inVehicle.position.z));
      // wheel spin
      if (inVehicle.userData.wheels) {
        inVehicle.userData.wheels.forEach(function(w){ w.rotation.x += spNow * dt * 2; });
      }
      player.position.set(inVehicle.position.x, 1, inVehicle.position.z);
      player.rotation.y = inVehicle.rotation.y;
    } else if (move.lengthSq() > 0.0001) {
      move.normalize().multiplyScalar(speed);
      var nx = player.position.x + move.x, nz = player.position.z + move.z;
      if (!collides(nx, player.position.y, player.position.z)) player.position.x = nx;
      if (!collides(player.position.x, player.position.y, nz)) player.position.z = nz;
    }
    velY -= 22 * dt;
    if ((keys['Space'] || stick.jump) && player.position.y <= 1.08) velY = 8.8;
    player.position.y = Math.max(1, player.position.y + velY * dt);
    if (player.position.y <= 1.01) velY = 0;
    if (!inVehicle) {
      player.position.x = Math.max(-60, Math.min(60, player.position.x));
      player.position.z = Math.max(-60, Math.min(60, player.position.z));
      player.rotation.y = yaw;
    } else {
      player.position.x = inVehicle.position.x;
      player.position.z = inVehicle.position.z;
      yaw = inVehicle.rotation.y;
      player.rotation.y = yaw;
    }
    // movement AFK tracking + kick
    if (Math.abs(player.position.x - lastPos.x) > 0.05 || Math.abs(player.position.z - lastPos.z) > 0.05) {
      lastMoveAt = performance.now();
      lastPos.x = player.position.x;
      lastPos.y = player.position.y;
      lastPos.z = player.position.z;
    }
    if (!kickedAfk && (performance.now() - lastMoveAt) > AFK_KICK_MS) {
      doAfkKick();
    }

    var moving = move.lengthSq() > 0.0001;
    if (moving) walkPhase += dt * 11;
    var swing = moving ? Math.sin(walkPhase) * 0.4 : 0;
    var parts = player.userData.parts;
    if (parts) {
      if (parts.legL) parts.legL.rotation.x = swing;
      if (parts.legR) parts.legR.rotation.x = -swing;
      if (parts.armL) parts.armL.rotation.x = -swing * 0.5;
      if (parts.armR) parts.armR.rotation.x = swing * 0.5;
    }

    // projectiles
    for (var pi = projectiles.length - 1; pi >= 0; pi--) {
      var pr = projectiles[pi];
      pr.mesh.position.addScaledVector(pr.vel, dt);
      pr.life -= dt;
      if (pr.life <= 0) { scene.remove(pr.mesh); projectiles.splice(pi, 1); }
    }

    var near = nearestNPC();
    var nearV = (!inVehicle && typeof vehicles !== 'undefined') ? nearestVehicle() : null;
    if (inVehicle) {
      promptEl.style.display = 'block';
      promptEl.textContent = isMobile ? 'F · выйти' : '[F] Выйти из машины';
    } else if (near && !dialogOpen) {
      promptEl.style.display = 'block';
      promptEl.textContent = (isMobile ? 'E · ' : '[E] ') + near.label;
    } else if (portal && portal.map_id) {
      var pdx = player.position.x - (+portal.x || 10), pdz = player.position.z - (+portal.z || 10);
      if (Math.sqrt(pdx*pdx+pdz*pdz) < 3.5) {
        promptEl.style.display = 'block';
        promptEl.textContent = (isMobile ? 'F · ' : '[F] ') + (portal.label || 'Перейти');
      } else if (nearV) {
        promptEl.style.display = 'block';
        promptEl.textContent = isMobile ? 'F · сесть' : '[F] Сесть в машину';
      } else promptEl.style.display = 'none';
    } else if (nearV) {
      promptEl.style.display = 'block';
      promptEl.textContent = isMobile ? 'F · сесть' : '[F] Сесть в машину';
    } else promptEl.style.display = 'none';

    // remote smooth: lerp + velocity extrapolation
    Object.keys(remotePlayers).forEach(function (id) {
      var g = remotePlayers[id];
      var tg = g.userData.target;
      if (!tg) return;
      var v = g.userData.vel || { x: 0, y: 0, z: 0 };
      var predX = tg.x + v.x * 0.15;
      var predY = tg.y + v.y * 0.15;
      var predZ = tg.z + v.z * 0.15;
      g.position.x += (predX - g.position.x) * Math.min(1, 12 * dt);
      g.position.y += (predY - g.position.y) * Math.min(1, 12 * dt);
      g.position.z += (predZ - g.position.z) * Math.min(1, 12 * dt);
      g.rotation.y = tg.yaw;
      var movingR = Math.abs(v.x) + Math.abs(v.z) > 0.5;
      if (movingR && g.userData.parts) {
        g.userData.walk = (g.userData.walk || 0) + dt * 10;
        var sw = Math.sin(g.userData.walk) * 0.35;
        g.userData.parts.legL.rotation.x = sw;
        g.userData.parts.legR.rotation.x = -sw;
      }
    });

    var camDist = inVehicle ? (isMobile ? 9 : 11) : (isMobile ? 5.2 : 6.2);
    camera.position.set(
      player.position.x + Math.sin(yaw) * camDist * Math.cos(pitch),
      player.position.y + 2.0 + Math.sin(pitch) * 3.2,
      player.position.z + Math.cos(yaw) * camDist * Math.cos(pitch)
    );
    camera.lookAt(player.position.x, player.position.y + 1.15, player.position.z);
    renderer.render(scene, camera);
  }
  animate();

  window.addEventListener('resize', function () {
    var ww = host.clientWidth, hh = Math.max(host.clientHeight || 0, isMobile ? 420 : 520);
    host.style.height = hh + 'px';
    camera.aspect = ww / hh;
    camera.updateProjectionMatrix();
    renderer.setSize(ww, hh);
  });
})();
