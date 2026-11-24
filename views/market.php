<?php

declare(strict_types=1);

/**
 * Вид market: каталог из assets/js/marketplace-data.js + карточка README по API.
 * Без SQLite, без модерации, без кнопки «Предложить» — каталог управляется правками JS.
 */

?><h1 class="ws-view-title">🛒 Маркетплейс</h1>
<p class="ws-view-sub">Курируемые решения для разработчиков. Каталог — в <code>assets/js/marketplace-data.js</code>: правки файла + коммит = публикация.</p>

<form class="mp-filters" id="mp-filters">
  <input type="search" id="mp-search" class="mp-input mp-search" placeholder="Поиск по имени и описанию…" maxlength="80">
  <select id="mp-cat" class="mp-input"><option value="">Все категории</option></select>
  <select id="mp-host" class="mp-input"><option value="">Все хосты</option></select>
  <select id="mp-sort" class="mp-input">
    <option value="name">По имени</option>
    <option value="category">По категории</option>
    <option value="added">По порядку добавления</option>
  </select>
</form>

<section class="mp-grid" id="mp-grid"></section>
<div class="mp-empty" id="mp-empty" hidden>
  <p class="mp-empty-icon">🗂</p>
  <p>Каталог пуст под текущий фильтр.</p>
</div>

<section class="mp-hero" id="mp-hero" hidden>
  <div class="mp-hero-avatar mp-hero-monogram" id="mp-monogram"></div>
  <div class="mp-hero-main">
    <div class="mp-hero-badges" id="mp-badges"></div>
    <p class="mp-hero-desc" id="mp-desc"></p>
    <p class="mp-note" id="mp-note" hidden></p>
    <div class="mp-stats" id="mp-stats"></div>
    <p class="mp-warn" id="mp-warn" hidden></p>
  </div>
  <div class="mp-hero-actions">
    <button type="button" class="btn btn--primary" id="mp-clone">⬇ Клонировать к себе</button>
    <a class="btn" id="mp-open" target="_blank" rel="noopener noreferrer">↗ На хосте</a>
    <button type="button" class="btn" id="mp-back">← Назад к каталогу</button>
    <pre class="mp-log" id="mp-clone-log" hidden></pre>
  </div>
</section>

<article class="mp-readme" id="mp-readme" hidden></article>