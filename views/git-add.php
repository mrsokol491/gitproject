<?php

declare(strict_types=1);

/**
 * Вид git-add: клонирование публичного git-проекта по URL в projects/.
 * Форма — flex-колонка (desc-form), поля не слипаются в строку.
 */

?><h2 class="hosts-title">➕ Добавить git-проект</h2>
<p class="mp-stats-note">Публичная ссылка на репозиторий. Проект клонируется в <code>projects/&lt;slug&gt;/</code>, где <code>&lt;slug&gt;</code> — имя репозитория из URL.</p>

<section class="hosts-box">
  <form class="desc-form host-form" id="git-add-form">
    <label class="info-label" for="ga-url">URL репозитория</label>
    <input type="url" id="ga-url" maxlength="400" required placeholder="https://gitverse.ru/git123/Krestiki-Noliki.git">
    <label class="info-label" for="ga-name">Локальное имя папки (опционально)</label>
    <input type="text" id="ga-name" maxlength="60" placeholder="По умолчанию — slug из URL">
    <p class="mp-stats-note" id="ga-hint">Будет клонирован в <code>projects/<span id="ga-preview">…</span>/</code></p>
    <div class="host-form-actions">
      <button type="submit" class="btn btn--primary" id="ga-submit">⬇ Клонировать</button>
    </div>
    <pre class="mp-log" id="ga-log" hidden></pre>
  </form>
</section>