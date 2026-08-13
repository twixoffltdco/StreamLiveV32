(function () {
  var canvas = document.getElementById('fb-canvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var W = 16, H = 8, D = 16;
  var grid = [];
  function empty() {
    grid = [];
    for (var y = 0; y < H; y++) {
      grid[y] = [];
      for (var z = 0; z < D; z++) {
        grid[y][z] = [];
        for (var x = 0; x < W; x++) grid[y][z][x] = y === 0 ? 1 : 0;
      }
    }
  }
  empty();
  try {
    if (window.__FB_INITIAL__) {
      var raw = typeof window.__FB_INITIAL__ === 'string' ? JSON.parse(window.__FB_INITIAL__) : window.__FB_INITIAL__;
      if (raw && raw.blocks) grid = raw.blocks;
    }
  } catch (e) {}

  var camX = 0, camY = 0, layer = 1;
  var colors = ['#0000', '#6b7280', '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#a855f7', '#ec4899'];
  var brush = 2;

  function project(x, y, z) {
    var isoX = (x - z) * 18 + canvas.width / 2 + camX;
    var isoY = (x + z) * 10 - y * 16 + 80 + camY;
    return [isoX, isoY];
  }
  function draw() {
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    for (var y = 0; y < H; y++) {
      for (var z = 0; z < D; z++) {
        for (var x = 0; x < W; x++) {
          var b = grid[y][z][x];
          if (!b) continue;
          var p = project(x, y, z);
          ctx.fillStyle = colors[b % colors.length];
          ctx.beginPath();
          ctx.moveTo(p[0], p[1]);
          ctx.lineTo(p[0] + 18, p[1] + 10);
          ctx.lineTo(p[0], p[1] + 20);
          ctx.lineTo(p[0] - 18, p[1] + 10);
          ctx.closePath();
          ctx.fill();
          ctx.strokeStyle = 'rgba(0,0,0,.25)';
          ctx.stroke();
        }
      }
    }
    ctx.fillStyle = '#94a3b8';
    ctx.font = '12px sans-serif';
    ctx.fillText('Слой Y=' + layer + ' · кисть ' + brush, 10, 18);
  }
  draw();

  function cellFromEvent(e) {
    var r = canvas.getBoundingClientRect();
    var mx = (e.clientX - r.left) * (canvas.width / r.width);
    var my = (e.clientY - r.top) * (canvas.height / r.height);
    // rough inverse iso
    var X = ((mx - canvas.width / 2 - camX) / 18 + (my - 80 - camY) / 10) / 2;
    var Z = ((my - 80 - camY) / 10 - (mx - canvas.width / 2 - camX) / 18) / 2;
    var x = Math.round(X), z = Math.round(Z);
    return [x, layer, z];
  }
  canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  canvas.addEventListener('mousedown', function (e) {
    var c = cellFromEvent(e);
    var x = c[0], y = c[1], z = c[2];
    if (x < 0 || z < 0 || x >= W || z >= D || y < 0 || y >= H) return;
    if (e.button === 2) grid[y][z][x] = 0;
    else grid[y][z][x] = brush;
    draw();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft' || e.key === 'a') camX += 20;
    if (e.key === 'ArrowRight' || e.key === 'd') camX -= 20;
    if (e.key === 'ArrowUp' || e.key === 'w') camY += 20;
    if (e.key === 'ArrowDown' || e.key === 's') camY -= 20;
    if (e.key === 'q') layer = Math.max(0, layer - 1);
    if (e.key === 'e') layer = Math.min(H - 1, layer + 1);
    if (e.key >= '1' && e.key <= '7') brush = +e.key;
    draw();
  });
  canvas.addEventListener('wheel', function (e) {
    e.preventDefault();
    layer = Math.max(0, Math.min(H - 1, layer + (e.deltaY > 0 ? -1 : 1)));
    draw();
  }, { passive: false });

  var form = document.getElementById('fb-form');
  if (form) {
    form.addEventListener('submit', function () {
      document.getElementById('map_json').value = JSON.stringify({ v: 1, w: W, h: H, d: D, blocks: grid });
    });
  }
})();
