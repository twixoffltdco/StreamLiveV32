(function () {
  var host = document.getElementById('flx-builder');
  var out = document.getElementById('map_blocks_json');
  if (!host || typeof THREE === 'undefined') {
    if (host) host.innerHTML = '<p style="padding:12px;color:#aaa">Three.js нужен для конструктора.</p>';
    return;
  }

  var tool = 'box'; // box | floor | wall | erase | spawn
  var color = '#64748b';
  var grid = 2;
  var blocks = [];
  try {
    var initial = window.__FLX_BUILD_INIT__;
    if (typeof initial === 'string') initial = JSON.parse(initial);
    if (Array.isArray(initial)) blocks = initial;
  } catch (e) {}

  var scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1d24);
  var w = host.clientWidth || 700;
  var h = Math.max(host.clientHeight || 420, 420);
  host.style.position = 'relative';
  host.style.height = h + 'px';

  var camera = new THREE.PerspectiveCamera(60, w / h, 0.1, 300);
  camera.position.set(18, 16, 22);
  var renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setSize(w, h);
  host.innerHTML = '';
  host.appendChild(renderer.domElement);
  var canvas = renderer.domElement;
  canvas.style.cssText = 'display:block;width:100%;height:100%;border-radius:12px';

  scene.add(new THREE.AmbientLight(0xffffff, 0.55));
  var sun = new THREE.DirectionalLight(0xffffff, 1);
  sun.position.set(30, 50, 20);
  scene.add(sun);

  // ground grid
  var ground = new THREE.Mesh(
    new THREE.PlaneGeometry(80, 80),
    new THREE.MeshLambertMaterial({ color: 0x2a2e38 })
  );
  ground.rotation.x = -Math.PI / 2;
  ground.position.y = 0;
  scene.add(ground);
  var gridHelper = new THREE.GridHelper(80, 40, 0x3b82f6, 0x333842);
  gridHelper.position.y = 0.02;
  scene.add(gridHelper);

  var ghostMat = new THREE.MeshLambertMaterial({ color: 0x00a2ff, transparent: true, opacity: 0.45 });
  var ghost = new THREE.Mesh(new THREE.BoxGeometry(grid, grid, grid), ghostMat);
  ghost.visible = false;
  scene.add(ghost);

  var meshMap = {}; // key -> mesh
  function keyOf(b) { return b.x + ',' + b.y + ',' + b.z + ',' + (b.w||1) + ',' + (b.h||1) + ',' + (b.d||1); }

  function addMesh(b) {
    var sx = (b.w || 1) * grid, sy = (b.h || 1) * grid, sz = (b.d || 1) * grid;
    var col = b.color || color;
    if (typeof col === 'string' && col[0] === '#') col = parseInt(col.slice(1), 16);
    var m = new THREE.Mesh(
      new THREE.BoxGeometry(sx, sy, sz),
      new THREE.MeshLambertMaterial({ color: col })
    );
    m.position.set(b.x * grid + sx / 2 - grid / 2, b.y * grid + sy / 2, b.z * grid + sz / 2 - grid / 2);
    m.userData.block = b;
    scene.add(m);
    meshMap[keyOf(b)] = m;
  }

  function rebuild() {
    Object.keys(meshMap).forEach(function (k) {
      scene.remove(meshMap[k]);
    });
    meshMap = {};
    blocks.forEach(addMesh);
    syncOut();
  }

  function syncOut() {
    if (out) out.value = JSON.stringify(blocks);
    var cnt = document.getElementById('flx-build-count');
    if (cnt) cnt.textContent = blocks.length + ' blocks';
  }

  blocks.forEach(addMesh);
  syncOut();

  var yaw = 0.6, pitch = 0.45, dist = 28;
  var dragging = false, lastX = 0, lastY = 0;
  function updateCam() {
    camera.position.set(
      Math.sin(yaw) * Math.cos(pitch) * dist,
      Math.sin(pitch) * dist + 4,
      Math.cos(yaw) * Math.cos(pitch) * dist
    );
    camera.lookAt(0, 2, 0);
  }
  updateCam();

  canvas.addEventListener('mousedown', function (e) {
    if (e.button === 2 || e.button === 1 || e.shiftKey) {
      dragging = true; lastX = e.clientX; lastY = e.clientY;
    }
  });
  window.addEventListener('mouseup', function () { dragging = false; });
  canvas.addEventListener('mousemove', function (e) {
    if (dragging) {
      yaw -= (e.clientX - lastX) * 0.005;
      pitch = Math.max(0.1, Math.min(1.2, pitch + (e.clientY - lastY) * 0.005));
      lastX = e.clientX; lastY = e.clientY;
      updateCam();
    }
  });
  canvas.addEventListener('wheel', function (e) {
    dist = Math.max(8, Math.min(60, dist + e.deltaY * 0.02));
    updateCam();
  }, { passive: true });
  canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });

  var raycaster = new THREE.Raycaster();
  var mouse = new THREE.Vector2();
  function getHit(e) {
    var r = canvas.getBoundingClientRect();
    mouse.x = ((e.clientX - r.left) / r.width) * 2 - 1;
    mouse.y = -((e.clientY - r.top) / r.height) * 2 + 1;
    raycaster.setFromCamera(mouse, camera);
    var hits = raycaster.intersectObject(ground);
    if (!hits.length) return null;
    var p = hits[0].point;
    return {
      x: Math.floor(p.x / grid),
      z: Math.floor(p.z / grid)
    };
  }

  canvas.addEventListener('mousemove', function (e) {
    if (dragging) return;
    var h = getHit(e);
    if (!h) { ghost.visible = false; return; }
    var bw = 1, bh = 1, bd = 1, by = 0;
    if (tool === 'floor') { bw = 2; bd = 2; bh = 1; }
    if (tool === 'wall') { bw = 1; bh = 2; bd = 1; by = 0; }
    if (tool === 'box') { bw = 1; bh = 2; bd = 1; }
    ghost.scale.set(bw, bh, bd);
    ghost.position.set(
      h.x * grid + (bw * grid) / 2 - grid / 2,
      by * grid + (bh * grid) / 2,
      h.z * grid + (bd * grid) / 2 - grid / 2
    );
    ghost.visible = tool !== 'erase';
    ghost.material.color.set(tool === 'erase' ? 0xef4444 : color);
  });

  canvas.addEventListener('click', function (e) {
    if (e.shiftKey || dragging) return;
    var h = getHit(e);
    if (!h) return;
    if (tool === 'erase') {
      blocks = blocks.filter(function (b) {
        return !(b.x === h.x && b.z === h.z);
      });
      rebuild();
      return;
    }
    var bw = 1, bh = 1, bd = 1, by = 0;
    if (tool === 'floor') { bw = 2; bd = 2; bh = 1; by = 0; }
    else if (tool === 'wall') { bw = 1; bh = 3; bd = 1; by = 0; }
    else if (tool === 'box') { bw = 1; bh = 2; bd = 1; by = 0; }
    else if (tool === 'tall') { bw = 2; bh = 5; bd = 2; by = 0; }
    else if (tool === 'interior') {
      // room: floor + 4 walls
      var room = [
        { x: h.x, y: 0, z: h.z, w: 4, h: 1, d: 4, color: '#3f3f46', kind: 'floor' },
        { x: h.x, y: 1, z: h.z, w: 4, h: 3, d: 1, color: color, kind: 'wall' },
        { x: h.x, y: 1, z: h.z + 3, w: 4, h: 3, d: 1, color: color, kind: 'wall' },
        { x: h.x, y: 1, z: h.z, w: 1, h: 3, d: 4, color: color, kind: 'wall' },
        { x: h.x + 3, y: 1, z: h.z, w: 1, h: 3, d: 4, color: color, kind: 'wall' },
      ];
      blocks = blocks.concat(room);
      rebuild();
      return;
    }
    blocks.push({ x: h.x, y: by, z: h.z, w: bw, h: bh, d: bd, color: color, kind: tool });
    if (blocks.length > 200) blocks = blocks.slice(-200);
    rebuild();
  });

  // toolbar hooks
  window.flxBuildSetTool = function (t) { tool = t; };
  window.flxBuildSetColor = function (c) { color = c; };
  window.flxBuildClear = function () { blocks = []; rebuild(); };
  window.flxBuildUndo = function () { blocks.pop(); rebuild(); };

  function animate() {
    requestAnimationFrame(animate);
    renderer.render(scene, camera);
  }
  animate();

  window.addEventListener('resize', function () {
    var ww = host.clientWidth, hh = Math.max(host.clientHeight || 420, 420);
    camera.aspect = ww / hh;
    camera.updateProjectionMatrix();
    renderer.setSize(ww, hh);
  });
})();
