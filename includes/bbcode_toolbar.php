<div class="bb-toolbar" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px">
  <button type="button" class="bb-btn" onclick="bbWrap('[b]','[/b]')" title="Жирный"><b>B</b></button>
  <button type="button" class="bb-btn" onclick="bbWrap('[i]','[/i]')" title="Курсив"><i>I</i></button>
  <button type="button" class="bb-btn" onclick="bbWrap('[u]','[/u]')" title="Подчёркнутый"><u>U</u></button>
  <button type="button" class="bb-btn" onclick="bbWrap('[s]','[/s]')" title="Зачёркнутый"><s>S</s></button>
  <button type="button" class="bb-btn" onclick="bbWrap('[url=https://]','[/url]')">URL</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[img]','[/img]')">IMG</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[quote]','[/quote]')">Quote</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[code]','[/code]')">Code</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[spoiler]','[/spoiler]')">Spoiler</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[list]\n[*]','\n[/list]')">List</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[color=#e74c3c]','[/color]')">Color</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[size=4]','[/size]')">Size</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[center]','[/center]')">Center</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[media=youtube]','[/media]')">YT</button>
  <button type="button" class="bb-btn" onclick="bbWrap('[user]','[/user]')">@user</button>
  <button type="button" class="bb-btn" onclick="bbInsertTableTemplate()">Table</button>
</div>
<style>
.bb-btn{background:#1e1e28;color:#ddd;border:1px solid #333;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:12px}
.bb-btn:hover{border-color:#7c5cff;color:#fff}
.bb-quote{border-left:3px solid #7c5cff;background:rgba(124,92,255,.08);padding:8px 12px;margin:8px 0;border-radius:0 8px 8px 0}
.bb-quote-head{font-size:12px;opacity:.7;margin-bottom:4px}
.bb-spoiler{margin:8px 0;padding:8px;background:#16161e;border-radius:8px}
.bb-ispoiler{background:#333;color:#333;border-radius:3px;padding:0 4px;cursor:help}
.bb-ispoiler:hover,.bb-ispoiler:focus{color:inherit;background:transparent}
.bb-code{background:#0d0d12;padding:10px;border-radius:8px;overflow:auto;font-size:13px}
.bb-inline-code{background:#1a1a24;padding:1px 5px;border-radius:4px;font-family:ui-monospace,monospace}
.bb-table-wrap{overflow:auto;margin:8px 0}
.bb-table{border-collapse:collapse;width:100%}
.bb-table th,.bb-table td{border:1px solid #333;padding:6px 8px}
.bb-img{border-radius:8px;margin:6px 0}
.bb-highlight{background:#f1c40f55;padding:0 2px}
</style>
<script>
(function(){
  function bbTa(){ return document.getElementById('bb-editor') || document.querySelector('textarea.bb-editor, textarea[name="message"], textarea[name="body"], textarea[name="content"]'); }
  window.bbWrap = function(open, close) {
    var ta = bbTa(); if (!ta) return;
    var start = ta.selectionStart, end = ta.selectionEnd;
    var selected = ta.value.substring(start, end);
    ta.value = ta.value.substring(0, start) + open + selected + close + ta.value.substring(end);
    ta.focus();
    ta.selectionStart = start + open.length;
    ta.selectionEnd = start + open.length + selected.length;
  };
  window.bbInsertTableTemplate = function() {
    bbWrap('', '[table]\n[tr][th]A[/th][th]B[/th][/tr]\n[tr][td]1[/td][td]2[/td][/tr]\n[/table]');
  };

  function htmlToBbcode(html) {
    var div = document.createElement('div');
    div.innerHTML = html;
    function walk(node) {
      if (node.nodeType === 3) return node.nodeValue;
      if (node.nodeType !== 1) return '';
      var tag = node.tagName.toLowerCase();
      var inner = '';
      for (var i = 0; i < node.childNodes.length; i++) inner += walk(node.childNodes[i]);
      if (tag === 'br') return '\n';
      if (tag === 'strong' || tag === 'b') return '[b]' + inner + '[/b]';
      if (tag === 'em' || tag === 'i') return '[i]' + inner + '[/i]';
      if (tag === 'u') return '[u]' + inner + '[/u]';
      if (tag === 's' || tag === 'strike' || tag === 'del') return '[s]' + inner + '[/s]';
      if (tag === 'code') return '[icode]' + inner + '[/icode]';
      if (tag === 'pre') return '[code]' + inner + '[/code]';
      if (tag === 'a') {
        var href = node.getAttribute('href') || '';
        if (/^https?:\/\//i.test(href)) return '[url=' + href + ']' + (inner || href) + '[/url]';
        return inner;
      }
      if (tag === 'img') {
        var src = node.getAttribute('src') || '';
        if (/^https?:\/\//i.test(src)) return '[img]' + src + '[/img]';
        return '';
      }
      if (tag === 'h1' || tag === 'h2') return '[heading=1]' + inner + '[/heading]\n';
      if (tag === 'h3') return '[heading=2]' + inner + '[/heading]\n';
      if (tag === 'p' || tag === 'div') return inner + '\n';
      if (tag === 'li') return '[*]' + inner + '\n';
      if (tag === 'ul') return '[list]\n' + inner + '[/list]\n';
      if (tag === 'ol') return '[list=1]\n' + inner + '[/list]\n';
      if (tag === 'blockquote') return '[quote]' + inner + '[/quote]\n';
      if (tag === 'span') {
        var st = node.getAttribute('style') || '';
        var m = st.match(/color\s*:\s*([^;]+)/i);
        if (m) return '[color=' + m[1].trim() + ']' + inner + '[/color]';
        return inner;
      }
      if (tag === 'table') {
        // leave plain text rows
        return inner + '\n';
      }
      return inner;
    }
    return walk(div).replace(/\n{3,}/g, '\n\n').trim();
  }

  function onReady() {
    var ta = bbTa();
    if (!ta || ta._bbPasteBound) return;
    ta._bbPasteBound = true;
    ta.addEventListener('paste', function (e) {
      var clip = e.clipboardData || window.clipboardData;
      if (!clip) return;
      var html = clip.getData('text/html') || '';
      if (html && (html.indexOf('<b') !== -1 || html.indexOf('<strong') !== -1 || html.indexOf('<em') !== -1 || html.indexOf('<a ') !== -1 || html.indexOf('<i') !== -1)) {
        var bb = htmlToBbcode(html);
        if (bb) {
          e.preventDefault();
          var start = ta.selectionStart, end = ta.selectionEnd;
          ta.value = ta.value.substring(0, start) + bb + ta.value.substring(end);
          ta.selectionStart = ta.selectionEnd = start + bb.length;
        }
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})();
</script>
