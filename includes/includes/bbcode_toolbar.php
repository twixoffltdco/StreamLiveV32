<div class="bb-toolbar">
  <button type="button" onclick="bbWrap('[b]','[/b]')" title="Жирный"><b>B</b></button>
  <button type="button" onclick="bbWrap('[i]','[/i]')" title="Курсив"><i>I</i></button>
  <button type="button" onclick="bbWrap('[u]','[/u]')" title="Подчёркнутый"><u>U</u></button>
  <button type="button" onclick="bbWrap('[s]','[/s]')" title="Зачёркнутый"><s>S</s></button>
  <button type="button" onclick="bbWrap('[url=https://]','[/url]')" title="Ссылка">Ссылка</button>
  <button type="button" onclick="bbWrap('[img]','[/img]')" title="Картинка">Картинка</button>
  <button type="button" onclick="bbWrap('[quote]','[/quote]')" title="Цитата">Цитата</button>
  <button type="button" onclick="bbWrap('[code]','[/code]')" title="Код">Код</button>
  <button type="button" onclick="bbWrap('[php]','[/php]')" title="PHP-код">PHP</button>
  <button type="button" onclick="bbWrap('[spoiler]','[/spoiler]')" title="Спойлер">Спойлер</button>
  <button type="button" onclick="bbWrap('[hide]','[/hide]')" title="Скрытый текст">Hide</button>
  <button type="button" onclick="bbWrap('[list]\n[*]','\n[/list]')" title="Список">Список</button>
  <button type="button" onclick="bbWrap('[color=red]','[/color]')" title="Цвет">Цвет</button>
  <button type="button" onclick="bbWrap('[size=3]','[/size]')" title="Размер">Размер</button>
  <button type="button" onclick="bbWrap('[center]','[/center]')" title="По центру">Центр</button>
  <button type="button" onclick="bbWrap('[user]','[/user]')" title="Упоминание пользователя">@user</button>
  <button type="button" onclick="bbWrap('[youtube]','[/youtube]')" title="YouTube">YouTube</button>
  <button type="button" onclick="bbWrap('[imgur]','[/imgur]')" title="Imgur скрин">Imgur</button>
  <button type="button" onclick="bbWrap('[iframe]','[/iframe]')" title="Встроить iframe">iframe</button>
  <button type="button" onclick="bbWrap('[embed]','[/embed]')" title="Embed URL">Embed</button>
  <button type="button" onclick="bbInsertTableTemplate()" title="Таблица">Таблица</button>
</div>
<script>
  function bbWrap(open, close) {
    const ta = document.getElementById('bb-editor');
    if (!ta) return;
    const start = ta.selectionStart, end = ta.selectionEnd;
    const selected = ta.value.substring(start, end);
    const before = ta.value.substring(0, start);
    const after = ta.value.substring(end);
    ta.value = before + open + selected + close + after;
    ta.focus();
    ta.selectionStart = start + open.length;
    ta.selectionEnd = start + open.length + selected.length;
  }

  // Готовый шаблон 2×2 по кнопке "Таблица" — быстрее, чем печатать теги руками.
  function bbInsertTableTemplate() {
    bbWrap('', '[table]\n[tr][th]Заголовок 1[/th][th]Заголовок 2[/th][/tr]\n[tr][td]Ячейка[/td][td]Ячейка[/td][/tr]\n[/table]');
  }

  // Строит BBCode-таблицу из двумерного массива ячеек (строки/столбцы).
  function bbBuildTable(rows) {
    if (!rows.length) return '';
    var out = '[table]\n';
    rows.forEach(function (row, i) {
      var tag = (i === 0) ? 'th' : 'td';
      out += '[tr]' + row.map(function (cell) { return '[' + tag + ']' + cell.trim() + '[/' + tag + ']'; }).join('') + '[/tr]\n';
    });
    out += '[/table]';
    return out;
  }

  // Разбирает HTML-таблицу (например, скопированную прямо со страницы сайта) в массив ячеек.
  function bbParseHtmlTable(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var table = doc.querySelector('table');
    if (!table) return null;
    var rows = [];
    table.querySelectorAll('tr').forEach(function (tr) {
      var cells = [];
      tr.querySelectorAll('td,th').forEach(function (cell) { cells.push(cell.textContent.replace(/\s+/g, ' ').trim()); });
      if (cells.length) rows.push(cells);
    });
    return rows.length ? rows : null;
  }

  // Разбирает табличные данные из обычного текста — так Excel/Google Таблицы/Numbers кладут
  // выделенный диапазон в буфer: строки через перенос строки, столбцы через Tab.
  function bbParsePlainTable(text) {
    var lines = text.replace(/\r\n/g, '\n').split('\n').filter(function (l) { return l.length; });
    if (lines.length < 2) return null;
    var hasTabs = lines.every(function (l) { return l.indexOf('\t') !== -1; });
    if (!hasTabs) return null;
    return lines.map(function (l) { return l.split('\t'); });
  }

  (function () {
    var ta = document.getElementById('bb-editor');
    if (!ta) return;

    ta.addEventListener('paste', function (e) {
      var clipboard = e.clipboardData || window.clipboardData;
      if (!clipboard) return;

      // 1) HTML-таблица в буфере (скопировали кусок веб-страницы или Google Таблицы через браузер)
      var html = clipboard.getData('text/html');
      if (html && /<table/i.test(html)) {
        var rows = bbParseHtmlTable(html);
        if (rows) {
          e.preventDefault();
          bbWrap('', bbBuildTable(rows));
          return;
        }
      }

      var text = clipboard.getData('text/plain') || '';

      // 2) Табличные данные как обычный текст (Excel/Numbers, вставка "как текст")
      var plainRows = bbParsePlainTable(text);
      if (plainRows) {
        e.preventDefault();
        bbWrap('', bbBuildTable(plainRows));
        return;
      }

      // 3) Голая ссылка на картинку — сама оборачивается в [img], чтобы не описывать вручную
      if (/^https?:\/\/\S+\.(png|jpe?g|gif|webp|svg)(\?\S*)?$/i.test(text.trim())) {
        e.preventDefault();
        bbWrap('[img]' + text.trim(), '[/img]');
        return;
      }

      // 4) Обычная голая ссылка — в [url], чтобы не расползалась превьюшками где не надо
      if (/^https?:\/\/\S+$/i.test(text.trim()) && ta.selectionStart !== ta.selectionEnd) {
        // Вставили ссылку поверх ВЫДЕЛЕННОГО текста — оборачиваем выделенное в [url=...]выделение[/url]
        e.preventDefault();
        var start = ta.selectionStart, end = ta.selectionEnd;
        var selected = ta.value.substring(start, end);
        ta.value = ta.value.substring(0, start) + '[url=' + text.trim() + ']' + selected + '[/url]' + ta.value.substring(end);
        ta.focus();
        return;
      }
      // Обычный текст — вставляем как есть, ничего не перехватываем
    });
  })();
</script>
