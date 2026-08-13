(function () {
  var canvas = document.getElementById('fb-canvas');
  if (!canvas || !window.__FB_PLAY__) return;
  var ctx = canvas.getContext('2d');
  var data = window.__FB_PLAY__;
  var grid = data.blocks || [];
  var W = data.w || 16, H = data.h || 8, D = data.d || 16;
  var camX = 0, camY = 0;
  var colors = ['#0000', '#6b7280', '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#a855f7', '#ec4899'];
  function project(x, y, z) {
    return [(x - z) * 18 + canvas.width / 2 + camX, (x + z) * 10 - y * 16 + 100 + camY];
  }
  function draw() {
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    for (var y = 0; y < grid.length; y++) {
      for (var z = 0; z < (grid[y] || []).length; z++) {
        for (var x = 0; x < (grid[y][z] || []).length; x++) {
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
        }
      }
    }
  }
  draw();
  document.addEventListener('keydown', function (e) {
    if (e.key === 'a' || e.key === 'ArrowLeft') camX += 24;
    if (e.key === 'd' || e.key === 'ArrowRight') camX -= 24;
    if (e.key === 'w' || e.key === 'ArrowUp') camY += 24;
    if (e.key === 's' || e.key === 'ArrowDown') camY -= 24;
    draw();
  });
})();
