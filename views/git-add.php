<?php

declare(strict_types=1);

/**
 * Вид git-add: клонирование публичного git-проекта по URL в projects/.
 * Поддерживает GitHub / GitVerse / GitFlic. Имя локальной папки = slug URL.
 * Существующие проекты в projects/ не перезаписываются.
 */

?><h1 class="ws-view-title">➕ Добавить git-проект</h1>
<p class="ws-view-sub">Публичная ссылка на репозиторий. Проект клонируется в <code>projects/&lt;slug&gt;/</code>, где <code>&lt;slug&gt;</code> — имя репозитория из URL.</p>

<section class="hosts-box">
  <form class="host-form" id="git-add-form">
    <label class="info-label" for="ga-url">URL репозитория</label>
    <input type="url" id="ga-url" maxlength="400" required
           placeholder="https://gitverse.ru/git123/Krestiki-Noliki.git"
           class="mp-input">
    <label class="info-label" for="ga-name">Локальное имя папки (опционально)</label>
    <input type="text" id="ga-name" maxlength="60"
           placeholder="По умолчанию — slug из URL"
           class="mp-input">
    <p class="mp-stats-note" id="ga-hint">Будет клонирован в <code>projects/<span id="ga-preview">…</span>/</code></p>
    <div class="host-form-actions">
      <button type="submit" class="btn btn--primary" id="ga-submit">⬇ Клонировать</button>
    </div>
    <pre class="mp-log" id="ga-log" hidden></pre>
  </form>
</section>