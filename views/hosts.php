<?php

declare(strict_types=1);

/**
 * Вид hosts: таблица хостов и форма добавления/обновления.
 * Заголовки на существующих классах hosts-title / mp-stats-note (пропорции интерфейса).
 */

$csrf = $csrf ?? '';
?><h2 class="hosts-title">🔑 Хосты и API-ключи</h2>
<p class="mp-stats-note">Конфигурация Git-хостов хранится в <code>config/hosts.json</code>, ключи — в <code>.env</code> (права 600). Правки мгновенно подхватываются скриптами синхронизации.</p>

<section class="hosts-box">
  <div class="hosts-head">
    <h2 class="hosts-title">Настроенные хосты</h2>
    <button type="button" class="btn btn--primary" id="host-add-open">➕ Добавить хост</button>
  </div>
  <div class="hosts-table-wrap">
    <table class="hosts-table" id="hosts-table">
      <thead>
        <tr><th>ID</th><th>Домен</th><th>Статус</th><th>Реестр</th><th>Ключ</th><th></th></tr>
      </thead>
      <tbody>
        <tr><td colspan="6" class="hosts-empty">Загрузка…</td></tr>
      </tbody>
    </table>
  </div>
  <form class="desc-form host-form" id="host-form" hidden>
    <input type="text" id="hf-id" maxlength="30" placeholder="ID хоста (gitea, codebase…)" required>
    <input type="text" id="hf-domain" maxlength="100" placeholder="Домен (gitea.com)" required>
    <input type="text" id="hf-prefix" maxlength="20" placeholder="Префикс переменных (GITEA)" required>
    <select id="hf-registry">
      <option value="foreign">Реестр: иностранный</option>
      <option value="rf">Реестр: РФ</option>
    </select>
    <select id="hf-scope">
      <option value="BOTH">Область: HUB + PROJECT</option>
      <option value="HUB">Область: HUB</option>
      <option value="PROJECT">Область: PROJECT</option>
    </select>
    <input type="text" id="hf-user" maxlength="60" placeholder="Пользователь (owner)" required>
    <input type="password" id="hf-token" maxlength="300" placeholder="API-токен (пусто = не менять)">
    <div class="host-form-actions">
      <button type="submit" class="btn btn--primary" id="hf-save">Сохранить хост и ключ</button>
      <button type="button" class="btn" id="hf-cancel">Отмена</button>
    </div>
    <p class="mp-msg" id="hf-msg" role="status"></p>
  </form>
</section>