// marketplace-data.js — каталог маркетплейса. Единственный источник записей.
// Никакой БД: правки каталога = правки этого файла + коммит.
export const MARKET_HOSTS = {
  github: { name: 'GitHub', domain: 'github.com', repo: 'https://github.com/%o/%s' },
  gitverse: { name: 'GitVerse', domain: 'gitverse.ru', repo: 'https://gitverse.ru/%o/%s' },
  gitflic: { name: 'GitFlic', domain: 'gitflic.ru', repo: 'https://gitflic.ru/project/%o/%s' },
};

export const MARKET_CATEGORIES = {
  'dev-tools': '🛠 Инструменты',
  'ux-ui': '🎨 UX/UI',
  'components': '🧩 Компоненты',
  'learning': '📚 Обучение',
  'self-hosted': '🚀 Self-hosted',
};

export const MARKET_ITEMS = [
  {
    host: 'github',
    owner: 'shadcn-ui',
    slug: 'ui',
    category: 'ux-ui',
    tags: ['components', 'react', 'tailwind'],
    note: 'Эталон подхода: компоненты копируются в проект, а не тянутся зависимостью',
  },
  {
    host: 'github',
    owner: 'filamentphp',
    slug: 'filament',
    category: 'dev-tools',
    tags: ['php', 'laravel', 'admin'],
    note: 'Админки Laravel за часы: таблицы, формы, виджеты из коробки',
  },
  {
    host: 'gitverse',
    owner: 'Sokol491',
    slug: 'gitproject',
    category: 'dev-tools',
    tags: ['php', 'git', 'workspace'],
    note: 'GitProject Workspace Hub - Централизованный хаб для управления, проксирования и изоляции проектов Git.',
  },
    {
    host: 'gitverse',
    owner: 'Sokol491',
    slug: 'archive',
    category: 'dev-tools',
    tags: ['php', 'workspace'],
    note: 'Семейный онлайн-архив: карточки персон, галереи фотографий и документов с метаданными, визуализация родословного древа по GEDCOM, админ-панель для ведения данных. Проект рассчитан на работу в домашней сети (LAN).',
  },
];