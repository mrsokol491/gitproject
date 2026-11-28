<?php

declare(strict_types=1);

/**
 * Вид home: таблица проектов; страница проекта — один живой блок сравнения с сервером
 * внутри панели информации (без дублирующего статического блока и без верхнего баннера).
 * Пустой список: CTA на существующих классах btn/btn--primary (без новых CSS-сущностей).
 */

if ($project === null): ?>
<section class="ws-table-wrap">
  <table class="ws-table">
    <thead>
      <tr>
        <th class="ws-th-name">NAME</th>
        <th>STATE</th>
        <th>TYPE</th>
        <th>SIZE</th>
        <th>UPDATED</th>
        <th class="ws-th-act">ACTIONS</th>
      </tr>
    </thead>
    <?php if ($projects === []): ?>
      <tbody>
        <tr><td colspan="6" class="ws-empty">📂 В projects/ пока нет репозиториев. <a class="btn btn--primary" href="/?view=git-add" id="empty-go-add">➕ Добавить git</a></td></tr>
      </tbody>
    <?php else: ?>
      <?php foreach (['PHP', 'Node.js', 'unknown'] as $grp): ?>
        <?php $rows = array_values(array_filter($projects, static fn(array $p): bool => $p['type'] === $grp)); if ($rows === []) { continue; } ?>
        <tbody class="ws-tbody" data-group="<?= htmlspecialchars($grp) ?>">
          <tr class="ws-group" data-group="<?= htmlspecialchars($grp) ?>">
            <td colspan="6">
              <button type="button" class="ws-group-btn" data-group="<?= htmlspecialchars($grp) ?>">
                <span class="ws-arrow">▾</span> <?= htmlspecialchars($grp) ?> <span class="ws-count"><?= count($rows) ?></span>
              </button>
            </td>
          </tr>
          <?php foreach ($rows as $p): ?>
            <?php $state = $p['synced'] === true ? 'synced' : ($p['synced'] === false ? 'behind' : 'none'); ?>
            <tr class="ws-row"
                data-group="<?= htmlspecialchars($grp) ?>"
                data-state="<?= $state ?>"
                data-name="<?= htmlspecialchars($p['name']) ?>"
                data-desc="<?= htmlspecialchars($p['description']) ?>"
                data-slug="<?= htmlspecialchars($p['name']) ?>"
                data-page="<?= htmlspecialchars($p['page_url']) ?>"
                data-preview="<?= htmlspecialchars($p['entry_point'] ?? '') ?>"
                data-remote="<?= htmlspecialchars($p['git_remote'] ?? '') ?>">
              <td class="ws-cell-name">
                <span class="ws-ico"><?= $p['type'] === 'PHP' ? '🐘' : ($p['type'] === 'Node.js' ? '🟨' : '📦') ?></span>
                <span class="ws-namebox">
                  <a class="ws-name" href="<?= htmlspecialchars($p['page_url']) ?>"><?= htmlspecialchars($p['name']) ?></a>
                  <span class="ws-sub"><?= htmlspecialchars($p['description'] !== '' ? $p['description'] : 'без описания') ?></span>
                </span>
              </td>
              <td>
                <span class="ws-dot ws-dot--<?= $state ?>"></span><span class="ws-state"><?= $state === 'synced' ? 'Синхронизирован' : ($state === 'behind' ? 'Есть обновления' : 'Нет данных') ?></span>
              </td>
              <td><span class="badge badge--<?= str_replace(['.', ' '], '-', strtolower($p['type'])) ?>"><?= htmlspecialchars($p['type']) ?></span></td>
              <td class="ws-dim"><?= htmlspecialchars($p['size_human']) ?></td>
              <td class="ws-dim" title="<?= htmlspecialchars($p['modified_full']) ?>"><?= htmlspecialchars($p['modified']) ?></td>
              <td class="ws-cell-act">
                <?php if ($p['entry_point'] !== null): ?>
                  <button type="button" class="ws-act" data-act="preview" title="Открыть проект">👁</button>
                <?php else: ?>
                  <button type="button" class="ws-act" disabled title="Нет docroot с index">👁</button>
                <?php endif; ?>
                <button type="button" class="ws-act" data-act="pull" title="Получить с Git-сервера">⬇</button>
                <button type="button" class="ws-act" data-act="push" title="Загрузить на Git-сервер">⬆</button>
                <button type="button" class="ws-act" data-act="export" title="Экспорт zip">📦</button>
                <button type="button" class="ws-act ws-act--danger" data-act="delete" title="Удалить локально">🗑</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
</section>
<footer class="ws-foot">Состояние синхронизации — по tracking-ссылкам последнего fetch каждого проекта. Точное сравнение — на странице проекта.</footer>

<?php else: ?>
  <header class="project-header">
    <h1 class="project-title"><span class="dot dot--<?= $project['dot'] ?>"></span><?= htmlspecialchars($project['name']) ?></h1>
  </header>

  <section class="project-info">
    <?php if ($project['description'] !== ''): ?>
    <div class="info-row"><span class="info-label">Описание</span><span class="info-value"><?= htmlspecialchars($project['description']) ?></span></div>
    <?php endif; ?>
    <div class="info-row"><span class="info-label">Тип</span><span class="info-value"><?= htmlspecialchars($project['type']) ?></span></div>
    <?php if ($project['version'] !== null): ?>
    <div class="info-row"><span class="info-label">Версия</span><span class="info-value"><?= htmlspecialchars($project['version']) ?></span></div>
    <?php endif; ?>
    <div class="info-row"><span class="info-label">Docroot</span><span class="info-value"><?= $project['docroot'] !== null ? htmlspecialchars($project['docroot']) . '/' : 'none' ?></span></div>
    <div class="info-row"><span class="info-label">Размер</span><span class="info-value"><?= htmlspecialchars($project['size_human']) ?></span></div>
    <div class="info-row"><span class="info-label">Обновлено</span><span class="info-value"><?= htmlspecialchars($project['modified_full']) ?></span></div>
    <hr class="info-divider">
    <div class="remote-badges" id="remote-badges"></div>
    <div id="proj-update">
      <div class="hosts-head">
        <span class="info-label">Сравнение с сервером</span>
        <button type="button" class="btn btn--primary" id="proj-check">🔍 Проверить сервер</button>
      </div>
      <div id="proj-update-text">Загрузка…</div>
      <div class="hub-update-buttons">
        <button type="button" class="btn" id="proj-pull" hidden>⬇ Обновить из сервера</button>
        <button type="button" class="btn" id="proj-push" hidden>⬆ Опубликовать на сервер</button>
      </div>
      <pre class="hub-log" id="proj-log" hidden></pre>
    </div>
  </section>

  <section class="project-actions">
    <div class="action-group">
      <h3 class="action-group-title">Интеграция и статус</h3>
      <div class="action-buttons">
        <button type="button" class="btn btn--primary" data-op="pull" hidden>⬇ Получить версию с Git-сервера</button>
        <button type="button" class="btn" data-op="push" hidden>⬆ Загрузить локальную версию на Git-сервер</button>
        <button type="button" class="btn" data-op="merge">🔀 Merge (все remote)</button>
        <button type="button" class="btn" id="git-status">📊 Статус репозитория</button>
      </div>
      <div class="status-box" id="status-box" hidden></div>
      <pre class="hub-log" id="git-log" hidden></pre>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Просмотр</h3>
      <div class="action-buttons">
        <?php if ($project['entry_point'] !== null): ?>
          <a href="<?= htmlspecialchars($project['entry_point']) ?>" class="btn" target="_blank" rel="noopener">🌐 Открыть проект для визуального отображения</a>
        <?php else: ?>
          <button type="button" class="btn" disabled>🌐 Открыть проект для визуального отображения</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Информация</h3>
      <div class="action-buttons">
        <button type="button" class="btn" id="show-commits">📜 Просмотр логов коммитов</button>
        <button type="button" class="btn" id="export-project">📦 Экспорт проекта (zip)</button>
        <button type="button" class="btn" id="fm-open">📁 Файловый менеджер</button>
      </div>
      <div class="commits-list" id="commits-list" hidden></div>
      <div class="fm-box" id="fm-box" hidden>
        <div class="fm-crumbs" id="fm-crumbs"></div>
        <button type="button" class="btn fm-back" id="fm-back" hidden>← К списку</button>
        <div class="fm-list" id="fm-list"></div>
        <pre class="fm-view" id="fm-view" hidden></pre>
      </div>
    </div>
    <div class="action-group">
      <h3 class="action-group-title">Настройки</h3>
      <div class="action-buttons">
        <button type="button" class="btn" id="edit-desc">✏️ Изменить описание проекта</button>
        <button type="button" class="btn" id="edit-meta">⚙️ Редактировать мета-данные</button>
      </div>
      <div class="desc-form" id="desc-form" hidden>
        <textarea id="desc-input" rows="3" maxlength="500"><?= htmlspecialchars($project['description']) ?></textarea>
        <button type="button" class="btn btn--primary" id="save-desc">Сохранить</button>
      </div>
      <div class="desc-form" id="meta-form" hidden>
        <input type="text" id="meta-version" maxlength="50" placeholder="Версия (1.2.3)">
        <input type="url" id="meta-homepage" maxlength="200" placeholder="Homepage (https://…)">
        <input type="text" id="meta-license" maxlength="100" placeholder="Лицензия (MIT)">
        <input type="text" id="meta-keywords" maxlength="1100" placeholder="Keywords через запятую">
        <button type="button" class="btn btn--primary" id="save-meta">Сохранить мета-данные</button>
      </div>
    </div>
    <div class="action-group action-group--danger">
      <h3 class="action-group-title">Опасная зона</h3>
      <div class="action-buttons">
        <button type="button" class="btn btn--danger" id="delete-local">🗑 Удалить локально</button>
      </div>
      <p class="action-hint">Удаление с хостов выполняется в Web-UI самого хоста. Локальное удаление необратимо.</p>
    </div>
  </section>
<?php endif; ?>